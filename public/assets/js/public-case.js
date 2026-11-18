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
(function () {
  'use strict';
  const form = document.querySelector('[data-reply-form]');
  if (!form) return;
  const feedback = form.querySelector('[data-reply-feedback]');
  const submit = form.querySelector('[data-reply-submit]');
  const defaultHint = feedback.textContent;
  const MB = 1024 * 1024;
  const problem = () => {
    let total = 0;
    for (const input of form.querySelectorAll('input[type="file"]')) {
      if (input.files.length > Number(input.dataset.maxFiles)) return `Puedes adjuntar máximo ${input.dataset.maxFiles} archivos en ese campo.`;
      for (const file of input.files) {
        if (file.size > 10 * MB) return `${file.name} pesa más de 10 MB.`;
        total += file.size;
      }
    }
    return total > 20 * MB ? 'Los adjuntos suman más de 20 MB.' : '';
  };
  const show = message => {
    feedback.textContent = message || defaultHint;
    feedback.classList.toggle('is-error', message !== '');
  };
  form.addEventListener('change', () => show(problem()));
  form.addEventListener('submit', event => {
    const message = problem();
    if (message) { event.preventDefault(); show(message); return; }
    submit.disabled = true;
    submit.textContent = 'Enviando…';
  });
})();
