/*
 * Server-side pricing. Prices always come from src/data, never from the browser,
 * so nobody can change a price by editing the page.
 */
const fs = require('fs');
const path = require('path');
const { ROOT } = require('./config');
const { HttpError } = require('./util');

const readJson = (rel) => JSON.parse(fs.readFileSync(path.join(ROOT, rel), 'utf8'));

function catalog() {
  return { site: readJson('src/data/site.json'), products: readJson('src/data/products.json') };
}

function priceOrder(items) {
  const { site, products } = catalog();
  if (!Array.isArray(items) || !items.length) throw new HttpError(400, 'Your cart is empty.');
  if (items.length > 20) throw new HttpError(400, 'Too many items in one order.');

  const qtyById = new Map();
  for (const it of items) {
    const id = String((it && it.id) || '');
    const qty = parseInt(it && it.qty, 10);
    if (!id || !(qty >= 1 && qty <= 20)) throw new HttpError(400, 'Please check the quantities in your cart.');
    qtyById.set(id, Math.min(20, (qtyById.get(id) || 0) + qty));
  }

  const lines = [];
  for (const [id, qty] of qtyById) {
    const p = products.find((x) => x.id === id);
    if (!p || !p.available) throw new HttpError(400, 'One of the items in your cart is no longer available.');
    if (p.price == null) throw new HttpError(400, `"${p.name}" can't be ordered online yet.`);
    lines.push({ id: p.id, name: p.name, count: p.count, qty, unitPrice: p.price, total: p.price * qty });
  }

  const subtotal = lines.reduce((s, l) => s + l.total, 0);
  const ship = site.shipping || {};
  let shipping;
  if (ship.freeAbove != null && subtotal >= ship.freeAbove) shipping = 0;
  else if (ship.flatRate != null) shipping = ship.flatRate;
  else {
    throw new HttpError(
      400,
      ship.freeAbove != null
        ? `Online orders under ₹${ship.freeAbove} aren't available yet. Please add items worth ₹${ship.freeAbove} or more, or order on WhatsApp.`
        : 'Shipping is not set up yet. Please order on WhatsApp.'
    );
  }
  return { lines, subtotal, shipping, total: subtotal + shipping };
}

module.exports = { catalog, priceOrder };
