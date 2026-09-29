(function () {
  'use strict';

  /* ------------------------------------------------------------------ */
  function setTheme(theme, persist) {
    var dark = theme === 'dark';
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    if (persist) {
      try { localStorage.setItem('camera-fix-console-theme', dark ? 'dark' : 'light'); } catch (e) {}
    }
    var target = dark ? 'light' : 'dark';
    $('themeBtn').setAttribute('aria-label', 'Switch to ' + target + ' theme');
    $('themeBtn').setAttribute('title', 'Switch to ' + target + ' theme');
    $('themeBtn').setAttribute('aria-pressed', String(dark));
    $('themeIcon').innerHTML = dark
      ? '<circle cx="12" cy="12" r="4"></circle><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"></path>'
      : '<path d="M20.9 13A9 9 0 0 1 11 3.1 9 9 0 1 0 20.9 13Z"></path>';
  }
  var savedTheme = null;
  try { savedTheme = localStorage.getItem('camera-fix-console-theme'); } catch (e) {}
  var initialTheme = savedTheme === 'light' || savedTheme === 'dark'
    ? savedTheme
    : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  setTheme(initialTheme, false);
  $('themeBtn').addEventListener('click', function () {
    setTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark', true);
  });
  /* Constants + tiny helpers                                            */
  /* ------------------------------------------------------------------ */
  var ISSUE_TYPES = ["Require Fine Tuning", "Require Appropriate Backlight Option", "Require Zoom Out", "Require Appropriate Min Size", "Require Zoom In", "Require Tilt Down", "Require Tilt Up", "Pin Hole Cam", "Require Appropriate Detection Area", "Require Tilt Left", "Require Tilt Right", "Offline", "Temporarily camera removed", "Straight the Camera", "FIX CAMERA ALLIGNMENT", "REMOVE TARGET BOX OVERLAY", "LAST CAPTURE ON 06-09,CHECK CAMERA", "CAPTURES MISSING", "CHECK CAMERA HEIGHT IS AS INSTRUCTED", "fix camera alignment", "CAPTURES ARE NOT RECOGNISABLE FIX THE ISSUE", "REMOVE OBSTRUCTION", "FIX TARGET AREA", "OBSTRUCTION INFRONT OF CAMERA", "FIX THE TARGET AREA", "CHECK THE CAMERA HEIGHT IS AS INSTRUCTED", "fix camera alignment,captures missing", "CHECK CAMERA ALIGNMENT", "NO CAPTURES TILL NOW", "LAST CAPTURE IS ON 02-09", "CAPTURES ARE NOT CLEAR,CLEAN THE LENS", "CLEAN THE LENS", "SOME REFLECTIONS SEEING IN CAMERA,CLEAR IT"];
  var FIELDS = ['id','channel','place','category','organization','chCategory','camType','model','ip','lon','lat','swVer','fw','integrator','remark','issues','status','activity','siContactName','siContactMobile','siContactEmail'];
  var IDX = {}; FIELDS.forEach(function (f, i) { IDX[f] = i; });
  var REGISTER_COLUMNS = [
    { key: 'id', label: 'ID' },
    { key: 'place', label: 'Site' },
    { key: 'category', label: 'Category' },
    { key: 'channel', label: 'Channel' },
    { key: 'chCategory', label: 'Channel Category' },
    { key: 'camType', label: 'Type' },
    { key: 'model', label: 'Model' },
    { key: 'fw', label: 'Firmware' },
    { key: 'swVer', label: 'Software' },
    { key: 'status', label: 'Status' },
    { key: 'issues', label: 'Issues' },
    { key: 'remark', label: 'Remark' },
    { key: 'integrator', label: 'Integrator' },
    { key: 'siContactName', label: 'SI Name' },
    { key: 'siContactMobile', label: 'SI Mobile' },
    { key: 'siContactEmail', label: 'SI Email' },
    { key: 'organization', label: 'Organization' },
    { key: 'lon', label: 'Longitude' },
    { key: 'lat', label: 'Latitude' }
  ];
  function get(r, n) { return r[IDX[n]]; }
  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function fmt(iso) {
    try { return new Date(iso).toLocaleString(undefined, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); }
    catch (e) { return iso; }
  }
  function statCard(num, label, cls) { return '<div class="stat-card ' + (cls || '') + '"><div class="stat-num">' + num + '</div><div class="stat-label">' + esc(label) + '</div></div>'; }
  function issueTags(list) { return (list || []).map(function (i) { return '<span class="issue-tag">' + esc(i) + '</span>'; }).join(''); }
  function siteKeyOf(name) { return String(name || '(Unnamed site)').toLowerCase(); }

  var toastEl = $('toast'), toastTimer = null;
  function toast(msg) {
    toastEl.textContent = msg; toastEl.classList.add('show');
    clearTimeout(toastTimer); toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 3200);
  }

  /* ------------------------------------------------------------------ */
  /* State                                                               */
  /* ------------------------------------------------------------------ */
  var ME = null, ROWS = [], ASSIGN = {}, SI_USERS = [], REV = 0;
  var sites = {}, siteList = [];
  var NOTIFS = [], lastUnread = 0;
  var view = 'overview', currentSiteKey = null, currentStatusFilter = 'all', lastQuery = '';
  var resultMatches = [], resultChecked = {};
  var allSitesPage = 1, allSitesPageSize = 50;
  var pollTimer = null, polling = false;

  /* ------------------------------------------------------------------ */
  /* API                                                                 */
  /* ------------------------------------------------------------------ */
  function api(method, url, body) {
    var endpoint = url.indexOf('/api/') === 0 ? 'api.php?path=' + encodeURIComponent(url) : url;
    return fetch(endpoint, {
      method: method,
      headers: body ? { 'Content-Type': 'application/json' } : {},
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (res.status === 401 && url !== '/api/login' && url !== '/api/me/password') { showLogin(); throw new Error('Please sign in'); }
        if (!res.ok) throw new Error(data.error || 'Request failed');
        return data;
      });
    });
  }
  function apiWorkbookUpload(url, file) {
    var form = new FormData(); form.append('workbook', file);
    var endpoint = 'api.php?path=' + encodeURIComponent(url);
    return fetch(endpoint, { method: 'POST', body: form, credentials: 'same-origin' }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (res.status === 401) { showLogin(); throw new Error('Please sign in'); }
        if (!res.ok) throw new Error(data.error || 'Workbook import failed');
        return data;
      });
    });
  }
  function applyRev(r) { if (r && r.prevRev === REV) REV = r.rev; }
  function fail(e) { if (e && e.message !== 'Please sign in') toast(e.message || 'Something went wrong'); }

  /* ------------------------------------------------------------------ */
  /* Workflow state (mirrors the server rules)                           */
  /* ------------------------------------------------------------------ */
  function activityOf(r) { return get(r, 'activity') || []; }
  function latestRejection(r) {
    var a = activityOf(r);
    for (var i = a.length - 1; i >= 0; i--) if (a[i].a === 'CHECK_NOTOK') return a[i];
    return null;
  }
  function siState(r) {
    var status = get(r, 'status'), issues = get(r, 'issues') || [], a = activityOf(r), last = a.length ? a[a.length - 1] : null;
    if (status === 'OK') return (last && last.a === 'CHECK_OK') ? { key: 'verified', label: 'Verified fixed', cls: 'ok', at: last.t, by: last.by } : { key: 'na', label: '—', cls: 'neutral' };
    if (!issues.length) return { key: 'na', label: '—', cls: 'neutral' };
    if (!last || last.a === 'CHECK_OK') return { key: 'awaiting', label: 'Awaiting fix', cls: 'neutral' };
    if (last.a === 'SI_FIXED') return { key: 'pending', label: 'Fixed — pending check', cls: 'pending', at: last.t, by: last.by };
    return { key: 'reopened', label: 'Refix needed', cls: 'warn', at: last.t, by: last.by };
  }

  /* ------------------------------------------------------------------ */
  /* Site index                                                          */
  /* ------------------------------------------------------------------ */
  function buildIndex() {
    sites = {};
    ROWS.forEach(function (r) {
      var place = get(r, 'place') || '(Unnamed site)', k = place.toLowerCase();
      if (!sites[k]) sites[k] = { name: place, category: get(r, 'category') || '', rows: [] };
      sites[k].rows.push(r);
    });
    siteList = Object.keys(sites).map(function (k) {
      var s = sites[k], siNames = [], t;
      s.rows.forEach(function (r) {
        var name = String(get(r, 'integrator') || '').trim();
        if (name && !siNames.some(function (existing) { return existing.toLowerCase() === name.toLowerCase(); })) siNames.push(name);
      });
      t = { key: k, name: s.name, category: s.category, siNames: siNames, total: s.rows.length, needsFix: 0, ok: 0, noData: 0, pending: 0, siId: ASSIGN[k] || null };
      s.rows.forEach(function (r) {
        var st = get(r, 'status');
        if (st === 'Needs Fix') t.needsFix++; else if (st === 'OK') t.ok++; else t.noData++;
        if (siState(r).key === 'pending') t.pending++;
      });
      return t;
    }).sort(function (a, b) { return b.total - a.total; });
  }
  function siById(id) { for (var i = 0; i < SI_USERS.length; i++) if (SI_USERS[i].id === id) return SI_USERS[i]; return null; }
  function siLabel(id) { var u = siById(id); return u ? esc(u.name) : '<span style="color:var(--text-faint);">Unassigned</span>'; }
  function isAdmin() { return ME && ME.role === 'admin'; }

  /* ------------------------------------------------------------------ */
  /* Views                                                               */
  /* ------------------------------------------------------------------ */
  var VIEW_IDS = { overview: 'viewOverview', results: 'viewResults', site: 'viewSite', users: 'viewUsers', empty: 'viewEmpty' };
  function showView(name) {
    view = name;
    Object.keys(VIEW_IDS).forEach(function (k) { $(VIEW_IDS[k]).classList.toggle('active', k === name); });
  }
  function goHome() { $('searchInput').value = ''; lastQuery = ''; showView('overview'); }
  $('brandHome').addEventListener('click', goHome);
  $('backBtn').addEventListener('click', goHome);
  $('usersBack').addEventListener('click', goHome);
  $('emptyReset').addEventListener('click', goHome);

  /* ---------- Login / logout ---------- */
  function showLogin() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    ME = null; ROWS = []; ASSIGN = {}; SI_USERS = []; NOTIFS = []; lastUnread = 0;
    $('appRoot').hidden = true; $('loginView').hidden = false;
    $('loginPass').value = ''; $('loginBtn').disabled = false;
    ['modalOverlay', 'rejectModalOverlay', 'pwOverlay'].forEach(function (id) { $(id).classList.remove('open'); });
    $('loginUser').focus();
  }
  $('loginForm').addEventListener('submit', function (e) {
    e.preventDefault();
    $('loginError').textContent = ''; $('loginBtn').disabled = true;
    api('POST', '/api/login', { username: $('loginUser').value, password: $('loginPass').value })
      .then(function (r) { ME = r.user; return startApp(); })
      .catch(function (err) { $('loginError').textContent = err.message; })
      .then(function () { $('loginBtn').disabled = false; });
  });
  $('logoutBtn').addEventListener('click', function () {
    api('POST', '/api/logout', {}).catch(function () {}).then(function () { $('loginUser').value = ''; showLogin(); });
  });

  function applyRole() {
    var admin = isAdmin();
    document.querySelectorAll('.admin-only').forEach(function (el) { el.hidden = !admin; });
    document.querySelectorAll('.si-only').forEach(function (el) { el.hidden = admin; });
    $('bellWrap').hidden = false;
    $('userLabel').textContent = ME.name;
    $('userPanelName').textContent = ME.name + ' · ' + (admin ? 'Administrator' : 'SI contractor');
    $('heroTitle').textContent = admin ? 'Find a site. See every camera on it.' : 'Your assigned sites';
    $('heroText').textContent = admin
      ? 'Search any site, see which SI is responsible, and verify their fixes. Assign sites to SI accounts from the search results.'
      : 'Search a site to see its cameras, mark fixes as done, and read messages from the admin.';
  }

  function startApp() {
    $('loginView').hidden = true; $('appRoot').hidden = false;
    applyRole(); goHome();
    return loadAll(true).then(function () {
      if (lastUnread > 0) toast('You have ' + lastUnread + ' unread ' + (isAdmin() ? 'fix update' : 'message') + (lastUnread === 1 ? '' : 's'));
      if (pollTimer) clearInterval(pollTimer);
      pollTimer = setInterval(poll, 10000);
    });
  }

  /* ---------- Data loading + polling ---------- */
  function loadAll(first) {
    return api('GET', '/api/data').then(function (d) {
      ME = d.me; REV = d.rev; ROWS = d.rows; ASSIGN = d.assignments || {}; SI_USERS = d.users || [];
      buildIndex(); renderOverview();
      if (!first) refreshView();
      return loadNotifications(!first);
    });
  }
  function refreshView() {
    if (view === 'site') { if (sites[currentSiteKey]) renderSiteDashboard(); else goHome(); }
    else if (view === 'results') runSearch(lastQuery, true);
    else if (view === 'users') renderUsers();
  }
  function poll() {
    if (polling || !ME) return;
    polling = true;
    api('GET', '/api/rev').then(function (r) {
      if (r.rev !== REV) return loadAll(false);
      if (r.unread !== lastUnread) return loadNotifications(true);
    }).catch(function () {}).then(function () { polling = false; });
  }

  /* ------------------------------------------------------------------ */
  /* Overview                                                            */
  /* ------------------------------------------------------------------ */
  function renderAllSitesTable() {
    if (!isAdmin() || !$('allSitesBody')) return;
    var sourceList = siteList && siteList.length ? siteList : Object.keys(sites).map(function (k) {
      var s = sites[k];
      return { key: k, name: s.name, category: s.category, total: s.rows.length, needsFix: s.rows.filter(function (r) { return get(r, 'status') === 'Needs Fix'; }).length, ok: s.rows.filter(function (r) { return get(r, 'status') === 'OK'; }).length, rows: s.rows, siId: ASSIGN[k] || null, pending: s.rows.filter(function (r) { return siState(r).key === 'pending'; }).length };
    }).sort(function (a, b) { return b.total - a.total; });

    var totalPages = Math.max(1, Math.ceil(sourceList.length / allSitesPageSize));
    allSitesPage = Math.min(allSitesPage, totalPages);
    var start = (allSitesPage - 1) * allSitesPageSize;
    var end = start + allSitesPageSize;
    var pageRows = sourceList.slice(start, end);

    var bodyHtml = pageRows.map(function (s) {
      var okCount = 0, pendingCount = 0;
      var cameraRows = sites[s.key] ? sites[s.key].rows : [];
      cameraRows.forEach(function (r) {
        var st = get(r, 'status');
        if (st === 'OK') okCount++;
        if (siState(r).key === 'pending') pendingCount++;
      });
      return '<tr class="row-link" data-key="' + esc(s.key) + '"><td class="place-cell">' + esc(s.name) + '</td><td class="path-cell">' + esc(s.category) + '</td>' +
        '<td>' + siLabel(s.siId) + '</td>' +
        '<td class="mono">' + s.total + '</td>' +
        '<td><span class="badge warn"><span class="dot"></span>' + s.needsFix + '</span></td>' +
        '<td><span class="badge ok"><span class="dot"></span>' + okCount + '</span></td>' +
        '<td><span class="badge danger"><span class="dot"></span>' + pendingCount + '</span></td>' +
        '<td class="mono">&rarr;</td></tr>';
    }).join('');

    $('allSitesBody').innerHTML = bodyHtml || '<tr><td colspan="8" style="text-align:center;color:var(--text-faint);padding:26px;">No sites found</td></tr>';
    $('allSitesPagination').innerHTML = '<div class="pagination-info">Page ' + allSitesPage + ' of ' + totalPages + ' · ' + sourceList.length + ' sites</div>' +
      '<div class="pagination-btns"><button class="mini-btn" data-page="prev" ' + (allSitesPage <= 1 ? 'disabled' : '') + '>Previous</button>' +
      '<button class="mini-btn" data-page="next" ' + (allSitesPage >= totalPages ? 'disabled' : '') + '>Next</button></div>';
  }

  function renderOverview() {
    var total = ROWS.length, fix = 0, ok = 0, pend = 0;
    ROWS.forEach(function (r) {
      var st = get(r, 'status');
      if (st === 'Needs Fix') fix++; else if (st === 'OK') ok++;
      if (siState(r).key === 'pending') pend++;
    });
    var admin = isAdmin();
    var html = statCard(siteList.length.toLocaleString(), admin ? 'Sites in register' : 'Sites assigned to you', '') +
      statCard(total.toLocaleString(), 'Total cameras', '') +
      statCard(fix.toLocaleString(), 'Cameras needing fix', 'warn') +
      statCard(pend.toLocaleString(), 'Pending check', pend ? 'pending' : '') +
      statCard((total ? Math.round(ok / total * 100) : 0) + '%', 'Confirmed OK', 'ok');
    if (admin) html += statCard(siteList.filter(function (s) { return !s.siId; }).length.toLocaleString(), 'Unassigned sites', '');
    $('overviewStats').innerHTML = html;

    var empty = !admin && siteList.length === 0;
    $('noSitesMsg').hidden = !empty;
    $('topIssuesTitle').hidden = empty;
    $('topIssuesBody').closest('.table-wrap').hidden = empty;

    var showAllSites = !!admin && siteList.length > 0;
    $('allSitesTitle').hidden = !showAllSites;
    $('allSitesWrap').hidden = !showAllSites;
    if (admin) {
      renderAllSitesTable();
    }

    var top = siteList.filter(function (s) { return s.needsFix > 0; }).sort(function (a, b) { return b.needsFix - a.needsFix; }).slice(0, 15);
    $('topIssuesBody').innerHTML = top.map(function (s) {
      return '<tr class="row-link" data-key="' + esc(s.key) + '"><td class="place-cell">' + esc(s.name) + '</td><td class="path-cell">' + esc(s.category) + '</td>' +
        (admin ? '<td>' + siLabel(s.siId) + '</td>' : '') +
        '<td class="mono">' + s.total + '</td><td><span class="badge warn"><span class="dot"></span>' + s.needsFix + '</span></td><td class="mono">&rarr;</td></tr>';
    }).join('');
    $('siteDatalist').innerHTML = siteList.map(function (s) { return '<option value="' + esc(s.name) + '">'; }).join('');
  }
  $('topIssuesBody').addEventListener('click', rowLinkClick);
  $('allSitesBody').addEventListener('click', rowLinkClick);
  $('allSitesPagination').addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-page]');
    if (!btn) return;
    if (btn.getAttribute('data-page') === 'prev') {
      if (allSitesPage > 1) { allSitesPage--; renderAllSitesTable(); }
    } else if (btn.getAttribute('data-page') === 'next') {
      var maxPage = Math.max(1, Math.ceil(siteList.length / allSitesPageSize));
      if (allSitesPage < maxPage) { allSitesPage++; renderAllSitesTable(); }
    }
  });
  function rowLinkClick(e) {
    if (e.target.closest('input')) return;
    var tr = e.target.closest('tr.row-link'); if (tr) openSite(tr.getAttribute('data-key'));
  }

  /* ------------------------------------------------------------------ */
  /* Search                                                              */
  /* ------------------------------------------------------------------ */
  var searchInput = $('searchInput'), suggestPanel = $('suggestPanel'), activeIndex = -1;
  function matchSites(q) {
    q = q.trim().toLowerCase(); if (!q) return [];
    var startsWithSite = [], containsSite = [], matchesSI = [];
    siteList.forEach(function (s) {
      var siteIndex = s.name.toLowerCase().indexOf(q);
      var siMatch = (s.siNames || []).some(function (name) { return name.toLowerCase().indexOf(q) > -1; });
      if (siteIndex === 0) startsWithSite.push(s);
      else if (siteIndex > 0) containsSite.push(s);
      else if (siMatch) matchesSI.push(s);
    });
    return startsWithSite.concat(containsSite, matchesSI);
  }
  searchInput.addEventListener('input', function () {
    var m = matchSites(searchInput.value).slice(0, 8);
    if (!m.length) { suggestPanel.classList.remove('open'); suggestPanel.innerHTML = ''; return; }
    suggestPanel.innerHTML = m.map(function (s) {
      return '<div class="suggest-item" data-key="' + esc(s.key) + '"><div><div class="suggest-name">' + esc(s.name) + '</div><div class="suggest-path">' + esc(s.category) + (s.siNames && s.siNames.length ? ' · SI: ' + esc(s.siNames.slice(0, 2).join(', ')) : '') + '</div></div>' +
        '<div class="suggest-meta">' + s.total + ' cam' + (s.total === 1 ? '' : 's') + (s.needsFix ? ' · ' + s.needsFix + ' to fix' : '') + '</div></div>';
    }).join('');
    suggestPanel.classList.add('open'); activeIndex = -1;
  });
  suggestPanel.addEventListener('click', function (e) { var el = e.target.closest('.suggest-item'); if (el) openSite(el.getAttribute('data-key')); });
  searchInput.addEventListener('keydown', function (e) {
    var items = suggestPanel.querySelectorAll('.suggest-item');
    function mark() { items.forEach(function (el, i) { el.classList.toggle('active', i === activeIndex); }); if (items[activeIndex]) items[activeIndex].scrollIntoView({ block: 'nearest' }); }
    if (e.key === 'ArrowDown') { e.preventDefault(); if (items.length) { activeIndex = Math.min(activeIndex + 1, items.length - 1); mark(); } }
    else if (e.key === 'ArrowUp') { e.preventDefault(); if (items.length) { activeIndex = Math.max(activeIndex - 1, 0); mark(); } }
    else if (e.key === 'Enter') { e.preventDefault(); if (activeIndex >= 0 && items[activeIndex]) openSite(items[activeIndex].getAttribute('data-key')); else runSearch(searchInput.value); }
    else if (e.key === 'Escape') suggestPanel.classList.remove('open');
  });

  function runSearch(query, keepChecks) {
    suggestPanel.classList.remove('open');
    lastQuery = query;
    var matches = matchSites(query);
    if (!matches.length) { $('emptyQuery').textContent = query; showView('empty'); return; }
    if (matches.length === 1 && !keepChecks) { openSite(matches[0].key); return; }
    resultMatches = matches;
    if (!keepChecks) { resultChecked = {}; matches.forEach(function (s) { resultChecked[s.key] = true; }); }
    $('resultsTitle').textContent = matches.length + ' site' + (matches.length === 1 ? '' : 's') + ' match "' + query + '"';
    renderResults();
    showView('results');
  }
  function checkedKeys() { return resultMatches.filter(function (s) { return resultChecked[s.key]; }).map(function (s) { return s.key; }); }
  function renderResults() {
    var admin = isAdmin();
    $('resultsBody').innerHTML = resultMatches.map(function (s) {
      return '<tr class="row-link" data-key="' + esc(s.key) + '">' +
        (admin ? '<td><input type="checkbox" class="rchk" data-key="' + esc(s.key) + '"' + (resultChecked[s.key] ? ' checked' : '') + '></td>' : '') +
        '<td class="place-cell">' + esc(s.name) + '</td><td class="path-cell">' + esc(s.category) + '</td>' +
        (admin ? '<td>' + siLabel(s.siId) + '</td>' : '') +
        '<td class="mono">' + s.total + '</td><td>' + (s.needsFix ? '<span class="badge warn"><span class="dot"></span>' + s.needsFix + '</span>' : '<span class="badge ok"><span class="dot"></span>0</span>') + '</td><td class="mono">&rarr;</td></tr>';
    }).join('');
    if (admin) {
      $('assignSelect').innerHTML = '<option value="__pick" selected disabled>Choose SI…</option><option value="">— Remove assignment —</option>' +
        SI_USERS.map(function (u) { return '<option value="' + esc(u.id) + '">' + esc(u.name) + ' (' + esc(u.username) + ')</option>'; }).join('');
      updateAssignCount();
    }
  }
  function updateAssignCount() {
    var n = checkedKeys().length;
    $('assignCount').textContent = n + ' of ' + resultMatches.length + ' selected';
    $('resultsCheckAll').checked = n === resultMatches.length;
  }
  $('resultsBody').addEventListener('click', rowLinkClick);
  $('resultsBody').addEventListener('change', function (e) {
    if (e.target.classList.contains('rchk')) { resultChecked[e.target.getAttribute('data-key')] = e.target.checked; updateAssignCount(); }
  });
  $('resultsCheckAll').addEventListener('change', function (e) {
    resultMatches.forEach(function (s) { resultChecked[s.key] = e.target.checked; }); renderResults();
  });
  $('assignBtn').addEventListener('click', function () {
    var v = $('assignSelect').value, keys = checkedKeys();
    if (v === '__pick') { toast('Choose an SI first'); return; }
    if (!keys.length) { toast('Select at least one site'); return; }
    if (!SI_USERS.length && v) { toast('Create an SI account first'); return; }
    assignSites(keys, v || null);
  });
  function assignSites(keys, userId) {
    return api('POST', '/api/assign', { sites: keys, userId: userId }).then(function (r) {
      applyRev(r); ASSIGN = r.assignments; buildIndex(); renderOverview(); refreshView();
      var u = siById(userId);
      toast(userId ? r.count + ' site' + (r.count === 1 ? '' : 's') + ' assigned to ' + (u ? u.name : 'SI') : 'Assignment removed from ' + r.count + ' site' + (r.count === 1 ? '' : 's'));
    }).catch(fail);
  }

  /* ------------------------------------------------------------------ */
  /* Site dashboard                                                      */
  /* ------------------------------------------------------------------ */
  function openSite(key) {
    if (!sites[key]) return;
    currentSiteKey = key; currentStatusFilter = 'all';
    suggestPanel.classList.remove('open');
    searchInput.value = sites[key].name;
    renderSiteDashboard(); showView('site'); window.scrollTo(0, 0);
  }
  function renderSiteDashboard() {
    var s = sites[currentSiteKey]; if (!s) return goHome();
    $('sitePath').textContent = s.category || 'Uncategorized';
    $('siteName').textContent = s.name;
    var total = s.rows.length, fix = 0, ok = 0, nod = 0, pend = 0, counts = {};
    s.rows.forEach(function (r) {
      var st = get(r, 'status');
      if (st === 'Needs Fix') fix++; else if (st === 'OK') ok++; else nod++;
      if (siState(r).key === 'pending') pend++;
      if (st !== 'OK') (get(r, 'issues') || []).forEach(function (i) { counts[i] = (counts[i] || 0) + 1; });
    });
    $('siteCount').textContent = total + ' camera' + (total === 1 ? '' : 's') + ' on this site';

    var siteContact = '';
    var contractorRow = s.rows.find(function (r) { return get(r, 'integrator'); });
    var contactRow = s.rows.find(function (r) { return get(r, 'siContactMobile') || get(r, 'siContactEmail'); });
    if (contractorRow || contactRow) {
      var name = contractorRow ? get(contractorRow, 'integrator') : '';
      var mobile = contactRow ? get(contactRow, 'siContactMobile') : '';
      var email = contactRow ? get(contactRow, 'siContactEmail') : '';
      var parts = [];
      if (name) parts.push('SI Name: ' + name);
      if (mobile) parts.push('Phone: ' + mobile);
      if (email) parts.push('Email: ' + email);
      siteContact = parts.join(' · ');
    }
    $('siteContact').innerHTML = siteContact ? '<span class="site-contact-label">SI contact</span><span class="site-contact-value">' + esc(siteContact) + '</span>' : '';

    if (isAdmin()) {
      var cur = ASSIGN[currentSiteKey] || '', u = siById(cur);
      $('siAssign').innerHTML = '<span>Assigned SI:</span><span class="si-chip' + (u ? '' : ' none') + '">' + (u ? esc(u.name) + ' · ' + esc(u.username) : 'Unassigned') + '</span>' +
        '<select id="siSelect" aria-label="Change SI"><option value=""' + (cur ? '' : ' selected') + '>Unassigned</option>' +
        SI_USERS.map(function (x) { return '<option value="' + esc(x.id) + '"' + (x.id === cur ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('') + '</select>';
      $('siSelect').addEventListener('change', function (e) { assignSites([currentSiteKey], e.target.value || null); });
    } else $('siAssign').innerHTML = '';

    $('siteStats').innerHTML = statCard(total, 'Total cameras', '') + statCard(ok, 'Confirmed OK', 'ok') + statCard(fix, 'Needs fix', fix ? 'warn' : '') + statCard(pend, 'Pending check', pend ? 'pending' : '') + statCard(nod, 'No data yet', '');

    var entries = Object.keys(counts).map(function (k) { return [k, counts[k]]; }).sort(function (a, b) { return b[1] - a[1]; });
    $('issueSection').hidden = !entries.length;
    if (entries.length) {
      var max = entries[0][1];
      $('issueBars').innerHTML = entries.map(function (e) {
        return '<div class="issue-bar-row"><div class="issue-bar-label">' + esc(e[0]) + '</div><div class="issue-bar-track"><div class="issue-bar-fill" style="width:' + Math.max(6, Math.round(e[1] / max * 100)) + '%"></div></div><div class="issue-bar-count">' + e[1] + '</div></div>';
      }).join('');
    }
    var filters = [['all', 'All (' + total + ')'], ['Needs Fix', 'Needs fix (' + fix + ')'], ['PendingCheck', 'Pending check (' + pend + ')'], ['OK', 'OK (' + ok + ')'], ['No Data', 'No data (' + nod + ')']];
    $('statusFilters').innerHTML = filters.map(function (f) { return '<button class="chip' + (currentStatusFilter === f[0] ? ' active' : '') + '" data-status="' + esc(f[0]) + '">' + esc(f[1]) + '</button>'; }).join('');
    renderCameraTable();
  }
  $('statusFilters').addEventListener('click', function (e) {
    var b = e.target.closest('.chip'); if (!b) return;
    currentStatusFilter = b.getAttribute('data-status');
    $('statusFilters').querySelectorAll('.chip').forEach(function (c) { c.classList.toggle('active', c === b); });
    renderCameraTable();
  });

  function renderCameraTable() {
    var s = sites[currentSiteKey], admin = isAdmin();
    var rows = s.rows.filter(function (r) {
      if (currentStatusFilter === 'all') return true;
      if (currentStatusFilter === 'PendingCheck') return siState(r).key === 'pending';
      return get(r, 'status') === currentStatusFilter;
    });
    $('cameraBody').innerHTML = rows.map(function (r) {
      var st = get(r, 'status'), id = get(r, 'id'), sst = siState(r), remark = get(r, 'remark');
      var details = issueTags(get(r, 'issues')) + (remark ? '<div style="margin-top:4px;color:var(--text-dim);font-size:12px;">' + esc(remark) + '</div>' : '');
      if (!details) details = '<span style="color:var(--text-faint);">&mdash;</span>';

      var si = '<div class="si-cell"><span class="badge ' + sst.cls + '"><span class="dot"></span>' + esc(sst.label) + '</span>';
      if (sst.at) si += '<span class="si-meta">' + fmt(sst.at) + (sst.by ? ' · ' + esc(sst.by) : '') + '</span>';
      var acts = '';
      if (!admin && (sst.key === 'awaiting' || sst.key === 'reopened')) acts = '<button class="mini-btn mini-fix" data-id="' + esc(id) + '" data-action="si_fixed">Mark fixed</button>';
      if (admin && sst.key === 'pending') acts = '<button class="mini-btn mini-ok" data-id="' + esc(id) + '" data-action="check_ok">Verified OK</button><button class="mini-btn mini-reject" data-id="' + esc(id) + '" data-action="check_notok">Not fixed</button>';
      if (acts) si += '<div class="si-actions">' + acts + '</div>';
      si += '</div>';

      var rej = latestRejection(r), r2 = '<span style="color:var(--text-faint);">&mdash;</span>';
      if (rej) r2 = '<div class="si-cell">' + issueTags(rej.issues) + (rej.note ? '<span class="si-note">"' + esc(rej.note) + '"</span>' : '') + '<span class="si-meta">' + fmt(rej.t) + (rej.by ? ' · ' + esc(rej.by) : '') + '</span></div>';

      return '<tr><td>' + esc(get(r, 'channel')) + '</td>' +
        '<td><span class="badge ' + (st === 'OK' ? 'ok' : st === 'Needs Fix' ? 'warn' : 'neutral') + '"><span class="dot"></span>' + esc(st) + '</span></td>' +
        '<td>' + si + '</td><td>' + esc(get(r, 'chCategory')) + '</td><td>' + esc(get(r, 'camType')) + '</td>' +
        '<td class="mono">' + esc(get(r, 'model')) + '</td><td class="mono">' + esc(get(r, 'fw')) + '</td>' +
        '<td>' + details + '</td><td>' + r2 + '</td>' +
        (admin ? '<td><button class="icon-btn" data-id="' + esc(id) + '" data-action="edit">Edit</button></td>' : '') + '</tr>';
    }).join('') || '<tr><td colspan="' + (admin ? '10' : '9') + '" style="text-align:center;color:var(--text-faint);padding:30px;">No cameras in this category</td></tr>';
  }

  function rowById(id) { for (var i = 0; i < ROWS.length; i++) if (get(ROWS[i], 'id') === id) return ROWS[i]; return null; }
  function replaceRow(row) {
    for (var i = 0; i < ROWS.length; i++) if (get(ROWS[i], 'id') === get(row, 'id')) { ROWS[i] = row; return; }
    ROWS.push(row);
  }
  function afterChange(r) { applyRev(r); buildIndex(); renderOverview(); refreshView(); }

  $('cameraBody').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-action]'); if (!b) return;
    var id = b.getAttribute('data-id'), action = b.getAttribute('data-action');
    if (action === 'edit') { var row = rowById(id); if (row) openModal(row); return; }
    if (action === 'check_notok') { openReject(id); return; }
    b.disabled = true;
    api('POST', '/api/cameras/' + encodeURIComponent(id) + '/action', { action: action }).then(function (r) {
      replaceRow(r.row); afterChange(r);
      toast(action === 'si_fixed' ? 'Marked as fixed — the admin will verify it' : 'Verified OK — Comment 1 will be OK in the Excel export');
    }).catch(function (err) { fail(err); b.disabled = false; });
  });

  /* ------------------------------------------------------------------ */
  /* "Not fixed" (admin) -> Remark 2 + message to SI                     */
  /* ------------------------------------------------------------------ */
  var rejectId = null;
  $('rejectIssueGrid').innerHTML = ISSUE_TYPES.map(function (t) { return '<label class="issue-check"><input type="checkbox" value="' + esc(t) + '"><span>' + esc(t) + '</span></label>'; }).join('');
  var rejectBoxes = Array.prototype.slice.call($('rejectIssueGrid').querySelectorAll('input'));
  function openReject(id) {
    var row = rowById(id); if (!row) return;
    rejectId = id; $('rejectNote').value = '';
    var cur = get(row, 'issues') || [];
    rejectBoxes.forEach(function (cb) { cb.checked = cur.indexOf(cb.value) > -1; });
    $('rejectModalOverlay').classList.add('open');
  }
  function closeReject() { $('rejectModalOverlay').classList.remove('open'); rejectId = null; }
  $('rejectModalClose').addEventListener('click', closeReject);
  $('rejectModalCancel').addEventListener('click', closeReject);
  $('rejectModalConfirm').addEventListener('click', function () {
    var issues = rejectBoxes.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
    if (!issues.length) { toast('Select at least one issue'); return; }
    api('POST', '/api/cameras/' + encodeURIComponent(rejectId) + '/action', { action: 'check_notok', issues: issues, note: $('rejectNote').value }).then(function (r) {
      replaceRow(r.row); afterChange(r); closeReject();
      toast('Sent back to the SI — they have been notified');
    }).catch(fail);
  });

  /* ------------------------------------------------------------------ */
  /* Notifications                                                       */
  /* ------------------------------------------------------------------ */
  function loadNotifications(announce) {
    return api('GET', '/api/notifications').then(function (r) {
      NOTIFS = r.items;
      if (announce && r.unread > lastUnread) toast(isAdmin() ? 'New SI fix update (' + r.unread + ' unread)' : 'New message from the admin (' + r.unread + ' unread)');
      lastUnread = r.unread; renderBell();
    });
  }
  function renderBell() {
    var c = $('bellCount'); c.hidden = lastUnread === 0; c.textContent = lastUnread > 9 ? '9+' : String(lastUnread);
    $('notifList').innerHTML = NOTIFS.length ? NOTIFS.map(function (n) {
      return '<div class="notif-item' + (n.read ? '' : ' unread') + '" data-id="' + esc(n.id) + '" data-site="' + esc(siteKeyOf(n.site)) + '">' +
        '<div class="notif-title">' + (isAdmin() ? 'SI marked fixed — ready to verify' : 'Camera not fixed — needs another look') + '</div>' +
        '<div class="notif-site">' + esc(n.site) + '</div><div class="mono" style="font-size:11.5px;margin-bottom:6px;word-break:break-all;">' + esc(n.channel) + '</div>' +
        issueTags(n.issues) + (n.note ? '<div class="notif-note">"' + esc(n.note) + '"</div>' : '') +
        '<div class="notif-time">' + fmt(n.at) + (n.by ? ' · from ' + esc(n.by) : '') + '</div></div>';
    }).join('') : '<div class="notif-empty">No messages yet</div>';
  }
  function dismissNotifications(body) {
    return api('POST', '/api/notifications/dismiss', body).then(function (r) { lastUnread = r.unread; return loadNotifications(false); }).catch(function () {});
  }
  $('bellBtn').addEventListener('click', function (e) { e.stopPropagation(); closePops('notifPanel'); $('notifPanel').classList.toggle('open'); });
  $('markAllRead').addEventListener('click', function () { dismissNotifications({ all: true }); });
  $('notifList').addEventListener('click', function (e) {
    var it = e.target.closest('.notif-item'); if (!it) return;
    dismissNotifications({ ids: [it.getAttribute('data-id')] });
    $('notifPanel').classList.remove('open');
    openSite(it.getAttribute('data-site'));
  });

  /* ---------- popups ---------- */
  function closePops(except) { ['notifPanel', 'userPanel'].forEach(function (id) { if (id !== except) $(id).classList.remove('open'); }); }
  $('userBtn').addEventListener('click', function (e) { e.stopPropagation(); closePops('userPanel'); $('userPanel').classList.toggle('open'); });
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.pop-wrap')) closePops();
    if (!e.target.closest('.search-wrap')) suggestPanel.classList.remove('open');
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closePops(); });

  /* ------------------------------------------------------------------ */
  /* Add / edit camera (admin)                                           */
  /* ------------------------------------------------------------------ */
  var editingId = null;
  $('issueCheckGrid').innerHTML = '<label class="issue-check"><input type="checkbox" id="chkOK"><span>Mark as OK (no issues)</span></label>' +
    ISSUE_TYPES.map(function (t) { return '<label class="issue-check"><input type="checkbox" value="' + esc(t) + '"><span>' + esc(t) + '</span></label>'; }).join('');
  var chkOK = $('chkOK');
  var issueBoxes = Array.prototype.slice.call($('issueCheckGrid').querySelectorAll('input')).filter(function (c) { return c !== chkOK; });
  chkOK.addEventListener('change', function () { if (chkOK.checked) issueBoxes.forEach(function (c) { c.checked = false; }); });
  issueBoxes.forEach(function (c) { c.addEventListener('change', function () { if (c.checked) chkOK.checked = false; }); });

  var FORM = { fSite: 'place', fCategory: 'category', fChannel: 'channel', fChCategory: 'chCategory', fCamType: 'camType', fModel: 'model', fIp: 'ip', fFw: 'fw', fSwVer: 'swVer', fIntegrator: 'integrator', fRemark: 'remark' };
  function openModal(row, prefillSite, prefillCat) {
    editingId = row ? get(row, 'id') : null;
    $('modalTitle').textContent = row ? 'Edit camera' : 'Add camera';
    $('modalDelete').hidden = !row;
    Object.keys(FORM).forEach(function (id) { $(id).value = row ? (get(row, FORM[id]) || '') : ''; });
    chkOK.checked = false; issueBoxes.forEach(function (c) { c.checked = false; });
    if (row) { chkOK.checked = get(row, 'status') === 'OK'; var cur = get(row, 'issues') || []; issueBoxes.forEach(function (c) { c.checked = cur.indexOf(c.value) > -1; }); }
    else { if (prefillSite) $('fSite').value = prefillSite; if (prefillCat) $('fCategory').value = prefillCat; }
    $('modalOverlay').classList.add('open'); $('fSite').focus();
  }
  function closeModal() { $('modalOverlay').classList.remove('open'); editingId = null; }
  $('modalClose').addEventListener('click', closeModal);
  $('modalCancel').addEventListener('click', closeModal);
  $('addCameraBtn').addEventListener('click', function () { openModal(null); });
  $('addCameraToSite').addEventListener('click', function () { var s = sites[currentSiteKey]; openModal(null, s && s.name, s && s.category); });
  $('emptyReset').insertAdjacentHTML('afterend', '');

  $('modalSave').addEventListener('click', function () {
    var body = { issues: issueBoxes.filter(function (c) { return c.checked; }).map(function (c) { return c.value; }), ok: chkOK.checked };
    Object.keys(FORM).forEach(function (id) { body[FORM[id]] = $(id).value; });
    if (!body.place.trim() || !body.channel.trim()) { toast('Site name and channel name are required'); return; }
    var req = editingId ? api('PUT', '/api/cameras/' + encodeURIComponent(editingId), body) : api('POST', '/api/cameras', body);
    req.then(function (r) {
      replaceRow(r.row); applyRev(r); buildIndex(); renderOverview(); closeModal();
      toast(editingId ? 'Camera updated' : 'Camera added');
      openSite(siteKeyOf(get(r.row, 'place')));
    }).catch(fail);
  });
  $('modalDelete').addEventListener('click', function () {
    if (!editingId || !window.confirm('Delete this camera entry? This cannot be undone.')) return;
    var id = editingId;
    api('DELETE', '/api/cameras/' + encodeURIComponent(id)).then(function (r) {
      ROWS = ROWS.filter(function (x) { return get(x, 'id') !== id; });
      closeModal(); afterChange(r); toast('Camera deleted');
    }).catch(fail);
  });

  /* ------------------------------------------------------------------ */
  /* SI accounts (admin)                                                 */
  /* ------------------------------------------------------------------ */
  $('usersBtn').addEventListener('click', function () { renderUsers(); showView('users'); window.scrollTo(0, 0); });
  function renderUsers() {
    $('usersBody').innerHTML = SI_USERS.map(function (u) {
      var mine = siteList.filter(function (s) { return s.siId === u.id; });
      var cams = mine.reduce(function (n, s) { return n + s.total; }, 0);
      return '<tr><td class="place-cell">' + esc(u.name) + '</td><td class="mono">' + esc(u.username) + '</td><td class="mono">' + mine.length + '</td><td class="mono">' + cams + '</td>' +
        '<td><div class="row-actions"><button class="icon-btn" data-act="pw" data-id="' + esc(u.id) + '">Reset password</button><button class="icon-btn" data-act="del" data-id="' + esc(u.id) + '">Delete</button></div></td></tr>';
    }).join('') || '<tr><td colspan="5" style="text-align:center;color:var(--text-faint);padding:26px;">No SI accounts yet — create the first one above.</td></tr>';
  }
  function genPassword() {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789', out = '', a = new Uint32Array(12);
    crypto.getRandomValues(a); for (var i = 0; i < a.length; i++) out += chars[a[i] % chars.length];
    return out;
  }
  $('nuGen').addEventListener('click', function () { $('nuPass').value = genPassword(); });
  $('nuCreate').addEventListener('click', function () {
    $('nuError').textContent = '';
    var body = { name: $('nuName').value, username: $('nuUser').value, password: $('nuPass').value };
    api('POST', '/api/users', body).then(function (r) {
      SI_USERS.push(r.user); applyRev(r);
      $('createdBox').hidden = false;
      $('createdBox').innerHTML = '<strong>Account created.</strong> Give the SI these sign-in details:<br>Address: <code>' + esc(location.origin) + '</code><br>Username: <code>' + esc(body.username) + '</code><br>Password: <code>' + esc(body.password) + '</code><br><span style="color:var(--text-dim);">This password is not shown again — copy it now. Next: search a site and assign it to this SI.</span>';
      $('nuName').value = ''; $('nuUser').value = ''; $('nuPass').value = '';
      renderUsers();
    }).catch(function (err) { $('nuError').textContent = err.message; });
  });
  $('usersBody').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-act]'); if (!b) return;
    var id = b.getAttribute('data-id'), u = siById(id); if (!u) return;
    if (b.getAttribute('data-act') === 'pw') openPw('reset', u);
    else if (window.confirm('Delete the account "' + u.name + '"? Their ' + siteList.filter(function (s) { return s.siId === id; }).length + ' site(s) become unassigned.')) {
      api('DELETE', '/api/users/' + id).then(function (r) {
        SI_USERS = SI_USERS.filter(function (x) { return x.id !== id; }); ASSIGN = r.assignments; applyRev(r);
        buildIndex(); renderOverview(); renderUsers(); toast('Account deleted');
      }).catch(fail);
    }
  });

  /* ---------- Password modal (own + reset) ---------- */
  var pwTarget = null;
  function openPw(mode, user) {
    pwTarget = mode === 'reset' ? user : null;
    $('pwTitle').textContent = mode === 'reset' ? 'Reset password — ' + user.name : 'Change password';
    $('pwCurrentWrap').hidden = mode === 'reset';
    $('pwCurrent').value = ''; $('pwNew').value = mode === 'reset' ? genPassword() : ''; $('pwError').textContent = '';
    $('pwNew').type = mode === 'reset' ? 'text' : 'password';
    $('pwOverlay').classList.add('open');
  }
  function closePw() { $('pwOverlay').classList.remove('open'); }
  $('changePwBtn').addEventListener('click', function () { closePops(); openPw('self'); });
  $('pwClose').addEventListener('click', closePw);
  $('pwCancel').addEventListener('click', closePw);
  $('pwSave').addEventListener('click', function () {
    $('pwError').textContent = '';
    var req = pwTarget ? api('POST', '/api/users/' + pwTarget.id + '/password', { password: $('pwNew').value })
                       : api('POST', '/api/me/password', { current: $('pwCurrent').value, next: $('pwNew').value });
    req.then(function () {
      var msg = pwTarget ? 'Password reset. New password for ' + pwTarget.name + ': ' + $('pwNew').value : 'Password changed';
      closePw(); toast(msg);
    }).catch(function (err) { $('pwError').textContent = err.message; });
  });

  /* ------------------------------------------------------------------ */
  /* Excel export (rows visible to this user)                            */
  /* ------------------------------------------------------------------ */
  var HEADERS = ['Extreme Channel Code','AFR Code','Channel Name','Final Status','Comment 1','Comment 2','Comment 3','Comment 4','Date','Remark 2','Date','Remark 3','Date','Remark 4','Remark 4 Date','System Integrator','Channel Category','Camera Type','Latust firmware Version','Firmware Updated or Not','Model','Longitude','Latitude','Software Version','Organization','SI Name','SI Contact Person Name','SI Contact Person Mobile Number','Email Id'];
  function excelDate(iso) {
    if (!iso) return '';
    var date = new Date(iso);
    return isNaN(date.getTime()) ? iso : date.toISOString().slice(0, 19).replace('T', ' ');
  }
  function excelRow(r) {
    var issues = get(r, 'issues') || [], c = ['', '', '', ''], history = activityOf(r);
    issues.slice(0, 4).forEach(function (issue, i) { c[i] = issue; });
    var verified = history.filter(function (item) { return item.a === 'CHECK_OK'; });
    var rejected = history.filter(function (item) { return item.a === 'CHECK_NOTOK'; });
    var remarks = ['', '', ''], remarkDates = ['', '', ''];
    rejected.forEach(function (item, index) {
      var slot = Math.min(index, 2), text = (item.issues || []).join(', ');
      if (item.note) text += (text ? ' - ' : '') + item.note;
      remarks[slot] += (remarks[slot] ? '\n' : '') + text;
      remarkDates[slot] += (remarkDates[slot] ? '\n' : '') + excelDate(item.t);
    });
    return [get(r, 'id'), '', get(r, 'channel'), get(r, 'status') === 'OK' ? 'OK' : '', c[0], c[1], c[2], c[3], excelDate(verified.length ? verified[verified.length - 1].t : ''), remarks[0], remarkDates[0], remarks[1], remarkDates[1], remarks[2], remarkDates[2], '', get(r, 'chCategory'), get(r, 'camType'), get(r, 'fw'), '', get(r, 'model'), get(r, 'lon'), get(r, 'lat'), get(r, 'swVer'), get(r, 'organization'), get(r, 'integrator'), get(r, 'siContactName'), get(r, 'siContactMobile'), get(r, 'siContactEmail')];
  }
  function loadSheetJS() {
    return new Promise(function (resolve, reject) {
      if (window.XLSX) return resolve();
      var s = document.createElement('script'); s.src = 'vendor/xlsx.full.min.js';
      s.onload = resolve; s.onerror = function () { reject(new Error('Could not load the Excel library')); };
      document.head.appendChild(s);
    });
  }
  function downloadExcel(rows, filename) {
    if (!rows || !rows.length) { toast('There are no cameras to export'); return; }
    toast('Preparing your Excel file…');
    loadSheetJS().then(function () {
      var aoa = [HEADERS].concat(rows.map(excelRow));
      var wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(aoa), 'Sheet1');
      XLSX.writeFile(wb, filename + '_' + new Date().toISOString().slice(0, 10) + '.xlsx');
      toast('Excel file downloaded');
    }).catch(fail);
  }
  $('exportExcelBtn').addEventListener('click', function () {
    downloadExcel(ROWS, 'camera-fix-register');
  });
  $('exportSiteExcelBtn').addEventListener('click', function () {
    var site = sites[currentSiteKey];
    if (!isAdmin() || !site) return;
    var filename = 'camera-fix-register_' + site.name.replace(/[^A-Za-z0-9_-]+/g, '_').replace(/^_+|_+$/g, '');
    downloadExcel(site.rows, filename);
  });
  $('importWorkbookBtn').addEventListener('click', function () {
    $('workbookImportFile').click();
  });
  $('workbookImportFile').addEventListener('change', function () {
    var file = this.files && this.files[0];
    if (!file) return;
    apiWorkbookUpload('/api/workbook/import-preview', file).then(function (preview) {
      if (!preview.cameras) {
        toast(preview.duplicateIds ? 'No new camera IDs found; existing cameras were not reimported' : 'No new cameras found in the selected workbook');
        return;
      }
      var sitesToImport = Object.keys(preview.sites || {});
      var siteSummary = sitesToImport.slice(0, 12).map(function (name) { return name + ' (' + preview.sites[name] + ')'; }).join('\n');
      if (sitesToImport.length > 12) siteSummary += '\n…and ' + (sitesToImport.length - 12) + ' more sites';
      var message = 'Import ' + preview.cameras + ' new cameras across ' + sitesToImport.length + ' sites?\n\n' + siteSummary +
        '\n\nExisting camera IDs will be skipped. New rows will be appended to the master workbook. New sites will be unassigned until you assign an SI.';
      if (!window.confirm(message)) return;
      return apiWorkbookUpload('/api/workbook/import', file).then(function (result) {
        toast('Imported ' + result.added + ' cameras across ' + Object.keys(result.sites || {}).length + ' sites');
        return loadAll(false);
      });
    }).catch(fail).then(function () { $('workbookImportFile').value = ''; });
  });

  function activityLabel(item) {
    if (item.a === 'SI_FIXED') return 'SI marked fixed';
    if (item.a === 'CHECK_OK') return 'Admin verified OK';
    if (item.a === 'CHECK_NOTOK') return 'Admin requested a refix';
    return 'Status updated';
  }
  function reportCamera(row) {
    var issues = get(row, 'issues') || [];
    var history = activityOf(row);
    var historyHtml = history.length ? history.map(function (item) {
      var extraIssues = item.issues && item.issues.length ? '<div><strong>Issues:</strong> ' + esc(item.issues.join(', ')) + '</div>' : '';
      var note = item.note ? '<div><strong>Message:</strong> ' + esc(item.note) + '</div>' : '';
      return '<li><strong>' + esc(activityLabel(item)) + '</strong> · ' + esc(fmt(item.t)) + (item.by ? ' · ' + esc(item.by) : '') + extraIssues + note + '</li>';
    }).join('') : '<li>No updates recorded</li>';
    var currentState = siState(row);
    var currentStatus = get(row, 'status') || 'No Data';
    var statusClass = currentStatus === 'OK' ? 'status-ok' : (currentStatus === 'Needs Fix' ? 'status-needs-fix' : 'status-neutral');
    var workflowLabel = currentState.key === 'pending' ? 'Waiting Confirmation' : currentState.label;
    var workflowClass = currentState.key === 'pending' ? 'status-waiting' : (currentState.key === 'verified' ? 'status-ok' : (currentState.key === 'reopened' ? 'status-needs-fix' : 'status-neutral'));
    return '<article class="camera"><h3>' + esc(get(row, 'channel') || 'Unnamed camera') + '</h3>' +
      '<div class="facts"><span class="fact-id"><b>Camera ID</b> ' + esc(get(row, 'id')) + '</span>' +
      '<span class="fact-model"><b>Type / model</b> ' + esc([get(row, 'camType'), get(row, 'model')].filter(Boolean).join(' / ') || '—') + '</span>' +
      '<span class="fact-status"><b>Current status</b> <span class="status-badge ' + statusClass + '">' + esc(currentStatus) + '</span></span>' +
      '<span class="fact-workflow"><b>SI workflow</b> <span class="status-badge ' + workflowClass + '">' + esc(workflowLabel) + '</span></span>' +
      '<span class="fact-firmware"><b>Firmware</b> ' + esc(get(row, 'fw') || '—') + '</span></div>' +
      '<div class="issues"><b>Current issues:</b> ' + esc(issues.length ? issues.join(', ') : 'None') + '</div>' +
      '<h4>Update history</h4><ol>' + historyHtml + '</ol></article>';
  }
  function openPdfReport(selectedSiteKey) {
    var reportSites = selectedSiteKey ? siteList.filter(function (site) { return site.key === selectedSiteKey; }) : siteList;
    if (selectedSiteKey && !reportSites.length) return;
    var reportCameraCount = reportSites.reduce(function (count, site) { return count + (sites[site.key] ? sites[site.key].rows.length : 0); }, 0);
    if (!reportCameraCount) { toast('There are no cameras to export'); return; }
    var popup = window.open('', '_blank');
    if (!popup) { toast('Allow popups to create the SI report'); return; }
    var generated = new Date();
    var singleSite = !!selectedSiteKey;
    var reportSite = singleSite ? reportSites[0] : null;
    var title = (singleSite ? 'Camera site report - ' + reportSite.name : 'SI camera report - ' + ME.name) + ' - ' + generated.toISOString().slice(0, 10);
    var sections = reportSites.map(function (site) {
      var siteData = sites[site.key];
      return '<section class="site"><h2>' + esc(site.name) + '</h2>' +
        (site.category ? '<p class="category">' + esc(site.category) + '</p>' : '') +
        siteData.rows.map(reportCamera).join('') + '</section>';
    }).join('');
    var report = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' +
      '<title>' + esc(title) + '</title><style>' +
      'body{font:12px/1.45 Arial,sans-serif;color:#17202b;margin:28px}h1{font-size:22px;margin:0 0 4px}h2{font-size:16px;margin:0}h3{font-size:14px;margin:0 0 8px;overflow-wrap:anywhere}h4{font-size:12px;margin:12px 0 4px}.meta,.category{color:#57616d}.summary{display:flex;gap:24px;margin:18px 0;padding:12px 0;border-block:1px solid #cbd2d9}.site{margin:24px 0}.site h2{border-bottom:2px solid #0f6db0;padding-bottom:6px}.category{margin:4px 0 10px}.camera{border:1px solid #d8dee4;border-radius:4px;padding:12px;margin:10px 0;break-inside:avoid}.facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 18px}.fact-id,.fact-model,.fact-workflow,.fact-firmware{grid-column:1}.fact-status{grid-column:2}.facts b{margin-right:4px}.status-badge{display:inline-block;padding:1px 7px;border:1px solid;border-radius:4px;font-weight:700;-webkit-print-color-adjust:exact;print-color-adjust:exact}.status-ok{color:#146c43;background:#e8f5ec;border-color:#a8d5b6}.status-needs-fix{color:#a52834;background:#fce8e8;border-color:#efb5b9}.status-waiting{color:#925000;background:#fff0d6;border-color:#edca8d}.status-neutral{color:#57616d;background:#eef1f4;border-color:#d8dee4}.issues{margin-top:8px}.camera ol{margin:4px 0;padding-left:20px}.camera li{margin:5px 0;break-inside:avoid}.camera li div{margin-left:4px;color:#57616d}.empty{padding:24px 0;color:#57616d}@page{size:auto;margin:15mm}@media print{body{margin:0}.site{break-before:auto}.camera{break-inside:avoid}}' +
      '</style></head><body><h1>' + (singleSite ? 'Camera Site Report' : 'SI Camera Update Report') + '</h1><div class="meta">' + esc(ME.name) + ' · Generated ' + esc(generated.toLocaleString()) + '</div>' +
      '<div class="summary">' + (singleSite ? '<span><b>Site:</b> ' + esc(reportSite.name) + '</span>' : '<span><b>Assigned sites:</b> ' + reportSites.length + '</span>') + '<span><b>Cameras:</b> ' + reportCameraCount + '</span></div>' +
      (sections || '<p class="empty">No assigned sites</p>') +
      '<script>window.addEventListener("load",function(){setTimeout(function(){window.print()},250)})<\/script></body></html>';
    popup.document.open(); popup.document.write(report); popup.document.close();
  }
  $('exportSiPdfBtn').addEventListener('click', function () { if (!isAdmin()) openPdfReport(null); });
  $('exportSitePdfBtn').addEventListener('click', function () { if (currentSiteKey && sites[currentSiteKey]) openPdfReport(currentSiteKey); });

  /* ------------------------------------------------------------------ */
  /* Boot                                                                */
  /* ------------------------------------------------------------------ */
  api('GET', '/api/me').then(function (r) { ME = r.user; return startApp(); }).catch(function () { showLogin(); });
})();
