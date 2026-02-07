
<?php
/*
 * Plugin Name: MRM Lesson Scheduler
 * Description: Lesson scheduling + instructor directory for Music at the Right Moment.
 * Version: 1.5.1
 * Author: Your Name
 *
 * Google Calendar integration:
 * - Service Account JSON stored in wp_options (autoload disabled)
 * - /availability supports:
 *   Mode A: Working hours minus Busy blocks (classic)
 *   Mode B: "Free" events define availability windows minus Busy blocks (YouCanBookMe-style)
 *
 * SECURITY NOTE:
 * Service Account JSON contains a private key. Restrict admin access and keep backups safe.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main plugin class. Encapsulates all functionality for the scheduler.
 */
class MRM_Lesson_Scheduler {
    protected static $instance;
    protected $option_key = 'mrm_scheduler_settings';
    protected $options = array();
    const DB_VERSION = '1.5.1';
    const CAPABILITY = 'manage_options';
    // Google endpoints
    const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const GOOGLE_FREEBUSY_URL = 'https://www.googleapis.com/calendar/v3/freeBusy';
    // Google events list endpoint
    const GOOGLE_EVENTS_LIST_URL = 'https://www.googleapis.com/calendar/v3/calendars/%s/events';

    // Get a single Google Calendar event
    protected function google_get_event( $calendar_id, $event_id ) {
        // Prevent double-encoding of calendar IDs stored as %40, etc.
        $calendar_id = rawurldecode( trim( (string) $calendar_id ) );

        if ( ! $this->google_is_configured() ) {
            return new WP_Error( 'google_not_configured', 'Google Calendar is not configured.' );
        }

        $access_token = $this->google_get_access_token();
        if ( is_wp_error( $access_token ) ) return $access_token;

        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( (string) $calendar_id ) .
               '/events/' . rawurlencode( (string) $event_id );

        $res = wp_remote_get( $url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ) );

        if ( is_wp_error( $res ) ) return $res;

