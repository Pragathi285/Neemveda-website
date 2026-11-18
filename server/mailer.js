/*
 * Email notifications via SMTP (e.g. Gmail with an App Password).
 * If SMTP isn't configured, emails are skipped and a line is logged instead.
 *
 * Customer emails, one per order stage (see CUSTOMER_EMAILS below):
 *   placed, packed, shipped, expected (delivery date set/changed), reminder (day before delivery),
 *   out_for_delivery, location (where the parcel is now), delivered, cancelled.
 */
const { smtp, mailFrom, ownerEmail } = require('./config');
const { escapeHtml, rupees } = require('./util');

let transport = null;
try {
  const nodemailer = require('nodemailer');
  if (smtp.host && smtp.user && smtp.pass) {
    transport = nodemailer.createTransport({
      host: smtp.host,
      port: smtp.port,
      secure: smtp.secure,
      auth: { user: smtp.user, pass: smtp.pass },
    });
  }
} catch (e) {
  console.warn('[mail] nodemailer is not installed (run `npm install`); emails are disabled.');
}

const enabled = () => Boolean(transport);

async function send(message) {
  if (!transport) {
    console.log(`[mail] SMTP not configured, skipped: "${message.subject}" -> ${message.to}`);
    return false;
  }
  try {
    await transport.sendMail({ from: mailFrom, ...message });
    return true;
  } catch (err) {
    console.error('[mail] failed:', err.message);
    return false;
  }
}

const PAYMENT_LABEL = { upi: 'UPI (Razorpay)', razorpay: 'Card / Netbanking (Razorpay)', cod: 'Cash on Delivery' };

/* ------------------------------------------------------------------ dates */
// Delivery dates are stored as YYYY-MM-DD (India time).
function fmtDay(ymd) {
  if (!ymd) return '';
  const d = new Date(ymd + 'T12:00:00+05:30');
  return d.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}
function deliveryWindow(o) {
  if (o.expectedDelivery) return fmtDay(o.expectedDelivery);
  if (o.estimate && o.estimate.from && o.estimate.to) {
    return o.estimate.from === o.estimate.to ? fmtDay(o.estimate.to) : `${fmtDay(o.estimate.from)} – ${fmtDay(o.estimate.to)}`;
  }
  return '';
}

/* ------------------------------------------------------------------ templates */
function orderSummaryText(o) {
  const c = o.customer;
  return [
    ...o.lines.map((l) => `${l.qty} x ${l.name} @ ${rupees(l.unitPrice)} = ${rupees(l.total)}`),
    `Subtotal: ${rupees(o.subtotal)}`,
    `Shipping: ${o.shipping ? rupees(o.shipping) : 'Free'}`,
    `Total: ${rupees(o.total)}`,
    `Payment: ${PAYMENT_LABEL[o.payment] || o.payment} (${o.status})`,
    '',
    'Deliver to:',
    c.name,
    c.address1,
    c.address2,
    `${c.city}, ${c.state} - ${c.pincode}`,
    `Phone: ${c.phone}`,
    `Email: ${c.email}`,
  ].filter((line, i, all) => line !== undefined && line !== null && !(line === '' && all[i - 1] === c.address1)).join('\n');
}

