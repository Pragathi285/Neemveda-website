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
4. You and the customer get an email. The order appears in `/admin`. When you mark it **Shipped** (with optional tracking), the customer is emailed.

Cash on Delivery orders skip payment and are saved as **Confirmed**.

## What to edit

| What | Where |
|---|---|
| Prices, packs, product text | `src/data/products.json` |
| Contact details, shipping rules, COD on/off | `src/data/site.json` |
| Page content | `src/pages/*.html`, product page: `src/templates/product.html` |
| Header / footer / newsletter | `src/partials/` |
| Colours, fonts, spacing | `src/assets/css/styles.css` |
| Backend | `server/` |

Orders, subscribers and messages are stored as JSON in `data/`. That folder holds customer details: keep it private and back it up. For high volume, move to a database.

Search the project for `[PLACEHOLDER]` to find details that still need real information.
