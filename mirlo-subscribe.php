<?php
/**
 * Plugin Name: Mirlo Subscribe
 * Plugin URI: https://mirlo.space
 * Description: Add subscription modals for Mirlo artists using shortcodes.
 * Version: 1.0.6
 * Author: Mirlo Code Circle
 * License: AGPL v3 or later
 * Text Domain: mirlo-subscribe
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MIRLO_SUBSCRIBE_PATH', plugin_dir_path( __FILE__ ) );
define( 'MIRLO_SUBSCRIBE_URL', plugin_dir_url( __FILE__ ) );
define( 'MIRLO_SUBSCRIBE_VERSION', '1.0.6' );

require_once MIRLO_SUBSCRIBE_PATH . 'includes/class-mirlo-api.php';
require_once MIRLO_SUBSCRIBE_PATH . 'includes/class-mirlo-shortcode.php';
require_once MIRLO_SUBSCRIBE_PATH . 'includes/class-mirlo-admin.php';

add_action( 'plugins_loaded', function () {
    new Mirlo_Shortcode();
    new Mirlo_Admin();
} );
