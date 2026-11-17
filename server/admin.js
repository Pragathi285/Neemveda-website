/*
 * /admin: the shop owner's panel.
 *   - Login page with a signed session cookie (ADMIN_USER / ADMIN_PASSWORD from .env).
 *   - Orders: live "new order" alerts (sound + notification), search, filters, order page,
 *     status updates (Shipped emails the customer), notes, WhatsApp shortcuts, packing slip.
 *   - Products: change price, MRP, stock and badge without editing code.
 *   - Newsletter subscribers and contact messages, CSV downloads.
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const querystring = require('querystring');
const config = require('./config');
const store = require('./store');
const mailer = require('./mailer');
const catalog = require('./catalog');
const razorpay = require('./razorpay');
const { readBody, send, sendJson, safeEqual, escapeHtml: e, rupees, clean, HttpError, rateLimit } = require('./util');

const { notify, istDate } = require('./notify');

const STATUSES = ['awaiting_payment', 'confirmed', 'paid', 'packed', 'shipped', 'out_for_delivery', 'delivered', 'cancelled'];
const STATUS_LABEL = {
  awaiting_payment: 'Awaiting payment', confirmed: 'Confirmed (COD)', paid: 'Paid online', packed: 'Packed',
  shipped: 'Shipped', out_for_delivery: 'Out for delivery', delivered: 'Delivered', cancelled: 'Cancelled',
};
// Status changes that email the customer (when "Email the customer" is ticked).
const STATUS_EMAIL = { packed: 'packed', shipped: 'shipped', out_for_delivery: 'out_for_delivery', delivered: 'delivered', cancelled: 'cancelled' };
const TO_SHIP = ['confirmed', 'paid', 'packed'];

/* ------------------------------------------------------------------ session */
const COOKIE = 'nv_admin';
const SESSION_DAYS = 7;
const SECRET_FILE = path.join(config.dataDir, '.admin-secret');

function secret() {
  if (!fs.existsSync(SECRET_FILE)) {
    fs.mkdirSync(config.dataDir, { recursive: true });
    fs.writeFileSync(SECRET_FILE, crypto.randomBytes(32).toString('hex'), { mode: 0o600 });
  }
  // The password is part of the key, so changing ADMIN_PASSWORD logs everyone out.
  return fs.readFileSync(SECRET_FILE, 'utf8').trim() + '|' + config.admin.password;
}
const sign = (payload) => crypto.createHmac('sha256', secret()).update(payload).digest('base64url');

function makeToken(user) {
  const payload = Buffer.from(JSON.stringify({ u: user, exp: Date.now() + SESSION_DAYS * 864e5 })).toString('base64url');
  return payload + '.' + sign(payload);
}

function readCookie(req, name) {
  for (const part of String(req.headers.cookie || '').split(';')) {
    const i = part.indexOf('=');
    if (i > 0 && part.slice(0, i).trim() === name) return part.slice(i + 1).trim();
  }
  return '';
}

function sessionUser(req) {
  const [payload, sig] = readCookie(req, COOKIE).split('.');
  if (!payload || !sig || !safeEqual(sig, sign(payload))) return null;
  try {
    const data = JSON.parse(Buffer.from(payload, 'base64url').toString('utf8'));
    return data.exp > Date.now() && data.u === config.admin.user ? data.u : null;
  } catch (err) {
    return null;
  }
}

function isHttps(req) {
  return Boolean(req.socket.encrypted) || (config.trustProxy && req.headers['x-forwarded-proto'] === 'https');
}

function cookieHeader(req, value, maxAge) {
  return `${COOKIE}=${value}; Path=/admin; HttpOnly; SameSite=Strict; Max-Age=${maxAge}${isHttps(req) ? '; Secure' : ''}`;
}

// Blocks other websites from submitting admin forms with the owner's session.
function requireSameOrigin(req) {
  let originHost = '';
  try { originHost = new URL(req.headers.origin || req.headers.referer || '').host; } catch (err) { /* missing */ }
  if (!originHost || originHost !== req.headers.host) throw new HttpError(403, 'Forbidden');
}

const redirect = (res, location, headers = {}) => { res.writeHead(303, { Location: location, ...headers }); res.end(); };

/* ------------------------------------------------------------------ helpers */
const fmtDate = (iso) =>
  iso ? new Date(iso).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', dateStyle: 'medium', timeStyle: 'short' }) : '';
const istDay = (iso) => new Date(iso).toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' });

function csvCell(v) {
  let s = String(v == null ? '' : v);
  if (/^[=+\-@]/.test(s)) s = "'" + s; // stop spreadsheet formula injection
  return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}
const csv = (rows) => '﻿' + rows.map((r) => r.map(csvCell).join(',')).join('\r\n');

const statusPill = (s) => `<span class="st st-${e(s)}">${e(STATUS_LABEL[s] || s)}</span>`;
const waLink = (phone, text) => `https://wa.me/91${encodeURIComponent(phone)}?text=${encodeURIComponent(text)}`;
const payLabel = (o) => mailer.PAYMENT_LABEL[o.payment] || o.payment;

