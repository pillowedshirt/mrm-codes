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
	const DB_VERSION = '1.1.0';
	const REST_NAMESPACE = 'mrm-masterclass/v1';
	const DEFAULT_PRICE_CENTS = 2000;

	public function __construct() {
		add_action( 'init', array( $this, 'runtime_upgrade' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		add_action( 'mrm_masterclass_send_reminders', array( $this, 'send_reminders' ) );
		add_action( 'mrm_masterclass_reconcile_events', array( $this, 'reconcile_events' ) );
		$actions = array(
			'mrm_masterclass_save_settings' => 'handle_save_settings',
			'mrm_masterclass_save_presenter' => 'handle_save_presenter',
			'mrm_masterclass_delete_presenter' => 'handle_delete_presenter',
			'mrm_masterclass_save_event' => 'handle_save_event',
			'mrm_masterclass_cancel_event' => 'handle_cancel_event',
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

	public static function activate() { self::install_tables(); set_transient( 'mrm_masterclass_activation_notice', 1, 60 ); if ( ! wp_next_scheduled( 'mrm_masterclass_send_reminders' ) ) { wp_schedule_event( time()+120, 'mrm_masterclass_15min', 'mrm_masterclass_send_reminders' ); } if ( ! wp_next_scheduled( 'mrm_masterclass_reconcile_events' ) ) { wp_schedule_event( time()+300, 'hourly', 'mrm_masterclass_reconcile_events' ); } }
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
		update_option('mrm_masterclass_db_version', self::DB_VERSION);
	}
	public function add_cron_schedule($s){$s['mrm_masterclass_15min']=array('interval'=>900,'display'=>'Every 15 Minutes'); return $s;}
	private function now(){return gmdate('Y-m-d H:i:s');}

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
	private function mrm_mc_get_secret_json($secret_id){ if(!defined('AWS_ACCESS_KEY_ID')) return null; return null; }
	private function mrm_mc_get_google_scheduler_secret_bundle(){ return $this->mrm_mc_get_secret_json('lowbrass/google/scheduler'); }
	private function mrm_mc_get_google_service_account_json(){ $b=$this->mrm_mc_get_google_scheduler_secret_bundle(); return is_array($b)&&isset($b['service_account_json'])?$b['service_account_json']:''; }
	private function mrm_mc_get_stripe_secret_bundle(){ return $this->mrm_mc_get_secret_json('lowbrass/stripe/keys'); }
	private function mrm_mc_get_stripe_secret_key(){ $b=$this->mrm_mc_get_stripe_secret_bundle(); return is_array($b)?($b['secret_key']??''):''; }
	private function mrm_mc_get_stripe_publishable_key(){ $b=$this->mrm_mc_get_stripe_secret_bundle(); return is_array($b)?($b['publishable_key']??''):''; }
	private function mrm_mc_google_get_access_token(){ return ''; }
	private function mrm_mc_extract_meet_url($event){ if(empty($event['conferenceData']['entryPoints'])) return ''; foreach($event['conferenceData']['entryPoints'] as $ep){ if(($ep['entryPointType']??'')==='video') return $ep['uri']??''; } return ''; }
	private function mrm_mc_google_insert_event($event,$presenter_email){ return array('google_event_id'=>'','google_meet_url'=>''); }
	private function mrm_mc_google_patch_event_attendees($event,$emails){ return true; }
	private function mrm_mc_google_cancel_event($google_event_id){ return true; }
	private function mrm_mc_stripe_request($method,$endpoint,$body=array()){ $sk=$this->mrm_mc_get_stripe_secret_key(); if(!$sk) return new WP_Error('stripe_missing','Stripe key missing'); $args=array('method'=>$method,'headers'=>array('Authorization'=>'Bearer '.$sk,'Content-Type'=>'application/x-www-form-urlencoded')); if($body)$args['body']=http_build_query($body); $r=wp_remote_request('https://api.stripe.com/v1/'.$endpoint,$args); if(is_wp_error($r)) return $r; return json_decode(wp_remote_retrieve_body($r),true); }
	private function mrm_mc_create_payment_intent($event,$email,$terms){ return $this->mrm_mc_stripe_request('POST','payment_intents',array('amount'=>$event->price_cents,'currency'=>'usd','automatic_payment_methods[enabled]'=>'true','metadata[mrm_masterclass_event_id]'=>$event->id,'metadata[mrm_masterclass_customer_email]'=>$email,'metadata[mrm_product_type]'=>'masterclass','metadata[source_flow]'=>'masterclass_registration','metadata[terms_version]'=>$terms['version'],'metadata[terms_accepted]'=>$terms['accepted']?'1':'0')); }
	private function mrm_mc_retrieve_payment_intent($id){ return $this->mrm_mc_stripe_request('GET','payment_intents/'.rawurlencode($id)); }
	private function mrm_mc_refund_payment_intent($pi,$amt){ return $this->mrm_mc_stripe_request('POST','refunds',array('payment_intent'=>$pi,'amount'=>$amt)); }
	public function register_admin_menu() {
	add_menu_page(
		'MRM Masterclass',
		'MRM Masterclass',
		'manage_options',
		'mrm-masterclass',
		array( $this, 'render_dashboard_page' ),
		'dashicons-groups',
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
		'Registrations',
		'Registrations',
		'manage_options',
		'mrm-masterclass-registrations',
		array( $this, 'render_registrations_page' )
	);

	add_submenu_page(
		'mrm-masterclass',
		'Payments',
		'Payments',
		'manage_options',
		'mrm-masterclass-payments',
		array( $this, 'render_payments_page' )
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
		'1099 Documents',
		'1099 Documents',
		'manage_options',
		'mrm-masterclass-1099',
		array( $this, 'render_1099_page' )
	);

	add_submenu_page(
		'mrm-masterclass',
		'Presenter Tax Profiles',
		'Presenter Tax Profiles',
		'manage_options',
		'mrm-masterclass-tax-profiles',
		array( $this, 'render_tax_profiles_page' )
	);

	add_submenu_page(
		'mrm-masterclass',
		'Email Log',
		'Email Log',
		'manage_options',
		'mrm-masterclass-email-log',
		array( $this, 'render_email_log_page' )
	);

	add_submenu_page(
		'mrm-masterclass',
		'Settings',
		'Settings',
		'manage_options',
		'mrm-masterclass-settings',
		array( $this, 'render_settings_page' )
	);
}

public function render_dashboard_page() {
	$this->must_admin();

	global $wpdb;

	$events_table        = $this->t( 'mrm_masterclass_events' );
	$registrations_table = $this->t( 'mrm_masterclass_registrations' );
	$ledger_table        = $this->t( 'mrm_masterclass_payment_ledger' );

	$total_events = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" ) );
	$scheduled    = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table} WHERE status = 'scheduled'" ) );
	$paid_regs    = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$registrations_table} WHERE payment_status = 'paid'" ) );
	$gross_cents  = absint( $wpdb->get_var( "SELECT COALESCE(SUM(gross_cents),0) FROM {$ledger_table} WHERE ledger_type = 'registration_payment'" ) );
	?>
	<div class="wrap"><h1>MRM Masterclass Dashboard</h1></div>
	<?php
}

public function render_settings_page() {
	$this->must_admin();
	?>
	<div class="wrap"><h1>MRM Masterclass Settings</h1></div>
	<?php
}

public function render_presenters_page() { $this->must_admin(); echo '<div class="wrap"><h1>Masterclass Presenters</h1></div>'; }
public function render_events_page() { $this->must_admin(); echo '<div class="wrap"><h1>Masterclass Events</h1></div>'; }
public function render_registrations_page() { $this->must_admin(); echo '<div class="wrap"><h1>Masterclass Registrations</h1></div>'; }
public function render_payments_page() { $this->must_admin(); echo '<div class="wrap"><h1>Masterclass Payments</h1></div>'; }
public function render_payouts_page() { $this->must_admin(); echo '<div class="wrap"><h1>Presenter Payouts</h1><p>This submenu is reserved for marking presenter payout periods as paid out. It needs the payout-period creation workflow added next.</p></div>'; }
public function render_1099_page() { $this->must_admin(); echo '<div class="wrap"><h1>Masterclass 1099 Documents</h1></div>'; }
public function render_tax_profiles_page() {
	$this->must_admin();

	global $wpdb;

	$presenters_table = $this->t( 'mrm_masterclass_presenters' );
	$profiles_table   = $this->t( 'mrm_masterclass_presenter_tax_profiles' );

	$rows = $wpdb->get_results(
		"SELECT p.id, p.name, p.email, t.legal_name, t.business_name, t.tin_last4, t.w9_received, t.is_1099_eligible, t.exclude_from_1099
		 FROM {$presenters_table} p
		 LEFT JOIN {$profiles_table} t ON t.presenter_id = p.id
		 ORDER BY p.name ASC"
	);

	echo '<div class="wrap">';
	echo '<h1>Presenter Tax Profiles</h1>';
	echo '<p>Use this page to track W-9 status, 1099 eligibility, and presenter tax profile readiness. Do not store full SSNs or full EINs in WordPress.</p>';

	echo '<table class="widefat striped">';
	echo '<thead><tr>';
	echo '<th>Presenter</th>';
	echo '<th>Email</th>';
	echo '<th>Legal Name</th>';
	echo '<th>Business Name</th>';
	echo '<th>TIN Last 4</th>';
	echo '<th>W-9 Received</th>';
	echo '<th>1099 Eligible</th>';
	echo '<th>Excluded</th>';
	echo '</tr></thead><tbody>';

	if ( $rows ) {
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row->name ) . '</td>';
			echo '<td>' . esc_html( $row->email ) . '</td>';
			echo '<td>' . esc_html( $row->legal_name ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $row->business_name ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $row->tin_last4 ?: '—' ) . '</td>';
			echo '<td>' . ( $row->w9_received ? 'Yes' : 'No' ) . '</td>';
			echo '<td>' . ( is_null( $row->is_1099_eligible ) || $row->is_1099_eligible ? 'Yes' : 'No' ) . '</td>';
			echo '<td>' . ( $row->exclude_from_1099 ? 'Yes' : 'No' ) . '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="8">No presenters found yet.</td></tr>';
	}

	echo '</tbody></table>';
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

	wp_safe_redirect( admin_url( 'admin.php?page=mrm-masterclass-settings&updated=1' ) );
	exit;
}

	public function handle_save_presenter() {
	$this->must_admin();
	check_admin_referer( 'mrm_masterclass_save_presenter' );
	global $wpdb;
	$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	$bio   = wp_kses_post( wp_unslash( $_POST['bio'] ?? '' ) );
	if ( ! $name || ! is_email( $email ) ) {
		wp_die( 'Presenter name and a valid email are required.' );
	}
	$now = $this->now();
	$wpdb->insert( $this->t( 'mrm_masterclass_presenters' ), array( 'name'=>$name,'email'=>$email,'bio'=>$bio,'created_at'=>$now,'updated_at'=>$now ) );
	wp_safe_redirect( admin_url( 'admin.php?page=mrm-masterclass-presenters&created=1' ) );
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

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$this->t( 'mrm_masterclass_payment_ledger' )}
			 SET status = 'paid_out', updated_at = %s
			 WHERE id IN ({$placeholders})
			   AND ledger_type = 'registration_payment'
			   AND status = 'recorded'",
			array_merge( array( $this->now() ), $ids )
		)
	);

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
	public function handle_save_event() {
	$this->must_admin();
	check_admin_referer( 'mrm_masterclass_save_event' );
	global $wpdb;
	$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
	$description = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
	$presenter_id = absint( $_POST['presenter_id'] ?? 0 );
	$start_time = sanitize_text_field( wp_unslash( $_POST['start_time'] ?? '' ) );
	$end_time = sanitize_text_field( wp_unslash( $_POST['end_time'] ?? '' ) );
	$timezone = sanitize_text_field( wp_unslash( $_POST['timezone'] ?? 'America/Phoenix' ) );
	$price_cents = max( 0, absint( $_POST['price_cents'] ?? self::DEFAULT_PRICE_CENTS ) );
	$capacity = max( 1, absint( $_POST['capacity'] ?? 100 ) );
	$status = sanitize_text_field( wp_unslash( $_POST['status'] ?? 'scheduled' ) );
	$open = isset( $_POST['registration_open'] ) ? 1 : 0;
	if ( ! $title || ! $presenter_id || ! $start_time || ! $end_time ) { wp_die( 'Title, presenter, start time, and end time are required.' ); }
	$presenter = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t( 'mrm_masterclass_presenters' )} WHERE id = %d", $presenter_id ) );
	if ( ! $presenter || ! is_email( $presenter->email ) ) { wp_die( 'A valid presenter is required.' ); }
	if ( ! in_array( $status, array( 'draft', 'scheduled', 'cancelled', 'completed', 'archived' ), true ) ) { $status = 'scheduled'; }
	$now = $this->now();
	$wpdb->insert($this->t( 'mrm_masterclass_events' ),array('title'=>$title,'description'=>$description,'presenter_id'=>$presenter_id,'presenter_email'=>$presenter->email,'start_time'=>str_replace( 'T', ' ', $start_time ) . ':00','end_time'=>str_replace( 'T', ' ', $end_time ) . ':00','timezone'=>$timezone,'price_cents'=>$price_cents,'capacity'=>$capacity,'status'=>$status,'registration_open'=>$open,'created_at'=>$now,'updated_at'=>$now));
	wp_safe_redirect( admin_url( 'admin.php?page=mrm-masterclass-events&created=1' ) ); exit;
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
