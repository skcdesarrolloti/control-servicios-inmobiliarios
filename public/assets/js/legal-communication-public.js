(function () {
  "use strict";
  var dialog = document.querySelector("[data-lcp-dialog]");
  var body = dialog.querySelector("[data-lcp-body]");
  var trigger = null;
  var objectUrl = "";

  function message(text) {
    var p = document.createElement("p");
    p.textContent = text;
    body.replaceChildren(p);
  }

  function show(url, mime, name) {
    if (mime === "application/pdf") {
      message("Cargando documento…");
      // file.php responde con CSP sandbox, que impide al visor de PDF abrirlo directo en un iframe.
      fetch(url).then(function (response) {
        if (!response.ok) throw new Error("No disponible");
        return response.blob();
      }).then(function (blob) {
        objectUrl = URL.createObjectURL(new Blob([blob], { type: "application/pdf" }));
        var frame = document.createElement("iframe");
        frame.title = name;
        frame.src = objectUrl;
        body.replaceChildren(frame);
      }).catch(function () { message("No se pudo mostrar el documento. Usa el botón Descargar."); });
      return;
    }
    if (mime.indexOf("image/") === 0) {
      var image = document.createElement("img");
      image.alt = name;
      image.src = url;
      body.replaceChildren(image);
      return;
    }
    message("Este archivo no se puede ver aquí. Usa el botón Descargar.");
  }

  document.addEventListener("click", function (event) {
    if (event.target.closest("[data-lcp-print]")) return window.print();
    var file = event.target.closest("[data-lcp-file]");
    if (file) {
      trigger = file;
      var url = file.getAttribute("data-lcp-file");
      var name = file.getAttribute("data-lcp-name");
      dialog.querySelector("[data-lcp-title]").textContent = name;
      dialog.querySelector("[data-lcp-download]").href = url;
      show(url, file.getAttribute("data-lcp-mime"), name);
      dialog.showModal();
    }
    if (event.target.closest("[data-lcp-close]")) dialog.close();
    return undefined;
  });
  dialog.addEventListener("close", function () {
    body.replaceChildren();
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = "";
    if (trigger) trigger.focus();
  });
})();
