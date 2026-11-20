#!/usr/bin/env bash
# End-to-end check of a running install: wiring, permissions and security basics.
#   bin/smoke-test.sh https://pos.example.com                      (public checks only)
#   bin/smoke-test.sh https://pos.example.com admin 'your-password' (also signs in and checks the API)
# Uses one wrong-password attempt against a made-up user name; it never changes any data.
set -u
BASE="${1:?Usage: smoke-test.sh BASE_URL [username password]}"; BASE="${BASE%/}"
U="${2:-}"; P="${3:-}"
JAR="$(mktemp)"; trap 'rm -f "$JAR"' EXIT
pass=0; fail=0
ok()  { echo "  [ ok ] $1"; pass=$((pass+1)); }
bad() { echo "  [FAIL] $1"; fail=$((fail+1)); }
api() { echo "$BASE/api/index.php?route=$1"; }
status() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
expect() { [ "$2" = "$3" ] && ok "$1" || bad "$1 (expected HTTP $2, got $3)"; }
blocked() { c=$(status "$BASE/$1"); case "$c" in 403|404) ok "not downloadable: /$1 ($c)";; *) bad "/$1 is reachable (HTTP $c)";; esac; }
XRW=(-H 'X-Requested-With: XMLHttpRequest')
JSON=(-H 'Content-Type: application/json')
jesc() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }

echo "Public checks on $BASE"
expect "app page loads" 200 "$(status "$BASE/")"
expect "health check (database reachable)" 200 "$(status "$(api health)")"
expect "API refuses anonymous product list" 401 "$(status "$(api products)")"
expect "API refuses a change without the app header (CSRF guard)" 403 "$(status -X POST "${JSON[@]}" -d '{}' "$(api login)")"
expect "wrong login is rejected" 401 "$(status -X POST "${XRW[@]}" "${JSON[@]}" -d '{"username":"smoke-test-nobody","password":"not-a-real-password"}' "$(api login)")"
for f in database/schema.sql database/migrate.sql api/config.php api/db.php api/installer.php api/config.local.php bin/install.php README.md .htaccess .env; do blocked "$f"; done
H="$(curl -sI "$BASE/")"
echo "$H" | grep -qi '^x-content-type-options: nosniff' && ok "header X-Content-Type-Options" || bad "missing X-Content-Type-Options"
echo "$H" | grep -qi '^content-security-policy:' && ok "header Content-Security-Policy" || bad "missing Content-Security-Policy"
case "$BASE" in https://*) echo "$H" | grep -qi '^strict-transport-security:' && ok "HSTS sent over HTTPS" || bad "HTTPS but no Strict-Transport-Security (is TRUST_PROXY=1 set behind your proxy?)";; *) echo "  [warn] not HTTPS: passwords travel unencrypted. Use HTTPS on any network you do not fully control.";; esac
echo "$(curl -s "$(api me)")" | grep -q '"user":null' && ok "anonymous /me answers with no user" || bad "unexpected /me answer"

if [ -n "$U" ] && [ -n "$P" ]; then
  echo "Signed-in checks as '$U'"
  R="$(curl -s -c "$JAR" -b "$JAR" -X POST "${XRW[@]}" "${JSON[@]}" -d "{\"username\":\"$(jesc "$U")\",\"password\":\"$(jesc "$P")\"}" "$(api login)")"
  if echo "$R" | grep -q '"user"'; then ok "login works"; else bad "login failed: $R"; fi
  grep -q '#HttpOnly_' "$JAR" && ok "session cookie is HttpOnly" || bad "session cookie is not HttpOnly"
  if echo "$R" | grep -q '"must_change":true'; then echo "  [warn] this account must change its password first: sign in on the website, then run the test again."
  else
    for r in me products categories customers sales; do
      expect "GET $r" 200 "$(status -b "$JAR" "$(api $r)")"
    done
    ROLE="$(echo "$R" | sed -n 's/.*"role":"\([a-z]*\)".*/\1/p')"
    if [ "$ROLE" = admin ] || [ "$ROLE" = manager ]; then
      for r in report suppliers expenses stock_log "rpt&type=pnl"; do expect "GET $r (manager)" 200 "$(status -b "$JAR" "$(api "$r")")"; done
      curl -s -b "$JAR" "$(api products)" | grep -q '"cost"' && ok "managers see cost prices" || echo "  [info] no products yet, or cost not returned"
    else
      expect "cashier is kept out of reports" 403 "$(status -b "$JAR" "$(api report)")"
      curl -s -b "$JAR" "$(api products)" | grep -q '"cost"' && bad "cashier can see cost prices" || ok "cashier cannot see cost prices"
    fi
    expect "logout" 200 "$(status -c "$JAR" -b "$JAR" -X POST "${XRW[@]}" "$(api logout)")"
    expect "session is gone after logout" 401 "$(status -b "$JAR" "$(api products)")"
  fi
fi
echo; echo "Passed: $pass   Failed: $fail"
[ "$fail" -eq 0 ]
