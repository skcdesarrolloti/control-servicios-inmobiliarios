(function () {
  "use strict";

  var root = document.querySelector("[data-acta-create-page]");
  if (!root) return;

  var form = root.querySelector("[data-acta-create]");
  var busy = false;
  var sequence = root.querySelectorAll("[data-acta-item]").length || 1;
  var MAX_PHOTOS_PER_DAMAGE = 4;
  var MAX_PHOTOS_PER_ACT = 12;
  var MAX_SOURCE_PHOTO_BYTES = 25 * 1024 * 1024;

  function message(text, error) {
    var target = root.querySelector("[data-acta-message]");
    if (!target) return;
    target.textContent = text;
    target.classList.toggle("is-error", !!error);
    target.setAttribute("role", error ? "alert" : "status");
    target.scrollIntoView({ block: "nearest", behavior: "smooth" });
  }

  function notifyError(text) {
    message(text, true);
    if (typeof window.alert === "function") window.alert(text);
  }

  function selectedPhotos(input) {
    return Array.isArray(input._actaFiles) ? input._actaFiles.slice() : Array.from(input.files || []);
  }

  function syncPhotoInput(input, files) {
    if (typeof DataTransfer === "undefined") return false;
    var transfer = new DataTransfer();
    files.forEach(function (file) { transfer.items.add(file); });
    input.files = transfer.files;
    input._actaFiles = files.slice();
    return true;
  }

  function totalSelectedPhotos(exceptInput) {
    return Array.from(form.querySelectorAll("[data-acta-photos]")).reduce(function (sum, input) {
      return sum + (input === exceptInput ? 0 : selectedPhotos(input).length);
    }, 0);
  }

  function photoKey(file) {
    return [file.name, file.size, file.type, file.lastModified].join("|");
  }

  function photoPreview(input) {
    (input._actaPreviewUrls || []).forEach(function (url) { URL.revokeObjectURL(url); });
    input._actaPreviewUrls = [];
    var preview = input.closest("[data-acta-item]").querySelector("[data-acta-photo-preview]");
    var files = selectedPhotos(input);
    preview.innerHTML = "";
    files.forEach(function (file, index) {
      var url = URL.createObjectURL(file);
      input._actaPreviewUrls.push(url);
      var figure = document.createElement("figure");
      var image = document.createElement("img");
      var caption = document.createElement("figcaption");
      var remove = document.createElement("button");
      image.src = url;
      image.alt = "Vista previa de evidencia " + (index + 1);
      caption.textContent = file.name + " · " + Math.max(1, Math.round(file.size / 1024)) + " KB";
      remove.type = "button";
      remove.className = "scm-acta-photo-remove";
      remove.dataset.actaRemovePhoto = String(index);
      remove.setAttribute("aria-label", "Quitar foto " + (index + 1) + ": " + file.name);
      remove.innerHTML = '<span aria-hidden="true">×</span>';
      figure.appendChild(image);
      figure.appendChild(caption);
      figure.appendChild(remove);
      preview.appendChild(figure);
    });
    preview.setAttribute("aria-label", files.length ? files.length + " fotos seleccionadas" : "Sin fotos seleccionadas");
  }

  function addPhotos(input, incoming) {
    var current = selectedPhotos(input);
    var known = new Set(current.map(photoKey));
    var additions = [];
    for (var index = 0; index < incoming.length; index++) {
      var file = incoming[index];
      if (!["image/jpeg", "image/png", "image/webp"].includes(file.type) || file.size > MAX_SOURCE_PHOTO_BYTES) {
        syncPhotoInput(input, current);
        notifyError("Usa únicamente fotos JPG, PNG o WebP de máximo 25 MB cada una.");
        return false;
      }
      if (!known.has(photoKey(file))) {
        known.add(photoKey(file));
        additions.push(file);
      }
    }
    var next = current.concat(additions);
    if (next.length > MAX_PHOTOS_PER_DAMAGE) {
      syncPhotoInput(input, current);
      notifyError("Este daño admite máximo 4 fotos. Quita alguna antes de agregar otra.");
      return false;
    }
    if (totalSelectedPhotos(input) + next.length > MAX_PHOTOS_PER_ACT) {
      syncPhotoInput(input, current);
      notifyError("El acta admite máximo 12 fotos en total. Quita alguna foto antes de agregar otra.");
      return false;
    }
    if (!syncPhotoInput(input, next)) {
      input.value = "";
      input._actaFiles = [];
      photoPreview(input);
      notifyError("Tu navegador no permite combinar o quitar fotos. Actualiza Chrome e inténtalo nuevamente.");
      return false;
    }
    photoPreview(input);
    return true;
  }

  function pastedPhotos(event) {
    var clipboard = event.clipboardData || window.clipboardData;
    var items = clipboard && clipboard.items ? Array.from(clipboard.items) : [];
    return items.reduce(function (files, item, index) {
      if (!item || !/^image\//i.test(item.type || "")) return files;
      var blob = item.getAsFile();
      if (!blob) return files;
      var subtype = (blob.type.split("/")[1] || "png").replace("jpeg", "jpg");
      files.push(new File([blob], "captura-" + Date.now() + "-" + (index + 1) + "." + subtype, {
        type: blob.type || "image/png",
        lastModified: Date.now(),
      }));
      return files;
    }, []);
  }

  function decodePhoto(file) {
    if (window.createImageBitmap) {
      return createImageBitmap(file, { imageOrientation: "from-image" }).catch(function () { return createImageBitmap(file); });
    }
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var image = new Image();
      image.onload = function () { URL.revokeObjectURL(url); resolve(image); };
      image.onerror = function () { URL.revokeObjectURL(url); reject(new Error("No se pudo leer " + file.name + ".")); };
      image.src = url;
    });
  }

  function compressPhoto(file) {
    if (!["image/jpeg", "image/png", "image/webp"].includes(file.type) || file.size > MAX_SOURCE_PHOTO_BYTES) {
      return Promise.reject(new Error("Usa fotos JPG, PNG o WebP de máximo 25 MB antes de comprimir."));
    }
    return decodePhoto(file).then(function (image) {
      var width = image.width || image.naturalWidth;
      var height = image.height || image.naturalHeight;
      var ratio = Math.min(1, 1600 / width, 1600 / height);
      var canvas = document.createElement("canvas");
      canvas.width = Math.max(1, Math.round(width * ratio));
      canvas.height = Math.max(1, Math.round(height * ratio));
      var context = canvas.getContext("2d", { alpha: false });
      context.fillStyle = "#fff";
      context.fillRect(0, 0, canvas.width, canvas.height);
      context.drawImage(image, 0, 0, canvas.width, canvas.height);
      if (typeof image.close === "function") image.close();
      return new Promise(function (resolve, reject) {
        canvas.toBlob(function (blob) {
          blob
            ? resolve(new File([blob], file.name.replace(/\.[^.]+$/, "") + ".jpg", { type: "image/jpeg", lastModified: Date.now() }))
            : reject(new Error("No se pudo comprimir " + file.name + "."));
        }, "image/jpeg", 0.78);
      });
    });
  }

  function compressedFormData() {
    var data = new FormData(form);
    var inputs = Array.from(form.querySelectorAll("[data-acta-photos]"));
    var total = inputs.reduce(function (sum, input) { return sum + selectedPhotos(input).length; }, 0);
    if (total > MAX_PHOTOS_PER_ACT) return Promise.reject(new Error("El acta admite máximo 12 fotos en total."));
    return Promise.all(inputs.map(function (input) {
      var name = input.name;
      data.delete(name);
      return Promise.all(selectedPhotos(input).map(compressPhoto)).then(function (files) {
        files.forEach(function (file) { data.append(name, file, file.name); });
      });
    })).then(function () {
      var bytes = Array.from(data.entries()).reduce(function (sum, entry) {
        return sum + (entry[1] instanceof File ? entry[1].size : 0);
      }, 0);
      if (bytes > 8 * 1000 * 1000) throw new Error("Las fotos superan 8 MB después de comprimir. Retira algunas evidencias.");
      return data;
    });
  }

  function syncSigner() {
    var signer = form.querySelector("[data-acta-signer]");
    if (!signer) return;
    var option = signer.selectedOptions[0];
    form.querySelector("[data-acta-signer-name]").value = option && option.dataset.name ? option.dataset.name : "";
    form.querySelector("[data-acta-signer-email]").value = option && option.dataset.email ? option.dataset.email : "";
    form.querySelector("[data-acta-signer-phone]").value = option && option.dataset.phone ? option.dataset.phone : "";
  }

  function syncTotal() {
    var fee = Number(form.querySelector("[data-acta-fee]").value) || 0;
    var transport = Number(form.querySelector("[data-acta-transport]").value) || 0;
    form.querySelector("[data-acta-total]").textContent = new Intl.NumberFormat("es-CO", {
      style: "currency",
      currency: "COP",
      maximumFractionDigits: 0,
    }).format(fee + transport);
  }

  function submit() {
    if (busy) return;
    busy = true;
    message("Comprimiendo fotos y generando acta…", false);
    compressedFormData().then(function (data) {
      data.set("action", root.dataset.action || "scm_ticket_acta");
      data.set("nonce", root.dataset.nonce || "");
      data.set("ticket_pk", root.dataset.ticketPk || "");
      data.set("operation", "create");
      form.querySelectorAll("button").forEach(function (button) { button.disabled = true; });
      return fetch(root.dataset.apiUrl || "api.php", {
        method: "POST",
        body: data,
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });
    }).then(function (response) {
      return response.json();
    }).then(function (json) {
      if (!json || !json.success || !json.data) throw new Error(json && json.data && json.data.message || "No se pudo crear el acta.");
      message(json.data.message || "Acta creada. Te llevamos a Actas de satisfacción…", json.data.queued === false);
      window.location.assign(json.data.redirect_url || root.dataset.redirectUrl || "index.php?tab=actas_satisfaccion");
    }).catch(function (error) {
      notifyError(error.message || "No se pudo crear el acta. Revisa los datos e inténtalo nuevamente.");
    }).finally(function () {
      busy = false;
      form.querySelectorAll("button").forEach(function (button) { button.disabled = false; });
    });
  }

  if (!form) {
    return;
  }

  var signer = form.querySelector("[data-acta-signer]");
  if (signer) signer.addEventListener("change", syncSigner);
  var fee = form.querySelector("[data-acta-fee]");
  if (fee) fee.addEventListener("input", syncTotal);
  syncSigner();
  syncTotal();

  form.addEventListener("click", function (event) {
    var removePhoto = event.target.closest("[data-acta-remove-photo]");
    if (removePhoto) {
      var photoInput = removePhoto.closest("[data-acta-item]").querySelector("[data-acta-photos]");
      var files = selectedPhotos(photoInput);
      var removeIndex = Number(removePhoto.dataset.actaRemovePhoto);
      if (Number.isInteger(removeIndex) && removeIndex >= 0 && removeIndex < files.length) {
        files.splice(removeIndex, 1);
        syncPhotoInput(photoInput, files) ? photoPreview(photoInput) : notifyError("No se pudo quitar la foto en este navegador.");
      }
      return;
    }
    var removeItem = event.target.closest("[data-acta-remove-item]");
    if (removeItem) {
      if (form.querySelectorAll("[data-acta-item]").length === 1) {
        notifyError("Debes conservar al menos un daño y su solución.");
        return;
      }
      removeItem.closest("[data-acta-item]").remove();
      return;
    }
    var addItem = event.target.closest("[data-acta-add-item]");
    if (addItem) {
      var items = form.querySelector("[data-acta-items]");
      if (items.children.length >= 30) {
        notifyError("El acta admite hasta 30 daños y soluciones.");
        return;
      }
      var item = items.firstElementChild.cloneNode(true);
      item.querySelectorAll("textarea").forEach(function (field) {
        field.name = field.name.replace(/items\[\d+\]/, "items[" + sequence + "]");
        field.value = "";
      });
      var photoInput = item.querySelector("[data-acta-photos]");
      var photoHelp = item.querySelector("[data-acta-photo-help]");
      var helpId = "acta-photo-help-" + sequence;
      photoInput.name = "acta_item_photos_" + sequence + "[]";
      photoInput.value = "";
      photoInput._actaFiles = [];
      photoInput.setAttribute("aria-describedby", helpId);
      if (photoHelp) photoHelp.id = helpId;
      item.querySelector("[data-acta-photo-preview]").innerHTML = "";
      sequence++;
      items.appendChild(item);
      item.querySelector("textarea").focus();
    }
  });

  form.addEventListener("change", function (event) {
    if (event.target.matches("[data-acta-photos]")) addPhotos(event.target, Array.from(event.target.files || []));
  });

  form.addEventListener("paste", function (event) {
    var pasteButton = event.target.closest && event.target.closest("[data-acta-photo-paste]");
    if (!pasteButton) return;
    event.preventDefault();
    var input = pasteButton.closest("[data-acta-item]").querySelector("[data-acta-photos]");
    var files = pastedPhotos(event);
    files.length ? addPhotos(input, files) : notifyError("No se encontró una imagen en el portapapeles. Copia una captura y vuelve a presionar Ctrl+V.");
  });

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    submit();
  });
})();
