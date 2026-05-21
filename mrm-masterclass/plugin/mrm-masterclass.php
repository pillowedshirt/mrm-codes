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

$autoload = ABSPATH . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

use Aws\SecretsManager\SecretsManagerClient;
use Aws\Exception\AwsException;

if ( ! defined( 'MRM_MASTERCLASS_FILE' ) ) {
	define( 'MRM_MASTERCLASS_FILE', __FILE__ );
}

if ( ! defined( 'MRM_MASTERCLASS_DIR' ) ) {
	define( 'MRM_MASTERCLASS_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'MRM_MASTERCLASS_URL' ) ) {
	define( 'MRM_MASTERCLASS_URL', plugin_dir_url( __FILE__ ) );
}

class MRM_Masterclass_Plugin {
	protected $mrm_secret_diagnostics = array();

	const DB_VERSION = '1.2.1';
	const REST_NAMESPACE = 'mrm-masterclass/v1';
	const DEFAULT_PRICE_CENTS = 2000;

	public function __construct() {
		add_action( 'init', array( $this, 'runtime_upgrade' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'mrm_mc_admin_boot_debug' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		add_action( 'mrm_masterclass_send_reminders', array( $this, 'send_reminders' ) );
		add_action( 'mrm_masterclass_reconcile_events', array( $this, 'reconcile_events' ) );
		$actions = array(
			'mrm_masterclass_save_settings' => 'handle_save_settings',
			'mrm_masterclass_save_presenter' => 'handle_save_presenter',
			'mrm_masterclass_delete_presenter' => 'handle_delete_presenter',
			'mrm_masterclass_save_event' => 'handle_save_event',
			'mrm_masterclass_cancel_event' => 'handle_cancel_event',

			// These methods already exist later in the class, but were not registered.
			'mrm_masterclass_mark_payouts_paid' => 'handle_mark_payouts_paid',
			'mrm_masterclass_save_tax_profile' => 'handle_save_tax_profile',
			'mrm_masterclass_create_presenter_page' => 'handle_create_presenter_page',

			'mrm_masterclass_resend_confirmation' => 'handle_resend_confirmation',
			'mrm_masterclass_resend_reminder' => 'handle_resend_reminder',
			'mrm_masterclass_emergency_cancel_confirm' => 'handle_emergency_cancel_confirm',
			'mrm_masterclass_emergency_cancel_execute' => 'handle_emergency_cancel_execute',
		);
		foreach ( $actions as $k => $m ) {
			add_action( 'admin_post_' . $k, array( $this, $m ) );
		}
		add_action( 'admin_notices', array( $this, 'render_activation_diagnostic_notice' ) );
	}

	public static function activate() { self::install_tables(); @file_put_contents( trailingslashit( WP_CONTENT_DIR ) . 'masterclass-debug.log', '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] Masterclass plugin activated.' . PHP_EOL, FILE_APPEND | LOCK_EX ); set_transient( 'mrm_masterclass_activation_notice', 1, 60 ); if ( ! wp_next_scheduled( 'mrm_masterclass_send_reminders' ) ) { wp_schedule_event( time()+120, 'mrm_masterclass_15min', 'mrm_masterclass_send_reminders' ); } if ( ! wp_next_scheduled( 'mrm_masterclass_reconcile_events' ) ) { wp_schedule_event( time()+300, 'hourly', 'mrm_masterclass_reconcile_events' ); } }
	public static function deactivate() { wp_clear_scheduled_hook( 'mrm_masterclass_send_reminders' ); wp_clear_scheduled_hook( 'mrm_masterclass_reconcile_events' ); }
	public function runtime_upgrade(){ if(get_option('mrm_masterclass_db_version')!==self::DB_VERSION){ self::install_tables(); }}
	private function t($n){global $wpdb; return $wpdb->prefix.$n;}
	public static function install_tables(){ global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $c=$wpdb->get_charset_collate(); $p=$wpdb->prefix;
		dbDelta("CREATE TABLE {$p}mrm_masterclass_presenters (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(191) NOT NULL, email VARCHAR(191) NOT NULL, bio TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id), KEY email(email));");
		dbDelta("CREATE TABLE {$p}mrm_masterclass_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, title VARCHAR(191) NOT NULL, description LONGTEXT NULL, presenter_id BIGINT UNSIGNED NULL, presenter_email VARCHAR(191) NULL, start_time DATETIME NOT NULL, end_time DATETIME NOT NULL, timezone VARCHAR(64) NOT NULL DEFAULT 'UTC', price_cents INT NOT NULL DEFAULT 2000, capacity INT NOT NULL DEFAULT 100, status VARCHAR(32) NOT NULL DEFAULT 'scheduled', registration_open TINYINT(1) NOT NULL DEFAULT 1, google_event_id VARCHAR(191) NULL, google_meet_url TEXT NULL, cancellation_reason TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id), KEY start_time(start_time));");
		dbDelta("CREATE TABLE {$p}mrm_masterclass_registrations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, event_id BIGINT UNSIGNED NOT NULL, first_name VARCHAR(191) NOT NULL, last_name VARCHAR(191) NOT NULL, email VARCHAR(191) NOT NULL, email_hash VARCHAR(64) NOT NULL, stripe_payment_intent_id VARCHAR(191) NULL, amount_cents INT NOT NULL, currency VARCHAR(10) NOT NULL DEFAULT 'usd', payment_status VARCHAR(32) NOT NULL DEFAULT 'pending', google_attendee_added TINYINT(1) NOT NULL DEFAULT 0, terms_version VARCHAR(32) NOT NULL DEFAULT 'v1', terms_accepted TINYINT(1) NOT NULL DEFAULT 0, reminder_24_sent TINYINT(1) NOT NULL DEFAULT 0, reminder_1_sent TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id), KEY event_id(event_id), KEY email(email));");
		dbDelta("CREATE TABLE {$p}mrm_masterclass_refunds (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, registration_id BIGINT UNSIGNED NOT NULL, event_id BIGINT UNSIGNED NOT NULL, stripe_refund_id VARCHAR(191) NULL, amount_cents INT NOT NULL, status VARCHAR(32) NOT NULL, notes TEXT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id), KEY event_id(event_id));");
		dbDelta("CREATE TABLE {$p}mrm_masterclass_email_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, event_id BIGINT UNSIGNED NULL, registration_id BIGINT UNSIGNED NULL, recipient_email VARCHAR(191) NOT NULL, email_type VARCHAR(64) NOT NULL, subject VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, error_message TEXT NULL, sent_at DATETIME NOT NULL, PRIMARY KEY(id), KEY event_id(event_id));");
		dbDelta("CREATE TABLE {$p}mrm_masterclass_unmute_requests (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, event_id BIGINT UNSIGNED NOT NULL, registration_id BIGINT UNSIGNED NULL, participant_name VARCHAR(191) NOT NULL, participant_email VARCHAR(191) NOT NULL, request_note TEXT NULL, status VARCHAR(32) NOT NULL DEFAULT 'pending', presenter_response TEXT NULL, responded_at DATETIME NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id), KEY event_id(event_id));");
		dbDelta("CREATE TABLE {$p}mrm_masterclass_presenter_tax_profiles (
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
	PRIMARY KEY(id),
	KEY presenter_id(presenter_id)
) {$c};");
		dbDelta("CREATE TABLE {$p}mrm_masterclass_payment_ledger (
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
	PRIMARY KEY(id),
	KEY event_id(event_id),
	KEY registration_id(registration_id),
	KEY presenter_id(presenter_id),
	KEY status(status)
) {$c};");

		$presenters_table = $p . 'mrm_masterclass_presenters';
		$events_table     = $p . 'mrm_masterclass_events';
		$ledger_table     = $p . 'mrm_masterclass_payment_ledger';

		$presenter_columns = $wpdb->get_col( "DESC {$presenters_table}", 0 );

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

		$event_adds = array(
			'calendar_id'    => "ALTER TABLE {$events_table} ADD calendar_id VARCHAR(191) NULL",
			'proctor_email'  => "ALTER TABLE {$events_table} ADD proctor_email VARCHAR(191) NULL",
			'cohost_note'    => "ALTER TABLE {$events_table} ADD cohost_note TEXT NULL",
		);

		foreach ( $event_adds as $column => $sql ) {
			if ( ! in_array( $column, $event_columns, true ) ) {
				$wpdb->query( $sql );
			}
		}

		$ledger_columns = $wpdb->get_col( "DESC {$ledger_table}", 0 );

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

		update_option('mrm_masterclass_db_version', self::DB_VERSION);
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

	$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

	if ( 0 !== strpos( $page, 'mrm-masterclass' ) ) {
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
		'page'       => $page,
		'db_version' => get_option( 'mrm_masterclass_db_version' ),
	);

	foreach ( $tables as $label => $table ) {
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$status[ $label . '_table' ] = $exists ? 'exists' : 'missing';
	}

	$this->mrm_mc_debug_log( 'Masterclass admin page loaded.', $status );
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
	private function mrm_mc_get_secret_json( $secret_id, $cache_key = '' ) { $secret_id=(string)$secret_id; $cache_key=$cache_key?(string)$cache_key:'mrm_masterclass_secret_'.md5($secret_id); $cached=get_transient($cache_key); if(is_array($cached)){ $this->mrm_mc_set_secret_diagnostic($secret_id,array('status'=>'cache_hit','message'=>'Secret loaded from WordPress transient cache.','region'=>defined('MRM_AWS_REGION')?MRM_AWS_REGION:'not defined','top_level_keys'=>array_keys($cached))); return $cached; } if(!defined('MRM_AWS_REGION')||!defined('MRM_AWS_ACCESS_KEY_ID')||!defined('MRM_AWS_SECRET_ACCESS_KEY')){ $this->mrm_mc_set_secret_diagnostic($secret_id,array('status'=>'missing_aws_constants','message'=>'One or more required AWS constants are missing in wp-config.php.','region_defined'=>defined('MRM_AWS_REGION')?'yes':'no','key_defined'=>defined('MRM_AWS_ACCESS_KEY_ID')?'yes':'no','secret_defined'=>defined('MRM_AWS_SECRET_ACCESS_KEY')?'yes':'no')); return null; } if(!class_exists('\Aws\SecretsManager\SecretsManagerClient')){ $this->mrm_mc_set_secret_diagnostic($secret_id,array('status'=>'aws_sdk_missing','message'=>'The AWS SDK class Aws\SecretsManager\SecretsManagerClient is not available.','region'=>MRM_AWS_REGION)); return null; } try { $client=new SecretsManagerClient(array('version'=>'latest','region'=>MRM_AWS_REGION,'credentials'=>array('key'=>MRM_AWS_ACCESS_KEY_ID,'secret'=>MRM_AWS_SECRET_ACCESS_KEY))); $result=$client->getSecretValue(array('SecretId'=>$secret_id)); if(empty($result['SecretString'])){ $this->mrm_mc_set_secret_diagnostic($secret_id,array('status'=>'empty_secret_string','message'=>'AWS returned the secret, but SecretString was empty.','region'=>MRM_AWS_REGION)); return null; } $decoded=json_decode((string)$result['SecretString'],true); if(!is_array($decoded)){ $this->mrm_mc_set_secret_diagnostic($secret_id,array('status'=>'json_decode_failed','message'=>'SecretString was returned by AWS, but it was not valid JSON.','region'=>MRM_AWS_REGION,'json_error'=>json_last_error_msg())); return null; } $this->mrm_mc_set_secret_diagnostic($secret_id,array('status'=>'loaded_from_aws','message'=>'Secret was successfully loaded and decoded from AWS Secrets Manager.','region'=>MRM_AWS_REGION,'top_level_keys'=>array_keys($decoded))); set_transient($cache_key,$decoded,15*MINUTE_IN_SECONDS); return $decoded; } catch ( AwsException $e ) { $this->mrm_mc_set_secret_diagnostic($secret_id,array('status'=>'aws_exception','message'=>$e->getAwsErrorMessage(),'aws_error_code'=>$e->getAwsErrorCode(),'region'=>MRM_AWS_REGION)); return null; } catch ( \Throwable $e ) { $this->mrm_mc_set_secret_diagnostic($secret_id,array('status'=>'php_exception','message'=>$e->getMessage(),'region'=>MRM_AWS_REGION)); return null; } }
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
	private function mrm_mc_google_make_jwt( $client_email, $private_key, $scope, $token_url ) { $now=time(); $header=array('alg'=>'RS256','typ'=>'JWT'); $claims=array('iss'=>$client_email,'scope'=>$scope,'aud'=>$token_url,'iat'=>$now,'exp'=>$now+3600); $segments=array($this->mrm_mc_base64url_encode(wp_json_encode($header)),$this->mrm_mc_base64url_encode(wp_json_encode($claims))); $signing_input=implode('.', $segments); $signature=''; $ok=openssl_sign($signing_input,$signature,$private_key,'sha256'); if(!$ok){ return new WP_Error('jwt_sign_failed','OpenSSL failed to sign the Google service account JWT.'); } $segments[]=$this->mrm_mc_base64url_encode($signature); return implode('.', $segments); }
	private function mrm_mc_google_get_access_token() { return ''; }
	private function mrm_mc_google_calendar_request( $method, $url, $body = null ) { return array(); }
	private function mrm_mc_extract_meet_url($event){ if(empty($event['conferenceData']['entryPoints'])) return ''; foreach($event['conferenceData']['entryPoints'] as $ep){ if(($ep['entryPointType']??'')==='video') return $ep['uri']??''; } return ''; }
	private function mrm_mc_google_insert_event( $event, $presenter_email ) { return array('google_event_id'=>'','google_meet_url'=>''); }
	private function mrm_mc_google_patch_event_attendees( $event, $emails ) { return true; }
	private function mrm_mc_google_cancel_event( $google_event_id ) { return true; }
	private function mrm_mc_stripe_request($method,$endpoint,$body=array()){ $sk=$this->mrm_mc_get_stripe_secret_key(); if(!$sk) return new WP_Error('stripe_missing','Stripe key missing'); $args=array('method'=>$method,'headers'=>array('Authorization'=>'Bearer '.$sk,'Content-Type'=>'application/x-www-form-urlencoded')); if($body)$args['body']=http_build_query($body); $r=wp_remote_request('https://api.stripe.com/v1/'.$endpoint,$args); if(is_wp_error($r)) return $r; return json_decode(wp_remote_retrieve_body($r),true); }
	private function mrm_mc_create_payment_intent($event,$email,$terms){ return $this->mrm_mc_stripe_request('POST','payment_intents',array('amount'=>$event->price_cents,'currency'=>'usd','automatic_payment_methods[enabled]'=>'true','metadata[mrm_masterclass_event_id]'=>$event->id,'metadata[mrm_masterclass_customer_email]'=>$email,'metadata[mrm_product_type]'=>'masterclass','metadata[source_flow]'=>'masterclass_registration','metadata[terms_version]'=>$terms['version'],'metadata[terms_accepted]'=>$terms['accepted']?'1':'0')); }
	private function mrm_mc_retrieve_payment_intent($id){ return $this->mrm_mc_stripe_request('GET','payment_intents/'.rawurlencode($id)); }
	private function mrm_mc_refund_payment_intent($pi,$amt){ return $this->mrm_mc_stripe_request('POST','refunds',array('payment_intent'=>$pi,'amount'=>$amt)); }
	public function register_admin_menu() {
	$this->mrm_mc_debug_log( 'Registering Masterclass admin menu.' );

	add_menu_page(
		'MRM Masterclass',
		'MRM Masterclass',
		'manage_options',
		'mrm-masterclass-events',
		array( $this, 'render_events_page' ),
		'dashicons-welcome-learn-more',
		56
	);

	add_submenu_page(
		'mrm-masterclass-events',
		'Events',
		'Events',
		'manage_options',
		'mrm-masterclass-events',
		array( $this, 'render_events_page' )
	);

	add_submenu_page(
		'mrm-masterclass-events',
		'Presenters',
		'Presenters',
		'manage_options',
		'mrm-masterclass-presenters',
		array( $this, 'render_presenters_page' )
	);

	add_submenu_page(
		'mrm-masterclass-events',
		'Registrations / Payments',
		'Registrations / Payments',
		'manage_options',
		'mrm-masterclass-registrations-payments',
		array( $this, 'render_registrations_payments_page' )
	);

	add_submenu_page(
		'mrm-masterclass-events',
		'Presenter Payouts',
		'Presenter Payouts',
		'manage_options',
		'mrm-masterclass-payouts',
		array( $this, 'render_payouts_page' )
	);

	add_submenu_page(
		'mrm-masterclass-events',
		'Tax Profiles',
		'Tax Profiles',
		'manage_options',
		'mrm-masterclass-tax-profiles',
		array( $this, 'render_tax_profiles_page' )
	);
}



public function render_dashboard_page() {
	$this->must_admin();
	$this->mrm_mc_debug_log( 'Legacy Dashboard submenu rendered.' );

	echo '<div class="wrap">';
	echo '<h1>MRM Masterclass</h1>';
	echo '<p>The dashboard has been retired for now. Use Events, Presenters, Registrations / Payments, Presenter Payouts, and Tax Profiles.</p>';
	echo '</div>';
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

	$table   = $this->t( 'mrm_masterclass_presenters' );
	$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	$editing = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $edit_id ), ARRAY_A ) : null;
	$rows    = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC", ARRAY_A );

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

	echo '<div class="wrap">';
	echo '<h1>Masterclass Presenters</h1>';
	echo '<p>Create and manage presenters separately from scheduler instructors. These profiles feed event assignment, payout reporting, and tax profiles.</p>';

	if ( isset( $_GET['saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Presenter saved.</p></div>';
	}

	if ( ! $this->mrm_mc_table_exists( $table ) ) {
		echo '<div class="notice notice-error"><p>The presenters table is missing. Reactivate the plugin or check wp-content/masterclass-debug.log.</p></div>';
		echo '</div>';
		return;
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

	echo '<div class="wrap">';
	echo '<h1>Masterclass Events</h1>';
	echo '<p>Create masterclass sessions, assign presenter/proctor emails, and store the Google Calendar ID this plugin controls.</p>';

	if ( isset( $_GET['created'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Masterclass event created.</p></div>';
	}

	if ( isset( $_GET['settings_updated'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Calendar settings saved.</p></div>';
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

	public function register_rest_routes(){ register_rest_route(self::REST_NAMESPACE,'/events',array('methods'=>'GET','callback'=>array($this,'rest_events'),'permission_callback'=>'__return_true')); register_rest_route(self::REST_NAMESPACE,'/event',array('methods'=>'GET','callback'=>array($this,'rest_event'),'permission_callback'=>'__return_true')); register_rest_route(self::REST_NAMESPACE,'/create-payment-intent',array('methods'=>'POST','callback'=>array($this,'rest_create_pi'),'permission_callback'=>'__return_true')); register_rest_route(self::REST_NAMESPACE,'/verify-payment-intent',array('methods'=>'POST','callback'=>array($this,'rest_verify_pi'),'permission_callback'=>'__return_true')); register_rest_route(self::REST_NAMESPACE,'/finalize-registration',array('methods'=>'POST','callback'=>array($this,'rest_finalize'),'permission_callback'=>'__return_true'));    }
	public function rest_events(){ global $wpdb; $rows=$wpdb->get_results("SELECT e.id,e.title,e.description,p.name presenter_name,e.start_time,e.end_time,e.timezone,e.price_cents,e.capacity,e.status,e.registration_open,(e.capacity-(SELECT COUNT(*) FROM {$this->t('mrm_masterclass_registrations')} r WHERE r.event_id=e.id AND r.payment_status='paid')) available_seats FROM {$this->t('mrm_masterclass_events')} e LEFT JOIN {$this->t('mrm_masterclass_presenters')} p ON p.id=e.presenter_id WHERE e.status='scheduled' ORDER BY e.start_time ASC"); return rest_ensure_response($rows); }
	public function rest_event($r){ global $wpdb; $id=absint($r->get_param('id')); $row=$wpdb->get_row($wpdb->prepare("SELECT e.id,e.title,e.description,p.name presenter_name,e.start_time,e.end_time,e.timezone,e.price_cents,e.capacity,e.status,e.registration_open,e.google_meet_url FROM {$this->t('mrm_masterclass_events')} e LEFT JOIN {$this->t('mrm_masterclass_presenters')} p ON p.id=e.presenter_id WHERE e.id=%d",$id)); return $row?rest_ensure_response($row):new WP_Error('not_found','Event not found',array('status'=>404)); }
	public function rest_create_pi($r){ global $wpdb; $event=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->t('mrm_masterclass_events')} WHERE id=%d",absint($r['event_id']))); if(!$event) return new WP_Error('bad_event','Event not found',array('status'=>404)); $email=sanitize_email($r['email']); $terms=rest_sanitize_boolean($r['terms_accepted']); if(!$terms) return new WP_Error('terms','Terms required',array('status'=>400)); $pi=$this->mrm_mc_create_payment_intent($event,$email,array('version'=>'v1','accepted'=>$terms)); if(is_wp_error($pi)||empty($pi['client_secret'])) return new WP_Error('stripe','Unable to create payment intent',array('status'=>500)); return array('client_secret'=>$pi['client_secret'],'publishable_key'=>$this->mrm_mc_get_stripe_publishable_key()); }
	public function rest_verify_pi($r){ $pi=$this->mrm_mc_retrieve_payment_intent(sanitize_text_field($r['payment_intent_id'])); if(is_wp_error($pi)) return $pi; return array('ok'=>($pi['status']??'')==='succeeded','status'=>$pi['status']??'unknown'); }
	public function rest_finalize($r){ global $wpdb; $event_id=absint($r['event_id']); $pi_id=sanitize_text_field($r['payment_intent_id']); $pi=$this->mrm_mc_retrieve_payment_intent($pi_id); if(is_wp_error($pi)||($pi['status']??'')!=='succeeded') return new WP_Error('unpaid','Payment not complete',array('status'=>400)); $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->t('mrm_masterclass_registrations')} WHERE stripe_payment_intent_id = %s LIMIT 1", $pi_id ) ); if ( $existing ) { return new WP_Error( 'duplicate_registration', 'This payment has already been used for a registration.', array( 'status' => 409 ) ); } $event=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->t('mrm_masterclass_events')} WHERE id=%d",$event_id)); if ( ! $event ) { return new WP_Error( 'event_not_found', 'Masterclass event not found.', array( 'status' => 404 ) ); } $paid_count = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->t('mrm_masterclass_registrations')} WHERE event_id = %d AND payment_status = 'paid'", $event_id ) ) ); if ( $paid_count >= absint( $event->capacity ) ) { return new WP_Error( 'event_full', 'This masterclass is full.', array( 'status' => 409 ) ); } $now=$this->now(); $wpdb->insert($this->t('mrm_masterclass_registrations'),array('event_id'=>$event_id,'first_name'=>sanitize_text_field($r['first_name']),'last_name'=>sanitize_text_field($r['last_name']),'email'=>sanitize_email($r['email']),'email_hash'=>hash('sha256',strtolower(trim($r['email']))),'stripe_payment_intent_id'=>$pi_id,'amount_cents'=>$event->price_cents,'payment_status'=>'paid','terms_accepted'=>1,'created_at'=>$now,'updated_at'=>$now)); $rid = $wpdb->insert_id; $split = $this->calculate_masterclass_payment_split( $event, absint( $event->price_cents ) ); $wpdb->insert($this->t( 'mrm_masterclass_payment_ledger' ),array('event_id'=>$event_id,'registration_id'=>$rid,'presenter_id'=>absint( $event->presenter_id ),'ledger_type'=>'registration_payment','stripe_payment_intent_id'=>$pi_id,'gross_cents'=>$split['gross_cents'],'stripe_fee_cents'=>$split['stripe_fee_cents'],'net_cents'=>$split['net_cents'],'presenter_share_cents'=>$split['presenter_share_cents'],'platform_share_cents'=>$split['platform_share_cents'],'status'=>'recorded','notes'=>'Masterclass registration payment recorded after successful Stripe payment.','created_at'=>$now,'updated_at'=>$now,)); $this->send_email(sanitize_email( $r['email'] ),'Masterclass registration confirmed','<h2>Registration Confirmed</h2><p>Thanks for registering. You will receive your masterclass details by email.</p>','confirmation',$event_id,$rid); return array('ok'=>true,'registration_id'=>$rid); }
	
	
	
	private function calculate_masterclass_payment_split( $event, $amount_cents ) {
	$settings = get_option( 'mrm_masterclass_settings', array() );

	$presenter_percent = floatval( $settings['presenter_default_percent'] ?? 70 );
	$stripe_percent    = floatval( $settings['stripe_fee_estimate_percent'] ?? 2.9 );
	$stripe_fixed      = absint( $settings['stripe_fee_estimate_fixed'] ?? 30 );

	if ( $presenter_percent < 0 ) {
		$presenter_percent = 0;
	}

	if ( $presenter_percent > 100 ) {
		$presenter_percent = 100;
	}

	$stripe_fee_cents = (int) round( ( $amount_cents * ( $stripe_percent / 100 ) ) + $stripe_fixed );
	$net_cents        = max( 0, $amount_cents - $stripe_fee_cents );

	$presenter_share_cents = (int) round( $net_cents * ( $presenter_percent / 100 ) );
	$platform_share_cents  = max( 0, $net_cents - $presenter_share_cents );

	return array(
		'gross_cents'           => $amount_cents,
		'stripe_fee_cents'      => $stripe_fee_cents,
		'net_cents'             => $net_cents,
		'presenter_share_cents' => $presenter_share_cents,
		'platform_share_cents'  => $platform_share_cents,
	);
}

private function send_email($to,$subject,$body,$type,$event_id=0,$registration_id=0){ $headers=array('Content-Type: text/html; charset=UTF-8','From: LowBrass Lessons <no-reply@lowbrass-lessons.com>'); $wrapped='<div style="font-family:Arial,sans-serif">'.$body.'</div>'; $ok=wp_mail($to,$subject,$wrapped,$headers); global $wpdb; $wpdb->insert($this->t('mrm_masterclass_email_log'),array('event_id'=>$event_id?:null,'registration_id'=>$registration_id?:null,'recipient_email'=>$to,'email_type'=>$type,'subject'=>$subject,'status'=>$ok?'sent':'failed','sent_at'=>$this->now())); return $ok; }
	public function send_reminders(){}
	public function reconcile_events(){}
	public function handle_save_settings() {
	$this->must_admin();
	check_admin_referer( 'mrm_masterclass_save_settings' );

	$presenter_percent = floatval( $_POST['presenter_default_percent'] ?? 70 );

	if ( $presenter_percent < 0 ) {
		$presenter_percent = 0;
	}

	if ( $presenter_percent > 100 ) {
		$presenter_percent = 100;
	}

	$settings = array(
		'masterclass_calendar_id'       => sanitize_text_field( wp_unslash( $_POST['masterclass_calendar_id'] ?? '' ) ),
		'default_price_cents'           => max( 0, absint( $_POST['default_price_cents'] ?? self::DEFAULT_PRICE_CENTS ) ),
		'default_capacity'              => max( 1, absint( $_POST['default_capacity'] ?? 100 ) ),
		'default_timezone'              => sanitize_text_field( wp_unslash( $_POST['default_timezone'] ?? 'America/Phoenix' ) ),
		'admin_notification_email'      => sanitize_email( wp_unslash( $_POST['admin_notification_email'] ?? get_option( 'admin_email' ) ) ),
		'from_email'                    => sanitize_email( wp_unslash( $_POST['from_email'] ?? 'no-reply@lowbrass-lessons.com' ) ),
		'terms_version'                 => sanitize_text_field( wp_unslash( $_POST['terms_version'] ?? 'v1' ) ),
		'presenter_default_percent'     => $presenter_percent,
		'stripe_fee_estimate_percent'   => floatval( $_POST['stripe_fee_estimate_percent'] ?? 2.9 ),
		'stripe_fee_estimate_fixed'     => max( 0, absint( $_POST['stripe_fee_estimate_fixed'] ?? 30 ) ),
		'cancellation_policy_text'      => wp_kses_post( wp_unslash( $_POST['cancellation_policy_text'] ?? '' ) ),
	);

	update_option( 'mrm_masterclass_settings', $settings );

	wp_safe_redirect( admin_url( 'admin.php?page=mrm-masterclass-events&settings_updated=1' ) );
	exit;
}

	public function handle_save_presenter() {
	$this->must_admin();
	check_admin_referer( 'mrm_masterclass_save_presenter' );

	global $wpdb;

	$id      = absint( $_POST['id'] ?? 0 );
	$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	$email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	$city    = sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) );
	$state   = strtoupper( sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) ) );
	$state   = substr( preg_replace( '/[^A-Z]/', '', $state ), 0, 2 );
	$address = sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) );
	$zip     = sanitize_text_field( wp_unslash( $_POST['zip_code'] ?? '' ) );

	$timezone = sanitize_text_field( wp_unslash( $_POST['timezone'] ?? 'America/Phoenix' ) );
	$stripe_connected_account_id = sanitize_text_field( wp_unslash( $_POST['stripe_connected_account_id'] ?? '' ) );
	$hire_date = sanitize_text_field( wp_unslash( $_POST['hire_date'] ?? '' ) );

	$profile_image_url = esc_url_raw( wp_unslash( $_POST['profile_image_url'] ?? '' ) );
	$short_description = sanitize_textarea_field( wp_unslash( $_POST['short_description'] ?? '' ) );
	$long_description  = wp_kses_post( wp_unslash( $_POST['long_description'] ?? '' ) );
	$bio               = wp_kses_post( wp_unslash( $_POST['bio'] ?? '' ) );

	$instruments = isset( $_POST['instruments'] ) && is_array( $_POST['instruments'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['instruments'] ) )
		: array();

	$instruments_json = $instruments ? wp_json_encode( array_values( $instruments ) ) : '';

	if ( ! $name ) {
		wp_die( 'Presenter name is required.' );
	}

	if ( ! is_email( $email ) ) {
		wp_die( 'A valid presenter email is required.' );
	}

	if ( $stripe_connected_account_id && ! preg_match( '/^acct_[A-Za-z0-9]+$/', $stripe_connected_account_id ) ) {
		wp_die( 'Stripe Connected Account ID must start with acct_.' );
	}

	$now = $this->now();

	$data = array(
		'name'                        => $name,
		'email'                       => $email,
		'city'                        => $city,
		'state'                       => $state,
		'address'                     => $address,
		'zip_code'                    => $zip,
		'timezone'                    => $timezone,
		'stripe_connected_account_id' => $stripe_connected_account_id ?: null,
		'hire_date'                   => $hire_date ?: null,
		'profile_image_url'           => $profile_image_url ?: null,
		'short_description'           => $short_description ?: null,
		'long_description'            => $long_description ?: null,
		'instruments'                 => $instruments_json ?: null,
		'bio'                         => $bio ?: null,
		'updated_at'                  => $now,
	);

	if ( $id ) {
		$wpdb->update( $this->t( 'mrm_masterclass_presenters' ), $data, array( 'id' => $id ) );
	} else {
		$data['created_at'] = $now;
		$wpdb->insert( $this->t( 'mrm_masterclass_presenters' ), $data );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=mrm-masterclass-presenters&saved=1' ) );
	exit;
}

	public function handle_delete_presenter() {
	$this->must_admin();

	$id = absint( $_GET['id'] ?? 0 );

	if ( ! $id ) {
		wp_die( 'Missing presenter ID.' );
	}

	check_admin_referer( 'mrm_masterclass_delete_presenter_' . $id );

	global $wpdb;

	$events_count = absint(
		$wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->t( 'mrm_masterclass_events' )} WHERE presenter_id = %d",
				$id
			)
		)
	);

	if ( $events_count > 0 ) {
		wp_die( 'This presenter is assigned to one or more events. Cancel/archive those events or create a replacement presenter before deleting.' );
	}

	$wpdb->delete( $this->t( 'mrm_masterclass_presenters' ), array( 'id' => $id ), array( '%d' ) );

	wp_safe_redirect( admin_url( 'admin.php?page=mrm-masterclass-presenters&deleted=1' ) );
	exit;
}

public function handle_mark_payouts_paid() {
	$this->must_admin();
	check_admin_referer( 'mrm_masterclass_mark_payouts_paid' );

	$ids = isset( $_POST['ledger_ids'] ) && is_array( $_POST['ledger_ids'] )
		? array_map( 'absint', $_POST['ledger_ids'] )
		: array();

	$ids = array_values( array_filter( $ids ) );

	if ( ! $ids ) {
		wp_safe_redirect( admin_url( 'admin.php?page=mrm-masterclass-payouts' ) );
		exit;
	}

	global $wpdb;

	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$batch_id     = 'mc_' . gmdate( 'Ymd_His' );
	$now          = $this->now();

	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$this->t( 'mrm_masterclass_payment_ledger' )}
			 SET status = 'paid_out',
			     payout_batch_id = %s,
			     paid_out_at = %s,
			     updated_at = %s
			 WHERE id IN ({$placeholders})
			   AND ledger_type = 'registration_payment'
			   AND status = 'recorded'",
			array_merge( array( $batch_id, $now, $now ), $ids )
		)
	);

	$this->mrm_mc_debug_log( 'Payout rows marked paid_out.', array( 'requested_rows' => count( $ids ), 'updated_rows' => absint( $updated ), 'batch_id' => $batch_id ) );

	wp_safe_redirect( admin_url( 'admin.php?page=mrm-masterclass-payouts&paid=1' ) );
	exit;
}


