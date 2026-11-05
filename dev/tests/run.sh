#!/usr/bin/env bash
# End-to-end checks against the fake Mirlo (dev/mu-plugins/mock-mirlo.php),
# in a throwaway Docker WordPress mounted the way `npm run dev` mounts it.
# Needs Docker, curl and python3. Exits non-zero if any check fails.
#
#   npm test        or   dev/tests/run.sh
#
# Set WP_IMAGE to try another PHP version, e.g. wordpress:php7.4-apache.
set -u
P=$(cd "$(dirname "$0")/../.." && pwd)
. "$P/dev/tests/lib.sh"
N=mirlo-subscribe-test
S=$(mktemp -d "${TMPDIR:-/tmp}/mirlo-subscribe-test.XXXXXX")
J="$S/cookies"
B=http://localhost:8099
WP_IMAGE=${WP_IMAGE:-wordpress:php8.2-apache}
WPCLI="docker run --rm --network $N --user 33:33 -v $N-wp:/var/www/html -v $P:/var/www/html/wp-content/plugins/mirlo-subscribe:ro -v $P/dev/mu-plugins:/var/www/html/wp-content/mu-plugins:ro -e WORDPRESS_DB_HOST=db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp wordpress:cli wp"

cleanup() { docker rm -f $N-db $N-wp >/dev/null 2>&1; docker volume rm $N-wp >/dev/null 2>&1; docker network rm $N >/dev/null 2>&1; }
trap 'cleanup; rm -rf "$S"' EXIT
cleanup

docker network create $N >/dev/null
docker run -d --name $N-db --network $N --network-alias db -e MARIADB_ROOT_PASSWORD=r -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp mariadb:11 >/dev/null
docker run -d --name $N-wp --network $N -p 8099:80 -v $N-wp:/var/www/html \
  -v $P:/var/www/html/wp-content/plugins/mirlo-subscribe:ro \
  -v $P/dev/mu-plugins:/var/www/html/wp-content/mu-plugins:ro \
  -e WORDPRESS_DB_HOST=db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
  -e WORDPRESS_CONFIG_EXTRA="define('WP_DEBUG',true);define('WP_DEBUG_LOG',true);define('WP_DEBUG_DISPLAY',false);" \
  "$WP_IMAGE" >/dev/null
wait_for_wordpress "$B" $N-db
$WPCLI core install --url=$B --title=t --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email >/dev/null || { echo "WordPress didn't install"; exit 1; }
# Same as dev/blueprint.json
$WPCLI plugin activate mirlo-subscribe >/dev/null
$WPCLI option update mirlo_instance_url http://mirlo.mock >/dev/null
$WPCLI option update mirlo_oauth_client_id 1 >/dev/null

section "preview page"
curl -s "$B/?pagename=mirlo-preview" -o "$S/preview.html"
PAGE=$(cat "$S/preview.html")
check "tier buttons from [mirlo_tier_list] and [mirlo_tiers]" 10 "$(grep -o 'class="mirlo-tier-v2"' "$S/preview.html" | wc -l | tr -d ' ')"
check "no buttons from the old shortcode code" 0 "$(grep -o 'class="mirlo-tier"' "$S/preview.html" | wc -l | tr -d ' ')"
check_has "pop-up markup" 'id="mirlo-modal-v2"' "$PAGE"
check_has "pop-up script" 'mirlo-modal-v2.js' "$PAGE"
check "visitors get a short message when tiers can't load" 2 "$(grep -o "Couldn&#039;t load the subscription tiers. Please try again later." "$S/preview.html" | wc -l | tr -d ' ')"
check_lacks "visitors don't see Mirlo's raw errors" 'HTTP 404' "$PAGE"
check_has "a relative success_url becomes a full address" "data-success-url=\"$B/thanks\"" "$PAGE"

