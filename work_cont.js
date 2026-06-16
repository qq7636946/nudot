(function () {
  'use strict';

  function initHero() {
    if (!window.gsap || !window.ScrollTrigger) return;
    gsap.registerPlugin(ScrollTrigger);

    const heroImg = document.querySelector('.hero-bg img');
    if (!heroImg) return;

    gsap.fromTo(heroImg,
      { scale: 1.08 },
      {
        scale: 1,
        ease: 'none',
        scrollTrigger: {
          trigger: '.hero',
          start: 'top top',
          end: 'bottom top',
          scrub: 1.5
        }
      }
    );
  }

  // Wait for 'load' so Lenis is already initialised and connected to ScrollTrigger
  // before we create the scrub animation.
  window.addEventListener('load', initHero, { once: true });
})();
