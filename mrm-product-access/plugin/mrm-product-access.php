<?php
/*
Plugin Name: MRM Product Access
Description: Provides purchase and access management for single-product pages using Stripe Checkout and Stripe Connect. Handles checkout session creation, webhook processing, OTP issuance and secure downloads without requiring user accounts.
Author: Your Name
Version: 1.2.3
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main plugin class.
 *
 * The MRM_Product_Access class encapsulates all plugin behaviour including admin UI,
 * REST API endpoints, checkout/webhook integration and access page rendering.
 *
 * This class was refactored from the original 1.0.3 release to improve reliability,
 * add additional quality checks and polish the admin experience. Notable changes:
 *   • Added a new “verified emails” list to quickly grant products or issue refunds.
 *   • Added quality check notices to help administrators configure the plugin properly.
 *   • Improved OTP handling: per‑product rate limiting, wp_mail() success check and
 *     clearer error handling.
 *   • Modernised the admin settings page layout for a more professional look.
 */
class MRM_Product_Access {

    /**
     * Singleton instance.
     *
     * @var self
     */
    protected static $instance;

    /**
     * Option key used to persist settings.
     *
     * @var string
     */
    protected $option_key = 'mrm_pa_settings';

    /**
     * Cached options array.
     *
     * @var array
     */
    protected $options;

    /**
     * Plugin version.
     *
     * Bump this when changes to rewrite rules or activation logic require a flush.
     *
     * @var string
     */
    const VERSION = '1.2.3';

    /**
     * Get singleton instance.
     */
    public static function get_instance() {
        if ( empty( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     *
     * Hooks into WordPress lifecycle and initialises plugin behaviour.
     */
    public function __construct() {
        $this->options = get_option( $this->option_key, array() );

        register_activation_hook( __FILE__, array( $this, 'activate' ) );

        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_notices', array( $this, 'admin_quality_checks' ) );
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

        // Pretty access URL support (best effort).
        add_action( 'init', array( $this, 'register_access_rewrite' ) );
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

        // Minimal robustness: run access-page renderer on BOTH hooks.
        add_action( 'wp', array( $this, 'maybe_render_access_page' ), 0 );
        add_action( 'template_redirect', array( $this, 'maybe_render_access_page' ), 0 );

        add_shortcode( 'mrm_sheet_music_catalog', array( $this, 'shortcode_sheet_music_catalog' ) );
        add_shortcode( 'mrm_piece_details', array( $this, 'shortcode_piece_details' ) );

        // Hostinger-proof: auto-flush rewrite rules once when plugin version changes.
        add_action( 'init', array( $this, 'maybe_flush_rewrites_on_update' ), 20 );
    }

    /**
     * Load plugin textdomain for translations.
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'mrm-product-access', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    /**
     * Plugin activation callback.
     *
     * Creates custom database tables and initialises default options.
     */
    public function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $table_purchases = $wpdb->prefix . 'mrm_purchases';
        $sql1 = "CREATE TABLE $table_purchases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_slug VARCHAR(200) NOT NULL,
            purchaser_email VARCHAR(255) NOT NULL,
            email_hash VARCHAR(64) NOT NULL,
            payout_json LONGTEXT NULL,
            stripe_checkout_session_id VARCHAR(255) NOT NULL,
            stripe_payment_intent_id VARCHAR(255) NOT NULL,
            amount_total BIGINT UNSIGNED NOT NULL,
            currency VARCHAR(10) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY email_hash_idx (email_hash),
            KEY product_slug_idx (product_slug)
        ) $charset_collate;";

        $table_otps = $wpdb->prefix . 'mrm_otp_tokens';
        $sql2 = "CREATE TABLE $table_otps (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_slug VARCHAR(200) NOT NULL,
            purchaser_email VARCHAR(255) NOT NULL,
            email_hash VARCHAR(64) NOT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            request_ip VARCHAR(45) NOT NULL,
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY email_hash_idx (email_hash),
            KEY product_slug_idx (product_slug)
        ) $charset_collate;";

        dbDelta( $sql1 );
        dbDelta( $sql2 );

        if ( ! get_option( $this->option_key ) ) {
            $default = array(
                'test_mode'                  => true,
                'stripe_test_secret_key'     => '',
                'stripe_live_secret_key'     => '',
                'stripe_test_webhook_secret' => '',
                'stripe_live_webhook_secret' => '',
                'auth_secret'                => wp_generate_password( 32, true, true ),
                'email_subject'              => 'Your access code',
                'email_body'                 => "Your one-time code is: {{OTP}}\n\nIf you did not request this, ignore this email.",
                'sheet_music_catalog_url'    => '/sheet-music/',
                'pieces'                     => array(),
                'offerings'                  => array(
                    array( 'key' => 'full', 'label' => 'Full Package', 'amount_cents' => 0 ),
                    array( 'key' => 'pdf', 'label' => 'PDF Only', 'amount_cents' => 0 ),
                    array( 'key' => 'audio', 'label' => 'Audio Only', 'amount_cents' => 0 ),
                    array( 'key' => 'bundle', 'label' => 'Bundle', 'amount_cents' => 0 ),
                ),
            );
            add_option( $this->option_key, $default );
        }

        $this->register_access_rewrite();
        flush_rewrite_rules();

        // Mark rewrite flushed for this version.
        update_option( 'mrm_pa_rewrite_flushed_ver', self::VERSION );
    }

