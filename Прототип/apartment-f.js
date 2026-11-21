const caseDialog = document.getElementById('apartment-f-dialog');
const caseLink = document.querySelector('[data-apartment-f]');
const caseImage = caseDialog.querySelector('.f-case-stage img');
const caseThumbs = caseDialog.querySelector('.f-case-thumbs');
const caseCounter = caseDialog.querySelector('.f-case-counter');
const caseRoom = caseDialog.querySelector('.f-case-room');
const apartmentFPhotos = apartmentFRooms.flatMap(({ room, ids }) =>
  ids.map(id => ({ id, room }))
);
const caseAssets = 'assets/apartment-f/gallery/';
const caseDescriptions = {
  'bathroom-retouched': 'Раковина и душевая в отражении',
  '7060': 'Гостиная с подсвеченной лестницей',
  '7108': 'Кухонный остров у окна',
  '7143': 'Подсветка ступеней и стеклянное ограждение',
  '7181': 'Спальня на втором уровне',
  '7192': 'Туалетный столик у окна',
  '7117': 'Санузел с тонированной перегородкой',
  '7126': 'Раковина и овальное зеркало'
};
let caseIndex = 0;

const caseButtons = apartmentFPhotos.map(({ id, room }, index) => {
  const button = document.createElement('button');
  const thumb = document.createElement('img');
  button.type = 'button';
  button.className = 'f-case-thumb';
  button.setAttribute('aria-label', caseDescriptions[id] || (room + ', фотография ' + (index + 1)));
  thumb.src = caseAssets + id + '-thumb.webp';
  thumb.alt = '';
  thumb.loading = 'lazy';
  thumb.decoding = 'async';
  button.append(thumb);
  button.addEventListener('click', () => showCasePhoto(index));
  return button;
});

function showCasePhoto(index) {
  caseButtons[caseIndex].removeAttribute('aria-current');
  caseIndex = (index + apartmentFPhotos.length) % apartmentFPhotos.length;
  const { id, room } = apartmentFPhotos[caseIndex];
  caseRoom.textContent = room;
  caseImage.alt = caseDescriptions[id] || (room + ', фотография ' + (caseIndex + 1));
  caseImage.src = caseAssets + id + '.webp';
  caseCounter.textContent = (caseIndex + 1) + ' / ' + apartmentFPhotos.length;
  const currentThumb = caseButtons[caseIndex];
  currentThumb.setAttribute('aria-current', 'true');
  const bounds = caseThumbs.getBoundingClientRect();
  const thumbBounds = currentThumb.getBoundingClientRect();
  if (thumbBounds.left < bounds.left || thumbBounds.right > bounds.right ||
      thumbBounds.top < bounds.top || thumbBounds.bottom > bounds.bottom) {
    caseThumbs.scrollBy({
      left: thumbBounds.left - bounds.left - 4,
      top: thumbBounds.top - bounds.top - 4
    });
  }
}

caseLink.addEventListener('click', event => {
  if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
  event.preventDefault();
  if (!caseThumbs.childElementCount) caseThumbs.append(...caseButtons);
  caseDialog.showModal();
  showCasePhoto(caseIndex);
});
caseDialog.querySelector('.f-case-close').addEventListener('click', () => caseDialog.close());
caseDialog.querySelector('[data-case-prev]').addEventListener('click', () => showCasePhoto(caseIndex - 1));
caseDialog.querySelector('[data-case-next]').addEventListener('click', () => showCasePhoto(caseIndex + 1));
caseDialog.addEventListener('keydown', event => {
  if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
  event.preventDefault();
  showCasePhoto(caseIndex + (event.key === 'ArrowLeft' ? -1 : 1));
  if (event.target.classList.contains('f-case-thumb')) caseButtons[caseIndex].focus({ preventScroll: true });
});
caseDialog.addEventListener('close', () => caseLink.focus({ preventScroll: true }));
