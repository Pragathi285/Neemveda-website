/* neemveda theme: header and mobile menu, reveal-on-scroll, quantity steppers. Cart and checkout are WooCommerce. */
(function () {
  'use strict';
  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  function initHeader() {
    var header = $('[data-header]');
    var toggle = $('[data-nav-toggle]');
    var nav = $('[data-site-nav]');
    var label = $('[data-nav-label]');
    if (header) {
      var onScroll = function () { header.classList.toggle('is-scrolled', window.scrollY > 8); };
      onScroll();
      window.addEventListener('scroll', onScroll, { passive: true });
    }
    if (!toggle || !nav) return;
    function setOpen(open, returnFocus) {
      toggle.setAttribute('aria-expanded', String(open));
      nav.classList.toggle('is-open', open);
      document.body.classList.toggle('nav-open', open);
      if (label) label.textContent = open ? 'Close menu' : 'Open menu';
      if (open) { var first = $('a', nav); if (first) setTimeout(function () { first.focus(); }, 60); }
      else if (returnFocus) toggle.focus();
    }
    toggle.addEventListener('click', function () { setOpen(toggle.getAttribute('aria-expanded') !== 'true'); });
    nav.addEventListener('click', function (e) { if (e.target.closest('a')) setOpen(false); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('is-open')) setOpen(false, true);
    });
    window.matchMedia('(min-width: 960px)').addEventListener('change', function (mq) { if (mq.matches) setOpen(false); });
  }

  function initReveal() {
    var items = $$('.reveal');
    if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      items.forEach(function (el) { el.classList.add('is-visible'); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) { entry.target.classList.add('is-visible'); io.unobserve(entry.target); }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    items.forEach(function (el) { io.observe(el); });
  }

  function clampQty(n) { n = parseInt(n, 10); return isNaN(n) ? 1 : Math.max(1, Math.min(20, n)); }
  function initQty() {
    $$('[data-qty]').forEach(function (wrap) {
      var input = $('input', wrap);
      wrap.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        input.value = clampQty(parseInt(input.value, 10) + (btn.hasAttribute('data-qty-inc') ? 1 : -1));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      });
      input.addEventListener('blur', function () { input.value = clampQty(input.value); });
    });
  }

  function boot() { initHeader(); initReveal(); initQty(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