function stats(all) {
  const today = istDay(new Date().toISOString());
  const received = (o) => (o.payment === 'cod' ? o.status === 'delivered' : ['paid', 'packed', 'shipped', 'out_for_delivery', 'delivered'].includes(o.status));
  return {
    total: all.length,
    today: all.filter((o) => istDay(o.createdAt) === today).length,
    unseen: all.filter((o) => o.seen === false).length,
    toShip: all.filter((o) => TO_SHIP.includes(o.status)).length,
    codDue: all.filter((o) => o.payment === 'cod' && ['confirmed', 'packed', 'shipped', 'out_for_delivery'].includes(o.status)).reduce((s, o) => s + o.total, 0),
    revenue: all.filter(received).reduce((s, o) => s + o.total, 0),
  };
}

/* ------------------------------------------------------------------ layout */
const CSS = `
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#F7F1E4;color:#231F20}
a{color:#2F4F2F}header{background:#2F4F2F;color:#fff;padding:12px 20px;display:flex;flex-wrap:wrap;gap:10px 22px;align-items:center;position:sticky;top:0;z-index:5}
header strong{font-size:19px;margin-right:6px}header a{color:#dbe4d0;text-decoration:none;padding:3px 0;border-bottom:2px solid transparent}
header a.on{color:#fff;border-color:#fff}header form{margin-left:auto}header .linkbtn{background:none;border:1px solid rgba(255,255,255,.4);color:#fff;border-radius:8px;padding:4px 10px;cursor:pointer;font:inherit;font-size:13px}
.badge{display:inline-block;min-width:20px;padding:0 6px;border-radius:99px;background:#E0A93B;color:#231F20;font-size:12px;font-weight:700;text-align:center;margin-left:4px}
.badge[hidden]{display:none}
main{padding:20px;max-width:1300px;margin:auto}h1{font-size:22px;margin:0 0 14px;color:#2F4F2F}h2{font-size:17px;margin:0 0 10px;color:#2F4F2F}
.bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:14px}.bar form{display:flex;flex-wrap:wrap;gap:8px}
.btn{display:inline-flex;align-items:center;gap:6px;background:#2F4F2F;color:#fff;border:0;border-radius:8px;padding:8px 13px;text-decoration:none;cursor:pointer;font:inherit;font-size:14px}
.btn.alt{background:#fff;color:#2F4F2F;border:1px solid #2F4F2F}.btn.wa{background:#1f8f4e}.btn.sm{padding:5px 10px;font-size:13px}
.cards{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin-bottom:18px}
.card{background:#fff;border-radius:12px;padding:14px 16px;border:1px solid rgba(47,79,47,.15)}.card b{display:block;font-size:22px;color:#2F4F2F}
.panel{background:#fff;border-radius:12px;padding:18px;border:1px solid rgba(47,79,47,.15);margin-bottom:16px}
.grid2{display:grid;gap:16px;grid-template-columns:1fr}@media(min-width:900px){.grid2{grid-template-columns:1.4fr 1fr}}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;font-size:14px}
th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}th{background:#efe5d0;font-size:12px;text-transform:uppercase;letter-spacing:.06em}
tr.new td{background:#fff8e6}tr.new td:first-child{box-shadow:inset 4px 0 0 #E0A93B}
.st{display:inline-block;padding:2px 9px;border-radius:99px;font-size:12px;background:#eee;white-space:nowrap}
.st-paid,.st-confirmed{background:#dfe9d3;color:#2F4F2F}.st-packed{background:#efe0f5}.st-shipped{background:#d8e6ef}.st-delivered{background:#2F4F2F;color:#fff}
.st-out_for_delivery{background:#fde3c8}.st-cancelled{background:#f3dcd5}
.places{display:flex;flex-wrap:wrap;gap:6px}.places span{background:#fff;border:1px solid rgba(47,79,47,.2);border-radius:99px;padding:3px 10px;font-size:13px}
.ok{color:#2F4F2F}.no{color:#a33}.check{display:flex;gap:8px;align-items:center;font-size:14px;color:#231F20;margin:4px 0 12px}.st-awaiting_payment{background:#f6e7c4}.newtag{background:#E0A93B;color:#231F20;font-size:11px;font-weight:700;border-radius:4px;padding:1px 5px;margin-left:6px}
select,input,textarea{font:inherit;font-size:14px;padding:7px 9px;border:1px solid #c9c2b2;border-radius:8px;background:#fff}textarea{width:100%;min-height:70px}
label{font-size:13px;color:#555}.field{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
.muted{color:#666;font-size:13px}.wrap{overflow-x:auto}.row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.flash{background:#dfe9d3;border:1px solid #9fbd8c;padding:10px 14px;border-radius:10px;margin-bottom:14px}.err{background:#f3dcd5;border-color:#d99}
#alert{display:none;position:fixed;right:16px;bottom:16px;z-index:20;background:#231F20;color:#fff;padding:14px 16px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.3);max-width:340px}
#alert a{color:#ffd77a;font-weight:700}#alert button{margin-left:8px;background:none;border:0;color:#aaa;cursor:pointer;font-size:18px}
.timeline{list-style:none;padding:0;margin:0}.timeline li{padding:6px 0 6px 16px;border-left:2px solid #dfe9d3;position:relative}
.timeline li:before{content:"";position:absolute;left:-6px;top:12px;width:10px;height:10px;border-radius:50%;background:#2F4F2F}
.login{max-width:380px;margin:12vh auto;background:#fff;border-radius:16px;padding:28px;border:1px solid rgba(47,79,47,.15)}
.login input{width:100%}.slip{display:none}
@media print{header,.no-print,#alert{display:none!important}body{background:#fff}main{padding:0}.slip{display:block}}
`;