section "pop-up AJAX"
NONCE=$(grep -o 'var mirloAjax = {[^}]*}' "$S/preview.html" | head -1 | grep -o '"nonce":"[^"]*"' | cut -d'"' -f4)
artist() { curl -s "$B/wp-admin/admin-ajax.php" -d action=fetch_mirlo_artist -d nonce=$NONCE -d artist_slug=$1; }
check "artist loads" "True" "$(artist mock-artist | json 'd["success"]')"
check "artist without tiers loads, with none" "0" "$(artist no-tiers | json 'len(d["data"]["tiers"])')"
check "missing artist: short message" "Couldn't load the subscription tiers. Please try again later." "$(artist missing | json 'd["data"]')"
check "Mirlo error: short message" "Couldn't load the subscription tiers. Please try again later." "$(artist broken | json 'd["data"]')"
SLOW=$(curl -s -o /dev/null -w '%{time_total}' "$B/wp-admin/admin-ajax.php" -d action=fetch_mirlo_artist -d nonce=$NONCE -d artist_slug=slow)
check "slow Mirlo takes about 3 seconds" "yes" "$(python3 -c "print('yes' if $SLOW >= 2.5 else 'no')")"

section "checkout and success_url"
NONCE2=$(grep -o 'var mirloAjaxV2 = {[^}]*}' "$S/preview.html" | grep -o '"nonce":"[^"]*"' | cut -d'"' -f4)
buy() { curl -s "$B/wp-admin/admin-ajax.php" -d action=mirlo_purchase_v2 -d nonce=$NONCE2 -d artist_id=42 -d tier_id=3 ${1:+--data-urlencode success_url="$1"}; }
check "no key, no success_url: checkout starts" "True" "$(buy | json 'd["success"]')"
R=$(buy "$B/thanks/?a=1")
check "no key, back to this site: Mirlo refuses" "False" "$(echo "$R" | json 'd["success"]')"
check "  and visitors get a short message" "Couldn't start the checkout. Please try again." "$(echo "$R" | json 'd["data"]["message"]')"
check "  without Mirlo's details" "None" "$(echo "$R" | json 'd["data"]["detail"]')"
check_has "no key, back to Mirlo itself: allowed" 'success=http%3A%2F%2Fmirlo.mock%2Fthanks' "$(buy http://mirlo.mock/thanks | json 'd["data"]["redirectUrl"]')"
check "not a URL: dropped, checkout starts" "True" "$(buy thanks | json 'd["success"]')"
$WPCLI option update mirlo_api_key anykey >/dev/null
URL=$(buy "$B/thanks/?a=1" | json 'd["data"]["redirectUrl"]')
check_has "with a key, back to this site: allowed" 'localhost%3A8099%2Fthanks' "$URL"
check_has "  the checkout page links back" "href=\"$B/thanks/?a=1\"" "$(curl -s "$URL")"
check_lacks "with a key, another site: dropped by the plugin" 'elsewhere' "$(buy https://elsewhere.example/thanks | json 'd["data"]["redirectUrl"]')"
$WPCLI option update mirlo_api_key "" >/dev/null
check "the old subscribe AJAX action is gone" "0" "$(curl -s "$B/wp-admin/admin-ajax.php" -d action=mirlo_subscribe -d nonce=$NONCE -d artist_id=42 -d tier_id=2)"

section "return addresses and the artist cache"
ret() { $WPCLI eval "echo Mirlo_API::return_url('$1');"; }
check "/thanks" "$B/thanks" "$(ret /thanks)"
check "thanks (no slash)" "" "$(ret thanks)"
check "//other host" "" "$(ret //evil.example/x)"
check "another site" "" "$(ret https://evil.example/x)"
check "javascript:" "" "$(ret 'javascript:alert(1)')"
check "Mirlo itself" "http://mirlo.mock/done" "$(ret http://mirlo.mock/done)"
check "a full address on this site" "$B/a?b=1" "$(ret "$B/a?b=1")"
check "an artist is cached after one call" "yes" "$($WPCLI eval '$a = new Mirlo_API(); $a->get_artist("mock-artist"); echo get_transient(Mirlo_API::ARTIST_CACHE . md5("http://mirlo.mock/v1|mock-artist")) ? "yes" : "no";')"
check "errors aren't cached" "no" "$($WPCLI eval '$a = new Mirlo_API(); $a->get_artist("missing"); echo get_transient(Mirlo_API::ARTIST_CACHE . md5("http://mirlo.mock/v1|missing")) ? "yes" : "no";')"

