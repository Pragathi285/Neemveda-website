/* neemveda OTP boxes: "Send code" -> enter 6 digits -> "Verify". A verified box stores a signed token in a hidden field. */
(function () {
  'use strict';
  if (!window.NVC_OTP) return;

  function post(action, data) {
    var body = new URLSearchParams(Object.assign({ action: action, nonce: NVC_OTP.nonce }, data));
    return fetch(NVC_OTP.ajax, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); })
      .catch(function () { return { success: false, data: { message: 'Could not connect. Please check your internet and try again.' } }; });
  }

  function bind(box) {
    if (box.dataset.bound) return;
    box.dataset.bound = '1';
    var input = document.querySelector(box.dataset.input);
    var sendBtn = box.querySelector('[data-otp-send]');
    var verifyBtn = box.querySelector('[data-otp-verify]');
    var codeEl = box.querySelector('[data-otp-code]');
    var msg = box.querySelector('[data-otp-msg]');
    var token = box.querySelector('[data-otp-token]');
    var verifiedFor = null;
    var timer = null;

    function say(text, kind) { msg.textContent = text || ''; msg.className = 'nv-otp__msg' + (kind ? ' is-' + kind : ''); }
    function value() { return input ? input.value.trim() : ''; }
    function cooldown(sec) {
      clearInterval(timer);
      var left = sec;
      sendBtn.disabled = true;
      sendBtn.textContent = 'Resend in ' + left + 's';
      timer = setInterval(function () {
        left -= 1;
        if (left <= 0) { clearInterval(timer); sendBtn.disabled = false; sendBtn.textContent = 'Resend code'; }
        else sendBtn.textContent = 'Resend in ' + left + 's';
      }, 1000);
    }
    function setVerified(on) {
      box.classList.toggle('is-verified', on);
      if (!on) { token.value = ''; verifiedFor = null; }
    }

    // A pre-verified phone (checkout) hides the box while the number matches.
    var wrap = box.closest('[data-nv-phone-otp-wrap]');
    function syncWrap() {
      if (!wrap) return;
      var digits = value().replace(/\D/g, '').slice(-10);
      wrap.hidden = !!wrap.dataset.verified && digits === wrap.dataset.verified;
    }

    if (input) {
      input.addEventListener('input', function () {
        if (verifiedFor !== null && value() !== verifiedFor) { setVerified(false); say('Changed: please verify again.', 'err'); }
        syncWrap();
      });
      syncWrap();
    }
    if (token.value) { box.classList.add('is-verified'); verifiedFor = value(); say('Verified ✓', 'ok'); }

    sendBtn.addEventListener('click', function () {
      if (!value()) { say(box.dataset.channel === 'email' ? 'Enter your email above first.' : 'Enter your mobile number above first.', 'err'); if (input) input.focus(); return; }
      sendBtn.disabled = true;
      say('Sending…');
      post('nvc_otp_send', { channel: box.dataset.channel, value: value(), purpose: box.dataset.purpose }).then(function (res) {
        if (res.success) { say(res.data.message, 'ok'); cooldown(60); codeEl.focus(); }
        else { say(res.data && res.data.message, 'err'); if (res.data && res.data.wait) cooldown(res.data.wait); else sendBtn.disabled = false; }
      });
    });

    verifyBtn.addEventListener('click', function () {
      var code = codeEl.value.replace(/\D/g, '');
      if (code.length !== 6) { say('Enter the 6-digit code.', 'err'); codeEl.focus(); return; }
      verifyBtn.disabled = true;
      post('nvc_otp_verify', { channel: box.dataset.channel, value: value(), code: code }).then(function (res) {
        verifyBtn.disabled = false;
        if (res.success) { token.value = res.data.token; verifiedFor = value(); setVerified(true); say(res.data.message, 'ok'); }
        else say(res.data && res.data.message, 'err');
      });
    });
    codeEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); verifyBtn.click(); } });
  }

  function scan() { Array.prototype.forEach.call(document.querySelectorAll('[data-nv-otp]'), bind); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan); else scan();
  // WooCommerce re-renders parts of checkout.
  if (window.jQuery) window.jQuery(document.body).on('updated_checkout', scan);
})();
