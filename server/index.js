#!/usr/bin/env node
/*
 * neemveda server: serves the built site (dist/) and the shop API.
 *
 *   node server/index.js           production
 *   node server/index.js --watch   also rebuilds the site when src/ changes
 *
 * Settings come from .env (see .env.example).
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const config = require('./config');
const { build } = require('../build');
const store = require('./store');
const razorpay = require('./razorpay');
const mailer = require('./mailer');
const admin = require('./admin');
const { notify, estimateFor, startScheduler } = require('./notify');
const { priceOrder, catalog } = require('./pricing');
const {
  HttpError, readJson, send, sendJson, rateLimit, clean, validateEmail, validateCustomer, requireField,
  normalisePhone, safeEqual,
} = require('./util');

/* ------------------------------------------------------------------ build */
function safeBuild() {
  try { build(); } catch (err) { console.error('[build] failed:', err.message); }
}
safeBuild();
if (process.argv.includes('--watch')) {
  let timer;
  fs.watch(path.join(config.ROOT, 'src'), { recursive: true }, () => {
    clearTimeout(timer);
    timer = setTimeout(safeBuild, 150);
  });
}

/* ------------------------------------------------------------------ orders */
function newRef() {
  const d = new Date();
  const ymd = `${String(d.getFullYear()).slice(2)}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`;
  return `NV-${ymd}-${crypto.randomBytes(3).toString('hex').toUpperCase()}`;
}

function setStatus(order, status, note) {
  order.status = status;
  order.history = order.history || [];
  order.history.push({ at: new Date().toISOString(), status, note: note || undefined });
}

function markPaid(order, paymentId, via) {
  if (['paid', 'packed', 'shipped', 'delivered'].includes(order.status)) return false; // already done
  order.razorpayPaymentId = paymentId;
  order.paidAt = new Date().toISOString();
  setStatus(order, 'paid', `via ${via}`);
  store.saveOrder(order);
  mailer.notifyOwnerNewOrder(order);
  notify(order, 'placed');
  console.log(`[order] ${order.ref} paid (${via})`);
  return true;
}

async function createOrder(req, res) {
  rateLimit(req, 'orders', 20, 10 * 60 * 1000);
  const { body } = await readJson(req);
  const { site } = catalog();
  const pricing = priceOrder(body.items);
  const customer = validateCustomer(body.customer);
  const payment = String(body.payment || '');
  if (!['upi', 'razorpay', 'cod'].includes(payment)) throw new HttpError(400, 'Please choose a payment method.', 'payment');
  if (payment === 'cod' && !(site.payments && site.payments.cod)) throw new HttpError(400, 'Cash on Delivery is not available.', 'payment');
  if (payment !== 'cod' && !razorpay.configured()) {
    throw new HttpError(503, 'Online payment is not set up yet. Please choose Cash on Delivery or order on WhatsApp.', 'payment');
  }

  const order = {
    ref: newRef(),
    createdAt: new Date().toISOString(),
    payment,
    customer,
    ...pricing,
    seen: false, // shows as NEW in the admin panel until opened
    estimate: estimateFor(Date.now()), // { from, to } delivery window shown to the customer
    history: [],
  };

  if (payment === 'cod') {
    setStatus(order, 'confirmed', 'Cash on Delivery');
    store.saveOrder(order);
    mailer.notifyOwnerNewOrder(order);
    notify(order, 'placed');
    console.log(`[order] ${order.ref} placed (COD, ₹${order.total})`);
    return sendJson(res, 201, { ref: order.ref, status: order.status, total: order.total, estimate: mailer.deliveryWindow(order) });
  }

  const rzp = await razorpay.createOrder({ amount: order.total * 100, receipt: order.ref, notes: { ref: order.ref } });
  order.razorpayOrderId = rzp.id;
  setStatus(order, 'awaiting_payment');
  store.saveOrder(order);
  console.log(`[order] ${order.ref} created, awaiting payment (₹${order.total})`);
  sendJson(res, 201, {
    ref: order.ref,
    status: order.status,
    total: order.total,
    razorpay: {
      keyId: config.razorpay.keyId,
      orderId: rzp.id,
      amount: rzp.amount,
      currency: rzp.currency,
      prefill: { name: customer.name, email: customer.email, contact: '+91' + customer.phone },
    },
  });
}

