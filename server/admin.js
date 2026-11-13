/*
 * /admin: password-protected page to see orders, subscribers and messages,
 * update order status (sends a "shipped" email), and download CSV files.
 * Login: ADMIN_USER / ADMIN_PASSWORD from .env.
 */
const querystring = require('querystring');
const config = require('./config');
const store = require('./store');
const mailer = require('./mailer');
const { readBody, send, safeEqual, escapeHtml: e, rupees, clean, HttpError, rateLimit } = require('./util');

const STATUSES = ['awaiting_payment', 'confirmed', 'paid', 'shipped', 'delivered', 'cancelled'];
const STATUS_LABEL = {
  awaiting_payment: 'Awaiting payment', confirmed: 'Confirmed (COD)', paid: 'Paid',
  shipped: 'Shipped', delivered: 'Delivered', cancelled: 'Cancelled',
};

function authorised(req) {
  const header = req.headers.authorization || '';
  if (!header.startsWith('Basic ')) return false;
  const [user, ...rest] = Buffer.from(header.slice(6), 'base64').toString('utf8').split(':');
  return safeEqual(user, config.admin.user) && safeEqual(rest.join(':'), config.admin.password);
}

const fmtDate = (iso) =>
  iso ? new Date(iso).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', dateStyle: 'medium', timeStyle: 'short' }) : '';

function csvCell(v) {
  let s = String(v == null ? '' : v);
  if (/^[=+\-@]/.test(s)) s = "'" + s; // stop spreadsheet formula injection
  return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}
const csv = (rows) => '﻿' + rows.map((r) => r.map(csvCell).join(',')).join('\r\n');

