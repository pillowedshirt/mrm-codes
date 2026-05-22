<?php
/**
 * Plugin Name: MRM Masterclass
 * Description: Standalone masterclass calendar, registration, Stripe checkout, Google Calendar, reminder, and cancellation system for Low Brass Lessons.
 * Version: 1.0.0
 * Author: Matt Rose
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Do not load the root Composer autoloader from public_html/vendor/autoload.php.
 * The Masterclass plugin should not depend on root Composer because it can create
 * runtime conflicts with other plugins or host-level packages.
 *
 * If AWS SDK loading is needed later, use a plugin-local vendor path or reuse
 * an already-loaded AWS SDK class safely with class_exists().
 */


if ( ! defined( 'MRM_MASTERCLASS_FILE' ) ) {
	define( 'MRM_MASTERCLASS_FILE', __FILE__ );
}

if ( ! defined( 'MRM_MASTERCLASS_DIR' ) ) {
	define( 'MRM_MASTERCLASS_DIR', plugin_dir_path( MRM_MASTERCLASS_FILE ) );
}

if ( ! defined( 'MRM_MASTERCLASS_URL' ) ) {
	define( 'MRM_MASTERCLASS_URL', plugin_dir_url( MRM_MASTERCLASS_FILE ) );
}

/*
 * Path safety:
 *
 * GitHub source layout:
 * mrm-masterclass/plugin/mrm-masterclass.php
 * mrm-masterclass/frontend/masterclass.html
 *
 * Live WordPress layout:
 * wp-content/plugins/mrm-masterclass/mrm-masterclass.php
 *
 * These constants avoid assuming that GitHub and live WordPress have the same
 * directory depth.
 */
if ( ! defined( 'MRM_MASTERCLASS_SOURCE_ROOT_DIR' ) ) {
	$maybe_github_root = trailingslashit( dirname( MRM_MASTERCLASS_DIR ) );

	if ( file_exists( $maybe_github_root . 'frontend/masterclass.html' ) ) {
		define( 'MRM_MASTERCLASS_SOURCE_ROOT_DIR', $maybe_github_root );
	} else {
		define( 'MRM_MASTERCLASS_SOURCE_ROOT_DIR', MRM_MASTERCLASS_DIR );
	}
}

if ( ! defined( 'MRM_MASTERCLASS_FRONTEND_DIR' ) ) {
	define( 'MRM_MASTERCLASS_FRONTEND_DIR', trailingslashit( MRM_MASTERCLASS_SOURCE_ROOT_DIR . 'frontend' ) );
}

if ( ! defined( 'MRM_MASTERCLASS_FRONTEND_URL' ) ) {
	/*
	 * In the live plugin-root deployment, frontend assets would be under:
	 * wp-content/plugins/mrm-masterclass/frontend/
	 *
	 * In GitHub, this constant is mostly informational because the HTML is
	 * copied to a WordPress page manually and calls REST endpoints directly.
	 */
	define( 'MRM_MASTERCLASS_FRONTEND_URL', trailingslashit( MRM_MASTERCLASS_URL . 'frontend' ) );
}

if ( ! function_exists( 'mrm_lowbrass_masterclass_emergency_file_log' ) ) {
	function mrm_lowbrass_masterclass_emergency_file_log( $message, $context = array() ) {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return;
		}

		$file = trailingslashit( WP_CONTENT_DIR ) . 'masterclass-emergency.log';

		$safe_context = array();

		if ( is_array( $context ) ) {
			foreach ( $context as $key => $value ) {
				$key = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $key ) : preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key );

				if ( preg_match( '/secret|token|password|private|authorization|cookie|nonce|key|tin|ssn|ein/i', $key ) ) {
					$safe_context[ $key ] = '[redacted]';
					continue;
				}

				if ( is_scalar( $value ) || null === $value ) {
					$value = (string) $value;

					if ( strlen( $value ) > 700 ) {
						$value = substr( $value, 0, 700 ) . '...[truncated]';
					}

					$safe_context[ $key ] = $value;
				} elseif ( is_array( $value ) ) {
					$safe_context[ $key ] = '[array:' . count( $value ) . ']';
				} elseif ( is_object( $value ) ) {
					$safe_context[ $key ] = '[object:' . get_class( $value ) . ']';
				} else {
					$safe_context[ $key ] = '[non-scalar]';
				}
			}
		}

		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] ' . (string) $message;

		if ( ! empty( $safe_context ) ) {
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $safe_context ) : json_encode( $safe_context );

			if ( false !== $encoded ) {
				$line .= ' | ' . $encoded;
			}
		}

		$line .= PHP_EOL;

		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}
}

mrm_lowbrass_masterclass_emergency_file_log(
	'Masterclass PHP file loaded before class instantiation.',
	array(
		'plugin_file' => defined( 'MRM_MASTERCLASS_FILE' ) ? MRM_MASTERCLASS_FILE : __FILE__,
		'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
		'is_admin' => is_admin() ? 1 : 0,
	)
);

