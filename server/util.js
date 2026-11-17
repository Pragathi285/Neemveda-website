/* Shared HTTP helpers and validation. */
const crypto = require('crypto');
const { trustProxy } = require('./config');

class HttpError extends Error {
  constructor(status, message, field) {
    super(message);
    this.status = status;
    this.field = field;
  }
}

function readBody(req, limit = 32 * 1024) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (c) => {
      size += c.length;
      if (size > limit) {
        reject(new HttpError(413, 'Request too large.'));
        req.destroy();
      } else chunks.push(c);
    });
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

async function readJson(req) {
  const raw = await readBody(req);
  try {
    return { raw, body: JSON.parse(raw || '{}') };
  } catch (e) {
    throw new HttpError(400, 'Invalid request.');
  }
}

function send(res, status, body, headers = {}) {
  res.writeHead(status, headers);
  res.end(body);
}
function sendJson(res, status, data) {
  send(res, status, JSON.stringify(data), { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
}

function clientIp(req) {
  if (trustProxy && req.headers['x-forwarded-for']) return req.headers['x-forwarded-for'].split(',')[0].trim();
  return req.socket.remoteAddress || 'unknown';
}

/* Simple fixed-window rate limiter per IP. */
const hits = new Map();
function rateLimit(req, key, max, windowMs) {
  const id = key + ':' + clientIp(req);
  const now = Date.now();
  const entry = hits.get(id);
  if (!entry || entry.reset < now) {
    hits.set(id, { count: 1, reset: now + windowMs });
    return;
  }
  entry.count += 1;
  if (entry.count > max) throw new HttpError(429, 'Too many requests. Please wait a few minutes and try again.');
}
setInterval(() => {
  const now = Date.now();
  for (const [k, v] of hits) if (v.reset < now) hits.delete(k);
}, 60 * 1000).unref();

function safeEqual(a, b) {
  const x = Buffer.from(String(a));
  const y = Buffer.from(String(b));
  return x.length === y.length && crypto.timingSafeEqual(x, y);
}

const clean = (v, max) => String(v == null ? '' : v).replace(/[\u0000-\u001f\u007f]/g, ' ').trim().slice(0, max);
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const normalisePhone = (v) => String(v || '').replace(/[\s-]/g, '').replace(/^(\+91|91|0)(?=\d{10}$)/, '');

function requireField(value, field, message) {
  if (!value) throw new HttpError(400, message, field);
  return value;
}

function validateEmail(value, field = 'email') {
  const email = clean(value, 120).toLowerCase();
  if (!EMAIL_RE.test(email)) throw new HttpError(400, 'Please enter a valid email address.', field);
  return email;
}

function validateCustomer(c) {
  c = c || {};
  const phone = normalisePhone(c.phone);
  if (!/^[6-9]\d{9}$/.test(phone)) throw new HttpError(400, 'Please enter a valid 10-digit Indian mobile number.', 'phone');
  const pincode = clean(c.pincode, 6);
  if (!/^[1-9]\d{5}$/.test(pincode)) throw new HttpError(400, 'Please enter a valid 6-digit PIN code.', 'pincode');
  return {
    name: requireField(clean(c.name, 80), 'name', 'Please enter your full name.'),
    phone,
    email: validateEmail(c.email),
    address1: requireField(clean(c.address1, 200), 'address1', 'Please enter your address.'),
    address2: clean(c.address2, 200),
    city: requireField(clean(c.city, 80), 'city', 'Please enter your city.'),
    state: requireField(clean(c.state, 60), 'state', 'Please choose your state.'),
    pincode,
  };
}

const escapeHtml = (v) =>
  String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const rupees = (n) => '₹' + Number(n).toLocaleString('en-IN');

module.exports = {
  HttpError, readBody, readJson, send, sendJson, clientIp, rateLimit, safeEqual,
  clean, validateEmail, validateCustomer, requireField, escapeHtml, rupees, normalisePhone,
};
