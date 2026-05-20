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

require_once MRM_MASTERCLASS_DIR . 'plugin/mrm-masterclass.php';

register_activation_hook( __FILE__, array( 'MRM_Masterclass_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MRM_Masterclass_Plugin', 'deactivate' ) );

mrm_masterclass_plugin();