/* Polls for new orders every 10 seconds; plays a chime and shows a notification when one arrives. */
const LIVE_SCRIPT = `<div id="alert" role="alert"></div>
<script>
(function(){
  var known=null, base=document.title, ctx=null;
  function esc(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function chime(){try{ctx=ctx||new (window.AudioContext||window.webkitAudioContext)();[0,0.18,0.36].forEach(function(t,i){var o=ctx.createOscillator(),g=ctx.createGain();o.frequency.value=[660,880,1100][i];o.connect(g);g.connect(ctx.destination);g.gain.setValueAtTime(0.0001,ctx.currentTime+t);g.gain.exponentialRampToValueAtTime(0.25,ctx.currentTime+t+0.02);g.gain.exponentialRampToValueAtTime(0.0001,ctx.currentTime+t+0.3);o.start(ctx.currentTime+t);o.stop(ctx.currentTime+t+0.32);});}catch(e){}}
  function show(o){var box=document.getElementById('alert');box.innerHTML='🛒 New order <a href="/admin/order?ref='+encodeURIComponent(o.ref)+'">'+esc(o.ref)+'</a><br>'+esc(o.name)+' · ₹'+esc(o.total)+' · '+esc(o.payment)+'<button type="button" aria-label="Close">×</button>';box.querySelector('button').onclick=function(){box.style.display='none';};box.style.display='block';}
  function notify(o){if(window.Notification&&Notification.permission==='granted'){try{var n=new Notification('New neemveda order '+o.ref,{body:o.name+' · ₹'+o.total+' · '+o.payment,icon:'/assets/img/logo-512.png',tag:o.ref});n.onclick=function(){window.focus();location.href='/admin/order?ref='+encodeURIComponent(o.ref);};}catch(e){}}}
  function poll(){fetch('/admin/api/poll',{credentials:'same-origin',cache:'no-store'}).then(function(r){if(r.status===401){location.href='/admin/login';throw 0;}return r.json();}).then(function(d){
    var badge=document.getElementById('nav-new');if(badge){badge.textContent=d.unseen;badge.hidden=!d.unseen;}
    document.title=(d.unseen?'('+d.unseen+') ':'')+base;
    var refs=d.latest.map(function(o){return o.ref;});
    if(known){var fresh=d.latest.filter(function(o){return known.indexOf(o.ref)<0;});
      if(fresh.length){chime();show(fresh[0]);fresh.forEach(notify);if(document.querySelector('[data-live-refresh]'))setTimeout(function(){try{sessionStorage.setItem('nv-alert',JSON.stringify(fresh[0]));}catch(e){}location.reload();},3000);}}
    known=refs;}).catch(function(){});}
  var btn=document.getElementById('enable-alerts');
  if(btn){if(window.Notification&&Notification.permission==='granted')btn.textContent='🔔 Alerts on';
    btn.addEventListener('click',function(){chime();if(window.Notification&&Notification.permission!=='granted')Notification.requestPermission().then(function(p){btn.textContent=p==='granted'?'🔔 Alerts on':'🔔 Sound only (notifications blocked)';});else btn.textContent='🔔 Alerts on';});}
  try{var saved=sessionStorage.getItem('nv-alert');if(saved){sessionStorage.removeItem('nv-alert');show(JSON.parse(saved));}}catch(e){}
  poll();setInterval(poll,10000);
})();
</script>`;

