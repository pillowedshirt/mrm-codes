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

		$file = trailingslashit( WP_CONTENT_DIR ) . 'masterclass-debug.log';

		$safe_context = array();

		if ( is_array( $context ) ) {
			foreach ( $context as $key => $value ) {
				$key = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $key ) : preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key );

				if ( preg_match( '/secret|token|password|private|authorization|cookie|nonce|key|tin|ssn|ein/i', $key ) ) {
					$safe_context[ $key ] = '[redacted]';
					continue;
				}

				if ( is_scalar( $value ) ) {
					$string_value = (string) $value;

					if ( preg_match( '/sk_live_|sk_test_|pk_live_|pk_test_|whsec_|-----BEGIN|Bearer\s+/i', $string_value ) ) {
						$safe_context[ $key ] = '[redacted]';
						continue;
					}
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

	const DB_VERSION = '1.3.0';
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
	/*
	 * Launch-mode logging:
	 * Keep fatal/runtime/admin action logs, but do not log every normal WordPress lifecycle checkpoint.
	 */

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
	$this->mrm_mc_add_action_if_method_exists( 'mrm_masterclass_reminder_cron', 'mrm_mc_run_reminder_cron', 10, 0 );
	$this->mrm_mc_add_action_if_method_exists( 'mrm_masterclass_payout_cron', 'mrm_mc_run_payout_cron', 10, 0 );

	$actions = array(
		'mrm_masterclass_save_settings'            => 'handle_save_settings',
		'mrm_masterclass_save_presenter'           => 'handle_save_presenter',
		'mrm_masterclass_delete_presenter'         => 'handle_delete_presenter',
		'mrm_masterclass_save_event'               => 'handle_save_event',
		'mrm_masterclass_cancel_event'             => 'handle_cancel_event',
		'mrm_masterclass_mark_payouts_paid'        => 'handle_mark_payouts_paid',
		'mrm_masterclass_mark_payout_paid'         => 'handle_mark_payout_paid',
		'mrm_masterclass_issue_payout_transfer'    => 'handle_issue_payout_transfer',
		'mrm_masterclass_recalculate_ledger_shares'=> 'handle_recalculate_ledger_shares',
		'mrm_masterclass_export_1099_csv'          => 'handle_export_1099_csv',
		'mrm_masterclass_save_tax_profile'         => 'handle_save_tax_profile',
		'mrm_masterclass_create_presenter_page'    => 'handle_create_presenter_page',
		'mrm_masterclass_resend_confirmation'      => 'handle_resend_confirmation',
		'mrm_masterclass_resend_reminder'          => 'handle_resend_reminder',
		'mrm_masterclass_emergency_cancel_confirm' => 'handle_emergency_cancel_confirm',
		'mrm_masterclass_emergency_cancel_execute' => 'handle_emergency_cancel_execute',
		'mrm_masterclass_emergency_cancel_event'   => 'handle_emergency_cancel_event',
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
	$this->mrm_mc_add_action_if_method_exists( 'template_redirect', 'mrm_mc_handle_gate_request', 1, 0 );

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

		if ( ! wp_next_scheduled( 'mrm_masterclass_reminder_cron' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'mrm_masterclass_reminder_cron' );
		}

		if ( ! wp_next_scheduled( 'mrm_masterclass_payout_cron' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'mrm_masterclass_payout_cron' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'mrm_masterclass_send_reminders' );
		wp_clear_scheduled_hook( 'mrm_masterclass_reconcile_events' );
		wp_clear_scheduled_hook( 'mrm_masterclass_reminder_cron' );
		wp_clear_scheduled_hook( 'mrm_masterclass_payout_cron' );
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
		'payout_percent'              => "ALTER TABLE {$presenters_table} ADD payout_percent DECIMAL(5,2) NULL",
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
		'status'                      => "ALTER TABLE {$presenters_table} ADD status VARCHAR(32) NOT NULL DEFAULT 'active'",
		'presenter_page_id'           => "ALTER TABLE {$presenters_table} ADD presenter_page_id BIGINT UNSIGNED NULL",
	);

	foreach ( $presenter_adds as $column => $sql ) {
		if ( ! in_array( $column, $presenter_columns, true ) ) {
			$wpdb->query( $sql );
		}
	}

	$default_percent = 70.00;
	$saved_settings  = get_option( 'mrm_masterclass_settings', array() );

	if ( is_array( $saved_settings ) && isset( $saved_settings['presenter_default_percent'] ) ) {
		$default_percent = max( 0, min( 100, (float) $saved_settings['presenter_default_percent'] ) );
	}

	if ( in_array( 'payout_percent', $wpdb->get_col( "DESC {$presenters_table}", 0 ), true ) ) {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$presenters_table} SET payout_percent = %f WHERE payout_percent IS NULL",
				$default_percent
			)
		);
	}

	$event_columns = $wpdb->get_col( "DESC {$events_table}", 0 );
	if ( ! is_array( $event_columns ) ) {
		$event_columns = array();
	}

	$event_adds = array(
		'calendar_id'            => "ALTER TABLE {$events_table} ADD calendar_id VARCHAR(191) NULL",
		'refund_request_token'   => "ALTER TABLE {$events_table} ADD refund_request_token VARCHAR(64) NULL",
		'last_update_notice_at'  => "ALTER TABLE {$events_table} ADD last_update_notice_at DATETIME NULL",
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
		'payout_eligible_at' => "ALTER TABLE {$ledger_table} ADD payout_eligible_at DATETIME NULL",
		'payout_attempted_at'=> "ALTER TABLE {$ledger_table} ADD payout_attempted_at DATETIME NULL",
		'payout_error'       => "ALTER TABLE {$ledger_table} ADD payout_error TEXT NULL",
		'paid_out_at'        => "ALTER TABLE {$ledger_table} ADD paid_out_at DATETIME NULL",
		'payout_batch_id'    => "ALTER TABLE {$ledger_table} ADD payout_batch_id VARCHAR(64) NULL",
		'stripe_transfer_id' => "ALTER TABLE {$ledger_table} ADD stripe_transfer_id VARCHAR(191) NULL",
	);

	foreach ( $ledger_adds as $column => $sql ) {
		if ( ! in_array( $column, $ledger_columns, true ) ) {
			$wpdb->query( $sql );
		}
	}


	/*
	 * Masterclass launch-readiness compatibility patch.
	 *
	 * This block intentionally preserves existing records and adds missing columns
	 * required by the current registration, refund, reminder, payout, tax, and gate flows.
	 */
	$compat_tables = array(
		'registrations' => $registrations_table,
		'ledger'        => $ledger_table,
		'refunds'       => $refunds_table,
		'tax'           => $tax_table,
	);

	foreach ( $compat_tables as $compat_table ) {
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $compat_table ) ) ) {
			continue;
		}
	}

	$registration_columns = $wpdb->get_col( "DESC {$registrations_table}", 0 );
	if ( ! is_array( $registration_columns ) ) {
		$registration_columns = array();
	}

	$registration_compat_adds = array(
		'name'                 => "ALTER TABLE {$registrations_table} ADD name VARCHAR(255) NULL",
		'payment_intent_id'    => "ALTER TABLE {$registrations_table} ADD payment_intent_id VARCHAR(191) NULL",
		'promo_status'         => "ALTER TABLE {$registrations_table} ADD promo_status VARCHAR(64) NULL",
		'reminder_24h_sent_at' => "ALTER TABLE {$registrations_table} ADD reminder_24h_sent_at DATETIME NULL",
		'reminder_1h_sent_at'  => "ALTER TABLE {$registrations_table} ADD reminder_1h_sent_at DATETIME NULL",
	);

	foreach ( $registration_compat_adds as $column => $sql ) {
		if ( ! in_array( $column, $registration_columns, true ) ) {
			$wpdb->query( $sql );
		}
	}

	$ledger_columns = $wpdb->get_col( "DESC {$ledger_table}", 0 );
	if ( ! is_array( $ledger_columns ) ) {
		$ledger_columns = array();
	}

	$ledger_compat_adds = array(
		'payment_intent_id'          => "ALTER TABLE {$ledger_table} ADD payment_intent_id VARCHAR(191) NULL",
		'discount_cents'             => "ALTER TABLE {$ledger_table} ADD discount_cents INT NOT NULL DEFAULT 0",
		'estimated_stripe_fee_cents' => "ALTER TABLE {$ledger_table} ADD estimated_stripe_fee_cents INT NOT NULL DEFAULT 0",
		'paid_at'                    => "ALTER TABLE {$ledger_table} ADD paid_at DATETIME NULL",
	);

	foreach ( $ledger_compat_adds as $column => $sql ) {
		if ( ! in_array( $column, $ledger_columns, true ) ) {
			$wpdb->query( $sql );
		}
	}

	$refund_columns = $wpdb->get_col( "DESC {$refunds_table}", 0 );
	if ( ! is_array( $refund_columns ) ) {
		$refund_columns = array();
	}

	$refund_compat_adds = array(
		'payment_intent_id' => "ALTER TABLE {$refunds_table} ADD payment_intent_id VARCHAR(191) NULL",
		'refund_id'         => "ALTER TABLE {$refunds_table} ADD refund_id VARCHAR(191) NULL",
		'reason'            => "ALTER TABLE {$refunds_table} ADD reason VARCHAR(191) NULL",
		'error_message'     => "ALTER TABLE {$refunds_table} ADD error_message TEXT NULL",
		'updated_at'        => "ALTER TABLE {$refunds_table} ADD updated_at DATETIME NULL",
	);

	foreach ( $refund_compat_adds as $column => $sql ) {
		if ( ! in_array( $column, $refund_columns, true ) ) {
			$wpdb->query( $sql );
		}
	}

	$tax_columns = $wpdb->get_col( "DESC {$tax_table}", 0 );
	if ( ! is_array( $tax_columns ) ) {
		$tax_columns = array();
	}

	$tax_compat_adds = array(
		'tax_classification' => "ALTER TABLE {$tax_table} ADD tax_classification VARCHAR(100) NULL",
		'is_employee'        => "ALTER TABLE {$tax_table} ADD is_employee TINYINT(1) NOT NULL DEFAULT 0",
		'mailing_address'    => "ALTER TABLE {$tax_table} ADD mailing_address TEXT NULL",
	);

	foreach ( $tax_compat_adds as $column => $sql ) {
		if ( ! in_array( $column, $tax_columns, true ) ) {
			$wpdb->query( $sql );
		}
	}

	update_option( 'mrm_masterclass_db_version', self::DB_VERSION );
}
	public function add_cron_schedule($s){$s['mrm_masterclass_15min']=array('interval'=>900,'display'=>'Every 15 Minutes'); return $s;}
	private function now(){return gmdate('Y-m-d H:i:s');}

/**
 * Masterclass production helper layer.
 * Add this block inside LowBrass_MRM_Masterclass_Plugin, immediately after private function now().
 */
private function mrm_mc_admin_notice_redirect( $page, $code, $extra = array() ) {
	$args = array_merge(
		array( 'mrm_mc_notice' => sanitize_key( $code ) ),
		is_array( $extra ) ? $extra : array()
	);

	$this->mrm_mc_safe_admin_redirect( $page, $args );
}

private function mrm_mc_clean_text( $value ) {
	return sanitize_text_field( wp_unslash( $value ?? '' ) );
}

private function mrm_mc_clean_email( $value ) {
	return sanitize_email( wp_unslash( $value ?? '' ) );
}

private function mrm_mc_clean_html( $value ) {
	return wp_kses_post( wp_unslash( $value ?? '' ) );
}

private function mrm_mc_bool_post( $key ) {
	return ! empty( $_POST[ $key ] ) ? 1 : 0;
}

private function mrm_mc_selected_instruments_from_post() {
	$raw = isset( $_POST['instruments'] ) && is_array( $_POST['instruments'] )
		? wp_unslash( $_POST['instruments'] )
		: array();

	$out = array();

	foreach ( $raw as $item ) {
		$item = sanitize_key( $item );

		if ( '' !== $item ) {
			$out[] = $item;
		}
	}

	return wp_json_encode( array_values( array_unique( $out ) ) );
}

private function mrm_mc_datetime_from_local( $value ) {
	$value = sanitize_text_field( wp_unslash( $value ?? '' ) );

	if ( '' === $value ) {
		return '';
	}

	$value = str_replace( 'T', ' ', $value );

	if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
		$value .= ':00';
	}

	return $value;
}

private function mrm_mc_get_presenter( $presenter_id ) {
	global $wpdb;

	$table = $this->t( 'mrm_masterclass_presenters' );

	if ( ! $this->mrm_mc_table_exists( $table ) ) {
		return null;
	}

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d",
			absint( $presenter_id )
		)
	);
}

private function mrm_mc_get_event( $event_id ) {
	global $wpdb;

	$table = $this->t( 'mrm_masterclass_events' );

	if ( ! $this->mrm_mc_table_exists( $table ) ) {
		return null;
	}

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d",
			absint( $event_id )
		)
	);
}

private function mrm_mc_paid_count_for_event( $event_id ) {
	global $wpdb;

	$table = $this->t( 'mrm_masterclass_registrations' );

	if ( ! $this->mrm_mc_table_exists( $table ) ) {
		return 0;
	}

	return absint(
		$wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND payment_status = 'paid'",
				absint( $event_id )
			)
		)
	);
}

private function mrm_mc_available_seats_for_event( $event ) {
	if ( ! is_object( $event ) ) {
		return 0;
	}

	return max( 0, absint( $event->capacity ) - $this->mrm_mc_paid_count_for_event( $event->id ) );
}

private function mrm_mc_gate_url_for_token( $token ) {
	return home_url( '/?mrm_masterclass_gate=' . rawurlencode( $token ) );
}

private function mrm_mc_make_gate_token_pair() {
	$token = wp_generate_password( 48, false, false );

	return array(
		'token' => $token,
		'hash'  => hash( 'sha256', $token ),
		'url'   => $this->mrm_mc_gate_url_for_token( $token ),
	);
}

private function mrm_mc_estimated_stripe_fee_cents( $amount_cents ) {
	$settings = $this->settings();

	$percent = isset( $settings['stripe_fee_estimate_percent'] )
		? (float) $settings['stripe_fee_estimate_percent']
		: 2.9;

	$fixed = isset( $settings['stripe_fee_estimate_fixed'] )
		? absint( $settings['stripe_fee_estimate_fixed'] )
		: 30;

	return max( 0, (int) round( ( absint( $amount_cents ) * ( $percent / 100 ) ) + $fixed ) );
}


private function mrm_mc_presenter_default_percent() {
	$settings = $this->settings();

	$percent = isset( $settings['presenter_default_percent'] )
		? (float) $settings['presenter_default_percent']
		: 70.0;

	if ( $percent < 0 ) {
		$percent = 0;
	}

	if ( $percent > 100 ) {
		$percent = 100;
	}

	return $percent;
}

private function mrm_mc_presenter_share_percent() {
	return $this->mrm_mc_presenter_default_percent();
}

private function mrm_mc_presenter_payout_percent( $presenter_id ) {
	global $wpdb;

	$presenter_id = absint( $presenter_id );
	$table        = $this->t( 'mrm_masterclass_presenters' );
	$default      = $this->mrm_mc_presenter_default_percent();

	if ( $presenter_id <= 0 || ! $this->mrm_mc_table_exists( $table ) ) {
		return $default;
	}

	if ( ! method_exists( $this, 'mrm_mc_column_exists' ) || ! $this->mrm_mc_column_exists( $table, 'payout_percent' ) ) {
		return $default;
	}

	$value = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT payout_percent FROM {$table} WHERE id = %d",
			$presenter_id
		)
	);

	if ( null === $value || '' === $value ) {
		return $default;
	}

	$percent = (float) $value;

	if ( $percent < 0 ) {
		$percent = 0;
	}

	if ( $percent > 100 ) {
		$percent = 100;
	}

	return $percent;
}

private function mrm_mc_calculate_registration_shares( $gross_cents, $presenter_id ) {
	$gross_cents = absint( $gross_cents );
	$stripe_fee  = $this->mrm_mc_estimated_stripe_fee_cents( $gross_cents );
	$net_cents   = max( 0, $gross_cents - $stripe_fee );
	$percent     = $this->mrm_mc_presenter_payout_percent( $presenter_id );

	$presenter_share = (int) round( $net_cents * ( $percent / 100 ) );
	$platform_share  = max( 0, $net_cents - $presenter_share );

	return array(
		'gross_cents'           => $gross_cents,
		'stripe_fee_cents'      => $stripe_fee,
		'net_cents'             => $net_cents,
		'presenter_percent'     => $percent,
		'presenter_share_cents' => $presenter_share,
		'platform_share_cents'  => $platform_share,
	);
}

