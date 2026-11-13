/*
 * Tiny JSON-file store. Good for a small shop (hundreds of orders a day).
 * Writes go to a temp file first and are then renamed, so a crash never leaves a half-written file.
 * The data/ folder holds customer details: keep it private and back it up.
 */
const fs = require('fs');
const path = require('path');
const { dataDir } = require('./config');

fs.mkdirSync(dataDir, { recursive: true });

class JsonFile {
  constructor(name, empty) {
    this.file = path.join(dataDir, name);
    this.data = empty;
    if (fs.existsSync(this.file)) this.data = JSON.parse(fs.readFileSync(this.file, 'utf8'));
  }
  save() {
    const tmp = this.file + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(this.data, null, 2));
    fs.renameSync(tmp, this.file);
  }
}

const orders = new JsonFile('orders.json', {});
const subscribers = new JsonFile('subscribers.json', []);
const messages = new JsonFile('messages.json', []);

module.exports = {
  getOrder: (ref) => orders.data[ref] || null,
  saveOrder(order) {
    order.updatedAt = new Date().toISOString();
    orders.data[order.ref] = order;
    orders.save();
    return order;
  },
  findOrderByRazorpayId(id) {
    return Object.values(orders.data).find((o) => o.razorpayOrderId === id) || null;
  },
  listOrders: () =>
    Object.values(orders.data).sort((a, b) => (a.createdAt < b.createdAt ? 1 : -1)),

  addSubscriber(email) {
    const exists = subscribers.data.some((s) => s.email === email);
    if (!exists) {
      subscribers.data.push({ email, at: new Date().toISOString() });
      subscribers.save();
    }
    return !exists;
  },
  listSubscribers: () => subscribers.data.slice().reverse(),

  addMessage(msg) {
    messages.data.push({ ...msg, at: new Date().toISOString() });
    messages.save();
  },
  listMessages: () => messages.data.slice().reverse(),
};