function page(title, nav, body, opts = {}) {
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>${e(title)} · neemveda admin</title><link rel="icon" href="/assets/img/favicon.svg">
<style>${CSS}</style></head><body>${nav ? `<header>${nav}</header>` : ''}<main>${body}</main>
${opts.live ? LIVE_SCRIPT : ''}</body></html>`;
}

function navHtml(view, unseen) {
  const link = (v, label, extra = '') => `<a href="/admin?view=${v}"${v === view ? ' class="on"' : ''}>${label}${extra}</a>`;
  return '<strong>neemveda admin</strong>' +
    link('orders', 'Orders', `<span class="badge" id="nav-new"${unseen ? '' : ' hidden'}>${unseen}</span>`) +
    link('products', 'Products') + link('subscribers', 'Newsletter') + link('messages', 'Messages') +
    '<a href="/" target="_blank" rel="noopener">View shop ↗</a>' +
    '<form method="post" action="/admin/logout"><button class="linkbtn" type="submit">Log out</button></form>';
}

/* ------------------------------------------------------------------ views */
function loginPage(error) {
  return page('Log in', '', `<form class="login" method="post" action="/admin/login">
<h1>neemveda admin</h1>${error ? `<p class="flash err">${e(error)}</p>` : ''}
<div class="field"><label for="u">User ID</label><input id="u" name="user" autocomplete="username" required autofocus></div>
<div class="field"><label for="p">Password</label><input id="p" name="password" type="password" autocomplete="current-password" required></div>
<button class="btn" type="submit" style="width:100%;justify-content:center">Log in</button></form>`);
}

function ordersView(q) {
  const all = store.listOrders();
  const s = stats(all);
  const raw = q.get('status') || '';
  const filter = STATUSES.includes(raw) || raw === 'new' ? raw : '';
  const term = clean(q.get('q'), 60).toLowerCase();
  let orders = all;
  if (filter === 'new') orders = orders.filter((o) => o.seen === false);
  else if (filter) orders = orders.filter((o) => o.status === filter);
  if (term) {
    orders = orders.filter((o) => [o.ref, o.customer.name, o.customer.phone, o.customer.email, o.customer.city, o.customer.pincode]
      .join(' ').toLowerCase().includes(term));
  }

  const rows = orders.map((o) => {
    const c = o.customer;
    const href = `/admin/order?ref=${encodeURIComponent(o.ref)}`;
    return `<tr class="${o.seen === false ? 'new' : ''}">
<td><a href="${href}"><strong>${e(o.ref)}</strong></a>${o.seen === false ? '<span class="newtag">NEW</span>' : ''}<div class="muted">${e(fmtDate(o.createdAt))}</div></td>
<td>${e(c.name)}<div class="muted"><a href="tel:+91${e(c.phone)}">${e(c.phone)}</a> · <a href="${e(waLink(c.phone, `Hi ${c.name}, this is neemveda about your order ${o.ref}.`))}" target="_blank" rel="noopener">WhatsApp</a></div></td>
<td class="muted">${e(c.city)}, ${e(c.state)} – ${e(c.pincode)}</td>
<td>${o.status === 'delivered' ? '✅ Delivered' : o.status === 'cancelled' ? '<span class="muted">–</span>'
    : `${o.place ? `📍 ${e(o.place)}` : '<span class="muted">Not shipped yet</span>'}${o.expectedDelivery ? `<div class="muted">Arrives ${e(mailer.fmtDay(o.expectedDelivery))}</div>` : ''}`}</td>
<td>${o.lines.map((l) => `${l.qty} × ${e(l.name)}`).join('<br>')}</td>
<td><strong>${rupees(o.total)}</strong><div class="muted">${e(payLabel(o))}</div></td>
<td>${statusPill(o.status)}<div style="margin-top:6px"><a class="btn sm alt" href="${href}">Open</a></div></td></tr>`;
  }).join('');

  const options = [['', 'All orders'], ['new', 'New (not opened)'], ...STATUSES.map((x) => [x, STATUS_LABEL[x]])]
    .map(([v, l]) => `<option value="${v}"${v === filter ? ' selected' : ''}>${l}</option>`).join('');

  const site = catalog.readSite();
  const tomorrow = istDate(1);
  const dueTomorrow = all.filter((o) => o.expectedDelivery === tomorrow && ['confirmed', 'paid', 'packed', 'shipped'].includes(o.status));
  const remindHtml = dueTomorrow.length ? `<div class="panel" style="border-color:#1f8f4e;background:#effaf2"><h2>Delivery tomorrow: send reminders</h2>
<div class="row">${dueTomorrow.map((o) => `<a class="btn sm wa" href="${e(waLink(o.customer.phone, mailer.whatsappText(o, 'reminder', site)))}" target="_blank" rel="noopener">📲 ${e(o.customer.name)} · ${e(o.ref)}</a>`).join('')}</div></div>` : '';
  return `<h1>Orders</h1>
${remindHtml}
<div class="cards">
<div class="card"><b>${s.unseen}</b>New orders</div>
<div class="card"><b>${s.today}</b>Orders today</div>
<div class="card"><b>${s.toShip}</b>To pack / ship</div>
<div class="card"><b>${rupees(s.codDue)}</b>COD to collect</div>
<div class="card"><b>${rupees(s.revenue)}</b>Money received</div>
<div class="card"><b>${s.total}</b>All orders</div></div>
<div class="bar"><form method="get" action="/admin"><input type="hidden" name="view" value="orders">
<input name="q" value="${e(term)}" placeholder="Search name, phone, order no." aria-label="Search orders">
<select name="status" aria-label="Filter by status" onchange="this.form.submit()">${options}</select>
<button class="btn alt" type="submit">Search</button></form>
<div class="row"><button class="btn alt" type="button" id="enable-alerts">🔔 Turn on new-order alerts</button><a class="btn" href="/admin/orders.csv">Download CSV</a></div></div>
${placesHtml(all)}
<div class="wrap" data-live-refresh><table><thead><tr><th>Order</th><th>Customer</th><th>Deliver to</th><th>Now at / arrives</th><th>Items</th><th>Total</th><th>Status</th></tr></thead>
<tbody>${rows || `<tr><td colspan="7" class="muted">${all.length ? 'No orders match.' : 'No orders yet. New orders appear here automatically.'}</td></tr>`}</tbody></table></div>
<p class="muted">This page checks for new orders every 10 seconds. Tap “Turn on new-order alerts” once to get a sound and a notification on this device.</p>`;
}

/* Where orders are going: counts by state and city (cancelled orders left out). */
function placesHtml(all) {
  const live = all.filter((o) => o.status !== 'cancelled');
  if (!live.length) return '';
  const count = (key) => Object.entries(live.reduce((m, o) => { const k = key(o); m[k] = (m[k] || 0) + 1; return m; }, {}))
    .sort((a, b) => b[1] - a[1]).slice(0, 12)
    .map(([k, n]) => `<span>${e(k)} <strong>${n}</strong></span>`).join('');
  const inTransit = live.filter((o) => ['shipped', 'out_for_delivery'].includes(o.status) && o.place);
  return `<div class="panel"><h2>Orders by place</h2>
<p class="muted" style="margin:0 0 6px">States</p><div class="places">${count((o) => o.customer.state)}</div>
<p class="muted" style="margin:12px 0 6px">Cities</p><div class="places">${count((o) => o.customer.city.trim())}</div>
${inTransit.length ? `<p class="muted" style="margin:12px 0 6px">On the way now</p><div class="places">${inTransit.map((o) =>
    `<span><a href="/admin/order?ref=${encodeURIComponent(o.ref)}">${e(o.ref)}</a> 📍 ${e(o.place)}</span>`).join('')}</div>` : ''}</div>`;
}

function orderView(o, flash, waKind) {
  const c = o.customer;
  const options = STATUSES.map((s) => `<option value="${s}"${s === o.status ? ' selected' : ''}>${STATUS_LABEL[s]}</option>`).join('');
  const address = [c.name, c.address1, c.address2, `${c.city}, ${c.state} – ${c.pincode}`, `Phone: ${c.phone}`].filter(Boolean);
  const items = o.lines.map((l) => `<tr><td>${l.qty} × ${e(l.name)}</td><td>${rupees(l.unitPrice)}</td><td style="text-align:right">${rupees(l.total)}</td></tr>`).join('');
  const site = catalog.readSite();
  const waKinds = ['placed', 'packed', 'shipped', ...(o.expectedDelivery ? ['expected', 'reminder'] : []), 'out_for_delivery', ...(o.place ? ['location'] : []), 'delivered'];
  const waButtons = waKinds.map((k) =>
    `<a class="btn sm wa" href="${e(waLink(c.phone, mailer.whatsappText(o, k, site)))}" target="_blank" rel="noopener">${e(mailer.KIND_LABEL[k])}</a>`).join('');
  const history = (o.history || []).slice().reverse().map((h) =>
    `<li><strong>${e(STATUS_LABEL[h.status] || h.status)}</strong>${h.place ? ` · 📍 ${e(h.place)}` : ''} <span class="muted">${e(fmtDate(h.at))}${h.note ? ' · ' + e(h.note) : ''}</span></li>`).join('');
  const sent = (o.notifications || []).slice().reverse().map((n) =>
    `<li>${n.ok ? '<span class="ok">✓ Sent</span>' : '<span class="no">✗ Not sent</span>'} · ${e(mailer.KIND_LABEL[n.kind] || n.kind)} email <span class="muted">${e(fmtDate(n.at))}</span></li>`).join('');
  const minDate = istDate(0, new Date(o.createdAt).getTime());
  const email = mailer.enabled();
  // Which WhatsApp message to suggest: the update just saved, or the order confirmation for a new order.
  const suggest = waKind && mailer.KINDS.includes(waKind) ? waKind
    : ['confirmed', 'paid'].includes(o.status) && (o.history || []).length <= 1 ? 'placed' : '';
  const suggestHtml = suggest ? `<div class="panel" style="border-color:#1f8f4e;background:#effaf2">
<h2>${waKind ? 'Saved. Now tell the customer on WhatsApp' : 'New order: send the confirmation on WhatsApp'}</h2>
<p class="muted" style="margin:0 0 10px">${e(mailer.whatsappText(o, suggest, site))}</p>
<a class="btn wa" href="${e(waLink(c.phone, mailer.whatsappText(o, suggest, site)))}" target="_blank" rel="noopener">📲 Send “${e(mailer.KIND_LABEL[suggest])}” on WhatsApp</a></div>` : '';

  return `<div class="no-print">
<p><a href="/admin?view=orders">← All orders</a></p>
${flash && !suggestHtml ? `<p class="flash">${e(flash)}</p>` : ''}
<div class="bar"><h1 style="margin:0">Order ${e(o.ref)} ${statusPill(o.status)}</h1>
<button class="btn alt" type="button" onclick="window.print()">🖨 Packing slip</button></div>
${suggestHtml}
<div class="grid2"><div>
<div class="panel"><h2>Items</h2><table><thead><tr><th>Item</th><th>Price</th><th style="text-align:right">Total</th></tr></thead><tbody>${items}
<tr><td colspan="2">Subtotal</td><td style="text-align:right">${rupees(o.subtotal)}</td></tr>
<tr><td colspan="2">Shipping</td><td style="text-align:right">${o.shipping ? rupees(o.shipping) : 'Free'}</td></tr>
<tr><td colspan="2"><strong>Total</strong></td><td style="text-align:right"><strong>${rupees(o.total)}</strong></td></tr></tbody></table>
<p class="muted" style="margin-top:10px">Payment: <strong>${e(payLabel(o))}</strong>${o.razorpayPaymentId ? ' · ' + e(o.razorpayPaymentId) : ''}${o.payment === 'cod' ? ' · collect ' + rupees(o.total) + ' on delivery' : ''}</p></div>
<div class="panel"><h2>Update order</h2>
<form method="post" action="/admin/orders/status"><input type="hidden" name="ref" value="${e(o.ref)}"><input type="hidden" name="back" value="order">
<div class="field"><label for="st">Status</label><select id="st" name="status">${options}</select></div>
<div class="field"><label for="ed">Expected delivery date</label><input id="ed" name="expectedDelivery" type="date" min="${e(minDate)}" value="${e(o.expectedDelivery || '')}">
<span class="muted">${o.expectedDelivery ? '' : `Estimated: ${e(mailer.deliveryWindow(o) || 'not set')}. `}${email ? 'The customer gets a reminder email the day before this date.' : 'The day before this date, the order shows under “Delivery tomorrow” on the Orders page with a WhatsApp reminder button.'}</span></div>
<div class="field"><label for="pl">Location update (where the parcel is now)</label><input id="pl" name="place" maxlength="120" placeholder="e.g. Dispatched from Coimbatore / Reached Chennai hub">
${o.place ? `<span class="muted">Last: 📍 ${e(o.place)}</span>` : ''}</div>
<div class="field"><label for="tr">Tracking (courier + number or link)</label><input id="tr" name="tracking" value="${e(o.tracking || '')}" maxlength="200"></div>
<div class="field"><label for="nt">Private note (only you see this)</label><textarea id="nt" name="note" maxlength="1000">${e(o.note || '')}</textarea></div>
${email ? '<label class="check"><input type="checkbox" name="email" value="1" checked> Email the customer about this update</label>' : ''}
<button class="btn" type="submit">Save</button></form>
<p class="muted">After you save, a WhatsApp button appears with the message for that update ready to send.</p></div>
</div><div>
<div class="panel"><h2>Customer</h2><p>${address.map(e).join('<br>')}<br><a href="mailto:${e(c.email)}">${e(c.email)}</a></p>
<div class="row"><a class="btn sm" href="tel:+91${e(c.phone)}">📞 Call</a></div>
<p class="muted" style="margin:12px 0 6px">Send any update on WhatsApp (opens with the message ready):</p><div class="row">${waButtons}</div></div>
<div class="panel"><h2>Delivery</h2><p>${o.status === 'delivered' ? '✅ <strong>Delivered</strong>' : o.expectedDelivery ? `Arrives <strong>${e(mailer.fmtDay(o.expectedDelivery))}</strong>` : `Estimated ${e(mailer.deliveryWindow(o) || '–')}`}${o.place && !['delivered', 'cancelled'].includes(o.status) ? `<br>Now at 📍 <strong>${e(o.place)}</strong>` : ''}${o.tracking ? `<br>Tracking: ${e(o.tracking)}` : ''}</p>
<h2 style="margin-top:14px">History</h2><ul class="timeline">${history || '<li class="muted">No updates yet.</li>'}</ul>
<p class="muted">Placed ${e(fmtDate(o.createdAt))}</p></div>
${email || sent ? `<div class="panel"><h2>Emails to customer</h2><ul style="padding-left:18px;margin:0">${sent || '<li class="muted">None yet.</li>'}</ul></div>` : ''}
</div></div></div>
<div class="slip"><h1>neemveda · Packing slip</h1><p><strong>Order ${e(o.ref)}</strong> · ${e(fmtDate(o.createdAt))}</p>
<p><strong>Ship to:</strong><br>${address.map(e).join('<br>')}</p>
<table><tbody>${o.lines.map((l) => `<tr><td>${l.qty} × ${e(l.name)}</td></tr>`).join('')}</tbody></table>
<p><strong>${o.payment === 'cod' ? 'CASH ON DELIVERY: collect ' + rupees(o.total) : 'PREPAID: ' + rupees(o.total)}</strong></p></div>`;
}

function productsView(flash, error) {
  const rows = catalog.readProducts().map((p) => `<tr>
<td><strong>${e(p.name)}</strong><div class="muted">${e(p.id)}</div></td>
<td><input name="price_${e(p.id)}" type="number" min="1" max="100000" step="1" value="${p.price == null ? '' : e(p.price)}" style="width:110px" aria-label="Price for ${e(p.name)}"></td>
<td><input name="mrp_${e(p.id)}" type="number" min="1" max="100000" step="1" value="${p.mrp == null ? '' : e(p.mrp)}" style="width:110px" aria-label="MRP for ${e(p.name)}"></td>
<td><label><input type="checkbox" name="available_${e(p.id)}" value="1"${p.available ? ' checked' : ''}> In stock</label></td>
<td><input name="badge_${e(p.id)}" value="${e(p.badge || '')}" maxlength="30" style="width:140px" aria-label="Badge for ${e(p.name)}"></td></tr>`).join('');
  const site = catalog.readSite();
  const ship = site.shipping || {};
  return `<h1>Products</h1>${flash ? `<p class="flash">${e(flash)}</p>` : ''}${error ? `<p class="flash err">${e(error)}</p>` : ''}
<form method="post" action="/admin/products"><div class="wrap"><table><thead><tr><th>Product</th><th>Price (₹)</th><th>MRP (₹)</th><th>Stock</th><th>Badge</th></tr></thead>
<tbody>${rows}</tbody></table></div>
<p><button class="btn" type="submit">Save products</button></p></form>
<div class="panel"><h2>Shop settings</h2><p class="muted">Delivery: ${ship.flatRate != null ? rupees(ship.flatRate) : 'not set'}${ship.freeAbove != null ? `, free on orders of ${rupees(ship.freeAbove)} and above` : ''} ·
Cash on Delivery: ${site.payments && site.payments.cod ? 'on' : 'off'} · Online payment (Razorpay): ${razorpay.configured() ? 'on' : 'not set up'} ·
Email alerts: ${mailer.enabled() ? 'on' : 'not set up'}</p>
<p class="muted">Leave a price empty to stop online orders for that pack. Saving updates the website straight away.</p></div>`;
}

function subscribersView() {
  const subs = store.listSubscribers();
  return `<h1>Newsletter</h1><div class="bar"><span>${subs.length} subscribers</span><a class="btn" href="/admin/subscribers.csv">Download CSV</a></div>
<div class="wrap"><table><thead><tr><th>Email</th><th>Joined</th></tr></thead><tbody>${
    subs.map((s) => `<tr><td>${e(s.email)}</td><td class="muted">${e(fmtDate(s.at))}</td></tr>`).join('') ||
    '<tr><td colspan="2" class="muted">No subscribers yet.</td></tr>'}</tbody></table></div>`;
}

function messagesView() {
  const msgs = store.listMessages();
  return `<h1>Messages</h1><div class="wrap"><table><thead><tr><th>From</th><th>Topic</th><th>Message</th><th>Received</th></tr></thead><tbody>${
    msgs.map((m) => `<tr><td>${e(m.name)}<div class="muted"><a href="mailto:${e(m.email)}">${e(m.email)}</a>${m.phone ? '<br>' + e(m.phone) : ''}</div></td>
<td>${e(m.topic || '-')}</td><td style="white-space:pre-wrap">${e(m.message)}</td><td class="muted">${e(fmtDate(m.at))}</td></tr>`).join('') ||
    '<tr><td colspan="4" class="muted">No messages yet.</td></tr>'}</tbody></table></div>`;
}

/* ------------------------------------------------------------------ actions */
async function login(req, res) {
  requireSameOrigin(req);
  rateLimit(req, 'admin-login', 10, 10 * 60 * 1000);
  const form = querystring.parse(await readBody(req));
  const ok = safeEqual(String(form.user || ''), config.admin.user) && safeEqual(String(form.password || ''), config.admin.password);
  if (!ok) {
    console.warn('[admin] failed login');
    return send(res, 401, loginPage('Wrong user ID or password.'), { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
  }
  redirect(res, '/admin', { 'Set-Cookie': cookieHeader(req, makeToken(config.admin.user), SESSION_DAYS * 86400) });
}

async function updateStatus(req, res) {
  const form = querystring.parse(await readBody(req));
  const order = store.getOrder(String(form.ref || ''));
  const status = String(form.status || '');
  if (!order || !STATUSES.includes(status)) throw new HttpError(400, 'Invalid update.');
  const now = new Date().toISOString();
  const place = clean(form.place, 120);
  const date = String(form.expectedDelivery || '').trim();
  if (date && !/^\d{4}-\d{2}-\d{2}$/.test(date)) throw new HttpError(400, 'Invalid delivery date.');

  const statusChanged = status !== order.status;
  const dateChanged = date !== (order.expectedDelivery || '');
  order.history = order.history || [];
  if (statusChanged) {
    order.status = status;
    order.history.push({ at: now, status, note: 'admin', place: place || undefined });
  } else if (place) {
    order.history.push({ at: now, status, note: 'location update', place });
  }
  if (place) order.place = place;
  if (dateChanged) {
    order.expectedDelivery = date || undefined;
    // The "expected" email already names the date, so skip the separate reminder when it's tomorrow.
    if (date && date === istDate(1)) order.reminderSentFor = date;
  }
  order.tracking = clean(form.tracking, 200);
  if (form.note !== undefined) order.note = String(form.note).replace(/\r\n/g, '\n').slice(0, 1000).trim();
  order.seen = true;
  store.saveOrder(order);

  // One message per save, most important first: status change, then new date, then location.
  const kind = statusChanged && STATUS_EMAIL[status] ? STATUS_EMAIL[status]
    : dateChanged && date ? 'expected'
      : place ? 'location' : '';
  if (kind && form.email === '1' && mailer.enabled()) await notify(order, kind);
  redirect(res, form.back === 'order'
    ? `/admin/order?ref=${encodeURIComponent(order.ref)}&saved=1${kind ? '&wa=' + kind : ''}` : '/admin?view=orders');
}

async function saveProducts(req, res, rebuild) {
  const form = querystring.parse(await readBody(req));
  const overrides = catalog.readOverrides();
  const num = (v) => (String(v || '').trim() === '' ? null : Number(v));
  const bad = (n) => n !== null && !(Number.isInteger(n) && n >= 1 && n <= 100000);
  const fail = (msg) => redirect(res, '/admin?view=products&error=' + encodeURIComponent(msg));
  for (const p of catalog.readProducts()) {
    const price = num(form['price_' + p.id]);
    const mrp = num(form['mrp_' + p.id]);
    if (bad(price) || bad(mrp)) return fail(`${p.name}: prices must be whole rupees between 1 and 100000.`);
    if (price !== null && mrp !== null && mrp < price) return fail(`${p.name}: MRP can't be lower than the price.`);
    overrides[p.id] = { price, mrp, available: form['available_' + p.id] === '1', badge: clean(form['badge_' + p.id], 30) };
  }
  catalog.saveOverrides(overrides);
  rebuild();
  console.log('[admin] products updated');
  redirect(res, '/admin?view=products&saved=1');
}

