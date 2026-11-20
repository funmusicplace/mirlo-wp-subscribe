<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: disconnects from
 * Mirlo and removes everything the plugin stored.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-mirlo-oauth.php';
require_once __DIR__ . '/includes/class-mirlo-api.php';
require_once __DIR__ . '/includes/class-mirlo-catalog.php';

function mirlo_subscribe_uninstall_site() {
    global $wpdb;

    // Needs the settings below, so revoke before deleting them.
    Mirlo_OAuth::revoke();
    wp_clear_scheduled_hook( Mirlo_OAuth::KEEPALIVE_HOOK );

    $options = array(
        'mirlo_api_key',
        'mirlo_button_color',
        'mirlo_button_text',
        'mirlo_instance_url',
        'mirlo_oauth_client_id',
        'mirlo_reader_sales',
        Mirlo_Catalog::READER_CAP_OPTION,
        Mirlo_OAuth::TOKENS_OPTION,
        Mirlo_OAuth::NOTICE_OPTION,
        Mirlo_OAuth::LOCK_OPTION,
    );
    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // Discovery, artist, merch and card reader caches, sign-ins in progress and settings page notices. With a
    // persistent object cache these aren't in the database, but they expire
    // within a day.
    foreach ( array( 'mirlo_oauth_', Mirlo_API::ARTIST_CACHE, Mirlo_API::MERCH_CACHE, Mirlo_API::READER_CACHE ) as $prefix ) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_' . $prefix ) . '%',
                $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
            )
        );
    }

    // The card reader capability, from every role and from any user given it directly.
    foreach ( wp_roles()->role_objects as $role ) {
        $role->remove_cap( Mirlo_Catalog::READER_CAP );
    }
    foreach ( get_users( array( 'capability' => Mirlo_Catalog::READER_CAP ) ) as $user ) {
        $user->remove_cap( Mirlo_Catalog::READER_CAP );
    }
}

if ( is_multisite() ) {
    foreach ( get_sites(
        array(
			'fields' => 'ids',
			'number' => 0,
        )
    ) as $site_id ) {
        switch_to_blog( $site_id );
        mirlo_subscribe_uninstall_site();
        restore_current_blog();
    }
} else {
    mirlo_subscribe_uninstall_site();
}
