<?php

class Mirlo_API {
    private $base_url = 'https://mirlo.space/v1';
    private $api_key;

    public function __construct() {
        $this->api_key = get_option( 'mirlo_api_key', '' );
    }

    public function get_artist( $url_slug ) {
        return $this->make_request( '/artists/' . rawurlencode( $url_slug ) );
    }

    public function get_subscription_tiers( $artist_id ) {
        return $this->make_request( '/artists/' . intval( $artist_id ) . '/subscriptionTiers' );
    }

    public function subscribe( $artist_id, $tier_id ) {
        return $this->make_request(
            '/artists/' . intval( $artist_id ) . '/subscribe',
            'POST',
            array( 'tierId' => intval( $tier_id ) )
        );
    }

    private function make_request( $endpoint, $method = 'GET', $body = null ) {
        $url  = $this->base_url . $endpoint;
        $args = array(
            'method'  => $method,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 10,
        );

        if ( $this->api_key ) {
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
            return array( 'error' => "HTTP {$code}: {$body}" );
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }
}