private function mrm_mc_terms_snapshot() {
	$settings = $this->settings();

	return array(
		'version'  => sanitize_text_field( $settings['terms_version'] ?? 'v1' ),
		'accepted' => true,
		'text'     => 'Masterclass purchase terms accepted at checkout. Access is provided through a protected gate link. Cancellation/refund policy follows the event cancellation rules stored in the Masterclass plugin.',
		'time'     => gmdate( 'c' ),
	);
}

private function mrm_mc_public_presenter_page_url( $presenter_page_id ) {
	$presenter_page_id = absint( $presenter_page_id );

	return $presenter_page_id > 0 ? get_permalink( $presenter_page_id ) : '';
}

private function mrm_mc_email_template( $heading, $content_html ) {
	return '<div style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif;color:#111;">'
		. '<div style="max-width:680px;margin:0 auto;padding:28px 16px;">'
		. '<div style="background:#fff;border:1px solid #ddd;border-radius:18px;padding:28px;box-shadow:0 8px 28px rgba(0,0,0,.08);">'
		. '<div style="text-align:center;margin-bottom:22px;"><strong style="font-size:20px;letter-spacing:.04em;">Low Brass Lessons</strong></div>'
		. '<h1 style="font-family:Georgia,serif;font-size:30px;line-height:1.1;margin:0 0 18px;">' . esc_html( $heading ) . '</h1>'
		. $content_html
		. '</div></div></div>';
}

private function mrm_mc_send_email_recorded( $type, $to, $subject, $body, $event_id = null, $registration_id = null ) {
	global $wpdb;

	$table    = $this->t( 'mrm_masterclass_email_log' );
	$settings = $this->settings();

	$headers = array(
		'Content-Type: text/html; charset=UTF-8',
		'From: Low Brass Lessons <' . sanitize_email( $settings['from_email'] ?? 'no-reply@lowbrass-lessons.com' ) . '>',
	);

	$status = 'sent';
	$error  = '';

	try {
		$sent = wp_mail(
			sanitize_email( $to ),
			sanitize_text_field( $subject ),
			$body,
			$headers
		);

		if ( ! $sent ) {
			$status = 'failed';
			$error  = 'wp_mail returned false.';
		}
	} catch ( \Throwable $e ) {
		$status = 'failed';
		$error  = $e->getMessage();
	}

	if ( $this->mrm_mc_table_exists( $table ) ) {
		$wpdb->insert(
			$table,
			array(
				'event_id'        => $event_id ? absint( $event_id ) : null,
				'registration_id' => $registration_id ? absint( $registration_id ) : null,
				'recipient_email' => sanitize_email( $to ),
				'email_type'      => sanitize_key( $type ),
				'subject'         => sanitize_text_field( $subject ),
				'status'          => $status,
				'error_message'   => $error,
				'sent_at'         => $this->now(),
			)
		);
	}

	if ( 'failed' === $status ) {
		$this->mrm_mc_debug_log(
			'Masterclass email failed.',
			array(
				'type'            => $type,
				'event_id'        => $event_id,
				'registration_id' => $registration_id,
				'error'           => $error,
			)
		);
	}

	return 'sent' === $status;
}

private function mrm_mc_confirmation_email_body( $event, $presenter, $registration ) {
	$gate          = esc_url( $registration->gate_url ?? '' );
	$terms_version = sanitize_text_field( $registration->terms_version ?? 'v1' );

	$content = '<p>Your masterclass registration is confirmed.</p>'
		. '<p><strong>Masterclass:</strong> ' . esc_html( $event->title ) . '<br>'
		. '<strong>Date/time:</strong> ' . esc_html( $event->start_time . ' ' . $event->timezone ) . '<br>'
		. '<strong>Presenter:</strong> ' . esc_html( $presenter->name ?? 'To be announced' ) . '<br>'
		. '<strong>Terms version:</strong> ' . esc_html( $terms_version ) . '</p>'
		. '<p><a href="' . $gate . '" style="display:inline-block;background:#111;color:#fff;padding:12px 18px;border-radius:999px;text-decoration:none;">Open protected access link</a></p>'
		. '<p>This gate link reveals the Google Meet link only during the allowed access window. Please save this email.</p>';

	return $this->mrm_mc_email_template( 'Masterclass Registration Confirmed', $content );
}

private function mrm_mc_reminder_email_body( $event, $presenter, $registration, $window_label ) {
	$gate = esc_url( $registration->gate_url ?? '' );

	$content = '<p>This is your ' . esc_html( $window_label ) . ' reminder for your upcoming Masterclass.</p>'
		. '<p><strong>Masterclass:</strong> ' . esc_html( $event->title ) . '<br>'
		. '<strong>Date/time:</strong> ' . esc_html( $event->start_time . ' ' . $event->timezone ) . '<br>'
		. '<strong>Presenter:</strong> ' . esc_html( $presenter->name ?? 'To be announced' ) . '</p>'
		. '<p><a href="' . $gate . '" style="display:inline-block;background:#111;color:#fff;padding:12px 18px;border-radius:999px;text-decoration:none;">Open protected access link</a></p>'
		. '<p>The access link will reveal the Google Meet link during the allowed access window.</p>';

	return $this->mrm_mc_email_template( 'Masterclass Reminder', $content );
}

private function mrm_mc_event_update_email_body( $event, $presenter, $registration ) {
	$gate = esc_url( $registration->gate_url ?? '' );

	$content = '<p>A Masterclass you registered for has been updated.</p>'
		. '<p><strong>Masterclass:</strong> ' . esc_html( $event->title ) . '<br>'
		. '<strong>Date/time:</strong> ' . esc_html( $event->start_time . ' ' . $event->timezone ) . '<br>'
		. '<strong>Presenter:</strong> ' . esc_html( $presenter->name ?? 'To be announced' ) . '</p>'
		. '<p><a href="' . $gate . '" style="display:inline-block;background:#111;color:#fff;padding:12px 18px;border-radius:999px;text-decoration:none;">Open protected access link</a></p>'
		. '<p>If this update no longer works for you, reply to this email to request assistance with a refund.</p>';

	return $this->mrm_mc_email_template( 'Masterclass Event Updated', $content );
}

private function mrm_mc_refund_completed_email_body( $event, $registration, $amount_cents ) {
	$content = '<p>Your Masterclass refund has been completed.</p>'
		. '<p><strong>Masterclass:</strong> ' . esc_html( $event->title ?? 'Masterclass' ) . '<br>'
		. '<strong>Refund amount:</strong> ' . esc_html( $this->cents_to_dollars( $amount_cents ) ) . '</p>'
		. '<p>The refund has been recorded in the Masterclass payment ledger.</p>';

	return $this->mrm_mc_email_template( 'Masterclass Refund Completed', $content );
}

private function mrm_mc_event_cancelled_email_body( $event, $registration, $amount_cents, $refund_status ) {
	$content = '<p>This Masterclass has been cancelled.</p>'
		. '<p><strong>Masterclass:</strong> ' . esc_html( $event->title ?? 'Masterclass' ) . '<br>'
		. '<strong>Date/time:</strong> ' . esc_html( ( $event->start_time ?? '' ) . ' ' . ( $event->timezone ?? '' ) ) . '</p>';

	if ( 'refunded' === $refund_status ) {
		$content .= '<p>Your registration has been automatically refunded for ' . esc_html( $this->cents_to_dollars( $amount_cents ) ) . '.</p>';
	} else {
		$content .= '<p>Your registration refund is recorded for manual review. We will follow up if any additional action is needed.</p>';
	}

	return $this->mrm_mc_email_template( 'Masterclass Cancelled', $content );
}

private function mrm_mc_send_event_update_notices( $event_id ) {
	global $wpdb;
	$regs_table       = $this->t( 'mrm_masterclass_registrations' );
	$events_table     = $this->t( 'mrm_masterclass_events' );
	$presenters_table = $this->t( 'mrm_masterclass_presenters' );
	if ( ! $this->mrm_mc_table_exists( $regs_table ) || ! $this->mrm_mc_table_exists( $events_table ) ) { return 0; }
	$event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$events_table} WHERE id = %d", absint( $event_id ) ) );
	if ( ! $event ) { return 0; }
	$presenter = $this->mrm_mc_table_exists( $presenters_table ) ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$presenters_table} WHERE id = %d", absint( $event->presenter_id ) ) ) : null;
	$registrations = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$regs_table} WHERE event_id = %d AND payment_status = 'paid'", absint( $event_id ) ) );
	$count = 0;
	foreach ( $registrations as $registration ) {
		if ( $this->mrm_mc_send_email_recorded( 'event_updated', $registration->email, 'Masterclass Updated — ' . $event->title, $this->mrm_mc_event_update_email_body( $event, $presenter, $registration ), $event->id, $registration->id ) ) { $count++; }
	}
	$wpdb->update( $events_table, array( 'last_update_notice_at' => $this->now(), 'updated_at' => $this->now() ), array( 'id' => absint( $event_id ) ) );
	return $count;
}

private function mrm_mc_refund_registration( $registration, $event, $reason = 'event_cancelled' ) {
	global $wpdb;
	$regs_table    = $this->t( 'mrm_masterclass_registrations' );
	$refunds_table = $this->t( 'mrm_masterclass_refunds' );
	$amount_cents = absint( $registration->amount_cents ?? 0 );
	$payment_intent_id = sanitize_text_field( $registration->payment_intent_id ?? ( $registration->stripe_payment_intent_id ?? '' ) );
	if ( '' === $payment_intent_id || $amount_cents <= 0 ) { return new WP_Error( 'mrm_masterclass_refund_missing_data', 'Refund data was incomplete.' ); }
	$refund_result = $this->mrm_mc_refund_payment_intent( $payment_intent_id, $amount_cents );
	if ( is_wp_error( $refund_result ) ) { return $refund_result; }
	$refund_id = sanitize_text_field( $refund_result['id'] ?? '' );
	$refund_status = sanitize_key( $refund_result['status'] ?? 'succeeded' );
	$wpdb->insert( $refunds_table, $this->mrm_mc_filter_data_for_table( $refunds_table, array( 'event_id' => absint( $event->id ), 'registration_id' => absint( $registration->id ), 'payment_intent_id' => $payment_intent_id, 'refund_id' => $refund_id, 'amount_cents' => $amount_cents, 'status' => $refund_status, 'reason' => sanitize_key( $reason ), 'error_message' => '', 'created_at' => $this->now(), 'updated_at' => $this->now() ) ) );
	$wpdb->update( $regs_table, array( 'payment_status' => 'refunded', 'updated_at' => $this->now() ), array( 'id' => absint( $registration->id ) ) );
	$this->mrm_mc_send_email_recorded( 'refund_completed', $registration->email, 'Masterclass Refund Completed — ' . sanitize_text_field( $event->title ?? 'Masterclass' ), $this->mrm_mc_refund_completed_email_body( $event, $registration, $amount_cents ), $event->id, $registration->id );
	return array( 'refund_id' => $refund_id, 'status' => $refund_status, 'amount_cents' => $amount_cents );
}

private function mrm_mc_send_confirmation_for_registration( $registration_id ) {
	global $wpdb;

	$regs       = $this->t( 'mrm_masterclass_registrations' );
	$events     = $this->t( 'mrm_masterclass_events' );
	$presenters = $this->t( 'mrm_masterclass_presenters' );

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$regs} WHERE id = %d",
			absint( $registration_id )
		)
	);

	if ( ! $row ) {
		return false;
	}

	$event = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$events} WHERE id = %d",
			absint( $row->event_id )
		)
	);

	$presenter = $event
		? $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$presenters} WHERE id = %d",
				absint( $event->presenter_id )
			)
		)
		: null;

	if ( ! $event ) {
		return false;
	}

	$subject = 'Purchase Confirmation — ' . $event->title;
	$body    = $this->mrm_mc_confirmation_email_body( $event, $presenter, $row );

	$sent = $this->mrm_mc_send_email_recorded(
		'confirmation',
		$row->email,
		$subject,
		$body,
		$event->id,
		$row->id
	);

	if ( $sent ) {
		$wpdb->update(
			$regs,
			array(
				'confirmation_sent'    => 1,
				'confirmation_sent_at' => $this->now(),
				'updated_at'           => $this->now(),
			),
			array( 'id' => $row->id )
		);
	}

	return $sent;
}

private function mrm_mc_public_event_payload( $row ) {
	if ( ! is_object( $row ) ) {
		return array();
	}

	return array(
		'id'                 => absint( $row->id ),
		'title'              => sanitize_text_field( $row->title ),
		'description_html'   => wp_kses_post( $row->description ?? '' ),
		'presenter_name'     => sanitize_text_field( $row->presenter_name ?? '' ),
		'presenter_page_url' => esc_url_raw( $row->presenter_page_url ?? '' ),
		'start_time'         => sanitize_text_field( $row->start_time ),
		'end_time'           => sanitize_text_field( $row->end_time ),
		'timezone'           => sanitize_text_field( $row->timezone ),
		'price_cents'        => absint( $row->price_cents ),
		'capacity'           => absint( $row->capacity ),
		'available_seats'    => isset( $row->available_seats )
			? max( 0, (int) $row->available_seats )
			: $this->mrm_mc_available_seats_for_event( $row ),
		'registration_open'  => ! empty( $row->registration_open ) ? 1 : 0,
		'status'             => sanitize_key( $row->status ),
	);
}



