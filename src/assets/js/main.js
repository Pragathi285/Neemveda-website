/* neemveda – site behaviour. No dependencies. Data comes from /assets/js/data.js (generated). */
(function () {
  'use strict';

  var NV = window.NV || { site: {}, products: [] };
  var site = NV.site || {};
  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  // Relative prefix for links (set per page by build.js) so the site works from disk too.
  var BASE = window.NV_BASE || '';
  function u(path) { return BASE + String(path).replace(/^\//, ''); }

  var API = (site.payments && site.payments.api) || ''; // e.g. "/api"; empty = demo mode without a server

  var PLACEHOLDER_PRICE = '₹[XX]';
  function money(n) { return n == null ? PLACEHOLDER_PRICE : '₹' + Number(n).toLocaleString('en-IN'); }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function productById(id) {
    for (var i = 0; i < NV.products.length; i++) if (NV.products[i].id === id) return NV.products[i];
    return null;
  }
  function clampQty(n) { n = parseInt(n, 10); return isNaN(n) ? 1 : Math.max(1, Math.min(20, n)); }

  /* ---------------------------------------------------------------- Cart */
  var Cart = {
    key: 'nv_cart_v1',
    read: function () {
      try {
        var items = JSON.parse(localStorage.getItem(this.key)) || [];
        return items.filter(function (it) { var p = productById(it.id); return p && p.available; });
      } catch (e) { return []; }
    },
    write: function (items) {
      try { localStorage.setItem(this.key, JSON.stringify(items)); } catch (e) { /* storage unavailable */ }
      document.dispatchEvent(new CustomEvent('cart:change'));
    },
    add: function (id, qty) {
      var items = this.read();
      var found = items.filter(function (it) { return it.id === id; })[0];
      if (found) found.qty = clampQty(found.qty + qty);
      else items.push({ id: id, qty: clampQty(qty) });
      this.write(items);
    },
    set: function (id, qty) {
      this.write(this.read().map(function (it) { return it.id === id ? { id: id, qty: clampQty(qty) } : it; }));
    },
    remove: function (id) { this.write(this.read().filter(function (it) { return it.id !== id; })); },
    clear: function () { this.write([]); },
    count: function () { return this.read().reduce(function (n, it) { return n + it.qty; }, 0); },
    lines: function () {
      return this.read().map(function (it) {
        var p = productById(it.id);
        return { product: p, qty: it.qty, total: p.price == null ? null : p.price * it.qty };
      });
    },
    totals: function () {
      var lines = this.lines();
      var subtotal = lines.some(function (l) { return l.total == null; })
        ? null
        : lines.reduce(function (s, l) { return s + l.total; }, 0);
      var ship = site.shipping || {};
      var shipping = null;
      if (subtotal != null && ship.freeAbove != null && subtotal >= ship.freeAbove) shipping = 0;
      else if (ship.flatRate != null) shipping = ship.flatRate;
      var total = subtotal != null && shipping != null ? subtotal + shipping : null;
      return { subtotal: subtotal, shipping: shipping, total: total, count: this.count() };
    }
  };
  window.NVCart = Cart;

  function updateCartBadge(bump) {
    var count = Cart.count();
    $$('[data-cart-count]').forEach(function (el) {
      el.textContent = count > 99 ? '99+' : String(count);
      el.classList.toggle('has-items', count > 0);
      if (bump && count > 0) { el.classList.remove('bump'); void el.offsetWidth; el.classList.add('bump'); }
    });
    $$('[data-cart-label]').forEach(function (el) {
      el.textContent = count ? 'Cart, ' + count + (count === 1 ? ' item' : ' items') : 'Cart, empty';
    });
  }

  /* ---------------------------------------------------------------- Toast */
  var toastTimer;
  function toast(html) {
    var el = $('[data-toast]');
    if (!el) return;
    el.innerHTML = html;
    el.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.classList.remove('is-visible'); }, 4200);
  }

  /* ---------------------------------------------------------------- Header & nav */
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

  /* ---------------------------------------------------------------- Reveal on scroll */
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

  /* ---------------------------------------------------------------- Quantity steppers */
  function initQty(root) {
    $$('[data-qty]', root).forEach(function (wrap) {
      if (wrap.dataset.bound) return;
      wrap.dataset.bound = '1';
      var input = $('input', wrap);
      var set = function (v) {
        input.value = clampQty(v);
        input.dispatchEvent(new Event('change', { bubbles: true }));
      };
      wrap.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        set(parseInt(input.value, 10) + (btn.hasAttribute('data-qty-inc') ? 1 : -1));
      });
      input.addEventListener('blur', function () { input.value = clampQty(input.value); });
    });
  }

  /* ---------------------------------------------------------------- Add to cart / Buy now */
  function qtyFor(el) {
    var scope = el.closest('[data-product]');
    var input = scope && $('[data-qty] input', scope);
    return input ? clampQty(input.value) : 1;
  }
  function initBuyButtons() {
    document.addEventListener('click', function (e) {
      var add = e.target.closest('[data-add-to-cart]');
      var buy = e.target.closest('[data-buy-now]');
      if (add) {
        var id = add.getAttribute('data-add-to-cart');
        var p = productById(id);
        if (!p || !p.available) return;
        var qty = qtyFor(add);
        Cart.add(id, qty);
        toast('<span>Added ' + qty + ' × ' + esc(p.name) + '</span><a href="' + u('cart.html') + '">View cart</a>');
        var original = add.innerHTML;
        add.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#i-check"/></svg> Added';
        add.disabled = true;
        setTimeout(function () { add.innerHTML = original; add.disabled = false; }, 1600);
      }
      if (buy) {
        var bid = buy.getAttribute('data-buy-now');
        var bp = productById(bid);
        if (!bp || !bp.available) return;
        Cart.add(bid, qtyFor(buy));
        window.location.href = u('checkout.html');
      }
    });
  }

  /* ---------------------------------------------------------------- Product gallery */
  function initGallery() {
    var main = $('[data-gallery-main]');
    if (!main) return;
    $$('[data-gallery-thumb]').forEach(function (btn, _, all) {
      btn.addEventListener('click', function () {
        all.forEach(function (b) { b.setAttribute('aria-pressed', String(b === btn)); });
        main.src = btn.dataset.src;
        main.alt = btn.dataset.alt;
        main.classList.toggle('is-box', btn.hasAttribute('data-box'));
      });
    });
  }

  /* ---------------------------------------------------------------- Forms */
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

  function postJSON(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) {
        if (!r.ok) {
          var err = new Error(data.error || 'Something went wrong. Please try again.');
          err.field = data.field;
          throw err;
        }
        return data;
      });
    }, function () {
      throw new Error('Could not connect. Please check your internet connection and try again.');
    });
  }

  function initNewsletter() {
    $$('[data-newsletter]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var input = form.querySelector('input[type=email]');
        var msg = form.querySelector('[data-form-msg]');
        var email = input.value.trim();
        if (!EMAIL_RE.test(email)) {
          msg.textContent = 'Please enter a valid email address.';
          input.setAttribute('aria-invalid', 'true');
          input.focus();
          return;
        }
        input.removeAttribute('aria-invalid');
        var done = function () {
          msg.textContent = 'Thank you! You are on the list. Look out for a gentle hello from us soon.';
          form.reset();
        };
        var endpoint = site.forms && site.forms.newsletterEndpoint;
        if (!endpoint) {
          console.info('[neemveda] newsletterEndpoint not set in src/data/site.json: signup not stored.');
          done();
          return;
        }
        postJSON(endpoint, { email: email }).then(done).catch(function () {
          msg.textContent = 'Sorry, something went wrong. Please try again in a moment.';
        });
      });
    });
  }

  function fieldError(field, message) {
    var wrap = field.closest('.field');
    var err = wrap && $('.error', wrap);
    if (wrap) wrap.classList.toggle('has-error', !!message);
    if (err) err.textContent = message || '';
    if (message) field.setAttribute('aria-invalid', 'true');
    else field.removeAttribute('aria-invalid');
    return !message;
  }

  function validateForm(form, rules) {
    var firstBad = null;
    Object.keys(rules).forEach(function (name) {
      var field = form.elements[name];
      if (!field) return;
      var value = (field.value || '').trim();
      var message = rules[name](value, field);
      if (!fieldError(field.length && !field.tagName ? field[0] : field, message) && !firstBad) firstBad = field;
    });
    if (firstBad) (firstBad.length && !firstBad.tagName ? firstBad[0] : firstBad).focus();
    return !firstBad;
  }

  var normalisePhone = function (v) { return v.replace(/[\s-]/g, '').replace(/^(\+91|91|0)(?=\d{10}$)/, ''); };
  var rules = {
    required: function (label) { return function (v) { return v ? '' : 'Please enter your ' + label + '.'; }; },
    email: function (v) { return !v ? 'Please enter your email address.' : EMAIL_RE.test(v) ? '' : 'Please enter a valid email address.'; },
    phone: function (v) { return /^[6-9]\d{9}$/.test(normalisePhone(v)) ? '' : 'Please enter a valid 10-digit Indian mobile number.'; },
    pincode: function (v) { return /^[1-9]\d{5}$/.test(v) ? '' : 'Please enter a valid 6-digit PIN code.'; }
  };

  function initContact() {
    var form = $('[data-contact-form]');
    if (!form) return;
    var msg = $('[data-form-msg]', form);
    var check = function () {
      return validateForm(form, {
        name: rules.required('name'),
        email: rules.email,
        message: rules.required('message')
      });
    };
    var compose = function () {
      return 'Hi neemveda, this is ' + form.elements.name.value.trim() +
        (form.elements.topic.value ? ' (' + form.elements.topic.value + ')' : '') + '.\n\n' +
        form.elements.message.value.trim() + '\n\nEmail: ' + form.elements.email.value.trim() +
        (form.elements.phone.value.trim() ? '\nPhone: ' + form.elements.phone.value.trim() : '');
    };
    var waBtn = $('[data-contact-wa]', form);
    if (waBtn) waBtn.addEventListener('click', function () {
      if (!check()) return;
      window.open('https://wa.me/' + site.whatsapp + '?text=' + encodeURIComponent(compose()), '_blank', 'noopener');
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!check()) return;
      var endpoint = site.forms && site.forms.contactEndpoint;
      if (!endpoint) {
        // No form backend yet: hand the message to the visitor's email app.
        window.location.href = 'mailto:' + site.email + '?subject=' +
          encodeURIComponent('Website enquiry' + (form.elements.topic.value ? ': ' + form.elements.topic.value : '')) +
          '&body=' + encodeURIComponent(compose());
        msg.textContent = 'Opening your email app… If nothing happens, write to us at ' + site.email + '.';
        msg.classList.add('is-success');
        return;
      }
      var data = {};
      new FormData(form).forEach(function (v, k) { data[k] = v; });
      postJSON(endpoint, data).then(function () {
        msg.textContent = 'Thank you! We have received your message and will reply within 1–2 working days.';
        msg.classList.add('is-success');
        form.reset();
      }).catch(function () {
        msg.textContent = 'Sorry, the message could not be sent. Please try WhatsApp or email instead.';
      });
    });
  }

  /* ---------------------------------------------------------------- Cart page */
  function placeholderTag(n) { return n == null ? ' <span class="placeholder">[PLACEHOLDER]</span>' : ''; }

  function shippingLabel(t) {
    var ship = site.shipping || {};
    if (t.shipping === 0) return 'Free';
    if (t.shipping == null && ship.freeAbove != null) return 'Free from ' + money(ship.freeAbove);
    return money(t.shipping) + placeholderTag(t.shipping);
  }
  function totalLabel(t) { return t.total != null ? money(t.total) : t.subtotal != null ? '—' : money(null); }

  /* Below the free-shipping threshold: a hint, or (if no flat rate is set) a block on ordering online. */
  function shippingNote(t) {
    var ship = site.shipping || {};
    if (t.subtotal == null || ship.freeAbove == null || t.subtotal >= ship.freeAbove) return null;
    var gap = money(ship.freeAbove - t.subtotal);
    if (t.shipping == null) {
      return {
        blocked: true,
        html: 'Add <strong>' + gap + '</strong> more to order online. Orders of ' + money(ship.freeAbove) +
          ' and above ship free. Or <a href="https://wa.me/' + site.whatsapp + '" target="_blank" rel="noopener">order on WhatsApp</a>.'
      };
    }
    return { blocked: false, html: 'Add <strong>' + gap + '</strong> more for free shipping.' };
  }
  function noteHtml(note) {
    return note ? '<p class="notice" role="status"><svg class="icon" aria-hidden="true"><use href="#i-truck"/></svg><span>' + note.html + '</span></p>' : '';
  }

  function renderCart() {
    var root = $('[data-cart-root]');
    if (!root) return;
    var lines = Cart.lines();
    if (!lines.length) {
      root.innerHTML =
        '<div class="card empty">' +
        '<div class="empty__icon"><svg class="icon" aria-hidden="true"><use href="#i-bag"/></svg></div>' +
        '<h2>Your cart is empty</h2>' +
        '<p class="muted">A simple, natural start to your mornings is one click away.</p>' +
        '<a class="btn btn-primary" href="' + u('shop.html') + '">Shop neem sticks</a></div>';
      return;
    }
    var t = Cart.totals();
    var note = shippingNote(t);
    var items = lines.map(function (l) {
      var p = l.product;
      return '<li class="card cart-item" data-product="' + p.id + '">' +
        '<a class="cart-item__img" href="' + u(p.url) + '" tabindex="-1" aria-hidden="true"><img src="' + u(p.image) + '" alt="" width="68" height="84"></a>' +
        '<div><h2 class="cart-item__title"><a href="' + u(p.url) + '">' + esc(p.name) + '</a></h2>' +
        '<p class="cart-item__meta">' + money(p.price) + placeholderTag(p.price) + ' each · ' + p.count + ' neem sticks</p>' +
        '<div class="cart-item__controls">' +
        '<div class="qty qty--sm" data-qty>' +
        '<button type="button" data-qty-dec aria-label="Decrease quantity of ' + esc(p.name) + '"><svg class="icon" aria-hidden="true"><use href="#i-minus"/></svg></button>' +
        '<input type="number" inputmode="numeric" min="1" max="20" value="' + l.qty + '" aria-label="Quantity of ' + esc(p.name) + '" data-cart-qty="' + p.id + '">' +
        '<button type="button" data-qty-inc aria-label="Increase quantity of ' + esc(p.name) + '"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg></button>' +
        '</div>' +
        '<span class="cart-item__total">' + money(l.total) + '</span>' +
        '<button class="cart-item__remove" type="button" data-cart-remove="' + p.id + '"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg>Remove<span class="sr-only"> ' + esc(p.name) + '</span></button>' +
        '</div></div></li>';
    }).join('');

    root.innerHTML =
      '<div class="cart-layout">' +
      '<section aria-label="Items in your cart"><ul class="cart-items">' + items + '</ul>' +
      '<p class="mt-lg"><a class="link-arrow" href="' + u('shop.html') + '"><svg class="icon" aria-hidden="true" style="transform:rotate(180deg)"><use href="#i-arrow"/></svg> Continue shopping</a></p></section>' +
      '<aside class="card summary" aria-labelledby="summary-title">' +
      '<h2 id="summary-title">Order summary</h2>' +
      '<div class="summary__rows">' +
      '<div class="summary__row"><span>Subtotal (' + t.count + (t.count === 1 ? ' item' : ' items') + ')</span><span>' + money(t.subtotal) + '</span></div>' +
      '<div class="summary__row"><span>Shipping</span><span>' + shippingLabel(t) + '</span></div>' +
      '<div class="summary__row summary__row--total"><span>Total</span><span>' + totalLabel(t) + '</span></div>' +
      '</div>' +
      noteHtml(note) +
      (note && note.blocked
        ? '<span class="btn btn-ghost btn-block is-disabled" aria-disabled="true">Proceed to checkout</span>'
        : '<a class="btn btn-primary btn-block" href="' + u('checkout.html') + '">Proceed to checkout <svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg></a>') +
      '<p class="summary__secure"><svg class="icon" aria-hidden="true"><use href="#i-lock"/></svg>Secure checkout · UPI, cards &amp; netbanking</p>' +
      '</aside></div>';
    initQty(root);
  }

  function initCartPage() {
    var root = $('[data-cart-root]');
    if (!root) return;
    renderCart();
    root.addEventListener('change', function (e) {
      var id = e.target.getAttribute('data-cart-qty');
      if (id) {
        var active = document.activeElement && document.activeElement.getAttribute('aria-label');
        Cart.set(id, e.target.value);
        renderCart();
        if (active) { var again = root.querySelector('[aria-label="' + active.replace(/"/g, '\\"') + '"]'); if (again) again.focus(); }
      }
    });
    root.addEventListener('click', function (e) {
      var rm = e.target.closest('[data-cart-remove]');
      if (rm) {
        var p = productById(rm.getAttribute('data-cart-remove'));
        Cart.remove(rm.getAttribute('data-cart-remove'));
        renderCart();
        toast('<span>Removed ' + esc(p ? p.name : 'item') + '</span>');
        var next = root.querySelector('[data-cart-remove], .btn');
        if (next) next.focus();
      }
    });
  }

  /* ---------------------------------------------------------------- Checkout */
  function renderCheckoutSummary() {
    var box = $('[data-checkout-summary]');
    if (!box) return;
    var lines = Cart.lines();
    var t = Cart.totals();
    box.innerHTML =
      '<ul class="summary__lines">' + lines.map(function (l) {
        return '<li class="summary__line"><img src="' + u(l.product.image) + '" alt="" width="42" height="52">' +
          '<span>' + esc(l.product.name) + '<br><span class="muted">Qty ' + l.qty + '</span></span>' +
          '<span>' + money(l.total) + '</span></li>';
      }).join('') + '</ul>' +
      '<div class="summary__rows">' +
      '<div class="summary__row"><span>Subtotal</span><span>' + money(t.subtotal) + '</span></div>' +
      '<div class="summary__row"><span>Shipping</span><span>' + shippingLabel(t) + '</span></div>' +
      '<div class="summary__row summary__row--total"><span>Total</span><span>' + totalLabel(t) + '</span></div>' +
      '</div>';
    // Show the minimum-order notice next to the submit button and block ordering until it's met.
    var note = shippingNote(t);
    var slot = $('[data-checkout-note]');
    var submit = $('[data-checkout-form] [type=submit]');
    if (slot) slot.innerHTML = noteHtml(note);
    if (submit) submit.disabled = !!(note && note.blocked);
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src; s.onload = resolve; s.onerror = reject;
      document.head.appendChild(s);
    });
  }

  function orderRef() { return 'NV-' + Date.now().toString(36).toUpperCase().slice(-6); }

  function showConfirmation(order, demo) {
    var root = $('[data-checkout-root]');
    var summary = order.lines.map(function (l) { return l.qty + ' × ' + l.product.name; }).join(', ');
    var waText = 'Hi neemveda, I just placed order ' + order.ref + ' (' + summary + ').';
    root.innerHTML =
      '<div class="card confirmation" tabindex="-1" data-confirmation>' +
      '<div class="confirmation__icon"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg></div>' +
      '<p class="eyebrow">Order ' + esc(order.ref) + '</p>' +
      '<h2>Thank you, ' + esc(order.customer.name.split(' ')[0]) + '!</h2>' +
      '<p>Your order has been received. We will send updates to <strong>' + esc(order.customer.email) + '</strong> and on WhatsApp at <strong>' + esc(order.customer.phone) + '</strong>.</p>' +
      (demo ? '<p class="notice"><span><span class="placeholder">[PLACEHOLDER]</span> Demo mode: no payment gateway or order backend is connected yet, so no payment was taken and this order was not stored.</span></p>' : '') +
      '<div class="btn-row" style="justify-content:center">' +
      '<a class="btn btn-primary" href="' + u('shop.html') + '">Continue shopping</a>' +
      '<a class="btn btn-wa" href="https://wa.me/' + site.whatsapp + '?text=' + encodeURIComponent(waText) + '" target="_blank" rel="noopener"><svg class="icon" aria-hidden="true"><use href="#i-whatsapp"/></svg>Message us</a>' +
      '</div></div>';
    Cart.clear();
    var heading = $('[data-checkout-heading]');
    if (heading) heading.textContent = 'Order received';
    $('[data-confirmation]').focus();
    window.scrollTo({ top: 0 });
  }

  /* Opens Razorpay Checkout for an order the server already created, then asks the server to verify it. */
  function openRazorpay(order, rp, ui) {
    return loadScript('https://checkout.razorpay.com/v1/checkout.js').then(function () {
      return new Promise(function (resolve) {
        var prefill = { name: rp.prefill.name, email: rp.prefill.email, contact: rp.prefill.contact };
        if (order.payment === 'upi') prefill.method = 'upi';
        var rzp = new window.Razorpay({
          key: rp.keyId,
          amount: rp.amount,
          currency: rp.currency,
          name: 'neemveda',
          description: 'Order ' + order.ref,
          order_id: rp.orderId,
          prefill: prefill,
          notes: { ref: order.ref },
          theme: { color: '#2F4F2F' },
          modal: {
            ondismiss: function () {
              ui('Payment was not completed. Your cart is saved, so you can try again.');
              resolve();
            }
          },
          handler: function (resp) {
            ui('Confirming your payment…');
            postJSON(API + '/payments/verify', {
              ref: order.ref,
              razorpay_order_id: resp.razorpay_order_id,
              razorpay_payment_id: resp.razorpay_payment_id,
              razorpay_signature: resp.razorpay_signature
            }).then(function () {
              showConfirmation(order, false);
            }).catch(function (err) {
              ui(err.message + ' Your order number is ' + order.ref + '.');
            }).then(resolve);
          }
        });
        rzp.on('payment.failed', function (resp) {
          ui('Payment failed: ' + ((resp.error && resp.error.description) || 'please try again') + '.');
        });
        rzp.open();
      });
    }, function () {
      ui('Could not load the payment window. Please check your connection and try again.');
    });
  }

  function initCheckout() {
    var root = $('[data-checkout-root]');
    if (!root) return;
    if (!Cart.lines().length) {
      root.innerHTML =
        '<div class="card empty"><div class="empty__icon"><svg class="icon" aria-hidden="true"><use href="#i-bag"/></svg></div>' +
        '<h2>Nothing to check out yet</h2><p class="muted">Add a pack of neem sticks to your cart to continue.</p>' +
        '<a class="btn btn-primary" href="' + u('shop.html') + '">Shop neem sticks</a></div>';
      return;
    }
    renderCheckoutSummary();
    var form = $('[data-checkout-form]');
    var status = $('[data-checkout-status]');
    if (!site.payments || !site.payments.cod) {
      var cod = $('[data-pay-cod]');
      if (cod) cod.remove();
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var ok = validateForm(form, {
        name: rules.required('full name'),
        phone: rules.phone,
        email: rules.email,
        address1: rules.required('address'),
        city: rules.required('city'),
        state: function (v) { return v ? '' : 'Please choose your state.'; },
        pincode: rules.pincode,
        payment: function (v) { return v ? '' : 'Please choose a payment method.'; }
      });
      if (!ok) { status.textContent = 'Please check the highlighted fields.'; return; }
      status.textContent = '';
      var f = form.elements;
      var customer = {
        name: f.name.value.trim(),
        phone: normalisePhone(f.phone.value.trim()),
        email: f.email.value.trim(),
        address1: f.address1.value.trim(),
        address2: f.address2.value.trim(),
        city: f.city.value.trim(),
        state: f.state.value,
        pincode: f.pincode.value.trim()
      };
      var order = { ref: orderRef(), lines: Cart.lines(), payment: f.payment.value, customer: customer };

      if (!API) { showConfirmation(order, true); return; } // no backend: demo mode

      var btn = $('[type=submit]', form);
      var ui = function (text) { status.textContent = text; btn.disabled = false; };
      btn.disabled = true;
      status.textContent = 'Placing your order…';
      postJSON(API + '/orders', {
        items: Cart.read().map(function (it) { return { id: it.id, qty: it.qty }; }),
        customer: customer,
        payment: order.payment
      }).then(function (res) {
        order.ref = res.ref;
        if (!res.razorpay) { showConfirmation(order, false); return; }
        status.textContent = 'Opening secure payment…';
        return openRazorpay(order, res.razorpay, ui);
      }).catch(function (err) {
        ui(err.message);
        var field = err.field && form.elements[err.field];
        if (field) {
          var el = field.length && !field.tagName ? field[0] : field;
          fieldError(el, err.message);
          el.focus();
        }
      });
    });
  }

  /* ---------------------------------------------------------------- Boot */
  function boot() {
    initHeader();
    initReveal();
    initQty();
    initBuyButtons();
    initGallery();
    // Clear a field's error as soon as the customer edits it.
    ['input', 'change'].forEach(function (type) {
      document.addEventListener(type, function (e) {
        var wrap = e.target.closest && e.target.closest('.field.has-error');
        if (wrap && e.target.matches('input, select, textarea')) fieldError(e.target, '');
      });
    });
    initNewsletter();
    initContact();
    initCartPage();
    initCheckout();
    updateCartBadge(false);
    document.addEventListener('cart:change', function () { updateCartBadge(true); renderCheckoutSummary(); });
    window.addEventListener('storage', function (e) { if (e.key === Cart.key) { updateCartBadge(false); renderCart(); } });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
