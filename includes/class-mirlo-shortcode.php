<?php

/**
 * The [mirlo_pop_up] and [mirlo_tier_list] shortcodes. Checkout goes through
 * Mirlo's /v1/purchase endpoint.
 */
class Mirlo_Shortcode {
    public function __construct() {
        add_shortcode( 'mirlo_pop_up', array( $this, 'render_shortcode' ) );
        add_shortcode( 'mirlo_tier_list', array( $this, 'render_tiers_shortcode' ) );
        // The original shortcodes' names, so pages that still use them keep
        // working. Their attributes are a subset of the new ones.
        add_shortcode( 'mirlo_subscribe', array( $this, 'render_shortcode' ) );
        add_shortcode( 'mirlo_tiers', array( $this, 'render_tiers_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_footer', array( $this, 'render_modal_template' ) );
        add_action( 'wp_ajax_fetch_mirlo_artist', array( $this, 'ajax_fetch_artist' ) );
        add_action( 'wp_ajax_nopriv_fetch_mirlo_artist', array( $this, 'ajax_fetch_artist' ) );
        add_action( 'wp_ajax_mirlo_purchase_v2', array( $this, 'ajax_purchase' ) );
        add_action( 'wp_ajax_nopriv_mirlo_purchase_v2', array( $this, 'ajax_purchase' ) );
    }

    /**
     * Usage: [mirlo_pop_up artist="slugname" button_text="Subscribe" full_width="true" tier_ids="12,34" success_url="https://example.com/thanks"]
     * tier_ids restricts the popup to only those subscription tiers, in that order.
     * success_url is where Mirlo's hosted checkout sends the buyer after payment;
     * it must share an origin with this site's registered Mirlo application URL.
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'artist'      => '',
                'button_text' => 'Subscribe',
                'full_width'  => 'false',
                'color'       => '',
                'tier_ids'    => '',
                'success_url' => '',
            ),
            $atts
        );

        if ( empty( $atts['artist'] ) ) {
            return '<p class="mirlo-error">Error: <code>artist</code> slug not specified.</p>';
        }

        $classes = 'mirlo-subscribe-btn-v2';
        if ( filter_var( $atts['full_width'], FILTER_VALIDATE_BOOLEAN ) ) {
            $classes .= ' mirlo-subscribe-btn--full-width';
        }

        $color       = sanitize_hex_color( $atts['color'] );
        $color_attr  = $color ? ' data-btn-color="' . esc_attr( $color ) . '"' : '';
        $color_style = $color ? ' style="--mirlo-accent:' . esc_attr( $color ) . '"' : '';

        $tier_ids      = array_filter( array_map( 'intval', explode( ',', $atts['tier_ids'] ) ) );
        $tier_ids_attr = ! empty( $tier_ids )
            ? ' data-tier-ids="' . esc_attr( implode( ',', $tier_ids ) ) . '"'
            : '';

        $success_url      = Mirlo_API::return_url( $atts['success_url'] );
        $success_url_attr = $success_url ? ' data-success-url="' . esc_attr( $success_url ) . '"' : '';

        return sprintf(
            '<button class="%s" data-artist-slug="%s"%s%s%s%s>%s</button>',
            esc_attr( $classes ),
            esc_attr( $atts['artist'] ),
            $color_attr,
            $tier_ids_attr,
            $success_url_attr,
            $color_style,
            esc_html( $atts['button_text'] )
        );
    }

    /**
     * Usage: [mirlo_tier_list artist="slugname"]
     * Renders subscription tiers inline on the page without a modal.
     * tier_ids restricts display to only those subscription tiers, in that order.
     */
    public function render_tiers_shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'artist'   => '',
                'color'    => '',
                'tier_ids' => '',
            ),
            $atts
        );

        if ( empty( $atts['artist'] ) ) {
            return '<p class="mirlo-error">Error: <code>artist</code> slug not specified.</p>';
        }

        $api         = new Mirlo_API();
        $artist_data = $api->get_artist( $atts['artist'] );

        if ( isset( $artist_data['error'] ) ) {
            return '<p class="mirlo-error">' . esc_html( self::load_error( $artist_data['error'] ) ) . '</p>';
        }

        if ( empty( $artist_data['result']['id'] ) ) {
            return '<p class="mirlo-error">Artist not found.</p>';
        }

        $artist          = $artist_data['result'];
        $tiers           = $artist['subscriptionTiers'] ?? array();
        $artist_currency = strtoupper( $artist['user']['currency'] ?? 'USD' );

        $tier_ids = array_filter( array_map( 'intval', explode( ',', $atts['tier_ids'] ) ) );
        if ( ! empty( $tier_ids ) ) {
            $tiers_by_id = array();
            foreach ( $tiers as $tier ) {
                $tiers_by_id[ (int) $tier['id'] ] = $tier;
            }
            $tiers = array_values(
                array_filter(
                    array_map(
                        function ( $id ) use ( $tiers_by_id ) {
                            return $tiers_by_id[ $id ] ?? null;
                        },
                        $tier_ids
                    )
                )
            );
        }

        if ( empty( $tiers ) ) {
            return '<p class="mirlo-no-tiers">No subscription tiers available.</p>';
        }

        $color       = sanitize_hex_color( $atts['color'] );
        $color_style = $color ? ' style="--mirlo-accent:' . esc_attr( $color ) . '"' : '';

        $html = '<div class="mirlo-tiers-inline"' . $color_style . '>';
        foreach ( $tiers as $tier ) {
            $currency    = strtoupper( $tier['currency'] ?? $artist_currency );
            $amount      = isset( $tier['minAmount'] ) && null !== $tier['minAmount']
                ? $tier['minAmount'] / 100
                : null;
            $description = ! empty( $tier['description'] )
                ? '<div class="mirlo-tier-description">' . esc_html( $tier['description'] ) . '</div>'
                : '';

            $price_attrs = null !== $amount
                ? ' data-amount="' . esc_attr( $amount ) . '" data-currency="' . esc_attr( $currency ) . '"'
                : '';

            $html .= sprintf(
                '<button class="mirlo-tier-v2" data-artist-id="%s" data-tier-id="%s">'
                . '<div class="mirlo-tier-name">%s</div>'
                . '<div class="mirlo-tier-price"%s>%s</div>'
                . '%s'
                . '</button>',
                esc_attr( (string) $artist['id'] ),
                esc_attr( (string) $tier['id'] ),
                esc_html( $tier['name'] ),
                $price_attrs,
                null === $amount ? 'Free' : '',
                $description
            );
        }
        $html .= '</div>';

        return $html;
    }

    public function enqueue_assets() {
        wp_enqueue_style(
            'mirlo-modal',
            MIRLO_SUBSCRIBE_URL . 'assets/css/mirlo-modal.css',
            array(),
            MIRLO_SUBSCRIBE_VERSION
        );

        wp_add_inline_style( 'mirlo-modal', $this->get_theme_color_css() );

        wp_enqueue_script(
            'mirlo-modal-v2',
            MIRLO_SUBSCRIBE_URL . 'assets/js/mirlo-modal-v2.js',
            array( 'jquery' ),
            MIRLO_SUBSCRIBE_VERSION,
            true
        );

        wp_localize_script(
            'mirlo-modal-v2',
            'mirloAjax',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'mirlo_nonce' ),
            )
        );

        wp_localize_script(
            'mirlo-modal-v2',
            'mirloAjaxV2',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'mirlo_nonce_v2' ),
            )
        );
    }

    private function get_theme_color_css() {
        $accent     = null;
        $foreground = null;
        $background = null;

        // WP 5.9+ block themes: wp_get_global_styles() returns values as either
        // a hex color or a preset reference like "var:preset|color|primary".
        // resolve_color_value() converts preset references to CSS custom properties.
        if ( function_exists( 'wp_get_global_styles' ) ) {
            $global     = wp_get_global_styles();
            $foreground = $this->resolve_color_value( $global['color']['text'] ?? null );
            $background = $this->resolve_color_value( $global['color']['background'] ?? null );
            $accent     = $this->resolve_color_value( $global['elements']['button']['color']['background'] ?? null );
        }

        // Classic themes that register a color palette via add_theme_support.
        // These always give us raw hex values.
        if ( ! $accent ) {
            $palette = get_theme_support( 'editor-color-palette' );
            if ( ! empty( $palette[0] ) ) {
                foreach ( $palette[0] as $color ) {
                    $slug = $color['slug'] ?? '';
                    if ( in_array( $slug, array( 'primary', 'accent', 'foreground' ), true ) ) {
                        $accent = sanitize_hex_color( $color['color'] );
                        break;
                    }
                }
                if ( ! $accent && ! empty( $palette[0][0]['color'] ) ) {
                    $accent = sanitize_hex_color( $palette[0][0]['color'] );
                }
            }
        }

        // Settings color overrides the theme color.
        $settings_color = sanitize_hex_color( get_option( 'mirlo_button_color', '' ) );
        if ( $settings_color ) {
            $accent = $settings_color;
        }

        $vars = array();
        if ( $accent ) {
            $vars[] = '--mirlo-accent: ' . $accent . ';'; }
        if ( $foreground ) {
            $vars[] = '--mirlo-foreground: ' . $foreground . ';'; }
        if ( $background ) {
            $vars[] = '--mirlo-background: ' . $background . ';'; }

        if ( empty( $vars ) ) {
            return '';
        }

        return ':root { ' . implode( ' ', $vars ) . ' }';
    }

    // Converts "var:preset|color|primary" → "var(--wp--preset--color--primary)".
    // Returns sanitized hex colors unchanged, and null for anything unrecognisable.
    private function resolve_color_value( $value ) {
        if ( ! $value ) {
            return null;
        }
        if ( preg_match( '/^var:preset\|([a-z0-9-]+)\|([a-z0-9-]+)$/i', $value, $m ) ) {
            $feature = sanitize_key( $m[1] );
            $slug    = sanitize_key( $m[2] );
            return 'var(--wp--preset--' . $feature . '--' . $slug . ')';
        }
        $hex = sanitize_hex_color( $value );
        return $hex ? $hex : null;
    }

    /**
     * What to show when the tiers can't be loaded. Mirlo's own error is only
     * shown to people who run the site.
     */
    private static function load_error( $error ) {
        return current_user_can( 'manage_options' )
            ? 'Couldn\'t load the tiers from Mirlo: ' . $error
            : 'Couldn\'t load the subscription tiers. Please try again later.';
    }

    public function ajax_fetch_artist() {
        check_ajax_referer( 'mirlo_nonce', 'nonce' );

        $artist_slug = isset( $_POST['artist_slug'] )
            ? sanitize_text_field( wp_unslash( $_POST['artist_slug'] ) )
            : '';

        if ( empty( $artist_slug ) ) {
            wp_send_json_error( 'No artist slug provided.' );
        }

        $api         = new Mirlo_API();
        $artist_data = $api->get_artist( $artist_slug );

        if ( isset( $artist_data['error'] ) ) {
            wp_send_json_error( self::load_error( $artist_data['error'] ) );
        }

        if ( empty( $artist_data ) || ! isset( $artist_data['result']['id'] ) ) {
            wp_send_json_error( 'Artist not found.' );
        }

        $artist = $artist_data['result'];
        $tiers  = $artist['subscriptionTiers'] ?? array();

        wp_send_json_success(
            array(
                'artist' => $artist,
                'tiers'  => $tiers,
            )
        );
    }

    public function ajax_purchase() {
        check_ajax_referer( 'mirlo_nonce_v2', 'nonce' );

        $artist_id = isset( $_POST['artist_id'] ) ? intval( $_POST['artist_id'] ) : 0;
        $tier_id   = isset( $_POST['tier_id'] ) ? intval( $_POST['tier_id'] ) : 0;
        // Sent by the browser, so check it again: only this site or Mirlo.
        // return_url() cleans it with esc_url_raw().
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $success_url = isset( $_POST['success_url'] ) ? Mirlo_API::return_url( wp_unslash( $_POST['success_url'] ) ) : '';

        if ( ! $artist_id || ! $tier_id ) {
            wp_send_json_error( 'Missing artist or tier.' );
        }

        $api    = new Mirlo_API();
        $result = $api->purchase_subscription( $artist_id, $tier_id, $success_url );

        if ( isset( $result['error'] ) || empty( $result['redirectUrl'] ) ) {
            wp_send_json_error(
                array(
                    'message' => 'Couldn\'t start the checkout. Please try again.',
                    // Mirlo's own error, only for people who run the site.
                    'detail'  => current_user_can( 'manage_options' ) ? ( $result['error'] ?? 'No redirectUrl' ) : null,
                )
            );
        }

        wp_send_json_success( array( 'redirectUrl' => $result['redirectUrl'] ) );
    }

    public function render_modal_template() {
        ?>
        <div id="mirlo-modal-v2" class="mirlo-modal hidden" role="dialog" aria-modal="true" aria-labelledby="mirlo-artist-name-v2">
            <div class="mirlo-modal-overlay"></div>
            <div class="mirlo-modal-content">
                <button class="mirlo-modal-close" aria-label="Close">&times;</button>
                <div class="mirlo-loading">Loading&hellip;</div>
                <div class="mirlo-modal-body hidden">
                    <div class="mirlo-modal-artist">
                        <img class="mirlo-artist-avatar" src="" alt="">
                        <h2 class="mirlo-artist-name" id="mirlo-artist-name-v2"></h2>
                    </div>
                    <div class="mirlo-tiers-list"></div>
                </div>
            </div>
        </div>
        <?php
    }
}
