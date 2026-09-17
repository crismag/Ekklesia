#!/usr/bin/env bash
#
# Deploy the portal, migrations included.
#
# The migration step used to be a line in a README that somebody had to
# remember. That has now failed three times: 007 was never applied anywhere and
# the member import broke the moment application code stopped creating its own
# tables; 009 arrived without its column and the calendar rendered no events at
# all, silently, for as long as it took someone to notice; 010 came within one
# command of the same thing. A step that is only documented is not a step.
#
# Stages, in this order and no other:
#
#   1. verify locally   — the suites must be green before anything leaves here
#   2. announce         — put the site behind a maintenance gate
#   3. sync             — rsync the tree (never --delete)
#   4. migrate          — apply pending migrations; STOP if any fails
#   5. release          — lift the gate
#   6. smoke            — confirm the site answers
#
# Stage 2 exists because rsync overwrites in place: without it, between stages
# 3 and 4 the site runs new code against old schema. Stage 5 runs only if 4
# succeeded, so a failed migration leaves the gate up rather than exposing a
# half-migrated site — and the gate expires on its own, so a dead deploy cannot
# keep the site dark.
#
# Usage:
#   tools/deploy.sh                 verify, sync, migrate, release
#   tools/deploy.sh --dry-run       show what would sync and what would migrate
#   tools/deploy.sh --skip-tests    sync without the local gate (say why)
#
set -Eeuo pipefail

REMOTE="${PORTAL_DEPLOY_REMOTE:-Hostinger}"
REMOTE_PATH="${PORTAL_DEPLOY_PATH:-/home/u471078694/domains/crishub.com/public_html/christlikeness/church_portal}"
SMOKE_URL="${PORTAL_SMOKE_URL:-https://christlikeness.crishub.com/church_portal}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DRY_RUN=0
SKIP_TESTS=0
for arg in "$@"; do
  case "$arg" in
    --dry-run)    DRY_RUN=1 ;;
    --skip-tests) SKIP_TESTS=1 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31mDEPLOY FAILED: %s\033[0m\n' "$*" >&2; }

GATE_UP=0
cleanup() {
  local code=$?
  if [ "$code" -ne 0 ]; then
    fail "stage exited $code"
    if [ "$GATE_UP" -eq 1 ]; then
      cat >&2 <<'MSG'

  The maintenance gate is still up on the server, deliberately: the release did
  not complete, and lifting it would expose code whose migration may not have
  run. It expires by itself within 15 minutes.

  To inspect:   ssh REMOTE 'cd PATH && php tools/migrate.php --status'
  To lift now:  ssh REMOTE 'rm -f PATH/storage/maintenance.flag'
MSG
    fi
  fi
}
trap cleanup EXIT

# ---------------------------------------------------------------- 1. verify
if [ "$SKIP_TESTS" -eq 1 ]; then
  say "1/6 local verification SKIPPED (--skip-tests)"
else
  say "1/6 verifying locally"
  php tests/Regression/run.php        > /dev/null || { fail "regression suite is red"; exit 1; }
  php tests/Regression/schedule-event-scope.php > /dev/null || { fail "schedule event-scope regression is red"; exit 1; }
  php tests/Architecture/BoundaryTest.php > /dev/null || { fail "architecture boundaries violated"; exit 1; }
  php tools/check-contrast.php        > /dev/null || { fail "contrast check failed"; exit 1; }
  php tools/check-privacy.php         > /dev/null || { fail "privacy check failed"; exit 1; }
  if command -v node > /dev/null 2>&1; then
    node tests/Regression/source-contracts.mjs > /dev/null || { fail "source contracts failed"; exit 1; }
  fi
  echo "  suites green"
fi

# Refuse to ship a migration whose file has changed since it was applied: the
# runner checksums them, and a rewritten migration means the server's schema no
# longer matches what the repository claims was run.
say "2/6 checking migration state on the server"
PENDING_BEFORE="$(ssh "$REMOTE" "cd '$REMOTE_PATH' && php tools/migrate.php --status" 2>&1 || true)"
echo "$PENDING_BEFORE" | sed 's/^/  /'
if echo "$PENDING_BEFORE" | grep -qi 'checksum'; then
  fail "the server reports a migration checksum mismatch; resolve it before deploying"
  exit 1
fi

if [ "$DRY_RUN" -eq 1 ]; then
  say "dry run: files that would change"
  rsync -az --dry-run --itemize-changes --exclude-from=.rsync-deploy-exclude ./ "$REMOTE:$REMOTE_PATH/" | sed 's/^/  /'
  say "dry run: no files were sent and no migration was applied"
  exit 0
fi

# ------------------------------------------------------------- 3. announce
say "3/6 raising the maintenance gate"
ssh "$REMOTE" "cd '$REMOTE_PATH' && mkdir -p storage && printf '%s\ndeploying\n' \"\$(date +%s)\" > storage/maintenance.flag"
GATE_UP=1
echo "  gate up (expires by itself within 15 minutes)"

# ------------------------------------------------------------------ 4. sync
# Never --delete: the server owns runtime config the repository does not.
say "4/6 syncing files"
rsync -az --exclude-from=.rsync-deploy-exclude ./ "$REMOTE:$REMOTE_PATH/"
echo "  files in place"

# --------------------------------------------------------------- 5. migrate
say "5/6 applying migrations"
if ! ssh "$REMOTE" "cd '$REMOTE_PATH' && php tools/migrate.php --apply"; then
  fail "migration did not complete — the gate stays up and the release is NOT live"
  exit 1
fi
STATUS_AFTER="$(ssh "$REMOTE" "cd '$REMOTE_PATH' && php tools/migrate.php --status" 2>&1 || true)"
if ! echo "$STATUS_AFTER" | grep -q 'Nothing pending'; then
  echo "$STATUS_AFTER" | sed 's/^/  /'
  fail "migrations remain pending after --apply; refusing to release"
  exit 1
fi
echo "  schema up to date"

# --------------------------------------------------------------- 6. release
say "6/6 releasing"
ssh "$REMOTE" "rm -f '$REMOTE_PATH/storage/maintenance.flag'"
GATE_UP=0

FAILED=0
for path in "" "/events" "/calendar" "/api/public/events?limit=1"; do
  code="$(curl -s -o /dev/null -w '%{http_code}' "${SMOKE_URL}${path}" || echo 000)"
  printf '  %-34s %s\n' "${path:-/}" "$code"
  case "$code" in 200|301|302) ;; *) FAILED=1 ;; esac
done
if [ "$FAILED" -ne 0 ]; then
  fail "the site is live but a smoke check did not return a success code"
  exit 1
fi

printf '\n\033[1;32mDeployed: files synced, migrations applied, site answering.\033[0m\n\n'
