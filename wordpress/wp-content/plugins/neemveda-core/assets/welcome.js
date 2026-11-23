/* neemveda welcome popup: "Create your profile" or "Continue as guest". */
(function () {
  'use strict';
  var cfg = window.NVC_WELCOME;
  if (!cfg) return;

  function setCookie(value, days) {
    var c = cfg.cookie + '=' + value + '; Max-Age=' + Math.round(days * 86400) + '; Path=/; SameSite=Lax';
    if (cfg.secure) c += '; Secure';
    document.cookie = c;
  }
  function hasCookie() {
    return document.cookie.split('; ').some(function (c) { return c.indexOf(cfg.cookie + '=') === 0; });
  }
  function toast(text) {
    var el = document.querySelector('[data-toast]');
    if (!el) return;
    el.textContent = text;
    el.classList.add('is-visible');
    setTimeout(function () { el.classList.remove('is-visible'); }, 4000);
  }

  function boot() {
    if (cfg.created) {
      setCookie('member', 365);
      toast('Your profile is ready. Welcome to neemveda!');
      if (window.history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.delete('nv_welcome');
        window.history.replaceState(null, '', url.toString());
      }
    }

    var dlg = document.querySelector('[data-nv-welcome]');
    if (!dlg || typeof dlg.showModal !== 'function') return;

    function close(choice) {
      // Guest: don't ask again for 30 days. ×, Esc or clicking outside: ask again tomorrow.
      setCookie(choice === 'guest' ? 'guest' : 'later', choice === 'guest' ? 30 : 1);
      if (dlg.open) dlg.close();
      if (choice === 'guest') toast('You’re browsing as a guest. You can create a profile any time from the account icon.');
    }

    dlg.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-nv-welcome-close]');
      if (btn) { close(btn.getAttribute('data-nv-welcome-close')); return; }
      if (e.target === dlg) close('later'); // click on the dimmed backdrop
    });
    dlg.addEventListener('cancel', function (e) { e.preventDefault(); close('later'); });

    if (cfg.openNow) { dlg.showModal(); return; }   // sign-up had errors: show them
    if (hasCookie()) return;
    setTimeout(function () {
      if (!document.querySelector('dialog[open]')) dlg.showModal();
    }, cfg.delay || 0);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
