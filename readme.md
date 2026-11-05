# Mirlo Subscribe WordPress plug-in

Contributors: Mirlo Code Circle
Tags: mirlo, music, subscriptions, modal
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 1.2.0
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
a full URL or a path on this site, such as `/thanks`. It needs an **API Key**
in the settings (ask hi@mirlo.space): without one, Mirlo only lets buyers
return to Mirlo itself. The original `[mirlo_subscribe]` and `[mirlo_tiers]` shortcodes
still work and show the same as these.

## Connecting a Mirlo account

Connecting lets the site read your artist's subscribers, so other plugins can
build on them (for example, giving subscribers access to members-only pages).
It uses Mirlo's OAuth sign-in: you approve the site on Mirlo, and it gets a
token that can only read subscribers, which you can revoke at any time under
**Account settings → Connected apps** on Mirlo.

1. In **Settings → Mirlo Subscribe**, set **Mirlo Instance** if your artist
   isn't on mirlo.space.
2. Choose how the site identifies itself to Mirlo:
   - **Leave Client ID blank** (HTTPS sites with pretty permalinks). The site
     serves its own [client metadata document](https://docs.mirlo.space/api/oauth.html#a-client-id-metadata-document-recommended)
     at `/wp-json/mirlo/v1/client-metadata`. The first time you connect, the
     instance's admins have to approve the site.
   - **Or enter a Client ID** that the instance's admins registered for you
     under **Admin → API clients**, with the redirect URI shown on the
     settings page and **Server-side app** left unticked. A client ID only
     works on the instance that issued it, so changing **Mirlo Instance**
     clears it.
3. Save, then click **Connect to Mirlo** and approve the site on Mirlo, signed
   in as an account that manages the artist.

Use **Preview Subscribers** to check it works.

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
3. Go to **Settings → Mirlo Subscribe** to set the button appearance and, if you need them, an API key (for `success_url`) and a connected Mirlo account.
4. Add the shortcode to any post or page.

## Working on the plugin

You can preview changes without setting up a web server or a Mirlo account. You need Node 20 or later.

    npm run dev

This starts WordPress in [WordPress Playground](https://wordpress.github.io/wordpress-playground/) at http://127.0.0.1:9400, with this folder installed as the plugin. You're logged in as an admin, and it opens on a **Mirlo preview** page that shows every shortcode. Edit the plugin's PHP, CSS or JS and reload the page to see the change. The site starts fresh each time you run the command.

The site talks to a fake Mirlo instead of the real one ([dev/mu-plugins/mock-mirlo.php](dev/mu-plugins/mock-mirlo.php)), with made-up artists, tiers and subscribers:

- **Connect to Mirlo** in **Settings → Mirlo Subscribe** opens a fake approval page where you can allow or deny the connection.
- Use the artist slugs `missing`, `broken`, `no-tiers`, `slow` and `no-subscribers` to see error, empty and loading states.
- Checkout goes to a fake payment page.
- Visit `/?mock_mirlo=end-connection` to pretend Mirlo ended the connection, and see the warning.
- To change what the preview page shows, edit [dev/preview-page.html](dev/preview-page.html).
- To connect to a real Mirlo instead, set **Mirlo Instance** to it and save. That clears the dev **Client ID**. The dev site isn't on HTTPS, so it can't identify itself with its metadata document. Instead, register a client on that instance under **Admin → API clients**: give it the redirect URI `http://127.0.0.1:9400/wp-admin/admin-post.php?action=mirlo_oauth_callback`, leave **Server-side app** unticked, and enter its number as **Client ID**.

Nothing in `dev/` or `package.json` is included in the release zip.

## Changelog

1.0.0 Initial release
1.1.0 Add short code for displaying tiers
1.2.0 Connect a Mirlo account over OAuth to read subscribers, renewed daily, with a warning on every admin page if Mirlo ends it; choose the Mirlo instance; deleting the plugin removes its settings and disconnects from Mirlo; `[mirlo_subscribe]` and `[mirlo_tiers]` now use the same checkout as `[mirlo_pop_up]` and `[mirlo_tier_list]`, since Mirlo removed the endpoint they used
