/*
 * Video jela: scena u pokretu, crtana za trenutak t. Sve što se vidi i kada dolazi iz window.SCENE (data.js, piše ga
 * App\Recipe\RecipeSceneBundle; vremena App\Recipe\RecipeVideoPlan).
 *
 *   udica     jela preko kadra (do tri, jelo objave zadnje) s naslovom i žutom naljepnicom
 *   telefon   zaslon „Što kuhati“ iz aplikacije: kartica jela, ističu se sastojci na akciji, ušteda i zbroj
 *   popis     dodir -> popis s glavnim sastojcima (samo kad ih izvor šalje)
 *   poziv     zelena kartica: gdje su recept i popis, gumb i gdje je poveznica
 *
 * Svaka animacija je Web Animations objekt pauziran na `currentTime = t`, pa renderAt(t) nacrta točno taj trenutak
 * bez ovisnosti o satu. Sav sadržaj je u sigurnoj zoni (y 200–1500), kao u ostalim 9:16 kadrovima huba.
 */
(() => {
  const D = window.SCENE;
  const T = D.timeline;
  const S = 2.04545;

  const EASE = {
    out: 'cubic-bezier(.16, 1, .3, 1)', inout: 'cubic-bezier(.65, 0, .35, 1)', soft: 'cubic-bezier(.4, 0, .2, 1)',
    back: 'cubic-bezier(.34, 1.56, .64, 1)', backBig: 'cubic-bezier(.3, 1.9, .5, 1)', in: 'cubic-bezier(.7, 0, .84, 0)',
    push: 'cubic-bezier(.32, .72, 0, 1)', linear: 'linear',
  };
  const $ = (selector, root = document) => root.querySelector(selector);
  const tracks = [];
  const frameFns = [];

  /** Staza s ključnim kadrovima na apsolutnim vremenima: [[t, {svojstva}, easing do sljedećeg ključa], …]. */
  function K(el, keys) {
    if (typeof el === 'string') el = $(el);
    if (!el) throw new Error('K: nema elementa');
    // Ključ koji bi pao prije prethodnog (kratak komad titla) staje na njega: vremena staze nikad ne idu unatrag.
    keys = keys.reduce((list, [t, props, ease]) => [...list, [Math.max(t, list.length ? list[list.length - 1][0] : t), props, ease]], []);
    const t0 = keys[0][0];
    const dur = Math.max(keys[keys.length - 1][0] - t0, 0.001);
    const frames = keys.map(([t, props, ease]) => ({ offset: Math.min(1, Math.max(0, (t - t0) / dur)), ...props, easing: ease || EASE.out }));
    const animation = el.animate(frames, { delay: t0 * 1000, duration: dur * 1000, fill: 'both', easing: 'linear' });
    animation.pause();
    tracks.push(animation);
  }
  const esc = (text) => String(text ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const svg = (inner, extra = '') => `<svg class="ic" viewBox="0 0 24 24" ${extra}>${inner}</svg>`;
  const I = {
    clock: svg('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'),
    heart: svg('<path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/>'),
    plus: svg('<path d="M12 5v14M5 12h14"/>'),
    check: svg('<path d="M5 12.5l4.5 4.5L19 7.5"/>'),
    tag: svg('<path d="M3 12.6V4a1 1 0 0 1 1-1h8.6a1 1 0 0 1 .7.3l7.4 7.4a1 1 0 0 1 0 1.4l-8.6 8.6a1 1 0 0 1-1.4 0L3.3 13.3a1 1 0 0 1-.3-.7z"/>'),
    list: svg('<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>'),
    filter: svg('<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>'),
    doc: svg('<path d="M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6"/>'),
    search: svg('<circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/>'),
    cook: svg('<path d="M6 3v7a2 2 0 0 0 4 0V3M8 3v18M17 3c-2 1-3 4-3 7h3zM17 3v18"/>'),
    cog: svg('<circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1"/>'),
  };
  const lockup = `<div class="lockup"><img src="${D.brand.icon}" alt=""><span>${esc(D.brand.app)}</span></div>`;
  const ai = (label) => (label ? `<div class="ailabel">${esc(label)}</div>` : '');

  const stage = $('#stage');
  stage.innerHTML = `
    <div class="layer" id="sHook"></div>
    <div class="layer" id="bgPhone"></div>
    <div class="layer" id="sPhone"></div>
    <div class="layer" id="bgEnd"><i style="width:1200px;height:1200px;left:-640px;top:-520px"></i><i style="width:1000px;height:1000px;right:-420px;bottom:-420px"></i></div>
    <div class="layer" id="sEnd"></div>`;

  const cvx = (x) => 90 + x * S;
  const cvy = (y) => 470 + y * S;
  const center = (el, device) => {
    const r = el.getBoundingClientRect(), d = device.getBoundingClientRect();
    return [(r.left + r.width / 2 - d.left) / S, (r.top + r.height / 2 - d.top) / S];
  };

  /** Titl rečenice: kratki komadi (do ~20 znakova), riječ po riječ, u ritmu trajanja rečenice. */
  function caption(root, line, until) {
    const words = line.text.split(/\s+/).filter(Boolean);
    const parts = [];
    let current = [];
    for (const word of words) {
      if (current.length > 0 && current.join(' ').length + word.length + 1 > 20) { parts.push(current); current = []; }
      current.push(word);
    }
    if (current.length > 0) parts.push(current);
    const total = parts.reduce((n, p) => n + p.join(' ').length, 0);
    const a = line.at, b = line.until;
    let done = 0;
    parts.forEach((part, index) => {
      const length = part.join(' ').length;
      const start = a + (done / total) * (b - a) - 0.05;
      const end = index === parts.length - 1 ? until : a + ((done + length) / total) * (b - a) - 0.05;
      done += length;
      const el = document.createElement('div');
      el.className = 'cap';
      el.innerHTML = '<div class="headline" style="font-size:98px"></div>';
      const headline = $('.headline', el);
      part.forEach((word, wi) => {
        const span = document.createElement('span');
        span.className = 'w';
        span.textContent = word;
        headline.appendChild(span);
        headline.appendChild(document.createTextNode(' '));
        const ws = Math.max(0, start + wi * 0.07);
        K(span, [[ws, { opacity: 0, translate: '0px 28px' }, EASE.out], [ws + 0.3, { opacity: 1, translate: '0px 0px' }, EASE.linear], [end - 0.14, { opacity: 1, translate: '0px 0px' }, EASE.in], [end + 0.02, { opacity: 0, translate: '0px -18px' }]]);
      });
      root.appendChild(el);
    });
  }

  /* ============================================================ 1. UDICA (u kadru 0) */
  function buildHook() {
    const dishes = D.hook.dishes;
    const headline = esc(D.hook.headline).replace(/^(\S+\s+\S+)\s+(.+)$/, '$1<br><em>$2</em>');
    $('#sHook').innerHTML = `
      ${dishes.map((dish, i) => `<img class="hbg" data-i="${i}" src="${dish.image}" alt="">`).join('')}
      <div id="hShade"></div>
      ${lockup}
      <div id="hHead"><div class="headline" style="font-size:${D.hook.headline.length > 24 ? 104 : 128}px">${headline}</div></div>
      ${dishes.map((dish, i) => `<div class="hcard" data-i="${i}" style="z-index:${i + 1}">
        <div class="ph"><img src="${dish.image}" alt=""><div class="nm">${esc(dish.title)}</div>${ai(D.aiLabel)}</div>
        <div class="hprice"><b>${esc(dish.big)}</b><span>${esc(dish.small)}</span></div></div>`).join('')}`;

    K('#hHead', [[0, { scale: 1.05 }, EASE.out], [0.6, { scale: 1 }]]);
    dishes.forEach((dish, i) => {
      const t = T.swaps[i], card = $(`.hcard[data-i="${i}"]`), tilt = [-2, 2.5, -1.5][i % 3];
      if (i === 0) {
        K(card, [[0, { rotate: `${tilt}deg`, scale: 1.04 }, EASE.out], [0.5, { rotate: `${tilt}deg`, scale: 1 }]]);
        K($('.hprice', card), [[0, { scale: 1.12 }, EASE.back], [0.35, { scale: 1 }]]);
      } else {
        K($(`.hbg[data-i="${i}"]`), [[t, { opacity: 0 }, EASE.soft], [t + 0.2, { opacity: 1 }]]);
        K(card, [[t, { opacity: 0, translate: '260px 60px', rotate: `${tilt + 9}deg`, scale: 0.9 }, EASE.out], [t + 0.04, { opacity: 1, translate: '200px 46px', rotate: `${tilt + 7}deg`, scale: 0.92 }, EASE.out], [t + 0.3, { opacity: 1, translate: '0px 0px', rotate: `${tilt}deg`, scale: 1 }]]);
        K($('.hprice', card), [[t + 0.1, { opacity: 0, scale: 0.4 }, EASE.backBig], [t + 0.36, { opacity: 1, scale: 1 }]]);
      }
      K($('.ph img', card), [[t, { scale: 1.14 }, EASE.linear], [t + 1.6, { scale: 1.02 }]]);
    });
  }

  /* ================================================================ 2. TELEFON */
  const tabbar = (on) => `<div class="tabbar">${['doc', 'search', 'cook', 'check', 'cog']
    .map((icon, k) => `<div class="tab ${k === on ? 'on' : ''}"><div class="pill">${I[icon]}</div><div class="lbl">${esc(D.ui.tabs[k])}</div></div>`).join('')}</div><div class="homebar"></div>`;

  function buildPhone() {
    const hero = D.hero, rows = D.list.rows;
    const card = `<div class="rc" id="card">
        <img class="im" src="${hero.image}" alt=""><div class="hrt">${I.heart}</div>${ai(D.aiLabel)}
        <div class="bd"><div class="tt">${esc(hero.title)}</div>${hero.summary ? `<div class="sm">${esc(hero.summary)}</div>` : ''}
          <div class="mt">${I.clock}<span>${esc(hero.meta)}</span></div>
          <div class="hr"></div>
          <div class="ch">${esc(hero.chainLine)}</div>
          <div class="pr"><b>${esc(hero.sum)}</b>${hero.old ? `<s>${esc(hero.old)}</s>` : ''}${hero.saving ? `<span class="sv">${esc(hero.saving)}</span>` : ''}</div>
          <div class="sl">${esc(hero.sumLabel)}</div>
        </div></div>`;
    const next = D.next ? `<div class="rc"><img class="im" src="${D.next.image}" alt=""><div class="hrt">${I.heart}</div>${ai(D.aiLabel)}</div>` : '';
    const feed = `<div class="screen" id="feed" style="background:var(--bg)">
        <div id="feedScroll">
          <div class="k-h1">${esc(D.ui.title)}</div>
          <div class="k-seg"><div class="on">${I.tag}${esc(D.ui.segA)}</div><div>${I.list}${esc(D.ui.segB)}</div></div>
          <div class="k-fil">${I.filter}${esc(D.ui.filters)}</div>
          <div id="feedCards">${card}${next}</div>
        </div><div class="sbar"></div>${tabbar(2)}</div>`;
    const lists = rows.length === 0 ? '' : `<div class="screen" id="lists">
        <div class="k-h1">${esc(D.ui.lists)}</div>
        <div class="l-chips"><div class="on">${esc(D.ui.mine)}</div><div>${esc(D.ui.shared)}</div></div>
        <div id="lcard">
          <div class="hd"><div class="mk">${I.cook}</div><div class="cn">${esc(hero.title)}</div></div>
          <div class="gl">${esc(D.ui.toBuy)} · ${rows.length}</div>
          ${rows.map((name, i) => `<div class="lr" data-i="${i}"><div class="ck">${I.check}</div><div class="nm">${esc(name)}</div><div class="tg">${esc(D.ui.onSale)}</div></div>`).join('')}
          <div id="ltot">✓ ${esc(D.ui.added)}</div>
        </div>${tabbar(3)}</div>`;

    $('#sPhone').innerHTML = `
      <div class="layer" id="cam" style="transform-origin:540px 1250px">
        <div class="phone" id="phone"><div class="dev" id="dev"><div class="island"></div>
          <div class="status"><span class="time">9:41</span><span class="icons"><span class="sig"><i></i><i></i><i></i><i></i></span><span class="batt"></span></span></div>
          ${feed}${lists}</div></div>
        <div id="fingers"></div>
      </div>
      <div id="topScrim"></div>
      <div id="pLk">${lockup}</div>
      <div id="caps"></div>`;

    const device = $('#dev');
    const cardEl = $('#card');
    // Sadržaj se pomakne tako da red sa zbrojem dođe na ~y 400 uređaja: sredina sigurne zone.
    const shift = Math.max(0, Math.round(center($('.pr', cardEl), device)[1] - 400));
    const title = center($('.tt', cardEl), device);
    const tap = [title[0] - 40, title[1] - shift];
    const exit = T.ctaAt;
    const listAt = T.listAt;

    // titlovi rečenica kartice i kraja; titl udice je njezin naslov
    const lines = T.captions;
    if (lines[1]) caption($('#caps'), lines[1], Math.min(lines[1].until + 0.25, T.tapAt + 0.1));
    if (lines[2] && listAt !== null) caption($('#caps'), { ...lines[2], until: Math.min(lines[2].until, exit) }, exit);

    K('#pLk', [[T.phoneAt + 0.15, { opacity: 0, translate: '0px -10px' }, EASE.out], [T.phoneAt + 0.5, { opacity: 1, translate: '0px 0px' }, EASE.linear], [exit, { opacity: 1 }, EASE.in], [exit + 0.3, { opacity: 0 }]]);
    K('#phone', [[T.phoneAt, { translate: '0px 1560px' }, EASE.out], [T.phoneAt + 0.7, { translate: '0px 0px' }, EASE.linear], [exit - 0.1, { translate: '0px 0px' }, EASE.in], [exit + 0.45, { translate: '0px 1900px' }]]);

    const PAN = listAt === null ? 0 : -300;
    const camera = [[T.scrollAt + 0.3, { scale: 1, translate: '0px 0px' }, EASE.inout], [T.pulses[0] + 0.1, { scale: 1.1, translate: '-26px -30px' }, EASE.linear]];
    if (listAt !== null) camera.push([T.tapAt + 0.05, { scale: 1.1, translate: '-26px -30px' }, EASE.inout], [T.tapAt + 0.7, { scale: 1, translate: `0px ${PAN}px` }]);
    K('#cam', camera);
    if (listAt !== null) K('#topScrim', [[T.tapAt + 0.05, { opacity: 0 }, EASE.soft], [T.tapAt + 0.5, { opacity: 1 }, EASE.linear], [exit, { opacity: 1 }, EASE.soft], [exit + 0.3, { opacity: 0 }]]);

    K('#feedScroll', [[T.scrollAt, { translate: '0px 0px' }, EASE.inout], [T.scrollAt + 0.6, { translate: `0px ${-shift}px` }]]);
    const pulse = (el, t, size) => { if (el) K(el, [[t - 0.04, { scale: 1 }, EASE.out], [t + 0.16, { scale: size }, EASE.soft], [t + 0.75, { scale: 1 }]]); };
    const chain = $('.ch', cardEl);
    pulse(chain, T.pulses[0], 1.16);
    K(chain, [[T.pulses[0] - 0.04, { color: '#6F7A67' }, EASE.out], [T.pulses[0] + 0.16, { color: '#2F6B45' }, EASE.soft], [T.pulses[0] + 1.2, { color: '#6F7A67' }]]);
    pulse($('.sv', cardEl), T.pulses[1], 1.22);
    pulse($('.pr b', cardEl), T.pulses[2], 1.3);

    // prst i dodir na karticu
    const finger = document.createElement('div');
    finger.className = 'finger';
    const ripple = document.createElement('div');
    ripple.className = 'ripple';
    ripple.style.left = `${cvx(tap[0])}px`;
    ripple.style.top = `${cvy(tap[1])}px`;
    $('#fingers').append(finger, ripple);
    const at = `${cvx(tap[0])}px ${cvy(tap[1])}px`;
    K(finger, [[T.tapAt - 0.5, { translate: `${cvx(tap[0]) + 120}px ${cvy(tap[1]) + 220}px`, opacity: 0 }, EASE.out], [T.tapAt - 0.12, { translate: at, opacity: 1 }, EASE.linear], [T.tapAt + 0.25, { translate: at, opacity: 1 }, EASE.soft], [T.tapAt + 0.45, { translate: at, opacity: 0 }]]);
    K(finger, [[T.tapAt - 0.03, { scale: 1 }, EASE.out], [T.tapAt + 0.06, { scale: 0.78 }, EASE.out], [T.tapAt + 0.22, { scale: 1 }]]);
    K(ripple, [[T.tapAt - 0.01, { opacity: 0, scale: 0.55 }, EASE.linear], [T.tapAt, { opacity: 0.95, scale: 0.55 }, EASE.out], [T.tapAt + 0.45, { opacity: 0, scale: 1.9 }]]);
    K(cardEl, [[T.tapAt - 0.02, { scale: 1 }, EASE.out], [T.tapAt + 0.08, { scale: 0.975 }, EASE.back], [T.tapAt + 0.3, { scale: 1 }]]);

    if (listAt !== null) {
      K('#lists', [[listAt, { opacity: 0, translate: '0px 70px' }, EASE.out], [listAt + 0.4, { opacity: 1, translate: '0px 0px' }]]);
      T.rowsAt.forEach((t, i) => {
        const row = $(`.lr[data-i="${i}"]`);
        if (!row) return;
        K(row, [[t, { opacity: 0, translate: '0px 22px' }, EASE.out], [t + 0.3, { opacity: 1, translate: '0px 0px' }]]);
        K($('.ck', row), [[t + 0.1, { scale: 0.2 }, EASE.backBig], [t + 0.4, { scale: 1 }]]);
      });
      const last = T.rowsAt[T.rowsAt.length - 1] ?? listAt;
      K('#ltot', [[last + 0.2, { opacity: 0, scale: 0.9 }, EASE.back], [last + 0.55, { opacity: 1, scale: 1.12 }, EASE.soft], [last + 1.1, { opacity: 1, scale: 1 }]]);
    }
  }

  /* ================================================================ 3. POZIV */
  function buildEnd() {
    const t = T.ctaAt;
    $('#sEnd').innerHTML = `
      <img class="abs" id="eIcon" src="${D.brand.icon}" alt="">
      <div class="abs" id="eWord">${esc(D.brand.app)}</div>
      <div class="abs" id="eLine">${esc(D.cta.line)}</div>
      <div class="abs" id="eBtn">${esc(D.cta.button)}</div>
      <div class="abs" id="eLink">${esc(D.cta.link)}</div>
      <div class="abs" id="eStores">App Store · Google Play</div>
      ${D.cta.aiNote ? `<div class="abs" id="eAi">${esc(D.cta.aiNote)}</div>` : ''}`;
    const enter = (selector, at, from = '0px 40px') => K(selector, [[at, { opacity: 0, translate: from, scale: 0.94 }, EASE.out], [at + 0.45, { opacity: 1, translate: '0px 0px', scale: 1 }]]);
    K('#eIcon', [[t + 0.05, { opacity: 0, scale: 0.4, translate: '0px 50px' }, EASE.backBig], [t + 0.5, { opacity: 1, scale: 1, translate: '0px 0px' }]]);
    enter('#eWord', t + 0.12);
    enter('#eLine', t + 0.3);
    K('#eBtn', [[t + 0.5, { opacity: 0, translate: '0px 60px', scale: 0.85 }, EASE.back], [t + 1.0, { opacity: 1, translate: '0px 0px', scale: 1 }]]);
    enter('#eLink', t + 0.8);
    enter('#eStores', t + 0.95);
    if (D.cta.aiNote) enter('#eAi', t + 1.0, '0px 0px');
  }


  /** Nacrta trenutak t i vrati ključ stanja: isti ključ znači istu sličicu, pa se mirni kraj snima jednom. */
  function renderAt(t) {
    for (const animation of tracks) animation.currentTime = t * 1000;
    for (const fn of frameFns) fn(t);

    return t >= T.ctaAt + 1.6 ? 'end' : `t${Math.round(t * 1000)}`;
  }
  window.renderAt = renderAt;

  window.sceneReady = (async () => {
    await document.fonts.ready;
    await Promise.all(['700 100px Fraunces', '400 40px Figtree', '500 40px Figtree', '600 40px Figtree', '700 40px Figtree', '800 40px Figtree'].map((font) => document.fonts.load(font, 'Aač Ćđšž äöüß €')));
    // Scena se slaže tek kad su fontovi tu: pomak sadržaja i mjesto dodira mjere se na stvarnom tekstu.
    K('#bgPhone', [[T.hookEnd, { clipPath: 'circle(0% at 540px 960px)' }, EASE.inout], [T.hookEnd + 0.6, { clipPath: 'circle(130% at 540px 960px)' }]]);
    K('#bgEnd', [[T.ctaAt, { opacity: 0 }, EASE.soft], [T.ctaAt + 0.4, { opacity: 1 }]]);
    buildHook();
    buildPhone();
    buildEnd();
    await Promise.all([...document.images].map((image) => (image.complete ? 1 : new Promise((resolve) => { image.onload = image.onerror = resolve; }))));
    renderAt(0);
  })();
})();
