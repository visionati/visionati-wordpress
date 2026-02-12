#!/bin/sh
set -e

echo "Waiting for database..."
while ! nc -z db 3306 2>/dev/null; do
  sleep 1
done
echo "Waiting for WordPress to initialize..."
while [ ! -f /var/www/html/wp-config.php ]; do
  sleep 2
done
sleep 5
echo "Ready."

echo "Installing WordPress..."
wp core install \
  --url=http://localhost:9090 \
  --title="Visionati Dev" \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=dev@visionati.com \
  --skip-email

echo "Activating Visionati plugin..."
wp plugin activate visionati

echo "Installing and activating WooCommerce..."
wp plugin install woocommerce --activate

echo "Setting permalinks..."
wp option update permalink_structure "/%postname%/"

echo "Creating sample product with image..."
wp media import https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=800 \
  --title="Sample Product Image" \
  --porcelain > /tmp/img_id.txt 2>/dev/null || true

if [ -s /tmp/img_id.txt ]; then
  IMG_ID=$(cat /tmp/img_id.txt)
  PRODUCT_ID=$(wp post create \
    --post_type=product \
    --post_title="Sample Product" \
    --post_status=publish \
    --porcelain)
  wp post meta update "$PRODUCT_ID" _thumbnail_id "$IMG_ID"
  wp post meta update "$PRODUCT_ID" _regular_price "29.99"
  wp post meta update "$PRODUCT_ID" _price "29.99"
  echo "Created sample product #$PRODUCT_ID with image #$IMG_ID"
else
  echo "Skipped sample product (image import failed, not critical)"
fi

echo ""
echo "========================================"
echo "  WordPress ready at http://localhost:9090"
echo "  Admin: http://localhost:9090/wp-admin"
echo "  User: admin / Password: admin"
echo "  WooCommerce + Visionati activated"
echo "  Debug logging enabled"
echo "========================================"