private function mrm_mc_debug_log( $message, $context = array() ) {
	if ( ! defined( 'WP_CONTENT_DIR' ) ) {
		return;
	}

	$file = trailingslashit( WP_CONTENT_DIR ) . 'masterclass-debug.log';

	$message = sanitize_text_field( (string) $message );

	$noise_patterns = array(
		'/constructor started safely/i',
		'/diagnostic checkpoint/i',
		'/wp_loaded/i',
		'/template_redirect/i',
		'/wordpress_shutdown_action/i',
		'/admin diagnostic checkpoint loaded/i',
	);

	foreach ( $noise_patterns as $pattern ) {
		if ( preg_match( $pattern, $message ) ) {
			return;
		}
	}

	$safe_context = array();

	if ( is_array( $context ) ) {
		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( preg_match( '/secret|token|password|private|authorization|cookie|nonce|client_secret|payment_secret|key|tin|ssn|ein/i', $key ) ) {
				$safe_context[ $key ] = '[redacted]';
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$value = (string) $value;

				if ( preg_match( '/sk_live_|sk_test_|pk_live_|pk_test_|whsec_|-----BEGIN|Bearer\s+/i', $value ) ) {
					$safe_context[ $key ] = '[redacted]';
					continue;
				}

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

	$line = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] ' . $message;

	if ( ! empty( $safe_context ) ) {
		$encoded = wp_json_encode( $safe_context );
		if ( false !== $encoded ) {
			$line .= ' | ' . $encoded;
		}
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

private function mrm_mc_table_columns( $table ) {
	global $wpdb;

	$table = sanitize_text_field( $table );

	if ( '' === $table || ! $this->mrm_mc_table_exists( $table ) ) {
		return array();
	}

	$columns = $wpdb->get_col( "DESC {$table}", 0 );

	return is_array( $columns ) ? $columns : array();
}

private function mrm_mc_column_exists( $table, $column ) {
	$column  = sanitize_key( $column );
	$columns = $this->mrm_mc_table_columns( $table );

	return in_array( $column, $columns, true );
}

private function mrm_mc_filter_data_for_table( $table, $data ) {
	$columns = $this->mrm_mc_table_columns( $table );

	if ( empty( $columns ) || ! is_array( $data ) ) {
		return array();
	}

	$filtered = array();

	foreach ( $data as $key => $value ) {
		if ( in_array( $key, $columns, true ) ) {
			$filtered[ $key ] = $value;
		}
	}

	return $filtered;
}

private function mrm_mc_payment_intent_column_for_registrations() {
	$table = $this->t( 'mrm_masterclass_registrations' );

	if ( $this->mrm_mc_column_exists( $table, 'payment_intent_id' ) ) {
		return 'payment_intent_id';
	}

	return 'stripe_payment_intent_id';
}

private function mrm_mc_render_notice_from_map( $notice_map ) {
	$notice = isset( $_GET['mrm_mc_notice'] ) ? sanitize_key( wp_unslash( $_GET['mrm_mc_notice'] ) ) : '';

	if ( '' === $notice || ! is_array( $notice_map ) || empty( $notice_map[ $notice ] ) ) {
		return;
	}

	$type    = isset( $notice_map[ $notice ][0] ) ? sanitize_html_class( $notice_map[ $notice ][0] ) : 'info';
	$message = isset( $notice_map[ $notice ][1] ) ? sanitize_text_field( $notice_map[ $notice ][1] ) : '';

	if ( '' === $message ) {
		return;
	}

	if ( ! in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ) {
		$type = 'info';
	}

	echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
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
	private function mrm_mc_get_stripe_keys() {
		$settings = $this->settings();

		$keys = array(
			'publishable_key' => sanitize_text_field( $settings['stripe_publishable_key'] ?? '' ),
			'secret_key'      => sanitize_text_field( $settings['stripe_secret_key'] ?? '' ),
			'webhook_secret'  => sanitize_text_field( $settings['stripe_webhook_secret'] ?? '' ),
		);

		if ( method_exists( $this, 'mrm_mc_get_secret_json' ) ) {
			$aws = $this->mrm_mc_get_secret_json( 'lowbrass/stripe/keys', 'mrm_masterclass_stripe_secret' );

			if ( is_array( $aws ) ) {
				foreach ( array( 'publishable_key', 'secret_key', 'webhook_secret' ) as $key ) {
					if ( ! empty( $aws[ $key ] ) ) {
						$keys[ $key ] = sanitize_text_field( $aws[ $key ] );
					}
				}
			}
		}

		if ( empty( $keys['publishable_key'] ) || empty( $keys['secret_key'] ) ) {
			return new WP_Error(
				'mrm_masterclass_stripe_keys_missing',
				'Masterclass payments are temporarily unavailable because Stripe is not fully configured.'
			);
		}

		return $keys;
	}
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

private function mrm_mc_google_event_body( $event, $presenter_email ) {
	$settings    = $this->settings();
	$calendar_id = ! empty( $event->calendar_id ) ? $event->calendar_id : ( $settings['masterclass_calendar_id'] ?? '' );

	$attendees = array();

	if ( is_email( $presenter_email ) ) {
		$attendees[] = array( 'email' => $presenter_email );
	}

	if ( ! empty( $event->proctor_email ) && is_email( $event->proctor_email ) ) {
		$attendees[] = array( 'email' => $event->proctor_email );
	}

	$description = wp_strip_all_tags( (string) ( $event->description ?? '' ) );

	if ( ! empty( $event->google_meet_url ) ) {
		$description .= "

Online attendance link: " . esc_url_raw( $event->google_meet_url );
	}

	return array(
		'summary'        => sanitize_text_field( $event->title ?? 'Masterclass' ),
		'description'    => $description,
		'start'          => array(
			'dateTime' => gmdate( 'c', strtotime( $event->start_time . ' UTC' ) ),
			'timeZone' => sanitize_text_field( $event->timezone ?? 'America/Phoenix' ),
		),
		'end'            => array(
			'dateTime' => gmdate( 'c', strtotime( $event->end_time . ' UTC' ) ),
			'timeZone' => sanitize_text_field( $event->timezone ?? 'America/Phoenix' ),
		),
		'attendees'      => $attendees,
		'conferenceData' => array(
			'createRequest' => array(
				'requestId'             => 'mrm-masterclass-' . absint( $event->id ) . '-' . wp_generate_password( 8, false, false ),
				'conferenceSolutionKey' => array(
					'type' => 'hangoutsMeet',
				),
			),
		),
	);
}

private function mrm_mc_google_insert_event( $event, $presenter_email ) {
	$settings    = $this->settings();
	$calendar_id = ! empty( $event->calendar_id ) ? $event->calendar_id : ( $settings['masterclass_calendar_id'] ?? '' );

	if ( ! $calendar_id ) {
		return new WP_Error( 'google_calendar_missing', 'Masterclass Google Calendar ID is missing.' );
	}

	$body = $this->mrm_mc_google_event_body( $event, $presenter_email );

	$url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $calendar_id ) . '/events?conferenceDataVersion=1&sendUpdates=all';

	$result = $this->mrm_mc_google_calendar_request( 'POST', $url, $body );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'id'       => sanitize_text_field( $result['id'] ?? '' ),
		'meet_url' => esc_url_raw( $this->mrm_mc_extract_meet_url( $result ) ),
		'raw'      => $result,
	);
}

private function mrm_mc_google_update_event( $event, $presenter_email ) {
	$settings    = $this->settings();
	$calendar_id = ! empty( $event->calendar_id ) ? $event->calendar_id : ( $settings['masterclass_calendar_id'] ?? '' );

	if ( empty( $calendar_id ) || empty( $event->google_event_id ) ) {
		return new WP_Error( 'google_update_missing_data', 'Google Calendar update skipped because the calendar ID or Google event ID is missing.' );
	}

	$body = $this->mrm_mc_google_event_body( $event, $presenter_email );
	unset( $body['conferenceData'] );

	$url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event->google_event_id ) . '?sendUpdates=all';

	$result = $this->mrm_mc_google_calendar_request( 'PATCH', $url, $body );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'id'       => sanitize_text_field( $result['id'] ?? $event->google_event_id ),
		'meet_url' => esc_url_raw( $this->mrm_mc_extract_meet_url( $result ) ?: ( $event->google_meet_url ?? '' ) ),
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
	$settings    = $this->settings();
	$calendar_id = sanitize_text_field( $settings['masterclass_calendar_id'] ?? '' );

	$google_event_id = sanitize_text_field( $google_event_id );

	if ( '' === $calendar_id || '' === $google_event_id ) {
		return new WP_Error(
			'google_cancel_missing_data',
			'Google Calendar cancellation skipped because the calendar ID or Google event ID is missing.'
		);
	}

	$url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $google_event_id ) . '?sendUpdates=all';

	$result = $this->mrm_mc_google_calendar_request( 'DELETE', $url, null );

	if ( is_wp_error( $result ) ) {
		$this->mrm_mc_debug_log(
			'Google Calendar cancellation failed.',
			array(
				'google_event_id' => '[present]',
				'error'           => $result->get_error_message(),
			)
		);

		return $result;
	}

	return true;
}
private function mrm_mc_stripe_request( $method, $path, $body = array() ) {
	$keys = $this->mrm_mc_get_stripe_keys();

	if ( is_wp_error( $keys ) ) {
		$this->mrm_mc_debug_log(
			'Stripe request blocked because keys are missing.',
			array( 'path' => $path )
		);

		return $keys;
	}

	$url = 'https://api.stripe.com/v1/' . ltrim( $path, '/' );

	$args = array(
		'method'  => strtoupper( $method ),
		'timeout' => 25,
		'headers' => array(
			'Authorization' => 'Bearer ' . $keys['secret_key'],
		),
	);

	if ( ! empty( $body ) ) {
		$args['body'] = $body;
	}

	$response = wp_remote_request( $url, $args );

	if ( is_wp_error( $response ) ) {
		$this->mrm_mc_debug_log(
			'Stripe API request failed at HTTP layer.',
			array(
				'path'  => $path,
				'error' => $response->get_error_message(),
			)
		);

		return new WP_Error(
			'mrm_masterclass_stripe_http_failed',
			'Payment setup could not be completed. Please try again.'
		);
	}

	$code = wp_remote_retrieve_response_code( $response );
	$json = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code < 200 || $code >= 300 ) {
		$message = is_array( $json ) && ! empty( $json['error']['message'] )
			? sanitize_text_field( $json['error']['message'] )
			: 'Stripe returned an error.';

		$this->mrm_mc_debug_log(
			'Stripe API request returned an error.',
			array(
				'path'    => $path,
				'status'  => $code,
				'message' => $message,
			)
		);

		return new WP_Error(
			'mrm_masterclass_stripe_api_error',
			'Payment setup could not be completed. Please try again.'
		);
	}

	if ( ! is_array( $json ) ) {
		$this->mrm_mc_debug_log(
			'Stripe API response was not valid JSON.',
			array( 'path' => $path )
		);

		return new WP_Error(
			'mrm_masterclass_stripe_bad_response',
			'Payment setup could not be completed. Please try again.'
		);
	}

	return $json;
}
private function mrm_mc_create_payment_intent( $event, $email, $terms ) {
		return $this->mrm_mc_stripe_request(
			'POST',
			'payment_intents',
			array(
				'amount'                                  => absint( $event->price_cents ),
				'currency'                                => 'usd',
				'payment_method_types[]'                  => 'card',
				'metadata[mrm_masterclass_event_id]'      => absint( $event->id ),
				'metadata[mrm_masterclass_customer_email]'=> sanitize_email( $email ),
				'metadata[mrm_product_type]'              => 'masterclass',
				'metadata[source_flow]'                   => 'masterclass_registration',
				'metadata[terms_version]'                 => sanitize_text_field( $terms['version'] ?? 'v1' ),
				'metadata[terms_accepted]'                => ! empty( $terms['accepted'] ) ? '1' : '0',
			)
		);
	}
	private function mrm_mc_retrieve_payment_intent( $payment_intent_id ) {
		$payment_intent_id = sanitize_text_field( $payment_intent_id );

		if ( '' === $payment_intent_id || ! preg_match( '/^pi_/', $payment_intent_id ) ) {
			return new WP_Error(
				'mrm_masterclass_invalid_payment_intent',
				'Payment verification failed because the payment reference was invalid.'
			);
		}

		return $this->mrm_mc_stripe_request(
			'GET',
			'payment_intents/' . rawurlencode( $payment_intent_id )
		);
	}
	private function mrm_mc_refund_payment_intent( $payment_intent_id, $amount_cents ) {
		$payment_intent_id = sanitize_text_field( $payment_intent_id );
		$amount_cents      = absint( $amount_cents );

		if ( '' === $payment_intent_id || $amount_cents <= 0 ) {
			return new WP_Error(
				'mrm_masterclass_refund_invalid_data',
				'Refund could not be created because the payment reference or amount was invalid.'
			);
		}

		$result = $this->mrm_mc_stripe_request(
			'POST',
			'refunds',
			array(
				'payment_intent'   => $payment_intent_id,
				'amount'           => $amount_cents,
				'reason'           => 'requested_by_customer',
				'metadata[source]' => 'mrm_masterclass_event_cancellation',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

	public function send_reminders() {
	$this->mrm_mc_run_reminder_cron();
}

public function mrm_mc_run_reminder_cron() {
	global $wpdb;

	$events_table     = $this->t( 'mrm_masterclass_events' );
	$regs_table       = $this->t( 'mrm_masterclass_registrations' );
	$presenters_table = $this->t( 'mrm_masterclass_presenters' );

	if (
		! $this->mrm_mc_table_exists( $events_table )
		|| ! $this->mrm_mc_table_exists( $regs_table )
		|| ! $this->mrm_mc_table_exists( $presenters_table )
	) {
		$this->mrm_mc_debug_log( 'Masterclass reminder cron skipped because required tables are missing.' );
		return;
	}

	$now      = time();
	$from_24h = gmdate( 'Y-m-d H:i:s', $now + ( 23 * HOUR_IN_SECONDS ) );
	$to_24h   = gmdate( 'Y-m-d H:i:s', $now + ( 25 * HOUR_IN_SECONDS ) );
	$from_1h  = gmdate( 'Y-m-d H:i:s', $now + ( 45 * MINUTE_IN_SECONDS ) );
	$to_1h    = gmdate( 'Y-m-d H:i:s', $now + ( 75 * MINUTE_IN_SECONDS ) );

	$has_24h_col = method_exists( $this, 'mrm_mc_column_exists' ) && $this->mrm_mc_column_exists( $regs_table, 'reminder_24h_sent_at' );
	$has_1h_col  = method_exists( $this, 'mrm_mc_column_exists' ) && $this->mrm_mc_column_exists( $regs_table, 'reminder_1h_sent_at' );

	$col_24 = $has_24h_col ? 'reminder_24h_sent_at' : 'reminder_24_sent_at';
	$col_1  = $has_1h_col ? 'reminder_1h_sent_at' : 'reminder_1_sent_at';

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT r.*, e.title, e.start_time, e.end_time, e.timezone, e.presenter_id, p.name AS presenter_name
			 FROM {$regs_table} r
			 INNER JOIN {$events_table} e ON e.id = r.event_id
			 LEFT JOIN {$presenters_table} p ON p.id = e.presenter_id
			 WHERE r.payment_status = 'paid'
			   AND e.status = 'scheduled'
			   AND (
					(e.start_time BETWEEN %s AND %s AND r.{$col_24} IS NULL)
					OR
					(e.start_time BETWEEN %s AND %s AND r.{$col_1} IS NULL)
			   )
			 LIMIT 200",
			$from_24h,
			$to_24h,
			$from_1h,
			$to_1h
		)
	);

	if ( ! $rows ) {
		return;
	}

	foreach ( $rows as $row ) {
		$event = (object) array(
			'id'         => absint( $row->event_id ),
			'title'      => $row->title,
			'start_time' => $row->start_time,
			'end_time'   => $row->end_time,
			'timezone'   => $row->timezone,
		);

		$presenter = (object) array(
			'name' => $row->presenter_name,
		);

		$registration = (object) $row;
		$start_ts     = strtotime( $row->start_time . ' UTC' );
		$seconds_out  = $start_ts - $now;

		if ( $seconds_out >= 23 * HOUR_IN_SECONDS && $seconds_out <= 25 * HOUR_IN_SECONDS && empty( $row->{$col_24} ) ) {
			$body = $this->mrm_mc_reminder_email_body( $event, $presenter, $registration, '24-hour' );

			$sent = $this->mrm_mc_send_email_recorded(
				'reminder_24h',
				$row->email,
				'Masterclass Reminder — ' . $row->title,
				$body,
				$row->event_id,
				$row->id
			);

			if ( $sent ) {
				$update = array(
					$col_24      => $this->now(),
					'updated_at' => $this->now(),
				);

				if ( method_exists( $this, 'mrm_mc_column_exists' ) && $this->mrm_mc_column_exists( $regs_table, 'reminder_24_sent' ) ) {
					$update['reminder_24_sent'] = 1;
				}

				$wpdb->update(
					$regs_table,
					$update,
					array( 'id' => absint( $row->id ) )
				);
			}
		}

		if ( $seconds_out >= 45 * MINUTE_IN_SECONDS && $seconds_out <= 75 * MINUTE_IN_SECONDS && empty( $row->{$col_1} ) ) {
			$body = $this->mrm_mc_reminder_email_body( $event, $presenter, $registration, '1-hour' );

			$sent = $this->mrm_mc_send_email_recorded(
				'reminder_1h',
				$row->email,
				'Masterclass Reminder — ' . $row->title,
				$body,
				$row->event_id,
				$row->id
			);

			if ( $sent ) {
				$update = array(
					$col_1       => $this->now(),
					'updated_at' => $this->now(),
				);

				if ( method_exists( $this, 'mrm_mc_column_exists' ) && $this->mrm_mc_column_exists( $regs_table, 'reminder_1_sent' ) ) {
					$update['reminder_1_sent'] = 1;
				}

				$wpdb->update(
					$regs_table,
					$update,
					array( 'id' => absint( $row->id ) )
				);
			}
		}
	}
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

	global $wpdb;

	$table = $this->t( 'mrm_masterclass_presenters' );

	if ( ! $this->mrm_mc_table_exists( $table ) ) {
		$this->mrm_mc_debug_log(
			'Presenter save failed because table is missing.',
			array( 'table' => $table )
		);

		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-presenters', 'presenter_table_missing' );
	}

	$id    = absint( $_POST['id'] ?? 0 );
	$name  = $this->mrm_mc_clean_text( $_POST['name'] ?? '' );
	$email = $this->mrm_mc_clean_email( $_POST['email'] ?? '' );

	if ( '' === $name || ! is_email( $email ) ) {
		$this->mrm_mc_admin_notice_redirect(
			'mrm-masterclass-presenters',
			'presenter_validation_failed',
			$id ? array( 'edit' => $id ) : array()
		);
	}

	$data = array(
		'name'                        => $name,
		'email'                       => $email,
		'city'                        => $this->mrm_mc_clean_text( $_POST['city'] ?? '' ),
		'state'                       => strtoupper( substr( $this->mrm_mc_clean_text( $_POST['state'] ?? '' ), 0, 2 ) ),
		'address'                     => $this->mrm_mc_clean_text( $_POST['address'] ?? '' ),
		'zip_code'                    => $this->mrm_mc_clean_text( $_POST['zip_code'] ?? '' ),
		'timezone'                    => $this->mrm_mc_clean_text( $_POST['timezone'] ?? 'America/Phoenix' ),
		'stripe_connected_account_id' => $this->mrm_mc_clean_text( $_POST['stripe_connected_account_id'] ?? '' ),
		'payout_percent'              => max( 0, min( 100, (float) ( $_POST['payout_percent'] ?? $this->mrm_mc_presenter_default_percent() ) ) ),
		'hire_date'                   => $this->mrm_mc_clean_text( $_POST['hire_date'] ?? '' ),
		'profile_image_url'           => esc_url_raw( wp_unslash( $_POST['profile_image_url'] ?? '' ) ),
		'short_description'           => $this->mrm_mc_clean_html( $_POST['short_description'] ?? '' ),
		'long_description'            => $this->mrm_mc_clean_html( $_POST['long_description'] ?? '' ),
		'bio'                         => $this->mrm_mc_clean_html( $_POST['bio'] ?? '' ),
		'instruments'                 => $this->mrm_mc_selected_instruments_from_post(),
		'updated_at'                  => $this->now(),
	);

	if ( $id > 0 ) {
		$result = $wpdb->update(
			$table,
			$data,
			array( 'id' => $id )
		);
	} else {
		$data['created_at'] = $this->now();

		$result = $wpdb->insert(
			$table,
			$data
		);

		$id = $result ? absint( $wpdb->insert_id ) : 0;
	}

	if ( false === $result ) {
		$this->mrm_mc_debug_log(
			'Presenter save database error.',
			array(
				'presenter_id' => $id,
				'db_error'     => $wpdb->last_error,
			)
		);

		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-presenters', 'presenter_save_failed' );
	}

	$this->mrm_mc_admin_notice_redirect(
		'mrm-masterclass-presenters',
		'presenter_saved',
		array( 'edit' => $id )
	);
}


public function handle_delete_presenter() {
	$this->must_admin();

	global $wpdb;

	$presenter_id = absint( $_POST['presenter_id'] ?? $_GET['presenter_id'] ?? 0 );

	if ( $presenter_id <= 0 ) {
		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-presenters', 'presenter_missing_id' );
	}

	$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_delete_presenter_' . $presenter_id );

	$presenters = $this->t( 'mrm_masterclass_presenters' );
	$events     = $this->t( 'mrm_masterclass_events' );

	if ( ! $this->mrm_mc_table_exists( $presenters ) || ! $this->mrm_mc_table_exists( $events ) ) {
		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-presenters', 'presenter_delete_table_missing' );
	}

	$linked_events = absint(
		$wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$events} WHERE presenter_id = %d",
				$presenter_id
			)
		)
	);

	if ( $linked_events > 0 ) {
		$result = $wpdb->update(
			$presenters,
			array(
				'status'     => 'inactive',
				'updated_at' => $this->now(),
			),
			array( 'id' => $presenter_id )
		);

		if ( false === $result ) {
			$this->mrm_mc_debug_log(
				'Presenter archive failed.',
				array(
					'presenter_id' => $presenter_id,
					'db_error'     => $wpdb->last_error,
				)
			);

			$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-presenters', 'presenter_archive_failed' );
		}

		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-presenters', 'presenter_archived' );
	}

	$result = $wpdb->delete(
		$presenters,
		array( 'id' => $presenter_id )
	);

	if ( false === $result ) {
		$this->mrm_mc_debug_log(
			'Presenter delete failed.',
			array(
				'presenter_id' => $presenter_id,
				'db_error'     => $wpdb->last_error,
			)
		);

		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-presenters', 'presenter_delete_failed' );
	}

	$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-presenters', 'presenter_deleted' );
}

