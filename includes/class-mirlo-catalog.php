<?php

/**
 * The [mirlo_catalog] shortcode: an artist's merch and subscription tiers as
 * cards, each with a button that starts Mirlo's hosted checkout.
 *
 * Merch that needs a choice Mirlo's checkout page can't make (a required
 * variant such as a size, or a shipping region out of several) links to the
 * item's page on Mirlo instead, which has the full form.
 *
 * With reader="yes", people with the mirlo_use_reader capability also get
 * card reader sales, sent to one of the artist's Stripe card readers through
 * the connected Mirlo account (pos scope): merch goes into a sale ("Add to
 * sale") that's charged on the reader in one go, and each paid tier has its
 * own "Pay on reader" form, since Mirlo sells a subscription on its own.
 */
class Mirlo_Catalog {
    const SECTIONS = array( 'merch', 'tiers' );

    /**
     * Lets someone see and use the card reader sales, which charge
     * through the connected Mirlo account. Administrators get it once; give
     * it to other roles or users (e.g. volunteers at the merch table) with a
     * role editor or a user_has_cap filter.
     */
    const READER_CAP = 'mirlo_use_reader';

    /** Set once the capability has been given to administrators, so removing it sticks. */
    const READER_CAP_OPTION = 'mirlo_reader_cap_added';

    /** How long the page waits for a card before it stops checking. */
    const READER_TIMEOUT = 180;

