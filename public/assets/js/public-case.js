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
  const drop = form.querySelector('[data-media-drop]');
  const list = form.querySelector('[data-media-list]');
  const defaultHint = feedback.textContent;
  const MB = 1024 * 1024;
  const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
  const inputs = {image: form.querySelector('[data-media-input="image"]'), pdf: form.querySelector('[data-media-input="pdf"]')};
  const files = {image: [], pdf: []};
  const previews = new Map();
  const canAssign = typeof DataTransfer === 'function';

  const show = message => {
    feedback.textContent = message || defaultHint;
    feedback.classList.toggle('is-error', message !== '');
  };
  const problem = () => {
    let total = 0;
    for (const kind of Object.keys(inputs)) {
      const selected = canAssign ? files[kind] : [...inputs[kind].files];
      if (selected.length > Number(inputs[kind].dataset.maxFiles)) return `Puedes adjuntar máximo ${inputs[kind].dataset.maxFiles} ${kind === 'image' ? 'fotos' : 'documentos PDF'}.`;
      for (const file of selected) {
        if (file.size > 10 * MB) return `${file.name} pesa más de 10 MB.`;
        total += file.size;
      }
    }
    return total > 20 * MB ? 'Los adjuntos suman más de 20 MB.' : '';
  };
  const size = bytes => bytes >= MB ? `${(bytes / MB).toFixed(1).replace('.', ',')} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
  const kindOf = file => IMAGE_TYPES.includes(file.type) ? 'image' : (file.type === 'application/pdf' || /\.pdf$/i.test(file.name) ? 'pdf' : '');

  // Sin DataTransfer (navegadores muy antiguos) se usan los campos nativos tal cual.
  if (!canAssign || !drop) {
    form.addEventListener('change', () => show(problem()));
  } else {
    drop.classList.add('is-enhanced');
    drop.querySelector('.scm-public-case-media-actions').hidden = false;
    drop.querySelector('.scm-public-case-media-help').hidden = false;

    const sync = () => {
      for (const kind of Object.keys(inputs)) {
        const transfer = new DataTransfer();
        files[kind].forEach(file => transfer.items.add(file));
        inputs[kind].files = transfer.files;
      }
      for (const [file, url] of previews) {
        if (!files.image.includes(file)) { URL.revokeObjectURL(url); previews.delete(file); }
      }
      const items = [...files.image.map(file => ['image', file]), ...files.pdf.map(file => ['pdf', file])].map(([kind, file]) => {
        const item = document.createElement('li');
        item.className = 'scm-public-case-media-item';
        const thumb = document.createElement('span');
        thumb.className = 'scm-public-case-media-thumb';
        if (kind === 'image') {
          if (!previews.has(file)) previews.set(file, URL.createObjectURL(file));
          const img = document.createElement('img');
          img.src = previews.get(file);
          img.alt = '';
          thumb.append(img);
        } else {
          thumb.classList.add('is-pdf');
          thumb.textContent = 'PDF';
        }
        const name = document.createElement('span');
        name.className = 'scm-public-case-media-name';
        const title = document.createElement('b');
        title.textContent = file.name;
        title.title = file.name;
        const meta = document.createElement('small');
        meta.textContent = `${kind === 'image' ? 'Foto' : 'PDF'} · ${size(file.size)}`;
        name.append(title, meta);
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'scm-public-case-media-remove';
        remove.setAttribute('aria-label', `Quitar ${file.name}`);
        remove.textContent = '×';
        remove.addEventListener('click', () => {
          files[kind] = files[kind].filter(candidate => candidate !== file);
          sync();
          show(problem());
        });
        item.append(thumb, name, remove);
        return item;
      });
      list.replaceChildren(...items);
      list.hidden = items.length === 0;
    };

    const add = incoming => {
      let message = '';
      for (const file of incoming) {
        const kind = kindOf(file);
        if (!kind) { message = `${file.name}: usa fotos JPG, PNG o WebP, o documentos PDF.`; continue; }
        const max = Number(inputs[kind].dataset.maxFiles);
        if (files[kind].some(current => current.name === file.name && current.size === file.size && current.lastModified === file.lastModified)) continue;
        if (files[kind].length >= max) { message = `Puedes adjuntar máximo ${max} ${kind === 'image' ? 'fotos' : 'documentos PDF'}.`; continue; }
        files[kind].push(file);
      }
      sync();
      show(message || problem());
    };

    form.querySelectorAll('[data-media-pick]').forEach(button => button.addEventListener('click', () => inputs[button.dataset.mediaPick].click()));
    // El selector nativo reemplaza la selección: se suma a lo ya agregado y se vuelve a asignar la lista completa.
    Object.values(inputs).forEach(input => input.addEventListener('change', () => { if (input.files.length) add([...input.files]); }));
    ['dragenter', 'dragover'].forEach(type => drop.addEventListener(type, event => {
      if (![...(event.dataTransfer?.types || [])].includes('Files')) return;
      event.preventDefault();
      drop.classList.add('is-dragging');
    }));
    ['dragleave', 'drop'].forEach(type => drop.addEventListener(type, event => {
      if (type === 'dragleave' && drop.contains(event.relatedTarget)) return;
      drop.classList.remove('is-dragging');
    }));
    drop.addEventListener('drop', event => {
      event.preventDefault();
      add([...(event.dataTransfer?.files || [])]);
    });
    form.addEventListener('paste', event => {
      const pasted = [...(event.clipboardData?.files || [])].filter(file => IMAGE_TYPES.includes(file.type));
      if (!pasted.length) return;
      event.preventDefault();
      add(pasted.map((file, index) => new File([file], `captura-${Date.now()}-${index + 1}.${file.type.split('/')[1].replace('jpeg', 'jpg')}`, {type: file.type, lastModified: Date.now()})));
    });
  }

  form.addEventListener('submit', event => {
    const message = problem();
    if (message) { event.preventDefault(); show(message); return; }
    submit.disabled = true;
    submit.textContent = 'Enviando…';
  });
})();