function exportOrders(res) {
  const rows = [['Order', 'Date', 'Status', 'Payment', 'Payment ID', 'Name', 'Phone', 'Email', 'Address', 'City', 'State', 'PIN', 'Items', 'Subtotal', 'Shipping', 'Total', 'Tracking', 'Expected delivery', 'Now at', 'Note']];
  for (const o of store.listOrders()) {
    const c = o.customer;
    rows.push([o.ref, fmtDate(o.createdAt), o.status, o.payment, o.razorpayPaymentId || '', c.name, c.phone, c.email,
      [c.address1, c.address2].filter(Boolean).join(', '), c.city, c.state, c.pincode,
      o.lines.map((l) => `${l.qty} x ${l.name}`).join('; '), o.subtotal, o.shipping, o.total, o.tracking || '', o.expectedDelivery || '', o.place || '', o.note || '']);
  }
  send(res, 200, csv(rows), { 'Content-Type': 'text/csv; charset=utf-8', 'Content-Disposition': 'attachment; filename="neemveda-orders.csv"', 'Cache-Control': 'no-store' });
}

/* ------------------------------------------------------------------ router */
async function handle(req, res, urlPath, rebuild = () => {}) {
  const html = { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' };
  if (!config.admin.password) {
    return send(res, 503, 'Admin is disabled. Set ADMIN_PASSWORD in .env and restart the server.', { 'Content-Type': 'text/plain; charset=utf-8' });
  }
  if (urlPath === '/admin/login') {
    if (req.method === 'POST') return login(req, res);
    if (sessionUser(req)) return redirect(res, '/admin');
    return send(res, 200, loginPage(), html);
  }
  if (urlPath === '/admin/logout' && req.method === 'POST') {
    requireSameOrigin(req);
    return redirect(res, '/admin/login', { 'Set-Cookie': cookieHeader(req, '', 0) });
  }
  if (!sessionUser(req)) {
    if (urlPath.startsWith('/admin/api/')) return sendJson(res, 401, { error: 'Login required.' });
    return redirect(res, '/admin/login');
  }

  if (req.method === 'POST') {
    requireSameOrigin(req);
    if (urlPath === '/admin/orders/status') return updateStatus(req, res);
    if (urlPath === '/admin/products') return saveProducts(req, res, rebuild);
    return send(res, 404, 'Not found');
  }

  const q = new URL(req.url, 'http://x').searchParams;
  const unseenOrders = () => store.listOrders().filter((o) => o.seen === false);

  if (urlPath === '/admin/api/poll') {
    const fresh = unseenOrders();
    return sendJson(res, 200, {
      unseen: fresh.length,
      latest: fresh.slice(0, 20).map((o) => ({ ref: o.ref, name: o.customer.name, total: o.total, payment: payLabel(o), createdAt: o.createdAt })),
    });
  }
  if (urlPath === '/admin/orders.csv') return exportOrders(res);
  if (urlPath === '/admin/subscribers.csv') {
    const rows = [['Email', 'Joined'], ...store.listSubscribers().map((s) => [s.email, fmtDate(s.at)])];
    return send(res, 200, csv(rows), { 'Content-Type': 'text/csv; charset=utf-8', 'Content-Disposition': 'attachment; filename="neemveda-subscribers.csv"', 'Cache-Control': 'no-store' });
  }

  if (urlPath === '/admin/order') {
    const order = store.getOrder(String(q.get('ref') || ''));
    if (!order) return send(res, 404, page('Not found', navHtml('orders', unseenOrders().length), '<p>Order not found. <a href="/admin">Back to orders</a></p>'), html);
    if (order.seen === false) { order.seen = true; store.saveOrder(order); }
    return send(res, 200, page(`Order ${order.ref}`, navHtml('orders', unseenOrders().length), orderView(order, q.get('saved') ? 'Saved.' : '', q.get('wa') || ''), { live: true }), html);
  }
  if (urlPath !== '/admin' && urlPath !== '/admin/') return send(res, 404, 'Not found');

  const view = ['orders', 'products', 'subscribers', 'messages'].includes(q.get('view')) ? q.get('view') : 'orders';
  const body = view === 'subscribers' ? subscribersView()
    : view === 'messages' ? messagesView()
      : view === 'products' ? productsView(q.get('saved') ? 'Saved. The website now shows the new prices.' : '', clean(q.get('error'), 200))
        : ordersView(q);
  send(res, 200, page(view[0].toUpperCase() + view.slice(1), navHtml(view, unseenOrders().length), body, { live: true }), html);
}

module.exports = { handle };
