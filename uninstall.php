<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: disconnects from
 * Mirlo and removes everything the plugin stored.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-mirlo-oauth.php';

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
        Mirlo_OAuth::TOKENS_OPTION,
        Mirlo_OAuth::NOTICE_OPTION,
        Mirlo_OAuth::LOCK_OPTION,
    );
    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // Discovery cache, sign-ins in progress and settings page notices. With a
    // persistent object cache these aren't in the database, but they expire
    // within a day.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like( '_transient_mirlo_oauth_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_mirlo_oauth_' ) . '%'
        )
    );
}

if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
        switch_to_blog( $site_id );
        mirlo_subscribe_uninstall_site();
        restore_current_blog();
    }
} else {
    mirlo_subscribe_uninstall_site();
}
