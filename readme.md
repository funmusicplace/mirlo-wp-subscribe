# Mirlo Subscribe WordPress plug-in

Contributors: Mirlo Code Circle
Tags: mirlo, music, subscriptions, modal
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 1.5.0
License: AGPLv3 or later

Add Mirlo artist subscription modals to any post or page via shortcode.

## Description

Mirlo Subscribe lets you embed a subscription buttons anywhere on your site.

### Button

When clicked, a modal opens showing the artist's subscription tiers fetched
live from the Mirlo API.

    [mirlo_pop_up artist="your-artist-slug" button_text="Support me" full_width="true" success_url="https://yoursite.com/thanks"]

### Tiers

Displays the tiers in a list and a user can click on them.

    [mirlo_tier_list artist="your-artist-slug"]

Both take `color="#hex"` and `tier_ids="12,34"` to show only those tiers, in
that order.

`success_url` on `[mirlo_pop_up]` is where buyers land after paying. It can be
a path on this site, such as `/thanks`, or a full URL on this site or on
Mirlo; any other address is ignored. It needs an **API key**, under
**After checkout** in the settings (ask hi@mirlo.space): without one, Mirlo
only lets buyers return to Mirlo itself. The original `[mirlo_subscribe]` and `[mirlo_tiers]` shortcodes
still work and show the same as these.

### Catalog

Shows the artist's merch and subscription tiers as cards, each with a button
that starts Mirlo's checkout, where the buyer enters their email, address and
card.

    [mirlo_catalog artist="your-artist-slug"]

| Attribute | |
|---|---|
| `show` | Which sections, in that order: `merch,tiers` (default), `merch` or `tiers` |
| `merch_ids`, `tier_ids` | Only these items or tiers, in that order |
| `columns` | The most cards in a row (default 3). Fewer fit on narrow screens |
| `color` | Button colour, as `#hex` |
| `merch_title`, `tiers_title` | Section headings (default "Merch" and "Subscriptions"). Empty for none |
| `success_url` | As for `[mirlo_pop_up]` |
| `reader` | `yes` adds card reader sales for staff (see below) |

Mirlo's checkout can't choose a variant or a shipping region, so merch is
bought one item at a time, at its minimum price, and:

- items with no required variant (such as a size) and at most one shipping
  region get a **Buy** button that goes straight to checkout;
- items with a required variant, more than one shipping region, or the EU
  region get **Buy on Mirlo**, a link to the item's page on Mirlo;
- items with an external shop link go to that shop, and sold-out items say so.

**Selling on a card reader.** At a show or a merch table, staff can send a
sale straight to one of the artist's Stripe card readers (the ones set up on
Mirlo's point-of-sale page) and the buyer taps their card:

1. In **Settings → Mirlo Subscribe**, open **Mirlo account**, tick **Card
   reader sales** and save. Saving takes you to Mirlo to approve the `pos`
   permission, signed in as an account that manages the artist.
2. Add `reader="yes"` to the shortcode.

People with the `mirlo_use_reader` capability then get a reader menu and a
sale above the cards. **Add to sale** on a merch card puts it in the sale,
where quantities can be changed, and **Charge on reader** sends the whole
sale to the reader as one payment. The sale is kept in the browser tab until
it's paid or cleared, so reloading the page doesn't lose it. Paid tiers have
their own **Pay on reader**, since Mirlo sells a subscription on its own.
The page waits for the card and says when it's paid, with a **Cancel**
button until then. Everyone else sees the catalog as usual. Administrators
have the capability; give it to other roles or people, such as volunteers,
with a role editor or a filter:

    add_filter( 'user_has_cap', function ( $allcaps, $caps ) {
        if ( in_array( 'mirlo_use_reader', $caps, true ) && ! empty( $allcaps['check_members'] ) ) {
            $allcaps['mirlo_use_reader'] = true;
        }
        return $allcaps;
    }, 10, 2 );

What can go on a reader:

- merch that's in stock and sold through Mirlo. Mirlo leaves shipping off
  sales on a reader. Variants are chosen from a menu on the card, and the
  sale can't have more of an item or a variant than is left. Merch that
  comes with a download needs the buyer's email, which the sale asks for;
