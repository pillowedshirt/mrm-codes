<?php
/**
 * Plugin Name: MRM Masterclass
 * Description: Standalone masterclass calendar, registration, Stripe checkout, Google Calendar, reminders, and emergency cancellation system for Low Brass Lessons.
 * Version: 1.0.0
 * Author: Matt Rose
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MRM_MASTERCLASS_FILE', __FILE__ );
define( 'MRM_MASTERCLASS_DIR', plugin_dir_path( __FILE__ ) );
define( 'MRM_MASTERCLASS_URL', plugin_dir_url( __FILE__ ) );

$implementation = MRM_MASTERCLASS_DIR . 'plugin/mrm-masterclass.php';

if ( ! file_exists( $implementation ) ) {
	add_action( 'admin_notices', function () use ( $implementation ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>MRM Masterclass:</strong> Missing implementation file at <code>' . esc_html( $implementation ) . '</code>.</p></div>';
	} );
	return;
}

require_once $implementation;

register_activation_hook( __FILE__, array( 'MRM_Masterclass_Plugin', 'activate' ) );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'mrm_masterclass_send_reminders' );
	wp_clear_scheduled_hook( 'mrm_masterclass_reconcile_events' );
} );

if ( function_exists( 'mrm_masterclass_plugin' ) ) {
	mrm_masterclass_plugin();
}