public function handle_save_event() {
	$this->must_admin();
	$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_save_event' );

	global $wpdb;

	$events_table     = $this->t( 'mrm_masterclass_events' );
	$presenters_table = $this->t( 'mrm_masterclass_presenters' );

	if ( ! $this->mrm_mc_table_exists( $events_table ) || ! $this->mrm_mc_table_exists( $presenters_table ) ) {
		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_table_missing' );
	}

	$event_id  = absint( $_POST['event_id'] ?? 0 );
	$is_edit   = $event_id > 0;
	$old_event = $is_edit ? $this->mrm_mc_get_event( $event_id ) : null;

	if ( $is_edit && ! $old_event ) {
		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_cancel_not_found' );
	}

	$title        = $this->mrm_mc_clean_text( $_POST['title'] ?? '' );
	$description  = $this->mrm_mc_clean_html( $_POST['description'] ?? '' );
	$presenter_id = absint( $_POST['presenter_id'] ?? 0 );
	$proctor      = $this->mrm_mc_clean_email( $_POST['proctor_email'] ?? '' );
	$start_time   = $this->mrm_mc_datetime_from_local( $_POST['start_time'] ?? '' );
	$end_time     = $this->mrm_mc_datetime_from_local( $_POST['end_time'] ?? '' );
	$timezone     = $this->mrm_mc_clean_text( $_POST['timezone'] ?? 'America/Phoenix' );
	$price_cents  = max( 0, absint( $_POST['price_cents'] ?? 0 ) );
	$capacity     = max( 1, absint( $_POST['capacity'] ?? 1 ) );
	$status       = sanitize_key( wp_unslash( $_POST['status'] ?? 'scheduled' ) );
	$allowed_statuses = array( 'draft', 'scheduled', 'cancelled', 'completed', 'archived' );
	if ( ! in_array( $status, $allowed_statuses, true ) ) { $status = 'scheduled'; }
	if ( '' === $title || $presenter_id <= 0 || '' === $start_time || '' === $end_time || '' === $timezone ) { $this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_validation_failed' ); }
	if ( strtotime( $end_time ) <= strtotime( $start_time ) ) { $this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_time_invalid' ); }
	if ( $price_cents <= 0 ) { $this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_price_invalid' ); }
	$presenter = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$presenters_table} WHERE id = %d",$presenter_id));
	if ( ! $presenter ) { $this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_presenter_missing' ); }
	$data = array('title'=>$title,'description'=>$description,'presenter_id'=>$presenter_id,'presenter_email'=>sanitize_email( $presenter->email ?? '' ),'proctor_email'=>$proctor,'start_time'=>$start_time,'end_time'=>$end_time,'timezone'=>$timezone,'price_cents'=>$price_cents,'capacity'=>$capacity,'status'=>$status,'registration_open'=>$this->mrm_mc_bool_post( 'registration_open' ),'updated_at'=>$this->now());
	$data = $this->mrm_mc_filter_data_for_table( $events_table, $data );
	if ( $is_edit ) { $result=$wpdb->update($events_table,$data,array('id'=>$event_id)); } else { $data['created_at']=$this->now(); if ( method_exists( $this, 'mrm_mc_column_exists' ) && $this->mrm_mc_column_exists( $events_table, 'refund_request_token' ) ) { $data['refund_request_token']=wp_generate_password( 32, false, false ); } $result=$wpdb->insert($events_table,$data); $event_id=$result?absint($wpdb->insert_id):0; }
	if ( false === $result || $event_id <= 0 ) { $this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_save_failed' ); }
	$event = $this->mrm_mc_get_event( $event_id ); if ( ! $event ) { $this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_saved_google_reload_failed', array( 'event_id' => $event_id ) ); }
	$settings=$this->settings(); $calendar_id=sanitize_text_field( $settings['masterclass_calendar_id'] ?? '' );
	if ( '' === $calendar_id ) { $wpdb->update($events_table,array('google_last_error'=>'Masterclass Google Calendar ID is missing.','updated_at'=>$this->now()),array('id'=>$event_id)); if ( $is_edit ) { $this->mrm_mc_send_event_update_notices( $event_id ); } $this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_saved_google_missing_calendar', array( 'event_id' => $event_id ) ); }
	$google_result = ( $is_edit && ! empty( $event->google_event_id ) && method_exists( $this, 'mrm_mc_google_update_event' ) ) ? $this->mrm_mc_google_update_event( $event, sanitize_email( $presenter->email ?? '' ) ) : $this->mrm_mc_google_insert_event( $event, sanitize_email( $presenter->email ?? '' ) );
	if ( is_wp_error( $google_result ) ) { $wpdb->update($events_table,array('google_last_error'=>$google_result->get_error_message(),'updated_at'=>$this->now()),array('id'=>$event_id)); if ( $is_edit ) { $this->mrm_mc_send_event_update_notices( $event_id ); } $this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_saved_google_failed', array( 'event_id' => $event_id ) ); }
	$wpdb->update($events_table,array('google_event_id'=>sanitize_text_field( $google_result['id'] ?? ( $event->google_event_id ?? '' ) ),'google_meet_url'=>esc_url_raw( $google_result['meet_url'] ?? ( $event->google_meet_url ?? '' ) ),'google_last_error'=>'','updated_at'=>$this->now()),array('id'=>$event_id));
	if ( $is_edit ) { $this->mrm_mc_send_event_update_notices( $event_id ); $this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_updated_google_success', array( 'event_id' => $event_id ) ); }
	$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_saved_google_success', array( 'event_id' => $event_id ) );
}

public function handle_cancel_event() {
	$this->must_admin();
	global $wpdb;
	$event_id = absint( $_POST['event_id'] ?? $_GET['event_id'] ?? 0 );
	if ( $event_id <= 0 ) { $this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_cancel_missing_id' ); }
	$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_cancel_event_' . $event_id );
	$events_table  = $this->t( 'mrm_masterclass_events' ); $regs_table=$this->t('mrm_masterclass_registrations'); $refunds_table=$this->t('mrm_masterclass_refunds');
	if ( ! $this->mrm_mc_table_exists( $events_table ) || ! $this->mrm_mc_table_exists( $regs_table ) || ! $this->mrm_mc_table_exists( $refunds_table ) ) { $this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_cancel_table_missing' ); }
	$event = $this->mrm_mc_get_event( $event_id ); if ( ! $event ) { $this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_cancel_not_found' ); }
	if ( in_array( sanitize_key( $event->status ), array( 'deleted', 'cancelled' ), true ) ) { $this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_cancel_already_deleted' ); }
	$paid_regs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$regs_table} WHERE event_id = %d AND payment_status = 'paid'",$event_id));
	$refund_success_count=0; $refund_failure_count=0;
	foreach((array)$paid_regs as $registration){$refund_result=$this->mrm_mc_refund_registration($registration,$event,'event_cancelled'); if(is_wp_error($refund_result)){$refund_failure_count++;continue;} $refund_success_count++; $this->mrm_mc_send_email_recorded('event_cancelled',$registration->email,'Masterclass Cancelled — '.sanitize_text_field( $event->title ),$this->mrm_mc_event_cancelled_email_body( $event, $registration, absint( $registration->amount_cents ), 'refunded' ),$event->id,$registration->id);} 
	$google_cancel_error=''; if(!empty($event->google_event_id)){ $google_cancel=$this->mrm_mc_google_cancel_event($event->google_event_id); if(is_wp_error($google_cancel)){$google_cancel_error=$google_cancel->get_error_message();}}
	$wpdb->update($events_table,array('status'=>'deleted','registration_open'=>0,'cancellation_reason'=>'Admin cancelled event. Paid registrations were automatically refunded when possible.','google_last_error'=>$google_cancel_error,'updated_at'=>$this->now()),array('id'=>$event_id));
	if($refund_failure_count>0){$this->mrm_mc_admin_notice_redirect('mrm-masterclass-events','event_cancel_refund_failures',array('event_id'=>$event_id,'success'=>$refund_success_count,'failed'=>$refund_failure_count));}
	if($refund_success_count>0){$this->mrm_mc_admin_notice_redirect('mrm-masterclass-events','event_cancel_refunds_success',array('event_id'=>$event_id,'refunded'=>$refund_success_count));}
	$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_cancel_no_paid_attendees', array( 'event_id' => $event_id ) );
}

public function handle_mark_payouts_paid() {
	$this->must_admin();
	$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_mark_payouts_paid' );

	global $wpdb;

	$ledger_table = $this->t( 'mrm_masterclass_payment_ledger' );

	if ( ! $this->mrm_mc_table_exists( $ledger_table ) ) {
		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-payouts', 'payout_table_missing' );
	}

	$ledger_ids = isset( $_POST['ledger_ids'] ) && is_array( $_POST['ledger_ids'] )
		? array_map( 'absint', wp_unslash( $_POST['ledger_ids'] ) )
		: array();

	$ledger_ids = array_values( array_filter( array_unique( $ledger_ids ) ) );

	if ( empty( $ledger_ids ) ) {
		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-payouts', 'payout_batch_empty' );
	}

	$placeholders = implode( ',', array_fill( 0, count( $ledger_ids ), '%d' ) );

	$data = array(
		'status'      => 'paid_out',
		'paid_at'     => $this->now(),
		'paid_out_at' => $this->now(),
		'updated_at'  => $this->now(),
	);

	$data = $this->mrm_mc_filter_data_for_table( $ledger_table, $data );

	$set_parts = array();

	foreach ( $data as $column => $value ) {
		$set_parts[] = "{$column} = %s";
	}

	$sql_values = array_values( $data );

	foreach ( $ledger_ids as $ledger_id ) {
		$sql_values[] = $ledger_id;
	}

	$sql = "UPDATE {$ledger_table} SET " . implode( ', ', $set_parts ) . " WHERE status = 'payable' AND id IN ({$placeholders})";

	$result = $wpdb->query(
		$wpdb->prepare(
			$sql,
			$sql_values
		)
	);

	if ( false === $result ) {
		$this->mrm_mc_debug_log(
			'Presenter payout batch mark-paid failed.',
			array(
				'db_error' => $wpdb->last_error,
				'count'    => count( $ledger_ids ),
			)
		);

		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-payouts', 'payout_batch_failed' );
	}

	$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-payouts', 'payout_batch_paid' );
}

public function handle_mark_payout_paid() {
	$this->must_admin();

	global $wpdb;

	$ledger_id = absint( $_POST['ledger_id'] ?? $_GET['ledger_id'] ?? 0 );

	if ( $ledger_id <= 0 ) {
		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-payouts', 'payout_missing_id' );
	}

	$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_mark_payout_paid_' . $ledger_id );

	$ledger_table = $this->t( 'mrm_masterclass_payment_ledger' );

	if ( ! $this->mrm_mc_table_exists( $ledger_table ) ) {
		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-payouts', 'payout_table_missing' );
	}

	$data = array(
		'status'      => 'paid_out',
		'paid_at'     => $this->now(),
		'paid_out_at' => $this->now(),
		'updated_at'  => $this->now(),
	);

	$data = $this->mrm_mc_filter_data_for_table( $ledger_table, $data );

	$result = $wpdb->update(
		$ledger_table,
		$data,
		array(
			'id'     => $ledger_id,
			'status' => 'payable',
		)
	);

	if ( false === $result ) {
		$this->mrm_mc_debug_log(
			'Presenter payout mark-paid failed.',
			array(
				'ledger_id' => $ledger_id,
				'db_error'  => $wpdb->last_error,
			)
		);

		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-payouts', 'payout_mark_paid_failed' );
	}

	if ( 0 === $result ) {
		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-payouts', 'payout_not_payable' );
	}

	$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-payouts', 'payout_marked_paid' );
}

public function handle_save_tax_profile() {
	$this->must_admin();

	global $wpdb;

	$table = $this->t( 'mrm_masterclass_presenter_tax_profiles' );

	if ( ! $this->mrm_mc_table_exists( $table ) ) {
		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-tax-profiles', 'tax_table_missing' );
	}

	$profile_id   = absint( $_POST['profile_id'] ?? 0 );
	$presenter_id = absint( $_POST['presenter_id'] ?? 0 );
	$nonce        = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ?? '' ) );

	$valid_nonce = false;

	if ( wp_verify_nonce( $nonce, 'mrm_masterclass_save_tax_profile' ) ) {
		$valid_nonce = true;
	}

	if ( $presenter_id > 0 && wp_verify_nonce( $nonce, 'mrm_masterclass_save_tax_profile_' . $presenter_id ) ) {
		$valid_nonce = true;
	}

	if ( ! $valid_nonce ) {
		wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'mrm-masterclass' ) );
	}

	if ( $presenter_id <= 0 ) {
		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-tax-profiles', 'tax_presenter_missing' );
	}

	$tin_last4 = preg_replace(
		'/[^0-9]/',
		'',
		sanitize_text_field( wp_unslash( $_POST['tin_last4'] ?? '' ) )
	);

	if ( strlen( $tin_last4 ) > 4 ) {
		$tin_last4 = substr( $tin_last4, -4 );
	}

	$data = array(
		'presenter_id'       => $presenter_id,
		'legal_name'         => sanitize_text_field( wp_unslash( $_POST['legal_name'] ?? '' ) ),
		'business_name'      => sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) ),
		'email'              => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
		'tin_last4'          => $tin_last4,
		'tin_type'           => sanitize_text_field( wp_unslash( $_POST['tin_type'] ?? '' ) ),
		'tax_classification' => sanitize_text_field( wp_unslash( $_POST['tax_classification'] ?? '' ) ),
		'w9_received'        => ! empty( $_POST['w9_received'] ) ? 1 : 0,
		'w9_received_date'   => sanitize_text_field( wp_unslash( $_POST['w9_received_date'] ?? '' ) ),
		'address_line1'      => sanitize_text_field( wp_unslash( $_POST['address_line1'] ?? '' ) ),
		'address_line2'      => sanitize_text_field( wp_unslash( $_POST['address_line2'] ?? '' ) ),
		'city'               => sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) ),
		'state'              => sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) ),
		'zip'                => sanitize_text_field( wp_unslash( $_POST['zip'] ?? '' ) ),
		'mailing_address'    => sanitize_textarea_field( wp_unslash( $_POST['mailing_address'] ?? '' ) ),
		'is_1099_eligible'   => ! empty( $_POST['is_1099_eligible'] ) ? 1 : 0,
		'is_employee'        => ! empty( $_POST['is_employee'] ) ? 1 : 0,
		'exclude_from_1099'  => ! empty( $_POST['exclude_from_1099'] ) ? 1 : 0,
		'notes'              => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
		'updated_at'         => $this->now(),
	);

	if ( method_exists( $this, 'mrm_mc_filter_data_for_table' ) ) {
		$data = $this->mrm_mc_filter_data_for_table( $table, $data );
	}

	$existing_id = absint(
		$wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE presenter_id = %d LIMIT 1",
				$presenter_id
			)
		)
	);

	if ( $profile_id <= 0 && $existing_id > 0 ) {
		$profile_id = $existing_id;
	}

	if ( $profile_id > 0 ) {
		$result = $wpdb->update(
			$table,
			$data,
			array( 'id' => $profile_id )
		);
	} else {
		$data['created_at'] = $this->now();

		if ( method_exists( $this, 'mrm_mc_filter_data_for_table' ) ) {
			$data = $this->mrm_mc_filter_data_for_table( $table, $data );
		}

		$result = $wpdb->insert(
			$table,
			$data
		);
	}

	if ( false === $result ) {
		$this->mrm_mc_debug_log(
			'Masterclass tax profile save failed.',
			array(
				'profile_id'   => $profile_id,
				'presenter_id' => $presenter_id,
				'db_error'     => $wpdb->last_error,
			)
		);

		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-tax-profiles', 'tax_save_failed' );
	}

	$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-tax-profiles', 'tax_saved' );
}

