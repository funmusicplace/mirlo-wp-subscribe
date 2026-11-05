<?php
/**
 * Plugin Name: Mock Mirlo (development only)
 * Description: Answers Mirlo Subscribe's requests to Mirlo with made-up data, so the UI can be worked on without a Mirlo account or a network connection. Does nothing unless the Mirlo Instance setting is http://mirlo.mock.
 *
 * Not part of the released plugin. dev/ is left out of the release zip.
 *
 * Artist slugs that show other states:
 *   missing         Mirlo says the artist doesn't exist (404)
 *   broken          Mirlo has an error (500)
 *   no-tiers        the artist has no subscription tiers
 *   slow            Mirlo takes 3 seconds to answer, to see loading states
 *   no-subscribers  the connected account's subscriber list is empty
 * Any other slug is a normal artist with four tiers and a few subscribers.
 *
 * Like the real Mirlo, checkout with a success_url on this site only works
 * once an API key is set in the settings. Any key will do here.
 *
 * Visit /?mock_mirlo=end-connection to pretend Mirlo ended the connection,
 * to see how the plugin reacts. Connecting again clears it.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MOCK_MIRLO_INSTANCE', 'http://mirlo.mock' );
// This file is mounted into wp-content/mu-plugins, so find dev/ through the
// mounted plugin rather than relative to here.
define( 'MOCK_MIRLO_PREVIEW_FILE', WP_PLUGIN_DIR . '/mirlo-subscribe/dev/preview-page.html' );

function mock_mirlo_active() {
    return MOCK_MIRLO_INSTANCE === untrailingslashit( get_option( 'mirlo_instance_url', '' ) );
}

/* ----------------------------------------------------------------------
 * Requests from WordPress to Mirlo
 * ---------------------------------------------------------------------- */

add_filter(
    'pre_http_request',
    function ( $preempt, $args, $url ) {
        if ( 0 !== strpos( $url, MOCK_MIRLO_INSTANCE . '/' ) ) {
            return $preempt;
        }
        $parts = wp_parse_url( $url );
        $query = array();
        parse_str( $parts['query'] ?? '', $query );
        return mock_mirlo_route( strtoupper( $args['method'] ?? 'GET' ), $parts['path'] ?? '/', $query, $args );
    },
    10,
    3
);

function mock_mirlo_response( $status, $data ) {
    return array(
        'headers'  => array( 'content-type' => 'application/json' ),
        'body'     => wp_json_encode( $data ),
        'response' => array( 'code' => $status, 'message' => get_status_header_desc( $status ) ),
        'cookies'  => array(),
        'filename' => null,
    );
}

