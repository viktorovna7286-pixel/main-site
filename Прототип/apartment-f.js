const apartmentFPhotos = typeof apartmentFRooms === 'undefined' ? [] : apartmentFRooms.flatMap(({ room, ids }) =>
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

function bindCase(caseDialog, caseLinks, photos, caseAssets, descriptions = {}, assetQuery = '') {
  const caseImage = caseDialog.querySelector('.f-case-stage img');
  const caseThumbs = caseDialog.querySelector('.f-case-thumbs');
  const caseCounter = caseDialog.querySelector('.f-case-counter');
  const caseRoom = caseDialog.querySelector('.f-case-room');
  const describePhoto = (photo, index) => photo.description || descriptions[photo.id] || photo.room + ', фотография ' + (index + 1);
  let caseIndex = 0;
  let opener;

  const caseButtons = photos.map((photo, index) => {
    const button = document.createElement('button');
    const thumb = document.createElement('img');
    button.type = 'button';
    button.className = 'f-case-thumb';
    button.setAttribute('aria-label', describePhoto(photo, index));
    thumb.src = photo.thumb || caseAssets + photo.id + '-thumb.webp' + assetQuery;
    thumb.alt = '';
    thumb.loading = 'lazy';
    thumb.decoding = 'async';
    button.append(thumb);
    button.addEventListener('click', () => showCasePhoto(index));
    return button;
  });

  function showCasePhoto(index) {
    caseButtons[caseIndex].removeAttribute('aria-current');
    caseIndex = index;
    const photo = photos[caseIndex];
    caseRoom.textContent = photo.description || photo.room;
    caseImage.alt = describePhoto(photo, caseIndex);
    caseImage.src = photo.src || caseAssets + photo.id + '.webp' + assetQuery;
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

  function movePhoto(offset) {
    showCasePhoto((caseIndex + offset + photos.length) % photos.length);
  }

  function renderThumbnails() {
    if (caseThumbs.childElementCount) return;
    photos.forEach((photo, index) => {
      if (photo.room && photo.room !== photos[index - 1]?.room) {
        const heading = document.createElement('h4');
        heading.className = 'f-case-thumb-room';
        heading.textContent = photo.room;
        caseThumbs.append(heading);
      }
      caseThumbs.append(caseButtons[index]);
    });
  }

  function openCase(link) {
    opener = link;
    renderThumbnails();
    caseDialog.showModal();
    showCasePhoto(caseIndex);
  }

  if (caseDialog instanceof HTMLDialogElement) {
    caseLinks.forEach(link => link.addEventListener('click', event => {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      openCase(link);
    }));
    caseDialog.querySelector('.f-case-close').addEventListener('click', () => caseDialog.close());
    caseDialog.addEventListener('close', () => opener?.focus({ preventScroll: true }));
  } else {
    renderThumbnails();
    showCasePhoto(caseIndex);
  }
  caseDialog.querySelector('[data-case-prev]').addEventListener('click', () => movePhoto(-1));
  caseDialog.querySelector('[data-case-next]').addEventListener('click', () => movePhoto(1));
  caseDialog.addEventListener('keydown', event => {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    event.preventDefault();
    movePhoto(event.key === 'ArrowLeft' ? -1 : 1);
    if (event.target.classList.contains('f-case-thumb')) caseButtons[caseIndex].focus({ preventScroll: true });
  });
  return openCase;
}

const apartmentFDialog = document.getElementById('apartment-f-dialog');
if (apartmentFDialog && apartmentFPhotos.length) {
  bindCase(apartmentFDialog, document.querySelectorAll('[data-case][aria-controls="apartment-f-dialog"]'),
    apartmentFPhotos, 'assets/apartment-f/gallery/', caseDescriptions);
}
const apartmentGDialog = document.getElementById('apartment-g-dialog');
if (apartmentGDialog && typeof apartmentGPhotos !== 'undefined') {
  bindCase(apartmentGDialog, document.querySelectorAll('[data-case][aria-controls="apartment-g-dialog"]'),
    apartmentGPhotos, apartmentGDialog.dataset.caseAssets || 'assets/apartment-g/', {}, '?v=5');
}
