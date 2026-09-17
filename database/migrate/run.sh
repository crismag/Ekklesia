#!/usr/bin/env bash
# Build the member database from the two legacy databases.
#
#   database/migrate/run.sh [members_db] [crm_db] [portal_db]
#
# Drops and recreates members_db (never the legacy databases). The mysql
# client authenticates through MYSQL (default "mysql"), e.g.
#   MYSQL="sudo -n mysql" database/migrate/run.sh
#   MYSQL="mysql --defaults-extra-file=/path/to/admin.cnf" database/migrate/run.sh
# RELATED_FAMILIES points at the portal's related-families.json (default: this checkout's).
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
MEMBERS="${1:-christlikeness_members}"
CRM="${2:-u471078694_churchcrm_v0}"
PORTAL="${3:-u471078694_christlike_mdb}"
RELATED_FAMILIES="${RELATED_FAMILIES:-$here/../config/related-families.json}"
MYSQL="${MYSQL:-mysql}"

for name in "$MEMBERS" "$CRM" "$PORTAL"; do
  [[ "$name" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Invalid database name: $name" >&2; exit 1; }
done
[[ "$MEMBERS" != "$CRM" && "$MEMBERS" != "$PORTAL" ]] || { echo "The member database must be a new database." >&2; exit 1; }

echo "Recreating $MEMBERS"
$MYSQL -e "DROP DATABASE IF EXISTS \`$MEMBERS\`; CREATE DATABASE \`$MEMBERS\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
$MYSQL "$MEMBERS" < "$here/members/001_schema.sql"

echo "Copying records from $CRM and $PORTAL"
sed -e "s/{{MEMBERS}}/$MEMBERS/g" -e "s/{{CRM}}/$CRM/g" -e "s/{{PORTAL}}/$PORTAL/g" \
  "$here/migrate/members_from_legacy.sql" | $MYSQL

php "$here/migrate/household_links_from_json.php" "$RELATED_FAMILIES" | $MYSQL "$MEMBERS"

$MYSQL "$MEMBERS" < "$here/members/002_sheet_views.sql"

echo "Verifying"
sed -e "s/{{MEMBERS}}/$MEMBERS/g" -e "s/{{CRM}}/$CRM/g" -e "s/{{PORTAL}}/$PORTAL/g" \
  "$here/verify/members.sql" | $MYSQL --table
