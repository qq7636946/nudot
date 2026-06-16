(function () {
  'use strict';

  if (window.__nudotCreativeProcessInit) return;
  window.__nudotCreativeProcessInit = true;

  const gsap = window.gsap;
  const ScrollTrigger = window.ScrollTrigger;
  const CustomEase = window.CustomEase;
  if (!gsap || !ScrollTrigger || !window.Lenis) return;

  const plugins = [ScrollTrigger];
  if (CustomEase) plugins.push(CustomEase);
  gsap.registerPlugin.apply(gsap, plugins);

  if (CustomEase && typeof CustomEase.create === 'function') {
    CustomEase.create('customEase', 'M0,0 C0.86,0 0.07,1 1,1');
  }

  let lenis;
  const PAGE_SOUND_ENABLED = false;

  class SoundManager {
    constructor() {
      this.sounds = {};
      this.isEnabled = PAGE_SOUND_ENABLED;
      if (PAGE_SOUND_ENABLED) {
        this.init();
      }
    }

    init() {
      if (!PAGE_SOUND_ENABLED) return;
      this.loadSound('hover', 'https://assets.codepen.io/7558/click-reverb-001.mp3');
      this.loadSound('click', 'https://assets.codepen.io/7558/shutter-fx-001.mp3');
      this.loadSound('textChange', 'https://assets.codepen.io/7558/whoosh-fx-001.mp3');
    }

    loadSound(name, url) {
      if (!PAGE_SOUND_ENABLED) return;
      const audio = new Audio(url);
      audio.preload = 'auto';
      audio.volume = name === 'hover' ? 0.15 : 0.3;
      this.sounds[name] = audio;
    }

    enableAudio() {
      if (!PAGE_SOUND_ENABLED) return;
      if (!this.isEnabled) {
        this.isEnabled = true;
      }
    }

    play(soundName, delay = 0) {
      if (!PAGE_SOUND_ENABLED) return;
      if (!this.isEnabled || !this.sounds[soundName]) return;

      const playSound = () => {
        this.sounds[soundName].currentTime = 0;
        this.sounds[soundName].play().catch(() => {});
      };

      if (delay > 0) {
        window.setTimeout(playSound, delay);
      } else {
        playSound();
      }
    }

    addSound(name, url, volume = 0.3) {
      if (!PAGE_SOUND_ENABLED) return;
      this.loadSound(name, url);
      if (this.sounds[name]) {
        this.sounds[name].volume = volume;
      }
    }
  }

  const soundManager = new SoundManager();

  document.addEventListener('DOMContentLoaded', function () {
    window.setTimeout(function () {
      document.fonts.ready.then(function () {
        initLenis();
        initPage();
      });
    }, 500);
  });

  function initLenis() {
    if (window._lenis) {
      lenis = window._lenis;
      try {
        lenis.scrollTo(0, { immediate: true });
      } catch (error) {}
      return;
    }

    lenis = new window.Lenis({
      duration: 1.2,
      easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
      direction: 'vertical',
      gestureDirection: 'vertical',
      smooth: true,
      smoothTouch: false,
      touchMultiplier: 2
    });

    lenis.on('scroll', ScrollTrigger.update);
    gsap.ticker.add((time) => {
      lenis.raf(time * 1000);
    });
    gsap.ticker.lagSmoothing(0);
    window._lenis = lenis;
    window.scrollTo(0, 0);
    try {
      lenis.scrollTo(0, { immediate: true });
    } catch (error) {}
  }

  function splitHeadingIntoWords(heading) {
    if (!heading) {
      return { words: [] };
    }

    if (heading.dataset.wordsSplit === 'true') {
      return {
        words: Array.from(heading.querySelectorAll('.split-word'))
      };
    }

    const text = (heading.textContent || '').replace(/\s+/g, ' ').trim();
    if (!text) {
      return { words: [] };
    }

    const words = [];
    const fragment = document.createDocumentFragment();
    const wordParts = text.split(' ');

    heading.textContent = '';
    heading.setAttribute('aria-label', text);

    wordParts.forEach((part, index) => {
      const mask = document.createElement('span');
      const word = document.createElement('span');

      mask.className = 'word-mask';
      word.className = 'split-word';
      word.textContent = part;
      word.setAttribute('aria-hidden', 'true');

      mask.appendChild(word);
      fragment.appendChild(mask);
      words.push(word);

      if (index < wordParts.length - 1) {
        fragment.appendChild(document.createTextNode('\u00A0'));
      }
    });

    heading.appendChild(fragment);
    heading.dataset.wordsSplit = 'true';

    return { words };
  }

  function initPage() {
    const duration = 0.64;
    const sectionEase = CustomEase ? 'customEase' : 'power3.out';
    const debugInfo = document.getElementById('debug-info');
    const fixedContainer = document.getElementById('fixed-container');
    const fixedSectionElement = document.querySelector('.fixed-section');
    const header = document.querySelector('.header');
    const content = document.querySelector('.content');
    const footer = document.getElementById('footer');
    const leftColumn = document.getElementById('left-column');
    const rightColumn = document.getElementById('right-column');
    const featured = document.getElementById('featured');
    const backgrounds = document.querySelectorAll('.background-image');
    const artists = document.querySelectorAll('.artist');
    const categories = document.querySelectorAll('.category');
    const featuredContents = document.querySelectorAll('.featured-content');
    const progressFill = document.getElementById('progress-fill');
    const currentSectionDisplay = document.getElementById('current-section');
    const splitTexts = {};

    if (!fixedContainer || !fixedSectionElement || !backgrounds.length) return;

    function animateColumns() {
      const artistItems = document.querySelectorAll('.artist');
      const categoryItems = document.querySelectorAll('.category');

      artistItems.forEach((item, index) => {
        window.setTimeout(() => {
          item.classList.add('loaded');
        }, index * 60);
      });

      categoryItems.forEach((item, index) => {
        window.setTimeout(() => {
          item.classList.add('loaded');
        }, index * 60 + 200);
      });
    }

    function updateProgressNumbers() {
      currentSectionDisplay.textContent = (currentSection + 1)
        .toString()
        .padStart(2, '0');
    }

    const fixedSectionTop = fixedSectionElement.offsetTop;
    const fixedSectionHeight = fixedSectionElement.offsetHeight;
    const fixedSectionScrollRange = Math.max(1, fixedSectionHeight - window.innerHeight);
    let currentSection = 0;
    let isAnimating = false;
    let isSnapping = false;
    let lastProgress = 0;
    let scrollDirection = 0;
    const sectionPositions = [];

    for (let index = 0; index < 10; index += 1) {
      sectionPositions.push(fixedSectionTop + (fixedSectionScrollRange * index) / 10);
    }

    function navigateToSection(index) {
      if (index === currentSection || isAnimating || isSnapping) return;

      soundManager.enableAudio();
      soundManager.play('click');

      isSnapping = true;
      const targetPosition = sectionPositions[index];

      changeSection(index);

      lenis.scrollTo(targetPosition, {
        duration: 0.8,
        easing: (t) => 1 - Math.pow(1 - t, 3),
        lock: true,
        onComplete: () => {
          isSnapping = false;
        }
      });
    }

    artists.forEach((artist, index) => {
      artist.addEventListener('click', (event) => {
        event.preventDefault();
        navigateToSection(index);
      });

      artist.addEventListener('mouseenter', () => {
        soundManager.enableAudio();
        soundManager.play('hover');
      });
    });

    categories.forEach((category, index) => {
      category.addEventListener('click', (event) => {
        event.preventDefault();
        navigateToSection(index);
      });

      category.addEventListener('mouseenter', () => {
        soundManager.enableAudio();
        soundManager.play('hover');
      });
    });

    document.addEventListener(
      'click',
      () => {
        soundManager.enableAudio();
      },
      { once: true }
    );

    try {
      featuredContents.forEach((featuredContent, index) => {
        const heading = featuredContent.querySelector('h3');
        if (!heading) return;

        splitTexts['featured-' + index] = splitHeadingIntoWords(heading);

        splitTexts['featured-' + index].words.forEach((word) => {
          if (index !== 0) {
            gsap.set(word, {
              yPercent: 100,
              opacity: 0
            });
          } else {
            gsap.set(word, {
              yPercent: 0,
              opacity: 1
            });
          }
        });
      });
    } catch (error) {
      console.error('Word split error:', error);
    }

    gsap.set(fixedContainer, {
      height: '100vh'
    });

    ScrollTrigger.create({
      trigger: '.fixed-section',
      start: 'top top',
      end: 'bottom bottom',
      pin: '.fixed-container',
      pinSpacing: true,
      onUpdate: (self) => {
        if (isSnapping) return;

        const progress = self.progress;
        const progressDelta = progress - lastProgress;

        if (Math.abs(progressDelta) > 0.001) {
          scrollDirection = progressDelta > 0 ? 1 : -1;
        }

        const targetSection = Math.min(9, Math.floor(progress * 10));

        if (targetSection !== currentSection && !isAnimating) {
          const nextSection = currentSection + (targetSection > currentSection ? 1 : -1);
          snapToSection(nextSection);
        }

        lastProgress = progress;
        const sectionProgress = currentSection / 9;
        progressFill.style.width = sectionProgress * 100 + '%';
        debugInfo.textContent = 'Section: ' + currentSection + ', Target: ' + targetSection + ', Progress: ' + progress.toFixed(3) + ', Direction: ' + scrollDirection;
      }
    });

    function snapToSection(targetSection) {
      if (
        targetSection < 0 ||
        targetSection > 9 ||
        targetSection === currentSection ||
        isAnimating
      ) {
        return;
      }

      isSnapping = true;
      changeSection(targetSection);

      const targetPosition = sectionPositions[targetSection];
      lenis.scrollTo(targetPosition, {
        duration: 0.6,
        easing: (t) => 1 - Math.pow(1 - t, 3),
        lock: true,
        onComplete: () => {
          isSnapping = false;
        }
      });
    }

    const parallaxAmount = 5;

    function changeSection(newSection) {
      if (newSection === currentSection || isAnimating) return;

      isAnimating = true;
      const isScrollingDown = newSection > currentSection;
      const previousSection = currentSection;
      currentSection = newSection;

      updateProgressNumbers();

      const sectionProgress = currentSection / 9;
      progressFill.style.width = sectionProgress * 100 + '%';

      debugInfo.textContent = 'Changing to Section: ' + newSection + ' (' + (isScrollingDown ? 'Down' : 'Up') + ')';

      featuredContents.forEach((featuredContent, index) => {
        if (index !== newSection && index !== previousSection) {
          featuredContent.classList.remove('active');
          gsap.set(featuredContent, {
            visibility: 'hidden',
            opacity: 0
          });
        }
      });

      if (previousSection !== null) {
        const previousWords = splitTexts['featured-' + previousSection] && splitTexts['featured-' + previousSection].words;
        if (previousWords) {
          gsap.to(previousWords, {
            yPercent: isScrollingDown ? -100 : 100,
            opacity: 0,
            duration: duration * 0.6,
            stagger: isScrollingDown ? 0.03 : -0.03,
            ease: sectionEase,
            onComplete: () => {
              featuredContents[previousSection].classList.remove('active');
              gsap.set(featuredContents[previousSection], {
                visibility: 'hidden'
              });
            }
          });
        }
      }

      const newWords = splitTexts['featured-' + newSection] && splitTexts['featured-' + newSection].words;
      if (newWords) {
        soundManager.play('textChange', 250);

        featuredContents[newSection].classList.add('active');
        gsap.set(featuredContents[newSection], {
          visibility: 'visible',
          opacity: 1
        });
        gsap.set(newWords, {
          yPercent: isScrollingDown ? 100 : -100,
          opacity: 0
        });
        gsap.to(newWords, {
          yPercent: 0,
          opacity: 1,
          duration: duration,
          stagger: isScrollingDown ? 0.05 : -0.05,
          ease: sectionEase
        });
      } else {
        featuredContents[newSection].classList.add('active');
        gsap.set(featuredContents[newSection], {
          visibility: 'visible',
          opacity: 1
        });
      }

      backgrounds.forEach((background, index) => {
        background.classList.remove('previous', 'active');

        if (index === newSection) {
          if (isScrollingDown) {
            gsap.set(background, {
              opacity: 1,
              y: 0,
              clipPath: 'inset(100% 0 0 0)'
            });
            gsap.to(background, {
              clipPath: 'inset(0% 0 0 0)',
              duration: duration,
              ease: sectionEase
            });
          } else {
            gsap.set(background, {
              opacity: 1,
              y: 0,
              clipPath: 'inset(0 0 100% 0)'
            });
            gsap.to(background, {
              clipPath: 'inset(0 0 0% 0)',
              duration: duration,
              ease: sectionEase
            });
          }
          background.classList.add('active');
        } else if (index === previousSection) {
          background.classList.add('previous');
          gsap.to(background, {
            y: (isScrollingDown ? parallaxAmount : -parallaxAmount) + '%',
            duration: duration,
            ease: sectionEase
          });
          gsap.to(background, {
            opacity: 0,
            delay: duration * 0.5,
            duration: duration * 0.5,
            ease: sectionEase,
            onComplete: () => {
              background.classList.remove('previous');
              gsap.set(background, {
                y: 0
              });
              isAnimating = false;
            }
          });
        } else {
          gsap.to(background, {
            opacity: 0,
            duration: duration * 0.3,
            ease: sectionEase
          });
        }
      });

      artists.forEach((artist, index) => {
        if (index === newSection) {
          artist.classList.add('active');
          gsap.to(artist, {
            opacity: 1,
            duration: 0.3,
            ease: 'power2.out'
          });
        } else {
          artist.classList.remove('active');
          gsap.to(artist, {
            opacity: 0.3,
            duration: 0.3,
            ease: 'power2.out'
          });
        }
      });

      categories.forEach((category, index) => {
        if (index === newSection) {
          category.classList.add('active');
          gsap.to(category, {
            opacity: 1,
            duration: 0.3,
            ease: 'power2.out'
          });
        } else {
          category.classList.remove('active');
          gsap.to(category, {
            opacity: 0.3,
            duration: 0.3,
            ease: 'power2.out'
          });
        }
      });
    }

    let globalProgress = 0;
    const progressBarTrigger = ScrollTrigger.create({
      trigger: '.scroll-container',
      start: 'top top',
      end: 'bottom bottom',
      onUpdate: (self) => {
        globalProgress = self.progress;
      }
    });

    ScrollTrigger.create({
      trigger: '.end-section',
      start: 'top center',
      end: 'bottom bottom',
      onUpdate: (self) => {
        if (self.progress > 0.1) {
          footer.classList.add('blur');
          leftColumn.classList.add('blur');
          rightColumn.classList.add('blur');
          featured.classList.add('blur');
        } else {
          footer.classList.remove('blur');
          leftColumn.classList.remove('blur');
          rightColumn.classList.remove('blur');
          featured.classList.remove('blur');
        }

        if (self.progress > 0.1) {
          const newHeight = Math.max(
            0,
            100 - ((self.progress - 0.1) / 0.9) * 100
          );
          gsap.to(fixedContainer, {
            height: newHeight + 'vh',
            duration: 0.1,
            ease: 'power1.out'
          });
          const moveY = (-(self.progress - 0.1) / 0.9) * 200;
          gsap.to(header, {
            y: moveY * 1.5,
            duration: 0.1,
            ease: 'power1.out'
          });
          gsap.to(content, {
            y: 'calc(' + moveY + 'px + (-50%))',
            duration: 0.1,
            ease: 'power1.out'
          });
          gsap.to(footer, {
            y: moveY * 0.5,
            duration: 0.1,
            ease: 'power1.out'
          });
        } else {
          gsap.to(fixedContainer, {
            height: '100vh',
            duration: 0.1,
            ease: 'power1.out'
          });
          gsap.to(header, {
            y: 0,
            duration: 0.1,
            ease: 'power1.out'
          });
          gsap.to(content, {
            y: '-50%',
            duration: 0.1,
            ease: 'power1.out'
          });
          gsap.to(footer, {
            y: 0,
            duration: 0.1,
            ease: 'power1.out'
          });
        }

        debugInfo.textContent = 'End Section - Height: ' + fixedContainer.style.height + ', Progress: ' + self.progress.toFixed(2);
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key.toLowerCase() === 'h') {
        debugInfo.style.display = debugInfo.style.display === 'none' ? 'block' : 'none';
      }
    });

    updateProgressNumbers();
    debugInfo.textContent = 'Current Section: 0 (Initial)';
    animateColumns();

    window.requestAnimationFrame(() => {
      ScrollTrigger.refresh();
    });
  }

  window.addSound = function (name, url, volume = 0.3) {
    soundManager.addSound(name, url, volume);
  };
})();