if ( ! class_exists( 'LowBrass_MRM_Masterclass_Plugin', false ) ) {

class LowBrass_MRM_Masterclass_Plugin {
	protected $mrm_secret_diagnostics = array();

	const DB_VERSION = '1.2.4';
	const REST_NAMESPACE = 'mrm-masterclass/v1';
	const DEFAULT_PRICE_CENTS = 2000;

	public function __construct() {
	/*
	 * Critical safety rule:
	 * Never register a shutdown/error handler unless the callback method exists.
	 * A missing callback here can fatal before this plugin can write any debug log.
	 */
	if ( method_exists( $this, 'mrm_mc_shutdown_fatal_error_logger' ) ) {
		register_shutdown_function( array( $this, 'mrm_mc_shutdown_fatal_error_logger' ) );
	}

	if ( method_exists( $this, 'mrm_mc_runtime_error_logger' ) ) {
		set_error_handler( array( $this, 'mrm_mc_runtime_error_logger' ) );
	}

	$this->mrm_mc_debug_log(
		'Masterclass plugin constructor started safely.',
		array(
			'php_version'      => PHP_VERSION,
			'wp_version'       => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '',
			'is_admin'         => is_admin() ? 1 : 0,
			'request_uri'      => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			'plugin_file'      => defined( 'MRM_MASTERCLASS_FILE' ) ? MRM_MASTERCLASS_FILE : '',
			'plugin_dir'       => defined( 'MRM_MASTERCLASS_DIR' ) ? MRM_MASTERCLASS_DIR : '',
			'db_version_code'  => self::DB_VERSION,
			'db_version_saved' => get_option( 'mrm_masterclass_db_version', '' ),
		)
	);

	$this->mrm_mc_add_action_if_method_exists( 'init', 'runtime_upgrade' );
	$this->mrm_mc_add_action_if_method_exists( 'rest_api_init', 'register_rest_routes' );
	$this->mrm_mc_add_action_if_method_exists( 'admin_menu', 'register_admin_menu' );
	$this->mrm_mc_add_action_if_method_exists( 'admin_init', 'mrm_mc_admin_boot_debug' );
	$this->mrm_mc_add_action_if_method_exists( 'admin_notices', 'render_activation_diagnostic_notice' );
	$this->mrm_mc_add_action_if_method_exists( 'admin_init', 'mrm_mc_remove_stale_admin_visibility_css_hooks', -999999, 0 );
	$this->mrm_mc_add_action_if_method_exists( 'admin_head', 'mrm_mc_remove_stale_admin_visibility_css_hooks', -999999, 0 );
	$this->mrm_mc_add_action_if_method_exists( 'admin_head', 'mrm_mc_admin_visibility_css', 20, 0 );
	$this->mrm_mc_add_action_if_method_exists( 'init', 'mrm_mc_log_init_checkpoint', 0, 0 );
	$this->mrm_mc_add_action_if_method_exists( 'wp_loaded', 'mrm_mc_log_wp_loaded_checkpoint', 999, 0 );
	$this->mrm_mc_add_action_if_method_exists( 'template_redirect', 'mrm_mc_log_template_redirect_checkpoint', 0, 0 );
	$this->mrm_mc_add_action_if_method_exists( 'shutdown', 'mrm_mc_log_shutdown_action_checkpoint', 999, 0 );

	if ( method_exists( $this, 'mrm_mc_render_critical_error_notice' ) ) {
		$this->mrm_mc_add_action_if_method_exists( 'admin_notices', 'mrm_mc_render_critical_error_notice' );
		$this->mrm_mc_add_action_if_method_exists( 'admin_init', 'mrm_mc_maybe_clear_stored_critical_error', 1, 0 );
	}

	if ( method_exists( $this, 'add_cron_schedule' ) ) {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
	} else {
		$this->mrm_mc_debug_log(
			'Skipped missing cron_schedules callback.',
			array(
				'method' => 'add_cron_schedule',
			)
		);
	}

	$this->mrm_mc_add_action_if_method_exists( 'mrm_masterclass_send_reminders', 'send_reminders' );
	$this->mrm_mc_add_action_if_method_exists( 'mrm_masterclass_reconcile_events', 'reconcile_events' );

	$actions = array(
		'mrm_masterclass_save_settings'            => 'handle_save_settings',
		'mrm_masterclass_save_presenter'           => 'handle_save_presenter',
		'mrm_masterclass_delete_presenter'         => 'handle_delete_presenter',
		'mrm_masterclass_save_event'               => 'handle_save_event',
		'mrm_masterclass_cancel_event'             => 'handle_cancel_event',
		'mrm_masterclass_mark_payouts_paid'        => 'handle_mark_payouts_paid',
		'mrm_masterclass_save_tax_profile'         => 'handle_save_tax_profile',
		'mrm_masterclass_create_presenter_page'    => 'handle_create_presenter_page',
		'mrm_masterclass_resend_confirmation'      => 'handle_resend_confirmation',
		'mrm_masterclass_resend_reminder'          => 'handle_resend_reminder',
		'mrm_masterclass_emergency_cancel_confirm' => 'handle_emergency_cancel_confirm',
		'mrm_masterclass_emergency_cancel_execute' => 'handle_emergency_cancel_execute',
	);

	foreach ( $actions as $action => $method ) {
		if ( method_exists( $this, $method ) ) {
			add_action( 'admin_post_' . $action, array( $this, $method ) );
		} else {
			$this->mrm_mc_debug_log(
				'Skipped missing admin_post callback.',
				array(
					'action' => $action,
					'method' => $method,
				)
			);
		}
	}

	$this->mrm_mc_add_filter_if_method_exists( 'query_vars', 'register_masterclass_gate_query_vars' );
	$this->mrm_mc_add_action_if_method_exists( 'template_redirect', 'maybe_render_masterclass_gate_page' );

	$this->mrm_mc_debug_log( 'Masterclass plugin initialized safely in REST-only frontend mode. No shortcode rendering is registered.' );
}



	private function mrm_mc_add_action_if_method_exists( $hook, $method, $priority = 10, $accepted_args = 1 ) {
		$hook   = (string) $hook;
		$method = (string) $method;

		if ( '' === $hook || '' === $method ) {
			$this->mrm_mc_debug_log(
				'Skipped invalid add_action request.',
				array(
					'hook'   => $hook,
					'method' => $method,
				)
			);
			return false;
		}

		if ( ! method_exists( $this, $method ) ) {
			$this->mrm_mc_debug_log(
				'Skipped missing add_action callback.',
				array(
					'hook'   => $hook,
					'method' => $method,
				)
			);
			return false;
		}

		add_action( $hook, array( $this, $method ), absint( $priority ), absint( $accepted_args ) );

		return true;
	}

	private function mrm_mc_add_filter_if_method_exists( $hook, $method, $priority = 10, $accepted_args = 1 ) {
		$hook   = (string) $hook;
		$method = (string) $method;

		if ( '' === $hook || '' === $method ) {
			$this->mrm_mc_debug_log(
				'Skipped invalid add_filter request.',
				array(
					'hook'   => $hook,
					'method' => $method,
				)
			);
			return false;
		}

		if ( ! method_exists( $this, $method ) ) {
			$this->mrm_mc_debug_log(
				'Skipped missing add_filter callback.',
				array(
					'hook'   => $hook,
					'method' => $method,
				)
			);
			return false;
		}

		add_filter( $hook, array( $this, $method ), absint( $priority ), absint( $accepted_args ) );

		return true;
	}

	public static function activate() {
		delete_option( 'mrm_masterclass_activation_error' );

		try {
			self::install_tables();
			set_transient( 'mrm_masterclass_activation_notice', 1, 60 );
		} catch ( \Throwable $e ) {
			update_option(
				'mrm_masterclass_activation_error',
				array(
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
					'time'    => gmdate( 'Y-m-d H:i:s' ),
				),
				false
			);

			self::safe_debug_log(
				'Activation database installer failed safely.',
				array(
					'error' => $e->getMessage(),
					'file'  => $e->getFile(),
					'line'  => $e->getLine(),
				)
			);
		}

		self::safe_debug_log( 'Masterclass plugin activation routine completed.' );

		$schedules = wp_get_schedules();

		if ( isset( $schedules['mrm_masterclass_15min'] ) && ! wp_next_scheduled( 'mrm_masterclass_send_reminders' ) ) {
			wp_schedule_event( time() + 120, 'mrm_masterclass_15min', 'mrm_masterclass_send_reminders' );
		}

		if ( isset( $schedules['hourly'] ) && ! wp_next_scheduled( 'mrm_masterclass_reconcile_events' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'mrm_masterclass_reconcile_events' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'mrm_masterclass_send_reminders' );
		wp_clear_scheduled_hook( 'mrm_masterclass_reconcile_events' );
	}

	public function runtime_upgrade() {
		$saved_version = get_option( 'mrm_masterclass_db_version', '' );

		if ( $this->mrm_mc_should_diagnose_current_request() ) {
			$this->mrm_mc_debug_log(
				'Runtime upgrade checkpoint reached.',
				array(
					'saved_version'    => $saved_version,
					'code_version'     => self::DB_VERSION,
					'is_admin'         => is_admin() ? 1 : 0,
					'is_cron'          => ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ? 1 : 0,
					'is_rest'          => $this->mrm_mc_is_rest_request() ? 1 : 0,
					'has_fail_lock'    => get_transient( 'mrm_masterclass_runtime_upgrade_failed' ) ? 1 : 0,
					'has_run_lock'     => get_transient( 'mrm_masterclass_runtime_upgrade_running' ) ? 1 : 0,
					'request_uri'      => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
				)
			);
		}

		if ( $saved_version === self::DB_VERSION ) {
			if ( $this->mrm_mc_should_diagnose_current_request() ) {
				$this->mrm_mc_debug_log( 'Runtime upgrade skipped because saved DB version already matches code version.' );
			}
			return;
		}

		if ( get_transient( 'mrm_masterclass_runtime_upgrade_failed' ) ) {
			if ( $this->mrm_mc_should_diagnose_current_request() ) {
				$this->mrm_mc_debug_log( 'Runtime upgrade skipped because failure lock transient is active.' );
			}
			return;
		}

		if ( get_transient( 'mrm_masterclass_runtime_upgrade_running' ) ) {
			if ( $this->mrm_mc_should_diagnose_current_request() ) {
				$this->mrm_mc_debug_log( 'Runtime upgrade skipped because another request is already running it.' );
			}
			return;
		}

		if ( ! is_admin() && ! wp_doing_cron() && ! $this->mrm_mc_is_rest_request() ) {
			if ( $this->mrm_mc_should_diagnose_current_request() ) {
				$this->mrm_mc_debug_log( 'Runtime upgrade skipped on frontend non-REST request.' );
			}
			return;
		}

		set_transient( 'mrm_masterclass_runtime_upgrade_running', 1, 2 * MINUTE_IN_SECONDS );

		try {
			$this->mrm_mc_debug_log(
				'Runtime database upgrade starting.',
				array(
					'from_version' => $saved_version,
					'to_version'   => self::DB_VERSION,
				)
			);

			self::install_tables();

			update_option( 'mrm_masterclass_db_version', self::DB_VERSION, false );
			delete_option( 'mrm_masterclass_activation_error' );
			delete_transient( 'mrm_masterclass_runtime_upgrade_failed' );
			delete_transient( 'mrm_masterclass_runtime_upgrade_running' );

			$this->mrm_mc_debug_log(
				'Runtime database upgrade completed.',
				array(
					'db_version_saved_after' => get_option( 'mrm_masterclass_db_version', '' ),
					'code_version'           => self::DB_VERSION,
				)
			);
		} catch ( \Throwable $e ) {
			delete_transient( 'mrm_masterclass_runtime_upgrade_running' );
			set_transient( 'mrm_masterclass_runtime_upgrade_failed', 1, 10 * MINUTE_IN_SECONDS );

			update_option(
				'mrm_masterclass_activation_error',
				array(
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
					'time'    => gmdate( 'Y-m-d H:i:s' ),
				),
				false
			);

			$this->mrm_mc_debug_log(
				'Runtime database upgrade failed safely.',
				array(
					'error' => $e->getMessage(),
					'file'  => $e->getFile(),
					'line'  => $e->getLine(),
				)
			);
		}
	}

private function mrm_mc_should_diagnose_current_request( $file = '' ) {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$wp_page     = isset( $GLOBALS['pagenow'] ) ? sanitize_text_field( (string) $GLOBALS['pagenow'] ) : '';

	$is_masterclass_uri = false !== stripos( $request_uri, '/masterclass' )
		|| false !== stripos( $request_uri, 'mrm-masterclass' )
		|| false !== stripos( $request_uri, 'mrm_masterclass' );

	$is_watched_admin_page = is_admin() && in_array(
		$wp_page,
		array(
			'admin.php',
			'admin-post.php',
			'plugins.php',
			'options-general.php',
			'edit.php',
			'post.php',
			'post-new.php',
		),
		true
	);

	$is_masterclass_rest = false !== stripos( $request_uri, '/wp-json/mrm-masterclass/' )
		|| false !== stripos( $request_uri, 'rest_route=/mrm-masterclass/' );

	$is_plugin_file = false;

	if ( '' !== (string) $file && defined( 'MRM_MASTERCLASS_DIR' ) ) {
		$normalized_file = function_exists( 'wp_normalize_path' )
			? wp_normalize_path( (string) $file )
			: str_replace( '\\', '/', (string) $file );

		$normalized_dir = function_exists( 'wp_normalize_path' )
			? wp_normalize_path( MRM_MASTERCLASS_DIR )
			: str_replace( '\\', '/', MRM_MASTERCLASS_DIR );

		$is_plugin_file = false !== strpos( $normalized_file, $normalized_dir );
	}

	$is_cron = function_exists( 'wp_doing_cron' ) ? wp_doing_cron() : ( defined( 'DOING_CRON' ) && DOING_CRON );

	return $is_masterclass_uri || $is_watched_admin_page || $is_masterclass_rest || $is_plugin_file || $is_cron;
}

private function mrm_mc_diagnostic_checkpoint( $phase ) {
	if ( ! $this->mrm_mc_should_diagnose_current_request() ) {
		return;
	}

	$this->mrm_mc_debug_log(
		'Masterclass diagnostic checkpoint: ' . sanitize_text_field( (string) $phase ),
		array(
			'phase'            => sanitize_text_field( (string) $phase ),
			'request_uri'      => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			'wp_page'          => isset( $GLOBALS['pagenow'] ) ? sanitize_text_field( (string) $GLOBALS['pagenow'] ) : '',
			'is_admin'         => is_admin() ? 1 : 0,
			'is_rest'          => $this->mrm_mc_is_rest_request() ? 1 : 0,
			'is_cron'          => ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ? 1 : 0,
			'current_filter'   => function_exists( 'current_filter' ) ? current_filter() : '',
			'db_version_code'  => self::DB_VERSION,
			'db_version_saved' => get_option( 'mrm_masterclass_db_version', '' ),
			'memory_usage'     => function_exists( 'memory_get_usage' ) ? memory_get_usage() : 0,
		)
	);
}

public function mrm_mc_log_init_checkpoint() {
	$this->mrm_mc_diagnostic_checkpoint( 'init' );
}

public function mrm_mc_log_wp_loaded_checkpoint() {
	$this->mrm_mc_diagnostic_checkpoint( 'wp_loaded' );
}

public function mrm_mc_log_template_redirect_checkpoint() {
	$this->mrm_mc_diagnostic_checkpoint( 'template_redirect' );
}

public function mrm_mc_log_shutdown_action_checkpoint() {
	$this->mrm_mc_diagnostic_checkpoint( 'wordpress_shutdown_action' );
}

public function mrm_mc_shutdown_fatal_error_logger() {
	$error = error_get_last();

	if ( ! is_array( $error ) || empty( $error['type'] ) ) {
		return;
	}

	$fatal_types = array(
		E_ERROR,
		E_PARSE,
		E_CORE_ERROR,
		E_COMPILE_ERROR,
		E_USER_ERROR,
		E_RECOVERABLE_ERROR,
	);

	if ( ! in_array( (int) $error['type'], $fatal_types, true ) ) {
		return;
	}

	$file        = isset( $error['file'] ) ? (string) $error['file'] : '';
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	/*
	 * Temporary broad fatal logging:
	 * While the site/admin are still returning critical errors, log every fatal
	 * that occurs while this plugin is active. This may catch a fatal caused by
	 * a theme or another plugin during the /masterclass/ request.
	 */
	$record = array(
		'type'             => (int) $error['type'],
		'message'          => isset( $error['message'] ) ? (string) $error['message'] : '',
		'file'             => $file,
		'line'             => isset( $error['line'] ) ? (int) $error['line'] : 0,
		'request_uri'      => $request_uri,
		'wp_page'          => isset( $GLOBALS['pagenow'] ) ? sanitize_text_field( (string) $GLOBALS['pagenow'] ) : '',
		'is_admin'         => is_admin() ? 1 : 0,
		'is_rest'          => $this->mrm_mc_is_rest_request() ? 1 : 0,
		'is_cron'          => ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ? 1 : 0,
		'is_diagnostic'    => $this->mrm_mc_should_diagnose_current_request( $file ) ? 1 : 0,
		'db_version_code'  => self::DB_VERSION,
		'db_version_saved' => get_option( 'mrm_masterclass_db_version', '' ),
		'timestamp'        => gmdate( 'c' ),
	);

	update_option( 'mrm_masterclass_last_critical_error', $record, false );

	$this->mrm_mc_debug_log(
		'CRITICAL PHP ERROR detected during request shutdown.',
		$record
	);

	if ( function_exists( 'mrm_lowbrass_masterclass_emergency_file_log' ) ) {
		mrm_lowbrass_masterclass_emergency_file_log(
			'CRITICAL PHP ERROR detected by emergency shutdown logger.',
			$record
		);
	}
}


	public function mrm_mc_runtime_error_logger( $errno, $errstr, $errfile, $errline ) {
	$watched_types = array(
		E_WARNING,
		E_USER_WARNING,
		E_RECOVERABLE_ERROR,
		E_DEPRECATED,
		E_USER_DEPRECATED,
		E_NOTICE,
		E_USER_NOTICE,
	);

	if ( ! in_array( (int) $errno, $watched_types, true ) ) {
		return false;
	}

	$errfile_string = (string) $errfile;

	/*
	 * Do not flood the Masterclass log with WordPress core dbDelta warnings.
	 * The fatal we are fixing is plugin-local. Core upgrade.php warnings are
	 * useful only when debugging schema formatting, not normal page loads.
	 */
	if ( false !== strpos( str_replace( '\\', '/', $errfile_string ), '/wp-admin/includes/upgrade.php' ) ) {
		return false;
	}

	if ( ! $this->mrm_mc_should_diagnose_current_request( $errfile_string ) ) {
		return false;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	$this->mrm_mc_debug_log(
		'PHP runtime warning/notice detected during watched Masterclass-related request.',
		array(
			'type'        => (int) $errno,
			'message'     => (string) $errstr,
			'file'        => $errfile_string,
			'line'        => (int) $errline,
			'request_uri' => $request_uri,
			'wp_page'     => isset( $GLOBALS['pagenow'] ) ? sanitize_text_field( (string) $GLOBALS['pagenow'] ) : '',
			'is_admin'    => is_admin() ? 1 : 0,
			'is_rest'     => $this->mrm_mc_is_rest_request() ? 1 : 0,
		)
	);

	return false;
}


public function mrm_mc_maybe_clear_stored_critical_error() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$action = isset( $_GET['mrm_masterclass_action'] )
		? sanitize_key( wp_unslash( $_GET['mrm_masterclass_action'] ) )
		: '';

	if ( 'clear_last_critical_error' !== $action ) {
		return;
	}

	check_admin_referer( 'mrm_masterclass_clear_last_critical_error' );

	delete_option( 'mrm_masterclass_last_critical_error' );

	$redirect = remove_query_arg(
		array(
			'mrm_masterclass_action',
			'_wpnonce',
		)
	);

	wp_safe_redirect( $redirect );
	exit;
}

public function mrm_mc_render_critical_error_notice() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$error = get_option( 'mrm_masterclass_last_critical_error', array() );

	if ( ! is_array( $error ) || empty( $error['message'] ) ) {
		return;
	}

	$timestamp = isset( $error['timestamp'] ) ? sanitize_text_field( (string) $error['timestamp'] ) : '';
	$clear_url = wp_nonce_url(
		add_query_arg(
			array(
				'mrm_masterclass_action' => 'clear_last_critical_error',
			)
		),
		'mrm_masterclass_clear_last_critical_error'
	);

	echo '<div class="notice notice-warning">';
	echo '<p><strong>MRM Masterclass has a stored critical PHP error notice.</strong></p>';
	echo '<p>This notice may be from an earlier failed request. If the site/admin now loads and the newest <code>wp-content/masterclass-debug.log</code> entries do not show a new <code>CRITICAL PHP ERROR</code>, clear this stored notice.</p>';

	echo '<p><strong>Stored message:</strong> ' . esc_html( $error['message'] ) . '</p>';

	if ( ! empty( $error['file'] ) || ! empty( $error['line'] ) ) {
		echo '<p><strong>Stored location:</strong> <code>' . esc_html( ( $error['file'] ?? '' ) . ':' . ( $error['line'] ?? '' ) ) . '</code></p>';
	}

	if ( ! empty( $error['request_uri'] ) ) {
		echo '<p><strong>Stored request:</strong> <code>' . esc_html( $error['request_uri'] ) . '</code></p>';
	}

	if ( '' !== $timestamp ) {
		echo '<p><strong>Stored time:</strong> ' . esc_html( $timestamp ) . '</p>';
	}

	echo '<p><a class="button button-secondary" href="' . esc_url( $clear_url ) . '">Clear stored Masterclass critical error notice</a></p>';
	echo '</div>';
}

	private function t( $table_name ) {
		global $wpdb;

		$table_name = sanitize_key( (string) $table_name );

		if ( '' === $table_name ) {
			return $wpdb->prefix . 'mrm_masterclass_invalid_table';
		}

		/*
		 * This helper is intentionally tiny and local to the Masterclass plugin.
		 * It restores the table-name helper expected throughout the plugin after
		 * the class was renamed to LowBrass_MRM_Masterclass_Plugin.
		 */
		return $wpdb->prefix . $table_name;
	}

	private function mrm_mc_required_tables() {
		return array(
			$this->t( 'mrm_masterclass_presenters' ),
			$this->t( 'mrm_masterclass_events' ),
			$this->t( 'mrm_masterclass_registrations' ),
			$this->t( 'mrm_masterclass_refunds' ),
			$this->t( 'mrm_masterclass_email_log' ),
			$this->t( 'mrm_masterclass_presenter_tax_profiles' ),
			$this->t( 'mrm_masterclass_payment_ledger' ),
		);
	}

	private function mrm_mc_required_tables_ready() {
		foreach ( $this->mrm_mc_required_tables() as $table ) {
			if ( ! $this->mrm_mc_table_exists( $table ) ) {
				return false;
			}
		}

		return true;
	}

	private function mrm_mc_missing_tables() {
		$missing = array();

		foreach ( $this->mrm_mc_required_tables() as $table ) {
			if ( ! $this->mrm_mc_table_exists( $table ) ) {
				$missing[] = $table;
			}
		}

		return $missing;
	}

	private function mrm_mc_is_rest_request() {
		if ( function_exists( 'wp_is_serving_rest_request' ) ) {
			return wp_is_serving_rest_request();
		}

		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	private function mrm_mc_rest_tables_error() {
		return new WP_Error(
			'mrm_masterclass_tables_missing',
			'Masterclass database tables are missing or incomplete. Reactivate the plugin or visit the Masterclass admin page to trigger a safe database upgrade.',
			array(
				'status'  => 503,
				'missing' => $this->mrm_mc_missing_tables(),
			)
		);
	}
	public static function install_tables() {
	global $wpdb;

	if ( ! defined( 'ABSPATH' ) ) {
		throw new \RuntimeException( 'ABSPATH is not defined. WordPress did not load correctly.' );
	}

	$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';

	if ( ! file_exists( $upgrade_file ) ) {
		throw new \RuntimeException( 'WordPress upgrade.php file was not found at: ' . $upgrade_file );
	}

	require_once $upgrade_file;

	if ( ! function_exists( 'dbDelta' ) ) {
		throw new \RuntimeException( 'dbDelta() is unavailable after loading wp-admin/includes/upgrade.php.' );
	}

	$c = $wpdb->get_charset_collate();
	$p = $wpdb->prefix;

	$presenters_table   = $p . 'mrm_masterclass_presenters';
	$events_table       = $p . 'mrm_masterclass_events';
	$registrations_table = $p . 'mrm_masterclass_registrations';
	$refunds_table      = $p . 'mrm_masterclass_refunds';
	$email_log_table    = $p . 'mrm_masterclass_email_log';
	$unmute_table       = $p . 'mrm_masterclass_unmute_requests';
	$tax_table          = $p . 'mrm_masterclass_presenter_tax_profiles';
	$ledger_table       = $p . 'mrm_masterclass_payment_ledger';

	dbDelta(
		"CREATE TABLE {$presenters_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			email VARCHAR(191) NOT NULL,
			bio TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email)
		) {$c};"
	);

	dbDelta(
		"CREATE TABLE {$events_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(191) NOT NULL,
			description LONGTEXT NULL,
			presenter_id BIGINT UNSIGNED NULL,
			presenter_email VARCHAR(191) NULL,
			start_time DATETIME NOT NULL,
			end_time DATETIME NOT NULL,
			timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
			price_cents INT NOT NULL DEFAULT 2000,
			capacity INT NOT NULL DEFAULT 100,
			status VARCHAR(32) NOT NULL DEFAULT 'scheduled',
			registration_open TINYINT(1) NOT NULL DEFAULT 1,
			google_event_id VARCHAR(191) NULL,
			google_meet_url TEXT NULL,
			cancellation_reason TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY start_time (start_time)
		) {$c};"
	);

	dbDelta(
		"CREATE TABLE {$registrations_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			first_name VARCHAR(191) NOT NULL,
			last_name VARCHAR(191) NOT NULL,
			email VARCHAR(191) NOT NULL,
			email_hash VARCHAR(64) NOT NULL,
			stripe_payment_intent_id VARCHAR(191) NULL,
			amount_cents INT NOT NULL,
			currency VARCHAR(10) NOT NULL DEFAULT 'usd',
			payment_status VARCHAR(32) NOT NULL DEFAULT 'pending',
			google_attendee_added TINYINT(1) NOT NULL DEFAULT 0,
			terms_version VARCHAR(32) NOT NULL DEFAULT 'v1',
			terms_accepted TINYINT(1) NOT NULL DEFAULT 0,
			reminder_24_sent TINYINT(1) NOT NULL DEFAULT 0,
			reminder_1_sent TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY email (email)
		) {$c};"
	);

	dbDelta(
		"CREATE TABLE {$refunds_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			registration_id BIGINT UNSIGNED NOT NULL,
			event_id BIGINT UNSIGNED NOT NULL,
			stripe_refund_id VARCHAR(191) NULL,
			amount_cents INT NOT NULL,
			status VARCHAR(32) NOT NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id)
		) {$c};"
	);

	dbDelta(
		"CREATE TABLE {$email_log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NULL,
			registration_id BIGINT UNSIGNED NULL,
			recipient_email VARCHAR(191) NOT NULL,
			email_type VARCHAR(64) NOT NULL,
			subject VARCHAR(255) NOT NULL,
			status VARCHAR(32) NOT NULL,
			error_message TEXT NULL,
			sent_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id)
		) {$c};"
	);

	dbDelta(
		"CREATE TABLE {$unmute_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			registration_id BIGINT UNSIGNED NULL,
			participant_name VARCHAR(191) NOT NULL,
			participant_email VARCHAR(191) NOT NULL,
			request_note TEXT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			presenter_response TEXT NULL,
			responded_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id)
		) {$c};"
	);

	dbDelta(
		"CREATE TABLE {$tax_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			presenter_id BIGINT UNSIGNED NOT NULL,
			legal_name VARCHAR(191) NULL,
			business_name VARCHAR(191) NULL,
			email VARCHAR(191) NULL,
			tin_last4 VARCHAR(4) NULL,
			tin_type VARCHAR(20) NULL,
			w9_received TINYINT(1) NOT NULL DEFAULT 0,
			w9_received_date DATE NULL,
			address_line1 VARCHAR(191) NULL,
			address_line2 VARCHAR(191) NULL,
			city VARCHAR(100) NULL,
			state VARCHAR(50) NULL,
			zip VARCHAR(20) NULL,
			is_1099_eligible TINYINT(1) NOT NULL DEFAULT 1,
			exclude_from_1099 TINYINT(1) NOT NULL DEFAULT 0,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY presenter_id (presenter_id)
		) {$c};"
	);

	dbDelta(
		"CREATE TABLE {$ledger_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NULL,
			registration_id BIGINT UNSIGNED NULL,
			presenter_id BIGINT UNSIGNED NULL,
			ledger_type VARCHAR(50) NOT NULL,
			stripe_payment_intent_id VARCHAR(191) NULL,
			stripe_refund_id VARCHAR(191) NULL,
			gross_cents INT NOT NULL DEFAULT 0,
			stripe_fee_cents INT NOT NULL DEFAULT 0,
			net_cents INT NOT NULL DEFAULT 0,
			presenter_share_cents INT NOT NULL DEFAULT 0,
			platform_share_cents INT NOT NULL DEFAULT 0,
			status VARCHAR(50) NOT NULL DEFAULT 'recorded',
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY registration_id (registration_id),
			KEY presenter_id (presenter_id),
			KEY status (status)
		) {$c};"
	);

	$presenter_columns = $wpdb->get_col( "DESC {$presenters_table}", 0 );
	if ( ! is_array( $presenter_columns ) ) {
		$presenter_columns = array();
	}

	$presenter_adds = array(
		'city'                        => "ALTER TABLE {$presenters_table} ADD city VARCHAR(100) NULL",
		'state'                       => "ALTER TABLE {$presenters_table} ADD state VARCHAR(50) NULL",
		'address'                     => "ALTER TABLE {$presenters_table} ADD address VARCHAR(191) NULL",
		'zip_code'                    => "ALTER TABLE {$presenters_table} ADD zip_code VARCHAR(20) NULL",
		'timezone'                    => "ALTER TABLE {$presenters_table} ADD timezone VARCHAR(64) NOT NULL DEFAULT 'America/Phoenix'",
		'stripe_connected_account_id' => "ALTER TABLE {$presenters_table} ADD stripe_connected_account_id VARCHAR(191) NULL",
		'hire_date'                   => "ALTER TABLE {$presenters_table} ADD hire_date DATE NULL",
		'profile_image_url'           => "ALTER TABLE {$presenters_table} ADD profile_image_url TEXT NULL",
		'short_description'           => "ALTER TABLE {$presenters_table} ADD short_description TEXT NULL",
		'long_description'            => "ALTER TABLE {$presenters_table} ADD long_description LONGTEXT NULL",
		'instruments'                 => "ALTER TABLE {$presenters_table} ADD instruments TEXT NULL",
		'presenter_page_id'           => "ALTER TABLE {$presenters_table} ADD presenter_page_id BIGINT UNSIGNED NULL",
	);

	foreach ( $presenter_adds as $column => $sql ) {
		if ( ! in_array( $column, $presenter_columns, true ) ) {
			$wpdb->query( $sql );
		}
	}

	$event_columns = $wpdb->get_col( "DESC {$events_table}", 0 );
	if ( ! is_array( $event_columns ) ) {
		$event_columns = array();
	}

	$event_adds = array(
		'calendar_id'       => "ALTER TABLE {$events_table} ADD calendar_id VARCHAR(191) NULL",
		'proctor_email'     => "ALTER TABLE {$events_table} ADD proctor_email VARCHAR(191) NULL",
		'cohost_note'       => "ALTER TABLE {$events_table} ADD cohost_note TEXT NULL",
		'google_last_error' => "ALTER TABLE {$events_table} ADD google_last_error TEXT NULL",
		'confirmation_note' => "ALTER TABLE {$events_table} ADD confirmation_note TEXT NULL",
	);

	foreach ( $event_adds as $column => $sql ) {
		if ( ! in_array( $column, $event_columns, true ) ) {
			$wpdb->query( $sql );
		}
	}

	$registration_columns = $wpdb->get_col( "DESC {$registrations_table}", 0 );
	if ( ! is_array( $registration_columns ) ) {
		$registration_columns = array();
	}

	$registration_adds = array(
		'promo_code'           => "ALTER TABLE {$registrations_table} ADD promo_code VARCHAR(100) NULL",
		'discount_cents'       => "ALTER TABLE {$registrations_table} ADD discount_cents INT NOT NULL DEFAULT 0",
		'gate_token_hash'      => "ALTER TABLE {$registrations_table} ADD gate_token_hash VARCHAR(64) NULL",
		'gate_url'             => "ALTER TABLE {$registrations_table} ADD gate_url TEXT NULL",
		'terms_snapshot'       => "ALTER TABLE {$registrations_table} ADD terms_snapshot LONGTEXT NULL",
		'confirmation_sent'    => "ALTER TABLE {$registrations_table} ADD confirmation_sent TINYINT(1) NOT NULL DEFAULT 0",
		'confirmation_sent_at' => "ALTER TABLE {$registrations_table} ADD confirmation_sent_at DATETIME NULL",
		'reminder_24_sent_at'  => "ALTER TABLE {$registrations_table} ADD reminder_24_sent_at DATETIME NULL",
		'reminder_1_sent_at'   => "ALTER TABLE {$registrations_table} ADD reminder_1_sent_at DATETIME NULL",
	);

	foreach ( $registration_adds as $column => $sql ) {
		if ( ! in_array( $column, $registration_columns, true ) ) {
			$wpdb->query( $sql );
		}
	}

	$ledger_columns = $wpdb->get_col( "DESC {$ledger_table}", 0 );
	if ( ! is_array( $ledger_columns ) ) {
		$ledger_columns = array();
	}

	$ledger_adds = array(
		'paid_out_at'        => "ALTER TABLE {$ledger_table} ADD paid_out_at DATETIME NULL",
		'payout_batch_id'    => "ALTER TABLE {$ledger_table} ADD payout_batch_id VARCHAR(64) NULL",
		'stripe_transfer_id' => "ALTER TABLE {$ledger_table} ADD stripe_transfer_id VARCHAR(191) NULL",
	);

	foreach ( $ledger_adds as $column => $sql ) {
		if ( ! in_array( $column, $ledger_columns, true ) ) {
			$wpdb->query( $sql );
		}
	}

	update_option( 'mrm_masterclass_db_version', self::DB_VERSION );
}
	public function add_cron_schedule($s){$s['mrm_masterclass_15min']=array('interval'=>900,'display'=>'Every 15 Minutes'); return $s;}
	private function now(){return gmdate('Y-m-d H:i:s');}