    public function __construct() {
        add_shortcode( 'mirlo_catalog', array( $this, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
        add_action( 'wp_ajax_mirlo_purchase_merch', array( $this, 'ajax_purchase_merch' ) );
        add_action( 'wp_ajax_nopriv_mirlo_purchase_merch', array( $this, 'ajax_purchase_merch' ) );
        // Signed-in users only: no nopriv versions.
        add_action( 'wp_ajax_mirlo_reader_charge', array( $this, 'ajax_reader_charge' ) );
        add_action( 'wp_ajax_mirlo_reader_status', array( $this, 'ajax_reader_status' ) );
        add_action( 'wp_ajax_mirlo_reader_cancel', array( $this, 'ajax_reader_cancel' ) );
        // Activation doesn't run on updates, so also check on admin pages.
        add_action( 'admin_init', array( __CLASS__, 'add_reader_capability' ) );
    }

    /** Give administrators the reader capability, once. */
    public static function add_reader_capability() {
        if ( get_option( self::READER_CAP_OPTION ) ) {
            return;
        }
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( self::READER_CAP );
        }
        update_option( self::READER_CAP_OPTION, 1, false );
    }

    /**
     * Usage: [mirlo_catalog artist="slugname"]
     *
     * show         Which sections, in that order: "merch,tiers" (default), "merch" or "tiers".
     * merch_ids    Only these merch items, in that order (Mirlo merch IDs).
     * tier_ids     Only these subscription tiers, in that order.
     * columns      The most cards in a row (default 3). Fewer fit on narrow screens.
     * color        Button colour, as #hex.
     * merch_title  Heading above the merch. Empty for none. Default "Merch".
     * tiers_title  Heading above the tiers. Empty for none. Default "Subscriptions".
     * success_url  Where buyers land after paying, as for [mirlo_pop_up].
     * reader       "yes" adds card reader sales: merch is added to a sale that's
     *              charged on one of the artist's Stripe card readers, and
     *              paid tiers get "Pay on reader". Only people with the
     *              mirlo_use_reader capability see them, and only once card
     *              reader sales are on in the settings.
     */
    public function render( $atts ) {
        $atts = shortcode_atts(
            array(
                'artist'      => '',
                'show'        => 'merch,tiers',
                'merch_ids'   => '',
                'tier_ids'    => '',
                'columns'     => '',
                'color'       => '',
                'merch_title' => 'Merch',
                'tiers_title' => 'Subscriptions',
                'success_url' => '',
                'reader'      => '',
            ),
            $atts,
            'mirlo_catalog'
        );

        if ( empty( $atts['artist'] ) ) {
            return '<p class="mirlo-error">Error: <code>artist</code> slug not specified.</p>';
        }

        $this->enqueue_assets();

        $sections = array_values(
            array_intersect(
                array_map( 'trim', explode( ',', strtolower( $atts['show'] ) ) ),
                self::SECTIONS
            )
        );
        if ( empty( $sections ) ) {
            $sections = self::SECTIONS;
        }

        $reader = $this->reader_context( $atts );

        $html = $reader ? $this->reader_bar( $reader, in_array( 'merch', $sections, true ) ) : '';
        foreach ( $sections as $section ) {
            $html .= 'merch' === $section
                ? $this->render_merch_section( $atts, $reader )
                : $this->render_tiers_section( $atts, $reader );
        }

        $style   = array();
        $color   = sanitize_hex_color( $atts['color'] );
        $columns = absint( $atts['columns'] );
        if ( $color ) {
            $style[] = '--mirlo-accent:' . $color;
        }
        if ( $columns ) {
            $style[] = '--mirlo-catalog-columns:' . min( $columns, 12 );
        }

        $success_url = Mirlo_API::return_url( $atts['success_url'] );

        return sprintf(
            '<div class="mirlo-catalog" data-artist="%s"%s%s>%s</div>',
            esc_attr( $atts['artist'] ),
            $success_url ? ' data-success-url="' . esc_attr( $success_url ) . '"' : '',
            $style ? ' style="' . esc_attr( implode( ';', $style ) ) . '"' : '',
            $html
        );
    }

    /**
     * What the reader buttons need, for someone allowed to use them: the
     * artist's ID and readers. Null when there are no reader buttons. With
     * a 'notice' instead, the buttons can't show and that says why.
     */
    private function reader_context( $atts ) {
        $on = in_array( strtolower( trim( $atts['reader'] ) ), array( 'yes', 'true', '1' ), true );
        if ( ! $on || ! current_user_can( self::READER_CAP ) ) {
            return null;
        }
        if ( ! get_option( 'mirlo_reader_sales' ) || ! Mirlo_OAuth::has_scope( 'pos' ) ) {
            return array(
                'notice' => current_user_can( 'manage_options' )
                    ? 'Card reader sales aren\'t set up. Turn them on in Settings → Mirlo Subscribe, then connect to Mirlo again.'
                    : 'Card reader sales aren\'t set up yet. Ask a site admin.',
            );
        }

        $api    = new Mirlo_API();
        $artist = $api->get_artist( $atts['artist'] );
        if ( empty( $artist['result']['id'] ) ) {
            // The sections say the artist couldn't load.
            return null;
        }
        $artist_id = (int) $artist['result']['id'];
        $readers   = $api->get_artist_readers( $artist_id );

        if ( isset( $readers['error'] ) ) {
            return array(
                'notice' => current_user_can( 'manage_options' )
                    ? 'Couldn\'t load the card readers from Mirlo: ' . $readers['error'] . ' The connected Mirlo account needs to manage this artist.'
                    : 'Couldn\'t load the card readers. Please try again later.',
            );
        }
        if ( empty( $readers['results'] ) ) {
            return array( 'notice' => 'This artist has no card readers on Mirlo.' );
        }

        // Online readers first.
        $list = array_values( (array) $readers['results'] );
        usort(
            $list,
            function ( $a, $b ) {
                return ( 'online' === ( $b['status'] ?? '' ) ) <=> ( 'online' === ( $a['status'] ?? '' ) );
            }
        );

        return array(
            'artist_id' => $artist_id,
            'readers'   => $list,
        );
    }

    /**
     * The reader menu, and the sale that merch is added to (with $cart; the
     * script fills it in). A sale is kept in the browser until it's paid.
     */
    private function reader_bar( $reader, $cart ) {
        if ( isset( $reader['notice'] ) ) {
            return '<p class="mirlo-catalog__reader-notice">' . esc_html( $reader['notice'] ) . '</p>';
        }
        $options = '';
        foreach ( $reader['readers'] as $r ) {
            $name     = ! empty( $r['label'] ) ? $r['label'] : $r['id'];
            $offline  = 'online' !== ( $r['status'] ?? '' );
            $options .= sprintf(
                '<option value="%s">%s</option>',
                esc_attr( (string) $r['id'] ),
                esc_html( $name . ( $offline ? ' (offline)' : '' ) )
            );
        }
        $sale = '';
        if ( $cart ) {
            $sale = '<form class="mirlo-catalog__reader-form mirlo-catalog__sale" data-mirlo-reader="cart" aria-label="Sale">'
                . '<p class="mirlo-catalog__sale-empty">Nothing in this sale yet. Add merch below.</p>'
                . '<ul class="mirlo-catalog__sale-lines" hidden></ul>'
                . '<p class="mirlo-catalog__sale-total" hidden></p>'
                . '<label class="mirlo-catalog__field">Buyer\'s email (optional, needed for downloads) <input type="email" name="email" autocomplete="off"></label>'
                . '<div class="mirlo-catalog__sale-actions">'
                . '<button type="submit" class="mirlo-catalog__buy" data-label="Charge on reader" disabled>Charge on reader</button>'
                . '<button type="button" class="mirlo-catalog__sale-clear" hidden>Clear sale</button></div>'
                . '<p class="mirlo-catalog__reader-status" role="status" hidden></p>'
                . '<button type="button" class="mirlo-catalog__reader-cancel" hidden>Cancel</button></form>';
        }
        return sprintf(
            '<div class="mirlo-catalog__reader" data-artist-id="%s"><label class="mirlo-catalog__reader-label">Card reader '
            . '<select class="mirlo-catalog__reader-select">%s</select></label>%s</div>',
            esc_attr( (string) $reader['artist_id'] ),
            $options,
            $sale
        );
    }

    /** Whether the reader buttons are on and working for this catalog. */
    private static function reader_ready( $reader ) {
        return $reader && ! isset( $reader['notice'] );
    }

    /**
     * Whether a merch item can be sold on a card reader: in stock and sold
     * through Mirlo. Mirlo leaves shipping off sales on a reader.
     */
    public static function reader_eligible( $merch ) {
        if ( isset( $merch['quantityRemaining'] ) && 0 === (int) $merch['quantityRemaining'] ) {
            return false;
        }
        if ( ! empty( $merch['externalUrl'] ) ) {
            return false;
        }
        // Only the merch endpoint sends the variants.
        return isset( $merch['optionTypes'] );
    }

    /**
     * The "Add to sale" form for a merch item, with a menu for each variant
     * type. The script adds it to the sale in the reader bar; the data on it
     * is only for showing the sale; it's all checked again when it's charged.
     */
    private function merch_add_form( $merch ) {
        $fields = '';
        foreach ( (array) $merch['optionTypes'] as $type ) {
            if ( empty( $type['options'] ) ) {
                continue;
            }
            $required = ! empty( $type['required'] );
            $options  = '<option value="">' . ( $required ? 'Choose…' : 'None' ) . '</option>';
            foreach ( $type['options'] as $option ) {
                $left     = isset( $option['quantityRemaining'] ) ? (int) $option['quantityRemaining'] : null;
                $extra    = (int) ( $option['additionalPrice'] ?? 0 );
                $options .= sprintf(
                    '<option value="%s" data-name="%s" data-price="%d"%s%s>%s</option>',
                    esc_attr( (string) ( $option['id'] ?? '' ) ),
                    esc_attr( (string) ( $option['name'] ?? '' ) ),
                    $extra,
                    null === $left ? '' : ' data-stock="' . esc_attr( (string) $left ) . '"',
                    0 === $left ? ' disabled' : '',
                    esc_html( (string) ( $option['name'] ?? '' ) . ( 0 === $left ? ' (sold out)' : '' ) )
                );
            }
            $fields .= sprintf(
                '<label class="mirlo-catalog__field">%s <select name="option_ids[]"%s>%s</select></label>',
                esc_html( ucfirst( (string) ( $type['optionName'] ?? 'Option' ) ) ),
                $required ? ' required' : '',
                $options
            );
        }
        return sprintf(
            '<form class="mirlo-catalog__add-form" data-merch-id="%s" data-title="%s" data-price="%d" data-currency="%s"%s>%s'
            . '<button type="submit" class="mirlo-catalog__buy mirlo-catalog__buy--reader">Add to sale</button></form>',
            esc_attr( (string) $merch['id'] ),
            esc_attr( (string) ( $merch['title'] ?? '' ) ),
            (int) ( $merch['minPrice'] ?? 0 ),
            esc_attr( strtoupper( (string) ( $merch['currency'] ?? 'USD' ) ) ),
            isset( $merch['quantityRemaining'] ) ? ' data-stock="' . esc_attr( (string) (int) $merch['quantityRemaining'] ) . '"' : '',
            $fields
        );
    }

    /** The "Pay on reader" form for a tier: the buyer's email, and the amount if they choose it. */
    private function tier_reader_form( $tier, $currency ) {
        $min    = (int) $tier['minAmount'];
        $fields = '<label class="mirlo-catalog__field">Buyer\'s email <input type="email" name="email" required autocomplete="off"></label>';
        if ( ! empty( $tier['allowVariable'] ) ) {
            $fields .= sprintf(
                '<label class="mirlo-catalog__field">Amount a month (%s) <input type="number" name="amount" min="%s" step="0.01" value="%s" required></label>',
                esc_html( $currency ),
                esc_attr( number_format( $min / 100, 2, '.', '' ) ),
                esc_attr( number_format( $min / 100, 2, '.', '' ) )
            );
        }
        return $this->reader_form( 'tier', array( 'tier-id' => (string) $tier['id'] ), $fields );
    }

    private function reader_form( $kind, $data, $fields ) {
        $attrs = '';
        foreach ( $data as $key => $value ) {
            $attrs .= ' data-' . $key . '="' . esc_attr( $value ) . '"';
        }
        return sprintf(
            '<form class="mirlo-catalog__reader-form" data-mirlo-reader="%s"%s>%s'
            . '<button type="submit" class="mirlo-catalog__buy mirlo-catalog__buy--reader" data-label="Pay on reader">Pay on reader</button>'
            . '<p class="mirlo-catalog__reader-status" role="status" hidden></p>'
            . '<button type="button" class="mirlo-catalog__reader-cancel" hidden>Cancel</button></form>',
            esc_attr( $kind ),
            $attrs,
            $fields
        );
    }

    private function render_merch_section( $atts, $reader = null ) {
        $api   = new Mirlo_API();
        $data  = $api->get_artist_merch( $atts['artist'] );
        $items = null;

        if ( isset( $data['error'] ) ) {
            // A Mirlo without the merch endpoint yet: fall back to the merch
            // on the artist, which has no variants or shipping regions, so
            // every item links to Mirlo.
            if ( 404 === ( $data['error_status'] ?? null ) ) {
                $artist = $api->get_artist( $atts['artist'] );
                if ( isset( $artist['result']['id'] ) ) {
                    $items = $artist['result']['merch'] ?? array();
                }
            }
            if ( null === $items ) {
                return $this->section( 'merch', $atts['merch_title'], $this->error( self::load_error( 'merch', $data['error'] ) ) );
            }
        } else {
            $items = $data['results'] ?? array();
        }

        $merch_ids = array_filter( array_map( 'trim', explode( ',', $atts['merch_ids'] ) ) );
        $items     = self::pick( $items, $merch_ids, 'strval' );

        if ( empty( $items ) ) {
            return $this->section( 'merch', $atts['merch_title'], '<p class="mirlo-catalog__empty">No merch available.</p>' );
        }

        $cards = '';
        foreach ( $items as $merch ) {
            $cards .= $this->merch_card( $merch, $atts['artist'], self::reader_ready( $reader ) );
        }
        return $this->section( 'merch', $atts['merch_title'], '<ul class="mirlo-catalog__items">' . $cards . '</ul>' );
    }

    private function merch_card( $merch, $artist_slug, $reader = false ) {
        $plan     = self::checkout_plan( $merch );
        $currency = strtoupper( $merch['currency'] ?? 'USD' );
        $title    = (string) ( $merch['title'] ?? '' );
        $classes  = 'mirlo-catalog__item mirlo-catalog__item--merch';
        if ( 'sold_out' === $plan['mode'] ) {
            $classes .= ' mirlo-catalog__item--sold-out';
        }

        $image = self::image_url( $merch['images'][0]['sizes'] ?? array() );
        $image = $image
            ? '<img class="mirlo-catalog__image" src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" loading="lazy">'
            : '<div class="mirlo-catalog__image mirlo-catalog__image--none" aria-hidden="true"></div>';

        $stock = '';
        if ( isset( $merch['quantityRemaining'] ) && $merch['quantityRemaining'] > 0 ) {
            $stock = '<p class="mirlo-catalog__stock">' . esc_html( (int) $merch['quantityRemaining'] . ' left' ) . '</p>';
        }

        switch ( $plan['mode'] ) {
            case 'checkout':
                $button = sprintf(
                    '<button type="button" class="mirlo-catalog__buy" data-mirlo-buy="merch" data-merch-id="%s">Buy</button>',
                    esc_attr( (string) $merch['id'] )
                );
                break;
            case 'external':
                $button = '<a class="mirlo-catalog__buy mirlo-catalog__buy--link" href="' . esc_url( $merch['externalUrl'] ) . '">Buy</a>';
                break;
            case 'sold_out':
                $button = '<span class="mirlo-catalog__buy mirlo-catalog__buy--sold-out">Sold out</span>';
                break;
            default:
                $button = '<a class="mirlo-catalog__buy mirlo-catalog__buy--link" href="' . esc_url( self::merch_url( $merch, $artist_slug ) ) . '">Buy on Mirlo</a>';
        }

        return sprintf(
            '<li class="%s">%s<div class="mirlo-catalog__body"><h3 class="mirlo-catalog__title">%s</h3>%s%s</div>'
            . '<div class="mirlo-catalog__actions">%s<p class="mirlo-catalog__error" role="alert" hidden></p>%s</div></li>',
            esc_attr( $classes ),
            $image,
            esc_html( $title ),
            self::price( (int) ( $merch['minPrice'] ?? 0 ), $currency ),
            $stock,
            $button,
            $reader && self::reader_eligible( $merch ) ? $this->merch_add_form( $merch ) : ''
        );
    }

    private function render_tiers_section( $atts, $reader = null ) {
        $api  = new Mirlo_API();
        $data = $api->get_artist( $atts['artist'] );

        if ( isset( $data['error'] ) ) {
            return $this->section( 'tiers', $atts['tiers_title'], $this->error( self::load_error( 'tiers', $data['error'] ) ) );
        }
        if ( empty( $data['result']['id'] ) ) {
            return $this->section( 'tiers', $atts['tiers_title'], $this->error( 'Artist not found.' ) );
        }

        $artist   = $data['result'];
        $currency = strtoupper( $artist['user']['currency'] ?? 'USD' );
        $tier_ids = array_filter( array_map( 'intval', explode( ',', $atts['tier_ids'] ) ) );
        $tiers    = self::pick( $artist['subscriptionTiers'] ?? array(), $tier_ids, 'intval' );

        if ( empty( $tiers ) ) {
            return $this->section( 'tiers', $atts['tiers_title'], '<p class="mirlo-catalog__empty">No subscription tiers available.</p>' );
        }

        $cards = '';
        foreach ( $tiers as $tier ) {
            $price = isset( $tier['minAmount'] ) && null !== $tier['minAmount']
                ? self::price( (int) $tier['minAmount'], strtoupper( $tier['currency'] ?? $currency ), '/month' )
                : '<p class="mirlo-catalog__price">Free</p>';

            $description = ! empty( $tier['description'] )
                ? '<p class="mirlo-catalog__description">' . esc_html( $tier['description'] ) . '</p>'
                : '';

            // A free tier has nothing to pay on a reader.
            $reader_form = self::reader_ready( $reader ) && (int) ( $tier['minAmount'] ?? 0 ) > 0
                ? $this->tier_reader_form( $tier, strtoupper( $tier['currency'] ?? $currency ) )
                : '';

            $cards .= sprintf(
                '<li class="mirlo-catalog__item mirlo-catalog__item--tier"><div class="mirlo-catalog__body"><h3 class="mirlo-catalog__title">%s</h3>%s%s</div>'
                . '<div class="mirlo-catalog__actions"><button type="button" class="mirlo-catalog__buy" data-mirlo-buy="tier" data-artist-id="%s" data-tier-id="%s">Subscribe</button>'
                . '<p class="mirlo-catalog__error" role="alert" hidden></p>%s</div></li>',
                esc_html( $tier['name'] ?? '' ),
                $price,
                $description,
                esc_attr( (string) $artist['id'] ),
                esc_attr( (string) $tier['id'] ),
                $reader_form
            );
        }
        return $this->section( 'tiers', $atts['tiers_title'], '<ul class="mirlo-catalog__items">' . $cards . '</ul>' );
    }

    /**
     * How a merch item can be bought from this site.
     *
     * Mirlo's hosted checkout asks for the buyer's address but can't choose
     * a variant or a shipping region, so it only works when there's nothing
     * to choose: no required variants, and at most one shipping region (not
     * the EU one, which Mirlo wants as a country code).
     *
     * @return array {
     *     @type string      $mode                  'checkout', 'mirlo' (link to the item on Mirlo),
     *                                              'external' (the artist's own shop) or 'sold_out'.
     *     @type string|null $shippingDestinationId The region to send with 'checkout'.
     * }
     */
    public static function checkout_plan( $merch ) {
        $plan = array(
            'mode'                  => 'mirlo',
            'shippingDestinationId' => null,
        );

        if ( isset( $merch['quantityRemaining'] ) && 0 === (int) $merch['quantityRemaining'] ) {
            $plan['mode'] = 'sold_out';
            return $plan;
        }
        if ( ! empty( $merch['externalUrl'] ) ) {
            $plan['mode'] = 'external';
            return $plan;
        }
        // Only the merch endpoint sends these. Without them we can't tell.
        if ( ! isset( $merch['optionTypes'], $merch['shippingDestinations'] ) ) {
            return $plan;
        }
        foreach ( (array) $merch['optionTypes'] as $type ) {
            if ( ! empty( $type['required'] ) && ! empty( $type['options'] ) ) {
                return $plan;
            }
        }
        $regions = (array) $merch['shippingDestinations'];
        if ( count( $regions ) > 1 ) {
            return $plan;
        }
        if ( 1 === count( $regions ) ) {
            if ( 'EU' === ( $regions[0]['destinationCountry'] ?? '' ) || empty( $regions[0]['id'] ) ) {
                return $plan;
            }
            $plan['shippingDestinationId'] = (string) $regions[0]['id'];
        }
        $plan['mode'] = 'checkout';
        return $plan;
    }

    public function ajax_purchase_merch() {
        check_ajax_referer( 'mirlo_nonce_v2', 'nonce' );

        $artist   = isset( $_POST['artist'] ) ? sanitize_text_field( wp_unslash( $_POST['artist'] ) ) : '';
        $merch_id = isset( $_POST['merch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['merch_id'] ) ) : '';
        // Sent by the browser, so check it again: only this site or Mirlo.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $success_url = isset( $_POST['success_url'] ) ? Mirlo_API::return_url( wp_unslash( $_POST['success_url'] ) ) : '';

        if ( '' === $artist || '' === $merch_id ) {
            wp_send_json_error( array( 'message' => 'Missing artist or merch.' ) );
        }

        // Work out the shipping region here rather than trusting the page,
        // from fresh data so the stock is current.
        $api   = new Mirlo_API();
        $data  = $api->get_artist_merch( $artist, true );
        $merch = null;
        foreach ( $data['results'] ?? array() as $item ) {
            if ( (string) ( $item['id'] ?? '' ) === $merch_id ) {
                $merch = $item;
            }
        }
        $plan = $merch ? self::checkout_plan( $merch ) : null;

        if ( ! $plan || 'checkout' !== $plan['mode'] || empty( $merch['artistId'] ) ) {
            wp_send_json_error(
                array(
                    'message' => 'This item can\'t be bought here. Please try again later.',
                    'detail'  => current_user_can( 'manage_options' ) ? ( $data['error'] ?? 'Not found, or needs choices made on Mirlo' ) : null,
                )
            );
        }

        $result = $api->purchase_merch( $merch['artistId'], $merch_id, $success_url, $plan['shippingDestinationId'] );

        if ( isset( $result['error'] ) || empty( $result['redirectUrl'] ) ) {
            wp_send_json_error(
                array(
                    'message' => 'Couldn\'t start the checkout. Please try again.',
                    'detail'  => current_user_can( 'manage_options' ) ? ( $result['error'] ?? 'No redirectUrl' ) : null,
                )
            );
        }

        wp_send_json_success( array( 'redirectUrl' => $result['redirectUrl'] ) );
    }

    /* ------------------------------------------------------------------
     * Card reader sales
     *
     * The page sends what to sell and which reader; everything is checked
     * again here against what Mirlo says, then sent to Mirlo as the
     * connected account. Mirlo checks that account manages the artist.
     * ------------------------------------------------------------------ */

    private static function reader_guard() {
        check_ajax_referer( 'mirlo_reader', 'nonce' );
        if ( ! current_user_can( self::READER_CAP ) || ! get_option( 'mirlo_reader_sales' ) ) {
            wp_send_json_error( array( 'message' => 'You can\'t use the card reader.' ), 403 );
        }
    }

    private static function reader_fail( $message, $detail = null ) {
        wp_send_json_error(
            array(
                'message' => $message,
                'detail'  => current_user_can( 'manage_options' ) ? $detail : null,
            )
        );
    }

    /** A Stripe-style ID from the request (pi_…, seti_…, acct_…, tmr_…), or ''. */
    private static function id_param( $name, $pattern = '/^[A-Za-z0-9_]{1,255}$/' ) {
        $value = isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- reader_guard() checked it.
        return preg_match( $pattern, $value ) ? $value : '';
    }

    /** The most lines one sale can have. */
    const CART_MAX_LINES = 50;

    /**
     * The items for a sale's merch, from the page's cart: a JSON list of
     * { merch_id, qty, option_ids }. Each line is checked against fresh
     * data from Mirlo, and the quantities are added up across lines, since
     * Mirlo checks the stock of each line on its own.
     *
     * @return array Purchase items for Mirlo. Fails the request if any line can't be sold.
     */
    private static function cart_items( $api, $artist ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reader_guard() checked the nonce; each field is checked below.
        $lines = isset( $_POST['cart'] ) ? json_decode( wp_unslash( $_POST['cart'] ), true ) : null;
        if ( ! is_array( $lines ) || ! $lines ) {
            self::reader_fail( 'Nothing in this sale yet.' );
        }
        if ( count( $lines ) > self::CART_MAX_LINES ) {
            self::reader_fail( sprintf( 'A sale can have up to %d lines.', self::CART_MAX_LINES ) );
        }

        $data = $api->get_artist_merch( $artist, true );
        if ( isset( $data['error'] ) ) {
            self::reader_fail( 'Couldn\'t load the merch. Please try again.', $data['error'] );
        }
        $by_id = array();
        foreach ( (array) ( $data['results'] ?? array() ) as $merch ) {
            $by_id[ (string) ( $merch['id'] ?? '' ) ] = $merch;
        }

        $items       = array();
        $merch_count = array();
        $option_qty  = array();
        foreach ( $lines as $line ) {
            $merch_id = is_array( $line ) && is_scalar( $line['merch_id'] ?? null ) ? (string) $line['merch_id'] : '';
            $qty      = is_array( $line ) && is_numeric( $line['qty'] ?? null ) ? (int) $line['qty'] : 0;
            $merch    = $by_id[ $merch_id ] ?? null;
            $title    = (string) ( $merch['title'] ?? 'An item' );

            if ( ! $merch || ! self::reader_eligible( $merch ) ) {
                self::reader_fail( $title . ' can\'t be sold on the card reader any more. Remove it from the sale.' );
            }
            if ( $qty < 1 || $qty > 999 ) {
                self::reader_fail( 'Check the quantity of ' . $title . '.' );
            }

            $chosen     = array_map( 'strval', array_filter( (array) ( $line['option_ids'] ?? array() ), 'is_scalar' ) );
            $option_ids = array();
            foreach ( (array) $merch['optionTypes'] as $type ) {
                $options = (array) ( $type['options'] ?? array() );
                $picked  = array_values( array_intersect( $chosen, array_map( 'strval', array_column( $options, 'id' ) ) ) );
                $name    = (string) ( $type['optionName'] ?? 'option' );
                if ( count( $picked ) > 1 ) {
                    self::reader_fail( 'Choose one ' . $name . ' for ' . $title . '.' );
                }
                if ( ! $picked && ! empty( $type['required'] ) && $options ) {
                    self::reader_fail( 'Choose a ' . $name . ' for ' . $title . '.' );
                }
                foreach ( $options as $option ) {
                    $id = (string) ( $option['id'] ?? '' );
                    if ( ! in_array( $id, $picked, true ) ) {
                        continue;
                    }
                    $option_qty[ $id ] = ( $option_qty[ $id ] ?? 0 ) + $qty;
                    if ( isset( $option['quantityRemaining'] ) && $option_qty[ $id ] > (int) $option['quantityRemaining'] ) {
                        self::reader_fail( sprintf( 'Only %d of %s (%s) left.', (int) $option['quantityRemaining'], $title, (string) ( $option['name'] ?? '' ) ) );
                    }
                }
                $option_ids = array_merge( $option_ids, $picked );
            }

            $merch_count[ $merch_id ] = ( $merch_count[ $merch_id ] ?? 0 ) + $qty;
            if ( isset( $merch['quantityRemaining'] ) && $merch_count[ $merch_id ] > (int) $merch['quantityRemaining'] ) {
                self::reader_fail( sprintf( 'Only %d of %s left.', (int) $merch['quantityRemaining'], $title ) );
            }

            $item = array(
                'type'     => 'merch',
                'id'       => $merch_id,
                'quantity' => $qty,
            );
            if ( $option_ids ) {
                $item['merchOptionIds'] = $option_ids;
            }
            $items[] = $item;
        }
        return $items;
    }

    public function ajax_reader_charge() {
        self::reader_guard();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- reader_guard() checked it.
        $artist    = isset( $_POST['artist'] ) ? sanitize_text_field( wp_unslash( $_POST['artist'] ) ) : '';
        $kind      = isset( $_POST['kind'] ) ? sanitize_key( $_POST['kind'] ) : '';
        $reader_id = self::id_param( 'reader_id' );

        if ( '' === $artist || '' === $reader_id ) {
            self::reader_fail( 'Choose a card reader.' );
        }

        $api  = new Mirlo_API();
        $data = $api->get_artist( $artist );
        if ( empty( $data['result']['id'] ) ) {
            self::reader_fail( 'Couldn\'t load the artist. Please try again.', $data['error'] ?? 'No artist' );
        }
        $artist_id = (int) $data['result']['id'];

        // Only the artist's own readers.
        $readers = $api->get_artist_readers( $artist_id );
        if ( ! in_array( $reader_id, array_column( (array) ( $readers['results'] ?? array() ), 'id' ), true ) ) {
            self::reader_fail( 'That card reader isn\'t available. Reload the page and try again.', $readers['error'] ?? null );
        }

        $email = null;
        if ( 'cart' === $kind ) {
            $items = self::cart_items( $api, $artist );
            // Optional: Mirlo asks for it when something comes with a download.
            $email = isset( $_POST['email'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['email'] ) ) ) : '';
            if ( '' !== $email ) {
                // sanitize_email() empties what isn't an address, so check first.
                if ( ! is_email( $email ) ) {
                    self::reader_fail( 'That email address doesn\'t look right.' );
                }
                $email = sanitize_email( $email );
            }
        } elseif ( 'tier' === $kind ) {
            $tier_id = isset( $_POST['tier_id'] ) ? absint( $_POST['tier_id'] ) : 0;
            $tier    = null;
            foreach ( (array) ( $data['result']['subscriptionTiers'] ?? array() ) as $candidate ) {
                if ( $tier_id && (int) ( $candidate['id'] ?? 0 ) === $tier_id ) {
                    $tier = $candidate;
                }
            }
            if ( ! $tier || (int) ( $tier['minAmount'] ?? 0 ) <= 0 ) {
                self::reader_fail( 'This tier can\'t be paid on the card reader.' );
            }

            $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
            if ( ! is_email( $email ) ) {
                self::reader_fail( 'Enter the buyer\'s email address. Mirlo sends them their subscription there.' );
            }

            $item = array(
                'type'   => 'subscription',
                'tierId' => $tier_id,
            );
            if ( ! empty( $tier['allowVariable'] ) && isset( $_POST['amount'] ) && '' !== $_POST['amount'] ) {
                $cents = (int) round( (float) sanitize_text_field( wp_unslash( $_POST['amount'] ) ) * 100 );
                if ( $cents < (int) $tier['minAmount'] ) {
                    self::reader_fail( sprintf( 'The amount must be at least %s.', number_format( $tier['minAmount'] / 100, 2 ) ) );
                }
                $item['amount'] = $cents;
            }
            $items = array( $item );
        } else {
            self::reader_fail( 'Nothing to sell.' );
        }
        // phpcs:enable

        $result = $api->purchase_on_reader( $artist_id, $reader_id, $items, $email ? $email : null );
        $intent = $result['paymentIntentId'] ?? ( $result['setupIntentId'] ?? '' );

        if ( isset( $result['error'] ) || '' === $intent || empty( $result['stripeAccountId'] ) ) {
            $error = (string) ( $result['error'] ?? 'No intent in the response' );
            if ( false !== stripos( $error, 'busy' ) ) {
                $message = 'The card reader is busy with another payment. Finish or cancel that one first.';
            } elseif ( false !== stripos( $error, 'offline' ) || false !== stripos( $error, 'unreachable' ) ) {
                $message = 'The card reader is offline. Check that it\'s on and connected.';
            } elseif ( false !== stripos( $error, 'email' ) ) {
                $message = 'Something in this sale comes with a download. Enter the buyer\'s email so Mirlo can send it.';
            } elseif ( false !== stripos( $error, 'stock' ) || false !== stripos( $error, 'sold out' ) ) {
                $message = 'Not enough of something in this sale is left. Reload the page to see what\'s in stock.';
            } else {
                $message = 'Couldn\'t send this to the card reader. Please try again.';
            }
            // Its status may have changed.
            $api->forget_artist_readers( $artist_id );
            self::reader_fail( $message, $error );
        }

        wp_send_json_success(
            array(
                'intentId'  => $intent,
                'accountId' => $result['stripeAccountId'],
                'timeout'   => self::READER_TIMEOUT,
            )
        );
    }

    public function ajax_reader_status() {
        self::reader_guard();
        $intent  = self::id_param( 'intent_id', '/^(pi|seti)_[A-Za-z0-9_]+$/' );
        $account = self::id_param( 'account_id', '/^acct_[A-Za-z0-9_]+$/' );
        if ( '' === $intent || '' === $account ) {
            self::reader_fail( 'Unknown payment.' );
        }

        $result = ( new Mirlo_API() )->get_reader_purchase( $intent, $account );
        if ( isset( $result['error'] ) || empty( $result['result']['status'] ) ) {
            self::reader_fail( 'Couldn\'t check the payment.', $result['error'] ?? 'No status' );
        }
        wp_send_json_success( array( 'status' => (string) $result['result']['status'] ) );
    }

    public function ajax_reader_cancel() {
        self::reader_guard();
        $intent    = self::id_param( 'intent_id', '/^(pi|seti)_[A-Za-z0-9_]+$/' );
        $account   = self::id_param( 'account_id', '/^acct_[A-Za-z0-9_]+$/' );
        $reader_id = self::id_param( 'reader_id' );
        if ( '' === $intent || '' === $account ) {
            self::reader_fail( 'Unknown payment.' );
        }

        $result = ( new Mirlo_API() )->cancel_reader_purchase( $intent, $account, $reader_id );
        if ( isset( $result['error'] ) ) {
            // Most likely paid in the meantime.
            self::reader_fail( 'Couldn\'t cancel. It may already be paid.', $result['error'] );
        }
        wp_send_json_success( array( 'status' => (string) ( $result['result']['status'] ?? 'canceled' ) ) );
    }

    public function register_assets() {
        wp_register_style( 'mirlo-catalog', MIRLO_SUBSCRIBE_URL . 'assets/css/mirlo-catalog.css', array( 'mirlo-modal' ), MIRLO_SUBSCRIBE_VERSION );
        wp_register_script( 'mirlo-catalog', MIRLO_SUBSCRIBE_URL . 'assets/js/mirlo-catalog.js', array(), MIRLO_SUBSCRIBE_VERSION, true );
        wp_localize_script(
            'mirlo-catalog',
            'mirloCatalog',
            array(
                'ajax_url'     => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( 'mirlo_nonce_v2' ),
                // Only for people who can use the card reader.
                'reader_nonce' => current_user_can( self::READER_CAP ) ? wp_create_nonce( 'mirlo_reader' ) : '',
            )
        );

        // Load the stylesheet in the head when the page is known to use the
        // shortcode, so cards don't show unstyled for a moment.
        $post = get_post();
        if ( is_singular() && $post && has_shortcode( $post->post_content, 'mirlo_catalog' ) ) {
            $this->enqueue_assets();
        }
    }

    private function enqueue_assets() {
        /**
         * Whether to load the catalog's stylesheet. Return false to style the
         * cards entirely in your theme.
         */
        if ( apply_filters( 'mirlo_catalog_load_styles', true ) ) {
            wp_enqueue_style( 'mirlo-catalog' );
        }
        wp_enqueue_script( 'mirlo-catalog' );
    }

    private function section( $name, $title, $content ) {
        $heading = '' !== trim( $title )
            ? '<h2 class="mirlo-catalog__heading">' . esc_html( $title ) . '</h2>'
            : '';
        return '<section class="mirlo-catalog__section mirlo-catalog__section--' . esc_attr( $name ) . '">' . $heading . $content . '</section>';
    }

    private function error( $message ) {
        return '<p class="mirlo-error mirlo-catalog__load-error">' . esc_html( $message ) . '</p>';
    }

    private static function load_error( $what, $error ) {
        $label = 'merch' === $what ? 'merch' : 'subscription tiers';
        return current_user_can( 'manage_options' )
            ? 'Couldn\'t load the ' . $label . ' from Mirlo: ' . $error
            : 'Couldn\'t load the ' . $label . '. Please try again later.';
    }

    /**
     * The items whose IDs are listed, in that order, or all of them if none are.
     */
    private static function pick( $items, $ids, $cast ) {
        if ( empty( $ids ) ) {
            return array_values( (array) $items );
        }
        $by_id = array();
        foreach ( (array) $items as $item ) {
            $by_id[ call_user_func( $cast, $item['id'] ?? '' ) ] = $item;
        }
        $picked = array();
        foreach ( $ids as $id ) {
            if ( isset( $by_id[ $id ] ) ) {
                $picked[] = $by_id[ $id ];
            }
        }
        return $picked;
    }

    private static function image_url( $sizes ) {
        foreach ( array( 600, 960, 1200, 300 ) as $width ) {
            if ( ! empty( $sizes[ $width ] ) ) {
                return $sizes[ $width ];
            }
        }
        $sizes = array_filter( (array) $sizes, 'is_string' );
        return $sizes ? reset( $sizes ) : '';
    }

    /**
     * The item's page on Mirlo, like Mirlo's own getMerchUrl().
     */
    private static function merch_url( $merch, $artist_slug ) {
        $artist = $merch['artist']['urlSlug'] ?? $artist_slug;
        $item   = ! empty( $merch['urlSlug'] ) ? $merch['urlSlug'] : ( $merch['id'] ?? '' );
        return Mirlo_OAuth::instance_url() . '/' . rawurlencode( strtolower( (string) $artist ) ) . '/merch/' . rawurlencode( (string) $item );
    }

    /**
     * A price that reads well without JavaScript. The script reformats it
     * for the visitor's locale.
     */
    private static function price( $cents, $currency, $suffix = '' ) {
        $symbols = array(
            'USD' => '$',
            'CAD' => 'CA$',
            'AUD' => 'A$',
            'NZD' => 'NZ$',
            'EUR' => '€',
            'GBP' => '£',
        );
        $text    = ( $symbols[ $currency ] ?? $currency . ' ' ) . number_format( $cents / 100, 2 ) . $suffix;
        return sprintf(
            '<p class="mirlo-catalog__price" data-amount="%s" data-currency="%s" data-suffix="%s">%s</p>',
            esc_attr( (string) $cents ),
            esc_attr( $currency ),
            esc_attr( $suffix ),
            esc_html( $text )
        );
    }
}
