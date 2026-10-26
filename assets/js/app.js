/* Citykart TMS · shell behaviour (sidebar, busy overlay, planning tab switching) */
(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);

  // ---- Live tracking: the portals (R-Track, Gtrac, Eicher) do not allow being embedded in another site, so the live page opens as a
  // side window next to the TMS. Returns false when the window opened (the link's own navigation is then cancelled).
  window.tmsTrack = function (url) {
    const w = Math.min(620, Math.round(screen.availWidth * 0.45)), h = Math.round(screen.availHeight * 0.9);
    const win = window.open(url, 'tmsTrack', 'popup=yes,width=' + w + ',height=' + h + ',left=' + (screen.availWidth - w - 8) + ',top=20');
    if (!win) return true;            // popup blocked: let the link open in a normal tab
    try { win.opener = null; } catch (e) {}
    return false;
  };

  // ---- Sidebar ----
  window.toggleSide = function () {
    const s = $('#side'); if (!s) return;
    if (window.matchMedia('(max-width:800px)').matches) { s.classList.toggle('open'); return; }
    s.classList.toggle('collapsed');
    try { localStorage.setItem('tms_side_collapsed', s.classList.contains('collapsed') ? '1' : '0'); } catch (e) {}
  };
  // Collapsible menu groups: the group of the current page is always open, the others remember their last state.
  (function () {
    let saved = {}; try { saved = JSON.parse(localStorage.getItem('tms_nav_groups') || '{}') || {}; } catch (e) {}
    const set = (btn, open) => {
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.nextElementSibling?.classList.toggle('shut', !open);
    };
    document.querySelectorAll('.nav-group[data-grp]').forEach((btn) => {
      const items = btn.nextElementSibling, hasActive = !!items?.querySelector('a.active'), g = btn.dataset.grp;
      set(btn, hasActive || saved[g] === 1 || (saved[g] === undefined && btn.getAttribute('aria-expanded') === 'true'));
      btn.addEventListener('click', () => {
        const open = btn.getAttribute('aria-expanded') !== 'true';
        set(btn, open); saved[g] = open ? 1 : 0;
        try { localStorage.setItem('tms_nav_groups', JSON.stringify(saved)); } catch (e) {}
      });
    });
  })();
  // Kept for older page scripts; navigation groups are always open now.
  window.togglePlanningSubnav = function () {};
  window.toggleMasterSubnav = function () {};
  try { if (localStorage.getItem('tms_side_collapsed') === '1') $('#side')?.classList.add('collapsed'); } catch (e) {}

  // ---- Busy overlay ----
  let busyTimer = null;
  window.showAppBusy = function (text, sub) {
    const o = $('#appBusy'); if (!o) return;
    $('#busyTitle').textContent = text || 'Working…';
    $('#busySub').textContent = sub || 'Please wait';
    o.classList.add('show');
    clearTimeout(busyTimer); busyTimer = setTimeout(window.hideAppBusy, 90000); // never stay stuck
  };
  window.hideAppBusy = function () { clearTimeout(busyTimer); $('#appBusy')?.classList.remove('show'); };
  window.addEventListener('pageshow', window.hideAppBusy);

  document.addEventListener('submit', (e) => {
    if (e.defaultPrevented || e.target.target === '_blank' || e.target.hasAttribute('data-no-busy')) return;
    setTimeout(() => { if (!e.defaultPrevented) window.showAppBusy('Saving', 'Processing your request…'); }, 0);
  });

  function isPageNav(link, ev) {
    if (!link || !link.href || ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return false;
    if (link.target === '_blank' || link.hasAttribute('download') || link.hasAttribute('data-no-busy')) return false;
    const raw = link.getAttribute('href') || '';
    if (raw === '' || raw.startsWith('#') || raw.startsWith('javascript:') || raw.startsWith('mailto:') || raw.startsWith('tel:')) return false;
    const u = new URL(link.href, location.href);
    if (u.origin !== location.origin) return false;
    if (/\.(csv|xlsx?|pdf|zip|png|jpe?g)$/i.test(u.pathname) || u.searchParams.has('export')) return false;
    return !(u.pathname === location.pathname && u.search === location.search && u.hash);
  }

  // ---- Planning tabs: switch without full reload ----
  const tabNames = { prd_pending: 'PRD Pending', indents: 'Indents', trips: 'Trips', gatepasses: 'Gate Pass', tripstatus: 'Trip Status' };
  async function loadPlanningTab(a) {
    const href = new URL(a.href, location.href); const tab = href.searchParams.get('tab') || 'prd_pending';
    const content = $('.planning-module-content'); if (!content) return false;
    try {
      window.showAppBusy('Opening ' + (tabNames[tab] || 'module'), 'Loading…');
      const u = new URL(href.href); u.searchParams.set('embedded', '1');
      const r = await fetch(u.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
      const fresh = doc.querySelector('.planning-module-content'); if (!fresh) throw new Error('Planning content missing');
      content.innerHTML = fresh.innerHTML;
      fresh.querySelectorAll('style').forEach((st) => { const n = document.createElement('style'); n.textContent = st.textContent; document.head.appendChild(n); });
      fresh.querySelectorAll('script').forEach((old) => { const n = document.createElement('script'); [...old.attributes].forEach((x) => n.setAttribute(x.name, x.value)); n.textContent = old.textContent; document.body.appendChild(n); });
      const alerts = doc.querySelector('.page-alerts'), mine = $('.page-alerts'); if (alerts && mine) mine.innerHTML = alerts.innerHTML;
      history.pushState({ planningTab: tab }, '', a.href);
      document.querySelectorAll('[data-planning-tab]').forEach((x) => x.classList.toggle('active', x.dataset.planningTab === tab));
      const t = $('#topTitle'); if (t) t.textContent = tabNames[tab] || t.textContent;
      document.title = (tabNames[tab] || 'Planning') + ' | ' + (document.title.split(' | ')[1] || 'TMS');
      $('.main')?.scrollTo({ top: 0 });
      window.closeRowMenus?.(); window.closeActionMenus?.();
      return true;
    } catch (err) { console.error(err); location.href = a.href; return false; }
    finally { window.hideAppBusy(); }
  }
  window.loadPlanningTab = loadPlanningTab;

  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-planning-tab]');
    if (a && a.href && $('.planning-module-content') && !e.metaKey && !e.ctrlKey) { e.preventDefault(); e.stopPropagation(); loadPlanningTab(a); $('#side')?.classList.remove('open'); return; }
    const link = e.target.closest('a');
    if (isPageNav(link, e)) window.showAppBusy('Opening', 'Loading page…');
  });
  window.addEventListener('popstate', () => {
    const tab = new URL(location.href).searchParams.get('tab') || 'prd_pending';
    const a = document.querySelector('[data-planning-tab="' + tab + '"]'); if (a) loadPlanningTab(a);
  });
})();