section "connecting"
curl -s -c $J -b $J -o /dev/null "$B/wp-login.php"
curl -s -c $J -b $J -o /dev/null "$B/wp-login.php" -d log=admin -d pwd=admin -d wp-submit=1 -d testcookie=1
SET="$B/wp-admin/options-general.php?page=mirlo-subscribe"
start_connect() {
  local cn=$(curl -s -b $J "$SET" | grep -A2 'value="mirlo_oauth_connect"' | grep -o 'name="_wpnonce" value="[^"]*"' | cut -d'"' -f4)
  curl -s -b $J -c $J -o /dev/null -w '%{redirect_url}' "$B/wp-admin/admin-post.php" -d action=mirlo_oauth_connect -d _wpnonce=$cn -d _wp_http_referer=/wp-admin/options-general.php?page=mirlo-subscribe
}
finish_connect() { # authorize URL, decision
  local back=$(curl -s -b $J -o /dev/null -w '%{redirect_url}' "$1&decision=$2")
  local final=$(curl -s -b $J -c $J -o /dev/null -w '%{redirect_url}' "$back")
  curl -s -b $J "$final"
}
AUTH=$(start_connect)
check_has "connect asks Mirlo with PKCE" 'code_challenge_method=S256' "$AUTH"
check_has "  and for the subscribers scope" 'subscribers%3Aread' "$AUTH"
check_has "denying shows a message" "Mirlo didn&#039;t grant access" "$(finish_connect "$AUTH" deny)"
finish_connect "$(start_connect)" allow > "$S/settings.html"
check_has "allowing connects" "Connected" "$(cat "$S/settings.html")"

section "subscriber preview"
AN=$(grep -o 'var mirloAdmin = {[^}]*}' "$S/settings.html" | grep -o '"nonce":"[^"]*"' | cut -d'"' -f4)
subs() { curl -s -b $J "$B/wp-admin/admin-ajax.php" -d action=mirlo_preview_subscribers -d nonce=$AN -d artist=$1; }
check "subscribers listed" "7" "$(subs mock-artist | json 'len(d["data"])')"
check "every subscriber has a Mirlo user ID" "True" "$(subs mock-artist | json 'all(s.get("userId") for s in d["data"])')"
check "an empty list is fine" "[]" "$(subs no-subscribers | json 'd["data"]')"
check "a missing artist is an error" "False" "$(subs missing | json 'd["success"]')"
check "logged out: refused" "0" "$(curl -s "$B/wp-admin/admin-ajax.php" -d action=mirlo_preview_subscribers -d nonce=$AN -d artist=mock-artist)"

section "daily renewal"
cronlist() { $WPCLI cron event list --fields=hook --format=csv 2>/dev/null | grep -c mirlo_oauth_keepalive; }
tok() { $WPCLI eval '$t = get_option("mirlo_oauth_tokens"); echo is_array($t) ? $t["access_token"] : "none";'; }
expire() { $WPCLI eval '$t = get_option("mirlo_oauth_tokens"); $t["expires_at"] = 0; update_option("mirlo_oauth_tokens", $t, false);'; }
check "scheduled after connecting" 1 "$(cronlist)"
before=$(tok); expire; $WPCLI cron event run mirlo_oauth_keepalive >/dev/null; after=$(tok)
check "the daily job refreshes an expired token" "changed" "$([ "$before" != "$after" ] && [ "$after" != none ] && echo changed || echo same)"
$WPCLI cron event run mirlo_oauth_keepalive >/dev/null
check "  and leaves a fresh one alone" "$after" "$(tok)"

