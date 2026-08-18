(function () {
  "use strict";

  function parseRuntime(root) {
    var raw = root.getAttribute("data-scm-runtime") || "";
    if (!raw) {
      return null;
    }

    try {
      return JSON.parse(raw);
    } catch (err) {
      console.error("SCM runtime parse error:", err);
      return null;
    }
  }

  function escHtml(value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function scmNotify(type, message, title) {
    var icon = type === "error" ? "error" : "success";
    if (window.Swal && typeof window.Swal.fire === "function") {
      window.Swal.fire({
        icon: icon,
        title: title || (type === "error" ? "No se pudo guardar" : "Guardado"),
        text: message || "",
        timer: type === "error" ? undefined : 2200,
        timerProgressBar: type !== "error",
        confirmButtonColor: "#1f4f99",
      });
      return;
    }
    if (type === "error") {
      alert(message || "No se pudo guardar.");
    }
  }

  function bindTabs(root, runtime) {
    var tabs = root.querySelectorAll(".scm-tab[data-tab]");
    if (!tabs.length) {
      return;
    }

    function activateOpenTopic(target) {
      if (!target) {
        return false;
      }
      var openWrap = root.querySelector("#scm-panel-abiertos .scm-open-bucket");
      if (!openWrap) {
        return false;
      }
      var targetPanel = openWrap.querySelector(
        '.scm-open-topic-panel[data-open-topic="' + target + '"]',
      );
      if (!targetPanel) {
        return false;
      }
      openWrap.querySelectorAll(".scm-open-topic-tab").forEach(function (tab) {
        tab.classList.toggle(
          "active",
          tab.getAttribute("data-open-target") === target,
        );
      });
      openWrap
        .querySelectorAll(".scm-open-topic-panel")
        .forEach(function (panel) {
          panel.classList.toggle(
            "active",
            panel.getAttribute("data-open-topic") === target,
          );
        });
      return true;
    }

    function activateTab(target) {
      if (!target) {
        return false;
      }

      var panelTarget = root.querySelector("#" + target);
      if (!panelTarget) {
        return false;
      }

      tabs.forEach(function (item) {
        item.classList.toggle("active", item.dataset.tab === target);
      });

      root.querySelectorAll(".scm-tab-panel").forEach(function (panel) {
        panel.classList.toggle("active", panel.id === target);
      });

      return true;
    }

    root.querySelectorAll(".scm-open-topic-tab").forEach(function (tab) {
      tab.addEventListener("click", function () {
        activateOpenTopic(tab.getAttribute("data-open-target") || "");
      });
    });

    tabs.forEach(function (tab) {
      tab.addEventListener("click", function () {
        activateTab(tab.dataset.tab || "");
      });
    });

    var initialTab = "";
    if (runtime && typeof runtime.initialTab === "string") {
      initialTab = runtime.initialTab.trim();
    }

    if (!initialTab) {
      try {
        var params = new URL(window.location.href).searchParams;
        initialTab = (
          params.get("scm_tab") ||
          params.get("tab") ||
          ""
        ).trim();
      } catch (_e) {}
    }

    if (initialTab && activateTab(initialTab)) {
      if (runtime && typeof runtime.initialOpenTopic === "string") {
        activateOpenTopic(runtime.initialOpenTopic.trim());
      }
      var iframeMode = !!(runtime && runtime.iframeMode);
      if (iframeMode) {
        var tabsBar = root.querySelector(".scm-tabs");
        if (tabsBar) {
          tabsBar.style.display = "none";
        }
        var guideBar = root.querySelector(".scm-guide-bar");
        if (guideBar) {
          guideBar.style.display = "none";
        }
      }
    }
  }

  window.scmToggleTL = function (btn) {
    if (!btn) {
      return;
    }
    var tr = btn.closest("tr");
    if (!tr) {
      return;
    }
    var next = tr.nextElementSibling;
    if (!next || !next.classList.contains("scm-tl-row")) {
      return;
    }

    var hidden = next.style.display === "none" || next.style.display === "";
    next.style.display = hidden ? "table-row" : "none";
    btn.innerHTML = hidden ? "&#9650; Timeline" : "&#9660; Timeline";
  };

  function findRootFromNode(node) {
    if (!node || !node.closest) {
      return null;
    }
    return node.closest("#scm-app.scm-wrap[data-scm-runtime]");
  }

  function getCaseModal(root) {
    if (!root) {
      return null;
    }
    return root.querySelector("#scm-case-modal");
  }

  function injectIframePrintStyles(frameDocument) {
    if (!frameDocument || !frameDocument.head || frameDocument.getElementById("scm-iframe-print-style")) {
      return;
    }
    var printStyle = frameDocument.createElement("style");
    printStyle.id = "scm-iframe-print-style";
    printStyle.textContent =
      "@media print{" +
      "@page{size:A4;margin:10mm}" +
      "html,body{background:#fff!important;color:#111827!important;overflow:visible!important}" +
      "body{margin:0!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}" +
      "#wpadminbar,header,footer,nav,.site-header,.site-footer,.elementor-location-header,.elementor-location-footer,.jet-mobile-menu-cover,.jet-mobile-menu__container,.no-print,.noprint,.print-hide,.hide-print,button,input[type='button'],input[type='submit']{display:none!important}" +
      "main,.site-main,#main,#content,.site-content,.entry-content,article{background:#fff!important;box-shadow:none!important}" +
      "table{max-width:100%!important;border-collapse:collapse!important;page-break-inside:auto!important}" +
      "tr,img{page-break-inside:avoid!important;break-inside:avoid!important}" +
      "img{max-width:100%!important;height:auto!important}" +
      "a[href]::after{content:''!important}" +
      "}";
    frameDocument.head.appendChild(printStyle);
  }

  function printIframeDocument(iframeEl, url) {
    try {
      var frameWindow = iframeEl ? iframeEl.contentWindow : null;
      var frameDocument = iframeEl ? iframeEl.contentDocument || (frameWindow ? frameWindow.document : null) : null;
      if (!frameWindow || !frameDocument) {
        throw new Error("Iframe no disponible");
      }
      injectIframePrintStyles(frameDocument);
      frameWindow.focus();
      setTimeout(function () {
        frameWindow.print();
      }, 80);
      return true;
    } catch (error) {
      window.open(url, "_blank", "noopener,noreferrer");
      return false;
    }
  }

  function openIframeModal(url, title, compact) {
    if (!url) {
      return;
    }
    var overlay = document.createElement("div");
    var compactMode = compact === true || compact === "1";
    var previouslyFocused = document.activeElement;
    overlay.className = "scm-iframe-overlay" + (compactMode ? " scm-iframe-overlay-compact" : "");
    overlay.innerHTML =
      '<div class="scm-iframe-box" role="dialog" aria-modal="true" aria-label="' + escHtml(title || "Detalle") + '">' +
      '<div class="scm-iframe-toolbar">' +
      '<span class="scm-iframe-toolbar-title">' +
      escHtml(title) +
      "</span>" +
      '<div class="scm-iframe-toolbar-actions">' +
      '<a class="scm-iframe-open-tab" href="#" target="_blank" rel="noopener noreferrer">Ver en grande</a>' +
      '<button type="button" class="scm-iframe-print">Imprimir / PDF</button>' +
      '<button type="button" class="scm-iframe-close" aria-label="Cerrar">&times;</button>' +
      "</div>" +
      "</div>" +
      '<div class="scm-iframe-loader"><div class="scm-iframe-spinner"></div></div>' +
      '<iframe class="scm-iframe-frame" src="" allowfullscreen></iframe>' +
      "</div>";
    document.body.appendChild(overlay);
    var iframeEl = overlay.querySelector(".scm-iframe-frame");
    var loaderEl = overlay.querySelector(".scm-iframe-loader");
    var openTabLink = overlay.querySelector(".scm-iframe-open-tab");
    var printButton = overlay.querySelector(".scm-iframe-print");
    if (openTabLink) {
      openTabLink.setAttribute("href", url);
    }
    iframeEl.addEventListener("load", function () {
      try {
        injectIframePrintStyles(iframeEl.contentDocument);
      } catch (error) {
        // Si el navegador bloquea el acceso por origen, queda disponible "Ver en grande".
      }
      if (compactMode) {
        try {
          var frameDocument = iframeEl.contentDocument;
          if (frameDocument && frameDocument.head) {
            var compactStyle = frameDocument.createElement("style");
            compactStyle.setAttribute("data-scm-compact-ticket", "1");
            compactStyle.textContent =
              "#wpadminbar,header,footer,.site-header,.site-footer,.elementor-location-header,.elementor-location-footer,.jet-mobile-menu-cover,.jet-mobile-menu__container{display:none!important}" +
              "html{margin-top:0!important}body{padding-top:0!important;background:#f6f8fb!important}" +
              "main,.site-main,#content,.site-content{margin-top:0!important;padding-top:12px!important}";
            frameDocument.head.appendChild(compactStyle);
          }
        } catch (error) {
          // Cross-origin tickets still work; they simply keep their original chrome.
        }
      }
      if (loaderEl) {
        loaderEl.style.display = "none";
      }
    });
    iframeEl.src = url;
    function destroyOverlay() {
      if (overlay.parentNode) {
        overlay.parentNode.removeChild(overlay);
      }
      if (previouslyFocused && typeof previouslyFocused.focus === "function") {
        previouslyFocused.focus();
      }
    }
    var closeButton = overlay.querySelector(".scm-iframe-close");
    if (printButton) {
      printButton.addEventListener("click", function () {
        printIframeDocument(iframeEl, url);
      });
    }
    closeButton.addEventListener("click", destroyOverlay);
    closeButton.focus();
  }

  function closeCaseModal(modal) {
    if (!modal) {
      return;
    }

    var sub = modal.querySelector(".scm-case-submodal");
    if (sub) {
      sub.classList.remove("open");
    }

    modal.classList.remove("open");
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("scm-modal-open");

    var body = modal.querySelector("#scm-case-body");
    if (body) {
      body.innerHTML = "";
    }
    var headActions = modal.querySelector("#scm-case-head-actions");
    if (headActions) {
      headActions.innerHTML = "";
    }
    modal._scmTimelineHtml = "";
  }

  function ensureCaseSubmodal(modal) {
    if (!modal) {
      return null;
    }
    var existing = modal.querySelector(".scm-case-submodal");
    if (existing) {
      return existing;
    }

    var wrap = document.createElement("div");
    wrap.className = "scm-case-submodal";
    wrap.setAttribute("aria-hidden", "true");
    wrap.innerHTML =
      '<div class="scm-case-submodal-dialog" role="dialog" aria-modal="true">' +
      '<button type="button" class="scm-case-submodal-close" aria-label="Cerrar detalle">&times;</button>' +
      '<div class="scm-case-submodal-head"><h4 class="scm-case-submodal-title">Detalle</h4><p class="scm-case-submodal-meta"></p></div>' +
      '<div class="scm-case-submodal-body"></div>' +
      "</div>";

    modal.querySelector(".scm-case-dialog").appendChild(wrap);

    function closeSub() {
      wrap.classList.remove("open");
      wrap.setAttribute("aria-hidden", "true");
    }

    var closeBtn = wrap.querySelector(".scm-case-submodal-close");
    if (closeBtn) {
      closeBtn.addEventListener("click", closeSub);
    }

    return wrap;
  }

  function getCasePropertyCode(caseBtn, fallbackNode) {
    var value = "";
    if (caseBtn && caseBtn.dataset) {
      value = String(caseBtn.dataset.idInmuebleWeb || "").trim();
    }
    if (!value && fallbackNode && fallbackNode.dataset) {
      value = String(fallbackNode.dataset.idInmuebleWeb || "").trim();
    }
    if (!value || value === "-") {
      return "";
    }
    return value;
  }

  function setCaseSubmodalMeta(sub, caseBtn) {
    if (!sub) {
      return;
    }
    var meta = sub.querySelector(".scm-case-submodal-meta");
    if (!meta) {
      return;
    }
    var propertyCode = getCasePropertyCode(caseBtn, sub.closest(".scm-case-modal"));
    if (!propertyCode) {
      meta.textContent = "";
      meta.style.display = "none";
      return;
    }
    meta.textContent = "Codigo inmueble web: " + propertyCode;
    meta.style.display = "block";
  }

  function openCaseTimelineSubmodal(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var timelineHtml = String((modal && modal._scmTimelineHtml) || "").trim();
    if (title) {
      title.textContent = "Línea de tiempo del caso";
    }
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML = timelineHtml
        ? '<div class="scm-modal-timeline-only scm-modal-timeline-popup">' +
          timelineHtml +
          "</div>"
        : '<p class="scm-muted">Este caso no tiene línea de tiempo disponible.</p>';
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function cleanCaseValue(value) {
    value = String(value || "").trim();
    return value === "-" ? "" : value;
  }

  function readCaseValue(caseBtn, fallbackNode, key) {
    if (caseBtn && caseBtn.dataset && caseBtn.dataset[key]) {
      return cleanCaseValue(caseBtn.dataset[key]);
    }
    if (fallbackNode && fallbackNode.dataset && fallbackNode.dataset[key]) {
      return cleanCaseValue(fallbackNode.dataset[key]);
    }
    return "";
  }

  function getCaseLocationPayload(caseBtn, fallbackNode) {
    return {
      propertyCode: readCaseValue(caseBtn, fallbackNode, "idInmuebleWeb"),
      propertyRowId: readCaseValue(caseBtn, fallbackNode, "idInmuebleData"),
      googleMapsUrl: readCaseValue(
        caseBtn,
        fallbackNode,
        "ubicacionGoogleMaps",
      ),
      direccion: readCaseValue(caseBtn, fallbackNode, "direccion"),
    };
  }

  function parseCoord(value) {
    var num = parseFloat(String(value || "").replace(",", "."));
    return isFinite(num) ? num : null;
  }

  function parseLatLngPair(text) {
    var match = String(text || "").match(
      /(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/,
    );
    if (!match) {
      return null;
    }
    var lat = parseCoord(match[1]);
    var lng = parseCoord(match[2]);
    if (lat === null || lng === null) {
      return null;
    }
    return { lat: lat, lng: lng };
  }

  function parseCoordsFromUrl(url) {
    url = String(url || "");
    if (!url) {
      return null;
    }
    var decoded = url;
    try {
      decoded = decodeURIComponent(url);
    } catch (e) {}

    var patterns = [
      /@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/,
      /[?&](?:q|ll|query|destination|marker)=(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/,
      /[?&]mlat=(-?\d+(?:\.\d+)?).*?[?&]mlon=(-?\d+(?:\.\d+)?)/,
      /#map=\d+\/(-?\d+(?:\.\d+)?)\/(-?\d+(?:\.\d+)?)/,
    ];
    for (var i = 0; i < patterns.length; i++) {
      var match = decoded.match(patterns[i]);
      if (match) {
        var lat = parseCoord(match[1]);
        var lng = parseCoord(match[2]);
        if (lat !== null && lng !== null) {
          return { lat: lat, lng: lng };
        }
      }
    }
    return parseLatLngPair(decoded);
  }

  function buildOpenStreetMapEmbedUrl(lat, lng) {
    var delta = 0.0035;
    var left = (lng - delta).toFixed(6);
    var right = (lng + delta).toFixed(6);
    var top = (lat + delta).toFixed(6);
    var bottom = (lat - delta).toFixed(6);
    return (
      "https://www.openstreetmap.org/export/embed.html?bbox=" +
      left +
      "%2C" +
      bottom +
      "%2C" +
      right +
      "%2C" +
      top +
      "&layer=mapnik&marker=" +
      lat.toFixed(6) +
      "%2C" +
      lng.toFixed(6)
    );
  }

  function buildCaseLocationInfo(caseBtn, fallbackNode) {
    var payload = getCaseLocationPayload(caseBtn, fallbackNode);
    var googleUrl = payload.googleMapsUrl;
    var googleIsUrl = /^https?:\/\//i.test(googleUrl);
    var osmUrl = "";
    var coords = null;
    if (!coords && googleUrl) {
      coords = parseCoordsFromUrl(googleUrl);
    }

    if (googleIsUrl && /openstreetmap\.org/i.test(googleUrl)) {
      osmUrl = googleUrl;
      googleUrl = "";
    }

    if (coords) {
      if (!googleUrl) {
        googleUrl =
          "https://www.google.com/maps?q=" + coords.lat + "," + coords.lng;
      }
      if (!osmUrl) {
        osmUrl =
          "https://www.openstreetmap.org/?mlat=" +
          coords.lat +
          "&mlon=" +
          coords.lng +
          "#map=18/" +
          coords.lat +
          "/" +
          coords.lng;
      }
    }

    var searchText = payload.direccion;
    if (!googleUrl && searchText) {
      googleUrl =
        "https://www.google.com/maps/search/?api=1&query=" +
        encodeURIComponent(searchText);
    }
    if (!osmUrl && searchText) {
      osmUrl =
        "https://www.openstreetmap.org/search?query=" +
        encodeURIComponent(searchText);
    }

    return {
      payload: payload,
      hasLocation: !!(googleUrl || osmUrl || coords),
      googleUrl: googleUrl,
      osmUrl: osmUrl,
      embedUrl: coords ? buildOpenStreetMapEmbedUrl(coords.lat, coords.lng) : "",
      coordsLabel: coords
        ? coords.lat.toFixed(6) + ", " + coords.lng.toFixed(6)
        : "",
    };
  }

  function renderCaseLocationPanel(caseBtn, fallbackNode, compact) {
    var info = buildCaseLocationInfo(caseBtn, fallbackNode);
    var payload = info.payload;
    var editLabel = info.hasLocation
      ? "Actualizar ubicacion"
      : "Agregar ubicacion";
    var html =
      '<section class="scm-case-location-panel' +
      (compact ? " is-compact" : "") +
      '">' +
      '<div class="scm-case-location-head"><div><h4>Ubicacion del inmueble</h4>' +
      (payload.propertyCode
        ? '<p>Codigo web: <strong>' + escHtml(payload.propertyCode) + "</strong></p>"
        : "<p>Sin codigo de inmueble.</p>") +
      "</div>" +
      '<button type="button" class="btn btn-outline btn-sm" data-scm-open-location-editor>' +
      editLabel +
      "</button></div>";

    if (payload.googleMapsUrl) {
      html +=
        '<p class="scm-case-location-source"><strong>Ubicacion guardada:</strong> ' +
        escHtml(payload.googleMapsUrl) +
        "</p>";
    }
    if (info.coordsLabel) {
      html +=
        '<p class="scm-case-location-source"><strong>Coordenadas:</strong> ' +
        escHtml(info.coordsLabel) +
        "</p>";
    }
    if (!payload.manualLocation && !info.coordsLabel && payload.direccion) {
      html +=
        '<p class="scm-case-location-source"><strong>Direccion base:</strong> ' +
        escHtml(payload.direccion) +
        "</p>";
    }

    html += '<div class="scm-case-location-links">';
    if (info.osmUrl) {
      html +=
        '<a class="scm-case-location-link" href="' +
        escHtml(info.osmUrl) +
        '" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>';
    }
    if (info.googleUrl) {
      html +=
        '<a class="scm-case-location-link" href="' +
        escHtml(info.googleUrl) +
        '" target="_blank" rel="noopener noreferrer">Google Maps</a>';
    }
    if (!info.osmUrl && !info.googleUrl) {
      html +=
        '<span class="scm-case-location-empty">No hay ubicacion registrada todavia.</span>';
    }
    html += "</div>";

    if (info.embedUrl) {
      html +=
        '<div class="scm-case-location-map"><iframe loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="' +
        escHtml(info.embedUrl) +
        '" title="Mapa del inmueble"></iframe></div>';
    }

    html += "</section>";
    return html;
  }

  function prependCaseLocationPanel(container, caseBtn, fallbackNode) {
    return;
  }

  function renderPropertyLocationEditorHtml(caseBtn, fallbackNode) {
    var locationInfo = buildCaseLocationInfo(caseBtn, fallbackNode);
    var payload = locationInfo.payload;
    return (
      renderCaseLocationPanel(caseBtn, fallbackNode, false) +
      '<form class="scm-property-location-form" method="post" autocomplete="off">' +
      '<input type="hidden" name="ticket_pk" value="' +
      escHtml(
        readCaseValue(caseBtn, fallbackNode, "ticketPk") ||
          readCaseValue(caseBtn, fallbackNode, "ticket"),
      ) +
      '">' +
      '<input type="hidden" name="property_row_id" value="' +
      escHtml(payload.propertyRowId || "") +
      '">' +
      '<input type="hidden" name="property_code" value="' +
      escHtml(payload.propertyCode || "") +
      '">' +
      '<label class="scm-seg-field"><span>Ubicacion del inmueble</span><textarea name="manual_location" rows="4" placeholder="Pega un link de Google Maps, OpenStreetMap, coordenadas lat,lng o una direccion">' +
      escHtml(payload.googleMapsUrl || "") +
      "</textarea></label>" +
      '<p class="scm-muted">Tambien tomamos como apoyo la direccion y las coordenadas actuales del inmueble cuando existen.</p>' +
      '<div class="scm-seg-actions"><button type="submit" class="scm-btn-primary">' +
      (locationInfo.hasLocation
        ? "Guardar ubicacion manual"
        : "Agregar ubicacion manual") +
      '</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
      "</form>"
    );
  }

  function openPropertyLocationEditor(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");

    if (title) {
      title.textContent = "Ubicacion del inmueble";
    }
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML = renderPropertyLocationEditorHtml(caseBtn, modal);
    }

    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function openPropertyLocationStandaloneEditor(root, caseBtn) {
    if (!root || !caseBtn) {
      return;
    }
    openStandaloneDetail(root, {
      title: "Ubicacion del inmueble",
      html: renderPropertyLocationEditorHtml(caseBtn, null),
      caseBtn: caseBtn,
    });
  }

  function openCaseSubmodal(modal, triggerBtn, targetId) {
    if (!modal || !targetId) {
      return;
    }
    var source = modal.querySelector("#" + targetId);
    if (!source) {
      return;
    }

    var sub = ensureCaseSubmodal(modal);
    if (!sub) {
      return;
    }

    var clone = source.cloneNode(true);
    clone.removeAttribute("id");
    clone.style.display = "";
    clone.querySelectorAll("[id]").forEach(function (el) {
      el.removeAttribute("id");
    });

    var title = "Detalle";
    var titleNode = source.querySelector("h4");
    if (titleNode && titleNode.textContent) {
      title = titleNode.textContent.trim();
    } else if (triggerBtn && triggerBtn.textContent) {
      title = triggerBtn.textContent.trim();
    }

    var subTitle = sub.querySelector(".scm-case-submodal-title");
    var subBody = sub.querySelector(".scm-case-submodal-body");
    if (subTitle) {
      subTitle.textContent = title || "Detalle";
    }
    var caseBtn = modal.querySelector(".scm-btn-case");
    setCaseSubmodalMeta(sub, caseBtn);
    if (subBody) {
      subBody.innerHTML = "";
      subBody.appendChild(clone);
      prependCaseLocationPanel(subBody, caseBtn, modal);
      initCotizacionResponseFields(subBody);
    }

    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function renderCaseDamageItems(items) {
    if (!Array.isArray(items) || !items.length) {
      return '<p class="scm-muted">Sin da&ntilde;os detallados para esta revision.</p>';
    }

    return (
      '<ul class="scm-damage-items">' +
      items
        .map(function (item) {
          var fields = item.fields || {};
          var photos = Array.isArray(item.photos) ? item.photos : [];
          var areas =
            Array.isArray(item.areas) && item.areas.length
              ? item.areas.join(", ")
              : [
                  fields.area_afectada_1,
                  fields.area_afectada_2,
                  fields.area_afectada_3,
                  fields.area_afectada_4,
                ]
                  .filter(Boolean)
                  .join(", ");
          var photoHtml = photos.length
            ? '<div class="scm-damage-photo-grid">' +
              photos
                .map(function (photo) {
                  return (
                    '<a href="' +
                    escHtml(photo.url) +
                    '" target="_blank" rel="noopener noreferrer">' +
                    '<img src="' +
                    escHtml(photo.url) +
                    '" alt="Registro fotografico ' +
                    escHtml(photo.id || "") +
                    '" loading="lazy">' +
                    "</a>"
                  );
                })
                .join("") +
              "</div>"
            : "";

          return (
            '<li class="scm-damage-item-rich">' +
            "<p><b>Indice:</b> " +
            escHtml(fields.indice || item.label || "Dano registrado") +
            "</p>" +
            (areas
              ? "<p><b>Area afectada:</b> " + escHtml(areas) + "</p>"
              : "") +
            (fields.a_quien_corresponde
              ? "<p><b>A quien corresponde este dano:</b> " +
                escHtml(fields.a_quien_corresponde) +
                "</p>"
              : "") +
            (photoHtml
              ? "<p><b>Registro fotografico:</b></p>" + photoHtml
              : "") +
            (fields.registro_foto_dano && !photos.length
              ? "<p><b>Registro fotografico:</b> " +
                escHtml(fields.registro_foto_dano) +
                "</p>"
              : "") +
            (fields.descripcion_dano
              ? "<p><b>Descripcion de dano:</b><br>" +
                escHtml(fields.descripcion_dano) +
                "</p>"
              : "") +
            (fields.consecuencia
              ? "<p><b>Consecuencia:</b><br>" +
                escHtml(fields.consecuencia) +
                "</p>"
              : "") +
            (fields.nivel_dano || fields.tiempo_atencion
              ? '<div class="scm-damage-item-duo">' +
                (fields.nivel_dano
                  ? "<p><b>Nivel del dano:</b> " +
                    escHtml(fields.nivel_dano) +
                    "</p>"
                  : "") +
                (fields.tiempo_atencion
                  ? "<p><b>Tiempo de atencion:</b> " +
                    escHtml(fields.tiempo_atencion) +
                    "</p>"
                  : "") +
                "</div>"
              : "") +
            "</li>"
          );
        })
        .join("") +
      "</ul>"
    );
  }

  function renderCaseDamage(ticket) {
    var m = (ticket && ticket.magnitud) || {};
    var matrix = Array.isArray(ticket && ticket.matriz) ? ticket.matriz : [];
    var matrixHtml = matrix
      .map(function (row) {
        return (
          '<div class="scm-ticket-matrix-row"><span>' +
          escHtml(row.factor) +
          "</span><strong>" +
          escHtml(row.nivel) +
          "</strong><small>" +
          escHtml(row.criterio) +
          "</small></div>"
        );
      })
      .join("");

    return (
      '<section class="scm-case-damage-detail">' +
      '<div class="scm-damage-modal-head"><div><span class="scm-ticket-id">#' +
      escHtml(ticket.id_ticket || ticket.ticket_row_id || "") +
      '</span><h3>Magnitud del da&ntilde;o</h3></div><span class="scm-badge scm-badge-' +
      escHtml(m.key || "medio") +
      '">' +
      escHtml(m.label || "") +
      "</span></div>" +
      '<div class="scm-score-explain">' +
      "<div><span>Score</span><strong>" +
      escHtml(m.score || 0) +
      "</strong></div>" +
      "<div><span>Hallazgos</span><strong>" +
      escHtml(m.items || 0) +
      "</strong></div>" +
      "<div><span>Indicadores criticos</span><strong>" +
      escHtml(m.critical_hits || 0) +
      "</strong></div>" +
      "<div><span>Indicadores altos</span><strong>" +
      escHtml(m.high_hits || 0) +
      "</strong></div>" +
      "</div>" +
      '<div class="scm-score-guide scm-score-guide-compact">' +
      "<div><h4>Formula</h4><p>(Criticos x 6) + (Altos x 4) + (Medios x 2) + (Bajos x 1). Si la prioridad es urgente suma +3.</p></div>" +
      "<div><h4>Ajustes</h4><ul><li>3 o mas hallazgos suman +1.</li><li>6 o mas hallazgos suman +3.</li><li>Los niveles escritos en la revision pesan mas que una palabra suelta.</li></ul></div>" +
      "<div><h4>Lectura</h4><ul><li>Critico: indicador critico o score 18+.</li><li>Alto: indicador alto o score 11+.</li><li>Medio: indicador medio o score 5+.</li></ul></div>" +
      "</div>" +
      "<h4>Matriz de interpretacion</h4>" +
      '<div class="scm-ticket-matrix">' +
      matrixHtml +
      "</div>" +
      "<h4>Danos detectados</h4>" +
      renderCaseDamageItems(ticket.danos_detectados || []) +
      "<h4>Recomendacion</h4><p>" +
      escHtml(m.recommendation || "") +
      "</p>" +
      "</section>"
    );
  }

  function normalizeMagnitudeKey(value) {
    var key = String(value || "")
      .trim()
      .toLowerCase();
    if (key === "crítico") {
      key = "critico";
    }
    return ["critico", "alto", "medio", "bajo"].indexOf(key) >= 0 ? key : "";
  }

  function magnitudeLabel(key) {
    var labels = {
      critico: "Critico",
      alto: "Alto",
      medio: "Medio",
      bajo: "Bajo",
    };
    return labels[key] || "Sin clasificar";
  }

  function renderMagnitudeBadge(value) {
    var key = normalizeMagnitudeKey(value);
    if (!key) {
      return '<span class="scm-magnitude-badge scm-magnitude-empty">Sin clasificar</span>';
    }
    return (
      '<span class="scm-magnitude-badge scm-magnitude-' +
      escHtml(key) +
      '">' +
      escHtml(magnitudeLabel(key)) +
      "</span>"
    );
  }

  function saveManualCaseMagnitude(root, ticketPk, magnitud, onDone) {
    var runtime = parseRuntime(root) || {};
    var fd = new FormData();
    fd.set(
      "action",
      (runtime.actions && runtime.actions.save_case_magnitude) ||
        "scm_guardar_magnitud_caso",
    );
    fd.set("nonce", runtime.nonce || "");
    fd.set("ticket_pk", ticketPk || "");
    fd.set("magnitud", magnitud || "");

    return fetch(runtime.ajaxUrl || "api.php", {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error(
            (json && json.data && json.data.message) ||
              "No se pudo guardar la magnitud.",
          );
        }
        if (typeof onDone === "function") {
          onDone(json.data || {});
        }
        return json.data || {};
      });
  }

  function openCaseDamageSubmodal(
    modal,
    triggerBtn,
    root,
    caseBtn,
    revisionType,
  ) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    if (title) {
      title.textContent =
        revisionType === "preventiva"
          ? "Magnitud daños preventiva"
          : "Magnitud daños correctiva";
    }
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<p class="scm-muted">Consultando magnitud del da&ntilde;o...</p>';
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");

    var runtime = parseRuntime(root) || {};
    var fd = new FormData();
    fd.set(
      "action",
      (runtime.actions && runtime.actions.damage_magnitude) ||
        "damage_magnitude_tickets",
    );
    fd.set("nonce", runtime.nonce || "");
    fd.set("ticket", caseBtn.dataset.ticket || caseBtn.dataset.ticketPk || "");
    fd.set("revision_type", revisionType);
    fd.set("limit", "50");
    fd.set("offset", "0");

    fetch(runtime.ajaxUrl || "api.php", {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (json) {
        if (!json || !(json.success || json.ok)) {
          throw new Error(
            (json && json.data && json.data.message) ||
              json.message ||
              "No se pudo consultar la magnitud.",
          );
        }
        var tickets = (json.data && json.data.tickets) || [];
        if (!tickets.length) {
          body.innerHTML =
            '<p class="scm-muted">Este ticket no tiene magnitud calculable para esa revision.</p>';
          prependCaseLocationPanel(body, caseBtn, modal);
          return;
        }
        body.innerHTML = renderCaseDamage(tickets[0]);
        prependCaseLocationPanel(body, caseBtn, modal);
      })
      .catch(function (err) {
        body.innerHTML =
          '<p class="scm-error">No se pudo cargar la magnitud: ' +
          escHtml(err.message || "error") +
          "</p>";
        prependCaseLocationPanel(body, caseBtn, modal);
      });
  }

  function openCaseMagnitudeEditor(modal, root, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;

    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var ticketPk = caseBtn.dataset.ticketPk || "";
    var current = normalizeMagnitudeKey(caseBtn.dataset.magnitudCaso || "");
    var options = ["critico", "alto", "medio", "bajo"];

    if (title) {
      title.textContent = "Editar magnitud del caso";
    }
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<div class="scm-case-magnitude-popup" data-ticket-pk="' +
        escHtml(ticketPk) +
        '">' +
        '<p class="scm-muted">Esta magnitud es manual y queda guardada en el ticket.</p>' +
        '<div class="scm-case-magnitude-options">' +
        options
          .map(function (key) {
            return (
              '<button type="button" class="scm-magnitude-choice ' +
              (current === key ? "is-active" : "") +
              '" data-magnitude="' +
              escHtml(key) +
              '">' +
              renderMagnitudeBadge(key) +
              "</button>"
            );
          })
          .join("") +
        "</div>" +
        '<small class="scm-case-magnitude-msg"></small>' +
        "</div>";
      prependCaseLocationPanel(body, caseBtn, modal);
    }

    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");

    sub.querySelectorAll(".scm-magnitude-choice").forEach(function (choice) {
      choice.addEventListener("click", function () {
        var value = choice.getAttribute("data-magnitude") || "";
        var msg = sub.querySelector(".scm-case-magnitude-msg");
        if (!ticketPk || !value) return;
        sub.querySelectorAll(".scm-magnitude-choice").forEach(function (btn) {
          btn.disabled = true;
        });
        if (msg) {
          msg.textContent = "Guardando...";
        }
        saveManualCaseMagnitude(root, ticketPk, value, function (data) {
          caseBtn.dataset.magnitudCaso = data.label || magnitudeLabel(value);
          sub.querySelectorAll(".scm-magnitude-choice").forEach(function (btn) {
            btn.classList.toggle(
              "is-active",
              btn.getAttribute("data-magnitude") === value,
            );
            btn.disabled = false;
          });
          var summaryBadge = modal.querySelector(
            "[data-scm-case-magnitude-badge]",
          );
          if (summaryBadge) {
            summaryBadge.innerHTML = renderMagnitudeBadge(value);
          }
          if (msg) {
            msg.textContent = "Guardado";
          }
        })
          .then(function (data) {
            scmNotify(
              "success",
              data && data.message
                ? data.message
                : "Magnitud del caso guardada.",
              "Magnitud actualizada",
            );
            if (root && typeof window.CustomEvent === "function") {
              root.dispatchEvent(new CustomEvent("scm:case-action-saved", {
                detail: { ticketPk: ticketPk, fromNode: sub },
              }));
            }
            setTimeout(function () {
              sub.classList.remove("open");
              sub.setAttribute("aria-hidden", "true");
            }, 350);
          })
          .catch(function (err) {
            sub
              .querySelectorAll(".scm-magnitude-choice")
              .forEach(function (btn) {
                btn.disabled = false;
              });
            if (msg) {
              msg.textContent =
                err && err.message ? err.message : "No se pudo guardar.";
            }
            scmNotify(
              "error",
              err && err.message
                ? err.message
                : "No se pudo guardar la magnitud.",
            );
          });
      });
    });
  }

  function openTrasladarCasoEditor(modal, caseBtn, runtime) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var ticketPk = caseBtn.dataset.ticketPk || "";
    var currentEmpId = String(
      caseBtn.dataset.empleadoId || caseBtn.dataset.empleado || "",
    ).trim();
    var funcionarios =
      runtime && Array.isArray(runtime.funcionarios)
        ? runtime.funcionarios
        : [];

    if (title) title.textContent = "Trasladar caso a otro funcionario";
    setCaseSubmodalMeta(sub, caseBtn);

    var empOptions = '<option value="">Seleccionar funcionario…</option>';
    funcionarios.forEach(function (func) {
      var id = String((func && func.id) || "").trim();
      var label = String((func && func.label) || id).trim();
      if (!id) return;
      var sel = id === currentEmpId ? " selected" : "";
      empOptions +=
        '<option value="' +
        escHtml(id) +
        '"' +
        sel +
        ">" +
        escHtml(label) +
        "</option>";
    });

    if (body) {
      body.innerHTML =
        '<form class="scm-trasladar-form" method="post" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<label class="scm-seg-field"><span>Nuevo funcionario</span><select name="new_empleado_id" required>' +
        empOptions +
        "</select></label>" +
        '<fieldset class="scm-notify-targets scm-notify-traslado"><legend>Notificar por correo (empleados)</legend>' +
        '<label class="scm-seg-check"><input type="checkbox" name="notify_anterior" value="1" checked> Notificar al funcionario anterior</label>' +
        '<label class="scm-seg-check"><input type="checkbox" name="notify_nuevo" value="1" checked> Notificar al funcionario nuevo</label>' +
        "</fieldset>" +
        renderNotifyTargets(["empleado"]) +
        '<div class="scm-seg-actions">' +
        '<button type="submit" class="scm-btn-primary">Trasladar caso</button>' +
        '<span class="scm-seg-msg" aria-live="polite"></span>' +
        "</div>" +
        "</form>";
      prependCaseLocationPanel(body, caseBtn, modal);
    }

    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function openCaseNoteEditor(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;

    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var ticketPk = caseBtn.dataset.ticketPk || "";
    var isPublicPqr = (caseBtn.dataset.caseKind || "") === "public-pqr";

    if (title) {
      title.textContent = isPublicPqr
        ? "Agregar nota a la solicitud"
        : "Agregar nota al ticket";
    }
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<form class="scm-note-form" method="post" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<label class="scm-seg-field"><span>Nota</span><textarea name="observacion" rows="6" required placeholder="' +
        (isPublicPqr
          ? "Escribe una nota interna para la solicitud..."
          : "Escribe una nota interna para el ticket...") +
        '"></textarea></label>' +
        '<div class="scm-seg-actions">' +
        '<button type="submit" class="scm-btn-primary">Guardar nota</button>' +
        '<span class="scm-seg-msg" aria-live="polite"></span>' +
        "</div>" +
        "</form>";
      prependCaseLocationPanel(body, caseBtn, modal);
    }

    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function openPostponeTicketEditor(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;

    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var ticketPk = caseBtn.dataset.ticketPk || "";
    var isPublicPqr = (caseBtn.dataset.caseKind || "") === "public-pqr";

    if (title) {
      title.textContent = isPublicPqr ? "Postergar solicitud" : "Postergar ticket";
    }
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<form class="scm-postpone-ticket-form" method="post" enctype="multipart/form-data" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<p class="scm-muted">Esta acci&oacute;n mantendr&aacute; ' +
        (isPublicPqr ? "la solicitud" : "el ticket") +
        ' abierta y marcar&aacute; el estado administrativo como Postergado.</p>' +
        '<label class="scm-seg-field"><span>Motivo de postergaci&oacute;n</span><textarea name="observacion" rows="6" required placeholder="' +
        (isPublicPqr
          ? "Describe por qu&eacute; se posterga la solicitud..."
          : "Describe por qu&eacute; se posterga el ticket...") +
        '"></textarea></label>' +
        '<label class="scm-seg-field"><span>Imagenes / Evidencias (opcional)</span><input type="file" name="evidencia[]" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif,image/tiff" multiple></label>' +
        renderPasteEvidenceBox("evidencia[]") +
        renderTicketDocumentFields() +
        renderNotifyTargets(isPublicPqr ? ["arrendatario", "propietario"] : []) +
        '<div class="scm-seg-actions"><button type="submit" class="scm-btn-primary">Guardar postergaci&oacute;n</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";
      prependCaseLocationPanel(body, caseBtn, modal);
    }

    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function renderTicketDocumentRow() {
    return (
      '<div class="scm-ticket-document-row">' +
      '<label class="scm-seg-field"><span>Titulo del documento</span><input type="text" name="documento_nombre[]" placeholder="Ej: Cotizacion, soporte, factura..."></label>' +
      '<label class="scm-seg-field"><span>Documento</span><input type="file" name="documento[]" accept="image/jpeg,image/png,application/pdf,application/msword,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/x-rar-compressed,text/html,text/plain,text/csv"></label>' +
      '<button type="button" class="btn btn-outline btn-sm scm-remove-ticket-document" data-remove-ticket-document>Quitar</button>' +
      "</div>"
    );
  }

  function renderPasteEvidenceBox(inputName) {
    return (
      '<div class="scm-paste-evidence" tabindex="0" role="button" data-scm-paste-evidence data-file-input-name="' +
      escHtml(inputName || "evidencia[]") +
      '">' +
      "<strong>Pegar captura</strong>" +
      "<span>Haz clic aqui y presiona Ctrl+V para adjuntar una imagen copiada.</span>" +
      '<ul data-scm-paste-list></ul>' +
      "</div>"
    );
  }

  function renderTicketDocumentFields() {
    return (
      '<div class="scm-ticket-documents-zone" data-ticket-documents-zone>' +
      '<div class="scm-ticket-documents-label">Documentos opcionales</div>' +
      '<div class="scm-ticket-documents" data-ticket-documents></div>' +
      '<button type="button" class="btn btn-outline scm-add-ticket-document" data-add-ticket-document>+ Agregar documento</button>' +
      "</div>"
    );
  }

  function caseHasCotizacion(caseBtn) {
    return !!(
      caseBtn &&
      ((caseBtn.dataset.cotizacionUrl || "").trim() ||
        (caseBtn.dataset.cotizacionId || "").trim())
    );
  }

  function caseCotizacionCanRespond(caseBtn) {
    if (!caseHasCotizacion(caseBtn)) {
      return false;
    }
    var cotEstado = (caseBtn.dataset.cotEstado || "").trim().toLowerCase();
    return cotEstado === "" || cotEstado === "esperando respuesta";
  }

  function renderCotizacionInlineFields(hasCotizacion) {
    if (!hasCotizacion) {
      return '<input type="hidden" name="estado_cotizacion" value="__keep__">';
    }
    return (
      '<section class="scm-cotizacion-response-inline" data-scm-cotizacion-response-fields>' +
      '<div class="scm-cotizacion-response-head"><strong>Respuesta de cotizaci&oacute;n</strong><span>Opcional: si respondes aqu&iacute;, tambi&eacute;n se actualiza la cotizaci&oacute;n asociada.</span></div>' +
      '<div class="scm-seg-grid">' +
      '<label class="scm-seg-field"><span>Estado cotizaci&oacute;n</span><select name="estado_cotizacion"><option value="__keep__">Sin cambio</option><option value="Aprobada">Aprobada</option><option value="Desaprobada">Desaprobada</option></select></label>' +
      '<label class="scm-seg-field scm-cotizacion-motivo" style="display:none;"><span>Motivo</span><select name="motivo_cotizacion"><option value="">Elige un motivo</option><option value="Por costo">Por costo</option><option value="Ejecucción por cuenta propia">Ejecucción por cuenta propia</option></select></label>' +
      '<label class="scm-seg-field scm-cotizacion-financiacion" style="display:none;"><span>Financiaci&oacute;n</span><select name="financiacion_cotizacion"><option value="">No aplica / sin respuesta</option><option value="Si">Si</option><option value="No">No</option></select></label>' +
      "</div>" +
      '<label class="scm-seg-field"><span>Observaci&oacute;n cotizaci&oacute;n</span><textarea name="observacion_cotizacion" rows="4" placeholder="Escribe la respuesta u observaci&oacute;n de la cotizaci&oacute;n..."></textarea></label>' +
      "</section>"
    );
  }

  function syncCotizacionResponseBox(box) {
    if (!box) return;
    var estado = box.querySelector(
      'select[name="estado_cotizacion"], select[name="estado"]',
    );
    var motivoWrap = box.querySelector(".scm-cotizacion-motivo");
    var motivoInput = box.querySelector(
      'select[name="motivo_cotizacion"], select[name="motivo"]',
    );
    var financiacionWrap = box.querySelector(".scm-cotizacion-financiacion");
    var financiacionInput = box.querySelector(
      'select[name="financiacion_cotizacion"], select[name="financiacion"]',
    );
    if (
      !estado ||
      !motivoWrap ||
      !motivoInput ||
      !financiacionWrap ||
      !financiacionInput
    ) {
      return;
    }
    var showMotivo = estado.value === "Desaprobada";
    var showFinanciacion = estado.value === "Aprobada";
    motivoWrap.style.display = showMotivo ? "" : "none";
    motivoInput.required = showMotivo;
    if (!showMotivo) motivoInput.value = "";
    financiacionWrap.style.display = showFinanciacion ? "" : "none";
    if (!showFinanciacion) financiacionInput.value = "";
  }

  function initCotizacionResponseFields(scope) {
    if (!scope || !scope.querySelectorAll) return;
    scope
      .querySelectorAll("[data-scm-cotizacion-response-fields]")
      .forEach(function (box) {
        var estado = box.querySelector(
          'select[name="estado_cotizacion"], select[name="estado"]',
        );
        if (estado && !estado.dataset.scmCotizacionBind) {
          estado.dataset.scmCotizacionBind = "1";
          estado.addEventListener("change", function () {
            syncCotizacionResponseBox(box);
          });
        }
        syncCotizacionResponseBox(box);
      });
  }

  function openTicketResponseEditor(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var ticketPk = caseBtn.dataset.ticketPk || "";
    var isPublicPqr = (caseBtn.dataset.caseKind || "") === "public-pqr";
    if (title) title.textContent = isPublicPqr ? "Responder solicitud" : "Responder ticket";
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<form class="scm-ticket-response-form" method="post" enctype="multipart/form-data" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<label class="scm-seg-field"><span>Estado administrativo</span><select name="estado_administrativo">' +
        '<option value="__keep__">Sin cambio</option><option value="Nuevo">Nuevo</option><option value="Por inspeccionar">Por inspeccionar</option><option value="Inspeccionado">Inspeccionado</option><option value="Cotizado">Cotizado</option><option value="En ejecucion por inmobiliaria">En ejecucion por inmobiliaria</option><option value="En ejecucion por propietario">En ejecucion por propietario</option><option value="En ejecucion por arrendatario">En ejecucion por arrendatario</option><option value="En ejecucion por copropiedad">En ejecucion por copropiedad</option><option value="Finalizado">Finalizado</option><option value="Trasladado">Trasladado</option><option value="Entregado">Entregado</option><option value="Recibido">Recibido</option><option value="Desistido">Desistido</option>' +
        "</select></label>" +
        '<label class="scm-seg-field"><span>Respuesta</span><textarea name="respuesta" rows="7" required placeholder="Escribe la respuesta que se enviara al solicitante..."></textarea></label>' +
        renderCotizacionInlineFields(!isPublicPqr && caseCotizacionCanRespond(caseBtn)) +
        '<label class="scm-seg-field"><span>Imagenes (opcional)</span><input type="file" name="imagen[]" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif,image/tiff" multiple></label>' +
        renderPasteEvidenceBox("imagen[]") +
        renderTicketDocumentFields() +
        renderNotifyTargets(isPublicPqr ? ["arrendatario", "propietario"] : []) +
        '<div class="scm-seg-actions"><label class="scm-seg-check"><input type="checkbox" name="cerrar_ticket" value="1"> Cerrar al responder</label><button type="submit" class="scm-btn-primary">Publicar y enviar correo</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";
      prependCaseLocationPanel(body, caseBtn, modal);
      initCotizacionResponseFields(body);
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function openCotizacionResponseEditor(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var ticketPk = caseBtn.dataset.ticketPk || "";
    if (title) title.textContent = "Responder cotizacion";
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<form class="scm-cotizacion-response-form" method="post" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<section class="scm-cotizacion-response-inline" data-scm-cotizacion-response-fields>' +
        '<label class="scm-seg-field"><span>Respuesta</span><select name="estado" required><option value="">Elige una respuesta</option><option value="Aprobada">Aprobada</option><option value="Desaprobada">Desaprobada</option></select></label>' +
        '<label class="scm-seg-field scm-cotizacion-motivo" style="display:none;"><span>Motivo</span><select name="motivo"><option value="">Elige un motivo</option><option value="Por costo">Por costo</option><option value="Ejecucción por cuenta propia">Ejecucción por cuenta propia</option></select></label>' +
        '<label class="scm-seg-field scm-cotizacion-financiacion" style="display:none;"><span>Financiacion</span><select name="financiacion"><option value="">No aplica / sin respuesta</option><option value="Si">Si</option><option value="No">No</option></select></label>' +
        '<label class="scm-seg-field"><span>Observaciones</span><textarea name="observacion" rows="6" placeholder="Ninguna">Ninguna</textarea></label>' +
        "</section>" +
        renderNotifyTargets() +
        '<div class="scm-seg-actions"><button type="submit" class="scm-btn-primary">Guardar respuesta</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";
      prependCaseLocationPanel(body, caseBtn, modal);
      initCotizacionResponseFields(body);
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  var scmCalendarCategoriesCache = null;

  function calendarApiBase(root) {
    var runtime = root ? parseRuntime(root) || {} : {};
    var config = runtime.config || {};
    return String(
      config.calendar_api_url ||
        "https://sucasainmobiliaria.com.co/calendario-actividades/index.php?action=",
    );
  }

  function calendarApiRequest(root, action, payload) {
    var options = {
      method: payload ? "POST" : "GET",
      credentials: "same-origin",
    };
    if (payload) {
      options.headers = { "Content-Type": "application/json" };
      options.body = JSON.stringify(payload);
    }
    return fetch(calendarApiBase(root) + encodeURIComponent(action), options).then(function (response) {
      return response.json();
    });
  }

  function loadCalendarCategories(root) {
    if (scmCalendarCategoriesCache) {
      return Promise.resolve(scmCalendarCategoriesCache);
    }
    return calendarApiRequest(root, "listar_categorias").then(function (json) {
      if (!json || !json.success || !Array.isArray(json.data)) {
        throw new Error((json && json.message) || "No se pudieron cargar categorias.");
      }
      scmCalendarCategoriesCache = json.data;
      return scmCalendarCategoriesCache;
    });
  }

  function normalizeCalendarText(value) {
    return String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
  }

  function calendarCategoryLabel(row) {
    return String((row && (row.nombre || row.categoria || row.name || row.id || row._ID || row.id_categoria)) || "").trim();
  }

  function isAdministrativeCalendarCategory(row) {
    var name = normalizeCalendarText(calendarCategoryLabel(row));
    if (!name) return false;
    var commercialTerms = [
      "comercial",
      "captacion",
      "captado",
      "recaptado",
      "prospect",
      "mostrando",
      "contactado",
      "entregado",
      "por publicar",
      "publicar",
      "en cierre",
      "en busqueda",
      "venta",
      "banco",
      "aseguradora",
    ];
    if (commercialTerms.some(function (term) { return name.indexOf(term) !== -1; })) return false;
    var adminTerms = [
      "administr",
      "preventiva",
      "correctiva",
      "revision",
      "mantenimiento",
      "servicio",
      "cita",
      "inspeccion",
      "contrato",
      "inmueble",
      "pendiente",
      "visita",
      "recibo",
    ];
    return adminTerms.some(function (term) { return name.indexOf(term) !== -1; });
  }

  function administrativeCalendarCategories(rows) {
    rows = Array.isArray(rows) ? rows : [];
    var filtered = rows.filter(isAdministrativeCalendarCategory);
    return filtered.length ? filtered : rows;
  }

  function selectedCalendarCategoryName(select) {
    if (!select || select.selectedIndex < 0) return "";
    var option = select.options[select.selectedIndex];
    return option ? String(option.textContent || "").trim() : "";
  }

  function cleanCalendarContractLabel(value) {
    return String(value || "").replace(/^#+\s*/, "").trim();
  }

  function buildCaseCalendarTitle(categoryName, contractLabel, ticketPk) {
    categoryName = String(categoryName || "Actividad").trim();
    var contract = cleanCalendarContractLabel(contractLabel);
    if (contract && contract !== "-") return "Contrato #" + contract + " - " + categoryName;
    return "Ticket #" + String(ticketPk || "").trim() + " - " + categoryName;
  }

  function formatCalendarDateForMessage(value) {
    var parts = String(value || "").split("-");
    if (parts.length !== 3) return value || "";
    return parts[2] + "/" + parts[1] + "/" + parts[0];
  }

  function formatCalendarTimeForMessage(value) {
    var pieces = String(value || "").split(":");
    var hour = parseInt(pieces[0] || "0", 10);
    var minute = pieces[1] || "00";
    if (Number.isNaN(hour)) return value || "";
    var suffix = hour >= 12 ? "p. m." : "a. m.";
    var displayHour = hour % 12 || 12;
    return String(displayHour).padStart(2, "0") + ":" + minute + " " + suffix;
  }

  function buildCaseCalendarDescription(categoryName, dateValue, startValue, endValue, asunto) {
    if (!categoryName || !dateValue || !startValue || !endValue) return String(asunto || "").trim();
    return "Por medio de la presente, le confirmo que he dispuesto de un espacio con el propósito de reunirnos, ya sea de forma presencial o por medios virtuales, a fin de atender cualquier inquietud o asunto pendiente.\n\nEn cumplimiento de " +
      categoryName +
      ", se ha programado una visita y/o reunión, la cual ha quedado agendada para el día " +
      formatCalendarDateForMessage(dateValue) +
      ", de " +
      formatCalendarTimeForMessage(startValue) +
      " a " +
      formatCalendarTimeForMessage(endValue) +
      ". En caso de no ser posible contar con su atención en la fecha indicada, le agradecemos nos lo comunique por este mismo medio con al menos 3 horas de antelación." +
      (asunto && asunto !== "-" ? "\n\nCaso: " + asunto : "");
  }

  function validateCalendarCaseEventTimes(dateValue, startValue, endValue) {
    if (!dateValue || !startValue || !endValue) return "Debes ingresar fecha, hora de inicio y hora de fin.";
    var start = new Date(dateValue + "T" + startValue);
    var end = new Date(dateValue + "T" + endValue);
    var now = new Date();
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return "Debes ingresar fechas y horas válidas.";
    if (end < start) return "La hora de finalización no puede ser menor que la hora de inicio.";
    var startDay = new Date(start.getFullYear(), start.getMonth(), start.getDate());
    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    if (startDay < today) return "No puedes seleccionar una fecha pasada.";
    if (end < now) return "No puedes seleccionar una hora de finalización pasada.";
    var startHour = start.getHours() + start.getMinutes() / 60;
    var endHour = end.getHours() + end.getMinutes() / 60;
    if (startHour < 8 || startHour > 21) return "La hora de inicio debe estar entre las 8:00 a. m. y las 9:00 p. m.";
    if (endHour < 8 || endHour > 21) return "La hora de finalización debe estar entre las 8:00 a. m. y las 9:00 p. m.";
    return "";
  }

  function calendarAllowedEmployeeIds(root) {
    var runtime = root ? parseRuntime(root) || {} : {};
    var config = runtime.config || {};
    var ids = Array.isArray(config.calendar_allowed_employee_ids) ? config.calendar_allowed_employee_ids : [];
    return ids.map(function (id) { return String(id || "").trim(); }).filter(Boolean);
  }

  function isCalendarEmployeeAllowed(root, employeeId) {
    var ids = calendarAllowedEmployeeIds(root);
    employeeId = String(employeeId || "").trim();
    return !ids.length || (employeeId !== "" && ids.indexOf(employeeId) !== -1);
  }

  function calendarDateKey(date) {
    return date.getFullYear() + "-" + String(date.getMonth() + 1).padStart(2, "0") + "-" + String(date.getDate()).padStart(2, "0");
  }

  function calendarCurrentMonthRange() {
    var now = new Date();
    var first = new Date(now.getFullYear(), now.getMonth(), 1);
    var last = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    return { from: calendarDateKey(first), to: calendarDateKey(last) };
  }

  function formatCalendarDateTime(value) {
    var raw = String(value || "").replace("T", " ");
    if (!raw) return "-";
    return raw.replace(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}:\d{2}).*$/, "$3/$2/$1 $4");
  }

  function extractCalendarRows(payload) {
    if (Array.isArray(payload)) return payload;
    if (!payload) return [];
    if (Array.isArray(payload.data)) return payload.data;
    if (payload.data && Array.isArray(payload.data.data)) return payload.data.data;
    if (payload.data && Array.isArray(payload.data.eventos)) return payload.data.eventos;
    if (Array.isArray(payload.eventos)) return payload.eventos;
    if (Array.isArray(payload.rows)) return payload.rows;
    return [];
  }

  var scmCaseHolidayCache = {};

  function addCalendarDays(date, amount) {
    var next = new Date(date.getTime());
    next.setDate(next.getDate() + amount);
    return next;
  }

  function nextCalendarMonday(date) {
    var next = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    var diff = (8 - next.getDay()) % 7;
    return addCalendarDays(next, diff);
  }

  function calendarEasterDate(year) {
    var a = year % 19;
    var b = Math.floor(year / 100);
    var c = year % 100;
    var d = Math.floor(b / 4);
    var e = b % 4;
    var f = Math.floor((b + 8) / 25);
    var g = Math.floor((b - f + 1) / 3);
    var h = (19 * a + b - d - g + 15) % 30;
    var i = Math.floor(c / 4);
    var k = c % 4;
    var l = (32 + 2 * e + 2 * i - h - k) % 7;
    var m = Math.floor((a + 11 * h + 22 * l) / 451);
    var month = Math.floor((h + l - 7 * m + 114) / 31);
    var day = ((h + l - 7 * m + 114) % 31) + 1;
    return new Date(year, month - 1, day);
  }

  function addCaseHoliday(map, date, label) {
    map[calendarDateKey(date)] = label;
  }

  function caseCalendarHolidays(year) {
    if (scmCaseHolidayCache[year]) return scmCaseHolidayCache[year];
    var map = {};
    addCaseHoliday(map, new Date(year, 0, 1), "Año Nuevo");
    addCaseHoliday(map, nextCalendarMonday(new Date(year, 0, 6)), "Reyes Magos");
    addCaseHoliday(map, nextCalendarMonday(new Date(year, 2, 19)), "San José");
    addCaseHoliday(map, new Date(year, 4, 1), "Día del Trabajo");
    addCaseHoliday(map, new Date(year, 6, 20), "Independencia");
    addCaseHoliday(map, new Date(year, 7, 7), "Batalla de Boyacá");
    addCaseHoliday(map, nextCalendarMonday(new Date(year, 7, 15)), "Asunción");
    addCaseHoliday(map, nextCalendarMonday(new Date(year, 9, 12)), "Día de la Raza");
    addCaseHoliday(map, nextCalendarMonday(new Date(year, 10, 1)), "Todos los Santos");
    addCaseHoliday(map, nextCalendarMonday(new Date(year, 10, 11)), "Independencia de Cartagena");
    addCaseHoliday(map, new Date(year, 11, 8), "Inmaculada Concepción");
    addCaseHoliday(map, new Date(year, 11, 25), "Navidad");

    var easter = calendarEasterDate(year);
    addCaseHoliday(map, addCalendarDays(easter, -3), "Jueves Santo");
    addCaseHoliday(map, addCalendarDays(easter, -2), "Viernes Santo");
    addCaseHoliday(map, nextCalendarMonday(addCalendarDays(easter, 43)), "Ascensión");
    addCaseHoliday(map, nextCalendarMonday(addCalendarDays(easter, 64)), "Corpus Christi");
    addCaseHoliday(map, nextCalendarMonday(addCalendarDays(easter, 71)), "Sagrado Corazón");

    scmCaseHolidayCache[year] = map;
    return map;
  }

  function caseCalendarHolidayLabel(date) {
    var year = date.getFullYear();
    return caseCalendarHolidays(year)[calendarDateKey(date)] || "";
  }

  function caseCalendarMonthDate(value) {
    var raw = String(value || "").trim();
    if (raw) {
      var parsed = new Date(raw + "T00:00:00");
      if (!Number.isNaN(parsed.getTime())) {
        return new Date(parsed.getFullYear(), parsed.getMonth(), 1);
      }
    }
    var now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), 1);
  }

  function caseCalendarMonthTitle(date) {
    try {
      return date.toLocaleDateString("es-CO", { month: "long", year: "numeric" });
    } catch (error) {
      return String(date.getMonth() + 1).padStart(2, "0") + "/" + date.getFullYear();
    }
  }

  function caseCalendarMonthRange(date) {
    var first = new Date(date.getFullYear(), date.getMonth(), 1);
    var last = new Date(date.getFullYear(), date.getMonth() + 1, 0);
    return { from: calendarDateKey(first), to: calendarDateKey(last) };
  }

  function caseCalendarEventDateKey(row) {
    return String((row && (row.fecha_inicio || row.fecha || row.start)) || "").slice(0, 10);
  }

  function caseCalendarEventDetailValue(row, keys) {
    for (var i = 0; i < keys.length; i += 1) {
      var value = row && row[keys[i]];
      if (value !== undefined && value !== null && String(value).trim() !== "") {
        return String(value).trim();
      }
    }
    return "";
  }

  function caseCalendarEventId(row) {
    return String((row && (row.id || row._ID || row.event_id || row.id_evento)) || "").trim();
  }

  function isCaseCalendarEventDone(row) {
    return normalizeCalendarText((row && row.estado) || "") === "si";
  }

  function closeCaseCalendarEventMini(shell) {
    var current = shell ? shell.querySelector("[data-scm-case-calendar-event-mini]") : null;
    if (current) current.remove();
  }

  function closeCaseCalendarPendingMini(shell) {
    var current = shell ? shell.querySelector("[data-scm-case-calendar-pending-mini]") : null;
    if (current) current.remove();
  }

  function openCaseCalendarEventMini(root, shell, row) {
    if (!shell || !row) return;
    closeCaseCalendarEventMini(shell);
    var eventId = caseCalendarEventId(row);
    var isDone = isCaseCalendarEventDone(row);
    var title = caseCalendarEventDetailValue(row, ["titulo", "title"]) || "Evento";
    var category = caseCalendarEventDetailValue(row, ["categoria", "nombre_categoria"]);
    var employee = caseCalendarEventDetailValue(row, ["funcionario", "nombre_empleado", "empleado", "nombre"]);
    var ticket = caseCalendarEventDetailValue(row, ["id_ticket", "ticket"]);
    var location = caseCalendarEventDetailValue(row, ["ubicacion", "lugar", "direccion"]);
    var description = caseCalendarEventDetailValue(row, ["descripcion", "observacion", "detalle"]);
    var html =
      '<div class="scm-case-calendar-event-mini" data-scm-case-calendar-event-mini>' +
      '<div class="scm-case-calendar-event-mini-card" role="dialog" aria-modal="true" aria-label="Detalle del evento">' +
      '<button type="button" class="scm-case-calendar-event-mini-close" data-scm-case-calendar-event-mini-close aria-label="Cerrar detalle">&times;</button>' +
      '<div class="scm-case-calendar-event-mini-head"><span>Detalle del evento</span><strong>' + escHtml(title) + '</strong></div>' +
      '<div class="scm-case-calendar-event-mini-grid">' +
      '<div><small>Inicio</small><strong>' + escHtml(formatCalendarDateTime(row.fecha_inicio || row.fecha || row.start)) + '</strong></div>' +
      '<div><small>Fin</small><strong>' + escHtml(formatCalendarDateTime(row.fecha_fin || row.end)) + '</strong></div>' +
      (category ? '<div><small>Categor&iacute;a</small><strong>' + escHtml(category) + '</strong></div>' : "") +
      (employee ? '<div><small>Funcionario</small><strong>' + escHtml(employee) + '</strong></div>' : "") +
      (ticket ? '<div><small>Ticket</small><strong>#' + escHtml(ticket) + '</strong></div>' : "") +
      (location ? '<div class="is-wide"><small>Ubicaci&oacute;n</small><strong>' + escHtml(location) + '</strong></div>' : "") +
      "</div>" +
      (description ? '<div class="scm-case-calendar-event-mini-description"><small>Descripci&oacute;n</small><p>' + escHtml(description).replace(/\n/g, "<br>") + "</p></div>" : "") +
      '<div class="scm-case-calendar-event-mini-actions" data-scm-case-calendar-mini-actions>' +
      '<span class="scm-case-calendar-event-state-badge' + (isDone ? " is-done" : "") + '" data-scm-case-calendar-event-state>' + (isDone ? "Realizado" : "Pendiente") + "</span>" +
      (eventId && !isDone ? '<button type="button" class="scm-case-calendar-complete-btn" data-scm-case-calendar-complete-open>Marcar realizado</button>' : "") +
      "</div>" +
      (eventId && !isDone
        ? '<form class="scm-case-calendar-complete-panel" data-scm-case-calendar-complete-panel hidden autocomplete="off">' +
          '<label><span>Observaci&oacute;n de realizaci&oacute;n</span><textarea name="observacion" rows="3" required>Realizado</textarea><small>Este texto se guardar&aacute; por defecto. Si tienes informaci&oacute;n adicional, puedes ampliarlo antes de guardar.</small></label>' +
          '<div><button type="submit" class="scm-case-calendar-complete-save">Guardar realizado</button><button type="button" class="scm-case-calendar-complete-cancel" data-scm-case-calendar-complete-cancel>Cancelar</button></div>' +
          '<small data-scm-case-calendar-complete-msg aria-live="polite"></small>' +
          "</form>"
        : "") +
      "</div>" +
      "</div>";
    shell.insertAdjacentHTML("beforeend", html);
    var mini = shell.querySelector("[data-scm-case-calendar-event-mini]");
    if (!mini) return;
    mini.addEventListener("click", function (event) {
      if (event.target === mini || (event.target && event.target.closest && event.target.closest("[data-scm-case-calendar-event-mini-close]"))) {
        closeCaseCalendarEventMini(shell);
        return;
      }
      var openBtn = event.target && event.target.closest ? event.target.closest("[data-scm-case-calendar-complete-open]") : null;
      if (openBtn) {
        var panel = mini.querySelector("[data-scm-case-calendar-complete-panel]");
        if (panel) {
          panel.hidden = false;
          var textarea = panel.querySelector("textarea");
          if (textarea) textarea.focus();
        }
        return;
      }
      if (event.target && event.target.closest && event.target.closest("[data-scm-case-calendar-complete-cancel]")) {
        var cancelPanel = mini.querySelector("[data-scm-case-calendar-complete-panel]");
        if (cancelPanel) cancelPanel.hidden = true;
      }
    });
    var form = mini.querySelector("[data-scm-case-calendar-complete-panel]");
    if (form && eventId) {
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        var msg = form.querySelector("[data-scm-case-calendar-complete-msg]");
        var saveBtn = form.querySelector("button[type='submit']");
        var observation = String(new FormData(form).get("observacion") || "").trim();
        if (!observation) {
          if (msg) {
            msg.textContent = "La observación es obligatoria.";
            msg.classList.add("is-error");
          }
          return;
        }
        if (saveBtn) saveBtn.disabled = true;
        if (msg) {
          msg.textContent = "Guardando cambio...";
          msg.classList.remove("is-error");
        }
        calendarApiRequest(root, "cambiar_estado", {
          id_evento: eventId,
          observacion: observation,
        }).then(function (json) {
          if (!json || !json.success) {
            throw new Error((json && json.message) || "No se pudo marcar el evento como realizado.");
          }
          row.estado = "Si";
          var status = mini.querySelector("[data-scm-case-calendar-event-state]");
          if (status) {
            status.textContent = "Realizado";
            status.classList.add("is-done");
          }
          var actions = mini.querySelector("[data-scm-case-calendar-mini-actions]");
          if (actions) {
            actions.innerHTML = '<span class="scm-case-calendar-event-state-badge is-done">Realizado</span>';
          }
          form.hidden = true;
          scmNotify("success", json.message || "Evento marcado como realizado.", "Calendario");
        }).catch(function (error) {
          if (msg) {
            msg.textContent = error.message || "No se pudo marcar el evento como realizado.";
            msg.classList.add("is-error");
          }
          scmNotify("error", error.message || "No se pudo marcar el evento como realizado.");
        }).finally(function () {
          if (saveBtn) saveBtn.disabled = false;
        });
      });
    }
  }

  function renderCaseCalendarMonth(root, shell, employeeId, monthDate) {
    var grid = shell ? shell.querySelector("[data-scm-case-calendar-grid]") : null;
    var title = shell ? shell.querySelector("[data-scm-case-calendar-title]") : null;
    if (title) title.textContent = caseCalendarMonthTitle(monthDate);
    if (!grid) return Promise.resolve();
    if (!employeeId) {
      grid.innerHTML = '<div class="scm-case-calendar-empty">Este caso no tiene funcionario asignado.</div>';
      return Promise.resolve();
    }
    if (!isCalendarEmployeeAllowed(root, employeeId)) {
      grid.innerHTML = '<div class="scm-case-calendar-empty">El funcionario asignado no pertenece a los cargos visibles del calendario.</div>';
      return Promise.resolve();
    }
    grid.innerHTML = '<div class="scm-case-calendar-empty">Cargando calendario...</div>';
    var range = caseCalendarMonthRange(monthDate);
    return calendarApiRequest(root, "filtrar_eventos_admin", {
      id_empleado: employeeId,
      fecha_inicio: range.from,
      fecha_fin: range.to,
      pagina: 1,
      limite: 250,
    }).then(function (json) {
      if (!json || !json.success) {
        throw new Error((json && json.message) || "No se pudo cargar el calendario.");
      }
      var rowsByDay = {};
      extractCalendarRows(json.data || []).forEach(function (row) {
        var key = caseCalendarEventDateKey(row);
        if (!key) return;
        if (!rowsByDay[key]) rowsByDay[key] = [];
        rowsByDay[key].push(row);
      });
      Object.keys(rowsByDay).forEach(function (key) {
        rowsByDay[key].sort(function (a, b) {
          return String(a.fecha_inicio || "").localeCompare(String(b.fecha_inicio || ""));
        });
      });

      var first = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1);
      var startOffset = (first.getDay() + 6) % 7;
      var cursor = addCalendarDays(first, -startOffset);
      var todayKey = calendarDateKey(new Date());
      var cells = [];
      var eventDetails = [];
      for (var i = 0; i < 42; i += 1) {
        var current = addCalendarDays(cursor, i);
        var key = calendarDateKey(current);
        var rows = rowsByDay[key] || [];
        var holiday = caseCalendarHolidayLabel(current);
        var classes = ["scm-case-calendar-day"];
        if (current.getMonth() !== monthDate.getMonth()) classes.push("is-muted");
        if (key === todayKey) classes.push("is-today");
        if (holiday) classes.push("is-holiday");
        cells.push(
          '<div class="' + classes.join(" ") + '">' +
          '<div class="scm-case-calendar-day-head"><span class="scm-case-calendar-day-number">' + current.getDate() + '</span>' +
          (holiday ? '<span class="scm-case-calendar-day-holiday">' + escHtml(holiday) + '</span>' : "") +
          "</div>" +
          rows.slice(0, 3).map(function (row) {
            var detailIndex = eventDetails.push(row) - 1;
            var done = isCaseCalendarEventDone(row);
            return '<div class="scm-case-calendar-day-pill"><div><strong>' + escHtml(formatCalendarDateTime(row.fecha_inicio)) + '</strong><span>' + escHtml(row.titulo || "Evento") + '</span><em class="scm-case-calendar-day-status' + (done ? " is-done" : "") + '">' + (done ? "Realizado" : "Pendiente") + '</em></div><button type="button" class="scm-case-calendar-event-detail-btn" data-scm-case-calendar-event-detail="' + detailIndex + '" aria-label="Ver detalle de ' + escHtml(row.titulo || "evento") + '">Ver</button></div>';
          }).join("") +
          (rows.length > 3 ? '<div class="scm-case-calendar-day-more">+' + (rows.length - 3) + " más</div>" : "") +
          "</div>",
        );
      }
      grid.innerHTML = cells.join("");
      shell._scmCaseCalendarEventDetails = eventDetails;
      grid.querySelectorAll("[data-scm-case-calendar-event-detail]").forEach(function (btn) {
        btn.addEventListener("click", function (event) {
          event.preventDefault();
          event.stopPropagation();
          var index = parseInt(btn.getAttribute("data-scm-case-calendar-event-detail") || "-1", 10);
          openCaseCalendarEventMini(root, shell, shell._scmCaseCalendarEventDetails[index]);
        });
      });
    }).catch(function (error) {
      grid.innerHTML = '<div class="scm-case-calendar-empty">No se pudo cargar el calendario.</div>';
      scmNotify("error", error.message || "No se pudo cargar el calendario.");
    });
  }

  function renderCaseCalendarPendingRows(rows) {
    rows = Array.isArray(rows) ? rows : [];
    if (!rows.length) {
      return '<div class="scm-case-calendar-pending-empty">No hay eventos pendientes vencidos para este funcionario.</div>';
    }
    return rows.map(function (row, index) {
      var ticket = caseCalendarEventDetailValue(row, ["id_ticket", "ticket"]);
      var category = caseCalendarEventDetailValue(row, ["categoria", "nombre_categoria"]);
      return '<article class="scm-case-calendar-pending-row">' +
        '<div><strong>' + escHtml(row.titulo || "Evento") + '</strong><span>' + escHtml(formatCalendarDateTime(row.fecha_inicio || row.fecha || row.start)) + (row.fecha_fin ? " - " + escHtml(formatCalendarDateTime(row.fecha_fin)) : "") + '</span></div>' +
        '<p>' + (category ? '<b>Categor&iacute;a:</b> ' + escHtml(category) + ' ' : "") + (ticket ? '<b>Ticket:</b> #' + escHtml(ticket) : "") + '</p>' +
        '<button type="button" class="scm-case-calendar-complete-btn" data-scm-case-calendar-pending-detail="' + index + '">Ver detalle</button>' +
        '</article>';
    }).join("");
  }

  function openCaseCalendarPendingMini(root, shell, employeeId, employeeName) {
    if (!shell) return;
    closeCaseCalendarEventMini(shell);
    closeCaseCalendarPendingMini(shell);
    var html =
      '<div class="scm-case-calendar-event-mini scm-case-calendar-pending-mini" data-scm-case-calendar-pending-mini>' +
      '<div class="scm-case-calendar-event-mini-card scm-case-calendar-pending-card" role="dialog" aria-modal="true" aria-label="Eventos pendientes">' +
      '<button type="button" class="scm-case-calendar-event-mini-close" data-scm-case-calendar-pending-close aria-label="Cerrar pendientes">&times;</button>' +
      '<div class="scm-case-calendar-event-mini-head"><span>Eventos pendientes</span><strong>' + escHtml(employeeName || "Funcionario asignado") + '</strong></div>' +
      '<div class="scm-case-calendar-pending-list" data-scm-case-calendar-pending-list><div class="scm-case-calendar-pending-empty">Cargando pendientes...</div></div>' +
      "</div>" +
      "</div>";
    shell.insertAdjacentHTML("beforeend", html);
    var mini = shell.querySelector("[data-scm-case-calendar-pending-mini]");
    var list = mini ? mini.querySelector("[data-scm-case-calendar-pending-list]") : null;
    if (!mini || !list) return;
    mini.addEventListener("click", function (event) {
      if (event.target === mini || (event.target && event.target.closest && event.target.closest("[data-scm-case-calendar-pending-close]"))) {
        closeCaseCalendarPendingMini(shell);
        return;
      }
      var detailBtn = event.target && event.target.closest ? event.target.closest("[data-scm-case-calendar-pending-detail]") : null;
      if (detailBtn) {
        event.preventDefault();
        var index = parseInt(detailBtn.getAttribute("data-scm-case-calendar-pending-detail") || "-1", 10);
        var row = shell._scmCaseCalendarPendingRows && shell._scmCaseCalendarPendingRows[index];
        closeCaseCalendarPendingMini(shell);
        openCaseCalendarEventMini(root, shell, row);
      }
    });
    if (!employeeId) {
      list.innerHTML = '<div class="scm-case-calendar-pending-empty">Este caso no tiene funcionario asignado.</div>';
      return;
    }
    calendarApiRequest(root, "listar_pendientes_vencidos", {
      id_empleado: employeeId,
    }).then(function (json) {
      if (!json || !json.success) {
        throw new Error((json && json.message) || "No se pudieron cargar pendientes.");
      }
      var rows = extractCalendarRows(json.data || []);
      shell._scmCaseCalendarPendingRows = rows;
      list.innerHTML = renderCaseCalendarPendingRows(rows);
    }).catch(function (error) {
      list.innerHTML = '<div class="scm-case-calendar-pending-empty">No se pudieron cargar pendientes.</div>';
      scmNotify("error", error.message || "No se pudieron cargar pendientes.");
    });
  }

  function openCalendarCaseMonthPopup(root, employeeId, employeeName, selectedDate) {
    if (!window.Swal || typeof window.Swal.fire !== "function") {
      scmNotify("error", "No se pudo abrir el calendario.");
      return;
    }
    var currentMonth = caseCalendarMonthDate(selectedDate);
    window.Swal.fire({
      title: "Calendario del funcionario",
      html:
        '<div class="scm-case-calendar-month-shell" data-scm-case-calendar-popup>' +
        '<div class="scm-case-calendar-toolbar">' +
        '<button type="button" class="scm-case-calendar-nav" data-scm-case-calendar-prev aria-label="Mes anterior">&lsaquo;</button>' +
        '<div class="scm-case-calendar-heading"><span>' + escHtml(employeeName || "Funcionario asignado") + '</span><strong data-scm-case-calendar-title>' + escHtml(caseCalendarMonthTitle(currentMonth)) + '</strong><small>Eventos y festivos de Colombia</small></div>' +
        '<button type="button" class="scm-case-calendar-nav" data-scm-case-calendar-next aria-label="Mes siguiente">&rsaquo;</button>' +
        "</div>" +
        '<div class="scm-case-calendar-top-actions"><button type="button" class="scm-case-calendar-pending-btn" data-scm-case-calendar-pending>Eventos pendientes</button></div>' +
        '<div class="scm-case-calendar-weekdays"><span>Lu</span><span>Ma</span><span>Mi</span><span>Ju</span><span>Vi</span><span>Sa</span><span>Do</span></div>' +
        '<div class="scm-case-calendar-grid" data-scm-case-calendar-grid><div class="scm-case-calendar-empty">Cargando calendario...</div></div>' +
        "</div>",
      width: 1120,
      showConfirmButton: false,
      showCancelButton: true,
      cancelButtonText: "Cerrar calendario",
      confirmButtonColor: "#f59e0b",
      cancelButtonColor: "#e2e8f0",
      allowOutsideClick: false,
      allowEscapeKey: false,
      customClass: { popup: "scm-calendar-swal-popup scm-case-calendar-swal" },
      didOpen: function () {
        var popup = window.Swal.getPopup();
        var shell = popup ? popup.querySelector("[data-scm-case-calendar-popup]") : null;
        var render = function () {
          renderCaseCalendarMonth(root, shell, employeeId, currentMonth);
        };
        var prev = popup ? popup.querySelector("[data-scm-case-calendar-prev]") : null;
        var next = popup ? popup.querySelector("[data-scm-case-calendar-next]") : null;
        var pending = popup ? popup.querySelector("[data-scm-case-calendar-pending]") : null;
        if (prev) {
          prev.addEventListener("click", function () {
            currentMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() - 1, 1);
            render();
          });
        }
        if (next) {
          next.addEventListener("click", function () {
            currentMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 1);
            render();
          });
        }
        if (pending) {
          pending.addEventListener("click", function () {
            openCaseCalendarPendingMini(root, shell, employeeId, employeeName);
          });
        }
        render();
      },
    });
  }

  function renderCalendarCaseEmployeeAgenda(root, body, employeeId) {
    var agenda = body ? body.querySelector("[data-scm-calendar-case-agenda]") : null;
    if (!agenda) return;
    if (!employeeId) {
      agenda.innerHTML = '<div class="scm-calendar-popup-agenda-empty">Este caso no tiene funcionario asignado.</div>';
      return;
    }
    if (!isCalendarEmployeeAllowed(root, employeeId)) {
      agenda.innerHTML = '<div class="scm-calendar-popup-agenda-empty">El funcionario asignado no pertenece a los cargos visibles del calendario.</div>';
      return;
    }
    agenda.innerHTML = '<div class="scm-calendar-popup-agenda-empty">Cargando agenda del funcionario...</div>';
    var range = calendarCurrentMonthRange();
    calendarApiRequest(root, "filtrar_eventos_admin", {
      id_empleado: employeeId,
      fecha_inicio: range.from,
      fecha_fin: range.to,
      pagina: 1,
      limite: 80,
    }).then(function (json) {
      if (!json || !json.success) {
        throw new Error((json && json.message) || "No se pudo cargar la agenda.");
      }
      var rows = extractCalendarRows(json.data || []).sort(function (a, b) {
        return String(a.fecha_inicio || "").localeCompare(String(b.fecha_inicio || ""));
      });
      if (!rows.length) {
        agenda.innerHTML = '<div class="scm-calendar-popup-agenda-empty">El funcionario no tiene eventos en el mes visible.</div>';
        return;
      }
      agenda.innerHTML = rows.slice(0, 12).map(function (row) {
        return '<div class="scm-calendar-popup-agenda-item"><strong>' + escHtml(formatCalendarDateTime(row.fecha_inicio)) + '</strong><span>' + escHtml(row.titulo || "Evento") + '</span></div>';
      }).join("");
    }).catch(function (error) {
      agenda.innerHTML = '<div class="scm-calendar-popup-agenda-empty">No se pudo cargar la agenda.</div>';
      scmNotify("error", error.message || "No se pudo cargar la agenda.");
    });
  }

  function openCalendarCaseEventEditor(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var root = findRootFromNode(caseBtn);
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var ticketPk = String(caseBtn.dataset.ticketPk || "").trim();
    var employeeId = String(caseBtn.dataset.empleadoId || "").trim();
    var contractLabel = String(caseBtn.dataset.contrato || "").trim();
    var asuntoLabel = String(caseBtn.dataset.asunto || "").trim();
    var addressLabel = String(caseBtn.dataset.direccion || "").trim();
    var today = new Date();
    var yyyy = today.getFullYear();
    var mm = String(today.getMonth() + 1).padStart(2, "0");
    var dd = String(today.getDate()).padStart(2, "0");
    var dateValue = yyyy + "-" + mm + "-" + dd;
    if (title) title.textContent = "Agendar cita del caso";
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<form class="scm-calendar-case-form" method="post" autocomplete="off">' +
        '<input type="hidden" name="id_ticket" value="' + escHtml(ticketPk) + '">' +
        '<input type="hidden" name="id_empleado" value="' + escHtml(employeeId) + '">' +
        '<input type="hidden" name="es_cita" value="si">' +
        '<div class="scm-calendar-case-note">Se crear&aacute; el evento en el calendario y quedar&aacute; enlazado al ticket #' + escHtml(ticketPk || "-") + '.</div>' +
        '<div class="scm-grid">' +
        '<label class="scm-seg-field"><span>T&iacute;tulo</span><input class="input input-bordered input-sm scm-input" name="titulo" required value="' + escHtml(buildCaseCalendarTitle("", contractLabel, ticketPk)) + '" data-auto-calendar-title="1"></label>' +
        '<label class="scm-seg-field"><span>Categor&iacute;a</span><select class="select select-bordered select-sm scm-select" name="id_categoria" required data-scm-calendar-case-categories><option value="">Cargando...</option></select></label>' +
        '<label class="scm-seg-field"><span>Fecha</span><input class="input input-bordered input-sm scm-input" type="date" name="fecha" required value="' + escHtml(dateValue) + '"></label>' +
        '<label class="scm-seg-field"><span>Hora inicio</span><input class="input input-bordered input-sm scm-input" type="time" name="hora_inicio" required></label>' +
        '<label class="scm-seg-field"><span>Hora fin</span><input class="input input-bordered input-sm scm-input" type="time" name="hora_fin" required></label>' +
        '<label class="scm-seg-field scm-calendar-field-full"><span>Ubicaci&oacute;n</span><input class="input input-bordered input-sm scm-input" name="ubicacion" value="' + escHtml(addressLabel && addressLabel !== "-" ? addressLabel : "") + '" data-auto-calendar-location="1"></label>' +
        '<label class="scm-seg-field scm-calendar-field-full"><span>Descripci&oacute;n</span><textarea class="textarea textarea-bordered scm-input" name="descripcion" rows="4" required data-auto-calendar-text="1">' + escHtml(asuntoLabel && asuntoLabel !== "-" ? asuntoLabel : "") + '</textarea><small>Se autocompleta con el texto de cita y puedes editarlo si necesitas ajustar el mensaje.</small></label>' +
        "</div>" +
        '<div class="scm-calendar-case-tools"><div><strong>Disponibilidad del funcionario</strong><span>Revisa el calendario sin cerrar este formulario.</span></div><button type="button" class="scm-case-work-btn scm-calendar-case-open-btn" data-scm-calendar-case-open-month>Ver calendario</button></div>' +
        '<div class="scm-seg-actions"><button type="submit" class="scm-btn-primary">Crear evento</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");

    var form = body ? body.querySelector(".scm-calendar-case-form") : null;
    var categorySelect = body ? body.querySelector("[data-scm-calendar-case-categories]") : null;
    var titleInput = form ? form.querySelector('[name="titulo"]') : null;
    var locationInput = form ? form.querySelector('[name="ubicacion"]') : null;
    var descriptionInput = form ? form.querySelector('[name="descripcion"]') : null;
    var dateInput = form ? form.querySelector('[name="fecha"]') : null;
    var startInput = form ? form.querySelector('[name="hora_inicio"]') : null;
    var endInput = form ? form.querySelector('[name="hora_fin"]') : null;
    var calendarMonthBtn = form ? form.querySelector("[data-scm-calendar-case-open-month]") : null;
    function maybeAutofillCaseCalendarFields(force) {
      var categoryName = selectedCalendarCategoryName(categorySelect);
      if (titleInput) {
        var nextTitle = buildCaseCalendarTitle(categoryName, contractLabel, ticketPk);
        if (nextTitle && (force || !titleInput.value || titleInput.getAttribute("data-auto-calendar-title") === "1")) {
          titleInput.value = nextTitle;
          titleInput.setAttribute("data-auto-calendar-title", "1");
        }
      }
      if (locationInput && addressLabel && addressLabel !== "-" && (force || !locationInput.value || locationInput.getAttribute("data-auto-calendar-location") === "1")) {
        locationInput.value = addressLabel;
        locationInput.setAttribute("data-auto-calendar-location", "1");
      }
      if (!descriptionInput || !dateInput || !startInput || !endInput) return;
      if (!dateInput.value || !startInput.value || !endInput.value || !categoryName) return;
      if (descriptionInput.value && descriptionInput.getAttribute("data-auto-calendar-text") !== "1") return;
      descriptionInput.value = buildCaseCalendarDescription(categoryName, dateInput.value, startInput.value, endInput.value, asuntoLabel);
      descriptionInput.setAttribute("data-auto-calendar-text", "1");
    }
    loadCalendarCategories(root)
      .then(function (rows) {
        if (!categorySelect) return;
        categorySelect.innerHTML = '<option value="">Selecciona categoria</option>';
        administrativeCalendarCategories(rows).forEach(function (row) {
          var id = String(row.id || row._ID || row.id_categoria || "").trim();
          var name = calendarCategoryLabel(row) || id;
          if (!id) return;
          categorySelect.innerHTML += '<option value="' + escHtml(id) + '">' + escHtml(name) + "</option>";
        });
        maybeAutofillCaseCalendarFields(false);
      })
      .catch(function (error) {
        if (categorySelect) {
          categorySelect.innerHTML = '<option value="">No se pudieron cargar categorias</option>';
        }
        scmNotify("error", error.message || "No se pudieron cargar categorias.");
      });

    if (form) {
      [categorySelect, dateInput, startInput, endInput].forEach(function (field) {
        if (!field) return;
        field.addEventListener("change", function () {
          maybeAutofillCaseCalendarFields(false);
        });
      });
      if (titleInput) {
        titleInput.addEventListener("input", function () {
          titleInput.setAttribute("data-auto-calendar-title", "0");
        });
      }
      if (locationInput) {
        locationInput.addEventListener("input", function () {
          locationInput.setAttribute("data-auto-calendar-location", "0");
        });
      }
      if (descriptionInput) {
        descriptionInput.addEventListener("input", function () {
          descriptionInput.setAttribute("data-auto-calendar-text", "0");
        });
      }
      if (calendarMonthBtn) {
        calendarMonthBtn.addEventListener("click", function () {
          openCalendarCaseMonthPopup(
            root,
            employeeId,
            caseBtn.dataset.empleado || caseBtn.dataset.asignado || "",
            dateInput ? dateInput.value : "",
          );
        });
      }
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        if (!employeeId) {
          scmNotify("error", "Este caso no tiene id_empleado para agendar la cita.");
          return;
        }
        if (!isCalendarEmployeeAllowed(root, employeeId)) {
          scmNotify("error", "El funcionario asignado no pertenece a los cargos visibles del calendario.");
          return;
        }
        var submitBtn = form.querySelector("button[type='submit']");
        var msg = form.querySelector(".scm-seg-msg");
        var fd = new FormData(form);
        var dateValue = String(fd.get("fecha") || "");
        var startValue = String(fd.get("hora_inicio") || "");
        var endValue = String(fd.get("hora_fin") || "");
        var timeError = validateCalendarCaseEventTimes(dateValue, startValue, endValue);
        if (timeError) {
          if (msg) {
            msg.textContent = timeError;
            msg.classList.add("error");
          }
          scmNotify("error", timeError, "Calendario");
          return;
        }
        var payload = {
          titulo: fd.get("titulo") || "",
          descripcion: fd.get("descripcion") || "",
          ubicacion: fd.get("ubicacion") || "",
          fecha_inicio: dateValue + " " + startValue + ":00",
          fecha_fin: dateValue + " " + endValue + ":00",
          id_empleado: employeeId,
          id_categoria: fd.get("id_categoria") || "",
          id_ticket: ticketPk,
          es_cita: "si",
          estado_administrativo: "Por inspeccionar",
        };
        if (submitBtn) submitBtn.disabled = true;
        if (msg) {
          msg.textContent = "Creando evento...";
          msg.classList.remove("error");
        }
        calendarApiRequest(root, "crear_evento", payload)
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error((json && json.message) || "No se pudo crear el evento.");
            }
            if (msg) msg.textContent = json.message || "Evento creado.";
            scmNotify("success", json.message || "Evento creado.", "Calendario");
            return notifyCalendarAppointment(root, [
              Object.assign({}, payload, {
                categoria: selectedCalendarCategoryName(categorySelect),
              }),
            ]).then(function (notifyResult) {
              showCalendarCitaNotificationResult(notifyResult, msg);
              return json;
            });
          })
          .then(function () {
            if (root && typeof window.CustomEvent === "function") {
              root.dispatchEvent(new CustomEvent("scm:case-action-saved", {
                detail: { ticketPk: ticketPk, fromNode: form },
              }));
            }
          })
          .catch(function (error) {
            if (msg) {
              msg.textContent = error.message || "No se pudo crear el evento.";
              msg.classList.add("error");
            }
            scmNotify("error", error.message || "No se pudo crear el evento.");
          })
          .finally(function () {
            if (submitBtn) submitBtn.disabled = false;
          });
      });
    }
  }

  function renderNotifyTargets(exclude) {
    exclude = Array.isArray(exclude) ? exclude : [];
    var all = [
      { value: "solicitante", label: "Solicitante" },
      { value: "arrendatario", label: "Arrendatario" },
      { value: "propietario", label: "Propietario" },
      { value: "empleado", label: "Empleado" },
      { value: "admin", label: "Administrativos" },
    ];
    var html =
      '<input type="hidden" name="notify_recipients_present" value="1">' +
      '<fieldset class="scm-notify-targets"><legend>Notificar por correo</legend>';
    all.forEach(function (opt) {
      if (exclude.indexOf(opt.value) !== -1) return;
      html +=
        '<label class="scm-seg-check"><input type="checkbox" name="notify_recipients[]" value="' +
        opt.value +
        '" checked> ' +
        opt.label +
        "</label>";
    });
    html +=
      '<label class="scm-seg-check scm-seg-check--none"><input type="checkbox" name="notify_recipients[]" value="none"> Ninguno</label>';
    html += "</fieldset>";
    return html;
  }

  function normalizeIndicativo(value) {
    value = String(value || "").replace(/[^0-9+]/g, "");
    if (!value) return "";
    return value.charAt(0) === "+" ? value : "+" + value.replace(/^\++/, "");
  }

  function indicativoFieldHtml(name, value, options) {
    value = normalizeIndicativo(value || "+57");
    options = Array.isArray(options) ? options : [];
    if (!options.length) {
      return (
        '<label class="scm-seg-field"><span>Indicativo</span><input class="input input-bordered input-sm scm-input" name="' +
        escHtml(name) +
        '" type="text" value="' +
        escHtml(value) +
        '" placeholder="+57"></label>'
      );
    }

    var found = false;
    var html =
      '<label class="scm-seg-field"><span>Indicativo</span><select class="select select-bordered select-sm scm-select" name="' +
      escHtml(name) +
      '">';
    options.forEach(function (opt) {
      var code = normalizeIndicativo(opt && opt.codigo);
      if (!code) return;
      var label = String((opt && opt.label) || code);
      if (code === value) found = true;
      html +=
        '<option value="' +
        escHtml(code) +
        '"' +
        (code === value ? " selected" : "") +
        ">" +
        escHtml(label) +
        "</option>";
    });
    if (value && !found) {
      html +=
        '<option value="' +
        escHtml(value) +
        '" selected>' +
        escHtml(value) +
        "</option>";
    }
    return html + "</select></label>";
  }

  function openContactEditor(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var root = findRootFromNode(caseBtn);
    var runtime = root ? parseRuntime(root) || {} : {};
    var indicativos = runtime.indicativos || [];
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var ticketPk = caseBtn.dataset.ticketPk || "";
    if (title) title.textContent = "Editar propietario y arrendatario";
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<form class="scm-contact-update-form" method="post" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<div class="scm-contact-edit-grid">' +
        '<fieldset class="scm-contact-edit-group"><legend>Propietario</legend>' +
        '<label class="scm-seg-field"><span>Nombre</span><input class="input input-bordered input-sm scm-input" name="propietario" type="text" value="' +
        escHtml(caseBtn.dataset.propietario || "") +
        '"></label>' +
        '<label class="scm-seg-field"><span>Correo</span><input class="input input-bordered input-sm scm-input" name="correo_propietario" type="email" value="' +
        escHtml(caseBtn.dataset.correoPropietario || "") +
        '"></label>' +
        indicativoFieldHtml(
          "indicativo_propietario",
          caseBtn.dataset.indicativoPropietario || "",
          indicativos,
        ) +
        '<label class="scm-seg-field"><span>Celular</span><input class="input input-bordered input-sm scm-input" name="celular_propietario" type="text" value="' +
        escHtml(caseBtn.dataset.celularPropietario || "") +
        '"></label>' +
        "</fieldset>" +
        '<fieldset class="scm-contact-edit-group"><legend>Arrendatario</legend>' +
        '<label class="scm-seg-field"><span>Nombre</span><input class="input input-bordered input-sm scm-input" name="arrendatario" type="text" value="' +
        escHtml(caseBtn.dataset.arrendatario || "") +
        '"></label>' +
        '<label class="scm-seg-field"><span>Correo</span><input class="input input-bordered input-sm scm-input" name="correo_arrendatario" type="email" value="' +
        escHtml(caseBtn.dataset.correoArrendatario || "") +
        '"></label>' +
        indicativoFieldHtml(
          "indicativo_arrendatario",
          caseBtn.dataset.indicativoArrendatario || "",
          indicativos,
        ) +
        '<label class="scm-seg-field"><span>Celular</span><input class="input input-bordered input-sm scm-input" name="celular_arrendatario" type="text" value="' +
        escHtml(caseBtn.dataset.celularArrendatario || "") +
        '"></label>' +
        "</fieldset>" +
        "</div>" +
        '<div class="scm-seg-actions"><button type="submit" class="scm-btn-primary">Guardar cambios</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";
      prependCaseLocationPanel(body, caseBtn, modal);
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function renderContactViewerHtml(caseBtn) {
    function line(label, value) {
      value = String(value || "").trim();
      return value
        ? "<dt>" + escHtml(label) + "</dt><dd><strong>" + escHtml(value) + "</strong></dd>"
        : "";
    }

    var propietario =
      line("Nombre", caseBtn.dataset.propietario || "") +
      line("Correo", caseBtn.dataset.correoPropietario || "") +
      line(
        "Celular",
        [
          caseBtn.dataset.indicativoPropietario || "",
          caseBtn.dataset.celularPropietario || "",
        ]
          .join(" ")
          .trim(),
      );
    var arrendatario =
      line("Nombre", caseBtn.dataset.arrendatario || "") +
      line("Correo", caseBtn.dataset.correoArrendatario || "") +
      line(
        "Celular",
        [
          caseBtn.dataset.indicativoArrendatario || "",
          caseBtn.dataset.celularArrendatario || "",
        ]
          .join(" ")
          .trim(),
      );

    return (
      '<div class="scm-contact-view-actions"><button type="button" class="btn btn-outline btn-sm" data-scm-edit-contacts-from-view>Editar contactos</button></div>' +
      '<div class="scm-contact-view-grid">' +
      '<section class="scm-contact-view-card"><h5>Propietario</h5>' +
      (propietario
        ? '<dl class="scm-detail-list">' + propietario + "</dl>"
        : '<p class="scm-muted">Sin datos de propietario.</p>') +
      "</section>" +
      '<section class="scm-contact-view-card"><h5>Arrendatario</h5>' +
      (arrendatario
        ? '<dl class="scm-detail-list">' + arrendatario + "</dl>"
        : '<p class="scm-muted">Sin datos de arrendatario.</p>') +
      "</section>" +
      "</div>"
    );
  }

  function openContactViewer(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    if (title) title.textContent = "Contactos del caso";
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML = renderContactViewerHtml(caseBtn);
      var editBtn = body.querySelector("[data-scm-edit-contacts-from-view]");
      if (editBtn) {
        editBtn.addEventListener("click", function () {
          openContactEditor(modal, caseBtn);
        });
      }
      prependCaseLocationPanel(body, caseBtn, modal);
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function openPropertyMapViewer(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    if (title) title.textContent = "Mapa del inmueble";
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML = renderCaseLocationPanel(caseBtn, modal, false);
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function hasPerturbacionValue(rawValue) {
    var value = String(rawValue || "")
      .trim()
      .toLowerCase();
    if (
      !value ||
      value === "0" ||
      value === "no" ||
      value === "false" ||
      value === "null"
    ) {
      return false;
    }
    var num = Number(value);
    if (!isNaN(num)) {
      return num > 0;
    }
    return true;
  }

  function openLlavesDetail(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var detail = getLlavesDetailPayload(caseBtn);

    if (title) title.textContent = detail.title;
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML = detail.html;
      prependCaseLocationPanel(body, caseBtn, modal);
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function openConsultorEntregaDetail(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var detail = getConsultorEntregaDetailPayload(caseBtn);

    if (title) title.textContent = detail.title;
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML = detail.html;
      prependCaseLocationPanel(body, caseBtn, modal);
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function getLlavesDetailPayload(caseBtn) {
    var ubicacion = String(caseBtn.dataset.ubicacionLlaves || "").trim();
    var persona = String(caseBtn.dataset.personaLlaves || "").trim();
    var contacto = String(caseBtn.dataset.contactoLlaves || "").trim();
    var html = "";

    if (!ubicacion && !persona && !contacto) {
      html =
        '<p class="scm-muted">No hay informaci\u00f3n de llaves registrada.</p>';
    } else {
      html = '<dl class="scm-detail-list">';
      if (ubicacion)
        html +=
          "<dt>Ubicaci\u00f3n</dt><dd><strong>" +
          escHtml(ubicacion) +
          "</strong></dd>";
      if (persona)
        html +=
          "<dt>Persona</dt><dd><strong>" + escHtml(persona) + "</strong></dd>";
      if (contacto)
        html +=
          "<dt>Contacto</dt><dd><strong>" +
          escHtml(contacto) +
          "</strong></dd>";
      html += "</dl>";
    }

    return {
      title: "Ubicaci\u00f3n de llaves",
      html: html,
    };
  }

  function getConsultorEntregaDetailPayload(caseBtn) {
    var nombre = String(caseBtn.dataset.consultorEntrega || "").trim();
    var celular = String(caseBtn.dataset.consultorEntregaCelular || "").trim();
    var correo = String(caseBtn.dataset.consultorEntregaCorreo || "").trim();
    var html = "";

    if (!nombre && !celular && !correo) {
      html =
        '<p class="scm-muted">No hay informaci\u00f3n del consultor/a de entrega registrada.</p>';
    } else {
      html = '<dl class="scm-detail-list">';
      if (nombre)
        html +=
          "<dt>Nombre</dt><dd><strong>" + escHtml(nombre) + "</strong></dd>";
      if (celular)
        html +=
          "<dt>Celular</dt><dd><strong>" +
          escHtml(celular) +
          "</strong></dd>";
      if (correo)
        html +=
          "<dt>Correo</dt><dd><strong>" + escHtml(correo) + "</strong></dd>";
      html += "</dl>";
    }

    return {
      title: "Consultor/a de entrega",
      html: html,
    };
  }

  function ensureStandaloneDetailModal(root) {
    if (!root) {
      return null;
    }
    var existing = root.querySelector(".scm-standalone-detail-modal");
    if (existing) {
      return existing;
    }

    var wrap = document.createElement("div");
    wrap.className = "scm-standalone-detail-modal";
    wrap.setAttribute("aria-hidden", "true");
    wrap.innerHTML =
      '<div class="scm-standalone-detail-dialog" role="dialog" aria-modal="true">' +
      '<button type="button" class="scm-standalone-detail-close" aria-label="Cerrar detalle">&times;</button>' +
      '<div class="scm-standalone-detail-head"><h4 class="scm-standalone-detail-title">Detalle</h4><p class="scm-standalone-detail-meta"></p></div>' +
      '<div class="scm-standalone-detail-body"></div>' +
      "</div>";
    root.appendChild(wrap);

    function closeStandaloneDetail() {
      wrap.classList.remove("open");
      wrap.setAttribute("aria-hidden", "true");
      document.body.classList.remove("scm-modal-open");
    }

    var closeBtn = wrap.querySelector(".scm-standalone-detail-close");
    if (closeBtn) {
      closeBtn.addEventListener("click", closeStandaloneDetail);
    }

    return wrap;
  }

  function openStandaloneDetail(root, detail) {
    var modal = ensureStandaloneDetailModal(root);
    if (!modal || !detail) {
      return;
    }
    var title = modal.querySelector(".scm-standalone-detail-title");
    var meta = modal.querySelector(".scm-standalone-detail-meta");
    var body = modal.querySelector(".scm-standalone-detail-body");
    if (title) {
      title.textContent = detail.title || "Detalle";
    }
    if (meta) {
      var propertyCode = getCasePropertyCode(detail.caseBtn, modal);
      if (propertyCode) {
        meta.textContent = "Codigo inmueble web: " + propertyCode;
        meta.style.display = "block";
      } else {
        meta.textContent = "";
        meta.style.display = "none";
      }
    }
    if (body) {
      body.innerHTML = detail.html || "";
      prependCaseLocationPanel(body, detail.caseBtn || null, modal);
    }
    if (detail.caseBtn && detail.caseBtn.dataset) {
      modal.dataset.ticketPk = detail.caseBtn.dataset.ticketPk || "";
      modal.dataset.idInmuebleWeb = detail.caseBtn.dataset.idInmuebleWeb || "";
      modal.dataset.idInmuebleData = detail.caseBtn.dataset.idInmuebleData || "";
      modal.dataset.ubicacionGoogleMaps =
        detail.caseBtn.dataset.ubicacionGoogleMaps || "";
      modal.dataset.direccion = detail.caseBtn.dataset.direccion || "";
    }
    modal.classList.add("open");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("scm-modal-open");
  }

  function openPerturbacionDetail(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var perturbacion = String(caseBtn.dataset.perturbacion || "").trim();
    var justificacion = String(
      caseBtn.dataset.justificacionPerturbacion || "",
    ).trim();
    var resumenRaw = String(
      caseBtn.dataset.resumenCalculoPerturbacion || "",
    ).trim();

    if (title) title.textContent = "Resumen t\u00e9cnico de perturbaci\u00f3n";

    if (
      !hasPerturbacionValue(perturbacion) &&
      resumenRaw === "" &&
      justificacion === ""
    ) {
      if (body)
        body.innerHTML =
          '<p class="scm-muted">Este caso no tiene perturbaci\u00f3n registrada.</p>';
      setCaseSubmodalMeta(sub, caseBtn);
      prependCaseLocationPanel(body, caseBtn, modal);
      sub.classList.add("open");
      sub.setAttribute("aria-hidden", "false");
      return;
    }

    var data = null;
    if (resumenRaw !== "") {
      try {
        data = JSON.parse(resumenRaw);
      } catch (e) {
        data = null;
      }
    }

    function moneyFmt(val) {
      var num = parseFloat(String(val).replace(/[^0-9.-]/g, ""));
      if (isNaN(num) || num === 0) return "$0";
      return "$" + Math.round(num).toLocaleString("es-CO");
    }

    var tipo = data && data.tipo_valoracion ? data.tipo_valoracion : "";
    var actividad =
      data && data.actividad_comercial ? data.actividad_comercial : "";
    var criterios = data && Array.isArray(data.criterios) ? data.criterios : [];
    var porcentaje =
      data && data.perturbacion
        ? data.perturbacion.porcentaje != null
          ? data.perturbacion.porcentaje
          : perturbacion
        : perturbacion;
    var nivel = data && data.perturbacion ? data.perturbacion.nivel || "" : "";
    var descripcion =
      data && data.perturbacion ? data.perturbacion.descripcion || "" : "";
    var bonificacion =
      data && data.bonificacion_sugerida != null
        ? data.bonificacion_sugerida
        : caseBtn.dataset.valorBonificacion || 0;
    var codigo = data && data.inmueble ? data.inmueble.codigo || "" : "";
    var areaTotal =
      data && data.inmueble ? data.inmueble.area_construida || 0 : 0;
    var areaAfect =
      data && data.inmueble
        ? data.inmueble.area_afectada || 0
        : caseBtn.dataset.areaAfectada || 0;
    var canonTotal = data && data.inmueble ? data.inmueble.canon_total || 0 : 0;
    var idTicket = data && data.ticket ? data.ticket.id_ticket || "" : "";
    var fechaTicket = data && data.ticket ? data.ticket.fecha_ticket || "" : "";
    var fechaCot =
      data && data.ticket ? data.ticket.fecha_cotizacion || "" : "";
    var diasTicket =
      data && data.ticket ? data.ticket.dias_desde_ticket || 0 : 0;
    var duracion = data && data.ticket ? data.ticket.duracion_trabajo || 0 : 0;
    var diasCalc =
      data && data.ticket ? data.ticket.dias_afectacion_calculados || 0 : 0;

    var styles =
      '<style id="skc-rp-styles">' +
      ".skc-rp{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:20px;font-family:inherit;box-shadow:0 8px 24px rgba(15,23,42,.08);}" +
      ".skc-rp-hdr{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;align-items:center;margin-bottom:14px;}" +
      ".skc-rp-title{font-size:17px;font-weight:900;color:#111827;margin:0 0 3px;}" +
      ".skc-rp-subtitle{font-size:12px;color:#6b7280;}" +
      ".skc-rp-badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:800;background:#eff6ff;color:#1d4ed8;margin:2px;}" +
      ".skc-rp-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:12px 0;}" +
      ".skc-rp-card{border:1px solid #e5e7eb;background:#f9fafb;border-radius:12px;padding:12px;}" +
      ".skc-rp-card.rp-primary{background:linear-gradient(135deg,#404041,#63605B);color:#fff;border-color:transparent;}" +
      ".skc-rp-card.rp-success{background:#f0fdf4;border-color:#bbf7d0;}" +
      ".skc-rp-lbl{font-size:11px;color:#6b7280;font-weight:700;margin-bottom:4px;}" +
      ".skc-rp-card.rp-primary .skc-rp-lbl{color:rgba(255,255,255,.75);}" +
      ".skc-rp-val{font-size:19px;font-weight:900;color:#111827;line-height:1.1;}" +
      ".skc-rp-card.rp-primary .skc-rp-val{color:#fff;}" +
      ".skc-rp-money{color:#15803d;}" +
      ".skc-rp-sec{font-size:13px;font-weight:900;color:#111827;margin:14px 0 6px;}" +
      ".skc-rp-txt{font-size:12px;color:#374151;line-height:1.55;}" +
      ".skc-rp-formula{background:#fff7ed;color:#7c2d12;border:1px solid #fed7aa;border-radius:12px;padding:10px;font-size:12px;line-height:1.7;}" +
      ".skc-rp-crits{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:8px;}" +
      ".skc-rp-crit{background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:8px;font-size:11px;color:#374151;}" +
      "@media(max-width:560px){.skc-rp-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.skc-rp-crits{grid-template-columns:1fr;}}" +
      "</style>";

    var headerBadges = "";
    if (tipo)
      headerBadges += '<span class="skc-rp-badge">' + escHtml(tipo) + "</span>";
    if (tipo.toLowerCase() === "comercial" && actividad)
      headerBadges +=
        '<span class="skc-rp-badge">' + escHtml(actividad) + "</span>";

    var grid1 =
      '<div class="skc-rp-grid">' +
      '<div class="skc-rp-card rp-primary"><div class="skc-rp-lbl">Perturbaci\u00f3n sugerida</div><div class="skc-rp-val">' +
      escHtml(String(porcentaje)) +
      "%</div></div>" +
      '<div class="skc-rp-card"><div class="skc-rp-lbl">Nivel</div><div class="skc-rp-val">' +
      escHtml(nivel) +
      "</div></div>" +
      '<div class="skc-rp-card rp-success"><div class="skc-rp-lbl">Bonificaci\u00f3n sugerida</div><div class="skc-rp-val skc-rp-money">' +
      escHtml(moneyFmt(bonificacion)) +
      "</div></div>" +
      '<div class="skc-rp-card"><div class="skc-rp-lbl">D\u00edas calculados</div><div class="skc-rp-val">' +
      escHtml(String(diasCalc)) +
      "</div></div>" +
      "</div>";

    var grid2 =
      '<div class="skc-rp-grid">' +
      '<div class="skc-rp-card"><div class="skc-rp-lbl">Canon total</div><div class="skc-rp-val">' +
      escHtml(moneyFmt(canonTotal)) +
      "</div></div>" +
      '<div class="skc-rp-card"><div class="skc-rp-lbl">\u00c1rea total</div><div class="skc-rp-val">' +
      escHtml(String(areaTotal)) +
      " m2</div></div>" +
      '<div class="skc-rp-card"><div class="skc-rp-lbl">\u00c1rea afectada</div><div class="skc-rp-val">' +
      escHtml(String(areaAfect)) +
      " m2</div></div>" +
      '<div class="skc-rp-card"><div class="skc-rp-lbl">Ticket / Inmueble</div><div class="skc-rp-val">#' +
      escHtml(String(idTicket)) +
      "</div>" +
      (codigo
        ? '<div class="skc-rp-txt">C\u00f3d: ' + escHtml(codigo) + "</div>"
        : "") +
      "</div>" +
      "</div>";

    var descHtml = descripcion
      ? '<div class="skc-rp-sec">Descripci\u00f3n t\u00e9cnica</div><div class="skc-rp-txt">' +
        escHtml(descripcion) +
        "</div>"
      : "";

    var diasHtml =
      '<div class="skc-rp-sec">C\u00e1lculo de d\u00edas</div>' +
      '<div class="skc-rp-formula">' +
      "<strong>Fecha ticket:</strong> " +
      escHtml(String(fechaTicket)) +
      "<br>" +
      "<strong>Fecha cotizaci\u00f3n:</strong> " +
      escHtml(String(fechaCot)) +
      "<br>" +
      "<strong>D\u00edas desde ticket:</strong> " +
      escHtml(String(diasTicket)) +
      "<br>" +
      "<strong>Duraci\u00f3n del trabajo:</strong> " +
      escHtml(String(duracion)) +
      "</div>";

    var critsHtml = "";
    if (criterios.length > 0) {
      critsHtml =
        '<div class="skc-rp-sec">Criterios seleccionados</div><div class="skc-rp-crits">';
      criterios.forEach(function (c) {
        critsHtml +=
          '<div class="skc-rp-crit">' + escHtml(String(c)) + "</div>";
      });
      critsHtml += "</div>";
    }

    var justHtml =
      '<div class="skc-rp-sec">Justificaci\u00f3n</div>' +
      '<div class="skc-rp-txt">' +
      (justificacion
        ? escHtml(justificacion)
        : '<em style="color:#9ca3af;">A\u00fan no est\u00e1 definida.</em>') +
      "</div>";

    if (body) {
      body.innerHTML =
        styles +
        '<div class="skc-rp">' +
        '<div class="skc-rp-hdr">' +
        '<div><div class="skc-rp-title">Resumen t\u00e9cnico de perturbaci\u00f3n</div>' +
        '<div class="skc-rp-subtitle">Informaci\u00f3n calculada autom\u00e1ticamente para apoyar la revisi\u00f3n de la cotizaci\u00f3n.</div></div>' +
        (headerBadges ? "<div>" + headerBadges + "</div>" : "") +
        "</div>" +
        grid1 +
        grid2 +
        descHtml +
        diasHtml +
        critsHtml +
        justHtml +
        "</div>";
      prependCaseLocationPanel(body, caseBtn, modal);
    }
    setCaseSubmodalMeta(sub, caseBtn);
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function openCloseTicketEditor(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var ticketPk = caseBtn.dataset.ticketPk || "";
    var isPublicPqr = (caseBtn.dataset.caseKind || "") === "public-pqr";
    if (title) title.textContent = isPublicPqr ? "Cerrar solicitud" : "Cerrar ticket";
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<form class="scm-close-ticket-form" method="post" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<p class="scm-muted">Esta acci&oacute;n cerrar&aacute; ' +
        (isPublicPqr ? "la solicitud" : "el ticket") +
        ' y marcar&aacute; el estado administrativo como Finalizado.</p>' +
        '<label class="scm-seg-field"><span>Mensaje de cierre</span><textarea name="observacion" rows="6" required placeholder="' +
        (isPublicPqr
          ? "Escribe el mensaje o motivo para cerrar la solicitud..."
          : "Escribe el mensaje o motivo para cerrar el ticket...") +
        '"></textarea></label>' +
        '<div class="scm-seg-actions"><button type="submit" class="scm-btn-primary">Confirmar cierre</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";
      prependCaseLocationPanel(body, caseBtn, modal);
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  window.scmCloseCase = function (trigger) {
    var modal = null;
    if (trigger && trigger.closest) {
      modal = trigger.closest(".scm-case-modal");
    }
    if (!modal) {
      modal = document.querySelector("#scm-app #scm-case-modal.open");
    }
    closeCaseModal(modal);
  };

  window.scmOpenCase = function (btn) {
    if (!btn) {
      return;
    }
    var root = findRootFromNode(btn);
    if (!root) {
      return;
    }

    var modal = getCaseModal(root);
    if (!modal) {
      return;
    }

    try {
      var sourceHtml = "";
      var card = btn.closest(".scm-ticket-card");
      var isPublicPqr = (btn.dataset.caseKind || "") === "public-pqr";
      if (card) {
        var cardSource = card.querySelector(".scm-case-source");
        if (cardSource) {
          sourceHtml = cardSource.innerHTML || "";
        }
      }

      if (!sourceHtml) {
        var tr = btn.closest("tr");
        if (tr) {
          var sourceRow = tr.nextElementSibling;
          if (sourceRow && sourceRow.classList.contains("scm-tl-row")) {
            var sourceCell = sourceRow.querySelector("td");
            if (sourceCell) {
              sourceHtml = sourceCell.innerHTML || "";
            }
          }
        }
      }

      if (!sourceHtml) {
        return;
      }

      var title = modal.querySelector("#scm-case-title");
      var subtitle = modal.querySelector("#scm-case-subtitle");
      var meta = modal.querySelector("#scm-case-meta");
      var headActions = modal.querySelector("#scm-case-head-actions");
      var body = modal.querySelector("#scm-case-body");
      var summaryItems = [];

      function escHtml(value) {
        return String(value || "")
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/\"/g, "&quot;")
          .replace(/'/g, "&#39;");
      }

      function collectSummary(label, value) {
        if (!value) {
          return;
        }
        summaryItems.push({ label: label, value: value });
      }

      if (title) {
        title.textContent =
          (isPublicPqr ? "Solicitud #" : "Caso #") +
          (btn.dataset.ticket || btn.dataset.ticketPk || "-");
      }
      if (subtitle) {
        subtitle.textContent =
          btn.dataset.asunto ||
          (isPublicPqr ? "Solicitud creada desde un portal web" : "Ticket de servicios inmobiliarios");
      }
      modal.dataset.ticketPk = btn.dataset.ticketPk || "";
      modal.dataset.caseKind = isPublicPqr ? "public-pqr" : "";
      modal.dataset.idInmuebleWeb = btn.dataset.idInmuebleWeb || "";
      modal.dataset.idInmuebleData = btn.dataset.idInmuebleData || "";
      modal.dataset.ubicacionGoogleMaps = btn.dataset.ubicacionGoogleMaps || "";
      modal.dataset.direccion = btn.dataset.direccion || "";
      if (meta) {
        meta.innerHTML = "";
        collectSummary("Estado", btn.dataset.estado || "");
        collectSummary("Estado administrativo", btn.dataset.admin || "");
        if (isPublicPqr) {
          collectSummary("Categoría", btn.dataset.categoria || "");
          collectSummary("Departamento", btn.dataset.departamento || "");
          collectSummary("Creado por", btn.dataset.creadoPor || "");
          collectSummary("Canal", btn.dataset.medio || "");
          collectSummary("Solicitante", btn.dataset.solicitante || "");
          collectSummary("Celular", btn.dataset.celularSolicitante || "");
          collectSummary("Correo", btn.dataset.correoSolicitante || "");
          collectSummary("Fecha", btn.dataset.creado || "");
          collectSummary("Asignado a", btn.dataset.empleado || "");
          collectSummary("Contrato", btn.dataset.contrato || "");
          collectSummary("Inmueble", btn.dataset.inmueble || "");
          collectSummary("Barrio", btn.dataset.barrio || "");
          collectSummary("Dirección", btn.dataset.direccion || "");
        } else {
          collectSummary("Contrato", btn.dataset.contrato || "");
          collectSummary("Inmueble", btn.dataset.inmueble || "");
          collectSummary(
            "Codigo inmueble web",
            btn.dataset.idInmuebleWeb || "",
          );
          collectSummary("Barrio", btn.dataset.barrio || "");
          collectSummary("Dirección", btn.dataset.direccion || "");
          collectSummary("Creado", btn.dataset.creado || "");
          collectSummary("Asignado a", btn.dataset.empleado || "");
          collectSummary("Propietario", btn.dataset.propietario || "");
          collectSummary("Arrendatario", btn.dataset.arrendatario || "");
          collectSummary("Tiempo total", btn.dataset.total || "");
          collectSummary("Etapa actual", btn.dataset.etapa || "");
          collectSummary("Tiempo en etapa", btn.dataset.etapaTiempo || "");
          collectSummary("En ejecución", btn.dataset.ejecucion || "");
          collectSummary("Sin actualizar", btn.dataset.sinActualizar || "");
        }
      }
      if (body) {
        var runtime = parseRuntime(root) || {};
        var runtimeConfig = runtime.config || {};
        var srcWrap = document.createElement("div");
        srcWrap.innerHTML = sourceHtml;
        var floatingActionWrap = srcWrap.querySelector(
          ".scm-case-action-buttons",
        );
        var seguimientoWrap = srcWrap.querySelector(".scm-seg-wrap");
        var topActionButtons = [];
        if (floatingActionWrap) {
          topActionButtons = Array.prototype.slice.call(
            floatingActionWrap.querySelectorAll("[data-scm-open-section]"),
          );
          floatingActionWrap.remove();
        }
        var timelineWrap = srcWrap.querySelector(".scm-modal-timeline-only");
        var timelineHtml = "";
        if (timelineWrap) {
          timelineHtml = timelineWrap.innerHTML || "";
          timelineWrap.remove();
        }
        modal._scmTimelineHtml = timelineHtml;
        var ticketUrl = (btn.dataset.ticketUrl || "").trim();
        if (!ticketUrl) {
          var baseTicketUrl = String(runtimeConfig.ticket_url || "").trim();
          var ticketRef = String(
            btn.dataset.ticket || btn.dataset.ticketPk || "",
          ).trim();
          if (baseTicketUrl && ticketRef) {
            ticketUrl = baseTicketUrl + encodeURIComponent(ticketRef);
          }
        }
        var cotizacionUrl = (btn.dataset.cotizacionUrl || "").trim();
        var cotizacionId = (btn.dataset.cotizacionId || "").trim();
        var cotEstado = (btn.dataset.cotEstado || "").trim().toLowerCase();
        var cotizacionSinResponder =
          cotEstado === "" || cotEstado === "esperando respuesta";
        var statusBucket = (btn.dataset.statusBucket || "").trim();
        var calendarTicketPk = String(btn.dataset.ticketPk || "").trim();
        if (seguimientoWrap) {
          seguimientoWrap.setAttribute("id", "scm-sec-seguimiento");
          seguimientoWrap.style.display = "none";
        }
        var caseActionsHtml =
          '<section class="scm-case-work-actions"><h4>' +
          (isPublicPqr ? "Acciones de la solicitud" : "Acciones del caso") +
          '</h4><div class="scm-case-work-action-list">';
        if (
          isPublicPqr &&
          card &&
          card.querySelector("[data-scm-open-pqr-transfer]")
        ) {
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-open-pqr-transfer-from-case data-ticket-pk="' +
            escHtml(btn.dataset.ticketPk || "") +
            '">Trasladar solicitud</button>';
        }
        if (isPublicPqr) {
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-open-note>Agregar nota</button>';
          if (statusBucket !== "cerrados") {
            caseActionsHtml +=
              '<button type="button" class="scm-case-work-btn" data-scm-open-postpone-ticket>Postergar solicitud</button>';
            caseActionsHtml +=
              '<button type="button" class="scm-case-work-btn" data-scm-open-ticket-response>Responder solicitud</button>';
            caseActionsHtml +=
              '<button type="button" class="scm-case-work-btn" data-scm-close-ticket>Cerrar solicitud</button>';
          }
          if (statusBucket === "postergados" || statusBucket === "cerrados") {
            caseActionsHtml +=
              '<button type="button" class="scm-case-work-btn" data-scm-activate-ticket>Activar solicitud</button>';
          }
        }
        if (!isPublicPqr && seguimientoWrap) {
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-open-section="scm-sec-seguimiento">Agregar seguimiento</button>';
        }
        if (!isPublicPqr) {
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-view-contacts>Ver contactos</button>';
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-view-property-map>Ubicaci&oacute;n del inmueble</button>';
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-edit-case-magnitude data-ticket-pk="' +
            escHtml(btn.dataset.ticketPk || "") +
            '">Editar magnitud caso</button>';
          if (calendarTicketPk) {
            caseActionsHtml +=
              '<button type="button" class="scm-case-work-btn" data-scm-calendar-create-case>Agendar cita del caso</button>';
          }
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-open-note>Agregar nota</button>';
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-open-postpone-ticket>Postergar ticket</button>';
        }
        if (!isPublicPqr && (statusBucket === "postergados" || statusBucket === "cerrados")) {
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-activate-ticket>Activar ticket</button>';
        }
        if (!isPublicPqr) {
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-open-ticket-response>Responder ticket</button>';
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-open-trasladar>Trasladar caso</button>';
        }
        if (!isPublicPqr && (cotizacionUrl || cotizacionId) && cotizacionSinResponder) {
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-open-cotizacion-response>Responder cotizaci&oacute;n</button>';
        }
        if (isPublicPqr && ticketUrl) {
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-open-iframe' +
            (isPublicPqr ? ' data-scm-compact-iframe' : '') +
            ' data-iframe-url="' +
            escHtml(ticketUrl) +
            '" data-iframe-title="Solicitud">Abrir solicitud original' +
            "</button>";
        }
        if (!isPublicPqr && cotizacionUrl) {
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-open-iframe data-iframe-url="' +
            escHtml(cotizacionUrl) +
            '" data-iframe-title="Cotizaci&oacute;n">Abrir cotizaci&oacute;n</button>';
        }
        caseActionsHtml += "</div></section>";

        var firstHistory = srcWrap.querySelector(".scm-case-history");
        if (firstHistory) {
          firstHistory.insertAdjacentHTML("beforebegin", caseActionsHtml);
        } else {
          srcWrap.insertAdjacentHTML("beforeend", caseActionsHtml);
        }
        sourceHtml = srcWrap.innerHTML;

        var sidebarHtml = '<aside class="scm-case-sidebar">';
        sidebarHtml += isPublicPqr ? "<h4>Resumen de la solicitud</h4>" : "<h4>Resumen del caso</h4>";
        sidebarHtml += '<div class="scm-case-sidebar-list">';
        summaryItems.forEach(function (item) {
          sidebarHtml +=
            '<div class="scm-case-side-item"><span class="scm-case-side-label">' +
            escHtml(item.label) +
            '</span><span class="scm-case-side-value">' +
            escHtml(item.value) +
            "</span></div>";
        });
        if (!isPublicPqr) {
          var sideMagnitude = normalizeMagnitudeKey(
            btn.dataset.magnitudCaso || "",
          );
          sidebarHtml +=
            '<div class="scm-case-side-item"><span class="scm-case-side-label">Magnitud del caso</span><span data-scm-case-magnitude-badge>' +
            renderMagnitudeBadge(sideMagnitude) +
            "</span></div>";
          sidebarHtml +=
            '<div class="scm-case-side-item scm-case-magnitude-editor">' +
            '<span class="scm-case-side-label">Editar caso</span>' +
            '<button type="button" class="btn btn-outline btn-sm scm-edit-case-magnitude" data-scm-edit-case-magnitude data-ticket-pk="' +
            escHtml(btn.dataset.ticketPk || "") +
            '">Editar magnitud caso</button>' +
            "</div>";
        }
        var tabKeySide = (btn.dataset.tabKey || "").trim();
        var consultorEntrega = (btn.dataset.consultorEntrega || "").trim();
        var consultorCelular = (
          btn.dataset.consultorEntregaCelular || ""
        ).trim();
        var consultorCorreo = (btn.dataset.consultorEntregaCorreo || "").trim();
        if (
          tabKeySide === "entrega" &&
          (consultorEntrega || consultorCelular || consultorCorreo)
        ) {
          sidebarHtml +=
            '<div class="scm-case-side-item"><span class="scm-case-side-label scm-label-section">Consultor/a de entrega</span></div>';
          if (consultorEntrega) {
            sidebarHtml +=
              '<div class="scm-case-side-item"><span class="scm-case-side-label">Nombre</span><span class="scm-case-side-value">' +
              escHtml(consultorEntrega) +
              "</span></div>";
          }
          if (consultorCelular) {
            sidebarHtml +=
              '<div class="scm-case-side-item"><span class="scm-case-side-label">Celular</span><span class="scm-case-side-value">' +
              escHtml(consultorCelular) +
              "</span></div>";
          }
          if (consultorCorreo) {
            sidebarHtml +=
              '<div class="scm-case-side-item"><span class="scm-case-side-label">Correo</span><span class="scm-case-side-value">' +
              escHtml(consultorCorreo) +
              "</span></div>";
          }
        }
        var ubicLlaves = (btn.dataset.ubicacionLlaves || "").trim();
        var personaLlaves = (btn.dataset.personaLlaves || "").trim();
        var contactoLlaves = (btn.dataset.contactoLlaves || "").trim();
        if (
          tabKeySide === "entrega" &&
          (ubicLlaves || personaLlaves || contactoLlaves)
        ) {
          sidebarHtml +=
            '<div class="scm-case-side-item">' +
            '<span class="scm-case-side-label">Llaves</span>' +
            '<button type="button" class="btn btn-outline btn-sm" data-scm-open-llaves>Ver llaves</button>' +
            "</div>";
        }
        sidebarHtml += "</div></aside>";
        if (headActions) {
          headActions.innerHTML = "";
          if (hasPerturbacionValue((btn.dataset.perturbacion || "").trim())) {
            headActions.innerHTML +=
              '<button type="button" class="scm-case-side-link" data-scm-open-perturbacion>Ver perturbaci&oacute;n</button>';
          }
          if ((btn.dataset.idRevisionCorrectiva || "").trim()) {
            headActions.innerHTML +=
              '<button type="button" class="scm-case-side-link" data-scm-open-damage="correctiva">Magnitud da&ntilde;os correctiva</button>';
          }
          if ((btn.dataset.idRevisionPreventiva || "").trim()) {
            headActions.innerHTML +=
              '<button type="button" class="scm-case-side-link" data-scm-open-damage="preventiva">Magnitud da&ntilde;os preventiva</button>';
          }
          topActionButtons.forEach(function (rawBtn) {
            var sectionId = rawBtn.getAttribute("data-scm-open-section") || "";
            var label = (rawBtn.textContent || "").trim() || "Ver detalle";
            if (!sectionId) {
              return;
            }
            headActions.innerHTML +=
              '<button type="button" class="scm-case-side-link" data-scm-open-section="' +
              escHtml(sectionId) +
              '">' +
              escHtml(label) +
              "</button>";
          });
          if (timelineHtml) {
            headActions.innerHTML +=
              '<button type="button" class="scm-case-side-link scm-case-timeline-head-btn" data-scm-open-timeline>Ver l&iacute;nea de tiempo</button>';
          }
          var tabKeyHead = (btn.dataset.tabKey || "").trim();
          var idEstudioHead = (btn.dataset.idEstudioAseguradora || "").trim();
          var anexosHead = (btn.dataset.anexosEntrega || "").trim();
          var ubicLlavesHead = (btn.dataset.ubicacionLlaves || "").trim();
          var personaLlavesHead = (btn.dataset.personaLlaves || "").trim();
          var contactoLlavesHead = (btn.dataset.contactoLlaves || "").trim();
          if (tabKeyHead === "entrega" && idEstudioHead) {
            headActions.innerHTML +=
              '<a href="https://sucasainmobiliaria.com.co/estudio-aseguradora/?id_estudio=' +
              encodeURIComponent(idEstudioHead) +
              '" class="scm-case-side-link" target="_blank" rel="noopener">Ver asegurable</a>';
          }
          if (tabKeyHead === "entrega" && anexosHead) {
            headActions.innerHTML +=
              '<a href="' +
              escHtml(anexosHead) +
              '" class="scm-case-side-link" target="_blank" rel="noopener">Ver documentos</a>';
          }
          if (
            tabKeyHead === "entrega" &&
            (ubicLlavesHead || personaLlavesHead || contactoLlavesHead)
          ) {
            headActions.innerHTML +=
              '<button type="button" class="scm-case-side-link" data-scm-open-llaves>Ver llaves</button>';
          }
        }

        body.innerHTML =
          '<div class="scm-case-layout">' +
          sidebarHtml +
          '<section class="scm-case-main">' +
          sourceHtml +
          "</section></div>";
        initCotizacionResponseFields(body);
      }

      modal.classList.add("open");
      modal.setAttribute("aria-hidden", "false");
      document.body.classList.add("scm-modal-open");

      var closeBtn = modal.querySelector(".scm-case-close");
      if (closeBtn) {
        closeBtn.focus();
      }

      modal
        .querySelectorAll("[data-scm-open-section]")
        .forEach(function (scrollBtn) {
          scrollBtn.addEventListener("click", function () {
            var targetId =
              scrollBtn.getAttribute("data-scm-open-section") || "";
            if (!targetId) {
              return;
            }
            openCaseSubmodal(modal, scrollBtn, targetId);
          });
        });

      modal
        .querySelectorAll("[data-scm-open-timeline]")
        .forEach(function (timelineBtn) {
          timelineBtn.addEventListener("click", function () {
            openCaseTimelineSubmodal(modal, btn);
          });
        });

      modal
        .querySelectorAll("[data-scm-open-damage]")
        .forEach(function (damageBtn) {
          damageBtn.addEventListener("click", function () {
            openCaseDamageSubmodal(
              modal,
              damageBtn,
              root,
              btn,
              damageBtn.getAttribute("data-scm-open-damage") || "correctiva",
            );
          });
        });

      modal
        .querySelectorAll("[data-scm-scroll-target]")
        .forEach(function (scrollBtn) {
          scrollBtn.addEventListener("click", function () {
            var targetId =
              scrollBtn.getAttribute("data-scm-scroll-target") || "";
            if (!targetId) {
              return;
            }
            var target = modal.querySelector("#" + targetId);
            if (!target || !target.scrollIntoView) {
              return;
            }
            target.scrollIntoView({ behavior: "smooth", block: "start" });
          });
        });

      modal
        .querySelectorAll("[data-scm-edit-case-magnitude]")
        .forEach(function (editBtn) {
          editBtn.addEventListener("click", function () {
            openCaseMagnitudeEditor(modal, root, btn);
          });
        });

      modal
        .querySelectorAll("[data-scm-open-note]")
        .forEach(function (noteBtn) {
          noteBtn.addEventListener("click", function () {
            openCaseNoteEditor(modal, btn);
          });
        });

      modal
        .querySelectorAll("[data-scm-open-postpone-ticket]")
        .forEach(function (postponeBtn) {
          postponeBtn.addEventListener("click", function () {
            openPostponeTicketEditor(modal, btn);
          });
        });

      modal
        .querySelectorAll("[data-scm-activate-ticket]")
        .forEach(function (activateBtn) {
          activateBtn.addEventListener("click", function (event) {
            event.preventDefault();
          });
        });

      modal
        .querySelectorAll("[data-scm-open-trasladar]")
        .forEach(function (trasladarBtn) {
          trasladarBtn.addEventListener("click", function () {
            var root = findRootFromNode(trasladarBtn);
            var rt = root ? parseRuntime(root) || {} : {};
            openTrasladarCasoEditor(modal, btn, rt);
          });
        });

      modal
        .querySelectorAll("[data-scm-open-contacts]")
        .forEach(function (contactsBtn) {
          contactsBtn.addEventListener("click", function () {
            openContactEditor(modal, btn);
          });
        });

      modal
        .querySelectorAll("[data-scm-view-contacts]")
        .forEach(function (contactsBtn) {
          contactsBtn.addEventListener("click", function () {
            openContactViewer(modal, btn);
          });
        });

      modal
        .querySelectorAll("[data-scm-view-property-map]")
        .forEach(function (mapBtn) {
          mapBtn.addEventListener("click", function () {
            openPropertyMapViewer(modal, btn);
          });
        });

      modal
        .querySelectorAll("[data-scm-calendar-create-case]")
        .forEach(function (calendarBtn) {
          calendarBtn.addEventListener("click", function () {
            openCalendarCaseEventEditor(modal, btn);
          });
        });

      modal
        .querySelectorAll("[data-scm-open-ticket-response]")
        .forEach(function (responseBtn) {
          responseBtn.addEventListener("click", function () {
            openTicketResponseEditor(modal, btn);
          });
        });

      modal
        .querySelectorAll("[data-scm-open-cotizacion-response]")
        .forEach(function (responseBtn) {
          responseBtn.addEventListener("click", function () {
            openCotizacionResponseEditor(modal, btn);
          });
        });
      modal
        .querySelectorAll("[data-scm-open-perturbacion]")
        .forEach(function (pb) {
          pb.addEventListener("click", function () {
            openPerturbacionDetail(modal, btn);
          });
        });

      modal.querySelectorAll("[data-scm-open-llaves]").forEach(function (lb) {
        lb.addEventListener("click", function () {
          openLlavesDetail(modal, btn);
        });
      });

      modal
        .querySelectorAll("[data-scm-close-ticket]")
        .forEach(function (closeBtn) {
          closeBtn.addEventListener("click", function () {
            openCloseTicketEditor(modal, btn);
          });
        });

      modal
        .querySelectorAll("[data-scm-open-iframe]")
        .forEach(function (iframeBtn) {
          iframeBtn.addEventListener("click", function () {
            openIframeModal(
              iframeBtn.dataset.iframeUrl || "",
              iframeBtn.dataset.iframeTitle || "",
              iframeBtn.hasAttribute("data-scm-compact-iframe"),
            );
          });
        });
    } catch (err) {
      console.error("SCM open case error:", err);
      closeCaseModal(modal);
    }
  };

  document.addEventListener("paste", function (event) {
    var zone =
      event.target && event.target.closest
        ? event.target.closest("[data-scm-paste-evidence]")
        : null;
    if (!zone) {
      return;
    }
    var clipboard = event.clipboardData || window.clipboardData;
    var items = clipboard && clipboard.items ? clipboard.items : [];
    var files = [];
    for (var i = 0; i < items.length; i++) {
      if (items[i] && /^image\//i.test(items[i].type || "")) {
        var file = items[i].getAsFile();
        if (file) {
          var ext = (file.type || "image/png").split("/").pop() || "png";
          files.push(
            new File([file], "captura-pegada-" + Date.now() + "-" + i + "." + ext, {
              type: file.type || "image/png",
            }),
          );
        }
      }
    }
    if (!files.length) {
      zone.classList.add("is-error");
      var noImageList = zone.querySelector("[data-scm-paste-list]");
      if (noImageList) {
        noImageList.innerHTML = "<li>No se encontro una imagen en el portapapeles.</li>";
      }
      return;
    }
    var form = zone.closest("form");
    var inputName = zone.getAttribute("data-file-input-name") || "evidencia[]";
    var input = form ? form.querySelector('input[type="file"][name="' + inputName + '"]') : null;
    if (!input || typeof DataTransfer === "undefined") {
      zone.classList.add("is-error");
      var unsupportedList = zone.querySelector("[data-scm-paste-list]");
      if (unsupportedList) {
        unsupportedList.innerHTML = "<li>Tu navegador no permitio adjuntar la captura pegada.</li>";
      }
      return;
    }
    var transfer = new DataTransfer();
    Array.prototype.forEach.call(input.files || [], function (file) {
      transfer.items.add(file);
    });
    files.forEach(function (file) {
      transfer.items.add(file);
    });
    input.files = transfer.files;
    zone.classList.remove("is-error");
    zone.classList.add("has-files");
    var list = zone.querySelector("[data-scm-paste-list]");
    if (list) {
      list.innerHTML = "";
      Array.prototype.forEach.call(input.files || [], function (file) {
        var item = document.createElement("li");
        item.textContent = file.name;
        list.appendChild(item);
      });
    }
    event.preventDefault();
  });

  document.addEventListener("change", function (event) {
    var target = event.target;
    if (
      !target ||
      !target.matches ||
      !target.matches('select[name="estado_cotizacion"], select[name="estado"]')
    ) {
      return;
    }
    var box = target.closest("[data-scm-cotizacion-response-fields]");
    if (box) {
      syncCotizacionResponseBox(box);
    }
  });

  function notifyCalendarAppointment(root, appointments) {
    var runtime = parseRuntime(root) || {};
    var action =
      (runtime.actions && runtime.actions.calendar_cita_notify) ||
      "scm_calendar_cita_notificar";
    if (!Array.isArray(appointments) || !appointments.length) {
      return Promise.resolve({ queued: 0, skipped: 0 });
    }
    var fd = new FormData();
    fd.set("action", action);
    fd.set("nonce", runtime.nonce || "");
    fd.set("appointments", JSON.stringify(appointments));

    return fetch(runtime.ajaxUrl || "api.php", {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error(
            (json && json.data && json.data.message) ||
              "No se pudieron encolar las notificaciones de la cita.",
          );
        }
        return json.data || {};
      })
      .catch(function (error) {
        console.warn("SCM calendar cita notify:", error);
        return { queued: 0, skipped: appointments.length, error: error.message || "" };
      });
  }

  function showCalendarCitaNotificationResult(result, msg) {
    result = result || {};
    var queued = Number(result.queued || 0);
    var skipped = Number(result.skipped || 0);
    var error = String(result.error || "").trim();
    var errors = Array.isArray(result.errors) ? result.errors.filter(Boolean) : [];
    if (queued > 0) {
      var okText = queued + " notificación" + (queued === 1 ? "" : "es") + " WhatsApp encolada" + (queued === 1 ? "" : "s") + ".";
      if (msg) msg.textContent = "Evento creado. " + okText;
      scmNotify("success", okText, "WhatsApp");
      return;
    }
    if (error || errors.length) {
      var errorText = error || errors[0] || "No se pudieron encolar las notificaciones WhatsApp.";
      if (msg) {
        msg.textContent = "Evento creado, pero WhatsApp no se encoló: " + errorText;
        msg.classList.add("error");
      }
      scmNotify("error", errorText, "WhatsApp");
      return;
    }
    if (skipped > 0) {
      var skippedText = "Evento creado, pero no se encoló WhatsApp. Revisa celular del ticket o funcionario.";
      if (msg) msg.textContent = skippedText;
      scmNotify("warning", skippedText, "WhatsApp");
    }
  }

  window.SCMAdminCore = {
    parseRuntime: parseRuntime,
    escHtml: escHtml,
    scmNotify: scmNotify,
    bindTabs: bindTabs,
    findRootFromNode: findRootFromNode,
    getCaseModal: getCaseModal,
    openIframeModal: openIframeModal,
    closeCaseModal: closeCaseModal,
    openPropertyLocationEditor: openPropertyLocationEditor,
    openPropertyLocationStandaloneEditor: openPropertyLocationStandaloneEditor,
    renderTicketDocumentRow: renderTicketDocumentRow,
    renderTicketDocumentFields: renderTicketDocumentFields,
    renderPasteEvidenceBox: renderPasteEvidenceBox,
    renderNotifyTargets: renderNotifyTargets,
    notifyCalendarAppointment: notifyCalendarAppointment,
    showCalendarCitaNotificationResult: showCalendarCitaNotificationResult,
    getLlavesDetailPayload: getLlavesDetailPayload,
    getConsultorEntregaDetailPayload: getConsultorEntregaDetailPayload,
    openStandaloneDetail: openStandaloneDetail
  };
})();
