<?php

/**
 * Connects the site to a Mirlo account over OAuth 2.1 (authorization code + PKCE),
 * so server-side requests can act as that account with a scoped bearer token.
 *
 * See https://docs.mirlo.space/api/oauth.html
 */
class Mirlo_OAuth {
    const DEFAULT_INSTANCE = 'https://mirlo.space';
    const TOKENS_OPTION    = 'mirlo_oauth_tokens';
    const NOTICE_OPTION    = 'mirlo_oauth_notice';
    const LOCK_OPTION      = 'mirlo_oauth_refresh_lock';
    const FLOW_TRANSIENT   = 'mirlo_oauth_flow_';
    const DISCOVERY_TTL    = DAY_IN_SECONDS;
    const EXPIRY_LEEWAY    = 60;

    public function __construct() {
        add_action( 'admin_post_mirlo_oauth_connect', array( $this, 'handle_connect' ) );
        add_action( 'admin_post_mirlo_oauth_callback', array( $this, 'handle_callback' ) );
        add_action( 'admin_post_mirlo_oauth_disconnect', array( $this, 'handle_disconnect' ) );
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /* ------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------ */

    public static function instance_url() {
        $url = untrailingslashit( get_option( 'mirlo_instance_url', '' ) );
        return $url ? $url : self::DEFAULT_INSTANCE;
    }

    public static function redirect_uri() {
        return admin_url( 'admin-post.php?action=mirlo_oauth_callback' );
    }

    public static function metadata_document_url() {
        return rest_url( 'mirlo/v1/client-metadata' );
    }

    /**
     * A Client ID Metadata Document URL must be HTTPS, and a query string
     * (plain permalinks give "?rest_route=") isn't allowed.
     */
    public static function can_use_metadata_document() {
        $url = self::metadata_document_url();
        return 0 === strpos( $url, 'https://' ) && false === strpos( $url, '?' );
    }

    /**
     * The numeric ID of a client registered on the instance, or else this
     * site's own metadata document URL.
     */
    public static function client_id() {
        $registered = trim( get_option( 'mirlo_oauth_client_id', '' ) );
        if ( '' !== $registered ) {
            return $registered;
        }
        return self::can_use_metadata_document() ? self::metadata_document_url() : '';
    }

    public static function uses_registered_client() {
        return '' !== trim( get_option( 'mirlo_oauth_client_id', '' ) );
    }

    public static function scopes() {
        return apply_filters(
            'mirlo_oauth_scopes',
            array( 'openid', 'profile', 'subscribers:read', 'offline_access' )
        );
    }

    /* ------------------------------------------------------------------
     * Connection state
     * ------------------------------------------------------------------ */

    /**
     * Stored tokens, or null when not connected. Tokens issued for another
     * instance or client (the settings changed since) don't count.
     */
    private static function get_tokens() {
        $tokens = get_option( self::TOKENS_OPTION );
        if ( ! is_array( $tokens ) || empty( $tokens['access_token'] ) ) {
            return null;
        }
        if ( $tokens['instance'] !== self::instance_url() || $tokens['client_id'] !== self::client_id() ) {
            return null;
        }
        return $tokens;
    }

    public static function is_connected() {
        return null !== self::get_tokens();
    }

    /**
     * The connected Mirlo account: array with 'sub' and 'name', or null.
     */
    public static function get_account() {
        $tokens = self::get_tokens();
        return $tokens ? $tokens['account'] : null;
    }

    /**
     * A current access token, refreshing it if it has expired.
     *
     * @param string|null $rejected A token the API just rejected. It's
     *                              refreshed even if it hasn't expired,
     *                              unless another request already has.
     * @return string|WP_Error
     */
    public static function get_access_token( $rejected = null ) {
        $tokens = self::get_tokens();
        if ( ! $tokens ) {
            return new WP_Error( 'mirlo_not_connected', 'This site isn\'t connected to Mirlo.' );
        }
        $is_rejected = null !== $rejected && $rejected === $tokens['access_token'];
        if ( ! $is_rejected && $tokens['expires_at'] > time() + self::EXPIRY_LEEWAY ) {
            return $tokens['access_token'];
        }
        return self::refresh( $tokens );
    }

    /**
     * Mirlo rotates refresh tokens, and using an old one again revokes the
     * whole grant, so only one request may refresh at a time.
     */
    private static function refresh( $tokens ) {
        if ( empty( $tokens['refresh_token'] ) ) {
            self::drop_connection( 'Your Mirlo access expired. Connect again to keep using it.' );
            return new WP_Error( 'mirlo_not_connected', 'The Mirlo access token expired and can\'t be refreshed.' );
        }

        if ( ! self::acquire_lock() ) {
            // Another request is refreshing. Wait for it to store new tokens.
            for ( $i = 0; $i < 10; $i++ ) {
                usleep( 500000 );
                wp_cache_delete( self::TOKENS_OPTION, 'options' );
                $latest = self::get_tokens();
                if ( ! $latest ) {
                    return new WP_Error( 'mirlo_not_connected', 'This site isn\'t connected to Mirlo.' );
                }
                if ( $latest['access_token'] !== $tokens['access_token'] ) {
                    return $latest['access_token'];
                }
            }
            return new WP_Error( 'mirlo_refresh_busy', 'Timed out waiting for the Mirlo token to refresh.' );
        }

        // Another request may have refreshed between our read and the lock.
        wp_cache_delete( self::TOKENS_OPTION, 'options' );
        $latest = self::get_tokens();
        if ( $latest && $latest['access_token'] !== $tokens['access_token'] ) {
            self::release_lock();
            return $latest['access_token'];
        }

        $response = self::token_request(
            array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $tokens['refresh_token'],
            )
        );

        if ( is_wp_error( $response ) ) {
            self::release_lock();
            if ( 'invalid_grant' === $response->get_error_code() ) {
                self::drop_connection( 'Mirlo no longer accepts this site\'s access. It may have been disconnected under Connected apps on Mirlo. Connect again to restore it.' );
            }
            return $response;
        }

        $tokens = self::store_tokens( $response, $tokens['account'], $tokens['refresh_token'] );
        self::release_lock();
        return $tokens['access_token'];
    }

