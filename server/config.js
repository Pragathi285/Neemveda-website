/* Server configuration, read from environment variables or a .env file in the project root. */
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');

function loadEnvFile(file) {
  if (!fs.existsSync(file)) return;
  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    if (!line.trim() || line.trim().startsWith('#')) continue;
    const m = line.match(/^\s*([A-Za-z0-9_]+)\s*=\s*(.*?)\s*$/);
    if (!m) continue;
    let value = m[2];
    if (/^(['"]).*\1$/.test(value)) value = value.slice(1, -1);
    if (process.env[m[1]] === undefined) process.env[m[1]] = value;
  }
}
loadEnvFile(path.join(ROOT, '.env'));

const env = (key, fallback = '') =>
  process.env[key] !== undefined && process.env[key] !== '' ? process.env[key] : fallback;

const site = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/data/site.json'), 'utf8'));

module.exports = {
  ROOT,
  DIST: path.join(ROOT, 'dist'),
  port: Number(env('PORT', 4173)),
  dataDir: path.resolve(ROOT, env('DATA_DIR', 'data')),
  trustProxy: env('TRUST_PROXY') === '1',
  razorpay: {
    keyId: env('RAZORPAY_KEY_ID'),
    keySecret: env('RAZORPAY_KEY_SECRET'),
    webhookSecret: env('RAZORPAY_WEBHOOK_SECRET'),
  },
  smtp: {
    host: env('SMTP_HOST'),
    port: Number(env('SMTP_PORT', 465)),
    secure: env('SMTP_SECURE', 'true') !== 'false',
    user: env('SMTP_USER'),
    pass: env('SMTP_PASS'),
  },
  mailFrom: env('MAIL_FROM', `neemveda <${env('SMTP_USER', site.email)}>`),
  ownerEmail: env('OWNER_EMAIL', site.email),
  admin: {
    user: env('ADMIN_USER', 'admin'),
    password: env('ADMIN_PASSWORD'),
  },
};
