const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

function summaryFunction(file) {
  const source = fs.readFileSync(path.join(__dirname, "..", file), "utf8");
  const start = source.indexOf("function syncActaDamageSummary(item) {");
  assert.ok(start >= 0, `${file}: missing damage summary`);
  const end = source.indexOf("function previewActaDamagePhotos", start);
  assert.ok(end > start, `${file}: missing summary boundary`);
  return new Function(`return (${source.slice(start, end).trim()})`)();
}

for (const file of ["public/assets/js/scm-admin.js", "public/assets/js/ticket-completion-create.js"]) {
  const fieldsByName = {
    descripcion_dano: { value: "Fisura nueva" },
    consecuencia: { value: "Filtración" },
    nivel_dano: { value: "Moderado" },
    tiempo_atencion: { value: "2 dias" },
  };
  const fields = {
    disabled: false,
    querySelector(selector) {
      if (selector.includes("data-corrective-area-field")) return { value: "Muros" };
      const name = selector.match(/\[name\$='\[([^\]]+)\]'\]/)?.[1];
      return fieldsByName[name] || null;
    },
  };
  const wrap = { hidden: false, querySelector: () => fields };
  const damage = { value: "", readOnly: false };
  const item = { querySelector: selector => selector === "[data-acta-corrective-wrap]" ? wrap : damage };
  const sync = summaryFunction(file);
  sync(item);
  assert.equal(damage.value, "Área afectada: Muros\nDescripción del daño: Fisura nueva\nConsecuencia: Filtración\nNivel del daño: Moderado\nTiempo de atención: 2 dias", `${file}: incomplete summary`);
  assert.equal(damage.readOnly, true);
  wrap.hidden = true;
  damage.value = "Daño precargado";
  sync(item);
  assert.equal(damage.value, "Daño precargado", `${file}: existing damage changed`);
}

console.log("Act damage summary checks passed.");
