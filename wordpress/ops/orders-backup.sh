#!/bin/bash
# neemveda: order backup every 5 minutes (run from /etc/cron.d/neemveda).
#
# Saves the order tables (orders, items, addresses, notes, tracking, customers) to
#   /var/backups/neemveda-orders/orders-YYYY-MM-DD_HHMM.sql.gz   (restorable SQL)
#   /var/backups/neemveda-orders/orders-latest.csv               (opens in Excel: one row per order)
# A new file is written only when order data has changed since the last run, so quiet hours
# use no space. Snapshots are kept 7 days; the newest one is always kept.
#
# Restore the order tables from a snapshot (replaces the current order tables!):
#   gunzip -c FILE.sql.gz | docker compose -f /opt/neemveda/wordpress/docker-compose.yml exec -T db \
#     sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" neemveda'
set -euo pipefail

COMPOSE_DIR=/opt/neemveda/wordpress
OUT=/var/backups/neemveda-orders
KEEP_DAYS=7
TABLES="wp_wc_orders wp_wc_orders_meta wp_wc_order_addresses wp_wc_order_operational_data
        wp_woocommerce_order_items wp_woocommerce_order_itemmeta wp_wc_order_stats
        wp_wc_order_product_lookup wp_wc_order_coupon_lookup wp_wc_order_tax_lookup
        wp_wc_customer_lookup wp_comments wp_commentmeta wp_users wp_usermeta"

install -d -m 700 "$OUT"
exec 9>"$OUT/.lock"
flock -n 9 || exit 0   # previous run still busy

# Don't make a full disk worse.
FREE_MB=$(df -Pm "$OUT" | awk 'NR==2 {print $4}')
if [ "$FREE_MB" -lt 500 ]; then
  echo "$(date '+%F %T') orders-backup SKIPPED: only ${FREE_MB} MB free on disk" >&2
  exit 1
fi

cd "$COMPOSE_DIR"
TMP="$OUT/.current.sql"
# --skip-dump-date / --skip-comments: identical data gives an identical file, so changes can be detected.
docker compose exec -T db sh -c "mariadb-dump -uroot -p\"\$MARIADB_ROOT_PASSWORD\" --single-transaction --skip-dump-date --skip-comments neemveda $(echo $TABLES)" </dev/null > "$TMP"
grep -q 'CREATE TABLE `wp_wc_orders`' "$TMP" || { echo "$(date '+%F %T') orders-backup FAILED: dump incomplete" >&2; rm -f "$TMP"; exit 1; }

NEW_SUM=$(sha256sum "$TMP" | cut -d' ' -f1)
OLD_SUM=$(cat "$OUT/.last.sha256" 2>/dev/null || true)
if [ "$NEW_SUM" = "$OLD_SUM" ]; then
  rm -f "$TMP"
  exit 0   # nothing changed since the last snapshot
fi

STAMP=$(date +%F_%H%M)
gzip -c "$TMP" > "$OUT/orders-$STAMP.sql.gz.part"
gzip -t "$OUT/orders-$STAMP.sql.gz.part"
mv "$OUT/orders-$STAMP.sql.gz.part" "$OUT/orders-$STAMP.sql.gz"
chmod 600 "$OUT/orders-$STAMP.sql.gz"
echo "$NEW_SUM" > "$OUT/.last.sha256"
rm -f "$TMP"

# Human-readable copy of every order (latest only).
docker compose run --rm --no-deps -T cli wp eval '
$out = fopen("php://stdout", "w");
fputcsv($out, ["Order", "Date", "Status", "Payment", "Name", "Phone", "Email", "Address", "City", "State", "PIN", "Items", "Total", "Courier", "Tracking no", "Expected delivery", "Map pin"]);
foreach (wc_get_orders(["limit" => -1, "orderby" => "date", "order" => "DESC"]) as $o) {
  if (!$o instanceof WC_Order) continue;
  $items = [];
  foreach ($o->get_items() as $i) $items[] = $i->get_quantity() . " x " . $i->get_name();
  $lat = $o->get_meta("_nv_lat"); $lng = $o->get_meta("_nv_lng");
  fputcsv($out, [$o->get_order_number(), $o->get_date_created() ? $o->get_date_created()->date("Y-m-d H:i") : "", $o->get_status(),
    $o->get_payment_method_title(), trim($o->get_billing_first_name() . " " . $o->get_billing_last_name()), $o->get_billing_phone(),
    $o->get_billing_email(), trim($o->get_billing_address_1() . " " . $o->get_billing_address_2()), $o->get_billing_city(),
    $o->get_billing_state(), $o->get_billing_postcode(), implode("; ", $items), $o->get_total(),
    $o->get_meta("_nv_courier"), $o->get_meta("_nv_tracking_no"), $o->get_meta("_nv_expected"),
    ($lat !== "" && $lng !== "") ? "https://www.google.com/maps?q=$lat,$lng" : ""]);
}' </dev/null 2>/dev/null | grep -v 'Container .* \(Creating\|Created\)' > "$OUT/orders-latest.csv.part" \
  && mv "$OUT/orders-latest.csv.part" "$OUT/orders-latest.csv" && chmod 600 "$OUT/orders-latest.csv" || true

# Keep 7 days, but never delete the newest snapshot.
NEWEST=$(ls -1t "$OUT"/orders-*.sql.gz | head -1)
find "$OUT" -maxdepth 1 -name 'orders-*.sql.gz' -mtime +"$KEEP_DAYS" ! -path "$NEWEST" -delete

echo "$(date '+%F %T') orders-backup saved orders-$STAMP.sql.gz ($(du -h "$OUT/orders-$STAMP.sql.gz" | cut -f1))"
