/* ============================================================================
   Unified film-grain / noise  —  shared across all NUDOT pages
   ========================================================================== */
(function () {
  'use strict';

  var canvas = document.getElementById('bg--noise') ||
               document.getElementById('film-grain-canvas');

  if (window.innerWidth <= 768 ||
      window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    if (canvas) canvas.style.display = 'none';
    return;
  }
  if (!canvas || !canvas.getContext) return;
  var ctx = canvas.getContext('2d');
  if (!ctx) return;

  // ── tuneable constants ────────────────────────────────────────────────────
  var GRAIN_OPACITY   = 0.06;
  var GRAIN_DENSITY   = 0.7;   // 0–1 fraction of pixels lit
  var FRAMES          = 10;    // pre-baked frames
  var FPS             = 25;    // normal playback
  var FPS_HOLD        = 12;    // playback while scroll is cooling
  var SCROLL_HOLD_MS  = 180;
  var RESIZE_DEBOUNCE = 160;
  // ─────────────────────────────────────────────────────────────────────────

  Object.assign(canvas.style, {
    display:       'block',
    position:      'fixed',
    top:           '0',
    left:          '0',
    width:         '100vw',
    height:        '100vh',
    zIndex:        '9999',
    pointerEvents: 'none',
    mixBlendMode:  'screen',
    transform:     'translateZ(0)',
    willChange:    'opacity',
    contain:       'strict',
    opacity:       String(GRAIN_OPACITY),
  });

  var wW = 0, wH = 0;
  var frames = [], frameIdx = 0;
  var rafId = 0, timerId = 0, resizeTimer = 0;
  var scrollHoldUntil = 0;
  var running = false;

  function bakeFrames() {
    frames = [];
    for (var i = 0; i < FRAMES; i++) {
      var idata = ctx.createImageData(wW, wH);
      var buf   = new Uint32Array(idata.data.buffer);
      for (var p = 0; p < buf.length; p++) {
        if (Math.random() < GRAIN_DENSITY) buf[p] = 0xffffffff;
      }
      frames.push(idata);
    }
  }

  function tick() {
    if (!running) return;
    rafId = 0;
    var cooling = performance.now() < scrollHoldUntil;
    if (!cooling && document.visibilityState !== 'hidden') {
      frameIdx = (frameIdx + 1) % FRAMES;
      ctx.putImageData(frames[frameIdx], 0, 0);
    }
    timerId = window.setTimeout(function () {
      rafId = window.requestAnimationFrame(tick);
    }, 1000 / (cooling ? FPS_HOLD : FPS));
  }

  function stop() {
    running = false;
    window.clearTimeout(timerId);
    if (rafId) { window.cancelAnimationFrame(rafId); rafId = 0; }
  }

  function start() {
    if (running) return;
    running = true;
    rafId = window.requestAnimationFrame(tick);
  }

  function setup() {
    stop();
    wW = canvas.width  = window.innerWidth;
    wH = canvas.height = window.innerHeight;
    ctx.clearRect(0, 0, wW, wH);
    bakeFrames();
    start();
  }

  function holdGrain() {
    scrollHoldUntil = performance.now() + SCROLL_HOLD_MS;
  }

  // public API + legacy aliases
  window._holdFilmGrain                 = holdGrain;
  window._holdLabsFilmGrainDuringScroll = holdGrain;
  window._holdWorkFilmGrain             = holdGrain;

  window.addEventListener('resize', function () {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(setup, RESIZE_DEBOUNCE);
  }, { passive: true });

  window.addEventListener('scroll', holdGrain, { passive: true });

  window.setTimeout(function () {
    if (window._lenis) window._lenis.on('scroll', holdGrain);
  }, 300);

  document.addEventListener('visibilitychange', function () {
    document.hidden ? stop() : start();
  });

  setup();
})();