public function handle_emergency_cancel_event() {
	$this->must_admin();
	$event_id = absint( $_POST['event_id'] ?? $_GET['event_id'] ?? 0 );
	if ( $event_id <= 0 ) { $this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-events', 'event_cancel_missing_id' ); }
	$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_emergency_cancel_event_' . $event_id );
	$this->mrm_mc_debug_log( 'Emergency Masterclass cancellation requested.', array( 'event_id' => $event_id ) );
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'mrm_masterclass_cancel_event_' . $event_id );
	$_GET['event_id'] = $event_id; $_POST['event_id'] = $event_id;
	$this->handle_cancel_event();
}

public function handle_create_presenter_page() {
	$this->must_admin();

	global $wpdb;

	$presenter_id = absint( $_POST['presenter_id'] ?? $_GET['presenter_id'] ?? 0 );

	if ( $presenter_id <= 0 ) {
		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-presenters', 'presenter_missing_id' );
	}

	$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_create_presenter_page_' . $presenter_id );

	$presenter = $this->mrm_mc_get_presenter( $presenter_id );

	if ( ! $presenter ) {
		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-presenters', 'presenter_not_found' );
	}

	$presenters_table = $this->t( 'mrm_masterclass_presenters' );

	$instruments = array();

	if ( ! empty( $presenter->instruments ) ) {
		$decoded = json_decode( $presenter->instruments, true );

		if ( is_array( $decoded ) ) {
			$instruments = array_map( 'sanitize_text_field', $decoded );
		}
	}

	$content = '<div class="mrm-masterclass-presenter-page">';

	if ( ! empty( $presenter->profile_image_url ) ) {
		$content .= '<figure class="mrm-masterclass-presenter-image">';
		$content .= '<img src="' . esc_url( $presenter->profile_image_url ) . '" alt="' . esc_attr( $presenter->name ) . '" />';
		$content .= '</figure>';
	}

	$content .= '<h1>' . esc_html( $presenter->name ) . '</h1>';

	if ( ! empty( $presenter->short_description ) ) {
		$content .= '<div class="mrm-masterclass-presenter-short">';
		$content .= wp_kses_post( wpautop( $presenter->short_description ) );
		$content .= '</div>';
	}

	if ( ! empty( $instruments ) ) {
		$content .= '<p><strong>Instruments:</strong> ' . esc_html( implode( ', ', $instruments ) ) . '</p>';
	}

	if ( ! empty( $presenter->long_description ) ) {
		$content .= '<div class="mrm-masterclass-presenter-long">';
		$content .= wp_kses_post( wpautop( $presenter->long_description ) );
		$content .= '</div>';
	}

	if ( ! empty( $presenter->bio ) ) {
		$content .= '<div class="mrm-masterclass-presenter-bio">';
		$content .= wp_kses_post( wpautop( $presenter->bio ) );
		$content .= '</div>';
	}

	$content .= '</div>';

	$page_title = $presenter->name . ' — Masterclass Presenter';

	$page_data = array(
		'post_title'   => $page_title,
		'post_content' => $content,
		'post_status'  => 'publish',
		'post_type'    => 'page',
	);

	$page_id = absint( $presenter->presenter_page_id ?? 0 );

	if ( $page_id > 0 && get_post( $page_id ) ) {
		$page_data['ID'] = $page_id;
		$result = wp_update_post( $page_data, true );
	} else {
		$result = wp_insert_post( $page_data, true );
	}

	if ( is_wp_error( $result ) ) {
		$this->mrm_mc_debug_log(
			'Presenter page generation failed.',
			array(
				'presenter_id' => $presenter_id,
				'error'        => $result->get_error_message(),
			)
		);

		$this->mrm_mc_admin_notice_redirect( 'mrm-masterclass-presenters', 'presenter_page_failed' );
	}

	$page_id = absint( $result );

	$wpdb->update(
		$presenters_table,
		array(
			'presenter_page_id' => $page_id,
			'updated_at'        => $this->now(),
		),
		array( 'id' => $presenter_id )
	);

	$this->mrm_mc_debug_log(
		'Presenter page generated or updated.',
		array(
			'presenter_id' => $presenter_id,
			'page_id'      => $page_id,
		)
	);

	$this->mrm_mc_admin_notice_redirect(
		'mrm-masterclass-presenters',
		'presenter_page_saved',
		array(
			'edit'    => $presenter_id,
			'page_id' => $page_id,
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

public function mrm_mc_handle_gate_request() {
	if ( empty( $_GET['mrm_masterclass_gate'] ) ) {
		return;
	}

	$token = sanitize_text_field( wp_unslash( $_GET['mrm_masterclass_gate'] ) );

	if ( '' === $token || strlen( $token ) < 20 ) {
		$this->mrm_mc_render_gate_page(
			'Invalid Masterclass Access Link',
			'This access link is invalid. Please use the protected link from your confirmation email.'
		);
		exit;
	}

	$hash = hash( 'sha256', $token );

	global $wpdb;

	$regs_table   = $this->t( 'mrm_masterclass_registrations' );
	$events_table = $this->t( 'mrm_masterclass_events' );

	if ( ! $this->mrm_mc_table_exists( $regs_table ) || ! $this->mrm_mc_table_exists( $events_table ) ) {
		$this->mrm_mc_debug_log( 'Masterclass gate failed because required tables are missing.' );

		$this->mrm_mc_render_gate_page(
			'Masterclass Access Temporarily Unavailable',
			'This access link could not be checked right now. Please contact support if your Masterclass is starting soon.'
		);
		exit;
	}

	$registration = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$regs_table} WHERE gate_token_hash = %s LIMIT 1",
			$hash
		)
	);

	if ( ! $registration ) {
		$this->mrm_mc_debug_log( 'Masterclass gate rejected unknown token hash.' );

		$this->mrm_mc_render_gate_page(
			'Invalid Masterclass Access Link',
			'This access link was not found. Please use the protected link from your confirmation email.'
		);
		exit;
	}

	if ( 'paid' !== sanitize_key( $registration->payment_status ) ) {
		$this->mrm_mc_debug_log(
			'Masterclass gate rejected unpaid registration.',
			array( 'registration_id' => absint( $registration->id ) )
		);

		$this->mrm_mc_render_gate_page(
			'Masterclass Access Not Available',
			'This registration is not marked as paid, so the access link cannot be opened.'
		);
		exit;
	}

	$event = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$events_table} WHERE id = %d LIMIT 1",
			absint( $registration->event_id )
		)
	);

	if ( ! $event ) {
		$this->mrm_mc_render_gate_page(
			'Masterclass Event Not Found',
			'This registration is valid, but the linked Masterclass event could not be found. Please contact support.'
		);
		exit;
	}

	$start_ts = strtotime( $event->start_time . ' UTC' );
	$end_ts   = strtotime( $event->end_time . ' UTC' );
	$now_ts   = time();

	$opens_ts  = $start_ts - ( 15 * MINUTE_IN_SECONDS );
	$closes_ts = $end_ts + ( 30 * MINUTE_IN_SECONDS );

	if ( $now_ts < $opens_ts ) {
		$this->mrm_mc_render_gate_page(
			'Masterclass Access Opens Soon',
			'Your access link is valid. The meeting link will appear 15 minutes before the Masterclass starts.<br><br><strong>Masterclass:</strong> ' . esc_html( $event->title ) . '<br><strong>Starts:</strong> ' . esc_html( $event->start_time . ' ' . $event->timezone )
		);
		exit;
	}

	if ( $now_ts > $closes_ts ) {
		$this->mrm_mc_render_gate_page(
			'Masterclass Access Window Has Closed',
			'This Masterclass access window has ended. Please contact support if you believe this is an error.'
		);
		exit;
	}

	if ( empty( $event->google_meet_url ) ) {
		$this->mrm_mc_debug_log(
			'Masterclass gate opened but Meet link is unavailable.',
			array(
				'event_id'        => absint( $event->id ),
				'registration_id' => absint( $registration->id ),
			)
		);

		$this->mrm_mc_render_gate_page(
			'Masterclass Link Not Available Yet',
			'Your access window is open, but the Google Meet link is not available yet. Please contact support if the Masterclass is starting now.'
		);
		exit;
	}

	$meet_url = esc_url( $event->google_meet_url );

	$this->mrm_mc_render_gate_page(
		'Your Masterclass Access Is Open',
		'<p>Your Masterclass meeting link is available now.</p>'
		. '<p><strong>Masterclass:</strong> ' . esc_html( $event->title ) . '</p>'
		. '<p><a class="mrm-masterclass-gate-button" href="' . $meet_url . '" target="_blank" rel="noopener">Join Google Meet</a></p>'
	);
	exit;
}

private function mrm_mc_render_gate_page( $title, $body_html ) {
	status_header( 200 );
	nocache_headers();

	$title     = sanitize_text_field( $title );
	$body_html = wp_kses_post( $body_html );

	echo '<!doctype html>';
	echo '<html lang="en">';
	echo '<head>';
	echo '<meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<title>' . esc_html( $title ) . '</title>';
	echo '<style>';
	echo 'body{margin:0;background:#f6f1ea;color:#20170f;font-family:Arial,sans-serif;}';
	echo '.mrm-masterclass-gate-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}';
	echo '.mrm-masterclass-gate-card{max-width:720px;width:100%;background:#fff;border:1px solid #dccab0;border-radius:28px;padding:34px;box-shadow:0 18px 48px rgba(32,23,15,.12);}';
	echo '.mrm-masterclass-gate-brand{text-align:center;font-weight:800;letter-spacing:.04em;margin-bottom:22px;}';
	echo 'h1{font-family:Georgia,serif;font-size:clamp(2rem,5vw,3.25rem);line-height:1;margin:0 0 18px;}';
	echo 'p{line-height:1.65;color:#5f5242;}';
	echo '.mrm-masterclass-gate-button{display:inline-block;background:#20170f;color:#fff!important;text-decoration:none;border-radius:999px;padding:14px 22px;font-weight:800;}';
	echo '</style>';
	echo '</head>';
	echo '<body>';
	echo '<main class="mrm-masterclass-gate-wrap">';
	echo '<section class="mrm-masterclass-gate-card">';
	echo '<div class="mrm-masterclass-gate-brand">Low Brass Lessons</div>';
	echo '<h1>' . esc_html( $title ) . '</h1>';
	echo '<div>' . $body_html . '</div>';
	echo '</section>';
	echo '</main>';
	echo '</body>';
	echo '</html>';
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

	$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

	if ( 0 !== strpos( $page, 'mrm-masterclass' ) ) {
		return;
	}

echo '<style id="mrm-masterclass-admin-visibility-css">
		.mrm-masterclass-admin-hidden {
			display: none !important;
		}
		.mrm-masterclass-admin-card {
			background: #fff;
			border: 1px solid #dcdcde;
			border-radius: 12px;
			padding: 16px;
			margin: 16px 0;
			box-shadow: 0 1px 2px rgba(0,0,0,0.04);
		}
		.mrm-masterclass-admin-muted {
			color: #646970;
		}
		.mrm-masterclass-admin .description {
			color: #6b5f4f;
			max-width: 860px;
		}

		.mrm-masterclass-card-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
			gap: 18px;
			margin-top: 22px;
		}

		.mrm-masterclass-admin .mrm-card,
		.mrm-masterclass-admin .mrm-masterclass-card {
			background: #fff;
			border: 1px solid #d7c7ad;
			border-radius: 18px;
			padding: 22px;
			box-shadow: 0 8px 22px rgba(0,0,0,.06);
		}

		.mrm-masterclass-admin table.widefat {
			border-radius: 12px;
			overflow: hidden;
		}

		.mrm-masterclass-admin table.widefat td,
		.mrm-masterclass-admin table.widefat th {
			vertical-align: top;
		}

		.mrm-masterclass-admin .widefat code {
			white-space: normal;
			word-break: break-word;
		}

		@media (max-width: 1100px) {
			.mrm-masterclass-admin .widefat {
				display: block;
				overflow-x: auto;
				white-space: nowrap;
			}
		}

		@media (max-width: 782px) {
			.mrm-masterclass-card-grid {
				grid-template-columns: 1fr;
			}

			.mrm-masterclass-admin .button {
				width: 100%;
				text-align: center;
				margin-bottom: 8px;
			}
		}
	</style>';
}

public function register_admin_menu() {
	$capability = 'manage_options';

	add_menu_page(
		'MRM Masterclass',
		'MRM Masterclass',
		$capability,
		'mrm-masterclass',
		array( $this, 'render_dashboard_page' ),
		'dashicons-welcome-learn-more',
		56
	);

	add_submenu_page(
		'mrm-masterclass',
		'Dashboard',
		'Dashboard',
		$capability,
		'mrm-masterclass',
		array( $this, 'render_dashboard_page' )
	);

	add_submenu_page(
		'mrm-masterclass',
		'Events',
		'Events',
		$capability,
		'mrm-masterclass-events',
		array( $this, 'render_events_page' )
	);

	add_submenu_page(
		'mrm-masterclass',
		'Presenters',
		'Presenters',
		$capability,
		'mrm-masterclass-presenters',
		array( $this, 'render_presenters_page' )
	);

	add_submenu_page(
		'mrm-masterclass',
		'Registrations / Payments',
		'Registrations / Payments',
		$capability,
		'mrm-masterclass-registrations-payments',
		array( $this, 'render_registrations_payments_page' )
	);

	add_submenu_page(
		'mrm-masterclass',
		'Presenter Payouts',
		'Presenter Payouts',
		$capability,
		'mrm-masterclass-payouts',
		array( $this, 'render_payouts_page' )
	);

	add_submenu_page(
		'mrm-masterclass',
		'Tax Profiles',
		'Tax Profiles',
		$capability,
		'mrm-masterclass-tax-profiles',
		array( $this, 'render_tax_profiles_page' )
	);
}