function wrapHtml(title, bodyHtml, footerHtml = '') {
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#F7F1E4;font-family:Arial,sans-serif;color:#231F20">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px">
<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden">
<tr><td style="background:#2F4F2F;color:#fff;padding:20px 24px;font-size:22px;font-weight:bold">neemveda<div style="font-size:10px;letter-spacing:3px;font-weight:normal">NATURAL ORAL CARE</div></td></tr>
<tr><td style="padding:24px;font-size:15px;line-height:1.5"><h1 style="color:#2F4F2F;font-size:20px;margin:0 0 12px">${escapeHtml(title)}</h1>${bodyHtml}</td></tr>
${footerHtml ? `<tr><td style="padding:16px 24px;background:#F7F1E4;font-size:12px;color:#555">${footerHtml}</td></tr>` : ''}
</table></td></tr></table></body></html>`;
}

function orderTableHtml(o) {
  const rows = o.lines
    .map((l) => `<tr><td style="padding:6px 0">${l.qty} × ${escapeHtml(l.name)}</td><td align="right">${rupees(l.total)}</td></tr>`)
    .join('');
  return `<table width="100%" style="border-collapse:collapse;font-size:14px">${rows}
<tr><td style="padding:6px 0;border-top:1px solid #ddd">Shipping</td><td align="right" style="border-top:1px solid #ddd">${o.shipping ? rupees(o.shipping) : 'Free'}</td></tr>
<tr><td style="padding:6px 0;font-weight:bold">Total</td><td align="right" style="font-weight:bold">${rupees(o.total)}</td></tr></table>`;
}

const button = (href, label) =>
  `<p style="margin:20px 0"><a href="${escapeHtml(href)}" style="background:#2F4F2F;color:#fff;text-decoration:none;padding:12px 20px;border-radius:8px;display:inline-block;font-weight:bold">${escapeHtml(label)}</a></p>`;
const infoBox = (html) => `<p style="background:#F7F1E4;border-radius:8px;padding:12px 14px;margin:14px 0">${html}</p>`;
const codLine = (o) => (o.payment === 'cod' && !['delivered', 'cancelled'].includes(o.status)
  ? infoBox(`💵 Cash on Delivery: please keep <strong>${rupees(o.total)}</strong> ready.`) : '');

/*
 * Each entry returns { subject, intro, extra } for an order. `site` is site.json.
 * The same wording is used for the WhatsApp messages in the admin panel (see whatsappText).
 */
const CUSTOMER_EMAILS = {
  placed: (o) => ({
    subject: `Order placed successfully: ${o.ref}`,
    title: `Thank you, ${o.customer.name.split(' ')[0]}! Your order is confirmed`,
    line: `Your neemveda order ${o.ref} has been placed successfully.${deliveryWindow(o) ? ` Expected delivery: ${deliveryWindow(o)}.` : ''}`,
  }),
  packed: (o) => ({
    subject: `Your order ${o.ref} is packed`,
    title: 'Your order is packed',
    line: `Your neemveda order ${o.ref} is packed and will be handed to the courier soon.${o.expectedDelivery ? ` Expected delivery: ${fmtDay(o.expectedDelivery)}.` : ''}`,
  }),
  shipped: (o) => ({
    subject: `Your order ${o.ref} has shipped`,
    title: 'Your order is on its way',
    line: `Your neemveda order ${o.ref} has been shipped.${o.tracking ? ` Tracking: ${o.tracking}.` : ''}${o.expectedDelivery ? ` Expected delivery: ${fmtDay(o.expectedDelivery)}.` : ''}`,
  }),
  expected: (o) => ({
    subject: `Delivery date for your order ${o.ref}: ${fmtDay(o.expectedDelivery)}`,
    title: `Arriving on ${fmtDay(o.expectedDelivery)}`,
    line: `Your neemveda order ${o.ref} is expected to be delivered on ${fmtDay(o.expectedDelivery)}.`,
  }),
  reminder: (o) => ({
    subject: `Arriving tomorrow: your order ${o.ref}`,
    title: 'Your order arrives tomorrow',
    line: `Your neemveda order ${o.ref} is expected to be delivered tomorrow, ${fmtDay(o.expectedDelivery)}. Please make sure someone is available to receive it.`,
  }),
  out_for_delivery: (o) => ({
    subject: `Out for delivery today: your order ${o.ref}`,
    title: 'Your order arrives today',
    line: `Your neemveda order ${o.ref} is out for delivery and will reach you today.`,
  }),
  location: (o) => ({
    subject: `Update on your order ${o.ref}: ${o.place}`,
    title: 'Delivery update',
    line: `Update on your neemveda order ${o.ref}: ${o.place}.${o.expectedDelivery ? ` Expected delivery: ${fmtDay(o.expectedDelivery)}.` : ''}`,
  }),
  delivered: (o) => ({
    subject: `Delivered: your order ${o.ref}`,
    title: 'Your order has been delivered',
    line: `Your neemveda order ${o.ref} has been delivered. Thank you for choosing natural oral care! We'd love to hear how you like your neem sticks.`,
  }),
  cancelled: (o) => ({
    subject: `Your order ${o.ref} has been cancelled`,
    title: 'Your order has been cancelled',
    line: `Your neemveda order ${o.ref} has been cancelled. If this is unexpected or you have questions, just reply to this email.`,
  }),
};
const KINDS = Object.keys(CUSTOMER_EMAILS);
const KIND_LABEL = {
  placed: 'Order placed', packed: 'Packed', shipped: 'Shipped', expected: 'Delivery date', reminder: 'Day-before reminder',
  out_for_delivery: 'Out for delivery', location: 'Location update', delivered: 'Delivered', cancelled: 'Cancelled',
};

