(function () {
  "use strict";
  var dialog = document.querySelector("[data-services-dialog]");
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