- paid subscription tiers. Staff enter the buyer's email, where Mirlo sends
  the subscription, and the amount for pay-what-you-want tiers. The card is
  saved and charged monthly.

**Styling.** The cards use BEM classes (`mirlo-catalog`, `__section`,
`__heading`, `__items`, `__item`, `__item--merch`, `__item--tier`,
`__item--sold-out`, `__image`, `__body`, `__title`, `__price`, `__stock`,
`__description`, `__actions`, `__buy`, `__buy--link`, `__buy--sold-out`,
`__error`, and for card reader sales `__reader`, `__reader-notice`,
`__reader-label`, `__reader-select`, `__reader-form`, `__field`,
`__buy--reader`, `__reader-status`, `__reader-cancel`, `__add-form`, and for
the sale `__sale`, `__sale-empty`, `__sale-lines`, `__sale-line`,
`__sale-name`, `__sale-qty`, `__sale-price`, `__sale-button`, `__sale-total`,
`__sale-actions` and `__sale-clear`). The plugin's rules have no specificity, so any rule in your theme
wins. For small changes, set these CSS variables on `.mirlo-catalog`:
`--mirlo-accent`, `--mirlo-catalog-columns`, `--mirlo-catalog-min-width`,
`--mirlo-catalog-gap`, `--mirlo-catalog-radius`, `--mirlo-catalog-image-ratio`
(such as `4 / 5`), `--mirlo-catalog-border` and
`--mirlo-catalog-card-background`. To leave out the plugin's stylesheet
entirely:

    add_filter( 'mirlo_catalog_load_styles', '__return_false' );

## Connecting a Mirlo account

Connecting lets the site read your artist's subscribers, so other plugins can
build on them (for example, giving subscribers access to members-only pages).
It uses Mirlo's OAuth sign-in: you approve the site on Mirlo, and it gets a
token that can only read subscribers, which you can revoke at any time under
**Account settings → Connected apps** on Mirlo.

1. In **Settings → Mirlo Subscribe**, set **Mirlo instance** if your artist
   isn't on mirlo.space, and open **Mirlo account**.
