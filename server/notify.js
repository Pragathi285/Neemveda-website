/*
 * Customer notifications for an order, recorded on the order so the admin panel
 * shows what was sent (and whether it went out).
 *
 * Also runs the day-before-delivery reminder: every 15 minutes it looks for orders
 * whose expected delivery date is tomorrow (India time) and emails the customer once,
 * from 9 am onwards.
 */
const mailer = require('./mailer');
const store = require('./store');
const { readSite } = require('./catalog');

const ACTIVE = ['confirmed', 'paid', 'packed', 'shipped'];

async function notify(order, kind) {
  if (!mailer.enabled()) return false; // email not set up: updates go out on WhatsApp from the admin panel
  let ok = false;
  try {
    ok = await mailer.notifyCustomer(order, kind, readSite());
  } catch (err) {
    console.error('[notify]', err.message);
  }
  order.notifications = order.notifications || [];
  order.notifications.push({ at: new Date().toISOString(), kind, channel: 'email', to: order.customer.email, ok });
  store.saveOrder(order);
  console.log(`[notify] ${order.ref} ${kind} email ${ok ? 'sent' : 'not sent'}`);
  return ok;
}

/* YYYY-MM-DD in India time, `days` from now. */
function istDate(days = 0, from = Date.now()) {
  return new Date(from + days * 864e5).toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' });
}
const istHour = () => Number(new Date().toLocaleString('en-US', { timeZone: 'Asia/Kolkata', hour: 'numeric', hour12: false })) % 24;

/* Estimated delivery window from site.json shipping.deliveryDays, e.g. "3–7". */
function estimateFor(createdAtMs) {
  const nums = String((readSite().shipping || {}).deliveryDays || '').match(/\d+/g);
  if (!nums) return null;
  const min = Number(nums[0]);
  const max = Number(nums[1] || nums[0]);
  return { from: istDate(min, createdAtMs), to: istDate(max, createdAtMs) };
}

async function sendDueReminders() {
  const tomorrow = istDate(1);
  if (istHour() < 9) return;
  for (const o of store.listOrders()) {
    if (o.expectedDelivery !== tomorrow || !ACTIVE.includes(o.status) || o.reminderSentFor === tomorrow) continue;
    o.reminderSentFor = tomorrow; // mark first so a slow send can't trigger a duplicate
    await notify(o, 'reminder');
  }
}

function startScheduler() {
  const run = () => sendDueReminders().catch((err) => console.error('[notify] reminders:', err.message));
  setTimeout(run, 10 * 1000).unref();
  setInterval(run, 15 * 60 * 1000).unref();
}

module.exports = { notify, estimateFor, istDate, startScheduler, sendDueReminders };
