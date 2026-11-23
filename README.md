# neemveda – WordPress + WooCommerce shop

**The live shop runs on WordPress + WooCommerce** (folder [`wordpress/`](wordpress)). The Node.js site further down this file is the earlier version, kept for reference.

## What's where
| Part | Where |
|---|---|
| Theme (design, home page, shop/cart/checkout styling) | `wordpress/wp-content/themes/neemveda` |
| neemveda features plugin | `wordpress/wp-content/plugins/neemveda-core` |
| Server setup (WordPress + MariaDB in Docker, behind Traefik) | `wordpress/docker-compose.yml`, `wordpress/.env.example` |

**WooCommerce** gives: products, cart, checkout, **Cash on Delivery**, customer **sign up / log in**, **order history** (My account → Orders), stock, coupons, reports, order emails.
**neemveda-core** adds:
- **OTP verification** – email OTP at sign up (always on); phone/SMS OTP at sign up and checkout once MSG91 or 2Factor is filled in (WooCommerce → neemveda settings). Customers can create a profile or order as guests (WooCommerce → neemveda settings → Accounts; turn guest checkout off to require a profile).
- **Welcome popup** – new visitors see "Create your profile" (name, mobile, email + OTP, password) or "Continue as guest". Guests aren't asked again for 30 days; it never shows to logged-in customers or on cart, checkout and My account.
- **Delivery location pin** – a map at checkout (OpenStreetMap); the pin shows on the admin order page and in the new-order email with a Google Maps link.
- **Order tracking** – statuses Confirmed → Packed → Shipped → Out for delivery → Delivered, courier / tracking number / link, expected delivery date and location updates on the order page; emails at each step, a reminder the day before delivery, a timeline in My account and a **Track order** page.
- **Notifications** – admin new-order alerts (sound + browser notification + "New orders" badge), browser push to customers via OneSignal (add App ID + REST key in settings), and an **announcement bar**.
- Contact form (messages under WooCommerce → Messages), newsletter list, "Buy now".

Contact email and social links: Appearance → Customize → *neemveda: contact & social*.

## Run on a server
```bash
cd wordpress && cp .env.example .env   # fill in DB passwords and the Brevo SMTP key
docker compose up -d
docker compose run --rm cli wp core install --url=https://YOUR-DOMAIN --title=neemveda --admin_user=ADMIN --admin_email=EMAIL --prompt=admin_password
docker compose run --rm cli wp plugin install woocommerce --activate
docker compose run --rm cli wp plugin activate neemveda-core
docker compose run --rm cli wp theme activate neemveda
docker compose run --rm cli wp neemveda setup --admin-email=EMAIL
```
`wp neemveda setup` creates the pages, the 3 neem stick packs (sample prices – change them in Products), Cash on Delivery, ₹50 delivery (free from ₹499) and the account settings. It is safe to run again.

## Moving to another domain
```bash
docker compose run --rm cli wp search-replace https://OLD-DOMAIN https://NEW-DOMAIN --all-tables
```

## Before going live (checklist)
Business details only the owner can give (search the pages for `[PLACEHOLDER`):
- [ ] **Terms & conditions** and **Privacy policy** pages: legal business name, registered address, GSTIN (if registered), grievance officer name + contact, city for jurisdiction, "last updated" date. Have them checked by a professional.
- [ ] **Contact page / footer**: full business address (the Contact page now just says "India") – required for Indian e-commerce rules.
- [ ] **Products**: real prices and MRP (setup uses sample prices), net quantity, and **real photos of your own packs** (the current photos are Pixabay stock).
- [ ] **Email**: send from an address on your own domain (e.g. `orders@your-domain`), verify that domain in Brevo and add its SPF, DKIM and DMARC records. Sending "from" a @gmail.com address through Brevo lands in spam. Set `MAIL_FROM` and `SMTP_*` in `.env`, then place a test order and check the emails arrive.
- [ ] **Phone OTP**: guest checkout + Cash on Delivery invites fake orders. Turn on SMS OTP (MSG91 or 2Factor, needs DLT registration) in neemveda settings.
- [ ] Change the admin password to a long unique one; give staff the *Shop manager* role, not Administrator.

