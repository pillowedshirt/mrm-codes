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

  public function __construct() {
    add_action('admin_menu', array($this, 'admin_menu'));
    add_action('admin_init', array($this, 'handle_admin_post'));
    add_action('rest_api_init', array($this, 'register_routes'));
    // Ensure DB tables exist even after plugin updates (activation hook is not enough).
    add_action('init', array($this, 'maybe_install_or_upgrade_db'), 5);
    add_action('mrm_pay_hub_cleanup_access', array($this, 'cleanup_access'));

    register_activation_hook(__FILE__, array($this, 'on_activate'));

  }

  /* =========================================================
   * Activation / DB
   * ======================================================= */

  public function on_activate() {
    // Create or upgrade database tables
    $this->install_or_upgrade_db();
    // Ensure the default lesson products are present
    $this->ensure_default_products();
    if (!wp_next_scheduled('mrm_pay_hub_cleanup_access')) {
      wp_schedule_event(time() + 300, 'hourly', 'mrm_pay_hub_cleanup_access');
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

  private function table_sheet_music_access() {
    global $wpdb;
    return $wpdb->prefix . 'mrm_sheet_music_access';
  }

  public function maybe_install_or_upgrade_db() {
    // Run a lightweight existence check; only dbDelta if needed.
    global $wpdb;

    $orders = $this->table_orders();
    $links  = $this->table_links();
    $access = $this->table_sheet_music_access();

    $missing = false;

    foreach (array($orders, $links, $access) as $t) {
      $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
      if ($found !== $t) { $missing = true; break; }
    }

    if ($missing) {
      error_log('[MRM Payments Hub] DB tables missing; running install_or_upgrade_db().');
      $this->install_or_upgrade_db();
    }
  }

  private function install_or_upgrade_db() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    $orders = $this->table_orders();
    $links  = $this->table_links();
    $access = $this->table_sheet_music_access();

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

    dbDelta($sql_orders);
    dbDelta($sql_links);
    dbDelta($sql_access);
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
    $s = $this->get_settings();
    $sku = isset($s['all_sheet_music_sku']) ? (string)$s['all_sheet_music_sku'] : '';
    $sku = strtolower(trim($sku));
    $sku = preg_replace('/[^a-z0-9\-_]+/', '', $sku);
    if (!$sku) $sku = 'piece-all-sheet-music-access-complete-package';
    return $sku;
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

  private function stripe_api_request($method, $path, $params = array()) {
    $key = $this->secret_key();
    if (!$key) return new WP_Error('stripe_not_configured', 'Stripe secret key is not configured.');

    $url = 'https://api.stripe.com' . $path;

    $args = array(
      'method'  => strtoupper($method),
      'timeout' => 30,
      'headers' => array(
        'Authorization' => 'Bearer ' . $key,
        'Content-Type'  => 'application/x-www-form-urlencoded',
      ),
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

  private function stripe_create_payment_intent($amount_cents, $currency, $metadata, $description = '', $extra_params = array()) {
    $params = array(
      'amount' => (int)$amount_cents,
      'currency' => $currency ?: 'usd',
      'automatic_payment_methods[enabled]' => 'true',
    );
    if ($description) $params['description'] = $description;

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

  private function attach_payment_intent_to_order($order_id, $payment_intent_id, $stripe_status = null) {
    global $wpdb;
    $wpdb->update($this->table_orders(), array(
      'stripe_payment_intent_id' => $payment_intent_id,
      'stripe_status' => $stripe_status,
      'updated_at' => current_time('mysql'),
    ), array('id' => (int)$order_id), array('%s','%s','%s'), array('%d'));
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
      $data['metadata_json'] = wp_json_encode($metadata);
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

  private function decode_email_hash(string $hash): ?string {
    $map = get_option('mrm_pay_hub_email_map', array());
    if (!is_array($map)) return null;
    $email = $map[$hash] ?? null;
    return $email && is_email($email) ? $email : null;
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
      $lesson_length = sanitize_text_field((string)($context['lesson_length'] ?? '')); // "30" or "60"
      $lesson_mode = sanitize_text_field((string)($context['lesson_mode'] ?? ''));     // "online" or "in_person"

      if ($lesson_id) $metadata['mrm_lesson_id'] = $lesson_id;
      if ($instructor_id) $metadata['mrm_instructor_id'] = $instructor_id;
      if ($lesson_length) $metadata['mrm_lesson_length'] = $lesson_length;
      if ($lesson_mode) $metadata['mrm_lesson_mode'] = $lesson_mode;

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

    register_rest_route('mrm-pay/v1', '/verify-payment-intent', array(
      'methods' => WP_REST_Server::CREATABLE,
      'callback' => array($this, 'rest_verify_payment_intent'),
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

    $base_amount_cents = (int)$amount;
    $addon_amount_cents = $addon_selected ? 500 : 0;
    $addon_tax_cents = $addon_selected ? $this->mrm_tax_cents_for_addon($address) : 0;
    $final_amount_cents = $base_amount_cents + $addon_amount_cents + $addon_tax_cents;

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
    $metadata['mrm_addon_tax_cents'] = (string)$addon_tax_cents;

    // Create internal order first so order_id can be labeled in Stripe metadata
    $order_id = $this->create_order($email_hash, $sku, $product_type, $final_amount_cents, $currency, $metadata);
    $metadata['mrm_order_id'] = (string)$order_id;

    $description = ($product_type === 'lesson') ? 'MRM Lesson' : 'MRM Sheet Music';

    $save_card = !empty($data['save_card']);

    $extra = array();
    $customer_id = '';

    if ($save_card) {
      $customer_id = $this->stripe_find_or_create_customer($email);
      if (is_wp_error($customer_id)) {
        return new WP_REST_Response(array('ok'=>false,'message'=>$customer_id->get_error_message()), 500);
      }

      // This tells Stripe to attach the payment method to the customer for future off-session charges.
      $extra['customer'] = $customer_id;
      $extra['setup_future_usage'] = 'off_session';

      $metadata['mrm_save_card'] = 'yes';
    }

    $pi = $this->stripe_create_payment_intent($final_amount_cents, $currency, $metadata, $description, $extra);
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
      'order_id' => (int)$order_id,
      'customer_id' => $customer_id ? (string)$customer_id : '',
      'sku' => $sku,
      'label' => (string)($p['label'] ?? $sku),
      'amount_cents' => $final_amount_cents,
      'currency' => $currency,
      'product_type' => $product_type,
      'category' => (string)($p['category'] ?? ''),
      'composer_pct' => isset($p['composer_pct']) ? (int)$p['composer_pct'] : null,
      // For debugging. Remove later if you want.
      'metadata' => $metadata,
    ), 200);
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

    // If this PaymentIntent was created with a customer + setup_future_usage,
    // Stripe should attach the payment method to the customer.
    // We log proof and force-set default PM for off-session charges.
    if ($ok) {
      $customer_id = isset($pi['customer']) ? (string)$pi['customer'] : '';
      $pm_id = isset($pi['payment_method']) ? (string)$pi['payment_method'] : '';
      $sfu = isset($pi['setup_future_usage']) ? (string)$pi['setup_future_usage'] : '';

      if ($customer_id && $pm_id) {
        error_log('[MRM Payments Hub] verify PI succeeded. customer=' . $customer_id . ' pm=' . $pm_id . ' setup_future_usage=' . $sfu);

        // Best-effort attach (harmless if already attached; Stripe may error if already attached to another customer)
        $attach = $this->stripe_attach_payment_method_to_customer($pm_id, $customer_id);
        if (is_wp_error($attach)) {
          error_log('[MRM Payments Hub] attach PM failed: ' . $attach->get_error_message() . ' data=' . wp_json_encode($attach->get_error_data()));
        }

        // Set as default for off-session usage
        $set = $this->stripe_set_default_payment_method($customer_id, $pm_id);
        if (is_wp_error($set)) {
          error_log('[MRM Payments Hub] set default PM failed: ' . $set->get_error_message() . ' data=' . wp_json_encode($set->get_error_data()));
        } else {
          error_log('[MRM Payments Hub] default PM set for customer=' . $customer_id);
        }
      } else {
        // If user expected autopay but no customer/pm is present, log it loudly
        error_log('[MRM Payments Hub] verify PI succeeded but missing customer/pm. customer=' . $customer_id . ' pm=' . $pm_id . ' setup_future_usage=' . $sfu);
      }
    }

    $meta = isset($pi['metadata']) && is_array($pi['metadata']) ? $pi['metadata'] : array();
    $addon_yes = (isset($meta['mrm_sheet_music_addon']) && strtolower((string)$meta['mrm_sheet_music_addon']) === 'yes');

    if ($ok && $addon_yes) {
      $email = sanitize_email((string)($meta['mrm_customer_email'] ?? ''));
      if ($email && is_email($email)) {
        $start_ts = 0;
        if (isset($pi['charges']['data'][0]['created'])) {
          $start_ts = (int)$pi['charges']['data'][0]['created'];
        } elseif (isset($pi['created'])) {
          $start_ts = (int)$pi['created'];
        } else {
          $start_ts = time();
        }
        $this->mrm_grant_all_sheet_music_ledger($email, $start_ts, 'stripe_pi_addon', $pi_id);
      }
    }

    // Update local order status if it exists
    $order = $this->get_order_by_pi($pi_id);
    if ($order) {
      $new_status = $ok ? 'paid' : (in_array($status, $fail_statuses, true) ? 'failed' : 'created');
      $this->update_order_status_from_pi($pi_id, $new_status, $status, $pi['metadata'] ?? null);
    }

    return new WP_REST_Response(array(
      'ok' => $ok,
      'status' => $status,
      'amount_cents' => (int)($pi['amount'] ?? 0),
      'currency' => (string)($pi['currency'] ?? 'usd'),
      'metadata' => (array)($pi['metadata'] ?? array()),
    ), 200);
  }

  public function rest_grant_sheet_music_access(WP_REST_Request $req) {
    $data = (array) $req->get_json_params();

    // Accept sku or product_slug (canonical is product_slug)
    $incoming_slug = isset($data['product_slug']) ? $data['product_slug'] : ($data['sku'] ?? '');
    $sku   = $this->sanitize_sku($incoming_slug); // keep sanitize_sku for now, but we treat sku == product_slug
    $email = sanitize_email((string)($data['email'] ?? ''));
    $pi_id = sanitize_text_field((string)($data['payment_intent_id'] ?? ''));

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

    // If payment_intent_id is provided, require it to be succeeded.
    if ($pi_id) {
      $pi = $this->stripe_retrieve_payment_intent($pi_id);
      if (is_wp_error($pi)) return new WP_REST_Response(array('ok'=>false,'message'=>$pi->get_error_message()), 500);

      $status = (string)($pi['status'] ?? '');
      if ($status !== 'succeeded') {
        return new WP_REST_Response(array('ok'=>false,'message'=>'PaymentIntent not succeeded.'), 400);
      }
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

    $ok = $this->grant_sheet_music_access($email_hash, $sku, $pi_id ? 'stripe_pi' : 'manual', $pi_id);
    if (!$ok) {
      global $wpdb;
      $last = $wpdb->last_error ? $wpdb->last_error : 'unknown_db_error';
      error_log('[MRM Payments Hub] grant_sheet_music_access insert failed sku=' . $sku . ' email_hash=' . $email_hash . ' err=' . $last);

      return new WP_REST_Response(array(
        'ok' => false,
        'message' => 'Failed to grant access.',
        'debug' => 'db_insert_failed'
      ), 500);
    }

    // Granting rule: add to per-product list as well
    $this->add_email_to_access_list($sku, $email);

    // Also append plaintext email to the approved list for admin visibility.
    $lists = $this->get_email_lists();
    if (!isset($lists[$sku]) || !is_array($lists[$sku])) $lists[$sku] = array();
    $lower = strtolower($email);
    if (!in_array($lower, $lists[$sku], true)) {
      $lists[$sku][] = $lower;
      sort($lists[$sku]);
      $this->save_email_lists($lists);
    }

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

  private function grant_sheet_music_access($email_hash, $sku, $source = null, $source_id = null) {
    global $wpdb;
    $table = $this->table_sheet_music_access();
    $now = current_time('mysql');

    // If already granted (active row exists), treat as idempotent success.
    $existing = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$table} WHERE email_hash = %s AND sku = %s AND revoked_at IS NULL LIMIT 1",
      $email_hash, $sku
    ));
    if ($existing) return true;

    $ins = $wpdb->insert($table, array(
      'email_hash' => $email_hash,
      'sku' => $sku,
      'granted_at' => $now,
      'revoked_at' => null,
      'source' => $source,
      'source_id' => $source_id,
    ), array('%s','%s','%s','%s','%s','%s'));

    return (bool)$ins;
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

    if (isset($_POST['mrm_pay_hub_nonce']) && wp_verify_nonce($_POST['mrm_pay_hub_nonce'], 'mrm_pay_hub_save')) {
      $settings = $this->get_settings();
      // Stripe environment mode: 'live' or 'test'
      $settings['stripe_mode'] = sanitize_text_field((string)($_POST['stripe_mode'] ?? 'live'));
      // Live keys
      $settings['stripe_publishable_key'] = sanitize_text_field((string)($_POST['stripe_publishable_key'] ?? ''));
      $settings['stripe_secret_key'] = sanitize_text_field((string)($_POST['stripe_secret_key'] ?? ''));
      $settings['stripe_webhook_secret'] = sanitize_text_field((string)($_POST['stripe_webhook_secret'] ?? ''));
      // Test keys
      $settings['stripe_test_publishable_key'] = sanitize_text_field((string)($_POST['stripe_test_publishable_key'] ?? ''));
      $settings['stripe_test_secret_key'] = sanitize_text_field((string)($_POST['stripe_test_secret_key'] ?? ''));
      $settings['stripe_test_webhook_secret'] = sanitize_text_field((string)($_POST['stripe_test_webhook_secret'] ?? ''));
      $settings['all_sheet_music_sku'] = $this->sanitize_sku((string)($_POST['all_sheet_music_sku'] ?? 'piece-all-sheet-music-access-complete-package'));

      $this->save_settings($settings);

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

        // Ensure master exists
        if (!isset($new_lists['all-sheet-music'])) $new_lists['all-sheet-music'] = array();

        $this->save_access_lists($new_lists);
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
      $approved_emails = isset($_POST['approved_emails']) ? (array)$_POST['approved_emails'] : array();
      $email_lists = $this->get_email_lists();

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
      // Save approved email lists per SKU (sheet_music only) and mirror into access table.
      $access_table = $this->table_sheet_music_access();
      global $wpdb;

      foreach ($skus as $index => $sku_raw) {
        $original_sku = $this->sanitize_sku((string)($original_skus[$index] ?? ''));
        $current_sku = $original_sku ? $original_sku : $this->sanitize_sku($sku_raw);
        if (!empty($deletes[$index])) {
          if ($current_sku) {
            unset($email_lists[$current_sku]);
          }
          continue;
        }

        // Resolve final SKU the same way the product save loop does.
        $type = sanitize_text_field((string)($types[$index] ?? 'sheet_music'));
        if ($type !== 'sheet_music') continue;

        $label_raw = sanitize_text_field((string)($labels[$index] ?? ''));
        $category = sanitize_text_field((string)($categories[$index] ?? 'fundamentals'));
        $final_sku = $this->generate_sheet_music_sku($label_raw, $category, $current_sku);
        $final_sku = $this->sanitize_sku($final_sku);
        if (!$final_sku) continue;

        if ($final_sku === $this->mrm_master_all_sheet_music_sku()) {
          continue;
        }

        $raw_lines = (string)($approved_emails[$index] ?? '');
        $emails = $this->normalize_email_lines($raw_lines);

        // Persist plaintext list
        $email_lists[$final_sku] = $emails;

        // Mirror to access table:
        // - grant for all in list
        // - revoke any previously granted by 'manual_list' not in the list
        $now = current_time('mysql');
        $wanted_hashes = array();
        foreach ($emails as $e) {
          $eh = $this->email_hash($e);
          $wanted_hashes[$eh] = true;

          // Insert if not exists active
          $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$access_table} WHERE email_hash=%s AND sku=%s AND revoked_at IS NULL LIMIT 1",
            $eh, $final_sku
          ));
          if (!$existing_id) {
            $wpdb->insert($access_table, array(
              'email_hash' => $eh,
              'sku' => $final_sku,
              'granted_at' => $now,
              'revoked_at' => null,
              'source' => 'manual_list',
              'source_id' => null,
            ), array('%s','%s','%s','%s','%s','%s'));
          }
        }

        // Revoke entries sourced from manual_list that are no longer wanted
        $rows = $wpdb->get_results($wpdb->prepare(
          "SELECT id,email_hash FROM {$access_table} WHERE sku=%s AND revoked_at IS NULL AND source='manual_list'",
          $final_sku
        ), ARRAY_A);

        foreach ($rows as $r) {
          $eh = (string)$r['email_hash'];
          if (!isset($wanted_hashes[$eh])) {
            $wpdb->update($access_table, array('revoked_at' => $now), array('id' => intval($r['id'])), array('%s'), array('%d'));
          }
        }
      }

      $this->save_email_lists($email_lists);
      add_settings_error('mrm_pay_hub', 'products_saved', 'Products saved.', 'updated');
    }
  }

  private function render_products_ui() {
    $products = $this->all_products();
    $email_lists = $this->get_email_lists();
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
            <?php if ($type === 'sheet_music') :
              $emails = isset($email_lists[$sku]) && is_array($email_lists[$sku]) ? $email_lists[$sku] : array();
              $text = esc_textarea(implode("\n", $emails));
            ?>
              <hr />
              <?php if ($sku === $all_sku) :
                global $wpdb;
                $access_table = $this->table_sheet_music_access();
                $now = current_time('mysql');
                $rows = $wpdb->get_results($wpdb->prepare(
                  "SELECT email_plain, month_key, start_at, expires_at
                   FROM {$access_table}
                   WHERE sku=%s AND revoked_at IS NULL
                     AND ((expires_at IS NOT NULL AND expires_at > %s) OR (expires_at IS NULL AND DATE_ADD(granted_at, INTERVAL 31 DAY) > %s))
                   ORDER BY expires_at ASC, id DESC
                   LIMIT 200",
                  $all_sku, $now, $now
                ), ARRAY_A);
              ?>
                <p><strong>Active Ledger Access (All Sheet Music)</strong><br />
                  <small>Managed by successful $5 add-on payments.</small>
                </p>
                <table class="widefat striped">
                  <thead><tr><th>Email</th><th>Month</th><th>Starts</th><th>Expires</th></tr></thead>
                  <tbody>
                  <?php if (!empty($rows)) : foreach ($rows as $r) : ?>
                    <tr>
                      <td><?php echo esc_html((string)($r['email_plain'] ?? '')); ?></td>
                      <td><?php echo esc_html((string)($r['month_key'] ?? '')); ?></td>
                      <td><?php echo esc_html((string)($r['start_at'] ?? '')); ?></td>
                      <td><?php echo esc_html((string)($r['expires_at'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; else : ?>
                    <tr><td colspan="4">No active ledger entries.</td></tr>
                  <?php endif; ?>
                  </tbody>
                </table>
              <?php else : ?>
                <p><strong>Approved Emails (OTP Access)</strong></p>
                <p>
                  <textarea name="approved_emails[<?php echo esc_attr($current_index); ?>]" rows="6" style="width:100%;font-family:monospace;"><?php echo $text; ?></textarea>
                  <small>One email per line.</small>
                </p>
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
    $pk_test   = esc_attr((string)($settings['stripe_test_publishable_key'] ?? ''));
    $sk_test   = esc_attr((string)($settings['stripe_test_secret_key'] ?? ''));
    $wh_test   = esc_attr((string)($settings['stripe_test_webhook_secret'] ?? ''));
    $mode      = esc_attr((string)($settings['stripe_mode'] ?? 'live'));
    $allSku    = esc_attr((string)($settings['all_sheet_music_sku'] ?? 'piece-all-sheet-music-access-complete-package'));

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
        <table class="form-table">
          <tr>
            <th scope="row"><label for="all_sheet_music_sku">All Sheet Music SKU (override)</label></th>
            <td>
              <input type="text" id="all_sheet_music_sku" name="all_sheet_music_sku" value="<?php echo $allSku; ?>" class="regular-text" />
              <p class="description">If an email has access to this SKU, OTP will be issued for any piece without checking the piece SKU list.</p>
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
        </table>

        <h2>Sheet Music Access Lists (Email-based)</h2>
        <p>Master list: <code>all-sheet-music</code> grants access to any piece.</p>

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

              // Build a stable list of rows: master + any existing keys
              $keys = array_keys($lists);
              sort($keys);

              // Ensure master is first
              $keys = array_values(array_unique(array_merge(array('all-sheet-music'), $keys)));

              global $wpdb;
              $access_table = $wpdb->prefix . 'mrm_sheet_music_access';

              foreach ($keys as $k) {
                $safe_k = esc_attr($k);
                $val = isset($lists[$k]) && is_array($lists[$k]) ? implode("\n", $lists[$k]) : '';
                $results = $wpdb->get_results($wpdb->prepare(
                  "SELECT email_plain, start_at, expires_at FROM {$access_table} WHERE sku = %s AND revoked_at IS NULL ORDER BY start_at DESC",
                  $k
                ));
                ?>
                <tr>
                  <td><input type="text" name="mrm_access_slug[]" value="<?php echo $safe_k; ?>" style="width:100%;" /></td>
                  <td>
                    <textarea name="mrm_access_emails[]" rows="4" style="width:100%; font-family: monospace;"><?php echo esc_textarea($val); ?></textarea>
                    <table class="widefat" style="margin-top:8px;">
                      <thead>
                        <tr><th>Email</th><th>Start date</th><th>Expires</th></tr>
                      </thead>
                      <tbody>
                        <?php if (!empty($results)) : ?>
                          <?php foreach ($results as $row) :
                            $start = $row->start_at ? date_i18n('Y-m-d', strtotime($row->start_at)) : '';
                            $expire = $row->expires_at ? date_i18n('Y-m-d', strtotime($row->expires_at)) : '';
                          ?>
                            <tr>
                              <td><?php echo esc_html((string)$row->email_plain); ?></td>
                              <td><?php echo esc_html($start); ?></td>
                              <td><?php echo esc_html($expire); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        <?php else : ?>
                          <tr><td colspan="3"><em>No active access rows.</em></td></tr>
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
              <td><textarea name="mrm_access_emails[]" rows="4" style="width:100%; font-family: monospace;" placeholder="email1@example.com&#10;email2@example.com"></textarea></td>
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
});

register_deactivation_hook(__FILE__, function() {
  wp_clear_scheduled_hook('mrm_pay_hub_cleanup_access');
});

/**
 * Optional helper for other plugins (PHP-level access)
 */
function mrm_pay_hub() {
  global $mrm_pay_hub_singleton;
  // not used here, but kept as a convention point
  return null;
}