public function handle_save_tax_profile() {
	$this->must_admin();

	$presenter_id = absint( $_POST['presenter_id'] ?? 0 );

	if ( ! $presenter_id ) {
		wp_die( 'Missing presenter ID.' );
	}

	check_admin_referer( 'mrm_masterclass_save_tax_profile_' . $presenter_id );

	global $wpdb;

	$table = $this->t( 'mrm_masterclass_presenter_tax_profiles' );
	$now   = $this->now();

	$tin_last4 = preg_replace( '/[^0-9]/', '', wp_unslash( $_POST['tin_last4'] ?? '' ) );
	$tin_last4 = substr( $tin_last4, -4 );

	$data = array(
		'presenter_id'       => $presenter_id,
		'legal_name'         => sanitize_text_field( wp_unslash( $_POST['legal_name'] ?? '' ) ),
		'business_name'      => sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) ),
		'email'              => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
		'tin_last4'          => $tin_last4,
		'tin_type'           => sanitize_text_field( wp_unslash( $_POST['tin_type'] ?? '' ) ),
		'w9_received'        => isset( $_POST['w9_received'] ) ? 1 : 0,
		'w9_received_date'   => sanitize_text_field( wp_unslash( $_POST['w9_received_date'] ?? '' ) ) ?: null,
		'address_line1'      => sanitize_text_field( wp_unslash( $_POST['address_line1'] ?? '' ) ),
		'address_line2'      => sanitize_text_field( wp_unslash( $_POST['address_line2'] ?? '' ) ),
		'city'               => sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) ),
		'state'              => sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) ),
		'zip'                => sanitize_text_field( wp_unslash( $_POST['zip'] ?? '' ) ),
		'is_1099_eligible'   => isset( $_POST['is_1099_eligible'] ) ? 1 : 0,
		'exclude_from_1099'  => isset( $_POST['exclude_from_1099'] ) ? 1 : 0,
		'notes'              => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
		'updated_at'         => $now,
	);

	$existing_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE presenter_id = %d LIMIT 1",
			$presenter_id
		)
	);

	if ( $existing_id ) {
		$wpdb->update( $table, $data, array( 'id' => absint( $existing_id ) ) );
	} else {
		$data['created_at'] = $now;
		$wpdb->insert( $table, $data );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=mrm-masterclass-tax-profiles&updated=1' ) );
	exit;
}