public function render_dashboard_page() {
	$this->must_admin();

	global $wpdb;

	$events_table        = $this->t( 'mrm_masterclass_events' );
	$presenters_table    = $this->t( 'mrm_masterclass_presenters' );
	$registrations_table = $this->t( 'mrm_masterclass_registrations' );
	$ledger_table        = $this->t( 'mrm_masterclass_payment_ledger' );

	$event_count = $this->mrm_mc_table_exists( $events_table )
		? absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table} WHERE status <> 'deleted'" ) )
		: 0;

	$presenter_count = $this->mrm_mc_table_exists( $presenters_table )
		? absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$presenters_table}" ) )
		: 0;

	$paid_count = $this->mrm_mc_table_exists( $registrations_table )
		? absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$registrations_table} WHERE payment_status = 'paid'" ) )
		: 0;

	$payable_cents = $this->mrm_mc_table_exists( $ledger_table )
		? absint( $wpdb->get_var( "SELECT COALESCE(SUM(presenter_share_cents),0) FROM {$ledger_table} WHERE status = 'payable'" ) )
		: 0;

	echo '<div class="wrap mrm-masterclass-admin">';
	echo '<h1>MRM Masterclass</h1>';
	echo '<p class="description">Dashboard for masterclass events, presenters, registrations, payments, payouts, tax profiles, reminders, and cancellations.</p>';

	echo '<div class="mrm-masterclass-card-grid">';

	$this->admin_card_open( 'Events', 'Active and historical masterclass sessions.' );
	echo '<p><strong>' . esc_html( $event_count ) . '</strong> events</p>';
	echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=mrm-masterclass-events' ) ) . '">Manage Events</a></p>';
	$this->admin_card_close();

	$this->admin_card_open( 'Presenters', 'Presenter profiles and generated public pages.' );
	echo '<p><strong>' . esc_html( $presenter_count ) . '</strong> presenters</p>';
	echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=mrm-masterclass-presenters' ) ) . '">Manage Presenters</a></p>';
	$this->admin_card_close();

	$this->admin_card_open( 'Registrations / Payments', 'Paid student registrations and Stripe references.' );
	echo '<p><strong>' . esc_html( $paid_count ) . '</strong> paid registrations</p>';
	echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=mrm-masterclass-registrations-payments' ) ) . '">Review Payments</a></p>';
	$this->admin_card_close();

	$this->admin_card_open( 'Presenter Payouts', 'Payable presenter shares from the ledger.' );
	echo '<p><strong>' . esc_html( $this->cents_to_dollars( $payable_cents ) ) . '</strong> currently payable</p>';
	echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=mrm-masterclass-payouts' ) ) . '">Review Payouts</a></p>';
	$this->admin_card_close();

	echo '</div>';
	echo '</div>';
}


public function render_settings_page() {
	$this->must_admin();
	$this->mrm_mc_debug_log( 'Legacy Settings submenu rendered.' );

	echo '<div class="wrap mrm-masterclass-admin">';
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
		echo '<div class="wrap mrm-masterclass-admin">';
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

	echo '<div class="wrap mrm-masterclass-admin">';
	echo '<h1>Masterclass Presenters</h1>';
	echo '<p>Create and manage presenters separately from scheduler instructors. These profiles feed event assignment, payout reporting, and tax profiles.</p>';

	$notice = isset( $_GET['mrm_mc_notice'] ) ? sanitize_key( wp_unslash( $_GET['mrm_mc_notice'] ) ) : '';
	$notice_map = array(
		'presenter_table_missing'     => array( 'error', 'The presenter table is missing. Reactivate the Masterclass plugin to run the database installer.' ),
		'presenter_validation_failed' => array( 'error', 'Presenter could not be saved. Please enter a presenter name and valid email address.' ),
		'presenter_save_failed'       => array( 'error', 'Presenter could not be saved because of a database error. Check wp-content/masterclass-debug.log.' ),
		'presenter_saved'             => array( 'success', 'Presenter saved successfully.' ),
		'presenter_missing_id'           => array( 'error', 'Presenter action failed because the presenter ID was missing.' ),
		'presenter_not_found'            => array( 'error', 'Presenter could not be found.' ),
		'presenter_page_failed'          => array( 'error', 'Presenter page could not be created or updated. Check wp-content/masterclass-debug.log.' ),
		'presenter_page_saved'           => array( 'success', 'Presenter page created or updated successfully.' ),
		'presenter_delete_table_missing' => array( 'error', 'Presenter could not be removed because one or more Masterclass tables are missing.' ),
		'presenter_archive_failed'       => array( 'error', 'Presenter could not be archived. Check wp-content/masterclass-debug.log.' ),
		'presenter_archived'             => array( 'success', 'Presenter has linked event history, so the record was safely archived instead of deleted.' ),
		'presenter_delete_failed'        => array( 'error', 'Presenter could not be deleted. Check wp-content/masterclass-debug.log.' ),
		'presenter_deleted'              => array( 'success', 'Presenter deleted because no linked events were found.' ),
	);

	if ( isset( $notice_map[ $notice ] ) ) {
		list( $level, $message ) = $notice_map[ $notice ];
		echo '<div class="notice notice-' . esc_attr( $level ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	echo '<div style="background:#fff;border:1px solid #d7c7ad;border-radius:16px;padding:20px;margin:18px 0;">';
	echo '<h2>' . esc_html( $editing ? 'Edit Presenter' : 'Add Presenter' ) . '</h2>';

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
	echo '<tr><th>Presenter Payout Percent</th><td><input type="number" step="0.01" min="0" max="100" name="payout_percent" class="regular-text" value="' . esc_attr( $editing['payout_percent'] ?? $this->mrm_mc_presenter_default_percent() ) . '"><p class="description">This presenter-specific percentage is used for Masterclass registration ledger calculations.</p></td></tr>';
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
		echo '<thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Location</th><th>Stripe Account</th><th>Payout %</th><th>Timezone</th><th>Presenter Page</th><th>Actions</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['id'] ) . '</td>';
			echo '<td><strong>' . esc_html( $row['name'] ) . '</strong></td>';
			echo '<td>' . esc_html( $row['email'] ) . '</td>';
			echo '<td>' . esc_html( trim( ( $row['city'] ?? '' ) . ', ' . ( $row['state'] ?? '' ), ', ' ) ) . '</td>';
			echo '<td><code>' . esc_html( $row['stripe_connected_account_id'] ?? '' ) . '</code></td>';
			echo '<td>' . esc_html( isset( $row['payout_percent'] ) && $row['payout_percent'] !== null ? $row['payout_percent'] . '%' : $this->mrm_mc_presenter_default_percent() . '%' ) . '</td>';
			echo '<td><code>' . esc_html( $row['timezone'] ?? '' ) . '</code></td>';
			echo '<td>';

			if ( ! empty( $row['presenter_page_id'] ) && get_permalink( absint( $row['presenter_page_id'] ) ) ) {
				echo '<a class="button" href="' . esc_url( get_permalink( absint( $row['presenter_page_id'] ) ) ) . '" target="_blank" rel="noopener">View Page</a>';
			} else {
				echo '<span class="description">Not generated</span>';
			}

			echo '</td>';
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
	$edit_event = null;
	$edit_event_id = absint( $_GET['edit'] ?? 0 );
	if ( $edit_event_id > 0 ) {
		$edit_event = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$events_table} WHERE id = %d",
				$edit_event_id
			)
		);
	}

	$events = $wpdb->get_results(
		"SELECT e.*, p.name AS presenter_name,
			(SELECT COUNT(*) FROM {$regs_table} r WHERE r.event_id = e.id AND r.payment_status = 'paid') AS paid_count
		 FROM {$events_table} e
		 LEFT JOIN {$presenters_table} p ON p.id = e.presenter_id
		 WHERE e.status <> 'deleted'
		 ORDER BY e.start_time DESC
		 LIMIT 200"
	);

	echo '<div class="wrap mrm-masterclass-admin">';
	echo '<h1>Masterclass Events</h1>';
	echo '<p>Create masterclass sessions, assign presenter/proctor emails, and store the Google Calendar ID this plugin controls.</p>';

	$notice = isset( $_GET['mrm_mc_notice'] ) ? sanitize_key( wp_unslash( $_GET['mrm_mc_notice'] ) ) : '';
	$notice_map = array(
		'event_table_missing'     => array( 'error', 'Masterclass event could not be saved because required database tables are missing. Reactivate the plugin and check wp-content/masterclass-debug.log.' ),
		'event_validation_failed' => array( 'error', 'Masterclass event could not be saved. Please complete the title, presenter, start time, end time, and timezone.' ),
		'event_time_invalid'      => array( 'error', 'Masterclass event could not be saved because the end time must be after the start time.' ),
		'event_price_invalid'     => array( 'error', 'Masterclass event could not be saved because the price must be greater than zero.' ),
		'event_presenter_missing' => array( 'error', 'Masterclass event could not be saved because the selected presenter could not be found.' ),
		'event_save_failed'       => array( 'error', 'Masterclass event could not be saved because of a database error. Check wp-content/masterclass-debug.log.' ),
		'event_saved_local'                  => array( 'success', 'Masterclass event saved locally.' ),
		'event_saved_google_reload_failed'    => array( 'warning', 'Masterclass event saved locally, but it could not be reloaded for Google Calendar creation. Check wp-content/masterclass-debug.log.' ),
		'event_saved_google_missing_calendar' => array( 'warning', 'Masterclass event saved locally, but no Masterclass Google Calendar ID is configured.' ),
		'event_saved_google_failed'           => array( 'warning', 'Masterclass event saved locally, but Google Calendar / Google Meet creation needs attention. Check wp-content/masterclass-debug.log.' ),
		'event_saved_google_success'          => array( 'success', 'Masterclass event saved and Google Calendar / Google Meet creation succeeded.' ),
		'event_cancel_missing_id'             => array( 'error', 'Event cancellation failed because the event ID was missing.' ),
		'event_cancel_table_missing'          => array( 'error', 'Event cancellation failed because one or more Masterclass database tables are missing.' ),
		'event_cancel_not_found'              => array( 'error', 'Event cancellation failed because the event could not be found.' ),
		'event_cancel_already_deleted'        => array( 'warning', 'This Masterclass event has already been deleted from active lists.' ),
		'event_cancel_refund_failures'        => array( 'error', 'Event was removed from active lists, but one or more automatic refunds failed. Check wp-content/masterclass-debug.log and Stripe.' ),
		'event_cancel_refunds_success'        => array( 'success', 'Event was removed from active lists and automatic refunds were processed for paid participants before the refund deadline.' ),
		'event_updated_google_success'        => array( 'success', 'Masterclass event updated, Google Calendar updated, and registered paid attendees were notified.' ),
		'event_cancel_no_paid_attendees'      => array( 'success', 'Event was removed from active lists. No paid attendees were found for automatic refund processing.' ),
		'event_cancel_no_refunds'             => array( 'success', 'Event was removed from active lists. The one-week post-event refund deadline had passed, so no automatic refunds were issued.' ),
		'settings_saved'          => array( 'success', 'Calendar settings saved.' ),
	);

	if ( isset( $notice_map[ $notice ] ) ) {
		list( $level, $message ) = $notice_map[ $notice ];
		echo '<div class="notice notice-' . esc_attr( $level ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	echo '<div style="background:#fff;border:1px solid #d7c7ad;border-radius:16px;padding:20px;margin:18px 0;">';
	echo '<h2>Google Calendar Control</h2>';
	echo '<p>This replaces a separate Settings submenu. Enter the Google Calendar ID that the Masterclass plugin should control.</p>';

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
	if ( $edit_event ) {
		echo '<input type="hidden" name="event_id" value="' . esc_attr( $edit_event->id ) . '">';
	}
	wp_nonce_field( 'mrm_masterclass_save_event' );

	echo '<table class="form-table"><tbody>';
	echo '<tr><th>Title</th><td><input type="text" name="title" class="regular-text" required value="' . esc_attr( $edit_event->title ?? '' ) . '"></td></tr>';
	echo '<tr><th>Description</th><td><textarea name="description" rows="5" class="large-text">' . esc_textarea( $edit_event->description ?? '' ) . '</textarea></td></tr>';

	echo '<tr><th>Presenter</th><td><select name="presenter_id" required>';
	echo '<option value="">Select presenter</option>';
	foreach ( $presenters as $presenter ) {
		echo '<option value="' . esc_attr( $presenter->id ) . '"' . selected( absint( $edit_event->presenter_id ?? 0 ), absint( $presenter->id ), false ) . '>' . esc_html( $presenter->name . ' — ' . $presenter->email ) . '</option>';
	}
	echo '</select><p class="description">The presenter email is pulled from the selected presenter record.</p></td></tr>';

	echo '<tr><th>Optional Proctor Email</th><td><input type="email" name="proctor_email" class="regular-text" placeholder="proctor@example.com" value="' . esc_attr( $edit_event->proctor_email ?? '' ) . '"><p class="description">Added as an event guest. True Google Meet co-host assignment may still require Workspace host controls.</p></td></tr>';
	echo '<tr><th>Start Time</th><td><input type="datetime-local" name="start_time" required value="' . esc_attr( ! empty( $edit_event->start_time ) ? str_replace( ' ', 'T', substr( $edit_event->start_time, 0, 16 ) ) : '' ) . '"></td></tr>';
	echo '<tr><th>End Time</th><td><input type="datetime-local" name="end_time" required value="' . esc_attr( ! empty( $edit_event->end_time ) ? str_replace( ' ', 'T', substr( $edit_event->end_time, 0, 16 ) ) : '' ) . '"></td></tr>';
	echo '<tr><th>Timezone</th><td><input type="text" name="timezone" class="regular-text" value="' . esc_attr( $edit_event->timezone ?? ( $settings['default_timezone'] ?? 'America/Phoenix' ) ) . '"></td></tr>';
	echo '<tr><th>Price</th><td><input type="number" name="price_cents" class="regular-text" value="' . esc_attr( $edit_event->price_cents ?? ( $settings['default_price_cents'] ?? self::DEFAULT_PRICE_CENTS ) ) . '"><p class="description">Cents. Example: 2500 = $25.00.</p></td></tr>';
	echo '<tr><th>Capacity</th><td><input type="number" name="capacity" class="regular-text" value="' . esc_attr( $edit_event->capacity ?? ( $settings['default_capacity'] ?? 100 ) ) . '"></td></tr>';
	echo '<tr><th>Status</th><td><select name="status">';
	foreach ( array( 'draft', 'scheduled', 'cancelled', 'completed', 'archived' ) as $status ) {
		echo '<option value="' . esc_attr( $status ) . '"' . selected( $status, $edit_event->status ?? 'scheduled', false ) . '>' . esc_html( ucfirst( $status ) ) . '</option>';
	}
	echo '</select></td></tr>';
	echo '<tr><th>Registration Open</th><td><label><input type="checkbox" name="registration_open" value="1"' . checked( absint( $edit_event->registration_open ?? 1 ), 1, false ) . '> Accept registrations</label></td></tr>';
	echo '</tbody></table>';

	submit_button( $edit_event ? 'Update Session' : 'Create Session' );
	echo '</form>';
	echo '</div>';

	echo '<h2>Existing Sessions</h2>';
	echo '<table class="widefat striped">';
	echo '<thead><tr>';
	echo '<th>Title</th>';
	echo '<th>Presenter</th>';
	echo '<th>Proctor</th>';
	echo '<th>Start</th>';
	echo '<th>End</th>';
	echo '<th>Refund Deadline</th>';
	echo '<th>Price</th>';
	echo '<th>Capacity</th>';
	echo '<th>Paid</th>';
	echo '<th>Available</th>';
	echo '<th>Status</th>';
	echo '<th>Registration</th>';
	echo '<th>Google Event</th>';
	echo '<th>Meet Link</th>';
	echo '<th>Google Status</th>';
	echo '<th>Actions</th>';
	echo '</tr></thead><tbody>';

	if ( $events ) {
		foreach ( $events as $event ) {
			$paid_count      = absint( $event->paid_count ?? 0 );
			$capacity        = absint( $event->capacity ?? 0 );
			$available       = max( 0, $capacity - $paid_count );
			$refund_deadline = gmdate( 'Y-m-d H:i:s', strtotime( $event->start_time . ' UTC' ) + ( 7 * DAY_IN_SECONDS ) );
			$google_error    = sanitize_text_field( $event->google_last_error ?? '' );
			$cancel_url      = wp_nonce_url(
				admin_url( 'admin-post.php?action=mrm_masterclass_cancel_event&event_id=' . absint( $event->id ) ),
				'mrm_masterclass_cancel_event_' . absint( $event->id )
			);

			echo '<tr>';
			echo '<td><strong>' . esc_html( $event->title ) . '</strong><br><span class="description">ID: ' . esc_html( absint( $event->id ) ) . '</span></td>';
			echo '<td>' . esc_html( $event->presenter_name ?: $event->presenter_email ) . '</td>';
			echo '<td>' . esc_html( $event->proctor_email ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $event->start_time ) . '</td>';
			echo '<td>' . esc_html( $event->end_time ) . '</td>';
			echo '<td>' . esc_html( $refund_deadline ) . '</td>';
			echo '<td>' . esc_html( $this->cents_to_dollars( $event->price_cents ) ) . '</td>';
			echo '<td>' . esc_html( $capacity ) . '</td>';
			echo '<td>' . esc_html( $paid_count ) . '</td>';
			echo '<td>' . esc_html( $available ) . '</td>';
			echo '<td><code>' . esc_html( $event->status ) . '</code></td>';
			echo '<td>' . ( ! empty( $event->registration_open ) ? '<span style="color:#116329;font-weight:700;">Open</span>' : '<span style="color:#8a1f11;font-weight:700;">Closed</span>' ) . '</td>';
			echo '<td><code>' . esc_html( $event->google_event_id ?: 'Not created yet' ) . '</code></td>';
			echo '<td>' . ( $event->google_meet_url ? '<a href="' . esc_url( $event->google_meet_url ) . '" target="_blank" rel="noopener">Open Meet</a>' : '—' ) . '</td>';

			if ( '' !== $google_error ) {
				echo '<td><span style="color:#8a1f11;font-weight:700;">Needs attention</span><br><span class="description">' . esc_html( $google_error ) . '</span></td>';
			} elseif ( ! empty( $event->google_event_id ) ) {
				echo '<td><span style="color:#116329;font-weight:700;">Created</span></td>';
			} else {
				echo '<td><span class="description">Not created</span></td>';
			}

			echo '<td>';
			echo '<a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=mrm-masterclass-events&edit=' . absint( $event->id ) ) ) . '">Edit</a> ';
			echo '<a class="button button-small button-link-delete" href="' . esc_url( $cancel_url ) . '" onclick="return confirm(\'Cancel/delete this event? Paid participants may be refunded automatically depending on the refund deadline.\');">Cancel / Delete</a>';

			$emergency_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=mrm_masterclass_emergency_cancel_event&event_id=' . absint( $event->id ) ),
				'mrm_masterclass_emergency_cancel_event_' . absint( $event->id )
			);

			echo ' <a class="button button-small" href="' . esc_url( $emergency_url ) . '" onclick="return confirm(\'Emergency cancel this event? This uses the same refund policy logic and attempts Google cancellation.\');">Emergency Cancel</a>';
			echo '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="16">No active masterclass sessions created yet.</td></tr>';
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

	echo '<div class="wrap mrm-masterclass-admin">';
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
		echo '<div class="wrap mrm-masterclass-admin"><h1>Presenter Payouts</h1><div class="notice notice-error"><p>Required payout tables are missing. Reactivate the plugin and check the Masterclass debug log.</p></div></div>';
		return;
	}

	$notice_map = array(
		'payout_missing_id'       => array( 'error', 'Payout action failed because the ledger ID was missing.' ),
		'payout_table_missing'    => array( 'error', 'Payout action failed because the payment ledger table is missing.' ),
		'payout_mark_paid_failed' => array( 'error', 'Payout could not be marked paid. Check the Masterclass debug log.' ),
		'payout_not_payable'      => array( 'warning', 'This payout was not marked paid because it is no longer payable.' ),
		'payout_marked_paid'      => array( 'success', 'Presenter payout marked paid and preserved in the ledger audit trail.' ),
		'payout_batch_empty'      => array( 'warning', 'No payout rows were selected.' ),
		'payout_batch_paid'       => array( 'success', 'Selected payout rows were marked paid and preserved in the ledger audit trail.' ),
		'payout_batch_failed'     => array( 'error', 'The payout batch could not be marked paid. Check the Masterclass debug log.' ),
		'payout_transfer_failed'  => array( 'error', 'Stripe Connect payout transfer could not be issued. The row was preserved for audit review.' ),
		'payout_transfer_success' => array( 'success', 'Stripe Connect payout transfer issued and the ledger row was marked paid out.' ),
	);

	echo '<div class="wrap mrm-masterclass-admin">';
	echo '<h1>Presenter Payouts</h1>';
	echo '<p>Presenter payouts are based on payment ledger rows. Mark rows paid only after the presenter has actually been paid.</p>';

	if ( method_exists( $this, 'mrm_mc_render_notice_from_map' ) ) {
		$this->mrm_mc_render_notice_from_map( $notice_map );
	}

	$summary = $wpdb->get_results(
		"SELECT p.id, p.name, p.email, p.stripe_connected_account_id,
		        COALESCE(SUM(CASE WHEN l.status = 'payable' THEN l.presenter_share_cents ELSE 0 END),0) AS unpaid_cents,
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
		   AND l.status IN ('payable','payout_failed')
		   AND l.presenter_share_cents > 0
		 ORDER BY p.name ASC, l.created_at ASC"
	);

	echo '<h2>Presenter Balances</h2>';
	echo '<table class="widefat striped">';
	echo '<thead><tr><th>Presenter</th><th>Email</th><th>Stripe Account</th><th>Payable</th><th>Paid Out</th></tr></thead><tbody>';

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

	echo '<h2 style="margin-top:28px;">Payable Ledger Rows</h2>';
	echo '<p>Select rows that have actually been paid. Marking rows paid out is what makes them count toward paid-out presenter totals.</p>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="mrm_masterclass_mark_payouts_paid">';
	wp_nonce_field( 'mrm_masterclass_mark_payouts_paid' );

	echo '<table class="widefat striped">';
	echo '<thead><tr><th>Select</th><th>Presenter</th><th>Event</th><th>Presenter Share</th><th>Eligibility</th><th>Status</th><th>Created</th><th>Single Action</th></tr></thead><tbody>';

	if ( $rows ) {
		foreach ( $rows as $row ) {
			$single_paid_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=mrm_masterclass_mark_payout_paid&ledger_id=' . absint( $row->id ) ),
				'mrm_masterclass_mark_payout_paid_' . absint( $row->id )
			);
			$transfer_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=mrm_masterclass_issue_payout_transfer&ledger_id=' . absint( $row->id ) ),
				'mrm_masterclass_issue_payout_transfer_' . absint( $row->id )
			);

			echo '<tr>';
			echo '<td><input type="checkbox" name="ledger_ids[]" value="' . esc_attr( $row->id ) . '"></td>';
			echo '<td>' . esc_html( $row->presenter_name ?: '—' ) . '<br><small>' . esc_html( $row->presenter_email ?: '' ) . '</small></td>';
			echo '<td>' . esc_html( $row->event_title ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $this->cents_to_dollars( $row->presenter_share_cents ) ) . '</td>';
			$eligible_at = ! empty( $row->payout_eligible_at ) ? $row->payout_eligible_at : gmdate( 'Y-m-d H:i:s', strtotime( $row->created_at . ' UTC' ) + WEEK_IN_SECONDS );
			$is_eligible = strtotime( $eligible_at . ' UTC' ) <= time();
			echo '<td>' . esc_html( $eligible_at ) . '<br><small>' . esc_html( $is_eligible ? 'Eligible now' : 'Scheduled' ) . '</small></td>';
			echo '<td>' . esc_html( $row->status ) . '</td>';
			echo '<td>' . esc_html( $row->created_at ) . '</td>';
			echo '<td>';
			echo '<a class="button button-small" href="' . esc_url( $transfer_url ) . '" onclick="return confirm(\'Issue this Stripe Connect payout transfer now?\');">Issue Stripe Transfer</a> ';
			echo '<a class="button button-small" href="' . esc_url( $single_paid_url ) . '" onclick="return confirm(\'Mark this presenter payout as paid without Stripe transfer?\');">Mark Paid</a>';
			echo '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="8">No payable presenter payout rows found.</td></tr>';
	}

	echo '</tbody></table>';

	if ( $rows ) {
		submit_button( 'Mark Selected Paid Out' );
	}

	echo '</form>';
	echo '</div>';
}