    /**
     * Retrieve stored options, reloading if necessary.
     *
     * @return array
     */
    protected function get_options() {
        if ( empty( $this->options ) ) {
            $this->options = get_option( $this->option_key, array() );
        }
        return $this->options;
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes() {
        register_rest_route( 'mrm/v1', '/piece', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'api_get_piece' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'slug' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_title',
                ),
            ),
        ) );

        register_rest_route( 'mrm/v1', '/request-otp', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'api_request_otp' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'mrm/v1', '/verify-otp', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'api_verify_otp' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'mrm/v1', '/download', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'api_download' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'mrm/v1', '/authorize', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'api_authorize' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Add menu entry under Settings for plugin configuration.
     */
    public function admin_menu() {
        add_menu_page(
            __( 'MRM Product Access', 'mrm-product-access' ),
            __( 'MRM Access', 'mrm-product-access' ),
            'manage_options',
            'mrm-pa-settings',
            array( $this, 'render_settings_page' ),
            'dashicons-lock'
        );
    }

    /**
     * Display admin notices highlighting misconfiguration.
     *
     * This method runs on the 'admin_notices' hook. It surfaces quality issues
     * identified by analysing current options. Administrators are alerted
     * to things like missing Stripe keys, payout totals exceeding 100% or
     * omitted OTP placeholders.
     */
    public function admin_quality_checks() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( empty( $screen ) || $screen->base !== 'toplevel_page_mrm-pa-settings' ) {
            return;
        }
        $opts    = $this->get_options();
        $notices = array();

        // Ensure OTP template contains placeholder.
        $body = isset( $opts['email_body'] ) ? $opts['email_body'] : '';
        if ( stripos( $body, '{{OTP}}' ) === false ) {
            $notices[] = __( 'The Email Body does not contain the {{OTP}} placeholder, so recipients will not see the code.', 'mrm-product-access' );
        }

        // Check payouts per product.
        if ( ! empty( $opts['products'] ) && is_array( $opts['products'] ) ) {
            foreach ( $opts['products'] as $slug => $conf ) {
                $total = 0;
                if ( ! empty( $conf['payouts'] ) && is_array( $conf['payouts'] ) ) {
                    foreach ( $conf['payouts'] as $p ) {
                        $total += floatval( $p['pct'] ?? 0 );
                    }
                }
                if ( $total > 100 ) {
                    $notices[] = sprintf( __( 'Payout percentages for product "%s" exceed 100%%.', 'mrm-product-access' ), esc_html( $slug ) );
                }
            }
        }

        foreach ( $notices as $msg ) {
            echo '<div class="notice notice-warning"><p>' . wp_kses_post( $msg ) . '</p></div>';
        }
    }

    /**
     * Render the settings page.
     *
     * Provides configuration UI for Stripe, email template, verified emails and
     * products. Fields are grouped for clarity and styled using native admin
     * classes for a polished experience.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $options = $this->get_options();

        if ( isset( $_POST['mrm_pa_save_settings'] ) && check_admin_referer( 'mrm_pa_save_settings' ) ) {
            // Stripe configuration.
            $options['test_mode']                  = isset( $_POST['test_mode'] );
            $options['stripe_test_secret_key']     = sanitize_text_field( $_POST['stripe_test_secret_key'] );
            $options['stripe_test_webhook_secret'] = sanitize_text_field( $_POST['stripe_test_webhook_secret'] );
            $options['stripe_live_secret_key']     = sanitize_text_field( $_POST['stripe_live_secret_key'] );
            $options['stripe_live_webhook_secret'] = sanitize_text_field( $_POST['stripe_live_webhook_secret'] );
            $options['email_subject']              = sanitize_text_field( $_POST['email_subject'] );
            $options['email_body']                 = wp_kses_post( $_POST['email_body'] );
            $options['sheet_music_catalog_url']    = isset( $_POST['sheet_music_catalog_url'] )
                ? sanitize_text_field( $_POST['sheet_music_catalog_url'] )
                : ( $options['sheet_music_catalog_url'] ?? '/sheet-music/' );

            /**
             * Pieces Catalog (sheet music listings)
             *
             * Stored at: $options['pieces'] = [
             *   [
             *     'slug' => 'blackbeards-revenge',
             *     'piece_title' => 'Blackbeard\'s Revenge',
             *     'composer_name' => 'Isaac Davanzo',
             *     'composer_url' => 'https://example.com/composer',
             *     'description' => '...',
             *     'difficulty' => 'Intermediate Grade 3',
             *     'instrumentation' => 'Trombone/Euphonium/Tuba',
             *     'duration' => '3:08',
             *     'year' => '2023',
             *     'main_preview_pdf_url' => 'https://.../sample.pdf',
             *     'preview_page_number' => 1,
             *     'offers' => [
             *        [
             *          'product_slug' => 'blackbeards-revenge-core-developmental-exercises',
             *          'display_title' => 'Core Developmental Exercises',
             *          'subtitle' => 'Includes...',
             *          'price_display' => '$10',
             *          'preview_audio_url' => 'https://.../demo.mp3'
             *        ]
             *     ],
             *   ],
             * ]
             */
            $pieces = array();

            if ( isset( $_POST['piece_slug'] ) && is_array( $_POST['piece_slug'] ) ) {

                $slugs               = (array) $_POST['piece_slug'];
                $titles              = isset( $_POST['piece_title'] ) ? (array) $_POST['piece_title'] : array();
                $composer_names      = isset( $_POST['piece_composer_name'] ) ? (array) $_POST['piece_composer_name'] : array();
                $composer_urls       = isset( $_POST['piece_composer_url'] ) ? (array) $_POST['piece_composer_url'] : array();
                $descs               = isset( $_POST['piece_description'] ) ? (array) $_POST['piece_description'] : array();
                $short_descs         = isset( $_POST['piece_short_description'] ) ? (array) $_POST['piece_short_description'] : array();
                $long_descs          = isset( $_POST['piece_long_description'] ) ? (array) $_POST['piece_long_description'] : array();
                $difficulty          = isset( $_POST['piece_difficulty'] ) ? (array) $_POST['piece_difficulty'] : array();
                $instrumentation     = isset( $_POST['piece_instrumentation'] ) ? (array) $_POST['piece_instrumentation'] : array();
                $duration            = isset( $_POST['piece_duration'] ) ? (array) $_POST['piece_duration'] : array();
                $year                = isset( $_POST['piece_year'] ) ? (array) $_POST['piece_year'] : array();
                $pdf_urls            = isset( $_POST['piece_main_preview_pdf_url'] ) ? (array) $_POST['piece_main_preview_pdf_url'] : array();
                $preview_pages       = isset( $_POST['piece_preview_page_number'] ) ? (array) $_POST['piece_preview_page_number'] : array();
                $preview_audio_urls  = isset( $_POST['piece_preview_audio_url'] ) ? (array) $_POST['piece_preview_audio_url'] : array();

                // Offers: offer_piece_index[], offer_product_slug[], offer_display_title[], offer_subtitle[], offer_price_display[], offer_preview_audio_url[]
                $offer_piece_index       = isset( $_POST['offer_piece_index'] ) ? (array) $_POST['offer_piece_index'] : array();
                $offer_product_slug      = isset( $_POST['offer_product_slug'] ) ? (array) $_POST['offer_product_slug'] : array();
                $offer_display_title     = isset( $_POST['offer_display_title'] ) ? (array) $_POST['offer_display_title'] : array();
                $offer_subtitle          = isset( $_POST['offer_subtitle'] ) ? (array) $_POST['offer_subtitle'] : array();
                $offer_price_display     = isset( $_POST['offer_price_display'] ) ? (array) $_POST['offer_price_display'] : array();
                $offer_preview_audio_url = isset( $_POST['offer_preview_audio_url'] ) ? (array) $_POST['offer_preview_audio_url'] : array();

                // First, bucket offers by piece index.
                $offers_by_piece = array();
                $offer_count     = max(
                    count( $offer_piece_index ),
                    count( $offer_product_slug ),
                    count( $offer_display_title ),
                    count( $offer_subtitle ),
                    count( $offer_price_display ),
                    count( $offer_preview_audio_url )
                );

                for ( $oi = 0; $oi < $offer_count; $oi++ ) {
                    $pi_raw = $offer_piece_index[ $oi ] ?? '';
                    if ( $pi_raw === '' ) {
                        continue;
                    }
                    $pi = intval( $pi_raw );

                    $pslug = sanitize_title( (string) ( $offer_product_slug[ $oi ] ?? '' ) );
                    $dt    = sanitize_text_field( (string) ( $offer_display_title[ $oi ] ?? '' ) );
                    $sub   = sanitize_text_field( (string) ( $offer_subtitle[ $oi ] ?? '' ) );
                    $price = sanitize_text_field( (string) ( $offer_price_display[ $oi ] ?? '' ) );
                    $aud   = trim( (string) ( $offer_preview_audio_url[ $oi ] ?? '' ) );

                    // Require at least a product slug or display title to keep an offer row.
                    if ( $pslug === '' && $dt === '' ) {
                        continue;
                    }

                    // If product slug missing but display title exists, derive a stable slug-ish value.
                    if ( $pslug === '' && $dt !== '' ) {
                        $pslug = sanitize_title( $dt );
                    }

                    if ( ! isset( $offers_by_piece[ $pi ] ) ) {
                        $offers_by_piece[ $pi ] = array();
                    }

                    $offers_by_piece[ $pi ][] = array(
                        'product_slug'      => $pslug,
                        'display_title'     => $dt,
                        'subtitle'          => $sub,
                        'price_display'     => $price,
                        'preview_audio_url' => $aud,
                    );
                }

                foreach ( $slugs as $i => $raw_slug ) {

                    $slug  = sanitize_title( (string) $raw_slug );
                    $title = sanitize_text_field( (string) ( $titles[ $i ] ?? '' ) );

                    // Require at least a title or slug to keep the row.
                    if ( $slug === '' && $title === '' ) {
                        continue;
                    }

                    // If slug missing but title exists, derive slug from title.
                    if ( $slug === '' && $title !== '' ) {
                        $slug = sanitize_title( $title );
                    }

                    $piece                         = array();
                    $piece['slug']                 = $slug;
                    $piece['piece_title']          = $title;
                    $piece['composer_name']        = sanitize_text_field( (string) ( $composer_names[ $i ] ?? '' ) );
                    $piece['composer_url']         = esc_url_raw( trim( (string) ( $composer_urls[ $i ] ?? '' ) ) );
                    $legacy_desc = sanitize_textarea_field( (string) ( $descs[ $i ] ?? '' ) );

                    $piece['short_description'] = sanitize_textarea_field( (string) ( $short_descs[ $i ] ?? '' ) );

                    $new_long = sanitize_textarea_field( (string) ( $long_descs[ $i ] ?? '' ) );
                    $piece['long_description']  = ( $new_long !== '' ) ? $new_long : $legacy_desc;

                    $piece['description'] = $piece['long_description'];
                    $piece['difficulty']           = sanitize_text_field( (string) ( $difficulty[ $i ] ?? '' ) );
                    $piece['instrumentation']      = sanitize_text_field( (string) ( $instrumentation[ $i ] ?? '' ) );
                    $piece['duration']             = sanitize_text_field( (string) ( $duration[ $i ] ?? '' ) );
                    $piece['year']                 = sanitize_text_field( (string) ( $year[ $i ] ?? '' ) );
                    $piece['main_preview_pdf_url'] = trim( (string) ( $pdf_urls[ $i ] ?? '' ) );

                    $preview_audio = trim( (string) ( $preview_audio_urls[ $i ] ?? '' ) );

                    $ppn = intval( $preview_pages[ $i ] ?? 1 );
                    if ( $ppn <= 0 ) {
                        $ppn = 1;
                    }
                    $piece['preview_page_number'] = $ppn;
                    $piece['preview_audio_url']  = $preview_audio;

                    $piece['offers'] = isset( $offers_by_piece[ $i ] ) && is_array( $offers_by_piece[ $i ] )
                        ? array_values( $offers_by_piece[ $i ] )
                        : array();

                    $pieces[] = $piece;
                }
            }

            $options['pieces'] = $pieces;

            // Verified emails.
            // Only update the verified_emails option when inputs are provided. If no
            // verified_email inputs exist (e.g. the UI has been removed), retain
            // existing values to avoid clearing the list unintentionally.
            if ( isset( $_POST['verified_email'] ) && is_array( $_POST['verified_email'] ) ) {
                $verified = array();
                foreach ( $_POST['verified_email'] as $email ) {
                    $email = sanitize_email( $email );
                    if ( ! empty( $email ) ) {
                        $verified[] = $email;
                    }
                }
                $options['verified_emails'] = array_values( array_unique( $verified ) );
            }

            // Products configuration.
            $products = array();
            if ( ! empty( $_POST['product_slug'] ) && is_array( $_POST['product_slug'] ) ) {
                $slugs       = array_map( 'sanitize_title', $_POST['product_slug'] );
                $prices      = array_map( 'intval', $_POST['product_price_cents'] );
                $currencies  = array_map( 'sanitize_text_field', $_POST['product_currency'] );
                $payouts     = isset( $_POST['product_payout'] ) ? $_POST['product_payout'] : array();
                // The PDF, audio and zip paths fields have been removed from the UI. Retain any existing
                // values by not parsing these from the submitted form.
                $tracks_json = isset( $_POST['product_tracks_json'] ) ? $_POST['product_tracks_json'] : array();
                $approved_emails_raw = isset( $_POST['product_approved_emails'] ) ? $_POST['product_approved_emails'] : null;

                foreach ( $slugs as $idx => $slug ) {
                    if ( empty( $slug ) ) {
                        continue;
                    }

                    $product = array();
                    $product['price_cents'] = isset( $prices[ $idx ] ) ? $prices[ $idx ] : 0;
                    $product['currency']    = isset( $currencies[ $idx ] ) ? strtolower( $currencies[ $idx ] ) : 'usd';

                    // Parse payout definitions: dest:pct;dest:pct.
                    $product['payouts'] = array();
                    if ( isset( $payouts[ $idx ] ) && ! empty( $payouts[ $idx ] ) ) {
                        $pairs = explode( ';', $payouts[ $idx ] );
                        foreach ( $pairs as $pair ) {
                            $parts = explode( ':', $pair );
                            if ( count( $parts ) === 2 ) {
                                $dest = trim( $parts[0] );
                                $pct  = floatval( $parts[1] );
                                if ( $dest !== '' && $pct > 0 ) {
                                    $product['payouts'][] = array(
                                        'stripe_account_id' => $dest,
                                        'pct'               => $pct,
                                    );
                                }
                            }
                        }
                    }

                    // Preserve any existing file paths from the options since these fields are no longer editable
                    $product['pdf_path']   = isset( $options['products'][ $slug ]['pdf_path'] ) ? sanitize_text_field( $options['products'][ $slug ]['pdf_path'] ) : '';
                    $product['audio_path'] = isset( $options['products'][ $slug ]['audio_path'] ) ? sanitize_text_field( $options['products'][ $slug ]['audio_path'] ) : '';
                    $product['zip_path']   = isset( $options['products'][ $slug ]['zip_path'] ) ? sanitize_text_field( $options['products'][ $slug ]['zip_path'] ) : '';

                    // Tracks JSON.
                    $product['tracks'] = array();
                    if ( isset( $tracks_json[ $idx ] ) && trim( $tracks_json[ $idx ] ) !== '' ) {
                        $decoded = json_decode( wp_unslash( $tracks_json[ $idx ] ), true );
                        if ( is_array( $decoded ) ) {
                            // Determine if sequential array (new format) or associative map (legacy).
                            if ( array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
                                foreach ( $decoded as $item ) {
                                    if ( is_array( $item ) && isset( $item['type'] ) && isset( $item['path'] ) ) {
                                        $type  = sanitize_key( $item['type'] );
                                        $title = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
                                        $path  = sanitize_text_field( $item['path'] );
                                        $product['tracks'][] = array(
                                            'type'  => $type,
                                            'title' => $title,
                                            'path'  => $path,
                                        );
                                    }
                                }
                            } else {
                                foreach ( $decoded as $k => $v ) {
                                    $key  = sanitize_key( $k );
                                    $path = sanitize_text_field( $v );
                                    if ( $key && $path ) {
                                        $product['tracks'][ $key ] = $path;
                                    }
                                }
                            }
                        }
                    }

                    
                    // Approved emails (per product): semicolon-separated list.
                    // If the new field isn't present (older versions), preserve existing values.
                    if ( is_array( $approved_emails_raw ) && array_key_exists( $idx, $approved_emails_raw ) ) {
                        $approved_list = array();
                        $raw = (string) $approved_emails_raw[ $idx ];
                        $parts = explode( ';', $raw );
                        foreach ( $parts as $em ) {
                            $em = sanitize_email( trim( $em ) );
                            if ( ! empty( $em ) ) {
                                $approved_list[] = strtolower( $em );
                            }
                        }
                        $product['approved_emails'] = array_values( array_unique( $approved_list ) );
                    } else {
                        $product['approved_emails'] = isset( $options['products'][ $slug ]['approved_emails'] ) && is_array( $options['products'][ $slug ]['approved_emails'] )
                            ? array_values( array_unique( array_map( 'strtolower', $options['products'][ $slug ]['approved_emails'] ) ) )
                            : array();
                    }

                    $products[ $slug ] = $product;
                }
            }

            $options['products'] = $products;

            update_option( $this->option_key, $options );
            $this->options = $options;

            // Rewrite safety: flush once after settings save.
            update_option( 'mrm_pa_rewrite_flushed_ver', '0' );

            echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'mrm-product-access' ) . '</p></div>';
        }

        ?>
        <div class="wrap">
            <!-- Inline style to ensure product configuration inputs and textareas do not overlap. -->
            <style>
            /* Make inputs and textareas stretch to fill their cells without overflowing. */
            .mrm-pa-products-table td input[type="text"],
            .mrm-pa-products-table td input[type="number"],
            .mrm-pa-products-table td textarea {
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }
            </style>
            <h1><?php esc_html_e( 'MRM Product Access Settings', 'mrm-product-access' ); ?></h1>
            <form method="post">
                <?php wp_nonce_field( 'mrm_pa_save_settings' ); ?>

                <h2 class="title"><?php esc_html_e( 'Stripe Configuration', 'mrm-product-access' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Test Mode', 'mrm-product-access' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="test_mode" <?php checked( ! empty( $options['test_mode'] ) ); ?> />
                                <?php esc_html_e( 'Enable test mode', 'mrm-product-access' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Stripe Test Secret Key', 'mrm-product-access' ); ?></th>
                        <td><input type="text" name="stripe_test_secret_key" value="<?php echo esc_attr( $options['stripe_test_secret_key'] ?? '' ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Stripe Test Webhook Secret', 'mrm-product-access' ); ?></th>
                        <td><input type="text" name="stripe_test_webhook_secret" value="<?php echo esc_attr( $options['stripe_test_webhook_secret'] ?? '' ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Stripe Live Secret Key', 'mrm-product-access' ); ?></th>
                        <td><input type="text" name="stripe_live_secret_key" value="<?php echo esc_attr( $options['stripe_live_secret_key'] ?? '' ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Stripe Live Webhook Secret', 'mrm-product-access' ); ?></th>
                        <td><input type="text" name="stripe_live_webhook_secret" value="<?php echo esc_attr( $options['stripe_live_webhook_secret'] ?? '' ); ?>" class="regular-text"></td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e( 'Email Template', 'mrm-product-access' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Email Subject', 'mrm-product-access' ); ?></th>
                        <td><input type="text" name="email_subject" value="<?php echo esc_attr( $options['email_subject'] ?? '' ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Email Body', 'mrm-product-access' ); ?></th>
                        <td>
                            <textarea name="email_body" rows="5" cols="50" class="large-text code"><?php echo esc_textarea( $options['email_body'] ?? '' ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Use {{OTP}} as the placeholder for the one‑time code.', 'mrm-product-access' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php
                // Verified Emails section removed per user request. Keeping the underlying
                // verified_emails option in place but no longer rendering inputs.
                ?>

                <h2 class="title"><?php esc_html_e( 'Pieces Catalog (Sheet Music Listings)', 'mrm-product-access' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Enter the piece fields + purchasing options (offers) exactly like the old product HTML. URLs can be full URLs or site-relative paths (e.g. /wp-content/uploads/...).', 'mrm-product-access' ); ?>
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Sheet Music Catalog Page URL', 'mrm-product-access' ); ?></th>
                        <td>
                            <input type="text" class="regular-text" name="sheet_music_catalog_url"
                                value="<?php echo esc_attr( $options['sheet_music_catalog_url'] ?? '/sheet-music/' ); ?>"
                                placeholder="/sheet-music/" />
                            <p class="description"><?php esc_html_e( 'Used for the “Preview more pieces” button on piece detail pages.', 'mrm-product-access' ); ?></p>
                        </td>
                    </tr>
                </table>

                <style>
                /* Admin-only styling for a cleaner “card” editor */
                .mrm-pa-piece-card{
                    border: 1px solid #dcdcde;
                    background: #fff;
                    border-radius: 12px;
                    padding: 14px;
                    margin: 14px 0;
                }
                .mrm-pa-piece-card-head{
                    display:flex;
                    align-items:center;
                    justify-content:space-between;
                    gap: 12px;
                    margin-bottom: 10px;
                }
                .mrm-pa-piece-card h3{
                    margin: 0;
                    font-size: 14px;
                }
                .mrm-pa-grid{
                    display:grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 12px;
                }
                .mrm-pa-grid .mrm-pa-field{ display:flex; flex-direction:column; gap:6px; }
                .mrm-pa-grid .mrm-pa-field label{ font-weight:600; }
                .mrm-pa-grid .mrm-pa-field input[type="text"],
                .mrm-pa-grid .mrm-pa-field input[type="number"],
                .mrm-pa-grid .mrm-pa-field textarea{
                    width:100%;
                    max-width:100%;
                    box-sizing:border-box;
                }
                .mrm-pa-wide{ grid-column: 1 / -1; }
                .mrm-pa-help{ color:#646970; font-size: 12px; margin-top: 2px; }
                .mrm-pa-offers{
                    margin-top: 12px;
                    border-top: 1px solid #ececec;
                    padding-top: 12px;
                }
                .mrm-pa-offers-head{
                    display:flex;
                    align-items:center;
                    justify-content:space-between;
                    gap: 10px;
                    margin-bottom: 8px;
                }
                .mrm-pa-offers table.widefat td input[type="text"]{
                    width:100%;
                    max-width:100%;
                    box-sizing:border-box;
                }
                .mrm-pa-row-actions{
                    display:flex;
                    gap:8px;
                }
                @media (max-width: 900px){
                    .mrm-pa-grid{ grid-template-columns: 1fr; }
                }
                </style>

                <div id="mrm-pa-pieces-cards">
                <?php
                $pieces = isset( $options['pieces'] ) && is_array( $options['pieces'] ) ? $options['pieces'] : array();
                if ( empty( $pieces ) ) {
                    $pieces = array(
                        array(
                            'slug' => '',
                            'piece_title' => '',
                            'composer_name' => '',
                            'composer_url' => '',
                            'description' => '',
                            'short_description' => '',
                            'long_description' => '',
                            'difficulty' => '',
                            'instrumentation' => '',
                            'duration' => '',
                            'year' => '',
                            'main_preview_pdf_url' => '',
                            'preview_page_number' => 1,
                            'preview_audio_url' => '',
                            'offers' => array(),
                        )
                    );
                }

                foreach ( $pieces as $i => $piece ) :
                    $slug   = $piece['slug'] ?? '';
                    $title  = $piece['piece_title'] ?? '';
                    $cname  = $piece['composer_name'] ?? '';
                    $curl   = $piece['composer_url'] ?? '';
                    $desc   = $piece['description'] ?? '';
                    $short_desc = $piece['short_description'] ?? '';
                    $long_desc  = $piece['long_description'] ?? '';
                    $diff   = $piece['difficulty'] ?? '';
                    $instr  = $piece['instrumentation'] ?? '';
                    $dur    = $piece['duration'] ?? '';
                    $yr     = $piece['year'] ?? '';
                    $pdf    = $piece['main_preview_pdf_url'] ?? '';
                    $ppn    = intval( $piece['preview_page_number'] ?? 1 );
                    if ( $ppn <= 0 ) { $ppn = 1; }
                    $preview_audio_url = $piece['preview_audio_url'] ?? '';
                    $offers = isset( $piece['offers'] ) && is_array( $piece['offers'] ) ? $piece['offers'] : array();
                    ?>
                    <div class="mrm-pa-piece-card" data-piece-index="<?php echo esc_attr( $i ); ?>">
                        <div class="mrm-pa-piece-card-head">
                            <h3><?php echo esc_html( $title !== '' ? $title : 'New Piece' ); ?></h3>
                            <div class="mrm-pa-row-actions">
                                <button type="button" class="button mrm-pa-remove-piece"><?php esc_html_e( 'Remove Piece', 'mrm-product-access' ); ?></button>
                            </div>
                        </div>

                        <div class="mrm-pa-grid">
                            <div class="mrm-pa-field">
                                <label><?php esc_html_e( 'Piece Title', 'mrm-product-access' ); ?></label>
                                <input type="text" name="piece_title[]" value="<?php echo esc_attr( $title ); ?>" placeholder="Blackbeard's Revenge">
                            </div>

                            <div class="mrm-pa-field">
                                <label><?php esc_html_e( 'Slug', 'mrm-product-access' ); ?></label>
                                <input type="text" name="piece_slug[]" value="<?php echo esc_attr( $slug ); ?>" placeholder="blackbeards-revenge">
                                <div class="mrm-pa-help"><?php esc_html_e( 'Leave blank to auto-generate from title.', 'mrm-product-access' ); ?></div>
                            </div>

                            <div class="mrm-pa-field">
                                <label><?php esc_html_e( 'Composer Name', 'mrm-product-access' ); ?></label>
                                <input type="text" name="piece_composer_name[]" value="<?php echo esc_attr( $cname ); ?>" placeholder="Isaac Davanzo">
                            </div>

                            <div class="mrm-pa-field">
                                <label><?php esc_html_e( 'Composer URL', 'mrm-product-access' ); ?></label>
                                <input type="text" name="piece_composer_url[]" value="<?php echo esc_attr( $curl ); ?>" placeholder="https://example.com/composer">
                            </div>

                            <div class="mrm-pa-field mrm-pa-wide">
                                <label><?php esc_html_e( 'Short Description (shows on catalog)', 'mrm-product-access' ); ?></label>
                                <textarea name="piece_short_description[]" rows="2" placeholder="A 1–2 sentence summary shown on the catalog listing."><?php echo esc_textarea( $short_desc ); ?></textarea>
                            </div>

                            <div class="mrm-pa-field mrm-pa-wide">
                                <label><?php esc_html_e( 'Long Description (shows on piece page)', 'mrm-product-access' ); ?></label>
                                <textarea name="piece_long_description[]" rows="5" placeholder="Full description shown on the generated piece page."><?php echo esc_textarea( $long_desc !== '' ? $long_desc : $desc ); ?></textarea>
                            </div>

                            <input type="hidden" name="piece_description[]" value="<?php echo esc_attr( $long_desc !== '' ? $long_desc : $desc ); ?>">

                            <div class="mrm-pa-field">
                                <label><?php esc_html_e( 'Difficulty', 'mrm-product-access' ); ?></label>
                                <input type="text" name="piece_difficulty[]" value="<?php echo esc_attr( $diff ); ?>" placeholder="Intermediate Grade 3">
                            </div>

                            <div class="mrm-pa-field">
                                <label><?php esc_html_e( 'Instrumentation', 'mrm-product-access' ); ?></label>
                                <input type="text" name="piece_instrumentation[]" value="<?php echo esc_attr( $instr ); ?>" placeholder="Trombone/Euphonium/Tuba">
                            </div>

                            <div class="mrm-pa-field">
                                <label><?php esc_html_e( 'Duration', 'mrm-product-access' ); ?></label>
                                <input type="text" name="piece_duration[]" value="<?php echo esc_attr( $dur ); ?>" placeholder="3:08">
                            </div>

                            <div class="mrm-pa-field">
                                <label><?php esc_html_e( 'Year', 'mrm-product-access' ); ?></label>
                                <input type="text" name="piece_year[]" value="<?php echo esc_attr( $yr ); ?>" placeholder="2023">
                            </div>

                            <div class="mrm-pa-field mrm-pa-wide">
                                <label><?php esc_html_e( 'Main Preview PDF URL / Path', 'mrm-product-access' ); ?></label>
                                <input type="text" name="piece_main_preview_pdf_url[]" value="<?php echo esc_attr( $pdf ); ?>" placeholder="/wp-content/uploads/2026/01/BlackbeardsRevengeSamplePage.pdf">
                            </div>

                            <div class="mrm-pa-field mrm-pa-wide">
                                <label><?php esc_html_e( 'Preview Audio URL / Path (plays on the card)', 'mrm-product-access' ); ?></label>
                                <input type="text" name="piece_preview_audio_url[]" value="<?php echo esc_attr( $preview_audio_url ); ?>" placeholder="/wp-content/uploads/2025/12/Blackbeards-Revenge.mp3">
                                <div class="mrm-pa-help"><?php esc_html_e( 'This is the piece’s main preview audio (separate from offer preview audio).', 'mrm-product-access' ); ?></div>
                            </div>

                            <div class="mrm-pa-field">
                                <label><?php esc_html_e( 'Preview Page Number', 'mrm-product-access' ); ?></label>
                                <input type="number" name="piece_preview_page_number[]" value="<?php echo esc_attr( $ppn ); ?>" min="1" step="1">
                            </div>
                        </div>

                        <div class="mrm-pa-offers">
                            <div class="mrm-pa-offers-head">
                                <strong><?php esc_html_e( 'Purchasing Options (Offers)', 'mrm-product-access' ); ?></strong>
                                <button type="button" class="button button-secondary mrm-pa-add-offer"><?php esc_html_e( 'Add Offer', 'mrm-product-access' ); ?></button>
                            </div>

                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th style="width:180px;"><?php esc_html_e( 'Product Slug', 'mrm-product-access' ); ?></th>
                                        <th style="width:220px;"><?php esc_html_e( 'Display Title', 'mrm-product-access' ); ?></th>
                                        <th><?php esc_html_e( 'Subtitle', 'mrm-product-access' ); ?></th>
                                        <th style="width:110px;"><?php esc_html_e( 'Price Display', 'mrm-product-access' ); ?></th>
                                        <th style="width:260px;"><?php esc_html_e( 'Preview Audio URL / Path', 'mrm-product-access' ); ?></th>
                                        <th style="width:110px;"><?php esc_html_e( 'Actions', 'mrm-product-access' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody class="mrm-pa-offers-body">
                                <?php
                                if ( empty( $offers ) ) {
                                    $offers = array( array() );
                                }
                                foreach ( $offers as $off ) :
                                    $ps = $off['product_slug'] ?? '';
                                    $dt = $off['display_title'] ?? '';
                                    $st = $off['subtitle'] ?? '';
                                    $pr = $off['price_display'] ?? '';
                                    $au = $off['preview_audio_url'] ?? '';
                                    ?>
                                    <tr class="mrm-pa-offer-row">
                                        <td>
                                            <input type="hidden" name="offer_piece_index[]" value="<?php echo esc_attr( $i ); ?>">
                                            <input type="text" name="offer_product_slug[]" value="<?php echo esc_attr( $ps ); ?>" placeholder="blackbeards-revenge-tuba-full-piece">
                                        </td>
                                        <td><input type="text" name="offer_display_title[]" value="<?php echo esc_attr( $dt ); ?>" placeholder="Tuba Full Piece"></td>
                                        <td><input type="text" name="offer_subtitle[]" value="<?php echo esc_attr( $st ); ?>" placeholder="Includes the Tuba part..."></td>
                                        <td><input type="text" name="offer_price_display[]" value="<?php echo esc_attr( $pr ); ?>" placeholder="$25"></td>
                                        <td><input type="text" name="offer_preview_audio_url[]" value="<?php echo esc_attr( $au ); ?>" placeholder="/wp-content/uploads/2025/12/Blackbeards-Revenge.mp3"></td>
                                        <td><button type="button" class="button mrm-pa-remove-offer"><?php esc_html_e( 'Remove', 'mrm-product-access' ); ?></button></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>

                            <div class="mrm-pa-help" style="margin-top:8px;">
                                <?php esc_html_e( 'These offer fields map 1:1 with the legacy HTML mrmConfig.offers: productSlug, displayTitle, subtitle, priceDisplay, previewAudioUrl.', 'mrm-product-access' ); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>

                <p style="margin-top:10px;">
                    <button type="button" class="button button-secondary" id="mrm-pa-add-piece"><?php esc_html_e( 'Add Piece', 'mrm-product-access' ); ?></button>
                </p>

                <script>
                (function(){
                    const wrap = document.getElementById('mrm-pa-pieces-cards');
                    const addPieceBtn = document.getElementById('mrm-pa-add-piece');
                    if (!wrap || !addPieceBtn) return;

                    function pieceTemplate(index){
                        return `
                        <div class="mrm-pa-piece-card" data-piece-index="${index}">
                            <div class="mrm-pa-piece-card-head">
                                <h3>New Piece</h3>
                                <div class="mrm-pa-row-actions">
                                    <button type="button" class="button mrm-pa-remove-piece">Remove Piece</button>
                                </div>
                            </div>

                            <div class="mrm-pa-grid">
                                <div class="mrm-pa-field">
                                    <label>Piece Title</label>
                                    <input type="text" name="piece_title[]" value="" placeholder="Blackbeard's Revenge">
                                </div>

                                <div class="mrm-pa-field">
                                    <label>Slug</label>
                                    <input type="text" name="piece_slug[]" value="" placeholder="blackbeards-revenge">
                                    <div class="mrm-pa-help">Leave blank to auto-generate from title.</div>
                                </div>

                                <div class="mrm-pa-field">
                                    <label>Composer Name</label>
                                    <input type="text" name="piece_composer_name[]" value="" placeholder="Isaac Davanzo">
                                </div>

                                <div class="mrm-pa-field">
                                    <label>Composer URL</label>
                                    <input type="text" name="piece_composer_url[]" value="" placeholder="https://example.com/composer">
                                </div>

                                <div class="mrm-pa-field mrm-pa-wide">
                                    <label>Short Description (shows on catalog)</label>
                                    <textarea name="piece_short_description[]" rows="2" placeholder="A 1–2 sentence summary shown on the catalog listing."></textarea>
                                </div>

                                <div class="mrm-pa-field mrm-pa-wide">
                                    <label>Long Description (shows on piece page)</label>
                                    <textarea name="piece_long_description[]" rows="5" placeholder="Full description shown on the generated piece page."></textarea>
                                </div>

                                <input type="hidden" name="piece_description[]" value="">

                                <div class="mrm-pa-field">
                                    <label>Difficulty</label>
                                    <input type="text" name="piece_difficulty[]" value="" placeholder="Intermediate Grade 3">
                                </div>

                                <div class="mrm-pa-field">
                                    <label>Instrumentation</label>
                                    <input type="text" name="piece_instrumentation[]" value="" placeholder="Trombone/Euphonium/Tuba">
                                </div>

                                <div class="mrm-pa-field">
                                    <label>Duration</label>
                                    <input type="text" name="piece_duration[]" value="" placeholder="3:08">
                                </div>

                                <div class="mrm-pa-field">
                                    <label>Year</label>
                                    <input type="text" name="piece_year[]" value="" placeholder="2023">
                                </div>

                                <div class="mrm-pa-field mrm-pa-wide">
                                    <label>Main Preview PDF URL / Path</label>
                                    <input type="text" name="piece_main_preview_pdf_url[]" value="" placeholder="/wp-content/uploads/.../SamplePage.pdf">
                                </div>

                                <div class="mrm-pa-field mrm-pa-wide">
                                    <label>Preview Audio URL / Path (plays on the card)</label>
                                    <input type="text" name="piece_preview_audio_url[]" value="" placeholder="/wp-content/uploads/2025/12/Blackbeards-Revenge.mp3">
                                    <div class="mrm-pa-help">This is the piece’s main preview audio (separate from offer preview audio).</div>
                                </div>

                                <div class="mrm-pa-field">
                                    <label>Preview Page Number</label>
                                    <input type="number" name="piece_preview_page_number[]" value="1" min="1" step="1">
                                </div>
                            </div>

                            <div class="mrm-pa-offers">
                                <div class="mrm-pa-offers-head">
                                    <strong>Purchasing Options (Offers)</strong>
                                    <button type="button" class="button button-secondary mrm-pa-add-offer">Add Offer</button>
                                </div>

                                <table class="widefat striped">
                                    <thead>
                                        <tr>
                                            <th style="width:180px;">Product Slug</th>
                                            <th style="width:220px;">Display Title</th>
                                            <th>Subtitle</th>
                                            <th style="width:110px;">Price Display</th>
                                            <th style="width:260px;">Preview Audio URL / Path</th>
                                            <th style="width:110px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="mrm-pa-offers-body">
                                        ${offerRowTemplate(index)}
                                    </tbody>
                                </table>

                                <div class="mrm-pa-help" style="margin-top:8px;">
                                    These map to legacy HTML mrmConfig.offers: productSlug, displayTitle, subtitle, priceDisplay, previewAudioUrl.
                                </div>
                            </div>
                        </div>`;
                    }

                    function offerRowTemplate(pieceIndex){
                        return `
                        <tr class="mrm-pa-offer-row">
                            <td>
                                <input type="hidden" name="offer_piece_index[]" value="${pieceIndex}">
                                <input type="text" name="offer_product_slug[]" value="" placeholder="blackbeards-revenge-tuba-full-piece">
                            </td>
                            <td><input type="text" name="offer_display_title[]" value="" placeholder="Tuba Full Piece"></td>
                            <td><input type="text" name="offer_subtitle[]" value="" placeholder="Includes the Tuba part..."></td>
                            <td><input type="text" name="offer_price_display[]" value="" placeholder="$25"></td>
                            <td><input type="text" name="offer_preview_audio_url[]" value="" placeholder="/wp-content/uploads/.../demo.mp3"></td>
                            <td><button type="button" class="button mrm-pa-remove-offer">Remove</button></td>
                        </tr>`;
                    }

                    addPieceBtn.addEventListener('click', function(){
                        const nextIndex = wrap.querySelectorAll('.mrm-pa-piece-card').length;
                        const temp = document.createElement('div');
                        temp.innerHTML = pieceTemplate(nextIndex);
                        wrap.appendChild(temp.firstElementChild);
                    });

                    wrap.addEventListener('click', function(e){
                        const removePieceBtn = e.target.closest('.mrm-pa-remove-piece');
                        if (removePieceBtn) {
                            const card = removePieceBtn.closest('.mrm-pa-piece-card');
                            if (card) card.remove();
                            return;
                        }

                        const addOfferBtn = e.target.closest('.mrm-pa-add-offer');
                        if (addOfferBtn) {
                            const card = addOfferBtn.closest('.mrm-pa-piece-card');
                            if (!card) return;
                            const pieceIndex = card.getAttribute('data-piece-index');
                            const tbody = card.querySelector('.mrm-pa-offers-body');
                            if (!tbody) return;

                            const temp = document.createElement('tbody');
                            temp.innerHTML = offerRowTemplate(pieceIndex);
                            tbody.appendChild(temp.firstElementChild);
                            return;
                        }

                        const removeOfferBtn = e.target.closest('.mrm-pa-remove-offer');
                        if (removeOfferBtn) {
                            const row = removeOfferBtn.closest('.mrm-pa-offer-row');
                            if (row) row.remove();
                            return;
                        }
                    });
                })();
                </script>

                <h2 class="title"><?php esc_html_e( 'Products Configuration', 'mrm-product-access' ); ?></h2>
                <p><?php esc_html_e( 'Define your products below. Payouts format: connectedAccountId:pct;anotherId:pct. Percentages should add up to 100.', 'mrm-product-access' ); ?></p>

                <table class="widefat fixed striped mrm-pa-products-table" cellspacing="0" style="max-width:1200px;">
                    <thead>
                        <tr>
                            <th style="width:130px; "><?php esc_html_e( 'Product Slug', 'mrm-product-access' ); ?></th>
                            <th style="width:120px; "><?php esc_html_e( 'Price (cents)', 'mrm-product-access' ); ?></th>
                            <th style="width:90px; "><?php esc_html_e( 'Currency', 'mrm-product-access' ); ?></th>
                            <th style="width:220px; "><?php esc_html_e( 'Payouts (dest:pct;...)', 'mrm-product-access' ); ?></th>
                            <?php
                            // Removed PDF Path, Audio Path and ZIP Path columns per user request.
                            ?>
                            <th style="width:240px; "><?php esc_html_e( 'Approved Emails (semicolon separated)', 'mrm-product-access' ); ?></th>
                            <th style="width:320px; "><?php esc_html_e( 'Tracks JSON (ordered items)', 'mrm-product-access' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ( ! empty( $options['products'] ) && is_array( $options['products'] ) ) {
                            foreach ( $options['products'] as $slug => $pconf ) {
                                $pairs = array();
                                if ( ! empty( $pconf['payouts'] ) && is_array( $pconf['payouts'] ) ) {
                                    foreach ( $pconf['payouts'] as $p ) {
                                        $pairs[] = ( $p['stripe_account_id'] ?? '' ) . ':' . ( $p['pct'] ?? 0 );
                                    }
                                }
                                $tracks_text = '';
                                if ( ! empty( $pconf['tracks'] ) && is_array( $pconf['tracks'] ) ) {
                                    $tracks_text = wp_json_encode( $pconf['tracks'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
                                }
                                ?>
                                <tr>
                                    <td><input type="text" name="product_slug[]" value="<?php echo esc_attr( $slug ); ?>" class="regular-text"></td>
                                    <td><input type="number" name="product_price_cents[]" value="<?php echo esc_attr( $pconf['price_cents'] ?? 0 ); ?>" class="small-text"></td>
                                    <td><input type="text" name="product_currency[]" value="<?php echo esc_attr( $pconf['currency'] ?? 'usd' ); ?>" class="small-text"></td>
                                    <td><input type="text" name="product_payout[]" value="<?php echo esc_attr( implode( ';', $pairs ) ); ?>" class="regular-text"></td>
                                    <td><input type="text" name="product_approved_emails[]" value="<?php echo esc_attr( isset( $pconf['approved_emails'] ) && is_array( $pconf['approved_emails'] ) ? implode( ';', $pconf['approved_emails'] ) : '' ); ?>" class="regular-text" placeholder="email1@example.com;email2@example.com"></td>
                                    <td>
                                        <textarea name="product_tracks_json[]" rows="6" style="width:100%; font-family: monospace;"><?php echo esc_textarea( $tracks_text ); ?></textarea>
                                        <div class="description" style="font-size:12px; opacity:.9;">
                                            <?php
                                            /* Translators: example of the new tracks JSON array format */
                                            echo esc_html__( 'Example', 'mrm-product-access' );
                                            ?>:
                                            <?php echo esc_html( '[{"type":"pdf","title":"PDF Title","path":"/full/path/file.pdf"},{"type":"audio","title":"Track 1","path":"/full/path/file.mp3"}]' ); ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        // Always provide an empty row to allow adding another product.
                        ?>
                        <tr>
                            <td><input type="text" name="product_slug[]" value="" class="regular-text"></td>
                            <td><input type="number" name="product_price_cents[]" value="" class="small-text"></td>
                            <td><input type="text" name="product_currency[]" value="usd" class="small-text"></td>
                            <td><input type="text" name="product_payout[]" value="" class="regular-text"></td>
                            <td><input type="text" name="product_approved_emails[]" value="" class="regular-text" placeholder="email1@example.com;email2@example.com"></td>
                            <td><textarea name="product_tracks_json[]" rows="6" style="width:100%; font-family: monospace;"></textarea></td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button( __( 'Save Settings', 'mrm-product-access' ), 'primary', 'mrm_pa_save_settings' ); ?>
            </form>

            <hr>
            <p><strong><?php esc_html_e( 'Access URL pattern:', 'mrm-product-access' ); ?></strong> <code><?php echo esc_html( home_url( '/mrm-access/{product_slug}/{token}/' ) ); ?></code></p>
            <p><strong><?php esc_html_e( 'Important:', 'mrm-product-access' ); ?></strong> <?php esc_html_e( 'After updating, go to Settings → Permalinks and click Save Changes once.', 'mrm-product-access' ); ?></p>
        </div>
        <?php
        // No client-side script is required for the settings page since the
        // Verified Emails section has been removed per user request.
        ?>
        <?php
    }

    /**
     * Get product configuration by slug.
     *
     * @param string $slug
     * @return array|null
     */
    protected function get_product_config( $slug ) {
        $options = $this->get_options();
        return isset( $options['products'][ $slug ] ) ? $options['products'][ $slug ] : null;
    }

    /**
     * Build the piece details URL from a piece slug.
     *
     * @param string $piece_slug
     * @return string
     */
    private function get_piece_url( $piece_slug ) {
        $piece_slug = sanitize_title( (string) $piece_slug );
        if ( $piece_slug === '' ) {
            return home_url( '/' );
        }
        return home_url( '/' . $piece_slug . '/' );
    }

    /**
     * Lookup the piece slug associated with a product slug via offers.
     *
     * @param string $product_slug
     * @return string
     */
    private function get_piece_slug_for_product( $product_slug ) {
        $product_slug = sanitize_title( (string) $product_slug );
        if ( $product_slug === '' ) {
            return '';
        }

        $options = $this->get_options();
        $pieces  = isset( $options['pieces'] ) && is_array( $options['pieces'] ) ? $options['pieces'] : array();

        foreach ( $pieces as $piece ) {
            $piece_slug = sanitize_title( (string) ( $piece['slug'] ?? $piece['piece_slug'] ?? '' ) );
            if ( $piece_slug === '' ) {
                continue;
            }
            $offers = isset( $piece['offers'] ) && is_array( $piece['offers'] ) ? $piece['offers'] : array();
            foreach ( $offers as $offer ) {
                $offer_slug = sanitize_title( (string) ( $offer['product_slug'] ?? '' ) );
                if ( $offer_slug !== '' && $offer_slug === $product_slug ) {
                    return $piece_slug;
                }
            }
        }

        return '';
    }

    /**
     * Build the sheet music catalog URL from settings.
     *
     * @return string
     */
    private function get_sheet_music_catalog_url() {
        $options   = $this->get_options();
        $raw_value = $options['sheet_music_catalog_url'] ?? '/sheet-music/';
        return ( strpos( $raw_value, 'http' ) === 0 ) ? $raw_value : home_url( $raw_value );
    }

    /**
     * Hash an email address with NONCE_SALT.
     *
     * @param string $email
     * @return string
     */
    protected function hash_email( $email ) {
        $email = strtolower( trim( $email ) );
        return hash( 'sha256', $email . NONCE_SALT );
    }

    private function payments_hub_has_access( $email_hash, $sku ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mrm_sheet_music_access';

        // If Payments Hub isn't installed yet, fail closed.
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table
        ) );
        if ( $exists !== $table ) {
            return false;
        }

        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE email_hash = %s AND sku = %s AND revoked_at IS NULL LIMIT 1",
            (string) $email_hash,
            (string) $sku
        ) );

        return ! empty( $id );
    }


    /**
     * Check whether a given email hash is allowed for a product via the per-product approved list.
     *
     * @param string $product_slug
     * @param string $email_hash
     * @return bool
     */
    protected function is_email_hash_approved_for_product( $product_slug, $email_hash ) {
        $conf = $this->get_product_config( $product_slug );
        if ( empty( $conf ) ) {
            return false;
        }
        $list = isset( $conf['approved_emails'] ) && is_array( $conf['approved_emails'] ) ? $conf['approved_emails'] : array();
        if ( empty( $list ) ) {
            return false;
        }
        foreach ( $list as $email ) {
            $email = sanitize_email( $email );
            if ( empty( $email ) ) {
                continue;
            }
            if ( hash_equals( $email_hash, $this->hash_email( strtolower( trim( $email ) ) ) ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check whether a given email hash is in the global verified_emails list.
     * (UI removed, but option may still exist for backwards compatibility.)
     *
     * @param string $email_hash
     * @return bool
     */
    protected function is_email_hash_globally_verified( $email_hash ) {
        $options   = $this->get_options();
        $whitelist = isset( $options['verified_emails'] ) && is_array( $options['verified_emails'] ) ? $options['verified_emails'] : array();
        if ( empty( $whitelist ) ) {
            return false;
        }
        foreach ( $whitelist as $email ) {
            $email = sanitize_email( $email );
            if ( empty( $email ) ) {
                continue;
            }
            if ( hash_equals( $email_hash, $this->hash_email( strtolower( trim( $email ) ) ) ) ) {
                return true;
            }
        }
        return false;
    }


    /**
     * Generate a secure access token.
     *
     * @return string
     */
    protected function generate_access_token() {
        try {
            return bin2hex( random_bytes( 16 ) );
        } catch ( Exception $e ) {
            return wp_generate_password( 32, false, false );
        }
    }

    /**
     * Register custom rewrite rules for access URLs.
     */
    public function register_access_rewrite() {
        add_rewrite_rule(
            '^mrm-access/([^/]+)/([a-zA-Z0-9]{16,64})/?$',
            'index.php?mrm_access=1&mrm_access_product=$matches[1]&mrm_access_token=$matches[2]',
            'top'
        );
    }

    /**
     * Register query vars for access handling.
     *
     * @param array $vars
     * @return array
     */
    public function register_query_vars( $vars ) {
        $vars[] = 'mrm_access';
        $vars[] = 'mrm_access_product';
        $vars[] = 'mrm_access_token';
        return $vars;
    }

    /**
     * Parse access parameters from REQUEST_URI when rewrite rules fail.
     *
     * @return array|null
     */
    protected function parse_access_from_request_uri() {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        if ( ! $uri ) {
            return null;
        }

        $path = wp_parse_url( $uri, PHP_URL_PATH );
        if ( ! $path ) {
            return null;
        }

        $path = trim( $path, '/' );

        // Make sure we match beginning cleanly even with odd prefixes.
        if ( strpos( $path, 'mrm-access/' ) !== 0 ) {
            return null;
        }

        $parts = explode( '/', $path );
        if ( count( $parts ) < 3 ) {
            return null;
        }

        $slug  = sanitize_title( $parts[1] );
        $token = sanitize_text_field( $parts[2] );

        if ( ! $slug || ! $token ) {
            return null;
        }
        if ( ! preg_match( '/^[a-zA-Z0-9]{16,64}$/', $token ) ) {
            return null;
        }

        return array(
            'product_slug' => $slug,
            'token'        => $token,
        );
    }

    /**
     * Flush rewrite rules when plugin version changes.
     */
    public function maybe_flush_rewrites_on_update() {
        $flushed_ver = get_option( 'mrm_pa_rewrite_flushed_ver', '0' );
        if ( $flushed_ver !== self::VERSION ) {
            $this->register_access_rewrite();
            flush_rewrite_rules();
            update_option( 'mrm_pa_rewrite_flushed_ver', self::VERSION );
        }
    }

    /**
     * Render the access page if requested.
     *
     * Validates cookies, transient payloads and purchase status before
     * rendering content. If any check fails a 403 status is emitted.
     */
    public function maybe_render_access_page() {
        $product_slug = '';
        $token        = '';

        // Primary: query vars (rewrite).
        $is_access = get_query_var( 'mrm_access' );
        if ( $is_access == '1' ) {
            $product_slug = sanitize_title( get_query_var( 'mrm_access_product' ) );
            $token        = sanitize_text_field( get_query_var( 'mrm_access_token' ) );
        } else {
            // Fallback: parse REQUEST_URI.
            $parsed = $this->parse_access_from_request_uri();
            if ( $parsed ) {
                $product_slug = $parsed['product_slug'];
                $token        = $parsed['token'];
            }
        }

        if ( empty( $product_slug ) || empty( $token ) ) {
            return; // Not an access page request.
        }

        // Must have cookie matching this token.
        $cookie_name = 'mrm_access_' . $product_slug;
        if ( empty( $_COOKIE[ $cookie_name ] ) || ! hash_equals( $_COOKIE[ $cookie_name ], $token ) ) {
            status_header( 403 );
            echo 'Unauthorized.';
            exit;
        }

        $transient_key = 'mrm_access_' . $product_slug . '_' . $token;
        $payload       = get_transient( $transient_key );

        if ( empty( $payload ) || ! is_array( $payload ) ) {
            status_header( 403 );
            echo 'Access expired.';
            exit;
        }

        if ( empty( $payload['email_hash'] ) || empty( $payload['exp'] ) || empty( $payload['product_slug'] ) ) {
            status_header( 403 );
            echo 'Access invalid.';
            exit;
        }

        if ( time() > intval( $payload['exp'] ) ) {
            delete_transient( $transient_key );
            status_header( 403 );
            echo 'Access expired.';
            exit;
        }

        if ( $payload['product_slug'] !== $product_slug ) {
            status_header( 403 );
            echo 'Unauthorized.';
            exit;
        }

        global $wpdb;
        $table    = $wpdb->prefix . 'mrm_purchases';
        $purchase = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM $table WHERE product_slug = %s AND email_hash = %s AND status = 'paid' LIMIT 1",
            $product_slug,
            $payload['email_hash']
        ) );

        // Allow access if purchase exists OR the email has been manually approved for this product
        // (or is in the legacy global verified list).
        if ( ! $purchase ) {
            $email_hash = (string) $payload['email_hash'];
            $approved   = $this->is_email_hash_approved_for_product( $product_slug, $email_hash );
            $verified   = $this->is_email_hash_globally_verified( $email_hash );
            if ( ! $approved && ! $verified ) {
                status_header( 403 );
                echo 'Unauthorized.';
                exit;
            }
        }

        $config = $this->get_product_config( $product_slug );
        if ( empty( $config ) ) {
            status_header( 404 );
            echo 'Product not found.';
            exit;
        }

        $this->render_access_page( $product_slug, $config, $payload );
        exit;
    }

    /**
     * Render the purchased content access page.
     *
     * @param string $product_slug
     * @param array  $config
     */
    protected function render_access_page( $product_slug, $config, $payload ) {
        $api_base = home_url( '/wp-json/mrm/v1/download' );

        $build = function( $args ) use ( $api_base ) {
            return esc_url( add_query_arg( $args, $api_base ) );
        };

        $has_tracks = ! empty( $config['tracks'] ) && is_array( $config['tracks'] );
        // Use the same accent colour as the main site (golden‑olive tone).
        $accent = '#7b734a';

        // Build view.
        ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo esc_html__( 'Access', 'mrm-product-access' ) . ' - ' . esc_html( $product_slug ); ?></title>
  <style>
    :root{
      --mrm-bg: #ecf4f3;
      --mrm-surface: #ffffff;
      --mrm-text: #0a0a0a;
      --mrm-muted: #656565;
      --mrm-border: #d1d1d1;
      --mrm-accent: var(--wp--preset--color--primary, <?php echo esc_html( $accent ); ?>);
      --mrm-gold: var(--mrm-accent);
      --mrm-black: #000000;
      --mrm-radius: 14px;
    }
    *, *::before, *::after{ box-sizing: border-box; }
    body{
      margin:0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
      background: var(--mrm-bg);
      color: var(--mrm-text);
    }
    .wrap{
      max-width: 1100px;
      margin: 0 auto;
      padding: 22px;
    }
    .return-home{
      margin-bottom: 20px;
    }
    .return-home .home-btn{
      display:inline-block;
      background: var(--mrm-accent);
      color: #fff;
      padding: 10px 16px;
      border-radius: 999px;
      text-decoration:none;
      font-weight: 700;
      font-size: 14px;
    }
    .return-home .home-btn:hover{
      opacity: 0.9;
    }
    .header{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap: 14px;
      margin-bottom: 18px;
    }
    .header h1{
      margin:0;
      font-size: 22px;
      letter-spacing: 0.2px;
    }
    .header .sub{
      color: var(--mrm-muted);
      font-size: 14px;
      margin-top: 6px;
    }
    .card{
      background: var(--mrm-surface);
      border: 1px solid var(--mrm-border);
      border-radius: var(--mrm-radius);
      padding: 18px;
      margin-bottom: 18px;
    }
    .section-title{
      margin: 0 0 12px 0;
      font-size: 18px;
      background: #f0f0f0;
      padding: 10px 14px;
      border-radius: var(--mrm-radius);
    }
    .grid{
      display:grid;
      grid-template-columns: 1fr;
      gap: 14px;
    }
    .item{
      border: 1px solid var(--mrm-border);
      border-radius: calc(var(--mrm-radius) - 4px);
      overflow:hidden;
      background: #f9f9f9;
    }
    .item .top{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
      padding: 12px;
      border-bottom: 1px solid var(--mrm-border);
    }
    .item .top .label{
      font-weight: 700;
      font-size: 14px;
    }
    .btn{
      appearance:none;
      border: 1px solid var(--mrm-accent);
      background: transparent;
      color: var(--mrm-accent);
      padding: 8px 12px;
      border-radius: 10px;
      text-decoration:none;
      font-size: 13px;
      font-weight: 700;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      white-space:nowrap;
    }
    .btn.primary{
      background: var(--mrm-black);
      border-color: var(--mrm-black);
      color: #fff;
    }
    .content{
      padding: 12px;
      background: #ffffff;
    }
    iframe.pdf{
      width: 100%;
      height: min(72vh, 780px);
      border: 0;
      display:block;
      background: #ffffff;
    }
    audio{
      width: 100%;
      display:block;
      background: #ffffff;
    }

/* ===== Custom audio player (from your HTML), theme-driven ===== */
.audio-box{
  border: 1px solid var(--mrm-border);
  border-radius: var(--mrm-radius);
  padding: 12px;
  background: var(--mrm-surface);
  box-sizing: border-box;
}
.audio-controls{
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 12px;
}
.audio-row-top{ display:flex; align-items:center; gap:12px; }
.audio-row-seek{ min-width: 0; }
.audio-row-vol{ display:flex; align-items:center; justify-content:flex-end; gap:8px; }

.play-button{
  width: 42px; height: 42px;
  border-radius: 10px;
  background: var(--mrm-black);
  color: #ffffff;
  border: none;
  cursor: pointer;
  padding: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
  flex: 0 0 auto;
}
.play-button svg{ width:18px; height:18px; display:block; fill: currentColor; }
.play-button .icon-pause{ display:none; }
.play-button.is-playing .icon-play{ display:none; }
.play-button.is-playing .icon-pause{ display:block; }

.time{ font-size: 13px; color: var(--mrm-muted); min-width: 52px; text-align:center; flex:0 0 auto; }

/* Seek + Volume tracks stay black/soft; THUMB is gold */
input.mrm-range{
  width: 100%;
  min-width: 0;
  appearance: none;
  height: 8px;
  border-radius: 999px;
  background: rgba(0,0,0,0.10);
  cursor: pointer;
}
input.mrm-range::-webkit-slider-thumb{
  appearance: none;
  width: 14px; height: 14px;
  border-radius: 50%;
  background: var(--mrm-gold);
}
input.mrm-range::-moz-range-thumb{
  width: 14px; height: 14px;
  border-radius: 50%;
  background: var(--mrm-gold);
  border: none;
}
input.mrm-range::-moz-range-track{
  height: 8px;
  border-radius: 999px;
  background: rgba(0,0,0,0.10);
}

.volume{ display:inline-flex; align-items:center; gap:6px; color: var(--mrm-black); }
.volume svg{ width:18px; height:18px; }
.volume input{ width: 110px; }
    .note{
      color: var(--mrm-muted);
      font-size: 13px;
      line-height: 1.4;
      margin-top: 10px;
    }
    .pill{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding: 6px 10px;
      border-radius: 999px;
      border: 1px solid var(--mrm-border);
      color: var(--mrm-muted);
      font-size: 12px;
    }
    .spacer{
      height: 40px;
    }
    .mrm-preview-more-row{
      display:flex;
      justify-content:flex-start;
      margin-top: 18px;
    }

    /* Mobile scaling + layout fixes */
    @media (max-width: 720px){
      .wrap{ padding: 14px; }

      .header{
        flex-direction: column;
        align-items: flex-start;
      }

      .header h1{
        font-size: 18px;
      }

      .card{ padding: 14px; }

      .pdf{
        height: 60vh; /* better fit on phones */
      }

      iframe.pdf{
        width: 100%;
        max-width: 100%;
      }

      /* Make audio controls stack cleanly on mobile */
      .audio-controls{
        display: grid;
        grid-template-columns: 1fr;
        gap: 10px;
        justify-items: stretch;
      }

      .audio-row-top{
        width: 100%;
        display: grid;
        grid-template-columns: 42px 1fr 1fr;
        align-items: center;
        column-gap: 10px;
      }

      .audio-row-seek{ width: 100%; }
      .audio-row-vol{ width: 100%; justify-content: flex-start; }

      .volume input{ width: min(260px, 72vw); }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="return-home">
      <?php
      $piece_slug        = isset( $payload['piece_slug'] ) ? sanitize_title( (string) $payload['piece_slug'] ) : '';
      $piece_details_url = $piece_slug ? $this->get_piece_url( $piece_slug ) : home_url( '/' );
      ?>
      <a class="home-btn" href="<?php echo esc_url( $piece_details_url ); ?>">&larr; <?php esc_html_e( 'Return to piece details', 'mrm-product-access' ); ?></a>
    </div>
    <div class="header">
      <div>
        <h1><?php esc_html_e( 'Purchased Content', 'mrm-product-access' ); ?></h1>
        <div class="sub"><?php esc_html_e( 'Access is temporary and tied to this browser.', 'mrm-product-access' ); ?></div>
      </div>
      <div class="pill"><?php echo esc_html( $product_slug ); ?></div>
    </div>
    <?php
      $render_item = function( $track_key, $type, $label ) use ( $build, $product_slug, $has_tracks, $config ) {
          $inline_url = $build( array(
              'product_slug' => $product_slug,
              'asset_type'   => $type,
              'track'        => $track_key,
              'inline'       => 1,
          ) );
          $download_url = $build( array(
              'product_slug' => $product_slug,
              'asset_type'   => $type,
              'track'        => $track_key,
              'download'     => 1,
          ) );
          if ( ! $has_tracks ) {
              if ( $type === 'pdf' && ! empty( $config['pdf_path'] ) ) {
                  $inline_url   = $build( array( 'product_slug' => $product_slug, 'asset_type' => 'pdf', 'inline' => 1 ) );
                  $download_url = $build( array( 'product_slug' => $product_slug, 'asset_type' => 'pdf', 'download' => 1 ) );
              }
              if ( $type === 'audio' && ! empty( $config['audio_path'] ) ) {
                  $inline_url   = $build( array( 'product_slug' => $product_slug, 'asset_type' => 'audio', 'inline' => 1 ) );
                  $download_url = $build( array( 'product_slug' => $product_slug, 'asset_type' => 'audio', 'download' => 1 ) );
              }
          }
          echo '<div class="item">';
          echo '  <div class="top">';
          echo '    <div class="label">' . esc_html( $label ) . '</div>';
          echo '    <div style="display:flex; gap:10px; align-items:center;">';
          if ( $type === 'pdf' ) {
              echo '      <a class="btn" target="_blank" rel="noopener" href="' . esc_url( $inline_url ) . '">' . esc_html__( 'Full Screen', 'mrm-product-access' ) . '</a>';
          }
          echo '      <a class="btn primary" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download', 'mrm-product-access' ) . '</a>';
          echo '    </div>';
          echo '  </div>';
          echo '  <div class="content">';
          if ( $type === 'pdf' ) {
              $pdf_src = $inline_url;
              if ( false === strpos( $pdf_src, '#' ) ) {
                  // Hint most browser PDF viewers to fit-to-width
                  $pdf_src .= '#zoom=page-width';
              }

              echo '    <iframe class="pdf" src="' . esc_url( $pdf_src ) . '" loading="lazy" title="' . esc_attr( $label ) . '"></iframe>';
          } else {
echo '    <div class="audio-box mrm-audio-box" data-src="' . esc_url( $inline_url ) . '">';
echo '      <audio class="mrm-audio" preload="metadata">';
echo '        <source src="' . esc_url( $inline_url ) . '" type="audio/mpeg">';
echo '      </audio>';
echo '      <div class="audio-controls">';
echo '        <div class="audio-row-top">';
echo '          <button class="play-button mrm-play" type="button" aria-label="Play/Pause">';
echo '            <svg class="icon-play" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"></path></svg>';
echo '            <svg class="icon-pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h4v14H6zM14 5h4v14h-4z"></path></svg>';
echo '          </button>';
echo '          <div class="time mrm-current">0:00</div>';
echo '          <div class="time mrm-duration">0:00</div>';
echo '        </div>';
echo '        <div class="audio-row-seek">';
echo '          <input class="mrm-range mrm-seek" type="range" min="0" value="0" aria-label="Seek">';
echo '        </div>';
echo '        <div class="audio-row-vol">';
echo '          <div class="volume" aria-label="Volume">';
echo '            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11 5L6 9H2v6h4l5 4V5z"/></svg>';
echo '            <input class="mrm-range mrm-volume" type="range" min="0" max="1" step="0.01" value="1" aria-label="Volume slider">';
echo '          </div>';
echo '        </div>';
echo '      </div>';
echo '    </div>';
          }
          echo '  </div>';
          echo '</div>';
      };
    ?>
    <?php
        // Dynamically build sections based on tracks configuration
        $tracks = isset( $config['tracks'] ) && is_array( $config['tracks'] ) ? $config['tracks'] : array();
        $sections = array();
        if ( ! empty( $tracks ) ) {
            if ( array_keys( $tracks ) === range( 0, count( $tracks ) - 1 ) ) {
                // New array format: sequential list of items
                $currentSection = array( 'pdf' => null, 'audios' => array() );
                foreach ( $tracks as $i => $item ) {
                    if ( is_array( $item ) && isset( $item['type'] ) ) {
                        $type  = $item['type'];
                        $title = isset( $item['title'] ) ? $item['title'] : '';
                        if ( $type === 'pdf' ) {
                            if ( $currentSection['pdf'] !== null || ! empty( $currentSection['audios'] ) ) {
                                $sections[] = $currentSection;
                            }
                            $currentSection = array( 'pdf' => array( 'idx' => $i, 'title' => $title ), 'audios' => array() );
                        } else {
                            $currentSection['audios'][] = array( 'idx' => $i, 'title' => $title );
                        }
                    }
                }
                if ( $currentSection['pdf'] !== null || ! empty( $currentSection['audios'] ) ) {
                    $sections[] = $currentSection;
                }
            } else {
                // Legacy associative map: group by PDF vs audio based on key or file extension
                $currentSection = array( 'pdf' => null, 'audios' => array() );
                foreach ( $tracks as $k => $v ) {
                    $label  = $k;
                    $is_pdf = ( strpos( $k, '_pdf' ) !== false ) || preg_match( '/\.pdf$/i', $v );
                    if ( $is_pdf ) {
                        if ( $currentSection['pdf'] !== null || ! empty( $currentSection['audios'] ) ) {
                            $sections[] = $currentSection;
                        }
                        $currentSection = array( 'pdf' => array( 'idx' => $k, 'title' => $label ), 'audios' => array() );
                    } else {
                        $currentSection['audios'][] = array( 'idx' => $k, 'title' => $label );
                    }
                }
                if ( $currentSection['pdf'] !== null || ! empty( $currentSection['audios'] ) ) {
                    $sections[] = $currentSection;
                }
            }
        }
        // Output each section
        foreach ( $sections as $sec ) {
            $secTitle = '';
            if ( $sec['pdf'] !== null ) {
                $secTitle = $sec['pdf']['title'];
            } else {
                $secTitle = __( 'Audio', 'mrm-product-access' );
            }
            echo '<div class="card">';
            echo '<h2 class="section-title">' . esc_html( $secTitle ) . '</h2>';
            echo '<div class="grid">';
            if ( $sec['pdf'] !== null ) {
                $idx = $sec['pdf']['idx'];
                $render_item( is_string( $idx ) ? $idx : (string) $idx, 'pdf', $sec['pdf']['title'] );
            }
            foreach ( $sec['audios'] as $audio ) {
                $idx = $audio['idx'];
                $render_item( is_string( $idx ) ? $idx : (string) $idx, 'audio', $audio['title'] );
            }
            echo '</div>';
            echo '</div>';
            echo '<div class="spacer"></div>';
        }

    ?>
  </div>

<script>
(function(){
  if (window.__MRM_ACCESS_AUDIO_INIT__) return;
  window.__MRM_ACCESS_AUDIO_INIT__ = true;

  function fmtTime(t){
    if (isNaN(t) || t === Infinity) return "0:00";
    var m = Math.floor(t / 60);
    var s = Math.floor(t % 60);
    return m + ":" + String(s).padStart(2,'0');
  }

  var boxes = Array.prototype.slice.call(document.querySelectorAll('.mrm-audio-box'));
  if (!boxes.length) return;

  function pauseOthers(currentAudio){
    boxes.forEach(function(b){
      var a = b.querySelector('.mrm-audio');
      var btn = b.querySelector('.mrm-play');
      if (a && a !== currentAudio){
        a.pause();
        if (btn) btn.classList.remove('is-playing');
      }
    });
  }

  boxes.forEach(function(box){
    var audio = box.querySelector('.mrm-audio');
    var play  = box.querySelector('.mrm-play');
    var seek  = box.querySelector('.mrm-seek');
    var cur   = box.querySelector('.mrm-current');
    var dur   = box.querySelector('.mrm-duration');
    var vol   = box.querySelector('.mrm-volume');

    if (!audio || !play || !seek || !cur || !dur || !vol) return;

    function syncPlayUI(){
      var playing = audio && !audio.paused && !audio.ended;
      play.classList.toggle('is-playing', !!playing);
    }

    audio.addEventListener('loadedmetadata', function(){
      seek.max = String(Math.floor(audio.duration || 0));
      dur.textContent = fmtTime(audio.duration || 0);
      cur.textContent = fmtTime(0);
    });

    audio.addEventListener('timeupdate', function(){
      if (!seek.matches(':active')) seek.value = String(Math.floor(audio.currentTime || 0));
      cur.textContent = fmtTime(audio.currentTime || 0);
    });

    audio.addEventListener('play', function(){
      pauseOthers(audio);
      syncPlayUI();
    });
    audio.addEventListener('pause', syncPlayUI);
    audio.addEventListener('ended', function(){
      syncPlayUI();
      seek.value = "0";
      cur.textContent = "0:00";
    });

    play.addEventListener('click', function(){
      if (audio.paused) audio.play();
      else audio.pause();
      syncPlayUI();
    });

    seek.addEventListener('input', function(){
      audio.currentTime = Number(seek.value || 0);
      cur.textContent = fmtTime(audio.currentTime || 0);
    });

    vol.addEventListener('input', function(){
      audio.volume = Number(vol.value || 1);
    });
  });
})();
</script>

</body>
</html>
        <?php
    }

    /**
     * Authorize access and set auth cookie.
     *
     * @param WP_REST_Request $req
     * @return WP_REST_Response
     */
    public function api_authorize( WP_REST_Request $req ) {
        $data = (array) $req->get_json_params();

        $sku   = sanitize_key( (string) ( $data['sku'] ?? '' ) );
        $email = sanitize_email( (string) ( $data['email'] ?? '' ) );

        if ( empty( $sku ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Missing sku.' ), 400 );
        }
        if ( empty( $email ) || ! is_email( $email ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Valid email required.' ), 400 );
        }

        $email_hash = hash( 'sha256', strtolower( trim( $email ) ) );

        // Require Payments Hub access to exist (fail closed).
        if ( ! $this->payments_hub_has_access( $email_hash, $sku ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Access not granted.' ), 403 );
        }

        $token = array(
            'email' => $email_hash,
            'sku'   => $sku,
            'exp'   => time() + HOUR_IN_SECONDS * 6,
        );

        $options     = $this->get_options();
        $auth_secret = $options['auth_secret'] ?? '';
        if ( empty( $auth_secret ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Auth secret missing.' ), 500 );
        }

        $raw = base64_encode( wp_json_encode( $token ) );
        $sig = hash_hmac( 'sha256', $raw, $auth_secret );
        $cookie_value = $raw . '.' . $sig;

        $cookie_name = 'mrm_auth_' . $sku;

        setcookie( $cookie_name, $cookie_value, array(
            'expires'  => time() + HOUR_IN_SECONDS * 6,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ) );

        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    /**
     * Fetch a catalog piece by slug.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function api_get_piece( WP_REST_Request $request ) {
        $slug = sanitize_title( (string) $request->get_param( 'slug' ) );
        if ( $slug === '' ) {
            return new WP_REST_Response( array(
                'ok'      => false,
                'message' => 'Missing slug.',
            ), 400 );
        }

        $opts   = $this->get_options();
        $pieces = isset( $opts['pieces'] ) && is_array( $opts['pieces'] ) ? $opts['pieces'] : array();

        $found = null;
        foreach ( $pieces as $piece ) {
            $pslug = sanitize_title( (string) ( $piece['slug'] ?? $piece['piece_slug'] ?? '' ) );
            if ( $pslug && $pslug === $slug ) {
                $found = $piece;
                break;
            }
        }

        if ( ! $found ) {
            return new WP_REST_Response( array(
                'ok'      => false,
                'message' => 'Piece not found for slug: ' . $slug,
            ), 404 );
        }

        // Include catalog URL for "Preview more pieces" button.
        $catalog_url = method_exists( $this, 'get_sheet_music_catalog_url' )
            ? $this->get_sheet_music_catalog_url()
            : home_url( '/sheet-music/' );

        return new WP_REST_Response( array(
            'ok'          => true,
            'slug'        => $slug,
            'catalog_url' => $catalog_url,
            'piece'       => $found,
        ), 200 );
    }

    /**
     * Generate and send OTP.
     *
     * Implements per‑product rate limiting and returns descriptive errors where possible.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function api_request_otp( $request ) {
        $params       = $request->get_json_params();
        $email        = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
        $product_slug = isset( $params['product_slug'] ) ? sanitize_title( $params['product_slug'] ) : '';
        if ( empty( $product_slug ) && ! empty( $params['piece_slug'] ) ) {
            $product_slug = sanitize_title( $params['piece_slug'] );
        }

        // Generic privacy-preserving response.
        $generic = array(
            'ok'      => true,
            'message' => __( 'If this purchase exists, a code will be sent shortly.', 'mrm-product-access' ),
        );

        if ( empty( $email ) || empty( $product_slug ) ) {
            return new WP_REST_Response( $generic, 200 );
        }

        $normalized_email = strtolower( trim( $email ) );
        $email_hash       = $this->hash_email( $normalized_email );
        $ip               = $_SERVER['REMOTE_ADDR'] ?? '';

        global $wpdb;
        $table_purchases = $wpdb->prefix . 'mrm_purchases';
        $table_otps      = $wpdb->prefix . 'mrm_otp_tokens';

        // Verify that the purchase exists OR the email is allowed via approved lists.
        $purchase = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM $table_purchases WHERE product_slug = %s AND email_hash = %s AND status = 'paid' LIMIT 1",
            $product_slug,
            $email_hash
        ) );

        $is_globally_verified = $this->is_email_hash_globally_verified( $email_hash );
        $is_product_approved  = $this->is_email_hash_approved_for_product( $product_slug, $email_hash );

        if ( ! $purchase && ! $is_globally_verified && ! $is_product_approved ) {
            return new WP_REST_Response( $generic, 200 );
        }

        // Limit OTP requests per product, per hour.
        $one_hour_ago   = gmdate( 'Y-m-d H:i:s', time() - 3600 );
        $count_requests = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_otps WHERE product_slug = %s AND (email_hash = %s OR request_ip = %s) AND created_at >= %s",
            $product_slug,
            $email_hash,
            $ip,
            $one_hour_ago
        ) );
        if ( intval( $count_requests ) >= 10 ) {
            // Lower threshold per product.
            return new WP_REST_Response( $generic, 200 );
        }

        try {
            $otp = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Server error generating code.' ), 500 );
        }
        $otp_hash   = password_hash( $otp, PASSWORD_DEFAULT );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( 30 * 60 ) );

        $wpdb->insert( $table_otps, array(
            'product_slug'    => $product_slug,
            'purchaser_email' => $normalized_email,
            'email_hash'      => $email_hash,
            'otp_hash'        => $otp_hash,
            'expires_at'      => $expires_at,
            'request_ip'      => $ip,
            'attempt_count'   => 0,
            'created_at'      => gmdate( 'Y-m-d H:i:s' ),
        ) );

        $subject = $options['email_subject'] ?? __( 'Sheet Music Access Code', 'mrm-product-access' );
        $body    = str_replace( '{{OTP}}', $otp, ( $options['email_body'] ?? 'Hello,

Your one‑time passcode for accessing your purchased piece is {{OTP}}. It expires in 30 minutes.

Thank you.' ) );
        // Option A: Let FluentSMTP (or your SMTP plugin) control the From Name/Email.
        // Do NOT set custom headers here.
        $sent = wp_mail( $normalized_email, $subject, $body );
if ( ! $sent && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( 'MRM OTP email send failure to %s', $normalized_email ) );
        }

        return new WP_REST_Response( $generic, 200 );
    }

    /**
     * Verify the OTP code and return access URL.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function api_verify_otp( $request ) {
        $params       = $request->get_json_params();
        $email        = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
        $product_slug = isset( $params['product_slug'] ) ? sanitize_title( $params['product_slug'] ) : '';
        if ( empty( $product_slug ) && ! empty( $params['piece_slug'] ) ) {
            $product_slug = sanitize_title( $params['piece_slug'] );
        }
        $otp          = isset( $params['otp'] ) ? trim( $params['otp'] ) : '';

        if ( empty( $email ) || empty( $product_slug ) || empty( $otp ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Invalid request.' ), 400 );
        }

        $normalized_email = strtolower( trim( $email ) );
        $email_hash       = $this->hash_email( $normalized_email );

        global $wpdb;
        $table_otps = $wpdb->prefix . 'mrm_otp_tokens';

        $now = gmdate( 'Y-m-d H:i:s' );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_otps
             WHERE product_slug = %s AND email_hash = %s AND expires_at >= %s AND used_at IS NULL
             ORDER BY id DESC LIMIT 1",
            $product_slug,
            $email_hash,
            $now
        ) );

        if ( ! $row ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Invalid or expired code.' ), 400 );
        }
        if ( intval( $row->attempt_count ) >= 5 ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Too many attempts.' ), 429 );
        }

        $is_valid = password_verify( $otp, $row->otp_hash );

        $wpdb->update( $table_otps, array(
            'attempt_count' => intval( $row->attempt_count ) + 1,
        ), array(
            'id' => $row->id,
        ) );

        if ( ! $is_valid ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Invalid or expired code.' ), 400 );
        }

        $wpdb->update( $table_otps, array(
            'used_at' => gmdate( 'Y-m-d H:i:s' ),
        ), array(
            'id' => $row->id,
        ) );

        // Download auth cookie.
        $options     = $this->get_options();
        $auth_secret = $options['auth_secret'] ?? '';
        if ( empty( $auth_secret ) ) {
            $auth_secret            = wp_generate_password( 32, true, true );
            $options['auth_secret'] = $auth_secret;
            update_option( $this->option_key, $options );
            $this->options = $options;
        }

        $payload = array(
            'email'        => $email_hash,
            'product_slug' => $product_slug,
            'exp'          => time() + ( 2 * 3600 ),
        );

        $token     = base64_encode( wp_json_encode( $payload ) );
        $signature = hash_hmac( 'sha256', $token, $auth_secret );
        $cookie_value = $token . '.' . $signature;

        setcookie( 'mrm_auth_' . $product_slug, $cookie_value, time() + ( 2 * 3600 ), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

        // Access page token cookie + transient.
        $access_token = $this->generate_access_token();
        setcookie( 'mrm_access_' . $product_slug, $access_token, time() + ( 2 * 3600 ), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

        $transient_key = 'mrm_access_' . $product_slug . '_' . $access_token;
        $piece_slug_param      = isset( $params['piece_slug'] ) ? sanitize_title( (string) $params['piece_slug'] ) : '';
        $piece_slug_for_return = $piece_slug_param;

        if ( $piece_slug_for_return === '' ) {
            $piece_slug_for_return = $this->get_piece_slug_for_product( $product_slug );
        }
        if ( $piece_slug_for_return === '' && $product_slug !== '' ) {
            // fallback: if product slug itself is a piece slug.
            $piece_slug_for_return = $product_slug;
        }
        set_transient( $transient_key, array(
            'email_hash'   => $email_hash,
            'product_slug' => $product_slug,
            'piece_slug'   => $piece_slug_for_return,
            'exp'          => time() + ( 2 * 3600 ),
        ), 2 * HOUR_IN_SECONDS );

        $access_url = home_url( '/mrm-access/' . rawurlencode( $product_slug ) . '/' . rawurlencode( $access_token ) . '/' );

        return new WP_REST_Response( array( 'ok' => true, 'access_url' => $access_url ), 200 );
    }

    /**
     * Render the sheet music catalog shortcode.
     *
     * @return string
     */
    public function shortcode_sheet_music_catalog() {
        $opts   = $this->get_options();
        $pieces = isset( $opts['pieces'] ) && is_array( $opts['pieces'] ) ? $opts['pieces'] : array();

        ob_start();

        echo '<div class="mrm-catalog wrapper">';

        foreach ( $pieces as $piece ) {
            $slug = sanitize_title( (string) ( $piece['slug'] ?? '' ) );
            if ( empty( $slug ) ) {
                continue;
            }

            // --- Normalize piece fields (support both old + new saved shapes) ---
            $slug = sanitize_title( (string) ( $piece['slug'] ?? $piece['piece_slug'] ?? '' ) );
            if ( empty( $slug ) ) {
                continue;
            }

            // New shape (card editor)
            $title        = (string) ( $piece['piece_title'] ?? '' );
            $composer     = (string) ( $piece['composer_name'] ?? '' );
            $composer_url = (string) ( $piece['composer_url'] ?? '' );
            $desc         = (string) ( $piece['description'] ?? '' );

            $difficulty      = (string) ( $piece['difficulty'] ?? '' );
            $instrumentation = (string) ( $piece['instrumentation'] ?? '' );
            $duration        = (string) ( $piece['duration'] ?? '' );
            $year            = (string) ( $piece['year'] ?? '' );

            $pdf_url      = (string) ( $piece['main_preview_pdf_url'] ?? '' );
            $preview_page = (int) ( $piece['preview_page_number'] ?? 1 );

            // Piece-level preview audio (NEW)
            $preview_audio = (string) ( $piece['preview_audio_url'] ?? '' );

            // Back-compat: older table UI shape (if any)
            if ( $title === '' && ! empty( $piece['title'] ) ) {
                $title = (string) $piece['title'];
            }
            if ( $composer === '' && ! empty( $piece['composer'] ) ) {
                $composer = (string) $piece['composer'];
            }
            if ( $desc === '' && ! empty( $piece['description'] ) ) {
                $desc = (string) $piece['description'];
            }
            if ( $pdf_url === '' && ! empty( $piece['pdf_preview_url'] ) ) {
                $pdf_url = (string) $piece['pdf_preview_url'];
            }
            if ( $preview_audio === '' && ! empty( $piece['demo_audio_url'] ) ) {
                $preview_audio = (string) $piece['demo_audio_url'];
            }

            // Build the exact old “subtitle” line from the separate fields
            $meta_parts = array();
            if ( $instrumentation !== '' ) {
                $meta_parts[] = $instrumentation;
            }
            if ( $duration !== '' ) {
                $meta_parts[] = $duration;
            }
            if ( $year !== '' ) {
                $meta_parts[] = $year;
            }
            $meta_line = implode( ' • ', $meta_parts );

            // Sanitize for output
            $pdf_url       = esc_url( $pdf_url );
            $preview_audio = esc_url( $preview_audio );
            $preview_page  = max( 1, $preview_page );

            $title_esc      = esc_html( $title );
            $composer_esc   = esc_html( $composer );
            $difficulty_esc = esc_html( $difficulty );
            $meta_line_esc  = esc_html( $meta_line );
            $short = trim( (string) ( $piece['short_description'] ?? '' ) );
            if ( $short === '' ) {
                $short = trim( (string) ( $piece['description'] ?? '' ) );
            }
            $desc_html      = wp_kses_post( wpautop( $short ) );

            // Link behavior (match old HTML)
            $piece_url = $this->get_piece_url( $slug );

            echo '<article class="product-card mrm-piece"'
                // IMPORTANT: match old HTML expectations
                . ' data-product-slug="' . esc_attr( $slug ) . '"'
                // Keep back-compat for your current JS too
                . ' data-piece-slug="' . esc_attr( $slug ) . '"'
                . ' data-pdf-url="' . esc_attr( $pdf_url ) . '"'
                . ' data-preview-page="' . esc_attr( $preview_page ) . '"'
                . ' data-api-base="/wp-json/mrm/v1"'
                . '>';

            echo '<div class="pdf-col">
                    <div class="pdf-preview mrm-pdfPreview" role="button" tabindex="0" aria-label="Open PDF preview">
                      <canvas class="mrm-pdfCanvas"></canvas>
                    </div>
                  </div>';

            echo '<div class="meta">
                    <div class="title-block">
                      <h1 class="piece-title"><a href="' . esc_url( $piece_url ) . '">' . $title_esc . '</a></h1>';

            echo '    <div class="piece-composer">';
            if ( $composer_url !== '' ) {
                echo '<a href="' . esc_url( $composer_url ) . '" aria-label="View composer page" style="text-decoration:none;">' . $composer_esc . '</a>';
            } else {
                echo $composer_esc;
            }
            echo '    </div>';

            echo '    <div class="piece-subtitle">' . $difficulty_esc . '</div>
                      <div class="subtitle">' . $meta_line_esc . '</div>
                    </div>';

            echo '  <div class="description">' . $desc_html . '</div>';

            // Preview audio box (piece-level)
            echo '  <div class="audio-box">
                      <audio class="mrm-audio" preload="metadata">';
            if ( $preview_audio !== '' ) {
                echo '      <source src="' . esc_attr( $preview_audio ) . '" type="audio/mpeg">';
            }
            echo '    </audio>

                      <div class="audio-controls">
                        <div class="audio-row-top">
                          <button class="play-button mrm-play" type="button" aria-label="Play/Pause">
                            <svg class="icon-play" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"></path></svg>
                            <svg class="icon-pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h4v14H6zM14 5h4v14h-4z"></path></svg>
                          </button>
                          <div class="time mrm-current">0:00</div>
                          <div class="time mrm-duration">0:00</div>
                        </div>

                        <div class="audio-row-seek">
                          <input class="progress mrm-seek" type="range" min="0" value="0" aria-label="Seek">
                        </div>

                        <div class="audio-row-vol">
                          <div class="volume" aria-label="Volume">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11 5L6 9H2v6h4l5 4V5z"/></svg>
                            <input class="mrm-volume" type="range" min="0" max="1" step="0.01" value="1" aria-label="Volume slider">
                          </div>
                        </div>
                      </div>
                    </div>';

            echo '  <div class="mrm-view-options-row">
                      <a class="mrm-view-options-btn" href="' . esc_url( $piece_url ) . '">
                        View purchasing options
                      </a>
                    </div>';

            echo '</div>'; // .meta

            // PDF overlay (required for click-to-open)
            echo '<div class="mrm-pdfOverlay" aria-hidden="true">
                    <div class="mrm-pdfModal" role="dialog" aria-label="PDF Preview">
                      <div class="mrm-pdfScroll"></div>
                    </div>
                  </div>';

            echo '</article>';
        }

        echo '</div>';

        $this->output_catalog_assets_inline();

        return ob_get_clean();
    }

    /**
     * Render the piece details shortcode.
     *
     * @return string
     */
    public function shortcode_piece_details() {
        $opts   = $this->get_options();
        $pieces = isset( $opts['pieces'] ) && is_array( $opts['pieces'] ) ? $opts['pieces'] : array();

        $post      = get_queried_object();
        $page_slug = ( $post && ! empty( $post->post_name ) ) ? sanitize_title( (string) $post->post_name ) : '';

        if ( $page_slug === '' ) {
            return '<div class="wrapper"><p>Piece not found (missing page slug).</p></div>';
        }

        // Find the piece by slug in settings.
        $piece = null;
        foreach ( $pieces as $p ) {
            $slug = sanitize_title( (string) ( $p['slug'] ?? $p['piece_slug'] ?? '' ) );
            if ( $slug !== '' && $slug === $page_slug ) {
                $piece = $p;
                break;
            }
        }

        if ( ! $piece ) {
            return '<div class="wrapper"><p>Piece not found in settings for slug: <strong>' . esc_html( $page_slug ) . '</strong></p></div>';
        }

        // Normalize fields.
        $title        = (string) ( $piece['piece_title'] ?? $piece['title'] ?? '' );
        $composer     = (string) ( $piece['composer_name'] ?? $piece['composer'] ?? '' );
        $composer_url = (string) ( $piece['composer_url'] ?? '' );

        $difficulty      = (string) ( $piece['difficulty'] ?? '' );
        $instrumentation = (string) ( $piece['instrumentation'] ?? '' );
        $duration        = (string) ( $piece['duration'] ?? '' );
        $year            = (string) ( $piece['year'] ?? '' );

        $pdf_url       = (string) ( $piece['main_preview_pdf_url'] ?? $piece['pdf_preview_url'] ?? '' );
        $preview_page  = max( 1, (int) ( $piece['preview_page_number'] ?? 1 ) );
        $preview_audio = (string) ( $piece['preview_audio_url'] ?? $piece['demo_audio_url'] ?? '' );

        $short_desc = trim( (string) ( $piece['short_description'] ?? '' ) );
        $long_desc  = trim( (string) ( $piece['long_description'] ?? '' ) );

        // Subtitle meta line.
        $meta_parts = array();
        if ( $instrumentation !== '' ) {
            $meta_parts[] = $instrumentation;
        }
        if ( $duration !== '' ) {
            $meta_parts[] = $duration;
        }
        if ( $year !== '' ) {
            $meta_parts[] = $year;
        }
        $meta_line = implode( ' • ', $meta_parts );

        $offers = ( isset( $piece['offers'] ) && is_array( $piece['offers'] ) ) ? $piece['offers'] : array();

        $catalog_url = $this->get_sheet_music_catalog_url();

        ob_start();
        ?>
        <div class="wrapper">
          <article class="product-card mrm-piece"
            data-product-slug="<?php echo esc_attr( $page_slug ); ?>"
            data-piece-slug="<?php echo esc_attr( $page_slug ); ?>"
            data-pdf-url="<?php echo esc_attr( esc_url( $pdf_url ) ); ?>"
            data-preview-page="<?php echo esc_attr( $preview_page ); ?>"
            data-api-base="/wp-json/mrm/v1"
          >
            <div class="pdf-col">
              <div class="pdf-preview mrm-pdfPreview" role="button" tabindex="0" aria-label="Open PDF preview">
                <canvas class="mrm-pdfCanvas"></canvas>
              </div>
            </div>

            <div class="meta">
              <div class="title-block">
                <h1 class="piece-title"><?php echo esc_html( $title ); ?></h1>

                <div class="piece-composer">
                  <?php if ( $composer_url !== '' ) : ?>
                    <a href="<?php echo esc_url( $composer_url ); ?>" style="text-decoration:none;">
                      <?php echo esc_html( $composer ); ?>
                    </a>
                  <?php else : ?>
                    <?php echo esc_html( $composer ); ?>
                  <?php endif; ?>
                </div>

                <div class="piece-subtitle"><?php echo esc_html( $difficulty ); ?></div>
                <div class="subtitle"><?php echo esc_html( $meta_line ); ?></div>
              </div>

              <?php if ( $short_desc !== '' ) : ?>
                <div class="description"><?php echo wp_kses_post( wpautop( $short_desc ) ); ?></div>
              <?php endif; ?>

              <div class="audio-box">
                <audio class="mrm-audio" preload="metadata">
                  <?php if ( $preview_audio !== '' ) : ?>
                    <source src="<?php echo esc_attr( esc_url( $preview_audio ) ); ?>" type="audio/mpeg">
                  <?php endif; ?>
                </audio>

                <div class="audio-controls">
                  <div class="audio-row-top">
                    <button class="play-button mrm-play" type="button" aria-label="Play/Pause">
                      <svg class="icon-play" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"></path></svg>
                      <svg class="icon-pause" viewBox="0 0 24 24"><path d="M6 5h4v14H6zM14 5h4v14h-4z"></path></svg>
                    </button>
                    <div class="time mrm-current">0:00</div>
                    <div class="time mrm-duration">0:00</div>
                  </div>

                  <div class="audio-row-seek">
                    <input class="progress mrm-seek" type="range" min="0" value="0" aria-label="Seek">
                  </div>

                  <div class="audio-row-vol">
                    <div class="volume">
                      <svg viewBox="0 0 24 24"><path d="M11 5L6 9H2v6h4l5 4V5z"/></svg>
                      <input class="mrm-volume" type="range" min="0" max="1" step="0.01" value="1" aria-label="Volume">
                    </div>
                  </div>
                </div>
              </div>

              <?php if ( $long_desc !== '' ) : ?>
                <div class="mrm-long-description">
                  <?php echo wp_kses_post( wpautop( $long_desc ) ); ?>
                </div>
              <?php endif; ?>

              <div style="height:18px;"></div>

              <div class="options-head">
                <h2><?php echo esc_html__( 'Purchasing Options', 'mrm-product-access' ); ?></h2>
              </div>

              <div class="offers">
                <?php if ( empty( $offers ) ) : ?>
                  <div class="note"><?php echo esc_html__( 'No offers configured for this piece yet.', 'mrm-product-access' ); ?></div>
                <?php else : ?>
                  <?php foreach ( $offers as $offer ) :
                    $offer_title = (string) ( $offer['display_title'] ?? '' );
                    $offer_sub   = (string) ( $offer['subtitle'] ?? '' );
                    $offer_price = (string) ( $offer['price_display'] ?? '' );
                    $offer_slug  = sanitize_title( (string) ( $offer['product_slug'] ?? '' ) );
                  ?>
                    <div class="offer">
                      <div class="offer-row">
                        <div>
                          <div class="offer-title"><?php echo esc_html( $offer_title ); ?></div>
                          <?php if ( $offer_sub !== '' ) : ?><div class="offer-sub"><?php echo esc_html( $offer_sub ); ?></div><?php endif; ?>
                        </div>
                        <?php if ( $offer_price !== '' ) : ?><div class="offer-price"><?php echo esc_html( $offer_price ); ?></div><?php endif; ?>
                      </div>

                      <div class="offer-actions">
                        <button type="button" class="buyBtn" data-product-slug="<?php echo esc_attr( $offer_slug ); ?>">
                          <?php echo esc_html__( 'Buy', 'mrm-product-access' ); ?>
                        </button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <div class="mrm-preview-more-row">
                <a class="home-btn" href="<?php echo esc_url( $catalog_url ); ?>">&larr; <?php echo esc_html__( 'Preview more pieces', 'mrm-product-access' ); ?></a>
              </div>

            </div><!-- .meta -->

            <div class="mrm-pdfOverlay" aria-hidden="true">
              <div class="mrm-pdfModal" role="dialog" aria-label="PDF Preview">
                <div class="mrm-pdfScroll"></div>
              </div>
            </div>

          </article>
        </div>
        <?php

        $this->output_catalog_assets_inline();

        return ob_get_clean();
    }

    /**
     * Output inline assets for the catalog display.
     */
    private function output_catalog_assets_inline() {
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;

        ?>
        <style>
        :root {
          --color-bg: #f5f5f5;
          --color-surface: #ffffff;
          --color-accent: #2f2f2f;
          --color-accent-soft: rgba(0,0,0,0.08);
          --color-text-main: #111111;
          --color-text-muted: #666666;
          --color-border: #dddddd;

          --radius-lg: 16px;
          --radius-md: 12px;
          --radius-sm: 8px;
        }

        html, body { height: 100%; }

        body {
          margin: 0;
          background: var(--color-bg);
          font-family: Inter, system-ui, sans-serif;
          color: var(--color-text-main);
        }

        body.no-scroll {
          overflow: hidden;
          position: fixed;
          width: 100%;
          left: 0;
          right: 0;
        }

        .wrapper {
          max-width: 980px;
          margin: 0 auto;
          padding: 28px;
        }

        .product-card {
          background: var(--color-surface);
          border-radius: var(--radius-lg);
          padding: 22px;
          display: grid;
          grid-template-columns: 420px 1fr;
          gap: 24px;
          border: 1px solid var(--color-border);
          box-sizing: border-box;
          margin-bottom: 22px;
        }

        .pdf-col { display: flex; flex-direction: column; }

        .pdf-preview {
          width: 100%;
          height: 460px;
          border-radius: var(--radius-md);
          overflow: hidden;
          border: none;
          background: transparent;
          position: relative;
          cursor: zoom-in;
          user-select: none;
          -webkit-tap-highlight-color: transparent;
        }

        .pdf-preview canvas { width: 100%; height: 100%; display: block; }

        .mrm-pdfOverlay {
          position: fixed;
          inset: 0;
          width: 100vw;
          height: 100vh;
          background: rgba(0,0,0,0.001);
          display: none;
          align-items: center;
          justify-content: center;
          padding: 18px;
          box-sizing: border-box;
          z-index: 2147483647;
          cursor: zoom-out;
          overscroll-behavior: none;
          overflow: hidden;
        }
        .mrm-pdfOverlay.is-open { display: flex; }

        .mrm-pdfModal {
          width: min(980px, calc(100vw - 36px));
          height: min(92vh, 1200px);
          border-radius: var(--radius-md);
          overflow: hidden;
          background: var(--color-surface);
          border: none;
          box-shadow: 0 18px 60px rgba(0,0,0,0.35);
          display: flex;
          cursor: zoom-out;
        }

        .mrm-pdfScroll {
          width: 100%;
          height: 100%;
          overflow-y: auto;
          overflow-x: hidden;
          -webkit-overflow-scrolling: touch;
          padding: 18px;
          box-sizing: border-box;
        }

        .mrm-pdfPage {
          display: block;
          margin: 0 auto 18px;
          border: none;
          background: transparent;
          max-width: 100%;
          height: auto;
        }

        .meta .description { font-size: 15px; line-height: 1.6; color: var(--color-text-main); margin-bottom: 20px; }

        .title-block { text-align: left; }

        .piece-title { margin: 0; font-size: 22px; font-weight: 700; line-height: 2.15; }
        .piece-title a { color: var(--color-text-main); text-decoration: none; }
        .piece-title a:hover { opacity: 0.75; }

        .piece-composer { margin-top: 0px; margin-bottom: 3em; font-size: 15px; line-height: 1.2; font-style: italic; }
        .piece-composer a { color: var(--color-text-muted); text-decoration: none; }
        .piece-composer a:hover { text-decoration: none; opacity: 0.85; }
        .piece-composer a:focus { text-decoration: none; }

        .piece-subtitle { margin-top: 4px; font-size: 16px; font-weight: 600; color: var(--color-text-main); line-height: 1.2; }

        .meta .subtitle { font-size: 14px; color: var(--color-text-muted); margin-bottom: 8px; margin-top: 10px; }

        .audio-box {
          border: 1px solid var(--color-border);
          border-radius: var(--radius-md);
          padding: 12px;
          margin-bottom: 20px;
          background: var(--color-surface);
          box-sizing: border-box;
        }

        .audio-controls {
          display: grid;
          grid-template-columns: auto 1fr auto;
          align-items: center;
          gap: 12px;
        }

        .audio-row-top { display: flex; align-items: center; gap: 12px; }
        .audio-row-seek { min-width: 0; }
        .audio-row-vol { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }

        .play-button {
          width: 42px; height: 42px;
          border-radius: var(--radius-sm);
          background: var(--color-accent);
          color: var(--color-surface);
          border: none;
          cursor: pointer;
          padding: 0;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          line-height: 1;
          flex: 0 0 auto;
        }
        .play-button svg { width: 18px; height: 18px; display: block; fill: currentColor; }
        .play-button .icon-pause { display: none; }
        .play-button.is-playing .icon-play { display: none; }
        .play-button.is-playing .icon-pause { display: block; }

        .time { font-size: 13px; color: var(--color-text-muted); min-width: 52px; text-align: center; flex: 0 0 auto; }

        .progress {
          width: 100%;
          min-width: 0;
          appearance: none;
          height: 8px;
          border-radius: 999px;
          background: var(--color-accent-soft);
          cursor: pointer;
        }
        .progress::-webkit-slider-thumb {
          appearance: none;
          width: 14px; height: 14px;
          border-radius: 50%;
          background: var(--color-accent);
        }

        .volume { display: inline-flex; align-items: center; gap: 6px; color: var(--color-accent); }
        .volume svg { width: 18px; height: 18px; }
        .volume input { width: 110px; }

        .mrm-view-options-row{
          display:flex;
          justify-content:flex-end;
          margin-top: 10px;
        }

        .mrm-view-options-btn{
          display:inline-flex;
          align-items:center;
          justify-content:center;
          text-decoration:none;
          padding: 10px 14px;
          border-radius: var(--radius-md);
          border: 1px solid rgba(0,0,0,0.25);
          background: #000;
          color: #fff;
          font-weight: 800;
          font-size: 14px;
        }

        /* Theme-proof: never underline this button */
        .mrm-view-options-btn,
        .mrm-view-options-btn:visited,
        .mrm-view-options-btn:hover,
        .mrm-view-options-btn:focus,
        .mrm-view-options-btn:active{
          text-decoration: none !important;
        }
        .mrm-view-options-btn:hover{ opacity: 0.88; }

        .mrm-preview-more-row{
          display:flex;
          justify-content:flex-start;
          margin-top: 18px;
        }

        .actions { display: flex; gap: 12px; flex-wrap: wrap; justify-content: flex-end; }
        .button-primary, .button-secondary {
          padding: 12px 18px;
          border-radius: var(--radius-sm);
          font-weight: 600;
          text-decoration: none;
          border: 1px solid var(--color-accent);
          display: inline-flex;
          align-items: center;
          justify-content: center;
        }
        .button-primary { background: var(--color-accent); color: var(--color-surface); }
        .button-secondary { background: transparent; color: var(--color-accent); }

        @media (max-width: 860px) {
          .wrapper { padding: 18px; }

          .product-card {
            grid-template-columns: 1fr;
            padding: 18px;
          }

          .pdf-col { align-items: center; }
          .pdf-preview { width: min(720px, 100%); height: 360px; }
          .pdf-preview canvas { margin: 0 auto; }

          .title-block { text-align: center; }
          .actions { justify-content: center; }

          .audio-controls {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            justify-items: center;
          }

          .audio-row-top {
            width: 100%;
            display: grid;
            grid-template-columns: 42px 1fr 1fr;
            align-items: center;
            column-gap: 10px;
          }
          .audio-row-top .time { min-width: 0; text-align: center; }

          .audio-row-seek { width: 100%; }
          .audio-row-seek .progress { width: 100%; height: 10px; }

          .audio-row-vol { width: 100%; justify-content: center; }
          .volume input { width: min(260px, 72vw); }
        }

        .mrm-otpOverlay {
          position: fixed;
          inset: 0;
          display: none;
          align-items: center;
          justify-content: center;
          background: rgba(0,0,0,0.55);
          backdrop-filter: blur(6px);
          -webkit-backdrop-filter: blur(6px);
          z-index: 2147483646;
        }
        .mrm-otpOverlay.is-open { display: flex; }

        .mrm-otpOverlay .modal,
        .mrm-otpOverlay .modal * {
          color: #111111 !important;
        }

        /* Close button: force readable black text and an obvious button look */
        .mrm-otpOverlay .mrm-closeBtn{
          color: #000000 !important;
          background: transparent;
          border: 1px solid rgba(0,0,0,0.25);
          border-radius: 14px;
          padding: 12px 16px;
          font-weight: 700;
          cursor: pointer;
        }

        .mrm-otpOverlay .mrm-closeBtn:hover{
          background: rgba(0,0,0,0.06);
        }

        .mrm-otpOverlay .modal {
          width: min(840px, 94vw);
          padding: 0;
          border-radius: 22px;
          overflow: hidden;
          background: #ffffff;
          border: 1px solid rgba(0,0,0,0.10);
          box-shadow:
            0 26px 90px rgba(0,0,0,0.38),
            0 2px 16px rgba(0,0,0,0.22);
          transform: scale(1.35);
          transform-origin: center;
        }

        .mrm-otpOverlay .modal h2 {
          margin: 0;
          padding: 22px 24px;
          font-size: 22px;
          letter-spacing: 0.2px;
          display: flex;
          align-items: center;
          gap: 12px;
          background: linear-gradient(180deg, rgba(0,0,0,0.06), rgba(0,0,0,0.02));
          border-bottom: 1px solid rgba(0,0,0,0.10);
        }

        .mrm-otpOverlay .modal h2::before {
          content: "";
          width: 44px;
          height: 44px;
          border-radius: 14px;
          background: rgba(0,0,0,0.08);
          display: inline-block;
          flex: 0 0 auto;
          mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='black' d='M12 1a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-1V6a5 5 0 0 0-5-5Zm-3 8V6a3 3 0 0 1 6 0v3H9Zm3 4a2 2 0 0 1 1 3.732V18a1 1 0 0 1-2 0v-1.268A2 2 0 0 1 12 13Z'/%3E%3C/svg%3E") center / 22px 22px no-repeat;
          -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='black' d='M12 1a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-1V6a5 5 0 0 0-5-5Zm-3 8V6a3 3 0 0 1 6 0v3H9Zm3 4a2 2 0 0 1 1 3.732V18a1 1 0 0 1-2 0v-1.268A2 2 0 0 1 12 13Z'/%3E%3C/svg%3E") center / 22px 22px no-repeat;
          background-color: var(--color-accent);
        }

        .mrm-otpOverlay .mrm-stepEmail,
        .mrm-otpOverlay .mrm-stepOtp {
          padding: 22px 24px 0 24px;
        }

        .mrm-otpOverlay label {
          font-size: 16px;
          font-weight: 600;
          color: #111111 !important;
          margin-bottom: 10px;
        }

        .mrm-otpOverlay input[type="email"],
        .mrm-otpOverlay input[type="text"] {
          width: 100%;
          padding: 16px 16px;
          font-size: 18px;
          border-radius: 14px;
          border: 1px solid rgba(0,0,0,0.16);
          background: rgba(255,255,255,0.98);
          outline: none;
          margin-bottom: 14px;
          box-sizing: border-box;
          transition: box-shadow 160ms ease, border-color 160ms ease;
        }

        .mrm-otpOverlay input[type="email"]:focus,
        .mrm-otpOverlay input[type="text"]:focus {
          border-color: rgba(0,0,0,0.32);
          box-shadow: 0 0 0 6px rgba(0,0,0,0.10);
        }

        .mrm-otpOverlay .message {
          padding: 12px 24px 0 24px;
          min-height: 28px;
          font-size: 16px;
          color: #111111 !important;
          opacity: 0.85;
        }

        .mrm-otpOverlay .modal > div:last-child {
          padding: 18px 24px 24px 24px;
          display: flex;
          gap: 12px;
          justify-content: flex-end;
          border-top: 1px solid rgba(0,0,0,0.10);
          background: rgba(0,0,0,0.02);
        }

        .mrm-otpOverlay button.primary,
        .mrm-otpOverlay button.secondary {
          font-size: 18px;
          padding: 14px 18px;
          border-radius: 14px;
        }

        .mrm-otpOverlay button.primary {
          font-weight: 700;
          border: none;
          cursor: pointer;
          background: var(--color-accent);
          color: #ffffff !important;
          box-shadow: 0 10px 22px rgba(0,0,0,0.16);
          transition: transform 120ms ease, box-shadow 120ms ease;
        }
        .mrm-otpOverlay button.primary:hover {
          transform: translateY(-1px);
          box-shadow: 0 14px 30px rgba(0,0,0,0.22);
        }
        .mrm-otpOverlay button.primary:active {
          transform: translateY(0px);
          box-shadow: 0 10px 22px rgba(0,0,0,0.16);
        }

        .mrm-otpOverlay button.secondary {
          font-weight: 700;
          border: 1px solid var(--color-accent);
          background: transparent;
          cursor: pointer;
        }
        .mrm-otpOverlay button.secondary:hover {
          background: rgba(0,0,0,0.06);
        }

        .hidden { display:none; }

        @media (max-width: 900px) {
          .mrm-otpOverlay .modal { transform: scale(1.15); }
        }
        @media (max-width: 620px) {
          .mrm-otpOverlay .modal { transform: none; width: min(560px, 94vw); }
          .mrm-otpOverlay .modal > div:last-child { justify-content: stretch; }
          .mrm-otpOverlay button.primary,
          .mrm-otpOverlay button.secondary { width: 100%; }
        }
        </style>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
        <script>
        (function(){
          if (window.__MRM_CATALOG_INIT__) return;
          window.__MRM_CATALOG_INIT__ = true;

          if (window.pdfjsLib) {
            pdfjsLib.GlobalWorkerOptions.workerSrc =
              "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";
          }

          function fmtTime(t) {
            if (isNaN(t)) return "0:00";
            const m = Math.floor(t / 60);
            const s = Math.floor(t % 60);
            return `${m}:${String(s).padStart(2,'0')}`;
          }

          function setCanvasSize(canvas, cssW, cssH, dpr) {
            canvas.style.width = cssW + "px";
            canvas.style.height = cssH + "px";
            canvas.width = Math.floor(cssW * dpr);
            canvas.height = Math.floor(cssH * dpr);
          }

          async function initPiece(piece) {
            const PIECE_SLUG = piece.dataset.productSlug || piece.dataset.pieceSlug || "";
            const PDF_URL = piece.dataset.pdfUrl || "";
            const PREVIEW_PAGE = Number(piece.dataset.previewPage || "1");

            const apiBase = (piece.dataset.apiBase || "/wp-json/mrm/v1").trim().startsWith("http")
              ? piece.dataset.apiBase.trim()
              : (window.location.origin + (piece.dataset.apiBase || "/wp-json/mrm/v1"));

            const previewWrap = piece.querySelector('.mrm-pdfPreview');
            const previewCanvas = piece.querySelector('.mrm-pdfCanvas');
            const overlay = piece.querySelector('.mrm-pdfOverlay');
            const pdfScroll = piece.querySelector('.mrm-pdfScroll');

            let pdfDoc = null;
            let overlayOpen = false;
            let overlayRendered = false;
            let resizeTimer = null;
            let scrollY = 0;

            async function renderSinglePageToCanvas(canvas, containerEl, pageNum) {
              if (!pdfDoc) return;
              const page = await pdfDoc.getPage(pageNum);
              const unscaled = page.getViewport({ scale: 1 });

              const cssW = containerEl.clientWidth;
              const cssH = containerEl.clientHeight;

              const scale = Math.min(cssW / unscaled.width, cssH / unscaled.height);
              const viewport = page.getViewport({ scale });
              const dpr = Math.max(1, window.devicePixelRatio || 1);

              setCanvasSize(canvas, Math.floor(viewport.width), Math.floor(viewport.height), dpr);
              const ctx = canvas.getContext('2d', { alpha: false });
              ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
              ctx.imageSmoothingEnabled = true;
              ctx.clearRect(0, 0, canvas.width, canvas.height);
              await page.render({ canvasContext: ctx, viewport }).promise;
            }

            async function renderPreview() {
              await renderSinglePageToCanvas(previewCanvas, previewWrap, PREVIEW_PAGE);
            }

            async function renderOverlayAllPages() {
              if (!pdfDoc || overlayRendered) return;
              pdfScroll.innerHTML = "";
              await new Promise(r => requestAnimationFrame(r));

              const containerWidth = pdfScroll.clientWidth;
              const dpr = Math.max(1, window.devicePixelRatio || 1);

              for (let i = 1; i <= pdfDoc.numPages; i++) {
                const page = await pdfDoc.getPage(i);
                const unscaled = page.getViewport({ scale: 1 });

                const scale = containerWidth / unscaled.width;
                const viewport = page.getViewport({ scale });

                const canvas = document.createElement('canvas');
                canvas.className = "mrm-pdfPage";
                canvas.style.width = Math.floor(viewport.width) + "px";
                canvas.style.height = Math.floor(viewport.height) + "px";
                canvas.width = Math.floor(viewport.width * dpr);
                canvas.height = Math.floor(viewport.height * dpr);

                const ctx = canvas.getContext('2d', { alpha: false });
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
                ctx.imageSmoothingEnabled = true;

                pdfScroll.appendChild(canvas);
                await page.render({ canvasContext: ctx, viewport }).promise;
              }

              overlayRendered = true;
            }

            function openOverlay() {
              overlayOpen = true;
              overlayRendered = false;

              scrollY = window.scrollY || window.pageYOffset || 0;
              document.body.style.top = `-${scrollY}px`;
              document.body.classList.add('no-scroll');

              overlay.classList.add('is-open');
              overlay.setAttribute('aria-hidden', 'false');

              renderOverlayAllPages();
            }

            function closeOverlay() {
              overlayOpen = false;

              overlay.classList.remove('is-open');
              overlay.setAttribute('aria-hidden', 'true');

              document.body.classList.remove('no-scroll');
              const top = document.body.style.top;
              document.body.style.top = "";
              const restoreY = top ? -parseInt(top, 10) : scrollY;
              window.scrollTo(0, restoreY);

              pdfScroll.scrollTop = 0;
            }

            function toggleOverlay() {
              overlayOpen ? closeOverlay() : openOverlay();
            }

            function debounceRerender() {
              clearTimeout(resizeTimer);
              resizeTimer = setTimeout(() => {
                renderPreview();
                if (overlayOpen) { overlayRendered = false; renderOverlayAllPages(); }
              }, 150);
            }

            previewWrap.addEventListener('click', toggleOverlay);
            previewWrap.addEventListener('keydown', (e) => {
              if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleOverlay(); }
            });

            // Close PDF overlay when clicking anywhere (including the PDF itself)
            overlay.addEventListener('click', () => closeOverlay());

            document.addEventListener('keydown', (e) => {
              if (e.key === 'Escape' && overlayOpen) closeOverlay();
            });
            window.addEventListener('resize', debounceRerender);

            (async function initPdf(){
              try {
                if (!PDF_URL) throw new Error("Missing data-pdf-url");
                pdfDoc = await pdfjsLib.getDocument({ url: PDF_URL }).promise;
                await renderPreview();
              } catch (err) {
                previewWrap.style.cursor = 'default';
                previewWrap.innerHTML =
                  '<div style="padding:12px;font-size:13px;color:#666;">PDF preview failed to load.</div>';
                console.error(err);
              }
            })();

            const audio = piece.querySelector('.mrm-audio');
            const play = piece.querySelector('.mrm-play');
            const seek = piece.querySelector('.mrm-seek');
            const cur = piece.querySelector('.mrm-current');
            const dur = piece.querySelector('.mrm-duration');
            const vol = piece.querySelector('.mrm-volume');

            function syncPlayUI() {
              const playing = audio && !audio.paused && !audio.ended;
              play.classList.toggle('is-playing', !!playing);
            }

            if (audio) {
              audio.addEventListener('loadedmetadata', () => {
                dur.textContent = fmtTime(audio.duration);
                seek.max = audio.duration || 0;
              });

              audio.addEventListener('timeupdate', () => {
                cur.textContent = fmtTime(audio.currentTime);
                if (!seek.matches(':active')) seek.value = audio.currentTime || 0;
              });

              play.addEventListener('click', async () => {
                try {
                  if (audio.paused) await audio.play();
                  else audio.pause();
                  syncPlayUI();
                } catch (e) { console.error(e); }
              });

              audio.addEventListener('play', syncPlayUI);
              audio.addEventListener('pause', syncPlayUI);
              audio.addEventListener('ended', syncPlayUI);

              seek.addEventListener('input', () => { audio.currentTime = Number(seek.value || 0); });
              vol.addEventListener('input', () => { audio.volume = Number(vol.value); });
            }

            const otpOverlay = piece.querySelector('.mrm-otpOverlay');
            const stepEmail = piece.querySelector('.mrm-stepEmail');
            const stepOtp = piece.querySelector('.mrm-stepOtp');
            const emailInput = piece.querySelector('.mrm-email');
            const otpInput = piece.querySelector('.mrm-otp');
            const sendBtn = piece.querySelector('.mrm-sendCodeBtn');
            const verifyBtn = piece.querySelector('.mrm-verifyBtn');
            const messageDiv = piece.querySelector('.mrm-message');
            const closeBtn = piece.querySelector('.mrm-closeBtn');

            // Force a visible, consistent close label (base behavior)
            if (closeBtn) {
              closeBtn.textContent = 'Close';
              closeBtn.setAttribute('type', 'button');
              closeBtn.setAttribute('aria-label', 'Close');
            }

            function openOtpModal(){
              otpOverlay.classList.add('is-open');
              otpOverlay.setAttribute('aria-hidden', 'false');

              const y = window.scrollY || window.pageYOffset || 0;
              document.body.style.top = `-${y}px`;
              document.body.classList.add('no-scroll');

              stepEmail.classList.remove('hidden');
              stepOtp.classList.add('hidden');
              messageDiv.textContent = '';
              emailInput.value = '';
              otpInput.value = '';
            }
            function closeOtpModal(){
              otpOverlay.classList.remove('is-open');
              otpOverlay.setAttribute('aria-hidden', 'true');

              document.body.classList.remove('no-scroll');
              const top = document.body.style.top;
              document.body.style.top = "";
              const restoreY = top ? -parseInt(top, 10) : 0;
              window.scrollTo(0, restoreY);
            }

            closeBtn.addEventListener('click', closeOtpModal);
            otpOverlay.addEventListener('click', (e) => { if (e.target === otpOverlay) closeOtpModal(); });

            sendBtn.addEventListener('click', function(){
              const email = (emailInput.value || '').trim();
              if(!email){ messageDiv.textContent='Please enter your email.'; return; }
              messageDiv.textContent='Sending code...';

              fetch(apiBase + '/request-otp', {
                method:'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ email: email, piece_slug: PIECE_SLUG }),
                credentials: 'same-origin'
              })
              .then(async (r) => {
                const txt = await r.text();
                let data = null;
                try { data = JSON.parse(txt); } catch(e) { data = null; }
                if (!r.ok) {
                  console.log('RAW RESPONSE (request-otp):', PIECE_SLUG, r.status, txt);
                  messageDiv.textContent='Server error. Check Console.';
                  return null;
                }
                return data || {};
              })
              .then(data=>{
                if(!data) return;
                messageDiv.textContent = data.message || 'If access exists, a code will be sent shortly.';
                stepEmail.classList.add('hidden');
                stepOtp.classList.remove('hidden');
              })
              .catch(err=>{ console.error(err); messageDiv.textContent='Error sending code.'; });
            });

            verifyBtn.addEventListener('click', function(){
              const email = (emailInput.value || '').trim();
              const otp = (otpInput.value || '').trim();
              if(!otp){ messageDiv.textContent='Enter the code you received.'; return; }
              messageDiv.textContent='Verifying...';

              fetch(apiBase + '/verify-otp', {
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body: JSON.stringify({ email: email, piece_slug: PIECE_SLUG, otp: otp }),
                credentials: 'same-origin'
              })
              .then(async (r) => {
                const txt = await r.text();
                let data = null;
                try { data = JSON.parse(txt); } catch(e) { data = null; }
                if (!r.ok) {
                  messageDiv.textContent=(data && data.message) ? data.message : 'Server error. Check Console.';
                  return null;
                }
                return data || {};
              })
              .then(data=>{
                if(!data) return;
                messageDiv.textContent = data.message || 'Verified.';
              })
              .catch(err=>{ console.error(err); messageDiv.textContent='Error verifying code.'; });
            });
          }

          document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.mrm-piece').forEach((piece) => initPiece(piece));
          });
        })();
        </script>
        <?php
    }

    /**
     * Download assets (PDF, audio or zip).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|void
     */
    public function api_download( $request ) {
        $product_slug = isset( $_GET['product_slug'] ) ? sanitize_title( $_GET['product_slug'] ) : '';
        $asset_type   = isset( $_GET['asset_type'] ) ? sanitize_key( $_GET['asset_type'] ) : '';
        $track        = isset( $_GET['track'] ) ? sanitize_key( $_GET['track'] ) : '';
        $inline       = ! empty( $_GET['inline'] );
        $force_dl     = ! empty( $_GET['download'] );

        if ( empty( $product_slug ) || empty( $asset_type ) ) {
            return new WP_REST_Response( array( 'error' => 'Invalid request.' ), 400 );
        }

        $cookie_name = 'mrm_auth_' . $product_slug;
        if ( empty( $_COOKIE[ $cookie_name ] ) ) {
            return new WP_REST_Response( array( 'error' => 'Unauthorized.' ), 403 );
        }

        $options     = $this->get_options();
        $auth_secret = $options['auth_secret'] ?? '';
        if ( empty( $auth_secret ) ) {
            return new WP_REST_Response( array( 'error' => 'Unauthorized.' ), 403 );
        }

        $value = $_COOKIE[ $cookie_name ];
        $parts = explode( '.', $value );
        if ( count( $parts ) !== 2 ) {
            return new WP_REST_Response( array( 'error' => 'Unauthorized.' ), 403 );
        }
        list( $token, $sig ) = $parts;
        $expected_sig = hash_hmac( 'sha256', $token, $auth_secret );
        if ( ! hash_equals( $expected_sig, $sig ) ) {
            return new WP_REST_Response( array( 'error' => 'Unauthorized.' ), 403 );
        }

        $payload = json_decode( base64_decode( $token ), true );
        if ( empty( $payload ) ) {
            return new WP_REST_Response( array( 'error' => 'Unauthorized.' ), 403 );
        }
        if ( time() > intval( $payload['exp'] ?? 0 ) ) {
            return new WP_REST_Response( array( 'error' => 'Authorization expired.' ), 403 );
        }
        $email_hash = $payload['email'] ?? '';
        if ( ( $payload['product_slug'] ?? '' ) !== $product_slug ) {
            return new WP_REST_Response( array( 'error' => 'Unauthorized.' ), 403 );
        }

        global $wpdb;
        $table    = $wpdb->prefix . 'mrm_purchases';
        $purchase = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM $table WHERE product_slug = %s AND email_hash = %s AND status = 'paid'",
            $product_slug,
            $email_hash
        ) );

        if ( ! $purchase ) {
            $approved = $this->is_email_hash_approved_for_product( $product_slug, (string) $email_hash );
            $verified = $this->is_email_hash_globally_verified( (string) $email_hash );
            if ( ! $approved && ! $verified ) {
                return new WP_REST_Response( array( 'error' => 'Unauthorized.' ), 403 );
            }
        }

        $config = $this->get_product_config( $product_slug );
        if ( empty( $config ) ) {
            return new WP_REST_Response( array( 'error' => 'File not found.' ), 404 );
        }

        $file = '';
        // Resolve file path based on track/index or asset type
        if ( $track !== '' ) {
            if ( ! empty( $config['tracks'] ) && is_array( $config['tracks'] ) ) {
                // New format: sequential array of track items (numeric index)
                if ( array_keys( $config['tracks'] ) === range( 0, count( $config['tracks'] ) - 1 ) && ctype_digit( (string) $track ) ) {
                    $i = intval( $track );
                    if ( isset( $config['tracks'][ $i ] ) && is_array( $config['tracks'][ $i ] ) && ! empty( $config['tracks'][ $i ]['path'] ) ) {
                        $file = $config['tracks'][ $i ]['path'];
                    }
                } else {
                    // Legacy associative map: use key lookup
                    if ( isset( $config['tracks'][ $track ] ) ) {
                        $file = $config['tracks'][ $track ];
                    }
                }
            }
        }
        // Fallback to legacy single PDF/audio/zip paths when no track specified or not found
        if ( empty( $file ) ) {
            switch ( $asset_type ) {
                case 'pdf':
                    $file = $config['pdf_path'] ?? '';
                    break;
                case 'audio':
                    $file = $config['audio_path'] ?? '';
                    break;
                case 'zip':
                    $file = $config['zip_path'] ?? '';
                    break;
            }
        }

        if ( empty( $file ) || ! file_exists( $file ) ) {
            return new WP_REST_Response( array( 'error' => 'File not found.' ), 404 );
        }

        // Determine MIME type.
        $mime      = wp_check_filetype( $file );
        $mime_type = ! empty( $mime['type'] ) ? $mime['type'] : '';
        if ( empty( $mime_type ) && function_exists( 'mime_content_type' ) ) {
            $detected = @mime_content_type( $file );
            if ( ! empty( $detected ) ) {
                $mime_type = $detected;
            }
        }
        if ( empty( $mime_type ) ) {
            $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
            switch ( $ext ) {
                case 'mp3':
                case 'm4a':
                case 'aac':
                    $mime_type = 'audio/mpeg';
                    break;
                case 'wav':
                case 'wave':
                    $mime_type = 'audio/wav';
                    break;
                case 'ogg':
                case 'oga':
                    $mime_type = 'audio/ogg';
                    break;
                case 'flac':
                    $mime_type = 'audio/flac';
                    break;
                default:
                    $mime_type = 'application/octet-stream';
            }
        }

        // Prevent timeouts on large files.
        @set_time_limit( 0 );
        @ignore_user_abort( true );

        // Disable compression to avoid corrupting binary output.
        if ( function_exists( 'apache_setenv' ) ) {
            @apache_setenv( 'no-gzip', '1' );
        }
        @ini_set( 'zlib.output_compression', 'Off' );

        // Clear all buffers.
        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }

        // Headers.
        header( 'Content-Type: ' . $mime_type );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        header( 'Accept-Ranges: bytes' );

        $filename    = basename( $file );
        $disposition = ( $inline && ! $force_dl ) ? 'inline' : 'attachment';
        header( 'Content-Disposition: ' . $disposition . '; filename="' . $filename . '"' );

        $size  = filesize( $file );
        $start = 0;
        $end   = $size - 1;

        $fp = fopen( $file, 'rb' );
        if ( $fp === false ) {
            return new WP_REST_Response( array( 'error' => 'File not readable.' ), 500 );
        }

        // Range support for audio seeking.
        if ( ! empty( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=\s*(\d*)-(\d*)/i', $_SERVER['HTTP_RANGE'], $m ) ) {
            if ( $m[1] !== '' ) {
                $start = (int) $m[1];
            }
            if ( $m[2] !== '' ) {
                $end = (int) $m[2];
            }
            if ( $start > $end || $start >= $size ) {
                fclose( $fp );
                header( 'Content-Range: bytes */' . $size );
                status_header( 416 );
                exit;
            }
            if ( $end >= $size ) {
                $end = $size - 1;
            }

            $length = ( $end - $start ) + 1;
            status_header( 206 );
            header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
            header( 'Content-Length: ' . $length );
            fseek( $fp, $start );
        } else {
            header( 'Content-Length: ' . $size );
        }

        $chunk = 1024 * 1024; // 1MB
        while ( ! feof( $fp ) ) {
            $pos = ftell( $fp );
            if ( $pos === false ) {
                break;
            }
            if ( $pos > $end ) {
                break;
            }
            $bytes_to_read = $chunk;
            $remaining     = ( $end - $pos ) + 1;
            if ( $remaining < $bytes_to_read ) {
                $bytes_to_read = $remaining;
            }
            $buffer = fread( $fp, $bytes_to_read );
            if ( $buffer === false ) {
                break;
            }
            echo $buffer;
            @flush();
        }

        fclose( $fp );
        exit;
    }
}

MRM_Product_Access::get_instance();