public function handle_create_presenter_page() {
	$this->must_admin();

	$presenter_id = absint( $_POST['presenter_id'] ?? $_GET['presenter_id'] ?? 0 );

	if ( ! $presenter_id ) {
		wp_die( 'Missing presenter ID.' );
	}

	check_admin_referer( 'mrm_masterclass_create_presenter_page_' . $presenter_id );

	global $wpdb;

	$table = $this->t( 'mrm_masterclass_presenters' );
	$presenter = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $presenter_id ) );

	if ( ! $presenter ) {
		wp_die( 'Presenter not found.' );
	}

	$content = '<h2>' . esc_html( $presenter->name ) . '</h2>';

	if ( ! empty( $presenter->profile_image_url ) ) {
		$content .= '<p><img src="' . esc_url( $presenter->profile_image_url ) . '" alt="' . esc_attr( $presenter->name ) . '" style="max-width:220px;height:auto;border-radius:14px;"></p>';
	}

	if ( ! empty( $presenter->long_description ) ) {
		$content .= wp_kses_post( wpautop( $presenter->long_description ) );
	} elseif ( ! empty( $presenter->short_description ) ) {
		$content .= wp_kses_post( wpautop( $presenter->short_description ) );
	} elseif ( ! empty( $presenter->bio ) ) {
		$content .= wp_kses_post( wpautop( $presenter->bio ) );
	}

	$page_id = absint( $presenter->presenter_page_id ?? 0 );

	$postarr = array(
		'post_title'   => $presenter->name,
		'post_content' => $content,
		'post_status'  => 'draft',
		'post_type'    => 'page',
	);

	if ( $page_id && get_post( $page_id ) ) {
		$postarr['ID'] = $page_id;
		$new_page_id = wp_update_post( $postarr, true );
	} else {
		$new_page_id = wp_insert_post( $postarr, true );
	}

	if ( is_wp_error( $new_page_id ) ) {
		$this->mrm_mc_debug_log( 'Presenter page creation failed.', array( 'presenter_id' => $presenter_id, 'error' => $new_page_id->get_error_message() ) );
		wp_die( 'Presenter page could not be created.' );
	}

	$wpdb->update(
		$table,
		array(
			'presenter_page_id' => absint( $new_page_id ),
			'updated_at'        => $this->now(),
		),
		array( 'id' => $presenter_id )
	);

	$this->mrm_mc_debug_log( 'Presenter page created/updated.', array( 'presenter_id' => $presenter_id, 'page_id' => absint( $new_page_id ) ) );

	wp_safe_redirect( admin_url( 'admin.php?page=mrm-masterclass-presenters&saved=1' ) );
	exit;
}

	public function handle_save_event() {
	$this->must_admin();
	check_admin_referer( 'mrm_masterclass_save_event' );

	global $wpdb;

	$title        = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
	$description  = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
	$presenter_id = absint( $_POST['presenter_id'] ?? 0 );
	$proctor_email = sanitize_email( wp_unslash( $_POST['proctor_email'] ?? '' ) );
	$start_time   = sanitize_text_field( wp_unslash( $_POST['start_time'] ?? '' ) );
	$end_time     = sanitize_text_field( wp_unslash( $_POST['end_time'] ?? '' ) );
	$timezone     = sanitize_text_field( wp_unslash( $_POST['timezone'] ?? 'America/Phoenix' ) );
	$price_cents  = max( 0, absint( $_POST['price_cents'] ?? self::DEFAULT_PRICE_CENTS ) );
	$capacity     = max( 1, absint( $_POST['capacity'] ?? 100 ) );
	$status       = sanitize_text_field( wp_unslash( $_POST['status'] ?? 'scheduled' ) );
	$open         = isset( $_POST['registration_open'] ) ? 1 : 0;
	$settings     = $this->settings();

	if ( ! $title || ! $presenter_id || ! $start_time || ! $end_time ) {
		$this->mrm_mc_debug_log( 'Event save failed: missing required fields.', compact( 'title', 'presenter_id', 'start_time', 'end_time' ) );
		wp_die( 'Title, presenter, start time, and end time are required.' );
	}

	$presenter = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t( 'mrm_masterclass_presenters' )} WHERE id = %d", $presenter_id ) );

	if ( ! $presenter || ! is_email( $presenter->email ) ) {
		$this->mrm_mc_debug_log( 'Event save failed: presenter missing or invalid.', array( 'presenter_id' => $presenter_id ) );
		wp_die( 'A valid presenter is required.' );
	}

	if ( $proctor_email && ! is_email( $proctor_email ) ) {
		wp_die( 'The optional proctor email is not valid.' );
	}

	if ( ! in_array( $status, array( 'draft', 'scheduled', 'cancelled', 'completed', 'archived' ), true ) ) {
		$status = 'scheduled';
	}

	$start_sql = str_replace( 'T', ' ', $start_time );
	$end_sql   = str_replace( 'T', ' ', $end_time );

	if ( strlen( $start_sql ) === 16 ) {
		$start_sql .= ':00';
	}

	if ( strlen( $end_sql ) === 16 ) {
		$end_sql .= ':00';
	}

	$now = $this->now();

	$data = array(
		'title'              => $title,
		'description'        => $description,
		'presenter_id'       => $presenter_id,
		'presenter_email'    => $presenter->email,
		'calendar_id'        => sanitize_text_field( $settings['masterclass_calendar_id'] ?? '' ),
		'proctor_email'      => $proctor_email ?: null,
		'cohost_note'        => 'Presenter and optional proctor should be treated as event staff/co-host candidates.',
		'start_time'         => $start_sql,
		'end_time'           => $end_sql,
		'timezone'           => $timezone,
		'price_cents'        => $price_cents,
		'capacity'           => $capacity,
		'status'             => $status,
		'registration_open'  => $open,
		'created_at'         => $now,
		'updated_at'         => $now,
	);

	$inserted = $wpdb->insert( $this->t( 'mrm_masterclass_events' ), $data );

	if ( false === $inserted ) {
		$this->mrm_mc_debug_log( 'Event insert failed.', array( 'db_error' => $wpdb->last_error ) );
		wp_die( 'The event could not be saved. Check wp-content/masterclass-debug.log for details.' );
	}

	$event_id = absint( $wpdb->insert_id );
	$this->mrm_mc_debug_log( 'Event saved locally.', array( 'event_id' => $event_id, 'title' => $title ) );

	if ( $event_id ) {
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t( 'mrm_masterclass_events' )} WHERE id = %d", $event_id ) );

		if ( $event ) {
			$google = $this->mrm_mc_google_insert_event( $event, $event->presenter_email );

			if ( is_wp_error( $google ) ) {
				$this->mrm_mc_debug_log( 'Google event creation failed.', array( 'event_id' => $event_id, 'error' => $google->get_error_message() ) );
			} elseif ( is_array( $google ) && ( ! empty( $google['google_event_id'] ) || ! empty( $google['google_meet_url'] ) ) ) {
				$wpdb->update(
					$this->t( 'mrm_masterclass_events' ),
					array(
						'google_event_id' => sanitize_text_field( $google['google_event_id'] ?? '' ),
						'google_meet_url' => esc_url_raw( $google['google_meet_url'] ?? '' ),
						'updated_at'      => $this->now(),
					),
					array( 'id' => $event_id )
				);

				$this->mrm_mc_debug_log( 'Google event fields saved.', array( 'event_id' => $event_id, 'has_meet_url' => ! empty( $google['google_meet_url'] ) ? 'yes' : 'no' ) );
			} else {
				$this->mrm_mc_debug_log( 'Google event creation skipped or returned empty values.', array( 'event_id' => $event_id ) );
			}
		}
	}

	wp_safe_redirect( admin_url( 'admin.php?page=mrm-masterclass-events&created=1' ) );
	exit;
}

	public function handle_cancel_event() {
	$this->must_admin();

	$id = absint( $_GET['id'] ?? 0 );

	if ( ! $id ) {
		wp_die( 'Missing event ID.' );
	}

	check_admin_referer( 'mrm_masterclass_cancel_event_' . $id );

	global $wpdb;

	$event = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$this->t( 'mrm_masterclass_events' )} WHERE id = %d",
			$id
		)
	);

	if ( ! $event ) {
		wp_die( 'Event not found.' );
	}

	$wpdb->update(
		$this->t( 'mrm_masterclass_events' ),
		array(
			'status'              => 'cancelled',
			'registration_open'   => 0,
			'cancellation_reason' => 'Cancelled from Masterclass admin.',
			'updated_at'          => $this->now(),
		),
		array( 'id' => $id ),
		array( '%s', '%d', '%s', '%s' ),
		array( '%d' )
	);

	if ( ! empty( $event->google_event_id ) ) {
		$this->mrm_mc_google_cancel_event( $event->google_event_id );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=mrm-masterclass-events&cancelled=1' ) );
	exit;
}
	public function handle_resend_confirmation(){ $this->must_admin(); }
	public function handle_resend_reminder(){ $this->must_admin(); }
	private function sign_token($payload,$ttl=3600){ $payload['exp']=time()+$ttl; $raw=wp_json_encode($payload); $sig=hash_hmac('sha256',$raw,wp_salt('auth')); return rtrim(strtr(base64_encode($raw.'||'.$sig),'+/','-_'),'='); }
	private function verify_token($token){ $dec=base64_decode(strtr($token,'-_','+/')); if(!$dec||strpos($dec,'||')===false) return false; list($raw,$sig)=explode('||',$dec,2); if(!hash_equals(hash_hmac('sha256',$raw,wp_salt('auth')),$sig)) return false; $p=json_decode($raw,true); if(empty($p['exp'])||time()>$p['exp']) return false; return $p; }
	public function handle_emergency_cancel_confirm(){ $token=sanitize_text_field($_GET['token']??''); $p=$this->verify_token($token); if(!$p) wp_die('Invalid token'); echo '<h1>Confirm Emergency Cancellation</h1><p>This will cancel the masterclass, notify all paid participants, and issue refunds.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mrm_masterclass_emergency_cancel_execute"/><input type="hidden" name="token" value="'.esc_attr($token).'"/><button type="submit">Confirm cancellation and refunds</button></form>'; exit; }
	public function handle_emergency_cancel_execute(){
	$p=$this->verify_token(sanitize_text_field($_POST['token']??''));
	if(!$p) {
		wp_die('Invalid token');
	}

	wp_die('Emergency cancellation executed.');
}

public function render_activation_diagnostic_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
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

function mrm_masterclass_plugin() {
	static $instance = null;

	if ( ! $instance ) {
		$instance = new MRM_Masterclass_Plugin();
	}

	return $instance;
}

register_activation_hook( __FILE__, array( 'MRM_Masterclass_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MRM_Masterclass_Plugin', 'deactivate' ) );

mrm_masterclass_plugin();
