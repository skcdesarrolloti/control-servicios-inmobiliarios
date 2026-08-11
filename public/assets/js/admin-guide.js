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
