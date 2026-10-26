/*
 * Data table component. Turns a server-rendered table into a working list: Search, Status, Date range, Reset, View (columns),
 * sortable headers, row selection, pagination and CSV export.
 *
 * Markup:
 *   <div class="dt" data-key="trips" data-default-status="open" data-page-size="25" data-date-label="Start date" data-export="trips"
 *        data-statuses='[{"v":"open","l":"Open","in":["Planned","Assigned"]},{"v":"Delivered","l":"Delivered"}]'>
 *     <div data-dt-actions> ...extra buttons, moved into the toolbar... </div>
 *     <div class="dt-wrap"><table class="dt-table"><thead><tr><th data-sort="text">Trip</th>…</tr></thead>
 *       <tbody><tr data-row data-status="Planned" data-date="2026-10-23" data-q="lowercase search text"><td data-v="sortvalue">…</td></tr></tbody></table></div>
 *   </div>
 * th: data-sort="text|num|date"  data-nohide  data-noexport.   Checkboxes: th .dt-ckh, td .dt-ck (fires "dt:select").
 * Fires "dt:render" after every redraw. The instance is available as root.dt.
 * Optional data-resize on .dt: column widths can be dragged (edge of a heading), View menu gets Fit columns / Reset widths, widths are remembered.
 */
