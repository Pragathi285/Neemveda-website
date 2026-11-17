/*
 * Product catalogue. Defaults come from src/data/products.json (in git).
 * Prices, MRP, availability and badges changed in the admin panel are saved to
 * <DATA_DIR>/product-overrides.json (not in git) and merged on top, so a
 * `git pull` never overwrites what the shop owner set.
 */
const fs = require('fs');
const path = require('path');
const { ROOT, dataDir } = require('./config');

const OVERRIDES = path.join(dataDir, 'product-overrides.json');
const EDITABLE = ['price', 'mrp', 'available', 'badge'];

const readJson = (file, fallback) => (fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : fallback);

function readOverrides() {
  return readJson(OVERRIDES, {});
}

function readSite() {
  return readJson(path.join(ROOT, 'src/data/site.json'));
}

function readProducts() {
  const overrides = readOverrides();
  return readJson(path.join(ROOT, 'src/data/products.json')).map((p) => {
    const o = overrides[p.id] || {};
    const merged = { ...p };
    for (const k of EDITABLE) if (k in o) merged[k] = o[k];
    return merged;
  });
}

function saveOverrides(next) {
  fs.mkdirSync(dataDir, { recursive: true });
  const tmp = OVERRIDES + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(next, null, 2));
  fs.renameSync(tmp, OVERRIDES);
}

module.exports = { readSite, readProducts, readOverrides, saveOverrides, EDITABLE };