        $code = (int) wp_remote_retrieve_response_code( $res );
        $json = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
            return new WP_Error( 'google_get_event_failed', 'Google Calendar event fetch failed.' );
        }

        return $json;
    }

    // List instances for a recurring Google Calendar event (master -> instances)
    protected function google_list_event_instances( $calendar_id, $event_id, $time_min_rfc3339, $time_max_rfc3339 ) {
        // Prevent double-encoding of calendar IDs stored as %40, etc.
        $calendar_id = rawurldecode( trim( (string) $calendar_id ) );

        if ( ! $this->google_is_configured() ) {
            return new WP_Error( 'google_not_configured', 'Google Calendar is not configured.' );
        }

        $access_token = $this->google_get_access_token();
        if ( is_wp_error( $access_token ) ) return $access_token;

        $base = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( (string) $calendar_id ) .
                '/events/' . rawurlencode( (string) $event_id ) . '/instances';

        $url = add_query_arg( array(
            'timeMin'     => $time_min_rfc3339,
            'timeMax'     => $time_max_rfc3339,
            'showDeleted' => 'false',
            'maxResults'  => 2500,
        ), $base );

        $res = wp_remote_get( $url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ) );

        if ( is_wp_error( $res ) ) return $res;

        $code = (int) wp_remote_retrieve_response_code( $res );
        $json = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
            return new WP_Error( 'google_list_instances_failed', 'Google Calendar events.instances failed.' );
        }

        return $json;
    }

    // List Google Calendar events for a window (Pattern B)
    protected function google_list_events( $calendar_id, $time_min_rfc3339, $time_max_rfc3339 ) {
        // Prevent double-encoding of calendar IDs stored as %40, etc.
        $calendar_id = rawurldecode( trim( (string) $calendar_id ) );

        if ( ! $this->google_is_configured() ) {
            return new WP_Error( 'google_not_configured', 'Google Calendar is not configured.' );
        }

        $access_token = $this->google_get_access_token();
        if ( is_wp_error( $access_token ) ) return $access_token;

        $base = sprintf( self::GOOGLE_EVENTS_LIST_URL, rawurlencode( (string) $calendar_id ) );

        $url = add_query_arg( array(
            'timeMin'      => $time_min_rfc3339,
            'timeMax'      => $time_max_rfc3339,
            'singleEvents' => 'true',   // IMPORTANT: expands recurring instances
            'showDeleted'  => 'false',
            'maxResults'   => 2500,
            'orderBy'      => 'startTime',
        ), $base );

        $res = wp_remote_get( $url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ) );

        if ( is_wp_error( $res ) ) return $res;

        $code = (int) wp_remote_retrieve_response_code( $res );
        $json = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
            return new WP_Error( 'google_list_events_failed', 'Google Calendar events.list failed.' );
        }

        return $json;
    }

    /**
     * Find the correct Google Calendar event instance for a given booking_id
     * by scanning events.list results and matching extendedProperties.private.booking_id.
     *
     * This is the same matching strategy used in cron_sync_upcoming_events().
     *
     * @return array|null|WP_Error  array = event, null = not found, WP_Error on API error
     */
    protected function google_find_event_by_booking_id( $calendar_id, $booking_id, $timeMin, $timeMax ) {
        $calendar_id = rawurldecode( trim( (string) $calendar_id ) );

        if ( ! $this->google_is_configured() ) {
            return new WP_Error( 'google_not_configured', 'Google Calendar is not configured.' );
        }

        $booking_id = (int) $booking_id;
        if ( ! $booking_id ) return null;

        $events = $this->google_list_events( $calendar_id, $timeMin, $timeMax );
        if ( is_wp_error( $events ) ) return $events;

        // google_list_events returns an array of events (items)
        if ( ! is_array( $events ) || empty( $events ) ) return null;

        $items = isset( $events['items'] ) && is_array( $events['items'] ) ? $events['items'] : array();
        if ( empty( $items ) ) return null;

        foreach ( $items as $ev ) {
            $bid = 0;
            if ( isset( $ev['extendedProperties']['private']['booking_id'] ) ) {
                $bid = (int) $ev['extendedProperties']['private']['booking_id'];
            }
            if ( $bid === $booking_id ) {
                return $ev;
            }
        }

        return null;
    }

    // From an instances.list payload, find the instance matching booking_id
    protected function google_find_instance_by_booking_id( $instances_payload, $booking_id ) {
        $booking_id = (int) $booking_id;
        if ( ! $booking_id ) return null;

        if ( ! is_array( $instances_payload ) ) return null;
        $items = isset( $instances_payload['items'] ) && is_array( $instances_payload['items'] ) ? $instances_payload['items'] : array();
        if ( empty( $items ) ) return null;

        foreach ( $items as $ev ) {
            $bid = 0;
            if ( isset( $ev['extendedProperties']['private']['booking_id'] ) ) {
                $bid = (int) $ev['extendedProperties']['private']['booking_id'];
            }
            if ( $bid === $booking_id ) {
                return $ev;
            }
        }

        return null;
    }

    // Extract UTC timestamps from a Google event payload
    protected function google_event_to_utc_ts( $event ) {
        $start = '';
        $end   = '';

        if ( is_array( $event ) ) {
            if ( ! empty( $event['start']['dateTime'] ) ) $start = (string) $event['start']['dateTime'];
            if ( ! empty( $event['end']['dateTime'] ) )   $end   = (string) $event['end']['dateTime'];
        }

        $start_ts = $start ? strtotime( $start ) : 0;
        $end_ts   = $end ? strtotime( $end ) : 0;

        if ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) {
            return array( 0, 0 );
        }
        return array( $start_ts, $end_ts );
    }

    public static function get_instance() {
        if ( empty( self::$instance ) ) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_schema_notice' ) );
        add_action( 'admin_post_mrm_scheduler_run_upgrade', array( $this, 'handle_run_upgrade' ) );
        add_action( 'admin_post_mrm_scheduler_save_google', array( $this, 'handle_save_google_settings' ) );
        add_action( 'admin_post_mrm_scheduler_test_google', array( $this, 'handle_test_google_settings' ) );
        add_action( 'mrm_scheduler_send_lesson_reminder', array( $this, 'cron_send_lesson_reminder' ), 10, 1 );
        // Pattern B: periodic sync of upcoming events so gate/reminders stay accurate if instructors drag events
        add_filter( 'cron_schedules', array( $this, 'register_custom_cron_schedules' ) );
        add_action( 'mrm_scheduler_sync_upcoming_events', array( $this, 'cron_sync_upcoming_events' ) );
        // Gate page (virtual) for joining online lessons
        add_action( 'init', array( $this, 'register_join_gate_rewrite' ) );
        add_filter( 'query_vars', array( $this, 'register_join_gate_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'maybe_render_join_gate_page' ) );
        add_action( 'send_headers', array( $this, 'maybe_send_gate_nocache_headers' ), 0 );
        add_filter( 'redirect_canonical', array( $this, 'maybe_disable_canonical_for_gate' ), 10, 2 );
        $this->options = get_option( $this->option_key, array() );
    }

    /* =========================================================
     * Installation / Upgrade
     * ========================================================= */
    public static function activate() {
        self::install_or_upgrade();

        // Ensure gate route is registered immediately
        $inst = self::get_instance();
        $inst->register_join_gate_rewrite();
        flush_rewrite_rules();

        if ( ! wp_next_scheduled( 'mrm_scheduler_sync_upcoming_events' ) ) {
            wp_schedule_event( time() + 60, 'mrm_10min', 'mrm_scheduler_sync_upcoming_events' );
        }
    }

    public static function install_or_upgrade() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $table_instructors = $wpdb->prefix . 'mrm_instructors';
        $sql1 = "CREATE TABLE {$table_instructors} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    state varchar(50) NOT NULL DEFAULT '',
    profile_image_url TEXT NULL,
    short_description TEXT NULL,
    long_description LONGTEXT NULL,
    instruments TEXT NULL,
    latitude DECIMAL(10,6) DEFAULT NULL,
    longitude DECIMAL(10,6) DEFAULT NULL,
    calendar_id VARCHAR(255) NOT NULL,
    stripe_recipient_id VARCHAR(255) DEFAULT NULL,
    timezone VARCHAR(50) NOT NULL,
    hire_date DATE DEFAULT NULL,
    PRIMARY KEY (id),
    KEY city_idx (city),
    KEY state_idx (state)
) {$charset_collate};";
        $table_lessons = $wpdb->prefix . 'mrm_lessons';
        $sql2 = "CREATE TABLE {$table_lessons} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    instructor_id BIGINT UNSIGNED NOT NULL,
    series_id BIGINT UNSIGNED NULL,
    student_name VARCHAR(255) NOT NULL,
    student_email VARCHAR(255) NOT NULL,
    instrument VARCHAR(100) NOT NULL,
    is_online TINYINT(1) NOT NULL DEFAULT 0,
    lesson_length INT NOT NULL DEFAULT 60,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'scheduled',
    google_event_id VARCHAR(255) NULL,
    google_meet_url TEXT NULL,
    agreement_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    reminder_token VARCHAR(80) DEFAULT NULL,
    reminder_token_hash CHAR(64) DEFAULT NULL,
    reminder_scheduled_at DATETIME DEFAULT NULL,
    reminder_sent_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY instructor_idx (instructor_id),
    KEY student_email_idx (student_email),
    KEY reminder_token_hash_idx (reminder_token_hash),
    KEY reminder_sent_at_idx (reminder_sent_at)
) {$charset_collate};";
        $table_agreements = $wpdb->prefix . 'mrm_agreements';
        $sql3 = "CREATE TABLE {$table_agreements} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    agreement_version VARCHAR(50) NOT NULL,
    signature TEXT NOT NULL,
    signed_at DATETIME NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    PRIMARY KEY (id),
    KEY email_idx (email)
) {$charset_collate};";
        dbDelta( $sql1 );
        dbDelta( $sql2 );
        dbDelta( $sql3 );
        update_option( 'mrm_scheduler_db_version', self::DB_VERSION );
        if ( ! get_option( 'mrm_scheduler_settings', false ) ) {
            add_option( 'mrm_scheduler_settings', array(), '', 'no' ); // autoload disabled
        }
    }

    protected function schema_status() {
        global $wpdb;

        // 1) Check instructors table
        $instructors = $wpdb->prefix . 'mrm_instructors';
        $exists_instructors = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $instructors ) ) === $instructors );
        if ( ! $exists_instructors ) {
            return array( 'ok' => false, 'reason' => 'missing_table', 'table' => 'mrm_instructors' );
        }

        $instructor_cols = $wpdb->get_col( "DESC {$instructors}", 0 );
        $need_instructors = array(
            'stripe_recipient_id',
            'timezone',
            'hire_date',
            'calendar_id',
            'profile_image_url',
            'short_description',
            'long_description',
            'instruments',
            'state'
        );

        foreach ( $need_instructors as $col ) {
            if ( ! in_array( $col, $instructor_cols, true ) ) {
                return array(
                    'ok'     => false,
                    'reason' => 'missing_column',
                    'table'  => 'mrm_instructors',
                    'column' => $col
                );
            }
        }

        // 2) Check lessons table (THIS is what prevents "Database insert failed" after reminder fields were added)
        $lessons = $wpdb->prefix . 'mrm_lessons';
        $exists_lessons = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $lessons ) ) === $lessons );
        if ( ! $exists_lessons ) {
            return array( 'ok' => false, 'reason' => 'missing_table', 'table' => 'mrm_lessons' );
        }

        $lesson_cols = $wpdb->get_col( "DESC {$lessons}", 0 );
        $need_lessons = array(
            'google_event_id',
            'google_meet_url',
            'agreement_id',
            'reminder_token',
            'reminder_token_hash',
            'reminder_scheduled_at',
            'reminder_sent_at'
        );

        foreach ( $need_lessons as $col ) {
            if ( ! in_array( $col, $lesson_cols, true ) ) {
                return array(
                    'ok'     => false,
                    'reason' => 'missing_column',
                    'table'  => 'mrm_lessons',
                    'column' => $col
                );
            }
        }

        return array( 'ok' => true );
    }

    public function maybe_show_schema_notice() {
        if ( ! current_user_can( self::CAPABILITY ) ) return;
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && strpos( $screen->id, 'mrm-scheduler' ) === false ) return;
        $status = $this->schema_status();
        if ( ! $status['ok'] ) {
            $table_label = isset( $status['table'] ) ? $status['table'] : 'database';
            $msg = ( $status['reason'] === 'missing_table' )
                ? 'The database table (' . esc_html( $table_label ) . ') is missing. Click “Run Installer/Upgrade” to create it.'
                : 'Database schema is outdated in (' . esc_html( $table_label ) . ') (missing column: ' . esc_html( $status['column'] ) . '). Click “Run Installer/Upgrade” to update safely.';
            $url = wp_nonce_url( admin_url( 'admin-post.php?action=mrm_scheduler_run_upgrade' ), 'mrm_scheduler_run_upgrade' );
            echo '<div class="notice notice-warning"><p><strong>MRM Lesson Scheduler:</strong> ' . $msg . '</p>' .
                 '<p><a class="button button-primary" href="' . esc_url( $url ) . '">Run Installer/Upgrade</a></p></div>';
        }
    }

    public function handle_run_upgrade() {
        if ( ! current_user_can( self::CAPABILITY ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'mrm_scheduler_run_upgrade' );
        self::install_or_upgrade();
        wp_safe_redirect( admin_url( 'admin.php?page=mrm-scheduler-instructors&upgraded=1' ) );
        exit;
    }

    /* =========================================================
     * REST API
     * ========================================================= */
    public function register_rest_routes() {
        register_rest_route( 'mrm-schedule/v1', '/instructors', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'rest_get_instructors' ),
            'args' => array(
                'city' => array( 'type' => 'string', 'required' => false ),
                'student_lat' => array( 'type' => 'number', 'required' => false ),
                'student_lng' => array( 'type' => 'number', 'required' => false ),
            ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'mrm-schedule/v1', '/availability', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( $this, 'rest_get_availability' ),
            'args' => array(
                'instructor_id' => array( 'type' => 'integer', 'required' => true ),
                'start_date' => array( 'type' => 'string', 'required' => false ),
                'end_date' => array( 'type' => 'string', 'required' => false ),
                'slot_minutes' => array( 'type' => 'integer', 'required' => false ),
            ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'mrm-schedule/v1', '/book', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'rest_book_lesson' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'mrm-schedule/v1', '/ping', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => function() {
                return new WP_REST_Response( array(
                    'ok' => true,
                    'time' => current_time( 'mysql' ),
                ), 200 );
            },
            'permission_callback' => '__return_true',
        ) );
    }

    /* =========================================================
     * Join Gate Page (virtual route)
     * URL: /join-video-lesson/?token=XXXX
     * ========================================================= */

    public function register_join_gate_rewrite() {
        // New canonical gate URL:
        add_rewrite_rule( '^join-online/?$', 'index.php?mrm_join_video_lesson=1', 'top' );

        // Backward-compatible alias for older calendar events / emails:
        add_rewrite_rule( '^join-video-lesson/?$', 'index.php?mrm_join_video_lesson=1', 'top' );
    }

    public function register_join_gate_query_vars( $vars ) {
        $vars[] = 'mrm_join_video_lesson';
        return $vars;
    }

    public function maybe_render_join_gate_page() {
        // Absolute: this route must never be cached.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
        if ( ! defined( 'DONOTCACHEDB' ) ) define( 'DONOTCACHEDB', true );
        nocache_headers();
        header( 'X-LiteSpeed-Cache-Control: no-cache' );

        $is_gate = get_query_var( 'mrm_join_video_lesson' );
        if ( (string) $is_gate !== '1' ) return;

        $token = isset( $_GET['token'] ) ? sanitize_text_field( (string) $_GET['token'] ) : '';
        if ( $token === '' ) {
            $this->render_gate_message_page(
                'Missing Link',
                'This lesson link is missing required information. Please use the link provided in your email or calendar event.'
            );
            exit;
        }

        $token_hash = hash( 'sha256', $token );

        global $wpdb;
        $table_lessons = $wpdb->prefix . 'mrm_lessons';
        $table_instructors = $wpdb->prefix . 'mrm_instructors';

        $lesson = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id,instructor_id,student_name,student_email,is_online,lesson_length,start_time,end_time,status,google_event_id,google_meet_url,reminder_token_hash
                 FROM {$table_lessons}
                 WHERE reminder_token_hash = %s
                 LIMIT 1",
                $token_hash
            ),
            ARRAY_A
        );

        $appointment_type = '';
        if ( ! is_array( $lesson ) || empty( $lesson['id'] ) ) {
            $this->render_gate_message_page(
                'Invalid Link',
                'This lesson link is not valid. Please use the link provided in your email or calendar event.'
            );
            exit;
        }

        if ( (string) $lesson['status'] !== 'scheduled' ) {
            $this->render_gate_message_page(
                'Lesson Not Available',
                'This lesson is not currently scheduled. If you believe this is an error, please contact support.'
            );
            exit;
        }

        // Gate is intended for online lessons only
        if ( empty( $lesson['is_online'] ) ) {
            $this->render_gate_message_page(
                'In-Person Lesson',
                'This is an in-person lesson and does not use a video room.'
            );
            exit;
        }

        $instr = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT calendar_id,timezone,email
                 FROM {$table_instructors}
                 WHERE id = %d
                 LIMIT 1",
                (int) $lesson['instructor_id']
            ),
            ARRAY_A
        );

        // ------------------------------------------------------------
        // IMMEDIATE SYNC ON GATE OPEN (Pattern B / Option B)
        //
        // The gate MUST reflect the instructor's current Google Calendar time,
        // even if they dragged/rescheduled the event moments ago.
        // We do an events.list scan in a tight window and match booking_id.
        // This avoids stale google_event_id issues (especially recurring instances).
        // ------------------------------------------------------------
        $calendar_id = ( is_array( $instr ) && ! empty( $instr['calendar_id'] ) ) ? (string) $instr['calendar_id'] : '';
        $booking_id  = isset( $lesson['id'] ) ? (int) $lesson['id'] : 0;

        if ( $calendar_id !== '' && $booking_id > 0 && $this->google_is_configured() ) {

            // ------------------------------------------------------------
            // FAST PATH SYNC (reliable):
            // 1) If we have google_event_id, try events.get (exact when it is an instance ID).
            // 2) If it is a recurring master, fetch instances and match booking_id.
            // 3) If we still can't resolve, fall back to scanning events.list by booking_id.
            // ------------------------------------------------------------

            // Use a wider window so reschedules from "days out" -> "soon" are always discoverable.
            $min_ts = time() - DAY_IN_SECONDS;            // now - 24h
            $max_ts = time() + ( 14 * DAY_IN_SECONDS );   // now + 14d

            $time_min = gmdate( 'c', $min_ts );
            $time_max = gmdate( 'c', $max_ts );

            $resolved_event = null;
            $event_id = ! empty( $lesson['google_event_id'] ) ? (string) $lesson['google_event_id'] : '';

            // Prefer direct GET if we have a stored event id (often updated to instance id by your cron sync)
            if ( $calendar_id !== '' && $event_id !== '' ) {
                $got = $this->google_get_event( $calendar_id, $event_id );

                if ( is_array( $got ) ) {
                    // If this looks like a recurring master (has recurrence rules), resolve via instances
                    $is_master = isset( $got['recurrence'] ) && is_array( $got['recurrence'] ) && ! empty( $got['recurrence'] );

                    if ( $is_master ) {
                        $inst = $this->google_list_event_instances( $calendar_id, $event_id, $time_min, $time_max );
                        if ( is_array( $inst ) ) {
                            $match = $this->google_find_instance_by_booking_id( $inst, $booking_id );
                            if ( is_array( $match ) ) {
                                $resolved_event = $match;
                            }
                        }
                    } else {
                        // If it's an instance (or normal single event), use it directly
                        $resolved_event = $got;
                    }
                }
            }

            // If we still didn't resolve, fall back to your existing Pattern-B scan by booking_id
            if ( ! is_array( $resolved_event ) && $calendar_id !== '' ) {
                $ev = $this->google_find_event_by_booking_id( $calendar_id, $booking_id, $time_min, $time_max );
                if ( is_array( $ev ) ) {
                    $resolved_event = $ev;
                }
            }

            if ( is_array( $resolved_event ) ) {
                list( $g_start_ts, $g_end_ts ) = $this->google_event_to_utc_ts( $resolved_event );

                if ( isset( $resolved_event['extendedProperties']['private']['appointment_type'] ) ) {
                    $appointment_type = (string) $resolved_event['extendedProperties']['private']['appointment_type'];
                }

                if ( $g_start_ts && $g_end_ts ) {
                    $new_start = gmdate( 'Y-m-d H:i:s', $g_start_ts );
                    $new_end   = gmdate( 'Y-m-d H:i:s', $g_end_ts );
                    $new_event_id = ! empty( $resolved_event['id'] ) ? (string) $resolved_event['id'] : '';

                    // Persist to DB (keeps gate + reminder logic consistent)
                    $wpdb->update(
                        $table_lessons,
                        array(
                            'start_time'      => $new_start,
                            'end_time'        => $new_end,
                            'google_event_id' => ( $new_event_id !== '' ? $new_event_id : null ),
                            'updated_at'      => current_time( 'mysql' ),
                        ),
                        array( 'id' => $booking_id ),
                        array( '%s','%s','%s','%s' ),
                        array( '%d' )
                    );

                    // Update in-memory lesson so the gate uses the refreshed time immediately
                    $lesson['start_time'] = $new_start;
                    $lesson['end_time']   = $new_end;
                    if ( $new_event_id !== '' ) {
                        $lesson['google_event_id'] = $new_event_id;
                    }
                }
            }
        }

        // Enforce 10-min before / 10-min after window
        $start_ts = strtotime( (string) $lesson['start_time'] . ' UTC' );
        $end_ts   = strtotime( (string) $lesson['end_time'] . ' UTC' );
        if ( ! $start_ts || ! $end_ts ) {
            $this->render_gate_message_page(
                'Time Error',
                'This lesson has an invalid time window. Please contact support.'
            );
            exit;
        }

        $now = time();
        $open_ts  = $start_ts - ( 10 * MINUTE_IN_SECONDS );
        $close_ts = $end_ts + ( 10 * MINUTE_IN_SECONDS );

        // If outside allowed window, show professional message
        if ( $now < $open_ts || $now > $close_ts ) {
            $open_str  = gmdate( 'g:i A', $open_ts );
            $start_str = gmdate( 'g:i A', $start_ts );
            $this->render_gate_message_page(
                'Room Not Yet Available',
                'This room opens 10 minutes before the lesson start time and remains available until 10 minutes after the lesson ends.' .
                "\n\n" .
                'Please try again closer to your lesson time.',
                5
            );
            exit;
        }

        // GET -> show form
        if ( strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) !== 'POST' ) {
            $this->render_gate_form_page( $token, $lesson, '', $appointment_type );
            exit;
        }

        // POST -> validate name, send notifications, ensure Meet exists, redirect
        $join_name = isset( $_POST['join_name'] ) ? sanitize_text_field( (string) $_POST['join_name'] ) : '';
        if ( $join_name === '' ) {
            $this->render_gate_form_page( $token, $lesson, 'Please enter your name.', $appointment_type );
            exit;
        }

        // Fetch instructor email + calendar id
        $instr = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT name, email, calendar_id FROM {$table_instructors} WHERE id = %d LIMIT 1",
                (int) $lesson['instructor_id']
            ),
            ARRAY_A
        );

        $student_email = isset( $lesson['student_email'] ) ? (string) $lesson['student_email'] : '';
        $instructor_email = ( is_array( $instr ) && ! empty( $instr['email'] ) ) ? (string) $instr['email'] : '';
        $calendar_id = ( is_array( $instr ) && ! empty( $instr['calendar_id'] ) ) ? (string) $instr['calendar_id'] : '';
        $instructor_name = ( is_array( $instr ) && ! empty( $instr['name'] ) ) ? (string) $instr['name'] : 'Instructor';

        // Immediate notifications (gate pass)
        $minutes = (int) $lesson['lesson_length'];
        $student_name = isset( $lesson['student_name'] ) ? (string) $lesson['student_name'] : 'Student';
        $is_consultation = ( (string) $appointment_type === 'consultation' );
        $thing_upper = $is_consultation ? 'Consultation' : 'Lesson';
        $thing_lower = $is_consultation ? 'consultation' : 'lesson';

        $subject = $join_name . ' has just joined ' . $student_name . ' ' . $minutes . ' Online ' . $thing_upper;

        $start_str = gmdate( 'Y-m-d g:i A', $start_ts ) . ' - ' . gmdate( 'g:i A', $end_ts ) . ' UTC';
        $gate_url = add_query_arg( array( 'token' => $token ), home_url( '/join-online/' ) );

        $to = array();
        if ( is_email( $student_email ) ) $to[] = $student_email;
        if ( is_email( $instructor_email ) ) $to[] = $instructor_email;

        if ( ! empty( $to ) ) {
            $title = 'Someone just joined your session';
            $intro_html = '<p>A participant has entered the online session.</p>';

            $details_html = '';
            $details_html .= '<div><strong>Instructor:</strong> ' . esc_html( $instructor_name ) . '</div>';
            $details_html .= '<div><strong>Student:</strong> ' . esc_html( $student_name ) . '</div>';
            $details_html .= '<div><strong>Start time:</strong> ' . esc_html( $start_str ) . '</div>';

            $email_html = $this->mrm_wrap_email_html(
                $title,
                $intro_html,
                $details_html,
                $gate_url,
                'Open Join Page'
            );

            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
                'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
            );
            wp_mail( $to, $subject, $email_html, $headers );
        }

        // If Meet already exists, go there
        if ( ! empty( $lesson['google_meet_url'] ) ) {
            wp_redirect( (string) $lesson['google_meet_url'], 302 );
            exit;
        }

        // Otherwise create Meet now (deferred) WITHOUT modifying the calendar event.
        // IMPORTANT: We never write the Meet link back into Google Calendar.
        $meet_url = $this->google_create_meet_link_deferred( $lesson, $instr );
        if ( is_wp_error( $meet_url ) ) {
            $this->render_gate_message_page(
                'Unable to Create Room',
                'We were unable to create the video room at this time. Please try again, or contact support.' . "\n\n" .
                'Details: ' . $meet_url->get_error_message()
            );
            exit;
        }

        // Persist meet url to DB
        $wpdb->update(
            $table_lessons,
            array(
                'google_meet_url' => (string) $meet_url,
                'updated_at'      => current_time( 'mysql' ),
            ),
            array( 'id' => (int) $lesson['id'] ),
            array( '%s','%s' ),
            array( '%d' )
        );

        wp_redirect( (string) $meet_url, 302 );
        exit;
    }

    protected function render_gate_form_page( $token, $lesson, $error = '', $appointment_type = '' ) {
        nocache_headers();
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $student_name = isset( $lesson['student_name'] ) ? (string) $lesson['student_name'] : 'Student';
        $minutes = (int) ( $lesson['lesson_length'] ?? 60 );

        $is_consultation = ( (string) $appointment_type === 'consultation' );
        $thing_upper = $is_consultation ? 'Consultation' : 'Lesson';
        $thing_lower = $is_consultation ? 'consultation' : 'lesson';

        $title = 'Join Online ' . $thing_upper;
        $subtitle = $student_name . ' • ' . $minutes . ' minutes';

        $err_html = '';
        if ( $error !== '' ) {
            $err_html = '<div style="margin:12px 0;padding:10px 12px;border:1px solid #f5c2c7;background:#f8d7da;color:#842029;border-radius:10px;">' .
                esc_html( $error ) .
            '</div>';
        }

        echo '<!doctype html><html><head>' .
             '<meta charset="utf-8">' .
             '<meta name="viewport" content="width=device-width,initial-scale=1">' .
             '<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">' .
             '<meta http-equiv="Pragma" content="no-cache">' .
             '<meta http-equiv="Expires" content="0">' .
             '<title>' . esc_html( $title ) . '</title></head><body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f6f6f6;margin:0;padding:22px;">' .
             '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:16px;padding:18px 16px;box-shadow:0 6px 20px rgba(0,0,0,.08);">' .
             '<h1 style="margin:0 0 6px 0;font-size:22px;">' . esc_html( $title ) . '</h1>' .
             '<div style="color:#666;margin-bottom:14px;">' . esc_html( $subtitle ) . '</div>' .
             $err_html .
             '<form method="post" action="">' .
             '<label style="display:block;font-weight:600;margin:10px 0 6px;">Name</label>' .
             '<input name="join_name" type="text" autocomplete="name" required style="width:100%;box-sizing:border-box;padding:12px 12px;border:1px solid #ddd;border-radius:12px;font-size:16px;">' .
             '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">' .
             '<button type="submit" style="margin-top:14px;width:100%;padding:12px 14px;border:0;border-radius:12px;background:#111;color:#fff;font-size:16px;font-weight:700;cursor:pointer;">Join ' . esc_html( $thing_upper ) . '</button>' .
             '</form>' .
             '<div style="margin-top:12px;color:#777;font-size:13px;line-height:1.4;">' .
             'This room opens 10 minutes before the ' . esc_html( $thing_lower ) . ' start time and closes 10 minutes after the ' . esc_html( $thing_lower ) . ' ends.' .
             '</div>' .
             '</div></body></html>';
    }

    protected function extract_gate_link_from_description( $description ) {
        $description = is_string( $description ) ? $description : '';
        if ( $description === '' ) return '';

        // Accept both canonical + backward-compatible alias.
        // We only trust links that point back to THIS site (home_url).
        $home = home_url( '/' );
        $home = rtrim( $home, '/' );

        // Match full URLs like: https://yoursite.com/join-online/?token=...
        // or https://yoursite.com/join-video-lesson/?token=...
        $pattern = '#(' . preg_quote( $home, '#' ) . '/(join-online|join-video-lesson)/\\?token=[A-Za-z0-9_\\-]+)#';

        if ( preg_match( $pattern, $description, $m ) ) {
            return (string) $m[1];
        }

        return '';
    }

    protected function render_gate_message_page( $title, $message, $auto_refresh_seconds = 0 ) {

        // Strong no-cache headers (prevents Hostinger/WordPress caching from freezing the state)
        nocache_headers();
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Cache-Control: post-check=0, pre-check=0', false );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $refresh_js = '';
        if ( (int) $auto_refresh_seconds > 0 ) {
            $sec = (int) $auto_refresh_seconds;

            // Reload with a cache-buster to force PHP to re-run gate checks.
            $refresh_js =
                '<script>' .
                'setTimeout(function(){' .
                '  try {' .
                '    var u = new URL(window.location.href);' .
                '    u.searchParams.set("_ts", String(Date.now()));' .
                '    window.location.replace(u.toString());' .
                '  } catch(e) {' .
                '    window.location.reload(true);' .
                '  }' .
                '}, ' . ( $sec * 1000 ) . ');' .
                '</script>';
        }

        echo '<!doctype html><html><head>' .
             '<meta charset="utf-8">' .
             '<meta name="viewport" content="width=device-width,initial-scale=1">' .
             '<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">' .
             '<meta http-equiv="Pragma" content="no-cache">' .
             '<meta http-equiv="Expires" content="0">' .
             '<title>' . esc_html( $title ) . '</title>' .
             '</head><body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f6f6f6;margin:0;padding:22px;">' .
             '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:16px;padding:18px 16px;box-shadow:0 6px 20px rgba(0,0,0,.08);">' .
             '<h1 style="margin:0 0 10px 0;font-size:22px;">' . esc_html( $title ) . '</h1>' .
             '<div style="white-space:pre-line;color:#333;line-height:1.5;">' . esc_html( $message ) . '</div>' .
             ( $auto_refresh_seconds ? '<div style="margin-top:12px;color:#777;font-size:13px;">Re-checking automatically…</div>' : '' ) .
             '</div>' .
             $refresh_js .
             '</body></html>';
    }

    protected function is_join_gate_request() {
        // Works for the virtual route: /join-online/?token=...
        // We rely on your rewrite/query var that triggers the gate render.
        $pagename = get_query_var( 'pagename' );
        if ( is_string( $pagename ) && $pagename === 'join-online' ) return true;

        // Fallback: if your rewrite sets a custom query var, keep this lightweight:
        // If the request URI begins with /join-online/ treat it as gate.
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $uri = (string) $_SERVER['REQUEST_URI'];
            if ( strpos( $uri, '/join-online' ) === 0 || strpos( $uri, '/join-online/' ) === 0 ) return true;
        }
        return false;
    }

    public function maybe_send_gate_nocache_headers() {
        if ( ! $this->is_join_gate_request() ) return;

        // Tell common WP cache plugins + hosts not to cache this request.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
        if ( ! defined( 'DONOTCACHEDB' ) ) define( 'DONOTCACHEDB', true );

        // WordPress standard no-cache headers
        nocache_headers();

        // Extra hardening for reverse proxies / aggressive stacks
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // LiteSpeed-specific (common on Hostinger)
        header( 'X-LiteSpeed-Cache-Control: no-cache' );

        // Avoid indexing
        header( 'X-Robots-Tag: noindex, nofollow', true );
    }

    public function maybe_disable_canonical_for_gate( $redirect_url, $requested_url ) {
        if ( $this->is_join_gate_request() ) {
            return false;
        }
        return $redirect_url;
    }

    /**
     * Create a Google Meet link WITHOUT writing it into a Google Calendar event.
     *
     * This uses the Google Meet REST API (spaces.create) and returns a meeting URI.
     * Requires enabling "Google Meet API" in Google Cloud and adding the proper scope
     * to your service account / token logic.
     */
    protected function google_create_meet_link_deferred( $lesson, $instr ) {

        if ( ! $this->google_is_configured() ) {
            return new WP_Error( 'google_not_configured', 'Google is not configured.' );
        }

        // IMPORTANT:
        // Do NOT impersonate the instructor email here.
        // Domain-wide delegation can only impersonate users inside the Workspace domain.
        // Instructors are external, so impersonation causes:
        // "Client is unauthorized to retrieve access tokens..."
        $access_token = $this->google_get_access_token( 'https://www.googleapis.com/auth/meetings.space.created' );
        if ( is_wp_error( $access_token ) ) return $access_token;

        // Meet API: create a Space (meeting) without binding it to a Calendar event.
        // Endpoint per Meet API v2.
        $url = 'https://meet.googleapis.com/v2/spaces';

        $res = wp_remote_post( $url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json; charset=utf-8',
            ),
            'body' => wp_json_encode( array(
                'config' => array(
                    // TRUSTED is the best compatible setting to allow invited/trusted users (incl. external)
                    // to join without "request access", while not making it fully public like OPEN.
                    'accessType' => 'OPEN',
                ),
            ) ),
        ) );

        if ( is_wp_error( $res ) ) return $res;

        $code = (int) wp_remote_retrieve_response_code( $res );
        $body = wp_remote_retrieve_body( $res );
        $json = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
            $detail = $body !== '' ? $body : ( 'HTTP ' . $code );
            return new WP_Error( 'meet_create_failed', 'Meet space creation failed: ' . $detail );
        }

        // Meet API returns a Space object.
        // - meetingUri is the join URL
        // - name is the space resource name
        $meeting_uri = '';
        if ( ! empty( $json['meetingUri'] ) ) {
            $meeting_uri = (string) $json['meetingUri'];
        }

        if ( $meeting_uri === '' ) {
            return new WP_Error( 'meet_missing_uri', 'Meet link was not returned by Google.' );
        }

        // Best-effort: add instructor as COHOST (does not block room creation if it fails).
        $space_name = '';
        if ( ! empty( $json['name'] ) ) {
            $space_name = (string) $json['name'];
        }

        $instructor_email = '';
        if ( is_array( $instr ) && ! empty( $instr['email'] ) && is_email( (string) $instr['email'] ) ) {
            $instructor_email = (string) $instr['email'];
        }

        if ( $space_name !== '' && $instructor_email !== '' ) {
            $this->google_meet_try_add_cohost_member( $space_name, $instructor_email );
        }

        return $meeting_uri;
    }

    /**
     * Best-effort: add an external instructor as a COHOST member of the Meet space.
     *
     * NOTE:
     * - This does NOT grant host rights or recording/chat history.
     * - The instructor must join Meet while signed into the same email address.
     * - Uses Meet API membership management (Developer Preview / v2beta).
     */
    protected function google_meet_try_add_cohost_member( $space_name, $email ) {

        $space_name = trim( (string) $space_name );
        $email      = trim( (string) $email );

        if ( $space_name === '' || ! is_email( $email ) ) {
            return;
        }

        if ( ! $this->google_is_configured() ) {
            return;
        }

        // Use the delegated Workspace user (NOT the external instructor) to manage the space.
        $access_token = $this->google_get_access_token( 'https://www.googleapis.com/auth/meetings.space.created' );
        if ( is_wp_error( $access_token ) ) {
            return;
        }

        // Meet members endpoint is currently documented under v2beta.
        // POST https://meet.googleapis.com/v2beta/{space=spaces/*}/members
        $space_path = ltrim( $space_name, '/' );
        $url = 'https://meet.googleapis.com/v2beta/' . $space_path . '/members';

        $body = array(
            'user' => array(
                'email' => $email,
            ),
            'role' => 'COHOST',
        );

        $res = wp_remote_post( $url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json; charset=utf-8',
            ),
            'body' => wp_json_encode( $body ),
        ) );

        // Best-effort only: do not throw, do not log, do not change behavior if it fails.
        if ( is_wp_error( $res ) ) {
            return;
        }

        $code = (int) wp_remote_retrieve_response_code( $res );
        if ( $code < 200 || $code >= 300 ) {
            return;
        }
    }

    /**
     * Adds Google Meet to an existing event (deferred generation).
     * Returns meet URL (string) or WP_Error.
     */
    protected function google_add_meet_to_event( $calendar_id, $event_id ) {
        // Safety: do not write Meet links into Calendar events (architecture rule).
        return new WP_Error( 'meet_calendar_write_disabled', 'Meet links are not written into Google Calendar events in this system.' );
        if ( ! $this->google_is_configured() ) {
            return new WP_Error( 'google_not_configured', 'Google Calendar is not configured.' );
        }

        $access_token = $this->google_get_access_token();
        if ( is_wp_error( $access_token ) ) return $access_token;

        $request_id = wp_generate_password( 20, false, false );

        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( (string) $calendar_id ) .
               '/events/' . rawurlencode( (string) $event_id ) . '?conferenceDataVersion=1';

        $body = array(
            'conferenceData' => array(
                'createRequest' => array(
                    'requestId' => $request_id,
                    'conferenceSolutionKey' => array(
                        'type' => 'hangoutsMeet',
                    ),
                ),
            ),
        );

        $res = wp_remote_request( $url, array(
            'method'  => 'PATCH',
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $res ) ) return $res;

        $code = (int) wp_remote_retrieve_response_code( $res );
        $json = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
            return new WP_Error( 'google_patch_failed', 'Google Calendar event update failed.' );
        }

        // Extract meet link
        if ( ! empty( $json['hangoutLink'] ) ) {
            return (string) $json['hangoutLink'];
        }
        if ( ! empty( $json['conferenceData']['entryPoints'] ) && is_array( $json['conferenceData']['entryPoints'] ) ) {
            foreach ( $json['conferenceData']['entryPoints'] as $ep ) {
                if ( isset( $ep['entryPointType'], $ep['uri'] ) && $ep['entryPointType'] === 'video' ) {
                    return (string) $ep['uri'];
                }
            }
        }

        return new WP_Error( 'meet_link_missing', 'Google did not return a Meet link.' );
    }

    public function rest_get_instructors( WP_REST_Request $request ) {
        global $wpdb;
        $city = sanitize_text_field( (string) $request->get_param( 'city' ) );
        $student_lat = $request->get_param( 'student_lat' );
        $student_lng = $request->get_param( 'student_lng' );
        $table = $wpdb->prefix . 'mrm_instructors';
        $table_exists = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== null );
        if ( ! $table_exists ) {
            return new WP_REST_Response( array(
                'error' => 'Instructors table not installed. Run the installer in WP Admin → MRM Scheduler.',
            ), 500 );
        }
        $sql = "SELECT * FROM {$table}";
        $params = array();
        if ( $city !== '' ) {
            $sql .= " WHERE city = %s";
            $params[] = $city;
        }
        $instructors = empty( $params ) ? $wpdb->get_results( $sql, ARRAY_A ) : $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        if ( ! is_array( $instructors ) ) {
            return new WP_REST_Response( array(
                'error' => 'Database query failed.',
                'details' => $wpdb->last_error,
            ), 500 );
        }
        // Normalize rows to avoid undefined index notices if schema is mid-upgrade.
        $defaults = array(
            'id' => 0,
            'name' => '',
            'email' => '',
            'city' => '',
            'state' => '',
            'profile_image_url' => null,
            'short_description' => null,
            'long_description' => null,
            'instruments' => null,
            'latitude' => null,
            'longitude' => null,
            'calendar_id' => '',
            'stripe_recipient_id' => null,
            'timezone' => '',
            'hire_date' => null,
        );
        foreach ( $instructors as &$row ) {
            if ( is_array( $row ) ) {
                $row = array_merge( $defaults, $row );
            }
        }
        unset( $row );

        if ( $student_lat !== null && $student_lng !== null && $student_lat !== '' && $student_lng !== '' ) {
            $student_lat = (float) $student_lat;
            $student_lng = (float) $student_lng;
            foreach ( $instructors as &$row ) {
                if ( isset($row['latitude'], $row['longitude']) && $row['latitude'] !== null && $row['longitude'] !== null && $row['latitude'] !== '' && $row['longitude'] !== '' ) {
                    $row['distance'] = self::haversine_distance( $student_lat, $student_lng, (float) $row['latitude'], (float) $row['longitude'], 'miles' );
                } else {
                    $row['distance'] = null;
                }
            }
            unset( $row );
            usort( $instructors, function( $a, $b ) {
                if ( $a['distance'] === null && $b['distance'] === null ) return 0;
                if ( $a['distance'] === null ) return 1;
                if ( $b['distance'] === null ) return -1;
                return $a['distance'] <=> $b['distance'];
            } );
        } else {
            usort( $instructors, function( $a, $b ) {
                $c = strcmp( (string) ( $a['city'] ?? '' ), (string) ( $b['city'] ?? '' ) );
                if ( 0 !== $c ) return $c;
                return strcmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
            } );
        }
        $instrument_order = array(
            'trombone' => 'Trombone',
            'euphonium' => 'Euphonium',
            'tuba' => 'Tuba',
        );
        $output = array();
        foreach ( $instructors as $row ) {
            $instruments = array();
            if ( ! empty( $row['instruments'] ) ) {
                $decoded = json_decode( $row['instruments'], true );
                if ( is_array( $decoded ) ) {
                    $instruments = $decoded;
                }
            }
            $display = array();
            foreach ( $instrument_order as $key => $label ) {
                if ( in_array( $key, $instruments, true ) ) {
                    $display[] = $label;
                }
            }
            $row['profile_image_url'] = $row['profile_image_url'] ?? null;
            $row['short_description'] = $row['short_description'] ?? null;
            $row['long_description'] = $row['long_description'] ?? null;
            $row['instruments'] = $instruments;
            $row['instruments_display'] = $display;
            $row['instruments_text'] = implode( ', ', $display );
            $output[] = $row;
        }
        return new WP_REST_Response( $output, 200 );
    }

    /**
     * AVAILABILITY
     *
     * Mode A: working hours minus Busy (FreeBusy)
     * Mode B: "Free" events define availability windows minus Busy (FreeBusy)
     */
    public function rest_get_availability( WP_REST_Request $request ) {
        global $wpdb;
        $instructor_id = absint( $request->get_param( 'instructor_id' ) );
        $start_date = sanitize_text_field( (string) $request->get_param( 'start_date' ) );
        $end_date = sanitize_text_field( (string) $request->get_param( 'end_date' ) );
        $slot_minutes = absint( $request->get_param( 'slot_minutes' ) );
        $no_cache_headers = array(
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        );
        $apply_no_cache = function( WP_REST_Response $response ) use ( $no_cache_headers ) {
            foreach ( $no_cache_headers as $header => $value ) {
                $response->header( $header, $value );
            }
            return $response;
        };
        $work = $this->google_get_working_hours();
        if ( $slot_minutes <= 0 ) $slot_minutes = (int) $work['default_slot_minutes'];
        if ( $slot_minutes <= 0 ) $slot_minutes = 30;
        $start_date = $start_date ?: gmdate( 'Y-m-d' );
        $end_date   = $end_date   ?: gmdate( 'Y-m-d', strtotime( '+30 days' ) );
        // Fetch instructor (calendar_id + timezone)
        $table = $wpdb->prefix . 'mrm_instructors';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT calendar_id, timezone, email FROM {$table} WHERE id = %d", $instructor_id ), ARRAY_A );
        if ( ! $row ) return $apply_no_cache( new WP_REST_Response( array( 'error' => 'Instructor not found.' ), 404 ) );
        $calendar_id = (string) $row['calendar_id'];
        $tz = (string) $row['timezone'];
        $instructor_email = isset( $row['email'] ) ? (string) $row['email'] : '';
        $block_cal_ids = array();
        if ( $calendar_id ) $block_cal_ids[] = $calendar_id;
        if ( ! empty( $instructor_email ) && $instructor_email !== $calendar_id ) $block_cal_ids[] = $instructor_email;
        $block_cal_ids = array_values( array_unique( array_filter( $block_cal_ids ) ) );
        if ( empty( $block_cal_ids ) ) $block_cal_ids = array( $calendar_id );
        if ( ! $tz ) $tz = 'America/Phoenix';
        if ( ! $this->google_is_configured() ) {
            return $apply_no_cache( new WP_REST_Response( array(
                'mode' => 'dummy',
                'warning' => 'Google Calendar not configured. Returning dummy slots.',
                'slots'  => $this->dummy_slots( $start_date ),
                'busy'   => array(),
            ), 200 ) );
        }
        // Build window as UTC for API
        try {
            $window_start_local = new DateTime( $start_date . ' 00:00:00', new DateTimeZone( $tz ) );
            $window_end_local   = new DateTime( $end_date   . ' 23:59:59', new DateTimeZone( $tz ) );
            $window_start_utc   = clone $window_start_local;
            $window_start_utc->setTimezone( new DateTimeZone( 'UTC' ) );
            $window_end_utc     = clone $window_end_local;
            $window_end_utc->setTimezone( new DateTimeZone( 'UTC' ) );
        } catch ( Exception $e ) {
            return $apply_no_cache( new WP_REST_Response( array(
                'error' => 'Invalid date/timezone.',
                'details' => $e->getMessage(),
            ), 400 ) );
        }
        $timeMin = $window_start_utc->format( 'Y-m-d\TH:i:s\Z' );
        $timeMax = $window_end_utc->format( 'Y-m-d\TH:i:s\Z' );

        // Calendars to treat as blocking (availability calendar + optional primary calendar/email)
        $block_cal_ids = array();
        if ( $calendar_id ) $block_cal_ids[] = $calendar_id;
        if ( ! empty( $instructor_email ) && $instructor_email !== $calendar_id ) $block_cal_ids[] = $instructor_email;
        $block_cal_ids = array_values( array_unique( array_filter( $block_cal_ids ) ) );
        if ( empty( $block_cal_ids ) ) $block_cal_ids = array( $calendar_id );
        $cache_bust = $this->get_cache_bust_token( implode(',', $block_cal_ids ) );
        $cache_key_busy = 'mrm_busy_' . md5( implode( ',', $block_cal_ids ) . '|' . $timeMin . '|' . $timeMax . '|' . $cache_bust );
        $busy_intervals = get_transient( $cache_key_busy );

        if ( ! is_array( $busy_intervals ) ) {
            // IMPORTANT: use per-event intervals (not FreeBusy), so back-to-back lessons stay distinct
            $busy_intervals = $this->google_busy_intervals_from_events( $block_cal_ids, $timeMin, $timeMax, $tz );
            if ( is_wp_error( $busy_intervals ) ) {
                return $apply_no_cache( new WP_REST_Response( array(
                    'error' => 'Google Events list failed.',
                    'details' => $busy_intervals->get_error_message(),
                ), 500 ) );
            }
            set_transient( $cache_key_busy, $busy_intervals, 5 * MINUTE_IN_SECONDS );
        }

        // Merge overlaps only (touching does NOT merge after Step 1A)
        $busy_intervals = $this->merge_intervals( $busy_intervals );
        $mode    = $this->google_get_availability_mode(); // 'working_hours' or 'free_events'
        $keyword = $this->google_get_availability_keyword();
        // MODE B: "Free events define availability windows"
        if ( $mode === 'free_events' ) {
            $cache_key_ev = 'mrm_ev_' . md5( $calendar_id . '|' . $timeMin . '|' . $timeMax . '|' . $keyword . '|' . $cache_bust );
            $events = get_transient( $cache_key_ev );
            if ( ! is_array( $events ) ) {
                $events_payload = $this->google_list_events( $calendar_id, $timeMin, $timeMax );
                if ( is_wp_error( $events_payload ) ) {
                    return $apply_no_cache( new WP_REST_Response( array(
                        'error'   => 'Google Events list failed.',
                        'details' => $events_payload->get_error_message(),
                    ), 500 ) );
                }
                $events = isset( $events_payload['items'] ) && is_array( $events_payload['items'] ) ? $events_payload['items'] : array();
                set_transient( $cache_key_ev, $events, 5 * MINUTE_IN_SECONDS );
            }
            // Extract availability windows strictly from events with Show as = Free (transparency==transparent)
            // Ignore summary/keyword entirely (keyword is passed for UI only)
            $availability_windows = $this->events_to_availability_windows( $events, '' );
            // Subtract busy blocks
            $free_windows = $this->subtract_busy_from_availability( $availability_windows, $busy_intervals );
            // Build slots
            $slots = $this->build_slots_from_windows( $free_windows, $slot_minutes );
            return $apply_no_cache( new WP_REST_Response( array(
                'mode'                 => 'google_free_events',
                'calendar_id'          => $calendar_id,
                'timezone'             => $tz,
                'slot_minutes'         => $slot_minutes,
                'availability_keyword' => $keyword,
                'slots'                => $slots,
                'busy'                 => array_map( function( $x ) {
                    return array(
                        'start' => $x['start'],
                        'end' => $x['end'],
                        'lesson_type' => isset( $x['lesson_type'] ) ? $x['lesson_type'] : null,
                        'lesson_minutes' => isset( $x['lesson_minutes'] ) ? $x['lesson_minutes'] : null,
                        'source' => isset( $x['source'] ) ? $x['source'] : null,
                    );
                }, $busy_intervals ),
                'availability_windows' => array_map( function( $w ) {
                    return array(
                        'start' => gmdate( 'c', $w['start_ts'] ),
                        'end'   => gmdate( 'c', $w['end_ts'] ),
                    );
                }, $availability_windows ),
            ), 200 ) );
        }
        // MODE A: working hours minus busy
        $slots = $this->build_available_slots( $start_date, $end_date, $tz, $slot_minutes, $work, $busy_intervals );
        return $apply_no_cache( new WP_REST_Response( array(
            'mode'         => 'google_working_hours',
            'calendar_id'  => $calendar_id,
            'timezone'     => $tz,
            'slot_minutes' => $slot_minutes,
            'working_hours'=> $work,
            'slots'        => $slots,
            'busy'         => array_map( function( $x ) {
                return array(
                    'start' => $x['start'],
                    'end' => $x['end'],
                    'lesson_type' => isset( $x['lesson_type'] ) ? $x['lesson_type'] : null,
                    'lesson_minutes' => isset( $x['lesson_minutes'] ) ? $x['lesson_minutes'] : null,
                    'source' => isset( $x['source'] ) ? $x['source'] : null,
                );
            }, $busy_intervals ),
        ), 200 ) );
    }

    public function rest_book_lesson( WP_REST_Request $request ) {
        global $wpdb;
        $data = (array) $request->get_json_params();
        $instructor_id = isset( $data['instructor_id'] ) ? absint( $data['instructor_id'] ) : 0;
        $slots         = isset( $data['slots'] ) && is_array( $data['slots'] ) ? $data['slots'] : array();
        $student_name  = isset( $data['student_name'] ) ? sanitize_text_field( (string) $data['student_name'] ) : '';
        $student_email = isset( $data['student_email'] ) ? sanitize_email( (string) $data['student_email'] ) : '';
        $instrument    = isset( $data['instrument'] ) ? sanitize_text_field( (string) $data['instrument'] ) : '';
        $first_name = isset( $data['first_name'] ) ? sanitize_text_field( $data['first_name'] ) : '';
        $last_name  = isset( $data['last_name'] ) ? sanitize_text_field( $data['last_name'] ) : '';
        $address    = isset( $data['address'] ) ? sanitize_text_field( $data['address'] ) : '';
        $phone      = isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '';

        $repeat_frequency = isset( $data['repeat_frequency'] ) ? sanitize_text_field( $data['repeat_frequency'] ) : 'weekly'; // weekly|biweekly|none
        $repeat_duration  = isset( $data['repeat_duration'] ) ? sanitize_text_field( $data['repeat_duration'] ) : '1_month'; // 1_month|3_months|indefinitely
        $lesson_type = isset( $data['lesson_type'] ) ? sanitize_text_field( (string) $data['lesson_type'] ) : '';
        if ( ! in_array( $lesson_type, array( 'in_person', 'online' ), true ) ) {
            $lesson_type = '';
        }
        if ( $lesson_type !== '' ) {
            $is_online = ( $lesson_type === 'online' );
        } else {
            if ( array_key_exists( 'is_online', $data ) ) {
                $is_online = ! empty( $data['is_online'] );
            } elseif ( array_key_exists( 'online', $data ) ) {
                $is_online = ( (int) $data['online'] ) === 1;
            } else {
                $is_online = false;
            }
            $lesson_type = $is_online ? 'online' : 'in_person';
        }
        $lesson_length = isset( $data['lesson_length'] ) ? absint( $data['lesson_length'] ) : 60;
        $appointment_type = isset( $data['appointment_type'] ) ? sanitize_text_field( (string) $data['appointment_type'] ) : '';
        if ( ! in_array( $appointment_type, array( 'consultation', 'lesson', 'standard', '' ), true ) ) {
            $appointment_type = '';
        }
        $agreement_version = isset( $data['agreement_version'] ) ? sanitize_text_field( (string) $data['agreement_version'] ) : '';
        $signature         = isset( $data['signature'] ) ? (string) $data['signature'] : '';
        $ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : '';
        if ( ! $instructor_id || empty( $slots ) || ! $student_name || ! $student_email || ! $instrument ) {
            return new WP_REST_Response( array( 'error' => 'Missing required fields.' ), 400 );
        }
        $agreement_id = $this->maybe_store_agreement( $student_email, $agreement_version, $signature, $ip_address );
        $table_lessons = $wpdb->prefix . 'mrm_lessons';
        $now = current_time( 'mysql' );
        $inserted = 0;
        $booked_sessions = array();
        $google_errors = array();
        // Fetch instructor calendar + timezone
        $table_instructors = $wpdb->prefix . 'mrm_instructors';
        $instr = $wpdb->get_row( $wpdb->prepare( "SELECT calendar_id, timezone, email FROM {$table_instructors} WHERE id = %d", $instructor_id ), ARRAY_A );
        $calendar_id = isset( $instr['calendar_id'] ) ? (string) $instr['calendar_id'] : '';
        $tz          = isset( $instr['timezone'] ) ? (string) $instr['timezone'] : 'America/Phoenix';
        $instructor_email = isset( $instr['email'] ) ? (string) $instr['email'] : '';
        // Calendars to consult for conflicts (Google only). Currently: instructor calendar_id.
        $block_cal_ids = array();
        if ( $calendar_id !== '' ) { $block_cal_ids[] = $calendar_id; }

        foreach ( $slots as $slot ) {
            $start = isset( $slot['start'] ) ? sanitize_text_field( (string) $slot['start'] ) : '';
            $end   = isset( $slot['end'] )   ? sanitize_text_field( (string) $slot['end'] )   : '';
            if ( ! $start || ! $end ) continue;

            // Prevent double-booking + enforce 30-min travel buffer for in-person lessons.
            $conflict = $this->slot_conflicts( $instructor_id, $block_cal_ids, $start, $end, $is_online );
            if ( is_wp_error( $conflict ) ) {
                $err_data = $conflict->get_error_data();
                return new WP_REST_Response( array(
                    'success' => false,
                    'error'   => $conflict->get_error_message(),
                    'code'    => $conflict->get_error_code(),
                    'data'    => is_array( $err_data ) ? $err_data : array(),
                ), 409 );
            }
            // Insert into DB
            // Second token specifically for the 1-hour reminder email:
            $reminder_token_raw  = wp_generate_password( 32, false, false );
            $reminder_token_hash = hash( 'sha256', $reminder_token_raw );
            $result = $wpdb->insert( $table_lessons, array(
                'instructor_id'  => $instructor_id,
                'series_id'      => null,
                'student_name'   => $student_name,
                'student_email'  => $student_email,
                'instrument'     => $instrument,
                'is_online'      => $is_online ? 1 : 0,
                'lesson_length'  => $lesson_length,
                'start_time'     => gmdate( 'Y-m-d H:i:s', strtotime( $start ) ),
                'end_time'       => gmdate( 'Y-m-d H:i:s', strtotime( $end ) ),
                'status'         => 'scheduled',
                'agreement_id'   => $agreement_id ? $agreement_id : null,
                'created_at'     => $now,
                'updated_at'     => $now,
                'reminder_token'       => $reminder_token_raw,
                'reminder_token_hash'  => $reminder_token_hash,
                'reminder_scheduled_at'=> null,
                'reminder_sent_at'     => null,
            ), array( '%d','%d','%s','%s','%s','%d','%d','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s' ) );
            if ( false === $result ) {
                return new WP_REST_Response( array(
                    'error'   => 'Database insert failed.',
                    'details' => $wpdb->last_error,
                ), 500 );
            }
            $lesson_id = (int) $wpdb->insert_id;
            $inserted++;
            // Create Google event marked busy
            if ( $calendar_id !== '' && $this->google_is_configured() ) {
                // Convert UTC ISO strings to instructor timezone dateTimes
                try {
                    $start_dt = new DateTime( $start );
                    $end_dt   = new DateTime( $end );
                    $tzObj    = new DateTimeZone( $tz );
                    $start_dt->setTimezone( $tzObj );
                    $end_dt->setTimezone( $tzObj );
                    $start_local = $start_dt->format( 'c' );
                    $end_local   = $end_dt->format( 'c' );
                } catch ( Exception $e ) {
                    $start_local = $start;
                    $end_local   = $end;
                }
                $lesson_minutes = $lesson_length;
                $slot_start_ts = strtotime( $start );
                $slot_end_ts = strtotime( $end );
                if ( $slot_start_ts && $slot_end_ts && $slot_end_ts > $slot_start_ts ) {
                    $lesson_minutes = (int) round( ( $slot_end_ts - $slot_start_ts ) / 60 );
                }
                $lesson_type_label = $is_online ? 'Online' : 'In person';

                if ( $appointment_type === 'consultation' ) {
                    // Consultation calendar title: "[online] [lesson type] [parent first and last name]"
                    $parent_name = trim( $first_name . ' ' . $last_name );
                    $consult_label = 'Meet The Instructor (Free Consultation)';
                    $title = 'Online ' . $consult_label . ' ' . $parent_name;

                    // Consultations are always online in labels (even if something upstream mis-sent)
                    $lesson_type_label = 'Online';
                } else {
                    // Normal lesson title stays as your current behavior
                    $title = $student_name . ' - ' . $lesson_minutes . ' minute ' . $lesson_type_label;
                }
                if ( $appointment_type === 'consultation' ) {
                    $repeat_frequency = 'none';
                    $repeat_duration = '1_month';
                }
                $description_lines = array(
                    'First Name: ' . $first_name,
                    'Last Name: ' . $last_name,
                    'Student Name: ' . $student_name,
                    'Email: ' . $student_email,
                    'Address: ' . $address,
                    'Phone: ' . $phone,
                    'Lesson Length: ' . $lesson_minutes . ' minutes',
                    'Lesson Type: ' . $lesson_type_label,
                    'Instrument: ' . $instrument,
                    'Frequency: ' . ( ( $repeat_frequency === 'biweekly' ) ? 'Biweekly' : ( ( $repeat_frequency === 'none' ) ? 'Do not repeat' : 'Weekly' ) ),
                    'How long would you like to reserve this lesson time?: ' . (
                        ( $repeat_frequency === 'none' ) ? '--' :
                        ( ( $repeat_duration === '3_months' ) ? 'Three months' :
                            ( ( $repeat_duration === 'indefinitely' ) ? 'Indefinitely' : 'One month' )
                        )
                    ),
                );
                $session_join_link = '';

                // Gate link for online lessons AND consultations (same token used by the reminder email)
                if ( $is_online || $appointment_type === 'consultation' ) {
                    $join_url = home_url( '/join-online/' );
                    $join_link = add_query_arg( array( 'token' => $reminder_token_raw ), $join_url );

                    $session_join_link = $join_link;

                    // Put the join link at the TOP of the description so it’s immediately visible.
                    array_unshift( $description_lines, 'Join online lesson: ' . $join_link, '' );
                }
                $description = implode( "\n", $description_lines );

                // For online lessons/consultations: do NOT set a Calendar "location".
                // For in-person lessons: set location to the student address.
                $location = $is_online ? '' : (string) $address;
                $extended_private = array(
                    'source' => 'mrm_scheduler',
                    'lesson_type' => $lesson_type,
                    'lesson_minutes' => (string) $lesson_minutes,
                    'instructor_id' => (string) $instructor_id,
                    'appointment_type' => $appointment_type,
                );
                if ( $lesson_id > 0 ) {
                    $extended_private['booking_id'] = (string) $lesson_id;
                }
                $recurrence = array();

                // Consultations never recur (ignore any UI defaults)
                if ( $appointment_type === 'consultation' ) {
                    $repeat_frequency = 'none';
                }
                if ( $repeat_frequency !== 'none' ) {
                    $interval = ( $repeat_frequency === 'biweekly' ) ? 2 : 1;

                    // Hard counts requested:
                    // - "One month" => 4 total lessons
                    // - "Three months" => 12 total lessons
                    // - "Indefinitely" => no COUNT/UNTIL (keeps running)
                    $count = 0;

                    // Weekly counts:
                    // - 1 month  => 4 total lessons
                    // - 3 months => 12 total lessons
                    // Biweekly should be HALF the number of lessons scheduled as weekly.
                    if ( $repeat_duration === '1_month' ) {
                        $count = ( $interval === 2 ) ? 2 : 4;
                    } elseif ( $repeat_duration === '3_months' ) {
                        $count = ( $interval === 2 ) ? 6 : 12;
                    } elseif ( $repeat_duration === 'indefinitely' ) {
                        $count = 0; // no COUNT/UNTIL
                    } else {
                        // If UI sent '--' or anything unexpected, default safely to weekly-1-month behavior
                        $count = ( $interval === 2 ) ? 2 : 4;
                    }

                    $rrule = 'RRULE:FREQ=WEEKLY;INTERVAL=' . $interval;
                    if ( $count > 0 ) {
                        $rrule .= ';COUNT=' . $count;
                    }

                    $recurrence = array( $rrule );
                }

                // Attendees for Calendar-managed reminders (Option A)
                // IMPORTANT: Only invite/email the parent (student_email). Do NOT add instructor here.
                $attendee_emails = array();
                if ( is_email( $student_email ) ) {
                    $attendee_emails[] = $student_email;
                }

                $res = $this->google_insert_event(
                    $calendar_id,
                    $title,
                    $description,
                    $location,
                    $start_local,
                    $end_local,
                    $tz,
                    $extended_private,
                    $recurrence,
                    false, // do not generate Google Meet links here; gate flow handles joining
                    $attendee_emails
                );
                if ( is_wp_error( $res ) ) {
                    $google_errors[] = array(
                        'slot_start' => $start,
                        'error'      => $res->get_error_message(),
                    );
                } elseif ( is_array( $res ) ) {
                    $booked_sessions[] = array(
                        'when'      => $start_local,
                        'start_utc' => $start,
                        'duration'  => $lesson_minutes . ' minutes',
                        'type'      => ( $appointment_type === 'consultation' ) ? 'Consultation' : ( $is_online ? 'Online lesson' : 'In-person lesson' ),
                        'join'      => $session_join_link,
                        'location'  => ( $is_online ? '' : (string) $address ),
                    );

                    $update = array();
                    $update_format = array();
                    if ( ! empty( $res['id'] ) ) {
                        $update['google_event_id'] = (string) $res['id'];
                        $update_format[] = '%s';
                        $update['google_meet_url'] = null; // explicitly clear any Meet link (gate flow owns joining)
                        $update_format[] = '%s';
                    }
                    if ( ! empty( $update ) ) {
                        $wpdb->update(
                            $table_lessons,
                            $update,
                            array( 'id' => $lesson_id ),
                            $update_format,
                            array( '%d' )
                        );
                    }
                }
            }
        }
        if ( $inserted > 0 ) {
            $this->bump_cache_bust_token();
        }

        // Confirmation email: send once per booking action (to parent + instructor if valid)
        $confirmation_recipients = array();

        if ( isset( $student_email ) && is_email( $student_email ) ) {
            $confirmation_recipients[] = $student_email;
        }
        if ( isset( $instructor_email ) && is_email( $instructor_email ) ) {
            $confirmation_recipients[] = $instructor_email;
        }

        if ( $inserted > 0 && ! empty( $booked_sessions ) && ! empty( $confirmation_recipients ) ) {
            $this->send_booking_confirmation_email( $confirmation_recipients, $booked_sessions, $appointment_type, $tz, $instructor_email );
        }

        if ( $inserted > 0 && $appointment_type === 'lesson' && isset( $student_email ) && is_email( $student_email ) ) {
            wp_remote_post( site_url( '/wp-json/mrm-pay/v1/grant-sheet-music-access' ), array(
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body' => wp_json_encode( array(
                    'sku' => 'all-sheet-music',
                    'email' => $student_email,
                ) ),
                'timeout' => 10,
            ) );
        }

        $response = array(
            'success'  => true,
            'inserted' => $inserted,
        );
        if ( ! empty( $google_errors ) ) {
            $response['google_event_errors'] = $google_errors;
        }
        return new WP_REST_Response( $response, 200 );
    }

    protected function send_booking_confirmation_email( $recipients, $sessions, $appointment_type = '', $instructor_timezone = '', $instructor_email = '' ) {
        $recipients = array_filter( array_map( 'sanitize_email', (array) $recipients ) );
        $recipients = array_filter( $recipients, 'is_email' );
        if ( empty( $recipients ) ) return;

        if ( ! is_array( $sessions ) || empty( $sessions ) ) return;

        $details_html = '<div><strong>Sessions booked:</strong></div>';
        $details_html .= '<ul style="padding-left:18px;margin:8px 0 0 0;">';
        $gate_url = '';
        foreach ( $sessions as $s ) {
            $when = isset( $s['when'] ) ? (string) $s['when'] : '';
            $start_utc = isset( $s['start_utc'] ) ? (string) $s['start_utc'] : '';
            $dur  = isset( $s['duration'] ) ? (string) $s['duration'] : '';
            $type = isset( $s['type'] ) ? (string) $s['type'] : '';
            $join = isset( $s['join'] ) ? (string) $s['join'] : '';
            $loc  = isset( $s['location'] ) ? trim( (string) $s['location'] ) : '';

            $tz_obj = new DateTimeZone( $instructor_timezone ?: 'UTC' );
            $dt = new DateTime( $start_utc !== '' ? $start_utc : $when, new DateTimeZone( 'UTC' ) );
            $dt->setTimezone( $tz_obj );
            $time_display = $dt->format( 'F j, Y \a\t g:i A' ) . ' (' . $tz_obj->getName() . ')';

            $details_html .= '<li style="margin:0 0 12px 0;">' .
                '<div><strong>Time:</strong> ' . esc_html( $time_display ) . '</div>' .
                '<div><strong>Duration:</strong> ' . esc_html( $dur ) . '</div>' .
                '<div><strong>Type:</strong> ' . esc_html( $type ) . '</div>';
            if ( $loc !== '' ) {
                $details_html .= '<div style="margin-top:4px;"><strong>Location:</strong> ' . esc_html( $loc ) . '</div>';
            }
            if ( $join !== '' && $gate_url === '' ) {
                $gate_url = $join;
            }
            $details_html .= '</li>';
        }
        $details_html .= '</ul>';

        $subject = 'Booking Confirmation';
        $title = ''; // Prevent duplicate “Booking confirmed” (keep the centered H2 below)
        $intro_html = '<div style="text-align:center;">' .
            '<h2 style="margin:0 0 8px 0;">Booking Confirmed</h2>' .
            '<p style="margin:0;">Your booking has been confirmed.</p>' .
        '</div>';
        $is_consultation = ( $appointment_type === 'consultation' );
        $button_label = $is_consultation ? 'Join Consultation' : 'Join Lesson';
        $details_html .= '<div style="margin-top:24px;text-align:center;font-size:14px;color:#555;">' .
            '<p style="margin-bottom:4px;">Need to cancel or reschedule?</p>' .
            '<p style="margin:0;">Please contact your instructor directly:<br><strong>' . esc_html( $instructor_email ) . '</strong></p>' .
        '</div>';
        $email_html = $this->mrm_wrap_email_html(
            $title,
            $intro_html,
            $details_html,
            $gate_url,
            $button_label,
            array(
                'button_color' => '#000000',
                'button_align' => 'center',
            )
        );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        foreach ( $recipients as $to ) {
            wp_mail( $to, $subject, $email_html, $headers );
        }
    }

    public function register_custom_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['mrm_10min'] ) ) {
            $schedules['mrm_10min'] = array(
                'interval' => 10 * MINUTE_IN_SECONDS,
                'display'  => 'Every 10 minutes (MRM)',
            );
        }
        return $schedules;
    }

    /**
     * WP-Cron: send reminder email 1 hour before lesson start.
     * Receives $lesson_id.
     */
    public function cron_send_lesson_reminder( $lesson_id ) {
        // Option A: Calendar-managed reminders are now the source of truth.
        // Keep this handler for backward compatibility, but do not send reminders from WP-Cron.
        return;

        global $wpdb;
        $lesson_id = absint( $lesson_id );
        if ( ! $lesson_id ) return;

        $table_lessons = $wpdb->prefix . 'mrm_lessons';
        $table_instructors = $wpdb->prefix . 'mrm_instructors';

        // Pull lesson (only scheduled, only if not already sent)
        $lesson = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id,instructor_id,student_name,student_email,is_online,lesson_length,start_time,end_time,
                        reminder_token,reminder_scheduled_at,reminder_sent_at,google_event_id
                 FROM {$table_lessons}
                 WHERE id = %d AND status = 'scheduled'
                 LIMIT 1",
                $lesson_id
            ),
            ARRAY_A
        );
        if ( ! is_array( $lesson ) ) return;
        if ( ! empty( $lesson['reminder_sent_at'] ) ) return;

        $instructor = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT email,calendar_id,timezone FROM {$table_instructors} WHERE id = %d LIMIT 1",
                (int) $lesson['instructor_id']
            ),
            ARRAY_A
        );

        $student_email = isset( $lesson['student_email'] ) ? (string) $lesson['student_email'] : '';
        $instructor_email = ( is_array( $instructor ) && ! empty( $instructor['email'] ) ) ? (string) $instructor['email'] : '';

        if ( $student_email === '' && $instructor_email === '' ) return;

        // Use the stored reminder token (this is the "second token" you requested)
        $reminder_token = isset( $lesson['reminder_token'] ) ? (string) $lesson['reminder_token'] : '';
        if ( $reminder_token === '' ) return;

        // ------------------------------------------------------------
        // FLEXIBLE REMINDER TIMING (Source of truth = Google Calendar)
        // If the instructor drags/reschedules the event in Google Calendar,
        // this cron run will re-check the event and reschedule itself to
        // exactly 1 hour before the *current* Google event start.
        // ------------------------------------------------------------
        $google_event_id = isset( $lesson['google_event_id'] ) ? (string) $lesson['google_event_id'] : '';
        $calendar_id     = ( is_array( $instructor ) && ! empty( $instructor['calendar_id'] ) ) ? (string) $instructor['calendar_id'] : '';

        $appointment_type = '';
        $join_link_from_event = '';
        if ( $google_event_id !== '' && $calendar_id !== '' && $this->google_is_configured() ) {
            $event = $this->google_get_event( $calendar_id, $google_event_id );
            if ( ! is_wp_error( $event ) ) {
                if ( isset( $event['extendedProperties']['private']['appointment_type'] ) ) {
                    $appointment_type = (string) $event['extendedProperties']['private']['appointment_type'];
                }

                if ( isset( $event['description'] ) ) {
                    $join_link_from_event = $this->extract_gate_link_from_description( (string) $event['description'] );
                }

                list( $g_start_ts, $g_end_ts ) = $this->google_event_to_utc_ts( $event );

                if ( $g_start_ts && $g_end_ts ) {
                    // Update DB start/end to match Google (keeps gate logic flexible too)
                    $wpdb->update(
                        $table_lessons,
                        array(
                            'start_time' => gmdate( 'Y-m-d H:i:s', $g_start_ts ),
                            'end_time'   => gmdate( 'Y-m-d H:i:s', $g_end_ts ),
                            'updated_at' => current_time( 'mysql' ),
                        ),
                        array( 'id' => $lesson_id ),
                        array( '%s','%s','%s' ),
                        array( '%d' )
                    );

                    // Update local copy so the rest of this function uses the synced time
                    $lesson['start_time'] = gmdate( 'Y-m-d H:i:s', $g_start_ts );
                    $lesson['end_time']   = gmdate( 'Y-m-d H:i:s', $g_end_ts );

                    // Desired reminder time = 1 hour before Google start
                    $desired_send_at = $g_start_ts - HOUR_IN_SECONDS;

                    // If we're already too close, allow "send soon" behavior (60 seconds)
                    if ( $desired_send_at < time() + 30 ) {
                        $desired_send_at = time() + 60;
                    }

                    // Compare with what we think is scheduled
                    $existing_sched = ! empty( $lesson['reminder_scheduled_at'] ) ? strtotime( (string) $lesson['reminder_scheduled_at'] . ' UTC' ) : 0;

                    // If schedule differs by more than 2 minutes, reschedule
                    if ( ! $existing_sched || abs( $existing_sched - $desired_send_at ) > 120 ) {

                        // Unschedule the old event if we know when it was scheduled
                        if ( $existing_sched ) {
                            wp_unschedule_event( $existing_sched, 'mrm_scheduler_send_lesson_reminder', array( $lesson_id ) );
                        }

                        // Persist + schedule the new reminder
                        $wpdb->update(
                            $table_lessons,
                            array(
                                'reminder_scheduled_at' => gmdate( 'Y-m-d H:i:s', $desired_send_at ),
                                'updated_at'            => current_time( 'mysql' ),
                            ),
                            array( 'id' => $lesson_id ),
                            array( '%s','%s' ),
                            array( '%d' )
                        );

                        wp_schedule_single_event( $desired_send_at, 'mrm_scheduler_send_lesson_reminder', array( $lesson_id ) );

                        // If the new send time is in the future, stop now (don’t send early).
                        if ( $desired_send_at > time() + 90 ) {
                            return;
                        }
                    }
                }
            }
        }

        // Reminder email should point to the join gate page.
        // Prefer the exact link already present in the Google Calendar description (backward compatible),
        // otherwise fall back to deterministic canonical generation from the stored reminder token.
        $join_link = '';
        if ( is_string( $join_link_from_event ) && $join_link_from_event !== '' ) {
            $join_link = $join_link_from_event;
        } else {
            $join_url  = home_url( '/join-online/' );
            $join_link = add_query_arg( array( 'token' => $reminder_token ), $join_url );
        }

        $lesson_type_label = ! empty( $lesson['is_online'] ) ? 'Online' : 'In Person';
        $minutes = (int) $lesson['lesson_length'];
        $student_name = isset( $lesson['student_name'] ) ? (string) $lesson['student_name'] : 'Student';

        // Requested subject format:
        // [Student Name] [Lesson Length] [Lesson Type]
        $is_consultation = ( (string) $appointment_type === 'consultation' );
        $thing_upper = $is_consultation ? 'Consultation' : 'Lesson';
        $thing_lower = $is_consultation ? 'consultation' : 'lesson';

        $subject = $student_name . ' ' . $minutes . ' ' . $lesson_type_label . ' ' . $thing_upper;

        $body_lines = array(
            'Reminder: you have a ' . $thing_lower . ' scheduled in 1 hour.',
            '',
            'Student: ' . $student_name,
            'Length: ' . $minutes . ' minutes',
            'Type: ' . $lesson_type_label,
            '',
            $thing_upper . ' link:',
            $join_link,
        );
        $message = implode( "\n", $body_lines );

        $to = array();
        if ( is_email( $student_email ) ) $to[] = $student_email;
        if ( is_email( $instructor_email ) ) $to[] = $instructor_email;

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
        );

        $sent = wp_mail( $to, $subject, $message, $headers );

        if ( $sent ) {
            $wpdb->update(
                $table_lessons,
                array(
                    'reminder_sent_at' => current_time( 'mysql' ),
                    'updated_at'       => current_time( 'mysql' ),
                ),
                array( 'id' => $lesson_id ),
                array( '%s','%s' ),
                array( '%d' )
            );
        }
    }

    public function cron_sync_upcoming_events() {
        // Guard: only run if Google is configured
        if ( ! $this->google_is_configured() ) return;

        global $wpdb;
        $table_lessons = $wpdb->prefix . 'mrm_lessons';
        $table_instructors = $wpdb->prefix . 'mrm_instructors';

        // Window: now-6h to now+7d (UTC)
        // -6h: catches just-missed boundary / timezone edge cases
        // +7d: ensures moves from farther future into the near future get discovered quickly
        $now_ts = time();
        $min_ts = $now_ts - ( 6 * HOUR_IN_SECONDS );
        $max_ts = $now_ts + ( 7 * DAY_IN_SECONDS );

        $min_utc = gmdate( 'Y-m-d H:i:s', $min_ts );
        $max_utc = gmdate( 'Y-m-d H:i:s', $max_ts );

        // Find instructors to sync (Pattern B):
        // Do NOT rely on lesson DB times, because Google events can be dragged earlier/later.
        // Instead: sync calendars for all instructors that have a calendar_id.
        $instructor_ids = $wpdb->get_col(
            "SELECT id
             FROM {$table_instructors}
             WHERE calendar_id IS NOT NULL
               AND calendar_id <> ''"
        );

        if ( ! is_array( $instructor_ids ) || empty( $instructor_ids ) ) return;

        foreach ( $instructor_ids as $iid ) {
            $iid = (int) $iid;
            if ( ! $iid ) continue;

            $instr = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id,calendar_id,timezone,email
                     FROM {$table_instructors}
                     WHERE id = %d
                     LIMIT 1",
                    $iid
                ),
                ARRAY_A
            );

            if ( ! is_array( $instr ) || empty( $instr['calendar_id'] ) ) continue;

            $calendar_id = (string) $instr['calendar_id'];

            // Query Google events in the same window (RFC3339)
            $time_min = gmdate( 'c', $min_ts );
            $time_max = gmdate( 'c', $max_ts );

            $events = $this->google_list_events( $calendar_id, $time_min, $time_max );
            if ( is_wp_error( $events ) ) continue;

            $items = isset( $events['items'] ) && is_array( $events['items'] ) ? $events['items'] : array();
            if ( empty( $items ) ) continue;

            foreach ( $items as $ev ) {
                // Match by extendedProperties.private.booking_id (you already set booking_id on insert)
                $booking_id = 0;
                if ( isset( $ev['extendedProperties']['private']['booking_id'] ) ) {
                    $booking_id = (int) $ev['extendedProperties']['private']['booking_id'];
                }
                if ( ! $booking_id ) continue;

                list( $g_start_ts, $g_end_ts ) = $this->google_event_to_utc_ts( $ev );
                if ( ! $g_start_ts || ! $g_end_ts ) continue;

                $new_start = gmdate( 'Y-m-d H:i:s', $g_start_ts );
                $new_end   = gmdate( 'Y-m-d H:i:s', $g_end_ts );
                $google_event_id = ! empty( $ev['id'] ) ? (string) $ev['id'] : '';

                // Load lesson to see if it changed
                $lesson = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id,start_time,end_time
                         FROM {$table_lessons}
                         WHERE id = %d AND status = 'scheduled'
                         LIMIT 1",
                        $booking_id
                    ),
                    ARRAY_A
                );
                if ( ! is_array( $lesson ) ) continue;

                $changed = ( (string) $lesson['start_time'] !== $new_start ) || ( (string) $lesson['end_time'] !== $new_end );

                if ( $changed || $google_event_id !== '' ) {
                    // Update lesson times (UTC) + store instance event id (so gate has a direct id)
                    $wpdb->update(
                        $table_lessons,
                        array(
                            'start_time'      => $new_start,
                            'end_time'        => $new_end,
                            'google_event_id' => ( $google_event_id !== '' ? $google_event_id : null ),
                            'updated_at'      => current_time( 'mysql' ),
                        ),
                        array( 'id' => $booking_id ),
                        array( '%s','%s','%s','%s' ),
                        array( '%d' )
                    );
                }
            }
        }
    }
    /**
     * Check if a proposed slot conflicts with busy time (Google FreeBusy + DB lessons).
     * For in-person lessons (is_online=false), enforce a 30-minute buffer before + after.
     *
     * @return false|WP_Error false if ok, WP_Error if conflict/invalid.
     */
    protected function slot_conflicts( $instructor_id, $block_cal_ids, $start_iso, $end_iso, $is_online ) {
        $start_ts = strtotime( (string) $start_iso );
        $end_ts   = strtotime( (string) $end_iso );
        if ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) {
            return new WP_Error( 'invalid_slot', 'Invalid slot start/end.' );
        }
        $buffer = $is_online ? 0 : ( 30 * 60 );
        $check_min = gmdate( 'c', $start_ts - $buffer );
        $check_max = gmdate( 'c', $end_ts + $buffer );
        $busy = array();

        // Busy from Google FreeBusy (calendar events)
        if ( ! empty( $block_cal_ids ) && $this->google_is_configured() ) {
            $fb = $this->google_freebusy( $block_cal_ids, $check_min, $check_max );
            if ( ! is_wp_error( $fb ) ) {
                foreach ( $fb as $b ) {
                    if ( empty( $b['start'] ) || empty( $b['end'] ) ) continue;
                    $bs = strtotime( $b['start'] );
                    $be = strtotime( $b['end'] );
                    if ( ! $bs || ! $be || $be <= $bs ) continue;
                    $busy[] = array(
                        'start'    => (string) $b['start'],
                        'end'      => (string) $b['end'],
                        'start_ts' => $bs,
                        'end_ts'   => $be,
                        'source'   => 'google',
                    );
                }
            }
        }

        $busy = $this->merge_intervals( $busy );

        $slot_check_start = $start_ts - $buffer;
        $slot_check_end   = $end_ts + $buffer;

        foreach ( $busy as $b ) {
            $bs = isset( $b['start_ts'] ) ? (int) $b['start_ts'] : 0;
            $be = isset( $b['end_ts'] ) ? (int) $b['end_ts'] : 0;
            if ( $bs && $be && $slot_check_start < $be && $slot_check_end > $bs ) {
                return new WP_Error( 'slot_conflict', 'Selected time is no longer available.', array(
                    'conflict_start' => gmdate( 'c', $bs ),
                    'conflict_end'   => gmdate( 'c', $be ),
                ) );
            }
        }
        return false;
    }



    /* =========================================================
     * Admin UI
     * ========================================================= */
    public function register_admin_menu() {
        add_menu_page( 'MRM Scheduler', 'MRM Scheduler', self::CAPABILITY, 'mrm-scheduler', array( $this, 'render_admin_instructors_page' ), 'dashicons-calendar-alt', 58 );
        add_submenu_page( 'mrm-scheduler', 'Instructors', 'Instructors', self::CAPABILITY, 'mrm-scheduler-instructors', array( $this, 'render_admin_instructors_page' ) );
        add_submenu_page( 'mrm-scheduler', 'Google Calendar', 'Google Calendar', self::CAPABILITY, 'mrm-scheduler-google', array( $this, 'render_admin_google_page' ) );
    }

    public function render_admin_instructors_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) wp_die( 'You do not have permission to access this page.' );
        global $wpdb;
        $table = $wpdb->prefix . 'mrm_instructors';
        if ( isset( $_GET['upgraded'] ) && $_GET['upgraded'] == '1' ) {
            echo '<div class="notice notice-success"><p>MRM Scheduler installer/upgrade ran successfully.</p></div>';
        }
        $edit_id  = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
        $editing  = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $edit_id ), ARRAY_A ) : null;
        // Handle POST actions (add/update/delete)
        if ( isset( $_POST['mrm_instructor_action'] ) ) {
            check_admin_referer( 'mrm_instructors_save', 'mrm_instructors_nonce' );
            $action    = sanitize_text_field( wp_unslash( $_POST['mrm_instructor_action'] ) );
            $id        = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
            $name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
            $email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
            $city      = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
            $state = isset( $_POST['state'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['state'] ) ) ) : '';
            $state = preg_replace('/[^A-Z]/', '', $state); // keep letters only
            $state = substr($state, 0, 2); // enforce 2-letter code
            $latitude_in  = ( isset( $_POST['latitude'] ) && $_POST['latitude'] !== '' ) ? (string) wp_unslash( $_POST['latitude'] ) : '';
            $longitude_in = ( isset( $_POST['longitude'] ) && $_POST['longitude'] !== '' ) ? (string) wp_unslash( $_POST['longitude'] ) : '';
            $calendar_id = isset( $_POST['calendar_id'] ) ? sanitize_text_field( wp_unslash( $_POST['calendar_id'] ) ) : '';
            $stripe_id   = isset( $_POST['stripe_recipient_id'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_recipient_id'] ) ) : '';
            $timezone    = isset( $_POST['timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone'] ) ) : '';
            $hire_date   = isset( $_POST['hire_date'] ) ? sanitize_text_field( wp_unslash( $_POST['hire_date'] ) ) : '';
            $profile_image_url = isset( $_POST['profile_image_url'] ) ? esc_url_raw( wp_unslash( $_POST['profile_image_url'] ) ) : '';
            $short_description = isset( $_POST['short_description'] ) ? sanitize_text_field( wp_unslash( $_POST['short_description'] ) ) : '';
            $long_description  = isset( $_POST['long_description'] ) ? wp_kses_post( wp_unslash( $_POST['long_description'] ) ) : '';

            $instruments_in = array();
            if ( isset( $_POST['instruments'] ) && is_array( $_POST['instruments'] ) ) {
                $instruments_in = array_map( 'sanitize_key', wp_unslash( $_POST['instruments'] ) );
            }
            // Store as JSON for flexibility
            $instruments_json = $instruments_in ? wp_json_encode( array_values( $instruments_in ) ) : '';
            $errors = array();
            if ( $action !== 'delete' ) {
                if ( ! $name ) $errors[] = 'Name is required.';
                if ( ! $email || ! is_email( $email ) ) $errors[] = 'A valid email is required.';
                if ( ! $city ) $errors[] = 'City is required.';
                if ( ! $state || strlen($state) !== 2 ) $errors[] = 'State is required (2-letter code like AZ).';
                if ( ! $calendar_id ) $errors[] = 'Calendar ID is required.';
                if ( ! $timezone ) $errors[] = 'Timezone is required.';
                if ( $hire_date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $hire_date ) ) $errors[] = 'Start Date must be in YYYY-MM-DD format.';
                if ( $latitude_in !== '' && ! is_numeric( $latitude_in ) ) $errors[] = 'Latitude must be a number (or blank).';
                if ( $longitude_in !== '' && ! is_numeric( $longitude_in ) ) $errors[] = 'Longitude must be a number (or blank).';
            }
            $schema = $this->schema_status();
            if ( ! $schema['ok'] ) $errors[] = 'Database schema is not ready. Click “Run Installer/Upgrade” first.';
            if ( empty( $errors ) ) {
                $data = array(
                    'name' => $name,
                    'email' => $email,
                    'city' => $city,
                    'state' => $state,
                    'latitude' => ( $latitude_in === '' ? null : (string) $latitude_in ),
                    'longitude' => ( $longitude_in === '' ? null : (string) $longitude_in ),
                    'calendar_id' => $calendar_id,
                    'stripe_recipient_id' => ( $stripe_id === '' ? null : $stripe_id ),
                    'timezone' => $timezone,
                    'hire_date' => ( $hire_date === '' ? null : $hire_date ),
                    'profile_image_url' => ( $profile_image_url === '' ? null : $profile_image_url ),
                    'short_description' => ( $short_description === '' ? null : $short_description ),
                    'long_description' => ( $long_description === '' ? null : $long_description ),
                    'instruments' => ( $instruments_json === '' ? null : $instruments_json ),
                );
                $formats = array( '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' );
                if ( $action === 'add' ) {
                    $result = $wpdb->insert( $table, $data, $formats );
                    echo $result === false ? '<div class="notice notice-error"><p><strong>Database error:</strong> ' . esc_html( $wpdb->last_error ) . '</p></div>' : '<div class="notice notice-success"><p>Instructor added (ID ' . esc_html( (int) $wpdb->insert_id ) . ').</p></div>';
                } elseif ( $action === 'update' && $id ) {
                    $result = $wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
                    echo $result === false ? '<div class="notice notice-error"><p><strong>Database error:</strong> ' . esc_html( $wpdb->last_error ) . '</p></div>' : '<div class="notice notice-success"><p>Instructor updated (ID ' . esc_html( $id ) . ').</p></div>';
                } elseif ( $action === 'delete' && $id ) {
                    $lessons_table = $wpdb->prefix . 'mrm_lessons';
                    $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$lessons_table} WHERE instructor_id = %d", $id ) );
                    if ( $count > 0 ) {
                        echo '<div class="notice notice-error"><p>Cannot delete: this instructor has lessons in the database. (Delete or reassign lessons first.)</p></div>';
                    } else {
                        $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
                        echo '<div class="notice notice-success"><p>Instructor deleted.</p></div>';
                    }
                }
            } else {
                echo '<div class="notice notice-error"><p><strong>Fix these issues:</strong><br>' . implode( '<br>', array_map( 'esc_html', $errors ) ) . '</p></div>';
            }
            // refresh edit state after changes
            $edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
            $editing = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $edit_id ), ARRAY_A ) : null;
        }
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY city ASC, name ASC", ARRAY_A );
        $tz_options = array(
            'America/New_York'    => 'Eastern (America/New_York)',
            'America/Chicago'     => 'Central (America/Chicago)',
            'America/Denver'      => 'Mountain (America/Denver)',
            'America/Phoenix'     => 'Arizona / Phoenix (America/Phoenix)',
            'America/Los_Angeles' => 'Pacific (America/Los_Angeles)',
            'America/Anchorage'   => 'Alaska (America/Anchorage)',
            'Pacific/Honolulu'    => 'Hawaii (Pacific/Honolulu)',
        );
        $tz_selected = $editing['timezone'] ?? 'America/Phoenix';
        ?>
        <div class="wrap">
            <h1>MRM Scheduler — Instructors</h1>
            <p style="max-width:900px;"> Add instructors here. <strong>Start Date</strong> is stored in <code>hire_date</code> for future pay-tier automation. </p>
            <p>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mrm-scheduler-google' ) ); ?>">Google Calendar Settings</a>
            </p>
            <h2><?php echo $editing ? 'Edit Instructor' : 'Add Instructor'; ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field( 'mrm_instructors_save', 'mrm_instructors_nonce' ); ?>
                <input type="hidden" name="mrm_instructor_action" value="<?php echo esc_attr( $editing ? 'update' : 'add' ); ?>">
                <input type="hidden" name="id" value="<?php echo esc_attr( $editing ? (int) $editing['id'] : 0 ); ?>">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="name">Name</label></th>
                        <td><input name="name" id="name" type="text" class="regular-text" required value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email">Business Email</label></th>
                        <td><input name="email" id="email" type="email" class="regular-text" required value="<?php echo esc_attr( $editing['email'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="city">City</label></th>
                        <td><input name="city" id="city" type="text" class="regular-text" required placeholder="Phoenix" value="<?php echo esc_attr( $editing['city'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                      <th scope="row"><label for="state">State</label></th>
                      <td>
                        <input name="state" id="state" type="text" class="regular-text" required
                               placeholder="AZ"
                               value="<?php echo esc_attr( $editing['state'] ?? '' ); ?>">
                        <p class="description">Use 2-letter state code (e.g., AZ, CA, NY).</p>
                      </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="profile_image_url">Profile Image URL (1:1)</label></th>
                        <td>
                            <input type="url" class="regular-text" id="profile_image_url" name="profile_image_url"
                                   value="<?php echo esc_attr( $editing['profile_image_url'] ?? '' ); ?>"
                                   placeholder="https://... (upload in Media Library, paste URL)" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="short_description">Short Description</label></th>
                        <td>
                            <input type="text" class="regular-text" id="short_description" name="short_description"
                                   value="<?php echo esc_attr( $editing['short_description'] ?? '' ); ?>"
                                   placeholder="One short sentence shown on the calendar page" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="long_description">Long Description</label></th>
                        <td>
                            <textarea class="large-text" rows="6" id="long_description" name="long_description"><?php
                                echo esc_textarea( $editing['long_description'] ?? '' );
                            ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Instruments</th>
                        <td>
                            <?php
                                $saved = array();
                                if ( ! empty( $editing['instruments'] ) ) {
                                    $decoded = json_decode( $editing['instruments'], true );
                                    if ( is_array( $decoded ) ) {
                                        $saved = $decoded;
                                    }
                                }
                                $options = array(
                                    'trombone' => 'Trombone',
                                    'euphonium' => 'Euphonium',
                                    'tuba' => 'Tuba',
                                );
                                foreach ( $options as $key => $label ) {
                                    $checked = in_array( $key, $saved, true ) ? 'checked' : '';
                                    echo '<label style="display:block; margin-bottom:6px;">';
                                    echo '<input type="checkbox" name="instruments[]" value="' . esc_attr( $key ) . '" ' . $checked . ' /> ';
                                    echo esc_html( $label );
                                    echo '</label>';
                                }
                            ?>
                            <p class="description">Displayed in this order: Trombone, Euphonium, Tuba. Unchecked instruments will not show.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="latitude">Latitude</label></th>
                        <td>
                            <input name="latitude" id="latitude" type="text" class="regular-text" placeholder="33.4484" value="<?php echo esc_attr( $editing['latitude'] ?? '' ); ?>">
                            <p class="description">Optional. Used for proximity sorting.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="longitude">Longitude</label></th>
                        <td><input name="longitude" id="longitude" type="text" class="regular-text" placeholder="-112.0740" value="<?php echo esc_attr( $editing['longitude'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="calendar_id">Google Calendar ID</label></th>
                        <td><input name="calendar_id" id="calendar_id" type="text" class="regular-text" required placeholder="...@group.calendar.google.com" value="<?php echo esc_attr( $editing['calendar_id'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="stripe_recipient_id">Stripe Recipient ID</label></th>
                        <td>
                            <input name="stripe_recipient_id" id="stripe_recipient_id" type="text" class="regular-text" placeholder="acct_..." value="<?php echo esc_attr( $editing['stripe_recipient_id'] ?? '' ); ?>">
                            <p class="description">Stripe Connect Account ID used later to route payouts.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="timezone">Timezone</label></th>
                        <td>
                            <select name="timezone" id="timezone" class="regular-text" required style="max-width:420px;">
                                <?php foreach ( $tz_options as $tz_val => $tz_label ) : ?>
                                <option value="<?php echo esc_attr( $tz_val ); ?>" <?php selected( $tz_selected, $tz_val ); ?>> <?php echo esc_html( $tz_label ); ?> </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Instructor’s home timezone. Student-facing times should be converted in frontend.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hire_date">Start Date</label></th>
                        <td><input name="hire_date" id="hire_date" type="date" value="<?php echo esc_attr( $editing['hire_date'] ?? '' ); ?>"></td>
                    </tr>
                </table>
                <?php submit_button( $editing ? 'Save Changes' : 'Add Instructor' ); ?>
                <?php if ( $editing ) : ?>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mrm-scheduler-instructors' ) ); ?>">Cancel</a>
                <?php endif; ?>
            </form>
            <hr>
            <h2>Current Instructors</h2>
            <?php if ( empty( $rows ) ) : ?>
                <p>No instructors added yet.</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>ID</th><th>Name</th><th>Email</th><th>City</th><th>Start Date</th><th>Stripe ID</th><th>Calendar ID</th><th>Timezone</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $r ) : ?>
                            <tr>
                                <td><?php echo esc_html( $r['id'] ); ?></td>
                                <td><?php echo esc_html( $r['name'] ); ?></td>
                                <td><?php echo esc_html( $r['email'] ); ?></td>
                                <td><?php echo esc_html( $r['city'] ); ?></td>
                                <td><?php echo esc_html( $r['hire_date'] ); ?></td>
                                <td><code><?php echo esc_html( $r['stripe_recipient_id'] ); ?></code></td>
                                <td><code><?php echo esc_html( $r['calendar_id'] ); ?></code></td>
                                <td><code><?php echo esc_html( $r['timezone'] ); ?></code></td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=mrm-scheduler-instructors&edit=' . (int) $r['id'] ) ); ?>">Edit</a>
                                    <form method="post" action="" style="display:inline;">
                                        <?php wp_nonce_field( 'mrm_instructors_save', 'mrm_instructors_nonce' ); ?>
                                        <input type="hidden" name="mrm_instructor_action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo esc_attr( (int) $r['id'] ); ?>">
                                        <?php submit_button( 'Delete', 'delete button-small', 'submit', false, array( 'onclick' => "return confirm('Delete this instructor? This cannot be undone.');" ) ); ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_admin_google_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) wp_die( 'You do not have permission to access this page.' );
        $opts = $this->get_settings();
        $json = isset( $opts['google_service_account_json'] ) ? (string) $opts['google_service_account_json'] : '';
        $delegated = isset( $opts['google_delegated_user'] ) ? (string) $opts['google_delegated_user'] : '';
        $mode = isset( $opts['availability_mode'] ) ? (string) $opts['availability_mode'] : 'free_events';
        if ( ! in_array( $mode, array( 'working_hours', 'free_events' ), true ) ) $mode = 'free_events';
        $keyword = isset( $opts['availability_keyword'] ) ? (string) $opts['availability_keyword'] : 'AVAILABLE';
        $work_start = isset( $opts['working_hours_start'] ) ? (string) $opts['working_hours_start'] : '09:00';
        $work_end   = isset( $opts['working_hours_end'] ) ? (string) $opts['working_hours_end'] : '17:00';
        $weekdays   = isset( $opts['working_weekdays'] ) && is_array( $opts['working_weekdays'] ) ? $opts['working_weekdays'] : array('1','2','3','4','5');
        $slot_default= isset( $opts['default_slot_minutes'] ) ? (int) $opts['default_slot_minutes'] : 30;
        $sa_email = '';
        $parsed = $this->parse_service_account_json( $json );
        if ( is_array( $parsed ) && ! empty( $parsed['client_email'] ) ) $sa_email = $parsed['client_email'];
        ?>
        <div class="wrap">
            <h1>MRM Scheduler — Google Calendar</h1>
            <p style="max-width:900px;"> This plugin uses a <strong>Google Cloud Service Account</strong> (JWT) to call the Google Calendar API. For each instructor calendar, share the calendar with the Service Account email below. </p>
            <hr>
            <h2>1) Service Account Credentials</h2>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field( 'mrm_scheduler_save_google', 'mrm_scheduler_google_nonce' ); ?>
                <input type="hidden" name="action" value="mrm_scheduler_save_google">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Service Account JSON</th>
                        <td>
                            <textarea name="google_service_account_json" rows="12" style="width:100%; max-width:900px;"><?php echo esc_textarea( $json ); ?></textarea>
                            <p class="description"> Paste the full JSON key file you download from Google Cloud (contains private_key + client_email). Keep this private. </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Service Account Email</th>
                        <td>
                            <?php if ( $sa_email ) : ?>
                                <code style="font-size:14px;"><?php echo esc_html( $sa_email ); ?></code>
                                <p class="description">Share each instructor calendar with this email.</p>
                            <?php else : ?>
                                <em>Paste JSON above and Save to see the service account email.</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Delegated User (optional)</th>
                        <td>
                            <input type="email" class="regular-text" name="google_delegated_user" value="<?php echo esc_attr( $delegated ); ?>" placeholder="you@yourdomain.com">
                            <p class="description"> Leave blank unless you set up <strong>Domain-wide Delegation</strong> in Google Workspace. If blank, sharing calendars with the Service Account email is enough. </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save Google Settings' ); ?>
            </form>
            <hr>
            <h2>2) Availability Mode (THIS is Option A vs Option B)</h2>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field( 'mrm_scheduler_save_google', 'mrm_scheduler_google_nonce' ); ?>
                <input type="hidden" name="action" value="mrm_scheduler_save_google">
                <input type="hidden" name="save_availability_mode" value="1">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Mode</th>
                        <td>
                            <label style="display:block; margin-bottom:8px;">
                                <input type="radio" name="availability_mode" value="free_events" <?php checked( $mode, 'free_events' ); ?>>
                                <strong>Option B:</strong> “Free” events define availability windows (recommended for your workflow)
                            </label>
                            <label style="display:block;">
                                <input type="radio" name="availability_mode" value="working_hours" <?php checked( $mode, 'working_hours' ); ?>>
                                <strong>Option A:</strong> Working hours minus Busy blocks
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Availability Keyword (optional)</th>
                        <td>
                            <input type="text" class="regular-text" name="availability_keyword" value="<?php echo esc_attr( $keyword ); ?>" placeholder="AVAILABLE">
                            <p class="description"> If set, only “Free” events whose title contains this keyword will count as availability windows. Leave blank to treat all “Free” events as availability. (Note: keyword filtering is ignored for Option B in this version.) </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save Availability Mode' ); ?>
            </form>
            <hr>
            <h2>3) Slot Rules (used for Option A; also slot length for Option B)</h2>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field( 'mrm_scheduler_save_google', 'mrm_scheduler_google_nonce' ); ?>
                <input type="hidden" name="action" value="mrm_scheduler_save_google">
                <input type="hidden" name="save_slot_rules" value="1">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Working Hours (Option A)</th>
                        <td>
                            <input type="time" name="working_hours_start" value="<?php echo esc_attr( $work_start ); ?>"> to <input type="time" name="working_hours_end" value="<?php echo esc_attr( $work_end ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Working Days (Option A)</th>
                        <td>
                            <?php $days = array( '1'=>'Mon','2'=>'Tue','3'=>'Wed','4'=>'Thu','5'=>'Fri','6'=>'Sat','7'=>'Sun' );
                            foreach ( $days as $k => $label ) {
                                $checked = in_array( (string) $k, $weekdays, true ) ? 'checked' : '';
                                echo '<label style="margin-right:12px;"><input type="checkbox" name="working_weekdays[]" value="' . esc_attr($k) . '" ' . $checked . '> ' . esc_html($label) . '</label>';
                            } ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Default Slot Minutes</th>
                        <td>
                            <input type="number" min="10" step="5" name="default_slot_minutes" value="<?php echo esc_attr( (string) $slot_default ); ?>">
                            <p class="description">Used if frontend doesn’t pass slot_minutes.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save Slot Rules' ); ?>
            </form>
            <hr>
            <h2>4) Test Connection</h2>
            <p>After saving your JSON, click test. If it fails, you’ll get a readable error.</p>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field( 'mrm_scheduler_test_google', 'mrm_scheduler_google_test_nonce' ); ?>
                <input type="hidden" name="action" value="mrm_scheduler_test_google">
                <?php submit_button( 'Test Google Calendar API', 'secondary' ); ?>
            </form>
            <hr>
            <h2>Sharing Calendars (required)</h2>
            <ol>
                <li>Open the instructor availability calendar → <strong>Settings and sharing</strong>.</li>
                <li>Under <strong>Share with specific people</strong>, add the Service Account Email and give it at least <strong>See all event details</strong> (recommended for Option B).</li>
                <li>Do <strong>NOT</strong> make the calendar public.</li>
            </ol>
            <h3>How to create availability windows (Option B)</h3>
            <ol>
                <li>Create events for availability (e.g. <code>AVAILABLE – Lessons</code>).</li>
                <li>Set <strong>Show as</strong> = <strong>Free</strong>.</li>
                <li>(Optional) Ensure the event title includes your keyword (default: <code>AVAILABLE</code>). Note that keyword filtering is now ignored; all Free events count.</li>
                <li>Busy events (Show as = Busy) will subtract/block those times.</li>
            </ol>
        </div>
        <?php
    }

    public function handle_save_google_settings() {
        if ( ! current_user_can( self::CAPABILITY ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'mrm_scheduler_save_google', 'mrm_scheduler_google_nonce' );
        $opts = $this->get_settings();
        if ( isset( $_POST['google_service_account_json'] ) ) {
            $json = wp_unslash( $_POST['google_service_account_json'] );
            $opts['google_service_account_json'] = is_string( $json ) ? trim( $json ) : '';
        }
        if ( isset( $_POST['google_delegated_user'] ) ) {
            $opts['google_delegated_user'] = sanitize_email( wp_unslash( $_POST['google_delegated_user'] ) );
        }
        if ( isset( $_POST['save_availability_mode'] ) ) {
            $mode = isset($_POST['availability_mode']) ? sanitize_text_field( wp_unslash($_POST['availability_mode']) ) : 'free_events';
            if ( ! in_array( $mode, array('working_hours','free_events'), true ) ) $mode = 'free_events';
            $opts['availability_mode'] = $mode;
            $kw = isset($_POST['availability_keyword']) ? sanitize_text_field( wp_unslash($_POST['availability_keyword']) ) : '';
            $opts['availability_keyword'] = trim($kw);
        }
        if ( isset( $_POST['save_slot_rules'] ) ) {
            $opts['working_hours_start'] = isset($_POST['working_hours_start']) ? sanitize_text_field( wp_unslash($_POST['working_hours_start']) ) : '09:00';
            $opts['working_hours_end']   = isset($_POST['working_hours_end']) ? sanitize_text_field( wp_unslash($_POST['working_hours_end']) )   : '17:00';
            $weekdays = array();
            if ( isset($_POST['working_weekdays']) && is_array($_POST['working_weekdays']) ) {
                foreach ( $_POST['working_weekdays'] as $d ) {
                    $d = sanitize_text_field( wp_unslash($d) );
                    if ( in_array( $d, array('1','2','3','4','5','6','7'), true ) ) $weekdays[] = $d;
                }
            }
            if ( empty($weekdays) ) $weekdays = array('1','2','3','4','5');
            $opts['working_weekdays'] = $weekdays;
            $slot = isset($_POST['default_slot_minutes']) ? absint($_POST['default_slot_minutes']) : 30;
            if ( $slot < 10 ) $slot = 30;
            $opts['default_slot_minutes'] = $slot;
        }
        update_option( $this->option_key, $opts, 'no' );
        $this->options = $opts;
        wp_safe_redirect( admin_url( 'admin.php?page=mrm-scheduler-google&google_saved=1' ) );
        exit;
    }

    public function handle_test_google_settings() {
        if ( ! current_user_can( self::CAPABILITY ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'mrm_scheduler_test_google', 'mrm_scheduler_google_test_nonce' );
        if ( ! $this->google_is_configured() ) {
            $msg = 'Google settings not configured yet. Paste Service Account JSON and save.';
            wp_safe_redirect( admin_url( 'admin.php?page=mrm-scheduler-google&test=fail&msg=' . rawurlencode($msg) ) );
            exit;
        }
        $token = $this->google_get_access_token();
        if ( is_wp_error( $token ) ) {
            $msg = 'Token fetch failed: ' . $token->get_error_message();
            wp_safe_redirect( admin_url( 'admin.php?page=mrm-scheduler-google&test=fail&msg=' . rawurlencode($msg) ) );
            exit;
        }
        $msg = 'Success: Access token obtained. Next: share an instructor calendar with the Service Account email and test /availability.';
        wp_safe_redirect( admin_url( 'admin.php?page=mrm-scheduler-google&test=ok&msg=' . rawurlencode($msg) ) );
        exit;
    }

    /* =========================================================
     * Google Calendar (Service Account JWT)
     * ========================================================= */
    protected function get_settings() {
        $opts = get_option( $this->option_key, array() );
        return is_array( $opts ) ? $opts : array();
    }

    protected function google_is_configured() {
        $opts = $this->get_settings();
        $json = isset( $opts['google_service_account_json'] ) ? (string) $opts['google_service_account_json'] : '';
        $parsed = $this->parse_service_account_json( $json );
        return ( is_array( $parsed ) && ! empty( $parsed['client_email'] ) && ! empty( $parsed['private_key'] ) );
    }

    protected function google_get_availability_mode() {
        $opts = $this->get_settings();
        $mode = isset($opts['availability_mode']) ? (string) $opts['availability_mode'] : 'free_events';
        if ( ! in_array( $mode, array('working_hours','free_events'), true ) ) $mode = 'free_events';
        return $mode;
    }

    protected function google_get_availability_keyword() {
        $opts = $this->get_settings();
        $kw = isset($opts['availability_keyword']) ? (string) $opts['availability_keyword'] : 'AVAILABLE';
        return trim($kw);
    }

    protected function parse_service_account_json( $json ) {
        $json = is_string($json) ? trim($json) : '';
        if ( $json === '' ) return null;
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) return null;
        if ( empty( $data['client_email'] ) || empty( $data['private_key'] ) ) return null;
        return array(
            'client_email' => (string) $data['client_email'],
            'private_key'  => (string) $data['private_key'],
            'token_uri'    => ! empty($data['token_uri']) ? (string) $data['token_uri'] : self::GOOGLE_TOKEN_URL,
        );
    }

    /**
     * Cache-bust token used to invalidate availability transients immediately after bookings.
     */
    protected function get_cache_bust_token( $calendar_id = '' ) {
        $v = get_option( 'mrm_scheduler_cache_bust', 0 );
        return is_scalar( $v ) ? (string) $v : '0';
    }

    /**
     * Bump cache-bust token (called after successful bookings).
     */
    protected function bump_cache_bust_token() {
        update_option( 'mrm_scheduler_cache_bust', time() );
    }

    /**
     * Busy intervals from lessons stored in the DB.
     * - Times are stored in UTC in the DB.
     * - In-person lessons (is_online=0) enforce a 30-minute buffer before and after.
     */
    protected function db_busy_intervals_for_instructor( $instructor_id, $timeMin, $timeMax ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mrm_lessons';
        $min_ts = strtotime( (string) $timeMin );
        $max_ts = strtotime( (string) $timeMax );
        if ( ! $min_ts || ! $max_ts ) return array();
        $pad = 60 * 60; // 1 hour
        $min_dt = gmdate( 'Y-m-d H:i:s', $min_ts - $pad );
        $max_dt = gmdate( 'Y-m-d H:i:s', $max_ts + $pad );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT start_time, end_time, is_online FROM {$table} WHERE instructor_id = %d AND status = %s AND end_time >= %s AND start_time <= %s",
            (int) $instructor_id, 'scheduled', $min_dt, $max_dt
        ), ARRAY_A );
        if ( ! is_array( $rows ) ) return array();
        $out = array();
        foreach ( $rows as $r ) {
            $s = strtotime( (string) $r['start_time'] . ' UTC' );
            $e = strtotime( (string) $r['end_time'] . ' UTC' );
            if ( ! $s || ! $e || $e <= $s ) continue;
            $buffer = empty( $r['is_online'] ) ? 30 * 60 : 0;
            $bs = $s - $buffer;
            $be = $e + $buffer;
            $raw_duration_minutes = (int) round( ( $e - $s ) / 60 );
            $lesson_type = empty( $r['is_online'] ) ? 'in_person' : 'online';

            $out[] = array(
                'start'          => gmdate( 'c', $bs ),
                'end'            => gmdate( 'c', $be ),
                'start_ts'       => (int) $bs,
                'end_ts'         => (int) $be,

                // Metadata so the frontend can render/split online blocks correctly
                'lesson_type'    => $lesson_type,
                'lesson_minutes' => $raw_duration_minutes,

                'source'         => 'db',
            );
        }
        return $out;
    }

    /**
     * Merge overlapping intervals (expects start_ts/end_ts).
     */
    protected function merge_intervals( $intervals ) {
        $ints = array();
        foreach ( (array) $intervals as $i ) {
            $st = isset( $i['start_ts'] ) ? (int) $i['start_ts'] : 0;
            $en = isset( $i['end_ts'] ) ? (int) $i['end_ts'] : 0;
            if ( $st && $en && $en > $st ) {
                $ints[] = array(
                    'start'    => isset( $i['start'] ) ? (string) $i['start'] : gmdate( 'c', $st ),
                    'end'      => isset( $i['end'] ) ? (string) $i['end'] : gmdate( 'c', $en ),
                    'start_ts' => $st,
                    'end_ts'   => $en,
                    'lesson_type' => array_key_exists( 'lesson_type', $i ) ? $i['lesson_type'] : null,
                    'lesson_minutes' => array_key_exists( 'lesson_minutes', $i ) ? $i['lesson_minutes'] : null,
                    'source' => array_key_exists( 'source', $i ) ? $i['source'] : null,
                );
            }
        }
        usort( $ints, function( $a, $b ) { return $a['start_ts'] <=> $b['start_ts']; } );
        $merged = array();
        foreach ( $ints as $i ) {
            if ( empty( $merged ) ) { $merged[] = $i; continue; }
            $last = count( $merged ) - 1;
            if ( $i['start_ts'] < $merged[$last]['end_ts'] ) {
                $merged[$last]['end_ts'] = max( $merged[$last]['end_ts'], $i['end_ts'] );
                $merged[$last]['end'] = gmdate( 'c', $merged[$last]['end_ts'] );
                $fields = array( 'lesson_type', 'lesson_minutes', 'source' );
                foreach ( $fields as $field ) {
                    $existing = array_key_exists( $field, $merged[ $last ] ) ? $merged[ $last ][ $field ] : null;
                    $incoming = array_key_exists( $field, $i ) ? $i[ $field ] : null;
                    if ( $existing === null ) {
                        $merged[ $last ][ $field ] = $incoming;
                    } elseif ( $incoming === null || $incoming === $existing ) {
                        $merged[ $last ][ $field ] = $existing;
                    } else {
                        $merged[ $last ][ $field ] = null;
                    }
                }
            } else {
                $merged[] = $i;
            }
        }
        return $merged;
    }

    protected function google_get_working_hours() {
        $opts = $this->get_settings();
        return array(
            'start' => isset($opts['working_hours_start']) ? (string) $opts['working_hours_start'] : '09:00',
            'end'   => isset($opts['working_hours_end']) ? (string) $opts['working_hours_end']   : '17:00',
            'weekdays' => isset($opts['working_weekdays']) && is_array($opts['working_weekdays']) ? $opts['working_weekdays'] : array('1','2','3','4','5'),
            'default_slot_minutes' => isset($opts['default_slot_minutes']) ? (int) $opts['default_slot_minutes'] : 30,
        );
    }

    
    /**
     * Normalize any strtotime-parseable time string to an RFC3339 UTC string ending in 'Z'.
     * This avoids '+' characters in query params (which can be misinterpreted as spaces by proxies),
     * and ensures Google Calendar receives strict RFC3339 timestamps.
     *
     * @param string $time A strtotime()-parseable time string (RFC3339 recommended)
     * @return string|WP_Error RFC3339 UTC string (e.g. 2026-01-14T20:15:00Z) or WP_Error on failure
     */
    protected function to_rfc3339_utc( $time ) {
        $time = is_string( $time ) ? trim( $time ) : '';
        $ts = strtotime( $time );
        if ( $ts === false ) {
            return new WP_Error( 'invalid_time', 'Invalid time value (expected RFC3339).' );
        }
        return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
    }

