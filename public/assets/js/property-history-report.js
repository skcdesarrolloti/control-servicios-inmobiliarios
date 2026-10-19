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
      var source = "";

      function apply() {
        var term = normalize(search ? search.value.trim() : "");
        var visible = 0;
        report.querySelectorAll("[data-phr-item]").forEach(function (item) {
          var matchSource = !source || item.getAttribute("data-phr-source") === source;
          var matchText = !term || normalize(item.getAttribute("data-phr-text")).indexOf(term) !== -1;
          item.hidden = !(matchSource && matchText);
          if (!item.hidden) visible += 1;
        });
        report.querySelectorAll("[data-phr-month]").forEach(function (month) {
          month.hidden = !month.querySelector("[data-phr-item]:not([hidden])");
        });
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
