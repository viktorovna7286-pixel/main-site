function galleryPhotos(page, base) {
  return [...page.querySelectorAll('.case-fallback section')].flatMap(section => {
    const room = section.querySelector('h2').textContent;
    return [...section.querySelectorAll('.photos a')].map(link => {
      const photo = link.querySelector('img');
      return {
        room,
        description: photo.alt,
        src: new URL(link.getAttribute('href'), base).href,
        thumb: new URL(photo.getAttribute('srcset').trim().split(/\s+/)[0], base).href
      };
    });
  });
}

const apartmentPage = document.querySelector('[data-apartment-page]');
if (apartmentPage) {
  bindCase(apartmentPage, [], galleryPhotos(apartmentPage, location.href));
  apartmentPage.classList.add('is-ready');
}

async function prepareApartmentDialog(url) {
  const response = await fetch(url);
  if (!response.ok) throw new Error('Case page unavailable');
  const page = new DOMParser().parseFromString(await response.text(), 'text/html')
    .querySelector('[data-apartment-page]');
  if (!page) throw new Error('Case page missing gallery');
  const photos = galleryPhotos(page, response.url);
  if (!photos.length) throw new Error('Case gallery is empty');

  const dialog = document.createElement('dialog');
  dialog.className = 'case-dialog apartment-gallery-dialog';
  dialog.id = page.id + '-dialog';
  dialog.setAttribute('aria-labelledby', page.getAttribute('aria-labelledby'));
  const header = page.querySelector('.f-case-header').cloneNode(true);
  const title = header.querySelector('h1');
  const heading = document.createElement('h2');
  heading.id = title.id;
  heading.textContent = title.textContent;
  title.replaceWith(heading);
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'f-case-close';
  close.setAttribute('aria-label', 'Закрыть кейс');
  close.autofocus = true;
  close.textContent = '×';
  header.querySelector('.f-case-close').replaceWith(close);
  dialog.append(header, page.querySelector('.f-case-layout'), page.querySelector('.f-case-credit'));
  document.body.append(dialog);
  return bindCase(dialog, [], photos);
}

let latestGalleryLink;
document.querySelectorAll('[data-apartment-gallery]').forEach(link => {
  let loading;
  link.addEventListener('click', async event => {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    latestGalleryLink = link;
    link.setAttribute('aria-busy', 'true');
    loading ||= prepareApartmentDialog(link.href);
    try {
      const open = await loading;
      if (latestGalleryLink === link) {
        latestGalleryLink = null;
        open(link);
      }
    } catch {
      loading = undefined;
      if (latestGalleryLink === link) location.assign(link.href);
    } finally {
      link.removeAttribute('aria-busy');
    }
  });
});