private function mrm_mc_debug_log( $message, $context = array() ) {
	if ( ! defined( 'WP_CONTENT_DIR' ) ) {
		return;
	}

	$file = trailingslashit( WP_CONTENT_DIR ) . 'masterclass-debug.log';

	$safe_context = array();

	if ( is_array( $context ) ) {
		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( preg_match( '/secret|token|password|private|key|tin|ssn|ein/i', $key ) ) {
				$safe_context[ $key ] = '[redacted]';
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$safe_context[ $key ] = $value;
			} else {
				$safe_context[ $key ] = '[non-scalar]';
			}
		}
	}

	$line = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] ' . sanitize_text_field( (string) $message );

	if ( $safe_context ) {
		$line .= ' | ' . wp_json_encode( $safe_context );
	}

	$line .= PHP_EOL;

	@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
}

public function mrm_mc_admin_boot_debug() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$page        = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
	$wp_page     = isset( $GLOBALS['pagenow'] ) ? sanitize_text_field( (string) $GLOBALS['pagenow'] ) : '';
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	$should_log = false;

	if ( 0 === strpos( $page, 'mrm-masterclass' ) ) {
		$should_log = true;
	}

	if ( in_array( $wp_page, array( 'admin.php', 'plugins.php', 'options-general.php', 'edit.php' ), true ) ) {
		$should_log = true;
	}

	if ( false !== strpos( $request_uri, 'mrm-masterclass' ) ) {
		$should_log = true;
	}

	if ( ! $should_log ) {
		return;
	}

	global $wpdb;

	$tables = array(
		'presenters'    => $this->t( 'mrm_masterclass_presenters' ),
		'events'        => $this->t( 'mrm_masterclass_events' ),
		'registrations' => $this->t( 'mrm_masterclass_registrations' ),
		'ledger'        => $this->t( 'mrm_masterclass_payment_ledger' ),
		'tax_profiles'  => $this->t( 'mrm_masterclass_presenter_tax_profiles' ),
	);

	$status = array(
		'wp_page'          => $wp_page,
		'page'             => $page,
		'request_uri'      => $request_uri,
		'db_version_saved' => get_option( 'mrm_masterclass_db_version', '' ),
		'db_version_code'  => self::DB_VERSION,
		'tables_ready'     => $this->mrm_mc_required_tables_ready() ? 1 : 0,
	);

	foreach ( $tables as $label => $table ) {
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$status[ $label . '_table' ] = $exists ? 'exists' : 'missing';
	}

	$this->mrm_mc_debug_log( 'Masterclass admin diagnostic checkpoint loaded.', $status );
}

private function mrm_mc_table_exists( $table ) {
	global $wpdb;
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}

private function mrm_mc_safe_count( $table, $where = '1=1' ) {
	global $wpdb;

	if ( ! $this->mrm_mc_table_exists( $table ) ) {
		return 0;
	}

	$where = preg_replace( '/[^a-zA-Z0-9_ =\'"<>.-]/', '', (string) $where );

	return absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ) );
}


private function cents_to_dollars( $cents ) {
	return '$' . number_format( absint( $cents ) / 100, 2 );
}

private function dollars_to_cents( $value ) {
	$value = preg_replace( '/[^0-9.]/', '', (string) $value );
	return (int) round( floatval( $value ) * 100 );
}

private function settings() {
	$defaults = array(
		'masterclass_calendar_id'       => '',
		'default_price_cents'           => self::DEFAULT_PRICE_CENTS,
		'default_capacity'              => 100,
		'default_timezone'              => 'America/Phoenix',
		'admin_notification_email'      => get_option( 'admin_email' ),
		'from_email'                    => 'no-reply@lowbrass-lessons.com',
		'terms_version'                 => 'v1',
		'presenter_default_percent'     => 70,
		'stripe_fee_estimate_percent'   => 2.9,
		'stripe_fee_estimate_fixed'     => 30,
		'cancellation_policy_text'      => '',
	);

	$saved = get_option( 'mrm_masterclass_settings', array() );

	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	return wp_parse_args( $saved, $defaults );
}

private function admin_card_open( $title, $description = '' ) {
	echo '<div style="background:#fff;border:1px solid #d7c7ad;border-radius:16px;padding:18px 20px;margin:16px 0;box-shadow:0 8px 22px rgba(31,41,51,.06);">';
	echo '<h2 style="margin-top:0;">' . esc_html( $title ) . '</h2>';

	if ( $description ) {
		echo '<p style="max-width:900px;color:#5f6b76;line-height:1.6;">' . wp_kses_post( $description ) . '</p>';
	}
}