function mock_mirlo_route( $method, $path, $query, $args ) {
    $bearer = $args['headers']['Authorization'] ?? '';

    // OAuth server.
    if ( '/.well-known/oauth-authorization-server' === $path ) {
        return mock_mirlo_response(
            200,
            array(
                'issuer'                 => MOCK_MIRLO_INSTANCE,
                // The sign-in page is served by this WordPress site, since
                // the browser can't reach http://mirlo.mock.
                'authorization_endpoint' => home_url( '/?mock_mirlo=authorize' ),
                'token_endpoint'         => MOCK_MIRLO_INSTANCE . '/auth/oidc/token',
                'revocation_endpoint'    => MOCK_MIRLO_INSTANCE . '/auth/oidc/token/revocation',
                'userinfo_endpoint'      => MOCK_MIRLO_INSTANCE . '/auth/oidc/me',
                'code_challenge_methods_supported' => array( 'S256' ),
            )
        );
    }
    if ( 'POST' === $method && '/auth/oidc/token' === $path ) {
        $grant_type = is_array( $args['body'] ?? null ) ? ( $args['body']['grant_type'] ?? '' ) : '';
        if ( 'authorization_code' === $grant_type ) {
            delete_option( 'mock_mirlo_connection_ended' );
        } elseif ( 'refresh_token' === $grant_type && get_option( 'mock_mirlo_connection_ended' ) ) {
            return mock_mirlo_response( 400, array( 'error' => 'invalid_grant', 'error_description' => 'grant request is invalid' ) );
        }
        return mock_mirlo_response(
            200,
            array(
                'access_token'  => 'mock-access-' . wp_generate_password( 16, false ),
                'refresh_token' => 'mock-refresh-' . wp_generate_password( 16, false ),
                'token_type'    => 'Bearer',
                'expires_in'    => HOUR_IN_SECONDS,
                'scope'         => 'openid profile subscribers:read offline_access',
            )
        );
    }
    if ( 'POST' === $method && '/auth/oidc/token/revocation' === $path ) {
        return mock_mirlo_response( 200, array() );
    }
    if ( '/auth/oidc/me' === $path ) {
        return mock_mirlo_response( 200, array( 'sub' => '1', 'name' => 'Mock Artist Owner' ) );
    }

    // API.
    if ( ! preg_match( '#^/v1(/.*)$#', $path, $m ) ) {
        return mock_mirlo_response( 404, array( 'error' => 'Not found' ) );
    }
    $endpoint = $m[1];

    if ( preg_match( '#^/manage/artists/([^/]+)/subscribers/active$#', $endpoint, $m ) ) {
        if ( 0 !== strpos( $bearer, 'Bearer mock-access-' ) ) {
            return mock_mirlo_response( 401, array( 'error' => 'invalid_token' ) );
        }
        $slug = rawurldecode( $m[1] );
        if ( in_array( $slug, array( 'missing', 'broken' ), true ) ) {
            return mock_mirlo_artist_error( $slug );
        }
        $results = 'no-subscribers' === $slug ? array() : mock_mirlo_subscribers();
        if ( isset( $query['paid'] ) ) {
            $paid    = 'true' === $query['paid'];
            $results = array_values( array_filter( $results, function ( $s ) use ( $paid ) {
                return $s['paid'] === $paid;
            } ) );
        }
        return mock_mirlo_response( 200, array( 'results' => $results ) );
    }

    if ( 'GET' === $method && preg_match( '#^/artists/([^/]+)$#', $endpoint, $m ) ) {
        $slug = rawurldecode( $m[1] );
        if ( 'slow' === $slug ) {
            sleep( 3 );
        }
        if ( in_array( $slug, array( 'missing', 'broken' ), true ) ) {
            return mock_mirlo_artist_error( $slug );
        }
        return mock_mirlo_response( 200, array( 'result' => mock_mirlo_artist( $slug ) ) );
    }

    if ( preg_match( '#^/artists/(\d+)/subscriptionTiers$#', $endpoint ) ) {
        return mock_mirlo_response( 200, array( 'results' => mock_mirlo_tiers() ) );
    }

    // Checkout: send the buyer to a stand-in for Stripe on this site. Real
    // Mirlo removed POST /artists/{id}/subscribe, so the mock doesn't answer it.
    $body = is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true ) : ( $args['body'] ?? array() );
    if ( 'POST' === $method && '/purchase' === $endpoint ) {
        $success_url = $body['successUrl'] ?? '';
        if ( '' !== $success_url ) {
            $error = mock_mirlo_success_url_error( $success_url, $args['headers']['mirlo-api-key'] ?? '' );
            if ( $error ) {
                return mock_mirlo_response( 400, array( 'error' => $error ) );
            }
        }
        $tier = $body['items'][0]['tierId'] ?? 0;
        return mock_mirlo_response( 200, array( 'redirectUrl' => mock_mirlo_checkout_url( $tier, $success_url ) ) );
    }

    return mock_mirlo_response( 404, array( 'error' => "Mock Mirlo doesn't know $method $path" ) );
}

function mock_mirlo_origin( $url ) {
    $parts = wp_parse_url( $url );
    if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
        return null;
    }
    return strtolower( $parts['scheme'] . '://' . $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
}

/**
 * Like Mirlo's assertAllowedSuccessUrl: buyers can only be sent back to
 * Mirlo itself, or to the calling app's own site. Mirlo only knows which app
 * is calling when it sends an API key. Here any key counts as this site's.
 */
function mock_mirlo_success_url_error( $success_url, $api_key ) {
    $target = mock_mirlo_origin( $success_url );
    if ( ! $target ) {
        return 'Invalid successUrl';
    }
    $allowed = array( mock_mirlo_origin( MOCK_MIRLO_INSTANCE ) );
    if ( '' !== $api_key ) {
        $allowed[] = mock_mirlo_origin( home_url( '/' ) );
    }
    return in_array( $target, $allowed, true ) ? null : 'successUrl origin is not allowed for this client';
}

function mock_mirlo_artist_error( $slug ) {
    return 'missing' === $slug
        ? mock_mirlo_response( 404, array( 'error' => 'Artist not found' ) )
        : mock_mirlo_response( 500, array( 'error' => 'Something went wrong (mock)' ) );
}

/* ----------------------------------------------------------------------
 * Made-up data. Only the fields the plugin reads are filled in.
 * ---------------------------------------------------------------------- */

