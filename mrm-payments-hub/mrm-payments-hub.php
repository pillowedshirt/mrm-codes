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

  // Admin menu
  const MENU_SLUG = 'mrm-payments-hub';

  public function __construct() {
    add_action('admin_menu', array($this, 'admin_menu'));
    add_action('admin_init', array($this, 'handle_admin_post'));
    add_action('rest_api_init', array($this, 'register_routes'));

    register_activation_hook(__FILE__, array($this, 'on_activate'));
  }

  /* =========================================================
   * Activation / DB
   * ======================================================= */

  public function on_activate() {
    $this->install_or_upgrade_db();
    $this->ensure_default_products();
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
      sku VARCHAR(120) NOT NULL,
      granted_at DATETIME NOT NULL,
      revoked_at DATETIME DEFAULT NULL,
      source VARCHAR(60) DEFAULT NULL,
      source_id VARCHAR(255) DEFAULT NULL,
      PRIMARY KEY (id),
      KEY email_hash_idx (email_hash),
      KEY sku_idx (sku),
      KEY active_idx (sku, revoked_at),
      KEY email_sku_idx (email_hash, sku)
    ) {$charset};";

    dbDelta($sql_orders);
    dbDelta($sql_links);
    dbDelta($sql_access);
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

  private function publishable_key() {
    $s = $this->get_settings();
    return trim((string)($s['stripe_publishable_key'] ?? ''));
  }

  private function secret_key() {
    $s = $this->get_settings();
    return trim((string)($s['stripe_secret_key'] ?? ''));
  }

  private function webhook_secret() {
    $s = $this->get_settings();
    return trim((string)($s['stripe_webhook_secret'] ?? ''));
  }

  private function all_products() {
    $p = get_option(self::OPT_PRODUCTS, array());
    return is_array($p) ? $p : array();
  }

  private function save_products($products) {
    update_option(self::OPT_PRODUCTS, $products);
  }

  private function get_product($sku) {
    $sku = $this->sanitize_sku($sku);
    $all = $this->all_products();
    return isset($all[$sku]) && is_array($all[$sku]) ? $all[$sku] : null;
  }

  private function ensure_default_products() {
    $all = $this->all_products();
    $changed = false;

    $defaults = array(
      'lesson_60_online' => array(
        'label' => '60 Minute Online Lesson',
        'amount_cents' => 6700,
        'currency' => 'usd',
        'product_type' => 'lesson',
        'active' => 1,
      ),
      'lesson_60_inperson' => array(
        'label' => '60 Minute In-Person Lesson',
        'amount_cents' => 7200,
        'currency' => 'usd',
        'product_type' => 'lesson',
        'active' => 1,
      ),
      'lesson_30_online' => array(
        'label' => '30 Minute Online Lesson',
        'amount_cents' => 3800,
        'currency' => 'usd',
        'product_type' => 'lesson',
        'active' => 1,
      ),
      'lesson_30_inperson' => array(
        'label' => '30 Minute In-Person Lesson',
        'amount_cents' => 4300,
        'currency' => 'usd',
        'product_type' => 'lesson',
        'active' => 1,
      ),
    );

    foreach ($defaults as $sku => $cfg) {
      if (!isset($all[$sku])) {
        $all[$sku] = $cfg;
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
    return hash('sha256', $email);
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
   * Sheet music SKU generator:
   * piece-<title>-<type>
   * type: fundamentals | trombone-euphonium | tuba | complete-package
   * uniqueness: appends -2, -3, ...
   */
  private function generate_sheet_music_sku($title, $type) {
    $type = strtolower(trim((string)$type));
    $allowed = array('fundamentals','trombone-euphonium','tuba','complete-package');
    if (!in_array($type, $allowed, true)) $type = 'fundamentals';

    $base_title = $this->slugify($title);
    $base = 'piece-' . $base_title . '-' . $type;

    $sku = $base;
    $all = $this->all_products();
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

  private function stripe_create_payment_intent($amount_cents, $currency, $metadata, $description = '') {
    $params = array(
      'amount' => (int)$amount_cents,
      'currency' => $currency ?: 'usd',
      'automatic_payment_methods[enabled]' => 'true',
    );
    if ($description) $params['description'] = $description;

    foreach ((array)$metadata as $k => $v) {
      $k = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$k);
      $params["metadata[{$k}]"] = (string)$v;
    }

    return $this->stripe_api_request('POST', '/v1/payment_intents', $params);
  }

  private function stripe_retrieve_payment_intent($pi_id) {
    return $this->stripe_api_request('GET', '/v1/payment_intents/' . rawurlencode((string)$pi_id));
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
    register_rest_route('mrm-pay/v1', '/pk', array(
      'methods' => WP_REST_Server::READABLE,
      'callback' => array($this, 'rest_pk'),
      'permission_callback' => '__return_true',
    ));

    register_rest_route('mrm-pay/v1', '/quote', array(
      'methods' => WP_REST_Server::READABLE,
      'callback' => array($this, 'rest_quote'),
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

    register_rest_route('mrm-pay/v1', '/link-order', array(
      'methods' => WP_REST_Server::CREATABLE,
      'callback' => array($this, 'rest_link_order'),
      'permission_callback' => '__return_true',
    ));
  }

  public function rest_pk() {
    return new WP_REST_Response(array(
      'publishableKey' => $this->publishable_key(),
    ), 200);
  }

  public function rest_quote(WP_REST_Request $req) {
    $sku = $this->sanitize_sku($req->get_param('sku'));
    if (!$sku) return new WP_REST_Response(array('ok'=>false,'message'=>'Missing sku.'), 400);

    $p = $this->get_product($sku);
    if (!$p || empty($p['active'])) return new WP_REST_Response(array('ok'=>false,'message'=>'Unknown or inactive sku.'), 404);

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
    if (!$p || empty($p['active'])) return new WP_REST_Response(array('ok'=>false,'message'=>'Unknown or inactive sku.'), 404);

    $amount = (int)($p['amount_cents'] ?? 0);
    $currency = (string)($p['currency'] ?? 'usd');
    $product_type = (string)($p['product_type'] ?? 'unknown');

    if ($amount <= 0) return new WP_REST_Response(array('ok'=>false,'message'=>'Invalid product price.'), 400);

    $email_hash = $this->email_hash($email);

    // Build labels
    $metadata = $this->build_metadata($sku, $product_type, $email_hash, $context, $p);

    // Create internal order first so order_id can be labeled in Stripe metadata
    $order_id = $this->create_order($email_hash, $sku, $product_type, $amount, $currency, $metadata);
    $metadata['mrm_order_id'] = (string)$order_id;

    $description = ($product_type === 'lesson') ? 'MRM Lesson' : 'MRM Sheet Music';

    $pi = $this->stripe_create_payment_intent($amount, $currency, $metadata, $description);
    if (is_wp_error($pi)) {
      return new WP_REST_Response(array('ok'=>false,'message'=>$pi->get_error_message()), 500);
    }

    $this->attach_payment_intent_to_order($order_id, (string)$pi['id'], (string)($pi['status'] ?? ''));

    return new WP_REST_Response(array(
      'ok' => true,
      'publishableKey' => $this->publishable_key(),
      'client_secret' => (string)($pi['client_secret'] ?? ''),
      'payment_intent_id' => (string)$pi['id'],
      'order_id' => (int)$order_id,
      'sku' => $sku,
      'label' => (string)($p['label'] ?? $sku),
      'amount_cents' => $amount,
      'currency' => $currency,
      'product_type' => $product_type,
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
    $ok = ($status === 'succeeded');

    // Update local order status if it exists
    $order = $this->get_order_by_pi($pi_id);
    if ($order) {
      $new_status = $ok ? 'paid' : (($status === 'requires_payment_method' || $status === 'canceled') ? 'failed' : 'created');
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

    $sku   = $this->sanitize_sku($data['sku'] ?? '');
    $email = sanitize_email((string)($data['email'] ?? ''));
    $pi_id = sanitize_text_field((string)($data['payment_intent_id'] ?? ''));

    if (!$sku) return new WP_REST_Response(array('ok'=>false,'message'=>'Missing sku.'), 400);
    if (!$email || !is_email($email)) return new WP_REST_Response(array('ok'=>false,'message'=>'Valid email required.'), 400);

    $p = $this->get_product($sku);
    if (!$p || empty($p['active'])) return new WP_REST_Response(array('ok'=>false,'message'=>'Unknown or inactive sku.'), 404);

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

    $email_hash = $this->email_hash($email);

    $ok = $this->grant_sheet_music_access($email_hash, $sku, $pi_id ? 'stripe_pi' : 'manual', $pi_id);
    if (!$ok) {
      return new WP_REST_Response(array('ok'=>false,'message'=>'Failed to grant access.'), 500);
    }

    return new WP_REST_Response(array(
      'ok' => true,
      'email_hash' => $email_hash,
      'sku' => $sku,
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

  public function has_sheet_music_access($email_hash, $sku) {
    global $wpdb;
    $table = $this->table_sheet_music_access();
    $id = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$table} WHERE email_hash = %s AND sku = %s AND revoked_at IS NULL LIMIT 1",
      $email_hash, $sku
    ));
    return !empty($id);
  }

  public function rest_link_order(WP_REST_Request $req) {
    $data = (array) $req->get_json_params();
    $pi_id = sanitize_text_field((string)($data['payment_intent_id'] ?? ''));
    $source = sanitize_text_field((string)($data['source'] ?? ''));
    $source_object_id = sanitize_text_field((string)($data['source_object_id'] ?? ''));

    if (!$pi_id || !$source || !$source_object_id) {
      return new WP_REST_Response(array('ok'=>false,'message'=>'payment_intent_id, source, source_object_id required.'), 400);
    }

    $order = $this->get_order_by_pi($pi_id);
    if (!$order) return new WP_REST_Response(array('ok'=>false,'message'=>'Order not found for payment_intent_id.'), 404);

    $this->link_order((int)$order['id'], $source, $source_object_id);

    return new WP_REST_Response(array('ok'=>true,'order_id'=>(int)$order['id']), 200);
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

    if (!isset($_POST['mrm_pay_hub_nonce']) || !wp_verify_nonce($_POST['mrm_pay_hub_nonce'], 'mrm_pay_hub_save')) return;

    $settings = $this->get_settings();
    $settings['stripe_publishable_key'] = sanitize_text_field((string)($_POST['stripe_publishable_key'] ?? ''));
    $settings['stripe_secret_key'] = sanitize_text_field((string)($_POST['stripe_secret_key'] ?? ''));
    $settings['stripe_webhook_secret'] = sanitize_text_field((string)($_POST['stripe_webhook_secret'] ?? ''));
    $this->save_settings($settings);

    if (isset($_POST['products_json'])) {
      $raw = wp_unslash((string)$_POST['products_json']);
      $json = json_decode($raw, true);
      if (is_array($json)) {
        $clean = array();
        foreach ($json as $sku => $cfg) {
          $sku_clean = $this->sanitize_sku($sku);
          if (!$sku_clean || !is_array($cfg)) continue;

          $clean[$sku_clean] = array(
            'label' => sanitize_text_field((string)($cfg['label'] ?? $sku_clean)),
            'amount_cents' => (int)($cfg['amount_cents'] ?? 0),
            'currency' => sanitize_text_field((string)($cfg['currency'] ?? 'usd')),
            'product_type' => sanitize_text_field((string)($cfg['product_type'] ?? 'unknown')),
            'active' => !empty($cfg['active']) ? 1 : 0,
          );

          // Optional: composer_pct for sheet music labeling
          if (isset($cfg['composer_pct'])) $clean[$sku_clean]['composer_pct'] = (int)$cfg['composer_pct'];
        }

        $this->save_products($clean);
        $this->ensure_default_products(); // keep lesson defaults
      }
    }

    add_settings_error('mrm_pay_hub', 'saved', 'Settings saved.', 'updated');
  }

  public function render_admin_page() {
    if (!current_user_can('manage_options')) return;

    settings_errors('mrm_pay_hub');

    $settings = $this->get_settings();
    $pk = esc_attr((string)($settings['stripe_publishable_key'] ?? ''));
    $sk = esc_attr((string)($settings['stripe_secret_key'] ?? ''));
    $wh = esc_attr((string)($settings['stripe_webhook_secret'] ?? ''));

    $products = $this->all_products();
    $products_json = esc_textarea(wp_json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    ?>
    <div class="wrap">
      <h1>MRM Payments Hub</h1>

      <form method="post">
        <?php wp_nonce_field('mrm_pay_hub_save', 'mrm_pay_hub_nonce'); ?>

        <h2>Stripe Settings</h2>
        <table class="form-table">
          <tr>
            <th scope="row"><label for="stripe_publishable_key">Publishable Key</label></th>
            <td><input type="text" id="stripe_publishable_key" name="stripe_publishable_key" value="<?php echo $pk; ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="stripe_secret_key">Secret Key</label></th>
            <td><input type="password" id="stripe_secret_key" name="stripe_secret_key" value="<?php echo $sk; ?>" class="regular-text" autocomplete="off" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="stripe_webhook_secret">Webhook Signing Secret (optional for later)</label></th>
            <td><input type="password" id="stripe_webhook_secret" name="stripe_webhook_secret" value="<?php echo $wh; ?>" class="regular-text" autocomplete="off" /></td>
          </tr>
        </table>

        <h2>Products Catalog (JSON)</h2>
        <p>
          This is the single source of truth for pricing. Lesson defaults are ensured on activation.
          For sheet music SKUs, you can use the generator below and then paste the generated SKU here.
        </p>

        <p><strong>Sheet music labeling:</strong> If you set <code>composer_pct</code> on a sheet music SKU, payments will be labeled with
          <code>mrm_composer_pct</code> and <code>mrm_platform_pct</code>.
        </p>

        <textarea name="products_json" rows="20" style="width:100%;font-family:monospace;"><?php echo $products_json; ?></textarea>

        <p class="submit">
          <button type="submit" class="button button-primary">Save Settings</button>
        </p>
      </form>

      <hr />

      <h2>Sheet Music SKU Generator</h2>
      <p>Pattern: <code>piece-&lt;title&gt;-&lt;type&gt;</code> with uniqueness auto-suffix.</p>

      <form method="post" style="max-width:800px;">
        <?php wp_nonce_field('mrm_pay_hub_save', 'mrm_pay_hub_nonce'); ?>
        <table class="form-table">
          <tr>
            <th scope="row"><label for="gen_title">Piece Title</label></th>
            <td><input type="text" id="gen_title" name="gen_title" value="<?php echo esc_attr((string)($_POST['gen_title'] ?? '')); ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="gen_type">Type</label></th>
            <td>
              <select id="gen_type" name="gen_type">
                <?php
                  $t = (string)($_POST['gen_type'] ?? 'fundamentals');
                  $opts = array('fundamentals','trombone-euphonium','tuba','complete-package');
                  foreach ($opts as $o) {
                    $sel = ($t === $o) ? 'selected' : '';
                    echo '<option value="'.esc_attr($o).'" '.$sel.'>'.esc_html($o).'</option>';
                  }
                ?>
              </select>
            </td>
          </tr>
        </table>
        <p class="submit">
          <button type="submit" class="button">Generate SKU</button>
        </p>
      </form>

      <?php
        if (!empty($_POST['gen_title']) && isset($_POST['gen_type']) && isset($_POST['mrm_pay_hub_nonce']) && wp_verify_nonce($_POST['mrm_pay_hub_nonce'], 'mrm_pay_hub_save')) {
          $gen_sku = $this->generate_sheet_music_sku((string)$_POST['gen_title'], (string)$_POST['gen_type']);
          echo '<p><strong>Generated SKU:</strong> <code>'.esc_html($gen_sku).'</code></p>';
        }
      ?>

      <hr />
      <h2>REST Endpoints</h2>
      <ul>
        <li><code>GET  /wp-json/mrm-pay/v1/pk</code></li>
        <li><code>GET  /wp-json/mrm-pay/v1/quote?sku=lesson_60_online</code></li>
        <li><code>POST /wp-json/mrm-pay/v1/create-payment-intent</code></li>
        <li><code>POST /wp-json/mrm-pay/v1/verify-payment-intent</code></li>
        <li><code>POST /wp-json/mrm-pay/v1/link-order</code></li>
      </ul>
    </div>
    <?php
  }
}

new MRM_Payments_Hub_Single();

/**
 * Optional helper for other plugins (PHP-level access)
 */
function mrm_pay_hub() {
  global $mrm_pay_hub_singleton;
  // not used here, but kept as a convention point
  return null;
}