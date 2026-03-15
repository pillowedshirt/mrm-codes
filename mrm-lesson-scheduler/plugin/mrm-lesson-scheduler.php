
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
 *   "Free" events define availability windows (calendar-driven scheduling)
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
    const DB_VERSION = '1.5.5';
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


    protected function google_find_instance_by_local_start( $instances_payload, $start_mysql ) {
        if ( ! is_array( $instances_payload ) ) return null;

        $items = isset( $instances_payload['items'] ) && is_array( $instances_payload['items'] )
            ? $instances_payload['items']
            : array();

        if ( empty( $items ) ) return null;

        $target_ts = strtotime( (string) $start_mysql );
        if ( ! $target_ts ) return null;

        foreach ( $items as $ev ) {
            list( $g_start_ts, $g_end_ts ) = $this->google_event_to_utc_ts( $ev );
            if ( $g_start_ts && abs( $g_start_ts - $target_ts ) <= 180 ) {
                return $ev;
            }
        }

        return null;
    }


    protected function google_find_instance_near_now( $instances_payload, $max_diff_seconds = 43200 ) {
        if ( ! is_array( $instances_payload ) ) return null;

        $items = isset( $instances_payload['items'] ) && is_array( $instances_payload['items'] )
            ? $instances_payload['items']
            : array();

        if ( empty( $items ) ) return null;

        $now = time();
        $best = null;
        $best_diff = null;

        foreach ( $items as $ev ) {
            $start_utc = $this->google_event_start_utc( $ev );
            if ( ! $start_utc ) {
                continue;
            }

            $ts = strtotime( (string) $start_utc );
            if ( ! $ts ) {
                continue;
            }

            $diff = abs( $ts - $now );

            if ( $best === null || $diff < $best_diff ) {
                $best = $ev;
                $best_diff = $diff;
            }
        }

        if ( $best !== null && $best_diff !== null && $best_diff <= (int) $max_diff_seconds ) {
            return $best;
        }

        return null;
    }


    protected function google_find_instance_by_original_start( $instances_payload, $original_start_mysql ) {
        if ( ! is_array( $instances_payload ) ) return null;

        $items = isset( $instances_payload['items'] ) && is_array( $instances_payload['items'] )
            ? $instances_payload['items']
            : array();

        if ( empty( $items ) ) return null;

        $target_ts = strtotime( (string) $original_start_mysql );
        if ( ! $target_ts ) return null;

        // Use a much wider tolerance here because this field is intended to be
        // the immutable anchor for the recurring occurrence identity.
        $best = null;
        $best_diff = null;

        foreach ( $items as $ev ) {
            $orig = $ev['originalStartTime'] ?? null;
            if ( ! is_array( $orig ) ) {
                continue;
            }

            $orig_ts = 0;

            if ( ! empty( $orig['dateTime'] ) ) {
                $orig_ts = strtotime( (string) $orig['dateTime'] );
            } elseif ( ! empty( $orig['date'] ) ) {
                $orig_ts = strtotime( (string) $orig['date'] . ' 00:00:00 UTC' );
            }

            if ( ! $orig_ts ) {
                continue;
            }

            $diff = abs( $orig_ts - $target_ts );

            if ( $best === null || $diff < $best_diff ) {
                $best = $ev;
                $best_diff = $diff;
            }

            if ( $diff <= 180 ) {
                return $ev;
            }
        }

        // Wider fallback tolerance for recurring-reschedule recovery.
        if ( $best !== null && $best_diff !== null && $best_diff <= DAY_IN_SECONDS ) {
            return $best;
        }

        return null;
    }




    protected function get_series_master_google_event_id( $series_id ) {
        global $wpdb;

        $series_id = (int) $series_id;
        if ( $series_id <= 0 ) {
            return '';
        }

        $lessons_table = $wpdb->prefix . 'mrm_lessons';

        return (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT google_event_id
                 FROM {$lessons_table}
                 WHERE id = %d
                   AND status = 'series'
                 LIMIT 1",
                $series_id
            )
        );
    }


    protected function get_shared_google_meet_url_for_lesson( $lesson_row ) {
        global $wpdb;

        $table_lessons = $wpdb->prefix . 'mrm_lessons';
        $series_id = (int) ( $lesson_row['series_id'] ?? 0 );

        if ( $series_id > 0 ) {
            $sql = $wpdb->prepare(
                "SELECT google_meet_url
                 FROM {$table_lessons}
                 WHERE ( id = %d OR series_id = %d )
                   AND google_meet_url IS NOT NULL
                   AND google_meet_url <> ''
                 ORDER BY CASE WHEN id = %d THEN 0 ELSE 1 END, id ASC
                 LIMIT 1",
                $series_id,
                $series_id,
                $series_id
            );

            return (string) $wpdb->get_var( $sql );
        }

        return (string) ( $lesson_row['google_meet_url'] ?? '' );
    }

    protected function persist_shared_google_meet_url_for_lesson( $lesson_row, $meet_url ) {
        global $wpdb;

        $table_lessons = $wpdb->prefix . 'mrm_lessons';
        $lesson_id = (int) ( $lesson_row['id'] ?? 0 );
        $series_id = (int) ( $lesson_row['series_id'] ?? 0 );
        $meet_url = trim( (string) $meet_url );

        if ( $lesson_id <= 0 || $meet_url === '' ) {
            return;
        }

        if ( $series_id > 0 ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table_lessons}
                     SET google_meet_url = %s,
                         updated_at = %s
                     WHERE id = %d
                        OR series_id = %d",
                    $meet_url,
                    current_time( 'mysql' ),
                    $series_id,
                    $series_id
                )
            );
            return;
        }

        $wpdb->update(
            $table_lessons,
            array(
                'google_meet_url' => $meet_url,
                'updated_at'      => current_time( 'mysql' ),
            ),
            array( 'id' => $lesson_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    protected function sync_shared_token_rows_from_google_truth( $token_hash ) {
        global $wpdb;

        $token_hash = trim( (string) $token_hash );
        if ( $token_hash === '' ) {
            return;
        }

        $table_lessons = $wpdb->prefix . 'mrm_lessons';
        $table_instructors = $wpdb->prefix . 'mrm_instructors';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.*, i.calendar_id
                 FROM {$table_lessons} l
                 LEFT JOIN {$table_instructors} i ON i.id = l.instructor_id
                 WHERE l.reminder_token_hash = %s
                   AND l.status IN ('scheduled','payment_due','delivered')
                 ORDER BY l.start_time ASC
                 LIMIT 100",
                $token_hash
            ),
            ARRAY_A
        );

        if ( ! $rows ) {
            return;
        }

        foreach ( $rows as $row ) {
            $calendar_id = (string) ( $row['calendar_id'] ?? '' );
            if ( $calendar_id === '' || ! $this->google_is_configured() ) {
                continue;
            }

            if ( empty( $row['google_original_start_time'] ) && ! empty( $row['start_time'] ) ) {
                $row['google_original_start_time'] = (string) $row['start_time'];

                $wpdb->update(
                    $table_lessons,
                    array(
                        'google_original_start_time' => (string) $row['google_original_start_time'],
                        'updated_at' => current_time( 'mysql' ),
                    ),
                    array( 'id' => (int) $row['id'] ),
                    array( '%s', '%s' ),
                    array( '%d' )
                );
            }

            $this->sync_lesson_row_from_google_truth( $row, $calendar_id, 120 );
        }
    }

    protected function resolve_google_event_for_lesson_row( $lesson_row, $calendar_id, $instance_window_days = 30 ) {
        if ( ! is_array( $lesson_row ) ) {
            return array(
                'status' => 'invalid',
                'event'  => null,
                'reason' => 'lesson_row_invalid',
            );
        }

        $event_id   = (string) ( $lesson_row['google_event_id'] ?? '' );
        $series_id  = (int) ( $lesson_row['series_id'] ?? 0 );
        $booking_id = (int) ( $lesson_row['id'] ?? 0 );
        $start_time = (string) ( $lesson_row['start_time'] ?? '' );
        $end_time   = (string) ( $lesson_row['end_time'] ?? '' );
        $google_original_start_time = (string) ( $lesson_row['google_original_start_time'] ?? '' );

        if ( $google_original_start_time === '' ) {
            $google_original_start_time = $start_time;
        }

        $explicit_cancel = function( $ev ) {
            return is_array( $ev ) && strtolower( (string) ( $ev['status'] ?? 'confirmed' ) ) === 'cancelled';
        };

        $resolve_from_master = function( $master_event_id ) use ( $calendar_id, $start_time, $end_time, $google_original_start_time, $booking_id, $explicit_cancel ) {
            $got = $this->google_get_event( $calendar_id, $master_event_id );
            if ( is_wp_error( $got ) || ! is_array( $got ) ) {
                return array(
                    'status' => 'unresolved',
                    'event'  => null,
                    'reason' => 'master_fetch_failed',
                );
            }

            if ( $explicit_cancel( $got ) ) {
                return array(
                    'status' => 'cancelled',
                    'event'  => $got,
                    'reason' => 'master_cancelled',
                );
            }

            $is_master = isset( $got['recurrence'] ) && is_array( $got['recurrence'] ) && ! empty( $got['recurrence'] );
            if ( ! $is_master ) {
                return array(
                    'status' => 'resolved',
                    'event'  => $got,
                    'reason' => 'direct_event_match',
                );
            }

            $anchor_ts = strtotime( (string) $google_original_start_time );
            $start_ts  = strtotime( (string) $start_time );
            $end_ts    = strtotime( (string) $end_time );

            if ( ! $anchor_ts ) $anchor_ts = $start_ts;
            if ( ! $start_ts )  $start_ts  = $anchor_ts;
            if ( ! $end_ts )    $end_ts    = $start_ts;

            $window_base_start = $anchor_ts ?: $start_ts ?: time();
            $window_base_end   = $end_ts ?: $window_base_start;

            $time_min = gmdate( 'c', min( $window_base_start, time() ) - ( 45 * DAY_IN_SECONDS ) );
            $time_max = gmdate( 'c', max( $window_base_end, time() ) + ( 45 * DAY_IN_SECONDS ) );

            $inst = $this->google_list_event_instances( $calendar_id, $master_event_id, $time_min, $time_max );
            if ( is_wp_error( $inst ) || ! is_array( $inst ) ) {
                return array(
                    'status' => 'unresolved',
                    'event'  => null,
                    'reason' => 'instance_list_failed',
                );
            }

            // Recurring match order:
            // 1) original start anchor
            // 2) current local row start
            // 3) booking_id only if Google still carries it
            $match = $this->google_find_instance_by_original_start( $inst, $google_original_start_time );
            if ( ! is_array( $match ) ) {
                $match = $this->google_find_instance_by_local_start( $inst, $start_time );
            }
            if ( ! is_array( $match ) ) {
                $match = $this->google_find_instance_by_booking_id( $inst, $booking_id );
            }

            if ( is_array( $match ) ) {
                if ( $explicit_cancel( $match ) ) {
                    return array(
                        'status' => 'cancelled',
                        'event'  => $match,
                        'reason' => 'instance_cancelled',
                    );
                }

                return array(
                    'status' => 'resolved',
                    'event'  => $match,
                    'reason' => 'instance_match',
                );
            }

            return array(
                'status' => 'unresolved',
                'event'  => null,
                'reason' => 'instance_unresolved',
            );
        };

        // Attempt to resolve recurring instances first.
        if ( $series_id > 0 ) {
            // If this row belongs to a series, try resolving against the master event id.
            $series_master_event_id = $this->get_series_master_google_event_id( $series_id );
            if ( $series_master_event_id !== '' ) {
                $series_result = $resolve_from_master( $series_master_event_id );
                if ( $series_result['status'] !== 'unresolved' ) {
                    return $series_result;
                }
            }
        }

        // If we have a stored event_id (could be a master or instance id), attempt to resolve directly.
        if ( $event_id !== '' ) {
            $direct = $resolve_from_master( $event_id );
            if ( $direct['status'] !== 'unresolved' ) {
                return $direct;
            }
        }

        /*
         * Fallback resolution: query Google for events carrying our booking_id.  Previously this
         * was only executed for non‑recurring events, but moved recurring instances still carry
         * the lesson booking ID in their extendedProperties.  Without this additional pass the
         * resolver can fail to match a rescheduled instance, leaving local start_time/end_time
         * stale and causing gate issues.  This fallback runs a wide search window around the
         * original and current start times and returns any event matching our booking ID.  The
         * booking_id property is only ever set on events created by this plugin.
         */
        {
            $fallback_anchor_ts = strtotime( (string) $google_original_start_time );
            $fallback_start_ts  = strtotime( (string) $start_time );
            $fallback_end_ts    = strtotime( (string) $end_time );

            if ( ! $fallback_anchor_ts ) $fallback_anchor_ts = $fallback_start_ts;
            if ( ! $fallback_start_ts )  $fallback_start_ts  = $fallback_anchor_ts;
            if ( ! $fallback_end_ts )    $fallback_end_ts    = $fallback_start_ts;

            $fallback_window_base_start = $fallback_anchor_ts ?: $fallback_start_ts ?: time();
            $fallback_window_base_end   = $fallback_end_ts ?: $fallback_window_base_start;

            $fallback_time_min = gmdate( 'c', min( $fallback_window_base_start, time() ) - ( 45 * DAY_IN_SECONDS ) );
            $fallback_time_max = gmdate( 'c', max( $fallback_window_base_end, time() ) + ( 45 * DAY_IN_SECONDS ) );

            $found = $this->google_find_event_by_booking_id( $calendar_id, $booking_id, $fallback_time_min, $fallback_time_max );
            if ( ! is_wp_error( $found ) && is_array( $found ) ) {
                if ( $explicit_cancel( $found ) ) {
                    return array(
                        'status' => 'cancelled',
                        'event'  => $found,
                        'reason' => 'booking_id_cancelled',
                    );
                }

                return array(
                    'status' => 'resolved',
                    'event'  => $found,
                    'reason' => 'booking_id_match',
                );
            }
        }

        // Still unresolved.  Include the last attempted reason for easier debugging.
        return array(
            'status' => 'unresolved',
            'event'  => null,
            'reason' => 'no_google_match',
        );
    }




    protected function sync_lesson_row_from_google_truth( $lesson_row, $calendar_id, $instance_window_days = 120 ) {
        global $wpdb;

        if ( ! is_array( $lesson_row ) ) {
            return array(
                'status' => 'invalid',
                'event'  => null,
                'reason' => 'lesson_row_invalid',
            );
        }

        $lesson_id = (int) ( $lesson_row['id'] ?? 0 );
        if ( $lesson_id <= 0 ) {
            return array(
                'status' => 'invalid',
                'event'  => null,
                'reason' => 'lesson_id_invalid',
            );
        }

        if ( $calendar_id === '' || ! $this->google_is_configured() ) {
            return array(
                'status' => 'unresolved',
                'event'  => null,
                'reason' => 'missing_calendar_or_google_not_configured',
            );
        }

        $resolved = $this->resolve_google_event_for_lesson_row( $lesson_row, $calendar_id, $instance_window_days );
        $resolved_status = (string) ( $resolved['status'] ?? 'unresolved' );
        $resolved_event  = $resolved['event'] ?? null;
        $resolved_reason = (string) ( $resolved['reason'] ?? '' );

        // Debug: log resolution outcomes to aid troubleshooting.  Include booking ID and reason.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $bid = (int) ( $lesson_row['id'] ?? 0 );
            error_log( '[MRM] sync_lesson_row_from_google_truth lesson_id=' . $bid . ' status=' . $resolved_status . ' reason=' . $resolved_reason );
        }

        if ( $resolved_status === 'cancelled' && is_array( $resolved_event ) ) {
            return array(
                'status' => 'cancelled',
                'event'  => $resolved_event,
                'reason' => $resolved_reason,
            );
        }

        if ( $resolved_status !== 'resolved' || ! is_array( $resolved_event ) ) {
            return array(
                'status' => 'unresolved',
                'event'  => null,
                'reason' => ( $resolved_reason !== '' ? $resolved_reason : 'unresolved' ),
            );
        }

        list( $g_start_ts, $g_end_ts ) = $this->google_event_to_utc_ts( $resolved_event );
        if ( ! $g_start_ts || ! $g_end_ts ) {
            return array(
                'status' => 'unresolved',
                'event'  => null,
                'reason' => 'google_event_missing_times',
            );
        }

        $new_start = gmdate( 'Y-m-d H:i:s', $g_start_ts );
        $new_end   = gmdate( 'Y-m-d H:i:s', $g_end_ts );
        $resolved_event_id = ! empty( $resolved_event['id'] ) ? (string) $resolved_event['id'] : '';

        $original_anchor = ! empty( $lesson_row['google_original_start_time'] )
            ? (string) $lesson_row['google_original_start_time']
            : (string) ( $lesson_row['start_time'] ?? $new_start );

        $series_id = (int) ( $lesson_row['series_id'] ?? 0 );
        $series_master_event_id = $this->get_series_master_google_event_id( $series_id );

        // For recurring rows, keep the row pointed at the recurring master event.
        $stored_event_id = ( $series_master_event_id !== '' ) ? $series_master_event_id : $resolved_event_id;

        $table_lessons = $wpdb->prefix . 'mrm_lessons';

        $wpdb->update(
            $table_lessons,
            array(
                'start_time'                 => $new_start,
                'end_time'                   => $new_end,
                'google_original_start_time' => $original_anchor,
                'google_event_id'            => ( $stored_event_id !== '' ? $stored_event_id : null ),
                'updated_at'                 => current_time( 'mysql' ),
            ),
            array( 'id' => $lesson_id ),
            array( '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        $lesson_row['start_time'] = $new_start;
        $lesson_row['end_time'] = $new_end;
        $lesson_row['google_event_id'] = $stored_event_id;
        $lesson_row['google_original_start_time'] = $original_anchor;

        return array(
            'status'     => 'resolved',
            'event'      => $resolved_event,
            'reason'     => ( $resolved_reason !== '' ? $resolved_reason : 'resolved' ),
            'lesson_row' => $lesson_row,
            'start_utc'  => $new_start,
            'end_utc'    => $new_end,
        );
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

    protected function google_event_end_utc( $event ) {
        list( , $end_ts ) = $this->google_event_to_utc_ts( $event );
        if ( ! $end_ts ) return '';
        return gmdate( 'Y-m-d H:i:s', $end_ts );
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
        add_action( 'mrm_scheduler_reconcile_completed_lessons', array( $this, 'cron_reconcile_completed_lessons' ) );
        add_action( 'mrm_scheduler_reconcile_cancelled_lessons', array( $this, 'cron_reconcile_cancelled_lessons' ) );
        if ( ! wp_next_scheduled( 'mrm_scheduler_sync_upcoming_events' ) ) {
            wp_schedule_event( time() + 60, 'mrm_10min', 'mrm_scheduler_sync_upcoming_events' );
        }
        if ( ! wp_next_scheduled( 'mrm_scheduler_reconcile_completed_lessons' ) ) {
            wp_schedule_event( time() + 120, 'mrm_10min', 'mrm_scheduler_reconcile_completed_lessons' );
        }
        if ( ! wp_next_scheduled( 'mrm_scheduler_reconcile_cancelled_lessons' ) ) {
            wp_schedule_event( time() + 180, 'mrm_10min', 'mrm_scheduler_reconcile_cancelled_lessons' );
        }
        // Gate page (virtual) for joining online lessons
        add_action( 'init', array( $this, 'register_join_gate_rewrite' ) );
        add_filter( 'query_vars', array( $this, 'register_join_gate_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'maybe_render_join_gate_page' ) );
        add_action( 'send_headers', array( $this, 'maybe_send_gate_nocache_headers' ), 0 );
        add_filter( 'redirect_canonical', array( $this, 'maybe_disable_canonical_for_gate' ), 10, 2 );
        $this->options = get_option( $this->option_key, array() );
        // Ensure our cron schedules are registered and any outdated jobs are rescheduled.
        $this->maybe_reschedule_cron_jobs();
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

        // Reschedule cron jobs on activation to remove outdated intervals.
        $inst->maybe_reschedule_cron_jobs();
    }

    /**
     * Unschedule any existing MRM cron jobs and reschedule them with the current interval.
     *
     * WordPress stores scheduled events in the options table and does not automatically update
     * their interval when a plugin changes its schedule definition.  Without clearing and
     * re‑scheduling, stale jobs may continue to run on old cadences.  This method should be
     * called from the constructor and from the activation handler.
     */
    protected function maybe_reschedule_cron_jobs() {
        // Clear existing schedules for our hooks.
        $hooks = array(
            'mrm_scheduler_sync_upcoming_events',
            'mrm_scheduler_reconcile_completed_lessons',
            'mrm_scheduler_reconcile_cancelled_lessons',
        );

        foreach ( $hooks as $hook ) {
            // Remove all scheduled instances of the hook.
            if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
                wp_clear_scheduled_hook( $hook );
            } else {
                // Fallback for older WP versions: unschedule individually.
                while ( $timestamp = wp_next_scheduled( $hook ) ) {
                    wp_unschedule_event( $timestamp, $hook );
                }
            }
        }

        // Register our custom schedule if not already present.
        add_filter( 'cron_schedules', array( $this, 'register_custom_cron_schedules' ) );

        // Schedule new events if none exist.  Stagger start times to avoid simultaneous runs.
        if ( ! wp_next_scheduled( 'mrm_scheduler_sync_upcoming_events' ) ) {
            wp_schedule_event( time() + 60, 'mrm_10min', 'mrm_scheduler_sync_upcoming_events' );
        }
        if ( ! wp_next_scheduled( 'mrm_scheduler_reconcile_completed_lessons' ) ) {
            wp_schedule_event( time() + 120, 'mrm_10min', 'mrm_scheduler_reconcile_completed_lessons' );
        }
        if ( ! wp_next_scheduled( 'mrm_scheduler_reconcile_cancelled_lessons' ) ) {
            wp_schedule_event( time() + 180, 'mrm_10min', 'mrm_scheduler_reconcile_cancelled_lessons' );
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
    timezone VARCHAR(50) NOT NULL,
    stripe_connected_account_id VARCHAR(255) DEFAULT NULL,
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
    google_original_start_time DATETIME NULL,
    delivered_at DATETIME NULL,
    charge_due_at DATETIME NULL,
    charge_status VARCHAR(30) NOT NULL DEFAULT 'none',
    charge_attempts INT NOT NULL DEFAULT 0,
    charge_last_attempt_at DATETIME NULL,
    charge_last_error LONGTEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'scheduled',
    google_event_id VARCHAR(255) NULL,
    google_meet_url TEXT NULL,
    order_id BIGINT UNSIGNED NULL,
    payment_mode VARCHAR(20) NOT NULL DEFAULT 'none',
    payout_unlocked_at DATETIME NULL,
    autopay_profile_id BIGINT UNSIGNED NULL,
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
    KEY order_id_idx (order_id),
    KEY payment_mode_idx (payment_mode),
    KEY payout_unlocked_at_idx (payout_unlocked_at),
    KEY charge_status_idx (charge_status),
    KEY charge_due_at_idx (charge_due_at),
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

        // Backfill recurring anchor for older lesson rows so moved recurring events
        // can still be resolved against Google after reschedules.
        $wpdb->query(
            "UPDATE {$table_lessons}
             SET google_original_start_time = start_time
             WHERE google_original_start_time IS NULL
                OR google_original_start_time = '0000-00-00 00:00:00'"
        );

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
            'timezone',
            'stripe_connected_account_id',
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
            'google_original_start_time',
            'delivered_at',
            'charge_due_at',
            'charge_status',
            'charge_attempts',
            'charge_last_attempt_at',
            'charge_last_error',
            'google_event_id',
            'google_meet_url',
            'order_id',
            'payment_mode',
            'payout_unlocked_at',
            'autopay_profile_id',
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
                // Canonical (scheduler.html uses these)
                'start_date' => array( 'type' => 'string', 'required' => false ),
                'end_date'   => array( 'type' => 'string', 'required' => false ),

                // Legacy (keep for 30 days)
                'start' => array( 'type' => 'string', 'required' => false ),
                'end'   => array( 'type' => 'string', 'required' => false ),

                // Optional slot size (scheduler.html sends slot_minutes=15)
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


    protected function expand_booking_slots_from_repeat_rule( $slots, $repeat_frequency, $repeat_duration ) {
        $slots = is_array( $slots ) ? array_values( $slots ) : array();
        $repeat_frequency = strtolower( trim( (string) $repeat_frequency ) );
        $repeat_duration  = strtolower( trim( (string) $repeat_duration ) );

        if ( empty( $slots ) ) return array();
        if ( $repeat_frequency !== 'weekly' && $repeat_frequency !== 'biweekly' ) return $slots;

        $interval_days = ( $repeat_frequency === 'biweekly' ) ? 14 : 7;

        $count_map = array(
            '1_month'   => 4,
            '2_months'  => 8,
            '3_months'  => 12,
            '6_months'  => 24,
            '12_months' => 48,
        );

        if ( $repeat_frequency === 'biweekly' ) {
            $count_map = array(
                '1_month'   => 2,
                '2_months'  => 4,
                '3_months'  => 6,
                '6_months'  => 12,
                '12_months' => 24,
            );
        }

        $repeat_count = isset( $count_map[ $repeat_duration ] ) ? (int) $count_map[ $repeat_duration ] : 0;
        if ( $repeat_count <= 1 ) return $slots;

        $expanded = array();

        foreach ( $slots as $slot ) {
            $start_raw = (string) ( $slot['start'] ?? '' );
            $end_raw   = (string) ( $slot['end'] ?? '' );

            $start_ts = strtotime( $start_raw );
            $end_ts   = strtotime( $end_raw );

            if ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) {
                continue;
            }

            for ( $i = 0; $i < $repeat_count; $i++ ) {
                $expanded[] = array(
                    'start' => gmdate( 'c', $start_ts + ( $i * $interval_days * DAY_IN_SECONDS ) ),
                    'end'   => gmdate( 'c', $end_ts   + ( $i * $interval_days * DAY_IN_SECONDS ) ),
                    'instructor_id' => (int) ( $slot['instructor_id'] ?? 0 ),
                );
            }
        }

        usort( $expanded, function( $a, $b ) {
            return strcmp( (string) $a['start'], (string) $b['start'] );
        } );

        $deduped = array();
        $seen = array();
        foreach ( $expanded as $slot ) {
            $k = (string) $slot['instructor_id'] . '|' . (string) $slot['start'] . '|' . (string) $slot['end'];
            if ( isset( $seen[ $k ] ) ) continue;
            $seen[ $k ] = true;
            $deduped[] = $slot;
        }

        return $deduped;
    }

    public function rest_book_lesson( WP_REST_Request $request ) {
        global $wpdb;

        $data = (array) $request->get_json_params();

        $instructor_id = intval( $data['instructor_id'] ?? 0 );
        $slots         = isset( $data['slots'] ) && is_array( $data['slots'] ) ? $data['slots'] : array();
        $slots_are_expanded = ! empty( $data['slots_are_expanded'] );

        if ( ! $slots_are_expanded ) {
            $slots = $this->expand_booking_slots_from_repeat_rule(
                $slots,
                (string) ( $data['repeat_frequency'] ?? 'none' ),
                (string) ( $data['repeat_duration'] ?? '' )
            );
        }

        $student_name  = sanitize_text_field( (string) ( $data['student_name'] ?? '' ) );
        $student_email = sanitize_email( (string) ( $data['student_email'] ?? '' ) );
        $instrument    = sanitize_text_field( (string) ( $data['instrument'] ?? '' ) );
        $is_online     = ! empty( $data['online'] ) ? 1 : 0;
        $lesson_length = intval( $data['lesson_length'] ?? ( $data['slot_minutes'] ?? 60 ) );

        $parent_first  = sanitize_text_field( (string) ( $data['first_name'] ?? '' ) );
        $parent_last   = sanitize_text_field( (string) ( $data['last_name'] ?? '' ) );

        $phone         = sanitize_text_field( (string) ( $data['phone'] ?? '' ) );

        $address       = sanitize_text_field( (string) ( $data['address'] ?? '' ) );
        $address_state = sanitize_text_field( (string) ( $data['address_state'] ?? '' ) );
        $address_postal= sanitize_text_field( (string) ( $data['address_postal'] ?? '' ) );

        $lesson_type       = sanitize_text_field( (string) ( $data['lesson_type'] ?? '' ) );          // online | inperson | consultation (per your UI)
        $repeat_frequency  = sanitize_text_field( (string) ( $data['repeat_frequency'] ?? 'none' ) ); // weekly | biweekly | none
        $repeat_duration   = sanitize_text_field( (string) ( $data['repeat_duration'] ?? '' ) );      // 1_month | 3_months | indefinitely (per UI)
        $appointment_type  = sanitize_text_field( (string) ( $data['appointment_type'] ?? 'lesson' ) ); // lesson | consultation
        $order_id = isset( $data['order_id'] ) ? intval( $data['order_id'] ) : 0;
        $payment_mode = sanitize_text_field( (string ) ( $data['payment_mode'] ?? 'none' ) ); // prepay|autopay|none
        $autopay_profile_id = isset( $data['autopay_profile_id'] ) ? intval( $data['autopay_profile_id'] ) : 0;

        if ( $instructor_id <= 0 ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Missing instructor_id.' ), 400 );
        }
        if ( ! $student_email || ! is_email( $student_email ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Valid student_email required.' ), 400 );
        }
        if ( empty( $slots ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'No slots selected.' ), 400 );
        }

        $lessons_table = $wpdb->prefix . 'mrm_lessons';
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $lessons_table ) );
        if ( $exists !== $lessons_table ) {
            return new WP_REST_Response( array( 'ok' => false, 'message' => 'Lessons table missing.' ), 500 );
        }

        $now = current_time( 'mysql' );
        $created_ids = array();
        $google_messages = array();

        // Fetch instructor calendar + timezone (required for Google event insert)
        $table_instructors = $wpdb->prefix . 'mrm_instructors';
        $instr = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, name, email, calendar_id, timezone
                 FROM {$table_instructors}
                 WHERE id = %d
                 LIMIT 1",
                $instructor_id
            ),
            ARRAY_A
        );

        $calendar_id = ( is_array( $instr ) && ! empty( $instr['calendar_id'] ) ) ? (string) $instr['calendar_id'] : '';
        $instr_tz    = ( is_array( $instr ) && ! empty( $instr['timezone'] ) ) ? (string) $instr['timezone'] : 'UTC';

        $is_recurring_booking = (
            in_array( strtolower( (string) $repeat_frequency ), array( 'weekly', 'biweekly' ), true ) &&
            count( $slots ) >= 1
        );

        $series_id = null;
        if ( $is_recurring_booking ) {
            $wpdb->insert(
                $lessons_table,
                array(
                    'instructor_id' => $instructor_id,
                    'series_id'     => null,
                    'student_name'  => $student_name !== '' ? $student_name : $student_email,
                    'student_email' => $student_email,
                    'instrument'    => $instrument !== '' ? $instrument : 'unknown',
                    'is_online'     => $is_online,
                    'lesson_length' => $lesson_length > 0 ? $lesson_length : 60,
                    'start_time'    => gmdate( 'Y-m-d H:i:s', strtotime( (string) ( $slots[0]['start'] ?? '' ) ) ),
                    'end_time'      => gmdate( 'Y-m-d H:i:s', strtotime( (string) ( $slots[0]['end'] ?? '' ) ) ),
                    'google_original_start_time' => gmdate( 'Y-m-d H:i:s', strtotime( (string) ( $slots[0]['start'] ?? '' ) ) ),
                    'status'        => 'series',
                    'google_event_id' => null,
                    'google_meet_url' => null,
                    'order_id'        => null,
                    'payment_mode'    => 'none',
                    'payout_unlocked_at' => null,
                    'autopay_profile_id' => null,
                    'agreement_id'    => null,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                    'reminder_token'  => null,
                    'reminder_token_hash' => null,
                    'reminder_scheduled_at' => null,
                    'reminder_sent_at' => null,
                ),
                array(
                    '%d','%d','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%s',
                    '%d','%s','%s','%d','%d','%s','%s','%s','%s','%s','%s'
                )
            );

            $series_id = (int) $wpdb->insert_id;
            if ( $series_id <= 0 ) {
                $series_id = null;
            }
        }

        $series_shared_token = '';
        $series_shared_token_hash = '';
        $series_master_google_event_id = '';
        $series_master_google_meet_url = '';

        if ( $is_recurring_booking ) {
            $series_shared_token = bin2hex( random_bytes( 16 ) );
            $series_shared_token_hash = hash( 'sha256', $series_shared_token );
        }

        foreach ( $slots as $slot_index => $slot ) {
            $start_raw = (string) ( $slot['start'] ?? '' );
            $end_raw   = (string) ( $slot['end'] ?? '' );

            $start_ts = strtotime( $start_raw );
            $end_ts   = strtotime( $end_raw );

            if ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) {
                continue;
            }

            $start_mysql = gmdate( 'Y-m-d H:i:s', $start_ts );
            $end_mysql   = gmdate( 'Y-m-d H:i:s', $end_ts );

            if ( $is_recurring_booking ) {
                $token = $series_shared_token;
                $token_hash = $series_shared_token_hash;
            } else {
                $token = bin2hex( random_bytes( 16 ) );
                $token_hash = hash( 'sha256', $token );
            }

            $slot_order_id = $order_id;
            if ( $payment_mode === 'autopay' && $slot_index > 0 ) {
                $slot_order_id = 0;
            }

            $ok = $wpdb->insert( $lessons_table, array(
                'instructor_id' => $instructor_id,
                'series_id'     => ( $series_id ? $series_id : null ),
                'student_name'  => $student_name !== '' ? $student_name : $student_email,
                'student_email' => $student_email,
                'instrument'    => $instrument !== '' ? $instrument : 'unknown',
                'is_online'     => $is_online,
                'lesson_length' => $lesson_length > 0 ? $lesson_length : 60,
                'start_time'    => $start_mysql,
                'end_time'      => $end_mysql,
                'google_original_start_time' => $start_mysql,
                'status'        => 'scheduled',
                'google_event_id' => ( $series_master_google_event_id !== '' ? $series_master_google_event_id : null ),
                'google_meet_url' => null,
                'order_id'        => ( $slot_order_id > 0 ? $slot_order_id : null ),
                'payment_mode'    => ( $payment_mode !== '' ? $payment_mode : 'none' ),
                'payout_unlocked_at' => null,
                'autopay_profile_id' => ( $autopay_profile_id > 0 ? $autopay_profile_id : null ),
                'agreement_id'    => null,
                'created_at'      => $now,
                'updated_at'      => $now,
                'reminder_token'  => $token,
                'reminder_token_hash' => $token_hash,
                'reminder_scheduled_at' => null,
                'reminder_sent_at' => null,
            ), array(
                '%d', // instructor_id
                '%d', // series_id
                '%s', // student_name
                '%s', // student_email
                '%s', // instrument
                '%d', // is_online
                '%d', // lesson_length
                '%s', // start_time
                '%s', // end_time
                '%s', // google_original_start_time
                '%s', // status
                '%s', // google_event_id
                '%s', // google_meet_url
                '%d', // order_id
                '%s', // payment_mode
                '%s', // payout_unlocked_at
                '%d', // autopay_profile_id
                '%d', // agreement_id
                '%s', // created_at
                '%s', // updated_at
                '%s', // reminder_token
                '%s', // reminder_token_hash
                '%s', // reminder_scheduled_at
                '%s', // reminder_sent_at
            ) );

            if ( $ok ) {
                $booking_id = (int) $wpdb->insert_id;
                $created_ids[] = $booking_id;

                // Call existing Google Calendar insert function (already defined in this plugin)
                // Only do this if Google is configured and the instructor has a calendar_id.
                if ( $calendar_id === '' ) {
                    $google_messages[] = 'Instructor calendar_id is blank, so no Google Calendar event could be created.';
                } elseif ( ! $this->google_is_configured() ) {
                    $google_messages[] = 'Google Calendar is not configured in the plugin settings, so no calendar event could be created.';
                }

                // Non-recurring lessons still create one standalone Google event.
                // Recurring lessons create exactly one Google recurring master event
                // on the first slot only.
                $should_create_google_event = ( ! $is_recurring_booking || $slot_index === 0 );

                if ( $should_create_google_event && $calendar_id !== '' && $this->google_is_configured() ) {

                    // ------------------------------------------------------------
                    // Event title/location/description (minimal but complete)
                    // Requirements:
                    // - Title format: "<length>m <instrument> <lesson type> - <student>"
                    // - Online: NO location field at all (handled by google_insert_event() omission below)
                    // - Description includes ALL confirm popup fillable fields
                    // - In-person: address goes in LOCATION only (street + state + postal)
                    // ------------------------------------------------------------
                    $display_student = ( $student_name !== '' ) ? $student_name : $student_email;

                    // =========================
                    // Title format (UPDATED):
                    // <Student or Parent Name> <Lesson Type> <Instrument> <30min/60min> Lesson
                    // - Lessons: use STUDENT name
                    // - Consultations: use PARENT name
                    // =========================
                    $minutes = (int) ( $lesson_length > 0 ? $lesson_length : 60 );
                    $minutes_label = ( $minutes === 30 ) ? '30min' : ( ( $minutes === 60 ) ? '60min' : ( $minutes . 'min' ) );

                    // Parent full name
                    $parent_full = trim( trim( (string) $parent_first ) . ' ' . trim( (string) $parent_last ) );

                    // Student display
                    $student_full = trim( (string) $student_name );
                    $student_display = ( $student_full !== '' ) ? $student_full : ( (string) $student_email );

                    // Determine consultation vs lesson
                    $lt_raw = strtolower( trim( (string) $lesson_type ) );
                    $is_consultation = ( strtolower( (string) $appointment_type ) === 'consultation' ) || ( $lt_raw === 'consultation' );

                    // Name in title depends on consultation vs lesson:
                    if ( $is_consultation ) {
                        $name_for_title = ( $parent_full !== '' ) ? $parent_full : $student_display;
                    } else {
                        $name_for_title = $student_display;
                    }

                    $name_for_calendar = $name_for_title;

                    // Lesson type label (keep readable)
                    if ( $lt_raw === '' ) {
                        $lesson_type_label = $is_online ? 'Online' : 'In person';
                    } elseif ( $lt_raw === 'online' ) {
                        $lesson_type_label = 'Online';
                    } elseif ( $lt_raw === 'in_person' || $lt_raw === 'inperson' || $lt_raw === 'in person' ) {
                        $lesson_type_label = 'In person';
                    } elseif ( $lt_raw === 'consultation' ) {
                        $lesson_type_label = 'Consultation';
                    } else {
                        $lesson_type_label = ucfirst( $lt_raw );
                    }

                    $inst = trim( (string) $instrument );

                    // Consultations must title as: "<Parent Name> Online Consultation"
                    if ( $is_consultation ) {
                        $parent_for_title = ( $parent_full !== '' ) ? $parent_full : $student_display;
                        $title = $parent_for_title . ' Online 30 min Consultation';
                    } else {
                        // Lessons: "<Student Name> <Lesson Type> <Instrument> <30min/60min> Lesson"
                        $title_parts = array();
                        $title_parts[] = $name_for_title;         // student
                        $title_parts[] = $lesson_type_label;      // Online / In person
                        if ( $inst !== '' ) $title_parts[] = $inst;
                        $title_parts[] = $minutes_label;          // 30min/60min
                        $title_parts[] = 'Lesson';
                        $title = implode( ' ', $title_parts );
                    }

                    // Compute student last name (best-effort, from student_name)
                    $student_last_name = '';
                    $sn = trim( (string) $student_name );
                    if ( $sn !== '' && preg_match( '/\s+/', $sn ) ) {
                        $parts = preg_split( '/\s+/', $sn );
                        if ( is_array( $parts ) && ! empty( $parts ) ) {
                            $student_last_name = (string) end( $parts );
                        }
                    }

                    // Location: in-person ONLY (street + state + postal). Online => blank (and omitted later).
                    $location = '';
                    if ( ! $is_online ) {
                        $state_zip = trim( trim( (string) $address_state ) . ' ' . trim( (string) $address_postal ) );
                        if ( $address !== '' && $state_zip !== '' ) {
                            $location = trim( $address . ', ' . $state_zip );
                        } elseif ( $address !== '' ) {
                            $location = trim( $address );
                        } elseif ( $state_zip !== '' ) {
                            $location = $state_zip;
                        }
                    }

                    // =========================
                    // Description format (REQUIRED):
                    // 1) Gate URL FIRST (online lessons + consultations)
                    // 2) "Details:" line
                    // 3) Every fillable box label WORD-FOR-WORD from scheduler.html + selected value
                    // =========================
                    $description_lines = array();

                    // Gate URL: same token-gate system you already use (±10 minutes enforced by gate page)
                    $gate_url = add_query_arg( array( 'token' => $token ), home_url( '/join-online/' ) );

                    // Show gate for: (a) online lessons OR (b) consultations (even if flagged oddly)
                    $is_consultation = ( strtolower( (string) $appointment_type ) === 'consultation' ) || ( strtolower( (string) $lt_raw ) === 'consultation' );
                    if ( $is_online || $is_consultation ) {
                        // First line must be the URL
                        $description_lines[] = 'Join video call: ' . $gate_url;
                        $description_lines[] = ''; // blank line
                    }

                    $description_lines[] = 'Details:';

                    // WORD-FOR-WORD labels from scheduler.html modal:
                    $description_lines[] = 'First Name: ' . (string) $parent_first;
                    $description_lines[] = 'Last Name: ' . (string) $parent_last;
                    $description_lines[] = 'Student Name: ' . (string) $student_name;
                    $description_lines[] = 'Instrument: ' . (string) $instrument;
                    $description_lines[] = 'Email: ' . (string) $student_email;
                    $description_lines[] = 'Phone: ' . (string) $phone;

                    // These labels match the exact casing in scheduler.html:
                    $description_lines[] = 'Lesson length: ' . $minutes . ' minutes';
                    $description_lines[] = 'Lesson type: ' . $lesson_type_label;

                    // Frequency (label is "Frequency")
                    if ( $repeat_frequency !== '' ) {
                        $description_lines[] = 'Frequency: ' . (string) $repeat_frequency;
                    }

                    // Reserve duration label must match scheduler.html exactly:
                    if ( $repeat_duration !== '' ) {
                        $description_lines[] = 'How long would you like to reserve this lesson time?: ' . (string) $repeat_duration;
                    }

                    // Address fields (these are fillable boxes in the modal)
                    // Keep them present in details for plugin access; location field can still remain in-person only elsewhere.
                    $description_lines[] = 'Address: ' . (string) $address;
                    $description_lines[] = 'State: ' . (string) $address_state;
                    $description_lines[] = 'Postal Code: ' . (string) $address_postal;

                    $description = implode( "\n", $description_lines );

                    // Your sync logic expects this to exist:
                    // extendedProperties.private.booking_id
                    $extended_private = array(
                        'student_email'       => (string) $student_email,
                        'student_name'        => (string) $name_for_calendar,
                        'student_last_name'   => (string) $student_last_name,
                        'instrument'          => (string) $instrument,
                        'parent_first_name'   => (string) $parent_first,
                        'parent_last_name'    => (string) $parent_last,
                        'phone'               => (string) $phone,
                        'address'             => (string) $address,
                        'address_state'       => (string) $address_state,
                        'address_postal'      => (string) $address_postal,
                        'lesson_type'         => (string) $lesson_type,
                        'repeat_frequency'    => (string) $repeat_frequency,
                        'repeat_duration'     => (string) $repeat_duration,
                        'appointment_type'    => (string) $appointment_type,
                        'reminder_token'      => (string) $token,
                    );

                    // Always attach the concrete lesson-row booking_id so each lesson can be
                    // resolved directly from Google.
                    $extended_private['booking_id'] = (string) $booking_id;

                    if ( $is_recurring_booking ) {
                        $extended_private['series_id'] = (string) ( $series_id ?: '' );
                        $extended_private['series_anchor_start_utc'] = (string) $start_mysql;
                    }

                    // Use your existing RFC3339 UTC helper (already in this file)
                    $start_rfc3339 = $this->to_rfc3339_utc( $start_raw );
                    $end_rfc3339   = $this->to_rfc3339_utc( $end_raw );

                    // If conversion fails, do not break booking — just log
                    if ( ! is_wp_error( $start_rfc3339 ) && ! is_wp_error( $end_rfc3339 ) ) {

                        $recurrence_rules = array();
                        $create_meet = false;

                        if ( $is_recurring_booking ) {
                            $recurrence_rules = $this->build_google_recurrence_rules( $repeat_frequency, $repeat_duration, $start_ts );
                        }

                        $ins = $this->google_insert_event(
                            $calendar_id,
                            $title,
                            $description,
                            $location,
                            $start_rfc3339,
                            $end_rfc3339,
                            'UTC',
                            $extended_private,
                            $recurrence_rules,
                            $create_meet,
                            (array) ( is_email( $student_email ) ? array( $student_email ) : array() )
                        );

                        if ( ! is_wp_error( $ins ) && is_array( $ins ) && ! empty( $ins['id'] ) ) {
                            $created_event_id = (string) $ins['id'];
                            $created_meet_url = ! empty( $ins['meet_url'] ) ? (string) $ins['meet_url'] : '';

                            if ( $is_recurring_booking ) {
                                $series_master_google_event_id = $created_event_id;
                                $series_master_google_meet_url = $created_meet_url;

                                $wpdb->update(
                                    $lessons_table,
                                    array(
                                        'google_event_id' => $series_master_google_event_id,
                                        'google_meet_url' => null,
                                        'updated_at'      => $now,
                                    ),
                                    array( 'id' => $booking_id ),
                                    array( '%s', '%s', '%s' ),
                                    array( '%d' )
                                );

                                if ( $series_id ) {
                                    $wpdb->update(
                                        $lessons_table,
                                        array(
                                            'google_event_id' => $series_master_google_event_id,
                                            'google_meet_url' => null,
                                            'updated_at'      => $now,
                                        ),
                                        array( 'id' => $series_id ),
                                        array( '%s', '%s', '%s' ),
                                        array( '%d' )
                                    );
                                }

                                $google_messages[] = 'Recurring Google Calendar series created successfully.';
                            } else {
                                $wpdb->update(
                                    $lessons_table,
                                    array(
                                        'google_event_id' => $created_event_id,
                                        'google_meet_url' => null,
                                        'updated_at'      => $now,
                                    ),
                                    array( 'id' => $booking_id ),
                                    array( '%s', '%s', '%s' ),
                                    array( '%d' )
                                );

                                $google_messages[] = 'Calendar event created successfully.';
                            }
                        } else {
                            $msg = is_wp_error( $ins ) ? $ins->get_error_message() : 'Unknown insert response.';
                            $google_messages[] = 'Calendar event was not created: ' . $msg;
                            error_log( 'MRM google_insert_event failed for booking_id ' . $booking_id . ': ' . $msg );
                        }

                    } else {
                        $msg = is_wp_error( $start_rfc3339 ) ? $start_rfc3339->get_error_message() : $end_rfc3339->get_error_message();
                        $google_messages[] = 'Calendar event was not created because time conversion failed: ' . $msg;
                        error_log( 'MRM time->RFC3339 failed for booking_id ' . $booking_id . ': ' . $msg );
                    }
                }
            } else {
                error_log(
                    'MRM booking insert failed. DB error: ' . $wpdb->last_error .
                    ' | start=' . $start_mysql .
                    ' | end=' . $end_mysql .
                    ' | instructor_id=' . (int) $instructor_id .
                    ' | student_email=' . $student_email
                );
            }
        }

        $this->bump_cache_bust_token();

        if ( empty( $created_ids ) ) {
            return new WP_REST_Response( array(
                'ok' => false,
                'message' => 'Booking could not be saved. The selected time may still appear open, but the lesson record was not inserted. Please check the plugin error log.'
            ), 400 );
        }

        // Removed: lesson purchases should NOT grant "all sheet music" access.
        // Only the $5 sheet-music add-on payment grants ledger access.

        $response_message = 'Booking confirmed!';
        if ( ! empty( $google_messages ) ) {
            $response_message .= ' ' . implode( ' ', array_unique( $google_messages ) );
        }

        return new WP_REST_Response( array(
            'ok' => true,
            'success' => true,
            'message' => $response_message,
            'lesson_ids' => $created_ids,
            'google_messages' => array_values( array_unique( $google_messages ) ),
        ), 200 );
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

    protected function resolve_gate_lesson_by_shared_token( $token_hash ) {
        global $wpdb;

        $table_lessons = $wpdb->prefix . 'mrm_lessons';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,series_id,instructor_id,student_name,student_email,is_online,lesson_length,start_time,end_time,google_original_start_time,status,google_event_id,google_meet_url,reminder_token_hash
                 FROM {$table_lessons}
                 WHERE reminder_token_hash = %s
                   AND status IN ('scheduled','payment_due','delivered')
                 ORDER BY start_time ASC
                 LIMIT 100",
                $token_hash
            ),
            ARRAY_A
        );

        if ( ! $rows ) {
            return array(
                'lesson' => null,
                'appointment_type' => '',
            );
        }

        $now = time();

        $nearest_upcoming = null;
        $nearest_upcoming_diff = null;

        $nearest_recent = null;
        $nearest_recent_diff = null;

        foreach ( $rows as $candidate ) {
            if ( empty( $candidate['is_online'] ) ) {
                continue;
            }

            $start_ts = strtotime( (string) ( $candidate['start_time'] ?? '' ) );
            $end_ts   = strtotime( (string) ( $candidate['end_time'] ?? '' ) );

            if ( ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) && ! empty( $candidate['google_original_start_time'] ) ) {
                $fallback_start_ts = strtotime( (string) $candidate['google_original_start_time'] );
                if ( $fallback_start_ts ) {
                    $start_ts = $fallback_start_ts;
                    $end_ts = $fallback_start_ts + ( max( 1, (int) ( $candidate['lesson_length'] ?? 60 ) ) * MINUTE_IN_SECONDS );
                }
            }

            if ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) {
                continue;
            }

            $open_ts  = $start_ts - ( 10 * MINUTE_IN_SECONDS );
            $close_ts = $end_ts + ( 10 * MINUTE_IN_SECONDS );

            if ( $now >= $open_ts && $now <= $close_ts ) {
                return array(
                    'lesson' => $candidate,
                    'appointment_type' => '',
                );
            }

            if ( $now < $open_ts ) {
                $diff = $open_ts - $now;
                if ( $nearest_upcoming === null || $nearest_upcoming_diff === null || $diff < $nearest_upcoming_diff ) {
                    $nearest_upcoming = $candidate;
                    $nearest_upcoming_diff = $diff;
                }
            } else {
                $diff = $now - $close_ts;
                if ( $nearest_recent === null || $nearest_recent_diff === null || $diff < $nearest_recent_diff ) {
                    $nearest_recent = $candidate;
                    $nearest_recent_diff = $diff;
                }
            }
        }

        if ( $nearest_upcoming !== null ) {
            return array(
                'lesson' => $nearest_upcoming,
                'appointment_type' => '',
            );
        }

        if ( $nearest_recent !== null ) {
            return array(
                'lesson' => $nearest_recent,
                'appointment_type' => '',
            );
        }

        return array(
            'lesson' => null,
            'appointment_type' => '',
        );
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

        // First sync every lesson row that shares this recurring room token.
        // This makes moved recurring occurrences update before room-availability is evaluated.
        $this->sync_shared_token_rows_from_google_truth( $token_hash );

        global $wpdb;
        $table_lessons = $wpdb->prefix . 'mrm_lessons';
        $table_instructors = $wpdb->prefix . 'mrm_instructors';

        $resolved_gate = $this->resolve_gate_lesson_by_shared_token( $token_hash );

        $lesson = isset( $resolved_gate['lesson'] ) && is_array( $resolved_gate['lesson'] )
            ? $resolved_gate['lesson']
            : null;

        $appointment_type = isset( $resolved_gate['appointment_type'] )
            ? (string) $resolved_gate['appointment_type']
            : '';

        if ( ! is_array( $lesson ) || empty( $lesson['id'] ) ) {
            $this->render_gate_message_page(
                'Invalid Link',
                'This lesson link is not valid. Please use the link provided in your email or calendar event.'
            );
            exit;
        }

        if ( empty( $lesson['google_original_start_time'] ) && ! empty( $lesson['start_time'] ) ) {
            $lesson['google_original_start_time'] = (string) $lesson['start_time'];

            $wpdb->update(
                $table_lessons,
                array(
                    'google_original_start_time' => (string) $lesson['google_original_start_time'],
                    'updated_at' => current_time( 'mysql' ),
                ),
                array( 'id' => (int) $lesson['id'] ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        }

        $allowed_gate_statuses = array( 'scheduled', 'payment_due', 'delivered' );

        if ( ! in_array( (string) $lesson['status'], $allowed_gate_statuses, true ) ) {
            $this->render_gate_message_page(
                'Lesson Not Available',
                'This lesson is not currently available. If you believe this is an error, please contact support.'
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

        $calendar_id = ( is_array( $instr ) && ! empty( $instr['calendar_id'] ) ) ? (string) $instr['calendar_id'] : '';
        // Row timing was already bulk-synced before resolve_gate_lesson_by_shared_token().
        // Keep the selected lesson row as-is here.

        // Enforce 10-min before / 10-min after window
        $start_ts = strtotime( (string) ( $lesson['start_time'] ?? '' ) );
        $end_ts   = strtotime( (string) ( $lesson['end_time'] ?? '' ) );

        if ( ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) && ! empty( $lesson['google_original_start_time'] ) ) {
            $fallback_start_ts = strtotime( (string) $lesson['google_original_start_time'] );
            if ( $fallback_start_ts ) {
                $start_ts = $fallback_start_ts;
                $end_ts = $fallback_start_ts + ( max( 1, (int) ( $lesson['lesson_length'] ?? 60 ) ) * MINUTE_IN_SECONDS );
            }
        }

        if ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) {
            $this->render_gate_message_page(
                'Schedule Unavailable',
                'We could not determine the lesson time for this link. Please contact your instructor.'
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

        // For recurring series, reuse one shared deferred room URL across the whole series.
        $shared_meet_url = $this->get_shared_google_meet_url_for_lesson( $lesson );
        if ( $shared_meet_url !== '' ) {
            wp_redirect( $shared_meet_url, 302 );
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

        $this->persist_shared_google_meet_url_for_lesson( $lesson, (string) $meet_url );

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
            'timezone' => '',
            'stripe_connected_account_id' => null,
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
     * Availability is derived from explicit "Free" events on the calendar.
     */
    function rest_get_availability( WP_REST_Request $request ) {

        $instructor_id = absint( $request->get_param( 'instructor_id' ) );

        // Preferred params (new front-end)
        $start = sanitize_text_field( (string) $request->get_param( 'start_date' ) );
        $end   = sanitize_text_field( (string) $request->get_param( 'end_date' ) );

        // Legacy fallback
        if ( $start === '' ) { $start = sanitize_text_field( (string) $request->get_param( 'start' ) ); }
        if ( $end   === '' ) { $end   = sanitize_text_field( (string) $request->get_param( 'end' ) ); }

        $slot_minutes = absint( $request->get_param( 'slot_minutes' ) );
        if ( ! $slot_minutes ) $slot_minutes = 15;

        // Minimal validation
        if ( ! $instructor_id || ! $start || ! $end ) {
            return new WP_REST_Response( array(
                'ok'      => false,
                'message' => 'Missing required parameters.',
            ), 400 );
        }

        // We expect YYYY-MM-DD (scheduler.html sends this)
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
            return new WP_REST_Response( array(
                'ok'      => false,
                'message' => 'Invalid date format. Use YYYY-MM-DD.',
            ), 400 );
        }

        // 1) Availability windows from Google (your “free/transparent” availability blocks)
        $availability = $this->get_calendar_availability_events( $instructor_id, $start, $end );

        // 2) Busy windows (ALWAYS compute: DB lessons + Google opaque events)
        // IMPORTANT: Keep DB busy separate from Google busy so we don't lose lesson_type metadata.
        $busy_db = array();
        $busy_google = array();

        // Build time_min/time_max UTC using instructor timezone (same approach your file already uses elsewhere)
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT calendar_id, timezone FROM {$wpdb->prefix}mrm_instructors WHERE id = %d",
                (int) $instructor_id
            ),
            ARRAY_A
        );

        $calendar_id = is_array( $row ) ? (string) ( $row['calendar_id'] ?? '' ) : '';
        $tz          = is_array( $row ) ? (string) ( $row['timezone'] ?? '' ) : '';
        if ( ! $tz ) $tz = 'America/Phoenix';

        try {
            $start_local = DateTime::createFromFormat( 'Y-m-d', $start, new DateTimeZone( $tz ) );
            $end_local   = DateTime::createFromFormat( 'Y-m-d', $end,   new DateTimeZone( $tz ) );

            if ( $start_local && $end_local ) {
                $start_local->setTime( 0, 0, 0 );
                $end_local->setTime( 0, 0, 0 );
                $end_local->modify( '+1 day' ); // inclusive end_date

                $start_utc = clone $start_local;
                $end_utc   = clone $end_local;
                $start_utc->setTimezone( new DateTimeZone( 'UTC' ) );
                $end_utc->setTimezone( new DateTimeZone( 'UTC' ) );

                $time_min = $start_utc->format( 'Y-m-d\TH:i:s\Z' );
                $time_max = $end_utc->format( 'Y-m-d\TH:i:s\Z' );

                // Map Google event IDs -> actual start/end timestamps for reconciliation (deletes/moves)
                $google_event_map = array();

                if ( $calendar_id !== '' && $this->google_is_configured() ) {
                    $events = $this->google_list_events( $calendar_id, $time_min, $time_max );
                    if ( ! is_wp_error( $events ) && is_array( $events ) ) {
                        $items = isset( $events['items'] ) && is_array( $events['items'] ) ? $events['items'] : $events;
                        foreach ( $items as $ev ) {
                            $id = (string) ( $ev['id'] ?? '' );
                            if ( $id === '' ) continue;

                            $start_dt = $ev['start']['dateTime'] ?? '';
                            $end_dt   = $ev['end']['dateTime'] ?? '';

                            // Skip all-day events
                            if ( ! $start_dt || ! $end_dt ) continue;

                            $s = strtotime( (string) $start_dt );
                            $e = strtotime( (string) $end_dt );
                            if ( ! $s || ! $e || $e <= $s ) continue;

                            $google_event_map[ $id ] = array( 'start_ts' => $s, 'end_ts' => $e );
                        }
                    }
                }

                // DB busy (scheduled lessons table) — includes lesson_type/lesson_minutes
                $db_busy = $this->db_busy_intervals_for_instructor( $instructor_id, $time_min, $time_max );
                foreach ( (array) $db_busy as $b ) {
                    $geid = (string) ( $b['google_event_id'] ?? '' );
                    if ( $geid !== '' ) {
                        if ( empty( $google_event_map[ $geid ] ) ) {
                            // Event was deleted from Google -> do NOT treat as booked
                            continue;
                        }
                        // Event exists but may have moved -> override DB timestamps with Google timestamps
                        $b['start_ts'] = (int) $google_event_map[ $geid ]['start_ts'];
                        $b['end_ts']   = (int) $google_event_map[ $geid ]['end_ts'];
                        $b['start']    = gmdate( 'c', (int) $b['start_ts'] );
                        $b['end']      = gmdate( 'c', (int) $b['end_ts'] );
                    }
                    if ( empty( $b['start_ts'] ) || empty( $b['end_ts'] ) ) continue;
                    $busy_db[] = array(
                        'start'          => (string) ( $b['start'] ?? gmdate( 'c', (int) $b['start_ts'] ) ),
                        'end'            => (string) ( $b['end']   ?? gmdate( 'c', (int) $b['end_ts'] ) ),
                        'start_ts'       => (int) $b['start_ts'],
                        'end_ts'         => (int) $b['end_ts'],

                        'lesson_type'    => (string) ( $b['lesson_type'] ?? '' ),    // 'online' | 'in_person'
                        'lesson_minutes' => (int)    ( $b['lesson_minutes'] ?? 0 ),  // 30 | 60
                        'source'         => 'db',
                    );
                }

                // Google FreeBusy busy (opaque events)
                if ( $calendar_id !== '' && $this->google_is_configured() ) {
                    $fb = $this->google_freebusy( array( $calendar_id ), $time_min, $time_max );
                    if ( ! is_wp_error( $fb ) ) {
                        foreach ( (array) $fb as $b ) {
                            if ( empty( $b['start'] ) || empty( $b['end'] ) ) continue;
                            $bs = strtotime( (string) $b['start'] );
                            $be = strtotime( (string) $b['end'] );
                            if ( ! $bs || ! $be || $be <= $bs ) continue;
                            $busy_google[] = array(
                                'start'       => gmdate( 'c', (int) $bs ),
                                'end'         => gmdate( 'c', (int) $be ),
                                'start_ts' => (int) $bs,
                                'end_ts'   => (int) $be,
                                // leave lesson_type unset for google
                                'source'   => 'google',
                            );
                        }
                    }
                }

                // Merge within each source ONLY (keeps DB lesson_type intact)
                $busy_db = $this->merge_intervals( $busy_db );
                $busy_google = $this->merge_intervals( $busy_google );

                // Drop google intervals that overlap DB intervals (prevents dupes and metadata loss)
                if ( ! empty( $busy_db ) && ! empty( $busy_google ) ) {
                    $filtered_google = array();
                    foreach ( $busy_google as $g ) {
                        $overlap = false;
                        foreach ( $busy_db as $d ) {
                            if ( max( (int)$g['start_ts'], (int)$d['start_ts'] ) < min( (int)$g['end_ts'], (int)$d['end_ts'] ) ) {
                                $overlap = true;
                                break;
                            }
                        }
                        if ( ! $overlap ) $filtered_google[] = $g;
                    }
                    $busy_google = $filtered_google;
                }

                // Final busy list: DB first (metadata-rich), then google
                $busy = array_merge( $busy_db, $busy_google );
            } else {
                $busy = array();
            }
        } catch ( Exception $e ) {
            $busy = array();
        }

        // 3) Split availability into slots
        $slots = array();
        foreach ( (array) $availability as $win ) {
            $s = strtotime( (string) ( $win['start'] ?? '' ) );
            $e = strtotime( (string) ( $win['end'] ?? '' ) );
            if ( ! $s || ! $e || $e <= $s ) continue;

            $slots = array_merge(
                $slots,
                (array) $this->split_into_lesson_slots( $s, $e, $instructor_id, $slot_minutes )
            );
        }

        // 4) Filter slots by busy overlaps
        if ( ! empty( $busy ) && ! empty( $slots ) ) {
            $filtered = array();
            foreach ( $slots as $slot ) {
                $ss = strtotime( (string) ( $slot['start'] ?? '' ) );
                $se = strtotime( (string) ( $slot['end'] ?? '' ) );
                if ( ! $ss || ! $se || $se <= $ss ) continue;

                $conflict = false;
                foreach ( $busy as $b ) {
                    $bs = (int) ( $b['start_ts'] ?? 0 );
                    $be = (int) ( $b['end_ts'] ?? 0 );
                    if ( $bs && $be && max( $ss, $bs ) < min( $se, $be ) ) {
                        $conflict = true;
                        break;
                    }
                }
                if ( ! $conflict ) $filtered[] = $slot;
            }
            $slots = $filtered;
        }

        // Return in the format scheduler.html expects
        // - slots: for booking
        // - busy: for calendar shading / busy markers
        // - availability: legacy alias used in older code paths
        return new WP_REST_Response( array(
            'ok'           => true,
            'slots'        => array_values( $slots ),
            'busy'         => array_values( $busy ),
            'availability' => array_values( $slots ),
        ), 200 );
    }


    private function mrm_is_valid_ymd( $s ) {
        $s = (string) $s;
        if ( ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $s ) ) {
            return false;
        }
        $y = (int) substr( $s, 0, 4 );
        $m = (int) substr( $s, 5, 2 );
        $d = (int) substr( $s, 8, 2 );
        return checkdate( $m, $d, $y );
    }

    private function get_calendar_availability_events( $instructor_id, $start, $end ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mrm_instructors';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT calendar_id, timezone FROM {$table} WHERE id = %d", (int) $instructor_id ), ARRAY_A );
        if ( ! $row ) {
            return array();
        }
        $calendar_id = (string) ( $row['calendar_id'] ?? '' );
        if ( $calendar_id === '' ) {
            return array();
        }
        if ( ! $this->google_is_configured() ) {
            return array();
        }

        $tz = (string) ( $row['timezone'] ?? '' );
        if ( ! $tz ) {
            $tz = 'America/Phoenix';
        }

        try {
            // We require strict YYYY-MM-DD from the REST layer, so interpret as local-day boundaries:
            // - start_date => inclusive at 00:00:00
            // - end_date   => inclusive, implemented as EXCLUSIVE upper bound (end + 1 day at 00:00:00)
            $start_local = DateTime::createFromFormat( 'Y-m-d', $start, new DateTimeZone( $tz ) );
            $end_local   = DateTime::createFromFormat( 'Y-m-d', $end,   new DateTimeZone( $tz ) );

            if ( ! $start_local || ! $end_local ) {
                return array();
            }

            $start_local->setTime( 0, 0, 0 );
            $end_local->setTime( 0, 0, 0 );
            $end_local->modify( '+1 day' ); // makes end_date inclusive
        } catch ( Exception $e ) {
            return array();
        }

        $start_utc = clone $start_local;
        $end_utc   = clone $end_local;
        $start_utc->setTimezone( new DateTimeZone( 'UTC' ) );
        $end_utc->setTimezone( new DateTimeZone( 'UTC' ) );
        $time_min = $start_utc->format( 'Y-m-d\\TH:i:s\\Z' );
        $time_max = $end_utc->format( 'Y-m-d\\TH:i:s\\Z' );

        $events_payload = $this->google_list_events( $calendar_id, $time_min, $time_max );
        if ( is_wp_error( $events_payload ) ) {
            return array();
        }
        $events = isset( $events_payload['items'] ) && is_array( $events_payload['items'] ) ? $events_payload['items'] : array();

        $windows = $this->events_to_availability_windows( $events, '' );
        $availability = array();
        foreach ( $windows as $win ) {
            if ( empty( $win['start_ts'] ) || empty( $win['end_ts'] ) ) {
                continue;
            }
            $availability[] = array(
                'start' => gmdate( 'c', (int) $win['start_ts'] ),
                'end'   => gmdate( 'c', (int) $win['end_ts'] ),
            );
        }
        return $availability;
    }

    private function split_into_lesson_slots( $start_ts, $end_ts, $instructor_id, $slot_minutes_override = 0 ) {
        $opts = $this->get_settings();
        $slot_minutes = $slot_minutes_override ? (int)$slot_minutes_override : ( isset( $opts['default_slot_minutes'] ) ? (int) $opts['default_slot_minutes'] : 30 );
        if ( $slot_minutes < 10 ) {
            $slot_minutes = 30;
        }
        $windows = array(
            array(
                'start_ts' => (int) $start_ts,
                'end_ts'   => (int) $end_ts,
            ),
        );
        return $this->build_slots_from_windows( $windows, $slot_minutes );
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

    protected function get_lessons_needing_google_truth_pass( $limit = 300 ) {
        global $wpdb;

        $lessons_table = $wpdb->prefix . 'mrm_lessons';
        $instructors_table = $wpdb->prefix . 'mrm_instructors';
        $limit = max( 1, (int) $limit );

        $sql = "
        SELECT l.*, i.calendar_id, i.timezone
        FROM {$lessons_table} l
        JOIN {$instructors_table} i ON i.id = l.instructor_id
        WHERE i.calendar_id IS NOT NULL
          AND i.calendar_id <> ''
          AND (
                (
                    l.payment_mode = 'autopay'
                    AND (
                        l.status IN ('scheduled', 'payment_due', 'delivered')
                        OR (
                            l.payout_unlocked_at IS NULL
                            AND l.status NOT IN ('cancelled', 'series')
                        )
                    )
                )
                OR
                (
                    l.payment_mode <> 'autopay'
                    AND l.status = 'scheduled'
                    AND l.payout_unlocked_at IS NULL
                )
          )
        ORDER BY l.start_time ASC
        LIMIT {$limit}
    ";

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    public function cron_sync_upcoming_events( $lookback_hours = 6, $lookahead_days = 7 ) {
        if ( ! $this->google_is_configured() ) return;

        $rows = $this->get_lessons_needing_google_truth_pass( 400 );

        // Debug: record when the sync job fires and how many rows were fetched.  This aids in
        // diagnosing whether WP‑Cron is running versus whether the resolver is failing.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $count = ( is_array( $rows ) ? count( $rows ) : 0 );
            error_log( '[MRM] cron_sync_upcoming_events running at ' . current_time( 'mysql' ) . ' with ' . $count . ' rows' );
        }

        if ( ! is_array( $rows ) || empty( $rows ) ) return;

        foreach ( $rows as $lesson_row ) {
            $calendar_id = (string) ( $lesson_row['calendar_id'] ?? '' );
            if ( $calendar_id === '' ) {
                continue;
            }

            $sync = $this->sync_lesson_row_from_google_truth( $lesson_row, $calendar_id, 120 );

            if ( (string) ( $sync['status'] ?? '' ) === 'cancelled' ) {
                $this->cancel_lesson_and_notify( $lesson_row, 'google_event_cancelled' );
                continue;
            }

            // unresolved rows are left for later runs / payment recovery pass
        }
    }

    public function register_custom_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['mrm_10min'] ) ) {
            $schedules['mrm_10min'] = array(
                'interval' => 10 * 60,
                'display'  => __( 'Every 10 minutes (MRM)', 'mrm-lesson-scheduler' ),
            );
        }

        return $schedules;
    }


    protected function cancel_lesson_and_notify( $lesson_row, $reason = '' ) {
        global $wpdb;

        if ( ! is_array( $lesson_row ) ) return;
        $lesson_id = (int) ( $lesson_row['id'] ?? 0 );
        if ( $lesson_id <= 0 ) return;

        $lessons_table = $wpdb->prefix . 'mrm_lessons';

        $wpdb->update(
            $lessons_table,
            array(
                'status' => 'cancelled',
                'updated_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $lesson_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        do_action( 'mrm_lesson_cancelled', array(
            'lesson_id' => $lesson_id,
            'instructor_id' => (int) ( $lesson_row['instructor_id'] ?? 0 ),
            'student_email' => (string) ( $lesson_row['student_email'] ?? '' ),
            'lesson_length' => (int) ( $lesson_row['lesson_length'] ?? 0 ),
            'is_online' => (int) ( $lesson_row['is_online'] ?? 0 ),
            'order_id' => (int) ( $lesson_row['order_id'] ?? 0 ),
            'payment_mode' => (string) ( $lesson_row['payment_mode'] ?? 'none' ),
            'autopay_profile_id' => (int) ( $lesson_row['autopay_profile_id'] ?? 0 ),
            'series_id' => (int) ( $lesson_row['series_id'] ?? 0 ),
            'google_event_id' => (string) ( $lesson_row['google_event_id'] ?? '' ),
            'cancel_reason' => (string) $reason,
        ) );
    }

    public function cron_reconcile_completed_lessons() {
        global $wpdb;

        $this->cron_sync_upcoming_events( 72, 30 );

        $lessons_table = $wpdb->prefix . 'mrm_lessons';
        $rows = $this->get_lessons_needing_google_truth_pass( 300 );

        if ( ! $rows ) return;

        foreach ( $rows as $l ) {
            $calendar_id = (string) ( $l['calendar_id'] ?? '' );
            $booking_id  = (int) ( $l['id'] ?? 0 );

            if ( $calendar_id === '' || ! $this->google_is_configured() ) {
                continue;
            }

            $sync = $this->sync_lesson_row_from_google_truth( $l, $calendar_id, 120 );
            $sync_status = (string) ( $sync['status'] ?? 'unresolved' );
            $ev = $sync['event'] ?? null;

            if ( $sync_status === 'cancelled' ) {
                $this->cancel_lesson_and_notify( $l, 'google_event_cancelled' );
                continue;
            }

            if ( $sync_status !== 'resolved' || ! is_array( $ev ) ) {
                continue;
            }

            $synced_lesson = isset( $sync['lesson_row'] ) && is_array( $sync['lesson_row'] )
                ? $sync['lesson_row']
                : $l;

            $google_status = strtolower( (string) ( $ev['status'] ?? 'confirmed' ) );
            if ( $google_status === 'cancelled' ) {
                $this->cancel_lesson_and_notify( $synced_lesson, 'google_event_cancelled' );
                continue;
            }

            $new_start = (string) ( $sync['start_utc'] ?? '' );
            $new_end   = (string) ( $sync['end_utc'] ?? '' );
            if ( $new_end === '' ) {
                continue;
            }

            // Google end time is the source of truth.
            if ( strtotime( $new_end ) > time() ) {
                continue;
            }

            $new_event_id = (string) ( $synced_lesson['google_event_id'] ?? ( $ev['id'] ?? '' ) );
            $payment_mode = (string) ( $synced_lesson['payment_mode'] ?? 'none' );
            $now_mysql = current_time( 'mysql' );

            if ( $payment_mode === 'autopay' ) {
                $autopay_profile_id = (int) ( $synced_lesson['autopay_profile_id'] ?? 0 );

                if ( $autopay_profile_id <= 0 ) {
                    $wpdb->update(
                        $lessons_table,
                        array(
                            'start_time'        => ( $new_start ?: (string) ( $synced_lesson['start_time'] ?? '' ) ),
                            'end_time'          => $new_end,
                            'google_event_id'   => ( $new_event_id !== '' ? $new_event_id : null ),
                            'delivered_at'      => $now_mysql,
                            'charge_due_at'     => $now_mysql,
                            'charge_status'     => 'failed',
                            'charge_last_error' => 'Missing autopay_profile_id on ended autopay lesson row.',
                            'status'            => 'payment_due',
                            'updated_at'        => $now_mysql,
                        ),
                        array( 'id' => $booking_id ),
                        array( '%s','%s','%s','%s','%s','%s','%s','%s','%s' ),
                        array( '%d' )
                    );
                    continue;
                }

                $wpdb->update(
                    $lessons_table,
                    array(
                        'start_time'        => ( $new_start ?: (string) ( $synced_lesson['start_time'] ?? '' ) ),
                        'end_time'          => $new_end,
                        'google_event_id'   => ( $new_event_id !== '' ? $new_event_id : null ),
                        'delivered_at'      => $now_mysql,
                        'charge_due_at'     => $now_mysql,
                        'charge_status'     => ( (string) ( $synced_lesson['charge_status'] ?? '' ) === 'paid' ? 'paid' : 'due' ),
                        'charge_last_error' => ( (string) ( $synced_lesson['charge_status'] ?? '' ) === 'paid' ? null : null ),
                        'status'            => ( (string) ( $synced_lesson['charge_status'] ?? '' ) === 'paid' ? 'delivered' : 'payment_due' ),
                        'updated_at'        => $now_mysql,
                    ),
                    array( 'id' => $booking_id ),
                    array( '%s','%s','%s','%s','%s','%s','%s','%s','%s' ),
                    array( '%d' )
                );

                if ( (string) ( $synced_lesson['charge_status'] ?? '' ) !== 'paid' ) {
                    do_action( 'mrm_lesson_charge_due', array(
                        'lesson_id' => $booking_id,
                        'instructor_id' => (int) $synced_lesson['instructor_id'],
                        'student_email' => (string) $synced_lesson['student_email'],
                        'lesson_length' => (int) $synced_lesson['lesson_length'],
                        'is_online' => (int) $synced_lesson['is_online'],
                        'order_id' => (int) ( $synced_lesson['order_id'] ?? 0 ),
                        'payment_mode' => $payment_mode,
                        'autopay_profile_id' => $autopay_profile_id,
                        'google_event_id' => $new_event_id,
                        'ended_at_utc' => (string) $new_end,
                    ) );
                }

                continue;
            }

            $wpdb->update(
                $lessons_table,
                array(
                    'start_time'         => ( $new_start ?: (string) ( $synced_lesson['start_time'] ?? '' ) ),
                    'end_time'           => $new_end,
                    'google_event_id'    => ( $new_event_id !== '' ? $new_event_id : null ),
                    'status'             => 'delivered',
                    'delivered_at'       => $now_mysql,
                    'payout_unlocked_at' => $now_mysql,
                    'updated_at'         => $now_mysql
                ),
                array( 'id' => $booking_id ),
                array( '%s','%s','%s','%s','%s','%s' ),
                array( '%d' )
            );

            do_action( 'mrm_lesson_delivered', array(
                'lesson_id' => $booking_id,
                'instructor_id' => (int) $synced_lesson['instructor_id'],
                'student_email' => (string) $synced_lesson['student_email'],
                'lesson_length' => (int) $synced_lesson['lesson_length'],
                'is_online' => (int) $synced_lesson['is_online'],
                'order_id' => (int) ( $synced_lesson['order_id'] ?? 0 ),
                'payment_mode' => $payment_mode,
                'autopay_profile_id' => (int) ( $synced_lesson['autopay_profile_id'] ?? 0 ),
                'google_event_id' => $new_event_id,
                'ended_at_utc' => (string) $new_end,
            ) );
        }
    }

    public function cron_reconcile_cancelled_lessons() {
        global $wpdb;

        if ( ! $this->google_is_configured() ) return;

        // Sync first so moved recurring instances update local start/end times.
        $this->cron_sync_upcoming_events( 72, 30 );

        $lessons_table = $wpdb->prefix . 'mrm_lessons';
        $instructors_table = $wpdb->prefix . 'mrm_instructors';

        $rows = $wpdb->get_results("
            SELECT l.*, i.calendar_id, i.timezone
            FROM {$lessons_table} l
            JOIN {$instructors_table} i ON i.id = l.instructor_id
            WHERE l.status='scheduled'
              AND l.google_event_id IS NOT NULL
              AND l.google_event_id <> ''
              AND l.start_time > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)
            ORDER BY l.start_time ASC
            LIMIT 200
        ", ARRAY_A);

        if ( ! $rows ) return;

        foreach ( $rows as $l ) {
            $calendar_id = (string) ( $l['calendar_id'] ?? '' );
            $event_id    = (string) ( $l['google_event_id'] ?? '' );
            $booking_id  = (int) ( $l['id'] ?? 0 );

            if ( $calendar_id === '' || $event_id === '' || $booking_id <= 0 ) continue;

            $resolved = $this->resolve_google_event_for_lesson_row( $l, $calendar_id, 30 );
            $resolved_status = (string) ( $resolved['status'] ?? 'unresolved' );
            $ev = $resolved['event'] ?? null;
            $reason = (string) ( $resolved['reason'] ?? '' );

            // Only explicit Google cancellation should cancel immediately.
            if ( $resolved_status === 'cancelled' ) {
                $this->cancel_lesson_and_notify( $l, 'google_event_cancelled' );
                continue;
            }

            // If still unresolved, do not cancel on the first miss.
            // Require stronger proof by checking for a previous unresolved marker.
            if ( $resolved_status === 'unresolved' ) {
                $last_reason = (string) ( $l['google_event_id'] ?? '' );

                // First unresolved pass: mark/update timestamp only, do not cancel yet.
                $wpdb->update(
                    $lessons_table,
                    array(
                        'updated_at' => current_time( 'mysql' ),
                    ),
                    array( 'id' => (int) $l['id'] ),
                    array( '%s' ),
                    array( '%d' )
                );

                error_log(
                    'MRM cancelled-reconcile: unresolved recurring lesson left scheduled'
                    . ' lesson_id=' . (int) $l['id']
                    . ' reason=' . $reason
                );

                continue;
            }

            // Resolved and not cancelled: keep scheduled.
            if ( $resolved_status === 'resolved' && is_array( $ev ) ) {
                continue;
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
            $timezone    = isset( $_POST['timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone'] ) ) : '';
            $stripe_connected_account_id = isset( $_POST['stripe_connected_account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_connected_account_id'] ) ) : '';
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
                if ( $stripe_connected_account_id && ! preg_match( '/^acct_[A-Za-z0-9]+$/', $stripe_connected_account_id ) ) $errors[] = 'Stripe Connected Account ID must start with acct_.';
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
                    'timezone' => $timezone,
                    'stripe_connected_account_id' => ( $stripe_connected_account_id === '' ? null : $stripe_connected_account_id ),
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
                        <th scope="row"><label for="stripe_connected_account_id">Stripe Connected Account ID</label></th>
                        <td>
                            <input name="stripe_connected_account_id" id="stripe_connected_account_id" type="text" class="regular-text" placeholder="acct_..." value="<?php echo esc_attr( $editing['stripe_connected_account_id'] ?? '' ); ?>">
                            <p class="description">Paste the instructor’s Stripe Connect account ID here after onboarding.</p>
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
                            <th>ID</th><th>Name</th><th>Email</th><th>City</th><th>Start Date</th><th>Stripe Acct</th><th>Calendar ID</th><th>Timezone</th><th>Actions</th>
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
                                <td><code><?php echo esc_html( $r['stripe_connected_account_id'] ?? '' ); ?></code></td>
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
            <h2>2) Slot Rules</h2>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field( 'mrm_scheduler_save_google', 'mrm_scheduler_google_nonce' ); ?>
                <input type="hidden" name="action" value="mrm_scheduler_save_google">
                <input type="hidden" name="save_slot_rules" value="1">
                <table class="form-table" role="presentation">
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
            <h2>3) Test Connection</h2>
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
                <li>Under <strong>Share with specific people</strong>, add the Service Account Email and give it at least <strong>See all event details</strong>.</li>
                <li>Do <strong>NOT</strong> make the calendar public.</li>
            </ol>
            <h3>How to create availability windows</h3>
            <ol>
                <li>Create events for availability (e.g. <code>AVAILABLE – Lessons</code>).</li>
                <li>Set <strong>Show as</strong> = <strong>Free</strong>.</li>
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
        if ( isset( $_POST['save_slot_rules'] ) ) {
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
            "SELECT start_time, end_time, is_online, google_event_id FROM {$table} WHERE instructor_id = %d AND status = %s AND end_time >= %s AND start_time <= %s",
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
                'google_event_id' => (string) ( $r['google_event_id'] ?? '' ),

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
    protected function build_google_recurrence_rules( $repeat_frequency, $repeat_duration, $start_ts ) {
        $repeat_frequency = strtolower( trim( (string) $repeat_frequency ) );
        $repeat_duration  = strtolower( trim( (string) $repeat_duration ) );
        $start_ts         = (int) $start_ts;

        if ( $start_ts <= 0 ) return array();
        if ( $repeat_frequency !== 'weekly' && $repeat_frequency !== 'biweekly' ) return array();

        $interval = ( $repeat_frequency === 'biweekly' ) ? 2 : 1;

        // If duration is indefinite, do not include COUNT/UNTIL.
        if ( $repeat_duration === 'indefinitely' ) {
            return array(
                'RRULE:FREQ=WEEKLY;INTERVAL=' . $interval
            );
        }

        // Map your UI values to a lesson count.
        $count_map = array(
            '1_month'  => 4,
            '2_months' => 8,
            '3_months' => 12,
            '6_months' => 24,
            '12_months' => 48,
        );

        if ( $interval === 2 ) {
            $count_map = array(
                '1_month'  => 2,
                '2_months' => 4,
                '3_months' => 6,
                '6_months' => 12,
                '12_months' => 24,
            );
        }

        $count = isset( $count_map[ $repeat_duration ] ) ? (int) $count_map[ $repeat_duration ] : 0;
        if ( $count <= 0 ) {
            return array(
                'RRULE:FREQ=WEEKLY;INTERVAL=' . $interval
            );
        }

        return array(
            'RRULE:FREQ=WEEKLY;INTERVAL=' . $interval . ';COUNT=' . $count
        );
    }

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

        // Only set location when we have a real in-person location.
        // (Online lessons: omit location entirely so it doesn't show up at all.)
        $loc = trim( (string) $location );
        if ( $loc !== '' ) {
            $payload['location'] = $loc;
        }

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

        // If requested explicitly, generate a Google Meet link for this event.
        // IMPORTANT: We no longer auto-create Meet for online lessons.
        $want_meet = (bool) $create_meet;

        if ( $want_meet ) {
            $request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 8 ) );
            $payload['conferenceData'] = array(
                'createRequest' => array(
                    'requestId' => $request_id,
                    'conferenceSolutionKey' => array(
                        'type' => 'hangoutsMeet',
                    ),
                ),
            );
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