function mock_mirlo_artist( $slug ) {
    $avatar = 'data:image/svg+xml,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="#be3455"/>'
        . '<text x="50" y="62" font-size="40" text-anchor="middle" fill="#fff" font-family="sans-serif">MA</text></svg>'
    );
    return array(
        'id'                => 42,
        'name'              => 'no-tiers' === $slug ? 'Artist Without Tiers' : 'Mock Artist',
        'urlSlug'           => $slug,
        'avatar'            => array( 'sizes' => array( '300' => $avatar ) ),
        'user'              => array( 'currency' => 'usd' ),
        'subscriptionTiers' => 'no-tiers' === $slug ? array() : mock_mirlo_tiers(),
    );
}

function mock_mirlo_tiers() {
    return array(
        array( 'id' => 1, 'name' => 'Follow', 'minAmount' => null, 'description' => 'Get news about new releases.', 'isDefaultTier' => true ),
        array( 'id' => 2, 'name' => 'Supporter', 'minAmount' => 500, 'currency' => 'usd', 'description' => 'Every release, as it comes out.' ),
        array( 'id' => 3, 'name' => 'Superfan', 'minAmount' => 1500, 'currency' => 'usd', 'description' => 'Everything in Supporter, plus a postcard from every tour and early access to tickets. This description is long on purpose, to see how the layout copes with a few lines of text.' ),
        array( 'id' => 4, 'name' => 'Euro tier', 'minAmount' => 1000, 'currency' => 'eur', 'description' => '' ),
    );
}

function mock_mirlo_subscribers() {
    $people = array(
        array( 'Ada Example', 3, 'active' ),
        array( 'Bo Placeholder', 2, 'active' ),
        array( 'Cam Testperson', 2, 'cancelling' ),
        array( 'Dee Sample', 4, 'active' ),
        array( 'Eli Demo', 1, 'active' ),
        array( 'Fran Fixture', 1, 'active' ),
        array( 'Gus <b>Escaped</b> & Co', 3, 'active' ),
    );
    $tiers = array();
    foreach ( mock_mirlo_tiers() as $tier ) {
        $tiers[ $tier['id'] ] = $tier;
    }
    $results = array();
    foreach ( $people as $i => list( $name, $tier_id, $status ) ) {
        $tier      = $tiers[ $tier_id ];
        $results[] = array(
            'id'               => 100 + $i,
            'email'            => 'person' . ( $i + 1 ) . '@example.com',
            'name'             => $name,
            'tierId'           => $tier_id,
            'tierName'         => $tier['name'],
            'paid'             => null !== $tier['minAmount'],
            'amount'           => (int) $tier['minAmount'],
            'currency'         => $tier['currency'] ?? 'usd',
            'status'           => $status,
            'currentPeriodEnd' => gmdate( 'c', strtotime( '+' . ( 3 + $i * 4 ) . ' days' ) ),
            'createdAt'        => gmdate( 'c', strtotime( '-' . ( 30 + $i * 45 ) . ' days' ) ),
            'updatedAt'        => gmdate( 'c', strtotime( '-' . ( 1 + $i ) . ' days' ) ),
        );
    }
    return $results;
}

function mock_mirlo_checkout_url( $tier_id, $success_url ) {
    return add_query_arg(
        array(
            'mock_mirlo' => 'checkout',
            'tier'       => (int) $tier_id,
            'success'    => rawurlencode( $success_url ),
        ),
        home_url( '/' )
    );
}

/* ----------------------------------------------------------------------
 * Pages the browser visits: Mirlo's sign-in screen and Stripe checkout
 * ---------------------------------------------------------------------- */

add_action(
    'init',
    function () {
        if ( ! isset( $_GET['mock_mirlo'] ) || ! mock_mirlo_active() ) {
            return;
        }
        $page = sanitize_key( $_GET['mock_mirlo'] );
        if ( 'authorize' === $page ) {
            mock_mirlo_authorize_page();
        } elseif ( 'checkout' === $page ) {
            mock_mirlo_checkout_page();
        } elseif ( 'end-connection' === $page && current_user_can( 'manage_options' ) ) {
            mock_mirlo_end_connection_page();
        }
    }
);

