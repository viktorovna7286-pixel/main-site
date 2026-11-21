const fontMap={cormorant:['Cormorant, Georgia, serif',1],noto:['Noto, Georgia, serif',.8],unbounded:['Unbounded, Arial, sans-serif',.65]};
const styleToggle=document.getElementById('styleToggle'),stylePanel=document.getElementById('stylePanel');
styleToggle.onclick=()=>{stylePanel.hidden=!stylePanel.hidden;styleToggle.setAttribute('aria-expanded',String(!stylePanel.hidden))};
function setFont(key){if(!fontMap[key])return;document.documentElement.style.setProperty('--display',fontMap[key][0]);document.documentElement.style.setProperty('--font-scale',fontMap[key][1]);document.querySelectorAll('[data-font]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.font===key)));try{localStorage.setItem('residence-font',key)}catch{}}
document.querySelectorAll('[data-font]').forEach(b=>b.onclick=()=>setFont(b.dataset.font));try{setFont(localStorage.getItem('residence-font')||'cormorant')}catch{}
const headingColor=document.getElementById('headingColor');
function setHeading(color){document.documentElement.style.setProperty('--heading',color);headingColor.value=color;document.getElementById('accentCode').textContent=color.toUpperCase()}
function readableAccent(color){const rgb=color.slice(1).match(/../g).map(v=>parseInt(v,16));const ink=getComputedStyle(document.documentElement).getPropertyValue('--ink').trim().slice(1).match(/../g).map(v=>parseInt(v,16));return '#'+rgb.map((v,i)=>Math.round(v*.45+ink[i]*.55).toString(16).padStart(2,'0')).join('')}
function resetHeading(){setHeading(readableAccent(getComputedStyle(document.documentElement).getPropertyValue('--accent').trim()))}
headingColor.oninput=()=>{setHeading(headingColor.value);try{localStorage.setItem('residence-heading',headingColor.value)}catch{}};
document.getElementById('resetAccent').onclick=()=>{try{localStorage.removeItem('residence-heading')}catch{}resetHeading()};
document.getElementById('themes').addEventListener('click',()=>{resetHeading();try{localStorage.removeItem('residence-heading')}catch{}});
document.querySelectorAll('.fields input').forEach(i=>i.addEventListener('input',resetHeading));resetHeading();try{const c=localStorage.getItem('residence-heading');if(/^#[0-9a-f]{6}$/i.test(c))setHeading(c)}catch{}
document.querySelectorAll('.stage').forEach(b=>b.onclick=()=>{const open=b.getAttribute('aria-expanded')!=='true';document.querySelectorAll('.stage').forEach(s=>s.setAttribute('aria-expanded',String(s===b&&open)))});
document.querySelectorAll('.service').forEach(d=>d.addEventListener('toggle',()=>{if(d.open)document.querySelectorAll('.service').forEach(o=>{if(o!==d)o.open=false})}));
if('IntersectionObserver' in window){const observer=new IntersectionObserver(entries=>entries.forEach(e=>{if(e.isIntersecting){e.target.classList.add('seen');observer.unobserve(e.target)}}),{threshold:.08});document.querySelectorAll('.section-heading,.about-grid,.principles,.gallery,.service-list,.budget-grid,.process-panels,.life-grid,.story,.comfort-grid,.clarity-grid').forEach(e=>{e.classList.add('reveal','ready');observer.observe(e)})}
document.querySelectorAll('dialog').forEach(d=>d.addEventListener('click',e=>{if(e.target===d){const r=d.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)d.close()}}));

document.getElementById('save').addEventListener('click',resetHeading);
const menuDialog=document.getElementById('siteMenu'),menuToggle=document.getElementById('menuToggle');
menuToggle.onclick=()=>{menuDialog.style.setProperty('--menu-top',Math.max(16,menuToggle.getBoundingClientRect().bottom+18)+'px');menuDialog.showModal();menuToggle.setAttribute('aria-expanded','true')};
document.getElementById('closeMenu').onclick=()=>menuDialog.close();
menuDialog.addEventListener('close',()=>{menuToggle.setAttribute('aria-expanded','false');menuToggle.focus()});
menuDialog.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>menuDialog.close()));
document.querySelectorAll('[data-channel]').forEach(a=>a.addEventListener('click',()=>{document.getElementById('briefChannel').value=a.dataset.channel}));
let briefBlobUrl;
document.getElementById('briefForm').addEventListener('submit',e=>{e.preventDefault();const form=e.currentTarget;if(!form.reportValidity())return;const name=form.elements.name.value.trim(),phone=form.elements.phone.value.trim();if(!name||phone.replace(/\D/g,'').length<7){document.getElementById('briefPhone').setCustomValidity('Укажите телефон, содержащий не менее 7 цифр.');document.getElementById('briefPhone').reportValidity();return}const content=['Заявка на обсуждение проекта — Atlas Group','', 'Имя: '+name,'Телефон: '+phone,'Удобный способ связи: '+form.elements.channel.value,'','О проекте:',form.elements.task.value.trim()||'Пожелания пока не указаны.','','Подготовлено локально. Заявка не отправлена.'].join('\n');if(briefBlobUrl)URL.revokeObjectURL(briefBlobUrl);briefBlobUrl=URL.createObjectURL(new Blob(['\ufeff'+content],{type:'text/plain;charset=utf-8'}));document.getElementById('downloadBrief').href=briefBlobUrl;document.getElementById('briefResult').hidden=false;document.getElementById('downloadBrief').focus()});
document.getElementById('briefPhone').addEventListener('input',e=>e.target.setCustomValidity(''));