private function admin_card_close() {
	echo '</div>';
}
	private function must_admin(){ if(!current_user_can('manage_options')) wp_die('Unauthorized'); }
	private function mrm_mc_aws_debug_log( $message, $context = array() ) {
		return;
	}

	private function mrm_mc_set_secret_diagnostic( $secret_id, $data ) {
		$secret_id = (string) $secret_id;
		if ( ! is_array( $data ) ) { $data = array(); }
		$safe = array();
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) { $safe[ $key ] = $value; } elseif ( is_bool( $value ) || is_numeric( $value ) || $value === null ) { $safe[ $key ] = $value; } else { $safe[ $key ] = sanitize_text_field( (string) $value ); }
		}
		$this->mrm_secret_diagnostics[ $secret_id ] = $safe;
	}
	private function mrm_mc_get_secret_diagnostic( $secret_id ) { $secret_id = (string) $secret_id; return isset( $this->mrm_secret_diagnostics[ $secret_id ] ) && is_array( $this->mrm_secret_diagnostics[ $secret_id ] ) ? $this->mrm_secret_diagnostics[ $secret_id ] : array(); }
	private function mrm_mc_secret_diagnostic_summary_html( $secret_id ) { $diag = $this->mrm_mc_get_secret_diagnostic( $secret_id ); if ( empty( $diag ) ) { return '<p class="description">No AWS diagnostic information is available yet.</p>'; } $status  = isset( $diag['status'] ) ? (string) $diag['status'] : 'unknown'; $region  = isset( $diag['region'] ) ? (string) $diag['region'] : ( defined( 'MRM_AWS_REGION' ) ? MRM_AWS_REGION : 'not defined' ); $message = isset( $diag['message'] ) ? (string) $diag['message'] : ''; $html  = '<p class="description"><strong>AWS diagnostic:</strong> <code>' . esc_html( $status ) . '</code></p>'; $html .= '<p class="description">Region used by site: <code>' . esc_html( $region ) . '</code></p>'; if ( $message !== '' ) { $html .= '<p class="description">Details: ' . esc_html( $message ) . '</p>'; } if ( ! empty( $diag['aws_error_code'] ) ) { $html .= '<p class="description">AWS error code: <code>' . esc_html( (string) $diag['aws_error_code'] ) . '</code></p>'; } if ( ! empty( $diag['top_level_keys'] ) && is_array( $diag['top_level_keys'] ) ) { $html .= '<p class="description">Top-level JSON keys found: <code>' . esc_html( implode( ', ', array_map( 'strval', $diag['top_level_keys'] ) ) ) . '</code></p>'; } return $html; }
	private function mrm_mc_get_secret_json( $secret_id, $cache_key = '' ) {
	$secret_id = (string) $secret_id;
	$cache_key = $cache_key ? (string) $cache_key : 'mrm_masterclass_secret_' . md5( $secret_id );

	$cached = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		$this->mrm_mc_set_secret_diagnostic(
			$secret_id,
			array(
				'status'         => 'cache_hit',
				'message'        => 'Secret loaded from WordPress transient cache.',
				'region'         => defined( 'MRM_AWS_REGION' ) ? MRM_AWS_REGION : 'not defined',
				'top_level_keys' => array_keys( $cached ),
			)
		);

		return $cached;
	}

	if ( ! defined( 'MRM_AWS_REGION' ) || ! defined( 'MRM_AWS_ACCESS_KEY_ID' ) || ! defined( 'MRM_AWS_SECRET_ACCESS_KEY' ) ) {
		$this->mrm_mc_set_secret_diagnostic(
			$secret_id,
			array(
				'status'         => 'missing_aws_constants',
				'message'        => 'One or more required AWS constants are missing in wp-config.php.',
				'region_defined' => defined( 'MRM_AWS_REGION' ) ? 'yes' : 'no',
				'key_defined'    => defined( 'MRM_AWS_ACCESS_KEY_ID' ) ? 'yes' : 'no',
				'secret_defined' => defined( 'MRM_AWS_SECRET_ACCESS_KEY' ) ? 'yes' : 'no',
			)
		);

		$this->mrm_mc_debug_log(
			'AWS secret load skipped: missing AWS constants.',
			array(
				'secret_id' => $secret_id,
			)
		);

		return null;
	}

	if ( ! class_exists( '\Aws\SecretsManager\SecretsManagerClient' ) ) {
		$this->mrm_mc_set_secret_diagnostic(
			$secret_id,
			array(
				'status'  => 'aws_sdk_missing',
				'message' => 'AWS SDK class Aws\SecretsManager\SecretsManagerClient is not available. The Masterclass plugin will not load root Composer automatically.',
				'region'  => MRM_AWS_REGION,
			)
		);

		$this->mrm_mc_debug_log(
			'AWS secret load skipped: AWS SDK missing.',
			array(
				'secret_id' => $secret_id,
			)
		);

		return null;
	}

	try {
		$client = new \Aws\SecretsManager\SecretsManagerClient(
			array(
				'version'     => 'latest',
				'region'      => MRM_AWS_REGION,
				'credentials' => array(
					'key'    => MRM_AWS_ACCESS_KEY_ID,
					'secret' => MRM_AWS_SECRET_ACCESS_KEY,
				),
			)
		);

		$result = $client->getSecretValue(
			array(
				'SecretId' => $secret_id,
			)
		);

		if ( empty( $result['SecretString'] ) ) {
			$this->mrm_mc_set_secret_diagnostic(
				$secret_id,
				array(
					'status'  => 'empty_secret_string',
					'message' => 'AWS returned the secret, but SecretString was empty.',
					'region'  => MRM_AWS_REGION,
				)
			);

			return null;
		}

		$decoded = json_decode( (string) $result['SecretString'], true );

		if ( ! is_array( $decoded ) ) {
			$this->mrm_mc_set_secret_diagnostic(
				$secret_id,
				array(
					'status'     => 'json_decode_failed',
					'message'    => 'SecretString was returned by AWS, but it was not valid JSON.',
					'region'     => MRM_AWS_REGION,
					'json_error' => json_last_error_msg(),
				)
			);

			return null;
		}

		$this->mrm_mc_set_secret_diagnostic(
			$secret_id,
			array(
				'status'         => 'loaded_from_aws',
				'message'        => 'Secret was successfully loaded and decoded from AWS Secrets Manager.',
				'region'         => MRM_AWS_REGION,
				'top_level_keys' => array_keys( $decoded ),
			)
		);

		set_transient( $cache_key, $decoded, 15 * MINUTE_IN_SECONDS );

		return $decoded;
	} catch ( \Throwable $e ) {
		$this->mrm_mc_set_secret_diagnostic(
			$secret_id,
			array(
				'status'  => 'php_exception',
				'message' => $e->getMessage(),
				'region'  => defined( 'MRM_AWS_REGION' ) ? MRM_AWS_REGION : 'not defined',
			)
		);

		$this->mrm_mc_debug_log(
			'AWS secret load failed safely.',
			array(
				'secret_id' => $secret_id,
				'error'     => $e->getMessage(),
			)
		);

		return null;
	}
}
private function mrm_mc_get_google_scheduler_secret_id() { return defined( 'MRM_SECRET_GOOGLE_SCHEDULER' ) ? MRM_SECRET_GOOGLE_SCHEDULER : 'lowbrass/google/scheduler'; }
	private function mrm_mc_get_stripe_secret_id() { return defined( 'MRM_SECRET_STRIPE_KEYS' ) ? MRM_SECRET_STRIPE_KEYS : 'lowbrass/stripe/keys'; }
	private function mrm_mc_get_tax_secret_id() { return defined( 'MRM_SECRET_MASTERCLASS_TAX_1099_PROFILES' ) ? MRM_SECRET_MASTERCLASS_TAX_1099_PROFILES : 'lowbrass/masterclass/tax/1099-profiles'; }
	private function mrm_mc_get_google_scheduler_secret_bundle() { return $this->mrm_mc_get_secret_json( $this->mrm_mc_get_google_scheduler_secret_id(), 'mrm_masterclass_google_scheduler_secret' ); }
	private function mrm_mc_get_google_service_account_json() { $b = $this->mrm_mc_get_google_scheduler_secret_bundle(); if ( ! is_array( $b ) || ! isset( $b['service_account_json'] ) ) { return ''; } if ( is_string( $b['service_account_json'] ) ) { return trim( $b['service_account_json'] ); } if ( is_array( $b['service_account_json'] ) ) { $encoded = wp_json_encode( $b['service_account_json'] ); return is_string( $encoded ) ? $encoded : ''; } return ''; }
	private function mrm_mc_get_stripe_secret_bundle() { return $this->mrm_mc_get_secret_json( $this->mrm_mc_get_stripe_secret_id(), 'mrm_masterclass_stripe_secret' ); }
	private function mrm_mc_get_stripe_secret_key() { $b = $this->mrm_mc_get_stripe_secret_bundle(); return is_array( $b ) ? trim( (string) ( $b['secret_key'] ?? '' ) ) : ''; }
	private function mrm_mc_get_stripe_publishable_key() { $b = $this->mrm_mc_get_stripe_secret_bundle(); return is_array( $b ) ? trim( (string) ( $b['publishable_key'] ?? '' ) ) : ''; }
	private function mrm_mc_parse_service_account_json( $json ) { $json = is_string( $json ) ? trim( $json ) : ''; if ( $json === '' ) { return null; } $data = json_decode( $json, true ); if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) { return null; } return array('client_email'=>(string)$data['client_email'],'private_key'=>(string)$data['private_key'],'token_uri'=>!empty($data['token_uri'])?(string)$data['token_uri']:'https://oauth2.googleapis.com/token'); }
	private function mrm_mc_base64url_encode( $data ) { return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); }
	private function mrm_mc_google_make_jwt( $client_email, $private_key, $scope, $token_url ) {
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error(
				'openssl_missing',
				'The PHP OpenSSL extension is not available, so the Google service account JWT cannot be signed.'
			);
		}

		$now = time();

		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		$claims = array(
			'iss'   => (string) $client_email,
			'scope' => (string) $scope,
			'aud'   => (string) $token_url,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);

		$segments = array(
			$this->mrm_mc_base64url_encode( wp_json_encode( $header ) ),
			$this->mrm_mc_base64url_encode( wp_json_encode( $claims ) ),
		);

		$signing_input = implode( '.', $segments );
		$signature     = '';
		$ok            = openssl_sign( $signing_input, $signature, $private_key, 'sha256' );

		if ( ! $ok ) {
			return new WP_Error( 'jwt_sign_failed', 'OpenSSL failed to sign the Google service account JWT.' );
		}

		$segments[] = $this->mrm_mc_base64url_encode( $signature );

		return implode( '.', $segments );
	}
	private function mrm_mc_google_get_access_token() {
	$service_account_json = $this->mrm_mc_get_google_service_account_json();

	if ( ! $service_account_json ) {
		return new WP_Error( 'google_not_configured', 'Google service account JSON is not configured.' );
	}

	$parsed = $this->mrm_mc_parse_service_account_json( $service_account_json );

	if ( ! is_array( $parsed ) ) {
		return new WP_Error( 'google_bad_service_account', 'Google service account JSON is invalid.' );
	}

	$scope     = 'https://www.googleapis.com/auth/calendar';
	$token_url = $parsed['token_uri'];

	$jwt = $this->mrm_mc_google_make_jwt(
		$parsed['client_email'],
		$parsed['private_key'],
		$scope,
		$token_url
	);

	if ( is_wp_error( $jwt ) ) {
		return $jwt;
	}

	$response = wp_remote_post(
		$token_url,
		array(
			'timeout' => 20,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
		return new WP_Error( 'google_token_failed', 'Google access token could not be retrieved.' );
	}

	return (string) $data['access_token'];
}


private function mrm_mc_google_calendar_request( $method, $url, $body = null ) {
	$token = $this->mrm_mc_google_get_access_token();

	if ( is_wp_error( $token ) ) {
		return $token;
	}

		$args = array(
		'method'  => strtoupper( $method ),
		'timeout' => 20,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
	);

	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}

	$response = wp_remote_request( $url, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$raw    = (string) wp_remote_retrieve_body( $response );
	$data   = json_decode( $raw, true );

	if ( ! is_array( $data ) ) {
		return new WP_Error(
			'google_bad_response',
			'Google Calendar returned an unreadable response.',
			array( 'status' => 502 )
		);
	}

	if ( $status < 200 || $status >= 300 ) {
		return new WP_Error(
			'google_api_error',
			$data['error']['message'] ?? 'Google Calendar request failed.',
			array(
				'status' => 502,
				'google' => $data,
			)
		);
	}

	return $data;
}

private function mrm_mc_extract_meet_url( $event ) {
	if ( ! is_array( $event ) ) {
		return '';
	}

	if ( ! empty( $event['hangoutLink'] ) ) {
		return esc_url_raw( $event['hangoutLink'] );
	}

	if ( empty( $event['conferenceData']['entryPoints'] ) || ! is_array( $event['conferenceData']['entryPoints'] ) ) {
		return '';
	}

	foreach ( $event['conferenceData']['entryPoints'] as $ep ) {
		if ( is_array( $ep ) && ( $ep['entryPointType'] ?? '' ) === 'video' && ! empty( $ep['uri'] ) ) {
			return esc_url_raw( $ep['uri'] );
		}
	}

	return '';
}

private function mrm_mc_google_insert_event( $event, $presenter_email ) {
	$settings    = $this->settings();
	$calendar_id = ! empty( $event->calendar_id ) ? $event->calendar_id : ( $settings['masterclass_calendar_id'] ?? '' );

	if ( ! $calendar_id ) {
		return new WP_Error( 'google_calendar_missing', 'Masterclass Google Calendar ID is missing.' );
	}

	$attendees = array();

	if ( is_email( $presenter_email ) ) {
		$attendees[] = array( 'email' => $presenter_email );
	}

	if ( ! empty( $event->proctor_email ) && is_email( $event->proctor_email ) ) {
		$attendees[] = array( 'email' => $event->proctor_email );
	}

	$body = array(
		'summary'     => $event->title,
		'description' => wp_strip_all_tags( $event->description ),
		'start'       => array(
			'dateTime' => gmdate( 'c', strtotime( $event->start_time ) ),
			'timeZone' => $event->timezone ?: 'America/Phoenix',
		),
		'end'         => array(
			'dateTime' => gmdate( 'c', strtotime( $event->end_time ) ),
			'timeZone' => $event->timezone ?: 'America/Phoenix',
		),
		'attendees'   => $attendees,
		'conferenceData' => array(
			'createRequest' => array(
				'requestId' => 'mrm-masterclass-' . absint( $event->id ) . '-' . time(),
				'conferenceSolutionKey' => array(
					'type' => 'hangoutsMeet',
				),
			),
		),
	);

	$url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $calendar_id ) . '/events?conferenceDataVersion=1&sendUpdates=all';

	$result = $this->mrm_mc_google_calendar_request( 'POST', $url, $body );

	if ( is_wp_error( $result ) ) {
		$this->mrm_mc_debug_log(
			'Google event creation failed.',
			array(
				'event_id' => absint( $event->id ),
				'error'    => $result->get_error_message(),
			)
		);

		return $result;
	}

	$meet_url = $this->mrm_mc_extract_meet_url( $result );

	return array(
		'id'       => $result['id'] ?? '',
		'meet_url' => $meet_url,
		'raw'      => $result,
	);
}


private function mrm_mc_google_patch_event_attendees( $event, $emails ) {
	$this->mrm_mc_debug_log(
		'Google attendee patch safely skipped during stabilization.',
		array(
			'event_id' => isset( $event->id ) ? absint( $event->id ) : 0,
		)
	);

	return false;
}

