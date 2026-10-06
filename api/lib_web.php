<?php
/**
 * Read an organisation's public website — homepage plus a few relevant pages —
 * as plain text for the AI, with the page URL kept for every fact.
 *
 * A server that fetches URLs for visitors can be turned against the network it
 * sits on (SSRF). So: http/https on ports 80/443 only; every hostname is
 * resolved and every address must be public; the connection is pinned to the
 * vetted address (no DNS rebinding); redirects are followed by hand and each hop
 * is vetted again; size, time and content type are capped.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

const LF_WEB_MAX_BYTES = 1_500_000;
const LF_WEB_PAGE_CHARS = 9000;
const LF_WEB_TOTAL_CHARS = 42000;
const LF_WEB_EXTRA_PAGES = 5;
const LF_WEB_UA = 'Mozilla/5.0 (compatible; Eval360-ProfileReader/1.0; +https://mel.eval360.tech/logframe/)';

/** Normalise what a visitor typed into a URL, or null. */
function lf_web_normalise(string $in): ?string
{
    $in = trim($in);
    if ($in === '') return null;
    if (!preg_match('~^https?://~i', $in)) $in = 'https://' . $in;
    $p = parse_url($in);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) return null;
    if (isset($p['user']) || isset($p['pass'])) return null;
    return $in;
}

/** Vet a URL: public host, standard port. Returns [url, host, port, ip] or a string error. */
function lf_web_vet(string $url): array|string
{
    $p = parse_url($url);
    $scheme = strtolower($p['scheme'] ?? '');
    $host = strtolower(rtrim($p['host'] ?? '', '.'));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') return 'Only web addresses (http or https) can be read.';
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    $allowPrivate = lf_env('AI_ALLOW_PRIVATE_FETCH') === '1';   // local tests only — never set on the server
    if (!$allowPrivate && !in_array($port, [80, 443], true)) return 'Only standard web ports are allowed.';
    if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
        $ips = [trim($host, '[]')];
    } else {
        if (!preg_match('/^[a-z0-9.-]+$/', $host) || !str_contains($host, '.')) return 'That does not look like a public website address.';
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    }
    if (!$ips) return 'The website address could not be found.';
    foreach ($ips as $ip) {
        if (!$allowPrivate && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'That address points to a private network and cannot be read.';
        }
    }
    return [$url, $host, $port, $ips[0]];
}

/** GET one URL safely. Returns ['url' => final, 'html' => string] or a string error. */
function lf_web_get(string $url): array|string
{
    for ($hop = 0; $hop <= 3; $hop++) {
        $v = lf_web_vet($url);
        if (is_string($v)) return $v;
        [$url, $host, $port, $ip] = $v;
        $buf = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => LF_WEB_UA,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', 'Accept-Language: en'],
            CURLOPT_ENCODING => '',
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf) {
                $buf .= $chunk;
                return strlen($buf) > LF_WEB_MAX_BYTES ? 0 : strlen($chunk);   // stop reading past the cap
            },
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($code >= 300 && $code < 400 && $loc !== '') { $url = $loc; continue; }
        if ($err && $buf === '') return 'The website did not respond.';
        if ($code >= 400 || $code === 0) return 'The website returned an error (' . $code . ').';
        if ($type !== '' && !preg_match('~text/html|application/xhtml~i', $type)) return 'That address is not a web page.';
        return ['url' => $url, 'html' => $buf];
    }
    return 'Too many redirects.';
}

/** Visible text of a page, roughly as a reader sees it. */
function lf_web_text(string $html): string
{
    $html = preg_replace('~<(script|style|noscript|svg|iframe|template|form)\b.*?</\1>~is', ' ', $html) ?? '';
    $html = preg_replace('~<!--.*?-->~s', ' ', $html) ?? '';
    $html = preg_replace('~<(br|/p|/div|/li|/h[1-6]|/tr|/section|/article|/header|/footer)\b[^>]*>~i', "\n", $html) ?? '';
    $html = preg_replace('~<(h[1-6])\b[^>]*>~i', "\n## ", $html) ?? '';
    $html = preg_replace('~<li\b[^>]*>~i', "\n- ", $html) ?? '';
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? '';
    $text = preg_replace('/\n\s*\n+/', "\n", $text) ?? '';
    $lines = array_values(array_unique(array_filter(array_map('trim', explode("\n", $text)), fn($l) => mb_strlen($l) > 2)));
    return trim(implode("\n", $lines));
}

function lf_web_attr(string $html, string $pattern): ?string
{
    // The value is the last capture group, so patterns can capture the quote character first.
    return preg_match($pattern, $html, $m) ? trim(html_entity_decode((string) end($m), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null;
}
function lf_web_abs(string $base, string $href): ?string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || preg_match('~^(#|mailto:|tel:|javascript:|data:)~i', $href)) return null;
    if (preg_match('~^https?://~i', $href)) return $href;
    $b = parse_url($base);
    $root = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($href, '//')) return $b['scheme'] . ':' . $href;
    if (str_starts_with($href, '/')) return $root . $href;
    $dir = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/');
    return $root . $dir . $href;
}

