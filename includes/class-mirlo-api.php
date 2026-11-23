<?php

class Mirlo_API {
    const ARTIST_CACHE = 'mirlo_artist_';
    const ARTIST_TTL   = 15 * MINUTE_IN_SECONDS;
    const MERCH_CACHE  = 'mirlo_merch_';
    const MERCH_TTL    = 2 * MINUTE_IN_SECONDS;
    const READER_CACHE = 'mirlo_readers_';
    const READER_TTL   = MINUTE_IN_SECONDS;

    private $base_url;
    private $api_key;

    public function __construct() {
        $this->base_url = Mirlo_OAuth::instance_url() . '/v1';
        $this->api_key  = get_option( 'mirlo_api_key', '' );
    }

    /**
     * An artist's public details, including their subscription tiers. Kept
     * for 15 minutes, so pop-ups don't ask Mirlo every time they open.
     *
     * @param bool $fresh Ask Mirlo even if there's a saved copy.
     */
    public function get_artist( $url_slug, $fresh = false ) {
        $key = self::ARTIST_CACHE . md5( $this->base_url . '|' . $url_slug );
        if ( ! $fresh ) {
            $cached = get_transient( $key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }
        $result = $this->make_request( '/artists/' . rawurlencode( $url_slug ) );
        if ( ! isset( $result['error'] ) && isset( $result['result']['id'] ) ) {
            set_transient( $key, $result, self::ARTIST_TTL );
        }
        return $result;
    }

    /**
     * An artist's public merch, with each item's variants and shipping
     * regions. Kept for only 2 minutes, so stock changes show up soon.
     *
     * @param string|int $artist Artist URL slug or ID.
     * @param bool       $fresh  Ask Mirlo even if there's a saved copy.
     */
    public function get_artist_merch( $artist, $fresh = false ) {
        $key = self::MERCH_CACHE . md5( $this->base_url . '|' . $artist );
        if ( ! $fresh ) {
            $cached = get_transient( $key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }
        $result = $this->make_request( '/artists/' . rawurlencode( (string) $artist ) . '/merch' );
        if ( ! isset( $result['error'] ) && isset( $result['results'] ) && is_array( $result['results'] ) ) {
            set_transient( $key, $result, self::MERCH_TTL );
        }
        return $result;
    }

    /**
     * Where a buyer may be sent after paying: a page on this site or on
     * Mirlo. A path such as /thanks becomes a full address on this site.
     *
     * @return string The full address, or '' if it's anywhere else.
     */
    public static function return_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return '';
        }
        if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
            $url = home_url( $url );
        }
        $url     = esc_url_raw( $url, array( 'http', 'https' ) );
        $host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $allowed = array(
            strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
            strtolower( (string) wp_parse_url( Mirlo_OAuth::instance_url(), PHP_URL_HOST ) ),
        );
        return '' !== $host && in_array( $host, $allowed, true ) ? $url : '';
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
     * Start a hosted checkout for one merch item at its minimum price. Mirlo
     * answers with a redirectUrl to send the buyer to, where they enter
     * their email, address and card.
     *
     * @param string      $merch_id                The merch item's ID.
     * @param string|null $shipping_destination_id Required by Mirlo when the
     *                                             item has shipping regions.
     * @param string[]    $merch_option_ids        Chosen variants, one per
     *                                             required variant type.
     */
    public function purchase_merch( $artist_id, $merch_id, $success_url = null, $shipping_destination_id = null, $merch_option_ids = array() ) {
        $item = array(
            'type'     => 'merch',
            'id'       => (string) $merch_id,
            'quantity' => 1,
        );
        if ( $shipping_destination_id ) {
            $item['shippingDestinationId'] = (string) $shipping_destination_id;
        }
        if ( ! empty( $merch_option_ids ) ) {
            $item['merchOptionIds'] = array_values( array_map( 'strval', $merch_option_ids ) );
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
     * The card readers on the artist's Stripe account, as the connected
     * Mirlo account (needs the pos scope). Each has id, label, deviceType
     * and status ("online" or "offline"). Kept for a minute, so a reader
     * that goes offline shows as such soon after.
     *
     * @param int  $artist_id Numeric artist ID.
     * @param bool $fresh     Ask Mirlo even if there's a saved copy.
     */
    public function get_artist_readers( $artist_id, $fresh = false ) {
        $key = $this->readers_key( $artist_id );
        if ( ! $fresh ) {
            $cached = get_transient( $key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }
        $result = $this->make_authenticated_request( '/manage/artists/' . intval( $artist_id ) . '/readers' );
        if ( ! isset( $result['error'] ) && isset( $result['results'] ) && is_array( $result['results'] ) ) {
            set_transient( $key, $result, self::READER_TTL );
        }
        return $result;
    }

    /** Drop the saved reader list, e.g. after a reader turned out to be offline. */
    public function forget_artist_readers( $artist_id ) {
        delete_transient( $this->readers_key( $artist_id ) );
    }

    private function readers_key( $artist_id ) {
        return self::READER_CACHE . md5( $this->base_url . '|' . intval( $artist_id ) );
    }

    /**
     * Send a sale to a card reader, as the connected Mirlo account (needs
     * the pos scope). The reader then asks for a card for the whole sale.
     *
     * Mirlo answers with paymentIntentId (merch) or setupIntentId (a
     * subscription: the card is saved and charged monthly), and
     * stripeAccountId, for get_reader_purchase() and cancel_reader_purchase().
     *
     * @param array       $items Purchase items, e.g. array( 'type' => 'merch', 'id' => ..., 'quantity' => 2 ),
     *                           or a single array( 'type' => 'subscription', 'tierId' => 2 ),
     *                           which Mirlo sells on its own.
     * @param string|null $email The buyer's email. Mirlo needs it for a subscription
     *                           and for merch that comes with a download.
     */
    public function purchase_on_reader( $artist_id, $reader_id, $items, $email = null ) {
        $body = array(
            'artistId' => intval( $artist_id ),
            'readerId' => (string) $reader_id,
            'items'    => array_values( $items ),
        );
        if ( $email ) {
            $body['email'] = $email;
        }
        return $this->make_authenticated_request( '/purchase', 'POST', $body );
    }

    /**
     * A reader sale's status: Stripe's PaymentIntent or SetupIntent status,
     * e.g. requires_payment_method (waiting for a card), requires_capture,
     * processing, succeeded or canceled.
     */
    public function get_reader_purchase( $intent_id, $stripe_account_id ) {
        return $this->make_authenticated_request(
            '/purchase/' . rawurlencode( $intent_id ) . '?stripeAccountId=' . rawurlencode( $stripe_account_id )
        );
    }

    /** Cancel a reader sale that hasn't been paid, and clear it from the reader. */
    public function cancel_reader_purchase( $intent_id, $stripe_account_id, $reader_id ) {
        return $this->make_authenticated_request(
            '/purchase/' . rawurlencode( $intent_id ) . '?stripeAccountId=' . rawurlencode( $stripe_account_id ) . '&readerId=' . rawurlencode( $reader_id ),
            'DELETE'
        );
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
        if ( 200 !== $code ) {
            $body = wp_remote_retrieve_body( $response );
            return array(
                'error'        => "HTTP {$code}: {$body}",
                'error_status' => $code,
            );
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }
}
