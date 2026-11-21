const apartmentFPhotos = apartmentFRooms.flatMap(({ room, ids }) =>
  ids.map(id => ({ id, room }))
);
const caseDescriptions = {
  'upper-bathroom-wide': 'Верхний санузел: светлая ниша и отделка душевой',
  'bathroom-retouched': 'Раковина и душевая в отражении',
  '7060-retouched': 'Гостиная с подсвеченной лестницей',
  '7108-retouched': 'Кухонный остров у окна',
  '7143': 'Подсветка ступеней и стеклянное ограждение',
  '7181': 'Спальня на втором уровне',
  '7192': 'Туалетный столик у окна',
  '7117-retouched': 'Санузел с тонированной перегородкой',
  '7126-retouched': 'Раковина и овальное зеркало'
};

function bindCase(caseDialog, caseLink, photos, caseAssets, descriptions = {}, useRoomAsDescription = false, assetQuery = '') {
  const caseImage = caseDialog.querySelector('.f-case-stage img');
  const caseThumbs = caseDialog.querySelector('.f-case-thumbs');
  const caseCounter = caseDialog.querySelector('.f-case-counter');
  const caseRoom = caseDialog.querySelector('.f-case-room');
  const describePhoto = (id, room, index) => descriptions[id] || (useRoomAsDescription ? room : room + ', фотография ' + (index + 1));
  let caseIndex = 0;

  const caseButtons = photos.map(({ id, room }, index) => {
    const button = document.createElement('button');
    const thumb = document.createElement('img');
    button.type = 'button';
    button.className = 'f-case-thumb';
    button.setAttribute('aria-label', describePhoto(id, room, index));
    thumb.src = caseAssets + id + '-thumb.webp' + assetQuery;
    thumb.alt = '';
    thumb.loading = 'lazy';
    thumb.decoding = 'async';
    button.append(thumb);
    button.addEventListener('click', () => showCasePhoto(index));
    return button;
  });

  function showCasePhoto(index) {
    caseButtons[caseIndex].removeAttribute('aria-current');
    caseIndex = (index + photos.length) % photos.length;
    const { id, room } = photos[caseIndex];
    caseRoom.textContent = room;
    caseImage.alt = describePhoto(id, room, caseIndex);
    caseImage.src = caseAssets + id + '.webp' + assetQuery;
    caseCounter.textContent = (caseIndex + 1) + ' / ' + photos.length;
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
}

const caseGalleries = {
  'apartment-f-dialog': [apartmentFPhotos, 'assets/apartment-f/gallery/', caseDescriptions],
  'apartment-g-dialog': [apartmentGPhotos, 'assets/apartment-g/', {}, true, '?v=5']
};

document.querySelectorAll('[data-case]').forEach(link => {
  const id = link.getAttribute('aria-controls');
  bindCase(document.getElementById(id), link, ...caseGalleries[id]);
});