/** Brand hints from the homepage: name, description, theme colour, logo, the colours the site uses most. */
function lf_web_brand(string $html, string $url): array
{
    $icon = lf_web_attr($html, '~<link[^>]+rel=["\'][^"\']*apple-touch-icon[^"\']*["\'][^>]*href=["\']([^"\']+)~i')
         ?? lf_web_attr($html, '~<link[^>]+href=["\']([^"\']+)["\'][^>]*rel=["\'][^"\']*apple-touch-icon~i')
         ?? lf_web_attr($html, '~<link[^>]+rel=["\'](?:shortcut )?icon["\'][^>]*href=["\']([^"\']+\.(?:png|svg|webp|jpg)[^"\']*)~i');
    $logo = lf_web_attr($html, '~<img[^>]+(?:class|id|alt)=["\'][^"\']*logo[^"\']*["\'][^>]*src=["\']([^"\']+)~i')
         ?? lf_web_attr($html, '~<img[^>]+src=["\']([^"\']+logo[^"\']*)["\']~i');
    // Colours that the site's own CSS uses most (inline styles only — no extra requests).
    preg_match_all('/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', implode(' ', (preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $html, $st) ? $st[1] : [])) . ' ' . implode(' ', (preg_match_all('~style=["\']([^"\']+)~i', $html, $is) ? $is[1] : [])), $cm);
    $count = [];
    foreach ($cm[1] as $hex) {
        $hex = strtolower(strlen($hex) === 3 ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : $hex);
        [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $max = max($r, $g, $b); $min = min($r, $g, $b);
        if ($max - $min < 40 || $max < 40 || $min > 215) continue;      // greys, near-black, near-white: not a brand colour
        $count['#' . $hex] = ($count['#' . $hex] ?? 0) + 1;
    }
    arsort($count);
    $theme = lf_web_attr($html, '~<meta[^>]+name=["\']theme-color["\'][^>]*content=["\'](#[0-9a-fA-F]{3,6})~i');
    $swatches = array_slice(array_keys($count), 0, 6);
    if ($theme && !in_array(strtolower($theme), $swatches, true)) array_unshift($swatches, strtolower($theme));
    return [
        'site_name' => lf_web_attr($html, '~<meta[^>]+property=["\']og:site_name["\'][^>]*content=(["\'])(.*?)\1~is'),
        'title' => lf_web_attr($html, '~<title[^>]*>(.*?)</title>~is'),
        'description' => lf_web_attr($html, '~<meta[^>]+name=["\']description["\'][^>]*content=(["\'])(.*?)\1~is')
                      ?? lf_web_attr($html, '~<meta[^>]+property=["\']og:description["\'][^>]*content=(["\'])(.*?)\1~is'),
        'theme_color' => $theme,
        'swatches' => array_slice($swatches, 0, 6),
        'icon' => $icon ? lf_web_abs($url, $icon) : null,
        'logo' => $logo ? lf_web_abs($url, $logo) : null,
        'image' => ($og = lf_web_attr($html, '~<meta[^>]+property=["\']og:image["\'][^>]*content=(["\'])(.*?)\1~is')) ? lf_web_abs($url, $og) : null,
    ];
}

/** The links on the homepage most likely to describe the organisation and its work. */
function lf_web_pick_links(string $html, string $base): array
{
    $host = parse_url($base, PHP_URL_HOST);
    $kw = ['about' => 5, 'who-we-are' => 5, 'mission' => 4, 'vision' => 3, 'strategy' => 5, 'strategic' => 5, 'programme' => 5, 'program' => 5,
           'project' => 4, 'what-we-do' => 5, 'our-work' => 5, 'impact' => 4, 'results' => 3, 'annual-report' => 3, 'focus' => 2, 'initiative' => 3, 'service' => 2, 'mandate' => 4];
    preg_match_all('~<a\b[^>]*href=["\']([^"\'#]+)["\'][^>]*>(.*?)</a>~is', $html, $m, PREG_SET_ORDER);
    $score = [];
    foreach ($m as [$all, $href, $label]) {
        $u = lf_web_abs($base, $href);
        if (!$u || parse_url($u, PHP_URL_HOST) !== $host) continue;
        $u = preg_replace('~[?#].*$~', '', $u);
        if (preg_match('~\.(pdf|jpe?g|png|gif|zip|docx?|xlsx?|mp4)$~i', $u) || rtrim($u, '/') === rtrim($base, '/')) continue;
        $hay = strtolower($u . ' ' . strip_tags($label));
        $s = 0;
        foreach ($kw as $k => $w) if (str_contains($hay, $k) || str_contains($hay, str_replace('-', ' ', $k))) $s += $w;
        if ($s > 0) $score[$u] = max($score[$u] ?? 0, $s - substr_count(parse_url($u, PHP_URL_PATH) ?? '', '/') * .5);
    }
    arsort($score);
    return array_slice(array_keys($score), 0, LF_WEB_EXTRA_PAGES);
}

/**
 * Homepage + up to five relevant pages. Never throws: a site that cannot be
 * read gives an empty page list and an error the AI and the visitor both see.
 */
function lf_web_read(string $url): array
{
    $home = lf_web_get($url);
    if (is_string($home)) return ['pages' => [], 'brand' => null, 'error' => $home];
    $pages = [];
    $total = 0;
    $add = function (string $u, string $html) use (&$pages, &$total) {
        $t = mb_substr(lf_web_text($html), 0, LF_WEB_PAGE_CHARS);
        if ($total >= LF_WEB_TOTAL_CHARS || mb_strlen($t) < 40) return;
        $t = mb_substr($t, 0, LF_WEB_TOTAL_CHARS - $total);
        $total += mb_strlen($t);
        $pages[] = ['url' => $u, 'title' => lf_web_attr($html, '~<title[^>]*>(.*?)</title>~is') ?? $u, 'text' => $t];
    };
    $add($home['url'], $home['html']);
    foreach (lf_web_pick_links($home['html'], $home['url']) as $u) {
        if ($total >= LF_WEB_TOTAL_CHARS) break;
        $r = lf_web_get($u);
        if (is_array($r)) $add($r['url'], $r['html']);
    }
    return ['pages' => $pages, 'brand' => lf_web_brand($home['html'], $home['url']), 'error' => null];
}