private function mrm_mc_google_cancel_event( $google_event_id ) {
	$this->mrm_mc_debug_log(
		'Google event cancellation safely skipped during stabilization.',
		array(
			'google_event_id' => $google_event_id ? '[present]' : '[missing]',
		)
	);

	return false;
}
private function mrm_mc_stripe_request( $method, $endpoint, $body = array() ) {
	$sk = $this->mrm_mc_get_stripe_secret_key();

	if ( ! $sk ) {
		return new WP_Error(
			'stripe_missing',
			'Stripe secret key is missing. Configure the Stripe AWS secret before processing payments.',
			array( 'status' => 503 )
		);
	}

	$args = array(
		'method'  => strtoupper( (string) $method ),
		'timeout' => 20,
		'headers' => array(
			'Authorization' => 'Bearer ' . $sk,
			'Content-Type'  => 'application/x-www-form-urlencoded',
		),
	);

	if ( is_array( $body ) && ! empty( $body ) ) {
		$args['body'] = http_build_query( $body );
	}

	$response = wp_remote_request( 'https://api.stripe.com/v1/' . ltrim( (string) $endpoint, '/' ), $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status_code = (int) wp_remote_retrieve_response_code( $response );
	$raw_body    = (string) wp_remote_retrieve_body( $response );
	$decoded     = json_decode( $raw_body, true );

	if ( ! is_array( $decoded ) ) {
		return new WP_Error(
			'stripe_bad_response',
			'Stripe returned an unreadable response.',
			array(
				'status'      => 502,
				'http_status' => $status_code,
			)
		);
	}

	if ( $status_code < 200 || $status_code >= 300 ) {
		$message = 'Stripe request failed.';

		if ( ! empty( $decoded['error']['message'] ) ) {
			$message = (string) $decoded['error']['message'];
		}

		return new WP_Error(
			'stripe_api_error',
			$message,
			array(
				'status'      => 502,
				'http_status' => $status_code,
				'stripe'      => $decoded,
			)
		);
	}

	return $decoded;
}
	private function mrm_mc_create_payment_intent($event,$email,$terms){ return $this->mrm_mc_stripe_request('POST','payment_intents',array('amount'=>$event->price_cents,'currency'=>'usd','automatic_payment_methods[enabled]'=>'true','metadata[mrm_masterclass_event_id]'=>$event->id,'metadata[mrm_masterclass_customer_email]'=>$email,'metadata[mrm_product_type]'=>'masterclass','metadata[source_flow]'=>'masterclass_registration','metadata[terms_version]'=>$terms['version'],'metadata[terms_accepted]'=>$terms['accepted']?'1':'0')); }
	private function mrm_mc_retrieve_payment_intent($id){ return $this->mrm_mc_stripe_request('GET','payment_intents/'.rawurlencode($id)); }
	private function mrm_mc_refund_payment_intent($pi,$amt){ return $this->mrm_mc_stripe_request('POST','refunds',array('payment_intent'=>$pi,'amount'=>$amt)); }

	public function send_reminders() {
		$this->mrm_mc_debug_log( 'Masterclass reminders cron ran. Reminder sending is not fully implemented yet.' );
	}

	public function reconcile_events() {
		$this->mrm_mc_debug_log( 'Masterclass reconcile cron ran. Google reconciliation is not fully implemented yet.' );
	}

	public function handle_save_settings() {
		$this->must_admin();
		check_admin_referer( 'mrm_masterclass_save_settings' );

		$settings = $this->settings();

		$settings['masterclass_calendar_id']     = sanitize_text_field( wp_unslash( $_POST['masterclass_calendar_id'] ?? '' ) );
		$settings['default_price_cents']         = max( 0, absint( $_POST['default_price_cents'] ?? self::DEFAULT_PRICE_CENTS ) );
		$settings['default_capacity']            = max( 1, absint( $_POST['default_capacity'] ?? 100 ) );
		$settings['default_timezone']            = sanitize_text_field( wp_unslash( $_POST['default_timezone'] ?? 'America/Phoenix' ) );
		$settings['presenter_default_percent']   = max( 0, min( 100, (float) ( $_POST['presenter_default_percent'] ?? 70 ) ) );
		$settings['admin_notification_email']    = sanitize_email( wp_unslash( $_POST['admin_notification_email'] ?? get_option( 'admin_email' ) ) );
		$settings['from_email']                  = sanitize_email( wp_unslash( $_POST['from_email'] ?? 'no-reply@lowbrass-lessons.com' ) );
		$settings['terms_version']               = sanitize_text_field( wp_unslash( $_POST['terms_version'] ?? 'v1' ) );
		$settings['stripe_fee_estimate_percent'] = (float) ( $_POST['stripe_fee_estimate_percent'] ?? 2.9 );
		$settings['stripe_fee_estimate_fixed']   = absint( $_POST['stripe_fee_estimate_fixed'] ?? 30 );

		update_option( 'mrm_masterclass_settings', $settings, false );

		wp_safe_redirect( admin_url( 'admin.php?page=mrm-masterclass-events&settings_updated=1' ) );
		exit;
	}

	private function mrm_mc_safe_admin_redirect( $page, $args = array() ) {
	$page = sanitize_key( (string) $page );

	if ( '' === $page ) {
		$page = 'mrm-masterclass-events';
	}

	$url = admin_url( 'admin.php?page=' . $page );

	if ( is_array( $args ) && ! empty( $args ) ) {
		$url = add_query_arg( array_map( 'rawurlencode', $args ), $url );
	}

	wp_safe_redirect( $url );
	exit;
}

private function mrm_mc_verify_admin_post_nonce_or_die( $action ) {
	$action = (string) $action;

	if ( '' === $action ) {
		wp_die(
			esc_html__( 'Invalid Masterclass admin action.', 'mrm-masterclass' ),
			esc_html__( 'Masterclass Error', 'mrm-masterclass' ),
			array( 'response' => 403 )
		);
	}

	check_admin_referer( $action );
}

public function handle_save_presenter() {
	$this->must_admin();
	$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_save_presenter' );

	$this->mrm_mc_debug_log(
		'Safe presenter save fallback reached. Full presenter save implementation is not installed yet.',
		array(
			'action'      => 'mrm_masterclass_save_presenter',
			'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
		)
	);

	$this->mrm_mc_safe_admin_redirect(
		'mrm-masterclass-presenters',
		array(
			'mrm_mc_notice' => 'presenter_save_fallback_reached',
		)
	);
}

public function handle_delete_presenter() {
	$this->must_admin();

	$presenter_id = absint( $_POST['presenter_id'] ?? $_GET['presenter_id'] ?? 0 );

	if ( $presenter_id > 0 ) {
		$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_delete_presenter_' . $presenter_id );
	}

	$this->mrm_mc_debug_log(
		'Safe presenter delete fallback reached. Destructive deletion is disabled in this stabilization patch.',
		array(
			'action'       => 'mrm_masterclass_delete_presenter',
			'presenter_id' => $presenter_id,
		)
	);

	$this->mrm_mc_safe_admin_redirect(
		'mrm-masterclass-presenters',
		array(
			'mrm_mc_notice' => 'presenter_delete_disabled_safe_patch',
		)
	);
}

public function handle_save_event() {
	$this->must_admin();
	$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_save_event' );

	$this->mrm_mc_debug_log(
		'Safe event save fallback reached. Full event save implementation is not installed yet.',
		array(
			'action'      => 'mrm_masterclass_save_event',
			'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
		)
	);

	$this->mrm_mc_safe_admin_redirect(
		'mrm-masterclass-events',
		array(
			'mrm_mc_notice' => 'event_save_fallback_reached',
		)
	);
}

public function handle_cancel_event() {
	$this->must_admin();

	$event_id = absint( $_POST['event_id'] ?? $_GET['event_id'] ?? 0 );

	if ( $event_id > 0 ) {
		$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_cancel_event_' . $event_id );
	}

	$this->mrm_mc_debug_log(
		'Safe event cancel fallback reached. Cancellation is disabled in this stabilization patch.',
		array(
			'action'   => 'mrm_masterclass_cancel_event',
			'event_id' => $event_id,
		)
	);

	$this->mrm_mc_safe_admin_redirect(
		'mrm-masterclass-events',
		array(
			'mrm_mc_notice' => 'event_cancel_disabled_safe_patch',
		)
	);
}

public function handle_mark_payouts_paid() {
	$this->must_admin();
	$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_mark_payouts_paid' );

	$this->mrm_mc_debug_log(
		'Safe mark-payouts-paid fallback reached. Payout mutation is disabled in this stabilization patch.',
		array(
			'action' => 'mrm_masterclass_mark_payouts_paid',
		)
	);

	$this->mrm_mc_safe_admin_redirect(
		'mrm-masterclass-payouts',
		array(
			'mrm_mc_notice' => 'payout_mutation_disabled_safe_patch',
		)
	);
}

public function handle_save_tax_profile() {
	$this->must_admin();
	$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_save_tax_profile' );

	$this->mrm_mc_debug_log(
		'Safe tax profile save fallback reached. Full tax profile save implementation is not installed yet.',
		array(
			'action' => 'mrm_masterclass_save_tax_profile',
		)
	);

	$this->mrm_mc_safe_admin_redirect(
		'mrm-masterclass-tax-profiles',
		array(
			'mrm_mc_notice' => 'tax_profile_save_fallback_reached',
		)
	);
}

public function handle_create_presenter_page() {
	$this->must_admin();

	$presenter_id = absint( $_POST['presenter_id'] ?? $_GET['presenter_id'] ?? 0 );

	if ( $presenter_id > 0 ) {
		$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_create_presenter_page_' . $presenter_id );
	}

	$this->mrm_mc_debug_log(
		'Safe presenter page generation fallback reached. Full page generation is not installed yet.',
		array(
			'action'       => 'mrm_masterclass_create_presenter_page',
			'presenter_id' => $presenter_id,
		)
	);

	$this->mrm_mc_safe_admin_redirect(
		'mrm-masterclass-presenters',
		array(
			'mrm_mc_notice' => 'presenter_page_generation_fallback_reached',
		)
	);
}

public function handle_resend_confirmation() {
	$this->must_admin();

	$registration_id = absint( $_POST['registration_id'] ?? $_GET['registration_id'] ?? 0 );

	if ( $registration_id > 0 ) {
		$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_resend_confirmation_' . $registration_id );
	}

	$this->mrm_mc_debug_log(
		'Safe resend confirmation fallback reached. Resend is disabled in this stabilization patch.',
		array(
			'action'          => 'mrm_masterclass_resend_confirmation',
			'registration_id' => $registration_id,
		)
	);

	$this->mrm_mc_safe_admin_redirect(
		'mrm-masterclass-registrations-payments',
		array(
			'mrm_mc_notice' => 'resend_confirmation_disabled_safe_patch',
		)
	);
}

public function handle_resend_reminder() {
	$this->must_admin();

	$registration_id = absint( $_POST['registration_id'] ?? $_GET['registration_id'] ?? 0 );

	if ( $registration_id > 0 ) {
		$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_resend_reminder_' . $registration_id );
	}

	$this->mrm_mc_debug_log(
		'Safe resend reminder fallback reached. Resend is disabled in this stabilization patch.',
		array(
			'action'          => 'mrm_masterclass_resend_reminder',
			'registration_id' => $registration_id,
		)
	);

	$this->mrm_mc_safe_admin_redirect(
		'mrm-masterclass-registrations-payments',
		array(
			'mrm_mc_notice' => 'resend_reminder_disabled_safe_patch',
		)
	);
}

public function handle_emergency_cancel_confirm() {
	$this->must_admin();

	$event_id = absint( $_POST['event_id'] ?? $_GET['event_id'] ?? 0 );

	if ( $event_id > 0 ) {
		$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_emergency_cancel_confirm_' . $event_id );
	}

	$this->mrm_mc_debug_log(
		'Safe emergency cancel confirmation fallback reached. Emergency cancellation is disabled in this stabilization patch.',
		array(
			'action'   => 'mrm_masterclass_emergency_cancel_confirm',
			'event_id' => $event_id,
		)
	);

	$this->mrm_mc_safe_admin_redirect(
		'mrm-masterclass-events',
		array(
			'mrm_mc_notice' => 'emergency_cancel_disabled_safe_patch',
		)
	);
}

public function handle_emergency_cancel_execute() {
	$this->must_admin();

	$event_id = absint( $_POST['event_id'] ?? $_GET['event_id'] ?? 0 );

	if ( $event_id > 0 ) {
		$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_emergency_cancel_execute_' . $event_id );
	}

	$this->mrm_mc_debug_log(
		'Safe emergency cancel execute fallback reached. Emergency cancellation is disabled in this stabilization patch.',
		array(
			'action'   => 'mrm_masterclass_emergency_cancel_execute',
			'event_id' => $event_id,
		)
	);

	$this->mrm_mc_safe_admin_redirect(
		'mrm-masterclass-events',
		array(
			'mrm_mc_notice' => 'emergency_cancel_disabled_safe_patch',
		)
	);
}

public function register_masterclass_gate_query_vars( $vars ) {
	if ( ! is_array( $vars ) ) {
		$vars = array();
	}

	$vars[] = 'mrm_masterclass_gate';

	return array_values( array_unique( $vars ) );
}

public function maybe_render_masterclass_gate_page() {
	$token = get_query_var( 'mrm_masterclass_gate' );

	if ( empty( $token ) ) {
		return;
	}

	$this->mrm_mc_debug_log(
		'Masterclass gate placeholder reached. Full gate implementation is not installed yet.',
		array(
			'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
		)
	);

	status_header( 503 );
	nocache_headers();

	wp_die(
		esc_html__( 'Masterclass access is not available yet. Please contact Low Brass Lessons support if your event is starting soon.', 'mrm-masterclass' ),
		esc_html__( 'Masterclass Access Not Ready', 'mrm-masterclass' ),
		array(
			'response' => 503,
		)
	);
}

public function mrm_mc_remove_stale_admin_visibility_css_hooks() {
	global $wp_filter;

	$removed = 0;

	/*
	 * Remove known stale callback forms first.
	 */
	$known_callbacks = array(
		array( 'MRM_Masterclass_Plugin', 'mrm_mc_admin_visibility_css' ),
		array( 'LowBrass_MRM_Masterclass_Plugin', 'mrm_mc_admin_visibility_css' ),
	);

	foreach ( $known_callbacks as $callback ) {
		for ( $priority = -999999; $priority <= 999999; $priority++ ) {
			if ( remove_action( 'admin_head', $callback, $priority ) ) {
				$removed++;
			}
		}
	}

	/*
	 * Strong cleanup:
	 * Some stale callbacks may have been registered at unusual priorities or
	 * with class/object callback shapes. Walk the WP_Hook structure directly
	 * and remove any admin_head callback that targets mrm_mc_admin_visibility_css
	 * unless it is this exact current object instance.
	 */
	if ( isset( $wp_filter['admin_head'] ) && $wp_filter['admin_head'] instanceof WP_Hook ) {
		foreach ( $wp_filter['admin_head']->callbacks as $priority => $callbacks ) {
			if ( ! is_array( $callbacks ) ) {
				continue;
			}

			foreach ( $callbacks as $callback_id => $callback_data ) {
				if ( empty( $callback_data['function'] ) ) {
					continue;
				}

				$function = $callback_data['function'];
				$remove   = false;

				if ( is_array( $function ) && isset( $function[0], $function[1] ) ) {
					$target = $function[0];
					$method = (string) $function[1];

					if ( 'mrm_mc_admin_visibility_css' === $method ) {
						if ( is_string( $target ) ) {
							$remove = true;
						} elseif ( is_object( $target ) && $target !== $this ) {
							$remove = true;
						}
					}
				} elseif ( is_string( $function ) && false !== strpos( $function, 'mrm_mc_admin_visibility_css' ) ) {
					$remove = true;
				}

				if ( $remove ) {
					unset( $wp_filter['admin_head']->callbacks[ $priority ][ $callback_id ] );
					$removed++;
				}
			}

			if ( empty( $wp_filter['admin_head']->callbacks[ $priority ] ) ) {
				unset( $wp_filter['admin_head']->callbacks[ $priority ] );
			}
		}
	}

	$this->mrm_mc_debug_log(
		'Removed stale Masterclass admin_head visibility CSS callbacks.',
		array(
			'removed'     => $removed,
			'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			'is_admin'    => is_admin() ? 1 : 0,
		)
	);
}

public function mrm_mc_admin_visibility_css() {
	if ( ! is_admin() ) {
		return;
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

	if ( 0 !== strpos( $page, 'mrm-masterclass' ) ) {
		return;
	}

	echo '<style id="mrm-masterclass-admin-visibility-css">
		.mrm-masterclass-admin-wrap {
			max-width: 1180px;
		}
	</style>';
}

	public function register_admin_menu() {
	$this->mrm_mc_debug_log( 'Registering Masterclass admin menu.' );

	add_menu_page(
		'MRM Masterclass',
		'MRM Masterclass',
		'manage_options',
		'mrm-masterclass',
		array( $this, 'render_dashboard_page' ),
		'dashicons-welcome-learn-more',
		56
	);

	add_submenu_page(
		'mrm-masterclass',
		'Dashboard',
		'Dashboard',
		'manage_options',
		'mrm-masterclass',
		array( $this, 'render_dashboard_page' )
	);

	add_submenu_page(
		'mrm-masterclass',
		'Events',
		'Events',
		'manage_options',
		'mrm-masterclass-events',
		array( $this, 'render_events_page' )
	);

	add_submenu_page(
		'mrm-masterclass',
		'Presenters',
		'Presenters',
		'manage_options',
		'mrm-masterclass-presenters',
		array( $this, 'render_presenters_page' )
	);

	add_submenu_page(
		'mrm-masterclass',
		'Registrations / Payments',
		'Registrations / Payments',
		'manage_options',
		'mrm-masterclass-registrations-payments',
		array( $this, 'render_registrations_payments_page' )
	);

	add_submenu_page(
		'mrm-masterclass',
		'Presenter Payouts',
		'Presenter Payouts',
		'manage_options',
		'mrm-masterclass-payouts',
		array( $this, 'render_payouts_page' )
	);

	add_submenu_page(
		'mrm-masterclass',
		'Tax Profiles',
		'Tax Profiles',
		'manage_options',
		'mrm-masterclass-tax-profiles',
		array( $this, 'render_tax_profiles_page' )
	);
}



private function mrm_mc_admin_current_page_slug() {
	return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
}

private function mrm_mc_admin_tabs() {
	$current = $this->mrm_mc_admin_current_page_slug();

	$tabs = array(
		'mrm-masterclass'                       => 'Dashboard',
		'mrm-masterclass-events'                => 'Events',
		'mrm-masterclass-presenters'            => 'Presenters',
		'mrm-masterclass-registrations-payments'=> 'Registrations / Payments',
		'mrm-masterclass-payouts'               => 'Presenter Payouts',
		'mrm-masterclass-tax-profiles'          => 'Tax Profiles',
	);

	echo '<nav class="mrm-masterclass-admin-tabs" aria-label="Masterclass admin sections">';

	foreach ( $tabs as $slug => $label ) {
		$class = $current === $slug ? 'mrm-masterclass-admin-tab is-active' : 'mrm-masterclass-admin-tab';

		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
	}

	echo '</nav>';
}

private function mrm_mc_admin_page_open( $title, $description = '' ) {
	echo '<div class="wrap mrm-masterclass-admin-wrap">';
	echo '<div class="mrm-masterclass-admin-hero">';
	echo '<p class="mrm-masterclass-admin-eyebrow">Low Brass Lessons</p>';
	echo '<h1>' . esc_html( $title ) . '</h1>';

	if ( '' !== (string) $description ) {
		echo '<p class="mrm-masterclass-admin-description">' . esc_html( $description ) . '</p>';
	}

	echo '</div>';

	$this->mrm_mc_admin_tabs();
}

private function mrm_mc_admin_page_close() {
	echo '</div>';
}

private function mrm_mc_admin_card_open( $title = '', $description = '' ) {
	echo '<section class="mrm-masterclass-admin-card">';

	if ( '' !== (string) $title ) {
		echo '<h2>' . esc_html( $title ) . '</h2>';
	}

	if ( '' !== (string) $description ) {
		echo '<p class="mrm-masterclass-admin-muted">' . esc_html( $description ) . '</p>';
	}
}

private function mrm_mc_admin_card_close() {
	echo '</section>';
}

public function render_dashboard_page() {
	$this->must_admin();
	$this->mrm_mc_debug_log( 'Rendering Masterclass dashboard.' );

	global $wpdb;

	$events_table    = $this->t( 'mrm_masterclass_events' );
	$presenters_table = $this->t( 'mrm_masterclass_presenters' );
	$regs_table      = $this->t( 'mrm_masterclass_registrations' );

	$event_count     = $this->mrm_mc_table_exists( $events_table ) ? absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" ) ) : 0;
	$presenter_count = $this->mrm_mc_table_exists( $presenters_table ) ? absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$presenters_table}" ) ) : 0;
	$registration_count = $this->mrm_mc_table_exists( $regs_table ) ? absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$regs_table}" ) ) : 0;

	$this->mrm_mc_admin_page_open(
		'MRM Masterclass',
		'Manage Masterclass events, presenters, registrations, payouts, tax profiles, and public REST-driven frontend behavior.'
	);

	$this->mrm_mc_admin_card_open( 'System Overview', 'This dashboard mirrors the clean card-based structure used by the Scheduler and Payments Hub admin areas.' );

	echo '<div class="mrm-masterclass-admin-grid">';
	echo '<div class="mrm-masterclass-admin-stat"><strong>' . esc_html( $event_count ) . '</strong><span>Total Events</span></div>';
	echo '<div class="mrm-masterclass-admin-stat"><strong>' . esc_html( $presenter_count ) . '</strong><span>Presenters</span></div>';
	echo '<div class="mrm-masterclass-admin-stat"><strong>' . esc_html( $registration_count ) . '</strong><span>Registrations</span></div>';
	echo '</div>';

	$this->mrm_mc_admin_card_close();

	$this->mrm_mc_admin_card_open( 'Workflow', 'Use the tabs above to manage each part of the Masterclass system.' );

	echo '<ol>';
	echo '<li><strong>Events:</strong> create Masterclass sessions and configure defaults.</li>';
	echo '<li><strong>Presenters:</strong> manage presenter profiles and Stripe/payout identity fields.</li>';
	echo '<li><strong>Registrations / Payments:</strong> review customer registrations and payment status.</li>';
	echo '<li><strong>Presenter Payouts:</strong> review presenter share records.</li>';
	echo '<li><strong>Tax Profiles:</strong> manage backend-only presenter tax profile data.</li>';
	echo '</ol>';

	$this->mrm_mc_admin_card_close();

	$this->mrm_mc_admin_page_close();
}


