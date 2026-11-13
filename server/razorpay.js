/*
 * Minimal Razorpay client (Orders API + signature checks). No SDK needed.
 * Docs: https://razorpay.com/docs/payments/server-integration/
 */
const https = require('https');
const crypto = require('crypto');
const { razorpay } = require('./config');
const { safeEqual, HttpError } = require('./util');

const configured = () => Boolean(razorpay.keyId && razorpay.keySecret);

function api(method, path, body) {
  return new Promise((resolve, reject) => {
    const payload = body ? JSON.stringify(body) : '';
    const req = https.request(
      {
        host: 'api.razorpay.com',
        path,
        method,
        auth: `${razorpay.keyId}:${razorpay.keySecret}`,
        headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload) },
        timeout: 15000,
      },
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => {
          let json = {};
          try { json = JSON.parse(data || '{}'); } catch (e) { /* ignore */ }
          if (res.statusCode >= 400) {
            const msg = (json.error && json.error.description) || `Razorpay error ${res.statusCode}`;
            console.error('[razorpay]', res.statusCode, msg);
            reject(new HttpError(502, 'We could not start the payment. Please try again in a moment.'));
          } else resolve(json);
        });
      }
    );
    req.on('timeout', () => req.destroy(new Error('Razorpay request timed out')));
    req.on('error', (err) => {
      console.error('[razorpay]', err.message);
      reject(new HttpError(502, 'We could not reach the payment gateway. Please try again in a moment.'));
    });
    req.end(payload);
  });
}

/** amount is in paise (₹1 = 100 paise) */
const createOrder = ({ amount, receipt, notes }) =>
  api('POST', '/v1/orders', { amount, currency: 'INR', receipt, notes });

const hmac = (secret, data) => crypto.createHmac('sha256', secret).update(data).digest('hex');

const verifyPaymentSignature = (orderId, paymentId, signature) =>
  Boolean(signature) && safeEqual(hmac(razorpay.keySecret, `${orderId}|${paymentId}`), signature);

const verifyWebhookSignature = (rawBody, signature) =>
  Boolean(razorpay.webhookSecret && signature) && safeEqual(hmac(razorpay.webhookSecret, rawBody), signature);

module.exports = { configured, createOrder, verifyPaymentSignature, verifyWebhookSignature };
