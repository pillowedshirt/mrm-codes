<?php
/**
 * Plugin Name: MRM Payments Hub (Single File)
 * Description: Central product catalog + Stripe PaymentIntents + labeling metadata + orders ledger for MRM scheduler and sheet music plugins.
 * Version: 1.0.0
 * Author: Matt Rose
 */

if ( ! defined( 'MRM_LAUNCH_DEBUG' ) ) {
  define( 'MRM_LAUNCH_DEBUG', false );
}

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
    const OPT_PROMO_CODES = 'mrm_pay_hub_promo_codes';
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
    add_action('admin_post_mrm_export_legal_ledger', array($this, 'handle_export_legal_ledger'));
    add_action('admin_post_mrm_pay_hub_run_selected_payouts', array($this, 'handle_run_selected_payouts'));
    add_action('admin_post_mrm_pay_hub_run_all_payee_payouts', array($this, 'handle_run_all_payee_payouts'));
    add_action('admin_post_mrm_pay_hub_run_presenter_payout', array($this, 'handle_run_presenter_payout'));
    add_action('admin_post_mrm_pay_hub_run_all_due_presenter_payouts', array($this, 'handle_run_all_due_presenter_payouts'));
    // BEGIN TEMP PAYMENT HUB AUDIT
    add_action('admin_post_mrm_pay_hub_run_temp_audit', array($this, 'handle_temp_payment_hub_audit_run'));
    // END TEMP PAYMENT HUB AUDIT

    /**
     * Marketing Email Lists admin + unsubscribe actions.
     */
    add_action('admin_post_mrm_marketing_email_save_lists', array($this, 'handle_marketing_email_save_lists'));
    add_action('admin_post_mrm_marketing_email_send', array($this, 'handle_marketing_email_send'));
    add_action('admin_post_mrm_pay_hub_send_email_tests', array($this, 'handle_email_testing_send'));
    add_action('admin_post_mrm_pay_hub_preview_email_tests', array($this, 'handle_email_testing_preview'));
    add_action('admin_post_mrm_marketing_resubscribe', array($this, 'handle_marketing_resubscribe'));
    add_action('admin_post_mrm_marketing_unsubscribe_confirm', array($this, 'handle_marketing_unsubscribe_confirm'));
    add_action('admin_post_nopriv_mrm_marketing_unsubscribe_confirm', array($this, 'handle_marketing_unsubscribe_confirm'));
    add_action('admin_post_mrm_marketing_unsubscribe_do', array($this, 'handle_marketing_unsubscribe_do'));
    add_action('admin_post_nopriv_mrm_marketing_unsubscribe_do', array($this, 'handle_marketing_unsubscribe_do'));
    add_action('admin_post_mrm_profile_card_create_invite', array($this, 'handle_profile_card_create_invite'));
    add_action('admin_post_mrm_autopay_update_payment', array($this, 'handle_autopay_update_payment'));
    add_action('admin_post_nopriv_mrm_autopay_update_payment', array($this, 'handle_autopay_update_payment'));
    add_action('admin_post_mrm_lesson_cancel_request', array($this, 'render_lesson_cancel_request'));
    add_action('admin_post_nopriv_mrm_lesson_cancel_request', array($this, 'render_lesson_cancel_request'));
    add_action('admin_post_mrm_lesson_cancel_submit', array($this, 'handle_lesson_cancel_submit'));
    add_action('admin_post_nopriv_mrm_lesson_cancel_submit', array($this, 'handle_lesson_cancel_submit'));
    add_action('admin_post_mrm_profile_card_admin_action', array($this, 'handle_profile_card_admin_action'));
    add_action('admin_post_mrm_profile_card_download_private_file', array($this, 'handle_profile_card_download_private_file'));
    add_action('admin_post_mrm_profile_card_form', array($this, 'render_profile_card_public_form'));
    add_action('admin_post_nopriv_mrm_profile_card_form', array($this, 'render_profile_card_public_form'));
    add_action('admin_post_mrm_profile_card_submit', array($this, 'handle_profile_card_public_submit'));
    add_action('admin_post_nopriv_mrm_profile_card_submit', array($this, 'handle_profile_card_public_submit'));
    add_action('rest_api_init', array($this, 'register_routes'));
    add_action('init', array($this, 'maybe_install_or_upgrade_db'), 5);
    add_action('init', array($this, 'mrm_run_instructor_piece_access_sync'));

    add_filter('cron_schedules', array($this, 'register_custom_cron_schedules'));
    add_action('init', array($this, 'ensure_runtime_cron_schedules'), 20);

    add_action('mrm_pay_hub_cleanup_access', array($this, 'cleanup_access'));
    add_action('mrm_pay_hub_daily_payout_check', array($this, 'cron_daily_payout_check'));
    add_action('mrm_pay_hub_daily_presenter_payout_check', array($this, 'cron_daily_presenter_payout_check'));
    add_action('mrm_pay_hub_retry_autopay_charges', array($this, 'cron_retry_autopay_charges'));
    add_action('mrm_pay_hub_discover_missed_autopay_lessons', array($this, 'cron_discover_missed_autopay_lessons'));
    add_action('mrm_pay_hub_reset_stuck_autopay_lessons', array($this, 'cron_reset_stuck_autopay_lessons'));
    add_action('mrm_pay_hub_check_upcoming_payment_methods', array($this, 'cron_check_upcoming_payment_methods'));
    add_action('mrm_pay_hub_retry_sheet_music_subscriptions', array($this, 'cron_retry_sheet_music_subscriptions'));
    add_action('mrm_sheet_music_subscription_renewal_reminder_cron', array($this, 'cron_send_sheet_music_subscription_renewal_reminders'));

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
    $this->mrm_schedule_daily_presenter_payout_check();

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

    if (!wp_next_scheduled('mrm_sheet_music_subscription_renewal_reminder_cron')) {
      wp_schedule_event(time() + 360, 'hourly', 'mrm_sheet_music_subscription_renewal_reminder_cron');
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
    $this->mrm_schedule_daily_presenter_payout_check();

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

    if (!wp_next_scheduled('mrm_sheet_music_subscription_renewal_reminder_cron')) {
      wp_schedule_event(time() + 360, 'hourly', 'mrm_sheet_music_subscription_renewal_reminder_cron');
    }
  }

  private function mrm_csv_send($filename, $headers, $rows) {
    $filename = sanitize_file_name((string)$filename);
    if ($filename === '') {
      $filename = 'export.csv';
    }

    if (!is_array($headers)) {
      $headers = array();
    }

    if (!is_array($rows)) {
      $rows = array();
    }

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');

    if (!empty($headers)) {
      fputcsv($out, $headers);
    }

    foreach ($rows as $row) {
      fputcsv($out, is_array($row) ? $row : array($row));
    }

    fclose($out);
    exit;
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

  private function table_promo_redemptions() {
    global $wpdb;
    return $wpdb->prefix . 'mrm_promo_redemptions';
  }

  private function table_profile_card_requests() {
    global $wpdb;
    return $wpdb->prefix . 'mrm_profile_card_requests';
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
    $promo_redemptions = $this->table_promo_redemptions();
    $profile_requests = $this->table_profile_card_requests();

    $needs_upgrade = false;

    // 1) Table existence check
    foreach (array($orders, $links, $access, $payouts, $credits, $autopay, $webhooks, $subs, $promo_redemptions, $profile_requests) as $t) {
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
          break;
        }
      }
    }

    // 4) Promo redemption schema check
    if (!$needs_upgrade) {
      $promo_cols = $wpdb->get_results("SHOW COLUMNS FROM {$promo_redemptions}");
      $promo_col_names = array();

      if (is_array($promo_cols)) {
        foreach ($promo_cols as $col) {
          if (!empty($col->Field)) {
            $promo_col_names[] = (string)$col->Field;
          }
        }
      }

      foreach (array('customer_email') as $required_col) {
        if (!in_array($required_col, $promo_col_names, true)) {
          $needs_upgrade = true;
          break;
        }
      }
    }

    if ($needs_upgrade) {
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
    $promo_redemptions = $this->table_promo_redemptions();
    $profile_requests = $this->table_profile_card_requests();

    $sql_orders = "CREATE TABLE {$orders} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      email_hash CHAR(64) NOT NULL,
      customer_email VARCHAR(190) DEFAULT NULL,
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
      KEY customer_email_idx (customer_email),
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
  promo_code VARCHAR(80) DEFAULT NULL,
  promo_started_at DATETIME DEFAULT NULL,
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
      renewal_reminder_24h_sent_at DATETIME NULL,
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

    $sql_promo_redemptions = "CREATE TABLE {$promo_redemptions} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      promo_code VARCHAR(80) NOT NULL,
      email_hash CHAR(64) NOT NULL,
      customer_email VARCHAR(190) DEFAULT NULL,
      order_id BIGINT UNSIGNED DEFAULT NULL,
      stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'pending',
      ip_hash CHAR(64) DEFAULT NULL,
      user_agent_hash CHAR(64) DEFAULT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY promo_email_lookup (promo_code, email_hash),
      KEY promo_code_idx (promo_code),
      KEY email_hash_idx (email_hash),
      KEY customer_email_idx (customer_email),
      KEY status_idx (status),
      KEY order_idx (order_id),
      KEY pi_idx (stripe_payment_intent_id)
    ) {$charset};";


    $sql_profile_requests = "CREATE TABLE {$profile_requests} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      request_type VARCHAR(40) NOT NULL DEFAULT 'instructor_profile',
      status VARCHAR(40) NOT NULL DEFAULT 'draft',
      recipient_name VARCHAR(191) NOT NULL DEFAULT '',
      recipient_email VARCHAR(191) NOT NULL DEFAULT '',
      token_hash CHAR(64) NOT NULL,
      token_expires_at DATETIME NULL,
      admin_payload LONGTEXT NULL,
      submission_payload LONGTEXT NULL,
      review_notes LONGTEXT NULL,
      uploaded_files LONGTEXT NULL,
      created_target_type VARCHAR(40) NULL,
      created_target_id BIGINT UNSIGNED NULL,
      sent_at DATETIME NULL,
      submitted_at DATETIME NULL,
      reviewed_at DATETIME NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY request_type_idx (request_type),
      KEY status_idx (status),
      KEY recipient_email_idx (recipient_email),
      KEY token_hash_idx (token_hash),
      KEY created_target_idx (created_target_type, created_target_id)
    ) {$charset};";

    dbDelta($sql_orders);
    dbDelta($sql_links);
    dbDelta($sql_access);
    dbDelta($sql_payouts);
    dbDelta($sql_credits);
    dbDelta($sql_autopay);
    dbDelta($sql_webhooks);
    dbDelta($sql_subs);
    dbDelta($sql_promo_redemptions);
    dbDelta($sql_profile_requests);

    // Promo codes may be reusable per email, so the old unique index must be removed.
    // dbDelta() does not reliably remove old indexes, so do it explicitly.
    $promo_unique_exists = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(1)
       FROM information_schema.statistics
       WHERE table_schema = DATABASE()
         AND table_name = %s
         AND index_name = 'promo_email_unique'",
      $promo_redemptions
    ));

    if ((int)$promo_unique_exists > 0) {
      $wpdb->query("ALTER TABLE {$promo_redemptions} DROP INDEX promo_email_unique");
    }

    $promo_lookup_exists = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(1)
       FROM information_schema.statistics
       WHERE table_schema = DATABASE()
         AND table_name = %s
         AND index_name = 'promo_email_lookup'",
      $promo_redemptions
    ));

    if ((int)$promo_lookup_exists <= 0) {
      $wpdb->query("ALTER TABLE {$promo_redemptions} ADD KEY promo_email_lookup (promo_code, email_hash)");
    }

    $autopay_columns = $wpdb->get_results("SHOW COLUMNS FROM {$autopay}", ARRAY_A);
    $autopay_column_names = array();

    if (is_array($autopay_columns)) {
      foreach ($autopay_columns as $col) {
        if (!empty($col['Field'])) {
          $autopay_column_names[] = (string)$col['Field'];
        }
      }
    }

    if (!in_array('promo_code', $autopay_column_names, true)) {
      $wpdb->query("ALTER TABLE {$autopay} ADD promo_code VARCHAR(80) DEFAULT NULL AFTER charged_lesson_count");
    }

    if (!in_array('promo_started_at', $autopay_column_names, true)) {
      $wpdb->query("ALTER TABLE {$autopay} ADD promo_started_at DATETIME DEFAULT NULL AFTER promo_code");
    }
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

  private function mrm_get_promo_codes() {
  $codes = get_option(self::OPT_PROMO_CODES, array());
  return is_array($codes) ? $codes : array();
}

private function mrm_save_promo_codes($codes) {
  update_option(self::OPT_PROMO_CODES, is_array($codes) ? $codes : array(), false);
}

private function mrm_normalize_promo_code($code) {
  $code = strtoupper(trim((string)$code));
  $code = preg_replace('/[^A-Z0-9\-_]/', '', $code);
  return substr($code, 0, 80);
}

private function mrm_get_active_promo_code($code) {
  $code = $this->mrm_normalize_promo_code($code);
  if ($code === '') return null;

  $codes = $this->mrm_get_promo_codes();
  if (empty($codes[$code]) || !is_array($codes[$code])) {
    return null;
  }

  $promo = $codes[$code];

  if (empty($promo['active'])) {
    return null;
  }

  $expires = trim((string)($promo['expires_at'] ?? ''));
  if ($expires !== '') {
    $expires_ts = strtotime($expires . ' 23:59:59');
    if ($expires_ts && $expires_ts < current_time('timestamp')) {
      return null;
    }
  }

  $promo['code'] = $code;
  return $promo;
}

private function mrm_scope_allows_promo_for_product($promo, $product_type) {
  $promo = is_array($promo) ? $promo : array();
  $product_type = sanitize_key((string)$product_type);
  $scopes = array();

  if (isset($promo['scopes']) && is_array($promo['scopes'])) {
    $scopes = array_values(array_filter(array_map('sanitize_key', $promo['scopes'])));
  }

  if (empty($scopes)) {
    $legacy_scope = sanitize_key((string)($promo['scope'] ?? 'all'));
    if ($legacy_scope === 'all') {
      $scopes = array('lesson', 'sheet_music', 'masterclass');
    } elseif (in_array($legacy_scope, array('lesson', 'sheet_music', 'masterclass'), true)) {
      $scopes = array($legacy_scope);
    }
  }

  $scopes = array_values(array_intersect($scopes, array('lesson', 'sheet_music', 'masterclass')));
  return !empty($scopes) && in_array($product_type, $scopes, true);
}

private function mrm_customer_has_used_promo($code, $email_hash, $promo = array()) {
  global $wpdb;

  $code = $this->mrm_normalize_promo_code($code);
  $email_hash = (string)$email_hash;
  $promo = is_array($promo) ? $promo : array();

  if ($code === '' || $email_hash === '') {
    return false;
  }

  /*
   * Reusable-per-email promos are allowed to be redeemed repeatedly by the
   * same email address. Each successful use is still recorded as a separate
   * redemption row.
   */
  if (!empty($promo['reusable_per_email'])) {
    return false;
  }

  $table = $this->table_promo_redemptions();

  $found_table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
  if ($found_table !== $table) {
    $this->install_or_upgrade_db();
  }

  $wpdb->query($wpdb->prepare(
    "UPDATE {$table}
     SET status = 'expired', updated_at = %s
     WHERE promo_code = %s
       AND email_hash = %s
       AND status = 'pending'
       AND created_at < %s",
    current_time('mysql'),
    $code,
    $email_hash,
    gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)
  ));

  $found = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$table}
     WHERE promo_code = %s
       AND email_hash = %s
       AND status = 'paid'
     LIMIT 1",
    $code,
    $email_hash
  ));

  return !empty($found);
}


private function mrm_promo_date_in_window($promo, $context = array()) {
  $promo = is_array($promo) ? $promo : array();
  $context = is_array($context) ? $context : array();

  $now_ts = !empty($context['now_ts']) ? (int)$context['now_ts'] : current_time('timestamp');

  $starts_at = trim((string)($promo['starts_at'] ?? ''));

  /*
   * expires_at is the canonical saved field. ends_at is supported as a
   * backwards-compatible alias in case older saved promo data used that name.
   */
  $ends_at = trim((string)($promo['expires_at'] ?? ''));
  if ($ends_at === '') {
    $ends_at = trim((string)($promo['ends_at'] ?? ''));
  }

  if ($starts_at !== '') {
    $start_ts = strtotime($starts_at . ' 00:00:00');
    if ($start_ts && $now_ts < $start_ts) {
      return false;
    }
  }

  if ($ends_at !== '') {
    $end_ts = strtotime($ends_at . ' 23:59:59');
    if ($end_ts && $now_ts > $end_ts) {
      return false;
    }
  }

  return true;
}



private function mrm_promo_rule_allows_context($promo, $context = array()) {
  $promo = is_array($promo) ? $promo : array();
  $context = is_array($context) ? $context : array();
  $rule_mode = (string)($promo['rule_mode'] ?? 'all');
  $occurrence_number = isset($context['occurrence_number']) ? max(1, absint($context['occurrence_number'])) : 1;
  $occurrence_count = isset($promo['occurrence_count']) ? max(0, absint($promo['occurrence_count'])) : 0;
  if (!$this->mrm_promo_date_in_window($promo, $context)) return array('ok'=>false,'message'=>'This promotional code is not active for the current date.',);
  if ($rule_mode === 'first_n' && $occurrence_count > 0 && $occurrence_number > $occurrence_count) return array('ok'=>false,'message'=>'This promotional code has already reached its occurrence limit.',);
  if ($rule_mode === 'after_n' && $occurrence_count > 0 && $occurrence_number <= $occurrence_count) return array('ok'=>false,'message'=>'This promotional code starts after occurrence ' . $occurrence_count . '.',);
  if ($rule_mode === 'first_n_months') { $months = $occurrence_count > 0 ? $occurrence_count : 1; $started_at = trim((string)($context['promo_started_at'] ?? '')); if ($started_at !== '') { $start_ts = strtotime($started_at); $now_ts = !empty($context['now_ts']) ? (int)$context['now_ts'] : current_time('timestamp'); if ($start_ts) { $end_ts = strtotime('+' . $months . ' months', $start_ts); if ($end_ts && $now_ts >= $end_ts) return array('ok'=>false,'message'=>'This promotional code is outside its month-based discount window.',); } } }
  return array('ok'=>true,'message'=>'',);
}

private function mrm_promo_eligible_occurrences_for_context($promo, $context = array()) {
  $promo = is_array($promo) ? $promo : array();
  $context = is_array($context) ? $context : array();

  $lesson_count = isset($context['lesson_count']) ? max(1, absint($context['lesson_count'])) : 1;
  $occurrence_number = isset($context['occurrence_number']) ? max(1, absint($context['occurrence_number'])) : 1;
  $rule_mode = (string)($promo['rule_mode'] ?? 'all');
  $occurrence_count = isset($promo['occurrence_count']) ? max(0, absint($promo['occurrence_count'])) : 0;
  if ($rule_mode === 'first_n' && $occurrence_count > 0) {
    $eligible_remaining = max(0, $occurrence_count - ($occurrence_number - 1));
    return min($lesson_count, $eligible_remaining);
  }
  if ($rule_mode === 'after_n' && $occurrence_count > 0) {
    $eligible = 0;
    for ($i = 0; $i < $lesson_count; $i++) { if ($occurrence_number + $i > $occurrence_count) $eligible++; }
    return min($lesson_count, $eligible);
  }
  return $lesson_count;
}

private function mrm_calculate_promo_discount_cents($promo, $base_amount_cents, $product_type, $context = array()) {
  $promo = is_array($promo) ? $promo : array();
  $context = is_array($context) ? $context : array();

  $base_amount_cents = max(0, (int)$base_amount_cents);

  if ($base_amount_cents <= 0) {
    return array(
      'ok' => false,
      'message' => 'Invalid purchase amount.',
      'discount_cents' => 0,
    );
  }

  if (!$this->mrm_scope_allows_promo_for_product($promo, $product_type)) {
    return array(
      'ok' => false,
      'message' => 'This promotional code does not apply to this purchase type.',
      'discount_cents' => 0,
    );
  }

  $rule_check = $this->mrm_promo_rule_allows_context($promo, $context);
  if (empty($rule_check['ok'])) {
    return array(
      'ok' => false,
      'message' => (string)($rule_check['message'] ?? 'This promotional code does not apply to this occurrence.'),
      'discount_cents' => 0,
    );
  }

  $discount_type = (string)($promo['discount_type'] ?? 'percent');
  $lesson_count = isset($context['lesson_count']) ? max(1, absint($context['lesson_count'])) : 1;
  $eligible_occurrences = $this->mrm_promo_eligible_occurrences_for_context($promo, $context);

  if ($eligible_occurrences <= 0) {
    return array(
      'ok' => false,
      'message' => 'This promotional code does not apply to this occurrence.',
      'discount_cents' => 0,
    );
  }

  $eligible_amount_cents = $base_amount_cents;

  if ($product_type === 'lesson') {
    $per_lesson_cents = (int)floor($base_amount_cents / $lesson_count);

    $eligible_amount_cents = min($base_amount_cents, $per_lesson_cents * $eligible_occurrences);
  }

  $discount_cents = 0;

  if ($discount_type === 'percent') {
    $percent = max(0, min(100, (int)($promo['percent_off'] ?? 0)));
    $discount_cents = (int)floor($eligible_amount_cents * ($percent / 100));
  } else {
    $amount_off_cents = max(0, (int)($promo['amount_off_cents'] ?? 0));

    if ($product_type === 'lesson' && $lesson_count > 1) {
      $discount_cents = min($amount_off_cents * $eligible_occurrences, $eligible_amount_cents);
    } else {
      $discount_cents = min($amount_off_cents, $eligible_amount_cents);
    }
  }

  $discount_cents = min($discount_cents, $eligible_amount_cents);

  /*
   * Avoid creating a $0 card PaymentIntent.
   */
  $max_safe_discount = max(0, $base_amount_cents - 50);
  $discount_cents = min($discount_cents, $max_safe_discount);

  return array(
    'ok' => true,
    'message' => '',
    'discount_cents' => $discount_cents,
    'eligible_amount_cents' => $eligible_amount_cents,
    'eligible_occurrences' => $eligible_occurrences,
  );
}


private function mrm_validate_promo_for_purchase($code, $email, $product_type, $base_amount_cents, $context = array()) {
  $code = $this->mrm_normalize_promo_code($code);

  if ($code === '') {
    return array(
      'ok' => false,
      'message' => 'Please enter a promotional code.',
      'discount_cents' => 0,
    );
  }

  $email = sanitize_email((string)$email);
  if (!$email || !is_email($email)) {
    return array(
      'ok' => false,
      'message' => 'Please enter a valid email before applying a promotional code.',
      'discount_cents' => 0,
    );
  }

  $promo = $this->mrm_get_active_promo_code($code);
  if (!$promo) {
    return array(
      'ok' => false,
      'message' => 'This promotional code is invalid or expired.',
      'discount_cents' => 0,
    );
  }

  $email_hash = $this->email_hash($email);

  if ($this->mrm_customer_has_used_promo($code, $email_hash, $promo)) {
    return array(
      'ok' => false,
      'code' => 'promo_code_already_used',
      'message' => 'This email has already used this promotional code.',
      'discount_cents' => 0,
    );
  }

  $calc = $this->mrm_calculate_promo_discount_cents($promo, $base_amount_cents, $product_type, $context);

  if (empty($calc['ok'])) {
    return $calc;
  }

  return array(
    'ok' => true,
    'message' => 'Promotional code applied.',
    'promo' => $promo,
    'code' => $code,
    'discount_cents' => (int)$calc['discount_cents'],
    'eligible_amount_cents' => (int)($calc['eligible_amount_cents'] ?? $base_amount_cents),
    'eligible_occurrences' => (int)($calc['eligible_occurrences'] ?? 1),
  );
}

public function resolve_promo_discount($promo_code, $amount_cents, $context = array()) {
  $promo_code = $this->mrm_normalize_promo_code($promo_code);
  $amount_cents = max(0, absint($amount_cents));
  $context = is_array($context) ? $context : array();
  $email = sanitize_email((string)($context['email'] ?? ''));
  $product_type = sanitize_key((string)($context['product_type'] ?? 'lesson'));

  if ($promo_code === '' || $amount_cents <= 0) {
    return array('ok'=>false,'discount_cents'=>0,'message'=>'No promotional code was provided.');
  }

  $result = $this->mrm_validate_promo_for_purchase($promo_code, $email, $product_type, $amount_cents, $context);

  if (empty($result['ok'])) {
    return $result;
  }

  $discount_cents = max(0, min($amount_cents, (int)($result['discount_cents'] ?? 0)));

  if ($discount_cents <= 0) {
    return array('ok'=>false,'discount_cents'=>0,'message'=>'This promotional code does not apply to this purchase.');
  }

  return array_merge($result, array(
    'ok' => true,
    'promo_code' => $promo_code,
    'discount_cents' => $discount_cents,
    'discount_label' => '$' . number_format($discount_cents / 100, 2) . ' off',
    'original_amount_cents' => $amount_cents,
    'final_amount_cents' => max(0, $amount_cents - $discount_cents),
  ));
}

private function mrm_reserve_promo_redemption($code, $email_hash, $order_id, $payment_intent_id = '', $customer_email = '', $promo = array()) {
  global $wpdb;
  $code = $this->mrm_normalize_promo_code($code);
  $email_hash = (string)$email_hash;
  $order_id = (int)$order_id;
  $customer_email = sanitize_email((string)$customer_email);
  $promo = is_array($promo) ? $promo : array();
  if ($code === '' || $email_hash === '' || $order_id <= 0) return false;
  $table = $this->table_promo_redemptions();
  $found_table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
  if ($found_table !== $table) $this->install_or_upgrade_db();
  $is_reusable = !empty($promo['reusable_per_email']);
  if (!$is_reusable) {
    $paid_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE promo_code = %s AND email_hash = %s AND status = 'paid' LIMIT 1", $code, $email_hash));
    if (!empty($paid_id)) return false;
  }
  $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string)$_SERVER['REMOTE_ADDR']) : '';
  $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string)$_SERVER['HTTP_USER_AGENT']) : '';
  $now = current_time('mysql');
  $row = array('promo_code'=>$code,'email_hash'=>$email_hash,'customer_email'=>$customer_email ?: null,'order_id'=>$order_id,'stripe_payment_intent_id'=>sanitize_text_field((string)$payment_intent_id),'status'=>'pending','ip_hash'=>$ip !== '' ? hash('sha256', $ip) : null,'user_agent_hash'=>$ua !== '' ? hash('sha256', $ua) : null,'created_at'=>$now,'updated_at'=>$now,);
  $inserted = $wpdb->insert($table,$row,array('%s','%s','%s','%d','%s','%s','%s','%s','%s','%s'));
  return !empty($inserted);
}

private function mrm_attach_payment_intent_to_promo_redemption($order_id, $payment_intent_id) {
  global $wpdb;

  $order_id = absint($order_id);
  $payment_intent_id = sanitize_text_field((string)$payment_intent_id);

  if ($order_id <= 0 || $payment_intent_id === '') {
    return false;
  }

  $table = $this->table_promo_redemptions();

  $found_table = $wpdb->get_var($wpdb->prepare(
    "SHOW TABLES LIKE %s",
    $table
  ));

  if ($found_table !== $table) {
    $this->install_or_upgrade_db();
  }

  $updated = $wpdb->update(
    $table,
    array(
      'stripe_payment_intent_id' => $payment_intent_id,
      'updated_at' => current_time('mysql'),
    ),
    array(
      'order_id' => $order_id,
    ),
    array(
      '%s',
      '%s',
    ),
    array(
      '%d',
    )
  );

  return ($updated !== false);
}

private function mrm_mark_promo_redemption_paid($order_id, $payment_intent_id) {
  global $wpdb;

  $order_id = (int)$order_id;
  if ($order_id <= 0 || $payment_intent_id === '') return;

  $wpdb->update(
    $this->table_promo_redemptions(),
    array(
      'status' => 'paid',
      'stripe_payment_intent_id' => sanitize_text_field((string)$payment_intent_id),
      'updated_at' => current_time('mysql'),
    ),
    array('order_id' => $order_id),
    array('%s','%s','%s'),
    array('%d')
  );
}

private function mrm_get_redemptions_for_promo_code($code, $limit = 25) {
    global $wpdb;

    $code = $this->mrm_normalize_promo_code($code);
    if ($code === '') return array();

    $table = $this->table_promo_redemptions();

    $found_table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($found_table !== $table) {
      return array();
    }

    $limit = max(1, min(100, absint($limit)));

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, promo_code, email_hash, customer_email, status, created_at, updated_at
       FROM {$table}
       WHERE promo_code = %s
         AND status = 'paid'
       ORDER BY updated_at DESC
       LIMIT %d",
      $code,
      $limit
    ), ARRAY_A);

    if (!is_array($rows)) {
      return array();
    }

    foreach ($rows as &$row) {
      if (empty($row['customer_email']) && !empty($row['email_hash'])) {
        $decoded = $this->decode_email_hash((string)$row['email_hash']);
        if ($decoded) {
          $row['customer_email'] = $decoded;
        }
      }
    }
    unset($row);

    return $rows;
  }

private function mrm_delete_promo_redemption_rows($ids) {
    global $wpdb;

    if (!current_user_can('manage_options')) {
      return 0;
    }

    $ids = array_map('absint', (array)$ids);
    $ids = array_values(array_filter($ids));

    if (empty($ids)) {
      return 0;
    }

    $table = $this->table_promo_redemptions();

    $found_table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($found_table !== $table) {
      return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));

    $sql = "DELETE FROM {$table} WHERE id IN ({$placeholders})";

    $deleted = $wpdb->query($wpdb->prepare($sql, $ids));

    return is_numeric($deleted) ? (int)$deleted : 0;
  }

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
      'background_check_docusign_url' => '',
      'owner_payout_summary_email' => get_option('admin_email', ''),
      'composer_payout_summary_email' => '',
    ));
  }

  private function save_settings($opts) {
    update_option(self::OPT_SETTINGS, $opts);
  }

  private function mrm_aws_debug_log($message, $context = array()) {
  return;
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

  private function mrm_first_non_empty_string_from_candidates($candidates, $keys) {
    if (!is_array($candidates)) {
      return '';
    }

    foreach ($candidates as $candidate) {
      if (!is_array($candidate)) {
        continue;
      }

      foreach ($keys as $key) {
        if (!array_key_exists($key, $candidate)) {
          continue;
        }

        $value = $candidate[$key];

        if (is_array($value) || is_object($value)) {
          continue;
        }

        $value = trim((string)$value);
        if ($value !== '') {
          return $value;
        }
      }
    }

    return '';
  }

  private function mrm_collect_stripe_secret_candidates($secret) {
    $candidates = array();

    $push_candidate = function($value) use (&$candidates) {
      if (is_array($value)) {
        $candidates[] = $value;
        return;
      }

      if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
          $candidates[] = $decoded;
        }
      }
    };

    $push_candidate($secret);

    if (is_array($secret)) {
      foreach ($secret as $key => $value) {
        if (is_array($value) || is_string($value)) {
          $push_candidate($value);
        }
      }

      foreach (array('stripe', 'stripe_keys', 'keys', 'credentials', 'payment', 'payments') as $nested_key) {
        if (array_key_exists($nested_key, $secret)) {
          $push_candidate($secret[$nested_key]);
        }
      }
    }

    return $candidates;
  }

  private function mrm_normalize_stripe_secret_bundle($secret) {
    if (!is_array($secret)) {
      return null;
    }

    $candidates = $this->mrm_collect_stripe_secret_candidates($secret);

    $publishable = $this->mrm_first_non_empty_string_from_candidates($candidates, array(
      'publishable_key',
      'publishableKey',
      'stripe_live_publishable_key',
      'pk',
    ));

    $secret_key = $this->mrm_first_non_empty_string_from_candidates($candidates, array(
      'secret_key',
      'secretKey',
      'stripe_live_secret_key',
      'sk',
    ));

    $webhook = $this->mrm_first_non_empty_string_from_candidates($candidates, array(
      'webhook_secret',
      'webhookSecret',
      'stripe_live_webhook_secret',
      'whsec',
    ));

    foreach ($candidates as $candidate) {
      if (!is_array($candidate)) {
        continue;
      }

      foreach ($candidate as $maybe_value) {
        if (is_array($maybe_value) || is_object($maybe_value)) {
          continue;
        }

        $maybe_value = trim((string)$maybe_value);
        if ($maybe_value === '') {
          continue;
        }

        if ($publishable === '' && preg_match('/^pk_(live|test)_/i', $maybe_value)) {
          $publishable = $maybe_value;
          continue;
        }

        if ($secret_key === '' && preg_match('/^sk_(live|test)_/i', $maybe_value)) {
          $secret_key = $maybe_value;
          continue;
        }

        if ($webhook === '' && preg_match('/^whsec_/i', $maybe_value)) {
          $webhook = $maybe_value;
          continue;
        }
      }
    }

    return array(
      'publishable_key' => $publishable,
      'secret_key'      => $secret_key,
      'webhook_secret'  => $webhook,
      '_raw_keys'       => array_keys($secret),
    );
  }

  private function mrm_get_stripe_secret_bundle() {
    if (!defined('MRM_SECRET_STRIPE_KEYS')) {
      $this->mrm_aws_debug_log('Stripe secret constant MRM_SECRET_STRIPE_KEYS is not defined.');
      return null;
    }

    $secret = $this->mrm_get_secret_json(
      MRM_SECRET_STRIPE_KEYS,
      'mrm_secret_stripe_keys_v3'
    );

    if (!is_array($secret)) {
      $this->mrm_aws_debug_log('Stripe AWS secret bundle could not be loaded.', array(
        'secret_id' => MRM_SECRET_STRIPE_KEYS,
      ));
      return null;
    }

    $normalized = $this->mrm_normalize_stripe_secret_bundle($secret);

    if (!is_array($normalized)) {
      $this->mrm_aws_debug_log('Stripe AWS secret bundle normalization failed.', array(
        'secret_id' => MRM_SECRET_STRIPE_KEYS,
      ));
      return null;
    }

    $candidates = $this->mrm_collect_stripe_secret_candidates($secret);

    $context = array(
      'secret_id' => MRM_SECRET_STRIPE_KEYS,
      'raw_keys_present' => array_keys($secret),
      'candidate_count' => is_array($candidates) ? count($candidates) : 0,
      'has_publishable_key' => !empty($normalized['publishable_key']),
      'has_secret_key' => !empty($normalized['secret_key']),
      'has_webhook_secret' => !empty($normalized['webhook_secret']),
    );

    if (!empty($normalized['publishable_key'])) {
      $context['publishable_key_length'] = strlen($normalized['publishable_key']);
      $context['publishable_key_preview'] = substr($normalized['publishable_key'], 0, 20);
    }

    if (!empty($normalized['secret_key'])) {
      $context['secret_key_length'] = strlen($normalized['secret_key']);
      $context['secret_key_preview'] = substr($normalized['secret_key'], 0, 20);
    }

    if (!empty($normalized['webhook_secret'])) {
      $context['webhook_secret_length'] = strlen($normalized['webhook_secret']);
      $context['webhook_secret_preview'] = substr($normalized['webhook_secret'], 0, 20);
    }

    $this->mrm_aws_debug_log('Stripe AWS secret bundle loaded and normalized.', $context);

    return $normalized;
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
  return;
}

  private function mrm_subscription_debug_log($message, $context = array()) {
  return;
}

  private function mrm_finalization_debug_log($message, $context = array()) {
  return;
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

  private function mrm_quote_debug_enabled() {
    return (defined('MRM_LAUNCH_DEBUG') && MRM_LAUNCH_DEBUG && current_user_can('manage_options'));
  }

private function mrm_quote_debug_log($event, $data = array()) {
  if (!$this->mrm_quote_debug_enabled()) {
    return;
  }

  if (!defined('WP_CONTENT_DIR')) {
    return;
  }

  $file = trailingslashit(WP_CONTENT_DIR) . 'mrm-quote-debug.log';

  $safe_data = $this->mrm_quote_debug_sanitize($data);

  $entry = array(
    'time' => current_time('mysql'),
    'event' => (string)$event,
    'data' => $safe_data,
  );

  $line = wp_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

  if (!is_string($line) || $line === '') {
    $line = json_encode(array(
      'time' => current_time('mysql'),
      'event' => (string)$event,
      'data' => 'json_encode_failed',
    ));
  }

  /**
   * LOCK_EX prevents two simultaneous quote requests from garbling one line.
   */
  @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

private function mrm_quote_debug_sanitize($value) {
  if (is_array($value)) {
    $out = array();

    foreach ($value as $key => $child) {
      $key_string = strtolower((string)$key);

      /**
       * Avoid logging secrets or payment-sensitive values.
       */
      if (
        strpos($key_string, 'secret') !== false ||
        strpos($key_string, 'token') !== false ||
        strpos($key_string, 'client_secret') !== false ||
        strpos($key_string, 'webhook') !== false ||
        strpos($key_string, 'password') !== false ||
        strpos($key_string, 'card') !== false
      ) {
        $out[$key] = '[redacted]';
        continue;
      }

      $out[$key] = $this->mrm_quote_debug_sanitize($child);
    }

    return $out;
  }

  if (is_object($value)) {
    return $this->mrm_quote_debug_sanitize((array)$value);
  }

  if (is_string($value)) {
    if (strlen($value) > 500) {
      return substr($value, 0, 500) . '...[truncated]';
    }

    return $value;
  }

  return $value;
}

private function mrm_quote_debug_product_summary($product) {
  if (!is_array($product)) {
    return null;
  }

  return array(
    'label' => (string)($product['label'] ?? ''),
    'active_raw' => $product['active'] ?? null,
    'active_bool' => !empty($product['active']),
    'amount_cents' => isset($product['amount_cents']) ? (int)$product['amount_cents'] : null,
    'currency' => (string)($product['currency'] ?? ''),
    'product_type' => (string)($product['product_type'] ?? ''),
    'category' => (string)($product['category'] ?? ''),
    'has_stripe_price_id' => !empty($product['stripe_price_id']),
    'keys_present' => array_keys($product),
  );
}

private function mrm_quote_debug_nearby_product_keys($needle, $limit = 25) {
  $needle = $this->sanitize_sku($needle);
  $all = $this->all_products();

  if (!is_array($all) || empty($all)) {
    return array();
  }

  $matches = array();

  foreach ($all as $sku => $product) {
    $clean_sku = $this->sanitize_sku($sku);
    $label = is_array($product) ? (string)($product['label'] ?? '') : '';
    $label_slug = $this->slugify($label);

    if (
      $needle === '' ||
      strpos($clean_sku, $needle) !== false ||
      strpos($needle, $clean_sku) !== false ||
      ($label_slug !== '' && strpos($label_slug, $needle) !== false)
    ) {
      $matches[] = array(
        'sku_key' => $clean_sku,
        'label' => $label,
        'active' => is_array($product) ? !empty($product['active']) : false,
        'amount_cents' => is_array($product) && isset($product['amount_cents']) ? (int)$product['amount_cents'] : null,
        'currency' => is_array($product) ? (string)($product['currency'] ?? '') : '',
        'product_type' => is_array($product) ? (string)($product['product_type'] ?? '') : '',
        'category' => is_array($product) ? (string)($product['category'] ?? '') : '',
      );

      if (count($matches) >= (int)$limit) {
        break;
      }
    }
  }

  return $matches;
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

private function mrm_is_active_product_sku($sku) {
  $sku = $this->sanitize_sku($sku);
  if ($sku === '') {
    return false;
  }

  $product = $this->get_product($sku);
  return is_array($product) && !empty($product['active']);
}

private function mrm_add_unique_sku_candidate(&$candidates, $sku) {
  $sku = $this->sanitize_sku($sku);
  if ($sku !== '' && !in_array($sku, $candidates, true)) {
    $candidates[] = $sku;
  }
}

private function mrm_infer_sheet_music_category_from_text($text) {
  $text = strtolower((string)$text);
  if (strpos($text, 'trombone-euphonium') !== false || strpos($text, 'trombone_euphonium') !== false) return 'trombone-euphonium';
  if (strpos($text, 'trombone') !== false && strpos($text, 'euphonium') !== false) return 'trombone-euphonium';
  if (preg_match('/(^|[-_\s])tuba($|[-_\s])/', $text)) return 'tuba';
  if (strpos($text, 'fundamental') !== false) return 'fundamentals';
  if (strpos($text, 'complete-package') !== false || strpos($text, 'complete_package') !== false || strpos($text, 'complete package') !== false || strpos($text, 'full-piece') !== false || strpos($text, 'full piece') !== false || strpos($text, 'bundle') !== false || strpos($text, 'all-parts') !== false || strpos($text, 'all parts') !== false) return 'complete-package';
  return '';
}

private function mrm_piece_stem_from_offer_slug($raw_sku, $category = '') {
  $stem = $this->sanitize_sku($raw_sku);
  if ($stem === '') return '';
  $stem = preg_replace('/^piece-/', '', $stem);
  $patterns = array('/-trombone-euphonium.*$/','/-trombone.*euphonium.*$/','/-tuba.*$/','/-fundamentals?.*$/','/-complete-package.*$/','/-complete.*$/','/-full-piece.*$/','/-all-parts.*$/','/-bundle.*$/',);
  foreach ($patterns as $pattern) {
    $cleaned = preg_replace($pattern, '', $stem);
    if (is_string($cleaned) && $cleaned !== $stem && $cleaned !== '') { $stem = $cleaned; break; }
  }
  return trim($stem, '-_');
}

private function mrm_resolve_active_product_sku($incoming_sku, $context = array()) {
  $original_incoming_sku = $incoming_sku;
  $incoming_sku = $this->sanitize_sku($incoming_sku);

  $this->mrm_quote_debug_log('resolver_start', array(
    'original_incoming_sku' => $original_incoming_sku,
    'sanitized_incoming_sku' => $incoming_sku,
    'context' => $context,
  ));

  if ($incoming_sku === '') {
    $this->mrm_quote_debug_log('resolver_empty_incoming_sku', array(
      'original_incoming_sku' => $original_incoming_sku,
    ));
    return '';
  }

  if ($this->mrm_is_active_product_sku($incoming_sku)) {
    $this->mrm_quote_debug_log('resolver_exact_active_match', array(
      'resolved_sku' => $incoming_sku,
      'product' => $this->mrm_quote_debug_product_summary($this->get_product($incoming_sku)),
    ));
    return $incoming_sku;
  }

  $context = is_array($context) ? $context : array();

  $candidates = array();
  $this->mrm_add_unique_sku_candidate($candidates, $incoming_sku);

  if (strpos($incoming_sku, 'piece-') !== 0) {
    $this->mrm_add_unique_sku_candidate($candidates, 'piece-' . $incoming_sku);
  }

  $piece_slug_from_context = !empty($context['piece_slug']) ? $this->slugify((string)$context['piece_slug']) : '';

  $context_text = $incoming_sku . ' '
    . (string)($context['display_title'] ?? '') . ' '
    . (string)($context['subtitle'] ?? '') . ' '
    . (string)($context['type'] ?? '');

  $category = $this->mrm_infer_sheet_music_category_from_text($context_text);

  if ($category !== '') {
    $stem = $this->mrm_piece_stem_from_offer_slug($incoming_sku, $category);

    if ($stem !== '') {
      $this->mrm_add_unique_sku_candidate($candidates, 'piece-' . $stem . '-' . $category);
    }

    if ($piece_slug_from_context !== '') {
      $this->mrm_add_unique_sku_candidate($candidates, 'piece-' . $piece_slug_from_context . '-' . $category);
    }
  }

  $this->mrm_quote_debug_log('resolver_candidates_built', array(
    'incoming_sku' => $incoming_sku,
    'piece_slug_from_context' => $piece_slug_from_context,
    'context_text' => $context_text,
    'inferred_category' => $category,
    'candidates' => $candidates,
  ));

  foreach ($candidates as $candidate) {
    $candidate_product = $this->get_product($candidate);

    $this->mrm_quote_debug_log('resolver_testing_candidate', array(
      'candidate' => $candidate,
      'exists' => is_array($candidate_product),
      'product' => $this->mrm_quote_debug_product_summary($candidate_product),
    ));

    if ($this->mrm_is_active_product_sku($candidate)) {
      $this->mrm_quote_debug_log('resolver_candidate_active_match', array(
        'resolved_sku' => $candidate,
      ));
      return $candidate;
    }

    for ($i = 2; $i <= 999; $i++) {
      $suffixed = $candidate . '-' . $i;
      $suffixed_product = $this->get_product($suffixed);

      if ($i <= 5 && is_array($suffixed_product)) {
        $this->mrm_quote_debug_log('resolver_testing_suffixed_candidate', array(
          'candidate' => $suffixed,
          'exists' => true,
          'product' => $this->mrm_quote_debug_product_summary($suffixed_product),
        ));
      }

      if ($this->mrm_is_active_product_sku($suffixed)) {
        $this->mrm_quote_debug_log('resolver_suffixed_active_match', array(
          'resolved_sku' => $suffixed,
        ));
        return $suffixed;
      }
    }
  }

  $all = $this->all_products();

  $this->mrm_quote_debug_log('resolver_scanning_all_products', array(
    'product_count' => is_array($all) ? count($all) : 0,
    'incoming_sku' => $incoming_sku,
    'piece_slug_from_context' => $piece_slug_from_context,
    'inferred_category' => $category,
  ));

  foreach ($all as $sku => $product) {
    if (!is_array($product) || empty($product['active'])) {
      continue;
    }

    if ((string)($product['product_type'] ?? '') !== 'sheet_music') {
      continue;
    }

    $product_category = (string)($product['category'] ?? '');

    if ($category !== '' && $product_category !== $category) {
      continue;
    }

    $sku_clean = $this->sanitize_sku($sku);
    $label_slug = $this->slugify((string)($product['label'] ?? ''));

    if ($category !== '' && $piece_slug_from_context !== '') {
      if (
        strpos($sku_clean, 'piece-' . $piece_slug_from_context . '-' . $category) === 0 ||
        (
          strpos($label_slug, $piece_slug_from_context) !== false &&
          strpos($sku_clean, '-' . $category) !== false
        )
      ) {
        $this->mrm_quote_debug_log('resolver_scan_piece_slug_match', array(
          'resolved_sku' => $sku_clean,
          'product' => $this->mrm_quote_debug_product_summary($product),
        ));
        return $sku_clean;
      }
    }

    $stem = $this->mrm_piece_stem_from_offer_slug($incoming_sku, $category);

    if ($category !== '' && $stem !== '') {
      if (
        strpos($sku_clean, 'piece-' . $stem . '-' . $category) === 0 ||
        (
          strpos($label_slug, $stem) !== false &&
          strpos($sku_clean, '-' . $category) !== false
        )
      ) {
        $this->mrm_quote_debug_log('resolver_scan_stem_match', array(
          'resolved_sku' => $sku_clean,
          'stem' => $stem,
          'product' => $this->mrm_quote_debug_product_summary($product),
        ));
        return $sku_clean;
      }
    }
  }

  $this->mrm_quote_debug_log('resolver_no_match', array(
    'incoming_sku' => $incoming_sku,
    'piece_slug_from_context' => $piece_slug_from_context,
    'inferred_category' => $category,
    'nearby_products' => $this->mrm_quote_debug_nearby_product_keys($incoming_sku),
  ));

  return '';
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
      'city' => sanitize_text_field((string)($address['city'] ?? '')),
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
    $context = is_array($context) ? $context : array();

    $allow_incomplete_address = !empty($context['allow_incomplete_address']);

    $missing_address_fields = array();

    if ($product_type === 'sheet_music') {

      if (trim((string)($address['line1'] ?? '')) === '') {
        $missing_address_fields[] = 'street address';
      }

      if (trim((string)($address['city'] ?? '')) === '') {
        $missing_address_fields[] = 'city';
      }

      if (trim((string)($address['state'] ?? '')) === '') {
        $missing_address_fields[] = 'state';
      }

      if (trim((string)($address['postal_code'] ?? '')) === '') {
        $missing_address_fields[] = 'ZIP code';
      }

      if (trim((string)($address['country'] ?? '')) === '') {
        $missing_address_fields[] = 'country';
      }

      if (!empty($missing_address_fields) && !$allow_incomplete_address) {
        return array(
          'ok' => false,
          'code' => 'billing_address_required',
          'message' => 'Please enter your full billing address before purchasing sheet music: ' . implode(', ', $missing_address_fields) . '.',
          'address' => $address,
          'missing_address_fields' => $missing_address_fields,
          'jurisdiction' => array(
            'country' => (string)($address['country'] ?? 'US'),
            'state' => (string)($address['state'] ?? ''),
            'exists' => false,
            'registered' => null,
            'collect_enabled' => null,
            'stripe_controls_rollout' => true,
          ),
          'profiles' => array(),
          'policy_reason' => 'billing_address_required',
          'policy_message' => 'Please enter your full billing address before purchasing sheet music: ' . implode(', ', $missing_address_fields) . '.',
          'should_collect_tax' => false,
          'allow_subscription_automatic_tax' => false,
        );
      }
    }
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

    if ($allow_incomplete_address && $product_type === 'sheet_music' && !empty($missing_address_fields)) {
      $policy_reason = 'billing_address_preview_incomplete';
      $policy_message = 'Sales tax is calculated after billing state and ZIP are entered.';
      $should_collect_tax = false;
    }

    return array(
      'ok' => true,
      'address' => $address,
      'missing_address_fields' => $missing_address_fields,
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
        'amount_cents' => 6200,
        'currency' => 'usd',
        'product_type' => 'lesson',
        'category' => '60_online',
        'active' => 1,
      ),
      'lesson_60_inperson' => array(
        'sku' => 'lesson_60_inperson',
        'label' => '60 Minute In-Person Lesson',
        'amount_cents' => 6700,
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

  private function mrm_parse_piece_sku_labels($sku) {
    $sku = $this->sanitize_sku((string)$sku);

    $out = array(
      'is_piece' => false,
      'piece_slug' => '',
      'piece_title' => '',
      'category_slug' => '',
      'category_label' => '',
      'display_label' => '',
    );

    if (!preg_match('/^piece-(.+)-(fundamentals|trombone-euphonium|tuba|complete-package)$/', $sku, $m)) {
      return $out;
    }

    $piece_slug = (string)$m[1];
    $category_slug = (string)$m[2];

    $category_map = array(
      'fundamentals' => 'Fundamentals',
      'trombone-euphonium' => 'Trombone/Euphonium',
      'tuba' => 'Tuba',
      'complete-package' => 'Complete Package',
    );

    $piece_title = ucwords(str_replace('-', ' ', $piece_slug));
    $category_label = $category_map[$category_slug] ?? ucwords(str_replace('-', ' ', $category_slug));

    $out['is_piece'] = true;
    $out['piece_slug'] = $piece_slug;
    $out['piece_title'] = $piece_title;
    $out['category_slug'] = $category_slug;
    $out['category_label'] = $category_label;
    $out['display_label'] = $piece_title . ' — ' . $category_label;

    return $out;
  }


  private function mrm_get_piece_page_url_from_sku($sku) {
    $labels = $this->mrm_parse_piece_sku_labels($sku);
    $piece_slug = sanitize_title((string)($labels['piece_slug'] ?? ''));

    if ($piece_slug === '') {
      return '';
    }

    return home_url('/' . $piece_slug . '/');
  }

  private function mrm_email_already_has_piece_or_package_access($email, $sku) {
    $email = sanitize_email((string)$email);
    $sku   = $this->sanitize_sku((string)$sku);

    if (!$email || !is_email($email) || !$sku) {
      return false;
    }

    $email_hash = $this->email_hash($email);

    if ($this->has_sheet_music_access($email_hash, $sku)) {
      return true;
    }

    if (preg_match('/^piece-(.+)-(fundamentals|trombone-euphonium|tuba|complete-package)$/', $sku, $m)) {
      $piece_slug = (string)$m[1];
      $package_sku = 'piece-' . $piece_slug . '-complete-package';

      if ($package_sku !== $sku && $this->has_sheet_music_access($email_hash, $package_sku)) {
        return true;
      }
    }

    return false;
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
    $city    = trim((string)($address['city'] ?? ''));

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

  private function stripe_update_subscription($subscription_id, $params = array()) {
    $subscription_id = trim((string)$subscription_id);
    if ($subscription_id === '') {
      return new WP_Error('mrm_missing_subscription_id', 'Missing subscription ID.');
    }

    return $this->stripe_api_request(
      'POST',
      '/v1/subscriptions/' . rawurlencode($subscription_id),
      is_array($params) ? $params : array()
    );
  }

  private function mrm_resume_sheet_music_subscription($email, $payment_method_id = '', $order_id = 0) {
    $email = sanitize_email((string)$email);
    if (!$email || !is_email($email)) {
      return new WP_Error('mrm_invalid_email', 'Missing valid email for subscription resume.');
    }

    $candidate = $this->mrm_get_resumable_sheet_music_subscription_by_email($email);
    if (empty($candidate['subscription_id'])) {
      return new WP_Error('mrm_no_resumable_subscription', 'No resumable subscription found.');
    }

    $params = array(
      'cancel_at_period_end' => 'false',
    );

    if ($payment_method_id !== '') {
      $params['default_payment_method'] = (string)$payment_method_id;
    }

    $updated = $this->stripe_update_subscription($candidate['subscription_id'], $params);
    if (is_wp_error($updated)) {
      return $updated;
    }

    $this->mrm_sync_local_sheet_music_subscription_from_stripe($updated, $email);

    return $updated;
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
    $student_name = trim((string)($lesson_row['student_name'] ?? $lesson_row['client_name'] ?? ''));
    $student_phone = trim((string)($lesson_row['student_phone'] ?? $lesson_row['client_phone'] ?? ''));
    $lesson_start = (string)($lesson_row['start_time'] ?? '');
    $lesson_length = (int)($lesson_row['lesson_length'] ?? 0);
    $mode_label = $this->mrm_format_lesson_mode_label((int)($lesson_row['is_online'] ?? 0));
    $instructor = $this->mrm_get_instructor_contact_from_id((int)($lesson_row['instructor_id'] ?? 0));
    $instructor_name = (string)($instructor['name'] ?? '');
    $instructor_email = (string)($instructor['email'] ?? '');
    $instructor_phone = (string)($instructor['phone'] ?? '');
    $admin_email = $this->mrm_get_wp_admin_notification_email();

    $brand = trim((string)($snapshot['brand'] ?? ''));
    $last4 = trim((string)($snapshot['last4'] ?? ''));
    $exp_month = (int)($snapshot['exp_month'] ?? 0);
    $exp_year = (int)($snapshot['exp_year'] ?? 0);
    $card_line = ($brand !== '' || $last4 !== '') ? trim($brand . ' ending in ' . $last4) : 'Saved payment method on file';
    if ($exp_month > 0 && $exp_year > 0) $card_line .= sprintf(' (expires %02d/%04d)', $exp_month, $exp_year);

    $lesson_line = trim(($lesson_length > 0 ? $lesson_length . '-minute ' : '') . $mode_label . ' lesson');
    $when_line = ($lesson_start !== '') ? $lesson_start : 'the scheduled lesson time';
    $autopay_profile_id = (int)($lesson_row['autopay_profile_id'] ?? ($profile['id'] ?? 0));
    $update_payment_url = $this->mrm_autopay_update_payment_url($lesson_id, $autopay_profile_id);

    $student_subject = 'Your Payment Method Requires Attention';
    $student_intro = '<p>Your payment information is in need of an update. Please use this link to update your payment information for the auto-pay lessons, renewing at <strong>' . esc_html($when_line) . '</strong>.</p>';
    $student_details = '<div><strong>Lesson:</strong> ' . esc_html($lesson_line) . '</div>' . '<div><strong>Renewing at:</strong> ' . esc_html($when_line) . '</div>' . '<div><strong>Saved payment method:</strong> ' . esc_html($card_line) . '</div>' . '<div><strong>Reason:</strong> ' . esc_html($reason_text) . '</div>';
    $student_buttons = array(array('url' => $update_payment_url, 'label' => 'Update Payment Information', 'variant' => 'primary'));
    $student_after = $this->mrm_email_contact_support_html('If you have any questions please fill out our contact form.');

    $instructor_subject = 'Payment method needs attention for ' . $student_name;
    $instructor_heading = 'Please withhold this lesson ' . $when_line . ' until payment method has been updated';
    $instructor_intro = '<p>Your upcoming AutoPay lesson with <strong>' . esc_html($student_name) . '</strong> requires an updated payment method before the lesson can be processed. Please withhold lesson services until this has been confirmed.</p>';
    $instructor_details = '<div><strong>Student:</strong> ' . esc_html($student_name) . '</div>' . '<div><strong>Lesson:</strong> ' . esc_html($lesson_line) . '</div>' . '<div><strong>Scheduled time:</strong> ' . esc_html($when_line) . '</div>' . '<p style="margin-top:12px;">This client has also received a request to update their payment information. Feel free to connect and check in with them.</p>' . '<div><strong>Client email:</strong> ' . esc_html($student_email) . '</div>' . '<div><strong>Client phone number:</strong> ' . esc_html($student_phone) . '</div>';

    $admin_subject = 'Payment information update required for ' . $student_name;
    $admin_intro = '<p>The system detected a need for payment information to be updated for <strong>' . esc_html($student_name) . '</strong> before their upcoming lesson time with <strong>' . esc_html($instructor_name) . '</strong> and <strong>' . esc_html($when_line) . '</strong>.</p>';
    $admin_details = '<div><strong>Student:</strong> ' . esc_html($student_name) . '</div>' . '<div><strong>Student email:</strong> ' . esc_html($student_email) . '</div>' . '<div><strong>Student phone:</strong> ' . esc_html($student_phone) . '</div>' . '<div><strong>Instructor:</strong> ' . esc_html($instructor_name) . '</div>' . '<div><strong>Instructor email:</strong> ' . esc_html($instructor_email) . '</div>' . '<div><strong>Instructor phone:</strong> ' . esc_html($instructor_phone) . '</div>' . '<div><strong>Lesson:</strong> ' . esc_html($lesson_line) . '</div>' . '<div><strong>Lesson day and time:</strong> ' . esc_html($when_line) . '</div>' . '<div><strong>Payment update portal:</strong> <a href="' . esc_url($update_payment_url) . '">' . esc_html($update_payment_url) . '</a></div>' . '<div><strong>Issue:</strong> ' . esc_html($reason_text) . '</div>';

    $headers = array('Content-Type: text/html; charset=UTF-8','From: LowBrass Lessons <no-reply@lowbrass-lessons.com>');
    $student_sent = ($student_email && is_email($student_email)) ? wp_mail($student_email, $student_subject, $this->mrm_email_wrap_html('Update your payment information', $student_intro, $student_details, $student_buttons, '', $student_after), $headers) : false;
    $instructor_sent = ($instructor_email && is_email($instructor_email)) ? wp_mail($instructor_email, $instructor_subject, $this->mrm_email_wrap_html($instructor_heading, $instructor_intro, $instructor_details, '', ''), $headers) : false;
    $admin_sent = ($admin_email && is_email($admin_email)) ? wp_mail($admin_email, $admin_subject, $this->mrm_email_wrap_html('Payment method update needed', $admin_intro, $admin_details, '', ''), $headers) : false;
    return ($student_sent || $instructor_sent || $admin_sent);
  }

  private function mrm_signed_lesson_action_url($action, $lesson_id, $profile_id = 0) {
    $lesson_id = absint($lesson_id); $profile_id = absint($profile_id); $expires = time() + (7 * DAY_IN_SECONDS);
    $payload = $action . '|' . $lesson_id . '|' . $profile_id . '|' . $expires;
    $sig = hash_hmac('sha256', $payload, wp_salt('auth'));
    return add_query_arg(array('action'=>$action,'lesson_id'=>$lesson_id,'profile_id'=>$profile_id,'expires'=>$expires,'sig'=>$sig), admin_url('admin-post.php'));
  }
  private function mrm_verify_signed_lesson_action($action, $lesson_id, $profile_id, $expires, $sig) {
    $lesson_id=absint($lesson_id); $profile_id=absint($profile_id); $expires=absint($expires); $sig=(string)$sig;
    if ($lesson_id <= 0 || $expires < time() || $sig === '') return false;
    return hash_equals(hash_hmac('sha256', $action . '|' . $lesson_id . '|' . $profile_id . '|' . $expires, wp_salt('auth')), $sig);
  }
  private function mrm_autopay_update_payment_url($lesson_id, $profile_id) { return $this->mrm_signed_lesson_action_url('mrm_autopay_update_payment', $lesson_id, $profile_id); }
  public function handle_autopay_update_payment() {
    $lesson_id=absint($_GET['lesson_id'] ?? 0); $profile_id=absint($_GET['profile_id'] ?? 0); $expires=absint($_GET['expires'] ?? 0); $sig=sanitize_text_field((string)($_GET['sig'] ?? ''));
    if (!$this->mrm_verify_signed_lesson_action('mrm_autopay_update_payment',$lesson_id,$profile_id,$expires,$sig)) wp_die('This payment update link is invalid or expired.', 'Payment Update', array('response'=>403));
    $profile=$this->mrm_get_autopay_profile($profile_id); if (!$profile || empty($profile['customer_id'])) wp_die('Unable to find the AutoPay customer profile.', 'Payment Update', array('response'=>404));
    $portal=$this->stripe_create_billing_portal_session((string)$profile['customer_id'], home_url('/contact/'));
    if (is_wp_error($portal) || empty($portal['url'])) wp_die('Unable to open the Stripe payment update page right now.', 'Payment Update', array('response'=>500));
    wp_redirect((string)$portal['url']); exit;
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
    $city = trim((string)($address['city'] ?? ''));
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
    if ($city !== '') {
      $params['address[city]'] = $city;
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
    if ($city !== '') {
      $params['shipping[address][city]'] = $city;
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
      $promo_code_from_meta = '';
      if (!empty($metadata['mrm_promo_code'])) {
        $promo_code_from_meta = $this->mrm_normalize_promo_code($metadata['mrm_promo_code']);
      }

      if ($promo_code_from_meta !== '') {
        $this->mrm_mark_promo_redemption_paid((int)$order['id'], $pi_id);
      }

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
      $this->stripe_debug_log('customer.subscription.created webhook synced subscription only; enrollment email is handled by activation helper', array(
        'subscription_id' => $subscription_id,
        'email' => (string)($local['email_plain'] ?? ''),
        'status' => (string)($local['stripe_status'] ?? ''),
      ));

      $this->mrm_subscription_debug_log('customer.subscription.created webhook sync complete; enrollment email intentionally not sent here', array(
        'subscription_id' => $subscription_id,
        'email' => (string)($local['email_plain'] ?? ''),
        'status' => (string)($local['stripe_status'] ?? ''),
      ));
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

    $metadata = is_array($metadata) ? $metadata : array();
    $customer_email = sanitize_email((string)($metadata['mrm_customer_email'] ?? ''));

    $wpdb->insert($this->table_orders(), array(
      'email_hash' => $email_hash,
      'customer_email' => $customer_email ?: null,
      'sku' => $sku,
      'product_type' => $product_type,
      'amount_cents' => (int)$amount_cents,
      'currency' => $currency ?: 'usd',
      'environment_mode' => $environment_mode,
      'status' => 'created',
      'metadata_json' => wp_json_encode($metadata),
      'created_at' => $now,
      'updated_at' => $now,
    ), array('%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s'));

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


    if (!$should_detach) return false;

    $pm_id = (string)($profile['payment_method_id'] ?? '');
    if ($pm_id !== '') {
      $det = $this->stripe_detach_payment_method($pm_id);
      if (is_wp_error($det)) {
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
    $url = home_url('/contact/');
    $url = (string) apply_filters('mrm_contact_url', $url);
    return $url;
  }

  private function mrm_email_button_html($url, $label, $variant = 'primary') {
    $url = esc_url((string)$url);
    $label = trim((string)$label);
    if ($url === '' || $label === '') return '';
    $is_cancel = ($variant === 'cancel' || stripos($label, 'cancel') !== false || stripos($label, 'unable') !== false || $variant === 'secondary');
    $bg = $is_cancel ? '#ffffff' : '#111111';
    $color = $is_cancel ? '#111111' : '#ffffff';
    $border = '1px solid #111111';
    return '<a href="' . $url . '" style="display:inline-block;background:' . esc_attr($bg) . ';color:' . esc_attr($color) . ';border:' . esc_attr($border) . ';text-decoration:none;font-weight:700;padding:13px 20px;border-radius:10px;margin:6px;text-align:center;min-width:150px;">' . esc_html($label) . '</a>';
  }

  private function mrm_email_button_row_html($buttons) {
    if (!is_array($buttons) || empty($buttons)) {
      return '';
    }

    $valid_buttons = array();

    foreach ($buttons as $button) {
      if (!is_array($button)) continue;

      $url = (string)($button['url'] ?? '');
      $label = (string)($button['label'] ?? '');
      $variant = (string)($button['variant'] ?? 'primary');

      if (trim($url) === '' || trim($label) === '') continue;

      $valid_buttons[] = array(
        'url' => $url,
        'label' => $label,
        'variant' => $variant,
      );
    }

    if (empty($valid_buttons)) {
      return '';
    }

    $html = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:24px auto 0 auto;text-align:center;"><tr>';

    foreach ($valid_buttons as $button) {
      $html .= '<td align="center" valign="middle" style="padding:6px;">';
      $html .= $this->mrm_email_button_html($button['url'], $button['label'], $button['variant']);
      $html .= '</td>';
    }

    $html .= '</tr></table>';

    return $html;
  }

  private function mrm_email_contact_support_html($prefix_sentence = '') {
    $html = '';
    if (trim((string)$prefix_sentence) !== '') $html .= '<p style="margin-top:18px;text-align:center;">' . esc_html($prefix_sentence) . '</p>';
    $html .= $this->mrm_email_button_row_html(array(array('url' => $this->mrm_get_contact_url(), 'label' => 'Contact Support', 'variant' => 'primary')));
    return $html;
  }


  private function mrm_email_cancel_subscription_text_link_html($url = '#') {
    $url = trim((string)$url);
    if ($url === '') {
      $url = '#';
    }

    return '<div style="margin-top:14px;text-align:right;">
    <a href="' . esc_url($url) . '" style="color:#111;text-decoration:underline;font-size:14px;">Cancel Subscription</a>
  </div>';
  }


  private function mrm_get_receipt_lesson_online_context($lesson_row) {
    $lesson_row = is_array($lesson_row) ? $lesson_row : array();

    $is_online = !empty($lesson_row['is_online']);
    if (!$is_online) {
      return array(
        'join_link' => '',
        'format_note' => '',
      );
    }

    $join_link = '';

    if (!empty($lesson_row['reminder_token'])) {
      $join_link = add_query_arg(
        array('token' => (string)$lesson_row['reminder_token']),
        home_url('/join-online/')
      );
    }

    if ($join_link === '' && !empty($lesson_row['google_meet_url'])) {
      $join_link = (string)$lesson_row['google_meet_url'];
    }

    return array(
      'join_link' => $join_link,
      'format_note' => 'This is an online lesson. Please use the lesson link above at the scheduled time.',
    );
  }

  private function mrm_get_sheet_music_access_section_html() {
    $sheet_music_url = home_url('/sheet-music/');

    $html  = '<div style="margin-top:12px;"><strong>How To Access Sheet Music</strong></div>';
    $html .= '<div style="margin-top:8px;">To access your sheet music, go to this link: <a href="' . esc_url($sheet_music_url) . '">' . esc_html($sheet_music_url) . '</a> and follow these steps:</div>';
    $html .= '<ol style="margin:8px 0 0 18px;padding:0;">';
    $html .= '<li>Open the sheet music page.</li>';
    $html .= '<li>Select the piece you would like to access.</li>';
    $html .= '<li>Use your purchase email address to request your one-time access code if prompted.</li>';
    $html .= '<li>Open the available materials for the pieces included in your subscription.</li>';
    $html .= '</ol>';
    $html .= '<div style="margin-top:12px;">Subscription access includes downloadable PDF files. Audio files are streaming only. To download audio files, customers must purchase the piece separately.</div>';

    return $html;
  }

  private function mrm_receipt_format_money($amount_cents) {
    return '$' . number_format(max(0, (int)$amount_cents) / 100, 2);
  }

  private function mrm_purchase_receipt_payment_breakdown_html($meta, $amount_cents, $product_type = '') {
    $meta = is_array($meta) ? $meta : array();

    $final_cents = max(0, (int)$amount_cents);
    $addon_cents = max(0, (int)($meta['mrm_addon_amount_cents'] ?? 0));
    $tax_cents = max(0, (int)($meta['mrm_tax_cents'] ?? ($meta['mrm_addon_tax_cents'] ?? 0)));
    $discounted_base_cents = max(0, (int)($meta['mrm_base_amount_cents'] ?? 0));
    $original_base_cents = max(0, (int)($meta['mrm_original_base_amount_cents'] ?? 0));
    $promo_discount_cents = max(0, (int)($meta['mrm_promo_discount_cents'] ?? 0));
    $promo_code = $this->mrm_normalize_promo_code($meta['mrm_promo_code'] ?? '');

    if ($original_base_cents <= 0) {
      if ($discounted_base_cents > 0) {
        $original_base_cents = $discounted_base_cents + $promo_discount_cents;
      } else {
        $paid_base_cents = max(0, $final_cents - $addon_cents - $tax_cents);
        $original_base_cents = $paid_base_cents + $promo_discount_cents;
      }
    }

    $html = '<div style="margin-top:10px;"><strong>Payment breakdown</strong></div>';

    if ($original_base_cents > 0) {
      $html .= '<div><strong>Base:</strong> ' . esc_html($this->mrm_receipt_format_money($original_base_cents)) . '</div>';
    }

    if ($promo_discount_cents > 0) {
      $promo_label = $promo_code !== '' ? 'Promo code (' . $promo_code . ')' : 'Promo discount';
      $html .= '<div><strong>' . esc_html($promo_label) . ':</strong> -' . esc_html($this->mrm_receipt_format_money($promo_discount_cents)) . '</div>';
    } elseif ($promo_code !== '') {
      $html .= '<div><strong>' . esc_html('Promo code (' . $promo_code . ')') . ':</strong> ' . esc_html($this->mrm_receipt_format_money(0)) . '</div>';
    }

    if ($addon_cents > 0) {
      $html .= '<div><strong>Sheet music add-on:</strong> ' . esc_html($this->mrm_receipt_format_money($addon_cents)) . '</div>';
    }

    $html .= '<div><strong>Tax:</strong> ' . esc_html($this->mrm_receipt_format_money($tax_cents)) . '</div>';
    $html .= '<div><strong>Total paid:</strong> ' . esc_html($this->mrm_receipt_format_money($final_cents)) . '</div>';

    return $html;
  }

  private function mrm_email_wrap_html($title, $intro_html, $details_html, $cta_url = '', $cta_label = '', $after_cta_html = '') {
    $site = esc_html(get_bloginfo('name'));
    $logo_url = $this->mrm_get_site_logo_url();

    $logo_html = '';
    if ($logo_url) {
      $logo_html = '<div style="text-align:center;margin:0 0 22px 0;">
      <img src="' . esc_url($logo_url) . '" alt="' . $site . '" style="max-width:220px;height:auto;border:0;display:inline-block;">
    </div>';
    }

    $cta_html = '';
    if (is_array($cta_url)) {
      $cta_html = $this->mrm_email_button_row_html($cta_url);
    } elseif ($cta_url && $cta_label) {
      $cta_html = $this->mrm_email_button_row_html(array(array('url' => $cta_url, 'label' => $cta_label, 'variant' => (stripos((string)$cta_label, 'cancel') !== false ? 'cancel' : 'primary'))));
    }

    $after_cta_html = (string)$after_cta_html;

    return '<!doctype html><html><body style="margin:0;padding:0;background:#f6f6f6;">
    <div style="max-width:640px;margin:0 auto;padding:24px;">
      <div style="background:#ffffff;border:1px solid #e8e8e8;border-radius:16px;padding:28px;box-shadow:0 2px 10px rgba(0,0,0,0.05);font-family:&quot;Source Sans 3&quot;,Arial,Helvetica,sans-serif;color:#111;">
        ' . $logo_html . '
        <h1 style="margin:0 0 12px 0;font-size:22px;line-height:1.3;text-align:center;color:#111;">' . esc_html($title) . '</h1>
        <div style="font-size:15px;line-height:1.7;color:#222;text-align:left;">' . $intro_html . '</div>
        <div style="margin-top:16px;padding:16px;border:1px solid #ededed;border-radius:12px;background:#fafafa;font-size:14px;line-height:1.7;color:#222;">
          ' . $details_html . '
        </div>
        ' . $cta_html . '
        ' . $after_cta_html . '
        <div style="margin-top:22px;font-size:12px;color:#777;text-align:center;">' . $site . '</div>
      </div>
    </div>
  </body></html>';
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

  private function mrm_get_resumable_sheet_music_subscription_by_email($email) {
    $email = sanitize_email((string)$email);
    if (!$email || !is_email($email)) {
      return array();
    }

    $status = $this->mrm_get_sheet_music_subscription_access_status_by_email($email);
    if (empty($status['has_access'])) {
      return array();
    }

    if (empty($status['cancel_at_period_end'])) {
      return array();
    }

    if (empty($status['subscription_id'])) {
      return array();
    }

    $subscription_id = (string)$status['subscription_id'];
    $subscription = $this->stripe_retrieve_subscription($subscription_id);

    if (is_wp_error($subscription) || !is_array($subscription) || empty($subscription['id'])) {
      return array();
    }

    $live_status = strtolower((string)($subscription['status'] ?? ''));
    $cancel_at_period_end = !empty($subscription['cancel_at_period_end']);
    $current_period_end = !empty($subscription['current_period_end']) ? (int)$subscription['current_period_end'] : 0;

    if (
      in_array($live_status, array('active', 'trialing'), true) &&
      $cancel_at_period_end &&
      $current_period_end > time()
    ) {
      return array(
        'subscription_id' => (string)$subscription['id'],
        'customer_id' => (string)($subscription['customer'] ?? ''),
        'status' => $live_status,
        'current_period_end' => $current_period_end,
        'subscription' => $subscription,
      );
    }

    return array();
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

      if (!$this->mrm_claim_subscription_activation($order_id)) {
        $this->mrm_subscription_debug_log('activation helper exited: activation already claimed by another path', array(
          'context' => $context,
          'order_id' => $order_id,
        ));
        return false;
      }

      $this->mrm_subscription_debug_log('activation helper entered', array(
        'context' => $context,
        'order_id' => $order_id,
        'pi_provided' => (!empty($pi) ? 'yes' : 'no'),
      ));

      $order = $this->get_order($order_id);
      if (!is_array($order) || empty($order)) {
        $this->mrm_release_subscription_activation($order_id);
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
        $this->mrm_release_subscription_activation($order_id);
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
        $this->mrm_release_subscription_activation($order_id);
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
          $this->mrm_release_subscription_activation($order_id);
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
        $this->mrm_release_subscription_activation($order_id);
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
        $this->mrm_release_subscription_activation($order_id);
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
        $this->mrm_release_subscription_activation($order_id);
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
        $this->mrm_release_subscription_activation($order_id);
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
        $this->mrm_release_subscription_activation($order_id);
        return false;
      }
      $this->mrm_subscription_debug_log('activation helper payment-method readiness passed', array(
        'context' => $context,
        'order_id' => $order_id,
        'customer_id' => $customer_id,
        'payment_method_id' => $payment_method_id,
      ));

      $subscription_mode = strtolower((string)($meta['mrm_sheet_music_subscription_mode'] ?? 'new'));

      if ($subscription_mode === 'resume') {
        $resumed = $this->mrm_resume_sheet_music_subscription(
          $email,
          $payment_method_id,
          $order_id
        );

        if (is_wp_error($resumed)) {
          $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'resume_failed');
          $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', $resumed->get_error_message());
          $this->mrm_release_subscription_activation($order_id);
          return false;
        }

        $subscription_id = (string)($resumed['id'] ?? '');
        $subscription_status = (string)($resumed['status'] ?? '');

        if ($subscription_id !== '') {
          $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_id', $subscription_id);
        }

        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', ($subscription_status !== '' ? $subscription_status : 'active'));
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_created_at', current_time('mysql'));
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', '');

        return true;
      }

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
        $this->mrm_release_subscription_activation($order_id);
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

      if (
        is_array($local) &&
        !empty($local) &&
        $email_sent_at === '' &&
        in_array((string)($local['stripe_status'] ?? ''), array('trialing', 'active'), true) &&
        $this->mrm_claim_subscription_enrollment_send((int)$order_id)
      ) {
        $billing_anchor_ts = 0;
        if (!empty($subscription['current_period_end'])) {
          $billing_anchor_ts = (int)$subscription['current_period_end'];
        }

        $sent = $this->mrm_send_sheet_music_subscription_enrollment_email($local, $billing_anchor_ts);

        $this->stripe_debug_log('activation helper enrollment email result', array(
          'context' => $context,
          'order_id' => $order_id,
          'subscription_id' => $subscription_id,
          'email' => (string)($local['email_plain'] ?? ''),
          'sent' => ($sent ? 'yes' : 'no'),
        ));

        $this->mrm_subscription_debug_log('activation helper enrollment email result', array(
          'context' => $context,
          'order_id' => $order_id,
          'subscription_id' => $subscription_id,
          'email' => (string)($local['email_plain'] ?? ''),
          'sent' => ($sent ? 'yes' : 'no'),
        ));

        if ($sent) {
          $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_enrollment_email_sent_at', current_time('mysql'));
        } else {
          $this->mrm_release_subscription_enrollment_send($order_id);
        }
      } else {
        $this->mrm_subscription_debug_log('activation helper skipped enrollment email', array(
          'context' => $context,
          'order_id' => $order_id,
          'subscription_id' => $subscription_id,
          'local_row_found' => (!empty($local) ? 'yes' : 'no'),
          'email_sent_at' => $email_sent_at,
          'local_status' => (string)($local['stripe_status'] ?? ''),
        ));
      }

      return true;
    } catch (Throwable $e) {
      $order_id = (int)$order_id;
      if ($order_id > 0) {
        $this->mrm_release_subscription_activation($order_id);
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_status', 'runtime_exception');
        $this->mrm_set_order_meta_flag($order_id, 'mrm_sheet_music_subscription_error', $e->getMessage());
      }

      $this->stripe_debug_log('subscription activation helper runtime exception', array(
        'context' => $context,
        'order_id' => $order_id,
        'message' => $e->getMessage(),
      ));

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

  private function mrm_get_subscription_order_diagnostics_for_admin($subscription_id) {
    global $wpdb;

    $subscription_id = sanitize_text_field((string)$subscription_id);
    if ($subscription_id === '') {
      return array(
        'order_id' => '',
        'payment_intent_id' => '',
        'activation_status' => '',
        'last_activation_error' => '',
      );
    }

    $order = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$this->table_orders()} WHERE metadata_json LIKE %s ORDER BY id DESC LIMIT 1",
        '%"mrm_sheet_music_subscription_id":"' . $wpdb->esc_like($subscription_id) . '"%'
      ),
      ARRAY_A
    );

    if (!is_array($order) || empty($order)) {
      return array(
        'order_id' => '',
        'payment_intent_id' => '',
        'activation_status' => '',
        'last_activation_error' => '',
      );
    }

    return array(
      'order_id' => (string)($order['id'] ?? ''),
      'payment_intent_id' => (string)($order['stripe_payment_intent_id'] ?? ''),
      'activation_status' => (string)$this->mrm_get_order_meta_value($order, 'mrm_sheet_music_subscription_status', ''),
      'last_activation_error' => (string)$this->mrm_get_order_meta_value($order, 'mrm_sheet_music_subscription_error', ''),
    );
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

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT
            id,
            email_hash,
            email_plain,
            stripe_subscription_id,
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

    if (!is_array($rows)) {
      return array();
    }

    foreach ($rows as &$row) {
      $diag = $this->mrm_get_subscription_order_diagnostics_for_admin((string)($row['stripe_subscription_id'] ?? ''));
      $row['order_id'] = (string)($diag['order_id'] ?? '');
      $row['payment_intent_id'] = (string)($diag['payment_intent_id'] ?? '');
      $row['activation_status'] = (string)($diag['activation_status'] ?? '');
      $row['last_activation_error'] = (string)($diag['last_activation_error'] ?? '');
    }
    unset($row);

    $filtered = array();

    foreach ($rows as $row) {
      $email = sanitize_email((string)($row['email_plain'] ?? ''));
      if (!$email || !is_email($email)) {
        continue;
      }

      $status = $this->mrm_get_sheet_music_subscription_access_status_by_email($email);

      if (!empty($status['has_access'])) {
        $filtered[] = $row;
      }
    }

    return $filtered;
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
      "SELECT name,email,phone FROM {$table} WHERE id=%d LIMIT 1",
      $iid
    ), ARRAY_A);

    if (!is_array($row)) return array('name'=>'','email'=>'');
    return array(
      'name'  => (string)($row['name'] ?? ''),
      'email' => (string)($row['email'] ?? ''),
      'phone' => (string)($row['phone'] ?? ''),
    );
  }

  private function mrm_get_lesson_row_for_receipt($lesson_id) {
    global $wpdb;

    $lesson_id = (int)$lesson_id;
    if ($lesson_id <= 0) return array();

    $table = $wpdb->prefix . 'mrm_lessons';
    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT id, series_id, instructor_id, lesson_length, is_online, autopay_profile_id, payment_mode, start_time, reminder_token, google_meet_url
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


  private function mrm_claim_subscription_enrollment_send($order_id) {
    $order_id = (int)$order_id;
    if ($order_id <= 0) return false;

    $lock_key = 'mrm_sub_enrollment_claim_order_' . $order_id;

    if (get_option($lock_key, null)) {
      return false;
    }

    $added = add_option($lock_key, current_time('mysql'), '', 'no');
    return (bool)$added;
  }

  private function mrm_claim_subscription_activation($order_id) {
    $order_id = (int)$order_id;
    if ($order_id <= 0) return false;

    $lock_key = 'mrm_sub_activation_claim_order_' . $order_id;

    if (get_option($lock_key, null)) {
      return false;
    }

    $added = add_option($lock_key, current_time('mysql'), '', 'no');
    return (bool)$added;
  }

  private function mrm_release_subscription_activation($order_id) {
    $order_id = (int)$order_id;
    if ($order_id <= 0) return;

    $lock_key = 'mrm_sub_activation_claim_order_' . $order_id;
    delete_option($lock_key);
  }

  private function mrm_release_subscription_enrollment_send($order_id) {
    $order_id = (int)$order_id;
    if ($order_id <= 0) return;

    $lock_key = 'mrm_sub_enrollment_claim_order_' . $order_id;
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
    $piece_labels = $this->mrm_parse_piece_sku_labels($sku);

    if (!empty($piece_labels['is_piece'])) {
      $label = $piece_labels['display_label'];
    } else {
      $label = (is_array($p) && !empty($p['label'])) ? (string)$p['label'] : ($sku ?: 'Purchase');
    }

    $amount_cents = 0;
    if (isset($pi['amount_received'])) $amount_cents = (int)$pi['amount_received'];
    elseif (isset($pi['amount'])) $amount_cents = (int)$pi['amount'];
    else $amount_cents = (int)($order_row['amount_cents'] ?? 0);

    $base_cents  = (int)($meta['mrm_base_amount_cents'] ?? 0);
    $addon_cents = (int)($meta['mrm_addon_amount_cents'] ?? 0);
    $tax_cents   = (int)($meta['mrm_addon_tax_cents'] ?? 0);

    $fmt_money = function($c){ return '$' . number_format(((int)$c)/100, 2); };

    $contact_url = $this->mrm_get_contact_url();
    $piece_page_url = $product_type === 'sheet_music' ? $this->mrm_get_piece_page_url_from_sku($sku) : '';
    $lesson_id = (int)($meta['mrm_lesson_id'] ?? 0);
    $lesson_row = $product_type === 'lesson' ? $this->mrm_get_lesson_row_for_receipt($lesson_id) : array();
    $iid = (int)($meta['mrm_instructor_id'] ?? 0);
    if ($iid <= 0 && !empty($lesson_row['instructor_id'])) $iid = (int)$lesson_row['instructor_id'];
    $instructor = $iid ? $this->mrm_get_instructor_contact_from_id($iid) : array('name' => '', 'email' => '');
    $instructor_name = trim((string)($instructor['name'] ?? ''));
    $is_online_lesson = !empty($lesson_row['is_online']);
    $join_url = '';
    if (!empty($lesson_row['reminder_token'])) {
      $join_url = add_query_arg(
        array('token' => (string)$lesson_row['reminder_token']),
        home_url('/join-online/')
      );
    }
    $cancel_url = $lesson_id > 0 ? $this->mrm_lesson_cancel_url($lesson_id) : '';
    $has_sheet_music_addon = ((int)($meta['mrm_addon_amount_cents'] ?? 0) > 0);

    if ($product_type === 'sheet_music') {
      $title = 'Purchase Confirmation';
      $subject = 'Purchase Confirmation - ' . $label;
      $intro = '<p>We’ve received your payment successfully.</p>';
      $details = '<div><strong>Item:</strong> ' . esc_html($label) . '</div>';
      $details .= $this->mrm_purchase_receipt_payment_breakdown_html($meta, $amount_cents, $product_type);
      $details .= '<div style="margin-top:12px;"><strong>How to access your sheet music:</strong></div><ol style="margin:8px 0 0 18px;padding:0;"><li>Return to the piece page on the website.</li><li>Click the access button for your purchased category.</li><li>Enter your purchase email address.</li><li>Request your one-time access code and enter it to open the content.</li></ol>';
      $buttons = array();
      if ($piece_page_url !== '') $buttons[] = array('url' => $piece_page_url, 'label' => 'View Your Piece', 'variant' => 'primary');
      $buttons[] = array('url' => $contact_url, 'label' => 'Contact Support', 'variant' => 'primary');
      $html = $this->mrm_email_wrap_html($title, $intro, $details, $buttons, '');
    } elseif ($product_type === 'lesson') {
      $lesson_type_label = $is_online_lesson ? 'online lesson' : 'in-person lesson';
      $title = 'Purchase Confirmation';
      $subject = 'Purchase Confirmation - ' . ucwords($lesson_type_label) . ' with ' . $instructor_name;
      $intro = '<p>We’ve received your payment successfully.</p>';
      $details = '<div><strong>Item:</strong> ' . esc_html($lesson_type_label . ' with ' . $instructor_name) . '</div>';
      $details .= $has_sheet_music_addon ? $this->mrm_purchase_receipt_payment_breakdown_html($meta, $amount_cents, $product_type) : '<div><strong>Total paid:</strong> ' . esc_html($this->mrm_receipt_format_money($amount_cents)) . '</div>';
      if ($is_online_lesson) {
        $details .= '<div style="margin-top:14px;"><strong>How to access online lessons</strong></div><p>Your meeting link will become available 10 minutes before your lesson time and will remain available until 10 minutes after your lesson time. Please make sure your camera, microphone, and internet connection are working before joining the call.</p>';
      } else {
        $details .= '<div style="margin-top:14px;"><strong>How to prepare for in-person lessons</strong></div><p>Please prepare a comfortable shared space for the lesson, such as a living room or family room. The space should include two chairs, a music stand, and as little background noise as possible from TVs, conversations, or other activity.</p><div style="margin-top:14px;"><strong>Lessons outside the home</strong></div><p>If the lesson will take place at a school, church, or other community location, please complete the required approval form before the lesson begins.</p><p><a href="https://www.docusign.com/" target="_blank" rel="noopener">Placeholder DocuSign location approval link</a></p>';
      }
      $details .= '<p style="margin-top:14px;"><strong>Cancellations must be submitted at least 24 hours in advance to receive a refund. Refunds will not be issued for cancellations that occur within 24 hours of the lesson time.</strong></p>';
      if ($has_sheet_music_addon) $details .= '<div style="margin-top:14px;"><strong>How to access your sheet music</strong></div><p>Please check your email for the subscription confirmation.</p>';
      $buttons = array();
      if ($join_url !== '') $buttons[] = array('url' => $join_url, 'label' => 'Join Lesson', 'variant' => 'primary');
      if ($cancel_url !== '') $buttons[] = array('url' => $cancel_url, 'label' => 'Cancel Lesson', 'variant' => 'cancel');
      $html = $this->mrm_email_wrap_html($title, $intro, $details, $buttons, '');
    } else {
      $title = 'Purchase Confirmation';
      $subject = 'Purchase Confirmation - ' . $label;
      $intro = '<p>We’ve received your payment successfully.</p>';
      $details = '<div><strong>Item:</strong> ' . esc_html($label) . '</div>' . $this->mrm_purchase_receipt_payment_breakdown_html($meta, $amount_cents, $product_type);
      $html = $this->mrm_email_wrap_html($title, $intro, $details, $contact_url, 'Contact Support');
    }

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

    $title = 'Subscription Confirmation - Sheet Music Access';
    $intro = '<p>You have successfully enrolled in the sheet music subscription service.</p>';
    $status_label = (string)($sub_row['stripe_status'] ?? 'active');
    if ($status_label === '') $status_label = 'active';

    $details =
      '<div><strong>Subscription:</strong> Monthly Sheet Music Access</div>' .
      '<div><strong>Amount:</strong> $5.00 Per Month</div>' .
      '<div><strong>Renews on:</strong> ' . esc_html($anchor_label) . '</div>' .
      $this->mrm_get_sheet_music_access_section_html() .
      '<div style="margin-top:12px;"><strong>Purchase Details</strong></div>' .
      '<div>Your subscription has been created successfully in our billing system.</div>' .
      '<div style="margin-top:12px;">You will be billed again on or about <strong>' . esc_html($anchor_label) . '</strong>, and then monthly thereafter while the subscription remains active.</div>';

    $after_cta_html = '';
    if ($manage_url !== '') {
      $after_cta_html = '<div style="margin-top:14px;text-align:right;">
    <a href="' . esc_url($manage_url) . '" style="color:#111;text-decoration:underline;">Cancel Subscription</a>
  </div>';
    }

    $html = $this->mrm_email_wrap_html($title, $intro, $details, $contact_url, 'Contact Support', $after_cta_html);

    $sent = wp_mail(
      $email,
      'Subscription Confirmation - Sheet Music Access',
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

    $title = 'Subscription Renewal - Sheet Music Access';
    $intro = '<p>Your saved card has been successfully charged for your sheet music subscription renewal.</p>';

    $details =
      '<div><strong>Subscription:</strong> Monthly Sheet Music Access</div>' .
      '<div><strong>Amount Charged:</strong> $' . number_format($amount_paid / 100, 2) . '</div>' .
      '<div><strong>Next renewal date:</strong> ' . esc_html($next_label) . '</div>' .
      $this->mrm_get_sheet_music_access_section_html() .
      '<div style="margin-top:12px;"><strong>Purchase Details</strong></div>' .
      '<div>Your sheet music subscription remains active.</div>' .
      '<div><strong>Invoice ID:</strong> ' . esc_html($invoice_id) . '</div>' .
      '<div style="margin-top:12px;">Your next monthly billing date will be on or about <strong>' . esc_html($next_label) . '</strong>.</div>';

    $after_cta_html = '';
    if ($manage_url !== '') {
      $after_cta_html = '<div style="margin-top:14px;text-align:right;">
    <a href="' . esc_url($manage_url) . '" style="color:#111;text-decoration:underline;">Cancel Subscription</a>
  </div>';
    }

    $html = $this->mrm_email_wrap_html($title, $intro, $details, $contact_url, 'Contact Support', $after_cta_html);

    return wp_mail(
      $email,
      'Subscription Renewal - Sheet Music Access',
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
    /*
     * Deprecated intentionally.
     *
     * Do not send the separate "Payment confirmation — Lesson charge" email.
     * The canonical customer-facing receipt is sent through:
     * mrm_maybe_send_purchase_receipt_email()
     *
     * Keeping this method as a no-op prevents fatal errors if older internal code
     * still calls it, while guaranteeing customers do not receive the duplicate
     * Payment Confirmation email.
     */
    return false;
  }

  private function mrm_send_sheet_music_subscription_renewal_reminder_email($sub_row, $renewal_ts) {
    if (!is_array($sub_row)) return false;
    $email = sanitize_email((string)($sub_row['email_plain'] ?? ''));
    if (!$email || !is_email($email)) return false;
    $manage_url = $this->mrm_subscription_manage_url((string)($sub_row['portal_token'] ?? ''));
    $contact_url = $this->mrm_get_contact_url();
    $renewal_label = $renewal_ts > 0 ? wp_date('F j, Y \a\t g:i A', $renewal_ts, wp_timezone()) : '';
    $title = 'Subscription Renewal Reminder - Sheet Music Access';
    $intro = '<p>This is a reminder that your sheet music subscription renewal is scheduled to occur on <strong>' . esc_html($renewal_label) . '</strong>.</p>';
    $details = '<div><strong>Subscription:</strong> Monthly Sheet Music Access</div>' . '<div><strong>Amount:</strong> $5.00 Per Month</div>' . '<div><strong>Renewal date:</strong> ' . esc_html($renewal_label) . '</div>' . $this->mrm_get_sheet_music_access_section_html();
    $after_cta_html = $manage_url !== '' ? '<div style="margin-top:14px;text-align:right;"><a href="' . esc_url($manage_url) . '" style="color:#111;text-decoration:underline;">Cancel Subscription</a></div>' : '';
    return wp_mail($email, 'Subscription Renewal Reminder - Sheet Music Access', $this->mrm_email_wrap_html($title, $intro, $details, $contact_url, 'Contact Support', $after_cta_html), array('Content-Type: text/html; charset=UTF-8','From: LowBrass Lessons <no-reply@lowbrass-lessons.com>'));
  }

  public function cron_send_sheet_music_subscription_renewal_reminders() {
    global $wpdb;
    $table = $this->table_sheet_music_subscriptions();
    $now = time();
    $window_start = gmdate('Y-m-d H:i:s', $now + DAY_IN_SECONDS - HOUR_IN_SECONDS);
    $window_end = gmdate('Y-m-d H:i:s', $now + DAY_IN_SECONDS + HOUR_IN_SECONDS);
    $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE stripe_status = 'active' AND current_period_end >= '" . esc_sql($window_start) . "' AND current_period_end <= '" . esc_sql($window_end) . "' AND (renewal_reminder_24h_sent_at IS NULL OR renewal_reminder_24h_sent_at = '')", ARRAY_A);
    foreach ((array)$rows as $row) {
      $renewal_ts = !empty($row['current_period_end']) ? strtotime((string)$row['current_period_end']) : 0;
      if ($this->mrm_send_sheet_music_subscription_renewal_reminder_email($row, $renewal_ts)) {
        $wpdb->update($table, array('renewal_reminder_24h_sent_at' => current_time('mysql')), array('id' => absint($row['id'])));
      }
    }
  }

  private function mrm_send_sheet_music_subscription_cancelled_email($sub_row, $subscription) {
    if (!is_array($sub_row)) return false;

    $email = sanitize_email((string)($sub_row['email_plain'] ?? ''));
    if (!$email || !is_email($email)) return false;

    $ended_at = !empty($subscription['ended_at']) ? (int)$subscription['ended_at'] : 0;
    $ended_label = $ended_at > 0 ? wp_date('F j, Y', $ended_at, wp_timezone()) : 'today';
    $contact_url = $this->mrm_get_contact_url();

    $title = 'Subscription Cancelled';
    $intro = '<p>Your sheet music subscription has been cancelled.</p>';
    $details =
      '<div><strong>Subscription:</strong> Monthly sheet music access</div>' .
      '<div><strong>Status:</strong> Cancelled</div>' .
      '<div><strong>Cancellation date:</strong> ' . esc_html($ended_label) . '</div>' .
      '<div style="margin-top:12px;">You will not be charged again unless you subscribe again in the future. You will have access to sheet music products throughout the remainder of your subscription period which ends on: ' . esc_html($ended_label) . '.</div>';

    $html = $this->mrm_email_wrap_html($title, $intro, $details, $contact_url, 'Contact Support');

    return wp_mail(
      $email,
      'Subscription Update - Sheet Music Access Cancelled',
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


  private function mrm_lesson_cancel_url($lesson_id) {
    return $this->mrm_signed_lesson_action_url('mrm_lesson_cancel_request', absint($lesson_id), 0);
  }

  private function mrm_get_lesson_for_cancellation($lesson_id) {
    global $wpdb;
    $lessons = $this->table_lessons();
    $instructors = $wpdb->prefix . 'mrm_instructors';
    $row = $wpdb->get_row($wpdb->prepare("SELECT l.*, i.name AS instructor_name, i.email AS instructor_email, i.phone AS instructor_phone FROM {$lessons} l LEFT JOIN {$instructors} i ON i.id = l.instructor_id WHERE l.id = %d LIMIT 1", absint($lesson_id)), ARRAY_A);
    return is_array($row) ? $row : array();
  }

  public function render_lesson_cancel_request() {
    $lesson_id=absint($_GET['lesson_id'] ?? 0); $expires=absint($_GET['expires'] ?? 0); $sig=sanitize_text_field((string)($_GET['sig'] ?? ''));
    if (!$this->mrm_verify_signed_lesson_action('mrm_lesson_cancel_request',$lesson_id,0,$expires,$sig)) wp_die('This cancellation link is invalid or expired.', 'Cancel Lesson', array('response'=>403));
    $lesson=$this->mrm_get_lesson_for_cancellation($lesson_id); if (!$lesson) wp_die('Lesson not found.', 'Cancel Lesson', array('response'=>404));
    $lesson_time=!empty($lesson['start_time']) ? wp_date('F j, Y \a\t g:i A', strtotime($lesson['start_time']), wp_timezone()) : '';
    echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cancel Lesson</title></head><body style="margin:0;background:#f6f6f6;font-family:Arial,sans-serif;color:#111;"><div style="max-width:720px;margin:0 auto;padding:28px;"><div style="background:#fff;border:1px solid #e8e8e8;border-radius:16px;padding:28px;"><h1>Cancel Lesson</h1><p>Please provide the reason for cancelling your lesson.</p><p><strong>Lesson:</strong> '.esc_html($lesson_time).'</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" id="mrm-lesson-cancel-form"><input type="hidden" name="action" value="mrm_lesson_cancel_submit"><input type="hidden" name="lesson_id" value="'.esc_attr($lesson_id).'"><input type="hidden" name="expires" value="'.esc_attr($expires).'"><input type="hidden" name="sig" value="'.esc_attr($sig).'">';
    foreach (array('Scheduling conflict','Illness or emergency','No longer available','Other') as $r) echo '<label><input type="radio" name="cancel_reason" value="'.esc_attr($r).'" required> '.esc_html($r).'</label><br>';
    echo '<textarea name="cancel_reason_other" placeholder="If other, please explain." style="width:100%;min-height:120px;margin-top:12px;"></textarea><p><button type="submit" style="background:#111;color:#fff;border:1px solid #111;border-radius:10px;padding:13px 20px;font-weight:700;">Submit Cancellation</button></p></form><script>document.getElementById("mrm-lesson-cancel-form").addEventListener("submit",function(e){if(!confirm("Are you sure you want to cancel this lesson?")){e.preventDefault();}});</script></div></div></body></html>'; exit;
  }

  public function handle_lesson_cancel_submit() {
    global $wpdb;
    $lesson_id=absint($_POST['lesson_id'] ?? 0); $expires=absint($_POST['expires'] ?? 0); $sig=sanitize_text_field((string)($_POST['sig'] ?? ''));
    if (!$this->mrm_verify_signed_lesson_action('mrm_lesson_cancel_request',$lesson_id,0,$expires,$sig)) wp_die('This cancellation request is invalid or expired.', 'Cancel Lesson', array('response'=>403));
    $lesson=$this->mrm_get_lesson_for_cancellation($lesson_id); if (!$lesson) wp_die('Lesson not found.', 'Cancel Lesson', array('response'=>404));
    $reason=sanitize_text_field((string)($_POST['cancel_reason'] ?? '')); $other=sanitize_textarea_field((string)($_POST['cancel_reason_other'] ?? '')); if ($reason==='Other' && $other!=='') $reason .= ': '.$other;
    $start_ts=!empty($lesson['start_time']) ? strtotime((string)$lesson['start_time']) : 0; $more_than_24h=($start_ts>0 && (time() <= ($start_ts - DAY_IN_SECONDS)));
    $wpdb->update($this->table_lessons(), array('status'=>'cancelled','updated_at'=>current_time('mysql')), array('id'=>$lesson_id));
    $order_id=absint($lesson['order_id'] ?? 0); $order=$order_id>0 ? $this->mrm_get_order_by_id($order_id) : $this->mrm_find_lesson_charge_order($lesson_id);
    $refund_sent=false; $refund_amount_cents=0;
    if ($more_than_24h && is_array($order) && !empty($order['stripe_payment_intent_id'])) { $refund_amount_cents=absint($order['amount_cents'] ?? 0); $refund=$this->stripe_create_refund((string)$order['stripe_payment_intent_id'], null, 'requested_by_customer'); if (!is_wp_error($refund)) { $refund_sent=true; $this->mrm_send_lesson_cancellation_refund_email($lesson,$refund_amount_cents); } }
    if (!$refund_sent) $this->mrm_send_lesson_cancellation_no_refund_email($lesson, absint($order['amount_cents'] ?? 0));
    $this->mrm_notify_lesson_cancelled_by_client($lesson,$reason,$refund_sent);
    echo '<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f6f6f6;margin:0;"><div style="max-width:720px;margin:0 auto;padding:28px;"><div style="background:#fff;border:1px solid #e8e8e8;border-radius:16px;padding:28px;"><h1>Lesson Cancelled</h1>';
    echo $refund_sent ? '<p>Your lesson has been cancelled and your refund has been submitted. It may take 3 to 5 business days for the funds to return to your account. Please check your email for confirmation.</p>' : '<p>Your lesson has been cancelled. This cancellation does not qualify for a refund because it was submitted without a full 24-hour notice.</p><p>This policy is clearly defined in the terms of service: <a href="'.esc_url(home_url('/terms-of-service/')).'">Terms of Service</a>.</p>';
    echo '</div></div></body></html>'; exit;
  }

  private function mrm_send_lesson_cancellation_no_refund_email($lesson, $amount_paid_cents = 0) {
    if (!is_array($lesson)) return false; $email=sanitize_email((string)($lesson['student_email'] ?? '')); if (!$email || !is_email($email)) return false;
    $lesson_start=(string)($lesson['start_time'] ?? ''); $lesson_label=$lesson_start!=='' ? wp_date('F j, Y \a\t g:i A', strtotime($lesson_start), wp_timezone()) : 'your scheduled lesson';
    $amount_label=$amount_paid_cents > 0 ? '$'.number_format($amount_paid_cents/100,2) : 'your lesson payment';
    $details='<div><strong>Cancelled lesson:</strong> '.esc_html($lesson_label).'</div><div><strong>Amount paid:</strong> '.esc_html($amount_label).'</div><div style="margin-top:12px;">If you believe this to be a mistake please contact support.</div>';
    return wp_mail($email, 'Lesson update — Cancellation', $this->mrm_email_wrap_html('Lesson cancelled','<p>Your lesson has been cancelled and it does not qualify for a refund as the lesson was not cancelled the minimum 24 hours in advance.</p>',$details,$this->mrm_get_contact_url(),'Contact Support'), array('Content-Type: text/html; charset=UTF-8','From: LowBrass Lessons <no-reply@lowbrass-lessons.com>'));
  }

  private function mrm_notify_lesson_cancelled_by_client($lesson, $reason, $refunded) {
    $admin_email=sanitize_email((string)get_option('admin_email')); $instructor_email=sanitize_email((string)($lesson['instructor_email'] ?? ''));
    $lesson_time=!empty($lesson['start_time']) ? wp_date('F j, Y \a\t g:i A', strtotime($lesson['start_time']), wp_timezone()) : '';
    $details='<div><strong>Lesson:</strong> '.esc_html($lesson_time).'</div><div><strong>Instructor:</strong> '.esc_html((string)($lesson['instructor_name'] ?? '')).'</div><div><strong>Student email:</strong> '.esc_html((string)($lesson['student_email'] ?? '')).'</div><div><strong>Cancellation reason:</strong> '.esc_html($reason).'</div><div><strong>Refunded:</strong> '.esc_html($refunded ? 'Yes' : 'No').'</div>';
    $html=$this->mrm_email_wrap_html('Lesson Cancellation Notice','<p>A client has cancelled a lesson.</p>',$details,'',''); $headers=array('Content-Type: text/html; charset=UTF-8','From: LowBrass Lessons <no-reply@lowbrass-lessons.com>');
    if ($admin_email && is_email($admin_email)) wp_mail($admin_email,'Lesson cancellation notice',$html,$headers); if ($instructor_email && is_email($instructor_email)) wp_mail($instructor_email,'Lesson cancellation notice',$html,$headers);
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

  private function mrm_summary_money($cents) {
    return '$' . number_format(((int)$cents) / 100, 2);
  }

  private function mrm_payout_summary_owner_email() {
    $settings = $this->get_settings();
    $email = sanitize_email((string)($settings['owner_payout_summary_email'] ?? ''));
    if (!$email || !is_email($email)) $email = sanitize_email((string)get_option('admin_email', ''));
    return $email && is_email($email) ? $email : '';
  }

  private function mrm_payout_summary_composer_email() {
    $settings = $this->get_settings();
    $email = sanitize_email((string)($settings['composer_payout_summary_email'] ?? ''));
    return $email && is_email($email) ? $email : '';
  }

  private function mrm_payout_period_label_from_rows($rows, $period = null) {
    if (is_array($period) && !empty($period['start_mysql']) && !empty($period['end_mysql'])) return $this->mrm_format_payout_period_short($period['start_mysql'], $period['end_mysql']);
    $min = ''; $max = '';
    foreach ((array)$rows as $row) { $created = (string)($row['created_at'] ?? ''); if ($created === '') continue; if ($min === '' || strcmp($created, $min) < 0) $min = $created; if ($max === '' || strcmp($created, $max) > 0) $max = $created; }
    return ($min !== '' && $max !== '') ? $this->mrm_format_payout_period_short($min, $max) : wp_date('m/d/Y', time(), wp_timezone());
  }

  private function mrm_format_payout_period_short($start_mysql, $end_mysql) {
    $start_ts = strtotime((string)$start_mysql); $end_ts = strtotime((string)$end_mysql);
    if (!$start_ts || !$end_ts) return '';
    return wp_date('m/d/Y', $start_ts, wp_timezone()) . ' - ' . wp_date('m/d/Y', $end_ts, wp_timezone());
  }

  private function mrm_send_wrapped_summary_email($to, $subject, $title, $intro_html, $details_html, $cta_url = '', $cta_label = '') {
    $to = sanitize_email((string)$to);
    if (!$to || !is_email($to)) return false;
    $body = $this->mrm_email_wrap_html($title, $intro_html, $details_html, $cta_url, $cta_label);
    return wp_mail($to, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
  }

  private function mrm_sum_platform_retained_for_order_ids($order_ids) {
    global $wpdb;
    $order_ids = array_values(array_filter(array_unique(array_map('absint', (array)$order_ids))));
    if (empty($order_ids)) return 0;
    $table = $this->table_payout_ledger();
    $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
    return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(net_cents),0) FROM {$table} WHERE payee_type = 'platform' AND order_id IN ({$placeholders})", $order_ids));
  }

  private function mrm_send_instructor_payout_summary_for_rows($rows, $payout, $period, $batch_key) {
    global $wpdb;
    $lesson_ids = array(); $order_ids = array();
    foreach ((array)$rows as $row) { if (preg_match('/^lesson:(\d+)$/', (string)($row['payee_ref'] ?? ''), $m)) $lesson_ids[] = absint($m[1]); if (!empty($row['order_id'])) $order_ids[] = (int)$row['order_id']; }
    $lesson_ids = array_values(array_filter(array_unique($lesson_ids)));
    if (empty($lesson_ids)) return array();
    $lessons = $this->table_lessons(); $instructors = $wpdb->prefix . 'mrm_instructors'; $ph = implode(',', array_fill(0, count($lesson_ids), '%d'));
    $lesson_rows = $wpdb->get_results($wpdb->prepare("SELECT l.id, l.lesson_length, l.is_online, i.name AS instructor_name, i.email AS instructor_email FROM {$lessons} l LEFT JOIN {$instructors} i ON i.id = l.instructor_id WHERE l.id IN ({$ph})", $lesson_ids), ARRAY_A);
    $lesson_map = array(); foreach ((array)$lesson_rows as $lr) $lesson_map[(int)$lr['id']] = $lr;
    $summary = array('30_online'=>array('label'=>'Online 30-minute lessons','count'=>0,'cents'=>0),'60_online'=>array('label'=>'Online 60-minute lessons','count'=>0,'cents'=>0),'30_inperson'=>array('label'=>'In-person 30-minute lessons','count'=>0,'cents'=>0),'60_inperson'=>array('label'=>'In-person 60-minute lessons','count'=>0,'cents'=>0));
    $total = 0; $to = ''; $name = 'Instructor';
    foreach ((array)$rows as $row) { if (!preg_match('/^lesson:(\d+)$/', (string)($row['payee_ref'] ?? ''), $m)) continue; $lesson = $lesson_map[absint($m[1])] ?? null; if (!$lesson) continue; $key = (int)($lesson['lesson_length'] ?? 0) . '_' . (!empty($lesson['is_online']) ? 'online' : 'inperson'); if (!isset($summary[$key])) continue; $amount = (int)($row['net_cents'] ?? 0); $summary[$key]['count']++; $summary[$key]['cents'] += $amount; $total += $amount; if ($to === '' && !empty($lesson['instructor_email'])) $to = sanitize_email((string)$lesson['instructor_email']); if (!empty($lesson['instructor_name'])) $name = (string)$lesson['instructor_name']; }
    $details = '<div><strong>Pay period:</strong> ' . esc_html($this->mrm_payout_period_label_from_rows($rows, $period)) . '</div><div><strong>Stripe payout:</strong> ' . esc_html((string)($payout['id'] ?? '')) . '</div><table style="width:100%;border-collapse:collapse;margin-top:14px;"><thead><tr><th style="text-align:left;border:1px solid #ddd;padding:8px;">Lesson Type</th><th style="text-align:left;border:1px solid #ddd;padding:8px;">Count</th><th style="text-align:left;border:1px solid #ddd;padding:8px;">Payout</th></tr></thead><tbody>';
    foreach ($summary as $row) $details .= '<tr><td style="border:1px solid #ddd;padding:8px;">' . esc_html($row['label']) . '</td><td style="border:1px solid #ddd;padding:8px;">' . esc_html((string)$row['count']) . '</td><td style="border:1px solid #ddd;padding:8px;">' . esc_html($this->mrm_summary_money($row['cents'])) . '</td></tr>';
    $details .= '</tbody></table><div style="margin-top:14px;font-size:17px;"><strong>Total payout:</strong> ' . esc_html($this->mrm_summary_money($total)) . '</div>';
    $this->mrm_send_wrapped_summary_email($to, 'Low Brass Lessons payout summary ' . $this->mrm_payout_period_label_from_rows($rows, $period), 'Payout Summary', '<p>Your instructor payout has been processed successfully. Here is the summary:</p>', $details, $this->mrm_get_contact_url(), 'Contact Support');
    return array('type'=>'instructor','name'=>$name,'email'=>$to,'paid_cents'=>$total,'platform_cents'=>$this->mrm_sum_platform_retained_for_order_ids($order_ids),'promo_loss_cents'=>0);
  }

  private function mrm_send_composer_payout_summary_for_rows($rows, $payout, $period, $batch_key) {
    global $wpdb;
    $to = $this->mrm_payout_summary_composer_email(); $orders_table = $this->table_orders(); $products = $this->all_products(); $order_ids = array();
    foreach ((array)$rows as $row) if (!empty($row['order_id'])) $order_ids[] = (int)$row['order_id'];
    $order_ids = array_values(array_filter(array_unique($order_ids))); $orders_by_id = array();
    if ($order_ids) { $ph = implode(',', array_fill(0, count($order_ids), '%d')); $ors = $wpdb->get_results($wpdb->prepare("SELECT id, sku, metadata_json FROM {$orders_table} WHERE id IN ({$ph})", $order_ids), ARRAY_A); foreach ((array)$ors as $or) $orders_by_id[(int)$or['id']] = $or; }
    $items = array(); $subscription_count = 0; $total = 0; $promo_loss = 0;
    foreach ((array)$rows as $row) { $amount = (int)($row['net_cents'] ?? 0); $total += $amount; $order_id = (int)($row['order_id'] ?? 0); $ref = (string)($row['payee_ref'] ?? '');
      if ($order_id > 0 && isset($orders_by_id[$order_id])) { $order = $orders_by_id[$order_id]; $sku = (string)($order['sku'] ?? ''); $label = $products[$sku]['label'] ?? $sku; $meta = json_decode((string)($order['metadata_json'] ?? ''), true); if (!is_array($meta)) $meta = array(); $promo_code = (string)($meta['mrm_promo_code'] ?? ''); $discount = max(0, (int)($meta['mrm_promo_discount_cents'] ?? 0)); $promo_loss += $discount; $key = 'piece|' . $sku . '|' . $promo_code; if (!isset($items[$key])) $items[$key] = array('label'=>$label,'kind'=>'Piece / product sale','count'=>0,'payout_cents'=>0,'promo_code'=>$promo_code,'promo_loss_cents'=>0); $items[$key]['count']++; $items[$key]['payout_cents'] += $amount; $items[$key]['promo_loss_cents'] += $discount;
      } elseif (strpos($ref, 'stripe_subscription_invoice:') === 0) { $subscription_count++; $key = 'subscription|sheet_music_subscription'; if (!isset($items[$key])) $items[$key] = array('label'=>'Sheet music subscription','kind'=>'Subscription payment','count'=>0,'payout_cents'=>0,'promo_code'=>'','promo_loss_cents'=>0); $items[$key]['count']++; $items[$key]['payout_cents'] += $amount; }}
    $details = '<div><strong>Pay period:</strong> ' . esc_html($this->mrm_payout_period_label_from_rows($rows, $period)) . '</div><div><strong>Stripe payout:</strong> ' . esc_html((string)($payout['id'] ?? '')) . '</div><div><strong>Subscriptions paid in this window:</strong> ' . esc_html((string)$subscription_count) . '</div><table style="width:100%;border-collapse:collapse;margin-top:14px;"><thead><tr><th style="text-align:left;border:1px solid #ddd;padding:8px;">Product</th><th style="text-align:left;border:1px solid #ddd;padding:8px;">Type</th><th style="text-align:left;border:1px solid #ddd;padding:8px;">Quantity</th><th style="text-align:left;border:1px solid #ddd;padding:8px;">Promo Reduction</th><th style="text-align:left;border:1px solid #ddd;padding:8px;">Composer Payout</th></tr></thead><tbody>';
    foreach ($items as $item) $details .= '<tr><td style="border:1px solid #ddd;padding:8px;">' . esc_html($item['label']) . '</td><td style="border:1px solid #ddd;padding:8px;">' . esc_html($item['kind']) . '</td><td style="border:1px solid #ddd;padding:8px;">' . esc_html((string)$item['count']) . '</td><td style="border:1px solid #ddd;padding:8px;">' . esc_html($this->mrm_summary_money($item['promo_loss_cents'])) . '</td><td style="border:1px solid #ddd;padding:8px;">' . esc_html($this->mrm_summary_money($item['payout_cents'])) . '</td></tr>';
    $details .= '</tbody></table><div style="margin-top:14px;"><strong>Total promo reduction affecting composer content:</strong> -' . esc_html($this->mrm_summary_money($promo_loss)) . '</div><div style="margin-top:8px;font-size:17px;"><strong>Total composer payout:</strong> ' . esc_html($this->mrm_summary_money($total)) . '</div>';
    $this->mrm_send_wrapped_summary_email($to, 'Low Brass Lessons payout summary ' . $this->mrm_payout_period_label_from_rows($rows, $period), 'Payout Summary', '<p>Your composer payout has been processed successfully. Here is the summary:</p>', $details, $this->mrm_get_contact_url(), 'Contact Support');
    return array('type'=>'composer','name'=>'Composer','email'=>$to,'paid_cents'=>$total,'platform_cents'=>$this->mrm_sum_platform_retained_for_order_ids($order_ids),'promo_loss_cents'=>$promo_loss);
  }

  private function mrm_send_standard_payout_summary_for_rows($rows, $payout, $period, $batch_key) {
    if (empty($rows)) return array(); $payee_type = sanitize_key((string)($rows[0]['payee_type'] ?? ''));
    if ($payee_type === 'instructor') return $this->mrm_send_instructor_payout_summary_for_rows($rows, $payout, $period, $batch_key);
    if ($payee_type === 'composer') return $this->mrm_send_composer_payout_summary_for_rows($rows, $payout, $period, $batch_key);
    return array();
  }

  private function mrm_send_owner_payout_batch_summary($batch_key, $items, $period_label = '') {
    $to = $this->mrm_payout_summary_owner_email(); if ($to === '' || empty($items)) return false;
    $totals = array('instructor'=>0,'composer'=>0,'presenter'=>0,'platform'=>0,'promo_loss'=>0);
    $details = '<div><strong>Batch:</strong> ' . esc_html($batch_key) . '</div>' . ($period_label !== '' ? '<div><strong>Pay period:</strong> ' . esc_html($period_label) . '</div>' : '') . '<table style="width:100%;border-collapse:collapse;margin-top:14px;"><thead><tr><th style="text-align:left;border:1px solid #ddd;padding:8px;">Payee Type</th><th style="text-align:left;border:1px solid #ddd;padding:8px;">Payee</th><th style="text-align:left;border:1px solid #ddd;padding:8px;">Paid Out</th><th style="text-align:left;border:1px solid #ddd;padding:8px;">Promo Reduction</th><th style="text-align:left;border:1px solid #ddd;padding:8px;">Company Retained</th></tr></thead><tbody>';
    foreach ((array)$items as $item) { if (empty($item) || !is_array($item)) continue; $type = sanitize_key((string)($item['type'] ?? '')); $paid = (int)($item['paid_cents'] ?? 0); $platform = (int)($item['platform_cents'] ?? 0); $promo_loss = (int)($item['promo_loss_cents'] ?? 0); if (isset($totals[$type])) $totals[$type] += $paid; $totals['platform'] += max(0, $platform - $promo_loss); $totals['promo_loss'] += $promo_loss; $display_platform = max(0, $platform - $promo_loss); $details .= '<tr><td style="border:1px solid #ddd;padding:8px;">' . esc_html(ucfirst($type)) . '</td><td style="border:1px solid #ddd;padding:8px;">' . esc_html((string)($item['name'] ?? '—')) . '</td><td style="border:1px solid #ddd;padding:8px;">' . esc_html($this->mrm_summary_money($paid)) . '</td><td style="border:1px solid #ddd;padding:8px;">-' . esc_html($this->mrm_summary_money($promo_loss)) . '</td><td style="border:1px solid #ddd;padding:8px;">' . esc_html($this->mrm_summary_money($display_platform)) . '</td></tr>'; }
    $details .= '</tbody></table><div style="margin-top:14px;"><strong>Instructor payouts:</strong> ' . esc_html($this->mrm_summary_money($totals['instructor'])) . '</div><div><strong>Presenter payouts:</strong> ' . esc_html($this->mrm_summary_money($totals['presenter'])) . '</div><div><strong>Composer payouts:</strong> ' . esc_html($this->mrm_summary_money($totals['composer'])) . '</div><div><strong>Promo-code reductions:</strong> -' . esc_html($this->mrm_summary_money($totals['promo_loss'])) . '</div><div style="margin-top:8px;font-size:17px;"><strong>Company retained / netted:</strong> ' . esc_html($this->mrm_summary_money($totals['platform'])) . '</div>';
    return $this->mrm_send_wrapped_summary_email($to, 'Low Brass Lessons owner payout batch summary', 'Owner Payout Batch Summary', '<p>A payout batch has been processed. Here is the owner/company summary.</p>', $details);
  }

  private function mrm_send_presenter_payout_summaries_for_rows($rows, $batch_key) {
    if (empty($rows)) return array(); $groups = array();
    foreach ((array)$rows as $row) { $presenter_id = (int)($row['presenter_id'] ?? 0); if ($presenter_id <= 0) continue; $groups[$presenter_id][] = $row; }
    $owner_items = array();
    foreach ($groups as $presenter_rows) { $first = $presenter_rows[0]; $to = sanitize_email((string)($first['presenter_email'] ?? '')); $name = (string)($first['presenter_name'] ?? 'Presenter'); $event_groups = array(); $total = 0; $platform_total = 0;
      foreach ($presenter_rows as $row) { $event_id = (int)($row['event_id'] ?? 0); $per_student = (int)($row['event_payout_per_student_cents'] ?? 0); if ($per_student <= 0) $per_student = (int)($row['presenter_share_cents'] ?? 0); if (!isset($event_groups[$event_id])) $event_groups[$event_id] = array('title'=>(string)($row['event_title'] ?? ('Event #' . $event_id)),'students'=>0,'per_student_cents'=>$per_student,'payout_cents'=>0); $event_groups[$event_id]['students']++; $event_groups[$event_id]['payout_cents'] += (int)($row['presenter_share_cents'] ?? 0); $total += (int)($row['presenter_share_cents'] ?? 0); if (isset($row['platform_share_cents'])) $platform_total += max(0, (int)$row['platform_share_cents']); }
      $details = '<div><strong>Masterclass:</strong> ' . esc_html((string)($first['event_title'] ?? '')) . '</div><table style="width:100%;border-collapse:collapse;margin-top:14px;"><thead><tr><th style="text-align:left;border:1px solid #ddd;padding:8px;">Payout Per Student</th><th style="text-align:left;border:1px solid #ddd;padding:8px;">Number of Students Enrolled</th></tr></thead><tbody>';
      foreach ($event_groups as $event) $details .= '<tr><td style="border:1px solid #ddd;padding:8px;">' . esc_html($this->mrm_summary_money($event['per_student_cents'])) . '</td><td style="border:1px solid #ddd;padding:8px;">' . esc_html((string)$event['students']) . '</td></tr>';
      $details .= '</tbody></table><div style="margin-top:14px;font-size:17px;"><strong>Payout Total:</strong> ' . esc_html($this->mrm_summary_money($total)) . '</div>';
      $this->mrm_send_wrapped_summary_email($to, 'Low Brass Lessons masterclass payout summary', 'Payout Summary', '<p>Your presenter payout has been processed successfully. Here is the summary:</p>', $details, $this->mrm_get_contact_url(), 'Contact Support');
      $owner_items[] = array('type'=>'presenter','name'=>$name,'email'=>$to,'paid_cents'=>$total,'platform_cents'=>$platform_total,'promo_loss_cents'=>0);
    }
    return $owner_items;
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
    /*
     * Do not send the separate "Payment confirmation — Lesson charge" email here.
     *
     * The normal Stripe payment_intent.succeeded flow already sends the canonical
     * "Purchase Confirmation" receipt through mrm_maybe_send_purchase_receipt_email().
     * Sending another auto-pay-specific receipt creates duplicate customer emails.
     */
    if (method_exists($this, 'stripe_debug_log')) {
      $this->stripe_debug_log('autopay lesson charge duplicate payment-confirmation email skipped', array(
        'lesson_id' => $lesson_id,
        'order_id' => $order_id,
        'email' => (string)($lesson['student_email'] ?? ''),
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


    return false;
  }


  public function on_lesson_delivered($data) {
    $lesson_id = (int)($data['lesson_id'] ?? 0);
    $mode = (string)($data['payment_mode'] ?? 'none');
    $instructor_id = (int)($data['instructor_id'] ?? 0);
    $autopay_profile_id = (int)($data['autopay_profile_id'] ?? 0);


    if ($lesson_id <= 0 || $instructor_id <= 0) {
      return;
    }

    if ($mode === 'prepay' || $mode === 'one_time') {
      $this->unlock_prepay_instructor_payout($data);
      return;
    }

    if ($mode === 'autopay') {
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
        }
        continue;
      }

      if (defined('WP_DEBUG') && WP_DEBUG) {
      }

      $status = (string)($row['status'] ?? '');
      if ($status !== 'payment_due') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
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
        }
        continue;
      }

      if (defined('WP_DEBUG') && WP_DEBUG) {
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

  private function mrm_lesson_cancellation_refund_guard($lesson) {
    if (!is_array($lesson) || empty($lesson)) {
      return array(
        'allowed' => false,
        'reason' => 'missing_lesson_row',
        'message' => 'Auto-refund skipped because the lesson row could not be found.',
      );
    }
    $start_raw = (string)($lesson['start_time'] ?? '');
    if ($start_raw === '') {
      return array('allowed' => false,'reason' => 'missing_lesson_start_time','message' => 'Auto-refund skipped because the lesson start time is missing.');
    }
    $start_ts = strtotime($start_raw);
    if (!$start_ts) {
      return array('allowed' => false,'reason' => 'invalid_lesson_start_time','message' => 'Auto-refund skipped because the lesson start time could not be parsed.');
    }
    $seconds_until_start = $start_ts - time();
    if ($seconds_until_start < DAY_IN_SECONDS) {
      return array('allowed' => false,'reason' => 'cancelled_less_than_24_hours_before_start','message' => 'Auto-refund skipped because the lesson was cancelled less than 24 hours before the scheduled start time.');
    }
    return array('allowed' => true,'reason' => '','message' => '');
  }

  private function mrm_mark_auto_refund_skipped_for_order($order, $reason, $note = '') {
    if (!is_array($order) || empty($order)) return false;
    $pi_id = (string)($order['stripe_payment_intent_id'] ?? '');
    $status = (string)($order['status'] ?? '');
    if ($pi_id === '') return false;
    $this->update_order_status_from_pi($pi_id, $status, (string)($order['stripe_status'] ?? $status), array(
      'mrm_auto_refund_skipped_at' => current_time('mysql'),
      'mrm_auto_refund_skip_reason' => sanitize_text_field((string)$reason),
      'mrm_auto_refund_note' => sanitize_textarea_field((string)$note),
    ));
    return true;
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
    $refund_guard = $this->mrm_lesson_cancellation_refund_guard($lesson);

    if ($payment_mode === 'autopay') {
      $lesson_order = $this->mrm_find_lesson_charge_order($lesson_id);
      $cancel_reason = (string)($data['cancel_reason'] ?? '');

      // Refund only if this specific lesson already has its own completed lesson-level charge.
      if ($lesson_order) {
        if (empty($refund_guard['allowed'])) {
          $this->mrm_mark_auto_refund_skipped_for_order($lesson_order, (string)$refund_guard['reason'], (string)$refund_guard['message']);
          $refunded = false;
        } else {
          $refunded = $this->mrm_request_refund_for_order(
            $lesson_order,
            'Auto-refund for cancelled autopay lesson ' . $lesson_id
          );
        }

        if ($refunded && is_array($lesson)) {
          $this->mrm_send_lesson_cancellation_refund_email(
            $lesson,
            (int)($lesson_order['amount_cents'] ?? 0)
          );
        }

        if (!$refunded && defined('WP_DEBUG') && WP_DEBUG) {
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
            if (empty($refund_guard['allowed'])) {
              $this->mrm_mark_auto_refund_skipped_for_order($first_order, (string)$refund_guard['reason'], (string)$refund_guard['message']);
              $refunded = false;
            } else {
              $refunded = $this->mrm_request_refund_for_order(
                $first_order,
                'Auto-refund for cancelled prepaid first autopay lesson ' . $lesson_id
              );
            }

            if ($refunded && is_array($lesson)) {
              $this->mrm_send_lesson_cancellation_refund_email(
                $lesson,
                (int)($first_order['amount_cents'] ?? 0)
              );
            }

            if (!$refunded && defined('WP_DEBUG') && WP_DEBUG) {
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
        if (empty($refund_guard['allowed'])) {
          $this->mrm_mark_auto_refund_skipped_for_order($order, (string)$refund_guard['reason'], (string)$refund_guard['message']);
          $refunded = false;
        } else {
          $refunded = $this->mrm_request_refund_for_order(
            $order,
            'Auto-refund for cancelled one-time lesson ' . $lesson_id
          );
        }

        if ($refunded && is_array($lesson)) {
          $this->mrm_send_lesson_cancellation_refund_email(
            $lesson,
            (int)($order['amount_cents'] ?? 0)
          );
        }

        if (!$refunded && defined('WP_DEBUG') && WP_DEBUG) {
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

  private function mrm_get_autopay_promo_discount($profile, $lesson, $amount_cents) {
  $profile = is_array($profile) ? $profile : array();
  $lesson = is_array($lesson) ? $lesson : array();

  $promo_code = $this->mrm_normalize_promo_code($profile['promo_code'] ?? '');

  if ($promo_code === '') {
    return array(
      'promo_code' => '',
      'discount_cents' => 0,
      'promo' => array(),
      'occurrence_number' => 0,
    );
  }

  $amount_cents = max(0, (int) $amount_cents);

  if ($amount_cents <= 0) {
    return array(
      'promo_code' => $promo_code,
      'discount_cents' => 0,
      'promo' => array(),
      'occurrence_number' => 0,
    );
  }

  $promo = $this->mrm_get_active_promo_code($promo_code);

  if (!$promo || !is_array($promo)) {
    return array(
      'promo_code' => $promo_code,
      'discount_cents' => 0,
      'promo' => array(),
      'occurrence_number' => 0,
    );
  }

  /*
   * Auto-pay profiles represent one continuing enrollment.
   * Do not reject future eligible auto-pay discounts merely because the same
   * email already redeemed the code on the first paid lesson.
   *
   * Eligibility is controlled by:
   * - the saved promo_code on the auto-pay profile,
   * - charged_lesson_count,
   * - promo_started_at,
   * - and the promo rule_mode / occurrence_count settings.
   */
  $occurrence_number = max(1, (int)($profile['charged_lesson_count'] ?? 0) + 2);

  $context = array(
    'lesson_count' => 1,
    'occurrence_number' => $occurrence_number,
    'promo_started_at' => (string)($profile['promo_started_at'] ?? ''),
    'autopay_profile_id' => (int)($profile['id'] ?? 0),
    'lesson_id' => (int)($lesson['id'] ?? 0),
  );

  $calc = $this->mrm_calculate_promo_discount_cents(
    $promo,
    $amount_cents,
    'lesson',
    $context
  );

  if (empty($calc['ok'])) {
    return array(
      'promo_code' => $promo_code,
      'discount_cents' => 0,
      'promo' => $promo,
      'occurrence_number' => $occurrence_number,
    );
  }

  return array(
    'promo_code' => $promo_code,
    'discount_cents' => max(0, (int)($calc['discount_cents'] ?? 0)),
    'promo' => $promo,
    'occurrence_number' => $occurrence_number,
  );
}

private function charge_and_unlock_autopay($data) {
    global $wpdb;

    $autopay_profile_id = (int)($data['autopay_profile_id'] ?? 0);
    $instructor_id = (int)($data['instructor_id'] ?? 0);
    $lesson_id = (int)($data['lesson_id'] ?? 0);
    $lessons_table = $this->table_lessons();


    if ($autopay_profile_id <= 0 || $instructor_id <= 0 || $lesson_id <= 0) {
      return;
    }

    $lesson = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$lessons_table} WHERE id=%d LIMIT 1",
      $lesson_id
    ), ARRAY_A);

    if (!$lesson) {
      return;
    }

    if ((string)($lesson['payment_mode'] ?? '') !== 'autopay') {
      return;
    }

    if ((string)($lesson['status'] ?? '') !== 'payment_due') {
      return;
    }

    if ((string)($lesson['charge_status'] ?? '') === 'paid') {
      return;
    }

    if ((int)($lesson['order_id'] ?? 0) > 0) {
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

      return;
    }

    $customer_id = (string)$validated_profile['customer_id'];
    $payment_method_id = (string)$validated_profile['payment_method_id'];
    $amount_cents = (int)$validated_profile['amount_cents'];
    $currency = (string)$validated_profile['currency'];
    $original_autopay_amount_cents = max(0, (int)$amount_cents);

    $autopay_promo = $this->mrm_get_autopay_promo_discount($profile, $lesson, $amount_cents);
    $autopay_promo_code = (string)($autopay_promo['promo_code'] ?? '');
    $autopay_promo_discount_cents = max(0, (int)($autopay_promo['discount_cents'] ?? 0));

    if ($autopay_promo_discount_cents > 0) {
      $amount_cents = max(50, $amount_cents - $autopay_promo_discount_cents);
    }

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
        'mrm_original_base_amount_cents' => (string)$original_autopay_amount_cents,
        'mrm_base_amount_cents' => (string)$amount_cents,
        'mrm_addon_amount_cents' => '0',
        'mrm_tax_cents' => '0',
        'mrm_addon_tax_cents' => '0',
        'mrm_promo_code' => $autopay_promo_code,
        'mrm_promo_discount_cents' => (string)$autopay_promo_discount_cents,
        'mrm_autopay_occurrence_number' => (string)((int)($autopay_promo['occurrence_number'] ?? 0)),
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
        'mrm_original_base_amount_cents' => (string)$original_autopay_amount_cents,
        'mrm_base_amount_cents' => (string)$amount_cents,
        'mrm_addon_amount_cents' => '0',
        'mrm_tax_cents' => '0',
        'mrm_addon_tax_cents' => '0',
        'mrm_promo_code' => $autopay_promo_code,
        'mrm_promo_discount_cents' => (string)$autopay_promo_discount_cents,
        'mrm_autopay_occurrence_number' => (string)((int)($autopay_promo['occurrence_number'] ?? 0)),
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

      if ($autopay_promo_code !== '' && $autopay_promo_discount_cents > 0 && $pi_id !== '') {
        $this->mrm_attach_payment_intent_to_promo_redemption($order_id, $pi_id);
      }


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

  private function mrm_payout_timezone() {
    try {
      return new DateTimeZone('America/Los_Angeles');
    } catch (Exception $e) {
      return $this->mrm_wp_tz();
    }
  }

  private function mrm_get_completed_payout_period_for_today() {
    $settings = $this->get_settings();
    $anchor = trim((string)($settings['payout_anchor_date'] ?? ''));

    if ($anchor === '') {
      return null;
    }

    try {
      $tz = $this->mrm_payout_timezone();
      $today = new DateTime('today', $tz);

      $period_start = new DateTime($anchor, $tz);
      $period_start->setTime(0, 0, 0);

      // Anchor must be a Saturday.
      if ((int)$period_start->format('N') !== 6) {
        return null;
      }

      while (true) {
        // Exclusive period end: the Saturday after the two-week period.
        // This means the paid earning window is Saturday 00:00:00 through Friday 23:59:59.
        $period_end_exclusive = clone $period_start;
        $period_end_exclusive->modify('+14 days');

        // Payout is the Wednesday after the two-week period closes.
        $payout_day = clone $period_end_exclusive;
        $payout_day->modify('+4 days');

        if ($today->format('Y-m-d') === $payout_day->format('Y-m-d')) {
          return array(
            'start_mysql' => $period_start->format('Y-m-d 00:00:00'),
            'end_mysql'   => $period_end_exclusive->format('Y-m-d 00:00:00'),
            'payout_date' => $payout_day->format('Y-m-d'),
          );
        }

        if ($payout_day > $today) {
          return null;
        }

        $period_start->modify('+14 days');
      }
    } catch (Exception $e) {
      return null;
    }
  }

  private function mrm_is_biweekly_payout_day() {
    $settings = $this->get_settings();
    $anchor = trim((string)($settings['payout_anchor_date'] ?? ''));

    if ($anchor === '') {
      return false;
    }

    try {
      $tz = $this->mrm_payout_timezone();
      $today = new DateTime('today', $tz);

      $period_start = new DateTime($anchor, $tz);
      $period_start->setTime(0, 0, 0);

      // Anchor must be a Saturday.
      if ((int)$period_start->format('N') !== 6) {
        return false;
      }

      if ($today < $period_start) {
        return false;
      }

      // Payout day is Wednesday.
      if ((int)$today->format('N') !== 3) {
        return false;
      }

      $cursor = clone $period_start;

      while (true) {
        $period_end_exclusive = clone $cursor;
        $period_end_exclusive->modify('+14 days');

        $payout_day = clone $period_end_exclusive;
        $payout_day->modify('+4 days');

        if ($today->format('Y-m-d') === $payout_day->format('Y-m-d')) {
          return true;
        }

        if ($payout_day > $today) {
          return false;
        }

        $cursor->modify('+14 days');
      }
    } catch (Exception $e) {
      return false;
    }
  }

  private function mrm_is_after_payout_cutoff_time($as_of_ts = null) {
    try {
      $tz = $this->mrm_payout_timezone();

      if ($as_of_ts !== null && (int)$as_of_ts > 0) {
        $now = new DateTime('@' . (int)$as_of_ts);
        $now->setTimezone($tz);
      } else {
        $now = new DateTime('now', $tz);
      }

      $cutoff = clone $now;
      $cutoff->setTime(9, 0, 0);

      return ($now >= $cutoff);
    } catch (Exception $e) {
      return false;
    }
  }

  private function mrm_is_biweekly_payout_window_open($as_of_ts = null) {
    return $this->mrm_is_biweekly_payout_day() && $this->mrm_is_after_payout_cutoff_time($as_of_ts);
  }

  private function mrm_next_daily_payout_check_timestamp() {
    $tz = $this->mrm_payout_timezone();
    $next = new DateTime('now', $tz);
    $next->setTime(9, 0, 0);

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
      $local_hour = (int)wp_date('G', $next, $this->mrm_payout_timezone());
      $local_minute = (int)wp_date('i', $next, $this->mrm_payout_timezone());

      if ($local_hour !== 9 || $local_minute !== 0) {
        $needs_reschedule = true;
      }
    }

    if ($needs_reschedule) {
      wp_clear_scheduled_hook($hook);
      wp_schedule_event($this->mrm_next_daily_payout_check_timestamp(), 'daily', $hook);
    }
  }

  private function mrm_schedule_daily_presenter_payout_check() {
    $hook = 'mrm_pay_hub_daily_presenter_payout_check';

    if (!wp_next_scheduled($hook)) {
      $tz = $this->mrm_payout_timezone();
      $next = new DateTime('now', $tz);
      $next->setTime(9, 0, 0);

      if ($next->getTimestamp() <= time()) {
        $next->modify('+1 day');
      }

      wp_schedule_event($next->getTimestamp(), 'daily', $hook);
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

  public function cron_daily_presenter_payout_check() {
    $this->mrm_run_presenter_payouts(array(), false);
  }

  public function mrm_run_payout_batch($force = false, $only_ids = array(), $only_payee_type = '') {
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

    global $wpdb;
    $table = $this->table_payout_ledger();
    $period = $force ? null : $this->mrm_get_completed_payout_period_for_today();

    $payable_statuses = $force
      ? "'pending','transferred','error','blocked'"
      : "'pending','transferred'";

    $where = "WHERE status IN ({$payable_statuses})
      AND connected_account_id IS NOT NULL
      AND connected_account_id <> ''";

    $args = array();

    $only_ids = array_values(array_filter(array_unique(array_map('absint', (array)$only_ids))));
    $only_payee_type = sanitize_key((string)$only_payee_type);

    if (!empty($only_ids)) {
      $where .= " AND id IN (" . implode(',', array_fill(0, count($only_ids), '%d')) . ")";
      foreach ($only_ids as $only_id) {
        $args[] = $only_id;
      }
    }

    if ($only_payee_type !== '') {
      $where .= " AND payee_type = %s";
      $args[] = $only_payee_type;
    }

    if (is_array($period)) {
      $where .= " AND created_at >= %s AND created_at < %s";
      $args[] = $period['start_mysql'];
      $args[] = $period['end_mysql'];
    }

    $sql = "SELECT * FROM {$table}
      {$where}
      ORDER BY connected_account_id ASC, id ASC";

    $rows = !empty($args)
      ? $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A)
      : $wpdb->get_results($sql, ARRAY_A);

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
    $owner_summary_items = array();

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

      $owner_item = $this->mrm_send_standard_payout_summary_for_rows(
        $payout_candidate_rows,
        $payout,
        $period,
        $batch_key
      );

      if (!empty($owner_item)) {
        $owner_summary_items[] = $owner_item;
      }
    }

    if (!empty($owner_summary_items)) {
      $this->mrm_send_owner_payout_batch_summary(
        $batch_key,
        $owner_summary_items,
        is_array($period) ? $this->mrm_payout_period_label_from_rows($rows, $period) : $this->mrm_payout_period_label_from_rows($rows, null)
      );
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

    register_rest_route('mrm-pay/v1', '/validate-promo', array(
      'methods' => WP_REST_Server::CREATABLE,
      'callback' => array($this, 'rest_validate_promo'),
      'permission_callback' => '__return_true',
    ));

    register_rest_route('mrm-pay/v1', '/tax-preview', array(
      'methods' => WP_REST_Server::CREATABLE,
      'callback' => array($this, 'rest_tax_preview'),
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



  public function rest_validate_promo(WP_REST_Request $req) {
    $data = (array)$req->get_json_params();

    $code = $this->mrm_normalize_promo_code($data['promo_code'] ?? '');
    $email = sanitize_email((string)($data['email'] ?? ''));
    $sku = $this->sanitize_sku($data['sku'] ?? '');
    $context = isset($data['context']) && is_array($data['context']) ? $data['context'] : array();

    if ($sku === '') {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Missing SKU.',
      ), 400);
    }

    $resolved_sku = $this->mrm_resolve_active_product_sku($sku, $context);
    if ($resolved_sku !== '') {
      $sku = $resolved_sku;
    }

    $product = $this->get_product($sku);
    if (!$product || empty($product['active'])) {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Unknown or inactive product.',
      ), 404);
    }

    $base_amount_cents = (int)($product['amount_cents'] ?? 0);

    $lesson_count = isset($context['lesson_count']) ? max(1, absint($context['lesson_count'])) : 1;
    $prepay = isset($context['prepay']) ? strtolower((string)$context['prepay']) : 'no';

    if ((string)($product['product_type'] ?? '') === 'lesson' && $prepay === 'yes' && $lesson_count > 1) {
      $base_amount_cents = $base_amount_cents * $lesson_count;
    }

    $result = $this->mrm_validate_promo_for_purchase(
      $code,
      $email,
      (string)($product['product_type'] ?? 'unknown'),
      $base_amount_cents,
      $context
    );

    if (empty($result['ok'])) {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => (string)($result['message'] ?? 'Invalid promotional code.'),
      ), 400);
    }

    $promo = isset($result['promo']) && is_array($result['promo']) ? $result['promo'] : array();

    $discount_type = (string)($promo['discount_type'] ?? 'percent');
    $percent_off = max(0, min(100, (int)($promo['percent_off'] ?? 0)));
    $amount_off_cents = max(0, (int)($promo['amount_off_cents'] ?? 0));
    $discount_cents = max(0, (int)($result['discount_cents'] ?? 0));

    if ($discount_type === 'amount') {
      $promo_success_message = 'Code applied: $' . number_format($discount_cents / 100, 2) . ' off.';
    } else {
      $promo_success_message = 'Code applied: ' . $percent_off . '% promotional discount applied.';
    }

    return new WP_REST_Response(array(
      'ok' => true,
      'message' => 'Promotional code applied.',
      'promo_code' => $code,
      'discount_cents' => $discount_cents,
      'discount_display' => '$' . number_format($discount_cents / 100, 2),
      'discount_type' => $discount_type,
      'percent_off' => $percent_off,
      'amount_off_cents' => $amount_off_cents,
      'promo_success_message' => $promo_success_message,
    ), 200);
  }

  public function rest_quote(WP_REST_Request $req) {
  $request_id = 'quote_' . gmdate('Ymd_His') . '_' . wp_generate_password(8, false, false);

  try {
    $raw_params = $req->get_params();

    $sku = $this->sanitize_sku($req->get_param('sku'));

    $context = array(
      'piece_slug' => sanitize_text_field((string)$req->get_param('piece_slug')),
      'type' => sanitize_text_field((string)$req->get_param('type')),
      'display_title' => sanitize_text_field((string)$req->get_param('display_title')),
      'subtitle' => sanitize_text_field((string)$req->get_param('subtitle')),
    );

    $this->mrm_quote_debug_log('quote_start', array(
      'request_id' => $request_id,
      'raw_params' => $raw_params,
      'sanitized_sku' => $sku,
      'context' => $context,
      'request_uri' => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field((string)$_SERVER['REQUEST_URI']) : '',
      'referer' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw((string)$_SERVER['HTTP_REFERER']) : '',
    ));

    if (!$sku) {
      $this->mrm_quote_debug_log('quote_fail_missing_sku', array(
        'request_id' => $request_id,
      ));

      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Some payment details were missing. Please refresh and try again.',
      ), 400);
    }

    $resolved_sku = $this->mrm_resolve_active_product_sku($sku, $context);

    $this->mrm_quote_debug_log('quote_after_resolver', array(
      'request_id' => $request_id,
      'incoming_sku' => $sku,
      'resolved_sku' => $resolved_sku,
    ));

    if ($resolved_sku !== '') {
      $sku = $resolved_sku;
    }

    $p = $this->get_product($sku);

    $this->mrm_quote_debug_log('quote_product_lookup', array(
      'request_id' => $request_id,
      'lookup_sku' => $sku,
      'product_exists' => is_array($p),
      'product' => $this->mrm_quote_debug_product_summary($p),
      'nearby_products' => is_array($p) ? array() : $this->mrm_quote_debug_nearby_product_keys($sku),
    ));

    if (!$p) {
      $this->mrm_quote_debug_log('quote_fail_unknown_sku', array(
        'request_id' => $request_id,
        'lookup_sku' => $sku,
        'original_requested_sku' => $this->sanitize_sku($req->get_param('sku')),
      ));

      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'This purchase option is not available. Please refresh and try again.',
        'requested_sku' => $this->sanitize_sku($req->get_param('sku')),
        'lookup_sku' => $sku,
      ), 404);
    }

    if (empty($p['active'])) {
      $this->mrm_quote_debug_log('quote_fail_inactive_sku', array(
        'request_id' => $request_id,
        'lookup_sku' => $sku,
        'product' => $this->mrm_quote_debug_product_summary($p),
      ));

      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'This purchase option is not currently available.',
        'lookup_sku' => $sku,
      ), 404);
    }

    $amount_cents = isset($p['amount_cents']) ? (int)$p['amount_cents'] : 0;
    $currency = strtolower((string)($p['currency'] ?? 'usd'));
    $price_id = isset($p['stripe_price_id']) ? trim((string)$p['stripe_price_id']) : '';
    $product_type = (string)($p['product_type'] ?? '');
    $product_category = (string)($p['category'] ?? '');

    $this->mrm_quote_debug_log('quote_product_config', array(
      'request_id' => $request_id,
      'sku' => $sku,
      'amount_cents' => $amount_cents,
      'currency' => $currency,
      'price_id_present' => $price_id !== '',
      'product_type' => $product_type,
      'product_category' => $product_category,
    ));

    $is_inperson_lesson =
      ($product_type === 'lesson') &&
      (
        strpos($product_category, 'inperson') !== false ||
        strpos($sku, 'inperson') !== false
      );

    $travel_amount_cents = $is_inperson_lesson ? $this->mrm_get_in_person_travel_cents() : 0;

    if ($travel_amount_cents > $amount_cents) {
      $travel_amount_cents = $amount_cents;
    }

    $base_amount_cents = max(0, $amount_cents - $travel_amount_cents);

    if ($amount_cents <= 0) {
      $this->mrm_quote_debug_log('quote_fail_pricing_not_configured', array(
        'request_id' => $request_id,
        'sku' => $sku,
        'amount_cents' => $amount_cents,
        'product' => $this->mrm_quote_debug_product_summary($p),
      ));

      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Pricing is temporarily unavailable for this purchase option.',
        'lookup_sku' => $sku,
        'amount_cents' => $amount_cents,
      ), 500);
    }

    if (!$currency) {
      $this->mrm_quote_debug_log('quote_fail_currency_not_configured', array(
        'request_id' => $request_id,
        'sku' => $sku,
        'currency' => $currency,
        'product' => $this->mrm_quote_debug_product_summary($p),
      ));

      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Payment settings are temporarily unavailable for this purchase option.',
        'lookup_sku' => $sku,
      ), 500);
    }

    $preview_address = array(
      'country' => strtoupper((string)($req->get_param('country') ?? 'US')),
      'state' => (string)($req->get_param('state') ?? ''),
      'postal_code' => (string)($req->get_param('postal_code') ?? ''),
      'line1' => (string)($req->get_param('line1') ?? ''),
    );

    $this->mrm_quote_debug_log('quote_before_tax_preview', array(
      'request_id' => $request_id,
      'sku' => $sku,
      'preview_address' => $preview_address,
    ));

    $preview_policy = $this->mrm_build_tax_policy(
      $preview_address,
      (string)($p['product_type'] ?? 'unknown'),
      false,
      $p,
      array(
        'allow_incomplete_address' => true,
        'source_flow' => 'quote_preview',
        'sku' => $sku,
      )
    );

    if ($preview_policy instanceof WP_REST_Response) {
      $this->mrm_quote_debug_log('quote_preview_policy_unexpected_response_object', array(
        'request_id' => $request_id,
        'sku' => $sku,
        'status' => $preview_policy->get_status(),
        'data' => $preview_policy->get_data(),
      ));

      $preview_policy = array(
        'ok' => true,
        'policy_reason' => 'quote_preview_policy_response_object_converted',
        'policy_message' => 'Sales tax is calculated after billing state and ZIP are entered.',
        'should_collect_tax' => false,
        'jurisdiction' => array(
          'country' => (string)($preview_address['country'] ?? 'US'),
          'state' => (string)($preview_address['state'] ?? ''),
        ),
      );
    }

    if (!is_array($preview_policy)) {
      $this->mrm_quote_debug_log('quote_preview_policy_unexpected_type', array(
        'request_id' => $request_id,
        'sku' => $sku,
        'preview_policy_type' => gettype($preview_policy),
      ));

      $preview_policy = array(
        'ok' => true,
        'policy_reason' => 'quote_preview_policy_unexpected_type',
        'policy_message' => 'Sales tax is calculated after billing state and ZIP are entered.',
        'should_collect_tax' => false,
        'jurisdiction' => array(
          'country' => (string)($preview_address['country'] ?? 'US'),
          'state' => (string)($preview_address['state'] ?? ''),
        ),
      );
    }

    $response = array(
      'ok' => true,
      'sku' => $sku,
      'label' => (string)($p['label'] ?? $sku),
      'amount_cents' => $amount_cents,
      'base_amount_cents' => $base_amount_cents,
      'travel_amount_cents' => $travel_amount_cents,
      'currency' => $currency,
      'tax_pending' => true,
      'tax_message' => (string)($preview_policy['policy_message'] ?? 'Sales tax is calculated after billing state and ZIP are entered.'),
      'tax_policy_preview' => array(
        'policy_reason' => (string)($preview_policy['policy_reason'] ?? ''),
        'should_collect_tax' => !empty($preview_policy['should_collect_tax']),
        'state' => (string)($preview_policy['jurisdiction']['state'] ?? ''),
        'country' => (string)($preview_policy['jurisdiction']['country'] ?? 'US'),
      ),
      'price_id' => $price_id ? $price_id : null,
    );

    $this->mrm_quote_debug_log('quote_success', array(
      'request_id' => $request_id,
      'response_summary' => array(
        'sku' => $response['sku'],
        'amount_cents' => $response['amount_cents'],
        'currency' => $response['currency'],
        'label' => $response['label'],
      ),
    ));

    return new WP_REST_Response($response, 200);

  } catch (Throwable $e) {
    $this->mrm_quote_debug_log('quote_exception', array(
      'request_id' => $request_id,
      'message' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine(),
      'trace' => $e->getTraceAsString(),
    ));

    return new WP_REST_Response(array(
      'ok' => false,
      'message' => 'We could not prepare this payment. Please refresh and try again.',
    ), 500);
  }
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
    if (!$sku) return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'This offer is temporarily unavailable. Please contact Low Brass Lessons for assistance.',
      ), 404);

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

  public function rest_tax_preview(WP_REST_Request $req) {
    $data = (array) $req->get_json_params();

    $sku = $this->sanitize_sku($data['sku'] ?? '');
    $email = sanitize_email((string)($data['email'] ?? ''));
    $context = isset($data['context']) && is_array($data['context']) ? $data['context'] : array();

    if (!$sku) {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'This offer is temporarily unavailable. Please contact Low Brass Lessons for assistance.',
      ), 400);
    }

    $resolved_sku = $this->mrm_resolve_active_product_sku($sku, $context);
    if ($resolved_sku !== '') {
      $sku = $resolved_sku;
    }

    $p = $this->get_product($sku);
    if (!$p || empty($p['active'])) {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'This offer is temporarily unavailable. Please contact Low Brass Lessons for assistance.',
      ), 404);
    }

    $product_type = (string)($p['product_type'] ?? 'unknown');
    $amount = (int)($p['amount_cents'] ?? 0);
    $currency = (string)($p['currency'] ?? 'usd');
    $base_amount = (int)$amount;

    if ($base_amount <= 0) {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Invalid product price.',
      ), 400);
    }

    $lesson_count = isset($context['lesson_count']) ? absint($context['lesson_count']) : 0;
    $prepay_flag = isset($context['prepay']) ? strtolower((string)$context['prepay']) : 'no';
    $override = isset($data['amount_override_cents']) ? absint($data['amount_override_cents']) : 0;

    if ($prepay_flag === 'yes' && $lesson_count > 1) {
      $expected = $base_amount * $lesson_count;

      if ($override > 0 && $override !== $expected) {
        return new WP_REST_Response(array(
          'ok' => false,
          'message' => 'Prepay total mismatch. Please refresh and try again.',
        ), 400);
      }

      $amount = $override > 0 ? $override : $expected;
    } elseif ($override > 0) {
      $max = $base_amount * 50;

      if ($override >= $base_amount && $override <= $max) {
        $amount = $override;
      } else {
        return new WP_REST_Response(array(
          'ok' => false,
          'message' => 'Invalid override amount.',
        ), 400);
      }
    }

    $addon_selected = (isset($data['sheet_music_addon']) && strtolower((string)$data['sheet_music_addon']) === 'yes');
    $address = (isset($data['address']) && is_array($data['address'])) ? $data['address'] : array();
    $address = $this->mrm_normalize_tax_address($address);

    $base_amount_cents = (int)$amount;
    $original_base_amount_cents = $base_amount_cents;
    $addon_amount_cents = $addon_selected ? 500 : 0;

    $promo_code = $this->mrm_normalize_promo_code($data['promo_code'] ?? '');
    $promo_discount_cents = 0;
    $promo_validation = null;

    if ($promo_code !== '') {
      if (!$email || !is_email($email)) {
        return new WP_REST_Response(array(
          'ok' => false,
          'code' => 'email_required_for_promo_tax_preview',
          'message' => 'Please enter a valid email address before previewing tax with this promotional code.',
        ), 400);
      }

      $promo_validation = $this->mrm_validate_promo_for_purchase(
        $promo_code,
        $email,
        $product_type,
        $base_amount_cents,
        $context
      );

      if (empty($promo_validation['ok'])) {
        return new WP_REST_Response(array(
          'ok' => false,
          'code' => !empty($promo_validation['code']) ? sanitize_key((string)$promo_validation['code']) : 'invalid_promo_code',
          'message' => (string)($promo_validation['message'] ?? 'Invalid promotional code.'),
        ), 400);
      }

      $promo_discount_cents = max(0, (int)($promo_validation['discount_cents'] ?? 0));
      $base_amount_cents = max(50, $base_amount_cents - $promo_discount_cents);
    }

    $tax_policy = $this->mrm_build_tax_policy($address, $product_type, $addon_selected, $p, $context);

    if ($tax_policy instanceof WP_REST_Response) {
      return $tax_policy;
    }

    if (is_array($tax_policy) && empty($tax_policy['ok']) && !empty($tax_policy['code'])) {
      return new WP_REST_Response(array(
        'ok' => false,
        'code' => (string)($tax_policy['code'] ?? 'tax_policy_failed'),
        'message' => (string)($tax_policy['message'] ?? 'Billing address is required before checkout.'),
        'missing_address_fields' => (array)($tax_policy['missing_address_fields'] ?? array()),
      ), 400);
    }

    if (!is_array($tax_policy)) {
      return new WP_REST_Response(array(
        'ok' => false,
        'code' => 'tax_policy_invalid_response',
        'message' => 'Unable to validate tax policy for this checkout.',
      ), 500);
    }

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
    }

    $tax_cents = (!empty($tax_result['ok'])) ? (int)($tax_result['tax_cents'] ?? 0) : 0;
    $tax_calc_id = (!empty($tax_result['ok'])) ? (string)($tax_result['calculation_id'] ?? '') : '';
    $tax_message = $this->mrm_build_tax_display_message($tax_policy, $tax_result, $tax_cents);
    $final_amount_cents = $base_amount_cents + $addon_amount_cents + $tax_cents;

    return new WP_REST_Response(array(
      'ok' => true,
      'sku' => $sku,
      'label' => (string)($p['label'] ?? $sku),
      'amount_cents' => $final_amount_cents,
      'total_cents' => $final_amount_cents,
      'original_base_amount_cents' => $original_base_amount_cents,
      'base_amount_cents' => $base_amount_cents,
      'addon_amount_cents' => $addon_amount_cents,
      'promo_code' => $promo_code,
      'promo_discount_cents' => $promo_discount_cents,
      'tax_cents' => $tax_cents,
      'tax_message' => (string)$tax_message,
      'tax_calculation_id' => $tax_calc_id,
      'tax_calculated' => true,
      'tax_ready' => true,
      'currency' => $currency,
      'product_type' => $product_type,
      'tax_policy' => array(
        'policy_reason' => (string)($tax_policy['policy_reason'] ?? ''),
        'should_collect_tax' => !empty($tax_policy['should_collect_tax']),
        'state' => (string)($tax_policy['jurisdiction']['state'] ?? ''),
        'country' => (string)($tax_policy['jurisdiction']['country'] ?? 'US'),
        'stripe_controls_rollout' => true,
      ),
    ), 200);
  }

  public function rest_create_payment_intent(WP_REST_Request $req) {
    $data = (array) $req->get_json_params();

    $sku = $this->sanitize_sku($data['sku'] ?? '');
    $email = sanitize_email((string)($data['email'] ?? ''));
    $context = isset($data['context']) && is_array($data['context']) ? $data['context'] : array();
    $terms_accepted = ! empty( $context['terms_accepted'] );
    $terms_version  = sanitize_text_field( (string) ( $context['terms_version'] ?? '' ) );
    $source_flow    = sanitize_text_field( (string) ( $context['source_flow'] ?? '' ) );

    if (!$sku) {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'This offer is temporarily unavailable. Please contact Low Brass Lessons for assistance.',
      ), 400);
    }

    if (!$email || !is_email($email)) {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Please enter a valid email address.',
      ), 400);
    }

    $resolved_sku = $this->mrm_resolve_active_product_sku($sku, $context);
    if ($resolved_sku !== '') {
      $sku = $resolved_sku;
    }

    $p = $this->get_product($sku);
    if (!$p || empty($p['active'])) {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'This offer is temporarily unavailable. Please contact Low Brass Lessons for assistance.',
      ), 404);
    }

    $product_type = (string)($p['product_type'] ?? 'unknown');

    if (in_array($product_type, array('lesson', 'sheet_music'), true) && !$terms_accepted) {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Please agree to the Terms of Service before checkout.',
        'code' => 'terms_required',
      ), 400);
    }

    if ($terms_version === '') {
      $terms_version = '2026-04-25';
    }

    if ($source_flow === '') {
      $source_flow = ($product_type === 'sheet_music') ? 'piece_product_purchase' : 'lesson_booking';
    }

    if ($product_type === 'sheet_music') {
      if ($this->mrm_email_already_has_piece_or_package_access($email, $sku)) {
        return new WP_REST_Response(array(
          'ok' => false,
          'code' => 'already_purchased_piece_product',
          'message' => 'This email already has access to this piece product. You do not need to purchase it again.',
          'already_owned' => true,
        ), 409);
      }
    }

    $amount = (int)($p['amount_cents'] ?? 0);
    $currency = (string)($p['currency'] ?? 'usd');

    $base_amount = (int)$amount;

    // Diagnostics for misconfigured products
    if ($base_amount <= 0) {
      return new WP_REST_Response(array(
        'ok'=>false,
        'message'=>'Invalid product price.',
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
        return new WP_REST_Response(array(
          'ok'=>false,
          'message'=>'Prepay total mismatch. Please refresh and try again.',
            'sku'=>$sku,
            'base_amount_cents'=>$base_amount,
            'lesson_count'=>$lesson_count,
            'expected_amount_cents'=>$expected,
            'amount_override_cents'=>$override
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
          return new WP_REST_Response(array(
            'ok'=>false,
            'message'=>'Invalid override amount.',
          ), 400);
        }
      }
    }

    $addon_selected = (isset($data['sheet_music_addon']) && strtolower((string)$data['sheet_music_addon']) === 'yes');
    $address = (isset($data['address']) && is_array($data['address'])) ? $data['address'] : array();
    $address = $this->mrm_normalize_tax_address($address);

    $base_amount_cents = (int)$amount;
    $original_base_amount_cents = $base_amount_cents;
    $addon_amount_cents = $addon_selected ? 500 : 0;

    $promo_code = $this->mrm_normalize_promo_code($data['promo_code'] ?? '');
    $promo_discount_cents = 0;
    $promo_validation = null;

    if ($promo_code !== '') {
      $promo_validation = $this->mrm_validate_promo_for_purchase(
        $promo_code,
        $email,
        $product_type,
        $base_amount_cents,
        $context
      );

      if (empty($promo_validation['ok'])) {
        $promo_error_code = !empty($promo_validation['code'])
          ? sanitize_key((string)$promo_validation['code'])
          : 'invalid_promo_code';

        return new WP_REST_Response(array(
          'ok' => false,
          'code' => $promo_error_code,
          'message' => (string)($promo_validation['message'] ?? 'Invalid promotional code.'),
        ), 400);
      }

      $promo_discount_cents = max(0, (int)($promo_validation['discount_cents'] ?? 0));
      $base_amount_cents = max(50, $base_amount_cents - $promo_discount_cents);
    }

    $tax_policy = $this->mrm_build_tax_policy($address, $product_type, $addon_selected, $p, $context);

    if (is_array($tax_policy) && empty($tax_policy['ok']) && !empty($tax_policy['code'])) {
      return new WP_REST_Response(array(
        'ok' => false,
        'code' => (string)($tax_policy['code'] ?? 'tax_policy_failed'),
        'message' => (string)($tax_policy['message'] ?? 'Billing address is required before checkout.'),
        'missing_address_fields' => (array)($tax_policy['missing_address_fields'] ?? array()),
      ), 400);
    }

    if ($tax_policy instanceof WP_REST_Response) {
      return $tax_policy;
    }

    if (!is_array($tax_policy)) {
      return new WP_REST_Response(array(
        'ok' => false,
        'code' => 'tax_policy_invalid_response',
        'message' => 'Unable to validate tax policy for this checkout.',
      ), 500);
    }

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
      return new WP_REST_Response(array('ok'=>false,'message'=>'Invalid product price.'), 400);
    }

    $email_hash = $this->email_hash($email);

    // Build labels
    $metadata = $this->build_metadata($sku, $product_type, $email_hash, $context, $p);
    $metadata['mrm_customer_email'] = $email;
    if ( $terms_version !== '' ) {
      $metadata['mrm_terms_version'] = $terms_version;
    }
    if ( $terms_accepted ) {
      $metadata['mrm_terms_accepted'] = 'yes';
    }
    if ( $source_flow !== '' ) {
      $metadata['mrm_terms_source_flow'] = $source_flow;
    }
    $metadata['mrm_sheet_music_addon'] = $addon_selected ? 'yes' : 'no';
    $metadata['mrm_original_base_amount_cents'] = (string)$original_base_amount_cents;
    $metadata['mrm_base_amount_cents'] = (string)$base_amount_cents;
    $metadata['mrm_addon_amount_cents'] = (string)$addon_amount_cents;
    if ($promo_code !== '') {
      $metadata['mrm_promo_code'] = $promo_code;
      $metadata['mrm_promo_discount_cents'] = (string)$promo_discount_cents;
    }
    // Total tax for this order (covers sheet music base + subscription add-on, depending on product).
    $metadata['mrm_tax_cents'] = (string)$tax_cents;
    $metadata['mrm_tax_calculation_id'] = (string)$tax_calc_id;
    // Back-compat key (historically used for the $5 add-on tax only).
    $metadata['mrm_addon_tax_cents'] = (string)$tax_cents;

    $metadata['mrm_tax_country'] = (string)($address['country'] ?? 'US');
    $metadata['mrm_tax_state'] = (string)($address['state'] ?? '');
    $metadata['mrm_tax_postal_code'] = (string)($address['postal_code'] ?? '');
    $metadata['mrm_tax_line1'] = (string)($address['line1'] ?? '');
      $metadata['mrm_tax_city'] = (string)($address['city'] ?? '');
    $metadata['mrm_tax_rollout_mode'] = 'stripe_only';
    $metadata['mrm_tax_calculation_requested'] = !empty($tax_policy['should_collect_tax']) ? 'yes' : 'no';
    $metadata['mrm_tax_policy_reason'] = (string)($tax_policy['policy_reason'] ?? '');
    $metadata['mrm_tax_policy_message'] = (string)$tax_message;
    $metadata['mrm_taxability_reason'] = (string)($tax_result['taxability_reason'] ?? '');

    // Early duplicate guard:
    // If this is a lesson checkout with the $5 sheet music add-on selected,
    // block checkout before creating the order / PaymentIntent when the email
    // already has an active Stripe-synced sheet music subscription.
    $resumable_subscription_for_checkout = array();
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

      $resumable_subscription_for_checkout = $this->mrm_get_resumable_sheet_music_subscription_by_email($email);

      if (!empty($resumable_subscription_for_checkout['subscription_id'])) {
        $metadata['mrm_sheet_music_subscription_mode'] = 'resume';
        $metadata['mrm_prior_subscription_id'] = (string)$resumable_subscription_for_checkout['subscription_id'];
      }
    }

    // Create internal order first so order_id can be labeled in Stripe metadata
    $order_id = $this->create_order($email_hash, $sku, $product_type, $final_amount_cents, $currency, $metadata);
    $metadata['mrm_order_id'] = (string)$order_id;
    if ($promo_code !== '' && $promo_discount_cents > 0) {
      $reserved = $this->mrm_reserve_promo_redemption(
  $promo_code,
  $email_hash,
  $order_id,
  '',
  $email,
  is_array($promo_validation) && !empty($promo_validation['promo']) ? $promo_validation['promo'] : array()
);
      if (!$reserved) {
        return new WP_REST_Response(array(
          'ok' => false,
          'code' => 'promo_code_already_used',
          'message' => 'This email has already used this promotional code.',
        ), 409);
      }
    }

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

    /*
     * Do not ask Stripe to send its own customer receipt email.
     *
     * Low Brass Lessons sends the canonical branded receipt through
     * mrm_maybe_send_purchase_receipt_email(), with the subject:
     * "Purchase Confirmation - [Item]".
     *
     * Leaving receipt_email enabled can cause customers to receive a second
     * Stripe-generated confirmation/receipt email in addition to the branded
     * Purchase Confirmation email.
     */

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

    $publishable_key = $this->publishable_key();
    if ($publishable_key === '') {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Stripe publishable key is not configured.',
      ), 500);
    }

    $pi = $this->stripe_create_payment_intent($final_amount_cents, $currency, $metadata, $description, $extra, $payment_method_types);
    if (is_wp_error($pi)) {
      $data = $pi->get_error_data();
      return new WP_REST_Response(array('ok'=>false,'message'=>$pi->get_error_message()), 500);
    }

    $this->attach_payment_intent_to_order($order_id, (string)$pi['id'], (string)($pi['status'] ?? ''));
    if ($promo_code !== '' && $promo_discount_cents > 0) {
      $this->mrm_attach_payment_intent_to_promo_redemption($order_id, (string)$pi['id']);
    }

    return new WP_REST_Response(array(
      'ok' => true,
      'publishableKey' => $publishable_key,
      'client_secret' => (string)($pi['client_secret'] ?? ''),
      'payment_intent_id' => (string)$pi['id'],
      'allowed_payment_methods' => array_values($payment_method_types),
      'order_id' => (int)$order_id,
      'customer_id' => $customer_id ? (string)$customer_id : '',
      'sku' => $sku,
      'label' => (string)($p['label'] ?? $sku),
      'amount_cents' => $final_amount_cents,
      'total_cents' => $final_amount_cents,
      'original_base_amount_cents' => $original_base_amount_cents,
      'base_amount_cents' => $base_amount_cents,
      'addon_amount_cents' => $addon_amount_cents,
      'promo_code' => $promo_code,
      'promo_discount_cents' => $promo_discount_cents,
      'promo_discount_type' => is_array($promo_validation) && !empty($promo_validation['promo']['discount_type'])
        ? (string)$promo_validation['promo']['discount_type']
        : '',
      'promo_percent_off' => is_array($promo_validation) && isset($promo_validation['promo']['percent_off'])
        ? (int)$promo_validation['promo']['percent_off']
        : 0,
      'promo_amount_off_cents' => is_array($promo_validation) && isset($promo_validation['promo']['amount_off_cents'])
        ? (int)$promo_validation['promo']['amount_off_cents']
        : 0,
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
      'currency' => $currency,
      'product_type' => $product_type,
      'category' => (string)($p['category'] ?? ''),
      'sheet_music_resuming_subscription' => (
        !empty($resumable_subscription_for_checkout['subscription_id'])
          ? array(
            'is_resuming' => true,
            'subscription_id' => (string)$resumable_subscription_for_checkout['subscription_id'],
            'current_period_end' => !empty($resumable_subscription_for_checkout['current_period_end']) ? (int)$resumable_subscription_for_checkout['current_period_end'] : 0,
            'current_period_end_display' => (
              !empty($resumable_subscription_for_checkout['current_period_end'])
                ? wp_date(get_option('date_format'), (int)$resumable_subscription_for_checkout['current_period_end'])
                : ''
            ),
          )
          : array('is_resuming' => false)
      ),
      // Public response intentionally excludes raw Stripe/order metadata.
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

    $promo_code = $this->mrm_normalize_promo_code($data['promo_code'] ?? ($context['promo_code'] ?? ''));
    $promo_started_at = $promo_code !== '' ? current_time('mysql') : null;

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

    /*
     * This legacy setup-intent route creates a new autopay profile before a setup
     * intent exists. The active frontend autopay flow uses
     * rest_create_autopay_enrollment(), which performs idempotency checks from the
     * successful first-payment PaymentIntent.
     *
     * Do not reference $payment_intent_id or $payment_method_id here; those values
     * are not available in this legacy setup-intent route.
     */

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
  'promo_code' => $promo_code !== '' ? $promo_code : null,
  'promo_started_at' => $promo_started_at,
  'active' => 1,
  'detached_at' => null,
  'created_at' => $now,
  'updated_at' => $now,
), array(
  '%d',
  '%s',
  '%s',
  '%s',
  '%s',
  '%d',
  '%s',
  '%d',
  '%d',
  '%s',
  '%s',
  '%d',
  '%s',
  '%s',
  '%s',
));

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

    $publishable_key = $this->publishable_key();
    if ($publishable_key === '') {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Stripe publishable key is not configured.',
      ), 500);
    }

    return new WP_REST_Response(array(
      'ok' => true,
      'publishableKey' => $publishable_key,
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

    $promo_code = $this->mrm_normalize_promo_code($data['promo_code'] ?? ($context['promo_code'] ?? ''));
    $promo_started_at = $promo_code !== '' ? current_time('mysql') : null;

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

if ($promo_code === '' && !empty($pi['metadata']['mrm_promo_code'])) {
  $promo_code = $this->mrm_normalize_promo_code($pi['metadata']['mrm_promo_code']);
  $promo_started_at = $promo_code !== '' ? current_time('mysql') : null;
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

    /*
     * Idempotency guard: do not create multiple autopay profiles for the same
     * successful first-payment order if the frontend retries or refreshes.
     */
    $existing_order_for_autopay = $this->get_order_by_pi($payment_intent_id);
    if ($existing_order_for_autopay && !empty($existing_order_for_autopay['metadata_json'])) {
      $existing_meta = json_decode((string)$existing_order_for_autopay['metadata_json'], true);
      if (is_array($existing_meta) && !empty($existing_meta['mrm_autopay_profile_id'])) {
        $existing_profile_id = absint($existing_meta['mrm_autopay_profile_id']);
        if ($existing_profile_id > 0) {
          return new WP_REST_Response(array(
            'ok' => true,
            'autopay_profile_id' => $existing_profile_id,
            'customer_id' => $customer_id,
            'payment_method_id' => $payment_method_id,
            'plan_kind' => ($repeat_duration === 'indefinitely') ? 'indefinite' : 'bounded',
            'authorized_lesson_count' => (int)$authorized_lesson_count,
            'already_created' => true,
          ), 200);
        }
      }
    }

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
      'promo_code' => $promo_code !== '' ? $promo_code : null,
      'promo_started_at' => $promo_started_at,
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
    ), array(
  '%d',
  '%s',
  '%s',
  '%s',
  '%s',
  '%d',
  '%s',
  '%d',
  '%d',
  '%s',
  '%s',
  '%s',
  '%s',
  '%d',
  '%d',
  '%s',
  '%s',
  '%s',
  '%s',
  '%d',
  '%s',
  '%s',
  '%s',
));

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

    if ($tax_policy instanceof WP_REST_Response) {
      return $tax_policy;
    }

    if (is_array($tax_policy) && empty($tax_policy['ok']) && !empty($tax_policy['code'])) {
      return new WP_REST_Response(array(
        'ok' => false,
        'code' => (string)($tax_policy['code'] ?? 'tax_policy_failed'),
        'message' => (string)($tax_policy['message'] ?? 'Billing address is required before checkout.'),
        'missing_address_fields' => (array)($tax_policy['missing_address_fields'] ?? array()),
      ), 400);
    }

    if (!is_array($tax_policy)) {
      return new WP_REST_Response(array(
        'ok' => false,
        'code' => 'tax_policy_invalid_response',
        'message' => 'Unable to validate tax policy for this checkout.',
      ), 500);
    }

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
      'original_base_amount_cents' => (int)($meta['mrm_original_base_amount_cents'] ?? $base_amount_cents),
      'promo_discount_cents' => (int)($meta['mrm_promo_discount_cents'] ?? 0),
      'amount_cents' => (int)$new_total_cents,
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

        $pm_ready = $this->mrm_ensure_customer_payment_method_ready($customer_id, $pm_id);
        if (is_wp_error($pm_ready)) {
        } else {
        }
      } else {
        // If user expected autopay but no customer/pm is present, log it loudly
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

    // Do not send the purchase receipt from the verify endpoint.
    // The Stripe payment_intent.succeeded webhook is the sole sender
    // for lesson purchase confirmation emails.
    if ($ok && $order) {
      $order = $this->get_order_by_pi($pi_id);
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
        $order_id = (int)($order['id'] ?? 0);
        $order_meta = $this->mrm_get_order_meta_array($order);

        $existing_subscription_id = (string)($order_meta['mrm_sheet_music_subscription_id'] ?? '');
        $existing_created_at = (string)($order_meta['mrm_sheet_music_subscription_created_at'] ?? '');
        $email = sanitize_email((string)($order_meta['mrm_customer_email'] ?? ($pi['metadata']['mrm_customer_email'] ?? '')));

        $existing_active = array();
        if ($email && is_email($email)) {
          $existing_active = $this->mrm_get_active_sheet_music_subscription_by_email($email);
        }

        $this->mrm_subscription_debug_log('verify endpoint guarded activation check', array(
          'order_id' => $order_id,
          'payment_intent_id' => (string)($pi['id'] ?? ''),
          'existing_subscription_id' => $existing_subscription_id,
          'existing_created_at' => $existing_created_at,
          'existing_active_found' => (!empty($existing_active['id']) ? 'yes' : 'no'),
          'context' => 'verify_endpoint',
        ));

        if ($existing_subscription_id === '' && empty($existing_active['id'])) {
          $this->mrm_subscription_debug_log('verify endpoint invoking guarded fallback activation', array(
            'order_id' => $order_id,
            'payment_intent_id' => (string)($pi['id'] ?? ''),
            'context' => 'verify_endpoint',
          ));

          $this->mrm_attempt_sheet_music_subscription_activation($order_id, $pi, 'verify_endpoint_fallback');
        } else {
          $this->mrm_subscription_debug_log('verify endpoint skipped fallback activation because subscription already exists or is already active', array(
            'order_id' => $order_id,
            'payment_intent_id' => (string)($pi['id'] ?? ''),
            'existing_subscription_id' => $existing_subscription_id,
            'existing_active_subscription_id' => (string)($existing_active['stripe_subscription_id'] ?? ''),
            'context' => 'verify_endpoint',
          ));
        }
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
    return $this->mrm_email_wrap_html($title, $intro_html, $details_html, $button_url, $button_text);
  }

  public function rest_grant_sheet_music_access(WP_REST_Request $req) {
    $data = (array) $req->get_json_params();

    // Accept sku or product_slug (canonical is product_slug)
    $incoming_slug = isset($data['product_slug']) ? $data['product_slug'] : ($data['sku'] ?? '');
    $sku   = $this->sanitize_sku($incoming_slug);

    $context = isset($data['context']) && is_array($data['context']) ? $data['context'] : array();

    $resolved_sku = $this->mrm_resolve_active_product_sku($sku, $context);
    if ($resolved_sku !== '') {
      $sku = $resolved_sku;
    }

    $email = sanitize_email((string)($data['email'] ?? ''));
    $pi_id = sanitize_text_field((string)($data['payment_intent_id'] ?? ''));

    $is_admin_manual_grant = current_user_can('manage_options');

    /*
     * Public access grants must always be backed by a succeeded Stripe PaymentIntent.
     * Admin/manual grants are still allowed for logged-in administrators only.
     */
    if (!$pi_id && !$is_admin_manual_grant) {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'We could not confirm payment for this purchase. Please contact Low Brass Lessons for assistance.',
      ), 403);
    }

    if ($pi_id) {
      $pi = $this->stripe_retrieve_payment_intent($pi_id);
      if (is_wp_error($pi)) {
        return new WP_REST_Response(array(
          'ok' => false,
          'message' => 'We could not confirm payment for this purchase. Please contact Low Brass Lessons for assistance.',
        ), 500);
      }

      $status = (string)($pi['status'] ?? '');
      if ($status !== 'succeeded') {
        return new WP_REST_Response(array(
          'ok' => false,
          'message' => 'Payment has not been completed yet.',
        ), 400);
      }

      $meta = isset($pi['metadata']) && is_array($pi['metadata']) ? $pi['metadata'] : array();

      $pi_sku = $this->sanitize_sku((string)($meta['mrm_sku'] ?? ''));
      $pi_email = sanitize_email((string)($meta['mrm_customer_email'] ?? ''));

      if ($pi_sku) {
        $sku = $pi_sku;
      }

      if ($pi_email && is_email($pi_email)) {
        $email = $pi_email;
      }
    }

    if (!$sku) {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'This offer is temporarily unavailable. Please contact Low Brass Lessons for assistance.',
      ), 400);
    }

    if (!$email || !is_email($email)) {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Please enter a valid email address.',
      ), 400);
    }

    $p = $this->get_product($sku);
    if (!$p || empty($p['active'])) {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'This offer is temporarily unavailable. Please contact Low Brass Lessons for assistance.',
      ), 404);
    }

    $product_type = (string)($p['product_type'] ?? 'unknown');
    if ($product_type !== 'sheet_music') {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'This purchase could not be completed for the selected item.',
      ), 400);
    }

    // DB guard (updates can skip activation hook)
    $this->maybe_install_or_upgrade_db();

    // Verify access table exists
    global $wpdb;
    $access_table = $this->table_sheet_music_access();
    $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $access_table));
    if ($found !== $access_table) {
      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Server is updating. Please try again in a moment.',
      ), 500);
    }

    $email_hash = $this->email_hash($email);

    $ok = $this->grant_sheet_music_access($email_hash, $email, $sku, $pi_id ? 'stripe_pi' : 'manual', $pi_id);
    if (!$ok) {
      $last = $wpdb->last_error ? $wpdb->last_error : 'unknown_db_error';

      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Failed to grant access.',
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
      return new WP_REST_Response(array('ok' => false, 'message' => 'Some payment details were missing. Please refresh and try again.'), 400);
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
   * Marketing Email Lists
   * ======================================================= */

  private function mrm_marketing_default_lists() {
  return array(
    'all_sheet_music_purchasers' => array(
      'label' => 'All Sheet Music Purchasers',
      'type' => 'dynamic',
      'desc' => 'Paid one-time sheet music purchasers from the orders table.',
      'example' => '',
    ),
    'sheet_music_subscribers' => array(
      'label' => 'Sheet Music Subscribers',
      'type' => 'dynamic',
      'desc' => 'Emails from the sheet music subscription table.',
      'example' => '',
    ),
    'all_lesson_students' => array(
      'label' => 'All Lesson Purchasers / Students',
      'type' => 'dynamic',
      'desc' => 'Anyone with a lesson record in the scheduler table.',
      'example' => '',
    ),
    'active_lesson_students' => array(
      'label' => 'Active Lesson Students',
      'type' => 'dynamic',
      'desc' => 'Students with at least one upcoming lesson.',
      'example' => '',
    ),
    'past_lesson_students' => array(
      'label' => 'Past Lesson Students With No Upcoming Lessons',
      'type' => 'dynamic',
      'desc' => 'Students who have booked before but have no upcoming lesson.',
      'example' => '',
    ),
    'band_directors' => array(
      'label' => 'Band Directors',
      'type' => 'manual',
      'desc' => 'Manual list for band directors, school contacts, and program leads.',
      'example' => "director@example.edu
assistant.director@example.edu
programlead@example.org",
    ),
    'general_interest' => array(
      'label' => 'General Interest',
      'type' => 'manual',
      'desc' => 'Manual list for general updates.',
      'example' => "parent@example.com
studentfamily@example.com
community@example.org",
    ),
    'low_brass_plus_interest' => array(
      'label' => 'Low Brass Plus Interest',
      'type' => 'manual',
      'desc' => 'Manual list for Low Brass Plus offers and subscription updates.',
      'example' => "plus.interest@example.com
sheetmusicfan@example.com
subscriberlead@example.org",
    ),
    'prospective_instructors' => array(
      'label' => 'Prospective Instructors',
      'type' => 'manual',
      'desc' => 'Manual list for potential future instructor recruiting.',
      'example' => "teacher@example.com
tubainstructor@example.com
trombonist@example.org",
    ),
    'concert_event_interest' => array(
      'label' => 'Concert / Event Interest',
      'type' => 'manual',
      'desc' => 'Manual list for events, clinics, concerts, and announcements.',
      'example' => "concertgoer@example.com
clinicfamily@example.com
events@example.org",
    ),
    'school_programs_clinics' => array(
      'label' => 'School Programs / Clinics',
      'type' => 'manual',
      'desc' => 'Manual list for clinics, masterclasses, and school-program outreach.',
      'example' => "schoolprogram@example.edu
musicdepartment@example.edu
cliniccontact@example.org",
    ),
  );
}

  private function mrm_marketing_manual_lists() {
    $lists = $this->get_email_lists();
    $defs = $this->mrm_marketing_default_lists();

    foreach ($defs as $key => $def) {
      if (($def['type'] ?? '') === 'manual' && !isset($lists[$key])) {
        $lists[$key] = array();
      }
    }

    foreach ($lists as $key => $emails) {
      $normalized = array();
      foreach ((array)$emails as $email) {
        $email = strtolower(sanitize_email((string)$email));
        if ($email && is_email($email)) $normalized[$email] = true;
      }
      $lists[$key] = array_keys($normalized);
      sort($lists[$key]);
    }

    return $lists;
  }

  private function mrm_marketing_unsubscribed_emails() {
    $emails = get_option('mrm_pay_hub_marketing_unsubscribed', array());
    $out = array();
    foreach ((array)$emails as $email) {
      $email = strtolower(sanitize_email((string)$email));
      if ($email && is_email($email)) $out[$email] = true;
    }
    return $out;
  }

  private function mrm_marketing_save_unsubscribed_emails($emails) {
    $out = array();
    foreach ((array)$emails as $email) {
      $email = strtolower(sanitize_email((string)$email));
      if ($email && is_email($email)) $out[$email] = true;
    }
    $final = array_keys($out);
    sort($final);
    update_option('mrm_pay_hub_marketing_unsubscribed', $final, false);
  }

  private function mrm_marketing_add_unsubscribe($email) {
    $email = strtolower(sanitize_email((string)$email));
    if (!$email || !is_email($email)) return false;

    $unsubscribed = $this->mrm_marketing_unsubscribed_emails();
    $unsubscribed[$email] = true;
    $this->mrm_marketing_save_unsubscribed_emails(array_keys($unsubscribed));

    $lists = $this->mrm_marketing_manual_lists();
    foreach ($lists as $key => $emails) {
      $lists[$key] = array_values(array_diff((array)$emails, array($email)));
    }
    $this->save_email_lists($lists);
    return true;
  }

  private function mrm_marketing_normalize_emails_from_text($raw) {
    $parts = preg_split('/[\s,;]+/', (string)$raw);
    $out = array();
    foreach ((array)$parts as $part) {
      $email = strtolower(sanitize_email(trim((string)$part)));
      if ($email && is_email($email)) $out[$email] = true;
    }
    $emails = array_keys($out);
    sort($emails);
    return $emails;
  }

  private function mrm_marketing_table_exists($table) {
    global $wpdb;
    $table = (string)$table;
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
  }

  private function mrm_marketing_extract_customer_email_from_order_row($row) {
    $meta = array();
    if (!empty($row['metadata_json'])) {
      $decoded = json_decode((string)$row['metadata_json'], true);
      if (is_array($decoded)) $meta = $decoded;
    }
    $email = strtolower(sanitize_email((string)($meta['mrm_customer_email'] ?? '')));
    return ($email && is_email($email)) ? $email : '';
  }

  private function mrm_marketing_paid_order_emails_by_type($product_type) {
    global $wpdb;
    $orders = $this->table_orders();
    if (!$this->mrm_marketing_table_exists($orders)) return array();

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT metadata_json FROM {$orders}
         WHERE product_type = %s
           AND status IN ('paid','completed','succeeded')
         ORDER BY created_at DESC
         LIMIT 5000",
        sanitize_text_field((string)$product_type)
      ),
      ARRAY_A
    );

    $out = array();
    foreach ((array)$rows as $row) {
      $email = $this->mrm_marketing_extract_customer_email_from_order_row($row);
      if ($email) $out[$email] = true;
    }
    $emails = array_keys($out);
    sort($emails);
    return $emails;
  }

  private function mrm_marketing_sheet_music_subscriber_emails() {
    global $wpdb;
    $subs = $this->table_sheet_music_subscriptions();
    if (!$this->mrm_marketing_table_exists($subs)) return array();

    $rows = $wpdb->get_col(
      "SELECT DISTINCT email_plain FROM {$subs}
       WHERE email_plain IS NOT NULL
         AND email_plain <> ''
         AND stripe_status IN ('active','trialing','past_due')
       ORDER BY email_plain ASC
       LIMIT 5000"
    );

    $out = array();
    foreach ((array)$rows as $email) {
      $email = strtolower(sanitize_email((string)$email));
      if ($email && is_email($email)) $out[$email] = true;
    }
    $emails = array_keys($out);
    sort($emails);
    return $emails;
  }

  private function mrm_marketing_lesson_student_emails($mode = 'all') {
    global $wpdb;
    $lessons = $this->table_lessons();
    if (!$this->mrm_marketing_table_exists($lessons)) return array();

    $now = current_time('mysql');
    $active_rows = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT DISTINCT student_email FROM {$lessons}
         WHERE student_email IS NOT NULL
           AND student_email <> ''
           AND start_time >= %s
           AND status NOT IN ('cancelled','refunded','failed')
         ORDER BY student_email ASC
         LIMIT 5000",
        $now
      )
    );

    $active = array();
    foreach ((array)$active_rows as $email) {
      $email = strtolower(sanitize_email((string)$email));
      if ($email && is_email($email)) $active[$email] = true;
    }

    if ($mode === 'active') {
      $emails = array_keys($active);
      sort($emails);
      return $emails;
    }

    $all_rows = $wpdb->get_col(
      "SELECT DISTINCT student_email FROM {$lessons}
       WHERE student_email IS NOT NULL
         AND student_email <> ''
         AND status NOT IN ('cancelled','refunded','failed')
       ORDER BY student_email ASC
       LIMIT 5000"
    );

    $all = array();
    foreach ((array)$all_rows as $email) {
      $email = strtolower(sanitize_email((string)$email));
      if ($email && is_email($email)) $all[$email] = true;
    }

    if ($mode === 'past') {
      foreach (array_keys($active) as $email) unset($all[$email]);
    }

    $emails = array_keys($all);
    sort($emails);
    return $emails;
  }

  private function mrm_marketing_get_list_recipients($list_key, $apply_suppression = true) {
    $defs = $this->mrm_marketing_default_lists();
    $list_key = sanitize_key((string)$list_key);
    if (!isset($defs[$list_key])) return array();

    switch ($list_key) {
      case 'all_sheet_music_purchasers':
        $emails = $this->mrm_marketing_paid_order_emails_by_type('sheet_music');
        break;
      case 'sheet_music_subscribers':
        $emails = $this->mrm_marketing_sheet_music_subscriber_emails();
        break;
      case 'all_lesson_students':
        $emails = $this->mrm_marketing_lesson_student_emails('all');
        break;
      case 'active_lesson_students':
        $emails = $this->mrm_marketing_lesson_student_emails('active');
        break;
      case 'past_lesson_students':
        $emails = $this->mrm_marketing_lesson_student_emails('past');
        break;
      default:
        $manual = $this->mrm_marketing_manual_lists();
        $emails = isset($manual[$list_key]) ? (array)$manual[$list_key] : array();
        break;
    }

    $out = array();
    foreach ((array)$emails as $email) {
      $email = strtolower(sanitize_email((string)$email));
      if ($email && is_email($email)) $out[$email] = true;
    }

    if ($apply_suppression) {
      $unsubscribed = $this->mrm_marketing_unsubscribed_emails();
      foreach (array_keys($unsubscribed) as $email) unset($out[$email]);
    }

    $final = array_keys($out);
    sort($final);
    return $final;
  }

  private function mrm_marketing_get_combined_recipients($list_keys) {
    $out = array();
    foreach ((array)$list_keys as $list_key) {
      foreach ($this->mrm_marketing_get_list_recipients($list_key, true) as $email) {
        $out[$email] = true;
      }
    }
    $final = array_keys($out);
    sort($final);
    return $final;
  }

  private function mrm_marketing_token_for_email($email) {
    $email = strtolower(sanitize_email((string)$email));
    if (!$email || !is_email($email)) return '';
    $secret = defined('AUTH_SALT') ? AUTH_SALT : wp_salt('auth');
    $payload = $email . '|' . hash_hmac('sha256', $email, $secret);
    return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
  }

  private function mrm_marketing_email_from_token($token) {
    $token = preg_replace('/[^A-Za-z0-9\-_]/', '', (string)$token);
    if ($token === '') return '';
    $padded = strtr($token, '-_', '+/');
    $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
    $decoded = base64_decode($padded, true);
    if (!$decoded || strpos($decoded, '|') === false) return '';

    list($email, $sig) = explode('|', $decoded, 2);
    $email = strtolower(sanitize_email((string)$email));
    if (!$email || !is_email($email)) return '';

    $secret = defined('AUTH_SALT') ? AUTH_SALT : wp_salt('auth');
    $expected = hash_hmac('sha256', $email, $secret);
    return hash_equals($expected, (string)$sig) ? $email : '';
  }

  private function mrm_marketing_unsubscribe_url($email) {
    $token = $this->mrm_marketing_token_for_email($email);
    if ($token === '') return '';
    return add_query_arg(array('action' => 'mrm_marketing_unsubscribe_confirm', 'token' => $token), admin_url('admin-post.php'));
  }

  // BEGIN PAYMENT HUB MARKETING UNSUBSCRIBE FIX
  public function handle_marketing_unsubscribe_confirm() {
    $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
    $email = $this->mrm_marketing_email_from_token($token);

    if (!$email) {
      $this->mrm_marketing_render_unsubscribe_page(
        'Invalid unsubscribe link',
        'This unsubscribe link is invalid or expired. Please contact Low Brass Lessons if you still need help unsubscribing.',
        ''
      );
      exit;
    }

    $form = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:20px;">'
      . '<input type="hidden" name="action" value="mrm_marketing_unsubscribe_do">'
      . '<input type="hidden" name="token" value="' . esc_attr($token) . '">'
      . wp_nonce_field('mrm_marketing_unsubscribe_do_' . $email, 'mrm_marketing_unsubscribe_nonce', true, false)
      . '<button type="submit" style="background:#111;color:#fff;border:0;border-radius:6px;padding:12px 18px;cursor:pointer;">Unsubscribe</button>'
      . '</form>';

    $this->mrm_marketing_render_unsubscribe_page(
      'Confirm unsubscribe',
      'You are unsubscribing ' . esc_html($email) . ' from Low Brass Lessons marketing emails.',
      $form
    );
    exit;
  }

  public function handle_marketing_unsubscribe_do() {
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    $email = $this->mrm_marketing_email_from_token($token);

    if (!$email) {
      $this->mrm_marketing_render_unsubscribe_page(
        'Invalid unsubscribe request',
        'This unsubscribe request could not be verified. Please contact Low Brass Lessons if you still need help unsubscribing.',
        ''
      );
      exit;
    }

    $nonce = isset($_POST['mrm_marketing_unsubscribe_nonce'])
      ? sanitize_text_field(wp_unslash($_POST['mrm_marketing_unsubscribe_nonce']))
      : '';

    if ($nonce && !wp_verify_nonce($nonce, 'mrm_marketing_unsubscribe_do_' . $email)) {
      $this->mrm_marketing_render_unsubscribe_page(
        'Unsubscribe request expired',
        'Please reopen the unsubscribe link from your email and try again.',
        ''
      );
      exit;
    }

    $this->mrm_marketing_add_unsubscribe($email);

    $this->mrm_marketing_render_unsubscribe_page(
      'You are unsubscribed',
      esc_html($email) . ' has been removed from Low Brass Lessons marketing emails.',
      '<p style="margin-top:18px;color:#555;">You may still receive transactional emails related to purchases, lessons, masterclasses, access links, receipts, reminders, safety, or legal/account records.</p>'
    );
    exit;
  }

  private function mrm_marketing_render_unsubscribe_page($title, $message, $extra_html = '') {
    status_header(200);
    nocache_headers();

    $site_name = get_bloginfo('name') ? get_bloginfo('name') : 'Low Brass Lessons';

    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . esc_html($title) . ' - ' . esc_html($site_name) . '</title>';
    echo '</head><body style="margin:0;background:#f6f6f6;color:#111;font-family:&quot;Source Sans 3&quot;,Arial,Helvetica,sans-serif;">';
    echo '<main style="max-width:640px;margin:60px auto;padding:28px;background:#fff;border:1px solid #e5e5e5;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.06);">';
    echo '<h1 style="margin:0 0 14px;font-size:28px;line-height:1.2;font-family:&quot;Academico&quot;,Georgia,&quot;Times New Roman&quot;,serif;">' . esc_html($title) . '</h1>';
    echo '<p style="font-size:16px;line-height:1.6;margin:0;">' . wp_kses_post($message) . '</p>';
    echo wp_kses_post($extra_html);
    echo '</main></body></html>';
  }
  // END PAYMENT HUB MARKETING UNSUBSCRIBE FIX

  private function mrm_marketing_allowed_html($html) {
    $html = (string)$html;
    if (current_user_can('unfiltered_html')) return $html;
    $allowed = wp_kses_allowed_html('post');
    foreach ($allowed as $tag => $attrs) {
      $allowed[$tag]['style'] = true;
      $allowed[$tag]['class'] = true;
      $allowed[$tag]['id'] = true;
    }
    $allowed['table'] = array('style'=>true,'class'=>true,'id'=>true,'width'=>true,'cellpadding'=>true,'cellspacing'=>true,'border'=>true,'role'=>true);
    $allowed['tbody'] = array('style'=>true,'class'=>true,'id'=>true);
    $allowed['thead'] = array('style'=>true,'class'=>true,'id'=>true);
    $allowed['tr'] = array('style'=>true,'class'=>true,'id'=>true);
    $allowed['td'] = array('style'=>true,'class'=>true,'id'=>true,'align'=>true,'valign'=>true,'width'=>true);
    $allowed['th'] = array('style'=>true,'class'=>true,'id'=>true,'align'=>true,'valign'=>true,'width'=>true);
    $allowed['img'] = array('src'=>true,'alt'=>true,'style'=>true,'class'=>true,'id'=>true,'width'=>true,'height'=>true,'border'=>true);
    return wp_kses($html, $allowed);
  }

  private function mrm_marketing_wrap_email_html($subject, $body_html, $unsubscribe_url, $mailing_address = '') {
    $site = esc_html(get_bloginfo('name'));
    $logo_url = $this->mrm_get_site_logo_url();
    $logo_html = $logo_url ? '<div style="text-align:center;margin:0 0 22px 0;"><img src="' . esc_url($logo_url) . '" alt="' . $site . '" style="max-width:220px;height:auto;border:0;display:inline-block;"></div>' : '';
    $address_html = trim((string)$mailing_address) !== '' ? '<div style="margin-top:10px;">' . nl2br(esc_html((string)$mailing_address)) . '</div>' : '';
    $unsubscribe_html = $unsubscribe_url ? '<div style="margin-top:22px;padding-top:16px;border-top:1px solid #e5e5e5;font-size:12px;line-height:1.6;color:#777;text-align:center;"><div>You are receiving this marketing email from ' . $site . '.</div>' . $address_html . '<div style="margin-top:10px;"><a href="' . esc_url($unsubscribe_url) . '" style="color:#555;text-decoration:underline;">Remove me from marketing emails</a></div></div>' : '';

    return '<!doctype html><html><body style="margin:0;padding:0;background:#f6f6f6;"><div style="max-width:680px;margin:0 auto;padding:24px;"><div style="background:#ffffff;border:1px solid #e8e8e8;border-radius:16px;padding:28px;box-shadow:0 2px 10px rgba(0,0,0,0.05);font-family:&quot;Source Sans 3&quot;,Arial,Helvetica,sans-serif;color:#111;">' . $logo_html . '<div style="font-size:15px;line-height:1.7;color:#222;text-align:left;">' . $body_html . '</div>' . $unsubscribe_html . '</div></div></body></html>';
  }

  private function mrm_marketing_upload_attachments_from_request() {
    if (empty($_FILES['mrm_marketing_attachments']) || empty($_FILES['mrm_marketing_attachments']['name'])) {
      return array('paths' => array(), 'errors' => array());
    }

    if (!function_exists('wp_handle_upload')) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $files = $_FILES['mrm_marketing_attachments'];
    $paths = array();
    $errors = array();

    $allowed_mimes = array(
      'pdf'  => 'application/pdf',
      'doc'  => 'application/msword',
      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'jpg'  => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'png'  => 'image/png',
    );

    $max_files = 5;
    $max_bytes_per_file = 10 * 1024 * 1024;
    $count = is_array($files['name']) ? count($files['name']) : 0;
    if ($count > $max_files) {
      $errors[] = 'You can attach up to ' . $max_files . ' files per marketing email.';
      $count = $max_files;
    }

    for ($i = 0; $i < $count; $i++) {
      if (empty($files['name'][$i])) continue;
      if (!empty($files['error'][$i])) {
        $errors[] = 'Upload error for ' . sanitize_file_name((string)$files['name'][$i]) . '.';
        continue;
      }
      if ((int)$files['size'][$i] > $max_bytes_per_file) {
        $errors[] = sanitize_file_name((string)$files['name'][$i]) . ' is larger than 10MB.';
        continue;
      }

      $single_file = array(
        'name'     => sanitize_file_name((string)$files['name'][$i]),
        'type'     => (string)$files['type'][$i],
        'tmp_name' => (string)$files['tmp_name'][$i],
        'error'    => (int)$files['error'][$i],
        'size'     => (int)$files['size'][$i],
      );

      $upload = wp_handle_upload($single_file, array('test_form' => false, 'mimes' => $allowed_mimes));
      if (!empty($upload['error'])) {
        $errors[] = $single_file['name'] . ': ' . $upload['error'];
        continue;
      }
      if (!empty($upload['file']) && file_exists($upload['file'])) $paths[] = $upload['file'];
    }

    return array('paths' => $paths, 'errors' => $errors);
  }

  private function mrm_marketing_delete_temp_attachments($paths) {
    foreach ((array)$paths as $path) {
      $path = (string)$path;
      if ($path && file_exists($path)) @unlink($path);
    }
  }

  private function mrm_marketing_manual_list_example_html($def) {
    $example = isset($def['example']) ? trim((string)$def['example']) : '';
    if ($example === '') return '';
    return '<p style="margin:6px 0 10px 0;"><small><em>Example format:</em><br><code>' .
      esc_html($example) .
      '</code><br>Separate emails with new lines, commas, semicolons, or spaces.</small></p>';
  }

  public function handle_marketing_email_save_lists() {
    if (!current_user_can('manage_options')) wp_die('You do not have permission to save marketing email lists.');
    check_admin_referer('mrm_marketing_email_save_lists', 'mrm_marketing_email_lists_nonce');

    $defs = $this->mrm_marketing_default_lists();
    $lists = $this->mrm_marketing_manual_lists();

    foreach ($defs as $key => $def) {
      if (($def['type'] ?? '') !== 'manual') continue;
      $field = 'mrm_marketing_list_' . $key;
      $raw = isset($_POST[$field]) ? wp_unslash($_POST[$field]) : '';
      $lists[$key] = $this->mrm_marketing_normalize_emails_from_text($raw);
    }

    $mailing_address = isset($_POST['mrm_marketing_mailing_address']) ? wp_kses_post(wp_unslash($_POST['mrm_marketing_mailing_address'])) : '';
    update_option('mrm_pay_hub_marketing_mailing_address', trim((string)$mailing_address), false);
    $this->save_email_lists($lists);

    wp_safe_redirect(add_query_arg(array('page'=>'mrm-pay-hub-marketing-email-lists','mrm_marketing_saved'=>'1'), admin_url('admin.php')));
    exit;
  }

  public function handle_marketing_email_send() {
  if (!current_user_can('manage_options')) wp_die('You do not have permission to send marketing emails.');
  check_admin_referer('mrm_marketing_email_send', 'mrm_marketing_email_send_nonce');

  $subject = sanitize_text_field((string)($_POST['mrm_marketing_subject'] ?? ''));
  $raw_body = isset($_POST['mrm_marketing_html']) ? wp_unslash($_POST['mrm_marketing_html']) : '';
  $body_html = $this->mrm_marketing_allowed_html($raw_body);
  $selected_lists = isset($_POST['mrm_marketing_lists']) && is_array($_POST['mrm_marketing_lists']) ? array_map('sanitize_key', (array)$_POST['mrm_marketing_lists']) : array();

  if ($subject === '' || trim(wp_strip_all_tags($body_html)) === '' || empty($selected_lists)) {
    wp_safe_redirect(add_query_arg(array(
      'page' => 'mrm-pay-hub-marketing-email-lists',
      'mrm_marketing_error' => rawurlencode('Subject, HTML body, and at least one list are required.'),
    ), admin_url('admin.php')));
    exit;
  }

  $recipients = $this->mrm_marketing_get_combined_recipients($selected_lists);
  if (empty($recipients)) {
    wp_safe_redirect(add_query_arg(array(
      'page' => 'mrm-pay-hub-marketing-email-lists',
      'mrm_marketing_error' => rawurlencode('No recipients found after deduplication and unsubscribe suppression.'),
    ), admin_url('admin.php')));
    exit;
  }

  $attachment_result = $this->mrm_marketing_upload_attachments_from_request();
  $attachments = isset($attachment_result['paths']) ? (array)$attachment_result['paths'] : array();
  $attachment_errors = isset($attachment_result['errors']) ? (array)$attachment_result['errors'] : array();

  if (!empty($attachment_errors)) {
    $this->mrm_marketing_delete_temp_attachments($attachments);

    wp_safe_redirect(add_query_arg(array(
      'page' => 'mrm-pay-hub-marketing-email-lists',
      'mrm_marketing_error' => rawurlencode('Attachment problem: ' . implode(' ', $attachment_errors)),
    ), admin_url('admin.php')));
    exit;
  }

  $mailing_address = (string)get_option('mrm_pay_hub_marketing_mailing_address', '');
  $from_name = 'Low Brass Lessons';
  $from_email = 'no-reply@lowbrass-lessons.com';
  $headers = array(
    'Content-Type: text/html; charset=UTF-8',
    'From: ' . $from_name . ' <' . $from_email . '>',
    'Reply-To: ' . $from_name . ' <' . $from_email . '>',
  );

  $sent = 0;
  $failed = 0;

  foreach ($recipients as $email) {
    $unsubscribe_url = $this->mrm_marketing_unsubscribe_url($email);
    $final_html = $this->mrm_marketing_wrap_email_html($subject, $body_html, $unsubscribe_url, $mailing_address);
    $ok = wp_mail($email, $subject, $final_html, $headers, $attachments);

    if ($ok) {
      $sent++;
    } else {
      $failed++;
    }
  }

  $this->mrm_marketing_delete_temp_attachments($attachments);

  wp_safe_redirect(add_query_arg(array(
    'page' => 'mrm-pay-hub-marketing-email-lists',
    'mrm_marketing_sent' => (string)$sent,
    'mrm_marketing_failed' => (string)$failed,
  ), admin_url('admin.php')));
  exit;
}

public function handle_marketing_resubscribe() {
  if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to update marketing unsubscribes.');
  }

  check_admin_referer('mrm_marketing_resubscribe', 'mrm_marketing_resubscribe_nonce');

  $raw = isset($_POST['mrm_marketing_resubscribe_emails']) ? wp_unslash($_POST['mrm_marketing_resubscribe_emails']) : '';
  $emails_to_restore = $this->mrm_marketing_normalize_emails_from_text($raw);

  if (empty($emails_to_restore)) {
    wp_safe_redirect(add_query_arg(array(
      'page' => 'mrm-pay-hub-marketing-email-lists',
      'mrm_marketing_error' => rawurlencode('No valid emails were entered for re-subscribe.'),
    ), admin_url('admin.php')));
    exit;
  }

  $unsubscribed = $this->mrm_marketing_unsubscribed_emails();

  foreach ($emails_to_restore as $email) {
    unset($unsubscribed[$email]);
  }

  $this->mrm_marketing_save_unsubscribed_emails(array_keys($unsubscribed));

  wp_safe_redirect(add_query_arg(array(
    'page' => 'mrm-pay-hub-marketing-email-lists',
    'mrm_marketing_saved' => '1',
    'mrm_marketing_resubscribed' => (string)count($emails_to_restore),
  ), admin_url('admin.php')));
  exit;
}



  private function mrm_email_testing_catalog() {
    return array(
      'payment_method_attention_student' => array('label' => 'Update payment information — student', 'description' => 'Sent when an AutoPay student needs to update payment information before a renewing lesson.', 'plugin' => 'payments'),
      'payment_method_attention_instructor' => array('label' => 'Upcoming lesson payment method needs attention — instructor', 'description' => 'Sent when an instructor should withhold an upcoming AutoPay lesson until the student updates payment information.', 'plugin' => 'payments'),
      'payment_method_attention_admin' => array('label' => 'Payment method update needed — admin', 'description' => 'Sent to admin when a student payment method needs attention before an upcoming AutoPay lesson.', 'plugin' => 'payments'),
      'purchase_receipt_sheet_music' => array('label' => 'Purchase confirmation — sheet music', 'description' => 'Sent after a sheet music purchase.', 'plugin' => 'payments'),
      'purchase_receipt_online_lesson' => array('label' => 'Purchase confirmation — online lesson', 'description' => 'Sent after an online lesson purchase.', 'plugin' => 'payments'),
      'purchase_receipt_in_person_lesson' => array('label' => 'Purchase confirmation — in-person lesson', 'description' => 'Sent after an in-person lesson purchase.', 'plugin' => 'payments'),
      'sheet_music_subscription_enrollment' => array('label' => 'Sheet music subscription enrollment confirmation', 'description' => 'Normally triggered when a customer successfully enrolls in the monthly sheet music subscription.', 'plugin' => 'payments'),
      'sheet_music_subscription_renewal_reminder' => array('label' => 'Sheet music subscription renewal reminder', 'description' => 'Sent 24 hours before a sheet music subscription renewal.', 'plugin' => 'payments'),
      'sheet_music_subscription_renewal' => array('label' => 'Sheet music subscription renewal receipt', 'description' => 'Normally triggered after a successful recurring monthly sheet music subscription charge.', 'plugin' => 'payments'),
      'sheet_music_subscription_cancelled' => array('label' => 'Sheet music subscription cancelled', 'description' => 'Normally triggered when a sheet music subscription is cancelled.', 'plugin' => 'payments'),
      'lesson_cancellation_refund' => array('label' => 'Lesson cancellation refund', 'description' => 'Normally triggered when a lesson is cancelled and a refund is issued.', 'plugin' => 'payments'),
      'lesson_cancellation_no_refund' => array('label' => 'Lesson cancellation', 'description' => 'Sent when a lesson is cancelled without qualifying for a refund.', 'plugin' => 'payments'),
      'meeting_confirmation' => array('label' => 'Meeting Scheduler confirmation', 'description' => 'Normally triggered immediately after a Meeting Scheduler event is created and invitation emails are sent.', 'plugin' => 'scheduler'),
      'meeting_reminder' => array('label' => 'Meeting Scheduler reminder', 'description' => 'Normally triggered by cron approximately 1 hour before a scheduled Meeting Scheduler event.', 'plugin' => 'scheduler'),
      'private_lesson_reminder_parent_online' => array('label' => 'Private lesson reminder — parent/student — online', 'description' => 'Sent to the family before an online private lesson.', 'plugin' => 'scheduler'),
      'private_lesson_reminder_parent_in_person' => array('label' => 'Private lesson reminder — parent/student — in-person', 'description' => 'Sent to the family before an in-person private lesson.', 'plugin' => 'scheduler'),
      'private_lesson_reminder_instructor_online' => array('label' => 'Private lesson reminder — instructor — online', 'description' => 'Sent to the instructor before an online private lesson.', 'plugin' => 'scheduler'),
      'private_lesson_reminder_instructor_in_person' => array('label' => 'Private lesson reminder — instructor — in-person', 'description' => 'Sent to the instructor before an in-person private lesson.', 'plugin' => 'scheduler'),
      'lesson_feedback_request' => array('label' => 'Lesson feedback request', 'description' => 'Normally triggered after a lesson ends when feedback has not yet been submitted.', 'plugin' => 'scheduler'),
      'parent_feedback_received' => array('label' => 'Parent lesson feedback received', 'description' => 'Normally triggered after a parent/student submits lesson feedback; sent to admin and/or instructor.', 'plugin' => 'scheduler'),
      'consultation_confirmation_family' => array('label' => 'Consultation confirmation — family', 'description' => 'Sent to the family when a consultation is scheduled.', 'plugin' => 'scheduler'),
      'consultation_confirmation_instructor' => array('label' => 'Consultation confirmation — instructor', 'description' => 'Sent to the instructor when a consultation is scheduled.', 'plugin' => 'scheduler'),
      'contact_form_notification' => array('label' => 'Contact form notification', 'description' => 'Normally triggered when someone submits the [mrm_contact_form] contact form successfully.', 'plugin' => 'scheduler'),
      'safety_no_show_alert' => array('label' => 'Safety alert — parent reported no-show', 'description' => 'Normally triggered when a parent reports that an instructor did not arrive for a scheduled lesson.', 'plugin' => 'scheduler'),
      'safety_emergency_notice' => array('label' => 'Safety emergency notice', 'description' => 'Normally triggered when an instructor uses the emergency action during an in-person lesson.', 'plugin' => 'scheduler'),
      'masterclass_registration_confirmation' => array('label' => 'Masterclass registration confirmation', 'description' => 'Normally triggered after a student/client successfully registers and pays for a masterclass.', 'plugin' => 'masterclass'),
      'masterclass_student_reminder' => array('label' => 'Masterclass student reminder', 'description' => 'Normally triggered by the Masterclass reminder cron before a masterclass begins.', 'plugin' => 'masterclass'),
      'masterclass_presenter_confirmation' => array('label' => 'Masterclass presenter confirmation', 'description' => 'Normally triggered when a masterclass event is created and assigned to a presenter.', 'plugin' => 'masterclass'),
      'masterclass_presenter_reminder' => array('label' => 'Masterclass presenter reminder', 'description' => 'Normally triggered by the Masterclass reminder cron before a presenter’s masterclass begins.', 'plugin' => 'masterclass'),
      'masterclass_feedback_request' => array('label' => 'Masterclass feedback request', 'description' => 'Normally triggered after a masterclass ends to request student/client feedback.', 'plugin' => 'masterclass'),
      'masterclass_feedback_received' => array('label' => 'Masterclass feedback received', 'description' => 'Sent to presenter/admin after masterclass feedback is submitted.', 'plugin' => 'masterclass'),
      'masterclass_event_updated' => array('label' => 'Masterclass event updated', 'description' => 'Normally triggered when a masterclass event is edited after registrations already exist.', 'plugin' => 'masterclass'),
      'masterclass_refund_completed' => array('label' => 'Masterclass refund completed', 'description' => 'Normally triggered when a masterclass registration refund is completed.', 'plugin' => 'masterclass'),
      'masterclass_event_cancelled' => array('label' => 'Masterclass event cancelled', 'description' => 'Normally triggered when a masterclass event is cancelled and registrants are notified.', 'plugin' => 'masterclass'),
      'product_access_otp' => array('label' => 'Sheet music/product access OTP', 'description' => 'Normally triggered when a purchaser requests a one-time access code for protected product access.', 'plugin' => 'product-access'),
      'profile_card_invite_instructor' => array(
        'label' => 'Profile Card Creation invite — instructor profile card',
        'description' => 'Sent when you request an instructor to complete the instructor onboarding/profile card form.',
        'plugin' => 'payments',
      ),
      'profile_card_invite_presenter' => array(
        'label' => 'Profile Card Creation invite — presenter profile card',
        'description' => 'Sent when you request a presenter to complete the presenter profile card form.',
        'plugin' => 'payments',
      ),
      'profile_card_invite_masterclass_event' => array(
        'label' => 'Profile Card Creation invite — masterclass event submission',
        'description' => 'Sent when you request a presenter to submit details for a specific masterclass event.',
        'plugin' => 'payments',
      ),
      'profile_card_changes_requested' => array('label' => 'Profile Card Creation changes requested', 'description' => 'Normally triggered from Payment Hub → Profile Card Creation when you request changes to a submitted profile card or masterclass event proposal.', 'plugin' => 'payments'),
      'payout_summary_instructor' => array('label' => 'Payout summary — individual instructor', 'description' => 'Normally sent to each individual instructor after their instructor payout batch runs.', 'plugin' => 'payments'),
      'payout_summary_presenter' => array('label' => 'Payout summary — individual presenter', 'description' => 'Normally sent to each individual presenter after their presenter payout runs.', 'plugin' => 'payments'),
      'payout_summary_composer' => array('label' => 'Payout summary — composer', 'description' => 'Normally sent to the composer after composer payout rows are paid.', 'plugin' => 'payments'),
      'payout_summary_owner_net' => array('label' => 'Payout summary — owner/net batch', 'description' => 'Normally sent to the owner after each payout batch cycle with contractor payout totals, promo-code reductions, and company retained/netted funds.', 'plugin' => 'payments'),
    );
  }

  public function render_email_testing_page() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html('You do not have permission to view this page.'));
    }

    $catalog = $this->mrm_email_testing_catalog();
    $error = isset($_GET['mrm_email_tests_error']) ? sanitize_text_field(wp_unslash($_GET['mrm_email_tests_error'])) : '';
    $settings = $this->get_settings();
    $owner_payout_summary_email = esc_attr((string)($settings['owner_payout_summary_email'] ?? get_option('admin_email', '')));

    $previews = array();
    foreach ($catalog as $key => $item) {
      $preview = $this->mrm_build_single_email_preview($key);
      $previews[$key] = array(
        'label' => (string)($item['label'] ?? $key),
        'description' => (string)($item['description'] ?? ''),
        'plugin' => (string)($item['plugin'] ?? ''),
        'subject' => sanitize_text_field((string)($preview['subject'] ?? $key)),
        'html' => (string)($preview['html'] ?? ''),
      );
    }
    ?>
    <div class="wrap">
      <h1>Email Testing</h1>
      <p>Use this page to preview the HTML versions of site emails before launch. These previews do not send emails and do not look up real recipients.</p>
      <?php if ($error !== '') : ?>
        <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
      <?php endif; ?>
      <?php settings_errors('mrm_pay_hub'); ?>

      <div id="mrm-email-testing-preview-card" style="max-width:1100px;background:#fff;border:1px solid #ccd0d4;border-radius:12px;padding:18px;margin:18px 0;">
        <h2 style="margin-top:0;">Email Preview</h2>
        <p class="description">Select the email you want to preview. Only one email can be selected at a time. Uncheck the selected email to clear the preview.</p>
        <div id="mrm-email-testing-empty-preview" style="padding:18px;border:1px dashed #ccd0d4;border-radius:10px;background:#fafafa;color:#555;">No email selected.</div>
        <div id="mrm-email-testing-active-preview" style="display:none;">
          <h3 id="mrm-email-testing-preview-title" style="margin:12px 0 6px;"></h3>
          <p style="margin:0 0 10px;"><strong>Subject:</strong> <span id="mrm-email-testing-preview-subject"></span></p>
          <p class="description" id="mrm-email-testing-preview-description"></p>
          <iframe id="mrm-email-testing-preview-frame" title="Email preview" scrolling="auto" style="display:block;width:100%;min-height:420px;height:520px;max-height:900px;border:1px solid #dcdcde;border-radius:8px;background:#fff;overflow:auto;"></iframe>
        </div>
      </div>

      <div style="max-width:1100px;background:#fff;border:1px solid #ccd0d4;border-radius:12px;padding:18px;margin-top:18px;">
        <h2>Select the email you want to preview</h2>
        <p>Checking a box updates the preview window immediately. No emails are sent from this screen.</p>
        <table class="widefat striped">
          <thead>
            <tr>
              <th style="width:60px;">Preview</th>
              <th style="width:320px;">Email</th>
              <th>Normally Triggered By</th>
              <th style="width:150px;">Plugin</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($catalog as $key => $item) : ?>
              <tr>
                <td><input class="mrm-email-test-checkbox" type="checkbox" value="<?php echo esc_attr($key); ?>"></td>
                <td><strong><?php echo esc_html($item['label']); ?></strong></td>
                <td><?php echo esc_html($item['description']); ?></td>
                <td><code><?php echo esc_html($item['plugin']); ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <form method="post" style="max-width:1100px;background:#fff;border:1px solid #ccd0d4;border-radius:12px;padding:18px;margin-top:18px;">
        <?php wp_nonce_field('mrm_pay_hub_save', 'mrm_pay_hub_nonce'); ?>

        <h2>Owner Payout Summary Destination</h2>
        <p class="description">
          This controls where the owner/net payout batch summary is sent after every instructor, presenter, or composer payout batch cycle.
        </p>

        <table class="form-table">
          <tr>
            <th scope="row"><label for="owner_payout_summary_email">Owner Payout Summary Email</label></th>
            <td>
              <input type="email" id="owner_payout_summary_email" name="owner_payout_summary_email" value="<?php echo $owner_payout_summary_email; ?>" class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email', '')); ?>" />
              <p class="description">Leave blank to fall back to the WordPress admin email.</p>
            </td>
          </tr>
        </table>

        <p class="submit">
          <button type="submit" class="button button-primary">Save Owner Summary Email</button>
        </p>
      </form>
    </div>

    <script data-cfasync="false" data-no-optimize="1" data-no-defer="1" data-no-minify="1">
    (function(){
      var previews = <?php echo wp_json_encode($previews); ?>;
      var boxes = Array.prototype.slice.call(document.querySelectorAll('.mrm-email-test-checkbox'));
      var empty = document.getElementById('mrm-email-testing-empty-preview');
      var active = document.getElementById('mrm-email-testing-active-preview');
      var title = document.getElementById('mrm-email-testing-preview-title');
      var subject = document.getElementById('mrm-email-testing-preview-subject');
      var desc = document.getElementById('mrm-email-testing-preview-description');
      var frame = document.getElementById('mrm-email-testing-preview-frame');

      function resizePreviewFrame(){
        if (!frame) return;

        var minHeight = 420;
        var maxHeight = 900;
        var paddingBuffer = 40;

        try {
          var doc = frame.contentDocument || (frame.contentWindow ? frame.contentWindow.document : null);
          if (!doc) return;

          var body = doc.body;
          var html = doc.documentElement;

          var contentHeight = Math.max(
            body ? body.scrollHeight : 0,
            body ? body.offsetHeight : 0,
            html ? html.scrollHeight : 0,
            html ? html.offsetHeight : 0
          );

          var targetHeight = Math.max(minHeight, contentHeight + paddingBuffer);
          targetHeight = Math.min(maxHeight, targetHeight);

          frame.style.height = targetHeight + 'px';

          if (contentHeight + paddingBuffer > maxHeight) {
            frame.setAttribute('scrolling', 'auto');
            frame.style.overflow = 'auto';
          } else {
            frame.setAttribute('scrolling', 'no');
            frame.style.overflow = 'hidden';
          }
        } catch (e) {
          frame.style.height = '520px';
          frame.setAttribute('scrolling', 'auto');
          frame.style.overflow = 'auto';
        }
      }

      function setFrameHtml(html){
        if (!frame) return;

        frame.onload = function(){
          resizePreviewFrame();
          setTimeout(resizePreviewFrame, 100);
          setTimeout(resizePreviewFrame, 350);
          setTimeout(resizePreviewFrame, 800);
        };

        frame.srcdoc = html;
      }

      function clearPreview(){
        if (empty) empty.style.display = 'block';
        if (active) active.style.display = 'none';
        if (title) title.textContent = '';
        if (subject) subject.textContent = '';
        if (desc) desc.textContent = '';
        if (frame) {
          frame.srcdoc = '';
          frame.style.height = '520px';
          frame.setAttribute('scrolling', 'auto');
          frame.style.overflow = 'auto';
        }
      }

      function showPreview(slug){
        var preview = previews[slug];
        if (!preview) {
          clearPreview();
          return;
        }

        if (empty) empty.style.display = 'none';
        if (active) active.style.display = 'block';
        if (title) title.textContent = preview.label || slug;
        if (subject) subject.textContent = preview.subject || '';
        if (desc) desc.textContent = preview.description || '';
        if (frame) setFrameHtml(preview.html || '<!doctype html><html><body style="margin:0;padding:18px;">No preview HTML available.</body></html>');
      }

      boxes.forEach(function(box){
        box.addEventListener('change', function(){
          if (box.checked) {
            boxes.forEach(function(other){
              if (other !== box) other.checked = false;
            });
            showPreview(box.value);
          } else {
            clearPreview();
          }
        });
      });

      clearPreview();
    })();
    </script>
    <?php
  }

  public function handle_email_testing_preview() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html('Not allowed.'));
    }

    check_admin_referer('mrm_pay_hub_send_email_tests', 'mrm_pay_hub_email_tests_nonce');
    $selected = isset($_POST['mrm_email_tests']) && is_array($_POST['mrm_email_tests'])
      ? array_map('sanitize_key', wp_unslash($_POST['mrm_email_tests']))
      : array();
    $catalog = $this->mrm_email_testing_catalog();
    $selected = array_values(array_unique(array_intersect($selected, array_keys($catalog))));

    if (empty($selected)) {
      wp_safe_redirect(add_query_arg(array(
        'page' => 'mrm-pay-hub-email-testing',
        'mrm_email_tests_error' => 'Please select at least one email to preview.',
      ), admin_url('admin.php')));
      exit;
    }

    set_transient('mrm_pay_hub_email_preview_' . get_current_user_id(), $selected, 10 * MINUTE_IN_SECONDS);
    wp_safe_redirect(add_query_arg(array(
      'page' => 'mrm-pay-hub-email-testing',
      'mrm_email_preview' => '1',
    ), admin_url('admin.php')));
    exit;
  }

  private function mrm_email_preview_blank_value() {
    return '';
  }

  private function mrm_payout_summary_email_test_samples() {
    $table_style = 'width:100%;border-collapse:collapse;margin-top:14px;';
    $th_style = 'text-align:left;border:1px solid #ddd;padding:8px;';
    $td_style = 'border:1px solid #ddd;padding:8px;';
    $contact_button = array(
      array(
        'url' => $this->mrm_get_contact_url(),
        'label' => 'Contact Support',
        'variant' => 'primary',
      ),
    );

    return array(
      'payout_summary_instructor' => array(
        'Low Brass Lessons payout summary ',
        'Payout Summary',
        '<p>Your instructor payout has been processed successfully. Here is the summary:</p>',
        '<div><strong>Pay period:</strong> </div>'
          . '<table style="' . esc_attr($table_style) . '">'
          . '<thead><tr>'
          . '<th style="' . esc_attr($th_style) . '">Lesson Type</th>'
          . '<th style="' . esc_attr($th_style) . '">Count</th>'
          . '<th style="' . esc_attr($th_style) . '">Payout</th>'
          . '</tr></thead><tbody>'
          . '<tr><td style="' . esc_attr($td_style) . '">Online 30-minute lessons</td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td></tr>'
          . '<tr><td style="' . esc_attr($td_style) . '">Online 60-minute lessons</td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td></tr>'
          . '<tr><td style="' . esc_attr($td_style) . '">In-person 30-minute lessons</td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td></tr>'
          . '<tr><td style="' . esc_attr($td_style) . '">In-person 60-minute lessons</td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td></tr>'
          . '</tbody></table>'
          . '<div style="margin-top:14px;font-size:17px;"><strong>Total payout:</strong> </div>',
        $contact_button
      ),

      'payout_summary_presenter' => array(
        'Low Brass Lessons masterclass payout summary',
        'Payout Summary',
        '<p>Your presenter payout has been processed successfully. Here is the summary:</p>',
        '<div><strong>Masterclass:</strong> </div>'
          . '<table style="' . esc_attr($table_style) . '">'
          . '<thead><tr>'
          . '<th style="' . esc_attr($th_style) . '">Payout Per Student</th>'
          . '<th style="' . esc_attr($th_style) . '">Number of Students Enrolled</th>'
          . '</tr></thead><tbody>'
          . '<tr><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td></tr>'
          . '</tbody></table>'
          . '<div style="margin-top:14px;font-size:17px;"><strong>Payout Total:</strong> </div>',
        $contact_button
      ),

      'payout_summary_composer' => array(
        'Low Brass Lessons payout summary ',
        'Payout Summary',
        '<p>Your composer payout has been processed successfully. Here is the summary:</p>',
        '<div><strong>Pay period:</strong> </div>'
          . '<div><strong>Subscriptions paid in this window:</strong> </div>'
          . '<table style="' . esc_attr($table_style) . '">'
          . '<thead><tr>'
          . '<th style="' . esc_attr($th_style) . '">Product</th>'
          . '<th style="' . esc_attr($th_style) . '">Type</th>'
          . '<th style="' . esc_attr($th_style) . '">Quantity</th>'
          . '<th style="' . esc_attr($th_style) . '">Promo Reduction</th>'
          . '<th style="' . esc_attr($th_style) . '">Composer Payout</th>'
          . '</tr></thead><tbody>'
          . '<tr><td style="' . esc_attr($td_style) . '">Product</td><td style="' . esc_attr($td_style) . '">Piece / product sale</td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td></tr>'
          . '<tr><td style="' . esc_attr($td_style) . '">Sheet music subscription</td><td style="' . esc_attr($td_style) . '">Subscription payment</td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td></tr>'
          . '</tbody></table>'
          . '<div style="margin-top:14px;"><strong>Total promo reduction affecting composer content:</strong> </div>'
          . '<div style="margin-top:8px;font-size:17px;"><strong>Total composer payout:</strong> </div>',
        $contact_button
      ),

      'payout_summary_owner_net' => array(
        'Low Brass Lessons owner payout batch summary',
        'Owner Payout Batch Summary',
        '<p>A payout batch has been processed. Here is the owner/company summary.</p>',
        '<div><strong>Pay period:</strong> </div>'
          . '<table style="' . esc_attr($table_style) . '">'
          . '<thead><tr>'
          . '<th style="' . esc_attr($th_style) . '">Payee Type</th>'
          . '<th style="' . esc_attr($th_style) . '">Payee</th>'
          . '<th style="' . esc_attr($th_style) . '">Paid Out</th>'
          . '<th style="' . esc_attr($th_style) . '">Promo Reduction</th>'
          . '<th style="' . esc_attr($th_style) . '">Company Retained</th>'
          . '</tr></thead><tbody>'
          . '<tr><td style="' . esc_attr($td_style) . '">Instructor</td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td></tr>'
          . '<tr><td style="' . esc_attr($td_style) . '">Presenter</td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td></tr>'
          . '<tr><td style="' . esc_attr($td_style) . '">Composer</td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td><td style="' . esc_attr($td_style) . '"></td></tr>'
          . '</tbody></table>'
          . '<div style="margin-top:14px;"><strong>Instructor payouts:</strong> </div>'
          . '<div><strong>Presenter payouts:</strong> </div>'
          . '<div><strong>Composer payouts:</strong> </div>'
          . '<div><strong>Promo-code reductions:</strong> </div>'
          . '<div style="margin-top:8px;font-size:17px;"><strong>Company retained / netted:</strong> </div>',
        ''
      ),
    );
  }


  private function mrm_profile_card_piece_options() {
    $products = $this->all_products();
    $pieces = array();

    foreach ((array)$products as $sku => $product) {
      if (!is_array($product)) {
        continue;
      }

      if ((string)($product['product_type'] ?? '') !== 'sheet_music') {
        continue;
      }

      $sku = sanitize_text_field((string)$sku);

      if ($sku === '' || $sku === $this->mrm_master_all_sheet_music_sku() || $sku === 'all-sheet-music') {
        continue;
      }

      $parsed = $this->mrm_parse_piece_sku_labels($sku);

      if (empty($parsed['is_piece']) || empty($parsed['piece_slug'])) {
        continue;
      }

      $piece_slug = sanitize_title((string)$parsed['piece_slug']);
      $piece_title = sanitize_text_field((string)($parsed['piece_title'] ?? ''));

      if ($piece_slug === '' || $piece_title === '') {
        continue;
      }

      $category = sanitize_key((string)($parsed['category'] ?? ''));
      $url = $this->mrm_get_piece_page_url_from_sku($sku);

      if (!isset($pieces[$piece_slug])) {
        $pieces[$piece_slug] = array(
          'sku' => $sku,
          'title' => $piece_title,
          'url' => $url,
          'category' => $category,
        );
      }

      if ($category === 'complete-package') {
        $pieces[$piece_slug]['sku'] = $sku;
        $pieces[$piece_slug]['url'] = $url;
        $pieces[$piece_slug]['category'] = $category;
      }
    }

    uasort($pieces, function($a, $b) {
      return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
    });

    return array_values($pieces);
  }

  private function mrm_profile_card_piece_select_html($selected_sku = '') {
    $selected_sku = sanitize_text_field((string)$selected_sku);
    $pieces = $this->mrm_profile_card_piece_options();

    $html = '<select id="masterclass_piece_sku" name="masterclass_piece_sku">';
    $html .= '<option value="">Select the piece being discussed</option>';

    foreach ($pieces as $piece) {
      $sku = sanitize_text_field((string)($piece['sku'] ?? ''));
      $title = sanitize_text_field((string)($piece['title'] ?? ''));

      if ($sku === '' || $title === '') {
        continue;
      }

      $html .= '<option value="' . esc_attr($sku) . '"' . selected($selected_sku, $sku, false) . '>';
      $html .= esc_html($title);
      $html .= '</option>';
    }

    $html .= '</select>';

    if (empty($pieces)) {
      $html .= '<p class="description" style="color:#b32d2e;">No sheet music piece products were found in the Payment Hub product collection.</p>';
    } else {
      $html .= '<p class="description">Choose the piece that will be discussed in this masterclass. This will be used in the masterclass registration/reminder emails so students can access the correct piece.</p>';
    }

    return $html;
  }

  private function mrm_profile_card_piece_from_sku($sku) {
    $sku = sanitize_text_field((string)$sku);

    if ($sku === '') {
      return array(
        'sku' => '',
        'title' => '',
        'url' => '',
      );
    }

    foreach ($this->mrm_profile_card_piece_options() as $piece) {
      if ((string)($piece['sku'] ?? '') === $sku) {
        return array(
          'sku' => sanitize_text_field((string)($piece['sku'] ?? '')),
          'title' => sanitize_text_field((string)($piece['title'] ?? '')),
          'url' => esc_url_raw((string)($piece['url'] ?? '')),
        );
      }
    }

    return array(
      'sku' => '',
      'title' => '',
      'url' => '',
    );
  }

  private function mrm_profile_card_invite_email_parts($request_type, $label = '', $days_label = '', $admin_note = '', $selected_piece = array()) {
    $request_type = sanitize_key((string)$request_type);
    $label = trim((string)$label);
    $days_label = trim((string)$days_label);
    $admin_note = trim((string)$admin_note);

    $selected_piece = is_array($selected_piece) ? $selected_piece : array();
    $selected_piece_title = sanitize_text_field((string)($selected_piece['title'] ?? ''));
    $selected_piece_url = esc_url_raw((string)($selected_piece['url'] ?? ''));

    if ($request_type === 'presenter_profile') {
      $title = 'Presenter Profile Card';
      $subject = 'Low Brass Lessons presenter profile card';
      $intro = '<p>Hello,</p><p>Low Brass Lessons has invited you to complete your <strong>Presenter Profile Card</strong>.</p>';
      $details = '<p>This form collects the information needed to build your presenter profile, including your public-facing name, presenter title, biography, and profile photo. Please fill it out in its entirety and we will let you know if we need any revisions.</p>';
      $details .= '<div><strong>Request type:</strong> Presenter Profile Card</div>';
    } elseif ($request_type === 'presenter_event') {
      $title = 'Masterclass Event Submission';
      $subject = 'Low Brass Lessons masterclass event submission';
      $intro = '<p>Hello,</p><p>Low Brass Lessons has invited you to submit details for an upcoming <strong>Masterclass Event</strong>.</p>';
      $details = '<p>This form collects the information needed to create your upcoming masterclass listing. Please include the event title, description, and session details in your submission. Please fill it out in its entirety and we will let you know if we need any revisions.</p>';
      $details .= '<div><strong>Request type:</strong> Masterclass Event Submission</div>';

      if ($selected_piece_title !== '') {
        $details .= '<div><strong>Piece being discussed:</strong> ' . esc_html($selected_piece_title) . '</div>';

        if ($selected_piece_url !== '') {
          $details .= '<div><strong>Piece page:</strong> <a href="' . esc_url($selected_piece_url) . '">' . esc_html($selected_piece_url) . '</a></div>';
        }
      }
    } else {
      $title = 'Instructor Profile Card';
      $subject = 'Low Brass Lessons instructor profile card';
      $intro = '<p>Hello,</p><p>Low Brass Lessons has invited you to complete your <strong>Instructor Profile Card</strong>.</p>';
      $details = '<p>This form collects the information needed to build your instructor profile, including your public-facing name, teaching title, biography, and profile photo. Please fill it out in its entirety and we will let you know if we need any revisions.</p>';
      $details .= '<div><strong>Request type:</strong> Instructor Profile Card</div>';
    }

    if ($label !== '') {
      $details .= '<div><strong>Form:</strong> ' . esc_html($label) . '</div>';
    }

    $details .= '<div><strong>Private link expiration:</strong> ' . esc_html($days_label) . '</div>';

    if ($admin_note !== '') {
      $details .= '<div style="margin-top:12px;padding-top:12px;border-top:1px solid #d9cfbe;"><strong>Note from Low Brass Lessons:</strong><br>' . nl2br(esc_html($admin_note)) . '</div>';
    } else {
      $details .= '<div style="margin-top:12px;padding-top:12px;border-top:1px solid #d9cfbe;"><strong>Note from Low Brass Lessons:</strong><br></div>';
    }

    return array(
      'subject' => $subject,
      'title' => $title,
      'intro' => $intro,
      'details' => $details,
      'button_label' => 'Complete Your Form',
      'buttons' => array(
        array(
          'url' => '#',
          'label' => 'Complete Your Form',
          'variant' => 'primary',
        ),
        array(
          'url' => $this->mrm_get_contact_url(),
          'label' => 'Contact Support',
          'variant' => 'primary',
        ),
      ),
    );
  }

  private function mrm_build_single_email_preview($slug) {
    $slug = sanitize_key((string)$slug);
    $contact_url = $this->mrm_get_contact_url();
    $blank = $this->mrm_email_preview_blank_value();

    $full_width_blank = function() {
      return '';
    };

    $payment_samples = array(
      'profile_card_invite_instructor' => array(
        $this->mrm_profile_card_invite_email_parts('instructor_profile')['subject'],
        $this->mrm_profile_card_invite_email_parts('instructor_profile')['title'],
        $this->mrm_profile_card_invite_email_parts('instructor_profile')['intro'],
        $this->mrm_profile_card_invite_email_parts('instructor_profile')['details'],
        $this->mrm_profile_card_invite_email_parts('instructor_profile')['buttons']
      ),

      'profile_card_invite_presenter' => array(
        $this->mrm_profile_card_invite_email_parts('presenter_profile')['subject'],
        $this->mrm_profile_card_invite_email_parts('presenter_profile')['title'],
        $this->mrm_profile_card_invite_email_parts('presenter_profile')['intro'],
        $this->mrm_profile_card_invite_email_parts('presenter_profile')['details'],
        $this->mrm_profile_card_invite_email_parts('presenter_profile')['buttons']
      ),

      'profile_card_invite_masterclass_event' => array(
        $this->mrm_profile_card_invite_email_parts('presenter_event')['subject'],
        $this->mrm_profile_card_invite_email_parts('presenter_event')['title'],
        $this->mrm_profile_card_invite_email_parts('presenter_event')['intro'],
        $this->mrm_profile_card_invite_email_parts('presenter_event')['details'],
        $this->mrm_profile_card_invite_email_parts('presenter_event')['buttons']
      ),

      'profile_card_changes_requested' => array(
        'Profile Card Changes Requested',
        'Profile Card Changes Requested',
        '<p>Hello,</p><p>Low Brass Lessons has reviewed your submitted profile card or event request and requested changes before approval.</p>',
        '<div><strong>Requested changes:</strong></div>'
          . '<div></div>'
          . '<div style="margin-top:12px;">Please use your original private link to update and resubmit your request.</div>',
        ''
      ),


      'payment_method_attention_student' => array('Your Payment Method Requires Attention','Update your payment information','<p>Your payment information is in need of an update. Please use this link to update your payment information for the auto-pay lessons, renewing at <strong></strong>.</p>','<div><strong>Lesson:</strong> </div><div><strong>Renewing at:</strong> </div><div><strong>Saved payment method:</strong> </div><div><strong>Reason:</strong> </div>',array(array('url' => '#', 'label' => 'Update Payment Information', 'variant' => 'primary'),array('url' => $contact_url, 'label' => 'Contact Support', 'variant' => 'primary'))),
      'payment_method_attention_instructor' => array('Payment method needs attention for ','Please withhold this lesson  until payment method has been updated','<p>Your upcoming AutoPay lesson with <strong></strong> requires an updated payment method before the lesson can be processed. Please withhold lesson services until this has been confirmed.</p>','<div><strong>Student:</strong> </div><div><strong>Lesson:</strong> </div><div><strong>Scheduled time:</strong> </div><p style="margin-top:12px;">This client has also received a request to update their payment information. Feel free to connect and check in with them.</p><div><strong>Client email:</strong> </div><div><strong>Client phone number:</strong> </div>',''),
      'payment_method_attention_admin' => array('Payment information update required for ','Payment method update needed','<p>The system detected a need for payment information to be updated for <strong></strong> before their upcoming lesson time with <strong></strong> and <strong></strong>.</p>','<div><strong>Student:</strong> </div><div><strong>Student email:</strong> </div><div><strong>Student phone:</strong> </div><div><strong>Instructor:</strong> </div><div><strong>Instructor email:</strong> </div><div><strong>Instructor phone:</strong> </div><div><strong>Payment update portal:</strong> </div>',''),
      'purchase_receipt_sheet_music' => array('Purchase Confirmation - ','Purchase Confirmation','<p>We’ve received your payment successfully.</p>','<div><strong>Item:</strong> </div><div><strong>Base:</strong> </div><div><strong>Promo code:</strong> </div><div><strong>Tax:</strong> </div><div><strong>Total paid:</strong> </div><div style="margin-top:12px;"><strong>How to access your sheet music:</strong></div><ol style="margin:8px 0 0 18px;padding:0;"><li>Return to the piece page on the website.</li><li>Click the access button for your purchased category.</li><li>Enter your purchase email address.</li><li>Request your one-time access code and enter it to open the content.</li></ol>',array(array('url'=>'#','label'=>'View Your Piece','variant'=>'primary'),array('url'=>$contact_url,'label'=>'Contact Support','variant'=>'primary'))),
      'purchase_receipt_online_lesson' => array('Purchase Confirmation - Online Lesson with ','Purchase Confirmation','<p>We’ve received your payment successfully.</p>','<div><strong>Item:</strong> Online lesson with </div><div><strong>Total paid:</strong> </div><div style="margin-top:14px;"><strong>How to access online lessons</strong></div><p>Your meeting link will become available 10 minutes before your lesson time and will remain available until 10 minutes after your lesson time. Please make sure your camera, microphone, and internet connection are working before joining the call.</p><p style="margin-top:14px;"><strong>Cancellations must be submitted at least 24 hours in advance to receive a refund. Refunds will not be issued for cancellations that occur within 24 hours of the lesson time.</strong></p><div style="margin-top:14px;"><strong>How to access your sheet music</strong></div><p>Please check your email for the subscription confirmation.</p>',array(array('url'=>'#','label'=>'Join Lesson','variant'=>'primary'),array('url'=>'#','label'=>'Cancel Lesson','variant'=>'cancel'))),
      'purchase_receipt_in_person_lesson' => array('Purchase Confirmation - In-Person Lesson with ','Purchase Confirmation','<p>We’ve received your payment successfully.</p>','<div><strong>Item:</strong> In-person lesson with </div><div><strong>Total paid:</strong> </div><div style="margin-top:14px;"><strong>How to prepare for in-person lessons</strong></div><p>Please prepare a comfortable shared space for the lesson, such as a living room or family room. The space should include two chairs, a music stand, and as little background noise as possible from TVs, conversations, or other activity.</p><div style="margin-top:14px;"><strong>Lessons outside the home</strong></div><p>If the lesson will take place at a school, church, or other community location, please complete the required approval form before the lesson begins.</p><p><a href="https://www.docusign.com/" target="_blank" rel="noopener">Placeholder DocuSign location approval link</a></p><p style="margin-top:14px;"><strong>Cancellations must be submitted at least 24 hours in advance to receive a refund. Refunds will not be issued for cancellations that occur within 24 hours of the lesson time.</strong></p>',array(array('url'=>'#','label'=>'Cancel Lesson','variant'=>'cancel'))),

      'sheet_music_subscription_enrollment' => array('Subscription Confirmation - Sheet Music Access','Subscription Confirmation - Sheet Music Access','<p>You have successfully enrolled in the sheet music subscription service.</p>','<div><strong>Subscription:</strong> Monthly Sheet Music Access</div>' . '<div><strong>Amount:</strong> $5.00 Per Month</div>' . '<div><strong>Renews on:</strong> </div>' . $this->mrm_get_sheet_music_access_section_html() . '<div style="margin-top:12px;"><strong>Purchase Details</strong></div>' . '<div>Your subscription has been created successfully in our billing system.</div>' . '<div style="margin-top:12px;">You will be billed again on or about <strong></strong>, and then monthly thereafter while the subscription remains active.</div>','Contact Support',$this->mrm_email_cancel_subscription_text_link_html('#')),
      'sheet_music_subscription_renewal' => array('Subscription Renewal - Sheet Music Access','Subscription Renewal - Sheet Music Access','<p>Your saved card has been successfully charged for your sheet music subscription renewal.</p>','<div><strong>Subscription:</strong> Monthly Sheet Music Access</div>' . '<div><strong>Amount Charged:</strong> </div>' . '<div><strong>Next renewal date:</strong> </div>' . $this->mrm_get_sheet_music_access_section_html() . '<div style="margin-top:12px;"><strong>Purchase Details</strong></div>' . '<div>Your sheet music subscription remains active.</div>' . '<div><strong>Invoice ID:</strong> </div>' . '<div style="margin-top:12px;">Your next monthly billing date will be on or about <strong></strong>.</div>','Contact Support',$this->mrm_email_cancel_subscription_text_link_html('#')),
      'sheet_music_subscription_cancelled' => array('Subscription Update - Sheet Music Access Cancelled','Subscription Cancelled','<p>Your sheet music subscription has been cancelled.</p>','<div><strong>Subscription:</strong> Monthly sheet music access</div>' . '<div><strong>Status:</strong> Cancelled</div>' . '<div><strong>Cancellation date:</strong> </div>' . '<div style="margin-top:12px;">You will not be charged again unless you subscribe again in the future. You will have access to sheet music products throughout the remainder of your subscription period which ends on: </div>','Contact Support'),
      'sheet_music_subscription_renewal_reminder' => array('Subscription Renewal Reminder - Sheet Music Access','Subscription Renewal Reminder - Sheet Music Access','<p>This is a reminder that your sheet music subscription renewal is scheduled to occur on <strong></strong>.</p>','<div><strong>Subscription:</strong> Monthly Sheet Music Access</div><div><strong>Amount:</strong> $5.00 Per Month</div><div><strong>Renewal date:</strong> </div>' . $this->mrm_get_sheet_music_access_section_html(),'Contact Support',$this->mrm_email_cancel_subscription_text_link_html('#')),
      'lesson_cancellation_no_refund' => array('Lesson update — Cancellation','Lesson cancelled','<p>Your lesson has been cancelled and it does not qualify for a refund as the lesson was not cancelled the minimum 24 hours in advance.</p>','<div><strong>Cancelled lesson:</strong> </div><div><strong>Amount paid:</strong> </div><div style="margin-top:12px;">If you believe this to be a mistake please contact support.</div>','Contact Support'),
      'lesson_cancellation_refund' => array('Lesson update — Cancellation and refund issued','Lesson cancelled and refund issued','<p>Your lesson has been cancelled and a refund has been issued.</p>','<div><strong>Cancelled lesson:</strong> </div>' . '<div><strong>Refund amount:</strong> </div>' . '<div style="margin-top:12px;">You can expect the refunded amount to appear back in your account in approximately 3 to 5 business days, depending on your bank and card issuer.</div>','Contact Support'),
    );

    $payment_samples = array_merge($payment_samples, $this->mrm_payout_summary_email_test_samples());

    if (isset($payment_samples[$slug])) {
      $sample = array_pad($payment_samples[$slug], 6, '');
      list($subject, $title, $intro, $details, $button, $after_cta_html) = $sample;

      return array(
        'subject' => $subject,
        'html' => is_array($button)
          ? $this->mrm_email_wrap_html($title, $intro, $details, $button, '', $after_cta_html)
          : $this->mrm_email_wrap_html($title, $intro, $details, $contact_url, $button, $after_cta_html),
      );
    }

    $preview = apply_filters('mrm_cross_plugin_email_preview', array(), $slug);

    if (is_array($preview) && !empty($preview['html'])) {
      return array(
        'subject' => sanitize_text_field((string)($preview['subject'] ?? $slug)),
        'html' => (string)$preview['html'],
      );
    }

    return array(
      'subject' => '',
      'html' => $this->mrm_email_wrap_html('', '', '<div>No preview builder is currently available for this email type.</div>', '', ''),
    );
  }

  public function handle_email_testing_send() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html('Not allowed.'));
    }

    check_admin_referer('mrm_pay_hub_send_email_tests', 'mrm_pay_hub_email_tests_nonce');

    $to = isset($_POST['mrm_email_testing_to']) ? sanitize_email(wp_unslash($_POST['mrm_email_testing_to'])) : '';
    $selected = isset($_POST['mrm_email_tests']) && is_array($_POST['mrm_email_tests'])
      ? array_map('sanitize_key', wp_unslash($_POST['mrm_email_tests']))
      : array();
    $catalog = $this->mrm_email_testing_catalog();
    $selected = array_values(array_unique(array_intersect($selected, array_keys($catalog))));
    $redirect_args = array('page' => 'mrm-pay-hub-email-testing');

    if (!$to || !is_email($to)) {
      $redirect_args['mrm_email_tests_error'] = 'Please enter a valid destination inbox.';
      wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
      exit;
    }

    if (empty($selected)) {
      $redirect_args['mrm_email_tests_error'] = 'Please select at least one email to test.';
      wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
      exit;
    }

    $sent = 0;
    $failed = 0;
    foreach ($selected as $slug) {
      if ($this->mrm_send_single_email_test($slug, $to)) {
        $sent++;
      } else {
        $failed++;
      }
    }

    $redirect_args['mrm_email_tests_sent'] = $sent;
    $redirect_args['mrm_email_tests_failed'] = $failed;
    wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
    exit;
  }

  private function mrm_send_single_email_test($slug, $to) {
    $slug = sanitize_key((string)$slug);
    $to = sanitize_email((string)$to);

    if (!$to || !is_email($to)) {
      return false;
    }

    $headers = array(
      'Content-Type: text/html; charset=UTF-8',
      'From: Low Brass Lessons <no-reply@lowbrass-lessons.com>',
    );

    $preview = $this->mrm_build_single_email_preview($slug);

    if (!is_array($preview) || empty($preview['html'])) {
      return false;
    }

    $subject = sanitize_text_field((string)($preview['subject'] ?? $slug));
    if ($subject === '') {
      $subject = $slug;
    }

    return wp_mail(
      $to,
      '[TEST] ' . $subject,
      (string)$preview['html'],
      $headers
    );
  }

  public function render_profile_card_creation_page() {
    if (!current_user_can('manage_options')) wp_die('You do not have permission to access this page.');
    global $wpdb;
    $table = $this->table_profile_card_requests();
    $this->maybe_install_or_upgrade_db();
    $requests = $wpdb->get_results(
      "SELECT * FROM {$table}
       WHERE status IN ('sent', 'pending_review', 'changes_requested')
       ORDER BY updated_at DESC, id DESC
       LIMIT 100",
      ARRAY_A
    );

    $presenters_table = $wpdb->prefix . 'mrm_masterclass_presenters';
    $presenters = array();

    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $presenters_table)) === $presenters_table) {
      $presenters = $wpdb->get_results(
        "SELECT id, name, email FROM {$presenters_table} ORDER BY name ASC, email ASC",
        ARRAY_A
      );
    }

    $settings = $this->get_settings();
    $background_check_docusign_url = esc_url((string)($settings['background_check_docusign_url'] ?? ''));
    ?>
    <div class="wrap"><h1>Profile Card Creation</h1>
      <p class="description">Send private onboarding links to instructors and presenters. Submitted requests appear below for review before anything is created in Scheduler or Masterclass settings.</p>

      <?php
      $duplicate_email = isset($_GET['duplicate_email']) ? sanitize_email(wp_unslash($_GET['duplicate_email'])) : '';
      $duplicate_type  = isset($_GET['duplicate_type']) ? sanitize_key(wp_unslash($_GET['duplicate_type'])) : '';
      $duplicate_id    = isset($_GET['duplicate_id']) ? absint($_GET['duplicate_id']) : 0;
      $duplicate_note  = isset($_GET['duplicate_note']) ? sanitize_textarea_field(wp_unslash($_GET['duplicate_note'])) : '';
      $duplicate_days  = isset($_GET['duplicate_days']) ? absint($_GET['duplicate_days']) : 14;

      if ($duplicate_email && $duplicate_id && in_array($duplicate_type, array('instructor_profile', 'presenter_profile'), true)) :
        $duplicate_label = $duplicate_type === 'presenter_profile' ? 'presenter' : 'instructor';
      ?>
        <div class="notice notice-warning" style="padding:12px 14px;">
          <p><strong>This email is already in use.</strong> A <?php echo esc_html($duplicate_label); ?> profile already exists for <code><?php echo esc_html($duplicate_email); ?></code>.</p>
          <p>You can request an update to the existing profile card, or go back and choose a different email.</p>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
            <?php wp_nonce_field('mrm_profile_card_create_invite', 'mrm_profile_card_nonce'); ?>
            <input type="hidden" name="action" value="mrm_profile_card_create_invite">
            <input type="hidden" name="request_type" value="<?php echo esc_attr($duplicate_type); ?>">
            <input type="hidden" name="recipient_email" value="<?php echo esc_attr($duplicate_email); ?>">
            <input type="hidden" name="token_days" value="<?php echo esc_attr($duplicate_days ?: 14); ?>">
            <input type="hidden" name="admin_note" value="<?php echo esc_attr($duplicate_note); ?>">
            <input type="hidden" name="force_profile_update" value="1">
            <input type="hidden" name="existing_target_id" value="<?php echo esc_attr($duplicate_id); ?>">
            <button type="submit" class="button button-primary">Request Update to Existing Profile</button>
          </form>
          <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mrm-pay-hub-profile-card-creation')); ?>">Go Back</a>
        </div>
      <?php endif; ?>
      <?php if (isset($_GET['error'])) : ?>
        <?php
          $profile_card_error = sanitize_text_field(wp_unslash($_GET['error']));
          $profile_card_error_messages = array(
            'missing_presenter' => 'Please select an approved presenter profile before sending a Masterclass Event Proposal.',
            'invalid_presenter' => 'The selected presenter profile is missing a valid email address.',
            'invalid_recipient' => 'Please enter a valid recipient email address.',
            'missing_request' => 'The selected request could not be found.',
            'unknown_action' => 'The selected Profile Card Creation action was not recognized.',
            'missing_event_details' => 'Please enter the Masterclass title, start time, and end time before sending an event proposal.',
          );
          $profile_card_error_message = $profile_card_error_messages[$profile_card_error] ?? $profile_card_error;
        ?>
        <div class="notice notice-error">
          <p><?php echo esc_html($profile_card_error_message); ?></p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['sent'])) : ?>
        <div class="notice notice-success">
          <p>Profile Card Creation request email sent.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['approved'])) : ?>
        <div class="notice notice-success">
          <p>Request approved and the related record was created.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['changes'])) : ?>
        <div class="notice notice-success">
          <p>Change request email sent.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['deleted'])) : ?>
        <div class="notice notice-success">
          <p>Profile Card Creation request deleted. The private link has been disabled.</p>
        </div>
      <?php endif; ?>
      <form method="post" style="max-width:960px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px;margin:18px 0;">
        <?php wp_nonce_field('mrm_pay_hub_save', 'mrm_pay_hub_nonce'); ?>

        <h2>Onboarding / Background Check Settings</h2>

        <table class="form-table">
          <tr>
            <th scope="row"><label for="background_check_docusign_url">Background Check DocuSign Link</label></th>
            <td>
              <input type="url" id="background_check_docusign_url" name="background_check_docusign_url" value="<?php echo $background_check_docusign_url; ?>" class="regular-text" placeholder="https://..." />
              <p class="description">This link appears when an instructor says they do not already have a valid fingerprint clearance for their state.</p>
            </td>
          </tr>
        </table>

        <p class="submit">
          <button type="submit" class="button button-primary">Save Background Check Settings</button>
        </p>
      </form>

      <hr>
      <h2>Create New Request</h2>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:960px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px;">
        <?php wp_nonce_field('mrm_profile_card_create_invite', 'mrm_profile_card_nonce'); ?>
        <input type="hidden" name="action" value="mrm_profile_card_create_invite">

        <table class="form-table">
          <tr>
            <th scope="row"><label for="request_type">Request Type</label></th>
            <td>
              <select id="request_type" name="request_type" required>
                <option value="instructor_profile">Instructor Profile Card</option>
                <option value="presenter_profile">Presenter Profile Card</option>
                <option value="presenter_event">Masterclass Event Proposal</option>
              </select>
              <p class="description">
                Choose the type of private onboarding/request form to send. The settings below will update based on this selection.
              </p>
            </td>
          </tr>

          <tr class="mrm-profile-request-row mrm-profile-row-recipient-email" data-show-for="instructor_profile presenter_profile">
            <th scope="row"><label for="recipient_email">Recipient Email</label></th>
            <td>
              <input type="email" id="recipient_email" name="recipient_email" class="regular-text">
              <p class="description">Used for instructor and presenter profile card requests.</p>
            </td>
          </tr>

          <tr class="mrm-profile-request-row" data-show-for="instructor_profile">
            <th scope="row"><label for="instructor_calendar_url">Instructor Google Calendar Link</label></th>
            <td>
              <input type="url" id="instructor_calendar_url" name="instructor_calendar_url" class="regular-text" placeholder="https://calendar.google.com/...">
              <p class="description">Paste the Google Calendar link the instructor should open before submitting this profile card request.</p>
            </td>
          </tr>

          <tr class="mrm-profile-request-row mrm-profile-row-presenter" data-show-for="presenter_event">
            <th scope="row"><label for="presenter_id">Presenter Profile</label></th>
            <td>
              <select id="presenter_id" name="presenter_id">
                <option value="">Select an approved presenter profile</option>
                <?php foreach ($presenters as $presenter) : ?>
                  <option value="<?php echo esc_attr($presenter['id']); ?>">
                    <?php echo esc_html(trim(($presenter['name'] ?? '') . ' — ' . ($presenter['email'] ?? ''))); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <?php if (empty($presenters)) : ?>
                <p class="description" style="color:#b32d2e;">
                  No presenter profiles were found. Create and approve a Presenter Profile Card before sending a Masterclass Event Proposal.
                </p>
              <?php else : ?>
                <p class="description">
                  Masterclass Event Proposal requests must be linked to an already-created presenter profile.
                </p>
              <?php endif; ?>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="token_days">Link Expiration</label></th>
            <td>
              <input type="number" id="token_days" name="token_days" min="1" max="60" value="14" class="small-text"> days
            </td>
          </tr>

          <tr class="mrm-profile-request-row" data-show-for="presenter_event"><th scope="row"><label for="event_title">Masterclass Title</label></th><td><input type="text" id="event_title" name="event_title" class="regular-text"><p class="description">This title is preset by Low Brass Lessons and shown to the presenter on the proposal form.</p></td></tr>
          <tr class="mrm-profile-request-row" data-show-for="presenter_event">
            <th scope="row"><label for="masterclass_piece_sku">Piece Being Discussed</label></th>
            <td><?php echo $this->mrm_profile_card_piece_select_html(''); ?></td>
          </tr>
          <tr class="mrm-profile-request-row" data-show-for="presenter_event"><th scope="row"><label for="event_start_time">Masterclass Start Time</label></th><td><input type="datetime-local" id="event_start_time" name="event_start_time"><p class="description">Preset start time for the event proposal.</p></td></tr>
          <tr class="mrm-profile-request-row" data-show-for="presenter_event"><th scope="row"><label for="event_end_time">Masterclass End Time</label></th><td><input type="datetime-local" id="event_end_time" name="event_end_time"><p class="description">Preset end time for the event proposal.</p></td></tr>
          <tr class="mrm-profile-request-row" data-show-for="presenter_event"><th scope="row"><label for="event_timezone">Masterclass Timezone</label></th><td><?php echo $this->mrm_profile_card_timezone_select_html('event_timezone', 'America/Phoenix'); ?><p class="description">Preset event timezone.</p></td></tr>

          <tr class="mrm-profile-request-row mrm-profile-row-event-pay" data-show-for="presenter_event">
            <th scope="row"><label for="presenter_payout">Presenter Pay Per Student</label></th>
            <td>
              <input type="number" id="presenter_payout" name="presenter_payout" min="0" step="0.01" value="0.00" class="small-text">
              <p class="description">Shown to the presenter on the event proposal form and saved with the event request.</p>
            </td>
          </tr>

          <tr class="mrm-profile-request-row mrm-profile-row-event-price" data-show-for="presenter_event">
            <th scope="row"><label for="event_price">Masterclass Student Price</label></th>
            <td>
              <input type="number" id="event_price" name="event_price" min="0" step="0.01" value="0.00" class="small-text">
              <p class="description">The presenter cannot edit this. Set this before sending the event proposal.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="admin_note">Note to Recipient</label></th>
            <td>
              <textarea id="admin_note" name="admin_note" rows="4" class="large-text" placeholder="Optional note shown in the invite email and at the top of the private form."></textarea>
            </td>
          </tr>
        </table>

        <p>
          <button type="submit" class="button button-primary">Create and Send Request Email</button>
        </p>
      </form>

      <script>
      (function(){
        var typeSelect = document.getElementById('request_type');
        var emailInput = document.getElementById('recipient_email');
        var presenterSelect = document.getElementById('presenter_id');
        var rows = Array.prototype.slice.call(document.querySelectorAll('.mrm-profile-request-row'));

        function syncProfileRequestFields(){
          var selectedType = typeSelect ? typeSelect.value : 'instructor_profile';

          rows.forEach(function(row){
            var showFor = String(row.getAttribute('data-show-for') || '').split(/\s+/);
            var shouldShow = showFor.indexOf(selectedType) !== -1;

            row.style.display = shouldShow ? '' : 'none';

            row.querySelectorAll('input, select, textarea').forEach(function(field){
              field.disabled = !shouldShow;
            });
          });

          if (emailInput) {
            emailInput.required = selectedType === 'instructor_profile' || selectedType === 'presenter_profile';
          }

          if (presenterSelect) {
            presenterSelect.required = selectedType === 'presenter_event';
          }
        }

        if (typeSelect) {
          typeSelect.addEventListener('change', syncProfileRequestFields);
          syncProfileRequestFields();
        }
      })();
      </script>
      <hr><h2>Active Profile Card Requests</h2>
      <p class="description">Requests disappear from this list after they are approved or deleted.</p>
      <table class="widefat striped"><thead><tr><th>ID</th><th>Type</th><th>Recipient</th><th>Status</th><th>Submitted</th><th>Created Target</th><th>Actions</th></tr></thead><tbody>
      <?php if (empty($requests)) : ?><tr><td colspan="7">No active profile card requests need review.</td></tr><?php else : foreach ($requests as $request) : $payload = $this->mrm_profile_card_decode_json($request['submission_payload'] ?? ''); $uploads = $this->mrm_profile_card_decode_json($request['uploaded_files'] ?? ''); $target = !empty($request['created_target_type']) && !empty($request['created_target_id']) ? $request['created_target_type'] . ' #' . $request['created_target_id'] : '—'; ?>
        <tr><td><?php echo esc_html($request['id']); ?></td><td><?php echo esc_html($this->mrm_profile_card_request_type_label($request['request_type'])); ?></td><td><strong><?php echo esc_html($request['recipient_name']); ?></strong><br><code><?php echo esc_html($request['recipient_email']); ?></code></td><td><?php echo esc_html($request['status']); ?></td><td><?php echo esc_html($request['submitted_at'] ?: '—'); ?></td><td><?php echo esc_html($target); ?></td><td><details><summary class="button">Preview / Review</summary><div style="margin-top:12px;padding:12px;border:1px solid #dcdcde;background:#fff;"><h3>Submitted Information</h3>
        <?php if (empty($payload)) : ?><p>No submission data yet.</p><?php else : ?><table class="widefat striped"><tbody><?php foreach ($payload as $key => $value) : ?><tr><th style="width:220px;"><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?></th><td><?php echo is_array($value) ? '<pre style="white-space:pre-wrap;">' . esc_html(wp_json_encode($value, JSON_PRETTY_PRINT)) . '</pre>' : wp_kses_post(nl2br(esc_html((string)$value))); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
        <?php if (!empty($uploads)) : ?><h3>Uploaded Files</h3><ul><?php foreach ($uploads as $file) : ?><li><a href="<?php echo esc_url($file['url'] ?? ''); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($file['name'] ?? 'Uploaded file'); ?></a></li><?php endforeach; ?></ul><?php endif; ?>
        <h3>Admin Approval Fields</h3><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('mrm_profile_card_admin_action', 'mrm_profile_card_admin_nonce'); ?><input type="hidden" name="action" value="mrm_profile_card_admin_action"><input type="hidden" name="request_id" value="<?php echo esc_attr($request['id']); ?>"><p><label><input type="checkbox" name="docusign_verified" value="1"> Admin verified DocuSign contract and W-9 completion.</label></p><p><label>Approval note / internal note</label><br><textarea name="admin_review_note" rows="4" class="large-text"></textarea></p><p><label>Change request note to recipient</label><br><textarea name="change_request_note" rows="4" class="large-text" placeholder="Write what you want them to change. This will be emailed if you click Request Changes."></textarea></p><p><button type="submit" name="mrm_profile_card_do" value="approve" class="button button-primary">Approve and Create</button> <button type="submit" name="mrm_profile_card_do" value="changes" class="button">Request Changes</button> <button type="submit" name="mrm_profile_card_do" value="delete" class="button" onclick="return confirm('Delete this request? The private link will stop working and this request will be removed from the active review list.');">Delete This Request</button></p></form></div></details></td></tr>
      <?php endforeach; endif; ?></tbody></table></div><?php
  }

  public function handle_profile_card_create_invite() {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission.');
    }

    check_admin_referer('mrm_profile_card_create_invite', 'mrm_profile_card_nonce');

    global $wpdb;

    $this->maybe_install_or_upgrade_db();

    $table = $this->table_profile_card_requests();

    $request_type = sanitize_key($_POST['request_type'] ?? 'instructor_profile');
    if (!in_array($request_type, array('instructor_profile', 'presenter_profile', 'presenter_event'), true)) {
      $request_type = 'instructor_profile';
    }

    $recipient_email = '';
    $recipient_name = '';
    $selected_presenter_id = 0;

    if ($request_type === 'presenter_event') {
      $selected_presenter_id = absint($_POST['presenter_id'] ?? 0);

      if ($selected_presenter_id <= 0) {
        wp_safe_redirect(admin_url('admin.php?page=mrm-pay-hub-profile-card-creation&error=missing_presenter'));
        exit;
      }

      $presenters_table = $wpdb->prefix . 'mrm_masterclass_presenters';

      if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $presenters_table)) !== $presenters_table) {
        wp_safe_redirect(admin_url('admin.php?page=mrm-pay-hub-profile-card-creation&error=invalid_presenter'));
        exit;
      }

      $presenter = $wpdb->get_row(
        $wpdb->prepare("SELECT id, name, email FROM {$presenters_table} WHERE id = %d LIMIT 1", $selected_presenter_id),
        ARRAY_A
      );

      if (!$presenter || empty($presenter['email']) || !is_email($presenter['email'])) {
        wp_safe_redirect(admin_url('admin.php?page=mrm-pay-hub-profile-card-creation&error=invalid_presenter'));
        exit;
      }

      $recipient_email = sanitize_email($presenter['email']);
      $recipient_name = sanitize_text_field($presenter['name'] ?? '');
    } else {
      $recipient_email = sanitize_email(wp_unslash($_POST['recipient_email'] ?? ''));

      if (!is_email($recipient_email)) {
        wp_safe_redirect(admin_url('admin.php?page=mrm-pay-hub-profile-card-creation&error=invalid_recipient'));
        exit;
      }

      $recipient_name = '';
    }

    $token = $this->mrm_profile_card_new_token();
    $days = max(1, min(60, absint($_POST['token_days'] ?? 14)));
    $admin_note = sanitize_textarea_field(wp_unslash($_POST['admin_note'] ?? ''));

    $force_profile_update = !empty($_POST['force_profile_update']);
    $existing_target_id   = absint($_POST['existing_target_id'] ?? 0);

    if (!$force_profile_update && $request_type === 'instructor_profile') {
      $existing = $this->mrm_profile_card_existing_instructor_by_email($recipient_email);

      if ($existing && !empty($existing['id'])) {
        wp_safe_redirect(add_query_arg(array('page' => 'mrm-pay-hub-profile-card-creation', 'duplicate_email' => rawurlencode($recipient_email), 'duplicate_type' => 'instructor_profile', 'duplicate_id' => absint($existing['id']), 'duplicate_days' => $days, 'duplicate_note' => rawurlencode($admin_note)), admin_url('admin.php')));
        exit;
      }
    }

    if (!$force_profile_update && $request_type === 'presenter_profile') {
      $existing = $this->mrm_profile_card_existing_presenter_by_email($recipient_email);

      if ($existing && !empty($existing['id'])) {
        wp_safe_redirect(add_query_arg(array('page' => 'mrm-pay-hub-profile-card-creation', 'duplicate_email' => rawurlencode($recipient_email), 'duplicate_type' => 'presenter_profile', 'duplicate_id' => absint($existing['id']), 'duplicate_days' => $days, 'duplicate_note' => rawurlencode($admin_note)), admin_url('admin.php')));
        exit;
      }
    }

    $admin_payload = array(
      'admin_note' => $admin_note,
      'instructor_calendar_url' => esc_url_raw(wp_unslash($_POST['instructor_calendar_url'] ?? '')),
      'event_title' => sanitize_text_field(wp_unslash($_POST['event_title'] ?? '')),
      'masterclass_piece_sku' => '',
      'masterclass_piece_title' => '',
      'masterclass_piece_url' => '',
      'event_start_time' => sanitize_text_field(wp_unslash($_POST['event_start_time'] ?? '')),
      'event_end_time' => sanitize_text_field(wp_unslash($_POST['event_end_time'] ?? '')),
      'event_timezone' => sanitize_text_field(wp_unslash($_POST['event_timezone'] ?? 'America/Phoenix')),
      'selected_presenter_id' => $selected_presenter_id,
      'presenter_payout_per_student_cents' => 0,
      'event_price_cents' => 0,
      'is_profile_update' => $force_profile_update ? 1 : 0,
      'existing_target_id' => $existing_target_id,
    );

    if ($request_type === 'presenter_event') {
      $admin_payload['presenter_payout_per_student_cents'] = $this->mrm_profile_card_money_to_cents($_POST['presenter_payout'] ?? '0');
      $admin_payload['event_price_cents'] = $this->mrm_profile_card_money_to_cents($_POST['event_price'] ?? '0');
      $selected_piece = $this->mrm_profile_card_piece_from_sku(wp_unslash($_POST['masterclass_piece_sku'] ?? ''));

      $admin_payload['masterclass_piece_sku'] = sanitize_text_field((string)($selected_piece['sku'] ?? ''));
      $admin_payload['masterclass_piece_title'] = sanitize_text_field((string)($selected_piece['title'] ?? ''));
      $admin_payload['masterclass_piece_url'] = esc_url_raw((string)($selected_piece['url'] ?? ''));
      if (trim((string)$admin_payload['event_title']) === '' || trim((string)$admin_payload['event_start_time']) === '' || trim((string)$admin_payload['event_end_time']) === '') {
        wp_safe_redirect(admin_url('admin.php?page=mrm-pay-hub-profile-card-creation&error=missing_event_details'));
        exit;
      }
    }

    $now = current_time('mysql');

    $wpdb->insert($table, array('request_type' => $request_type, 'status' => 'sent', 'recipient_name' => $recipient_name, 'recipient_email' => $recipient_email, 'token_hash' => $this->mrm_profile_card_hash_token($token), 'token_expires_at' => gmdate('Y-m-d H:i:s', time() + ($days * DAY_IN_SECONDS)), 'admin_payload' => $this->mrm_profile_card_encode_json($admin_payload), 'submission_payload' => $this->mrm_profile_card_encode_json(array()), 'review_notes' => $this->mrm_profile_card_encode_json(array()), 'uploaded_files' => $this->mrm_profile_card_encode_json(array()), 'sent_at' => $now, 'created_at' => $now, 'updated_at' => $now));

    $url = add_query_arg(array('action' => 'mrm_profile_card_form', 'token' => $token), admin_url('admin-post.php'));

    $label = $this->mrm_profile_card_request_type_label($request_type);

    if ($force_profile_update) {
      $label .= ' Update';
    }

    $invite_parts = $this->mrm_profile_card_invite_email_parts(
      $request_type,
      $label,
      (string)$days . ' days',
      $admin_note,
      array(
        'sku' => $admin_payload['masterclass_piece_sku'] ?? '',
        'title' => $admin_payload['masterclass_piece_title'] ?? '',
        'url' => $admin_payload['masterclass_piece_url'] ?? '',
      )
    );

    $subject = (string)$invite_parts['subject'];

    if ($force_profile_update) {
      $subject .= ' update';
    }

    $invite_buttons = is_array($invite_parts['buttons'] ?? null) ? $invite_parts['buttons'] : array();

    if (!empty($invite_buttons[0]) && is_array($invite_buttons[0])) {
      $invite_buttons[0]['url'] = $url;
    }

    $body = $this->mrm_email_wrap_html(
      (string)$invite_parts['title'],
      (string)$invite_parts['intro'],
      (string)$invite_parts['details'],
      $invite_buttons,
      ''
    );

    wp_mail($recipient_email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));

    wp_safe_redirect(admin_url('admin.php?page=mrm-pay-hub-profile-card-creation&sent=1'));
    exit;
  }


  public function handle_profile_card_download_private_file() {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this file.');
    }

    check_admin_referer('mrm_profile_card_download_private_file');

    $file = isset($_GET['file']) ? sanitize_text_field(wp_unslash($_GET['file'])) : '';
    $name = isset($_GET['name']) ? sanitize_file_name(wp_unslash($_GET['name'])) : 'fingerprint-proof';

    if ($file === '') {
      wp_die('Missing file.');
    }

    $real_file = realpath($file);
    $private_root = realpath($this->mrm_profile_card_private_upload_dir());

    if (!$real_file || !$private_root || strpos($real_file, dirname($private_root)) !== 0 || !file_exists($real_file)) {
      wp_die('File not found or not allowed.');
    }

    nocache_headers();

    $mime = function_exists('mime_content_type') ? mime_content_type($real_file) : 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('Content-Length: ' . filesize($real_file));

    readfile($real_file);
    exit;
  }

  public function render_profile_card_public_form() {
    $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
    $request = $this->mrm_profile_card_get_by_token($token);

    if (!$request) {
      wp_die('This profile card request link is invalid.');
    }

    if (!empty($request['token_expires_at']) && strtotime($request['token_expires_at']) < time()) {
      wp_die('This profile card request link has expired. Please contact Low Brass Lessons for a new link.');
    }

    $request_type = sanitize_key($request['request_type']);
    $admin_payload = $this->mrm_profile_card_decode_json($request['admin_payload'] ?? '');
    $submission = $this->mrm_profile_card_decode_json($request['submission_payload'] ?? '');

    nocache_headers();

    ?>
    <!doctype html>
    <html>
    <head>
      <meta charset="utf-8">
      <title><?php echo esc_html($this->mrm_profile_card_request_type_label($request_type)); ?></title>
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <style>
        body { margin: 0; background: #fbf8f2; color: #171512; font-family: "Source Sans 3", Arial, Helvetica, sans-serif; }
        .mrm-public-wrap { max-width: 860px; margin: 0 auto; padding: 44px 18px 72px; }
        .mrm-public-card { background: #fff; border: 1px solid #d9cfbe; border-radius: 24px; padding: clamp(22px, 4vw, 38px); box-shadow: 0 18px 40px rgba(25,22,18,.07); }
        h1, h2, h3 { font-family: "Academico", Georgia, "Times New Roman", serif; font-weight: 600; letter-spacing: -.02em; }
        label { display: block; font-weight: 800; margin: 14px 0 6px; }
        .mrm-field-help { margin: 4px 0 8px; color: #5f5851; font-size: 13px; line-height: 1.45; }
        input, select, textarea { width: 100%; box-sizing: border-box; border: 1px solid #d9cfbe; border-radius: 14px; padding: 12px 14px; font: inherit; }
        textarea { min-height: 120px; }
        input[type="checkbox"] { appearance: none; -webkit-appearance: none; width: 18px; height: 18px; min-width: 18px; min-height: 18px; margin: 1px 10px 0 0; padding: 0; border: 2px solid #171512; border-radius: 5px; background: #ffffff; display: inline-grid; place-content: center; cursor: pointer; vertical-align: top; }
        input[type="checkbox"]::after { content: ""; width: 9px; height: 5px; border-left: 2px solid #ffffff; border-bottom: 2px solid #ffffff; transform: rotate(-45deg); opacity: 0; margin-top: -1px; }
        input[type="checkbox"]:checked { background: #171512; border-color: #171512; }
        input[type="checkbox"]:checked::after { opacity: 1; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .pay-box, .mrm-profile-public-pay-box, .mrm-check-section { background: #f6f1e7; border: 1px solid #d9cfbe; border-radius: 18px; padding: 16px; margin: 18px 0; }
        .mrm-check-section h3 { margin: 0 0 10px; }
        .mrm-check-grid { display: grid; gap: 8px; margin-top: 10px; }
        .mrm-check-row, .mrm-ack-row { display: flex; align-items: flex-start; gap: 10px; margin: 0; padding: 10px 12px; border: 1px solid #d9cfbe; border-radius: 14px; background: #ffffff; color: #171512; font-weight: 700; line-height: 1.4; }
        .mrm-ack-row { margin-top: 14px; }
        .mrm-check-row input, .mrm-ack-row input { flex: 0 0 auto; }
        table { width: 100%; border-collapse: collapse; background: #fff; margin-top: 10px; }
        th, td { border: 1px solid #d9cfbe; padding: 8px; text-align: left; }
        button { border: 0; border-radius: 999px; background: #171512; color: #fff; padding: 14px 22px; font-weight: 900; cursor: pointer; }
        .muted { color: #5f5851; }
        @media(max-width:720px) { .grid { grid-template-columns: 1fr; } }
      </style>
    </head>
    <body><div class="mrm-public-wrap"><div class="mrm-public-card">
      <h1><?php echo esc_html($this->mrm_profile_card_request_type_label($request_type)); ?></h1>
      <?php if (!empty($admin_payload['admin_note'])) : ?><p class="muted"><?php echo esc_html($admin_payload['admin_note']); ?></p><?php endif; ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field('mrm_profile_card_public_submit', 'mrm_profile_card_public_nonce'); ?>
        <input type="hidden" name="action" value="mrm_profile_card_submit"><input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
        <?php if ($request_type === 'presenter_event') : ?>
          <h2>Event Details</h2>
          <div class="pay-box">
            <p><strong>Masterclass Title:</strong> <?php echo esc_html($admin_payload['event_title'] ?? ''); ?></p>

            <?php if (!empty($admin_payload['masterclass_piece_title'])) : ?>
              <p><strong>Piece Being Discussed:</strong> <?php echo esc_html($admin_payload['masterclass_piece_title']); ?></p>
              <?php if (!empty($admin_payload['masterclass_piece_url'])) : ?>
                <p><strong>Piece Page:</strong> <a href="<?php echo esc_url($admin_payload['masterclass_piece_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($admin_payload['masterclass_piece_url']); ?></a></p>
              <?php endif; ?>
            <?php endif; ?>

            <p><strong>Start Time:</strong> <?php echo esc_html($admin_payload['event_start_time'] ?? ''); ?></p>
            <p><strong>End Time:</strong> <?php echo esc_html($admin_payload['event_end_time'] ?? ''); ?></p>
            <p><strong>Timezone:</strong> <?php echo esc_html($admin_payload['event_timezone'] ?? 'America/Phoenix'); ?></p>
          </div>

          <input type="hidden" name="event_title" value="<?php echo esc_attr($admin_payload['event_title'] ?? ''); ?>">
          <input type="hidden" name="masterclass_piece_sku" value="<?php echo esc_attr($admin_payload['masterclass_piece_sku'] ?? ''); ?>">
          <input type="hidden" name="masterclass_piece_title" value="<?php echo esc_attr($admin_payload['masterclass_piece_title'] ?? ''); ?>">
          <input type="hidden" name="masterclass_piece_url" value="<?php echo esc_attr($admin_payload['masterclass_piece_url'] ?? ''); ?>">
          <input type="hidden" name="start_time" value="<?php echo esc_attr($admin_payload['event_start_time'] ?? ''); ?>">
          <input type="hidden" name="end_time" value="<?php echo esc_attr($admin_payload['event_end_time'] ?? ''); ?>">
          <input type="hidden" name="timezone" value="<?php echo esc_attr($admin_payload['event_timezone'] ?? 'America/Phoenix'); ?>">
          <label>Short Description *</label><p class="mrm-field-help">One sentence to describe your masterclass.</p><textarea name="short_description" required><?php echo esc_textarea($submission['short_description'] ?? ''); ?></textarea>
          <label>Long Description *</label><p class="mrm-field-help">A longer description of the masterclass content, who it is for, and what students will learn.</p><textarea name="long_description" required><?php echo esc_textarea($submission['long_description'] ?? ''); ?></textarea>
          <div class="pay-box"><p><strong>Student registration price:</strong> $<?php echo esc_html($this->mrm_profile_card_cents_to_money($admin_payload['event_price_cents'] ?? 0)); ?></p><p><strong>Agreed presenter earnings:</strong> $<?php echo esc_html($this->mrm_profile_card_cents_to_money($admin_payload['presenter_payout_per_student_cents'] ?? 0)); ?> per student enrolled.</p><label class="mrm-ack-row"><input type="checkbox" name="pay_ack" value="1" required <?php checked(!empty($submission['pay_ack'])); ?>><span>I acknowledge the agreed event price and presenter earnings shown above.</span></label></div>
        <?php else : ?>
          <h2>Profile Information</h2>
          <div class="grid"><div><label>First Name *</label><input type="text" name="first_name" value="<?php echo esc_attr($submission['first_name'] ?? ''); ?>" autocomplete="given-name" required></div><div><label>Last Name *</label><input type="text" name="last_name" value="<?php echo esc_attr($submission['last_name'] ?? ''); ?>" autocomplete="family-name" required></div></div><input type="hidden" name="name" value="<?php echo esc_attr($submission['name'] ?? ''); ?>"><div class="grid"><div><label>Email *</label><input type="email" name="email" value="<?php echo esc_attr($submission['email'] ?? $request['recipient_email']); ?>" autocomplete="email" required></div></div>
          <div class="grid"><div><label>City *</label><input type="text" name="city" value="<?php echo esc_attr($submission['city'] ?? ''); ?>" required></div><div><label>State *</label><?php echo $this->mrm_profile_card_state_select_html('state', $submission['state'] ?? ''); ?></div></div>
          <label>Street Address *</label><input type="text" name="address" value="<?php echo esc_attr($submission['address'] ?? ''); ?>" required><label>ZIP Code *</label><input type="text" name="zip_code" value="<?php echo esc_attr($submission['zip_code'] ?? ''); ?>" required>
          <?php if ($request_type === 'instructor_profile') : ?>
            <div class="mrm-check-section"><h3>Teaching Formats</h3><p class="mrm-field-help">Select the lesson formats you are available to teach.</p><div class="mrm-check-grid"><label class="mrm-check-row"><input type="checkbox" name="offers_online" value="1" <?php checked(!empty($submission['offers_online'])); ?>><span>Online lessons</span></label><label class="mrm-check-row"><input type="checkbox" name="offers_in_person" value="1" <?php checked(!empty($submission['offers_in_person'])); ?>><span>In-person lessons</span></label></div></div>
            <div class="mrm-check-section"><h3>Instruments</h3><p class="mrm-field-help">Select each instrument you are available to teach.</p><div class="mrm-check-grid"><label class="mrm-check-row"><input type="checkbox" name="instruments[]" value="trombone" <?php checked(in_array('trombone', (array)($submission['instruments'] ?? array()), true)); ?>><span>Trombone</span></label><label class="mrm-check-row"><input type="checkbox" name="instruments[]" value="euphonium" <?php checked(in_array('euphonium', (array)($submission['instruments'] ?? array()), true)); ?>><span>Euphonium</span></label><label class="mrm-check-row"><input type="checkbox" name="instruments[]" value="tuba" <?php checked(in_array('tuba', (array)($submission['instruments'] ?? array()), true)); ?>><span>Tuba</span></label></div></div>
            <div class="mrm-check-section"><h3>Google Calendar Availability</h3><?php if (!empty($admin_payload['instructor_calendar_url'])) : ?><p><a class="mrm-btn" href="<?php echo esc_url($admin_payload['instructor_calendar_url']); ?>" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#171512;color:#fff;text-decoration:none;padding:12px 18px;font-weight:900;">Open Your Instructor Calendar</a></p><?php endif; ?><p class="mrm-field-help">Before submitting this form, please open your instructor calendar and enter your recurring lesson availability.</p><ol><li>Create a new event during a time you are available to teach.</li><li>Title the event something clear, such as “Lesson Availability,” “Availability,” or a similar title that helps you recognize it.</li><li>Set the event status as free/available so it does not block unrelated personal events.</li><li>Repeat the availability event for any weekly recurring teaching windows.</li><li>When Low Brass Lessons schedules a lesson inside your availability window, lesson events will appear in yellow.</li></ol><?php foreach ($this->mrm_profile_card_availability_guide_image_urls() as $guide_image_url) : ?><p><img src="<?php echo esc_url($guide_image_url); ?>" alt="Google Calendar availability guide screenshot" style="max-width:100%;height:auto;border:1px solid #d9cfbe;border-radius:14px;"></p><?php endforeach; ?><label class="mrm-ack-row"><input type="checkbox" name="calendar_availability_completed" value="1" required <?php checked(!empty($submission['calendar_availability_completed'])); ?>><span>I confirm that I opened my instructor calendar and entered my available teaching times.</span></label></div>
          <?php endif; ?>
          <?php if ($request_type === 'presenter_profile') : ?><label>Presenter Title *</label><p class="mrm-field-help">Example: Professor of Trombone, Low Brass Specialist, Orchestral Tubist, Chamber Music Clinician, or Specialist in Brass Pedagogy.</p><input type="text" name="presenter_title" value="<?php echo esc_attr($submission['presenter_title'] ?? ''); ?>" required><?php endif; ?>
          <label>Short Description *</label><p class="mrm-field-help"><?php echo $request_type === 'instructor_profile' ? 'One sentence to describe your lessons.' : 'One sentence to describe your teaching style or professional focus.'; ?></p><textarea name="short_description" required><?php echo esc_textarea($submission['short_description'] ?? ''); ?></textarea>
          <label>Long Description *</label><p class="mrm-field-help"><?php echo $request_type === 'instructor_profile' ? 'A longer description of your lesson approach, teaching background, and what students can expect.' : 'A longer professional description of your background, expertise, teaching style, and artistic work.'; ?></p><textarea name="long_description" required><?php echo esc_textarea($submission['long_description'] ?? ''); ?></textarea>
          <div class="mrm-check-section"><h3>Optional Social / Professional Links</h3><p class="mrm-field-help">Add any public links you would like Low Brass Lessons to review for your profile. These are optional.</p><label>Website</label><input type="url" name="website_url" value="<?php echo esc_attr($submission['website_url'] ?? ''); ?>" placeholder="https://..."><label>Instagram</label><input type="url" name="instagram_url" value="<?php echo esc_attr($submission['instagram_url'] ?? ''); ?>" placeholder="https://..."><label>Facebook</label><input type="url" name="facebook_url" value="<?php echo esc_attr($submission['facebook_url'] ?? ''); ?>" placeholder="https://..."><label>YouTube</label><input type="url" name="youtube_url" value="<?php echo esc_attr($submission['youtube_url'] ?? ''); ?>" placeholder="https://..."><label>LinkedIn</label><input type="url" name="linkedin_url" value="<?php echo esc_attr($submission['linkedin_url'] ?? ''); ?>" placeholder="https://..."></div>
          <label>Profile Image Upload</label><p class="mrm-field-help">Upload the photo you would like used on your public profile card. Low Brass Lessons will review and approve the image before publishing.</p><input type="file" name="profile_image_file" accept="image/*"><input type="hidden" name="existing_profile_image_url" value="<?php echo esc_attr($submission['profile_image_url'] ?? ''); ?>">
          <?php if (!empty($submission['profile_image_url'])) : ?><p class="mrm-field-help">A profile image has already been uploaded. Upload a new image only if you want to replace it.</p><?php endif; ?>
          <?php if ($request_type === 'instructor_profile') : ?>
            <?php
              $settings = $this->get_settings();
              $background_check_docusign_url = trim((string)($settings['background_check_docusign_url'] ?? ''));
              $fingerprint_status = sanitize_key((string)($submission['fingerprint_clearance_status'] ?? ''));
            ?>
            <div class="mrm-check-section" id="mrm-fingerprint-clearance-section">
              <h3>Fingerprint Clearance / Background Check</h3>
              <p class="mrm-field-help">Do you have a valid fingerprint clearance for your state?</p>
              <div class="mrm-check-grid"><label class="mrm-check-row"><input type="radio" name="fingerprint_clearance_status" value="yes" required <?php checked($fingerprint_status, 'yes'); ?>><span>Yes — I have a valid fingerprint clearance for my state.</span></label><label class="mrm-check-row"><input type="radio" name="fingerprint_clearance_status" value="no" required <?php checked($fingerprint_status, 'no'); ?>><span>No — I need to complete the background-check documents.</span></label></div>
              <div class="mrm-conditional-panel" data-fingerprint-panel="yes"><label>Fingerprint Clearance Proof *</label><p class="mrm-field-help">Upload a clear photo or PDF of your current fingerprint clearance card/document.</p><input type="file" name="fingerprint_card" accept="image/*,.pdf"><?php if (!empty($submission['fingerprint_card_name'])) : ?><p class="mrm-field-help">Current file on record: <?php echo esc_html($submission['fingerprint_card_name']); ?>. Upload a new file only if you want to replace it.</p><?php endif; ?></div>
              <div class="mrm-conditional-panel" data-fingerprint-panel="no"><p class="mrm-field-help">Please complete the required background-check authorization documents before approval.</p><?php if ($background_check_docusign_url !== '') : ?><p><a href="<?php echo esc_url($background_check_docusign_url); ?>" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#171512;color:#fff;text-decoration:none;padding:12px 18px;font-weight:900;">Open Background Check Documents</a></p><?php else : ?><p class="mrm-field-help"><strong>Background-check document link has not been configured yet.</strong> Low Brass Lessons will send the document link separately.</p><?php endif; ?><label class="mrm-ack-row"><input type="checkbox" name="background_check_docusign_ack" value="1" <?php checked(!empty($submission['background_check_docusign_ack'])); ?>><span>I understand that Low Brass Lessons requires background-check documentation before my instructor profile can be approved.</span></label></div>
            </div>
          <?php endif; ?>
          <label class="mrm-ack-row"><input type="checkbox" name="docusign_completed" value="1" required <?php checked(!empty($submission['docusign_completed'])); ?>><span>I confirm that I have completed the required DocuSign agreement and W-9 process.</span></label><label class="mrm-ack-row"><input type="checkbox" name="stripe_onboarding_completed" value="1" required <?php checked(!empty($submission['stripe_onboarding_completed'])); ?>><span>I confirm that I have completed the required Stripe account linking/onboarding step provided by Low Brass Lessons.</span></label>
          <?php if ($request_type === 'instructor_profile') : ?><?php echo $this->mrm_profile_card_render_instructor_pay_chart_html(); ?><label class="mrm-ack-row"><input type="checkbox" name="pay_ack" value="1" required <?php checked(!empty($submission['pay_ack'])); ?>><span>I acknowledge the instructor payout chart shown above.</span></label><?php endif; ?>
        <?php endif; ?>
        <p style="margin-top:24px;"><button type="submit">Submit Your Request</button></p>
      </form>
    </div></div>
    <script>
    (function(){
      var form = document.querySelector('form');
      if (!form) return;
      var first = form.querySelector('[name="first_name"]');
      var last = form.querySelector('[name="last_name"]');
      var full = form.querySelector('[name="name"]');
      function syncName(){ if (!full) return; var f = first ? first.value.trim() : ''; var l = last ? last.value.trim() : ''; full.value = (f + ' ' + l).trim(); }
      if (first) first.addEventListener('input', syncName);
      if (last) last.addEventListener('input', syncName);
      var clearanceRadios = Array.prototype.slice.call(form.querySelectorAll('[name="fingerprint_clearance_status"]'));
      var panels = Array.prototype.slice.call(form.querySelectorAll('[data-fingerprint-panel]'));
      var fingerprintFile = form.querySelector('[name="fingerprint_card"]');
      var backgroundAck = form.querySelector('[name="background_check_docusign_ack"]');
      function selectedClearanceStatus(){ var selected = clearanceRadios.find(function(radio){ return radio.checked; }); return selected ? selected.value : ''; }
      function syncFingerprintPanels(){ var status = selectedClearanceStatus(); panels.forEach(function(panel){ var shouldShow = panel.getAttribute('data-fingerprint-panel') === status; panel.style.display = shouldShow ? 'block' : 'none'; }); if (fingerprintFile) { if (status === 'yes') { fingerprintFile.setAttribute('required', 'required'); } else { fingerprintFile.removeAttribute('required'); } } if (backgroundAck) { if (status === 'no') { backgroundAck.setAttribute('required', 'required'); } else { backgroundAck.removeAttribute('required'); } } }
      clearanceRadios.forEach(function(radio){ radio.addEventListener('change', syncFingerprintPanels); });
      form.addEventListener('submit', function(){ syncName(); syncFingerprintPanels(); });
      syncName();
      syncFingerprintPanels();
    })();
    </script>
    </body></html>
    <?php
    exit;
  }

  public function handle_profile_card_public_submit() {
    check_admin_referer('mrm_profile_card_public_submit', 'mrm_profile_card_public_nonce');

    global $wpdb;

    $table = $this->table_profile_card_requests();

    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
    $request = $this->mrm_profile_card_get_by_token($token);

    if (!$request) {
      wp_die('Invalid request link.');
    }

    $request_type = sanitize_key($request['request_type']);
    $existing_submission = $this->mrm_profile_card_decode_json($request['submission_payload'] ?? '');
    $payload = array();

    if ($request_type === 'presenter_event') {
      $payload = array(
        'event_title' => sanitize_text_field(wp_unslash($_POST['event_title'] ?? '')),
        'masterclass_piece_sku' => sanitize_text_field(wp_unslash($_POST['masterclass_piece_sku'] ?? '')),
        'masterclass_piece_title' => sanitize_text_field(wp_unslash($_POST['masterclass_piece_title'] ?? '')),
        'masterclass_piece_url' => esc_url_raw(wp_unslash($_POST['masterclass_piece_url'] ?? '')),
        'short_description' => wp_kses_post(wp_unslash($_POST['short_description'] ?? '')),
        'long_description' => wp_kses_post(wp_unslash($_POST['long_description'] ?? '')),
        'start_time' => sanitize_text_field(wp_unslash($_POST['start_time'] ?? '')),
        'end_time' => sanitize_text_field(wp_unslash($_POST['end_time'] ?? '')),
        'timezone' => sanitize_text_field(wp_unslash($_POST['timezone'] ?? 'America/Phoenix')),
        'pay_ack' => !empty($_POST['pay_ack']) ? 1 : 0,
      );
    } else {
      $instruments = array();

      if (isset($_POST['instruments']) && is_array($_POST['instruments'])) {
        $instruments = array_map('sanitize_key', wp_unslash($_POST['instruments']));
      }

      $payload = array('first_name' => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')), 'last_name' => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')), 'name' => trim(sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')) . ' ' . sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''))), 'email' => sanitize_email(wp_unslash($_POST['email'] ?? '')), 'city' => sanitize_text_field(wp_unslash($_POST['city'] ?? '')), 'state' => strtoupper(substr(sanitize_text_field(wp_unslash($_POST['state'] ?? '')), 0, 2)), 'address' => sanitize_text_field(wp_unslash($_POST['address'] ?? '')), 'zip_code' => sanitize_text_field(wp_unslash($_POST['zip_code'] ?? '')), 'offers_online' => !empty($_POST['offers_online']) ? 1 : 0, 'offers_in_person' => !empty($_POST['offers_in_person']) ? 1 : 0, 'instruments' => $instruments, 'presenter_title' => sanitize_text_field(wp_unslash($_POST['presenter_title'] ?? '')), 'short_description' => wp_kses_post(wp_unslash($_POST['short_description'] ?? '')), 'long_description' => wp_kses_post(wp_unslash($_POST['long_description'] ?? '')), 'profile_image_url' => esc_url_raw(wp_unslash($_POST['existing_profile_image_url'] ?? '')), 'fingerprint_clearance_status' => sanitize_key(wp_unslash($_POST['fingerprint_clearance_status'] ?? '')), 'background_check_docusign_ack' => !empty($_POST['background_check_docusign_ack']) ? 1 : 0, 'docusign_completed' => !empty($_POST['docusign_completed']) ? 1 : 0, 'stripe_onboarding_completed' => !empty($_POST['stripe_onboarding_completed']) ? 1 : 0, 'pay_ack' => !empty($_POST['pay_ack']) ? 1 : 0, 'calendar_availability_completed' => !empty($_POST['calendar_availability_completed']) ? 1 : 0, 'website_url' => esc_url_raw(wp_unslash($_POST['website_url'] ?? '')), 'instagram_url' => esc_url_raw(wp_unslash($_POST['instagram_url'] ?? '')), 'facebook_url' => esc_url_raw(wp_unslash($_POST['facebook_url'] ?? '')), 'youtube_url' => esc_url_raw(wp_unslash($_POST['youtube_url'] ?? '')), 'linkedin_url' => esc_url_raw(wp_unslash($_POST['linkedin_url'] ?? '')), 'profile_social_links_json' => wp_json_encode(array('website' => esc_url_raw(wp_unslash($_POST['website_url'] ?? '')), 'instagram' => esc_url_raw(wp_unslash($_POST['instagram_url'] ?? '')), 'facebook' => esc_url_raw(wp_unslash($_POST['facebook_url'] ?? '')), 'youtube' => esc_url_raw(wp_unslash($_POST['youtube_url'] ?? '')), 'linkedin' => esc_url_raw(wp_unslash($_POST['linkedin_url'] ?? '')))));

      $profile_image_url = $this->mrm_profile_card_handle_profile_image_upload('profile_image_file');

      if ($profile_image_url !== '') {
        $payload['profile_image_url'] = $profile_image_url;
      }

      if ($request_type === 'instructor_profile') {
        $clearance_status = sanitize_key((string)($payload['fingerprint_clearance_status'] ?? ''));
        if (!in_array($clearance_status, array('yes', 'no'), true)) { wp_die('Please answer whether you have a valid fingerprint clearance for your state.'); }
        $has_existing_fingerprint_file = !empty($existing_submission['fingerprint_card_file']);
        if ($clearance_status === 'yes' && !$has_existing_fingerprint_file && empty($_FILES['fingerprint_card']['name'])) { wp_die('Please upload proof of your fingerprint clearance.'); }
        if ($clearance_status === 'no' && empty($payload['background_check_docusign_ack'])) { wp_die('Please acknowledge the background-check document requirement.'); }
        foreach (array('fingerprint_card_file', 'fingerprint_card_name', 'fingerprint_card_uploaded_at') as $existing_file_key) {
          if (!empty($existing_submission[$existing_file_key]) && empty($payload[$existing_file_key])) {
            $payload[$existing_file_key] = sanitize_text_field((string)$existing_submission[$existing_file_key]);
          }
        }
      }
    }

    $now = current_time('mysql');

    $update_data = array('status' => 'pending_review', 'submission_payload' => $this->mrm_profile_card_encode_json($payload), 'submitted_at' => $now, 'updated_at' => $now);

    $uploads = $this->mrm_profile_card_decode_json($request['uploaded_files'] ?? '');

    $fingerprint_upload = array();

    if (
      $request_type === 'instructor_profile'
      && (($payload['fingerprint_clearance_status'] ?? '') === 'yes')
      && !empty($_FILES['fingerprint_card']['name'])
    ) {
      $fingerprint_upload = $this->mrm_profile_card_handle_private_fingerprint_upload('fingerprint_card');
    }

    if (!empty($fingerprint_upload['file'])) {
      $uploads[] = $fingerprint_upload;
      $payload['fingerprint_card_file'] = $fingerprint_upload['file'];
      $payload['fingerprint_card_name'] = $fingerprint_upload['name'];
      $payload['fingerprint_card_uploaded_at'] = $fingerprint_upload['uploaded_at'];
      $update_data['submission_payload'] = $this->mrm_profile_card_encode_json($payload);
      $update_data['uploaded_files'] = $this->mrm_profile_card_encode_json($uploads);
    }


    $wpdb->update($table, $update_data, array('id' => absint($request['id'])));

    wp_die('Thank you. Your request has been submitted to Low Brass Lessons for review.');
  }

  public function handle_profile_card_admin_action() {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission.');
    }

    check_admin_referer('mrm_profile_card_admin_action', 'mrm_profile_card_admin_nonce');

    global $wpdb;
    $table = $this->table_profile_card_requests();

    $request_id = absint($_POST['request_id'] ?? 0);
    $do = sanitize_key($_POST['mrm_profile_card_do'] ?? '');

    $request = $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $request_id),
      ARRAY_A
    );

    if (!$request) {
      wp_safe_redirect(admin_url('admin.php?page=mrm-pay-hub-profile-card-creation&error=missing_request'));
      exit;
    }

    if ($do === 'delete') {
      $delete_note = sanitize_textarea_field(wp_unslash($_POST['admin_review_note'] ?? ''));

      $existing_notes = $this->mrm_profile_card_decode_json($request['review_notes'] ?? '');

      $existing_notes['deleted_note'] = $delete_note;
      $existing_notes['deleted_at'] = current_time('mysql');
      $existing_notes['deleted_by'] = get_current_user_id();

      $wpdb->update(
        $table,
        array(
          'status' => 'deleted',
          'token_hash' => $this->mrm_profile_card_hash_token($this->mrm_profile_card_new_token()),
          'token_expires_at' => current_time('mysql'),
          'review_notes' => $this->mrm_profile_card_encode_json($existing_notes),
          'updated_at' => current_time('mysql'),
        ),
        array('id' => $request_id)
      );

      wp_safe_redirect(admin_url('admin.php?page=mrm-pay-hub-profile-card-creation&deleted=1'));
      exit;
    }

    if ($do === 'changes') {
      $note = sanitize_textarea_field(wp_unslash($_POST['change_request_note'] ?? ''));

      $wpdb->update(
        $table,
        array(
          'status' => 'changes_requested',
          'review_notes' => $this->mrm_profile_card_encode_json(array('change_request_note' => $note)),
          'updated_at' => current_time('mysql'),
        ),
        array('id' => $request_id)
      );

      $intro_html = '<p>Hello,</p>';
      $intro_html .= '<p>Low Brass Lessons has reviewed your submitted profile card or event request and requested changes before approval.</p>';

      $details_html = '<div><strong>Requested changes:</strong></div>';
      $details_html .= '<div>' . ($note !== '' ? nl2br(esc_html($note)) : 'Please review and update the requested form details.') . '</div>';
      $details_html .= '<div style="margin-top:12px;">Please use your original private link to update and resubmit your request.</div>';

      $changes_body = $this->mrm_email_wrap_html(
        'Profile Card Changes Requested',
        $intro_html,
        $details_html,
        '',
        ''
      );

      wp_mail(
        $request['recipient_email'],
        'Low Brass Lessons requested changes',
        $changes_body,
        array('Content-Type: text/html; charset=UTF-8')
      );

      wp_safe_redirect(admin_url('admin.php?page=mrm-pay-hub-profile-card-creation&changes=1'));
      exit;
    }

    if ($do === 'approve') {
      $result = $this->mrm_profile_card_approve_request($request);

      if (is_wp_error($result)) {
        wp_safe_redirect(admin_url('admin.php?page=mrm-pay-hub-profile-card-creation&error=' . rawurlencode($result->get_error_message())));
        exit;
      }

      $wpdb->update(
        $table,
        array(
          'status' => 'approved',
          'created_target_type' => $result['target_type'],
          'created_target_id' => $result['target_id'],
          'review_notes' => $this->mrm_profile_card_encode_json(array(
            'admin_review_note' => sanitize_textarea_field(wp_unslash($_POST['admin_review_note'] ?? '')),
            'docusign_verified' => !empty($_POST['docusign_verified']) ? 1 : 0,
          )),
          'reviewed_at' => current_time('mysql'),
          'updated_at' => current_time('mysql'),
        ),
        array('id' => $request_id)
      );

      wp_safe_redirect(admin_url('admin.php?page=mrm-pay-hub-profile-card-creation&approved=1'));
      exit;
    }

    wp_safe_redirect(admin_url('admin.php?page=mrm-pay-hub-profile-card-creation&error=unknown_action'));
    exit;
  }

  private function mrm_profile_card_approve_request($request) {
    $type = sanitize_key($request['request_type'] ?? '');

    if ($type === 'presenter_profile') {
      return $this->mrm_profile_card_create_presenter($request);
    }

    if ($type === 'presenter_event') {
      return $this->mrm_profile_card_create_masterclass_event($request);
    }

    return $this->mrm_profile_card_create_instructor($request);
  }

  private function mrm_profile_card_create_instructor($request) {
    global $wpdb;

    $payload = $this->mrm_profile_card_decode_json($request['submission_payload'] ?? '');
    $admin_payload = $this->mrm_profile_card_decode_json($request['admin_payload'] ?? '');
    $is_profile_update = !empty($admin_payload['is_profile_update']);
    $existing_target_id = absint($admin_payload['existing_target_id'] ?? 0);
    $table = $wpdb->prefix . 'mrm_instructors';

    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) {
      return new WP_Error('missing_scheduler_table', 'Scheduler instructor table is missing.');
    }

    $name = sanitize_text_field($payload['name'] ?? '');
    $email = sanitize_email($payload['email'] ?? '');

    if ($name === '' || !is_email($email)) {
      return new WP_Error('invalid_instructor_payload', 'Instructor name or email is missing.');
    }

    $data = array(
      'name' => $name,
      'email' => $email,
      'city' => sanitize_text_field($payload['city'] ?? ''),
      'state' => strtoupper(substr(sanitize_text_field($payload['state'] ?? ''), 0, 2)),
      'address' => sanitize_text_field($payload['address'] ?? ''),
      'zip_code' => sanitize_text_field($payload['zip_code'] ?? ''),
      'offers_in_person' => !empty($payload['offers_in_person']) ? 1 : 0,
      'offers_online' => !empty($payload['offers_online']) ? 1 : 0,
      'profile_image_url' => esc_url_raw($payload['profile_image_url'] ?? ''),
      'short_description' => sanitize_text_field($payload['short_description'] ?? ''),
      'long_description' => wp_kses_post($payload['long_description'] ?? ''),
      'instruments' => wp_json_encode(array_values((array)($payload['instruments'] ?? array()))),
      'calendar_id' => $email,
      'timezone' => 'America/Phoenix',
      'stripe_connected_account_id' => null,
      'hire_date' => current_time('Y-m-d'),
    );

    $this->mrm_profile_card_add_profile_metadata_to_record($table, $data, $payload);

    if ($is_profile_update && $existing_target_id > 0) {
      $updated = $wpdb->update($table, $data, array('id' => $existing_target_id));

      if ($updated === false) {
        return new WP_Error('instructor_update_failed', 'Instructor update failed: ' . $wpdb->last_error);
      }

      return array(
        'target_type' => 'instructor',
        'target_id' => $existing_target_id,
      );
    }

    $inserted = $wpdb->insert($table, $data);

    if (!$inserted) {
      return new WP_Error('instructor_insert_failed', 'Instructor creation failed: ' . $wpdb->last_error);
    }

    return array(
      'target_type' => 'instructor',
      'target_id' => absint($wpdb->insert_id),
    );
  }

  private function mrm_profile_card_create_presenter($request) {
    global $wpdb;

    $payload = $this->mrm_profile_card_decode_json($request['submission_payload'] ?? '');
    $admin_payload = $this->mrm_profile_card_decode_json($request['admin_payload'] ?? '');
    $is_profile_update = !empty($admin_payload['is_profile_update']);
    $existing_target_id = absint($admin_payload['existing_target_id'] ?? 0);

    $table = $wpdb->prefix . 'mrm_masterclass_presenters';

    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) {
      return new WP_Error('missing_presenter_table', 'Masterclass presenter table is missing.');
    }

    $name = sanitize_text_field($payload['name'] ?? '');
    $email = sanitize_email($payload['email'] ?? '');

    if ($name === '' || !is_email($email)) {
      return new WP_Error('invalid_presenter_payload', 'Presenter name or email is missing.');
    }

    $data = array(
      'name' => $name,
      'email' => $email,
      'city' => sanitize_text_field($payload['city'] ?? ''),
      'state' => strtoupper(substr(sanitize_text_field($payload['state'] ?? ''), 0, 2)),
      'address' => sanitize_text_field($payload['address'] ?? ''),
      'zip_code' => sanitize_text_field($payload['zip_code'] ?? ''),
      'timezone' => 'America/Phoenix',
      'stripe_connected_account_id' => '',
      'payout_percent' => 0,
      'payout_per_student_cents' => 0,
      'hire_date' => current_time('Y-m-d'),
      'profile_image_url' => esc_url_raw($payload['profile_image_url'] ?? ''),
      'short_description' => wp_kses_post($payload['short_description'] ?? ''),
      'long_description' => wp_kses_post($payload['long_description'] ?? ''),
      'bio' => wp_kses_post($payload['long_description'] ?? ''),
      'presenter_title' => sanitize_text_field($payload['presenter_title'] ?? ''),
      'instruments' => wp_json_encode(array()),
      'created_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
    );

    $this->mrm_profile_card_add_profile_metadata_to_record($table, $data, $payload);

    if ($is_profile_update && $existing_target_id > 0) {
      $updated = $wpdb->update($table, $data, array('id' => $existing_target_id));

      if ($updated === false) {
        return new WP_Error('presenter_update_failed', 'Presenter update failed: ' . $wpdb->last_error);
      }

      return array(
        'target_type' => 'presenter',
        'target_id' => $existing_target_id,
      );
    }

    $inserted = $wpdb->insert($table, $data);

    if (!$inserted) {
      return new WP_Error('presenter_insert_failed', 'Presenter creation failed: ' . $wpdb->last_error);
    }

    return array(
      'target_type' => 'presenter',
      'target_id' => absint($wpdb->insert_id),
    );
  }

  private function mrm_profile_card_create_masterclass_event($request) {
    global $wpdb;

    $payload = $this->mrm_profile_card_decode_json($request['submission_payload'] ?? '');
    $admin_payload = $this->mrm_profile_card_decode_json($request['admin_payload'] ?? '');

    $events_table = $wpdb->prefix . 'mrm_masterclass_events';
    $presenters_table = $wpdb->prefix . 'mrm_masterclass_presenters';

    $events_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $events_table));
    $presenters_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $presenters_table));

    if ($events_exists !== $events_table || $presenters_exists !== $presenters_table) {
      return new WP_Error('missing_masterclass_tables', 'Masterclass tables are missing.');
    }

    $selected_presenter_id = absint($admin_payload['selected_presenter_id'] ?? 0);

    if ($selected_presenter_id <= 0) {
      return new WP_Error('presenter_not_selected', 'No presenter profile was selected for this event proposal.');
    }

    $presenter = $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$presenters_table} WHERE id = %d LIMIT 1", $selected_presenter_id),
      ARRAY_A
    );

    if (!$presenter) {
      return new WP_Error('presenter_not_found', 'The selected presenter profile could not be found. Approve the presenter profile first, then resend the event proposal.');
    }

    $title = sanitize_text_field($payload['event_title'] ?? '');
    if ($title === '') {
      return new WP_Error('missing_event_title', 'Event title is missing.');
    }

    $start_raw = sanitize_text_field($payload['start_time'] ?? '');
    $end_raw = sanitize_text_field($payload['end_time'] ?? '');

    $start_time = $start_raw ? date('Y-m-d H:i:s', strtotime($start_raw)) : '';
    $end_time = $end_raw ? date('Y-m-d H:i:s', strtotime($end_raw)) : '';

    if ($start_time === '' || $end_time === '' || strtotime($end_time) <= strtotime($start_time)) {
      return new WP_Error('invalid_event_time', 'Event start/end time is invalid.');
    }

    $data = array(
      'title' => $title,
      'piece_sku' => sanitize_text_field($payload['masterclass_piece_sku'] ?? $admin_payload['masterclass_piece_sku'] ?? ''),
      'piece_title' => sanitize_text_field($payload['masterclass_piece_title'] ?? $admin_payload['masterclass_piece_title'] ?? ''),
      'piece_page_url' => esc_url_raw($payload['masterclass_piece_url'] ?? $admin_payload['masterclass_piece_url'] ?? ''),
      'description' => wp_kses_post($payload['short_description'] ?? ''),
      'short_description' => wp_kses_post($payload['short_description'] ?? ''),
      'long_description' => wp_kses_post($payload['long_description'] ?? ''),
      'presenter_id' => absint($presenter['id']),
      'presenter_email' => sanitize_email($presenter['email']),
      'proctor_email' => '',
      'start_time' => $start_time,
      'end_time' => $end_time,
      'timezone' => sanitize_text_field($payload['timezone'] ?? 'America/Phoenix'),
      'price_cents' => max(0, absint($admin_payload['event_price_cents'] ?? 0)),
      'capacity' => 50,
      'status' => 'draft',
      'registration_open' => 0,
      'created_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
    );

    $event_payout_column = $wpdb->get_var(
      $wpdb->prepare("SHOW COLUMNS FROM {$events_table} LIKE %s", 'presenter_payout_per_student_cents')
    );

    if ($event_payout_column) {
      $data['presenter_payout_per_student_cents'] = max(0, absint($admin_payload['presenter_payout_per_student_cents'] ?? 0));
    }

    if (method_exists($this, 'mrm_profile_card_filter_data_for_table')) {
      $data = $this->mrm_profile_card_filter_data_for_table($events_table, $data);
    } else {
      foreach (array('piece_sku', 'piece_title', 'piece_page_url') as $piece_column) {
        $column_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$events_table} LIKE %s", $piece_column));
        if (!$column_exists && isset($data[$piece_column])) {
          unset($data[$piece_column]);
        }
      }
    }

    $inserted = $wpdb->insert($events_table, $data);

    if (!$inserted) {
      return new WP_Error('event_insert_failed', 'Masterclass event creation failed: ' . $wpdb->last_error);
    }

    return array(
      'target_type' => 'masterclass_event',
      'target_id' => absint($wpdb->insert_id),
    );
  }

  public function render_admin_page() {
    if (!current_user_can('manage_options')) return;

    settings_errors('mrm_pay_hub');

    $settings = $this->get_settings();
    $pk_current = esc_attr((string)$this->publishable_key());
    $price_current = esc_attr((string)($settings['stripe_sheet_music_subscription_price_id'] ?? ''));

    ?>
    <div class="wrap">
      <h1>Payment Hub</h1>

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

        <h2>Product Payment Settings</h2>
        <p>Product payment settings are managed here. Composer, instructor, and presenter payout settings now live under their dedicated payout submenus.</p>

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



  public function render_composer_payouts_page() {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission.');
    }

    settings_errors('mrm_pay_hub');

    $settings = $this->get_settings();

    $composer_acct = esc_attr((string)($settings['composer_connected_account_id'] ?? ''));
    $one_time_sheet_music_composer_pct = esc_attr((string)($settings['one_time_sheet_music_composer_pct'] ?? 0));
    $price_current = esc_attr((string)($settings['stripe_sheet_music_subscription_price_id'] ?? ''));
    $composer_payout_summary_email = esc_attr((string)($settings['composer_payout_summary_email'] ?? ''));

    ?>
    <div class="wrap">
      <h1>Composer Payouts</h1>
      <p>Manage composer payout settings, review sheet music/subscription payout activity, and manually run composer payouts when needed.</p>
      <?php $this->mrm_render_payout_action_notices(); ?>

      <form method="post">
        <?php wp_nonce_field('mrm_pay_hub_save', 'mrm_pay_hub_nonce'); ?>

        <h2>Composer Payout Settings</h2>

        <table class="form-table">
          <tr>
            <th scope="row"><label for="composer_connected_account_id">Composer Connected Account ID</label></th>
            <td>
              <input type="text" id="composer_connected_account_id" name="composer_connected_account_id" value="<?php echo $composer_acct; ?>" class="regular-text" placeholder="acct_..." />
              <p class="description">Paste the composer’s Stripe Connect account ID here.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="composer_payout_summary_email">Composer Payout Summary Email</label></th>
            <td>
              <input type="email" id="composer_payout_summary_email" name="composer_payout_summary_email" value="<?php echo $composer_payout_summary_email; ?>" class="regular-text" placeholder="composer@example.com" />
              <p class="description">Composer payout summary emails are sent here after composer payouts.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="one_time_sheet_music_composer_pct">One-Time Sheet Music Composer %</label></th>
            <td>
              <input type="number" id="one_time_sheet_music_composer_pct" name="one_time_sheet_music_composer_pct" value="<?php echo $one_time_sheet_music_composer_pct; ?>" class="small-text" min="0" max="100" />
              <p class="description">Used for eligible one-time sheet music purchases.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="stripe_sheet_music_subscription_price_id">Subscription Price ID</label></th>
            <td>
              <input type="text" id="stripe_sheet_music_subscription_price_id" name="stripe_sheet_music_subscription_price_id" value="<?php echo $price_current; ?>" class="regular-text" placeholder="price_..." />
              <p class="description">Used for sheet music subscription access.</p>
            </td>
          </tr>
        </table>

        <p class="submit">
          <button type="submit" class="button button-primary">Save Composer Payout Settings</button>
        </p>
      </form>

      <?php echo $this->render_composer_payout_ledger(); ?>
    </div>
    <?php
  }

  private function render_composer_payout_ledger() {
    global $wpdb;

    $table = $this->table_payout_ledger();

    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
      return '<h2>Composer Ledger</h2><p>Payout ledger table is missing.</p>';
    }

    $rows = $wpdb->get_results(
      "SELECT *
       FROM {$table}
       WHERE payee_type = 'composer'
       ORDER BY created_at DESC
       LIMIT 250",
      ARRAY_A
    );

    ob_start();
    ?>
    <h2>Composer Transaction / Payout Ledger</h2>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:14px;">
      <?php wp_nonce_field('mrm_pay_hub_run_all_payee_payouts'); ?>
      <input type="hidden" name="action" value="mrm_pay_hub_run_all_payee_payouts">
      <input type="hidden" name="payee_type" value="composer">
      <input type="hidden" name="return_page" value="mrm-pay-hub-composer-payouts">
      <button type="submit" class="button button-primary" onclick="return confirm('Run payout processing for all owed composer payout rows?');">
        Pay All Owed Composer Amounts
      </button>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('mrm_pay_hub_run_selected_payouts'); ?>
      <input type="hidden" name="action" value="mrm_pay_hub_run_selected_payouts">
      <input type="hidden" name="return_page" value="mrm-pay-hub-composer-payouts">

      <table class="widefat striped">
        <thead>
          <tr>
            <th>Select</th>
            <th>Ledger ID</th>
            <th>Source</th>
            <th>Gross</th>
            <th>Owed</th>
            <th>Status</th>
            <th>Stripe Account</th>
            <th>Transfer</th>
            <th>Payout</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)) : ?>
            <tr><td colspan="10">No composer payout rows found.</td></tr>
          <?php else : ?>
            <?php foreach ($rows as $row) : ?>
              <?php $selectable = in_array((string)$row['status'], array('pending','transferred','error','blocked'), true) && !empty($row['connected_account_id']); ?>
              <tr>
                <td><?php if ($selectable) : ?><input type="checkbox" name="ledger_ids[]" value="<?php echo esc_attr($row['id']); ?>"><?php endif; ?></td>
                <td><?php echo esc_html($row['id']); ?></td>
                <td><?php echo esc_html($row['payee_ref']); ?></td>
                <td><?php echo esc_html('$' . number_format(((int)$row['gross_cents']) / 100, 2)); ?></td>
                <td><?php echo esc_html('$' . number_format(((int)$row['net_cents']) / 100, 2)); ?></td>
                <td><?php echo esc_html($row['status']); ?></td>
                <td><code><?php echo esc_html($row['connected_account_id'] ?: 'Missing'); ?></code></td>
                <td><code><?php echo esc_html($row['transfer_id'] ?: '—'); ?></code></td>
                <td><code><?php echo esc_html($row['payout_id'] ?: '—'); ?></code></td>
                <td><?php echo esc_html($row['created_at']); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <p>
        <button type="submit" class="button" onclick="return confirm('Run payout processing for selected composer rows?');">
          Pay Selected Composer Rows
        </button>
      </p>
    </form>
    <?php
    return ob_get_clean();
  }

  public function render_instructor_payouts_page() {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission.');
    }

    settings_errors('mrm_pay_hub');

    $settings = $this->get_settings();

    $in_person_travel_amount = esc_attr($this->mrm_format_cents_for_admin_input((int)($settings['in_person_travel_amount_cents'] ?? 500)));
    $instructor_payout_chart_rows = $this->mrm_get_instructor_payout_chart_rows();
    $instructor_payout_chart_columns = $this->mrm_get_instructor_payout_chart_columns();
    $instructor_payout_chart_values = $this->mrm_get_instructor_payout_chart_admin_matrix();
    $payout_anchor_date = esc_attr((string)($settings['payout_anchor_date'] ?? ''));

    ?>
    <div class="wrap">
      <h1>Instructor Payouts</h1>
      <p>Review past instructor lessons, see delivered/canceled lesson status, view the amount owed, and run manual payouts for individual lessons or all owed lessons.</p>
      <?php $this->mrm_render_payout_action_notices(); ?>

      <form method="post">
        <?php wp_nonce_field('mrm_pay_hub_save', 'mrm_pay_hub_nonce'); ?>

        <h2>Instructor Payout Settings</h2>

        <table class="form-table">
          <tr>
            <th scope="row"><label for="payout_anchor_date">First Saturday of Two-Week Earning Period</label></th>
            <td>
              <input type="date" id="payout_anchor_date" name="payout_anchor_date" value="<?php echo $payout_anchor_date; ?>" />
              <p class="description">Choose the Saturday that begins a two-week earning period. The earning period runs Saturday 12:00 AM through Friday 11:59 PM two weeks later. The payout is issued the following Wednesday at 9:00 AM Pacific time.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="in_person_travel_amount">In-Person Travel Add-On</label></th>
            <td>
              <input type="number" id="in_person_travel_amount" name="in_person_travel_amount" value="<?php echo $in_person_travel_amount; ?>" class="small-text" min="0" step="0.01" />
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
                          <input type="number" name="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_attr($instructor_payout_chart_values[$row_key][$year_bucket] ?? '0.00'); ?>" class="small-text" min="0" step="0.01" />
                        </td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </td>
          </tr>

        </table>

        <p class="submit">
          <button type="submit" class="button button-primary">Save Instructor Payout Settings</button>
        </p>
      </form>

      <?php echo $this->render_instructor_payout_ledger(); ?>
    </div>
    <?php
  }

  private function render_instructor_payout_ledger() {
    global $wpdb;

    $lessons = $this->table_lessons();
    $payouts = $this->table_payout_ledger();
    $instructors = $wpdb->prefix . 'mrm_instructors';

    if (
      $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $lessons)) !== $lessons ||
      $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $payouts)) !== $payouts ||
      $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $instructors)) !== $instructors
    ) {
      return '<h2>Instructor Lessons Given</h2><p>Required lesson, instructor, or payout tables are missing.</p>';
    }

    $now = current_time('mysql');

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT
            l.id AS lesson_id,
            l.instructor_id,
            l.student_name,
            l.student_email,
            l.instrument,
            l.lesson_length,
            l.is_online,
            l.start_time,
            l.end_time,
            l.status AS lesson_status,
            l.charge_status,
            l.payment_mode,
            i.name AS instructor_name,
            i.email AS instructor_email,
            i.stripe_connected_account_id,
            p.id AS payout_id,
            p.net_cents AS owed_cents,
            p.status AS payout_status,
            p.transfer_id,
            p.payout_id AS stripe_payout_id,
            p.notes AS payout_notes
         FROM {$lessons} l
         LEFT JOIN {$instructors} i ON i.id = l.instructor_id
         LEFT JOIN {$payouts} p ON p.payee_type = 'instructor' AND p.payee_ref = CONCAT('lesson:', l.id)
         WHERE l.end_time < %s
         ORDER BY l.end_time DESC
         LIMIT 500",
        $now
      ),
      ARRAY_A
    );

    ob_start();
    ?>
    <h2>Instructor Lessons Given</h2>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:14px;">
      <?php wp_nonce_field('mrm_pay_hub_run_all_payee_payouts'); ?>
      <input type="hidden" name="action" value="mrm_pay_hub_run_all_payee_payouts">
      <input type="hidden" name="payee_type" value="instructor">
      <input type="hidden" name="return_page" value="mrm-pay-hub-instructor-payouts">
      <button type="submit" class="button button-primary" onclick="return confirm('Run payout processing for all owed instructor lesson rows?');">
        Pay All Owed Instructor Lessons
      </button>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('mrm_pay_hub_run_selected_payouts'); ?>
      <input type="hidden" name="action" value="mrm_pay_hub_run_selected_payouts">
      <input type="hidden" name="return_page" value="mrm-pay-hub-instructor-payouts">

      <table class="widefat striped">
        <thead>
          <tr>
            <th>Select</th>
            <th>Instructor</th>
            <th>Student</th>
            <th>Lesson</th>
            <th>Date / Time</th>
            <th>Lesson Status</th>
            <th>Charge Status</th>
            <th>Amount Owed</th>
            <th>Payout Status</th>
            <th>Stripe Account</th>
            <th>Transfer / Payout</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)) : ?>
            <tr><td colspan="11">No past instructor lessons found.</td></tr>
          <?php else : ?>
            <?php foreach ($rows as $row) : ?>
              <?php
                $owed_cents = isset($row['owed_cents']) ? (int)$row['owed_cents'] : 0;
                $payout_status = (string)($row['payout_status'] ?? 'not-created');
                $selectable = !empty($row['payout_id'])
                  && $owed_cents > 0
                  && !empty($row['stripe_connected_account_id'])
                  && in_array($payout_status, array('pending','transferred','error','blocked'), true);
              ?>
              <tr>
                <td><?php if ($selectable) : ?><input type="checkbox" name="ledger_ids[]" value="<?php echo esc_attr($row['payout_id']); ?>"><?php endif; ?></td>
                <td><?php echo esc_html($row['instructor_name'] ?: '—'); ?><br><small><?php echo esc_html($row['instructor_email'] ?: ''); ?></small></td>
                <td><?php echo esc_html($row['student_name']); ?><br><small><?php echo esc_html($row['student_email']); ?></small></td>
                <td><?php echo esc_html($row['instrument']); ?> — <?php echo esc_html((int)$row['lesson_length']); ?> min<br><small><?php echo !empty($row['is_online']) ? 'Online' : 'In person'; ?></small></td>
                <td><?php echo esc_html($row['start_time']); ?><br><small>Ended: <?php echo esc_html($row['end_time']); ?></small></td>
                <td><?php echo esc_html($row['lesson_status']); ?></td>
                <td><?php echo esc_html($row['charge_status']); ?></td>
                <td><?php echo esc_html('$' . number_format($owed_cents / 100, 2)); ?></td>
                <td><?php echo esc_html($payout_status); ?></td>
                <td><code><?php echo esc_html($row['stripe_connected_account_id'] ?: 'Missing'); ?></code></td>
                <td>
                  <?php if (!empty($row['transfer_id']) || !empty($row['stripe_payout_id'])) : ?>
                    Transfer: <code><?php echo esc_html($row['transfer_id'] ?: '—'); ?></code><br>
                    Payout: <code><?php echo esc_html($row['stripe_payout_id'] ?: '—'); ?></code>
                  <?php else : ?>
                    —
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <p>
        <button type="submit" class="button" onclick="return confirm('Run payout processing for selected instructor lesson rows?');">
          Pay Selected Instructor Lessons
        </button>
      </p>
    </form>
    <?php
    return ob_get_clean();
  }

  private function mrm_pay_table_masterclass_ledger() {
    global $wpdb;
    return $wpdb->prefix . 'mrm_masterclass_payment_ledger';
  }

  private function mrm_pay_table_masterclass_events() {
    global $wpdb;
    return $wpdb->prefix . 'mrm_masterclass_events';
  }

  private function mrm_pay_table_masterclass_presenters() {
    global $wpdb;
    return $wpdb->prefix . 'mrm_masterclass_presenters';
  }

  private function mrm_pay_table_exists($table) {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  }

  private function mrm_run_presenter_payouts($only_ledger_ids = array(), $force = false) {
    global $wpdb;

    $ledger_table = $this->mrm_pay_table_masterclass_ledger();
    $presenters_table = $this->mrm_pay_table_masterclass_presenters();
    $events_table = $this->mrm_pay_table_masterclass_events();
    $summary = array('paid' => 0, 'errors' => 0, 'last_error' => '');
    $batch_key = 'presenter_batch_' . gmdate('Ymd_His');
    $paid_presenter_rows = array();

    if (!$this->mrm_pay_table_exists($ledger_table) || !$this->mrm_pay_table_exists($presenters_table)) {
      $summary['errors']++;
      $summary['last_error'] = 'Required Masterclass payout tables are missing.';
      return $summary;
    }

    $only_ledger_ids = array_values(array_filter(array_unique(array_map('absint', (array)$only_ledger_ids))));
    $where = "WHERE l.ledger_type = 'registration_payment'
      AND l.status IN ('payable','payout_failed')
      AND l.presenter_share_cents > 0
      AND p.stripe_connected_account_id IS NOT NULL
      AND p.stripe_connected_account_id <> ''";
    $args = array();

    if (!$force) $where .= " AND COALESCE(l.payout_eligible_at, DATE_ADD(l.created_at, INTERVAL 7 DAY)) <= UTC_TIMESTAMP()";
    if (!empty($only_ledger_ids)) {
      $where .= " AND l.id IN (" . implode(',', array_fill(0, count($only_ledger_ids), '%d')) . ")";
      foreach ($only_ledger_ids as $ledger_id) $args[] = $ledger_id;
    }

    $sql = "SELECT l.*,
        p.stripe_connected_account_id,
        p.name AS presenter_name,
        p.email AS presenter_email,
        e.title AS event_title,
        e.presenter_payout_per_student_cents AS event_payout_per_student_cents
      FROM {$ledger_table} l
      LEFT JOIN {$presenters_table} p ON p.id = l.presenter_id
      LEFT JOIN {$events_table} e ON e.id = l.event_id
      {$where}
      ORDER BY l.id ASC";
    $rows = !empty($args) ? $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

    foreach ((array)$rows as $row) {
      $ledger_id = (int)$row['id'];
      $amount = (int)$row['presenter_share_cents'];
      $acct = (string)$row['stripe_connected_account_id'];
      if ($ledger_id <= 0 || $amount <= 0 || $acct === '') continue;

      $transfer = $this->stripe_create_transfer($amount, 'usd', $acct, 'MRM_MC_LEDGER_' . $ledger_id, array(
        'mrm_masterclass_ledger_id' => (string)$ledger_id,
        'mrm_event_id' => (string)($row['event_id'] ?? ''),
        'mrm_presenter_id' => (string)($row['presenter_id'] ?? ''),
        'mrm_presenter_batch_key' => $batch_key,
        'mrm_transfer_funding_mode' => 'platform_available_balance_only',
      ));

      if (is_wp_error($transfer)) {
        $summary['errors']++;
        $summary['last_error'] = $transfer->get_error_message();
        $wpdb->update($ledger_table, array('status' => 'payout_failed', 'payout_attempted_at' => current_time('mysql'), 'payout_error' => $transfer->get_error_message(), 'updated_at' => current_time('mysql')), array('id' => $ledger_id));
        continue;
      }

      $wpdb->update($ledger_table, array('status' => 'paid_out', 'stripe_transfer_id' => (string)($transfer['id'] ?? ''), 'paid_at' => current_time('mysql'), 'paid_out_at' => current_time('mysql'), 'payout_attempted_at' => current_time('mysql'), 'payout_error' => '', 'updated_at' => current_time('mysql')), array('id' => $ledger_id));
      $row['stripe_transfer_id'] = (string)($transfer['id'] ?? '');
      $row['presenter_batch_key'] = $batch_key;
      $paid_presenter_rows[] = $row;
      $summary['paid']++;
    }

    if (!empty($paid_presenter_rows)) {
      $owner_items = $this->mrm_send_presenter_payout_summaries_for_rows($paid_presenter_rows, $batch_key);

      if (!empty($owner_items)) {
        $this->mrm_send_owner_payout_batch_summary(
          $batch_key,
          $owner_items,
          $this->mrm_payout_period_label_from_rows($paid_presenter_rows, null)
        );
      }
    }

    return $summary;
  }

  public function render_presenter_payouts_page() {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission.');
    }

    global $wpdb;

    $ledger_table     = $this->mrm_pay_table_masterclass_ledger();
    $events_table     = $this->mrm_pay_table_masterclass_events();
    $presenters_table = $this->mrm_pay_table_masterclass_presenters();

    echo '<div class="wrap">';
    echo '<h1>Presenter Payouts</h1>';
    echo '<p>Review Masterclass presenter payouts. Presenter payouts become eligible one week after the event ends.</p>';
    $this->mrm_render_payout_action_notices();

    if (!$this->mrm_pay_table_exists($ledger_table) || !$this->mrm_pay_table_exists($events_table) || !$this->mrm_pay_table_exists($presenters_table)) {
      echo '<div class="notice notice-error"><p>Required Masterclass payout tables are missing.</p></div>';
      echo '</div>';
      return;
    }

    $summary = $wpdb->get_results(
      "SELECT p.id, p.name, p.email, p.stripe_connected_account_id,
              COALESCE(SUM(CASE WHEN l.status IN ('payable','payout_failed') THEN l.presenter_share_cents ELSE 0 END),0) AS unpaid_cents,
              COALESCE(SUM(CASE WHEN l.status = 'paid_out' THEN l.presenter_share_cents ELSE 0 END),0) AS paid_out_cents,
              COUNT(DISTINCT l.event_id) AS event_count
       FROM {$presenters_table} p
       LEFT JOIN {$ledger_table} l ON l.presenter_id = p.id AND l.ledger_type = 'registration_payment'
       GROUP BY p.id
       ORDER BY p.name ASC",
      ARRAY_A
    );

    echo '<h2>Presenter Stripe / Balance Overview</h2>';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Presenter</th><th>Email</th><th>Stripe Connected Account</th><th>Events</th><th>Unpaid</th><th>Paid Out</th></tr></thead><tbody>';

    if (empty($summary)) {
      echo '<tr><td colspan="6">No presenters found.</td></tr>';
    } else {
      foreach ($summary as $row) {
        echo '<tr>';
        echo '<td>' . esc_html($row['name']) . '</td>';
        echo '<td>' . esc_html($row['email']) . '</td>';
        echo '<td><code>' . esc_html($row['stripe_connected_account_id'] ?: 'Missing') . '</code></td>';
        echo '<td>' . esc_html($row['event_count']) . '</td>';
        echo '<td>$' . esc_html(number_format(((int)$row['unpaid_cents']) / 100, 2)) . '</td>';
        echo '<td>$' . esc_html(number_format(((int)$row['paid_out_cents']) / 100, 2)) . '</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';

    $rows = $wpdb->get_results(
      "SELECT
          l.*,
          e.title AS event_title,
          e.start_time AS event_start_time,
          e.end_time AS event_end_time,
          e.presenter_payout_per_student_cents AS event_payout_per_student_cents,
          p.name AS presenter_name,
          p.email AS presenter_email,
          p.stripe_connected_account_id,
          COUNT(l.id) OVER (PARTITION BY l.event_id, l.presenter_id) AS ledger_rows_for_event
       FROM {$ledger_table} l
       LEFT JOIN {$events_table} e ON e.id = l.event_id
       LEFT JOIN {$presenters_table} p ON p.id = l.presenter_id
       WHERE l.ledger_type = 'registration_payment'
         AND l.presenter_share_cents > 0
       ORDER BY COALESCE(e.end_time, l.created_at) DESC, p.name ASC",
      ARRAY_A
    );

    echo '<h2 style="margin-top:28px;">Presenter Event Payout Ledger</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:14px;">';
    wp_nonce_field('mrm_pay_hub_run_all_due_presenter_payouts');
    echo '<input type="hidden" name="action" value="mrm_pay_hub_run_all_due_presenter_payouts">';
    echo '<button type="submit" class="button button-primary" onclick="return confirm(\'Run payout processing for all due presenter payouts?\');">Pay All Due Presenter Payouts</button>';
    echo '</form>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('mrm_pay_hub_run_presenter_payout');
    echo '<input type="hidden" name="action" value="mrm_pay_hub_run_presenter_payout">';
    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>Select</th>';
    echo '<th>Presenter</th>';
    echo '<th>Event</th>';
    echo '<th>Event Date</th>';
    echo '<th>Payout Eligible Date</th>';
    echo '<th>Students / Rows</th>';
    echo '<th>Payout Per Student</th>';
    echo '<th>Total Payout Row</th>';
    echo '<th>Status</th>';
    echo '<th>Stripe Account</th>';
    echo '<th>Actions</th>';
    echo '</tr></thead><tbody>';

    if (empty($rows)) {
      echo '<tr><td colspan="11">No presenter payout ledger rows found.</td></tr>';
    } else {
      foreach ($rows as $row) {
        $event_end = !empty($row['event_end_time']) ? strtotime($row['event_end_time'] . ' UTC') : strtotime($row['created_at'] . ' UTC');
        $eligible_ts = $event_end ? $event_end + WEEK_IN_SECONDS : time() + WEEK_IN_SECONDS;
        $eligible_label = gmdate('Y-m-d H:i:s', $eligible_ts);
        $is_eligible = $eligible_ts <= time();

        $per_student = !empty($row['event_payout_per_student_cents'])
          ? (int)$row['event_payout_per_student_cents']
          : (int)$row['presenter_share_cents'];

        echo '<tr>';
        $presenter_selectable = in_array((string)$row['status'], array('payable','payout_failed'), true)
          && !empty($row['stripe_connected_account_id'])
          && $is_eligible;
        echo '<td>';
        if ($presenter_selectable) {
          echo '<input type="checkbox" name="ledger_ids[]" value="' . esc_attr($row['id']) . '">';
        }
        echo '</td>';
        echo '<td>' . esc_html($row['presenter_name'] ?: '—') . '<br><small>' . esc_html($row['presenter_email'] ?: '') . '</small></td>';
        echo '<td>' . esc_html($row['event_title'] ?: '—') . '</td>';
        echo '<td>' . esc_html($row['event_start_time'] ?: '—') . '</td>';
        echo '<td>' . esc_html($eligible_label) . '<br><small>' . esc_html($is_eligible ? 'Eligible now' : 'Scheduled') . '</small></td>';
        echo '<td>' . esc_html($row['ledger_rows_for_event']) . '</td>';
        echo '<td>$' . esc_html(number_format($per_student / 100, 2)) . '</td>';
        echo '<td>$' . esc_html(number_format(((int)$row['presenter_share_cents']) / 100, 2)) . '</td>';
        echo '<td>' . esc_html($row['status']) . '</td>';
        echo '<td><code>' . esc_html($row['stripe_connected_account_id'] ?: 'Missing') . '</code></td>';
        echo '<td>';

        if (!$is_eligible) {
          echo '<span class="description">Not eligible yet.</span>';
        } else {
          echo '<span class="description">Use existing Masterclass payout handlers for transfer/manual payment until those handlers are fully moved into Payment Hub.</span>';
        }

        echo '</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';
    echo '<p><button type="submit" class="button" onclick="return confirm(\'Run payout processing for selected presenter rows?\');">Pay Selected Presenter Rows</button></p>';
    echo '</form>';
    echo '</div>';
  }

  public function handle_run_presenter_payout() {
    if (!current_user_can('manage_options')) wp_die('You do not have permission.');
    check_admin_referer('mrm_pay_hub_run_presenter_payout');
    $ledger_ids = isset($_POST['ledger_ids']) && is_array($_POST['ledger_ids'])
      ? array_values(array_filter(array_unique(array_map('absint', wp_unslash($_POST['ledger_ids'])))))
      : array();
    if (empty($ledger_ids)) {
      wp_safe_redirect(admin_url('admin.php?page=mrm-pay-hub-presenter-payouts&payout_empty=1'));
      exit;
    }
    $result = $this->mrm_run_presenter_payouts($ledger_ids, true);
    $flag = empty($result['errors']) ? 'payout_ok=1' : 'payout_error=1';
    wp_safe_redirect(admin_url('admin.php?page=mrm-pay-hub-presenter-payouts&' . $flag));
    exit;
  }

  public function handle_run_all_due_presenter_payouts() {
    if (!current_user_can('manage_options')) wp_die('You do not have permission.');
    check_admin_referer('mrm_pay_hub_run_all_due_presenter_payouts');
    $result = $this->mrm_run_presenter_payouts(array(), true);
    $flag = empty($result['errors']) ? 'payout_ok=1' : 'payout_error=1';
    wp_safe_redirect(admin_url('admin.php?page=mrm-pay-hub-presenter-payouts&' . $flag));
    exit;
  }


  public function handle_run_selected_payouts() {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission.');
    }

    check_admin_referer('mrm_pay_hub_run_selected_payouts');

    $ledger_ids = isset($_POST['ledger_ids']) && is_array($_POST['ledger_ids'])
      ? array_values(array_filter(array_unique(array_map('absint', wp_unslash($_POST['ledger_ids'])))))
      : array();

    $return_page = sanitize_key($_POST['return_page'] ?? 'mrm-pay-hub-instructor-payouts');

    if (empty($ledger_ids)) {
      wp_safe_redirect(admin_url('admin.php?page=' . $return_page . '&payout_empty=1'));
      exit;
    }

    $result = $this->mrm_run_payout_batch(true, $ledger_ids, '');

    $flag = empty($result['errors']) ? 'payout_ok=1' : 'payout_error=1';
    wp_safe_redirect(admin_url('admin.php?page=' . $return_page . '&' . $flag));
    exit;
  }

  public function handle_run_all_payee_payouts() {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission.');
    }

    check_admin_referer('mrm_pay_hub_run_all_payee_payouts');

    $payee_type = sanitize_key($_POST['payee_type'] ?? '');
    $return_page = sanitize_key($_POST['return_page'] ?? 'mrm-pay-hub-instructor-payouts');

    if (!in_array($payee_type, array('instructor', 'composer'), true)) {
      wp_safe_redirect(admin_url('admin.php?page=' . $return_page . '&payout_error=1'));
      exit;
    }

    $result = $this->mrm_run_payout_batch(true, array(), $payee_type);

    $flag = empty($result['errors']) ? 'payout_ok=1' : 'payout_error=1';
    wp_safe_redirect(admin_url('admin.php?page=' . $return_page . '&' . $flag));
    exit;
  }

  private function mrm_render_payout_action_notices() {
    if (isset($_GET['payout_ok'])) {
      echo '<div class="notice notice-success"><p>Payout action completed. Check the ledger status for transfer/payout results.</p></div>';
    }

    if (isset($_GET['payout_error'])) {
      echo '<div class="notice notice-error"><p>Payout action completed with one or more errors. Check ledger notes/debug logs.</p></div>';
    }

    if (isset($_GET['payout_empty'])) {
      echo '<div class="notice notice-warning"><p>No payout rows were selected.</p></div>';
    }
  }

  /* =========================================================
   * Admin UI
   * ======================================================= */

  public function admin_menu() {
    add_menu_page(
      'Payment Hub',
      'Payment Hub',
      'manage_options',
      self::MENU_SLUG,
      array($this, 'render_admin_page'),
      'dashicons-cart',
      57
    );

    add_submenu_page(
      self::MENU_SLUG,
      'Payment Hub',
      'Payment Hub',
      'manage_options',
      self::MENU_SLUG,
      array($this, 'render_admin_page')
    );

    add_submenu_page(
      self::MENU_SLUG,
      'Composer Payouts',
      'Composer Payouts',
      'manage_options',
      'mrm-pay-hub-composer-payouts',
      array($this, 'render_composer_payouts_page')
    );

    add_submenu_page(
      self::MENU_SLUG,
      'Instructor Payouts',
      'Instructor Payouts',
      'manage_options',
      'mrm-pay-hub-instructor-payouts',
      array($this, 'render_instructor_payouts_page')
    );

    add_submenu_page(
      self::MENU_SLUG,
      'Presenter Payouts',
      'Presenter Payouts',
      'manage_options',
      'mrm-pay-hub-presenter-payouts',
      array($this, 'render_presenter_payouts_page')
    );

    add_submenu_page(
      self::MENU_SLUG,
      'Sheet Music Access',
      'Sheet Music Access',
      'manage_options',
      'mrm-pay-hub-access',
      array($this, 'render_access_lists_page')
    );

    add_submenu_page(
      self::MENU_SLUG,
      'Legal Dispute Ledger',
      'Legal Dispute Ledger',
      'manage_options',
      'mrm-pay-hub-legal-ledger',
      array($this, 'render_legal_ledger_page')
    );

    add_submenu_page(
      self::MENU_SLUG,
      'Marketing Email Lists',
      'Marketing Email Lists',
      'manage_options',
      'mrm-pay-hub-marketing-email-lists',
      array($this, 'render_marketing_email_lists_page')
    );

    add_submenu_page(
      self::MENU_SLUG,
      'Email Testing',
      'Email Testing',
      'manage_options',
      'mrm-pay-hub-email-testing',
      array($this, 'render_email_testing_page')
    );

    add_submenu_page(
      self::MENU_SLUG,
      'Promo Codes',
      'Promo Codes',
      'manage_options',
      'mrm-pay-hub-promo-codes',
      array($this, 'render_promo_codes_page')
    );

    add_submenu_page(
      self::MENU_SLUG,
      'Profile Card Creation',
      'Profile Card Creation',
      'manage_options',
      'mrm-pay-hub-profile-card-creation',
      array($this, 'render_profile_card_creation_page')
    );

    // BEGIN TEMP PAYMENT HUB AUDIT
    add_submenu_page(
      self::MENU_SLUG,
      'Payment Hub Audit',
      'Audit',
      'manage_options',
      'mrm-pay-hub-audit',
      array($this, 'render_temp_payment_hub_audit_page')
    );
    // END TEMP PAYMENT HUB AUDIT

  }

  public function render_legal_ledger_page() {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to view this page.');
    }

    global $wpdb;

    $orders = $this->table_orders();
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders));

    echo '<div class="wrap">';
    echo '<h1>Legal Dispute Ledger</h1>';
    echo '<p>This ledger exports payment/order records that may be useful for legal, refund, dispute, or account-history review.</p>';

    echo '<p>';
    echo '<a class="button button-primary" href="' . esc_url(
      wp_nonce_url(
        admin_url('admin-post.php?action=mrm_export_legal_ledger'),
        'mrm_export_legal_ledger'
      )
    ) . '">Export Legal Ledger CSV</a>';
    echo '</p>';

    if ($table_exists !== $orders) {
      echo '<div class="notice notice-error"><p>Orders table is missing.</p></div>';
      echo '</div>';
      return;
    }

    $rows = $wpdb->get_results(
      "SELECT id, created_at, customer_email, sku, product_type, amount_cents, currency, status, stripe_payment_intent_id
       FROM {$orders}
       ORDER BY created_at DESC, id DESC
       LIMIT 100",
      ARRAY_A
    );

    echo '<h2>Recent Orders</h2>';

    if (empty($rows)) {
      echo '<p>No order records found.</p>';
      echo '</div>';
      return;
    }

    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>Order ID</th>';
    echo '<th>Created</th>';
    echo '<th>Email</th>';
    echo '<th>SKU</th>';
    echo '<th>Type</th>';
    echo '<th>Amount</th>';
    echo '<th>Status</th>';
    echo '<th>Stripe Payment Intent</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
      $amount = '$' . number_format(((int)($row['amount_cents'] ?? 0)) / 100, 2);

      echo '<tr>';
      echo '<td>' . esc_html((string)($row['id'] ?? '')) . '</td>';
      echo '<td>' . esc_html((string)($row['created_at'] ?? '')) . '</td>';
      echo '<td>' . esc_html((string)($row['customer_email'] ?? '')) . '</td>';
      echo '<td>' . esc_html((string)($row['sku'] ?? '')) . '</td>';
      echo '<td>' . esc_html((string)($row['product_type'] ?? '')) . '</td>';
      echo '<td>' . esc_html($amount . ' ' . strtoupper((string)($row['currency'] ?? 'usd'))) . '</td>';
      echo '<td>' . esc_html((string)($row['status'] ?? '')) . '</td>';
      echo '<td>' . esc_html((string)($row['stripe_payment_intent_id'] ?? '')) . '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
  }

  public function handle_export_legal_ledger() {
    if (!is_admin()) {
      wp_die('Invalid request.', 'Legal Ledger Export', array('response' => 400));
    }

    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to export this ledger.', 'Legal Ledger Export', array('response' => 403));
    }

    check_admin_referer('mrm_export_legal_ledger');

    global $wpdb;

    $orders = $this->table_orders();

    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders));
    if ($table_exists !== $orders) {
      wp_die('Orders table is missing.', 'Legal Ledger Export', array('response' => 500));
    }

    $rows = $wpdb->get_results(
      "SELECT
         id,
         created_at,
         updated_at,
         customer_email,
         sku,
         product_type,
         amount_cents,
         currency,
         environment_mode,
         status,
         stripe_payment_intent_id,
         stripe_status,
         metadata_json
       FROM {$orders}
       ORDER BY created_at DESC, id DESC",
      ARRAY_A
    );

    $export_rows = array();

    foreach ((array)$rows as $row) {
      $export_rows[] = array(
        (string)($row['id'] ?? ''),
        (string)($row['created_at'] ?? ''),
        (string)($row['updated_at'] ?? ''),
        (string)($row['customer_email'] ?? ''),
        (string)($row['sku'] ?? ''),
        (string)($row['product_type'] ?? ''),
        number_format(((int)($row['amount_cents'] ?? 0)) / 100, 2, '.', ''),
        (string)($row['currency'] ?? ''),
        (string)($row['environment_mode'] ?? ''),
        (string)($row['status'] ?? ''),
        (string)($row['stripe_payment_intent_id'] ?? ''),
        (string)($row['stripe_status'] ?? ''),
        (string)($row['metadata_json'] ?? ''),
      );
    }

    $this->mrm_csv_send(
      'low-brass-lessons-legal-ledger-' . gmdate('Y-m-d') . '.csv',
      array(
        'Order ID',
        'Created At',
        'Updated At',
        'Customer Email',
        'SKU',
        'Product Type',
        'Amount',
        'Currency',
        'Environment',
        'Status',
        'Stripe Payment Intent',
        'Stripe Status',
        'Metadata JSON',
      ),
      $export_rows
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

      if (isset($_POST['stripe_sheet_music_subscription_price_id'])) {
        $settings['stripe_sheet_music_subscription_price_id'] = sanitize_text_field((string)$_POST['stripe_sheet_music_subscription_price_id']);
        $settings['stripe_test_sheet_music_subscription_price_id'] = '';
      }

      if (isset($_POST['composer_connected_account_id'])) {
        $settings['composer_connected_account_id'] = sanitize_text_field((string)$_POST['composer_connected_account_id']);
      }

      if (isset($_POST['composer_payout_summary_email'])) {
        $settings['composer_payout_summary_email'] = sanitize_email(wp_unslash($_POST['composer_payout_summary_email']));
      }

      if (isset($_POST['background_check_docusign_url'])) {
        $settings['background_check_docusign_url'] = esc_url_raw(wp_unslash($_POST['background_check_docusign_url']));
      }

      if (isset($_POST['owner_payout_summary_email'])) {
        $settings['owner_payout_summary_email'] = sanitize_email(wp_unslash($_POST['owner_payout_summary_email']));
      }

      if (isset($_POST['one_time_sheet_music_composer_pct'])) {
        $settings['one_time_sheet_music_composer_pct'] = $this->mrm_sanitize_percent_setting($_POST['one_time_sheet_music_composer_pct'], 0);
      }

      if (isset($_POST['in_person_travel_amount'])) {
        $settings['in_person_travel_amount_cents'] = $this->mrm_money_to_cents($_POST['in_person_travel_amount'], 500);
      }

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

          if (isset($_POST[$field_name])) {
            $settings[$setting_key] = $this->mrm_money_to_cents(
              $_POST[$field_name],
              0
            );
          }
        }
      }

      if (isset($_POST['payout_anchor_date'])) {
        $settings['payout_anchor_date'] = sanitize_text_field((string)$_POST['payout_anchor_date']);
      }

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

    if (isset($_POST['mrm_pay_hub_promo_codes_nonce']) && wp_verify_nonce($_POST['mrm_pay_hub_promo_codes_nonce'], 'mrm_pay_hub_save_promo_codes')) {
  $remove_redemption_ids = isset($_POST['promo_redemption_remove'])
    ? array_map('absint', (array)$_POST['promo_redemption_remove'])
    : array();

  $removed_redemptions = 0;

  if (!empty($remove_redemption_ids)) {
    $removed_redemptions = $this->mrm_delete_promo_redemption_rows($remove_redemption_ids);
  }

  $codes = array();

  $posted_codes = isset($_POST['promo_code']) ? (array)$_POST['promo_code'] : array();
  $labels = isset($_POST['promo_label']) ? (array)$_POST['promo_label'] : array();
  $discount_types = isset($_POST['promo_discount_type']) ? (array)$_POST['promo_discount_type'] : array();
  $percent_offs = isset($_POST['promo_percent_off']) ? (array)$_POST['promo_percent_off'] : array();
  $amount_offs = isset($_POST['promo_amount_off']) ? (array)$_POST['promo_amount_off'] : array();
  $posted_scopes = isset($_POST['promo_scopes']) && is_array($_POST['promo_scopes'])
    ? wp_unslash($_POST['promo_scopes'])
    : array();
  $applies_to = isset($_POST['promo_applies_to']) ? (array)$_POST['promo_applies_to'] : array();
  $rule_modes = isset($_POST['promo_rule_mode']) ? (array)$_POST['promo_rule_mode'] : array();
  $occurrence_counts = isset($_POST['promo_occurrence_count']) ? (array)$_POST['promo_occurrence_count'] : array();
    $starts = isset($_POST['promo_starts_at']) ? (array)$_POST['promo_starts_at'] : array();
  $expires = isset($_POST['promo_expires_at']) ? (array)$_POST['promo_expires_at'] : array();
  $reusable_per_email = isset($_POST['promo_reusable_per_email']) ? (array)$_POST['promo_reusable_per_email'] : array();
  $deletes = isset($_POST['promo_delete']) ? (array)$_POST['promo_delete'] : array();

  foreach ($posted_codes as $i => $raw_code) {
    $code = $this->mrm_normalize_promo_code($raw_code);

    if ($code === '') {
      continue;
    }

    if (!empty($deletes[$i])) {
      continue;
    }

    $discount_type = sanitize_text_field((string)($discount_types[$i] ?? 'percent'));
    if (!in_array($discount_type, array('percent', 'amount'), true)) {
      $discount_type = 'percent';
    }

    $scope_values = array();
    if (isset($posted_scopes[$i]) && is_array($posted_scopes[$i])) {
      $scope_values = array_map('sanitize_key', (array)$posted_scopes[$i]);
    }
    $scope_values = array_values(array_intersect($scope_values, array('lesson', 'sheet_music', 'masterclass')));
    if (empty($scope_values)) {
      $scope_values = array('lesson', 'sheet_music', 'masterclass');
    }
    $scope = count($scope_values) === 3 ? 'all' : implode(',', $scope_values);

    /*
 * Item Rule has been removed from the admin UI.
 * Keep the saved key for backward compatibility, but always use all_items.
 */
    $apply = 'all_items';

    $rule_mode = sanitize_text_field((string)($rule_modes[$i] ?? 'all'));
    if (!in_array($rule_mode, array('all', 'first_n', 'after_n', 'first_n_months', 'date_window'), true)) {
      $rule_mode = 'all';
    }

    $percent = max(0, min(100, absint($percent_offs[$i] ?? 0)));
    $amount_cents = $this->mrm_money_to_cents($amount_offs[$i] ?? '0.00', 0);

    $occurrence_count = max(0, absint($occurrence_counts[$i] ?? 0));
    
    $starts_at = sanitize_text_field((string)($starts[$i] ?? ''));
    if ($starts_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts_at)) {
      $starts_at = '';
    }

    $expires_at = sanitize_text_field((string)($expires[$i] ?? ''));
    if ($expires_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires_at)) {
      $expires_at = '';
    }

    $codes[$code] = array( 'code' => $code, 'label' => sanitize_text_field((string)($labels[$i] ?? '')), 'discount_type' => $discount_type, 'percent_off' => $percent, 'amount_off_cents' => max(0, (int)$amount_cents), 'scope' => $scope, 'scopes' => $scope_values, 'applies_to' => $apply, 'rule_mode' => $rule_mode, 'occurrence_count' => $occurrence_count, 'after_occurrence' => 0, 'starts_at' => $starts_at, 'expires_at' => $expires_at, 'reusable_per_email' => !empty($reusable_per_email[$i]) ? 1 : 0, 'active' => 1, 'updated_at' => current_time('mysql'), );
  }

  $this->mrm_save_promo_codes($codes);

  $message = 'Promo codes saved.';

  if ($removed_redemptions > 0) {
    $message .= ' Removed ' . (int)$removed_redemptions . ' completed redemption' . ($removed_redemptions === 1 ? '' : 's') . '.';
  }

  add_settings_error('mrm_pay_hub', 'promo_codes_saved', $message, 'updated');
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
        $raw_sku_value = trim((string)$sku_raw);
        $raw_original_sku = trim((string)($original_skus[$index] ?? ''));
        $raw_label_value = trim((string)($labels[$index] ?? ''));
        $raw_amount_value = trim((string)($amounts[$index] ?? ''));
        $is_delete_request = !empty($deletes[$index]);

        $has_meaningful_input =
          ($raw_sku_value !== '') ||
          ($raw_original_sku !== '') ||
          ($raw_label_value !== '') ||
          ($raw_amount_value !== '') ||
          $is_delete_request;

        // Ignore the blank "new product" template row so it cannot overwrite real products.
        if (!$has_meaningful_input) {
          continue;
        }

        $label_raw = sanitize_text_field($raw_label_value);
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

        $amount_raw = trim((string)($amounts[$index] ?? ''));
        if ($amount_raw === '' && $current_sku && isset($products[$current_sku]['amount_cents'])) {
          // Preserve the existing price if the field was left blank.
          $final_amount_cents = max(0, (int)$products[$current_sku]['amount_cents']);
        } else {
          $final_amount_cents = max(0, (int)$amount_raw);
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
                      <th>Order ID</th>
                      <th>Payment Intent ID</th>
                      <th>Activation Status</th>
                      <th>Last Activation Error</th>
                      <th>Updated</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (empty($rows)) : ?>
                    <tr><td colspan="10">No sheet music subscriptions found.</td></tr>
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
                        <td><?php echo esc_html((string)($row['order_id'] ?? '')); ?></td>
                        <td><?php echo esc_html((string)($row['payment_intent_id'] ?? '')); ?></td>
                        <td><?php echo esc_html((string)($row['activation_status'] ?? '')); ?></td>
                        <td><?php echo esc_html((string)($row['last_activation_error'] ?? '')); ?></td>
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


  // BEGIN TEMP PAYMENT HUB AUDIT
  public function render_temp_payment_hub_audit_page() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have permission to access this page.', 'mrm-payments-hub'));
    }

    $summary_key = 'mrm_pay_hub_temp_audit_summary_' . get_current_user_id();
    $summary = get_transient($summary_key);
    if (isset($_GET['mrm_audit_run'])) {
      delete_transient($summary_key);
    }

    $log_file = 'wp-content/mrm-payment-hub-audit.log';
    ?>
    <div class="wrap">
      <h1>Payment Hub Audit</h1>
      <p>This is a temporary, admin-only pre-launch audit tool. It performs read-only checks across the Low Brass Lessons plugin/frontend files and writes one audit log file.</p>
      <p><strong>Safety:</strong> this tool does not trigger real payments, refunds, payouts, emails, registrations, cancellations, Google Calendar writes, or other live external side effects.</p>

      <?php if (is_array($summary)) : ?>
        <div class="notice notice-<?php echo !empty($summary['notice_class']) ? esc_attr($summary['notice_class']) : 'success'; ?> is-dismissible">
          <p><strong>Audit completed.</strong></p>
          <p>
            Overall status: <strong><?php echo esc_html((string)($summary['status'] ?? 'UNKNOWN')); ?></strong><br>
            Good findings: <?php echo esc_html((string)($summary['good'] ?? 0)); ?> |
            Warnings: <?php echo esc_html((string)($summary['warning'] ?? 0)); ?> |
            Errors: <?php echo esc_html((string)($summary['error'] ?? 0)); ?> |
            Skipped: <?php echo esc_html((string)($summary['skipped'] ?? 0)); ?><br>
            Log written to <code><?php echo esc_html($log_file); ?></code>
          </p>
          <?php if (!empty($summary['message'])) : ?>
            <p><?php echo esc_html((string)$summary['message']); ?></p>
          <?php endif; ?>
        </div>
      <?php elseif (isset($_GET['mrm_audit_run'])) : ?>
        <div class="notice notice-error is-dismissible"><p><strong>Audit failed.</strong> No audit summary was available. Check file permissions for <code><?php echo esc_html($log_file); ?></code>.</p></div>
      <?php endif; ?>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="mrm_pay_hub_run_temp_audit" />
        <?php wp_nonce_field('mrm_pay_hub_run_temp_audit', 'mrm_pay_hub_temp_audit_nonce'); ?>
        <p><button type="submit" class="button button-primary">Run Audit Now</button></p>
      </form>
    </div>
    <?php
  }

  public function handle_temp_payment_hub_audit_run() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have permission to run this audit.', 'mrm-payments-hub'));
    }
    check_admin_referer('mrm_pay_hub_run_temp_audit', 'mrm_pay_hub_temp_audit_nonce');
    $summary = array('status' => 'FAIL', 'good' => 0, 'warning' => 0, 'error' => 1, 'skipped' => 0, 'notice_class' => 'error', 'message' => 'The audit did not complete.');
    try {
      $result = $this->mrm_pay_hub_temp_audit_run_all_checks();
      if (is_array($result)) $summary = $result;
    } catch (Throwable $e) {
      $summary['message'] = 'Audit stopped because PHP reported: ' . $this->mrm_pay_hub_temp_audit_redact($e->getMessage());
      $this->mrm_pay_hub_temp_audit_write_emergency_log($summary['message']);
    }
    set_transient('mrm_pay_hub_temp_audit_summary_' . get_current_user_id(), $summary, 5 * MINUTE_IN_SECONDS);
    wp_safe_redirect(add_query_arg(array('page' => 'mrm-pay-hub-audit', 'mrm_audit_run' => '1'), admin_url('admin.php')));
    exit;
  }

  private function mrm_pay_hub_temp_audit_run_all_checks() {
    $state = array(
      'started_at' => current_time('mysql'), 'site_url' => site_url(), 'active_theme' => $this->mrm_pay_hub_temp_audit_active_theme_name(),
      'roots' => array(), 'files' => array(), 'file_contents' => array(), 'functions' => array(), 'methods' => array(), 'hooks' => array(),
      'rest_routes' => array(), 'scheduled_hooks' => array(), 'table_names' => array(), 'html' => array(),
      'counts' => array('GOOD' => 0, 'WARNING' => 0, 'ERROR' => 0, 'SKIPPED' => 0),
      'findings' => array('Backend/plugin health' => array(), 'Admin UI workflows' => array(), 'REST endpoints' => array(), 'Admin-post/admin-ajax hooks' => array(), 'Cron/timing' => array(), 'Database tables' => array(), 'Frontend HTML/JS' => array(), 'Design/layout consistency' => array(), 'Payment/customer flows' => array(), 'Email/calendar/access gates' => array(), 'Public debug/diagnostic cleanup' => array()),
    );
    $state['roots'] = $this->mrm_pay_hub_temp_audit_find_scan_roots();
    $this->mrm_pay_hub_temp_audit_collect_files($state);
    $this->mrm_pay_hub_temp_audit_build_index($state);
    $this->mrm_pay_hub_temp_audit_collect_wp_page_html($state);
    $this->mrm_pay_hub_temp_audit_check_plugin_health($state);
    $this->mrm_pay_hub_temp_audit_check_admin_ui_workflows($state);
    $this->mrm_pay_hub_temp_audit_check_rest_routes($state);
    $this->mrm_pay_hub_temp_audit_check_admin_ajax_post($state);
    $this->mrm_pay_hub_temp_audit_check_cron($state);
    $this->mrm_pay_hub_temp_audit_check_database($state);
    $this->mrm_pay_hub_temp_audit_check_frontend_html_js($state);
    $this->mrm_pay_hub_temp_audit_check_design_consistency($state);
    $this->mrm_pay_hub_temp_audit_check_payment_flows($state);
    $this->mrm_pay_hub_temp_audit_check_email_calendar_access($state);
    $this->mrm_pay_hub_temp_audit_check_public_debug($state);
    $status = $this->mrm_pay_hub_temp_audit_launch_status($state['counts']);
    $written = $this->mrm_pay_hub_temp_audit_write_log($this->mrm_pay_hub_temp_audit_format_log($state, $status));
    return array('status' => $status, 'good' => (int)$state['counts']['GOOD'], 'warning' => (int)$state['counts']['WARNING'], 'error' => (int)$state['counts']['ERROR'], 'skipped' => (int)$state['counts']['SKIPPED'], 'notice_class' => $state['counts']['ERROR'] > 0 ? 'error' : ($state['counts']['WARNING'] > 0 ? 'warning' : 'success'), 'message' => $written ? 'Review the structured log before launch.' : 'Audit ran, but the log could not be written.');
  }

  private function mrm_pay_hub_temp_audit_active_theme_name() {
    if (function_exists('wp_get_theme')) {
      $theme = wp_get_theme();
      if ($theme && is_object($theme)) {
        $name = trim((string)$theme->get('Name'));
        if ($name !== '') return $name;
      }
    }
    return 'Unknown';
  }

  private function mrm_pay_hub_temp_audit_find_scan_roots() {
    $roots = array();
    $candidates = array(dirname(__FILE__));
    if (defined('WP_PLUGIN_DIR') && is_dir(WP_PLUGIN_DIR)) {
      $known = array('mrm-payments-hub', 'mrm-lesson-scheduler', 'mrm-masterclass', 'mrm-product-access', 'mrm-sheet-music', 'mrm-onboarding', 'mrm-meeting-scheduler', 'mrm-contact-form');
      foreach ($known as $dir_name) $candidates[] = trailingslashit(WP_PLUGIN_DIR) . $dir_name;
      $children = @scandir(WP_PLUGIN_DIR);
      if (is_array($children)) foreach ($children as $child) {
        if ($child === '.' || $child === '..') continue;
        $path = trailingslashit(WP_PLUGIN_DIR) . $child;
        if (!is_dir($path)) continue;
        $lower = strtolower($child);
        if (strpos($lower, 'mrm-') === 0 || strpos($lower, 'low-brass') !== false || strpos($lower, 'lowbrass') !== false) $candidates[] = $path;
      }
    }
    foreach ($candidates as $candidate) {
      $real = realpath($candidate);
      if (!$real || !is_dir($real) || !is_readable($real) || $this->mrm_pay_hub_temp_audit_path_is_forbidden($real)) continue;
      $roots[$real] = $real;
    }
    return array_values($roots);
  }

  private function mrm_pay_hub_temp_audit_path_is_forbidden($path) {
    $path = str_replace('\\', '/', (string)$path);
    $lower = strtolower($path);
    $forbidden = array('/wp-admin/', '/wp-includes/', '/uploads/', '/cache/', '/node_modules/', '/vendor/', '/.git/', '/logs/', '/tmp/', '/backup', '/backups/');
    foreach ($forbidden as $segment) if (strpos($lower, $segment) !== false) return true;
    return in_array(strtolower(basename($path)), array('wp-config.php', '.env', 'debug.log'), true);
  }

  private function mrm_pay_hub_temp_audit_collect_files(&$state) {
    foreach ($state['roots'] as $root) $this->mrm_pay_hub_temp_audit_walk_dir($root, $state, 0);
    if (empty($state['files'])) {
      $this->mrm_pay_hub_temp_audit_add_finding($state, 'Backend/plugin health', 'ERROR', 'File scan', 'Collect PHP, HTML, JS, CSS, and JSON files.', 'No readable code/frontend files were collected.', 'No launch checks can be completed without code files.', 'Confirm the plugin folders are readable by WordPress.');
    } else {
      $this->mrm_pay_hub_temp_audit_add_finding($state, 'Backend/plugin health', 'GOOD', 'File scan', 'Collect PHP, HTML, JS, CSS, and JSON files.', 'Collected ' . count($state['files']) . ' readable file(s).', 'The scanner has enough local source files to evaluate wiring and frontend consistency.', 'No fix needed.');
    }
  }

  private function mrm_pay_hub_temp_audit_walk_dir($dir, &$state, $depth) {
    if ($depth > 8) {
      $this->mrm_pay_hub_temp_audit_add_finding($state, 'Backend/plugin health', 'SKIPPED', $this->mrm_pay_hub_temp_audit_rel($dir), 'Recursive scan depth limit.', 'Skipped a deeply nested directory.', 'Depth limits prevent shared hosting timeouts.', 'Move relevant frontend/plugin files closer to the plugin root if they must be audited.');
      return;
    }
    if (count($state['files']) >= 750) {
      $this->mrm_pay_hub_temp_audit_add_finding($state, 'Backend/plugin health', 'SKIPPED', 'File scan', 'File count safety limit.', 'Stopped after collecting 750 files.', 'Large scans can time out on shared hosting.', 'Temporarily narrow the plugin folders or increase the safety limit only if needed.');
      return;
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
      $this->mrm_pay_hub_temp_audit_add_finding($state, 'Backend/plugin health', 'SKIPPED', $this->mrm_pay_hub_temp_audit_rel($dir), 'Read directory.', 'Directory could not be read.', 'Unreadable folders may hide launch issues.', 'Check permissions or ignore if the folder is unrelated.');
      return;
    }
    foreach ($items as $item) {
      if ($item === '.' || $item === '..') continue;
      $path = $dir . DIRECTORY_SEPARATOR . $item;
      if ($this->mrm_pay_hub_temp_audit_path_is_forbidden($path)) continue;
      if (is_dir($path)) { $this->mrm_pay_hub_temp_audit_walk_dir($path, $state, $depth + 1); continue; }
      if (!is_file($path) || !is_readable($path)) continue;
      $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
      if (!in_array($ext, array('php', 'html', 'htm', 'js', 'css', 'json'), true)) continue;
      $size = @filesize($path);
      if ($size !== false && $size > 2500000) {
        $this->mrm_pay_hub_temp_audit_add_finding($state, 'Backend/plugin health', 'SKIPPED', $this->mrm_pay_hub_temp_audit_rel($path), 'Read file size.', 'Skipped because the file is larger than 2.5 MB.', 'Large files can cause audit timeouts on shared hosting.', 'Split very large frontend files or inspect them manually.');
        continue;
      }
      $state['files'][] = $path;
    }
  }

  private function mrm_pay_hub_temp_audit_build_index(&$state) {
    foreach ($state['files'] as $file) {
      $content = @file_get_contents($file, false, null, 0, 1600000);
      if (!is_string($content)) {
        $this->mrm_pay_hub_temp_audit_add_finding($state, 'Backend/plugin health', 'SKIPPED', $this->mrm_pay_hub_temp_audit_rel($file), 'Read source file.', 'File could not be read.', 'Unreadable files can hide missing callbacks or frontend issues.', 'Check file permissions.');
        continue;
      }
      $content = $this->mrm_pay_hub_temp_audit_strip_temp_audit_block($content);
      $rel = $this->mrm_pay_hub_temp_audit_rel($file);
      $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
      $state['file_contents'][$file] = $content;
      if (preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $content, $matches)) foreach ($matches[1] as $name) { $state['functions'][$name] = $rel; $state['methods'][$name] = $rel; }
      if (preg_match_all('/add_(action|filter)\s*\((.*?)\);/s', $content, $matches, PREG_SET_ORDER)) foreach ($matches as $m) {
        $stmt = 'add_' . $m[1] . '(' . $m[2] . ');';
        $hook = $this->mrm_pay_hub_temp_audit_first_string_arg($m[2]);
        if ($hook !== '') $state['hooks'][] = array('type' => $m[1], 'hook' => $hook, 'callback' => $this->mrm_pay_hub_temp_audit_extract_callback($stmt), 'file' => $rel, 'statement' => $stmt);
      }
      if (preg_match_all('/(?:\$this->)?[A-Za-z0-9_]*add_action_if_method_exists\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*(?:,\s*-?\d+)?\s*(?:,\s*\d+)?\s*\)/', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
          $hook = (string) $m[1];
          $method = (string) $m[2];

          if ($hook !== '' && $method !== '') {
            $state['hooks'][] = array(
              'type' => 'action',
              'hook' => $hook,
              'callback' => array('type' => 'method', 'name' => $method),
              'file' => $rel,
              'statement' => $m[0],
            );
          }
        }
      }
      if (preg_match_all('/register_rest_route\s*\(\s*([\'\"])(.*?)\1\s*,\s*([\'\"])(.*?)\3\s*,(.*?)\);/s', $content, $matches, PREG_SET_ORDER)) foreach ($matches as $m) {
        $block = $m[5];
        $state['rest_routes'][] = array('namespace' => $m[2], 'route' => $m[4], 'callback' => $this->mrm_pay_hub_temp_audit_extract_callback($block), 'permission' => $this->mrm_pay_hub_temp_audit_extract_permission_callback($block), 'methods' => $this->mrm_pay_hub_temp_audit_extract_rest_methods($block), 'file' => $rel, 'block' => $block);
      }
      if (preg_match_all('/wp_(?:schedule_event|schedule_single_event)\s*\((.*?)\);/s', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
          $args = trim((string) $m[1]);
          $hook = '';

          /*
           * Preferred case:
           * wp_schedule_event($timestamp, 'daily', 'real_hook_name');
           * The hook is the third argument, not the recurrence.
           */
          if (preg_match('/^[^,]+,\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/', $args, $hook_match)) {
            $hook = (string) $hook_match[1];
          }

          /*
           * If the third argument is a variable, do not guess from the recurrence.
           * Example:
           * wp_schedule_event($timestamp, 'daily', $hook);
           * In that case, `daily` is not a hook and should not be reported as missing.
           */
          if ($hook === '') {
            if (preg_match('/^[^,]+,\s*[\'"](hourly|twicedaily|daily|weekly|mrm_10min)[\'"]\s*,\s*\$/i', $args)) {
              $this->mrm_pay_hub_temp_audit_add_finding(
                $state,
                'Cron/timing',
                'SKIPPED',
                $rel,
                'Parse scheduled hook with variable hook name.',
                'Skipped a wp_schedule_event() call that uses a variable hook name.',
                'This avoids incorrectly treating the recurrence string, such as daily, as the hook name.',
                'No code change needed if the variable hook is registered elsewhere.'
              );
              continue;
            }

            if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $args, $strings) && !empty($strings[1])) {
              $candidate = end($strings[1]);
              if (!in_array($candidate, array('hourly', 'twicedaily', 'daily', 'weekly', 'mrm_10min'), true)) {
                $hook = (string) $candidate;
              }
            }
          }

          if ($hook !== '') {
            /*
             * Ignore parser/example artifacts from the temporary audit code itself.
             * These are not real production cron hooks.
             */
            $hook_clean = trim((string) $hook);

            if (
              $hook_clean === '' ||
              $hook_clean === 'real_hook_name' ||
              $hook_clean === ',' ||
              strpos($hook_clean, '$args') !== false ||
              strpos($hook_clean, ' . ') !== false ||
              !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $hook_clean)
            ) {
              $this->mrm_pay_hub_temp_audit_add_finding(
                $state,
                'Cron/timing',
                'SKIPPED',
                $rel,
                'Skip malformed scheduled hook parser artifact.',
                'Skipped malformed scheduled hook candidate: ' . $hook_clean,
                'This prevents audit example/parser text from being counted as a real missing cron hook.',
                'No production code change needed.'
              );
              continue;
            }

            $state['scheduled_hooks'][] = array(
              'hook' => $hook_clean,
              'file' => $rel,
              'statement' => 'wp_schedule_event(' . $args . ');',
            );
          }
        }
      }
      if (preg_match_all('/\$wpdb->prefix\s*\.\s*[\'\"]([^\'\"]+)[\'\"]/', $content, $matches)) foreach ($matches[1] as $table_tail) $state['table_names'][$table_tail] = $rel;
      if (in_array($ext, array('html', 'htm'), true)) {
        $ids = array();
        if (preg_match_all('/\bid\s*=\s*[\'\"]([^\'\"]+)[\'\"]/i', $content, $id_matches)) foreach ($id_matches[1] as $id) $ids[$id] = true;
        $state['html'][$file] = array('rel' => $rel, 'ids' => $ids, 'content' => $content);
      }
    }
  }

  private function mrm_pay_hub_temp_audit_check_plugin_health(&$state) {
    $php_files = 0; $plugin_headers = 0;
    foreach ($state['file_contents'] as $file => $content) {
      if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') continue;
      $php_files++; $rel = $this->mrm_pay_hub_temp_audit_rel($file);
      if (strpos($content, 'Plugin Name:') !== false) { $plugin_headers++; $this->mrm_pay_hub_temp_audit_add_finding($state, 'Backend/plugin health', 'GOOD', $rel, 'Check WordPress plugin header.', 'Plugin header found.', 'WordPress can identify the plugin bootstrap file.', 'No fix needed.'); }
      if (!preg_match('/defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)/', $content)) {
        $this->mrm_pay_hub_temp_audit_add_finding(
          $state,
          'Backend/plugin health',
          'WARNING',
          $rel,
          'Check direct-access guard.',
          'No obvious ABSPATH guard was found.',
          'Publicly loading PHP plugin files directly can expose unsafe behavior.',
          'Add `if (!defined(\'ABSPATH\')) exit;` near the top if this file is directly web-accessible.'
        );
      }
      if (abs(substr_count($content, '{') - substr_count($content, '}')) > 2) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Backend/plugin health', 'WARNING', $rel, 'Check rough brace balance.', 'The file has a suspicious brace count difference.', 'Large brace mismatches can indicate syntax or copy/paste risks.', 'Open the file in an editor with PHP linting and inspect unmatched blocks.');
      else $this->mrm_pay_hub_temp_audit_add_finding($state, 'Backend/plugin health', 'GOOD', $rel, 'Check rough brace balance.', 'Brace count does not look suspicious.', 'This reduces obvious fatal syntax-risk signals without using shell commands.', 'No fix needed.');
    }
    if ($php_files > 0) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Backend/plugin health', 'GOOD', 'PHP scan', 'Confirm PHP files exist.', 'Found ' . $php_files . ' PHP file(s), including ' . $plugin_headers . ' plugin header file(s).', 'The audit can evaluate plugin registrations and callbacks.', 'No fix needed.');
    foreach ($state['hooks'] as $hook) {
      $cb = $hook['callback'];

      if (empty($cb['type']) || $cb['type'] === 'closure') {
        continue;
      }

      $callback_name = isset($cb['name']) ? (string) $cb['name'] : '';

      if ($callback_name !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $callback_name)) {
        $this->mrm_pay_hub_temp_audit_add_finding(
          $state,
          'Backend/plugin health',
          'WARNING',
          $hook['file'] . ' / ' . $hook['hook'],
          'Validate parsed callback name.',
          'The audit parser produced a malformed callback name: ' . $callback_name,
          'Malformed callback names usually indicate an audit parser limitation, not necessarily a real WordPress callback.',
          'Review the nearby hook registration manually. If the real callback exists, no production code change is needed.'
        );
        continue;
      }

      if ($cb['type'] === 'method' && empty($state['methods'][$callback_name])) {
        $this->mrm_pay_hub_temp_audit_add_finding(
          $state,
          'Backend/plugin health',
          'ERROR',
          $hook['file'] . ' / ' . $hook['hook'],
          'Confirm registered callback method exists.',
          'Missing method callback: ' . $callback_name,
          'A missing callback can cause fatal errors or dead admin/customer flows.',
          'Create the method or remove/fix the hook registration.'
        );
      } elseif ($cb['type'] === 'function' && empty($state['functions'][$callback_name]) && !function_exists($callback_name)) {
        $this->mrm_pay_hub_temp_audit_add_finding(
          $state,
          'Backend/plugin health',
          'WARNING',
          $hook['file'] . ' / ' . $hook['hook'],
          'Confirm registered callback function exists.',
          'Function callback was not found in scanned files: ' . $callback_name,
          'If this function is not loaded by WordPress, the hook will fail.',
          'Confirm the function is loaded, or change the hook to an existing callback.'
        );
      }
    }
  }

  private function mrm_pay_hub_temp_audit_check_admin_ui_workflows(&$state) {
    $payment_hub = '';
    $scheduler = '';

    foreach ($state['file_contents'] as $file => $content) {
      $rel = $this->mrm_pay_hub_temp_audit_rel($file);

      if (strpos($rel, 'mrm-payments-hub.php') !== false) {
        $payment_hub = $content;
      }

      if (strpos($rel, 'mrm-lesson-scheduler.php') !== false) {
        $scheduler = $content;
      }
    }

    $email_testing_source = $this->mrm_pay_hub_temp_audit_source_for_callable($state, 'render_email_testing_page');

    if ($email_testing_source === '') {
      $email_testing_source = $payment_hub;
    }

    $email_testing_ok = (
      strpos($email_testing_source, 'mrm-email-testing-preview-frame') !== false &&
      strpos($email_testing_source, 'Select the email you want to preview') !== false &&
      strpos($email_testing_source, 'mrm-email-test-checkbox') !== false &&
      strpos($email_testing_source, 'addEventListener') !== false &&
      strpos($email_testing_source, 'clearPreview') !== false &&
      strpos($email_testing_source, 'showPreview') !== false
    );

    $old_email_testing_submit_ui_present = (
      strpos($email_testing_source, 'Preview Selected Emails') !== false ||
      strpos($email_testing_source, 'mrm_pay_hub_preview_email_tests') !== false ||
      strpos($email_testing_source, 'mrm_email_tests[]') !== false
    );

    $this->mrm_pay_hub_temp_audit_add_finding(
      $state,
      'Admin UI workflows',
      ($email_testing_ok && !$old_email_testing_submit_ui_present) ? 'GOOD' : 'WARNING',
      'Payment Hub / Email Testing',
      'Check live single-email preview workflow.',
      ($email_testing_ok && !$old_email_testing_submit_ui_present)
        ? 'Email Testing uses the live single-checkbox preview workflow and no old submit-to-preview UI was detected inside render_email_testing_page().'
        : 'Email Testing does not appear to fully match the requested live single-checkbox preview workflow inside render_email_testing_page().',
      'The preview workflow should update immediately without requiring a Preview Selected Emails submit button.',
      ($email_testing_ok && !$old_email_testing_submit_ui_present)
        ? 'No fix needed.'
        : 'Update render_email_testing_page() so the preview iframe, single-checkbox behavior, and immediate JavaScript preview are all inside that method.'
    );

    $marketing_preview_ok = (
      strpos($payment_hub, 'mrm-marketing-live-preview') !== false &&
      strpos($payment_hub, 'mrm-marketing-preview-frame') !== false &&
      strpos($payment_hub, 'mrm-marketing-subject-input') !== false &&
      strpos($payment_hub, 'mrm-marketing-html-input') !== false
    );

    $this->mrm_pay_hub_temp_audit_add_finding(
      $state,
      'Admin UI workflows',
      $marketing_preview_ok ? 'GOOD' : 'WARNING',
      'Payment Hub / Marketing Email Lists',
      'Check live marketing email preview workflow.',
      $marketing_preview_ok ? 'Marketing Email Lists appears to include a live subject/body preview window.' : 'Marketing Email Lists does not appear to include the requested live preview window.',
      'Marketing sends should be visually reviewed before sending to a list.',
      $marketing_preview_ok ? 'No fix needed.' : 'Add the live preview window and script to render_marketing_email_lists_page().'
    );

    $unified_1099_ok = (
      strpos($scheduler, 'mrm_get_calculations_presenter_summary') !== false &&
      strpos($scheduler, 'mrm_get_paid_out_masterclass_1099_payees') !== false &&
      strpos($scheduler, 'mrm_tax_payroll_imports') !== false &&
      strpos($scheduler, 'presenters.') !== false
    );

    $this->mrm_pay_hub_temp_audit_add_finding(
      $state,
      'Admin UI workflows',
      $unified_1099_ok ? 'GOOD' : 'WARNING',
      'Scheduler / Calculations',
      'Check unified calculations and 1099 export support.',
      $unified_1099_ok ? 'Calculations appears to include Masterclass presenter payout summaries and unified 1099 export support.' : 'Calculations does not appear to include all requested presenter/payroll/1099 support.',
      'The goal is to run Calculations once and export 1099 documents for instructors, composer, and presenters.',
      $unified_1099_ok ? 'No fix needed.' : 'Add presenter summary and presenter 1099 merge support to Scheduler Calculations.'
    );
  }

  private function mrm_pay_hub_temp_audit_check_rest_routes(&$state) {
    if (empty($state['rest_routes'])) {
      $this->mrm_pay_hub_temp_audit_add_finding($state, 'REST endpoints', 'SKIPPED', 'REST scan', 'Identify registered REST routes.', 'No register_rest_route calls were found in scanned files.', 'This is fine only if no frontend depends on WordPress REST endpoints.', 'Confirm whether REST endpoints are expected for this install.');
      return;
    }

    foreach ($state['rest_routes'] as $route) {
      $full = '/' . trim($route['namespace'], '/') . '/' . trim($route['route'], '/');
      $cb = $route['callback'];
      $methods = strtoupper((string)$route['methods']);
      $perm = (string)$route['permission'];
      $callback_name = isset($cb['name']) ? (string)$cb['name'] : '';
      $callback_source = $callback_name !== '' ? $this->mrm_pay_hub_temp_audit_source_for_callable($state, $callback_name) : '';

      $this->mrm_pay_hub_temp_audit_add_finding($state, 'REST endpoints', 'GOOD', $route['file'] . ' ' . $full, 'Identify REST route.', 'Route registered with methods: ' . ($methods !== '' ? $methods : 'not detected'), 'Mapped routes help compare backend endpoints against frontend fetch calls.', 'No fix needed.');

      if (empty($cb['type'])) {
        $this->mrm_pay_hub_temp_audit_add_finding($state, 'REST endpoints', 'WARNING', $route['file'] . ' ' . $full, 'Check REST callback.', 'No obvious callback was detected in the route registration.', 'Routes without callbacks cannot respond correctly.', 'Confirm the route array includes a callback.');
      } elseif ($cb['type'] === 'method' && empty($state['methods'][$callback_name])) {
        $this->mrm_pay_hub_temp_audit_add_finding($state, 'REST endpoints', 'ERROR', $route['file'] . ' ' . $full, 'Check REST callback method.', 'Missing REST callback method: ' . $callback_name, 'Frontend API requests may fail with server errors.', 'Create the callback method or update the route registration.');
      } else {
        $this->mrm_pay_hub_temp_audit_add_finding($state, 'REST endpoints', 'GOOD', $route['file'] . ' ' . $full, 'Check REST callback.', 'Callback appears to be present.', 'Endpoint wiring appears structurally complete.', 'No fix needed.');
      }

      if ($perm === '') {
        $this->mrm_pay_hub_temp_audit_add_finding($state, 'REST endpoints', 'WARNING', $route['file'] . ' ' . $full, 'Check permission_callback.', 'No obvious permission_callback was detected.', 'Modern WordPress REST routes should explicitly declare public/protected access.', 'Add a permission_callback that returns true only for safe public reads or validates permissions/tokens/nonces for sensitive routes.');
        continue;
      }

      if (strpos($perm, '__return_true') === false) {
        $this->mrm_pay_hub_temp_audit_add_finding($state, 'REST endpoints', 'GOOD', $route['file'] . ' ' . $full, 'Check REST permissions.', 'permission_callback is present and not simply __return_true.', 'Protected route wiring is less likely to be accidentally public.', 'No fix needed.');
        continue;
      }

      $sensitive = preg_match('/payment|intent|verify|refund|cancel|register|payout|admin|stripe|calendar|email|token|access|grant|otp/i', $full . ' ' . $methods);

      if (!$sensitive) {
        $this->mrm_pay_hub_temp_audit_add_finding($state, 'REST endpoints', 'GOOD', $route['file'] . ' ' . $full, 'Check public REST permissions.', 'permission_callback appears public: __return_true.', 'Public read endpoints are acceptable when they do not mutate data or expose secrets.', 'No fix needed if this is intentionally public read-only.');
        continue;
      }

      $has_validation = $this->mrm_pay_hub_temp_audit_rest_callback_has_validation($full, $callback_source);

      $this->mrm_pay_hub_temp_audit_add_finding(
        $state,
        'REST endpoints',
        $has_validation ? 'GOOD' : 'WARNING',
        $route['file'] . ' ' . $full,
        'Check public sensitive REST callback validation.',
        $has_validation ? 'Route is public, but the callback contains validation/security indicators.' : 'Route is public and the audit did not find enough callback-level validation indicators.',
        $has_validation ? 'Customer-facing checkout/access routes often must be public, but the callback must validate payment status, tokens, emails, Stripe signatures, or required fields internally.' : 'Sensitive public routes need internal validation because permission_callback allows unauthenticated requests.',
        $has_validation ? 'No fix needed. Keep this endpoint public only if the callback validation remains in place.' : 'Review the callback and add strict validation before performing any write, grant, or payment-related action.'
      );
    }
  }


  private function mrm_pay_hub_temp_audit_check_admin_ajax_post(&$state) {
    $found = 0;
    foreach ($state['hooks'] as $hook) {
      $name = (string)$hook['hook'];
      if (strpos($name, 'admin_post_') !== 0 && strpos($name, 'wp_ajax_') !== 0 && strpos($name, 'wp_ajax_nopriv_') !== 0) continue;
      $found++; $cb = $hook['callback'];
      $sensitive = preg_match('/refund|payout|payment|stripe|cancel|delete|save|send|email|calendar|register|access|grant|export/i', $name);
      $content = $this->mrm_pay_hub_temp_audit_file_content_by_rel($state, $hook['file']);
      $callback_name = isset($cb['name']) ? (string) $cb['name'] : '';

      if ($callback_name !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $callback_name)) {
        $this->mrm_pay_hub_temp_audit_add_finding(
          $state,
          'Admin-post/admin-ajax hooks',
          'WARNING',
          $hook['file'] . ' / ' . $name,
          'Validate parsed admin-post/admin-ajax callback name.',
          'The audit parser produced a malformed callback name: ' . $callback_name,
          'Malformed callback names usually indicate an audit parser limitation, not necessarily a real endpoint failure.',
          'Review the nearby hook registration manually. If the real callback exists, no production code change is needed.'
        );
        continue;
      }

      if (!empty($cb['type']) && $cb['type'] === 'method' && empty($state['methods'][$callback_name])) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Admin-post/admin-ajax hooks', 'ERROR', $hook['file'] . ' / ' . $name, 'Check callback method exists.', 'Missing callback method: ' . $callback_name, 'The endpoint can fail when submitted.', 'Create the method or correct the hook registration.');
      else $this->mrm_pay_hub_temp_audit_add_finding($state, 'Admin-post/admin-ajax hooks', 'GOOD', $hook['file'] . ' / ' . $name, 'Check callback exists.', 'Callback appears present or uses a closure/function.', 'Endpoint wiring is structurally complete.', 'No fix needed.');
      $has_security = preg_match('/check_admin_referer|wp_verify_nonce|current_user_can|sanitize_|absint|token|hash_equals|permission|capability/i', $content . ' ' . $hook['statement']);
      if ($sensitive && !$has_security) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Admin-post/admin-ajax hooks', 'WARNING', $hook['file'] . ' / ' . $name, 'Check sensitive endpoint validation hints.', 'No obvious nonce/capability/token/sanitization indicators were found near this hook file.', 'Sensitive post/ajax actions must not be callable without validation.', 'Review the callback body and confirm nonce, capability, token, and sanitized field checks exist.');
    }
    if ($found === 0) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Admin-post/admin-ajax hooks', 'SKIPPED', 'Hook scan', 'Identify admin_post/admin_ajax hooks.', 'No admin_post/admin_ajax hooks were found.', 'This is fine only if the system does not use these endpoint types.', 'Confirm expected forms use REST or normal admin settings instead.');
  }

  private function mrm_pay_hub_temp_audit_check_cron(&$state) {
    $cron_hooks = array();
    foreach ($state['hooks'] as $hook) if (preg_match('/^mrm_.*(cron|cleanup|retry|daily|reminder|feedback|payout|reconcile|charge|subscription|payment|access|sync|check|discover|reset|due|delivered|cancelled)/i', $hook['hook'])) $cron_hooks[$hook['hook']] = $hook;
    foreach ($state['scheduled_hooks'] as $scheduled) {
      $name = $scheduled['hook'];
      if (isset($cron_hooks[$name])) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Cron/timing', 'GOOD', $scheduled['file'] . ' / ' . $name, 'Match scheduled event to callback hook.', 'Scheduled hook has a registered callback.', 'Cron jobs need callbacks or reminders/retries/payouts never run.', 'No fix needed.');
      else $this->mrm_pay_hub_temp_audit_add_finding($state, 'Cron/timing', 'ERROR', $scheduled['file'] . ' / ' . $name, 'Match scheduled event to callback hook.', 'Scheduled hook has no matching add_action callback in scanned files.', 'The event may fire but do nothing.', 'Add an add_action callback for this scheduled hook or remove the schedule.');
    }
    if (empty($state['scheduled_hooks']) && empty($cron_hooks)) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Cron/timing', 'SKIPPED', 'Cron scan', 'Identify scheduled hooks and callbacks.', 'No obvious cron hooks or schedule calls were found.', 'This is fine only if reminders, feedback, payouts, and syncs are not cron-driven.', 'Confirm timing features are handled elsewhere.');
  }

  private function mrm_pay_hub_temp_audit_check_database(&$state) {
    global $wpdb;

    if (empty($state['table_names'])) {
      $this->mrm_pay_hub_temp_audit_add_finding($state, 'Database tables', 'SKIPPED', 'Database scan', 'Identify custom table names.', 'No $wpdb->prefix table references were found.', 'This is fine only if the plugins do not use custom tables.', 'Confirm expected data is stored in options or external systems.');
      return;
    }

    foreach ($state['table_names'] as $tail => $rel) {
      $tail = (string)$tail;

      if ($tail === 'mrm_masterclass_invalid_table') {
        $this->mrm_pay_hub_temp_audit_add_finding($state, 'Database tables', 'SKIPPED', $rel . ' / ' . $tail, 'Classify table reference.', 'This is a defensive sentinel table name returned only when the Masterclass table helper receives an empty table name.', 'This is not a real required database table and should not be created.', 'No database change needed. Investigate only if another audit finding shows a blank table helper call.');
        continue;
      }

      if (!is_object($wpdb) || empty($wpdb->prefix)) {
        $this->mrm_pay_hub_temp_audit_add_finding($state, 'Database tables', 'SKIPPED', $rel . ' / ' . $tail, 'Runtime table existence check.', '$wpdb was unavailable.', 'The audit cannot confirm table existence without a database connection.', 'Rerun inside normal WordPress admin.');
        continue;
      }

      if ($tail === 'mrm_pay_orders') {
        $current_orders_table = $wpdb->prefix . 'mrm_orders';
        $legacy_orders_table = $wpdb->prefix . 'mrm_pay_orders';

        $current_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $current_orders_table));
        $legacy_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy_orders_table));

        if ($current_exists === $current_orders_table) {
          $this->mrm_pay_hub_temp_audit_add_finding($state, 'Database tables', 'SKIPPED', $rel . ' / ' . $legacy_orders_table, 'Classify legacy order table fallback.', 'Legacy table wp_mrm_pay_orders is missing, but current table wp_mrm_orders exists.', 'The current legal/payment order record table is wp_mrm_orders. Creating the old fallback table is unnecessary and could create duplicate recordkeeping confusion.', 'No database change needed.');
        } elseif ($legacy_exists === $legacy_orders_table) {
          $this->mrm_pay_hub_temp_audit_add_finding($state, 'Database tables', 'GOOD', $rel . ' / ' . $legacy_orders_table, 'Check legacy order table fallback.', 'Legacy fallback table exists.', 'Older installs may still depend on this fallback.', 'No fix needed.');
        } else {
          $this->mrm_pay_hub_temp_audit_add_finding($state, 'Database tables', 'ERROR', $rel . ' / ' . $current_orders_table, 'Check current or legacy order table.', 'Neither wp_mrm_orders nor wp_mrm_pay_orders exists.', 'Payment confirmation and legal order records may fail if no order table exists.', 'Run the Payments Hub installer/upgrade routine after backing up the database.');
        }

        continue;
      }

      $table = $wpdb->prefix . $tail;
      $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

      if ($found === $table) {
        $this->mrm_pay_hub_temp_audit_add_finding($state, 'Database tables', 'GOOD', $rel . ' / ' . $table, 'Check required table exists.', 'Table exists.', 'Existing tables reduce launch risk for orders, access, ledgers, reminders, and registrations.', 'No fix needed.');
        continue;
      }

      if ($tail === 'mrm_tax_payroll_imports') {
        $this->mrm_pay_hub_temp_audit_add_finding($state, 'Database tables', 'WARNING', $rel . ' / ' . $table, 'Check optional payroll import table.', 'The payroll import table does not exist.', 'This does not appear to block customer payments, bookings, registrations, or access. From a legal/tax perspective, it matters only if you rely on the Calculations page for imported payroll/W-2 officer compensation support.', 'Do not create this table just to satisfy the audit. Create it later only if you intentionally add payroll import functionality.');
        continue;
      }

      $this->mrm_pay_hub_temp_audit_add_finding($state, 'Database tables', 'ERROR', $rel . ' / ' . $table, 'Check required table exists.', 'Table was not found.', 'Missing tables can break payments, registrations, access gates, payouts, or logs.', 'Run the relevant plugin install/upgrade routine after backing up the database.');
    }
  }

  private function mrm_pay_hub_temp_audit_check_frontend_html_js(&$state) {
    if (empty($state['html'])) { $this->mrm_pay_hub_temp_audit_add_finding($state, 'Frontend HTML/JS', 'SKIPPED', 'HTML scan', 'Find pasted frontend HTML files.', 'No frontend HTML files were found.', 'The audit cannot verify customer-facing DOM/script consistency.', 'Confirm frontend HTML files are installed near the plugin folders.'); return; }
    foreach ($state['html'] as $file => $info) {
      $rel = $info['rel']; $content = $info['content']; $ids = $info['ids']; $refs = array();
      if (preg_match_all('/getElementById\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $m)) foreach ($m[1] as $id) $refs[$id] = 'getElementById';
      if (preg_match_all('/querySelector(?:All)?\s*\(\s*[\'"]#([A-Za-z0-9_\-:]+)[\'"]\s*\)/', $content, $m)) foreach ($m[1] as $id) $refs[$id] = 'querySelector';
      $missing = array(); foreach ($refs as $id => $source) if (!isset($ids[$id])) $missing[] = $id;
      if (empty($missing)) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Frontend HTML/JS', 'GOOD', $rel, 'Compare JavaScript ID selectors to DOM IDs.', 'No missing ID selectors were detected among ' . count($refs) . ' referenced ID(s).', 'Missing DOM IDs can stop page boot, payment setup, forms, or loading state resolution.', 'No fix needed.');
      else $this->mrm_pay_hub_temp_audit_add_finding($state, 'Frontend HTML/JS', 'WARNING', $rel, 'Compare JavaScript ID selectors to DOM IDs.', 'Missing DOM IDs referenced by JavaScript: ' . implode(', ', array_slice($missing, 0, 20)), 'Missing DOM IDs can leave frontend pages stuck or non-clickable.', 'Add the missing elements or update the script selectors.');
      $has_boot = preg_match('/DOMContentLoaded|addEventListener\s*\(\s*[\'"]load|\binit[A-Za-z0-9_]*\s*\(|\bboot[A-Za-z0-9_]*\s*\(|\bmount[A-Za-z0-9_]*\s*\(/i', $content); $has_fetch = preg_match('/\bfetch\s*\(/i', $content); $has_catch = preg_match('/\.catch\s*\(|try\s*\{|catch\s*\(/i', $content);
      if ($has_fetch && !$has_catch) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Frontend HTML/JS', 'WARNING', $rel, 'Check fetch error handling.', 'Fetch calls were found without an obvious catch/try error path.', 'Missing failure paths can leave customers stuck on loading screens.', 'Add catch/error UI handling for REST failures.');
      elseif ($has_fetch) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Frontend HTML/JS', 'GOOD', $rel, 'Check fetch error handling.', 'Fetch calls appear to have an error-handling path.', 'Customers should see clean failures instead of infinite loading.', 'No fix needed.');
      if (!$has_boot && strpos($content, '<script') !== false) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Frontend HTML/JS', 'WARNING', $rel, 'Check frontend boot signal.', 'Script tags exist but no obvious DOMContentLoaded/load/init/boot/mount signal was found.', 'Without a reliable boot path, frontend code may not initialize after paste/optimization.', 'Confirm the controller runs after the DOM exists.');
      if (strpos($content, '<script') !== false) {
        $looks_like_custom_controller = preg_match('/fetch\s*\(|addEventListener|getElementById|querySelector|Stripe\s*\(|wp-json|rest_route|mrm-|payment|checkout|otp|access|download|calendar|scheduler|masterclass|sheet/i', $content);
        $has_attrs = preg_match('/data-cfasync\s*=|data-no-optimize\s*=|data-no-defer\s*=|data-no-minify\s*=/i', $content);

        if (!$looks_like_custom_controller) {
          $this->mrm_pay_hub_temp_audit_add_finding($state, 'Frontend HTML/JS', 'SKIPPED', $rel, 'Check optimizer-resistant script attributes.', 'Script tags exist, but no custom controller indicators were detected.', 'Theme, builder, analytics, or embed scripts may not need custom optimizer-resistant attributes.', 'No fix needed unless this page contains a custom checkout, access, scheduler, masterclass, or product controller.');
        } elseif ($has_attrs) {
          $this->mrm_pay_hub_temp_audit_add_finding($state, 'Frontend HTML/JS', 'GOOD', $rel, 'Check optimizer-resistant script attributes.', 'At least one optimizer-resistant script attribute was found.', 'These attributes help prevent caching/minification/defer tools from breaking pasted page controllers.', 'No fix needed.');
        } else {
          $this->mrm_pay_hub_temp_audit_add_finding($state, 'Frontend HTML/JS', 'WARNING', $rel, 'Check optimizer-resistant script attributes.', 'Custom controller indicators were found, but no data-cfasync/data-no-optimize/data-no-defer/data-no-minify attributes were detected.', 'Optimizers can defer or rewrite inline controllers and break boot timing.', 'Add these attributes to critical frontend controller script tags where needed.');
        }
      }
    }
  }

  private function mrm_pay_hub_temp_audit_check_design_consistency(&$state) {
    $button_classes = array();
    $modal_classes = array();
    $colors = array();

    foreach ($state['html'] as $info) {
      $rel = $info['rel'];
      $content = $info['content'];

      if (preg_match_all('/class\s*=\s*[\'\"]([^\'\"]*(?:button|btn|mrm-btn)[^\'\"]*)[\'\"]/i', $content, $m)) {
        foreach ($m[1] as $class) {
          $button_classes[$class][$rel] = true;
        }
      }

      if (preg_match_all('/class\s*=\s*[\'\"]([^\'\"]*modal[^\'\"]*)[\'\"]/i', $content, $m)) {
        foreach ($m[1] as $class) {
          $modal_classes[$class][$rel] = true;
        }
      }

      if (preg_match_all('/#[0-9A-Fa-f]{6}\b/', $content, $m)) {
        foreach ($m[0] as $color) {
          $colors[strtolower($color)] = true;
        }
      }
    }

    $this->mrm_pay_hub_temp_audit_add_finding($state, 'Design/layout consistency', 'GOOD', 'Frontend HTML', 'Compare button class naming across customer-facing HTML.', 'Detected ' . count($button_classes) . ' button-related class strings. This is now treated as an advisory count rather than a launch warning.', 'Different page builders and product templates can create multiple class strings without breaking launch readiness.', 'No immediate fix needed unless a specific page looks visually inconsistent.');

    $this->mrm_pay_hub_temp_audit_add_finding($state, 'Design/layout consistency', 'GOOD', 'Frontend HTML', 'Compare modal class naming.', 'Detected ' . count($modal_classes) . ' modal-related class strings. This is now treated as an advisory count rather than a launch warning.', 'The audit should not fail launch readiness only because class names vary across different page templates.', 'No immediate fix needed unless a specific modal looks inconsistent.');

    if (isset($colors['#95c2d8'])) {
      $this->mrm_pay_hub_temp_audit_add_finding($state, 'Design/layout consistency', 'GOOD', 'Frontend HTML', 'Check recurring brand accent color.', 'Detected #95c2d8 in frontend styling.', 'Shared brand color usage helps page consistency.', 'No fix needed.');
    }
  }

  private function mrm_pay_hub_temp_audit_check_payment_flows(&$state) {
    $found_payment_code = false;
    foreach ($state['file_contents'] as $file => $content) { $rel = $this->mrm_pay_hub_temp_audit_rel($file); if (!preg_match('/stripe|payment_intent|PaymentIntent|card|promo|discount|tax|total/i', $content)) continue; $found_payment_code = true;
      if (preg_match('/sk_(?:test|live)_[A-Za-z0-9_\-]+/', $content)) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Payment/customer flows', 'ERROR', $rel, 'Check for exposed Stripe secret keys.', 'A Stripe secret-key-looking value was found in source.', 'Secret keys must never be exposed in plugin files or frontend HTML.', 'Move secrets to AWS Secrets Manager, wp-config, or server environment and rotate the key.');
      if (preg_match('/pk_(?:test|live)_[A-Za-z0-9_\-]+/', $content)) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Payment/customer flows', 'GOOD', $rel, 'Check Stripe publishable-key usage.', 'A publishable-key-looking value/reference was found.', 'Frontend Stripe.js should use publishable keys only.', 'No fix needed if no secret keys are exposed.');
      if (preg_match('/create-payment-intent|PaymentIntent::create|payment_intents/i', $content)) { $valid = preg_match('/sanitize_|absint|floatval|intval|email|sku|amount|currency|metadata|promo|tax/i', $content); $this->mrm_pay_hub_temp_audit_add_finding($state, 'Payment/customer flows', $valid ? 'GOOD' : 'WARNING', $rel, 'Check payment-intent validation hints.', $valid ? 'Payment code contains field validation/calculation indicators.' : 'No obvious validation/calculation indicators were detected in payment code.', 'Payment endpoints must validate amount/product/customer fields server-side.', $valid ? 'No fix needed, but manually review final amount/tax/promo calculations.' : 'Review the callback and validate product, total, tax, discount, and customer fields server-side.'); }
    }
    if (!$found_payment_code) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Payment/customer flows', 'SKIPPED', 'Payment scan', 'Identify payment/customer flow code.', 'No obvious payment-related code was found.', 'This is unexpected for Payment Hub unless payment code is outside the scan roots.', 'Confirm Payment Hub was included in scan roots.');
  }

  private function mrm_pay_hub_temp_audit_check_email_calendar_access(&$state) {
    $found = false;
    foreach ($state['file_contents'] as $file => $content) { if (!preg_match('/wp_mail|send_mail|email|Google|Calendar|meet\.google|zoom\.us|access gate|token|reminder|feedback|confirmation/i', $content)) continue; $found = true; $rel = $this->mrm_pay_hub_temp_audit_rel($file);
      if (preg_match('/wp_mail|send_mail|email/i', $content)) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Email/calendar/access gates', 'GOOD', $rel, 'Identify email flow code.', 'Email-related code was found.', 'Confirmation, reminder, feedback, and test email flows can be manually reviewed from this location.', 'No fix needed.');
      if (preg_match('/Calendar|googleapis|meet\.google/i', $content)) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Email/calendar/access gates', 'GOOD', $rel, 'Identify calendar/meeting code.', 'Calendar or meeting-link code was found.', 'Calendar writes and protected meeting gates should be manually verified without triggering live writes.', 'No fix needed.');
      if (preg_match('/meet\.google\.com|zoom\.us/i', $content) && preg_match('/frontend|\.html/i', $rel)) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Email/calendar/access gates', 'WARNING', $rel, 'Check raw meeting links in public frontend files.', 'A raw meeting URL pattern was found in a public-facing frontend file.', 'Raw meeting links can bypass protected access gates.', 'Replace public raw meeting links with protected gate links unless this is only placeholder text.');
      if (preg_match('/token/i', $content)) {
        $valid = preg_match('/hash_equals|expires|current_time|sanitize|wp_verify_nonce|nonce|email_hash|otp|payment_intent|stripe/i', $content);
        $looks_like_protected_gate = preg_match('/gate|access|download|meet|zoom|protected|verify|otp/i', $content);

        if ($valid || !$looks_like_protected_gate) {
          $this->mrm_pay_hub_temp_audit_add_finding($state, 'Email/calendar/access gates', 'GOOD', $rel, 'Check access-token validation hints.', $valid ? 'Token-related code includes validation/expiration indicators.' : 'Token wording was detected, but this file does not appear to be a protected access gate.', 'Protected gates need token checks, expiration, and safe comparisons.', 'No fix needed, but manually verify access windows on protected pages.');
        } else {
          $this->mrm_pay_hub_temp_audit_add_finding($state, 'Email/calendar/access gates', 'WARNING', $rel, 'Check access-token validation hints.', 'Token-related code was found without obvious validation/expiration indicators.', 'Weak token checks can allow link sharing or blocked legitimate access.', 'Review token validation, expiration, device lock, and reconnect behavior.');
        }
      }
    }
    if (!$found) $this->mrm_pay_hub_temp_audit_add_finding($state, 'Email/calendar/access gates', 'SKIPPED', 'Email/calendar/access scan', 'Identify email, calendar, and access-gate code.', 'No related code was detected.', 'This is unexpected if lessons/masterclasses/meetings are in scope.', 'Confirm those plugins are installed in scan roots.');
  }

  private function mrm_pay_hub_temp_audit_check_public_debug(&$state) {
    $patterns = array(
      '/\bvar_dump\s*\(/i' => 'var_dump call',
      '/\bprint_r\s*\(/i' => 'print_r call',
      '/console\.log\s*\(/i' => 'console.log call',
      '/debug panel|debug route|debug output|health check|launch debug|raw json|paste this into console|diagnostic panel|diagnostic route|diagnostic output|diagnostic log/i' => 'public debug/diagnostic tooling wording',
      '/MRM_LAUNCH_DEBUG\s*,\s*true/i' => 'MRM_LAUNCH_DEBUG enabled'
    );

    $hits = 0;

    foreach ($state['file_contents'] as $file => $content) {
      $rel = $this->mrm_pay_hub_temp_audit_rel($file);
      $is_public_page = strpos($rel, 'WP Page') === 0 || strpos($rel, 'frontend/') !== false || preg_match('/\.html?$/i', $rel);

      if (!$is_public_page) {
        continue;
      }

      foreach ($patterns as $regex => $label) {
        if (preg_match($regex, $content)) {
          $hits++;
          $this->mrm_pay_hub_temp_audit_add_finding($state, 'Public debug/diagnostic cleanup', 'WARNING', $rel, 'Scan public-facing page content for debug/diagnostic leftovers.', 'Found indicator: ' . $label, 'Debug leftovers can expose internals or make launch pages look unfinished.', 'Remove customer-facing debug output or wrap it behind admin-only checks and disabled constants.');
        }
      }
    }

    if ($hits === 0) {
      $this->mrm_pay_hub_temp_audit_add_finding($state, 'Public debug/diagnostic cleanup', 'GOOD', 'Public pages and frontend HTML', 'Scan public-facing page content for debug/diagnostic leftovers.', 'No obvious public-facing debug/diagnostic output indicators were found.', 'Customer-facing pages are less likely to expose internal testing labels.', 'No fix needed.');
    }
  }

  private function mrm_pay_hub_temp_audit_strip_temp_audit_block($content) {
    /*
     * Only strip blocks whose marker is exactly:
     * // BEGIN TEMP PAYMENT HUB AUDIT
     * ...
     * // END TEMP PAYMENT HUB AUDIT
     *
     * Do not strip repair blocks such as:
     * // BEGIN TEMP PAYMENT HUB AUDIT FIX - ...
     *
     * This prevents the audit from hiding real repair methods such as
     * handle_marketing_unsubscribe_confirm() and handle_marketing_unsubscribe_do().
     */
    return preg_replace(
      '/^[ \t]*\/\/ BEGIN TEMP PAYMENT HUB AUDIT[ \t]*\R.*?^[ \t]*\/\/ END TEMP PAYMENT HUB AUDIT[ \t]*$/ms',
      '/* temporary audit block omitted from self-scan */',
      (string) $content
    );
  }

  private function mrm_pay_hub_temp_audit_collect_wp_page_html(&$state) {
    if (!function_exists('get_posts')) {
      $this->mrm_pay_hub_temp_audit_add_finding($state, 'Frontend HTML/JS', 'SKIPPED', 'WordPress Pages', 'Load WordPress Pages for frontend HTML scanning.', 'get_posts() was unavailable.', 'The audit cannot inspect pasted frontend HTML stored in Pages.', 'Run this audit from normal WordPress admin.');
      return;
    }

    $pages = get_posts(array(
      'post_type' => 'page',
      'post_status' => array('publish', 'draft', 'private'),
      'numberposts' => 150,
      'orderby' => 'modified',
      'order' => 'DESC',
      'suppress_filters' => true,
    ));

    if (empty($pages)) {
      $this->mrm_pay_hub_temp_audit_add_finding($state, 'Frontend HTML/JS', 'SKIPPED', 'WordPress Pages', 'Load WordPress Pages for frontend HTML scanning.', 'No WordPress Pages were found.', 'The audit cannot inspect pasted frontend HTML if no pages are returned.', 'Confirm the customer-facing pages are stored as WordPress Pages.');
      return;
    }

    $added = 0;

    foreach ($pages as $page) {
      if (!is_object($page) || empty($page->ID)) {
        continue;
      }

      $chunks = array((string)$page->post_content);

      $elementor_data = get_post_meta($page->ID, '_elementor_data', true);
      if (!empty($elementor_data)) {
        $chunks[] = is_string($elementor_data) ? $elementor_data : wp_json_encode($elementor_data);
      }

      $content = implode("\n\n", array_filter($chunks));
      $content = $this->mrm_pay_hub_temp_audit_redact($content);

      if (trim($content) === '') {
        continue;
      }

      if (!preg_match('/<script|<style|<form|<button|<div|mrm-|stripe|masterclass|scheduler|lesson|sheet|otp|payment|access|modal|data-no-|wp-json|rest_route/i', $content)) {
        continue;
      }

      if (strlen($content) > 1600000) {
        $content = substr($content, 0, 1600000);
        $this->mrm_pay_hub_temp_audit_add_finding($state, 'Frontend HTML/JS', 'SKIPPED', 'WP Page #' . absint($page->ID), 'Read WordPress Page HTML size.', 'Page content was truncated for audit safety.', 'Very large builder pages can time out shared hosting scans.', 'Manually inspect the full page if this page contains critical checkout, scheduler, masterclass, or access code.');
      }

      $ids = array();
      if (preg_match_all('/\bid\s*=\s*[\'"]([^\'"]+)[\'"]/i', $content, $id_matches)) {
        foreach ($id_matches[1] as $id) {
          $ids[$id] = true;
        }
      }

      $title = get_the_title($page);
      $permalink = get_permalink($page);
      $rel = 'WP Page: ' . ($title ? $title : 'Untitled') . ' (#' . absint($page->ID) . ')';
      if ($permalink) {
        $rel .= ' / ' . $permalink;
      }

      $key = 'wp-page://' . absint($page->ID);
      $state['file_contents'][$key] = $content;
      $state['html'][$key] = array(
        'rel' => $rel,
        'ids' => $ids,
        'content' => $content,
      );

      $added++;
    }

    if ($added > 0) {
      $this->mrm_pay_hub_temp_audit_add_finding($state, 'Frontend HTML/JS', 'GOOD', 'WordPress Pages', 'Load WordPress Pages for frontend HTML scanning.', 'Added ' . $added . ' WordPress Page(s) to the frontend HTML audit.', 'This lets the audit inspect pasted customer-facing HTML that lives in the Pages section instead of plugin files.', 'No fix needed.');
    } else {
      $this->mrm_pay_hub_temp_audit_add_finding($state, 'Frontend HTML/JS', 'WARNING', 'WordPress Pages', 'Load WordPress Pages for frontend HTML scanning.', 'Pages were found, but none matched frontend HTML/script markers.', 'The audit may still miss builder-rendered HTML if it is stored in custom builder metadata.', 'Confirm the key customer-facing pages contain pasted HTML or add their builder meta keys to the audit.');
    }
  }

  private function mrm_pay_hub_temp_audit_first_string_arg($args) { if (preg_match('/^[\s\(]*[\'"]([^\'"]+)[\'"]/', (string)$args, $m)) return $m[1]; return ''; }
  private function mrm_pay_hub_temp_audit_extract_callback($text) { $text = (string)$text; if (preg_match('/function\s*\(/', $text)) return array('type' => 'closure', 'name' => 'closure'); if (preg_match('/(?:array\s*\(\s*\$this\s*,\s*|\[\s*\$this\s*,\s*)[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/s', $text, $m)) return array('type' => 'method', 'name' => $m[1]); if (preg_match('/callback[\'"]?\s*=>\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/s', $text, $m)) return array('type' => 'function', 'name' => $m[1]); if (preg_match('/,\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*(?:,|\))/s', $text, $m)) return array('type' => 'function', 'name' => $m[1]); return array('type' => '', 'name' => ''); }
  private function mrm_pay_hub_temp_audit_extract_permission_callback($block) { if (preg_match('/permission_callback[\'"]?\s*=>\s*(.*?)(?:,\s*[\'"][A-Za-z_]|\n\s*\)|\n\s*array|\n\s*\])/s', (string)$block, $m)) return trim(preg_replace('/\s+/', ' ', $m[1])); return ''; }
  private function mrm_pay_hub_temp_audit_extract_rest_methods($block) { if (preg_match('/methods[\'"]?\s*=>\s*(.*?)(?:,\s*[\'"][A-Za-z_]|\n\s*\)|\n\s*array|\n\s*\])/s', (string)$block, $m)) return trim(preg_replace('/\s+/', ' ', $m[1])); return ''; }
  private function mrm_pay_hub_temp_audit_source_for_callable($state, $callable_name) {
    $callable_name = preg_quote((string)$callable_name, '/');

    foreach ($state['file_contents'] as $content) {
      if (preg_match('/function\s+' . $callable_name . '\s*\([^)]*\)\s*\{/s', $content, $m, PREG_OFFSET_CAPTURE)) {
        $start = (int)$m[0][1];
        return substr($content, $start, 9000);
      }
    }

    return '';
  }

  private function mrm_pay_hub_temp_audit_rest_callback_has_validation($route, $source) {
    $route = strtolower((string)$route);
    $source = (string)$source;

    $checks = array(
      'sanitize' => preg_match('/sanitize_|absint|intval|floatval|is_email|sanitize_email|sanitize_text_field|sanitize_key/i', $source),
      'required_fields' => preg_match('/return\s+new\s+WP_REST_Response|WP_Error|missing|required|valid|required/i', $source),
      'stripe_payment' => preg_match('/stripe_retrieve_payment_intent|payment_intent|PaymentIntent|status.+succeeded|requires_capture|amount|currency|metadata/i', $source),
      'stripe_webhook' => preg_match('/stripe_construct_webhook_event|HTTP_STRIPE_SIGNATURE|webhook_secret|signature|event_id|event_type/i', $source),
      'access_token' => preg_match('/token|email_hash|hash_equals|expires|revoked_at|otp|one.?time|verify/i', $source),
      'admin_gate' => preg_match('/current_user_can|manage_options|permission|nonce|wp_verify_nonce/i', $source),
    );

    if (strpos($route, 'stripe-webhook') !== false) {
      return !empty($checks['stripe_webhook']);
    }

    if (strpos($route, 'payment-intent') !== false || strpos($route, 'setup-intent') !== false) {
      return !empty($checks['sanitize']) && !empty($checks['required_fields']) && !empty($checks['stripe_payment']);
    }

    if (strpos($route, 'grant-sheet-music-access') !== false) {
      return !empty($checks['sanitize']) && !empty($checks['stripe_payment']);
    }

    if (strpos($route, 'has-access') !== false || strpos($route, 'access-context') !== false || strpos($route, 'verify-otp') !== false) {
      return !empty($checks['sanitize']) && !empty($checks['access_token']);
    }

    return !empty($checks['sanitize']) && (!empty($checks['required_fields']) || !empty($checks['access_token']) || !empty($checks['admin_gate']));
  }

  private function mrm_pay_hub_temp_audit_file_content_by_rel($state, $rel) { foreach ($state['file_contents'] as $file => $content) if ($this->mrm_pay_hub_temp_audit_rel($file) === $rel) return $content; return ''; }
  private function mrm_pay_hub_temp_audit_launch_status($counts) { $errors = isset($counts['ERROR']) ? (int)$counts['ERROR'] : 0; $warnings = isset($counts['WARNING']) ? (int)$counts['WARNING'] : 0; if ($errors >= 5) return 'FAIL'; if ($errors > 0) return 'NEEDS ATTENTION'; if ($warnings > 0) return 'PASS WITH WARNINGS'; return 'PASS'; }
  private function mrm_pay_hub_temp_audit_add_finding(&$state, $section, $severity, $location, $checked, $found, $matters, $fix) { $severity = strtoupper((string)$severity); if (!isset($state['counts'][$severity])) $severity = 'WARNING'; if (!isset($state['findings'][$section])) $state['findings'][$section] = array(); $state['counts'][$severity]++; $state['findings'][$section][] = array('severity' => $severity, 'location' => $this->mrm_pay_hub_temp_audit_redact($location), 'checked' => $this->mrm_pay_hub_temp_audit_redact($checked), 'found' => $this->mrm_pay_hub_temp_audit_redact($found), 'matters' => $this->mrm_pay_hub_temp_audit_redact($matters), 'fix' => $this->mrm_pay_hub_temp_audit_redact($fix)); }

  private function mrm_pay_hub_temp_audit_format_log($state, $status) {
    $lines = array('LOW BRASS LESSONS / PAYMENT HUB TEMP AUDIT LOG', '=================================================', 'Timestamp: ' . $this->mrm_pay_hub_temp_audit_redact($state['started_at']), 'Site URL: ' . $this->mrm_pay_hub_temp_audit_redact($state['site_url']), 'Active Theme: ' . $this->mrm_pay_hub_temp_audit_redact($state['active_theme']), 'Overall launch readiness: ' . $status, '', 'Audit summary:', '- Good findings: ' . (int)$state['counts']['GOOD'], '- Warnings: ' . (int)$state['counts']['WARNING'], '- Errors: ' . (int)$state['counts']['ERROR'], '- Skipped checks: ' . (int)$state['counts']['SKIPPED'], '- Files scanned: ' . count($state['file_contents']), '');
    foreach ($state['findings'] as $section => $findings) { $lines[] = ''; $lines[] = $section; $lines[] = str_repeat('-', strlen($section)); if (empty($findings)) { $lines[] = 'No findings recorded.'; continue; } $i = 1; foreach ($findings as $finding) { $lines[] = $i . '. Severity: ' . $finding['severity']; $lines[] = '   File/function/route/hook: ' . $finding['location']; $lines[] = '   What was checked: ' . $finding['checked']; $lines[] = '   What was found: ' . $finding['found']; $lines[] = '   Why it matters: ' . $finding['matters']; $lines[] = '   Suggested fix: ' . $finding['fix']; $i++; } }
    $lines[] = ''; $lines[] = 'Temporary audit notes:'; $lines[] = '- This audit is heuristic and read-only except for writing this log file.'; $lines[] = '- It does not trigger payments, refunds, payouts, emails, registrations, cancellations, Stripe writes, or Google Calendar writes.'; $lines[] = '- Manually verify any WARNING/ERROR before launch.'; return implode("\n", array_map(array($this, 'mrm_pay_hub_temp_audit_redact'), $lines)) . "\n";
  }
  private function mrm_pay_hub_temp_audit_write_log($log) { $path = trailingslashit(WP_CONTENT_DIR) . 'mrm-payment-hub-audit.log'; $bytes = @file_put_contents($path, $log, LOCK_EX); return ($bytes !== false); }
  private function mrm_pay_hub_temp_audit_write_emergency_log($message) { $path = trailingslashit(WP_CONTENT_DIR) . 'mrm-payment-hub-audit.log'; $log = 'LOW BRASS LESSONS / PAYMENT HUB TEMP AUDIT LOG' . "\n"; $log .= 'Timestamp: ' . current_time('mysql') . "\n"; $log .= 'Overall launch readiness: FAIL' . "\n"; $log .= 'Audit failed before completion: ' . $this->mrm_pay_hub_temp_audit_redact($message) . "\n"; @file_put_contents($path, $log, LOCK_EX); }
  private function mrm_pay_hub_temp_audit_rel($path) { $path = str_replace('\\', '/', (string)$path); if (strpos($path, 'wp-page://') === 0) return 'WP Page #' . absint(substr($path, strlen('wp-page://'))); $bases = array(); if (defined('WP_CONTENT_DIR')) $bases[] = str_replace('\\', '/', WP_CONTENT_DIR); if (defined('ABSPATH')) $bases[] = str_replace('\\', '/', ABSPATH); foreach ($bases as $base) { $base = rtrim($base, '/'); if ($base !== '' && strpos($path, $base) === 0) return ltrim(substr($path, strlen($base)), '/'); } return basename($path); }
  private function mrm_pay_hub_temp_audit_redact($value) { $value = (string)$value; $patterns = array('/sk_(?:live|test)_[A-Za-z0-9_\-]+/' => '[REDACTED_STRIPE_SECRET_KEY]', '/rk_(?:live|test)_[A-Za-z0-9_\-]+/' => '[REDACTED_STRIPE_RESTRICTED_KEY]', '/whsec_[A-Za-z0-9_\-]+/' => '[REDACTED_STRIPE_WEBHOOK_SECRET]', '/Bearer\s+[A-Za-z0-9_\.\-]+/i' => 'Bearer [REDACTED_TOKEN]', '/password\s*[=:]\s*[^\s,;]+/i' => 'password=[REDACTED]', '/passwd\s*[=:]\s*[^\s,;]+/i' => 'passwd=[REDACTED]', '/private_key\s*[=:]\s*[^\s,;]+/i' => 'private_key=[REDACTED]', '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s' => '[REDACTED_PRIVATE_KEY]', '/\b\d{3}-\d{2}-\d{4}\b/' => '[REDACTED_SSN]', '/\b\d{2}-\d{7}\b/' => '[REDACTED_TAX_ID]', '/wordpress_logged_in_[^=\s]+=[^\s;]+/i' => 'wordpress_logged_in_[REDACTED_COOKIE]'); foreach ($patterns as $regex => $replacement) $value = preg_replace($regex, $replacement, $value); return $value; }
  // END TEMP PAYMENT HUB AUDIT

}

global $mrm_pay_hub_singleton;
$mrm_pay_hub_singleton = new MRM_Payments_Hub_Single();

function mrm_pay_hub_singleton() {
  global $mrm_pay_hub_singleton;
  return $mrm_pay_hub_singleton instanceof MRM_Payments_Hub_Single ? $mrm_pay_hub_singleton : null;
}

if (!function_exists('mrm_payments_hub_resolve_promo_discount')) {
  function mrm_payments_hub_resolve_promo_discount($promo_code, $amount_cents, $context = array()) {
    $promo_code = strtoupper(trim(sanitize_text_field((string)$promo_code)));
    $amount_cents = max(0, absint($amount_cents));

    if ($promo_code === '' || $amount_cents <= 0) {
      return array('ok'=>false,'discount_cents'=>0,'message'=>'No promotional code was provided.');
    }

    $hub = mrm_pay_hub_singleton();
    if (is_object($hub) && method_exists($hub, 'resolve_promo_discount')) {
      return $hub->resolve_promo_discount($promo_code, $amount_cents, $context);
    }

    return array('ok'=>false,'discount_cents'=>0,'message'=>'Promotional codes are unavailable right now.');
  }
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
  wp_clear_scheduled_hook('mrm_pay_hub_daily_presenter_payout_check');
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
