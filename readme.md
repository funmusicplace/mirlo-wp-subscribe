# Mirlo Subscribe WordPress plug-in

Contributors: Mirlo Code Circle
Tags: mirlo, music, subscriptions, modal
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 1.1.3
License: AGPLv3 or later

Add Mirlo artist subscription modals to any post or page via shortcode.

## Description

Mirlo Subscribe lets you embed a subscription buttons anywhere on your site.

### Button

When clicked, a modal opens showing the artist's subscription tiers fetched
live from the Mirlo API.

Shortcode usage:

    [mirlo_subscribe artist="your-artist-slug"]
    [mirlo_subscribe artist="your-artist-slug" button_text="Support me" full_width="true"]

### Tiers

Displays the tiers in a list and a user can click on them

Usage:
[mirlo_tiers artist="your-artist-slug"]

## Installation

1. Upload the `mirlo-subscribe` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Settings → Mirlo Subscribe** to configure your API key and button appearance.
4. Add the shortcode to any post or page.

## Changelog

1.0.0 Initial release
1.1.0 Add short code for displaying tiers
