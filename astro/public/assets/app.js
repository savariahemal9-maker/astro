// Website client. Uses only the public /api/v1 endpoints, exactly as a mobile app would.
(() => {
  const API = window.API_BASE;
  const $app = document.getElementById('app');
  const $rail = document.getElementById('rail'), $bnav = document.getElementById('bnav'), $prog = document.getElementById('progress');
  let inflight = 0;
  const $lang = document.getElementById('lang');
  let isAdmin = false;
  let dict = {}, lang = localStorage.getItem('lang') || 'en', token = localStorage.getItem('token');

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
      if (json.error.code === 'unauthorized') { setToken(null); location.hash = '#/login'; }
      const e = new Error(json.error.message); e.code = json.error.code; e.details = json.error.details; throw e;
    }
    return json.data;
  }
  const setToken = v => { token = v; v ? localStorage.setItem('token', v) : localStorage.removeItem('token'); renderNav(); };

  async function loadLang(l) {
    lang = l; localStorage.setItem('lang', l); $lang.value = l; document.documentElement.lang = l;
    dict = await api('GET', `/meta/i18n?lang=${l}`);
    document.querySelectorAll('[data-t]').forEach(el => el.textContent = t(el.dataset.t));
  }
  $lang.addEventListener('change', async () => {
    await loadLang($lang.value);
    if (token) api('PATCH', '/me', { lang }).catch(() => {});
    route();
  });

  function renderNav() {
    const cur = location.hash.split('/')[1] || (token ? 'dashboard' : 'panchang');
    const links = [['panchang', 'ui.panchang', 'calendar_month']].concat(token
      ? [['dashboard', 'ui.dashboard', 'space_dashboard'], ['predict', 'ui.personal_predictions', 'auto_awesome'], ['add', 'ui.add_chart', 'person_add']].concat(isAdmin ? [['admin', 'ui.admin', 'admin_panel_settings']] : []).concat([['profile', 'ui.my_profile', 'account_circle'], ['logout', 'ui.sign_out', 'logout']])
      : [['login', 'ui.sign_in', 'login'], ['register', 'ui.register', 'person_add']]);
    const html = links.map(([k, l, ic]) => `<a href="#/${k}" class="${cur === k || (k === 'dashboard' && ['chart', 'print', 'charts'].includes(cur)) ? 'on' : ''}"><span class="ms">${ic}</span><span>${esc(t(l))}</span></a>`).join('');
    $rail.innerHTML = html; $bnav.innerHTML = html;
  }
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
  document.getElementById('railToggle').onclick = () => document.body.classList.toggle('rail-collapsed');

  const fmtLoc = (s) => s ? esc(s.slice(11) ? `${s.slice(8, 10)}-${s.slice(5, 7)} ${s.slice(11)}` : s) : '—';
  const errBox = (e) => `<p class="err" role="alert">${esc(e.message)}</p>`;
  const skel = () => `<div class="skel" role="status" aria-label="${esc(t('ui.loading'))}"><span></span><span></span><span></span></div>`;
  const loading = () => h(skel());
  const calcTag = () => `<span class="tag c"><span class="ms">calculate</span>${esc(t('ui.calculated'))}</span>`;
  const interpTag = () => `<span class="tag i"><span class="ms">auto_awesome</span>${esc(t('ui.interpretation'))}</span>`;

  // ---------- place search (shared by panchang + chart form) ----------
  function placePicker(root, onPick) {
    const input = root.querySelector('[name=place_q]'), box = root.querySelector('.suggest');
    let timer;
    input.addEventListener('input', () => {
      clearTimeout(timer);
      if (input.value.trim().length < 3) { box.innerHTML = ''; return; }
      timer = setTimeout(async () => {
        try {
          const rows = await api('GET', '/geo/search?q=' + encodeURIComponent(input.value));
          box.innerHTML = rows.map((r, i) => `<button type="button" data-i="${i}">${esc(r.name)}</button>`).join('');
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
    h(`<section class="hero-band"><div><p class="eyebrow">${esc(t('ui.panchang'))}</p><h1>${esc(place.name.split(',')[0])}</h1></div>
        <form class="pbar" id="pf"><label>${esc(t('ui.date'))}<input type="date" name="date" value="${date}" required></label>
        <label class="grow">${esc(t('ui.location'))}<input name="place_q" value="${esc(place.name)}" autocomplete="off"></label><div class="suggest"></div></form></section>
      <div class="tabs" role="tablist">${[['panchang', 'calendar_month'], ['choghadiya', 'schedule'], ['panchang_chart', 'grid_view']].map(([x, ic]) =>
        `<button data-t2="${x}" class="${x === tab ? 'on' : ''}"><span class="ms">${ic}</span>${esc(t('ui.' + x))}</button>`).join('')}</div><div id="pout"></div>`);
    const f = document.getElementById('pf');
    tabIndicator($app.querySelector('.tabs'));
    placePicker(f, p => { if (p.tzid) { place = p; localStorage.setItem('place', JSON.stringify(p)); show(); } });
    $app.querySelectorAll('[data-t2]').forEach(b => b.onclick = () => { $app.querySelectorAll('[data-t2]').forEach(x => x.classList.toggle('on', x === b)); tabIndicator(b.parentElement); tab = b.dataset.t2; show(); });
    f.date.onchange = () => show(); f.onsubmit = e => { e.preventDefault(); show(); };
    async function show() {
      const out = document.getElementById('pout'); out.innerHTML = skel();
      try {
        const p = await api('GET', `/panchang?date=${f.date.value}&lat=${place.lat}&lon=${place.lon}&tzid=${encodeURIComponent(place.tzid)}`);
        const seg = (list, fn) => list.map(x => `<div class="pv"><b>${fn(x)}</b><small>${esc(t('ui.until'))} ${fmtLoc(x.ends)}</small></div>`).join('');
        const tm = s => s ? esc(s.slice(11)) : '—', kaal = k => `${tm(p[k].start)} – ${tm(p[k].end)}`;
        const mins = x => x ? (+x.slice(11, 13)) * 60 + (+x.slice(14, 16)) : 0;
        if (tab === 'panchang') { const sr = mins(p.sunrise), ss = mins(p.sunset), span = Math.max(1, ss - sr), pos = x => Math.max(0, Math.min(100, (mins(x) - sr) / span * 100));
          const seg1 = (k, cls) => `<i class="${cls}" style="left:${pos(p[k].start)}%;width:${pos(p[k].end) - pos(p[k].start)}%" title="${esc(t('ui.' + k))}"></i>`;
          const anga = (ic, lbl, list, fn) => `<article class="anga"><span class="ms">${ic}</span><div><small>${esc(lbl)}</small>${list.map(x => `<b>${fn(x)}</b><em>${esc(t('ui.until'))} ${fmtLoc(x.ends)}</em>`).join('')}</div></article>`;
          out.innerHTML = `<section class="pday"><div class="pd-main"><small>${esc(tn('weekdays', p.vara.name))}</small><h2>${esc(tn('paksha', p.tithi[0].paksha) + ' ' + tn('tithis', p.tithi[0].name))}</h2>
              <p>${esc(tn('nakshatras', p.nakshatra[0].name))} · ${esc(tn('yogas', p.yoga[0].name))}</p></div>
            <div class="pd-sun"><div><span class="ms">wb_twilight</span><b>${tm(p.sunrise)}</b><small>${esc(t('ui.sunrise'))}</small></div><div><span class="ms">wb_sunny</span><b>${tm(p.sunset)}</b><small>${esc(t('ui.sunset'))}</small></div>
              <div><span class="ms">nightlight</span><b>${tm(p.moonrise)}</b><small>${esc(t('ui.moonrise'))}</small></div><div><span class="ms">bedtime</span><b>${tm(p.moonset)}</b><small>${esc(t('ui.moonset'))}</small></div></div></section>
            <section class="card"><div class="card-h"><span class="ms">schedule</span>${esc(t('ui.day_c'))} · ${tm(p.sunrise)} – ${tm(p.sunset)}</div>
              <div class="dayline">${seg1('rahu_kaal', 'r')}${seg1('yamaganda', 'y')}${seg1('gulika', 'g')}</div>
              <div class="klist"><span class="kd r"></span>${esc(t('ui.rahu_kaal'))} <b>${kaal('rahu_kaal')}</b><span class="kd y"></span>${esc(t('ui.yamaganda'))} <b>${kaal('yamaganda')}</b><span class="kd g"></span>${esc(t('ui.gulika'))} <b>${kaal('gulika')}</b></div></section>
            <div class="angas">${anga('brightness_4', t('ui.tithi'), p.tithi, x => esc(tn('paksha', x.paksha) + ' ' + tn('tithis', x.name)))}${anga('stars', t('ui.nakshatra'), p.nakshatra, x => esc(tn('nakshatras', x.name)))}
              ${anga('join', t('ui.yoga'), p.yoga, x => esc(tn('yogas', x.name)))}${anga('hourglass', t('ui.karana'), p.karana, x => esc(tn('karanas', x.name)))}
              <article class="anga"><span class="ms">public</span><div><small>${esc(t('ui.sun_sign'))} / ${esc(t('ui.moon_sign'))}</small><b>${esc(tn('signs', p.sun_sign))} / ${esc(tn('signs', p.moon_sign))}</b></div></article></div>`; }
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
  function viewAuth(mode) {
    const reg = mode === 'register', adm = mode === 'admin';
    h(`<h1>${adm ? `<span class="ms">admin_panel_settings</span> ${esc(t('ui.admin_login'))}` : esc(t(reg ? 'ui.register' : 'ui.sign_in'))}</h1>
      <form class="stack" id="af">
        ${reg ? `<label>${esc(t('ui.name'))}<input name="name" required autocomplete="name"></label>` : ''}
        <label>${esc(t('ui.email'))}<input type="email" name="email" required autocomplete="email"></label>
        <label>${esc(t('ui.password'))}<input type="password" name="password" minlength="8" required autocomplete="${reg ? 'new-password' : 'current-password'}"></label>
        <div id="aerr"></div>
        <div><button>${esc(t(reg ? 'ui.register' : 'ui.sign_in'))}</button></div>
        <div class="row"><a href="#/${reg ? 'login' : 'register'}">${esc(t(reg ? 'ui.have_account' : 'ui.no_account'))}</a>${reg ? '' : `<a href="#/forgot">${esc(t('ui.forgot_password'))}</a>`}</div>
      </form>`);
    const f = document.getElementById('af');
    f.onsubmit = async e => {
      e.preventDefault(); const b = f.querySelector('button'); b.disabled = true;
      try {
        const body = { email: f.email.value, password: f.password.value, lang, client: 'web' };
        if (reg) body.name = f.name.value;
        const d = await api('POST', reg ? '/auth/register' : '/auth/login', body);
        setToken(d.token); if (d.user.lang !== lang) await loadLang(d.user.lang);
        try { isAdmin = (await api('GET', '/me/admin')).admin; } catch (e2) {}
        if (adm) { if (location.hash === '#/admin') route(); else location.hash = '#/admin'; } else location.hash = '#/dashboard';
      } catch (err) { document.getElementById('aerr').innerHTML = errBox(err); b.disabled = false; }
    };
  }

  function viewForgot() {
    h(`<h1>${esc(t('ui.forgot_password'))}</h1><p class="muted">${esc(t('ui.forgot_hint'))}</p>
      <form class="stack" id="ff"><label>${esc(t('ui.email'))}<input type="email" name="email" required autocomplete="email"></label>
        <div id="ferr"></div><div><button>${esc(t('ui.send_reset_link'))}</button></div><a href="#/login">${esc(t('ui.back_to_login'))}</a></form>`);
    const f = document.getElementById('ff');
    f.onsubmit = async e => {
      e.preventDefault(); const b = f.querySelector('button'); b.disabled = true;
      try { await api('POST', '/auth/forgot', { email: f.email.value }); f.outerHTML = `<section class="card"><span class="ms">mark_email_read</span> ${esc(t('ui.reset_sent'))}</section><p><a href="#/login">${esc(t('ui.back_to_login'))}</a></p>`; }
      catch (err) { document.getElementById('ferr').innerHTML = errBox(err); b.disabled = false; }
    };
  }
  // new password + confirmation; used by reset and change-password forms
  const pwPair = () => `<label>${esc(t('ui.new_password'))}<input type="password" name="new_password" minlength="8" required autocomplete="new-password"></label>
    <label>${esc(t('ui.confirm_password'))}<input type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></label>`;
  const pwMismatch = f => f.new_password.value !== f.confirm_password.value ? errBox({ message: t('ui.password_mismatch') }) : '';
  function viewReset(token) {
    h(`<h1>${esc(t('ui.reset_password'))}</h1><form class="stack" id="rf">${pwPair()}<div id="rerr"></div><div><button>${esc(t('ui.reset_password'))}</button></div></form>`);
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
    h(`<section class="hero-band"><div><p class="eyebrow">${esc(me.email)}</p><h1>${esc(t('ui.my_profile'))}</h1></div><span class="ms hero-ic">account_circle</span></section>
      <div class="addwrap"><section class="card"><div class="card-h"><span class="ms">edit</span>${esc(t('ui.edit_profile'))}</div>
        <form class="stack" id="pf"><label>${esc(t('ui.name'))}<input name="name" required maxlength="120" autocomplete="name" value="${esc(me.name)}"></label>
          <label>${esc(t('ui.email'))}<input type="email" name="email" required autocomplete="email" value="${esc(me.email)}"></label>
          <label>${esc(t('ui.language'))}<select name="lang">${[['en', 'English'], ['hi', 'हिन्दी'], ['gu', 'ગુજરાતી']].map(([v, n]) => `<option value="${v}"${me.lang === v ? ' selected' : ''}>${n}</option>`).join('')}</select></label>
          <div id="pwc" hidden><label>${esc(t('ui.current_password'))}<input type="password" name="password" autocomplete="current-password"></label><p class="muted">${esc(t('ui.email_change_hint'))}</p></div>
          <div id="perr"></div><div><button>${esc(t('ui.save'))}</button></div></form></section>
      <section class="card"><div class="card-h"><span class="ms">lock_reset</span>${esc(t('ui.change_password'))}</div>
        <form class="stack" id="cpf"><label>${esc(t('ui.current_password'))}<input type="password" name="current_password" required autocomplete="current-password"></label>${pwPair()}
          <div id="cperr"></div><div><button>${esc(t('ui.change_password'))}</button></div></form></section></div>`);
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
    let mine = cats.filter(c => c.selected), editing = !mine.length;
    const head = `<section class="hero-band"><div><p class="eyebrow">${esc(t('ui.app_name'))}</p><h1>${esc(t('ui.personal_predictions'))}</h1></div><span class="ms hero-ic">auto_awesome</span></section>`;
    if (!profs.length) { h(head + `<section class="card"><p>${esc(t('ui.no_kundali'))}</p><a class="btn accent" href="#/add"><span class="ms">add</span>${esc(t('ui.new_kundali'))}</a></section>`); return; }
    h(head + `<section class="card pp-bar"><div class="row">
        <label>${esc(t('ui.select_kundali'))}<select id="ppk">${profs.map(p => `<option value="${p.id}"${p.id === st.kid ? ' selected' : ''}>${esc(p.label)} · ${esc(p.birth_date)}</option>`).join('')}</select></label>
        <label>${esc(t('ui.date'))}<input type="date" id="ppd" name="date" value="${esc(st.date)}"></label></div>
        <div class="card-h"><span class="ms">category</span>${esc(t('ui.my_categories'))}<button class="ghost" id="ppe" type="button"><span class="ms">tune</span>${esc(t('ui.manage_categories'))}</button></div>
        <div id="ppc"></div></section><div id="ppt"></div>`);
    const $c = document.getElementById('ppc'), $t = document.getElementById('ppt');
    const chips = () => {
      if (editing) {
        $c.innerHTML = `<div class="chips">${cats.map(c => `<label class="chip-btn pick${c.selected ? ' on' : ''}"><input type="checkbox" value="${c.id}"${c.selected ? ' checked' : ''} hidden><span class="ms">${esc(c.icon)}</span>${esc(c.name)}</label>`).join('')}</div>
          <div class="row"><button id="pps">${esc(t('ui.save'))}</button>${mine.length ? `<button class="ghost" id="ppx" type="button">${esc(t('ui.cancel'))}</button>` : ''}</div>`;
        $c.querySelectorAll('.pick input').forEach(i => i.onchange = () => i.parentElement.classList.toggle('on', i.checked));
        document.getElementById('ppx')?.addEventListener('click', () => { editing = false; chips(); });
        document.getElementById('pps').onclick = async () => {
          const ids = [...$c.querySelectorAll('.pick input:checked')].map(i => +i.value);
          try { const r = await api('PUT', '/me/categories', { ids }); cats.forEach(c => c.selected = r.ids.includes(c.id)); mine = cats.filter(c => c.selected);
            toast(t('ui.saved')); editing = !mine.length; if (!mine.find(c => c.id === st.cat)) st.cat = mine[0]?.id; save(); chips(); } catch (e) { toast(e.message, 'error'); } };
      } else {
        if (!mine.find(c => c.id === st.cat)) st.cat = mine[0]?.id;
        $c.innerHTML = `<div class="chips">${mine.map(c => `<button type="button" class="chip-btn${c.id === st.cat ? ' on' : ''}" data-c="${c.id}"><span class="ms">${esc(c.icon)}</span>${esc(c.name)}</button>`).join('')}</div>`;
        $c.querySelectorAll('[data-c]').forEach(b => b.onclick = () => { st.cat = +b.dataset.c; save(); chips(); });
      }
      show();
    };
    document.getElementById('ppe').onclick = () => { editing = !editing; chips(); };
    document.getElementById('ppk').onchange = e => { st.kid = +e.target.value; save(); show(); };
    document.getElementById('ppd').onchange = e => { st.date = e.target.value || today(); save(); show(); };
    const yr = d => d.slice(0, 4);
    const box = (cls, icon, title, list) => list && list.length ? `<div class="pp-box ${cls}"><h3><span class="ms">${icon}</span> ${esc(title)}</h3><ul>${list.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div>` : '';
    const card = d => `<section class="card pp-res ${d.level}"><div class="pp-top"><div><p class="pp-cat"><span class="ms">${esc(d.category.icon)}</span> ${esc(d.category.name)}</p>
        <h2 class="pp-head">${esc(d.headline)}</h2><p class="pp-range"><span class="ms">event</span>${esc(rangeText(d.range) || t('ui.p_lifetime'))}</p>
        <span class="chip ${d.level === 'favourable' ? 'ok' : d.level === 'challenging' ? 'warn' : ''}">${esc(d.level_text)}</span></div>
        <div class="pp-score">${dial(d.score, d.score_label)}</div></div>
      <p class="lead">${esc(d.explanation)}</p>
      ${d.caution ? `<div class="warn"><span class="ms">report</span> ${esc(d.caution)}</div>` : ''}
      <div class="pp-boxes">${box('do', 'check_circle', t('ui.what_to_do'), d.do)}${box('dont', 'block', t('ui.what_to_avoid'), d.dont)}${box('upay', 'spa', t('ui.simple_upay'), d.upay)}</div>
      ${d.timeline && d.timeline.length ? `<h3><span class="ms">timeline</span> ${esc(t('ui.life_phases'))}</h3><ul class="pp-phases">${d.timeline.map(x => `<li class="${x.favourable ? 'good' : 'care'}"><b>${yr(x.start)} – ${yr(x.end)}</b><span class="chip ${x.favourable ? 'ok' : 'warn'}">${esc(t(x.favourable ? 'ui.good_phase' : 'ui.care_phase'))}</span></li>`).join('')}</ul>` : ''}
      ${collapsible(t('ui.why_details'), `<div class="two"><div><h3>${esc(t('ui.positive_factors'))}</h3><ul class="lines">${d.details.positive.map(x => `<li>${esc(x)}</li>`).join('') || `<li class="muted">${esc(t('ui.none'))}</li>`}</ul></div>
        <div><h3>${esc(t('ui.challenging_factors'))}</h3><ul class="lines">${d.details.challenging.map(x => `<li>${esc(x)}</li>`).join('') || `<li class="muted">${esc(t('ui.none'))}</li>`}</ul></div></div>`)}
      <p class="muted pp-disc"><span class="ms">info</span> ${esc(d.disclaimer)}</p></section>`;
    let seq = 0;
    const show = () => {
      if (!st.cat) { $t.innerHTML = `<p class="muted">${esc(t('ui.no_categories'))}</p>`; return; }
      localTabs($t, PERIODS.map(p => [p, t('ui.p_' + p), { daily: 'today', weekly: 'date_range', monthly: 'calendar_month', yearly: 'event_repeat', lifetime: 'all_inclusive' }[p], () => {
        const my = ++seq; st.per = p; save();
        api('GET', `/profiles/${st.kid}/category-prediction?category=${st.cat}&period=${p}&date=${st.date}&lang=${lang}`)
          .then(d => { if (my === seq) $t.querySelector('.lbody').innerHTML = card(d); }).catch(e => { if (my === seq) $t.querySelector('.lbody').innerHTML = errBox(e); });
        return skel();
      }]), PERIODS.indexOf(st.per));
    };
    chips();
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
    h(`<div class="hero"><div><h1>${esc(prof.label)}</h1>
      <div class="chips"><span class="chip"><span class="ms">event</span>${esc(b.local.slice(0, 16))}</span><span class="chip"><span class="ms">location_on</span>${esc(prof.place_name.split(',')[0])}</span>
      <span class="chip"><span class="ms">public</span>${esc(b.tzid)} · UTC ${esc(b.utc.slice(11, 16))}</span><span class="chip ok"><span class="ms">north_east</span>${esc(t('ui.lagna'))} ${esc(tn('signs', k.lagna.sign_name))}</span></div></div>
      <a class="btn tonal" href="#/edit/${id}"><span class="ms">edit</span>${esc(t('ui.edit_kundali'))}</a><a class="btn accent" href="#/print/${id}"><span class="ms">download</span>${esc(t('ui.download_report'))}</a></div>
      ${b.warnings.map(w => `<div class="warn">${esc(w.message)}</div>`).join('')}
      ${prof.time_accuracy === 'approximate' ? `<div class="warn">${esc(t('ui.approx_warning'))}</div>` : ''}
      <div class="tabs" role="tablist">${Object.keys(GROUPS).map((x, i) =>
        `<button role="tab" data-tab="${x}" class="${i ? '' : 'on'}"><span class="ms">${GROUPS[x].icon}</span>${esc(t('ui.' + x))}</button>`).join('')}</div>
      <div id="sub" class="subtabs"></div><div id="tab"></div>
      <details><summary>${esc(t('ui.settings'))}</summary><ul>
        <li>${esc(t('ui.ayanamsa'))}: ${esc(s.ayanamsa)} (${esc(k.meta.ayanamsa_dms)})</li><li>Rahu/Ketu: ${esc(s.node)} node</li>
        <li>Zodiac: ${esc(s.zodiac)}, ${esc(s.positions)}; houses: ${esc(s.house_system)}</li>
        <li>Dasha year: ${esc(s.dasha_year_days)} days</li><li>Swiss Ephemeris ${esc(k.meta.engine.swisseph)}, engine ${esc(k.meta.engine.version)}</li></ul></details>`);
    const fns = { overview: () => tabOverview(id, k), daily_kundali: () => tabDaily(id), charts: () => tabCharts(k, prof), planets: () => tabPlanets(k), dasha: () => tabDasha(k),
      whole_life: () => tabPred(id, 'life'), mdphal: () => tabPred(id, 'mdphal'), current_mahadasha: () => tabPred(id, 'dasha'), monthly: () => tabMonthly(id), yogas: () => tabYogas(id),
      varshphal: () => tabVarsh(id, k), doshas: () => tabDoshas(id), priority_remedies: () => tabPriority(id), planet_results: () => tabPlanetResults(id), periodic: () => tabRemedies(id),
      gemstones: () => tabGems(id), transits: () => tabTransits(id) };
    const openGroup = g => { document.getElementById('tab').classList.toggle('y4', ['predictions', 'overview'].includes(g)); const subs = GROUPS[g].subs, sub = document.getElementById('sub');
      sub.innerHTML = subs.length > 1 ? subs.map((x, i) => `<button data-s="${x}" class="${i ? '' : 'on'}">${esc(t('ui.' + x))}</button>`).join('') : '';
      sub.querySelectorAll('button').forEach(b => b.onclick = () => { sub.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b)); fns[b.dataset.s](); });
      fns[subs[0]](); };
    document.querySelectorAll('.tabs button').forEach(bt => bt.onclick = () => {
      document.querySelectorAll('.tabs button').forEach(x => x.classList.toggle('on', x === bt)); tabIndicator(bt.parentElement); openGroup(bt.dataset.tab);
    });
    tabIndicator($app.querySelector('.tabs')); addEventListener('resize', () => tabIndicator($app.querySelector('.tabs')));
    openGroup('overview');
  }
  const GROUPS = { overview: { icon: 'space_dashboard', subs: ['overview'] }, daily_kundali: { icon: 'today', subs: ['daily_kundali'] }, kundali: { icon: 'grid_view', subs: ['charts', 'planets', 'dasha'] },
    predictions: { icon: 'auto_awesome', subs: ['whole_life', 'mdphal', 'current_mahadasha', 'monthly'] }, varshphal: { icon: 'event_repeat', subs: ['varshphal'] },
    doshas: { icon: 'report', subs: ['doshas'] }, yogas: { icon: 'auto_fix_high', subs: ['yogas'] }, remedies: { icon: 'spa', subs: ['priority_remedies', 'planet_results', 'periodic'] },
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
      el.innerHTML = `<section>${interpTag()}${collItems(d.items)}</section>`; }
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
  async function viewPrint(id) {
    loading();
    let r; try { r = await api('GET', `/profiles/${id}/full-report?lang=${lang}&tzid=${encodeURIComponent(TZ)}`); } catch (e) { h(errBox(e)); return; }
    const k = r.kundali, b = k.birth, s = k.meta.settings, P = r.profile;
    const items = (d) => d.items.map(it => `<div class="interp"><p style="margin:0">${esc(it.text)}</p>
      <p class="deriv">${esc(t('ui.rule'))}: ${esc(it.derivation.rule)} · ${it.derivation.from_calculated.map(f => `${esc(f.path)} = ${esc(Array.isArray(f.value) ? f.value.join(', ') : f.value)}`).join('; ')}</p></div>`).join('');
    const block = (title, d) => `<h2>${esc(title)}</h2>${items(d)}`;
    r.predictions.life.items = r.predictions.life.items.filter(i => i.section !== 'timeline');
    const sec = (title, body, calc) => `<section class="page"><h1>${esc(title)}</h1>${calc ? calcTag() : interpTag()}${body}</section>`;
    const planetRows = k.planets.map(p => `<tr><td>${esc(tn('planets', p.name))}</td><td>${esc(tn('signs', p.sign_name))}</td><td>${esc(p.dms)}</td><td>${esc(tn('nakshatras', p.nakshatra))} ${p.pada}</td><td>${esc(tn('planets', p.nakshatra_lord))}</td><td>${p.house}</td><td>${status(p)}</td></tr>`).join('');
    const vargaCharts = Object.keys(k.vargas).map(v => `<figure class="vc"><figcaption>${esc(vname(v))}</figcaption>${northChart(k, v)}</figure>`).join('');
    const dasha = k.dasha.mahadasha.map(md => `<tr class="md"><td><strong>${esc(tn('planets', md.lord))}</strong></td><td>${md.start}</td><td>${md.end}</td><td>${md.antardasha.map(a => `${esc(tn('planets', a.lord))} ${a.start}`).join(' · ')}</td></tr>`).join('');
    const tr = r.transits;
    const trRows = tr.planets.map(p => `<tr><td>${esc(tn('planets', p.name))}${p.retrograde && !['Rahu', 'Ketu'].includes(p.name) ? ' *' : ''}</td><td>${esc(tn('signs', p.sign_name))} ${esc(p.dms)}</td><td>${esc(tn('nakshatras', p.nakshatra))}</td><td>${p.house_from_lagna}</td><td>${p.house_from_moon}</td></tr>`).join('');
    const sens = Object.entries(k.sensitivity.lagna).map(([d, x]) => `<tr><td>${d}</td><td>${x.changes_if_earlier_by_min}</td><td>${x.changes_if_later_by_min}</td></tr>`).join('');
    const toc = [t('ui.whole_life'), t('ui.mdphal'), t('ui.planet_results'), t('ui.varshphal'), t('ui.birth_details'), t('ui.charts') + ' & ' + t('ui.planets'), t('ui.all_charts'), t('ui.dasha'), t('ui.transits') + ' & ' + t('ui.doshas'),
      t('ui.predictions'), t('ui.gemstones') + ' & ' + t('ui.influences'), t('ui.sensitivity_title')];
    h(`<div class="noprint row" style="margin-bottom:1rem"><button onclick="window.print()">${esc(t('ui.download_report'))}</button><a href="#/chart/${id}">← ${esc(P.label)}</a><span class="muted">${esc(t('ui.print_hint'))}</span></div>
    <div class="report">
    <section class="cover"><p class="brand">${esc(t('ui.app_name'))}</p><h1>${esc(P.label)}</h1>
      <table><tr><th>${esc(t('ui.birth_date'))}</th><td>${esc(b.local)} (${esc(b.tzid)}, UTC${b.offset_minutes >= 0 ? '+' : '−'}${Math.floor(Math.abs(b.offset_minutes) / 60)}:${String(Math.abs(b.offset_minutes) % 60).padStart(2, '0')}, ${esc(b.offset_source)})</td></tr>
      <tr><th>${esc(t('ui.utc'))}</th><td>${esc(b.utc)}</td></tr><tr><th>${esc(t('ui.birth_place'))}</th><td>${esc(P.place_name)} (${b.lat}, ${b.lon})</td></tr>
      <tr><th>${esc(t('ui.time_accuracy'))}</th><td>${esc(t('ui.' + P.time_accuracy))}</td></tr>
      <tr><th>${esc(t('ui.lagna'))}</th><td>${esc(tn('signs', k.lagna.sign_name))} ${esc(k.lagna.dms)} · ${esc(tn('nakshatras', k.lagna.nakshatra))} ${k.lagna.pada}</td></tr>
      <tr><th>${esc(t('ui.settings'))}</th><td>${esc(t('ui.ayanamsa'))} ${esc(s.ayanamsa)} ${esc(k.meta.ayanamsa_dms)} · ${esc(s.node)} node · ${esc(s.zodiac)} · ${esc(s.house_system)} · Swiss Ephemeris ${esc(k.meta.engine.swisseph)}</td></tr>
      <tr><th>${esc(t('ui.as_on'))}</th><td>${esc(r.as_on)} · ${esc(t('ui.generated'))} ${esc(r.generated_utc)}</td></tr></table>
      ${b.warnings.map(w => `<div class="warn">${esc(w.message)}</div>`).join('')}
      <h2>${esc(t('ui.contents'))}</h2><ol>${toc.map(x => `<li>${esc(x)}</li>`).join('')}</ol>
</section>
    ${sec(t('ui.charts') + ' & ' + t('ui.planets'), `<div class="two"><figure class="vc"><figcaption>${esc(vname('D1'))}</figcaption>${northChart(k, 'D1')}</figure>
      <figure class="vc"><figcaption>${esc(vname('D9'))}</figcaption>${northChart(k, 'D9')}</figure></div>${legend()}
      <table><tr><th>${esc(t('ui.planet'))}</th><th>${esc(t('ui.sign'))}</th><th>${esc(t('ui.degree'))}</th><th>${esc(t('ui.nakshatra'))}</th><th>${esc(t('ui.lord'))}</th><th>${esc(t('ui.house'))}</th><th>${esc(t('ui.status'))}</th></tr>
      <tr><td>${esc(t('ui.lagna'))}</td><td>${esc(tn('signs', k.lagna.sign_name))}</td><td>${esc(k.lagna.dms)}</td><td>${esc(tn('nakshatras', k.lagna.nakshatra))} ${k.lagna.pada}</td><td></td><td>1</td><td></td></tr>${planetRows}</table>`, true)}
    ${sec(t('ui.all_charts'), `<div class="grid">${vargaCharts}</div>${legend()}`, true)}
    ${sec(t('ui.dasha'), `<p class="muted">${esc(t('ui.dasha'))} · ${esc(tn('nakshatras', k.dasha.moon_nakshatra))}</p><table><tr><th>${esc(t('ui.mahadasha'))}</th><th>${esc(t('ui.from'))}</th><th>${esc(t('ui.to'))}</th><th>${esc(t('ui.antardasha'))}</th></tr>${dasha}</table>`, true)}
    ${sec(t('ui.transits') + ' & ' + t('ui.doshas'), `<p class="muted">${esc(t('ui.as_on'))} ${esc(r.as_on)}</p><table><tr><th>${esc(t('ui.planet'))}</th><th>${esc(t('ui.sign'))}</th><th>${esc(t('ui.nakshatra'))}</th><th>${esc(t('ui.from_lagna'))}</th><th>${esc(t('ui.from_moon'))}</th></tr>${trRows}</table>
      <h2>${esc(t('ui.doshas'))}</h2><div class="checks">${r.dosha_report.items.map(i => `<div class="check ${i.present ? 'on' : ''}"><b>${esc(i.name)}</b><small>${esc(i.present ? t('ui.present') : t('ui.absent'))} · ${esc(i.rule)}</small></div>`).join('')}</div>
      ${r.dosha_report.items.filter(i => i.present).map(i => `<div class="interp"><h3>${esc(i.name)}</h3>${factList(i.factors)}<p><b>${esc(t('ui.effects'))}:</b> ${esc(i.effects)}</p><p><b>${esc(t('ui.remedy_how'))}:</b> ${esc(i.remedy)}</p></div>`).join('')}`, true)}
    ${sec(t('ui.overview'), `<div class="two"><div>${dial(r.summary.overall)}</div><div>${Object.entries(r.summary.planets).map(([n, x]) => bar(esc(tn('planets', n)), x.score)).join('')}</div></div>
      <p class="muted">${esc(r.summary.method.planet)}. ${esc(r.summary.method.percent)}.</p>`)}
    ${sec(t('ui.daily_kundali') + ' ' + (r.as_on || '').slice(0, 10), r.daily_reading.items.map(i => `<div class="interp"><h3>${esc(i.title)} · ${i.score}</h3><p>${esc(i.prediction)}</p><p class="deriv">${esc(i.reason)}</p><p>${esc(i.guidance)}</p></div>`).join(''))}
    ${sec(t('ui.priority_remedies'), r.priority_remedies.items.map(i => `<div class="interp"><h3>${i.rank}. ${esc(i.title)}</h3><p><b>${esc(t('ui.issue'))}:</b> ${esc(i.issue)}</p><p><b>${esc(t('ui.remedy_how'))}:</b> ${esc(i.how)}</p><p><b>${esc(t('ui.benefit'))}:</b> ${esc(i.benefit)}</p></div>`).join(''))}
    ${sec(t('ui.annual_prediction'), `<p>${esc(r.annual.muntha)}</p>` + r.annual.items.map(i => `<div class="interp"><h3>${esc(i.title)} · ${esc(t('ui.v_' + i.verdict))}</h3><p>${esc(i.prediction)}</p><p class="deriv">${esc(i.reason)}</p><p>${esc(i.description)}</p><p>${esc(i.guidance)}</p></div>`).join(''))}
    ${sec(t('ui.predictions'), block(t('ui.daily'), r.predictions.daily) + block(t('ui.monthly'), r.predictions.monthly) + block(t('ui.mahadasha_pred'), r.predictions.dasha) + block(t('ui.life'), r.predictions.life))}
    ${sec(t('ui.whole_life'), r.predictions.life.sections.map(it => `<div class="interp"><h3>${esc(it.title)}</h3><p style="margin:0">${esc(it.text)}</p><p class="deriv">${esc(t('ui.rule'))}: ${esc(it.derivation.rule)}</p></div>`).join(''))}
    ${sec(t('ui.mdphal'), r.predictions.mdphal.items.map(it => `<div class="interp"><h3>${esc(it.title)}</h3><p class="sub">${esc(it.subtitle)}</p><p style="margin:0">${esc(it.text)}</p></div>`).join(''))}
    ${sec(t('ui.planet_results'), r.planet_results.items.map(it => `<div class="interp"><h3>${esc(it.title)}</h3><p class="sub">${esc(it.subtitle)}</p><p style="margin:0">${esc(it.text)}</p><ol class="rem">${it.remedies.map(x => `<li>${esc(x)}</li>`).join('')}</ol></div>`).join(''))}
    ${sec(t('ui.varshphal') + ' ' + r.varshphal.return_local.slice(0, 10) + ' → ' + r.varshphal.valid_until_local.slice(0, 10), `<div class="two"><figure class="vc">${northChart(r.varshphal, 'D1')}</figure><div><table>
      <tr><th>${esc(t('ui.return_moment'))}</th><td>${esc(r.varshphal.return_local)}</td></tr><tr><th>${esc(t('ui.lagna'))}</th><td>${esc(tn('signs', r.varshphal.lagna.sign_name))} ${esc(r.varshphal.lagna.dms)}</td></tr>
      <tr><th>${esc(t('ui.muntha'))}</th><td>${esc(tn('signs', r.varshphal.muntha.sign_name))} · ${r.varshphal.muntha.house}</td></tr></table>
      <table>${r.varshphal.planets.map(p => `<tr><td>${esc(tn('planets', p.name))}</td><td>${esc(tn('signs', p.sign_name))} ${esc(p.dms)}</td><td>${p.house}</td></tr>`).join('')}</table></div></div><p class="muted">${esc(r.varshphal.meta.method)}</p>`, true)}
    ${sec(t('ui.gemstones'), `<div class="grid">${r.gem_report.recommended.concat(r.gem_report.caution).map(gemCard).join('')}</div>` + block(t('ui.influences'), r.gemstones.influences))}
    ${sec(t('ui.sensitivity_title'), `<p>${esc(t('ui.sensitivity_note'))}</p><table><tr><th>${esc(t('ui.charts'))}</th><th>${esc(t('ui.min_earlier'))}</th><th>${esc(t('ui.min_later'))}</th></tr>${sens}</table>
      ${P.time_accuracy === 'approximate' ? `<div class="warn">${esc(t('ui.approx_warning'))}</div>` : ''}`, true)}
    </div>`);
  }

  function viewAdd() {
    h(`<section class="hero-band"><div><p class="eyebrow">${esc(t('ui.app_name'))}</p><h1>${esc(t('ui.add_chart'))}</h1></div><span class="ms hero-ic">person_add</span></section>
      <div class="addwrap"><section class="card">${chartForm()}</section>
      <aside class="card tips"><div class="card-h"><span class="ms">lightbulb</span>${esc(t('ui.tips'))}</div><ul><li>${esc(t('ui.tip1'))}</li><li>${esc(t('ui.tip2'))}</li><li>${esc(t('ui.tip3'))}</li></ul></aside></div>`);
    bindChartForm();
  }
  async function viewEdit(id) {
    loading(); let p; try { p = await api('GET', '/profiles/' + id); } catch (e) { h(errBox(e)); return; }
    h(`<section class="hero-band"><div><p class="eyebrow">${esc(p.label)}</p><h1>${esc(t('ui.edit_kundali'))}</h1></div><span class="ms hero-ic">edit_calendar</span></section>
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
  const adminTabs = cur => `<div class="ltabs admin-tabs">${[['categories', t('ui.categories'), 'category'], ['remedies', t('ui.remedy_rules'), 'spa']].map(([k, l, ic]) =>
    `<a class="${cur === k ? 'on' : ''}" href="#/admin/${k}"><span class="ms">${ic}</span>${esc(l)}</a>`).join('')}</div>`;
  async function viewAdminCats(edit = null) {
    loading();
    try {
      const rows = await api('GET', '/admin/categories'), e = edit || {};
      const LG = [['en', 'English'], ['hi', 'हिन्दी'], ['gu', 'ગુજરાતી']];
      h(`<section class="hero-band"><div><p class="eyebrow">Admin</p><h1>${esc(t('ui.categories'))}</h1></div><span class="ms hero-ic">category</span></section>${adminTabs('categories')}
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
            <td><button class="icon-btn" data-e="${r.id}" aria-label="Edit"><span class="ms">edit</span></button><button class="icon-btn" data-d="${r.id}" aria-label="Delete"><span class="ms">delete</span></button></td></tr>`).join('')}</table></div></section>`);
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
      h(`<section class="hero-band"><div><p class="eyebrow">Admin</p><h1>${esc(t('ui.remedy_rules'))}</h1></div></section>${adminTabs('remedies')}
        <section class="card"><form id="rf" class="stack"><div class="row"><label>Planet${sel('planet', PL, e.planet)}</label><label>House${sel('house', ['', 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], e.house)}</label></div>
          <div class="row"><label>Dosha${sel('dosha', DS, e.dosha)}</label><label>Condition${sel('cond', ['any', 'weak', 'strong', 'malefic', 'benefic'], e.cond || 'any')}</label></div>
          <div class="row"><label>Priority<input name="priority" type="number" value="${e.priority ?? 50}"></label><label>Status${sel('status', ['draft', 'approved'], e.status || 'draft')}</label></div>
          <label>Source (book, chapter / astrologer)<input name="source" value="${esc(e.source || '')}" required></label>
          <label>English (one step per line)<textarea name="text_en" rows="4" required>${esc(e.text_en || '')}</textarea></label>
          <label>हिन्दी<textarea name="text_hi" rows="4">${esc(e.text_hi || '')}</textarea></label><label>ગુજરાતી<textarea name="text_gu" rows="4">${esc(e.text_gu || '')}</textarea></label>
          <div class="row"><button>${e.id ? 'Update' : 'Add'}</button>${e.id ? '<button type="button" class="ghost" id="rc">Cancel</button>' : ''}</div></form></section>
        <section class="card"><div class="scroll"><table><tr><th>Planet</th><th>House</th><th>Dosha</th><th>Cond</th><th>Source</th><th>Status</th><th></th></tr>
          ${rows.map(r => `<tr><td>${esc(r.planet || '—')}</td><td>${r.house ?? '—'}</td><td>${esc(r.dosha || '—')}</td><td>${esc(r.cond)}</td><td>${esc(r.source)}</td><td>${esc(r.status)}</td>
            <td><button class="icon-btn" data-e="${r.id}"><span class="ms">edit</span></button><button class="icon-btn" data-d="${r.id}"><span class="ms">delete</span></button></td></tr>`).join('')}</table></div></section>`);
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
      const place = JSON.parse(localStorage.getItem('place') || 'null') || { name: 'Ahmedabad', lat: 23.0225, lon: 72.5714, tzid: 'Asia/Kolkata' };
      const [me, list] = await Promise.all([api('GET', '/me'), api('GET', '/profiles')]), pc = null; void place;
      h(`<section class="hero-band"><div><p class="eyebrow">${esc(new Date().toLocaleDateString(lang === 'en' ? 'en-IN' : lang + '-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))}</p>
          <h1>${esc(t('ui.welcome'))}, ${esc(me.name)}</h1><div class="chips"><span class="chip"><span class="ms">person_book</span>${esc(t('ui.my_charts'))}: <b data-count="${list.length}">0</b></span></div></div>
          <a class="btn accent" href="#/add"><span class="ms">add</span>${esc(t('ui.new_kundali'))}</a></section>
        ${pc ? `<section class="card"><div class="card-h"><span class="ms">calendar_month</span>${esc(t('ui.today_panchang'))} · ${esc(place.name.split(',')[0])}${calcTag()}</div>
          <div class="kpis"><div><span>${esc(t('ui.tithi'))}</span><b>${esc(tn('paksha', pc.tithi[0].paksha) + ' ' + tn('tithis', pc.tithi[0].name))}</b></div>
          <div><span>${esc(t('ui.nakshatra'))}</span><b>${esc(tn('nakshatras', pc.nakshatra[0].name))}</b></div><div><span>${esc(t('ui.sunrise'))}</span><b>${esc(pc.sunrise.slice(11))}</b></div>
          <div><span>${esc(t('ui.rahu_kaal'))}</span><b>${esc(pc.rahu_kaal.start.slice(11))}–${esc(pc.rahu_kaal.end.slice(11))}</b></div></div></section>` : ''}
        <h2>${esc(t('ui.my_charts'))}</h2>
        <div class="kgrid">${list.map(p => `<section class="card kcard"><div class="kc-h"><span class="avatar">${esc(p.label.trim().charAt(0).toUpperCase())}</span><div><h3>${esc(p.label)}</h3>
            <small class="muted">${esc(p.birth_date)} · ${esc(p.birth_time.slice(0, 5))} · ${esc(p.place_name.split(',')[0])}</small></div></div>
            <div class="row"><a class="btn" href="#/chart/${p.id}"><span class="ms">open_in_new</span>${esc(t('ui.open'))}</a><a class="btn tonal" href="#/print/${p.id}"><span class="ms">download</span>PDF</a>
            <a class="icon-btn" href="#/edit/${p.id}" aria-label="${esc(t('ui.edit_kundali'))}"><span class="ms">edit</span></a><button class="icon-btn" data-del="${p.id}" aria-label="${esc(t('ui.delete'))}"><span class="ms">delete</span></button></div></section>`).join('')}
          <a class="card kcard add" href="#/add"><span class="ms">add_circle</span>${esc(t('ui.new_kundali'))}</a></div>`);
      $app.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
        if (!(await confirmDialog(t('ui.confirm_delete')))) return;
        try { await api('DELETE', '/profiles/' + b.dataset.del); toast(t('ui.deleted'), 'delete'); viewDashboard(); } catch (e) { toast(e.message, 'error'); } });
    } catch (e) { h(errBox(e)); }
  }

  // ---------- Router ----------
  async function route() {
    renderNav();
    const [, page, arg] = location.hash.split('/');
    if (page === 'logout') { try { await api('POST', '/auth/logout'); } catch (e) {} setToken(null); location.hash = '#/panchang'; return; }
    if (['charts', 'chart', 'print', 'dashboard', 'add', 'edit', 'profile', 'predict'].includes(page) && !token) { location.hash = '#/login'; return; }
    if (page === 'login' || page === 'register') return viewAuth(page);
    if (page === 'forgot') return viewForgot();
    if (page === 'reset' && arg) return viewReset(arg);
    if (page === 'profile') return viewProfile();
    if (page === 'edit' && arg) return viewEdit(parseInt(arg, 10));
    if (page === 'charts') { location.hash = '#/dashboard'; return; }
    if (page === 'add') return viewAdd();
    if (page === 'predict') return viewPredict();
    if (page === 'admin') {
      if (!token) return viewAuth('admin');
      if (!isAdmin) { try { isAdmin = (await api('GET', '/me/admin')).admin; } catch (e) {} renderNav(); }
      if (!isAdmin) { h(`<h1>${esc(t('ui.admin'))}</h1><section class="card"><p>${esc(t('ui.not_admin'))}</p><a class="btn" href="#/logout">${esc(t('ui.sign_out'))}</a></section>`); return; }
      return arg === 'remedies' ? viewAdmin() : viewAdminCats();
    }
    if (page === 'dashboard' || (!page && token)) return viewDashboard();
    if (page === 'chart' && arg) return viewChart(parseInt(arg, 10));
    if (page === 'print' && arg) return viewPrint(parseInt(arg, 10));
    return viewPanchang();
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
  loadLang(lang).then(async () => { if (token) try { isAdmin = (await api('GET', '/me/admin')).admin; } catch (e) {} }).then(route).catch(e => h(errBox(e)));
})();