    private static function acquire_lock() {
        global $wpdb;

        $locked_at = (int) get_option( self::LOCK_OPTION, 0 );
        if ( $locked_at && $locked_at < time() - 30 ) {
            // A refresh that died without releasing the lock.
            delete_option( self::LOCK_OPTION );
        }

        // add_option() isn't atomic, so insert directly.
        return 1 === $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                self::LOCK_OPTION,
                time()
            )
        );
    }

    private static function release_lock() {
        delete_option( self::LOCK_OPTION );
    }

    private static function store_tokens( $response, $account, $previous_refresh_token = '' ) {
        $tokens = array(
            'instance'      => self::instance_url(),
            'client_id'     => self::client_id(),
            'access_token'  => $response['access_token'],
            // Confidential clients may keep their refresh token.
            'refresh_token' => $response['refresh_token'] ?? $previous_refresh_token,
            'expires_at'    => time() + intval( $response['expires_in'] ?? HOUR_IN_SECONDS ),
            'scope'         => $response['scope'] ?? implode( ' ', self::scopes() ),
            'account'       => $account,
        );
        update_option( self::TOKENS_OPTION, $tokens, false );
        return $tokens;
    }

    private static function drop_connection( $notice ) {
        delete_option( self::TOKENS_OPTION );
        update_option( self::NOTICE_OPTION, $notice, false );
        do_action( 'mirlo_oauth_disconnected' );
    }

    /* ------------------------------------------------------------------
     * Talking to the authorization server
     * ------------------------------------------------------------------ */

    /**
     * The instance's authorization server metadata, cached for a day.
     *
     * @return array|WP_Error
     */
    public static function discovery() {
        $instance  = self::instance_url();
        $cache_key = 'mirlo_oauth_discovery_' . md5( $instance );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $response = wp_remote_get( $instance . '/.well-known/oauth-authorization-server', array( 'timeout' => 10 ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $metadata = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== wp_remote_retrieve_response_code( $response )
            || ! is_array( $metadata )
            || empty( $metadata['issuer'] )
            || empty( $metadata['authorization_endpoint'] )
            || empty( $metadata['token_endpoint'] ) ) {
            return new WP_Error( 'mirlo_discovery_failed', sprintf( 'Couldn\'t find an OAuth server at %s.', $instance ) );
        }

        set_transient( $cache_key, $metadata, self::DISCOVERY_TTL );
        return $metadata;
    }

    /**
     * POST to the token endpoint.
     *
     * @return array|WP_Error The token response, or an error whose code is
     *                        the OAuth error (e.g. invalid_grant).
     */
    private static function token_request( $params ) {
        $metadata = self::discovery();
        if ( is_wp_error( $metadata ) ) {
            return $metadata;
        }

        $response = wp_remote_post( $metadata['token_endpoint'], self::with_client_id( $params ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $body['access_token'] ) ) {
            $error = $body['error'] ?? 'mirlo_token_failed';
            return new WP_Error( $error, $body['error_description'] ?? $error );
        }
        return $body;
    }

    /**
     * Request args for a form POST from this client. The site is always a
     * public client: a secret would sit in the same options table as the
     * tokens, so it would add nothing. PKCE and refresh token rotation are
     * what protect the grant.
     */
    private static function with_client_id( $body ) {
        $body['client_id'] = self::client_id();
        return array(
            'timeout' => 10,
            'body'    => $body,
        );
    }

    private static function fetch_account( $access_token ) {
        $metadata = self::discovery();
        if ( is_wp_error( $metadata ) || empty( $metadata['userinfo_endpoint'] ) ) {
            return array( 'sub' => '', 'name' => '' );
        }
        $response = wp_remote_get(
            $metadata['userinfo_endpoint'],
            array(
                'timeout' => 10,
                'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
            )
        );
        $body = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
        return array(
            'sub'  => (string) ( $body['sub'] ?? '' ),
            'name' => (string) ( $body['name'] ?? '' ),
        );
    }

    private static function base64url( $bytes ) {
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    }

    /* ------------------------------------------------------------------
     * Request handlers
     * ------------------------------------------------------------------ */

    public function handle_connect() {
        check_admin_referer( 'mirlo_oauth_connect' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $client_id = self::client_id();
        if ( '' === $client_id ) {
            $this->back_to_settings( 'error', 'Enter a client ID first. This site can\'t identify itself with a metadata document because it isn\'t served over HTTPS with pretty permalinks.' );
        }

        $metadata = self::discovery();
        if ( is_wp_error( $metadata ) ) {
            $this->back_to_settings( 'error', $metadata->get_error_message() );
        }

        $verifier = self::base64url( random_bytes( 32 ) );
        $state    = self::base64url( random_bytes( 16 ) );

        set_transient(
            self::FLOW_TRANSIENT . $state,
            array(
                'verifier'  => $verifier,
                'user_id'   => get_current_user_id(),
                'issuer'    => $metadata['issuer'],
                'instance'  => self::instance_url(),
                'client_id' => $client_id,
            ),
            10 * MINUTE_IN_SECONDS
        );

        $url = add_query_arg(
            rawurlencode_deep(
                array(
                    'client_id'             => $client_id,
                    'response_type'         => 'code',
                    'redirect_uri'          => self::redirect_uri(),
                    'scope'                 => implode( ' ', self::scopes() ),
                    'prompt'                => 'consent',
                    'state'                 => $state,
                    'code_challenge'        => self::base64url( hash( 'sha256', $verifier, true ) ),
                    'code_challenge_method' => 'S256',
                )
            ),
            $metadata['authorization_endpoint']
        );

        wp_redirect( $url );
        exit;
    }

    public function handle_callback() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
        $flow  = $state ? get_transient( self::FLOW_TRANSIENT . $state ) : false;
        if ( ! is_array( $flow ) || (int) $flow['user_id'] !== get_current_user_id() ) {
            $this->back_to_settings( 'error', 'The Mirlo sign-in expired or didn\'t start here. Try connecting again.' );
        }
        delete_transient( self::FLOW_TRANSIENT . $state );

        if ( $flow['instance'] !== self::instance_url() || $flow['client_id'] !== self::client_id() ) {
            $this->back_to_settings( 'error', 'The Mirlo settings changed during sign-in. Try connecting again.' );
        }

        $error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );
        if ( 'access_denied' === $error ) {
            $this->back_to_settings(
                'error',
                self::uses_registered_client()
                    ? 'Mirlo didn\'t grant access. The request was declined, or the Mirlo account\'s email address isn\'t verified yet.'
                    : 'Mirlo didn\'t grant access. If this is the first time this site has connected to the instance, its admins need to approve it before you can connect. Otherwise the request was declined, or the Mirlo account\'s email address isn\'t verified yet.'
            );
        }
        if ( $error ) {
            $description = sanitize_text_field( wp_unslash( $_GET['error_description'] ?? '' ) );
            $this->back_to_settings( 'error', sprintf( 'Mirlo returned an error: %s', $description ? $description : $error ) );
        }

        $iss = esc_url_raw( wp_unslash( $_GET['iss'] ?? '' ) );
        if ( untrailingslashit( $iss ) !== untrailingslashit( $flow['issuer'] ) ) {
            $this->back_to_settings( 'error', 'The response came from a different Mirlo instance than expected.' );
        }

        $response = self::token_request(
            array(
                'grant_type'    => 'authorization_code',
                'code'          => sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) ),
                'redirect_uri'  => self::redirect_uri(),
                'code_verifier' => $flow['verifier'],
            )
        );
        if ( is_wp_error( $response ) ) {
            $this->back_to_settings( 'error', sprintf( 'Couldn\'t get a token from Mirlo: %s', $response->get_error_message() ) );
        }

        $account = self::fetch_account( $response['access_token'] );
        self::store_tokens( $response, $account );
        delete_option( self::NOTICE_OPTION );
        do_action( 'mirlo_oauth_connected', $account );

        $this->back_to_settings( 'success', 'Connected to Mirlo.' );
    }

    public function handle_disconnect() {
        check_admin_referer( 'mirlo_oauth_disconnect' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        self::revoke();
        do_action( 'mirlo_oauth_disconnected' );

        $this->back_to_settings( 'success', 'Disconnected from Mirlo.' );
    }

    /**
     * Revoke the site's access on Mirlo, so it drops off the account's
     * Connected apps, and forget the tokens.
     */
    public static function revoke() {
        $tokens   = get_option( self::TOKENS_OPTION );
        $metadata = self::discovery();
        if ( is_array( $tokens ) && ! is_wp_error( $metadata ) && ! empty( $metadata['revocation_endpoint'] ) ) {
            // Revoking the refresh token ends the whole grant.
            $token = ! empty( $tokens['refresh_token'] ) ? $tokens['refresh_token'] : $tokens['access_token'];
            wp_remote_post( $metadata['revocation_endpoint'], self::with_client_id( array( 'token' => $token ) ) );
        }

        delete_option( self::TOKENS_OPTION );
        delete_option( self::NOTICE_OPTION );
    }

    private function back_to_settings( $type, $message ) {
        set_transient(
            'mirlo_oauth_result_' . get_current_user_id(),
            array( 'type' => $type, 'message' => $message ),
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect( admin_url( 'options-general.php?page=mirlo-subscribe' ) );
        exit;
    }

    /* ------------------------------------------------------------------
     * Client ID Metadata Document
     * ------------------------------------------------------------------ */

    public function register_routes() {
        register_rest_route(
            'mirlo/v1',
            '/client-metadata',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'serve_client_metadata' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function serve_client_metadata() {
        $name = wp_strip_all_tags( get_bloginfo( 'name' ) );
        $document = array(
            'client_id'                  => self::metadata_document_url(),
            'client_name'                => mb_substr( $name ? $name : wp_parse_url( home_url(), PHP_URL_HOST ), 0, 100 ),
            'client_uri'                 => home_url( '/' ),
            'redirect_uris'              => array( self::redirect_uri() ),
            'application_type'           => 'web',
            'grant_types'                => array( 'authorization_code', 'refresh_token' ),
            'response_types'             => array( 'code' ),
            'token_endpoint_auth_method' => 'none',
        );

        $logo = get_site_icon_url( 512 );
        if ( $logo && 0 === strpos( $logo, 'https://' ) ) {
            $document['logo_uri'] = $logo;
        }

        $response = new WP_REST_Response( $document );
        $response->header( 'Cache-Control', 'public, max-age=3600' );
        return $response;
    }
}
