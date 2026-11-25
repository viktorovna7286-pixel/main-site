(() => {
  const bar = document.querySelector('.home-bar');
  if (!bar) return;

  const links = [...bar.querySelectorAll('.home-nav a')];
  const sections = links.map(link => document.getElementById(link.hash.slice(1)));
  let frame = 0;

  function sync() {
    frame = 0;
    bar.classList.toggle('is-scrolled', window.scrollY > 8);
    const line = bar.offsetHeight + 20;
    let active = -1;
    sections.forEach((section, index) => {
      if (section && section.getBoundingClientRect().top <= line) active = index;
    });
    links.forEach((link, index) => {
      if (index === active) link.setAttribute('aria-current', 'location');
      else link.removeAttribute('aria-current');
    });
  }

  function resize() {
    const height = bar.offsetHeight + 'px';
    document.documentElement.style.setProperty('--home-bar-height', height);
    document.documentElement.style.scrollPaddingTop = height;
    sync();
  }

  addEventListener('scroll', () => {
    if (!frame) frame = requestAnimationFrame(sync);
  }, { passive: true });
  new ResizeObserver(resize).observe(bar);
  resize();
})();
