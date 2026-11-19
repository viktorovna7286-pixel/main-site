(() => {
  const nav = document.querySelector('.section-nav');
  if (!nav) return;
  const links = [...nav.querySelectorAll('a')];
  const sections = links.map(a => document.querySelector(a.getAttribute('href')));
  const previous = nav.querySelector('[data-section-step="-1"]');
  const next = nav.querySelector('[data-section-step="1"]');
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  let active = 0, frame = 0, wheelSum = 0, wheelTime = 0, lockedUntil = 0;
  function select(index) {
    active = index;
    nav.dataset.surface = sections[index].matches(".projects,.stories,.clarity,.contact") ? "dark" : "light";
    links.forEach((link, i) => {
      if (i === index) link.setAttribute('aria-current', 'location');
      else link.removeAttribute('aria-current');
    });
    previous.disabled = index === 0;
    next.disabled = index === sections.length - 1;
    const link = links[index], track = link.parentElement;
    if (link.offsetLeft < track.scrollLeft || link.offsetLeft + link.offsetWidth > track.scrollLeft + track.clientWidth) {
      track.scrollTo({left: link.offsetLeft - track.offsetLeft - track.clientWidth / 2 + link.offsetWidth / 2, behavior: reduced.matches ? 'instant' : 'smooth'});
    }
  }
  function sync() {
    frame = 0;
    const line = nav.getBoundingClientRect().height + 100;
    let index = 0;
    sections.forEach((section, i) => { if (section.getBoundingClientRect().top <= line) index = i; });
    if (innerHeight + scrollY >= document.documentElement.scrollHeight - 3) index = sections.length - 1;
    if (index !== active) select(index);
  }
  function go(index, sideways = false) {
    if (index < 0 || index >= sections.length) return;
    const direction = Math.sign(index - active);
    select(index);
    const top = sections[index].getBoundingClientRect().top + scrollY - nav.getBoundingClientRect().height;
    window.scrollTo({top, behavior: sideways || reduced.matches ? 'instant' : 'smooth'});
    if (sideways && !reduced.matches) {
      sections[index].animate([{opacity:.45, transform:`translateX(${direction * 32}px)`},{opacity:1, transform:'translateX(0)'}], {duration:280, easing:'ease-out'});
    }
  }
  links.forEach((link, index) => link.addEventListener('click', e => { e.preventDefault(); go(index); history.replaceState(null, '', link.getAttribute('href')); }));
  previous.addEventListener('click', () => go(active - 1, true));
  next.addEventListener('click', () => go(active + 1, true));
  nav.addEventListener('keydown', e => {
    if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
    e.preventDefault(); go(active + (e.key === 'ArrowRight' ? 1 : -1), true); links[active].focus({preventScroll:true});
  });
  const main = document.querySelector('main');
  const excluded = target => !!target.closest('a,button,input,select,textarea,summary,dialog,.section-nav,.tabs,.gallery,.service-tabs');
  const available = () => document.getElementById('home').getBoundingClientRect().bottom <= nav.offsetHeight + 5 && !document.querySelector('dialog[open]');
  let touch;
  main.addEventListener('touchstart', e => {
    touch = e.touches.length === 1 && available() && !excluded(e.target) ? {x:e.touches[0].clientX,y:e.touches[0].clientY} : null;
  }, {passive:true});
  main.addEventListener('touchend', e => {
    if (!touch || !e.changedTouches.length) return;
    const dx = e.changedTouches[0].clientX - touch.x, dy = e.changedTouches[0].clientY - touch.y;
    touch = null;
    if (Math.abs(dx) > 75 && Math.abs(dx) > Math.abs(dy) * 1.8) go(active + (dx < 0 ? 1 : -1), true);
  }, {passive:true});
  main.addEventListener('touchcancel', () => {touch = null;}, {passive:true});
  main.addEventListener('wheel', e => {
    if (!available() || excluded(e.target) || e.ctrlKey || e.shiftKey || Math.abs(e.deltaX) < Math.abs(e.deltaY) * 1.8 || Math.abs(e.deltaX) < 3) return;
    const target = active + (e.deltaX > 0 ? 1 : -1);
    if (target < 0 || target >= sections.length) return;
    e.preventDefault();
    const now = performance.now();
    if (now < lockedUntil) return;
    if (now - wheelTime > 180 || Math.sign(wheelSum) !== Math.sign(e.deltaX)) wheelSum = 0;
    wheelTime = now; wheelSum += e.deltaX;
    if (Math.abs(wheelSum) > 85) { go(target, true); wheelSum = 0; lockedUntil = now + 650; }
  }, {passive:false});
  new ResizeObserver(() => document.documentElement.style.scrollPaddingTop = `${nav.offsetHeight}px`).observe(nav);
  addEventListener('scroll', () => { if (!frame) frame = requestAnimationFrame(sync); }, {passive:true});
  addEventListener('resize', sync);
  select(0); sync();
})();