(function () {
  'use strict';

  if (window.__nudotWorkArchiveInit) return;
  window.__nudotWorkArchiveInit = true;

  function ready(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback, { once: true });
      return;
    }
    callback();
  }

  function initReveal() {
    const items = Array.from(document.querySelectorAll('.work-reveal'));
    items.forEach((item) => item.classList.add('is-visible'));

    // 頁面進場動畫：WORK/ND-26 從中間往兩側，分隔線由中往左右拉開
    const hero = document.querySelector('.work-hero');
    if (hero) {
      // 強制 layout reflow 後才加 class，確保 transition 能觸發
      window.requestAnimationFrame(() => {
        window.requestAnimationFrame(() => {
          hero.classList.add('work-hero-entered');
        });
      });
    }
  }

  function initWorkTags() {
    const boxes = Array.from(document.querySelectorAll('.work-box'));
    if (!boxes.length) return;

    const tagLabels = [
      '品牌設計',
      '使用者介面設計',
      '使用者體驗設計'
    ];

    boxes.forEach((box) => {
      if (box.querySelector('.work-tags')) return;

      const tags = document.createElement('div');
      tags.className = 'work-tags';
      tags.setAttribute('aria-hidden', 'true');

      tagLabels.forEach((label) => {
        const tag = document.createElement('span');
        tag.className = 'work-tag';
        tag.textContent = label;
        tags.appendChild(tag);
      });

      const videoContainer = box.querySelector('.work-video');
      if (videoContainer) {
        box.insertBefore(tags, videoContainer);
        return;
      }

      box.appendChild(tags);
    });
  }

  function syncWorkTagLabels() {
    const labels = [
      '\u54c1\u724c\u8a2d\u8a08',
      '\u4f7f\u7528\u8005\u4ecb\u9762\u8a2d\u8a08',
      '\u4f7f\u7528\u8005\u9ad4\u9a57\u8a2d\u8a08'
    ];

    document.querySelectorAll('.work-tags').forEach((group) => {
      const tags = group.querySelectorAll('.work-tag');
      labels.forEach((label, index) => {
        if (!tags[index]) return;
        tags[index].textContent = label;
      });
    });
  }

  function initHoverVideos() {
    const boxes = Array.from(document.querySelectorAll('.work-box'));
    if (!boxes.length) return;

    const canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    const hideWorkVideos = window.matchMedia('(max-width: 1024px), (hover: none), (pointer: coarse)').matches;

    if (hideWorkVideos) {
      boxes.forEach((box) => {
        const video = box.querySelector('video');
        if (!video) return;
        video.pause();
        try {
          video.currentTime = 0;
        } catch (error) {}
      });
      return;
    }

    boxes.forEach((box) => {
      const video = box.querySelector('video');
      const hoverTarget = box.closest('.work-card') || box;
      if (!video) return;

      video.muted = true;
      video.playsInline = true;

      const playVideo = () => {
        const playPromise = video.play();
        if (playPromise && typeof playPromise.catch === 'function') {
          playPromise.catch(() => {});
        }
      };

      const resetVideo = () => {
        video.pause();
        try {
          video.currentTime = 0;
        } catch (error) {}
      };

      if (canHover) {
        hoverTarget.addEventListener('mouseenter', playVideo);
        hoverTarget.addEventListener('mouseleave', resetVideo);
      }
    });

    if (canHover || !('IntersectionObserver' in window)) return;

    const mobileVideoObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        const video = entry.target.querySelector('video');
        if (!video) return;

        if (entry.isIntersecting && entry.intersectionRatio > 0.45) {
          const playPromise = video.play();
          if (playPromise && typeof playPromise.catch === 'function') {
            playPromise.catch(() => {});
          }
          return;
        }

        video.pause();
      });
    }, {
      threshold: [0, 0.45, 0.75]
    });

    boxes.forEach((box) => mobileVideoObserver.observe(box));
  }

  function initHeroParallax() {
    return;

    let current = 0;
    let target = 0;
    let raf = 0;
    let isTicking = false;

    function clamp(value, min, max) {
      return Math.max(min, Math.min(max, value));
    }

    function apply(progress) {
      const centerShift = progress * -112;
      const sideShift = progress * -54;
      const scale = 1 - progress * 0.08;
      const fade = 1 - progress * 0.4;

      hero.style.setProperty('--work-hero-progress', progress.toFixed(4));
      hero.style.setProperty('--work-hero-center-shift', centerShift.toFixed(2) + 'px');
      hero.style.setProperty('--work-hero-side-shift', sideShift.toFixed(2) + 'px');
      hero.style.setProperty('--work-hero-scale', scale.toFixed(4));
      hero.style.setProperty('--work-hero-fade', fade.toFixed(4));
      hero.style.setProperty('--work-hero-blur', '0px');
    }

    function tick() {
      current += (target - current) * 0.1;
      apply(current);

      if (Math.abs(target - current) > 0.0015) {
        raf = window.requestAnimationFrame(tick);
        return;
      }

      isTicking = false;
      raf = 0;
    }

    function startTick() {
      if (isTicking) return;
      isTicking = true;
      raf = window.requestAnimationFrame(tick);
    }

    function measure() {
      const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 1;
      const patternRect = pattern.getBoundingClientRect();
      const triggerPoint = viewportHeight * 0.78;
      const travel = hero.offsetHeight * 0.72 + viewportHeight * 0.28;

      target = clamp((triggerPoint - patternRect.top) / travel, 0, 1);
      startTick();
    }

    function hookLenis() {
      const lenis = window._lenis;
      if (!lenis || typeof lenis.on !== 'function' || lenis.__nudotWorkHeroHooked) return;
      lenis.__nudotWorkHeroHooked = true;
      lenis.on('scroll', measure);
    }

    window.addEventListener('scroll', measure, { passive: true });
    window.addEventListener('resize', measure, { passive: true });
    window.addEventListener('orientationchange', measure, { passive: true });

    window.setTimeout(hookLenis, 250);
    window.setTimeout(hookLenis, 1000);

    apply(0);
    measure();
  }

  function initScrollDrift() {
    return; // work-drift-y 已停用

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const boxes = Array.from(document.querySelectorAll('.work-box'));
    if (!boxes.length) return;

    const states = boxes.map((box, index) => ({
      box,
      current: 0,
      target: 0,
      strength: 16 + (index % 3) * 5,
      direction: index % 2 === 0 ? -1 : 1
    }));

    let raf = 0;
    let isTicking = false;

    function clamp(value, min, max) {
      return Math.max(min, Math.min(max, value));
    }

    function measure() {
      const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 1;
      const viewportCenter = viewportHeight * 0.5;

      states.forEach((state) => {
        const rect = state.box.getBoundingClientRect();
        const elementCenter = rect.top + rect.height * 0.5;
        const distance = clamp((elementCenter - viewportCenter) / viewportHeight, -1, 1);
        state.target = distance * state.strength * state.direction;
      });

      startTick();
    }

    function tick() {
      let keepGoing = false;

      states.forEach((state) => {
        state.current += (state.target - state.current) * 0.12;

        if (Math.abs(state.target - state.current) > 0.05) {
          keepGoing = true;
        }

        state.box.style.setProperty('--work-drift-y', state.current.toFixed(2) + 'px');
      });

      if (keepGoing) {
        raf = window.requestAnimationFrame(tick);
        return;
      }

      isTicking = false;
      raf = 0;
    }

    function startTick() {
      if (isTicking) return;
      isTicking = true;
      raf = window.requestAnimationFrame(tick);
    }

    function hookLenis() {
      const lenis = window._lenis;
      if (!lenis || typeof lenis.on !== 'function' || lenis.__nudotWorkDriftHooked) return;
      lenis.__nudotWorkDriftHooked = true;
      lenis.on('scroll', measure);
    }

    window.addEventListener('scroll', measure, { passive: true });
    window.addEventListener('resize', measure, { passive: true });
    window.addEventListener('orientationchange', measure, { passive: true });

    window.setTimeout(hookLenis, 250);
    window.setTimeout(hookLenis, 1000);
    measure();
  }

  function refreshScrollSystems() {
    const refresh = () => {
      if (window.ScrollTrigger && typeof window.ScrollTrigger.refresh === 'function') {
        window.ScrollTrigger.refresh();
      }
    };

    refresh();
    window.setTimeout(refresh, 350);
    window.setTimeout(refresh, 900);
  }

  ready(function () {
    if (!document.body.classList.contains('page-work-pattern')) return;

    initReveal();
    initHeroParallax();
    initHoverVideos();
    initScrollDrift();
    // film grain / noise is now handled by the shared noise.js

    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(refreshScrollSystems).catch(refreshScrollSystems);
    } else {
      refreshScrollSystems();
    }
  });
})();
