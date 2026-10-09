(function () {
  'use strict';
  const dialog = document.querySelector('[data-evidence-dialog]');
  const body = document.querySelector('[data-preview-body]');
  if (!dialog || !body) return;
  document.querySelector('[data-print]')?.addEventListener('click', () => window.print());
  document.querySelectorAll('[data-preview]').forEach(button => button.addEventListener('click', () => {
    document.querySelector('[data-preview-title]').textContent = button.dataset.title;
    const media = document.createElement(button.dataset.preview === 'pdf' ? 'iframe' : 'img');
    media.src = button.dataset.url;
    if (media.tagName === 'IMG') media.alt = button.dataset.title;
    else media.title = button.dataset.title;
    body.replaceChildren(media);
    dialog.showModal();
  }));
  document.querySelector('[data-close-preview]').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => body.replaceChildren());
  dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
})();
