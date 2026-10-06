/* TEST BUILD ONLY — never part of the shipped page. Open with #e2e on a server running api/ in AI_MOCK mode. */
if (location.hash === '#e2e') (async () => {
  const out = [], ok = (name, cond, info = '') => out.push(`${cond ? 'PASS' : 'FAIL'} ${name}${info ? ' — ' + info : ''}`);
  try {
    await im_checkStatus();
    ok('status endpoint reports AI available (mock)', appData.ai.available && appData.ai.mock, JSON.stringify(appData.ai));
    const fileFrom = async (path, name, type) => new File([await (await fetch(path)).blob()], name, {type});
    const csv = await im_filePayload(await fileFrom('samples/sample_logframe.csv', 'sample_logframe.csv', 'text/csv'));
    ok('CSV read as text', csv.kind === 'text' && csv.text.includes('Apprenticeships completed'), `${csv.chars} chars`);
    const docx = await im_filePayload(await fileFrom('samples/sample_logframe.docx', 'sample_logframe.docx', ''));
    ok('Word (.docx) converted in the browser, table rows kept', docx.kind === 'text' && /Apprenticeships completed \| apprenticeships \| 0/.test(docx.text), docx.text.split('\n')[3]);
    const pdf = await im_filePayload(await fileFrom('samples/sample_logframe.pdf', 'sample_logframe.pdf', 'application/pdf'));
    ok('PDF sent as a file (base64)', pdf.kind === 'pdf' && atob(pdf.file_base64.slice(0, 8)).startsWith('%PDF'));
    await im_loadScript(LIBS.xlsx);
    const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([['Level','Result','Indicator','Baseline','Target'],['Goal','Improved learning outcomes','Pupils reading at grade level (%)',34,60],['Output','Teachers trained in structured pedagogy','Teachers trained',0,400]]), 'Logframe');
    const xl = await im_filePayload(new File([XLSX.write(wb, {type:'array', bookType:'xlsx'})], 'lf.xlsx'));
    ok('Excel (.xlsx) converted in the browser', xl.kind === 'text' && xl.text.includes('Teachers trained,0,400'), xl.text.replace(/\n/g, ' / '));
    let bad = null; try { await im_filePayload(new File(['x'], 'old.doc')); } catch(e){ bad = e.message; }
    ok('Old .doc rejected with advice', !!bad && bad.includes('.docx'), bad);
    await im_run(async () => docx, 'sample_logframe.docx');
    ok('Proxy returned a framework; review screen opened', !!appData.draft && currentView === 'review', im_state.error || '');
    ok('Nothing went live before review', appData.fw.title !== (appData.draft && appData.draft.fw.title));
    ACTIONS['rv-accept']();
    ok('Accepted framework is live', appData.fw.title.startsWith('Mock extraction') && currentView === 'dashboard' && !appData.draft, appData.fw.title);
    ok('Illustrative results generated', appData.results.length > 0, `${appData.results.length} results`);
    ok('Audit trail recorded import and acceptance', appData.log.filter(e => /imported|accepted/.test(e.text)).length >= 2);
    ok('Saved in this browser', (JSON.parse(localStorage.getItem(STORE_KEY)) || {}).fw.title === appData.fw.title);
  } catch(e){ out.push('FAIL exception — ' + (e && e.stack || e)); }
  const pre = document.createElement('pre'); pre.id = 'e2e'; pre.textContent = out.join('\n'); document.body.appendChild(pre);
})();
/* #e2e-review: stop at the review screen (for screenshots). */
if (location.hash === '#e2e-review') (async () => {
  await im_checkStatus();
  const f = new File([await (await fetch('samples/sample_logframe.docx')).blob()], 'sample_logframe.docx');
  await im_run(() => im_filePayload(f), 'sample_logframe.docx');
})();
