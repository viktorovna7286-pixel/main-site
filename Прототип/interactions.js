if (!document.querySelector(".lab").hidden) {
  const panel=document.getElementById('palettePanel');document.getElementById('paletteToggle').onclick=()=>{panel.hidden=!panel.hidden;document.getElementById('paletteToggle').setAttribute('aria-expanded',!panel.hidden)};
}