public function render_settings_page() {
	$this->must_admin();
	$this->mrm_mc_debug_log( 'Legacy Settings submenu rendered.' );

	echo '<div class="wrap">';
	echo '<h1>MRM Masterclass Settings</h1>';
	echo '<p>The separate Settings submenu has been retired. Calendar/default settings now live at the top of <a href="' . esc_url( admin_url( 'admin.php?page=mrm-masterclass-events' ) ) . '">Masterclass Events</a>.</p>';
	echo '</div>';
}


public function render_presenters_page() {
	$this->must_admin();

	global $wpdb;

	$this->mrm_mc_debug_log( 'Rendering Presenters submenu.' );

	$table = $this->t( 'mrm_masterclass_presenters' );

	if ( ! $this->mrm_mc_table_exists( $table ) ) {
		echo '<div class="wrap">';
		echo '<h1>Masterclass Presenters</h1>';
		echo '<div class="notice notice-error"><p>The presenters table is missing. Reactivate the plugin or check wp-content/masterclass-debug.log.</p></div>';
		echo '</div>';
		return;
	}

	$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	$editing = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $edit_id ), ARRAY_A ) : null;
	$rows    = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC", ARRAY_A );

	if ( ! is_array( $rows ) ) {
		$rows = array();
	}

	$instrument_options = array(
		'trombone'   => 'Trombone',
		'euphonium' => 'Euphonium',
		'tuba'      => 'Tuba',
		'composer'  => 'Composer',
		'conductor' => 'Conductor',
		'other'     => 'Other',
	);

	$selected_instruments = array();

	if ( ! empty( $editing['instruments'] ) ) {
		$decoded = json_decode( $editing['instruments'], true );
		if ( is_array( $decoded ) ) {
			$selected_instruments = $decoded;
		}
	}

	$this->mrm_mc_admin_page_open(
		'Masterclass Presenters',
		'Create and manage presenters separately from scheduler instructors. These profiles feed event assignment, payout reporting, and tax profiles.'
	);

	if ( isset( $_GET['saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Presenter saved.</p></div>';
	}

	$this->mrm_mc_admin_card_open(
		$editing ? 'Edit Presenter' : 'Add Presenter',
		'Presenter records are backend-only and feed event assignment, payouts, and tax profile workflows.'
	);

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="mrm_masterclass_save_presenter">';
	echo '<input type="hidden" name="id" value="' . esc_attr( $editing['id'] ?? 0 ) . '">';
	wp_nonce_field( 'mrm_masterclass_save_presenter' );

	echo '<table class="form-table"><tbody>';
	echo '<tr><th>Name</th><td><input name="name" type="text" class="regular-text" required value="' . esc_attr( $editing['name'] ?? '' ) . '"></td></tr>';
	echo '<tr><th>Email</th><td><input name="email" type="email" class="regular-text" required value="' . esc_attr( $editing['email'] ?? '' ) . '"></td></tr>';
	echo '<tr><th>City</th><td><input name="city" type="text" class="regular-text" value="' . esc_attr( $editing['city'] ?? '' ) . '"></td></tr>';
	echo '<tr><th>State</th><td><input name="state" type="text" class="small-text" maxlength="2" value="' . esc_attr( $editing['state'] ?? '' ) . '"></td></tr>';
	echo '<tr><th>Address</th><td><input name="address" type="text" class="regular-text" value="' . esc_attr( $editing['address'] ?? '' ) . '"></td></tr>';
	echo '<tr><th>ZIP Code</th><td><input name="zip_code" type="text" class="regular-text" value="' . esc_attr( $editing['zip_code'] ?? '' ) . '"></td></tr>';
	echo '<tr><th>Timezone</th><td><input name="timezone" type="text" class="regular-text" value="' . esc_attr( $editing['timezone'] ?? 'America/Phoenix' ) . '"></td></tr>';
	echo '<tr><th>Stripe Connected Account ID</th><td><input name="stripe_connected_account_id" type="text" class="regular-text" placeholder="acct_..." value="' . esc_attr( $editing['stripe_connected_account_id'] ?? '' ) . '"><p class="description">Use the presenter Stripe Connect account ID. Store full SSN/EIN/TIN outside WordPress.</p></td></tr>';
	echo '<tr><th>Start Date</th><td><input name="hire_date" type="date" value="' . esc_attr( $editing['hire_date'] ?? '' ) . '"></td></tr>';
	echo '<tr><th>Profile Image URL</th><td><input name="profile_image_url" type="url" class="regular-text" value="' . esc_attr( $editing['profile_image_url'] ?? '' ) . '"></td></tr>';

	echo '<tr><th>Specialties</th><td>';
	foreach ( $instrument_options as $key => $label ) {
		echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="instruments[]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $selected_instruments, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
	}
	echo '</td></tr>';

	echo '<tr><th>Short Description</th><td><textarea name="short_description" rows="3" class="large-text">' . esc_textarea( $editing['short_description'] ?? '' ) . '</textarea></td></tr>';
	echo '<tr><th>Long Description</th><td><textarea name="long_description" rows="7" class="large-text">' . esc_textarea( $editing['long_description'] ?? '' ) . '</textarea></td></tr>';
	echo '<tr><th>Internal Bio / Notes</th><td><textarea name="bio" rows="5" class="large-text">' . esc_textarea( $editing['bio'] ?? '' ) . '</textarea></td></tr>';
	echo '</tbody></table>';

	submit_button( $editing ? 'Save Presenter' : 'Add Presenter' );

	if ( $editing ) {
		echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=mrm-masterclass-presenters' ) ) . '">Cancel</a>';
	}

	echo '</form>';
	echo '</div>';

	echo '<h2>Current Presenters</h2>';

	if ( empty( $rows ) ) {
		echo '<p>No presenters added yet. Use the form above to create the first presenter.</p>';
	} else {
		echo '<table class="widefat striped">';
		echo '<thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Location</th><th>Stripe Account</th><th>Timezone</th><th>Actions</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['id'] ) . '</td>';
			echo '<td><strong>' . esc_html( $row['name'] ) . '</strong></td>';
			echo '<td>' . esc_html( $row['email'] ) . '</td>';
			echo '<td>' . esc_html( trim( ( $row['city'] ?? '' ) . ', ' . ( $row['state'] ?? '' ), ', ' ) ) . '</td>';
			echo '<td><code>' . esc_html( $row['stripe_connected_account_id'] ?? '' ) . '</code></td>';
			echo '<td><code>' . esc_html( $row['timezone'] ?? '' ) . '</code></td>';
			echo '<td><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=mrm-masterclass-presenters&edit=' . absint( $row['id'] ) ) ) . '">Edit</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	echo '</div>';
}

