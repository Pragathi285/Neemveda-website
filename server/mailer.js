/*
 * Email notifications via SMTP (e.g. Gmail with an App Password).
 * If SMTP isn't configured, emails are skipped and a line is logged instead.
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
  ].filter((line) => line !== undefined && line !== null).join('\n');
}

function wrapHtml(title, bodyHtml) {
  return `<!doctype html><html><body style="margin:0;background:#F7F1E4;font-family:Arial,sans-serif;color:#231F20">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px">
<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden">
<tr><td style="background:#2F4F2F;color:#fff;padding:20px 24px;font-size:22px;font-weight:bold">neemveda<div style="font-size:10px;letter-spacing:3px;font-weight:normal">NATURAL ORAL CARE</div></td></tr>
<tr><td style="padding:24px"><h1 style="color:#2F4F2F;font-size:20px;margin:0 0 12px">${escapeHtml(title)}</h1>${bodyHtml}</td></tr>
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

function notifyNewOrder(o) {
  const c = o.customer;
  const owner = send({
    to: ownerEmail,
    replyTo: c.email,
    subject: `New order ${o.ref}: ${rupees(o.total)} (${o.status === 'paid' ? 'PAID' : PAYMENT_LABEL[o.payment]})`,
    text: `New order ${o.ref}\n\n${orderSummaryText(o)}`,
  });
  const customer = send({
    to: c.email,
    replyTo: ownerEmail,
    subject: `Your neemveda order ${o.ref} is confirmed`,
    text: `Hi ${c.name},\n\nThank you for your order! Here are the details:\n\n${orderSummaryText(o)}\n\nWe'll let you know when it ships.\n\nWarmly,\nneemveda`,
    html: wrapHtml(
      `Thank you, ${c.name.split(' ')[0]}!`,
      `<p>Your order <strong>${escapeHtml(o.ref)}</strong> is confirmed. We'll let you know when it ships.</p>
${orderTableHtml(o)}
<p style="font-size:14px;margin-top:16px"><strong>Delivering to</strong><br>${escapeHtml(c.name)}<br>${escapeHtml(c.address1)}${c.address2 ? '<br>' + escapeHtml(c.address2) : ''}<br>${escapeHtml(c.city)}, ${escapeHtml(c.state)} – ${escapeHtml(c.pincode)}</p>
<p style="font-size:14px">Payment: ${escapeHtml(PAYMENT_LABEL[o.payment] || o.payment)}</p>`
    ),
  });
  return Promise.all([owner, customer]);
}

function notifyShipped(o) {
  const c = o.customer;
  const tracking = o.tracking ? `\nTracking: ${o.tracking}\n` : '';
  return send({
    to: c.email,
    replyTo: ownerEmail,
    subject: `Your neemveda order ${o.ref} has shipped`,
    text: `Hi ${c.name},\n\nGood news: your order ${o.ref} is on its way.\n${tracking}\nWarmly,\nneemveda`,
    html: wrapHtml(
      'Your order is on its way',
      `<p>Hi ${escapeHtml(c.name)}, your order <strong>${escapeHtml(o.ref)}</strong> has shipped.</p>${
        o.tracking ? `<p><strong>Tracking:</strong> ${escapeHtml(o.tracking)}</p>` : ''
      }`
    ),
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

module.exports = { enabled, notifyNewOrder, notifyShipped, notifyContact, PAYMENT_LABEL };
