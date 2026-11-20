<?php

class Mirlo_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_mirlo_check_artist', array( $this, 'ajax_check_artist' ) );
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

        register_setting(
            'mirlo_subscribe_settings',
            'mirlo_reader_sales',
            array(
                'type'              => 'boolean',
                'sanitize_callback' => function ( $value ) {
                    return $value ? 1 : 0;
                },
                'default'           => 0,
            )
        );

        // Settings save in the order they're registered, so this runs before
        // the client ID is sanitized.
        add_filter( 'pre_update_option_mirlo_instance_url', array( $this, 'note_instance_change' ), 10, 2 );
        add_filter( 'pre_update_option_mirlo_reader_sales', array( $this, 'note_reader_sales_change' ), 10, 2 );
        add_filter( 'wp_redirect', array( $this, 'connect_after_save' ) );

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

        // Sections are rendered one by one in render_settings_page(), so
        // these only group the fields.
        add_settings_section( 'mirlo_artist_section', 'Your artist on Mirlo', null, 'mirlo-subscribe' );
        add_settings_section( 'mirlo_appearance_section', 'Appearance', null, 'mirlo-subscribe' );
        add_settings_section( 'mirlo_checkout_section', 'After checkout', null, 'mirlo-subscribe' );
        add_settings_section( 'mirlo_oauth_section', 'Mirlo account', null, 'mirlo-subscribe' );

        add_settings_field(
            'mirlo_instance_url',
            'Mirlo instance',
            array( $this, 'render_instance_url_field' ),
            'mirlo-subscribe',
            'mirlo_artist_section',
            array( 'label_for' => 'mirlo_instance_url' )
        );

        add_settings_field(
            'mirlo_check_artist',
            'Check an artist',
            array( $this, 'render_check_artist_field' ),
            'mirlo-subscribe',
            'mirlo_artist_section',
            array( 'label_for' => 'mirlo-check-slug' )
        );

        add_settings_field(
            'mirlo_button_color',
            'Button colour',
            array( $this, 'render_button_color_field' ),
            'mirlo-subscribe',
            'mirlo_appearance_section'
        );

        add_settings_field(
            'mirlo_api_key',
            'API key',
            array( $this, 'render_api_key_field' ),
            'mirlo-subscribe',
            'mirlo_checkout_section',
            array( 'label_for' => 'mirlo_api_key' )
        );

        add_settings_field(
            'mirlo_connection',
            'Connection',
            array( $this, 'render_connection_field' ),
            'mirlo-subscribe',
            'mirlo_oauth_section'
        );

        add_settings_field(
            'mirlo_oauth_client_id',
            'Client ID',
            array( $this, 'render_oauth_client_id_field' ),
            'mirlo-subscribe',
            'mirlo_oauth_section'
        );

        add_settings_field(
            'mirlo_reader_sales',
            'Card reader sales',
            array( $this, 'render_reader_sales_field' ),
            'mirlo-subscribe',
            'mirlo_oauth_section'
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( 'settings_page_mirlo-subscribe' !== $hook ) {
            return;
        }

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_style(
            'mirlo-admin',
            MIRLO_SUBSCRIBE_URL . 'assets/css/mirlo-admin.css',
            array(),
            MIRLO_SUBSCRIBE_VERSION
        );
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

    /**
     * Looks an artist up afresh (not from the 15-minute cache) and lists
     * their tiers and merch, with the IDs the shortcodes take.
     */
    public function ajax_check_artist() {
        check_ajax_referer( 'mirlo_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $slug = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
        if ( '' === $slug ) {
            wp_send_json_error( 'Enter an artist\'s URL slug.' );
        }

        $api    = new Mirlo_API();
        $result = $api->get_artist( $slug, true );
        if ( isset( $result['error'] ) ) {
            wp_send_json_error(
                404 === ( $result['error_status'] ?? 0 )
                    ? sprintf( 'There\'s no artist "%s" on %s.', $slug, Mirlo_OAuth::instance_url() )
                    : 'Couldn\'t reach Mirlo: ' . $result['error']
            );
        }
        $artist = $result['result'] ?? array();
        if ( empty( $artist['id'] ) ) {
            wp_send_json_error( sprintf( 'There\'s no artist "%s" on %s.', $slug, Mirlo_OAuth::instance_url() ) );
        }

        $tiers = array();
        foreach ( $artist['subscriptionTiers'] ?? array() as $tier ) {
            $tiers[] = array(
                'id'   => $tier['id'] ?? '',
                'name' => $tier['name'] ?? '',
            );
        }

        $merch       = null;
        $merch_found = $api->get_artist_merch( $slug, true );
        if ( ! isset( $merch_found['error'] ) && isset( $merch_found['results'] ) && is_array( $merch_found['results'] ) ) {
            $merch = array();
            foreach ( $merch_found['results'] as $item ) {
                $merch[] = array(
                    'id'    => $item['id'] ?? '',
                    'title' => $item['title'] ?? '',
                );
            }
        }

        wp_send_json_success(
            array(
                'name'     => $artist['name'] ?? $slug,
                'slug'     => $artist['urlSlug'] ?? $slug,
                'instance' => Mirlo_OAuth::instance_url(),
                'tiers'    => $tiers,
                'merch'    => $merch,
            )
        );
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

    /** @var bool Whether the settings being saved turn card reader sales on. */
    private $reader_sales_turned_on = false;

    public function note_instance_change( $value, $old_value ) {
        if ( untrailingslashit( (string) $value ) !== untrailingslashit( (string) $old_value ) ) {
            $this->instance_changed = true;
        }
        return $value;
    }

    public function note_reader_sales_change( $value, $old_value ) {
        if ( $value && ! $old_value ) {
            $this->reader_sales_turned_on = true;
        }
        return $value;
    }

    /**
     * After the settings save, go straight on to connecting when "Connect to
     * Mirlo" was the button pressed, or when card reader sales were just
     * turned on and the connection doesn't allow them yet. Not if saving
     * raised an error or warning, so it can be read first.
     */
    public function connect_after_save( $location ) {
        // options.php checked the nonce before saving.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ( 'mirlo_subscribe_settings' !== ( $_POST['option_page'] ?? '' ) || false === strpos( $location, 'settings-updated=true' ) ) {
            return $location;
        }
        $asked = ! empty( $_POST['mirlo_connect'] );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( ! $asked && ! ( $this->reader_sales_turned_on && ! Mirlo_OAuth::has_scope( 'pos' ) ) ) {
            return $location;
        }
        foreach ( get_settings_errors() as $error ) {
            if ( ! in_array( $error['type'], array( 'success', 'updated' ), true ) ) {
                return $location;
            }
        }
        return add_query_arg(
            array(
                'action'   => 'mirlo_oauth_connect',
                '_wpnonce' => wp_create_nonce( 'mirlo_oauth_connect' ),
            ),
            admin_url( 'admin-post.php' )
        );
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

    public function render_instance_url_field() {
        $value = get_option( 'mirlo_instance_url', '' );
        printf(
            '<input type="url" id="mirlo_instance_url" name="mirlo_instance_url" value="%s" class="regular-text" placeholder="%s">
             <p class="description">Leave blank for %s. Only set this if your artist is on another Mirlo instance.</p>',
            esc_attr( $value ),
            esc_attr( Mirlo_OAuth::DEFAULT_INSTANCE ),
            esc_html( Mirlo_OAuth::DEFAULT_INSTANCE )
        );
    }

    public function render_check_artist_field() {
        // No name, so it isn't saved with the settings.
        ?>
        <div class="mirlo-tool-row">
            <input type="text" id="mirlo-check-slug" placeholder="e.g. my-artist-name" class="regular-text">
            <button type="button" id="mirlo-check-btn" class="button button-secondary">Check</button>
        </div>
        <p class="description">The part after the instance in the artist's Mirlo address. Shows their tiers and merch, with the IDs the shortcodes take. Uses the saved instance.</p>
        <div id="mirlo-check-result" class="mirlo-tool-result" aria-live="polite"></div>
        <?php
    }

    public function render_button_color_field() {
        $value = get_option( 'mirlo_button_color', '' );
        printf(
            '<input type="text" id="mirlo_button_color" name="mirlo_button_color" value="%s" class="mirlo-color-picker">
             <p class="description">For the buttons of every shortcode. Leave blank to use the theme\'s colour. A shortcode\'s <code>color</code> overrides it.</p>',
            esc_attr( $value )
        );
    }

    public function render_api_key_field() {
        $value = get_option( 'mirlo_api_key', '' );
        printf(
            '<input type="password" id="mirlo_api_key" name="mirlo_api_key" value="%s" class="regular-text" autocomplete="off">
             <p class="description">%s</p>
             <p class="description">Lets a shortcode\'s <code>success_url</code> send buyers to a page on this site, such as a thank-you page, after they pay. '
            . 'Ask <a href="mailto:hi@mirlo.space">hi@mirlo.space</a> for a key.</p>',
            esc_attr( $value ),
            '' === $value
                ? '<strong>Off.</strong> Without a key, Mirlo only sends buyers back to Mirlo itself.'
                : '<strong>On.</strong> Buyers can come back to this site after paying.'
        );
    }

    /**
     * Connection status, with buttons to connect or disconnect. "Connect"
     * submits the settings form, so anything changed here is saved first.
     */
    public function render_connection_field() {
        $account = Mirlo_OAuth::get_account();
        if ( ! $account ) {
            printf(
                '<p>Not connected. You\'ll sign in to %s and approve this site.</p>',
                esc_html( Mirlo_OAuth::instance_url() )
            );
            echo '<p><button type="submit" name="mirlo_connect" value="1" class="button button-primary">Connect to Mirlo</button></p>';
            echo '<p class="description">Saves these settings first.</p>';
            return;
        }

        printf(
            '<p>Connected to %s as <strong>%s</strong>.</p>',
            esc_html( Mirlo_OAuth::instance_url() ),
            esc_html( $account['name'] ? $account['name'] : 'user ' . $account['sub'] )
        );
        if ( get_option( 'mirlo_reader_sales' ) && ! Mirlo_OAuth::has_scope( 'pos' ) ) {
            echo '<div class="notice notice-warning inline"><p>Card reader sales are on, but this connection doesn\'t allow them yet. '
                . 'Connect again to give the site the <code>pos</code> permission.</p>'
                . '<p><button type="submit" name="mirlo_connect" value="1" class="button button-primary">Connect again</button></p></div>';
        }
        printf(
            '<p><a class="button" href="%s">Disconnect</a></p>',
            esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mirlo_oauth_disconnect' ), 'mirlo_oauth_disconnect' ) )
        );
        ?>
        <h4>Preview subscribers</h4>
        <div class="mirlo-tool-row">
            <input type="text" id="mirlo-subscribers-artist" placeholder="e.g. my-artist-name" class="regular-text" aria-label="Artist URL slug">
            <button type="button" id="mirlo-subscribers-btn" class="button button-secondary">Preview</button>
        </div>
        <p class="description">The URL slug of an artist the connected account manages.</p>
        <div id="mirlo-subscribers-result" class="mirlo-tool-result" aria-live="polite"></div>
        <?php
    }

    public function render_oauth_client_id_field() {
        $value = get_option( 'mirlo_oauth_client_id', '' );
        $input = sprintf(
            '<input type="text" id="mirlo_oauth_client_id" name="mirlo_oauth_client_id" value="%s" class="regular-text">',
            esc_attr( $value )
        );
        $register = sprintf(
            'Ask the instance\'s admins to register this site under Admin → API clients with the redirect URI <code>%s</code> and "Server-side app" left unticked, and enter the numeric client ID they give you.',
            esc_html( Mirlo_OAuth::redirect_uri() )
        );

        if ( ! Mirlo_OAuth::can_use_metadata_document() ) {
            echo $input; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
            echo '<p class="description">' . $register . ' (Sites served over HTTPS with pretty permalinks can skip this step.)</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
            return;
        }

        // On HTTPS, leaving it blank is the easy way, so the field is tucked
        // away unless it's in use.
        printf(
            '<p>Not needed: this site identifies itself to Mirlo with <a href="%s" target="_blank" rel="noopener">its own metadata document</a>. '
            . 'The first time it connects to an instance, that instance\'s admins need to approve it.</p>',
            esc_url( Mirlo_OAuth::metadata_document_url() )
        );
        printf(
            '<details class="mirlo-client-id"%s><summary>Use a registered client ID instead</summary>%s<p class="description">%s Leave it blank to go back to the metadata document.</p></details>',
            '' !== trim( $value ) ? ' open' : '',
            $input, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
            $register // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
        );
    }

    public function render_reader_sales_field() {
        printf(
            '<label><input type="checkbox" id="mirlo_reader_sales" name="mirlo_reader_sales" value="1"%s> Let staff send sales to the artist\'s Stripe card readers</label>'
            . '<p class="description">Adds "Pay on reader" to <code>[mirlo_catalog reader="yes"]</code>, for people with the <code>%s</code> capability (administrators have it). '
            . 'Needs Mirlo\'s <code>pos</code> permission, so saving with this newly ticked takes you to Mirlo to approve it. The connected account needs to manage the artist.</p>',
            checked( (bool) get_option( 'mirlo_reader_sales' ), true, false ),
            esc_html( Mirlo_Catalog::READER_CAP )
        );
    }

    /** One settings section's fields, under a heading. */
    private function render_section( $id, $title, $intro = '' ) {
        printf( '<h2>%s</h2>', esc_html( $title ) );
        if ( $intro ) {
            echo '<p>' . wp_kses_post( $intro ) . '</p>';
        }
        echo '<table class="form-table" role="presentation">';
        do_settings_fields( 'mirlo-subscribe', $id );
        echo '</table>';
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // The account section is optional, so it starts closed unless it's
        // already in use or needs attention.
        $account_open = Mirlo_OAuth::is_connected()
            || get_option( 'mirlo_reader_sales' )
            || Mirlo_OAuth::uses_registered_client()
            || get_option( Mirlo_OAuth::NOTICE_OPTION );
        ?>
        <div class="wrap mirlo-settings">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php $this->render_oauth_notices(); ?>

            <form method="post" action="options.php">
                <?php
                // Pressing Enter in a field submits with the form's first
                // button, so make that Save rather than Connect.
                ?>
                <button type="submit" name="submit" class="mirlo-default-submit" tabindex="-1" aria-hidden="true">Save Settings</button>
                <?php
                settings_fields( 'mirlo_subscribe_settings' );
                $this->render_section( 'mirlo_artist_section', 'Your artist on Mirlo' );
                $this->render_section( 'mirlo_appearance_section', 'Appearance' );
                $this->render_section(
                    'mirlo_checkout_section',
                    'After checkout',
                    'Buyers pay on Mirlo. By default they stay there afterwards.'
                );
                ?>

                <details class="mirlo-account"<?php echo $account_open ? ' open' : ''; ?>>
                    <summary><h2>Mirlo account <span class="mirlo-optional">(optional)</span></h2></summary>
                    <p>Only needed to sell on card readers, or to read your subscribers so other plugins can build on them. Buttons, tier lists and the catalog work without it.</p>
                    <table class="form-table" role="presentation">
                        <?php do_settings_fields( 'mirlo-subscribe', 'mirlo_oauth_section' ); ?>
                    </table>
                </details>

                <?php submit_button( 'Save Settings' ); ?>
            </form>

            <hr>

            <?php $this->render_shortcode_reference(); ?>
        </div>
        <?php
    }

    private function render_shortcode_reference() {
        $shortcodes = array(
            array(
                'code'  => '[mirlo_pop_up artist="my-artist"]',
                'shows' => 'A button that opens the artist\'s subscription tiers in a pop-up.',
                'atts'  => array( 'button_text', 'full_width', 'color', 'tier_ids', 'success_url' ),
            ),
            array(
                'code'  => '[mirlo_tier_list artist="my-artist"]',
                'shows' => 'The subscription tiers, as a list of buttons on the page.',
                'atts'  => array( 'color', 'tier_ids' ),
            ),
            array(
                'code'  => '[mirlo_catalog artist="my-artist"]',
                'shows' => 'Merch and subscription tiers as cards, each with a buy button.',
                'atts'  => array( 'show', 'merch_ids', 'tier_ids', 'columns', 'color', 'merch_title', 'tiers_title', 'success_url', 'reader' ),
            ),
        );
        $attributes = array(
            'button_text' => 'The button\'s label. Default "Subscribe".',
            'full_width'  => '<code>true</code> makes the button as wide as its container.',
            'color'       => 'Button colour, as <code>#hex</code>. Overrides the colour above.',
            'tier_ids'    => 'Only these tiers, in that order, such as <code>2,3</code>. <strong>Check an artist</strong> above lists the IDs.',
            'merch_ids'   => 'Only these merch items, in that order.',
            'show'        => 'Which sections, in that order: <code>merch,tiers</code> (default), <code>merch</code> or <code>tiers</code>.',
            'columns'     => 'The most cards in a row (default 3). Fewer fit on narrow screens.',
            'merch_title' => 'Heading above the merch. Default "Merch". Empty for none.',
            'tiers_title' => 'Heading above the tiers. Default "Subscriptions". Empty for none.',
            'success_url' => 'Where buyers land after paying: a path on this site such as <code>/thanks</code>, or a full URL on this site or on Mirlo. This site needs an API key (see <strong>After checkout</strong>).',
            'reader'      => '<code>yes</code> adds "Pay on reader" for staff, once card reader sales are on.',
        );
        ?>
        <h2>Shortcodes</h2>
        <p>Every shortcode needs <code>artist</code>, the artist's URL slug on Mirlo.</p>
        <table class="widefat striped mirlo-shortcodes">
            <thead>
                <tr>
                    <th>Shortcode</th>
                    <th>Shows</th>
                    <th>Also takes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $shortcodes as $shortcode ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $shortcode['code'] ); ?></code></td>
                        <td><?php echo esc_html( $shortcode['shows'] ); ?></td>
                        <td><?php echo wp_kses_post( implode( ', ', array_map( function ( $a ) { return '<code>' . $a . '</code>'; }, $shortcode['atts'] ) ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <details class="mirlo-attributes">
            <summary>What the attributes do</summary>
            <table class="widefat striped mirlo-shortcodes">
                <tbody>
                    <?php foreach ( $attributes as $name => $description ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $name ); ?></code></td>
                            <td><?php echo wp_kses_post( $description ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>Example: <code>[mirlo_pop_up artist="my-artist" button_text="Support me" tier_ids="2,3" success_url="/thanks"]</code></p>
            <p>The older <code>[mirlo_subscribe]</code> and <code>[mirlo_tiers]</code> still work, the same as <code>[mirlo_pop_up]</code> and <code>[mirlo_tier_list]</code>.</p>
        </details>
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
}