public function render_events_page() {
	$this->must_admin();

	global $wpdb;

	$this->mrm_mc_debug_log( 'Rendering Events submenu.' );

	$settings         = $this->settings();
	$events_table     = $this->t( 'mrm_masterclass_events' );
	$presenters_table = $this->t( 'mrm_masterclass_presenters' );
	$regs_table       = $this->t( 'mrm_masterclass_registrations' );

	if ( ! $this->mrm_mc_table_exists( $events_table ) || ! $this->mrm_mc_table_exists( $presenters_table ) ) {
		echo '<div class="wrap"><h1>Masterclass Events</h1><div class="notice notice-error"><p>Required Masterclass database tables are missing. Reactivate the plugin and check wp-content/masterclass-debug.log.</p></div></div>';
		return;
	}

	$presenters = $wpdb->get_results( "SELECT * FROM {$presenters_table} ORDER BY name ASC" );

	$events = $wpdb->get_results(
		"SELECT e.*, p.name AS presenter_name,
			(SELECT COUNT(*) FROM {$regs_table} r WHERE r.event_id = e.id AND r.payment_status = 'paid') AS paid_count
		 FROM {$events_table} e
		 LEFT JOIN {$presenters_table} p ON p.id = e.presenter_id
		 ORDER BY e.start_time DESC
		 LIMIT 200"
	);

	$this->mrm_mc_admin_page_open(
		'Masterclass Events',
		'Create masterclass sessions, assign presenters, configure event defaults, and manage the Google Calendar ID controlled by this plugin.'
	);

	if ( isset( $_GET['created'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Masterclass event created.</p></div>';
	}

	if ( isset( $_GET['settings_updated'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Calendar settings saved.</p></div>';
	}

	$this->mrm_mc_admin_card_open(
		'Google Calendar Control',
		'Enter the Google Calendar ID that the Masterclass plugin should control. These defaults apply to newly created Masterclass events.'
	);

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="mrm_masterclass_save_settings">';
	wp_nonce_field( 'mrm_masterclass_save_settings' );

	echo '<table class="form-table"><tbody>';
	echo '<tr><th>Masterclass Google Calendar ID</th><td><input type="text" name="masterclass_calendar_id" class="regular-text" placeholder="...@group.calendar.google.com" value="' . esc_attr( $settings['masterclass_calendar_id'] ?? '' ) . '"></td></tr>';
	echo '<tr><th>Default Price</th><td><input type="number" name="default_price_cents" class="regular-text" value="' . esc_attr( $settings['default_price_cents'] ?? self::DEFAULT_PRICE_CENTS ) . '"><p class="description">Cents. Example: 2500 = $25.00.</p></td></tr>';
	echo '<tr><th>Default Capacity</th><td><input type="number" name="default_capacity" class="regular-text" value="' . esc_attr( $settings['default_capacity'] ?? 100 ) . '"></td></tr>';
	echo '<tr><th>Default Timezone</th><td><input type="text" name="default_timezone" class="regular-text" value="' . esc_attr( $settings['default_timezone'] ?? 'America/Phoenix' ) . '"></td></tr>';
	echo '<tr><th>Presenter Default Percent</th><td><input type="number" step="0.01" min="0" max="100" name="presenter_default_percent" class="regular-text" value="' . esc_attr( $settings['presenter_default_percent'] ?? 70 ) . '"></td></tr>';
	echo '<input type="hidden" name="admin_notification_email" value="' . esc_attr( $settings['admin_notification_email'] ?? get_option( 'admin_email' ) ) . '">';
	echo '<input type="hidden" name="from_email" value="' . esc_attr( $settings['from_email'] ?? 'no-reply@lowbrass-lessons.com' ) . '">';
	echo '<input type="hidden" name="terms_version" value="' . esc_attr( $settings['terms_version'] ?? 'v1' ) . '">';
	echo '<input type="hidden" name="stripe_fee_estimate_percent" value="' . esc_attr( $settings['stripe_fee_estimate_percent'] ?? 2.9 ) . '">';
	echo '<input type="hidden" name="stripe_fee_estimate_fixed" value="' . esc_attr( $settings['stripe_fee_estimate_fixed'] ?? 30 ) . '">';
	echo '</tbody></table>';

	submit_button( 'Save Calendar / Defaults' );
	echo '</form>';
	echo '</div>';

	echo '<div style="background:#fff;border:1px solid #d7c7ad;border-radius:16px;padding:20px;margin:18px 0;">';
	echo '<h2>Create Masterclass Session</h2>';

	if ( ! $presenters ) {
		echo '<div class="notice notice-warning inline"><p>Create a presenter before creating a masterclass event.</p></div>';
	}

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="mrm_masterclass_save_event">';
	wp_nonce_field( 'mrm_masterclass_save_event' );

	echo '<table class="form-table"><tbody>';
	echo '<tr><th>Title</th><td><input type="text" name="title" class="regular-text" required></td></tr>';
	echo '<tr><th>Description</th><td><textarea name="description" rows="5" class="large-text"></textarea></td></tr>';

	echo '<tr><th>Presenter</th><td><select name="presenter_id" required>';
	echo '<option value="">Select presenter</option>';
	foreach ( $presenters as $presenter ) {
		echo '<option value="' . esc_attr( $presenter->id ) . '">' . esc_html( $presenter->name . ' — ' . $presenter->email ) . '</option>';
	}
	echo '</select><p class="description">The presenter email is pulled from the selected presenter record.</p></td></tr>';

	echo '<tr><th>Optional Proctor Email</th><td><input type="email" name="proctor_email" class="regular-text" placeholder="proctor@example.com"><p class="description">Added as an event guest. True Google Meet co-host assignment may still require Workspace host controls.</p></td></tr>';
	echo '<tr><th>Start Time</th><td><input type="datetime-local" name="start_time" required></td></tr>';
	echo '<tr><th>End Time</th><td><input type="datetime-local" name="end_time" required></td></tr>';
	echo '<tr><th>Timezone</th><td><input type="text" name="timezone" class="regular-text" value="' . esc_attr( $settings['default_timezone'] ?? 'America/Phoenix' ) . '"></td></tr>';
	echo '<tr><th>Price</th><td><input type="number" name="price_cents" class="regular-text" value="' . esc_attr( $settings['default_price_cents'] ?? self::DEFAULT_PRICE_CENTS ) . '"><p class="description">Cents. Example: 2500 = $25.00.</p></td></tr>';
	echo '<tr><th>Capacity</th><td><input type="number" name="capacity" class="regular-text" value="' . esc_attr( $settings['default_capacity'] ?? 100 ) . '"></td></tr>';
	echo '<tr><th>Status</th><td><select name="status">';
	foreach ( array( 'draft', 'scheduled', 'cancelled', 'completed', 'archived' ) as $status ) {
		echo '<option value="' . esc_attr( $status ) . '"' . selected( $status, 'scheduled', false ) . '>' . esc_html( ucfirst( $status ) ) . '</option>';
	}
	echo '</select></td></tr>';
	echo '<tr><th>Registration Open</th><td><label><input type="checkbox" name="registration_open" value="1" checked> Accept registrations</label></td></tr>';
	echo '</tbody></table>';

	submit_button( 'Create Session' );
	echo '</form>';
	echo '</div>';

	echo '<h2>Existing Sessions</h2>';
	echo '<table class="widefat striped">';
	echo '<thead><tr><th>Title</th><th>Presenter</th><th>Proctor</th><th>Start</th><th>End</th><th>Price</th><th>Capacity</th><th>Paid</th><th>Status</th><th>Google Event</th><th>Meet Link</th></tr></thead><tbody>';

	if ( $events ) {
		foreach ( $events as $event ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $event->title ) . '</strong></td>';
			echo '<td>' . esc_html( $event->presenter_name ?: $event->presenter_email ) . '</td>';
			echo '<td>' . esc_html( $event->proctor_email ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $event->start_time ) . '</td>';
			echo '<td>' . esc_html( $event->end_time ) . '</td>';
			echo '<td>' . esc_html( $this->cents_to_dollars( $event->price_cents ) ) . '</td>';
			echo '<td>' . esc_html( $event->capacity ) . '</td>';
			echo '<td>' . esc_html( $event->paid_count ) . '</td>';
			echo '<td>' . esc_html( $event->status ) . '</td>';
			echo '<td><code>' . esc_html( $event->google_event_id ?: 'Not created yet' ) . '</code></td>';
			echo '<td>' . ( $event->google_meet_url ? '<a href="' . esc_url( $event->google_meet_url ) . '" target="_blank" rel="noopener">Open Meet</a>' : '—' ) . '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="11">No masterclass sessions created yet.</td></tr>';
	}

	echo '</tbody></table>';
	echo '</div>';
}

public function render_registrations_page() {
	$this->render_registrations_payments_page();
}

public function render_payments_page() {
	$this->render_registrations_payments_page();
}

public function render_registrations_payments_page() {
	$this->must_admin();

	global $wpdb;

	$this->mrm_mc_debug_log( 'Rendering Registrations / Payments submenu.' );

	$regs_table       = $this->t( 'mrm_masterclass_registrations' );
	$events_table     = $this->t( 'mrm_masterclass_events' );
	$presenters_table = $this->t( 'mrm_masterclass_presenters' );
	$ledger_table     = $this->t( 'mrm_masterclass_payment_ledger' );

	if ( ! $this->mrm_mc_table_exists( $regs_table ) || ! $this->mrm_mc_table_exists( $ledger_table ) ) {
		echo '<div class="wrap"><h1>Registrations / Payments</h1><div class="notice notice-error"><p>Required registration/payment tables are missing. Reactivate the plugin and check wp-content/masterclass-debug.log.</p></div></div>';
		return;
	}

	$rows = $wpdb->get_results(
		"SELECT r.*, e.title AS event_title, e.start_time, p.name AS presenter_name,
		        l.gross_cents, l.stripe_fee_cents, l.net_cents, l.presenter_share_cents, l.platform_share_cents, l.status AS ledger_status
		 FROM {$regs_table} r
		 LEFT JOIN {$events_table} e ON e.id = r.event_id
		 LEFT JOIN {$presenters_table} p ON p.id = e.presenter_id
		 LEFT JOIN {$ledger_table} l ON l.registration_id = r.id
		 ORDER BY r.created_at DESC
		 LIMIT 500"
	);

	echo '<div class="wrap">';
	echo '<h1>Registrations / Payments</h1>';
	echo '<p>This combines the masterclass registration list and payment ledger into one audit view, similar in purpose to the legal dispute ledger.</p>';

	echo '<table class="widefat striped">';
	echo '<thead><tr><th>ID</th><th>Event</th><th>Presenter</th><th>Customer</th><th>Email</th><th>PaymentIntent</th><th>Paid</th><th>Gross</th><th>Stripe Fee Est.</th><th>Net</th><th>Presenter Share</th><th>Platform Share</th><th>Ledger</th><th>Google Attendee</th><th>Created</th></tr></thead><tbody>';

	if ( $rows ) {
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row->id ) . '</td>';
			echo '<td><strong>' . esc_html( $row->event_title ?: '—' ) . '</strong><br><small>' . esc_html( $row->start_time ?: '' ) . '</small></td>';
			echo '<td>' . esc_html( $row->presenter_name ?: '—' ) . '</td>';
			echo '<td>' . esc_html( trim( $row->first_name . ' ' . $row->last_name ) ) . '</td>';
			echo '<td>' . esc_html( $row->email ) . '</td>';
			echo '<td><code>' . esc_html( $row->stripe_payment_intent_id ?: '—' ) . '</code></td>';
			echo '<td>' . esc_html( $row->payment_status ) . '</td>';
			echo '<td>' . esc_html( $this->cents_to_dollars( $row->gross_cents ) ) . '</td>';
			echo '<td>' . esc_html( $this->cents_to_dollars( $row->stripe_fee_cents ) ) . '</td>';
			echo '<td>' . esc_html( $this->cents_to_dollars( $row->net_cents ) ) . '</td>';
			echo '<td>' . esc_html( $this->cents_to_dollars( $row->presenter_share_cents ) ) . '</td>';
			echo '<td>' . esc_html( $this->cents_to_dollars( $row->platform_share_cents ) ) . '</td>';
			echo '<td>' . esc_html( $row->ledger_status ?: '—' ) . '</td>';
			echo '<td>' . ( $row->google_attendee_added ? 'Yes' : 'No' ) . '</td>';
			echo '<td>' . esc_html( $row->created_at ) . '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="15">No registrations or payments found yet.</td></tr>';
	}

	echo '</tbody></table>';
	echo '</div>';
}


public function render_payouts_page() {
	$this->must_admin();

	global $wpdb;

	$this->mrm_mc_debug_log( 'Rendering Presenter Payouts submenu.' );

	$ledger_table     = $this->t( 'mrm_masterclass_payment_ledger' );
	$events_table     = $this->t( 'mrm_masterclass_events' );
	$presenters_table = $this->t( 'mrm_masterclass_presenters' );

	if ( ! $this->mrm_mc_table_exists( $ledger_table ) || ! $this->mrm_mc_table_exists( $presenters_table ) ) {
		echo '<div class="wrap"><h1>Presenter Payouts</h1><div class="notice notice-error"><p>Required payout tables are missing. Reactivate the plugin and check wp-content/masterclass-debug.log.</p></div></div>';
		return;
	}

	$summary = $wpdb->get_results(
		"SELECT p.id, p.name, p.email, p.stripe_connected_account_id,
		        COALESCE(SUM(CASE WHEN l.status = 'recorded' THEN l.presenter_share_cents ELSE 0 END),0) AS unpaid_cents,
		        COALESCE(SUM(CASE WHEN l.status = 'paid_out' THEN l.presenter_share_cents ELSE 0 END),0) AS paid_out_cents
		 FROM {$presenters_table} p
		 LEFT JOIN {$ledger_table} l ON l.presenter_id = p.id AND l.ledger_type = 'registration_payment'
		 GROUP BY p.id
		 ORDER BY p.name ASC"
	);

	$rows = $wpdb->get_results(
		"SELECT l.*, e.title AS event_title, p.name AS presenter_name, p.email AS presenter_email, p.stripe_connected_account_id
		 FROM {$ledger_table} l
		 LEFT JOIN {$events_table} e ON e.id = l.event_id
		 LEFT JOIN {$presenters_table} p ON p.id = l.presenter_id
		 WHERE l.ledger_type = 'registration_payment'
		   AND l.status = 'recorded'
		   AND l.presenter_share_cents > 0
		 ORDER BY p.name ASC, l.created_at ASC"
	);

	echo '<div class="wrap">';
	echo '<h1>Presenter Payouts</h1>';

	if ( isset( $_GET['paid'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Selected presenter payout rows were marked paid out.</p></div>';
	}

	echo '<h2>Presenter Balances</h2>';
	echo '<table class="widefat striped">';
	echo '<thead><tr><th>Presenter</th><th>Email</th><th>Stripe Account</th><th>Unpaid</th><th>Paid Out</th></tr></thead><tbody>';

	if ( $summary ) {
		foreach ( $summary as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row->name ) . '</td>';
			echo '<td>' . esc_html( $row->email ) . '</td>';
			echo '<td><code>' . esc_html( $row->stripe_connected_account_id ?: '—' ) . '</code></td>';
			echo '<td>' . esc_html( $this->cents_to_dollars( $row->unpaid_cents ) ) . '</td>';
			echo '<td>' . esc_html( $this->cents_to_dollars( $row->paid_out_cents ) ) . '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="5">No presenters found.</td></tr>';
	}

	echo '</tbody></table>';

	echo '<h2 style="margin-top:28px;">Run Payout Batch</h2>';
	echo '<p>Select rows that have actually been paid. Marking rows paid out is what makes them count toward 1099 totals.</p>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="mrm_masterclass_mark_payouts_paid">';
	wp_nonce_field( 'mrm_masterclass_mark_payouts_paid' );

	echo '<table class="widefat striped">';
	echo '<thead><tr><th>Select</th><th>Presenter</th><th>Event</th><th>Presenter Share</th><th>Created</th></tr></thead><tbody>';

	if ( $rows ) {
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td><input type="checkbox" name="ledger_ids[]" value="' . esc_attr( $row->id ) . '"></td>';
			echo '<td>' . esc_html( $row->presenter_name ?: '—' ) . '<br><small>' . esc_html( $row->presenter_email ?: '' ) . '</small></td>';
			echo '<td>' . esc_html( $row->event_title ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $this->cents_to_dollars( $row->presenter_share_cents ) ) . '</td>';
			echo '<td>' . esc_html( $row->created_at ) . '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="5">No unpaid presenter payout rows found.</td></tr>';
	}

	echo '</tbody></table>';

	if ( $rows ) {
		submit_button( 'Run Payout Batch Now / Mark Selected Paid Out' );
	}

	echo '</form>';
	echo '</div>';
}

public function render_1099_page() {
	$this->render_tax_profiles_page();
}

public function render_tax_profiles_page() {
	$this->must_admin();

	global $wpdb;

	$this->mrm_mc_debug_log( 'Rendering Tax Profiles submenu.' );

	$presenters_table = $this->t( 'mrm_masterclass_presenters' );
	$profiles_table   = $this->t( 'mrm_masterclass_presenter_tax_profiles' );
	$ledger_table     = $this->t( 'mrm_masterclass_payment_ledger' );

	if ( ! $this->mrm_mc_table_exists( $presenters_table ) || ! $this->mrm_mc_table_exists( $profiles_table ) ) {
		echo '<div class="wrap"><h1>Tax Profiles</h1><div class="notice notice-error"><p>Required tax profile tables are missing. Reactivate the plugin and check wp-content/masterclass-debug.log.</p></div></div>';
		return;
	}

	$year = isset( $_GET['tax_year'] ) ? absint( $_GET['tax_year'] ) : absint( gmdate( 'Y' ) );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.*,
			        t.legal_name, t.business_name, t.email AS tax_email, t.tin_last4, t.tin_type,
			        t.w9_received, t.w9_received_date, t.address_line1, t.address_line2,
			        t.city AS tax_city, t.state AS tax_state, t.zip AS tax_zip,
			        t.is_1099_eligible, t.exclude_from_1099, t.notes,
			        COALESCE(SUM(CASE WHEN l.status = 'paid_out' AND YEAR(COALESCE(l.paid_out_at,l.updated_at)) = %d THEN l.presenter_share_cents ELSE 0 END),0) AS box1_cents
			 FROM {$presenters_table} p
			 LEFT JOIN {$profiles_table} t ON t.presenter_id = p.id
			 LEFT JOIN {$ledger_table} l ON l.presenter_id = p.id AND l.ledger_type = 'registration_payment'
			 GROUP BY p.id
			 ORDER BY p.name ASC",
			$year
		)
	);

	echo '<div class="wrap">';
	echo '<h1>Tax Profiles</h1>';
	echo '<p>This combines presenter tax profiles and 1099 paid-out totals. Full TIN values should live in AWS/Stripe, not WordPress.</p>';

	echo '<form method="get" style="margin:16px 0;">';
	echo '<input type="hidden" name="page" value="mrm-masterclass-tax-profiles">';
	echo '<label><strong>Tax Year</strong> <input type="number" name="tax_year" value="' . esc_attr( $year ) . '" min="2024" max="2100"></label> ';
	echo '<button class="button">Filter</button>';
	echo '</form>';

	if ( ! $rows ) {
		echo '<p>No presenters found yet. Create presenters first in the Presenters submenu, then return here to complete their tax profiles.</p>';
		echo '</div>';
		return;
	}

	foreach ( $rows as $row ) {
		$box1     = absint( $row->box1_cents );
		$eligible = ( is_null( $row->is_1099_eligible ) || absint( $row->is_1099_eligible ) === 1 ) && ! absint( $row->exclude_from_1099 );
		$needed   = $eligible && $box1 >= 60000;

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="background:#fff;border:1px solid #d7c7ad;border-radius:16px;padding:20px;margin:18px 0;">';
		echo '<input type="hidden" name="action" value="mrm_masterclass_save_tax_profile">';
		echo '<input type="hidden" name="presenter_id" value="' . esc_attr( $row->id ) . '">';
		wp_nonce_field( 'mrm_masterclass_save_tax_profile_' . absint( $row->id ) );

		echo '<h2>' . esc_html( $row->name ) . '</h2>';
		echo '<p><strong>Presenter email:</strong> ' . esc_html( $row->email ) . '</p>';
		echo '<p><strong>Stripe Connected Account:</strong> <code>' . esc_html( $row->stripe_connected_account_id ?: '—' ) . '</code></p>';
		echo '<p><strong>' . esc_html( $year ) . ' paid-out 1099 total:</strong> ' . esc_html( $this->cents_to_dollars( $box1 ) ) . ' — <strong>1099 Needed?</strong> ' . esc_html( $needed ? 'Yes' : 'No' ) . '</p>';
		echo '<p><strong>AWS expected TIN path:</strong> <code>presenters.' . esc_html( $row->id ) . '.tin</code></p>';

		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Legal Name</th><td><input type="text" name="legal_name" class="regular-text" value="' . esc_attr( $row->legal_name ?: $row->name ) . '"></td></tr>';
		echo '<tr><th>Business Name</th><td><input type="text" name="business_name" class="regular-text" value="' . esc_attr( $row->business_name ) . '"></td></tr>';
		echo '<tr><th>Tax Email</th><td><input type="email" name="email" class="regular-text" value="' . esc_attr( $row->tax_email ?: $row->email ) . '"></td></tr>';
		echo '<tr><th>TIN Last 4</th><td><input type="text" name="tin_last4" maxlength="4" class="small-text" value="' . esc_attr( $row->tin_last4 ) . '"><p class="description">Only store last four digits in WordPress.</p></td></tr>';

		echo '<tr><th>TIN Type</th><td><select name="tin_type">';
		foreach ( array( '', 'SSN', 'EIN', 'ITIN' ) as $type ) {
			echo '<option value="' . esc_attr( $type ) . '"' . selected( $row->tin_type, $type, false ) . '>' . esc_html( $type ?: 'Select' ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th>W-9 Received</th><td><label><input type="checkbox" name="w9_received" value="1" ' . checked( $row->w9_received, 1, false ) . '> Yes</label></td></tr>';
		echo '<tr><th>W-9 Received Date</th><td><input type="date" name="w9_received_date" value="' . esc_attr( $row->w9_received_date ) . '"></td></tr>';
		echo '<tr><th>Address Line 1</th><td><input type="text" name="address_line1" class="regular-text" value="' . esc_attr( $row->address_line1 ?: $row->address ) . '"></td></tr>';
		echo '<tr><th>Address Line 2</th><td><input type="text" name="address_line2" class="regular-text" value="' . esc_attr( $row->address_line2 ) . '"></td></tr>';
		echo '<tr><th>City</th><td><input type="text" name="city" class="regular-text" value="' . esc_attr( $row->tax_city ?: $row->city ) . '"></td></tr>';
		echo '<tr><th>State</th><td><input type="text" name="state" class="regular-text" value="' . esc_attr( $row->tax_state ?: $row->state ) . '"></td></tr>';
		echo '<tr><th>ZIP</th><td><input type="text" name="zip" class="regular-text" value="' . esc_attr( $row->tax_zip ?: $row->zip_code ) . '"></td></tr>';
		echo '<tr><th>1099 Eligible</th><td><label><input type="checkbox" name="is_1099_eligible" value="1" ' . checked( is_null( $row->is_1099_eligible ) ? 1 : $row->is_1099_eligible, 1, false ) . '> Yes</label></td></tr>';
		echo '<tr><th>Exclude From 1099</th><td><label><input type="checkbox" name="exclude_from_1099" value="1" ' . checked( $row->exclude_from_1099, 1, false ) . '> Yes</label></td></tr>';
		echo '<tr><th>Notes</th><td><textarea name="notes" rows="4" class="large-text">' . esc_textarea( $row->notes ) . '</textarea></td></tr>';
		echo '</tbody></table>';

		submit_button( 'Save Tax Profile' );
		echo '</form>';
	}

	echo '</div>';
}

public function render_email_log_page() {
		$this->must_admin();

		global $wpdb;

		$table = $this->t( 'mrm_masterclass_email_log' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sent_at DESC LIMIT 300" );

		echo '<div class="wrap">';
		echo '<h1>Masterclass Email Log</h1>';

		$this->admin_card_open( 'Recent Emails', 'This log records masterclass transactional email attempts, including confirmations, reminders, and failure records.' );

		echo '<table class="widefat striped">';
		echo '<thead><tr><th>Status</th><th>Type</th><th>Recipient</th><th>Subject</th><th>Event</th><th>Registration</th><th>Sent At</th><th>Error</th></tr></thead><tbody>';

		if ( $rows ) {
			foreach ( $rows as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( $row->status ) . '</td>';
				echo '<td>' . esc_html( $row->email_type ) . '</td>';
				echo '<td>' . esc_html( $row->recipient_email ) . '</td>';
				echo '<td>' . esc_html( $row->subject ) . '</td>';
				echo '<td>' . esc_html( $row->event_id ?: '—' ) . '</td>';
				echo '<td>' . esc_html( $row->registration_id ?: '—' ) . '</td>';
				echo '<td>' . esc_html( $row->sent_at ) . '</td>';
				echo '<td>' . esc_html( $row->error_message ?: '—' ) . '</td>';
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="8">No masterclass email records found yet.</td></tr>';
		}

		echo '</tbody></table>';

		$this->admin_card_close();

		echo '</div>';
	}

	public function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_events' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/event',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_event' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/apply-promo',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_apply_promo' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/create-payment-intent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_create_pi' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/verify-payment-intent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_verify_pi' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/finalize-registration',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_finalize' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_health' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private function mrm_mc_rest_not_implemented_error( $feature ) {
	return new WP_Error(
		'mrm_masterclass_feature_not_implemented',
		sprintf(
			'The Masterclass %s endpoint exists, but the full implementation has not been installed yet.',
			sanitize_text_field( (string) $feature )
		),
		array(
			'status' => 501,
		)
	);
}

private function mrm_mc_get_rest_json_body() {
	$raw = file_get_contents( 'php://input' );

	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		return array();
	}

	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) ) {
		return array();
	}

	return $data;
}

public function rest_event( WP_REST_Request $request ) {
	$event_id = absint( $request->get_param( 'id' ) );

	$this->mrm_mc_debug_log(
		'REST single-event endpoint reached.',
		array(
			'event_id' => $event_id,
		)
	);

	if ( ! $this->mrm_mc_required_tables_ready() ) {
		return $this->mrm_mc_rest_tables_error();
	}

	if ( $event_id <= 0 ) {
		return new WP_Error(
			'mrm_masterclass_missing_event_id',
			'Missing Masterclass event ID.',
			array(
				'status' => 400,
			)
		);
	}

	global $wpdb;

	$events_table     = $this->t( 'mrm_masterclass_events' );
	$presenters_table = $this->t( 'mrm_masterclass_presenters' );
	$regs_table       = $this->t( 'mrm_masterclass_registrations' );

	if (
		! $this->mrm_mc_table_exists( $events_table ) ||
		! $this->mrm_mc_table_exists( $presenters_table ) ||
		! $this->mrm_mc_table_exists( $regs_table )
	) {
		return $this->mrm_mc_rest_tables_error();
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT e.id,e.title,e.description,p.name AS presenter_name,p.presenter_page_id,
			        e.start_time,e.end_time,e.timezone,e.price_cents,e.capacity,e.status,e.registration_open,
			        (e.capacity-(SELECT COUNT(*) FROM {$regs_table} r WHERE r.event_id=e.id AND r.payment_status='paid')) AS available_seats
			 FROM {$events_table} e
			 LEFT JOIN {$presenters_table} p ON p.id=e.presenter_id
			 WHERE e.id=%d
			 LIMIT 1",
			$event_id
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) || empty( $row ) ) {
		return new WP_Error(
			'mrm_masterclass_event_not_found',
			'This Masterclass could not be found.',
			array(
				'status' => 404,
			)
		);
	}

	$page_id = absint( $row['presenter_page_id'] ?? 0 );

	$row['presenter_page_url'] = $page_id ? get_permalink( $page_id ) : '';
	$row['description_html']   = wp_kses_post( $row['description'] ?? '' );
	$row['price_cents']        = absint( $row['price_cents'] ?? 0 );
	$row['available_seats']    = max( 0, absint( $row['available_seats'] ?? 0 ) );

	return rest_ensure_response( $row );
}

public function rest_apply_promo( WP_REST_Request $request ) {
	$data       = $this->mrm_mc_get_rest_json_body();
	$promo_code = strtoupper( sanitize_text_field( $data['promo_code'] ?? '' ) );

	$this->mrm_mc_debug_log(
		'REST promo placeholder endpoint reached.',
		array(
			'has_promo_code' => '' !== $promo_code ? 1 : 0,
		)
	);

	if ( '' === $promo_code ) {
		return rest_ensure_response(
			array(
				'ok'             => true,
				'discount_cents' => 0,
				'message'        => '',
			)
		);
	}

	return rest_ensure_response(
		array(
			'ok'             => true,
			'discount_cents' => 0,
			'message'        => 'Promo code support for Masterclasses is being prepared and is not active yet.',
			'promo_code'     => $promo_code,
		)
	);
}

public function rest_create_pi( WP_REST_Request $request ) {
	$this->mrm_mc_debug_log( 'REST create-payment-intent placeholder endpoint reached.' );

	if ( ! $this->mrm_mc_required_tables_ready() ) {
		return $this->mrm_mc_rest_tables_error();
	}

	return $this->mrm_mc_rest_not_implemented_error( 'payment setup' );
}

public function rest_verify_pi( WP_REST_Request $request ) {
	$this->mrm_mc_debug_log( 'REST verify-payment-intent placeholder endpoint reached.' );

	return $this->mrm_mc_rest_not_implemented_error( 'payment verification' );
}

public function rest_finalize( WP_REST_Request $request ) {
	$this->mrm_mc_debug_log( 'REST finalize-registration placeholder endpoint reached.' );

	if ( ! $this->mrm_mc_required_tables_ready() ) {
		return $this->mrm_mc_rest_tables_error();
	}

	return $this->mrm_mc_rest_not_implemented_error( 'registration finalization' );
}

	public function rest_health() {
		return rest_ensure_response(
			array(
				'ok'              => true,
				'plugin'          => 'mrm-masterclass',
				'mode'            => 'rest-only-frontend',
				'db_version'      => get_option( 'mrm_masterclass_db_version', '' ),
				'code_db_version' => self::DB_VERSION,
				'tables_ready'    => $this->mrm_mc_required_tables_ready(),
				'missing_tables'  => $this->mrm_mc_missing_tables(),
				'timestamp'       => gmdate( 'c' ),
			)
		);
	}

	public function rest_events() {
		if ( ! $this->mrm_mc_required_tables_ready() ) {
			return $this->mrm_mc_rest_tables_error();
		}

		global $wpdb;

		$events_table     = $this->t( 'mrm_masterclass_events' );
		$presenters_table = $this->t( 'mrm_masterclass_presenters' );
		$regs_table       = $this->t( 'mrm_masterclass_registrations' );

		$rows = $wpdb->get_results(
			"SELECT e.id,e.title,e.description,p.name AS presenter_name,p.presenter_page_id,
			        e.start_time,e.end_time,e.timezone,e.price_cents,e.capacity,e.status,e.registration_open,
			        (e.capacity-(SELECT COUNT(*) FROM {$regs_table} r WHERE r.event_id=e.id AND r.payment_status='paid')) AS available_seats
			 FROM {$events_table} e
			 LEFT JOIN {$presenters_table} p ON p.id=e.presenter_id
			 WHERE e.status='scheduled'
			   AND e.registration_open = 1
			 ORDER BY e.start_time ASC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		foreach ( $rows as &$row ) {
			$page_id = absint( $row['presenter_page_id'] ?? 0 );
			$row['presenter_page_url'] = $page_id ? get_permalink( $page_id ) : '';
		}

		return rest_ensure_response( $rows );
	}


public function render_activation_diagnostic_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$error = get_option( 'mrm_masterclass_activation_error', array() );

	if ( is_array( $error ) && ! empty( $error['message'] ) ) {
		echo '<div class="notice notice-error">';
		echo '<p><strong>MRM Masterclass needs attention.</strong> The database installer or runtime upgrade failed safely instead of crashing the site.</p>';
		echo '<p><strong>Error:</strong> ' . esc_html( $error['message'] ) . '</p>';

		if ( ! empty( $error['file'] ) || ! empty( $error['line'] ) ) {
			echo '<p><code>' . esc_html( ( $error['file'] ?? '' ) . ':' . ( $error['line'] ?? '' ) ) . '</code></p>';
		}

		echo '<p>After applying the stabilization patch, reload the failing admin/settings/frontend page and check <code>wp-content/masterclass-debug.log</code>. If the notice remains, the stored critical-error details below should identify the file and line that failed.</p>';
		echo '</div>';
	}

	if ( ! get_transient( 'mrm_masterclass_activation_notice' ) ) {
		return;
	}

	delete_transient( 'mrm_masterclass_activation_notice' );

	echo '<div class="notice notice-success is-dismissible">';
	echo '<p><strong>MRM Masterclass activated.</strong> Database tables were checked and the masterclass admin menus are available.</p>';
	echo '</div>';
}


}

} // End class_exists guard for LowBrass_MRM_Masterclass_Plugin.

if ( ! function_exists( 'mrm_lowbrass_masterclass_plugin_instance' ) ) {
	function mrm_lowbrass_masterclass_plugin_instance() {
		static $instance = null;

		if ( null === $instance && class_exists( 'LowBrass_MRM_Masterclass_Plugin', false ) ) {
			$instance = new LowBrass_MRM_Masterclass_Plugin();
		}

		return $instance;
	}
}

if ( is_admin() ) {
	add_action(
		'admin_head',
		function() {
			global $wp_filter;

			if ( ! isset( $wp_filter['admin_head'] ) || ! $wp_filter['admin_head'] instanceof WP_Hook ) {
				return;
			}

			foreach ( $wp_filter['admin_head']->callbacks as $priority => $callbacks ) {
				if ( ! is_array( $callbacks ) ) {
					continue;
				}

				foreach ( $callbacks as $callback_id => $callback_data ) {
					if ( empty( $callback_data['function'] ) ) {
						continue;
					}

					$function = $callback_data['function'];

					if ( is_array( $function ) && isset( $function[1] ) && 'mrm_mc_admin_visibility_css' === (string) $function[1] ) {
						if ( is_string( $function[0] ) && in_array( $function[0], array( 'MRM_Masterclass_Plugin', 'LowBrass_MRM_Masterclass_Plugin' ), true ) ) {
							unset( $wp_filter['admin_head']->callbacks[ $priority ][ $callback_id ] );
						}
					}
				}

				if ( empty( $wp_filter['admin_head']->callbacks[ $priority ] ) ) {
					unset( $wp_filter['admin_head']->callbacks[ $priority ] );
				}
			}
		},
		-1000000,
		0
	);
}

if ( class_exists( 'LowBrass_MRM_Masterclass_Plugin', false ) ) {
	register_activation_hook( __FILE__, array( 'LowBrass_MRM_Masterclass_Plugin', 'activate' ) );
	register_deactivation_hook( __FILE__, array( 'LowBrass_MRM_Masterclass_Plugin', 'deactivate' ) );

	try {
		if ( function_exists( 'mrm_lowbrass_masterclass_emergency_file_log' ) ) {
			mrm_lowbrass_masterclass_emergency_file_log(
				'Attempting to instantiate LowBrass_MRM_Masterclass_Plugin through isolated Masterclass bootstrap.',
				array(
					'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
					'is_admin'    => is_admin() ? 1 : 0,
				)
			);
		}

		mrm_lowbrass_masterclass_plugin_instance();

		if ( function_exists( 'mrm_lowbrass_masterclass_emergency_file_log' ) ) {
			mrm_lowbrass_masterclass_emergency_file_log(
				'LowBrass_MRM_Masterclass_Plugin instantiated successfully through isolated Masterclass bootstrap.',
				array(
					'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
					'is_admin'    => is_admin() ? 1 : 0,
				)
			);
		}
	} catch ( Throwable $e ) {
		if ( function_exists( 'mrm_lowbrass_masterclass_emergency_file_log' ) ) {
			mrm_lowbrass_masterclass_emergency_file_log(
				'LowBrass_MRM_Masterclass_Plugin instantiation failed safely.',
				array(
					'message'     => $e->getMessage(),
					'file'        => $e->getFile(),
					'line'        => $e->getLine(),
					'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
					'is_admin'    => is_admin() ? 1 : 0,
				)
			);
		}

		if ( is_admin() && current_user_can( 'manage_options' ) ) {
			add_action(
				'admin_notices',
				function() use ( $e ) {
					echo '<div class="notice notice-error">';
					echo '<p><strong>MRM Masterclass failed to initialize.</strong></p>';
					echo '<p>' . esc_html( $e->getMessage() ) . '</p>';
					echo '<p><code>' . esc_html( $e->getFile() . ':' . $e->getLine() ) . '</code></p>';
					echo '</div>';
				}
			);
		}
	}
}
