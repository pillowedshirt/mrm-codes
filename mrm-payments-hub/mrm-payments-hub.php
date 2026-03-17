<?php
/**
 * Plugin Name: MRM Payments Hub (Single File)
 * Description: Central product catalog + Stripe PaymentIntents + labeling metadata + orders ledger for MRM scheduler and sheet music plugins.
 * Version: 1.0.0
 * Author: Matt Rose
 */

if (!defined('ABSPATH')) exit;

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

    if (!wp_next_scheduled('mrm_pay_hub_daily_payout_check')) {
      wp_schedule_event(time() + 600, 'daily', 'mrm_pay_hub_daily_payout_check');
    }

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

    if (!wp_next_scheduled('mrm_pay_hub_daily_payout_check')) {
      wp_schedule_event(time() + 600, 'daily', 'mrm_pay_hub_daily_payout_check');
    }

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
    $expires_mysql = $this->mrm_mysql_from_ts($start_ts + (31 * 24 * 60 * 60));
    $now = $this->mrm_now_mysql();

    $ok = $wpdb->insert($table, array(
      'email_hash'  => $email_hash,
      'email_plain' => $email,
      'sku'         => $master_sku,
      'start_at'    => $start_mysql,
      'expires_at'  => $expires_mysql,
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
    return is_array($opts) ? $opts : array();
  }

  private function save_settings($opts) {
    update_option(self::OPT_SETTINGS, $opts);
  }

  // Return the appropriate key depending on whether test or live mode is selected
  private function publishable_key() {
    $s = $this->get_settings();
    $mode = isset($s['stripe_mode']) ? (string)$s['stripe_mode'] : 'live';
    if ($mode === 'test') {
      return trim((string)($s['stripe_test_publishable_key'] ?? ''));
    }
    return trim((string)($s['stripe_publishable_key'] ?? ''));
  }

  private function secret_key() {
    $s = $this->get_settings();
    $mode = isset($s['stripe_mode']) ? (string)$s['stripe_mode'] : 'live';
    if ($mode === 'test') {
      return trim((string)($s['stripe_test_secret_key'] ?? ''));
    }
    return trim((string)($s['stripe_secret_key'] ?? ''));
  }

  private function webhook_secret() {
    $s = $this->get_settings();
    $mode = isset($s['stripe_mode']) ? (string)$s['stripe_mode'] : 'live';
    if ($mode === 'test') {
      return trim((string)($s['stripe_test_webhook_secret'] ?? ''));
    }
    return trim((string)($s['stripe_webhook_secret'] ?? ''));
  }

  private function is_test_mode() {
    $s = $this->get_settings();
    $mode = isset($s['stripe_mode']) ? (string)$s['stripe_mode'] : 'live';
    return ($mode === 'test');
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

  private function subscription_price_id() {
    $s = $this->get_settings();
    if ($this->is_test_mode()) {
      return trim((string)($s['stripe_test_sheet_music_subscription_price_id'] ?? ''));
    }
    return trim((string)($s['stripe_sheet_music_subscription_price_id'] ?? ''));
  }

  private function composer_connected_account_id() {
    $s = $this->get_settings();

    $live = trim((string)($s['composer_connected_account_id'] ?? ''));
    $test = trim((string)($s['test_composer_connected_account_id'] ?? ''));

    if ($this->is_test_mode()) {
      return ($test !== '') ? $test : $live;
    }

    return $live;
  }

  private function all_products() {
    $p = get_option(self::OPT_PRODUCTS, array());
    return is_array($p) ? $p : array();
  }

  private function save_products($products) {
    update_option(self::OPT_PRODUCTS, $products);
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

    // Stripe Tax needs at minimum country + postal code for most US calculations.
    if (!$country || !$postal) {
      return array('ok'=>false,'tax_cents'=>0,'amount_total_cents'=>0,'calculation_id'=>'','line_items'=>array());
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
      if (!$tax_code) $tax_code = 'txcd_10000000'; // Generic electronically supplied services

      $items[] = array(
        'amount' => $amt,
        'reference' => $ref,
        'tax_code' => $tax_code,
      );
    }

    if (empty($items)) {
      return array('ok'=>true,'tax_cents'=>0,'amount_total_cents'=>$subtotal,'calculation_id'=>'','line_items'=>array());
    }

    $payload = array(
      'currency' => strtolower((string)$currency),
      'customer_details' => array(
        'address' => array(
          'country' => $country,
          'postal_code' => $postal,
        ),
      ),
      'line_items' => $items,
    );

    // Optional fields if present
    if ($state) $payload['customer_details']['address']['state'] = $state;
    if ($line1) $payload['customer_details']['address']['line1'] = $line1;

    $calc = $this->stripe_api_request('POST', '/v1/tax/calculations', $payload);
    if (is_wp_error($calc)) {
      error_log('[MRM Payments Hub] Stripe Tax calc error: ' . $calc->get_error_message());
      return array('ok'=>false,'tax_cents'=>0,'amount_total_cents'=>$subtotal,'calculation_id'=>'','line_items'=>array());
    }

    $tax_cents = isset($calc['tax_amount_exclusive']) ? (int)$calc['tax_amount_exclusive'] : 0;
    $amount_total = isset($calc['amount_total']) ? (int)$calc['amount_total'] : ($subtotal + $tax_cents);
    $calc_id = isset($calc['id']) ? (string)$calc['id'] : '';

    // Extract per-line-item tax amounts if expanded (Stripe may not return by default)
    $out_items = array();
    if (!empty($calc['line_items']['data']) && is_array($calc['line_items']['data'])) {
      foreach ($calc['line_items']['data'] as $x) {
        $out_items[] = array(
          'reference' => (string)($x['reference'] ?? ''),
          'amount_cents' => (int)($x['amount'] ?? 0),
          'tax_cents' => (int)($x['amount_tax'] ?? 0),
        );
      }
    }

    return array(
      'ok' => true,
      'tax_cents' => $tax_cents,
      'amount_total_cents' => $amount_total,
      'calculation_id' => $calc_id,
      'line_items' => $out_items,
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

  private function stripe_create_subscription($customer_id, $price_id, $default_payment_method_id, $billing_cycle_anchor_ts, $metadata = array()) {
    $params = array(
      'customer' => (string)$customer_id,
      'items[0][price]' => (string)$price_id,
      'default_payment_method' => (string)$default_payment_method_id,
      'collection_method' => 'charge_automatically',
      'proration_behavior' => 'none',
      'billing_cycle_anchor' => (int)$billing_cycle_anchor_ts,
      'description' => 'Low Brass Lessons - Sheet Music Subscription Charge',
    );

    $metadata['mrm_description'] = 'Low Brass Lessons - Sheet Music Subscription Charge';

    foreach ((array)$metadata as $k => $v) {
      if ($k === '' || $v === null) continue;
      $params["metadata[{$k}]"] = (string)$v;
    }

    return $this->stripe_api_request('POST', '/v1/subscriptions', $params);
  }

  private function stripe_retrieve_subscription($subscription_id) {
    return $this->stripe_api_request('GET', '/v1/subscriptions/' . rawurlencode((string)$subscription_id));
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

    $order = $this->get_order_by_pi($pi_id);
    if ($order) {
      $this->mrm_maybe_send_purchase_receipt_email($pi, $order);
      $this->mrm_maybe_create_payout_ledger_for_order($order);
      $this->mrm_maybe_create_sheet_music_subscription_from_initial_payment_intent($pi, $order);

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
    $this->mrm_sync_local_sheet_music_subscription_from_stripe($subscription, $email);
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
    $this->stripe_debug_log('customer.subscription.deleted handler entered', array(
      'subscription_id' => (string)($subscription['id'] ?? ''),
      'status' => (string)($subscription['status'] ?? ''),
      'email' => $email,
    ));
    $this->mrm_sync_local_sheet_music_subscription_from_stripe($subscription, $email);
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
    $wpdb->insert($this->table_orders(), array(
      'email_hash' => $email_hash,
      'sku' => $sku,
      'product_type' => $product_type,
      'amount_cents' => (int)$amount_cents,
      'currency' => $currency ?: 'usd',
      'status' => 'created',
      'metadata_json' => wp_json_encode($metadata),
      'created_at' => $now,
      'updated_at' => $now,
    ), array('%s','%s','%s','%d','%s','%s','%s','%s','%s'));
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
      'stripe_status' => $stripe_status,
      'updated_at' => current_time('mysql'),
    );
    $fmt = array('%s','%s','%s');

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
           AND stripe_status IN ('trialing','active','past_due','unpaid')
         ORDER BY id DESC
         LIMIT 1",
        $email_hash
      ),
      ARRAY_A
    );

    return is_array($row) ? $row : array();
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
             OR (current_period_end IS NOT NULL AND current_period_end >= %s)
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

    if ($base_cents > 0) {
      $details .= '<div style="margin-top:10px;"><strong>Base:</strong> ' . $fmt_money($base_cents) . '</div>';
      if ($addon_cents > 0) $details .= '<div><strong>Sheet music add-on:</strong> ' . $fmt_money($addon_cents) . '</div>';
      if ($tax_cents > 0) $details .= '<div><strong>Add-on tax:</strong> ' . $fmt_money($tax_cents) . '</div>';
      $details .= '<div><strong>Total:</strong> ' . $fmt_money($amount_cents) . '</div>';
    } else {
      $details .= '<div style="margin-top:10px;"><strong>Total:</strong> ' . $fmt_money($amount_cents) . '</div>';
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
        $details .= '<div style="margin-top:12px;">This message confirms that we have received payment for lesson 1. You will not be charged again until after lesson 2 is delivered.</div>';
      }

      if ($is_autopay_followup_receipt) {
        $details .= '<div style="margin-top:12px;">This message confirms your automatic payment for this lesson.</div>';
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
    $details =
      '<div><strong>Subscription:</strong> Monthly sheet music access</div>' .
      '<div><strong>Amount:</strong> $5.00 per month</div>' .
      '<div><strong>Status:</strong> Active</div>' .
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
      'Purchase confirmation — Sheet music subscription',
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
      // If missing piece_type/slug and sku follows piece pattern, derive values
      if (!$piece_type || !$piece_slug) {
        if (preg_match('/^piece-([a-z0-9\-]+)-(fundamentals|trombone-euphonium|tuba|complete-package)$/', $sku, $m)) {
          $piece_slug = $piece_slug ?: $m[1];
          $piece_type = $piece_type ?: $m[2];
        }
      }
      if ($piece_type) $metadata['mrm_piece_type'] = $piece_type;
      if ($piece_slug) $metadata['mrm_piece_slug'] = $piece_slug;

      // Percent split labeling for composer/platform
      $composer_pct = null;
      if (isset($product_cfg['composer_pct'])) $composer_pct = (int)$product_cfg['composer_pct'];
      if ($composer_pct === null && isset($context['composer_pct'])) $composer_pct = (int)$context['composer_pct'];
      if ($composer_pct === null) $composer_pct = 0;
      if ($composer_pct < 0) $composer_pct = 0;
      if ($composer_pct > 100) $composer_pct = 100;
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

      // Optional: scheduler may pass tier
      if (isset($context['instructor_tier'])) {
        $metadata['mrm_instructor_tier'] = (string)((int)$context['instructor_tier']);
      }
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

  private function mrm_parse_tier_rules($text) {
    $rules = array();
    $lines = preg_split('/\\r\\n|\\r|\\n/', (string)$text);
    foreach ((array)$lines as $line) {
      $line = trim((string)$line);
      if ($line === '' || strpos($line, '=') === false) continue;
      list($months, $pct) = array_map('trim', explode('=', $line, 2));
      $months = max(0, intval($months));
      $pct = max(0, min(100, intval($pct)));
      $rules[$months] = $pct;
    }
    if (empty($rules)) {
      $rules = array(0 => 50, 12 => 55, 24 => 60);
    }
    ksort($rules, SORT_NUMERIC);
    return $rules;
  }

  private function mrm_months_since_date($date_string) {
    $date_string = trim((string)$date_string);
    if ($date_string === '') return 0;
    try {
      $tz = $this->mrm_wp_tz();
      $start = new DateTime($date_string, $tz);
      $now = new DateTime('now', $tz);
      if ($start > $now) return 0;
      $diff = $start->diff($now);
      return max(0, ((int)$diff->y * 12) + (int)$diff->m);
    } catch (Exception $e) {
      return 0;
    }
  }

  private function mrm_resolve_instructor_pct($hire_date) {
    $settings = $this->get_settings();
    $rules = $this->mrm_parse_tier_rules($settings['instructor_tier_rules'] ?? "0=50\n12=55\n24=60");
    $months = $this->mrm_months_since_date($hire_date);
    $pct = 0;
    foreach ($rules as $rule_months => $rule_pct) {
      if ($months >= (int)$rule_months) {
        $pct = (int)$rule_pct;
      }
    }
    return max(0, min(100, $pct));
  }

  private function stripe_retrieve_balance() {
    return $this->stripe_api_request('GET', '/v1/balance');
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
      'gross_cents' => (int)$gross_cents,
      'net_cents' => (int)$net_cents,
      'status' => (string)$status,
      'transfer_id' => null,
      'payout_id' => null,
      'batch_key' => null,
      'notes' => $notes !== '' ? (string)$notes : null,
      'created_at' => $now,
      'updated_at' => $now,
    ), array('%d','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%s'));

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
      $composer_pct = max(0, min(100, (int)($meta['mrm_composer_pct'] ?? 0)));
      $composer_share = (int) round($base_cents * ($composer_pct / 100));
      $platform_share = max(0, $base_cents - $composer_share) + max(0, $addon_cents);
      $composer_acct = (string)($this->get_settings()['composer_connected_account_id'] ?? '');

      if ($composer_share > 0) {
        $this->mrm_insert_payout_ledger_row($order_id, $pi_id, 'composer', 'composer', $composer_acct, $currency, $composer_share, $composer_share, $composer_acct ? 'pending' : 'blocked', $composer_acct ? '' : 'Missing composer connected account ID');
      }

      $this->mrm_insert_payout_ledger_row($order_id, $pi_id, 'platform', 'platform', '', $currency, $platform_share, $platform_share, 'retained', 'Retained by platform');
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
      $composer_acct = (string)($this->get_settings()['composer_connected_account_id'] ?? '');

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

    $instr = $this->mrm_get_instructor_row($instructor_id);
    $instr_pct = $this->mrm_resolve_instructor_pct((string)($instr['hire_date'] ?? ''));
    $instructor_share = (int) floor($amount_cents * ($instr_pct / 100));
    $instructor_acct = (string)($instr['stripe_connected_account_id'] ?? '');

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
        $instructor_acct ? ('Autopay lesson unlocked at ' . $instr_pct . '%') : 'Missing instructor connected account ID'
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
      'Retained by platform'
    );

    error_log('[MRM AutoPay] webhook finalized successful lesson charge'
      . ' lesson_id=' . $lesson_id
      . ' order_id=' . $order_id
      . ' pi_id=' . $pi_id
    );
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

  public function on_lesson_cancelled($data) {
    $lesson_id = (int)($data['lesson_id'] ?? 0);
    $payment_mode = (string)($data['payment_mode'] ?? 'none');
    $order_id = (int)($data['order_id'] ?? 0);
    $autopay_profile_id = (int)($data['autopay_profile_id'] ?? 0);
    $series_id = (int)($data['series_id'] ?? 0);

    if ($lesson_id <= 0) return;

    if ($payment_mode === 'autopay') {
      $lesson_order = $this->mrm_find_lesson_charge_order($lesson_id);
      $cancel_reason = (string)($data['cancel_reason'] ?? '');

      // Refund only if this specific lesson already has its own completed lesson-level charge.
      if ($lesson_order) {
        $refunded = $this->mrm_request_refund_for_order(
          $lesson_order,
          'Auto-refund for cancelled autopay lesson ' . $lesson_id
        );

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

    $new_remaining = max(0, ((int)$credit['remaining_credits']) - 1);
    $wpdb->update($credits_table, array(
      'remaining_credits' => $new_remaining,
      'updated_at' => current_time('mysql'),
    ), array('id' => (int)$credit['id']), array('%d','%s'), array('%d'));

    $instr = $this->mrm_get_instructor_row($instructor_id);
    $instr_pct = $this->mrm_resolve_instructor_pct((string)($instr['hire_date'] ?? ''));
    $instructor_share = (int) floor(((int)$credit['unit_base_cents']) * ($instr_pct / 100));
    $instructor_acct = (string)($instr['stripe_connected_account_id'] ?? '');

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
        $instructor_acct ? ('Prepay lesson unlocked at ' . $instr_pct . '%') : 'Missing instructor connected account ID'
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
    if ($this->mrm_is_biweekly_payout_day()) {
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

    if (!$force && !$this->mrm_is_biweekly_payout_day()) {
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
       WHERE status='pending'
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

    // Build order_id -> latest_charge_id map
    $order_ids = array();
    foreach ($rows as $r) $order_ids[] = (int)$r['order_id'];
    $order_ids = array_values(array_unique($order_ids));

    $charge_map = array();
    if (!empty($order_ids)) {
      $orders_table = $this->table_orders();
      $in = implode(',', array_map('intval', $order_ids));
      $order_rows = $wpdb->get_results("SELECT id, metadata_json FROM {$orders_table} WHERE id IN ({$in})", ARRAY_A);
      foreach ((array)$order_rows as $or) {
        $m = array();
        if (!empty($or['metadata_json'])) {
          $d = json_decode((string)$or['metadata_json'], true);
          if (is_array($d)) $m = $d;
        }
        $ch = (string)($m['mrm_latest_charge_id'] ?? '');
        if ($ch !== '') $charge_map[(int)$or['id']] = $ch;
      }
    }

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
      $transferred_total = 0;
      $transferred_ids = array();

      $group_required_total = $this->mrm_sum_group_transfer_amount($group_rows);
      $available = $this->mrm_balance_available_for_currency($platform_balance, $currency);
      $requires_card_balance = $this->mrm_group_requires_card_balance($group_rows, $charge_map);

      if ($group_required_total <= 0) {
        continue;
      }

      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(
          '[MRM Payout] balance_preflight'
          . ' currency=' . $currency
          . ' group_required_total=' . (int)$group_required_total
          . ' available_total=' . (int)$available['total']
          . ' available_card=' . (int)$available['card']
          . ' requires_card_balance=' . ($requires_card_balance ? 'yes' : 'no')
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

        $this->mrm_mark_group_pending_balance_wait($table, $group_rows, $msg);
        $summary['errors']++;
        $summary['last_error'] = $msg;
        continue;
      }

      if ($requires_card_balance && (int)$available['card'] < $group_required_total) {
        $msg = sprintf(
          'Waiting for Stripe card available balance. Needed %s %0.2f, but only %s %0.2f of card balance is currently available.',
          strtoupper($currency),
          $group_required_total / 100,
          strtoupper($currency),
          ((int)$available['card']) / 100
        );

        $this->mrm_mark_group_pending_balance_wait($table, $group_rows, $msg);
        $summary['errors']++;
        $summary['last_error'] = $msg;
        continue;
      }

      foreach ($group_rows as $row) {
        $amount = (int)$row['net_cents'];
        if ($amount <= 0) continue;
        $source_txn = (string)($charge_map[(int)$row['order_id']] ?? '');

        $transfer = $this->stripe_create_transfer(
          $amount,
          $currency,
          $acct,
          'MRM_ORDER_' . (int)$row['order_id'],
          array(
            'mrm_order_id' => (string)$row['order_id'],
            'mrm_payee_type' => (string)$row['payee_type'],
            'mrm_batch_key' => $batch_key,
          ),
          $source_txn
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
              'notes' => $transfer_error_message,
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
        $transferred_total += $amount;
        $transferred_ids[] = (int)$row['id'];

        if (isset($platform_balance['available']) && is_array($platform_balance['available'])) {
          foreach ($platform_balance['available'] as &$balance_entry) {
            if (strtolower((string)($balance_entry['currency'] ?? '')) !== $currency) {
              continue;
            }

            $balance_entry['amount'] = max(0, (int)($balance_entry['amount'] ?? 0) - $amount);

            if (
              isset($balance_entry['source_types']) &&
              is_array($balance_entry['source_types']) &&
              $source_txn !== '' &&
              isset($balance_entry['source_types']['card'])
            ) {
              $balance_entry['source_types']['card'] = max(
                0,
                (int)$balance_entry['source_types']['card'] - $amount
              );
            }

            break;
          }
          unset($balance_entry);
        }
      }

      if ($transferred_total <= 0 || empty($transferred_ids)) {
        continue;
      }

      $payout = $this->stripe_create_connected_account_payout(
        $acct,
        $transferred_total,
        $currency,
        array(
          'mrm_batch_key' => $batch_key,
        )
      );

      if (is_wp_error($payout)) {
        $summary['errors']++;
        $summary['last_error'] = $payout->get_error_message();
        continue;
      }

      foreach ($transferred_ids as $ledger_id) {
        $wpdb->update(
          $table,
          array(
            'status' => 'paid_out',
            'payout_id' => (string)($payout['id'] ?? ''),
            'updated_at' => current_time('mysql'),
          ),
          array('id' => (int)$ledger_id),
          array('%s','%s','%s'),
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

    return new WP_REST_Response(array(
      'ok' => true,
      'sku' => $sku,
      'label' => (string)($p['label'] ?? $sku),
      'amount_cents' => $amount_cents,
      'currency' => $currency,
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

    // Harden: default country to US when omitted (prevents Stripe Tax from skipping calculation)
    if (empty($address['country'])) {
      $address['country'] = 'US';
    }

    $base_amount_cents = (int)$amount;
    $addon_amount_cents = $addon_selected ? 500 : 0;

    // Stripe Tax (US-wide): calculate tax for taxable items only.
    // Lessons are treated as non-taxable by design; sheet music and sheet music access add-ons are taxable.
    $taxable_items = array();

    if ($product_type === 'sheet_music') {
      // Digital sheet music (closest match: Digital Books - downloaded - non subscription - with permanent rights)
      $taxable_items[] = array(
        'amount_cents' => $base_amount_cents,
        'reference' => 'sheet_music_base',
        'tax_code' => 'txcd_10302000',
      );
    }

    if ($addon_amount_cents > 0) {
      // Monthly sheet music access (closest match: Digital Books - downloaded - subscription - with conditional rights)
      $taxable_items[] = array(
        'amount_cents' => $addon_amount_cents,
        'reference' => 'sheet_music_access',
        'tax_code' => 'txcd_10302002',
      );
    }

    $tax_result = $this->mrm_tax_calculate_for_items($address, $taxable_items, $currency);
    $tax_cents = (!empty($tax_result['ok'])) ? (int)($tax_result['tax_cents'] ?? 0) : 0;
    $tax_calc_id = (!empty($tax_result['ok'])) ? (string)($tax_result['calculation_id'] ?? '') : '';

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

    // Allow bank only on flows that do NOT need future off-session charging
    if (!$save_card && !$requires_customer_for_subscription && !$requires_customer_for_piece_purchase) {
      $payment_method_types = array('card', 'us_bank_account');
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
      'tax_calculation_id' => $tax_calc_id,
      'currency' => $currency,
      'product_type' => $product_type,
      'category' => (string)($p['category'] ?? ''),
      'composer_pct' => isset($p['composer_pct']) ? (int)$p['composer_pct'] : null,
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

    if (empty($address['country'])) {
      $address['country'] = 'US';
    }

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

    // Recompute tax on taxable items only.
    $taxable_items = array();

    if ($product_type === 'sheet_music') {
      $taxable_items[] = array('amount_cents'=>$base_amount_cents,'reference'=>'sheet_music_base','tax_code'=>'txcd_10302000');
    }

    if ($addon_amount_cents > 0) {
      $taxable_items[] = array('amount_cents'=>$addon_amount_cents,'reference'=>'sheet_music_access','tax_code'=>'txcd_10302002');
    }

    $currency = strtolower((string)($order['currency'] ?? 'usd'));

    $tax_result = $this->mrm_tax_calculate_for_items($address, $taxable_items, $currency);
    $tax_cents = (!empty($tax_result['ok'])) ? (int)($tax_result['tax_cents'] ?? 0) : 0;
    $tax_calc_id = (!empty($tax_result['ok'])) ? (string)($tax_result['calculation_id'] ?? '') : '';

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

    $this->update_order_amount_and_metadata($order_id, $new_total_cents, $meta);

    return new WP_REST_Response(array(
      'ok' => true,
      'order_id' => (int)$order_id,
      'payment_intent_id' => $pi_id,
      'base_amount_cents' => (int)$base_amount_cents,
      'addon_amount_cents' => (int)$addon_amount_cents,
      'tax_cents' => (int)$tax_cents,
      'total_cents' => (int)$new_total_cents,
      'currency' => $currency,
    ), 200);
  }

  private function mrm_maybe_create_sheet_music_subscription_from_initial_payment_intent($pi, $order = array()) {
    try {
      if (!is_array($pi)) return;
      $this->stripe_debug_log('entered Low Brass Lessons - Sheet Music Subscription Charge helper');

      $this->stripe_debug_log('subscription path entered from initial payment intent', array(
        'pi_id' => (string)($pi['id'] ?? ''),
      ));

      $pi_id = (string)($pi['id'] ?? '');
      if ($pi_id === '') return;

      if (!is_array($order) || empty($order)) {
        $order = $this->get_order_by_pi($pi_id);
      }

      $this->stripe_debug_log('subscription path order lookup complete', array(
        'pi_id' => $pi_id,
        'order_found' => (!empty($order) ? 'yes' : 'no'),
        'order_id' => (int)($order['id'] ?? 0),
      ));
      if (!is_array($order) || empty($order)) {
        error_log('[MRM Payments Hub] Subscription path skipped: order not found for PI ' . $pi_id);
        return;
      }

      $order_id = (int)($order['id'] ?? 0);
      if ($order_id <= 0) return;

      $pi_meta = isset($pi['metadata']) && is_array($pi['metadata']) ? $pi['metadata'] : array();
      $order_meta = $this->mrm_get_order_meta_array($order);
      $meta = array_merge($order_meta, $pi_meta);

      $addon_yes = (isset($meta['mrm_sheet_music_addon']) && strtolower((string)$meta['mrm_sheet_music_addon']) === 'yes');
      $product_type = (string)($order['product_type'] ?? '');
      if (!$addon_yes || $product_type !== 'lesson') {
        return;
      }

      $already_created_at = (string)$this->mrm_get_order_meta_value($order, 'mrm_sheet_music_subscription_created_at', '');
      $already_subscription_id = (string)$this->mrm_get_order_meta_value($order, 'mrm_sheet_music_subscription_id', '');
      if ($already_created_at !== '' || $already_subscription_id !== '') {
        return;
      }

      $order_status = (string)($order['status'] ?? '');
      if (!in_array($order_status, array('paid', 'completed', 'succeeded'), true)) {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'waiting_for_paid_order');
        return;
      }

      $price_id = $this->subscription_price_id();

      $this->stripe_debug_log('subscription path checking price id', array(
        'order_id' => $order_id,
        'price_id_present' => ($price_id !== '' ? 'yes' : 'no'),
      ));

      if ($price_id === '') {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'configuration_missing');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', 'Missing Stripe subscription Price ID.');
        error_log('[MRM Payments Hub] Subscription price id is not configured.');
        return;
      }

      $email = sanitize_email((string)($meta['mrm_customer_email'] ?? ''));
      if (!$email || !is_email($email)) {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'missing_email');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', 'Missing valid customer email.');
        return;
      }

      $existing = $this->mrm_get_active_sheet_music_subscription_by_email($email);
      if (!empty($existing['id'])) {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'already_active');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_id', (string)($existing['stripe_subscription_id'] ?? ''));
        return;
      }

      $customer_id = (string)($pi['customer'] ?? '');
      $payment_method_id = (string)($pi['payment_method'] ?? '');

      $this->stripe_debug_log('subscription path inspecting PI customer/payment method', array(
        'order_id' => $order_id,
        'pi_id' => $pi_id,
        'customer_id' => $customer_id,
        'payment_method_id' => $payment_method_id,
      ));

      if ($customer_id === '' || $payment_method_id === '') {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'missing_customer_or_payment_method');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', 'Missing Stripe customer or payment method on initial payment intent.');
        $this->stripe_debug_log('subscription path blocked: missing customer or payment method', array(
          'order_id' => $order_id,
          'pi_id' => $pi_id,
          'customer_id_present' => ($customer_id !== '' ? 'yes' : 'no'),
          'payment_method_present' => ($payment_method_id !== '' ? 'yes' : 'no'),
        ));
        error_log('[MRM Payments Hub] Cannot create sheet music subscription: missing customer or payment method.');
        return;
      }

      $start_ts = !empty($pi['created']) ? (int)$pi['created'] : time();
      $billing_cycle_anchor_ts = strtotime('+1 month', $start_ts);
      if (!$billing_cycle_anchor_ts || $billing_cycle_anchor_ts <= time()) {
        $billing_cycle_anchor_ts = time() + (31 * DAY_IN_SECONDS);
      }

      $this->stripe_debug_log('subscription billing cycle anchor computed', array(
        'order_id' => $order_id,
        'pi_id' => $pi_id,
        'start_ts' => $start_ts,
        'billing_cycle_anchor_ts' => $billing_cycle_anchor_ts,
      ));

      $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'creating');

      $this->stripe_debug_log('subscription create requested', array(
        'order_id' => $order_id,
        'customer_id' => $customer_id,
        'payment_method_id' => $payment_method_id,
        'price_id' => $price_id,
        'billing_cycle_anchor_ts' => $billing_cycle_anchor_ts,
        'email' => $email,
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
        )
      );

      $this->stripe_debug_log('subscription create response received', array(
        'order_id' => $order_id,
        'subscription_id' => (string)($subscription['id'] ?? ''),
        'subscription_status' => (string)($subscription['status'] ?? ''),
        'is_wp_error' => (is_wp_error($subscription) ? 'yes' : 'no'),
      ));

      if (is_wp_error($subscription)) {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'create_failed');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', $subscription->get_error_message());
        error_log('[MRM Payments Hub] Failed to create sheet music subscription: ' . $subscription->get_error_message());
        return;
      }

      $this->mrm_sync_local_sheet_music_subscription_from_stripe($subscription, $email);

      $this->stripe_debug_log('local subscription sync complete', array(
        'order_id' => $order_id,
        'subscription_id' => (string)($subscription['id'] ?? ''),
        'email' => $email,
      ));

      $subscription_id = (string)($subscription['id'] ?? '');
      if ($subscription_id !== '') {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_id', $subscription_id);
      }

      $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', (string)($subscription['status'] ?? 'created'));
      $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_created_at', current_time('mysql'));

      $local = $this->mrm_get_sheet_music_subscription_by_stripe_id($subscription_id);
      $email_sent_at = (string)$this->mrm_get_order_meta_value($order, 'mrm_sheet_music_subscription_enrollment_email_sent_at', '');

      if (is_array($local) && !empty($local) && $email_sent_at === '') {
        $sent = $this->mrm_send_sheet_music_subscription_enrollment_email($local, $billing_cycle_anchor_ts);
        if ($sent) {
          $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_enrollment_email_sent_at', current_time('mysql'));
          $this->stripe_debug_log('subscription enrollment email sent', array(
            'order_id' => $order_id,
            'subscription_id' => $subscription_id,
            'email' => $email,
          ));
        } else {
          $this->stripe_debug_log('subscription enrollment email failed to send', array(
            'order_id' => $order_id,
            'subscription_id' => $subscription_id,
            'email' => $email,
          ));
        }
      }
    } catch (Throwable $e) {
      $order_id = is_array($order) ? (int)($order['id'] ?? 0) : 0;
      if ($order_id > 0) {
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'runtime_exception');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', $e->getMessage());
      }
      $this->stripe_debug_log('subscription path runtime exception', array(
        'order_id' => $order_id,
        'message' => $e->getMessage(),
      ));
      error_log('[MRM Payments Hub] Subscription path runtime exception: ' . $e->getMessage());
      return;
    }
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

    // Rule 1: master list grants access to ANY piece
    $master = isset($lists['all-sheet-music']) && is_array($lists['all-sheet-music'])
      ? $lists['all-sheet-music'] : array();
    if (in_array(strtolower($email), $master, true)) return true;

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
  }

  public function handle_admin_post() {
    if (!is_admin()) return;
    if (!current_user_can('manage_options')) return;

    // Repair default lesson SKUs if they were wiped to 0 by a prior update bug
    $this->ensure_default_products();

    if (isset($_POST['mrm_pay_hub_nonce']) && wp_verify_nonce($_POST['mrm_pay_hub_nonce'], 'mrm_pay_hub_save')) {
      $settings = $this->get_settings();
      // Stripe environment mode: 'live' or 'test'
      $settings['stripe_mode'] = sanitize_text_field((string)($_POST['stripe_mode'] ?? 'live'));
      // Live keys
      $settings['stripe_publishable_key'] = sanitize_text_field((string)($_POST['stripe_publishable_key'] ?? ''));
      $settings['stripe_secret_key'] = sanitize_text_field((string)($_POST['stripe_secret_key'] ?? ''));
      $settings['stripe_webhook_secret'] = sanitize_text_field((string)($_POST['stripe_webhook_secret'] ?? ''));
      $settings['stripe_sheet_music_subscription_price_id'] = sanitize_text_field((string)($_POST['stripe_sheet_music_subscription_price_id'] ?? ''));
      // Test keys
      $settings['stripe_test_publishable_key'] = sanitize_text_field((string)($_POST['stripe_test_publishable_key'] ?? ''));
      $settings['stripe_test_secret_key'] = sanitize_text_field((string)($_POST['stripe_test_secret_key'] ?? ''));
      $settings['stripe_test_webhook_secret'] = sanitize_text_field((string)($_POST['stripe_test_webhook_secret'] ?? ''));
      $settings['stripe_test_sheet_music_subscription_price_id'] = sanitize_text_field((string)($_POST['stripe_test_sheet_music_subscription_price_id'] ?? ''));
      $settings['composer_connected_account_id'] = sanitize_text_field((string)($_POST['composer_connected_account_id'] ?? ''));
      $settings['instructor_tier_rules'] = trim((string) wp_unslash($_POST['instructor_tier_rules'] ?? "0=50\n12=55\n24=60"));
      $settings['payout_anchor_date'] = sanitize_text_field((string)($_POST['payout_anchor_date'] ?? ''));
      $this->save_settings($settings);

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
      $composer_pcts = isset($_POST['composer_pct']) ? (array)$_POST['composer_pct'] : array();
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

        $composer_pct = null;
        if (isset($composer_pcts[$index]) && $composer_pcts[$index] !== '') {
          $composer_pct = intval($composer_pcts[$index]);
          if ($composer_pct < 0) $composer_pct = 0;
          if ($composer_pct > 100) $composer_pct = 100;
        }

        $products[$sku] = array(
          'sku' => $sku,
          'label' => $label,
          'amount_cents' => $final_amount_cents,
          'currency' => $currency,
          'product_type' => $type,
          'category' => $category,
          'active' => 1,
        );

        if ($composer_pct !== null) {
          $products[$sku]['composer_pct'] = $composer_pct;
        } else {
          unset($products[$sku]['composer_pct']);
        }
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
            <p>
              <label>Composer %<br />
                <input type="number" name="composer_pct[<?php echo esc_attr($current_index); ?>]" value="<?php echo esc_attr((string)($product['composer_pct'] ?? '')); ?>" class="small-text" min="0" max="100" />
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
          <p>
            <label>Composer %<br />
              <input type="number" name="composer_pct[<?php echo esc_attr($new_index); ?>]" value="" class="small-text" min="0" max="100" />
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

  public function render_admin_page() {
    if (!current_user_can('manage_options')) return;

    settings_errors('mrm_pay_hub');

    $settings = $this->get_settings();
    $pk_live   = esc_attr((string)($settings['stripe_publishable_key'] ?? ''));
    $sk_live   = esc_attr((string)($settings['stripe_secret_key'] ?? ''));
    $wh_live   = esc_attr((string)($settings['stripe_webhook_secret'] ?? ''));
    $price_live = esc_attr((string)($settings['stripe_sheet_music_subscription_price_id'] ?? ''));
    $pk_test   = esc_attr((string)($settings['stripe_test_publishable_key'] ?? ''));
    $sk_test   = esc_attr((string)($settings['stripe_test_secret_key'] ?? ''));
    $wh_test   = esc_attr((string)($settings['stripe_test_webhook_secret'] ?? ''));
    $price_test = esc_attr((string)($settings['stripe_test_sheet_music_subscription_price_id'] ?? ''));
    $composer_acct = esc_attr((string)($settings['composer_connected_account_id'] ?? ''));
    $tier_rules = esc_textarea((string)($settings['instructor_tier_rules'] ?? "0=50\n12=55\n24=60"));
    $payout_anchor_date = esc_attr((string)($settings['payout_anchor_date'] ?? ''));
    $mode      = esc_attr((string)($settings['stripe_mode'] ?? 'live'));

    ?>
    <div class="wrap">
      <h1>MRM Payments Hub</h1>

      <form method="post">
        <?php wp_nonce_field('mrm_pay_hub_save', 'mrm_pay_hub_nonce'); ?>

        <h2>Stripe Environment & Keys</h2>
        <p>Select whether to operate in live or test (sandbox) mode. Provide the appropriate keys for each environment. The mode determines which keys are used for API calls.</p>
        <table class="form-table">
          <tr>
            <th scope="row">Mode</th>
            <td>
              <fieldset>
                <label><input type="radio" name="stripe_mode" value="live" <?php checked($mode, 'live'); ?> /> Live</label><br />
                <label><input type="radio" name="stripe_mode" value="test" <?php checked($mode, 'test'); ?> /> Test (Sandbox)</label>
              </fieldset>
            </td>
          </tr>
        </table>
        <h3>Live Keys</h3>
        <table class="form-table">
          <tr>
            <th scope="row"><label for="stripe_publishable_key">Publishable Key</label></th>
            <td><input type="text" id="stripe_publishable_key" name="stripe_publishable_key" value="<?php echo $pk_live; ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="stripe_secret_key">Secret Key</label></th>
            <td><input type="password" id="stripe_secret_key" name="stripe_secret_key" value="<?php echo $sk_live; ?>" class="regular-text" autocomplete="off" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="stripe_webhook_secret">Webhook Signing Secret (optional)</label></th>
            <td><input type="password" id="stripe_webhook_secret" name="stripe_webhook_secret" value="<?php echo $wh_live; ?>" class="regular-text" autocomplete="off" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="stripe_sheet_music_subscription_price_id">Sheet Music Subscription Price ID</label></th>
            <td>
              <input type="text" id="stripe_sheet_music_subscription_price_id" name="stripe_sheet_music_subscription_price_id" value="<?php echo $price_live; ?>" class="regular-text" placeholder="price_..." />
              <p class="description">Paste the live Stripe recurring Price ID for the $5 monthly sheet music subscription.</p>
            </td>
          </tr>
        </table>

        <h3>Test Keys</h3>
        <table class="form-table">
          <tr>
            <th scope="row"><label for="stripe_test_publishable_key">Publishable Key (Test)</label></th>
            <td><input type="text" id="stripe_test_publishable_key" name="stripe_test_publishable_key" value="<?php echo $pk_test; ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="stripe_test_secret_key">Secret Key (Test)</label></th>
            <td><input type="password" id="stripe_test_secret_key" name="stripe_test_secret_key" value="<?php echo $sk_test; ?>" class="regular-text" autocomplete="off" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="stripe_test_webhook_secret">Webhook Signing Secret (Test, optional)</label></th>
            <td><input type="password" id="stripe_test_webhook_secret" name="stripe_test_webhook_secret" value="<?php echo $wh_test; ?>" class="regular-text" autocomplete="off" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="stripe_test_sheet_music_subscription_price_id">Sheet Music Subscription Price ID (Test)</label></th>
            <td>
              <input type="text" id="stripe_test_sheet_music_subscription_price_id" name="stripe_test_sheet_music_subscription_price_id" value="<?php echo $price_test; ?>" class="regular-text" placeholder="price_..." />
              <p class="description">Paste the test Stripe recurring Price ID for the $5 monthly sheet music subscription.</p>
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
            <th scope="row"><label for="instructor_tier_rules">Instructor Tier Rules</label></th>
            <td>
              <textarea id="instructor_tier_rules" name="instructor_tier_rules" rows="6" class="large-text code"><?php echo $tier_rules; ?></textarea>
              <p class="description">One rule per line in the format <code>months=pct</code>. Example:<br><code>0=50<br>12=55<br>24=60</code></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="payout_anchor_date">Biweekly Payout Anchor Date</label></th>
            <td>
              <input type="date" id="payout_anchor_date" name="payout_anchor_date" value="<?php echo $payout_anchor_date; ?>" />
              <p class="description">Use the first Friday that should count as a payout Friday. Every 14 days after that is a payout day.</p>
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
                            <th>Purchase date</th>
                            <th>Stripe subscription active</th>
                            <th>Expires</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php if (!empty($subscription_rows)) : ?>
                            <?php foreach ($subscription_rows as $sub_row) :
                              $purchase_date = !empty($sub_row['created_at']) ? date_i18n('Y-m-d', strtotime($sub_row['created_at'])) : '';
                              $is_active = $this->mrm_is_sheet_music_subscription_active_for_admin($sub_row);
                              $expire_label = $this->mrm_sheet_music_subscription_end_date_for_admin($sub_row);
                            ?>
                              <tr>
                                <td><?php echo esc_html((string)($sub_row['email_plain'] ?? '')); ?></td>
                                <td><?php echo esc_html($purchase_date); ?></td>
                                <td style="text-align:center;"><?php echo $is_active ? '✔' : 'X'; ?></td>
                                <td><?php echo esc_html($expire_label); ?></td>
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
  if (!wp_next_scheduled('mrm_pay_hub_cleanup_access')) {
    wp_schedule_event(time() + 300, 'hourly', 'mrm_pay_hub_cleanup_access');
  }

  if (!wp_next_scheduled('mrm_pay_hub_daily_payout_check')) {
    wp_schedule_event(time() + 600, 'daily', 'mrm_pay_hub_daily_payout_check');
  }

  if (!wp_next_scheduled('mrm_pay_hub_retry_autopay_charges')) {
    wp_schedule_event(time() + 240, 'mrm_10min', 'mrm_pay_hub_retry_autopay_charges');
  }
});

register_deactivation_hook(__FILE__, function() {
  wp_clear_scheduled_hook('mrm_pay_hub_cleanup_access');
  wp_clear_scheduled_hook('mrm_pay_hub_daily_payout_check');
  wp_clear_scheduled_hook('mrm_pay_hub_retry_autopay_charges');
});

/**
 * Optional helper for other plugins (PHP-level access)
 */
function mrm_pay_hub() {
  global $mrm_pay_hub_singleton;
  // not used here, but kept as a convention point
  return null;
}
