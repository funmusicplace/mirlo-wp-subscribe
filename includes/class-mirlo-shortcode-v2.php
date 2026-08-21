<?php

/**
 * Duplicate of Mirlo_Shortcode that checks out through the new unified
 * /v1/purchase endpoint instead of /artists/{id}/subscribe. Kept as a
 * separate shortcode/class so the new flow can be tested on a page
 * without touching the existing [mirlo_subscribe] / [mirlo_tiers] shortcodes.
 */
class Mirlo_Shortcode_V2 {
    public function __construct() {
        add_shortcode( 'mirlo_pop_up', array( $this, 'render_shortcode' ) );
        add_shortcode( 'mirlo_tier_list', array( $this, 'render_tiers_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_footer', array( $this, 'render_modal_template' ) );
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

        $success_url      = ! empty( $atts['success_url'] ) ? esc_url_raw( $atts['success_url'] ) : '';
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
            return '<p class="mirlo-error">Error: ' . esc_html( $artist_data['error'] ) . '</p>';
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
            $tiers = array_values( array_filter( array_map(
                function ( $id ) use ( $tiers_by_id ) {
                    return $tiers_by_id[ $id ] ?? null;
                },
                $tier_ids
            ) ) );
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
        // Relies on Mirlo_Shortcode having already enqueued the shared
        // 'mirlo-modal' stylesheet (visual rules are shared via the
        // -v2 selectors added alongside the originals in mirlo-modal.css).
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

    public function ajax_purchase() {
        check_ajax_referer( 'mirlo_nonce_v2', 'nonce' );

        $artist_id   = isset( $_POST['artist_id'] )   ? intval( $_POST['artist_id'] )   : 0;
        $tier_id     = isset( $_POST['tier_id'] )     ? intval( $_POST['tier_id'] )     : 0;
        $success_url = isset( $_POST['success_url'] ) ? esc_url_raw( wp_unslash( $_POST['success_url'] ) ) : '';

        if ( ! $artist_id || ! $tier_id ) {
            wp_send_json_error( 'Missing artist or tier.' );
        }

        $api    = new Mirlo_API();
        $result = $api->purchase_subscription( $artist_id, $tier_id, $success_url );

        if ( isset( $result['error'] ) || empty( $result['redirectUrl'] ) ) {
            wp_send_json_error( $result );
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
