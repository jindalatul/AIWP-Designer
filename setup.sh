#!/usr/bin/env bash
# One-shot setup: boot WordPress, install wp-cli, install ACF + AIWP Designer,
# create an MCP token and print the connect command.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$HERE"

SITE_URL="http://localhost:8090"
ADMIN_USER="admin"
ADMIN_PASS="admin"
ADMIN_EMAIL="admin@example.com"

say() { printf "\n\033[1;34m==> %s\033[0m\n" "$1"; }

say "Starting containers"
docker compose up -d

say "Waiting for WordPress to answer"
for i in $(seq 1 60); do
  if curl -fsS -o /dev/null "$SITE_URL"; then break; fi
  sleep 2
done

say "Installing wp-cli inside the container"
docker compose exec -T -u root wordpress bash -lc '
  set -e
  if ! command -v wp >/dev/null 2>&1; then
    curl -sSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
    chmod +x /usr/local/bin/wp
  fi
  wp --info --allow-root >/dev/null
'

say "Allowing the Authorization header through Apache (MCP bearer tokens)"
docker compose exec -T -u root wordpress bash -lc '
  set -e
  a2enmod rewrite headers >/dev/null 2>&1 || true
  cat > /etc/apache2/conf-available/aiwp-auth.conf <<EOF
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=\$1
<Directory /var/www/html>
  AllowOverride All
</Directory>
EOF
  a2enconf aiwp-auth >/dev/null 2>&1 || true
  apache2ctl -k graceful || service apache2 reload || true
'

say "Installing WordPress"
docker compose exec -T -u www-data wordpress bash -lc "
  set -e
  if ! wp core is-installed 2>/dev/null; then
    wp core install \
      --url='$SITE_URL' \
      --title='AIWP Test Site' \
      --admin_user='$ADMIN_USER' \
      --admin_password='$ADMIN_PASS' \
      --admin_email='$ADMIN_EMAIL' \
      --skip-email
  fi
  wp rewrite structure '/%postname%/' --hard
  wp rewrite flush --hard
"

say "Installing Advanced Custom Fields (free) and activating both plugins"
docker compose exec -T -u www-data wordpress bash -lc '
  set -e
  wp plugin is-installed advanced-custom-fields || wp plugin install advanced-custom-fields
  wp plugin activate advanced-custom-fields
  wp plugin activate aiwp-designer
  wp plugin list --status=active --field=name
'

say "Generating an MCP token"
TOKEN=$(docker compose exec -T -u www-data wordpress bash -lc '
  wp eval "
    \$provider = new \\AIWP\\Designer\\MCP\\Auth\\TokenAuthProvider();
    \$user = get_user_by( \"login\", \"admin\" );
    \$result = \$provider->create( \$user->ID, \"Claude Code\" );
    echo \$result[\"token\"];
  "
' | tr -d '\r')

echo "$TOKEN" > "$HERE/.mcp-token"

say "Done"
cat <<EOF

  Site        : $SITE_URL
  Admin       : $SITE_URL/wp-admin  ($ADMIN_USER / $ADMIN_PASS)
  MCP endpoint: $SITE_URL/wp-json/aiwp-designer/v1/mcp
  MCP token   : $TOKEN   (also saved in aiwp/.mcp-token)

  Connect Claude Code:

    claude mcp add --transport http aiwp $SITE_URL/wp-json/aiwp-designer/v1/mcp \\
      --header "Authorization: Bearer $TOKEN"

EOF
