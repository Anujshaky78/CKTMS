/* Citykart TMS · searchable dropdowns
 * Every <select> with 4 or more options gets a type-to-search box (Transporter, Vehicle, Driver, Store, State, Cluster …).
 * The real <select> stays in the page (hidden), so forms, validation and scripts that read or set its value keep working.
 * Opt out with data-nosearch on the select; force it for a short list or a select inside a table cell with data-search.
 * Light by design: the list is built only when a dropdown is opened, and one shared timer keeps the labels in step. */
(function () {
  'use strict';
  const MIN = 4;
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const all = new Set();   // enhanced selects, for the shared label sync
  let open = null;         // the dropdown that is open right now

  function enhance(sel) {
    if (sel.dataset.ss || sel.multiple || sel.size > 1 || sel.hasAttribute('data-nosearch')) return;
    const forced = sel.hasAttribute('data-search');
    if (sel.options.length < MIN && !forced) return;
    if (!forced && sel.closest('td, th')) return;   // small in-row controls (status of a row …) stay plain
    sel.dataset.ss = '1';
    const wrap = document.createElement('div'); wrap.className = 'ss';
    // keep the size the select had (full width stays full width); a select that is hidden right now (closed dialog) takes the CSS default
    let h = 0;
    if (sel.offsetWidth > 0) { const pw = sel.parentNode.clientWidth; wrap.style.width = pw && sel.offsetWidth >= pw * 0.9 ? '100%' : sel.offsetWidth + 'px'; h = sel.offsetHeight; }
    const box = document.createElement('button'); box.type = 'button'; box.className = 'ss-box'; box.setAttribute('aria-haspopup', 'listbox'); box.setAttribute('aria-expanded', 'false');
    if (h) box.style.height = h + 'px';
    const lab = document.createElement('span'); lab.className = 'ss-lab'; box.appendChild(lab);
    const car = document.createElement('i'); car.className = 'ss-car'; car.setAttribute('aria-hidden', 'true'); box.appendChild(car);
    sel.parentNode.insertBefore(wrap, sel); wrap.appendChild(box); wrap.appendChild(sel); sel.classList.add('ss-native'); sel.tabIndex = -1;

    let panel = null, q = null, ul = null, items = [], hi = -1, shown = '';
    const text = (o) => (o.textContent || '').trim();
    const sync = () => { const o = sel.options[sel.selectedIndex]; const t = o ? text(o) : ''; if (t !== lab.textContent) lab.textContent = t; box.classList.toggle('ss-empty', !o || o.value === ''); box.disabled = sel.disabled; wrap.classList.toggle('ss-off', sel.disabled); shown = sel.value; };
    const mark = () => { [...ul.children].forEach((li, k) => li.classList.toggle('hi', k === hi)); const li = ul.children[hi]; if (li && li.scrollIntoView) li.scrollIntoView({ block: 'nearest' }); };
    const build = (term) => {
      const t = term.trim().toLowerCase(); items = [];
      [...sel.options].forEach((o, i) => { if (o.hidden) return; const s = text(o); if (!t || s.toLowerCase().includes(t)) items.push({ i, s, o }); });
      ul.innerHTML = items.length ? items.map((x, k) => '<li role="option" data-k="' + k + '" class="' + (x.i === sel.selectedIndex ? 'on' : '') + (x.o.disabled ? ' dis' : '') + '">' + esc(x.s || '—') + '</li>').join('') : '<li class="ss-none">No match</li>';
      hi = Math.max(0, items.findIndex((x) => x.i === sel.selectedIndex)); if (t) hi = 0; mark();
    };
    const place = () => {
      const r = box.getBoundingClientRect(), vh = window.innerHeight, below = vh - r.bottom, hh = Math.min(320, 52 + Math.min(items.length, 8) * 32);
      panel.style.minWidth = Math.max(r.width, 200) + 'px'; panel.style.left = Math.max(4, Math.min(r.left, window.innerWidth - Math.max(r.width, 200) - 8)) + 'px';
      if (below < hh && r.top > below) { panel.style.top = 'auto'; panel.style.bottom = (vh - r.top + 4) + 'px'; } else { panel.style.bottom = 'auto'; panel.style.top = (r.bottom + 4) + 'px'; }
    };
    const close = (focus) => { if (!panel || panel.hidden) return; panel.hidden = true; box.setAttribute('aria-expanded', 'false'); if (open && open.panel === panel) open = null; if (focus) box.focus(); };
    const pick = (k) => { const x = items[k]; if (!x || x.o.disabled) return; if (sel.selectedIndex !== x.i) { sel.selectedIndex = x.i; sel.dispatchEvent(new Event('input', { bubbles: true })); sel.dispatchEvent(new Event('change', { bubbles: true })); } sync(); close(true); };
    const ensurePanel = () => {
      if (panel) return;
      panel = document.createElement('div'); panel.className = 'ss-panel'; panel.hidden = true;
      panel.innerHTML = '<input class="ss-q" type="text" placeholder="Type to search…" autocomplete="off" aria-label="Search"><ul class="ss-list" role="listbox"></ul>';
      q = panel.querySelector('.ss-q'); ul = panel.querySelector('.ss-list');
      q.addEventListener('input', () => { build(q.value); place(); });
      q.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(items.length - 1, hi + 1); mark(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(0, hi - 1); mark(); }
        else if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); pick(hi); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); }
        else if (e.key === 'Tab') close(false);
      });
      ul.addEventListener('mousedown', (e) => { const li = e.target.closest('li[data-k]'); if (li) { e.preventDefault(); pick(+li.dataset.k); } });
      ul.addEventListener('mousemove', (e) => { const li = e.target.closest('li[data-k]'); if (li && +li.dataset.k !== hi) { hi = +li.dataset.k; mark(); } });
    };
    const show = () => {
      if (sel.disabled) return; if (open && open.panel !== panel) open.close(false);
      ensurePanel();
      // a modal <dialog> sits in the browser's top layer: anything outside it is drawn underneath, so the list must live inside the dialog
      const host = box.closest('dialog') || document.body; if (panel.parentNode !== host) host.appendChild(panel);
      sync(); q.value = ''; build(''); panel.hidden = false; box.setAttribute('aria-expanded', 'true'); place(); open = { panel, close }; setTimeout(() => q.focus(), 0);
    };
    box.addEventListener('click', () => (!panel || panel.hidden ? show() : close(true)));
    box.addEventListener('keydown', (e) => { if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); show(); } else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) { show(); q.value = e.key; build(q.value); } });
    sel.addEventListener('change', sync);
    if (sel.form) sel.form.addEventListener('reset', () => setTimeout(sync, 0));
    // options filled by scripts (for example a vehicle list that follows the transporter): refresh label / open list
    new MutationObserver(() => { sync(); if (panel && !panel.hidden) build(q.value); }).observe(sel, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });
    sel.addEventListener('focus', () => { box.focus(); });   // label click / validation focus lands on the visible box
    sel._ssSync = () => { if (sel.value !== shown || box.disabled !== sel.disabled) sync(); };
    all.add(sel); sync();
  }

  // one timer for the whole page: picks up a value that a script set without firing "change"; idle while the tab is hidden
  setInterval(() => { if (document.hidden) return; all.forEach((s) => { if (!s.isConnected) { all.delete(s); return; } s._ssSync(); }); }, 600);

  const scan = (root) => (root.querySelectorAll ? root.querySelectorAll('select') : []).forEach(enhance);
  document.addEventListener('mousedown', (e) => { if (open && !open.panel.contains(e.target) && !e.target.closest('.ss-box')) open.close(false); });
  window.addEventListener('resize', () => { if (open) open.close(false); });
  window.addEventListener('scroll', (e) => { if (open && !open.panel.contains(e.target)) open.close(false); }, true);
  const start = () => { scan(document); new MutationObserver((ms) => ms.forEach((m) => m.addedNodes.forEach((n) => { if (n.nodeType === 1) { if (n.tagName === 'SELECT') enhance(n); else scan(n); } }))).observe(document.body, { childList: true, subtree: true }); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  window.tmsSearchSelect = enhance;
})();
