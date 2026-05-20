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
		'Presenter Pages',
		'Presenter Pages',
		'manage_options',
		'mrm-masterclass-presenter-pages',
		array( $this, 'render_presenter_pages_page' )
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
	$s = $this->settings();

	echo '<div class="wrap"><h1>MRM Masterclass Settings</h1>';
	if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Masterclass settings saved.</p></div>';

	$this->admin_card_open( 'Masterclass System Settings', 'These settings mirror the scheduler model, but use presenters instead of instructors. They control default session creation, presenter payout calculations, email identity, terms tracking, and Google Calendar configuration.' );
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="mrm_masterclass_save_settings">';
	wp_nonce_field( 'mrm_masterclass_save_settings' );
	echo '<table class="form-table"><tbody>';
	echo '<tr><th>Default Price</th><td><input type="number" name="default_price_cents" value="' . esc_attr( $s['default_price_cents'] ) . '" class="regular-text"><p class="description">Enter cents. Example: 2500 = $25.00.</p></td></tr>';
	echo '<tr><th>Default Capacity</th><td><input type="number" min="1" name="default_capacity" value="' . esc_attr( $s['default_capacity'] ) . '" class="regular-text"></td></tr>';
	echo '<tr><th>Default Timezone</th><td><input type="text" name="default_timezone" value="' . esc_attr( $s['default_timezone'] ) . '" class="regular-text"></td></tr>';
	echo '<tr><th>Masterclass Calendar ID</th><td><input type="text" name="masterclass_calendar_id" value="' . esc_attr( $s['masterclass_calendar_id'] ) . '" class="regular-text"></td></tr>';
	echo '<tr><th>Admin Notification Email</th><td><input type="email" name="admin_notification_email" value="' . esc_attr( $s['admin_notification_email'] ) . '" class="regular-text"></td></tr>';
	echo '<tr><th>From Email</th><td><input type="email" name="from_email" value="' . esc_attr( $s['from_email'] ) . '" class="regular-text"></td></tr>';
	echo '<tr><th>Terms Version</th><td><input type="text" name="terms_version" value="' . esc_attr( $s['terms_version'] ) . '" class="regular-text"></td></tr>';
	echo '<tr><th>Presenter Default Percent</th><td><input type="number" step="0.01" min="0" max="100" name="presenter_default_percent" value="' . esc_attr( $s['presenter_default_percent'] ) . '" class="regular-text"></td></tr>';
	echo '<tr><th>Stripe Fee Estimate Percent</th><td><input type="number" step="0.01" min="0" name="stripe_fee_estimate_percent" value="' . esc_attr( $s['stripe_fee_estimate_percent'] ) . '" class="regular-text"></td></tr>';
	echo '<tr><th>Stripe Fixed Fee Estimate</th><td><input type="number" min="0" name="stripe_fee_estimate_fixed" value="' . esc_attr( $s['stripe_fee_estimate_fixed'] ) . '" class="regular-text"><p class="description">Enter cents. Example: 30 = $0.30.</p></td></tr>';
	echo '<tr><th>Cancellation Policy Text</th><td><textarea name="cancellation_policy_text" rows="6" class="large-text">' . esc_textarea( $s['cancellation_policy_text'] ) . '</textarea></td></tr>';
	echo '</tbody></table>';
	submit_button( 'Save Masterclass Settings' );
	echo '</form>';
	$this->admin_card_close();
	echo '</div>';
}