function mock_mirlo_authorize_page() {
    $redirect = esc_url_raw( wp_unslash( $_GET['redirect_uri'] ?? '' ) );
    $state    = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );

    if ( isset( $_GET['decision'] ) ) {
        $params = 'allow' === $_GET['decision']
            ? array( 'code' => 'mock-code-' . wp_generate_password( 12, false ) )
            : array( 'error' => 'access_denied' );
        $params['state'] = $state;
        $params['iss']   = MOCK_MIRLO_INSTANCE;
        // Only back to this site, so this can't be used as an open redirect.
        wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $params ), $redirect ) );
        exit;
    }

    $allow = add_query_arg( 'decision', 'allow' );
    $deny  = add_query_arg( 'decision', 'deny' );
    mock_mirlo_page(
        'Mock Mirlo: connect an app',
        sprintf(
            '<p>Pretend this is Mirlo asking you to approve the WordPress site.</p>'
            . '<dl><dt>Client ID</dt><dd><code>%s</code></dd><dt>Scopes</dt><dd><code>%s</code></dd>'
            . '<dt>Sends you back to</dt><dd><code>%s</code></dd></dl>'
            . '<p><a class="btn" href="%s">Allow</a> <a class="btn secondary" href="%s">Deny</a></p>',
            esc_html( wp_unslash( $_GET['client_id'] ?? '' ) ),
            esc_html( wp_unslash( $_GET['scope'] ?? '' ) ),
            esc_html( $redirect ),
            esc_url( $allow ),
            esc_url( $deny )
        )
    );
}

function mock_mirlo_checkout_page() {
    $success = esc_url( wp_unslash( $_GET['success'] ?? '' ) );
    mock_mirlo_page(
        'Mock checkout',
        sprintf(
            '<p>On the real site this would be Stripe checkout for tier <strong>%d</strong>.</p><p>%s<a class="btn secondary" href="javascript:history.back()">Back</a></p>',
            (int) ( $_GET['tier'] ?? 0 ),
            $success ? '<a class="btn" href="' . $success . '">Pay (go to success URL)</a> ' : ''
        )
    );
}

/**
 * Pretend Mirlo ended the connection (a year passed, or it was disconnected
 * under Connected apps). The plugin finds out at its next refresh.
 */
function mock_mirlo_end_connection_page() {
    update_option( 'mock_mirlo_connection_ended', 1, false );
    // Make the next use refresh straight away instead of within the hour.
    $tokens = get_option( 'mirlo_oauth_tokens' );
    if ( is_array( $tokens ) ) {
        $tokens['expires_at'] = 0;
        update_option( 'mirlo_oauth_tokens', $tokens, false );
    }
    mock_mirlo_page(
        'Mock Mirlo: connection ended',
        sprintf(
            '<p>The fake Mirlo will refuse this site\'s next refresh. The plugin notices at its daily check, or as soon as something uses the connection, such as Preview Subscribers.</p>'
            . '<p><a class="btn" href="%s">Go to the settings</a></p>',
            esc_url( admin_url( 'options-general.php?page=mirlo-subscribe' ) )
        )
    );
}

function mock_mirlo_page( $title, $html ) {
    status_header( 200 );
    header( 'Content-Type: text/html; charset=utf-8' );
    printf(
        '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>%1$s</title>'
        . '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:36rem;margin:3rem auto;padding:0 1rem}'
        . '.btn{display:inline-block;padding:.5rem 1rem;background:#be3455;color:#fff;border-radius:4px;text-decoration:none}'
        . '.btn.secondary{background:#666}dd{margin:0 0 .75rem}code{word-break:break-all}'
        . '.banner{background:#fff3cd;padding:.5rem 1rem;border-radius:4px}</style>'
        . '<p class="banner">Development mock, not the real Mirlo.</p><h1>%1$s</h1>%2$s',
        esc_html( $title ),
        $html // Built above with each value escaped.
    );
    exit;
}

/* ----------------------------------------------------------------------
 * A "Mirlo preview" page showing every shortcode, kept in sync with
 * dev/preview-page.html. Edit that file and reload to change it.
 * ---------------------------------------------------------------------- */

add_action(
    'init',
    function () {
        if ( ! mock_mirlo_active() || ! is_readable( MOCK_MIRLO_PREVIEW_FILE ) ) {
            return;
        }
        $content = file_get_contents( MOCK_MIRLO_PREVIEW_FILE );
        $hash    = md5( $content );
        if ( get_option( 'mock_mirlo_preview_hash' ) === $hash && get_post( (int) get_option( 'mock_mirlo_preview_page' ) ) ) {
            return;
        }
        $page = array(
            'ID'           => (int) get_option( 'mock_mirlo_preview_page' ),
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Mirlo preview',
            'post_name'    => 'mirlo-preview',
            'post_content' => $content,
        );
        if ( ! get_post( $page['ID'] ) ) {
            unset( $page['ID'] );
        }
        $id = wp_insert_post( wp_slash( $page ) );
        if ( $id && ! is_wp_error( $id ) ) {
            update_option( 'mock_mirlo_preview_page', $id, false );
            update_option( 'mock_mirlo_preview_hash', $hash, false );
        }
    },
    20
);
