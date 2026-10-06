/* TEST BUILD ONLY — injected by scripts/e2e.sh, never part of the shipped page.
   Runs the whole set-up against api/ in AI_MOCK mode, with a local fixture website. */
if (location.hash === '#e2e') (async () => {
  const out = [], ok = (name, cond, info = '') => out.push(`${cond ? 'PASS' : 'FAIL'} ${name}${info ? ' — ' + info : ''}`);
  const SITE = new URLSearchParams(location.search).get('site') || 'http://127.0.0.1:8811/';
  try {
    await im_checkStatus();
    ok('Status endpoint reports AI available (mock)', appData.ai.available && appData.ai.mock);
    ok('First visit opens the set-up wizard', currentView === 'setup');
    localStorage.removeItem(STORE_KEY);
    // File converters
    const fileFrom = async (path, name, type) => new File([await (await fetch(path)).blob()], name, {type});
    const docx = await im_filePayload(await fileFrom('samples/sample_logframe.docx', 'sample_logframe.docx', ''));
    ok('Word (.docx) converted in the browser, table rows kept', docx.kind === 'text' && /Apprenticeships completed \| apprenticeships \| 0/.test(docx.text));
    const pdf = await im_filePayload(await fileFrom('samples/sample_logframe.pdf', 'sample_logframe.pdf', 'application/pdf'));
    ok('PDF sent as a file', pdf.kind === 'pdf' && atob(pdf.file_base64.slice(0, 8)).startsWith('%PDF'));
    await im_loadScript(LIBS.xlsx);
    const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([['Level','Result','Indicator','Baseline','Target'],['Goal','Improved learning outcomes','Pupils reading at grade level (%)',34,60],['Output','Teachers trained in structured pedagogy','Teachers trained',0,400]]), 'Logframe');
    const xl = await im_filePayload(new File([XLSX.write(wb, {type:'array', bookType:'xlsx'})], 'lf.xlsx'));
    ok('Excel (.xlsx) converted in the browser', xl.kind === 'text' && xl.text.includes('Teachers trained,0,400'));
    let bad = null; try { await im_filePayload(new File(['x'], 'old.doc')); } catch(e){ bad = e.message; }
    ok('Old .doc rejected with advice', !!bad && bad.includes('.docx'));
    // OPTION A — logframes only: no name, no website — two files and one pasted
    const sa = su();
    sa.uploads = [{key:'ua', filename:'sample_logframe.docx', payload:docx, error:null}, {key:'ub', filename:'youth_programme.pdf', payload:pdf, error:null}];
    const csvText = await (await fetch('samples/sample_logframe.csv')).text();
    showView('setup');
    document.getElementById('su-paste').value = csvText.replace(/,/g, '\t'); document.getElementById('su-paste-name').value = 'Youth Skills (pasted)';
    ACTIONS['su-paste-add']();
    ok('A: pasted logframe added to the list (tabs kept as cells)', sa.uploads.length === 3 && sa.uploads[2].pasted && sa.uploads[2].payload.text.includes(' | '), sa.uploads.map(u => u.filename).join(', '));
    document.getElementById('su-paste').value = 'too short';
    ACTIONS['su-paste-add']();
    ok('A: a too-short paste is refused', sa.uploads.length === 3);
    ACTIONS['su-from-logframes']();
    for (let i = 0; i < 100 && appData.setup.stage !== 'review'; i++) await new Promise(r => setTimeout(r, 100));
    ok('A: two files and one paste built without a website; review opened', appData.setup.stage === 'review' && appData.setup.programmes.every(p => p.status === 'ready'), appData.setup.programmes.map(p => p.status + ':' + (p.error || '')).join(', '));
    ok('A: file programmes named from their logframes; the pasted one keeps the name given', appData.setup.programmes.slice(0, 2).every(p => p.name.startsWith('Mock extraction')) && appData.setup.programmes[2].name === 'Youth Skills (pasted)', appData.setup.programmes.map(p => p.name).join(' | '));
    ok('A: the pasted programme is recorded as pasted', appData.setup.programmes[2].source.pasted === true && appData.setup.programmes[2].source.kind === 'upload');
    ok('A: organisation name taken from the logframe', appData.setup.org.name === 'Mock organisation', appData.setup.org.name);
    ACTIONS['rv-accept']();
    ok('A: live with three programmes and no goals', appData.programmes.length === 3 && appData.org.goals.length === 0 && appData.org.source.kind === 'logframe' && appData.programmes.every(p => p.source.kind === 'upload'));
    ok('A: every screen draws without a website or goals', Object.keys(VIEW_RENDERERS).every(v => { try { VIEW_RENDERERS[v](v === 'indicator-detail' ? allInds()[0].id : v === 'result-detail' ? allInds()[0].node : ''); destroyCharts(); return true; } catch(e){ out.push('  ' + v + ': ' + e.message); return false; } }));
    const gf = new FormData(); gf.set('code', 'SG1'); gf.set('statement', 'Universal access to safe water'); gf.append('prog', 'P1');
    const gform = document.createElement('form'); gform.dataset.i = '-1';
    SUBMITS['st-goal-form'](gform, gf);
    ok('A: goal added later and linked to a programme', appData.org.goals.length === 1 && appData.programmes[0].goalCodes.includes('SG1') && goalIndex('SG1') !== null);
    appData.setup = null;
    // OPTION B — organisation and website

    const fd = new FormData(); fd.set('name', 'Kijani Water Trust'); fd.set('website', SITE); fd.set('country', 'Kenya'); fd.set('description', 'We drill boreholes.');
    await SUBMITS['su-org-form'](document.createElement('form'), fd);
    const s = appData.setup;
    ok('Website read and profile built', s.stage === 'programmes' && s.org.name === 'Kijani Water Trust' && s.org.source.pages.length === 3, s.error || `${s.org.source.pages.length} pages`);
    ok('Facts keep the page they came from', s.org.facts.some(f => f.url === SITE), JSON.stringify(s.org.facts.map(f => f.url)));
    ok('Brand colours picked up from the website', s.org.brand.primary === '#0b6e4f' && s.org.brand.swatches.includes('#f2a541'), JSON.stringify(s.org.brand));
    ok('Programmes proposed', s.programmes.length === 2);
    // Step 2 — one uploaded logframe, one pasted
    s.programmes[0].mode = 'upload'; s.programmes[0].payload = docx; s.programmes[0].filename = 'sample_logframe.docx';
    s.programmes[1].mode = 'paste'; CHANGES['su-ppaste']({dataset:{key:s.programmes[1].key}, value:csvText});
    ok('B: a programme\u2019s logframe can be pasted', !!s.programmes[1].payload && s.programmes[1].payload.kind === 'text');
    ACTIONS['su-build']();
    for (let i = 0; i < 100 && appData.setup.stage !== 'review'; i++) await new Promise(r => setTimeout(r, 100));
    ok('Both programmes built; review opened', appData.setup.stage === 'review' && currentView === 'review', appData.setup.programmes.map(p => p.status + ':' + (p.error || '')).join(', '));
    ok('Nothing live before review', appData.org.name === 'Mock organisation');
    // Edit during review, then accept
    const p0 = appData.setup.programmes[0], ind = p0.fw.indicators[0];
    CHANGES['rv-ind']({dataset:{pk:p0.key, id:ind.id, f:'target'}, value:'77', classList:{toggle(){}}});
    ACTIONS['rv-accept']();
    const P = appData.programmes;
    ok('Organisation is live with two programmes', appData.org.name === 'Kijani Water Trust' && P.length === 2 && currentView === 'dashboard');
    ok('Sources recorded: uploaded file and pasted text', P[0].source.kind === 'upload' && !P[0].source.pasted && P[1].source.kind === 'upload' && P[1].source.pasted, P.map(p => p.source.kind + (p.source.pasted ? '/pasted' : '')).join(','));
    ok('Edit made on review kept and marked', P[0].fw.indicators[0].target === 77 && P[0].fw.indicators[0].prov === 'edited');
    ok('Ids re-keyed to the live programmes', P.every(p => p.fw.indicators.every(i => i.id.startsWith(p.id + '-') && nodeById(i.node))));
    ok('Brand colour applied', getComputedStyle(document.documentElement).getPropertyValue('--brand').trim() === '#0b6e4f', getComputedStyle(document.documentElement).getPropertyValue('--brand'));
    ok('Illustrative results generated for both', P.every(p => appData.results.some(r => r.prog === p.id)));
    // Add a third programme later
    appData.setup = {stage:'building', addOnly:true, org:appData.org, programmes:[su_newProg({name:'Shule Safi schools programme', description:'WASH in schools', mode:'draft'})], error:null};
    await su_runAll();
    ok('Added programme reaches review', appData.setup && appData.setup.stage === 'review');
    ACTIONS['rv-accept']();
    ok('Portfolio now has three programmes', appData.programmes.length === 3 && appData.programmes[2].id === 'P3' && appData.current === 'P3');
    ok('Every screen draws for the new organisation', Object.keys(VIEW_RENDERERS).every(v => { try { VIEW_RENDERERS[v](v === 'indicator-detail' ? allInds()[0].id : v === 'result-detail' ? allInds()[0].node : ''); destroyCharts(); return true; } catch(e){ out.push('  ' + v + ': ' + e.message); return false; } }));
    ok('Audit trail recorded the set-up', appData.log.some(e => /set up Kijani/.test(e.text)) && appData.log.some(e => /added Shule Safi/.test(e.text)));
    const saved = JSON.parse(localStorage.getItem(STORE_KEY));
    ok('Saved in this browser (without file contents)', saved.org.name === 'Kijani Water Trust' && saved.programmes.length === 3 && !JSON.stringify(saved).includes('file_base64'));
    // Add a programme by pasting, through the modal
    ACTIONS['pf-add']();
    const af = new FormData(); af.set('name', 'Pasted programme'); af.set('description', ''); af.set('file', new File([], '')); af.set('pasted', csvText);
    await SUBMITS['pf-add-form'](document.createElement('form'), af);
    ok('Add a programme by pasting its logframe', appData.setup && appData.setup.stage === 'review' && appData.setup.programmes[0].source.pasted === true);
    ACTIONS['rv-accept']();
    ok('Portfolio now has four programmes', appData.programmes.length === 4);
  } catch(e){ out.push('FAIL exception — ' + (e && e.stack || e)); }
  const pre = document.createElement('pre'); pre.id = 'e2e'; pre.textContent = out.join('\n'); document.body.appendChild(pre);
})();