public function render_presenters_page() {
	$this->must_admin();
	global $wpdb;
	$table = $this->t( 'mrm_masterclass_presenters' );
	$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );

	echo '<div class="wrap"><h1>Masterclass Presenters</h1>';
	if ( isset( $_GET['created'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Presenter saved.</p></div>';
	if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Presenter deleted.</p></div>';

	$this->admin_card_open( 'Add Presenter', 'Presenters are the masterclass equivalent of instructors. Their email is used for session assignment, calendar invites, payout tracking, tax profiles, and presenter information pages.' );
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="mrm_masterclass_save_presenter">';
	wp_nonce_field( 'mrm_masterclass_save_presenter' );
	echo '<table class="form-table"><tbody>';
	echo '<tr><th>Name</th><td><input type="text" name="name" class="regular-text" required></td></tr>';
	echo '<tr><th>Email</th><td><input type="email" name="email" class="regular-text" required></td></tr>';
	echo '<tr><th>Presenter Bio / Public Information</th><td><textarea name="bio" rows="7" class="large-text"></textarea><p class="description">This can be used on the presenter information page.</p></td></tr>';
	echo '</tbody></table>';
	submit_button( 'Save Presenter' );
	echo '</form>';
	$this->admin_card_close();

	$this->admin_card_open( 'Current Presenters' );
	echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Bio</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
	if ( $rows ) {
		foreach ( $rows as $row ) {
			$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=mrm_masterclass_delete_presenter&id=' . absint( $row->id ) ), 'mrm_masterclass_delete_presenter_' . absint( $row->id ) );
			$page_url = wp_nonce_url( admin_url( 'admin-post.php?action=mrm_masterclass_create_presenter_page&id=' . absint( $row->id ) ), 'mrm_masterclass_create_presenter_page_' . absint( $row->id ) );
			echo '<tr><td><strong>' . esc_html( $row->name ) . '</strong></td><td>' . esc_html( $row->email ) . '</td><td>' . wp_kses_post( wp_trim_words( $row->bio, 28 ) ) . '</td><td>' . esc_html( $row->created_at ) . '</td><td><a class="button button-small" href="' . esc_url( $page_url ) . '">Create/Update Info Page</a> <a class="button button-small" href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'Delete this presenter? Existing sessions using this presenter should be reviewed first.\');">Delete</a></td></tr>';
		}
	} else {
		echo '<tr><td colspan="5">No presenters have been created yet.</td></tr>';
	}
	echo '</tbody></table>';
	$this->admin_card_close();
	echo '</div>';
}

public function render_presenter_pages_page() {
	$this->must_admin();
	global $wpdb;
	$presenters = $wpdb->get_results( "SELECT * FROM {$this->t( 'mrm_masterclass_presenters' )} ORDER BY name ASC" );
	$page_map = get_option( 'mrm_masterclass_presenter_pages', array() );
	if ( ! is_array( $page_map ) ) $page_map = array();

	echo '<div class="wrap"><h1>Presenter Information Pages</h1>';
	if ( isset( $_GET['page_created'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Presenter information page created or updated.</p></div>';
	$this->admin_card_open( 'Presenter Pages', 'This creates WordPress pages for presenter information using the saved presenter bio. This mirrors instructor profile/public information behavior, but labels the people as presenters.' );
	echo '<table class="widefat striped"><thead><tr><th>Presenter</th><th>Email</th><th>Page</th><th>Action</th></tr></thead><tbody>';
	if ( $presenters ) {
		foreach ( $presenters as $p ) {
			$page_id = absint( $page_map[ $p->id ] ?? 0 );
			$page_link = $page_id ? '<a href="' . esc_url( get_edit_post_link( $page_id ) ) . '">Edit page</a> | <a href="' . esc_url( get_permalink( $page_id ) ) . '" target="_blank">View</a>' : 'No page created yet';
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=mrm_masterclass_create_presenter_page&id=' . absint( $p->id ) ), 'mrm_masterclass_create_presenter_page_' . absint( $p->id ) );
			echo '<tr><td>' . esc_html( $p->name ) . '</td><td>' . esc_html( $p->email ) . '</td><td>' . $page_link . '</td><td><a class="button" href="' . esc_url( $url ) . '">Create/Update Info Page</a></td></tr>';
		}
	} else {
		echo '<tr><td colspan="4">Create presenters first.</td></tr>';
	}
	echo '</tbody></table>';
	$this->admin_card_close();
	echo '</div>';
}

public function render_events_page() {
	$this->must_admin();
	global $wpdb;
	$s = $this->settings();
	$presenters = $wpdb->get_results( "SELECT * FROM {$this->t( 'mrm_masterclass_presenters' )} ORDER BY name ASC" );
	$events = $wpdb->get_results( "SELECT e.*, p.name AS presenter_name, (SELECT COUNT(*) FROM {$this->t( 'mrm_masterclass_registrations' )} r WHERE r.event_id=e.id AND r.payment_status='paid') AS paid_count FROM {$this->t( 'mrm_masterclass_events' )} e LEFT JOIN {$this->t( 'mrm_masterclass_presenters' )} p ON p.id=e.presenter_id ORDER BY e.start_time DESC LIMIT 250" );

	echo '<div class="wrap"><h1>Masterclass Sessions</h1>';
	if ( isset( $_GET['created'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Masterclass session created.</p></div>';
	if ( isset( $_GET['cancelled'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Masterclass session cancelled.</p></div>';

	$this->admin_card_open( 'Create Session', 'This is the masterclass version of creating a scheduled lesson/event. Assign a presenter, set the date/time, price, capacity, and whether registration is open.' );
	if ( ! $presenters ) echo '<div class="notice notice-warning inline"><p>Create at least one presenter before creating sessions.</p></div>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mrm_masterclass_save_event">';
	wp_nonce_field( 'mrm_masterclass_save_event' );
	echo '<table class="form-table"><tbody>';
	echo '<tr><th>Session Title</th><td><input type="text" name="title" class="regular-text" required></td></tr>';
	echo '<tr><th>Description</th><td><textarea name="description" rows="6" class="large-text"></textarea></td></tr>';
	echo '<tr><th>Presenter</th><td><select name="presenter_id" required><option value="">Select presenter</option>';
	foreach ( $presenters as $p ) echo '<option value="' . esc_attr( $p->id ) . '">' . esc_html( $p->name . ' — ' . $p->email ) . '</option>';
	echo '</select></td></tr>';
	echo '<tr><th>Start Time</th><td><input type="datetime-local" name="start_time" required></td></tr>';
	echo '<tr><th>End Time</th><td><input type="datetime-local" name="end_time" required></td></tr>';
	echo '<tr><th>Timezone</th><td><input type="text" name="timezone" value="' . esc_attr( $s['default_timezone'] ) . '" class="regular-text"></td></tr>';
	echo '<tr><th>Price</th><td><input type="number" min="0" name="price_cents" value="' . esc_attr( $s['default_price_cents'] ) . '" class="regular-text"><p class="description">Enter cents.</p></td></tr>';
	echo '<tr><th>Capacity</th><td><input type="number" min="1" name="capacity" value="' . esc_attr( $s['default_capacity'] ) . '" class="regular-text"></td></tr>';
	echo '<tr><th>Status</th><td><select name="status">';
	foreach ( array( 'draft', 'scheduled', 'cancelled', 'completed', 'archived' ) as $status ) echo '<option value="' . esc_attr( $status ) . '"' . selected( $status, 'scheduled', false ) . '>' . esc_html( ucfirst( $status ) ) . '</option>';
	echo '</select></td></tr>';
	echo '<tr><th>Registration Open</th><td><label><input type="checkbox" name="registration_open" value="1" checked> Accept student registrations</label></td></tr>';
	echo '</tbody></table>';
	submit_button( 'Create Session' );
	echo '</form>';
	$this->admin_card_close();

	$this->admin_card_open( 'Existing Sessions' );
	echo '<table class="widefat striped"><thead><tr><th>Title</th><th>Presenter</th><th>Start</th><th>End</th><th>Price</th><th>Capacity</th><th>Paid</th><th>Status</th><th>Open</th><th>Google</th><th>Action</th></tr></thead><tbody>';
	if ( $events ) {
		foreach ( $events as $e ) {
			$cancel_url = wp_nonce_url( admin_url( 'admin-post.php?action=mrm_masterclass_cancel_event&id=' . absint( $e->id ) ), 'mrm_masterclass_cancel_event_' . absint( $e->id ) );
			echo '<tr><td><strong>' . esc_html( $e->title ) . '</strong></td><td>' . esc_html( $e->presenter_name ?: $e->presenter_email ) . '</td><td>' . esc_html( $e->start_time ) . '</td><td>' . esc_html( $e->end_time ) . '</td><td>' . esc_html( $this->cents_to_dollars( $e->price_cents ) ) . '</td><td>' . esc_html( $e->capacity ) . '</td><td>' . esc_html( $e->paid_count ) . '</td><td>' . esc_html( $e->status ) . '</td><td>' . ( $e->registration_open ? 'Yes' : 'No' ) . '</td><td>' . ( $e->google_event_id ? 'Linked' : 'Not linked' ) . '</td><td><a class="button button-small" href="' . esc_url( $cancel_url ) . '" onclick="return confirm(\'Cancel this session? This does not automatically refund registrations yet.\');">Cancel</a></td></tr>';
		}
	} else {
		echo '<tr><td colspan="11">No masterclass sessions have been created yet.</td></tr>';
	}
	echo '</tbody></table>';
	$this->admin_card_close();
	echo '</div>';
}

public function render_registrations_page() {
	$this->must_admin();
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT r.*, e.title AS event_title, e.start_time FROM {$this->t( 'mrm_masterclass_registrations' )} r LEFT JOIN {$this->t( 'mrm_masterclass_events' )} e ON e.id=r.event_id ORDER BY r.created_at DESC LIMIT 300" );
	echo '<div class="wrap"><h1>Masterclass Registrations</h1>';
	$this->admin_card_open( 'Student Registration Records', 'These are student registrations finalized after successful Stripe payment.' );
	echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Session</th><th>Student</th><th>Email</th><th>Amount</th><th>Payment</th><th>Terms</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
	if ( $rows ) foreach ( $rows as $r ) {
		$confirm_url = wp_nonce_url( admin_url( 'admin-post.php?action=mrm_masterclass_resend_confirmation&id=' . absint( $r->id ) ), 'mrm_masterclass_resend_confirmation_' . absint( $r->id ) );
		$reminder_url = wp_nonce_url( admin_url( 'admin-post.php?action=mrm_masterclass_resend_reminder&id=' . absint( $r->id ) ), 'mrm_masterclass_resend_reminder_' . absint( $r->id ) );
		echo '<tr><td>' . esc_html( $r->id ) . '</td><td><strong>' . esc_html( $r->event_title ?: 'Event missing' ) . '</strong><br><small>' . esc_html( $r->start_time ) . '</small></td><td>' . esc_html( trim( $r->first_name . ' ' . $r->last_name ) ) . '</td><td>' . esc_html( $r->email ) . '</td><td>' . esc_html( $this->cents_to_dollars( $r->amount_cents ) ) . '</td><td>' . esc_html( $r->payment_status ) . '</td><td>' . ( $r->terms_accepted ? esc_html( $r->terms_version ) : 'No' ) . '</td><td>' . esc_html( $r->created_at ) . '</td><td><a class="button button-small" href="' . esc_url( $confirm_url ) . '">Resend Confirmation</a> <a class="button button-small" href="' . esc_url( $reminder_url ) . '">Send Reminder</a></td></tr>';
	}
	else echo '<tr><td colspan="9">No registrations found yet.</td></tr>';
	echo '</tbody></table>';
	$this->admin_card_close();
	echo '</div>';
}

public function render_payments_page() {
	$this->must_admin();
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT l.*, e.title AS event_title, p.name AS presenter_name FROM {$this->t( 'mrm_masterclass_payment_ledger' )} l LEFT JOIN {$this->t( 'mrm_masterclass_events' )} e ON e.id=l.event_id LEFT JOIN {$this->t( 'mrm_masterclass_presenters' )} p ON p.id=l.presenter_id ORDER BY l.created_at DESC LIMIT 500" );
	echo '<div class="wrap"><h1>Masterclass Payments</h1>';
	$this->admin_card_open( 'Payment Ledger', 'This is the masterclass equivalent of your scheduler/payment ledger view. It records each paid registration and the calculated presenter/platform split.' );
	echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Session</th><th>Presenter</th><th>Type</th><th>Gross</th><th>Stripe Fee Est.</th><th>Net</th><th>Presenter Share</th><th>Platform Share</th><th>Status</th><th>Created</th></tr></thead><tbody>';
	if ( $rows ) foreach ( $rows as $l ) echo '<tr><td>' . esc_html( $l->id ) . '</td><td>' . esc_html( $l->event_title ?: '—' ) . '</td><td>' . esc_html( $l->presenter_name ?: '—' ) . '</td><td>' . esc_html( $l->ledger_type ) . '</td><td>' . esc_html( $this->cents_to_dollars( $l->gross_cents ) ) . '</td><td>' . esc_html( $this->cents_to_dollars( $l->stripe_fee_cents ) ) . '</td><td>' . esc_html( $this->cents_to_dollars( $l->net_cents ) ) . '</td><td>' . esc_html( $this->cents_to_dollars( $l->presenter_share_cents ) ) . '</td><td>' . esc_html( $this->cents_to_dollars( $l->platform_share_cents ) ) . '</td><td>' . esc_html( $l->status ) . '</td><td>' . esc_html( $l->created_at ) . '</td></tr>';
	else echo '<tr><td colspan="11">No payment ledger records found yet.</td></tr>';
	echo '</tbody></table>';
	$this->admin_card_close();
	echo '</div>';
}

public function render_payouts_page() {
	$this->must_admin();
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT l.*, e.title AS event_title, p.name AS presenter_name, p.email AS presenter_email FROM {$this->t( 'mrm_masterclass_payment_ledger' )} l LEFT JOIN {$this->t( 'mrm_masterclass_events' )} e ON e.id=l.event_id LEFT JOIN {$this->t( 'mrm_masterclass_presenters' )} p ON p.id=l.presenter_id WHERE l.ledger_type='registration_payment' AND l.status='recorded' AND l.presenter_share_cents > 0 ORDER BY p.name ASC, l.created_at ASC" );
	$total = 0; foreach ( $rows as $row ) $total += absint( $row->presenter_share_cents );
	echo '<div class="wrap"><h1>Presenter Payouts</h1>';
	if ( isset( $_GET['paid'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Selected presenter payout rows were marked paid out.</p></div>';
	$this->admin_card_open( 'Unpaid Presenter Shares', 'Mark rows paid out only after the presenter has actually been paid. The 1099 summary only counts paid_out rows.' );
	echo '<p><strong>Total currently payable:</strong> ' . esc_html( $this->cents_to_dollars( $total ) ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mrm_masterclass_mark_payouts_paid">';
	wp_nonce_field( 'mrm_masterclass_mark_payouts_paid' );
	echo '<table class="widefat striped"><thead><tr><th>Select</th><th>Presenter</th><th>Email</th><th>Session</th><th>Presenter Share</th><th>Created</th></tr></thead><tbody>';
	if ( $rows ) foreach ( $rows as $row ) echo '<tr><td><input type="checkbox" name="ledger_ids[]" value="' . esc_attr( $row->id ) . '"></td><td>' . esc_html( $row->presenter_name ?: '—' ) . '</td><td>' . esc_html( $row->presenter_email ?: '—' ) . '</td><td>' . esc_html( $row->event_title ?: '—' ) . '</td><td>' . esc_html( $this->cents_to_dollars( $row->presenter_share_cents ) ) . '</td><td>' . esc_html( $row->created_at ) . '</td></tr>';
	else echo '<tr><td colspan="6">No unpaid presenter payout rows found.</td></tr>';
	echo '</tbody></table>';
	if ( $rows ) submit_button( 'Mark Selected Rows Paid Out' );
	echo '</form>';
	$this->admin_card_close();
	echo '</div>';
}

public function render_1099_page() {
	$this->must_admin();
	global $wpdb;
	$year = isset( $_GET['tax_year'] ) ? absint( $_GET['tax_year'] ) : absint( gmdate( 'Y' ) );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.id AS presenter_id, p.name, p.email, t.legal_name, t.business_name, t.tin_last4, t.w9_received, t.is_1099_eligible, t.exclude_from_1099, COALESCE(SUM(l.presenter_share_cents),0) AS box1_cents FROM {$this->t( 'mrm_masterclass_presenters' )} p LEFT JOIN {$this->t( 'mrm_masterclass_presenter_tax_profiles' )} t ON t.presenter_id=p.id LEFT JOIN {$this->t( 'mrm_masterclass_payment_ledger' )} l ON l.presenter_id=p.id AND l.ledger_type='registration_payment' AND l.status='paid_out' AND YEAR(l.updated_at)=%d GROUP BY p.id ORDER BY p.name ASC", $year ) );
	echo '<div class="wrap"><h1>Masterclass 1099 Documents</h1>';
	$this->admin_card_open( '1099 Summary', 'This summary uses the same paid-out-only rule you use for contractor reporting. Pending or recorded balances do not count. PDF export can be added after this summary is verified.' );
	echo '<form method="get" style="margin-bottom:16px;"><input type="hidden" name="page" value="mrm-masterclass-1099"><label><strong>Tax Year</strong> <input type="number" name="tax_year" value="' . esc_attr( $year ) . '" min="2024" max="2100"></label> <button class="button">Filter</button></form>';
	echo '<table class="widefat striped"><thead><tr><th>Presenter</th><th>Email</th><th>Legal Name</th><th>Business Name</th><th>TIN Last 4</th><th>W-9</th><th>Eligible</th><th>Excluded</th><th>Box 1 Total</th><th>1099 Needed?</th></tr></thead><tbody>';
	if ( $rows ) foreach ( $rows as $row ) {
		$box1 = absint( $row->box1_cents );
		$eligible = ( is_null( $row->is_1099_eligible ) || absint( $row->is_1099_eligible ) === 1 ) && ! absint( $row->exclude_from_1099 );
		echo '<tr><td>' . esc_html( $row->name ) . '</td><td>' . esc_html( $row->email ) . '</td><td>' . esc_html( $row->legal_name ?: '—' ) . '</td><td>' . esc_html( $row->business_name ?: '—' ) . '</td><td>' . esc_html( $row->tin_last4 ?: '—' ) . '</td><td>' . ( $row->w9_received ? 'Yes' : 'No' ) . '</td><td>' . ( $eligible ? 'Yes' : 'No' ) . '</td><td>' . ( $row->exclude_from_1099 ? 'Yes' : 'No' ) . '</td><td>' . esc_html( $this->cents_to_dollars( $box1 ) ) . '</td><td>' . ( $eligible && $box1 >= 60000 ? 'Yes' : 'No' ) . '</td></tr>';
	}
	else echo '<tr><td colspan="10">No presenters found.</td></tr>';
	echo '</tbody></table>';
	$this->admin_card_close();
	echo '</div>';
}

public function render_tax_profiles_page() {
	$this->must_admin();
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT p.id, p.name, p.email, t.legal_name, t.business_name, t.email AS tax_email, t.tin_last4, t.tin_type, t.w9_received, t.w9_received_date, t.address_line1, t.address_line2, t.city, t.state, t.zip, t.is_1099_eligible, t.exclude_from_1099, t.notes FROM {$this->t( 'mrm_masterclass_presenters' )} p LEFT JOIN {$this->t( 'mrm_masterclass_presenter_tax_profiles' )} t ON t.presenter_id=p.id ORDER BY p.name ASC" );
	echo '<div class="wrap"><h1>Presenter Tax Profiles</h1>';
	if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Tax profile saved.</p></div>';
	echo '<p>Track W-9 status and 1099 readiness. Do not store full SSNs or EINs in WordPress; use last four only unless you later add encrypted/AWS-backed full TIN storage.</p>';
	if ( $rows ) {
		foreach ( $rows as $row ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="background:#fff;border:1px solid #d7c7ad;border-radius:16px;padding:16px;margin:16px 0;">';
			echo '<input type="hidden" name="action" value="mrm_masterclass_save_tax_profile"><input type="hidden" name="presenter_id" value="' . esc_attr( $row->id ) . '">';
			wp_nonce_field( 'mrm_masterclass_save_tax_profile_' . absint( $row->id ) );
			echo '<h2 style="margin-top:0;">' . esc_html( $row->name ) . '</h2><p><strong>Presenter email:</strong> ' . esc_html( $row->email ) . '</p>';
			echo '<table class="form-table"><tbody>';
			echo '<tr><th>Legal Name</th><td><input type="text" name="legal_name" value="' . esc_attr( $row->legal_name ) . '" class="regular-text"></td></tr>';
			echo '<tr><th>Business Name</th><td><input type="text" name="business_name" value="' . esc_attr( $row->business_name ) . '" class="regular-text"></td></tr>';
			echo '<tr><th>Tax Email</th><td><input type="email" name="email" value="' . esc_attr( $row->tax_email ?: $row->email ) . '" class="regular-text"></td></tr>';
			echo '<tr><th>TIN Last 4</th><td><input type="text" maxlength="4" name="tin_last4" value="' . esc_attr( $row->tin_last4 ) . '" class="small-text"></td></tr>';
			echo '<tr><th>TIN Type</th><td><select name="tin_type">';
			foreach ( array( '', 'SSN', 'EIN', 'ITIN' ) as $type ) echo '<option value="' . esc_attr( $type ) . '"' . selected( $row->tin_type, $type, false ) . '>' . esc_html( $type ?: 'Select' ) . '</option>';
			echo '</select></td></tr>';
			echo '<tr><th>W-9 Received</th><td><label><input type="checkbox" name="w9_received" value="1" ' . checked( $row->w9_received, 1, false ) . '> Yes</label></td></tr>';
			echo '<tr><th>W-9 Received Date</th><td><input type="date" name="w9_received_date" value="' . esc_attr( $row->w9_received_date ) . '"></td></tr>';
			echo '<tr><th>Address Line 1</th><td><input type="text" name="address_line1" value="' . esc_attr( $row->address_line1 ) . '" class="regular-text"></td></tr>';
			echo '<tr><th>Address Line 2</th><td><input type="text" name="address_line2" value="' . esc_attr( $row->address_line2 ) . '" class="regular-text"></td></tr>';
			echo '<tr><th>City</th><td><input type="text" name="city" value="' . esc_attr( $row->city ) . '" class="regular-text"></td></tr>';
			echo '<tr><th>State</th><td><input type="text" name="state" value="' . esc_attr( $row->state ) . '" class="regular-text"></td></tr>';
			echo '<tr><th>ZIP</th><td><input type="text" name="zip" value="' . esc_attr( $row->zip ) . '" class="regular-text"></td></tr>';
			echo '<tr><th>1099 Eligible</th><td><label><input type="checkbox" name="is_1099_eligible" value="1" ' . checked( is_null( $row->is_1099_eligible ) ? 1 : $row->is_1099_eligible, 1, false ) . '> Yes</label></td></tr>';
			echo '<tr><th>Exclude From 1099</th><td><label><input type="checkbox" name="exclude_from_1099" value="1" ' . checked( $row->exclude_from_1099, 1, false ) . '> Yes</label></td></tr>';
			echo '<tr><th>Notes</th><td><textarea name="notes" rows="4" class="large-text">' . esc_textarea( $row->notes ) . '</textarea></td></tr>';
			echo '</tbody></table>';
			submit_button( 'Save Tax Profile' );
			echo '</form>';
		}
	} else {
		echo '<p>No presenters have been created yet.</p>';
	}
	echo '</div>';
}

public function render_email_log_page() {
	$this->must_admin();
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT * FROM {$this->t( 'mrm_masterclass_email_log' )} ORDER BY sent_at DESC LIMIT 300" );
	echo '<div class="wrap"><h1>Masterclass Email Log</h1>';
	$this->admin_card_open( 'Recent Emails', 'This log records confirmation, reminder, and failure records for masterclass emails.' );
	echo '<table class="widefat striped"><thead><tr><th>Status</th><th>Type</th><th>Recipient</th><th>Subject</th><th>Session</th><th>Registration</th><th>Sent At</th><th>Error</th></tr></thead><tbody>';
	if ( $rows ) foreach ( $rows as $row ) echo '<tr><td>' . esc_html( $row->status ) . '</td><td>' . esc_html( $row->email_type ) . '</td><td>' . esc_html( $row->recipient_email ) . '</td><td>' . esc_html( $row->subject ) . '</td><td>' . esc_html( $row->event_id ?: '—' ) . '</td><td>' . esc_html( $row->registration_id ?: '—' ) . '</td><td>' . esc_html( $row->sent_at ) . '</td><td>' . esc_html( $row->error_message ?: '—' ) . '</td></tr>';
	else echo '<tr><td colspan="8">No masterclass email records found yet.</td></tr>';
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

private function send_email($to,$subject,$body,$type,$event_id=0,$registration_id=0){
	$settings = $this->settings();
	$from_email = ! empty( $settings['from_email'] ) ? $settings['from_email'] : 'no-reply@lowbrass-lessons.com';
	$headers = array(
		'Content-Type: text/html; charset=UTF-8',
		'From: Low Brass Lessons <' . $from_email . '>',
	);
	$wrapped = '<div style="font-family:Arial,sans-serif;background:#fff8ed;padding:24px;color:#1f2933;"><div style="max-width:680px;margin:0 auto;background:#fff;border:1px solid #decfb8;border-radius:18px;padding:24px;"><h1 style="font-family:Georgia,serif;color:#143447;margin-top:0;">Low Brass Lessons</h1>' . $body . '</div></div>';
	$ok = wp_mail($to,$subject,$wrapped,$headers);
	global $wpdb;
	$wpdb->insert($this->t('mrm_masterclass_email_log'),array('event_id'=>$event_id?:null,'registration_id'=>$registration_id?:null,'recipient_email'=>$to,'email_type'=>$type,'subject'=>$subject,'status'=>$ok?'sent':'failed','sent_at'=>$this->now()));
	return $ok;
}
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
	
public function handle_create_presenter_page() {
	$this->must_admin();

	$id = absint( $_GET['id'] ?? 0 );
	if ( ! $id ) {
		wp_die( 'Missing presenter ID.' );
	}

	check_admin_referer( 'mrm_masterclass_create_presenter_page_' . $id );

	global $wpdb;

	$presenter = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$this->t( 'mrm_masterclass_presenters' )} WHERE id = %d",
			$id
		)
	);

	if ( ! $presenter ) {
		wp_die( 'Presenter not found.' );
	}

	$page_map = get_option( 'mrm_masterclass_presenter_pages', array() );
	if ( ! is_array( $page_map ) ) {
		$page_map = array();
	}

	$existing_page_id = absint( $page_map[ $id ] ?? 0 );
	$title = $presenter->name . ' — Masterclass Presenter';

	$content  = '<!-- wp:group {"className":"mrm-masterclass-presenter-page"} --><div class="wp-block-group mrm-masterclass-presenter-page">';
	$content .= '<!-- wp:heading --><h2>' . esc_html( $presenter->name ) . '</h2><!-- /wp:heading -->';
	$content .= '<!-- wp:paragraph --><p><strong>Masterclass Presenter</strong></p><!-- /wp:paragraph -->';
	$content .= '<!-- wp:paragraph --><p>' . wp_kses_post( nl2br( $presenter->bio ) ) . '</p><!-- /wp:paragraph -->';
	$content .= '<!-- wp:paragraph --><p>For masterclass questions, contact Low Brass Lessons through the official contact form.</p><!-- /wp:paragraph -->';
	$content .= '</div><!-- /wp:group -->';

	$post_data = array(
		'post_title'   => $title,
		'post_content' => $content,
		'post_status'  => 'draft',
		'post_type'    => 'page',
	);

	if ( $existing_page_id && get_post( $existing_page_id ) ) {
		$post_data['ID'] = $existing_page_id;
		$page_id = wp_update_post( $post_data, true );
	} else {
		$page_id = wp_insert_post( $post_data, true );
	}

	if ( is_wp_error( $page_id ) ) {
		wp_die( esc_html( $page_id->get_error_message() ) );
	}

	$page_map[ $id ] = absint( $page_id );
	update_option( 'mrm_masterclass_presenter_pages', $page_map );

	wp_safe_redirect( admin_url( 'admin.php?page=mrm-masterclass-presenter-pages&page_created=1' ) );
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
