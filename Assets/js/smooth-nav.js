/* ========================================================
   SMOOTH NAVIGATION  (Assets/js/smooth-nav.js)
   - Loads motion.css on pages that don't use includes/header.php
   - Shows a thin loading bar while the next page loads
   - Fetches a page early when you hover/press its link (Chrome, Edge)
   Included once at the end of includes/footer.php.
   ======================================================== */
(function () {
  'use strict';

  // Set to false to turn off early page loading (see the note about forms).
  var PREFETCH = true;

  /* ---------- 1. Make sure motion.css is on the page ---------- */
  try {
    var me = document.currentScript;
    if (me && me.src && !document.querySelector('link[href*="motion.css"]')) {
      var css = document.createElement('link');
      css.rel = 'stylesheet';
      css.href = me.src.replace(/js\/smooth-nav\.js.*$/, 'css/motion.css?v=2');
      document.head.appendChild(css);
    }
  } catch (e) { /* not critical */ }

  /* ---------- 2. Loading bar ---------- */
  var bar = document.createElement('div');
  bar.className = 'nav-progress';
  bar.setAttribute('aria-hidden', 'true');
  document.body.appendChild(bar);

  var showTimer = null;
  var safetyTimer = null;

  function start() {
    clearTimeout(showTimer);
    clearTimeout(safetyTimer);
    // Only show it if the page takes a moment, so fast pages never flash it
    showTimer = setTimeout(function () { bar.classList.add('is-loading'); }, 120);
    // Never leave it running (e.g. a download that keeps you on the page)
    safetyTimer = setTimeout(stop, 10000);
  }

  function stop() {
    clearTimeout(showTimer);
    clearTimeout(safetyTimer);
    bar.classList.remove('is-loading');
  }

  window.addEventListener('pageshow', stop);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') stop();
  });

  function isPageLink(a, e) {
    if (!a || e.defaultPrevented || e.button !== 0) return false;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return false;
    if (a.target && a.target !== '_self') return false;
    if (a.hasAttribute('download') || a.hasAttribute('data-bs-toggle') || a.hasAttribute('data-no-progress')) return false;

    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return false;

    var url;
    try { url = new URL(a.href, window.location.href); } catch (err) { return false; }
    if (url.origin !== window.location.origin) return false;
    if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return false;
    if (/[?&]export=/.test(url.search)) return false; // CSV downloads stay on the page
    return true;
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (isPageLink(a, e)) start();
  });

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (e.defaultPrevented || !form) return;
    if (form.target && form.target !== '_self') return;
    if (form.hasAttribute('data-no-progress')) return;
    if (/export=/.test(form.getAttribute('action') || '')) return;
    start();
  });

  // Street filters use this.form.submit(), which skips the submit event
  var nativeSubmit = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () {
    if ((!this.target || this.target === '_self') && !this.hasAttribute('data-no-progress')) start();
    return nativeSubmit.apply(this, arguments);
  };

  /* ---------- 3. Load the next page early on hover / press ---------- */
  var canSpeculate = PREFETCH &&
    window.HTMLScriptElement &&
    typeof HTMLScriptElement.supports === 'function' &&
    HTMLScriptElement.supports('speculationrules');

  if (canSpeculate) {
    var rules = {
      prefetch: [{
        source: 'document',
        where: {
          and: [
            { href_matches: '/*' },
            { not: { selector_matches: [
              'a[href*="logout"]',
              'a[href*="export="]',
              'a[href*="delete"]',
              'a[href*="mark_read"]',
              'a[href*="receipt"]',
              'a[target="_blank"]',
              'a[download]',
              'a[data-no-prefetch]',
              'a[data-bs-toggle]'
            ].join(', ') } }
          ]
        },
        // "moderate" = after ~200ms of hover on a computer, or on press on a phone
        eagerness: 'moderate'
      }]
    };
    var s = document.createElement('script');
    s.type = 'speculationrules';
    s.textContent = JSON.stringify(rules);
    document.head.appendChild(s);
  }
})();