Server (run once):
- [ ] **Real cron** instead of visitor-triggered WP-Cron (delivery reminders and WooCommerce jobs). Add `define('DISABLE_WP_CRON', true);` to `WORDPRESS_CONFIG_EXTRA` and a host crontab line:
  ```
  */5 * * * * cd /opt/neemveda/wordpress && docker compose run --rm cli wp cron event run --due-now >/dev/null 2>&1
  ```
- [ ] **Nightly backups**, copied off the server (and test a restore once):
  ```
  15 2 * * * cd /opt/neemveda/wordpress && docker compose exec -T db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction neemveda' | gzip > /var/backups/neemveda-db-$(date +\%F).sql.gz
  30 2 * * * docker run --rm -v neemveda_wp:/wp:ro -v /var/backups:/out alpine tar czf /out/neemveda-files-$(date +\%F).tgz -C /wp wp-content/uploads
  45 2 * * * find /var/backups -name 'neemveda-*' -mtime +14 -delete
  ```
  Restore: `gunzip -c FILE.sql.gz | docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" neemveda'`.
- [ ] **Traefik**: HTTPS redirect, HSTS and security headers (`X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options: SAMEORIGIN`) on the neemveda router.
- [ ] **Uptime monitor** (e.g. UptimeRobot, free) on the home page and `/wp-login.php`.
- [ ] **Updates**: before updating WordPress / WooCommerce, take a backup; keep a note of the git commit that's live so you can roll back.
- [ ] After going live: submit `https://YOUR-DOMAIN/wp-sitemap.xml` in Google Search Console.

## Server operations (`wordpress/ops/`)
What runs on the live server (neemveda.in on 103.190.92.248), kept here so a new server can be set up the same way:
| File | Installed at | What it does |
|---|---|---|
| `ops/orders-backup.sh` | `/opt/neemveda-ops/` | **Every 5 minutes**: if any order or customer data changed, saves the order tables to `/var/backups/neemveda-orders/orders-DATE_TIME.sql.gz` (kept 7 days) and `orders-latest.csv` (opens in Excel) |
| `ops/backup.sh` | `/opt/neemveda-ops/` | **Nightly 02:15**: full database + uploaded files to `/var/backups/neemveda-*`, kept 14 days |
| `ops/neemveda.cron` | `/etc/cron.d/neemveda` | Runs both, plus WordPress scheduled jobs every 5 minutes |
| `ops/traefik/neemveda-in.yml`, `ops/traefik/neemveda.yml` | Traefik dynamic config folder | Routes `neemveda.in`, `www.neemveda.in` and the old `neemveda.indobase.in` to WordPress with security headers and Let's Encrypt certificates |
| `ops/htaccess-redirects.conf` | Top of WordPress's `.htaccess` | The **301** redirects (path + query kept): `neemveda.indobase.in`, `www.neemveda.in` and `http://neemveda.in` → `https://neemveda.in`. Done in Apache because Traefik 3.3 only sends 308. |

Log: `/var/log/neemveda-ops.log`. **These backups are on the same server**; also copy them somewhere else (another server or cloud storage) so a server failure can't take the backups with it.

## Checking the shop
`wordpress/tests/neemveda-tests.php` checks setup, OTP, sign up, checkout, emails, tracking and security (it cleans up after itself):
```bash
docker compose run --rm -v "$PWD/tests:/tests:ro" cli wp eval-file /tests/neemveda-tests.php
```

---

# neemveda – Natural Oral Care website

Mobile-first shop with a small Node.js backend: Razorpay payments (UPI, cards, netbanking), Cash on Delivery, saved orders, email alerts, newsletter and contact form, and a password-protected admin page.

## Run it

```bash
npm install
```

```bash
npm run dev
```

Open http://localhost:4173. The site rebuilds automatically when you edit files in `src/`.

