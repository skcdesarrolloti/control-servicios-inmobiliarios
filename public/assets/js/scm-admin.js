(function () {
  "use strict";

  function parseRuntime(root) {
    if (root && root._scmRuntime && typeof root._scmRuntime === "object") {
      return root._scmRuntime;
    }
    var raw = root.getAttribute("data-scm-runtime") || "";
    if (!raw) {
      return null;
    }

    try {
      var parsed = JSON.parse(raw);
      if (root) {
        root._scmRuntime = parsed;
      }
      return parsed;
    } catch (err) {
      console.error("SCM runtime parse error:", err);
      return null;
    }
  }

  function persistRuntime(root, runtime) {
    if (!root || !runtime || typeof runtime !== "object") {
      return runtime || null;
    }
    root._scmRuntime = runtime;
    try {
      root.setAttribute("data-scm-runtime", JSON.stringify(runtime));
    } catch (err) {
      console.warn("SCM runtime persist error:", err);
    }
    return runtime;
  }

  function pickFuncionarioOptions(data) {
    var filterOptions = data && data.filter_options ? data.filter_options : {};
    var cotizacionOptions =
      data && data.cotizacion_options ? data.cotizacion_options : {};
    if (
      Array.isArray(filterOptions.funcionarios) &&
      filterOptions.funcionarios.length
    ) {
      return filterOptions.funcionarios;
    }
    if (
      Array.isArray(cotizacionOptions.funcionarios) &&
      cotizacionOptions.funcionarios.length
    ) {
      return cotizacionOptions.funcionarios;
    }
    return [];
  }

  function hasFuncionarioOptions(runtime) {
    return (
      runtime &&
      Array.isArray(runtime.funcionarios) &&
      runtime.funcionarios.length > 0
    );
  }

  function filterPanelFuncionarios(runtime, rows) {
    rows = Array.isArray(rows) ? rows : [];
    var allowedIds =
      runtime &&
      runtime.config &&
      Array.isArray(runtime.config.calendar_allowed_employee_ids)
        ? runtime.config.calendar_allowed_employee_ids
        : [];
    var allowedMap = {};
    allowedIds.forEach(function (id) {
      var clean = String(id || "").trim();
      if (clean) {
        allowedMap[clean] = true;
      }
    });
    if (!Object.keys(allowedMap).length) {
      return rows;
    }
    return rows.filter(function (row) {
      var id = String(
        (row && (row.id || row.id_empleado || row.employee_id)) || "",
      ).trim();
      return !!allowedMap[id];
    });
  }

  function loadFuncionarioOptions(root) {
    var runtime = root ? parseRuntime(root) || {} : {};
    if (hasFuncionarioOptions(runtime)) {
      runtime.funcionarios = filterPanelFuncionarios(
        runtime,
        runtime.funcionarios,
      );
      persistRuntime(root, runtime);
      return Promise.resolve(runtime.funcionarios);
    }
    if (root && root._scmFuncionarioOptionsPromise) {
      return root._scmFuncionarioOptionsPromise;
    }
    var action =
      (runtime.actions && runtime.actions.dashboard_filter_options) ||
      "scm_dashboard_filter_options";
    var ajaxUrl = runtime.ajaxUrl || "api.php";
    var fd = new FormData();
    fd.set("action", action);
    fd.set("nonce", runtime.nonce || "");
    if (root) {
      root._scmFuncionarioOptionsPromise = fetch(ajaxUrl, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (json) {
          if (!json || !json.success || !json.data) {
            throw new Error("No se pudieron cargar los funcionarios.");
          }
          var funcionarios = filterPanelFuncionarios(
            runtime,
            pickFuncionarioOptions(json.data),
          );
          runtime.funcionarios = funcionarios;
          if (json.data.config && typeof json.data.config === "object") {
            runtime.config = Object.assign(
              runtime.config || {},
              json.data.config,
            );
          }
          persistRuntime(root, runtime);
          if (!funcionarios.length) {
            throw new Error("No hay funcionarios activos disponibles.");
          }
          return funcionarios;
        })
        .catch(function (err) {
          if (root) {
            root._scmFuncionarioOptionsPromise = null;
          }
          throw err;
        });
      return root._scmFuncionarioOptionsPromise;
    }
    return Promise.reject(new Error("No se pudo ubicar el panel del sistema."));
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
      return window.Swal.fire({
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
    return Promise.resolve();
  }

  var imageLightboxState = {
    overlay: null,
    image: null,
    caption: null,
    counter: null,
    original: null,
    prev: null,
    next: null,
    items: [],
    index: 0,
    previousFocus: null,
  };

  function isImageUrl(url) {
    var raw = String(url || "").trim();
    if (!raw) return false;
    if (/^(data:image\/|blob:)/i.test(raw)) return true;
    try {
      var parsed = new URL(raw, window.location.href);
      return /\.(?:jpe?g|png|gif|webp|bmp|svg|avif)$/i.test(
        parsed.pathname || "",
      );
    } catch (_error) {
      return /\.(?:jpe?g|png|gif|webp|bmp|svg|avif)(?:[?#].*)?$/i.test(raw);
    }
  }

  function imageLinkFromEventTarget(target) {
    if (!target || !target.closest) return null;
    var anchor = target.closest("a[href]");
    if (!anchor || anchor.closest(".scm-image-lightbox")) return null;
    if (
      anchor.hasAttribute("download") ||
      anchor.hasAttribute("data-scm-no-lightbox")
    ) {
      return null;
    }
    if (anchor.getAttribute("data-scm-lightbox") === "1") return anchor;
    return isImageUrl(anchor.getAttribute("href") || anchor.href)
      ? anchor
      : null;
  }

  function imageCaptionFromAnchor(anchor) {
    var image = anchor ? anchor.querySelector("img") : null;
    var text =
      (anchor && anchor.getAttribute("data-scm-lightbox-title")) ||
      (image && image.getAttribute("alt")) ||
      (anchor && anchor.getAttribute("title")) ||
      (anchor && anchor.textContent) ||
      "";
    text = String(text || "")
      .replace(/\s+/g, " ")
      .trim();
    if (text) return text;
    try {
      var parsed = new URL(anchor.href, window.location.href);
      var name = decodeURIComponent(
        (parsed.pathname || "").split("/").pop() || "",
      );
      return name || "Imagen adjunta";
    } catch (_error) {
      return "Imagen adjunta";
    }
  }

  function imageItemFromAnchor(anchor) {
    if (!anchor) return null;
    var image = anchor.querySelector("img");
    var url =
      anchor.getAttribute("href") || anchor.href || (image ? image.src : "");
    if (!url && image) url = image.src || image.currentSrc || "";
    if (!url) return null;
    if (!image && !isImageUrl(url)) return null;
    return {
      url: url,
      caption: imageCaptionFromAnchor(anchor),
    };
  }

  function imageGalleryScope(anchor) {
    if (!anchor || !anchor.closest) return document.body;
    return (
      anchor.closest(
        ".scm-cotizacion-media-grid, .scm-acta-gallery-grid, .scm-acta-detail-photos, .scm-case-submodal-body, #scm-case-body, .scm-case-dialog, .swal2-popup, .scm-tab-panel, #scm-app",
      ) || document.body
    );
  }

  function imageGalleryFromAnchor(anchor) {
    var scope = imageGalleryScope(anchor);
    var links = Array.prototype.slice.call(scope.querySelectorAll("a[href]"));
    var items = [];
    var seen = {};
    var activeIndex = 0;
    links.forEach(function (link) {
      var imageLink =
        link.getAttribute("data-scm-lightbox") === "1" ||
        isImageUrl(link.getAttribute("href") || link.href);
      if (
        !imageLink ||
        link.hasAttribute("download") ||
        link.hasAttribute("data-scm-no-lightbox")
      ) {
        return;
      }
      var item = imageItemFromAnchor(link);
      if (!item || seen[item.url]) return;
      seen[item.url] = true;
      if (link === anchor) activeIndex = items.length;
      items.push(item);
    });
    if (!items.length) {
      var single = imageItemFromAnchor(anchor);
      if (single) items.push(single);
      activeIndex = 0;
    }
    return { items: items, index: activeIndex };
  }

  function ensureImageLightbox() {
    if (imageLightboxState.overlay) return imageLightboxState.overlay;
    var overlay = document.createElement("div");
    overlay.className = "scm-image-lightbox";
    overlay.setAttribute("aria-hidden", "true");
    overlay.innerHTML =
      '<div class="scm-image-lightbox-dialog" role="dialog" aria-modal="true" aria-label="Vista ampliada de imagen">' +
      '<div class="scm-image-lightbox-head">' +
      '<div><strong>Vista de evidencia</strong><span class="scm-image-lightbox-counter"></span></div>' +
      '<div class="scm-image-lightbox-actions">' +
      '<a class="scm-image-lightbox-original" href="#" target="_blank" rel="noopener noreferrer">Abrir original</a>' +
      '<button type="button" class="scm-image-lightbox-close" aria-label="Cerrar visor">&times;</button>' +
      "</div>" +
      "</div>" +
      '<button type="button" class="scm-image-lightbox-nav scm-image-lightbox-prev" aria-label="Imagen anterior">&lsaquo;</button>' +
      '<figure class="scm-image-lightbox-stage"><img alt=""><figcaption></figcaption></figure>' +
      '<button type="button" class="scm-image-lightbox-nav scm-image-lightbox-next" aria-label="Imagen siguiente">&rsaquo;</button>' +
      "</div>";
    document.body.appendChild(overlay);
    imageLightboxState.overlay = overlay;
    imageLightboxState.image = overlay.querySelector(
      ".scm-image-lightbox-stage img",
    );
    imageLightboxState.caption = overlay.querySelector("figcaption");
    imageLightboxState.counter = overlay.querySelector(
      ".scm-image-lightbox-counter",
    );
    imageLightboxState.original = overlay.querySelector(
      ".scm-image-lightbox-original",
    );
    imageLightboxState.prev = overlay.querySelector(".scm-image-lightbox-prev");
    imageLightboxState.next = overlay.querySelector(".scm-image-lightbox-next");

    overlay.addEventListener("click", function (event) {
      if (event.target === overlay) closeImageLightbox();
    });
    overlay
      .querySelector(".scm-image-lightbox-close")
      .addEventListener("click", closeImageLightbox);
    imageLightboxState.prev.addEventListener("click", function () {
      moveImageLightbox(-1);
    });
    imageLightboxState.next.addEventListener("click", function () {
      moveImageLightbox(1);
    });
    return overlay;
  }

  function renderImageLightbox() {
    var state = imageLightboxState;
    var item = state.items[state.index] || {};
    var total = state.items.length;
    if (state.image) {
      state.image.src = item.url || "";
      state.image.alt = item.caption || "Imagen adjunta";
    }
    if (state.caption)
      state.caption.textContent = item.caption || "Imagen adjunta";
    if (state.counter)
      state.counter.textContent =
        total > 1 ? state.index + 1 + " de " + total : "1 imagen";
    if (state.original) state.original.href = item.url || "#";
    if (state.prev) state.prev.disabled = total < 2;
    if (state.next) state.next.disabled = total < 2;
  }

  function moveImageLightbox(delta) {
    var total = imageLightboxState.items.length;
    if (total < 2) return;
    imageLightboxState.index =
      (imageLightboxState.index + delta + total) % total;
    renderImageLightbox();
  }

  function openImageLightbox(items, index) {
    if (!Array.isArray(items) || !items.length) return;
    ensureImageLightbox();
    imageLightboxState.items = items;
    imageLightboxState.index = Math.max(
      0,
      Math.min(Number(index) || 0, items.length - 1),
    );
    imageLightboxState.previousFocus = document.activeElement;
    renderImageLightbox();
    imageLightboxState.overlay.classList.add("is-open");
    imageLightboxState.overlay.setAttribute("aria-hidden", "false");
    document.body.classList.add("scm-image-lightbox-open");
    var close = imageLightboxState.overlay.querySelector(
      ".scm-image-lightbox-close",
    );
    if (close && typeof close.focus === "function") close.focus();
  }

  function closeImageLightbox() {
    var state = imageLightboxState;
    if (!state.overlay || !state.overlay.classList.contains("is-open")) return;
    state.overlay.classList.remove("is-open");
    state.overlay.setAttribute("aria-hidden", "true");
    document.body.classList.remove("scm-image-lightbox-open");
    if (state.image) state.image.removeAttribute("src");
    if (
      state.previousFocus &&
      typeof state.previousFocus.focus === "function"
    ) {
      state.previousFocus.focus();
    }
  }

  function handleImageLightboxClick(event) {
    if (
      event.defaultPrevented ||
      event.button !== 0 ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey
    ) {
      return;
    }
    var anchor = imageLinkFromEventTarget(event.target);
    if (!anchor) return;
    var gallery = imageGalleryFromAnchor(anchor);
    if (!gallery.items.length) return;
    event.preventDefault();
    openImageLightbox(gallery.items, gallery.index);
  }

  function handleImageLightboxKeydown(event) {
    if (
      !imageLightboxState.overlay ||
      !imageLightboxState.overlay.classList.contains("is-open")
    )
      return;
    if (event.key === "Escape") {
      event.preventDefault();
      closeImageLightbox();
    } else if (event.key === "ArrowLeft") {
      event.preventDefault();
      moveImageLightbox(-1);
    } else if (event.key === "ArrowRight") {
      event.preventDefault();
      moveImageLightbox(1);
    }
  }

  function bindGlobalImageLightbox() {
    if (document.documentElement.dataset.scmImageLightboxBound === "1") return;
    document.documentElement.dataset.scmImageLightboxBound = "1";
    document.addEventListener("click", handleImageLightboxClick);
    document.addEventListener("keydown", handleImageLightboxKeydown);
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

      var ticketHeaderWrap = root.querySelector("#scm-tickets-header-wrap");
      var ticketStatusNav = root.querySelector(
        "#scm-ticket-status-nav, .scm-ticket-status-nav",
      );
      var isTicketPanel =
        [
          "scm-panel-abiertos",
          "scm-panel-mis-tickets",
          "scm-panel-postergados",
          "scm-panel-cerrados",
        ].indexOf(target) !== -1;

      if (ticketHeaderWrap) {
        ticketHeaderWrap.style.display = isTicketPanel ? "" : "none";
      }
      if (ticketStatusNav) {
        ticketStatusNav
          .querySelectorAll("[data-ticket-status-target]")
          .forEach(function (pill) {
            pill.classList.toggle(
              "active",
              pill.getAttribute("data-ticket-status-target") === target,
            );
          });
      }

      return true;
    }

    function preloadFuncionariosForActivePanel() {
      var activePanel = root.querySelector(".scm-tab-panel.active");
      if (
        !activePanel ||
        activePanel.id === "scm-panel-inicio" ||
        hasFuncionarioOptions(parseRuntime(root) || {})
      ) {
        return;
      }
      loadFuncionarioOptions(root).catch(function (err) {
        console.warn("SCM funcionarios preload:", (err && err.message) || err);
      });
    }

    root.querySelectorAll(".scm-open-topic-tab").forEach(function (tab) {
      tab.addEventListener("click", function () {
        activateOpenTopic(tab.getAttribute("data-open-target") || "");
        preloadFuncionariosForActivePanel();
      });
    });

    root
      .querySelectorAll("[data-ticket-status-target]")
      .forEach(function (btn) {
        btn.addEventListener("click", function (e) {
          e.preventDefault();
          var targetPanel = this.getAttribute("data-ticket-status-target");
          var nativeTabBtn = root.querySelector(
            '.scm-main-tabs .scm-tab[data-tab="' + targetPanel + '"]',
          );
          if (nativeTabBtn) {
            nativeTabBtn.click();
          } else {
            activateTab(targetPanel);
          }
        });
      });

    tabs.forEach(function (tab) {
      tab.addEventListener("click", function () {
        if (activateTab(tab.dataset.tab || "")) {
          preloadFuncionariosForActivePanel();
        }
      });
    });

    var initialTab = "";
    if (runtime && typeof runtime.initialTab === "string") {
      initialTab = runtime.initialTab.trim();
    }

    if (!initialTab) {
      try {
        var params = new URL(window.location.href).searchParams;
        initialTab = (params.get("scm_tab") || params.get("tab") || "").trim();
      } catch (_e) {}
    }

    if (initialTab && activateTab(initialTab)) {
      preloadFuncionariosForActivePanel();
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

    preloadFuncionariosForActivePanel();
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

  function openIframeModal(url, title, compact) {
    if (!url) {
      return;
    }
    document
      .querySelectorAll(".scm-iframe-overlay")
      .forEach(function (existingOverlay) {
        existingOverlay.remove();
      });
    var overlay = document.createElement("div");
    var compactMode = compact === true || compact === "1";
    var previouslyFocused = document.activeElement;
    overlay.className =
      "scm-iframe-overlay" + (compactMode ? " scm-iframe-overlay-compact" : "");
    overlay.style.zIndex = "2147483000";
    overlay.innerHTML =
      '<div class="scm-iframe-box" role="dialog" aria-modal="true" aria-label="' +
      escHtml(title || "Detalle") +
      '">' +
      '<div class="scm-iframe-toolbar">' +
      '<span class="scm-iframe-toolbar-title">' +
      escHtml(title) +
      "</span>" +
      '<div class="scm-iframe-toolbar-actions">' +
      '<a class="scm-iframe-open-tab" href="#" target="_blank" rel="noopener noreferrer">Ver en grande</a>' +
      '<button type="button" class="scm-iframe-close" aria-label="Cerrar">&times;</button>' +
      "</div>" +
      "</div>" +
      '<div class="scm-iframe-loader"><div class="scm-iframe-spinner"></div></div>' +
      '<iframe class="scm-iframe-frame" src="" allowfullscreen></iframe>' +
      "</div>";
    document.documentElement.appendChild(overlay);
    var iframeEl = overlay.querySelector(".scm-iframe-frame");
    var loaderEl = overlay.querySelector(".scm-iframe-loader");
    var openTabLink = overlay.querySelector(".scm-iframe-open-tab");
    if (openTabLink) {
      openTabLink.setAttribute("href", url);
    }
    iframeEl.addEventListener("load", function () {
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
      sub._scmReturnView = null;
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
      existing._scmReturnView = null;
      existing.classList.remove(
        "scm-case-submodal--property-history",
        "scm-case-submodal--property-tech",
        "scm-case-submodal--transfer",
        "scm-case-submodal--contacts",
        "scm-case-submodal--workflow",
        "scm-case-submodal--reply",
        "scm-case-submodal--postpone",
        "scm-case-submodal--quote",
        "scm-case-submodal--contract",
      );
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
      closeCaseSubmodalElement(wrap);
    }

    var closeBtn = wrap.querySelector(".scm-case-submodal-close");
    if (closeBtn) {
      closeBtn.addEventListener("click", closeSub);
    }
    wrap.addEventListener("click", function (event) {
      var target = event.target;
      var iframeBtn =
        target && typeof target.closest === "function"
          ? target.closest("[data-scm-open-iframe]")
          : null;
      if (!iframeBtn || !wrap.contains(iframeBtn)) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      openIframeModal(
        iframeBtn.dataset.iframeUrl || "",
        iframeBtn.dataset.iframeTitle || "",
        iframeBtn.hasAttribute("data-scm-compact-iframe"),
      );
    });

    return wrap;
  }

  function closeCaseSubmodalElement(sub) {
    if (!sub) return;
    if (typeof sub._scmReturnView === "function") {
      var returnView = sub._scmReturnView;
      sub._scmReturnView = null;
      returnView();
      return;
    }
    sub.classList.remove("open");
    sub.setAttribute("aria-hidden", "true");
  }

  function closeCaseSubmodal(modal) {
    var sub = modal ? modal.querySelector(".scm-case-submodal") : null;
    closeCaseSubmodalElement(sub);
  }

  function dispatchCaseActionSaved(root, ticketPk, fromNode) {
    if (!root || typeof window.CustomEvent !== "function") return;
    ticketPk = String(ticketPk || "").trim();
    if (ticketPk) {
      root.dispatchEvent(
        new CustomEvent("scm:case-action-saved", {
          detail: { ticketPk: ticketPk, fromNode: fromNode || root },
        }),
      );
      return;
    }
    root.dispatchEvent(new CustomEvent("scm:refresh-active-tab"));
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
    var propertyCode = getCasePropertyCode(
      caseBtn,
      sub.closest(".scm-case-modal"),
    );
    if (!propertyCode) {
      meta.textContent = "";
      meta.style.display = "none";
      return;
    }
    meta.textContent = "Código inmueble web: " + propertyCode;
    meta.style.display = "";
  }

  function renderCaseWorkflowMeta(caseBtn, modal, extraItems) {
    var propertyCode = getCasePropertyCode(caseBtn, modal) || "-";
    var logicalTicket = String(
      (caseBtn && caseBtn.dataset
        ? caseBtn.dataset.ticket || caseBtn.dataset.idTicket || ""
        : "") || "",
    )
      .replace(/^#+/, "")
      .trim();
    var statusLabel = String(
      caseBtn && caseBtn.dataset ? caseBtn.dataset.estado || "" : "",
    ).trim();
    var items = [
      '<span><span class="material-symbols-outlined">domain</span><b>Código inmueble web:</b> <strong>' +
        escHtml(propertyCode) +
        "</strong></span>",
    ];
    if (logicalTicket) {
      items.push(
        '<span><span class="material-symbols-outlined">confirmation_number</span><b>Caso activo</b> #' +
          escHtml(logicalTicket) +
          "</span>",
      );
    }
    if (statusLabel) {
      items.push(
        '<span><span class="material-symbols-outlined">radio_button_checked</span>' +
          escHtml(statusLabel) +
          "</span>",
      );
    }
    if (Array.isArray(extraItems)) {
      extraItems.forEach(function (item) {
        if (item) items.push(item);
      });
    }
    return '<div class="scm-transfer-meta-row scm-workflow-meta-row">' + items.join("") + "</div>";
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
      embedUrl: coords
        ? buildOpenStreetMapEmbedUrl(coords.lat, coords.lng)
        : "",
      coordsLabel: coords
        ? coords.lat.toFixed(6) + ", " + coords.lng.toFixed(6)
        : "",
    };
  }

  function normalizeCaseFieldLabel(value) {
    return String(value || "")
      .normalize ? String(value || "")
          .normalize("NFD")
          .replace(/[\u0300-\u036f]/g, "")
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, " ")
          .trim()
      : String(value || "")
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, " ")
          .trim();
  }

  function collectCaseSectionFields(fallbackNode, sectionId) {
    var out = {};
    if (!fallbackNode || !fallbackNode.querySelector || !sectionId) {
      return out;
    }
    var section = fallbackNode.querySelector("#" + sectionId);
    if (!section) {
      return out;
    }
    section.querySelectorAll(".scm-case-history-detail p").forEach(function (p) {
      var strong = p.querySelector("strong");
      if (!strong) {
        return;
      }
      var label = normalizeCaseFieldLabel(strong.textContent || "");
      if (!label) {
        return;
      }
      var value = (p.textContent || "").replace(strong.textContent || "", "");
      value = cleanCaseValue(value.replace(/^:\s*/, ""));
      if (value) {
        out[label] = value;
      }
    });
    return out;
  }

  function firstCaseSectionValue(fields, labels, fallback) {
    for (var i = 0; i < labels.length; i++) {
      var value = fields[normalizeCaseFieldLabel(labels[i])];
      if (cleanCaseValue(value)) {
        return cleanCaseValue(value);
      }
    }
    return cleanCaseValue(fallback || "");
  }

  function buildPropertyWebUrl(code) {
    code = cleanCaseValue(code);
    if (!code) {
      return "";
    }
    return (
      "https://sucasainmobiliaria.com.co/inmuebles/inmueble-" +
      encodeURIComponent(code)
    );
  }

  function formatPropertyMoney(value) {
    value = cleanCaseValue(value);
    if (!value) return "-";
    var digits = value.replace(/\D/g, "");
    var numeric = parseInt(digits || "0", 10);
    if (!isFinite(numeric)) return value;
    return "$" + numeric.toLocaleString("es-CO");
  }

  function formatPropertyArea(value) {
    value = cleanCaseValue(value);
    if (!value) return "-";
    return /\bm(?:2|²)\b/i.test(value) ? value : value + " m²";
  }

  function formatPropertyLevel(value) {
    value = cleanCaseValue(value);
    if (!value) return "-";
    return /^nivel\b/i.test(value) ? value : "Nivel " + value;
  }

  function renderPropertyMapPreview(info, label, webCode) {
    var codeLabel = cleanCaseValue(webCode) ? "#" + cleanCaseValue(webCode) : "";
    if (info && info.embedUrl) {
      return (
        '<div class="scm-property-mini-map"><iframe loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="' +
        escHtml(info.embedUrl) +
        '" title="Mapa del inmueble"></iframe></div>'
      );
    }
    return (
      '<div class="scm-property-mini-map scm-property-mini-map-fallback" role="img" aria-label="Mapa de referencia del inmueble">' +
      '<div class="scm-property-map-roads"><span></span><span></span><span></span></div>' +
      '<div class="scm-property-map-pin"><strong>' +
      escHtml(codeLabel ? "Inmueble " + codeLabel : "Inmueble") +
      '</strong><span class="material-symbols-outlined">store</span></div>' +
      '<div class="scm-property-map-address"><span class="material-symbols-outlined">location_on</span>' +
      escHtml(cleanCaseValue(label) || "Ubicacion pendiente por confirmar") +
      "</div>" +
      "</div>"
    );
  }

  function renderPropertyMetric(label, value, helper, icon, accent) {
    return (
      '<div class="scm-property-metric' +
      (accent ? " is-accent" : "") +
      '">' +
      '<span class="scm-property-metric-label">' +
      escHtml(label) +
      "</span>" +
      '<strong class="scm-property-metric-value">' +
      escHtml(cleanCaseValue(value) || "-") +
      "</strong>" +
      '<span class="scm-property-metric-help">' +
      (icon
        ? '<span class="material-symbols-outlined">' +
          escHtml(icon) +
          "</span>"
        : "") +
      escHtml(helper || "") +
      "</span>" +
      "</div>"
    );
  }

  function renderPropertyDetailItem(label, value, icon) {
    value = cleanCaseValue(value);
    if (!value) {
      return "";
    }
    return (
      '<div class="scm-property-detail-item">' +
      '<span class="scm-property-detail-label">' +
      (icon
        ? '<span class="material-symbols-outlined">' +
          escHtml(icon) +
          "</span>"
        : "") +
      escHtml(label) +
      "</span>" +
      '<strong class="scm-property-detail-value">' +
      escHtml(value) +
      "</strong>" +
      "</div>"
    );
  }

  function renderPropertyTechnicalCard(caseBtn, fallbackNode, options) {
    options = options || {};
    var fields = collectCaseSectionFields(fallbackNode, "scm-sec-inmueble");
    var info = buildCaseLocationInfo(caseBtn, fallbackNode);
    var payload = info.payload || {};
    var simiCode = firstCaseSectionValue(fields, ["ID inmueble"], options.inmuebleId);
    var webCode =
      cleanCaseValue(options.webId || payload.propertyCode) ||
      firstCaseSectionValue(fields, ["Codigo inmueble web", "Codigo"], "");
    var barrio = firstCaseSectionValue(fields, ["Barrio"], options.barrio);
    var direccion = firstCaseSectionValue(
      fields,
      ["Direccion"],
      options.direccion || payload.direccion,
    );
    var contrato = cleanCaseValue(options.contratoId || "");
    var estado = firstCaseSectionValue(fields, ["Estado"], "");
    var ciudad = firstCaseSectionValue(fields, ["Ciudad"], "");
    var tipo = firstCaseSectionValue(fields, ["Tipo"], "");
    var negocio = firstCaseSectionValue(fields, ["Negocio"], "");
    var destino = firstCaseSectionValue(fields, ["Destinacion"], "");
    var canon = formatPropertyMoney(firstCaseSectionValue(fields, ["Canon", "Precio arriendo"], ""));
    var admin = formatPropertyMoney(firstCaseSectionValue(fields, ["Administracion"], ""));
    var areaConst = formatPropertyArea(firstCaseSectionValue(fields, ["Area construida"], ""));
    var areaPrivada = formatPropertyArea(firstCaseSectionValue(fields, ["Area privada"], ""));
    var habitaciones = firstCaseSectionValue(fields, ["Habitaciones"], "");
    var banos = firstCaseSectionValue(fields, ["Banos"], "");
    var estrato = formatPropertyLevel(firstCaseSectionValue(fields, ["Estrato"], ""));
    var webUrl = buildPropertyWebUrl(webCode);
    var locationLabel =
      cleanCaseValue(payload.manualLocation) ||
      cleanCaseValue(payload.googleMapsUrl) ||
      direccion ||
      barrio ||
      "Sin ubicacion registrada";

    var html = '<div class="scm-sidebar-card scm-property-tech-card">';
    html +=
      '<div class="scm-property-tech-head"><div class="scm-property-tech-title"><span class="material-symbols-outlined">real_estate_agent</span><div><strong>Ficha Técnica de Inmueble</strong><small>Control inmobiliario</small></div></div><div class="scm-property-tech-badges">';
    if (webCode) {
      html += '<span class="scm-property-code-chip">#' + escHtml(webCode) + "</span>";
    }
    if (estado) {
      html += '<span class="scm-property-status-chip">' + escHtml(estado) + "</span>";
    }
    html += "</div></div>";

    html += '<div class="scm-property-metrics">';
    html += renderPropertyMetric("Canon arriendo", canon, "COP / Mes", "payments", true);
    html += renderPropertyMetric("Administración", admin, "Cuota admin", "receipt_long", false);
    html += renderPropertyMetric("Área const.", areaConst, "Superficie", "square_foot", false);
    html += renderPropertyMetric("Área privada", areaPrivada, "Área útil", "straighten", false);
    html += renderPropertyMetric("Habitaciones", habitaciones, "Ambientes", "bed", false);
    html += renderPropertyMetric("Baños", banos, "Servicios", "bathroom", false);
    html += renderPropertyMetric("Estrato", estrato, "Nivel", "domain", false);
    html += "</div>";

    html += '<div class="scm-property-detail-grid">';
    html += renderPropertyDetailItem("Inmueble simi", simiCode ? "#" + simiCode : "", "tag");
    html += renderPropertyDetailItem("Contrato asociado", contrato ? "#" + contrato : "", "contract");
    html += renderPropertyDetailItem("Barrio / sector", barrio, "location_city");
    html += renderPropertyDetailItem("Ciudad / municipio", ciudad, "map");
    html += renderPropertyDetailItem("Dirección completa base", direccion, "place");
    html += renderPropertyDetailItem("Tipo de inmueble", tipo, "apartment");
    html += renderPropertyDetailItem("Tipo de negocio", negocio, "handshake");
    html += renderPropertyDetailItem("Destinación autorizada", destino, "storefront");
    html += "</div>";

    html +=
      '<div class="scm-property-geo-block"><div class="scm-property-geo-head"><span><span class="material-symbols-outlined">map</span> Geolocalización y ubicación cartográfica</span><strong>' +
      (info.hasLocation ? "Georreferenciado" : "Sin georreferencia") +
      "</strong></div>";
    html +=
      '<div class="scm-property-geo-summary"><span class="material-symbols-outlined">location_on</span><span>' +
      escHtml(locationLabel) +
      "</span></div>";
    html += '<div class="scm-property-map-actions">';
    if (info.osmUrl) {
      html +=
        '<a class="scm-property-map-link" href="' +
        escHtml(info.osmUrl) +
        '" target="_blank" rel="noopener noreferrer"><span class="material-symbols-outlined">public</span> OpenStreetMap</a>';
    }
    if (info.googleUrl) {
      html +=
        '<a class="scm-property-map-link" href="' +
        escHtml(info.googleUrl) +
        '" target="_blank" rel="noopener noreferrer"><span class="material-symbols-outlined">travel_explore</span> Google Maps</a>';
    }
    html +=
      '<button type="button" class="scm-property-map-link" data-scm-view-property-map><span class="material-symbols-outlined">edit_location_alt</span> Actualizar ubicación</button>';
    html += "</div>";
    html += renderPropertyMapPreview(info, locationLabel, webCode);
    html += "</div>";

    html += '<div class="scm-property-footer-actions">';
    if (webUrl) {
      html +=
        '<a class="scm-property-web-btn" href="' +
        escHtml(webUrl) +
        '" target="_blank" rel="noopener noreferrer"><span class="material-symbols-outlined">open_in_new</span> Ver inmueble publicado en la web</a>';
    }
    html += "</div></div>";
    return html;
  }

  function openPropertyTechnicalSubmodal(modal, caseBtn, options) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    sub.classList.add("scm-case-submodal--property-tech");
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    if (title) {
      title.textContent = "Ficha Técnica de Inmueble";
    }
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML = renderPropertyTechnicalCard(caseBtn, modal, options || {});
      body.querySelectorAll("[data-scm-view-property-map]").forEach(function (mapBtn) {
        mapBtn.addEventListener("click", function () {
          openPropertyLocationEditor(modal, caseBtn, {
            returnToPropertyTechnical: true,
            propertyOptions: options || {},
          });
        });
      });
      body.querySelectorAll("[data-scm-open-section]").forEach(function (detailBtn) {
        detailBtn.addEventListener("click", function () {
          var targetId = detailBtn.getAttribute("data-scm-open-section") || "";
          if (targetId) {
            openCaseSubmodal(modal, detailBtn, targetId);
          }
        });
      });
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function renderCaseLocationPanel(caseBtn, fallbackNode, compact) {
    var info = buildCaseLocationInfo(caseBtn, fallbackNode);
    var payload = info.payload;
    var locationLabel =
      cleanCaseValue(payload.googleMapsUrl) ||
      cleanCaseValue(payload.direccion) ||
      "Sin ubicacion registrada";
    var html =
      '<section class="scm-case-location-panel scm-property-geo-block' +
      (compact ? " is-compact" : "") +
      '">' +
      '<div class="scm-property-geo-head"><span><span class="material-symbols-outlined">map</span> Geolocalización y ubicación cartográfica</span><strong>' +
      (info.hasLocation ? "Georreferenciado" : "Sin georreferencia") +
      "</strong></div>" +
      '<div class="scm-property-geo-summary"><span class="material-symbols-outlined">location_on</span><span>' +
      escHtml(locationLabel) +
      "</span></div>";

    html += '<div class="scm-property-map-actions">';
    if (info.osmUrl) {
      html +=
        '<a class="scm-property-map-link" href="' +
        escHtml(info.osmUrl) +
        '" target="_blank" rel="noopener noreferrer"><span class="material-symbols-outlined">public</span> OpenStreetMap</a>';
    }
    if (info.googleUrl) {
      html +=
        '<a class="scm-property-map-link" href="' +
        escHtml(info.googleUrl) +
        '" target="_blank" rel="noopener noreferrer"><span class="material-symbols-outlined">travel_explore</span> Google Maps</a>';
    }
    if (!info.osmUrl && !info.googleUrl) {
      html +=
        '<span class="scm-case-location-empty">No hay ubicacion registrada todavia.</span>';
    }
    html += "</div>";
    html += renderPropertyMapPreview(info, locationLabel, payload.propertyCode);

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
      '<form class="scm-property-location-form scm-property-location-editor" method="post" autocomplete="off">' +
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
      '<label class="scm-seg-field scm-property-location-field"><span>Actualizar enlace o coordenadas</span><textarea name="manual_location" rows="3" placeholder="Pega un enlace de Google Maps, OpenStreetMap, coordenadas lat,lng o una dirección normalizada...">' +
      escHtml(payload.googleMapsUrl || "") +
      "</textarea></label>" +
      '<div class="scm-property-location-foot"><p class="scm-muted"><span class="material-symbols-outlined">info</span> También tomamos como apoyo la dirección y las coordenadas actuales del inmueble cuando existen.</p>' +
      '<div class="scm-seg-actions"><button type="submit" class="scm-btn-primary"><span class="material-symbols-outlined">save</span> ' +
      (locationInfo.hasLocation
        ? "Guardar ubicacion manual"
        : "Agregar ubicacion manual") +
      '</button><span class="scm-seg-msg" aria-live="polite"></span></div></div>' +
      "</form>"
    );
  }

  function openPropertyLocationEditor(modal, caseBtn, options) {
    options = options || {};
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    if (options.returnToPropertyTechnical) {
      sub._scmReturnView = function () {
        openPropertyTechnicalSubmodal(
          modal,
          caseBtn,
          options.propertyOptions || {},
        );
      };
    }
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
    sub.classList.remove(
      "scm-case-submodal--transfer",
      "scm-case-submodal--contacts",
      "scm-case-submodal--property-tech",
      "scm-case-submodal--contract",
    );
    sub.classList.toggle(
      "scm-case-submodal--property-history",
      targetId === "scm-sec-hist-inmueble",
    );
    sub.classList.toggle(
      "scm-case-submodal--contract",
      targetId === "scm-sec-contrato",
    );

    var clone = source.cloneNode(true);
    clone.removeAttribute("id");
    clone.style.display = "";
    var cloneSuffix =
      String(Date.now()) + "-" + String(Math.floor(Math.random() * 10000));
    clone
      .querySelectorAll(".scm-case-history-list[id]")
      .forEach(function (list, index) {
        var oldId = list.getAttribute("id") || "";
        if (!oldId) {
          return;
        }
        var newId = oldId + "-clone-" + cloneSuffix + "-" + String(index);
        list.setAttribute("id", newId);
        clone
          .querySelectorAll('[data-target="' + oldId + '"]')
          .forEach(function (pager) {
            pager.setAttribute("data-target", newId);
          });
      });
    clone.querySelectorAll("[id]").forEach(function (el) {
      if (el.classList && el.classList.contains("scm-case-history-list")) {
        return;
      }
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
      if (targetId !== "scm-sec-contrato") {
        prependCaseLocationPanel(subBody, caseBtn, modal);
      }
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
              root.dispatchEvent(
                new CustomEvent("scm:case-action-saved", {
                  detail: { ticketPk: ticketPk, fromNode: sub },
                }),
              );
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
    var currentEmpId = String(caseBtn.dataset.empleadoId || "").trim();
    var funcionarios =
      runtime && Array.isArray(runtime.funcionarios)
        ? runtime.funcionarios
        : [];
    funcionarios = filterPanelFuncionarios(runtime || {}, funcionarios);
    var root =
      findRootFromNode(modal) ||
      findRootFromNode(caseBtn) ||
      document.querySelector("#scm-app.scm-wrap[data-scm-runtime]");
    var currentEmpName = String(
      caseBtn.dataset.empleado ||
        caseBtn.dataset.asignado ||
        caseBtn.dataset.nombreEmpleado ||
        "",
    ).trim();
    var currentDept = String(caseBtn.dataset.departamento || "").trim();
    var currentEmpLabel =
      currentEmpId || currentEmpName
        ? (currentEmpId ? currentEmpId + " - " : "") +
          (currentEmpName || "Sin nombre") +
          (currentDept ? " (" + currentDept + ")" : "")
        : "Sin funcionario asignado";
    var propertyCode = getCasePropertyCode(caseBtn, modal) || "-";
    var logicalTicket = String(
      caseBtn.dataset.ticket || caseBtn.dataset.idTicket || ticketPk || "",
    )
      .replace(/^#+/, "")
      .trim();
    var statusLabel = String(caseBtn.dataset.estado || "").trim();

    if (title) title.textContent = "Trasladar caso a otro funcionario";
    setCaseSubmodalMeta(sub, caseBtn);

    if (!funcionarios.length) {
      if (body) {
        body.innerHTML =
          '<div class="scm-inline-loader">' +
          "<strong>Cargando funcionarios...</strong>" +
          "<p>Estamos consultando los funcionarios activos antes de abrir el traslado.</p>" +
          "</div>";
        prependCaseLocationPanel(body, caseBtn, modal);
      }
      sub.classList.add("open");
      sub.setAttribute("aria-hidden", "false");

      loadFuncionarioOptions(root)
        .then(function (loadedFuncionarios) {
          var updatedRuntime = root
            ? parseRuntime(root) || runtime || {}
            : runtime || {};
          updatedRuntime.funcionarios = loadedFuncionarios;
          openTrasladarCasoEditor(modal, caseBtn, updatedRuntime);
        })
        .catch(function (err) {
          if (body) {
            body.innerHTML =
              '<p class="scm-error">No se pudieron cargar los funcionarios: ' +
              escHtml((err && err.message) || "error") +
              "</p>";
            prependCaseLocationPanel(body, caseBtn, modal);
          }
          scmNotify(
            "error",
            (err && err.message) || "No se pudieron cargar los funcionarios.",
            "Funcionarios",
          );
        });
      return;
    }

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
      sub.classList.add("scm-case-submodal--transfer");
      var transferNotifyTargets = renderNotifyTargets(["empleado"]).replace(
        'class="scm-notify-targets"',
        'class="scm-notify-targets scm-transfer-email-targets"',
      );
      body.innerHTML =
        '<form class="scm-trasladar-form scm-transfer-form-modern" method="post" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<div class="scm-transfer-meta-row">' +
        '<span><span class="material-symbols-outlined">domain</span><b>Código inmueble web:</b> <strong>' +
        escHtml(propertyCode) +
        "</strong></span>" +
        (logicalTicket
          ? '<span><span class="material-symbols-outlined">confirmation_number</span><b>Caso activo</b> #' +
            escHtml(logicalTicket) +
            "</span>"
          : "") +
        (statusLabel
          ? '<span><span class="material-symbols-outlined">radio_button_checked</span>' +
            escHtml(statusLabel) +
            "</span>"
          : "") +
        "</div>" +
        '<div class="scm-transfer-warning"><span class="material-symbols-outlined">info</span><p>Al confirmar el traslado, el seguimiento operativo y los compromisos de SLA pasarán al funcionario receptor. Esta acción quedará registrada en la bitácora de auditoría del inmueble.</p></div>' +
        '<div class="scm-transfer-current"><span>Funcionario actual a cargo:</span><strong><i></i>' +
        escHtml(currentEmpLabel) +
        "</strong></div>" +
        '<label class="scm-seg-field scm-transfer-field"><span>Nuevo funcionario responsable <em>*</em></span><div class="scm-transfer-select-wrap"><span class="material-symbols-outlined">person</span><select name="new_empleado_id" required>' +
        empOptions +
        "</select></div><small>El nuevo funcionario recibirá las alertas de trazabilidad de forma instantánea.</small></label>" +
        '<fieldset class="scm-notify-targets scm-notify-traslado scm-transfer-responsible-alert"><legend>Funcionario responsable</legend>' +
        '<label class="scm-seg-check"><input type="checkbox" name="notify_funcionario" value="1" checked> Notificar al funcionario responsable <small>Envío de alerta en la plataforma web, app móvil y recordatorio de agenda.</small></label>' +
        "</fieldset>" +
        transferNotifyTargets +
        '<label class="scm-seg-field scm-transfer-field"><span>Motivo o notas del traslado <em>Opcional</em></span><textarea name="observacion" rows="3" placeholder="Ej: Reasignación por turno laboral, especialidad en garantías o redistribución de carga..."></textarea></label>' +
        '<div class="scm-seg-actions">' +
        '<button type="button" class="scm-btn-secondary" data-scm-case-submodal-cancel>Cancelar</button>' +
        '<button type="submit" class="scm-btn-primary"><span class="material-symbols-outlined">sync_alt</span> Trasladar caso</button>' +
        '<span class="scm-seg-msg" aria-live="polite"></span>' +
        "</div>" +
        "</form>";
      var cancelBtn = body.querySelector("[data-scm-case-submodal-cancel]");
      if (cancelBtn) {
        cancelBtn.addEventListener("click", function () {
          closeCaseSubmodal(modal);
        });
      }
      var notifyFieldset = body.querySelector(".scm-transfer-email-targets");
      if (notifyFieldset) {
        var noneCheckbox = notifyFieldset.querySelector(
          '.scm-seg-check--none input[type="checkbox"]',
        );
        var recipientCheckboxes = Array.prototype.slice.call(
          notifyFieldset.querySelectorAll(
            'input[name="notify_recipients[]"]:not([value="none"])',
          ),
        );
        if (noneCheckbox) {
          noneCheckbox.addEventListener("change", function () {
            if (noneCheckbox.checked) {
              recipientCheckboxes.forEach(function (cb) {
                cb.checked = false;
              });
            }
          });
        }
        recipientCheckboxes.forEach(function (cb) {
          cb.addEventListener("change", function () {
            if (cb.checked && noneCheckbox) {
              noneCheckbox.checked = false;
            } else if (
              noneCheckbox &&
              recipientCheckboxes.every(function (item) {
                return !item.checked;
              })
            ) {
              noneCheckbox.checked = true;
            }
          });
        });
      }
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
        : "Agregar nota al caso";
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
          : "Escribe una nota interna para el caso...") +
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
      title.textContent = isPublicPqr
        ? "Postergar solicitud"
        : "Postergar caso";
    }
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      sub.classList.add(
        "scm-case-submodal--workflow",
        "scm-case-submodal--postpone",
      );
      var postponeNotifyTargets = renderNotifyTargets(
        isPublicPqr ? ["arrendatario", "propietario"] : [],
      ).replace(
        'class="scm-notify-targets"',
        'class="scm-notify-targets scm-workflow-email-targets"',
      );
      body.innerHTML =
        '<form class="scm-postpone-ticket-form scm-transfer-form-modern scm-workflow-form-modern" method="post" enctype="multipart/form-data" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        renderCaseWorkflowMeta(caseBtn, modal) +
        '<div class="scm-transfer-warning"><span class="material-symbols-outlined">info</span><p>Esta acci&oacute;n mantendr&aacute; ' +
        (isPublicPqr ? "la solicitud" : "el caso") +
        " abierta y marcar&aacute; el estado administrativo como Postergado.</p></div>" +
        '<label class="scm-seg-field scm-transfer-field"><span>Motivo de postergaci&oacute;n <em>*</em></span><textarea name="observacion" rows="6" required placeholder="' +
        (isPublicPqr
          ? "Describe por qu&eacute; se posterga la solicitud..."
          : "Describe por qu&eacute; se posterga el caso...") +
        '"></textarea></label>' +
        '<label class="scm-seg-field scm-transfer-field"><span>Imagenes / Evidencias <em>Opcional</em></span><input type="file" name="evidencia[]" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif,image/tiff" multiple></label>' +
        renderPasteEvidenceBox("evidencia[]") +
        renderTicketDocumentFields() +
        postponeNotifyTargets +
        '<div class="scm-seg-actions"><button type="button" class="scm-btn-secondary" data-scm-case-submodal-cancel>Cancelar</button><button type="submit" class="scm-btn-primary"><span class="material-symbols-outlined">event_repeat</span> Guardar postergaci&oacute;n</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";
      prependCaseLocationPanel(body, caseBtn, modal);
      var cancelBtn = body.querySelector("[data-scm-case-submodal-cancel]");
      if (cancelBtn) {
        cancelBtn.addEventListener("click", function () {
          closeCaseSubmodal(modal);
        });
      }
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
      "<ul data-scm-paste-list></ul>" +
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
    return caseHasCotizacion(caseBtn);
  }

  function caseCanGenerateRepairFollowup(caseBtn) {
    return !!(
      caseBtn &&
      caseHasCotizacion(caseBtn) &&
      caseCotizacionCanRespond(caseBtn) &&
      String(
        caseBtn.dataset.cotSeguimientoReparacionesDisponible || "",
      ).trim() === "1"
    );
  }

  function renderCotizacionInlineFields(hasCotizacion, cotizacionId) {
    if (!hasCotizacion) {
      return '<input type="hidden" name="estado_cotizacion" value="__keep__">';
    }
    return (
      '<section class="scm-cotizacion-response-inline" data-scm-cotizacion-response-fields>' +
      '<input type="hidden" name="id_cotizacion" value="' +
      escHtml(cotizacionId || "") +
      '">' +
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

  function isPreventivaCase(caseBtn) {
    if (!caseBtn || !caseBtn.dataset) return false;
    var values = [
      caseBtn.dataset.tabKey,
      caseBtn.dataset.ticketMode,
      caseBtn.dataset.asunto,
      caseBtn.dataset.tema,
      caseBtn.dataset.idRevisionPreventiva,
    ].join(" ");
    return /preventiva/i.test(values);
  }

  function isMaintenanceCase(caseBtn) {
    if (!caseBtn || !caseBtn.dataset) return false;
    var tabKey = String(caseBtn.dataset.tabKey || "")
      .trim()
      .toLowerCase();
    return tabKey === "mantenimiento";
  }

  function scmIsYesLike(value) {
    var text = String(value || "")
      .trim()
      .toLowerCase();
    return (
      ["si", "sí", "1", "true", "yes", "con daños", "con danos"].indexOf(
        text,
      ) !== -1
    );
  }

  function caseCanCreateMaintenanceQuote(caseBtn) {
    if (!caseBtn || !caseBtn.dataset || !isMaintenanceCase(caseBtn))
      return false;
    if (String(caseBtn.dataset.idRevisionCorrectiva || "").trim()) {
      return true;
    }
    if (!String(caseBtn.dataset.idRevisionPreventiva || "").trim()) {
      return false;
    }
    return scmIsYesLike(caseBtn.dataset.prevEncontroDanos || "");
  }

  function syncPreventivaNoAccessBox(scope) {
    if (!scope) return;
    var select = scope.querySelector('select[name="estado_administrativo"]');
    var input = scope.querySelector(
      'input[name="generar_acta_no_acceso_preventiva"]',
    );
    if (!input) return;
    if (input.checked && select && select.value !== "En espera de respuesta") {
      select.value = "En espera de respuesta";
    }
  }

  function initPreventivaNoAccessBox(scope) {
    if (!scope) return;
    var select = scope.querySelector('select[name="estado_administrativo"]');
    var input = scope.querySelector(
      'input[name="generar_acta_no_acceso_preventiva"]',
    );
    if (input && !input.dataset.scmNoAccessBind) {
      input.dataset.scmNoAccessBind = "1";
      input.addEventListener("change", function () {
        if (input.checked && select) {
          select.value = "En espera de respuesta";
        }
      });
    }
    if (select && !select.dataset.scmNoAccessBind) {
      select.dataset.scmNoAccessBind = "1";
      select.addEventListener("change", function () {
        if (
          input &&
          input.checked &&
          select.value !== "En espera de respuesta"
        ) {
          input.checked = false;
        }
      });
    }
    syncPreventivaNoAccessBox(scope);
  }

  function renderComposerAdminStateOptions() {
    return [
      "Nuevo",
      "En espera de respuesta",
      "Por inspeccionar",
      "Inspeccionado",
      "Cotizado",
      "En ejecucion por inmobiliaria",
      "En ejecucion por propietario",
      "En ejecucion por arrendatario",
      "En ejecucion por copropiedad",
      "Finalizado",
      "Trasladado",
      "Entregado",
      "Recibido",
      "Desistido",
    ]
      .map(function (state) {
        return (
          '<option value="' +
          escHtml(state) +
          '">' +
          escHtml(state) +
          "</option>"
        );
      })
      .join("");
  }

  function renderComposerResponseOptions(caseBtn, isPublicPqr, statusBucket) {
    var currentAdmin =
      caseBtn && caseBtn.dataset
        ? String(caseBtn.dataset.admin || "").trim()
        : "";
    if (!currentAdmin || currentAdmin === "-") currentAdmin = "Sin asignar";
    var canClose = statusBucket !== "cerrados";
    var adminSelect = isPublicPqr
      ? ""
      : '<label class="scm-composer-response-field scm-composer-response-field-admin">' +
        "<span>Estado administrativo <em>Actual: " +
        escHtml(currentAdmin) +
        "</em></span>" +
        '<select class="scm-select scm-composer-response-select" name="composer_estado_administrativo" data-scm-composer-admin-state>' +
        '<option value="__keep__">Sin cambio</option>' +
        renderComposerAdminStateOptions() +
        "</select>" +
        "</label>";
    var closeLabel = isPublicPqr
      ? "Cerrar solicitud al responder"
      : "Cerrar caso al responder";
    return (
      '<div class="scm-composer-response-options" data-scm-composer-response-options>' +
      adminSelect +
      (canClose
        ? '<label class="scm-composer-close-check"><input type="checkbox" name="composer_cerrar_ticket" value="1" data-scm-composer-close-ticket><span class="material-symbols-outlined text-[16px]">check_circle</span><span>' +
          closeLabel +
          "</span></label>"
        : "") +
      "</div>"
    );
  }

  function openTicketCompletionEditor(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    var root = findRootFromNode(caseBtn);
    if (!sub || !root) return;
    var runtime = parseRuntime(root) || {};
    var body = sub.querySelector(".scm-case-submodal-body");
    var actaRun = (sub._scmActaRun || 0) + 1;
    sub._scmActaRun = actaRun;
    sub.querySelector(".scm-case-submodal-title").textContent =
      "Acta de solución y satisfacción";
    setCaseSubmodalMeta(sub, caseBtn);
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
    body.innerHTML =
      '<div class="scm-acta"><p role="status">Cargando actas, firmantes y tarifa administrativa…</p></div>';
    var busy = false;
    sub._scmActaReturnFocus = modal.querySelector(
      "[data-scm-open-ticket-acta]",
    );
    if (!sub._scmActaFocusBound) {
      sub._scmActaFocusBound = true;
      sub.addEventListener("keydown", function (event) {
        if (!sub.classList.contains("open") || !sub.querySelector(".scm-acta"))
          return;
        if (event.key === "Escape") {
          event.preventDefault();
          event.stopPropagation();
          sub.querySelector(".scm-case-submodal-close").click();
        } else if (event.key === "Tab") {
          var controls = Array.from(
            sub.querySelectorAll(
              "button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary",
            ),
          ).filter(function (el) {
            return el.getClientRects().length > 0;
          });
          var first = controls[0],
            last = controls[controls.length - 1];
          if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
          } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
          }
        }
      });
      sub
        .querySelector(".scm-case-submodal-close")
        .addEventListener("click", function () {
          if (sub.querySelector(".scm-acta") && sub._scmActaReturnFocus)
            sub._scmActaReturnFocus.focus();
        });
    }

    function message(text, error, scroll) {
      var target = body.querySelector("[data-acta-message]");
      if (!target) return;
      target.textContent = text;
      target.classList.toggle("is-error", !!error);
      target.setAttribute("role", error ? "alert" : "status");
      if (scroll !== false) target.scrollIntoView({ block: "nearest" });
    }

    function request(operation, data) {
      if (busy) return Promise.resolve();
      busy = true;
      var controller = new AbortController();
      var timeout = window.setTimeout(function () {
        controller.abort();
      }, 30000);
      data = data || new FormData();
      data.set("action", "scm_ticket_acta");
      data.set("nonce", runtime.nonce || "");
      data.set("ticket_pk", caseBtn.dataset.ticketPk || "");
      data.set("operation", operation);
      if (
        ["read", "create"].includes(operation) &&
        String(caseBtn.dataset.cotizacionId || "").trim() &&
        String(caseBtn.dataset.cotEstado || "")
          .trim()
          .toLowerCase() === "aprobada"
      ) {
        data.set("source_flow", "approved_quote");
        data.set(
          "source_cotizacion_id",
          String(caseBtn.dataset.cotizacionId || "").trim(),
        );
      }
      body.querySelectorAll("button").forEach(function (button) {
        button.disabled = true;
      });
      message(
        operation === "read" ? "Cargando…" : "Guardando, espera por favor…",
        false,
      );
      return fetch(runtime.ajaxUrl || "api.php", {
        method: "POST",
        body: data,
        credentials: "same-origin",
        signal: controller.signal,
        headers: { Accept: "application/json" },
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (json) {
          if (sub._scmActaRun !== actaRun) return;
          if (!json || !json.success || !json.data)
            throw new Error(
              (json && json.data && json.data.message) ||
                "No se pudo completar la operación.",
            );
          if (operation === "create" && json.data.redirect_url) {
            sub.dataset.scmActaDashboardUrl = json.data.redirect_url;
            if (json.data.message) {
              scmNotify(
                json.data.queued === false ? "error" : "success",
                json.data.message,
                "Acta de satisfacción",
              );
            }
          }
          body.innerHTML = json.data.html;
          bind();
          if (json.data.message)
            message(json.data.message, json.data.queued === false);
          if (operation !== "read")
            root.dispatchEvent(new CustomEvent("scm:refresh-active-tab"));
          if (operation === "read") {
            var first = body.querySelector("select, a, button");
            if (first) first.focus();
          }
        })
        .catch(function (error) {
          if (sub._scmActaRun !== actaRun) return;
          if (!body.querySelector("[data-acta-message]")) {
            body.innerHTML =
              '<div class="scm-acta"><p data-acta-message></p><button type="button" class="scm-acta-button" data-acta-retry>Reintentar carga</button></div>';
            body
              .querySelector("[data-acta-retry]")
              .addEventListener("click", function () {
                request("read");
              });
          }
          message(
            error.name === "AbortError"
              ? "La solicitud tardó demasiado. Cierra y vuelve a consultar las actas antes de repetir la operación."
              : error.message ||
                  "No se pudo conectar. Consulta el acta antes de volver a generar.",
            true,
          );
        })
        .finally(function () {
          window.clearTimeout(timeout);
          busy = false;
          if (sub._scmActaRun !== actaRun) return;
          body.querySelectorAll("button").forEach(function (button) {
            button.disabled = false;
          });
        });
    }

    function bind() {
      var MAX_PHOTOS_PER_DAMAGE = 4;
      var MAX_PHOTOS_PER_ACT = 12;
      var MAX_SOURCE_PHOTO_BYTES = 25 * 1024 * 1024;

      function photoError(text, input) {
        if (input) input.focus();
        message(text, true, false);
        scmNotify("error", text, "Fotos del acta");
      }

      function selectedPhotos(input) {
        return Array.isArray(input._actaFiles)
          ? input._actaFiles.slice()
          : Array.from(input.files || []);
      }

      function syncPhotoInput(input, files) {
        if (typeof DataTransfer === "undefined") return false;
        var transfer = new DataTransfer();
        files.forEach(function (file) {
          transfer.items.add(file);
        });
        input.files = transfer.files;
        input._actaFiles = files.slice();
        return true;
      }

      function totalSelectedPhotos(form, exceptInput) {
        return Array.from(form.querySelectorAll("[data-acta-photos]")).reduce(
          function (sum, input) {
            return (
              sum + (input === exceptInput ? 0 : selectedPhotos(input).length)
            );
          },
          0,
        );
      }

      function photoKey(file) {
        return [file.name, file.size, file.type, file.lastModified].join("|");
      }

      function photoPreview(input) {
        (input._actaPreviewUrls || []).forEach(function (url) {
          URL.revokeObjectURL(url);
        });
        input._actaPreviewUrls = [];
        var preview = input
          .closest("[data-acta-item]")
          .querySelector("[data-acta-photo-preview]");
        var files = selectedPhotos(input);
        preview.innerHTML = "";
        files.forEach(function (file, index) {
          var url = URL.createObjectURL(file);
          input._actaPreviewUrls.push(url);
          var figure = document.createElement("figure");
          var image = document.createElement("img");
          image.src = url;
          image.alt = "Vista previa de evidencia " + (index + 1);
          var caption = document.createElement("figcaption");
          caption.textContent =
            file.name +
            " · " +
            Math.max(1, Math.round(file.size / 1024)) +
            " KB";
          var remove = document.createElement("button");
          remove.type = "button";
          remove.className = "scm-acta-photo-remove";
          remove.dataset.actaRemovePhoto = String(index);
          remove.setAttribute(
            "aria-label",
            "Quitar foto " + (index + 1) + ": " + file.name,
          );
          remove.innerHTML = '<span aria-hidden="true">×</span>';
          figure.appendChild(image);
          figure.appendChild(caption);
          figure.appendChild(remove);
          preview.appendChild(figure);
        });
        preview.setAttribute(
          "aria-label",
          files.length
            ? files.length + " fotos seleccionadas"
            : "Sin fotos seleccionadas",
        );
      }

      function addPhotos(input, incoming) {
        var form = input.closest("form");
        var current = Array.isArray(input._actaFiles)
          ? input._actaFiles.slice()
          : [];
        var known = new Set(current.map(photoKey));
        var additions = [];
        for (var index = 0; index < incoming.length; index++) {
          var file = incoming[index];
          if (
            !["image/jpeg", "image/png", "image/webp"].includes(file.type) ||
            file.size > MAX_SOURCE_PHOTO_BYTES
          ) {
            syncPhotoInput(input, current);
            photoError(
              "Usa únicamente fotos JPG, PNG o WebP de máximo 25 MB cada una.",
              input,
            );
            return false;
          }
          var key = photoKey(file);
          if (!known.has(key)) {
            known.add(key);
            additions.push(file);
          }
        }
        var next = current.concat(additions);
        if (next.length > MAX_PHOTOS_PER_DAMAGE) {
          syncPhotoInput(input, current);
          photoError(
            "Este daño admite máximo 4 fotos. Ya tienes " +
              current.length +
              " y estás intentando agregar " +
              additions.length +
              ".",
            input,
          );
          return false;
        }
        if (
          totalSelectedPhotos(form, input) + next.length >
          MAX_PHOTOS_PER_ACT
        ) {
          syncPhotoInput(input, current);
          photoError(
            "El acta admite máximo 12 fotos en total. Quita alguna foto antes de agregar otra.",
            input,
          );
          return false;
        }
        if (!syncPhotoInput(input, next)) {
          input.value = "";
          input._actaFiles = [];
          photoPreview(input);
          photoError(
            "Tu navegador no permite combinar o quitar fotos. Actualiza Chrome e inténtalo nuevamente.",
            input,
          );
          return false;
        }
        photoPreview(input);
        return true;
      }

      function pastedPhotos(event) {
        var clipboard = event.clipboardData || window.clipboardData;
        var items =
          clipboard && clipboard.items ? Array.from(clipboard.items) : [];
        return items.reduce(function (files, item, index) {
          if (!item || !/^image\//i.test(item.type || "")) return files;
          var blob = item.getAsFile();
          if (!blob) return files;
          var subtype = (blob.type.split("/")[1] || "png").replace(
            "jpeg",
            "jpg",
          );
          files.push(
            new File(
              [blob],
              "captura-" + Date.now() + "-" + (index + 1) + "." + subtype,
              {
                type: blob.type || "image/png",
                lastModified: Date.now(),
              },
            ),
          );
          return files;
        }, []);
      }
      function decodePhoto(file) {
        if (window.createImageBitmap) {
          return createImageBitmap(file, {
            imageOrientation: "from-image",
          }).catch(function () {
            return createImageBitmap(file);
          });
        }
        return new Promise(function (resolve, reject) {
          var url = URL.createObjectURL(file),
            image = new Image();
          image.onload = function () {
            URL.revokeObjectURL(url);
            resolve(image);
          };
          image.onerror = function () {
            URL.revokeObjectURL(url);
            reject(new Error("No se pudo leer " + file.name + "."));
          };
          image.src = url;
        });
      }
      function compressPhoto(file) {
        if (
          !["image/jpeg", "image/png", "image/webp"].includes(file.type) ||
          file.size > 25 * 1024 * 1024
        ) {
          return Promise.reject(
            new Error(
              "Usa fotos JPG, PNG o WebP de máximo 25 MB antes de comprimir.",
            ),
          );
        }
        return decodePhoto(file).then(function (image) {
          var width = image.width || image.naturalWidth,
            height = image.height || image.naturalHeight;
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
            canvas.toBlob(
              function (blob) {
                blob
                  ? resolve(
                      new File(
                        [blob],
                        file.name.replace(/\.[^.]+$/, "") + ".jpg",
                        { type: "image/jpeg", lastModified: Date.now() },
                      ),
                    )
                  : reject(
                      new Error("No se pudo comprimir " + file.name + "."),
                    );
              },
              "image/jpeg",
              0.78,
            );
          });
        });
      }
      function compressedFormData(form) {
        var data = new FormData(form),
          inputs = Array.from(form.querySelectorAll("[data-acta-photos]"));
        var total = inputs.reduce(function (sum, input) {
          return sum + (input.files ? input.files.length : 0);
        }, 0);
        if (total > 12)
          return Promise.reject(
            new Error("El acta admite máximo 12 fotos en total."),
          );
        return Promise.all(
          inputs.map(function (input) {
            var name = input.name;
            data.delete(name);
            return Promise.all(
              Array.from(input.files || []).map(compressPhoto),
            ).then(function (files) {
              files.forEach(function (file) {
                data.append(name, file, file.name);
              });
            });
          }),
        ).then(function () {
          var bytes = Array.from(data.entries()).reduce(function (sum, entry) {
            return sum + (entry[1] instanceof File ? entry[1].size : 0);
          }, 0);
          if (bytes > 8 * 1000 * 1000)
            throw new Error(
              "Las fotos superan 8 MB después de comprimir. Retira algunas evidencias.",
            );
          return data;
        });
      }
      var form = body.querySelector("[data-acta-create]");
      if (form) {
        var signer = form.querySelector("[data-acta-signer]");
        signer.addEventListener("change", function () {
          var option = signer.selectedOptions[0];
          form.querySelector("[data-acta-signer-name]").value =
            option.dataset.name || "";
          form.querySelector("[data-acta-signer-email]").value =
            option.dataset.email || "";
          form.querySelector("[data-acta-signer-phone]").value =
            option.dataset.phone || "";
        });
        function total() {
          var fee = Number(form.querySelector("[data-acta-fee]").value) || 0;
          var transport =
            Number(form.querySelector("[data-acta-transport]").value) || 0;
          var totalField = form.querySelector("[data-acta-total]");
          if (!totalField) return;
          totalField.textContent = new Intl.NumberFormat("es-CO", {
            style: "currency",
            currency: "COP",
            maximumFractionDigits: 0,
          }).format(fee + transport);
        }
        form.querySelector("[data-acta-fee]").addEventListener("input", total);
        total();
        form
          .querySelector("[data-acta-add-item]")
          .addEventListener("click", function () {
            var items = form.querySelector("[data-acta-items]");
            if (items.children.length >= 30) {
              message("El acta admite hasta 30 daños y soluciones.", true);
              return;
            }
            var item = items.firstElementChild.cloneNode(true);
            item.querySelectorAll("textarea").forEach(function (field) {
              field.name = field.name.replace(
                /items\[\d+\]/,
                "items[" + sequence + "]",
              );
              field.value = "";
            });
            var photoInput = item.querySelector("[data-acta-photos]");
            photoInput.name = "acta_item_photos_" + sequence + "[]";
            photoInput.value = "";
            photoInput._actaFiles = [];
            var photoHelp = item.querySelector("[data-acta-photo-help]");
            var helpId = "acta-photo-help-" + sequence;
            if (photoHelp) photoHelp.id = helpId;
            photoInput.setAttribute("aria-describedby", helpId);
            item.querySelector("[data-acta-photo-preview]").innerHTML = "";
            sequence++;
            items.appendChild(item);
            item.querySelector("textarea").focus();
          });
        form.addEventListener("click", function (event) {
          var photoButton = event.target.closest("[data-acta-remove-photo]");
          if (photoButton) {
            var photoItem = photoButton.closest("[data-acta-item]");
            var photoInput = photoItem.querySelector("[data-acta-photos]");
            var files = selectedPhotos(photoInput);
            var removedIndex = Number(photoButton.dataset.actaRemovePhoto);
            if (
              Number.isInteger(removedIndex) &&
              removedIndex >= 0 &&
              removedIndex < files.length
            ) {
              files.splice(removedIndex, 1);
              if (syncPhotoInput(photoInput, files)) {
                photoPreview(photoInput);
              } else {
                photoError(
                  "No se pudo quitar la foto en este navegador. Actualiza Chrome e inténtalo nuevamente.",
                  photoInput,
                );
              }
            }
            return;
          }
          var button = event.target.closest("[data-acta-remove-item]");
          if (!button) return;
          if (form.querySelectorAll("[data-acta-item]").length === 1) {
            message("Debes conservar al menos un daño y su solución.", true);
            return;
          }
          var item = button.closest("[data-acta-item]");
          var input = item.querySelector("[data-acta-photos]");
          (input._actaPreviewUrls || []).forEach(function (url) {
            URL.revokeObjectURL(url);
          });
          item.remove();
        });
        form.addEventListener("change", function (event) {
          if (event.target.matches("[data-acta-photos]"))
            addPhotos(event.target, Array.from(event.target.files || []));
        });
        form.addEventListener("paste", function (event) {
          var pasteButton =
            event.target.closest &&
            event.target.closest("[data-acta-photo-paste]");
          if (!pasteButton) return;
          var input = pasteButton
            .closest("[data-acta-item]")
            .querySelector("[data-acta-photos]");
          var files = pastedPhotos(event);
          event.preventDefault();
          if (!files.length) {
            photoError(
              "No se encontró una imagen en el portapapeles. Copia una captura y vuelve a presionar Ctrl+V.",
              input,
            );
            return;
          }
          addPhotos(input, files);
        });
        form.addEventListener("submit", function (event) {
          event.preventDefault();
          message("Comprimiendo las fotos antes de guardar…", false);
          compressedFormData(form)
            .then(function (data) {
              return request("create", data);
            })
            .catch(function (error) {
              photoError(error.message || "No se pudieron preparar las fotos.");
            });
        });
      }
      body.querySelectorAll("[data-acta-preview]").forEach(function (link) {
        link.addEventListener("click", function (event) {
          event.preventDefault();
          openIframeModal(link.href, "Acta de satisfacción");
        });
      });
      body.querySelectorAll("[data-acta-resend]").forEach(function (button) {
        button.addEventListener("click", function () {
          var fd = new FormData();
          fd.set("act_id", button.dataset.actaResend);
          request("resend", fd);
        });
      });
      body.querySelectorAll("[data-acta-archive]").forEach(function (button) {
        button.addEventListener("click", function (event) {
          event.preventDefault();
          event.stopPropagation();
          var actId = button.dataset.actaArchive || "";
          function archiveAct(reason) {
            reason = String(reason || "").trim();
            if (!reason) {
              scmNotify(
                "error",
                "Escribe un motivo para archivar el acta.",
                "Acta de satisfacción",
              );
              return;
            }
            var fd = new FormData();
            fd.set("act_id", actId);
            fd.set("reason", reason);
            request("archive", fd);
          }
          if (window.Swal && typeof window.Swal.fire === "function") {
            window.Swal.fire({
              icon: "warning",
              title: "Archivar acta #" + actId + "?",
              text: "El acta saldrá de pendientes. No se cerrará el ticket ni se generará cobro.",
              input: "textarea",
              inputLabel: "Motivo",
              showCancelButton: true,
              confirmButtonText: "Archivar",
              cancelButtonText: "Cancelar",
              confirmButtonColor: "#b42318",
              inputValidator: function (value) {
                return String(value || "").trim()
                  ? undefined
                  : "Escribe el motivo del archivo.";
              },
            }).then(function (result) {
              if (result && result.isConfirmed) archiveAct(result.value);
            });
            return;
          }
          if (
            window.confirm(
              "¿Archivar acta #" +
                actId +
                "? No se cerrará el ticket ni se generará cobro.",
            )
          ) {
            archiveAct(window.prompt("Motivo para archivar:", "") || "");
          }
        });
      });
      body.querySelectorAll("[data-acta-delete]").forEach(function (button) {
        button.addEventListener("click", function (event) {
          event.preventDefault();
          event.stopPropagation();
          var actId = button.dataset.actaDelete || "";
          function deleteAct() {
            var fd = new FormData();
            fd.set("act_id", actId);
            request("delete", fd);
          }
          if (window.Swal && typeof window.Swal.fire === "function") {
            window.Swal.fire({
              icon: "warning",
              title: "Eliminar acta #" + actId + "?",
              text: "Esta acción borra permanentemente el acta y retira sus soportes internos asociados.",
              input: "text",
              inputLabel: "Escribe ELIMINAR para confirmar",
              showCancelButton: true,
              confirmButtonText: "Eliminar",
              cancelButtonText: "Cancelar",
              confirmButtonColor: "#b42318",
              inputValidator: function (value) {
                return String(value || "")
                  .trim()
                  .toUpperCase() === "ELIMINAR"
                  ? undefined
                  : "Escribe ELIMINAR para confirmar.";
              },
            }).then(function (result) {
              if (result && result.isConfirmed) deleteAct();
            });
            return;
          }
          if (
            window.confirm(
              "¿Eliminar acta #" +
                actId +
                "? Esta acción no se puede deshacer.",
            )
          ) {
            var typed =
              window.prompt("Escribe ELIMINAR para confirmar:", "") || "";
            if (typed.trim().toUpperCase() === "ELIMINAR") deleteAct();
          }
        });
      });
      body
        .querySelectorAll("[data-acta-cancel]")
        .forEach(function (cancelForm) {
          cancelForm.addEventListener("submit", function (event) {
            event.preventDefault();
            if (
              !window.confirm(
                "¿Anular esta acta? Su enlace dejará de permitir la firma y podrás generar una nueva versión.",
              )
            )
              return;
            var fd = new FormData(cancelForm);
            fd.set("act_id", cancelForm.dataset.actaCancel);
            request("cancel", fd);
          });
        });
    }
    request("read");
  }

  function openCorrectiveReviewEditor(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    var root = findRootFromNode(caseBtn);
    if (!sub || !root || !caseBtn) return;
    var runtime = parseRuntime(root) || {};
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var busy = false;
    var sequence = 1;
    if (title) title.textContent = "Revisión correctiva";
    setCaseSubmodalMeta(sub, caseBtn);
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
    body.innerHTML =
      '<div class="scm-acta scm-corrective-review"><p role="status">Cargando datos del caso…</p></div>';

    function message(text, error) {
      var target = body.querySelector("[data-corrective-review-message]");
      if (!target) return;
      target.textContent = text || "";
      target.classList.toggle("is-error", !!error);
      target.setAttribute("role", error ? "alert" : "status");
    }

    function request(operation, data) {
      if (busy) return Promise.resolve();
      busy = true;
      data = data || new FormData();
      data.set(
        "action",
        (runtime.actions && runtime.actions.revision_correctiva) ||
          "scm_revision_correctiva",
      );
      data.set("nonce", runtime.nonce || "");
      data.set("ticket_pk", caseBtn.dataset.ticketPk || "");
      data.set("operation", operation);
      body.querySelectorAll("button").forEach(function (button) {
        button.disabled = true;
      });
      if (operation === "delete") {
        message("Eliminando revisión correctiva…", false);
      } else if (operation === "edit") {
        message("Cargando edición…", false);
      } else if (operation !== "read") {
        message("Guardando revisión correctiva…", false);
      }
      return fetch(runtime.ajaxUrl || "api.php", {
        method: "POST",
        body: data,
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (json) {
          if (!json || !json.success || !json.data)
            throw new Error(
              (json && json.data && json.data.message) ||
                "No se pudo completar la operación.",
            );
          if (operation === "create" || operation === "update") {
            var savedCorrectiveForm = body.querySelector(
              "[data-corrective-review-create], [data-corrective-review-edit]",
            );
            if (
              savedCorrectiveForm &&
              typeof savedCorrectiveForm._scmCorrectiveClearDraft === "function"
            ) {
              savedCorrectiveForm._scmCorrectiveClearDraft();
            }
          }
          body.innerHTML = json.data.html;
          bind();
          if (json.data.message) {
            message(json.data.message, false);
            scmNotify("success", json.data.message, "Revisión correctiva");
          }
          if (
            operation === "create" ||
            operation === "update" ||
            operation === "delete"
          ) {
            dispatchCaseActionSaved(root, caseBtn.dataset.ticketPk || "", sub);
          }
        })
        .catch(function (error) {
          if (operation === "create" || operation === "update") {
            var activeCorrectiveForm = body.querySelector(
              "[data-corrective-review-create], [data-corrective-review-edit]",
            );
            if (
              activeCorrectiveForm &&
              typeof activeCorrectiveForm._scmCorrectiveSaveDraft === "function"
            ) {
              activeCorrectiveForm._scmCorrectiveSaveDraft();
            }
          }
          if (!body.querySelector("[data-corrective-review-message]")) {
            body.innerHTML =
              '<div class="scm-acta scm-corrective-review"><p data-corrective-review-message></p><button type="button" class="scm-acta-button" data-corrective-retry>Reintentar</button></div>';
            var retry = body.querySelector("[data-corrective-retry]");
            if (retry)
              retry.addEventListener("click", function () {
                request("read");
              });
          }
          message(
            error.message || "No se pudo guardar la revisión correctiva.",
            true,
          );
          scmNotify(
            "error",
            error.message || "No se pudo guardar la revisión correctiva.",
            "Revisión correctiva",
          );
        })
        .finally(function () {
          busy = false;
          body.querySelectorAll("button").forEach(function (button) {
            button.disabled = false;
          });
        });
    }

    function bind() {
      var MAX_PER_DAMAGE = 10;
      var MAX_TOTAL = 30;
      var MAX_SOURCE_BYTES = 25 * 1024 * 1024;

      if (body._scmCorrectiveClickHandler) {
        body.removeEventListener("click", body._scmCorrectiveClickHandler);
      }
      body._scmCorrectiveClickHandler = function (event) {
        var editBtn = event.target.closest("[data-corrective-edit-review]");
        if (editBtn && body.contains(editBtn)) {
          event.preventDefault();
          var editData = new FormData();
          editData.set("review_id", editBtn.dataset.correctiveEditReview || "");
          request("edit", editData);
          return;
        }
        var cancelEdit = event.target.closest("[data-corrective-cancel-edit]");
        if (cancelEdit && body.contains(cancelEdit)) {
          event.preventDefault();
          request("read");
          return;
        }
        var deleteBtn = event.target.closest("[data-corrective-delete-review]");
        if (deleteBtn && body.contains(deleteBtn)) {
          event.preventDefault();
          var reviewId = deleteBtn.dataset.correctiveDeleteReview || "";
          function deleteReview() {
            var deleteData = new FormData();
            deleteData.set("review_id", reviewId);
            request("delete", deleteData);
          }
          if (window.Swal && typeof window.Swal.fire === "function") {
            window.Swal.fire({
              icon: "warning",
              title: "Eliminar revisión #" + reviewId + "?",
              text: "Esta acción borra el registro de la revisión correctiva del caso.",
              input: "text",
              inputLabel: "Escribe ELIMINAR para confirmar",
              showCancelButton: true,
              confirmButtonText: "Eliminar",
              cancelButtonText: "Cancelar",
              confirmButtonColor: "#b42318",
              inputValidator: function (value) {
                return String(value || "")
                  .trim()
                  .toUpperCase() === "ELIMINAR"
                  ? undefined
                  : "Escribe ELIMINAR para confirmar.";
              },
            }).then(function (result) {
              if (result && result.isConfirmed) deleteReview();
            });
            return;
          }
          if (
            window.confirm(
              "¿Eliminar la revisión #" +
                reviewId +
                "? Esta acción no se puede deshacer.",
            )
          ) {
            var typed =
              window.prompt("Escribe ELIMINAR para confirmar:", "") || "";
            if (typed.trim().toUpperCase() === "ELIMINAR") deleteReview();
          }
        }
      };
      body.addEventListener("click", body._scmCorrectiveClickHandler);

      Array.from(
        body.querySelectorAll(
          "[data-corrective-review-create], [data-corrective-review-edit]",
        ),
      ).forEach(function (form) {
        if (form._scmCorrectiveBound) return;
        form._scmCorrectiveBound = true;

        function filesOf(input) {
          return Array.isArray(input._scmFiles)
            ? input._scmFiles.slice()
            : Array.from(input.files || []);
        }
        function syncInput(input, files) {
          if (typeof DataTransfer === "undefined") return false;
          var transfer = new DataTransfer();
          files.forEach(function (file) {
            transfer.items.add(file);
          });
          input.files = transfer.files;
          input._scmFiles = files.slice();
          return true;
        }
        function totalPhotos(except) {
          return Array.from(
            form.querySelectorAll("[data-corrective-photos]"),
          ).reduce(function (sum, input) {
            return sum + (input === except ? 0 : filesOf(input).length);
          }, form.querySelectorAll("input[name$='[existing_fotos][]']").length);
        }
        function preview(input) {
          (input._scmPreviewUrls || []).forEach(function (url) {
            URL.revokeObjectURL(url);
          });
          input._scmPreviewUrls = [];
          var box = input
            .closest("[data-corrective-item]")
            .querySelector("[data-corrective-photo-preview]");
          box
            .querySelectorAll("[data-corrective-new-photo]")
            .forEach(function (node) {
              node.remove();
            });
          filesOf(input).forEach(function (file, index) {
            var url = URL.createObjectURL(file);
            input._scmPreviewUrls.push(url);
            var fig = document.createElement("figure");
            fig.setAttribute("data-corrective-new-photo", "1");
            fig.innerHTML =
              '<img alt="Evidencia ' +
              (index + 1) +
              '"><figcaption></figcaption><button type="button" class="scm-acta-photo-remove" data-corrective-remove-photo="' +
              index +
              '" aria-label="Quitar foto">×</button>';
            fig.querySelector("img").src = url;
            fig.querySelector("figcaption").textContent =
              file.name +
              " · " +
              Math.max(1, Math.round(file.size / 1024)) +
              " KB";
            box.appendChild(fig);
          });
        }
        function addFiles(input, incoming) {
          var current = filesOf(input);
          var existing = new Set(
            current.map(function (file) {
              return [file.name, file.size, file.type, file.lastModified].join(
                "|",
              );
            }),
          );
          var additions = [];
          for (var i = 0; i < incoming.length; i++) {
            var file = incoming[i];
            if (
              !["image/jpeg", "image/png", "image/webp"].includes(file.type) ||
              file.size > MAX_SOURCE_BYTES
            ) {
              syncInput(input, current);
              message(
                "Usa fotos JPG, PNG o WebP de máximo 25 MB cada una.",
                true,
              );
              scmNotify(
                "error",
                "Usa fotos JPG, PNG o WebP de máximo 25 MB cada una.",
                "Fotos",
              );
              return false;
            }
            var key = [file.name, file.size, file.type, file.lastModified].join(
              "|",
            );
            if (!existing.has(key)) {
              existing.add(key);
              additions.push(file);
            }
          }
          var next = current.concat(additions);
          if (next.length > MAX_PER_DAMAGE) {
            message("Cada daño admite máximo 10 fotos.", true);
            scmNotify("error", "Cada daño admite máximo 10 fotos.", "Fotos");
            syncInput(input, current);
            return false;
          }
          if (totalPhotos(input) + next.length > MAX_TOTAL) {
            message("La revisión admite máximo 30 fotos en total.", true);
            scmNotify(
              "error",
              "La revisión admite máximo 30 fotos en total.",
              "Fotos",
            );
            syncInput(input, current);
            return false;
          }
          if (!syncInput(input, next)) {
            message(
              "Tu navegador no permite administrar las fotos seleccionadas. Actualiza Chrome e inténtalo de nuevo.",
              true,
            );
            return false;
          }
          preview(input);
          return true;
        }
        function decodePhoto(file) {
          if (window.createImageBitmap) {
            return createImageBitmap(file, {
              imageOrientation: "from-image",
            }).catch(function () {
              return createImageBitmap(file);
            });
          }
          return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var image = new Image();
            image.onload = function () {
              URL.revokeObjectURL(url);
              resolve(image);
            };
            image.onerror = function () {
              URL.revokeObjectURL(url);
              reject(new Error("No se pudo leer " + file.name + "."));
            };
            image.src = url;
          });
        }
        function compressPhoto(file) {
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
              canvas.toBlob(
                function (blob) {
                  blob
                    ? resolve(
                        new File(
                          [blob],
                          file.name.replace(/\.[^.]+$/, "") + ".jpg",
                          { type: "image/jpeg", lastModified: Date.now() },
                        ),
                      )
                    : reject(
                        new Error("No se pudo comprimir " + file.name + "."),
                      );
                },
                "image/jpeg",
                0.78,
              );
            });
          });
        }
        function compressedFormData() {
          var data = new FormData(form);
          var inputs = Array.from(
            form.querySelectorAll("[data-corrective-photos]"),
          );
          var total = inputs.reduce(function (sum, input) {
            return sum + filesOf(input).length;
          }, form.querySelectorAll("input[name$='[existing_fotos][]']").length);
          if (total > MAX_TOTAL)
            return Promise.reject(
              new Error("La revisión admite máximo 30 fotos en total."),
            );
          return Promise.all(
            inputs.map(function (input) {
              var name = input.name;
              data.delete(name);
              return Promise.all(filesOf(input).map(compressPhoto)).then(
                function (files) {
                  files.forEach(function (file) {
                    data.append(name, file, file.name);
                  });
                },
              );
            }),
          ).then(function () {
            return data;
          });
        }
        function nextItemIndex() {
          var next = 0;
          form.querySelectorAll("[name^='items[']").forEach(function (field) {
            var match = field.name.match(/^items\[(\d+)\]/);
            if (match) next = Math.max(next, Number(match[1]) + 1);
          });
          return next;
        }
        function normalizeCorrectiveIndice(value) {
          return String(value || "").normalize
            ? String(value || "")
                .normalize("NFD")
                .replace(/[\u0300-\u036f]/g, "")
                .toLowerCase()
            : String(value || "").toLowerCase();
        }
        function areaKeyForCorrectiveIndice(value) {
          var normalized = normalizeCorrectiveIndice(value);
          if (normalized.indexOf("estructurales") !== -1)
            return "area_afectada_2";
          if (normalized.indexOf("otros inconvenientes") !== -1)
            return "area_afectada_3";
          if (normalized.indexOf("servicios publicos") !== -1)
            return "area_afectada_4";
          return "area_afectada_1";
        }
        function syncCorrectiveAreaFields(item) {
          if (!item) return;
          var indice = item.querySelector("[data-corrective-indice]");
          var active = areaKeyForCorrectiveIndice(indice ? indice.value : "");
          item
            .querySelectorAll("[data-corrective-area-group]")
            .forEach(function (group) {
              var isActive =
                group.getAttribute("data-corrective-area-for") === active;
              group.hidden = !isActive;
              group
                .querySelectorAll("[data-corrective-area-field]")
                .forEach(function (field) {
                  field.disabled = !isActive;
                  field.required = isActive;
                });
            });
        }
        function appendCorrectiveItem(focusNewItem) {
          var list = form.querySelector("[data-corrective-review-items]");
          if (!list || !list.firstElementChild || list.children.length >= 30)
            return null;
          var item = list.firstElementChild.cloneNode(true);
          item.querySelector("legend").textContent =
            "Daño #" + (list.children.length + 1);
          var index = nextItemIndex();
          item
            .querySelectorAll("input, select, textarea")
            .forEach(function (field) {
              field.name = field.name
                .replace(/items\[\d+\]/, "items[" + index + "]")
                .replace(
                  /corrective_review_photos_\d+\[\]/,
                  "corrective_review_photos_" + index + "[]",
                );
              if (field.type === "file") {
                field.value = "";
                field._scmFiles = [];
              } else {
                field.value = "";
              }
            });
          item
            .querySelectorAll("[data-corrective-existing-photo]")
            .forEach(function (photo) {
              photo.remove();
            });
          item.querySelector("[data-corrective-photo-preview]").innerHTML = "";
          list.appendChild(item);
          syncCorrectiveAreaFields(item);
          if (focusNewItem) {
            var firstField = item.querySelector(
              "textarea, select, input:not([type='file'])",
            );
            if (firstField) firstField.focus();
          }
          return item;
        }
        function correctiveDraftKey() {
          var reviewField = form.querySelector("[name='review_id']");
          return [
            "scm",
            "revision-correctiva-draft",
            caseBtn.dataset.ticketPk || "ticket",
            form.hasAttribute("data-corrective-review-edit")
              ? "edit"
              : "create",
            reviewField ? reviewField.value || "new" : "new",
          ].join(":");
        }
        function correctiveDraftFieldElements(item) {
          return Array.from(
            item.querySelectorAll("input[name], select[name], textarea[name]"),
          ).filter(function (field) {
            if (field.type === "file") return false;
            if (/\[existing_fotos\]\[\]$/.test(field.name)) return false;
            return true;
          });
        }
        function correctiveDraftFields(item) {
          return correctiveDraftFieldElements(item).map(function (field) {
            return {
              name: field.name,
              value:
                field.type === "checkbox" || field.type === "radio"
                  ? !!field.checked
                  : field.value || "",
              checked:
                field.type === "checkbox" || field.type === "radio"
                  ? !!field.checked
                  : undefined,
            };
          });
        }
        function saveCorrectiveDraft() {
          try {
            window.localStorage.setItem(
              correctiveDraftKey(),
              JSON.stringify({
                version: 1,
                savedAt: Date.now(),
                items: Array.from(
                  form.querySelectorAll("[data-corrective-item]"),
                ).map(correctiveDraftFields),
              }),
            );
          } catch (error) {
            if (window.console && console.warn)
              console.warn(
                "[correctiva] No se pudo guardar el borrador local.",
                error,
              );
          }
        }
        function clearCorrectiveDraft() {
          try {
            window.localStorage.removeItem(correctiveDraftKey());
          } catch (error) {}
        }
        function setCorrectiveDraftField(field, entry) {
          if (!field || !entry) return;
          if (field.type === "checkbox" || field.type === "radio") {
            field.checked = !!entry.checked;
          } else {
            field.value = entry.value == null ? "" : String(entry.value);
          }
        }
        function restoreCorrectiveDraft() {
          var raw = null;
          try {
            raw = window.localStorage.getItem(correctiveDraftKey());
          } catch (error) {
            raw = null;
          }
          if (!raw) return;
          var payload = null;
          try {
            payload = JSON.parse(raw);
          } catch (error) {
            clearCorrectiveDraft();
            return;
          }
          if (
            !payload ||
            !Array.isArray(payload.items) ||
            !payload.items.length
          )
            return;
          var list = form.querySelector("[data-corrective-review-items]");
          if (list) {
            while (
              list.children.length < payload.items.length &&
              list.children.length < 30
            )
              appendCorrectiveItem(false);
            while (
              list.children.length > payload.items.length &&
              list.children.length > 1
            )
              list.lastElementChild.remove();
          }
          Array.from(form.querySelectorAll("[data-corrective-item]")).forEach(
            function (item, itemIndex) {
              var entries = payload.items[itemIndex] || [];
              correctiveDraftFieldElements(item).forEach(
                function (field, fieldIndex) {
                  setCorrectiveDraftField(field, entries[fieldIndex]);
                },
              );
              syncCorrectiveAreaFields(item);
            },
          );
          message(
            "Restauré un borrador local de la revisión correctiva. Las fotos solo se conservan si no recargaste la página.",
            false,
          );
        }
        var correctiveDraftTimer = null;
        function scheduleCorrectiveDraftSave() {
          window.clearTimeout(correctiveDraftTimer);
          correctiveDraftTimer = window.setTimeout(saveCorrectiveDraft, 250);
        }
        form._scmCorrectiveSaveDraft = saveCorrectiveDraft;
        form._scmCorrectiveClearDraft = clearCorrectiveDraft;

        form.addEventListener("click", function (event) {
          var removePhoto = event.target.closest(
            "[data-corrective-remove-photo]",
          );
          if (removePhoto) {
            var item = removePhoto.closest("[data-corrective-item]");
            var input = item.querySelector("[data-corrective-photos]");
            var files = filesOf(input);
            files.splice(Number(removePhoto.dataset.correctiveRemovePhoto), 1);
            if (syncInput(input, files)) preview(input);
            scheduleCorrectiveDraftSave();
            return;
          }
          var removeExistingPhoto = event.target.closest(
            "[data-corrective-remove-existing-photo]",
          );
          if (removeExistingPhoto) {
            removeExistingPhoto
              .closest("[data-corrective-existing-photo]")
              .remove();
            scheduleCorrectiveDraftSave();
            return;
          }
          var removeItem = event.target.closest(
            "[data-corrective-remove-item]",
          );
          if (removeItem) {
            if (form.querySelectorAll("[data-corrective-item]").length <= 1) {
              message("Debes conservar al menos un daño.", true);
              return;
            }
            removeItem.closest("[data-corrective-item]").remove();
            scheduleCorrectiveDraftSave();
          }
        });
        var addItem = form.querySelector("[data-corrective-add-item]");
        if (addItem)
          addItem.addEventListener("click", function () {
            var list = form.querySelector("[data-corrective-review-items]");
            if (list.children.length >= 30) {
              message("La revisión admite máximo 30 daños.", true);
              return;
            }
            appendCorrectiveItem(true);
            scheduleCorrectiveDraftSave();
          });
        form.addEventListener("change", function (event) {
          if (event.target.matches("[data-corrective-indice]"))
            syncCorrectiveAreaFields(
              event.target.closest("[data-corrective-item]"),
            );
          if (event.target.matches("[data-corrective-photos]"))
            addFiles(event.target, Array.from(event.target.files || []));
          scheduleCorrectiveDraftSave();
        });
        form.addEventListener("input", function () {
          scheduleCorrectiveDraftSave();
        });
        form
          .querySelectorAll("[data-corrective-item]")
          .forEach(syncCorrectiveAreaFields);
        restoreCorrectiveDraft();
        form.addEventListener("submit", function (event) {
          event.preventDefault();
          saveCorrectiveDraft();
          message("Comprimiendo fotos antes de guardar…", false);
          var operation = form.hasAttribute("data-corrective-review-edit")
            ? "update"
            : "create";
          compressedFormData()
            .then(function (data) {
              request(operation, data);
            })
            .catch(function (error) {
              saveCorrectiveDraft();
              message(
                error.message || "No se pudieron preparar las fotos.",
                true,
              );
              scmNotify(
                "error",
                error.message || "No se pudieron preparar las fotos.",
                "Fotos",
              );
            });
        });
      });
    }
    request("read");
  }

  function openTicketResponseEditor(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var ticketPk = caseBtn.dataset.ticketPk || "";
    var isPublicPqr = (caseBtn.dataset.caseKind || "") === "public-pqr";
    var isPreventiva = !isPublicPqr && isPreventivaCase(caseBtn);
    var noAccessCount = Math.max(
      0,
      parseInt(caseBtn.dataset.preventivaNoAccessCount || "0", 10) || 0,
    );
    var nextNoAccessCount = noAccessCount + 1;
    if (title)
      title.textContent = isPublicPqr
        ? "Responder solicitud"
        : "Responder caso";
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      sub.classList.add(
        "scm-case-submodal--workflow",
        "scm-case-submodal--reply",
      );
      var replyNotifyTargets = renderNotifyTargets(
        isPublicPqr ? ["arrendatario", "propietario"] : [],
      ).replace(
        'class="scm-notify-targets"',
        'class="scm-notify-targets scm-workflow-email-targets"',
      );
      body.innerHTML =
        '<form class="scm-ticket-response-form scm-transfer-form-modern scm-workflow-form-modern" method="post" enctype="multipart/form-data" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        renderCaseWorkflowMeta(caseBtn, modal) +
        '<label class="scm-seg-field scm-transfer-field"><span>Estado administrativo</span><select name="estado_administrativo">' +
        '<option value="__keep__">Sin cambio</option><option value="Nuevo">Nuevo</option><option value="En espera de respuesta">En espera de respuesta</option><option value="Por inspeccionar">Por inspeccionar</option><option value="Inspeccionado">Inspeccionado</option><option value="Cotizado">Cotizado</option><option value="En ejecucion por inmobiliaria">En ejecucion por inmobiliaria</option><option value="En ejecucion por propietario">En ejecucion por propietario</option><option value="En ejecucion por arrendatario">En ejecucion por arrendatario</option><option value="En ejecucion por copropiedad">En ejecucion por copropiedad</option><option value="Finalizado">Finalizado</option><option value="Trasladado">Trasladado</option><option value="Entregado">Entregado</option><option value="Recibido">Recibido</option><option value="Desistido">Desistido</option>' +
        "</select></label>" +
        '<label class="scm-seg-field scm-transfer-field"><span>Respuesta <em>*</em></span><textarea name="respuesta" rows="7" required placeholder="Escribe la respuesta que se enviara al solicitante..."></textarea></label>' +
        (isPreventiva
          ? '<section class="scm-preventiva-no-access-box" data-scm-preventiva-no-access-box><div><strong>Comunicaci&oacute;n / Acta preventiva por no autorizaci&oacute;n</strong><span>Este ticket lleva <b>' +
            escHtml(String(noAccessCount)) +
            "</b> comunicaci&oacute;n" +
            (noAccessCount === 1 ? "" : "es") +
            " registrada" +
            (noAccessCount === 1 ? "" : "s") +
            ". Si marcas esta opci&oacute;n se generar&aacute; la constancia oficial con membrete <b>#" +
            escHtml(String(nextNoAccessCount)) +
            '</b>, se anexar&aacute; al caso y se enviar&aacute; por correo al arrendatario.</span></div><label class="scm-seg-check scm-preventiva-no-access-check"><input type="checkbox" name="generar_acta_no_acceso_preventiva" value="1"> Crear y enviar comunicaci&oacute;n / acta de no autorizaci&oacute;n de revisi&oacute;n preventiva (#' +
            escHtml(String(nextNoAccessCount)) +
            ")</label></section>"
          : "") +
        renderCotizacionInlineFields(
          !isPublicPqr && caseHasCotizacion(caseBtn),
          caseBtn.dataset.cotizacionId || "",
        ) +
        '<label class="scm-seg-field scm-transfer-field"><span>Imagenes <em>Opcional</em></span><input type="file" name="imagen[]" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif,image/tiff" multiple></label>' +
        renderPasteEvidenceBox("imagen[]") +
        renderTicketDocumentFields() +
        replyNotifyTargets +
        '<div class="scm-seg-actions"><span class="scm-seg-msg" aria-live="polite"></span><label class="scm-seg-check scm-workflow-close-check"><input type="checkbox" name="cerrar_ticket" value="1"> Cerrar al responder</label><button type="button" class="scm-btn-secondary" data-scm-case-submodal-cancel>Cancelar</button><button type="submit" class="scm-btn-primary"><span class="material-symbols-outlined">send</span> Publicar y enviar correo</button></div>' +
        "</form>";
      prependCaseLocationPanel(body, caseBtn, modal);
      initCotizacionResponseFields(body);
      initPreventivaNoAccessBox(body);
      var responseCancelBtn = body.querySelector(
        "[data-scm-case-submodal-cancel]",
      );
      if (responseCancelBtn) {
        responseCancelBtn.addEventListener("click", function () {
          closeCaseSubmodal(modal);
        });
      }
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
      sub.classList.add(
        "scm-case-submodal--workflow",
        "scm-case-submodal--quote",
      );
      var quoteNotifyTargets = renderNotifyTargets()
        .replace(
          'class="scm-notify-targets"',
          'class="scm-notify-targets scm-workflow-email-targets"',
        );
      var cotizacionMeta = caseBtn.dataset.cotizacionId
        ? [
            '<span><span class="material-symbols-outlined">receipt_long</span><b>Cotizaci&oacute;n</b> #' +
              escHtml(caseBtn.dataset.cotizacionId || "") +
              "</span>",
          ]
        : [];
      body.innerHTML =
        '<form class="scm-cotizacion-response-form scm-transfer-form-modern scm-workflow-form-modern" method="post" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<input type="hidden" name="id_cotizacion" value="' +
        escHtml(caseBtn.dataset.cotizacionId || "") +
        '">' +
        renderCaseWorkflowMeta(caseBtn, modal, cotizacionMeta) +
        '<section class="scm-cotizacion-response-inline" data-scm-cotizacion-response-fields>' +
        '<label class="scm-seg-field scm-transfer-field"><span>Respuesta <em>*</em></span><select name="estado" required><option value="">Elige una respuesta</option><option value="Aprobada">Aprobada</option><option value="Desaprobada">Desaprobada</option></select></label>' +
        '<label class="scm-seg-field scm-transfer-field scm-cotizacion-motivo" style="display:none;"><span>Motivo</span><select name="motivo"><option value="">Elige un motivo</option><option value="Por costo">Por costo</option><option value="Ejecucción por cuenta propia">Ejecucción por cuenta propia</option></select></label>' +
        '<label class="scm-seg-field scm-transfer-field scm-cotizacion-financiacion" style="display:none;"><span>Financiacion</span><select name="financiacion"><option value="">No aplica / sin respuesta</option><option value="Si">Si</option><option value="No">No</option></select></label>' +
        '<label class="scm-seg-field scm-transfer-field"><span>Observaciones</span><textarea name="observacion" rows="6" placeholder="Ninguna">Ninguna</textarea></label>' +
        "</section>" +
        quoteNotifyTargets +
        '<div class="scm-seg-actions"><button type="button" class="scm-btn-secondary" data-scm-case-submodal-cancel>Cancelar</button><button type="submit" class="scm-btn-primary"><span class="material-symbols-outlined">receipt_long</span> Guardar respuesta</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";
      prependCaseLocationPanel(body, caseBtn, modal);
      initCotizacionResponseFields(body);
      var quoteCancelBtn = body.querySelector(
        "[data-scm-case-submodal-cancel]",
      );
      if (quoteCancelBtn) {
        quoteCancelBtn.addEventListener("click", function () {
          closeCaseSubmodal(modal);
        });
      }
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function openRepairFollowupNoticeEditor(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var ticketPk = String(
      caseBtn.dataset.ticketPk || caseBtn.dataset.ticket || "",
    ).trim();
    var cotizacionId = String(caseBtn.dataset.cotizacionId || "").trim();
    var elapsedDays = String(caseBtn.dataset.cotDiasCalendario || "").trim();
    if (title) title.textContent = "Seguimiento de reparaciones";
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<form class="scm-repair-followup-form" method="post" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<input type="hidden" name="id_cotizacion" value="' +
        escHtml(cotizacionId) +
        '">' +
        '<section class="scm-preventiva-no-access-box"><div><strong>Generar acta/carta de seguimiento</strong><span>Se crear&aacute; la comunicaci&oacute;n con membrete para la cotizaci&oacute;n <b>#' +
        escHtml(cotizacionId || "-") +
        "</b>, se anexar&aacute; al ticket y se enviar&aacute; por correo y WhatsApp al destinatario de la cotizaci&oacute;n. D&iacute;as calendario sin respuesta: <b>" +
        escHtml(elapsedDays || "-") +
        "</b>.</span></div></section>" +
        '<div class="scm-seg-actions"><button type="submit" class="scm-btn-primary">Generar, guardar y enviar</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";
      prependCaseLocationPanel(body, caseBtn, modal);
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
    return fetch(
      calendarApiBase(root) + encodeURIComponent(action),
      options,
    ).then(function (response) {
      return response.json();
    });
  }

  function loadCalendarCategories(root) {
    if (scmCalendarCategoriesCache) {
      return Promise.resolve(scmCalendarCategoriesCache);
    }
    return calendarApiRequest(root, "listar_categorias").then(function (json) {
      if (!json || !json.success || !Array.isArray(json.data)) {
        throw new Error(
          (json && json.message) || "No se pudieron cargar categorias.",
        );
      }
      scmCalendarCategoriesCache = json.data;
      return scmCalendarCategoriesCache;
    });
  }

  function normalizeCalendarText(value) {
    return String(value || "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase();
  }

  function calendarCategoryLabel(row) {
    return String(
      (row &&
        (row.nombre ||
          row.categoria ||
          row.name ||
          row.id ||
          row._ID ||
          row.id_categoria)) ||
        "",
    ).trim();
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
    if (
      commercialTerms.some(function (term) {
        return name.indexOf(term) !== -1;
      })
    )
      return false;
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
    return adminTerms.some(function (term) {
      return name.indexOf(term) !== -1;
    });
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
    return String(value || "")
      .replace(/^#+\s*/, "")
      .trim();
  }

  function buildCaseCalendarTitle(categoryName, contractLabel, ticketPk) {
    categoryName = String(categoryName || "Actividad").trim();
    var contract = cleanCalendarContractLabel(contractLabel);
    if (contract && contract !== "-")
      return "Contrato #" + contract + " - " + categoryName;
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

  function buildCaseCalendarDescription(
    categoryName,
    dateValue,
    startValue,
    endValue,
    asunto,
  ) {
    if (!categoryName || !dateValue || !startValue || !endValue)
      return String(asunto || "").trim();
    return (
      "Por medio de la presente, le confirmo que he dispuesto de un espacio con el propósito de reunirnos, ya sea de forma presencial o por medios virtuales, a fin de atender cualquier inquietud o asunto pendiente.\n\nEn cumplimiento de " +
      categoryName +
      ", se ha programado una visita y/o reunión, la cual ha quedado agendada para el día " +
      formatCalendarDateForMessage(dateValue) +
      ", de " +
      formatCalendarTimeForMessage(startValue) +
      " a " +
      formatCalendarTimeForMessage(endValue) +
      ". En caso de no ser posible contar con su atención en la fecha indicada, le agradecemos nos lo comunique por este mismo medio con al menos 3 horas de antelación." +
      (asunto && asunto !== "-" ? "\n\nCaso: " + asunto : "")
    );
  }

  function buildCaseCalendarRescheduleDescription(
    title,
    dateValue,
    startValue,
    endValue,
    observation,
  ) {
    if (!title || !dateValue || !startValue || !endValue) return "";
    return (
      "Por medio de la presente, se informa que la cita " +
      title +
      " fue reprogramada para el día " +
      formatCalendarDateForMessage(dateValue) +
      ", de " +
      formatCalendarTimeForMessage(startValue) +
      " a " +
      formatCalendarTimeForMessage(endValue) +
      "." +
      (observation ? "\n\nMotivo: " + observation : "")
    );
  }

  function validateCalendarCaseEventTimes(dateValue, startValue, endValue) {
    if (!dateValue || !startValue || !endValue)
      return "Debes ingresar fecha, hora de inicio y hora de fin.";
    var start = new Date(dateValue + "T" + startValue);
    var end = new Date(dateValue + "T" + endValue);
    var now = new Date();
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()))
      return "Debes ingresar fechas y horas válidas.";
    if (end < start)
      return "La hora de finalización no puede ser menor que la hora de inicio.";
    var startDay = new Date(
      start.getFullYear(),
      start.getMonth(),
      start.getDate(),
    );
    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    if (startDay < today) return "No puedes seleccionar una fecha pasada.";
    if (end < now)
      return "No puedes seleccionar una hora de finalización pasada.";
    var startHour = start.getHours() + start.getMinutes() / 60;
    var endHour = end.getHours() + end.getMinutes() / 60;
    if (startHour < 8 || startHour > 21)
      return "La hora de inicio debe estar entre las 8:00 a. m. y las 9:00 p. m.";
    if (endHour < 8 || endHour > 21)
      return "La hora de finalización debe estar entre las 8:00 a. m. y las 9:00 p. m.";
    return "";
  }

  function calendarAllowedEmployeeIds(root) {
    var runtime = root ? parseRuntime(root) || {} : {};
    var config = runtime.config || {};
    var ids = Array.isArray(config.calendar_allowed_employee_ids)
      ? config.calendar_allowed_employee_ids
      : [];
    return ids
      .map(function (id) {
        return String(id || "").trim();
      })
      .filter(Boolean);
  }

  function isCalendarEmployeeAllowed(root, employeeId) {
    var ids = calendarAllowedEmployeeIds(root);
    employeeId = String(employeeId || "").trim();
    return !ids.length || (employeeId !== "" && ids.indexOf(employeeId) !== -1);
  }

  function calendarDateKey(date) {
    return (
      date.getFullYear() +
      "-" +
      String(date.getMonth() + 1).padStart(2, "0") +
      "-" +
      String(date.getDate()).padStart(2, "0")
    );
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
    return raw.replace(
      /^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}:\d{2}).*$/,
      "$3/$2/$1 $4",
    );
  }

  function calendarDatePartFromDateTime(value) {
    return String(value || "")
      .replace("T", " ")
      .slice(0, 10);
  }

  function calendarTimePartFromDateTime(value) {
    var match = String(value || "")
      .replace("T", " ")
      .match(/\s(\d{2}:\d{2})/);
    return match ? match[1] : "";
  }

  function extractCalendarRows(payload) {
    if (Array.isArray(payload)) return payload;
    if (!payload) return [];
    if (Array.isArray(payload.data)) return payload.data;
    if (payload.data && Array.isArray(payload.data.data))
      return payload.data.data;
    if (payload.data && Array.isArray(payload.data.eventos))
      return payload.data.eventos;
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
    addCaseHoliday(
      map,
      nextCalendarMonday(new Date(year, 0, 6)),
      "Reyes Magos",
    );
    addCaseHoliday(map, nextCalendarMonday(new Date(year, 2, 19)), "San José");
    addCaseHoliday(map, new Date(year, 4, 1), "Día del Trabajo");
    addCaseHoliday(map, new Date(year, 6, 20), "Independencia");
    addCaseHoliday(map, new Date(year, 7, 7), "Batalla de Boyacá");
    addCaseHoliday(map, nextCalendarMonday(new Date(year, 7, 15)), "Asunción");
    addCaseHoliday(
      map,
      nextCalendarMonday(new Date(year, 9, 12)),
      "Día de la Raza",
    );
    addCaseHoliday(
      map,
      nextCalendarMonday(new Date(year, 10, 1)),
      "Todos los Santos",
    );
    addCaseHoliday(
      map,
      nextCalendarMonday(new Date(year, 10, 11)),
      "Independencia de Cartagena",
    );
    addCaseHoliday(map, new Date(year, 11, 8), "Inmaculada Concepción");
    addCaseHoliday(map, new Date(year, 11, 25), "Navidad");

    var easter = calendarEasterDate(year);
    addCaseHoliday(map, addCalendarDays(easter, -3), "Jueves Santo");
    addCaseHoliday(map, addCalendarDays(easter, -2), "Viernes Santo");
    addCaseHoliday(
      map,
      nextCalendarMonday(addCalendarDays(easter, 43)),
      "Ascensión",
    );
    addCaseHoliday(
      map,
      nextCalendarMonday(addCalendarDays(easter, 64)),
      "Corpus Christi",
    );
    addCaseHoliday(
      map,
      nextCalendarMonday(addCalendarDays(easter, 71)),
      "Sagrado Corazón",
    );

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
      return date.toLocaleDateString("es-CO", {
        month: "long",
        year: "numeric",
      });
    } catch (error) {
      return (
        String(date.getMonth() + 1).padStart(2, "0") + "/" + date.getFullYear()
      );
    }
  }

  function caseCalendarMonthRange(date) {
    var first = new Date(date.getFullYear(), date.getMonth(), 1);
    var last = new Date(date.getFullYear(), date.getMonth() + 1, 0);
    return { from: calendarDateKey(first), to: calendarDateKey(last) };
  }

  function caseCalendarEventDateKey(row) {
    return String(
      (row && (row.fecha_inicio || row.fecha || row.start)) || "",
    ).slice(0, 10);
  }

  function caseCalendarEventDetailValue(row, keys) {
    for (var i = 0; i < keys.length; i += 1) {
      var value = row && row[keys[i]];
      if (
        value !== undefined &&
        value !== null &&
        String(value).trim() !== ""
      ) {
        return String(value).trim();
      }
    }
    return "";
  }

  function caseCalendarEventId(row) {
    return String(
      (row && (row.id || row._ID || row.event_id || row.id_evento)) || "",
    ).trim();
  }

  function isCaseCalendarEventDone(row) {
    return normalizeCalendarText((row && row.estado) || "") === "si";
  }

  function closeCaseCalendarEventMini(shell) {
    var current = shell
      ? shell.querySelector("[data-scm-case-calendar-event-mini]")
      : null;
    if (current) current.remove();
  }

  function closeCaseCalendarPendingMini(shell) {
    var current = shell
      ? shell.querySelector("[data-scm-case-calendar-pending-mini]")
      : null;
    if (current) current.remove();
  }

  function openCaseCalendarEventMini(root, shell, row) {
    if (!shell || !row) return;
    closeCaseCalendarEventMini(shell);
    var eventId = caseCalendarEventId(row);
    var isDone = isCaseCalendarEventDone(row);
    var title =
      caseCalendarEventDetailValue(row, ["titulo", "title"]) || "Evento";
    var category = caseCalendarEventDetailValue(row, [
      "categoria",
      "nombre_categoria",
    ]);
    var employee = caseCalendarEventDetailValue(row, [
      "funcionario",
      "nombre_empleado",
      "empleado",
      "nombre",
    ]);
    var ticket = caseCalendarEventDetailValue(row, ["id_ticket", "ticket"]);
    var location = caseCalendarEventDetailValue(row, [
      "ubicacion",
      "lugar",
      "direccion",
    ]);
    var description = caseCalendarEventDetailValue(row, [
      "descripcion",
      "observacion",
      "detalle",
    ]);
    var html =
      '<div class="scm-case-calendar-event-mini" data-scm-case-calendar-event-mini>' +
      '<div class="scm-case-calendar-event-mini-card" role="dialog" aria-modal="true" aria-label="Detalle del evento">' +
      '<button type="button" class="scm-case-calendar-event-mini-close" data-scm-case-calendar-event-mini-close aria-label="Cerrar detalle">&times;</button>' +
      '<div class="scm-case-calendar-event-mini-head"><span>Detalle del evento</span><strong>' +
      escHtml(title) +
      "</strong></div>" +
      '<div class="scm-case-calendar-event-mini-grid">' +
      "<div><small>Inicio</small><strong>" +
      escHtml(
        formatCalendarDateTime(row.fecha_inicio || row.fecha || row.start),
      ) +
      "</strong></div>" +
      "<div><small>Fin</small><strong>" +
      escHtml(formatCalendarDateTime(row.fecha_fin || row.end)) +
      "</strong></div>" +
      (category
        ? "<div><small>Categor&iacute;a</small><strong>" +
          escHtml(category) +
          "</strong></div>"
        : "") +
      (employee
        ? "<div><small>Funcionario</small><strong>" +
          escHtml(employee) +
          "</strong></div>"
        : "") +
      (ticket
        ? "<div><small>Ticket</small><strong>#" +
          escHtml(ticket) +
          "</strong></div>"
        : "") +
      (location
        ? '<div class="is-wide"><small>Ubicaci&oacute;n</small><strong>' +
          escHtml(location) +
          "</strong></div>"
        : "") +
      "</div>" +
      (description
        ? '<div class="scm-case-calendar-event-mini-description"><small>Descripci&oacute;n</small><p>' +
          escHtml(description).replace(/\n/g, "<br>") +
          "</p></div>"
        : "") +
      '<div class="scm-case-calendar-event-mini-actions" data-scm-case-calendar-mini-actions>' +
      '<span class="scm-case-calendar-event-state-badge' +
      (isDone ? " is-done" : "") +
      '" data-scm-case-calendar-event-state>' +
      (isDone ? "Realizado" : "Pendiente") +
      "</span>" +
      (eventId
        ? '<button type="button" class="scm-case-calendar-transfer-btn" data-scm-case-calendar-transfer-open>Trasladar evento</button>'
        : "") +
      (eventId && !isDone
        ? '<button type="button" class="scm-case-calendar-complete-btn" data-scm-case-calendar-complete-open>Marcar realizado</button>'
        : "") +
      "</div>" +
      (eventId
        ? '<form class="scm-case-calendar-complete-panel scm-case-calendar-transfer-panel" data-scm-case-calendar-transfer-panel hidden autocomplete="off">' +
          '<div class="scm-case-calendar-transfer-grid">' +
          '<label><span>Nueva fecha</span><input type="date" name="fecha" required value="' +
          escHtml(
            calendarDatePartFromDateTime(
              row.fecha_inicio || row.fecha || row.start,
            ),
          ) +
          '"></label>' +
          '<label><span>Hora inicio</span><input type="time" name="hora_inicio" required value="' +
          escHtml(
            calendarTimePartFromDateTime(
              row.fecha_inicio || row.fecha || row.start,
            ),
          ) +
          '"></label>' +
          '<label><span>Hora fin</span><input type="time" name="hora_fin" required value="' +
          escHtml(calendarTimePartFromDateTime(row.fecha_fin || row.end)) +
          '"></label>' +
          "</div>" +
          '<label><span>Motivo del traslado</span><textarea name="observacion" rows="3" required placeholder="Explica por qu&eacute; se traslada este evento..."></textarea><small>Si es una cita relacionada con ticket, tambi&eacute;n se preparar&aacute; el mensaje de reprogramaci&oacute;n.</small></label>' +
          '<div><button type="submit" class="scm-case-calendar-complete-save">Guardar traslado</button><button type="button" class="scm-case-calendar-complete-cancel" data-scm-case-calendar-transfer-cancel>Cancelar</button></div>' +
          '<small data-scm-case-calendar-transfer-msg aria-live="polite"></small>' +
          "</form>"
        : "") +
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
      if (
        event.target === mini ||
        (event.target &&
          event.target.closest &&
          event.target.closest("[data-scm-case-calendar-event-mini-close]"))
      ) {
        closeCaseCalendarEventMini(shell);
        return;
      }
      var transferBtn =
        event.target && event.target.closest
          ? event.target.closest("[data-scm-case-calendar-transfer-open]")
          : null;
      if (transferBtn) {
        var transferPanel = mini.querySelector(
          "[data-scm-case-calendar-transfer-panel]",
        );
        var completePanel = mini.querySelector(
          "[data-scm-case-calendar-complete-panel]",
        );
        if (completePanel) completePanel.hidden = true;
        if (transferPanel) {
          transferPanel.hidden = false;
          var firstField = transferPanel.querySelector("input, textarea");
          if (firstField) firstField.focus();
        }
        return;
      }
      var openBtn =
        event.target && event.target.closest
          ? event.target.closest("[data-scm-case-calendar-complete-open]")
          : null;
      if (openBtn) {
        var panel = mini.querySelector(
          "[data-scm-case-calendar-complete-panel]",
        );
        var openTransferPanel = mini.querySelector(
          "[data-scm-case-calendar-transfer-panel]",
        );
        if (openTransferPanel) openTransferPanel.hidden = true;
        if (panel) {
          panel.hidden = false;
          var textarea = panel.querySelector("textarea");
          if (textarea) textarea.focus();
        }
        return;
      }
      if (
        event.target &&
        event.target.closest &&
        event.target.closest("[data-scm-case-calendar-complete-cancel]")
      ) {
        var cancelPanel = mini.querySelector(
          "[data-scm-case-calendar-complete-panel]",
        );
        if (cancelPanel) cancelPanel.hidden = true;
      }
      if (
        event.target &&
        event.target.closest &&
        event.target.closest("[data-scm-case-calendar-transfer-cancel]")
      ) {
        var cancelTransferPanel = mini.querySelector(
          "[data-scm-case-calendar-transfer-panel]",
        );
        if (cancelTransferPanel) cancelTransferPanel.hidden = true;
      }
    });
    var transferForm = mini.querySelector(
      "[data-scm-case-calendar-transfer-panel]",
    );
    if (transferForm && eventId) {
      transferForm.addEventListener("submit", function (event) {
        event.preventDefault();
        var msg = transferForm.querySelector(
          "[data-scm-case-calendar-transfer-msg]",
        );
        var saveBtn = transferForm.querySelector("button[type='submit']");
        var fd = new FormData(transferForm);
        var dateValue = String(fd.get("fecha") || "");
        var startValue = String(fd.get("hora_inicio") || "");
        var endValue = String(fd.get("hora_fin") || "");
        var observation = String(fd.get("observacion") || "").trim();
        var timeError = validateCalendarCaseEventTimes(
          dateValue,
          startValue,
          endValue,
        );
        if (timeError || !observation) {
          if (msg) {
            msg.textContent = timeError || "Escribe el motivo del traslado.";
            msg.classList.add("is-error");
          }
          return;
        }
        if (saveBtn) saveBtn.disabled = true;
        if (msg) {
          msg.textContent = "Guardando traslado...";
          msg.classList.remove("is-error");
        }
        var payload = {
          id_evento: eventId,
          fecha_inicio: dateValue + " " + startValue + ":00",
          fecha_fin: dateValue + " " + endValue + ":00",
          observacion: observation,
          es_cita: ticket ? "si" : "no",
          descripcion: ticket
            ? buildCaseCalendarRescheduleDescription(
                title,
                dateValue,
                startValue,
                endValue,
                observation,
              )
            : "",
        };
        calendarApiRequest(root, "trasladar_evento", payload)
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error(
                (json && json.message) || "No se pudo trasladar el evento.",
              );
            }
            row.fecha_inicio = payload.fecha_inicio;
            row.fecha_fin = payload.fecha_fin;
            if (payload.descripcion) row.descripcion = payload.descripcion;
            var cells = mini.querySelectorAll(
              ".scm-case-calendar-event-mini-grid > div strong",
            );
            if (cells[0])
              cells[0].textContent = formatCalendarDateTime(
                payload.fecha_inicio,
              );
            if (cells[1])
              cells[1].textContent = formatCalendarDateTime(payload.fecha_fin);
            if (msg) msg.textContent = json.message || "Evento trasladado.";
            scmNotify(
              "success",
              json.message || "Evento trasladado.",
              "Calendario",
            );
            if (typeof shell._scmCaseCalendarRender === "function") {
              shell._scmCaseCalendarRender();
            }
            closeCaseCalendarEventMini(shell);
            dispatchCaseActionSaved(root, ticket, shell);
            if (
              shell &&
              typeof shell._scmCaseCalendarEventTransferred === "function"
            ) {
              shell._scmCaseCalendarEventTransferred({
                eventId: eventId,
                payload: payload,
                response: json,
                row: row,
              });
            }
            if (
              shell &&
              shell._scmCaseCalendarCloseOnTransferred &&
              window.Swal &&
              typeof window.Swal.close === "function"
            ) {
              window.setTimeout(function () {
                window.Swal.close();
              }, 250);
            }
            if (ticket) {
              notifyCalendarAppointment(root, [
                {
                  id_ticket: ticket,
                  id_empleado: caseCalendarEventDetailValue(row, [
                    "id_empleado",
                    "funcionario_id",
                    "empleado_id",
                  ]),
                  categoria: category || "cita",
                  titulo: title,
                  fecha_inicio: payload.fecha_inicio,
                  fecha_fin: payload.fecha_fin,
                  ubicacion: location,
                  es_cita: "si",
                },
              ]).then(function (result) {
                result = result || {};
                var queued = Number(result.queued || 0);
                var errors = Array.isArray(result.errors)
                  ? result.errors.filter(Boolean)
                  : [];
                var errorText = String(result.error || errors[0] || "").trim();
                if (queued > 0) {
                  scmNotify(
                    "success",
                    "WhatsApp de traslado encolado.",
                    "WhatsApp",
                  );
                } else if (errorText) {
                  scmNotify("error", errorText, "WhatsApp");
                }
              });
            }
          })
          .catch(function (error) {
            if (msg) {
              msg.textContent =
                error.message || "No se pudo trasladar el evento.";
              msg.classList.add("is-error");
            }
            scmNotify(
              "error",
              error.message || "No se pudo trasladar el evento.",
            );
          })
          .finally(function () {
            if (saveBtn) saveBtn.disabled = false;
          });
      });
    }
    var form = mini.querySelector("[data-scm-case-calendar-complete-panel]");
    if (form && eventId) {
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        var msg = form.querySelector("[data-scm-case-calendar-complete-msg]");
        var saveBtn = form.querySelector("button[type='submit']");
        var observation = String(
          new FormData(form).get("observacion") || "",
        ).trim();
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
        })
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error(
                (json && json.message) ||
                  "No se pudo marcar el evento como realizado.",
              );
            }
            row.estado = "Si";
            var status = mini.querySelector(
              "[data-scm-case-calendar-event-state]",
            );
            if (status) {
              status.textContent = "Realizado";
              status.classList.add("is-done");
            }
            var actions = mini.querySelector(
              "[data-scm-case-calendar-mini-actions]",
            );
            if (actions) {
              actions.innerHTML =
                '<span class="scm-case-calendar-event-state-badge is-done">Realizado</span>';
            }
            form.hidden = true;
            scmNotify(
              "success",
              json.message || "Evento marcado como realizado.",
              "Calendario",
            );
          })
          .catch(function (error) {
            if (msg) {
              msg.textContent =
                error.message || "No se pudo marcar el evento como realizado.";
              msg.classList.add("is-error");
            }
            scmNotify(
              "error",
              error.message || "No se pudo marcar el evento como realizado.",
            );
          })
          .finally(function () {
            if (saveBtn) saveBtn.disabled = false;
          });
      });
    }
  }

  function renderCaseCalendarMonth(root, shell, employeeId, monthDate) {
    var grid = shell
      ? shell.querySelector("[data-scm-case-calendar-grid]")
      : null;
    var title = shell
      ? shell.querySelector("[data-scm-case-calendar-title]")
      : null;
    if (title) title.textContent = caseCalendarMonthTitle(monthDate);
    if (!grid) return Promise.resolve();
    if (!employeeId) {
      grid.innerHTML =
        '<div class="scm-case-calendar-empty">Este caso no tiene funcionario asignado.</div>';
      return Promise.resolve();
    }
    if (!isCalendarEmployeeAllowed(root, employeeId)) {
      grid.innerHTML =
        '<div class="scm-case-calendar-empty">El funcionario asignado no pertenece a los cargos visibles del calendario.</div>';
      return Promise.resolve();
    }
    grid.innerHTML =
      '<div class="scm-case-calendar-empty">Cargando calendario...</div>';
    var range = caseCalendarMonthRange(monthDate);
    return calendarApiRequest(root, "filtrar_eventos_admin", {
      id_empleado: employeeId,
      fecha_inicio: range.from,
      fecha_fin: range.to,
      pagina: 1,
      limite: 250,
    })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error(
            (json && json.message) || "No se pudo cargar el calendario.",
          );
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
            return String(a.fecha_inicio || "").localeCompare(
              String(b.fecha_inicio || ""),
            );
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
          if (current.getMonth() !== monthDate.getMonth())
            classes.push("is-muted");
          if (key === todayKey) classes.push("is-today");
          if (holiday) classes.push("is-holiday");
          cells.push(
            '<div class="' +
              classes.join(" ") +
              '">' +
              '<div class="scm-case-calendar-day-head"><span class="scm-case-calendar-day-number">' +
              current.getDate() +
              "</span>" +
              (holiday
                ? '<span class="scm-case-calendar-day-holiday">' +
                  escHtml(holiday) +
                  "</span>"
                : "") +
              "</div>" +
              rows
                .slice(0, 3)
                .map(function (row) {
                  var detailIndex = eventDetails.push(row) - 1;
                  var done = isCaseCalendarEventDone(row);
                  return (
                    '<div class="scm-case-calendar-day-pill"><div><strong>' +
                    escHtml(formatCalendarDateTime(row.fecha_inicio)) +
                    "</strong><span>" +
                    escHtml(row.titulo || "Evento") +
                    '</span><em class="scm-case-calendar-day-status' +
                    (done ? " is-done" : "") +
                    '">' +
                    (done ? "Realizado" : "Pendiente") +
                    '</em></div><button type="button" class="scm-case-calendar-event-detail-btn" data-scm-case-calendar-event-detail="' +
                    detailIndex +
                    '" aria-label="Ver detalle de ' +
                    escHtml(row.titulo || "evento") +
                    '">Ver</button></div>'
                  );
                })
                .join("") +
              (rows.length > 3
                ? '<div class="scm-case-calendar-day-more">+' +
                  (rows.length - 3) +
                  " más</div>"
                : "") +
              "</div>",
          );
        }
        grid.innerHTML = cells.join("");
        shell._scmCaseCalendarEventDetails = eventDetails;
        grid
          .querySelectorAll("[data-scm-case-calendar-event-detail]")
          .forEach(function (btn) {
            btn.addEventListener("click", function (event) {
              event.preventDefault();
              event.stopPropagation();
              var index = parseInt(
                btn.getAttribute("data-scm-case-calendar-event-detail") || "-1",
                10,
              );
              openCaseCalendarEventMini(
                root,
                shell,
                shell._scmCaseCalendarEventDetails[index],
              );
            });
          });
      })
      .catch(function (error) {
        grid.innerHTML =
          '<div class="scm-case-calendar-empty">No se pudo cargar el calendario.</div>';
        scmNotify("error", error.message || "No se pudo cargar el calendario.");
      });
  }

  function renderCaseCalendarPendingRows(rows) {
    rows = Array.isArray(rows) ? rows : [];
    if (!rows.length) {
      return '<div class="scm-case-calendar-pending-empty">No hay eventos pendientes vencidos para este funcionario.</div>';
    }
    return rows
      .map(function (row, index) {
        var ticket = caseCalendarEventDetailValue(row, ["id_ticket", "ticket"]);
        var category = caseCalendarEventDetailValue(row, [
          "categoria",
          "nombre_categoria",
        ]);
        return (
          '<article class="scm-case-calendar-pending-row">' +
          "<div><strong>" +
          escHtml(row.titulo || "Evento") +
          "</strong><span>" +
          escHtml(
            formatCalendarDateTime(row.fecha_inicio || row.fecha || row.start),
          ) +
          (row.fecha_fin
            ? " - " + escHtml(formatCalendarDateTime(row.fecha_fin))
            : "") +
          "</span></div>" +
          "<p>" +
          (category
            ? "<b>Categor&iacute;a:</b> " + escHtml(category) + " "
            : "") +
          (ticket ? "<b>Ticket:</b> #" + escHtml(ticket) : "") +
          "</p>" +
          '<button type="button" class="scm-case-calendar-complete-btn" data-scm-case-calendar-pending-detail="' +
          index +
          '">Ver detalle</button>' +
          "</article>"
        );
      })
      .join("");
  }

  function openCaseCalendarPendingMini(root, shell, employeeId, employeeName) {
    if (!shell) return;
    closeCaseCalendarEventMini(shell);
    closeCaseCalendarPendingMini(shell);
    var html =
      '<div class="scm-case-calendar-event-mini scm-case-calendar-pending-mini" data-scm-case-calendar-pending-mini>' +
      '<div class="scm-case-calendar-event-mini-card scm-case-calendar-pending-card" role="dialog" aria-modal="true" aria-label="Eventos pendientes">' +
      '<button type="button" class="scm-case-calendar-event-mini-close" data-scm-case-calendar-pending-close aria-label="Cerrar pendientes">&times;</button>' +
      '<div class="scm-case-calendar-event-mini-head"><span>Eventos pendientes</span><strong>' +
      escHtml(employeeName || "Funcionario asignado") +
      "</strong></div>" +
      '<div class="scm-case-calendar-pending-list" data-scm-case-calendar-pending-list><div class="scm-case-calendar-pending-empty">Cargando pendientes...</div></div>' +
      "</div>" +
      "</div>";
    shell.insertAdjacentHTML("beforeend", html);
    var mini = shell.querySelector("[data-scm-case-calendar-pending-mini]");
    var list = mini
      ? mini.querySelector("[data-scm-case-calendar-pending-list]")
      : null;
    if (!mini || !list) return;
    mini.addEventListener("click", function (event) {
      if (
        event.target === mini ||
        (event.target &&
          event.target.closest &&
          event.target.closest("[data-scm-case-calendar-pending-close]"))
      ) {
        closeCaseCalendarPendingMini(shell);
        return;
      }
      var detailBtn =
        event.target && event.target.closest
          ? event.target.closest("[data-scm-case-calendar-pending-detail]")
          : null;
      if (detailBtn) {
        event.preventDefault();
        var index = parseInt(
          detailBtn.getAttribute("data-scm-case-calendar-pending-detail") ||
            "-1",
          10,
        );
        var row =
          shell._scmCaseCalendarPendingRows &&
          shell._scmCaseCalendarPendingRows[index];
        closeCaseCalendarPendingMini(shell);
        openCaseCalendarEventMini(root, shell, row);
      }
    });
    if (!employeeId) {
      list.innerHTML =
        '<div class="scm-case-calendar-pending-empty">Este caso no tiene funcionario asignado.</div>';
      return;
    }
    calendarApiRequest(root, "listar_pendientes_vencidos", {
      id_empleado: employeeId,
    })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error(
            (json && json.message) || "No se pudieron cargar pendientes.",
          );
        }
        var rows = extractCalendarRows(json.data || []);
        shell._scmCaseCalendarPendingRows = rows;
        list.innerHTML = renderCaseCalendarPendingRows(rows);
      })
      .catch(function (error) {
        list.innerHTML =
          '<div class="scm-case-calendar-pending-empty">No se pudieron cargar pendientes.</div>';
        scmNotify(
          "error",
          error.message || "No se pudieron cargar pendientes.",
        );
      });
  }

  function openCalendarCaseMonthPopup(
    root,
    employeeId,
    employeeName,
    selectedDate,
    options,
  ) {
    if (!window.Swal || typeof window.Swal.fire !== "function") {
      scmNotify("error", "No se pudo abrir el calendario.");
      return;
    }
    options = options || {};
    var currentMonth = caseCalendarMonthDate(selectedDate);
    window.Swal.fire({
      title: "Calendario del funcionario",
      html:
        '<div class="scm-case-calendar-month-shell" data-scm-case-calendar-popup>' +
        '<div class="scm-case-calendar-toolbar">' +
        '<button type="button" class="scm-case-calendar-nav" data-scm-case-calendar-prev aria-label="Mes anterior">&lsaquo;</button>' +
        '<div class="scm-case-calendar-heading"><span>' +
        escHtml(employeeName || "Funcionario asignado") +
        "</span><strong data-scm-case-calendar-title>" +
        escHtml(caseCalendarMonthTitle(currentMonth)) +
        "</strong><small>Eventos y festivos de Colombia</small></div>" +
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
        var shell = popup
          ? popup.querySelector("[data-scm-case-calendar-popup]")
          : null;
        var render = function () {
          renderCaseCalendarMonth(root, shell, employeeId, currentMonth);
        };
        if (shell) {
          shell._scmCaseCalendarRender = render;
          shell._scmCaseCalendarEventTransferred =
            typeof options.onEventTransferred === "function"
              ? options.onEventTransferred
              : null;
          shell._scmCaseCalendarCloseOnTransferred =
            options.closeOnEventTransferred === true;
        }
        var prev = popup
          ? popup.querySelector("[data-scm-case-calendar-prev]")
          : null;
        var next = popup
          ? popup.querySelector("[data-scm-case-calendar-next]")
          : null;
        var pending = popup
          ? popup.querySelector("[data-scm-case-calendar-pending]")
          : null;
        if (prev) {
          prev.addEventListener("click", function () {
            currentMonth = new Date(
              currentMonth.getFullYear(),
              currentMonth.getMonth() - 1,
              1,
            );
            render();
          });
        }
        if (next) {
          next.addEventListener("click", function () {
            currentMonth = new Date(
              currentMonth.getFullYear(),
              currentMonth.getMonth() + 1,
              1,
            );
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
    var agenda = body
      ? body.querySelector("[data-scm-calendar-case-agenda]")
      : null;
    if (!agenda) return;
    if (!employeeId) {
      agenda.innerHTML =
        '<div class="scm-calendar-popup-agenda-empty">Este caso no tiene funcionario asignado.</div>';
      return;
    }
    if (!isCalendarEmployeeAllowed(root, employeeId)) {
      agenda.innerHTML =
        '<div class="scm-calendar-popup-agenda-empty">El funcionario asignado no pertenece a los cargos visibles del calendario.</div>';
      return;
    }
    agenda.innerHTML =
      '<div class="scm-calendar-popup-agenda-empty">Cargando agenda del funcionario...</div>';
    var range = calendarCurrentMonthRange();
    calendarApiRequest(root, "filtrar_eventos_admin", {
      id_empleado: employeeId,
      fecha_inicio: range.from,
      fecha_fin: range.to,
      pagina: 1,
      limite: 80,
    })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error(
            (json && json.message) || "No se pudo cargar la agenda.",
          );
        }
        var rows = extractCalendarRows(json.data || []).sort(function (a, b) {
          return String(a.fecha_inicio || "").localeCompare(
            String(b.fecha_inicio || ""),
          );
        });
        if (!rows.length) {
          agenda.innerHTML =
            '<div class="scm-calendar-popup-agenda-empty">El funcionario no tiene eventos en el mes visible.</div>';
          return;
        }
        agenda.innerHTML = rows
          .slice(0, 12)
          .map(function (row) {
            return (
              '<div class="scm-calendar-popup-agenda-item"><strong>' +
              escHtml(formatCalendarDateTime(row.fecha_inicio)) +
              "</strong><span>" +
              escHtml(row.titulo || "Evento") +
              "</span></div>"
            );
          })
          .join("");
      })
      .catch(function (error) {
        agenda.innerHTML =
          '<div class="scm-calendar-popup-agenda-empty">No se pudo cargar la agenda.</div>';
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
        '<input type="hidden" name="id_ticket" value="' +
        escHtml(ticketPk) +
        '">' +
        '<input type="hidden" name="id_empleado" value="' +
        escHtml(employeeId) +
        '">' +
        '<input type="hidden" name="es_cita" value="si">' +
        '<div class="scm-calendar-case-note">Se crear&aacute; el evento en el calendario y quedar&aacute; enlazado al ticket #' +
        escHtml(ticketPk || "-") +
        ".</div>" +
        '<div class="scm-grid">' +
        '<label class="scm-seg-field"><span>T&iacute;tulo</span><input class="input input-bordered input-sm scm-input" name="titulo" required value="' +
        escHtml(buildCaseCalendarTitle("", contractLabel, ticketPk)) +
        '" data-auto-calendar-title="1"></label>' +
        '<label class="scm-seg-field"><span>Categor&iacute;a</span><select class="select select-bordered select-sm scm-select" name="id_categoria" required data-scm-calendar-case-categories><option value="">Cargando...</option></select></label>' +
        '<label class="scm-seg-field"><span>Fecha</span><input class="input input-bordered input-sm scm-input" type="date" name="fecha" required value="' +
        escHtml(dateValue) +
        '"></label>' +
        '<label class="scm-seg-field"><span>Hora inicio</span><input class="input input-bordered input-sm scm-input" type="time" name="hora_inicio" required></label>' +
        '<label class="scm-seg-field"><span>Hora fin</span><input class="input input-bordered input-sm scm-input" type="time" name="hora_fin" required></label>' +
        '<label class="scm-seg-field scm-calendar-field-full"><span>Ubicaci&oacute;n</span><input class="input input-bordered input-sm scm-input" name="ubicacion" value="' +
        escHtml(addressLabel && addressLabel !== "-" ? addressLabel : "") +
        '" data-auto-calendar-location="1"></label>' +
        '<label class="scm-seg-field scm-calendar-field-full"><span>Descripci&oacute;n</span><textarea class="textarea textarea-bordered scm-input" name="descripcion" rows="4" required data-auto-calendar-text="1">' +
        escHtml(asuntoLabel && asuntoLabel !== "-" ? asuntoLabel : "") +
        "</textarea><small>Se autocompleta con el texto de cita y puedes editarlo si necesitas ajustar el mensaje.</small></label>" +
        "</div>" +
        '<div class="scm-calendar-case-tools"><div><strong>Disponibilidad del funcionario</strong><span>Revisa el calendario sin cerrar este formulario.</span></div><button type="button" class="scm-case-work-btn scm-calendar-case-open-btn" data-scm-calendar-case-open-month>Ver calendario</button></div>' +
        '<div class="scm-seg-actions"><button type="submit" class="scm-btn-primary">Crear evento</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");

    var form = body ? body.querySelector(".scm-calendar-case-form") : null;
    var categorySelect = body
      ? body.querySelector("[data-scm-calendar-case-categories]")
      : null;
    var titleInput = form ? form.querySelector('[name="titulo"]') : null;
    var locationInput = form ? form.querySelector('[name="ubicacion"]') : null;
    var descriptionInput = form
      ? form.querySelector('[name="descripcion"]')
      : null;
    var dateInput = form ? form.querySelector('[name="fecha"]') : null;
    var startInput = form ? form.querySelector('[name="hora_inicio"]') : null;
    var endInput = form ? form.querySelector('[name="hora_fin"]') : null;
    var calendarMonthBtn = form
      ? form.querySelector("[data-scm-calendar-case-open-month]")
      : null;
    function maybeAutofillCaseCalendarFields(force) {
      var categoryName = selectedCalendarCategoryName(categorySelect);
      if (titleInput) {
        var nextTitle = buildCaseCalendarTitle(
          categoryName,
          contractLabel,
          ticketPk,
        );
        if (
          nextTitle &&
          (force ||
            !titleInput.value ||
            titleInput.getAttribute("data-auto-calendar-title") === "1")
        ) {
          titleInput.value = nextTitle;
          titleInput.setAttribute("data-auto-calendar-title", "1");
        }
      }
      if (
        locationInput &&
        addressLabel &&
        addressLabel !== "-" &&
        (force ||
          !locationInput.value ||
          locationInput.getAttribute("data-auto-calendar-location") === "1")
      ) {
        locationInput.value = addressLabel;
        locationInput.setAttribute("data-auto-calendar-location", "1");
      }
      if (!descriptionInput || !dateInput || !startInput || !endInput) return;
      if (
        !dateInput.value ||
        !startInput.value ||
        !endInput.value ||
        !categoryName
      )
        return;
      if (
        descriptionInput.value &&
        descriptionInput.getAttribute("data-auto-calendar-text") !== "1"
      )
        return;
      descriptionInput.value = buildCaseCalendarDescription(
        categoryName,
        dateInput.value,
        startInput.value,
        endInput.value,
        asuntoLabel,
      );
      descriptionInput.setAttribute("data-auto-calendar-text", "1");
    }
    loadCalendarCategories(root)
      .then(function (rows) {
        if (!categorySelect) return;
        categorySelect.innerHTML =
          '<option value="">Selecciona categoria</option>';
        administrativeCalendarCategories(rows).forEach(function (row) {
          var id = String(row.id || row._ID || row.id_categoria || "").trim();
          var name = calendarCategoryLabel(row) || id;
          if (!id) return;
          categorySelect.innerHTML +=
            '<option value="' +
            escHtml(id) +
            '">' +
            escHtml(name) +
            "</option>";
        });
        maybeAutofillCaseCalendarFields(false);
      })
      .catch(function (error) {
        if (categorySelect) {
          categorySelect.innerHTML =
            '<option value="">No se pudieron cargar categorias</option>';
        }
        scmNotify(
          "error",
          error.message || "No se pudieron cargar categorias.",
        );
      });

    if (form) {
      [categorySelect, dateInput, startInput, endInput].forEach(
        function (field) {
          if (!field) return;
          field.addEventListener("change", function () {
            maybeAutofillCaseCalendarFields(false);
          });
        },
      );
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
            {
              closeOnEventTransferred: true,
              onEventTransferred: function () {
                closeCaseSubmodal(modal);
              },
            },
          );
        });
      }
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        if (!employeeId) {
          scmNotify(
            "error",
            "Este caso no tiene id_empleado para agendar la cita.",
          );
          return;
        }
        if (!isCalendarEmployeeAllowed(root, employeeId)) {
          scmNotify(
            "error",
            "El funcionario asignado no pertenece a los cargos visibles del calendario.",
          );
          return;
        }
        var submitBtn = form.querySelector("button[type='submit']");
        var msg = form.querySelector(".scm-seg-msg");
        var fd = new FormData(form);
        var dateValue = String(fd.get("fecha") || "");
        var startValue = String(fd.get("hora_inicio") || "");
        var endValue = String(fd.get("hora_fin") || "");
        var timeError = validateCalendarCaseEventTimes(
          dateValue,
          startValue,
          endValue,
        );
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
              throw new Error(
                (json && json.message) || "No se pudo crear el evento.",
              );
            }
            if (msg) msg.textContent = json.message || "Evento creado.";
            scmNotify(
              "success",
              json.message || "Evento creado.",
              "Calendario",
            );
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
              root.dispatchEvent(
                new CustomEvent("scm:case-action-saved", {
                  detail: { ticketPk: ticketPk, fromNode: form },
                }),
              );
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

  function renderNotifyTargets(exclude, checkedValues) {
    exclude = Array.isArray(exclude) ? exclude : [];
    checkedValues = Array.isArray(checkedValues) ? checkedValues : null;
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
      var checked = checkedValues
        ? checkedValues.indexOf(opt.value) !== -1
        : true;
      html +=
        '<label class="scm-seg-check"><input type="checkbox" name="notify_recipients[]" value="' +
        opt.value +
        '"' +
        (checked ? " checked" : "") +
        "> " +
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

  function normalizeContactRole(role) {
    role = String(role || "")
      .trim()
      .toLowerCase();
    return role === "owner" || role === "tenant" ? role : "all";
  }

  function openContactEditor(modal, caseBtn, role) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    role = normalizeContactRole(role);
    var root = findRootFromNode(caseBtn);
    var runtime = root ? parseRuntime(root) || {} : {};
    var indicativos = runtime.indicativos || [];
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var ticketPk = caseBtn.dataset.ticketPk || "";
    var ownerFields =
      '<fieldset class="scm-contact-edit-group scm-contact-edit-group-owner"><legend><span class="material-symbols-outlined">person_pin</span> Propietario</legend>' +
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
      "</fieldset>";
    var tenantFields =
      '<fieldset class="scm-contact-edit-group scm-contact-edit-group-tenant"><legend><span class="material-symbols-outlined">group</span> Arrendatario</legend>' +
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
      "</fieldset>";
    var ownerHidden =
      '<input type="hidden" name="propietario" value="' +
      escHtml(caseBtn.dataset.propietario || "") +
      '">' +
      '<input type="hidden" name="correo_propietario" value="' +
      escHtml(caseBtn.dataset.correoPropietario || "") +
      '">' +
      '<input type="hidden" name="indicativo_propietario" value="' +
      escHtml(caseBtn.dataset.indicativoPropietario || "") +
      '">' +
      '<input type="hidden" name="celular_propietario" value="' +
      escHtml(caseBtn.dataset.celularPropietario || "") +
      '">';
    var tenantHidden =
      '<input type="hidden" name="arrendatario" value="' +
      escHtml(caseBtn.dataset.arrendatario || "") +
      '">' +
      '<input type="hidden" name="correo_arrendatario" value="' +
      escHtml(caseBtn.dataset.correoArrendatario || "") +
      '">' +
      '<input type="hidden" name="indicativo_arrendatario" value="' +
      escHtml(caseBtn.dataset.indicativoArrendatario || "") +
      '">' +
      '<input type="hidden" name="celular_arrendatario" value="' +
      escHtml(caseBtn.dataset.celularArrendatario || "") +
      '">';
    var visibleFields = ownerFields + tenantFields;
    var hiddenFields = "";
    if (role === "owner") {
      visibleFields = ownerFields;
      hiddenFields = tenantHidden;
    } else if (role === "tenant") {
      visibleFields = tenantFields;
      hiddenFields = ownerHidden;
    }
    sub.classList.add("scm-case-submodal--contacts");
    if (title) {
      title.textContent =
        role === "owner"
          ? "Editar datos del propietario"
          : role === "tenant"
            ? "Editar datos del arrendatario"
            : "Editar datos de titulares";
    }
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<form class="scm-contact-update-form scm-contact-editor-modern scm-contact-shell" method="post" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        hiddenFields +
        '<div class="scm-contact-editor-note"><span class="material-symbols-outlined">info</span><p>Los cambios actualizan las fichas vinculadas al caso y conservan el contexto del inmueble.</p></div>' +
        '<div class="scm-contact-edit-grid' +
        (role === "all" ? "" : " scm-contact-edit-single") +
        '">' +
        visibleFields +
        "</div>" +
        '<div class="scm-contact-audit-note"><span class="material-symbols-outlined">lock</span> Edición auditada bajo protocolo RGPD / Habeas Data</div>' +
        '<div class="scm-seg-actions scm-contact-footer-actions"><button type="button" class="scm-btn-secondary" data-scm-case-submodal-cancel>Cancelar</button><button type="submit" class="scm-btn-primary"><span class="material-symbols-outlined">check</span> Guardar cambios</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";
      var cancelBtn = body.querySelector("[data-scm-case-submodal-cancel]");
      if (cancelBtn) {
        cancelBtn.addEventListener("click", function () {
          closeCaseSubmodal(modal);
        });
      }
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");
  }

  function renderContactViewerHtml(caseBtn, role) {
    role = normalizeContactRole(role);
    function line(label, value) {
      value = String(value || "").trim();
      return value
        ? "<dt>" +
            escHtml(label) +
            "</dt><dd><strong>" +
            escHtml(value) +
            "</strong></dd>"
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
      '<div class="scm-contact-shell">' +
      '<div class="scm-contact-editor-note"><span class="material-symbols-outlined">info</span><p>Los cambios realizados actualizarán de forma inmediata las fichas vinculadas al inmueble y las notificaciones automatizadas del sistema.</p></div>' +
      '<div class="scm-contact-view-actions"><button type="button" class="scm-contact-view-edit-btn" data-scm-edit-contacts-from-view data-scm-contact-role="' +
      escHtml(role) +
      '"><span class="material-symbols-outlined">edit</span> Editar datos</button></div>' +
      '<div class="scm-contact-view-grid scm-contact-view-modern' +
      (role === "all" ? "" : " scm-contact-view-single") +
      '">' +
      (role !== "tenant"
        ? '<section class="scm-contact-view-card scm-contact-view-card-owner"><div class="scm-contact-view-card-head"><h5>Propietario</h5><span>Titular registrado</span></div>' +
          (propietario
            ? '<dl class="scm-detail-list">' + propietario + "</dl>"
            : '<p class="scm-muted">Sin datos de propietario.</p>') +
          "</section>"
        : "") +
      (role !== "owner"
        ? '<section class="scm-contact-view-card scm-contact-view-card-tenant"><div class="scm-contact-view-card-head"><h5>Arrendatario</h5><span>Arrendatario activo</span></div>' +
          (arrendatario
            ? '<dl class="scm-detail-list">' + arrendatario + "</dl>"
            : '<p class="scm-muted">Sin datos de arrendatario.</p>') +
          "</section>"
        : "") +
      "</div>" +
      '<div class="scm-contact-view-footer"><span><span class="material-symbols-outlined">lock</span> Edición auditada bajo protocolo RGPD / Habeas Data</span></div>' +
      "</div>"
    );
  }

  function openContactViewer(modal, caseBtn, role) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    role = normalizeContactRole(role);
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    sub.classList.add("scm-case-submodal--contacts");
    if (title) {
      title.textContent =
        role === "owner"
          ? "Datos del propietario"
          : role === "tenant"
            ? "Datos del arrendatario"
            : "Contactos del caso y titulares";
    }
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML = renderContactViewerHtml(caseBtn, role);
      var editBtn = body.querySelector("[data-scm-edit-contacts-from-view]");
      if (editBtn) {
        editBtn.addEventListener("click", function () {
          openContactEditor(
            modal,
            caseBtn,
            editBtn.dataset.scmContactRole || role,
          );
        });
      }
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
          "<dt>Celular</dt><dd><strong>" + escHtml(celular) + "</strong></dd>";
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
    if (body) {
      body.innerHTML = detail.html || "";
      prependCaseLocationPanel(body, detail.caseBtn || null, modal);
    }
    if (detail.caseBtn && detail.caseBtn.dataset) {
      modal.dataset.ticketPk = detail.caseBtn.dataset.ticketPk || "";
      modal.dataset.idInmuebleWeb = detail.caseBtn.dataset.idInmuebleWeb || "";
      modal.dataset.idInmuebleData =
        detail.caseBtn.dataset.idInmuebleData || "";
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
    if (title)
      title.textContent = isPublicPqr ? "Cerrar solicitud" : "Cerrar ticket";
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<form class="scm-close-ticket-form" method="post" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<p class="scm-muted">Esta acci&oacute;n cerrar&aacute; ' +
        (isPublicPqr ? "la solicitud" : "el ticket") +
        " y marcar&aacute; el estado administrativo como Finalizado.</p>" +
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

  function openChangeAdminStateEditor(modal, caseBtn) {
    var sub = ensureCaseSubmodal(modal);
    if (!sub || !caseBtn) return;
    var title = sub.querySelector(".scm-case-submodal-title");
    var body = sub.querySelector(".scm-case-submodal-body");
    var ticketPk = caseBtn.dataset.ticketPk || "";
    var currentAdmin = (caseBtn.dataset.admin || "").trim();
    if (title) title.textContent = "Cambiar estado administrativo";
    setCaseSubmodalMeta(sub, caseBtn);
    if (body) {
      body.innerHTML =
        '<form class="scm-change-admin-state-form" method="post" autocomplete="off">' +
        '<input type="hidden" name="ticket_pk" value="' +
        escHtml(ticketPk) +
        '">' +
        '<input type="hidden" name="estado_ticket" value="__keep__">' +
        '<input type="hidden" name="estado_cotizacion" value="__keep__">' +
        '<input type="hidden" name="notify_recipients_present" value="1">' +
        '<p class="scm-muted">Selecciona el nuevo estado administrativo del ticket #' +
        escHtml(ticketPk) +
        " (estado actual: <strong>" +
        escHtml(currentAdmin || "Sin asignar") +
        "</strong>).</p>" +
        '<label class="scm-seg-field"><span>Nuevo estado administrativo</span>' +
        '<select name="estado_administrativo" class="scm-select" required>' +
        '<option value="">Selecciona un estado</option>' +
        '<option value="Nuevo">Nuevo</option>' +
        '<option value="En espera de respuesta">En espera de respuesta</option>' +
        '<option value="Por inspeccionar">Por inspeccionar</option>' +
        '<option value="Inspeccionado">Inspeccionado</option>' +
        '<option value="Cotizado">Cotizado</option>' +
        '<option value="En ejecucion por inmobiliaria">En ejecucion por inmobiliaria</option>' +
        '<option value="En ejecucion por propietario">En ejecucion por propietario</option>' +
        '<option value="En ejecucion por arrendatario">En ejecucion por arrendatario</option>' +
        '<option value="En ejecucion por copropiedad">En ejecucion por copropiedad</option>' +
        '<option value="Finalizado">Finalizado</option>' +
        '<option value="Trasladado">Trasladado</option>' +
        '<option value="Entregado">Entregado</option>' +
        '<option value="Recibido">Recibido</option>' +
        '<option value="Desistido">Desistido</option>' +
        "</select></label>" +
        '<label class="scm-seg-field"><span>Motivo / Observación del cambio</span>' +
        '<textarea name="observacion" rows="4" required placeholder="Escribe el motivo del cambio de estado...">Cambio de estado administrativo a </textarea></label>' +
        renderNotifyTargets([]) +
        '<div class="scm-seg-actions"><button type="submit" class="scm-btn-primary">Guardar cambio de estado</button><span class="scm-seg-msg" aria-live="polite"></span></div>' +
        "</form>";
      prependCaseLocationPanel(body, caseBtn, modal);
    }
    sub.classList.add("open");
    sub.setAttribute("aria-hidden", "false");

    var form = body ? body.querySelector(".scm-change-admin-state-form") : null;
    if (form) {
      var stateSelect = form.querySelector(
        'select[name="estado_administrativo"]',
      );
      var obsTextarea = form.querySelector('textarea[name="observacion"]');
      if (stateSelect && obsTextarea) {
        stateSelect.addEventListener("change", function () {
          if (stateSelect.value) {
            obsTextarea.value =
              "Cambio de estado administrativo a: " + stateSelect.value;
          }
        });
      }
      form.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var root =
          findRootFromNode(modal) ||
          modal.closest("#scm-app") ||
          document.querySelector("#scm-app");
        var runtime = parseRuntime(root) || {};
        var action =
          (runtime.actions && runtime.actions.seg) || "scm_guardar_seguimiento";
        var submitBtn = form.querySelector('button[type="submit"]');
        var msg = form.querySelector(".scm-seg-msg");
        var fd = new FormData(form);
        fd.set("action", action);
        fd.set("nonce", runtime.nonce || "");
        if (submitBtn) submitBtn.disabled = true;
        if (msg) {
          msg.textContent = "Guardando cambio de estado...";
          msg.classList.remove("error");
        }
        fetch(runtime.ajaxUrl || "api.php", {
          method: "POST",
          body: fd,
          credentials: "same-origin",
          headers: { Accept: "application/json" },
        })
          .then(function (res) {
            return res.json();
          })
          .then(function (json) {
            if (!json || !json.success) {
              throw new Error(
                (json && json.data && json.data.message) ||
                  "No se pudo actualizar el estado.",
              );
            }
            if (msg) msg.textContent = "Estado actualizado correctamente.";
            scmNotify(
              "success",
              "Estado administrativo actualizado correctamente.",
              "Estado del caso",
            );
            closeCaseSubmodal(modal);
            dispatchCaseActionSaved(root, ticketPk, form);
          })
          .catch(function (err) {
            if (msg) {
              msg.textContent = err.message || "Error al actualizar.";
              msg.classList.add("error");
            }
            scmNotify(
              "error",
              err.message || "Error al actualizar estado administrativo.",
            );
          })
          .finally(function () {
            if (submitBtn) submitBtn.disabled = false;
          });
      });
    }
  }

  function initCaseComposer(modal, caseBtn) {
    var composer = modal.querySelector("[data-scm-composer]");
    if (!composer || !caseBtn) return;

    var root =
      findRootFromNode(modal) ||
      modal.closest("#scm-app") ||
      document.querySelector("#scm-app");
    var runtime = parseRuntime(root) || {};
    var ajaxUrl = runtime.ajaxUrl || "";
    var nonce = runtime.nonce || "";
    var actions = runtime.actions || {};

    var tabs = composer.querySelectorAll("[data-composer-tab]");
    var visBadge = composer.querySelector("[data-scm-composer-visibility]");
    var input = composer.querySelector("[data-scm-composer-input]");
    var fileInput = composer.querySelector("[data-scm-composer-files]");
    var preview = composer.querySelector("[data-scm-composer-preview]");
    var discardBtn = composer.querySelector("[data-scm-composer-discard]");
    var submitBtn = composer.querySelector("[data-scm-composer-submit]");
    var submitLabel = composer.querySelector(
      "[data-scm-composer-submit-label]",
    );
    var cannedBtn = composer.querySelector("[data-scm-composer-canned]");
    var templateBtn = composer.querySelector("[data-scm-composer-template]");
    var pasteBtn = composer.querySelector("[data-scm-composer-paste]");
    var notifyRow = composer.querySelector("[data-scm-composer-notify-row]");
    var notifyOptions = composer.querySelector(
      "[data-scm-composer-notify-options]",
    );
    var privateNotice = composer.querySelector(
      "[data-scm-composer-private-notice]",
    );
    var toolsWrap = composer.querySelector(".scm-composer-tools");
    var docsContainer = composer.querySelector("[data-scm-composer-docs]");
    var addDocBtn = composer.querySelector("[data-scm-composer-add-doc]");
    var responseOptions = composer.querySelector(
      "[data-scm-composer-response-options]",
    );
    var adminStateSelect = composer.querySelector(
      "[data-scm-composer-admin-state]",
    );
    var closeTicketCheck = composer.querySelector(
      "[data-scm-composer-close-ticket]",
    );

    var isPublicPqr = (caseBtn.dataset.caseKind || "") === "public-pqr";
    var ticketPk = String(
      caseBtn.dataset.ticketPk ||
        modal.dataset.ticketPk ||
        caseBtn.dataset.ticket ||
        "",
    ).trim();
    var recipient =
      (isPublicPqr
        ? caseBtn.dataset.solicitante
        : caseBtn.dataset.arrendatario) ||
      (isPublicPqr ? "Solicitante" : "Arrendatario");
    var mode = "reply";
    var composerFiles = [];

    function clearDocs() {
      if (docsContainer) {
        docsContainer.innerHTML = "";
        docsContainer.style.display = "none";
      }
    }

    function addDocumentRow(presetFile, presetTitle) {
      if (mode === "note") return null;
      if (!docsContainer) return null;
      docsContainer.style.display = "flex";

      var row = document.createElement("div");
      row.className = "scm-composer-doc-row";
      row.setAttribute("data-scm-doc-row", "1");

      row.innerHTML =
        '<div class="scm-composer-doc-icon">' +
        '<span class="material-symbols-outlined text-[18px]">description</span>' +
        "</div>" +
        '<div class="scm-composer-doc-fields">' +
        '<input type="text" class="scm-composer-doc-name-input" name="documento_nombre[]" placeholder="Nombre o título del documento (ej. Cotización, Cuenta de cobro, Acta...)" />' +
        '<label class="scm-composer-doc-file-btn">' +
        '<input type="file" class="scm-composer-doc-file-input" style="display:none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.txt,.csv,image/*">' +
        '<span class="material-symbols-outlined text-[16px]">upload_file</span>' +
        '<span class="scm-composer-doc-file-text" data-doc-file-text>Seleccionar archivo...</span>' +
        "</label>" +
        "</div>" +
        '<button type="button" class="scm-composer-doc-remove" title="Quitar este documento">&times;</button>';

      docsContainer.appendChild(row);

      var fileInput = row.querySelector(".scm-composer-doc-file-input");
      var fileText = row.querySelector("[data-doc-file-text]");
      var titleInput = row.querySelector(".scm-composer-doc-name-input");
      var removeBtn = row.querySelector(".scm-composer-doc-remove");

      function setFile(file) {
        if (!file) return;
        row._attachedFile = file;
        row.classList.add("has-file");
        row.classList.remove("is-error");
        var sizeKb = Math.max(1, Math.round(file.size / 1024));
        if (fileText) {
          fileText.textContent = file.name + " (" + sizeKb + " KB)";
          fileText.title = file.name;
        }
        if (titleInput && !titleInput.value.trim()) {
          var cleanTitle = file.name
            .replace(/\.[a-z0-9]+$/i, "")
            .replace(/[-_]+/g, " ");
          titleInput.value = cleanTitle;
        }
      }

      if (presetFile) {
        setFile(presetFile);
      }
      if (presetTitle && titleInput) {
        titleInput.value = presetTitle;
      }

      if (fileInput) {
        fileInput.addEventListener("change", function () {
          if (fileInput.files && fileInput.files[0]) {
            setFile(fileInput.files[0]);
          }
        });
      }

      if (removeBtn) {
        removeBtn.addEventListener("click", function () {
          row.remove();
          if (!docsContainer.querySelector("[data-scm-doc-row]")) {
            docsContainer.style.display = "none";
          }
        });
      }

      return { row: row, fileInput: fileInput, titleInput: titleInput };
    }

    if (addDocBtn) {
      addDocBtn.addEventListener("click", function () {
        var created = addDocumentRow();
        if (created && created.fileInput) {
          created.fileInput.click();
        }
      });
    }

    function renderFilePreviews() {
      if (!preview) return;
      preview.innerHTML = "";
      if (!composerFiles.length) {
        preview.style.display = "none";
        return;
      }
      preview.style.display = "flex";
      composerFiles.forEach(function (file, idx) {
        var chip = document.createElement("div");
        chip.className = "scm-composer-file-chip";
        var isImg = file.type && file.type.indexOf("image/") === 0;
        var sizeKb = Math.max(1, Math.round(file.size / 1024));
        var thumbHtml = "";
        if (isImg) {
          try {
            var url = URL.createObjectURL(file);
            thumbHtml =
              '<img src="' +
              url +
              '" class="scm-composer-chip-thumb" alt="Preview">';
          } catch (e) {
            thumbHtml =
              '<span class="material-symbols-outlined text-[16px] text-blue-600">image</span>';
          }
        } else {
          thumbHtml =
            '<span class="material-symbols-outlined text-[16px] text-blue-600">description</span>';
        }
        chip.innerHTML =
          thumbHtml +
          '<div class="scm-composer-chip-info">' +
          '<span class="scm-composer-chip-name">' +
          escHtml(file.name) +
          "</span>" +
          '<span class="scm-composer-chip-size">' +
          sizeKb +
          " KB</span>" +
          "</div>" +
          '<button type="button" class="scm-composer-chip-remove" data-remove-file-idx="' +
          idx +
          '" title="Quitar archivo">&times;</button>';
        preview.appendChild(chip);
      });
    }

    if (preview) {
      preview.addEventListener("click", function (e) {
        var removeBtn = e.target.closest("[data-remove-file-idx]");
        if (removeBtn) {
          e.preventDefault();
          var idx = parseInt(
            removeBtn.getAttribute("data-remove-file-idx"),
            10,
          );
          if (!isNaN(idx) && idx >= 0 && idx < composerFiles.length) {
            composerFiles.splice(idx, 1);
            renderFilePreviews();
          }
        }
      });
    }

    function addFiles(files) {
      if (mode === "note") return;
      if (!files || !files.length) return;
      Array.prototype.forEach.call(files, function (f) {
        composerFiles.push(f);
      });
      renderFilePreviews();
    }

    if (fileInput) {
      fileInput.addEventListener("change", function () {
        if (fileInput.files && fileInput.files.length) {
          addFiles(fileInput.files);
          fileInput.value = "";
        }
      });
    }

    // Clipboard image paste (Ctrl+V) handler on modal & composer
    function handleClipboardImagePaste(e) {
      if (mode === "note") return;
      var clipboard = e.clipboardData || window.clipboardData;
      if (!clipboard || !clipboard.items) return;
      var pastedFiles = [];
      for (var i = 0; i < clipboard.items.length; i++) {
        var item = clipboard.items[i];
        if (item && item.type && item.type.indexOf("image/") === 0) {
          var blob = item.getAsFile();
          if (blob) {
            var ext =
              (blob.type || "image/png")
                .split("/")
                .pop()
                .replace(/[^a-z0-9]/gi, "") || "png";
            var fileName =
              "imagen-" +
              Date.now() +
              "-" +
              (composerFiles.length + pastedFiles.length + 1) +
              "." +
              ext;
            pastedFiles.push(
              new File([blob], fileName, { type: blob.type || "image/png" }),
            );
          }
        }
      }
      if (pastedFiles.length > 0) {
        e.preventDefault();
        addFiles(pastedFiles);
        if (typeof scmNotify === "function") {
          scmNotify(
            "info",
            pastedFiles.length === 1
              ? "Imagen pegada adjuntada."
              : pastedFiles.length + " imágenes pegadas.",
          );
        }
      }
    }

    composer.addEventListener("paste", handleClipboardImagePaste);
    modal.addEventListener("paste", handleClipboardImagePaste);

    // Drag and drop images and documents onto composer
    composer.addEventListener("dragover", function (e) {
      if (mode === "note") return;
      e.preventDefault();
      composer.classList.add("is-dragover");
    });
    composer.addEventListener("dragleave", function () {
      composer.classList.remove("is-dragover");
    });
    composer.addEventListener("drop", function (e) {
      if (mode === "note") return;
      e.preventDefault();
      composer.classList.remove("is-dragover");
      if (
        e.dataTransfer &&
        e.dataTransfer.files &&
        e.dataTransfer.files.length
      ) {
        var dropped = Array.prototype.slice.call(e.dataTransfer.files);
        var images = [];
        var docs = [];
        dropped.forEach(function (f) {
          if (f.type && f.type.indexOf("image/") === 0) {
            images.push(f);
          } else {
            docs.push(f);
          }
        });
        if (images.length) {
          addFiles(images);
        }
        if (docs.length) {
          docs.forEach(function (df) {
            addDocumentRow(df);
          });
        }
      }
    });

    if (pasteBtn) {
      pasteBtn.addEventListener("click", function () {
        if (mode === "note") return;
        if (navigator.clipboard && navigator.clipboard.read) {
          navigator.clipboard
            .read()
            .then(function (items) {
              var found = false;
              for (var i = 0; i < items.length; i++) {
                for (var j = 0; j < items[i].types.length; j++) {
                  var type = items[i].types[j];
                  if (type.indexOf("image/") === 0) {
                    found = true;
                    items[i].getType(type).then(function (blob) {
                      var ext =
                        (blob.type || "image/png")
                          .split("/")
                          .pop()
                          .replace(/[^a-z0-9]/gi, "") || "png";
                      var fileName = "imagen-" + Date.now() + "." + ext;
                      addFiles([
                        new File([blob], fileName, {
                          type: blob.type || "image/png",
                        }),
                      ]);
                      if (typeof scmNotify === "function") {
                        scmNotify(
                          "info",
                          "Imagen pegada desde el portapapeles.",
                        );
                      }
                    });
                  }
                }
              }
              if (!found) {
                if (input) input.focus();
                if (typeof scmNotify === "function") {
                  scmNotify(
                    "info",
                    "Copia una imagen o captura al portapapeles y presiona Ctrl+V.",
                  );
                }
              }
            })
            .catch(function () {
              if (input) input.focus();
              if (typeof scmNotify === "function") {
                scmNotify("info", "Presiona Ctrl+V para pegar la imagen aquí.");
              }
            });
        } else {
          if (input) input.focus();
          if (typeof scmNotify === "function") {
            scmNotify("info", "Presiona Ctrl+V para pegar la imagen aquí.");
          }
        }
      });
    }

    // Mutual exclusion on recipient checkboxes
    if (notifyOptions) {
      notifyOptions.addEventListener("change", function (e) {
        var target = e.target;
        if (!target || target.name !== "composer_notify[]") return;
        var allCbs = notifyOptions.querySelectorAll(
          'input[name="composer_notify[]"]',
        );
        if (target.value === "none" && target.checked) {
          allCbs.forEach(function (cb) {
            if (cb !== target) cb.checked = false;
          });
        } else if (target.value !== "none" && target.checked) {
          var noneCb = notifyOptions.querySelector(
            'input[name="composer_notify[]"][value="none"]',
          );
          if (noneCb) noneCb.checked = false;
        }
      });
    }

    function setTabMode(newMode) {
      mode = newMode;
      var isNoteMode = mode === "note";
      composer.classList.toggle("is-note-mode", isNoteMode);
      tabs.forEach(function (t) {
        if (t.getAttribute("data-composer-tab") === mode) {
          t.classList.add("active");
        } else {
          t.classList.remove("active");
        }
      });

      var clientCbs = notifyOptions
        ? notifyOptions.querySelectorAll(
            'input[name="composer_notify[]"]:not([value="admin"]):not([value="none"])',
          )
        : [];
      var adminCb = notifyOptions
        ? notifyOptions.querySelector(
            'input[name="composer_notify[]"][value="admin"]',
          )
        : null;
      var noneCb = notifyOptions
        ? notifyOptions.querySelector(
            'input[name="composer_notify[]"][value="none"]',
          )
        : null;

      if (mode === "reply") {
        if (visBadge) visBadge.style.display = "none";
        if (input)
          input.placeholder =
            "Escriba una respuesta o actualización sobre el caso...";
        if (submitLabel)
          submitLabel.textContent = isPublicPqr
            ? "Responder solicitud"
            : "Responder caso";
        if (privateNotice) {
          privateNotice.classList.remove("is-active");
          privateNotice.style.display = "none";
        }
        if (notifyOptions) notifyOptions.style.display = "";
        clientCbs.forEach(function (cb) {
          cb.disabled = false;
          if (cb.value === "solicitante" || cb.value === "arrendatario")
            cb.checked = true;
          else cb.checked = false;
        });
        if (adminCb) {
          adminCb.disabled = false;
          adminCb.checked = false;
        }
        if (noneCb) {
          noneCb.disabled = false;
          noneCb.checked = false;
        }
      } else if (mode === "followup") {
        if (visBadge) visBadge.style.display = "none";
        if (input)
          input.placeholder =
            "Escriba el avance o seguimiento técnico del caso...";
        if (submitLabel) submitLabel.textContent = "Registrar Seguimiento";
        if (privateNotice) {
          privateNotice.classList.remove("is-active");
          privateNotice.style.display = "none";
        }
        if (notifyOptions) notifyOptions.style.display = "";
        clientCbs.forEach(function (cb) {
          cb.disabled = false;
          cb.checked = true;
        });
        if (adminCb) {
          adminCb.disabled = false;
          adminCb.checked = true;
        }
        if (noneCb) {
          noneCb.disabled = false;
          noneCb.checked = false;
        }
      } else {
        // mode === "note"
        if (visBadge) visBadge.style.display = "none";
        if (input)
          input.placeholder =
            "Escriba una nota interna o diagnóstico para el equipo técnico...";
        if (submitLabel) submitLabel.textContent = "Guardar Nota Interna";
        if (privateNotice) {
          privateNotice.classList.remove("is-active");
          privateNotice.style.display = "none";
        }
        composerFiles = [];
        renderFilePreviews();
        clearDocs();
        clientCbs.forEach(function (cb) {
          cb.checked = false;
          cb.disabled = true;
        });
        if (adminCb) {
          adminCb.disabled = true;
          adminCb.checked = false;
        }
        if (noneCb) {
          noneCb.disabled = true;
          noneCb.checked = true;
        }
        if (adminStateSelect) adminStateSelect.value = "__keep__";
        if (closeTicketCheck) closeTicketCheck.checked = false;
      }
      var prevBox = composer.querySelector(
        "[data-scm-composer-preventiva-box]",
      );
      var cotBox = composer.querySelector("[data-scm-composer-cotizacion-box]");
      if (notifyRow) notifyRow.style.display = mode === "note" ? "none" : "";
      if (toolsWrap) toolsWrap.style.display = mode === "note" ? "none" : "";
      if (responseOptions)
        responseOptions.style.display = mode === "reply" ? "" : "none";
      if (prevBox) prevBox.style.display = mode === "reply" ? "" : "none";
      if (cotBox) cotBox.style.display = mode === "reply" ? "" : "none";
    }

    var noAccessCheck = composer.querySelector("[data-scm-composer-no-access]");
    if (noAccessCheck && adminStateSelect) {
      noAccessCheck.addEventListener("change", function () {
        if (noAccessCheck.checked) {
          adminStateSelect.value = "En espera de respuesta";
        }
      });
      adminStateSelect.addEventListener("change", function () {
        if (
          noAccessCheck.checked &&
          adminStateSelect.value !== "En espera de respuesta"
        ) {
          noAccessCheck.checked = false;
        }
      });
    }

    var cotStateSelect = composer.querySelector(
      "[data-scm-composer-cot-estado]",
    );
    var cotDetails = composer.querySelector("[data-scm-composer-cot-details]");
    var cotMotivo = composer.querySelector("[data-scm-composer-cot-motivo]");
    var cotFin = composer.querySelector("[data-scm-composer-cot-financiacion]");
    if (cotStateSelect) {
      cotStateSelect.addEventListener("change", function () {
        var val = cotStateSelect.value;
        if (cotDetails) {
          cotDetails.style.display =
            val === "Aprobada" || val === "Desaprobada" ? "flex" : "none";
        }
        if (cotMotivo) {
          cotMotivo.style.display = val === "Desaprobada" ? "block" : "none";
        }
        if (cotFin) {
          cotFin.style.display = val === "Aprobada" ? "block" : "none";
        }
      });
    }

    tabs.forEach(function (tab) {
      tab.addEventListener("click", function () {
        setTabMode(tab.getAttribute("data-composer-tab") || "reply");
      });
    });

    if (discardBtn) {
      discardBtn.addEventListener("click", function () {
        if (input) input.value = "";
        composerFiles = [];
        renderFilePreviews();
        clearDocs();
        var prevBox = composer.querySelector(
          "[data-scm-composer-preventiva-box]",
        );
        if (prevBox) {
          var noAcc = prevBox.querySelector("[data-scm-composer-no-access]");
          if (noAcc) noAcc.checked = false;
        }
        if (adminStateSelect) adminStateSelect.value = "__keep__";
        if (closeTicketCheck) closeTicketCheck.checked = false;
        var cotBox = composer.querySelector(
          "[data-scm-composer-cotizacion-box]",
        );
        if (cotBox) {
          var cotSel = cotBox.querySelector("[data-scm-composer-cot-estado]");
          if (cotSel) {
            cotSel.value = "__keep__";
            cotSel.dispatchEvent(new Event("change"));
          }
        }
      });
    }

    if (templateBtn) {
      templateBtn.addEventListener("click", function () {
        var checklist =
          "📋 DIAGNÓSTICO TÉCNICO:\n• Problema identificado:\n• Causa raíz:\n• Solución técnica propuesta:\n• Materiales requeridos:";
        if (input) {
          if (input.value.trim()) {
            input.value += "\n\n" + checklist;
          } else {
            input.value = checklist;
          }
          input.focus();
        }
      });
    }

    if (cannedBtn) {
      cannedBtn.addEventListener("click", function () {
        var canned =
          "Estimado/a " +
          recipient +
          ", le informamos que su caso se encuentra en trámite activo con nuestro equipo técnico. Nos comunicaremos nuevamente con el avance correspondiente.";
        if (input) {
          if (input.value.trim()) {
            input.value += "\n\n" + canned;
          } else {
            input.value = canned;
          }
          input.focus();
        }
      });
    }

    if (submitBtn) {
      submitBtn.addEventListener("click", function () {
        var text = (input ? input.value : "").trim();
        if (!text) {
          if (input) input.focus();
          if (typeof scmNotify === "function") {
            scmNotify(
              "warning",
              "Por favor escriba una observación o respuesta antes de guardar.",
            );
          }
          return;
        }

        var selectedRecipients = [];
        if (notifyOptions) {
          var checkedCbs = notifyOptions.querySelectorAll(
            'input[name="composer_notify[]"]:checked',
          );
          checkedCbs.forEach(function (cb) {
            selectedRecipients.push(cb.value);
          });
        }
        if (!selectedRecipients.length) {
          selectedRecipients = ["none"];
        }
        if (mode === "note") {
          selectedRecipients = ["none"];
        }

        var docRows =
          mode === "note"
            ? []
            : docsContainer
              ? docsContainer.querySelectorAll("[data-scm-doc-row]")
              : [];
        var hasMissingDocFile = false;
        var validDocs = [];

        docRows.forEach(function (row) {
          var rowFileInput = row.querySelector(".scm-composer-doc-file-input");
          var rowTitleInput = row.querySelector(".scm-composer-doc-name-input");
          var rowFile =
            (rowFileInput && rowFileInput.files && rowFileInput.files[0]) ||
            row._attachedFile ||
            null;
          var rowTitle = rowTitleInput ? rowTitleInput.value.trim() : "";

          if (rowFile) {
            validDocs.push({ file: rowFile, title: rowTitle || rowFile.name });
          } else if (rowTitle) {
            hasMissingDocFile = true;
            row.classList.add("is-error");
          }
        });

        if (hasMissingDocFile) {
          if (typeof scmNotify === "function") {
            scmNotify(
              "warning",
              "Por favor selecciona el archivo correspondiente a cada documento con título o elimina la fila.",
            );
          }
          return;
        }

        var origBtnHtml = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML =
          '<span class="material-symbols-outlined text-[16px] animate-spin">refresh</span> Guardando...';

        var fd = new FormData();
        fd.append("nonce", nonce);
        fd.append("ticket_pk", ticketPk);
        if (mode !== "note") {
          fd.append("notify_recipients_present", "1");
          selectedRecipients.forEach(function (rec) {
            fd.append("notify_recipients[]", rec);
          });
        }

        if (mode !== "note") {
          composerFiles.forEach(function (file) {
            if (mode === "reply") {
              fd.append("imagen[]", file);
            } else {
              fd.append("evidencia[]", file);
            }
          });
        }

        if (mode !== "note") {
          validDocs.forEach(function (doc) {
            fd.append("documento[]", doc.file);
            fd.append("documento_nombre[]", doc.title);
          });
        }

        if (mode === "reply") {
          fd.append(
            "action",
            actions.ticket_response || "scm_ajax_ticket_response",
          );
          fd.append("respuesta", text);
          var adminState = adminStateSelect
            ? adminStateSelect.value || "__keep__"
            : "__keep__";
          var noAccessCb = composer.querySelector(
            "[data-scm-composer-no-access]",
          );
          if (noAccessCb && noAccessCb.checked) {
            fd.append("generar_acta_no_acceso_preventiva", "1");
            adminState = "En espera de respuesta";
          }
          fd.append("estado_administrativo", adminState);
          fd.append(
            "cerrar_ticket",
            closeTicketCheck && closeTicketCheck.checked ? "1" : "0",
          );

          var cotSelect = composer.querySelector(
            "[data-scm-composer-cot-estado]",
          );
          var cotState = cotSelect ? cotSelect.value || "__keep__" : "__keep__";
          if (cotState && cotState !== "__keep__") {
            fd.append("estado_cotizacion", cotState);
            fd.append("id_cotizacion", caseBtn.dataset.cotizacionId || "");
            var motivoInput = composer.querySelector(
              "[data-scm-composer-cot-motivo-input]",
            );
            var finInput = composer.querySelector(
              "[data-scm-composer-cot-fin-input]",
            );
            var obsInput = composer.querySelector(
              "[data-scm-composer-cot-obs]",
            );
            if (motivoInput && motivoInput.value) {
              fd.append("motivo_cotizacion", motivoInput.value);
            }
            if (finInput && finInput.value) {
              fd.append("financiacion_cotizacion", finInput.value);
            }
            if (obsInput && obsInput.value) {
              fd.append("observacion_cotizacion", obsInput.value);
            }
          }
        } else if (mode === "followup") {
          fd.append("action", actions.seg || "scm_ajax_ticket_seguimiento");
          fd.append("observacion", text);
          fd.append("estado_ticket", "__keep__");
          fd.append("estado_administrativo", "__keep__");
          fd.append("estado_cotizacion", "__keep__");
        } else {
          fd.append("action", actions.nota || "scm_ajax_case_note");
          fd.append("observacion", text);
        }

        fetch(ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
          .then(function (r) {
            return r.json();
          })
          .then(function (json) {
            if (!json || !json.success) {
              var errText =
                (json && json.data ? json.data.message || json.data : null) ||
                "Error al guardar.";
              throw new Error(errText);
            }
            var successMsg =
              json.data && json.data.message
                ? json.data.message
                : mode === "reply"
                  ? "Respuesta publicada con éxito."
                  : mode === "followup"
                    ? "Seguimiento registrado con éxito."
                    : "Nota interna guardada con éxito.";
            if (typeof scmNotify === "function") {
              scmNotify("success", successMsg);
            } else if (typeof showToast === "function") {
              showToast("success", successMsg);
            }

            if (input) input.value = "";
            composerFiles = [];
            renderFilePreviews();
            clearDocs();

            // Refresh modal and timeline in place!
            if (root) {
              root.dispatchEvent(
                new CustomEvent("scm:case-action-saved", {
                  detail: { ticketPk: ticketPk, fromNode: modal },
                }),
              );
            }
          })
          .catch(function (err) {
            var msg = err && err.message ? err.message : "Error al guardar.";
            if (typeof scmNotify === "function") {
              scmNotify("error", msg);
            } else if (typeof showToast === "function") {
              showToast("error", msg);
            }
          })
          .finally(function () {
            submitBtn.disabled = false;
            submitBtn.innerHTML = origBtnHtml;
          });
      });
    }

    // Timeline filtering
    function applyTimelineFilter(filterVal) {
      var feed = modal.querySelector("[data-scm-timeline-feed]");
      var emptyNotice = modal.querySelector("[data-scm-unified-empty]");
      var items = modal.querySelectorAll(
        "[data-scm-timeline-feed] .scm-case-history-item",
      );
      var matchedCount = 0;

      items.forEach(function (item) {
        var itemType = item.getAttribute("data-history-type") || "reply";
        var itemText = (item.textContent || "").toLowerCase();
        var match = false;

        if (filterVal === "all") {
          match = true;
        } else if (filterVal === "reply" || filterVal === "public") {
          match =
            itemType === "reply" ||
            itemType === "public" ||
            (itemType !== "followup" &&
              itemType !== "note" &&
              itemType !== "internal");
        } else if (filterVal === "followup") {
          match =
            itemType === "followup" || itemText.indexOf("seguimiento") !== -1;
        } else if (filterVal === "note" || filterVal === "internal") {
          match =
            itemType === "note" ||
            itemType === "internal" ||
            itemText.indexOf("nota interna") !== -1 ||
            itemText.indexOf("privada") !== -1;
        }

        if (match) {
          item.style.display = "";
          matchedCount++;
        } else {
          item.style.display = "none";
        }
      });

      if (emptyNotice) {
        if (matchedCount === 0) {
          emptyNotice.classList.add("is-visible");
          emptyNotice.style.display = "flex";
          var emptyText = emptyNotice.querySelector("p");
          if (emptyText) {
            emptyText.textContent =
              items.length === 0
                ? "No hay historial registrado en este caso."
                : "No se encontraron registros para el filtro seleccionado.";
          }
        } else {
          emptyNotice.classList.remove("is-visible");
          emptyNotice.style.display = "none";
        }
      }
    }

    var filterSelect = modal.querySelector("[data-scm-timeline-filter]");
    if (filterSelect) {
      filterSelect.addEventListener("change", function () {
        applyTimelineFilter(filterSelect.value);
      });
      applyTimelineFilter(filterSelect.value || "all");
    }
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
      var card = btn.closest(".scm-ticket-card, .scm-cotizacion-card");
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

      var ticketNumStr = btn.dataset.ticket || btn.dataset.ticketPk || "-";
      var breadcrumbNum = modal.querySelector("#scm-case-breadcrumb-num");
      var breadcrumbDept = modal.querySelector("#scm-case-breadcrumb-dept");
      if (breadcrumbNum) {
        breadcrumbNum.textContent = "#" + ticketNumStr;
      }
      if (breadcrumbDept) {
        breadcrumbDept.textContent =
          btn.dataset.departamento ||
          (isPublicPqr ? "Solicitud Web" : "Mantenimiento");
      }

      if (title) {
        var asuntoText =
          btn.dataset.asunto ||
          (isPublicPqr
            ? "Solicitud creada desde un portal web"
            : "Caso de servicios inmobiliarios");
        title.innerHTML =
          '<span class="scm-case-title-num">' +
          (isPublicPqr ? "Solicitud #" : "Caso #") +
          escHtml(ticketNumStr) +
          "</span>: " +
          escHtml(asuntoText);
      }
      if (subtitle) {
        var creadoDate = btn.dataset.creado || "";
        var dirText = btn.dataset.direccion || "";
        var barrioText = btn.dataset.barrio || "";
        var contratoText = (btn.dataset.contrato || "")
          .trim()
          .replace(/^#+/, "");
        var inmuebleVal = (btn.dataset.inmueble || "")
          .trim()
          .replace(/^#+/, "");
        var ejecucionText = (btn.dataset.ejecucion || "").trim();
        var sinActualizarText = (btn.dataset.sinActualizar || "").trim();
        var subParts = [];
        if (creadoDate) {
          subParts.push(
            '<span class="scm-meta-bit"><span class="material-symbols-outlined">calendar_today</span> ' +
              escHtml(creadoDate) +
              "</span>",
          );
        }
        if (dirText || barrioText) {
          subParts.push(
            '<span class="scm-meta-bit"><span class="material-symbols-outlined">domain</span> ' +
              escHtml(
                (barrioText ? barrioText + " • " : "") + (dirText || ""),
              ) +
              "</span>",
          );
        }
        if (contratoText && contratoText !== "-") {
          subParts.push(
            '<span class="scm-meta-bit"><span class="material-symbols-outlined">description</span> Contrato #' +
              escHtml(contratoText) +
              "</span>",
          );
        }
        if (inmuebleVal && inmuebleVal !== "-") {
          var cleanInmueble = inmuebleVal.replace(/^#+/, "");
          subParts.push(
            '<span class="scm-meta-bit"><span class="material-symbols-outlined">domain</span> Inmueble simi: #' +
              escHtml(cleanInmueble) +
              "</span>",
          );
        }
        if (ejecucionText && ejecucionText !== "-") {
          subParts.push(
            '<span class="scm-meta-bit scm-meta-bit-ejecucion"><span class="material-symbols-outlined text-[15px]">hourglass_top</span> En ejecución: ' +
              escHtml(ejecucionText) +
              "</span>",
          );
        }
        if (sinActualizarText && sinActualizarText !== "-") {
          subParts.push(
            '<span class="scm-meta-bit scm-meta-bit-sin-act"><span class="material-symbols-outlined text-[15px]">history</span> Sin actualizar: ' +
              escHtml(sinActualizarText) +
              "</span>",
          );
        }
        subtitle.innerHTML = subParts.join("");
      }
      modal.dataset.ticketPk = btn.dataset.ticketPk || "";
      modal.dataset.caseKind = isPublicPqr ? "public-pqr" : "";
      modal.dataset.idInmuebleWeb = btn.dataset.idInmuebleWeb || "";
      modal.dataset.idInmuebleData = btn.dataset.idInmuebleData || "";
      modal.dataset.ubicacionGoogleMaps = btn.dataset.ubicacionGoogleMaps || "";
      modal.dataset.direccion = btn.dataset.direccion || "";
      if (meta) {
        meta.innerHTML = "";
        var estadoVal = btn.dataset.estado || "";
        var adminVal = btn.dataset.admin || "";
        var prioridadVal = btn.dataset.prioridad || "";
        var totalVal = btn.dataset.total || "";
        var metaChips = [];
        if (ticketNumStr && ticketNumStr !== "-") {
          metaChips.push(
            '<span class="scm-chip scm-chip-primary">' +
              (isPublicPqr ? "Solicitud #" : "Caso #") +
              escHtml(ticketNumStr) +
              "</span>",
          );
        }
        if (estadoVal && estadoVal !== "-") {
          metaChips.push(
            '<span class="scm-chip scm-chip-info"><span class="scm-chip-dot"></span>' +
              escHtml(estadoVal) +
              "</span>",
          );
        }
        if (prioridadVal && prioridadVal !== "-") {
          var cleanPrioridad = prioridadVal.replace(/^prioridad\s+/i, "");
          metaChips.push(
            '<span class="scm-chip scm-chip-warning"><span class="material-symbols-outlined text-[14px]">bolt</span> ' +
              (cleanPrioridad
                ? "Prioridad " + escHtml(cleanPrioridad)
                : escHtml(prioridadVal)) +
              "</span>",
          );
        }
        if (adminVal && adminVal !== "-") {
          metaChips.push(
            '<span class="scm-chip scm-chip-secondary">' +
              escHtml(adminVal) +
              "</span>",
          );
        }
        if (totalVal && totalVal !== "-") {
          metaChips.push(
            '<span class="scm-chip scm-chip-muted"><span class="material-symbols-outlined text-[14px]">schedule</span> SLA: ' +
              escHtml(totalVal) +
              "</span>",
          );
        }
        meta.innerHTML = metaChips.join("");

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
          collectSummary("Departamento", btn.dataset.departamento || "");
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

        // Move docs section to hidden sections so it doesn't take space in the main flow
        var docSection = srcWrap.querySelector(
          "#scm-sec-documentos, .scm-case-documents-section",
        );
        if (docSection) {
          docSection.style.display = "none";
          var hiddenWrap = srcWrap.querySelector(".scm-case-hidden-sections");
          if (!hiddenWrap) {
            hiddenWrap = document.createElement("div");
            hiddenWrap.className = "scm-case-hidden-sections";
            hiddenWrap.style.display = "none";
            srcWrap.appendChild(hiddenWrap);
          }
          if (!hiddenWrap.contains(docSection)) {
            hiddenWrap.appendChild(docSection);
          }
        }

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
        if (docSection) {
          var hasDocBtn = topActionButtons.some(function (b) {
            return (
              b.getAttribute("data-scm-open-section") === "scm-sec-documentos"
            );
          });
          if (!hasDocBtn) {
            var fakeDocBtn = document.createElement("button");
            fakeDocBtn.setAttribute(
              "data-scm-open-section",
              "scm-sec-documentos",
            );
            fakeDocBtn.textContent = "Adjuntos del caso";
            topActionButtons.unshift(fakeDocBtn);
          }
        }
        var timelineWrap = srcWrap.querySelector(".scm-modal-timeline-only");
        if (timelineWrap) {
          timelineWrap.remove();
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
        var cotizacionId = (btn.dataset.cotizacionId || "").trim();
        var statusBucket = (btn.dataset.statusBucket || "").trim();
        var calendarTicketPk = String(btn.dataset.ticketPk || "").trim();
        var noAccessCount = Math.max(
          0,
          parseInt(btn.dataset.preventivaNoAccessCount || "0", 10) || 0,
        );
        var nextNoAccessCount = noAccessCount + 1;
        if (seguimientoWrap) {
          seguimientoWrap.setAttribute("id", "scm-sec-seguimiento");
          seguimientoWrap.style.display = "none";
        }
        var isMaintenanceForActions = !isPublicPqr && isMaintenanceCase(btn);
        var hasCorrectiveReview =
          String(btn.dataset.idRevisionCorrectiva || "").trim() !== "";
        var mainActionButtons = [];
        var complementaryActionButtons = [];
        var quoteActionButtons = [];
        var actionPermissions = runtime.actionPermissions || {};
        var actionPermissionMap = actionPermissions.actions || null;
        function canUseDashboardAction(action) {
          if (!action) return true;
          if (!actionPermissionMap) return true;
          return actionPermissionMap[action] === true;
        }

        function renderActionGroup(label, buttons, extraClass) {
          if (!buttons.length) return "";
          return (
            '<div class="scm-case-work-group ' +
            escHtml(extraClass || "") +
            '"><h5>' +
            escHtml(label) +
            '</h5><div class="scm-case-work-action-list">' +
            buttons.join("") +
            "</div></div>"
          );
        }

        if (!isPublicPqr) {
          if (canUseDashboardAction("case_completion_act")) {
            complementaryActionButtons.push(
              '<button type="button" class="scm-case-work-btn" data-scm-open-ticket-acta>Acta de solución y firma</button>',
            );
          }
          if (statusBucket !== "cerrados" && isMaintenanceForActions) {
            if (canUseDashboardAction("corrective_review_manage")) {
              complementaryActionButtons.push(
                '<button type="button" class="scm-case-work-btn" data-scm-open-corrective-review><span class="material-symbols-outlined scm-btn-icon">fact_check</span><div class="scm-btn-text"><span class="scm-btn-label">' +
                  (hasCorrectiveReview
                    ? "Gestionar revisi&oacute;n correctiva"
                    : "Crear revisi&oacute;n correctiva") +
                  '</span><span class="scm-btn-sub">Diagnóstico detallado</span></div></button>',
              );
            }
          }
          if (isPreventivaCase(btn)) {
            var prevIdVal = (btn.dataset.idRevisionPreventiva || "").trim();
            if (prevIdVal) {
              complementaryActionButtons.push(
                '<a href="https://sucasainmobiliaria.com.co/revision-preventiva/?numero=' +
                  encodeURIComponent(prevIdVal) +
                  '" class="scm-case-work-btn" target="_blank" rel="noopener"><span class="material-symbols-outlined scm-btn-icon">verified_user</span><div class="scm-btn-text"><span class="scm-btn-label">Revisión preventiva</span><span class="scm-btn-sub">Acta #' +
                  escHtml(prevIdVal) +
                  "</span></div></a>",
              );
            }
          }
        }
        if (!isPublicPqr && (cotizacionUrl || cotizacionId)) {
          if (canUseDashboardAction("quote_manage")) {
            quoteActionButtons.push(
              '<button type="button" class="scm-case-work-btn" data-scm-view-case-cotizaciones data-ticket-pk="' +
                escHtml(calendarTicketPk || "") +
                '" data-ticket="' +
                escHtml(btn.dataset.ticket || "") +
                '" data-cotizacion-id="' +
                escHtml(cotizacionId) +
                '"><span class="material-symbols-outlined scm-btn-icon">receipt_long</span><div class="scm-btn-text"><span class="scm-btn-label">Gestionar cotizaciones</span><span class="scm-btn-sub">Costos y proveedores</span></div></button>',
            );
          }
          if (cotizacionId && canUseDashboardAction("quote_respond")) {
            quoteActionButtons.push(
              '<button type="button" class="scm-case-work-btn scm-primary-action" data-scm-open-cotizacion-response data-ticket-pk="' +
                escHtml(calendarTicketPk || "") +
                '" data-cotizacion-id="' +
                escHtml(cotizacionId) +
                '"><span class="material-symbols-outlined scm-btn-icon">rate_review</span><div class="scm-btn-text"><span class="scm-btn-label">Responder cotizaci&oacute;n</span><span class="scm-btn-sub">Aprobaci&oacute;n o rechazo</span></div></button>',
            );
          }
          var cotEstadoKey = String(btn.dataset.cotEstado || "");
          cotEstadoKey = cotEstadoKey.normalize
            ? cotEstadoKey
                .normalize("NFD")
                .replace(/[\u0300-\u036f]/g, "")
                .toLowerCase()
            : cotEstadoKey.toLowerCase();
          if (
            canUseDashboardAction("quote_acta_create") &&
            cotizacionId &&
            cotEstadoKey === "aprobada"
          ) {
            quoteActionButtons.push(
              '<button type="button" class="scm-case-work-btn scm-primary-action" data-scm-open-ticket-acta data-scm-cotizacion-acta-button>Crear acta de cotizaci&oacute;n</button>',
            );
          }
          if (
            canUseDashboardAction("quote_repair_followup") &&
            caseCanGenerateRepairFollowup(btn)
          ) {
            quoteActionButtons.push(
              '<button type="button" class="scm-case-work-btn scm-primary-action" data-scm-repair-followup-notice><span class="material-symbols-outlined scm-btn-icon">engineering</span><div class="scm-btn-text"><span class="scm-btn-label">Seguimiento reparaciones</span><span class="scm-btn-sub">Control de ejecución</span></div></button>',
            );
          }
        }
        if (
          !isPublicPqr &&
          canUseDashboardAction("quote_create") &&
          caseCanCreateMaintenanceQuote(btn)
        ) {
          quoteActionButtons.push(
            '<button type="button" class="scm-case-work-btn scm-primary-action" data-scm-create-cotizacion data-cotizacion-mode="create"' +
              (cotizacionUrl || cotizacionId
                ? ' data-scm-clear-cotizacion-create-draft="1"'
                : "") +
              ' data-ticket-pk="' +
              escHtml(calendarTicketPk || "") +
              '" data-ticket="' +
              escHtml(btn.dataset.ticket || "") +
              '">' +
              (cotizacionUrl || cotizacionId
                ? "A&ntilde;adir nueva cotizaci&oacute;n"
                : "A&ntilde;adir cotizaci&oacute;n") +
              "</button>",
          );
        }
        if (isPublicPqr && ticketUrl) {
          complementaryActionButtons.push(
            '<button type="button" class="scm-case-work-btn" data-scm-open-iframe' +
              (isPublicPqr ? " data-scm-compact-iframe" : "") +
              ' data-iframe-url="' +
              escHtml(ticketUrl) +
              '" data-iframe-title="Solicitud"><span class="material-symbols-outlined scm-btn-icon">open_in_new</span><div class="scm-btn-text"><span class="scm-btn-label">Solicitud original</span><span class="scm-btn-sub">Abrir expediente</span></div></button>',
          );
        }
        var estadoVal = btn.dataset.estado || "En gestión";
        var adminVal = btn.dataset.admin || "Normal";
        var totalVal = btn.dataset.total || "En curso";
        var etapaVal = btn.dataset.etapa || "Diagnóstico";
        var etapaTiempoVal = btn.dataset.etapaTiempo || "";
        var empleadoVal = btn.dataset.empleado || "Sin asignar";
        var empleadoIdVal = (btn.dataset.empleadoId || "").trim();
        var solicitanteVal = isPublicPqr
          ? btn.dataset.solicitante || ""
          : btn.dataset.arrendatario || "";
        var celularVal = (btn.dataset.celularSolicitante || "").trim();
        var correoVal = (btn.dataset.correoSolicitante || "").trim();
        var propietarioVal = (btn.dataset.propietario || "").trim();
        var inmuebleIdVal = (btn.dataset.inmueble || "-")
          .trim()
          .replace(/^#+/, "");
        var webIdVal = btn.dataset.idInmuebleWeb || "-";
        var barrioVal = btn.dataset.barrio || "-";
        var direccionVal = btn.dataset.direccion || "-";
        var contratoIdVal = (btn.dataset.contrato || "-")
          .trim()
          .replace(/^#+/, "");

        var caseActionsContent =
          renderActionGroup(
            isPublicPqr ? "Gestión de la solicitud" : "Gestión del caso",
            mainActionButtons,
            "is-main",
          ) +
          renderActionGroup(
            "Complementarias",
            complementaryActionButtons,
            "is-secondary",
          ) +
          renderActionGroup("Cotización", quoteActionButtons, "is-quote");

        var caseActionsHtml = caseActionsContent
          ? '<section class="scm-case-work-actions"><h4><span class="material-symbols-outlined text-[20px]">tune</span> ' +
            (isPublicPqr ? "Acciones de la solicitud" : "Acciones del caso") +
            "</h4>" +
            caseActionsContent +
            "</section>"
          : "";

        var recipientName =
          solicitanteVal ||
          (isPublicPqr ? "Solicitante" : "Arrendatario / Solicitante");
        var composerHtml =
          '<section class="scm-case-composer-card" data-scm-composer>' +
          '<div class="scm-case-composer-header">' +
          '<div class="scm-case-composer-tabs" role="tablist">' +
          '<button type="button" class="scm-composer-tab active" data-composer-tab="reply">' +
          '<span class="material-symbols-outlined text-[16px]">reply</span>' +
          "<span>" +
          (isPublicPqr ? "Responder solicitud" : "Responder caso") +
          "</span>" +
          "</button>" +
          '<button type="button" class="scm-composer-tab" data-composer-tab="followup">' +
          '<span class="material-symbols-outlined text-[16px]">add_comment</span>' +
          "<span>Seguimiento</span>" +
          "</button>" +
          '<button type="button" class="scm-composer-tab" data-composer-tab="note">' +
          '<span class="material-symbols-outlined text-[16px]">lock</span>' +
          "<span>Nota Interna (Privada)</span>" +
          "</button>" +
          "</div>" +
          "</div>" +
          '<div class="scm-case-composer-body">' +
          renderComposerResponseOptions(btn, isPublicPqr, statusBucket) +
          '<textarea class="scm-composer-textarea" rows="3" placeholder="Escriba una respuesta o actualización sobre el caso..." data-scm-composer-input></textarea>' +
          '<div class="scm-composer-file-preview" data-scm-composer-preview style="display:none;"></div>' +
          '<div class="scm-composer-docs-list" data-scm-composer-docs style="display:none;"></div>' +
          (isPreventivaCase(btn)
            ? '<div class="scm-composer-extra-box scm-composer-preventiva-box" data-scm-composer-preventiva-box style="padding:10px 14px;margin-top:8px;background:#fffbeb;border:1px solid #fef08a;border-radius:8px;font-size:13px;">' +
              '<label class="scm-composer-check" style="font-weight:600;display:flex;align-items:flex-start;gap:8px;cursor:pointer;">' +
              '<input type="checkbox" name="composer_generar_acta_no_acceso_preventiva" value="1" style="margin-top:2px;" data-scm-composer-no-access>' +
              "<div><span>Crear y enviar comunicación / acta preventiva por no autorización de acceso (Constancia #" +
              escHtml(String(nextNoAccessCount)) +
              ")</span>" +
              '<small style="display:block;color:#78350f;font-weight:normal;margin-top:2px;">Genera la constancia oficial en PDF #' +
              escHtml(String(nextNoAccessCount)) +
              ", la anexa a los documentos del caso y notifica al arrendatario.</small></div>" +
              "</label>" +
              "</div>"
            : "") +
          (!isPublicPqr && caseHasCotizacion(btn)
            ? '<div class="scm-composer-extra-box scm-composer-cotizacion-box" data-scm-composer-cotizacion-box style="padding:10px 14px;margin-top:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;">' +
              '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">' +
              '<strong style="display:flex;align-items:center;gap:6px;"><span class="material-symbols-outlined text-[16px] text-amber-600">receipt_long</span> Responder cotización #' +
              escHtml(cotizacionId || "-") +
              "</strong>" +
              '<select name="composer_estado_cotizacion" data-scm-composer-cot-estado class="scm-select scm-select-sm" style="font-size:12px;padding:3px 8px;border-radius:6px;border:1px solid #cbd5e1;">' +
              '<option value="__keep__">Sin cambio en cotización</option>' +
              '<option value="Aprobada">Aprobada</option>' +
              '<option value="Desaprobada">Desaprobada</option>' +
              "</select>" +
              "</div>" +
              '<div class="scm-composer-cot-details" data-scm-composer-cot-details style="display:none;margin-top:8px;flex-direction:column;gap:8px;">' +
              '<div class="scm-composer-cot-motivo" data-scm-composer-cot-motivo style="display:none;">' +
              '<label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;"><span>Motivo de desaprobación</span>' +
              '<select name="composer_motivo_cotizacion" data-scm-composer-cot-motivo-input class="scm-select scm-select-sm" style="font-size:12px;padding:3px 8px;border-radius:6px;border:1px solid #cbd5e1;">' +
              '<option value="">Elige un motivo</option>' +
              '<option value="Por costo">Por costo</option>' +
              '<option value="Ejecucción por cuenta propia">Ejecución por cuenta propia</option>' +
              "</select>" +
              "</label>" +
              "</div>" +
              '<div class="scm-composer-cot-financiacion" data-scm-composer-cot-financiacion style="display:none;">' +
              '<label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;"><span>Financiación</span>' +
              '<select name="composer_financiacion_cotizacion" data-scm-composer-cot-fin-input class="scm-select scm-select-sm" style="font-size:12px;padding:3px 8px;border-radius:6px;border:1px solid #cbd5e1;">' +
              '<option value="">No aplica / sin respuesta</option>' +
              '<option value="Si">Si</option>' +
              '<option value="No">No</option>' +
              "</select>" +
              "</label>" +
              "</div>" +
              '<label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;"><span>Observación cotización</span>' +
              '<textarea name="composer_observacion_cotizacion" data-scm-composer-cot-obs rows="2" class="scm-textarea" style="font-size:12px;padding:6px 8px;border-radius:6px;border:1px solid #cbd5e1;width:100%;" placeholder="Observación sobre la cotización..."></textarea>' +
              "</label>" +
              "</div>" +
              "</div>"
            : "") +
          "</div>" +
          '<div class="scm-composer-notify-row" data-scm-composer-notify-row>' +
          '<span class="scm-composer-notify-label"><span class="material-symbols-outlined text-[15px]">mail</span><span>Notificar:</span></span>' +
          '<div class="scm-composer-notify-options" data-scm-composer-notify-options>' +
          (isPublicPqr
            ? '<label class="scm-composer-check"><input type="checkbox" name="composer_notify[]" value="solicitante" checked> Solicitante</label>'
            : '<label class="scm-composer-check"><input type="checkbox" name="composer_notify[]" value="arrendatario" checked> Arrendatario</label>' +
              '<label class="scm-composer-check"><input type="checkbox" name="composer_notify[]" value="propietario"> Propietario</label>') +
          '<label class="scm-composer-check"><input type="checkbox" name="composer_notify[]" value="admin"> Administrativos</label>' +
          '<label class="scm-composer-check scm-composer-check-none"><input type="checkbox" name="composer_notify[]" value="none"> Ninguno</label>' +
          "</div>" +
          '<div class="scm-composer-private-notice" data-scm-composer-private-notice style="display:none;">' +
          '<span class="material-symbols-outlined text-[14px]">lock</span>' +
          "<span>Uso interno: Solo visible para funcionarios y administradores.</span>" +
          "</div>" +
          "</div>" +
          '<div class="scm-case-composer-footer">' +
          '<div class="scm-composer-tools">' +
          '<label class="scm-composer-tool-btn scm-composer-attach-btn" title="Adjuntar imagen">' +
          '<input type="file" multiple accept="image/*" class="scm-composer-file-input" style="display:none;" data-scm-composer-files>' +
          '<span class="material-symbols-outlined text-[17px]">add_photo_alternate</span>' +
          "<span>Adjuntar imagen</span>" +
          "</label>" +
          '<button type="button" class="scm-composer-tool-btn" data-scm-composer-paste title="Pegar imagen o captura del portapapeles (Ctrl+V)">' +
          '<span class="material-symbols-outlined text-[17px]">content_paste</span>' +
          "<span>Pegar (Ctrl+V)</span>" +
          "</button>" +
          '<button type="button" class="scm-composer-tool-btn scm-composer-attach-doc-btn" data-scm-composer-add-doc title="Adjuntar documento con título (PDF, Word, Excel...)">' +
          '<span class="material-symbols-outlined text-[17px]">attach_file</span>' +
          "<span>Adjuntar documento</span>" +
          "</button>" +
          '<button type="button" class="scm-composer-tool-btn" title="Plantillas y respuestas rápidas" data-scm-composer-canned>' +
          '<span class="material-symbols-outlined text-[18px]">chat</span>' +
          "</button>" +
          '<button type="button" class="scm-composer-tool-btn" title="Insertar checklist diagnóstico" data-scm-composer-template>' +
          '<span class="material-symbols-outlined text-[18px]">checklist</span>' +
          "</button>" +
          "</div>" +
          '<div class="scm-composer-actions">' +
          '<button type="button" class="scm-composer-btn-discard" data-scm-composer-discard>Descartar</button>' +
          '<button type="button" class="scm-composer-btn-submit" data-scm-composer-submit>' +
          '<span class="material-symbols-outlined text-[16px]">send</span>' +
          "<span data-scm-composer-submit-label>" +
          (isPublicPqr ? "Responder solicitud" : "Responder caso") +
          "</span>" +
          "</button>" +
          "</div>" +
          "</div>" +
          "</section>";

        var timelineHeaderHtml =
          '<div class="scm-case-timeline-head">' +
          '<div class="scm-case-timeline-title">' +
          '<span class="material-symbols-outlined text-[20px] text-blue-600">trending_up</span>' +
          "<h4>Historial de Actividad &amp; Seguimiento</h4>" +
          "</div>" +
          '<div class="scm-case-timeline-filter">' +
          '<label class="scm-timeline-filter-label">' +
          "<span>Filtrar por:</span>" +
          '<select class="scm-timeline-filter-select" data-scm-timeline-filter>' +
          '<option value="all">Todo el historial</option>' +
          '<option value="reply">Solo respuestas</option>' +
          '<option value="followup">Solo seguimientos</option>' +
          '<option value="note">Solo notas internas</option>' +
          "</select>" +
          "</label>" +
          "</div>" +
          "</div>";

        // Unify all history sections into one single timeline feed (exclude hidden modal sections like contract/inmueble)
        var historySections = Array.prototype.slice.call(
          srcWrap.querySelectorAll(
            ".scm-case-history:not(.scm-case-documents-section):not(.scm-case-hidden-sections .scm-case-history)",
          ),
        );
        var allHistoryArticles = [];

        historySections.forEach(function (sec) {
          var h4 = sec.querySelector("h4");
          var secTitle = (h4 ? h4.textContent : "").toLowerCase();
          var isNotesSection =
            secTitle.indexOf("nota") !== -1 || sec.id === "scm-sec-notas";
          var isSeguimientoSection =
            secTitle.indexOf("seguimiento") !== -1 ||
            sec.id === "scm-sec-seguimiento";

          var items = sec.querySelectorAll(".scm-case-history-item");
          items.forEach(function (item) {
            var rawType = item.getAttribute("data-history-type") || "";
            var itemText = (item.textContent || "").toLowerCase();
            var classifiedType = "reply";

            if (
              rawType === "note" ||
              isNotesSection ||
              itemText.indexOf("nota interna") !== -1 ||
              itemText.indexOf("nota privada") !== -1
            ) {
              classifiedType = "note";
            } else if (
              rawType === "followup" ||
              isSeguimientoSection ||
              itemText.indexOf("seguimiento") !== -1
            ) {
              classifiedType = "followup";
            } else {
              classifiedType = "reply";
            }

            item.setAttribute("data-history-type", classifiedType);
            item.removeAttribute("data-page");
            item.style.display = "";

            if (!item.querySelector(".scm-timeline-type-pill")) {
              var pillIcon = "reply";
              var pillLabel = "Respuesta";
              if (classifiedType === "followup") {
                pillIcon = "engineering";
                pillLabel = "Seguimiento";
              } else if (classifiedType === "note") {
                pillIcon = "lock";
                pillLabel = "Nota Interna";
              }
              var pillHtml =
                '<span class="scm-timeline-type-pill scm-type-' +
                classifiedType +
                '"><span class="material-symbols-outlined text-[13px]">' +
                pillIcon +
                "</span> " +
                pillLabel +
                "</span>";

              var metaEl = item.querySelector(".scm-case-history-meta");
              var recordHead = item.querySelector(".scm-case-record-head");
              if (metaEl) {
                metaEl.insertAdjacentHTML("afterbegin", pillHtml);
              } else if (recordHead) {
                recordHead.insertAdjacentHTML("beforeend", pillHtml);
              } else {
                item.insertAdjacentHTML(
                  "afterbegin",
                  '<div class="scm-case-history-meta">' + pillHtml + "</div>",
                );
              }
            }

            allHistoryArticles.push(item);
          });

          sec.remove();
        });

        allHistoryArticles.sort(function (a, b) {
          var tsA = parseInt(a.getAttribute("data-timestamp") || "0", 10) || 0;
          var tsB = parseInt(b.getAttribute("data-timestamp") || "0", 10) || 0;
          if (tsA && tsB && tsA !== tsB) {
            return tsB - tsA;
          }
          return 0;
        });

        var unifiedTimelineHtml =
          '<section class="scm-case-unified-section" data-scm-unified-section>' +
          timelineHeaderHtml +
          '<div class="scm-case-history-list scm-case-timeline-feed" data-scm-timeline-feed>';

        allHistoryArticles.forEach(function (art) {
          unifiedTimelineHtml += art.outerHTML;
        });

        unifiedTimelineHtml +=
          "</div>" +
          '<div class="scm-timeline-filtered-empty' +
          (allHistoryArticles.length === 0 ? " is-visible" : "") +
          '" data-scm-unified-empty style="' +
          (allHistoryArticles.length === 0
            ? "display:flex;"
            : "display:none;") +
          '">' +
          '<span class="material-symbols-outlined text-[32px] text-slate-400">' +
          (allHistoryArticles.length === 0
            ? "history_toggle_off"
            : "filter_list_off") +
          "</span>" +
          "<p>" +
          (allHistoryArticles.length === 0
            ? "No hay historial registrado en este caso."
            : "No se encontraron registros para el filtro seleccionado.") +
          "</p>" +
          "</div>" +
          "</section>";

        srcWrap.insertAdjacentHTML(
          "beforeend",
          (caseActionsHtml || "") + composerHtml + unifiedTimelineHtml,
        );
        sourceHtml = srcWrap.innerHTML;

        var sidebarHtml = '<aside class="scm-case-sidebar">';

        // Card 1: Estado & Cumplimiento
        sidebarHtml += '<div class="scm-sidebar-card">';
        sidebarHtml +=
          '<div class="scm-sidebar-card-head"><span class="scm-sidebar-card-title">Estado &amp; Cumplimiento</span><span class="material-symbols-outlined text-[20px]">donut_large</span></div>';
        sidebarHtml +=
          '<div class="scm-status-box"><div class="scm-status-info"><span class="scm-status-sub">Estado Operativo</span><strong class="scm-status-main">' +
          escHtml(estadoVal) +
          '</strong></div><span class="scm-chip scm-chip-primary">' +
          escHtml(adminVal) +
          "</span></div>";
        sidebarHtml +=
          '<div class="scm-sla-widget"><div class="scm-sla-info"><span>Tiempo de Gestión</span><strong>' +
          escHtml(totalVal) +
          "</strong></div>";
        sidebarHtml +=
          '<div class="scm-sla-bar"><div class="scm-sla-bar-fill" style="width: 65%;"></div></div>';
        if (etapaVal && etapaVal !== "-") {
          sidebarHtml +=
            '<div class="scm-sla-detail"><span>Etapa: <strong>' +
            escHtml(etapaVal) +
            "</strong>" +
            (etapaTiempoVal ? " (" + escHtml(etapaTiempoVal) + ")" : "") +
            "</span></div>";
        }
        sidebarHtml += "</div>";

        if (!isPublicPqr) {
          var sideMagnitude = normalizeMagnitudeKey(
            btn.dataset.magnitudCaso || "",
          );
          sidebarHtml +=
            '<div class="flex items-center justify-between pt-1 border-t border-slate-100"><span class="scm-spec-label text-xs">Magnitud:</span><span data-scm-case-magnitude-badge>' +
            renderMagnitudeBadge(sideMagnitude) +
            "</span></div>";
          if (isPreventivaCase(btn)) {
            var noAccessSideCount = Math.max(
              0,
              parseInt(btn.dataset.preventivaNoAccessCount || "0", 10) || 0,
            );
            sidebarHtml +=
              '<div class="scm-case-side-item scm-case-side-no-access"><span class="scm-case-side-label">Constancias preventivas</span><span class="scm-case-side-value">' +
              escHtml(String(noAccessSideCount)) +
              (noAccessSideCount === 1 ? " comunicación" : " comunicaciones") +
              "</span><small>Próxima #" +
              escHtml(String(noAccessSideCount + 1)) +
              "</small></div>";
          }
        }

        // Acciones de estado en el lateral con estilo de Acciones del caso
        var sidebarStateButtons = [];
        if (!isPublicPqr && canUseDashboardAction("case_edit_magnitude")) {
          sidebarStateButtons.push(
            '<button type="button" class="scm-case-work-btn w-full" data-scm-edit-case-magnitude data-ticket-pk="' +
              escHtml(btn.dataset.ticketPk || "") +
              '">' +
              '<span class="material-symbols-outlined scm-btn-icon">tune</span>' +
              '<div class="scm-btn-text"><span class="scm-btn-label">Editar magnitud</span><span class="scm-btn-sub">Modificar severidad</span></div>' +
              "</button>",
          );
        }
        var canPostpone = isPublicPqr
          ? statusBucket !== "cerrados" && statusBucket !== "postergados"
          : statusBucket !== "cerrados" &&
            statusBucket !== "postergados" &&
            canUseDashboardAction("case_postpone");
        if (canPostpone) {
          sidebarStateButtons.push(
            '<button type="button" class="scm-case-work-btn w-full" data-scm-open-postpone-ticket>' +
              '<span class="material-symbols-outlined scm-btn-icon">schedule_send</span>' +
              '<div class="scm-btn-text"><span class="scm-btn-label">' +
              (isPublicPqr ? "Postergar solicitud" : "Postergar caso") +
              '</span><span class="scm-btn-sub">En espera de repuesto</span></div>' +
              "</button>",
          );
        }
        var canActivate =
          (statusBucket === "postergados" || statusBucket === "cerrados") &&
          (isPublicPqr || canUseDashboardAction("case_activate"));
        if (canActivate) {
          sidebarStateButtons.push(
            '<button type="button" class="scm-case-work-btn w-full" data-scm-activate-ticket>' +
              '<span class="material-symbols-outlined scm-btn-icon">play_arrow</span>' +
              '<div class="scm-btn-text"><span class="scm-btn-label">' +
              (isPublicPqr ? "Activar solicitud" : "Activar caso") +
              '</span><span class="scm-btn-sub">Reanudar gestión activa</span></div>' +
              "</button>",
          );
        }
        if (sidebarStateButtons.length > 0) {
          sidebarHtml +=
            '<div class="scm-sidebar-state-actions">' +
            sidebarStateButtons.join("") +
            "</div>";
        }
        sidebarHtml += "</div>";

        // Card 2: Partes Interesadas
        sidebarHtml += '<div class="scm-sidebar-card">';
        sidebarHtml +=
          '<div class="scm-sidebar-card-head"><span class="scm-sidebar-card-title">Partes Interesadas</span><span class="material-symbols-outlined text-[20px]">group</span></div>';

        // Responsable
        sidebarHtml += '<div class="scm-stakeholder-item">';
        sidebarHtml +=
          '<div class="scm-stakeholder-head"><span class="scm-stakeholder-role">Responsable Asignado</span><span class="material-symbols-outlined text-[#0e996b] text-[16px]">verified_user</span></div>';
        sidebarHtml +=
          '<div class="scm-stakeholder-body"><div class="scm-avatar-circle"><span class="material-symbols-outlined text-[18px]">person</span></div><div class="scm-stakeholder-details"><strong class="scm-stakeholder-name">' +
          escHtml(empleadoVal) +
          '</strong><span class="scm-stakeholder-sub">' +
          escHtml(btn.dataset.departamento || "Funcionario Asignado") +
          "</span></div></div>";
        sidebarHtml +=
          '<div class="scm-stakeholder-actions scm-stakeholder-actions-assigned">';
        if (empleadoIdVal) {
          sidebarHtml +=
            '<button type="button" class="scm-stakeholder-btn" data-scm-calendar-view-employee><span class="material-symbols-outlined text-[14px]">calendar_month</span> Ver agenda</button>';
        }
        if (
          !isPublicPqr &&
          calendarTicketPk &&
          canUseDashboardAction("case_schedule")
        ) {
          sidebarHtml +=
            '<button type="button" class="scm-stakeholder-btn scm-stakeholder-btn-schedule" data-scm-calendar-create-case><span class="material-symbols-outlined text-[14px]">event_available</span> Agendar cita</button>';
        }
        if (isPublicPqr) {
          if (card && card.querySelector("[data-scm-open-pqr-transfer]")) {
            sidebarHtml +=
              '<button type="button" class="scm-stakeholder-btn scm-stakeholder-btn-transfer" data-scm-open-pqr-transfer-from-case data-ticket-pk="' +
              escHtml(btn.dataset.ticketPk || "") +
              '"><span class="material-symbols-outlined text-[14px]">swap_horiz</span> Trasladar caso</button>';
          }
        } else {
          if (canUseDashboardAction("case_transfer")) {
            sidebarHtml +=
              '<button type="button" class="scm-stakeholder-btn scm-stakeholder-btn-transfer" data-scm-open-trasladar><span class="material-symbols-outlined text-[14px]">swap_horiz</span> Trasladar caso</button>';
          }
        }
        sidebarHtml += "</div>";
        sidebarHtml += "</div>";

        // Arrendatario / Solicitante
        if (solicitanteVal) {
          sidebarHtml += '<div class="scm-stakeholder-item">';
          sidebarHtml +=
            '<div class="scm-stakeholder-head"><span class="scm-stakeholder-role">' +
            (isPublicPqr ? "Solicitante" : "Arrendatario") +
            '</span><span class="scm-chip scm-chip-secondary text-[10px]">Arrendatario</span></div>';
          sidebarHtml +=
            '<div class="scm-stakeholder-body"><div class="scm-stakeholder-details"><strong class="scm-stakeholder-name">' +
            escHtml(solicitanteVal) +
            "</strong>" +
            (celularVal
              ? '<span class="scm-stakeholder-sub">' +
                escHtml(celularVal) +
                "</span>"
              : "") +
            (correoVal
              ? '<span class="scm-stakeholder-sub">' +
                escHtml(correoVal) +
                "</span>"
              : "") +
            "</div></div>";
          sidebarHtml +=
            '<div class="scm-stakeholder-actions scm-stakeholder-actions-data">';
          sidebarHtml +=
            '<button type="button" class="scm-stakeholder-btn" data-scm-view-contacts data-scm-contact-role="tenant"><span class="material-symbols-outlined text-[14px]">visibility</span> Ver datos</button>';
          if (!isPublicPqr) {
            sidebarHtml +=
              '<button type="button" class="scm-stakeholder-btn scm-stakeholder-btn-edit" data-scm-open-contacts data-scm-contact-role="tenant"><span class="material-symbols-outlined text-[14px]">edit</span> Editar datos</button>';
          }
          if (celularVal) {
            var rawDigits = celularVal.replace(/\D/g, "");
            sidebarHtml +=
              '<a class="scm-stakeholder-btn" href="tel:' +
              escHtml(rawDigits) +
              '"><span class="material-symbols-outlined text-[14px]">call</span> Llamar</a><a class="scm-stakeholder-btn scm-stakeholder-btn-whatsapp" href="https://wa.me/57' +
              escHtml(rawDigits) +
              '" target="_blank" rel="noopener"><span class="material-symbols-outlined text-[14px]">chat</span> WhatsApp</a>';
          }
          sidebarHtml += "</div>";
          sidebarHtml += "</div>";
        }

        // Propietario
        if (propietarioVal) {
          sidebarHtml += '<div class="scm-stakeholder-item">';
          sidebarHtml +=
            '<div class="scm-stakeholder-head"><span class="scm-stakeholder-role">Propietario del Inmueble</span><span class="scm-chip scm-chip-warning text-[10px]">Propietario</span></div>';
          sidebarHtml +=
            '<div class="scm-stakeholder-body"><div class="scm-stakeholder-details"><strong class="scm-stakeholder-name">' +
            escHtml(propietarioVal) +
            "</strong></div></div>";
          sidebarHtml +=
            '<div class="scm-stakeholder-actions scm-stakeholder-actions-data"><button type="button" class="scm-stakeholder-btn" data-scm-view-contacts data-scm-contact-role="owner"><span class="material-symbols-outlined text-[14px]">visibility</span> Ver datos</button>' +
            (!isPublicPqr
              ? '<button type="button" class="scm-stakeholder-btn scm-stakeholder-btn-edit" data-scm-open-contacts data-scm-contact-role="owner"><span class="material-symbols-outlined text-[14px]">edit</span> Editar datos</button>'
              : "") +
            "</div>";
          sidebarHtml += "</div>";
        }
        sidebarHtml += "</div>";

        // Entrega specifics
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
            '<div class="scm-sidebar-card"><div class="scm-sidebar-card-head"><span class="scm-sidebar-card-title">Consultor/a de Entrega</span><span class="material-symbols-outlined text-[20px]">assignment_ind</span></div><div class="scm-property-specs">';
          if (consultorEntrega)
            sidebarHtml +=
              '<div class="scm-spec-row"><span class="scm-spec-label">Nombre:</span><strong class="scm-spec-value">' +
              escHtml(consultorEntrega) +
              "</strong></div>";
          if (consultorCelular)
            sidebarHtml +=
              '<div class="scm-spec-row"><span class="scm-spec-label">Celular:</span><strong class="scm-spec-value">' +
              escHtml(consultorCelular) +
              "</strong></div>";
          if (consultorCorreo)
            sidebarHtml +=
              '<div class="scm-spec-row"><span class="scm-spec-label">Correo:</span><strong class="scm-spec-value">' +
              escHtml(consultorCorreo) +
              "</strong></div>";
          sidebarHtml += "</div></div>";
        }

        var ubicLlaves = (btn.dataset.ubicacionLlaves || "").trim();
        var personaLlaves = (btn.dataset.personaLlaves || "").trim();
        var contactoLlaves = (btn.dataset.contactoLlaves || "").trim();
        if (
          tabKeySide === "entrega" &&
          (ubicLlaves || personaLlaves || contactoLlaves)
        ) {
          sidebarHtml +=
            '<div class="scm-sidebar-card"><div class="scm-sidebar-card-head"><span class="scm-sidebar-card-title">Llaves del Inmueble</span><span class="material-symbols-outlined text-[20px]">key</span></div>';
          sidebarHtml +=
            '<button type="button" class="btn btn-outline btn-sm w-full" data-scm-open-llaves>Ver llaves registradas</button></div>';
        }

        sidebarHtml += "</aside>";

        if (headActions) {
          headActions.innerHTML = "";
          if (hasPerturbacionValue((btn.dataset.perturbacion || "").trim())) {
            headActions.innerHTML +=
              '<button type="button" class="scm-case-side-link" data-scm-open-perturbacion><span class="material-symbols-outlined text-[16px]">warning</span> Ver perturbación</button>';
          }
          if ((btn.dataset.idRevisionCorrectiva || "").trim()) {
            headActions.innerHTML +=
              '<button type="button" class="scm-case-side-link" data-scm-open-damage="correctiva"><span class="material-symbols-outlined text-[16px]">home_repair_service</span> Magnitud correctiva</button>';
          }
          if ((btn.dataset.idRevisionPreventiva || "").trim()) {
            headActions.innerHTML +=
              '<a href="https://sucasainmobiliaria.com.co/revision-preventiva/?numero=' +
              encodeURIComponent(
                (btn.dataset.idRevisionPreventiva || "").trim(),
              ) +
              '" class="scm-case-side-link" target="_blank" rel="noopener"><span class="material-symbols-outlined text-[16px]">verified_user</span> Ver revisión preventiva</a>';
            headActions.innerHTML +=
              '<button type="button" class="scm-case-side-link" data-scm-open-damage="preventiva"><span class="material-symbols-outlined text-[16px]">shield</span> Magnitud preventiva</button>';
          }
          topActionButtons.forEach(function (rawBtn) {
            var sectionId = rawBtn.getAttribute("data-scm-open-section") || "";
            var label = (rawBtn.textContent || "").trim() || "Ver detalle";
            if (!sectionId) {
              return;
            }
            if (sectionId === "scm-sec-inmueble") {
              headActions.innerHTML +=
                '<button type="button" class="scm-case-side-link" data-scm-open-property-technical><span class="material-symbols-outlined text-[16px]">home_work</span> Ficha técnica del inmueble</button>';
              return;
            }
            var iconName = "description";
            if (sectionId === "scm-sec-hist-inmueble") iconName = "history";
            else if (sectionId === "scm-sec-contrato") iconName = "description";
            else if (sectionId === "scm-sec-documentos")
              iconName = "attach_file";
            headActions.innerHTML +=
              '<button type="button" class="scm-case-side-link" data-scm-open-section="' +
              escHtml(sectionId) +
              '"><span class="material-symbols-outlined text-[16px]">' +
              iconName +
              "</span> " +
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
              '" class="scm-case-side-link" target="_blank" rel="noopener"><span class="material-symbols-outlined text-[16px]">verified</span> Ver asegurable</a>';
          }
          if (tabKeyHead === "entrega" && anexosHead) {
            headActions.innerHTML +=
              '<a href="' +
              escHtml(anexosHead) +
              '" class="scm-case-side-link" target="_blank" rel="noopener"><span class="material-symbols-outlined text-[16px]">folder</span> Ver documentos</a>';
          }
          if (
            tabKeyHead === "entrega" &&
            (ubicLlavesHead || personaLlavesHead || contactoLlavesHead)
          ) {
            headActions.innerHTML +=
              '<button type="button" class="scm-case-side-link" data-scm-open-llaves><span class="material-symbols-outlined text-[16px]">key</span> Ver llaves</button>';
          }
        }

        body.innerHTML =
          '<div class="scm-case-layout">' +
          '<section class="scm-case-main">' +
          sourceHtml +
          "</section>" +
          sidebarHtml +
          "</div>";
        initCotizacionResponseFields(body);
        initCaseComposer(modal, btn);
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
        .querySelectorAll("[data-scm-open-property-technical]")
        .forEach(function (propertyBtn) {
          propertyBtn.addEventListener("click", function () {
            openPropertyTechnicalSubmodal(modal, btn, {
              inmuebleId: inmuebleIdVal,
              webId: webIdVal,
              barrio: barrioVal,
              direccion: direccionVal,
              contratoId: contratoIdVal,
            });
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
            openContactEditor(
              modal,
              btn,
              contactsBtn.dataset.scmContactRole || "all",
            );
          });
        });

      modal
        .querySelectorAll("[data-scm-view-contacts]")
        .forEach(function (contactsBtn) {
          contactsBtn.addEventListener("click", function () {
            openContactViewer(
              modal,
              btn,
              contactsBtn.dataset.scmContactRole || "all",
            );
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
        .querySelectorAll("[data-scm-calendar-view-employee]")
        .forEach(function (calendarBtn) {
          calendarBtn.addEventListener("click", function () {
            var employeeId = String(btn.dataset.empleadoId || "").trim();
            if (!employeeId) {
              scmNotify(
                "error",
                "Este caso no tiene funcionario asignado para ver calendario.",
              );
              return;
            }
            openCalendarCaseMonthPopup(
              findRootFromNode(btn),
              employeeId,
              btn.dataset.empleado || btn.dataset.asignado || "",
              "",
            );
          });
        });

      modal
        .querySelectorAll("[data-scm-open-ticket-acta]")
        .forEach(function (actaBtn) {
          actaBtn.addEventListener("click", function () {
            openTicketCompletionEditor(modal, btn);
          });
        });

      modal
        .querySelectorAll("[data-scm-open-corrective-review]")
        .forEach(function (reviewBtn) {
          reviewBtn.addEventListener("click", function () {
            openCorrectiveReviewEditor(modal, btn);
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
        .querySelectorAll("[data-scm-repair-followup-notice]")
        .forEach(function (noticeBtn) {
          noticeBtn.addEventListener("click", function (event) {
            event.preventDefault();
            event.stopPropagation();
            openRepairFollowupNoticeEditor(modal, btn);
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
        .querySelectorAll("[data-scm-change-admin-state]")
        .forEach(function (adminStateBtn) {
          adminStateBtn.addEventListener("click", function () {
            openChangeAdminStateEditor(modal, btn);
          });
        });

      modal
        .querySelectorAll("[data-scm-open-iframe]")
        .forEach(function (iframeBtn) {
          iframeBtn.addEventListener("click", function (event) {
            event.preventDefault();
            event.stopPropagation();
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

  function openActaFromDeepLink() {
    var params;
    try {
      params = new URL(window.location.href).searchParams;
    } catch (_error) {
      return;
    }
    var ticketPk = String(params.get("scm_acta_ticket_pk") || "").trim();
    if (!/^[1-9][0-9]*$/.test(ticketPk)) return;
    var attempts = 0;
    var timer = window.setInterval(function () {
      attempts++;
      var caseBtn = Array.from(
        document.querySelectorAll(".scm-btn-case[data-ticket-pk]"),
      ).find(function (button) {
        return String(button.dataset.ticketPk || "") === ticketPk;
      });
      if (!caseBtn && attempts < 40) return;
      window.clearInterval(timer);
      params.delete("scm_acta_ticket_pk");
      try {
        window.history.replaceState(
          {},
          "",
          window.location.pathname +
            (params.toString() ? "?" + params.toString() : "") +
            window.location.hash,
        );
      } catch (_error) {}
      if (!caseBtn) {
        if (typeof window.scmNotify === "function")
          window.scmNotify(
            "error",
            "No se encontró el ticket autorizado para abrir su acta.",
          );
        return;
      }
      window.scmOpenCase(caseBtn);
      window.setTimeout(function () {
        var button = document.querySelector(
          "#scm-app #scm-case-modal.open [data-scm-open-ticket-acta]",
        );
        if (button) button.click();
      }, 50);
    }, 250);
  }

  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", openActaFromDeepLink, {
      once: true,
    });
  else openActaFromDeepLink();

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
            new File(
              [file],
              "captura-pegada-" + Date.now() + "-" + i + "." + ext,
              {
                type: file.type || "image/png",
              },
            ),
          );
        }
      }
    }
    if (!files.length) {
      zone.classList.add("is-error");
      var noImageList = zone.querySelector("[data-scm-paste-list]");
      if (noImageList) {
        noImageList.innerHTML =
          "<li>No se encontro una imagen en el portapapeles.</li>";
      }
      return;
    }
    var form = zone.closest("form");
    var inputName = zone.getAttribute("data-file-input-name") || "evidencia[]";
    var input = form
      ? form.querySelector('input[type="file"][name="' + inputName + '"]')
      : null;
    if (!input || typeof DataTransfer === "undefined") {
      zone.classList.add("is-error");
      var unsupportedList = zone.querySelector("[data-scm-paste-list]");
      if (unsupportedList) {
        unsupportedList.innerHTML =
          "<li>Tu navegador no permitio adjuntar la captura pegada.</li>";
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

  document.addEventListener("submit", function (event) {
    var form =
      event.target && event.target.closest
        ? event.target.closest(".scm-repair-followup-form")
        : null;
    if (!form) {
      return;
    }
    event.preventDefault();
    var root = form.closest("#scm-app") || document.querySelector("#scm-app");
    var runtime = parseRuntime(root) || {};
    var action =
      (runtime.actions && runtime.actions.repair_followup_notice) ||
      "scm_seguimiento_reparaciones_cotizacion";
    var msg = form.querySelector(".scm-seg-msg");
    var submit = form.querySelector('button[type="submit"]');
    var fd = new FormData(form);
    fd.set("action", action);
    fd.set("nonce", runtime.nonce || "");
    if (submit) submit.disabled = true;
    if (msg) {
      msg.textContent = "Generando comunicacion y encolando notificaciones...";
      msg.classList.remove("error");
    }
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
        if (!json || !json.success) {
          throw new Error(
            (json && json.data && json.data.message) ||
              "No se pudo generar el seguimiento de reparaciones.",
          );
        }
        var data = json.data || {};
        var text = data.message || "Seguimiento de reparaciones generado.";
        var ticketPk = String(fd.get("ticket_pk") || "").trim();
        if (msg) msg.textContent = text;
        scmNotify("success", text, "Seguimiento de reparaciones");
        closeCaseSubmodal(form.closest(".scm-case-modal"));
        dispatchCaseActionSaved(root, ticketPk, form);
      })
      .catch(function (error) {
        var text =
          error.message || "No se pudo generar el seguimiento de reparaciones.";
        if (msg) {
          msg.textContent = text;
          msg.classList.add("error");
        }
        scmNotify("error", text, "Seguimiento de reparaciones");
      })
      .finally(function () {
        if (submit) submit.disabled = false;
      });
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
        return {
          queued: 0,
          skipped: appointments.length,
          error: error.message || "",
        };
      });
  }

  function showCalendarCitaNotificationResult(result, msg) {
    result = result || {};
    var queued = Number(result.queued || 0);
    var skipped = Number(result.skipped || 0);
    var error = String(result.error || "").trim();
    var errors = Array.isArray(result.errors)
      ? result.errors.filter(Boolean)
      : [];
    if (queued > 0) {
      var okText =
        queued +
        " notificación" +
        (queued === 1 ? "" : "es") +
        " WhatsApp encolada" +
        (queued === 1 ? "" : "s") +
        ".";
      if (msg) msg.textContent = "Evento creado. " + okText;
      scmNotify("success", okText, "WhatsApp");
      return;
    }
    if (error || errors.length) {
      var errorText =
        error ||
        errors[0] ||
        "No se pudieron encolar las notificaciones WhatsApp.";
      if (msg) {
        msg.textContent =
          "Evento creado, pero WhatsApp no se encoló: " + errorText;
        msg.classList.add("error");
      }
      scmNotify("error", errorText, "WhatsApp");
      return;
    }
    if (skipped > 0) {
      var skippedText =
        "Evento creado, pero no se encoló WhatsApp. Revisa celular del ticket o funcionario.";
      if (msg) msg.textContent = skippedText;
      scmNotify("warning", skippedText, "WhatsApp");
    }
  }

  bindGlobalImageLightbox();

  window.SCMAdminCore = {
    parseRuntime: parseRuntime,
    persistRuntime: persistRuntime,
    escHtml: escHtml,
    scmNotify: scmNotify,
    openImageLightbox: openImageLightbox,
    bindGlobalImageLightbox: bindGlobalImageLightbox,
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
    openStandaloneDetail: openStandaloneDetail,
  };
})();