2. Choose how the site identifies itself to Mirlo:
   - **Leave Client ID blank** (HTTPS sites with pretty permalinks). The site
     serves its own [client metadata document](https://docs.mirlo.space/api/oauth.html#a-client-id-metadata-document-recommended)
     at `/wp-json/mirlo/v1/client-metadata`. The first time you connect, the
     instance's admins have to approve the site.
   - **Or open "Use a registered client ID instead"** and enter a client ID
     that the instance's admins registered for you
     under **Admin → API clients**, with the redirect URI shown on the
     settings page and **Server-side app** left unticked. A client ID only
     works on the instance that issued it, so changing **Mirlo Instance**
     clears it.
3. Click **Connect to Mirlo**, which saves the settings first, and approve
   the site on Mirlo, signed in as an account that manages the artist.

Use **Preview subscribers** to check it works.

The site renews its access once a day, so it stays connected as long as it
gets a visit most days. Mirlo still ends every connection after a year, and
you can end it sooner under **Connected apps** on Mirlo. When that happens, a
warning appears on every admin page until you reconnect or dismiss it.

### For developers

    $api = new Mirlo_API();
    $result = $api->get_subscribers( 'artist-slug', true ); // true: paying only, false: followers only, null: both
    if ( isset( $result['error'] ) ) { /* not connected, or no access */ }
    foreach ( $result['results'] as $subscriber ) {
        // id, email, name, tierId, tierName, paid, amount, currency,
        // status ('active' or 'cancelling'), currentPeriodEnd, createdAt, updatedAt
    }

Anyone missing from the list is no longer subscribed. A `cancelling`
subscription is still active until `currentPeriodEnd`.

- `Mirlo_OAuth::is_connected()` and `Mirlo_OAuth::get_account()` report the connection.
- `mirlo_oauth_scopes` filters the scopes requested when connecting. The default is `openid profile subscribers:read offline_access`.
- `mirlo_oauth_connected` (with the account) and `mirlo_oauth_disconnected` fire when the connection changes.

## Installation

1. Upload the `mirlo-subscribe` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Settings → Mirlo Subscribe**. Use **Check an artist** to see their tiers and merch, with the IDs the shortcodes take. If you need them, set the button colour, an API key (for `success_url`) and a connected Mirlo account.
4. Add the shortcode to any post or page.

## Working on the plugin

You can preview changes without setting up a web server or a Mirlo account. You need Node 20 or later.

    npm run dev

This starts WordPress in [WordPress Playground](https://wordpress.github.io/wordpress-playground/) at http://127.0.0.1:9400, with this folder installed as the plugin. You're logged in as an admin, and it opens on a **Mirlo preview** page that shows every shortcode. Edit the plugin's PHP, CSS or JS and reload the page to see the change. The site starts fresh each time you run the command.

The site talks to a fake Mirlo instead of the real one ([dev/mu-plugins/mock-mirlo.php](dev/mu-plugins/mock-mirlo.php)), with made-up artists, tiers and subscribers:

- **Connect to Mirlo** in **Settings → Mirlo Subscribe** opens a fake approval page where you can allow or deny the connection.
- Use the artist slugs `missing`, `broken`, `no-tiers`, `no-merch`, `no-readers`, `old-mirlo`, `slow` and `no-subscribers` to see error, empty and loading states.
- Checkout goes to a fake payment page.
- The fake artist has two card readers, one offline. A sale sent to a reader is paid on the third status check (about six seconds).
- Visit `/?mock_mirlo=end-connection` to pretend Mirlo ended the connection, and see the warning.
- To change what the preview page shows, edit [dev/preview-page.html](dev/preview-page.html).
- To connect to a real Mirlo instead, set **Mirlo instance** to it and save. That clears the dev **Client ID**. The dev site isn't on HTTPS, so it can't identify itself with its metadata document. Instead, register a client on that instance under **Admin → API clients**: give it the redirect URI `http://127.0.0.1:9400/wp-admin/admin-post.php?action=mirlo_oauth_callback`, leave **Server-side app** unticked, and enter its number as **Client ID**.

`npm test` runs end-to-end checks against the fake Mirlo in a throwaway WordPress. It needs Docker, and prints what it finds for you to read ([dev/tests/run.sh](dev/tests/run.sh)).

`composer install` then `composer lint` checks the PHP against the WordPress coding standards ([phpcs.xml.dist](phpcs.xml.dist)); `composer lint:fix` fixes what it can.

Nothing in `dev/`, `package.json`, `composer.json` or `phpcs.xml.dist` is included in the release zip.

## Changelog

1.0.0 Initial release
1.1.0 Add short code for displaying tiers
1.2.0 Connect a Mirlo account over OAuth to read subscribers, renewed daily, with a warning on every admin page if Mirlo ends it; choose the Mirlo instance; deleting the plugin removes its settings and disconnects from Mirlo; `[mirlo_subscribe]` and `[mirlo_tiers]` now use the same checkout as `[mirlo_pop_up]` and `[mirlo_tier_list]`, since Mirlo removed the endpoint they used
1.3.0 `Mirlo_API::purchase_subscription()` takes an optional monthly amount, for tiers where people choose what they pay
1.3.1 `success_url` must be a page on this site or on Mirlo, and is checked again when someone checks out; artist details are kept for 15 minutes; visitors see a short message instead of Mirlo's raw error when tiers can't load or checkout fails (admins still see the details); `Mirlo_API::return_url()` and the artist cache are public, for add-ons
1.4.0 `[mirlo_catalog]` shows an artist's merch and tiers as cards that go straight to Mirlo's checkout; `Mirlo_API::get_artist_merch()` and `purchase_merch()`
1.5.0 Staff with the `mirlo_use_reader` capability can send `[mirlo_catalog]` sales to the artist's card readers with `reader="yes"`, once **Card Reader Sales** is on and the site has connected with Mirlo's `pos` permission; `Mirlo_API::get_artist_readers()`, `purchase_on_reader()`, `get_reader_purchase()` and `cancel_reader_purchase()`; a tidier settings page: the Mirlo account is an optional section, **Connect to Mirlo** saves the settings first, **Check an artist** lists tiers and merch with their IDs, the shortcode reference covers every shortcode, and the unused **Default Button Text** setting is gone
