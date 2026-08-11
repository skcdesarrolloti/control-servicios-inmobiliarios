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

  function openIframeModal(url, title) {
    if (!url) {
      return;
    }
    var overlay = document.createElement("div");
    overlay.className = "scm-iframe-overlay";
    overlay.innerHTML =
      '<div class="scm-iframe-box">' +
      '<div class="scm-iframe-toolbar">' +
      '<span class="scm-iframe-toolbar-title">' +
      escHtml(title) +
      "</span>" +
      '<button type="button" class="scm-iframe-close" aria-label="Cerrar">&times;</button>' +
      "</div>" +
      '<div class="scm-iframe-loader"><div class="scm-iframe-spinner"></div></div>' +
      '<iframe class="scm-iframe-frame" src="" allowfullscreen></iframe>' +
      "</div>";
    document.body.appendChild(overlay);
    var iframeEl = overlay.querySelector(".scm-iframe-frame");
    var loaderEl = overlay.querySelector(".scm-iframe-loader");
    iframeEl.addEventListener("load", function () {
      if (loaderEl) {
        loaderEl.style.display = "none";
      }
    });
    iframeEl.src = url;
    function destroyOverlay() {
      overlay.removeEventListener("click", onOverlayClick);
      document.removeEventListener("keydown", onKeyDown);
      if (overlay.parentNode) {
        overlay.parentNode.removeChild(overlay);
      }
    }
    function onOverlayClick(e) {
      if (e.target === overlay) {
        destroyOverlay();
      }
    }
    function onKeyDown(e) {
      if (e.key === "Escape") {
        destroyOverlay();
      }
    }
    overlay.addEventListener("click", onOverlayClick);
    overlay
      .querySelector(".scm-iframe-close")
      .addEventListener("click", destroyOverlay);
    document.addEventListener("keydown", onKeyDown);
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

    wrap.addEventListener("click", function (e) {
      if (e.target === wrap) {
        closeSub();
      }
    });
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
    if (!container) {
      return;
    }
    var existing = container.querySelector(".scm-case-location-panel");
    if (existing) {
      existing.remove();
    }
    container.insertAdjacentHTML(
      "afterbegin",
      renderCaseLocationPanel(caseBtn, fallbackNode, true),
    );
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
              root.dispatchEvent(new CustomEvent("scm:refresh-active-tab"));
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

    if (title) {
      title.textContent = "Agregar nota al ticket";
    }
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<form class="scm-note-form" method="post" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<label class="scm-seg-field"><span>Nota</span><textarea name="observacion" rows="6" required placeholder="Escribe una nota interna para el ticket..."></textarea></label>' +
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

    if (title) {
      title.textContent = "Postergar ticket";
    }
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<form class="scm-postpone-ticket-form" method="post" enctype="multipart/form-data" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<p class="scm-muted">Esta acci&oacute;n mantendr&aacute; el ticket abierto y marcar&aacute; el estado administrativo como Postergado.</p>' +
        '<label class="scm-seg-field"><span>Motivo de postergaci&oacute;n</span><textarea name="observacion" rows="6" required placeholder="Describe por qu&eacute; se posterga el ticket..."></textarea></label>' +
        '<label class="scm-seg-field"><span>Imagenes / Evidencias (opcional)</span><input type="file" name="evidencia[]" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif,image/tiff" multiple></label>' +
        renderTicketDocumentFields() +
        renderNotifyTargets() +
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

  function renderTicketDocumentFields() {
    return (
      '<div class="scm-ticket-documents" data-ticket-documents>' +
      renderTicketDocumentRow() +
      "</div>" +
      '<button type="button" class="btn btn-outline btn-sm scm-add-ticket-document" data-add-ticket-document>Agregar otro documento</button>'
    );
  }

  function openTicketResponseEditor(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var ticketPk = caseBtn.dataset.ticketPk || "";
    if (title) title.textContent = "Responder ticket";
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<form class="scm-ticket-response-form" method="post" enctype="multipart/form-data" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<label class="scm-seg-field"><span>Estado administrativo</span><select name="estado_administrativo">' +
        '<option value="__keep__">Sin cambio</option><option value="Nuevo">Nuevo</option><option value="Por inspecccionar">Por inspecccionar</option><option value="Inspeccionado">Inspeccionado</option><option value="Cotizado">Cotizado</option><option value="En ejecucion">En ejecucion</option><option value="Finalizado">Finalizado</option><option value="Trasladado">Trasladado</option><option value="Entregado">Entregado</option><option value="Recibido">Recibido</option><option value="Desistido">Desistido</option>' +
        "</select></label>" +
        '<label class="scm-seg-field"><span>Respuesta</span><textarea name="respuesta" rows="7" required placeholder="Escribe la respuesta que se enviara al solicitante..."></textarea></label>' +
        '<label class="scm-seg-field"><span>Imagenes (opcional)</span><input type="file" name="imagen[]" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif,image/tiff" multiple></label>' +
        renderTicketDocumentFields() +
        renderNotifyTargets() +
        '<div class="scm-seg-actions"><label class="scm-seg-check"><input type="checkbox" name="cerrar_ticket" value="1"> Cerrar al responder</label><button type="submit" class="scm-btn-primary">Publicar y enviar correo</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";
      prependCaseLocationPanel(body, caseBtn, modal);
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
        '<label class="scm-seg-field"><span>Respuesta</span><select name="estado" required><option value="">Elige una respuesta</option><option value="Aprobada">Aprobada</option><option value="Desaprobada">Desaprobada</option></select></label>' +
        '<label class="scm-seg-field scm-cotizacion-motivo" style="display:none;"><span>Motivo</span><select name="motivo"><option value="">Elige un motivo</option><option value="Por costo">Por costo</option><option value="Ejecucción por cuenta propia">Ejecucción por cuenta propia</option></select></label>' +
        '<label class="scm-seg-field scm-cotizacion-financiacion" style="display:none;"><span>Financiacion</span><select name="financiacion"><option value="">No aplica / sin respuesta</option><option value="Si">Si</option><option value="No">No</option></select></label>' +
        '<label class="scm-seg-field"><span>Observaciones</span><textarea name="observacion" rows="6" placeholder="Ninguna">Ninguna</textarea></label>' +
        renderNotifyTargets() +
        '<div class="scm-seg-actions"><button type="submit" class="scm-btn-primary">Guardar y enviar correo</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";
      prependCaseLocationPanel(body, caseBtn, modal);
      var estado = body.querySelector('select[name="estado"]');
      var motivoWrap = body.querySelector(".scm-cotizacion-motivo");
      var motivoInput = body.querySelector('select[name="motivo"]');
      var financiacionWrap = body.querySelector(".scm-cotizacion-financiacion");
      var financiacionInput = body.querySelector('select[name="financiacion"]');
      if (
        estado &&
        motivoWrap &&
        motivoInput &&
        financiacionWrap &&
        financiacionInput
      ) {
        estado.addEventListener("change", function () {
          var showMotivo = estado.value === "Desaprobada";
          var showFinanciacion = estado.value === "Aprobada";
          motivoWrap.style.display = showMotivo ? "" : "none";
          motivoInput.required = showMotivo;
          if (!showMotivo) motivoInput.value = "";
          financiacionWrap.style.display = showFinanciacion ? "" : "none";
          if (!showFinanciacion) financiacionInput.value = "";
        });
      }
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
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

    wrap.addEventListener("click", function (e) {
      if (e.target === wrap) {
        closeStandaloneDetail();
      }
    });
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
    if (title) title.textContent = "Cerrar ticket";
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<form class="scm-close-ticket-form" method="post" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<p class="scm-muted">Esta acci&oacute;n cerrar&aacute; el ticket y marcar&aacute; el estado administrativo como Finalizado.</p>' +
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
          "Caso #" + (btn.dataset.ticketPk || btn.dataset.ticket || "-");
      }
      if (subtitle) {
        subtitle.textContent =
          btn.dataset.asunto || "Ticket de servicios inmobiliarios";
      }
      modal.dataset.ticketPk = btn.dataset.ticketPk || "";
      modal.dataset.idInmuebleWeb = btn.dataset.idInmuebleWeb || "";
      modal.dataset.idInmuebleData = btn.dataset.idInmuebleData || "";
      modal.dataset.ubicacionGoogleMaps = btn.dataset.ubicacionGoogleMaps || "";
      modal.dataset.direccion = btn.dataset.direccion || "";
      if (meta) {
        meta.innerHTML = "";
        collectSummary("Estado", btn.dataset.estado || "");
        collectSummary("Estado administrativo", btn.dataset.admin || "");
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
        var cotEstado = (btn.dataset.cotEstado || "").trim().toLowerCase();
        var cotizacionSinResponder =
          cotEstado === "" || cotEstado === "esperando respuesta";
        var statusBucket = (btn.dataset.statusBucket || "").trim();
        if (seguimientoWrap) {
          seguimientoWrap.setAttribute("id", "scm-sec-seguimiento");
          seguimientoWrap.style.display = "none";
        }
        var caseActionsHtml =
          '<section class="scm-case-work-actions"><h4>Acciones del caso</h4><div class="scm-case-work-action-list">';
        if (seguimientoWrap) {
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-open-section="scm-sec-seguimiento">Agregar seguimiento</button>';
        }
        caseActionsHtml +=
          '<button type="button" class="scm-case-work-btn" data-scm-open-contacts>Editar contactos</button>';
        caseActionsHtml +=
          '<button type="button" class="scm-case-work-btn" data-scm-open-note>Agregar nota</button>';
        caseActionsHtml +=
          '<button type="button" class="scm-case-work-btn" data-scm-open-postpone-ticket>Postergar ticket</button>';
        if (statusBucket === "postergados" || statusBucket === "cerrados") {
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-activate-ticket>Activar ticket</button>';
        }
        caseActionsHtml +=
          '<button type="button" class="scm-case-work-btn" data-scm-open-ticket-response>Responder ticket / enviar correo</button>';
        caseActionsHtml +=
          '<button type="button" class="scm-case-work-btn" data-scm-open-trasladar>Trasladar caso</button>';
        if (cotizacionUrl && cotizacionSinResponder) {
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-open-cotizacion-response>Responder cotizaci&oacute;n / enviar correo</button>';
        }
        if (ticketUrl) {
          caseActionsHtml +=
            '<button type="button" class="scm-case-work-btn" data-scm-open-iframe data-iframe-url="' +
            escHtml(ticketUrl) +
            '" data-iframe-title="Ticket">Abrir ticket</button>';
        }
        if (cotizacionUrl) {
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
        sidebarHtml += "<h4>Resumen del caso</h4>";
        sidebarHtml += '<div class="scm-case-sidebar-list">';
        summaryItems.forEach(function (item) {
          sidebarHtml +=
            '<div class="scm-case-side-item"><span class="scm-case-side-label">' +
            escHtml(item.label) +
            '</span><span class="scm-case-side-value">' +
            escHtml(item.value) +
            "</span></div>";
        });
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
        sidebarHtml += renderCaseLocationPanel(btn, modal, false);
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
            event.stopPropagation();
            openActivateTicketPrompt(btn, activateBtn);
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
            );
          });
        });
    } catch (err) {
      console.error("SCM open case error:", err);
      closeCaseModal(modal);
    }
  };

  function initRoot(root) {
    if (!root || root.dataset.scmInit === "1") {
      return;
    }

    var runtime = parseRuntime(root);
    if (!runtime) {
      return;
    }

    root.dataset.scmInit = "1";

    bindTabs(root, runtime);

    // ── Guide modal (new tabbed version) ────────────────────────────
    var guideBtn = root.querySelector("#scm-open-guide");
    var guideModal = document.getElementById("scm-guide-modal");
    var guideClose = document.getElementById("scm-close-guide");

    function openGuideModal() {
      if (!guideModal) return;
      guideModal.classList.add("open");
      guideModal.setAttribute("aria-hidden", "false");
    }
    function closeGuideModal() {
      if (!guideModal) return;
      guideModal.classList.remove("open");
      guideModal.setAttribute("aria-hidden", "true");
    }

    if (guideBtn) {
      guideBtn.addEventListener("click", function () {
        openGuideModal();
        // Load active CRUD pane on first open
        var activePane = guideModal.querySelector(".scm-go-pane.active");
        if (activePane) {
          var paneId = activePane.id;
          if (paneId === "scm-go-pane-correspondencias") scmGoLoad("gcd");
          else if (paneId === "scm-go-pane-respuestas") scmGoLoad("grt");
          else if (paneId === "scm-go-pane-articulos") scmGoLoad("gac");
        }
      });
    }
    if (guideClose) {
      guideClose.addEventListener("click", closeGuideModal);
    }
    if (guideModal) {
      guideModal.addEventListener("click", function (e) {
        if (e.target === guideModal) closeGuideModal();
      });
    }

    var caseModal = getCaseModal(root);
    if (caseModal) {
      caseModal.addEventListener("click", function (e) {
        if (e.target === caseModal) {
          closeCaseModal(caseModal);
        }
      });
    }

    document.addEventListener("keydown", function (e) {
      if (e.key !== "Escape") {
        return;
      }
      var adminTicketModal = root.querySelector("#scm-admin-ticket-modal.open");
      if (adminTicketModal) {
        closeAdminTicketModal();
        return;
      }
      var openModal = root.querySelector("#scm-case-modal.open");
      if (!openModal) {
        return;
      }
      var sub = openModal.querySelector(".scm-case-submodal.open");
      if (sub) {
        sub.classList.remove("open");
        sub.setAttribute("aria-hidden", "true");
        return;
      }
      closeCaseModal(openModal);
    });

    var ajaxUrl = runtime.ajaxUrl || "";
    var nonce = runtime.nonce || "";
    var config = runtime.config || {};
    var actions = runtime.actions || {};

    var actionMant = actions.mant || "";
    var actionSeg = actions.seg || "";
    var actionNote = actions.nota || "";
    var actionTicketResponse = actions.ticket_response || "";
    var actionCotizacionResponse = actions.cotizacion_response || "";
    var actionPostponeTicket = actions.postpone_ticket || "";
    var actionStatusTickets = actions.status_tickets || "";
    var actionActivateTicket = actions.activate_ticket || "";
    var actionCloseTicket = actions.close_ticket || "";
    var actionContactsUpdate = actions.contacts_update || "";
    var actionSavePropertyLocation = actions.save_property_location || "";
    var actionTrasladarCaso = actions.trasladar_caso || "";
    var actionContratosArrendamiento = actions.contratos_arrendamiento || "";
    var actionContratosArrendamientoFallback =
      actions.preventivas_pendientes || "";
    var actionCrearTicketAdministrativo =
      actions.crear_ticket_administrativo || "";

    function showToast(type, message) {
      scmNotify(type, message);
    }

    function ticketAdminDatalist(id, values) {
      return (
        '<datalist id="' +
        escHtml(id) +
        '">' +
        values
          .map(function (value) {
            return '<option value="' + escHtml(value) + '"></option>';
          })
          .join("") +
        "</datalist>"
      );
    }

    function ticketAdminSelectOptions(values, selected, placeholder) {
      selected = String(selected || "");
      var html = placeholder
        ? '<option value="">' + escHtml(placeholder) + "</option>"
        : "";
      values.forEach(function (value) {
        html +=
          '<option value="' +
          escHtml(value) +
          '"' +
          (value === selected ? " selected" : "") +
          ">" +
          escHtml(value) +
          "</option>";
      });
      return html;
    }

    function adminTicketEmployeeOptions(selectedId) {
      var funcionarios =
        runtime && Array.isArray(runtime.funcionarios)
          ? runtime.funcionarios
          : [];
      var html = '<option value="">Seleccionar responsable</option>';
      funcionarios.forEach(function (func) {
        var id = String((func && func.id) || "").trim();
        var label = String((func && func.label) || id).trim();
        if (!id) return;
        html +=
          '<option value="' +
          escHtml(id) +
          '"' +
          (id === selectedId ? " selected" : "") +
          ">" +
          escHtml(label) +
          "</option>";
      });
      return html;
    }

    function ensureAdminTicketModal() {
      var modal = root.querySelector("#scm-admin-ticket-modal");
      if (modal) {
        return modal;
      }
      modal = document.createElement("div");
      modal.id = "scm-admin-ticket-modal";
      modal.className = "scm-admin-ticket-modal";
      modal.setAttribute("aria-hidden", "true");
      modal.innerHTML =
        '<div class="scm-admin-ticket-dialog" role="dialog" aria-modal="true" aria-labelledby="scm-admin-ticket-title">' +
        '<div class="scm-case-submodal-head">' +
        '<div><h4 class="scm-case-submodal-title" id="scm-admin-ticket-title">Crear ticket</h4><p class="scm-case-submodal-meta">Ticket administrativo desde contrato</p></div>' +
        '<button type="button" class="scm-case-submodal-close" data-admin-ticket-close aria-label="Cerrar">&times;</button>' +
        "</div>" +
        '<div class="scm-admin-ticket-body"></div>' +
        "</div>";
      root.appendChild(modal);
      modal.addEventListener("click", function (e) {
        if (e.target === modal || e.target.closest("[data-admin-ticket-close]")) {
          closeAdminTicketModal();
        }
      });
      return modal;
    }

    function closeAdminTicketModal() {
      var modal = root.querySelector("#scm-admin-ticket-modal");
      if (!modal) return;
      modal.classList.remove("open");
      modal.setAttribute("aria-hidden", "true");
      document.body.classList.remove("scm-modal-open");
    }

    function contractDataset(btn, key) {
      return String((btn && btn.dataset && btn.dataset[key]) || "").trim();
    }

    function openAdminTicketModal(btn) {
      if (!btn) return;
      if (!actionCrearTicketAdministrativo) {
        showToast("error", "Accion de crear ticket no configurada.");
        return;
      }
      var mode = contractDataset(btn, "ticketMode") || "administrativo";
      var isPreventiva = mode === "preventiva";
      var modal = ensureAdminTicketModal();
      var title = modal.querySelector(".scm-case-submodal-title");
      var meta = modal.querySelector(".scm-case-submodal-meta");
      var body = modal.querySelector(".scm-admin-ticket-body");
      var contractCode = contractDataset(btn, "contractCode");
      var inmueble = contractDataset(btn, "inmueble");
      var direccion = contractDataset(btn, "direccion");
      var defaultEmpleado = contractDataset(btn, "defaultEmpleadoId");
      var defaultTema = isPreventiva ? "Revision preventiva" : "";
      var defaultDepartamento = isPreventiva ? "Servicio al arrendatario" : "";
      var defaultPrioridad = isPreventiva ? "Prioridad urgente" : "";
      var defaultAsunto = isPreventiva ? "REVISION PREVENTIVA" : "";
      var defaultDescripcion = isPreventiva
        ? "Espero que se encuentre bien. Se llevara acabo un revision preventiva programada segun la fecha de inicio del contrato de arrendamiento. En este ticket se documentara todo el proceso realizado."
        : "";

      if (title) {
        title.textContent = contractDataset(btn, "ticketTitle") || "Crear ticket";
      }
      if (meta) {
        meta.textContent =
          "Contrato " +
          (contractCode || contractDataset(btn, "contractPk") || "-") +
          (inmueble ? " | Inmueble " + inmueble : "");
      }
      if (!body) return;

      var deptOptions = [
        "Servicio al cliente",
        "Servicio al propietario",
        "Servicio al arrendatario",
        "Servicio a la copropiedad",
      ];
      var topicOptions = [
        "Mantenimiento",
        "Revision preventiva",
        "Entrega de inmuebles",
        "Recibo de inmuebles",
        "Procesos juridicos",
        "Solicitud contractual",
        "Solicitud de servicios publicos",
        "Retencion de contrato",
        "Otros servicios",
        "Contable y tributaria",
        "Certificaciones tributarias",
      ];
      var priorityOptions = [
        "Prioridad urgente",
        "Prioridad alta",
        "Prioridad media",
        "Prioridad baja",
      ];
      var solicitanteField = isPreventiva
        ? '<input type="hidden" name="solicitante_tipo" value="arrendatario">' +
          '<div class="scm-admin-ticket-fixed"><span>Solicitante</span><strong>Arrendatario</strong></div>'
        : '<label class="scm-seg-field"><span>Solicitante</span><select name="solicitante_tipo"><option value="arrendatario">Arrendatario</option><option value="propietario" selected>Propietario</option></select></label>';

      body.innerHTML =
        '<form class="scm-admin-ticket-form" method="post" enctype="multipart/form-data" autocomplete="off">' +
        '<input type="hidden" name="ticket_mode" value="' +
        escHtml(mode) +
        '">' +
        '<input type="hidden" name="contract_pk" value="' +
        escHtml(contractDataset(btn, "contractPk")) +
        '">' +
        '<input type="hidden" name="id_contrato" value="' +
        escHtml(contractDataset(btn, "contractPk")) +
        '">' +
        '<input type="hidden" name="id_inmueble" value="' +
        escHtml(contractDataset(btn, "idInmueble")) +
        '">' +
        '<input type="hidden" name="id_arrendatario" value="' +
        escHtml(contractDataset(btn, "idArrendatario")) +
        '">' +
        '<input type="hidden" name="id_propietario" value="' +
        escHtml(contractDataset(btn, "idPropietario")) +
        '">' +
        '<input type="hidden" name="id_sucursal" value="' +
        escHtml(contractDataset(btn, "idSucursal")) +
        '">' +
        '<input type="hidden" name="id_inventario" value="' +
        escHtml(contractDataset(btn, "idInventario")) +
        '">' +
        '<input type="hidden" name="fecha_final_contrato" value="' +
        escHtml(contractDataset(btn, "fechaFinalContrato")) +
        '">' +
        '<div class="scm-admin-ticket-summary">' +
        '<span><b>Contrato:</b> ' +
        escHtml(contractCode || "-") +
        "</span>" +
        '<span><b>Inmueble:</b> ' +
        escHtml(inmueble || "-") +
        "</span>" +
        '<span><b>Direccion:</b> ' +
        escHtml(direccion || "-") +
        "</span>" +
        "</div>" +
        '<section class="scm-admin-ticket-section">' +
        '<h5 class="scm-admin-ticket-section-title">Informacion del ticket</h5>' +
        '<div class="scm-admin-ticket-grid">' +
        '<label class="scm-seg-field"><span>Responsable</span><select name="id_empleado" required>' +
        adminTicketEmployeeOptions(defaultEmpleado) +
        "</select></label>" +
        '<label class="scm-seg-field"><span>Prioridad</span><select name="prioridad" required>' +
        ticketAdminSelectOptions(priorityOptions, defaultPrioridad, "Selecciona prioridad") +
        "</select></label>" +
        '<label class="scm-seg-field"><span>Departamento</span><select name="departamento" required>' +
        ticketAdminSelectOptions(deptOptions, defaultDepartamento, "Selecciona departamento") +
        "</select></label>" +
        '<label class="scm-seg-field"><span>Tema de ayuda</span><select name="tema_ayuda" required>' +
        ticketAdminSelectOptions(topicOptions, defaultTema, "Selecciona tema") +
        "</select></label>" +
        solicitanteField +
        "</div>" +
        "</section>" +
        '<section class="scm-admin-ticket-section">' +
        '<h5 class="scm-admin-ticket-section-title">Inmueble y contrato</h5>' +
        '<div class="scm-admin-ticket-grid">' +
        '<label class="scm-seg-field"><span>Contrato</span><input name="contrato" value="' +
        escHtml(contractCode) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Inmueble</span><input name="inmueble" value="' +
        escHtml(inmueble) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Direccion</span><input name="direccion" value="' +
        escHtml(direccion) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Barrio</span><input name="barrio" value="' +
        escHtml(contractDataset(btn, "barrio")) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Registro fotografico</span><input name="registro_fotografico" value="' +
        escHtml(contractDataset(btn, "registroFotografico")) +
        '"></label>' +
        "</div>" +
        "</section>" +
        '<section class="scm-admin-ticket-section">' +
        '<h5 class="scm-admin-ticket-section-title">Propietario</h5>' +
        '<div class="scm-admin-ticket-grid scm-admin-ticket-grid--contact">' +
        '<label class="scm-seg-field"><span>Propietario</span><input name="propietario" value="' +
        escHtml(contractDataset(btn, "propietario")) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Correo propietario</span><input name="correo_propietario" type="email" value="' +
        escHtml(contractDataset(btn, "correoPropietario")) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Celular propietario</span><input name="celular_propietario" value="' +
        escHtml(contractDataset(btn, "celularPropietario")) +
        '"></label>' +
        "</div>" +
        "</section>" +
        '<section class="scm-admin-ticket-section">' +
        '<h5 class="scm-admin-ticket-section-title">Arrendatario</h5>' +
        '<div class="scm-admin-ticket-grid scm-admin-ticket-grid--contact">' +
        '<label class="scm-seg-field"><span>Arrendatario</span><input name="arrendatario" value="' +
        escHtml(contractDataset(btn, "arrendatario")) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Correo arrendatario</span><input name="correo_arrendatario" type="email" value="' +
        escHtml(contractDataset(btn, "correoArrendatario")) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Celular arrendatario</span><input name="celular_arrendatario" value="' +
        escHtml(contractDataset(btn, "celularArrendatario")) +
        '"></label>' +
        "</div>" +
        "</section>" +
        '<label class="scm-seg-field"><span>Asunto</span><input name="asunto" required value="' +
        escHtml(defaultAsunto) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Descripcion</span><textarea name="descripcion" rows="6" required>' +
        escHtml(defaultDescripcion) +
        "</textarea></label>" +
        '<label class="scm-seg-field"><span>Imagenes / evidencias</span><input type="file" name="imagen[]" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif,image/tiff" multiple></label>' +
        renderTicketDocumentFields() +
        renderNotifyTargets() +
        '<div class="scm-seg-actions"><button type="submit" class="scm-btn-primary">Crear ticket</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";

      modal.classList.add("open");
      modal.setAttribute("aria-hidden", "false");
      document.body.classList.add("scm-modal-open");
      var firstInput = body.querySelector('select[name="id_empleado"]');
      if (firstInput && firstInput.focus) {
        firstInput.focus();
      }
    }

    function closeCaseSubmodalForNode(node) {
      var modal = node && node.closest ? node.closest(".scm-case-modal") : null;
      if (!modal) {
        modal = root.querySelector("#scm-case-modal.open");
      }
      if (modal) {
        var sub = modal.querySelector(".scm-case-submodal.open");
        if (sub) {
          sub.classList.remove("open");
          sub.setAttribute("aria-hidden", "true");
        }
      }
      var standalone =
        node && node.closest
          ? node.closest(".scm-standalone-detail-modal")
          : root.querySelector(".scm-standalone-detail-modal.open");
      if (standalone) {
        standalone.classList.remove("open");
        standalone.setAttribute("aria-hidden", "true");
        document.body.classList.remove("scm-modal-open");
      }
    }

    function cssAttrValue(value) {
      value = String(value || "");
      if (window.CSS && typeof window.CSS.escape === "function") {
        return window.CSS.escape(value);
      }
      return value.replace(/\\/g, "\\\\").replace(/"/g, '\\"');
    }

    function reopenCaseFromUpdatedCard(ticketPk) {
      ticketPk = String(ticketPk || "").trim();
      if (!ticketPk) {
        return;
      }
      var modal = root.querySelector("#scm-case-modal.open");
      if (!modal) {
        return;
      }
      var btn = root.querySelector(
        '.scm-btn-case[data-ticket-pk="' + cssAttrValue(ticketPk) + '"]',
      );
      if (btn) {
        window.scmOpenCase(btn);
      }
    }

    function refreshCaseAfterSave(ticketPk, fromNode) {
      closeCaseSubmodalForNode(fromNode);
      var refreshed = refreshActiveTab();
      if (!refreshed || typeof refreshed.then !== "function") {
        reopenCaseFromUpdatedCard(ticketPk);
        return;
      }
      refreshed.then(function () {
        reopenCaseFromUpdatedCard(ticketPk);
      });
    }

    function finishActivateTicket(ticketPk, triggerNode) {
      var openModal = root.querySelector("#scm-case-modal.open");
      if (openModal) {
        closeCaseModal(openModal);
      }
      return refreshActiveTab().then(function () {
        showToast("success", "Ticket activado.");
        if (triggerNode && triggerNode.focus) {
          triggerNode.focus();
        }
      });
    }

    function submitActivateTicket(caseBtn, motivo, triggerNode) {
      var ticketPk = String(caseBtn && caseBtn.dataset.ticketPk ? caseBtn.dataset.ticketPk : "").trim();
      if (!ticketPk) {
        showToast("error", "No se encontro el ticket.");
        return Promise.resolve();
      }
      if (!actionActivateTicket) {
        showToast("error", "Accion de activacion no configurada.");
        return Promise.resolve();
      }

      var fd = new FormData();
      fd.append("action", actionActivateTicket);
      fd.append("nonce", nonce);
      fd.append("ticket_pk", ticketPk);
      fd.append("motivo", motivo);

      if (triggerNode) {
        triggerNode.disabled = true;
      }
      return fetch(ajaxUrl, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error(
              (json && json.data && json.data.message) ||
                "No se pudo activar el ticket.",
            );
          }
          return finishActivateTicket(ticketPk, triggerNode);
        })
        .catch(function (err) {
          showToast("error", err.message || "No se pudo activar el ticket.");
        })
        .finally(function () {
          if (triggerNode) {
            triggerNode.disabled = false;
          }
        });
    }

    function openActivateTicketPrompt(caseBtn, triggerNode) {
      if (!caseBtn) {
        showToast("error", "No se encontro el ticket.");
        return;
      }
      if (window.Swal && typeof window.Swal.fire === "function") {
        window.Swal.fire({
          title: "Activar ticket",
          input: "textarea",
          inputLabel: "Motivo",
          inputPlaceholder: "Escribe el motivo de activacion",
          inputAttributes: { "aria-label": "Motivo de activacion" },
          showCancelButton: true,
          confirmButtonText: "Activar",
          cancelButtonText: "Cancelar",
          confirmButtonColor: "#1f4f99",
          inputValidator: function (value) {
            return String(value || "").trim()
              ? undefined
              : "El motivo es obligatorio.";
          },
        }).then(function (result) {
          if (!result || !result.isConfirmed) {
            return;
          }
          submitActivateTicket(
            caseBtn,
            String(result.value || "").trim(),
            triggerNode,
          );
        });
        return;
      }

      var motivo = window.prompt("Motivo para activar el ticket:");
      if (motivo === null) {
        return;
      }
      motivo = String(motivo || "").trim();
      if (!motivo) {
        showToast("error", "El motivo es obligatorio.");
        return;
      }
      submitActivateTicket(caseBtn, motivo, triggerNode);
    }

    function refreshActiveTab() {
      var activePanel = root.querySelector(".scm-tab-panel.active");
      var panelId = activePanel ? activePanel.id : "";
      var activeKey = panelId.replace("scm-panel-", "");
      if (activeKey === "mant" && form) {
        return doFetch(new FormData(form));
      } else if (tabFetchers[activeKey]) {
        return tabFetchers[activeKey].fetchTab(
          new FormData(tabFetchers[activeKey].form),
        );
      } else if (activeKey === "abiertos" && activePanel) {
        var openPanel = activePanel.querySelector(
          ".scm-open-topic-panel.active",
        );
        var openKey = openPanel
          ? openPanel.getAttribute("data-open-topic") || ""
          : "";
        if (openKey === "mant" && form) {
          return doFetch(new FormData(form));
        }
        if (openKey && tabFetchers[openKey]) {
          return tabFetchers[openKey].fetchTab(
            new FormData(tabFetchers[openKey].form),
          );
        }
      } else if (
        (activeKey === "postergados" || activeKey === "cerrados") &&
        activePanel
      ) {
        var statusPanel = activePanel.querySelector(
          ".scm-status-topic-panel.active",
        );
        var statusKey = statusPanel
          ? statusPanel.getAttribute("data-status-key") || ""
          : "";
        if (statusKey && tabFetchers[statusKey]) {
          return tabFetchers[statusKey].fetchTab(
            new FormData(tabFetchers[statusKey].form),
          );
        }
      }
      return Promise.resolve();
    }

    root.addEventListener("scm:refresh-active-tab", function () {
      refreshActiveTab();
    });

    function updateKPI(id, val) {
      var el = root.querySelector("#" + id);
      if (el) {
        el.textContent = val;
      }
    }

    function toNumber(value) {
      if (typeof value === "number" && isFinite(value)) {
        return value;
      }
      var parsed = parseFloat(String(value || "0").replace(/[^0-9.-]/g, ""));
      return isFinite(parsed) ? parsed : 0;
    }

    function normalizeMetrics(input) {
      var total = Math.max(0, Math.round(toNumber(input.total)));
      var abiertos = Math.max(0, Math.round(toNumber(input.abiertos)));
      var cerrados = Math.max(0, Math.round(toNumber(input.cerrados)));
      var slaVencido = Math.max(0, Math.round(toNumber(input.sla_vencido)));
      var slaRiesgo = Math.max(0, Math.round(toNumber(input.sla_riesgo)));
      var conCotizacion = Math.max(
        0,
        Math.round(toNumber(input.con_cotizacion)),
      );
      var sinCotizacion = Math.max(
        0,
        Math.round(toNumber(input.sin_cotizacion)),
      );
      var conRevision = Math.max(0, Math.round(toNumber(input.con_revision)));
      var sinRevision = Math.max(0, Math.round(toNumber(input.sin_revision)));

      if (total <= 0) {
        total = abiertos + cerrados;
      }
      if (total <= 0) {
        total = conCotizacion + sinCotizacion;
      }
      if (total <= 0) {
        total = conRevision + sinRevision;
      }

      var slaEnTiempo = Math.max(0, abiertos - slaRiesgo - slaVencido);

      return {
        total: total,
        abiertos: abiertos,
        cerrados: cerrados,
        sla_vencido: slaVencido,
        sla_riesgo: slaRiesgo,
        sla_en_tiempo: slaEnTiempo,
        con_cotizacion: conCotizacion,
        sin_cotizacion: sinCotizacion,
        con_revision: conRevision,
        sin_revision: sinRevision,
        avg_first_h: toNumber(input.avg_first_h),
        avg_close_h: toNumber(input.avg_close_h),
        avg_stale_h: toNumber(input.avg_stale_h),
        mes_actualizados: Math.max(
          0,
          Math.round(toNumber(input.mes_actualizados)),
        ),
        mes_cerrados: Math.max(0, Math.round(toNumber(input.mes_cerrados))),
        mes_seguimientos: Math.max(
          0,
          Math.round(toNumber(input.mes_seguimientos)),
        ),
        con_revision_entrega: Math.max(
          0,
          Math.round(toNumber(input.con_revision_entrega)),
        ),
        sin_revision_entrega: Math.max(
          0,
          Math.round(toNumber(input.sin_revision_entrega)),
        ),
        con_inventario: Math.max(0, Math.round(toNumber(input.con_inventario))),
        sin_inventario: Math.max(0, Math.round(toNumber(input.sin_inventario))),
        con_cita: Math.max(0, Math.round(toNumber(input.con_cita))),
        sin_cita: Math.max(0, Math.round(toNumber(input.sin_cita))),
        con_revision_recibo: Math.max(
          0,
          Math.round(toNumber(input.con_revision_recibo)),
        ),
        sin_revision_recibo: Math.max(
          0,
          Math.round(toNumber(input.sin_revision_recibo)),
        ),
        danos_si: Math.max(0, Math.round(toNumber(input.danos_si))),
        danos_no: Math.max(0, Math.round(toNumber(input.danos_no))),
        estado_nuevo: Math.max(0, Math.round(toNumber(input.estado_nuevo))),
        estado_en_proceso: Math.max(
          0,
          Math.round(toNumber(input.estado_en_proceso)),
        ),
        por_categoria:
          input &&
          typeof input.por_categoria === "object" &&
          input.por_categoria
            ? input.por_categoria
            : {},
        seg_por_funcionario:
          input &&
          typeof input.seg_por_funcionario === "object" &&
          input.seg_por_funcionario
            ? input.seg_por_funcionario
            : {},
        abiertos_por_funcionario:
          input &&
          typeof input.abiertos_por_funcionario === "object" &&
          input.abiertos_por_funcionario
            ? input.abiertos_por_funcionario
            : {},
        actualizados_por_funcionario:
          input &&
          typeof input.actualizados_por_funcionario === "object" &&
          input.actualizados_por_funcionario
            ? input.actualizados_por_funcionario
            : {},
      };
    }

    function renderBars(container, rows, unitSuffix) {
      if (!container) {
        return;
      }

      var max = 0;
      rows.forEach(function (row) {
        if (row.value > max) {
          max = row.value;
        }
      });
      if (max <= 0) {
        max = 1;
      }

      container.innerHTML = "";
      rows.forEach(function (row) {
        var item = document.createElement("div");
        item.className = "scm-bar-item";

        var head = document.createElement("div");
        head.className = "scm-bar-head";

        var label = document.createElement("span");
        label.textContent = row.label;

        var value = document.createElement("strong");
        value.textContent =
          unitSuffix === "h"
            ? row.value.toFixed(1) + "h"
            : String(Math.round(row.value));

        head.appendChild(label);
        head.appendChild(value);

        var track = document.createElement("div");
        track.className = "scm-bar-track";

        var fill = document.createElement("div");
        fill.className = "scm-bar-fill " + (row.cls || "");
        fill.style.width = Math.max(5, (row.value / max) * 100) + "%";

        track.appendChild(fill);
        item.appendChild(head);
        item.appendChild(track);
        container.appendChild(item);
      });
    }

    function renderMetricsCharts(metrics, catKey) {
      var total = Math.max(1, metrics.total);

      var totalEl = root.querySelector("#scm-metrics-total");
      if (totalEl) {
        totalEl.textContent = String(metrics.total);
      }

      var abiertosEl = root.querySelector("#scm-metric-abiertos");
      if (abiertosEl) {
        abiertosEl.textContent = String(metrics.abiertos);
      }

      var cerradosEl = root.querySelector("#scm-metric-cerrados");
      if (cerradosEl) {
        cerradosEl.textContent = String(metrics.cerrados);
      }

      var donut = root.querySelector("#scm-chart-estado-ring");
      var donutCenter = root.querySelector("#scm-chart-estado-center");
      if (donut) {
        var openPct = Math.max(
          0,
          Math.min(100, (metrics.abiertos / total) * 100),
        );
        donut.style.background =
          "conic-gradient(var(--scm-metric-open) 0 " +
          openPct.toFixed(2) +
          "%, var(--scm-metric-closed) " +
          openPct.toFixed(2) +
          "% 100%)";
      }
      if (donutCenter) {
        donutCenter.textContent = String(metrics.total);
      }

      renderBars(
        root.querySelector("#scm-chart-sla"),
        [
          { label: "En tiempo", value: metrics.sla_en_tiempo, cls: "success" },
          { label: "En riesgo", value: metrics.sla_riesgo, cls: "warning" },
          { label: "Vencidos", value: metrics.sla_vencido, cls: "danger" },
        ],
        "",
      );

      var flujoRows;
      if (catKey === "entrega") {
        flujoRows = [
          {
            label: "Con revision de entrega",
            value: metrics.con_revision_entrega,
            cls: "success",
          },
          {
            label: "Sin revision de entrega",
            value: metrics.sin_revision_entrega,
            cls: "warning",
          },
          {
            label: "Con inventario",
            value: metrics.con_inventario,
            cls: "accent",
          },
          {
            label: "Sin inventario",
            value: metrics.sin_inventario,
            cls: "neutral",
          },
        ];
      } else if (catKey === "preventiva") {
        flujoRows = [
          {
            label: "Con cotizacion",
            value: metrics.con_cotizacion,
            cls: "accent",
          },
          {
            label: "Sin cotizacion",
            value: metrics.sin_cotizacion,
            cls: "neutral",
          },
          {
            label: "Con revision",
            value: metrics.con_revision,
            cls: "success",
          },
          {
            label: "Sin revision",
            value: metrics.sin_revision,
            cls: "warning",
          },
          { label: "Con danos", value: metrics.danos_si, cls: "danger" },
          { label: "Sin danos", value: metrics.danos_no, cls: "success" },
        ];
      } else if (catKey === "recibo") {
        flujoRows = [
          { label: "Con cita", value: metrics.con_cita, cls: "accent" },
          { label: "Sin cita", value: metrics.sin_cita, cls: "neutral" },
          {
            label: "Con revision de recibo",
            value: metrics.con_revision_recibo,
            cls: "success",
          },
          {
            label: "Sin revision de recibo",
            value: metrics.sin_revision_recibo,
            cls: "warning",
          },
        ];
      } else if (
        catKey === "contable" ||
        catKey === "certificaciones" ||
        catKey === "contractual"
      ) {
        flujoRows = [
          { label: "Nuevo", value: metrics.estado_nuevo, cls: "accent" },
          {
            label: "En proceso",
            value: metrics.estado_en_proceso,
            cls: "warning",
          },
        ];
      } else {
        flujoRows = [
          {
            label: "Con cotizacion",
            value: metrics.con_cotizacion,
            cls: "accent",
          },
          {
            label: "Sin cotizacion",
            value: metrics.sin_cotizacion,
            cls: "neutral",
          },
          {
            label: "Con revision",
            value: metrics.con_revision,
            cls: "success",
          },
          {
            label: "Sin revision",
            value: metrics.sin_revision,
            cls: "warning",
          },
        ];
      }
      renderBars(root.querySelector("#scm-chart-flujo"), flujoRows, "");

      renderBars(
        root.querySelector("#scm-chart-tiempos"),
        [
          {
            label: "Primera gestion",
            value: metrics.avg_first_h,
            cls: "accent",
          },
          { label: "Cierre", value: metrics.avg_close_h, cls: "success" },
          {
            label: "Desactualizacion",
            value: metrics.avg_stale_h,
            cls: "warning",
          },
        ],
        "h",
      );

      renderBars(
        root.querySelector("#scm-chart-produccion"),
        [
          {
            label: "Actualizados",
            value: metrics.mes_actualizados,
            cls: "accent",
          },
          { label: "Cerrados", value: metrics.mes_cerrados, cls: "success" },
          {
            label: "Seguimientos",
            value: metrics.mes_seguimientos,
            cls: "warning",
          },
        ],
        "",
      );

      var categorias = metrics.por_categoria || {};
      var categoriasRows = Object.keys(categorias).map(function (label) {
        return {
          label: label,
          value: Math.max(0, Math.round(toNumber(categorias[label]))),
          cls: "neutral",
        };
      });
      renderBars(
        root.querySelector("#scm-chart-categorias"),
        categoriasRows,
        "",
      );

      renderBars(
        root.querySelector("#scm-chart-seg-funcionario"),
        Object.keys(metrics.seg_por_funcionario || {}).map(function (name) {
          return {
            label: name,
            value: Math.max(
              0,
              Math.round(toNumber((metrics.seg_por_funcionario || {})[name])),
            ),
            cls: "accent",
          };
        }),
        "",
      );

      renderBars(
        root.querySelector("#scm-chart-actualizados-funcionario"),
        Object.keys(metrics.actualizados_por_funcionario || {}).map(
          function (name) {
            return {
              label: name,
              value: Math.max(
                0,
                Math.round(
                  toNumber((metrics.actualizados_por_funcionario || {})[name]),
                ),
              ),
              cls: "warning",
            };
          },
        ),
        "",
      );

      renderBars(
        root.querySelector("#scm-chart-abiertos-funcionario"),
        Object.keys(metrics.abiertos_por_funcionario || {}).map(
          function (name) {
            return {
              label: name,
              value: Math.max(
                0,
                Math.round(
                  toNumber((metrics.abiertos_por_funcionario || {})[name]),
                ),
              ),
              cls: "neutral",
            };
          },
        ),
        "",
      );
    }

    function toggleKpiVisibility(id, visible) {
      var el = root.querySelector("#" + id);
      if (!el || !el.parentElement) {
        return;
      }
      el.parentElement.style.display = visible ? "" : "none";
    }

    function applyRevisionKpiVisibility(
      prefix,
      conRevisionRaw,
      sinRevisionRaw,
    ) {
      var conRevision = Math.max(0, Math.round(toNumber(conRevisionRaw)));
      var sinRevision = Math.max(0, Math.round(toNumber(sinRevisionRaw)));
      var showOnlyConRevision = conRevision > 0 && sinRevision === 0;
      var showOnlySinRevision = sinRevision > 0 && conRevision === 0;
      var showMagnitude = !showOnlySinRevision;

      toggleKpiVisibility(prefix + "kpi-sin-prev", !showOnlyConRevision);
      toggleKpiVisibility(prefix + "kpi-magnitud-critico", showMagnitude);
      toggleKpiVisibility(prefix + "kpi-magnitud-alto", showMagnitude);
      toggleKpiVisibility(prefix + "kpi-magnitud-medio", showMagnitude);
      toggleKpiVisibility(prefix + "kpi-magnitud-bajo", showMagnitude);
    }

    function applyBinaryFilterKpiVisibility(prefix, filterValue, conSuffix, sinSuffix) {
      var normalized = String(filterValue || "").trim().toLowerCase();
      var showCon = true;
      var showSin = true;

      if (normalized === "has") {
        showSin = false;
      } else if (normalized === "none") {
        showCon = false;
      }

      toggleKpiVisibility(prefix + "kpi-" + conSuffix, showCon);
      toggleKpiVisibility(prefix + "kpi-" + sinSuffix, showSin);
    }

    function getCategoryMetricSet(baseMetrics, key) {
      var details =
        baseMetrics &&
        typeof baseMetrics.detalle_por_categoria === "object" &&
        baseMetrics.detalle_por_categoria
          ? baseMetrics.detalle_por_categoria
          : {};
      var row = details[key] || details.mantenimiento || {};
      return normalizeMetrics({
        total: row.total,
        abiertos: row.abiertos,
        cerrados: row.cerrados,
        sla_vencido: row.sla_vencido,
        sla_riesgo: row.sla_riesgo,
        con_cotizacion: row.con_cotizacion,
        sin_cotizacion: row.sin_cotizacion,
        con_revision: row.con_revision,
        sin_revision: row.sin_revision,
        avg_first_h: row.avg_first_h,
        avg_close_h: row.avg_close_h,
        avg_stale_h: row.avg_stale_h,
        mes_actualizados: row.mes_actualizados,
        mes_cerrados: row.mes_cerrados,
        mes_seguimientos: row.mes_seguimientos,
        con_revision_entrega: row.con_revision_entrega,
        sin_revision_entrega: row.sin_revision_entrega,
        con_inventario: row.con_inventario,
        sin_inventario: row.sin_inventario,
        con_cita: row.con_cita,
        sin_cita: row.sin_cita,
        con_revision_recibo: row.con_revision_recibo,
        sin_revision_recibo: row.sin_revision_recibo,
        danos_si: row.danos_si,
        danos_no: row.danos_no,
        estado_nuevo: row.estado_nuevo,
        estado_en_proceso: row.estado_en_proceso,
        seg_por_funcionario: row.seg_por_funcionario,
        abiertos_por_funcionario: row.abiertos_por_funcionario,
        actualizados_por_funcionario: row.actualizados_por_funcionario,
        por_categoria: baseMetrics.por_categoria || {},
      });
    }

    function readInitialMetrics() {
      var panel = root.querySelector("#scm-panel-metricas");
      if (!panel) {
        return null;
      }
      var raw = panel.getAttribute("data-scm-metrics") || "";
      if (!raw) {
        return null;
      }
      try {
        return JSON.parse(raw);
      } catch (err) {
        console.error("SCM metrics parse error:", err);
        return null;
      }
    }

    var activeMetricCategory = "mantenimiento";
    function updateMetricsFromAjax(data) {
      if (!initialMetrics) {
        return;
      }
      if (!initialMetrics.detalle_por_categoria) {
        initialMetrics.detalle_por_categoria = {};
      }
      initialMetrics.detalle_por_categoria.mantenimiento = {
        label: "Mantenimiento",
        total: data.kpi_total,
        abiertos: data.kpi_abiertos,
        cerrados: data.kpi_cerrados,
        sla_vencido: data.kpi_vencidos,
        sla_riesgo: data.kpi_en_riesgo,
        con_cotizacion: data.kpi_con_cotz,
        sin_cotizacion: data.kpi_sin_cotz,
        con_revision: data.kpi_con_prev,
        sin_revision: data.kpi_sin_prev,
        avg_first_h: data.kpi_avg_first_h,
        avg_close_h: data.kpi_avg_close_h,
        avg_stale_h: data.kpi_avg_stale_h,
        mes_actualizados: data.kpi_mes_actualizados,
        mes_cerrados: data.kpi_mes_cerrados,
        mes_seguimientos: data.kpi_mes_seguimientos,
        seg_por_funcionario: data.kpi_seg_por_funcionario || {},
        abiertos_por_funcionario: data.kpi_abiertos_por_funcionario || {},
        actualizados_por_funcionario:
          data.kpi_actualizados_por_funcionario || {},
      };
      initialMetrics.por_categoria =
        data.kpi_por_categoria || initialMetrics.por_categoria || {};
      renderMetricsCharts(
        getCategoryMetricSet(initialMetrics, activeMetricCategory),
        activeMetricCategory,
      );
    }

    var initialMetrics = readInitialMetrics();
    if (initialMetrics) {
      var initialCategoryMetrics = getCategoryMetricSet(
        initialMetrics,
        activeMetricCategory,
      );
      renderMetricsCharts(initialCategoryMetrics, activeMetricCategory);
      applyRevisionKpiVisibility(
        "scm-",
        initialCategoryMetrics.con_revision,
        initialCategoryMetrics.sin_revision,
      );
      var metricTabsWrap = root.querySelector("#scm-metric-tabs");
      if (metricTabsWrap) {
        metricTabsWrap
          .querySelectorAll("[data-scm-metric-cat]")
          .forEach(function (btn) {
            btn.addEventListener("click", function () {
              activeMetricCategory =
                btn.getAttribute("data-scm-metric-cat") || "mantenimiento";
              metricTabsWrap
                .querySelectorAll("[data-scm-metric-cat]")
                .forEach(function (b) {
                  b.classList.remove("active");
                });
              btn.classList.add("active");
              var activeCategoryMetrics = getCategoryMetricSet(
                initialMetrics,
                activeMetricCategory,
              );
              renderMetricsCharts(activeCategoryMetrics, activeMetricCategory);
              applyRevisionKpiVisibility(
                "scm-",
                activeCategoryMetrics.con_revision,
                activeCategoryMetrics.sin_revision,
              );
            });
          });
      }
    }

    var revisionPrefixes = [
      "scm-",
      "scm-entrega-",
      "scm-preventiva-",
      "scm-recibo-",
      "scm-contable-",
      "scm-certificaciones-",
      "scm-contractual-",
    ];
    revisionPrefixes.forEach(function (prefix) {
      var conEl = root.querySelector("#" + prefix + "kpi-con-prev");
      var sinEl = root.querySelector("#" + prefix + "kpi-sin-prev");
      if (!conEl || !sinEl) {
        return;
      }
      applyRevisionKpiVisibility(prefix, conEl.textContent, sinEl.textContent);
    });

    applyBinaryFilterKpiVisibility(
      "scm-",
      root.querySelector("#scm_cotizacion")
        ? root.querySelector("#scm_cotizacion").value
        : "",
      "con-cotz",
      "sin-cotz",
    );

    var form = root.querySelector("#scm-form");
    var tbody = root.querySelector("#scm-tbody");
    var pagination = root.querySelector("#scm-pagination");
    var spinner = root.querySelector("#scm-spinner");
    var clearBtn = root.querySelector("#scm-clear");

    function setMantPage(page) {
      if (!form) {
        return;
      }
      var pageInput = form.querySelector("#scm_page");
      if (pageInput) {
        pageInput.value = String(page);
      }
    }

    function doFetch(fd) {
      if (!form || !tbody || !ajaxUrl || !actionMant) {
        return Promise.resolve();
      }

      fd.append("action", actionMant);
      fd.append("nonce", nonce);
      fd.append("config", JSON.stringify(config));

      if (spinner) {
        spinner.classList.add("active");
      }
      form.classList.add("scm-loading");

      return fetch(ajaxUrl, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            return;
          }
          var d = json.data || {};
          tbody.innerHTML = d.tbody || "";
          if (pagination) {
            pagination.innerHTML = d.pagination || "";
          }
          if (tbody) {
            tbody.scrollIntoView({ behavior: "smooth", block: "start" });
          }

          updateKPI("scm-kpi-total", d.kpi_total || "0");
          updateKPI("scm-kpi-sin-cotz", d.kpi_sin_cotz || "0");
          updateKPI("scm-kpi-con-cotz", d.kpi_con_cotz || "0");
          updateKPI("scm-kpi-sin-prev", d.kpi_sin_prev || "0");
          updateKPI("scm-kpi-con-prev", d.kpi_con_prev || "0");
          updateKPI("scm-kpi-abiertos", d.kpi_abiertos || "0");
          updateKPI("scm-kpi-cerrados", d.kpi_cerrados || "0");
          updateKPI("scm-kpi-vencidos", d.kpi_vencidos || "0");
          updateKPI("scm-kpi-riesgo", d.kpi_en_riesgo || "0");
          updateKPI("scm-kpi-avg-first", d.kpi_avg_first_h || "-");
          updateKPI("scm-kpi-avg-close", d.kpi_avg_close_h || "-");
          updateKPI("scm-kpi-avg-stale", d.kpi_avg_stale_h || "-");
          updateKPI("scm-kpi-magnitud-critico", d.kpi_magnitud_critico || "0");
          updateKPI("scm-kpi-magnitud-alto", d.kpi_magnitud_alto || "0");
          updateKPI("scm-kpi-magnitud-medio", d.kpi_magnitud_medio || "0");
          updateKPI("scm-kpi-magnitud-bajo", d.kpi_magnitud_bajo || "0");
          updateKPI("scm-kpi-header-count", d.kpi_total || "0");
          applyRevisionKpiVisibility("scm-", d.kpi_con_prev, d.kpi_sin_prev);
          applyBinaryFilterKpiVisibility(
            "scm-",
            form && form.querySelector("#scm_cotizacion")
              ? form.querySelector("#scm_cotizacion").value
              : "",
            "con-cotz",
            "sin-cotz",
          );
          updateMetricsFromAjax(d);
        })
        .catch(function (err) {
          console.error("SCM error:", err);
        })
        .finally(function () {
          if (spinner) {
            spinner.classList.remove("active");
          }
          form.classList.remove("scm-loading");
        });
    }

    if (form) {
      form.addEventListener("submit", function (e) {
        e.preventDefault();
        setMantPage(1);
        doFetch(new FormData(form));
      });
      var perPageSelect = form.querySelector("#scm_per_page");
      var cotizacionSelect = form.querySelector("#scm_cotizacion");
      if (perPageSelect) {
        perPageSelect.addEventListener("change", function () {
          setMantPage(1);
          doFetch(new FormData(form));
        });
      }
      if (cotizacionSelect) {
        cotizacionSelect.addEventListener("change", function () {
          applyBinaryFilterKpiVisibility(
            "scm-",
            cotizacionSelect.value,
            "con-cotz",
            "sin-cotz",
          );
        });
      }
    }

    if (clearBtn && form) {
        clearBtn.addEventListener("click", function () {
        form.querySelectorAll("select").forEach(function (s) {
          s.selectedIndex = 0;
        });
        form
          .querySelectorAll("input[type='text'], input[type='date']")
          .forEach(function (i) {
            i.value = "";
          });
          setMantPage(1);
          applyBinaryFilterKpiVisibility("scm-", "", "con-cotz", "sin-cotz");
          doFetch(new FormData(form));
        });
      }

    root.querySelectorAll(".scm-classify-magnitude").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var type = btn.getAttribute("data-revision-type") || "correctiva";
        var oldText = btn.textContent;
        btn.disabled = true;
        btn.textContent = "Clasificando...";
        classifyMagnitude(
          type,
          function () {
            var tabKey = btn.getAttribute("data-tab-key") || "";
            if (tabKey && tabFetchers[tabKey]) {
              tabFetchers[tabKey].fetchTab(
                new FormData(tabFetchers[tabKey].form),
              );
            } else if (form) {
              setMantPage(1);
              doFetch(new FormData(form));
            }
          },
          function () {
            btn.textContent = oldText;
            btn.disabled = false;
          },
        );
      });
    });

    root.addEventListener("click", function (e) {
      var addTicketDocBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-add-ticket-document]")
          : null;
      if (addTicketDocBtn) {
        e.preventDefault();
        var form = addTicketDocBtn.closest("form");
        var docsWrap = form ? form.querySelector("[data-ticket-documents]") : null;
        var firstRow = docsWrap
          ? docsWrap.querySelector(".scm-ticket-document-row")
          : null;
        if (docsWrap && firstRow) {
          var clone = firstRow.cloneNode(true);
          clone.querySelectorAll("input").forEach(function (input) {
            input.value = "";
          });
          docsWrap.appendChild(clone);
        } else if (docsWrap) {
          docsWrap.insertAdjacentHTML("beforeend", renderTicketDocumentRow());
        }
        return;
      }

      var removeTicketDocBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-remove-ticket-document]")
          : null;
      if (removeTicketDocBtn) {
        e.preventDefault();
        var row = removeTicketDocBtn.closest(".scm-ticket-document-row");
        if (row) {
          row.remove();
        }
        return;
      }

      var extLink =
        e.target && e.target.closest ? e.target.closest("a[href]") : null;
      if (
        extLink &&
        extLink.closest(".scm-case-modal") &&
        extLink.getAttribute("target") !== "_blank" &&
        /^https?:\/\//i.test(extLink.getAttribute("href") || "")
      ) {
        e.preventDefault();
        openIframeModal(
          extLink.href,
          (extLink.textContent || "").trim() || extLink.getAttribute("href"),
        );
        return;
      }

      var adminTicketBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-open-admin-ticket]")
          : null;
      if (adminTicketBtn) {
        e.preventDefault();
        openAdminTicketModal(adminTicketBtn);
        return;
      }

      var iframeBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-open-iframe]")
          : null;
      if (iframeBtn) {
        e.preventDefault();
        openIframeModal(
          iframeBtn.dataset.iframeUrl || "",
          iframeBtn.dataset.iframeTitle || "",
        );
        return;
      }

      var activateTicketBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-activate-ticket]")
          : null;
      if (activateTicketBtn) {
        e.preventDefault();
        var activateCard = activateTicketBtn.closest(".scm-ticket-card");
        var activateCaseBtn = activateCard
          ? activateCard.querySelector(".scm-btn-case")
          : null;
        if (!activateCaseBtn) {
          var activeModal = activateTicketBtn.closest(".scm-case-modal");
          var activeTicketPk = activeModal ? activeModal.dataset.ticketPk || "" : "";
          activateCaseBtn = activeTicketPk
            ? root.querySelector(
                '.scm-btn-case[data-ticket-pk="' +
                  cssAttrValue(activeTicketPk) +
                  '"]',
              )
            : null;
        }
        if (!activateCaseBtn) {
          showToast("error", "No se encontro el ticket.");
          return;
        }
        openActivateTicketPrompt(activateCaseBtn, activateTicketBtn);
        return;
      }

      var cardConsultorBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-open-card-consultor]")
          : null;
      var locationEditorBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-open-location-editor]")
          : null;
      if (locationEditorBtn) {
        e.preventDefault();
        var parentCaseModal = locationEditorBtn.closest(".scm-case-modal");
        if (parentCaseModal) {
          var activeTicketPk = parentCaseModal.dataset.ticketPk || "";
          var activeCaseBtn = activeTicketPk
            ? root.querySelector(
                '.scm-btn-case[data-ticket-pk="' +
                  cssAttrValue(activeTicketPk) +
                  '"]',
              )
            : null;
          if (activeCaseBtn) {
            openPropertyLocationEditor(parentCaseModal, activeCaseBtn);
          }
          return;
        }
        var standaloneModal = locationEditorBtn.closest(
          ".scm-standalone-detail-modal",
        );
        if (standaloneModal) {
          var standaloneTicketPk = standaloneModal.dataset.ticketPk || "";
          var standaloneCaseBtn = standaloneTicketPk
            ? root.querySelector(
                '.scm-btn-case[data-ticket-pk="' +
                  cssAttrValue(standaloneTicketPk) +
                  '"]',
              )
            : null;
          if (standaloneCaseBtn) {
            openPropertyLocationStandaloneEditor(root, standaloneCaseBtn);
          }
          return;
        }
      }
      if (cardConsultorBtn) {
        e.preventDefault();
        var consultorCard = cardConsultorBtn.closest(".scm-ticket-card");
        var consultorCaseBtn = consultorCard
          ? consultorCard.querySelector(".scm-btn-case")
          : null;
        if (!consultorCaseBtn) {
          return;
        }
        var consultorRoot = findRootFromNode(cardConsultorBtn) || root;
        openStandaloneDetail(
          consultorRoot,
          Object.assign(getConsultorEntregaDetailPayload(consultorCaseBtn), {
            caseBtn: consultorCaseBtn,
          }),
        );
        return;
      }

      var cardLlavesBtn =
        e.target && e.target.closest
          ? e.target.closest("[data-scm-open-card-llaves]")
          : null;
      if (cardLlavesBtn) {
        e.preventDefault();
        var llavesCard = cardLlavesBtn.closest(".scm-ticket-card");
        var llavesCaseBtn = llavesCard
          ? llavesCard.querySelector(".scm-btn-case")
          : null;
        if (!llavesCaseBtn) {
          return;
        }
        var llavesRoot = findRootFromNode(cardLlavesBtn) || root;
        openStandaloneDetail(
          llavesRoot,
          Object.assign(getLlavesDetailPayload(llavesCaseBtn), {
            caseBtn: llavesCaseBtn,
          }),
        );
        return;
      }

      var caseBtn =
        e.target && e.target.closest ? e.target.closest(".scm-btn-case") : null;
      if (caseBtn) {
        e.preventDefault();
        window.scmOpenCase(caseBtn);
        return;
      }

      var historyPager =
        e.target && e.target.closest
          ? e.target.closest(".scm-history-page-btn")
          : null;
      if (historyPager) {
        e.preventDefault();
        var targetId = historyPager.getAttribute("data-target") || "";
        var pagerScope =
          historyPager.closest(".scm-case-modal") ||
          historyPager.closest(".scm-case-main") ||
          root;
        var list = targetId ? pagerScope.querySelector("#" + targetId) : null;
        if (!list) {
          return;
        }

        var items = list.querySelectorAll(".scm-case-history-item[data-page]");
        if (!items.length) {
          return;
        }

        var totalPages = 1;
        items.forEach(function (it) {
          var p = parseInt(it.getAttribute("data-page") || "1", 10);
          if (p > totalPages) {
            totalPages = p;
          }
        });

        var current = parseInt(
          list.getAttribute("data-current-page") || "1",
          10,
        );
        var dir = historyPager.getAttribute("data-dir") || "next";
        var next = dir === "prev" ? current - 1 : current + 1;
        if (next < 1) {
          next = 1;
        }
        if (next > totalPages) {
          next = totalPages;
        }
        if (next === current) {
          return;
        }

        list.setAttribute("data-current-page", String(next));
        items.forEach(function (it) {
          var p = parseInt(it.getAttribute("data-page") || "1", 10);
          it.style.display = p === next ? "" : "none";
        });

        var status = pagerScope.querySelector(
          '.scm-history-page-status[data-target="' + targetId + '"]',
        );
        if (status) {
          var total = parseInt(status.getAttribute("data-total") || "0", 10);
          if (total <= 0) {
            total = items.length;
          }
          var perPage = parseInt(
            status.getAttribute("data-per-page") || "10",
            10,
          );
          if (perPage <= 0) {
            perPage = 10;
          }
          var start = (next - 1) * perPage + 1;
          var end = Math.min(total, next * perPage);
          status.textContent =
            "Mostrando " +
            String(start) +
            "-" +
            String(end) +
            " de " +
            String(total) +
            " | Pagina " +
            String(next) +
            " de " +
            String(totalPages);
        }

        var pagerBtns = pagerScope.querySelectorAll(
          '.scm-history-page-btn[data-target="' + targetId + '"]',
        );
        pagerBtns.forEach(function (b) {
          var bDir = b.getAttribute("data-dir") || "next";
          b.disabled =
            (bDir === "prev" && next <= 1) ||
            (bDir === "next" && next >= totalPages);
        });

        return;
      }

      var btn =
        e.target && e.target.closest ? e.target.closest(".scm-page-btn") : null;
      if (!btn || !form) {
        return;
      }
      if (btn.classList && btn.classList.contains("scm-page-btn-generic")) {
        return;
      }
      // Solo manejar botones de paginación del panel de Mantenimiento
      var mantPanel = root.querySelector("#scm-panel-mant");
      if (mantPanel && !mantPanel.contains(btn)) {
        return;
      }
      e.preventDefault();
      if (btn.disabled) {
        return;
      }
      setMantPage(btn.getAttribute("data-page") || "1");
      doFetch(new FormData(form));
    });

    // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ Generic tab fetchers ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
    var tabFetchers = {};
    function updateGenericKPI(tabKey, suffix, value) {
      var el = root.querySelector("#scm-" + tabKey + "-kpi-" + suffix);
      if (el) {
        el.textContent = value;
      }
    }

    function classifyMagnitude(revisionType, afterDone, afterFinally) {
      var fd = new FormData();
      fd.append(
        "action",
        (actions && actions.classify_magnitude) || "scm_clasificar_magnitud",
      );
      fd.append("nonce", nonce);
      fd.append("revision_type", revisionType || "correctiva");

      fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error(
              (json && json.data && json.data.message) ||
                "No se pudo clasificar.",
            );
          }
          if (typeof afterDone === "function") {
            afterDone(json.data || {});
          }
        })
        .catch(function (err) {
          alert(
            "No se pudo clasificar la magnitud: " + (err.message || "error"),
          );
        })
        .finally(function () {
          if (typeof afterFinally === "function") {
            afterFinally();
          }
        });
    }

    function makeTabFetcher(tabKey, action) {
      var tabForm = root.querySelector("#scm-form-" + tabKey);
      var tabCards = root.querySelector("#scm-cards-" + tabKey);
      var tabPagination = root.querySelector("#scm-pagination-" + tabKey);
      var tabCount = root.querySelector("#scm-" + tabKey + "-count");
      var tabSpinner = root.querySelector("#scm-spinner-" + tabKey);
      var tabClear = root.querySelector("#scm-clear-" + tabKey);
      var pageInput = tabForm
        ? tabForm.querySelector("input[name$='page']")
        : null;
      var tabPanel = tabCards ? tabCards.closest(".scm-open-topic-panel") : null;

      if (!tabForm || !tabCards || !ajaxUrl || !action) {
        return null;
      }

      function fetchTab(fd) {
        fd.append("action", action);
        fd.append("nonce", nonce);
        fd.append("config", JSON.stringify(config));

        if (tabSpinner) {
          tabSpinner.classList.add("active");
        }
        tabForm.classList.add("scm-loading");

        return fetch(ajaxUrl, {
          method: "POST",
          body: fd,
          credentials: "same-origin",
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (json) {
            if (!json || !json.success) {
              return;
            }
            var d = json.data || {};
            tabCards.innerHTML = d.cards || "";
            if (tabPagination) {
              tabPagination.innerHTML = d.pagination || "";
            }
            if (tabCount) {
              tabCount.textContent = d.count || "0";
            }
            if (tabPanel) {
              tabPanel.setAttribute("data-scm-loaded", "1");
            }
            updateGenericKPI(tabKey, "total", d.kpi_total || "0");
            updateGenericKPI(tabKey, "con-cotz", d.kpi_con_cotz || "0");
            updateGenericKPI(tabKey, "sin-cotz", d.kpi_sin_cotz || "0");
            updateGenericKPI(tabKey, "con-prev", d.kpi_con_prev || "0");
            updateGenericKPI(tabKey, "sin-prev", d.kpi_sin_prev || "0");
            if (tabKey === "entrega") {
              updateGenericKPI(
                tabKey,
                "con-rev-entrega",
                d.kpi_con_rev_entrega || "0",
              );
              updateGenericKPI(
                tabKey,
                "sin-rev-entrega",
                d.kpi_sin_rev_entrega || "0",
              );
              updateGenericKPI(
                tabKey,
                "con-inventario",
                d.kpi_con_inventario || "0",
              );
              updateGenericKPI(
                tabKey,
                "sin-inventario",
                d.kpi_sin_inventario || "0",
              );
            }
            if (tabKey === "recibo") {
              updateGenericKPI(tabKey, "con-cita", d.kpi_con_cita || "0");
              updateGenericKPI(tabKey, "sin-cita", d.kpi_sin_cita || "0");
              updateGenericKPI(
                tabKey,
                "con-rev-recibo",
                d.kpi_con_rev_recibo || "0",
              );
              updateGenericKPI(
                tabKey,
                "sin-rev-recibo",
                d.kpi_sin_rev_recibo || "0",
              );
            }
            if (
              ["contractual", "contable", "certificaciones"].includes(tabKey)
            ) {
              updateGenericKPI(tabKey, "nuevo", d.kpi_nuevo || "0");
              updateGenericKPI(tabKey, "en-proceso", d.kpi_en_proceso || "0");
            }
            updateGenericKPI(tabKey, "avg-first", d.kpi_avg_first_h || "-");
            updateGenericKPI(tabKey, "avg-stale", d.kpi_avg_stale_h || "-");
            updateGenericKPI(
              tabKey,
              "magnitud-critico",
              d.kpi_magnitud_critico || "0",
            );
            updateGenericKPI(
              tabKey,
              "magnitud-alto",
              d.kpi_magnitud_alto || "0",
            );
            updateGenericKPI(
              tabKey,
              "magnitud-medio",
              d.kpi_magnitud_medio || "0",
            );
            updateGenericKPI(
              tabKey,
              "magnitud-bajo",
              d.kpi_magnitud_bajo || "0",
            );
            updateGenericKPI(tabKey, "danos-si", d.kpi_danos_si || "0");
            updateGenericKPI(tabKey, "danos-no", d.kpi_danos_no || "0");
            applyRevisionKpiVisibility(
              "scm-" + tabKey + "-",
              d.kpi_con_prev,
              d.kpi_sin_prev,
            );
            applyBinaryFilterKpiVisibility(
              "scm-" + tabKey + "-",
              tabForm.querySelector("[name$='cotizacion']")
                ? tabForm.querySelector("[name$='cotizacion']").value
                : "",
              "con-cotz",
              "sin-cotz",
            );
          })
          .catch(function (err) {
            console.error("SCM " + tabKey + " error:", err);
          })
          .finally(function () {
            if (tabSpinner) {
              tabSpinner.classList.remove("active");
            }
            tabForm.classList.remove("scm-loading");
          });
      }

      tabForm.addEventListener("submit", function (e) {
        e.preventDefault();
        if (pageInput) {
          pageInput.value = "1";
        }
        fetchTab(new FormData(tabForm));
      });

      var tabCotizacionSelect = tabForm.querySelector("[name$='cotizacion']");
      if (tabCotizacionSelect) {
        tabCotizacionSelect.addEventListener("change", function () {
          applyBinaryFilterKpiVisibility(
            "scm-" + tabKey + "-",
            tabCotizacionSelect.value,
            "con-cotz",
            "sin-cotz",
          );
        });
      }

      if (tabClear) {
        tabClear.addEventListener("click", function () {
          tabForm.querySelectorAll("select").forEach(function (s) {
            s.selectedIndex = 0;
          });
          tabForm
            .querySelectorAll("input[type='text'], input[type='date']")
            .forEach(function (i) {
              i.value = "";
            });
          if (pageInput) {
            pageInput.value = "1";
          }
          applyBinaryFilterKpiVisibility(
            "scm-" + tabKey + "-",
            "",
            "con-cotz",
            "sin-cotz",
          );
          fetchTab(new FormData(tabForm));
        });
      }

      applyBinaryFilterKpiVisibility(
        "scm-" + tabKey + "-",
        tabCotizacionSelect ? tabCotizacionSelect.value : "",
        "con-cotz",
        "sin-cotz",
      );

      if (tabPagination) {
        tabPagination.addEventListener("click", function (e) {
          var pageBtn =
            e.target && e.target.closest
              ? e.target.closest(".scm-page-btn-generic")
              : null;
          if (!pageBtn || pageBtn.disabled) {
            return;
          }
          e.preventDefault();
          e.stopPropagation();
          if (pageInput) {
            pageInput.value = String(pageBtn.getAttribute("data-page") || "1");
          }
          fetchTab(new FormData(tabForm));
        });
      }

      return { fetchTab: fetchTab, form: tabForm };
    }

    function makeStatusFetcher(statusPanel) {
      var statusKey = statusPanel
        ? statusPanel.getAttribute("data-status-key") || ""
        : "";
      var bucket = statusPanel
        ? statusPanel.getAttribute("data-status-bucket") || ""
        : "";
      var topic = statusPanel
        ? statusPanel.getAttribute("data-status-topic") || ""
        : "";
      var tabForm = statusKey
        ? root.querySelector("#scm-form-" + statusKey)
        : null;
      var tabCards = statusKey
        ? root.querySelector("#scm-status-cards-" + statusKey)
        : null;
      var tabPagination = statusKey
        ? root.querySelector("#scm-status-pagination-" + statusKey)
        : null;
      var tabCount = statusKey
        ? root.querySelector("#scm-" + statusKey + "-count")
        : null;
      var tabSpinner = statusKey
        ? root.querySelector("#scm-spinner-" + statusKey)
        : null;
      var tabClear = statusKey
        ? root.querySelector("#scm-clear-" + statusKey)
        : null;
      var pageInput = tabForm
        ? tabForm.querySelector("input[name$='page']")
        : null;

      if (
        !statusKey ||
        !tabForm ||
        !tabCards ||
        !ajaxUrl ||
        !actionStatusTickets
      ) {
        return null;
      }

      function fetchTab(fd) {
        fd.append("action", actionStatusTickets);
        fd.append("nonce", nonce);
        fd.append("config", JSON.stringify(config));
        fd.append("bucket", bucket);
        fd.append("topic", topic);

        if (tabSpinner) {
          tabSpinner.classList.add("active");
        }
        tabForm.classList.add("scm-loading");

        return fetch(ajaxUrl, {
          method: "POST",
          body: fd,
          credentials: "same-origin",
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error(
                (json && json.data && json.data.message) ||
                  "No se pudo cargar la vista.",
              );
            }
            var d = json.data || {};
            tabCards.innerHTML = d.cards || "";
            if (tabPagination) {
              tabPagination.innerHTML = d.pagination || "";
            }
            if (tabCount) {
              tabCount.textContent = d.count || d.kpi_total || "0";
            }
            if (statusPanel) {
              statusPanel.setAttribute("data-scm-loaded", "1");
            }
          })
          .catch(function (err) {
            console.error("SCM status tickets error:", err);
            showToast("error", err.message || "No se pudo cargar la vista.");
          })
          .finally(function () {
            if (tabSpinner) {
              tabSpinner.classList.remove("active");
            }
            tabForm.classList.remove("scm-loading");
          });
      }

      tabForm.addEventListener("submit", function (e) {
        e.preventDefault();
        if (pageInput) {
          pageInput.value = "1";
        }
        fetchTab(new FormData(tabForm));
      });

      if (tabClear) {
        tabClear.addEventListener("click", function () {
          tabForm.querySelectorAll("select").forEach(function (s) {
            s.selectedIndex = 0;
          });
          tabForm
            .querySelectorAll("input[type='text'], input[type='date']")
            .forEach(function (i) {
              if (!i.readOnly) {
                i.value = "";
              }
            });
          if (pageInput) {
            pageInput.value = "1";
          }
          fetchTab(new FormData(tabForm));
        });
      }

      if (tabPagination) {
        tabPagination.addEventListener("click", function (e) {
          var pageBtn =
            e.target && e.target.closest
              ? e.target.closest(".scm-page-btn")
              : null;
          if (!pageBtn || pageBtn.disabled) {
            return;
          }
          e.preventDefault();
          e.stopPropagation();
          if (pageInput) {
            pageInput.value = String(pageBtn.getAttribute("data-page") || "1");
          }
          fetchTab(new FormData(tabForm));
        });
      }

      return { fetchTab: fetchTab, form: tabForm };
    }

    root.querySelectorAll(".scm-status-topic-panel").forEach(function (panel) {
      var statusFetcher = makeStatusFetcher(panel);
      if (statusFetcher) {
        tabFetchers[panel.getAttribute("data-status-key") || ""] =
          statusFetcher;
      }
    });

    root
      .querySelectorAll(".scm-status-bucket[data-status-bucket]")
      .forEach(function (bucketWrap) {
      var tabs = bucketWrap.querySelectorAll(".scm-status-topic-tab");
      var panels = bucketWrap.querySelectorAll(".scm-status-topic-panel");
      tabs.forEach(function (tab) {
        tab.addEventListener("click", function () {
          var target = tab.getAttribute("data-status-target") || "";
          tabs.forEach(function (item) {
            item.classList.toggle(
              "active",
              item.getAttribute("data-status-target") === target,
            );
          });
          panels.forEach(function (panel) {
            panel.classList.toggle(
              "active",
              panel.getAttribute("data-status-key") === target,
            );
          });
          loadStatusPanelIfNeeded(
            bucketWrap.querySelector(
              '.scm-status-topic-panel[data-status-key="' + target + '"]',
            ),
          );
        });
      });
    });

    function initContractsPanel() {
      var wrap = root.querySelector("[data-scm-contracts]");
      if (
        !wrap ||
        (!actionContratosArrendamiento && !actionContratosArrendamientoFallback)
      ) {
        return;
      }

      function activePanel() {
        return wrap.querySelector(".scm-contract-panel.active");
      }

      function fetchContracts(panel) {
        if (!panel) {
          return Promise.resolve();
        }
        var bucket = panel.getAttribute("data-contract-panel") || "";
        var form = panel.querySelector("form.sca_form");
        var table = panel.querySelector("[data-contract-table]");
        var pagination = panel.querySelector("[data-contract-pagination]");
        var countEl = panel.querySelector("[data-contract-count]");
        var spinner = panel.querySelector(".scm-spinner");
        if (!bucket || !form || !table) {
          return Promise.resolve();
        }
        function buildRequest(actionName, asFallback) {
          var fd = new FormData(form);
          fd.append("action", actionName);
          fd.append("nonce", nonce);
          fd.append("bucket", bucket);
          if (asFallback) {
            fd.append("pending_scope", "contratos_arrendamiento");
          }
          return fd;
        }

        function requestContracts(actionName, asFallback) {
          return fetch(ajaxUrl, {
            method: "POST",
            body: buildRequest(actionName, asFallback),
            credentials: "same-origin",
          }).then(function (r) {
            return r.json();
          });
        }

        if (spinner) spinner.classList.add("active");
        form.classList.add("scm-loading");
        var primaryAction =
          actionContratosArrendamiento || actionContratosArrendamientoFallback;
        var primaryIsFallback = !actionContratosArrendamiento;
        return requestContracts(primaryAction, primaryIsFallback)
          .then(function (json) {
            if (!json || !json.success) {
              var message =
                (json && json.data && json.data.message) ||
                "No se pudieron cargar los contratos.";
              if (
                actionContratosArrendamientoFallback &&
                actionContratosArrendamientoFallback !== primaryAction &&
                /desconocid/i.test(message)
              ) {
                return requestContracts(
                  actionContratosArrendamientoFallback,
                  true,
                );
              }
              throw new Error(
                message,
              );
            }
            return json;
          })
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error(
                (json && json.data && json.data.message) ||
                  "No se pudieron cargar los contratos.",
              );
            }
            var data = json.data || {};
            table.innerHTML = data.table_html || "";
            if (pagination) {
              pagination.innerHTML = data.pagination_html || "";
            }
            if (countEl) {
              countEl.textContent = data.count || "0";
            }
            var headerCount = root.querySelector("#sca-kpi-count");
            if (headerCount) {
              headerCount.textContent = data.count || "0";
            }
            panel.setAttribute("data-scm-loaded", "1");
          })
          .catch(function (err) {
            showToast(
              "error",
              err && err.message
                ? err.message
                : "No se pudieron cargar los contratos.",
            );
          })
          .finally(function () {
            if (spinner) spinner.classList.remove("active");
            form.classList.remove("scm-loading");
          });
      }

      function loadActiveContracts(force) {
        var panel = activePanel();
        if (!panel) {
          return Promise.resolve();
        }
        if (!force && panel.getAttribute("data-scm-loaded") === "1") {
          return Promise.resolve();
        }
        return fetchContracts(panel);
      }

      wrap.querySelectorAll(".scm-contract-tab").forEach(function (tab) {
        tab.addEventListener("click", function () {
          var bucket = tab.getAttribute("data-contract-bucket") || "";
          wrap.querySelectorAll(".scm-contract-tab").forEach(function (item) {
            item.classList.toggle(
              "active",
              item.getAttribute("data-contract-bucket") === bucket,
            );
          });
          wrap.querySelectorAll(".scm-contract-panel").forEach(function (panel) {
            panel.classList.toggle(
              "active",
              panel.getAttribute("data-contract-panel") === bucket,
            );
          });
          loadActiveContracts(false);
        });
      });

      wrap.querySelectorAll("form.sca_form").forEach(function (form) {
        form.addEventListener("submit", function (e) {
          e.preventDefault();
          var page = form.querySelector('input[name="sca_page"]');
          if (page) page.value = "1";
          fetchContracts(form.closest(".scm-contract-panel"));
        });
      });

      wrap.addEventListener("click", function (e) {
        var clear = e.target.closest("[data-contract-clear]");
        if (clear) {
          e.preventDefault();
          var panel = clear.closest(".scm-contract-panel");
          var form = panel ? panel.querySelector("form.sca_form") : null;
          if (form) {
            form
              .querySelectorAll("input[type='text'], input[type='date']")
              .forEach(function (input) {
                input.value = "";
              });
            form.querySelectorAll("select").forEach(function (select) {
              select.selectedIndex = 0;
            });
            var page = form.querySelector('input[name="sca_page"]');
            if (page) page.value = "1";
          }
          fetchContracts(panel);
          return;
        }

        var pageBtn = e.target.closest(".scm-page-btn-contracts");
        if (pageBtn && !pageBtn.disabled) {
          e.preventDefault();
          var pagePanel = pageBtn.closest(".scm-contract-panel");
          var pageForm = pagePanel ? pagePanel.querySelector("form.sca_form") : null;
          var pageInput = pageForm
            ? pageForm.querySelector('input[name="sca_page"]')
            : null;
          if (pageInput) {
            pageInput.value = String(pageBtn.getAttribute("data-page") || "1");
          }
          fetchContracts(pagePanel);
        }
      });

      var contractsMainTab = root.querySelector(
        '.scm-tab[data-tab="scm-panel-contratos-arrendamiento"]',
      );
      if (contractsMainTab) {
        contractsMainTab.addEventListener("click", function () {
          window.setTimeout(function () {
            loadActiveContracts(false);
          }, 0);
        });
      }

      root.addEventListener("scm:contracts-refresh", function () {
        loadActiveContracts(true);
      });

      if (
        root.querySelector("#scm-panel-contratos-arrendamiento.active")
      ) {
        loadActiveContracts(false);
      }
    }

    initContractsPanel();

    var genericTabKeys = [
      { key: "entrega", action: actions.entrega || "" },
      { key: "preventiva", action: actions.preventiva || "" },
      { key: "recibo", action: actions.recibo || "" },
      { key: "contable", action: actions.contable || "" },
      { key: "certificaciones", action: actions.certificaciones || "" },
      { key: "contractual", action: actions.contractual || "" },
    ];

    genericTabKeys.forEach(function (t) {
      if (t.action) {
        var f = makeTabFetcher(t.key, t.action);
        if (f) {
          tabFetchers[t.key] = f;
        }
      }
    });

    // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ Seguimiento form ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
    function loadPanelOnce(panel, fetcherKey) {
      if (!panel || !fetcherKey || !tabFetchers[fetcherKey]) {
        return Promise.resolve();
      }
      if (panel.getAttribute("data-scm-loaded") === "1") {
        return Promise.resolve();
      }
      if (panel.getAttribute("data-scm-loading") === "1") {
        return Promise.resolve();
      }
      var fetcher = tabFetchers[fetcherKey];
      if (!fetcher || !fetcher.form) {
        return Promise.resolve();
      }
      panel.setAttribute("data-scm-loading", "1");
      return fetcher
        .fetchTab(new FormData(fetcher.form))
        .finally(function () {
          panel.setAttribute("data-scm-loading", "0");
        });
    }

    function loadOpenTopicPanelIfNeeded(panel) {
      if (!panel) {
        return Promise.resolve();
      }
      var key = panel.getAttribute("data-open-topic") || "";
      if (!key || key === "mant") {
        return Promise.resolve();
      }
      return loadPanelOnce(panel, key);
    }

    function loadStatusPanelIfNeeded(panel) {
      if (!panel) {
        return Promise.resolve();
      }
      return loadPanelOnce(panel, panel.getAttribute("data-status-key") || "");
    }

    function loadActiveLazyPanel() {
      var activePanel = root.querySelector(".scm-tab-panel.active");
      if (!activePanel) {
        return Promise.resolve();
      }
      if (activePanel.id === "scm-panel-abiertos") {
        return loadOpenTopicPanelIfNeeded(
          activePanel.querySelector(".scm-open-topic-panel.active"),
        );
      }
      if (
        activePanel.id === "scm-panel-postergados" ||
        activePanel.id === "scm-panel-cerrados"
      ) {
        return loadStatusPanelIfNeeded(
          activePanel.querySelector(".scm-status-topic-panel.active"),
        );
      }
      return Promise.resolve();
    }

    root.querySelectorAll(".scm-open-topic-tab").forEach(function (tab) {
      tab.addEventListener("click", function () {
        window.setTimeout(loadActiveLazyPanel, 0);
      });
    });

    root.querySelectorAll(".scm-tab[data-tab]").forEach(function (tab) {
      tab.addEventListener("click", function () {
        window.setTimeout(loadActiveLazyPanel, 0);
      });
    });

    loadActiveLazyPanel();

    root.addEventListener("submit", function (e) {
      var segForm = e.target;
      if (
        !segForm ||
        !segForm.classList ||
        !segForm.classList.contains("scm-seg-form")
      ) {
        return;
      }
      e.preventDefault();

      var btn = segForm.querySelector("button[type='submit']");
      var msg = segForm.querySelector(".scm-seg-msg");
      if (btn) {
        btn.disabled = true;
      }
      if (msg) {
        msg.textContent = "Guardando...";
        msg.classList.remove("error");
      }

      var fd = new FormData(segForm);
      fd.append("action", actionSeg);
      fd.append("nonce", nonce);

      fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            var errText =
              json && json.data
                ? json.data.message || json.data
                : "No se pudo guardar.";
            throw new Error(errText);
          }
          if (msg) {
            msg.textContent =
              json.data && json.data.message
                ? json.data.message
                : "Seguimiento guardado.";
            msg.classList.remove("error");
          }
          segForm.reset();
          showToast(
            "success",
            json.data && json.data.message
              ? json.data.message
              : "Seguimiento guardado.",
          );
          refreshCaseAfterSave(fd.get("ticket_pk"), segForm);
        })
        .catch(function (err) {
          if (msg) {
            msg.textContent =
              err && err.message ? err.message : "Error guardando seguimiento.";
            msg.classList.add("error");
          }
          showToast(
            "error",
            err && err.message ? err.message : "Error guardando seguimiento.",
          );
        })
        .finally(function () {
          if (btn) {
            btn.disabled = false;
          }
        });
    });

    root.addEventListener("submit", function (e) {
      var noteForm = e.target;
      if (
        !noteForm ||
        !noteForm.classList ||
        !noteForm.classList.contains("scm-note-form")
      ) {
        return;
      }
      e.preventDefault();

      var btn = noteForm.querySelector("button[type='submit']");
      var msg = noteForm.querySelector(".scm-seg-msg");
      if (btn) {
        btn.disabled = true;
      }
      if (msg) {
        msg.textContent = "Guardando...";
        msg.classList.remove("error");
      }

      var fd = new FormData(noteForm);
      fd.append("action", actionNote);
      fd.append("nonce", nonce);

      fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            var errText =
              json && json.data
                ? json.data.message || json.data
                : "No se pudo guardar.";
            throw new Error(errText);
          }
          if (msg) {
            msg.textContent =
              json.data && json.data.message
                ? json.data.message
                : "Nota guardada.";
            msg.classList.remove("error");
          }
          noteForm.reset();
          showToast(
            "success",
            json.data && json.data.message
              ? json.data.message
              : "Nota guardada.",
          );
          refreshCaseAfterSave(fd.get("ticket_pk"), noteForm);
        })
        .catch(function (err) {
          if (msg) {
            msg.textContent =
              err && err.message ? err.message : "Error guardando nota.";
            msg.classList.add("error");
          }
          showToast(
            "error",
            err && err.message ? err.message : "Error guardando nota.",
          );
        })
        .finally(function () {
          if (btn) {
            btn.disabled = false;
          }
        });
    });

    function bindCasePostForm(e, formClass, actionName, fallbackMessage) {
      var postForm = e.target;
      if (
        !postForm ||
        !postForm.classList ||
        !postForm.classList.contains(formClass)
      ) {
        return false;
      }
      e.preventDefault();
      var btn = postForm.querySelector("button[type='submit']");
      var msg = postForm.querySelector(".scm-seg-msg");
      if (btn) btn.disabled = true;
      if (msg) {
        msg.textContent = "Enviando...";
        msg.classList.remove("error");
      }

      var fd = new FormData(postForm);
      fd.append("action", actionName);
      fd.append("nonce", nonce);
      fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            var errText =
              json && json.data
                ? json.data.message || json.data
                : fallbackMessage;
            throw new Error(errText);
          }
          if (msg) {
            msg.textContent =
              json.data && json.data.message ? json.data.message : "Guardado.";
          }
          postForm.reset();
          showToast(
            "success",
            json.data && json.data.message ? json.data.message : "Guardado.",
          );
          refreshCaseAfterSave(fd.get("ticket_pk"), postForm);
        })
        .catch(function (err) {
          if (msg) {
            msg.textContent =
              err && err.message ? err.message : fallbackMessage;
            msg.classList.add("error");
          }
          showToast(
            "error",
            err && err.message ? err.message : fallbackMessage,
          );
        })
        .finally(function () {
          if (btn) btn.disabled = false;
        });
      return true;
    }

    // Ninguno ↔ otros destinatarios: exclusión mutua en cualquier fieldset de notificaciones
    root.addEventListener("change", function (e) {
      var input = e.target;
      if (!input || input.name !== "notify_recipients[]") return;
      var fieldset = input.closest(".scm-notify-targets");
      if (!fieldset) return;
      if (input.value === "none" && input.checked) {
        fieldset
          .querySelectorAll('input[name="notify_recipients[]"]')
          .forEach(function (cb) {
            if (cb !== input) cb.checked = false;
          });
      } else if (input.value !== "none" && input.checked) {
        var noneInput = fieldset.querySelector(
          'input[name="notify_recipients[]"][value="none"]',
        );
        if (noneInput) noneInput.checked = false;
      }
    });

    root.addEventListener("submit", function (e) {
      var adminForm = e.target;
      if (
        !adminForm ||
        !adminForm.classList ||
        !adminForm.classList.contains("scm-admin-ticket-form")
      ) {
        return;
      }
      e.preventDefault();

      var btn = adminForm.querySelector("button[type='submit']");
      var msg = adminForm.querySelector(".scm-seg-msg");
      if (btn) btn.disabled = true;
      if (msg) {
        msg.textContent = "Creando ticket...";
        msg.classList.remove("error");
      }

      var fd = new FormData(adminForm);
      fd.append("action", actionCrearTicketAdministrativo);
      fd.append("nonce", nonce);

      fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (r) {
          return r.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error(
              (json && json.data && json.data.message) ||
                "No se pudo crear el ticket.",
            );
          }
          var data = json.data || {};
          var okMsg = data.message || "Ticket creado correctamente.";
          if (msg) {
            msg.textContent = okMsg;
            msg.classList.remove("error");
          }
          showToast("success", okMsg);
          closeAdminTicketModal();

          var active = root.querySelector(".scm-tab-panel.active");
          if (active && active.id === "scm-panel-preventivas-pendientes") {
            var sppForm = root.querySelector("#spp_form");
            if (sppForm) {
              sppForm.dispatchEvent(
                new Event("submit", { bubbles: true, cancelable: true }),
              );
            }
          }
          if (active && active.id === "scm-panel-contratos-arrendamiento") {
            root.dispatchEvent(new CustomEvent("scm:contracts-refresh"));
          }
        })
        .catch(function (err) {
          if (msg) {
            msg.textContent =
              err && err.message ? err.message : "No se pudo crear el ticket.";
            msg.classList.add("error");
          }
          showToast(
            "error",
            err && err.message ? err.message : "No se pudo crear el ticket.",
          );
        })
        .finally(function () {
          if (btn) btn.disabled = false;
        });
    });

    root.addEventListener("submit", function (e) {
      if (
        bindCasePostForm(
          e,
          "scm-contact-update-form",
          actionContactsUpdate,
          "Error actualizando contactos.",
        )
      ) {
        return;
      }
      if (
        bindCasePostForm(
          e,
          "scm-property-location-form",
          actionSavePropertyLocation,
          "Error guardando ubicacion del inmueble.",
        )
      ) {
        return;
      }
      if (
        bindCasePostForm(
          e,
          "scm-close-ticket-form",
          actionCloseTicket,
          "Error cerrando ticket.",
        )
      ) {
        return;
      }
      if (
        bindCasePostForm(
          e,
          "scm-postpone-ticket-form",
          actionPostponeTicket,
          "Error postergando ticket.",
        )
      ) {
        return;
      }
      if (
        bindCasePostForm(
          e,
          "scm-ticket-response-form",
          actionTicketResponse,
          "Error enviando respuesta.",
        )
      ) {
        return;
      }
      if (
        bindCasePostForm(
          e,
          "scm-cotizacion-response-form",
          actionCotizacionResponse,
          "Error enviando respuesta de cotizacion.",
        )
      ) {
        return;
      }
      bindCasePostForm(
        e,
        "scm-trasladar-form",
        actionTrasladarCaso,
        "Error trasladando caso.",
      );
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      document
        .querySelectorAll("#scm-app.scm-wrap[data-scm-runtime]")
        .forEach(initRoot);
    });
  } else {
    document
      .querySelectorAll("#scm-app.scm-wrap[data-scm-runtime]")
      .forEach(initRoot);
  }
})();