public function render_1099_page() {
	$this->render_tax_profiles_page();
}

public function handle_export_1099_csv() {
	$this->must_admin();
	$this->mrm_mc_verify_admin_post_nonce_or_die( 'mrm_masterclass_export_1099_csv' );
	global $wpdb;
	$year = absint( $_GET['tax_year'] ?? $_POST['tax_year'] ?? gmdate( 'Y' ) );
	if ( $year < 2000 || $year > 2100 ) { $year = absint( gmdate( 'Y' ) ); }
	$ledger_table     = $this->t( 'mrm_masterclass_payment_ledger' );
	$events_table     = $this->t( 'mrm_masterclass_events' );
	$presenters_table = $this->t( 'mrm_masterclass_presenters' );
	$tax_table        = $this->t( 'mrm_masterclass_presenter_tax_profiles' );
	if ( ! $this->mrm_mc_table_exists( $ledger_table ) || ! $this->mrm_mc_table_exists( $presenters_table ) || ! $this->mrm_mc_table_exists( $tax_table ) ) {
		wp_die( esc_html__( 'Required 1099 export tables are missing.', 'mrm-masterclass' ) );
	}
	$start = $year . '-01-01 00:00:00';
	$end   = ( $year + 1 ) . '-01-01 00:00:00';
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.id AS presenter_id, p.name AS presenter_name, p.email AS presenter_email, t.legal_name, t.business_name, t.tin_last4, t.w9_received, t.is_1099_eligible, t.exclude_from_1099, t.is_employee, t.address_line1, t.address_line2, t.city, t.state, t.zip, COUNT(DISTINCT l.event_id) AS masterclass_count, COALESCE(SUM(l.presenter_share_cents),0) AS paid_out_cents
		 FROM {$ledger_table} l
		 INNER JOIN {$presenters_table} p ON p.id = l.presenter_id
		 LEFT JOIN {$tax_table} t ON t.presenter_id = p.id
		 WHERE l.ledger_type = 'registration_payment'
		   AND l.status = 'paid_out'
		   AND COALESCE(l.paid_out_at,l.paid_at,l.updated_at,l.created_at) >= %s
		   AND COALESCE(l.paid_out_at,l.paid_at,l.updated_at,l.created_at) < %s
		 GROUP BY p.id
		 ORDER BY p.name ASC",
		$start, $end
	), ARRAY_A );
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=masterclass-1099-summary-' . $year . '.csv' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'Tax Year','Presenter ID','Presenter Name','Email','Legal Name','Business Name','Address Line 1','Address Line 2','City','State','ZIP','TIN Last 4','W9 Received','1099 Eligible','Employee Excluded','Excluded From 1099','Masterclass Count','Paid Out Dollars' ) );
	foreach ( (array) $rows as $row ) {
		$is_employee = ! empty( $row['is_employee'] );
		$is_excluded = ! empty( $row['exclude_from_1099'] );
		fputcsv( $out, array( $year, $row['presenter_id'], $row['presenter_name'], $row['presenter_email'], $row['legal_name'], $row['business_name'], $row['address_line1'], $row['address_line2'], $row['city'], $row['state'], $row['zip'], $row['tin_last4'], ! empty( $row['w9_received'] ) ? 'yes' : 'no', ! empty( $row['is_1099_eligible'] ) ? 'yes' : 'no', $is_employee ? 'yes' : 'no', $is_excluded ? 'yes' : 'no', $row['masterclass_count'], number_format( absint( $row['paid_out_cents'] ) / 100, 2, '.', '' ) ) );
	}
	fclose( $out );
	exit;
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
			        COALESCE(SUM(CASE WHEN l.status = 'paid_out' AND YEAR(COALESCE(l.paid_out_at,l.paid_at,l.updated_at)) = %d THEN l.presenter_share_cents ELSE 0 END),0) AS box1_cents
			 FROM {$presenters_table} p
			 LEFT JOIN {$profiles_table} t ON t.presenter_id = p.id
			 LEFT JOIN {$ledger_table} l ON l.presenter_id = p.id AND l.ledger_type = 'registration_payment'
			 GROUP BY p.id
			 ORDER BY p.name ASC",
			$year
		)
	);

	echo '<div class="wrap mrm-masterclass-admin">';
	echo '<h1>Tax Profiles</h1>';

	$notice_map = array(
		'tax_table_missing'     => array( 'error', 'Tax profile could not be saved because the tax profile table is missing.' ),
		'tax_presenter_missing' => array( 'error', 'Tax profile could not be saved because a presenter was not selected.' ),
		'tax_save_failed'       => array( 'error', 'Tax profile could not be saved. Check the Masterclass debug log.' ),
		'tax_saved'             => array( 'success', 'Tax profile saved successfully.' ),
	);

	if ( method_exists( $this, 'mrm_mc_render_notice_from_map' ) ) {
		$this->mrm_mc_render_notice_from_map( $notice_map );
	}

	echo '<p>This combines presenter tax profiles and 1099 paid-out totals. Full TIN values should live in AWS/Stripe, not WordPress.</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="background:#fff;border:1px solid #d7c7ad;border-radius:16px;padding:16px;margin:16px 0;">';
	echo '<input type="hidden" name="action" value="mrm_masterclass_export_1099_csv">';
	wp_nonce_field( 'mrm_masterclass_export_1099_csv' );
	echo '<label><strong>Tax year</strong> <input type="number" name="tax_year" value="' . esc_attr( gmdate( 'Y' ) ) . '" min="2000" max="2100"></label> ';
	submit_button( 'Export Masterclass 1099 CSV', 'secondary', 'submit', false );
	echo '<p class="description">Export includes paid-out presenter payout ledger rows only. It excludes pending payable amounts, refunded rows, platform share, Stripe fees, employees, and excluded profiles.</p>';
	echo '</form>';

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

		echo '<div class="wrap mrm-masterclass-admin">';
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
			'mrm-masterclass/v1',
			'/events',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_get_events' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'mrm-masterclass/v1',
			'/event',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_get_event' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
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
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_create_payment_intent' ),
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
				'callback'            => array( $this, 'rest_finalize_registration' ),
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

public function rest_get_event( $request ) {
	global $wpdb;

	$event_id = absint( $request->get_param( 'id' ) );

	if ( $event_id <= 0 ) {
		return new WP_Error(
			'mrm_masterclass_invalid_event_id',
			'Please select a valid Masterclass event.',
			array( 'status' => 400 )
		);
	}

	$events_table     = $this->t( 'mrm_masterclass_events' );
	$presenters_table = $this->t( 'mrm_masterclass_presenters' );
	$regs_table       = $this->t( 'mrm_masterclass_registrations' );

	if (
		! $this->mrm_mc_table_exists( $events_table )
		|| ! $this->mrm_mc_table_exists( $presenters_table )
		|| ! $this->mrm_mc_table_exists( $regs_table )
	) {
		$this->mrm_mc_debug_log(
			'Single Masterclass event REST request failed because required tables are missing.',
			array( 'event_id' => $event_id )
		);

		return new WP_Error(
			'mrm_masterclass_tables_missing',
			'This Masterclass event is temporarily unavailable. Please try again later.',
			array( 'status' => 503 )
		);
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT e.*,
				p.name AS presenter_name,
				p.presenter_page_id AS presenter_page_id,
				(SELECT COUNT(*) FROM {$regs_table} r WHERE r.event_id = e.id AND r.payment_status = 'paid') AS paid_count
			 FROM {$events_table} e
			 LEFT JOIN {$presenters_table} p ON p.id = e.presenter_id
			 WHERE e.id = %d
			   AND e.status = 'scheduled'
			   AND e.registration_open = 1
			   AND e.status <> 'deleted'
			 LIMIT 1",
			$event_id
		)
	);

	if ( ! $row ) {
		return new WP_Error(
			'mrm_masterclass_event_unavailable',
			'This Masterclass event is unavailable, closed, sold out, or no longer active.',
			array( 'status' => 404 )
		);
	}

	$row->available_seats    = max( 0, absint( $row->capacity ) - absint( $row->paid_count ) );
	$row->presenter_page_url = $this->mrm_mc_public_presenter_page_url( $row->presenter_page_id );

	return rest_ensure_response(
		array(
			'success' => true,
			'event'   => $this->mrm_mc_public_event_payload( $row ),
		)
	);
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
	return $this->rest_create_payment_intent( $request );
}