protected function base64url_encode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    protected function google_make_jwt( $client_email, $private_key, $scope, $token_url, $subject = '' ) {
        $now = time();
        $header = array(
            'alg' => 'RS256',
            'typ' => 'JWT',
        );
        $claims = array(
            'iss'   => $client_email,
            'scope' => $scope,
            'aud'   => $token_url,
            'iat'   => $now,
            'exp'   => $now + 3600,
        );
        if ( $subject ) $claims['sub'] = $subject;
        $segments = array(
            $this->base64url_encode( wp_json_encode( $header ) ),
            $this->base64url_encode( wp_json_encode( $claims ) ),
        );
        $signing_input = implode( '.', $segments );
        $signature = '';
        $ok = openssl_sign( $signing_input, $signature, $private_key, 'sha256' );
        if ( ! $ok ) return new WP_Error( 'jwt_sign_failed', 'OpenSSL failed to sign JWT. Make sure OpenSSL is enabled on your server.' );
        $segments[] = $this->base64url_encode( $signature );
        return implode( '.', $segments );
    }

    protected function google_get_access_token( $scope = '', $subject_override = null ) {
        // Default to Calendar scope for ALL scheduling / availability behavior.
        // Meet scope is requested only by the Meet creation code path.
        $scope = is_string( $scope ) ? trim( $scope ) : '';
        if ( $scope === '' ) {
            $scope = 'https://www.googleapis.com/auth/calendar';
        }

        $subject_override = is_string( $subject_override ) ? trim( $subject_override ) : '';
        $scope_key = md5( $scope . '|' . $subject_override );
        $cache_key = 'mrm_google_access_token_' . $scope_key;
        $cached = get_transient( $cache_key );
        if ( is_string( $cached ) && $cached !== '' ) return $cached;
        $opts = $this->get_settings();
        $parsed = $this->parse_service_account_json( isset($opts['google_service_account_json']) ? $opts['google_service_account_json'] : '' );
        if ( ! $parsed ) return new WP_Error( 'google_not_configured', 'Service Account JSON not configured.' );
        $client_email = $parsed['client_email'];
        $private_key  = $parsed['private_key'];
        $token_url    = ! empty($parsed['token_uri']) ? $parsed['token_uri'] : self::GOOGLE_TOKEN_URL;
        $subject      = isset( $opts['google_delegated_user'] ) ? (string) $opts['google_delegated_user'] : '';
        if ( $subject_override !== '' ) {
            $subject = $subject_override;
        }
        $jwt = $this->google_make_jwt( $client_email, $private_key, $scope, $token_url, $subject );
        if ( is_wp_error( $jwt ) ) return $jwt;
        $resp = wp_remote_post( $token_url, array(
            'timeout' => 20,
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'body'    => http_build_query( array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ) ),
        ) );
        if ( is_wp_error( $resp ) ) return $resp;
        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );
        if ( $code < 200 || $code >= 300 || ! is_array($data) || empty($data['access_token']) ) {
            $detail = is_array($data) && ! empty($data['error_description']) ? $data['error_description'] : $body;
            return new WP_Error( 'google_token_failed', 'Token request failed: ' . $detail );
        }
        $token = (string) $data['access_token'];
        set_transient( $cache_key, $token, 55 * MINUTE_IN_SECONDS );
        return $token;
    }

    protected function google_freebusy( $calendar_id, $timeMin, $timeMax ) {
        $token = $this->google_get_access_token();
        if ( is_wp_error( $token ) ) return $token;

        $timeMin_n = $this->to_rfc3339_utc( $timeMin );
        if ( is_wp_error( $timeMin_n ) ) return $timeMin_n;
        $timeMax_n = $this->to_rfc3339_utc( $timeMax );
        if ( is_wp_error( $timeMax_n ) ) return $timeMax_n;

        // Allow a single calendar id OR an array of calendar ids.
        $cal_ids = array();
        if ( is_array( $calendar_id ) ) {
            foreach ( $calendar_id as $cid ) {
                $cid = is_scalar( $cid ) ? (string) $cid : '';
                $cid = trim( $cid );
                if ( $cid !== '' ) $cal_ids[] = $cid;
            }
        } else {
            $cid = is_scalar( $calendar_id ) ? (string) $calendar_id : '';
            $cid = trim( $cid );
            if ( $cid !== '' ) $cal_ids[] = $cid;
        }
        $cal_ids = array_values( array_unique( $cal_ids ) );
        if ( empty( $cal_ids ) ) {
            return new WP_Error( 'google_freebusy_bad_calendar', 'No calendar_id provided for FreeBusy.' );
        }

        $items = array();
        foreach ( $cal_ids as $cid ) {
            $items[] = array( 'id' => $cid );
        }

        $payload = array(
            'timeMin'  => $timeMin_n,
            'timeMax'  => $timeMax_n,
            'timeZone' => 'UTC',
            'items'    => $items,
        );

        $resp = wp_remote_post( self::GOOGLE_FREEBUSY_URL, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $resp ) ) return $resp;
        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
            return new WP_Error( 'google_freebusy_http', 'FreeBusy failed (HTTP ' . $code . ') — ' . $body );
        }
        if ( empty( $data['calendars'] ) || ! is_array( $data['calendars'] ) ) {
            return new WP_Error( 'google_freebusy_no_calendars', 'FreeBusy response missing calendars.' );
        }

        // Merge busy blocks across all requested calendars (ignoring calendars we cannot access).
        $merged_busy = array();
        foreach ( $cal_ids as $cid ) {
            if ( empty( $data['calendars'][ $cid ] ) || ! is_array( $data['calendars'][ $cid ] ) ) continue;
            if ( ! empty( $data['calendars'][ $cid ]['busy'] ) && is_array( $data['calendars'][ $cid ]['busy'] ) ) {
                foreach ( $data['calendars'][ $cid ]['busy'] as $b ) {
                    if ( is_array( $b ) && ! empty( $b['start'] ) && ! empty( $b['end'] ) ) {
                        $merged_busy[] = $b;
                    }
                }
            }
        }

        return $merged_busy;
    }


    /**
     * Build a map of busy event metadata keyed by start_ts|end_ts.
     * Returns lesson metadata when available in extendedProperties.private.
     */
    protected function google_get_busy_meta_map( $calendar_ids, $timeMin, $timeMax ) {
        if ( ! $this->google_is_configured() ) return array();
        $cal_ids = array();
        if ( is_array( $calendar_ids ) ) {
            foreach ( $calendar_ids as $cid ) {
                $cid = is_scalar( $cid ) ? trim( (string) $cid ) : '';
                if ( $cid !== '' ) $cal_ids[] = $cid;
            }
        } else {
            $cid = is_scalar( $calendar_ids ) ? trim( (string) $calendar_ids ) : '';
            if ( $cid !== '' ) $cal_ids[] = $cid;
        }
        $cal_ids = array_values( array_unique( $cal_ids ) );
        if ( empty( $cal_ids ) ) return array();

        $meta_map = array();
        foreach ( $cal_ids as $cid ) {
            $events_payload = $this->google_list_events( $cid, $timeMin, $timeMax );
            if ( is_wp_error( $events_payload ) || ! is_array( $events_payload ) ) continue;
            $events = isset( $events_payload['items'] ) && is_array( $events_payload['items'] ) ? $events_payload['items'] : array();
            foreach ( $events as $ev ) {
                $transparency = isset( $ev['transparency'] ) ? (string) $ev['transparency'] : '';
                if ( $transparency === 'transparent' ) continue;
                $start_dt = isset( $ev['start']['dateTime'] ) ? (string) $ev['start']['dateTime'] : '';
                $end_dt   = isset( $ev['end']['dateTime'] )   ? (string) $ev['end']['dateTime']   : '';
                if ( ! $start_dt || ! $end_dt ) continue;
                $start_ts = strtotime( $start_dt );
                $end_ts   = strtotime( $end_dt );
                if ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) continue;
                $private = isset( $ev['extendedProperties']['private'] ) && is_array( $ev['extendedProperties']['private'] )
                    ? $ev['extendedProperties']['private']
                    : array();
                $key = $start_ts . '|' . $end_ts;
                if ( isset( $meta_map[ $key ] ) ) continue;
                $meta_map[ $key ] = array(
                    'lesson_type' => isset( $private['lesson_type'] ) ? (string) $private['lesson_type'] : null,
                    'lesson_minutes' => isset( $private['lesson_minutes'] ) ? (string) $private['lesson_minutes'] : null,
                    'source' => isset( $private['source'] ) ? (string) $private['source'] : null,
                );
            }
        }
        return $meta_map;
    }

    protected function google_busy_intervals_from_events( $calendar_ids, $timeMin, $timeMax, $tz ) {
        $out = array();
        $seen = array();

        foreach ( ( $calendar_ids ?: array() ) as $cal_id ) {
            if ( ! $cal_id ) continue;

            $events_payload = $this->google_list_events( $cal_id, $timeMin, $timeMax );
            if ( ! is_array( $events_payload ) ) continue;
            $events = isset( $events_payload['items'] ) && is_array( $events_payload['items'] ) ? $events_payload['items'] : array();
            if ( empty( $events ) ) continue;

            foreach ( $events as $ev ) {
                // Skip "transparent" events (do not block time)
                $transparency = isset( $ev['transparency'] ) ? strtolower( trim( (string) $ev['transparency'] ) ) : '';
                if ( $transparency === 'transparent' ) continue;

                $start_iso = null;
                $end_iso   = null;

                if ( isset( $ev['start']['dateTime'] ) ) {
                    $start_iso = $ev['start']['dateTime'];
                    $end_iso   = $ev['end']['dateTime'] ?? null;
                } elseif ( isset( $ev['start']['date'] ) ) {
                    // All-day: treat as blocking the whole day
                    // Use midnight boundaries in calendar TZ
                    $start_iso = $ev['start']['date'] . 'T00:00:00';
                    $end_iso   = ( $ev['end']['date'] ?? $ev['start']['date'] ) . 'T00:00:00';
                }

                if ( ! $start_iso || ! $end_iso ) continue;

                $s_ts = strtotime( $start_iso );
                $e_ts = strtotime( $end_iso );
                if ( ! $s_ts || ! $e_ts || $e_ts <= $s_ts ) continue;

                $priv = $ev['extendedProperties']['private'] ?? array();
                $lesson_type = isset( $priv['lesson_type'] ) ? (string) $priv['lesson_type'] : null;
                $lesson_minutes = isset( $priv['lesson_minutes'] ) ? intval( $priv['lesson_minutes'] ) : null;
                $source = isset( $priv['source'] ) ? (string) $priv['source'] : null;

                // Unique key so we don't accidentally duplicate recurring instances
                $k = $cal_id . '|' . $s_ts . '|' . $e_ts;
                if ( isset( $seen[ $k ] ) ) continue;
                $seen[ $k ] = true;

                $out[] = array(
                    'start_ts' => $s_ts,
                    'end_ts' => $e_ts,
                    'start' => gmdate( 'c', $s_ts ),
                    'end' => gmdate( 'c', $e_ts ),
                    'lesson_type' => $lesson_type,
                    'lesson_minutes' => $lesson_minutes,
                    'source' => $source,
                );
            }
        }

        usort( $out, function( $a, $b ) {
            if ( $a['start_ts'] === $b['start_ts'] ) return $a['end_ts'] <=> $b['end_ts'];
            return $a['start_ts'] <=> $b['start_ts'];
        } );

        return $out;
    }

    /**
     * Convert Google events to availability windows:
     * - Only events with transparency == 'transparent' (Show as: Free)
     * - All-day events (start.date/end.date) are ignored
     * - Merges overlapping windows
     *
     * Note: Keyword filtering has been removed; all Free events count as availability.
     * @param array $events List of Google event objects
     * @param string $keyword Ignored parameter (kept for backward compatibility)
     * @return array List of merged availability windows with start_ts and end_ts (Unix timestamps)
     */
    protected function events_to_availability_windows( $events, $keyword = '' ) {
        $windows = array();
        foreach ( $events as $ev ) {
            $trans = isset( $ev['transparency'] ) ? (string) $ev['transparency'] : '';
            if ( $trans !== 'transparent' ) continue; // must be "Free"
            // Timed events only (ignore all-day)
            $start_dt = isset( $ev['start']['dateTime'] ) ? (string) $ev['start']['dateTime'] : '';
            $end_dt   = isset( $ev['end']['dateTime'] )   ? (string) $ev['end']['dateTime']   : '';
            if ( ! $start_dt || ! $end_dt ) continue;
            $s = strtotime( $start_dt );
            $e = strtotime( $end_dt );
            if ( ! $s || ! $e || $e <= $s ) continue;
            $windows[] = array(
                'start_ts' => $s,
                'end_ts'   => $e,
            );
        }
        usort( $windows, function( $a, $b ) {
            return $a['start_ts'] <=> $b['start_ts'];
        } );
        // merge overlaps
        $merged = array();
        foreach ( $windows as $w ) {
            if ( empty( $merged ) ) {
                $merged[] = $w;
                continue;
            }
            $last = &$merged[count( $merged ) - 1];
            if ( $w['start_ts'] <= $last['end_ts'] ) {
                $last['end_ts'] = max( $last['end_ts'], $w['end_ts'] );
            } else {
                $merged[] = $w;
            }
            unset( $last );
        }
        return $merged;
    }

    /**
     * Subtract busy intervals from availability windows (splitting as needed).
     */
    protected function subtract_busy_from_availability( $availability_windows, $busy_intervals ) {
        // Convert busy to ts-only list
        $busy = array();
        foreach ( $busy_intervals as $b ) {
            $bs = isset( $b['start_ts'] ) ? (int) $b['start_ts'] : 0;
            $be = isset( $b['end_ts'] )   ? (int) $b['end_ts']   : 0;
            if ( $bs && $be && $be > $bs ) $busy[] = array( 'start_ts' => $bs, 'end_ts' => $be );
        }
        usort( $busy, function( $a, $b ) {
            return $a['start_ts'] <=> $b['start_ts'];
        } );
        $result = array();
        foreach ( $availability_windows as $win ) {
            $segments = array( $win );
            foreach ( $busy as $b ) {
                $new_segments = array();
                foreach ( $segments as $seg ) {
                    $s1 = $seg['start_ts'];
                    $e1 = $seg['end_ts'];
                    $s2 = $b['start_ts'];
                    $e2 = $b['end_ts'];
                    // no overlap
                    if ( $e2 <= $s1 || $s2 >= $e1 ) {
                        $new_segments[] = $seg;
                        continue;
                    }
                    // overlap -> split possible left/right
                    if ( $s2 > $s1 ) {
                        $new_segments[] = array( 'start_ts' => $s1, 'end_ts' => min( $s2, $e1 ) );
                    }
                    if ( $e2 < $e1 ) {
                        $new_segments[] = array( 'start_ts' => max( $e2, $s1 ), 'end_ts' => $e1 );
                    }
                }
                $segments = $new_segments;
                if ( empty( $segments ) ) break;
            }
            foreach ( $segments as $seg ) {
                if ( $seg['end_ts'] > $seg['start_ts'] ) $result[] = $seg;
            }
        }
        usort( $result, function( $a, $b ) {
            return $a['start_ts'] <=> $b['start_ts'];
        } );
        return $result;
    }

    /**
     * Build slots from free windows in UTC, aligned to slot_minutes.
     */
    protected function build_slots_from_windows( $windows, $slot_minutes ) {
        $slots = array();
        $step = max( 10, (int) $slot_minutes ) * 60;
        foreach ( $windows as $w ) {
            $cursor = (int) $w['start_ts'];
            $end    = (int) $w['end_ts'];
            while ( $cursor + $step <= $end ) {
                $slots[] = array(
                    'start' => gmdate( 'c', $cursor ),
                    'end'   => gmdate( 'c', $cursor + $step ),
                );
                $cursor += $step;
            }
        }
        return $slots;
    }

    /**
     * Existing Mode A slot builder: iterate days, working hours, remove overlaps with busy.
     */
    protected function build_available_slots( $start_date, $end_date, $tz, $slot_minutes, $work, $busy_intervals ) {
        $slots = array();
        $weekdays = isset( $work['weekdays'] ) && is_array( $work['weekdays'] ) ? $work['weekdays'] : array('1','2','3','4','5');
        $work_start = isset( $work['start'] ) ? (string) $work['start'] : '09:00';
        $work_end   = isset( $work['end'] )   ? (string) $work['end']   : '17:00';
        try {
            $tzObj = new DateTimeZone( $tz );
            $utc   = new DateTimeZone( 'UTC' );
        } catch ( Exception $e ) {
            $tzObj = new DateTimeZone( 'UTC' );
            $utc   = new DateTimeZone( 'UTC' );
        }
        $day = new DateTime( $start_date . ' 00:00:00', $tzObj );
        $end = new DateTime( $end_date   . ' 00:00:00', $tzObj );
        while ( $day <= $end ) {
            $dow = $day->format( 'N' ); // 1..7
            if ( in_array( (string) $dow, $weekdays, true ) ) {
                $dayStr    = $day->format( 'Y-m-d' );
                $startLocal= new DateTime( $dayStr . ' ' . $work_start . ':00', $tzObj );
                $endLocal  = new DateTime( $dayStr . ' ' . $work_end   . ':00', $tzObj );
                $cursor    = clone $startLocal;
                while ( $cursor < $endLocal ) {
                    $slotEnd = clone $cursor;
                    $slotEnd->modify( '+' . (int) $slot_minutes . ' minutes' );
                    if ( $slotEnd > $endLocal ) break;
                    $slotStartUtc = clone $cursor;
                    $slotStartUtc->setTimezone( $utc );
                    $slotEndUtc   = clone $slotEnd;
                    $slotEndUtc->setTimezone( $utc );
                    $slotStartTs  = $slotStartUtc->getTimestamp();
                    $slotEndTs    = $slotEndUtc->getTimestamp();
                    $overlap = false;
                    foreach ( $busy_intervals as $b ) {
                        $bs = isset( $b['start_ts'] ) ? (int) $b['start_ts'] : 0;
                        $be = isset( $b['end_ts'] )   ? (int) $b['end_ts']   : 0;
                        if ( $bs && $be && $slotStartTs < $be && $slotEndTs > $bs ) {
                            $overlap = true;
                            break;
                        }
                    }
                    if ( ! $overlap ) {
                        $slots[] = array(
                            'start' => gmdate( 'c', $slotStartTs ),
                            'end'   => gmdate( 'c', $slotEndTs ),
                        );
                    }
                    $cursor->modify( '+' . (int) $slot_minutes . ' minutes' );
                }
            }
            $day->modify( '+1 day' );
        }
        return $slots;
    }

    protected function dummy_slots( $start_date ) {
        return array(
            array( 'start' => $start_date . 'T15:00:00Z', 'end' => $start_date . 'T15:30:00Z' ),
            array( 'start' => $start_date . 'T16:00:00Z', 'end' => $start_date . 'T16:30:00Z' ),
            array( 'start' => gmdate( 'Y-m-d', strtotime( $start_date . ' +1 day' ) ) . 'T17:00:00Z', 'end' => gmdate( 'Y-m-d', strtotime( $start_date . ' +1 day' ) ) . 'T17:30:00Z' ),
        );
    }

    /* =========================================================
     * Agreements + Utilities
     * ========================================================= */
    protected function mrm_get_email_logo_url() {
        // Prefer the WordPress Site Icon (reliable on most WP setups)
        $site_icon = get_site_icon_url( 256 );
        if ( $site_icon ) return $site_icon;

        // Fallback: custom logo (theme-dependent)
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) {
            $logo = wp_get_attachment_image_src( $custom_logo_id, 'full' );
            if ( is_array( $logo ) && ! empty( $logo[0] ) ) return $logo[0];
        }
        return '';
    }

    protected function mrm_wrap_email_html( $title, $intro_html, $details_html, $button_url, $button_text, $options = array() ) {
        $brand = '#780000';
        $options = is_array( $options ) ? $options : array();
        $button_color = isset( $options['button_color'] ) ? (string) $options['button_color'] : $brand;
        $button_align = isset( $options['button_align'] ) ? (string) $options['button_align'] : 'center';
        $logo  = $this->mrm_get_email_logo_url();
        $site  = esc_html( get_bloginfo( 'name' ) );

        $logo_html = '';
        if ( $logo ) {
            $logo_html = '<div style="text-align:center;margin:0 0 18px 0;">
                <img src="' . esc_url( $logo ) . '" alt="' . $site . '" style="max-width:220px;height:auto;border:0;"/>
            </div>';
        }

        $btn_html = '';
        if ( $button_url ) {
            $btn_html = '<div style="text-align:' . esc_attr( $button_align ) . ';margin:22px 0 10px 0;">
                <a href="' . esc_url( $button_url ) . '" style="display:inline-block;background:' . esc_attr( $button_color ) . ';color:#ffffff;text-decoration:none;font-weight:700;padding:12px 18px;border-radius:10px;">
                    ' . esc_html( $button_text ? $button_text : 'Open Link' ) . '
                </a>
            </div>
            <div style="text-align:center;font-size:12px;color:#666;margin-top:10px;">
                If the button doesn’t work, copy and paste this link:<br/>
                <span style="word-break:break-all;">' . esc_html( $button_url ) . '</span>
            </div>';
        }

        return '<!doctype html><html><body style="margin:0;padding:0;background:#f6f6f6;">
            <div style="max-width:640px;margin:0 auto;padding:24px;">
                <div style="background:#ffffff;border-radius:16px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,0.06);font-family:Arial,sans-serif;">
                    ' . $logo_html . '
                    <h1 style="margin:0 0 10px 0;font-size:20px;line-height:1.3;color:#111;">' . esc_html( $title ) . '</h1>
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

    protected function maybe_store_agreement( $email, $version, $signature, $ip ) {
        global $wpdb;
        if ( ! $email || ! $version || ! $signature ) return 0;
        $table = $wpdb->prefix . 'mrm_agreements';
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s AND agreement_version = %s", $email, $version ) );
        if ( $existing ) return (int) $existing;
        $wpdb->insert( $table, array(
            'email'            => $email,
            'agreement_version'=> $version,
            'signature'        => $signature,
            'signed_at'        => current_time( 'mysql' ),
            'ip_address'       => $ip,
        ), array( '%s','%s','%s','%s','%s' ) );
        return (int) $wpdb->insert_id;
    }

    /**
     * Compute Haversine distance between two lat/lng points.
     */
    public static function haversine_distance( $lat1, $lon1, $lat2, $lon2, $unit = 'miles' ) {
        $theta = $lon1 - $lon2;
        $distance = ( sin( deg2rad( $lat1 ) ) * sin( deg2rad( $lat2 ) ) ) + ( cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * cos( deg2rad( $theta ) ) );
        $distance = acos( min( 1, max( -1, $distance ) ) );
        $distance = rad2deg( $distance );
        $distance = $distance * 60 * 1.1515;
        if ( 'kilometers' === $unit ) $distance = $distance * 1.609344;
        return round( $distance, 2 );
    }

    /**
     * Insert a new event on Google Calendar for a booked lesson.
     * Marks the event as busy (transparency = opaque). Returns true on success or WP_Error on failure.
     *
     * @param string $calendar_id Google Calendar ID
     * @param string $title       Event title
     * @param string $description Event description
     * @param string $location    Event location
     * @param string $start_local Start datetime in instructor's local time (Y-m-d\TH:i:s)
     * @param string $end_local   End datetime in instructor's local time (Y-m-d\TH:i:s)
     * @param string $timezone    Timezone identifier (e.g. America/Phoenix)
     * @param array  $extended_private Extended private properties
     * @param array  $recurrence Recurrence rule array
     * @param bool   $create_meet Whether to request a Google Meet link
     * @param array  $attendee_emails Emails to add as attendees for calendar reminders
     * @return true|WP_Error
     */
    protected function google_insert_event( $calendar_id, $title, $description, $location, $start_rfc3339, $end_rfc3339, $timezone, $extended_private, $recurrence = array(), $create_meet = false, $attendee_emails = array() ) {
        $token = $this->google_get_access_token();
        if ( is_wp_error( $token ) ) return $token;

        // Prevent double-encoding of calendar IDs stored as %40, etc.
        $calendar_id = rawurldecode( trim( (string) $calendar_id ) );

        $start_rfc3339 = is_string( $start_rfc3339 ) ? trim( $start_rfc3339 ) : '';
        $end_rfc3339   = is_string( $end_rfc3339 ) ? trim( $end_rfc3339 ) : '';
        if ( $start_rfc3339 === '' || $end_rfc3339 === '' ) {
            return new WP_Error( 'google_insert_event_failed', 'Google insert failed: missing start/end dateTime.' );
        }

        $payload = array(
            'summary' => (string) $title,
            'description' => (string) $description,
            'location' => (string) $location,
            // Force yellow regardless of calendar color
            'colorId' => '5',
            'start' => array(
                'dateTime' => $start_rfc3339,
                'timeZone' => (string) $timezone,
            ),
            'end' => array(
                'dateTime' => $end_rfc3339,
                'timeZone' => (string) $timezone,
            ),
            // Show as Busy
            'transparency' => 'opaque',
        );

        // Disable Calendar reminders for events created by this plugin.
        // (You requested to remove the 1-hour-before reminder entirely.)
        $payload['reminders'] = array(
            'useDefault' => false,
            'overrides'  => array(),
        );

        // --- Calendar-managed reminders + email recipients (Option A) ---
        // If we add attendees AND an email reminder override, Google will send
        // reminder emails 60 minutes before the CURRENT event start time,
        // even if the event is moved in Google Calendar.
        if ( isset( $attendee_emails ) && is_array( $attendee_emails ) && ! empty( $attendee_emails ) ) {
            $attendees = array();
            foreach ( $attendee_emails as $em ) {
                $em = trim( (string) $em );
                if ( $em !== '' && is_email( $em ) ) {
                    $attendees[] = array( 'email' => $em );
                }
            }
            if ( ! empty( $attendees ) ) {
                $payload['attendees'] = $attendees;
            }
        }
        if ( ! empty( $recurrence ) ) {
            $payload['recurrence'] = $recurrence;
        }
        if ( is_array( $extended_private ) && ! empty( $extended_private ) ) {
            $private = array();
            foreach ( $extended_private as $key => $value ) {
                if ( $value === null || $value === '' ) continue;
                $private[ (string) $key ] = (string) $value;
            }
            if ( ! empty( $private ) ) {
                $payload['extendedProperties'] = array(
                    'private' => $private,
                );
            }
        }

        // If requested, generate a unique Google Meet link for this event.
        // IMPORTANT: do NOT force Meet creation based on lesson_type / appointment_type.
        // Meet creation must be explicitly requested by the caller.
        $want_meet = (bool) $create_meet;
        if ( $want_meet ) {
            // requestId must be unique per createRequest
            $request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 8 ) );
            $payload['conferenceData'] = array(
                'createRequest' => array(
                    'requestId' => $request_id,
                    'conferenceSolutionKey' => array(
                        // Google Meet
                        'type' => 'hangoutsMeet',
                    ),
                ),
            );

            // Optional: make location clearly “online”
            if ( empty( $payload['location'] ) || stripos( (string) $payload['location'], 'meet' ) === false ) {
                $payload['location'] = 'Google Meet';
            }
        }

        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $calendar_id ) . '/events';

        // If attendees are present, tell Google to email them event updates/invites.
        $url = add_query_arg( 'sendUpdates', 'all', $url );

        // Required for Meet link creation when conferenceData is present
        if ( $want_meet ) {
            $url = add_query_arg( 'conferenceDataVersion', 1, $url );
        }

        $resp = wp_remote_post( $url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $resp ) ) return $resp;

        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'google_insert_event_failed', 'Google insert failed (' . $code . '): ' . $body );
        }

        $data = json_decode( $body, true );
        if ( is_array( $data ) && ! empty( $data['id'] ) ) {
            $meet_url = '';
            if ( ! empty( $data['hangoutLink'] ) ) {
                $meet_url = (string) $data['hangoutLink'];
            } elseif ( ! empty( $data['conferenceData']['entryPoints'] ) && is_array( $data['conferenceData']['entryPoints'] ) ) {
                foreach ( $data['conferenceData']['entryPoints'] as $entry_point ) {
                    if ( ! is_array( $entry_point ) ) continue;
                    if ( isset( $entry_point['entryPointType'] ) && $entry_point['entryPointType'] === 'video' && ! empty( $entry_point['uri'] ) ) {
                        $meet_url = (string) $entry_point['uri'];
                        break;
                    }
                }
            }
            return array(
                'id' => (string) $data['id'],
                'meet_url' => $meet_url,
            );
        }
        return true;
    }
}

/**
 * Activation hook in main scope.
 */
register_activation_hook( __FILE__, array( 'MRM_Lesson_Scheduler', 'activate' ) );

// Boot plugin.
MRM_Lesson_Scheduler::get_instance();

/**
 * Admin notice: google test messages
 */
add_action( 'admin_notices', function() {
    if ( ! is_admin() ) return;
    if ( ! isset($_GET['page']) || $_GET['page'] !== 'mrm-scheduler-google' ) return;
    if ( isset($_GET['test']) && isset($_GET['msg']) ) {
        $type = ($_GET['test'] === 'ok') ? 'success' : 'error';
        $msg = sanitize_text_field( wp_unslash($_GET['msg']) );
        echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($msg) . '</p></div>';
    }
} );