/* Plain-text message for WhatsApp/SMS, same content as the email. */
function whatsappText(o, kind, site) {
  const m = CUSTOMER_EMAILS[kind](o);
  const extra = kind === 'placed' || kind === 'reminder' || kind === 'out_for_delivery'
    ? (o.payment === 'cod' ? ` Please keep ${rupees(o.total)} ready (Cash on Delivery).` : '') : '';
  return `Hi ${o.customer.name}, ${m.line}${extra} Track your order: ${site.url}/track.html?ref=${o.ref} - neemveda`;
}

async function notifyCustomer(o, kind, site) {
  const m = CUSTOMER_EMAILS[kind](o);
  const c = o.customer;
  const trackUrl = `${site.url}/track.html?ref=${encodeURIComponent(o.ref)}`;
  const showItems = ['placed', 'delivered', 'cancelled'].includes(kind);
  const showCod = ['placed', 'shipped', 'expected', 'reminder', 'out_for_delivery'].includes(kind);
  const html = wrapHtml(m.title, `<p>Hi ${escapeHtml(c.name)},</p><p>${escapeHtml(m.line)}</p>
${o.tracking && kind !== 'placed' ? infoBox(`📦 Tracking: <strong>${escapeHtml(o.tracking)}</strong>`) : ''}
${showCod ? codLine(o) : ''}
${showItems ? orderTableHtml(o) : ''}
${kind === 'placed' ? `<p style="font-size:14px;margin-top:16px"><strong>Delivering to</strong><br>${escapeHtml(c.name)}<br>${escapeHtml(c.address1)}${c.address2 ? '<br>' + escapeHtml(c.address2) : ''}<br>${escapeHtml(c.city)}, ${escapeHtml(c.state)} – ${escapeHtml(c.pincode)}</p><p style="font-size:14px">Payment: ${escapeHtml(PAYMENT_LABEL[o.payment] || o.payment)}</p>` : ''}
${kind === 'cancelled' ? '' : button(trackUrl, 'Track your order')}`,
  `Questions? Just reply to this email.<br>Order number: ${escapeHtml(o.ref)}`);
  const text = `Hi ${c.name},\n\n${m.line}\n${o.tracking && kind !== 'placed' ? `\nTracking: ${o.tracking}\n` : ''}${
    showCod && o.payment === 'cod' ? `\nCash on Delivery: please keep ${rupees(o.total)} ready.\n` : ''}${
    showItems ? `\n${orderSummaryText(o)}\n` : ''}\nTrack your order: ${trackUrl}\n\nWarmly,\nneemveda`;
  return send({ to: c.email, replyTo: ownerEmail, subject: m.subject, text, html });
}

function notifyOwnerNewOrder(o) {
  const c = o.customer;
  return send({
    to: ownerEmail,
    replyTo: c.email,
    subject: `New order ${o.ref}: ${rupees(o.total)} (${o.status === 'paid' ? 'PAID' : PAYMENT_LABEL[o.payment]})`,
    text: `New order ${o.ref}\n\n${orderSummaryText(o)}`,
  });
}

function notifyContact(m) {
  return send({
    to: ownerEmail,
    replyTo: m.email,
    subject: `Website message from ${m.name}${m.topic ? ` (${m.topic})` : ''}`,
    text: `${m.message}\n\nName: ${m.name}\nEmail: ${m.email}\nPhone: ${m.phone || '-'}\nTopic: ${m.topic || '-'}`,
  });
}

module.exports = {
  enabled, notifyCustomer, notifyOwnerNewOrder, notifyContact, whatsappText,
  fmtDay, deliveryWindow, PAYMENT_LABEL, KINDS, KIND_LABEL,
};
