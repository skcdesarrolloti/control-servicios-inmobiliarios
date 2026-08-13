(function() {
          var panel = document.getElementById('scm-panel-pqr-publico') || document.querySelector('[data-public-pqr-listing]');
          if (!panel) return;

          function ensureTransferModal() {
            var modal = document.getElementById('scm-pqr-transfer-modal');
            if (modal) return modal;
            modal = document.createElement('div');
            modal.id = 'scm-pqr-transfer-modal';
            modal.className = 'scm-pqr-transfer-overlay';
            modal.innerHTML =
              '<div class="scm-pqr-transfer-backdrop" data-close-pqr-transfer="1"></div>' +
              '<div class="scm-pqr-transfer-dialog" role="dialog" aria-modal="true" aria-labelledby="scm-pqr-transfer-title">' +
              '<button type="button" class="scm-pqr-transfer-close" data-close-pqr-transfer="1" aria-label="Cerrar">&times;</button>' +
              '<h4 id="scm-pqr-transfer-title" class="scm-pqr-transfer-title">Trasladar Solicitud</h4>' +
              '<div class="scm-pqr-transfer-body"></div>' +
              '</div>';
            document.body.appendChild(modal);
            modal.addEventListener('click', function(ev) {
              var closeTarget = ev.target && ev.target.closest ? ev.target.closest('[data-close-pqr-transfer="1"]') : null;
              if (!closeTarget) return;
              closeTransferModal();
            });
            return modal;
          }

          function closeTransferModal() {
            var modal = document.getElementById('scm-pqr-transfer-modal');
            if (!modal) return;
            modal.classList.remove('open');
            var body = modal.querySelector('.scm-pqr-transfer-body');
            if (body) body.innerHTML = '';
          }

          function openTransferModal(triggerBtn) {
            if (!triggerBtn) return;
            var row = triggerBtn.closest('[data-pqr-row]');
            if (!row) return;
            var sourceForm = row.querySelector('form.scm-public-pqr-form-inline, form.scm-public-pqr-public-form');
            if (!sourceForm) return;

            var modal = ensureTransferModal();
            var body = modal.querySelector('.scm-pqr-transfer-body');
            if (!body) return;
            body.innerHTML = '';

            var clonedForm = sourceForm.cloneNode(true);
            clonedForm.classList.remove('scm-public-pqr-form-inline');
            clonedForm.classList.add('scm-public-pqr-form-modal');
            clonedForm.style.display = 'block';
            body.appendChild(clonedForm);

            var logicalId = (triggerBtn.getAttribute('data-ticket-logical') || '').trim();
            var title = modal.querySelector('.scm-pqr-transfer-title');
            if (title) {
              title.textContent = logicalId ? ('Trasladar Solicitud #' + logicalId) : 'Trasladar Solicitud';
            }

            modal.classList.add('open');
          }

          function injectIframePrintStyles(frameDocument) {
            if (!frameDocument || !frameDocument.head || frameDocument.getElementById('scm-public-iframe-print-style')) return;
            var printStyle = frameDocument.createElement('style');
            printStyle.id = 'scm-public-iframe-print-style';
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

          function printIframeDocument(iframe, url) {
            try {
              var frameWindow = iframe ? iframe.contentWindow : null;
              var frameDocument = iframe ? iframe.contentDocument || (frameWindow ? frameWindow.document : null) : null;
              if (!frameWindow || !frameDocument) throw new Error('Iframe no disponible');
              injectIframePrintStyles(frameDocument);
              frameWindow.focus();
              setTimeout(function() {
                frameWindow.print();
              }, 80);
              return true;
            } catch (error) {
              window.open(url, '_blank', 'noopener,noreferrer');
              return false;
            }
          }

          function ensureIframeModal() {
            var modal = document.getElementById('scm-public-iframe-modal');
            if (modal) return modal;
            modal = document.createElement('div');
            modal.id = 'scm-public-iframe-modal';
            modal.className = 'scm-public-iframe-overlay';
            modal.innerHTML =
              '<div class="scm-public-iframe-backdrop" data-close-public-iframe="1"></div>' +
              '<div class="scm-public-iframe-dialog" role="dialog" aria-modal="true" aria-labelledby="scm-public-iframe-title">' +
              '<button type="button" class="scm-public-iframe-close" data-close-public-iframe="1" aria-label="Cerrar">&times;</button>' +
              '<div class="scm-public-iframe-header">' +
              '<h4 id="scm-public-iframe-title" class="scm-public-iframe-title">Solicitud</h4>' +
              '<div class="scm-public-iframe-actions">' +
              '<a class="scm-public-iframe-open-tab" href="#" target="_blank" rel="noopener noreferrer">Ver en grande</a>' +
              '<button type="button" class="scm-public-iframe-print">Imprimir / PDF</button>' +
              '</div>' +
              '</div>' +
              '<iframe class="scm-public-iframe-frame" src="about:blank" loading="lazy"></iframe>' +
              '</div>';
            document.body.appendChild(modal);
            modal.addEventListener('click', function(ev) {
              var closeTarget = ev.target && ev.target.closest ? ev.target.closest('[data-close-public-iframe="1"]') : null;
              if (!closeTarget) return;
              closeIframeModal();
            });
            return modal;
          }

          function closeIframeModal() {
            var modal = document.getElementById('scm-public-iframe-modal');
            if (!modal) return;
            modal.classList.remove('open');
            var iframe = modal.querySelector('iframe.scm-public-iframe-frame');
            if (iframe) iframe.src = 'about:blank';
          }

          function openIframeModal(triggerBtn) {
            if (!triggerBtn) return;
            var url = (triggerBtn.getAttribute('data-iframe-url') || '').trim();
            if (!url) return;
            var titleText = (triggerBtn.getAttribute('data-iframe-title') || 'Solicitud').trim();
            var modal = ensureIframeModal();
            var title = modal.querySelector('.scm-public-iframe-title');
            var iframe = modal.querySelector('iframe.scm-public-iframe-frame');
            var openTabLink = modal.querySelector('.scm-public-iframe-open-tab');
            var printButton = modal.querySelector('.scm-public-iframe-print');
            if (title) title.textContent = titleText;
            if (openTabLink) openTabLink.setAttribute('href', url);
            if (printButton) {
              printButton.onclick = function() {
                printIframeDocument(iframe, url);
              };
            }
            if (iframe) {
              iframe.onload = function() {
                try {
                  injectIframePrintStyles(iframe.contentDocument);
                } catch (error) {
                  // Si el navegador bloquea el iframe, queda disponible "Ver en grande".
                }
              };
              iframe.src = url;
            }
            modal.classList.add('open');
          }

          panel.addEventListener('click', function(e) {
            var transferBtn = e.target && e.target.closest ? e.target.closest('[data-scm-open-pqr-transfer]') : null;
            if (transferBtn) {
              e.preventDefault();
              openTransferModal(transferBtn);
              return;
            }
            var iframeBtn = e.target && e.target.closest ? e.target.closest('[data-scm-open-iframe]') : null;
            if (iframeBtn) {
              e.preventDefault();
              openIframeModal(iframeBtn);
            }
          });

          panel.addEventListener('submit', function(e) {
            var form = e.target;
            if (!form || !form.classList || !form.classList.contains('scm-public-pqr-form-modal')) return;
            var btn = form.querySelector('button[type="submit"]');
            if (btn) {
              btn.disabled = true;
              btn.textContent = 'Trasladando...';
            }
          });

          document.addEventListener('keydown', function(ev) {
            if (ev.key !== 'Escape') return;
            closeTransferModal();
            closeIframeModal();
          });
        })();
