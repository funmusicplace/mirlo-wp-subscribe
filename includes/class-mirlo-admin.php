<?php

class Mirlo_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_mirlo_test_connection', array( $this, 'ajax_test_connection' ) );
    }

    public function add_menu_page() {
        add_options_page(
            'Mirlo Subscribe Settings',
            'Mirlo Subscribe',
            'manage_options',
            'mirlo-subscribe',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'mirlo_subscribe_settings',
            'mirlo_api_key',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            )
        );

        register_setting(
            'mirlo_subscribe_settings',
            'mirlo_button_color',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_hex_color',
                'default'           => '',
            )
        );

        register_setting(
            'mirlo_subscribe_settings',
            'mirlo_button_text',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => 'Subscribe',
            )
        );

        add_settings_section(
            'mirlo_api_section',
            'API Configuration',
            array( $this, 'render_api_section_description' ),
            'mirlo-subscribe'
        );

        add_settings_section(
            'mirlo_appearance_section',
            'Button Appearance',
            null,
            'mirlo-subscribe'
        );

        add_settings_field(
            'mirlo_api_key',
            'API Key',
            array( $this, 'render_api_key_field' ),
            'mirlo-subscribe',
            'mirlo_api_section'
        );

        add_settings_field(
            'mirlo_button_color',
            'Button Color',
            array( $this, 'render_button_color_field' ),
            'mirlo-subscribe',
            'mirlo_appearance_section'
        );

        add_settings_field(
            'mirlo_button_text',
            'Default Button Text',
            array( $this, 'render_button_text_field' ),
            'mirlo-subscribe',
            'mirlo_appearance_section'
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( 'settings_page_mirlo-subscribe' !== $hook ) {
            return;
        }

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script(
            'mirlo-admin',
            MIRLO_SUBSCRIBE_URL . 'assets/js/mirlo-admin.js',
            array( 'jquery', 'wp-color-picker' ),
            MIRLO_SUBSCRIBE_VERSION,
            true
        );
        wp_localize_script(
            'mirlo-admin',
            'mirloAdmin',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'mirlo_admin_nonce' ),
            )
        );
    }

    public function ajax_test_connection() {
        check_ajax_referer( 'mirlo_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $slug = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
        if ( empty( $slug ) ) {
            wp_send_json_error( 'Please enter a test artist slug.' );
        }

        $api    = new Mirlo_API();
        $result = $api->get_artist( $slug );

        wp_send_json_success( $result );
    }

    public function render_api_section_description() {
        echo '<p>Enter your Mirlo API key. Contact <a href="mailto:hi@mirlo.space">hi@mirlo.space</a> to request one. '
            . 'The API key is optional for public artist data but may be required for private tiers.</p>';
    }

    public function render_api_key_field() {
        $value = get_option( 'mirlo_api_key', '' );
        printf(
            '<input type="password" id="mirlo_api_key" name="mirlo_api_key" value="%s" class="regular-text" autocomplete="off">
             <p class="description">Stored securely. Leave blank if not required.</p>',
            esc_attr( $value )
        );
    }

    public function render_button_color_field() {
        $value = get_option( 'mirlo_button_color', '' );
        printf(
            '<input type="text" id="mirlo_button_color" name="mirlo_button_color" value="%s" class="mirlo-color-picker">
             <p class="description">Leave blank to inherit the theme color.</p>',
            esc_attr( $value )
        );
    }

    public function render_button_text_field() {
        $value = get_option( 'mirlo_button_text', 'Subscribe' );
        printf(
            '<input type="text" id="mirlo_button_text" name="mirlo_button_text" value="%s" class="regular-text">
             <p class="description">Used when <code>button_text</code> is not set in the shortcode.</p>',
            esc_attr( $value )
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'mirlo_subscribe_settings' );
                do_settings_sections( 'mirlo-subscribe' );
                submit_button( 'Save Settings' );
                ?>
            </form>

            <hr>

            <h2>Test Connection</h2>
            <p>Enter an artist URL slug to verify the API is working correctly.</p>
            <div class="mirlo-test-row">
                <input type="text" id="mirlo-test-slug" placeholder="e.g. my-artist-name" class="regular-text">
                <button type="button" id="mirlo-test-btn" class="button button-secondary">Test</button>
            </div>
            <div id="mirlo-test-result" style="margin-top:10px;"></div>

            <hr>

            <h2>Shortcode Reference</h2>
            <table class="widefat striped" style="max-width:700px;">
                <thead>
                    <tr>
                        <th>Attribute</th>
                        <th>Required</th>
                        <th>Default</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>artist</code></td>
                        <td>Yes</td>
                        <td>—</td>
                        <td>The artist's URL slug on Mirlo.</td>
                    </tr>
                    <tr>
                        <td><code>button_text</code></td>
                        <td>No</td>
                        <td><?php echo esc_html( get_option( 'mirlo_button_text', 'Subscribe' ) ); ?></td>
                        <td>Label shown on the button.</td>
                    </tr>
                </tbody>
            </table>
            <p><strong>Example:</strong> <code>[mirlo_subscribe artist="my-artist-name" button_text="Support me"]</code></p>
        </div>
        <?php
    }
}
