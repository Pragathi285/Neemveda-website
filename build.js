#!/usr/bin/env node
/*
 * neemveda static site builder (zero dependencies).
 *
 *   node build.js          -> builds src/ into dist/
 *
 * Templating:
 *   {{> name}}       include src/partials/name.html
 *   {{ path.to.x }}  HTML-escaped value from the render context
 *   {{{ path }}}     raw (unescaped) value
 * Pages in src/pages/*.html start with a front-matter block (--- key: value ---)
 * and are wrapped in src/partials/layout.html.
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { readProducts } = require('./server/catalog');

const ROOT = __dirname;
const SRC = path.join(ROOT, 'src');
const OUT = path.join(ROOT, 'dist');

const read = (p) => fs.readFileSync(p, 'utf8');
const write = (p, s) => {
  fs.mkdirSync(path.dirname(p), { recursive: true });
  fs.writeFileSync(p, s);
};

function copyDir(from, to) {
  fs.mkdirSync(to, { recursive: true });
  for (const entry of fs.readdirSync(from, { withFileTypes: true })) {
    const a = path.join(from, entry.name);
    const b = path.join(to, entry.name);
    if (entry.isDirectory()) copyDir(a, b);
    else fs.copyFileSync(a, b);
  }
}

const escapeHtml = (v) =>
  String(v)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

const jsonForScript = (obj) => JSON.stringify(obj, null, 2).replace(/</g, '\\u003c');

const lookup = (ctx, key) =>
  key.split('.').reduce((o, k) => (o == null ? undefined : o[k]), ctx);

function render(tpl, ctx, partials, file) {
  let out = tpl;
  for (let i = 0; i < 6 && out.includes('{{>'); i++) {
    out = out.replace(/\{\{>\s*([\w-]+)\s*\}\}/g, (_, name) => {
      if (!(name in partials)) throw new Error(`${file}: unknown partial "${name}"`);
      return partials[name];
    });
  }
  out = out.replace(/\{\{\{\s*([\w.-]+)\s*\}\}\}/g, (_, key) => {
    const v = lookup(ctx, key);
    if (v === undefined) throw new Error(`${file}: missing value {{{${key}}}}`);
    return String(v);
  });
  out = out.replace(/\{\{\s*([\w.-]+)\s*\}\}/g, (_, key) => {
    const v = lookup(ctx, key);
    if (v === undefined) throw new Error(`${file}: missing value {{${key}}}`);
    return escapeHtml(v);
  });
  return out;
}

function parseFrontMatter(src) {
  const m = src.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n?/);
  if (!m) return { meta: {}, body: src };
  const meta = {};
  for (const line of m[1].split(/\r?\n/)) {
    const i = line.indexOf(':');
    if (i > 0) meta[line.slice(0, i).trim()] = line.slice(i + 1).trim();
  }
  return { meta, body: src.slice(m[0].length) };
}

const formatPrice = (n) => (n == null ? '₹[XX]' : '₹' + Number(n).toLocaleString('en-IN'));

function productCard(p) {
  const cta = p.available
    ? `<button class="btn btn-primary btn-block" type="button" data-add-to-cart="${p.id}">Add to cart</button>`
    : `<span class="btn btn-ghost btn-block is-disabled" aria-disabled="true">Coming soon</span>`;
  return `
      <article class="card product-card reveal" data-product="${p.id}">
        <a class="product-card__media" href="${p.url}" tabindex="-1" aria-hidden="true">
          <img src="${p.image}" alt="" width="420" height="520" loading="lazy" decoding="async">
          ${p.badge ? `<span class="tag ${p.available ? '' : 'tag--muted'}">${escapeHtml(p.badge)}</span>` : ''}
        </a>
        <div class="product-card__body">
          <h3 class="product-card__title"><a href="${p.url}">${escapeHtml(p.name)}</a></h3>
          <p class="product-card__text">${escapeHtml(p.short)}</p>
          <p class="price">${p.priceDisplay}${p.price == null ? ' <span class="placeholder">[PLACEHOLDER]</span>' : ''}</p>
          ${cta}
        </div>
      </article>`;
}

function productJsonLd(p, site) {
  const data = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: p.fullName,
    description: p.description,
    image: site.url + p.image,
    sku: p.id,
    brand: { '@type': 'Brand', name: site.name },
  };
  if (p.price != null) {
    data.offers = {
      '@type': 'Offer',
      priceCurrency: 'INR',
      price: String(p.price),
      availability: p.available ? 'https://schema.org/InStock' : 'https://schema.org/PreOrder',
      url: site.url + p.url,
    };
  }
  return jsonForScript(data);
}

function build() {
  const t0 = Date.now();
  const site = JSON.parse(read(path.join(SRC, 'data/site.json')));
  const products = readProducts().map((p) => ({
    ...p,
    url: `/products/${p.slug}.html`,
    image: `/assets/img/box-${p.count}.svg`,
    priceDisplay: formatPrice(p.price),
    // raw HTML snippets for templates
    priceTag: p.price == null ? '<span class="placeholder">[PLACEHOLDER]</span>' : '',
    mrpHtml: p.mrp == null ? '' : `<span class="price__mrp"><span class="sr-only">MRP </span>${formatPrice(p.mrp)}</span>`,
  }));
  const featured = products.find((p) => p.featured) || products[0];

  if (fs.rmSync) fs.rmSync(OUT, { recursive: true, force: true });
  else if (fs.existsSync(OUT)) fs.rmdirSync(OUT, { recursive: true }); // Node < 14.14
  copyDir(path.join(SRC, 'assets'), path.join(OUT, 'assets'));

  const partials = {};
  for (const f of fs.readdirSync(path.join(SRC, 'partials'))) {
    partials[path.basename(f, '.html')] = read(path.join(SRC, 'partials', f));
  }

  // Product box artwork, one per pack size.
  const boxTpl = read(path.join(SRC, 'templates/box.svg'));
  for (const count of new Set(products.map((p) => p.count))) {
    write(path.join(OUT, `assets/img/box-${count}.svg`), boxTpl.replace(/\{\{count\}\}/g, count));
  }

  // Data for the browser (cart, checkout).
  const publicSite = {
    name: site.name,
    email: site.email,
    shipping: site.shipping,
    payments: site.payments,
    forms: site.forms,
  };
  const publicProducts = products.map(({ id, name, url, image, price, mrp, available, count }) => ({
    id, name, url, image, price, mrp, available, count,
  }));
  write(
    path.join(OUT, 'assets/js/data.js'),
    `/* Generated by build.js from src/data. Do not edit. */\nwindow.NV = ${JSON.stringify({ site: publicSite, products: publicProducts }, null, 2)};\n`
  );

  const baseCtx = {
    site,
    featured,
    products,
    year: new Date().getFullYear(),
    productGrid: products.map(productCard).join('\n'),
    shippingFreeAbove: formatPrice(site.shipping.freeAbove),
  };

  // Cache-busting: link CSS/JS as file.css?v=<content hash> so browsers fetch new code after each update.
  const versions = {};
  for (const rel of ['assets/css/styles.css', 'assets/js/main.js', 'assets/js/data.js']) {
    versions[rel] = crypto.createHash('md5').update(fs.readFileSync(path.join(OUT, rel))).digest('hex').slice(0, 8);
  }

  const pages = [];
  const renderPage = (srcFile, outRel, extraCtx = {}) => {
    const { meta: rawMeta, body } = parseFrontMatter(read(srcFile));
    const ctx = { ...baseCtx, ...extraCtx };
    const meta = {};
    for (const [k, v] of Object.entries(rawMeta)) meta[k] = render(v, ctx, partials, srcFile);
    const pagePath = '/' + outRel.replace(/\\/g, '/').replace(/^index\.html$/, '');
    const page = {
      title: meta.title || `${site.name} – ${site.tagline}`,
      description: meta.description || '',
      id: meta.page || '',
      canonical: site.url + pagePath,
      ogImage: site.url + (meta.ogImage || '/assets/img/og-image.jpg'),
      ogType: meta.ogType || 'website',
      robots: meta.noindex === 'true' ? 'noindex, follow' : 'index, follow',
    };
    const pageCtx = { ...ctx, page };
    const content = render(body, pageCtx, partials, srcFile);
    let html = render(partials.layout, { ...pageCtx, content }, partials, srcFile);
    // Rewrite root-absolute links ("/shop.html") to relative ones ("shop.html" or "../shop.html")
    // so the site also works when opened straight from disk (file://) or from a sub-folder.
    // 404.html is served for missing URLs at any depth, so it keeps root-absolute links.
    const base = outRel === '404.html' ? '/' : '../'.repeat(outRel.replace(/\\/g, '/').split('/').length - 1);
    html = html
      .replace(/(href|src|data-src)="\/(?!\/)([^"]*)"/g, (_, attr, rest) => {
        let target = rest;
        if (target === '' || target.startsWith('#') || target.startsWith('?')) target = 'index.html' + target;
        return `${attr}="${base}${target}"`;
      })
      .replace('<script>document.documentElement', `<script>window.NV_BASE=${JSON.stringify(base)};document.documentElement`)
      .replace(/((?:href|src)="[^"]*?)(assets\/(?:css\/styles\.css|js\/main\.js|js\/data\.js))"/g,
        (_, pre, rel) => `${pre}${rel}?v=${versions[rel]}"`);
    if (page.id) {
      html = html.replace(
        new RegExp(`data-nav="${page.id}"`, 'g'),
        `data-nav="${page.id}" aria-current="page"`
      );
    }
    write(path.join(OUT, outRel), html);
    if (meta.noindex !== 'true') pages.push(pagePath);
  };

  for (const f of fs.readdirSync(path.join(SRC, 'pages'))) {
    if (f.endsWith('.html')) renderPage(path.join(SRC, 'pages', f), f);
  }

  const productTpl = path.join(SRC, 'templates/product.html');
  for (const product of products) {
    const related = products.filter((p) => p.id !== product.id).map(productCard).join('\n');
    renderPage(productTpl, `products/${product.slug}.html`, {
      product: { ...product, jsonLd: productJsonLd(product, site) },
      relatedGrid: related,
    });
  }

  write(
    path.join(OUT, 'sitemap.xml'),
    `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${pages
      .filter((p) => p !== '/404.html')
      .map((p) => `  <url><loc>${site.url}${p}</loc></url>`)
      .join('\n')}\n</urlset>\n`
  );
  write(
    path.join(OUT, 'robots.txt'),
    `User-agent: *\nDisallow: /cart.html\nDisallow: /checkout.html\n\nSitemap: ${site.url}/sitemap.xml\n`
  );

  console.log(`[build] ${pages.length} indexable pages written to dist/ in ${Date.now() - t0}ms`);
}

module.exports = { build };
if (require.main === module) {
  try {
    build();
  } catch (err) {
    console.error('[build] failed:', err.message);
    process.exit(1);
  }
}
