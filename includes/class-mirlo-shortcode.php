<?php

class Mirlo_Shortcode {
    public function __construct() {
        add_shortcode( 'mirlo_subscribe', array( $this, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_footer', array( $this, 'render_modal_template' ) );
        add_action( 'wp_ajax_fetch_mirlo_artist', array( $this, 'ajax_fetch_artist' ) );
        add_action( 'wp_ajax_nopriv_fetch_mirlo_artist', array( $this, 'ajax_fetch_artist' ) );
        add_action( 'wp_ajax_mirlo_subscribe', array( $this, 'ajax_subscribe' ) );
        add_action( 'wp_ajax_nopriv_mirlo_subscribe', array( $this, 'ajax_subscribe' ) );
    }

    /**
     * Usage: [mirlo_subscribe artist="slugname" button_text="Subscribe"]
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'artist'      => '',
                'button_text' => 'Subscribe',
            ),
            $atts
        );

        if ( empty( $atts['artist'] ) ) {
            return '<p class="mirlo-error">Error: <code>artist</code> slug not specified.</p>';
        }

        return sprintf(
            '<button class="mirlo-subscribe-btn" data-artist-slug="%s">%s</button>',
            esc_attr( $atts['artist'] ),
            esc_html( $atts['button_text'] )
        );
    }

    public function enqueue_assets() {
        wp_enqueue_style(
            'mirlo-modal',
            MIRLO_SUBSCRIBE_URL . 'assets/css/mirlo-modal.css',
            array(),
            MIRLO_SUBSCRIBE_VERSION
        );

        wp_enqueue_script(
            'mirlo-modal',
            MIRLO_SUBSCRIBE_URL . 'assets/js/mirlo-modal.js',
            array( 'jquery' ),
            MIRLO_SUBSCRIBE_VERSION,
            true
        );

        wp_localize_script(
            'mirlo-modal',
            'mirloAjax',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'mirlo_nonce' ),
            )
        );
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
            wp_send_json_error( $artist_data['error'] );
        }

        if ( empty( $artist_data ) || ! isset( $artist_data['result']['id'] ) ) {
            wp_send_json_error( 'Artist not found.' );
        }

        $artist = $artist_data['result'];
        $tiers = $artist['subscriptionTiers'] ?? array();

        if ( isset( $tiers['error'] ) ) {
            wp_send_json_error( $tiers['error'] );
        }

        wp_send_json_success(
            array(
                'artist' => $artist,
                'tiers'  => $tiers,
            )
        );
    }

    public function ajax_subscribe() {
        check_ajax_referer( 'mirlo_nonce', 'nonce' );

        $artist_id = isset( $_POST['artist_id'] ) ? intval( $_POST['artist_id'] ) : 0;
        $tier_id   = isset( $_POST['tier_id'] )   ? intval( $_POST['tier_id'] )   : 0;

        if ( ! $artist_id || ! $tier_id ) {
            wp_send_json_error( 'Missing artist or tier.' );
        }

        $api    = new Mirlo_API();
        $result = $api->subscribe( $artist_id, $tier_id );

        if ( isset( $result['error'] ) || empty( $result['sessionUrl'] ) ) {
            wp_send_json_error( $result );
        }

        wp_send_json_success( array( 'sessionUrl' => $result['sessionUrl'] ) );
    }

    public function render_modal_template() {
        ?>
        <div id="mirlo-modal" class="mirlo-modal hidden" role="dialog" aria-modal="true" aria-labelledby="mirlo-artist-name">
            <div class="mirlo-modal-overlay"></div>
            <div class="mirlo-modal-content">
                <button class="mirlo-modal-close" aria-label="Close">&times;</button>
                <div class="mirlo-loading">Loading&hellip;</div>
                <div class="mirlo-modal-body hidden">
                    <div class="mirlo-modal-artist">
                        <img class="mirlo-artist-avatar" src="" alt="">
                        <h2 class="mirlo-artist-name" id="mirlo-artist-name"></h2>
                    </div>
                    <div class="mirlo-tiers-list"></div>
                </div>
            </div>
        </div>
        <?php
    }
}
