(function() {
          if (window.self === window.top) return;
          var root = document.getElementById("public-ticket-app");
          var runtime = root ? JSON.parse(root.getAttribute("data-runtime") || "{}") : {};
          var actor = runtime.actor || "";

          function sendHeight() {
            var body = document.body;
            var doc = document.documentElement;
            if (!body || !doc) return;
            var height = Math.max(
              body.scrollHeight,
              body.offsetHeight,
              doc.clientHeight,
              doc.scrollHeight,
              doc.offsetHeight
            );
            window.parent.postMessage({
              type: 'scm-public-portal-height',
              actor: actor,
              height: height
            }, '*');
          }

          window.addEventListener('load', sendHeight);
          window.addEventListener('resize', sendHeight);
          if (window.MutationObserver) {
            var observer = new MutationObserver(function() {
              sendHeight();
            });
            observer.observe(document.body, {
              attributes: true,
              childList: true,
              subtree: true
            });
          }
          setTimeout(sendHeight, 60);
          setTimeout(sendHeight, 320);
          setTimeout(sendHeight, 900);
        })();
