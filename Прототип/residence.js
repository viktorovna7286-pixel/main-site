if (!document.querySelector(".lab").hidden) {
const fontMap={cormorant:['Cormorant, Georgia, serif',1],noto:['Noto, Georgia, serif',.8],unbounded:['Unbounded, Arial, sans-serif',.65],
  'rubik-golos':['Rubik, Arial, sans-serif',.85,'Golos Text, Arial, sans-serif'],
  'unbounded-onest':['Unbounded, Arial, sans-serif',.65,'Onest, Arial, sans-serif'],
  'unbounded-montserrat':['Unbounded, Arial, sans-serif',.65,'Montserrat, Arial, sans-serif']
};
const styleToggle=document.getElementById('styleToggle'),stylePanel=document.getElementById('stylePanel');
styleToggle.onclick=()=>{stylePanel.hidden=!stylePanel.hidden;styleToggle.setAttribute('aria-expanded',String(!stylePanel.hidden))};
function setFont(key){if(!Object.hasOwn(fontMap,key))return;
  const [display,scale,text]=fontMap[key];
  const style=document.documentElement.style;
  style.setProperty('--display',display);
  style.setProperty('--font-scale',scale);
  style.setProperty('--body-font',text||'Manrope, Arial, sans-serif');
  style.setProperty('--paired-display',text?display:'');
  document.querySelectorAll('[data-font]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.font===key)));try{localStorage.setItem('residence-font-preset',key)}catch{}}
document.querySelectorAll('[data-font]').forEach(b=>b.onclick=()=>setFont(b.dataset.font));try{setFont(localStorage.getItem('residence-font-preset')||'unbounded-montserrat')}catch{}
const headingColor=document.getElementById('headingColor');
function setHeading(color){document.documentElement.style.setProperty('--heading',color);headingColor.value=color;document.getElementById('accentCode').textContent=color.toUpperCase()}
function readableAccent(color){const rgb=color.slice(1).match(/../g).map(v=>parseInt(v,16));const ink=getComputedStyle(document.documentElement).getPropertyValue('--ink').trim().slice(1).match(/../g).map(v=>parseInt(v,16));return '#'+rgb.map((v,i)=>Math.round(v*.45+ink[i]*.55).toString(16).padStart(2,'0')).join('')}
function resetHeading(){setHeading(readableAccent(getComputedStyle(document.documentElement).getPropertyValue('--accent').trim()))}
headingColor.oninput=()=>{setHeading(headingColor.value);try{localStorage.setItem('residence-heading',headingColor.value)}catch{}};
document.getElementById('resetAccent').onclick=()=>{try{localStorage.removeItem('residence-heading')}catch{}resetHeading()};
document.getElementById('themes').addEventListener('click',()=>{resetHeading();try{localStorage.removeItem('residence-heading')}catch{}});
document.querySelectorAll('.fields input').forEach(i=>i.addEventListener('input',resetHeading));resetHeading();try{const c=localStorage.getItem('residence-heading');if(/^#[0-9a-f]{6}$/i.test(c))setHeading(c)}catch{}
document.getElementById('save').addEventListener('click',resetHeading);
}
document.querySelectorAll('.stage').forEach(b=>b.onclick=()=>{const open=b.getAttribute('aria-expanded')!=='true';document.querySelectorAll('.stage').forEach(s=>s.setAttribute('aria-expanded',String(s===b&&open)))});
document.querySelectorAll('.service').forEach(d=>d.addEventListener('toggle',()=>{if(d.open)document.querySelectorAll('.service').forEach(o=>{if(o!==d)o.open=false})}));
if('IntersectionObserver' in window){const observer=new IntersectionObserver(entries=>entries.forEach(e=>{if(e.isIntersecting){e.target.classList.add('seen');observer.unobserve(e.target)}}),{threshold:.08});document.querySelectorAll('.section-heading,.about-grid,.principles,.gallery,.service-list,.budget-grid,.process-panels,.life-grid,.story,.comfort-grid,.clarity-grid').forEach(e=>{e.classList.add('reveal','ready');observer.observe(e)})}
document.querySelectorAll('dialog').forEach(d=>d.addEventListener('click',e=>{if(e.target===d){const r=d.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)d.close()}}));


document.querySelectorAll('.tabs [role=tab]').forEach((tab,i,all)=>{tab.tabIndex=tab.getAttribute('aria-selected')==='true'?0:-1;tab.addEventListener('click',()=>all.forEach(t=>t.tabIndex=t===tab?0:-1));tab.addEventListener('keydown',e=>{let next;if(e.key==='ArrowRight')next=(i+1)%all.length;if(e.key==='ArrowLeft')next=(i-1+all.length)%all.length;if(e.key==='Home')next=0;if(e.key==='End')next=all.length-1;if(next!==undefined){e.preventDefault();all[next].focus();all[next].click()}})});
const menuDialog=document.getElementById('siteMenu'),menuToggle=document.getElementById('menuToggle');
menuToggle.onclick=()=>{menuDialog.style.setProperty('--menu-top',Math.max(16,menuToggle.getBoundingClientRect().bottom+18)+'px');menuDialog.showModal();menuToggle.setAttribute('aria-expanded','true')};
document.getElementById('closeMenu').onclick=()=>menuDialog.close();
menuDialog.addEventListener('close',()=>{menuToggle.setAttribute('aria-expanded','false');menuToggle.focus()});
menuDialog.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>menuDialog.close()));
document.querySelectorAll('[data-channel]').forEach(a=>a.addEventListener('click',()=>{document.getElementById('briefChannel').value=a.dataset.channel}));
const briefForm = document.getElementById('briefForm');
const briefResult = document.getElementById('briefResult');
let briefRequestId;

briefForm.addEventListener('input', () => {
  briefRequestId = undefined;
  briefResult.hidden = true;
});

briefForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  const submit = briefForm.querySelector('[type="submit"]');
  if (submit.disabled) return;

  const { name, phone, channel, task } = Object.fromEntries(new FormData(briefForm));
  const digits = phone.replace(/\D/g, '');
  if (!name.trim() || digits.length < 10 || digits.length > 15) {
    briefResult.textContent = 'Укажите имя и корректный телефон.';
    briefResult.hidden = false;
    briefResult.focus();
    return;
  }

  briefRequestId ||= crypto.randomUUID();
  submit.disabled = true;
  submit.textContent = 'Отправляем…';
  briefResult.hidden = true;

  try {
    const response = await fetch('../leads-api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ request_id: briefRequestId, name, phone, channel, task, service: 'Ремонт под ключ' })
    });
    const result = await response.json();
    if (!response.ok || result.ok !== true) {
      briefResult.textContent = 'Не удалось отправить заявку. Попробуйте ещё раз или позвоните нам.';
    } else {
      briefForm.reset();
      briefRequestId = undefined;
      briefResult.textContent = 'Спасибо! Мы скоро свяжемся с вами.';
    }
  } catch {
    briefResult.textContent = 'Не удалось отправить заявку. Попробуйте ещё раз или позвоните нам.';
  } finally {
    submit.disabled = false;
    submit.textContent = 'Отправить заявку';
    briefResult.hidden = false;
    briefResult.focus();
  }
});
