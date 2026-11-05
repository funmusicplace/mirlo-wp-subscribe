<?php

class Mirlo_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_mirlo_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_mirlo_preview_subscribers', array( $this, 'ajax_preview_subscribers' ) );
        add_action( 'admin_notices', array( $this, 'render_lost_connection_notice' ) );
        add_action( 'admin_post_mirlo_oauth_dismiss_notice', array( $this, 'handle_dismiss_notice' ) );
    }

    /**
     * When Mirlo ends the connection, say so on every admin page, not just
     * this plugin's settings, until it's reconnected or dismissed.
     */
    public function render_lost_connection_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( $screen && 'settings_page_mirlo-subscribe' === $screen->id ) {
            return; // The settings page shows it in place.
        }
        $lost = get_option( Mirlo_OAuth::NOTICE_OPTION );
        if ( ! $lost || Mirlo_OAuth::is_connected() ) {
            return;
        }
        $dismiss = wp_nonce_url(
            admin_url( 'admin-post.php?action=mirlo_oauth_dismiss_notice' ),
            'mirlo_oauth_dismiss_notice'
        );
        printf(
            '<div class="notice notice-warning"><p><strong>Mirlo Subscribe:</strong> %s</p><p><a class="button button-primary" href="%s">Reconnect to Mirlo</a> <a class="button" href="%s">Dismiss</a></p></div>',
            esc_html( $lost ),
            esc_url( admin_url( 'options-general.php?page=mirlo-subscribe' ) ),
            esc_url( $dismiss )
        );
    }

    public function handle_dismiss_notice() {
        check_admin_referer( 'mirlo_oauth_dismiss_notice' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        delete_option( Mirlo_OAuth::NOTICE_OPTION );
        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
        exit;
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
            'mirlo_instance_url',
            array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_instance_url' ),
                'default'           => '',
            )
        );

        register_setting(
            'mirlo_subscribe_settings',
            'mirlo_oauth_client_id',
            array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_client_id' ),
                'default'           => '',
            )
        );

        // Settings save in the order they're registered, so this runs before
        // the client ID is sanitized.
        add_filter( 'pre_update_option_mirlo_instance_url', array( $this, 'note_instance_change' ), 10, 2 );

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
            'mirlo_oauth_section',
            'Mirlo Account',
            array( $this, 'render_oauth_section_description' ),
            'mirlo-subscribe'
        );

        add_settings_section(
            'mirlo_appearance_section',
            'Button Appearance',
            null,
            'mirlo-subscribe'
        );

        add_settings_field(
            'mirlo_instance_url',
            'Mirlo Instance',
            array( $this, 'render_instance_url_field' ),
            'mirlo-subscribe',
            'mirlo_api_section'
        );

        add_settings_field(
            'mirlo_api_key',
            'API Key',
            array( $this, 'render_api_key_field' ),
            'mirlo-subscribe',
            'mirlo_api_section'
        );

        add_settings_field(
            'mirlo_oauth_client_id',
            'Client ID',
            array( $this, 'render_oauth_client_id_field' ),
            'mirlo-subscribe',
            'mirlo_oauth_section'
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

    public function ajax_preview_subscribers() {
        check_ajax_referer( 'mirlo_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $artist = sanitize_text_field( wp_unslash( $_POST['artist'] ?? '' ) );
        if ( empty( $artist ) ) {
            wp_send_json_error( 'Please enter an artist slug.' );
        }

        $api    = new Mirlo_API();
        $result = $api->get_subscribers( $artist );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }

        wp_send_json_success( $result['results'] ?? array() );
    }

    public function sanitize_instance_url( $value ) {
        $value = untrailingslashit( esc_url_raw( trim( (string) $value ), array( 'https', 'http' ) ) );
        return Mirlo_OAuth::DEFAULT_INSTANCE === $value ? '' : $value;
    }

    /** @var bool Whether the settings being saved change the Mirlo instance. */
    private $instance_changed = false;

    public function note_instance_change( $value, $old_value ) {
        if ( untrailingslashit( (string) $value ) !== untrailingslashit( (string) $old_value ) ) {
            $this->instance_changed = true;
        }
        return $value;
    }

    /**
     * A registered client ID only means something on the instance that issued
     * it, so drop it when the instance changes, unless a new one was entered
     * at the same time.
     */
    public function sanitize_client_id( $value ) {
        $value = trim( sanitize_text_field( (string) $value ) );
        $old   = trim( (string) get_option( 'mirlo_oauth_client_id', '' ) );
        if ( $this->instance_changed && '' !== $value && $value === $old ) {
            add_settings_error(
                'mirlo_oauth_client_id',
                'mirlo_client_id_cleared',
                'The Client ID was cleared because it belongs to the previous Mirlo instance. Register this site on the new instance and enter the ID it gives you, or leave Client ID blank if this site is on HTTPS.',
                'warning'
            );
            return '';
        }
        return $value;
    }

    public function render_api_section_description() {
        echo '<p>Where your artist is on Mirlo, and how this site identifies itself when someone checks out.</p>';
    }

    public function render_instance_url_field() {
        $value = get_option( 'mirlo_instance_url', '' );
        printf(
            '<input type="url" id="mirlo_instance_url" name="mirlo_instance_url" value="%s" class="regular-text" placeholder="%s">
             <p class="description">Leave blank to use %s. Set this if your artist is on another Mirlo instance.</p>',
            esc_attr( $value ),
            esc_attr( Mirlo_OAuth::DEFAULT_INSTANCE ),
            esc_html( Mirlo_OAuth::DEFAULT_INSTANCE )
        );
    }

    public function render_oauth_section_description() {
        echo '<p>Connect a Mirlo account that manages your artist to let this site read your subscribers. '
            . 'Save any changes here before connecting.</p>';
    }

    public function render_oauth_client_id_field() {
        $value = get_option( 'mirlo_oauth_client_id', '' );
        printf(
            '<input type="text" id="mirlo_oauth_client_id" name="mirlo_oauth_client_id" value="%s" class="regular-text">',
            esc_attr( $value )
        );
        if ( Mirlo_OAuth::can_use_metadata_document() ) {
            printf(
                '<p class="description">Leave blank and this site identifies itself to Mirlo with <a href="%1$s" target="_blank" rel="noopener">its own metadata document</a>. '
                . 'The first time it connects to an instance, that instance\'s admins need to approve it.<br>'
                . 'Or ask the instance\'s admins to register this site under Admin → API clients with the redirect URI <code>%2$s</code> and "Server-side app" left unticked, and enter the numeric client ID they give you.</p>',
                esc_url( Mirlo_OAuth::metadata_document_url() ),
                esc_html( Mirlo_OAuth::redirect_uri() )
            );
        } else {
            printf(
                '<p class="description">Ask the instance\'s admins to register this site under Admin → API clients with the redirect URI <code>%s</code> and "Server-side app" left unticked, and enter the numeric client ID they give you. '
                . '(Sites served over HTTPS with pretty permalinks can skip this step.)</p>',
                esc_html( Mirlo_OAuth::redirect_uri() )
            );
        }
    }

    public function render_api_key_field() {
        $value = get_option( 'mirlo_api_key', '' );
        printf(
            '<input type="password" id="mirlo_api_key" name="mirlo_api_key" value="%s" class="regular-text" autocomplete="off">
             <p class="description">Optional. Only needed if a shortcode uses <code>success_url</code> to bring buyers back to this site after they pay: '
            . 'without a key, Mirlo only lets buyers return to Mirlo itself. Ask <a href="mailto:hi@mirlo.space">hi@mirlo.space</a> for one.</p>',
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

            <?php $this->render_oauth_notices(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'mirlo_subscribe_settings' );
                do_settings_sections( 'mirlo-subscribe' );
                submit_button( 'Save Settings' );
                ?>
            </form>

            <hr>

            <?php $this->render_connection(); ?>

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

    private function render_oauth_notices() {
        $key    = 'mirlo_oauth_result_' . get_current_user_id();
        $result = get_transient( $key );
        if ( is_array( $result ) ) {
            delete_transient( $key );
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                'success' === $result['type'] ? 'success' : 'error',
                esc_html( $result['message'] )
            );
        }

        $lost = get_option( Mirlo_OAuth::NOTICE_OPTION );
        if ( $lost && ! Mirlo_OAuth::is_connected() ) {
            printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $lost ) );
        }
    }

    private function render_connection() {
        $account = Mirlo_OAuth::get_account();
        ?>
        <h2>Mirlo Connection</h2>
        <?php if ( $account ) : ?>
            <p>
                <?php
                printf(
                    'Connected to %s as <strong>%s</strong>.',
                    esc_html( Mirlo_OAuth::instance_url() ),
                    esc_html( $account['name'] ? $account['name'] : 'user ' . $account['sub'] )
                );
                ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="mirlo_oauth_disconnect">
                <?php wp_nonce_field( 'mirlo_oauth_disconnect' ); ?>
                <?php submit_button( 'Disconnect', 'secondary', 'submit', false ); ?>
            </form>

            <h3>Preview Subscribers</h3>
            <p>Enter the URL slug of an artist the connected account manages.</p>
            <div class="mirlo-test-row">
                <input type="text" id="mirlo-subscribers-artist" placeholder="e.g. my-artist-name" class="regular-text">
                <button type="button" id="mirlo-subscribers-btn" class="button button-secondary">Preview</button>
            </div>
            <div id="mirlo-subscribers-result" style="margin-top:10px;"></div>
        <?php elseif ( '' === Mirlo_OAuth::client_id() ) : ?>
            <p>Enter a client ID above to connect.</p>
        <?php else : ?>
            <p>
                <?php
                printf(
                    'Not connected. You\'ll sign in to %s and choose whether to let this site read your subscribers.',
                    esc_html( Mirlo_OAuth::instance_url() )
                );
                ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="mirlo_oauth_connect">
                <?php wp_nonce_field( 'mirlo_oauth_connect' ); ?>
                <?php submit_button( 'Connect to Mirlo', 'primary', 'submit', false ); ?>
            </form>
        <?php endif; ?>
        <?php
    }
}
