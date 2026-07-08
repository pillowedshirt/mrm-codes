<?php
/*
Plugin Name: MRM Product Access
Description: Provides purchase and access management for single-product pages using Stripe Checkout and Stripe Connect. Handles checkout session creation, webhook processing, OTP issuance and secure downloads without requiring user accounts.
Author: Your Name
Version: 1.2.7
*/

if ( ! defined( 'MRM_LAUNCH_DEBUG' ) ) {
    define( 'MRM_LAUNCH_DEBUG', false );
}

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
    const VERSION = '1.2.7';

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
        add_action( 'mrm_send_cross_plugin_email_test', array( $this, 'handle_cross_plugin_email_test' ), 10, 3 );
        add_filter( 'mrm_cross_plugin_email_preview', array( $this, 'handle_cross_plugin_email_preview' ), 10, 2 );
        add_action( 'admin_notices', array( $this, 'admin_quality_checks' ) );
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

        // Pretty access URL support (best effort).
        add_action( 'init', array( $this, 'register_access_rewrite' ) );
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

        // Minimal robustness: run access-page renderer on BOTH hooks.
        add_action( 'wp', array( $this, 'maybe_render_access_page' ), 0 );
        add_action( 'template_redirect', array( $this, 'maybe_render_access_page' ), 0 );

        add_shortcode( 'mrm_sheet_music_catalog', array( $this, 'shortcode_sheet_music_catalog' ) );
        add_shortcode( 'mrm_sheet_music_timeline', array( $this, 'shortcode_sheet_music_timeline' ) );
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
     * Return a stored/post value as a clean string without WordPress magic slashes.
     */
    private function mrm_pa_unslash_value( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        return wp_unslash( (string) $value );
    }

    /**
     * Plain one-line text: titles, display titles, composer, difficulty, price labels.
     */
    private function mrm_pa_plain_text( $value ) {
        return sanitize_text_field( $this->mrm_pa_unslash_value( $value ) );
    }

    /**
     * Allowed rich text for admin textareas.
     * This preserves blank lines plus basic formatting only.
     */
    private function mrm_pa_allowed_rich_text_tags() {
        return array(
            'p'      => array(),
            'br'     => array(),
            'strong' => array(),
            'b'      => array(),
            'em'     => array(),
            'i'      => array(),
            'u'      => array(),
            'a'      => array(
                'href'   => true,
                'title'  => true,
                'target' => true,
                'rel'    => true,
            ),
        );
    }

    /**
     * Rich multiline text: subtitles, short descriptions, long descriptions.
     */
    private function mrm_pa_rich_text( $value ) {
        $value = $this->mrm_pa_unslash_value( $value );
        $value = str_replace( array( "\r\n", "\r" ), "\n", $value );

        return wp_kses( $value, $this->mrm_pa_allowed_rich_text_tags() );
    }

    /**
     * Normalize a piece before sending it to piece-product.html.
     * This also cleans legacy values that were previously saved with slashes.
     */
    private function mrm_pa_normalize_piece_for_output( $piece ) {
        if ( ! is_array( $piece ) ) {
            return array();
        }

        $piece['piece_title']       = $this->mrm_pa_plain_text( $piece['piece_title'] ?? ( $piece['title'] ?? '' ) );
        $piece['title']             = $piece['piece_title'];
        $piece['composer_name']     = $this->mrm_pa_plain_text( $piece['composer_name'] ?? ( $piece['composer'] ?? '' ) );
        $piece['composer']          = $piece['composer_name'];
        $piece['short_description'] = $this->mrm_pa_rich_text( $piece['short_description'] ?? '' );
        $piece['long_description']  = $this->mrm_pa_rich_text( $piece['long_description'] ?? ( $piece['description'] ?? '' ) );
        $piece['description']       = $piece['long_description'];
        $piece['difficulty']        = $this->mrm_pa_plain_text( $piece['difficulty'] ?? '' );
        $piece['instrumentation']   = $this->mrm_pa_plain_text( $piece['instrumentation'] ?? '' );
        $piece['duration']          = $this->mrm_pa_plain_text( $piece['duration'] ?? '' );
        $piece['year']              = $this->mrm_pa_plain_text( $piece['year'] ?? '' );

        if ( ! empty( $piece['offers'] ) && is_array( $piece['offers'] ) ) {
            foreach ( $piece['offers'] as $offer_i => $offer ) {
                if ( ! is_array( $offer ) ) {
                    $piece['offers'][ $offer_i ] = array();
                    continue;
                }

                $offer['product_slug']      = $this->sanitize_product_slug( (string) ( $offer['product_slug'] ?? '' ) );
                $offer['display_title']     = $this->mrm_pa_plain_text( $offer['display_title'] ?? '' );
                $offer['subtitle']          = $this->mrm_pa_rich_text( $offer['subtitle'] ?? '' );
                $offer['price_display']     = $this->mrm_pa_plain_text( $offer['price_display'] ?? '' );
                $offer['preview_audio_url'] = trim( $this->mrm_pa_unslash_value( $offer['preview_audio_url'] ?? '' ) );

                $piece['offers'][ $offer_i ] = $offer;
            }
        }

        return $piece;
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

        register_rest_route( 'mrm-pa/v1', '/access-context', array(
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'rest_access_context' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Add menu entry under Settings for plugin configuration.
     */
    public function admin_menu() {
        add_menu_page(
            __( 'Product Access', 'mrm-product-access' ),
            __( 'Product Access', 'mrm-product-access' ),
            'manage_options',
            'mrm-pa-settings',
            array( $this, 'render_settings_page' ),
            'dashicons-lock',
            59
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

        // Migration: old option -> new option
        $legacy = get_option( 'mrm_pa_product_tracks_by_slug', null );
        $current = get_option( 'product_tracks_by_slug', null );
        if ( is_array( $legacy ) && ! is_array( $current ) ) {
            update_option( 'product_tracks_by_slug', $legacy );
        }

        // One-time migration: legacy options['products'][slug]['tracks'] -> mrm_pa_product_tracks_by_slug
        $existing_map = get_option( 'mrm_pa_product_tracks_by_slug', null );
        if ( $existing_map === null ) {
            $migrated = array();
            if ( ! empty( $options['products'] ) && is_array( $options['products'] ) ) {
                foreach ( $options['products'] as $slug => $conf ) {
                    $slug = $this->sanitize_product_slug( $slug );
                    if ( $slug === '' ) {
                        continue;
                    }

                    $items = array();

                    // Legacy "tracks" could be sequential items or associative map
                    if ( ! empty( $conf['tracks'] ) && is_array( $conf['tracks'] ) ) {
                        if ( array_keys( $conf['tracks'] ) === range( 0, count( $conf['tracks'] ) - 1 ) ) {
                            foreach ( $conf['tracks'] as $it ) {
                                if ( ! is_array( $it ) ) {
                                    continue;
                                }
                                $title = sanitize_text_field( (string) ( $it['title'] ?? '' ) );
                                $path  = sanitize_text_field( (string) ( $it['path'] ?? '' ) );
                                if ( $path !== '' ) {
                                    $items[] = array(
                                        'name' => $title !== '' ? $title : 'Track',
                                        'url'  => $path,
                                    );
                                }
                                if ( count( $items ) >= 25 ) {
                                    break;
                                }
                            }
                        } else {
                            foreach ( $conf['tracks'] as $k => $v ) {
                                $k = sanitize_text_field( (string) $k );
                                $v = sanitize_text_field( (string) $v );
                                if ( $v !== '' ) {
                                    $items[] = array(
                                        'name' => $k !== '' ? $k : 'Track',
                                        'url'  => $v,
                                    );
                                }
                                if ( count( $items ) >= 25 ) {
                                    break;
                                }
                            }
                        }
                    }

                    if ( ! empty( $items ) ) {
                        $migrated[ $slug ] = $items;
                    }
                }
            }
            add_option( 'mrm_pa_product_tracks_by_slug', $migrated );
        }

        if ( isset( $_POST['mrm_pa_save_settings'] ) && check_admin_referer( 'mrm_pa_save_settings' ) ) {
            // Email template settings are no longer edited from Product Access.
            // Preserve existing values for backward compatibility with any legacy fallback code.
            $options['email_subject'] = isset( $options['email_subject'] ) ? (string) $options['email_subject'] : 'Your access code';
            $options['email_body']    = isset( $options['email_body'] ) ? (string) $options['email_body'] : "Your one-time code is: {{OTP}}\n\nIf you did not request this, ignore this email.";

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
                $timeline_levels     = isset( $_POST['piece_timeline_level'] ) ? (array) $_POST['piece_timeline_level'] : array();
                $timeline_orders     = isset( $_POST['piece_timeline_order'] ) ? (array) $_POST['piece_timeline_order'] : array();

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

                    $pslug = $this->sanitize_product_slug( (string) ( $offer_product_slug[ $oi ] ?? '' ) );
                    $dt    = $this->mrm_pa_plain_text( $offer_display_title[ $oi ] ?? '' );
                    $sub   = $this->mrm_pa_rich_text( $offer_subtitle[ $oi ] ?? '' );
                    $price = $this->mrm_pa_plain_text( $offer_price_display[ $oi ] ?? '' );
                    $aud   = trim( $this->mrm_pa_unslash_value( $offer_preview_audio_url[ $oi ] ?? '' ) );

                    // Require at least a product slug or display title to keep an offer row.
                    if ( $pslug === '' && $dt === '' ) {
                        continue;
                    }

                    // IMPORTANT: Do NOT auto-derive offer product_slug from display title.
                    // Payments Hub sheet-music SKUs follow a strict canonical format (piece-<title>-<type>[optional-suffix]).
                    // Deriving from display title can create mismatches that break quoting/access grants.
                    // Leave blank so the frontend/admin clearly indicates a configuration error and the slug can be corrected intentionally.
                    if ( $pslug === '' && $dt !== '' ) {
                        $pslug = '';
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
                    $title = $this->mrm_pa_plain_text( $titles[ $i ] ?? '' );

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
                    $piece['composer_name']        = $this->mrm_pa_plain_text( $composer_names[ $i ] ?? '' );
                    $piece['composer_url']         = esc_url_raw( trim( $this->mrm_pa_unslash_value( $composer_urls[ $i ] ?? '' ) ) );
                    $legacy_desc = $this->mrm_pa_rich_text( $descs[ $i ] ?? '' );

                    $piece['short_description'] = $this->mrm_pa_rich_text( $short_descs[ $i ] ?? '' );

                    $new_long = $this->mrm_pa_rich_text( $long_descs[ $i ] ?? '' );
                    $piece['long_description']  = ( $new_long !== '' ) ? $new_long : $legacy_desc;

                    $piece['description'] = $piece['long_description'];
                    $piece['difficulty']           = $this->mrm_pa_plain_text( $difficulty[ $i ] ?? '' );
                    $piece['instrumentation']      = $this->mrm_pa_plain_text( $instrumentation[ $i ] ?? '' );
                    $piece['duration']             = $this->mrm_pa_plain_text( $duration[ $i ] ?? '' );
                    $piece['year']                 = $this->mrm_pa_plain_text( $year[ $i ] ?? '' );
                    $piece['main_preview_pdf_url'] = trim( $this->mrm_pa_unslash_value( $pdf_urls[ $i ] ?? '' ) );

                    $preview_audio = trim( $this->mrm_pa_unslash_value( $preview_audio_urls[ $i ] ?? '' ) );

                    $ppn = intval( $preview_pages[ $i ] ?? 1 );
                    if ( $ppn <= 0 ) {
                        $ppn = 1;
                    }
                    $piece['preview_page_number'] = $ppn;
                    $piece['preview_audio_url']  = $preview_audio;

                    $timeline_level = sanitize_key( (string) ( $timeline_levels[ $i ] ?? '' ) );
                    if ( ! in_array( $timeline_level, array( 'level_1', 'level_2', 'level_3', 'hidden' ), true ) ) {
                        $timeline_level = 'hidden';
                    }

                    $timeline_order = intval( $timeline_orders[ $i ] ?? 0 );
                    if ( $timeline_order < 0 ) {
                        $timeline_order = 0;
                    }

                    $piece['timeline_level'] = $timeline_level;
                    $piece['timeline_order'] = $timeline_order;

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
            // Save Product Pages to a single option: product_tracks_by_slug.
            // IMPORTANT:
            // Keep this option name and row shape unchanged so existing entered track data persists.
            // Rows are saved in the same order they appear in the admin table.
            if (
                isset( $_POST['tracks_map_slug'] ) && is_array( $_POST['tracks_map_slug'] )
                || isset( $_POST['tracks_map_name'] ) && is_array( $_POST['tracks_map_name'] )
                || isset( $_POST['tracks_map_url'] ) && is_array( $_POST['tracks_map_url'] )
            ) {
                $rows  = array();
                $slugs = isset( $_POST['tracks_map_slug'] ) && is_array( $_POST['tracks_map_slug'] ) ? (array) $_POST['tracks_map_slug'] : array();
                $names = isset( $_POST['tracks_map_name'] ) && is_array( $_POST['tracks_map_name'] ) ? (array) $_POST['tracks_map_name'] : array();
                $urls  = isset( $_POST['tracks_map_url'] ) && is_array( $_POST['tracks_map_url'] ) ? (array) $_POST['tracks_map_url'] : array();

                $row_count = max( count( $slugs ), count( $names ), count( $urls ) );

                for ( $i = 0; $i < $row_count; $i++ ) {
                    $raw_slug = isset( $slugs[ $i ] ) ? (string) $slugs[ $i ] : '';
                    $slug     = strtolower( trim( $raw_slug ) );
                    $slug     = preg_replace( '/[^a-z0-9\-_]+/', '', $slug );

                    $name    = isset( $names[ $i ] ) ? sanitize_text_field( (string) $names[ $i ] ) : '';
                    $raw_url = isset( $urls[ $i ] ) ? (string) $urls[ $i ] : '';
                    $url     = $this->sanitize_track_location( $raw_url );

                    // Allow empty rows; store as-is. Runtime already ignores invalid rows.
                    $rows[] = array(
                        'product_slug' => $slug,
                        'display_name' => $name,
                        'url'          => $url,
                    );
                }

                update_option( 'product_tracks_by_slug', $rows );
            }

            // Keep legacy options['products'] around for back-compat only (do not update it here).
            // $options['products'] no longer drives access, pricing, or gating.

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
            <h1><?php esc_html_e( 'Product Access', 'mrm-product-access' ); ?></h1>
            <form method="post">
                <?php wp_nonce_field( 'mrm_pa_save_settings' ); ?>

                <h2 class="title"><?php esc_html_e( 'Timeline Display Settings', 'mrm-product-access' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Drag pieces between levels and reorder pieces within each level. This controls [mrm_sheet_music_timeline] only and does not change the catalog display order.', 'mrm-product-access' ); ?>
                </p>
                <p class="description">
                    <?php esc_html_e( 'The timeline and catalog are independent systems that reference the same piece titles. Use the Catalog Display section below to control catalog order.', 'mrm-product-access' ); ?>
                </p>

                <div class="mrm-pa-timeline-panel" id="mrm-pa-timeline-panel">
                    <div class="mrm-pa-timeline-panel-head">
                        <h3><?php esc_html_e( 'Timeline Piece Order', 'mrm-product-access' ); ?></h3>
                        <code>[mrm_sheet_music_timeline]</code>
                    </div>

                    <p class="description">
                        <?php esc_html_e( 'Drag each piece into Level 1, Level 2, or Level 3. Pieces in Hidden from Timeline will not appear on the timeline shortcode.', 'mrm-product-access' ); ?>
                    </p>

                    <div class="mrm-pa-timeline-board" id="mrm-pa-timeline-board" aria-label="<?php esc_attr_e( 'Timeline display settings', 'mrm-product-access' ); ?>">
                        <div class="mrm-pa-timeline-column" data-mrm-timeline-level="level_1">
                            <div class="mrm-pa-timeline-column-head">
                                <strong><?php esc_html_e( 'Level 1', 'mrm-product-access' ); ?></strong>
                            </div>
                            <ol class="mrm-pa-timeline-list" aria-label="<?php esc_attr_e( 'Level 1 timeline pieces', 'mrm-product-access' ); ?>"></ol>
                        </div>

                        <div class="mrm-pa-timeline-column" data-mrm-timeline-level="level_2">
                            <div class="mrm-pa-timeline-column-head">
                                <strong><?php esc_html_e( 'Level 2', 'mrm-product-access' ); ?></strong>
                            </div>
                            <ol class="mrm-pa-timeline-list" aria-label="<?php esc_attr_e( 'Level 2 timeline pieces', 'mrm-product-access' ); ?>"></ol>
                        </div>

                        <div class="mrm-pa-timeline-column" data-mrm-timeline-level="level_3">
                            <div class="mrm-pa-timeline-column-head">
                                <strong><?php esc_html_e( 'Level 3', 'mrm-product-access' ); ?></strong>
                            </div>
                            <ol class="mrm-pa-timeline-list" aria-label="<?php esc_attr_e( 'Level 3 timeline pieces', 'mrm-product-access' ); ?>"></ol>
                        </div>

                        <div class="mrm-pa-timeline-column mrm-pa-timeline-column-hidden" data-mrm-timeline-level="hidden">
                            <div class="mrm-pa-timeline-column-head">
                                <strong><?php esc_html_e( 'Hidden from Timeline', 'mrm-product-access' ); ?></strong>
                            </div>
                            <ol class="mrm-pa-timeline-list" aria-label="<?php esc_attr_e( 'Hidden timeline pieces', 'mrm-product-access' ); ?>"></ol>
                        </div>
                    </div>

                    <p class="mrm-pa-help">
                        <?php esc_html_e( 'Timeline order is saved independently from the catalog order. Save settings after dragging pieces into place.', 'mrm-product-access' ); ?>
                    </p>
                </div>

                <?php
                // Verified Emails section removed per user request. Keeping the underlying
                // verified_emails option in place but no longer rendering inputs.
                ?>

                <h2 class="title"><?php esc_html_e( 'Catalog Display', 'mrm-product-access' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Enter the piece fields + purchasing options (offers) exactly like the old product HTML. URLs can be full URLs or site-relative paths (e.g. /wp-content/uploads/...). Use the Piece Display Order panel below to control the order used by the sheet music catalog shortcode.', 'mrm-product-access' ); ?>
                </p>
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
                .mrm-pa-rich-text{
                    min-height:110px;
                    resize:vertical;
                    line-height:1.45;
                    white-space:pre-wrap;
                }
                textarea[name="piece_long_description[]"].mrm-pa-rich-text{ min-height:180px; }

                textarea[name="offer_subtitle[]"].mrm-pa-rich-text{
                    display:block;
                    width:100% !important;
                    max-width:none !important;
                    min-height:180px;
                    box-sizing:border-box;
                }

                .mrm-pa-offer-subtitle-row td{
                    padding:12px 0 18px !important;
                }

                .mrm-pa-offer-subtitle-inner{
                    width:100%;
                    max-width:none;
                }
                .mrm-pa-format-toolbar{
                    display:flex;
                    gap:6px;
                    flex-wrap:wrap;
                    margin:0 0 4px;
                }
                .mrm-pa-format-toolbar button{
                    min-height:28px;
                    padding:2px 8px;
                    border:1px solid #c3c4c7;
                    border-radius:6px;
                    background:#f6f7f7;
                    cursor:pointer;
                    font-size:12px;
                    font-weight:700;
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

                /* Piece ordering panel */
                .mrm-pa-piece-order-panel{
                    border: 1px solid #dcdcde;
                    background: #fff;
                    border-radius: 12px;
                    padding: 14px;
                    margin: 14px 0 18px;
                }

                .mrm-pa-piece-order-panel h3{
                    margin: 0 0 6px;
                    font-size: 14px;
                }

                .mrm-pa-piece-order-panel .description{
                    margin: 0 0 10px;
                }

                #mrm-pa-piece-order-list{
                    margin: 0;
                    padding: 0;
                    list-style: none;
                }

                .mrm-pa-piece-order-item{
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 12px;
                    border: 1px solid #dcdcde;
                    background: #f6f7f7;
                    border-radius: 8px;
                    padding: 8px 10px;
                    margin: 8px 0;
                    cursor: grab;
                }

                .mrm-pa-piece-order-item.is-dragging{
                    opacity: .55;
                }

                .mrm-pa-piece-order-title{
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    min-width: 0;
                    font-weight: 600;
                }

                .mrm-pa-piece-order-handle{
                    color: #646970;
                    font-size: 16px;
                    line-height: 1;
                }

                .mrm-pa-piece-order-name{
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }

                .mrm-pa-piece-order-actions{
                    display: flex;
                    gap: 6px;
                    flex-shrink: 0;
                }
                /* Timeline display settings panel */
                .mrm-pa-timeline-panel{
                    border: 1px solid #dcdcde;
                    background: #fff;
                    border-radius: 12px;
                    padding: 14px;
                    margin: 14px 0 18px;
                    max-width: 1200px;
                }

                .mrm-pa-timeline-panel-head{
                    display:flex;
                    align-items:center;
                    justify-content:space-between;
                    gap:12px;
                    margin-bottom: 6px;
                }

                .mrm-pa-timeline-panel-head h3{
                    margin: 0;
                    font-size: 14px;
                }

                .mrm-pa-timeline-board{
                    display:grid;
                    grid-template-columns: repeat(4, minmax(0, 1fr));
                    gap: 12px;
                    margin-top: 12px;
                }

                .mrm-pa-timeline-column{
                    border: 1px solid #dcdcde;
                    background: #f6f7f7;
                    border-radius: 10px;
                    padding: 10px;
                    min-height: 180px;
                }

                .mrm-pa-timeline-column-hidden{
                    background: #fbfbfb;
                }

                .mrm-pa-timeline-column-head{
                    display:flex;
                    align-items:center;
                    justify-content:space-between;
                    gap:8px;
                    margin-bottom: 8px;
                }

                .mrm-pa-timeline-column-head strong{
                    font-size: 13px;
                }

                .mrm-pa-timeline-list{
                    min-height: 126px;
                    margin: 0;
                    padding: 0;
                    list-style: none;
                }

                .mrm-pa-timeline-list.is-drag-over{
                    outline: 2px dashed #2271b1;
                    outline-offset: 4px;
                    border-radius: 8px;
                }

                .mrm-pa-timeline-item{
                    display:flex;
                    flex-direction:column;
                    align-items:stretch;
                    justify-content:flex-start;
                    gap:8px;
                    border: 1px solid #c3c4c7;
                    background: #fff;
                    border-radius: 8px;
                    padding: 9px 10px;
                    margin: 8px 0;
                    cursor: grab;
                }

                .mrm-pa-timeline-item.is-dragging{
                    opacity: .55;
                }

                .mrm-pa-timeline-item-title{
                    display:flex;
                    align-items:center;
                    gap:8px;
                    min-width:0;
                    flex: 1 1 auto;
                    width: 100%;
                    font-weight:600;
                    color:#1d2327;
                }

                .mrm-pa-timeline-handle{
                    color:#646970;
                    font-size:16px;
                    line-height:1;
                }

                .mrm-pa-timeline-name{
                    display:block;
                    flex: 1 1 auto;
                    min-width:0;
                    max-width:100%;
                    overflow:hidden;
                    text-overflow:ellipsis;
                    white-space:nowrap;
                    color:#1d2327;
                    font-weight:700;
                }

                .mrm-pa-timeline-actions{
                    display:flex;
                    gap:4px;
                    flex-shrink:0;
                    flex-wrap:wrap;
                }

                .mrm-pa-timeline-actions .button{
                    min-height: 26px;
                    line-height: 1;
                }

                .mrm-pa-timeline-empty{
                    margin: 8px 0 0;
                    padding: 10px;
                    border: 1px dashed #c3c4c7;
                    border-radius: 8px;
                    color: #646970;
                    background: rgba(255,255,255,.65);
                    font-size: 12px;
                }

                @media (max-width: 1100px){
                    .mrm-pa-timeline-board{
                        grid-template-columns: repeat(2, minmax(0, 1fr));
                    }
                }

                @media (max-width: 700px){
                    .mrm-pa-timeline-board{
                        grid-template-columns: 1fr;
                    }

                    .mrm-pa-timeline-panel-head{
                        align-items:flex-start;
                        flex-direction:column;
                    }

                    .mrm-pa-timeline-item{
                        align-items:flex-start;
                        flex-direction:column;
                    }

                    .mrm-pa-timeline-actions{
                        width:100%;
                        flex-wrap:wrap;
                    }
                }
                @media (max-width: 900px){
                    .mrm-pa-grid{ grid-template-columns: 1fr; }

                    .mrm-pa-piece-order-item{
                        align-items: flex-start;
                        flex-direction: column;
                    }

                    .mrm-pa-piece-order-actions{
                        width: 100%;
                    }
                }
                </style>

                <div class="mrm-pa-piece-order-panel" id="mrm-pa-piece-order-panel">
    <h3><?php esc_html_e( 'Piece Display Order', 'mrm-product-access' ); ?></h3>
    <p class="description">
        <?php esc_html_e( 'Drag pieces or use the Move Up / Move Down buttons to control the order used by the sheet music catalog shortcode.', 'mrm-product-access' ); ?>
    </p>
    <ol id="mrm-pa-piece-order-list" aria-label="<?php esc_attr_e( 'Piece display order', 'mrm-product-access' ); ?>"></ol>
    <p class="mrm-pa-help">
        <?php esc_html_e( 'This does not change any piece content. It only changes the saved display order used by [mrm_sheet_music_catalog].', 'mrm-product-access' ); ?>
    </p>
</div>

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
                    $title  = $this->mrm_pa_plain_text( $piece['piece_title'] ?? '' );
                    $cname  = $this->mrm_pa_plain_text( $piece['composer_name'] ?? '' );
                    $curl   = $this->mrm_pa_unslash_value( $piece['composer_url'] ?? '' );
                    $desc   = $this->mrm_pa_rich_text( $piece['description'] ?? '' );
                    $short_desc = $this->mrm_pa_rich_text( $piece['short_description'] ?? '' );
                    $long_desc  = $this->mrm_pa_rich_text( $piece['long_description'] ?? '' );
                    $diff   = $this->mrm_pa_plain_text( $piece['difficulty'] ?? '' );
                    $instr  = $this->mrm_pa_plain_text( $piece['instrumentation'] ?? '' );
                    $dur    = $this->mrm_pa_plain_text( $piece['duration'] ?? '' );
                    $yr     = $this->mrm_pa_plain_text( $piece['year'] ?? '' );
                    $pdf    = $this->mrm_pa_unslash_value( $piece['main_preview_pdf_url'] ?? '' );
                    $ppn    = intval( $piece['preview_page_number'] ?? 1 );
                    if ( $ppn <= 0 ) { $ppn = 1; }
                    $preview_audio_url = $this->mrm_pa_unslash_value( $piece['preview_audio_url'] ?? '' );
                    $timeline_level = isset( $piece['timeline_level'] ) ? sanitize_key( (string) $piece['timeline_level'] ) : 'hidden';
                    if ( ! in_array( $timeline_level, array( 'level_1', 'level_2', 'level_3', 'hidden' ), true ) ) {
                        $timeline_level = 'hidden';
                    }
                    $timeline_order = isset( $piece['timeline_order'] ) ? intval( $piece['timeline_order'] ) : 0;
                    if ( $timeline_order < 0 ) {
                        $timeline_order = 0;
                    }
                    $offers = isset( $piece['offers'] ) && is_array( $piece['offers'] ) ? $piece['offers'] : array();
                    ?>
                    <div class="mrm-pa-piece-card" data-piece-index="<?php echo esc_attr( $i ); ?>" data-piece-order-key="<?php echo esc_attr( $slug !== '' ? $slug : 'piece-' . $i ); ?>">
                        <div class="mrm-pa-piece-card-head">
                            <h3><?php echo esc_html( $title !== '' ? $title : 'New Piece' ); ?></h3>
                            <div class="mrm-pa-row-actions">
                                <button type="button" class="button mrm-pa-remove-piece"><?php esc_html_e( 'Remove Piece', 'mrm-product-access' ); ?></button>
                            </div>
                        </div>

                        <div class="mrm-pa-grid">
                            <div class="mrm-pa-field">
                                <label><?php esc_html_e( 'Piece Display Title', 'mrm-product-access' ); ?></label>
                                <input type="text" name="piece_title[]" value="<?php echo esc_attr( $title ); ?>" placeholder="Blackbeard's Revenge">
                                <div class="mrm-pa-help"><?php esc_html_e( 'This is the public-facing title. The slug stays separate underneath.', 'mrm-product-access' ); ?></div>
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
                                <textarea class="mrm-pa-rich-text" name="piece_short_description[]" rows="5" placeholder="A 1–2 sentence summary shown on the catalog listing."><?php echo esc_textarea( $short_desc ); ?></textarea>
                                <div class="mrm-pa-help"><?php esc_html_e( 'Use Enter once for a new line or Enter twice for a blank line/new paragraph. Supports bold, italic, and underline.', 'mrm-product-access' ); ?></div>
                            </div>

                            <div class="mrm-pa-field mrm-pa-wide">
                                <label><?php esc_html_e( 'Long Description (shows on piece page)', 'mrm-product-access' ); ?></label>
                                <textarea class="mrm-pa-rich-text" name="piece_long_description[]" rows="8" placeholder="Full description shown on the generated piece page."><?php echo esc_textarea( $long_desc !== '' ? $long_desc : $desc ); ?></textarea>
                                <div class="mrm-pa-help"><?php esc_html_e( 'Use Enter once for a new line or Enter twice for a blank line/new paragraph. Supports bold, italic, and underline.', 'mrm-product-access' ); ?></div>
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

                            <input type="hidden" name="piece_timeline_level[]" class="mrm-pa-timeline-level-input" value="<?php echo esc_attr( $timeline_level ); ?>">
                            <input type="hidden" name="piece_timeline_order[]" class="mrm-pa-timeline-order-input" value="<?php echo esc_attr( $timeline_order ); ?>">

                            <div class="mrm-pa-field mrm-pa-wide">
                                <label><?php esc_html_e( 'Main Preview PDF URL / Path', 'mrm-product-access' ); ?></label>
                                <input type="text" name="piece_main_preview_pdf_url[]" value="<?php echo esc_attr( $pdf ); ?>" placeholder="/wp-content/uploads/2026/01/BlackbeardsRevengeSamplePage.pdf">
                            </div>

                            <div class="mrm-pa-field mrm-pa-wide">
                                <label><?php esc_html_e( 'Main Preview Audio URL / Path', 'mrm-product-access' ); ?></label>
                                <input type="text" name="piece_preview_audio_url[]" value="<?php echo esc_attr( $preview_audio_url ); ?>" placeholder="/wp-content/uploads/2025/12/Blackbeards-Revenge.mp3">
                                <div class="mrm-pa-help"><?php esc_html_e( 'This plays once near the top of the piece product page, directly under the PDF preview.', 'mrm-product-access' ); ?></div>
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
                                        <th style="width:220px;"><?php esc_html_e( 'Product Slug', 'mrm-product-access' ); ?></th>
                                        <th style="width:240px;"><?php esc_html_e( 'Display Title', 'mrm-product-access' ); ?></th>
                                        <th style="width:120px;"><?php esc_html_e( 'Price Display', 'mrm-product-access' ); ?></th>
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
                                    $dt = $this->mrm_pa_plain_text( $off['display_title'] ?? '' );
                                    $st = $this->mrm_pa_rich_text( $off['subtitle'] ?? '' );
                                    $pr = $this->mrm_pa_plain_text( $off['price_display'] ?? '' );
                                    $au = $this->mrm_pa_unslash_value( $off['preview_audio_url'] ?? '' );
                                    ?>
                                    <tr class="mrm-pa-offer-row">
                                        <td>
                                            <input type="hidden" name="offer_piece_index[]" value="<?php echo esc_attr( $i ); ?>">
                                            <input type="hidden" name="offer_preview_audio_url[]" value="<?php echo esc_attr( $au ); ?>">
                                            <input type="text" name="offer_product_slug[]" value="<?php echo esc_attr( $ps ); ?>" placeholder="blackbeards-revenge-tuba-full-piece">
                                        </td>
                                        <td><input type="text" name="offer_display_title[]" value="<?php echo esc_attr( $dt ); ?>" placeholder="Tuba Full Piece"></td>
                                        <td><input type="text" name="offer_price_display[]" value="<?php echo esc_attr( $pr ); ?>" placeholder="$25"></td>
                                        <td><button type="button" class="button mrm-pa-remove-offer"><?php esc_html_e( 'Remove', 'mrm-product-access' ); ?></button></td>
                                    </tr>
                                    <tr class="mrm-pa-offer-subtitle-row">
                                        <td colspan="4">
                                            <div class="mrm-pa-offer-subtitle-inner">
                                                <label style="display:block;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Purchasing Option Subtitle', 'mrm-product-access' ); ?></label>
                                                <textarea class="mrm-pa-rich-text" name="offer_subtitle[]" rows="8" placeholder="Includes the Tuba part..."><?php echo esc_textarea( $st ); ?></textarea>
                                                <div class="mrm-pa-help"><?php esc_html_e( 'Use Enter once for a new line or Enter twice for a blank line/new paragraph. Supports bold, italic, and underline.', 'mrm-product-access' ); ?></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>

                            <div class="mrm-pa-help" style="margin-top:8px;">
                                <?php esc_html_e( 'These offer fields map to the piece product purchasing options. Offer-level preview audio is preserved if already saved, but the public page now uses the single main preview audio above the details.', 'mrm-product-access' ); ?>
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
                    const orderList = document.getElementById('mrm-pa-piece-order-list');
                    const timelineBoard = document.getElementById('mrm-pa-timeline-board');
                    const settingsForm = wrap ? wrap.closest('form') : null;
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
                                    <label>Piece Display Title</label>
                                    <input type="text" name="piece_title[]" value="" placeholder="Blackbeard's Revenge">
                                    <div class="mrm-pa-help">This is the public-facing title. The slug stays separate underneath.</div>
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
                                    <textarea class="mrm-pa-rich-text" name="piece_short_description[]" rows="5" placeholder="A 1–2 sentence summary shown on the catalog listing."></textarea>
                                    <div class="mrm-pa-help">Use Enter once for a new line or Enter twice for a blank line/new paragraph. Supports bold, italic, and underline.</div>
                                </div>

                                <div class="mrm-pa-field mrm-pa-wide">
                                    <label>Long Description (shows on piece page)</label>
                                    <textarea class="mrm-pa-rich-text" name="piece_long_description[]" rows="8" placeholder="Full description shown on the generated piece page."></textarea>
                                    <div class="mrm-pa-help">Use Enter once for a new line or Enter twice for a blank line/new paragraph. Supports bold, italic, and underline.</div>
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

                                <input type="hidden" name="piece_timeline_level[]" class="mrm-pa-timeline-level-input" value="hidden">
                                <input type="hidden" name="piece_timeline_order[]" class="mrm-pa-timeline-order-input" value="0">

                                <div class="mrm-pa-field mrm-pa-wide">
                                    <label>Main Preview PDF URL / Path</label>
                                    <input type="text" name="piece_main_preview_pdf_url[]" value="" placeholder="/wp-content/uploads/.../SamplePage.pdf">
                                </div>

                                <div class="mrm-pa-field mrm-pa-wide">
                                    <label>Main Preview Audio URL / Path</label>
                                    <input type="text" name="piece_preview_audio_url[]" value="" placeholder="/wp-content/uploads/2025/12/Blackbeards-Revenge.mp3">
                                    <div class="mrm-pa-help">This plays once near the top of the piece product page, directly under the PDF preview.</div>
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
                                            <th style="width:220px;">Product Slug</th>
                                            <th style="width:240px;">Display Title</th>
                                            <th style="width:120px;">Price Display</th>
                                            <th style="width:110px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="mrm-pa-offers-body">
                                        ${offerRowTemplate(index)}
                                    </tbody>
                                </table>

                                <div class="mrm-pa-help" style="margin-top:8px;">
                                    These map to the piece product purchasing options. Offer-level preview audio is no longer displayed on the public page.
                                </div>
                            </div>
                        </div>`;
                    }

                    function getPieceCards(){
    return Array.from(wrap.children).filter(function(child){
        return child.classList && child.classList.contains('mrm-pa-piece-card');
    });
}

function getPieceTitle(card, index){
    if (!card) {
        return `Untitled Piece ${index + 1}`;
    }

    const titleInput = card.querySelector('input[name="piece_title[]"]');
    const heading = card.querySelector('.mrm-pa-piece-card-head h3');

    const rawTitle = titleInput && titleInput.value ? titleInput.value.trim() : '';
    const headingTitle = heading && heading.textContent ? heading.textContent.trim() : '';

    return rawTitle || headingTitle || `Untitled Piece ${index + 1}`;
}

function updatePieceCardHeadings(){
    getPieceCards().forEach(function(card, index){
        const heading = card.querySelector('.mrm-pa-piece-card-head h3');
        if (heading) {
            heading.textContent = getPieceTitle(card, index);
        }
    });
}

function reindexPiecesAndOffers(){
    getPieceCards().forEach(function(card, index){
        card.setAttribute('data-piece-index', String(index));

        const offerIndexInputs = card.querySelectorAll('input[name="offer_piece_index[]"]');
        offerIndexInputs.forEach(function(input){
            input.value = String(index);
        });
    });
}

function syncPieceOrderList(){
    if (!orderList) return;

    updatePieceCardHeadings();
    reindexPiecesAndOffers();

    const cards = getPieceCards();
    orderList.innerHTML = '';

    if (!cards.length) {
        const empty = document.createElement('li');
        empty.className = 'mrm-pa-help';
        empty.textContent = 'No pieces yet. Add a piece below to include it in the catalog order.';
        orderList.appendChild(empty);
        return;
    }

    cards.forEach(function(card, index){
        const item = document.createElement('li');
        item.className = 'mrm-pa-piece-order-item';
        item.draggable = true;
        item.setAttribute('data-piece-index', String(index));

        const title = getPieceTitle(card, index);

        item.innerHTML = `
            <div class="mrm-pa-piece-order-title">
                <span class="mrm-pa-piece-order-handle" aria-hidden="true">↕</span>
                <span class="mrm-pa-piece-order-name"></span>
            </div>
            <div class="mrm-pa-piece-order-actions">
                <button type="button" class="button button-small mrm-pa-piece-order-up">Move Up</button>
                <button type="button" class="button button-small mrm-pa-piece-order-down">Move Down</button>
            </div>
        `;

        const name = item.querySelector('.mrm-pa-piece-order-name');
        if (name) {
            name.textContent = title;
        }

        orderList.appendChild(item);
    });
}

function movePieceCard(fromIndex, toIndex){
    const cards = getPieceCards();

    if (
        fromIndex === toIndex ||
        fromIndex < 0 ||
        toIndex < 0 ||
        fromIndex >= cards.length ||
        toIndex >= cards.length
    ) {
        return;
    }

    const cardToMove = cards[fromIndex];
    const targetCard = cards[toIndex];

    if (!cardToMove || !targetCard) {
        return;
    }

    if (fromIndex < toIndex) {
        wrap.insertBefore(cardToMove, targetCard.nextSibling);
    } else {
        wrap.insertBefore(cardToMove, targetCard);
    }

    syncPieceOrderList();
    syncTimelineBoard();
}

function getTimelineLevelInput(card){
    return card ? card.querySelector('input[name="piece_timeline_level[]"], select[name="piece_timeline_level[]"]') : null;
}

function getTimelineOrderInput(card){
    return card ? card.querySelector('input[name="piece_timeline_order[]"]') : null;
}

function normalizeTimelineLevel(level){
    const allowed = ['level_1', 'level_2', 'level_3', 'hidden'];
    return allowed.includes(level) ? level : 'hidden';
}

function ensureTimelineInputs(card){
    if (!card) return;

    let levelInput = getTimelineLevelInput(card);
    if (!levelInput) {
        levelInput = document.createElement('input');
        levelInput.type = 'hidden';
        levelInput.name = 'piece_timeline_level[]';
        levelInput.className = 'mrm-pa-timeline-level-input';
        levelInput.value = 'hidden';
        card.appendChild(levelInput);
    }

    let orderInput = getTimelineOrderInput(card);
    if (!orderInput) {
        orderInput = document.createElement('input');
        orderInput.type = 'hidden';
        orderInput.name = 'piece_timeline_order[]';
        orderInput.className = 'mrm-pa-timeline-order-input';
        orderInput.value = '0';
        card.appendChild(orderInput);
    }
}

function getTimelineLevel(card){
    ensureTimelineInputs(card);
    const input = getTimelineLevelInput(card);
    return normalizeTimelineLevel(input ? input.value : 'hidden');
}

function getTimelineOrder(card){
    ensureTimelineInputs(card);
    const input = getTimelineOrderInput(card);
    const value = input ? parseInt(input.value || '0', 10) : 0;
    return Number.isFinite(value) ? value : 0;
}

function setTimelineValues(card, level, order){
    ensureTimelineInputs(card);

    const levelInput = getTimelineLevelInput(card);
    const orderInput = getTimelineOrderInput(card);

    if (levelInput) {
        levelInput.value = normalizeTimelineLevel(level);
    }

    if (orderInput) {
        orderInput.value = String(Math.max(0, parseInt(order || 0, 10) || 0));
    }
}

function getTimelineList(level){
    if (!timelineBoard) return null;
    const column = timelineBoard.querySelector('[data-mrm-timeline-level="' + level + '"]');
    return column ? column.querySelector('.mrm-pa-timeline-list') : null;
}

function getTimelineLevelFromList(list){
    const column = list ? list.closest('.mrm-pa-timeline-column') : null;
    return column ? normalizeTimelineLevel(column.getAttribute('data-mrm-timeline-level') || 'hidden') : 'hidden';
}

function timelineItemTemplate(card, pieceIndex){
    const level = getTimelineLevel(card);
    const title = getPieceTitle(card, pieceIndex);

    const item = document.createElement('li');
    item.className = 'mrm-pa-timeline-item';
    item.draggable = true;
    item.setAttribute('data-piece-index', String(pieceIndex));

    item.innerHTML = `
        <div class="mrm-pa-timeline-item-title">
            <span class="mrm-pa-timeline-handle" aria-hidden="true">↕</span>
            <span class="mrm-pa-timeline-name"></span>
        </div>
        <div class="mrm-pa-timeline-actions">
            <button type="button" class="button button-small mrm-pa-timeline-up">Up</button>
            <button type="button" class="button button-small mrm-pa-timeline-down">Down</button>
            <button type="button" class="button button-small mrm-pa-timeline-level" data-mrm-target-level="level_1">L1</button>
            <button type="button" class="button button-small mrm-pa-timeline-level" data-mrm-target-level="level_2">L2</button>
            <button type="button" class="button button-small mrm-pa-timeline-level" data-mrm-target-level="level_3">L3</button>
            <button type="button" class="button button-small mrm-pa-timeline-level" data-mrm-target-level="hidden">Hide</button>
        </div>
    `;

    const name = item.querySelector('.mrm-pa-timeline-name');
    if (name) {
        name.textContent = title;
        name.setAttribute('title', title);
    }

    item.querySelectorAll('.mrm-pa-timeline-level').forEach(function(btn){
        if (normalizeTimelineLevel(btn.getAttribute('data-mrm-target-level')) === level) {
            btn.disabled = true;
        }
    });

    return item;
}

function clearTimelineEmptyMessages(){
    if (!timelineBoard) return;
    timelineBoard.querySelectorAll('.mrm-pa-timeline-empty').forEach(function(empty){
        empty.remove();
    });
}

function addTimelineEmptyMessages(){
    if (!timelineBoard) return;

    timelineBoard.querySelectorAll('.mrm-pa-timeline-list').forEach(function(list){
        if (list.children.length) return;

        const column = list.closest('.mrm-pa-timeline-column');
        const level = column ? normalizeTimelineLevel(column.getAttribute('data-mrm-timeline-level') || 'hidden') : 'hidden';

        const empty = document.createElement('div');
        empty.className = 'mrm-pa-timeline-empty';
        empty.textContent = level === 'hidden' ? 'No hidden pieces.' : 'Drag pieces here.';

        list.appendChild(empty);
    });
}

function syncTimelineBoard(){
    if (!timelineBoard) return;

    const cards = getPieceCards();

    timelineBoard.querySelectorAll('.mrm-pa-timeline-list').forEach(function(list){
        list.innerHTML = '';
    });

    const grouped = {
        level_1: [],
        level_2: [],
        level_3: [],
        hidden: []
    };

    cards.forEach(function(card, index){
        ensureTimelineInputs(card);
        const level = getTimelineLevel(card);
        grouped[level].push({
            card: card,
            index: index,
            order: getTimelineOrder(card),
            title: getPieceTitle(card, index)
        });
    });

    Object.keys(grouped).forEach(function(level){
        grouped[level].sort(function(a, b){
            if (a.order === b.order) {
                return a.index - b.index;
            }
            return a.order - b.order;
        });

        const list = getTimelineList(level);
        if (!list) return;

        grouped[level].forEach(function(entry){
            list.appendChild(timelineItemTemplate(entry.card, entry.index));
        });
    });

    clearTimelineEmptyMessages();
    addTimelineEmptyMessages();
}

function commitTimelineFromBoard(){
    if (!timelineBoard) return;

    timelineBoard.querySelectorAll('.mrm-pa-timeline-list').forEach(function(list){
        const level = getTimelineLevelFromList(list);
        const items = Array.from(list.querySelectorAll('.mrm-pa-timeline-item'));

        items.forEach(function(item, position){
            const pieceIndex = parseInt(item.getAttribute('data-piece-index') || '-1', 10);
            const card = getPieceCards()[pieceIndex];
            if (!card) return;

            setTimelineValues(card, level, (position + 1) * 10);
        });
    });
}

function moveTimelineItem(item, direction){
    if (!item) return;

    const list = item.closest('.mrm-pa-timeline-list');
    if (!list) return;

    const sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;
    if (!sibling || sibling.classList.contains('mrm-pa-timeline-empty')) return;

    if (direction < 0) {
        list.insertBefore(item, sibling);
    } else {
        list.insertBefore(sibling, item);
    }

    commitTimelineFromBoard();
    syncTimelineBoard();
}

function moveTimelineItemToLevel(item, targetLevel){
    if (!item || !timelineBoard) return;

    const targetList = getTimelineList(normalizeTimelineLevel(targetLevel));
    if (!targetList) return;

    targetList.appendChild(item);
    commitTimelineFromBoard();
    syncTimelineBoard();
}

function offerRowTemplate(pieceIndex){
    return `
    <tr class="mrm-pa-offer-row">
        <td>
            <input type="hidden" name="offer_piece_index[]" value="${pieceIndex}">
            <input type="hidden" name="offer_preview_audio_url[]" value="">
            <input type="text" name="offer_product_slug[]" value="" placeholder="blackbeards-revenge-tuba-full-piece">
        </td>
        <td><input type="text" name="offer_display_title[]" value="" placeholder="Tuba Full Piece"></td>
        <td><input type="text" name="offer_price_display[]" value="" placeholder="$25"></td>
        <td><button type="button" class="button mrm-pa-remove-offer">Remove</button></td>
    </tr>
    <tr class="mrm-pa-offer-subtitle-row">
        <td colspan="4">
            <div class="mrm-pa-offer-subtitle-inner">
                <label style="display:block;font-weight:600;margin-bottom:6px;">Purchasing Option Subtitle</label>
                <textarea class="mrm-pa-rich-text" name="offer_subtitle[]" rows="8" placeholder="Includes the Tuba part..."></textarea>
                <div class="mrm-pa-help">Use Enter once for a new line or Enter twice for a blank line/new paragraph. Supports bold, italic, and underline.</div>
            </div>
        </td>
    </tr>`;
}

function wrapTextareaSelection(textarea, before, after) {
    if (!textarea) return;

    const start = textarea.selectionStart || 0;
    const end = textarea.selectionEnd || 0;
    const selected = textarea.value.substring(start, end) || 'text';

    textarea.value = textarea.value.substring(0, start) + before + selected + after + textarea.value.substring(end);
    textarea.focus();
    textarea.selectionStart = start + before.length;
    textarea.selectionEnd = start + before.length + selected.length;
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
}

function initRichTextToolbars(scope) {
    (scope || document).querySelectorAll('textarea.mrm-pa-rich-text').forEach(function(textarea){
        if (textarea.dataset.mrmToolbarReady === '1') return;
        textarea.dataset.mrmToolbarReady = '1';

        const bar = document.createElement('div');
        bar.className = 'mrm-pa-format-toolbar';
        bar.innerHTML = `
            <button type="button" data-before="<strong>" data-after="</strong>">Bold</button>
            <button type="button" data-before="<em>" data-after="</em>">Italic</button>
            <button type="button" data-before="<u>" data-after="</u>">Underline</button>
        `;

        bar.addEventListener('click', function(e){
            const btn = e.target.closest('button[data-before]');
            if (!btn) return;

            wrapTextareaSelection(
                textarea,
                btn.getAttribute('data-before') || '',
                btn.getAttribute('data-after') || ''
            );
        });

        textarea.parentNode.insertBefore(bar, textarea);
    });
}

                    addPieceBtn.addEventListener('click', function(){
                        const nextIndex = getPieceCards().length;
                        const temp = document.createElement('div');
                        temp.innerHTML = pieceTemplate(nextIndex);
                        const newCard = temp.firstElementChild;
                        wrap.appendChild(newCard);
                        initRichTextToolbars(newCard);
                        syncPieceOrderList();
                        syncTimelineBoard();
                    });

                    wrap.addEventListener('click', function(e){
                        const removePieceBtn = e.target.closest('.mrm-pa-remove-piece');
                        if (removePieceBtn) {
                            const card = removePieceBtn.closest('.mrm-pa-piece-card');
                            if (card) {
                                card.remove();
                                syncPieceOrderList();
                                syncTimelineBoard();
                            }
                            return;
                        }

                        const addOfferBtn = e.target.closest('.mrm-pa-add-offer');
                        if (addOfferBtn) {
                            reindexPiecesAndOffers();

                            const card = addOfferBtn.closest('.mrm-pa-piece-card');
                            if (!card) return;

                            const pieceIndex = card.getAttribute('data-piece-index');
                            const tbody = card.querySelector('.mrm-pa-offers-body');
                            if (!tbody) return;

                            const temp = document.createElement('tbody');
                            temp.innerHTML = offerRowTemplate(pieceIndex);

                            while (temp.firstElementChild) {
                                tbody.appendChild(temp.firstElementChild);
                            }

                            initRichTextToolbars(tbody);
                            reindexPiecesAndOffers();
                            return;
                        }

                        const removeOfferBtn = e.target.closest('.mrm-pa-remove-offer');
                        if (removeOfferBtn) {
                            const row = removeOfferBtn.closest('.mrm-pa-offer-row');
                            if (row) {
                                const next = row.nextElementSibling;
                                row.remove();
                                if (next && next.classList.contains('mrm-pa-offer-subtitle-row')) {
                                    next.remove();
                                }
                            }
                            return;
                        }
                    });


                    if (timelineBoard) {
                        let draggedTimelineItem = null;

                        timelineBoard.addEventListener('click', function(e){
                            const item = e.target.closest('.mrm-pa-timeline-item');
                            if (!item) return;

                            if (e.target.closest('.mrm-pa-timeline-up')) {
                                moveTimelineItem(item, -1);
                                return;
                            }

                            if (e.target.closest('.mrm-pa-timeline-down')) {
                                moveTimelineItem(item, 1);
                                return;
                            }

                            const levelBtn = e.target.closest('.mrm-pa-timeline-level');
                            if (levelBtn) {
                                moveTimelineItemToLevel(item, levelBtn.getAttribute('data-mrm-target-level'));
                                return;
                            }
                        });

                        timelineBoard.addEventListener('dragstart', function(e){
                            const item = e.target.closest('.mrm-pa-timeline-item');
                            if (!item) return;

                            draggedTimelineItem = item;
                            item.classList.add('is-dragging');

                            if (e.dataTransfer) {
                                e.dataTransfer.effectAllowed = 'move';
                                e.dataTransfer.setData('text/plain', item.getAttribute('data-piece-index') || '');
                            }
                        });

                        timelineBoard.addEventListener('dragover', function(e){
                            const list = e.target.closest('.mrm-pa-timeline-list');
                            if (!list || !draggedTimelineItem) return;

                            e.preventDefault();

                            timelineBoard.querySelectorAll('.mrm-pa-timeline-list.is-drag-over').forEach(function(activeList){
                                if (activeList !== list) {
                                    activeList.classList.remove('is-drag-over');
                                }
                            });

                            list.classList.add('is-drag-over');

                            const afterElement = Array.from(list.querySelectorAll('.mrm-pa-timeline-item:not(.is-dragging)')).find(function(child){
                                const box = child.getBoundingClientRect();
                                return e.clientY < box.top + box.height / 2;
                            });

                            if (afterElement) {
                                list.insertBefore(draggedTimelineItem, afterElement);
                            } else {
                                list.appendChild(draggedTimelineItem);
                            }
                        });

                        timelineBoard.addEventListener('drop', function(e){
                            const list = e.target.closest('.mrm-pa-timeline-list');
                            if (!list || !draggedTimelineItem) return;

                            e.preventDefault();

                            draggedTimelineItem.classList.remove('is-dragging');
                            draggedTimelineItem = null;

                            timelineBoard.querySelectorAll('.mrm-pa-timeline-list.is-drag-over').forEach(function(activeList){
                                activeList.classList.remove('is-drag-over');
                            });

                            commitTimelineFromBoard();
                            syncTimelineBoard();
                        });

                        timelineBoard.addEventListener('dragend', function(){
                            if (draggedTimelineItem) {
                                draggedTimelineItem.classList.remove('is-dragging');
                            }

                            draggedTimelineItem = null;

                            timelineBoard.querySelectorAll('.mrm-pa-timeline-list.is-drag-over').forEach(function(activeList){
                                activeList.classList.remove('is-drag-over');
                            });

                            commitTimelineFromBoard();
                            syncTimelineBoard();
                        });
                    }

                    if (orderList) {
                        let draggedPieceIndex = null;

                        orderList.addEventListener('click', function(e){
                            const item = e.target.closest('.mrm-pa-piece-order-item');
                            if (!item) return;

                            const currentIndex = parseInt(item.getAttribute('data-piece-index') || '-1', 10);

                            if (e.target.closest('.mrm-pa-piece-order-up')) {
                                movePieceCard(currentIndex, currentIndex - 1);
                                return;
                            }

                            if (e.target.closest('.mrm-pa-piece-order-down')) {
                                movePieceCard(currentIndex, currentIndex + 1);
                                return;
                            }
                        });

                        orderList.addEventListener('dragstart', function(e){
                            const item = e.target.closest('.mrm-pa-piece-order-item');
                            if (!item) return;

                            draggedPieceIndex = parseInt(item.getAttribute('data-piece-index') || '-1', 10);
                            item.classList.add('is-dragging');

                            if (e.dataTransfer) {
                                e.dataTransfer.effectAllowed = 'move';
                                e.dataTransfer.setData('text/plain', String(draggedPieceIndex));
                            }
                        });

                        orderList.addEventListener('dragover', function(e){
                            const item = e.target.closest('.mrm-pa-piece-order-item');
                            if (!item) return;

                            e.preventDefault();

                            if (e.dataTransfer) {
                                e.dataTransfer.dropEffect = 'move';
                            }
                        });

                        orderList.addEventListener('drop', function(e){
                            const item = e.target.closest('.mrm-pa-piece-order-item');
                            if (!item) return;

                            e.preventDefault();

                            const targetIndex = parseInt(item.getAttribute('data-piece-index') || '-1', 10);
                            const fromIndex = draggedPieceIndex;

                            draggedPieceIndex = null;

                            movePieceCard(fromIndex, targetIndex);
                        });

                        orderList.addEventListener('dragend', function(){
                            draggedPieceIndex = null;

                            orderList.querySelectorAll('.mrm-pa-piece-order-item.is-dragging').forEach(function(item){
                                item.classList.remove('is-dragging');
                            });
                        });
                    }

                    wrap.addEventListener('input', function(e){
                        if (e.target && e.target.matches('input[name="piece_title[]"]')) {
                            syncPieceOrderList();
                            syncTimelineBoard();
                        }
                    });

                    if (settingsForm) {
                        settingsForm.addEventListener('submit', function(){
                            commitTimelineFromBoard();
                            reindexPiecesAndOffers();
                        });
                    }

                    initRichTextToolbars(wrap);
                    syncPieceOrderList();
                    syncTimelineBoard();
                })();
                </script>

                <h2 class="title">Product Pages</h2>
<p>
    Unlimited rows. Empty rows are allowed and ignored at runtime.
    Drag rows to organize track order, or duplicate an existing row to create a similar track quickly.
    Stored in <code>product_tracks_by_slug</code>.
</p>

<style>
  .mrm-tracks-map-wrap { max-width: 1200px; }
  .mrm-tracks-map-table { max-width: 1200px; }
  .mrm-tracks-map-table input[type="text"] { box-sizing: border-box; }
  .mrm-tracks-map-slug { width: 180px; }
  .mrm-tracks-map-name { width: 240px; }
  .mrm-tracks-map-url { width: 100%; }
  .mrm-track-drag-cell { width: 44px; text-align: center; vertical-align: middle; }
  .mrm-drag-handle { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border: 1px solid #c3c4c7; border-radius: 6px; background: #f6f7f7; cursor: grab; user-select: none; touch-action: none; font-size: 17px; line-height: 1; }
  .mrm-drag-handle:active { cursor: grabbing; }
  .mrm-track-row.is-dragging { opacity: 0.55; background: #f0f6fc; }
  .mrm-track-row.mrm-drop-before td { border-top: 3px solid #2271b1; }
  .mrm-track-row.mrm-drop-after td { border-bottom: 3px solid #2271b1; }
  .mrm-track-actions { width: 125px; white-space: nowrap; }
  .mrm-track-actions .button { margin-right: 4px; }
  .mrm-track-help { margin-top: 8px; color: #646970; }
</style>

<?php
  $rows = get_option( 'product_tracks_by_slug', array() );
  if ( ! is_array( $rows ) ) {
      $rows = array();
  }
?>

<div class="mrm-tracks-map-wrap">
  <table class="widefat striped mrm-tracks-map-table" id="mrmTracksMapTable">
    <thead><tr><th style="width:44px;">Move</th><th style="width:200px;">product_slug</th><th style="width:260px;">Track / PDF Display Name</th><th>URL / Path</th><th style="width:125px;">Actions</th></tr></thead>
    <tbody>
      <?php foreach ( $rows as $r ) : ?>
        <tr class="mrm-track-row"><td class="mrm-track-drag-cell"><span class="mrm-drag-handle" title="Drag to reorder" aria-label="Drag to reorder">☰</span></td><td><input class="mrm-tracks-map-slug" type="text" name="tracks_map_slug[]" value="<?php echo esc_attr( $r['product_slug'] ?? '' ); ?>" /></td><td><input class="mrm-tracks-map-name" type="text" name="tracks_map_name[]" value="<?php echo esc_attr( $r['display_name'] ?? '' ); ?>" /></td><td><input class="mrm-tracks-map-url" type="text" name="tracks_map_url[]" value="<?php echo esc_attr( $r['url'] ?? '' ); ?>" /></td><td class="mrm-track-actions"><button type="button" class="button button-small mrm-duplicate-track-row">Duplicate</button></td></tr>
      <?php endforeach; ?>
      <tr class="mrm-track-row"><td class="mrm-track-drag-cell"><span class="mrm-drag-handle" title="Drag to reorder" aria-label="Drag to reorder">☰</span></td><td><input class="mrm-tracks-map-slug" type="text" name="tracks_map_slug[]" value="" /></td><td><input class="mrm-tracks-map-name" type="text" name="tracks_map_name[]" value="" /></td><td><input class="mrm-tracks-map-url" type="text" name="tracks_map_url[]" value="" /></td><td class="mrm-track-actions"><button type="button" class="button button-small mrm-duplicate-track-row">Duplicate</button></td></tr>
    </tbody>
  </table>
  <p><button type="button" class="button" id="mrmAddTrackRow">Add Blank Row</button></p>
  <p class="mrm-track-help">Tip: Drag using the ☰ handle. Duplicating a row copies its product slug, display name, and URL/path, then inserts the copy directly below the original.</p>
</div>

<script>
(function(){
  const table = document.getElementById('mrmTracksMapTable');
  const addBtn = document.getElementById('mrmAddTrackRow');
  if (!table) return;
  const tbody = table.querySelector('tbody');
  if (!tbody) return;
  function makeRow(values = {}) { const tr = document.createElement('tr'); tr.className = 'mrm-track-row'; tr.innerHTML = `<td class="mrm-track-drag-cell"><span class="mrm-drag-handle" title="Drag to reorder" aria-label="Drag to reorder">☰</span></td><td><input class="mrm-tracks-map-slug" type="text" name="tracks_map_slug[]" value="" /></td><td><input class="mrm-tracks-map-name" type="text" name="tracks_map_name[]" value="" /></td><td><input class="mrm-tracks-map-url" type="text" name="tracks_map_url[]" value="" /></td><td class="mrm-track-actions"><button type="button" class="button button-small mrm-duplicate-track-row">Duplicate</button></td>`; const slugInput = tr.querySelector('.mrm-tracks-map-slug'); const nameInput = tr.querySelector('.mrm-tracks-map-name'); const urlInput  = tr.querySelector('.mrm-tracks-map-url'); if (slugInput) slugInput.value = values.slug || ''; if (nameInput) nameInput.value = values.name || ''; if (urlInput)  urlInput.value  = values.url || ''; return tr; }
  function getRowValues(row) { return { slug: row.querySelector('.mrm-tracks-map-slug')?.value || '', name: row.querySelector('.mrm-tracks-map-name')?.value || '', url: row.querySelector('.mrm-tracks-map-url')?.value || '' }; }
  function clearDropClasses() { tbody.querySelectorAll('.mrm-drop-before, .mrm-drop-after').forEach(function(row){ row.classList.remove('mrm-drop-before', 'mrm-drop-after'); }); }
  if (addBtn) { addBtn.addEventListener('click', function(){ const row = makeRow(); tbody.appendChild(row); const firstInput = row.querySelector('input'); if (firstInput) firstInput.focus(); }); }
  tbody.addEventListener('click', function(e){ const duplicateBtn = e.target.closest('.mrm-duplicate-track-row'); if (!duplicateBtn) return; const row = duplicateBtn.closest('tr.mrm-track-row'); if (!row) return; const duplicate = makeRow(getRowValues(row)); row.insertAdjacentElement('afterend', duplicate); const firstInput = duplicate.querySelector('input'); if (firstInput) firstInput.focus(); });
  let dragRow = null; let pointerId = null; let ghost = null;
  function getRowFromPoint(x, y) { if (ghost) { ghost.style.display = 'none'; } const el = document.elementFromPoint(x, y); if (ghost) { ghost.style.display = ''; } return el ? el.closest('tr.mrm-track-row') : null; }
  function createGhost(row, x, y) { const rect = row.getBoundingClientRect(); const g = row.cloneNode(true); g.style.position = 'fixed'; g.style.left = rect.left + 'px'; g.style.top = rect.top + 'px'; g.style.width = rect.width + 'px'; g.style.pointerEvents = 'none'; g.style.opacity = '0.85'; g.style.zIndex = '999999'; g.style.background = '#fff'; g.style.boxShadow = '0 8px 24px rgba(0,0,0,0.18)'; g.style.transform = 'translateY(0)'; document.body.appendChild(g); moveGhost(x, y); return g; }
  function moveGhost(x, y) { if (!ghost || !dragRow) return; const rect = dragRow.getBoundingClientRect(); ghost.style.left = rect.left + 'px'; ghost.style.top = (y - rect.height / 2) + 'px'; }
  tbody.addEventListener('pointerdown', function(e){ const handle = e.target.closest('.mrm-drag-handle'); if (!handle) return; const row = handle.closest('tr.mrm-track-row'); if (!row) return; e.preventDefault(); dragRow = row; pointerId = e.pointerId; row.classList.add('is-dragging'); try { handle.setPointerCapture(pointerId); } catch(err) {} ghost = createGhost(row, e.clientX, e.clientY); });
  tbody.addEventListener('pointermove', function(e){ if (!dragRow || e.pointerId !== pointerId) return; e.preventDefault(); moveGhost(e.clientX, e.clientY); clearDropClasses(); const target = getRowFromPoint(e.clientX, e.clientY); if (!target || target === dragRow) return; const rect = target.getBoundingClientRect(); const before = (e.clientY - rect.top) < (rect.height / 2); if (before) { target.classList.add('mrm-drop-before'); tbody.insertBefore(dragRow, target); } else { target.classList.add('mrm-drop-after'); tbody.insertBefore(dragRow, target.nextElementSibling); } });
  function endDrag(e) { if (!dragRow || e.pointerId !== pointerId) return; clearDropClasses(); dragRow.classList.remove('is-dragging'); dragRow = null; pointerId = null; if (ghost && ghost.parentNode) { ghost.parentNode.removeChild(ghost); } ghost = null; }
  tbody.addEventListener('pointerup', endDrag); tbody.addEventListener('pointercancel', endDrag);
})();
</script>

                <?php submit_button( __( 'Save Settings', 'mrm-product-access' ), 'primary', 'mrm_pa_save_settings' ); ?>
            </form>

            <hr>
            <p><strong><?php esc_html_e( 'Access URL pattern:', 'mrm-product-access' ); ?></strong> <code><?php echo esc_html( home_url( '/mrm-access/{product_slug}/{token}/' ) ); ?></code></p>
            <p><strong><?php esc_html_e( 'Important:', 'mrm-product-access' ); ?></strong> <?php esc_html_e( 'After updating, go to Settings → Permalinks and click Save Changes once.', 'mrm-product-access' ); ?></p>
        </div>
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

    protected function get_tracks_mapping() {
        $rows = get_option( 'product_tracks_by_slug', array() );
        if ( ! is_array( $rows ) ) {
            return array();
        }

        $map = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $slug = $this->sanitize_product_slug( $row['product_slug'] ?? '' );
            $name = sanitize_text_field( (string) ( $row['display_name'] ?? '' ) );
            $url  = $this->sanitize_track_location( (string) ( $row['url'] ?? '' ) );

            if ( $slug === '' || $url === '' ) {
                continue;
            }

            if ( ! isset( $map[ $slug ] ) ) {
                $map[ $slug ] = array();
            }

            $map[ $slug ][] = array(
                'name' => $name !== '' ? $name : 'Track',
                'url'  => $url,
            );
        }

        return $map;
    }

    protected function mrm_pa_track_slug_aliases_for_product_slug( $product_slug ) {
        $product_slug = $this->sanitize_product_slug( $product_slug );

        if ( $product_slug === '' ) {
            return array();
        }

        $aliases = array();

        $add = function( $slug ) use ( &$aliases ) {
            $slug = $this->sanitize_product_slug( $slug );

            if ( $slug !== '' && ! in_array( $slug, $aliases, true ) ) {
                $aliases[] = $slug;
            }
        };

        $add( $product_slug );

        if ( preg_match( '/^piece-(.+)-(fundamentals|trombone-euphonium|tuba|complete-package)$/', $product_slug, $m ) ) {
            $piece_slug = sanitize_title( (string) $m[1] );
            $type       = sanitize_title( (string) $m[2] );

            $add( $piece_slug );
            $add( 'piece-' . $piece_slug );
            $add( 'piece-' . $piece_slug . '-' . $type );
            $add( $piece_slug . '-' . $type );

            if ( $type === 'fundamentals' ) {
                $add( $piece_slug . '-fundamentals-package' );
                $add( $piece_slug . '-fundamentals-full-piece' );
            } elseif ( $type === 'trombone-euphonium' ) {
                $add( $piece_slug . '-trombone-euphonium-full-piece' );
                $add( $piece_slug . '-trombone-euph' );
                $add( $piece_slug . '-trombone-euph-full-piece' );
            } elseif ( $type === 'tuba' ) {
                $add( $piece_slug . '-tuba-full-piece' );
            } elseif ( $type === 'complete-package' ) {
                $add( $piece_slug . '-complete-package' );
                $add( $piece_slug . '-complete-bundle' );
                $add( $piece_slug . '-full-piece' );
                $add( $piece_slug . '-full-package' );

                // Complete package can also fall back to individual package mappings.
                $add( 'piece-' . $piece_slug . '-fundamentals' );
                $add( 'piece-' . $piece_slug . '-trombone-euphonium' );
                $add( 'piece-' . $piece_slug . '-tuba' );
                $add( $piece_slug . '-fundamentals' );
                $add( $piece_slug . '-trombone-euphonium' );
                $add( $piece_slug . '-tuba' );
            }
        }

        return $aliases;
    }

    protected function mrm_get_private_asset_root() {
        return '/home/u309866334/domains/lowbrass-lessons.com/mrm-private';
    }

    protected function sanitize_track_location( $raw ) {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) return '';

        if ( strpos( $raw, '..' ) !== false ) return '';

        if ( preg_match( '#^https?://#i', $raw ) ) {
            return esc_url_raw( $raw );
        }

        if ( strpos( $raw, '/' ) === 0 ) {
            $raw = preg_replace( '/[\\x00-\\x1F\\x7F]/u', '', $raw );

            $rp = realpath( $raw );
            if ( $rp ) {
                $wp_root = realpath( ABSPATH );
                $private_root = realpath( $this->mrm_get_private_asset_root() );

                if ( $wp_root && strpos( $rp, $wp_root ) === 0 ) {
                    return $rp;
                }

                if ( $private_root && strpos( $rp, $private_root ) === 0 ) {
                    return $rp;
                }
            }

            // Keep site-relative paths like /wp-content/uploads/... untouched
            return $raw;
        }

        return '';
    }

    protected function get_tracks_for_slug( $product_slug ) {
        $product_slug = $this->sanitize_product_slug( $product_slug );

        if ( $product_slug === '' ) {
            return array();
        }

        $mapping = $this->get_tracks_mapping();
        $aliases = $this->mrm_pa_track_slug_aliases_for_product_slug( $product_slug );

        $items = array();
        $matched_slug = '';

        foreach ( $aliases as $alias ) {
            if ( isset( $mapping[ $alias ] ) && is_array( $mapping[ $alias ] ) && ! empty( $mapping[ $alias ] ) ) {
                $items = $mapping[ $alias ];
                $matched_slug = $alias;
                break;
            }
        }

        $out = array();

        foreach ( (array) $items as $it ) {
            if ( ! is_array( $it ) ) {
                continue;
            }

            $name = sanitize_text_field( (string) ( $it['name'] ?? '' ) );
            $url  = $this->sanitize_track_location( (string) ( $it['url'] ?? '' ) );

            if ( $url === '' ) {
                continue;
            }

            $out[] = array(
                'name' => $name !== '' ? $name : 'Track',
                'url'  => $url,
            );
        }

        if ( empty( $out ) ) {
            $this->mrm_pa_log_access_event( 'access_page_no_tracks_found', array(
                'requested_product_slug' => $product_slug,
                'aliases_checked'        => implode( ',', array_slice( $aliases, 0, 20 ) ),
            ) );
        } else {
            $this->mrm_pa_log_access_event( 'access_page_tracks_found', array(
                'requested_product_slug' => $product_slug,
                'matched_track_slug'     => $matched_slug,
                'track_count'            => count( $out ),
            ) );
        }

        return $out;
    }

    protected function infer_asset_type_from_url( $url ) {
        $u = strtolower( (string) $url );
        $path = wp_parse_url( $u, PHP_URL_PATH );
        $ext = $path ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : '';
        if ( $ext === 'pdf' ) {
            return 'pdf';
        }
        if ( in_array( $ext, array( 'mp3', 'wav', 'm4a', 'aac', 'ogg', 'oga', 'flac' ), true ) ) {
            return 'audio';
        }
        return 'link';
    }

    protected function resolve_local_path_from_url_or_path( $raw ) {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) {
            return '';
        }

        // If it's already a real path on disk.
        if ( file_exists( $raw ) ) {
            return $raw;
        }

        $private_root = $this->mrm_get_private_asset_root();
        if ( $private_root ) {
            $candidate = trailingslashit( $private_root ) . ltrim( $raw, '/' );
            if ( file_exists( $candidate ) ) {
                return $candidate;
            }
        }

        // Convert uploads URL -> local path
        $uploads = wp_upload_dir();
        $baseurl = $uploads['baseurl'] ?? '';
        $basedir = $uploads['basedir'] ?? '';

        if ( $baseurl && $basedir && strpos( $raw, $baseurl ) === 0 ) {
            $candidate = $basedir . substr( $raw, strlen( $baseurl ) );
            if ( file_exists( $candidate ) ) {
                return $candidate;
            }
        }

        // Convert site URL -> ABSPATH relative (best effort)
        $home = home_url( '/' );
        if ( $home && strpos( $raw, $home ) === 0 ) {
            $rel = '/' . ltrim( substr( $raw, strlen( $home ) ), '/' );
            $candidate = untrailingslashit( ABSPATH ) . $rel;
            if ( file_exists( $candidate ) ) {
                return $candidate;
            }
        }

        return '';
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
        return home_url( '/sheet-music/' );
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

    /**
     * Sanitize a product slug (canonical SKU).
     * Requirements:
     * - lowercase
     * - only a-z 0-9 hyphen underscore
     * - must start with alnum
     */
    protected function sanitize_product_slug( $raw ) {
        $raw = strtolower( trim( (string) $raw ) );
        // Convert spaces to hyphen first, then strip invalid chars.
        $raw = preg_replace( '/\s+/', '-', $raw );
        $raw = preg_replace( '/[^a-z0-9\-_]/', '', $raw );
        $raw = preg_replace( '/-+/', '-', $raw );
        $raw = preg_replace( '/_+/', '_', $raw );
        $raw = trim( $raw, "-_" );

        if ( $raw === '' ) {
            return '';
        }
        if ( ! preg_match( '/^[a-z0-9][a-z0-9\-_]{0,199}$/', $raw ) ) {
            return '';
        }
        return $raw;
    }

    protected function is_valid_product_slug( $slug ) {
        $slug = (string) $slug;
        return ( $slug !== '' && preg_match( '/^[a-z0-9][a-z0-9\-_]{0,199}$/', $slug ) );
    }


    private function mrm_pa_blocked_sheet_music_emails() {
        $emails = get_option( 'mrm_pay_hub_sheet_music_blocked_emails', array() );

        if ( ! is_array( $emails ) ) {
            $emails = array();
        }

        $clean = array();

        foreach ( $emails as $email ) {
            $email = strtolower( trim( sanitize_email( (string) $email ) ) );

            if ( $email && is_email( $email ) ) {
                $clean[] = $email;
            }
        }

        return array_values( array_unique( $clean ) );
    }

    private function mrm_pa_is_sheet_music_email_blocked( $email ) {
        $email = strtolower( trim( sanitize_email( (string) $email ) ) );

        if ( ! $email || ! is_email( $email ) ) {
            return false;
        }

        return in_array( $email, $this->mrm_pa_blocked_sheet_music_emails(), true );
    }

    private function mrm_pa_is_sheet_music_email_hash_blocked( $email_hash ) {
        $email_hash = trim( (string) $email_hash );

        if ( $email_hash === '' ) {
            return false;
        }

        foreach ( $this->mrm_pa_blocked_sheet_music_emails() as $blocked_email ) {
            $salted_hash   = $this->hash_email( $blocked_email );
            $unsalted_hash = hash( 'sha256', $blocked_email );

            if (
                hash_equals( $email_hash, $salted_hash )
                || hash_equals( $email_hash, $unsalted_hash )
            ) {
                return true;
            }
        }

        return false;
    }

    private function mrm_pa_blocked_sheet_music_response() {
        return new WP_REST_Response(
            array(
                'ok'           => false,
                'blocked'      => true,
                'redirect_url' => home_url( '/contact/' ),
                'message'      => __( 'This email is currently restricted from accessing sheet music. Please contact Low Brass Lessons support.', 'mrm-product-access' ),
            ),
            403
        );
    }

    private function payments_hub_has_access( $email_hash, $sku ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mrm_sheet_music_access';

        $sku = strtolower( trim( (string) $sku ) );
        $sku = preg_replace( '/[^a-z0-9\-_]+/', '', $sku );
        if ( ! $sku ) return false;

        if ( $this->mrm_pa_is_sheet_music_email_hash_blocked( $email_hash ) ) {
            return false;
        }

        // Pull lists from Payments Hub (legacy/option-based fallback)
        $lists = get_option( 'mrm_pay_hub_access_lists', array() );
        if ( ! is_array( $lists ) ) $lists = array();

        $hash_of = function( $email ) {
            return $this->hash_email( strtolower( trim( (string) $email ) ) );
        };

        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( $exists !== $table ) {
            // fallback to option lists only
            if ( isset( $lists['all-sheet-music'] ) && is_array( $lists['all-sheet-music'] ) ) {
                foreach ( $lists['all-sheet-music'] as $em ) {
                    $em = sanitize_email( $em );
                    if ( $em && hash_equals( $email_hash, $hash_of( $em ) ) ) return true;
                }
            }
            if ( isset( $lists[ $sku ] ) && is_array( $lists[ $sku ] ) ) {
                foreach ( $lists[ $sku ] as $em ) {
                    $em = sanitize_email( $em );
                    if ( $em && hash_equals( $email_hash, $hash_of( $em ) ) ) return true;
                }
            }
            return false;
        }

        $now = current_time( 'mysql' );

        // Helper: match a DB row against Product Access hash (salted) OR exact db hash (legacy/current)
        $row_matches_email_hash = function( $row ) use ( $email_hash ) {
            $db_hash = isset( $row->email_hash ) ? (string) $row->email_hash : '';
            if ( $db_hash !== '' && hash_equals( $email_hash, $db_hash ) ) {
                return true;
            }

            $email_plain = isset( $row->email_plain ) ? sanitize_email( (string) $row->email_plain ) : '';
            if ( $email_plain !== '' ) {
                $salted = $this->hash_email( strtolower( trim( $email_plain ) ) );
                if ( hash_equals( $email_hash, $salted ) ) {
                    return true;
                }

                $unsalted = hash( 'sha256', strtolower( trim( $email_plain ) ) );
                if ( hash_equals( $email_hash, $unsalted ) || ( $db_hash !== '' && hash_equals( $db_hash, $unsalted ) ) ) {
                    return true;
                }
            }

            return false;
        };

        // Rule 1: Stripe-synced all-sheet-music subscription (primary truth)
        $subs_table = $wpdb->prefix . 'mrm_sheet_music_subscriptions';
        $subs_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $subs_table ) );

        if ( $subs_exists === $subs_table ) {
            $sub_rows = $wpdb->get_results(
                "SELECT id, email_hash, email_plain, stripe_status, current_period_end
                 FROM {$subs_table}
                 ORDER BY id DESC"
            );

            if ( is_array( $sub_rows ) ) {
                foreach ( $sub_rows as $row ) {
                    $db_hash = isset( $row->email_hash ) ? (string) $row->email_hash : '';
                    $email_plain = isset( $row->email_plain ) ? sanitize_email( (string) $row->email_plain ) : '';

                    $matches = false;
                    if ( $db_hash !== '' && hash_equals( $email_hash, $db_hash ) ) {
                        $matches = true;
                    } elseif ( $email_plain !== '' ) {
                        $salted = $this->hash_email( strtolower( trim( $email_plain ) ) );
                        if ( hash_equals( $email_hash, $salted ) ) {
                            $matches = true;
                        }
                    }

                    if ( ! $matches ) {
                        continue;
                    }

                    $status = isset( $row->stripe_status ) ? (string) $row->stripe_status : '';
                    $period_end = isset( $row->current_period_end ) ? (string) $row->current_period_end : '';
                    $period_end_ts = $period_end ? strtotime( $period_end ) : 0;

                    $active = in_array( $status, array( 'trialing', 'active' ), true );
                    $paid_through = ( $period_end_ts > strtotime( $now ) );

                    if ( $active || $paid_through ) {
                        return true;
                    }
                }
            }
        }

        // Rule 1B: instructor-wide piece-product master access
        $instructor_master_sku = 'all-piece-products-instructors';
        $instructor_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, email_hash, email_plain
             FROM {$table}
             WHERE sku=%s AND revoked_at IS NULL
             ORDER BY id DESC",
            (string) $instructor_master_sku
        ) );

        if ( is_array( $instructor_rows ) ) {
            foreach ( $instructor_rows as $row ) {
                if ( $row_matches_email_hash( $row ) ) {
                    return true;
                }
            }
        }

        // Rule 2: master all-sheet-music ledger (legacy fallback)
        $master_sku = 'all-sheet-music';
        $master_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, email_hash, email_plain, expires_at, granted_at
             FROM {$table}
             WHERE sku=%s AND revoked_at IS NULL
             ORDER BY id DESC",
            (string) $master_sku
        ) );

        if ( is_array( $master_rows ) ) {
            foreach ( $master_rows as $row ) {
                if ( ! $row_matches_email_hash( $row ) ) continue;

                $expires_at = isset( $row->expires_at ) ? (string) $row->expires_at : '';
                $granted_at = isset( $row->granted_at ) ? (string) $row->granted_at : '';

                $active = false;
                if ( $expires_at !== '' ) {
                    $active = ( strtotime( $expires_at ) > strtotime( $now ) );
                } elseif ( $granted_at !== '' ) {
                    $active = ( strtotime( $granted_at . ' +31 days' ) > strtotime( $now ) );
                }

                if ( $active ) return true;
            }
        }

        // Rule 3: per-product option list (fallback)
        if ( isset( $lists[ $sku ] ) && is_array( $lists[ $sku ] ) ) {
            foreach ( $lists[ $sku ] as $em ) {
                $em = sanitize_email( $em );
                if ( $em && hash_equals( $email_hash, $hash_of( $em ) ) ) return true;
            }
        }

        if ( preg_match( '/^piece-(.+)-(fundamentals|trombone-euphonium|tuba|complete-package)$/', $sku, $m ) ) {
            $piece_slug = (string) $m[1];
            $package_sku = 'piece-' . $piece_slug . '-complete-package';

            if ( $package_sku !== $sku ) {
                $package_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, email_hash, email_plain
                     FROM {$table}
                     WHERE sku=%s AND revoked_at IS NULL
                     ORDER BY id DESC",
                    (string) $package_sku
                ) );

                if ( is_array( $package_rows ) ) {
                    foreach ( $package_rows as $row ) {
                        if ( $row_matches_email_hash( $row ) ) {
                            return true;
                        }
                    }
                }

                if ( isset( $lists[ $package_sku ] ) && is_array( $lists[ $package_sku ] ) ) {
                    foreach ( $lists[ $package_sku ] as $em ) {
                        $em = sanitize_email( $em );
                        if ( $em && hash_equals( $email_hash, $hash_of( $em ) ) ) {
                            return true;
                        }
                    }
                }
            }
        }

        // Rule 4: per-product DB row
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, email_hash, email_plain
             FROM {$table}
             WHERE sku=%s AND revoked_at IS NULL
             ORDER BY id DESC",
            (string) $sku
        ) );

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                if ( $row_matches_email_hash( $row ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function payments_hub_has_access_for_email( $email, $sku ) {
        $email = sanitize_email( strtolower( trim( (string) $email ) ) );

        if ( empty( $email ) ) {
            return false;
        }

        if ( $this->mrm_pa_is_sheet_music_email_blocked( $email ) ) {
            return false;
        }

        $salted_hash   = $this->hash_email( $email );
        $unsalted_hash = hash( 'sha256', $email );

        if ( $this->payments_hub_has_access( $salted_hash, $sku ) ) {
            return true;
        }

        return $this->payments_hub_has_access( $unsalted_hash, $sku );
    }

    /**
     * Infer the Payments Hub sheet music offer type from Product Access offer data.
     *
     * @param array  $offer    Product Access offer data.
     * @param string $raw_slug Raw Product Access offer slug.
     * @return string
     */
    private function mrm_pa_infer_sheet_music_offer_type( $offer, $raw_slug = '' ) {
        $offer = is_array( $offer ) ? $offer : array();

        $text_parts = array(
            (string) $raw_slug,
            (string) ( $offer['product_slug'] ?? '' ),
            (string) ( $offer['payments_hub_sku'] ?? '' ),
            (string) ( $offer['sku'] ?? '' ),
            (string) ( $offer['display_title'] ?? '' ),
            (string) ( $offer['displayTitle'] ?? '' ),
            (string) ( $offer['subtitle'] ?? '' ),
            (string) ( $offer['short_description'] ?? '' ),
            (string) ( $offer['shortDescription'] ?? '' ),
        );

        $text = strtolower( implode( ' ', $text_parts ) );
        $text = str_replace( '_', '-', $text );

        if ( preg_match( '/complete[-\s]*package|complete[-\s]*bundle|all[-\s]*parts|all[-\s]*versions|bundle/', $text ) ) {
            return 'complete-package';
        }

        if ( preg_match( '/trombone[-\s]*euphonium/', $text ) || ( preg_match( '/trombone/', $text ) && preg_match( '/euphonium/', $text ) ) ) {
            return 'trombone-euphonium';
        }

        if ( preg_match( '/\btuba\b/', $text ) ) {
            return 'tuba';
        }

        if ( preg_match( '/fundamental/', $text ) ) {
            return 'fundamentals';
        }

        return '';
    }

    /**
     * Build likely Payments Hub SKUs for a Product Access OTP request.
     *
     * @param string $product_slug   Best available product SKU guess.
     * @param string $piece_slug     Piece page slug.
     * @param string $offer_type     Payments Hub sheet-music offer type.
     * @param string $raw_offer_slug Raw Product Access offer slug.
     * @return array
     */
    private function get_otp_product_slug_candidates( $product_slug, $piece_slug = '', $offer_type = '', $raw_offer_slug = '' ) {
        global $wpdb;

        $product_slug   = $this->sanitize_product_slug( $product_slug );
        $raw_offer_slug = $this->sanitize_product_slug( $raw_offer_slug );
        $piece_slug     = sanitize_title( (string) $piece_slug );
        $offer_type     = sanitize_title( (string) $offer_type );

        $allowed_types = array(
            'fundamentals',
            'trombone-euphonium',
            'tuba',
            'complete-package',
        );

        if ( ! in_array( $offer_type, $allowed_types, true ) ) {
            $offer_type = '';
        }

        $candidates = array();

        $add_candidate = function( $candidate ) use ( &$candidates ) {
            $candidate = $this->sanitize_product_slug( $candidate );

            if ( $candidate !== '' && ! in_array( $candidate, $candidates, true ) ) {
                $candidates[] = $candidate;
            }
        };

        $add_candidate( $product_slug );
        $add_candidate( $raw_offer_slug );

        if ( $product_slug !== '' && strpos( $product_slug, 'piece-' ) !== 0 ) {
            $add_candidate( 'piece-' . $product_slug );
        }

        if ( $raw_offer_slug !== '' && strpos( $raw_offer_slug, 'piece-' ) !== 0 ) {
            $add_candidate( 'piece-' . $raw_offer_slug );
        }

        if ( $piece_slug !== '' && $offer_type !== '' ) {
            $add_candidate( 'piece-' . $piece_slug . '-' . $offer_type );
        }

        if ( $piece_slug !== '' ) {
            $add_candidate( 'piece-' . $piece_slug . '-complete-package' );
        }

        $table = $wpdb->prefix . 'mrm_sheet_music_access';
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

        if ( $table_exists === $table ) {
            $base_candidates = $candidates;

            foreach ( $base_candidates as $base_candidate ) {
                $suffixed = $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT sku
                     FROM {$table}
                     WHERE (sku = %s OR sku LIKE %s)
                       AND revoked_at IS NULL
                     ORDER BY sku ASC",
                    $base_candidate,
                    $wpdb->esc_like( $base_candidate ) . '-%'
                ) );

                foreach ( (array) $suffixed as $candidate ) {
                    $candidate = $this->sanitize_product_slug( $candidate );

                    if ( $candidate === $base_candidate ) {
                        $add_candidate( $candidate );
                        continue;
                    }

                    if ( preg_match( '/^' . preg_quote( $base_candidate, '/' ) . '-[0-9]+$/', $candidate ) ) {
                        $add_candidate( $candidate );
                    }
                }
            }
        }

        return $candidates;
    }

    /**
     * Resolve an OTP request to the first candidate for which the email has Hub access.
     *
     * @return string Empty when no candidate grants access.
     */
    private function resolve_otp_product_slug_for_email( $email, $product_slug, $piece_slug = '', $offer_type = '', $raw_offer_slug = '' ) {
        $candidates = $this->get_otp_product_slug_candidates(
            $product_slug,
            $piece_slug,
            $offer_type,
            $raw_offer_slug
        );

        foreach ( $candidates as $candidate ) {
            if ( $this->payments_hub_has_access_for_email( $email, $candidate ) ) {
                $this->mrm_pa_log_access_event( 'otp_resolved_access_sku', array(
                    'submitted_product_slug' => $product_slug,
                    'raw_offer_slug'         => $raw_offer_slug,
                    'piece_slug'             => $piece_slug,
                    'offer_type'             => $offer_type,
                    'resolved_sku'           => $candidate,
                ) );

                return $candidate;
            }
        }

        $this->mrm_pa_log_access_event( 'otp_no_candidate_matched_access', array(
            'submitted_product_slug' => $product_slug,
            'raw_offer_slug'         => $raw_offer_slug,
            'piece_slug'             => $piece_slug,
            'offer_type'             => $offer_type,
            'candidate_count'        => count( $candidates ),
            'candidate_preview'      => implode( ',', array_slice( $candidates, 0, 12 ) ),
        ) );

        return '';
    }

    private function mrm_pa_log_access_event( $event, $context = array() ) {
        if ( ! is_array( $context ) ) {
            $context = array();
        }

        $safe_context = array();

        foreach ( $context as $key => $value ) {
            $key = sanitize_key( (string) $key );

            if ( is_scalar( $value ) || $value === null ) {
                $safe_context[ $key ] = is_bool( $value ) ? $value : sanitize_text_field( (string) $value );
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[MRM Product Access] ' . sanitize_key( (string) $event ) . ' ' . wp_json_encode( $safe_context ) );
        }
    }


    private function payments_hub_access_context( $email_hash, $sku ) {
        global $wpdb;

        $table = $wpdb->prefix . 'mrm_sheet_music_access';
        $subs_table = $wpdb->prefix . 'mrm_sheet_music_subscriptions';
        $now = current_time( 'mysql' );

        $context = array(
            'has_access' => false,
            'source' => '',
            'allow_audio_download' => false,
        );

        $sku = strtolower( trim( (string) $sku ) );
        if ( $sku === '' ) return $context;

        if ( $this->mrm_pa_is_sheet_music_email_hash_blocked( $email_hash ) ) {
            $context['source'] = 'blocked_email';
            return $context;
        }

        // Instructor-wide piece access
        $instructor_master_sku = 'all-piece-products-instructors';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, email_hash, email_plain
             FROM {$table}
             WHERE sku=%s AND revoked_at IS NULL
             ORDER BY id DESC",
            $instructor_master_sku
        ) );

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $db_hash = isset( $row->email_hash ) ? (string) $row->email_hash : '';
                $email_plain = isset( $row->email_plain ) ? sanitize_email( (string) $row->email_plain ) : '';

                if (
                    ( $db_hash !== '' && hash_equals( $email_hash, $db_hash ) ) ||
                    ( $email_plain !== '' && hash_equals( $email_hash, $this->hash_email( strtolower( trim( $email_plain ) ) ) ) )
                ) {
                    $context['has_access'] = true;
                    $context['source'] = 'instructor_master';
                    $context['allow_audio_download'] = true;
                    return $context;
                }
            }
        }

        // Customer subscription master access: no audio downloads
        $subs_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $subs_table ) );
        if ( $subs_exists === $subs_table ) {
            $sub_rows = $wpdb->get_results(
                "SELECT id, email_hash, email_plain, stripe_status, current_period_end
                 FROM {$subs_table}
                 ORDER BY id DESC"
            );

            if ( is_array( $sub_rows ) ) {
                foreach ( $sub_rows as $row ) {
                    $db_hash = isset( $row->email_hash ) ? (string) $row->email_hash : '';
                    $email_plain = isset( $row->email_plain ) ? sanitize_email( (string) $row->email_plain ) : '';

                    $matches = false;
                    if ( $db_hash !== '' && hash_equals( $email_hash, $db_hash ) ) {
                        $matches = true;
                    } elseif ( $email_plain !== '' ) {
                        $salted = $this->hash_email( strtolower( trim( $email_plain ) ) );
                        if ( hash_equals( $email_hash, $salted ) ) {
                            $matches = true;
                        }
                    }

                    if ( ! $matches ) {
                        continue;
                    }

                    $status = isset( $row->stripe_status ) ? (string) $row->stripe_status : '';
                    $period_end = isset( $row->current_period_end ) ? (string) $row->current_period_end : '';
                    $period_end_ts = $period_end ? strtotime( $period_end ) : 0;

                    $active = in_array( $status, array( 'trialing', 'active' ), true );
                    $paid_through = ( $period_end_ts > strtotime( $now ) );

                    if ( $active || $paid_through ) {
                        $context['has_access'] = true;
                        $context['source'] = 'sheet_music_subscription';
                        $context['allow_audio_download'] = false;
                        return $context;
                    }
                }
            }
        }

        // Direct piece purchase / legacy access rows: audio downloads allowed
        if ( $this->payments_hub_has_access( $email_hash, $sku ) ) {
            $context['has_access'] = true;
            $context['source'] = 'piece_purchase_or_legacy';
            $context['allow_audio_download'] = true;
            return $context;
        }

        return $context;
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
        if ( ! empty( $conf ) ) {
            $list = isset( $conf['approved_emails'] ) && is_array( $conf['approved_emails'] ) ? $conf['approved_emails'] : array();
            foreach ( $list as $email ) {
                $email = sanitize_email( $email );
                if ( empty( $email ) ) {
                    continue;
                }
                if ( hash_equals( $email_hash, $this->hash_email( strtolower( trim( $email ) ) ) ) ) {
                    return true;
                }
            }
        }

        $products = get_option( 'mrm_pay_hub_products', array() );

        if ( isset( $products[ $product_slug ] ) && ! empty( $products[ $product_slug ]['emails'] ) ) {
            foreach ( $products[ $product_slug ]['emails'] as $email ) {
                $email = sanitize_email( $email );
                if ( empty( $email ) ) {
                    continue;
                }
                if ( hash_equals( $email_hash, $this->hash_email( strtolower( trim( $email ) ) ) ) ) {
                    return true;
                }
            }
        }

        if ( isset( $products['all-sheet-music'] ) && ! empty( $products['all-sheet-music']['emails'] ) ) {
            foreach ( $products['all-sheet-music']['emails'] as $email ) {
                $email = sanitize_email( $email );
                if ( empty( $email ) ) {
                    continue;
                }
                if ( hash_equals( $email_hash, $this->hash_email( strtolower( trim( $email ) ) ) ) ) {
                    return true;
                }
            }
        }

        // Capture everything after "piece-" up to the last hyphen before the type.
        if ( preg_match( '/^piece-(.+)-(fundamentals|trombone-euphonium|tuba|complete-package)$/', $product_slug, $matches ) ) {
            $piece_slug = $matches[1];
            $package_sku = 'piece-' . $piece_slug . '-complete-package';
            if ( isset( $products[ $package_sku ] ) && ! empty( $products[ $package_sku ]['emails'] ) ) {
                foreach ( $products[ $package_sku ]['emails'] as $email ) {
                    $email = sanitize_email( $email );
                    if ( empty( $email ) ) {
                        continue;
                    }
                    if ( hash_equals( $email_hash, $this->hash_email( strtolower( trim( $email ) ) ) ) ) {
                        return true;
                    }
                }
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

        $slug  = $this->sanitize_product_slug( $parts[1] );
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
            $product_slug = $this->sanitize_product_slug( get_query_var( 'mrm_access_product' ) );
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

        // Hub is the source of truth for access.
        $email_hash_from_payload = (string) ( $payload['email_hash'] ?? '' );

        if ( $email_hash_from_payload !== '' && $this->mrm_pa_is_sheet_music_email_hash_blocked( $email_hash_from_payload ) ) {
            wp_safe_redirect( home_url( '/contact/' ) );
            exit;
        }

        $access_still_valid = false;

        // The access page payload stores the hash, not the email.
        // Keep this strict at the SKU level using the existing hash-aware method.
        if ( $email_hash_from_payload !== '' && $this->payments_hub_has_access( $email_hash_from_payload, $product_slug ) ) {
            $access_still_valid = true;
        }

        if ( ! $access_still_valid ) {
            status_header( 403 );
            echo 'Unauthorized.';
            exit;
        }

        $tracks = $this->get_tracks_for_slug( $product_slug );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        }

        if ( empty( $tracks ) ) {
            $this->mrm_pa_log_access_event( 'access_page_empty_tracks_blocked', array(
                'product_slug' => $product_slug,
                'piece_slug'   => (string) ( $payload['piece_slug'] ?? '' ),
            ) );

            status_header( 404 );
            echo 'No protected content is currently configured for this piece/package. Please contact Low Brass Lessons.';
            exit;
        }

        $this->render_access_page( $product_slug, $tracks, $payload );
        exit;
    }

    /**
     * Render the purchased content access page.
     *
     * @param string $product_slug
     * @param array  $tracks
     */
    protected function render_access_page( $product_slug, $tracks, $payload ) {
        $api_base = home_url( '/wp-json/mrm/v1/download' );

        $build = function( $args ) use ( $api_base ) {
            return esc_url( add_query_arg( $args, $api_base ) );
        };

        $has_tracks = ! empty( $tracks ) && is_array( $tracks );
        $access_context = $this->payments_hub_access_context( (string) ( $payload['email_hash'] ?? '' ), $product_slug );
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
      font-family: "Source Sans 3", Arial, Helvetica, sans-serif;
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
      background: #fbf8f2;
    }

    audio.mrm-audio{
      display:none !important;
      background:#fbf8f2 !important;
    }

/* ===== Unified Low Brass Lessons audio player ===== */
.audio-box{
  --mrm-audio-bg: #fbf8f2; --mrm-audio-bg-soft: #fffaf3; --mrm-audio-border: rgba(124, 74, 45, 0.22); --mrm-audio-text: #2f2118; --mrm-audio-muted: #62483a; --mrm-audio-button: #20170f; --mrm-audio-button-text: #fbf8f2; --mrm-audio-track: rgba(98, 72, 58, 0.22); --mrm-audio-thumb: #20170f;
  border: 1px solid var(--mrm-audio-border); border-radius: var(--mrm-radius); padding: 12px; background: var(--mrm-audio-bg); box-sizing: border-box; box-shadow: 0 10px 24px rgba(32, 23, 15, 0.06);
}
.audio-controls{ display:grid; grid-template-columns: auto auto minmax(140px, 1fr) auto auto; grid-template-areas: "play current seek duration volume"; align-items:center; gap:12px; }
.audio-row-top{ display:contents; }
.audio-row-top .mrm-play,.audio-controls > .mrm-play{ grid-area:play; }
.audio-row-top .mrm-current,.audio-controls > .mrm-current{ grid-area:current; }
.audio-row-top .mrm-duration,.audio-controls > .mrm-duration{ grid-area:duration; }
.audio-row-seek{ grid-area:seek; min-width:0; width:100%; }
.audio-row-seek .progress{ display:block; width:100%; min-width:0; }
.audio-row-vol{ grid-area:volume; display:flex; align-items:center; justify-content:flex-end; gap:8px; }
.play-button{ width:42px; height:42px; border-radius:10px; background:var(--mrm-audio-button); color:var(--mrm-audio-button-text); border:1px solid rgba(32, 23, 15, 0.16); cursor:pointer; padding:0; display:inline-flex; align-items:center; justify-content:center; line-height:1; flex:0 0 auto; box-shadow:0 8px 20px rgba(32, 23, 15, 0.15); }
.play-button svg{ width:18px; height:18px; display:block; fill:currentColor; }
.play-button .icon-pause{ display:none; }.play-button.is-playing .icon-play{ display:none; }.play-button.is-playing .icon-pause{ display:block; }
.time{ font-size:13px; color:var(--mrm-audio-muted); min-width:46px; text-align:center; flex:0 0 auto; font-variant-numeric:tabular-nums; line-height:1; }
.progress,.volume input.mrm-volume{ width:100%; min-width:0; appearance:none; -webkit-appearance:none; height:10px; border-radius:999px; background:var(--mrm-audio-track); cursor:pointer; }
.progress::-webkit-slider-thumb,.volume input.mrm-volume::-webkit-slider-thumb{ -webkit-appearance:none; appearance:none; width:18px; height:18px; border-radius:50%; background:var(--mrm-audio-thumb); border:2px solid var(--mrm-audio-bg); box-shadow:0 3px 8px rgba(32, 23, 15, 0.18); }
.progress::-moz-range-thumb,.volume input.mrm-volume::-moz-range-thumb{ width:18px; height:18px; border-radius:50%; background:var(--mrm-audio-thumb); border:2px solid var(--mrm-audio-bg); box-shadow:0 3px 8px rgba(32, 23, 15, 0.18); }
.progress::-moz-range-track,.volume input.mrm-volume::-moz-range-track{ height:10px; border-radius:999px; background:var(--mrm-audio-track); border:none; }
.volume{ display:inline-flex; align-items:center; gap:8px; color:var(--mrm-audio-muted); }.volume svg{ width:20px; height:20px; }.volume input.mrm-volume{ width:132px; }
@media (max-width: 620px){
  .audio-controls{ grid-template-columns:auto 1fr auto; grid-template-areas: "play current duration" "seek seek seek" "volume volume volume"; justify-items:stretch; gap:12px; }
  .audio-row-seek{ width:100%; }
  .audio-row-seek .progress{ height:12px; }
  .audio-row-vol{ width:100%; justify-content:center; }
  .volume input.mrm-volume{ width:min(280px, 72vw); }
}
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
        height: 60vh;
      }

      iframe.pdf{
        width: 100%;
        max-width: 100%;
      }

      /* Audio stays in the exact desktop order on mobile.
         Only the sizing compresses. */
      .audio-box{
        padding: 9px !important;
      }

      .audio-controls{
        display:grid !important;
        grid-template-columns: auto auto minmax(48px, 1fr) auto auto !important;
        grid-template-areas: "play current seek duration volume" !important;
        align-items:center !important;
        justify-items:stretch !important;
        gap:6px !important;
      }

      .audio-row-top{
        display:contents !important;
        width:auto !important;
      }

      .audio-row-top .mrm-play,
      .audio-controls > .mrm-play{
        grid-area:play !important;
      }

      .audio-row-top .mrm-current,
      .audio-controls > .mrm-current{
        grid-area:current !important;
      }

      .audio-row-top .mrm-duration,
      .audio-controls > .mrm-duration{
        grid-area:duration !important;
      }

      .audio-row-seek{
        grid-area:seek !important;
        width:100% !important;
        min-width:0 !important;
      }

      .audio-row-vol{
        grid-area:volume !important;
        width:auto !important;
        justify-content:flex-end !important;
        gap:4px !important;
      }

      .play-button{
        width:34px !important;
        height:34px !important;
        border-radius:9px !important;
      }

      .play-button svg{
        width:14px !important;
        height:14px !important;
      }

      .time{
        font-size:10px !important;
        min-width:30px !important;
      }

      .progress,
      .volume input.mrm-volume{
        height:7px !important;
      }

      .progress::-webkit-slider-thumb,
      .volume input.mrm-volume::-webkit-slider-thumb{
        width:13px !important;
        height:13px !important;
      }

      .progress::-moz-range-thumb,
      .volume input.mrm-volume::-moz-range-thumb{
        width:13px !important;
        height:13px !important;
      }

      .volume svg{
        width:14px !important;
        height:14px !important;
      }

      .volume input,
      .volume input.mrm-volume{
        width:clamp(44px, 15vw, 62px) !important;
      }
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
    <?php if ( $has_tracks ) : ?>
      <div class="card">
        <h2 class="section-title"><?php echo esc_html__( 'Your Files', 'mrm-product-access' ); ?></h2>
        <div class="grid">
          <?php foreach ( $tracks as $idx => $it ) :
              $label = (string) ( $it['name'] ?? ( 'Track ' . ( $idx + 1 ) ) );
              $raw   = (string) ( $it['url'] ?? '' );
              $type  = $this->infer_asset_type_from_url( $raw );

              // If we can resolve to a local path, serve via download proxy (gated).
              $local = $this->resolve_local_path_from_url_or_path( $raw );

              $download_url = '';
              if ( $local !== '' ) {
                  $download_args = array(
                      'product_slug' => $product_slug,
                      'asset_type'   => $type,
                      'track'        => (string) $idx,
                      'inline'       => $type === 'pdf' ? '1' : '0',
                  );
                  if ( $type === 'audio' ) {
                      $download_args['delivery_mode'] = 'stream';
                  }
                  $download_url = $build( $download_args );
              } else {
                  // Fallback: direct URL (not gated) if admin provided a remote URL.
                  $download_url = esc_url( $raw );
              }
          ?>
            <div class="item">
              <div class="top">
                <div class="label"><?php echo esc_html( $label ); ?></div>
                <?php if ( $download_url && ! ( $type === 'audio' && (string) ( $access_context['source'] ?? '' ) === 'sheet_music_subscription' ) ) : ?>
                  <a class="btn primary" href="<?php echo esc_url( $download_url ); ?>" target="_blank" rel="noopener">
                    <?php echo 'Download'; ?>
                  </a>
                <?php endif; ?>
              </div>
              <div class="content">
                <?php if ( $type === 'pdf' && $download_url ) : ?>
                  <iframe class="pdf" src="<?php echo esc_url( $download_url ); ?>"></iframe>
                <?php elseif ( $type === 'audio' && $download_url ) : ?>
                  <div class="audio-box mrm-audio-box">
                    <div class="audio-controls">
                      <div class="audio-row-top">
                        <button class="play-button mrm-play" type="button" aria-label="Play/Pause">
                          <svg class="icon-play" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M8 5v14l11-7z"></path>
                          </svg>
                          <svg class="icon-pause" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M6 5h4v14H6zM14 5h4v14h-4z"></path>
                          </svg>
                        </button>

                        <div class="time mrm-current">0:00</div>
                        <div class="time mrm-duration">0:00</div>
                      </div>

                      <div class="audio-row-seek">
                        <input class="progress mrm-seek" type="range" min="0" max="0" value="0" step="1" aria-label="Seek">
                      </div>

                      <div class="audio-row-vol">
                        <div class="volume" aria-label="Volume">
                          <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M11 5L6 9H2v6h4l5 4V5z"></path>
                          </svg>
                          <input class="mrm-volume" type="range" min="0" max="1" step="0.01" value="1" aria-label="Volume slider">
                        </div>
                      </div>
                    </div>

                    <audio class="mrm-audio" preload="metadata">
                      <source src="<?php echo esc_url( $download_url ); ?>">
                    </audio>
                  </div>
                <?php else : ?>
                  <a class="btn" href="<?php echo esc_url( $download_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'Download', 'mrm-product-access' ); ?></a>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
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
            'email'        => $email_hash,
            'product_slug' => $sku,
            'sku'          => $sku,
            'exp'          => time() + HOUR_IN_SECONDS * 6,
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

    public function rest_access_context( WP_REST_Request $req ) {
        $email = sanitize_email( (string) $req->get_param( 'email' ) );
        $sku   = sanitize_text_field( (string) $req->get_param( 'sku' ) );

        if ( ! $email || ! is_email( $email ) || $sku === '' ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Missing email or sku.' ), 400 );
        }

        if ( $this->mrm_pa_is_sheet_music_email_blocked( $email ) ) {
            return $this->mrm_pa_blocked_sheet_music_response();
        }

        $email_hash = $this->hash_email( strtolower( trim( $email ) ) );
        $context = $this->payments_hub_access_context( $email_hash, $sku );

        return new WP_REST_Response( array(
            'ok' => true,
            'context' => $context,
        ), 200 );
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

        $found = $this->mrm_pa_normalize_piece_for_output( $found );

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

    private function mrm_pa_get_email_logo_url() {
        $custom_logo_id = (int) get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id > 0 ) {
            $img = wp_get_attachment_image_src( $custom_logo_id, 'full' );
            if ( is_array( $img ) && ! empty( $img[0] ) ) {
                return (string) $img[0];
            }
        }
        return '';
    }

    private function mrm_pa_wrap_otp_email_html( $title, $intro_html, $otp, $footer_html = '' ) {
        $site = esc_html( get_bloginfo( 'name' ) );
        $logo = $this->mrm_pa_get_email_logo_url();

        $logo_html = '';
        if ( $logo ) {
            $logo_html = '<div style="text-align:center;margin:0 0 22px 0;">
            <img src="' . esc_url( $logo ) . '" alt="' . $site . '" style="max-width:220px;height:auto;border:0;display:inline-block;">
        </div>';
        }

        $code_box = '<div style="margin:22px auto 18px auto;max-width:260px;background:#f1f1f1;border:1px solid #dddddd;border-radius:12px;padding:18px 14px;text-align:center;">
        <div style="font-size:13px;color:#666;margin-bottom:8px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;">Your Access Code</div>
        <div style="font-size:34px;line-height:1.1;font-weight:700;color:#111;letter-spacing:0.18em;">' . esc_html( $otp ) . '</div>
    </div>';

        return '<!doctype html><html><body style="margin:0;padding:0;background:#f6f6f6;">
        <div style="max-width:640px;margin:0 auto;padding:24px;">
            <div style="background:#ffffff;border:1px solid #e8e8e8;border-radius:16px;padding:28px;box-shadow:0 2px 10px rgba(0,0,0,0.05);font-family:&quot;Source Sans 3&quot;,Arial,Helvetica,sans-serif;color:#111;">
                ' . $logo_html . '
                <h1 style="margin:0 0 12px 0;font-size:22px;line-height:1.3;text-align:center;color:#111;font-family:&quot;Academico&quot;,Georgia,&quot;Times New Roman&quot;,serif;">' . esc_html( $title ) . '</h1>
                <div style="font-size:15px;line-height:1.7;color:#222;">' . $intro_html . '</div>
                ' . $code_box . '
                <div style="margin-top:10px;padding:16px;border:1px solid #ededed;border-radius:12px;background:#fafafa;font-size:14px;line-height:1.7;color:#222;text-align:center;">
                    This code expires in 30 minutes.
                </div>
                ' . $footer_html . '
                <div style="margin-top:22px;font-size:12px;color:#777;text-align:center;">' . $site . '</div>
            </div>
        </div>
    </body></html>';
    }

    public function handle_cross_plugin_email_test( $slug, $to, &$results ) {
        $slug = sanitize_key( (string) $slug );
        $to = sanitize_email( (string) $to );
        if ( $slug !== 'product_access_otp' || ! is_email( $to ) ) {
            return;
        }

        $options = $this->get_options();
        $subject = isset( $options['email_subject'] ) && trim( (string) $options['email_subject'] ) !== ''
            ? (string) $options['email_subject']
            : __( 'Sheet Music Access Code', 'mrm-product-access' );
        $custom_body = str_replace( '{{OTP}}', '', (string) ( $options['email_body'] ?? '' ) );
        $custom_body = trim( (string) preg_replace( '/\n{2,}/', "\n\n", $custom_body ) );

        $intro_html = '<p>This is a test of the protected product access code email.</p>';
        $intro_html .= '<p>Your one-time passcode for accessing your purchased piece is below.</p>';
        if ( $custom_body !== '' ) {
            $intro_html .= '<div style="margin-top:10px;">' . nl2br( esc_html( $custom_body ) ) . '</div>';
        }

        $html = $this->mrm_pa_wrap_otp_email_html( $subject, $intro_html, '' );
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: Low Brass Lessons <no-reply@lowbrass-lessons.com>',
        );
        $results[ $slug ] = wp_mail( $to, '[TEST] ' . $subject, $html, $headers );
    }

    public function handle_cross_plugin_email_preview( $preview, $slug ) {
        $slug = sanitize_key( (string) $slug );

        if ( $slug !== 'product_access_otp' ) {
            return $preview;
        }

        $options = $this->get_options();
        $subject = isset( $options['email_subject'] ) && trim( (string) $options['email_subject'] ) !== ''
            ? (string) $options['email_subject']
            : __( 'Sheet Music Access Code', 'mrm-product-access' );
        $intro_html = '<p>Your one-time passcode for accessing your purchased piece is below.</p>';

        return array(
            'subject' => $subject,
            'html'    => $this->mrm_pa_wrap_otp_email_html( $subject, $intro_html, '' ),
        );
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
        $params         = $request->get_json_params();
        $email          = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
        $product_slug   = isset( $params['product_slug'] ) ? $this->sanitize_product_slug( $params['product_slug'] ) : '';
        $raw_offer_slug = isset( $params['raw_offer_slug'] ) ? $this->sanitize_product_slug( $params['raw_offer_slug'] ) : '';
        $piece_slug     = isset( $params['piece_slug'] ) ? sanitize_title( (string) $params['piece_slug'] ) : '';
        $offer_type     = isset( $params['offer_type'] ) ? sanitize_title( (string) $params['offer_type'] ) : '';

        if ( empty( $product_slug ) && ! empty( $raw_offer_slug ) ) {
            $product_slug = $raw_offer_slug;
        }

        if ( empty( $product_slug ) && ! empty( $piece_slug ) && ! empty( $offer_type ) ) {
            $product_slug = $this->sanitize_product_slug( 'piece-' . $piece_slug . '-' . $offer_type );
        }

        // Generic privacy-preserving response.
        $generic = array(
            'ok'      => true,
            'message' => __( 'If this purchase exists, a code will be sent shortly.', 'mrm-product-access' ),
        );

        if ( empty( $email ) || ( empty( $product_slug ) && ( empty( $piece_slug ) || empty( $offer_type ) ) ) ) {
            $this->mrm_pa_log_access_event( 'otp_missing_request_data', array(
                'has_email'    => empty( $email ) ? 'no' : 'yes',
                'product_slug' => $product_slug,
            ) );

            return new WP_REST_Response( $generic, 200 );
        }

        $normalized_email = strtolower( trim( $email ) );
        $email_hash       = $this->hash_email( $normalized_email );
        $ip               = $_SERVER['REMOTE_ADDR'] ?? '';

        if ( $this->mrm_pa_is_sheet_music_email_blocked( $normalized_email ) ) {
            $this->mrm_pa_log_access_event( 'otp_blocked_security_email', array(
                'email'        => $normalized_email,
                'product_slug' => $product_slug,
                'ip'           => $ip,
            ) );

            return $this->mrm_pa_blocked_sheet_music_response();
        }

        global $wpdb;
        $table_otps = $wpdb->prefix . 'mrm_otp_tokens';

        // Hub is the source of truth for access.
        // Use the existing resolver for this plugin version, but fail closed when no verified purchase/access exists.
        $resolved_product_slug = $this->resolve_otp_product_slug_for_email(
            $normalized_email,
            $product_slug,
            $piece_slug,
            $offer_type,
            $raw_offer_slug
        );

        if ( $resolved_product_slug === '' ) {
            $this->mrm_pa_log_access_event( 'otp_blocked_unverified_email', array(
                'email'          => $normalized_email,
                'product_slug'   => $product_slug,
                'raw_offer_slug' => $raw_offer_slug,
                'piece_slug'     => $piece_slug,
                'offer_type'     => $offer_type,
            ) );

            return new WP_REST_Response(
                array(
                    'ok'        => false,
                    'code_sent' => false,
                    'message'   => __( 'We could not verify a completed purchase for that email and piece. Please confirm the purchase email and selected access option.', 'mrm-product-access' ),
                ),
                403
            );
        }

        $product_slug = $resolved_product_slug;

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
            $this->mrm_pa_log_access_event( 'otp_rate_limited', array(
                'email'        => $normalized_email,
                'product_slug' => $product_slug,
                'ip'           => $ip,
            ) );

            return new WP_REST_Response(
                array(
                    'ok'        => false,
                    'code_sent' => false,
                    'message'   => __( 'Too many access code requests were made recently. Please wait and try again.', 'mrm-product-access' ),
                ),
                429
            );
        }

        try {
            $otp = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Server error generating code.' ), 500 );
        }
        $otp_hash   = password_hash( $otp, PASSWORD_DEFAULT );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( 30 * 60 ) );

        $inserted = $wpdb->insert( $table_otps, array(
            'product_slug'    => $product_slug,
            'purchaser_email' => $normalized_email,
            'email_hash'      => $email_hash,
            'otp_hash'        => $otp_hash,
            'expires_at'      => $expires_at,
            'request_ip'      => $ip,
            'attempt_count'   => 0,
            'created_at'      => gmdate( 'Y-m-d H:i:s' ),
        ) );

        if ( false === $inserted ) {
            $this->mrm_pa_log_access_event( 'otp_insert_failed', array(
                'email'        => $normalized_email,
                'product_slug' => $product_slug,
                'db_error'     => $wpdb->last_error,
            ) );

            return new WP_REST_Response( $generic, 200 );
        }

        $options = $this->get_options();

        $subject = $options['email_subject'] ?? __( 'Sheet Music Access Code', 'mrm-product-access' );

        $custom_body = (string) ( $options['email_body'] ?? '' );
        $custom_body = str_replace( '{{OTP}}', '', $custom_body );
        $custom_body = trim( preg_replace( '/\n{2,}/', "\n\n", $custom_body ) );

        $intro_html = '<p>Your one-time passcode for accessing your purchased piece is below.</p>';

        if ( $custom_body !== '' ) {
            $intro_html .= '<div style="margin-top:10px;">' . nl2br( esc_html( $custom_body ) ) . '</div>';
        }

        $html = $this->mrm_pa_wrap_otp_email_html(
            $subject,
            $intro_html,
            $otp
        );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: Low Brass Lessons <no-reply@lowbrass-lessons.com>',
        );

        $sent = wp_mail( $normalized_email, $subject, $html, $headers );

        $this->mrm_pa_log_access_event( $sent ? 'otp_email_sent' : 'otp_email_failed', array(
            'email'        => $normalized_email,
            'product_slug' => $product_slug,
            'subject'      => $subject,
        ) );

        return new WP_REST_Response(
            array(
                'ok'        => $sent ? true : false,
                'code_sent' => $sent ? true : false,
                'message'   => $sent
                    ? __( 'A one-time access code has been sent to the verified purchase email.', 'mrm-product-access' )
                    : __( 'We verified the purchase, but the access code email could not be sent. Please contact Low Brass Lessons.', 'mrm-product-access' ),
            ),
            $sent ? 200 : 500
        );
    }

    /**
     * Verify the OTP code and return access URL.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function api_verify_otp( $request ) {
        $params         = $request->get_json_params();
        $email          = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
        $product_slug   = isset( $params['product_slug'] ) ? $this->sanitize_product_slug( $params['product_slug'] ) : '';
        $raw_offer_slug = isset( $params['raw_offer_slug'] ) ? $this->sanitize_product_slug( $params['raw_offer_slug'] ) : '';
        $piece_slug     = isset( $params['piece_slug'] ) ? sanitize_title( (string) $params['piece_slug'] ) : '';
        $offer_type     = isset( $params['offer_type'] ) ? sanitize_title( (string) $params['offer_type'] ) : '';

        if ( empty( $product_slug ) && ! empty( $raw_offer_slug ) ) {
            $product_slug = $raw_offer_slug;
        }

        if ( empty( $product_slug ) && ! empty( $piece_slug ) && ! empty( $offer_type ) ) {
            $product_slug = $this->sanitize_product_slug( 'piece-' . $piece_slug . '-' . $offer_type );
        }
        $otp          = isset( $params['otp'] ) ? trim( $params['otp'] ) : '';

        if ( empty( $email ) || empty( $otp ) || ( empty( $product_slug ) && ( empty( $piece_slug ) || empty( $offer_type ) ) ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Invalid request.' ), 400 );
        }

        $normalized_email = strtolower( trim( $email ) );
        $email_hash       = $this->hash_email( $normalized_email );

        if ( $this->mrm_pa_is_sheet_music_email_blocked( $normalized_email ) ) {
            $this->mrm_pa_log_access_event( 'otp_verify_blocked_security_email', array(
                'email'        => $normalized_email,
                'product_slug' => $product_slug,
            ) );

            return $this->mrm_pa_blocked_sheet_music_response();
        }

        global $wpdb;
        $table_otps = $wpdb->prefix . 'mrm_otp_tokens';

        $now = gmdate( 'Y-m-d H:i:s' );
        $candidate_slugs = $this->get_otp_product_slug_candidates(
            $product_slug,
            $piece_slug,
            $offer_type,
            $raw_offer_slug
        );
        $row = null;

        foreach ( $candidate_slugs as $candidate_slug ) {
            $candidate_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $table_otps
                 WHERE product_slug = %s AND email_hash = %s AND expires_at >= %s AND used_at IS NULL
                 ORDER BY id DESC LIMIT 1",
                $candidate_slug,
                $email_hash,
                $now
            ) );

            if ( $candidate_row && ( ! $row || (int) $candidate_row->id > (int) $row->id ) ) {
                $row = $candidate_row;
            }
        }

        if ( ! $row ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Invalid or expired code.' ), 400 );
        }
        $product_slug = $this->sanitize_product_slug( $row->product_slug );
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

        // Re-check access in Payments Hub before granting session cookies.
        if ( ! $this->payments_hub_has_access_for_email( $normalized_email, $product_slug ) ) {
            return new WP_REST_Response(
                array(
                    'ok'      => false,
                    'message' => 'This access code could not be verified for this purchase.',
                ),
                403
            );
        }

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
     * Render the sheet music difficulty timeline shortcode.
     *
     * Usage:
     * [mrm_sheet_music_timeline]
     *
     * @return string
     */
    public function shortcode_sheet_music_timeline() {
        $opts   = $this->get_options();
        $pieces = isset( $opts['pieces'] ) && is_array( $opts['pieces'] ) ? $opts['pieces'] : array();

        $levels = array(
            'level_1' => array(
                'label'       => 'Level 1',
                'items'       => array(),
                'description' => array(
                    'A great fit for developing players, typically late middle school to early high school.',
                    'Features a steady beat and moderate speed that are easy to follow.',
                    'Uses predictable note patterns and achievable jumps between notes.',
                    'Stays in a more comfortable playing range.',
                ),
            ),
            'level_2' => array(
                'label'       => 'Level 2',
                'items'       => array(),
                'description' => array(
                    'Best for advancing high school players who are ready for more challenge.',
                    'Created with faster sections and longer technical passages.',
                    'Uses more rhythmic variety and asks for stronger counting skills.',
                    'Includes bigger note jumps and a wider range than Level 1.',
                ),
            ),
            'level_3' => array(
                'label'       => 'Level 3',
                'items'       => array(),
                'description' => array(
                    'Designed for advanced players, including late high school and college audition preparation.',
                    'Features quick tempo changes and more independent playing.',
                    'Uses advanced rhythms and more complex meter changes.',
                    'Requires the widest range, strongest technique, and greatest musical control.',
                ),
            ),
        );

        foreach ( $pieces as $piece ) {
            if ( ! is_array( $piece ) ) {
                continue;
            }

            $level = sanitize_key( (string) ( $piece['timeline_level'] ?? '' ) );
            if ( ! isset( $levels[ $level ] ) ) {
                continue;
            }

            $title = trim( (string) ( $piece['piece_title'] ?? $piece['title'] ?? '' ) );
            if ( $title === '' ) {
                continue;
            }

            $order = intval( $piece['timeline_order'] ?? 0 );

            $levels[ $level ]['items'][] = array(
                'title' => $title,
                'order' => $order,
            );
        }

        foreach ( $levels as $level_key => $level_data ) {
            usort( $levels[ $level_key ]['items'], function( $a, $b ) {
                $ao = intval( $a['order'] ?? 0 );
                $bo = intval( $b['order'] ?? 0 );

                if ( $ao === $bo ) {
                    return strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
                }

                return $ao <=> $bo;
            } );
        }

        ob_start();
        ?>
        <div class="lbl-sheet-music-page">
          <div class="mrm-timeline-intro">
            <h2>Level System</h2>
            <p>
              Repertoire is organized by player proficiency so students and families can identify music that is developmentally appropriate, musically rewarding, and appropriately challenging at each stage of growth.
            </p>
          </div>

          <div class="mrm-timeline-spacer" aria-hidden="true"></div>

          <div class="timeline">
            <?php foreach ( $levels as $level ) : ?>
              <div class="timeline-section">
                <div class="timeline-items">
                  <?php foreach ( $level['items'] as $item ) : ?>
                    <div class="timeline-item">
                      <p><?php echo esc_html( $item['title'] ); ?></p>
                    </div>
                  <?php endforeach; ?>
                </div>

                <div class="section-title"><?php echo esc_html( $level['label'] ); ?></div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="difficulty-columns">
            <?php foreach ( $levels as $level ) : ?>
              <div class="difficulty-column">
                <ul>
                  <?php foreach ( $level['description'] as $line ) : ?>
                    <li><?php echo esc_html( $line ); ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php

        $this->output_catalog_assets_inline();

        return ob_get_clean();
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

        echo '<section class="mrm-sheet-music-catalog-section" aria-label="Sheet music catalog">';
        echo '  <div class="mrm-sheet-music-catalog-heading">';
        echo '    <h2>Catalog</h2>';
        echo '    <div class="mrm-sheet-music-catalog-divider" aria-hidden="true"></div>';
        echo '  </div>';
        echo '  <div class="mrm-catalog wrapper">';

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

        echo '  </div>';
        echo '</section>';

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
        <div class="wrapper mrm-piece-details-wrapper">
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
                    $offer_type = $this->mrm_pa_infer_sheet_music_offer_type( $offer, $offer_slug );
                    $otp_piece_slug = sanitize_title( (string) $page_slug );
                    $otp_product_slug = $offer_slug;

                    if ( $otp_piece_slug !== '' && $offer_type !== '' ) {
                        $otp_product_slug = sanitize_title( 'piece-' . $otp_piece_slug . '-' . $offer_type );
                    }
                  ?>
                    <div class="offer<?php echo $offer_slug === '' ? ' is-unavailable' : ''; ?>">
                      <div class="offer-row">
                        <div>
                          <div class="offer-title"><?php echo esc_html( $offer_title ); ?></div>
                          <?php if ( $offer_sub !== '' ) : ?>
                            <div class="offer-sub"><?php echo wp_kses_post( wpautop( $offer_sub ) ); ?></div>
                          <?php endif; ?>
                        </div>
                        <?php if ( $offer_price !== '' ) : ?><div class="offer-price"><?php echo esc_html( $offer_price ); ?></div><?php endif; ?>
                      </div>

                      <div class="offer-actions">
                        <div class="mrm-pa-terms-row">
                          <input type="checkbox" class="mrm-pa-terms-check">
                          <label>
                            I agree to the <a href="/terms-of-service/" target="_blank" rel="noopener">Terms of Service</a>.
                            <span class="mrm-pa-terms-subcaption">
                              Includes the digital content license, access restrictions, anti-sharing rules, and non-refund terms.
                            </span>
                          </label>
                        </div>

                        <button type="button" class="buyBtn" data-product-slug="<?php echo esc_attr( $offer_slug ); ?>"<?php disabled( $offer_slug, '' ); ?>>
                          <?php echo esc_html__( 'Buy', 'mrm-product-access' ); ?>
                        </button>
                        <button
                          type="button"
                          class="mrm-otp-open-btn mrmAccessBtn mrm-accessBtn"
                          data-product-slug="<?php echo esc_attr( $otp_product_slug ); ?>"
                          data-raw-offer-slug="<?php echo esc_attr( $offer_slug ); ?>"
                          data-piece-slug="<?php echo esc_attr( $otp_piece_slug ); ?>"
                          data-offer-type="<?php echo esc_attr( $offer_type ); ?>"
                          <?php disabled( $offer_slug, '' ); ?>
                        >
                          <?php echo esc_html__( 'Access', 'mrm-product-access' ); ?>
                        </button>
                      </div>
                      <?php if ( $offer_slug === '' ) : ?>
                        <p class="mrm-pa-unavailable"><?php echo esc_html__( 'This option is temporarily unavailable. Please contact Low Brass Lessons.', 'mrm-product-access' ); ?></p>
                      <?php endif; ?>
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

            <div class="mrm-otpOverlay" aria-hidden="true">
              <div class="modal" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__( 'Access purchased product', 'mrm-product-access' ); ?>">
                <h2><?php echo esc_html__( 'Access Purchased Product', 'mrm-product-access' ); ?></h2>
                <div class="mrm-stepEmail">
                  <label><?php echo esc_html__( 'Email address', 'mrm-product-access' ); ?></label>
                  <input type="email" class="mrm-email" autocomplete="email" required>
                  <button
                    type="button"
                    class="primary mrm-otp-send-btn mrm-sendCodeBtn"
                    data-mrm-otp-action="send"
                    aria-label="<?php echo esc_attr__( 'Send access code', 'mrm-product-access' ); ?>"
                  >
                    <?php echo esc_html__( 'Send Code', 'mrm-product-access' ); ?>
                  </button>
                </div>
                <div class="mrm-stepOtp hidden">
                  <label><?php echo esc_html__( 'Enter Code', 'mrm-product-access' ); ?></label>
                  <input type="text" class="mrm-otp" inputmode="numeric" pattern="\d*" required>
                  <button
                    type="button"
                    class="primary mrm-otp-verify-btn mrm-verifyBtn"
                    data-mrm-otp-action="verify"
                    aria-label="<?php echo esc_attr__( 'Verify access code', 'mrm-product-access' ); ?>"
                  >
                    <?php echo esc_html__( 'Verify', 'mrm-product-access' ); ?>
                  </button>
                </div>
                <div class="message mrm-message" aria-live="polite"></div>
                <div>
                  <button
                    type="button"
                    class="secondary mrm-otp-close-btn mrm-closeBtn"
                    data-mrm-otp-action="close"
                    aria-label="<?php echo esc_attr__( 'Close access code modal', 'mrm-product-access' ); ?>"
                  >
                    <?php echo esc_html__( 'Close', 'mrm-product-access' ); ?>
                  </button>
                </div>
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

          --mrm-font-heading: "Academico", Georgia, "Times New Roman", serif;
          --mrm-font-body: "Source Sans 3", Arial, Helvetica, sans-serif;
        }

        html, body { height: 100%; }

        body {
          margin: 0;
          background: var(--color-bg);
          font-family: "Source Sans 3", Arial, Helvetica, sans-serif;
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
        .mrm-pa-terms-row {
          display: flex;
          align-items: flex-start;
          gap: 8px;
          margin: 10px 0 12px;
          font-size: 14px;
          line-height: 1.35;
        }

        .mrm-pa-terms-row input {
          margin-top: 2px;
          flex: 0 0 auto;
        }

        .mrm-pa-terms-row label {
          display: block;
          margin: 0;
        }

        .mrm-pa-terms-subcaption {
          display: block;
          margin-top: 3px;
          font-size: 12px;
          line-height: 1.35;
          color: #666;
        }

        .pdf-preview {
          width: min(860px, 100%);
          height: clamp(420px, 56vh, 720px);
          border-radius: var(--radius-lg);
          overflow: hidden;
          border: 1px solid rgba(0,0,0,0.12);
          background: linear-gradient(180deg, rgba(0,0,0,0.02), rgba(0,0,0,0.00));
          position: relative;
          cursor: zoom-in;
          user-select: none;
          -webkit-tap-highlight-color: transparent;
          display: flex;
          align-items: center;
          justify-content: center;
        }

        .pdf-preview canvas {
          display: block;
          margin: 0 auto;
        }

        .mrm-pdfOverlay {
          position: fixed;
          inset: 0;
          width: 100vw;
          height: 100vh;
          background: rgba(0,0,0,0.50);
          backdrop-filter: blur(6px);
          -webkit-backdrop-filter: blur(6px);
          display: none;
          align-items: center;
          justify-content: center;
          padding: 18px;
          box-sizing: border-box;
          z-index: 2147483600;
          cursor: zoom-out;
          overscroll-behavior: none;
          overflow: hidden;
        }
        .mrm-pdfOverlay.is-open { display: flex; }

        .mrm-pdfModal {
          width: min(1100px, calc(100vw - 36px));
          height: min(92vh, 1400px);
          border-radius: var(--radius-lg);
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

        .meta .description {
          font-size: 15px;
          line-height: 1.6;
          color: var(--color-text-main);
          margin-bottom: 20px;
        }

        .meta .description p,
        .mrm-long-description p,
        .offer-sub p {
          margin-top: 0;
          margin-bottom: 1em;
        }

        .meta .description p:last-child,
        .mrm-long-description p:last-child,
        .offer-sub p:last-child {
          margin-bottom: 0;
        }

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
          --mrm-audio-bg: #fbf8f2; --mrm-audio-border: rgba(124, 74, 45, 0.22); --mrm-audio-muted: #62483a; --mrm-audio-button: #20170f; --mrm-audio-button-text: #fbf8f2; --mrm-audio-track: rgba(98, 72, 58, 0.22); --mrm-audio-thumb: #20170f;
          border: 1px solid var(--mrm-audio-border); border-radius: var(--radius-md); padding: 12px; margin-bottom: 20px; background: var(--mrm-audio-bg); box-sizing: border-box; box-shadow: 0 10px 24px rgba(32, 23, 15, 0.06);
        }
        .audio-controls { display: grid; grid-template-columns: auto auto minmax(140px, 1fr) auto auto; grid-template-areas: "play current seek duration volume"; align-items: center; gap: 12px; }
        .audio-row-top { display: contents; }
        .audio-row-top .mrm-play, .audio-controls > .mrm-play { grid-area: play; }
        .audio-row-top .mrm-current, .audio-controls > .mrm-current { grid-area: current; }
        .audio-row-top .mrm-duration, .audio-controls > .mrm-duration { grid-area: duration; }
        .audio-row-seek { grid-area: seek; min-width: 0; width: 100%; }
        .audio-row-vol { grid-area: volume; display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
        .play-button { width: 42px; height: 42px; border-radius: var(--radius-sm); background: var(--mrm-audio-button); color: var(--mrm-audio-button-text); border: 1px solid rgba(32, 23, 15, 0.16); cursor: pointer; padding: 0; display: inline-flex; align-items: center; justify-content: center; line-height: 1; flex: 0 0 auto; box-shadow: 0 8px 20px rgba(32, 23, 15, 0.15); }
        .play-button svg { width: 18px; height: 18px; display: block; fill: currentColor; }
        .play-button .icon-pause { display: none; }
        .play-button.is-playing .icon-play { display: none; }
        .play-button.is-playing .icon-pause { display: block; }
        .time { font-size: 13px; color: var(--mrm-audio-muted); min-width: 46px; text-align: center; flex: 0 0 auto; font-variant-numeric: tabular-nums; line-height: 1; }
        .progress, .volume input.mrm-volume { width: 100%; min-width: 0; appearance: none; -webkit-appearance: none; height: 10px; border-radius: 999px; background: var(--mrm-audio-track); cursor: pointer; }
        .progress::-webkit-slider-thumb, .volume input.mrm-volume::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 18px; height: 18px; border-radius: 50%; background: var(--mrm-audio-thumb); border: 2px solid var(--mrm-audio-bg); box-shadow: 0 3px 8px rgba(32, 23, 15, 0.18); }
        .progress::-moz-range-thumb, .volume input.mrm-volume::-moz-range-thumb { width: 18px; height: 18px; border-radius: 50%; background: var(--mrm-audio-thumb); border: 2px solid var(--mrm-audio-bg); box-shadow: 0 3px 8px rgba(32, 23, 15, 0.18); }
        .progress::-moz-range-track, .volume input.mrm-volume::-moz-range-track { height: 10px; border-radius: 999px; background: var(--mrm-audio-track); border: none; }
        .volume { display: inline-flex; align-items: center; gap: 8px; color: var(--mrm-audio-muted); }
        .volume svg { width: 20px; height: 20px; }
        .volume input.mrm-volume { width: 132px; }
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
          .wrapper {
            padding: 14px;
            background: #f6f4ef;
          }

          .product-card {
            grid-template-columns: 1fr;
            padding: 16px;
            border-radius: 24px;
            box-shadow: 0 14px 32px rgba(0,0,0,0.08);
          }

          .pdf-col { align-items: center; }
          .pdf-preview {
            width: 100%;
            height: clamp(360px, 62vh, 640px);
          }
          .pdf-preview canvas { margin: 0 auto; }

          .title-block { text-align: center; }
          .actions {
            justify-content: center;
            gap: 10px;
          }

          .button-primary,
          .button-secondary {
            min-height: 48px;
            border-radius: 16px;
          }

          /* Audio keeps the desktop visual order on mobile.
             Only the sizes compress. */
          .audio-box{
            padding: 9px !important;
          }

          .audio-controls {
            display:grid !important;
            grid-template-columns: auto auto minmax(48px, 1fr) auto auto !important;
            grid-template-areas: "play current seek duration volume" !important;
            align-items:center !important;
            justify-items:stretch !important;
            gap:6px !important;
          }

          .audio-row-top {
            display: contents !important;
          }

          .audio-row-top .time {
            min-width: 30px !important;
          }

          .audio-row-seek {
            grid-area:seek !important;
            width: 100% !important;
            min-width:0 !important;
          }

          .audio-row-seek .progress {
            width: 100% !important;
            height: 7px !important;
          }

          .audio-row-vol {
            grid-area:volume !important;
            width: auto !important;
            justify-content: flex-end !important;
            gap:4px !important;
          }

          .play-button{
            width:34px !important;
            height:34px !important;
            border-radius:9px !important;
          }

          .play-button svg{
            width:14px !important;
            height:14px !important;
          }

          .time{
            font-size:10px !important;
            min-width:30px !important;
          }

          .progress,
          .volume input.mrm-volume{
            height:7px !important;
          }

          .progress::-webkit-slider-thumb,
          .volume input.mrm-volume::-webkit-slider-thumb{
            width:13px !important;
            height:13px !important;
          }

          .progress::-moz-range-thumb,
          .volume input.mrm-volume::-moz-range-thumb{
            width:13px !important;
            height:13px !important;
          }

          .volume svg{
            width:14px !important;
            height:14px !important;
          }

          .volume input.mrm-volume {
            width: clamp(44px, 15vw, 62px) !important;
          }
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
          z-index: 2147483647 !important;
          pointer-events: auto !important;
        }

        .mrm-otpOverlay.is-open {
          display: flex !important;
          pointer-events: auto !important;
        }

        .mrm-otpOverlay .modal {
          position: relative;
          z-index: 2147483647 !important;
          pointer-events: auto !important;
        }

        .mrm-otpOverlay .modal *,
        .mrm-otpOverlay button,
        .mrm-otpOverlay input,
        .mrm-otpOverlay label {
          pointer-events: auto !important;
        }

        .mrm-otpOverlay .mrm-otp-send-btn,
        .mrm-otpOverlay .mrm-otp-verify-btn,
        .mrm-otpOverlay .mrm-sendCodeBtn,
        .mrm-otpOverlay .mrm-verifyBtn {
          display: inline-flex !important;
          align-items: center;
          justify-content: center;
          min-height: 48px;
          cursor: pointer !important;
          touch-action: manipulation;
          user-select: none;
          -webkit-user-select: none;
          pointer-events: auto !important;
        }

        .mrm-otpOverlay .mrm-otp-send-btn:disabled,
        .mrm-otpOverlay .mrm-otp-verify-btn:disabled,
        .mrm-otpOverlay .mrm-sendCodeBtn:disabled,
        .mrm-otpOverlay .mrm-verifyBtn:disabled {
          opacity: 0.62;
          cursor: wait !important;
        }

        .mrm-otpOverlay .modal,
        .mrm-otpOverlay .modal * {
          color: #111111 !important;
        }

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
        }

        .mrm-otpOverlay button.secondary {
          font-weight: 700;
          border: 1px solid var(--color-accent);
          background: transparent;
          cursor: pointer;
        }
        .hidden { display:none; }
        @media (max-width: 620px) {
          .wrapper {
            padding: 0;
          }

          .product-card {
            border-radius: 0;
            border-left: 0;
            border-right: 0;
            box-shadow: none;
            margin-bottom: 14px;
          }

          .pdf-preview {
            height: 320px;
            border-radius: 18px;
          }

          .actions {
            flex-direction: column;
            align-items: stretch;
          }

          .mrm-otpOverlay button.primary,
          .mrm-otpOverlay button.secondary {
            width: 100%;
          }

          .mrm-otpOverlay {
            align-items: flex-end;
          }

          .mrm-otpOverlay .modal {
            transform: none;
            width: 100%;
            max-width: 100%;
            border-radius: 26px 26px 0 0;
            box-shadow: 0 -18px 42px rgba(0,0,0,0.24);
          }

          .mrm-otpOverlay .modal > div:last-child {
            justify-content: stretch;
            flex-direction: column;
          }
        }


/* =========================================================
   MRM Warm Masterclass Popup Patch for Product Access Shortcode
   Applies the Masterclass popup palette to shortcode-generated OTP/PDF popups.
   ========================================================= */

.mrm-otpOverlay .modal,
.mrm-pdfModal { background: #fffaf3 !important; color: #2f2118 !important; border: 1px solid rgba(124, 74, 45, 0.22) !important; box-shadow: 0 24px 70px rgba(27, 20, 15, 0.22) !important; }
.mrm-otpOverlay .modal,
.mrm-otpOverlay .modal * { color: #2f2118 !important; }
.mrm-otpOverlay .modal h2 { background: linear-gradient(135deg, #fffaf3 0%, #f7efe3 100%) !important; color: #2f2118 !important; border-bottom: 1px solid rgba(124, 74, 45, 0.20) !important; }
.mrm-otpOverlay .modal h2::before { background-color: #9a6a2f !important; }
.mrm-otpOverlay label,
.mrm-otpOverlay .message { color: #62483a !important; }
.mrm-otpOverlay input[type="email"],
.mrm-otpOverlay input[type="text"] { background: #fffdf9 !important; color: #2f2118 !important; border: 1px solid rgba(124, 74, 45, 0.22) !important; }
.mrm-otpOverlay .modal > div:last-child { background: #f7efe3 !important; border-top: 1px solid rgba(124, 74, 45, 0.20) !important; }
.mrm-otpOverlay button.primary,
.mrm-otpOverlay .mrm-otp-send-btn,
.mrm-otpOverlay .mrm-otp-verify-btn,
.mrm-otpOverlay .mrm-sendCodeBtn,
.mrm-otpOverlay .mrm-verifyBtn { background: #20170f !important; color: #ffffff !important; }
.mrm-otpOverlay button.secondary,
.mrm-otpOverlay .mrm-closeBtn { background: #ffffff !important; color: #2f2118 !important; border: 1px solid #20170f !important; }

/* =========================================================
   MRM Warm Product Detail Surface Patch
   Applies the warm Masterclass palette only to the single
   piece details shortcode output, not the full catalog listing.
   ========================================================= */

.mrm-piece-details-wrapper .title-block { background: linear-gradient(135deg, #fffaf3 0%, #f7efe3 100%) !important; color: #2f2118 !important; border: 1px solid rgba(124, 74, 45, 0.22) !important; border-radius: 24px; padding: 18px 20px; margin-bottom: 22px; box-shadow: 0 14px 34px rgba(32, 23, 15, 0.08); }
.mrm-piece-details-wrapper .piece-title,
.mrm-piece-details-wrapper .piece-title a { color: #2f2118 !important; }
.mrm-piece-details-wrapper .piece-composer,
.mrm-piece-details-wrapper .piece-composer a { color: #62483a !important; }
.mrm-piece-details-wrapper .piece-subtitle,
.mrm-piece-details-wrapper .meta .subtitle { color: #62483a !important; }
.mrm-piece-details-wrapper .audio-box {
  --mrm-audio-bg: #fbf8f2 !important;
  --mrm-audio-button-text: #fbf8f2 !important;
  background: #fbf8f2 !important;
  border: 1px solid rgba(124, 74, 45, 0.22) !important;
}

.mrm-piece-details-wrapper audio.mrm-audio {
  display:none !important;
  background:#fbf8f2 !important;
}
.mrm-piece-details-wrapper .options-head { background: #fffdf9 !important; border: 1px solid rgba(124, 74, 45, 0.22) !important; border-radius: 22px; padding: 16px 18px; margin-bottom: 16px; box-shadow: 0 12px 30px rgba(32, 23, 15, 0.06); }
.mrm-piece-details-wrapper .options-head h2 { margin: 0; color: #2f2118 !important; }
.mrm-piece-details-wrapper .offer { background: #fffaf3 !important; border: 1px solid rgba(124, 74, 45, 0.22) !important; border-radius: 22px; padding: 14px; box-shadow: 0 18px 46px rgba(32, 23, 15, 0.10); }
.mrm-piece-details-wrapper .offer + .offer { margin-top: 18px; }
.mrm-piece-details-wrapper .offer-row { background: linear-gradient(135deg, #fffaf3 0%, #f7efe3 100%) !important; border: 1px solid rgba(124, 74, 45, 0.18) !important; border-radius: 18px; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.mrm-piece-details-wrapper .offer-title { color: #2f2118 !important; font-weight: 950; }
.mrm-piece-details-wrapper .offer-sub { color: #62483a !important; }
.mrm-piece-details-wrapper .offer-price { background: #f7efe3 !important; color: #2f2118 !important; border: 1px solid rgba(124, 74, 45, 0.22) !important; border-radius: 999px; padding: 8px 13px; font-weight: 950; white-space: nowrap; }
.mrm-piece-details-wrapper .mrm-pa-terms-row { color: #62483a !important; }
.mrm-piece-details-wrapper .buyBtn,
.mrm-piece-details-wrapper .mrmAccessBtn,
.mrm-piece-details-wrapper .mrm-accessBtn { background: #20170f !important; border: 1px solid #20170f !important; color: #ffffff !important; border-radius: 14px; padding: 11px 14px; font-weight: 900; cursor: pointer; }
.mrm-piece-details-wrapper .buyBtn:hover,
.mrm-piece-details-wrapper .mrmAccessBtn:hover,
.mrm-piece-details-wrapper .mrm-accessBtn:hover { background: #3a2a19 !important; }
.mrm-piece-details-wrapper .mrm-preview-more-row .home-btn { display: inline-flex; align-items: center; justify-content: center; background: #fffaf3 !important; color: #2f2118 !important; border: 1px solid rgba(124, 74, 45, 0.22) !important; border-radius: 999px; padding: 10px 14px; font-weight: 900; text-decoration: none !important; box-shadow: 0 12px 30px rgba(32, 23, 15, 0.08); }
.mrm-piece-details-wrapper .mrm-preview-more-row .home-btn:hover,
.mrm-piece-details-wrapper .mrm-preview-more-row .home-btn:focus { background: #f7efe3 !important; color: #2f2118 !important; text-decoration: none !important; }

@media (max-width: 860px) {
  .mrm-piece-details-wrapper .offer-row { align-items: flex-start; flex-direction: column; }
  .mrm-piece-details-wrapper .offer-price { white-space: normal; }
}


/* =========================================================
   MRM Product Access Audio Final Color Lock
   Keeps all plugin-rendered audio players off-white instead of white.
   ========================================================= */

.mrm-product-access-wrapper .audio-box,
.mrm-piece-details-wrapper .audio-box,
.mrm-sheet-music-catalog-section .audio-box,
.mrm-audio-box,
.audio-box.mrm-audio-box {
  --mrm-audio-bg: #fbf8f2 !important;
  --mrm-audio-button-text: #fbf8f2 !important;
  background: #fbf8f2 !important;
  border-color: rgba(124, 74, 45, 0.22) !important;
}

.mrm-product-access-wrapper audio.mrm-audio,
.mrm-piece-details-wrapper audio.mrm-audio,
.mrm-sheet-music-catalog-section audio.mrm-audio,
audio.mrm-audio {
  display:none !important;
  background:#fbf8f2 !important;
}

/* =========================================================
   MRM Sheet Music Catalog Section Patch
   Applies the site off-white background directly through
   the [mrm_sheet_music_catalog] shortcode output.
   ========================================================= */

.mrm-sheet-music-catalog-section {
  width: 100%;
  background: #fbf8f2 !important;
  color: #171512 !important;
  padding: 44px 0 76px;
  box-shadow: 0 0 0 100vmax #fbf8f2;
  clip-path: inset(0 -100vmax);
}

.mrm-sheet-music-catalog-heading {
  max-width: 980px;
  margin: 0 auto 28px;
  padding: 0 28px;
  box-sizing: border-box;
}

.mrm-sheet-music-catalog-heading h2 {
  margin: 0 0 16px;
  color: #171512 !important;
  font-family: var(--mrm-font-heading, "Academico", Georgia, "Times New Roman", serif) !important;
  font-size: clamp(1.75rem, 3.5vw, 2rem);
  font-weight: 600;
  line-height: 1.08;
  letter-spacing: -0.02em;
  text-align: center;
}

.mrm-sheet-music-catalog-divider {
  width: 100%;
  height: 1px;
  background: #d9cfbe;
}

.mrm-sheet-music-catalog-section .mrm-catalog.wrapper {
  width: min(100%, 1040px) !important;
  max-width: 1040px !important;
  margin-left: auto !important;
  margin-right: auto !important;
  padding: 0 28px !important;
  background: transparent !important;
  box-sizing: border-box !important;
  overflow: hidden !important;
}

.mrm-sheet-music-catalog-section .product-card {
  width: 100% !important;
  max-width: 100% !important;
  min-width: 0 !important;
  background: #ffffff !important;
  border-color: #d9cfbe !important;
  box-sizing: border-box !important;
  overflow: hidden !important;
  display: grid !important;
  grid-template-columns: minmax(260px, 420px) minmax(0, 1fr) !important;
  align-items: start !important;
}

.mrm-sheet-music-catalog-section .product-card *,
.mrm-sheet-music-catalog-section .product-card *::before,
.mrm-sheet-music-catalog-section .product-card *::after {
  box-sizing: border-box !important;
}

.mrm-sheet-music-catalog-section .pdf-col,
.mrm-sheet-music-catalog-section .meta,
.mrm-sheet-music-catalog-section .title-block,
.mrm-sheet-music-catalog-section .description,
.mrm-sheet-music-catalog-section .audio-box,
.mrm-sheet-music-catalog-section .mrm-view-options-row {
  min-width: 0 !important;
  max-width: 100% !important;
}

.mrm-sheet-music-catalog-section .pdf-preview {
  width: 100% !important;
  max-width: 100% !important;
  min-width: 0 !important;
  overflow: hidden !important;
}

.mrm-sheet-music-catalog-section .pdf-preview canvas {
  max-width: 100% !important;
  height: auto !important;
}

.mrm-sheet-music-catalog-section .piece-title,
.mrm-sheet-music-catalog-section .piece-title a,
.mrm-sheet-music-catalog-section .piece-composer,
.mrm-sheet-music-catalog-section .piece-subtitle,
.mrm-sheet-music-catalog-section .subtitle,
.mrm-sheet-music-catalog-section .description {
  max-width: 100% !important;
  overflow-wrap: anywhere !important;
  word-break: normal !important;
}

.mrm-sheet-music-catalog-section .description img,
.mrm-sheet-music-catalog-section .description iframe,
.mrm-sheet-music-catalog-section .description video {
  max-width: 100% !important;
  height: auto !important;
}

.mrm-sheet-music-catalog-section .audio-controls {
  min-width: 0 !important;
  max-width: 100% !important;
}

.mrm-sheet-music-catalog-section .audio-row-seek {
  min-width: 0 !important;
}

.mrm-sheet-music-catalog-section .volume input.mrm-volume {
  width: clamp(76px, 12vw, 132px) !important;
  max-width: 100% !important;
}

.mrm-sheet-music-catalog-section .mrm-view-options-row {
  justify-content: flex-end !important;
}

.mrm-sheet-music-catalog-section .mrm-view-options-btn {
  max-width: 100% !important;
  white-space: normal !important;
  text-align: center !important;
}

@media (max-width: 860px) {
  .mrm-sheet-music-catalog-section {
    padding: 34px 0 58px;
    overflow-x: hidden !important;
  }

  .mrm-sheet-music-catalog-heading {
    padding: 0 14px;
    margin-bottom: 20px;
  }

  .mrm-sheet-music-catalog-heading h2 {
    font-size: clamp(1.55rem, 7vw, 1.9rem);
    line-height: 1.08;
  }

  .mrm-sheet-music-catalog-section .mrm-catalog.wrapper {
    width: 100% !important;
    max-width: 100% !important;
    padding-left: 14px !important;
    padding-right: 14px !important;
    background: transparent !important;
    overflow: hidden !important;
  }

  .mrm-sheet-music-catalog-section .product-card {
    width: 100% !important;
    max-width: 100% !important;
    display: grid !important;
    grid-template-columns: 1fr !important;
    gap: 18px !important;
    padding: 16px !important;
    overflow: hidden !important;
  }

  .mrm-sheet-music-catalog-section .pdf-preview {
    width: 100% !important;
    max-width: 100% !important;
    height: clamp(260px, 68vw, 380px) !important;
  }

  .mrm-sheet-music-catalog-section .title-block,
  .mrm-sheet-music-catalog-section .meta {
    text-align: center !important;
  }

  .mrm-sheet-music-catalog-section .piece-composer {
    margin-bottom: 1.5em !important;
  }

  .mrm-sheet-music-catalog-section .description {
    text-align: left !important;
  }

  .mrm-sheet-music-catalog-section .audio-box {
    padding: 9px !important;
  }

  .mrm-sheet-music-catalog-section .audio-controls {
    display:grid !important;
    grid-template-columns: auto auto minmax(48px, 1fr) auto auto !important;
    grid-template-areas: "play current seek duration volume" !important;
    align-items:center !important;
    justify-items:stretch !important;
    gap:6px !important;
  }

  .mrm-sheet-music-catalog-section .audio-row-top {
    display: contents !important;
  }

  .mrm-sheet-music-catalog-section .audio-row-seek {
    grid-area:seek !important;
    width: 100% !important;
    min-width:0 !important;
  }

  .mrm-sheet-music-catalog-section .audio-row-vol {
    grid-area:volume !important;
    width: auto !important;
    justify-content: flex-end !important;
    gap:4px !important;
  }

  .mrm-sheet-music-catalog-section .play-button {
    width:34px !important;
    height:34px !important;
    border-radius:9px !important;
  }

  .mrm-sheet-music-catalog-section .play-button svg {
    width:14px !important;
    height:14px !important;
  }

  .mrm-sheet-music-catalog-section .time {
    font-size:10px !important;
    min-width:30px !important;
  }

  .mrm-sheet-music-catalog-section .progress,
  .mrm-sheet-music-catalog-section .volume input.mrm-volume {
    height:7px !important;
  }

  .mrm-sheet-music-catalog-section .progress::-webkit-slider-thumb,
  .mrm-sheet-music-catalog-section .volume input.mrm-volume::-webkit-slider-thumb {
    width:13px !important;
    height:13px !important;
  }

  .mrm-sheet-music-catalog-section .progress::-moz-range-thumb,
  .mrm-sheet-music-catalog-section .volume input.mrm-volume::-moz-range-thumb {
    width:13px !important;
    height:13px !important;
  }

  .mrm-sheet-music-catalog-section .volume svg {
    width:14px !important;
    height:14px !important;
  }

  .mrm-sheet-music-catalog-section .volume input.mrm-volume {
    width:clamp(44px, 15vw, 62px) !important;
  }

  .mrm-sheet-music-catalog-section .mrm-view-options-row {
    justify-content: center !important;
  }

  .mrm-sheet-music-catalog-section .mrm-view-options-btn {
    width: 100% !important;
  }
}

@media (min-width: 861px) and (max-width: 1040px) {
  .mrm-sheet-music-catalog-section .mrm-catalog.wrapper {
    padding-left: 20px !important;
    padding-right: 20px !important;
  }

  .mrm-sheet-music-catalog-section .product-card {
    grid-template-columns: minmax(220px, 36vw) minmax(0, 1fr) !important;
    gap: 20px !important;
  }

  .mrm-sheet-music-catalog-section .pdf-preview {
    height: clamp(320px, 44vw, 430px) !important;
  }

  .mrm-sheet-music-catalog-section .audio-controls {
    grid-template-columns: auto auto minmax(90px, 1fr) auto auto !important;
    grid-template-areas: "play current seek duration volume" !important;
  }

  .mrm-sheet-music-catalog-section .audio-row-vol {
    width: auto !important;
    justify-content: flex-end !important;
  }
}

.mrm-sheet-music-catalog-section .pdf-preview {
  width: min(860px, 100%) !important;
  height: clamp(420px, 56vh, 720px) !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
}

@media (max-width: 700px) {
  .mrm-sheet-music-catalog-section .pdf-preview {
    width: 100% !important;
    height: clamp(360px, 62vh, 640px) !important;
  }
}


@media (max-width: 420px) {
  .mrm-sheet-music-catalog-section .mrm-catalog.wrapper {
    padding-left: 10px !important;
    padding-right: 10px !important;
  }

  .mrm-sheet-music-catalog-section .product-card {
    padding: 12px !important;
    border-radius: 18px !important;
  }

  .mrm-sheet-music-catalog-section .piece-title {
    font-size: clamp(20px, 7vw, 26px) !important;
    line-height: 1.05 !important;
  }

  .mrm-sheet-music-catalog-section .audio-box {
    padding: 8px !important;
  }

  .mrm-sheet-music-catalog-section .play-button {
    width: 31px !important;
    height: 31px !important;
  }

  .mrm-sheet-music-catalog-section .play-button svg {
    width: 13px !important;
    height: 13px !important;
  }

  .mrm-sheet-music-catalog-section .time {
    font-size: 9px !important;
    min-width: 28px !important;
  }

  .mrm-sheet-music-catalog-section .audio-controls {
    gap: 5px !important;
  }

  .mrm-sheet-music-catalog-section .volume input.mrm-volume {
    width: 42px !important;
  }
}



/* =========================================================
   MRM Sheet Music Timeline Shortcode
   Used by [mrm_sheet_music_timeline]
   ========================================================= */

.lbl-sheet-music-page {
  font-family: var(--mrm-font-body, "Source Sans 3", Arial, Helvetica, sans-serif);
  padding: 80px 60px;
  background: #fbf8f2;
  overflow-x: hidden;
  box-sizing: border-box;
  width: 100%;
  box-shadow: 0 0 0 100vmax #fbf8f2;
  clip-path: inset(0 -100vmax);
}

.lbl-sheet-music-page *,
.lbl-sheet-music-page *::before,
.lbl-sheet-music-page *::after { box-sizing: border-box; }

.lbl-sheet-music-page .mrm-timeline-intro {
  max-width: 880px;
  margin: 0 auto;
  padding: 0 18px;
  text-align: center;
}

.lbl-sheet-music-page .mrm-timeline-intro h2 {
  margin: 0 0 16px;
  color: #171512 !important;
  font-family: var(--mrm-font-heading, "Academico", Georgia, "Times New Roman", serif) !important;
  font-size: clamp(1.75rem, 3.5vw, 2rem);
  font-weight: 600;
  line-height: 1.08;
  letter-spacing: -0.02em;
}

.lbl-sheet-music-page .mrm-timeline-intro p {
  max-width: 64ch;
  margin: 0 auto;
  color: #5f5851 !important;
  font-family: var(--mrm-font-body, "Source Sans 3", Arial, Helvetica, sans-serif) !important;
  font-size: 1rem;
  line-height: 1.5;
  font-weight: 400;
}

.lbl-sheet-music-page .mrm-timeline-spacer {
  display: block;
  width: 100%;
  height: 200px;
  flex: 0 0 auto;
}

.lbl-sheet-music-page .timeline {
  display: flex;
  width: 100%;
  max-width: 1000px;
  margin: 0 auto 0;
  position: relative;
}
.lbl-sheet-music-page .timeline::before { content: ""; position: absolute; left: -18px; top: -7px; width: 0; height: 0; border-top: 8px solid transparent; border-bottom: 8px solid transparent; border-right: 14px solid #171512; }
.lbl-sheet-music-page .timeline::after { content: ""; position: absolute; right: -18px; top: -7px; width: 0; height: 0; border-top: 8px solid transparent; border-bottom: 8px solid transparent; border-left: 14px solid #171512; }
.lbl-sheet-music-page .timeline-section { flex: 1; position: relative; height: 130px; border-top: 2px solid #171512; overflow: visible; }
.lbl-sheet-music-page .timeline-section:not(:last-child)::after { content: ""; position: absolute; right: 0; top: -14px; width: 2px; height: 28px; background: #171512; }
.lbl-sheet-music-page .section-title { position: absolute; top: 30px; left: 50%; transform: translateX(-50%); font-weight: 800; font-size: 24px; white-space: nowrap; text-align: center; color: #171512; z-index: 5; }
.lbl-sheet-music-page .timeline-items { position: absolute; top: 0; left: 0; width: 100%; display: flex; justify-content: space-evenly; align-items: flex-start; overflow: visible; }
.lbl-sheet-music-page .timeline-item { position: relative; width: 0; height: 0; overflow: visible; }
.lbl-sheet-music-page .timeline-item::before { content: ""; position: absolute; left: -4px; top: -5px; width: 8px; height: 8px; background-color: #171512; border-radius: 50%; z-index: 3; }
.lbl-sheet-music-page .timeline-item p { position: absolute; left: 10px; top: -36px; transform: rotate(-60deg); transform-origin: bottom left; width: 180px; margin: 0; font-size: 13px; white-space: nowrap; text-align: left; color: #171512; z-index: 4; }
.lbl-sheet-music-page .difficulty-columns { display: flex; width: 100%; max-width: 1000px; margin: 10px auto 0; background: transparent; }
.lbl-sheet-music-page .difficulty-column { flex: 1; padding: 0 22px; background: transparent; }
.lbl-sheet-music-page .difficulty-column:not(:last-child) { border-right: 2px solid #171512; }
.lbl-sheet-music-page .difficulty-column ul { margin: 0; padding-left: 20px; }
.lbl-sheet-music-page .difficulty-column li { font-size: 15px; line-height: 1.5; margin-bottom: 10px; color: #171512; }

@media (max-width: 850px) {
  .lbl-sheet-music-page { padding: 36px 8px; overflow-x: hidden; }

  .lbl-sheet-music-page .mrm-timeline-intro {
    margin: 0 auto;
    padding: 0 14px;
  }

  .lbl-sheet-music-page .mrm-timeline-spacer {
    height: 200px;
  }

  .lbl-sheet-music-page .mrm-timeline-intro h2 {
    font-size: clamp(1.55rem, 7vw, 1.9rem);
    line-height: 1.08;
  }

  .lbl-sheet-music-page .mrm-timeline-intro p {
    font-size: 0.96rem;
    line-height: 1.45;
  }

  .lbl-sheet-music-page .timeline {
    width: calc(100% - 20px);
    max-width: none;
    margin: 0 auto 0;
  }
  .lbl-sheet-music-page .timeline::before { left: -9px; top: -4px; border-top-width: 5px; border-bottom-width: 5px; border-right-width: 8px; }
  .lbl-sheet-music-page .timeline::after { right: -9px; top: -4px; border-top-width: 5px; border-bottom-width: 5px; border-left-width: 8px; }
  .lbl-sheet-music-page .timeline-section { height: 82px; border-top-width: 1.5px; }
  .lbl-sheet-music-page .timeline-section:not(:last-child)::after { top: -9px; width: 1.5px; height: 18px; }
  .lbl-sheet-music-page .section-title { top: 20px; font-size: clamp(10px, 3vw, 16px); }
  .lbl-sheet-music-page .timeline-item::before { left: -3px; top: -4px; width: 6px; height: 6px; }
  .lbl-sheet-music-page .timeline-item p { left: 6px; top: -25px; width: 105px; font-size: clamp(6px, 2.1vw, 10px); line-height: 1.1; }
  .lbl-sheet-music-page .difficulty-columns { width: 100%; max-width: none; margin: 4px auto 0; display: flex; }
  .lbl-sheet-music-page .difficulty-column { flex: 1; padding: 0 5px; }
  .lbl-sheet-music-page .difficulty-column:not(:last-child) { border-right: 1px solid #171512; }
  .lbl-sheet-music-page .difficulty-column ul { padding-left: 10px; }
  .lbl-sheet-music-page .difficulty-column li { font-size: clamp(6px, 1.9vw, 10px); line-height: 1.35; margin-bottom: 5px; }
}

@media (max-width: 480px) {
  .lbl-sheet-music-page { padding: 32px 5px; }

  .lbl-sheet-music-page .mrm-timeline-intro {
    margin-bottom: 0;
    padding: 0 12px;
  }

  .lbl-sheet-music-page .mrm-timeline-spacer {
    height: 200px;
  }

  .lbl-sheet-music-page .timeline {
    width: calc(100% - 16px);
    margin-top: 0;
  }
  .lbl-sheet-music-page .timeline-section { height: 72px; }
  .lbl-sheet-music-page .section-title { top: 17px; font-size: clamp(9px, 3vw, 13px); }
  .lbl-sheet-music-page .timeline-item p { left: 5px; top: -22px; width: 90px; font-size: clamp(5.5px, 2vw, 8px); }
  .lbl-sheet-music-page .difficulty-column { padding: 0 3px; }
  .lbl-sheet-music-page .difficulty-column ul { padding-left: 8px; }
  .lbl-sheet-music-page .difficulty-column li { font-size: clamp(5.5px, 1.85vw, 8px); line-height: 1.3; margin-bottom: 4px; }
}

@media (max-width: 380px) {
  .lbl-sheet-music-page { padding-left: 4px; padding-right: 4px; }
  .lbl-sheet-music-page .timeline { margin-top: 0; }
  .lbl-sheet-music-page .timeline-item p { width: 82px; font-size: 5.5px; }
  .lbl-sheet-music-page .difficulty-column { padding: 0 2px; }
  .lbl-sheet-music-page .difficulty-column ul { padding-left: 7px; }
  .lbl-sheet-music-page .difficulty-column li { font-size: 5.5px; line-height: 1.25; }
}

/* =========================================================
   MRM Audio Mobile Layout Lock
   Keeps every custom Product Access audio player in the same
   visual order on mobile. Mobile only scales size; it does not
   rearrange controls into new rows.
   ========================================================= */

@media (max-width: 860px) {
  .mrm-product-access-wrapper .audio-controls,
  .mrm-piece-details-wrapper .audio-controls,
  .mrm-sheet-music-catalog-section .audio-controls,
  .audio-box.mrm-audio-box .audio-controls,
  .mrm-audio-box .audio-controls {
    display:grid !important;
    grid-template-columns: auto auto minmax(48px, 1fr) auto auto !important;
    grid-template-areas: "play current seek duration volume" !important;
    align-items:center !important;
    justify-items:stretch !important;
    gap:6px !important;
  }

  .mrm-product-access-wrapper .audio-row-top,
  .mrm-piece-details-wrapper .audio-row-top,
  .mrm-sheet-music-catalog-section .audio-row-top,
  .audio-box.mrm-audio-box .audio-row-top,
  .mrm-audio-box .audio-row-top {
    display:contents !important;
    width:auto !important;
  }

  .mrm-product-access-wrapper .audio-row-seek,
  .mrm-piece-details-wrapper .audio-row-seek,
  .mrm-sheet-music-catalog-section .audio-row-seek,
  .audio-box.mrm-audio-box .audio-row-seek,
  .mrm-audio-box .audio-row-seek {
    grid-area:seek !important;
    width:100% !important;
    min-width:0 !important;
  }

  .mrm-product-access-wrapper .audio-row-vol,
  .mrm-piece-details-wrapper .audio-row-vol,
  .mrm-sheet-music-catalog-section .audio-row-vol,
  .audio-box.mrm-audio-box .audio-row-vol,
  .mrm-audio-box .audio-row-vol {
    grid-area:volume !important;
    width:auto !important;
    justify-content:flex-end !important;
    gap:4px !important;
  }

  .mrm-product-access-wrapper .audio-box,
  .mrm-piece-details-wrapper .audio-box,
  .mrm-sheet-music-catalog-section .audio-box,
  .audio-box.mrm-audio-box,
  .mrm-audio-box {
    padding:9px !important;
  }

  .mrm-product-access-wrapper .play-button,
  .mrm-piece-details-wrapper .play-button,
  .mrm-sheet-music-catalog-section .play-button,
  .audio-box.mrm-audio-box .play-button,
  .mrm-audio-box .play-button {
    width:34px !important;
    height:34px !important;
    border-radius:9px !important;
  }

  .mrm-product-access-wrapper .play-button svg,
  .mrm-piece-details-wrapper .play-button svg,
  .mrm-sheet-music-catalog-section .play-button svg,
  .audio-box.mrm-audio-box .play-button svg,
  .mrm-audio-box .play-button svg {
    width:14px !important;
    height:14px !important;
  }

  .mrm-product-access-wrapper .time,
  .mrm-piece-details-wrapper .time,
  .mrm-sheet-music-catalog-section .time,
  .audio-box.mrm-audio-box .time,
  .mrm-audio-box .time {
    font-size:10px !important;
    min-width:30px !important;
  }

  .mrm-product-access-wrapper .progress,
  .mrm-product-access-wrapper .volume input.mrm-volume,
  .mrm-piece-details-wrapper .progress,
  .mrm-piece-details-wrapper .volume input.mrm-volume,
  .mrm-sheet-music-catalog-section .progress,
  .mrm-sheet-music-catalog-section .volume input.mrm-volume,
  .audio-box.mrm-audio-box .progress,
  .audio-box.mrm-audio-box .volume input.mrm-volume,
  .mrm-audio-box .progress,
  .mrm-audio-box .volume input.mrm-volume {
    height:7px !important;
  }

  .mrm-product-access-wrapper .volume input.mrm-volume,
  .mrm-piece-details-wrapper .volume input.mrm-volume,
  .mrm-sheet-music-catalog-section .volume input.mrm-volume,
  .audio-box.mrm-audio-box .volume input.mrm-volume,
  .mrm-audio-box .volume input.mrm-volume {
    width:clamp(44px, 15vw, 62px) !important;
  }

  .mrm-product-access-wrapper .volume svg,
  .mrm-piece-details-wrapper .volume svg,
  .mrm-sheet-music-catalog-section .volume svg,
  .audio-box.mrm-audio-box .volume svg,
  .mrm-audio-box .volume svg {
    width:14px !important;
    height:14px !important;
  }
}

/* =========================================================
   MRM Sheet Music Catalog Containment Lock
   Prevents shortcode cards from bleeding outside their card
   at narrow desktop, tablet, and mobile widths.
   ========================================================= */

.mrm-sheet-music-catalog-section,
.mrm-sheet-music-catalog-section * {
  max-width: 100%;
}

.mrm-sheet-music-catalog-section {
  overflow-x: hidden !important;
}

.mrm-sheet-music-catalog-section .product-card {
  contain: layout paint;
}

.mrm-sheet-music-catalog-section .pdf-col,
.mrm-sheet-music-catalog-section .meta {
  overflow: hidden !important;
}

.mrm-sheet-music-catalog-section .audio-box {
  overflow: hidden !important;
}

.mrm-sheet-music-catalog-section .progress {
  min-width: 0 !important;
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
            const PIECE_SLUG = piece.dataset.pieceSlug || piece.dataset.productSlug || "";
            const PRODUCT_SLUG = piece.dataset.productSlug || PIECE_SLUG;
            let selectedProductSlug = "";
            let selectedOtpContext = {
              productSlug: "",
              rawOfferSlug: "",
              pieceSlug: PIECE_SLUG,
              offerType: ""
            };
            const PDF_URL = piece.dataset.pdfUrl || "";
            const PREVIEW_PAGE = Number(piece.dataset.previewPage || "1");

            const apiBase = (piece.dataset.apiBase || "/wp-json/mrm/v1").trim().startsWith("http")
              ? piece.dataset.apiBase.trim()
              : (window.location.origin + (piece.dataset.apiBase || "/wp-json/mrm/v1"));

            function mrmPaApiUrl(path) {
              return apiBase.replace(/\/+$/, '') + '/' + String(path || '').replace(/^\/+/, '');
            }

            function mrmPaLooksLikeEmail(value) {
              return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
            }

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
                } catch (e) {
                  alert('We could not play this preview. Please refresh and try again.');
                }
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

            if (!otpOverlay || !stepEmail || !stepOtp || !emailInput || !otpInput || !sendBtn || !verifyBtn || !messageDiv || !closeBtn) {
              return;
            }

            // Force a visible, consistent close label (base behavior)
            if (closeBtn) {
              closeBtn.textContent = 'Close';
              closeBtn.setAttribute('type', 'button');
              closeBtn.setAttribute('aria-label', 'Close');
            }

            /*
             * OTP button hardening:
             * This capture-level listener runs before older direct button handlers.
             * It prevents stale/double-patched handlers from blocking Send Code.
             */
            if (piece.dataset.mrmOtpDelegatedBound !== '1') {
              piece.dataset.mrmOtpDelegatedBound = '1';

              piece.addEventListener('click', async function(event) {
                const sendClick = event.target.closest('.mrm-otp-send-btn, .mrm-sendCodeBtn');
                const verifyClick = event.target.closest('.mrm-otp-verify-btn, .mrm-verifyBtn');
                const closeClick = event.target.closest('.mrm-otp-close-btn, .mrm-closeBtn');

                if (!sendClick && !verifyClick && !closeClick) return;

                event.preventDefault();
                event.stopPropagation();
                if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();

                if (closeClick) {
                  closeOtpModal();
                  return;
                }

                const email = (emailInput.value || '').trim();
                const productSlug = String(selectedOtpContext.productSlug || selectedProductSlug || PRODUCT_SLUG || PIECE_SLUG || '').trim().toLowerCase();
                const rawOfferSlug = String(selectedOtpContext.rawOfferSlug || selectedOtpContext.productSlug || productSlug || '').trim().toLowerCase();
                const pieceSlug = String(selectedOtpContext.pieceSlug || PIECE_SLUG || '').trim().toLowerCase();
                const offerType = String(selectedOtpContext.offerType || '').trim().toLowerCase();

                if (!email) { messageDiv.textContent = 'Please enter your email.'; return; }
                if (!mrmPaLooksLikeEmail(email)) { messageDiv.textContent = 'Please enter a valid email address.'; return; }
                if (!productSlug && (!pieceSlug || !offerType)) {
                  messageDiv.textContent = 'This access option is temporarily unavailable. Please contact Low Brass Lessons.';
                  return;
                }

                if (sendClick) {
                  sendClick.disabled = true;
                  sendClick.setAttribute('aria-busy', 'true');
                  messageDiv.textContent = 'Sending code...';
                  try {
                    const response = await fetch(mrmPaApiUrl('request-otp'), {
                      method: 'POST', credentials: 'same-origin',
                      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                      body: JSON.stringify({ email, product_slug: productSlug, raw_offer_slug: rawOfferSlug, piece_slug: pieceSlug, offer_type: offerType })
                    });
                    let data = {};
                    try { data = await response.json(); } catch (jsonError) { data = {}; }
                    if (!response.ok || !data.ok || data.code_sent !== true) {
                      messageDiv.textContent = data.message || 'We could not verify a completed purchase for that email and piece.';
                      return;
                    }

                    messageDiv.textContent = data.message || 'A one-time access code has been sent to the verified purchase email.';
                    stepEmail.classList.add('hidden');
                    stepOtp.classList.remove('hidden');
                    window.setTimeout(function () { otpInput.focus(); }, 50);
                  } catch (error) {
                    messageDiv.textContent = 'We could not send an access code right now. Please try again or contact Low Brass Lessons.';
                  } finally {
                    sendClick.disabled = false;
                    sendClick.removeAttribute('aria-busy');
                  }
                  return;
                }

                const otp = (otpInput.value || '').trim();
                if (!otp) { messageDiv.textContent = 'Enter the code you received.'; return; }
                verifyClick.disabled = true;
                verifyClick.setAttribute('aria-busy', 'true');
                messageDiv.textContent = 'Verifying...';
                try {
                  const response = await fetch(mrmPaApiUrl('verify-otp'), {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ email, product_slug: productSlug, raw_offer_slug: rawOfferSlug, piece_slug: pieceSlug, offer_type: offerType, otp })
                  });
                  let data = {};
                  try { data = await response.json(); } catch (jsonError) { data = {}; }
                  if (!response.ok) { messageDiv.textContent = data.message || 'We could not verify that code right now. Please try again.'; return; }
                  if (data.ok && data.access_url) { window.location.href = data.access_url; return; }
                  messageDiv.textContent = data.message || 'Verified, but no access link was returned. Please contact Low Brass Lessons.';
                } catch (error) {
                  messageDiv.textContent = 'We could not verify that code right now. Please try again.';
                } finally {
                  verifyClick.disabled = false;
                  verifyClick.removeAttribute('aria-busy');
                }
              }, true);
            }

            function openOtpModal(){
              const pdfOverlay = piece.querySelector('.mrm-pdfOverlay');
              if (pdfOverlay) {
                pdfOverlay.classList.remove('is-open');
                pdfOverlay.setAttribute('aria-hidden', 'true');
              }

              if (typeof overlayOpen !== 'undefined') {
                overlayOpen = false;
              }

              if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.removeAttribute('disabled');
                sendBtn.style.pointerEvents = 'auto';
                sendBtn.style.cursor = 'pointer';
              }

              if (verifyBtn) {
                verifyBtn.disabled = false;
                verifyBtn.removeAttribute('disabled');
                verifyBtn.style.pointerEvents = 'auto';
                verifyBtn.style.cursor = 'pointer';
              }

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
            piece.querySelectorAll('.buyBtn').forEach((buyBtn) => {
              buyBtn.addEventListener('click', function(){
                selectedProductSlug = buyBtn.getAttribute('data-product-slug') || PRODUCT_SLUG || PIECE_SLUG;
                if (!selectedProductSlug) {
                  alert('This purchase option is temporarily unavailable. Please contact Low Brass Lessons.');
                  return;
                }
                const offerBox = buyBtn.closest('.offer, .offer-card, .mrm-pa-offer, .purchase-option') || buyBtn.parentElement;
                const termsCheck = offerBox ? offerBox.querySelector('.mrm-pa-terms-check') : null;
                if (!termsCheck || !termsCheck.checked) {
                  alert('Please agree to the Terms of Service before purchasing.');
                  return;
                }
                window.dispatchEvent(new CustomEvent('mrm-product-access:purchase', {
                  detail: { productSlug: selectedProductSlug, trigger: buyBtn }
                }));
              });
            });
            piece.querySelectorAll('.mrm-otp-open-btn, .mrm-accessBtn, .mrmAccessBtn').forEach((accessBtn) => {
              if (accessBtn.dataset.mrmOtpBound === '1') return;
              accessBtn.dataset.mrmOtpBound = '1';

              accessBtn.addEventListener('click', function(event){
                event.preventDefault();

                const productSlug = String(
                  accessBtn.getAttribute('data-product-slug') ||
                  PRODUCT_SLUG ||
                  PIECE_SLUG ||
                  ''
                ).trim().toLowerCase();

                const rawOfferSlug = String(
                  accessBtn.getAttribute('data-raw-offer-slug') ||
                  accessBtn.getAttribute('data-product-slug') ||
                  ''
                ).trim().toLowerCase();

                const pieceSlug = String(
                  accessBtn.getAttribute('data-piece-slug') ||
                  PIECE_SLUG ||
                  ''
                ).trim().toLowerCase();

                const offerType = String(
                  accessBtn.getAttribute('data-offer-type') ||
                  ''
                ).trim().toLowerCase();

                selectedProductSlug = productSlug;
                selectedOtpContext = {
                  productSlug: productSlug,
                  rawOfferSlug: rawOfferSlug,
                  pieceSlug: pieceSlug,
                  offerType: offerType
                };

                if (!selectedOtpContext.productSlug && (!selectedOtpContext.pieceSlug || !selectedOtpContext.offerType)) {
                  alert('This access option is temporarily unavailable. Please contact Low Brass Lessons.');
                  return;
                }

                openOtpModal();
              });
            });
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
        $product_slug = isset( $_GET['product_slug'] ) ? $this->sanitize_product_slug( $_GET['product_slug'] ) : '';
        $asset_type   = isset( $_GET['asset_type'] ) ? sanitize_key( $_GET['asset_type'] ) : '';
        $delivery_mode = strtolower( sanitize_text_field( (string) $request->get_param('delivery_mode') ) );
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

        if ( $this->mrm_pa_is_sheet_music_email_hash_blocked( (string) $email_hash ) ) {
            return $this->mrm_pa_blocked_sheet_music_response();
        }

        // ✅ Hub is the ONLY source of truth for access (download time enforcement).
        $access_context = $this->payments_hub_access_context( (string) $email_hash, $product_slug );
        if ( empty( $access_context['has_access'] ) ) {
            return new WP_REST_Response( array( 'error' => 'Unauthorized.' ), 403 );
        }

        if ( $asset_type === 'audio' && empty( $access_context['allow_audio_download'] ) ) {
            // Allow playback/streaming, but not explicit downloads, for subscription-based access.
            if ( $delivery_mode !== 'stream' ) {
                return new WP_REST_Response( array( 'error' => 'Audio downloads are not included with this access type.' ), 403 );
            }
        }

        $tracks = $this->get_tracks_for_slug( $product_slug );
        if ( empty( $tracks ) ) {
            return new WP_REST_Response( array( 'error' => 'File not found.' ), 404 );
        }

        $file = '';

        // Tracks are indexed by row position (0..24).
        if ( $track !== '' && ctype_digit( (string) $track ) ) {
            $i = intval( $track );
            if ( isset( $tracks[ $i ] ) && is_array( $tracks[ $i ] ) && ! empty( $tracks[ $i ]['url'] ) ) {
                $file = $this->resolve_local_path_from_url_or_path( (string) $tracks[ $i ]['url'] );
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
