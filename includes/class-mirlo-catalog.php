<?php

/**
 * The [mirlo_catalog] shortcode: an artist's merch and subscription tiers as
 * cards, each with a button that starts Mirlo's hosted checkout.
 *
 * Merch that needs a choice Mirlo's checkout page can't make (a required
 * variant such as a size, or a shipping region out of several) links to the
 * item's page on Mirlo instead, which has the full form.
 */
class Mirlo_Catalog {
    const SECTIONS = array( 'merch', 'tiers' );

    public function __construct() {
        add_shortcode( 'mirlo_catalog', array( $this, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
        add_action( 'wp_ajax_mirlo_purchase_merch', array( $this, 'ajax_purchase_merch' ) );
        add_action( 'wp_ajax_nopriv_mirlo_purchase_merch', array( $this, 'ajax_purchase_merch' ) );
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

        $html = '';
        foreach ( $sections as $section ) {
            $html .= 'merch' === $section
                ? $this->render_merch_section( $atts )
                : $this->render_tiers_section( $atts );
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

    private function render_merch_section( $atts ) {
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
            $cards .= $this->merch_card( $merch, $atts['artist'] );
        }
        return $this->section( 'merch', $atts['merch_title'], '<ul class="mirlo-catalog__items">' . $cards . '</ul>' );
    }

    private function merch_card( $merch, $artist_slug ) {
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
            . '<div class="mirlo-catalog__actions">%s<p class="mirlo-catalog__error" role="alert" hidden></p></div></li>',
            esc_attr( $classes ),
            $image,
            esc_html( $title ),
            self::price( (int) ( $merch['minPrice'] ?? 0 ), $currency ),
            $stock,
            $button
        );
    }

    private function render_tiers_section( $atts ) {
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

            $cards .= sprintf(
                '<li class="mirlo-catalog__item mirlo-catalog__item--tier"><div class="mirlo-catalog__body"><h3 class="mirlo-catalog__title">%s</h3>%s%s</div>'
                . '<div class="mirlo-catalog__actions"><button type="button" class="mirlo-catalog__buy" data-mirlo-buy="tier" data-artist-id="%s" data-tier-id="%s">Subscribe</button>'
                . '<p class="mirlo-catalog__error" role="alert" hidden></p></div></li>',
                esc_html( $tier['name'] ?? '' ),
                $price,
                $description,
                esc_attr( (string) $artist['id'] ),
                esc_attr( (string) $tier['id'] )
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

        // Work out the shipping region here rather than trusting the page.
        $api   = new Mirlo_API();
        $data  = $api->get_artist_merch( $artist );
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

    public function register_assets() {
        wp_register_style( 'mirlo-catalog', MIRLO_SUBSCRIBE_URL . 'assets/css/mirlo-catalog.css', array( 'mirlo-modal' ), MIRLO_SUBSCRIBE_VERSION );
        wp_register_script( 'mirlo-catalog', MIRLO_SUBSCRIBE_URL . 'assets/js/mirlo-catalog.js', array(), MIRLO_SUBSCRIBE_VERSION, true );
        wp_localize_script(
            'mirlo-catalog',
            'mirloCatalog',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'mirlo_nonce_v2' ),
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
