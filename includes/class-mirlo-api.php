<?php

class Mirlo_API {
    private $base_url;
    private $api_key;

    public function __construct() {
        $this->base_url = Mirlo_OAuth::instance_url() . '/v1';
        $this->api_key  = get_option( 'mirlo_api_key', '' );
    }

    public function get_artist( $url_slug ) {
        return $this->make_request( '/artists/' . rawurlencode( $url_slug ) );
    }

    public function get_subscription_tiers( $artist_id ) {
        return $this->make_request( '/artists/' . intval( $artist_id ) . '/subscriptionTiers' );
    }

    /**
     * Start a hosted checkout for a subscription tier. Mirlo answers with a
     * redirectUrl to send the buyer to.
     *
     * @param int|null $amount Monthly amount in cents, for tiers that let
     *                         people choose what they pay. Mirlo checks it
     *                         against the tier's minimum and maximum. Leave
     *                         out to pay the tier's price.
     */
    public function purchase_subscription( $artist_id, $tier_id, $success_url = null, $amount = null ) {
        $item = array(
            'type'   => 'subscription',
            'tierId' => intval( $tier_id ),
        );
        if ( null !== $amount ) {
            $item['amount'] = intval( $amount );
        }
        $body = array(
            'artistId' => intval( $artist_id ),
            'hosted'   => true,
            'items'    => array( $item ),
        );

        if ( $success_url ) {
            $body['successUrl'] = $success_url;
        }

        return $this->make_request( '/purchase', 'POST', $body );
    }

    /**
     * The artist's current subscribers, as the connected Mirlo account.
     * Needs the site connected with the subscribers:read scope, by an
     * account that can edit the artist.
     *
     * @param string    $artist Artist URL slug or ID.
     * @param bool|null $paid   true for paying subscribers only, false for
     *                          followers only, null for both.
     */
    public function get_subscribers( $artist, $paid = null ) {
        $endpoint = '/manage/artists/' . rawurlencode( $artist ) . '/subscribers/active';
        if ( null !== $paid ) {
            $endpoint .= '?paid=' . ( $paid ? 'true' : 'false' );
        }
        return $this->make_authenticated_request( $endpoint );
    }

    /**
     * Make a request with the connected account's bearer token, refreshing
     * it once if Mirlo says it's no longer valid.
     */
    private function make_authenticated_request( $endpoint, $method = 'GET', $body = null ) {
        $token = Mirlo_OAuth::get_access_token();
        if ( is_wp_error( $token ) ) {
            return array( 'error' => $token->get_error_message() );
        }

        $result = $this->make_request( $endpoint, $method, $body, $token );
        if ( isset( $result['error_status'] ) && 401 === $result['error_status'] ) {
            $token = Mirlo_OAuth::get_access_token( $token );
            if ( is_wp_error( $token ) ) {
                return array( 'error' => $token->get_error_message() );
            }
            $result = $this->make_request( $endpoint, $method, $body, $token );
        }
        return $result;
    }

    private function make_request( $endpoint, $method = 'GET', $body = null, $access_token = null ) {
        $url  = $this->base_url . $endpoint;
        $args = array(
            'method'  => $method,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 10,
        );

        // A bearer token replaces the API key.
        if ( $access_token ) {
            $args['headers']['Authorization'] = 'Bearer ' . $access_token;
        } elseif ( $this->api_key ) {
            $args['headers']['mirlo-api-key'] = $this->api_key;
        }

        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return array( 'error' => $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $body = wp_remote_retrieve_body( $response );
            return array(
                'error'        => "HTTP {$code}: {$body}",
                'error_status' => $code,
            );
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }
}
