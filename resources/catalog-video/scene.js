/*
 * Kadar videa „izašao je novi katalog“ za trenutak t.
 *
 * Sve što se vidi je funkcija od t i od `window.SCENE` (data.js): kamera (izrez zaslona), listanje, pokazivač,
 * označene kartice, obavijest, popis, završna kartica. Vremena stižu iz plana (App\Catalog\CatalogVideoPlan)
 * i ista su ona na kojima se u mix stavlja zvuk dodavanja, pa reakcija aplikacije pada na sličicu zvuka.
 */
(() => {
  const S = window.SCENE;
  const T = S.timeline;
  const $ = (selector) => document.querySelector(selector);

  const PHONE = { w: 402, h: 874 };
  /** Gdje stoji ploča sa zaslonom: sredina kolone, ispod naslova (kao u videu A). */
  const MAIN = { cx: 490, top: 476, maxW: 840, maxH: 1010 };
  /** Na završnoj kartici ploča je manja i niže, ispod naslova. */
  const END = { cx: 490, top: 690, maxW: 640, maxH: 600 };
  const TOAST_W = 370;
  const TOAST_MARGIN = 26;

  const clamp = (x, a = 0, b = 1) => Math.max(a, Math.min(b, x));
  const smooth = (x) => { x = clamp(x); return x * x * (3 - 2 * x); };
  const inOutQuad = (x) => { x = clamp(x); return x < 0.5 ? 2 * x * x : 1 - Math.pow(-2 * x + 2, 2) / 2; };
  const outCubic = (x) => 1 - Math.pow(1 - clamp(x), 3);
  const lerp = (a, b, k) => a + (b - a) * k;
  const r3 = (x) => Math.round(x * 1000) / 1000;

  // ── podaci ────────────────────────────────────────────────────────
  const price = (cents) => new Intl.NumberFormat(S.locale, { style: 'currency', currency: S.currency }).format(cents / 100);
  const MEASURE = { g: 'g', kg: 'kg', ml: 'ml', l: 'l', pc: 'kom' };
  const unitLabel = (unit) => (unit ? `${new Intl.NumberFormat(S.locale, { maximumFractionDigits: 3 }).format(unit.amount)} ${MEASURE[unit.measure] || unit.measure}` : null);
  const CHAIN_COLORS = { konzum: '#C8102E', kaufland: '#E10915', lidl: '#0050AA', plodine: '#009444', eurospin: '#005CA9', dm: '#002878', muller: '#E30613', bipa: '#E2007A', spar: '#00843D', tommy: '#D2232A' };

  // Geometrija stranice u zaslonu: širina zaslona, visina po omjeru, sredina po visini (contentFit="contain").
  const geometry = S.pages.map((page) => {
    const h = PHONE.w * page.h / page.w;
    return { top: (PHONE.h - h) / 2, h };
  });
  const pageIndex = (number) => S.pages.findIndex((page) => page.number === number);

  // ── izgradnja DOM-a ───────────────────────────────────────────────
  document.documentElement.style.setProperty('--cream', S.theme.cream);
  document.documentElement.style.setProperty('--green', S.theme.green);
  document.documentElement.style.setProperty('--ink', S.theme.ink);
  document.documentElement.style.setProperty('--soft', S.theme.soft);

  const backdropCircles = (dark) => `
    radial-gradient(circle 325px at 1065px 125px, rgba(${dark ? '244,241,233,.032' : '47,107,69,.036'}), rgba(0,0,0,0) 100%),
    radial-gradient(circle 440px at 100px 1860px, rgba(${dark ? '244,241,233,.028' : '47,107,69,.028'}), rgba(0,0,0,0) 100%)`;
  $('#backdrop').style.background = `${backdropCircles(false)}, ${S.theme.cream}`;

  $('#lockup-icon').src = S.brand.icon;
  $('#cta-icon').src = S.brand.icon;
  $('#lockup-name').textContent = S.brand.name;
  $('#cta-name').textContent = S.brand.name;
  $('#lockup-site').textContent = S.brand.site;
  $('#cta-site').textContent = S.brand.site;

  const titleOf = (lines, chip) => {
    const el = document.createElement('div');
    el.className = 'title';
    el.innerHTML = lines.map((line) => `<div>${escapeHtml(line)}</div>`).join('') + (chip ? `<div class="chip">${escapeHtml(chip)}</div>` : '');
    $('#titles').appendChild(el);
    return el;
  };
  const titles = [
    titleOf(S.title, S.validity),
    titleOf(S.titles.tap),
    titleOf(S.titles.list),
  ];

  $('#chain-name').textContent = S.chain.name;
  $('#list-chain-name').textContent = S.chain.name;
  $('#chain-mark').innerHTML = S.chain.logo
    ? `<img src="${S.chain.logo}" alt="">`
    : escapeHtml(initials(S.chain.name));
  if (!S.chain.logo) $('#chain-mark').style.background = CHAIN_COLORS[S.chain.slug] || '#C8102E';

  const track = $('#track');
  track.style.width = `${S.pages.length * PHONE.w}px`;
  const marks = [];
  const flashes = [];

  S.pages.forEach((page, index) => {
    const el = document.createElement('div');
    el.className = 'page';
    const img = document.createElement('img');
    img.src = page.src;
    img.style.top = `${geometry[index].top}px`;
    img.style.height = `${geometry[index].h}px`;
    el.appendChild(img);

    S.taps.forEach((tap, tapIndex) => {
      if (tap.page !== page.number) return;
      const rect = {
        left: tap.bbox.x * PHONE.w,
        top: geometry[index].top + tap.bbox.y * geometry[index].h,
        width: tap.bbox.w * PHONE.w,
        height: tap.bbox.h * geometry[index].h,
      };
      const place = (node) => Object.assign(node.style, { left: `${rect.left}px`, top: `${rect.top}px`, width: `${rect.width}px`, height: `${rect.height}px` });

      const mark = document.createElement('div');
      mark.className = 'mark';
      place(mark);
      mark.style.display = 'none';
      mark.innerHTML = '<div class="badge"><svg viewBox="0 0 14 14" width="11" height="11"><polyline points="3,7.4 6,10.2 11,4.2" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></div>';
      el.appendChild(mark);
      marks[tapIndex] = mark;

      const flash = document.createElement('div');
      flash.className = 'flash';
      place(flash);
      el.appendChild(flash);
      flashes[tapIndex] = flash;
    });

    track.appendChild(el);
  });

  $('#counter').textContent = `1 / ${S.pageCount}`;

  // Redovi liste: novije prvo (server ih vraća `order by position, added_at desc`).
  const rows = $('#rows');
  [...S.taps].reverse().forEach((tap) => {
    const row = document.createElement('div');
    row.className = 'row';
    const unit = unitLabel(tap.unit);
    row.innerHTML = `
      <div class="check"></div>
      <div class="main">
        <div class="crop" style="background-image:url('${tap.crop}')"></div>
        <div class="text">${tap.brand ? `<div class="brand">${escapeHtml(tap.brand)}</div>` : ''}<div class="name">${escapeHtml(tap.name)}</div>${unit ? `<div class="unit">${escapeHtml(unit)}</div>` : ''}</div>
        <div class="price">${escapeHtml(price(tap.priceCents))}</div>
      </div>`;
    rows.appendChild(row);
  });
  $('#total-value').textContent = price(S.totalCents);

  $('#cta-headline').textContent = S.cta.headline;
  $('#cta-link').textContent = S.cta.link;
  $('#cta-button').textContent = S.cta.headline;

  const captionBox = $('#caption');
  const captionText = $('#caption span');
  const toast = $('#toast');
  const stage = $('#stage');
  const phone = $('#phone');
  const viewer = $('#viewer');
  const lists = $('#lists');
  const status = $('#statusbar');
  const pointer = $('#pointer');
  const ctaEls = ['#cta-headline', '#cta-link', '#cta-button', '#cta-stores'].map($);

  // ── kamera ────────────────────────────────────────────────────────
  const FULL = { x: 0, y: 0, w: PHONE.w, h: PHONE.h };
  const cover = geometry[0];
  const PAPER = { x: 0, y: cover.top, w: PHONE.w, h: cover.h };
  const LIST = { x: 0, y: 72, w: PHONE.w, h: 540 };
  const LIST_CARD = { x: 8, y: 124, w: 386, h: 488 };

  /** Okvir oko triju kartica na stranici, proširen i svučen na omjer koji daje pristojnu ploču. */
  function around(cards) {
    const boxes = cards.map((tap) => {
      const g = geometry[pageIndex(tap.page)];
      return { x: tap.bbox.x * PHONE.w, y: g.top + tap.bbox.y * g.h, w: tap.bbox.w * PHONE.w, h: tap.bbox.h * g.h, g };
    });
    const x0 = Math.min(...boxes.map((b) => b.x));
    const y0 = Math.min(...boxes.map((b) => b.y));
    const x1 = Math.max(...boxes.map((b) => b.x + b.w));
    const y1 = Math.max(...boxes.map((b) => b.y + b.h));
    const g = boxes[0].g;
    let w = Math.max(190, (x1 - x0) * 1.5);
    let h = Math.max(230, (y1 - y0) * 1.4);
    // Omjer ploče: ne uži od 0.62 ni širi od 1.1.
    if (w / h < 0.62) w = h * 0.62;
    if (w / h > 1.1) h = w / 1.1;
    w = Math.min(w, PHONE.w);
    h = Math.min(h, g.h + 24);
    const cx = (x0 + x1) / 2;
    const cy = (y0 + y1) / 2;
    return {
      x: clamp(cx - w / 2, 0, PHONE.w - w),
      y: clamp(cy - h / 2, g.top - 4, g.top + g.h + 4 - h),
      w,
      h,
    };
  }

  const zoomTaps = S.taps.slice(1);
  const zoomable = T.zoom && zoomTaps.length > 0 && new Set(zoomTaps.map((tap) => tap.page)).size === 1;
  const CLOSE = zoomable ? around(zoomTaps) : PAPER;

  const mixBox = (a, b, k) => ({ x: lerp(a.x, b.x, k), y: lerp(a.y, b.y, k), w: lerp(a.w, b.w, k), h: lerp(a.h, b.h, k) });

  function roiAt(t) {
    let roi = FULL;
    roi = mixBox(roi, PAPER, smooth((t - T.camera.paperAt) / T.camera.paperDur));
    if (zoomable) roi = mixBox(roi, CLOSE, smooth((t - T.zoom.at) / T.zoom.dur));
    // Pogled se širi na cijeli zaslon prije povratka na popis, pa se skuplja na karticu popisa.
    const out = smooth((t - T.nav.at) / T.nav.pullDur);
    if (out > 0) roi = mixBox(zoomable ? CLOSE : PAPER, FULL, out);
    const inn = smooth((t - T.list.at) / T.list.settleDur);
    if (inn > 0) roi = mixBox(FULL, LIST, inn);
    const end = smooth((t - T.cta.at) / T.cta.dur);
    if (end > 0) roi = mixBox(LIST, LIST_CARD, end);
    return { roi, end };
  }

  function layout(t) {
    const { roi, end } = roiAt(t);
    const place = mixBox({ x: MAIN.cx, y: MAIN.top, w: MAIN.maxW, h: MAIN.maxH }, { x: END.cx, y: END.top, w: END.maxW, h: END.maxH }, end);
    const s = Math.min(place.w / roi.w, place.h / roi.h);
    const ow = roi.w * s;
    const oh = roi.h * s;
    return { roi, end, s, ow, oh, px: place.x - ow / 2, py: place.y + (place.h - oh) / 2 };
  }

  const toFrame = (L, x, y) => [L.px + (x - L.roi.x) * L.s, L.py + (y - L.roi.y) * L.s];

  // ── pokazivač ─────────────────────────────────────────────────────
  const pagePoint = (tap) => {
    const g = geometry[pageIndex(tap.page)];
    return [tap.point.x * PHONE.w, g.top + tap.point.y * g.h];
  };

  function pointerKeys() {
    const keys = [];
    const push = (t, x, y, press, alpha, bow = 0) => keys.push({ t, x, y, press, alpha, bow });
    const events = [
      ...T.swipes.map((swipe) => ({ kind: 'swipe', at: swipe.at, swipe })),
      ...T.taps.map((tap, index) => ({ kind: 'tap', at: tap.at, index })),
    ].sort((a, b) => a.at - b.at);

    const first = events[0];
    const START = [PHONE.w - 70, cover.top + cover.h - 60];
    const appear = Math.max(0.35, first.at - (first.kind === 'tap' ? 1.15 : 0.7));
    push(appear, START[0], START[1], 0, 0);
    push(appear + 0.25, START[0], START[1], 0, 1);

    let last = null;
    events.forEach((event, order) => {
      if (event.kind === 'swipe') {
        const y = cover.top + cover.h * 0.55;
        const from = [PHONE.w - 90, y];
        const to = [90, y];
        push(event.at - 0.2, from[0], from[1], 0, 1, order === 0 ? 40 : 0);
        push(event.at - 0.06, from[0], from[1], 1, 1);
        push(event.at + event.swipe.dur, to[0], to[1], 1, 1);
        push(event.at + event.swipe.dur + 0.06, to[0], to[1], 0, 1);
        last = to;
        return;
      }

      const tap = S.taps[event.index];
      const [x, y] = pagePoint(tap);
      const firstTap = event.index === 0;
      push(event.at - (firstTap ? 0.3 : 0.16), x, y, 0, 1, firstTap ? 55 : 0);
      push(event.at - 0.1, x, y, 1, 1);
      push(event.at, x, y, 1, 1);
      push(event.at + 0.16, x, y, 0, 1);
      last = [x, y];
    });

    const done = events[events.length - 1].at + 0.22;
    push(done, last[0], last[1], 0, 1);
    push(done + 0.55, last[0] + 50, last[1] + 70, 0, 0);

    return keys;
  }

  const KEYS = pointerKeys();

  function pointerAt(t) {
    if (t <= KEYS[0].t) return { x: KEYS[0].x, y: KEYS[0].y, press: 0, alpha: 0 };
    for (let i = 1; i < KEYS.length; i++) {
      if (t <= KEYS[i].t) {
        const a = KEYS[i - 1];
        const b = KEYS[i];
        const k = smooth((t - a.t) / Math.max(1e-6, b.t - a.t));
        const lin = clamp((t - a.t) / Math.max(1e-6, b.t - a.t));
        // Blagi luk, ne ravna crta: pokazivač tek stiže na mjesto.
        const bow = Math.sin(Math.PI * k) * b.bow;
        return { x: lerp(a.x, b.x, k) - bow, y: lerp(a.y, b.y, k), press: lerp(a.press, b.press, smooth(lin)), alpha: lerp(a.alpha, b.alpha, smooth(lin)) };
      }
    }
    const end = KEYS[KEYS.length - 1];
    return { x: end.x, y: end.y, press: 0, alpha: 0 };
  }

  // ── titlovi ───────────────────────────────────────────────────────
  S.pageNumberAt = (p) => S.pages[Math.min(S.pages.length - 1, Math.round(p))].number;
  const captionAt = (t) => (S.timeline.captions.find((c) => t >= c.at && t < c.until) || null);

  // ── jedan kadar ───────────────────────────────────────────────────
  const dim = { toastH: 56 };

  function renderAt(t) {
    const L = layout(t);
    const st = {};

    // Okvir (izrez zaslona).
    stage.style.left = `${L.px}px`;
    stage.style.top = `${L.py}px`;
    stage.style.width = `${L.ow}px`;
    stage.style.height = `${L.oh}px`;
    stage.style.borderRadius = '28px';
    phone.style.transform = `translate(${-L.roi.x * L.s}px, ${-L.roi.y * L.s}px) scale(${L.s})`;
    st.layout = [L.px, L.py, L.ow, L.oh, L.s, L.roi.x, L.roi.y].map(r3);

    // Listanje: indeks stranice kao decimalni broj, svako listanje je jedan korak.
    let p = 0;
    T.swipes.forEach((swipe, index) => { if (t >= swipe.at) p = Math.max(p, index + smooth((t - swipe.at) / swipe.dur)); });
    p = Math.min(p, S.pages.length - 1);
    track.style.transform = `translateX(${-p * PHONE.w}px)`;
    $('#counter').textContent = `${S.pageNumberAt(p)} / ${S.pageCount}`;
    st.p = r3(p);

    // Označene kartice i bljesak.
    st.marks = [];
    st.flash = [];
    S.taps.forEach((tap, index) => {
      const at = T.taps[index].at;
      const shown = t >= at;
      marks[index].style.display = shown ? 'block' : 'none';
      st.marks.push(shown ? 1 : 0);

      const k = (t - at) / 0.62;
      const opacity = k >= 0 && k <= 1 ? 1 - inOutQuad(k) : 0;
      flashes[index].style.opacity = String(r3(opacity));
      st.flash.push(r3(opacity));
    });

    // Zaslon: letak, pa popis (križni prijelaz).
    const screen = smooth((t - T.list.at) / T.list.fadeDur);
    viewer.style.opacity = String(r3(1 - screen));
    lists.style.opacity = '1';
    lists.style.zIndex = '0';
    viewer.style.zIndex = '1';
    viewer.style.display = screen >= 1 ? 'none' : 'block';
    status.className = screen > 0.5 ? 'dark' : '';
    st.screen = r3(screen);

    // Obavijest „Dodano u listu“: zadnja započeta; nova zamjenjuje staru (Toast.tsx resetira napredak).
    let current = -1;
    T.taps.forEach((tap, index) => { if (t >= tap.at) current = index; });
    let progress = 0;
    if (current >= 0) {
      const u = t - T.taps[current].at;
      progress = u < 0.22 ? inOutQuad(u / 0.22) : u < 1.72 ? 1 : u < 1.98 ? 1 - inOutQuad((u - 1.72) / 0.26) : 0;
    }
    // Na popisu i završnoj kartici obavijesti nema: dodavanje je gotovo.
    progress *= 1 - smooth((t - (T.nav.at - 0.05)) / 0.2);
    if (progress > 0.001) {
      const tap = S.taps[current];
      $('.toast-detail').textContent = [tap.brand, tap.name].filter(Boolean).join(' ');
      const hs = L.ow / PHONE.w;
      const sc = hs * (0.96 + 0.04 * progress);
      const wpx = TOAST_W * sc;
      toast.style.width = `${TOAST_W}px`;
      toast.style.opacity = String(r3(progress));
      toast.style.transform = `translate(${(L.ow - wpx) / 2}px, ${L.oh - TOAST_MARGIN - dim.toastH * sc + (1 - progress) * 24 * hs}px) scale(${sc})`;
      toast.style.transformOrigin = '0 0';
    } else {
      toast.style.opacity = '0';
    }
    st.toast = [current, r3(progress), r3(L.ow)];

    // Popis: prsten oko „Ukupno“ nakon što se vidi cijeli.
    const ring = smooth((t - (T.list.at + 0.55)) / 0.25) * (1 - smooth((t - T.cta.at) / 0.25));
    $('#total-ring').style.opacity = String(r3(ring));
    st.ring = r3(ring);

    // Naslovi.
    const fadeIn = (from, d = 0.22) => smooth((t - from) / d);
    const a1 = (1 - fadeIn(T.titles.tapAt - 0.05));
    const a2 = fadeIn(T.titles.tapAt + 0.05) * (1 - fadeIn(T.titles.listAt - 0.05));
    const a3 = fadeIn(T.titles.listAt + 0.05) * (1 - fadeIn(T.cta.at - 0.1, 0.2));
    [a1, a2, a3].forEach((a, i) => { titles[i].style.opacity = String(r3(a)); });
    st.titles = [a1, a2, a3].map(r3);

    // Završna kartica.
    const end = smooth((t - T.cta.at) / T.cta.dur);
    $('#cta-bg').style.opacity = String(r3(end));
    $('#backdrop').style.opacity = String(r3(1 - end));
    $('#lockup').style.opacity = String(r3(1 - end));
    $('#cta-lockup').style.opacity = String(r3(end));
    const textIn = [0, 0.12, 0.22, 0.22].map((delay) => smooth((t - T.cta.at - delay) / 0.35));
    ctaEls.forEach((el, i) => { el.style.opacity = String(r3(textIn[i])); });
    st.cta = [r3(end), ...textIn.map(r3)];

    // Titl glasa.
    const caption = captionAt(t);
    if (caption) {
      captionText.textContent = caption.text;
      captionBox.style.display = 'block';
    } else {
      captionBox.style.display = 'none';
    }
    st.caption = caption ? caption.text : '';

    // Pokazivač (u pikselima kadra, preko svega).
    const ptr = pointerAt(t);
    if (ptr.alpha > 0.002 && t < T.cta.at) {
      const [fx, fy] = toFrame(L, ptr.x, ptr.y);
      const r = 38 - 9 * ptr.press;
      pointer.style.opacity = String(r3(ptr.alpha));
      pointer.style.transform = `translate(${fx}px, ${fy}px)`;
      const body = pointer.querySelector('.body');
      Object.assign(body.style, { width: `${2 * r}px`, height: `${2 * r}px`, left: `${-r}px`, top: `${-r}px`, background: `rgba(255,255,255,${(214 + 30 * ptr.press) / 255})` });
      const dot = body.querySelector('.dot');
      const dr = r * 0.30;
      Object.assign(dot.style, { width: `${2 * dr}px`, height: `${2 * dr}px`, opacity: String((95 + 70 * ptr.press) / 255) });
      const shadow = pointer.querySelector('.shadow');
      const dy = 10 * (1 - 0.6 * ptr.press);
      Object.assign(shadow.style, { width: `${2 * r}px`, height: `${2 * r}px`, left: `${-r}px`, top: `${-r + dy}px` });

      // Prsten pri otpuštanju svakog dodira.
      let ringK = -1;
      T.taps.forEach((tap) => { const k = (t - tap.at) / 0.5; if (k >= 0 && k <= 1) ringK = k; });
      for (const [cls, extra, color] of [['.ring-white', 13, '255,255,255'], ['.ring-green', 7, '47,107,69']]) {
        const el = pointer.querySelector(cls);
        if (ringK < 0) { el.style.opacity = '0'; continue; }
        const rr = 30 + 62 * outCubic(ringK);
        const width = extra * (1 - 0.4 * ringK);
        Object.assign(el.style, { width: `${2 * rr}px`, height: `${2 * rr}px`, left: `${-rr}px`, top: `${-rr}px`, borderWidth: `${width}px`, borderColor: `rgba(${color},${(235 * (1 - smooth(ringK))) / 255})`, opacity: '1' });
      }
      st.ptr = [fx, fy, ptr.press, ptr.alpha, ringK].map(r3);
    } else {
      pointer.style.opacity = '0';
      st.ptr = 0;
    }

    return JSON.stringify(st);
  }

  window.renderAt = renderAt;

  window.sceneReady = (async () => {
    await document.fonts.ready;
    await Promise.all([...document.images].map((img) => (img.complete ? Promise.resolve() : new Promise((resolve) => { img.onload = img.onerror = resolve; }))));
    await Promise.all([...document.images].map((img) => img.decode().catch(() => undefined)));
    toast.style.width = `${TOAST_W}px`;
    toast.querySelector('.toast-detail').textContent = 'Naziv proizvoda';
    dim.toastH = toast.offsetHeight || 56;
    return true;
  })();

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  }

  function initials(name) {
    const words = name.trim().split(/\s+/);
    return (words.length === 1 ? words[0].slice(0, 2) : words[0][0] + words[1][0]).toUpperCase();
  }
})();
