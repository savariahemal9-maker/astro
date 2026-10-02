// Website client. Uses only the public /api/v1 endpoints, exactly as a mobile app would.
(() => {
  const API = window.API_BASE, ASSET = API.replace(/\/api\/v1$/, '') + '/public/assets/', LOGO = ASSET + 'brand/logo-wide.webp';
  const $app = document.getElementById('app');
  const $rail = document.getElementById('rail'), $bnav = document.getElementById('bnav'), $prog = document.getElementById('progress');
  let inflight = 0;
  const $lang = document.getElementById('lang');
  let isAdmin = false;
  let dict = {}, lang = localStorage.getItem('lang') || 'en', token = localStorage.getItem(window.ADMIN_APP ? 'admin_token' : 'token');

  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const t = (key, fallback) => key.split('.').reduce((o, k) => (o && o[k] !== undefined ? o[k] : undefined), dict) ?? fallback ?? key.split('.').pop();
  const tn = (group, name) => t(`astro.${group}.${name}`, name);
  const h = (html) => { $app.classList.remove('enter'); $app.innerHTML = html; void $app.offsetWidth; $app.classList.add('enter'); $app.focus({ preventScroll: true }); stagger($app); window.scrollTo({ top: 0 }); };
  const stagger = (root) => root.querySelectorAll('.interp, .calc, .list .item, figure.vc').forEach((el, i) => el.style.setProperty('--i', Math.min(i, 12)));

  async function api(method, path, body) {
    inflight++; $prog.className = 'on';
    try { return await apiRaw(method, path, body); } finally { if (--inflight === 0) { $prog.className = 'done'; setTimeout(() => { if (!inflight) $prog.className = ''; }, 400); } }
  }
  async function apiRaw(method, path, body) {
    const ctl = new AbortController(), tmo = setTimeout(() => ctl.abort(), 45000);
    const res = await fetch(API + path, { signal: ctl.signal,
      method, headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    }).catch(e => { clearTimeout(tmo); const er = new Error(e.name === 'AbortError' ? 'Server is taking too long. Please try again.' : 'Network error. Please check your connection.'); er.code = 'network'; throw er; });
    clearTimeout(tmo);
    const json = await res.json().catch(() => ({ ok: false, error: { code: 'network', message: 'No response from server' } }));
    if (!json.ok) {
      if (json.error.code === 'unauthorized') { setToken(null); location.hash = window.ADMIN_APP ? '#/' : '#/login'; }
      const e = new Error(json.error.message); e.code = json.error.code; e.details = json.error.details; throw e;
    }
    return json.data;
  }
  const setToken = v => { token = v; const tk = window.ADMIN_APP ? 'admin_token' : 'token'; v ? localStorage.setItem(tk, v) : localStorage.removeItem(tk); renderNav(); };

  async function loadLang(l) {
    lang = l; localStorage.setItem('lang', l); $lang.value = l; document.documentElement.lang = l;
    dict = await api('GET', `/meta/i18n?lang=${l}`);
    document.querySelectorAll('[data-t]').forEach(el => el.textContent = t(el.dataset.t));
  }
  $lang.addEventListener('change', async () => {
    await loadLang($lang.value);
    if (token && !window.ADMIN_APP) api('PATCH', '/me', { lang }).catch(() => {});
    route();
  });

  function renderNav() {
    const cur = location.hash.split('/')[1] || (token ? 'dashboard' : 'home');
    const is = k => cur === k || (k === 'dashboard' && ['chart', 'print', 'charts', 'edit', 'add'].includes(cur)) || (k === 'ai-chat' && cur === 'chat') || (k === 'profile' && cur === 'admin');
    const a = ([k, l, ic], cls = '') => `<a href="#/${k}" class="${cls}${is(k) ? ' on' : ''}"${is(k) ? ' aria-current="page"' : ''}><span class="ms">${ic}</span><span>${esc(t(l))}</span></a>`;
    // desktop top navigation
    const top = [['home', 'home.nav', 'home'], ...(token ? [['dashboard', 'ui.nav_kundali', 'auto_stories']] : []), ['rashifal', 'rf.title', 'stars'], ['panchang', 'ui.panchang', 'calendar_month'],
      ...(token ? [['predict', 'ui.nav_predict', 'auto_awesome'], ['milan', 'ui.kundali_milan', 'favorite'], ['ai-chat', 'ui.nav_claude', 'psychology']] : [])];
    $rail.innerHTML = top.map(x => a(x)).join('');
    // account area
    document.getElementById('acct').innerHTML = token
      ? `<a class="acct-btn${is('profile') ? ' on' : ''}" href="#/profile" aria-label="${esc(t('ui.my_profile'))}"><span class="ms">account_circle</span></a>`
      : `<a class="btn text" href="#/login">${esc(t('ui.sign_in'))}</a><a class="btn" href="#/register">${esc(t('ui.register'))}</a>`;
    // drawer (phones / narrow screens): everything
    const all = [['home', 'home.nav', 'home'], ['rashifal', 'rf.title', 'stars'], ['panchang', 'ui.panchang', 'calendar_month'], ...(token ? [
      ['dashboard', 'ui.nav_kundali', 'auto_stories'], ['add', 'ui.add_chart', 'person_add'], ['predict', 'ui.personal_predictions', 'auto_awesome'], ['milan', 'ui.kundali_milan', 'favorite'],
      ['ai-chat', 'ui.nav_claude', 'psychology'], ['chat', 'ui.chat', 'forum'], ['profile', 'ui.my_profile', 'account_circle'], ['logout', 'ui.sign_out', 'logout']]
      : [['login', 'ui.sign_in', 'login'], ['register', 'ui.register', 'person_add']])];
    document.getElementById('drawer').innerHTML = `<div class="drawer-h"><img src="${LOGO}" alt="${esc(t('ui.app_name'))}"></div>${all.map(x => a(x)).join('')}`;
    // phones: five destinations
    const bl = token ? [['home', 'home.nav', 'home'], ['dashboard', 'ui.nav_kundali', 'auto_stories'], ['rashifal', 'rf.title', 'stars'], ['ai-chat', 'ui.nav_chat', 'forum'], ['profile', 'ui.nav_profile', 'account_circle']]
      : [['home', 'home.nav', 'home'], ['rashifal', 'rf.title', 'stars'], ['panchang', 'ui.panchang', 'calendar_month'], ['login', 'ui.sign_in', 'login']];
    $bnav.innerHTML = bl.map(x => a(x)).join('');
    renderStrip(); renderFooter();
  }
  // traditional header strip: today's date, tithi and nakshatra for the saved place
  let stripPc = null;
  function renderStrip() {
    const el = document.getElementById('tsText'); if (!el) return;
    const place = JSON.parse(localStorage.getItem('place') || 'null') || { name: 'Ahmedabad', lat: 23.0225, lon: 72.5714, tzid: 'Asia/Kolkata' };
    const d = new Date().toLocaleDateString(lang === 'en' ? 'en-IN' : lang + '-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    const put = () => { el.textContent = stripPc ? `${d} · ${tn('paksha', stripPc.tithi[0].paksha)} ${tn('tithis', stripPc.tithi[0].name)} · ${tn('nakshatras', stripPc.nakshatra[0].name)} · ${place.name.split(',')[0]}` : d; };
    put(); if (!stripPc) apiRaw('GET', `/panchang?date=${new Date().toLocaleDateString('en-CA')}&lat=${place.lat}&lon=${place.lon}&tzid=${encodeURIComponent(place.tzid)}`).then(p => { stripPc = p; put(); }).catch(() => {});
  }
  function renderFooter() {
    const el = document.getElementById('sfoot'); if (!el) return;
    const L = (h, l) => `<a href="#/${h}">${esc(t(l))}</a>`;
    el.innerHTML = `<div class="sf-in"><div class="sf-brand"><span class="sf-logo"><img src="${LOGO}" alt="${esc(t('ui.app_name'))}"></span><p>${esc(t('home.foot'))}</p></div>
      <div><h4>${esc(t('home.explore_e'))}</h4>${L(token ? 'add' : 'register', 'home.f_kundali')}${L('predict', 'home.f_predict')}${L('ai-chat', 'home.f_ai')}${L('dashboard', 'home.f_remedy')}</div>
      <div><h4>${esc(t('rf.title'))}</h4>${L('rashifal/daily', 'rf.title')}${L('panchang', 'ui.panchang')}${L('home', 'home.nav')}</div></div>
      <div class="sf-copy">© ${new Date().getFullYear()} KarmYog Astro · Vastu · Vedic · Lal Kitab · Panchang</div>`;
  }
  const closeDrawer = () => document.body.classList.remove('drawer-open');
  document.getElementById('scrim').onclick = closeDrawer;
  addEventListener('hashchange', closeDrawer);
  // ---------- UI kit: ripple, toast, dialog, floating fields, tabs indicator, collapsibles, count-up ----------
  document.addEventListener('pointerdown', e => {
    const el = e.target.closest('button, .btn, .rail a, .bnav a, .chip-btn'); if (!el || el.disabled) return;
    const r = el.getBoundingClientRect(), d = Math.max(r.width, r.height), sp = document.createElement('span');
    sp.className = 'ripple'; sp.style.cssText = `width:${d}px;height:${d}px;left:${e.clientX - r.left - d / 2}px;top:${e.clientY - r.top - d / 2}px`;
    el.appendChild(sp); setTimeout(() => sp.remove(), 600);
  });
  const toast = (msg, icon = 'check_circle') => { const el = document.createElement('div'); el.className = 'toast';
    el.innerHTML = `<span class="ms">${icon}</span>${esc(msg)}`; document.getElementById('toasts').appendChild(el);
    setTimeout(() => el.classList.add('out'), 3200); setTimeout(() => el.remove(), 3600); };
  const confirmDialog = (msg) => new Promise(res => { const d = document.getElementById('dialog');
    document.getElementById('dialogMsg').textContent = msg; const [no, yes] = d.querySelectorAll('button');
    no.textContent = t('ui.cancel'); yes.textContent = t('ui.confirm'); d.hidden = false; yes.focus();
    const done = v => { d.hidden = true; no.onclick = yes.onclick = d.onclick = null; res(v); };
    no.onclick = () => done(false); yes.onclick = () => done(true); d.onclick = e => { if (e.target === d) done(false); }; });
  const FIELD_ICONS = { name: 'person', email: 'mail', password: 'lock', label: 'badge', birth_date: 'event', birth_time: 'schedule', place_q: 'location_on',
    tzid: 'public', manual_offset_minutes: 'more_time', date: 'event' };
  function enhanceFields(root) {
    root.querySelectorAll('label:not(.fl)').forEach(l => {
      const inp = l.querySelector('input:not([type=radio]):not([type=checkbox]), select'); if (!inp) return;
      const txt = [...l.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent).join('').trim(); if (!txt) return;
      [...l.childNodes].filter(n => n.nodeType === 3).forEach(n => n.remove());
      l.classList.add('fl'); if (inp.tagName === 'INPUT' && inp.placeholder && inp.placeholder !== ' ') l.classList.add('fixed'); if (inp.tagName === 'INPUT' && !inp.placeholder) inp.placeholder = ' ';
      if (inp.tagName === 'SELECT' || ['date', 'time'].includes(inp.type)) l.classList.add('fixed');
      const ic = FIELD_ICONS[inp.name]; if (ic) { l.classList.add('has-ic'); l.insertAdjacentHTML('afterbegin', `<span class="ms fi">${ic}</span>`); }
      const sp = document.createElement('span'); sp.className = 'fl-label'; sp.textContent = txt; inp.after(sp);
      if (inp.type === 'password') {
        l.classList.add('has-eye'); const eb = document.createElement('button'); eb.type = 'button'; eb.className = 'pw-eye';
        const set = show => { inp.type = show ? 'text' : 'password'; eb.innerHTML = `<span class="ms">${show ? 'visibility_off' : 'visibility'}</span>`; eb.setAttribute('aria-label', t(show ? 'ui.hide_password' : 'ui.show_password')); eb.setAttribute('aria-pressed', show); };
        set(false); eb.onclick = e => { e.preventDefault(); set(inp.type === 'password'); inp.focus(); }; l.appendChild(eb);
      }
    });
  }
  function tabIndicator(tabs) {
    if (!tabs) return; let ind = tabs.querySelector('.ind'); if (!ind) { ind = document.createElement('span'); ind.className = 'ind'; tabs.appendChild(ind); }
    const on = tabs.querySelector('button.on'); if (!on) return;
    ind.style.width = on.offsetWidth + 'px'; ind.style.transform = `translateX(${on.offsetLeft}px)`;
    on.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
  }
  const collapsible = (title, body, open = false, sub = '') => `<div class="coll ${open ? 'open' : ''}"><button class="coll-h" aria-expanded="${open}">
      <span><strong>${esc(title)}</strong>${sub ? `<small>${esc(sub)}</small>` : ''}</span><span class="ms">expand_more</span></button>
      <div class="coll-b"><div>${body}</div></div></div>`;
  document.addEventListener('click', e => { const hd = e.target.closest('.coll-h'); if (!hd) return;
    const c = hd.parentElement; c.classList.toggle('open'); hd.setAttribute('aria-expanded', c.classList.contains('open')); });
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  function countUp(root) { root.querySelectorAll('[data-count]:not(.counted)').forEach(el => { el.classList.add('counted'); const to = +el.dataset.count, dec = +(el.dataset.dec || 0);
    const fin = () => { el.textContent = to.toFixed(dec); };
    if (reduced) return fin(); const t0 = performance.now();
    const step = now => { const k = Math.max(0, Math.min(1, (now - t0) / 900)); el.textContent = (to * (1 - Math.pow(1 - k, 3))).toFixed(dec); if (k < 1) requestAnimationFrame(step); else fin(); };
    requestAnimationFrame(step); }); }
  document.getElementById('railToggle').onclick = () => document.body.classList.toggle('drawer-open');

  const fmtLoc = (s) => s ? esc(s.slice(11) ? `${s.slice(8, 10)}-${s.slice(5, 7)} ${s.slice(11)}` : s) : '—';
  // Material page header: icon tile, eyebrow, title, subtitle, actions
  const ph = (ic, title, sub = '', actions = '', eyebrow = '') => `<header class="ph"><div class="ph-t"><span class="ph-ic"><span class="ms">${ic}</span></span><div>${eyebrow ? `<span class="ph-e">${esc(eyebrow)}</span>` : ''}<h1>${esc(title)}</h1>${sub ? `<p>${esc(sub)}</p>` : ''}</div></div>${actions ? `<div class="ph-a">${actions}</div>` : ''}</header>`;
  const errBox = (e) => `<p class="err" role="alert">${esc(e.message)}</p>`;
  const skel = () => `<div class="skel" role="status" aria-label="${esc(t('ui.loading'))}"><span></span><span></span><span></span></div>`;
  const loading = () => h(skel());
  const calcTag = () => `<span class="tag c"><span class="ms">calculate</span>${esc(t('ui.calculated'))}</span>`;
  const interpTag = () => `<span class="tag i"><span class="ms">auto_awesome</span>${esc(t('ui.interpretation'))}</span>`;

  // ---------- place search (shared by panchang + chart form) ----------
  function placePicker(root, onPick, inputSel = '[name=place_q]') {
    const input = root.querySelector(inputSel), box = root.querySelector('.suggest');
    if (!input || !box) return;
    let timer;
    input.addEventListener('focus', () => { if (innerWidth < 860) setTimeout(() => input.scrollIntoView({ block: 'start', behavior: 'smooth' }), 300); });
    input.addEventListener('input', () => {
      clearTimeout(timer);
      if (input.value.trim().length < 3) { box.innerHTML = ''; return; }
      timer = setTimeout(async () => {
        try {
          const rows = await api('GET', '/geo/search?q=' + encodeURIComponent(input.value));
          box.innerHTML = rows.length ? rows.map((r, i) => { const [c, ...rest] = r.name.split(', ');
            return `<button type="button" class="sg" data-i="${i}"><span class="ms">location_on</span><span><b>${esc(c)}</b><small>${esc(rest.join(', '))}</small></span></button>`; }).join('') : `<p class="sg-none">—</p>`;
          box.querySelectorAll('button').forEach(b => b.onclick = async () => {
            const r = rows[b.dataset.i]; input.value = r.name; box.innerHTML = '';
            let tz = r.tzid || null;
            if (!tz) try { tz = (await api('GET', `/geo/timezone?lat=${r.lat}&lon=${r.lon}&country_code=${r.country_code}`)).tzid; } catch (e) { /* user selects */ }
            onPick({ ...r, tzid: tz });
          });
        } catch (e) { box.innerHTML = errBox(e); }
      }, 400);
    });
  }

  // ---------- Panchang ----------
  async function viewPanchang(tab = 'panchang', date = today()) {
    let place = JSON.parse(localStorage.getItem('place') || 'null') || { name: 'Ahmedabad, Gujarat, India', lat: 23.0225, lon: 72.5714, tzid: 'Asia/Kolkata' };
    const TABS = [['panchang', 'wb_sunny'], ['choghadiya', 'schedule'], ['panchang_chart', 'grid_view']];
    h(`${ph('calendar_month', t('ui.panchang'), place.name, '', t('pc.eyebrow'))}
      <section class="pn-bar"><form id="pf" class="pn-when"><button type="button" class="icon-btn pn-step" data-step="-1" aria-label="${esc(t('pc.prev'))}"><span class="ms">chevron_left</span></button>
          <label class="pn-date"><span class="ms">event</span><input type="date" name="date" value="${date}" required aria-label="${esc(t('ui.date'))}"></label>
          <button type="button" class="icon-btn pn-step" data-step="1" aria-label="${esc(t('pc.next'))}"><span class="ms">chevron_right</span></button>
          <button type="button" class="btn tonal pn-today" id="ptoday">${esc(t('pc.today'))}</button>
          <label class="pn-place"><span class="ms">location_on</span><input name="place_q" value="${esc(place.name)}" autocomplete="off" aria-label="${esc(t('ui.location'))}"></label><div class="suggest"></div></form>
        <div class="pn-tabs" role="tablist">${TABS.map(([x, ic]) => `<button type="button" role="tab" data-t2="${x}" class="${x === tab ? 'on' : ''}"><span class="ms">${ic}</span>${esc(t('ui.' + x))}</button>`).join('')}</div></section>
      <div id="pout"></div>`);
    const f = document.getElementById('pf');
    placePicker(f, p => { if (p.tzid) { place = p; localStorage.setItem('place', JSON.stringify(p)); show(); } });
    $app.querySelectorAll('[data-t2]').forEach(b => b.onclick = () => { $app.querySelectorAll('[data-t2]').forEach(x => x.classList.toggle('on', x === b)); tab = b.dataset.t2; show(); });
    $app.querySelectorAll('[data-step]').forEach(b => b.onclick = () => { const d = new Date(f.date.value + 'T12:00:00'); d.setDate(d.getDate() + +b.dataset.step); f.date.value = d.toLocaleDateString('en-CA'); show(); });
    document.getElementById('ptoday').onclick = () => { f.date.value = today(); show(); };
    f.date.onchange = () => show(); f.onsubmit = e => { e.preventDefault(); show(); };
    async function show() {
      const out = document.getElementById('pout'); out.innerHTML = skel();
      try {
        const p = await api('GET', `/panchang?date=${f.date.value}&lat=${place.lat}&lon=${place.lon}&tzid=${encodeURIComponent(place.tzid)}`);
        const tm = s => s ? esc(s.slice(11)) : '—', kaal = k => `${tm(p[k].start)} – ${tm(p[k].end)}`;
        const mins = x => x ? (+x.slice(11, 13)) * 60 + (+x.slice(14, 16)) : 0, hm = m => { m = ((Math.round(m) % 1440) + 1440) % 1440; return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`; };
        if (tab === 'panchang') {
          const sr = mins(p.sunrise), ss = mins(p.sunset), day = Math.max(1, ss - sr), night = mins(p.next_sunrise) + 1440 - ss, mu = day / 15;
          const abh = [sr + 7 * mu, sr + 8 * mu], brahma = [sr - 2 * night / 15, sr - night / 15];
          const isToday = f.date.value === today(), now = new Date(), nowM = now.getHours() * 60 + now.getMinutes();
          const sunM = isToday ? nowM : Math.round((sr + ss) / 2); // today=current time, other=solar noon
          const k = Math.max(0, Math.min(1, (sunM - sr) / day)), ang = Math.PI * (1 - k), sx = 150 + 120 * Math.cos(ang), sy = 140 - 120 * Math.sin(ang);
          const arc = `<svg class="pn-arc" viewBox="0 0 300 160" aria-hidden="true"><path d="M30 140 A120 120 0 0 1 270 140" class="a0"/>${isToday && sunM > sr && sunM < ss ? `<path d="M30 140 A120 120 0 0 1 ${sx.toFixed(1)} ${sy.toFixed(1)}" class="a1"/>` : ''}<circle cx="${sx.toFixed(1)}" cy="${sy.toFixed(1)}" r="11" class="sun"/>
            <line x1="14" y1="140" x2="286" y2="140" class="hz"/><text x="30" y="158" text-anchor="middle">${tm(p.sunrise)}</text><text x="270" y="158" text-anchor="middle">${tm(p.sunset)}</text></svg>`;
          const t0 = p.tithi[0], limb = (n, ic, lbl, x, name, extra = '') => `<li><span class="pn-n">${n}</span><span class="ms">${ic}</span><div><small>${esc(lbl)}</small><b>${name}</b>${extra}</div>
              ${x && x.ends ? `<em>${esc(t('ui.until'))} ${fmtLoc(x.ends)}</em>` : '<em></em>'}</li>`;
          const nextOf = (list, fn) => list[1] ? `<i>${esc(t('pc.then'))} ${fn(list[1])}</i>` : '';
          const tName = x => esc(tn('paksha', x.paksha) + ' ' + tn('tithis', x.name));
          const bar = (a, b, cls) => `<span class="pn-bar2"><i class="${cls}" style="left:${Math.max(0, (a - sr) / day * 100)}%;width:${Math.max(1, (b - a) / day * 100)}%"></i></span>`;
          const chogNow = (p.choghadiya?.day || []).concat(p.choghadiya?.night || []).find(c => isToday && c.start.slice(11) <= hm(nowM) && hm(nowM) < c.end.slice(11));
          out.innerHTML = `<div class="pn-grid"><div class="pn-main">
            <section class="pn-hero"><div class="pn-h-l"><span class="pn-vara">${esc(tn('weekdays', p.vara.name))} · ${esc(tn('planets', p.vara.lord))}</span>
                <h2>${tName(t0)}</h2><p>${esc(t('ui.until'))} ${fmtLoc(t0.ends)}${p.tithi[1] ? ` · ${esc(t('pc.then'))} ${tName(p.tithi[1])}` : ''}</p>
                <div class="pn-chips"><span><span class="ms">stars</span>${esc(tn('nakshatras', p.nakshatra[0].name))}</span><span><span class="ms">join</span>${esc(tn('yogas', p.yoga[0].name))}</span><span><span class="ms">dark_mode</span>${esc(tn('signs', p.moon_sign))}</span></div></div>
              <div class="pn-h-r">${arc}<div class="pn-sun"><div><span class="ms">wb_twilight</span><b>${tm(p.sunrise)}</b><small>${esc(t('ui.sunrise'))}</small></div><div><span class="ms">wb_sunny</span><b>${tm(p.sunset)}</b><small>${esc(t('ui.sunset'))}</small></div>
                <div><span class="ms">nightlight</span><b>${tm(p.moonrise)}</b><small>${esc(t('ui.moonrise'))}</small></div><div><span class="ms">bedtime</span><b>${tm(p.moonset)}</b><small>${esc(t('ui.moonset'))}</small></div></div></div></section>
            <section class="card pn-limbs"><div class="card-h"><span class="ms">auto_awesome_mosaic</span>${esc(t('pc.five_limbs'))}</div><ol>
              ${limb('१', 'brightness_4', t('ui.tithi'), t0, tName(t0), nextOf(p.tithi, tName))}
              ${limb('२', 'today', t('pc.vara'), null, esc(tn('weekdays', p.vara.name)), `<i>${esc(t('pc.lord'))}: ${esc(tn('planets', p.vara.lord))}</i>`)}
              ${limb('३', 'stars', t('ui.nakshatra'), p.nakshatra[0], esc(tn('nakshatras', p.nakshatra[0].name)), nextOf(p.nakshatra, x => esc(tn('nakshatras', x.name))))}
              ${limb('४', 'join', t('ui.yoga'), p.yoga[0], esc(tn('yogas', p.yoga[0].name)), nextOf(p.yoga, x => esc(tn('yogas', x.name))))}
              ${limb('५', 'hourglass', t('ui.karana'), p.karana[0], esc(tn('karanas', p.karana[0].name)), nextOf(p.karana, x => esc(tn('karanas', x.name))))}</ol></section></div>
          <aside class="pn-side">
            <section class="card pn-good"><div class="card-h"><span class="ms">check_circle</span>${esc(t('pc.shubh'))}</div>
              <div class="pn-t"><b>${esc(t('pc.abhijit'))}</b><span>${hm(abh[0])} – ${hm(abh[1])}</span>${bar(abh[0], abh[1], 'g')}</div>
              <div class="pn-t"><b>${esc(t('pc.brahma'))}</b><span>${hm(brahma[0])} – ${hm(brahma[1])}</span></div>
              ${chogNow ? `<div class="pn-t now"><b>${esc(t('pc.chog_now'))}: ${esc(t('astro.chog.' + chogNow.name))}</b><span>${tm(chogNow.start)} – ${tm(chogNow.end)} · ${esc(t('ui.q_' + chogNow.quality))}</span></div>` : ''}</section>
            <section class="card pn-bad"><div class="card-h"><span class="ms">block</span>${esc(t('pc.ashubh'))}</div>
              ${[['rahu_kaal', 'r'], ['yamaganda', 'y'], ['gulika', 'g2']].map(([key, c]) => `<div class="pn-t"><b>${esc(t('ui.' + key))}</b><span>${kaal(key)}</span>${bar(mins(p[key].start), mins(p[key].end), c)}</div>`).join('')}</section>
            <section class="card pn-signs"><div class="pn-sg"><span class="g">${GLYPH[SIGNS.indexOf(p.sun_sign)] || '☉'}</span><div><small>${esc(t('ui.sun_sign'))}</small><b>${esc(tn('signs', p.sun_sign))}</b></div></div>
              <div class="pn-sg"><span class="g">${GLYPH[SIGNS.indexOf(p.moon_sign)] || '☽'}</span><div><small>${esc(t('ui.moon_sign'))}</small><b>${esc(tn('signs', p.moon_sign))}</b></div></div></section>
          </aside></div>`; }
        else if (tab === 'choghadiya') { const now = new Date(), nowS = `${f.date.value} ${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
          const isNow = c => c.start <= nowS && nowS < c.end;
          const col = (lbl, ic, list) => `<section class="card chogc"><div class="card-h"><span class="ms">${ic}</span>${esc(lbl)}<span class="muted">${tm(list[0].start)} – ${tm(list[7].end)}</span></div>
            <div class="cline">${list.map(c => `<i class="${c.quality} ${isNow(c) ? 'now' : ''}" title="${esc(t('astro.chog.' + c.name))}"></i>`).join('')}</div>
            <div class="cgrid">${list.map(c => `<div class="cg ${c.quality} ${isNow(c) ? 'now' : ''}"><span class="ms">${c.quality === 'good' ? 'check_circle' : c.quality === 'bad' ? 'cancel' : 'radio_button_checked'}</span>
              <div><b>${esc(t('astro.chog.' + c.name))}</b><span>${tm(c.start)} – ${tm(c.end)}</span></div>${isNow(c) ? `<em>${esc(t('ui.now'))}</em>` : `<i>${esc(t('ui.q_' + c.quality))}</i>`}</div>`).join('')}</div></section>`;
          out.innerHTML = p.choghadiya ? `<div class="chogs">${col(t('ui.day_c'), 'light_mode', p.choghadiya.day)}${col(t('ui.night_c'), 'dark_mode', p.choghadiya.night)}</div>
            <div class="chips center"><span class="chip ok"><span class="ms">check_circle</span>${esc(t('ui.q_good'))}</span><span class="chip warn"><span class="ms">radio_button_checked</span>${esc(t('ui.q_neutral'))}</span><span class="chip bad"><span class="ms">cancel</span>${esc(t('ui.q_bad'))}</span></div>` : errBox({ message: t('ui.none') }); }
        else out.innerHTML = p.chart ? `${calcTag()}<div class="chartwrap"><section class="card">${northChart(p.chart, 'D1')}${legend()}</section><section class="card"><table>
          <tr><th>${esc(t('ui.planet'))}</th><th>${esc(t('ui.sign'))}</th><th>${esc(t('ui.nakshatra'))}</th></tr>
          <tr><td>${esc(t('ui.lagna'))}</td><td>${esc(tn('signs', p.chart.lagna.sign_name))} ${esc(p.chart.lagna.dms)}</td><td>${esc(tn('nakshatras', p.chart.lagna.nakshatra))}</td></tr>
          ${p.chart.planets.map(x => `<tr><td>${esc(tn('planets', x.name))} ${status(x)}</td><td>${esc(tn('signs', x.sign_name))} ${esc(x.dms)}</td><td>${esc(tn('nakshatras', x.nakshatra))}</td></tr>`).join('')}</table>
          <p class="muted">${esc(t('ui.panchang_chart'))} · ${tm(p.sunrise)}</p></section></div>` : errBox({ message: t('ui.none') });
      } catch (e) { out.innerHTML = errBox(e); }
    }
    show();
  }

  // ---------- Auth ----------
  const authArt = (title, sub) => `<aside class="au-art"><div><h2>${esc(title)}</h2><p>${esc(sub)}</p></div>
      <ul><li><span class="ms">auto_stories</span>${esc(t('home.f_kundali'))}</li><li><span class="ms">auto_awesome</span>${esc(t('home.f_predict'))}</li>
        <li><span class="ms">psychology</span>${esc(t('home.f_ai'))}</li><li><span class="ms">spa</span>${esc(t('home.f_remedy'))}</li></ul></aside>`;
  const authPage = (title, sub, form) => h(`<div class="au">${authArt(t('home.h1a') + ' ' + t('home.h1b'), t('home.eyebrow'))}<div class="au-f"><h1>${esc(title)}</h1>${sub ? `<p>${esc(sub)}</p>` : ''}${form}</div></div>`);
  function viewAuth(mode) {
    const reg = mode === 'register';
    authPage(t(reg ? 'ui.register' : 'ui.sign_in'), t(reg ? 'ui.register_sub' : 'ui.sign_in_sub'), `<form class="stack" id="af">
        ${reg ? `<label>${esc(t('ui.name'))}<input name="name" required autocomplete="name"></label>` : ''}
        <label>${esc(t('ui.email'))}<input type="email" name="email" required autocomplete="email"></label>
        <label>${esc(t('ui.password'))}<input type="password" name="password" minlength="8" required autocomplete="${reg ? 'new-password' : 'current-password'}"></label>
        <div id="aerr"></div>
        <button class="btn-full">${esc(t(reg ? 'ui.register' : 'ui.sign_in'))}</button>
        <div class="au-alt"><a href="#/${reg ? 'login' : 'register'}">${esc(t(reg ? 'ui.have_account' : 'ui.no_account'))}</a>${reg ? '' : `<a href="#/forgot">${esc(t('ui.forgot_password'))}</a>`}</div>
      </form>`);
    const f = document.getElementById('af');
    f.onsubmit = async e => {
      e.preventDefault(); const b = f.querySelector('button.btn-full'); b.disabled = true;
      try {
        const body = { email: f.email.value, password: f.password.value, lang, client: 'web' };
        if (reg) body.name = f.name.value;
        const d = await api('POST', reg ? '/auth/register' : '/auth/login', body);
        setToken(d.token); if (d.user.lang !== lang) await loadLang(d.user.lang);
        let draft = null; try { draft = JSON.parse(sessionStorage.getItem('kdraft') || 'null'); } catch (er) {}
        if (draft?.place?.lat != null) { try { const p = await quickCreate(draft); sessionStorage.removeItem('kdraft'); location.hash = '#/chart/' + p.id; return; } catch (er) {} }
        location.hash = draft ? '#/add' : '#/dashboard';
      } catch (err) { document.getElementById('aerr').innerHTML = errBox(err); b.disabled = false; }
    };
  }

  function viewForgot() {
    authPage(t('ui.forgot_password'), t('ui.forgot_hint'), `<form class="stack" id="ff"><label>${esc(t('ui.email'))}<input type="email" name="email" required autocomplete="email"></label>
        <div id="ferr"></div><button class="btn-full">${esc(t('ui.send_reset_link'))}</button><div class="au-alt"><a href="#/login">${esc(t('ui.back_to_login'))}</a></div></form>`);
    const f = document.getElementById('ff');
    f.onsubmit = async e => {
      e.preventDefault(); const b = f.querySelector('button.btn-full'); b.disabled = true;
      try { await api('POST', '/auth/forgot', { email: f.email.value }); f.outerHTML = `<div class="m-card tonal"><span class="ms">mark_email_read</span> ${esc(t('ui.reset_sent'))}</div><p><a href="#/login">${esc(t('ui.back_to_login'))}</a></p>`; }
      catch (err) { document.getElementById('ferr').innerHTML = errBox(err); b.disabled = false; }
    };
  }
  // new password + confirmation; used by reset and change-password forms
  const pwPair = () => `<label>${esc(t('ui.new_password'))}<input type="password" name="new_password" minlength="8" required autocomplete="new-password"></label>
    <label>${esc(t('ui.confirm_password'))}<input type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></label>`;
  const pwMismatch = f => f.new_password.value !== f.confirm_password.value ? errBox({ message: t('ui.password_mismatch') }) : '';
  function viewReset(token) {
    authPage(t('ui.reset_password'), '', `<form class="stack" id="rf">${pwPair()}<div id="rerr"></div><button class="btn-full">${esc(t('ui.reset_password'))}</button></form>`);
    const f = document.getElementById('rf');
    f.onsubmit = async e => {
      e.preventDefault(); const err = document.getElementById('rerr'); if ((err.innerHTML = pwMismatch(f))) return;
      const b = f.querySelector('button'); b.disabled = true;
      try { await api('POST', '/auth/reset', { token, password: f.new_password.value }); toast(t('ui.reset_done')); location.hash = '#/login'; }
      catch (e2) { err.innerHTML = errBox(e2); b.disabled = false; }
    };
  }
  async function viewProfile() {
    loading(); let me; try { me = await api('GET', '/me'); } catch (e) { h(errBox(e)); return; }
    h(`${ph('account_circle', t('ui.my_profile'), me.email)}
      <div class="pf-id"><span class="avatar">${esc(me.name.trim().charAt(0).toUpperCase())}</span><div><b>${esc(me.name)}</b><small>${esc(me.email)}</small></div></div>
      <div class="pf-grid"><section class="card"><div class="card-h"><span class="ms">edit</span>${esc(t('ui.edit_profile'))}</div>
        <form class="stack" id="pf"><label>${esc(t('ui.name'))}<input name="name" required maxlength="120" autocomplete="name" value="${esc(me.name)}"></label>
          <label>${esc(t('ui.email'))}<input type="email" name="email" required autocomplete="email" value="${esc(me.email)}"></label>
          <label>${esc(t('ui.language'))}<select name="lang">${[['en', 'English'], ['hi', 'हिन्दी'], ['gu', 'ગુજરાતી']].map(([v, n]) => `<option value="${v}"${me.lang === v ? ' selected' : ''}>${n}</option>`).join('')}</select></label>
          <div id="pwc" hidden><label>${esc(t('ui.current_password'))}<input type="password" name="password" autocomplete="current-password"></label><p class="muted">${esc(t('ui.email_change_hint'))}</p></div>
          <div id="perr"></div><div><button>${esc(t('ui.save'))}</button></div></form></section>
      <section class="card"><div class="card-h"><span class="ms">lock_reset</span>${esc(t('ui.change_password'))}</div>
        <form class="stack" id="cpf"><label>${esc(t('ui.current_password'))}<input type="password" name="current_password" required autocomplete="current-password"></label>${pwPair()}
          <div id="cperr"></div><div><button>${esc(t('ui.change_password'))}</button></div></form></section></div>
      <nav class="pf-list"><a href="#/dashboard"><span class="ms">auto_stories</span>${esc(t('ui.my_charts'))}<span class="ms">chevron_right</span></a>
        <a href="#/ai-chat"><span class="ms">psychology</span>${esc(t('ui.nav_claude'))}<span class="ms">chevron_right</span></a>
        <a href="#/logout"><span class="ms">logout</span>${esc(t('ui.sign_out'))}<span class="ms">chevron_right</span></a></nav>`);
    const pf = document.getElementById('pf'), cpf = document.getElementById('cpf');
    pf.email.oninput = () => { const ch = pf.email.value.trim().toLowerCase() !== me.email; document.getElementById('pwc').hidden = !ch; pf.password.required = ch; };
    pf.onsubmit = async e => {
      e.preventDefault(); const b = pf.querySelector('button'); b.disabled = true; document.getElementById('perr').innerHTML = '';
      try {
        const body = { name: pf.name.value, email: pf.email.value, lang: pf.lang.value }; if (pf.password.value) body.password = pf.password.value;
        const u = await api('PATCH', '/me', body); toast(t('ui.saved'));
        if (u.lang !== lang) await loadLang(u.lang); route();
      } catch (err) { document.getElementById('perr').innerHTML = errBox(err); b.disabled = false; }
    };
    cpf.onsubmit = async e => {
      e.preventDefault(); const err = document.getElementById('cperr'); if ((err.innerHTML = pwMismatch(cpf))) return;
      const b = cpf.querySelector('button'); b.disabled = true;
      try { await api('POST', '/me/password', { current_password: cpf.current_password.value, new_password: cpf.new_password.value }); toast(t('ui.password_changed')); cpf.reset(); }
      catch (e2) { err.innerHTML = errBox(e2); }
      b.disabled = false;
    };
  }

  // ---------- Personalized category predictions ----------
  const PERIODS = ['daily', 'weekly', 'monthly', 'yearly', 'lifetime'];
  const fmtD = (d, o) => new Date(d + 'T12:00:00').toLocaleDateString(lang === 'en' ? 'en-IN' : lang + '-IN', o);
  const rangeText = (r) => r.date ? fmtD(r.date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
    : r.month ? fmtD(r.month + '-01', { month: 'long', year: 'numeric' }) : r.year ? String(r.year)
    : r.start ? `${fmtD(r.start, { day: 'numeric', month: 'short' })} – ${fmtD(r.end, { day: 'numeric', month: 'short', year: 'numeric' })}` : '';
  async function viewPredict() {
    loading(); let profs, cats;
    try { [profs, cats] = await Promise.all([api('GET', '/profiles'), api('GET', '/categories?lang=' + lang)]); } catch (e) { h(errBox(e)); return; }
    const st = (() => { try { return JSON.parse(localStorage.getItem('pp') || '{}'); } catch (e) { return {}; } })();
    const save = () => { try { localStorage.setItem('pp', JSON.stringify(st)); } catch (e) {} };
    if (!profs.find(p => p.id === st.kid)) st.kid = profs[0]?.id;
    if (!PERIODS.includes(st.per)) st.per = 'daily';
    st.date = st.date || today();
    let mine = cats.filter(c => c.selected), editing = !mine.length, seq = 0;
    const PI = { daily: 'today', weekly: 'date_range', monthly: 'calendar_month', yearly: 'event_repeat', lifetime: 'all_inclusive' };
    if (!profs.length) { h(`${ph('auto_awesome', t('ui.personal_predictions'), t('ui.pp_sub'))}<section class="pp-hero"><a class="btn accent" href="#/add"><span class="ms">add</span>${esc(t('ui.new_kundali'))}</a></section><p class="muted">${esc(t('ui.no_kundali'))}</p>`); return; }
    h(`<div class="pp-layout">
      <div class="pp-catbar">
        <div class="pp-catrow" id="ppc"></div>
        <button type="button" class="icon-btn pp-edit" id="ppe" title="${esc(t('ui.manage_categories'))}"><span class="ms">tune</span></button>
      </div>
      <div class="pp-body">
        <aside class="pp-side">
          <div class="pp-side-block">
            <div class="pp-side-label"><span class="ms">person</span>${esc(t('ui.kundali'))}</div>
            <div class="pp-kpick" style="position:relative">
              <button type="button" class="pp-kbtn" id="ppkb" aria-haspopup="listbox" aria-expanded="false"></button>
              <div class="pp-kpanel" id="ppkp" hidden><input type="search" id="ppks" placeholder="${esc(t('ui.search'))}…" autocomplete="off"><ul role="listbox" id="ppkl"></ul></div>
            </div>
            <a class="pp-kadd-link" href="#/add"><span class="ms">add</span>${esc(t('ui.new_kundali'))}</a>
          </div>
          <div class="pp-side-block">
            <div class="pp-side-label"><span class="ms">event</span>${esc(t('ui.date'))}</div>
            <label class="pp-date-full"><input type="date" id="ppd" value="${esc(st.date)}" aria-label="${esc(t('ui.date'))}"></label>
          </div>
          <div class="pp-side-block">
            <div class="pp-side-label"><span class="ms">schedule</span>${esc(t('ui.period'))}</div>
            <div class="pp-seg" id="ppp" role="tablist">${PERIODS.map(p => `<button type="button" role="tab" data-p="${p}"><span class="ms">${PI[p]}</span><span>${esc(t('ui.p_' + p))}</span></button>`).join('')}</div>
          </div>
        </aside>
        <div class="pp-result" id="ppt" aria-live="polite"></div>
      </div>
    </div>`);
    const $c = document.getElementById('ppc'), $t = document.getElementById('ppt');
    const av = p => `<span class="avatar">${esc(p.label.trim().charAt(0).toUpperCase())}</span><span class="pp-pn"><b>${esc(p.label)}</b><small>${esc(p.birth_date)} · ${esc(p.place_name.split(',')[0])}</small></span>`;
    const $kb = document.getElementById('ppkb'), $kp = document.getElementById('ppkp'), $ks = document.getElementById('ppks'), $kl = document.getElementById('ppkl');
    const fill = () => { const q = $ks.value.trim().toLowerCase(), f = profs.filter(p => !q || p.label.toLowerCase().includes(q) || p.place_name.toLowerCase().includes(q)).slice(0, 200);
      $kl.innerHTML = f.map(p => `<li role="option" data-k="${p.id}" aria-selected="${p.id === st.kid}" tabindex="0">${av(p)}</li>`).join('') || `<li class="muted">${esc(t('ui.none'))}</li>`;
      $kl.querySelectorAll('[data-k]').forEach(li => li.onclick = li.onkeydown = e => { if (e.type === 'keydown' && e.key !== 'Enter') return; st.kid = +li.dataset.k; save(); open(false); mark(); show(); }); };
    const open = v => { $kp.hidden = !v; $kb.setAttribute('aria-expanded', v); if (v) { $ks.value = ''; fill(); $ks.focus(); } };
    $kb.onclick = () => open($kp.hidden); $ks.oninput = fill;
    document.addEventListener('click', e => { if (!e.target.closest('.pp-kpick')) open(false); });
    const mark = () => {
      $kb.innerHTML = av(profs.find(p => p.id === st.kid)) + '<span class="ms">expand_more</span>';
      $app.querySelectorAll('[data-p]').forEach(b => { const on = b.dataset.p === st.per; b.classList.toggle('on', on); b.setAttribute('aria-selected', on); });
      const ppdl = document.getElementById('ppd'); if (ppdl) { const pw = ppdl.closest('.pp-side-block'); if(pw) pw.style.display = st.per === 'lifetime' ? 'none' : ''; }
    };
    const chips = () => {
      if (editing) {
        $c.innerHTML = cats.map(c => `<label class="pp-chip pick${c.selected ? ' on' : ''}"><input type="checkbox" value="${c.id}"${c.selected ? ' checked' : ''}><span class="ms">${esc(c.icon)}</span>${esc(c.name)}</label>`).join('') + `<div class="pp-pick-act"><button id="pps"><span class="ms">check</span>${esc(t('ui.save'))}</button>${mine.length ? `<button class="ghost" id="ppx" type="button">${esc(t('ui.cancel'))}</button>` : ''}</div>`;
        $c.querySelectorAll('.pick input').forEach(i => i.onchange = () => i.parentElement.classList.toggle('on', i.checked));
        document.getElementById('ppx')?.addEventListener('click', () => { editing = false; chips(); });
        document.getElementById('pps').onclick = async () => {
          const ids = [...$c.querySelectorAll('.pick input:checked')].map(i => +i.value);
          try { const r = await api('PUT', '/me/categories', { ids }); cats.forEach(c => c.selected = r.ids.includes(c.id)); mine = cats.filter(c => c.selected);
            toast(t('ui.saved')); editing = !mine.length; save(); chips(); } catch (e) { toast(e.message, 'error'); } };
      } else {
        if (!mine.find(c => c.id === st.cat)) st.cat = mine[0]?.id;
        $c.innerHTML = mine.map(c => `<button type="button" class="pp-chip${c.id === st.cat ? ' on' : ''}" data-c="${c.id}"><span class="ms">${esc(c.icon)}</span>${esc(c.name)}</button>`).join('');
        $c.querySelectorAll('[data-c]').forEach(b => b.onclick = () => { st.cat = +b.dataset.c; save(); chips(); });
      }
      show();
    };
    $app.querySelectorAll('[data-p]').forEach(b => b.onclick = () => { st.per = b.dataset.p; save(); mark(); show(); });
    document.getElementById('ppe').onclick = () => { editing = !editing; chips(); };
    document.getElementById('ppd').onchange = e => { st.date = e.target.value || today(); save(); show(); };
    const yr = d => d.slice(0, 4);
    const list = (cls, icon, title, items) => items && items.length ? `<section class="pp-adv ${cls}"><h3><span class="ms">${icon}</span>${esc(title)}</h3><ul>${items.map(x => `<li>${esc(x)}</li>`).join('')}</ul></section>` : '';
    const card = d => `<article class="pp-card lvl-${d.level}">
      <div class="pp-top">
        <div class="pp-top-l">
          <div class="pp-toprow"><span class="pp-badge"><span class="ms">${esc(d.category.icon)}</span>${esc(d.category.name)}</span><span class="pp-range"><span class="ms">event</span>${esc(rangeText(d.range) || t('ui.p_lifetime'))}</span></div>
          <h2 class="pp-hl">${esc(d.headline)}</h2>
          <div class="pp-lvlrow"><span class="pp-lvl">${esc(d.level_text)}</span><small>${esc(d.score_label)}</small></div>
          <p class="pp-exp">${esc(d.explanation)}</p>
          ${d.caution ? `<div class="pp-caution"><span class="ms">report</span><span>${esc(d.caution)}</span></div>` : ''}
        </div>
        <div class="pp-meter" title="${esc(d.score_label)}">${ring(d.score)}<div class="pp-meter-v"><b>${d.score}</b><small>/100</small></div></div>
      </div>
      <div class="pp-2col">
        ${d.personal && d.personal.length ? `<section class="pp-says"><h3><span class="ms">menu_book</span>${esc(t('ui.kundali_says'))}</h3><ul>${d.personal.map(x => `<li class="${x.good ? 'good' : 'care'}"><span class="ms">${x.good ? 'thumb_up' : 'error'}</span><span>${esc(x.text)}</span></li>`).join('')}</ul></section>` : ''}
        <div>
          ${list('upay', 'spa', t('ui.simple_upay'), (d.upay || []).slice(0, 3))}
          ${d.timeline && d.timeline.length ? `<section class="pp-life"><h3><span class="ms">timeline</span>${esc(t('ui.life_phases'))}</h3><ol>${d.timeline.map(x => `<li class="${x.favourable ? 'good' : 'care'}"><b>${yr(x.start)} – ${yr(x.end)}</b><span>${esc(t(x.favourable ? 'ui.good_phase' : 'ui.care_phase'))}</span></li>`).join('')}</ol></section>` : ''}
          <p class="pp-more"><a href="#/chart/${st.kid}" data-open-tab="remedies"><span class="ms">spa</span>${esc(t('ui.all_remedies'))}</a></p>
        </div>
      </div>
      ${collapsible(t('ui.why_details'), `<div class="two"><div><h3>${esc(t('ui.positive_factors'))}</h3><ul class="lines">${d.details.positive.map(x => `<li><b>${esc(x.text)}</b><small>${esc(x.effect)}</small></li>`).join('') || `<li class="muted">${esc(t('ui.none'))}</li>`}</ul></div>
        <div><h3>${esc(t('ui.challenging_factors'))}</h3><ul class="lines">${d.details.challenging.map(x => `<li><b>${esc(x.text)}</b><small>${esc(x.effect)}</small></li>`).join('') || `<li class="muted">${esc(t('ui.none'))}</li>`}</ul></div></div>`)}
      </article>`;
    const show = () => {
      if (!st.cat) { $t.innerHTML = `<p class="pp-empty"><span class="ms">touch_app</span>${esc(t('ui.no_categories'))}</p>`; return; }
      const my = ++seq; $t.innerHTML = skel();
      api('GET', `/profiles/${st.kid}/category-prediction?category=${st.cat}&period=${st.per}&date=${st.date}&lang=${lang}`)
        .then(d => { if (my === seq) $t.innerHTML = card(d); }).catch(e => { if (my === seq) $t.innerHTML = errBox(e); });
    };
    mark(); chips();
  }

  // ---------- Kundali Milan (Ashtakoot): form + saved list, and the report ----------
  async function viewMilan(reportId = 0) {
    const M = k => esc(t('ml.' + k)), band = s => s >= 33 ? 'ex' : s >= 25 ? 'vg' : s >= 18 ? 'ok' : 'low';
    const ring = (s, size = 132) => { const r = 52, c = 2 * Math.PI * r;
      return `<svg class="mm-ring ${band(s)}" width="${size}" height="${size}" viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="${r}" class="bg"/>
        <circle cx="60" cy="60" r="${r}" class="fg" stroke-dasharray="${(s / 36 * c).toFixed(1)} ${c.toFixed(1)}" transform="rotate(-90 60 60)"/></svg>`; };
    if (reportId) {
      loading(); let d;
      try { d = await api('GET', `/milan/${reportId}?lang=${lang}`); } catch (e) { h(errBox(e)); return; }
      const who = (x, n, cls) => `<div class="mm-who ${cls}"><span class="mm-av"><span class="ms">${cls === 'boy' ? 'man' : 'woman'}</span></span>
        <b>${esc(n)}</b><small>${M(cls)}</small><span>${esc(x.rashi)} · ${esc(x.nakshatra)}</span></div>`;
      const who2 = w => w === 'boy' ? `${d.boy_name} · ${d.rem_labels.boy}` : w === 'girl' ? `${d.girl_name} · ${d.rem_labels.girl}` : d.rem_labels.both;
      const remList = (list, bare) => list && list.length ? `<div class="mm-rem">${bare ? '' : `<b class="mm-rh"><span class="ms">spa</span>${esc(d.rem_labels.title)}</b>`}
        <ul>${list.map(([w, tx]) => `<li><span class="mm-who-tag ${w}">${esc(who2(w))}</span>${esc(tx)}</li>`).join('')}</ul></div>` : '';
      const rows = [['rashi', 'rashi'], ['lord', 'lord'], ['nak', 'nakshatra'], ['pada', 'pada'], ['varna', 'varna'], ['vashya', 'vashya'], ['yoni', 'yoni'], ['gana', 'gana'], ['nadi', 'nadi'], ['mangal', 'mangal']];
      h(`<div class="mm">${ph('favorite', t('ml.title'), `${d.boy_name} ${t('ml.vs')} ${d.girl_name}`, `<a class="btn ghost" href="#/milan"><span class="ms">arrow_back</span>${M('back')}</a>`, t('ml.eyebrow'))}
        <section class="mm-hero">${who(d.boy, d.boy_name, 'boy')}
          <div class="mm-score">${ring(d.score)}<div class="mm-sv"><b>${d.score}</b><small>/ 36 ${M('gunas')}</small></div></div>
          ${who(d.girl, d.girl_name, 'girl')}
          <div class="mm-verdict"><span class="mm-pill ${d.band}">${esc(d.verdict)}</span><p>${esc(d.summary)}</p></div></section>
        <section class="card mm-sec"><div class="card-h"><span class="ms">table_chart</span>${M('table')}</div>
          <div class="mm-table"><div class="mm-tr mm-th"><span>${M('koota')}</span><span>${esc(d.boy_name)}</span><span>${esc(d.girl_name)}</span><span>${M('points')}</span></div>
          ${d.kootas.map(k => `<div class="mm-tr"><span class="mm-k"><b>${esc(k.name)}</b><small>${esc(k.area)}</small></span>
            <span class="mm-v" data-l="${esc(d.boy_name)}">${esc(k.boy)}</span><span class="mm-v" data-l="${esc(d.girl_name)}">${esc(k.girl)}</span>
            <span class="mm-p ${k.score >= k.max ? 'full' : k.score > 0 ? 'part' : 'zero'}"><b>${k.score}<small>/${k.max}</small></b><i><em style="width:${k.score / k.max * 100}%"></em></i></span></div>`).join('')}
          <div class="mm-tr mm-tot"><span>${M('total')}</span><span></span><span></span><span class="mm-p ${band(d.score)}"><b>${d.score}<small>/36</small></b></span></div></div></section>
        <section class="mm-sec"><h2 class="mm-h"><span class="ms">health_and_safety</span>${M('doshas')}</h2>
          <div class="mm-doshas">${d.doshas.map(x => `<div class="mm-dosha ${x.state}"><div><b>${esc(x.name)}</b><span class="mm-st">${esc(x.label)}</span></div><p>${esc(x.text)}</p>${remList(x.remedies)}</div>`).join('')}</div>
</section>
        <section class="card mm-sec"><div class="card-h"><span class="ms">nightlight</span>${M('details')}</div>
          <div class="mm-table mm-det"><div class="mm-tr mm-th"><span></span><span>${esc(d.boy_name)}</span><span>${esc(d.girl_name)}</span></div>
          ${rows.map(([l, k]) => `<div class="mm-tr"><span class="mm-k"><b>${M(l)}</b></span><span class="mm-v" data-l="${esc(d.boy_name)}">${esc(d.boy[k])}</span><span class="mm-v" data-l="${esc(d.girl_name)}">${esc(d.girl[k])}</span></div>`).join('')}</div></section>
        <section class="mm-sec"><h2 class="mm-h"><span class="ms">menu_book</span>${M('explain')}</h2>
          <div class="mm-ex">${d.kootas.map(k => `<div class="mm-exi"><div class="mm-exh"><b>${esc(k.name)}</b><span class="mm-p ${k.score >= k.max ? 'full' : k.score > 0 ? 'part' : 'zero'}"><b>${k.score}<small>/${k.max}</small></b></span></div>
            <p>${esc(k.about)}</p><p class="mm-res">${esc(k.result)}</p>${remList(k.remedies)}</div>`).join('')}</div></section>
        <section class="mm-final"><h2 class="mm-h"><span class="ms">summarize</span>${esc(d.final.title)}</h2>
          <div class="mm-fg"><div class="mm-fp"><b><span class="ms">thumb_up</span>${esc(d.final.pos_title)}</b><ul>${d.final.positive.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div>
            <div class="mm-fn"><b><span class="ms">warning</span>${esc(d.final.neg_title)}</b><ul>${d.final.negative.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div></div>
          ${d.final.solutions.length ? `<div class="mm-fs"><b><span class="ms">spa</span>${esc(d.final.sol_title)}</b>${remList(d.final.solutions, true)}</div>` : ''}</section>
        <p class="mm-note"><span class="ms">info</span>${M('note')}</p>
        <div class="mm-act noprint"><button onclick="window.print()"><span class="ms">print</span>${M('print')}</button><a class="btn ghost" href="#/milan"><span class="ms">add</span>${M('new')}</a></div></div>`);
      return;
    }
    loading();
    let profs = [], reports = [];
    try { [profs, reports] = await Promise.all([api('GET', '/profiles'), api('GET', '/milan')]); } catch (e) { h(errBox(e)); return; }
    const side = g => `<div class="mm-side ${g}" data-g="${g}"><div class="mm-sh"><span class="mm-av"><span class="ms">${g === 'boy' ? 'man' : 'woman'}</span></span><b>${M(g)}</b></div>
      ${profs.length ? `<label>${M('saved')}<select name="saved"><option value="">— ${M('manual')} —</option>${profs.map(p => `<option value="${p.id}">${esc(p.label)} · ${esc(p.birth_date)}</option>`).join('')}</select></label>` : ''}
      <div class="mm-man"><label>${esc(t('ui.name'))}<input name="name" maxlength="120" required></label>
        <div class="th-row"><label>${esc(t('ui.birth_date'))}<input type="date" name="date" required></label><label>${esc(t('ui.birth_time'))}<input type="time" name="time"></label></div>
        <label>${esc(t('ui.birth_place'))}<input name="place_q" placeholder="${esc(t('ui.search_place'))}" autocomplete="off" required><div class="suggest"></div></label></div></div>`;
    h(`<div class="mm">${ph('favorite', t('ml.title'), t('ml.sub'), '', t('ml.eyebrow'))}
      <form id="mlf" class="card mm-form"><div class="mm-pair">${side('boy')}<span class="mm-heart"><span class="ms">favorite</span></span>${side('girl')}</div>
        <div id="mlerr"></div><button class="mm-go"><span class="ms">join_inner</span>${M('calc')}</button></form>
      <section class="mm-sec"><h2 class="mm-h"><span class="ms">history</span>${M('recent')}</h2>
        ${reports.length ? `<div class="mm-list">${reports.map(r => `<div class="mm-item"><a href="#/milan/${r.id}">${ring(+r.score, 54)}<span class="mm-is">${+r.score}</span>
          <span class="mm-in"><b>${esc(r.boy_name)} <span class="ms">favorite</span> ${esc(r.girl_name)}</b><small>${esc(String(r.created_at).slice(0, 10))} · ${+r.score}/36 ${M('gunas')}</small></span></a>
          <button type="button" class="icon-btn" data-del="${r.id}" aria-label="${esc(t('ui.delete'))}"><span class="ms">delete</span></button></div>`).join('')}</div>` : `<p class="pp-empty">${M('none')}</p>`}</section></div>`);
    const f = document.getElementById('mlf'), place = {};
    f.querySelectorAll('.mm-side').forEach(el => {
      const g = el.dataset.g, man = el.querySelector('.mm-man'), sel = el.querySelector('[name=saved]');
      placePicker(el, p => place[g] = p); el.querySelector('[name=place_q]').addEventListener('input', () => delete place[g]);
      if (sel) sel.onchange = () => { const on = !!sel.value; man.hidden = on; man.querySelectorAll('input').forEach(i => i.disabled = on); };
    });
    f.onsubmit = async e => {
      e.preventDefault(); const err = document.getElementById('mlerr'); err.innerHTML = ''; const body = {};
      for (const el of f.querySelectorAll('.mm-side')) {
        const g = el.dataset.g, sel = el.querySelector('[name=saved]'), v = n => el.querySelector(`[name=${n}]`).value;
        if (sel && sel.value) { body[g + '_profile_id'] = +sel.value; body[g + '_name'] = profs.find(p => p.id === +sel.value)?.label; continue; }
        if (!place[g]) { err.innerHTML = errBox({ message: t('ml.need_place') }); el.querySelector('[name=place_q]').focus(); return; }
        body[g] = { name: v('name'), date: v('date'), time: v('time') || '12:00', lat: place[g].lat, lon: place[g].lon, tz: place[g].tzid || 'Asia/Kolkata' };
      }
      const btn = f.querySelector('.mm-go'); btn.disabled = true;
      try { const r = await api('POST', '/milan', body); location.hash = '#/milan/' + r.id; } catch (e2) { err.innerHTML = errBox(e2); btn.disabled = false; }
    };
    $app.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
      if (!confirm(t('ml.del_q'))) return;
      try { await api('DELETE', '/milan/' + b.dataset.del); b.closest('.mm-item').remove(); } catch (e) { toast(e.message); } });
  }

  // ---------- Chat (basic chat + Claude "AI Astrologer"): full page, saved conversations, own language picker ----------
  async function viewChat(mode = 'rules') {
    const AI = mode === 'claude', LS = (k, v) => { try { if (v === undefined) return localStorage.getItem(k); v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) {} return null; };
    loading(); let profs; try { profs = await api('GET', '/profiles'); } catch (e) { h(errBox(e)); return; }
    if (!profs.length) { h(`<p class="pp-empty">${esc(t('ui.no_kundali'))}</p><a class="btn" href="#/add">${esc(t('ui.new_kundali'))}</a>`); return; }
    let kid = +(LS('chat_kid') || 0); if (!profs.find(p => p.id === kid)) kid = profs[0].id;
    let clang = LS('chat_lang') || 'auto', thread = +(LS('cthread_' + mode) || 0) || null, st = { msgs: [], ctx: {}, q: [] }, busy = false;
    const LANGS = [['auto', t('chat.lang_auto')], ['en', 'English'], ['hi', 'हिन्दी'], ['gu', 'ગુજરાતી']];
    h(`<section class="cp${AI ? ' ai' : ''}">
      <aside class="cp-side" id="cps">
        <div class="cp-sh"><button type="button" class="btn cp-new" id="cpn" style="flex:1"><span class="ms">edit_square</span>${esc(t('ui.new_chat'))}</button><button type="button" class="icon-btn cp-x" id="cpx" aria-label="${esc(t('ui.close'))}"><span class="ms">close</span></button></div>
        <div class="cp-list" id="cpl"></div>
        <div class="cp-ctrl" style="flex-direction:row;gap:.4rem">
          <label class="cp-pick" style="flex:1;min-width:0"><span class="ms">person</span><select id="chk" aria-label="${esc(t('ui.select_kundali'))}">${profs.map(p => `<option value="${p.id}"${p.id === kid ? ' selected' : ''}>${esc(p.label)}</option>`).join('')}</select></label>
          <label class="cp-pick" style="flex:1;min-width:0"><span class="ms">translate</span><select id="chlg" aria-label="${esc(t('chat.lang_label'))}">${LANGS.map(([v, n]) => `<option value="${v}"${v === clang ? ' selected' : ''}>${esc(n)}</option>`).join('')}</select></label>
        </div></aside>
      <div class="cp-main">
        <div class="cp-top">
          <button type="button" class="icon-btn cp-menu" id="cpm" aria-label="Menu"><span class="ms">menu</span></button>
          <div class="chat-av"><span class="ms">${AI ? 'psychology' : 'forum'}</span></div>
          <div class="chat-who"><b>${esc(AI ? t('ui.nav_claude') : t('ui.chat'))}</b><small><span class="dot"></span>${esc(t('ui.online', 'Online'))}</small></div>
          ${AI ? `<a class="chat-sw" href="#/chat"><span class="ms">forum</span><span>${esc(t('ui.chat'))}</span></a>` : `<a class="chat-sw" href="#/ai-chat"><span class="ms">psychology</span><span>${esc(t('ui.nav_claude'))}</span></a>`}
        </div>
        <div class="chat-log" id="chl" aria-live="polite"></div>
        <form class="chat-in" id="chf"><input name="m" autocomplete="off" maxlength="800" enterkeyhint="send" placeholder="${esc(t('chat.placeholder'))}" aria-label="${esc(t('chat.placeholder'))}"><button aria-label="${esc(t('ui.send'))}"><span class="ms">send</span></button></form>

      </div><div class="cp-shade" id="cpd"></div></section>`);
    const $l = document.getElementById('chl'), f = document.getElementById('chf'), side = document.getElementById('cps');
    const tm = ts => new Date(ts || Date.now()).toLocaleTimeString(lang === 'en' ? 'en-IN' : lang + '-IN', { hour: 'numeric', minute: '2-digit' });
    const bubble = m => `<div class="msg ${m.me ? 'me' : 'bot'}"><div>${m.lines.map(x => `<p>${esc(x)}</p>`).join('')}<time class="nofmt">${esc(tm(m.ts))}</time></div></div>`;
    const draw = () => {
      $l.innerHTML = st.msgs.map(bubble).join('') + (st.q && st.q.length ? `<div class="chat-q">${st.q.map(x => `<button type="button" class="qr">${esc(x)}</button>`).join('')}</div>` : '');
      $l.querySelectorAll('.chat-q button').forEach(b => b.onclick = () => ask(b.textContent)); $l.scrollTop = $l.scrollHeight; };
    const greet = () => { thread = null; LS('cthread_' + mode, null); st = { msgs: [{ lines: AI ? [t('chat.claude_greet')] : [t('chat.greet'), t('chat.help')], ts: Date.now() }], ctx: {},
      q: ['career', 'marriage', 'finance', 'child', 'dasha'].map(x => t('chat.q.' + x)) }; draw(); };
    // reveal a new answer line by line so it feels live
    const reveal = async lines => {
      const m = { lines: [], ts: Date.now() }; st.msgs.push(m);
      for (const ln of lines) { m.lines.push(''); const words = ln.split(/(\s+)/), step = Math.max(1, Math.ceil(words.length / 40));
        for (let i = 0; i < words.length; i += step) { m.lines[m.lines.length - 1] += words.slice(i, i + step).join(''); const last = $l.querySelector('.msg.bot:last-of-type > div');
          if (last) last.innerHTML = m.lines.map(x => `<p>${esc(x)}</p>`).join('') + `<time class="nofmt">${esc(tm(m.ts))}</time>`; $l.scrollTop = $l.scrollHeight; await new Promise(r => setTimeout(r, 18)); } }
    };
    const ask = async text => {
      text = text.trim(); if (!text || busy) return; busy = true; f.m.value = '';
      st.msgs.push({ me: true, lines: [text], ts: Date.now() }); st.q = []; draw();
      $l.insertAdjacentHTML('beforeend', '<div class="msg bot typing"><div><span></span><span></span><span></span></div></div>'); $l.scrollTop = $l.scrollHeight;
      try {
        const d = await apiRaw('POST', `/profiles/${kid}/${AI ? 'claude-chat' : 'chat'}?lang=${lang}`, { message: text, chat_lang: clang, thread, context: st.ctx });
        if (d.thread) { thread = d.thread; LS('cthread_' + mode, thread); }
        st.ctx = d.context || {}; $l.querySelector('.typing')?.remove();
        $l.insertAdjacentHTML('beforeend', '<div class="msg bot"><div></div></div>'); await reveal(d.reply);
        st.q = d.quick || []; if (d.engine) console.info('[chat] answered by', d.engine, d.why || ''); listChats();
      } catch (e) { st.msgs.push({ lines: [e.message], ts: Date.now() }); }
      busy = false; draw(); f.m.focus({ preventScroll: true });
    };
    const openThread = async id => {
      try { const d = await apiRaw('GET', '/chats/' + id); thread = d.id; LS('cthread_' + mode, thread);
        if (d.profile_id !== kid && profs.find(p => p.id === d.profile_id)) { kid = d.profile_id; document.getElementById('chk').value = kid; LS('chat_kid', kid); }
        st = { msgs: d.msgs, ctx: d.ctx || {}, q: [] }; draw(); } catch (e) { greet(); }
      side.classList.remove('open'); markActive();
    };
    const markActive = () => document.querySelectorAll('.cp-item').forEach(x => x.classList.toggle('on', +x.dataset.id === thread));
    async function listChats() {
      const box = document.getElementById('cpl'); if (!box) return;
      let rows = []; try { rows = await apiRaw('GET', '/chats?mode=' + (AI ? 'claude' : 'rules')); } catch (e) {}
      box.innerHTML = rows.length ? rows.map(r => `<div class="cp-item" data-id="${r.id}"><button type="button" class="cp-open"><b>${esc(r.title)}</b><small>${esc(r.label)} · ${esc(r.updated_at.slice(0, 10))}</small></button>
        <button type="button" class="icon-btn cp-del" aria-label="${esc(t('ui.delete'))}"><span class="ms">delete</span></button></div>`).join('') : `<p class="muted cp-empty">${esc(t('chat.no_saved'))}</p>`;
      box.querySelectorAll('.cp-item').forEach(it => { const id = +it.dataset.id;
        it.querySelector('.cp-open').onclick = () => openThread(id);
        it.querySelector('.cp-del').onclick = async () => { if (!confirm(t('chat.delete_q'))) return; try { await apiRaw('DELETE', '/chats/' + id); } catch (e) {} if (id === thread) greet(); listChats(); }; });
      markActive();
    }
    f.onsubmit = e => { e.preventDefault(); ask(f.m.value); };
    document.getElementById('chk').onchange = e => { kid = +e.target.value; LS('chat_kid', kid); greet(); markActive(); };
    document.getElementById('chlg').onchange = e => { clang = e.target.value; LS('chat_lang', clang); };
    const newChatFn = () => { greet(); markActive(); side.classList.remove('open'); f.m.focus(); };
    document.getElementById('cpn').onclick = newChatFn;
    const _chn = document.getElementById('chn'); if (_chn) _chn.onclick = newChatFn;
    document.getElementById('cpm').onclick = () => side.classList.add('open');
    document.getElementById('cpx').onclick = document.getElementById('cpd').onclick = () => side.classList.remove('open');
    LS('chat_kid', kid);
    if (thread) await openThread(thread); else greet();
    listChats(); if (matchMedia('(min-width: 861px)').matches) f.m.focus({ preventScroll: true });
  }

  // ---------- Chart list + add ----------
  async function viewCharts() {
    loading();
    try {
      const list = await api('GET', '/profiles');
      h(`<h1>${esc(t('ui.my_charts'))}</h1>
        <div class="list">${list.length ? list.map(p => `<div class="item"><div><strong>${esc(p.label)}</strong><br>
          <span class="muted">${esc(p.birth_date)} ${esc(p.birth_time.slice(0, 5))} · ${esc(p.place_name.split(',')[0])}</span></div>
          <div class="row"><button class="icon-btn" data-del="${p.id}" aria-label="${esc(t('ui.delete'))}"><span class="ms">delete</span></button><a class="btn" href="#/chart/${p.id}">${esc(t('ui.open'))}</a></div></div>`).join('') : `<p>${esc(t('ui.empty_charts'))}</p>`}</div>
        <h2>${esc(t('ui.add_chart'))}</h2>${chartForm()}`);
      bindChartForm();
      $app.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
        if (!(await confirmDialog(t('ui.confirm_delete')))) return;
        try { await api('DELETE', '/profiles/' + b.dataset.del); toast(t('ui.deleted'), 'delete'); viewCharts(); } catch (e) { toast(e.message, 'error'); }
      });
    } catch (e) { h(errBox(e)); }
  }

  function chartForm() {
    return `<form class="stack" id="cf">
      <label>${esc(t('ui.label'))}<input name="label" required maxlength="120"></label>
      <label>${esc(t('ui.gender'))}<select name="gender"><option value="">—</option>
        <option value="male">${esc(t('ui.male'))}</option><option value="female">${esc(t('ui.female'))}</option><option value="other">${esc(t('ui.other'))}</option></select></label>
      <div class="row">
        <label>${esc(t('ui.birth_date'))}<input type="date" name="birth_date" required min="1800-01-01" max="2399-12-31"></label>
        <label>${esc(t('ui.birth_time'))}<input type="time" name="birth_time" step="1" required></label>
      </div>
      <label>${esc(t('ui.time_accuracy'))}<select name="time_accuracy">
        <option value="exact">${esc(t('ui.exact'))}</option><option value="approximate">${esc(t('ui.approximate'))}</option></select></label>
      <label>${esc(t('ui.birth_place'))}<input name="place_q" placeholder="${esc(t('ui.search_place'))}" autocomplete="off" required><div class="suggest"></div></label>
      <label>${esc(t('ui.timezone'))}<input name="tzid" required placeholder="Asia/Kolkata" list="tzlist"><datalist id="tzlist"></datalist></label>
      <label>${esc(t('ui.manual_offset'))}<input name="manual_offset_minutes" type="number" min="-720" max="840" step="1"></label>
      <div id="fold"></div><div id="resolved"></div><div id="cerr"></div>
      <div class="row"><button type="submit" name="check">${esc(t('ui.check_details'))}</button></div>
    </form>`;
  }

  function bindChartForm(edit = null) {
    const f = document.getElementById('cf'); let place = null, confirmed = null;
    if (edit) {
      for (const k of ['label', 'gender', 'birth_date', 'time_accuracy', 'tzid']) f[k].value = edit[k] ?? '';
      f.birth_time.value = edit.birth_time; f.place_q.value = edit.place_name; place = { lat: edit.lat, lon: edit.lon };
      if (edit.offset_source === 'manual') f.manual_offset_minutes.value = edit.offset_minutes;
    }
    api('GET', '/meta/timezones').then(z => document.getElementById('tzlist').innerHTML = z.map(x => `<option value="${esc(x)}">`).join('')).catch(() => {});
    placePicker(f, p => { place = p; if (p.tzid) f.tzid.value = p.tzid; reset(); });
    const reset = () => { confirmed = null; document.getElementById('resolved').innerHTML = ''; f.check.textContent = t('ui.check_details'); };
    f.querySelectorAll('input,select').forEach(i => i.addEventListener('change', () => { if (i.name !== 'fold') reset(); }));
    const body = () => ({
      label: f.label.value, gender: f.gender.value || null, birth_date: f.birth_date.value, birth_time: f.birth_time.value,
      time_accuracy: f.time_accuracy.value, place_name: f.place_q.value, lat: place?.lat, lon: place?.lon, tzid: f.tzid.value,
      manual_offset_minutes: f.manual_offset_minutes.value === '' ? null : parseInt(f.manual_offset_minutes.value, 10),
      dst_fold: f.querySelector('[name=fold]:checked')?.value || null,
    });
    f.onsubmit = async e => {
      e.preventDefault(); const err = document.getElementById('cerr'); err.innerHTML = '';
      if (!place) { err.innerHTML = errBox({ message: t('ui.search_place') }); return; }
      try {
        if (!confirmed) {
          const r = await api('POST', '/birth/resolve', body()); confirmed = r;
          document.getElementById('resolved').innerHTML = `<section class="calc"><strong>${esc(t('ui.resolved_as'))}</strong>
            <table><tr><th>${esc(t('ui.utc'))}</th><td>${esc(r.utc)}</td></tr>
            <tr><th>${esc(t('ui.offset'))}</th><td>${r.offset_minutes >= 0 ? '+' : '−'}${String(Math.floor(Math.abs(r.offset_minutes) / 60)).padStart(2, '0')}:${String(Math.abs(r.offset_minutes) % 60).padStart(2, '0')} (${esc(r.offset_source)})</td></tr>
            <tr><th>${esc(t('ui.location'))}</th><td>${place.lat}, ${place.lon}</td></tr></table>
            ${r.warnings.map(w => `<div class="warn">${esc(w.message)}</div>`).join('')}</section>`;
          f.check.textContent = t('ui.confirm_save');
        } else {
          const p = await api(edit ? 'PUT' : 'POST', edit ? '/profiles/' + edit.id : '/profiles', { ...body(), confirmed: true });
          if (edit) toast(t('ui.saved'));
          location.hash = '#/chart/' + p.id;
        }
      } catch (e2) {
        if (e2.code === 'ambiguous_local_time') {
          document.getElementById('fold').innerHTML = `<fieldset><legend>${esc(t('ui.dst_choose'))}</legend>
            <label><input type="radio" name="fold" value="earlier" required> ${esc(t('ui.earlier'))}</label>
            <label><input type="radio" name="fold" value="later"> ${esc(t('ui.later'))}</label></fieldset>`;
        }
        err.innerHTML = errBox(e2);
      }
    };
  }

  // ---------- Chart detail ----------
  const HOUSE_XY = [[200, 95], [100, 44], [38, 118], [100, 192], [38, 282], [100, 352], [200, 292], [300, 352], [362, 282], [300, 192], [362, 118], [300, 44]];
  const SIGN_XY = [[200, 162], [100, 14], [30, 64], [100, 252], [30, 340], [100, 392], [200, 362], [300, 392], [370, 340], [300, 252], [370, 64], [300, 14]];
  const SIGN_SHORT = ['Ari', 'Tau', 'Gem', 'Can', 'Leo', 'Vir', 'Lib', 'Sco', 'Sag', 'Cap', 'Aqu', 'Pis'];

  const marks = (p, dig) => (p.retrograde && !['Rahu', 'Ketu'].includes(p.name) ? '*' : '') + (dig === 'exalted' ? '↑' : dig === 'debilitated' ? '↓' : '') + (p.combust ? '°' : '');
  const legend = () => `<p class="muted">${esc(t('ui.legend'))}: * ${esc(t('ui.retro'))} · ↑ ${esc(t('ui.exalted_s'))} · ↓ ${esc(t('ui.debilitated_s'))} · ° ${esc(t('ui.combust'))}</p>`;
  const vname = n => `${n} — ${t('astro.vargas.' + n, n)}`;
  const status = (p) => [p.retrograde && !['Rahu', 'Ketu'].includes(p.name) ? t('ui.retro') : '', p.dignity ? tn('dignity', p.dignity) : '', p.combust ? t('ui.combust') : ''].filter(Boolean).map(x => `<span class="tag c">${esc(x)}</span>`).join(' ');
  const HOUSE_POLY = ['200,0 300,100 200,200 100,100', '0,0 200,0 100,100', '0,0 100,100 0,200', '100,100 200,200 100,300 0,200', '0,200 100,300 0,400', '0,400 100,300 200,400',
    '200,200 300,300 200,400 100,300', '200,400 300,300 400,400', '400,400 300,300 400,200', '300,100 400,200 300,300 200,200', '400,200 300,100 400,0', '400,0 300,100 200,0'];
  const SIGN_NAMES = ['Aries','Taurus','Gemini','Cancer','Leo','Virgo','Libra','Scorpio','Sagittarius','Capricorn','Aquarius','Pisces'];
  function northChart(k, varga) {
    const v = k.vargas[varga], asc = v.lagna, houses = Array.from({ length: 12 }, () => []);
    houses[0].push(`<tspan class="as">${esc(t('astro.planets_short.Lagna', 'As'))}</tspan>`);
    k.planets.forEach(p => {
      const s = v.planets[p.name], house = (s - asc + 12) % 12, dig = v.dignity?.[p.name];
      houses[house].push(`<tspan class="${dig === 'exalted' ? 'ex' : dig === 'debilitated' ? 'db' : ''}">${esc(t('astro.planets_short.' + p.name, p.name.slice(0, 2)))}${marks(p, dig)}</tspan>`);
    });
    let body = '';
    houses.forEach((items, i) => {
      const [x, y] = HOUSE_XY[i], [sx, sy] = SIGN_XY[i], rows = [], sign = (asc + i) % 12;
      for (let j = 0; j < items.length; j += 3) rows.push(items.slice(j, j + 3).join(' '));
      const y0 = y - (rows.length - 1) * 8;
      body += `<polygon class="${[0, 3, 6, 9].includes(i) ? 'hk' : 'ho'}" points="${HOUSE_POLY[i]}"/>`;
      body += `<text class="sn" x="${sx}" y="${sy}" text-anchor="middle"><tspan class="snum">${sign + 1}</tspan> ${esc(lang === 'en' ? SIGN_SHORT[sign] : tn('signs', SIGN_NAMES[sign]))}</text>`;
      rows.forEach((r, j) => body += `<text class="pl" x="${x}" y="${y0 + j * 16 + 4}" text-anchor="middle">${r}</text>`);
    });
    return `<svg class="kundali" viewBox="-2 -2 404 404" role="img" aria-label="${esc(varga)} chart">${body}<rect class="frame" x="0" y="0" width="400" height="400" rx="6"/></svg>`;
  }
  // ---------- visual helpers ----------
  const scoreLabel = v => t('ui.' + (v >= 75 ? 's_excellent' : v >= 60 ? 's_good' : v >= 45 ? 's_average' : 's_weak'));
  const dial = (v, label = '') => { const a = Math.PI * (1 - v / 100), x = 110 + 88 * Math.cos(a), y = 118 - 88 * Math.sin(a);
    return `<div class="gauge"><svg class="dial" data-v="${v}" viewBox="0 0 220 135" role="img" aria-label="${v}/100">
      <defs><linearGradient id="gg" x1="0" x2="1"><stop offset="0" stop-color="#e5484d"/><stop offset=".45" stop-color="#f5a524"/><stop offset="1" stop-color="#1b9e5a"/></linearGradient></defs>
      <path d="M22 118 A88 88 0 0 1 198 118" class="trk"/><path d="M22 118 A88 88 0 0 1 198 118" class="val" pathLength="100" style="--v:${100 - v}"/>
      <circle cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="8" class="knob"/>
      <text x="22" y="134" class="tick">0</text><text x="198" y="134" class="tick" text-anchor="end">100</text>
      <text x="110" y="98" class="num" text-anchor="middle" data-count="${v}">0</text><text x="110" y="122" class="lab" text-anchor="middle">${esc(scoreLabel(v))}</text></svg>${label ? `<p class="gl">${label}</p>` : ''}</div>`; };
  const ring = (v) => { const C = 2 * Math.PI * 42; return `<svg class="ring" data-v="${v}" viewBox="0 0 100 100" style="--c:${C};--off:${C * (1 - v / 100)}"><circle cx="50" cy="50" r="42" class="trk"/><circle cx="50" cy="50" r="42" class="val"/></svg>`; };
  const bar = (label, v, right = '', cls = '') => `<div class="b"><div class="bar-l"><span>${label}</span><span>${right || v}</span></div><div class="bar ${cls} ${v >= 60 ? 'good' : v <= 40 ? 'low' : ''}" data-v="${v}"><i style="--w:${v}%"></i></div></div>`;
  const verdictChip = v => `<span class="chip ${v === 'strong' ? 'ok' : v === 'weak' ? 'warn' : ''}">${esc(t('ui.v_' + v))}</span>`;
  const TOK = { house: 'ui.house', sign: 'ui.sign', dignity: 'ui.dignity', lagna: 'ui.lagna', combust: 'ui.combust', transit: 'ui.transits', house_from_moon: 'ui.from_moon', house_from_lagna: 'ui.from_lagna',
    mahadasha: 'ui.mahadasha', antardasha: 'ui.antardasha', dasha: 'ui.dasha', lord: 'ui.lord', score: 'ui.overall_rating', varsha: 'ui.varshphal', muntha_house: 'ui.muntha', period: 'ui.from', planets: '', current: 'ui.current' };
  const PL_RE = /\b(Sun|Moon|Mars|Mercury|Jupiter|Venus|Saturn|Rahu|Ketu)\b/g;
  const fv = v => { if (Array.isArray(v)) return v.map(fv).join(', ') || '—'; if (v === true) return '✓'; if (v === false || v === null || v === undefined) return '—';
    if (typeof v === 'string' && PL_RE.test(v)) { PL_RE.lastIndex = 0; if (!SIGN_NAMES.includes(v) && !t('astro.planets.' + v, '')) return v.replace(PL_RE, m => tn('planets', m)); }
    if (['rising', 'peak', 'setting'].includes(v)) return t('interp.sade_phase.' + v);
    if (SIGN_NAMES.includes(v)) return tn('signs', v); if (t('astro.planets.' + v, '') ) return tn('planets', v); if (['exalted', 'own', 'debilitated'].includes(v)) return tn('dignity', v); return String(v); };
  const fl = path => t('ui.f_' + path, '') || path.split('.').map(p => { if (t('astro.planets.' + p, '')) return tn('planets', p); const m = p.match(/^house_(\d+)(.*)$/); if (m) return t('ui.house') + ' ' + m[1] + (m[2] ? ' ' + fl(m[2].slice(1).replace(/_/g, '.')) : '');
    return TOK[p] !== undefined ? (TOK[p] ? t(TOK[p]) : '') : (lang === 'en' ? p.replace(/_/g, ' ') : p.replace(/_/g, ' ')); }).filter(Boolean).join(' · ');
  const factList = fs => `<ul class="facts">${fs.map(f => `<li><span>${esc(fl(f.path))}</span><b>${esc(fv(f.value))}</b></li>`).join('')}</ul>`;
  const gemSvg = c => `<svg class="gem" viewBox="0 0 64 64" aria-hidden="true"><defs><linearGradient id="g${c.slice(1)}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".85"/><stop offset=".45" stop-color="${c}"/><stop offset="1" stop-color="${c}" stop-opacity=".75"/></linearGradient></defs>
    <polygon points="16,6 48,6 60,22 32,60 4,22" fill="url(#g${c.slice(1)})" stroke="rgba(0,0,0,.25)"/><polyline points="4,22 60,22" fill="none" stroke="rgba(255,255,255,.7)"/>
    <polyline points="16,6 24,22 32,6 40,22 48,6" fill="none" stroke="rgba(255,255,255,.6)"/><polyline points="24,22 32,60 40,22" fill="none" stroke="rgba(0,0,0,.18)"/></svg>`;

  async function viewChart(id) {
    loading();
    let prof, k;
    try { [prof, k] = await Promise.all([api('GET', `/profiles/${id}`), api('GET', `/profiles/${id}/kundali`)]); }
    catch (e) { h(errBox(e)); return; }
    const s = k.meta.settings, b = k.birth;
    h(`<section class="kv-head"><span class="avatar">${esc(prof.label.trim().charAt(0).toUpperCase())}</span><div class="kv-id"><h1>${esc(prof.label)}</h1>
      <div class="chips"><span class="chip"><span class="ms">event</span>${esc(b.local.slice(0, 16))}</span><span class="chip"><span class="ms">location_on</span>${esc(prof.place_name.split(',')[0])}</span>
      <span class="chip"><span class="ms">public</span>${esc(b.tzid)} · UTC ${esc(b.utc.slice(11, 16))}</span><span class="chip ok"><span class="ms">north_east</span>${esc(t('ui.lagna'))} ${esc(tn('signs', k.lagna.sign_name))}</span></div></div>
      <div class="kv-act"><a class="btn" href="#/predict" id="goPredict"><span class="ms">auto_awesome</span>${esc(t('ui.personal_predictions'))}</a><a class="btn tonal" href="#/print/${id}"><span class="ms">download</span>PDF</a><a class="btn ghost" href="#/edit/${id}"><span class="ms">edit</span>${esc(t('ui.edit_kundali'))}</a></div></section>
      ${b.warnings.map(w => `<div class="warn">${esc(w.message)}</div>`).join('')}
      ${prof.time_accuracy === 'approximate' ? `<div class="warn">${esc(t('ui.approx_warning'))}</div>` : ''}
      <div class="kvl"><div class="tabs" role="tablist" aria-orientation="vertical">${Object.keys(GROUPS).map((x, i) =>
        `<button role="tab" data-tab="${x}" class="${i ? '' : 'on'}"><span class="ms">${GROUPS[x].icon}</span>${esc(t('ui.' + x))}</button>`).join('')}</div>
      <div class="kv-body">      <div id="sub" class="subtabs"></div><div id="tab"></div></div></div>
      <details><summary>${esc(t('ui.settings'))}</summary><ul>
        <li>${esc(t('ui.ayanamsa'))}: ${esc(s.ayanamsa)} (${esc(k.meta.ayanamsa_dms)})</li><li>Rahu/Ketu: ${esc(s.node)} node</li>
        <li>Zodiac: ${esc(s.zodiac)}, ${esc(s.positions)}; houses: ${esc(s.house_system)}</li>
        <li>Dasha year: ${esc(s.dasha_year_days)} days</li><li>Swiss Ephemeris ${esc(k.meta.engine.swisseph)}, engine ${esc(k.meta.engine.version)}</li></ul></details>`);
    const fns = { overview: () => tabOverview(id, k), daily_kundali: () => tabDaily(id), charts: () => tabCharts(k, prof), planets: () => tabPlanets(k), dasha: () => tabDasha(k),
      whole_life: () => tabPred(id, 'life'), mdphal: () => tabPred(id, 'mdphal'), current_mahadasha: () => tabPred(id, 'dasha'), monthly: () => tabMonthly(id), yogas: () => tabYogas(id),
      varshphal: () => tabVarsh(id, k), doshas: () => tabDoshas(id), priority_remedies: () => tabPriority(id), remedy_plan: () => tabRemedyPlan(id), pooja: () => tabPooja(id), planet_results: () => tabPlanetResults(id), periodic: () => tabRemedies(id),
      gemstones: () => tabGems(id), transits: () => tabTransits(id) };
    const openGroup = g => { document.getElementById('tab').classList.toggle('y4', ['predictions', 'overview'].includes(g)); const subs = GROUPS[g].subs, sub = document.getElementById('sub');
      sub.innerHTML = subs.length > 1 ? subs.map((x, i) => `<button data-s="${x}" class="${i ? '' : 'on'}">${esc(t('ui.' + x))}</button>`).join('') : '';
      sub.querySelectorAll('button').forEach(b => b.onclick = () => { sub.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b)); fns[b.dataset.s](); });
      fns[subs[0]](); };
    document.querySelectorAll('.tabs button').forEach(bt => bt.onclick = () => {
      document.querySelectorAll('.tabs button').forEach(x => x.classList.toggle('on', x === bt)); tabIndicator(bt.parentElement); openGroup(bt.dataset.tab);
    });
    tabIndicator($app.querySelector('.tabs')); addEventListener('resize', () => tabIndicator($app.querySelector('.tabs')));
    let ot = null; try { ot = sessionStorage.getItem('openTab'); sessionStorage.removeItem('openTab'); } catch (e) {}
    const ob = ot && document.querySelector(`.tabs [data-tab="${ot}"]`); if (ob) ob.click(); else openGroup('overview');
  }
  document.addEventListener('click', e => { const a = e.target.closest('[data-open-tab]'); if (a) try { sessionStorage.setItem('openTab', a.dataset.openTab); } catch (er) {} });
  const GROUPS = { overview: { icon: 'space_dashboard', subs: ['overview'] }, daily_kundali: { icon: 'today', subs: ['daily_kundali'] }, kundali: { icon: 'grid_view', subs: ['charts', 'planets', 'dasha'] },
    predictions: { icon: 'auto_awesome', subs: ['whole_life', 'mdphal', 'current_mahadasha', 'monthly', 'planet_results'] }, varshphal: { icon: 'event_repeat', subs: ['varshphal'] },
    doshas: { icon: 'report', subs: ['doshas'] }, yogas: { icon: 'auto_fix_high', subs: ['yogas'] }, remedies: { icon: 'spa', subs: ['remedy_plan'] }, pooja: { icon: 'temple_hindu', subs: ['pooja'] },
    gemstones: { icon: 'diamond', subs: ['gemstones'] }, transits: { icon: 'public', subs: ['transits'] } };
  function tabCharts(k, prof) {
    const el = document.getElementById('tab'), names = Object.keys(k.vargas);
    const sens = k.sensitivity.lagna;
    const draw = (v) => {
      el.innerHTML = `<section class="calc">${calcTag()}
        <div class="row" style="margin:.8rem 0"><label>${esc(t('ui.charts'))}<select id="vs">${names.map(n => `<option value="${n}" ${n === v ? 'selected' : ''}>${esc(vname(n))}</option>`).join('')}</select></label></div>
        <div class="chartwrap"><div><strong>${esc(vname(v))}</strong>${northChart(k, v)}${legend()}</div>
        <div><h2 style="margin-top:0">${esc(t('ui.sensitivity_title'))}</h2><p class="muted">${esc(t('ui.sensitivity_note'))}</p>
          <table>${Object.entries(sens).map(([d, x]) => `<tr${d === v ? ' class="now"' : ''}><th>${d}</th>
            <td>${x.changes_if_earlier_by_min} ${esc(t('ui.min_earlier'))}</td><td>${x.changes_if_later_by_min} ${esc(t('ui.min_later'))}</td></tr>`).join('')}</table>
          <p class="muted">${esc(t('ui.lagna'))}: ${esc(tn('signs', k.lagna.sign_name))} ${esc(k.lagna.dms)} · ${esc(tn('nakshatras', k.lagna.nakshatra))} ${k.lagna.pada}</p>
        </div></div></section>`;
      document.getElementById('vs').onchange = e => draw(e.target.value);
    };
    draw('D1');
  }

  function tabPlanets(k) {
    document.getElementById('tab').innerHTML = `<section class="calc">${calcTag()}<div class="scroll"><table>
      <tr><th>${esc(t('ui.planet'))}</th><th>${esc(t('ui.sign'))}</th><th>${esc(t('ui.degree'))}</th><th>${esc(t('ui.nakshatra'))}</th><th>${esc(t('ui.pada'))}</th><th>${esc(t('ui.house'))}</th><th>${esc(t('ui.status'))}</th></tr>
      <tr><td>${esc(t('ui.lagna'))}</td><td>${esc(tn('signs', k.lagna.sign_name))}</td><td>${esc(k.lagna.dms)}</td><td>${esc(tn('nakshatras', k.lagna.nakshatra))}</td><td>${k.lagna.pada}</td><td>1</td><td></td></tr>
      ${k.planets.map(p => `<tr><td>${esc(tn('planets', p.name))}</td><td>${esc(tn('signs', p.sign_name))}</td><td>${esc(p.dms)}</td>
        <td>${esc(tn('nakshatras', p.nakshatra))}</td><td>${p.pada}</td><td>${p.house}</td><td>${status(p)}</td></tr>`).join('')}</table></div></section>`;
  }

  function tabDasha(k) {
    const jd = Date.now() / 864e5 + 2440587.5, cur = x => jd >= x.start_jd && jd < x.end_jd;
    document.getElementById('tab').classList.add('y4');
    document.getElementById('tab').innerHTML = `${calcTag()}<p class="muted">${esc(t('ui.dasha'))} · ${esc(tn('nakshatras', k.dasha.moon_nakshatra))} → ${esc(tn('planets', k.dasha.start_lord))}</p>
      ${k.dasha.mahadasha.map(md => collapsible(`${tn('planets', md.lord)} ${t('ui.mahadasha')}${cur(md) ? ' · ' + t('ui.now') : ''}`,
        `<table>${md.antardasha.map(ad => `<tr class="${cur(ad) ? 'now' : ''}"><td>${esc(tn('planets', ad.lord))}</td><td>${ad.start}</td><td>${ad.end}</td></tr>`).join('')}</table>`,
        cur(md), `${md.start} → ${md.end}`)).join('')}`;
  }

  const TZ = Intl.DateTimeFormat().resolvedOptions().timeZone;
  const dateBar = (d, cb) => { const w = document.createElement('div'); w.className = 'row'; w.style.margin = '1rem 0';
    w.innerHTML = `<label>${esc(t('ui.as_on'))}<input type="date" value="${d}" min="1800-01-02" max="2399-12-30"></label>`; w.querySelector('input').onchange = e => cb(e.target.value); return w; };
  const today = () => new Date().toLocaleDateString('en-CA');
  async function tabTransits(id, date = today()) {
    const el = document.getElementById('tab'); el.innerHTML = skel();
    try {
      const tr = await api('GET', `/profiles/${id}/transits?date=${date}&tzid=${encodeURIComponent(TZ)}`);
      const gk = await api('GET', `/profiles/${id}/kundali`);
      const gch = { planets: tr.planets, vargas: { D1: { lagna: gk.lagna.sign, planets: Object.fromEntries(tr.planets.map(p => [p.name, p.sign])), dignity: {} } } };
      const chips = `<div class="chips">${Object.entries(tr.derived).filter(([n]) => n !== 'tara').map(([n, d]) => `<span class="chip ${d.active ? 'warn' : 'ok'}"><span class="ms">${d.active ? 'error' : 'check_circle'}</span>${esc(t('ui.transit_' + n))}</span>`).join('')}</div>`;
      const tbl = `<section class="card">${calcTag()}<div class="scroll"><table>
        <tr><th>${esc(t('ui.planet'))}</th><th>${esc(t('ui.sign'))}</th><th>${esc(t('ui.degree'))}</th><th>${esc(t('ui.nakshatra'))}</th><th>${esc(t('ui.from_lagna'))}</th><th>${esc(t('ui.from_moon'))}</th></tr>
        ${tr.planets.map(p => `<tr><td>${esc(tn('planets', p.name))}${p.retrograde && !['Rahu', 'Ketu'].includes(p.name) ? ' *' : ''}</td><td>${esc(tn('signs', p.sign_name))}</td><td>${esc(p.dms)}</td>
          <td>${esc(tn('nakshatras', p.nakshatra))}</td><td>${p.house_from_lagna}</td><td>${p.house_from_moon}</td></tr>`).join('')}</table></div></section>`;
      localTabs(el, [['c', t('ui.charts'), 'grid_view', () => `<div class="chartwrap"><section class="card">${calcTag()}${northChart(gch, 'D1')}${legend()}</section><section class="card">${chips}</section></div>`],
                     ['t', t('ui.planets'), 'table_rows', () => tbl]]);
      const _x = `
        <div class="chips">${Object.entries(tr.derived).filter(([n]) => n !== 'tara').map(([n, d]) => `<span class="chip ${d.active ? 'warn' : ''}"><span class="ms">${d.active ? 'error' : 'check_circle'}</span>${esc(t('ui.transit_' + n))}</span>`).join('')}</div></section>`;
      el.prepend(dateBar(date, d => tabTransits(id, d))); void _x;
    } catch (e) { el.innerHTML = errBox(e); }
  }

  const IC = { remedy: 'spa', caution: 'warning', mahadasha: 'hourglass_top', antardasha: 'hourglass_bottom', personality: 'person', mind: 'psychology', present: 'schedule',
    houses: 'home', timeline: 'timeline', auspicious: 'thumb_up', inauspicious: 'thumb_down', mixed: 'balance', gochar: 'public', tara: 'star', summary: 'summarize' };
  const interpItems = (d) => d.items.length ? `<div class="icards">${d.items.map(it => `<article class="icard"><span class="ms ic">${IC[it.section] || 'auto_awesome'}</span>
      <div>${it.title ? `<b class="it-t">${esc(it.title)}</b>` : ''}${it.section === 'remedy' ? steps(it.text) : `<p>${esc(it.text)}</p>`}${derivList(it)}</div></article>`).join('')}</div>` : `<p class="muted">${esc(t('ui.none'))}</p>`;

  const grouped = (d) => { const g = {}; d.items.forEach(i => (g[i.section] ??= []).push(i));
    return `` + Object.entries(g).map(([sec, its]) => `<h2>${esc(t('ui.sec_' + sec, ''))}</h2>` + interpItems({ ...d, items: its })).join(''); };
  const lines = s => String(s || '').split(/\s*;\s*|(?<=[.।])\s+/).map(x => x.trim()).filter(Boolean);
  const steps = s => `<ol class="steps">${lines(s).map(x => `<li><span class="ms">check</span><span>${esc(x)}</span></li>`).join('')}</ol>`;
  const derivList = (it) => `<details><summary>${esc(t('ui.how_derived'))}</summary>${lang === 'en' ? `<p class="muted">${esc(it.derivation.rule)}</p>` : ''}${factList(it.derivation.from_calculated)}</details>`;
  const ptsBox = P => P ? `<div class="pn"><div class="pn-g"><h3><span class="ms">thumb_up</span>${esc(t('ui.good_points'))}</h3><ul>${P.positive.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div>
    <div class="pn-b"><h3><span class="ms">warning</span>${esc(t('ui.care_points'))}</h3><ul>${P.negative.length ? P.negative.map(x => `<li>${esc(x)}</li>`).join('') : '<li>—</li>'}</ul></div></div>` : '';
  const collItems = (items, openFirst = true) => items.map((it, i) => collapsible(it.title, `${it.subtitle ? `<p class="sub">${esc(it.subtitle)}</p>` : ''}<p>${esc(it.text)}</p>${ptsBox(it.points)}
      ${it.remedies ? `<h3>${esc(t('ui.remedies_list'))}</h3>${steps(it.remedies.join(' '))}` : ''}${derivList(it)}`, openFirst && (i === 0 || it.section === 'current'))).join('');
  async function tabPred(id, type = 'life', date = today()) {
    const el = document.getElementById('tab'); el.innerHTML = skel();
    try {
      const d = await api('GET', `/profiles/${id}/predictions?type=${type}&date=${date}&tzid=${encodeURIComponent(TZ)}&lang=${lang}`);
      const sub = `<div class="seg">${[['life', 'whole_life'], ['mdphal', 'mdphal'], ['dasha', 'mahadasha_pred'], ['monthly', 'monthly'], ['daily', 'daily']].map(([p, l]) =>
        `<button class="${p === type ? 'on' : ''}" data-p="${p}">${esc(t('ui.' + l))}</button>`).join('')}</div>`;
      let body;
      if (type === 'life') body = `<h2>${esc(t('ui.whole_life'))}</h2>${collItems(d.sections)}
        <h2>${esc(t('ui.houses_timeline'))}</h2>${collapsible(t('ui.sec_houses'), interpItems({ ...d, items: d.items.filter(i => i.section === 'houses') }))}
        ${collapsible(t('ui.sec_timeline'), interpItems({ ...d, items: d.items.filter(i => i.section === 'timeline') }))}`;
      else if (type === 'mdphal') body = `${collItems(d.items, true)}`;
      else body = grouped(d);
      el.innerHTML = `<section>${interpTag()}${body}</section>`;
      if (type === 'dasha') { const M = d.items.find(i => i.id === 'mahadasha'), A = d.items.find(i => i.id === 'antardasha'), N = d.items.find(i => i.id === 'next_ad');
        const period = x => `<div class="dp"><div class="bar-l"><span>${esc(x.start)} → ${esc(x.end)}</span><span>${x.progress ?? 0}%</span></div><div class="bar" data-v="${x.progress ?? 0}"><i style="--w:${x.progress ?? 0}%"></i></div></div>`;
        el.innerHTML = interpTag() + (M ? `<section class="card dcard"><div class="card-h"><span class="ms">hourglass_top</span>${esc(tn('planets', M.lord))} ${esc(t('ui.mahadasha'))}<span class="chip ok">${esc(t('ui.current'))}</span></div>
          ${period(M)}<p class="sub">${esc(M.placement)}</p><p>${esc(M.text)}</p><p>${esc(M.details)}</p>${ptsBox(M.points)}
          <h3>${esc(t('ui.remedies_list'))}</h3>${steps([M.mantra].concat(M.lk_remedies || []).join(' '))}${derivList(M)}</section>` : '')
          + (A ? `<section class="card dcard"><div class="card-h"><span class="ms">hourglass_bottom</span>${esc(tn('planets', A.lord))} ${esc(t('ui.antardasha'))}<span class="chip">${esc(t('ui.current'))}</span></div>
          ${period(A)}<p>${esc(A.text)}</p>${A.lk ? `<p class="sub">${esc(A.lk.effect)}</p>` : ''}${ptsBox(A.points)}${A.lk && A.lk.remedies.length ? `<h3>${esc(t('ui.remedies_list'))}</h3>${steps(A.lk.remedies.join(' '))}` : ''}${derivList(A)}</section>` : '')
          + (N ? `<section class="card dcard"><div class="card-h"><span class="ms">skip_next</span>${esc(t('ui.next_antardasha'))}: ${esc(tn('planets', N.lord))}</div>
          <p class="muted">${esc(N.start)} → ${esc(N.end)}</p><p>${esc(N.text)}</p>${ptsBox(N.points)}</section>` : ''); }
      if (!['life', 'mdphal', 'dasha'].includes(type)) el.prepend(dateBar(date, v => tabPred(id, type, v)));
    } catch (e) { el.innerHTML = errBox(e); }
  }
  async function tabPlanetResults(id) {
    const el = document.getElementById('tab'); el.innerHTML = skel();
    try { const d = await api('GET', `/profiles/${id}/planet-results?lang=${lang}`);
      el.innerHTML = `<section>${interpTag()}${collItems(d.items.map(({ remedies, ...x }) => x))}</section>`; }
    catch (e) { el.innerHTML = errBox(e); }
  }
  async function tabVarsh(id, k, year = new Date().getFullYear()) {
    const el = document.getElementById('tab'); el.innerHTML = skel();
    const by = +k.birth.local.slice(0, 4), yrs = []; for (let y = Math.max(by + 1, year - 5); y <= Math.min(by + 120, year + 10); y++) yrs.push(y);
    try {
      const A = await api('GET', `/profiles/${id}/annual?year=${year}&tzid=${encodeURIComponent(TZ)}&lang=${lang}`), v = A.chart, rd = A.reading;
      const html = `<div class="row" style="margin:1rem 0"><label>${esc(t('ui.year'))}<select id="vy">${yrs.map(y => `<option ${y === year ? 'selected' : ''}>${y}</option>`).join('')}</select></label></div>
        <section class="calc">${calcTag()}<h2 style="margin-top:.5rem">${esc(t('ui.varshphal'))}: ${esc(v.return_local.slice(0, 10))} → ${esc(v.valid_until_local.slice(0, 10))}</h2>
        <div class="chartwrap"><div>${northChart(v, 'D1')}${legend()}</div><div>
          <table><tr><th>${esc(t('ui.return_moment'))}</th><td>${esc(v.return_local)} <span class="muted">(${esc(v.return_utc)})</span></td></tr>
          <tr><th>${esc(t('ui.lagna'))}</th><td>${esc(tn('signs', v.lagna.sign_name))} ${esc(v.lagna.dms)}</td></tr>
          <tr><th>${esc(t('ui.muntha'))}</th><td>${esc(tn('signs', v.muntha.sign_name))} · ${esc(t('ui.house'))} ${v.muntha.house}</td></tr>
          <tr><th>${esc(t('ui.age'))}</th><td>${v.age}</td></tr></table>
          <table><tr><th>${esc(t('ui.planet'))}</th><th>${esc(t('ui.sign'))}</th><th>${esc(t('ui.house'))}</th><th>${esc(t('ui.status'))}</th></tr>
          ${v.planets.map(p => `<tr><td>${esc(tn('planets', p.name))}</td><td>${esc(tn('signs', p.sign_name))} ${esc(p.dms)}</td><td>${p.house}</td><td>${status(p)}</td></tr>`).join('')}</table>
          ${lang === 'en' ? `<p class="muted">${esc(v.meta.method)}. ${esc(v.muntha.rule)}.</p>` : ''}</div></div></section>
`; const pred = `${interpTag()}<p class="lead">${esc(rd.muntha)}</p>
        ${rd.items.map((i, n) => collapsible(i.title, `${verdictChip(i.verdict)}<p class="lead">${esc(i.prediction)}</p><div class="kv"><span>${esc(t('ui.reason'))}</span><span>${esc(i.reason)}</span></div>
          <div class="kv"><span>${esc(t('ui.description'))}</span><span>${esc(i.description)}</span></div><div class="kv"><span>${esc(t('ui.guidance'))}</span><span>${esc(i.guidance)}</span></div>${derivList(i)}`, n === 0, t('ui.v_' + i.verdict))).join('')}`;
      const [top, chart] = [html.slice(0, html.indexOf('</div>') + 6), html.slice(html.indexOf('</div>') + 6)];
      let hv = null; try { hv = await api('GET', `/profiles/${id}/house-varsh?year=${year}&lang=${lang}`); } catch (e) {}
      const hvChart = () => hv ? `<div class="chartwrap"><section class="card">${interpTag()}${northChart(hv, 'D1')}${legend()}</section><section class="card"><div class="card-h"><span class="ms">speed</span>${esc(t('ui.overall_rating'))}</div>${dial(hv.score)}
        <table><tr><th>${esc(t('ui.planet'))}</th><th>${esc(t('ui.house'))}</th><th>${esc(t('ui.status'))}</th></tr>${hv.planets.map(x => `<tr><td>${esc(tn('planets', x.name))}</td><td>${x.natal_house} → <b>${x.house}</b></td><td><span class="chip ${x.benefic ? 'ok' : 'warn'}">${esc(t(x.benefic ? 'ui.q_good' : 'ui.sec_caution'))}</span></td></tr>`).join('')}</table></section></div>` : errBox({ message: t('ui.none') });
      const hvPred = () => hv ? `<div class="tgrid">${hv.planets.map(x => `<article class="card tcard ${x.benefic ? 'strong' : 'weak'}"><div class="card-h"><span class="ms">${x.benefic ? 'thumb_up' : 'warning'}</span>${esc(tn('planets', x.name))} · ${esc(t('ui.house'))} ${x.house}</div>
        <p class="lead">${esc(x.effect)}</p>${x.remedies.length ? `<h3>${esc(t('ui.remedies_list'))}</h3>${steps(x.remedies.join(' '))}` : ''}</article>`).join('')}</div>` : '';
      localTabs(el, [['k', t('ui.kundali'), 'grid_view', () => chart], ['p', t('ui.annual_prediction'), 'auto_awesome', () => pred],
        ['hk', t('ui.house_varsh'), 'grid_on', hvChart], ['hp', t('ui.house_varsh_fal'), 'menu_book', hvPred]]);
      el.insertAdjacentHTML('afterbegin', top);
      el.querySelector('#vy').onchange = e => tabVarsh(id, k, +e.target.value);
    } catch (e) { el.innerHTML = errBox(e); }
  }
  async function tabOverview(id, k) {
    const el = document.getElementById('tab'); el.innerHTML = skel();
    try {
      const [d, L] = await Promise.all([api('GET', `/profiles/${id}/summary?lang=${lang}`), api('GET', `/profiles/${id}/luck?lang=${lang}`)]), P = d.planets;
      const pl = n => esc(tn('planets', n)), list = a => a.length ? a.map(n => `<span class="chip">${pl(n)} · ${P[n].score}</span>`).join('') : '—';
      const tile = (ic, lbl, val, from) => `<div class="tile"><span class="ms">${ic}</span><small>${esc(lbl)}</small><b>${val}</b>${from ? `<i>${esc(t('ui.from_planet'))}: ${esc(from)}</i>` : ''}</div>`;
      const yrs = a => a.length ? a.map(p => `<div class="yr"><b>${p.from}${p.to !== p.from ? '–' + p.to : ''}</b><span>${pl(p.md)} ${esc(t('ui.mahadasha'))}</span></div>`).join('') : `<p class="muted">—</p>`;
      const C = L.career.ranking, cl = { job: 'job', business: 'business', govt: 'govt_job' };
      el.innerHTML = `<section class="card"><div class="card-h"><span class="ms">badge</span>${esc(t('ui.chart_profile'))}${calcTag()}</div><div class="tiles">
          ${tile('dark_mode', t('ui.rashi'), esc(tn('signs', L.rashi)))}${tile('north_east', t('ui.lagna'), esc(tn('signs', L.lagna)))}${tile('stars', t('ui.nakshatra'), esc(tn('nakshatras', L.nakshatra)))}
          ${tile('temple_hindu', t('ui.aradhya'), esc(L.aradhya.text), L.aradhya.from.name)}${L.bhagya_ratna ? tile('diamond', t('ui.bhagya_ratna'), esc(L.bhagya_ratna.gem), L.bhagya_ratna.from.name) : ''}</div></section>
        <div class="dash">
        <section class="card metric"><div class="card-h"><span class="ms">speed</span>${esc(t('ui.overall_rating'))}${interpTag()}</div>${dial(d.overall, esc(d.method.overall))}</section>
        <section class="card metric"><div class="card-h"><span class="ms">donut_large</span>${esc(t('ui.positive'))} / ${esc(t('ui.challenging'))}</div>${ring(d.positive_pct)}
          <div class="big"><span data-count="${d.positive_pct}">0</span><small>%</small></div>
          ${bar(esc(t('ui.positive')), d.positive_pct, d.positive_pct + '%')}${bar(esc(t('ui.challenging')), d.challenging_pct, d.challenging_pct + '%', 'neg')}<p class="muted center">${esc(d.method.percent)}</p></section>
        <section class="card"><div class="card-h"><span class="ms">military_tech</span>${esc(t('ui.key_planets'))}</div>
          <div class="kv"><span>${esc(t('ui.strongest'))}</span><b>${pl(d.strongest)} · ${P[d.strongest].score}</b></div><div class="kv"><span>${esc(t('ui.weakest'))}</span><b>${pl(d.weakest)} · ${P[d.weakest].score}</b></div>
          <h3>${esc(t('ui.most_supportive'))}</h3><div class="chips">${list(d.supportive)}</div><h3>${esc(t('ui.most_challenging'))}</h3><div class="chips">${list(d.challenging)}</div></section></div>
        <section class="card"><div class="card-h"><span class="ms">star</span>${esc(t('ui.luck'))}${interpTag()}</div><div class="tiles">
          ${tile('palette', t('ui.shubh_color'), esc(L.lucky.color), L.lucky.from.map(x => x.name).join(', '))}${tile('pin', t('ui.shubh_number'), L.lucky.number.join(', '))}
          ${tile('event', t('ui.shubh_day'), esc(L.lucky.day.join(', ')))}${tile('calendar_month', t('ui.shubh_dates'), L.lucky.dates.sort((a, b) => a - b).join(', '))}
          ${tile('dangerous', t('ui.ashubh_grah'), L.unlucky.planets.map(x => esc(x.name)).join(', ') || '—')}${tile('format_color_reset', t('ui.ashubh_color'), esc(L.unlucky.color), L.unlucky.from.name)}
          ${tile('block', t('ui.ashubh_number'), L.unlucky.number)}</div></section>
        <div class="dash"><section class="card"><div class="card-h"><span class="ms">trending_up</span>${esc(t('ui.best_years'))}</div><div class="yrs good">${yrs(L.best_years)}</div></section>
          <section class="card"><div class="card-h"><span class="ms">trending_down</span>${esc(t('ui.negative_years'))}</div><div class="yrs bad">${yrs(L.negative_years)}</div></section>
          <section class="card"><div class="card-h"><span class="ms">work</span>${esc(t('ui.career_type'))}</div>
            ${Object.entries(C).map(([k2, v]) => bar(`${esc(t('ui.' + cl[k2]))}${k2 === L.career.best ? ` <span class="chip ok">${esc(t('ui.best_choice'))}</span>` : ''}`, v, v)).join('')}
            <p class="muted">${esc(L.career.method)}</p></section></div>
        <section class="card"><div class="card-h"><span class="ms">explore</span>${esc(t('ui.lucky_field'))}</div><div class="fields">${L.career.fields.map(f => `<div class="field"><b>${esc(f.name)}</b><span>${esc(f.fields)}</span></div>`).join('')}</div></section>
        <section class="card wide"><div class="card-h"><span class="ms">leaderboard</span>${esc(t('ui.planet_ratings'))}</div>
          <div class="bars2">${Object.entries(P).map(([n, x]) => bar(`${pl(n)} <small class="muted">${esc(tn('signs', x.sign))} · ${x.house}</small>`, x.score, x.score)).join('')}</div>
          <details><summary>${esc(t('ui.how_calculated'))}</summary><p class="muted">${esc(d.method.planet)}</p>
          <ul class="facts">${Object.entries(P).map(([n, x]) => `<li><span>${pl(n)}</span><b>${x.score}</b></li>`).join('')}</ul></details></section>`;
    } catch (e) { el.innerHTML = errBox(e); }
  }
  const TOPIC_IC = { career: 'work', finance: 'payments', relationships: 'favorite', health: 'ecg_heart', mind: 'self_improvement', education: 'school', business: 'storefront' };
  const topicCards = d => `<div class="dash">
      <section class="card metric"><div class="card-h"><span class="ms">speed</span>${esc(t('ui.day_score'))}${interpTag()}</div>${dial(d.overall)}
        <div class="chips center"><span class="chip"><span class="ms">dark_mode</span>${esc(tn('signs', d.moon_sign))}</span><span class="chip">${esc(tn('nakshatras', d.moon_nakshatra))}</span></div></section>
      <section class="card"><div class="card-h"><span class="ms">bar_chart</span>${esc(t('ui.topics'))}</div>${d.items.map(i => bar(`<span class="ms sm">${TOPIC_IC[i.topic]}</span> ${esc(i.title)}`, i.score, i.score)).join('')}</section></div>
    <div class="tgrid">${d.items.map(i => `<article class="card tcard ${i.verdict}"><div class="card-h"><span class="ms">${TOPIC_IC[i.topic]}</span>${esc(i.title)}${verdictChip(i.verdict)}</div>
      <p class="lead">${esc(i.prediction)}</p>
      <ul class="pts">${(i.points || []).map(x => `<li class="${x.good ? 'g' : 'b'}"><span class="ms">${x.good ? 'arrow_upward' : 'arrow_downward'}</span><div><b>${esc(tn('planets', x.planet))} · ${esc(t('ui.house'))} ${x.house}</b>
        <small>${esc(t(x.good ? 'ui.q_good' : 'ui.sec_caution'))}: ${esc(x.domain)}</small>${x.lk ? `<small class="lkx">${esc(x.lk)}</small>` : ''}</div></li>`).join('')}</ul>
      ${(i.notes || []).map(n => `<p class="note"><span class="ms">info</span>${esc(n)}</p>`).join('')}
      <div class="guide"><span class="ms">tips_and_updates</span><span>${esc(i.guidance)}</span></div>${i.remedy ? `<div class="guide rem"><span class="ms">spa</span><span>${esc(i.remedy)}</span></div>` : ''}${derivList(i)}</article>`).join('')}</div>`;
  async function tabDaily(id, date = today()) {
    const el = document.getElementById('tab'); el.innerHTML = skel();
    try { const d = await api('GET', `/profiles/${id}/daily-reading?date=${date}&tzid=${encodeURIComponent(TZ)}&lang=${lang}`);
      el.innerHTML = topicCards(d); el.prepend(dateBar(date, v => tabDaily(id, v)));
    } catch (e) { el.innerHTML = errBox(e); }
  }
  async function tabMonthly(id, month = monthsAhead()[0].v) {
    const el = document.getElementById('tab'); el.innerHTML = skel();
    try { const d = await api('GET', `/profiles/${id}/monthly-reading?month=${month}&tzid=${encodeURIComponent(TZ)}&lang=${lang}`);
      el.innerHTML = `<div class="row" style="margin:.4rem 0 1rem"><label>${esc(t('ui.month'))}<select id="mm">${monthsAhead().map(m => `<option value="${m.v}" ${m.v === month ? 'selected' : ''}>${esc(m.l)}</option>`).join('')}</select></label></div>`
        + topicCards(d).replace(esc(t('ui.day_score')), esc(t('ui.monthly')));
      el.querySelector('#mm').onchange = e => tabMonthly(id, e.target.value);
    } catch (e) { el.innerHTML = errBox(e); }
  }
  async function tabYogas(id) {
    const el = document.getElementById('tab'); el.innerHTML = skel();
    try { const d = await api('GET', `/profiles/${id}/yogas?lang=${lang}`), P = d.items.filter(i => i.present), A = d.items.filter(i => !i.present);
      el.innerHTML = `<div class="chips"><span class="chip"><span class="ms">fact_check</span>${esc(t('ui.checked'))}: ${d.checked}</span><span class="chip ok"><span class="ms">auto_fix_high</span>${esc(t('ui.present'))}: ${d.present}</span></div>
        <h2>${esc(t('ui.present_yogas'))}</h2>${P.length ? `<div class="tgrid">${P.map(i => `<article class="card tcard strong"><div class="card-h"><span class="ms">auto_fix_high</span>${esc(i.name)}</div>
          <p class="lead">${esc(i.effect)}</p><p class="rule">${esc(i.rule)}</p>${factList(i.factors)}</article>`).join('')}</div>` : `<p class="muted">${esc(t('ui.none'))}</p>`}
        <h2>${esc(t('ui.other_yogas'))}</h2><div class="dlist">${A.map(i => `<div class="drow"><span class="ms">remove_circle</span><b>${esc(i.name)}</b><span class="st off">${esc(t('ui.absent'))}</span></div>`).join('')}</div>`;
    } catch (e) { el.innerHTML = errBox(e); }
  }
  const doshaCalc = (c) => `<section class="calc">${calcTag()}<table>
    <tr><th>Mangal</th><td>${c.mangal_dosha.present ? '✓' : '—'}</td><td class="muted">${esc(t('ui.from_lagna'))} ${c.mangal_dosha.from.lagna.house}, ${esc(t('ui.from_moon'))} ${c.mangal_dosha.from.moon.house}, Venus ${c.mangal_dosha.from.venus.house}</td></tr>
    <tr><th>Kaal Sarp</th><td>${c.kaal_sarp.present ? '✓ ' + esc(c.kaal_sarp.type) : '—'}</td><td class="muted">${esc(c.kaal_sarp.rule)}</td></tr>
    <tr><th>Sade Sati</th><td>${c.sade_sati.active ? '✓ ' + esc(t('interp.sade_phase.' + c.sade_sati.phase)) : '—'}</td><td class="muted">${c.sade_sati.period ? esc(c.sade_sati.period.start + ' → ' + c.sade_sati.period.end) : ''}</td></tr>
    <tr><th>Shani Dhaiya</th><td>${c.shani_dhaiya.active ? '✓' : '—'}</td><td class="muted">${esc(c.shani_dhaiya.rule)}</td></tr></table>
    <p class="muted">${esc(t('ui.as_on'))} ${esc(c.as_on)}</p></section>`;
  async function tabDoshas(id) {
    const el = document.getElementById('tab'); el.innerHTML = skel();
    try {
      const d = await api('GET', `/profiles/${id}/dosha-report?tzid=${encodeURIComponent(TZ)}&lang=${lang}`), P = d.items.filter(i => i.present);
      el.innerHTML = `<div class="chips"><span class="chip"><span class="ms">fact_check</span>${esc(t('ui.checked'))}: ${d.checked}</span><span class="chip ${d.present ? 'warn' : 'ok'}"><span class="ms">report</span>${esc(t('ui.present'))}: ${d.present}</span></div>
        <div class="dlist">${d.items.map(i => `<div class="drow ${i.present ? 'on' : ''}"><span class="ms">${i.present ? 'error' : 'check_circle'}</span><b>${esc(i.name)}</b><span class="st">${esc(i.present ? t('ui.present') : t('ui.absent'))}</span></div>`).join('')}</div>
        ${d.rin ? `<h2><span class="ms">family_restroom</span> ${esc(t('ui.rin'))}</h2><div class="dlist">${d.rin.items.map(i => `<div class="drow ${i.present ? 'on' : ''}"><span class="ms">${i.present ? 'error' : 'check_circle'}</span><b>${esc(i.name)}</b><span class="st">${esc(i.present ? t('ui.present') : t('ui.absent'))}</span></div>`).join('')}</div>
          ${d.rin.items.filter(i => i.present).map(i => collapsible(i.name, `<ul class="lines"><li>${esc(i.rule)}</li></ul><h3>${esc(t('ui.factors'))}</h3>${factList(i.factors)}<h3>${esc(t('ui.effects'))}</h3><p>${esc(i.effect)}</p><h3>${esc(t('ui.remedy_how'))}</h3>${steps(i.remedy)}`, false, i.effect)).join('')}` : ''}
        ${P.length ? `<h2><span class="ms">help</span> ${esc(t('ui.how_dosha'))}</h2>` + P.map((i, n) => collapsible(i.name, `<ul class="lines">${lines(i.rule).map(x => `<li>${esc(x)}</li>`).join('')}</ul><h3>${esc(t('ui.factors'))}</h3>${factList(i.factors)}
          <h3>${esc(t('ui.effects'))}</h3><p>${esc(i.effects)}</p><h3>${esc(t('ui.remedy_how'))}</h3>${steps(i.remedy)}`, n === 0, i.effects)).join('') : ''}`;
    } catch (e) { el.innerHTML = errBox(e); }
  }
  const gemCard = g => `<section class="card gemcard wide"><div class="gemtop">${gemSvg(g.color)}<div><h3>${esc(g.gem)}</h3><span class="chip">${esc(tn('planets', g.planet))}</span></div></div><div class="gcols"><div class="gcol">
    ${g.caution ? `<div class="warn">${esc(g.caution)}</div>` : ''}
    <div class="kv"><span>${esc(t('ui.why_recommended'))}</span><span>${esc(g.why)}</span></div><div class="kv"><span>${esc(t('ui.helps_with'))}</span><span>${esc(g.helps)}</span></div>
    <div class="kv"><span>${esc(t('ui.when_to_wear'))}</span><span>${esc(g.when)}</span></div></div><div class="gcol">
    <table class="proc"><tr><th>${esc(t('ui.metal'))}</th><td>${esc(g.process.metal)}</td></tr><tr><th>${esc(t('ui.finger'))}</th><td>${esc(g.process.finger)}</td></tr>
    <tr><th>${esc(t('ui.vara'))}</th><td>${esc(g.process.day)}</td></tr><tr><th>${esc(t('ui.weight'))}</th><td>${esc(g.process.weight)}</td></tr><tr><th>${esc(t('ui.preparation'))}</th><td>${esc(g.process.preparation)}</td></tr></table>${derivList(g)}</div></section>`;
  async function tabGems(id) {
    const el = document.getElementById('tab'); el.innerHTML = skel();
    try {
      const [g, inf] = await Promise.all([api('GET', `/profiles/${id}/gem-report?lang=${lang}`), api('GET', `/profiles/${id}/gemstones?lang=${lang}`)]);
      localTabs(el, [['r', t('ui.recommended'), 'verified', () => g.recommended.map(gemCard).join('') || `<p class="muted">${esc(t('ui.none'))}</p>`],
        ['c', t('ui.caution_gems'), 'warning', () => g.caution.map(gemCard).join('') || `<p class="muted">${esc(t('ui.none'))}</p>`],
        ['a', t('ui.avoid_gems'), 'block', () => `<div class="dash">${g.avoid.map(a => `<section class="card gemcard dim"><div class="gemtop">${gemSvg(a.color)}<div><h3>${esc(a.gem)}</h3></div></div><p>${esc(a.why)}</p></section>`).join('')}</div>`],
        ['i', t('ui.influences'), 'balance', () => grouped(inf.influences)]]);
      el.insertAdjacentHTML('beforeend', `<p class="muted">${esc(g.note)}</p>`);
    } catch (e) { el.innerHTML = errBox(e); }
  }

  const monthsAhead = () => { const d = new Date(); d.setDate(1); return Array.from({ length: 13 }, (_, i) => {
    const x = new Date(d.getFullYear(), d.getMonth() + i, 1);
    return { v: `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}`, l: x.toLocaleDateString(lang === 'en' ? 'en-IN' : lang + '-IN', { month: 'long', year: 'numeric' }) }; }); };
  async function tabPriority(id) {
    const el = document.getElementById('tab'); el.innerHTML = skel();
    try { const pr = await api('GET', `/profiles/${id}/priority-remedies?lang=${lang}&tzid=${encodeURIComponent(TZ)}`);
      el.innerHTML = interpTag() + pr.items.map((i, n) => collapsible(`${i.rank}. ${i.title}`, `<div class="kv"><span>${esc(t('ui.issue'))}</span><span>${esc(i.issue)}</span></div>
        <h3>${esc(t('ui.remedy_how'))}</h3>${steps(i.how)}<div class="kv"><span>${esc(t('ui.benefit'))}</span><span>${esc(i.benefit)}</span></div>
        <h3>${esc(t('ui.factors'))}</h3>${factList(i.derivation.from_calculated)}`, n === 0, i.issue)).join('');
    } catch (e) { el.innerHTML = errBox(e); } }
  // ---------- Personal remedy plan (new; the existing remedy tabs are unchanged) ----------
  const RPV = [['life', 'rp_life', 'all_inclusive'], ['day', 'rp_period', 'event'], ['important', 'rp_important', 'priority_high']], RPP = [['day', 'rp_day'], ['week', 'rp_week'], ['month', 'rp_month']];
  const rpItem = (i, n) => `<li class="rp-it"><div class="rp-top">${n ? `<span class="rp-n">${n}</span>` : ''}${i.planet_name ? `<span class="rp-pl">${esc(i.planet_name)}</span>` : ''}
      ${(i.dates || (i.date ? [i.date] : [])).map(d => `<span class="rp-dt">${esc(fmtD(d, { weekday: 'short', day: 'numeric', month: 'short' }))}</span>`).join('')}</div>
    <p>${esc(i.text)}</p>${i.reason ? `<small><span class="ms">person_search</span>${esc(t('ui.why_this'))}: ${esc(i.reason)}</small>` : ''}</li>`;
  document.addEventListener('click', e => { const a = e.target.closest('#goPredict'); if (!a) return;
    try { const st = JSON.parse(localStorage.getItem('pp') || '{}'); st.kid = +location.hash.split('/')[2]; localStorage.setItem('pp', JSON.stringify(st)); } catch (er) {} });
  async function tabRemedyPlan(id, view = 'life', date = today()) {
    const el = document.getElementById('tab'); el.innerHTML = skel();
    try {
      const d = await api('GET', `/profiles/${id}/remedy-plan?view=${view}&date=${date}&lang=${lang}`);
      const head = view === 'day' ? fmtD(d.date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) : view === 'week' ? rangeText(d) : view === 'month' ? fmtD(d.month + '-01', { month: 'long', year: 'numeric' }) : '';
      let body;
      if (view === 'life') body = `${d.planets.length ? `<p class="rp-weak"><b>${esc(t('ui.weak_planets'))}:</b> ${d.planets.map(p => `<span class="rp-pl">${esc(p.name)} ${p.score}/100</span>`).join(' ')}</p>` : ''}
        <div class="rp-groups">${['daily', 'weekly', 'monthly', 'yearly'].map(f => d.groups[f].length ? `<details class="rp-g rp-${f} cx"${f === 'daily' ? ' open' : ''}><summary><span class="ms">${{ daily: 'today', weekly: 'date_range', monthly: 'dark_mode', yearly: 'cake' }[f]}</span>${esc(t('ui.f_' + f))}<span class="cx-n">${d.groups[f].length}</span></summary><ul>${d.groups[f].map(i => rpItem(i)).join('')}</ul></details>` : '').join('')}</div>`;
      else body = `<ul class="rp-list${view === 'important' ? ' rp-imp' : ''}">${d.items.map((i, n) => rpItem(i, view === 'important' ? n + 1 : 0)).join('')}</ul>`;
      el.innerHTML = `<div class="rp-bar"><div class="pp-seg">${RPV.map(([v, l, ic]) => `<button type="button" data-v="${v}" class="${v === view || (v === 'day' && ['week', 'month'].includes(view)) ? 'on' : ''}"><span class="ms">${ic}</span><span>${esc(t('ui.' + l))}</span></button>`).join('')}</div>
        ${['day', 'week', 'month'].includes(view) ? `<div class="seg rp-sub">${RPP.map(([v, l]) => `<button type="button" data-v="${v}" class="${v === view ? 'on' : ''}">${esc(t('ui.' + l))}</button>`).join('')}</div><label class="pp-date"><span class="ms">event</span><input type="date" id="rpd" value="${esc(date)}" aria-label="${esc(t('ui.date'))}"></label>` : ''}</div>
        ${head ? `<p class="rp-head"><span class="ms">event</span>${esc(head)}</p>` : ''}${interpTag()}${body}`;
      el.querySelectorAll('[data-v]').forEach(b => b.onclick = () => tabRemedyPlan(id, b.dataset.v, date));
      el.querySelector('#rpd')?.addEventListener('change', e => tabRemedyPlan(id, view, e.target.value || today()));
    } catch (e) { el.innerHTML = errBox(e); }
  }
  async function tabPooja(id) {
    const el = document.getElementById('tab'); el.innerHTML = skel();
    try { const d = await api('GET', `/profiles/${id}/poojas?lang=${lang}`);
      const row = (ic, l, v) => `<div class="pj-row"><span class="ms">${ic}</span><div><b>${esc(t('ui.' + l))}</b><p>${esc(v)}</p></div></div>`;
      el.innerHTML = interpTag() + (d.items.length ? `<div class="pj-list">${d.items.map((p, n) => `<details class="pj-card cx"${n ? '' : ' open'}><summary><span class="rp-n">${n + 1}</span><span class="cx-t"><b>${esc(p.name)}</b><small>${esc(p.purpose)}</small></span></summary><div class="pj-row"><span class="ms">auto_stories</span><div><b>${esc(t('ui.pooja_factors'))}</b><ul>${p.factors.map(f => `<li>${esc(f)}</li>`).join('')}</ul></div></div>
          ${row('help', 'pooja_why', p.why)}${row('schedule', 'pooja_timing', p.timing)}${row('local_fire_department', 'pooja_involves', p.involves)}${row('payments', 'pooja_cost', p.cost)}</details>`).join('')}</div>`
        : `<p class="pp-empty"><span class="ms">self_improvement</span>${esc(t('ui.no_pooja'))}</p>`) + `<p class="pp-disc"><span class="ms">info</span>${esc(d.note)}</p>`;
    } catch (e) { el.innerHTML = errBox(e); } }
  async function tabRemedies(id, period = 'common', month = monthsAhead()[0].v) {
    const el = document.getElementById('tab'); el.innerHTML = skel();
    try {
      const q = `period=${period}&lang=${lang}&tzid=${encodeURIComponent(TZ)}` + (period === 'monthly' ? `&month=${month}` : '');
      const d = await api('GET', `/profiles/${id}/remedies?${q}`);
      el.innerHTML = `<div class="row" style="margin:.5rem 0 1rem"><div class="seg">${[['common', 'common_remedies'], ['daily', 'daily'], ['weekly', 'weekly'], ['monthly', 'monthly']].map(([p, l]) =>
        `<button class="${p === period ? 'on' : ''}" data-p="${p}">${esc(t('ui.' + l))}</button>`).join('')}</div>
        ${period === 'monthly' ? `<label>${esc(t('ui.month'))}<select id="rm">${monthsAhead().map(m => `<option value="${m.v}" ${m.v === month ? 'selected' : ''}>${esc(m.l)}</option>`).join('')}</select></label>` : ''}</div>
        <section>${interpTag()}${interpItems(d)}</section>`;
      el.querySelectorAll('[data-p]').forEach(b => b.onclick = () => tabRemedies(id, b.dataset.p));
      el.querySelector('#rm')?.addEventListener('change', e => tabRemedies(id, 'monthly', e.target.value));
    } catch (e) { el.innerHTML = errBox(e); }
  }

  // ---------- Printable multi-page report (browser "Save as PDF" keeps Hindi/Gujarati text shaping correct) ----------
  // ---------- Printable kundali book (A4): cover, contents, chapters built by the server from the knowledge base ----------
  async function viewPrint(id) {
    loading();
    let r; try { r = await api('GET', `/profiles/${id}/book?lang=${lang}&tzid=${encodeURIComponent(TZ)}`); } catch (e) { h(errBox(e)); return; }
    const k = r.kundali, P = r.profile, B = r.book, L = B.labels, b = k.birth;
    document.title = `${P.label} - ${L.cover_title} - KarmYog Astro`; // Chrome uses this as the PDF file name
    const moonK = { ...k, vargas: { ...k.vargas, D1: { ...k.vargas.D1, lagna: k.vargas.D1.planets.Moon } } };
    const fig = (cap, svg) => `<figure class="bk-fig">${svg}<figcaption>${esc(cap)}</figcaption></figure>`;
    const charts = {
      D1: () => fig(vname('D1'), northChart(k, 'D1')), D9: () => fig(vname('D9'), northChart(k, 'D9')),
      moon: () => fig(t('ui.moon_chart', t('ui.moon_sign')), northChart(moonK, 'D1')), varsh: () => fig(t('ui.varshphal'), northChart(r.varshphal, 'D1')),
      vargas: () => `<div class="bk-vg">${Object.keys(k.vargas).filter(v => v !== 'D1').map(v => fig(vname(v), northChart(k, v))).join('')}</div>`,
    };
    const planetsTable = () => `<table class="bk-t keep"><thead><tr><th>${esc(L.table_planet)}</th><th>${esc(L.table_sign)}</th><th>${esc(L.table_deg)}</th><th>${esc(L.table_nak)}</th><th>${esc(L.table_house)}</th><th>${esc(L.table_status)}</th></tr></thead><tbody>
      <tr><td><b>${esc(t('ui.lagna'))}</b></td><td>${esc(tn('signs', k.lagna.sign_name))}</td><td>${esc(k.lagna.dms)}</td><td>${esc(tn('nakshatras', k.lagna.nakshatra))} ${k.lagna.pada}</td><td>1</td><td></td></tr>
      ${k.planets.map(p => `<tr><td><b>${esc(tn('planets', p.name))}</b></td><td>${esc(tn('signs', p.sign_name))}</td><td>${esc(p.dms)}</td><td>${esc(tn('nakshatras', p.nakshatra))} ${p.pada}</td><td>${p.house}</td><td>${status(p)}</td></tr>`).join('')}</tbody></table>`;
    const block = x => {
      switch (x[0]) {
        case 'p': return `<p>${esc(x[1])}</p>`;
        case 'h': return `<h4 class="bk-h">${esc(x[1])}</h4>`;
        case 'note': return `<div class="bk-note"><span class="ms">info</span><p>${esc(x[1])}</p></div>`;
        case 'facts': return `<div class="bk-facts">${x[1].map(([l, v]) => `<div><small>${esc(l)}</small><b>${esc(v)}</b></div>`).join('')}</div>`;
        case 'list': return `<ul class="bk-list">${x[1].map(i => `<li>${esc(i)}</li>`).join('')}</ul>`;
        case 'table': return `<table class="bk-t${x[2].length <= 10 ? ' keep' : ''}">${x[1].some(Boolean) ? `<thead><tr>${x[1].map(c => `<th>${esc(c)}</th>`).join('')}</tr></thead>` : ''}<tbody>${x[2].map(row => `<tr>${row.map((c, i) => i ? `<td>${esc(c)}</td>` : `<td><b>${esc(c)}</b></td>`).join('')}</tr>`).join('')}</tbody></table>`;
        case 'chart': return charts[x[1]] ? charts[x[1]]() : '';
        case 'charts2': return `<div class="bk-two">${x.slice(1).map(c => charts[c]()).join('')}</div>`;
        case 'planets': return planetsTable();
        case 'score': return `<div class="bk-score"><span>${esc(x[1])}</span><i><em class="${x[2] >= 60 ? 'g' : x[2] < 45 ? 'r' : 'a'}" style="width:${Math.max(4, Math.min(100, x[2]))}%"></em></i></div>`;
        case 'pros': return `<div class="bk-pros"><div class="g"><b><span class="ms">thumb_up</span>${esc(t('ui.positive', 'Favourable'))}</b><ul>${x[1].map(i => `<li>${esc(i)}</li>`).join('')}</ul></div>
          <div class="r"><b><span class="ms">error</span>${esc(t('ui.challenging'))}</b><ul>${x[2].map(i => `<li>${esc(i)}</li>`).join('')}</ul></div></div>`;
      }
      return '';
    };
    const opener = (n, c) => `<header class="bk-open"><span class="bk-num">${String(n).padStart(2, '0')}</span><span class="bk-ic"><span class="ms">${esc(c.icon)}</span></span><h2>${esc(c.title)}</h2><i class="bk-orn"></i></header>`;
    const chapter = (c, n) => {
      if (!c.sections) return `<section class="bk-ch" id="bk-${c.id}">${opener(n, c)}${c.blocks.map(block).join('')}</section>`;
      return c.sections.map((s, i) => `<section class="bk-ch${c.flow && i ? ' flow' : ''}"${i ? '' : ` id="bk-${c.id}"`}>${i && c.flow ? '' : opener(n, c)}${!i && c.intro ? `<p>${esc(c.intro)}</p>` : ''}
        <div class="bk-sec${s.now ? ' now' : ''}"><h3>${esc(s.title)}</h3>${s.sub ? `<p class="bk-sub">${esc(s.sub)}</p>` : ''}${s.blocks.map(block).join('')}</div></section>`).join('');
    };
    const gen = new Date().toLocaleDateString(lang === 'en' ? 'en-IN' : lang === 'hi' ? 'hi-IN' : 'gu-IN', { day: 'numeric', month: 'long', year: 'numeric' });
    h(`<div class="bk-bar noprint"><a class="btn ghost" href="#/chart/${id}"><span class="ms">arrow_back</span>${esc(P.label)}</a>
        <button onclick="window.print()"><span class="ms">picture_as_pdf</span>${esc(t('ui.download_report'))}</button><span class="muted">${esc(t('ui.print_hint'))}</span></div>
      <table class="bk"><thead><tr><td><div class="bk-run"><img src="${LOGO}" alt="KarmYog Astro · Vastu"><span>${esc(P.label)} · ${esc(L.cover_title)}</span></div></td></tr></thead>
      <tfoot><tr><td><div class="bk-foot">KarmYog Astro · Vastu · astro.hemalsavaria.com</div></td></tr></tfoot><tbody><tr><td>
      <section class="bk-cover"><img class="bk-logo" src="${ASSET}brand/logo-square.webp" alt="KarmYog Astro · Vastu">
        <p class="bk-k">${esc(L.cover_sub)}</p><h1>${esc(L.cover_title)}</h1><i class="bk-orn"></i>
        <p class="bk-for">${esc(L.prepared_for)}</p><p class="bk-name">${esc(P.label)}</p>
        <div class="bk-cf"><div><small>${esc(t('ui.birth_date'))}</small><b>${esc(b.local)}</b></div><div><small>${esc(t('ui.birth_place'))}</small><b>${esc(P.place_name)}</b></div>
          <div><small>${esc(t('ui.lagna'))}</small><b>${esc(tn('signs', k.lagna.sign_name))}</b></div><div><small>${esc(t('ui.moon_sign'))}</small><b>${esc(tn('signs', k.planets.find(p => p.name === 'Moon').sign_name))}</b></div></div>
        <p class="bk-gen">${esc(L.generated)}: ${esc(gen)}</p></section>
      <section class="bk-toc"><h2>${esc(L.contents)}</h2><ol>${B.chapters.map(c => `<li><a href="#bk-${c.id}" onclick="event.preventDefault();document.getElementById('bk-${c.id}').scrollIntoView({behavior:'smooth'})"><span class="ms">${esc(c.icon)}</span>${esc(c.title)}</a></li>`).join('')}</ol></section>
      ${B.chapters.map((c, i) => chapter(c, i + 1)).join('')}
      </td></tr></tbody></table>`);
  }
  function viewAdd() {
    h(`${ph('person_add', t('ui.add_chart'), t('ui.add_sub'))}
      <div class="addwrap"><section class="card">${chartForm()}</section>
      <aside class="card tips"><div class="card-h"><span class="ms">lightbulb</span>${esc(t('ui.tips'))}</div><ul><li>${esc(t('ui.tip1'))}</li><li>${esc(t('ui.tip2'))}</li><li>${esc(t('ui.tip3'))}</li></ul></aside></div>`);
    bindChartForm();
    let dr = null; try { dr = JSON.parse(sessionStorage.getItem('kdraft') || 'null'); sessionStorage.removeItem('kdraft'); } catch (e) {}
    if (dr) { const f = document.getElementById('cf'); ['label', 'birth_date', 'birth_time', 'place_q'].forEach(k => { if (dr[k]) f[k].value = dr[k]; });
      f.place_q.dispatchEvent(new Event('input')); f.place_q.focus(); }
  }
  async function viewEdit(id) {
    loading(); let p; try { p = await api('GET', '/profiles/' + id); } catch (e) { h(errBox(e)); return; }
    h(`${ph('edit_calendar', t('ui.edit_kundali'), p.label)}
      <div class="addwrap"><section class="card">${chartForm()}</section>
      <aside class="card tips"><div class="card-h"><span class="ms">info</span>${esc(t('ui.tips'))}</div><ul><li>${esc(t('ui.edit_hint'))}</li><li>${esc(t('ui.tip1'))}</li></ul></aside></div>`);
    bindChartForm(p);
  }
  // local in-tab tabs
  function localTabs(el, list, i0 = 0) {
    el.innerHTML = `<div class="ltabs">${list.map(([k, l, ic], i) => `<button data-l="${i}" class="${i === i0 ? 'on' : ''}"><span class="ms">${ic}</span>${esc(l)}</button>`).join('')}</div><div class="lbody"></div>`;
    const body = el.querySelector('.lbody'), go = i => { el.querySelectorAll('[data-l]').forEach(b => b.classList.toggle('on', +b.dataset.l === i)); body.innerHTML = list[i][3](); };
    el.querySelectorAll('[data-l]').forEach(b => b.onclick = () => go(+b.dataset.l)); go(i0);
  }

  // ---------- Admin: remedy rules ----------
  const ADM = [['overview', 'ui.admin_overview', 'monitoring'], ['users', 'ui.admin_users', 'group'], ['kundalis', 'ui.admin_kundalis', 'auto_stories'],
    ['categories', 'ui.categories', 'category'], ['remedies', 'ui.remedy_rules', 'spa'], ['plans', 'ui.admin_plans', 'workspace_premium'], ['admins', 'ui.admin_admins', 'shield_person']];
  const adminTabs = () => '';
  const admWrap = (cur, html) => `<div class="adm"><aside class="adm-nav"><p class="adm-brand"><span class="ms">admin_panel_settings</span>${esc(t('ui.admin'))}</p>
    ${ADM.map(([k, l, ic]) => `<a class="${cur === k ? 'on' : ''}" href="#/${k}"><span class="ms">${ic}</span><span>${esc(t(l))}</span></a>`).join('')}
    <a class="adm-back" href="#/logout"><span class="ms">logout</span><span>${esc(t('ui.sign_out'))}</span></a></aside><div class="adm-main">${html}</div></div>`;
  const pager = (d, go) => `<div class="row adm-pager">${d.page > 1 ? `<button class="ghost" data-pg="${d.page - 1}">‹</button>` : ''}<span class="muted">${d.page}</span>${d.has_more ? `<button class="ghost" data-pg="${d.page + 1}">›</button>` : ''}</div>`;
  async function viewAdminOverview() {
    loading(); try { const d = await api('GET', '/admin/stats');
      h(admWrap('overview', `<h1>${esc(t('ui.admin_overview'))}</h1><div class="adm-stats">${[['group', 'ui.admin_users', d.users], ['person_add', '7d', d.new_users_7d], ['workspace_premium', 'Premium', d.premium],
        ['block', 'Disabled', d.disabled], ['auto_stories', 'ui.admin_kundalis', d.kundalis], ['category', 'ui.categories', d.categories]].map(([ic, l, v]) => `<div class="adm-stat"><span class="ms">${ic}</span><b>${v}</b><small>${esc(l.startsWith('ui.') ? t(l) : l)}</small></div>`).join('')}</div>
        <section class="card" id="aist"><div class="card-h"><span class="ms">smart_toy</span>AI chat (Gemini)</div><p class="muted">Checking…</p></section>
        <section class="card" id="clst"><div class="card-h"><span class="ms">psychology</span>AI Astrologer (Claude)</div><p class="muted">Checking…</p></section>
        <section class="card"><div class="card-h"><span class="ms">schedule</span>Latest users</div><div class="scroll"><table>${d.recent.map(u => `<tr><td><a href="#/users/${u.id}">${esc(u.name)}</a></td><td>${esc(u.email)}</td><td>${esc(u.created_at)}</td></tr>`).join('')}</table></div></section>`));
      api('GET', '/admin/ai-status').then(s => { const el = document.getElementById('aist'); if (el) el.lastElementChild.outerHTML = s.ok
        ? `<p><span class="chip ok">Working</span> Model ${esc(s.model)} answered the test request.</p>`
        : `<p><span class="chip warn">Not working</span> ${s.key_set ? `Key set (${s.key_length} characters), model ${esc(s.model)}.` : ''}</p><p><b>Reason:</b> ${esc(s.error || 'unknown')}</p>`; }).catch(() => {});
      api('GET', '/admin/claude-status').then(s => { const el = document.getElementById('clst'); if (el) el.lastElementChild.outerHTML = s.ok
        ? `<p><span class="chip ok">Working</span> Model ${esc(s.model)} answered the test request.</p>`
        : `<p><span class="chip warn">Not working</span> Model ${esc(s.model)}.</p><p><b>Reason:</b> ${esc(s.error || 'unknown')}</p>`; }).catch(() => {});
    } catch (e) { h(admWrap('overview', errBox(e))); } }
  async function viewAdminUsers(q = '', page = 1) {
    loading(); try { const d = await api('GET', `/admin/users?q=${encodeURIComponent(q)}&page=${page}`);
      h(admWrap('users', `<div class="row" style="justify-content:space-between;align-items:center;margin-bottom:.5rem"><h1 style="margin:0">${esc(t('ui.admin_users'))}</h1><a href="#/users/new" class="tonal" style="text-decoration:none;padding:.4rem .8rem;border-radius:.5rem;font-size:.9rem"><span class="ms" style="font-size:1.1rem;vertical-align:middle">person_add</span> Add User</a></div>
        <form class="adm-search" id="aq"><input type="search" name="q" value="${esc(q)}" placeholder="${esc(t('ui.search'))}: name / email"><button><span class="ms">search</span></button></form>
        <section class="card"><div class="scroll"><table><tr><th>Name</th><th>Email</th><th>Plan</th><th>Kundalis</th><th>Joined</th><th>Status</th></tr>
        ${d.items.map(u => `<tr><td><a href="#/users/${u.id}"><b>${esc(u.name)}</b></a></td><td>${esc(u.email)}</td><td><span class="chip ${u.plan === 'premium' ? 'ok' : ''}">${esc(u.plan)}</span></td><td>${u.kundalis}</td><td>${esc(u.created_at.slice(0, 10))}</td><td>${+u.disabled ? '<span class="chip warn">Disabled</span>' : 'Active'}</td></tr>`).join('')}</table></div>${pager(d)}</section>`));
      document.getElementById('aq').onsubmit = e => { e.preventDefault(); viewAdminUsers(e.target.q.value); };
      $app.querySelectorAll('[data-pg]').forEach(b => b.onclick = () => viewAdminUsers(q, +b.dataset.pg));
    } catch (e) { h(admWrap('users', errBox(e))); } }
  async function viewAdminUser(id) {
    loading(); try { const u = await api('GET', '/admin/users/' + id);
      h(admWrap('users', `<p><a href="#/users">‹ ${esc(t('ui.admin_users'))}</a></p><h1>${esc(u.name)}</h1><p class="muted">${esc(u.email)} · ${esc(u.created_at)}</p>
        <section class="card"><div class="card-h"><span class="ms">workspace_premium</span>Plan & access</div><form class="stack" id="uf"><div class="row">
          <label>Plan<select name="plan"><option value="free"${u.plan === 'free' ? ' selected' : ''}>Free</option><option value="premium"${u.plan === 'premium' ? ' selected' : ''}>Premium</option></select></label>
          <label>Premium until<input type="date" name="plan_expires" value="${esc(u.plan_expires || '')}"></label></div>
          <label class="check"><input type="checkbox" name="disabled"${+u.disabled ? ' checked' : ''}> Disable this account (signs the user out)</label>
          <div class="row"><button>${esc(t('ui.save'))}</button><button type="button" class="ghost danger" id="ud"><span class="ms">delete</span>Delete user</button></div></form></section>
        <section class="card"><div class="card-h" style="justify-content:space-between"><div><span class="ms">auto_stories</span>${esc(t('ui.admin_kundalis'))} (${u.profiles.length})</div>
          <a href="#/users/${id}/kundali" class="tonal" style="text-decoration:none;padding:.3rem .7rem;border-radius:.5rem;font-size:.85rem"><span class="ms" style="font-size:1rem;vertical-align:middle">add</span> Add</a></div>
          <div class="scroll"><table><tr><th>Kundali</th><th>Birth</th><th>Place</th><th></th></tr>
          ${u.profiles.map(p => `<tr><td><b>${esc(p.label)}</b></td><td>${esc(p.birth_date)} ${esc(p.birth_time.slice(0, 5))}</td><td>${esc(p.place_name)}</td>
            <td style="white-space:nowrap"><a class="icon-btn" href="#/kundalis/${p.id}" title="Edit"><span class="ms">edit</span></a>
            <button class="icon-btn" data-dk="${p.id}" aria-label="Delete"><span class="ms">delete</span></button></td></tr>`).join('') || `<tr><td class="muted" colspan="4">${esc(t('ui.none'))}</td></tr>`}
          </table></div></section>`));
      const f = document.getElementById('uf');
      f.onsubmit = async e => { e.preventDefault(); try { await api('PATCH', '/admin/users/' + id, { plan: f.plan.value, plan_expires: f.plan_expires.value, disabled: f.disabled.checked }); toast(t('ui.saved')); } catch (er) { toast(er.message, 'error'); } };
      document.getElementById('ud').onclick = async () => { if (await confirmDialog(`Delete ${u.email} and all their kundalis?`)) { try { await api('DELETE', '/admin/users/' + id); toast(t('ui.deleted'), 'delete'); location.hash = '#/users'; } catch (er) { toast(er.message, 'error'); } } };
      $app.querySelectorAll('[data-dk]').forEach(b => b.onclick = async () => { if (await confirmDialog('Delete this kundali?')) { try { await api('DELETE', '/admin/kundalis/' + b.dataset.dk); viewAdminUser(id); } catch (er) { toast(er.message, 'error'); } } });
    } catch (e) { h(admWrap('users', errBox(e))); } }
  function viewAdminAddUser() {
    h(admWrap('users', `<p><a href="#/users">‹ ${esc(t('ui.admin_users'))}</a></p><h1>Add User</h1>
      <section class="card"><form class="stack" id="nuf">
        <label>${esc(t('ui.name'))}<input name="name" required></label>
        <label>${esc(t('ui.email'))}<input type="email" name="email" required></label>
        <label>${esc(t('ui.password'))}<input type="password" name="password" required minlength="8"></label>
        <label>Plan<select name="plan"><option value="free">Free</option><option value="premium">Premium</option></select></label>
        <div id="uerr"></div><div class="row"><button>Create User</button></div>
      </form></section>`));
    const f = document.getElementById('nuf');
    f.onsubmit = async e => { e.preventDefault(); try {
      const u = await api('POST', '/admin/users', { name: f.name.value, email: f.email.value, password: f.password.value, plan: f.plan.value });
      toast('User created'); location.hash = '#/users/' + u.id;
    } catch (er) { document.getElementById('uerr').innerHTML = errBox(er); } }; }
  async function viewAdminAddKundali(userId) {
    loading(); try { const u = await api('GET', '/admin/users/' + userId);
      h(admWrap('users', `<p><a href="#/users/${userId}">‹ ${esc(u.name)}</a></p><h1>Add Kundali</h1><section class="card">${chartForm()}</section>`));
      bindAdminKundaliForm(userId, null, null);
    } catch (e) { h(admWrap('users', errBox(e))); } }
  async function viewAdminEditKundali(kundaliId) {
    loading(); try { const p = await api('GET', '/admin/kundalis/' + kundaliId);
      h(admWrap('kundalis', `<p><a href="#/users/${p.user_id}">‹ User</a></p><h1>Edit Kundali</h1><section class="card">${chartForm()}</section>`));
      bindAdminKundaliForm(p.user_id, kundaliId, p);
    } catch (e) { h(admWrap('kundalis', errBox(e))); } }
  function bindAdminKundaliForm(userId, kundaliId, edit) {
    const f = document.getElementById('cf'); let place = null, confirmed = null;
    if (edit) {
      for (const k of ['label', 'gender', 'birth_date', 'time_accuracy', 'tzid']) f[k].value = edit[k] ?? '';
      f.birth_time.value = edit.birth_time; f.place_q.value = edit.place_name; place = { lat: edit.lat, lon: edit.lon };
      if (edit.offset_source === 'manual') f.manual_offset_minutes.value = edit.offset_minutes;
    }
    api('GET', '/meta/timezones').then(z => document.getElementById('tzlist').innerHTML = z.map(x => `<option value="${esc(x)}">`).join('')).catch(() => {});
    placePicker(f, p => { place = p; if (p.tzid) f.tzid.value = p.tzid; reset(); });
    const reset = () => { confirmed = null; document.getElementById('resolved').innerHTML = ''; f.check.textContent = t('ui.check_details'); };
    f.querySelectorAll('input,select').forEach(i => i.addEventListener('change', () => { if (i.name !== 'fold') reset(); }));
    const body = () => ({
      label: f.label.value, gender: f.gender.value || null, birth_date: f.birth_date.value, birth_time: f.birth_time.value,
      time_accuracy: f.time_accuracy.value, place_name: f.place_q.value, lat: place?.lat, lon: place?.lon, tzid: f.tzid.value,
      manual_offset_minutes: f.manual_offset_minutes.value === '' ? null : parseInt(f.manual_offset_minutes.value, 10),
      dst_fold: f.querySelector('[name=fold]:checked')?.value || null,
    });
    f.onsubmit = async e => {
      e.preventDefault(); const err = document.getElementById('cerr'); err.innerHTML = '';
      if (!place) { err.innerHTML = errBox({ message: t('ui.search_place') }); return; }
      try {
        if (!confirmed) {
          const r = await api('POST', '/birth/resolve', body()); confirmed = r;
          document.getElementById('resolved').innerHTML = `<section class="calc"><strong>${esc(t('ui.resolved_as'))}</strong>
            <table><tr><th>${esc(t('ui.utc'))}</th><td>${esc(r.utc)}</td></tr>
            <tr><th>${esc(t('ui.offset'))}</th><td>${r.offset_minutes >= 0 ? '+' : '−'}${String(Math.floor(Math.abs(r.offset_minutes) / 60)).padStart(2, '0')}:${String(Math.abs(r.offset_minutes) % 60).padStart(2, '0')} (${esc(r.offset_source)})</td></tr>
            <tr><th>${esc(t('ui.location'))}</th><td>${place.lat}, ${place.lon}</td></tr></table>
            ${r.warnings.map(w => `<div class="warn">${esc(w.message)}</div>`).join('')}</section>`;
          f.check.textContent = t('ui.confirm_save');
        } else {
          await api(kundaliId ? 'PUT' : 'POST', kundaliId ? '/admin/kundalis/' + kundaliId : '/admin/users/' + userId + '/kundalis', { ...body(), confirmed: true });
          toast(t('ui.saved')); location.hash = '#/users/' + userId;
        }
      } catch (e2) {
        if (e2.code === 'ambiguous_local_time') {
          document.getElementById('fold').innerHTML = `<fieldset><legend>${esc(t('ui.dst_choose'))}</legend>
            <label><input type="radio" name="fold" value="earlier" required> ${esc(t('ui.earlier'))}</label>
            <label><input type="radio" name="fold" value="later"> ${esc(t('ui.later'))}</label></fieldset>`;
        }
        err.innerHTML = errBox(e2);
      }
    };
  }
  async function viewAdminKundalis(q = '', page = 1) {
    loading(); try { const d = await api('GET', `/admin/kundalis?q=${encodeURIComponent(q)}&page=${page}`);
      h(admWrap('kundalis', `<h1>${esc(t('ui.admin_kundalis'))}</h1><form class="adm-search" id="aq"><input type="search" name="q" value="${esc(q)}" placeholder="${esc(t('ui.search'))}: name / place / email"><button><span class="ms">search</span></button></form>
        <section class="card"><div class="scroll"><table><tr><th>Kundali</th><th>Birth</th><th>Place</th><th>Owner</th><th></th></tr>
        ${d.items.map(k => `<tr><td><b>${esc(k.label)}</b></td><td>${esc(k.birth_date)} ${esc(k.birth_time.slice(0, 5))}</td><td>${esc(k.place_name.split(',')[0])}</td><td><a href="#/users/${k.user_id}">${esc(k.user_name)}</a><br><small class="muted">${esc(k.email)}</small></td>
          <td><button class="icon-btn" data-d="${k.id}" aria-label="Delete"><span class="ms">delete</span></button></td></tr>`).join('')}</table></div>${pager(d)}</section>`));
      document.getElementById('aq').onsubmit = e => { e.preventDefault(); viewAdminKundalis(e.target.q.value); };
      $app.querySelectorAll('[data-pg]').forEach(b => b.onclick = () => viewAdminKundalis(q, +b.dataset.pg));
      $app.querySelectorAll('[data-d]').forEach(b => b.onclick = async () => { if (await confirmDialog('Delete this kundali?')) { await api('DELETE', '/admin/kundalis/' + b.dataset.d); viewAdminKundalis(q, page); } });
    } catch (e) { h(admWrap('kundalis', errBox(e))); } }
  function viewAdminAuth(mode = 'login') {
    const reg = mode === 'register';
    h(`<section class="adm-auth"><p class="adm-brand"><span class="ms">admin_panel_settings</span>${esc(t('ui.app_name'))} · ${esc(t('ui.admin'))}</p>
      <h1>${esc(reg ? t('ui.admin_register') : t('ui.admin_login'))}</h1>${reg ? `<p class="muted">${esc(t('ui.admin_reg_hint'))}</p>` : ''}
      <form class="stack" id="aaf">${reg ? `<label>${esc(t('ui.name'))}<input name="name" required autocomplete="name"></label>` : ''}
        <label>${esc(t('ui.email'))}<input type="email" name="email" required autocomplete="email"></label>
        <label>${esc(t('ui.password'))}<input type="password" name="password" minlength="${reg ? 10 : 1}" required autocomplete="${reg ? 'new-password' : 'current-password'}"></label>
        <div id="aerr"></div><button>${esc(reg ? t('ui.register') : t('ui.sign_in'))}</button>
        <a href="#/${reg ? '' : 'register'}">${esc(reg ? t('ui.have_account') : t('ui.admin_new'))}</a></form></section>`);
    const f = document.getElementById('aaf');
    f.onsubmit = async e => { e.preventDefault(); const b = f.querySelector('button'); b.disabled = true;
      try { const body = { email: f.email.value, password: f.password.value }; if (reg) body.name = f.name.value;
        const d = await api('POST', reg ? '/admin-auth/register' : '/admin-auth/login', body);
        if (d.pending) { f.outerHTML = `<section class="card"><span class="ms">hourglass_top</span> ${esc(t('ui.admin_pending'))}</section><p><a href="#/">${esc(t('ui.back_to_login'))}</a></p>`; return; }
        setToken(d.token); location.hash = '#/overview'; route();
      } catch (err) { document.getElementById('aerr').innerHTML = errBox(err); b.disabled = false; } };
  }
  async function viewAdminAdmins() {
    loading(); try { const [rows, me] = await Promise.all([api('GET', '/admin/admins'), api('GET', '/admin-auth/me')]);
      h(admWrap('admins', `<h1>${esc(t('ui.admin_admins'))}</h1><p class="muted">${esc(t('ui.admin_admins_hint'))}</p><section class="card"><div class="scroll"><table><tr><th>Name</th><th>Email</th><th>Status</th><th></th></tr>
        ${rows.map(a => `<tr><td><b>${esc(a.name)}</b></td><td>${esc(a.email)}</td><td><span class="chip ${a.status === 'active' ? 'ok' : 'warn'}">${esc(a.status)}</span></td><td>${a.id === me.id ? '<span class="muted">you</span>' :
          `${a.status !== 'active' ? `<button class="tonal" data-st="${a.id}" data-v="active"><span class="ms">check</span>Approve</button>` : `<button class="ghost" data-st="${a.id}" data-v="disabled">Disable</button>`}
           <button class="icon-btn" data-del="${a.id}" aria-label="Delete"><span class="ms">delete</span></button>`}</td></tr>`).join('')}</table></div></section>`));
      $app.querySelectorAll('[data-st]').forEach(b => b.onclick = async () => { try { await api('PATCH', '/admin/admins/' + b.dataset.st, { status: b.dataset.v }); viewAdminAdmins(); } catch (e) { toast(e.message, 'error'); } });
      $app.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => { if (await confirmDialog('Delete this admin?')) { try { await api('DELETE', '/admin/admins/' + b.dataset.del); viewAdminAdmins(); } catch (e) { toast(e.message, 'error'); } } });
    } catch (e) { h(admWrap('admins', errBox(e))); } }
  function viewAdminPlans() {
    h(admWrap('plans', `<h1>${esc(t('ui.admin_plans'))}</h1><section class="card"><p>Every user now has a <b>Free</b> or <b>Premium</b> plan with an optional end date. You can change it from <a href="#/users">${esc(t('ui.admin_users'))}</a>.</p>
      <p class="muted">Online payments (Razorpay / Stripe) and choosing which features are premium will be added here next.</p></section>`)); }
  async function viewAdminCats(edit = null) {
    loading();
    try {
      const rows = await api('GET', '/admin/categories'), e = edit || {};
      const LG = [['en', 'English'], ['hi', 'हिन्दी'], ['gu', 'ગુજરાતી']];
      h(admWrap('categories', `<section class="hero-band"><div><p class="eyebrow">Admin</p><h1>${esc(t('ui.categories'))}</h1></div><span class="ms hero-ic">category</span></section>${adminTabs('categories')}
        <section class="card"><div class="card-h"><span class="ms">${e.id ? 'edit' : 'add_circle'}</span>${e.id ? 'Edit category' : 'Add a category'}</div>
          <p class="muted">Type the category name and simple advice in each language. Write one point per line. The astrology behind it is chosen automatically from the English name.</p>
          <form id="cf2" class="stack"><div class="seg" id="lgs">${LG.map(([l, n], i) => `<button type="button" data-lg="${l}" class="${i ? '' : 'on'}">${n}</button>`).join('')}</div>
          ${LG.map(([l, n], i) => `<div class="stack" data-pane="${l}"${i ? ' hidden' : ''}>
            <label>Category name (${n})<input name="name_${l}" maxlength="80"${l === 'en' ? ' required' : ''} value="${esc(e['name_' + l] || '')}" placeholder="${{ en: 'e.g. Property', hi: 'जैसे: संपत्ति', gu: 'દા.ત. મિલકત' }[l]}"></label>
            <label>What to do — one per line<textarea name="dos_${l}" rows="3">${esc(e['dos_' + l] || '')}</textarea></label>
            <label>What to avoid — one per line<textarea name="donts_${l}" rows="3">${esc(e['donts_' + l] || '')}</textarea></label>
            <label>Upay (simple remedies) — one per line<textarea name="upay_${l}" rows="3">${esc(e['upay_' + l] || '')}</textarea></label></div>`).join('')}
          <label class="check"><input type="checkbox" name="active"${e.id === undefined || +e.active ? ' checked' : ''}> Show to users</label>
          <div class="row"><button>${e.id ? 'Save changes' : 'Add category'}</button>${e.id ? '<button type="button" class="ghost" id="cc">Cancel</button>' : ''}</div></form></section>
        <section class="card"><div class="scroll"><table><tr><th></th><th>Category</th><th>Languages</th><th>Tips</th><th>Shown</th><th></th></tr>
          ${rows.map(r => `<tr><td><span class="ms">${esc(r.icon)}</span></td><td><b>${esc(r.name_en)}</b><br><small class="muted">${esc([r.name_hi, r.name_gu].filter(Boolean).join(' · '))}</small></td>
            <td>${LG.map(([l]) => r['name_' + l] ? l.toUpperCase() : '').filter(Boolean).join(' ')}</td><td>${['dos_en', 'donts_en', 'upay_en'].filter(k => r[k]).length}/3</td><td>${+r.active ? '✓' : '—'}</td>
            <td><button class="icon-btn" data-e="${r.id}" aria-label="Edit"><span class="ms">edit</span></button><button class="icon-btn" data-d="${r.id}" aria-label="Delete"><span class="ms">delete</span></button></td></tr>`).join('')}</table></div></section>`));
      $app.querySelectorAll('[data-lg]').forEach(b => b.onclick = () => { $app.querySelectorAll('[data-lg]').forEach(x => x.classList.toggle('on', x === b));
        $app.querySelectorAll('[data-pane]').forEach(p => p.hidden = p.dataset.pane !== b.dataset.lg); });
      const f = document.getElementById('cf2');
      f.onsubmit = async ev => { ev.preventDefault(); const b = Object.fromEntries(new FormData(f)); b.active = f.active.checked;
        if (!b.name_en.trim()) { $app.querySelector('[data-lg="en"]').click(); f.name_en.focus(); return; }
        try { await api(e.id ? 'PUT' : 'POST', '/admin/categories' + (e.id ? '/' + e.id : ''), b); toast(t('ui.saved')); viewAdminCats(); } catch (er) { toast(er.message, 'error'); } };
      document.getElementById('cc')?.addEventListener('click', () => viewAdminCats());
      $app.querySelectorAll('[data-e]').forEach(b => b.onclick = () => viewAdminCats(rows.find(r => r.id == b.dataset.e)));
      $app.querySelectorAll('[data-d]').forEach(b => b.onclick = async () => { if (await confirmDialog('Delete this category? Users who saved it will lose it.')) { await api('DELETE', '/admin/categories/' + b.dataset.d); viewAdminCats(); } });
    } catch (e) { h(errBox(e)); }
  }
  async function viewAdmin(edit = null) {
    loading();
    try {
      const rows = await api('GET', '/admin/remedies'), e = edit || {};
      const PL = ['', 'Sun', 'Moon', 'Mars', 'Mercury', 'Jupiter', 'Venus', 'Saturn', 'Rahu', 'Ketu'], DS = ['', 'mangal', 'kaal_sarp', 'sade_sati', 'dhaiya', 'grahan', 'guru_chandal', 'kemadruma', 'pitra'];
      const sel = (n, opts, v) => `<select name="${n}">${opts.map(o => `<option value="${o}" ${String(v ?? '') === String(o) ? 'selected' : ''}>${o || '—'}</option>`).join('')}</select>`;
      h(admWrap('remedies', `<section class="hero-band"><div><p class="eyebrow">Admin</p><h1>${esc(t('ui.remedy_rules'))}</h1></div></section>${adminTabs('remedies')}
        <section class="card"><form id="rf" class="stack"><div class="row"><label>Planet${sel('planet', PL, e.planet)}</label><label>House${sel('house', ['', 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], e.house)}</label></div>
          <div class="row"><label>Dosha${sel('dosha', DS, e.dosha)}</label><label>Condition${sel('cond', ['any', 'weak', 'strong', 'malefic', 'benefic'], e.cond || 'any')}</label></div>
          <div class="row"><label>Priority<input name="priority" type="number" value="${e.priority ?? 50}"></label><label>Status${sel('status', ['draft', 'approved'], e.status || 'draft')}</label></div>
          <label>Source (book, chapter / astrologer)<input name="source" value="${esc(e.source || '')}" required></label>
          <label>English (one step per line)<textarea name="text_en" rows="4" required>${esc(e.text_en || '')}</textarea></label>
          <label>हिन्दी<textarea name="text_hi" rows="4">${esc(e.text_hi || '')}</textarea></label><label>ગુજરાતી<textarea name="text_gu" rows="4">${esc(e.text_gu || '')}</textarea></label>
          <div class="row"><button>${e.id ? 'Update' : 'Add'}</button>${e.id ? '<button type="button" class="ghost" id="rc">Cancel</button>' : ''}</div></form></section>
        <section class="card"><div class="scroll"><table><tr><th>Planet</th><th>House</th><th>Dosha</th><th>Cond</th><th>Source</th><th>Status</th><th></th></tr>
          ${rows.map(r => `<tr><td>${esc(r.planet || '—')}</td><td>${r.house ?? '—'}</td><td>${esc(r.dosha || '—')}</td><td>${esc(r.cond)}</td><td>${esc(r.source)}</td><td>${esc(r.status)}</td>
            <td><button class="icon-btn" data-e="${r.id}"><span class="ms">edit</span></button><button class="icon-btn" data-d="${r.id}"><span class="ms">delete</span></button></td></tr>`).join('')}</table></div></section>`));
      const f = document.getElementById('rf');
      f.onsubmit = async ev => { ev.preventDefault(); const b = Object.fromEntries(new FormData(f));
        try { await api(e.id ? 'PUT' : 'POST', '/admin/remedies' + (e.id ? '/' + e.id : ''), b); toast(t('ui.saved')); viewAdmin(); } catch (er) { toast(er.message, 'error'); } };
      document.getElementById('rc')?.addEventListener('click', () => viewAdmin());
      $app.querySelectorAll('[data-e]').forEach(b => b.onclick = () => viewAdmin(rows.find(r => r.id == b.dataset.e)));
      $app.querySelectorAll('[data-d]').forEach(b => b.onclick = async () => { if (await confirmDialog('Delete?')) { await api('DELETE', '/admin/remedies/' + b.dataset.d); viewAdmin(); } });
    } catch (e) { h(errBox(e)); }
  }

  // ---------- Dashboard ----------
  async function viewDashboard() {
    loading();
    try {
      const place = JSON.parse(localStorage.getItem('place') || 'null') || { name: 'Ahmedabad, Gujarat, India', lat: 23.0225, lon: 72.5714, tzid: 'Asia/Kolkata' };
      const [me, list] = await Promise.all([api('GET', '/me'), api('GET', '/profiles')]);
      const dateTxt = new Date().toLocaleDateString(lang === 'en' ? 'en-IN' : lang + '-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
      const acts = [['add', 'person_add', 'ui.add_chart'], ['predict', 'auto_awesome', 'ui.personal_predictions'], ['ai-chat', 'psychology', 'ui.nav_claude'], ['rashifal', 'stars', 'rf.title']];
      h(`${ph('waving_hand', `${t('ui.welcome')}, ${me.name}`, dateTxt, `<a class="btn" href="#/add"><span class="ms">add</span>${esc(t('ui.new_kundali'))}</a>`)}
        <div class="m-sec"><h2>${esc(t('ui.my_charts'))} <span class="muted">(${list.length})</span></h2></div>
        <div class="db-list">${list.map(p => `<article class="db-k"><div class="db-k-h"><span class="avatar">${esc(p.label.trim().charAt(0).toUpperCase())}</span><div><h3>${esc(p.label)}</h3>
            <small>${esc(p.place_name.split(',')[0])}</small></div></div>
            <div class="db-k-m"><span class="chip"><span class="ms">event</span>${esc(p.birth_date)}</span><span class="chip"><span class="ms">schedule</span>${esc(p.birth_time.slice(0, 5))}</span></div>
            <div class="db-k-a"><a class="btn" href="#/chart/${p.id}">${esc(t('ui.open'))}</a><a class="btn tonal" href="#/print/${p.id}"><span class="ms">download</span>PDF</a>
              <a class="icon-btn" href="#/edit/${p.id}" aria-label="${esc(t('ui.edit_kundali'))}"><span class="ms">edit</span></a><button class="icon-btn" data-del="${p.id}" aria-label="${esc(t('ui.delete'))}"><span class="ms">delete</span></button></div></article>`).join('')}
          <a class="db-new" href="#/add"><span class="ms">add_circle</span>${esc(t('ui.new_kundali'))}</a></div>`);
      $app.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
        if (!(await confirmDialog(t('ui.confirm_delete')))) return;
        try { await api('DELETE', '/profiles/' + b.dataset.del); toast(t('ui.deleted'), 'delete'); viewDashboard(); } catch (e) { toast(e.message, 'error'); } });
      apiRaw('GET', `/panchang?date=${today()}&lat=${place.lat}&lon=${place.lon}&tzid=${encodeURIComponent(place.tzid)}`).then(pc => {
        const el = document.getElementById('dbpc'); if (!el) return; const tm = x => esc((x || '').slice(11));
        el.innerHTML = `<div class="db-pc"><div class="db-kv"><small>${esc(t('ui.tithi'))}</small><b>${esc(tn('paksha', pc.tithi[0].paksha) + ' ' + tn('tithis', pc.tithi[0].name))}</b></div>
          <div class="db-kv"><small>${esc(t('ui.nakshatra'))}</small><b>${esc(tn('nakshatras', pc.nakshatra[0].name))}</b></div>
          <div class="db-kv"><small>${esc(t('ui.sunrise'))} / ${esc(t('ui.sunset'))}</small><b>${tm(pc.sunrise)} · ${tm(pc.sunset)}</b></div>
          <div class="db-kv"><small>${esc(t('ui.rahu_kaal'))}</small><b>${tm(pc.rahu_kaal.start)} – ${tm(pc.rahu_kaal.end)}</b></div></div>`;
      }).catch(() => { const el = document.getElementById('dbpc'); if (el) el.innerHTML = `<p class="muted">—</p>`; });
    } catch (e) { h(errBox(e)); }
  }

  // ---------- Rashifal: all 12 Moon signs, daily / weekly / monthly (public) ----------
  async function viewRashifal(period) {
    const P = ['daily', 'weekly', 'monthly'], names = t('rf.tabs').split('|');
    const IC = { career: 'work', money: 'payments', love: 'favorite', health: 'self_improvement', moon: 'dark_mode', caution: 'warning' };
    let sign = (() => { try { return +(localStorage.getItem('rf_sign') || 0); } catch (e) { return 0; } })() % 12;
    h(`${ph('stars', names[P.indexOf(period)] + ' ' + t('rf.title'), t('rf.sub'), `<div class="rf-seg" role="tablist">${P.map((x, i) => `<button role="tab" data-rp="${x}" class="${x === period ? 'on' : ''}" aria-selected="${x === period}">${esc(names[i])}</button>`).join('')}</div>`)}
      <div class="rz"><aside class="rz-wheel"><div id="rzw"></div><p class="rz-hint"><span class="ms">touch_app</span>${esc(t('rf.pick'))}</p></aside><div id="rfo" class="rz-read"></div></div>`);
    $app.querySelectorAll('[data-rp]').forEach(b => b.onclick = () => { location.hash = '#/rashifal/' + b.dataset.rp; });
    const out = document.getElementById('rfo'), wh = document.getElementById('rzw'); out.innerHTML = skel();
    let d; try { d = await api('GET', `/rashifal?period=${period}&lang=${lang}`); } catch (e) { out.innerHTML = errBox(e); return; }
    const range = d.from === d.to ? d.from : d.from + ' – ' + d.to;
    // zodiac wheel: 12 clickable sectors, Aries at the top
    const C = 200, R1 = 192, R0 = 84, pt = (r, deg) => [(C + r * Math.cos(deg * Math.PI / 180)).toFixed(2), (C + r * Math.sin(deg * Math.PI / 180)).toFixed(2)];
    const sector = i => { const a0 = -105 + i * 30, a1 = a0 + 30, [x1, y1] = pt(R1, a0), [x2, y2] = pt(R1, a1), [x3, y3] = pt(R0, a1), [x4, y4] = pt(R0, a0);
      return `M${x1} ${y1} A${R1} ${R1} 0 0 1 ${x2} ${y2} L${x3} ${y3} A${R0} ${R0} 0 0 0 ${x4} ${y4}Z`; };
    wh.innerHTML = `<svg class="rz-svg" viewBox="0 0 400 400" role="tablist" aria-label="${esc(t('rf.pick'))}">
      <circle cx="200" cy="200" r="198" class="rz-rim"/>
      ${d.rashis.map((r, i) => { const [gx, gy] = pt(150, -90 + i * 30), [nx, ny] = pt(110, -90 + i * 30), [dx, dy] = pt(181, -90 + i * 30);
        return `<g class="rz-s t-${r.overall}" data-sg="${i}" role="tab" tabindex="0" aria-label="${esc(r.name)}"><path d="${sector(i)}"/>
          <text x="${gx}" y="${gy}" class="rz-g">${GLYPH[i]}</text><text x="${nx}" y="${ny}" class="rz-n">${esc(r.name.length > 7 ? r.name.slice(0, 6) + '.' : r.name)}</text><circle cx="${dx}" cy="${dy}" r="4" class="rz-d"/></g>`; }).join('')}
      <circle cx="200" cy="200" r="${R0 - 6}" class="rz-core"/><g id="rzc"></g></svg>`;
    const strip = x => x.replace(/^[^:：]{1,24}[:：]\s*/, '');
    const badge = tone => `<span class="rf-badge t-${tone}">${esc(t('rf.tone.' + tone))}</span>`;
    const meter = tone => `<span class="rz-m t-${tone}" aria-hidden="true">${[0, 1, 2].map(k => `<i class="${k < ({ good: 3, mixed: 2, bad: 1 }[tone]) ? 'on' : ''}"></i>`).join('')}</span>`;
    const show = () => {
      const r = d.rashis[sign], L = a => r.lines.find(l => l.area === a);
      wh.querySelectorAll('.rz-s').forEach(g => { const on = +g.dataset.sg === sign; g.classList.toggle('on', on); g.setAttribute('aria-selected', on); });
      document.getElementById('rzc').innerHTML = `<text x="200" y="192" class="rz-cg">${GLYPH[sign]}</text><text x="200" y="228" class="rz-cn">${esc(r.name)}</text><text x="200" y="250" class="rz-cs">${'★'.repeat(r.score)}${'☆'.repeat(5 - r.score)}</text>`;
      const notes = r.lines.filter(l => l.area === 'moon' || l.area === 'caution');
      out.innerHTML = `<article class="rz-card t-${r.overall}">
        <header class="rz-h"><div><h2>${esc(r.name)} <span>${esc(names[P.indexOf(period)])}</span></h2><p><span class="ms">event</span>${esc(range)}</p></div>
          <div class="rz-hr"><span class="rf-stars" aria-label="${r.score}/5">${'★'.repeat(r.score)}<s>${'★'.repeat(5 - r.score)}</s></span>${badge(r.overall)}</div></header>
        <blockquote class="rz-q">${esc(L('overall').text)}</blockquote>
        ${notes.map(n => `<div class="rf-note t-${n.tone}"><span class="ms">${IC[n.area]}</span><p>${esc(n.text)}</p></div>`).join('')}
        <div class="rz-areas">${['career', 'money', 'love', 'health'].map(a => { const l = L(a); return `<section class="rz-a t-${l.tone}">
          <span class="rz-ai"><span class="ms">${IC[a]}</span></span><div><div class="rz-at"><b>${esc(t('rf.lbl.' + a))}</b>${meter(l.tone)}<small>${esc(t('rf.tone.' + l.tone))}</small></div><p>${esc(strip(l.text))}</p></div></section>`; }).join('')}</div>
        ${r.lucky && r.remedy ? `<div class="rz-foot"><section class="rz-lucky"><h3><span class="ms">auto_awesome</span>${esc(t('rf.lbl.lucky'))}</h3><div class="rz-lk">
            <div><span class="rz-med" style="background:${r.lucky.hex}"></span><small>${esc(t('rf.l_color'))}</small><b>${esc(r.lucky.color)}</b></div>
            <div><span class="rz-med n">${r.lucky.num}</span><small>${esc(t('rf.l_num'))}</small><b>${r.lucky.num}</b></div>
            <div><span class="rz-med n"><span class="ms">event</span></span><small>${esc(t('rf.l_day'))}</small><b>${esc(r.lucky.day)}</b></div></div></section>
          <section class="rz-rem"><h3><span class="ms">spa</span>${esc(t('rf.lbl.remedy'))}</h3><p class="rz-mantra">${esc(r.remedy.mantra)}</p><small>${esc(t('rf.chant').replace('{planet}', r.remedy.planet))}</small></section></div>` : ''}
      </article>`;
    };
    wh.querySelectorAll('.rz-s').forEach(g => g.onclick = g.onkeydown = e => { if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return; e.preventDefault?.();
      sign = +g.dataset.sg; try { localStorage.setItem('rf_sign', sign); } catch (er) {} show(); if (matchMedia('(max-width: 960px)').matches) out.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
    show();
  }


  // ---------- Home: animated zodiac wheel, features, rashis ----------
  const SIGNS = ['Aries', 'Taurus', 'Gemini', 'Cancer', 'Leo', 'Virgo', 'Libra', 'Scorpio', 'Sagittarius', 'Capricorn', 'Aquarius', 'Pisces'];
  const GLYPH = ['♈', '♉', '♊', '♋', '♌', '♍', '♎', '♏', '♐', '♑', '♒', '♓'].map(g => g + '︎');
  function zodiacWheel() {
    const c = 200, P = (r, deg) => [(c + r * Math.cos(deg * Math.PI / 180)).toFixed(1), (c + r * Math.sin(deg * Math.PI / 180)).toFixed(1)];
    let seg = '', gl = '', dots = '';
    for (let i = 0; i < 12; i++) {
      const [x1, y1] = P(128, i * 30 - 90), [x2, y2] = P(184, i * 30 - 90), [gx, gy] = P(156, i * 30 - 75);
      seg += `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}"/>`;
      gl += `<text x="${gx}" y="${gy}">${GLYPH[i]}</text>`;
      const [dx, dy] = P(108, i * 30 - 75); dots += `<circle cx="${dx}" cy="${dy}" r="${i % 3 ? 2 : 3.2}"/>`;
    }
    return `<div class="hm-art" aria-hidden="true"><svg viewBox="0 0 400 400">
      <circle cx="200" cy="200" r="196" fill="#fff" stroke="#dee0ff" stroke-width="2"/>
      <g class="ring" fill="none" stroke="#4355b9" stroke-opacity=".55"><circle cx="200" cy="200" r="184" stroke-width="1.5"/><circle cx="200" cy="200" r="128" stroke-width="1.2"/><g stroke-width="1">${seg}</g>
        <g fill="#4355b9" stroke="none" font-size="24" text-anchor="middle" dominant-baseline="central" font-family="'Segoe UI Symbol','Noto Sans Symbols 2','DejaVu Sans',sans-serif">${gl}</g></g>
      <g class="ring2"><circle cx="200" cy="200" r="108" fill="#eef0ff"/><g fill="#8b95e6">${dots}</g></g>
      <circle cx="200" cy="200" r="64" fill="#f4a62a"/><circle cx="200" cy="200" r="64" fill="none" stroke="#ffdcbe" stroke-width="10" stroke-opacity=".7"/>
      <path d="M200 160l8.5 27 28.5-.5-23 16.5 9 27-23-16.5-23 16.5 9-27-23-16.5 28.5.5z" fill="#fff"/>
    </svg><div class="orb"><i></i></div><div class="orb o2"><i></i></div></div>`;
  }
  // hide the bottom bar while the on-screen keyboard is up
  (() => { const isField = el => el && /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) && !['checkbox', 'radio', 'button', 'submit'].includes(el.type);
    const upd = () => { if (window.visualViewport) document.documentElement.style.setProperty('--vvh', visualViewport.height + 'px');
      document.body.classList.toggle('kb-open', isField(document.activeElement) || !!(window.visualViewport && visualViewport.height < innerHeight * 0.75)); };
    document.addEventListener('focusin', upd); document.addEventListener('focusout', () => setTimeout(upd, 60));
    window.visualViewport?.addEventListener('resize', upd); })();
  // create a kundali straight from the quick form data (place already resolved)
  const quickCreate = d => api('POST', '/profiles', { label: d.label, gender: null, birth_date: d.birth_date, birth_time: d.birth_time, time_accuracy: 'exact',
    place_name: d.place_q, lat: d.place.lat, lon: d.place.lon, tzid: d.place.tzid || 'Asia/Kolkata', manual_offset_minutes: null, dst_fold: null, confirmed: true });
  function viewHome() {
    const T = k => esc(t('home.' + k)), need = r => !token && ['add', 'predict', 'ai-chat', 'dashboard'].includes(r) ? 'register' : r;
    const svc = [['add', 'auto_stories', t('home.f_kundali')], ['rashifal', 'stars', t('rf.title')], ['panchang', 'calendar_month', t('ui.panchang')], ['predict', 'auto_awesome', t('home.f_predict')],
      ['ai-chat', 'psychology', t('home.f_ai')], ['dashboard', 'spa', t('home.f_remedy')], ['dashboard', 'temple_hindu', t('ui.pooja')], ['panchang', 'schedule', t('ui.choghadiya')]];
    h(`<div class="th">
      <section class="th-hero"><div class="th-copy"><span class="th-om">ॐ</span><span class="ph-e">${T('eyebrow')}</span><h1>${T('h1a')} <em>${T('h1b')}</em></h1><p>${T('sub')}</p>
          <div class="th-trust"><span><span class="ms">verified</span>${T('t1')}</span><span><span class="ms">spa</span>${T('t2')}</span><span><span class="ms">translate</span>${T('t3')}</span></div></div>
        <form class="th-form" id="qk"><h2><span class="ms">auto_stories</span>${T('qk_title')}</h2><p>${T('qk_sub')}</p>
          <label>${esc(t('ui.name'))}<input name="label" required maxlength="120" autocomplete="name"></label>
          <div class="th-row"><label>${esc(t('ui.birth_date'))}<input type="date" name="birth_date" required></label><label>${esc(t('ui.birth_time'))}<input type="time" name="birth_time" required></label></div>
          <label class="th-place-l">${esc(t('ui.birth_place'))}<input name="place_q" required placeholder="${esc(t('ui.search_place'))}" autocomplete="off"><div class="suggest"></div></label>
          <div id="qkerr"></div><button><span class="ms">auto_awesome</span>${T('qk_btn')}</button></form></section>
      <section class="th-svc">${svc.map(([r, ic, l], i) => `<a class="th-s c${i}" href="#/${need(r)}"><span class="ms">${ic}</span><b>${esc(l)}</b></a>`).join('')}</section>
      <div class="th-two">
        <section class="card th-pc"><div class="card-h"><span class="ms">calendar_month</span>${T('pc_title')}</div><div id="thpc"><div class="skel"><span></span><span></span></div></div>
          <a class="btn tonal" href="#/panchang">${esc(t('ui.panchang'))} <span class="ms">arrow_forward</span></a></section>
        <section class="card th-hs"><div class="card-h"><span class="ms">stars</span>${T('hs_title')}</div>
          <div class="th-signs">${SIGNS.map((sg, i) => `<a href="#/rashifal/daily" data-sign="${i}"><span class="g">${GLYPH[i]}</span><small>${esc(t('astro.signs.' + sg))}</small></a>`).join('')}</div></section>
      </div>
      <div class="m-sec"><h2>${T('how_h')}</h2></div>
      <div class="hm-steps">${[['edit_calendar', 's1'], ['travel_explore', 's2'], ['task_alt', 's3']].map(([ic, k], i) => `<div class="hm-step"><span class="n"><span class="ms">${ic}</span></span><b>${i + 1}. ${T(k)}</b><p>${T(k + 'p')}</p></div>`).join('')}</div>
      <section class="hm-band"><div><h3>${T('band_h')}</h3><p>${T('band_p')}</p></div><a class="btn" href="#/${token ? 'ai-chat' : 'register'}"><span class="ms">psychology</span>${T('band_b')}</a></section></div>`);
    $app.querySelectorAll('[data-sign]').forEach(el => el.onclick = () => { try { localStorage.setItem('rf_sign', el.dataset.sign); } catch (e) {} });
    const f = document.getElementById('qk');
    let qp = null; placePicker(f, p => qp = p); f.place_q.addEventListener('input', () => qp = null);
    f.onsubmit = async e => { e.preventDefault(); const err = document.getElementById('qkerr'); err.innerHTML = '';
      if (!qp) { err.innerHTML = errBox({ message: t('ui.search_place') }); f.place_q.focus(); return; }
      const d = { label: f.label.value, birth_date: f.birth_date.value, birth_time: f.birth_time.value, place_q: f.place_q.value, place: qp };
      if (!token) { try { sessionStorage.setItem('kdraft', JSON.stringify(d)); } catch (er) {} location.hash = '#/register'; return; }
      const btn = f.querySelector('button:last-child'); btn.disabled = true;
      try { const p = await quickCreate(d); location.hash = '#/chart/' + p.id; } catch (e2) { err.innerHTML = errBox(e2); btn.disabled = false; } };
    const place = JSON.parse(localStorage.getItem('place') || 'null') || { name: 'Ahmedabad, Gujarat, India', lat: 23.0225, lon: 72.5714, tzid: 'Asia/Kolkata' };
    apiRaw('GET', `/panchang?date=${today()}&lat=${place.lat}&lon=${place.lon}&tzid=${encodeURIComponent(place.tzid)}`).then(p => {
      const el = document.getElementById('thpc'); if (!el) return; const tm = x => esc((x || '').slice(11));
      const row = (ic, l, v) => `<div class="th-r"><span class="ms">${ic}</span><small>${esc(l)}</small><b>${v}</b></div>`;
      el.innerHTML = `<p class="th-place"><span class="ms">location_on</span>${esc(place.name.split(',')[0])} · ${esc(tn('weekdays', p.vara.name))}</p><div class="th-rows">
        ${row('brightness_4', t('ui.tithi'), esc(tn('paksha', p.tithi[0].paksha) + ' ' + tn('tithis', p.tithi[0].name)))}${row('stars', t('ui.nakshatra'), esc(tn('nakshatras', p.nakshatra[0].name)))}
        ${row('join', t('ui.yoga'), esc(tn('yogas', p.yoga[0].name)))}${row('wb_twilight', t('ui.sunrise') + ' / ' + t('ui.sunset'), `${tm(p.sunrise)} · ${tm(p.sunset)}`)}
        ${row('block', t('ui.rahu_kaal'), `${tm(p.rahu_kaal.start)} – ${tm(p.rahu_kaal.end)}`)}${row('dark_mode', t('ui.moon_sign'), esc(tn('signs', p.moon_sign)))}</div>`;
    }).catch(() => { const el = document.getElementById('thpc'); if (el) el.innerHTML = '<p class="muted">—</p>'; });
  }

  // ---------- Router ----------
  const BASE_TITLE = document.title;
  async function route() {
    document.title = BASE_TITLE;
    if (window.ADMIN_APP) return adminRoute();
    renderNav();
    const [, page, arg] = location.hash.split('/');
    if (page === 'logout') { try { await api('POST', '/auth/logout'); } catch (e) {} setToken(null); location.hash = '#/panchang'; return; }
    if (['charts', 'chart', 'print', 'dashboard', 'add', 'edit', 'profile', 'predict', 'chat', 'ai-chat', 'milan'].includes(page) && !token) { location.hash = '#/login'; return; }
    if (page === 'login' || page === 'register') return viewAuth(page);
    if (page === 'forgot') return viewForgot();
    if (page === 'reset' && arg) return viewReset(arg);
    if (page === 'profile') return viewProfile();
    if (page === 'edit' && arg) return viewEdit(parseInt(arg, 10));
    if (page === 'charts') { location.hash = '#/dashboard'; return; }
    if (page === 'add') return viewAdd();
    if (page === 'predict') return viewPredict();
    if (page === 'milan') return viewMilan(arg ? parseInt(arg,10) : 0);
    if (page === 'chat') return viewChat();
    if (page === 'ai-chat') return viewChat('claude');
    if (page === 'rashifal') return viewRashifal(['daily', 'weekly', 'monthly'].includes(arg) ? arg : 'daily');
    if (page === 'admin') { location.hash = '#/panchang'; return; }

    if (page === 'home' || (!page && !token)) return viewHome();
    if (page === 'dashboard' || (!page && token)) return viewDashboard();
    if (page === 'chart' && arg) return viewChart(parseInt(arg, 10));
    if (page === 'print' && arg) return viewPrint(parseInt(arg, 10));
    return viewPanchang();
  }
  async function adminRoute() {
    document.body.classList.add('admin-mode'); $rail.innerHTML = $bnav.innerHTML = '';
    const [, page, sub, action] = location.hash.split('/');
    if (page === 'logout') { try { await api('POST', '/admin-auth/logout'); } catch (e) {} setToken(null); location.hash = '#/'; return viewAdminAuth(); }
    if (!token) return viewAdminAuth(page === 'register' ? 'register' : 'login');
    return ({
      overview: viewAdminOverview,
      users: () => sub === 'new' ? viewAdminAddUser() : sub && action === 'kundali' ? viewAdminAddKundali(+sub) : sub ? viewAdminUser(+sub) : viewAdminUsers(),
      kundalis: () => sub ? viewAdminEditKundali(+sub) : viewAdminKundalis(),
      categories: () => viewAdminCats(), remedies: () => viewAdmin(), plans: viewAdminPlans, admins: viewAdminAdmins
    }[page] || viewAdminOverview)();
  }
  const DATE_RE = /\b(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}:\d{2})(?::\d{2})?Z)?\b/g;
  const TIME_RE = /(?<![+\u2212\-\d:])\b([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?\b(?!\s?[AP]M)/g, AMPM = { en: ['AM', 'PM'], hi: ['AM', 'PM'], gu: ['AM', 'PM'] };
  const to12 = (h, m) => { h = +h; return `${h % 12 || 12}:${m} ${AMPM[lang][h < 12 ? 0 : 1]}`; };
  function fmtDates(root) { const w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, { acceptNode: n => n.parentElement.closest('script,style,code,.nofmt') ? 2 : 1 }); let n; const L = [];
    while ((n = w.nextNode())) L.push(n);
    L.forEach(n => { let v = n.nodeValue, o = v;
      const y4 = n.parentElement.closest('.y4');
      v = v.replace(DATE_RE, (m, y, mo, d, tm) => `${d}-${mo}-${y4 ? y : y.slice(2)}${tm ? ' ' + tm + ' UTC' : ''}`);
      if (/\d:\d\d/.test(v)) v = v.replace(TIME_RE, (m, h, mi) => to12(h, mi));
      if (v !== o) n.nodeValue = v; }); }
  let obsBusy = false;
  new MutationObserver(() => { if (obsBusy) return; obsBusy = true; requestAnimationFrame(() => { obsBusy = false; fmtDates($app); stagger($app); enhanceFields($app); countUp($app); $app.querySelectorAll('.dial[data-v]:not(.go), .ring[data-v]:not(.go), .bar[data-v]:not(.go)').forEach(el => requestAnimationFrame(() => el.classList.add('go'))); }); }).observe($app, { childList: true, subtree: true });
  window.addEventListener('hashchange', route);
  loadLang(lang).then(route).catch(e => h(errBox(e)));
})();