For production, run `npm start` on a host that runs Node.js (Render, Railway, a VPS, Hostinger VPS / Node hosting).

## First-time setup

1. Copy `.env.example` to `.env`.
2. **Razorpay:** in the Razorpay Dashboard, generate **Test Mode** API keys and fill in `RAZORPAY_KEY_ID` and `RAZORPAY_KEY_SECRET`. After going live, add a webhook to `https://YOUR-DOMAIN/api/payments/webhook` (events `payment.captured`, `order.paid`) and fill in `RAZORPAY_WEBHOOK_SECRET`. Switch to Live keys once test payments work.
3. **Email:** for neemveda0@gmail.com, turn on 2-Step Verification, create an App Password, and put it in `SMTP_PASS`.
4. **Admin:** set a strong `ADMIN_PASSWORD`, restart, and open `/admin`.

Check what's configured at `/api/health`. Never share `.env` or put the secret keys in chat or in `src/`.

## How an order works

1. The customer fills the checkout form. The browser sends the cart and address to `POST /api/orders`.
2. The server recalculates prices from `src/data/products.json` (the browser's prices are never trusted), saves the order, and creates a Razorpay order.
3. Razorpay Checkout opens. After payment, the server verifies the Razorpay signature (`/api/payments/verify`) and marks the order **Paid**. The webhook does the same if the customer closes the window early.
4. The order appears in `/admin` straight away: the panel checks every 10 seconds and plays a sound and shows a notification for each new order. If email is set up, you and the customer also get an email. When you mark it **Shipped** (with optional tracking), the customer is emailed.
5. On the confirmation screen the customer can send the full order to your WhatsApp with one tap, and later check its status at `/track.html` with the order number and phone number.

## Customer emails

Sent automatically once email is set up (`SMTP_PASS` in `.env`), and logged on each order page as sent / not sent:

| When | Email |
|---|---|
| Order placed (COD) or paid online | "Order placed successfully" with items, total and expected delivery window |
| Status set to Packed / Shipped / Out for delivery / Delivered / Cancelled | One email for each |
| Expected delivery date set or changed | "Delivery date for your order" |
| Day before the expected delivery date (from 9 am IST) | "Arriving tomorrow" reminder (once) |
| Location update added | "Update on your order" |

Untick "Email the customer about this update" on the order page to skip an email. Each stage also has a WhatsApp button on the order page that opens WhatsApp with the same message ready to send.

## Admin panel (`/admin`)

- **Log in** with `ADMIN_USER` / `ADMIN_PASSWORD` from `.env` (you stay logged in for 7 days on that device; changing the password logs everyone out).
- **Orders:** new-order alerts (tap “Turn on new-order alerts” once per device), search, status filter, today's orders, COD amount to collect, CSV download.
- **Order page:** items and totals, customer contact (call / WhatsApp with ready messages), status (Confirmed → Packed → Shipped → Delivered), tracking number, private notes, history, printable packing slip.
- **Delivery updates:** on the order page set the expected delivery date and add location updates ("Reached Chennai hub"). Customers see them on the Track order page; the orders list shows where each parcel is, plus an "Orders by place" summary.
- **Products:** change price, MRP, stock and badge. Saved to `data/product-overrides.json` (not in git) and the site updates immediately.
- **Newsletter** and **Messages** from the website forms.

Cash on Delivery orders skip payment and are saved as **Confirmed**.

## What to edit

| What | Where |
|---|---|
| Prices, MRP, stock | Admin panel → Products (defaults in `src/data/products.json`) |
| Pack names, product text | `src/data/products.json` |
| Contact details, shipping rules, COD on/off | `src/data/site.json` |
| Page content | `src/pages/*.html`, product page: `src/templates/product.html` |
| Header / footer / newsletter | `src/partials/` |
| Colours, fonts, spacing | `src/assets/css/styles.css` |
| Backend | `server/` |

Orders, subscribers and messages are stored as JSON in `data/`. That folder holds customer details: keep it private and back it up. For high volume, move to a database.

Search the project for `[PLACEHOLDER]` to find details that still need real information.
