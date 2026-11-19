(() => {
  const list = document.querySelector('.service-tabs');
  if (!list) return;
  const tabs = [...list.querySelectorAll('[role=tab]')];
  const panels = tabs.map(tab => document.getElementById(tab.getAttribute('aria-controls')));
  function select(index, focus = false) {
    tabs.forEach((tab, i) => {
      tab.setAttribute('aria-selected', String(i === index));
      tab.tabIndex = i === index ? 0 : -1;
      panels[i].hidden = i !== index;
    });
    if (focus) tabs[index].focus({preventScroll:true});
    if (list.scrollWidth > list.clientWidth) {
      const tab = tabs[index];
      list.scrollTo({left:tab.offsetLeft - list.offsetLeft - 12, behavior:matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'});
    }
  }
  function orientation() { list.setAttribute('aria-orientation', matchMedia('(max-width:600px)').matches ? 'horizontal' : 'vertical'); }
  tabs.forEach((tab, i) => {
    tab.addEventListener('click', () => select(i));
    tab.addEventListener('keydown', event => {
      const horizontal = list.getAttribute('aria-orientation') === 'horizontal';
      let next;
      if (event.key === (horizontal ? 'ArrowRight' : 'ArrowDown')) next = (i+1) % tabs.length;
      if (event.key === (horizontal ? 'ArrowLeft' : 'ArrowUp')) next = (i-1+tabs.length) % tabs.length;
      if (event.key === 'Home') next = 0;
      if (event.key === 'End') next = tabs.length-1;
      if (next !== undefined) { event.preventDefault(); select(next, true); }
    });
  });
  orientation(); addEventListener('resize', orientation);
})();
