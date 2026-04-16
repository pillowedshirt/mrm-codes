<?php
/**
 * Plugin Name: MRM Payments Hub (Single File)
 * Description: Central product catalog + Stripe PaymentIntents + labeling metadata + orders ledger for MRM scheduler and sheet music plugins.
 * Version: 1.0.0
 * Author: Matt Rose
 */

if (!defined('ABSPATH')) exit;

$autoload = ABSPATH . 'vendor/autoload.php';
if (file_exists($autoload)) {
  require_once $autoload;
}

use Aws\SecretsManager\SecretsManagerClient;
use Aws\Exception\AwsException;

class MRM_Payments_Hub_Single {
  const VERSION = '1.0.0';

  // Options keys
  const OPT_SETTINGS = 'mrm_pay_hub_settings';
  const OPT_PRODUCTS = 'mrm_pay_hub_products';
  const OPT_ACCESS_LISTS = 'mrm_pay_hub_access_lists';
  const OPT_EMAIL_LISTS = 'mrm_pay_hub_email_lists';

  // Admin menu
  const MENU_SLUG = 'mrm-payments-hub';
  private const MRM_AUTO_REFUND_MAX_AGE_DAYS = 7;
  private const MRM_PM_LOOKAHEAD_HOURS = 72;
  private const MRM_PM_SAME_MONTH_REQUIRES_UPDATE = true;

  public function __construct() {
    add_action('admin_menu', array($this, 'admin_menu'));
    add_action('admin_init', array($this, 'handle_admin_post'));
    add_action('rest_api_init', array($this, 'register_routes'));
    add_action('init', array($this, 'maybe_install_or_upgrade_db'), 5);
    add_action('init', array($this, 'mrm_run_instructor_piece_access_sync'));

    add_filter('cron_schedules', array($this, 'register_custom_cron_schedules'));
    add_action('init', array($this, 'ensure_runtime_cron_schedules'), 20);

    add_action('mrm_pay_hub_cleanup_access', array($this, 'cleanup_access'));
    add_action('mrm_pay_hub_daily_payout_check', array($this, 'cron_daily_payout_check'));
    add_action('mrm_pay_hub_retry_autopay_charges', array($this, 'cron_retry_autopay_charges'));
    add_action('mrm_pay_hub_discover_missed_autopay_lessons', array($this, 'cron_discover_missed_autopay_lessons'));
    add_action('mrm_pay_hub_reset_stuck_autopay_lessons', array($this, 'cron_reset_stuck_autopay_lessons'));
    add_action('mrm_pay_hub_check_upcoming_payment_methods', array($this, 'cron_check_upcoming_payment_methods'));
    add_action('mrm_pay_hub_retry_sheet_music_subscriptions', array($this, 'cron_retry_sheet_music_subscriptions'));

    add_action('mrm_lesson_charge_due', array($this, 'on_lesson_charge_due'), 10, 1);
    add_action('mrm_lesson_delivered', array($this, 'on_lesson_delivered'), 10, 1);
    add_action('mrm_lesson_cancelled', array($this, 'on_lesson_cancelled'), 10, 1);

    register_activation_hook(__FILE__, array($this, 'on_activate'));
  }

  /* =========================================================
   * Activation / DB
   * ======================================================= */

  public function register_custom_cron_schedules($schedules) {
    if (!isset($schedules['mrm_10min'])) {
      $schedules['mrm_10min'] = array(
        'interval' => 10 * MINUTE_IN_SECONDS,
        'display'  => __('Every 10 Minutes', 'mrm-payments-hub'),
      );
    }
    return $schedules;
  }

  public function ensure_runtime_cron_schedules() {
    if (!wp_next_scheduled('mrm_pay_hub_cleanup_access')) {
      wp_schedule_event(time() + 300, 'hourly', 'mrm_pay_hub_cleanup_access');
    }

    $this->mrm_schedule_daily_payout_check();

    if (!wp_next_scheduled('mrm_pay_hub_retry_autopay_charges')) {
      wp_schedule_event(time() + 240, 'mrm_10min', 'mrm_pay_hub_retry_autopay_charges');
    }

    if (!wp_next_scheduled('mrm_pay_hub_discover_missed_autopay_lessons')) {
      wp_schedule_event(time() + 180, 'mrm_10min', 'mrm_pay_hub_discover_missed_autopay_lessons');
    }

    if (!wp_next_scheduled('mrm_pay_hub_reset_stuck_autopay_lessons')) {
      wp_schedule_event(time() + 420, 'daily', 'mrm_pay_hub_reset_stuck_autopay_lessons');
    }

    if (!wp_next_scheduled('mrm_pay_hub_check_upcoming_payment_methods')) {
      wp_schedule_event(time() + 300, 'hourly', 'mrm_pay_hub_check_upcoming_payment_methods');
    }

    if (!wp_next_scheduled('mrm_pay_hub_retry_sheet_music_subscriptions')) {
      wp_schedule_event(time() + 210, 'mrm_10min', 'mrm_pay_hub_retry_sheet_music_subscriptions');
    }
  }

  public function mrm_run_instructor_piece_access_sync() {
    static $did_run = false;
    if ($did_run) return;
    $did_run = true;

    $this->mrm_sync_instructor_piece_access_master();
  }

  public function on_activate() {
    $this->install_or_upgrade_db();
    $this->ensure_default_products();

    if (!wp_next_scheduled('mrm_pay_hub_cleanup_access')) {
      wp_schedule_event(time() + 300, 'hourly', 'mrm_pay_hub_cleanup_access');
    }

    $this->mrm_schedule_daily_payout_check();

    if (!wp_next_scheduled('mrm_pay_hub_retry_autopay_charges')) {
      wp_schedule_event(time() + 240, 'mrm_10min', 'mrm_pay_hub_retry_autopay_charges');
    }

    if (!wp_next_scheduled('mrm_pay_hub_discover_missed_autopay_lessons')) {
      wp_schedule_event(time() + 180, 'mrm_10min', 'mrm_pay_hub_discover_missed_autopay_lessons');
    }

    if (!wp_next_scheduled('mrm_pay_hub_reset_stuck_autopay_lessons')) {
      wp_schedule_event(time() + 420, 'daily', 'mrm_pay_hub_reset_stuck_autopay_lessons');
    }

    if (!wp_next_scheduled('mrm_pay_hub_check_upcoming_payment_methods')) {
      wp_schedule_event(time() + 300, 'hourly', 'mrm_pay_hub_check_upcoming_payment_methods');
    }

    if (!wp_next_scheduled('mrm_pay_hub_retry_sheet_music_subscriptions')) {
      wp_schedule_event(time() + 210, 'mrm_10min', 'mrm_pay_hub_retry_sheet_music_subscriptions');
    }
  }

  private function table_orders() {
    global $wpdb;
    return $wpdb->prefix . 'mrm_orders';
  }

  private function table_links() {
    global $wpdb;
    return $wpdb->prefix . 'mrm_order_links';
  }

  private function table_lessons() {
    global $wpdb;
    return $wpdb->prefix . 'mrm_lessons';
  }

  private function table_sheet_music_access() {
    global $wpdb;
    return $wpdb->prefix . 'mrm_sheet_music_access';
  }

  private function table_payout_ledger() {
    global $wpdb;
    return $wpdb->prefix . 'mrm_payout_ledger';
  }

  private function table_lesson_credits() {
    global $wpdb;
    return $wpdb->prefix . 'mrm_lesson_credits';
  }

  private function table_autopay_profiles() {
    global $wpdb;
    return $wpdb->prefix . 'mrm_autopay_profiles';
  }

  private function table_webhook_events() {
    global $wpdb;
    return $wpdb->prefix . 'mrm_stripe_webhook_events';
  }

  private function table_sheet_music_subscriptions() {
    global $wpdb;
    return $wpdb->prefix . 'mrm_sheet_music_subscriptions';
  }

  public function maybe_install_or_upgrade_db() {
    // Run a lightweight existence + schema check; only dbDelta if needed.
    global $wpdb;

    $orders  = $this->table_orders();
    $links   = $this->table_links();
    $access  = $this->table_sheet_music_access();
    $payouts = $this->table_payout_ledger();
    $credits = $this->table_lesson_credits();
    $autopay = $this->table_autopay_profiles();
    $webhooks = $this->table_webhook_events();
    $subs = $this->table_sheet_music_subscriptions();

    $needs_upgrade = false;

    // 1) Table existence check
    foreach (array($orders, $links, $access, $payouts, $credits, $autopay, $webhooks, $subs) as $t) {
      $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
      if ($found !== $t) {
        $needs_upgrade = true;
        break;
      }
    }

    // 2) Access-table schema check (important after plugin updates)
    if (!$needs_upgrade) {
      $required_access_columns = array(
        'email_hash',
        'email_plain',
        'sku',
        'start_at',
        'expires_at',
        'month_key',
        'granted_at',
        'revoked_at',
        'source',
        'source_id',
      );

      $existing_cols = array();
      $cols = $wpdb->get_results("SHOW COLUMNS FROM {$access}");
      if (is_array($cols)) {
        foreach ($cols as $c) {
          if (!empty($c->Field)) {
            $existing_cols[] = (string)$c->Field;
          }
        }
      }

      foreach ($required_access_columns as $col) {
        if (!in_array($col, $existing_cols, true)) {
          $needs_upgrade = true;
          error_log('[MRM Payments Hub] Access table missing column: ' . $col . ' — running install_or_upgrade_db().');
          break;
        }
      }
    }

    if (!$needs_upgrade) {
      $required_autopay_columns = array(
        'plan_kind',
        'authorized_lesson_count',
        'charged_lesson_count',
        'detached_at',
      );

      $existing_autopay_cols = array();
      $cols = $wpdb->get_results("SHOW COLUMNS FROM {$autopay}");
      if (is_array($cols)) {
        foreach ($cols as $c) {
          if (!empty($c->Field)) {
            $existing_autopay_cols[] = (string)$c->Field;
          }
        }
      }

      foreach ($required_autopay_columns as $col) {
        if (!in_array($col, $existing_autopay_cols, true)) {
          $needs_upgrade = true;
          error_log('[MRM Payments Hub] Autopay table missing column: ' . $col . ' — running install_or_upgrade_db().');
          break;
        }
      }
    }

    if ($needs_upgrade) {
      error_log('[MRM Payments Hub] DB schema upgrade required; running install_or_upgrade_db().');
      $this->install_or_upgrade_db();
    }

    // ✅ One-time migration: move old master SKU rows to all-sheet-music
    $table = $this->table_sheet_music_access();
    $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($found === $table) {
      $wpdb->query(
        "UPDATE {$table}
         SET sku = 'all-sheet-music'
         WHERE sku = 'piece-all-sheet-music-access-complete-package'"
      );
    }
  }

  private function install_or_upgrade_db() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    $orders  = $this->table_orders();
    $links   = $this->table_links();
    $access  = $this->table_sheet_music_access();
    $payouts = $this->table_payout_ledger();
    $credits = $this->table_lesson_credits();
    $autopay = $this->table_autopay_profiles();
    $webhooks = $this->table_webhook_events();
    $subs = $this->table_sheet_music_subscriptions();

    $sql_orders = "CREATE TABLE {$orders} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      email_hash CHAR(64) NOT NULL,
      sku VARCHAR(120) NOT NULL,
      product_type VARCHAR(30) NOT NULL,
      amount_cents INT NOT NULL,
      currency VARCHAR(10) NOT NULL DEFAULT 'usd',
      environment_mode VARCHAR(10) NOT NULL DEFAULT 'live',
      status VARCHAR(30) NOT NULL DEFAULT 'created',
      stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
      stripe_status VARCHAR(60) DEFAULT NULL,
      metadata_json LONGTEXT DEFAULT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY email_hash_idx (email_hash),
      KEY sku_idx (sku),
      KEY status_idx (status),
      KEY pi_idx (stripe_payment_intent_id)
    ) {$charset};";

    $sql_links = "CREATE TABLE {$links} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      order_id BIGINT UNSIGNED NOT NULL,
      source VARCHAR(50) NOT NULL,
      source_object_id VARCHAR(80) NOT NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY order_id_idx (order_id),
      KEY source_idx (source),
      KEY source_object_id_idx (source_object_id)
    ) {$charset};";

    $sql_access = "CREATE TABLE {$access} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      email_hash CHAR(64) NOT NULL,
      email_plain VARCHAR(190) DEFAULT NULL,
      sku VARCHAR(120) NOT NULL,
      start_at DATETIME DEFAULT NULL,
      expires_at DATETIME DEFAULT NULL,
      month_key VARCHAR(7) DEFAULT NULL,
      granted_at DATETIME NOT NULL,
      revoked_at DATETIME DEFAULT NULL,
      source VARCHAR(60) DEFAULT NULL,
      source_id VARCHAR(255) DEFAULT NULL,
      PRIMARY KEY (id),
      KEY email_hash_idx (email_hash),
      KEY sku_idx (sku),
      KEY active_idx (sku, revoked_at),
      KEY email_sku_idx (email_hash, sku),
      KEY expires_idx (expires_at),
      KEY email_month_idx (email_hash, month_key)
    ) {$charset};";

    $sql_payouts = "CREATE TABLE {$payouts} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      order_id BIGINT UNSIGNED NOT NULL,
      stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
      payee_type VARCHAR(30) NOT NULL,
      payee_ref VARCHAR(120) DEFAULT NULL,
      connected_account_id VARCHAR(255) DEFAULT NULL,
      currency VARCHAR(10) NOT NULL DEFAULT 'usd',
      environment_mode VARCHAR(10) NOT NULL DEFAULT 'live',
      gross_cents INT NOT NULL DEFAULT 0,
      net_cents INT NOT NULL DEFAULT 0,
      status VARCHAR(30) NOT NULL DEFAULT 'pending',
      transfer_id VARCHAR(255) DEFAULT NULL,
      payout_id VARCHAR(255) DEFAULT NULL,
      batch_key VARCHAR(80) DEFAULT NULL,
      notes TEXT DEFAULT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_order_payee (order_id, payee_type, payee_ref),
      KEY status_idx (status),
      KEY acct_idx (connected_account_id),
      KEY pi_idx (stripe_payment_intent_id)
    ) {$charset};";


    $sql_credits = "CREATE TABLE {$credits} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      order_id BIGINT UNSIGNED NOT NULL,
      instructor_id BIGINT UNSIGNED NOT NULL,
      email_hash CHAR(64) NOT NULL,
      currency VARCHAR(10) NOT NULL DEFAULT 'usd',
      unit_base_cents INT NOT NULL DEFAULT 0,
      total_credits INT NOT NULL DEFAULT 0,
      remaining_credits INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_order_instructor (order_id, instructor_id),
      KEY email_hash_idx (email_hash),
      KEY instructor_idx (instructor_id)
    ) {$charset};";

    $sql_autopay = "CREATE TABLE {$autopay} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  instructor_id BIGINT UNSIGNED NOT NULL,
  email_hash CHAR(64) NOT NULL,
  customer_id VARCHAR(255) NOT NULL,
  payment_method_id VARCHAR(255) NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'usd',
  unit_base_cents INT NOT NULL DEFAULT 0,
  plan_kind VARCHAR(20) NOT NULL DEFAULT 'indefinite',
  authorized_lesson_count INT NOT NULL DEFAULT 0,
  charged_lesson_count INT NOT NULL DEFAULT 0,
  pm_brand VARCHAR(40) DEFAULT NULL,
  pm_last4 VARCHAR(10) DEFAULT NULL,
  pm_exp_month INT NOT NULL DEFAULT 0,
  pm_exp_year INT NOT NULL DEFAULT 0,
  pm_last_checked_at DATETIME DEFAULT NULL,
  pm_attention_status VARCHAR(40) DEFAULT NULL,
  pm_attention_reason VARCHAR(255) DEFAULT NULL,
  pm_attention_notified_at DATETIME DEFAULT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  detached_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY email_hash_idx (email_hash),
  KEY instructor_idx (instructor_id),
  KEY active_idx (active)
) {$charset};";

    $sql_webhooks = "CREATE TABLE {$webhooks} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      stripe_event_id VARCHAR(255) NOT NULL,
      event_type VARCHAR(120) NOT NULL,
      object_id VARCHAR(255) DEFAULT NULL,
      processed_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY stripe_event_id_uniq (stripe_event_id),
      KEY event_type_idx (event_type),
      KEY object_id_idx (object_id)
    ) {$charset};";

    $sql_subs = "CREATE TABLE {$subs} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      email_hash CHAR(64) NOT NULL,
      email_plain VARCHAR(190) DEFAULT NULL,
      stripe_customer_id VARCHAR(255) NOT NULL,
      stripe_subscription_id VARCHAR(255) NOT NULL,
      stripe_price_id VARCHAR(255) NOT NULL,
      stripe_status VARCHAR(60) NOT NULL DEFAULT 'pending',
      current_period_start DATETIME DEFAULT NULL,
      current_period_end DATETIME DEFAULT NULL,
      cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
      canceled_at DATETIME DEFAULT NULL,
      latest_invoice_id VARCHAR(255) DEFAULT NULL,
      portal_token VARCHAR(64) NOT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY stripe_subscription_id_uniq (stripe_subscription_id),
      UNIQUE KEY portal_token_uniq (portal_token),
      KEY email_hash_idx (email_hash),
      KEY stripe_customer_idx (stripe_customer_id),
      KEY stripe_status_idx (stripe_status)
    ) {$charset};";

    dbDelta($sql_orders);
    dbDelta($sql_links);
    dbDelta($sql_access);
    dbDelta($sql_payouts);
    dbDelta($sql_credits);
    dbDelta($sql_autopay);
    dbDelta($sql_webhooks);
    dbDelta($sql_subs);
  }


  /* =========================================================
   * Ledger helpers (WP timezone safe)
   * ======================================================= */

  private function mrm_wp_tz() {
    if (function_exists('wp_timezone')) return wp_timezone();
    $tz = get_option('timezone_string');
    if ($tz) return new DateTimeZone($tz);
    $offset = (float) get_option('gmt_offset', 0);
    $hours = (int) $offset;
    $mins = (int) round(abs($offset - $hours) * 60);
    $sign = ($offset < 0) ? '-' : '+';
    return new DateTimeZone(sprintf('%s%02d:%02d', $sign, abs($hours), $mins));
  }

  private function mrm_month_key_from_ts($ts) {
    $ts = (int)$ts;
    if (function_exists('wp_date')) return wp_date('Y-m', $ts, $this->mrm_wp_tz());
    $dt = new DateTime('@' . $ts);
    $dt->setTimezone($this->mrm_wp_tz());
    return $dt->format('Y-m');
  }

  private function mrm_mysql_from_ts($ts) {
    $dt = new DateTime('@' . (int)$ts);
    $dt->setTimezone($this->mrm_wp_tz());
    return $dt->format('Y-m-d H:i:s');
  }

  private function mrm_now_mysql() {
    return current_time('mysql');
  }

  public function mrm_master_all_sheet_music_sku() {
    // ✅ No override. Master SKU is fixed.
    return 'all-sheet-music';
  }

  private function master_sheet_music_sku() {
    return $this->mrm_master_all_sheet_music_sku();
  }

  public function mrm_is_all_sheet_music_active_for_month($email, $month_key) {
    $email = sanitize_email((string)$email);
    if (!$email || !is_email($email)) return false;

    global $wpdb;
    $table = $this->table_sheet_music_access();
    $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($found !== $table) return false;

    $email_hash = $this->email_hash($email);
    $master_sku = $this->mrm_master_all_sheet_music_sku();
    $now = $this->mrm_now_mysql();

    $id = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$table}
       WHERE email_hash=%s AND sku=%s AND month_key=%s AND revoked_at IS NULL
         AND (
           (expires_at IS NOT NULL AND expires_at > %s)
           OR
           (expires_at IS NULL AND DATE_ADD(granted_at, INTERVAL 31 DAY) > %s)
         )
       ORDER BY id DESC LIMIT 1",
      $email_hash, $master_sku, $month_key, $now, $now
    ));
    return !empty($id);
  }

  public function mrm_grant_all_sheet_music_ledger($email, $start_ts, $source = null, $source_id = null) {
    $email = sanitize_email((string)$email);
    if (!$email || !is_email($email)) return false;

    $start_ts = (int)$start_ts;
    if ($start_ts <= 0) $start_ts = time();

    $month_key = $this->mrm_month_key_from_ts($start_ts);
    if ($this->mrm_is_all_sheet_music_active_for_month($email, $month_key)) {
      return true;
    }

    global $wpdb;
    $table = $this->table_sheet_music_access();
    $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($found !== $table) return false;

    $email_hash = $this->email_hash($email);
    $master_sku = $this->mrm_master_all_sheet_music_sku();

    $start_mysql = $this->mrm_mysql_from_ts($start_ts);
    $now = $this->mrm_now_mysql();

    $ok = $wpdb->insert($table, array(
      'email_hash'  => $email_hash,
      'email_plain' => $email,
      'sku'         => $master_sku,
      'start_at'    => $start_mysql,
      'expires_at'  => null,
      'month_key'   => $month_key,
      'granted_at'  => $now,
      'revoked_at'  => null,
      'source'      => $source,
      'source_id'   => $source_id,
    ), array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'));

    return (bool)$ok;
  }

  public function mrm_prune_expired_all_sheet_music() {
    global $wpdb;
    $table = $this->table_sheet_music_access();
    $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($found !== $table) return;

    $master_sku = $this->mrm_master_all_sheet_music_sku();
    $now = $this->mrm_now_mysql();

    $wpdb->query($wpdb->prepare(
      "UPDATE {$table}
       SET revoked_at=%s
       WHERE sku=%s AND revoked_at IS NULL
         AND (
           (expires_at IS NOT NULL AND expires_at <= %s)
           OR
           (expires_at IS NULL AND DATE_ADD(granted_at, INTERVAL 31 DAY) <= %s)
         )",
      $now, $master_sku, $now, $now
    ));
  }

  /* =========================================================
   * Settings / Products
   * ======================================================= */

  private function get_settings() {
    $opts = get_option(self::OPT_SETTINGS, array());
    $opts = is_array($opts) ? $opts : array();

    return wp_parse_args($opts, array(
      'one_time_sheet_music_composer_pct' => 0,
      'in_person_travel_amount_cents' => 500,

      'instructor_payout_30_online_year1_cents' => 0,
      'instructor_payout_30_online_year2_cents' => 0,
      'instructor_payout_30_online_year3_cents' => 0,

      'instructor_payout_30_inperson_year1_cents' => 0,
      'instructor_payout_30_inperson_year2_cents' => 0,
      'instructor_payout_30_inperson_year3_cents' => 0,

      'instructor_payout_60_online_year1_cents' => 0,
      'instructor_payout_60_online_year2_cents' => 0,
      'instructor_payout_60_online_year3_cents' => 0,

      'instructor_payout_60_inperson_year1_cents' => 0,
      'instructor_payout_60_inperson_year2_cents' => 0,
      'instructor_payout_60_inperson_year3_cents' => 0,

      'payout_anchor_date' => '',
      'composer_connected_account_id' => '',
      'test_composer_connected_account_id' => '',
    ));
  }

  private function save_settings($opts) {
    update_option(self::OPT_SETTINGS, $opts);
  }

  private function mrm_aws_debug_log($message, $context = array()) {
    $log_file = WP_CONTENT_DIR . '/AWS Debug.log';

    $line = '[' . current_time('mysql') . '] ' . $message;

    if (!empty($context)) {
      $json = wp_json_encode($context);
      if (is_string($json) && $json !== '') {
        $line .= ' | ' . $json;
      }
    }

    $line .= PHP_EOL;

    @file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
  }

  private function mrm_get_secret_json($secret_id, $cache_key) {
    $this->mrm_aws_debug_log('Stripe plugin AWS call started', array(
      'secret_id' => $secret_id,
      'cache_key' => $cache_key,
    ));

    $cached = get_transient($cache_key);
    if (is_array($cached)) {
      $this->mrm_aws_debug_log('Stripe plugin AWS cache hit', array(
        'secret_id' => $secret_id,
        'cache_key' => $cache_key,
        'keys_present' => array_keys($cached),
      ));
      return $cached;
    }

    if (
      !defined('MRM_AWS_REGION') ||
      !defined('MRM_AWS_ACCESS_KEY_ID') ||
      !defined('MRM_AWS_SECRET_ACCESS_KEY')
    ) {
      $this->mrm_aws_debug_log('Stripe plugin AWS constants missing', array(
        'region_defined' => defined('MRM_AWS_REGION'),
        'key_defined' => defined('MRM_AWS_ACCESS_KEY_ID'),
        'secret_defined' => defined('MRM_AWS_SECRET_ACCESS_KEY'),
      ));
      return null;
    }

    try {
      $client = new SecretsManagerClient(array(
        'version' => 'latest',
        'region'  => MRM_AWS_REGION,
        'credentials' => array(
          'key'    => MRM_AWS_ACCESS_KEY_ID,
          'secret' => MRM_AWS_SECRET_ACCESS_KEY,
        ),
      ));

      $this->mrm_aws_debug_log('Stripe plugin calling AWS Secrets Manager', array(
        'secret_id' => $secret_id,
        'region' => MRM_AWS_REGION,
      ));

      $result = $client->getSecretValue(array(
        'SecretId' => $secret_id,
      ));

      if (empty($result['SecretString'])) {
        $this->mrm_aws_debug_log('Stripe plugin AWS returned empty SecretString', array(
          'secret_id' => $secret_id,
        ));
        return null;
      }

      $decoded = json_decode((string)$result['SecretString'], true);

      if (!is_array($decoded)) {
        $this->mrm_aws_debug_log('Stripe plugin AWS SecretString did not decode to array', array(
          'secret_id' => $secret_id,
          'secret_string_length' => strlen((string)$result['SecretString']),
        ));
        return null;
      }

      $this->mrm_aws_debug_log('Stripe plugin AWS secret decoded successfully', array(
        'secret_id' => $secret_id,
        'keys_present' => array_keys($decoded),
        'secret_key_present' => array_key_exists('secret_key', $decoded),
        'webhook_secret_present' => array_key_exists('webhook_secret', $decoded),
      ));

      set_transient($cache_key, $decoded, 15 * MINUTE_IN_SECONDS);

      $this->mrm_aws_debug_log('Stripe plugin AWS secret cached', array(
        'secret_id' => $secret_id,
        'cache_key' => $cache_key,
      ));

      return $decoded;
    } catch (AwsException $e) {
      $this->mrm_aws_debug_log('Stripe plugin AWS exception', array(
        'secret_id' => $secret_id,
        'aws_error_message' => $e->getAwsErrorMessage(),
        'aws_error_code' => $e->getAwsErrorCode(),
      ));
      return null;
    } catch (\Throwable $e) {
      $this->mrm_aws_debug_log('Stripe plugin AWS fatal exception', array(
        'secret_id' => $secret_id,
        'message' => $e->getMessage(),
      ));
      return null;
    }
  }

  private function mrm_get_stripe_secret_bundle() {
    if (!defined('MRM_SECRET_STRIPE_KEYS')) {
      $this->mrm_aws_debug_log('Stripe secret constant MRM_SECRET_STRIPE_KEYS is not defined.');
      return null;
    }

    $secret = $this->mrm_get_secret_json(
      MRM_SECRET_STRIPE_KEYS,
      'mrm_secret_stripe_keys_v2'
    );

    if (!is_array($secret)) {
      $this->mrm_aws_debug_log('Stripe AWS secret bundle could not be loaded.', array(
        'secret_id' => MRM_SECRET_STRIPE_KEYS,
      ));
      return null;
    }

    $context = array(
      'secret_id' => MRM_SECRET_STRIPE_KEYS,
      'keys_present' => array_keys($secret),
      'has_publishable_key' => array_key_exists('publishable_key', $secret),
      'has_secret_key' => array_key_exists('secret_key', $secret),
      'has_webhook_secret' => array_key_exists('webhook_secret', $secret),
    );

    if (array_key_exists('publishable_key', $secret) && is_string($secret['publishable_key'])) {
      $context['publishable_key_length'] = strlen($secret['publishable_key']);
      $context['publishable_key_preview'] = substr($secret['publishable_key'], 0, 20);
    }

    if (array_key_exists('secret_key', $secret) && is_string($secret['secret_key'])) {
      $context['secret_key_length'] = strlen($secret['secret_key']);
      $context['secret_key_preview'] = substr($secret['secret_key'], 0, 20);
    }

    if (array_key_exists('webhook_secret', $secret) && is_string($secret['webhook_secret'])) {
      $context['webhook_secret_length'] = strlen($secret['webhook_secret']);
      $context['webhook_secret_preview'] = substr($secret['webhook_secret'], 0, 20);
    }

    $this->mrm_aws_debug_log('Stripe AWS secret bundle loaded.', $context);

    return $secret;
  }

  private function publishable_key() {
    $secret = $this->mrm_get_stripe_secret_bundle();
    if (is_array($secret) && !empty($secret['publishable_key'])) {
      $this->mrm_aws_debug_log('Stripe publishable_key loaded from AWS secret bundle.', array(
        'length' => strlen((string)$secret['publishable_key']),
        'preview' => substr((string)$secret['publishable_key'], 0, 20),
      ));
      return trim((string)$secret['publishable_key']);
    }

    $this->mrm_aws_debug_log('Stripe publishable_key missing from AWS secret bundle. AWS-only mode active.');
    return '';
  }

  private function secret_key() {
    $secret = $this->mrm_get_stripe_secret_bundle();
    if (is_array($secret) && !empty($secret['secret_key'])) {
      $this->mrm_aws_debug_log('Stripe secret_key loaded from AWS secret bundle.', array(
        'length' => strlen((string)$secret['secret_key']),
        'preview' => substr((string)$secret['secret_key'], 0, 20),
      ));
      return trim((string)$secret['secret_key']);
    }

    $this->mrm_aws_debug_log('Stripe secret_key missing from AWS secret bundle. AWS-only mode active.');
    return '';
  }

  private function webhook_secret() {
    $secret = $this->mrm_get_stripe_secret_bundle();
    if (is_array($secret) && !empty($secret['webhook_secret'])) {
      $this->mrm_aws_debug_log('Stripe webhook_secret loaded from AWS secret bundle.', array(
        'length' => strlen((string)$secret['webhook_secret']),
        'preview' => substr((string)$secret['webhook_secret'], 0, 20),
      ));
      return trim((string)$secret['webhook_secret']);
    }

    $this->mrm_aws_debug_log('Stripe webhook_secret missing from AWS secret bundle. AWS-only mode active.');
    return '';
  }

  private function stripe_debug_log_path() {
    if (!defined('WP_CONTENT_DIR') || !is_string(WP_CONTENT_DIR) || WP_CONTENT_DIR === '') {
      return '';
    }

    return rtrim(WP_CONTENT_DIR, '/\\') . '/stripe-debug.log';
  }

  private function stripe_debug_log($message, $context = array()) {
    try {
      $timestamp = gmdate('d-M-Y H:i:s') . ' UTC';

      if (!is_scalar($message)) {
        $message = print_r($message, true);
      } else {
        $message = (string)$message;
      }

      $line = '[' . $timestamp . '] [MRM Stripe] ' . $message;

      if (!empty($context)) {
        $json = function_exists('wp_json_encode') ? wp_json_encode($context) : json_encode($context);
        if ($json !== false && $json !== null) {
          $line .= ' ' . $json;
        }
      }

      $line .= PHP_EOL;

      $path = $this->stripe_debug_log_path();
      if ($path === '') {
        error_log(trim($line));
        return;
      }

      $dir = dirname($path);
      if (!is_dir($dir)) {
        error_log(trim($line));
        return;
      }

      // If file exists but is not writable, fall back to PHP error log.
      if (file_exists($path) && !is_writable($path)) {
        error_log(trim($line));
        return;
      }

      // If directory is not writable and file does not already exist, fall back.
      if (!file_exists($path) && !is_writable($dir)) {
        error_log(trim($line));
        return;
      }

      $result = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
      if ($result === false) {
        error_log(trim($line));
      }
    } catch (Throwable $e) {
      error_log('[MRM Stripe] logger failure: ' . $e->getMessage());
    }
  }

  private function mrm_subscription_debug_log($message, $context = array()) {
    if (!is_array($context)) {
      $context = array('value' => $context);
    }

    $context['component'] = 'sheet_music_subscription';
    $this->stripe_debug_log($message, $context);
  }

  private function mrm_finalization_debug_log($message, $context = array()) {
    if (!is_array($context)) {
      $context = array('value' => $context);
    }

    $line = '[' . gmdate('d-M-Y H:i:s') . ' UTC] [MRM Lesson Finalization] ' . $message;

    if (!empty($context)) {
      $line .= ' ' . wp_json_encode($context);
    }

    $line .= PHP_EOL;

    $log_file = trailingslashit(WP_CONTENT_DIR) . 'stripe-debug.log';
    @file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
  }

  private function subscription_price_id() {
    $s = $this->get_settings();
    return trim((string)($s['stripe_sheet_music_subscription_price_id'] ?? ''));
  }

  private function composer_connected_account_id() {
    $s = $this->get_settings();

    $live = trim((string)($s['composer_connected_account_id'] ?? ''));
    $test = trim((string)($s['test_composer_connected_account_id'] ?? ''));
    return ($live !== '') ? $live : $test;
  }

  private function all_products() {
    $p = get_option(self::OPT_PRODUCTS, array());
    return is_array($p) ? $p : array();
  }

  private function save_products($products) {
    update_option(self::OPT_PRODUCTS, $this->mrm_strip_legacy_sheet_music_composer_pct_from_products($products));
  }

  private function mrm_strip_legacy_sheet_music_composer_pct_from_products($products) {
    if (!is_array($products)) return array();

    foreach ($products as $sku => $product) {
      if (!is_array($product)) continue;

      $type = (string)($product['product_type'] ?? '');
      if ($type === 'sheet_music' && array_key_exists('composer_pct', $product)) {
        unset($product['composer_pct']);
      }

      $products[$sku] = $product;
    }

    return $products;
  }

  private function mrm_sanitize_percent_setting($value, $default = 0) {
    if ($value === null || $value === '') return (int)$default;
    $pct = (int)$value;
    if ($pct < 0) $pct = 0;
    if ($pct > 100) $pct = 100;
    return $pct;
  }

  private function mrm_money_to_cents($value, $default = 0) {
    if ($value === null || $value === '') return (int)$default;

    $value = is_string($value) ? wp_unslash($value) : $value;
    $value = str_replace(array('$', ',', ' '), '', (string)$value);

    if ($value === '' || !is_numeric($value)) {
      return (int)$default;
    }

    return max(0, (int) round(((float)$value) * 100));
  }

  private function mrm_format_cents_for_admin_input($cents) {
    return number_format(((int)$cents) / 100, 2, '.', '');
  }

  private function mrm_get_one_time_sheet_music_composer_pct() {
    $settings = $this->get_settings();
    return $this->mrm_sanitize_percent_setting($settings['one_time_sheet_music_composer_pct'] ?? 0, 0);
  }

  private function mrm_get_in_person_travel_cents() {
    $settings = $this->get_settings();
    return max(0, (int)($settings['in_person_travel_amount_cents'] ?? 500));
  }

  private function mrm_get_instructor_payout_chart_rows() {
    return array(
      '30_online' => array(
        'label' => '30-Minute Online Lesson',
        'lesson_length' => 30,
        'is_online' => 1,
      ),
      '30_inperson' => array(
        'label' => '30-Minute In-Person Lesson',
        'lesson_length' => 30,
        'is_online' => 0,
      ),
      '60_online' => array(
        'label' => '60-Minute Online Lesson',
        'lesson_length' => 60,
        'is_online' => 1,
      ),
      '60_inperson' => array(
        'label' => '60-Minute In-Person Lesson',
        'lesson_length' => 60,
        'is_online' => 0,
      ),
    );
  }

  private function mrm_get_instructor_payout_chart_columns() {
    return array(
      1 => 'Year 1',
      2 => 'Year 2',
      3 => 'Year 3+',
    );
  }

  private function mrm_get_instructor_payout_chart_setting_key($lesson_length, $is_online, $year_bucket) {
    $lesson_length = (int)$lesson_length;
    $is_online = !empty($is_online);
    $year_bucket = max(1, min(3, (int)$year_bucket));

    if (!in_array($lesson_length, array(30, 60), true)) {
      return '';
    }

    $mode = $is_online ? 'online' : 'inperson';
    return 'instructor_payout_' . $lesson_length . '_' . $mode . '_year' . $year_bucket . '_cents';
  }

  private function mrm_get_instructor_payout_chart_admin_matrix() {
    $settings = $this->get_settings();
    $rows = $this->mrm_get_instructor_payout_chart_rows();
    $matrix = array();

    foreach ($rows as $row_key => $row) {
      $matrix[$row_key] = array();

      foreach (array_keys($this->mrm_get_instructor_payout_chart_columns()) as $year_bucket) {
        $setting_key = $this->mrm_get_instructor_payout_chart_setting_key(
          (int)$row['lesson_length'],
          (int)$row['is_online'],
          (int)$year_bucket
        );

        $matrix[$row_key][$year_bucket] = $this->mrm_format_cents_for_admin_input(
          (int)($settings[$setting_key] ?? 0)
        );
      }
    }

    return $matrix;
  }

  private function mrm_get_instructor_employment_year_bucket($hire_date, $as_of_ts = null) {
    $hire_date = trim((string)$hire_date);
    if ($hire_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire_date)) {
      return 1;
    }

    try {
      $tz = $this->mrm_wp_tz();

      if ($as_of_ts !== null && (int)$as_of_ts > 0) {
        $now = new DateTime('@' . (int)$as_of_ts);
        $now->setTimezone($tz);
      } else {
        $now = new DateTime('now', $tz);
      }

      $start = new DateTime($hire_date . ' 00:00:00', $tz);

      if ($now < $start) {
        return 1;
      }

      $full_years = (int)$start->diff($now)->y;
      $bucket = $full_years + 1;

      if ($bucket < 1) $bucket = 1;
      if ($bucket > 3) $bucket = 3;

      return $bucket;
    } catch (Exception $e) {
      return 1;
    }
  }

  private function mrm_get_instructor_year_bucket_label($year_bucket) {
    $year_bucket = (int)$year_bucket;
    return ($year_bucket >= 3) ? '3+' : (string)max(1, $year_bucket);
  }

  private function mrm_get_instructor_payout_chart_base_cents($lesson_length, $is_online, $hire_date, $as_of_ts = null) {
    $settings = $this->get_settings();
    $year_bucket = $this->mrm_get_instructor_employment_year_bucket($hire_date, $as_of_ts);

    $setting_key = $this->mrm_get_instructor_payout_chart_setting_key($lesson_length, $is_online, $year_bucket);
    if ($setting_key === '') {
      return 0;
    }

    return max(0, (int)($settings[$setting_key] ?? 0));
  }

  private function mrm_get_instructor_payout_cents_for_instructor($lesson_length, $is_online, $hire_date, $as_of_ts = null) {
    $base = $this->mrm_get_instructor_payout_chart_base_cents($lesson_length, $is_online, $hire_date, $as_of_ts);

    if (!empty($is_online)) {
      return $base;
    }

    return $base + $this->mrm_get_in_person_travel_cents();
  }

  private function all_access_lists() {
    $x = get_option(self::OPT_ACCESS_LISTS, array());
    return is_array($x) ? $x : array();
  }

  private function save_access_lists($lists) {
    update_option(self::OPT_ACCESS_LISTS, $lists);
  }

  private function normalize_email_list_textarea($raw) {
    $raw = (string)$raw;
    $parts = preg_split('/[\s,;]+/', $raw);
    $out = array();
    foreach ($parts as $p) {
      $em = sanitize_email(trim($p));
      if ($em && is_email($em)) $out[] = strtolower($em);
    }
    $out = array_values(array_unique($out));
    return $out;
  }

  private function sanitize_product_slug($slug) {
    // Lowercase, hyphen/underscore only (your requirement)
    $slug = strtolower(trim((string)$slug));
    $slug = preg_replace('/[^a-z0-9\-_]+/', '', $slug);
    return $slug;
  }

  private function get_product($sku) {
    $sku = $this->sanitize_sku($sku);
    $all = $this->all_products();
    return isset($all[$sku]) && is_array($all[$sku]) ? $all[$sku] : null;
  }

  private function get_email_lists() {
    $m = get_option(self::OPT_EMAIL_LISTS, array());
    return is_array($m) ? $m : array();
  }

  private function save_email_lists($lists) {
    update_option(self::OPT_EMAIL_LISTS, is_array($lists) ? $lists : array());
  }

  private function mrm_normalize_state_code($state) {
    $state = strtoupper((string)$state);
    $state = preg_replace('/[^A-Z]/', '', $state);
    return substr($state, 0, 2);
  }

  private function mrm_normalize_tax_address($address = array()) {
    return array(
      'country' => strtoupper(trim((string)($address['country'] ?? 'US'))),
      'state' => $this->mrm_normalize_state_code($address['state'] ?? ''),
      'postal_code' => trim((string)($address['postal_code'] ?? '')),
      'line1' => trim((string)($address['line1'] ?? '')),
    );
  }

  private function mrm_get_tax_product_profiles($product_type, $addon_selected = false, $product_cfg = array(), $context = array()) {
    $profiles = array();

    if ($product_type === 'sheet_music') {
      $profiles[] = array(
        'key' => 'sheet_music_base',
        'label' => 'Sheet music',
        'taxable' => true,
        'tax_code' => 'txcd_10302000',
        'amount_source' => 'base',
      );
    }

    if ($addon_selected) {
      $profiles[] = array(
        'key' => 'sheet_music_access',
        'label' => 'Sheet music access subscription',
        'taxable' => true,
        'tax_code' => 'txcd_10302002',
        'amount_source' => 'addon',
      );
    }

    if ($product_type === 'lesson' && empty($profiles)) {
      $profiles[] = array(
        'key' => 'lesson_service',
        'label' => 'Lesson service',
        'taxable' => false,
        'tax_code' => '',
        'amount_source' => 'base',
      );
    }

    return $profiles;
  }

  private function mrm_build_tax_policy($address, $product_type, $addon_selected = false, $product_cfg = array(), $context = array()) {
    $address = $this->mrm_normalize_tax_address($address);
    $country = (string)($address['country'] ?? 'US');
    $state = (string)($address['state'] ?? '');

    $profiles = $this->mrm_get_tax_product_profiles($product_type, $addon_selected, $product_cfg, $context);

    $has_taxable_profile = false;
    foreach ((array)$profiles as $profile) {
      if (!empty($profile['taxable'])) {
        $has_taxable_profile = true;
        break;
      }
    }

    $policy_reason = 'stripe_tax_calculation_ready';
    $policy_message = 'Sales tax is calculated after billing state and ZIP are entered.';
    $should_collect_tax = true;

    if (!$has_taxable_profile) {
      $policy_reason = 'non_taxable_product_profile';
      $policy_message = 'This checkout currently has no taxable product profile.';
      $should_collect_tax = false;
    } elseif ($country !== 'US') {
      $policy_reason = 'unsupported_country';
      $policy_message = 'Tax calculation is currently configured only for U.S. billing addresses.';
      $should_collect_tax = false;
    } elseif ($state === '') {
      $policy_reason = 'missing_state';
      $policy_message = 'Sales tax is calculated after billing state and ZIP are entered.';
      $should_collect_tax = false;
    }

    return array(
      'address' => $address,
      'jurisdiction' => array(
        'country' => $country,
        'state' => $state,
        'exists' => ($country === 'US' && $state !== ''),
        'registered' => null,
        'collect_enabled' => null,
        'stripe_controls_rollout' => true,
      ),
      'profiles' => $profiles,
      'policy_reason' => $policy_reason,
      'policy_message' => $policy_message,
      'should_collect_tax' => $should_collect_tax,
      'allow_subscription_automatic_tax' => ($country === 'US' && $state !== ''),
    );
  }

  private function mrm_build_taxable_items_from_policy($policy, $base_amount_cents, $addon_amount_cents) {
    $items = array();

    foreach ((array)($policy['profiles'] ?? array()) as $profile) {
      if (empty($profile['taxable'])) continue;

      $amount_source = (string)($profile['amount_source'] ?? 'base');
      $amount_cents = ($amount_source === 'addon') ? (int)$addon_amount_cents : (int)$base_amount_cents;

      if ($amount_cents <= 0) continue;

      $items[] = array(
        'amount_cents' => $amount_cents,
        'reference' => (string)($profile['key'] ?? 'item'),
        'tax_code' => (string)($profile['tax_code'] ?? 'txcd_10000000'),
      );
    }

    return $items;
  }

  private function mrm_build_tax_display_message($policy, $tax_result = array(), $tax_cents = 0) {
    if (empty($policy['should_collect_tax'])) {
      return (string)($policy['policy_message'] ?? 'Sales tax is calculated after billing state and ZIP are entered.');
    }

    $reason = (string)($tax_result['taxability_reason'] ?? '');

    if ((int)$tax_cents > 0) {
      return 'Sales tax calculated based on the billing address provided.';
    }

    if ($reason === 'not_collecting') {
      return 'We are not currently collecting tax in this jurisdiction.';
    }

    if (in_array($reason, array('not_subject_to_tax', 'product_exempt', 'zero_rated'), true)) {
      return 'No sales tax applies to this purchase for the billing address provided.';
    }

    if ($reason === 'missing_location_inputs') {
      return 'Sales tax is calculated after billing state and ZIP are entered.';
    }

    return 'No sales tax was applied for the billing address provided.';
  }

  private function mrm_should_enable_subscription_automatic_tax_from_meta($meta = array()) {
    $country = strtoupper(trim((string)($meta['mrm_tax_country'] ?? 'US')));
    $state = $this->mrm_normalize_state_code($meta['mrm_tax_state'] ?? '');

    return ($country === 'US' && $state !== '');
  }

  private function normalize_email_lines($raw) {
    $raw = (string)$raw;
    $lines = preg_split("/\r\n|\n|\r/", $raw);
    $out = array();
    foreach ($lines as $line) {
      $e = sanitize_email(trim($line));
      if ($e && is_email($e)) $out[strtolower($e)] = true;
    }
    return array_keys($out);
  }

  private function get_all_sheet_music_sku() {
    return $this->mrm_master_all_sheet_music_sku();
  }

  /**
   * Ensure that lesson default products are present AND valid.
   * If a default SKU already exists but has an invalid amount/shape, repair it.
   */
  private function ensure_default_products() {
    $all = $this->all_products();
    $changed = false;

    $defaults = array(
      'lesson_60_online' => array(
        'sku' => 'lesson_60_online',
        'label' => '60 Minute Online Lesson',
        'amount_cents' => 6700,
        'currency' => 'usd',
        'product_type' => 'lesson',
        'category' => '60_online',
        'active' => 1,
      ),
      'lesson_60_inperson' => array(
        'sku' => 'lesson_60_inperson',
        'label' => '60 Minute In-Person Lesson',
        'amount_cents' => 7200,
        'currency' => 'usd',
        'product_type' => 'lesson',
        'category' => '60_inperson',
        'active' => 1,
      ),
      'lesson_30_online' => array(
        'sku' => 'lesson_30_online',
        'label' => '30 Minute Online Lesson',
        'amount_cents' => 3800,
        'currency' => 'usd',
        'product_type' => 'lesson',
        'category' => '30_online',
        'active' => 1,
      ),
      'lesson_30_inperson' => array(
        'sku' => 'lesson_30_inperson',
        'label' => '30 Minute In-Person Lesson',
        'amount_cents' => 4300,
        'currency' => 'usd',
        'product_type' => 'lesson',
        'category' => '30_inperson',
        'active' => 1,
      ),
    );

    foreach ($defaults as $sku => $cfg) {
      if (!isset($all[$sku]) || !is_array($all[$sku])) {
        // Missing => add
        $all[$sku] = $cfg;
        $changed = true;
        continue;
      }

      // Exists => validate and repair only if needed
      $current = $all[$sku];

      $cur_amount = isset($current['amount_cents']) ? (int)$current['amount_cents'] : 0;
      $cur_currency = isset($current['currency']) ? (string)$current['currency'] : '';
      $cur_type = isset($current['product_type']) ? (string)$current['product_type'] : '';
      $cur_label = isset($current['label']) ? (string)$current['label'] : '';
      $cur_active = isset($current['active']) ? (int)$current['active'] : 0;

      $needs_repair =
        ($cur_amount <= 0) ||
        (!$cur_currency) ||
        (!$cur_type) ||
        (!$cur_label) ||
        ($cur_active !== 1); // keep lesson defaults active unless you explicitly disable them

      if ($needs_repair) {
        // Preserve any extra fields the admin may have added, but repair core shape.
        $all[$sku] = array_merge($current, $cfg);
        $changed = true;

        error_log('[MRM Payments Hub] Repaired default product: ' . $sku . ' (was amount=' . $cur_amount . ')');
      }
    }

    if ($changed) $this->save_products($all);
  }

  /* =========================================================
   * Helpers: hash/slug/sku
   * ======================================================= */

  private function email_hash($email) {
    $email = strtolower(trim((string)$email));
    $hash = hash('sha256', $email);
    if ($email && is_email($email)) {
      $this->remember_email_map($email, $hash);
    }
    return $hash;
  }

  private function remember_email_map($email, $hash) {
    $map = get_option('mrm_pay_hub_email_map', array());
    if (!is_array($map)) $map = array();
    if (!isset($map[$hash]) || $map[$hash] !== $email) {
      $map[$hash] = $email;
      update_option('mrm_pay_hub_email_map', $map);
    }
  }

  private function sanitize_sku($sku) {
    $sku = strtolower(trim((string)$sku));
    $sku = preg_replace('/[^a-z0-9\-_]/', '', $sku);
    return $sku;
  }

  private function slugify($text) {
    $text = (string)$text;
    $text = remove_accents($text);
    $text = strtolower($text);
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    $text = trim($text, '-');
    return $text ?: 'untitled';
  }

  /**
   * Sheet music SKU generator: piece-<title>-<type>.  The type can be
   * fundamentals, trombone-euphonium, tuba or complete-package.  If the base
   * SKU already exists, a numeric suffix is added to make it unique.
   */
  private function generate_sheet_music_sku($title, $type, $current_sku = '') {
    $type = strtolower(trim((string)$type));
    $allowed = array('fundamentals','trombone-euphonium','tuba','complete-package');
    if (!in_array($type, $allowed, true)) $type = 'fundamentals';

    $base_title = $this->slugify($title);
    $base = 'piece-' . $base_title . '-' . $type;

    $sku = $base;
    $all = $this->all_products();
    $current_sku = $this->sanitize_sku($current_sku);
    if ($current_sku && isset($all[$current_sku])) {
      unset($all[$current_sku]);
    }
    $i = 2;
    while (isset($all[$sku])) {
      $sku = $base . '-' . $i;
      $i++;
      if ($i > 999) break;
    }
    return $sku;
  }

  /* =========================================================
   * Stripe HTTP (no composer dependency)
   * ======================================================= */

  private function stripe_api_request($method, $path, $params = array(), $extra_headers = array()) {
    $key = $this->secret_key();
    if (!$key) return new WP_Error('stripe_not_configured', 'Stripe secret key is not configured.');

    $url = 'https://api.stripe.com' . $path;

    $headers = array(
      'Authorization' => 'Bearer ' . $key,
      'Content-Type'  => 'application/x-www-form-urlencoded',
    );

    if (!empty($extra_headers) && is_array($extra_headers)) {
      $headers = array_merge($headers, $extra_headers);
    }

    $args = array(
      'method'  => strtoupper($method),
      'timeout' => 30,
      'headers' => $headers,
    );

    if ($args['method'] === 'GET') {
      if (!empty($params)) $url = add_query_arg($params, $url);
    } else {
      $args['body'] = http_build_query($params, '', '&');
    }

    $res = wp_remote_request($url, $args);
    if (is_wp_error($res)) return $res;

    $code = (int) wp_remote_retrieve_response_code($res);
    $body = (string) wp_remote_retrieve_body($res);
    $json = json_decode($body, true);

    if ($code < 200 || $code >= 300 || !is_array($json)) {
      $msg = is_array($json) && isset($json['error']['message']) ? $json['error']['message'] : 'Stripe request failed.';
      return new WP_Error('stripe_error', $msg, array('http_code'=>$code,'body'=>$body));
    }

    return $json;
  }


  private function mrm_tax_cents_for_addon($address) {
    $addr = is_array($address) ? $address : array();
    $state = strtoupper(sanitize_text_field((string)($addr['state'] ?? '')));
    $zip = sanitize_text_field((string)($addr['postal_code'] ?? ''));
    $country = strtoupper(sanitize_text_field((string)($addr['country'] ?? 'US')));

    if (!$state || !$zip) return 0;

    $payload = array(
      'currency' => 'usd',
      'customer_details[address][state]' => $state,
      'customer_details[address][postal_code]' => $zip,
      'customer_details[address][country]' => $country,
      'line_items[0][amount]' => 500,
      'line_items[0][quantity]' => 1,
      'line_items[0][reference]' => 'sheet_music_addon'
    );

    $calc = $this->stripe_api_request('POST', '/v1/tax/calculations', $payload);
    if (is_wp_error($calc)) return 0;

    $tax = isset($calc['tax_amount_exclusive']) ? (int)$calc['tax_amount_exclusive'] : 0;
    return max(0, $tax);
  }

  /**
   * Calculate tax for a set of line items using Stripe Tax (custom flow).
   * Each line item: ['amount_cents'=>int, 'reference'=>string, 'tax_code'=>string]
   * Returns array: ['ok'=>bool,'tax_cents'=>int,'amount_total_cents'=>int,'calculation_id'=>string,'line_items'=>array]
   */
  private function mrm_tax_calculate_for_items($address, $line_items, $currency = 'usd') {
    $country = strtoupper((string)($address['country'] ?? ''));
    $postal  = trim((string)($address['postal_code'] ?? ''));
    $state   = trim((string)($address['state'] ?? ''));
    $line1   = trim((string)($address['line1'] ?? ''));

    if (!$country || !$postal) {
      $this->stripe_debug_log('tax calculation skipped: missing minimum address fields', array(
        'country' => $country,
        'postal_code' => $postal,
        'state' => $state,
        'line1_present' => $line1 ? 'yes' : 'no',
      ));

      return array(
        'ok'=>false,
        'tax_cents'=>0,
        'amount_total_cents'=>0,
        'calculation_id'=>'',
        'line_items'=>array(),
        'taxability_reason'=>'missing_location_inputs',
      );
    }

    $items = array();
    $subtotal = 0;

    foreach ((array)$line_items as $li) {
      $amt = isset($li['amount_cents']) ? (int)$li['amount_cents'] : 0;
      if ($amt <= 0) continue;
      $subtotal += $amt;

      $ref = isset($li['reference']) ? (string)$li['reference'] : '';
      if (!$ref) $ref = 'item_' . count($items) . '_' . $amt;

      $tax_code = isset($li['tax_code']) ? (string)$li['tax_code'] : '';
      if (!$tax_code) {
        $tax_code = 'txcd_10000000';
      }

      $items[] = array(
        'amount' => $amt,
        'reference' => $ref,
        'tax_code' => $tax_code,
      );
    }

    if (empty($items)) {
      $this->stripe_debug_log('tax calculation skipped: no taxable items', array(
        'subtotal' => $subtotal,
      ));

      return array(
        'ok'=>true,
        'tax_cents'=>0,
        'amount_total_cents'=>$subtotal,
        'calculation_id'=>'',
        'line_items'=>array(),
        'taxability_reason'=>'no_taxable_items',
      );
    }

    $payload = array(
      'currency' => strtolower((string)$currency),
      'customer_details' => array(
        'address_source' => 'shipping',
        'address' => array(
          'country' => (string)($address['country'] ?? 'US'),
          'postal_code' => (string)($address['postal_code'] ?? ''),
          'state' => (string)($address['state'] ?? ''),
          'line1' => (string)($address['line1'] ?? ''),
        ),
      ),
      'line_items' => $items,
      'expand[]' => 'line_items',
    );

    if ($state) {
      $payload['customer_details']['address']['state'] = $state;
    }
    if ($line1) {
      $payload['customer_details']['address']['line1'] = $line1;
    }

    $this->stripe_debug_log('tax calculation request', array(
      'currency' => strtolower((string)$currency),
      'address_source' => 'shipping',
      'address' => array(
        'country' => (string)($address['country'] ?? 'US'),
        'postal_code' => (string)($address['postal_code'] ?? ''),
        'state' => (string)($address['state'] ?? ''),
        'line1_present' => (!empty($address['line1']) ? 'yes' : 'no'),
      ),
      'line_items' => $items,
    ));

    $calc = $this->stripe_api_request('POST', '/v1/tax/calculations', $payload);

    if (is_wp_error($calc)) {
      $this->stripe_debug_log('tax calculation error', array(
        'message' => $calc->get_error_message(),
      ));

      return array(
        'ok'=>false,
        'tax_cents'=>0,
        'amount_total_cents'=>$subtotal,
        'calculation_id'=>'',
        'line_items'=>array(),
        'taxability_reason'=>'stripe_error',
      );
    }

    $tax_cents = isset($calc['tax_amount_exclusive']) ? (int)$calc['tax_amount_exclusive'] : 0;
    $amount_total = isset($calc['amount_total']) ? (int)$calc['amount_total'] : ($subtotal + $tax_cents);
    $calc_id = isset($calc['id']) ? (string)$calc['id'] : '';

    $out_items = array();
    $taxability_reason = '';

    if (!empty($calc['line_items']['data']) && is_array($calc['line_items']['data'])) {
      foreach ($calc['line_items']['data'] as $x) {
        $reason = (string)($x['taxability_reason'] ?? '');
        if ($reason && $taxability_reason === '') {
          $taxability_reason = $reason;
        }

        $out_items[] = array(
          'reference' => (string)($x['reference'] ?? ''),
          'amount_cents' => (int)($x['amount'] ?? 0),
          'tax_cents' => (int)($x['amount_tax'] ?? 0),
          'taxability_reason' => $reason,
          'tax_code' => (string)($x['tax_code'] ?? ''),
        );
      }
    }

    $this->stripe_debug_log('tax calculation response', array(
      'calculation_id' => $calc_id,
      'tax_cents' => $tax_cents,
      'amount_total_cents' => $amount_total,
      'taxability_reason' => $taxability_reason,
      'line_items' => $out_items,
    ));

    return array(
      'ok' => true,
      'tax_cents' => $tax_cents,
      'amount_total_cents' => $amount_total,
      'calculation_id' => $calc_id,
      'line_items' => $out_items,
      'taxability_reason' => $taxability_reason,
    );
  }

  private function stripe_create_payment_intent($amount_cents, $currency, $metadata, $description = '', $extra_params = array(), $payment_method_types = array('card')) {
    $params = array(
      'amount' => (int)$amount_cents,
      'currency' => $currency ?: 'usd',
    );

    if ($description) {
      $params['description'] = $description;
    }

    $idx = 0;
    foreach ((array)$payment_method_types as $pm_type) {
      $pm_type = sanitize_text_field((string)$pm_type);
      if ($pm_type === '') continue;
      $params["payment_method_types[{$idx}]"] = $pm_type;
      $idx++;
    }

    if ($idx <= 0) {
      $params['payment_method_types[0]'] = 'card';
    }

    // Allow additional Stripe params (customer, setup_future_usage, etc.)
    if (is_array($extra_params)) {
      foreach ($extra_params as $k => $v) {
        if ($v === null || $v === '') continue;
        $params[(string)$k] = (string)$v;
      }
    }

    foreach ((array)$metadata as $k => $v) {
      $k = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$k);
      $params["metadata[{$k}]"] = (string)$v;
    }

    return $this->stripe_api_request('POST', '/v1/payment_intents', $params);
  }

  private function stripe_retrieve_payment_intent($pi_id) {
    return $this->stripe_api_request('GET', '/v1/payment_intents/' . rawurlencode((string)$pi_id));
  }


  private function stripe_create_setup_intent($customer_id, $metadata = array()) {
    $params = array(
      'customer' => (string)$customer_id,
      'usage' => 'off_session',
      'automatic_payment_methods[enabled]' => 'true',
    );
    foreach ((array)$metadata as $k => $v) {
      if ($k === '' || $v === null) continue;
      $params["metadata[{$k}]"] = (string)$v;
    }
    return $this->stripe_api_request('POST', '/v1/setup_intents', $params);
  }

  private function stripe_retrieve_setup_intent($setup_intent_id) {
    return $this->stripe_api_request('GET', '/v1/setup_intents/' . rawurlencode((string)$setup_intent_id));
  }

  private function stripe_create_offsession_payment_intent($amount_cents, $currency, $customer_id, $payment_method_id, $metadata = array(), $description = '') {
    $params = array(
      'amount' => (int)$amount_cents,
      'currency' => strtolower((string)$currency),
      'customer' => (string)$customer_id,
      'payment_method' => (string)$payment_method_id,
      'off_session' => 'true',
      'confirm' => 'true',
      'payment_method_types[0]' => 'card',
    );
    if ($description !== '') $params['description'] = (string)$description;
    foreach ((array)$metadata as $k => $v) {
      if ($k === '' || $v === null) continue;
      $params["metadata[{$k}]"] = (string)$v;
    }
    return $this->stripe_api_request('POST', '/v1/payment_intents', $params);
  }

  private function stripe_attach_payment_method_to_customer($payment_method_id, $customer_id) {
    $payment_method_id = sanitize_text_field((string)$payment_method_id);
    $customer_id = sanitize_text_field((string)$customer_id);
    if (!$payment_method_id || !$customer_id) return new WP_Error('stripe_invalid_args', 'Missing payment method or customer id.');
    return $this->stripe_api_request('POST', '/v1/payment_methods/' . rawurlencode($payment_method_id) . '/attach', array(
      'customer' => $customer_id,
    ));
  }

  private function stripe_set_default_payment_method($customer_id, $payment_method_id) {
    $customer_id = sanitize_text_field((string)$customer_id);
    $payment_method_id = sanitize_text_field((string)$payment_method_id);
    if (!$customer_id || !$payment_method_id) return new WP_Error('stripe_invalid_args', 'Missing customer id or payment method id.');
    return $this->stripe_api_request('POST', '/v1/customers/' . rawurlencode($customer_id), array(
      'invoice_settings[default_payment_method]' => $payment_method_id,
    ));
  }


  private function stripe_retrieve_payment_method($payment_method_id) {
    $payment_method_id = sanitize_text_field((string)$payment_method_id);
    if (!$payment_method_id) return new WP_Error('stripe_invalid_args', 'Missing payment method id.');
    return $this->stripe_api_request('GET', '/v1/payment_methods/' . rawurlencode($payment_method_id));
  }

  private function stripe_create_subscription($customer_id, $price_id, $default_payment_method_id, $billing_cycle_anchor_ts, $metadata = array(), $enable_automatic_tax = true) {
    $params = array(
      'customer' => (string)$customer_id,
      'items[0][price]' => (string)$price_id,
      'default_payment_method' => (string)$default_payment_method_id,
      'collection_method' => 'charge_automatically',
      'proration_behavior' => 'none',
      'billing_cycle_anchor' => (int)$billing_cycle_anchor_ts,
      'description' => 'Low Brass Lessons - Sheet Music Subscription Charge',
    );

    if ($enable_automatic_tax) {
      $params['automatic_tax[enabled]'] = 'true';
    }

    $metadata['mrm_description'] = 'Low Brass Lessons - Sheet Music Subscription Charge';

    foreach ((array)$metadata as $k => $v) {
      if ($k === '' || $v === null) continue;
      $params["metadata[{$k}]"] = (string)$v;
    }

    $this->stripe_debug_log('creating stripe subscription with stripe-controlled automatic tax', array(
      'customer_id' => (string)$customer_id,
      'price_id' => (string)$price_id,
      'billing_cycle_anchor' => (int)$billing_cycle_anchor_ts,
      'automatic_tax_enabled' => ($enable_automatic_tax ? 'true' : 'false'),
    ));

    return $this->stripe_api_request('POST', '/v1/subscriptions', $params);
  }

  private function stripe_retrieve_subscription($subscription_id) {
    return $this->stripe_api_request('GET', '/v1/subscriptions/' . rawurlencode((string)$subscription_id));
  }

  private function stripe_find_customer_by_email($email) {
    $email = sanitize_email((string)$email);
    if (!$email || !is_email($email)) {
      return new WP_Error('invalid_email', 'Invalid email.');
    }

    $results = $this->stripe_api_request('GET', '/v1/customers', array(
      'email' => $email,
      'limit' => 1,
    ));

    if (is_wp_error($results)) {
      return $results;
    }

    if (empty($results['data']) || !is_array($results['data'])) {
      return new WP_Error('customer_not_found', 'Stripe customer not found.');
    }

    return (array)$results['data'][0];
  }

  private function stripe_list_customer_subscriptions($customer_id) {
    $customer_id = trim((string)$customer_id);
    if ($customer_id === '') {
      return new WP_Error('missing_customer_id', 'Missing Stripe customer ID.');
    }

    $subscriptions = $this->stripe_api_request('GET', '/v1/subscriptions', array(
      'customer' => $customer_id,
      'status'   => 'all',
      'limit'    => 20,
    ));

    if (is_wp_error($subscriptions)) {
      return $subscriptions;
    }

    return $subscriptions;
  }

  private function mrm_get_sheet_music_subscription_access_status_by_email($email) {
    $email = sanitize_email((string)$email);

    if (!$email || !is_email($email)) {
      $result = array(
        'has_access' => false,
        'status' => '',
        'subscription_id' => '',
        'reason' => 'invalid_email',
      );
      error_log('[MRM Stripe Access] subscription access lookup ' . wp_json_encode(array(
        'email' => $email,
        'has_access' => !empty($result['has_access']) ? 'yes' : 'no',
        'status' => (string)$result['status'],
        'subscription_id' => (string)$result['subscription_id'],
        'reason' => (string)$result['reason'],
      )));
      return $result;
    }

    $customer = $this->stripe_find_customer_by_email($email);
    if (is_wp_error($customer) || empty($customer['id'])) {
      $result = array(
        'has_access' => false,
        'status' => '',
        'subscription_id' => '',
        'reason' => 'no_customer',
      );
      error_log('[MRM Stripe Access] subscription access lookup ' . wp_json_encode(array(
        'email' => $email,
        'has_access' => !empty($result['has_access']) ? 'yes' : 'no',
        'status' => (string)$result['status'],
        'subscription_id' => (string)$result['subscription_id'],
        'reason' => (string)$result['reason'],
      )));
      return $result;
    }

    $subscriptions = $this->stripe_list_customer_subscriptions($customer['id']);
    if (is_wp_error($subscriptions) || empty($subscriptions['data']) || !is_array($subscriptions['data'])) {
      $result = array(
        'has_access' => false,
        'status' => '',
        'subscription_id' => '',
        'reason' => 'no_subscriptions',
      );
      error_log('[MRM Stripe Access] subscription access lookup ' . wp_json_encode(array(
        'email' => $email,
        'has_access' => !empty($result['has_access']) ? 'yes' : 'no',
        'status' => (string)$result['status'],
        'subscription_id' => (string)$result['subscription_id'],
        'reason' => (string)$result['reason'],
      )));
      return $result;
    }

    $now_ts = current_time('timestamp');

    foreach ($subscriptions['data'] as $subscription) {
      $status = strtolower((string)($subscription['status'] ?? ''));
      $subscription_id = (string)($subscription['id'] ?? '');
      $cancel_at_period_end = !empty($subscription['cancel_at_period_end']);
      $current_period_end = !empty($subscription['current_period_end']) ? (int)$subscription['current_period_end'] : 0;

      // Still renewing
      if (in_array($status, array('active', 'trialing'), true) && !$cancel_at_period_end) {
        $result = array(
          'has_access' => true,
          'status' => $status,
          'subscription_id' => $subscription_id,
          'reason' => 'stripe_active',
        );
        error_log('[MRM Stripe Access] subscription access lookup ' . wp_json_encode(array(
          'email' => $email,
          'has_access' => !empty($result['has_access']) ? 'yes' : 'no',
          'status' => (string)$result['status'],
          'subscription_id' => (string)$result['subscription_id'],
          'reason' => (string)$result['reason'],
        )));
        return $result;
      }

      // Canceled but still paid through current period
      if (
        ($status === 'active' || $status === 'canceled' || $cancel_at_period_end) &&
        $current_period_end > $now_ts
      ) {
        $result = array(
          'has_access' => true,
          'status' => 'canceled',
          'subscription_id' => $subscription_id,
          'reason' => 'paid_through_canceled',
        );
        error_log('[MRM Stripe Access] subscription access lookup ' . wp_json_encode(array(
          'email' => $email,
          'has_access' => !empty($result['has_access']) ? 'yes' : 'no',
          'status' => (string)$result['status'],
          'subscription_id' => (string)$result['subscription_id'],
          'reason' => (string)$result['reason'],
        )));
        return $result;
      }
    }

    $latest = $subscriptions['data'][0];
    $result = array(
      'has_access' => false,
      'status' => strtolower((string)($latest['status'] ?? '')),
      'subscription_id' => (string)($latest['id'] ?? ''),
      'reason' => 'stripe_not_active',
    );
    error_log('[MRM Stripe Access] subscription access lookup ' . wp_json_encode(array(
      'email' => $email,
      'has_access' => !empty($result['has_access']) ? 'yes' : 'no',
      'status' => (string)$result['status'],
      'subscription_id' => (string)$result['subscription_id'],
      'reason' => (string)$result['reason'],
    )));
    return $result;
  }

  private function stripe_create_billing_portal_session($customer_id, $return_url) {
    return $this->stripe_api_request('POST', '/v1/billing_portal/sessions', array(
      'customer' => (string)$customer_id,
      'return_url' => (string)$return_url,
    ));
  }

  private function mrm_extract_card_snapshot_from_payment_method($pm) {
    if (!is_array($pm)) {
      return array(
        'brand' => '',
        'last4' => '',
        'exp_month' => 0,
        'exp_year' => 0,
      );
    }

    $card = isset($pm['card']) && is_array($pm['card']) ? $pm['card'] : array();

    return array(
      'brand' => (string)($card['brand'] ?? ''),
      'last4' => (string)($card['last4'] ?? ''),
      'exp_month' => (int)($card['exp_month'] ?? 0),
      'exp_year' => (int)($card['exp_year'] ?? 0),
    );
  }

  private function mrm_store_autopay_payment_method_snapshot($autopay_profile_id, $payment_method_id, $pm = null, $attention_status = null, $attention_reason = null, $notified_now = false) {
    global $wpdb;

    $autopay_profile_id = (int)$autopay_profile_id;
    if ($autopay_profile_id <= 0) return false;

    if (!is_array($pm)) {
      $pm = $this->stripe_retrieve_payment_method($payment_method_id);
      if (is_wp_error($pm)) {
        return false;
      }
    }

    $snap = $this->mrm_extract_card_snapshot_from_payment_method($pm);

    $data = array(
      'payment_method_id' => (string)$payment_method_id,
      'pm_brand' => $snap['brand'],
      'pm_last4' => $snap['last4'],
      'pm_exp_month' => (int)$snap['exp_month'],
      'pm_exp_year' => (int)$snap['exp_year'],
      'pm_last_checked_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
    );

    $formats = array('%s','%s','%s','%d','%d','%s','%s');

    if ($attention_status !== null) {
      $data['pm_attention_status'] = (string)$attention_status;
      $formats[] = '%s';
    }

    if ($attention_reason !== null) {
      $data['pm_attention_reason'] = (string)$attention_reason;
      $formats[] = '%s';
    }

    if ($notified_now) {
      $data['pm_attention_notified_at'] = current_time('mysql');
      $formats[] = '%s';
    }

    return (false !== $wpdb->update(
      $this->table_autopay_profiles(),
      $data,
      array('id' => $autopay_profile_id),
      $formats,
      array('%d')
    ));
  }

  private function mrm_payment_method_needs_attention_for_lesson($exp_month, $exp_year, $lesson_start_mysql) {
    $exp_month = (int)$exp_month;
    $exp_year = (int)$exp_year;
    $lesson_start_mysql = (string)$lesson_start_mysql;

    if ($exp_month <= 0 || $exp_year <= 0 || $lesson_start_mysql === '') {
      return array(
        'needs_attention' => true,
        'reason_code' => 'missing_expiration',
        'reason_text' => 'We could not confirm the expiration date of the saved payment method.',
      );
    }

    $lesson_ts = strtotime($lesson_start_mysql);
    if (!$lesson_ts) {
      return array(
        'needs_attention' => true,
        'reason_code' => 'lesson_time_invalid',
        'reason_text' => 'We could not confirm the lesson start time for payment-readiness review.',
      );
    }

    $lesson_year = (int)gmdate('Y', $lesson_ts);
    $lesson_month = (int)gmdate('n', $lesson_ts);

    $expires_before_lesson_month =
      ($exp_year < $lesson_year) ||
      ($exp_year === $lesson_year && $exp_month < $lesson_month);

    if ($expires_before_lesson_month) {
      return array(
        'needs_attention' => true,
        'reason_code' => 'expired_before_lesson',
        'reason_text' => sprintf('The saved card expires %02d/%04d, which is before the lesson month.', $exp_month, $exp_year),
      );
    }

    if (self::MRM_PM_SAME_MONTH_REQUIRES_UPDATE && $exp_year === $lesson_year && $exp_month === $lesson_month) {
      return array(
        'needs_attention' => true,
        'reason_code' => 'expires_same_month',
        'reason_text' => sprintf('The saved card expires %02d/%04d, which is the same month as the scheduled lesson.', $exp_month, $exp_year),
      );
    }

    return array(
      'needs_attention' => false,
      'reason_code' => '',
      'reason_text' => '',
    );
  }

  private function mrm_pm_notice_option_key($lesson_id) {
    return 'mrm_pm_attention_notice_lesson_' . (int)$lesson_id;
  }

  private function mrm_pm_notice_already_sent_for_signature($lesson_id, $signature) {
    $existing = (string)get_option($this->mrm_pm_notice_option_key($lesson_id), '');
    return ($existing !== '' && hash_equals($existing, (string)$signature));
  }

  private function mrm_mark_pm_notice_sent_for_signature($lesson_id, $signature) {
    update_option($this->mrm_pm_notice_option_key($lesson_id), (string)$signature, false);
  }

  private function mrm_clear_pm_notice_signature($lesson_id) {
    delete_option($this->mrm_pm_notice_option_key($lesson_id));
  }

  private function mrm_get_wp_admin_notification_email() {
    return sanitize_email((string)get_option('admin_email', ''));
  }

  private function mrm_format_lesson_mode_label($is_online) {
    return ((int)$is_online === 1) ? 'Online' : 'In Person';
  }

  private function mrm_send_upcoming_payment_method_attention_emails($lesson_row, $profile, $snapshot, $reason_text) {
    $lesson_id = (int)($lesson_row['id'] ?? 0);
    if ($lesson_id <= 0) return false;

    $student_email = sanitize_email((string)($lesson_row['student_email'] ?? ''));
    $lesson_start = (string)($lesson_row['start_time'] ?? '');
    $lesson_length = (int)($lesson_row['lesson_length'] ?? 0);
    $mode_label = $this->mrm_format_lesson_mode_label((int)($lesson_row['is_online'] ?? 0));

    $instructor = $this->mrm_get_instructor_contact_from_id((int)($lesson_row['instructor_id'] ?? 0));
    $admin_email = $this->mrm_get_wp_admin_notification_email();

    $brand = trim((string)($snapshot['brand'] ?? ''));
    $last4 = trim((string)($snapshot['last4'] ?? ''));
    $exp_month = (int)($snapshot['exp_month'] ?? 0);
    $exp_year = (int)($snapshot['exp_year'] ?? 0);

    $card_line = 'Saved payment method on file';
    if ($brand !== '' || $last4 !== '') {
      $card_line = trim($brand . ' ending in ' . $last4);
    }
    if ($exp_month > 0 && $exp_year > 0) {
      $card_line .= sprintf(' (expires %02d/%04d)', $exp_month, $exp_year);
    }

    $lesson_line = trim(($lesson_length > 0 ? $lesson_length . '-minute ' : '') . $mode_label . ' lesson');
    $when_line = ($lesson_start !== '') ? $lesson_start : 'the scheduled lesson time';

    $student_subject = 'Action required: update your payment method before your lesson';
    $student_intro = '<p>We are writing regarding your upcoming lesson.</p>';
    $student_details =
      '<p>Before this lesson can proceed, we need a confirmed payment method on file.</p>' .
      '<div><strong>Lesson:</strong> ' . esc_html($lesson_line) . '</div>' .
      '<div><strong>Scheduled time:</strong> ' . esc_html($when_line) . '</div>' .
      '<div><strong>Saved payment method:</strong> ' . esc_html($card_line) . '</div>' .
      '<div><strong>Reason:</strong> ' . esc_html($reason_text) . '</div>' .
      '<p style="margin-top:12px;">Please update or reconfirm your payment method as soon as possible. Until that is completed, your instructor has been asked to withhold the lesson.</p>';

    $instructor_subject = 'Payment method not confirmed — please withhold upcoming lesson';
    $instructor_intro = '<p>A scheduled AutoPay lesson requires payment-method confirmation before instruction.</p>';
    $instructor_details =
      '<div><strong>Lesson ID:</strong> ' . esc_html((string)$lesson_id) . '</div>' .
      '<div><strong>Lesson:</strong> ' . esc_html($lesson_line) . '</div>' .
      '<div><strong>Scheduled time:</strong> ' . esc_html($when_line) . '</div>' .
      '<div><strong>Student email:</strong> ' . esc_html($student_email) . '</div>' .
      '<div><strong>Issue:</strong> ' . esc_html($reason_text) . '</div>' .
      '<p style="margin-top:12px;">Please withhold providing this lesson until the payment method has been updated and confirmed.</p>';

    $admin_subject = 'AutoPay payment method attention needed for upcoming lesson';
    $admin_intro = '<p>An upcoming AutoPay lesson needs payment-method attention.</p>';
    $admin_details =
      '<div><strong>Lesson ID:</strong> ' . esc_html((string)$lesson_id) . '</div>' .
      '<div><strong>Lesson:</strong> ' . esc_html($lesson_line) . '</div>' .
      '<div><strong>Scheduled time:</strong> ' . esc_html($when_line) . '</div>' .
      '<div><strong>Student email:</strong> ' . esc_html($student_email) . '</div>' .
      '<div><strong>Instructor:</strong> ' . esc_html((string)($instructor['name'] ?? '')) . '</div>' .
      '<div><strong>Instructor email:</strong> ' . esc_html((string)($instructor['email'] ?? '')) . '</div>' .
      '<div><strong>Saved payment method:</strong> ' . esc_html($card_line) . '</div>' .
      '<div><strong>Issue:</strong> ' . esc_html($reason_text) . '</div>' .
      '<p style="margin-top:12px;">Instructor notification has been sent. Please manage any additional communication as needed.</p>';

    $headers = array(
      'Content-Type: text/html; charset=UTF-8',
      'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
    );

    $contact_url = $this->mrm_get_contact_url();

    $student_sent = false;
    if ($student_email && is_email($student_email)) {
      $student_html = $this->mrm_email_wrap_html('Payment method confirmation needed', $student_intro, $student_details, $contact_url, 'Contact Support');
      $student_sent = wp_mail($student_email, $student_subject, $student_html, $headers);
    }

    $instructor_sent = false;
    $instructor_email = sanitize_email((string)($instructor['email'] ?? ''));
    if ($instructor_email && is_email($instructor_email)) {
      $instructor_html = $this->mrm_email_wrap_html('Instructor action required', $instructor_intro, $instructor_details, $contact_url, 'Contact Support');
      $instructor_sent = wp_mail($instructor_email, $instructor_subject, $instructor_html, $headers);
    }

    $admin_sent = false;
    if ($admin_email && is_email($admin_email)) {
      $admin_html = $this->mrm_email_wrap_html('Admin awareness', $admin_intro, $admin_details, $contact_url, 'Contact Support');
      $admin_sent = wp_mail($admin_email, $admin_subject, $admin_html, $headers);
    }

    return ($student_sent || $instructor_sent || $admin_sent);
  }

  private function mrm_ensure_customer_payment_method_ready($customer_id, $payment_method_id) {
    $customer_id = sanitize_text_field((string)$customer_id);
    $payment_method_id = sanitize_text_field((string)$payment_method_id);

    if (!$customer_id || !$payment_method_id) {
      return new WP_Error('mrm_autopay_missing_customer_or_pm', 'Missing Stripe customer or payment method for autopay.');
    }

    $pm = $this->stripe_retrieve_payment_method($payment_method_id);
    if (is_wp_error($pm)) {
      return $pm;
    }

    $attached_customer = isset($pm['customer']) ? (string)$pm['customer'] : '';
    if ($attached_customer !== '' && $attached_customer !== $customer_id) {
      return new WP_Error(
        'mrm_autopay_pm_customer_mismatch',
        'Saved payment method is attached to a different Stripe customer.'
      );
    }

    if ($attached_customer === '') {
      $attach = $this->stripe_attach_payment_method_to_customer($payment_method_id, $customer_id);
      if (is_wp_error($attach)) {
        return $attach;
      }
    }

    $set = $this->stripe_set_default_payment_method($customer_id, $payment_method_id);
    if (is_wp_error($set)) {
      return $set;
    }

    return array(
      'customer_id' => $customer_id,
      'payment_method_id' => $payment_method_id,
    );
  }

  private function stripe_detach_payment_method($payment_method_id) {
    $payment_method_id = sanitize_text_field((string)$payment_method_id);
    if (!$payment_method_id) return new WP_Error('stripe_invalid_args', 'Missing payment method id.');
    return $this->stripe_api_request('POST', '/v1/payment_methods/' . rawurlencode($payment_method_id) . '/detach', array());
  }

  private function stripe_create_refund($payment_intent_id, $amount_cents = null, $reason = 'requested_by_customer') {
    $payment_intent_id = sanitize_text_field((string)$payment_intent_id);
    if (!$payment_intent_id) return new WP_Error('stripe_invalid_args', 'Missing payment_intent id.');

    $params = array(
      'payment_intent' => $payment_intent_id,
      'reason' => $reason,
    );

    if ($amount_cents !== null && (int)$amount_cents > 0) {
      $params['amount'] = (int)$amount_cents;
    }

    return $this->stripe_api_request('POST', '/v1/refunds', $params);
  }

  private function stripe_find_or_create_customer($email) {
    $email = sanitize_email((string)$email);
    if (!$email || !is_email($email)) return new WP_Error('bad_email', 'Valid email required for customer.');

    // Try to find existing customer by email
    $list = $this->stripe_api_request('GET', '/v1/customers', array(
      'email' => $email,
      'limit' => 1,
    ));
    if (is_wp_error($list)) return $list;

    if (isset($list['data'][0]['id'])) {
      return (string)$list['data'][0]['id'];
    }

    // Create new customer
    $created = $this->stripe_api_request('POST', '/v1/customers', array(
      'email' => $email,
    ));
    if (is_wp_error($created)) return $created;

    return isset($created['id']) ? (string)$created['id'] : new WP_Error('stripe_error', 'Unable to create customer.');
  }

  private function stripe_update_customer_address($customer_id, $email, $address = array(), $name = '') {
    $customer_id = trim((string)$customer_id);
    $email = sanitize_email((string)$email);

    if ($customer_id === '') {
      return new WP_Error('missing_customer_id', 'Missing Stripe customer id.');
    }

    $line1 = trim((string)($address['line1'] ?? ''));
    $state = trim((string)($address['state'] ?? ''));
    $postal = trim((string)($address['postal_code'] ?? ''));
    $country = strtoupper(trim((string)($address['country'] ?? 'US')));

    $params = array(
      'email' => $email,
    );

    if ($name !== '') {
      $params['name'] = (string)$name;
    }

    if ($line1 !== '') {
      $params['address[line1]'] = $line1;
    }
    if ($state !== '') {
      $params['address[state]'] = $state;
    }
    if ($postal !== '') {
      $params['address[postal_code]'] = $postal;
    }
    if ($country !== '') {
      $params['address[country]'] = $country;
    }

    $params['shipping[name]'] = $name !== '' ? (string)$name : $email;
    if ($line1 !== '') {
      $params['shipping[address][line1]'] = $line1;
    }
    if ($state !== '') {
      $params['shipping[address][state]'] = $state;
    }
    if ($postal !== '') {
      $params['shipping[address][postal_code]'] = $postal;
    }
    if ($country !== '') {
      $params['shipping[address][country]'] = $country;
    }

    $this->stripe_debug_log('updating stripe customer tax location', array(
      'customer_id' => $customer_id,
      'email' => $email,
      'state' => $state,
      'postal_code' => $postal,
      'country' => $country,
      'line1_present' => $line1 ? 'yes' : 'no',
    ));

    return $this->stripe_api_request('POST', '/v1/customers/' . rawurlencode($customer_id), $params);
  }

  private function stripe_construct_webhook_event($payload, $signature_header, $secret) {
    if (!$payload || !$signature_header || !$secret) {
      return new WP_Error('stripe_webhook_invalid', 'Missing webhook payload, signature, or secret.');
    }

    $parts = explode(',', (string)$signature_header);
    $timestamp = '';
    $signatures = array();

    foreach ($parts as $part) {
      $kv = explode('=', trim($part), 2);
      if (count($kv) !== 2) continue;
      if ($kv[0] === 't') $timestamp = $kv[1];
      if ($kv[0] === 'v1') $signatures[] = $kv[1];
    }

    if ($timestamp === '' || empty($signatures)) {
      return new WP_Error('stripe_webhook_invalid', 'Invalid Stripe-Signature header.');
    }

    $signed_payload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed_payload, $secret);

    $valid = false;
    foreach ($signatures as $sig) {
      if (hash_equals($expected, $sig)) {
        $valid = true;
        break;
      }
    }

    if (!$valid) {
      return new WP_Error('stripe_webhook_invalid', 'Webhook signature verification failed.');
    }

    $json = json_decode($payload, true);
    if (!is_array($json)) {
      return new WP_Error('stripe_webhook_invalid', 'Invalid webhook JSON.');
    }

    return $json;
  }

  private function mrm_webhook_event_already_processed($event_id) {
    global $wpdb;
    return (bool)$wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$this->table_webhook_events()} WHERE stripe_event_id=%s LIMIT 1",
      (string)$event_id
    ));
  }

  private function mrm_mark_webhook_event_processed($event_id, $event_type, $object_id = '') {
    global $wpdb;
    $now = current_time('mysql');

    $wpdb->insert($this->table_webhook_events(), array(
      'stripe_event_id' => (string)$event_id,
      'event_type' => (string)$event_type,
      'object_id' => (string)$object_id,
      'processed_at' => $now,
      'created_at' => $now,
    ), array('%s','%s','%s','%s','%s'));
  }

  private function mrm_handle_payment_intent_succeeded_webhook($pi) {
    $this->stripe_debug_log('payment_intent.succeeded handler entered', array(
      'pi_id' => (string)($pi['id'] ?? ''),
      'customer_id' => (string)($pi['customer'] ?? ''),
      'payment_method_id' => (string)($pi['payment_method'] ?? ''),
    ));

    if (!is_array($pi)) return;

    $pi_id = (string)($pi['id'] ?? '');
    if ($pi_id === '') return;

    $latest_charge = '';
    if (!empty($pi['latest_charge'])) {
      $latest_charge = (string)$pi['latest_charge'];
    }

    $order = $this->get_order_by_pi($pi_id);
    if (!$order) return;

    $metadata = isset($pi['metadata']) && is_array($pi['metadata']) ? $pi['metadata'] : array();
    $metadata['mrm_latest_charge_id'] = $latest_charge;

      $this->update_order_status_from_pi($pi_id, 'paid', (string)($pi['status'] ?? 'succeeded'), $metadata);
      $this->mrm_subscription_debug_log('order updated after payment success', array(
        'order_id' => (int)($order['id'] ?? 0),
        'payment_intent_id' => (string)($pi['id'] ?? ''),
        'status' => 'paid',
      ));

    $order = $this->get_order_by_pi($pi_id);
    if ($order) {
      $this->mrm_maybe_send_purchase_receipt_email($pi, $order);
      $this->mrm_maybe_create_payout_ledger_for_order($order);

      $meta = isset($pi['metadata']) && is_array($pi['metadata']) ? $pi['metadata'] : array();
      $addon_yes = (isset($meta['mrm_sheet_music_addon']) && strtolower((string)$meta['mrm_sheet_music_addon']) === 'yes');
      $product_type = (string)($order['product_type'] ?? '');

      if ($addon_yes && $product_type === 'lesson' && !empty($order['id'])) {
        $this->mrm_attempt_sheet_music_subscription_activation((int)$order['id'], $pi, 'payment_intent_succeeded_webhook');
      }

      $lesson_id = 0;

      if (!empty($metadata['mrm_lesson_id'])) {
        $lesson_id = (int)$metadata['mrm_lesson_id'];
      }

      if ($lesson_id <= 0 && !empty($order['metadata_json'])) {
        $decoded = json_decode((string)$order['metadata_json'], true);
        if (is_array($decoded) && !empty($decoded['mrm_lesson_id'])) {
          $lesson_id = (int)$decoded['mrm_lesson_id'];
        }
      }

      if ($lesson_id > 0 && (string)($order['sku'] ?? '') === 'autopay_lesson_charge') {
        $this->mrm_finalize_autopay_lesson_success($lesson_id, $order, $pi_id);
      }
    }
  }

  private function mrm_handle_payment_intent_failed_webhook($pi, $local_status = 'failed') {
    if (!is_array($pi)) return;

    $pi_id = (string)($pi['id'] ?? '');
    if ($pi_id === '') return;

    $order = $this->get_order_by_pi($pi_id);
    if (!$order) return;

    $metadata = isset($pi['metadata']) && is_array($pi['metadata']) ? $pi['metadata'] : array();
    if (!empty($pi['latest_charge'])) {
      $metadata['mrm_latest_charge_id'] = (string)$pi['latest_charge'];
    }

    $this->update_order_status_from_pi($pi_id, $local_status, (string)($pi['status'] ?? ''), $metadata);

    $lesson_id = 0;
    if (!empty($metadata['mrm_lesson_id'])) {
      $lesson_id = (int)$metadata['mrm_lesson_id'];
    }

    if ($lesson_id <= 0 && !empty($order['metadata_json'])) {
      $decoded = json_decode((string)$order['metadata_json'], true);
      if (is_array($decoded) && !empty($decoded['mrm_lesson_id'])) {
        $lesson_id = (int)$decoded['mrm_lesson_id'];
      }
    }

    if ($lesson_id > 0 && (string)($order['sku'] ?? '') === 'autopay_lesson_charge') {
      $message = 'Autopay webhook failure: ' . (string)($pi['status'] ?? $local_status);
      $this->mrm_finalize_autopay_lesson_failure($lesson_id, $message);
    }
  }

  private function mrm_handle_charge_refunded_webhook($charge) {
    if (!is_array($charge)) return;

    $pi_id = (string)($charge['payment_intent'] ?? '');
    if ($pi_id === '') return;

    $order = $this->get_order_by_pi($pi_id);
    if (!$order || empty($order['id'])) return;

    $order_id = (int)$order['id'];
    $this->update_order_status_from_pi($pi_id, 'refunded', 'refunded', array(
      'mrm_refunded_at' => current_time('mysql'),
    ));

    $product_type = (string)($order['product_type'] ?? '');
    $sku = (string)($order['sku'] ?? '');

    // Revoke piece-product access rows when a refunded piece purchase is detected.
    if ($product_type === 'sheet_music' && $sku !== '' && $sku !== 'all-sheet-music') {
      global $wpdb;
      $access_table = $this->table_sheet_music_access();
      $now = current_time('mysql');

      $wpdb->update(
        $access_table,
        array(
          'revoked_at' => $now,
        ),
        array(
          'sku' => $sku,
          'source' => 'stripe_pi',
          'source_id' => $pi_id,
          'revoked_at' => null,
        ),
        array('%s'),
        array('%s','%s','%s','%s')
      );

      $this->stripe_debug_log('piece product access revoked due to refund', array(
        'order_id' => $order_id,
        'pi_id' => $pi_id,
        'sku' => $sku,
      ));
    }
  }

  private function mrm_handle_customer_subscription_created_webhook($subscription) {
    $email = sanitize_email((string)($subscription['metadata']['mrm_customer_email'] ?? ''));
    $this->stripe_debug_log('customer.subscription.created handler entered', array(
      'subscription_id' => (string)($subscription['id'] ?? ''),
      'status' => (string)($subscription['status'] ?? ''),
      'email' => $email,
    ));
    $this->mrm_subscription_debug_log('customer.subscription.created webhook entered', array(
      'subscription_id' => (string)($subscription['id'] ?? ''),
      'status' => (string)($subscription['status'] ?? ''),
      'email' => $email,
    ));

    $this->mrm_sync_local_sheet_music_subscription_from_stripe($subscription, $email);

    $subscription_id = (string)($subscription['id'] ?? '');
    $local = $this->mrm_get_sheet_music_subscription_by_stripe_id($subscription_id);

    if (is_array($local) && !empty($local)) {
      $order = $this->get_order_by_meta_value('mrm_sheet_music_subscription_id', $subscription_id);
      $email_sent_at = '';

      if (is_array($order) && !empty($order)) {
        $email_sent_at = (string)$this->mrm_get_order_meta_value($order, 'mrm_sheet_music_subscription_enrollment_email_sent_at', '');
      }

      if ($email_sent_at === '' && in_array((string)($subscription['status'] ?? ''), array('trialing', 'active'), true)) {
        $billing_anchor_ts = 0;
        if (!empty($subscription['current_period_end'])) {
          $billing_anchor_ts = (int)$subscription['current_period_end'];
        }

        $sent = $this->mrm_send_sheet_music_subscription_enrollment_email($local, $billing_anchor_ts);
        $this->stripe_debug_log('subscription created webhook enrollment email result', array(
          'subscription_id' => $subscription_id,
          'email' => (string)($local['email_plain'] ?? ''),
          'sent' => ($sent ? 'yes' : 'no'),
        ));
        $this->mrm_subscription_debug_log('created webhook enrollment email result', array(
          'subscription_id' => $subscription_id,
          'email' => (string)($local['email_plain'] ?? ''),
          'sent' => ($sent ? 'yes' : 'no'),
        ));

        if ($sent && is_array($order) && !empty($order)) {
          $this->mrm_set_order_meta_flag((int)$order['id'], 'mrm_sheet_music_subscription_enrollment_email_sent_at', current_time('mysql'));
        }
      }
    }
  }

  private function mrm_handle_customer_subscription_updated_webhook($subscription) {
    $email = sanitize_email((string)($subscription['metadata']['mrm_customer_email'] ?? ''));
    $this->stripe_debug_log('customer.subscription.updated handler entered', array(
      'subscription_id' => (string)($subscription['id'] ?? ''),
      'status' => (string)($subscription['status'] ?? ''),
      'cancel_at_period_end' => !empty($subscription['cancel_at_period_end']) ? 'yes' : 'no',
      'email' => $email,
    ));
    $this->mrm_sync_local_sheet_music_subscription_from_stripe($subscription, $email);
  }

  private function mrm_handle_customer_subscription_deleted_webhook($subscription) {
    $email = sanitize_email((string)($subscription['metadata']['mrm_customer_email'] ?? ''));
    $subscription_id = (string)($subscription['id'] ?? '');

    $this->stripe_debug_log('customer.subscription.deleted handler entered', array(
      'subscription_id' => $subscription_id,
      'status' => (string)($subscription['status'] ?? ''),
      'email' => $email,
    ));
    $this->mrm_subscription_debug_log('customer.subscription.deleted webhook entered', array(
      'subscription_id' => $subscription_id,
      'status' => (string)($subscription['status'] ?? ''),
      'email' => $email,
    ));

    $this->mrm_sync_local_sheet_music_subscription_from_stripe($subscription, $email);

    $order = $this->get_order_by_meta_value('mrm_sheet_music_subscription_id', $subscription_id);
    if (is_array($order) && !empty($order)) {
      $order_id = (int)($order['id'] ?? 0);
      if ($order_id > 0) {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'retry_reopened_after_unhealthy_subscription');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', 'Stripe reported the subscription as deleted/canceled; reopening activation.');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_id', '');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_created_at', '');
        $this->mrm_subscription_debug_log('deleted webhook reopened order for retry', array(
          'order_id' => $order_id,
          'subscription_id' => $subscription_id,
        ));
      }
    }

    $local = $this->mrm_get_sheet_music_subscription_by_stripe_id($subscription_id);
    if (!empty($local['email_plain'])) {
      $sent = $this->mrm_send_sheet_music_subscription_cancelled_email($local, $subscription);

      $this->stripe_debug_log('subscription cancellation email result', array(
        'subscription_id' => $subscription_id,
        'email' => (string)($local['email_plain'] ?? ''),
        'sent' => ($sent ? 'yes' : 'no'),
      ));
    }
  }

  private function mrm_handle_invoice_paid_webhook($invoice) {
    $this->stripe_debug_log('invoice.paid handler entered', array(
      'invoice_id' => (string)($invoice['id'] ?? ''),
      'subscription_id' => (string)($invoice['subscription'] ?? ''),
      'amount_paid' => (int)($invoice['amount_paid'] ?? 0),
    ));

    $subscription_id = (string)($invoice['subscription'] ?? '');
    $amount_paid = (int)($invoice['amount_paid'] ?? 0);

    // Ignore non-subscription or zero-dollar invoice events.
    if ($subscription_id === '' || $amount_paid <= 0) {
      $this->stripe_debug_log('invoice.paid ignored', array(
        'invoice_id' => (string)($invoice['id'] ?? ''),
        'subscription_id' => $subscription_id,
        'amount_paid' => $amount_paid,
        'reason' => ($subscription_id === '' ? 'missing_subscription_id' : 'zero_amount_paid'),
      ));
      return;
    }

    $sub = $this->stripe_retrieve_subscription($subscription_id);
    if (is_wp_error($sub)) {
      $this->stripe_debug_log('invoice.paid subscription retrieve failed', array(
        'invoice_id' => (string)($invoice['id'] ?? ''),
        'subscription_id' => $subscription_id,
        'error' => $sub->get_error_message(),
      ));
      return;
    }

    $configured_price_id = $this->subscription_price_id();
    $invoice_price_id = (string)($invoice['lines']['data'][0]['price']['id'] ?? '');

    if ($configured_price_id !== '' && $invoice_price_id !== '' && $invoice_price_id !== $configured_price_id) {
      $this->stripe_debug_log('invoice.paid ignored due to price mismatch', array(
        'invoice_id' => (string)($invoice['id'] ?? ''),
        'subscription_id' => $subscription_id,
        'invoice_price_id' => $invoice_price_id,
        'configured_price_id' => $configured_price_id,
      ));
      return;
    }

    $this->mrm_sync_local_sheet_music_subscription_from_stripe($sub, (string)($sub['metadata']['mrm_customer_email'] ?? ''));

    $local = $this->mrm_get_sheet_music_subscription_by_stripe_id($subscription_id);
    if (!empty($local['email_plain'])) {
      $invoice_created_ts = !empty($invoice['created']) ? (int)$invoice['created'] : time();
      $invoice_id = (string)($invoice['id'] ?? '');

      $this->mrm_grant_all_sheet_music_ledger((string)$local['email_plain'], $invoice_created_ts, 'stripe_subscription_invoice', $invoice_id);

      $this->mrm_create_composer_payout_for_subscription_invoice($invoice, $local);

      $sent = $this->mrm_send_sheet_music_subscription_charge_email($local, $invoice);

      $this->stripe_debug_log('subscription recurring charge email result', array(
        'invoice_id' => $invoice_id,
        'subscription_id' => $subscription_id,
        'email' => (string)($local['email_plain'] ?? ''),
        'sent' => ($sent ? 'yes' : 'no'),
      ));
    }
  }

  private function mrm_handle_invoice_payment_failed_webhook($invoice) {
    $subscription_id = (string)($invoice['subscription'] ?? '');
    if ($subscription_id === '') return;

    $sub = $this->stripe_retrieve_subscription($subscription_id);
    if (is_wp_error($sub)) return;

    $email = sanitize_email((string)($sub['metadata']['mrm_customer_email'] ?? ''));
    $this->mrm_sync_local_sheet_music_subscription_from_stripe($sub, $email);
  }

  /* =========================================================
   * Orders ledger helpers
   * ======================================================= */

  private function create_order($email_hash, $sku, $product_type, $amount_cents, $currency, $metadata) {
    global $wpdb;
    $now = current_time('mysql');
    $environment_mode = 'live';
    $wpdb->insert($this->table_orders(), array(
      'email_hash' => $email_hash,
      'sku' => $sku,
      'product_type' => $product_type,
      'amount_cents' => (int)$amount_cents,
      'currency' => $currency ?: 'usd',
      'environment_mode' => $environment_mode,
      'status' => 'created',
      'metadata_json' => wp_json_encode($metadata),
      'created_at' => $now,
      'updated_at' => $now,
    ), array('%s','%s','%s','%d','%s','%s','%s','%s','%s','%s'));
    return (int)$wpdb->insert_id;
  }

  private function attach_payment_intent_to_order($order_id, $payment_intent_id, $stripe_status = null, $local_status = 'processing') {
    global $wpdb;

    $data = array(
      'stripe_payment_intent_id' => $payment_intent_id,
      'stripe_status' => $stripe_status,
      'updated_at' => current_time('mysql'),
    );
    $fmt = array('%s','%s','%s');

    if ($local_status !== null && $local_status !== '') {
      $data['status'] = (string)$local_status;
      $fmt[] = '%s';
    }

    $wpdb->update(
      $this->table_orders(),
      $data,
      array('id' => (int)$order_id),
      $fmt,
      array('%d')
    );
  }

  private function update_order_amount_and_metadata($order_id, $amount_cents, $metadata_array) {
    global $wpdb;
    $wpdb->update(
      $this->table_orders(),
      array(
        'amount_cents' => (int)$amount_cents,
        'metadata_json' => wp_json_encode(is_array($metadata_array) ? $metadata_array : array()),
        'updated_at' => current_time('mysql'),
      ),
      array('id' => (int)$order_id),
      array('%d','%s','%s'),
      array('%d')
    );
  }

  private function get_order($order_id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$this->table_orders()} WHERE id=%d LIMIT 1",
      (int)$order_id
    ), ARRAY_A);
  }

  private function get_order_by_pi($payment_intent_id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$this->table_orders()} WHERE stripe_payment_intent_id=%s LIMIT 1",
      $payment_intent_id
    ), ARRAY_A);
  }

  private function update_order_status_from_pi($payment_intent_id, $status, $stripe_status = null, $metadata = null) {
    global $wpdb;
    $data = array(
      'status' => $status,
      'stripe_payment_intent_id' => (string)$payment_intent_id,
      'stripe_status' => $stripe_status,
      'updated_at' => current_time('mysql'),
    );
    $fmt = array('%s','%s','%s','%s');

    if ($metadata !== null) {
      // Merge with any existing metadata_json so we can store local flags (like receipt sent)
      $existing_json = $wpdb->get_var($wpdb->prepare(
        "SELECT metadata_json FROM {$this->table_orders()} WHERE stripe_payment_intent_id=%s LIMIT 1",
        $payment_intent_id
      ));
      $existing = array();
      if ($existing_json) {
        $decoded = json_decode((string)$existing_json, true);
        if (is_array($decoded)) $existing = $decoded;
      }

      $incoming = is_array($metadata) ? $metadata : array();
      $merged = array_merge($existing, $incoming);

      $data['metadata_json'] = wp_json_encode($merged);
      $fmt[] = '%s';
    }

    $wpdb->update($this->table_orders(), $data, array('stripe_payment_intent_id' => $payment_intent_id), $fmt, array('%s'));
  }

  private function link_order($order_id, $source, $source_object_id) {
    global $wpdb;
    $wpdb->insert($this->table_links(), array(
      'order_id' => (int)$order_id,
      'source' => sanitize_text_field((string)$source),
      'source_object_id' => sanitize_text_field((string)$source_object_id),
      'created_at' => current_time('mysql'),
    ), array('%d','%s','%s','%s'));
  }


  private function mrm_create_or_update_credits_for_order($order_id, $instructor_id, $email_hash, $currency, $base_cents, $lesson_count) {
    global $wpdb;
    $table = $this->table_lesson_credits();
    $now = current_time('mysql');

    $lesson_count = max(1, (int)$lesson_count);
    $unit = (int) floor(((int)$base_cents) / $lesson_count);

    $exists = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$table} WHERE order_id=%d AND instructor_id=%d LIMIT 1",
      (int)$order_id,
      (int)$instructor_id
    ));

    if ($exists) return (int)$exists;

    $wpdb->insert($table, array(
      'order_id' => (int)$order_id,
      'instructor_id' => (int)$instructor_id,
      'email_hash' => (string)$email_hash,
      'currency' => strtolower((string)$currency),
      'unit_base_cents' => (int)$unit,
      'total_credits' => (int)$lesson_count,
      'remaining_credits' => (int)$lesson_count,
      'created_at' => $now,
      'updated_at' => $now,
    ), array('%d','%d','%s','%s','%d','%d','%d','%s','%s'));

    return (int)$wpdb->insert_id;
  }

  private function mrm_get_autopay_profile($autopay_profile_id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$this->table_autopay_profiles()} WHERE id=%d LIMIT 1",
      (int)$autopay_profile_id
    ), ARRAY_A);
  }

  private function mrm_get_order_by_id($order_id) {
    global $wpdb;
    return $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$this->table_orders()} WHERE id=%d LIMIT 1", (int)$order_id),
      ARRAY_A
    );
  }

  private function mrm_order_is_auto_refund_eligible($order, $max_age_days = 7) {
    if (!is_array($order)) {
      return false;
    }

    $status = (string)($order['status'] ?? '');
    if (!in_array($status, array('paid', 'completed', 'succeeded'), true)) {
      return false;
    }

    $created_at = (string)($order['created_at'] ?? '');
    if ($created_at === '') {
      // Fail closed: if we cannot prove the charge age, do not auto-refund.
      return false;
    }

    $created_ts = strtotime($created_at);
    if (!$created_ts) {
      // Fail closed: if timestamp parsing fails, do not auto-refund.
      return false;
    }

    $max_age_seconds = max(1, (int)$max_age_days) * DAY_IN_SECONDS;
    $age_seconds = time() - $created_ts;

    return ($age_seconds <= $max_age_seconds);
  }

  private function mrm_google_truth_confirms_lesson_ended( $lesson_id ) {
    global $wpdb;

    $lesson_id = (int)$lesson_id;
    if ($lesson_id <= 0) {
      return new WP_Error('mrm_invalid_lesson_id', 'Invalid lesson ID.');
    }

    $lessons_table = $wpdb->prefix . 'mrm_lessons';
    $instructors_table = $wpdb->prefix . 'mrm_instructors';

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT l.*, i.calendar_id
       FROM {$lessons_table} l
       LEFT JOIN {$instructors_table} i ON i.id = l.instructor_id
       WHERE l.id = %d
       LIMIT 1",
      $lesson_id
    ), ARRAY_A);

    if (!$row) {
      return new WP_Error('mrm_lesson_missing', 'Lesson row not found.');
    }

    $calendar_id = (string)($row['calendar_id'] ?? '');
    if ($calendar_id === '') {
      return new WP_Error('mrm_google_calendar_missing', 'Lesson is missing an instructor Google calendar ID.');
    }

    if (!class_exists('MRM_Lesson_Scheduler')) {
      return new WP_Error('mrm_scheduler_missing', 'Lesson scheduler class unavailable.');
    }

    $scheduler = MRM_Lesson_Scheduler::get_instance();
    if (!$scheduler || !method_exists($scheduler, 'sync_lesson_row_from_google_truth')) {
      return new WP_Error('mrm_scheduler_sync_missing', 'Google sync helper unavailable.');
    }

    $sync = $scheduler->sync_lesson_row_from_google_truth($row, $calendar_id, 120);
    $status = (string)($sync['status'] ?? 'unresolved');
    $reason = (string)($sync['reason'] ?? 'unresolved');

    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log(
        '[MRM AutoPay] google_truth_check lesson_id=' . $lesson_id .
        ' status=' . $status .
        ' reason=' . $reason .
        ' calendar_id=' . $calendar_id .
        ' local_start=' . (string)($row['start_time'] ?? '') .
        ' local_end=' . (string)($row['end_time'] ?? '')
      );
    }

    if ($status === 'cancelled') {
      return new WP_Error('mrm_google_cancelled', 'Google event is cancelled.');
    }

    if ($status !== 'resolved') {
      return new WP_Error('mrm_google_unresolved', 'Could not resolve lesson from Google: ' . $reason);
    }

    $synced = isset($sync['lesson_row']) && is_array($sync['lesson_row']) ? $sync['lesson_row'] : $row;
    $end_utc = (string)($sync['end_utc'] ?? ($synced['end_time'] ?? ''));

    if ($end_utc === '') {
      return new WP_Error('mrm_google_end_missing', 'Google event end time is missing.');
    }

    $end_ts = strtotime($end_utc);
    if (!$end_ts) {
      return new WP_Error('mrm_google_end_invalid', 'Google event end time could not be parsed.');
    }

    if ($end_ts > time()) {
      return new WP_Error(
        'mrm_google_not_ended',
        'Google event has not ended yet. end_utc=' . $end_utc
      );
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log(
        '[MRM AutoPay] google_truth_confirmed lesson_id=' . $lesson_id .
        ' end_utc=' . $end_utc .
        ' synced_start=' . (string)($synced['start_time'] ?? '') .
        ' synced_end=' . (string)($synced['end_time'] ?? '')
      );
    }

    return array(
      'lesson_row' => $synced,
      'end_utc' => $end_utc,
      'sync' => $sync,
    );
  }


  private function mrm_validate_autopay_profile_for_charge( $profile ) {
    if ( ! is_array( $profile ) ) {
      return new WP_Error( 'mrm_autopay_invalid_profile', 'Autopay profile is invalid.' );
    }

    if ( (int)($profile['active'] ?? 0) !== 1 ) {
      return new WP_Error( 'mrm_autopay_inactive', 'Autopay profile is inactive.' );
    }

    $customer_id = (string)($profile['customer_id'] ?? '');
    $payment_method_id = (string)($profile['payment_method_id'] ?? '');

    if ( $customer_id === '' ) {
      return new WP_Error( 'mrm_autopay_missing_customer', 'Autopay profile is missing Stripe customer ID.' );
    }

    if ( $payment_method_id === '' ) {
      return new WP_Error( 'mrm_autopay_missing_pm', 'Autopay profile is missing Stripe payment method ID.' );
    }

    $amount_cents = (int)($profile['unit_base_cents'] ?? 0);
    if ( $amount_cents <= 0 ) {
      return new WP_Error( 'mrm_autopay_bad_amount', 'Autopay profile amount is invalid.' );
    }

    return array(
      'customer_id' => $customer_id,
      'payment_method_id' => $payment_method_id,
      'amount_cents' => $amount_cents,
      'currency' => (string)($profile['currency'] ?? 'usd'),
    );
  }

  private function mrm_count_open_lessons_for_autopay($autopay_profile_id) {
    global $wpdb;
    if ((int)$autopay_profile_id <= 0) return 0;

    $table = $wpdb->prefix . 'mrm_lessons';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) return 0;

    return (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*)
       FROM {$table}
       WHERE autopay_profile_id = %d
         AND (
              payout_unlocked_at IS NULL
              OR charge_status IN ('due','processing','failed')
         )
         AND status NOT IN ('cancelled','series')",
      (int)$autopay_profile_id
    ));
  }

  private function mrm_required_followup_charge_count($profile) {
    $plan_kind = (string)($profile['plan_kind'] ?? 'indefinite');
    $authorized = (int)($profile['authorized_lesson_count'] ?? 0);

    // First lesson is prepaid up front in your recurring autopay model.
    if ($plan_kind === 'bounded') {
      return max(0, $authorized - 1);
    }

    return PHP_INT_MAX;
  }

  private function mrm_increment_autopay_charge_count($autopay_profile_id) {
    global $wpdb;
    if ((int)$autopay_profile_id <= 0) return;

    $profile = $this->mrm_get_autopay_profile($autopay_profile_id);
    if (!$profile) return;

    $new_count = max(0, (int)($profile['charged_lesson_count'] ?? 0) + 1);

    $wpdb->update(
      $this->table_autopay_profiles(),
      array(
        'charged_lesson_count' => $new_count,
        'updated_at' => current_time('mysql'),
      ),
      array('id' => (int)$autopay_profile_id),
      array('%d','%s'),
      array('%d')
    );
  }

  private function mrm_maybe_deactivate_and_detach_autopay($autopay_profile_id, $force = false) {
    global $wpdb;

    $autopay_profile_id = (int)$autopay_profile_id;
    if ($autopay_profile_id <= 0) return false;

    $profile = $this->mrm_get_autopay_profile($autopay_profile_id);
    if (!$profile) return false;

    $plan_kind = (string)($profile['plan_kind'] ?? 'indefinite');
    $authorized = (int)($profile['authorized_lesson_count'] ?? 0);
    $charged = (int)($profile['charged_lesson_count'] ?? 0);
    $open_count = $this->mrm_count_open_lessons_for_autopay($autopay_profile_id);
    $required_followup_count = $this->mrm_required_followup_charge_count($profile);

    $should_detach = false;

    if ($force) {
      $should_detach = true;
    } elseif ($plan_kind === 'bounded') {
      $should_detach = ($authorized > 0 && $charged >= $required_followup_count && $open_count <= 0);
    } else {
      $should_detach = ($open_count <= 0);
    }

    error_log('[MRM Payments Hub] autopay detach evaluation'
      . ' profile_id=' . $autopay_profile_id
      . ' plan_kind=' . $plan_kind
      . ' authorized=' . $authorized
      . ' charged=' . $charged
      . ' required_followup_count=' . $required_followup_count
      . ' open_count=' . $open_count
      . ' should_detach=' . ($should_detach ? 'yes' : 'no')
    );

    if (!$should_detach) return false;

    $pm_id = (string)($profile['payment_method_id'] ?? '');
    if ($pm_id !== '') {
      $det = $this->stripe_detach_payment_method($pm_id);
      if (is_wp_error($det)) {
        error_log('[MRM Payments Hub] Detach PM failed for autopay profile ' . $autopay_profile_id . ': ' . $det->get_error_message());
        return false;
      }
    }

    $wpdb->update(
      $this->table_autopay_profiles(),
      array(
        'active' => 0,
        'detached_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
      ),
      array('id' => $autopay_profile_id),
      array('%d','%s','%s'),
      array('%d')
    );

    error_log('[MRM Payments Hub] autopay profile detached'
      . ' profile_id=' . $autopay_profile_id
      . ' charged=' . $charged
      . ' open_count=' . $open_count
    );

    return true;
  }

  private function mrm_find_lesson_charge_order($lesson_id) {
    global $wpdb;
    $lesson_id = (int)$lesson_id;
    if ($lesson_id <= 0) return null;

    return $wpdb->get_row($wpdb->prepare(
      "SELECT o.*
       FROM {$this->table_orders()} o
       INNER JOIN {$this->table_links()} l ON l.order_id = o.id
       WHERE l.source = %s
         AND l.source_object_id = %s
       ORDER BY o.id DESC
       LIMIT 1",
      'lesson',
      (string)$lesson_id
    ), ARRAY_A);
  }

  private function mrm_request_refund_for_order($order, $note = '', $respect_refund_window = true) {
    if (!is_array($order)) return false;

    $status = (string)($order['status'] ?? '');
    $pi_id = (string)($order['stripe_payment_intent_id'] ?? '');
    $order_id = (int)($order['id'] ?? 0);

    if ($pi_id === '' || $status === 'refunded') return false;
    if (!in_array($status, array('paid','completed','succeeded'), true)) return false;

    if ($respect_refund_window && !$this->mrm_order_is_auto_refund_eligible($order, self::MRM_AUTO_REFUND_MAX_AGE_DAYS)) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(
          '[MRM Payments Hub] Auto-refund skipped: order is older than 7 days. order_id=' . $order_id .
          ' created_at=' . (string)($order['created_at'] ?? '') .
          ' note=' . (string)$note
        );
      }

      $this->update_order_status_from_pi($pi_id, $status, $status, array(
        'mrm_auto_refund_skipped_at' => current_time('mysql'),
        'mrm_auto_refund_skip_reason' => 'older_than_7_days',
        'mrm_auto_refund_note' => (string)$note,
      ));

      return false;
    }

    $refund = $this->stripe_create_refund($pi_id, null, 'requested_by_customer');
    if (is_wp_error($refund)) {
      error_log('[MRM Payments Hub] Refund failed for order ' . $order_id . ': ' . $refund->get_error_message());
      return false;
    }

    $this->update_order_status_from_pi($pi_id, 'refund_pending', 'refund_pending', array(
      'mrm_refund_requested_at' => current_time('mysql'),
      'mrm_refund_note' => (string)$note,
    ));

    return true;
  }


  private function mrm_request_partial_refund_for_order($order, $amount_cents, $note = '', $respect_refund_window = true) {
    if (!is_array($order)) return false;

    $status = (string)($order['status'] ?? '');
    $pi_id = (string)($order['stripe_payment_intent_id'] ?? '');
    $order_id = (int)($order['id'] ?? 0);
    $amount_cents = (int)$amount_cents;

    if ($amount_cents <= 0) return false;
    if ($pi_id === '' || $status === 'refunded') return false;
    if (!in_array($status, array('paid','completed','succeeded'), true)) return false;

    if ($respect_refund_window && !$this->mrm_order_is_auto_refund_eligible($order, self::MRM_AUTO_REFUND_MAX_AGE_DAYS)) {
      $this->update_order_status_from_pi($pi_id, $status, $status, array(
        'mrm_auto_refund_skipped_at' => current_time('mysql'),
        'mrm_auto_refund_skip_reason' => 'older_than_7_days',
        'mrm_auto_refund_note' => (string)$note,
      ));
      return false;
    }

    $refund = $this->stripe_create_refund($pi_id, $amount_cents, 'requested_by_customer');
    if (is_wp_error($refund)) {
      error_log('[MRM Payments Hub] Partial refund failed for order ' . $order_id . ': ' . $refund->get_error_message());
      return false;
    }

    $this->update_order_status_from_pi($pi_id, $status, $status, array(
      'mrm_partial_refund_requested_at' => current_time('mysql'),
      'mrm_partial_refund_amount_cents' => (string)$amount_cents,
      'mrm_partial_refund_note' => (string)$note,
    ));

    return true;
  }

  private function mrm_calculate_prepay_per_lesson_refund_cents($order) {
    if (!is_array($order)) return 0;

    $meta = $this->mrm_get_order_meta_array($order);
    $lesson_count = max(1, (int)($meta['mrm_lesson_count'] ?? 1));
    $base_amount_cents = (int)($meta['mrm_base_amount_cents'] ?? 0);

    if ($base_amount_cents <= 0 || $lesson_count <= 0) {
      return 0;
    }

    return (int) floor($base_amount_cents / $lesson_count);
  }

  private function decode_email_hash(string $hash): ?string {
    $map = get_option('mrm_pay_hub_email_map', array());
    if (!is_array($map)) return null;
    $email = $map[$hash] ?? null;
    return $email && is_email($email) ? $email : null;
  }

  /* =========================================================
   * Purchase receipt email (custom)
   * ======================================================= */

  private function mrm_get_site_logo_url(): string {
    $custom_logo_id = (int) get_theme_mod('custom_logo');
    if ($custom_logo_id > 0) {
      $img = wp_get_attachment_image_src($custom_logo_id, 'full');
      if (is_array($img) && !empty($img[0])) return (string) $img[0];
    }
    return '';
  }

  private function mrm_get_contact_url(): string {
    // Prefer a real Contact page if it exists, otherwise fallback to an on-page anchor.
    $page = get_page_by_path('contact');
    $url = ($page && !empty($page->ID)) ? get_permalink($page->ID) : home_url('/#contact');

    // Allow you to override later without editing plugin code:
    // add_filter('mrm_contact_url', fn($u)=>'https://example.com/contact');
    $url = (string) apply_filters('mrm_contact_url', $url);
    return $url;
  }

  private function mrm_email_wrap_html($title, $intro_html, $details_html, $cta_url = '', $cta_label = '') {
    $title_safe = esc_html((string)$title);
    $logo_url = $this->mrm_get_site_logo_url();
    $logo_html = '';
    if ($logo_url) {
      $logo_html = '<div style="text-align:center;margin:0 0 12px 0;">
    <img src="'.esc_url($logo_url).'" alt="Logo" style="max-width:180px;height:auto;display:inline-block;">
  </div>';
    }
    $cta = '';
    if ($cta_url && $cta_label) {
      $cta = '<p style="margin:20px 0 0 0;">
      <a href="'.esc_url($cta_url).'" style="display:inline-block;padding:12px 16px;background:#111;color:#fff;text-decoration:none;border-radius:10px;">
        '.esc_html($cta_label).'
      </a>
    </p>';
    }

    return '
  <div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;padding:18px;">
    <div style="border:1px solid #e7e7e7;border-radius:14px;padding:18px;">
      '.$logo_html.'
      <h2 style="margin:0 0 10px 0;font-size:18px;line-height:1.2;text-align:center;">'.$title_safe.'</h2>
      <div style="font-size:14px;line-height:1.5;color:#222;">'.$intro_html.'</div>
      <div style="margin-top:12px;font-size:14px;line-height:1.5;color:#222;">'.$details_html.'</div>
      '.$cta.'
      <div style="margin-top:18px;font-size:12px;color:#666;">
        If you need help, use the contact link above.
      </div>
    </div>
  </div>';
  }

  private function mrm_generate_subscription_portal_token() {
    return wp_generate_password(48, false, false);
  }

  private function mrm_get_sheet_music_subscription_by_stripe_id($stripe_subscription_id) {
    global $wpdb;
    $table = $this->table_sheet_music_subscriptions();

    return $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table} WHERE stripe_subscription_id = %s LIMIT 1",
        (string)$stripe_subscription_id
      ),
      ARRAY_A
    );
  }

  private function mrm_get_active_sheet_music_subscription_by_email($email) {
    global $wpdb;

    $email = sanitize_email((string)$email);
    if (!$email || !is_email($email)) return array();

    $table = $this->table_sheet_music_subscriptions();
    $email_hash = $this->email_hash($email);

    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE email_hash = %s
           AND stripe_status IN ('trialing','active')
         ORDER BY id DESC
         LIMIT 1",
        $email_hash
      ),
      ARRAY_A
    );

    return is_array($row) ? $row : array();
  }

  private function mrm_get_retryable_sheet_music_subscription_orders($limit = 25) {
    global $wpdb;

    $limit = max(1, min(100, (int)$limit));
    $table = $this->table_orders();

    $sql = $wpdb->prepare(
      "SELECT *
       FROM {$table}
       WHERE product_type = %s
         AND status IN ('paid','completed','succeeded')
         AND (
           metadata_json LIKE %s
           OR metadata_json LIKE %s
           OR metadata_json LIKE %s
           OR metadata_json LIKE %s
           OR metadata_json LIKE %s
           OR metadata_json LIKE %s
         )
       ORDER BY id DESC
       LIMIT %d",
      'lesson',
      '%\"mrm_sheet_music_subscription_status\":\"retry_pending_customer_or_payment_method\"%',
      '%\"mrm_sheet_music_subscription_status\":\"payment_intent_retrieve_failed\"%',
      '%\"mrm_sheet_music_subscription_status\":\"create_failed\"%',
      '%\"mrm_sheet_music_subscription_status\":\"payment_method_not_ready\"%',
      '%\"mrm_sheet_music_subscription_status\":\"retry_reopened_after_unhealthy_subscription\"%',
      '%\"mrm_sheet_music_subscription_status\":\"retry_reopened_after_stale_created_flag\"%',
      $limit
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);
    return is_array($rows) ? $rows : array();
  }

  private function mrm_attempt_sheet_music_subscription_activation($order_id, $pi = array(), $context = 'direct') {
    try {
      $order_id = (int)$order_id;
      if ($order_id <= 0) return false;

      $this->mrm_subscription_debug_log('activation helper entered', array(
        'context' => $context,
        'order_id' => $order_id,
        'pi_provided' => (!empty($pi) ? 'yes' : 'no'),
      ));

      $order = $this->get_order($order_id);
      if (!is_array($order) || empty($order)) {
        return false;
      }
      $this->mrm_subscription_debug_log('activation helper order loaded', array(
        'context' => $context,
        'order_id' => $order_id,
        'order_status' => (string)($order['status'] ?? ''),
        'product_type' => (string)($order['product_type'] ?? ''),
        'payment_intent_id' => (string)($order['stripe_payment_intent_id'] ?? ($order['payment_intent_id'] ?? '')),
      ));

      $order_meta = $this->mrm_get_order_meta_array($order);
      $product_type = (string)($order['product_type'] ?? '');

      $already_created_at = (string)$this->mrm_get_order_meta_value($order, 'mrm_sheet_music_subscription_created_at', '');
      $already_subscription_id = (string)$this->mrm_get_order_meta_value($order, 'mrm_sheet_music_subscription_id', '');

      $this->mrm_subscription_debug_log('activation helper existing subscription flags', array(
        'context' => $context,
        'order_id' => $order_id,
        'existing_created_at' => $already_created_at,
        'existing_subscription_id' => $already_subscription_id,
      ));

      if ($already_subscription_id !== '') {
        $existing_local = $this->mrm_get_sheet_music_subscription_by_stripe_id($already_subscription_id);
        $existing_status = is_array($existing_local) ? (string)($existing_local['stripe_status'] ?? '') : '';

        if (in_array($existing_status, array('trialing', 'active'), true)) {
          return true;
        }

        // If the prior subscription record exists but is no longer healthy, reopen activation.
        if ($existing_status !== '') {
          $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'retry_reopened_after_unhealthy_subscription');
          $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', 'Previous subscription record was not active/trialing; reopening activation.');
          $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_id', '');
          $already_subscription_id = '';
        }
      }

      if ($already_created_at !== '' && $already_subscription_id === '') {
        $existing_local_by_order = array();
        $email_for_lookup = sanitize_email((string)($order_meta['mrm_customer_email'] ?? ''));
        if ($email_for_lookup && is_email($email_for_lookup)) {
          $existing_local_by_order = $this->mrm_get_active_sheet_music_subscription_by_email($email_for_lookup);
        }

        if (!empty($existing_local_by_order['id'])) {
          return true;
        }

        // Created-at alone should not permanently block activation if no healthy active local subscription exists.
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'retry_reopened_after_stale_created_flag');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', 'Stale created flag found without healthy active local subscription; reopening activation.');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_created_at', '');
      }

      $order_status = (string)($order['status'] ?? '');
      if (!in_array($order_status, array('paid', 'completed', 'succeeded'), true)) {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'waiting_for_paid_order');
        return false;
      }

      $passed_pi_id = (is_array($pi) && !empty($pi)) ? (string)($pi['id'] ?? '') : '';
      $order_pi_id = (string)($order['stripe_payment_intent_id'] ?? ($order['payment_intent_id'] ?? ''));

      $this->mrm_subscription_debug_log('activation helper payment_intent source check', array(
        'context' => $context,
        'order_id' => $order_id,
        'order_payment_intent_id' => $order_pi_id,
        'passed_pi_id' => $passed_pi_id,
      ));

      $pi_id = ($passed_pi_id !== '') ? $passed_pi_id : $order_pi_id;

      if ($pi_id === '') {
        $this->mrm_subscription_debug_log('activation helper exited: missing payment_intent_id on both passed PI and order', array(
          'context' => $context,
          'order_id' => $order_id,
        ));

        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'missing_payment_intent');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', 'Missing payment_intent_id on both passed PI and order.');
        return false;
      }

      if (!is_array($pi) || empty($pi)) {
        $pi = $this->stripe_retrieve_payment_intent($pi_id);
        if (is_wp_error($pi)) {
          $this->mrm_subscription_debug_log('activation helper PI retrieve failed', array(
            'context' => $context,
            'order_id' => $order_id,
            'pi_id' => $pi_id,
            'message' => $pi->get_error_message(),
          ));

          $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'payment_intent_retrieve_failed');
          $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', $pi->get_error_message());
          return false;
        }

        $this->mrm_subscription_debug_log('activation helper PI retrieved', array(
          'context' => $context,
          'order_id' => $order_id,
          'pi_id' => $pi_id,
          'pi_status' => (string)($pi['status'] ?? ''),
          'customer' => (string)($pi['customer'] ?? ''),
          'payment_method' => (string)($pi['payment_method'] ?? ''),
        ));
      } else {
        $this->mrm_subscription_debug_log('activation helper using passed PI', array(
          'context' => $context,
          'order_id' => $order_id,
          'pi_id' => $pi_id,
          'pi_status' => (string)($pi['status'] ?? ''),
          'customer' => (string)($pi['customer'] ?? ''),
          'payment_method' => (string)($pi['payment_method'] ?? ''),
        ));
      }

      // IMPORTANT: merge PI metadata before checking whether the sheet music add-on was selected.
      $pi_meta = isset($pi['metadata']) && is_array($pi['metadata']) ? $pi['metadata'] : array();
      $meta = array_merge($order_meta, $pi_meta);

      $addon_yes = (isset($meta['mrm_sheet_music_addon']) && strtolower((string)$meta['mrm_sheet_music_addon']) === 'yes');

      $this->mrm_subscription_debug_log('activation helper add-on gate', array(
        'context' => $context,
        'order_id' => $order_id,
        'product_type' => $product_type,
        'addon_yes' => ($addon_yes ? 'yes' : 'no'),
        'order_meta_addon' => (string)($order_meta['mrm_sheet_music_addon'] ?? ''),
        'pi_meta_addon' => (string)($pi_meta['mrm_sheet_music_addon'] ?? ''),
        'customer_email' => (string)($meta['mrm_customer_email'] ?? ''),
      ));

      if (!$addon_yes || $product_type !== 'lesson') {
        $this->mrm_subscription_debug_log('activation helper exited at add-on gate', array(
          'context' => $context,
          'order_id' => $order_id,
          'product_type' => $product_type,
          'addon_yes' => ($addon_yes ? 'yes' : 'no'),
        ));
        return false;
      }

      $price_id = $this->subscription_price_id();
      $this->mrm_subscription_debug_log('activation helper price lookup', array(
        'context' => $context,
        'order_id' => $order_id,
        'price_id' => $price_id,
      ));
      if ($price_id === '') {
        $this->mrm_subscription_debug_log('activation helper exited: missing price id', array(
          'context' => $context,
          'order_id' => $order_id,
        ));
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'configuration_missing');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', 'Missing Stripe subscription Price ID.');
        return false;
      }

      $email = sanitize_email((string)($meta['mrm_customer_email'] ?? ''));
      $this->mrm_subscription_debug_log('activation helper email check', array(
        'context' => $context,
        'order_id' => $order_id,
        'email' => $email,
      ));
      if (!$email || !is_email($email)) {
        $this->mrm_subscription_debug_log('activation helper exited: invalid email', array(
          'context' => $context,
          'order_id' => $order_id,
          'email' => (string)($meta['mrm_customer_email'] ?? ''),
        ));
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'missing_email');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', 'Missing valid customer email.');
        return false;
      }

      $existing = $this->mrm_get_active_sheet_music_subscription_by_email($email);
      $this->mrm_subscription_debug_log('activation helper duplicate active lookup', array(
        'context' => $context,
        'order_id' => $order_id,
        'email' => $email,
        'existing_found' => (!empty($existing['id']) ? 'yes' : 'no'),
        'existing_subscription_id' => (string)($existing['stripe_subscription_id'] ?? ''),
        'existing_status' => (string)($existing['stripe_status'] ?? ''),
      ));
      if (!empty($existing['id'])) {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'already_active');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_id', (string)($existing['stripe_subscription_id'] ?? ''));
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', '');
        return true;
      }

      $customer_id = (string)($pi['customer'] ?? '');
      $payment_method_id = (string)($pi['payment_method'] ?? '');

      $this->mrm_subscription_debug_log('activation helper PI customer/payment method check', array(
        'context' => $context,
        'order_id' => $order_id,
        'customer_id' => $customer_id,
        'payment_method_id' => $payment_method_id,
      ));

      if ($customer_id === '' || $payment_method_id === '') {
        $this->mrm_subscription_debug_log('activation helper exited: missing customer or payment method', array(
          'context' => $context,
          'order_id' => $order_id,
          'customer_present' => ($customer_id !== '' ? 'yes' : 'no'),
          'payment_method_present' => ($payment_method_id !== '' ? 'yes' : 'no'),
        ));
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'retry_pending_customer_or_payment_method');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', 'Stripe customer/payment method is not ready yet.');
        return false;
      }

      $pm_ready = $this->mrm_ensure_customer_payment_method_ready($customer_id, $payment_method_id);
      if (is_wp_error($pm_ready)) {
        $this->mrm_subscription_debug_log('activation helper payment-method readiness failed', array(
          'context' => $context,
          'order_id' => $order_id,
          'customer_id' => $customer_id,
          'payment_method_id' => $payment_method_id,
          'message' => $pm_ready->get_error_message(),
        ));
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'payment_method_not_ready');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', $pm_ready->get_error_message());
        return false;
      }
      $this->mrm_subscription_debug_log('activation helper payment-method readiness passed', array(
        'context' => $context,
        'order_id' => $order_id,
        'customer_id' => $customer_id,
        'payment_method_id' => $payment_method_id,
      ));

      $address = array(
        'line1' => (string)($meta['mrm_tax_line1'] ?? ''),
        'state' => (string)($meta['mrm_tax_state'] ?? ''),
        'postal_code' => (string)($meta['mrm_tax_postal_code'] ?? ''),
        'country' => (string)($meta['mrm_tax_country'] ?? 'US'),
      );

      $customer_update = $this->stripe_update_customer_address(
        $customer_id,
        $email,
        $address,
        (string)($meta['mrm_student_name'] ?? '')
      );

      $address_ready_for_tax = !is_wp_error($customer_update);

      if (is_wp_error($customer_update)) {
        $this->mrm_subscription_debug_log('activation helper customer address refresh failed', array(
          'context' => $context,
          'order_id' => $order_id,
          'customer_id' => $customer_id,
          'message' => $customer_update->get_error_message(),
        ));
      } else {
        $this->mrm_subscription_debug_log('activation helper customer address refresh passed', array(
          'context' => $context,
          'order_id' => $order_id,
          'customer_id' => $customer_id,
        ));
      }

      $start_ts = !empty($pi['created']) ? (int)$pi['created'] : time();
      $billing_cycle_anchor_ts = strtotime('+1 month', $start_ts);
      if (!$billing_cycle_anchor_ts || $billing_cycle_anchor_ts <= time()) {
        $billing_cycle_anchor_ts = time() + (31 * DAY_IN_SECONDS);
      }

      $enable_subscription_tax =
        $address_ready_for_tax &&
        $this->mrm_should_enable_subscription_automatic_tax_from_meta($meta);

      $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'creating');
      $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', '');

      $this->mrm_subscription_debug_log('about to call stripe_create_subscription', array(
        'context' => $context,
        'order_id' => $order_id,
        'pi_id' => $pi_id,
        'customer_id' => $customer_id,
        'payment_method_id' => $payment_method_id,
        'email' => $email,
        'price_id' => $price_id,
        'automatic_tax_enabled' => ($enable_subscription_tax ? 'yes' : 'no'),
        'billing_cycle_anchor_ts' => $billing_cycle_anchor_ts,
        'tax_state' => (string)($meta['mrm_tax_state'] ?? ''),
        'tax_country' => (string)($meta['mrm_tax_country'] ?? ''),
      ));

      $subscription = $this->stripe_create_subscription(
        $customer_id,
        $price_id,
        $payment_method_id,
        $billing_cycle_anchor_ts,
        array(
          'mrm_customer_email' => $email,
          'mrm_source' => 'sheet_music_addon_initial_checkout',
          'mrm_source_payment_intent' => $pi_id,
          'mrm_order_id' => (string)$order_id,
          'mrm_tax_state' => (string)($meta['mrm_tax_state'] ?? ''),
          'mrm_tax_country' => (string)($meta['mrm_tax_country'] ?? 'US'),
          'mrm_tax_rollout_mode' => 'stripe_only',
          'mrm_tax_calculation_requested' => (string)($meta['mrm_tax_calculation_requested'] ?? 'no'),
        ),
        $enable_subscription_tax
      );

      $this->mrm_subscription_debug_log('subscription create response received', array(
        'context' => $context,
        'order_id' => $order_id,
        'subscription_id' => (string)($subscription['id'] ?? ''),
        'subscription_status' => (string)($subscription['status'] ?? ''),
        'default_payment_method' => (string)($subscription['default_payment_method'] ?? ''),
        'customer' => (string)($subscription['customer'] ?? ''),
        'is_wp_error' => (is_wp_error($subscription) ? 'yes' : 'no'),
        'automatic_tax_enabled' => ($enable_subscription_tax ? 'yes' : 'no'),
      ));

      if (is_wp_error($subscription)) {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'create_failed');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', $subscription->get_error_message());
        return false;
      }

      $this->mrm_sync_local_sheet_music_subscription_from_stripe($subscription, $email);

      $subscription_id = (string)($subscription['id'] ?? '');
      $subscription_status = (string)($subscription['status'] ?? '');

      if ($subscription_id !== '') {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_id', $subscription_id);
      }

      $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', ($subscription_status !== '' ? $subscription_status : 'created'));

      if (in_array($subscription_status, array('trialing', 'active'), true)) {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_created_at', current_time('mysql'));
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', '');
      } else {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', 'Subscription object was created but did not reach a healthy active/trialing state.');
      }

      $local = $this->mrm_get_sheet_music_subscription_by_stripe_id($subscription_id);
      $email_sent_at = (string)$this->mrm_get_order_meta_value($order, 'mrm_sheet_music_subscription_enrollment_email_sent_at', '');

      if (is_array($local) && !empty($local) && $email_sent_at === '') {
        $this->mrm_subscription_debug_log('attempting enrollment email from activation helper', array(
          'context' => $context,
          'order_id' => $order_id,
          'subscription_id' => $subscription_id,
          'local_status' => (string)($local['stripe_status'] ?? ''),
          'email' => (string)($local['email_plain'] ?? ''),
        ));

        $sent = $this->mrm_send_sheet_music_subscription_enrollment_email($local, $billing_cycle_anchor_ts);

        $this->mrm_subscription_debug_log('activation helper enrollment email result', array(
          'context' => $context,
          'order_id' => $order_id,
          'subscription_id' => $subscription_id,
          'sent' => ($sent ? 'yes' : 'no'),
        ));

        if ($sent) {
          $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_enrollment_email_sent_at', current_time('mysql'));
        }
      } else {
        $this->mrm_subscription_debug_log('activation helper skipped enrollment email', array(
          'context' => $context,
          'order_id' => $order_id,
          'subscription_id' => $subscription_id,
          'local_row_found' => (!empty($local) ? 'yes' : 'no'),
          'email_sent_at' => $email_sent_at,
        ));
      }

      return true;
    } catch (Throwable $e) {
      $order_id = (int)$order_id;
      if ($order_id > 0) {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'runtime_exception');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', $e->getMessage());
      }

      $this->stripe_debug_log('subscription activation helper runtime exception', array(
        'context' => $context,
        'order_id' => $order_id,
        'message' => $e->getMessage(),
      ));

      error_log('[MRM Payments Hub] Subscription activation helper runtime exception: ' . $e->getMessage());
      return false;
    }
  }

  public function cron_retry_sheet_music_subscriptions() {
    $orders = $this->mrm_get_retryable_sheet_music_subscription_orders(25);
    if (!is_array($orders) || empty($orders)) {
      return;
    }

    foreach ($orders as $order) {
      $order_id = (int)($order['id'] ?? 0);
      if ($order_id <= 0) continue;

      $this->mrm_subscription_debug_log('cron retrying subscription activation', array(
        'order_id' => $order_id,
      ));

      $this->mrm_attempt_sheet_music_subscription_activation($order_id, array(), 'cron_retry');
    }
  }

  private function mrm_sheet_music_duplicate_subscription_message() {
    return 'This email is already enrolled in the sheet music subscription. You do not need to subscribe again. If you need help, please contact us through our contact form.';
  }

  private function mrm_get_sheet_music_subscription_by_portal_token($token) {
    global $wpdb;
    $table = $this->table_sheet_music_subscriptions();

    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table} WHERE portal_token = %s LIMIT 1",
        (string)$token
      ),
      ARRAY_A
    );

    return is_array($row) ? $row : array();
  }

  private function mrm_get_sheet_music_subscription_rows_for_admin() {
    global $wpdb;

    $subs = $this->table_sheet_music_subscriptions();
    $now = current_time('mysql');

    return $wpdb->get_results(
      $wpdb->prepare(
        "SELECT
            id,
            email_hash,
            email_plain,
            stripe_status,
            cancel_at_period_end,
            current_period_start,
            current_period_end,
            canceled_at,
            created_at,
            updated_at
         FROM {$subs}
         WHERE email_plain IS NOT NULL
           AND email_plain <> ''
           AND (
             stripe_status IN ('trialing','active')
             OR (
               (cancel_at_period_end = 1 OR stripe_status = 'canceled')
               AND current_period_end IS NOT NULL
               AND current_period_end >= %s
             )
           )
         ORDER BY email_plain ASC",
        $now
      ),
      ARRAY_A
    );
  }

  private function mrm_is_sheet_music_subscription_active_for_admin($row) {
    if (!is_array($row)) return false;

    $status = (string)($row['stripe_status'] ?? '');
    $cancel_at_period_end = !empty($row['cancel_at_period_end']);

    // "Active" means still renewing, not merely paid-through.
    if ($cancel_at_period_end) {
      return false;
    }

    return in_array($status, array('active', 'trialing'), true);
  }

  private function mrm_sheet_music_subscription_end_date_for_admin($row) {
    if (!is_array($row)) return '';

    $period_end = (string)($row['current_period_end'] ?? '');
    if ($period_end === '') {
      return '';
    }

    $period_end_ts = strtotime($period_end);
    if (!$period_end_ts) {
      return '';
    }

    return date_i18n('Y-m-d g:i A', $period_end_ts);
  }

  private function mrm_get_piece_product_access_rows_for_admin($sku) {
    global $wpdb;

    $sku = $this->sanitize_product_slug((string)$sku);
    if ($sku === '' || $sku === 'all-sheet-music') {
      return array();
    }

    $table = $this->table_sheet_music_access();

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT id, email_plain, granted_at, source, source_id, revoked_at
         FROM {$table}
         WHERE sku = %s
           AND revoked_at IS NULL
         ORDER BY granted_at DESC",
        $sku
      ),
      ARRAY_A
    );

    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log('[MRM Payments Hub] Admin piece access rows fetched. sku=' . $sku . ' row_count=' . count((array)$rows));
    }

    return $rows;
  }

  private function mrm_upsert_sheet_music_subscription_row($data) {
    global $wpdb;

    $table = $this->table_sheet_music_subscriptions();
    $stripe_subscription_id = (string)($data['stripe_subscription_id'] ?? '');
    if ($stripe_subscription_id === '') return false;

    $existing = $this->mrm_get_sheet_music_subscription_by_stripe_id($stripe_subscription_id);
    $now = current_time('mysql');

    $row = array(
      'email_hash' => (string)($data['email_hash'] ?? ''),
      'email_plain' => (string)($data['email_plain'] ?? ''),
      'stripe_customer_id' => (string)($data['stripe_customer_id'] ?? ''),
      'stripe_subscription_id' => $stripe_subscription_id,
      'stripe_price_id' => (string)($data['stripe_price_id'] ?? ''),
      'stripe_status' => (string)($data['stripe_status'] ?? 'pending'),
      'current_period_start' => (string)($data['current_period_start'] ?? null),
      'current_period_end' => (string)($data['current_period_end'] ?? null),
      'cancel_at_period_end' => !empty($data['cancel_at_period_end']) ? 1 : 0,
      'canceled_at' => (string)($data['canceled_at'] ?? null),
      'latest_invoice_id' => (string)($data['latest_invoice_id'] ?? ''),
      'updated_at' => $now,
    );

    if (!empty($existing['id'])) {
      return false !== $wpdb->update(
        $table,
        $row,
        array('id' => (int)$existing['id']),
        array('%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s'),
        array('%d')
      );
    }

    $row['portal_token'] = $this->mrm_generate_subscription_portal_token();
    $row['created_at'] = $now;

    return false !== $wpdb->insert(
      $table,
      $row,
      array('%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s')
    );
  }

  private function mrm_subscription_manage_url($portal_token) {
    return home_url('/wp-json/mrm-pay/v1/subscription-portal?token=' . rawurlencode((string)$portal_token));
  }

  private function mrm_sync_local_sheet_music_subscription_from_stripe($subscription, $fallback_email = '') {
    if (!is_array($subscription)) return false;

    $customer_id = (string)($subscription['customer'] ?? '');
    $subscription_id = (string)($subscription['id'] ?? '');
    $status = (string)($subscription['status'] ?? 'pending');
    $latest_invoice_id = is_array($subscription['latest_invoice'] ?? null)
      ? (string)(($subscription['latest_invoice']['id'] ?? ''))
      : (string)($subscription['latest_invoice'] ?? '');
    $price_id = (string)($subscription['items']['data'][0]['price']['id'] ?? '');
    $period_start = !empty($subscription['current_period_start']) ? $this->mrm_mysql_from_ts((int)$subscription['current_period_start']) : null;
    $period_end = !empty($subscription['current_period_end']) ? $this->mrm_mysql_from_ts((int)$subscription['current_period_end']) : null;
    $canceled_at = !empty($subscription['canceled_at']) ? $this->mrm_mysql_from_ts((int)$subscription['canceled_at']) : null;

    $email = sanitize_email((string)($subscription['metadata']['mrm_customer_email'] ?? $fallback_email));

    if ((!$email || !is_email($email)) && !empty($subscription['customer'])) {
      $order_by_subscription = $this->get_order_by_meta_value('mrm_sheet_music_subscription_id', (string)$subscription['id']);
      if (is_array($order_by_subscription) && !empty($order_by_subscription)) {
        $order_meta_for_email = $this->mrm_get_order_meta_array($order_by_subscription);
        $candidate_email = sanitize_email((string)($order_meta_for_email['mrm_customer_email'] ?? ''));
        if ($candidate_email && is_email($candidate_email)) {
          $email = $candidate_email;
        }
      }
    }

    $email_hash = $email && is_email($email) ? $this->email_hash($email) : '';

    return $this->mrm_upsert_sheet_music_subscription_row(array(
      'email_hash' => $email_hash,
      'email_plain' => $email,
      'stripe_customer_id' => $customer_id,
      'stripe_subscription_id' => $subscription_id,
      'stripe_price_id' => $price_id,
      'stripe_status' => $status,
      'current_period_start' => $period_start,
      'current_period_end' => $period_end,
      'cancel_at_period_end' => !empty($subscription['cancel_at_period_end']),
      'canceled_at' => $canceled_at,
      'latest_invoice_id' => $latest_invoice_id,
    ));
  }

  private function mrm_sync_instructor_piece_access_master() {
    global $wpdb;

    $instructors_table = $wpdb->prefix . 'mrm_instructors';
    $access_table = $this->table_sheet_music_access();
    $master_sku = 'all-piece-products-instructors';
    $now = current_time('mysql');

    $instructor_emails = $wpdb->get_col(
      "SELECT email FROM {$instructors_table}
       WHERE email IS NOT NULL AND email <> ''"
    );

    if (!is_array($instructor_emails)) {
      $instructor_emails = array();
    }

    $instructor_emails = array_values(array_unique(array_filter(array_map('sanitize_email', $instructor_emails))));

    error_log('[MRM Payments Hub] Instructor piece-access sync running. instructor_count=' . count($instructor_emails));

    // Remove rows for emails that are no longer instructors.
    $existing_rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT id, email_plain
         FROM {$access_table}
         WHERE sku = %s
           AND revoked_at IS NULL",
        $master_sku
      ),
      ARRAY_A
    );

    foreach ((array)$existing_rows as $row) {
      $email_plain = sanitize_email((string)($row['email_plain'] ?? ''));
      if ($email_plain === '' || !in_array($email_plain, $instructor_emails, true)) {
        $wpdb->update(
          $access_table,
          array(
            'revoked_at' => $now,
          ),
          array('id' => (int)$row['id']),
          array('%s'),
          array('%d')
        );
      }
    }

    // Ensure every current instructor has a live row.
    foreach ($instructor_emails as $email) {
      $email_hash = $this->email_hash($email);
      $exists = $wpdb->get_var(
        $wpdb->prepare(
          "SELECT id
           FROM {$access_table}
           WHERE sku = %s
             AND email_hash = %s
             AND revoked_at IS NULL
           LIMIT 1",
          $master_sku,
          $email_hash
        )
      );

      if (!$exists) {
        $wpdb->insert(
          $access_table,
          array(
            'email_hash' => $email_hash,
            'email_plain' => $email,
            'sku' => $master_sku,
            'start_at' => $now,
            'expires_at' => null,
            'month_key' => null,
            'granted_at' => $now,
            'revoked_at' => null,
            'source' => 'instructor_sync',
            'source_id' => 'instructor_email',
          ),
          array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')
        );
      }
    }
  }

  private function mrm_get_instructor_contact_from_id($instructor_id) {
    global $wpdb;
    $iid = (int)$instructor_id;
    if (!$iid) return array('name'=>'','email'=>'');

    $table = $wpdb->prefix . 'mrm_instructors';
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT name,email FROM {$table} WHERE id=%d LIMIT 1",
      $iid
    ), ARRAY_A);

    if (!is_array($row)) return array('name'=>'','email'=>'');
    return array(
      'name'  => (string)($row['name'] ?? ''),
      'email' => (string)($row['email'] ?? ''),
    );
  }

  private function mrm_get_lesson_row_for_receipt($lesson_id) {
    global $wpdb;

    $lesson_id = (int)$lesson_id;
    if ($lesson_id <= 0) return array();

    $table = $wpdb->prefix . 'mrm_lessons';
    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT id, series_id, instructor_id, lesson_length, is_online, autopay_profile_id, payment_mode, start_time
         FROM {$table}
         WHERE id = %d
         LIMIT 1",
        $lesson_id
      ),
      ARRAY_A
    );

    return is_array($row) ? $row : array();
  }


  private function mrm_get_lesson_sequence_for_receipt($lesson_row) {
    global $wpdb;

    if (!is_array($lesson_row)) {
      return 0;
    }

    $lesson_id = (int)($lesson_row['id'] ?? 0);
    $series_id = (int)($lesson_row['series_id'] ?? 0);
    $start_time = (string)($lesson_row['start_time'] ?? '');

    if ($lesson_id <= 0 || $series_id <= 0 || $start_time === '') {
      return 0;
    }

    $table = $wpdb->prefix . 'mrm_lessons';

    $position = (int)$wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(*)
         FROM {$table}
         WHERE series_id = %d
           AND (
             start_time < %s
             OR (start_time = %s AND id <= %d)
           )",
        $series_id,
        $start_time,
        $start_time,
        $lesson_id
      )
    );

    return max(0, $position);
  }

  private function mrm_set_order_meta_flag($order_id, $key, $value) {
    global $wpdb;
    $order_id = (int)$order_id;
    if (!$order_id) return;

    $orders = $this->table_orders();
    $existing_json = $wpdb->get_var($wpdb->prepare(
      "SELECT metadata_json FROM {$orders} WHERE id=%d LIMIT 1",
      $order_id
    ));
    $meta = array();
    if ($existing_json) {
      $decoded = json_decode((string)$existing_json, true);
      if (is_array($decoded)) $meta = $decoded;
    }
    $meta[(string)$key] = $value;

    $wpdb->update($orders, array(
      'metadata_json' => wp_json_encode($meta),
      'updated_at'    => current_time('mysql'),
    ), array('id'=>$order_id), array('%s','%s'), array('%d'));
  }


  private function mrm_get_order_meta_array($order_row) {
    if (!is_array($order_row)) return array();

    $raw = (string)($order_row['metadata_json'] ?? '');
    if ($raw === '') return array();

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
  }

  private function mrm_get_order_meta_value($order_row, $key, $default = '') {
    $meta = $this->mrm_get_order_meta_array($order_row);
    return array_key_exists((string)$key, $meta) ? $meta[(string)$key] : $default;
  }

  private function mrm_claim_purchase_receipt_send($order_id) {
    $order_id = (int)$order_id;
    if ($order_id <= 0) return false;

    $lock_key = 'mrm_receipt_claim_order_' . $order_id;

    if (get_option($lock_key, null)) {
      return false;
    }

    $added = add_option($lock_key, current_time('mysql'), '', 'no');
    return (bool)$added;
  }

  private function mrm_release_purchase_receipt_claim($order_id) {
    $order_id = (int)$order_id;
    if ($order_id <= 0) return;

    $lock_key = 'mrm_receipt_claim_order_' . $order_id;
    delete_option($lock_key);
  }

  private function mrm_maybe_send_purchase_receipt_email($pi, $order_row) {
    if (!is_array($pi) || !is_array($order_row)) return;

    $pi_id = (string)($pi['id'] ?? '');
    $pi_meta = (isset($pi['metadata']) && is_array($pi['metadata'])) ? $pi['metadata'] : array();

    $order_meta = array();
    if (!empty($order_row['metadata_json'])) {
      $decoded = json_decode((string)$order_row['metadata_json'], true);
      if (is_array($decoded)) $order_meta = $decoded;
    }

    // Merge metadata so local order metadata can backfill fields missing from PI metadata.
    $meta = array_merge($order_meta, $pi_meta);

    // Determine customer email
    $email = sanitize_email((string)($meta['mrm_customer_email'] ?? ''));
    if (!$email || !is_email($email)) {
      $email_hash = (string)($order_row['email_hash'] ?? '');
      if ($email_hash) {
        $decoded = $this->decode_email_hash($email_hash);
        if ($decoded) $email = $decoded;
      }
    }
    if (!$email || !is_email($email)) return;

    // Idempotency + race-condition lock
    $existing_meta = array();
    if (!empty($order_row['metadata_json'])) {
      $decoded = json_decode((string)$order_row['metadata_json'], true);
      if (is_array($decoded)) $existing_meta = $decoded;
    }
    if (!empty($existing_meta['mrm_receipt_sent_at'])) {
      return;
    }

    $order_id_for_claim = (int)($order_row['id'] ?? 0);
    if ($order_id_for_claim <= 0) {
      return;
    }

    if (!$this->mrm_claim_purchase_receipt_send($order_id_for_claim)) {
      return;
    }

    $sku = (string)($meta['mrm_sku'] ?? ($order_row['sku'] ?? ''));
    $product_type = (string)($meta['mrm_product_type'] ?? ($order_row['product_type'] ?? 'unknown'));

    $p = $sku ? $this->get_product($sku) : null;
    $label = (is_array($p) && !empty($p['label'])) ? (string)$p['label'] : ($sku ?: 'Purchase');

    $amount_cents = 0;
    if (isset($pi['amount_received'])) $amount_cents = (int)$pi['amount_received'];
    elseif (isset($pi['amount'])) $amount_cents = (int)$pi['amount'];
    else $amount_cents = (int)($order_row['amount_cents'] ?? 0);

    $base_cents  = (int)($meta['mrm_base_amount_cents'] ?? 0);
    $addon_cents = (int)($meta['mrm_addon_amount_cents'] ?? 0);
    $tax_cents   = (int)($meta['mrm_addon_tax_cents'] ?? 0);

    $fmt_money = function($c){ return '$' . number_format(((int)$c)/100, 2); };

    $title = 'Thank you for your purchase!';
    $intro = '<p>We’ve received your payment successfully.</p>';

    $details = '';
    $details .= '<div><strong>Item:</strong> ' . esc_html($label) . '</div>';
    if ($sku && $product_type !== 'lesson') {
      $details .= '<div><strong>SKU:</strong> ' . esc_html($sku) . '</div>';
    }
    if (!empty($order_row['id'])) {
      $details .= '<div><strong>Order #:</strong> ' . esc_html((string)$order_row['id']) . '</div>';
    }
    if ($pi_id) {
      $details .= '<div><strong>Payment ID:</strong> ' . esc_html($pi_id) . '</div>';
    }

    if ($product_type === 'lesson') {
      $lesson_amount_cents = ($base_cents > 0) ? $base_cents : $amount_cents;
      $details .= '<div style="margin-top:10px;"><strong>Lesson payment:</strong> ' . $fmt_money($lesson_amount_cents) . '</div>';
    } else {
      if ($base_cents > 0) {
        $details .= '<div style="margin-top:10px;"><strong>Base:</strong> ' . $fmt_money($base_cents) . '</div>';
        if ($addon_cents > 0) $details .= '<div><strong>Sheet music add-on:</strong> ' . $fmt_money($addon_cents) . '</div>';
        if ($tax_cents > 0) $details .= '<div><strong>Add-on tax:</strong> ' . $fmt_money($tax_cents) . '</div>';
        $details .= '<div><strong>Total:</strong> ' . $fmt_money($amount_cents) . '</div>';
      } else {
        $details .= '<div style="margin-top:10px;"><strong>Total:</strong> ' . $fmt_money($amount_cents) . '</div>';
      }
    }

    if ($product_type === 'lesson') {
      $lesson_id = (int)($meta['mrm_lesson_id'] ?? 0);
      $lesson_row = $this->mrm_get_lesson_row_for_receipt($lesson_id);

      $autopay_profile_id = (int)($meta['mrm_autopay_profile_id'] ?? 0);
      if ($autopay_profile_id <= 0) {
        $autopay_profile_id = (int)($lesson_row['autopay_profile_id'] ?? 0);
      }
      $autopay_profile = $autopay_profile_id > 0 ? $this->mrm_get_autopay_profile($autopay_profile_id) : array();

      $iid = (int)($meta['mrm_instructor_id'] ?? 0);
      if ($iid <= 0) {
        $iid = (int)($lesson_row['instructor_id'] ?? 0);
      }
      $instructor = $iid ? $this->mrm_get_instructor_contact_from_id($iid) : array('name'=>'','email'=>'');

      $len = trim((string)($meta['mrm_lesson_length'] ?? ''));
      if ($len === '') {
        $len = (string)($lesson_row['lesson_length'] ?? '');
      }

      $raw_mode = trim((string)($meta['mrm_lesson_mode'] ?? ''));
      if ($raw_mode === '') {
        $is_online = (int)($lesson_row['is_online'] ?? 0);
        $raw_mode = $is_online ? 'Online' : 'In Person';
      }

      $mode_lower = strtolower($raw_mode);
      if (in_array($mode_lower, array('online', 'virtual'), true)) {
        $mode_label = 'Online';
      } elseif (in_array($mode_lower, array('in person', 'in-person', 'inperson'), true)) {
        $mode_label = 'In Person';
      } else {
        $mode_label = ucwords(trim($raw_mode));
      }

      $lesson_count_raw = trim((string)($meta['mrm_lesson_count'] ?? ''));
      $prepay = trim((string)($meta['mrm_prepay'] ?? ''));
      $autopay = trim((string)($meta['mrm_autopay'] ?? ''));
      $repeat_duration = trim((string)($meta['mrm_repeat_duration'] ?? ''));
      $plan_kind = trim((string)($meta['mrm_plan_kind'] ?? ''));
      if ($plan_kind === '' && is_array($autopay_profile)) {
        $plan_kind = (string)($autopay_profile['plan_kind'] ?? '');
      }

      $authorized_lesson_count = (int)($meta['mrm_authorized_lesson_count'] ?? 0);
      if ($authorized_lesson_count <= 0 && is_array($autopay_profile)) {
        $authorized_lesson_count = (int)($autopay_profile['authorized_lesson_count'] ?? 0);
      }

      $is_autopay_followup_receipt = ($sku === 'autopay_lesson_charge');
      $is_autopay_initial_receipt = ($autopay === 'yes' && $sku !== 'autopay_lesson_charge');
      $is_autopay_receipt = ($is_autopay_initial_receipt || $is_autopay_followup_receipt);

      $lesson_sequence = 0;
      if ($is_autopay_receipt && !empty($lesson_row)) {
        $lesson_sequence = (int)$this->mrm_get_lesson_sequence_for_receipt($lesson_row);
      }

      if ($lesson_sequence <= 0 && $is_autopay_initial_receipt) {
        $lesson_sequence = 1;
      }

      $count_display = '';
      if ($is_autopay_receipt) {
        if ($repeat_duration === 'indefinitely' || $plan_kind === 'indefinite') {
          $count_display = ($lesson_sequence > 0)
            ? ($lesson_sequence . ' of Indefinite')
            : 'Indefinite';
        } elseif ($authorized_lesson_count > 0) {
          $current_display = ($lesson_sequence > 0) ? $lesson_sequence : 1;
          $count_display = $current_display . ' of ' . $authorized_lesson_count;
        } elseif ($lesson_count_raw !== '') {
          $current_display = ($lesson_sequence > 0) ? $lesson_sequence : 1;
          $count_display = $current_display . ' of ' . $lesson_count_raw;
        }
      } else {
        if ($lesson_count_raw !== '') {
          $count_display = $lesson_count_raw;
        }
      }

      $plan_display = 'Prepay';
      if ($is_autopay_receipt) {
        $plan_display = 'Auto';
      } elseif ($prepay === 'yes') {
        $plan_display = 'Prepay';
      }

      $lesson_subject_bits = array();
      if ($len !== '') $lesson_subject_bits[] = $len . '-minute';
      if ($mode_label !== '') $lesson_subject_bits[] = $mode_label;
      $lesson_subject_bits[] = 'Lesson';
      $lesson_subject_label = implode(' ', $lesson_subject_bits);

      $details .= '<div style="margin-top:12px;"><strong>Lesson details</strong></div>';
      if ($len !== '') {
        $details .= '<div>Length: ' . esc_html($len) . ' minutes</div>';
      }
      if ($mode_label !== '') {
        $details .= '<div>Mode: ' . esc_html($mode_label) . '</div>';
      }
      if ($count_display !== '') {
        $details .= '<div>Count: ' . esc_html($count_display) . '</div>';
      }
      $details .= '<div>Plan: ' . esc_html($plan_display) . '</div>';

      if ($is_autopay_initial_receipt) {
        $details .= '<div style="margin-top:12px;">This message confirms that we have received payment for your first lesson. Any sheet music subscription selected during checkout will be confirmed separately in its own email.</div>';
      }

      if ($is_autopay_followup_receipt) {
        $details .= '<div style="margin-top:12px;">This message confirms your automatic payment for this lesson. Subscription billing, if active, is confirmed separately in its own email.</div>';
      }

      $details .= '<div style="margin-top:12px;"><strong>Need changes or want to cancel? Contact your instructor.</strong></div>';

      if (!empty($instructor['name'])) {
        $details .= '<div style="margin-top:6px;">' . esc_html($instructor['name']) . '</div>';
      }
      if (!empty($instructor['email'])) {
        $details .= '<div><a href="mailto:' . esc_attr($instructor['email']) . '">' . esc_html($instructor['email']) . '</a></div>';
      }

      if ($is_autopay_receipt) {
        $label = 'AutoPay for ' . $lesson_subject_label;
      } else {
        $label = $lesson_subject_label;
      }
    }

    if ($product_type === 'sheet_music') {
      $details .= '<div style="margin-top:12px;">If you purchased sheet music, you can access it from the product page using your email and one-time password.</div>';
    }

    $contact_url = $this->mrm_get_contact_url();

    if ($product_type === 'sheet_music') {
      $details .= '<div style="margin-top:12px;"><strong>Need assistance or would like to request a refund?</strong></div>';
    } elseif ($product_type !== 'lesson') {
      $details .= '<div style="margin-top:12px;"><strong>Need changes or want to cancel?</strong></div>';
    }

    $html = $this->mrm_email_wrap_html($title, $intro, $details, $contact_url, 'Contact Support');

    $subject = 'Purchase confirmation — ' . $label;

    $headers = array(
      'Content-Type: text/html; charset=UTF-8',
      'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
    );

    $sent = wp_mail($email, $subject, $html, $headers);

    if ($sent && !empty($order_row['id'])) {
      $this->mrm_set_order_meta_flag((int)$order_row['id'], 'mrm_receipt_sent_at', current_time('mysql'));
      return;
    }

    if (!empty($order_row['id'])) {
      $this->mrm_release_purchase_receipt_claim((int)$order_row['id']);
    }
  }


  private function mrm_send_sheet_music_subscription_enrollment_email($sub_row, $billing_anchor_ts) {
    if (!is_array($sub_row)) return false;

    $email = sanitize_email((string)($sub_row['email_plain'] ?? ''));
    if (!$email || !is_email($email)) {
      $this->stripe_debug_log('subscription enrollment email aborted: invalid email', array(
        'email' => (string)($sub_row['email_plain'] ?? ''),
      ));
      return false;
    }

    $manage_url = $this->mrm_subscription_manage_url((string)($sub_row['portal_token'] ?? ''));
    $contact_url = $this->mrm_get_contact_url();
    $anchor_label = $billing_anchor_ts > 0 ? wp_date('F j, Y', (int)$billing_anchor_ts, wp_timezone()) : 'the same date next month';

    $title = 'Sheet music subscription enrolled';
    $intro = '<p>You have successfully enrolled in the sheet music subscription service.</p>';
    $status_label = (string)($sub_row['stripe_status'] ?? 'active');
    if ($status_label === '') $status_label = 'active';

    $details =
      '<div><strong>Subscription:</strong> Monthly sheet music access</div>' .
      '<div><strong>Amount:</strong> $5.00 per month</div>' .
      '<div><strong>Status:</strong> ' . esc_html(ucwords(str_replace('_', ' ', $status_label))) . '</div>' .
      '<div style="margin-top:12px;"><strong>Purchase details</strong></div>' .
      '<div>Your subscription has been created successfully in our billing system.</div>' .
      '<div style="margin-top:12px;">You will be billed again on or about <strong>' . esc_html($anchor_label) . '</strong>, and then monthly thereafter while the subscription remains active.</div>' .
      '<p style="margin-top:18px;">
       <a href="' . esc_url($manage_url) . '" style="color:#111;text-decoration:underline;">cancel subscription</a>
     </p>';

    $html = $this->mrm_email_wrap_html($title, $intro, $details, $contact_url, 'Contact Support');

    $sent = wp_mail(
      $email,
      'Subscription confirmation — Sheet music access',
      $html,
      array(
        'Content-Type: text/html; charset=UTF-8',
        'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
      )
    );

    $this->stripe_debug_log('subscription enrollment email attempt', array(
      'email' => $email,
      'subscription_id' => (string)($sub_row['stripe_subscription_id'] ?? ''),
      'status' => (string)($sub_row['status'] ?? ''),
      'sent' => ($sent ? 'yes' : 'no'),
    ));

    return $sent;
  }

  private function mrm_send_sheet_music_subscription_charge_email($sub_row, $invoice) {
    if (!is_array($sub_row)) return false;

    $email = sanitize_email((string)($sub_row['email_plain'] ?? ''));
    if (!$email || !is_email($email)) return false;

    $amount_paid = (int)($invoice['amount_paid'] ?? 0);
    $invoice_id = (string)($invoice['id'] ?? '');
    $period_end_ts = !empty($invoice['lines']['data'][0]['period']['end']) ? (int)$invoice['lines']['data'][0]['period']['end'] : 0;

    $manage_url = $this->mrm_subscription_manage_url((string)($sub_row['portal_token'] ?? ''));
    $contact_url = $this->mrm_get_contact_url();
    $next_label = $period_end_ts > 0 ? wp_date('F j, Y', $period_end_ts, wp_timezone()) : 'next month';

    $title = 'Subscription payment received';
    $intro = '<p>Your saved card has been successfully charged for your sheet music subscription.</p>';
    $details =
      '<div><strong>Subscription:</strong> Monthly sheet music access</div>' .
      '<div><strong>Amount charged:</strong> $' . number_format($amount_paid / 100, 2) . '</div>' .
      '<div><strong>Invoice ID:</strong> ' . esc_html($invoice_id) . '</div>' .
      '<div style="margin-top:12px;"><strong>Purchase details</strong></div>' .
      '<div>Your sheet music subscription remains active.</div>' .
      '<div style="margin-top:12px;">Your next monthly billing date will be on or about <strong>' . esc_html($next_label) . '</strong>.</div>' .
      '<p style="margin-top:18px;">
       <a href="' . esc_url($manage_url) . '" style="color:#111;text-decoration:underline;">cancel subscription</a>
     </p>';

    $html = $this->mrm_email_wrap_html($title, $intro, $details, $contact_url, 'Contact Support');

    return wp_mail(
      $email,
      'Subscription payment confirmation — Sheet music access',
      $html,
      array(
        'Content-Type: text/html; charset=UTF-8',
        'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
      )
    );
  }

  /* =========================================================
   * Labeling policy (Stripe metadata)
   * ======================================================= */


  private function mrm_send_autopay_lesson_charge_email($lesson, $order = array()) {
    if (!is_array($lesson)) return false;

    $email = sanitize_email((string)($lesson['student_email'] ?? ''));
    if (!$email || !is_email($email)) return false;

    $amount_cents = (int)($order['amount_cents'] ?? 0);
    $lesson_start = (string)($lesson['start_time'] ?? '');
    $lesson_length = (int)($lesson['lesson_length'] ?? 0);
    $mode_label = !empty($lesson['is_online']) ? 'Online' : 'In person';

    $lesson_label = $lesson_start !== ''
      ? wp_date('F j, Y \a\t g:i A', strtotime($lesson_start), wp_timezone())
      : 'your scheduled lesson';

    $contact_url = $this->mrm_get_contact_url();

    $title = 'Lesson payment received';
    $intro = '<p>Your saved card has been successfully charged for your lesson.</p>';
    $details =
      '<div><strong>Lesson date:</strong> ' . esc_html($lesson_label) . '</div>' .
      '<div><strong>Lesson format:</strong> ' . esc_html($mode_label) . '</div>' .
      '<div><strong>Lesson length:</strong> ' . esc_html($lesson_length) . ' minutes</div>' .
      '<div><strong>Amount charged:</strong> $' . number_format($amount_cents / 100, 2) . '</div>' .
      '<div style="margin-top:12px;"><strong>Purchase details</strong></div>' .
      '<div>Your lesson payment has been processed successfully.</div>';

    $html = $this->mrm_email_wrap_html($title, $intro, $details, $contact_url, 'Contact Support');

    return wp_mail(
      $email,
      'Payment confirmation — Lesson charge',
      $html,
      array(
        'Content-Type: text/html; charset=UTF-8',
        'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
      )
    );
  }

  private function mrm_send_sheet_music_subscription_cancelled_email($sub_row, $subscription) {
    if (!is_array($sub_row)) return false;

    $email = sanitize_email((string)($sub_row['email_plain'] ?? ''));
    if (!$email || !is_email($email)) return false;

    $ended_at = !empty($subscription['ended_at']) ? (int)$subscription['ended_at'] : 0;
    $ended_label = $ended_at > 0 ? wp_date('F j, Y', $ended_at, wp_timezone()) : 'today';
    $contact_url = $this->mrm_get_contact_url();

    $title = 'Subscription cancelled';
    $intro = '<p>Your sheet music subscription has been cancelled.</p>';
    $details =
      '<div><strong>Subscription:</strong> Monthly sheet music access</div>' .
      '<div><strong>Status:</strong> Cancelled</div>' .
      '<div><strong>Cancellation date:</strong> ' . esc_html($ended_label) . '</div>' .
      '<div style="margin-top:12px;">You will not be charged again unless you subscribe again in the future.</div>';

    $html = $this->mrm_email_wrap_html($title, $intro, $details, $contact_url, 'Contact Support');

    return wp_mail(
      $email,
      'Subscription update — Sheet music access cancelled',
      $html,
      array(
        'Content-Type: text/html; charset=UTF-8',
        'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
      )
    );
  }

  private function mrm_send_lesson_cancellation_refund_email($lesson, $refund_amount_cents = 0) {
    if (!is_array($lesson)) return false;

    $email = sanitize_email((string)($lesson['student_email'] ?? ''));
    if (!$email || !is_email($email)) return false;

    $lesson_start = (string)($lesson['start_time'] ?? '');
    $lesson_label = $lesson_start !== ''
      ? wp_date('F j, Y \a\t g:i A', strtotime($lesson_start), wp_timezone())
      : 'your scheduled lesson';

    $amount_label = $refund_amount_cents > 0
      ? '$' . number_format($refund_amount_cents / 100, 2)
      : 'your lesson payment';

    $contact_url = $this->mrm_get_contact_url();

    $title = 'Lesson cancelled and refund issued';
    $intro = '<p>Your lesson has been cancelled and a refund has been issued.</p>';
    $details =
      '<div><strong>Cancelled lesson:</strong> ' . esc_html($lesson_label) . '</div>' .
      '<div><strong>Refund amount:</strong> ' . esc_html($amount_label) . '</div>' .
      '<div style="margin-top:12px;">You can expect the refunded amount to appear back in your account in approximately 3 to 5 business days, depending on your bank and card issuer.</div>';

    $html = $this->mrm_email_wrap_html($title, $intro, $details, $contact_url, 'Contact Support');

    return wp_mail(
      $email,
      'Lesson update — Cancellation and refund issued',
      $html,
      array(
        'Content-Type: text/html; charset=UTF-8',
        'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
      )
    );
  }

  private function build_metadata($sku, $product_type, $email_hash, $context, $product_cfg) {
    $metadata = array(
      'mrm_source' => 'mrm_payments_hub',
      'mrm_product_type' => $product_type,
      'mrm_sku' => $sku,
      'mrm_email_hash' => $email_hash,
      // order id is added after order is created
    );

    if ($product_type === 'sheet_music') {
      $piece_type = sanitize_text_field((string)($context['piece_type'] ?? ''));
      $piece_slug = sanitize_text_field((string)($context['piece_slug'] ?? ''));

      if (!$piece_type || !$piece_slug) {
        if (preg_match('/^piece-([a-z0-9\-]+)-(fundamentals|trombone-euphonium|tuba|complete-package)$/', $sku, $m)) {
          $piece_slug = $piece_slug ?: $m[1];
          $piece_type = $piece_type ?: $m[2];
        }
      }

      if ($piece_type) $metadata['mrm_piece_type'] = $piece_type;
      if ($piece_slug) $metadata['mrm_piece_slug'] = $piece_slug;

      $composer_pct = $this->mrm_get_one_time_sheet_music_composer_pct();

      $metadata['mrm_split_model'] = 'pct';
      $metadata['mrm_composer_pct'] = (string)$composer_pct;
      $metadata['mrm_platform_pct'] = (string)(100 - $composer_pct);
    }

    if ($product_type === 'lesson') {
      $lesson_id = sanitize_text_field((string)($context['lesson_id'] ?? ''));
      $instructor_id = sanitize_text_field((string)($context['instructor_id'] ?? ''));
      $lesson_length = sanitize_text_field((string)($context['lesson_length'] ?? ''));
      $lesson_mode = sanitize_text_field((string)($context['lesson_mode'] ?? ''));
      $lesson_count = sanitize_text_field((string)($context['lesson_count'] ?? ''));
      $prepay = sanitize_text_field((string)($context['prepay'] ?? ''));
      $autopay = sanitize_text_field((string)($context['autopay'] ?? ''));
      $repeat_frequency = sanitize_text_field((string)($context['repeat_frequency'] ?? ''));
      $repeat_duration = sanitize_text_field((string)($context['repeat_duration'] ?? ''));
      $authorized_lesson_count = sanitize_text_field((string)($context['authorized_lesson_count'] ?? ''));

      if ($lesson_id) $metadata['mrm_lesson_id'] = $lesson_id;
      if ($instructor_id) $metadata['mrm_instructor_id'] = $instructor_id;
      if ($lesson_length) $metadata['mrm_lesson_length'] = $lesson_length;
      if ($lesson_mode) $metadata['mrm_lesson_mode'] = $lesson_mode;
      if ($lesson_count) $metadata['mrm_lesson_count'] = $lesson_count;
      if ($prepay) $metadata['mrm_prepay'] = $prepay;
      if ($autopay) $metadata['mrm_autopay'] = $autopay;
      if ($repeat_frequency) $metadata['mrm_repeat_frequency'] = $repeat_frequency;
      if ($repeat_duration) $metadata['mrm_repeat_duration'] = $repeat_duration;
      if ($authorized_lesson_count) $metadata['mrm_authorized_lesson_count'] = $authorized_lesson_count;

      // Fixed composer cut cents for your automation
      $composer_cut_cents = 0;
      if ((int)$lesson_length === 60) $composer_cut_cents = 500;
      if ((int)$lesson_length === 30) $composer_cut_cents = 250;

      $metadata['mrm_split_model'] = 'fixed';
      $metadata['mrm_composer_cut_cents'] = (string)$composer_cut_cents;
    }

    return $metadata;
  }


  private function mrm_get_instructor_row($instructor_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'mrm_instructors';
    return $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1", (int)$instructor_id),
      ARRAY_A
    );
  }

  private function mrm_build_instructor_payout_note($lesson_length, $is_online, $hire_date) {
    $lesson_length = (int)$lesson_length;
    $is_online = !empty($is_online);

    $year_bucket = $this->mrm_get_instructor_employment_year_bucket($hire_date);
    $year_label = $this->mrm_get_instructor_year_bucket_label($year_bucket);

    $base = $this->mrm_get_instructor_payout_chart_base_cents($lesson_length, $is_online, $hire_date);
    $travel = $is_online ? 0 : $this->mrm_get_in_person_travel_cents();
    $total = $base + $travel;

    if ($is_online) {
      return sprintf(
        'Instructor payout chart: Year %s, %d-minute online lesson ($%0.2f)',
        $year_label,
        $lesson_length,
        $total / 100
      );
    }

    return sprintf(
      'Instructor payout chart: Year %s, %d-minute in-person lesson ($%0.2f base + $%0.2f travel = $%0.2f)',
      $year_label,
      $lesson_length,
      $base / 100,
      $travel / 100,
      $total / 100
    );
  }

  private function stripe_retrieve_balance() {
    return $this->stripe_api_request('GET', '/v1/balance');
  }

  private function stripe_retrieve_connected_account_balance($connected_account_id) {
    return $this->stripe_api_request('GET', '/v1/balance', array(), array(
      'Stripe-Account' => (string)$connected_account_id,
    ));
  }

  /**
   * Stripe separates available funds by currency and source type (for example card vs bank_account).
   * Payout batch transfers should only use funds that are actually available right now.
   */
  private function mrm_balance_available_for_currency($balance, $currency) {
    $currency = strtolower((string)$currency);
    if (!is_array($balance) || empty($balance['available']) || !is_array($balance['available'])) {
      return array(
        'total' => 0,
        'card' => 0,
        'bank_account' => 0,
      );
    }

    foreach ($balance['available'] as $entry) {
      if (strtolower((string)($entry['currency'] ?? '')) !== $currency) {
        continue;
      }

      $source_types = isset($entry['source_types']) && is_array($entry['source_types'])
        ? $entry['source_types']
        : array();

      return array(
        'total' => (int)($entry['amount'] ?? 0),
        'card' => (int)($source_types['card'] ?? 0),
        'bank_account' => (int)($source_types['bank_account'] ?? 0),
      );
    }

    return array(
      'total' => 0,
      'card' => 0,
      'bank_account' => 0,
    );
  }

  private function mrm_group_requires_card_balance($group_rows, $charge_map) {
    foreach ((array)$group_rows as $row) {
      $order_id = (int)($row['order_id'] ?? 0);
      $source_txn = (string)($charge_map[$order_id] ?? '');
      if ($source_txn !== '') {
        return true;
      }
    }
    return false;
  }

  private function mrm_sum_group_transfer_amount($group_rows) {
    $sum = 0;
    foreach ((array)$group_rows as $row) {
      $amount = (int)($row['net_cents'] ?? 0);
      if ($amount > 0) {
        $sum += $amount;
      }
    }
    return (int)$sum;
  }

  private function mrm_mark_group_pending_balance_wait($table, $group_rows, $message) {
    global $wpdb;

    foreach ((array)$group_rows as $row) {
      $wpdb->update(
        $table,
        array(
          'status' => 'pending',
          'notes' => (string)$message,
          'updated_at' => current_time('mysql'),
        ),
        array('id' => (int)$row['id']),
        array('%s','%s','%s'),
        array('%d')
      );
    }
  }

  private function mrm_mark_group_transferred_balance_wait($table, $group_rows, $message) {
    global $wpdb;

    foreach ((array)$group_rows as $row) {
      $wpdb->update(
        $table,
        array(
          'status' => 'transferred',
          'notes' => (string)$message,
          'updated_at' => current_time('mysql'),
        ),
        array('id' => (int)$row['id']),
        array('%s','%s','%s'),
        array('%d')
      );
    }
  }

  private function stripe_create_transfer($amount_cents, $currency, $destination_account_id, $transfer_group = '', $metadata = array(), $source_transaction = '') {
    $params = array(
      'amount' => (int)$amount_cents,
      'currency' => strtolower((string)$currency),
      'destination' => (string)$destination_account_id,
    );
    if ($transfer_group !== '') $params['transfer_group'] = $transfer_group;
    if (!empty($metadata)) $params['metadata'] = $metadata;
    if ($source_transaction !== '') $params['source_transaction'] = (string)$source_transaction;
    return $this->stripe_api_request('POST', '/v1/transfers', $params);
  }

  private function stripe_create_connected_account_payout($connected_account_id, $amount_cents, $currency, $metadata = array()) {
    $params = array(
      'amount' => (int)$amount_cents,
      'currency' => strtolower((string)$currency),
    );
    if (!empty($metadata)) $params['metadata'] = $metadata;
    return $this->stripe_api_request('POST', '/v1/payouts', $params, array(
      'Stripe-Account' => (string)$connected_account_id,
    ));
  }

  private function mrm_insert_payout_ledger_row($order_id, $pi_id, $payee_type, $payee_ref, $connected_account_id, $currency, $gross_cents, $net_cents, $status = 'pending', $notes = '') {
    global $wpdb;
    $table = $this->table_payout_ledger();
    $now = current_time('mysql');
    $environment_mode = 'live';

    $existing = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$table} WHERE order_id=%d AND payee_type=%s AND payee_ref=%s LIMIT 1",
      (int)$order_id,
      (string)$payee_type,
      (string)$payee_ref
    ));
    if ($existing) return (int)$existing;

    $wpdb->insert($table, array(
      'order_id' => (int)$order_id,
      'stripe_payment_intent_id' => (string)$pi_id,
      'payee_type' => (string)$payee_type,
      'payee_ref' => (string)$payee_ref,
      'connected_account_id' => $connected_account_id ? (string)$connected_account_id : null,
      'currency' => strtolower((string)$currency),
      'environment_mode' => $environment_mode,
      'gross_cents' => (int)$gross_cents,
      'net_cents' => (int)$net_cents,
      'status' => (string)$status,
      'transfer_id' => null,
      'payout_id' => null,
      'batch_key' => null,
      'notes' => $notes !== '' ? (string)$notes : null,
      'created_at' => $now,
      'updated_at' => $now,
    ), array('%d','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%s'));

    return (int)$wpdb->insert_id;
  }


  private function mrm_recurring_subscription_payout_exists($invoice_id) {
    global $wpdb;

    $invoice_id = trim((string)$invoice_id);
    if ($invoice_id === '') return false;

    $table = $this->table_payout_ledger();

    $existing = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT id
         FROM {$table}
         WHERE order_id = %d
           AND payee_type = %s
           AND payee_ref = %s
         LIMIT 1",
        0,
        'composer',
        'stripe_subscription_invoice:' . $invoice_id
      )
    );

    return !empty($existing);
  }

  private function mrm_create_composer_payout_for_subscription_invoice($invoice, $local_subscription = array()) {
    global $wpdb;

    if (!is_array($invoice)) return false;

    $invoice_id = trim((string)($invoice['id'] ?? ''));
    $subscription_id = trim((string)($invoice['subscription'] ?? ''));
    $amount_paid = (int)($invoice['amount_paid'] ?? 0);
    $currency = strtolower(trim((string)($invoice['currency'] ?? 'usd')));

    if ($invoice_id === '' || $subscription_id === '' || $amount_paid <= 0) {
      return false;
    }

    if ($this->mrm_recurring_subscription_payout_exists($invoice_id)) {
      return true;
    }

    $connected_account_id = $this->composer_connected_account_id();
    if ($connected_account_id === '') {
      error_log('[MRM Payments Hub] Recurring subscription composer payout skipped: missing composer connected account setting. invoice_id=' . $invoice_id);

      if (method_exists($this, 'stripe_debug_log')) {
        $this->stripe_debug_log('recurring subscription composer payout skipped', array(
          'invoice_id' => $invoice_id,
          'reason' => 'missing_composer_connected_account',
        ));
      }

      return false;
    }

    $table = $this->table_payout_ledger();
    $now = current_time('mysql');

    $notes = 'Recurring sheet music subscription composer payout (100% composer share)';

    $inserted = $wpdb->insert(
      $table,
      array(
        'order_id' => 0,
        'stripe_payment_intent_id' => '',
        'payee_type' => 'composer',
        'payee_ref' => 'stripe_subscription_invoice:' . $invoice_id,
        'connected_account_id' => $connected_account_id,
        'currency' => $currency,
        'gross_cents' => $amount_paid,
        'net_cents' => $amount_paid,
        'status' => 'pending',
        'transfer_id' => null,
        'payout_id' => null,
        'batch_key' => null,
        'notes' => $notes,
        'created_at' => $now,
        'updated_at' => $now,
      ),
      array('%d','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%s','%s')
    );

    if ($inserted === false) {
      error_log('[MRM Payments Hub] Failed to insert recurring subscription payout ledger row. invoice_id=' . $invoice_id);
      return false;
    }

    if (method_exists($this, 'stripe_debug_log')) {
      $this->stripe_debug_log('recurring subscription composer payout row created', array(
        'invoice_id' => $invoice_id,
        'subscription_id' => $subscription_id,
        'connected_account_id' => $connected_account_id,
        'amount_paid' => $amount_paid,
        'currency' => $currency,
      ));
    }

    return true;
  }

  private function mrm_maybe_create_payout_ledger_for_order($order) {
    if (!$order || empty($order['id'])) return false;
    if ((string)($order['status'] ?? '') !== 'paid') return false;

    global $wpdb;
    $table = $this->table_payout_ledger();

    $already = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE order_id=%d",
      (int)$order['id']
    ));
    if ($already > 0) return true;

    $meta = array();
    if (!empty($order['metadata_json'])) {
      $decoded = json_decode((string)$order['metadata_json'], true);
      if (is_array($decoded)) $meta = $decoded;
    }

    $order_id = (int)$order['id'];
    $pi_id = (string)($order['stripe_payment_intent_id'] ?? '');
    $currency = strtolower((string)($order['currency'] ?? 'usd'));
    $product_type = (string)($order['product_type'] ?? ($meta['mrm_product_type'] ?? ''));
    $base_cents = isset($meta['mrm_base_amount_cents']) ? (int)$meta['mrm_base_amount_cents'] : (int)($order['amount_cents'] ?? 0);
    $addon_cents = isset($meta['mrm_addon_amount_cents']) ? (int)$meta['mrm_addon_amount_cents'] : 0;

    if ($product_type === 'sheet_music') {
      $composer_pct = $this->mrm_sanitize_percent_setting(
        $meta['mrm_composer_pct'] ?? $this->mrm_get_one_time_sheet_music_composer_pct(),
        $this->mrm_get_one_time_sheet_music_composer_pct()
      );

      $composer_share = (int) round($base_cents * ($composer_pct / 100));
      $platform_share = max(0, $base_cents - $composer_share) + max(0, $addon_cents);
      $composer_acct = $this->composer_connected_account_id();

      if ($composer_share > 0) {
        $this->mrm_insert_payout_ledger_row(
          $order_id,
          $pi_id,
          'composer',
          'composer',
          $composer_acct,
          $currency,
          $composer_share,
          $composer_share,
          $composer_acct ? 'pending' : 'blocked',
          $composer_acct ? 'Centralized one-time sheet music composer payout' : 'Missing composer connected account ID'
        );
      }

      $this->mrm_insert_payout_ledger_row(
        $order_id,
        $pi_id,
        'platform',
        'platform',
        '',
        $currency,
        $platform_share,
        $platform_share,
        'retained',
        'Retained by platform'
      );

      return true;
    }

    if ($product_type === 'lesson') {
      $instructor_id = (int)($meta['mrm_instructor_id'] ?? 0);
      $lesson_count = max(1, (int)($meta['mrm_lesson_count'] ?? 1));
      $email_hash = (string)($order['email_hash'] ?? '');

      if ($instructor_id > 0) {
        $this->mrm_create_or_update_credits_for_order($order_id, $instructor_id, $email_hash, $currency, $base_cents, $lesson_count);
      }

      $composer_addon_share = max(0, (int)$addon_cents);
      $composer_acct = $this->composer_connected_account_id();

      if ($composer_addon_share > 0) {
        $this->mrm_insert_payout_ledger_row(
          $order_id,
          $pi_id,
          'composer',
          'composer',
          $composer_acct,
          $currency,
          $composer_addon_share,
          $composer_addon_share,
          $composer_acct ? 'pending' : 'blocked',
          $composer_acct ? 'Add-on payout (subscription upcharge)' : 'Missing composer connected account ID'
        );
      }

      return true;
    }

    return false;
  }


  public function on_lesson_charge_due($data) {
    $mode = (string)($data['payment_mode'] ?? 'none');
    if ($mode !== 'autopay') return;
    $this->charge_and_unlock_autopay($data);
  }

  private function mrm_finalize_autopay_lesson_success($lesson_id, $order = array(), $pi_id = '') {
    global $wpdb;

    $lesson_id = (int)$lesson_id;
    if ($lesson_id <= 0) return;

    $lessons_table = $this->table_lessons();
    $lesson = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$lessons_table} WHERE id=%d LIMIT 1",
      $lesson_id
    ), ARRAY_A);

    if (!$lesson) return;

    if ((string)($lesson['charge_status'] ?? '') === 'paid' && (string)($lesson['status'] ?? '') === 'delivered') {
      return;
    }

    $now = current_time('mysql');
    $wpdb->update(
      $lessons_table,
      array(
        'status' => 'delivered',
        'charge_status' => 'paid',
        'payout_unlocked_at' => $now,
        'delivered_at' => !empty($lesson['delivered_at']) ? (string)$lesson['delivered_at'] : $now,
        'charge_last_error' => null,
        'updated_at' => $now,
      ),
      array('id' => $lesson_id),
      array('%s','%s','%s','%s','%s','%s'),
      array('%d')
    );

    $autopay_profile_id = (int)($lesson['autopay_profile_id'] ?? 0);
    if ($autopay_profile_id > 0) {
      $this->mrm_increment_autopay_charge_count($autopay_profile_id);
      $this->mrm_maybe_deactivate_and_detach_autopay($autopay_profile_id, false);
    }

    if (!is_array($order) || empty($order['id'])) {
      return;
    }

    $order_id = (int)$order['id'];
    $amount_cents = (int)($order['amount_cents'] ?? 0);
    $currency = (string)($order['currency'] ?? 'usd');
    $instructor_id = (int)($lesson['instructor_id'] ?? 0);

    $this->link_order($order_id, 'lesson', (string)$lesson_id);

    $lesson_length = (int)($lesson['lesson_length'] ?? 0);
    $is_online = !empty($lesson['is_online']);

    $instr = $this->mrm_get_instructor_row($instructor_id);
    $instructor_acct = (string)($instr['stripe_connected_account_id'] ?? '');
    $hire_date = (string)($instr['hire_date'] ?? '');

    $instructor_share = $this->mrm_get_instructor_payout_cents_for_instructor(
      $lesson_length,
      $is_online,
      $hire_date
    );

    if ($instructor_share > 0) {
      $this->mrm_insert_payout_ledger_row(
        $order_id,
        $pi_id,
        'instructor',
        'lesson:' . $lesson_id,
        $instructor_acct,
        $currency,
        $instructor_share,
        $instructor_share,
        $instructor_acct ? 'pending' : 'blocked',
        $instructor_acct ? $this->mrm_build_instructor_payout_note($lesson_length, $is_online, $hire_date) : 'Missing instructor connected account ID'
      );
    }

    $platform_share = max(0, $amount_cents - $instructor_share);
    $this->mrm_insert_payout_ledger_row(
      $order_id,
      $pi_id,
      'platform',
      'platform',
      '',
      $currency,
      $platform_share,
      $platform_share,
      'retained',
      'Retained by platform after fixed instructor payout'
    );
    $email_sent = $this->mrm_send_autopay_lesson_charge_email($lesson, $order);

    if (method_exists($this, 'stripe_debug_log')) {
      $this->stripe_debug_log('autopay lesson charge email result', array(
        'lesson_id' => $lesson_id,
        'order_id' => $order_id,
        'email' => (string)($lesson['student_email'] ?? ''),
        'sent' => ($email_sent ? 'yes' : 'no'),
      ));
    }
  }

  private function mrm_finalize_autopay_lesson_failure($lesson_id, $message = '') {
    global $wpdb;

    $lesson_id = (int)$lesson_id;
    if ($lesson_id <= 0) return;

    $lessons_table = $this->table_lessons();
    $wpdb->update(
      $lessons_table,
      array(
        'status' => 'payment_due',
        'charge_status' => 'failed',
        'charge_last_error' => (string)$message,
        'updated_at' => current_time('mysql'),
      ),
      array('id' => $lesson_id),
      array('%s','%s','%s','%s'),
      array('%d')
    );

    error_log('[MRM AutoPay] webhook finalized failed lesson charge'
      . ' lesson_id=' . $lesson_id
      . ' message=' . (string)$message
    );
  }

  private function mrm_reconcile_autopay_existing_order($lesson_id, $order) {
    $lesson_id = (int)$lesson_id;
    if ($lesson_id <= 0 || !is_array($order)) {
      return false;
    }

    $pi_id = (string)($order['stripe_payment_intent_id'] ?? '');
    if ($pi_id === '') {
      return false;
    }

    $pi = $this->stripe_retrieve_payment_intent($pi_id);
    if (is_wp_error($pi)) {
      error_log('[MRM AutoPay] reconcile failed to retrieve PI'
        . ' lesson_id=' . $lesson_id
        . ' pi_id=' . $pi_id
        . ' error=' . $pi->get_error_message()
      );
      return false;
    }

    $pi_status = (string)($pi['status'] ?? '');
    $metadata = isset($pi['metadata']) && is_array($pi['metadata']) ? $pi['metadata'] : array();

    if ($lesson_id > 0 && empty($metadata['mrm_lesson_id'])) {
      $metadata['mrm_lesson_id'] = (string)$lesson_id;
    }
    if (!empty($pi['latest_charge'])) {
      $metadata['mrm_latest_charge_id'] = (string)$pi['latest_charge'];
    }

    if (in_array($pi_status, array('succeeded', 'requires_capture'), true)) {
      $this->update_order_status_from_pi($pi_id, 'paid', $pi_status, $metadata);
      $fresh_order = $this->get_order_by_pi($pi_id);
      $this->mrm_finalize_autopay_lesson_success(
        $lesson_id,
        (is_array($fresh_order) ? $fresh_order : $order),
        $pi_id
      );
      return true;
    }

    if (in_array($pi_status, array('processing', 'requires_confirmation'), true)) {
      $this->update_order_status_from_pi($pi_id, 'processing', $pi_status, $metadata);
      return true;
    }

    if (in_array($pi_status, array('requires_payment_method', 'requires_action', 'canceled'), true)) {
      $this->update_order_status_from_pi($pi_id, 'failed', $pi_status, $metadata);
      $this->mrm_finalize_autopay_lesson_failure(
        $lesson_id,
        'Autopay reconcile status: ' . $pi_status
      );
      return true;
    }

    error_log('[MRM AutoPay] reconcile encountered unhandled PI status'
      . ' lesson_id=' . $lesson_id
      . ' pi_id=' . $pi_id
      . ' status=' . $pi_status
    );

    return false;
  }


  public function on_lesson_delivered($data) {
    $lesson_id = (int)($data['lesson_id'] ?? 0);
    $mode = (string)($data['payment_mode'] ?? 'none');
    $instructor_id = (int)($data['instructor_id'] ?? 0);
    $autopay_profile_id = (int)($data['autopay_profile_id'] ?? 0);

    error_log('[MRM AutoPay] on_lesson_delivered fired'
      . ' lesson_id=' . $lesson_id
      . ' instructor_id=' . $instructor_id
      . ' payment_mode=' . $mode
      . ' autopay_profile_id=' . $autopay_profile_id
    );

    if ($lesson_id <= 0 || $instructor_id <= 0) {
      error_log('[MRM AutoPay] on_lesson_delivered aborted: missing lesson_id or instructor_id.');
      return;
    }

    if ($mode === 'prepay' || $mode === 'one_time') {
      $this->unlock_prepay_instructor_payout($data);
      return;
    }

    if ($mode === 'autopay') {
      error_log('[MRM AutoPay] autopay lesson delivery acknowledged; waiting for charge-due / webhook flow.');
      return;
    }
  }

  public function cron_retry_autopay_charges() {
  global $wpdb;

  $lessons_table = $this->table_lessons();

  $rows = $wpdb->get_results("
    SELECT id, instructor_id, student_email, lesson_length, is_online, order_id, payment_mode, autopay_profile_id, end_time, status, charge_status, charge_attempts, charge_last_attempt_at
    FROM {$lessons_table}
    WHERE payment_mode = 'autopay'
      AND COALESCE(order_id, 0) = 0
      AND autopay_profile_id IS NOT NULL
      AND autopay_profile_id > 0
      AND status = 'payment_due'
      AND (
        charge_status = 'due'
        OR charge_status = 'failed'
        OR (
          charge_status = 'processing'
          AND (
            charge_last_attempt_at IS NULL
            OR charge_last_attempt_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE)
          )
        )
      )
      AND charge_attempts < 12
    ORDER BY charge_due_at ASC, end_time ASC
    LIMIT 25
  ", ARRAY_A);

  if (!$rows) {
    return;
  }

  foreach ($rows as $row) {
    $lesson_id = (int)($row['id'] ?? 0);
    if ($lesson_id <= 0) {
      continue;
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log(
        '[MRM AutoPay] retry_candidate lesson_id=' . $lesson_id .
        ' status=' . (string)($row['status'] ?? '') .
        ' charge_status=' . (string)($row['charge_status'] ?? '') .
        ' charge_attempts=' . (int)($row['charge_attempts'] ?? 0) .
        ' autopay_profile_id=' . (int)($row['autopay_profile_id'] ?? 0)
      );
    }

    $existing_order = $this->mrm_find_lesson_charge_order($lesson_id);
    if ($existing_order) {
      $existing_status = (string)($existing_order['status'] ?? '');
      $existing_pi_id = (string)($existing_order['stripe_payment_intent_id'] ?? '');

      if ($existing_pi_id !== '') {
        $this->mrm_reconcile_autopay_existing_order($lesson_id, $existing_order);
        $existing_order = $this->mrm_find_lesson_charge_order($lesson_id);
        $existing_status = (string)($existing_order['status'] ?? '');
      }

      if (in_array($existing_status, array('paid', 'completed', 'succeeded'), true)) {
        $this->mrm_finalize_autopay_lesson_success(
          $lesson_id,
          $existing_order,
          (string)($existing_order['stripe_payment_intent_id'] ?? '')
        );
        continue;
      }

      if (in_array($existing_status, array('pending', 'processing', 'requires_capture'), true)) {
        continue;
      }
    }

    $google_truth = $this->mrm_google_truth_confirms_lesson_ended($lesson_id);
    if (is_wp_error($google_truth)) {
      $google_error = $google_truth->get_error_code() . ': ' . $google_truth->get_error_message();

      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MRM AutoPay] retry_skip_google_truth lesson_id=' . $lesson_id . ' error=' . $google_error);
      }

      $wpdb->update(
        $lessons_table,
        array(
          'charge_last_error' => substr('Google truth blocked charge: ' . $google_error, 0, 65535),
          'updated_at' => current_time('mysql'),
        ),
        array('id' => $lesson_id),
        array('%s','%s'),
        array('%d')
      );

      continue;
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log(
        '[MRM AutoPay] retry_charge_ready lesson_id=' . $lesson_id .
        ' end_utc=' . (string)($google_truth['end_utc'] ?? '')
      );
    }

    $this->charge_and_unlock_autopay(array(
      'lesson_id' => $lesson_id,
      'instructor_id' => (int)($row['instructor_id'] ?? 0),
      'student_email' => (string)($row['student_email'] ?? ''),
      'lesson_length' => (int)($row['lesson_length'] ?? 0),
      'is_online' => (int)($row['is_online'] ?? 0),
      'order_id' => (int)($row['order_id'] ?? 0),
      'payment_mode' => (string)($row['payment_mode'] ?? 'autopay'),
      'autopay_profile_id' => (int)($row['autopay_profile_id'] ?? 0),
    ));
  }
}


  public function cron_discover_missed_autopay_lessons() {
    global $wpdb;

    $lessons_table = $this->table_lessons();
    $rows = $wpdb->get_results("
    SELECT id, instructor_id, student_email, lesson_length, is_online, order_id, payment_mode, autopay_profile_id, status
    FROM {$lessons_table}
    WHERE payment_mode = 'autopay'
      AND COALESCE(order_id, 0) = 0
      AND autopay_profile_id IS NOT NULL
      AND autopay_profile_id > 0
      AND payout_unlocked_at IS NULL
      AND status IN ('scheduled', 'payment_due', 'delivered')
    ORDER BY start_time ASC
    LIMIT 100
  ", ARRAY_A);

    if (!$rows) {
      return;
    }

    foreach ($rows as $row) {
      $lesson_id = (int)($row['id'] ?? 0);
      if ($lesson_id <= 0) {
        continue;
      }

      $google_truth = $this->mrm_google_truth_confirms_lesson_ended($lesson_id);
      if (is_wp_error($google_truth)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
          error_log(
            '[MRM AutoPay] discover_skip_google_truth lesson_id=' . $lesson_id .
            ' error=' . $google_truth->get_error_code() . ': ' . $google_truth->get_error_message()
          );
        }
        continue;
      }

      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(
          '[MRM AutoPay] discover_google_truth_ready lesson_id=' . $lesson_id .
          ' end_utc=' . (string)($google_truth['end_utc'] ?? '')
        );
      }

      $status = (string)($row['status'] ?? '');
      if ($status !== 'payment_due') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
          error_log(
            '[MRM AutoPay] discover_promote_to_payment_due lesson_id=' . $lesson_id .
            ' old_status=' . $status
          );
        }

        $wpdb->update(
          $lessons_table,
          array(
            'status' => 'payment_due',
            'charge_due_at' => current_time('mysql'),
            'charge_status' => 'due',
            'updated_at' => current_time('mysql'),
          ),
          array('id' => $lesson_id),
          array('%s','%s','%s','%s'),
          array('%d')
        );
      }

      $this->charge_and_unlock_autopay(array(
        'lesson_id' => $lesson_id,
        'instructor_id' => (int)($row['instructor_id'] ?? 0),
        'student_email' => (string)($row['student_email'] ?? ''),
        'lesson_length' => (int)($row['lesson_length'] ?? 0),
        'is_online' => (int)($row['is_online'] ?? 0),
        'order_id' => (int)($row['order_id'] ?? 0),
        'payment_mode' => (string)($row['payment_mode'] ?? 'autopay'),
        'autopay_profile_id' => (int)($row['autopay_profile_id'] ?? 0),
      ));
    }
  }

  public function cron_reset_stuck_autopay_lessons() {
    global $wpdb;

    $lessons_table = $this->table_lessons();

    $rows = $wpdb->get_results("
      SELECT id, charge_attempts, charge_last_error
      FROM {$lessons_table}
      WHERE payment_mode = 'autopay'
        AND COALESCE(order_id, 0) = 0
        AND status = 'payment_due'
        AND charge_status = 'failed'
        AND charge_attempts >= 12
        AND payout_unlocked_at IS NULL
      ORDER BY updated_at ASC
      LIMIT 100
    ", ARRAY_A);

    if (!$rows) {
      return;
    }

    foreach ($rows as $row) {
      $lesson_id = (int)($row['id'] ?? 0);
      if ($lesson_id <= 0) {
        continue;
      }

      // Only reset lessons that Google still confirms have actually ended.
      $google_truth = $this->mrm_google_truth_confirms_lesson_ended($lesson_id);
      if (is_wp_error($google_truth)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
          error_log(
            '[MRM AutoPay] reset_skip_google_truth lesson_id=' . $lesson_id .
            ' error=' . $google_truth->get_error_code() . ': ' . $google_truth->get_error_message()
          );
        }
        continue;
      }

      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(
          '[MRM AutoPay] reset_google_truth_ready lesson_id=' . $lesson_id .
          ' end_utc=' . (string)($google_truth['end_utc'] ?? '')
        );
      }

      $prev_error = trim((string)($row['charge_last_error'] ?? ''));
      $new_error = trim($prev_error . ' | Auto-reset after retry cap; retry counter cleared for a fresh pass.');

      $wpdb->update(
        $lessons_table,
        array(
          'charge_status' => 'due',
          'charge_attempts' => 0,
          'charge_last_attempt_at' => null,
          'charge_due_at' => current_time('mysql'),
          'charge_last_error' => $new_error,
          'updated_at' => current_time('mysql'),
        ),
        array('id' => $lesson_id),
        array('%s','%d','%s','%s','%s','%s'),
        array('%d')
      );
    }
  }

  private function mrm_is_lesson_refund_locked($lesson) {
    if (!is_array($lesson) || empty($lesson)) {
      return false;
    }

    return ((string)($lesson['status'] ?? '') === 'finalized');
  }

  public function on_lesson_cancelled($data) {
    global $wpdb;

    $lesson_id = (int)($data['lesson_id'] ?? 0);
    $payment_mode = (string)($data['payment_mode'] ?? 'none');
    $order_id = (int)($data['order_id'] ?? 0);
    $autopay_profile_id = (int)($data['autopay_profile_id'] ?? 0);
    $series_id = (int)($data['series_id'] ?? 0);

    if ($lesson_id <= 0) return;

    $lessons_table = $this->table_lessons();
    $lesson = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$lessons_table} WHERE id=%d LIMIT 1",
      $lesson_id
    ), ARRAY_A);

    if ($this->mrm_is_lesson_refund_locked($lesson)) {
      $this->mrm_finalization_debug_log('refund_and_cancellation_email_blocked_finalized_lesson', array(
        'lesson_id' => $lesson_id,
        'status' => (string)($lesson['status'] ?? ''),
        'delivered_at' => (string)($lesson['delivered_at'] ?? ''),
      ));
      return;
    }

    if ($payment_mode === 'autopay') {
      $lesson_order = $this->mrm_find_lesson_charge_order($lesson_id);
      $cancel_reason = (string)($data['cancel_reason'] ?? '');

      // Refund only if this specific lesson already has its own completed lesson-level charge.
      if ($lesson_order) {
        $refunded = $this->mrm_request_refund_for_order(
          $lesson_order,
          'Auto-refund for cancelled autopay lesson ' . $lesson_id
        );

        if ($refunded && is_array($lesson)) {
          $this->mrm_send_lesson_cancellation_refund_email(
            $lesson,
            (int)($lesson_order['amount_cents'] ?? 0)
          );
        }

        if (!$refunded && defined('WP_DEBUG') && WP_DEBUG) {
          error_log(
            '[MRM Payments Hub] Auto-refund not issued for cancelled autopay lesson. lesson_id=' . $lesson_id .
            ' order_id=' . (int)($lesson_order['id'] ?? 0)
          );
        }
      } elseif ($order_id > 0) {
        // Fallback for the prepaid first autopay lesson.
        // Make this stricter for recurring lessons: only refund on explicit, validated deletion/cancellation.
        $explicit_cancel_reasons = array(
          'google_event_cancelled',
          'google_event_deleted_or_series_removed',
        );

        $is_recurring = !empty($data['series_id']) && (int)$data['series_id'] > 0;

        if (
          in_array($cancel_reason, $explicit_cancel_reasons, true) &&
          (
            !$is_recurring ||
            $cancel_reason === 'google_event_cancelled'
          )
        ) {
          $first_order = $this->mrm_get_order_by_id($order_id);
          if ($first_order) {
            $refunded = $this->mrm_request_refund_for_order(
              $first_order,
              'Auto-refund for cancelled prepaid first autopay lesson ' . $lesson_id
            );

            if ($refunded && is_array($lesson)) {
              $this->mrm_send_lesson_cancellation_refund_email(
                $lesson,
                (int)($first_order['amount_cents'] ?? 0)
              );
            }

            if (!$refunded && defined('WP_DEBUG') && WP_DEBUG) {
              error_log(
                '[MRM Payments Hub] Auto-refund not issued for cancelled prepaid first autopay lesson. lesson_id=' . $lesson_id .
                ' order_id=' . (int)($first_order['id'] ?? 0)
              );
            }
          }
        }
      }

      if ($autopay_profile_id > 0) {
        $this->mrm_maybe_deactivate_and_detach_autopay($autopay_profile_id, false);
      }
      return;
    }

    // One-time lessons: refund the original booking order if it was paid.
    if ($payment_mode === 'one_time' && $order_id > 0) {
      $order = $this->mrm_get_order_by_id($order_id);
      if ($order) {
        $refunded = $this->mrm_request_refund_for_order(
          $order,
          'Auto-refund for cancelled one-time lesson ' . $lesson_id
        );

        if ($refunded && is_array($lesson)) {
          $this->mrm_send_lesson_cancellation_refund_email(
            $lesson,
            (int)($order['amount_cents'] ?? 0)
          );
        }

        if (!$refunded && defined('WP_DEBUG') && WP_DEBUG) {
          error_log(
            '[MRM Payments Hub] Auto-refund not issued for cancelled one-time lesson. lesson_id=' . $lesson_id .
            ' order_id=' . (int)($order['id'] ?? 0)
          );
        }
      }

      // If this one-time lesson is actually the prepaid first lesson in a recurring autopay series,
      // also deactivate the autopay profile so the rest of the series cannot keep charging.
      if ($series_id > 0 && $autopay_profile_id > 0) {
        $this->mrm_maybe_deactivate_and_detach_autopay($autopay_profile_id, false);
      }

      return;
    }

    // Prepay bundles: refund only the canceled prepaid lesson's share, not the full order.
    if ($payment_mode === 'prepay' && $order_id > 0) {
      $order = $this->mrm_get_order_by_id($order_id);
      if ($order) {
        $per_lesson_refund_cents = $this->mrm_calculate_prepay_per_lesson_refund_cents($order);

        error_log(
          '[MRM Payments Hub] Prepay lesson cancellation refund evaluation. lesson_id=' . $lesson_id .
          ' order_id=' . (int)($order['id'] ?? 0) .
          ' per_lesson_refund_cents=' . $per_lesson_refund_cents
        );

        if ($per_lesson_refund_cents > 0) {
          $refunded = $this->mrm_request_partial_refund_for_order(
            $order,
            $per_lesson_refund_cents,
            'Partial refund for cancelled prepaid lesson ' . $lesson_id
          );

          if ($refunded && is_array($lesson)) {
            $this->mrm_send_lesson_cancellation_refund_email(
              $lesson,
              (int)$per_lesson_refund_cents
            );
          }

          if (!$refunded && defined('WP_DEBUG') && WP_DEBUG) {
            error_log(
              '[MRM Payments Hub] Partial refund not issued for cancelled prepaid lesson. lesson_id=' . $lesson_id .
              ' order_id=' . (int)($order['id'] ?? 0) .
              ' amount_cents=' . $per_lesson_refund_cents
            );
          }
        }
      }
      return;
    }
  }

  private function unlock_prepay_instructor_payout($data) {
    global $wpdb;

    $order_id = (int)($data['order_id'] ?? 0);
    $instructor_id = (int)($data['instructor_id'] ?? 0);
    $lesson_id = (int)($data['lesson_id'] ?? 0);
    if ($order_id <= 0 || $instructor_id <= 0 || $lesson_id <= 0) return;

    $credits_table = $this->table_lesson_credits();
    $credit = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$credits_table} WHERE order_id=%d AND instructor_id=%d LIMIT 1",
      $order_id,
      $instructor_id
    ), ARRAY_A);

    if (!$credit) {
      $this->mrm_insert_payout_ledger_row($order_id, '', 'instructor', 'lesson:' . $lesson_id, '', 'usd', 0, 0, 'blocked', 'Missing prepay credit row for delivered lesson');
      return;
    }

    if ((int)$credit['remaining_credits'] <= 0) return;

    $lessons_table = $this->table_lessons();
    $lesson = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$lessons_table} WHERE id=%d LIMIT 1",
      $lesson_id
    ), ARRAY_A);

    if (!$lesson) {
      $this->mrm_insert_payout_ledger_row($order_id, '', 'instructor', 'lesson:' . $lesson_id, '', (string)($credit['currency'] ?? 'usd'), 0, 0, 'blocked', 'Missing lesson row for fixed payout resolution');
      return;
    }

    $new_remaining = max(0, ((int)$credit['remaining_credits']) - 1);
    $wpdb->update($credits_table, array(
      'remaining_credits' => $new_remaining,
      'updated_at' => current_time('mysql'),
    ), array('id' => (int)$credit['id']), array('%d','%s'), array('%d'));

    $lesson_length = (int)($lesson['lesson_length'] ?? 0);
    $is_online = !empty($lesson['is_online']);

    $instr = $this->mrm_get_instructor_row($instructor_id);
    $instructor_acct = (string)($instr['stripe_connected_account_id'] ?? '');
    $hire_date = (string)($instr['hire_date'] ?? '');

    $instructor_share = $this->mrm_get_instructor_payout_cents_for_instructor(
      $lesson_length,
      $is_online,
      $hire_date
    );

    if ($instructor_share > 0) {
      $this->mrm_insert_payout_ledger_row(
        $order_id,
        '',
        'instructor',
        'lesson:' . $lesson_id,
        $instructor_acct,
        (string)($credit['currency'] ?? 'usd'),
        $instructor_share,
        $instructor_share,
        $instructor_acct ? 'pending' : 'blocked',
        $instructor_acct ? $this->mrm_build_instructor_payout_note($lesson_length, $is_online, $hire_date) : 'Missing instructor connected account ID'
      );
    }
  }

  private function charge_and_unlock_autopay($data) {
    global $wpdb;

    $autopay_profile_id = (int)($data['autopay_profile_id'] ?? 0);
    $instructor_id = (int)($data['instructor_id'] ?? 0);
    $lesson_id = (int)($data['lesson_id'] ?? 0);
    $lessons_table = $this->table_lessons();

    error_log('[MRM AutoPay] charge_and_unlock_autopay start'
      . ' lesson_id=' . $lesson_id
      . ' instructor_id=' . $instructor_id
      . ' autopay_profile_id=' . $autopay_profile_id
    );

    if ($autopay_profile_id <= 0 || $instructor_id <= 0 || $lesson_id <= 0) {
      error_log('[MRM AutoPay] charge_and_unlock_autopay aborted: invalid ids.');
      return;
    }

    $lesson = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$lessons_table} WHERE id=%d LIMIT 1",
      $lesson_id
    ), ARRAY_A);

    if (!$lesson) {
      error_log('[MRM AutoPay] charge_and_unlock_autopay aborted: lesson not found.');
      return;
    }

    if ((string)($lesson['payment_mode'] ?? '') !== 'autopay') {
      error_log('[MRM AutoPay] charge_and_unlock_autopay aborted: lesson is not autopay.');
      return;
    }

    if ((string)($lesson['status'] ?? '') !== 'payment_due') {
      error_log('[MRM AutoPay] charge_and_unlock_autopay skipped: lesson status is not payment_due. status=' . (string)($lesson['status'] ?? ''));
      return;
    }

    if ((string)($lesson['charge_status'] ?? '') === 'paid') {
      error_log('[MRM AutoPay] charge_and_unlock_autopay skipped: lesson already paid.');
      return;
    }

    if ((int)($lesson['order_id'] ?? 0) > 0) {
      error_log(
        '[MRM AutoPay] charge_and_unlock_autopay skipped: lesson has original booking order_id and is not a follow-up autopay charge candidate. order_id=' .
        (int)($lesson['order_id'] ?? 0)
      );
      return;
    }

    $google_truth = $this->mrm_google_truth_confirms_lesson_ended($lesson_id);
    if (is_wp_error($google_truth)) {
      $message = $google_truth->get_error_message();

      $wpdb->update(
        $lessons_table,
        array(
          'status' => 'payment_due',
          'charge_status' => 'due',
          'charge_last_error' => $message,
          'updated_at' => current_time('mysql'),
        ),
        array('id' => $lesson_id),
        array('%s','%s','%s','%s'),
        array('%d')
      );

      error_log('[MRM AutoPay] charge delayed pending Google truth for lesson_id=' . $lesson_id . ' reason=' . $message);
      return;
    }

    $existing_order = $this->mrm_find_lesson_charge_order($lesson_id);
    if ($existing_order) {
      $existing_status = (string)($existing_order['status'] ?? '');
      $existing_pi_id = (string)($existing_order['stripe_payment_intent_id'] ?? '');

      if ($existing_pi_id !== '') {
        $this->mrm_reconcile_autopay_existing_order($lesson_id, $existing_order);
        $existing_order = $this->mrm_find_lesson_charge_order($lesson_id);
        $existing_status = (string)($existing_order['status'] ?? '');
      }

      if (in_array($existing_status, array('paid', 'completed', 'succeeded'), true)) {
        $this->mrm_finalize_autopay_lesson_success(
          $lesson_id,
          $existing_order,
          (string)($existing_order['stripe_payment_intent_id'] ?? '')
        );
        return;
      }

      if (in_array($existing_status, array('pending', 'processing', 'requires_capture'), true)) {
        error_log('[MRM AutoPay] charge_and_unlock_autopay skipped: existing in-flight order for lesson_id=' . $lesson_id);
        return;
      }
    }

    $attempts = (int)($lesson['charge_attempts'] ?? 0) + 1;
    $wpdb->update(
      $lessons_table,
      array(
        'charge_status' => 'processing',
        'charge_attempts' => $attempts,
        'charge_last_attempt_at' => current_time('mysql'),
        'charge_last_error' => null,
        'updated_at' => current_time('mysql'),
      ),
      array('id' => $lesson_id),
      array('%s','%d','%s','%s','%s'),
      array('%d')
    );

    $profile = $this->mrm_get_autopay_profile($autopay_profile_id);
    $validated_profile = $this->mrm_validate_autopay_profile_for_charge($profile);
    if ( is_wp_error($validated_profile) ) {
      $this->mrm_finalize_autopay_lesson_failure($lesson_id, $validated_profile->get_error_message());
      return;
    }

    if ((string)($profile['pm_attention_status'] ?? '') === 'needs_update') {
      global $wpdb;
      $wpdb->update(
        $this->table_lessons(),
        array(
          'charge_last_error' => 'AutoPay charge blocked because the saved payment method still requires confirmation.',
          'updated_at' => current_time('mysql'),
        ),
        array('id' => $lesson_id),
        array('%s','%s'),
        array('%d')
      );

      error_log('[MRM AutoPay] charge_and_unlock_autopay aborted: payment method still requires confirmation. lesson_id=' . $lesson_id . ' autopay_profile_id=' . $autopay_profile_id);
      return;
    }

    $customer_id = (string)$validated_profile['customer_id'];
    $payment_method_id = (string)$validated_profile['payment_method_id'];
    $amount_cents = (int)$validated_profile['amount_cents'];
    $currency = (string)$validated_profile['currency'];

    $pm_ready = $this->mrm_ensure_customer_payment_method_ready($customer_id, $payment_method_id);
    if (is_wp_error($pm_ready)) {
      $this->mrm_finalize_autopay_lesson_failure($lesson_id, $pm_ready->get_error_message());
      return;
    }

    $order_id = $this->create_order(
      (string)($profile['email_hash'] ?? ''),
      'autopay_lesson_charge',
      'lesson',
      $amount_cents,
      (string)($profile['currency'] ?? 'usd'),
      array(
        'mrm_autopay_profile_id' => (string)$autopay_profile_id,
        'mrm_instructor_id' => (string)$instructor_id,
        'mrm_lesson_id' => (string)$lesson_id,
        'mrm_charge_attempt' => (string)$attempts,
        'mrm_lesson_length' => (string)($lesson['lesson_length'] ?? ''),
        'mrm_lesson_mode' => ((int)($lesson['is_online'] ?? 0) === 1 ? 'Online' : 'In Person'),
        'mrm_autopay' => 'yes',
        'mrm_plan_kind' => (string)($profile['plan_kind'] ?? ''),
        'mrm_authorized_lesson_count' => (string)((int)($profile['authorized_lesson_count'] ?? 0)),
      )
    );

    $this->link_order($order_id, 'lesson', (string)$lesson_id);

    $pi = $this->stripe_create_offsession_payment_intent(
      $amount_cents,
      $currency,
      $customer_id,
      $payment_method_id,
      array(
        'mrm_order_id' => (string)$order_id,
        'mrm_lesson_id' => (string)$lesson_id,
        'mrm_autopay_profile_id' => (string)$autopay_profile_id,
        'mrm_instructor_id' => (string)$instructor_id,
        'mrm_lesson_length' => (string)($lesson['lesson_length'] ?? ''),
        'mrm_lesson_mode' => ((int)($lesson['is_online'] ?? 0) === 1 ? 'Online' : 'In Person'),
        'mrm_autopay' => 'yes',
        'mrm_plan_kind' => (string)($profile['plan_kind'] ?? ''),
        'mrm_authorized_lesson_count' => (string)((int)($profile['authorized_lesson_count'] ?? 0)),
      ),
      'Low Brass Lessons - Lesson Charge'
    );

    if (is_wp_error($pi)) {
      $wpdb->update($this->table_orders(), array(
        'status' => 'failed',
        'stripe_status' => 'offsession_failed',
        'updated_at' => current_time('mysql'),
      ), array('id' => (int)$order_id), array('%s','%s','%s'), array('%d'));

      $this->mrm_finalize_autopay_lesson_failure($lesson_id, $pi->get_error_message());
      error_log('[MRM AutoPay] off-session payment failed for lesson_id=' . $lesson_id . ' reason=' . $pi->get_error_message());
      return;
    }

    $pi_id = (string)($pi['id'] ?? '');
    $status = (string)($pi['status'] ?? '');

    $local_order_status = 'processing';
    if (in_array($status, array('succeeded', 'requires_capture'), true)) {
      $local_order_status = 'paid';
    } elseif (in_array($status, array('requires_payment_method', 'requires_action', 'canceled'), true)) {
      $local_order_status = 'failed';
    }

    $this->attach_payment_intent_to_order($order_id, $pi_id, $status, $local_order_status);

    error_log('[MRM AutoPay] off-session payment intent created'
      . ' lesson_id=' . $lesson_id
      . ' order_id=' . $order_id
      . ' pi_id=' . $pi_id
      . ' status=' . $status
    );

    if (in_array($status, array('succeeded', 'requires_capture'), true)) {
      // Fast-path finalize from direct Stripe truth.
      // Webhook remains idempotent and will not hurt anything if it arrives later.
      $fresh_order = $this->get_order($order_id);
      $this->mrm_finalize_autopay_lesson_success($lesson_id, $fresh_order, $pi_id);
      return;
    }

    if ($status === 'processing') {
      // Leave lesson in payment_due / processing and let webhook or retry reconcile it.
      return;
    }

    $this->update_order_status_from_pi($pi_id, 'failed', $status, array(
      'mrm_lesson_id' => (string)$lesson_id,
      'mrm_autopay_profile_id' => (string)$autopay_profile_id,
    ));

    $this->mrm_finalize_autopay_lesson_failure($lesson_id, 'Autopay charge not successful: ' . $status);
  }

  private function mrm_is_biweekly_payout_day() {
    $settings = $this->get_settings();
    $anchor = trim((string)($settings['payout_anchor_date'] ?? ''));
    if ($anchor === '') return false;

    try {
      $tz = $this->mrm_wp_tz();
      $today = new DateTime('today', $tz);
      $start = new DateTime($anchor, $tz);

      if ($today < $start) return false;
      if ((int)$today->format('N') !== 5) return false; // Friday

      $days = (int)$start->diff($today)->format('%a');
      return ($days % 14) === 0;
    } catch (Exception $e) {
      return false;
    }
  }

  private function mrm_is_after_payout_cutoff_time($as_of_ts = null) {
    try {
      $tz = $this->mrm_wp_tz();

      if ($as_of_ts !== null && (int)$as_of_ts > 0) {
        $now = new DateTime('@' . (int)$as_of_ts);
        $now->setTimezone($tz);
      } else {
        $now = new DateTime('now', $tz);
      }

      $cutoff = clone $now;
      $cutoff->setTime(10, 0, 0);

      return ($now >= $cutoff);
    } catch (Exception $e) {
      return false;
    }
  }

  private function mrm_is_biweekly_payout_window_open($as_of_ts = null) {
    return $this->mrm_is_biweekly_payout_day() && $this->mrm_is_after_payout_cutoff_time($as_of_ts);
  }

  private function mrm_next_daily_payout_check_timestamp() {
    $tz = $this->mrm_wp_tz();
    $next = new DateTime('now', $tz);
    $next->setTime(10, 0, 0);

    if ($next->getTimestamp() <= time()) {
      $next->modify('+1 day');
    }

    return $next->getTimestamp();
  }

  private function mrm_schedule_daily_payout_check() {
    $hook = 'mrm_pay_hub_daily_payout_check';
    $next = wp_next_scheduled($hook);

    $needs_reschedule = false;

    if (!$next) {
      $needs_reschedule = true;
    } else {
      $local_hour = (int)wp_date('G', $next, $this->mrm_wp_tz());
      $local_minute = (int)wp_date('i', $next, $this->mrm_wp_tz());

      if ($local_hour !== 10 || $local_minute !== 0) {
        $needs_reschedule = true;
      }
    }

    if ($needs_reschedule) {
      wp_clear_scheduled_hook($hook);
      wp_schedule_event($this->mrm_next_daily_payout_check_timestamp(), 'daily', $hook);
    }
  }

  public function cron_check_upcoming_payment_methods() {
    global $wpdb;

    $lessons_table = $this->table_lessons();
    $autopay_table = $this->table_autopay_profiles();

    $window_start = current_time('mysql');
    $window_end = gmdate('Y-m-d H:i:s', time() + (self::MRM_PM_LOOKAHEAD_HOURS * HOUR_IN_SECONDS));

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT l.id, l.instructor_id, l.student_email, l.lesson_length, l.is_online, l.start_time, l.end_time, l.autopay_profile_id,
              l.status, l.charge_status, l.charge_last_error,
              ap.id AS profile_id, ap.payment_method_id, ap.pm_brand, ap.pm_last4, ap.pm_exp_month, ap.pm_exp_year,
              ap.pm_attention_status, ap.pm_attention_reason, ap.active
       FROM {$lessons_table} l
       INNER JOIN {$autopay_table} ap ON ap.id = l.autopay_profile_id
       WHERE l.payment_mode = 'autopay'
         AND COALESCE(l.status, 'scheduled') = 'scheduled'
         AND COALESCE(ap.active, 0) = 1
         AND COALESCE(l.autopay_profile_id, 0) > 0
         AND l.start_time >= %s
         AND l.start_time <= %s
       ORDER BY l.start_time ASC",
      $window_start,
      $window_end
    ), ARRAY_A);

    if (!is_array($rows) || empty($rows)) {
      return;
    }

    foreach ($rows as $row) {
      $lesson_id = (int)($row['id'] ?? 0);
      $autopay_profile_id = (int)($row['autopay_profile_id'] ?? 0);
      $payment_method_id = (string)($row['payment_method_id'] ?? '');

      if ($lesson_id <= 0 || $autopay_profile_id <= 0 || $payment_method_id === '') {
        continue;
      }

      $pm = $this->stripe_retrieve_payment_method($payment_method_id);

      if (is_wp_error($pm)) {
        $reason_text = 'We could not confirm the saved payment method with Stripe.';
        $signature = md5('pm-unreachable|' . $payment_method_id . '|' . $lesson_id);

        $this->mrm_store_autopay_payment_method_snapshot(
          $autopay_profile_id,
          $payment_method_id,
          null,
          'needs_update',
          $reason_text,
          false
        );

        $wpdb->update(
          $lessons_table,
          array(
            'charge_last_error' => 'Payment method requires confirmation before lesson. ' . $reason_text,
            'updated_at' => current_time('mysql'),
          ),
          array('id' => $lesson_id),
          array('%s','%s'),
          array('%d')
        );

        if (!$this->mrm_pm_notice_already_sent_for_signature($lesson_id, $signature)) {
          $snapshot = array(
            'brand' => (string)($row['pm_brand'] ?? ''),
            'last4' => (string)($row['pm_last4'] ?? ''),
            'exp_month' => (int)($row['pm_exp_month'] ?? 0),
            'exp_year' => (int)($row['pm_exp_year'] ?? 0),
          );
          $sent = $this->mrm_send_upcoming_payment_method_attention_emails($row, $row, $snapshot, $reason_text);
          if ($sent) {
            $this->mrm_mark_pm_notice_sent_for_signature($lesson_id, $signature);
            $this->mrm_store_autopay_payment_method_snapshot(
              $autopay_profile_id,
              $payment_method_id,
              array('card' => $snapshot),
              'needs_update',
              $reason_text,
              true
            );
          }
        }

        continue;
      }

      $snap = $this->mrm_extract_card_snapshot_from_payment_method($pm);
      $eval = $this->mrm_payment_method_needs_attention_for_lesson(
        (int)$snap['exp_month'],
        (int)$snap['exp_year'],
        (string)($row['start_time'] ?? '')
      );

      if (!empty($eval['needs_attention'])) {
        $reason_text = (string)$eval['reason_text'];
        $signature = md5($payment_method_id . '|' . $snap['exp_month'] . '|' . $snap['exp_year'] . '|' . $lesson_id . '|' . $eval['reason_code']);

        $this->mrm_store_autopay_payment_method_snapshot(
          $autopay_profile_id,
          $payment_method_id,
          $pm,
          'needs_update',
          $reason_text,
          false
        );

        $wpdb->update(
          $lessons_table,
          array(
            'charge_last_error' => 'Payment method requires confirmation before lesson. ' . $reason_text,
            'updated_at' => current_time('mysql'),
          ),
          array('id' => $lesson_id),
          array('%s','%s'),
          array('%d')
        );

        if (!$this->mrm_pm_notice_already_sent_for_signature($lesson_id, $signature)) {
          $sent = $this->mrm_send_upcoming_payment_method_attention_emails($row, $row, $snap, $reason_text);
          if ($sent) {
            $this->mrm_mark_pm_notice_sent_for_signature($lesson_id, $signature);
            $this->mrm_store_autopay_payment_method_snapshot(
              $autopay_profile_id,
              $payment_method_id,
              $pm,
              'needs_update',
              $reason_text,
              true
            );
          }
        }

        continue;
      }

      $this->mrm_store_autopay_payment_method_snapshot(
        $autopay_profile_id,
        $payment_method_id,
        $pm,
        'ok',
        '',
        false
      );

      $existing_error = (string)($row['charge_last_error'] ?? '');
      if (strpos($existing_error, 'Payment method requires confirmation before lesson.') === 0) {
        $wpdb->update(
          $lessons_table,
          array(
            'charge_last_error' => null,
            'updated_at' => current_time('mysql'),
          ),
          array('id' => $lesson_id),
          array('%s','%s'),
          array('%d')
        );
      }

      $this->mrm_clear_pm_notice_signature($lesson_id);
    }
  }

  public function cron_daily_payout_check() {
    if ($this->mrm_is_biweekly_payout_window_open()) {
      $this->mrm_run_payout_batch(false);
    }
  }

  public function mrm_run_payout_batch($force = false) {
    $summary = array(
      'transfers_created' => 0,
      'payouts_created' => 0,
      'errors' => 0,
      'last_error' => '',
    );

    if (!$force && !$this->mrm_is_biweekly_payout_window_open()) {
      return $summary;
    }

    // Force lesson completion reconciliation before looking for pending payouts.
    // This lets manual payout runs pick up lessons that were moved/rescheduled
    // in Google Calendar and have now ended.
    do_action('mrm_scheduler_reconcile_completed_lessons');
    do_action('mrm_scheduler_reconcile_cancelled_lessons');
    error_log('MRM payout batch: forced lesson reconciliation before payout selection.');

    global $wpdb;
    $table = $this->table_payout_ledger();
    $rows = $wpdb->get_results(
      "SELECT * FROM {$table}
       WHERE status IN ('pending','transferred')
         AND connected_account_id IS NOT NULL
         AND connected_account_id <> ''
       ORDER BY connected_account_id ASC, id ASC",
      ARRAY_A
    );

    if (!$rows) return $summary;

    $platform_balance = $this->stripe_retrieve_balance();
    if (is_wp_error($platform_balance)) {
      $summary['errors']++;
      $summary['last_error'] = 'Unable to retrieve Stripe balance before payout batch: ' . $platform_balance->get_error_message();
      return $summary;
    }

    // Available-funds-only payout model:
    // do not build an order -> charge map, because instructor transfers
    // should never use source_transaction / pending incoming charge funds.

    $batch_key = 'batch_' . gmdate('Ymd_His');

    $groups = array();
    foreach ($rows as $row) {
      $gk = $row['connected_account_id'] . '|' . strtolower((string)$row['currency']);
      if (!isset($groups[$gk])) $groups[$gk] = array();
      $groups[$gk][] = $row;
    }

    foreach ($groups as $group_key => $group_rows) {
      $first = $group_rows[0];
      $acct = (string)$first['connected_account_id'];
      $currency = strtolower((string)$first['currency']);

      $pending_rows = array();
      $already_transferred_rows = array();

      foreach ($group_rows as $group_row) {
        $row_status = (string)($group_row['status'] ?? '');
        if ($row_status === 'transferred') {
          $already_transferred_rows[] = $group_row;
        } else {
          $pending_rows[] = $group_row;
        }
      }

      $newly_transferred_total = 0;
      $newly_transferred_ids = array();

      $group_required_total = $this->mrm_sum_group_transfer_amount($pending_rows);
      $available = $this->mrm_balance_available_for_currency($platform_balance, $currency);

      if ($group_required_total > 0) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
          error_log(
            '[MRM Payout] available_balance_only_preflight'
            . ' currency=' . $currency
            . ' group_required_total=' . (int)$group_required_total
            . ' available_total=' . (int)$available['total']
            . ' connected_account_id=' . $acct
          );
        }

        if ((int)$available['total'] < $group_required_total) {
          $msg = sprintf(
            'Waiting for Stripe available balance. Needed %s %0.2f, but only %s %0.2f is currently available.',
            strtoupper($currency),
            $group_required_total / 100,
            strtoupper($currency),
            ((int)$available['total']) / 100
          );

          $this->mrm_mark_group_pending_balance_wait($table, $pending_rows, $msg);
          $summary['errors']++;
          $summary['last_error'] = $msg;
          continue;
        }

      }

      foreach ($pending_rows as $row) {
        $amount = (int)$row['net_cents'];
        if ($amount <= 0) continue;

        $transfer = $this->stripe_create_transfer(
          $amount,
          $currency,
          $acct,
          'MRM_ORDER_' . (int)$row['order_id'],
          array(
            'mrm_order_id' => (string)$row['order_id'],
            'mrm_payee_type' => (string)$row['payee_type'],
            'mrm_batch_key' => $batch_key,
            'mrm_transfer_funding_mode' => 'platform_available_balance_only',
          )
        );

        if (is_wp_error($transfer)) {
          $summary['errors']++;
          $summary['last_error'] = $transfer->get_error_message();

          $transfer_error_message = (string)$transfer->get_error_message();
          $is_balance_wait_condition =
            (stripos($transfer_error_message, 'insufficient funds') !== false) ||
            (stripos($transfer_error_message, 'balance is too low') !== false);

          $wpdb->update(
            $table,
            array(
              'status' => $is_balance_wait_condition ? 'pending' : 'error',
              'notes' => $is_balance_wait_condition
                ? ('Waiting for platform available balance before transfer: ' . $transfer_error_message)
                : $transfer_error_message,
              'updated_at' => current_time('mysql'),
            ),
            array('id' => (int)$row['id']),
            array('%s','%s','%s'),
            array('%d')
          );
          continue;
        }

        $wpdb->update(
          $table,
          array(
            'status' => 'transferred',
            'transfer_id' => (string)($transfer['id'] ?? ''),
            'batch_key' => $batch_key,
            'updated_at' => current_time('mysql'),
          ),
          array('id' => (int)$row['id']),
          array('%s','%s','%s','%s'),
          array('%d')
        );

        $summary['transfers_created']++;
        $newly_transferred_total += $amount;
        $newly_transferred_ids[] = (int)$row['id'];

        $row['status'] = 'transferred';
        $row['transfer_id'] = (string)($transfer['id'] ?? '');
        $row['batch_key'] = $batch_key;
        $already_transferred_rows[] = $row;

        if (isset($platform_balance['available']) && is_array($platform_balance['available'])) {
          foreach ($platform_balance['available'] as &$balance_entry) {
            if (strtolower((string)($balance_entry['currency'] ?? '')) !== $currency) {
              continue;
            }

            $balance_entry['amount'] = max(0, (int)($balance_entry['amount'] ?? 0) - $amount);
            break;
          }
          unset($balance_entry);
        }
      }

      $payout_candidate_rows = array();
      $payout_total = 0;
      $payout_ledger_ids = array();

      foreach ($already_transferred_rows as $row) {
        $ledger_id = (int)($row['id'] ?? 0);
        $amount = (int)($row['net_cents'] ?? 0);
        if ($ledger_id <= 0 || $amount <= 0) {
          continue;
        }

        $payout_candidate_rows[] = $row;
        $payout_total += $amount;
        $payout_ledger_ids[] = $ledger_id;
      }

      if ($payout_total <= 0 || empty($payout_candidate_rows)) {
        continue;
      }

      $connected_balance = $this->stripe_retrieve_connected_account_balance($acct);
      if (is_wp_error($connected_balance)) {
        $summary['errors']++;
        $summary['last_error'] = 'Unable to retrieve connected account balance before payout: ' . $connected_balance->get_error_message();
        $this->mrm_mark_group_transferred_balance_wait(
          $table,
          $payout_candidate_rows,
          'Waiting for connected account balance visibility before payout: ' . $connected_balance->get_error_message()
        );
        continue;
      }

      $connected_available = $this->mrm_balance_available_for_currency($connected_balance, $currency);

      if ((int)$connected_available['total'] < $payout_total) {
        $msg = sprintf(
          'Waiting for connected account available balance. Needed %s %0.2f, but only %s %0.2f is currently available on the connected account.',
          strtoupper($currency),
          $payout_total / 100,
          strtoupper($currency),
          ((int)$connected_available['total']) / 100
        );

        $this->mrm_mark_group_transferred_balance_wait($table, $payout_candidate_rows, $msg);
        $summary['errors']++;
        $summary['last_error'] = $msg;
        continue;
      }

      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(
          '[MRM Payout] connected_balance_preflight'
          . ' currency=' . $currency
          . ' payout_total=' . (int)$payout_total
          . ' connected_available_total=' . (int)$connected_available['total']
          . ' connected_account_id=' . $acct
        );
      }

      $payout = $this->stripe_create_connected_account_payout(
        $acct,
        $payout_total,
        $currency,
        array(
          'mrm_batch_key' => $batch_key,
        )
      );

      if (is_wp_error($payout)) {
        $summary['errors']++;
        $summary['last_error'] = $payout->get_error_message();

        $this->mrm_mark_group_transferred_balance_wait(
          $table,
          $payout_candidate_rows,
          'Connected account payout failed and will be retried: ' . $payout->get_error_message()
        );
        continue;
      }

      foreach ($payout_ledger_ids as $ledger_id) {
        $wpdb->update(
          $table,
          array(
            'status' => 'paid_out',
            'payout_id' => (string)($payout['id'] ?? ''),
            'notes' => '',
            'updated_at' => current_time('mysql'),
          ),
          array('id' => (int)$ledger_id),
          array('%s','%s','%s','%s'),
          array('%d')
        );
      }

      $summary['payouts_created']++;
    }

    return $summary;
  }

  /* =========================================================
   * REST API
   * ======================================================= */

  public function register_routes() {
    register_rest_route('mrm-pay/v1', '/quote', array(
      'methods' => WP_REST_Server::READABLE,
      'callback' => array($this, 'rest_quote'),
      'permission_callback' => '__return_true',
    ));

    register_rest_route('mrm-pay/v1', '/resolve', array(
      'methods' => WP_REST_Server::READABLE,
      'callback' => array($this, 'rest_resolve'),
      'permission_callback' => '__return_true',
    ));

    register_rest_route('mrm-pay/v1', '/create-payment-intent', array(
      'methods' => WP_REST_Server::CREATABLE,
      'callback' => array($this, 'rest_create_payment_intent'),
      'permission_callback' => '__return_true',
    ));

    register_rest_route('mrm-pay/v1', '/update-tax', array(
      'methods' => WP_REST_Server::CREATABLE,
      'callback' => array($this, 'rest_update_tax'),
      'permission_callback' => '__return_true',
    ));

    register_rest_route('mrm-pay/v1', '/verify-payment-intent', array(
      'methods' => WP_REST_Server::CREATABLE,
      'callback' => array($this, 'rest_verify_payment_intent'),
      'permission_callback' => '__return_true',
    ));


    register_rest_route('mrm-pay/v1', '/create-setup-intent', array(
      'methods' => WP_REST_Server::CREATABLE,
      'callback' => array($this, 'rest_create_setup_intent'),
      'permission_callback' => '__return_true',
    ));

    register_rest_route('mrm-pay/v1', '/finalize-autopay', array(
      'methods' => WP_REST_Server::CREATABLE,
      'callback' => array($this, 'rest_finalize_autopay'),
      'permission_callback' => '__return_true',
    ));

    register_rest_route('mrm-pay/v1', '/create-autopay-enrollment', array(
      'methods' => 'POST',
      'callback' => array($this, 'rest_create_autopay_enrollment'),
      'permission_callback' => '__return_true',
    ));

    register_rest_route('mrm-pay/v1', '/stripe-webhook', array(
      'methods' => WP_REST_Server::CREATABLE,
      'callback' => array($this, 'rest_stripe_webhook'),
      'permission_callback' => '__return_true',
    ));


    register_rest_route('mrm-pay/v1', '/grant-sheet-music-access', array(
      'methods' => WP_REST_Server::CREATABLE,
      'callback' => array($this, 'rest_grant_sheet_music_access'),
      'permission_callback' => '__return_true',
    ));

    register_rest_route('mrm-pay/v1', '/has-access', array(
      'methods' => WP_REST_Server::CREATABLE,
      'callback' => array($this, 'rest_has_access'),
      'permission_callback' => '__return_true',
    ));

    register_rest_route('mrm-pay/v1', '/subscription-portal', array(
      'methods' => WP_REST_Server::READABLE,
      'callback' => array($this, 'rest_subscription_portal'),
      'permission_callback' => '__return_true',
    ));

  }

  public function rest_stripe_webhook(WP_REST_Request $req) {
    $payload = $req->get_body();
    $this->stripe_debug_log('stripe webhook entrypoint reached');
    $signature = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? (string)$_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
    $secret = $this->webhook_secret();

    if (!$secret) {
      return new WP_REST_Response(array('ok' => false, 'message' => 'Webhook secret is not configured.'), 500);
    }

    $event = $this->stripe_construct_webhook_event($payload, $signature, $secret);
    if (is_wp_error($event)) {
      return new WP_REST_Response(array('ok' => false, 'message' => $event->get_error_message()), 400);
    }

    $event_id = (string)($event['id'] ?? '');
    $event_type = (string)($event['type'] ?? '');

    $this->stripe_debug_log('webhook received', array(
      'event_id' => (string)($event['id'] ?? ''),
      'event_type' => $event_type,
    ));
    $object = isset($event['data']['object']) && is_array($event['data']['object']) ? $event['data']['object'] : array();
    $object_id = (string)($object['id'] ?? '');

    if ($event_id === '' || $event_type === '') {
      return new WP_REST_Response(array('ok' => false, 'message' => 'Invalid webhook event.'), 400);
    }

    if ($this->mrm_webhook_event_already_processed($event_id)) {
      return new WP_REST_Response(array('ok' => true, 'duplicate' => true), 200);
    }

    switch ($event_type) {
      case 'payment_intent.succeeded':
        $this->mrm_handle_payment_intent_succeeded_webhook($object);
        break;

      case 'payment_intent.payment_failed':
        $this->mrm_handle_payment_intent_failed_webhook($object, 'failed');
        break;

      case 'payment_intent.canceled':
        $this->mrm_handle_payment_intent_failed_webhook($object, 'failed');
        break;

      case 'charge.refunded':
        $this->mrm_handle_charge_refunded_webhook($object);
        break;

      case 'customer.subscription.created':
        $this->mrm_handle_customer_subscription_created_webhook($object);
        break;

      case 'customer.subscription.updated':
        $this->mrm_handle_customer_subscription_updated_webhook($object);
        break;

      case 'customer.subscription.deleted':
        $this->mrm_handle_customer_subscription_deleted_webhook($object);
        break;

      case 'invoice.paid':
        $this->mrm_handle_invoice_paid_webhook($object);
        break;

      case 'invoice.payment_failed':
        $this->mrm_handle_invoice_payment_failed_webhook($object);
        break;

      default:
        // Acknowledge unhandled events so Stripe does not keep retrying forever.
        break;
    }

    $this->mrm_mark_webhook_event_processed($event_id, $event_type, $object_id);

    $this->stripe_debug_log('webhook processed successfully', array(
      'event_id' => (string)($event['id'] ?? ''),
      'event_type' => $event_type,
    ));

    return new WP_REST_Response(array('ok' => true), 200);
  }

  public function rest_subscription_portal(WP_REST_Request $req) {
    $token = sanitize_text_field((string)$req->get_param('token'));
    if ($token === '') {
      wp_die('Missing subscription token.', 'Subscription Portal', array('response' => 400));
    }

    $sub = $this->mrm_get_sheet_music_subscription_by_portal_token($token);
    if (empty($sub['id']) || empty($sub['stripe_customer_id'])) {
      wp_die('Invalid subscription token.', 'Subscription Portal', array('response' => 404));
    }

    $portal = $this->stripe_create_billing_portal_session(
      (string)$sub['stripe_customer_id'],
      home_url('/')
    );

    if (is_wp_error($portal) || empty($portal['url'])) {
      wp_die('Unable to open subscription portal right now.', 'Subscription Portal', array('response' => 500));
    }

    wp_redirect((string)$portal['url']);
    exit;
  }


  public function rest_quote(WP_REST_Request $req) {
    $sku = $this->sanitize_sku($req->get_param('sku'));
    if (!$sku) {
      error_log('[MRM Payments Hub] /quote missing sku');
      return new WP_REST_Response(array('ok'=>false,'message'=>'Missing sku.'), 400);
    }

    $p = $this->get_product($sku);
    if (!$p) {
      error_log('[MRM Payments Hub] /quote unmapped sku=' . $sku);
      return new WP_REST_Response(array(
        'ok'=>false,
        'message'=>'Unknown sku.',
        'debug'=>array('sku'=>$sku)
      ), 404);
    }

    if (empty($p['active'])) {
      error_log('[MRM Payments Hub] /quote inactive sku=' . $sku);
      return new WP_REST_Response(array(
        'ok'=>false,
        'message'=>'Inactive sku.',
        'debug'=>array('sku'=>$sku)
      ), 404);
    }

    $amount_cents = isset($p['amount_cents']) ? (int)$p['amount_cents'] : 0;
    $currency = strtolower((string)($p['currency'] ?? 'usd'));
    $price_id = isset($p['stripe_price_id']) ? trim((string)$p['stripe_price_id']) : '';

    // Hard fail: NEVER report ok:true with a non-positive amount
    if ($amount_cents <= 0) {
      error_log('[MRM Payments Hub] /quote invalid amount for sku=' . $sku . ' amount_cents=' . $amount_cents);
      return new WP_REST_Response(array(
        'ok'=>false,
        'message'=>'Pricing is not configured for this sku.',
        'debug'=>array('sku'=>$sku,'amount_cents'=>$amount_cents)
      ), 500);
    }

    if (!$currency) {
      error_log('[MRM Payments Hub] /quote missing currency for sku=' . $sku);
      return new WP_REST_Response(array(
        'ok'=>false,
        'message'=>'Currency is not configured for this sku.',
        'debug'=>array('sku'=>$sku)
      ), 500);
    }

    $preview_address = array(
      'country' => strtoupper((string)($req->get_param('country') ?? 'US')),
      'state' => (string)($req->get_param('state') ?? ''),
      'postal_code' => (string)($req->get_param('postal_code') ?? ''),
      'line1' => (string)($req->get_param('line1') ?? ''),
    );

    $preview_policy = $this->mrm_build_tax_policy($preview_address, (string)($p['product_type'] ?? 'unknown'), false, $p, array());

    return new WP_REST_Response(array(
      'ok' => true,
      'sku' => $sku,
      'label' => (string)($p['label'] ?? $sku),
      'amount_cents' => $amount_cents,
      'currency' => $currency,
      'tax_pending' => true,
      'tax_message' => (string)($preview_policy['policy_message'] ?? 'Sales tax is calculated after billing state and ZIP are entered.'),
      'tax_policy_preview' => array(
        'policy_reason' => (string)($preview_policy['policy_reason'] ?? ''),
        'should_collect_tax' => !empty($preview_policy['should_collect_tax']),
        'state' => (string)($preview_policy['jurisdiction']['state'] ?? ''),
        'country' => (string)($preview_policy['jurisdiction']['country'] ?? 'US'),
      ),
      // Optional: only returned if you’ve stored it in products
      'price_id' => $price_id ? $price_id : null,
    ), 200);
  }

  private function resolve_sheet_music_sku($piece_slug, $type) {
    $piece_slug = $this->slugify((string)$piece_slug);

    $type = strtolower(trim((string)$type));
    $allowed = array('fundamentals','trombone-euphonium','tuba','complete-package');
    if (!in_array($type, $allowed, true)) return null;

    $base = 'piece-' . $piece_slug . '-' . $type;

    $all = $this->all_products();

    // Exact match first
    if (isset($all[$base]) && is_array($all[$base]) && !empty($all[$base]['active'])) {
      return $base;
    }

    // Fall back to any numeric-suffix SKU the hub may have generated
    for ($i = 2; $i <= 999; $i++) {
      $candidate = $base . '-' . $i;
      if (isset($all[$candidate]) && is_array($all[$candidate]) && !empty($all[$candidate]['active'])) {
        return $candidate;
      }
    }

    return null;
  }

  public function rest_resolve(WP_REST_Request $req) {
    $piece_slug = (string)$req->get_param('piece_slug');
    $type = (string)$req->get_param('type');

    if (!$piece_slug) return new WP_REST_Response(array('ok'=>false,'message'=>'Missing piece_slug.'), 400);
    if (!$type) return new WP_REST_Response(array('ok'=>false,'message'=>'Missing type.'), 400);

    $sku = $this->resolve_sheet_music_sku($piece_slug, $type);
    if (!$sku) return new WP_REST_Response(array('ok'=>false,'message'=>'Unknown or inactive sku.'), 404);

    $p = $this->get_product($sku);
    return new WP_REST_Response(array(
      'ok' => true,
      'sku' => $sku,
      'label' => (string)($p['label'] ?? $sku),
      'amount_cents' => (int)($p['amount_cents'] ?? 0),
      'currency' => (string)($p['currency'] ?? 'usd'),
      'product_type' => (string)($p['product_type'] ?? 'unknown'),
    ), 200);
  }

  public function rest_create_payment_intent(WP_REST_Request $req) {
    $data = (array) $req->get_json_params();

    $sku = $this->sanitize_sku($data['sku'] ?? '');
    $email = sanitize_email((string)($data['email'] ?? ''));
    $context = isset($data['context']) && is_array($data['context']) ? $data['context'] : array();

    if (!$sku) return new WP_REST_Response(array('ok'=>false,'message'=>'Missing sku.'), 400);
    if (!$email || !is_email($email)) return new WP_REST_Response(array('ok'=>false,'message'=>'Valid email required.'), 400);

    $p = $this->get_product($sku);
    if (!$p || empty($p['active'])) {
      return new WP_REST_Response(array('ok'=>false,'message'=>'Unknown or inactive sku.'), 404);
    }

    $amount = (int)($p['amount_cents'] ?? 0);
    $currency = (string)($p['currency'] ?? 'usd');
    $product_type = (string)($p['product_type'] ?? 'unknown');

    $base_amount = (int)$amount;

    // Diagnostics for misconfigured products
    if ($base_amount <= 0) {
      error_log('[MRM Payments Hub] create-payment-intent invalid base amount sku=' . $sku . ' amount_cents=' . $base_amount);
      return new WP_REST_Response(array(
        'ok'=>false,
        'message'=>'Invalid product price.',
        'debug'=>array('sku'=>$sku,'amount_cents'=>$base_amount)
      ), 400);
    }

    // Read scheduler intent
    $lesson_count = isset($context['lesson_count']) ? absint($context['lesson_count']) : 0;
    $prepay_flag  = isset($context['prepay']) ? strtolower((string)$context['prepay']) : 'no';

    // Optional: scheduler may request a one-time override (e.g., prepay multiple lessons).
    $override = isset($data['amount_override_cents']) ? absint($data['amount_override_cents']) : 0;

    // If prepay is enabled and count > 1, enforce exact math: override MUST equal base * count.
    // (This prevents “prepay but only paid for one”.)
    if ($prepay_flag === 'yes' && $lesson_count > 1) {
      $expected = $base_amount * $lesson_count;

      if ($override > 0 && $override !== $expected) {
        error_log('[MRM Payments Hub] prepay mismatch sku=' . $sku . ' base=' . $base_amount . ' count=' . $lesson_count . ' override=' . $override . ' expected=' . $expected);
        return new WP_REST_Response(array(
          'ok'=>false,
          'message'=>'Prepay total mismatch. Please refresh and try again.',
          'debug'=>array(
            'sku'=>$sku,
            'base_amount_cents'=>$base_amount,
            'lesson_count'=>$lesson_count,
            'expected_amount_cents'=>$expected,
            'amount_override_cents'=>$override
          )
        ), 400);
      }

      // If frontend didn’t send override for some reason, compute it server-side
      if ($override <= 0) {
        $override = $expected;
      }

      $amount = $override;
    } else {
      // Non-prepay: accept override with reasonable bounds (existing behavior)
      if ($override > 0) {
        $max = $base_amount * 50; // generous cap
        if ($override >= $base_amount && $override <= $max) {
          $amount = $override;
        } else {
          error_log('[MRM Payments Hub] Rejected amount_override_cents sku=' . $sku . ' base=' . $base_amount . ' override=' . $override);
          return new WP_REST_Response(array(
            'ok'=>false,
            'message'=>'Invalid override amount.',
            'debug'=>array('sku'=>$sku,'base_amount_cents'=>$base_amount,'amount_override_cents'=>$override)
          ), 400);
        }
      }
    }

    $addon_selected = (isset($data['sheet_music_addon']) && strtolower((string)$data['sheet_music_addon']) === 'yes');
    $address = (isset($data['address']) && is_array($data['address'])) ? $data['address'] : array();
    $address = $this->mrm_normalize_tax_address($address);

    $base_amount_cents = (int)$amount;
    $addon_amount_cents = $addon_selected ? 500 : 0;

    $tax_policy = $this->mrm_build_tax_policy($address, $product_type, $addon_selected, $p, $context);
    $taxable_items = $this->mrm_build_taxable_items_from_policy($tax_policy, $base_amount_cents, $addon_amount_cents);

    $tax_result = array(
      'ok' => true,
      'tax_cents' => 0,
      'amount_total_cents' => ($base_amount_cents + $addon_amount_cents),
      'calculation_id' => '',
      'line_items' => array(),
      'taxability_reason' => (string)($tax_policy['policy_reason'] ?? ''),
    );

    if (!empty($tax_policy['should_collect_tax']) && !empty($taxable_items)) {
      $tax_result = $this->mrm_tax_calculate_for_items($address, $taxable_items, $currency);
    } else {
      $this->stripe_debug_log('create_payment_intent tax skipped before stripe tax request', array(
        'sku' => $sku,
        'product_type' => $product_type,
        'policy_reason' => (string)($tax_policy['policy_reason'] ?? ''),
        'policy_message' => (string)($tax_policy['policy_message'] ?? ''),
        'address' => $address,
      ));
    }

    $tax_cents = (!empty($tax_result['ok'])) ? (int)($tax_result['tax_cents'] ?? 0) : 0;
    $tax_calc_id = (!empty($tax_result['ok'])) ? (string)($tax_result['calculation_id'] ?? '') : '';
    $tax_message = $this->mrm_build_tax_display_message($tax_policy, $tax_result, $tax_cents);

    if ($tax_cents <= 0 && !empty($taxable_items)) {
      $this->stripe_debug_log('create_payment_intent tax result was zero', array(
        'sku' => $sku,
        'product_type' => $product_type,
        'tax_calculation_id' => $tax_calc_id,
        'taxability_reason' => (string)($tax_result['taxability_reason'] ?? ''),
        'policy_reason' => (string)($tax_policy['policy_reason'] ?? ''),
        'policy_message' => $tax_message,
        'address' => $address,
        'taxable_items' => $taxable_items,
      ));
    }

    $final_amount_cents = $base_amount_cents + $addon_amount_cents + $tax_cents;

    // Final safety: NEVER create PI with amount=0
    if ($final_amount_cents <= 0) {
      error_log('[MRM Payments Hub] Refused PI with non-positive amount sku=' . $sku . ' amount=' . $final_amount_cents);
      return new WP_REST_Response(array('ok'=>false,'message'=>'Invalid product price.'), 400);
    }

    $email_hash = $this->email_hash($email);

    // Build labels
    $metadata = $this->build_metadata($sku, $product_type, $email_hash, $context, $p);
    $metadata['mrm_customer_email'] = $email;
    $metadata['mrm_sheet_music_addon'] = $addon_selected ? 'yes' : 'no';
    $metadata['mrm_base_amount_cents'] = (string)$base_amount_cents;
    $metadata['mrm_addon_amount_cents'] = (string)$addon_amount_cents;
    // Total tax for this order (covers sheet music base + subscription add-on, depending on product).
    $metadata['mrm_tax_cents'] = (string)$tax_cents;
    $metadata['mrm_tax_calculation_id'] = (string)$tax_calc_id;
    // Back-compat key (historically used for the $5 add-on tax only).
    $metadata['mrm_addon_tax_cents'] = (string)$tax_cents;

    $metadata['mrm_tax_country'] = (string)($address['country'] ?? 'US');
    $metadata['mrm_tax_state'] = (string)($address['state'] ?? '');
    $metadata['mrm_tax_postal_code'] = (string)($address['postal_code'] ?? '');
    $metadata['mrm_tax_line1'] = (string)($address['line1'] ?? '');
    $metadata['mrm_tax_rollout_mode'] = 'stripe_only';
    $metadata['mrm_tax_calculation_requested'] = !empty($tax_policy['should_collect_tax']) ? 'yes' : 'no';
    $metadata['mrm_tax_policy_reason'] = (string)($tax_policy['policy_reason'] ?? '');
    $metadata['mrm_tax_policy_message'] = (string)$tax_message;
    $metadata['mrm_taxability_reason'] = (string)($tax_result['taxability_reason'] ?? '');

    // Early duplicate guard:
    // If this is a lesson checkout with the $5 sheet music add-on selected,
    // block checkout before creating the order / PaymentIntent when the email
    // already has an active Stripe-synced sheet music subscription.
    if ($addon_selected && $product_type === 'lesson') {
      $existing_subscription = $this->mrm_get_active_sheet_music_subscription_by_email($email);

      if (!empty($existing_subscription['id'])) {
        $this->stripe_debug_log('create_payment_intent blocked: duplicate sheet music subscription', array(
          'email' => $email,
          'sku' => $sku,
          'product_type' => $product_type,
          'existing_subscription_id' => (string)($existing_subscription['stripe_subscription_id'] ?? ''),
          'existing_status' => (string)($existing_subscription['stripe_status'] ?? ''),
        ));

        return new WP_REST_Response(array(
          'ok' => false,
          'code' => 'already_enrolled_sheet_music_subscription',
          'message' => $this->mrm_sheet_music_duplicate_subscription_message(),
          'already_enrolled' => true,
        ), 409);
      }
    }

    // Create internal order first so order_id can be labeled in Stripe metadata
    $order_id = $this->create_order($email_hash, $sku, $product_type, $final_amount_cents, $currency, $metadata);
    $metadata['mrm_order_id'] = (string)$order_id;

    $description = ($product_type === 'lesson')
      ? 'Low Brass Lessons - Lesson Charge'
      : 'Low Brass Lessons - Sheet Music Charge';

    $save_card = !empty($data['save_card']);
    $requires_customer_for_subscription = ($addon_selected && $product_type === 'lesson');
    $requires_customer_for_piece_purchase = false;

    $extra = array();
    $customer_id = '';

    // Default safe method set
    $payment_method_types = array('card');

    // Keep card-only so the Payment Element can show:
    // - card entry
    // - Apple Pay (when available)
    // - Google Pay (when available)
    // We intentionally do NOT include us_bank_account here.
    if (!$save_card && !$requires_customer_for_subscription && !$requires_customer_for_piece_purchase) {
      $payment_method_types = array('card');
    }

    // Enable Stripe receipt emails
    $extra['receipt_email'] = $email;

    if ($save_card || $requires_customer_for_subscription || $requires_customer_for_piece_purchase) {
      $this->stripe_debug_log('create_payment_intent resolving stripe customer', array(
        'email' => $email,
        'save_card' => ($save_card ? 'yes' : 'no'),
        'requires_customer_for_subscription' => ($requires_customer_for_subscription ? 'yes' : 'no'),
        'requires_customer_for_piece_purchase' => ($requires_customer_for_piece_purchase ? 'yes' : 'no'),
        'addon_selected' => ($addon_selected ? 'yes' : 'no'),
        'product_type' => $product_type,
      ));
      $customer_id = $this->stripe_find_or_create_customer($email);
      if (is_wp_error($customer_id)) {
        return new WP_REST_Response(array('ok'=>false,'message'=>$customer_id->get_error_message()), 500);
      }

      $customer_update = $this->stripe_update_customer_address(
        $customer_id,
        $email,
        $address,
        (string)($context['student_name'] ?? '')
      );

      if (is_wp_error($customer_update)) {
        $this->stripe_debug_log('create_payment_intent stripe customer address update failed', array(
          'email' => $email,
          'customer_id' => (string)$customer_id,
          'message' => $customer_update->get_error_message(),
        ));
      }

      // Attach PI to a real Stripe customer so future off-session billing can work.
      $extra['customer'] = $customer_id;
      $extra['setup_future_usage'] = 'off_session';

      $metadata['mrm_save_card'] = 'yes';
      $metadata['mrm_customer_id'] = (string)$customer_id;
      $this->stripe_debug_log('create_payment_intent stripe customer resolved', array(
        'email' => $email,
        'customer_id' => (string)$customer_id,
      ));
    }

    $pi = $this->stripe_create_payment_intent($final_amount_cents, $currency, $metadata, $description, $extra, $payment_method_types);
    if (is_wp_error($pi)) {
      $data = $pi->get_error_data();
      error_log('[MRM Payments Hub] Stripe PI error sku=' . $sku . ' msg=' . $pi->get_error_message() . ' data=' . wp_json_encode($data));
      return new WP_REST_Response(array('ok'=>false,'message'=>$pi->get_error_message()), 500);
    }

    $this->attach_payment_intent_to_order($order_id, (string)$pi['id'], (string)($pi['status'] ?? ''));

    return new WP_REST_Response(array(
      'ok' => true,
      'publishableKey' => $this->publishable_key(),
      'client_secret' => (string)($pi['client_secret'] ?? ''),
      'payment_intent_id' => (string)$pi['id'],
      'allowed_payment_methods' => array_values($payment_method_types),
      'order_id' => (int)$order_id,
      'customer_id' => $customer_id ? (string)$customer_id : '',
      'sku' => $sku,
      'label' => (string)($p['label'] ?? $sku),
      'amount_cents' => $final_amount_cents,
      'base_amount_cents' => $base_amount_cents,
      'addon_amount_cents' => $addon_amount_cents,
      'tax_cents' => $tax_cents,
      'tax_message' => (string)$tax_message,
      'tax_policy' => array(
        'policy_reason' => (string)($tax_policy['policy_reason'] ?? ''),
        'should_collect_tax' => !empty($tax_policy['should_collect_tax']),
        'state' => (string)($tax_policy['jurisdiction']['state'] ?? ''),
        'country' => (string)($tax_policy['jurisdiction']['country'] ?? 'US'),
        'registered' => null,
        'collect_enabled' => null,
        'stripe_controls_rollout' => true,
      ),
      'tax_calculation_id' => $tax_calc_id,
      'tax_debug' => array(
        'taxability_reason' => (string)($tax_result['taxability_reason'] ?? ''),
        'line_items' => (array)($tax_result['line_items'] ?? array()),
      ),
      'currency' => $currency,
      'product_type' => $product_type,
      'category' => (string)($p['category'] ?? ''),
      // For debugging. Remove later if you want.
      'metadata' => $metadata,
    ), 200);
  }


  public function rest_create_setup_intent(WP_REST_Request $req) {
    global $wpdb;

    $data = (array)$req->get_json_params();
    $email = sanitize_email((string)($data['email'] ?? ''));
    $context = isset($data['context']) && is_array($data['context']) ? $data['context'] : array();

    $instructor_id = isset($context['instructor_id']) ? absint($context['instructor_id']) : 0;
    $lesson_length = isset($context['lesson_length']) ? absint($context['lesson_length']) : 60;
    $lesson_mode = sanitize_text_field((string)($context['lesson_mode'] ?? 'online'));

    $repeat_frequency = sanitize_text_field((string)($context['repeat_frequency'] ?? 'none'));
    $repeat_duration = sanitize_text_field((string)($context['repeat_duration'] ?? 'indefinitely'));
    $authorized_lesson_count = isset($context['authorized_lesson_count']) ? absint($context['authorized_lesson_count']) : 0;

    $plan_kind = ($repeat_duration === 'indefinitely') ? 'indefinite' : 'bounded';
    if ($plan_kind === 'bounded' && $authorized_lesson_count <= 0) {
      $authorized_lesson_count = 1;
    }

    if (!$email || !is_email($email)) return new WP_REST_Response(array('ok'=>false,'message'=>'Valid email required.'), 400);
    if ($instructor_id <= 0) return new WP_REST_Response(array('ok'=>false,'message'=>'Valid instructor_id required.'), 400);

    $sku = 'lesson_' . ($lesson_length === 60 ? '60' : '30') . '_' . ($lesson_mode === 'online' ? 'online' : 'inperson');
    $p = $this->get_product($sku);
    if (!$p || empty($p['active'])) return new WP_REST_Response(array('ok'=>false,'message'=>'Unknown or inactive lesson SKU.'), 404);

    $base_amount = (int)($p['amount_cents'] ?? 0);
    $currency = strtolower((string)($p['currency'] ?? 'usd'));
    if ($base_amount <= 0) return new WP_REST_Response(array('ok'=>false,'message'=>'Invalid lesson price.'), 400);

    $customer_id = $this->stripe_find_or_create_customer($email);
    if (is_wp_error($customer_id)) return new WP_REST_Response(array('ok'=>false,'message'=>$customer_id->get_error_message()), 500);

    $email_hash = $this->email_hash($email);
    $now = current_time('mysql');

    $wpdb->insert($this->table_autopay_profiles(), array(
      'instructor_id' => (int)$instructor_id,
      'email_hash' => (string)$email_hash,
      'customer_id' => (string)$customer_id,
      'payment_method_id' => '',
      'currency' => $currency,
      'unit_base_cents' => (int)$base_amount,
      'plan_kind' => $plan_kind,
      'authorized_lesson_count' => (int)$authorized_lesson_count,
      'charged_lesson_count' => 0,
      'active' => 1,
      'detached_at' => null,
      'created_at' => $now,
      'updated_at' => $now,
    ), array('%d','%s','%s','%s','%s','%d','%s','%d','%d','%d','%s','%s','%s'));

    $autopay_profile_id = (int)$wpdb->insert_id;
    if ($autopay_profile_id <= 0) {
      return new WP_REST_Response(array('ok'=>false,'message'=>'Unable to create autopay profile.'), 500);
    }

    // This route is not your active frontend path, but this cleanup removes stale overlapping
    // logic that was guaranteed to fail if it ever got used again.

    $si = $this->stripe_create_setup_intent($customer_id, array(
      'mrm_autopay_profile_id' => (string)$autopay_profile_id,
      'mrm_instructor_id' => (string)$instructor_id,
      'mrm_customer_email' => (string)$email,
    ));

    if (is_wp_error($si)) {
      return new WP_REST_Response(array('ok'=>false,'message'=>$si->get_error_message()), 500);
    }

    return new WP_REST_Response(array(
      'ok' => true,
      'publishableKey' => $this->publishable_key(),
      'client_secret' => (string)($si['client_secret'] ?? ''),
      'setup_intent_id' => (string)($si['id'] ?? ''),
      'autopay_profile_id' => $autopay_profile_id,
      'currency' => $currency,
      'unit_base_cents' => $base_amount,
      'plan_kind' => $plan_kind,
      'authorized_lesson_count' => (int)$authorized_lesson_count,
    ), 200);
  }

  public function rest_finalize_autopay(WP_REST_Request $req) {
    global $wpdb;

    $data = (array)$req->get_json_params();
    $autopay_profile_id = isset($data['autopay_profile_id']) ? absint($data['autopay_profile_id']) : 0;
    $setup_intent_id = sanitize_text_field((string)($data['setup_intent_id'] ?? ''));

    if ($autopay_profile_id <= 0 || $setup_intent_id === '') {
      return new WP_REST_Response(array('ok'=>false,'message'=>'Missing autopay_profile_id or setup_intent_id.'), 400);
    }

    $profile = $this->mrm_get_autopay_profile($autopay_profile_id);
    if (!$profile) return new WP_REST_Response(array('ok'=>false,'message'=>'Autopay profile not found.'), 404);

    $si = $this->stripe_retrieve_setup_intent($setup_intent_id);
    if (is_wp_error($si)) return new WP_REST_Response(array('ok'=>false,'message'=>$si->get_error_message()), 500);

    $status = (string)($si['status'] ?? '');
    $customer_id = (string)($si['customer'] ?? '');
    $payment_method_id = (string)($si['payment_method'] ?? '');

    if ($status !== 'succeeded' || $payment_method_id === '') {
      return new WP_REST_Response(array('ok'=>false,'message'=>'SetupIntent not ready.'), 400);
    }

    if ($customer_id !== '' && $customer_id !== (string)$profile['customer_id']) {
      return new WP_REST_Response(array('ok'=>false,'message'=>'SetupIntent customer mismatch.'), 400);
    }

    $pm_ready = $this->mrm_ensure_customer_payment_method_ready((string)$profile['customer_id'], $payment_method_id);
    if (is_wp_error($pm_ready)) {
      error_log('[MRM Payments Hub] finalize-autopay payment-method readiness failed: ' . $pm_ready->get_error_message());
      return new WP_REST_Response(array('ok'=>false,'message'=>$pm_ready->get_error_message()), 400);
    }

    $wpdb->update($this->table_autopay_profiles(), array(
      'payment_method_id' => $payment_method_id,
      'active' => 1,
      'updated_at' => current_time('mysql'),
    ), array('id' => $autopay_profile_id), array('%s','%d','%s'), array('%d'));

    $pm_live = $this->stripe_retrieve_payment_method($payment_method_id);
    if (!is_wp_error($pm_live)) {
      $this->mrm_store_autopay_payment_method_snapshot(
        $autopay_profile_id,
        $payment_method_id,
        $pm_live,
        'ok',
        '',
        false
      );
    }

    return new WP_REST_Response(array(
      'ok' => true,
      'autopay_profile_id' => $autopay_profile_id,
      'payment_method_id' => $payment_method_id,
    ), 200);
  }

  public function rest_create_autopay_enrollment(WP_REST_Request $req) {
    global $wpdb;

    $data = (array)$req->get_json_params();
    $payment_intent_id = sanitize_text_field((string)($data['payment_intent_id'] ?? ''));
    $email = sanitize_email((string)($data['email'] ?? ''));
    $context = isset($data['context']) && is_array($data['context']) ? $data['context'] : array();

    $instructor_id = isset($context['instructor_id']) ? absint($context['instructor_id']) : 0;
    $lesson_length = isset($context['lesson_length']) ? absint($context['lesson_length']) : 60;
    $lesson_mode = sanitize_text_field((string)($context['lesson_mode'] ?? 'online'));
    $repeat_frequency = sanitize_text_field((string)($context['repeat_frequency'] ?? 'none'));
    $repeat_duration = sanitize_text_field((string)($context['repeat_duration'] ?? 'indefinitely'));
    $authorized_lesson_count = isset($context['authorized_lesson_count']) ? absint($context['authorized_lesson_count']) : 0;

    if ($payment_intent_id === '') {
      return new WP_REST_Response(array('ok'=>false,'message'=>'Missing payment_intent_id.'), 400);
    }
    if (!$email || !is_email($email)) {
      return new WP_REST_Response(array('ok'=>false,'message'=>'Valid email required.'), 400);
    }
    if ($instructor_id <= 0) {
      return new WP_REST_Response(array('ok'=>false,'message'=>'Valid instructor_id required.'), 400);
    }

    $pi = $this->stripe_retrieve_payment_intent($payment_intent_id);
    if (is_wp_error($pi)) {
      return new WP_REST_Response(array('ok'=>false,'message'=>$pi->get_error_message()), 500);
    }

    $status = (string)($pi['status'] ?? '');
    $customer_id = (string)($pi['customer'] ?? '');
    $payment_method_id = (string)($pi['payment_method'] ?? '');

    if ($status !== 'succeeded') {
      return new WP_REST_Response(array('ok'=>false,'message'=>'PaymentIntent not succeeded.'), 400);
    }
    if ($customer_id === '' || $payment_method_id === '') {
      return new WP_REST_Response(array('ok'=>false,'message'=>'Saved card details were not attached to the successful payment.'), 400);
    }

    $pm_ready = $this->mrm_ensure_customer_payment_method_ready($customer_id, $payment_method_id);
    if (is_wp_error($pm_ready)) {
      return new WP_REST_Response(array('ok'=>false,'message'=>$pm_ready->get_error_message()), 400);
    }

    $sku = 'lesson_' . ($lesson_length === 60 ? '60' : '30') . '_' . ($lesson_mode === 'online' ? 'online' : 'inperson');
    $p = $this->get_product($sku);
    if (!$p || empty($p['active'])) {
      return new WP_REST_Response(array('ok'=>false,'message'=>'Unknown or inactive lesson SKU.'), 404);
    }

    $base_amount = (int)($p['amount_cents'] ?? 0);
    $currency = strtolower((string)($p['currency'] ?? 'usd'));
    if ($base_amount <= 0) {
      return new WP_REST_Response(array('ok'=>false,'message'=>'Invalid lesson price.'), 400);
    }

    $plan_kind = ($repeat_duration === 'indefinitely') ? 'indefinite' : 'bounded';
    if ($plan_kind === 'bounded' && $authorized_lesson_count <= 0) {
      $authorized_lesson_count = 1;
    }

    $email_hash = $this->email_hash($email);
    $now = current_time('mysql');

    $pm_live = $this->stripe_retrieve_payment_method($payment_method_id);
    $pm_snap = !is_wp_error($pm_live) ? $this->mrm_extract_card_snapshot_from_payment_method($pm_live) : array(
      'brand' => '',
      'last4' => '',
      'exp_month' => 0,
      'exp_year' => 0,
    );

    $wpdb->insert($this->table_autopay_profiles(), array(
      'instructor_id' => (int)$instructor_id,
      'email_hash' => (string)$email_hash,
      'customer_id' => (string)$customer_id,
      'payment_method_id' => (string)$payment_method_id,
      'currency' => $currency,
      'unit_base_cents' => (int)$base_amount,
      'plan_kind' => $plan_kind,
      'authorized_lesson_count' => (int)$authorized_lesson_count,
      'charged_lesson_count' => 0,
      'pm_brand' => (string)$pm_snap['brand'],
      'pm_last4' => (string)$pm_snap['last4'],
      'pm_exp_month' => (int)$pm_snap['exp_month'],
      'pm_exp_year' => (int)$pm_snap['exp_year'],
      'pm_last_checked_at' => $now,
      'pm_attention_status' => 'ok',
      'pm_attention_reason' => '',
      'pm_attention_notified_at' => null,
      'active' => 1,
      'detached_at' => null,
      'created_at' => $now,
      'updated_at' => $now,
    ), array('%d','%s','%s','%s','%s','%d','%s','%d','%d','%s','%s','%d','%d','%s','%s','%s','%d','%s','%s','%s'));

    $autopay_profile_id = (int)$wpdb->insert_id;
    if ($autopay_profile_id <= 0) {
      return new WP_REST_Response(array('ok'=>false,'message'=>'Unable to create autopay profile.'), 500);
    }

    $validated_profile = $this->mrm_validate_autopay_profile_for_charge(
      $this->mrm_get_autopay_profile($autopay_profile_id)
    );

    if ( is_wp_error($validated_profile) ) {
      return new WP_Error(
        'mrm_autopay_profile_incomplete',
        $validated_profile->get_error_message(),
        array('status' => 500)
      );
    }

    $order = $this->get_order_by_pi($payment_intent_id);
    if ($order) {
      $meta = array();
      if (!empty($order['metadata_json'])) {
        $decoded = json_decode((string)$order['metadata_json'], true);
        if (is_array($decoded)) $meta = $decoded;
      }

      $meta['mrm_autopay_enrollment'] = 'yes';
      $meta['mrm_autopay_profile_id'] = (string)$autopay_profile_id;
      $meta['mrm_plan_kind'] = $plan_kind;
      $meta['mrm_authorized_lesson_count'] = (string)$authorized_lesson_count;
      $meta['mrm_first_lesson_prepaid'] = 'yes';

      $this->update_order_status_from_pi($payment_intent_id, (string)($order['status'] ?? 'paid'), 'succeeded', $meta);
    }

    error_log('[MRM Payments Hub] autopay enrollment created'
      . ' profile_id=' . $autopay_profile_id
      . ' instructor_id=' . (int)$instructor_id
      . ' customer_id=' . $customer_id
      . ' payment_method_id=' . $payment_method_id
      . ' plan_kind=' . $plan_kind
      . ' authorized_lesson_count=' . (int)$authorized_lesson_count
      . ' unit_base_cents=' . (int)$base_amount
    );

    return new WP_REST_Response(array(
      'ok' => true,
      'autopay_profile_id' => $autopay_profile_id,
      'customer_id' => $customer_id,
      'payment_method_id' => $payment_method_id,
      'plan_kind' => $plan_kind,
      'authorized_lesson_count' => (int)$authorized_lesson_count,
    ), 200);
  }


  public function rest_update_tax(WP_REST_Request $req) {
    $data = (array) $req->get_json_params();
    $order_id = isset($data['order_id']) ? absint($data['order_id']) : 0;
    $address = (isset($data['address']) && is_array($data['address'])) ? $data['address'] : array();

    $address = $this->mrm_normalize_tax_address($address);

    if ($order_id <= 0) return new WP_REST_Response(array('ok'=>false,'message'=>'Missing order_id.'), 400);

    $order = $this->get_order($order_id);
    if (!$order) return new WP_REST_Response(array('ok'=>false,'message'=>'Order not found.'), 404);

    $pi_id = (string)($order['stripe_payment_intent_id'] ?? '');
    if (!$pi_id) return new WP_REST_Response(array('ok'=>false,'message'=>'PaymentIntent not attached yet.'), 409);

    // Only allow updating tax for unpaid orders
    $status = (string)($order['status'] ?? '');
    // NOTE: your orders start as 'created' in create_order(). Allow both 'created' and 'pending' here.
    if ($status && !in_array($status, array('created','pending'), true)) {
      return new WP_REST_Response(array('ok'=>false,'message'=>'Order is not pending.'), 409);
    }

    $meta = array();
    if (!empty($order['metadata_json'])) {
      $decoded = json_decode((string)$order['metadata_json'], true);
      if (is_array($decoded)) $meta = $decoded;
    }

    $product_type = (string)($order['product_type'] ?? ($meta['mrm_product_type'] ?? ''));
    $base_amount_cents = isset($meta['mrm_base_amount_cents']) ? (int)$meta['mrm_base_amount_cents'] : (int)($order['amount_cents'] ?? 0);
    $addon_amount_cents = isset($meta['mrm_addon_amount_cents']) ? (int)$meta['mrm_addon_amount_cents'] : 0;

    $currency = strtolower((string)($order['currency'] ?? 'usd'));

    $addon_selected = ($addon_amount_cents > 0);
    $tax_policy = $this->mrm_build_tax_policy($address, $product_type, $addon_selected, array(), $meta);
    $taxable_items = $this->mrm_build_taxable_items_from_policy($tax_policy, $base_amount_cents, $addon_amount_cents);

    $tax_result = array(
      'ok' => true,
      'tax_cents' => 0,
      'amount_total_cents' => ($base_amount_cents + $addon_amount_cents),
      'calculation_id' => '',
      'line_items' => array(),
      'taxability_reason' => (string)($tax_policy['policy_reason'] ?? ''),
    );

    if (!empty($tax_policy['should_collect_tax']) && !empty($taxable_items)) {
      $tax_result = $this->mrm_tax_calculate_for_items($address, $taxable_items, $currency);
    } else {
      $this->stripe_debug_log('update_tax skipped before stripe tax request', array(
        'order_id' => $order_id,
        'product_type' => $product_type,
        'policy_reason' => (string)($tax_policy['policy_reason'] ?? ''),
        'policy_message' => (string)($tax_policy['policy_message'] ?? ''),
        'address' => $address,
      ));
    }

    $tax_cents = (!empty($tax_result['ok'])) ? (int)($tax_result['tax_cents'] ?? 0) : 0;
    $tax_calc_id = (!empty($tax_result['ok'])) ? (string)($tax_result['calculation_id'] ?? '') : '';
    $tax_message = $this->mrm_build_tax_display_message($tax_policy, $tax_result, $tax_cents);

    $new_total_cents = $base_amount_cents + $addon_amount_cents + $tax_cents;

    // Update Stripe PaymentIntent amount (before confirmation)
    $pi = $this->stripe_api_request('POST', '/v1/payment_intents/' . $pi_id, array(
      'amount' => $new_total_cents,
    ));
    if (is_wp_error($pi)) {
      return new WP_REST_Response(array('ok'=>false,'message'=>$pi->get_error_message()), 500);
    }

    // Persist into order metadata so receipts and ledger remain consistent
    $meta['mrm_tax_cents'] = (string)$tax_cents;
    $meta['mrm_tax_calculation_id'] = (string)$tax_calc_id;
    $meta['mrm_addon_tax_cents'] = (string)$tax_cents; // back-compat
    $meta['mrm_tax_country'] = (string)($address['country'] ?? 'US');
    $meta['mrm_tax_state'] = (string)($address['state'] ?? '');
    $meta['mrm_tax_postal_code'] = (string)($address['postal_code'] ?? '');
    $meta['mrm_tax_line1'] = (string)($address['line1'] ?? '');
    $meta['mrm_tax_rollout_mode'] = 'stripe_only';
    $meta['mrm_tax_calculation_requested'] = !empty($tax_policy['should_collect_tax']) ? 'yes' : 'no';
    $meta['mrm_tax_policy_reason'] = (string)($tax_policy['policy_reason'] ?? '');
    $meta['mrm_tax_policy_message'] = (string)$tax_message;
    $meta['mrm_taxability_reason'] = (string)($tax_result['taxability_reason'] ?? '');

    $this->update_order_amount_and_metadata($order_id, $new_total_cents, $meta);

    return new WP_REST_Response(array(
      'ok' => true,
      'order_id' => (int)$order_id,
      'payment_intent_id' => $pi_id,
      'base_amount_cents' => (int)$base_amount_cents,
      'addon_amount_cents' => (int)$addon_amount_cents,
      'tax_cents' => (int)$tax_cents,
      'tax_message' => (string)$tax_message,
      'tax_policy' => array(
        'policy_reason' => (string)($tax_policy['policy_reason'] ?? ''),
        'should_collect_tax' => !empty($tax_policy['should_collect_tax']),
        'state' => (string)($tax_policy['jurisdiction']['state'] ?? ''),
        'country' => (string)($tax_policy['jurisdiction']['country'] ?? 'US'),
        'registered' => null,
        'collect_enabled' => null,
        'stripe_controls_rollout' => true,
      ),
      'tax_calculation_id' => $tax_calc_id,
      'tax_debug' => array(
        'taxability_reason' => (string)($tax_result['taxability_reason'] ?? ''),
        'line_items' => (array)($tax_result['line_items'] ?? array()),
      ),
      'total_cents' => (int)$new_total_cents,
      'currency' => $currency,
    ), 200);
  }

  private function mrm_maybe_create_sheet_music_subscription_from_initial_payment_intent($pi, $order = array()) {
    if (!is_array($pi)) return;

    $pi_id = (string)($pi['id'] ?? '');
    if ($pi_id === '') return;

    if (!is_array($order) || empty($order)) {
      $order = $this->get_order_by_pi($pi_id);
    }

    if (!is_array($order) || empty($order)) {
      return;
    }

    $order_id = (int)($order['id'] ?? 0);
    if ($order_id <= 0) return;

    $this->mrm_attempt_sheet_music_subscription_activation($order_id, $pi, 'initial_payment_intent_helper');
  }

  private function mrm_retry_sheet_music_subscription_creation_for_order($order_id) {
    $order_id = (int)$order_id;
    if ($order_id <= 0) return false;

    $order = $this->get_order($order_id);
    if (!is_array($order) || empty($order)) {
      return false;
    }

    $pi_id = (string)($order['stripe_payment_intent_id'] ?? ($order['payment_intent_id'] ?? ''));
    if ($pi_id === '') {
      $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'missing_payment_intent');
      $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', 'Missing payment_intent_id on order.');
      return false;
    }

    $pi = $this->stripe_retrieve_payment_intent($pi_id);
    if (is_wp_error($pi)) {
      $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'payment_intent_retrieve_failed');
      $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', $pi->get_error_message());
      return false;
    }

    $this->stripe_debug_log('subscription retry helper invoked', array(
      'order_id' => $order_id,
      'pi_id' => $pi_id,
      'pi_status' => (string)($pi['status'] ?? ''),
      'customer_id' => (string)($pi['customer'] ?? ''),
      'payment_method_id' => (string)($pi['payment_method'] ?? ''),
    ));

    $result = $this->mrm_attempt_sheet_music_subscription_activation($order_id, $pi, 'retry_helper');

    $this->mrm_subscription_debug_log('retry helper completed', array(
      'order_id' => $order_id,
      'result' => ($result ? 'true' : 'false'),
      'pi_id' => $pi_id,
    ));

    return $result;
  }


  public function rest_verify_payment_intent(WP_REST_Request $req) {
    $data = (array) $req->get_json_params();
    $pi_id = sanitize_text_field((string)($data['payment_intent_id'] ?? ''));

    if (!$pi_id) return new WP_REST_Response(array('ok'=>false,'message'=>'Missing payment_intent_id.'), 400);

    $pi = $this->stripe_retrieve_payment_intent($pi_id);
    if (is_wp_error($pi)) return new WP_REST_Response(array('ok'=>false,'message'=>$pi->get_error_message()), 500);

    $status = (string)($pi['status'] ?? '');
    $terminal_statuses = array('succeeded', 'requires_capture');
    $fail_statuses = array('requires_payment_method', 'canceled');

    $ok = in_array($status, $terminal_statuses, true);

    $piece_auto_grant_attempted = false;
    $piece_auto_grant_success = false;

    // If this PaymentIntent was created with a customer + setup_future_usage,
    // Stripe should attach the payment method to the customer.
    // We log proof and force-set default PM for off-session charges.
    if ($ok) {
      $customer_id = isset($pi['customer']) ? (string)$pi['customer'] : '';
      $pm_id = isset($pi['payment_method']) ? (string)$pi['payment_method'] : '';
      $sfu = isset($pi['setup_future_usage']) ? (string)$pi['setup_future_usage'] : '';

      if ($customer_id && $pm_id) {
        error_log('[MRM Payments Hub] verify PI succeeded. customer=' . $customer_id . ' pm=' . $pm_id . ' setup_future_usage=' . $sfu);

        $pm_ready = $this->mrm_ensure_customer_payment_method_ready($customer_id, $pm_id);
        if (is_wp_error($pm_ready)) {
          error_log('[MRM Payments Hub] verify PI payment-method readiness failed: ' . $pm_ready->get_error_message() . ' data=' . wp_json_encode($pm_ready->get_error_data()));
        } else {
          error_log('[MRM Payments Hub] payment method ready for customer=' . $customer_id);
        }
      } else {
        // If user expected autopay but no customer/pm is present, log it loudly
        error_log('[MRM Payments Hub] verify PI succeeded but missing customer/pm. customer=' . $customer_id . ' pm=' . $pm_id . ' setup_future_usage=' . $sfu);
      }
    }

    $meta = isset($pi['metadata']) && is_array($pi['metadata']) ? $pi['metadata'] : array();
    $addon_yes = (isset($meta['mrm_sheet_music_addon']) && strtolower((string)$meta['mrm_sheet_music_addon']) === 'yes');

    $start_ts = 0;
    if (isset($pi['charges']['data'][0]['created'])) {
      $start_ts = (int)$pi['charges']['data'][0]['created'];
    } elseif (isset($pi['created'])) {
      $start_ts = (int)$pi['created'];
    } else {
      $start_ts = time();
    }

    // Scheduler lesson addon path (existing behavior)
    if ($ok && $addon_yes) {
      $email = sanitize_email((string)($meta['mrm_customer_email'] ?? ''));
      if ($email && is_email($email)) {
        $this->mrm_grant_all_sheet_music_ledger($email, $start_ts, 'stripe_pi_addon', $pi_id);
      }
    }

    // ✅ Piece-product path (new): auto-grant specific sheet-music SKU from PI metadata,
    // same backend-driven pattern as scheduler verify flow.
    if ($ok) {
      $pi_product_type = sanitize_text_field((string)($meta['mrm_product_type'] ?? ''));
      $pi_sku = $this->sanitize_sku((string)($meta['mrm_sku'] ?? ''));
      $pi_email = sanitize_email((string)($meta['mrm_customer_email'] ?? ''));

      if ($pi_product_type === 'sheet_music' && $pi_sku && $pi_email && is_email($pi_email)) {
        // Do not double-handle the scheduler addon master SKU here.
        if ($pi_sku !== $this->master_sheet_music_sku()) {
          $pi_product = $this->get_product($pi_sku);

          if ($pi_product && !empty($pi_product['active']) && (string)($pi_product['product_type'] ?? '') === 'sheet_music') {
            $email_hash = $this->email_hash($pi_email);
            $piece_auto_grant_attempted = true;
            $granted = $this->grant_sheet_music_access(
              $email_hash,
              $pi_email,
              $pi_sku,
              'stripe_pi_verify',
              $pi_id,
              $start_ts
            );

            $piece_auto_grant_success = (bool) $granted;

            if (!$granted) {
              error_log('[MRM Payments Hub] verify PI auto-grant failed for piece sku=' . $pi_sku . ' pi=' . $pi_id);
            }
          }
        }
      }
    }

    // Update local order status if it exists
    $latest_charge = '';
    if (!empty($pi['latest_charge'])) {
      $latest_charge = (string)$pi['latest_charge'];
    }

    $order = $this->get_order_by_pi($pi_id);
    if ($order) {
      $new_status = $ok ? 'paid' : (in_array($status, $fail_statuses, true) ? 'failed' : 'created');
      $metadata = $pi['metadata'] ?? null;
      if (is_array($metadata)) {
        $metadata['mrm_latest_charge_id'] = $latest_charge;
      } else {
        $metadata = array('mrm_latest_charge_id' => $latest_charge);
      }
      $this->update_order_status_from_pi($pi_id, $new_status, $status, $metadata);
    }

    // Send custom receipt email once (idempotent)
    if ($ok && $order) {
      $order = $this->get_order_by_pi($pi_id);
      $this->mrm_maybe_send_purchase_receipt_email($pi, $order);
      $this->mrm_maybe_create_payout_ledger_for_order($order);

      $meta = isset($pi['metadata']) && is_array($pi['metadata']) ? $pi['metadata'] : array();
      $addon_yes = (isset($meta['mrm_sheet_music_addon']) && strtolower((string)$meta['mrm_sheet_music_addon']) === 'yes');
      $product_type = (string)($order['product_type'] ?? '');

      $this->mrm_subscription_debug_log('verify endpoint loaded order before activation', array(
        'order_id' => (int)($order['id'] ?? 0),
        'order_status' => (string)($order['status'] ?? ''),
        'order_payment_intent_id' => (string)($order['stripe_payment_intent_id'] ?? ($order['payment_intent_id'] ?? '')),
        'passed_pi_id' => (string)($pi['id'] ?? ''),
      ));

      if ($addon_yes && $product_type === 'lesson' && !empty($order['id'])) {
        $this->mrm_attempt_sheet_music_subscription_activation((int)$order['id'], $pi, 'verify_endpoint');
      }
    }

    return new WP_REST_Response(array(
      'ok' => $ok,
      'status' => $status,
      'amount_cents' => (int)($pi['amount'] ?? 0),
      'currency' => (string)($pi['currency'] ?? 'usd'),
      'metadata' => (array)($pi['metadata'] ?? array()),
      'piece_auto_grant_attempted' => (bool)$piece_auto_grant_attempted,
      'piece_auto_grant_success' => (bool)$piece_auto_grant_success,
    ), 200);
  }

  private function mrm_wrap_transactional_email_html($title, $intro_html, $details_html, $button_url = '', $button_text = '', $options = array()) {
    $brand = '#780000';
    $options = is_array($options) ? $options : array();
    $button_color = isset($options['button_color']) ? (string)$options['button_color'] : $brand;
    $button_align = isset($options['button_align']) ? (string)$options['button_align'] : 'center';
    $site = esc_html(get_bloginfo('name'));

    $btn_html = '';
    if ($button_url) {
      $btn_html = '<div style="text-align:' . esc_attr($button_align) . ';margin:22px 0 10px 0;">
      <a href="' . esc_url($button_url) . '" style="display:inline-block;background:' . esc_attr($button_color) . ';color:#ffffff;text-decoration:none;font-weight:700;padding:12px 18px;border-radius:10px;">
        ' . esc_html($button_text ? $button_text : 'Open Link') . '
      </a>
    </div>
    <div style="text-align:center;font-size:12px;color:#666;margin-top:10px;">
      If the button doesn’t work, copy and paste this link:<br/>
      <span style="word-break:break-all;">' . esc_html($button_url) . '</span>
    </div>';
    }

    return '<!doctype html><html><body style="margin:0;padding:0;background:#f6f6f6;">
    <div style="max-width:640px;margin:0 auto;padding:24px;">
      <div style="background:#ffffff;border-radius:16px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,0.06);font-family:Arial,sans-serif;">
        <h1 style="margin:0 0 10px 0;font-size:20px;line-height:1.3;color:#111;">' . esc_html($title) . '</h1>
        <div style="font-size:14px;line-height:1.6;color:#222;">' . $intro_html . '</div>
        <div style="margin-top:14px;padding:14px;border:1px solid #eee;border-radius:12px;background:#fafafa;font-size:14px;line-height:1.6;color:#222;">
          ' . $details_html . '
        </div>
        ' . $btn_html . '
        <div style="margin-top:20px;font-size:12px;color:#777;text-align:center;">
          ' . $site . '
        </div>
      </div>
    </div>
  </body></html>';
  }

  private function mrm_build_piece_access_instructions_html($email, $sku) {
    $email = sanitize_email((string)$email);
    $sku   = $this->sanitize_sku((string)$sku);

    $details  = '<div><strong>Email:</strong> ' . esc_html($email) . '</div>';
    $details .= '<div><strong>Product:</strong> ' . esc_html($sku) . '</div>';

    $intro  = '<p>Thank you for your purchase.</p>';
    $intro .= '<p>Your piece access has been granted successfully.</p>';
    $intro .= '<p><strong>How to access your purchased content:</strong></p>';
    $intro .= '<ol style="margin:10px 0 0 18px; padding:0;">';
    $intro .= '<li>Return to the piece page on the website.</li>';
    $intro .= '<li>Click the access button for your purchased version.</li>';
    $intro .= '<li>Enter this email address: <strong>' . esc_html($email) . '</strong></li>';
    $intro .= '<li>Request your access code and enter it to open the purchased content.</li>';
    $intro .= '</ol>';
    $intro .= '<p style="margin-top:14px;">If you have trouble accessing your files, please contact support.</p>';

    return $this->mrm_wrap_transactional_email_html(
      'Piece Purchase Confirmation',
      $intro,
      $details,
      '',
      '',
      array()
    );
  }

  private function mrm_send_piece_purchase_confirmation_email($email, $sku) {
    $email = sanitize_email((string)$email);
    $sku   = $this->sanitize_sku((string)$sku);

    if (!$email || !is_email($email) || !$sku) {
      return false;
    }

    $subject = 'Piece Purchase Confirmation';
    $body    = $this->mrm_build_piece_access_instructions_html($email, $sku);

    $sent = wp_mail(
      $email,
      $subject,
      $body,
      array(
        'Content-Type: text/html; charset=UTF-8',
        'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
      )
    );

    error_log('[MRM Payments Hub] piece purchase confirmation email ' . wp_json_encode(array(
      'email' => $email,
      'sku'   => $sku,
      'sent'  => $sent ? 'yes' : 'no',
    )));

    return $sent;
  }

  public function rest_grant_sheet_music_access(WP_REST_Request $req) {
    $data = (array) $req->get_json_params();

    // Accept sku or product_slug (canonical is product_slug)
    $incoming_slug = isset($data['product_slug']) ? $data['product_slug'] : ($data['sku'] ?? '');
    $sku   = $this->sanitize_sku($incoming_slug);
    $email = sanitize_email((string)($data['email'] ?? ''));
    $pi_id = sanitize_text_field((string)($data['payment_intent_id'] ?? ''));

    // If payment_intent_id is provided, verify it succeeded and bind grant inputs to PI metadata.
    // This prevents stale/mismatched frontend state from granting the wrong product/email.
    if ($pi_id) {
      $pi = $this->stripe_retrieve_payment_intent($pi_id);
      if (is_wp_error($pi)) {
        return new WP_REST_Response(array('ok'=>false,'message'=>$pi->get_error_message()), 500);
      }

      $status = (string)($pi['status'] ?? '');
      if ($status !== 'succeeded') {
        return new WP_REST_Response(array('ok'=>false,'message'=>'PaymentIntent not succeeded.'), 400);
      }

      $meta = isset($pi['metadata']) && is_array($pi['metadata']) ? $pi['metadata'] : array();

      $pi_sku = $this->sanitize_sku((string)($meta['mrm_sku'] ?? ''));
      $pi_email = sanitize_email((string)($meta['mrm_customer_email'] ?? ''));

      // Prefer PI metadata as the source of truth when available.
      if ($pi_sku) {
        if ($sku && $sku !== $pi_sku) {
          error_log('[MRM Payments Hub] grant-sheet-music-access sku mismatch; request=' . $sku . ' pi_meta=' . $pi_sku . ' pi=' . $pi_id);
        }
        $sku = $pi_sku;
      }

      if ($pi_email && is_email($pi_email)) {
        if ($email && strtolower($email) !== strtolower($pi_email)) {
          error_log('[MRM Payments Hub] grant-sheet-music-access email mismatch; request=' . $email . ' pi_meta=' . $pi_email . ' pi=' . $pi_id);
        }
        $email = $pi_email;
      }
    }

    if (!$sku) return new WP_REST_Response(array('ok'=>false,'message'=>'Missing sku.'), 400);
    if (!$email || !is_email($email)) return new WP_REST_Response(array('ok'=>false,'message'=>'Valid email required.'), 400);

    $p = $this->get_product($sku);
    if (!$p || empty($p['active'])) {
      return new WP_REST_Response(array('ok'=>false,'message'=>'Unknown or inactive sku.'), 404);
    }

    $product_type = (string)($p['product_type'] ?? 'unknown');
    if ($product_type !== 'sheet_music') {
      return new WP_REST_Response(array('ok'=>false,'message'=>'SKU is not sheet_music.'), 400);
    }

    // DB guard (updates can skip activation hook)
    $this->maybe_install_or_upgrade_db();

    // Verify access table exists
    global $wpdb;
    $access_table = $this->table_sheet_music_access();
    $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $access_table));
    if ($found !== $access_table) {
      error_log('[MRM Payments Hub] grant access failed: missing table ' . $access_table);
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Server is updating. Please try again in a moment.',
        'debug' => 'missing_access_table'
      ), 500);
    }

    $email_hash = $this->email_hash($email);

    $ok = $this->grant_sheet_music_access($email_hash, $email, $sku, $pi_id ? 'stripe_pi' : 'manual', $pi_id);
    if (!$ok) {
      $last = $wpdb->last_error ? $wpdb->last_error : 'unknown_db_error';
      error_log('[MRM Payments Hub] grant_sheet_music_access insert failed sku=' . $sku . ' email_hash=' . $email_hash . ' err=' . $last);

      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Failed to grant access.',
        'debug' => 'db_insert_failed'
      ), 500);
    }

    // ✅ Access source of truth: DB ledger row only.
    // Do NOT also mirror into the per-product "Approved Emails (OTP Access)" product UI.

    $this->mrm_send_piece_purchase_confirmation_email($email, $sku);

    return new WP_REST_Response(array(
      'ok' => true,
      'email_hash' => $email_hash,
      'sku' => $sku,
    ), 200);
  }


  public function rest_has_access(WP_REST_Request $req) {
    $data = (array) $req->get_json_params();
    $sku   = $this->sanitize_sku($data['sku'] ?? '');
    $email = sanitize_email((string)($data['email'] ?? ''));

    // Privacy-safe: do not leak whether access exists unless caller already has context.
    // We still return ok with has_access boolean (needed for internal systems / admin tools).
    if (!$sku) {
      return new WP_REST_Response(array('ok' => false, 'message' => 'Missing sku.'), 400);
    }
    if (!$email || !is_email($email)) {
      return new WP_REST_Response(array('ok' => false, 'message' => 'Valid email required.'), 400);
    }

    $email_hash = $this->email_hash($email);

    global $wpdb;
    $table = $wpdb->prefix . 'mrm_sheet_music_access';

    // If table doesn't exist, fail closed.
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) {
      return new WP_REST_Response(array('ok' => true, 'sku' => $sku, 'has_access' => false), 200);
    }

    $id = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$table} WHERE email_hash=%s AND sku=%s AND revoked_at IS NULL LIMIT 1",
      (string)$email_hash,
      (string)$sku
    ));

    return new WP_REST_Response(array(
      'ok' => true,
      'sku' => $sku,
      'has_access' => !empty($id),
    ), 200);
  }

  private function grant_sheet_music_access($email_hash, $email_plain, $sku, $source = null, $source_id = null, $start_ts = null) {
    global $wpdb;
    $table = $this->table_sheet_music_access();

    // Default to "now", but allow caller to pass Stripe charge/create timestamp.
    $now_mysql = current_time('mysql');
    $start_at_mysql = $now_mysql;

    if ($start_ts !== null && is_numeric($start_ts) && (int)$start_ts > 0) {
      $start_at_mysql = gmdate('Y-m-d H:i:s', (int)$start_ts);
    }

    // If already granted (active row exists), treat as idempotent success.
    $existing = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$table} WHERE email_hash = %s AND sku = %s AND revoked_at IS NULL LIMIT 1",
      $email_hash, $sku
    ));

    if ($existing) {
      // Back-compat mirror: ensure legacy email-based access list is also populated.
      // DB ledger remains source of truth.
      if ($email_plain && is_email($email_plain)) {
        $this->add_email_to_access_list($sku, $email_plain);
      }
      return true;
    }

    $ins = $wpdb->insert($table, array(
      'email_hash'  => $email_hash,
      'email_plain' => $email_plain,
      'sku'         => $sku,
      'start_at'    => $start_at_mysql,
      'expires_at'  => null,              // piece purchases never expire
      'month_key'   => null,
      'granted_at'  => $now_mysql,
      'revoked_at'  => null,
      'source'      => $source,
      'source_id'   => $source_id,
    ), array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'));

    if ($ins) {
      // Back-compat mirror: also write to email-based access list UI data.
      // This keeps older UI paths and expectations in sync with DB ledger rows.
      if ($email_plain && is_email($email_plain)) {
        $this->add_email_to_access_list($sku, $email_plain);
      }
      return true;
    }

    return false;
  }

  public function grant_all_sheet_music_db_row($email, $source = null, $source_id = null) {
    $email = sanitize_email((string)$email);
    if (!$email || !is_email($email)) return false;

    $this->maybe_install_or_upgrade_db();
    return $this->mrm_grant_all_sheet_music_ledger($email, time(), $source, $source_id);
  }

  public function has_sheet_music_access($email_hash, $sku) {
    global $wpdb;
    $table = $this->table_sheet_music_access();
    $id = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$table} WHERE email_hash = %s AND sku = %s AND revoked_at IS NULL LIMIT 1",
      $email_hash, $sku
    ));
    return !empty($id);
  }

  public function email_has_access_for_slug($email, $product_slug) {
    $email = sanitize_email((string)$email);
    if (!$email || !is_email($email)) return false;

    $product_slug = $this->sanitize_product_slug($product_slug);
    if (!$product_slug) return false;

    $lists = $this->all_access_lists();

    // Rule 1: Stripe is source-of-truth for all-sheet-music subscription access.
    $subscription_status = $this->mrm_get_sheet_music_subscription_access_status_by_email($email);
    if (!empty($subscription_status['has_access'])) {
      return true;
    }

    if ($product_slug === 'all-sheet-music') {
      return false;
    }

    // Rule 2: per-product list
    $per = isset($lists[$product_slug]) && is_array($lists[$product_slug])
      ? $lists[$product_slug] : array();
    if (in_array(strtolower($email), $per, true)) return true;

    // Rule 3: DB table check (idempotent truth)
    $email_hash = $this->email_hash($email);
    return $this->has_sheet_music_access($email_hash, $product_slug);
  }

  public function add_email_to_access_list($product_slug, $email) {
    $product_slug = $this->sanitize_product_slug($product_slug);
    $email = sanitize_email((string)$email);
    if (!$product_slug || !$email || !is_email($email)) return false;

    $lists = $this->all_access_lists();
    if (!isset($lists[$product_slug]) || !is_array($lists[$product_slug])) {
      $lists[$product_slug] = array();
    }
    $lists[$product_slug][] = strtolower($email);
    $lists[$product_slug] = array_values(array_unique($lists[$product_slug]));
    $this->save_access_lists($lists);
    return true;
  }

  public function cleanup_access() {
    $this->mrm_prune_expired_all_sheet_music();
  }

  /* =========================================================
   * Admin UI
   * ======================================================= */

  public function admin_menu() {
    add_menu_page(
      'MRM Payments Hub',
      'MRM Payments',
      'manage_options',
      self::MENU_SLUG,
      array($this, 'render_admin_page'),
      'dashicons-cart',
      57
    );

    add_submenu_page(
      self::MENU_SLUG,
      'Sheet Music Access',
      'Sheet Music Access',
      'manage_options',
      'mrm-pay-hub-access',
      array($this, 'render_access_lists_page')
    );
  }

  public function handle_admin_post() {
    if (!is_admin()) return;
    if (!current_user_can('manage_options')) return;

    if (isset($_POST['mrm_pay_hub_test_aws_nonce']) && wp_verify_nonce($_POST['mrm_pay_hub_test_aws_nonce'], 'mrm_pay_hub_test_aws')) {
      $this->mrm_aws_debug_log('Stripe AWS test button pressed.');
      $result = $this->stripe_api_request('GET', '/v1/account');

      if (is_wp_error($result)) {
        $this->mrm_aws_debug_log('Stripe AWS test failed.', array(
          'error_code' => $result->get_error_code(),
          'error_message' => $result->get_error_message(),
        ));
        add_settings_error(
          'mrm_pay_hub',
          'aws_test_failed',
          'Stripe AWS test failed: ' . $result->get_error_message(),
          'error'
        );
      } else {
        $acct_id = isset($result['id']) ? (string)$result['id'] : '';
        $this->mrm_aws_debug_log('Stripe AWS test succeeded.', array(
          'account_id' => isset($result['id']) ? (string)$result['id'] : '',
        ));
        add_settings_error(
          'mrm_pay_hub',
          'aws_test_ok',
          'Stripe AWS test succeeded. Connected account response received' . ($acct_id !== '' ? ' (' . $acct_id . ')' : '') . '.',
          'updated'
        );
      }
    }

    // Repair default lesson SKUs if they were wiped to 0 by a prior update bug
    $this->ensure_default_products();

    if (isset($_POST['mrm_pay_hub_nonce']) && wp_verify_nonce($_POST['mrm_pay_hub_nonce'], 'mrm_pay_hub_save')) {
      $settings = $this->get_settings();
      // AWS / wp-config managed Stripe credentials are no longer stored in WordPress settings.
      $settings['stripe_publishable_key'] = '';
      $settings['stripe_secret_key'] = '';
      $settings['stripe_webhook_secret'] = '';
      $settings['stripe_test_publishable_key'] = '';
      $settings['stripe_test_secret_key'] = '';
      $settings['stripe_test_webhook_secret'] = '';

      $settings['stripe_sheet_music_subscription_price_id'] = sanitize_text_field((string)($_POST['stripe_sheet_music_subscription_price_id'] ?? ''));
      $settings['stripe_test_sheet_music_subscription_price_id'] = '';
      $settings['composer_connected_account_id'] = sanitize_text_field((string)($_POST['composer_connected_account_id'] ?? ''));
      $settings['one_time_sheet_music_composer_pct'] = $this->mrm_sanitize_percent_setting($_POST['one_time_sheet_music_composer_pct'] ?? 0, 0);
      $settings['in_person_travel_amount_cents'] = $this->mrm_money_to_cents($_POST['in_person_travel_amount'] ?? '5.00', 500);

      foreach ($this->mrm_get_instructor_payout_chart_rows() as $row) {
        foreach (array_keys($this->mrm_get_instructor_payout_chart_columns()) as $year_bucket) {
          $setting_key = $this->mrm_get_instructor_payout_chart_setting_key(
            (int)$row['lesson_length'],
            (int)$row['is_online'],
            (int)$year_bucket
          );

          if ($setting_key === '') {
            continue;
          }

          $field_name = str_replace('_cents', '', $setting_key);

          $settings[$setting_key] = $this->mrm_money_to_cents(
            $_POST[$field_name] ?? '0.00',
            0
          );
        }
      }

      $settings['payout_anchor_date'] = sanitize_text_field((string)($_POST['payout_anchor_date'] ?? ''));

      unset($settings['instructor_tier_rules']);
      unset($settings['instructor_payout_30_online_cents']);
      unset($settings['instructor_payout_30_inperson_cents']);
      unset($settings['instructor_payout_60_online_cents']);
      unset($settings['instructor_payout_60_inperson_cents']);

      $this->save_settings($settings);
      $this->mrm_schedule_daily_payout_check();

      // ✅ Manual access row inserts (row-based UI)
      if (
        isset($_POST['mrm_access_add_slug'], $_POST['mrm_access_add_email']) &&
        is_array($_POST['mrm_access_add_slug']) &&
        is_array($_POST['mrm_access_add_email'])
      ) {
        global $wpdb;
        $access_table = $this->table_sheet_music_access();

        // Ensure DB exists
        $this->maybe_install_or_upgrade_db();

        foreach ($_POST['mrm_access_add_slug'] as $i => $slug_raw) {
          $slug = $this->sanitize_product_slug($slug_raw);
          $email = sanitize_email((string)($_POST['mrm_access_add_email'][$i] ?? ''));

          if (!$slug) continue;
          if (!$email || !is_email($email)) continue;
          if ($slug === 'all-sheet-music') continue;

          $purchase_raw = (string)($_POST['mrm_access_add_purchase'][$i] ?? '');
          $expires_raw  = (string)($_POST['mrm_access_add_expires'][$i] ?? '');

          $purchase_dt = $purchase_raw ? date('Y-m-d 00:00:00', strtotime($purchase_raw)) : current_time('mysql');
          $expires_dt  = $expires_raw ? date('Y-m-d 00:00:00', strtotime($expires_raw)) : null;

          $email_hash = $this->email_hash($email);

          // Idempotent: skip if already active
          $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$access_table} WHERE email_hash=%s AND sku=%s AND revoked_at IS NULL LIMIT 1",
            $email_hash, $slug
          ));
          if ($existing) continue;

          $wpdb->insert($access_table, array(
            'email_hash'  => $email_hash,
            'email_plain' => $email,
            'sku'         => $slug,
            'start_at'    => $purchase_dt,
            'expires_at'  => $expires_dt,
            'month_key'   => null,
            'granted_at'  => current_time('mysql'),
            'revoked_at'  => null,
            'source'      => 'manual_admin',
            'source_id'   => null,
          ), array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'));
        }
      }

      // ✅ Edit / delete existing active access rows (row-based UI)
      if (isset($_POST['mrm_access_row_id']) && is_array($_POST['mrm_access_row_id'])) {
        global $wpdb;
        $access_table = $this->table_sheet_music_access();
        $this->maybe_install_or_upgrade_db();

        $delete_ids = array();
        if (isset($_POST['mrm_access_row_delete']) && is_array($_POST['mrm_access_row_delete'])) {
          foreach ($_POST['mrm_access_row_delete'] as $did) {
            $delete_ids[(int)$did] = true;
          }
        }

        foreach ($_POST['mrm_access_row_id'] as $i => $id_raw) {
          $row_id = (int)$id_raw;
          if ($row_id <= 0) continue;

          $row_sku = (string)$wpdb->get_var($wpdb->prepare(
            "SELECT sku FROM {$access_table} WHERE id = %d LIMIT 1",
            $row_id
          ));
          if ($row_sku === 'all-sheet-music') {
            continue;
          }

          // Delete (revoke) row
          if (isset($delete_ids[$row_id])) {
            $wpdb->update(
              $access_table,
              array('revoked_at' => current_time('mysql')),
              array('id' => $row_id),
              array('%s'),
              array('%d')
            );
            continue;
          }

          $email = sanitize_email((string)($_POST['mrm_access_row_email'][$i] ?? ''));
          if (!$email || !is_email($email)) {
            // skip invalid edits; preserve existing row
            continue;
          }

          $start_raw  = (string)($_POST['mrm_access_row_start'][$i] ?? '');
          $expire_raw = (string)($_POST['mrm_access_row_expires'][$i] ?? '');

          $start_dt  = $start_raw ? date('Y-m-d 00:00:00', strtotime($start_raw)) : null;
          $expire_dt = $expire_raw ? date('Y-m-d 00:00:00', strtotime($expire_raw)) : null;

          $wpdb->update(
            $access_table,
            array(
              'email_plain' => $email,
              'email_hash'  => $this->email_hash($email),
              'start_at'    => $start_dt,
              'expires_at'  => $expire_dt,
            ),
            array('id' => $row_id),
            array('%s','%s','%s','%s'),
            array('%d')
          );
        }
      }

      // Delete selected piece-product access rows
      if (!empty($_POST['mrm_piece_access_delete']) && is_array($_POST['mrm_piece_access_delete'])) {
        global $wpdb;
        $access_table = $this->table_sheet_music_access();

        $delete_ids = array_map('intval', (array)$_POST['mrm_piece_access_delete']);
        $delete_ids = array_filter($delete_ids);

        foreach ($delete_ids as $row_id) {
          if ($row_id <= 0) continue;

          $row_sku = (string)$wpdb->get_var($wpdb->prepare(
            "SELECT sku FROM {$access_table} WHERE id = %d LIMIT 1",
            $row_id
          ));

          // Never allow manual delete of the Stripe-managed subscription master row.
          if ($row_sku === 'all-sheet-music') {
            continue;
          }

          $wpdb->update(
            $access_table,
            array(
              'revoked_at' => current_time('mysql'),
            ),
            array('id' => $row_id),
            array('%s'),
            array('%d')
          );
        }
      }

      // Save Access Lists
      if (isset($_POST['mrm_access_slug']) && is_array($_POST['mrm_access_slug'])
          && isset($_POST['mrm_access_emails']) && is_array($_POST['mrm_access_emails'])) {

        $new_lists = array();

        foreach ($_POST['mrm_access_slug'] as $i => $slug_raw) {
          $slug = $this->sanitize_product_slug($slug_raw);
          if (!$slug) continue;

          $emails_raw = isset($_POST['mrm_access_emails'][$i]) ? $_POST['mrm_access_emails'][$i] : '';
          $new_lists[$slug] = $this->normalize_email_list_textarea($emails_raw);
        }

        // Force master subscription list to be Stripe-managed only
        $new_lists['all-sheet-music'] = array();

        $this->save_access_lists($new_lists);
      }

      if (!empty($_POST['mrm_run_payout_batch'])) {
        $result = $this->mrm_run_payout_batch(true);
        $last = !empty($result['last_error']) ? (' Last error: ' . $result['last_error']) : '';
        $msg = 'Payout batch run. Transfers: ' . (int)($result['transfers_created'] ?? 0) . ', payouts: ' . (int)($result['payouts_created'] ?? 0) . ', errors: ' . (int)($result['errors'] ?? 0) . $last;
        add_settings_error('mrm_pay_hub', 'payout_batch_run', $msg, empty($result['errors']) ? 'updated' : 'error');
      }

      add_settings_error('mrm_pay_hub', 'saved', 'Settings saved.', 'updated');
    }

    if (isset($_POST['mrm_pay_hub_products_nonce']) && wp_verify_nonce($_POST['mrm_pay_hub_products_nonce'], 'mrm_pay_hub_save_products')) {
      $existing = $this->all_products();
      $products = is_array($existing) ? $existing : array();

      $skus = isset($_POST['sku']) ? (array)$_POST['sku'] : array();
      $original_skus = isset($_POST['original_sku']) ? (array)$_POST['original_sku'] : array();
      $labels = isset($_POST['label']) ? (array)$_POST['label'] : array();
      $amounts = isset($_POST['amount_cents']) ? (array)$_POST['amount_cents'] : array();
      $currencies = isset($_POST['currency']) ? (array)$_POST['currency'] : array();
      $types = isset($_POST['product_type']) ? (array)$_POST['product_type'] : array();
      $categories = isset($_POST['category']) ? (array)$_POST['category'] : array();
      $deletes = isset($_POST['delete']) ? (array)$_POST['delete'] : array();

      $allowed_currencies = array('usd', 'eur');
      $allowed_types = array('lesson', 'sheet_music');
      $allowed_categories = array(
        'lesson' => array('60_online', '60_inperson', '30_online', '30_inperson'),
        'sheet_music' => array('fundamentals', 'trombone-euphonium', 'tuba', 'complete-package'),
      );

      foreach ($skus as $index => $sku_raw) {
        $label_raw = sanitize_text_field((string)($labels[$index] ?? ''));
        $type = sanitize_text_field((string)($types[$index] ?? 'sheet_music'));
        if (!in_array($type, $allowed_types, true)) $type = 'lesson';
        $category = sanitize_text_field((string)($categories[$index] ?? ''));
        if (!in_array($category, $allowed_categories[$type], true)) {
          $category = $allowed_categories[$type][0];
        }

        $original_sku = $this->sanitize_sku((string)($original_skus[$index] ?? ''));
        $current_sku = $original_sku ? $original_sku : $this->sanitize_sku($sku_raw);
        if (!empty($deletes[$index])) {
          if ($current_sku) {
            unset($products[$current_sku]);
          }
          continue;
        }
        if ($type === 'sheet_music') {
          $sku = $this->generate_sheet_music_sku($label_raw, $category, $current_sku);
          if ($current_sku && $current_sku !== $sku) {
            unset($products[$current_sku]);
          }
        } else {
          $sku = 'lesson_' . $category;
          if ($current_sku && $current_sku !== $sku) {
            unset($products[$current_sku]);
          }
        }
        $sku = $this->sanitize_sku($sku);
        if (!$sku) continue;

        $label = $label_raw !== '' ? $label_raw : $sku;
        $amount = intval($amounts[$index] ?? 0);
        $final_amount_cents = max(0, $amount);
        if ($final_amount_cents <= 0) {
          // You can choose to allow 0 for certain SKUs if you want, but generally this avoids accidental free products.
          $final_amount_cents = 0;
        }
        $currency = sanitize_text_field((string)($currencies[$index] ?? 'usd'));
        if (!in_array($currency, $allowed_currencies, true)) $currency = 'usd';

        $products[$sku] = array(
          'sku' => $sku,
          'label' => $label,
          'amount_cents' => $final_amount_cents,
          'currency' => $currency,
          'product_type' => $type,
          'category' => $category,
          'active' => 1,
        );
      }

      $this->save_products($products);
      add_settings_error('mrm_pay_hub', 'products_saved', 'Products saved.', 'updated');
    }
  }

  private function render_products_ui() {
    $products = $this->all_products();
    $all_sku = $this->get_all_sheet_music_sku();
    $lesson_categories = array(
      '60_online' => '60 Online',
      '60_inperson' => '60 In-Person',
      '30_online' => '30 Online',
      '30_inperson' => '30 In-Person',
    );
    $sheet_categories = array(
      'fundamentals' => 'Fundamentals',
      'trombone-euphonium' => 'Trombone/Euphonium',
      'tuba' => 'Tuba',
      'complete-package' => 'Complete Package',
    );
    $currency_options = array('usd' => 'USD', 'eur' => 'EUR');
    $type_options = array('lesson' => 'Lesson', 'sheet_music' => 'Sheet Music');

    ob_start();
    ?>
    <h2>Products</h2>
    <form method="post">
      <?php wp_nonce_field('mrm_pay_hub_save_products', 'mrm_pay_hub_products_nonce'); ?>
      <div class="mrm-products-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;">
        <?php $index = 0; ?>
        <?php foreach ($products as $sku => $product) :
          $sku = $this->sanitize_sku($sku);
          if (!$sku || !is_array($product)) continue;
          $current_index = $index;
          $index++;
          $type = (string)($product['product_type'] ?? 'lesson');
          $category = (string)($product['category'] ?? '');
          if (!$category && $type === 'lesson') {
            $category = str_replace('lesson_', '', $sku);
          }
          if (!$category && $type === 'sheet_music') {
            if (preg_match('/^piece-[a-z0-9\-]+-(fundamentals|trombone-euphonium|tuba|complete-package)$/', $sku, $match)) {
              $category = $match[1];
            }
          }
        ?>
          <div class="card" style="padding:16px;">
            <h3 style="margin-top:0;"><?php echo esc_html($sku); ?></h3>
            <p>
              <label>SKU<br />
                <input type="text" class="regular-text mrm-sku-display" value="<?php echo esc_attr($sku); ?>" disabled />
                <input type="hidden" name="sku[<?php echo esc_attr($current_index); ?>]" value="<?php echo esc_attr($sku); ?>" />
                <input type="hidden" name="original_sku[<?php echo esc_attr($current_index); ?>]" value="<?php echo esc_attr($sku); ?>" />
              </label>
            </p>
            <p>
              <label>Label<br />
                <input type="text" name="label[<?php echo esc_attr($current_index); ?>]" value="<?php echo esc_attr((string)($product['label'] ?? $sku)); ?>" class="regular-text" />
              </label>
            </p>
            <p>
              <label>Amount (cents)<br />
                <input type="number" name="amount_cents[<?php echo esc_attr($current_index); ?>]" value="<?php echo esc_attr((string)($product['amount_cents'] ?? 0)); ?>" class="small-text" />
              </label>
            </p>
            <p>
              <label>Currency<br />
                <select name="currency[<?php echo esc_attr($current_index); ?>]">
                  <?php foreach ($currency_options as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($value, (string)($product['currency'] ?? 'usd')); ?>>
                      <?php echo esc_html($label); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
            </p>
            <p>
              <label>Product Type<br />
                <select name="product_type[<?php echo esc_attr($current_index); ?>]" class="mrm-product-type">
                  <?php foreach ($type_options as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $type); ?>>
                      <?php echo esc_html($label); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
            </p>
            <p>
              <label>Category<br />
                <select name="category[<?php echo esc_attr($current_index); ?>]" class="mrm-product-category">
                  <?php
                    $cat_options = ($type === 'sheet_music') ? $sheet_categories : $lesson_categories;
                    foreach ($cat_options as $value => $label) :
                  ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $category); ?>>
                      <?php echo esc_html($label); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
            </p>
            <?php if ($type === 'sheet_music') : ?>
              <hr />
              <?php if ($sku === $all_sku) :
                $rows = $this->mrm_get_sheet_music_subscription_rows_for_admin();
              ?>
                <p><strong>Stripe Subscription Status (All Sheet Music)</strong><br />
                  <small>Synced from Stripe subscription webhooks.</small>
                </p>
                <table class="widefat striped">
                  <thead>
                    <tr>
                      <th>Email</th>
                      <th>Subscription Active</th>
                      <th>End Date</th>
                      <th>Stripe Status</th>
                      <th>Updated</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (empty($rows)) : ?>
                    <tr><td colspan="5">No sheet music subscriptions found.</td></tr>
                  <?php else : ?>
                    <?php foreach ($rows as $row) :
                      $is_active = $this->mrm_is_sheet_music_subscription_active_for_admin($row);
                      $end_date = $this->mrm_sheet_music_subscription_end_date_for_admin($row);
                    ?>
                      <tr>
                        <td><?php echo esc_html((string)$row['email_plain']); ?></td>
                        <td style="text-align:center;"><?php echo $is_active ? '✔' : ''; ?></td>
                        <td><?php echo esc_html($end_date); ?></td>
                        <td><?php echo esc_html((string)$row['stripe_status']); ?></td>
                        <td><?php echo esc_html((string)$row['updated_at']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            <?php endif; ?>
            <p>
              <label>
                <input type="checkbox" name="delete[<?php echo esc_attr($current_index); ?>]" value="1" />
                Delete
              </label>
            </p>
          </div>
        <?php endforeach; ?>

        <?php $new_index = $index; ?>
        <div class="card" style="padding:16px;border:1px dashed #ccd0d4;">
          <h3 style="margin-top:0;">Add New Product</h3>
          <p>
            SKU will be generated automatically based on the label and type.
          </p>
          <p>
            <label>SKU<br />
              <input type="text" class="regular-text mrm-sku-display" value="" disabled />
              <input type="hidden" name="sku[<?php echo esc_attr($new_index); ?>]" value="" />
              <input type="hidden" name="original_sku[<?php echo esc_attr($new_index); ?>]" value="" />
            </label>
          </p>
          <p>
            <label>Label<br />
              <input type="text" name="label[<?php echo esc_attr($new_index); ?>]" value="" class="regular-text" />
            </label>
          </p>
          <p>
            <label>Amount (cents)<br />
              <input type="number" name="amount_cents[<?php echo esc_attr($new_index); ?>]" value="" class="small-text" />
            </label>
          </p>
          <p>
            <label>Currency<br />
              <select name="currency[<?php echo esc_attr($new_index); ?>]">
                <?php foreach ($currency_options as $value => $label) : ?>
                  <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </p>
          <p>
            <label>Product Type<br />
              <select name="product_type[<?php echo esc_attr($new_index); ?>]" class="mrm-product-type">
                <?php foreach ($type_options as $value => $label) : ?>
                  <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </p>
          <p>
            <label>Category<br />
              <select name="category[<?php echo esc_attr($new_index); ?>]" class="mrm-product-category">
                <?php foreach ($lesson_categories as $value => $label) : ?>
                  <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </p>
          <input type="hidden" name="delete[<?php echo esc_attr($new_index); ?>]" value="0" />
        </div>
      </div>
      <p class="submit">
        <button type="submit" class="button button-primary">Save Products</button>
      </p>
    </form>
    <script>
      (function() {
        var lessonCategories = <?php echo wp_json_encode($lesson_categories); ?>;
        var sheetCategories = <?php echo wp_json_encode($sheet_categories); ?>;
        function updateCategory(select) {
          var card = select.closest('.card');
          if (!card) return;
          var categorySelect = card.querySelector('.mrm-product-category');
          if (!categorySelect) return;
          var type = select.value;
          var options = type === 'sheet_music' ? sheetCategories : lessonCategories;
          var current = categorySelect.value;
          categorySelect.innerHTML = '';
          Object.keys(options).forEach(function(value) {
            var opt = document.createElement('option');
            opt.value = value;
            opt.textContent = options[value];
            if (value === current) opt.selected = true;
            categorySelect.appendChild(opt);
          });
          if (!categorySelect.value) {
            var keys = Object.keys(options);
            if (keys.length) categorySelect.value = keys[0];
          }
        }
        function slugify(s) {
          var text = String(s || '').trim().toLowerCase();
          text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
          text = text.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
          return text || 'untitled';
        }
        function updateSku(card) {
          var labelInput = card.querySelector('input[name^="label"]');
          var typeSelect = card.querySelector('select[name^="product_type"]');
          var catSelect = card.querySelector('select[name^="category"]');
          var skuInput = card.querySelector('input[name^="sku"]');
          var skuDisplay = card.querySelector('.mrm-sku-display');
          if (!labelInput || !typeSelect || !catSelect || !skuInput) return;
          var labelSlug = slugify(labelInput.value);
          var type = typeSelect.value;
          var category = catSelect.value;
          var sku;
          if (type === 'sheet_music') {
            sku = 'piece-' + labelSlug + '-' + category;
          } else {
            sku = 'lesson_' + category;
          }
          skuInput.value = sku;
          if (skuDisplay) skuDisplay.value = sku;
        }
        document.querySelectorAll('.mrm-product-type').forEach(function(select) {
          select.addEventListener('change', function() {
            updateCategory(select);
            updateSku(select.closest('.card'));
          });
          updateCategory(select);
        });
        document.querySelectorAll('.mrm-products-grid .card').forEach(function(card) {
          ['input', 'change'].forEach(function(evt) {
            card.addEventListener(evt, function() {
              updateSku(card);
            });
          });
          updateSku(card);
        });
      })();
    </script>
    <?php
    return ob_get_clean();
  }

  public function render_access_lists_page() {
    if (!current_user_can('manage_options')) return;

    settings_errors('mrm_pay_hub');
    ?>
    <div class="wrap">
      <h1>Sheet Music Access</h1>

      <form method="post">
        <?php wp_nonce_field('mrm_pay_hub_save', 'mrm_pay_hub_nonce'); ?>
        <h2>Sheet Music Access Lists (Email-based)</h2>
        <p>Master list: <code>all-sheet-music</code> grants access to any piece.</p>

        <?php
        $instructor_rows = $this->mrm_get_piece_product_access_rows_for_admin('all-piece-products-instructors');
        if (defined('WP_DEBUG') && WP_DEBUG) error_log('[MRM Payments Hub] Instructor piece access table render. row_count=' . count((array)$instructor_rows));
        ?>
        <h3 style="margin-top:18px;">Instructor piece access (auto-managed)</h3>
        <p><small>This list is auto-generated from the instructors table and updates automatically.</small></p>

        <table class="widefat striped" style="max-width: 980px; margin-bottom:18px;">
          <thead>
            <tr>
              <th>Email</th>
              <th>Validated for all piece products</th>
              <th>Granted</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($instructor_rows)) : ?>
              <?php foreach ($instructor_rows as $row) :
                $granted = !empty($row['granted_at']) ? date_i18n('Y-m-d g:i A', strtotime($row['granted_at'])) : '';
              ?>
                <tr>
                  <td><?php echo esc_html((string)($row['email_plain'] ?? '')); ?></td>
                  <td style="text-align:center;">✔</td>
                  <td><?php echo esc_html($granted); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else : ?>
              <tr><td colspan="3"><em>No instructor piece-access rows found.</em></td></tr>
            <?php endif; ?>
          </tbody>
        </table>

        <table class="widefat striped" style="max-width: 980px;">
          <thead>
            <tr>
              <th style="width:220px;">product_slug</th>
              <th>Approved emails (comma / newline / semicolon separated)</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $lists = $this->all_access_lists();

              // Always show master row
              if (!isset($lists['all-sheet-music']) || !is_array($lists['all-sheet-music'])) {
                $lists['all-sheet-music'] = array();
              }

              // Keys from option lists
              $keys = array_keys($lists);

              // Also include any SKU present in access table
              global $wpdb;
              $access_table = $wpdb->prefix . 'mrm_sheet_music_access';
              $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $access_table));
              if ($exists === $access_table) {
                $db_keys = $wpdb->get_col("SELECT DISTINCT sku FROM {$access_table} ORDER BY sku ASC");
                if (is_array($db_keys)) $keys = array_merge($keys, $db_keys);
              }

              $keys = array_filter(array_map(array($this, 'sanitize_product_slug'), $keys));
              sort($keys);

              // Ensure master is first
              $keys = array_values(array_unique(array_merge(array('all-sheet-music'), $keys)));

              foreach ($keys as $k) {
                if ($k === 'all-piece-products-instructors') {
                  continue;
                }

                $safe_k = esc_attr($k);
                $results = $wpdb->get_results($wpdb->prepare(
                  "SELECT id, email_plain, start_at, expires_at FROM {$access_table} WHERE sku = %s AND revoked_at IS NULL ORDER BY start_at DESC",
                  $k
                ));
                if ($k === 'all-sheet-music') {
                  $subscription_rows = $this->mrm_get_sheet_music_subscription_rows_for_admin();
                  ?>
                  <tr>
                    <td>
                      <code>all-sheet-music</code>
                      <p style="margin:8px 0 0;">
                        <small>This row is Stripe-managed and read-only.</small>
                      </p>
                    </td>
                    <td>
                      <p style="margin:0 0 8px;">
                        <small>Customers listed below are derived from the Stripe-synced subscription table. The checkbox is visual only.</small>
                      </p>

                      <table class="widefat" style="margin-top:8px;">
                        <thead>
                          <tr>
                            <th>Email</th>
                            <th>Source</th>
                            <th>Stripe Subscription Status</th>
                            <th>Access</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php if (!empty($subscription_rows)) : ?>
                            <?php foreach ($subscription_rows as $sub_row) :
                              $email = sanitize_email((string)($sub_row['email_plain'] ?? ''));
                              $status = $this->mrm_get_sheet_music_subscription_access_status_by_email($email);
                              $status_label = ($status['status'] !== '') ? strtoupper((string)$status['status']) : 'NONE';
                              $access_label = !empty($status['has_access']) ? '✓' : '✕';
                            ?>
                              <tr>
                                <td><?php echo esc_html($email); ?></td>
                                <td>Stripe Subscription</td>
                                <td><?php echo esc_html($status_label); ?></td>
                                <td style="font-weight:700;"><?php echo esc_html($access_label); ?></td>
                              </tr>
                            <?php endforeach; ?>
                          <?php else : ?>
                            <tr><td colspan="4"><em>No active or paid-through sheet music subscriptions found.</em></td></tr>
                          <?php endif; ?>
                        </tbody>
                      </table>
                    </td>
                  </tr>
                  <?php
                  continue;
                }
                ?>
                <tr>
                  <td><input type="text" name="mrm_access_slug[]" value="<?php echo $safe_k; ?>" style="width:100%;" /></td>
                  <td>
                    <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                      <input type="email" name="mrm_access_add_email[]" value="" placeholder="email@example.com" style="flex:1; max-width: 360px;" />
                      <input type="date" name="mrm_access_add_purchase[]" value="" style="width:160px;" />
                      <input type="date" name="mrm_access_add_expires[]" value="" style="width:160px;" />
                      <input type="hidden" name="mrm_access_add_slug[]" value="<?php echo esc_attr($k); ?>" />
                      <span style="opacity:.75;">(purchase / expires optional)</span>
                    </div>
                    <p style="margin:0;">
                      <small>Add one email at a time to manually validate or credit access for this piece product.</small>
                    </p>
                    <?php
                    $piece_rows = $this->mrm_get_piece_product_access_rows_for_admin($k);
                    ?>
                    <table class="widefat" style="margin-top:8px;">
                      <thead>
                        <tr><th>Email</th><th>Purchase date</th><th>Delete?</th></tr>
                      </thead>
                      <tbody>
                        <?php if (!empty($piece_rows)) : ?>
                          <?php foreach ($piece_rows as $row) :
                            $purchase_date = !empty($row['granted_at']) ? date_i18n('Y-m-d g:i A', strtotime($row['granted_at'])) : '';
                          ?>
                            <tr>
                              <td>
                                <?php echo esc_html((string)($row['email_plain'] ?? '')); ?>
                                <input type="hidden" name="mrm_piece_access_row_id[]" value="<?php echo (int)($row['id'] ?? 0); ?>" />
                              </td>
                              <td><?php echo esc_html($purchase_date); ?></td>
                              <td style="text-align:center;">
                                <label>
                                  <input type="checkbox" name="mrm_piece_access_delete[]" value="<?php echo (int)($row['id'] ?? 0); ?>" />
                                </label>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        <?php else : ?>
                          <tr><td colspan="3"><em>No access rows found.</em></td></tr>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </td>
                </tr>
                <?php
              }
            ?>
            <!-- blank row to add a new slug -->
            <tr>
              <td><input type="text" name="mrm_access_slug[]" value="" placeholder="new-product-slug" style="width:100%;" /></td>
              <td>
                <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                  <input type="email" name="mrm_access_add_email[]" value="" placeholder="email@example.com" style="flex:1; max-width: 360px;" />
                  <input type="date" name="mrm_access_add_purchase[]" value="" style="width:160px;" />
                  <input type="date" name="mrm_access_add_expires[]" value="" style="width:160px;" />
                  <input type="hidden" name="mrm_access_add_slug[]" value="" />
                  <span style="opacity:.75;">(purchase / expires optional)</span>
                </div>
                <p style="margin:0;">
                  <small>Add one email at a time. Leave “expires” blank for never.</small>
                </p>
              </td>
            </tr>
          </tbody>
        </table>

        <p class="submit">
          <button type="submit" class="button button-primary">Save Settings</button>
        </p>
      </form>
    </div>
    <?php
  }

  public function render_admin_page() {
    if (!current_user_can('manage_options')) return;

    settings_errors('mrm_pay_hub');

    $settings = $this->get_settings();
    $pk_current = esc_attr((string)$this->publishable_key());
    $price_current = esc_attr((string)($settings['stripe_sheet_music_subscription_price_id'] ?? ''));
    $composer_acct = esc_attr((string)($settings['composer_connected_account_id'] ?? ''));
    $one_time_sheet_music_composer_pct = esc_attr((string)($settings['one_time_sheet_music_composer_pct'] ?? 0));
    $in_person_travel_amount = esc_attr($this->mrm_format_cents_for_admin_input((int)($settings['in_person_travel_amount_cents'] ?? 500)));
    $instructor_payout_chart_rows = $this->mrm_get_instructor_payout_chart_rows();
    $instructor_payout_chart_columns = $this->mrm_get_instructor_payout_chart_columns();
    $instructor_payout_chart_values = $this->mrm_get_instructor_payout_chart_admin_matrix();
    $payout_anchor_date = esc_attr((string)($settings['payout_anchor_date'] ?? ''));

    ?>
    <div class="wrap">
      <h1>MRM Payments Hub</h1>

      <form method="post">
        <?php wp_nonce_field('mrm_pay_hub_save', 'mrm_pay_hub_nonce'); ?>

        <h2>Stripe Configuration</h2>
        <p>Stripe <code>publishable_key</code>, <code>secret_key</code>, and <code>webhook_secret</code> are managed through AWS Secrets Manager at <code>lowbrass/stripe/keys</code>. WordPress no longer controls live vs test mode.</p>

        <table class="form-table">
          <tr>
            <th scope="row">Publishable Key Source</th>
            <td>
              <p><strong>Current:</strong> <code><?php echo $pk_current !== '' ? 'Loaded from AWS Secrets Manager' : 'Missing'; ?></code></p>
              <p class="description">The active environment is determined by the Stripe keys you have configured outside WordPress.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">AWS Stripe Test</th>
            <td>
              <button type="submit" name="mrm_pay_hub_test_aws" value="1" class="button button-secondary">Test Stripe AWS Connection</button>
              <?php wp_nonce_field('mrm_pay_hub_test_aws', 'mrm_pay_hub_test_aws_nonce'); ?>
              <p class="description">This sends a harmless authenticated request to Stripe using the AWS-backed secret key currently configured.</p>
            </td>
          </tr>
        </table>

        <h3>Sheet Music Subscription Price ID</h3>
        <table class="form-table">
          <tr>
            <th scope="row"><label for="stripe_sheet_music_subscription_price_id">Subscription Price ID</label></th>
            <td>
              <input type="text" id="stripe_sheet_music_subscription_price_id" name="stripe_sheet_music_subscription_price_id" value="<?php echo $price_current; ?>" class="regular-text" placeholder="price_..." />
              <p class="description">Use the Stripe Price ID that matches the environment of the keys currently configured in AWS and <code>wp-config.php</code>.</p>
            </td>
          </tr>
        </table>

        <h2>Connect / Payout Settings</h2>
        <p>These settings drive instructor/composer payout math and the biweekly payout batch.</p>
        <table class="form-table">
          <tr>
            <th scope="row"><label for="composer_connected_account_id">Composer Connected Account ID</label></th>
            <td>
              <input type="text" id="composer_connected_account_id" name="composer_connected_account_id" value="<?php echo $composer_acct; ?>" class="regular-text" placeholder="acct_..." />
              <p class="description">Paste the composer’s Stripe Connect account ID here.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="one_time_sheet_music_composer_pct">One-Time Sheet Music Composer %</label></th>
            <td>
              <input type="number" id="one_time_sheet_music_composer_pct" name="one_time_sheet_music_composer_pct" value="<?php echo $one_time_sheet_music_composer_pct; ?>" class="small-text" min="0" max="100" />
              <p class="description">This centralized percentage is used for eligible one-time sheet music purchases. Per-product sheet music composer percentages are no longer used.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="in_person_travel_amount">In-Person Travel Add-On</label></th>
            <td>
              <input type="number" id="in_person_travel_amount" name="in_person_travel_amount" value="<?php echo $in_person_travel_amount; ?>" class="small-text" min="0" step="0.01" />
              <p class="description">This amount is added to every in-person instructor payout in all payout paths.</p>
            </td>
          </tr>

          <tr>
            <th scope="row">Instructor Payout Chart</th>
            <td>
              <table class="widefat striped" style="max-width:1000px;">
                <thead>
                  <tr>
                    <th>Lesson Type</th>
                    <?php foreach ($instructor_payout_chart_columns as $year_bucket => $year_label) : ?>
                      <th><?php echo esc_html($year_label); ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($instructor_payout_chart_rows as $row_key => $row) : ?>
                    <tr>
                      <th scope="row"><?php echo esc_html($row['label']); ?></th>
                      <?php foreach ($instructor_payout_chart_columns as $year_bucket => $year_label) : ?>
                        <?php
                          $field_name = str_replace(
                            '_cents',
                            '',
                            $this->mrm_get_instructor_payout_chart_setting_key(
                              (int)$row['lesson_length'],
                              (int)$row['is_online'],
                              (int)$year_bucket
                            )
                          );
                        ?>
                        <td>
                          <input
                            type="number"
                            name="<?php echo esc_attr($field_name); ?>"
                            value="<?php echo esc_attr($instructor_payout_chart_values[$row_key][$year_bucket] ?? '0.00'); ?>"
                            class="small-text"
                            min="0"
                            step="0.01"
                          />
                        </td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <p class="description">
                The instructor year bucket is resolved from <code>hire_date</code> in the instructors table.
                Year changes happen at 12:00 AM in the site timezone on each employment anniversary date.
                Year 3+ is used for year 3 and every later year.
                The in-person travel add-on below this section is still added separately to all in-person payouts.
              </p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="payout_anchor_date">Biweekly Payout Anchor Date</label></th>
            <td>
              <input type="date" id="payout_anchor_date" name="payout_anchor_date" value="<?php echo $payout_anchor_date; ?>" />
              <p class="description">Use the first Friday that should count as a payout Friday. Every 14 days after that is a payout day. The automatic payout check is scheduled for 10:00 AM site time on each eligible payout date. For near-exact timing, your server cron should trigger WordPress cron every minute.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Manual Test Button</th>
            <td>
              <button type="submit" name="mrm_run_payout_batch" value="1" class="button">Run Payout Batch Now</button>
              <p class="description">Use this in sandbox to test transfers and payouts immediately.</p>
            </td>
          </tr>
        </table>

        <p class="submit">
          <button type="submit" class="button button-primary">Save Settings</button>
        </p>
      </form>

      <?php echo $this->render_products_ui(); ?>

      <hr />
      <h2>REST Endpoints</h2>
      <ul>
        <li><code>GET  /wp-json/mrm-pay/v1/quote?sku=lesson_60_online</code></li>
        <li><code>GET  /wp-json/mrm-pay/v1/resolve?piece_slug=example&amp;type=fundamentals</code></li>
        <li><code>POST /wp-json/mrm-pay/v1/create-payment-intent</code></li>
        <li><code>POST /wp-json/mrm-pay/v1/verify-payment-intent</code></li>
        <li><code>POST /wp-json/mrm-pay/v1/grant-sheet-music-access</code></li>
      </ul>
    </div>
    <?php
  }
}

global $mrm_pay_hub_singleton;
$mrm_pay_hub_singleton = new MRM_Payments_Hub_Single();

function mrm_pay_hub_singleton() {
  global $mrm_pay_hub_singleton;
  return $mrm_pay_hub_singleton instanceof MRM_Payments_Hub_Single ? $mrm_pay_hub_singleton : null;
}

register_activation_hook(__FILE__, function() {
  $hub = mrm_pay_hub_singleton();

  if ($hub instanceof MRM_Payments_Hub_Single) {
    $hub->ensure_runtime_cron_schedules();
    return;
  }

  if (!wp_next_scheduled('mrm_pay_hub_cleanup_access')) {
    wp_schedule_event(time() + 300, 'hourly', 'mrm_pay_hub_cleanup_access');
  }

  if (!wp_next_scheduled('mrm_pay_hub_retry_autopay_charges')) {
    wp_schedule_event(time() + 240, 'mrm_10min', 'mrm_pay_hub_retry_autopay_charges');
  }

  if (!wp_next_scheduled('mrm_pay_hub_retry_sheet_music_subscriptions')) {
    wp_schedule_event(time() + 210, 'mrm_10min', 'mrm_pay_hub_retry_sheet_music_subscriptions');
  }
});

register_deactivation_hook(__FILE__, function() {
  wp_clear_scheduled_hook('mrm_pay_hub_cleanup_access');
  wp_clear_scheduled_hook('mrm_pay_hub_daily_payout_check');
  wp_clear_scheduled_hook('mrm_pay_hub_retry_autopay_charges');
  wp_clear_scheduled_hook('mrm_pay_hub_retry_sheet_music_subscriptions');
});

/**
 * Optional helper for other plugins (PHP-level access)
 */
function mrm_pay_hub() {
  global $mrm_pay_hub_singleton;
  // not used here, but kept as a convention point
  return null;
}
