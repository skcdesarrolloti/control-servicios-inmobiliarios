(function () {
  "use strict";
  // Compresión compartida de evidencias (actas, revisión correctiva/preventiva).
  // En celulares, createImageBitmap a resolución completa falla con "The source image could
  // not be decoded" cuando falta memoria; por eso se decodifica ya reducida, una foto a la vez,
  // con respaldo en <img> y, si nada funciona, se envía el original (el servidor la comprime).
  var MAX_SIDE = 1600;
  var QUALITY = 0.78;
  var MAX_SOURCE_BYTES = 25 * 1024 * 1024;
  var MAX_PASSTHROUGH_BYTES = Math.floor(9.5 * 1024 * 1024);
  var TYPES = { "image/jpeg": 1, "image/jpg": 1, "image/png": 1, "image/webp": 1 };
  var SERVER_COMPRESSED_BYTES = 1500000;
  var cache = typeof WeakMap === "function" ? new WeakMap() : null;
  var uncompressed = typeof WeakSet === "function" ? new WeakSet() : null;
  var queue = Promise.resolve();

  function imageType(file) {
    var type = String(file.type || "").toLowerCase();
    if (TYPES[type]) return type === "image/jpg" ? "image/jpeg" : type;
    if (!type) {
      var ext = (String(file.name || "").match(/\.([a-z0-9]+)$/i) || [])[1] || "";
      ext = ext.toLowerCase();
      if (ext === "jpg" || ext === "jpeg") return "image/jpeg";
      if (ext === "png" || ext === "webp") return "image/" + ext;
    }
    return "";
  }

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var image = new Image();
      image.onload = function () {
        resolve({ image: image, url: url });
      };
      image.onerror = function () {
        URL.revokeObjectURL(url);
        reject(new Error("No se pudo leer " + file.name + "."));
      };
      image.src = url;
    });
  }

  function targetSize(width, height) {
    var ratio = Math.min(1, MAX_SIDE / width, MAX_SIDE / height);
    return {
      width: Math.max(1, Math.round(width * ratio)),
      height: Math.max(1, Math.round(height * ratio)),
    };
  }

  function encode(source, size, file) {
    var canvas = document.createElement("canvas");
    canvas.width = size.width;
    canvas.height = size.height;
    var context = canvas.getContext("2d", { alpha: false });
    context.fillStyle = "#fff";
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(source, 0, 0, canvas.width, canvas.height);
    if (typeof source.close === "function") source.close();
    return new Promise(function (resolve, reject) {
      canvas.toBlob(
        function (blob) {
          canvas.width = canvas.height = 0;
          blob && blob.size
            ? resolve(
                new File([blob], String(file.name || "foto").replace(/\.[^.]+$/, "") + ".jpg", {
                  type: "image/jpeg",
                  lastModified: Date.now(),
                }),
              )
            : reject(new Error("No se pudo comprimir " + file.name + "."));
        },
        "image/jpeg",
        QUALITY,
      );
    });
  }

  function bitmap(file, size) {
    if (!window.createImageBitmap) return Promise.reject(new Error("sin createImageBitmap"));
    var resized = size
      ? { resizeWidth: size.width, resizeHeight: size.height, resizeQuality: "high" }
      : {};
    return createImageBitmap(file, Object.assign({ imageOrientation: "from-image" }, resized))
      .catch(function () {
        return createImageBitmap(file, resized);
      });
  }

  function passthrough(file, type, error) {
    if (file.size > 0 && file.size <= MAX_PASSTHROUGH_BYTES) {
      var original = file.type === type ? file : new File([file], file.name, { type: type, lastModified: file.lastModified || Date.now() });
      if (uncompressed) uncompressed.add(original);
      return original;
    }
    throw new Error(
      "No se pudo procesar la foto " + file.name + " en este celular. Tómala de nuevo o elige otra imagen." +
        (error && error.message ? " (" + error.message + ")" : ""),
    );
  }

  function run(file) {
    var type = imageType(file);
    if (!type || file.size > MAX_SOURCE_BYTES) {
      return Promise.reject(new Error("Usa fotos JPG, PNG o WebP de máximo 25 MB antes de comprimir."));
    }
    if (!file.size) {
      return Promise.reject(new Error("La foto " + file.name + " está vacía o ya no está disponible. Vuelve a seleccionarla."));
    }
    // 1) Medir con <img> (solo lee la cabecera) para decodificar ya reducida.
    return loadImage(file).then(
      function (loaded) {
        var size = targetSize(loaded.image.naturalWidth || loaded.image.width, loaded.image.naturalHeight || loaded.image.height);
        function done(result) {
          URL.revokeObjectURL(loaded.url);
          return result;
        }
        return bitmap(file, size)
          .then(function (bmp) {
            return encode(bmp, size, file);
          })
          // 2) Respaldo: dibujar directamente el <img> ya cargado.
          .catch(function () {
            return encode(loaded.image, size, file);
          })
          .then(done, function (error) {
            done();
            return passthrough(file, type, error);
          });
      },
      function (imgError) {
        // <img> no pudo abrirla: último intento con createImageBitmap sin reducir.
        return bitmap(file, null)
          .then(function (bmp) {
            return encode(bmp, targetSize(bmp.width, bmp.height), file);
          })
          .catch(function (error) {
            return passthrough(file, type, error || imgError);
          });
      },
    );
  }

  // Una foto a la vez y una sola compresión por archivo (el borrador y el envío la reutilizan).
  function compress(file) {
    if (cache && cache.has(file)) return cache.get(file);
    var task = queue.then(function () {
      return run(file);
    });
    queue = task.catch(function () {});
    if (cache) {
      cache.set(file, task);
      task.catch(function () {
        cache.delete(file);
      });
    }
    return task;
  }

  // Peso con el que una foto cuenta para el límite total: el servidor reduce las no comprimidas.
  function uploadBytes(file) {
    return uncompressed && uncompressed.has(file) ? Math.min(file.size, SERVER_COMPRESSED_BYTES) : file.size;
  }

  window.ScmPhotoCompress = { compress: compress, uploadBytes: uploadBytes };
})();
