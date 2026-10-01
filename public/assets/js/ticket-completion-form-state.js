(function () {
  "use strict";
  var cache = new WeakMap();
  function files(input) { return Array.isArray(input._actaFiles) ? input._actaFiles.slice() : Array.from(input.files || []); }
  function inputs(form) { return Array.from(form.querySelectorAll('[data-acta-photos], [data-corrective-photos]')).filter(function (i) { return !i.disabled && !i.closest('fieldset[disabled]'); }); }
  function kind(input) { return input.matches('[data-corrective-photos]') ? 'damage' : 'solution'; }
  function count(form) { return form.querySelectorAll('[data-acta-existing-photo]').length + inputs(form).reduce(function (n,i) { return n + files(i).length; },0); }
  function setFiles(input, list) {
    input._actaFiles = list.slice();
    try { var dt = new DataTransfer(); list.forEach(function(f) { dt.items.add(f); }); input.files = dt.files; }
    catch (e) { input.value = ''; }
  }
  function preview(input) {
    var area = input.closest('[data-acta-item]').querySelector(kind(input) === 'damage' ? '[data-corrective-photo-preview]' : '[data-acta-photo-preview]');
    if (!area) return;
    area.querySelectorAll('[data-acta-selected-photo], [data-acta-new-damage-photo]').forEach(function(n) { var im=n.matches('img')?n:n.querySelector('img'); if(im) URL.revokeObjectURL(im.src); n.remove(); });
    files(input).forEach(function(file,index) {
      var figure=document.createElement('figure'), im=document.createElement('img'), caption=document.createElement('figcaption'), remove=document.createElement('button');
      figure.dataset.actaSelectedPhoto=''; im.src=URL.createObjectURL(file); im.alt='Evidencia '+(index+1);
      caption.textContent=file.name; remove.type='button'; remove.className='scm-acta-photo-remove';
      remove.dataset.actaUnselect=String(index); remove.dataset.actaPhotoKind=kind(input); remove.setAttribute('aria-label','Quitar '+file.name); remove.textContent='×';
      figure.append(im,caption,remove); area.appendChild(figure);
    });
  }
  function database() {
    return new Promise(function(resolve,reject) {
      if (!window.indexedDB) return reject(new Error('Borrador local no disponible'));
      var req=indexedDB.open('scm-acta-drafts',1);
      req.onupgradeneeded=function() { req.result.createObjectStore('drafts'); };
      req.onsuccess=function() { resolve(req.result); }; req.onerror=function() { reject(req.error); };
    });
  }
  function storage(action,key,value) {
    return database().then(function(db) { return new Promise(function(resolve,reject) {
      var tx=db.transaction('drafts',action==='get'?'readonly':'readwrite'), store=tx.objectStore('drafts');
      var req=action==='get'?store.get(key):action==='put'?store.put(value,key):store.delete(key);
      tx.oncomplete=function() { db.close(); resolve(req.result); }; tx.onerror=tx.onabort=function() { db.close(); reject(tx.error || new Error('No se pudo guardar el borrador')); };
    }); });
  }
  function bind(form, options) {
    if (!form || form._actaState) return;
    options=options||{};
    var key=['acta',form.dataset.actaDraftUser,form.dataset.actaDraftTicket,form.querySelector('[name="source_flow"]').value,form.querySelector('[name="source_cotizacion_id"]').value,form.dataset.actaId||'new'].join(':');
    var revision=form.dataset.actaDraftRevision || '', modifiedWhileLoading=false, loading=true, cleared=false, timer, generation=0, chain=Promise.resolve(), originals=new Map();
    Array.from(form.querySelectorAll('[data-acta-item]')).forEach(function(item,index) { item.dataset.actaDraftItem='server-'+index; originals.set(item.dataset.actaDraftItem,item); });
    var status=document.createElement('p'), counter=document.createElement('p'); status.dataset.actaDraftStatus=''; status.setAttribute('role','status'); counter.dataset.actaPhotoCount='';
    form.prepend(status,counter);
    function message(text) { status.textContent=text; }
    function refresh() { counter.textContent='Fotos del acta: '+count(form)+'. Sin límite de cantidad; 8 MB en total después de comprimir.'; }
    function value(field) { return field.type==='checkbox'||field.type==='radio'?field.checked:field.multiple?Array.from(field.selectedOptions).map(function(o){return o.value;}):field.value; }
    function collectFields(scope,item) {
      var out={}; scope.querySelectorAll('input[name],select[name],textarea[name]').forEach(function(field) {
        if(field.type==='file'||field.type==='hidden'||field.readOnly||(!item&&field.closest('[data-acta-item]')))return;
        out[item?field.name.replace(/^items\[\d+\]/,''):field.name]=value(field);
      }); return out;
    }
    function snapshot() {
      var identities=new Set();
      return {revision:revision, savedAt:Date.now(), fields:collectFields(form,false), items:Array.from(form.querySelectorAll('[data-acta-item]')).map(function(item) {
        if(!item.dataset.actaDraftItem||identities.has(item.dataset.actaDraftItem))item.dataset.actaDraftItem='new-'+Date.now()+'-'+Math.random();
        identities.add(item.dataset.actaDraftItem);
        return {key:item.dataset.actaDraftItem,values:collectFields(item,true),kept:Array.from(item.querySelectorAll('[data-acta-existing-photo] input[name$="[name]"]')).map(function(i){return i.value;}),photos:inputs(item).map(function(input){return {kind:kind(input),files:files(input)};})};
      })};
    }
    function save() {
      if(loading||cleared)return Promise.resolve();
      var current=++generation, data=snapshot(); message('Guardando borrador y fotos en este navegador…');
      var task=Promise.all(data.items.map(function(item){return Promise.all(item.photos.map(function(group){return Promise.all(group.files.map(function(file){
        if(!cache.has(file))cache.set(file,Promise.resolve().then(function(){return options.compress?options.compress(file):file;}));
        return cache.get(file).then(function(compressed){return {blob:compressed,name:compressed.name,lastModified:compressed.lastModified};});
      })).then(function(saved){group.files=saved;});}));})).then(function(){
        if(cleared||current!==generation)return;
        return storage('put',key,data).then(function(){if(current===generation)message('Borrador guardado en este navegador, incluidas las fotos. '+new Date(data.savedAt).toLocaleTimeString('es-CO'));});
      }).catch(function(){message('No se pudo guardar el borrador local. Conserva abierta esta página para no perder lo escrito ni las fotos.');});
      chain=chain.then(function(){return task;}); return chain;
    }
    function schedule(){if(loading||cleared)return;clearTimeout(timer);timer=setTimeout(save,150);refresh();}
    function restoreFields(scope,data,item){scope.querySelectorAll('input[name],select[name],textarea[name]').forEach(function(field){
      var name=item?field.name.replace(/^items\[\d+\]/,''):field.name;
      if(field.type==='file'||field.type==='hidden'||field.readOnly||(!item&&field.closest('[data-acta-item]'))||!Object.prototype.hasOwnProperty.call(data,name))return;
      if(field.type==='checkbox'||field.type==='radio')field.checked=!!data[name];
      else if(field.multiple&&Array.isArray(data[name]))Array.from(field.options).forEach(function(o){o.selected=data[name].includes(o.value);});
      else field.value=data[name];
      field.dispatchEvent(new Event('change',{bubbles:true}));
    });}
    function restore(data){
      if(!data)return;
      if(modifiedWhileLoading){message('Se conservan los cambios que acabas de escribir. El borrador anterior no se cargó.');return;}
      if(data.revision!==revision||Date.now()-data.savedAt>7*86400000){message('Hay un borrador anterior, pero el acta o los datos de origen cambiaron. Se cargó la versión actual para evitar sobrescribirla.');return;}
      var wrap=form.querySelector('[data-acta-items]'), restored=[];
      data.items.slice(0,30).forEach(function(saved){
        var item=originals.get(saved.key);
        if(!item){
          if(restored.length)wrap.replaceChildren.apply(wrap,restored);
          else wrap.replaceChildren(originals.values().next().value);
          var before=wrap.children.length;form.querySelector('[data-acta-add-item]').click();
          if(wrap.children.length===before)throw new Error('No se pudo recuperar el detalle del borrador');
          item=wrap.lastElementChild;
        }
        item.dataset.actaDraftItem=saved.key;
        item.querySelectorAll('[data-acta-existing-photo]').forEach(function(f){var n=f.querySelector('input[name$="[name]"]');if(!n||!saved.kept.includes(n.value))f.remove();});
        restoreFields(item,saved.values,true);
        (saved.photos||[]).forEach(function(group){var input=item.querySelector(group.kind==='damage'?'[data-corrective-photos]':'[data-acta-photos]');if(input){setFiles(input,group.files.map(function(f){return new File([f.blob],f.name,{type:f.blob.type,lastModified:f.lastModified});}));preview(input);}});
        restored.push(item);
      });
      if(restored.length)wrap.replaceChildren.apply(wrap,restored);
      restoreFields(form,data.fields,false); refresh(); message('Borrador recuperado de este navegador, incluidas las fotos y los cambios pendientes.');
    }
    form._actaState={save:save,clear:function(){cleared=true;++generation;clearTimeout(timer);return chain.then(function(){return storage('delete',key);}).catch(function(){message('El acta se guardó, pero no se pudo borrar el borrador local.');});}};
    function add(input,incoming){
      var old=Array.isArray(input._actaFiles)?input._actaFiles.slice():[], known=new Set(old.map(function(f){return [f.name,f.size,f.lastModified].join('|');}));
      var next=old.slice(); incoming.forEach(function(f){var id=[f.name,f.size,f.lastModified].join('|');if(!known.has(id)){known.add(id);next.push(f);}});
      if(next.some(function(f){return !['image/jpeg','image/png','image/webp'].includes(f.type)||f.size>25*1024*1024;})){setFiles(input,old);message('Usa JPG, PNG o WebP de máximo 25 MB cada una.');return;}

      setFiles(input,next);preview(input);schedule();
    }
    form.addEventListener('change',function(event){var input=event.target;if(!input.matches('[data-acta-photos],[data-corrective-photos]'))return;event.stopImmediatePropagation();add(input,Array.from(input.files||[]));},true);
    form.addEventListener('paste',function(event){
      var button=event.target.closest('[data-acta-photo-paste]');if(!button)return;event.preventDefault();event.stopImmediatePropagation();
      var incoming=Array.from(event.clipboardData&&event.clipboardData.items||[]).filter(function(i){return /^image\//.test(i.type);}).map(function(i,index){var blob=i.getAsFile();return blob&&new File([blob],'captura-'+Date.now()+'-'+index+'.png',{type:blob.type});}).filter(Boolean);
      if(incoming.length)add(button.closest('[data-acta-item]').querySelector('[data-acta-photos]'),incoming);else message('No se encontró una imagen en el portapapeles.');
    },true);
    form.addEventListener('click',function(event){
      var button=event.target.closest('[data-acta-unselect],[data-acta-remove-existing-photo]');if(!button)return;
      event.preventDefault();event.stopImmediatePropagation();
      if(button.hasAttribute('data-acta-remove-existing-photo'))button.closest('[data-acta-existing-photo]').remove();
      else {var input=button.closest('[data-acta-item]').querySelector(button.dataset.actaPhotoKind==='damage'?'[data-corrective-photos]':'[data-acta-photos]'), list=files(input);list.splice(Number(button.dataset.actaUnselect),1);setFiles(input,list);preview(input);}
      schedule();
    },true);
    form.addEventListener('input',function(){if(loading)modifiedWhileLoading=true;schedule();});form.addEventListener('change',schedule);
    form.addEventListener('click',function(event){
      var adding=event.target.closest('[data-acta-add-item]');
      queueMicrotask(function(){
        if(adding&&!loading){
          var added=form.querySelector('[data-acta-items]').lastElementChild;
          var duplicate=Array.from(form.querySelectorAll('[data-acta-item]')).some(function(item){return item!==added&&item.dataset.actaDraftItem===added.dataset.actaDraftItem;});
          if(!added.dataset.actaDraftItem||duplicate)added.dataset.actaDraftItem='new-'+Date.now()+'-'+Math.random();
          inputs(added).forEach(preview);
        }
        schedule();
      });
    });
    form.addEventListener('submit',function(event){if(loading){event.preventDefault();event.stopImmediatePropagation();message('Espera a recuperar el borrador.');}},true);
    storage('get',key).then(restore).catch(function(){message('Borrador local no disponible. No cierres esta página sin guardar el acta.');}).finally(function(){loading=false;refresh();});
    window.addEventListener('pagehide',function(){if(form.isConnected)save();});
    document.addEventListener('visibilitychange',function(){if(document.hidden&&form.isConnected)save();});
    var observer=new MutationObserver(function(){if(!form.isConnected){save();observer.disconnect();}});
    observer.observe(document.body,{childList:true,subtree:true});
  }
  window.ScmActaFormState={bind:bind,count:count,preview:preview};
})();
