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
check "visitors get a short message when tiers can't load (tier list and catalog)" 4 "$(grep -o "Couldn&#039;t load the subscription tiers. Please try again later." "$S/preview.html" | wc -l | tr -d ' ')"
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

section "catalog"
CAT=$(curl -s "$B/?pagename=mirlo-preview")
cat_count() { echo "$CAT" | grep -o "$1" | wc -l | tr -d ' '; }
check "Buy buttons: 2 in the full catalog, 2 picked, 2 in the reader catalog" 6 "$(cat_count 'data-mirlo-buy="merch"')"
check "Subscribe buttons: 4 in the full catalog, 2 picked, 3 in the reader catalogs" 9 "$(cat_count 'data-mirlo-buy="tier"')"
check "Buy on Mirlo links: vinyl, shirt, tote, pin, 6 from the old-Mirlo fallback, pin in the reader catalog" 11 "$(cat_count '>Buy on Mirlo</a>')"
check_has "  linking to the item's page" 'href="http://mirlo.mock/mock-artist/merch/merch-vinyl"' "$CAT"
check_has "the artist's own shop link" 'href="https://shop.example/hat"' "$CAT"
check "sold out, in the catalog and the fallback" 2 "$(cat_count 'mirlo-catalog__item--sold-out')"
check_has "prices are readable without JavaScript" '>$20.00</p>' "$CAT"
check_has "  in the item's currency" '>€15.00</p>' "$CAT"
check_has "titles are escaped" 'T-shirt &lt;b&gt;escaped&lt;/b&gt; &amp; co' "$CAT"
SECOND=$(echo "$CAT" | awk '/class="mirlo-catalog"/{n++} n==2' | grep -o 'data-merch-id="[^"]*"\|data-tier-id="[^"]*"' | paste -sd' ')
check "picked items and tiers keep their order" 'data-tier-id="3" data-tier-id="2" data-merch-id="merch-poster" data-merch-id="merch-digital"' "$SECOND"
check_has "columns and colour become CSS variables" 'style="--mirlo-accent:#2a7a4b;--mirlo-catalog-columns:2"' "$CAT"
check_has "an empty title hides the heading" '<section class="mirlo-catalog__section mirlo-catalog__section--merch"><ul' "$CAT"
check_has "no merch" 'No merch available.' "$CAT"
check "visitors get a short message when merch can't load" 2 "$(cat_count "Couldn&#039;t load the merch. Please try again later.")"
check_has "stylesheet loads in the head" "mirlo-catalog.css" "$(echo "$CAT" | sed -n '1,/<\/head>/p')"
NONCE3=$(echo "$CAT" | grep -o 'var mirloCatalog = {[^}]*}' | grep -o '"nonce":"[^"]*"' | cut -d'"' -f4)
merch() { curl -s "$B/wp-admin/admin-ajax.php" -d action=mirlo_purchase_merch -d nonce=$NONCE3 -d artist=${2:-mock-artist} -d merch_id=$1 ${3:+--data-urlencode success_url="$3"}; }
check_has "digital merch: checkout starts" 'merch=merch-digital' "$(merch merch-digital | json 'd["data"]["redirectUrl"]')"
check "one shipping region: checkout starts" "True" "$(merch merch-poster | json 'd["success"]')"
check "two shipping regions: refused here" "This item can't be bought here. Please try again later." "$(merch merch-vinyl | json 'd["data"]["message"]')"
check "a size to choose: refused here" "False" "$(merch merch-shirt | json 'd["success"]')"
check "sold out: refused here" "False" "$(merch merch-gone | json 'd["success"]')"
check "unknown merch: refused" "False" "$(merch nope | json 'd["success"]')"
check "  without Mirlo's details" "None" "$(merch nope | json 'd["data"]["detail"]')"
check "back to this site without a key: Mirlo refuses" "False" "$(merch merch-digital mock-artist "$B/thanks" | json 'd["success"]')"
check "tier checkout from the catalog's nonce" "True" "$(curl -s "$B/wp-admin/admin-ajax.php" -d action=mirlo_purchase_v2 -d nonce=$NONCE3 -d artist_id=42 -d tier_id=3 | json 'd["success"]')"
check "the merch is cached after one call" "yes" "$($WPCLI eval '$a = new Mirlo_API(); $a->get_artist_merch("mock-artist"); echo get_transient(Mirlo_API::MERCH_CACHE . md5("http://mirlo.mock/v1|mock-artist")) ? "yes" : "no";')"
check "turning the stylesheet off" "no" "$($WPCLI eval 'add_filter("mirlo_catalog_load_styles","__return_false"); do_action("wp_enqueue_scripts"); do_shortcode("[mirlo_catalog artist=\"mock-artist\"]"); echo wp_style_is("mirlo-catalog") ? "yes" : "no";')"

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
form_nonce() { curl -s -b $J "$SET" | grep -o 'name="_wpnonce" value="[^"]*"' | head -1 | cut -d'"' -f4; }
SAVED='$o = array(); foreach ( array( "mirlo_instance_url", "mirlo_oauth_client_id", "mirlo_reader_sales", "mirlo_api_key", "mirlo_button_color" ) as $k ) { $o[ $k ] = get_option( $k ); } echo http_build_query( $o );'
save_form() { # extra fields; submits the settings form with what's saved, and prints where it redirects
  curl -s -b $J -c $J -o /dev/null -w '%{redirect_url}' "$B/wp-admin/options.php" -d option_page=mirlo_subscribe_settings -d action=update \
    -d _wpnonce=$(form_nonce) -d _wp_http_referer=/wp-admin/options-general.php?page=mirlo-subscribe -d "$($WPCLI eval "$SAVED")" "$@"
}
start_connect() { # "Connect to Mirlo" saves the settings, then connects
  curl -s -b $J -c $J -o /dev/null -w '%{redirect_url}' "$(save_form -d mirlo_connect=1)"
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

section "settings page"
chk() { curl -s -b $J "$B/wp-admin/admin-ajax.php" -d action=mirlo_check_artist -d nonce=$AN -d slug=$1; }
check "check an artist: tiers listed with IDs" "1,2,3,4" "$(chk mock-artist | json '",".join(str(t["id"]) for t in d["data"]["tiers"])')"
check "  and merch" "True" "$(chk mock-artist | json 'len(d["data"]["merch"]) > 0')"
check_has "  a missing artist says so" "There's no artist" "$(chk missing | json 'd["data"]')"
SP=$(curl -s -b $J "$SET")
check_lacks "no Default Button Text setting" "mirlo_button_text" "$SP"
check_has "the reference lists [mirlo_catalog]" "[mirlo_catalog artist=" "$SP"
check_has "connected: the account section is open" '<details class="mirlo-account" open>' "$SP"

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

section "card reader"
login() { # cookie jar, user, password
  curl -s -c $1 -b $1 -o /dev/null "$B/wp-login.php"
  curl -s -c $1 -b $1 -o /dev/null "$B/wp-login.php" -d log=$2 -d pwd=$3 -d wp-submit=1 -d testcookie=1
}
readers() { echo "$1" | grep -o 'class="mirlo-catalog__reader-form"' | wc -l | tr -d ' '; }
ANON=$(curl -s "$B/?pagename=mirlo-preview")
check "signed out: no reader forms" 0 "$(readers "$ANON")"
check_has "  and no reader nonce" '"reader_nonce":""' "$ANON"
check_lacks "  and no reader notices" 'mirlo-catalog__reader-notice' "$ANON"
check "administrators have the capability" "yes" "$($WPCLI eval 'echo get_role("administrator")->has_cap("mirlo_use_reader") ? "yes" : "no";')"
check_has "admin, reader sales off: told how to set them up" "Turn them on in Settings" "$(curl -s -b $J "$B/?pagename=mirlo-preview")"

check_has "ticking card reader sales goes on to connect" "action=mirlo_oauth_connect" "$(save_form -d mirlo_reader_sales=1)"
check "  and turns them on" 1 "$($WPCLI option get mirlo_reader_sales)"
check_lacks "saving again with them on just saves" "action=mirlo_oauth_connect" "$(save_form)"
check_has "turned on: settings ask to connect again" "Connect again" "$(curl -s -b $J "$SET")"
check_has "  and pages still say it isn't set up" "Turn them on in Settings" "$(curl -s -b $J "$B/?pagename=mirlo-preview")"
finish_connect "$(start_connect)" allow >/dev/null
check "connecting again grants pos" "yes" "$($WPCLI eval 'echo Mirlo_OAuth::has_scope("pos") ? "yes" : "no";')"
check_lacks "  and the settings stop asking" "Connect again" "$(curl -s -b $J "$SET")"

RP=$(curl -s -b $J "$B/?pagename=mirlo-preview")
check "reader forms: zine, pin, two paid tiers (not the poster, which has shipping)" 4 "$(readers "$RP")"
check_has "  a menu for the pin's colour" '<select name="option_ids[]" required><option value="">Choose…</option><option value="opt-s">' "$RP"
check_has "  an amount for a pay-what-you-want tier" 'name="amount" min="5.00"' "$RP"
check_has "the online reader is listed first" '<select class="mirlo-catalog__reader-select"><option value="tmr_table">Merch table</option><option value="tmr_spare">Spare reader (offline)</option>' "$RP"
check_has "an artist without readers says so" "This artist has no card readers on Mirlo." "$RP"
RN=$(echo "$RP" | grep -o '"reader_nonce":"[^"]*"' | cut -d'"' -f4)
rc() { curl -s -b $J "$B/wp-admin/admin-ajax.php" -d action=mirlo_reader_charge -d nonce=$RN -d artist=mock-artist "$@"; }
rs() { curl -s -b $J "$B/wp-admin/admin-ajax.php" -d action=mirlo_reader_status -d nonce=$RN -d intent_id=$1 -d account_id=acct_mock | json 'd["data"]["status"]'; }
SALE=$(rc -d reader_id=tmr_table -d kind=merch -d merch_id=merch-digital)
PI=$(echo "$SALE" | json 'd["data"]["intentId"]')
check_has "zine sent to the reader" "pi_mock_" "$PI"
check "  with the Stripe account to check it with" "acct_mock" "$(echo "$SALE" | json 'd["data"]["accountId"]')"
check "  waiting for the card" "requires_payment_method" "$(rs $PI)"
check "  card read" "requires_capture" "$(rs $PI)"
check "  paid" "succeeded" "$(rs $PI)"
check "the pin needs a colour" "Choose a colour." "$(rc -d reader_id=tmr_table -d kind=merch -d merch_id=merch-pin | json 'd["data"]["message"]')"
check "  a colour from another item is ignored" "Choose a colour." "$(rc -d reader_id=tmr_table -d kind=merch -d merch_id=merch-pin -d 'option_ids[]=nope' | json 'd["data"]["message"]')"
PIN=$(rc -d reader_id=tmr_table -d kind=merch -d merch_id=merch-pin -d 'option_ids[]=' -d 'option_ids[]=opt-m' | json 'd["data"]["intentId"]')
check_has "  with one it's sent" "pi_mock_" "$PIN"
check "  and can be cancelled" "canceled" "$(curl -s -b $J "$B/wp-admin/admin-ajax.php" -d action=mirlo_reader_cancel -d nonce=$RN -d intent_id=$PIN -d account_id=acct_mock -d reader_id=tmr_table | json 'd["data"]["status"]')"
check "  which sticks" "canceled" "$(rs $PIN)"
check "items with shipping can't be sold on the reader" "This item can't be sold on the card reader." "$(rc -d reader_id=tmr_table -d kind=merch -d merch_id=merch-poster | json 'd["data"]["message"]')"
check "an offline reader" "The card reader is offline. Check that it's on and connected." "$(rc -d reader_id=tmr_spare -d kind=merch -d merch_id=merch-digital | json 'd["data"]["message"]')"
check "another artist's reader" "That card reader isn't available. Reload the page and try again." "$(rc -d reader_id=tmr_other -d kind=merch -d merch_id=merch-digital | json 'd["data"]["message"]')"
check "a tier needs the buyer's email" "False" "$(rc -d reader_id=tmr_table -d kind=tier -d tier_id=2 | json 'd["success"]')"
check_has "  with it, the card is saved for the subscription" "seti_mock_" "$(rc -d reader_id=tmr_table -d kind=tier -d tier_id=2 -d email=fan@example.com -d amount=7.50 | json 'd["data"]["intentId"]')"
check "  not below the tier's minimum" "The amount must be at least 5.00." "$(rc -d reader_id=tmr_table -d kind=tier -d tier_id=2 -d email=fan@example.com -d amount=1 | json 'd["data"]["message"]')"
check "  not a free tier" "False" "$(rc -d reader_id=tmr_table -d kind=tier -d tier_id=1 -d email=fan@example.com | json 'd["success"]')"
check "signed out: the reader actions don't exist" "0" "$(curl -s "$B/wp-admin/admin-ajax.php" -d action=mirlo_reader_charge -d nonce=$RN)"
check "a status check without the reader nonce is refused" "-1" "$(curl -s -b $J "$B/wp-admin/admin-ajax.php" -d action=mirlo_reader_status -d nonce=nope -d intent_id=$PI -d account_id=acct_mock)"

$WPCLI user create vol vol@example.com --role=subscriber --user_pass=vol >/dev/null
V="$S/vol-cookies"; login $V vol vol
check "a volunteer without the capability: no reader forms" 0 "$(readers "$(curl -s -b $V "$B/?pagename=mirlo-preview")")"
check_has "  nor a reader nonce" '"reader_nonce":""' "$(curl -s -b $V "$B/?pagename=mirlo-preview")"
check "  so the actions are refused" "-1" "$(curl -s -b $V "$B/wp-admin/admin-ajax.php" -d action=mirlo_reader_status -d nonce=$RN -d intent_id=$PI -d account_id=acct_mock)"
$WPCLI user add-cap vol mirlo_use_reader >/dev/null
VP=$(curl -s -b $V "$B/?pagename=mirlo-preview")
check "given the capability: reader forms" 4 "$(readers "$VP")"
VRN=$(echo "$VP" | grep -o '"reader_nonce":"[^"]*"' | cut -d'"' -f4)
check_has "  and can send a sale" "pi_mock_" "$(curl -s -b $V "$B/wp-admin/admin-ajax.php" -d action=mirlo_reader_charge -d nonce=$VRN -d artist=mock-artist -d reader_id=tmr_table -d kind=merch -d merch_id=merch-digital | json 'd["data"]["intentId"]')"
$WPCLI option update mirlo_reader_sales 0 >/dev/null
check "turned off: the actions are refused" "False" "$(curl -s -b $V "$B/wp-admin/admin-ajax.php" -d action=mirlo_reader_status -d nonce=$VRN -d intent_id=$PI -d account_id=acct_mock | json 'd["success"]')"
check_has "  and staff are told it isn't set up" "Ask a site admin" "$(curl -s -b $V "$B/?pagename=mirlo-preview")"
$WPCLI option update mirlo_reader_sales 1 >/dev/null
$WPCLI user remove-cap vol mirlo_use_reader >/dev/null
check "capability taken away: a nonce from before is refused" "False" "$(curl -s -b $V "$B/wp-admin/admin-ajax.php" -d action=mirlo_reader_charge -d nonce=$VRN -d artist=mock-artist -d reader_id=tmr_table -d kind=merch -d merch_id=merch-digital | json 'd["success"]')"

section "disconnecting"
DL=$(curl -s -b $J "$SET" | grep -oE 'href="[^"]*mirlo_oauth_disconnect[^"]*"' | head -1 | cut -d'"' -f2 | sed 's/&#038;/\&/g; s/&amp;/\&/g')
curl -s -b $J -o /dev/null "$DL"
check "the Disconnect link disconnects" "none" "$(tok)"
check_has "  and the page offers to connect" "Connect to Mirlo" "$(curl -s -b $J "$SET")"
check "disconnecting without a nonce is refused" 403 "$(curl -s -b $J -o /dev/null -w '%{http_code}' "$B/wp-admin/admin-post.php?action=mirlo_oauth_disconnect")"

section "settings: Client ID and instance changes"
save() { # instance client_id
  curl -s -b $J -c $J -o /dev/null "$B/wp-admin/options.php" -d option_page=mirlo_subscribe_settings -d action=update \
    -d _wpnonce=$(form_nonce) -d _wp_http_referer=/wp-admin/options-general.php?page=mirlo-subscribe \
    --data-urlencode mirlo_instance_url="$1" --data-urlencode mirlo_oauth_client_id="$2"
  $WPCLI option get mirlo_oauth_client_id 2>/dev/null
}
check "a save that raises a warning doesn't go on to connect" "settings-updated=true" "$(curl -s -b $J -c $J -o /dev/null -w '%{redirect_url}' "$B/wp-admin/options.php" -d option_page=mirlo_subscribe_settings -d action=update \
    -d _wpnonce=$(form_nonce) -d _wp_http_referer=/wp-admin/options-general.php?page=mirlo-subscribe -d mirlo_instance_url=https://other.example -d mirlo_oauth_client_id=1 -d mirlo_connect=1 | grep -o 'settings-updated=true')"
$WPCLI option update mirlo_instance_url http://mirlo.mock >/dev/null; $WPCLI option update mirlo_oauth_client_id 1 >/dev/null
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
