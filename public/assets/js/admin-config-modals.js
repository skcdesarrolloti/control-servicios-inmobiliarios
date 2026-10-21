(function () {
  "use strict";

  // Mejora de interfaz para Permisos por cargo y Temas de Casos. El guardado sigue en
  // admin-dashboard-runtime.js: aquí solo se navega, filtra y se mantienen sincronizados
  // los mismos inputs (checkboxes y textareas) que ese archivo envía.

  function fold(value) {
    return String(value == null ? "" : value).toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").trim();
  }
  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }
  function each(scope, selector, fn) {
    Array.prototype.forEach.call(scope.querySelectorAll(selector), fn);
  }

  function initPermissions(form) {
    if (!form || form.dataset.scmPermEnhanced === "1") return;
    form.dataset.scmPermEnhanced = "1";
    var picker = form.querySelector("#scm-permissions-cargo");
    var filterInput = form.querySelector("[data-scm-perm-filter]");
    var pane = "tabs";

    function cargo() {
      return picker ? picker.value : "";
    }
    function checksOf(card) {
      return Array.prototype.slice.call(card.querySelectorAll('.scm-permissions-check input[type="checkbox"]'));
    }
    function countLabel(checks) {
      var on = checks.filter(function (input) { return input.checked; }).length;
      return on + "/" + checks.length;
    }

    function refreshCounts() {
      var id = cargo();
      each(form, "[data-scm-perm-cargo-summary]", function (badge) {
        var cardId = badge.getAttribute("data-scm-perm-cargo-summary");
        var card = form.querySelector('.scm-permission-card[data-scm-perm-kind="tabs"][data-permission-cargo="' + cardId + '"]');
        badge.textContent = card ? countLabel(checksOf(card)) + " pestañas" : "";
      });
      ["tabs", "actions"].forEach(function (kind) {
        var card = form.querySelector('.scm-permission-card[data-scm-perm-kind="' + kind + '"][data-permission-cargo="' + id + '"]');
        var target = form.querySelector('[data-scm-perm-pane-count="' + kind + '"]');
        if (target) target.textContent = card ? countLabel(checksOf(card)) : "";
      });
      each(form, ".scm-permission-card", function (card) {
        var checks = checksOf(card);
        var on = checks.filter(function (input) { return input.checked; }).length;
        var label = card.querySelector("[data-scm-perm-card-count]");
        if (label) label.textContent = on + " de " + checks.length + " activas.";
        var all = card.querySelector("[data-scm-perm-all]");
        if (all) all.textContent = checks.length && on === checks.length ? "Quitar todo" : "Marcar todo";
      });
      each(form, "[data-scm-perm-group]", function (group) {
        var checks = checksOf(group);
        var toggle = group.querySelector("[data-scm-perm-group-all]");
        if (toggle) toggle.textContent = checks.every(function (input) { return input.checked; }) ? "Quitar grupo" : "Marcar grupo";
      });
      each(form, ".scm-permissions-check", function (label) {
        var input = label.querySelector('input[type="checkbox"]');
        label.classList.toggle("is-checked", !!(input && input.checked));
      });
    }

    function applyFilter() {
      var query = fold(filterInput ? filterInput.value : "");
      var id = cargo();
      var visible = 0;
      each(form, '.scm-permission-card[data-permission-cargo="' + id + '"][data-scm-perm-kind="' + pane + '"] [data-scm-perm-group]', function (group) {
        var shown = 0;
        each(group, ".scm-permissions-check", function (label) {
          var match = !query || fold(label.textContent).indexOf(query) !== -1;
          label.hidden = !match;
          if (match) shown += 1;
        });
        group.hidden = shown === 0;
        visible += shown;
      });
      var empty = form.querySelector("[data-scm-perm-filter-empty]");
      if (empty) empty.hidden = visible > 0;
    }

    function showPane(next) {
      pane = next;
      each(form, "[data-scm-perm-pane]", function (button) {
        var active = button.getAttribute("data-scm-perm-pane") === pane;
        button.classList.toggle("is-active", active);
        button.setAttribute("aria-selected", active ? "true" : "false");
      });
      each(form, "[data-scm-perm-pane-body]", function (body) {
        body.hidden = body.getAttribute("data-scm-perm-pane-body") !== pane;
      });
      applyFilter();
    }

    function selectCargo(id) {
      if (!picker || !id) return;
      if (picker.value !== id) {
        picker.value = id;
        // El runtime oculta/muestra las tarjetas del cargo al escuchar este cambio.
        picker.dispatchEvent(new Event("change", { bubbles: true }));
      }
      syncCargo();
    }

    function syncCargo() {
      var id = cargo();
      each(form, "[data-scm-perm-cargo-pick]", function (button) {
        var active = button.getAttribute("data-scm-perm-cargo-pick") === id;
        button.classList.toggle("is-active", active);
        button.setAttribute("aria-current", active ? "true" : "false");
      });
      each(form, "[data-permission-cargo]", function (item) {
        item.hidden = item.getAttribute("data-permission-cargo") !== id;
      });
      refreshCounts();
      applyFilter();
    }

    form.addEventListener("click", function (event) {
      var pick = event.target.closest("[data-scm-perm-cargo-pick]");
      if (pick) {
        selectCargo(pick.getAttribute("data-scm-perm-cargo-pick"));
        return;
      }
      var paneButton = event.target.closest("[data-scm-perm-pane]");
      if (paneButton) {
        showPane(paneButton.getAttribute("data-scm-perm-pane"));
        return;
      }
      var groupToggle = event.target.closest("[data-scm-perm-group-all]");
      if (groupToggle) {
        var checks = checksOf(groupToggle.closest("[data-scm-perm-group]")).filter(function (input) {
          return !input.closest(".scm-permissions-check").hidden;
        });
        var shouldCheck = checks.some(function (input) { return !input.checked; });
        checks.forEach(function (input) { input.checked = shouldCheck; });
        refreshCounts();
        return;
      }
      // "Marcar todo" lo resuelve el runtime; se recalculan los contadores después.
      if (event.target.closest("[data-scm-perm-all]")) window.setTimeout(refreshCounts, 0);
    });
    form.addEventListener("change", function (event) {
      if (event.target === picker) {
        syncCargo();
        return;
      }
      refreshCounts();
    });
    if (filterInput) filterInput.addEventListener("input", applyFilter);

    var cargoSearch = form.querySelector("[data-scm-perm-cargo-search]");
    if (cargoSearch) {
      cargoSearch.addEventListener("input", function () {
        var query = fold(cargoSearch.value);
        var shown = 0;
        each(form, "[data-scm-perm-cargo-pick]", function (button) {
          var match = !query || fold(button.getAttribute("data-scm-perm-cargo-name") + " " + button.getAttribute("data-scm-perm-cargo-pick")).indexOf(query) !== -1;
          button.hidden = !match;
          if (match) shown += 1;
        });
        var empty = form.querySelector("[data-scm-perm-cargo-empty]");
        if (empty) empty.hidden = shown > 0;
      });
    }

    syncCargo();
  }

  function initTopics(form) {
    if (!form || form.dataset.scmTopicsEnhanced === "1") return;
    form.dataset.scmTopicsEnhanced = "1";
    var modal = form.closest(".scm-ticket-topic-settings-modal") || form;
    var search = modal.querySelector("[data-scm-topic-search]");
    var summary = modal.querySelector("[data-scm-topic-summary]");

    function topicsOf(card) {
      var source = card.querySelector("[data-scm-topic-source]");
      var seen = {};
      return String(source ? source.value : "").split(/\r?\n/).map(function (line) {
        return line.trim();
      }).filter(function (line) {
        var key = fold(line);
        if (!key || seen[key]) return false;
        seen[key] = true;
        return true;
      });
    }
    function writeTopics(card, topics) {
      var source = card.querySelector("[data-scm-topic-source]");
      if (source) source.value = topics.join("\n");
    }

    // Dónde aparece cada tema, para marcar los que alimentan más de una bandeja.
    function usage() {
      var map = {};
      each(form, "[data-scm-topic-card]", function (card) {
        var label = card.getAttribute("data-scm-topic-label");
        topicsOf(card).forEach(function (topic) {
          var key = fold(topic);
          (map[key] = map[key] || []).push(label);
        });
      });
      return map;
    }

    function render() {
      var where = usage();
      var query = fold(search ? search.value : "");
      var total = 0;
      var repeated = Object.keys(where).filter(function (key) { return where[key].length > 1; }).length;
      var visibleCards = 0;
      each(form, "[data-scm-topic-card]", function (card) {
        var label = card.getAttribute("data-scm-topic-label");
        var topics = topicsOf(card);
        total += topics.length;
        var matches = 0;
        card.querySelector("[data-scm-topic-chips]").innerHTML = topics.length
          ? topics.map(function (topic, index) {
            var others = (where[fold(topic)] || []).filter(function (name) { return name !== label; });
            var hit = !!query && fold(topic).indexOf(query) !== -1;
            if (hit) matches += 1;
            return '<span class="scm-topic-chip' + (others.length ? " is-repeated" : "") + (hit ? " is-match" : "") + '"' +
              (others.length ? ' title="También en: ' + esc(others.join(", ")) + '"' : "") + ">" + esc(topic) +
              '<button type="button" data-scm-topic-remove="' + index + '" aria-label="Quitar ' + esc(topic) + '">×</button></span>';
          }).join("")
          : '<span class="scm-topic-empty">Sin temas propios: usa los temas por defecto.</span>';
        var count = card.querySelector("[data-scm-topic-count]");
        if (count) count.textContent = topics.length + (topics.length === 1 ? " tema" : " temas");
        var show = !query || matches > 0 || fold(label).indexOf(query) !== -1;
        card.hidden = !show;
        if (show) visibleCards += 1;
      });
      if (summary) {
        summary.textContent = query
          ? visibleCards + (visibleCards === 1 ? " bandeja coincide" : " bandejas coinciden")
          : total + " temas en " + form.querySelectorAll("[data-scm-topic-card]").length + " bandejas" +
            (repeated ? " · " + repeated + (repeated === 1 ? " tema repetido" : " temas repetidos") + " entre bandejas" : "");
      }
    }

    function addFrom(card) {
      var input = card.querySelector("[data-scm-topic-input]");
      if (!input) return;
      var additions = input.value.split(/[\n;]/).map(function (item) { return item.trim(); }).filter(Boolean);
      if (!additions.length) return;
      var topics = topicsOf(card);
      var known = {};
      topics.forEach(function (topic) { known[fold(topic)] = true; });
      additions.forEach(function (topic) {
        if (!known[fold(topic)]) {
          topics.push(topic);
          known[fold(topic)] = true;
        }
      });
      writeTopics(card, topics);
      input.value = "";
      render();
      input.focus();
    }

    form.addEventListener("click", function (event) {
      var card = event.target.closest("[data-scm-topic-card]");
      if (!card) return;
      var remove = event.target.closest("[data-scm-topic-remove]");
      if (remove) {
        var topics = topicsOf(card);
        topics.splice(Number(remove.getAttribute("data-scm-topic-remove")), 1);
        writeTopics(card, topics);
        render();
        return;
      }
      if (event.target.closest("[data-scm-topic-add]")) addFrom(card);
    });
    form.addEventListener("keydown", function (event) {
      if (event.key !== "Enter" || !event.target.matches("[data-scm-topic-input]")) return;
      event.preventDefault();
      addFrom(event.target.closest("[data-scm-topic-card]"));
    });
    form.addEventListener("input", function (event) {
      if (event.target.matches("[data-scm-topic-source]")) render();
    });
    if (search) search.addEventListener("input", render);
    render();
  }

  function boot() {
    initPermissions(document.querySelector("#scm-permissions-form[data-scm-perm-form]"));
    initTopics(document.querySelector("#scm-ticket-topic-tabs-form"));
    initTopics(document.querySelector("#scm-case-catalog-form"));
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
  } else {
    boot();
  }
})();