(function () {
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>';

  function init(root) {
    const key = root.dataset.key || 'dt', table = $('table.dt-table', root), tbody = table.tBodies[0];
    const rows = $$('tr[data-row]', tbody); rows.forEach((r, i) => r._i = i);
    const statuses = JSON.parse(root.dataset.statuses || '[]'), ths = $$('thead th', table);
    const st = { q: '', status: root.dataset.defaultStatus || '', from: '', to: '', sort: null, dir: 1, page: 1, size: +(root.dataset.pageSize || 25), hidden: [] };
    try { const s = JSON.parse(localStorage.getItem('dt_' + key) || '{}'); if (s.size) st.size = s.size; if (Array.isArray(s.hidden)) st.hidden = s.hidden; if (s.status !== undefined && (s.status === '' || statuses.some(x => x.v === s.status))) st.status = s.status; } catch (e) { }
    // ?st=<status> (links from the process bar) wins over the remembered filter, when this table has that status
    try { const u = new URLSearchParams(location.search).get('st'); if (u !== null && (u === '' || statuses.some(x => x.v === u))) st.status = u; } catch (e) { }
    const save = () => { try { localStorage.setItem('dt_' + key, JSON.stringify({ size: st.size, hidden: st.hidden, status: st.status })); } catch (e) { } };

    // status options with counts
    const inList = o => o.in || [o.v];
    const count = o => rows.filter(r => inList(o).includes(r.dataset.status)).length;
    const statusOpts = '<option value="">Status (all)</option>' + statuses.map(o => `<option value="${esc(o.v)}">${esc(o.l)} (${count(o)})</option>`).join('');

    // toolbar
    const bar = document.createElement('div'); bar.className = 'dt-bar';
    bar.innerHTML = `<label class="dt-search">${ICON}<input type="search" placeholder="Search" aria-label="Search"></label>` +
      (statuses.length ? `<select class="dt-status" aria-label="Status">${statusOpts}</select>` : '') +
      (rows.some(r => r.dataset.date) ? `<div class="dt-date" title="${esc(root.dataset.dateLabel || 'Date')}"><input type="date" class="dt-from" aria-label="From date"><span>–</span><input type="date" class="dt-to" aria-label="To date"></div>` : '') +
      '<span class="dt-grow"></span><span class="dt-slot"></span>' +
      '<button type="button" class="dt-btn dt-reset">↻ Reset</button>' +
      '<div class="dt-view"><button type="button" class="dt-btn dt-vbtn">View ▾</button><div class="dt-menu"></div></div>' +
      '<button type="button" class="dt-btn dt-export">Export</button>';
    const wrap = $('.dt-wrap', root); root.insertBefore(bar, root.firstChild);
    const slot = $('.dt-slot', bar); $$('[data-dt-actions]', root).forEach(a => { while (a.firstChild) slot.appendChild(a.firstChild); a.remove(); });
    // selection bar
    const selBar = document.createElement('div'); selBar.className = 'dt-sel'; selBar.innerHTML = '<span><b class="dt-selN">0</b> selected</span><span class="dt-grow"></span><span class="dt-selslot"></span><button type="button" class="dt-btn dt-selclr">Clear</button>';
    root.insertBefore(selBar, wrap);
    // footer
    const foot = document.createElement('div'); foot.className = 'dt-foot';
    foot.innerHTML = '<span>Rows per page <select class="dt-size">' + [10, 25, 50, 100, 0].map(n => `<option value="${n}">${n || 'All'}</option>`).join('') + '</select></span><span class="dt-range"></span><span class="dt-pg"><button type="button" data-p="first" aria-label="First page">«</button><button type="button" data-p="prev" aria-label="Previous page">‹</button><button type="button" data-p="next" aria-label="Next page">›</button><button type="button" data-p="last" aria-label="Last page">»</button></span>';
    wrap.appendChild(foot);
    const empty = document.createElement('tr'); empty.className = 'dt-empty'; empty.hidden = true; empty.innerHTML = `<td colspan="${ths.length}">Nothing matches these filters.</td>`; tbody.appendChild(empty);

    const qI = $('.dt-search input', bar), sS = $('.dt-status', bar), fI = $('.dt-from', bar), tI = $('.dt-to', bar), sizeS = $('.dt-size', foot);
    if (sS) sS.value = st.status; sizeS.value = String(st.size);

    // column chooser
    const menu = $('.dt-menu', bar);
    menu.innerHTML = '<div class="h">Columns</div>' + ths.map((th, i) => th.hasAttribute('data-nohide') || !th.textContent.trim() ? '' : `<label><input type="checkbox" data-c="${i}" ${st.hidden.includes(i) ? '' : 'checked'}> ${esc(th.textContent.trim())}</label>`).join('');
    let colFix = null;
    function applyCols() { $$('tr', table).forEach(tr => { if (tr.classList.contains('dt-empty')) return; [...tr.children].forEach((c, i) => c.classList.toggle('dt-hide', st.hidden.includes(i))); }); if (colFix) colFix(); }
    menu.addEventListener('change', e => { const i = +e.target.dataset.c; st.hidden = e.target.checked ? st.hidden.filter(x => x !== i) : [...st.hidden, i]; applyCols(); save(); });
    $('.dt-vbtn', bar).addEventListener('click', e => { e.stopPropagation(); menu.classList.toggle('open'); });
    document.addEventListener('click', e => { if (!e.target.closest('.dt-view')) menu.classList.remove('open'); });

    const val = (r, i, type) => { const c = r.children[i]; const v = c ? (c.dataset.v ?? c.textContent.trim()) : ''; return type === 'num' ? (parseFloat(String(v).replace(/[^0-9.\-]/g, '')) || 0) : String(v); };
    function filtered() {
      const q = st.q.toLowerCase().trim(), so = statuses.find(o => o.v === st.status);
      let out = rows.filter(r => {
        if (q && !(r.dataset.q || (r._q = r._q || r.textContent.toLowerCase())).includes(q)) return false;
        if (st.status && !(so ? inList(so) : [st.status]).includes(r.dataset.status)) return false;
        const d = r.dataset.date || ''; if (st.from && (!d || d < st.from)) return false; if (st.to && (!d || d > st.to)) return false;
        return true;
      });
      if (st.sort !== null) { const type = ths[st.sort].dataset.sort; out = out.slice().sort((a, b) => { const x = val(a, st.sort, type), y = val(b, st.sort, type); const c = type === 'num' ? x - y : String(x).localeCompare(String(y), undefined, { numeric: true, sensitivity: 'base' }); return (c || a._i - b._i) * st.dir; }); }
      return out;
    }
    function render() {
      const all = filtered(), size = st.size || all.length || 1, pages = Math.max(1, Math.ceil(all.length / size)); st.page = Math.min(Math.max(1, st.page), pages);
      const from = (st.page - 1) * size, show = all.slice(from, from + size), shown = new Set(show);
      rows.forEach(r => { r.hidden = !shown.has(r); });
      show.forEach(r => tbody.insertBefore(r, empty));
      empty.hidden = all.length > 0 || !rows.length;
      ths.forEach((th, i) => { th.classList.toggle('asc', st.sort === i && st.dir === 1); th.classList.toggle('desc', st.sort === i && st.dir === -1); });
      $('.dt-range', foot).textContent = all.length ? `${from + 1}–${from + show.length} of ${all.length}` : '0 rows';
      $$('.dt-pg button', foot).forEach(b => { const p = b.dataset.p; b.disabled = (p === 'first' || p === 'prev') ? st.page <= 1 : st.page >= pages; });
      applyCols(); updSel();
      root.dispatchEvent(new CustomEvent('dt:render', { detail: { rows: show, all } }));
    }
    function selected() { return rows.filter(r => { const c = $('.dt-ck', r); return c && c.checked; }); }
    function updSel() {
      const s = selected(); rows.forEach(r => r.classList.toggle('sel', s.includes(r)));
      $('.dt-selN', selBar).textContent = s.length; selBar.classList.toggle('show', s.length > 0);
      const m = $('.dt-ckh input', table); if (m) { const vis = rows.filter(r => !r.hidden && $('.dt-ck', r)); m.checked = vis.length > 0 && vis.every(r => $('.dt-ck', r).checked); }
      root.dispatchEvent(new CustomEvent('dt:select', { detail: { rows: s } }));
    }
    function reset() { st.q = ''; st.from = st.to = ''; st.sort = null; st.dir = 1; st.page = 1; st.status = root.dataset.defaultStatus || ''; qI.value = ''; if (fI) fI.value = ''; if (tI) tI.value = ''; if (sS) sS.value = st.status; $$('.dt-ck', table).forEach(c => c.checked = false); save(); render(); }

    qI.addEventListener('input', () => { st.q = qI.value; st.page = 1; render(); });
    if (sS) sS.addEventListener('change', () => { st.status = sS.value; st.page = 1; save(); render(); });
    if (fI) fI.addEventListener('change', () => { st.from = fI.value; st.page = 1; render(); });
    if (tI) tI.addEventListener('change', () => { st.to = tI.value; st.page = 1; render(); });
    $('.dt-reset', bar).addEventListener('click', reset);
    sizeS.addEventListener('change', () => { st.size = +sizeS.value; st.page = 1; save(); render(); });
    foot.addEventListener('click', e => { const b = e.target.closest('button[data-p]'); if (!b) return; const size = st.size || 1, pages = Math.max(1, Math.ceil(filtered().length / size)); st.page = { first: 1, prev: st.page - 1, next: st.page + 1, last: pages }[b.dataset.p]; render(); });
    ths.forEach((th, i) => { if (!th.dataset.sort) return; th.tabIndex = 0; const go = () => { if (st.sort === i) { if (st.dir === 1) st.dir = -1; else { st.sort = null; st.dir = 1; } } else { st.sort = i; st.dir = 1; } st.page = 1; render(); }; th.addEventListener('click', go); th.addEventListener('keydown', e => { if (e.key === 'Enter') go(); }); });
    table.addEventListener('change', e => { if (e.target.classList.contains('dt-ck')) updSel(); else if (e.target.closest('.dt-ckh')) { rows.filter(r => !r.hidden).forEach(r => { const c = $('.dt-ck', r); if (c) c.checked = e.target.checked; }); updSel(); } });
    $('.dt-selclr', selBar).addEventListener('click', () => { $$('.dt-ck', table).forEach(c => c.checked = false); updSel(); });
    $('.dt-export', bar).addEventListener('click', () => {
      const cols = ths.map((th, i) => ({ th, i })).filter(x => !x.th.hasAttribute('data-noexport') && x.th.textContent.trim() && !st.hidden.includes(x.i));
      const cell = (r, i) => { const c = r.children[i]; return c ? (c.dataset.x ?? c.textContent).replace(/\s+/g, ' ').trim() : ''; };
      const q = s => '"' + String(s).replace(/"/g, '""') + '"';
      const csv = '﻿' + [cols.map(x => q(x.th.textContent.trim())).join(',')].concat(filtered().map(r => cols.map(x => q(cell(r, x.i))).join(','))).join('\r\n');
      const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })); a.download = (root.dataset.export || key) + '_' + new Date().toISOString().slice(0, 10) + '.csv'; document.body.appendChild(a); a.click(); a.remove();
    });
    root.dt = { render, filtered, selected, reset, state: st, selSlot: $('.dt-selslot', selBar) };
    render();
    if (root.hasAttribute('data-resize')) setupCols();

    // ---- resizable columns ----
    function setupCols() {
      const wkey = 'dt_' + key + '_w', cg = document.createElement('colgroup'); ths.forEach(() => cg.appendChild(document.createElement('col'))); table.insertBefore(cg, table.firstChild);
      const cols = [...cg.children]; let ws = [];
      const fix = () => { cols.forEach((c, i) => { c.style.display = st.hidden.includes(i) ? 'none' : ''; c.style.width = (ws[i] || 80) + 'px'; }); table.style.tableLayout = 'fixed'; table.style.width = ws.reduce((a, w, i) => a + (st.hidden.includes(i) ? 0 : w), 0) + 'px'; };
      colFix = fix;
      // natural (one-line) width of every column, hidden ones included
      const measure = () => {
        const hid = $$('.dt-hide', table); hid.forEach(c => c.classList.remove('dt-hide'));
        table.style.tableLayout = 'auto'; table.style.width = 'auto'; cols.forEach(c => { c.style.width = ''; c.style.display = ''; }); table.classList.add('dt-fit');
        const out = ths.map(th => Math.min(360, Math.max(36, Math.ceil(th.getBoundingClientRect().width))));
        table.classList.remove('dt-fit'); hid.forEach(c => c.classList.add('dt-hide')); return out;
      };
      const saveW = () => { try { localStorage.setItem(wkey, JSON.stringify(ws)); } catch (e) { } };
      try { const a = JSON.parse(localStorage.getItem(wkey) || 'null'); if (Array.isArray(a) && a.length === ths.length) ws = a; } catch (e) { }
      if (!ws.length) ws = measure();
      fix();
      ths.forEach((th, i) => {
        if (!th.textContent.trim() && !th.querySelector('input')) return;
        const h = document.createElement('i'); h.className = 'dt-rz'; h.title = 'Drag to resize'; th.appendChild(h);
        h.addEventListener('click', e => e.stopPropagation());
        h.addEventListener('mousedown', e => {
          e.preventDefault(); e.stopPropagation(); const x0 = e.clientX, w0 = ws[i]; h.classList.add('on'); document.body.classList.add('dt-rzing');
          const mv = ev => { ws[i] = Math.max(36, w0 + ev.clientX - x0); fix(); };
          const up = () => { document.removeEventListener('mousemove', mv); document.removeEventListener('mouseup', up); h.classList.remove('on'); document.body.classList.remove('dt-rzing'); saveW(); };
          document.addEventListener('mousemove', mv); document.addEventListener('mouseup', up);
        });
      });
      menu.insertAdjacentHTML('beforeend', '<div class="h">Column width</div><a href="#" class="dt-w" data-w="fit">Fit columns to content</a><a href="#" class="dt-w" data-w="reset">Reset widths</a>');
      menu.addEventListener('click', e => { const a = e.target.closest('a.dt-w'); if (!a) return; e.preventDefault(); ws = measure(); fix(); if (a.dataset.w === 'fit') saveW(); else { try { localStorage.removeItem(wkey); } catch (x) { } } menu.classList.remove('open'); });
    }
  }
  window.dtInit = init;
  document.addEventListener('DOMContentLoaded', () => $$('.dt').forEach(r => { if (!r.dt) init(r); }));
  if (document.readyState !== 'loading') $$('.dt').forEach(r => { if (!r.dt) init(r); });
})();