private function mrm_mc_validate_masterclass_promo_placeholder( $promo_code, $event, $base_amount_cents ) {
	$promo_code        = trim( sanitize_text_field( $promo_code ) );
	$base_amount_cents = absint( $base_amount_cents );

	if ( '' === $promo_code ) {
		return array(
			'status'         => 'not_applied',
			'message'        => '',
			'discount_cents' => 0,
			'promo_code'     => '',
			'metadata'       => array(),
		);
	}

	if ( strlen( $promo_code ) > 80 ) {
		return new WP_Error(
			'mrm_masterclass_promo_invalid',
			'Please enter a shorter promo code.',
			array( 'status' => 400 )
		);
	}

	/*
	 * Future shared promo integration path.
	 *
	 * Keep this self-contained for now, but allow safe use of an existing
	 * shared function if one is later made available globally.
	 *
	 * Expected future function shape:
	 * mrm_validate_online_lesson_style_promo( $promo_code, $context )
	 */
	if ( function_exists( 'mrm_validate_online_lesson_style_promo' ) ) {
		$context = array(
			'source'            => 'masterclass',
			'event_id'          => absint( $event->id ?? 0 ),
			'product_type'      => 'online_lesson_style_masterclass',
			'base_amount_cents' => $base_amount_cents,
			'email'             => '',
		);

		$result = mrm_validate_online_lesson_style_promo( $promo_code, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( is_array( $result ) ) {
			return array(
				'status'         => sanitize_key( $result['status'] ?? 'applied' ),
				'message'        => sanitize_text_field( $result['message'] ?? 'Promo code applied.' ),
				'discount_cents' => min( $base_amount_cents, absint( $result['discount_cents'] ?? 0 ) ),
				'promo_code'     => $promo_code,
				'metadata'       => is_array( $result['metadata'] ?? null ) ? $result['metadata'] : array(),
			);
		}
	}

	/*
	 * Launch-safe placeholder behavior:
	 * Accept the code, do not crash, do not discount yet.
	 */
	return array(
		'status'         => 'received_not_active',
		'message'        => 'Promo code received. Masterclass promo discounts are not active yet.',
		'discount_cents' => 0,
		'promo_code'     => $promo_code,
		'metadata'       => array(
			'placeholder' => true,
			'future_path' => 'online_lesson_style',
		),
	);
}
public function rest_create_payment_intent( $request ) {
	$event_id   = absint( $request->get_param( 'event_id' ) );
	$promo_code = sanitize_text_field( $request->get_param( 'promo_code' ) ?? '' );

	if ( $event_id <= 0 ) {
		return new WP_Error( 'mrm_masterclass_invalid_event_id', 'Please select a valid Masterclass event.', array( 'status' => 400 ) );
	}

	$event = $this->mrm_mc_get_event( $event_id );
	if ( ! $event ) {
		return new WP_Error( 'mrm_masterclass_event_missing', 'This Masterclass event could not be found.', array( 'status' => 404 ) );
	}
	if ( 'scheduled' !== sanitize_key( $event->status ) || empty( $event->registration_open ) ) {
		return new WP_Error( 'mrm_masterclass_event_closed', 'Registration is not open for this Masterclass.', array( 'status' => 409 ) );
	}

	$available = $this->mrm_mc_available_seats_for_event( $event );
	if ( $available <= 0 ) {
		return new WP_Error( 'mrm_masterclass_event_sold_out', 'This Masterclass is sold out.', array( 'status' => 409 ) );
	}

	$base_amount_cents = absint( $event->price_cents );
	if ( $base_amount_cents <= 0 ) {
		$this->mrm_mc_debug_log( 'PaymentIntent creation blocked because event amount is invalid.', array( 'event_id' => $event_id ) );
		return new WP_Error( 'mrm_masterclass_invalid_amount', 'This Masterclass is not configured for payment yet.', array( 'status' => 500 ) );
	}

	$promo = $this->mrm_mc_validate_masterclass_promo_placeholder( $promo_code, $event, $base_amount_cents );
	if ( is_wp_error( $promo ) ) {
		return $promo;
	}

	$discount_cents = absint( $promo['discount_cents'] ?? 0 );
	$amount_cents   = max( 50, $base_amount_cents - $discount_cents );
	$keys           = $this->mrm_mc_get_stripe_keys();

	if ( is_wp_error( $keys ) ) {
		return new WP_Error( 'mrm_masterclass_stripe_unavailable', 'Payments are temporarily unavailable. Please try again later.', array( 'status' => 503 ) );
	}

	$intent = $this->mrm_mc_stripe_request( 'POST', 'payment_intents', array(
		'amount'                            => $amount_cents,
		'currency'                          => 'usd',
		'payment_method_types[]'             => 'card',
		'description'                       => 'Masterclass registration: ' . sanitize_text_field( $event->title ),
		'metadata[event_id]'                => (string) $event_id,
		'metadata[event_title]'             => sanitize_text_field( $event->title ),
		'metadata[promo_code]'              => $promo_code,
		'metadata[base_amount]'             => (string) $base_amount_cents,
		'metadata[discount_amount]'         => (string) $discount_cents,
		'metadata[source]'                  => 'mrm_masterclass',
	) );

	if ( is_wp_error( $intent ) ) {
		return new WP_Error( $intent->get_error_code(), $intent->get_error_message(), array( 'status' => 500 ) );
	}

	if ( empty( $intent['client_secret'] ) || empty( $intent['id'] ) ) {
		$this->mrm_mc_debug_log( 'Stripe PaymentIntent response missing expected fields.', array( 'event_id' => $event_id ) );
		return new WP_Error( 'mrm_masterclass_payment_intent_incomplete', 'Payment setup could not be completed. Please try again.', array( 'status' => 500 ) );
	}

	return rest_ensure_response( array(
		'success'           => true,
		'client_secret'     => sanitize_text_field( $intent['client_secret'] ),
		'payment_intent_id' => sanitize_text_field( $intent['id'] ),
		'publishable_key'   => sanitize_text_field( $keys['publishable_key'] ),
		'amount_cents'      => $amount_cents,
		'base_amount_cents' => $base_amount_cents,
		'discount_cents'    => $discount_cents,
		'promo_status'      => sanitize_key( $promo['status'] ?? 'not_applied' ),
		'promo_message'     => sanitize_text_field( $promo['message'] ?? '' ),
	) );
}

public function rest_verify_pi( WP_REST_Request $request ) {
	$this->mrm_mc_debug_log( 'REST verify-payment-intent placeholder endpoint reached.' );

	return $this->mrm_mc_rest_not_implemented_error( 'payment verification' );
}

public function rest_finalize_registration( $request ) {
	global $wpdb;

	$event_id          = absint( $request->get_param( 'event_id' ) );
	$payment_intent_id = sanitize_text_field( $request->get_param( 'payment_intent_id' ) ?? '' );
	$first_name        = sanitize_text_field( $request->get_param( 'first_name' ) ?? '' );
	$last_name         = sanitize_text_field( $request->get_param( 'last_name' ) ?? '' );
	$name              = sanitize_text_field( $request->get_param( 'name' ) ?? '' );
	$email             = sanitize_email( $request->get_param( 'email' ) ?? '' );
	$terms_accepted    = filter_var( $request->get_param( 'terms_accepted' ), FILTER_VALIDATE_BOOLEAN );
	$promo_code        = sanitize_text_field( $request->get_param( 'promo_code' ) ?? '' );

	if ( '' === $name && ( '' !== $first_name || '' !== $last_name ) ) {
		$name = trim( $first_name . ' ' . $last_name );
	}

	if ( '' === $first_name && '' !== $name ) {
		$parts      = preg_split( '/\s+/', $name );
		$first_name = sanitize_text_field( $parts[0] ?? '' );
		$last_name  = sanitize_text_field( trim( substr( $name, strlen( $first_name ) ) ) );
	}

	if ( '' === $last_name ) {
		$last_name = '—';
	}

	if ( $event_id <= 0 || '' === $payment_intent_id ) {
		return new WP_Error('mrm_masterclass_finalize_missing_payment','Registration could not be finalized because the event or payment reference was missing.',array('status'=>400));
	}
	if ( '' === $name || ! is_email( $email ) ) { return new WP_Error('mrm_masterclass_finalize_invalid_customer','Please enter your name and a valid email address.',array('status'=>400)); }
	if ( ! $terms_accepted ) { return new WP_Error('mrm_masterclass_terms_required','Please accept the Masterclass terms before completing registration.',array('status'=>400)); }
	$events_table = $this->t( 'mrm_masterclass_events' );
	$regs_table   = $this->t( 'mrm_masterclass_registrations' );
	$ledger_table = $this->t( 'mrm_masterclass_payment_ledger' );
	if (! $this->mrm_mc_table_exists( $events_table )|| ! $this->mrm_mc_table_exists( $regs_table )|| ! $this->mrm_mc_table_exists( $ledger_table )) { return new WP_Error('mrm_masterclass_finalize_tables_missing','Registration could not be finalized right now. Please contact support.',array('status'=>503)); }
	$pi_column = $this->mrm_mc_payment_intent_column_for_registrations();
	$existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$regs_table} WHERE {$pi_column} = %s LIMIT 1",$payment_intent_id));
	if ( $existing ) { return rest_ensure_response(array('success'=>true,'already_finalized'=>true,'registration_id'=>absint($existing->id),'gate_url'=>esc_url_raw($existing->gate_url ?? ''),'message'=>'This registration was already finalized.')); }
	$event = $this->mrm_mc_get_event( $event_id );
	if ( ! $event ) { return new WP_Error('mrm_masterclass_event_missing','This Masterclass event could not be found.',array('status'=>404)); }
	if ( 'scheduled' !== sanitize_key( $event->status ) || empty( $event->registration_open ) ) { return new WP_Error('mrm_masterclass_event_closed','Registration is no longer open for this Masterclass.',array('status'=>409)); }
	if ( $this->mrm_mc_available_seats_for_event( $event ) <= 0 ) { return new WP_Error('mrm_masterclass_event_sold_out','This Masterclass is sold out.',array('status'=>409)); }
	$payment_intent = $this->mrm_mc_retrieve_payment_intent( $payment_intent_id );
	if ( is_wp_error( $payment_intent ) ) { return new WP_Error( $payment_intent->get_error_code(),$payment_intent->get_error_message(),array( 'status' => 400 ) ); }
	$status = sanitize_key( $payment_intent['status'] ?? '' );
	if ( 'succeeded' !== $status ) { return new WP_Error('mrm_masterclass_payment_not_succeeded','Payment has not been completed yet. Please finish payment before finalizing registration.',array('status'=>409)); }
	$amount_received = absint( $payment_intent['amount_received'] ?? $payment_intent['amount'] ?? 0 );
	$currency        = strtolower( sanitize_text_field( $payment_intent['currency'] ?? 'usd' ) );
	if ( 'usd' !== $currency || $amount_received <= 0 ) { return new WP_Error('mrm_masterclass_payment_invalid_verified_amount','Payment verification failed. Please contact support.',array('status'=>409)); }
	$base_amount_cents = absint( $event->price_cents );
	$discount_cents    = max( 0, $base_amount_cents - $amount_received );
	$promo = $this->mrm_mc_validate_masterclass_promo_placeholder( $promo_code, $event, $base_amount_cents );
	$promo_status = is_wp_error( $promo ) ? 'error' : sanitize_key( $promo['status'] ?? 'not_applied' );
	$terms = $this->mrm_mc_terms_snapshot(); $gate = $this->mrm_mc_make_gate_token_pair(); $email_hash = hash( 'sha256', strtolower( trim( $email ) ) );
	$share_calc = $this->mrm_mc_calculate_registration_shares( $amount_received, absint( $event->presenter_id ) );
	$stripe_fee = absint( $share_calc['stripe_fee_cents'] );
	$net_cents = absint( $share_calc['net_cents'] );
	$presenter_pct = (float) $share_calc['presenter_percent'];
	$presenter_cut = absint( $share_calc['presenter_share_cents'] );
	$platform_cut  = absint( $share_calc['platform_share_cents'] );
	$terms_snapshot = wp_json_encode( $terms );
	$registration_data = array('event_id'=>$event_id,'first_name'=>$first_name,'last_name'=>$last_name,'name'=>$name,'email'=>$email,'email_hash'=>$email_hash,'stripe_payment_intent_id'=>$payment_intent_id,'payment_intent_id'=>$payment_intent_id,'amount_cents'=>$amount_received,'currency'=>$currency,'payment_status'=>'paid','terms_version'=>sanitize_text_field( $terms['version'] ?? 'v1' ),'terms_accepted'=>1,'terms_snapshot'=>$terms_snapshot,'promo_code'=>$promo_code,'promo_status'=>$promo_status,'discount_cents'=>$discount_cents,'gate_token_hash'=>$gate['hash'],'gate_url'=>$gate['url'],'created_at'=>$this->now(),'updated_at'=>$this->now());
	$registration_data = $this->mrm_mc_filter_data_for_table( $regs_table, $registration_data );
	$inserted = $wpdb->insert( $regs_table, $registration_data );
	if ( false === $inserted ) { return new WP_Error('mrm_masterclass_registration_insert_failed','Payment succeeded, but registration could not be saved. Please contact support.',array('status'=>500)); }
	$registration_id = absint( $wpdb->insert_id );
	$ledger_data = array('event_id'=>$event_id,'registration_id'=>$registration_id,'presenter_id'=>absint( $event->presenter_id ),'ledger_type'=>'registration_payment','stripe_payment_intent_id'=>$payment_intent_id,'payment_intent_id'=>$payment_intent_id,'gross_cents'=>$amount_received,'discount_cents'=>$discount_cents,'stripe_fee_cents'=>$stripe_fee,'estimated_stripe_fee_cents'=>$stripe_fee,'net_cents'=>$net_cents,'presenter_share_cents'=>$presenter_cut,'platform_share_cents'=>$platform_cut,'status'=>'payable','notes'=>'Masterclass registration payment finalized. Presenter payout percent used: ' . $presenter_pct . '%.','payout_eligible_at'=>gmdate( 'Y-m-d H:i:s', strtotime( $event->end_time . ' UTC' ) + WEEK_IN_SECONDS ),'created_at'=>$this->now(),'updated_at'=>$this->now());
	$ledger_data = $this->mrm_mc_filter_data_for_table( $ledger_table, $ledger_data );
	$wpdb->insert($ledger_table,$ledger_data);
	$this->mrm_mc_send_confirmation_for_registration( $registration_id );
	return rest_ensure_response(array('success'=>true,'registration_id'=>$registration_id,'gate_url'=>esc_url_raw( $gate['url'] ),'message'=>'Registration confirmed.'));
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

	public function rest_get_events( $request ) {
		global $wpdb;

		$events_table     = $this->t( 'mrm_masterclass_events' );
		$presenters_table = $this->t( 'mrm_masterclass_presenters' );
		$regs_table       = $this->t( 'mrm_masterclass_registrations' );

		if (
			! $this->mrm_mc_table_exists( $events_table )
			|| ! $this->mrm_mc_table_exists( $presenters_table )
			|| ! $this->mrm_mc_table_exists( $regs_table )
		) {
			$this->mrm_mc_debug_log(
				'Public Masterclass events REST request failed because required tables are missing.',
				array(
					'events_table'     => $events_table,
					'presenters_table' => $presenters_table,
					'regs_table'       => $regs_table,
				)
			);

			return new WP_Error(
				'mrm_masterclass_tables_missing',
				'Masterclass events are temporarily unavailable. Please try again later.',
				array( 'status' => 503 )
			);
		}

		$rows = $wpdb->get_results(
			"SELECT e.*,
				p.name AS presenter_name,
				p.presenter_page_id AS presenter_page_id,
				(SELECT COUNT(*) FROM {$regs_table} r WHERE r.event_id = e.id AND r.payment_status = 'paid') AS paid_count
			 FROM {$events_table} e
			 LEFT JOIN {$presenters_table} p ON p.id = e.presenter_id
			 WHERE e.status = 'scheduled'
			   AND e.registration_open = 1
			   AND e.status <> 'deleted'
			 ORDER BY e.start_time ASC
			 LIMIT 200"
		);

		if ( null === $rows ) {
			$this->mrm_mc_debug_log(
				'Public Masterclass events REST database query failed.',
				array( 'db_error' => $wpdb->last_error )
			);

			return new WP_Error(
				'mrm_masterclass_events_query_failed',
				'Masterclass events could not be loaded. Please try again later.',
				array( 'status' => 500 )
			);
		}

		$events = array();

		foreach ( $rows as $row ) {
			$row->available_seats     = max( 0, absint( $row->capacity ) - absint( $row->paid_count ) );
			$row->presenter_page_url  = $this->mrm_mc_public_presenter_page_url( $row->presenter_page_id );
			$events[]                 = $this->mrm_mc_public_event_payload( $row );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'events'  => $events,
			)
		);
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
