(function () {
  'use strict';

  var TRANSITION_KEY = 'nudot:page-transition';
  var TRANSITION_TTL = 12000;
  var gsap = window.gsap;
  var state = {
    leaving: false,
    entryCleanupTimer: null,
    entryFailSafeTimer: null,
  };
  var sharedMenuState = {
    open: false,
    animating: false,
    openTl: null,
    closeTl: null,
    phaseTimer: null,
    finishTimer: null,
  };

  function readPayload() {
    try {
      var raw = window.sessionStorage.getItem(TRANSITION_KEY);
      if (!raw) return null;
      var payload = JSON.parse(raw);
      if (!payload || !payload.at || Date.now() - payload.at > TRANSITION_TTL) {
        window.sessionStorage.removeItem(TRANSITION_KEY);
        return null;
      }
      return payload;
    } catch (error) {
      try {
        window.sessionStorage.removeItem(TRANSITION_KEY);
      } catch (ignored) { }
      return null;
    }
  }

  function consumePayload() {
    var payload = readPayload();
    if (!payload) return null;
    try {
      window.sessionStorage.removeItem(TRANSITION_KEY);
    } catch (ignored) { }
    return payload;
  }

  function normalizePathname(pathname) {
    return pathname.replace(/\/index\.html$/i, '/').replace(/\/$/, '') || '/';
  }

  function isSameDocument(url) {
    var current = normalizePathname(window.location.pathname);
    var next = normalizePathname(url.pathname);
    return current === next;
  }

  function getLenis() {
    return window._lenis || null;
  }

  function stopLenis() {
    var lenis = getLenis();
    if (!lenis || typeof lenis.stop !== 'function') return;
    try {
      lenis.stop();
    } catch (error) { }
  }

  function scrollToTarget(target) {
    if (!target) return;
    var lenis = getLenis();
    if (lenis && typeof lenis.scrollTo === 'function') {
      try {
        lenis.scrollTo(target, { duration: 1.15, offset: -24 });
        return;
      } catch (error) { }
    }
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function getShellRefs() {
    return {
      shell: document.querySelector('.js-page-transition-shell'),
      curtain: document.querySelector('.js-page-transition-curtain'),
      frame: document.querySelector('.js-page-transition-frame'),
      loader: document.querySelector('.js-page-transition-loader'),
      counter: document.querySelector('.js-page-transition-counter'),
      counterNum: document.querySelector('.js-page-transition-counter-num'),
      panel: document.querySelector('.js-page-transition-panel'),
      eyebrow: document.querySelector('.js-page-transition-eyebrow'),
      title: document.querySelector('.js-page-transition-title'),
      subtitle: document.querySelector('.js-page-transition-subtitle'),
      metaTo: document.querySelector('.js-page-transition-meta-to'),
    };
  }

  // ── Pixel 轉場（取代原本的 curtain 動畫，效果來自 PixelTransition demo2）──
  var pixelGrid = {
    el: null,
    cells: [],
    rows: 9,
    columns: 17,
    built: false
  };

  function buildPixelGrid(shell) {
    if (!shell) return null;
    if (pixelGrid.built && pixelGrid.el && pixelGrid.el.isConnected) return pixelGrid;

    var el = document.createElement('div');
    el.className = 'page-transition-pixels';
    el.setAttribute('aria-hidden', 'true');
    el.style.position = 'absolute';
    el.style.inset = '0';
    el.style.display = 'grid';
    el.style.gridTemplateColumns = 'repeat(' + pixelGrid.columns + ', 1fr)';
    el.style.gridTemplateRows = 'repeat(' + pixelGrid.rows + ', 1fr)';
    el.style.opacity = '0';
    el.style.pointerEvents = 'none';

    var cells = [];
    var total = pixelGrid.rows * pixelGrid.columns;
    for (var i = 0; i < total; i++) {
      var cell = document.createElement('div');
      cell.style.background = 'var(--pt-bg, #000)';
      cell.style.willChange = 'transform, opacity';
      el.appendChild(cell);
      cells.push(cell);
    }

    shell.appendChild(el);
    pixelGrid.el = el;
    pixelGrid.cells = cells;
    pixelGrid.built = true;
    return pixelGrid;
  }

  function pixelStagger(from, each) {
    return {
      grid: [pixelGrid.rows, pixelGrid.columns],
      from: from,
      each: each
    };
  }

  // 顯示 overlay：cell 從 0 放大蓋滿畫面
  function pixelShow(config) {
    return new Promise(function (resolve) {
      if (!gsap || !pixelGrid.el) { resolve(); return; }
      gsap.set(pixelGrid.el, { opacity: 1 });
      gsap.fromTo(pixelGrid.cells, {
        scale: 0,
        opacity: 0,
        transformOrigin: '50% 50%'
      }, {
        duration: config.duration,
        ease: config.ease,
        scale: 1.03,
        opacity: 1,
        stagger: config.stagger,
        overwrite: 'auto',
        onComplete: resolve
      });
    });
  }

  // 隱藏 overlay：cell 縮回 0 露出頁面
  function pixelHide(config) {
    return new Promise(function (resolve) {
      if (!gsap || !pixelGrid.el) { resolve(); return; }
      gsap.to(pixelGrid.cells, {
        duration: config.duration,
        ease: config.ease,
        scale: 0,
        opacity: 0,
        transformOrigin: '50% 50%',
        stagger: config.stagger,
        overwrite: 'auto',
        onComplete: function () {
          gsap.set(pixelGrid.el, { opacity: 0 });
          resolve();
        }
      });
    });
  }

  function deriveLabel(url, fallback) {
    if (fallback) return fallback.trim().toUpperCase();
    if (url.hash) {
      return url.hash.replace(/^#/, '').replace(/[-_]+/g, ' ').trim().toUpperCase() || 'SECTION';
    }
    var pathname = url.pathname.toLowerCase();
    if (pathname.endsWith('/work.html') || pathname.endsWith('work.html')) return 'WORK';
    if (pathname.endsWith('/project.html') || pathname.endsWith('project.html')) return 'WORK';
    if (pathname.endsWith('/index.html') || pathname === '/' || pathname === '') return 'HOME';
    var last = pathname.split('/').filter(Boolean).pop() || 'PAGE';
    return last.replace(/\.html$/i, '').replace(/[-_]+/g, ' ').trim().toUpperCase();
  }

  function updateOverlayContent(refs, url, label) {
    var targetLabel = deriveLabel(url, label);
    if (refs.eyebrow) refs.eyebrow.textContent = 'NUDOT CREATIVE STUDIO';
    if (refs.title) refs.title.textContent = targetLabel;
    if (refs.subtitle) {
      refs.subtitle.textContent = url.hash
        ? 'Scrolling into the selected section with a shared transition shell.'
        : 'Shared page-transition layer for the home page and work archive.';
    }
    if (refs.metaTo) refs.metaTo.textContent = url.pathname.split('/').pop() || 'index.html';
  }

  function updateProgress(refs, value) {
    var rounded = Math.max(0, Math.min(100, Math.round(value)));
    if (refs.counterNum) refs.counterNum.textContent = String(rounded).padStart(2, '0');
    if (refs.loader) refs.loader.style.width = rounded + '%';
  }

  function resetReveal(refs) {
    if (!refs.shell || !refs.curtain || !gsap) return;
    gsap.set(refs.shell, { autoAlpha: 1 });
    gsap.set(refs.curtain, {
      autoAlpha: 1,
      '--pt-top': '-102%',
      '--pt-bottom': '102%'
    });
  }

  function finalizeEntryTransition(refs) {
    if (state.entryCleanupTimer) {
      window.clearTimeout(state.entryCleanupTimer);
      state.entryCleanupTimer = null;
    }

    if (!refs || !refs.shell) {
      document.documentElement.classList.remove('has-pending-page-transition');
      return;
    }

    refs.shell.classList.remove('is-active');

    if (gsap && refs.curtain) {
      gsap.killTweensOf(refs.curtain);
      gsap.killTweensOf(refs.shell);
      gsap.set(refs.curtain, { clearProps: 'all' });
      gsap.set(refs.shell, { clearProps: 'all' });
    } else {
      refs.curtain && refs.curtain.removeAttribute('style');
      refs.shell.removeAttribute('style');
    }

    if (pixelGrid.el) {
      if (gsap) {
        gsap.killTweensOf(pixelGrid.cells);
        gsap.set(pixelGrid.el, { opacity: 0 });
      } else {
        pixelGrid.el.style.opacity = '0';
      }
    }

    document.documentElement.classList.remove('has-pending-page-transition');
  }

  function playEntryTransition(payload) {
    var refs = getShellRefs();
    if (!payload || !refs.shell) {
      document.documentElement.classList.remove('has-pending-page-transition');
      return;
    }

    var destination = new URL(payload.href || window.location.href, window.location.href);
    updateOverlayContent(refs, destination, payload.label || '');

    refs.shell.classList.add('is-active');

    if (!gsap) {
      refs.shell.classList.remove('is-active');
      document.documentElement.classList.remove('has-pending-page-transition');
      return;
    }

    if (state.entryCleanupTimer) {
      window.clearTimeout(state.entryCleanupTimer);
      state.entryCleanupTimer = null;
    }

    // 建立 pixel grid，並讓它「滿格」蓋住整個畫面，無縫接手原本 curtain 提供的黑幕
    buildPixelGrid(refs.shell);
    gsap.set(refs.shell, { autoAlpha: 1 });
    if (pixelGrid.el) {
      gsap.set(pixelGrid.el, { opacity: 1 });
      gsap.set(pixelGrid.cells, { scale: 1.03, opacity: 1, transformOrigin: '50% 50%' });
    }
    // 隱藏舊的 curtain 黑幕（pixel grid 已接手覆蓋）
    if (refs.curtain) gsap.set(refs.curtain, { autoAlpha: 0 });

    state.entryCleanupTimer = window.setTimeout(function () {
      finalizeEntryTransition(refs);
    }, 1500);

    // pixel grid 由中心往外縮回，逐格露出新頁面
    pixelHide({
      duration: 0.3,
      ease: 'power1',
      stagger: pixelStagger('center', 0.022)
    }).then(function () {
      finalizeEntryTransition(refs);
    });
  }

  function armEntryFailSafe(payload) {
    if (!payload) return;

    function finalizeIfStuck() {
      state.entryFailSafeTimer = null;
      // 離場進行中時絕不動遮罩：避免 load/pageshow 重新排程的 fail-safe 把離場 cover 清掉
      if (state.leaving) return;
      var refs = getShellRefs();
      if (!refs.shell) {
        document.documentElement.classList.remove('has-pending-page-transition');
        return;
      }

      if (refs.shell.classList.contains('is-active') || document.documentElement.classList.contains('has-pending-page-transition')) {
        finalizeEntryTransition(refs);
      }
    }

    function schedule(delay) {
      if (state.entryFailSafeTimer) {
        window.clearTimeout(state.entryFailSafeTimer);
      }
      state.entryFailSafeTimer = window.setTimeout(finalizeIfStuck, delay);
    }

    schedule(2400);
    window.addEventListener('load', function () {
      schedule(1600);
    }, { once: true });
    window.addEventListener('pageshow', function () {
      schedule(1600);
    }, { once: true });
  }

  function navigateWithTransition(href, label) {
    if (state.leaving) return;
    state.leaving = true;

    // 清掉進場轉場殘留的計時器，避免它們在離場 cover 進行中觸發 finalizeEntryTransition，
    // 把 pixel 遮罩硬清掉造成畫面硬跳（快速連點時最常見）。
    if (state.entryCleanupTimer) {
      window.clearTimeout(state.entryCleanupTimer);
      state.entryCleanupTimer = null;
    }
    if (state.entryFailSafeTimer) {
      window.clearTimeout(state.entryFailSafeTimer);
      state.entryFailSafeTimer = null;
    }

    var refs = getShellRefs();
    var destination = new URL(href, window.location.href);

    try {
      window.sessionStorage.setItem(TRANSITION_KEY, JSON.stringify({
        href: destination.href,
        label: label || '',
        at: Date.now()
      }));
    } catch (error) { }

    stopLenis();
    document.documentElement.classList.add('is-page-transitioning');

    if (!refs.shell || !gsap) {
      window.location.href = destination.href;
      return;
    }

    updateOverlayContent(refs, destination, label || '');
    buildPixelGrid(refs.shell);
    // 若進場揭開動畫還沒跑完，先 kill 掉，讓離場蓋上動畫從乾淨狀態接管同一組格子
    if (pixelGrid.cells.length) gsap.killTweensOf(pixelGrid.cells);
    if (pixelGrid.el) gsap.set(pixelGrid.el, { opacity: 1 });
    refs.shell.classList.add('is-active');
    if (refs.curtain) gsap.set(refs.curtain, { autoAlpha: 0 });

    var navigated = false;
    function go() {
      if (navigated) return;
      navigated = true;
      window.location.href = destination.href;
    }

    var fallbackTimer = window.setTimeout(go, 1000);

    // pixel grid 由中心往外放大，逐格蓋滿畫面後再導頁
    pixelShow({
      duration: 0.28,
      ease: 'power1.in',
      stagger: pixelStagger('center', 0.022)
    }).then(function () {
      window.clearTimeout(fallbackTimer);
      go();
    });
  }

  window._nudotNavigate = navigateWithTransition;

  function initLinkRouting() {
    document.addEventListener('click', function (event) {
      var link = event.target.closest('a[href]');
      if (!link || event.defaultPrevented) return;
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      if (link.target === '_blank' || link.hasAttribute('download')) return;

      var rawHref = link.getAttribute('href');
      if (!rawHref || rawHref.indexOf('mailto:') === 0 || rawHref.indexOf('tel:') === 0 || rawHref.indexOf('javascript:') === 0) {
        return;
      }

      var destination = new URL(rawHref, window.location.href);
      if (destination.origin !== window.location.origin) return;

      if (rawHref.charAt(0) === '#' || isSameDocument(destination)) {
        if (!destination.hash) return;
        var target = document.querySelector(destination.hash);
        if (!target) return;
        event.preventDefault();
        closeSharedMenu(false);
        scrollToTarget(target);
        return;
      }

      event.preventDefault();
      var transitionLabel = link.dataset.transitionLabel || link.textContent || '';
      var navContainer = document.getElementById('nav_scroll_container');
      if (document.body.classList.contains('shared-nav-page') && navContainer && (navContainer.classList.contains('is-menu-open') || sharedMenuState.animating)) {
        document.documentElement.classList.add('is-menu-link-transition');
        closeSharedMenu(false, function () {
          document.documentElement.classList.remove('is-menu-link-transition');
          navigateWithTransition(destination.href, transitionLabel);
        });
        return;
      }

      var navContainerEl = document.getElementById('nav_scroll_container');
      var menuIsOpen = !!(navContainerEl && navContainerEl.classList.contains('is-menu-open'));
      if (menuIsOpen) {
        document.documentElement.classList.add('is-menu-link-transition');
        closeSharedMenu(false, function () {
          document.documentElement.classList.remove('is-menu-link-transition');
          navigateWithTransition(destination.href, transitionLabel);
        });
      } else {
        closeSharedMenu(true);
        navigateWithTransition(destination.href, transitionLabel);
      }
    }, false);
  }

  function getMenuClosedWidth() {
    var inset = window.innerWidth <= 767 ? 24 : 80;
    return Math.min(500, Math.max(280, window.innerWidth - inset));
  }

  function getMenuClosedTop() {
    return window.innerWidth <= 767 ? 16 : 30;
  }

  function getMenuCollapsedTop() {
    return getMenuClosedTop() + 30;
  }

  function killSharedMenuTimeline(key) {
    if (!sharedMenuState[key]) return;
    sharedMenuState[key].kill();
    sharedMenuState[key] = null;
  }

  function clearSharedMenuFinishTimer() {
    if (!sharedMenuState.finishTimer) return;
    window.clearTimeout(sharedMenuState.finishTimer);
    sharedMenuState.finishTimer = null;
  }

  function clearSharedMenuPhaseTimer() {
    if (!sharedMenuState.phaseTimer) return;
    window.clearTimeout(sharedMenuState.phaseTimer);
    sharedMenuState.phaseTimer = null;
  }

  function clearSharedMenuTimers() {
    clearSharedMenuPhaseTimer();
    clearSharedMenuFinishTimer();
  }

  function scheduleSharedMenuFinish(callback, delay) {
    clearSharedMenuFinishTimer();
    sharedMenuState.finishTimer = window.setTimeout(function () {
      sharedMenuState.finishTimer = null;
      callback();
    }, delay);
  }

  function scheduleSharedMenuPhase(callback, delay) {
    clearSharedMenuPhaseTimer();
    sharedMenuState.phaseTimer = window.setTimeout(function () {
      sharedMenuState.phaseTimer = null;
      callback();
    }, delay);
  }

  function syncSharedMenuLayoutVars(container) {
    if (!container) return;
    container.style.setProperty('--ns-menu-collapse-top', getMenuCollapsedTop() + 'px');
    container.style.setProperty('--ns-menu-closed-width', getMenuClosedWidth() + 'px');
  }

  function setSharedMenuShellStyles(container, styles) {
    if (!container) return;
    Object.keys(styles).forEach(function (key) {
      container.style[key] = styles[key];
    });
  }

  function clearSharedMenuShellStyles(container) {
    if (!container) return;
    container.style.removeProperty('top');
    container.style.removeProperty('left');
    container.style.removeProperty('width');
    container.style.removeProperty('height');
    container.style.removeProperty('border-radius');
    container.style.removeProperty('transform');
    container.style.removeProperty('background-color');
  }

  function finishSharedMenuOpen(container, dropdown) {
    clearSharedMenuTimers();
    sharedMenuState.animating = false;
    container.classList.remove('is-menu-animating', 'is-menu-phase-compact', 'is-menu-phase-line');
    sharedMenuState.openTl = null;
  }

  function finishSharedMenuClosed(container, dropdown, items) {
    clearSharedMenuTimers();
    setMenuExpanded(container, false);
    sharedMenuState.animating = false;
    container.classList.remove('is-menu-animating', 'is-menu-phase-compact', 'is-menu-phase-line');
    clearSharedMenuShellStyles(container);
    sharedMenuState.closeTl = null;
  }

  function setMenuExpanded(container, expanded) {
    var menuBtn = document.getElementById('nav-scroll-menu-btn');
    var dropdown = document.getElementById('nav-scroll-dropdown');
    if (!container || !menuBtn || !dropdown) return;

    sharedMenuState.open = expanded;
    container.classList.toggle('is-menu-open', expanded);
    document.body.classList.toggle('nav-menu-open', expanded);
    menuBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    menuBtn.setAttribute('aria-label', expanded ? 'Close menu' : 'Open menu');
    menuBtn.dataset.cursor = expanded ? 'CLOSE' : 'OPEN';
    dropdown.setAttribute('aria-hidden', expanded ? 'false' : 'true');
  }

  function animateMenuItems(expanded) {
    var items = Array.prototype.slice.call(document.querySelectorAll('#nav-scroll-dropdown .ns-dropdown__item'));
    if (!items.length || !gsap) return;

    if (expanded) {
      gsap.fromTo(items,
        { autoAlpha: 0, y: 18 },
        {
          autoAlpha: 1,
          y: 0,
          duration: 0.44,
          stagger: 0.05,
          ease: 'power3.out',
          overwrite: 'auto'
        }
      );
      return;
    }

    gsap.to(items, {
      autoAlpha: 0,
      y: 18,
      filter: 'blur(8px)',
      duration: 0.18,
      ease: 'power2.in',
      stagger: 0.02,
      overwrite: 'auto'
    });
  }

  function isSimpleTouchNavMode() {
    return window.innerWidth <= 1280;
  }

  function previewRow(row) {
    if (!row) return;
    if (isSimpleTouchNavMode()) {
      clearPreviewRow();
      return;
    }
    var rows = document.querySelectorAll('#nav-scroll-dropdown .ns-showcase-row');
    rows.forEach(function (item) {
      item.classList.toggle('is-previewed', item === row);
    });
  }

  function clearPreviewRow(row) {
    var rows = document.querySelectorAll('#nav-scroll-dropdown .ns-showcase-row');
    if (!row) {
      rows.forEach(function (item) {
        item.classList.remove('is-previewed');
      });
      return;
    }
    row.classList.remove('is-previewed');
  }

  function closeSharedMenu(skipAnimation, onClosed) {
    var container = document.getElementById('nav_scroll_container');
    var dropdown = document.getElementById('nav-scroll-dropdown');
    var items = Array.prototype.slice.call(document.querySelectorAll('#nav-scroll-dropdown .ns-dropdown__item'));
    var done = typeof onClosed === 'function' ? onClosed : null;
    if (!container) {
      if (done) done();
      return;
    }
    if (!container.classList.contains('is-menu-open') && !sharedMenuState.animating) {
      if (done) done();
      return;
    }

    killSharedMenuTimeline('openTl');

    clearSharedMenuTimers();
    sharedMenuState.animating = true;
    sharedMenuState.open = false;
    syncSharedMenuLayoutVars(container);
    clearSharedMenuShellStyles(container);
    container.classList.add('is-menu-animating');
    container.classList.remove('ns-enter', 'ns-exit');

    if (skipAnimation) {
      killSharedMenuTimeline('closeTl');
      finishSharedMenuClosed(container, dropdown, items);
      if (done) done();
      return;
    }

    container.classList.remove('is-menu-phase-compact');
    container.classList.add('is-menu-phase-line');

    scheduleSharedMenuPhase(function () {
      container.classList.remove('is-menu-phase-line');
      container.classList.add('is-menu-phase-compact');

      scheduleSharedMenuPhase(function () {
        setMenuExpanded(container, false);
        container.classList.remove('is-menu-phase-compact');
        setSharedMenuShellStyles(container, {
          top: getMenuClosedTop() + 'px',
          left: '50%',
          width: getMenuClosedWidth() + 'px',
          height: '64px',
          borderRadius: '6px',
          transform: 'translateX(-50%)',
          backgroundColor: '#141414'
        });
      }, 250);
    }, 220);

    scheduleSharedMenuFinish(function () {
      finishSharedMenuClosed(container, dropdown, items);
      if (done) done();
    }, 780);
  }

  function initSharedMenu() {
    if (!document.body.classList.contains('shared-nav-page')) return;

    var container = document.getElementById('nav_scroll_container');
    var menuBtn = document.getElementById('nav-scroll-menu-btn');
    var dropdown = document.getElementById('nav-scroll-dropdown');
    if (!container || !menuBtn || !dropdown) return;

    var items = Array.prototype.slice.call(dropdown.querySelectorAll('.ns-dropdown__item'));

    setMenuExpanded(container, false);
    container.classList.remove('is-menu-animating', 'is-menu-phase-compact', 'is-menu-phase-line');

    menuBtn.addEventListener('click', function () {
      var expanded = sharedMenuState.open;
      killSharedMenuTimeline('closeTl');

      if (expanded) {
        closeSharedMenu(false);
        return;
      }

      clearSharedMenuTimers();
      sharedMenuState.animating = true;
      sharedMenuState.open = true;
      syncSharedMenuLayoutVars(container);
      clearSharedMenuShellStyles(container);
      container.classList.add('is-menu-animating');
      container.classList.remove('ns-enter', 'ns-exit');
      setMenuExpanded(container, true);

      container.classList.add('is-menu-phase-compact');
      container.classList.remove('is-menu-phase-line');

      scheduleSharedMenuPhase(function () {
        container.classList.remove('is-menu-phase-compact');
        container.classList.add('is-menu-phase-line');

        scheduleSharedMenuPhase(function () {
          container.classList.remove('is-menu-phase-line');
          setSharedMenuShellStyles(container, {
            top: '0px',
            left: '50%',
            width: '100vw',
            height: '100vh',
            borderRadius: '0px',
            transform: 'translateX(-50%)',
            backgroundColor: '#efe6d8'
          });
        }, 250);
      }, 220);

      scheduleSharedMenuFinish(function () {
        finishSharedMenuOpen(container, dropdown);
      }, 860);
    });

    document.addEventListener('click', function (event) {
      if (!container.classList.contains('is-menu-open')) return;
      if (container.contains(event.target)) return;
      closeSharedMenu();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeSharedMenu();
    });

    window.addEventListener('resize', function () {
      if (isSimpleTouchNavMode()) clearPreviewRow();
    }, { passive: true });

    Array.prototype.slice.call(dropdown.querySelectorAll('.ns-showcase-row')).forEach(function (row) {
      row.addEventListener('mouseenter', function () { previewRow(row); });
      row.addEventListener('mouseleave', function () { clearPreviewRow(row); });
      row.addEventListener('focus', function () { previewRow(row); });
      row.addEventListener('blur', function () { clearPreviewRow(row); });
    });

  }

  function initIndexSyncedSharedNav() {
    if (!document.body.classList.contains('index-nav-sync')) return;

    var container = document.getElementById('nav_scroll_container');
    var progressBar = document.getElementById('scroll-progress');
    if (!container) return;

    var visibleOnLoad = document.body.classList.contains('nav-visible-on-load');
    var showing = false;
    var raf = 0;
    var hideTimer = null;
    var enterTimer = null;
    var exitTimer = null;
    var lenisHooked = false;

    function clearEnterTimer() {
      if (!enterTimer) return;
      window.clearTimeout(enterTimer);
      enterTimer = null;
    }

    function clearExitTimer() {
      if (!exitTimer) return;
      window.clearTimeout(exitTimer);
      exitTimer = null;
    }

    function getScrollTop() {
      return window.scrollY || document.documentElement.scrollTop || document.body.scrollTop || 0;
    }

    function updateProgress() {
      if (!progressBar) return;
      var doc = document.documentElement;
      var max = Math.max(1, doc.scrollHeight - window.innerHeight);
      var progress = Math.max(0, Math.min(1, getScrollTop() / max));
      progressBar.style.width = (progress * 100).toFixed(2) + '%';
    }

    function setInteractive(active) {
      if (active || container.classList.contains('is-menu-open')) {
        container.removeAttribute('inert');
        container.style.pointerEvents = 'auto';
        return;
      }

      if (container.contains(document.activeElement) && document.activeElement && document.activeElement.blur) {
        document.activeElement.blur();
      }
      container.setAttribute('inert', '');
      container.style.pointerEvents = 'none';
    }

    function showNav() {
      if (showing) return;
      showing = true;
      if (hideTimer) {
        window.clearTimeout(hideTimer);
        hideTimer = null;
      }
      clearEnterTimer();
      clearExitTimer();
      document.documentElement.classList.add('show-nav-scroll');
      container.classList.remove('ns-exit');
      container.classList.add('ns-enter');
      setInteractive(true);
      enterTimer = window.setTimeout(function () {
        container.classList.remove('ns-enter');
        enterTimer = null;
      }, 700);
    }

    function hideNav() {
      if (!showing) return;
      showing = false;
      clearEnterTimer();
      clearExitTimer();
      document.documentElement.classList.remove('show-nav-scroll');
      closeSharedMenu(true);
      container.classList.remove('ns-enter');
      container.classList.add('ns-exit');
      setInteractive(false);
      exitTimer = window.setTimeout(function () {
        if (!showing) {
          container.classList.remove('ns-exit');
        }
        exitTimer = null;
      }, 520);
      hideTimer = window.setTimeout(function () {
        if (!showing && !container.classList.contains('is-menu-open')) setInteractive(false);
      }, 520);
    }

    function syncFromScroll(scrollTop) {
      updateProgress();
      if (visibleOnLoad || scrollTop > 300) showNav();
      else hideNav();
    }

    function scheduleSync() {
      if (raf) return;
      raf = window.requestAnimationFrame(function () {
        raf = 0;
        syncFromScroll(getScrollTop());
      });
    }

    function hookLenis() {
      var lenis = getLenis();
      if (lenisHooked || !lenis || typeof lenis.on !== 'function') return;
      lenisHooked = true;
      lenis.on('scroll', function (event) {
        var scrollTop = event && typeof event.scroll === 'number' ? event.scroll : getScrollTop();
        syncFromScroll(scrollTop);
      });
    }

    setInteractive(false);
    scheduleSync();
    window.addEventListener('scroll', scheduleSync, { passive: true });
    window.addEventListener('resize', scheduleSync, { passive: true });
    window.setTimeout(function () {
      hookLenis();
      scheduleSync();
    }, 250);
    window.setTimeout(function () {
      hookLenis();
      scheduleSync();
    }, 1000);
  }

  function initBarbaMarkers() {
    if (!window.barba) return;
    document.documentElement.setAttribute('data-barba-mode', 'reload-safe');
  }

  // 處理瀏覽器 bfcache 還原（上下一頁）— 完整重置 Lenis / ScrollTrigger / 轉場 / 選單狀態
  // 解決卷軸卡死：離開頁面時呼叫過 stopLenis()，bfcache 還原時 Lenis 仍停著，必須主動重啟
  function restoreFromBfcache() {
    // 1. 重置轉場狀態 + 清掉所有殘留 CSS class
    state.leaving = false;
    if (state.entryCleanupTimer) {
      window.clearTimeout(state.entryCleanupTimer);
      state.entryCleanupTimer = null;
    }
    if (state.entryFailSafeTimer) {
      window.clearTimeout(state.entryFailSafeTimer);
      state.entryFailSafeTimer = null;
    }
    document.documentElement.classList.remove(
      'has-pending-page-transition',
      'is-page-transitioning',
      'is-menu-link-transition'
    );
    try { window.sessionStorage.removeItem(TRANSITION_KEY); } catch (ignored) { }

    // 2. 收掉轉場 shell（curtain / shell 完全清乾淨）
    var refs = getShellRefs();
    finalizeEntryTransition(refs);

    // 3. 【關鍵】重啟 Lenis，並同步內部 scroll target 到瀏覽器還原的卷軸位置
    //    若不做這一步,Lenis 會停留在停止狀態,滾輪完全沒反應 = 卷軸卡死
    var lenis = getLenis();
    if (lenis) {
      try {
        if (typeof lenis.start === 'function') lenis.start();
        var currentY = window.scrollY || document.documentElement.scrollTop || 0;
        if (typeof lenis.scrollTo === 'function') {
          lenis.scrollTo(currentY, { immediate: true, force: true });
        }
      } catch (err) { /* ignore */ }
    }

    // 4. 刷新 ScrollTrigger,讓觸發點對齊還原後的版面（避免 scroll 觸發位錯位）
    if (window.ScrollTrigger && typeof window.ScrollTrigger.refresh === 'function') {
      try { window.ScrollTrigger.refresh(); } catch (err) { /* ignore */ }
    }

    // 5. 強制重置共用導覽選單，避免離開時動畫卡在中途
    var navContainer = document.getElementById('nav_scroll_container');
    if (navContainer) {
      killSharedMenuTimeline('openTl');
      killSharedMenuTimeline('closeTl');
      clearSharedMenuTimers();
      sharedMenuState.animating = false;
      sharedMenuState.open = false;
      navContainer.classList.remove(
        'is-menu-animating',
        'is-menu-phase-compact',
        'is-menu-phase-line',
        'is-menu-open',
        'ns-enter',
        'ns-exit'
      );
      clearSharedMenuShellStyles(navContainer);
      navContainer.removeAttribute('inert');
      navContainer.style.removeProperty('pointer-events');
      document.body.classList.remove('nav-menu-open');

      var menuBtn = document.getElementById('nav-scroll-menu-btn');
      var dropdown = document.getElementById('nav-scroll-dropdown');
      if (menuBtn) {
        menuBtn.setAttribute('aria-expanded', 'false');
        menuBtn.setAttribute('aria-label', 'Open menu');
        menuBtn.dataset.cursor = 'OPEN';
      }
      if (dropdown) dropdown.setAttribute('aria-hidden', 'true');
    }
  }

  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    restoreFromBfcache();
    // 二段保險:bfcache 還原後,部分瀏覽器(尤其 Safari)第一幀 Lenis raf 尚未掛上,
    // 延後一輪 rAF 再呼叫一次,確保滾輪一定能動
    window.requestAnimationFrame(function () {
      var lenis = getLenis();
      if (lenis && typeof lenis.start === 'function') {
        try { lenis.start(); } catch (err) { /* ignore */ }
      }
    });
  });

  function cleanupStalePageShowTransition() {
    if (state.leaving) return;
    var refs = getShellRefs();
    var hasStaleTransition = document.documentElement.classList.contains('has-pending-page-transition')
      || document.documentElement.classList.contains('is-page-transitioning')
      || !!(refs.shell && refs.shell.classList.contains('is-active'));
    if (!hasStaleTransition) return;
    restoreFromBfcache();
  }

  // 觸控設備（沒用 Lenis）時,bfcache 還原偶爾會殘留 has-pending-page-transition
  // 配合上面 restoreFromBfcache 已處理；此處針對非 persisted 的 pageshow 做最後保險
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) return;
    // 一般載入(非 bfcache):如果發現 transitions 狀態還掛著,代表上一次離開時動畫沒走完
    window.setTimeout(cleanupStalePageShowTransition, 1800);
  });

  function boot() {
    // 重新同步 gsap 參照：IIFE 載入當下若 gsap 尚未就緒（例如有人調動 script 順序／移除 defer），
    // 仍能在 boot 時補抓到，避免整個轉場因抓不到 gsap 而靜默失效。
    if (!gsap && window.gsap) gsap = window.gsap;

    var payload = consumePayload();
    initBarbaMarkers();
    initSharedMenu();
    initIndexSyncedSharedNav();
    initLinkRouting();
    armEntryFailSafe(payload);
    playEntryTransition(payload);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
