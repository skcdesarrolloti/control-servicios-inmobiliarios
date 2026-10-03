(function () {
  "use strict";
  var dialog = document.querySelector("[data-services-dialog]");
  var previewUrl = null;
  document.addEventListener('change', function (event) {
    if (!event.target.matches('[data-services-payment-file]')) return;
    var preview = document.querySelector('[data-services-payment-preview]');
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    preview.hidden = true; preview.removeAttribute('src');
    var file = event.target.files[0];
    if (!file) return;
    if (file.size > 10 * 1024 * 1024 || !/\.pdf$/i.test(file.name)) { event.target.setCustomValidity('Selecciona un PDF de máximo 10 MB.'); event.target.reportValidity(); return; }
    event.target.setCustomValidity('');
    previewUrl = URL.createObjectURL(file); preview.src = previewUrl; preview.hidden = false;
  });
  document.addEventListener("click", function (event) {
    var print = event.target.closest("[data-services-print]");
    if (print) { window.print(); return; }
    var open = event.target.closest("[data-services-preview]");
    if (open && dialog) {
      dialog._trigger = open;
      dialog.querySelector("iframe").src = open.getAttribute("data-services-preview");
      dialog.showModal();
    }
    if (event.target.closest("[data-services-preview-close]") && dialog) dialog.close();
  });
  if (dialog) dialog.addEventListener("close", function () {
    dialog.querySelector("iframe").removeAttribute("src");
    if (dialog._trigger) dialog._trigger.focus();
  });
})();