section "Mirlo ends the connection"
curl -s -b $J -o /dev/null "$B/?mock_mirlo=end-connection"
$WPCLI cron event run mirlo_oauth_keepalive >/dev/null
check "tokens dropped" "none" "$(tok)"
check "daily job unscheduled" 0 "$(cronlist)"
DASH=$(curl -s -b $J "$B/wp-admin/" | tr -d '\n')
check_has "every admin page warns" "Reconnect to Mirlo" "$DASH"
check "the settings page warns once" 1 "$(curl -s -b $J "$SET" | grep -o 'Mirlo ended this' | wc -l | tr -d ' ')"
DIS=$(echo "$DASH" | grep -oE 'href="[^"]*mirlo_oauth_dismiss_notice[^"]*"' | head -1 | cut -d'"' -f2 | sed 's/&#038;/\&/g; s/&amp;/\&/g')
check "dismissing goes back" 302 "$(curl -s -b $J -e "$B/wp-admin/" -o /dev/null -w '%{http_code}' "$DIS")"
check "  and the warning is gone" 0 "$(curl -s -b $J "$B/wp-admin/" | grep -o 'Mirlo Subscribe:</strong>' | wc -l | tr -d ' ')"
check "dismissing without a nonce is refused" 403 "$(curl -s -b $J -o /dev/null -w '%{http_code}' "$B/wp-admin/admin-post.php?action=mirlo_oauth_dismiss_notice")"

section "reconnect, deactivate, activate"
finish_connect "$(start_connect)" allow >/dev/null
check "reconnected" "yes" "$([ "$(tok)" != none ] && echo yes || echo no)"
check "  and rescheduled" 1 "$(cronlist)"
$WPCLI plugin deactivate mirlo-subscribe >/dev/null
check "deactivating unschedules" 0 "$(cronlist)"
$WPCLI plugin activate mirlo-subscribe >/dev/null; curl -s -b $J -o /dev/null "$B/wp-admin/"
check "activating and visiting admin reschedules" 1 "$(cronlist)"

section "preview page"
$WPCLI option update mock_mirlo_preview_hash stale >/dev/null
curl -s -o /dev/null "$B/?pagename=mirlo-preview"
check "re-syncing keeps a single preview page" 1 "$($WPCLI post list --post_type=page --name=mirlo-preview --format=count)"

section "settings: Client ID and instance changes"
save() { # instance client_id
  local n=$(curl -s -b $J "$SET" | grep -o 'name="_wpnonce" value="[^"]*"' | head -1 | cut -d'"' -f4)
  curl -s -b $J -c $J -o /dev/null "$B/wp-admin/options.php" -d option_page=mirlo_subscribe_settings -d action=update \
    -d _wpnonce=$n -d _wp_http_referer=/wp-admin/options-general.php?page=mirlo-subscribe \
    --data-urlencode mirlo_instance_url="$1" --data-urlencode mirlo_oauth_client_id="$2" -d mirlo_button_text=Subscribe
  $WPCLI option get mirlo_oauth_client_id 2>/dev/null
}
check "nothing changes: ID kept" 1 "$(save http://mirlo.mock 1)"
check "instance changed, ID left as it was: cleared" "" "$(save https://mirlo.space 1)"
check_has "  with a warning" "Client ID was cleared" "$(curl -s -b $J "$SET&settings-updated=true")"
check "same instance, new ID: kept" 7 "$(save https://mirlo.space 7)"
check "instance and ID changed together: kept" 9 "$(save https://music.example.org 9)"
check "trailing slash only: kept" 9 "$(save https://music.example.org/ 9)"
check "back to the default instance: cleared" "" "$(save "" 9)"

section "PHP"
check "no PHP warnings or notices" "" "$(docker exec $N-wp sh -c 'cat /var/www/html/wp-content/debug.log 2>/dev/null' | grep -v 'PHP Deprecated' | head -20)"

finish