function page(title, nav, body) {
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>${e(title)} · neemveda admin</title><link rel="icon" href="/assets/img/favicon.svg">
<style>
body{margin:0;font:15px/1.5 system-ui,Segoe UI,sans-serif;background:#F7F1E4;color:#231F20}
header{background:#2F4F2F;color:#fff;padding:14px 20px;display:flex;flex-wrap:wrap;gap:12px 24px;align-items:center}
header strong{font-size:20px} header a{color:#dbe4d0;text-decoration:none;padding:4px 0;border-bottom:2px solid transparent}
header a.on{color:#fff;border-color:#fff} main{padding:20px;max-width:1300px;margin:auto}
.bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:14px}
.btn{display:inline-block;background:#2F4F2F;color:#fff;border:0;border-radius:8px;padding:7px 12px;text-decoration:none;cursor:pointer;font:inherit;font-size:13px}
.cards{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:18px}
.card{background:#fff;border-radius:12px;padding:14px 16px;border:1px solid rgba(47,79,47,.15)} .card b{display:block;font-size:22px;color:#2F4F2F}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;font-size:14px}
th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;vertical-align:top} th{background:#efe5d0;font-size:12px;text-transform:uppercase;letter-spacing:.06em}
.st{display:inline-block;padding:2px 8px;border-radius:99px;font-size:12px;background:#eee}
.st-paid,.st-confirmed{background:#dfe9d3;color:#2F4F2F}.st-shipped{background:#d8e6ef}.st-delivered{background:#2F4F2F;color:#fff}
.st-cancelled{background:#f3dcd5}.st-awaiting_payment{background:#f6e7c4}
form.inline{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px} select,input{font:inherit;font-size:13px;padding:5px 6px;border:1px solid #ccc;border-radius:6px}
.muted{color:#666;font-size:13px} .wrap{overflow-x:auto}
</style></head><body><header><strong>neemveda admin</strong>${nav}</header><main>${body}</main></body></html>`;
}

function navHtml(view) {
  const link = (v, label) => `<a href="/admin?view=${v}"${v === view ? ' class="on"' : ''}>${label}</a>`;
  return link('orders', 'Orders') + link('subscribers', 'Newsletter') + link('messages', 'Messages') + '<a href="/" target="_blank">View shop ↗</a>';
}

function ordersView(filter) {
  const all = store.listOrders();
  const orders = filter ? all.filter((o) => o.status === filter) : all;
  const counted = (s) => all.filter((o) => o.status === s).length;
  const revenue = all.filter((o) => ['paid', 'shipped', 'delivered'].includes(o.status)).reduce((s, o) => s + o.total, 0);
  const toShip = all.filter((o) => ['paid', 'confirmed'].includes(o.status)).length;

  const rows = orders.map((o) => {
    const c = o.customer;
    const options = STATUSES.map((s) => `<option value="${s}"${s === o.status ? ' selected' : ''}>${STATUS_LABEL[s]}</option>`).join('');
    return `<tr>
<td><strong>${e(o.ref)}</strong><div class="muted">${e(fmtDate(o.createdAt))}</div></td>
<td>${e(c.name)}<div class="muted"><a href="tel:+91${e(c.phone)}">${e(c.phone)}</a> · <a href="https://wa.me/91${e(c.phone)}" target="_blank" rel="noopener">WhatsApp</a><br>${e(c.email)}</div></td>
<td class="muted">${e(c.address1)}${c.address2 ? ', ' + e(c.address2) : ''}<br>${e(c.city)}, ${e(c.state)} – ${e(c.pincode)}</td>
<td>${o.lines.map((l) => `${l.qty} × ${e(l.name)}`).join('<br>')}</td>
<td><strong>${rupees(o.total)}</strong><div class="muted">${e(mailer.PAYMENT_LABEL[o.payment] || o.payment)}${o.razorpayPaymentId ? '<br>' + e(o.razorpayPaymentId) : ''}</div></td>
<td><span class="st st-${e(o.status)}">${e(STATUS_LABEL[o.status] || o.status)}</span>
<form class="inline" method="post" action="/admin/orders/status">
<input type="hidden" name="ref" value="${e(o.ref)}"><select name="status" aria-label="Status for ${e(o.ref)}">${options}</select>
<input name="tracking" placeholder="Tracking (optional)" value="${e(o.tracking || '')}" aria-label="Tracking for ${e(o.ref)}" size="14">
<button class="btn" type="submit">Update</button></form></td></tr>`;
  }).join('');

  const filters = ['', ...STATUSES].map((s) =>
    `<option value="${s}"${s === (filter || '') ? ' selected' : ''}>${s ? STATUS_LABEL[s] : 'All orders'}</option>`).join('');

  return `<div class="cards">
<div class="card"><b>${all.length}</b>Total orders</div>
<div class="card"><b>${toShip}</b>Ready to ship</div>
<div class="card"><b>${counted('awaiting_payment')}</b>Awaiting payment</div>
<div class="card"><b>${rupees(revenue)}</b>Paid revenue</div></div>
<div class="bar"><form method="get" action="/admin"><input type="hidden" name="view" value="orders">
<select name="status" onchange="this.form.submit()" aria-label="Filter by status">${filters}</select></form>
<a class="btn" href="/admin/orders.csv">Download CSV</a></div>
<div class="wrap"><table><thead><tr><th>Order</th><th>Customer</th><th>Address</th><th>Items</th><th>Total</th><th>Status</th></tr></thead>
<tbody>${rows || '<tr><td colspan="6" class="muted">No orders yet.</td></tr>'}</tbody></table></div>
<p class="muted">Setting an order to "Shipped" emails the customer (with the tracking text, if any).</p>`;
}

function subscribersView() {
  const subs = store.listSubscribers();
  return `<div class="bar"><span>${subs.length} subscribers</span><a class="btn" href="/admin/subscribers.csv">Download CSV</a></div>
<div class="wrap"><table><thead><tr><th>Email</th><th>Joined</th></tr></thead><tbody>${
    subs.map((s) => `<tr><td>${e(s.email)}</td><td class="muted">${e(fmtDate(s.at))}</td></tr>`).join('') ||
    '<tr><td colspan="2" class="muted">No subscribers yet.</td></tr>'}</tbody></table></div>`;
}

function messagesView() {
  const msgs = store.listMessages();
  return `<div class="wrap"><table><thead><tr><th>From</th><th>Topic</th><th>Message</th><th>Received</th></tr></thead><tbody>${
    msgs.map((m) => `<tr><td>${e(m.name)}<div class="muted"><a href="mailto:${e(m.email)}">${e(m.email)}</a>${m.phone ? '<br>' + e(m.phone) : ''}</div></td>
<td>${e(m.topic || '-')}</td><td style="white-space:pre-wrap">${e(m.message)}</td><td class="muted">${e(fmtDate(m.at))}</td></tr>`).join('') ||
    '<tr><td colspan="4" class="muted">No messages yet.</td></tr>'}</tbody></table></div>`;
}

async function updateStatus(req, res) {
  // Same-origin check: blocks other websites from submitting this form with your saved login.
  let originHost = '';
  try { originHost = new URL(req.headers.origin || req.headers.referer || '').host; } catch (err) { /* missing or malformed */ }
  if (!originHost || originHost !== req.headers.host) throw new HttpError(403, 'Forbidden');
  const form = querystring.parse(await readBody(req));
  const order = store.getOrder(String(form.ref || ''));
  const status = String(form.status || '');
  if (!order || !STATUSES.includes(status)) throw new HttpError(400, 'Invalid update.');
  const tracking = clean(form.tracking, 200);
  const becameShipped = status === 'shipped' && order.status !== 'shipped';
  if (status !== order.status) {
    order.status = status;
    order.history = order.history || [];
    order.history.push({ at: new Date().toISOString(), status, note: 'admin' });
  }
  order.tracking = tracking;
  store.saveOrder(order);
  if (becameShipped) mailer.notifyShipped(order);
  res.writeHead(303, { Location: '/admin?view=orders' });
  res.end();
}

async function handle(req, res, urlPath) {
  if (!config.admin.password) {
    return send(res, 503, 'Admin is disabled. Set ADMIN_PASSWORD in .env and restart the server.', { 'Content-Type': 'text/plain; charset=utf-8' });
  }
  if (!authorised(req)) {
    rateLimit(req, 'admin-login', 30, 10 * 60 * 1000);
    return send(res, 401, 'Login required', { 'WWW-Authenticate': 'Basic realm="neemveda admin", charset="UTF-8"' });
  }
  const headers = { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' };

  if (req.method === 'POST' && urlPath === '/admin/orders/status') return updateStatus(req, res);

  if (urlPath === '/admin/orders.csv') {
    const rows = [['Order', 'Date', 'Status', 'Payment', 'Payment ID', 'Name', 'Phone', 'Email', 'Address', 'City', 'State', 'PIN', 'Items', 'Subtotal', 'Shipping', 'Total', 'Tracking']];
    for (const o of store.listOrders()) {
      const c = o.customer;
      rows.push([o.ref, fmtDate(o.createdAt), o.status, o.payment, o.razorpayPaymentId || '', c.name, c.phone, c.email,
        [c.address1, c.address2].filter(Boolean).join(', '), c.city, c.state, c.pincode,
        o.lines.map((l) => `${l.qty} x ${l.name}`).join('; '), o.subtotal, o.shipping, o.total, o.tracking || '']);
    }
    return send(res, 200, csv(rows), { 'Content-Type': 'text/csv; charset=utf-8', 'Content-Disposition': 'attachment; filename="neemveda-orders.csv"', 'Cache-Control': 'no-store' });
  }
  if (urlPath === '/admin/subscribers.csv') {
    const rows = [['Email', 'Joined'], ...store.listSubscribers().map((s) => [s.email, fmtDate(s.at)])];
    return send(res, 200, csv(rows), { 'Content-Type': 'text/csv; charset=utf-8', 'Content-Disposition': 'attachment; filename="neemveda-subscribers.csv"', 'Cache-Control': 'no-store' });
  }
  if (urlPath !== '/admin' && urlPath !== '/admin/') return send(res, 404, 'Not found');

  const q = new URL(req.url, 'http://x').searchParams;
  const view = ['orders', 'subscribers', 'messages'].includes(q.get('view')) ? q.get('view') : 'orders';
  const filter = STATUSES.includes(q.get('status')) ? q.get('status') : '';
  const body = view === 'subscribers' ? subscribersView() : view === 'messages' ? messagesView() : ordersView(filter);
  send(res, 200, page(view[0].toUpperCase() + view.slice(1), navHtml(view), body), headers);
}

module.exports = { handle };
