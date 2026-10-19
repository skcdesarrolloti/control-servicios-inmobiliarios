/* Historial del inmueble: filtros por fuente y búsqueda en la línea de tiempo.
   Mismo archivo en el panel, el bot de WhatsApp y el portal del propietario. */
(function () {
  "use strict";

  function normalize(value) {
    return String(value || "")
      .toLowerCase()
      .normalize("NFD")
      .replace(/[̀-ͯ]/g, "");
  }

  function bind(scope) {
    var reports = scope && scope.querySelectorAll ? scope.querySelectorAll("[data-phr]") : [];
    Array.prototype.forEach.call(reports, function (report) {
      if (report.getAttribute("data-phr-ready") === "1") return;
      report.setAttribute("data-phr-ready", "1");
      var search = report.querySelector("[data-phr-search]");
      var empty = report.querySelector("[data-phr-noresults]");
      var counter = report.querySelector("[data-phr-count]");
      var total = report.querySelectorAll("[data-phr-item]").length;
      var source = "";

      function apply() {
        var term = normalize(search ? search.value.trim() : "");
        var words = term ? term.split(/\s+/) : [];
        var visible = 0;
        var perSource = {};
        var textMatches = 0;
        report.querySelectorAll("[data-phr-item]").forEach(function (item) {
          var text = normalize(item.getAttribute("data-phr-text"));
          var key = item.getAttribute("data-phr-source") || "";
          var matchText = words.every(function (word) { return text.indexOf(word) !== -1; });
          if (matchText) {
            perSource[key] = (perSource[key] || 0) + 1;
            textMatches += 1;
          }
          item.hidden = !(matchText && (!source || key === source));
          if (!item.hidden) visible += 1;
        });
        report.querySelectorAll("[data-phr-month]").forEach(function (month) {
          month.hidden = !month.querySelector("[data-phr-item]:not([hidden])");
        });
        // Los contadores de las fuentes reflejan la búsqueda actual.
        report.querySelectorAll("[data-phr-filter]").forEach(function (button) {
          var key = button.getAttribute("data-phr-filter") || "";
          var count = key ? perSource[key] || 0 : textMatches;
          var badge = button.querySelector("[data-phr-filter-count]");
          if (badge) badge.textContent = String(count);
          button.classList.toggle("is-empty", key !== "" && count === 0);
        });
        if (counter) {
          counter.textContent = visible === total ? total + " movimiento(s)" : "Mostrando " + visible + " de " + total + " movimiento(s)";
        }
        if (empty) empty.hidden = visible > 0;
      }

      report.addEventListener("click", function (event) {
        var button = event.target && event.target.closest ? event.target.closest("[data-phr-filter]") : null;
        if (!button || !report.contains(button)) return;
        source = button.getAttribute("data-phr-filter") || "";
        report.querySelectorAll("[data-phr-filter]").forEach(function (item) {
          var active = item === button;
          item.classList.toggle("is-active", active);
          item.setAttribute("aria-pressed", active ? "true" : "false");
        });
        apply();
      });
      if (search) {
        search.addEventListener("input", apply);
      }
    });
  }

  window.scmPropertyHistoryBind = bind;
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () { bind(document); });
  } else {
    bind(document);
  }
})();