async function verifyPayment(req, res) {
  rateLimit(req, 'verify', 30, 10 * 60 * 1000);
  const { body } = await readJson(req);
  const order = store.getOrder(String(body.ref || ''));
  if (!order) throw new HttpError(404, 'Order not found.');
  if (order.razorpayOrderId !== body.razorpay_order_id) throw new HttpError(400, 'Payment does not match this order.');
  if (!razorpay.verifyPaymentSignature(body.razorpay_order_id, body.razorpay_payment_id, body.razorpay_signature)) {
    console.warn(`[order] ${order.ref} signature check FAILED`);
    throw new HttpError(400, 'We could not verify this payment. If money was deducted, please contact us with your order number.');
  }
  markPaid(order, String(body.razorpay_payment_id), 'checkout');
  sendJson(res, 200, { ref: order.ref, status: order.status });
}

/* Razorpay webhook: confirms payments even if the customer closes the browser before returning. */
async function webhook(req, res) {
  const { raw, body } = await readJson(req);
  if (!razorpay.verifyWebhookSignature(raw, req.headers['x-razorpay-signature'])) {
    throw new HttpError(400, 'Invalid signature.');
  }
  const payment = body.payload && body.payload.payment && body.payload.payment.entity;
  if (payment && ['payment.captured', 'order.paid'].includes(body.event)) {
    const order = store.findOrderByRazorpayId(payment.order_id);
    if (order && payment.amount === order.total * 100) markPaid(order, payment.id, 'webhook');
  }
  sendJson(res, 200, { ok: true });
}

/* Customer order tracking: needs the order number AND the phone number used for the order. */
const TRACK_LABEL = {
  awaiting_payment: 'Awaiting payment', confirmed: 'Order confirmed', paid: 'Payment received',
  packed: 'Packed', shipped: 'Shipped', out_for_delivery: 'Out for delivery today', delivered: 'Delivered',
  cancelled: 'Cancelled',
};
async function trackOrder(req, res) {
  rateLimit(req, 'track', 20, 10 * 60 * 1000);
  const { body } = await readJson(req);
  const ref = String(body.ref || '').trim().toUpperCase();
  const phone = normalisePhone(body.phone);
  const order = store.getOrder(ref);
  if (!order || !safeEqual(order.customer.phone, phone)) {
    throw new HttpError(404, 'We could not find an order with that order number and phone number.');
  }
  sendJson(res, 200, {
    ref: order.ref,
    createdAt: order.createdAt,
    status: order.status,
    statusLabel: TRACK_LABEL[order.status] || order.status,
    payment: mailer.PAYMENT_LABEL[order.payment] || order.payment,
    lines: order.lines.map((l) => ({ name: l.name, qty: l.qty, total: l.total })),
    subtotal: order.subtotal,
    shipping: order.shipping,
    total: order.total,
    tracking: order.tracking || '',
    expectedDelivery: order.expectedDelivery ? mailer.fmtDay(order.expectedDelivery) : '',
    estimate: !order.expectedDelivery ? mailer.deliveryWindow(order) : '',
    place: order.place || '',
    city: order.customer.city,
    history: (order.history || []).map((h) => ({
      at: h.at, status: h.status, label: TRACK_LABEL[h.status] || h.status, place: h.place || '',
    })),
  });
}

/* ------------------------------------------------------------------ forms */
async function newsletter(req, res) {
  rateLimit(req, 'newsletter', 10, 10 * 60 * 1000);
  const { body } = await readJson(req);
  store.addSubscriber(validateEmail(body.email));
  sendJson(res, 200, { ok: true });
}

