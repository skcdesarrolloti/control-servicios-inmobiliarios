(function () {
  "use strict";

  var nativeFetch = window.fetch.bind(window);
  var draftsKey = "scm_session_drafts_v1";
  var returnUrlKey = "scm_session_return_url_v1";
  var navigationKey = "scm_session_navigation_v1";
  var autoRedirectSeconds = 12;
  var handlingExpiration = false;
  var redirectTimer = null;
  var countdownTimer = null;
  var lastUserActivityAt = Date.now();
  var lastHeartbeatAt = Date.now();
  var broadcast = null;

  try {
    if (typeof window.BroadcastChannel === "function") {
      broadcast = new BroadcastChannel("scm-session-state");
    }
  } catch (_error) {}

  function readRuntime() {
    var root = document.getElementById("scm-app");
    if (!root) return {};
    try {
      return JSON.parse(root.getAttribute("data-scm-runtime") || "{}");
    } catch (_error) {
      return {};
    }
  }

  function sessionConfig() {
    var runtime = readRuntime();
    return {
      ajaxUrl: String(runtime.ajaxUrl || "api.php"),
      nonce: String(runtime.nonce || ""),
      heartbeatAction: String(
        (runtime.actions && runtime.actions.session_heartbeat) ||
          "scm_session_heartbeat",
      ),
      loginUrl: String(
        (runtime.session && runtime.session.loginUrl) || "login.php",
      ),
      heartbeatIntervalMs: Math.max(
        60000,
        Number(
          (runtime.session && runtime.session.heartbeatIntervalMs) || 240000,
        ),
      ),
    };
  }

  function isScmApiRequest(input) {
    var rawUrl = "";
    if (typeof input === "string") {
      rawUrl = input;
    } else if (input && typeof input.url === "string") {
      rawUrl = input.url;
    }
    if (!rawUrl) return false;
    try {
      var url = new URL(rawUrl, window.location.href);
      return (
        url.origin === window.location.origin &&
        /\/api\.php$/i.test(url.pathname)
      );
    } catch (_error) {
      return false;
    }
  }

  function formIdentity(form, index) {
    if (form.id) return "id:" + form.id;
    var name = form.getAttribute("name");
    if (name) return "name:" + name;
    return "index:" + index;
  }

  function serializableFields(form) {
    var fields = [];
    var nameOccurrences = {};
    form.querySelectorAll("input[name], textarea[name], select[name]").forEach(
      function (field) {
        var type = String(field.type || "").toLowerCase();
        var name = String(field.name || "");
        if (
          !name ||
          [
            "password",
            "file",
            "hidden",
            "submit",
            "button",
            "reset",
          ].indexOf(type) !== -1 ||
          ["nonce", "action", "_csrf_token", "_csrf_action"].indexOf(name) !== -1
        ) {
          return;
        }
        var occurrence = nameOccurrences[name] || 0;
        nameOccurrences[name] = occurrence + 1;
        var value;
        if (field.tagName === "SELECT" && field.multiple) {
          value = Array.prototype.map.call(field.selectedOptions, function (option) {
            return option.value;
          });
        } else {
          value = String(field.value || "").slice(0, 10000);
        }
        fields.push({
          name: name,
          occurrence: occurrence,
          type: type,
          value: value,
          checked: Boolean(field.checked),
        });
      },
    );
    return fields;
  }

  function preserveDrafts() {
    var root = document.getElementById("scm-app");
    if (!root) return 0;
    var forms = [];
    var allForms = root.querySelectorAll("form");
    allForms.forEach(function (form, index) {
      if (form.getAttribute("data-scm-draft-dirty") !== "1") return;
      var fields = serializableFields(form);
      if (!fields.length) return;
      forms.push({
        identity: formIdentity(form, index),
        fields: fields,
      });
    });
    if (!forms.length) return 0;
    try {
      var serialized = JSON.stringify({
        savedAt: Date.now(),
        forms: forms,
      });
      if (serialized.length <= 200000) {
        window.sessionStorage.setItem(draftsKey, serialized);
        return forms.length;
      }
    } catch (_error) {}
    return 0;
  }

  function findSavedForm(identity) {
    var root = document.getElementById("scm-app");
    if (!root) return null;
    if (identity.indexOf("id:") === 0) {
      return document.getElementById(identity.slice(3));
    }
    if (identity.indexOf("name:") === 0) {
      return root.querySelector(
        'form[name="' + CSS.escape(identity.slice(5)) + '"]',
      );
    }
    var index = Number(identity.slice(6));
    return root.querySelectorAll("form")[index] || null;
  }

  function applySavedFields(form, savedFields) {
    var byName = {};
    form.querySelectorAll("input[name], textarea[name], select[name]").forEach(
      function (field) {
        var name = String(field.name || "");
        if (!byName[name]) byName[name] = [];
        byName[name].push(field);
      },
    );
    savedFields.forEach(function (saved) {
      var field = byName[saved.name] && byName[saved.name][saved.occurrence];
      if (!field || field.disabled) return;
      var type = String(field.type || "").toLowerCase();
      if (type === "checkbox" || type === "radio") {
        field.checked = Boolean(saved.checked);
      } else if (field.tagName === "SELECT" && field.multiple) {
        var selected = Array.isArray(saved.value) ? saved.value : [];
        Array.prototype.forEach.call(field.options, function (option) {
          option.selected = selected.indexOf(option.value) !== -1;
        });
      } else {
        field.value = String(saved.value || "");
      }
      field.setAttribute("data-scm-draft-restored", "1");
    });
    form.setAttribute("data-scm-draft-dirty", "1");
  }

  function showDraftRestoredNotice(count) {
    if (!count || document.getElementById("scm-draft-restored-notice")) return;
    var notice = document.createElement("div");
    notice.id = "scm-draft-restored-notice";
    notice.className = "scm-draft-restored-notice";
    notice.setAttribute("role", "status");
    notice.setAttribute("aria-live", "polite");
    notice.innerHTML =
      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>' +
      "Recuperamos " +
      (count === 1 ? "el formulario que estabas editando." : "tus formularios pendientes.");
    document.body.appendChild(notice);
    window.setTimeout(function () {
      notice.classList.add("is-visible");
    }, 30);
    window.setTimeout(function () {
      notice.classList.remove("is-visible");
      window.setTimeout(function () { notice.remove(); }, 250);
    }, 6500);
  }

  function restoreNavigation() {
    var raw = "";
    try {
      raw = window.sessionStorage.getItem(navigationKey) || "";
      window.sessionStorage.removeItem(navigationKey);
    } catch (_error) {}
    if (!raw) return;
    try {
      var saved = JSON.parse(raw);
      var target = String(saved.activeTab || "");
      if (target) {
        var tab = Array.prototype.find.call(
          document.querySelectorAll(".scm-main-tabs [data-tab]"),
          function (item) {
            return item.getAttribute("data-tab") === target;
          },
        );
        if (tab) tab.click();
      }
      window.setTimeout(function () {
        window.scrollTo(0, Math.max(0, Number(saved.scrollY || 0)));
      }, 250);
    } catch (_error) {}
  }

  function restoreDrafts() {
    var raw = "";
    try {
      raw = window.sessionStorage.getItem(draftsKey) || "";
    } catch (_error) {}
    if (!raw) return;

    var payload;
    try {
      payload = JSON.parse(raw);
    } catch (_error) {
      window.sessionStorage.removeItem(draftsKey);
      return;
    }
    if (
      !payload ||
      !Array.isArray(payload.forms) ||
      Date.now() - Number(payload.savedAt || 0) > 2 * 60 * 60 * 1000
    ) {
      window.sessionStorage.removeItem(draftsKey);
      return;
    }

    var pending = payload.forms.slice();
    var restored = 0;
    var startedAt = Date.now();
    function attemptRestore() {
      pending = pending.filter(function (savedForm) {
        var form = findSavedForm(String(savedForm.identity || ""));
        if (!form) return true;
        applySavedFields(form, savedForm.fields || []);
        restored += 1;
        return false;
      });
      if (!pending.length || Date.now() - startedAt > 30000) {
        if (observer) observer.disconnect();
        window.sessionStorage.removeItem(draftsKey);
        showDraftRestoredNotice(restored);
      }
    }
    var observer = new MutationObserver(attemptRestore);
    observer.observe(document.body, { childList: true, subtree: true });
    attemptRestore();
    window.setTimeout(attemptRestore, 1000);
  }

  function markDraftDirty(event) {
    lastUserActivityAt = Date.now();
    var field = event.target;
    if (!field || !field.closest) return;
    var form = field.closest("#scm-app form");
    if (form) form.setAttribute("data-scm-draft-dirty", "1");
  }

  function rememberNavigation() {
    var activeTab = document.querySelector(
      ".scm-main-tabs .scm-tab.active[data-tab]",
    );
    try {
      window.sessionStorage.setItem(
        navigationKey,
        JSON.stringify({
          activeTab: activeTab ? activeTab.getAttribute("data-tab") : "",
          scrollY: window.scrollY || 0,
        }),
      );
    } catch (_error) {}
  }

  function loginTargetUrl() {
    var raw = "";
    try {
      raw = sessionConfig().loginUrl || "login.php";
    } catch (_error) {
      raw = "login.php";
    }
    try {
      return new URL(raw, window.location.href).href;
    } catch (_error) {
      return "login.php";
    }
  }

  function redirectToLogin(event) {
    if (event && typeof event.preventDefault === "function") event.preventDefault();
    if (event && typeof event.stopPropagation === "function") event.stopPropagation();
    try { if (redirectTimer) window.clearTimeout(redirectTimer); } catch (_error) {}
    try { if (countdownTimer) window.clearInterval(countdownTimer); } catch (_error) {}
    try { preserveDrafts(); } catch (_error) {}
    try { rememberNavigation(); } catch (_error) {}
    try {
      window.sessionStorage.setItem(returnUrlKey, window.location.href);
    } catch (_error) {}
    var target = loginTargetUrl();
    try {
      window.location.assign(target);
    } catch (_error) {
      try {
        window.location.href = target;
      } catch (_fallbackError) {}
    }
    window.setTimeout(function () {
      if (window.location.href !== target) {
        try {
          window.location.replace(target);
        } catch (_error) {
          window.location.href = target;
        }
      }
    }, 250);
  }

  function createExpirationModal(reason) {
    var existing = document.getElementById("scm-session-expired");
    if (existing) return existing;
    var savedDraftCount = preserveDrafts();
    rememberNavigation();

    var overlay = document.createElement("div");
    overlay.id = "scm-session-expired";
    overlay.className = "scm-session-expired";
    overlay.innerHTML =
      '<div class="scm-session-expired-dialog" role="alertdialog" aria-modal="true" aria-labelledby="scm-session-expired-title" aria-describedby="scm-session-expired-description">' +
      '<span class="scm-session-expired-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 8v5m0 3h.01M10.3 3.7 2.6 17a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/></svg></span>' +
      '<span class="scm-session-expired-kicker">Seguridad de la cuenta</span>' +
      '<h2 id="scm-session-expired-title">Tu sesión terminó</h2>' +
      '<p id="scm-session-expired-description">' +
      (reason === "csrf"
        ? "La verificación de seguridad venció."
        : "La sesión venció por inactividad o por una renovación del servidor.") +
      " Inicia sesión nuevamente para continuar.</p>" +
      (savedDraftCount > 0
        ? '<p class="scm-session-expired-draft"><strong>Tu trabajo no se perdió.</strong> Recuperaremos ' +
          (savedDraftCount === 1 ? "el formulario abierto" : "los formularios abiertos") +
          " después de ingresar.</p>"
        : "") +
      '<button type="button" class="scm-session-expired-action" data-scm-session-login>Iniciar sesión nuevamente</button>' +
      '<p class="scm-session-expired-countdown" role="status" aria-live="polite">Redirigiendo en <strong data-scm-session-countdown>' +
      autoRedirectSeconds +
      "</strong> segundos.</p>" +
      "</div>";
    document.body.appendChild(overlay);
    document.body.classList.add("scm-session-locked");
    var button = overlay.querySelector("[data-scm-session-login]");
    if (button) {
      button.addEventListener("click", redirectToLogin);
      window.setTimeout(function () { button.focus(); }, 30);
    }
    overlay.addEventListener("click", function (event) {
      var target = event.target && event.target.closest
        ? event.target.closest("[data-scm-session-login]")
        : null;
      if (target) {
        redirectToLogin(event);
      }
    });
    Array.prototype.forEach.call(document.body.children, function (child) {
      if (child !== overlay && child instanceof HTMLElement) {
        child.inert = true;
      }
    });
    overlay.addEventListener("keydown", function (event) {
      if (event.key === "Tab") {
        event.preventDefault();
        if (button) button.focus();
      } else if (event.key === "Escape") {
        event.preventDefault();
      }
    });
    return overlay;
  }

  function silenceLegacyAlerts() {
    if (!window.Swal) return;
    if (typeof window.Swal.close === "function") {
      window.Swal.close();
    }
    if (typeof window.Swal.fire === "function") {
      window.Swal.fire = function () {
        return Promise.resolve({
          isConfirmed: false,
          isDenied: false,
          isDismissed: true,
          dismiss: "session-expired",
        });
      };
    }
  }

  function handleExpiration(reason, notifyTabs) {
    if (handlingExpiration) return;
    handlingExpiration = true;
    silenceLegacyAlerts();
    var overlay = createExpirationModal(reason || "expired");
    if (notifyTabs !== false && broadcast) {
      try { broadcast.postMessage({ type: "expired", reason: reason || "expired" }); } catch (_error) {}
    }
    var remaining = autoRedirectSeconds;
    countdownTimer = window.setInterval(function () {
      remaining -= 1;
      var node = overlay.querySelector("[data-scm-session-countdown]");
      if (node) node.textContent = String(Math.max(0, remaining));
    }, 1000);
    redirectTimer = window.setTimeout(redirectToLogin, autoRedirectSeconds * 1000);
    document.dispatchEvent(
      new CustomEvent("scm:session-expired", { detail: { reason: reason || "expired" } }),
    );
  }

  if (broadcast) {
    broadcast.addEventListener("message", function (event) {
      if (event.data && event.data.type === "expired") {
        handleExpiration(String(event.data.reason || "expired"), false);
      }
    });
  }

  window.fetch = function (input, init) {
    return nativeFetch(input, init).then(function (response) {
      if (isScmApiRequest(input)) {
        var authState = String(response.headers.get("X-SCM-Auth") || "");
        if (response.status === 401 || authState === "required") {
          handleExpiration("expired", true);
        } else if (authState === "csrf-expired") {
          handleExpiration("csrf", true);
        }
      }
      return response;
    });
  };

  function heartbeat(force) {
    if (handlingExpiration || document.visibilityState !== "visible") return;
    var config = sessionConfig();
    var now = Date.now();
    if (now - lastUserActivityAt > 10 * 60 * 1000) return;
    if (force && now - lastHeartbeatAt < 60000) return;
    if (!force && now - lastHeartbeatAt < config.heartbeatIntervalMs) return;
    lastHeartbeatAt = now;
    var formData = new FormData();
    formData.append("action", config.heartbeatAction);
    formData.append("nonce", config.nonce);
    window.fetch(config.ajaxUrl, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    }).catch(function () {
      // Un error de red no equivale a una sesión vencida. La siguiente acción
      // o heartbeat volverá a comprobar el estado.
    });
  }

  function noteActivity() {
    lastUserActivityAt = Date.now();
  }

  ["pointerdown", "keydown", "touchstart", "scroll"].forEach(function (name) {
    document.addEventListener(name, noteActivity, { passive: true, capture: true });
  });
  document.addEventListener("input", markDraftDirty, true);
  document.addEventListener("change", markDraftDirty, true);
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") {
      noteActivity();
      heartbeat(true);
    }
  });
  window.addEventListener("focus", function () {
    noteActivity();
    heartbeat(true);
  });

  var heartbeatInterval = window.setInterval(function () {
    heartbeat(false);
  }, 60000);

  function restoreAfterLogin() {
    var returnUrl = "";
    try {
      returnUrl = window.sessionStorage.getItem(returnUrlKey) || "";
    } catch (_error) {}
    if (returnUrl) {
      try {
        var target = new URL(returnUrl, window.location.href);
        if (target.origin === window.location.origin && target.href !== window.location.href) {
          window.sessionStorage.removeItem(returnUrlKey);
          window.location.replace(target.href);
          return;
        }
      } catch (_error) {}
      try { window.sessionStorage.removeItem(returnUrlKey); } catch (_error) {}
    }
    restoreNavigation();
    restoreDrafts();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", restoreAfterLogin, { once: true });
  } else {
    restoreAfterLogin();
  }

  window.addEventListener("pagehide", function () {
    window.clearInterval(heartbeatInterval);
    if (broadcast) broadcast.close();
  });

  window.SCMSessionGuard = {
    expire: function (reason) { handleExpiration(reason || "expired", true); },
    heartbeat: function () { heartbeat(true); },
    preserveDrafts: preserveDrafts,
  };
})();