/* ═══════════════════════════════════════════════════════════════════
   GUIDE MODAL – Tabs / CRUD (global helpers)
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  // ── Helpers ──────────────────────────────────────────────────────

  function guideRuntime() {
    var app = document.getElementById("scm-app");
    if (!app) return {};
    try {
      return JSON.parse(app.dataset.scmRuntime || "{}");
    } catch (e) {
      return {};
    }
  }

  function guideAjax(action, data, cb) {
    var rt = guideRuntime();
    var fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", rt.nonce || "");
    Object.keys(data || {}).forEach(function (k) {
      fd.append(k, data[k]);
    });
    fetch(rt.ajaxUrl || "api.php", { method: "POST", body: fd })
      .then(function (r) {
        return r.json();
      })
      .then(cb)
      .catch(function (e) {
        scmGoToast(e.message || "Error de red", "err");
      });
  }

  function scmGoToast(msg, type) {
    var el = document.getElementById("scm-go-toast");
    if (!el) {
      el = document.createElement("div");
      el.id = "scm-go-toast";
      el.className = "scm-go-toast";
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.className = "scm-go-toast " + (type || "ok");
    void el.offsetWidth;
    el.classList.add("show");
    clearTimeout(el._timer);
    el._timer = setTimeout(function () {
      el.classList.remove("show");
    }, 3000);
  }

  function esc(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  // ── Tab switching ─────────────────────────────────────────────────

  window.scmGoTab = function (tabKey) {
    var modal = document.getElementById("scm-guide-modal");
    if (!modal) return;

    modal.querySelectorAll(".scm-go-tab").forEach(function (btn) {
      btn.classList.toggle("active", btn.dataset.goTab === tabKey);
    });
    modal.querySelectorAll(".scm-go-pane").forEach(function (pane) {
      var active = pane.id === "scm-go-pane-" + tabKey;
      pane.classList.toggle("active", active);
      if (active) {
        pane.style.display = "block";
        if (tabKey === "correspondencias" && !pane.dataset.loaded) {
          pane.dataset.loaded = "1";
          scmGoLoad("gcd");
        } else if (tabKey === "respuestas" && !pane.dataset.loaded) {
          pane.dataset.loaded = "1";
          scmGoLoad("grt");
        } else if (tabKey === "articulos" && !pane.dataset.loaded) {
          pane.dataset.loaded = "1";
          scmGoLoadGacCats();
          scmGoLoad("gac");
        }
      } else {
        pane.style.display = "none";
      }
    });
  };

  // ── Sub-tabs (Estados) ────────────────────────────────────────────

  window.scmGoSubTab = function (sub) {
    ["com", "adm"].forEach(function (s) {
      var btn = document.getElementById("scm-go-subtab-" + s);
      var grid = document.getElementById("scm-go-grid-" + s);
      if (btn) btn.classList.toggle("active", s === sub);
      if (grid) grid.style.display = s === sub ? "" : "none";
    });
    scmGoSearch();
  };

  // ── Search in Estados ─────────────────────────────────────────────

  window.scmGoSearch = function () {
    var q = (document.getElementById("scm-go-search") || {}).value || "";
    q = q.toLowerCase().trim();
    var visible = 0;
    ["com", "adm"].forEach(function (s) {
      var grid = document.getElementById("scm-go-grid-" + s);
      if (!grid || grid.style.display === "none") return;
      grid.querySelectorAll(".scm-go-card").forEach(function (card) {
        var match = !q || (card.dataset.search || "").includes(q);
        card.style.display = match ? "" : "none";
        if (match) visible++;
      });
    });
    var noRes = document.getElementById("scm-go-no-results");
    if (noRes) noRes.style.display = q && visible === 0 ? "" : "none";
  };

  // ── Load GAC categories into select ──────────────────────────────

  function scmGoLoadGacCats() {
    var rt = guideRuntime();
    var action = (rt.actions || {})["guide_gac_cats"] || "";
    if (!action) return;
    var sel = document.getElementById("scm-gac-cat");
    if (!sel) return;
    guideAjax(action, {}, function (res) {
      if (!res || !res.success) return;
      var cats = (res.data && res.data.categories) || [];
      var current = sel.value;
      sel.innerHTML = '<option value="">\u2014 Categor\u00eda \u2014</option>';
      cats.forEach(function (c) {
        var opt = document.createElement("option");
        opt.value = c;
        opt.textContent = c;
        if (c === current) opt.selected = true;
        sel.appendChild(opt);
      });
    });
  }

  // ── Generic CRUD load ─────────────────────────────────────────────

  window.scmGoLoad = function (prefix) {
    var rt = guideRuntime();
    var actions = rt.actions || {};
    var action = actions["guide_" + prefix + "_read"] || "";
    if (!action) return;

    var container = document.getElementById("scm-" + prefix + "-result");
    if (!container) return;
    container.innerHTML =
      '<div class="scm-go-loading"><i class="fas fa-circle-notch fa-spin"></i></div>';

    var filters = {};
    var filtersForm = document.getElementById("scm-" + prefix + "-filters");
    if (filtersForm) {
      new FormData(filtersForm).forEach(function (v, k) {
        filters[k] = v;
      });
      // named inputs que no usen name=... (buscamos por ID)
    }
    // fallback: leer por IDs conocidos
    if (prefix === "gcd") {
      filters.clasificacion =
        (document.getElementById("scm-gcd-clas") || {}).value || "";
      filters.quien_corresponde =
        (document.getElementById("scm-gcd-resp") || {}).value || "";
      filters.busqueda =
        (document.getElementById("scm-gcd-bus") || {}).value || "";
    } else if (prefix === "grt") {
      filters.categoria =
        (document.getElementById("scm-grt-cat") || {}).value || "";
      filters.estado =
        (document.getElementById("scm-grt-est") || {}).value || "";
      filters.situacion =
        (document.getElementById("scm-grt-sit") || {}).value || "";
      filters.respuesta =
        (document.getElementById("scm-grt-res") || {}).value || "";
    } else if (prefix === "gac") {
      filters.categoria =
        (document.getElementById("scm-gac-cat") || {}).value || "";
      filters.busqueda =
        (document.getElementById("scm-gac-bus") || {}).value || "";
    }

    guideAjax(action, filters, function (res) {
      if (!res || !res.success) {
        container.innerHTML =
          '<p class="scm-go-empty-table">' +
          esc((res && res.data && res.data.message) || "Error al cargar.") +
          "</p>";
        return;
      }
      var rows = (res.data && res.data.rows) || [];
      if (!rows.length) {
        container.innerHTML =
          '<p class="scm-go-empty-table">Sin resultados.</p>';
        return;
      }
      if (prefix === "gcd") container.innerHTML = scmGoBuildGcdTable(rows);
      else if (prefix === "grt") container.innerHTML = scmGoBuildGrtTable(rows);
      else if (prefix === "gac") container.innerHTML = scmGoBuildGacTable(rows);
    });
  };

  // ── Reset filtros ─────────────────────────────────────────────────

  window.scmGoReset = function (prefix) {
    [
      "scm-gcd-clas",
      "scm-gcd-resp",
      "scm-gcd-bus",
      "scm-grt-cat",
      "scm-grt-est",
      "scm-grt-sit",
      "scm-grt-res",
      "scm-gac-cat",
      "scm-gac-bus",
    ].forEach(function (id) {
      var el = document.getElementById(id);
      if (el && id.startsWith("scm-" + prefix.slice(0, 3))) el.value = "";
    });
    scmGoLoad(prefix);
  };

  // ── Open edit modal ───────────────────────────────────────────────

  window.scmGoModal = function (prefix, row) {
    var overlay = document.getElementById("scm-" + prefix + "-edit");
    if (!overlay) return;
    var titleEl = document.getElementById("scm-" + prefix + "-edit-title");

    if (!row) {
      // New record
      if (titleEl)
        titleEl.innerHTML =
          prefix === "gcd"
            ? '<i class="fas fa-tools"></i> Nueva Correspondencia'
            : prefix === "grt"
              ? '<i class="fas fa-comment-dots"></i> Nueva Respuesta'
              : '<i class="fas fa-book-open"></i> Nuevo Artículo';
      if (prefix === "gcd") {
        document.getElementById("scm-gcd-id").value = "";
        document.getElementById("scm-gcd-desc").value = "";
        document.getElementById("scm-gcd-clas-m").selectedIndex = 0;
        document.getElementById("scm-gcd-resp-m").selectedIndex = 0;
        document.getElementById("scm-gcd-legal").value = "";
        document.getElementById("scm-gcd-reem").value = "";
        document.getElementById("scm-gcd-obs").value = "";
      } else if (prefix === "grt") {
        document.getElementById("scm-grt-id").value = "";
        document.getElementById("scm-grt-cat-m").selectedIndex = 0;
        document.getElementById("scm-grt-est").value = "";
        document.getElementById("scm-grt-sit").value = "";
        document.getElementById("scm-grt-res").value = "";
      } else if (prefix === "gac") {
        document.getElementById("scm-gac-id").value = "";
        document.getElementById("scm-gac-cat-m").value = "";
        document.getElementById("scm-gac-cont").value = "";
      }
    } else {
      // Edit record
      if (titleEl)
        titleEl.innerHTML =
          prefix === "gcd"
            ? '<i class="fas fa-tools"></i> Editar Correspondencia #' +
              esc(row._ID)
            : prefix === "grt"
              ? '<i class="fas fa-comment-dots"></i> Editar Respuesta #' +
                esc(row._ID)
              : '<i class="fas fa-book-open"></i> Editar Artículo #' +
                esc(row._ID);
      if (prefix === "gcd") {
        document.getElementById("scm-gcd-id").value = row._ID || "";
        document.getElementById("scm-gcd-desc").value = row.descripcion || "";
        setSelectVal("scm-gcd-clas-m", row.clasificacion);
        setSelectVal("scm-gcd-resp-m", row.quien_corresponde);
        document.getElementById("scm-gcd-legal").value =
          row.fundamento_legal || "";
        document.getElementById("scm-gcd-reem").value = row.reembolso || "";
        document.getElementById("scm-gcd-obs").value = row.observaciones || "";
      } else if (prefix === "grt") {
        document.getElementById("scm-grt-id").value = row._ID || "";
        setSelectVal("scm-grt-cat-m", row.categoria);
        document.getElementById("scm-grt-est").value = row.estado || "";
        document.getElementById("scm-grt-sit").value = row.situacion || "";
        document.getElementById("scm-grt-res").value = row.respuesta || "";
      } else if (prefix === "gac") {
        document.getElementById("scm-gac-id").value = row._ID || "";
        document.getElementById("scm-gac-cat-m").value = row.categoria || "";
        document.getElementById("scm-gac-cont").value = row.codigo_civil || "";
      }
    }

    overlay.style.display = "flex";
    overlay.setAttribute("aria-hidden", "false");
  };

  function setSelectVal(id, val) {
    var sel = document.getElementById(id);
    if (!sel) return;
    for (var i = 0; i < sel.options.length; i++) {
      if (sel.options[i].value === val) {
        sel.selectedIndex = i;
        return;
      }
    }
    sel.selectedIndex = 0;
  }

  // ── Save ──────────────────────────────────────────────────────────

  window.scmGoSave = function (prefix) {
    var rt = guideRuntime();
    var actions = rt.actions || {};
    var action = actions["guide_" + prefix + "_save"] || "";
    if (!action) return;

    var btn = document.getElementById("scm-" + prefix + "-save-btn");
    if (btn) {
      btn.disabled = true;
    }

    var data = {};
    if (prefix === "gcd") {
      data.id = document.getElementById("scm-gcd-id").value;
      data.descripcion = document.getElementById("scm-gcd-desc").value.trim();
      data.clasificacion = document.getElementById("scm-gcd-clas-m").value;
      data.quien_corresponde = document.getElementById("scm-gcd-resp-m").value;
      data.fundamento_legal = document.getElementById("scm-gcd-legal").value;
      data.reembolso = document.getElementById("scm-gcd-reem").value;
      data.observaciones = document.getElementById("scm-gcd-obs").value;

      if (!data.descripcion || !data.clasificacion || !data.quien_corresponde) {
        scmGoToast(
          "Situación, Clasificación y Responsable son requeridos.",
          "err",
        );
        if (btn) btn.disabled = false;
        return;
      }
    } else if (prefix === "grt") {
      data.id = document.getElementById("scm-grt-id").value;
      data.categoria = document.getElementById("scm-grt-cat-m").value;
      data.estado = document.getElementById("scm-grt-est").value;
      data.situacion = document.getElementById("scm-grt-sit").value;
      data.respuesta = document.getElementById("scm-grt-res").value.trim();

      if (!data.respuesta) {
        scmGoToast("El campo Respuesta es requerido.", "err");
        if (btn) btn.disabled = false;
        return;
      }
    } else if (prefix === "gac") {
      data.id = document.getElementById("scm-gac-id").value;
      data.categoria = document.getElementById("scm-gac-cat-m").value.trim();
      data.codigo_civil = document.getElementById("scm-gac-cont").value.trim();

      if (!data.categoria || !data.codigo_civil) {
        scmGoToast("Categoría y Contenido son requeridos.", "err");
        if (btn) btn.disabled = false;
        return;
      }
    }

    guideAjax(action, data, function (res) {
      if (btn) btn.disabled = false;
      if (res && res.success) {
        document.getElementById("scm-" + prefix + "-edit").style.display =
          "none";
        scmGoToast((res.data && res.data.message) || "Guardado.", "ok");
        scmGoLoad(prefix);
      } else {
        scmGoToast(
          (res && res.data && res.data.message) || "Error al guardar.",
          "err",
        );
      }
    });
  };

  // ── Delete ────────────────────────────────────────────────────────

  window.scmGoDel = function (prefix, id) {
    if (!confirm("¿Eliminar este registro?")) return;
    var rt = guideRuntime();
    var actions = rt.actions || {};
    var action = actions["guide_" + prefix + "_del"] || "";
    if (!action) return;

    guideAjax(action, { id: id }, function (res) {
      if (res && res.success) {
        scmGoToast((res.data && res.data.message) || "Eliminado.", "ok");
        scmGoLoad(prefix);
      } else {
        scmGoToast(
          (res && res.data && res.data.message) ||
            "Sin permisos para eliminar.",
          "err",
        );
      }
    });
  };

  // ── Copy respuesta ────────────────────────────────────────────────

  window.scmGoCopy = function (text) {
    if (navigator.clipboard) {
      navigator.clipboard
        .writeText(text)
        .then(function () {
          scmGoToast("Copiado al portapapeles.", "ok");
        })
        .catch(function () {
          scmGoCopyFallback(text);
        });
    } else {
      scmGoCopyFallback(text);
    }
  };
  function scmGoCopyFallback(text) {
    var ta = document.createElement("textarea");
    ta.value = text;
    ta.style.cssText = "position:fixed;top:-9999px;left:-9999px";
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand("copy");
      scmGoToast("Copiado.", "ok");
    } catch (e) {
      scmGoToast("No se pudo copiar.", "err");
    }
    document.body.removeChild(ta);
  }

  // ── Table builders ────────────────────────────────────────────────

  function scmGoBuildGcdTable(rows) {
    var html =
      '<table class="scm-go-table"><thead><tr>' +
      '<th style="width:35%">Situación</th>' +
      "<th>Clasificación</th>" +
      "<th>Responsable</th>" +
      '<th style="width:30%">Fundamento / Reembolso</th>' +
      '<th style="width:160px;text-align:center">Acciones</th>' +
      "</tr></thead><tbody>";

    rows.forEach(function (r) {
      html +=
        "<tr>" +
        "<td>" +
        esc(r.descripcion) +
        "</td>" +
        '<td><span class="scm-go-badge">' +
        esc(r.clasificacion) +
        "</span></td>" +
        "<td>" +
        esc(r.quien_corresponde) +
        "</td>" +
        "<td><small>" +
        esc(r.fundamento_legal || "—") +
        "</small></td>" +
        '<td><div class="scm-go-table-actions">' +
        '<button class="scm-go-btn scm-go-btn--secondary" style="padding:4px 8px" onclick=\'scmGoModal("gcd",' +
        JSON.stringify(r) +
        ')\'><i class="fas fa-edit"></i> Editar</button>' +
        '<button class="scm-go-btn scm-go-btn--danger" style="padding:4px 8px" onclick="scmGoDel(\'gcd\',' +
        esc(r._ID) +
        ')"><i class="fas fa-trash"></i> Eliminar</button>' +
        "</div></td>" +
        "</tr>";
    });
    return html + "</tbody></table>";
  }

  function scmGoBuildGrtTable(rows) {
    var html =
      '<table class="scm-go-table"><thead><tr>' +
      "<th>Categoría</th>" +
      "<th>Estado</th>" +
      "<th>Situación</th>" +
      "<th>Respuesta</th>" +
      '<th style="width:180px;text-align:center">Acciones</th>' +
      "</tr></thead><tbody>";

    rows.forEach(function (r) {
      var respText = r.respuesta || "";
      html +=
        "<tr>" +
        '<td><span class="scm-go-badge">' +
        esc(r.categoria) +
        "</span></td>" +
        "<td>" +
        esc(r.estado) +
        "</td>" +
        "<td>" +
        esc(r.situacion) +
        "</td>" +
        '<td style="max-width:280px"><div style="max-height:60px;overflow:hidden;font-size:.78rem">' +
        esc(respText).replace(/\n/g, "<br>") +
        "</div></td>" +
        '<td><div class="scm-go-table-actions">' +
        '<button class="scm-go-copy-btn" onclick=\'scmGoCopy(' +
        JSON.stringify(respText) +
        ')\'><i class="fas fa-copy"></i> Copiar</button>' +
        '<button class="scm-go-btn scm-go-btn--secondary" style="padding:4px 8px" onclick=\'scmGoModal("grt",' +
        JSON.stringify(r) +
        ')\'><i class="fas fa-edit"></i> Editar</button>' +
        '<button class="scm-go-btn scm-go-btn--danger" style="padding:4px 8px" onclick="scmGoDel(\'grt\',' +
        esc(r._ID) +
        ')"><i class="fas fa-trash"></i> Eliminar</button>' +
        "</div></td>" +
        "</tr>";
    });
    return html + "</tbody></table>";
  }

  function scmGoBuildGacTable(rows) {
    var html =
      '<table class="scm-go-table"><thead><tr>' +
      '<th style="width:40px;text-align:center">#</th>' +
      '<th style="width:200px">Categoría</th>' +
      "<th>Contenido</th>" +
      '<th style="width:160px;text-align:center">Acciones</th>' +
      "</tr></thead><tbody>";

    rows.forEach(function (r) {
      html +=
        "<tr>" +
        '<td style="text-align:center;font-weight:700">' +
        esc(r._ID) +
        "</td>" +
        '<td><span class="scm-go-badge">' +
        esc(r.categoria) +
        "</span></td>" +
        '<td><div style="max-height:80px;overflow-y:auto;font-size:.8rem">' +
        (r.codigo_civil || "") +
        "</div></td>" +
        '<td><div class="scm-go-table-actions">' +
        '<button class="scm-go-btn scm-go-btn--secondary" style="padding:4px 8px" onclick=\'scmGoModal("gac",' +
        JSON.stringify(r) +
        ')\'><i class="fas fa-edit"></i> Editar</button>' +
        '<button class="scm-go-btn scm-go-btn--danger" style="padding:4px 8px" onclick="scmGoDel(\'gac\',' +
        esc(r._ID) +
        ')"><i class="fas fa-trash"></i> Eliminar</button>' +
        "</div></td>" +
        "</tr>";
    });
    return html + "</tbody></table>";
  }

  // ── Bind tab clicks (delegated) ───────────────────────────────────
  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-go-tab]");
    if (btn) {
      e.preventDefault();
      scmGoTab(btn.dataset.goTab);
    }
  });
})();