async function contact(req, res) {
  rateLimit(req, 'contact', 5, 10 * 60 * 1000);
  const { body } = await readJson(req);
  const msg = {
    name: requireField(clean(body.name, 80), 'name', 'Please enter your name.'),
    email: validateEmail(body.email),
    phone: clean(body.phone, 20),
    topic: clean(body.topic, 60),
    message: requireField(clean(body.message, 4000), 'message', 'Please enter your message.'),
  };
  store.addMessage(msg);
  mailer.notifyContact(msg);
  sendJson(res, 200, { ok: true });
}

/* ------------------------------------------------------------------ static files */
const TYPES = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.ico': 'image/x-icon', '.xml': 'application/xml; charset=utf-8',
  '.txt': 'text/plain; charset=utf-8', '.webmanifest': 'application/manifest+json',
};

function serveStatic(req, res, urlPath) {
  if (urlPath.endsWith('/')) urlPath += 'index.html';
  let file = path.normalize(path.join(config.DIST, urlPath));
  if (!file.startsWith(config.DIST + path.sep)) return send(res, 403, 'Forbidden');
  if (!fs.existsSync(file) && fs.existsSync(file + '.html')) file += '.html';
  fs.readFile(file, (err, data) => {
    if (err) {
      return fs.readFile(path.join(config.DIST, '404.html'), (e, page) =>
        send(res, 404, e ? 'Not found' : page, { 'Content-Type': TYPES['.html'] }));
    }
    const ext = path.extname(file).toLowerCase();
    res.writeHead(200, {
      'Content-Type': TYPES[ext] || 'application/octet-stream',
      'Cache-Control': ext === '.html' || file.endsWith('data.js') ? 'no-cache' : 'public, max-age=3600',
    });
    res.end(req.method === 'HEAD' ? undefined : data);
  });
}

/* ------------------------------------------------------------------ router */
const API = {
  'POST /api/orders': createOrder,
  'POST /api/orders/track': trackOrder,
  'POST /api/payments/verify': verifyPayment,
  'POST /api/payments/webhook': webhook,
  'POST /api/newsletter': newsletter,
  'POST /api/contact': contact,
  'GET /api/health': (req, res) =>
    sendJson(res, 200, { ok: true, razorpay: razorpay.configured(), email: mailer.enabled() }),
};

const server = http.createServer(async (req, res) => {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
  res.setHeader('X-Frame-Options', 'SAMEORIGIN');
  let urlPath;
  try {
    urlPath = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  } catch (e) {
    return send(res, 400, 'Bad request');
  }
  try {
    const handler = API[`${req.method} ${urlPath}`];
    if (handler) return await handler(req, res);
    if (urlPath.startsWith('/api/')) throw new HttpError(404, 'Not found.');
    if (urlPath === '/admin' || urlPath.startsWith('/admin/')) return await admin.handle(req, res, urlPath, safeBuild);
    if (req.method !== 'GET' && req.method !== 'HEAD') return send(res, 405, 'Method not allowed');
    return serveStatic(req, res, urlPath);
  } catch (err) {
    if (!(err instanceof HttpError)) console.error('[server]', err);
    if (res.headersSent) return res.end();
    const status = err instanceof HttpError ? err.status : 500;
    sendJson(res, status, {
      error: err instanceof HttpError ? err.message : 'Something went wrong. Please try again.',
      field: err.field,
    });
  }
});

startScheduler();

server.listen(config.port, () => {
  console.log(`[server] http://localhost:${config.port}`);
  console.log(`[server] Razorpay: ${razorpay.configured() ? 'ready' : 'NOT configured (online payments off)'}`);
  console.log(`[server] Email: ${mailer.enabled() ? 'ready' : 'NOT configured (emails are skipped)'}`);
  console.log(`[server] Admin: ${config.admin.password ? `/admin (user "${config.admin.user}")` : 'disabled (set ADMIN_PASSWORD)'}`);
});
