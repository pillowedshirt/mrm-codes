
<?php
/*
 * Plugin Name: MRM Lesson Scheduler
 * Description: Lesson scheduling + instructor directory for Music at the Right Moment.
 * Version: 1.5.1
 * Author: Your Name
 *
 * Google Calendar integration:
 * - Service Account JSON loaded from AWS Secrets Manager
 * - /availability supports:
 *   "Free" events define availability windows (calendar-driven scheduling)
 *
 * SECURITY NOTE:
 * Service Account JSON contains a private key. Keep AWS credentials and secret access tightly restricted.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$autoload = ABSPATH . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
    require_once $autoload;
}

use Aws\SecretsManager\SecretsManagerClient;
use Aws\Exception\AwsException;

/**
 * Main plugin class. Encapsulates all functionality for the scheduler.
 */
class MRM_Lesson_Scheduler {
    protected static $instance;
    protected $option_key = 'mrm_scheduler_settings';
    protected $options = array();
    const DB_VERSION = '1.5.8';
    const CAPABILITY = 'manage_options';
    const TERMS_VERSION = '2026-04-25';
    // Google endpoints
    const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const GOOGLE_FREEBUSY_URL = 'https://www.googleapis.com/calendar/v3/freeBusy';
    // Google events list endpoint
    const GOOGLE_EVENTS_LIST_URL = 'https://www.googleapis.com/calendar/v3/calendars/%s/events';

    protected function mrm_aws_debug_log( $message, $context = array() ) {
        return;
    }

    protected function mrm_get_secret_json( $secret_id, $cache_key ) {
        $this->mrm_aws_debug_log( 'Scheduler AWS call started', array(
            'secret_id' => $secret_id,
            'cache_key' => $cache_key,
        ) );

        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            $this->mrm_aws_debug_log( 'Scheduler AWS cache hit', array(
                'secret_id' => $secret_id,
                'cache_key' => $cache_key,
                'keys_present' => array_keys( $cached ),
            ) );
            return $cached;
        }

        if (
            ! defined( 'MRM_AWS_REGION' ) ||
            ! defined( 'MRM_AWS_ACCESS_KEY_ID' ) ||
            ! defined( 'MRM_AWS_SECRET_ACCESS_KEY' )
        ) {
            $this->mrm_aws_debug_log( 'Scheduler AWS constants missing', array(
                'region_defined' => defined( 'MRM_AWS_REGION' ),
                'key_defined' => defined( 'MRM_AWS_ACCESS_KEY_ID' ),
                'secret_defined' => defined( 'MRM_AWS_SECRET_ACCESS_KEY' ),
            ) );
            return null;
        }

        try {
            $client = new SecretsManagerClient( array(
                'version' => 'latest',
                'region'  => MRM_AWS_REGION,
                'credentials' => array(
                    'key'    => MRM_AWS_ACCESS_KEY_ID,
                    'secret' => MRM_AWS_SECRET_ACCESS_KEY,
                ),
            ) );

            $this->mrm_aws_debug_log( 'Scheduler calling AWS Secrets Manager', array(
                'secret_id' => $secret_id,
                'region' => MRM_AWS_REGION,
            ) );

            $result = $client->getSecretValue( array(
                'SecretId' => $secret_id,
            ) );

            if ( empty( $result['SecretString'] ) ) {
                $this->mrm_aws_debug_log( 'Scheduler AWS returned empty SecretString', array(
                    'secret_id' => $secret_id,
                ) );
                return null;
            }

            $decoded = json_decode( (string) $result['SecretString'], true );

            if ( ! is_array( $decoded ) ) {
                $this->mrm_aws_debug_log( 'Scheduler AWS SecretString did not decode to array', array(
                    'secret_id' => $secret_id,
                    'secret_string_length' => strlen( (string) $result['SecretString'] ),
                ) );
                return null;
            }

            $context = array(
                'secret_id' => $secret_id,
                'keys_present' => array_keys( $decoded ),
            );

            if ( array_key_exists( 'service_account_json', $decoded ) ) {
                $context['service_account_json_type'] = gettype( $decoded['service_account_json'] );
                if ( is_string( $decoded['service_account_json'] ) ) {
                    $context['service_account_json_length'] = strlen( $decoded['service_account_json'] );
                    $context['service_account_json_preview'] = substr( $decoded['service_account_json'], 0, 120 );
                }
                if ( is_array( $decoded['service_account_json'] ) ) {
                    $context['service_account_json_keys'] = array_keys( $decoded['service_account_json'] );
                }
            } else {
                $context['service_account_json_missing'] = true;
            }

            if ( array_key_exists( 'sync_secret', $decoded ) ) {
                $context['sync_secret_present'] = true;
                $context['sync_secret_length'] = is_string( $decoded['sync_secret'] ) ? strlen( $decoded['sync_secret'] ) : 0;
            } else {
                $context['sync_secret_missing'] = true;
            }

            $this->mrm_aws_debug_log( 'Scheduler AWS secret decoded successfully', $context );

            set_transient( $cache_key, $decoded, 15 * MINUTE_IN_SECONDS );

            $this->mrm_aws_debug_log( 'Scheduler AWS secret cached', array(
                'secret_id' => $secret_id,
                'cache_key' => $cache_key,
            ) );

            return $decoded;
        } catch ( AwsException $e ) {
            $this->mrm_aws_debug_log( 'Scheduler AWS exception', array(
                'secret_id' => $secret_id,
                'aws_error_message' => $e->getAwsErrorMessage(),
                'aws_error_code' => $e->getAwsErrorCode(),
            ) );
            return null;
        } catch ( \Throwable $e ) {
            $this->mrm_aws_debug_log( 'Scheduler AWS fatal exception', array(
                'secret_id' => $secret_id,
                'message' => $e->getMessage(),
            ) );
            return null;
        }
    }

    protected function mrm_first_non_empty_google_string($candidates, $keys) {
        if ( ! is_array( $candidates ) ) {
            return '';
        }

        foreach ( $candidates as $candidate ) {
            if ( ! is_array( $candidate ) ) {
                continue;
            }

            foreach ( $keys as $key ) {
                if ( ! array_key_exists( $key, $candidate ) ) {
                    continue;
                }

                $value = $candidate[ $key ];

                if ( is_array( $value ) || is_object( $value ) ) {
                    continue;
                }

                $value = trim( (string) $value );
                if ( $value !== '' ) {
                    return $value;
                }
            }
        }

        return '';
    }

    protected function mrm_normalize_google_scheduler_secret_bundle( $secret ) {
        if ( ! is_array( $secret ) ) {
            return null;
        }

        $service_account_json = '';
        $sync_secret = '';
        $maps_distance_api_key = '';

        // Case 1: Wrapped service account JSON in expected key.
        if ( array_key_exists( 'service_account_json', $secret ) ) {
            if ( is_string( $secret['service_account_json'] ) && trim( $secret['service_account_json'] ) !== '' ) {
                $service_account_json = trim( (string) $secret['service_account_json'] );
            } elseif ( is_array( $secret['service_account_json'] ) && ! empty( $secret['service_account_json'] ) ) {
                $encoded = wp_json_encode( $secret['service_account_json'] );
                if ( is_string( $encoded ) && $encoded !== '' ) {
                    $service_account_json = $encoded;
                }
            }
        }

        // Case 2: Raw top-level Google service account object.
        if (
            $service_account_json === '' &&
            ! empty( $secret['client_email'] ) &&
            ! empty( $secret['private_key'] )
        ) {
            $raw_service_account = $secret;
            unset(
                $raw_service_account['sync_secret'],
                $raw_service_account['google_sync_secret'],
                $raw_service_account['sync_token']
            );

            $encoded = wp_json_encode( $raw_service_account );
            if ( is_string( $encoded ) && $encoded !== '' ) {
                $service_account_json = $encoded;
            }
        }

        // Case 3: Nested service account objects.
        if ( $service_account_json === '' ) {
            foreach ( array( 'service_account', 'google_service_account', 'google', 'credentials' ) as $nested_key ) {
                if ( ! isset( $secret[ $nested_key ] ) || ! is_array( $secret[ $nested_key ] ) ) {
                    continue;
                }

                $candidate = $secret[ $nested_key ];
                if ( empty( $candidate['client_email'] ) || empty( $candidate['private_key'] ) ) {
                    continue;
                }

                $encoded = wp_json_encode( $candidate );
                if ( is_string( $encoded ) && $encoded !== '' ) {
                    $service_account_json = $encoded;
                    break;
                }
            }
        }

        $sync_secret = $this->mrm_first_non_empty_google_string(
            array( $secret ),
            array( 'sync_secret', 'google_sync_secret', 'sync_token' )
        );

        $maps_distance_api_key = $this->mrm_first_non_empty_google_string(
            array( $secret ),
            array( 'maps_distance_api_key' )
        );

        return array(
            'service_account_json'    => $service_account_json,
            'sync_secret'             => $sync_secret,
            'maps_distance_api_key'   => $maps_distance_api_key,
            '_raw_keys'               => array_keys( $secret ),
        );
    }

    protected function mrm_get_google_scheduler_secret_bundle() {
        if ( ! defined( 'MRM_SECRET_GOOGLE_SCHEDULER' ) ) {
            $this->mrm_aws_debug_log( 'MRM Google AWS secret constant MRM_SECRET_GOOGLE_SCHEDULER is not defined.' );
            return null;
        }

        $secret = $this->mrm_get_secret_json(
            MRM_SECRET_GOOGLE_SCHEDULER,
            'mrm_secret_google_scheduler_v5'
        );

        if ( ! is_array( $secret ) ) {
            $this->mrm_aws_debug_log( 'MRM Google AWS secret bundle could not be loaded from Secrets Manager.', array(
                'secret_id' => MRM_SECRET_GOOGLE_SCHEDULER,
            ) );
            return null;
        }

        $normalized = $this->mrm_normalize_google_scheduler_secret_bundle( $secret );

        if ( ! is_array( $normalized ) ) {
            $this->mrm_aws_debug_log( 'MRM Google AWS secret bundle normalization failed.', array(
                'secret_id' => MRM_SECRET_GOOGLE_SCHEDULER,
            ) );
            return null;
        }

        $context = array(
            'secret_id' => MRM_SECRET_GOOGLE_SCHEDULER,
            'raw_keys_present' => array_keys( $secret ),
            'has_service_account_json' => ! empty( $normalized['service_account_json'] ),
            'has_sync_secret' => ! empty( $normalized['sync_secret'] ),
            'has_maps_distance_api_key' => ! empty( $normalized['maps_distance_api_key'] ),
        );

        if ( ! empty( $normalized['service_account_json'] ) ) {
            $context['service_account_json_length'] = strlen( $normalized['service_account_json'] );
            $context['service_account_json_preview'] = substr( $normalized['service_account_json'], 0, 120 );
        }

        if ( ! empty( $normalized['sync_secret'] ) ) {
            $context['sync_secret_length'] = strlen( $normalized['sync_secret'] );
        }

        $this->mrm_aws_debug_log( 'MRM Google AWS secret bundle loaded and normalized.', $context );

        return $normalized;
    }

    protected function mrm_google_service_account_uses_aws() {
        $secret = $this->mrm_get_google_scheduler_secret_bundle();

        if ( ! is_array( $secret ) || ! array_key_exists( 'service_account_json', $secret ) ) {
            return false;
        }

        if ( is_string( $secret['service_account_json'] ) && trim( $secret['service_account_json'] ) !== '' ) {
            return true;
        }

        if ( is_array( $secret['service_account_json'] ) && ! empty( $secret['service_account_json'] ) ) {
            return true;
        }

        return false;
    }

protected function mrm_get_google_service_account_json() {
    $secret = $this->mrm_get_google_scheduler_secret_bundle();

    if ( is_array( $secret ) && array_key_exists( 'service_account_json', $secret ) ) {
        if ( is_string( $secret['service_account_json'] ) && trim( $secret['service_account_json'] ) !== '' ) {
            $this->mrm_aws_debug_log( 'Scheduler using AWS service_account_json as string', array(
                'length' => strlen( $secret['service_account_json'] ),
                'preview' => substr( $secret['service_account_json'], 0, 120 ),
            ) );
            return (string) $secret['service_account_json'];
        }

        if ( is_array( $secret['service_account_json'] ) && ! empty( $secret['service_account_json'] ) ) {
            $encoded = wp_json_encode( $secret['service_account_json'] );
            if ( is_string( $encoded ) && $encoded !== '' ) {
                $this->mrm_aws_debug_log( 'Scheduler using AWS service_account_json after array re-encode', array(
                    'length' => strlen( $encoded ),
                    'keys' => array_keys( $secret['service_account_json'] ),
                ) );
                return $encoded;
            }
        }

        $this->mrm_aws_debug_log( 'Scheduler found AWS service_account_json but it was unusable', array(
            'type' => gettype( $secret['service_account_json'] ),
        ) );
        return '';
    }

    $this->mrm_aws_debug_log( 'Scheduler missing AWS service_account_json. AWS-only mode active.' );
    return '';
}

    protected function mrm_get_google_sync_secret() {
        $secret = $this->mrm_get_google_scheduler_secret_bundle();
        if ( is_array( $secret ) && ! empty( $secret['sync_secret'] ) ) {
            return (string) $secret['sync_secret'];
        }

        $this->mrm_aws_debug_log( 'Scheduler missing AWS sync_secret. AWS-only mode active.' );
        return '';
    }

    protected function log_google_api_failure( $label, $url, $res ) {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        if ( is_wp_error( $res ) ) {
            return;
        }

        $code = (int) wp_remote_retrieve_response_code( $res );
        $body = (string) wp_remote_retrieve_body( $res );

        $decoded = json_decode( $body, true );
        $message = '';
        $status  = '';
        $reason  = '';

        if ( is_array( $decoded ) && ! empty( $decoded['error'] ) ) {
            if ( is_array( $decoded['error'] ) ) {
                $message = (string) ( $decoded['error']['message'] ?? '' );
                $status  = (string) ( $decoded['error']['status'] ?? '' );

                if ( ! empty( $decoded['error']['errors'] ) && is_array( $decoded['error']['errors'] ) ) {
                    $first_reason = $decoded['error']['errors'][0]['reason'] ?? '';
                    $reason = is_scalar( $first_reason ) ? (string) $first_reason : '';
                }
            } elseif ( is_scalar( $decoded['error'] ) ) {
                $message = (string) $decoded['error'];
            }
        }

    }

    protected function mrm_finalization_debug_log( $message, $context = array() ) {
        return;
    }


    protected function mrm_safety_log( $message, $context = array() ) {
        return;
    }

    protected function maybe_log_safety_boot( $message, $context = array(), $ttl = 900 ) {
        $cache_key = 'mrm_safety_boot_' . md5( (string) $message );

        if ( get_transient( $cache_key ) ) {
            return;
        }

        set_transient( $cache_key, '1', max( 60, (int) $ttl ) );
        $this->mrm_safety_log( $message, $context );
    }

    public function ensure_scheduler_runtime_cron_hooks() {
        $hooks = array(
            array(
                'hook'     => 'mrm_scheduler_sync_upcoming_events',
                'schedule' => 'mrm_1min',
                'offset'   => 60,
            ),
            array(
                'hook'     => 'mrm_scheduler_reconcile_completed_lessons',
                'schedule' => 'mrm_1min',
                'offset'   => 70,
            ),
            array(
                'hook'     => 'mrm_scheduler_reconcile_cancelled_lessons',
                'schedule' => 'mrm_1min',
                'offset'   => 80,
            ),
            array(
                'hook'     => 'mrm_scheduler_finalize_old_lessons',
                'schedule' => 'hourly',
                'offset'   => 300,
            ),
            array(
                'hook'     => 'mrm_scheduler_send_safety_reminders',
                'schedule' => 'mrm_5min',
                'offset'   => 90,
            ),
            array(
                'hook'     => 'mrm_scheduler_check_safety_exceptions',
                'schedule' => 'mrm_5min',
                'offset'   => 120,
            ),
            array(
                'hook'     => 'mrm_scheduler_send_feedback_requests',
                'schedule' => 'mrm_5min',
                'offset'   => 150,
            ),
        );

        foreach ( $hooks as $hook_config ) {
            $hook     = (string) $hook_config['hook'];
            $schedule = (string) $hook_config['schedule'];
            $offset   = (int) $hook_config['offset'];

            $next = wp_next_scheduled( $hook );

            if ( $next ) {
                $this->mrm_safety_log( 'cron_hook_already_scheduled', array(
                    'hook'               => $hook,
                    'schedule'           => $schedule,
                    'next_run_local'     => wp_date( 'Y-m-d H:i:s', $next, wp_timezone() ),
                    'next_run_utc'       => gmdate( 'Y-m-d H:i:s', $next ),
                    'next_run_timestamp' => (int) $next,
                ) );
                continue;
            }

            wp_schedule_event( time() + $offset, $schedule, $hook );

            $scheduled = wp_next_scheduled( $hook );

            $this->mrm_safety_log( 'scheduled_cron_hook', array(
                'hook'                    => $hook,
                'schedule'                => $schedule,
                'scheduled_for_local'     => $scheduled ? wp_date( 'Y-m-d H:i:s', $scheduled, wp_timezone() ) : '',
                'scheduled_for_utc'       => $scheduled ? gmdate( 'Y-m-d H:i:s', $scheduled ) : '',
                'scheduled_for_timestamp' => $scheduled ? (int) $scheduled : 0,
            ) );
        }
    }


    // Get a single Google Calendar event
    protected function google_get_event( $calendar_id, $event_id ) {
        $calendar_id = rawurldecode( trim( (string) $calendar_id ) );

        if ( ! $this->google_is_configured() ) {
            return new WP_Error( 'google_not_configured', 'Google Calendar is not configured.' );
        }

        $access_token = $this->google_get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }

        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( (string) $calendar_id ) .
               '/events/' . rawurlencode( (string) $event_id );

        $res = wp_remote_get( $url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ) );

        if ( is_wp_error( $res ) ) {
            $this->log_google_api_failure( 'google_get_event_wp_error', $url, $res );
            return $res;
        }

        $code = (int) wp_remote_retrieve_response_code( $res );
        $json = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
            $this->log_google_api_failure( 'google_get_event_failed', $url, $res );
            return new WP_Error( 'google_get_event_failed', 'Google Calendar event fetch failed.' );
        }

        return $json;
    }

    // List instances for a recurring Google Calendar event (master -> instances)
    protected function google_list_event_instances( $calendar_id, $event_id, $time_min_rfc3339, $time_max_rfc3339 ) {
        $calendar_id = rawurldecode( trim( (string) $calendar_id ) );
        $event_id    = trim( (string) $event_id );

        if ( ! $this->google_is_configured() ) {
            return new WP_Error( 'google_not_configured', 'Google Calendar is not configured.' );
        }

        if ( $calendar_id === '' ) {
            return new WP_Error( 'google_calendar_missing', 'Google Calendar ID is missing.' );
        }

        if ( $event_id === '' ) {
            return new WP_Error( 'google_event_missing', 'Google event ID is missing.' );
        }

        $range = $this->normalize_google_time_range( $time_min_rfc3339, $time_max_rfc3339 );
        if ( is_wp_error( $range ) ) {
            return $range;
        }

        $access_token = $this->google_get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }

        $base = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( (string) $calendar_id ) .
                '/events/' . rawurlencode( (string) $event_id ) . '/instances';

        $url = add_query_arg( array(
            'timeMin'     => $range['timeMin'],
            'timeMax'     => $range['timeMax'],
            'showDeleted' => 'false',
            'maxResults'  => 2500,
        ), $base );

        $res = wp_remote_get( $url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ) );

        if ( is_wp_error( $res ) ) {
            $this->log_google_api_failure( 'google_list_instances_wp_error', $url, $res );
            return $res;
        }

        $code = (int) wp_remote_retrieve_response_code( $res );
        $json = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
            $this->log_google_api_failure( 'google_list_instances_failed', $url, $res );
            return new WP_Error( 'google_list_instances_failed', 'Google Calendar events.instances failed.' );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        }

        return $json;
    }


    // List Google Calendar events for a window (Pattern B)
    protected function google_list_events( $calendar_id, $time_min_rfc3339, $time_max_rfc3339 ) {
        $calendar_id = rawurldecode( trim( (string) $calendar_id ) );

        if ( ! $this->google_is_configured() ) {
            return new WP_Error( 'google_not_configured', 'Google Calendar is not configured.' );
        }

        if ( $calendar_id === '' ) {
            return new WP_Error( 'google_calendar_missing', 'Google Calendar ID is missing.' );
        }

        $range = $this->normalize_google_time_range( $time_min_rfc3339, $time_max_rfc3339 );
        if ( is_wp_error( $range ) ) {
            return $range;
        }

        $access_token = $this->google_get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }

        $base = sprintf( self::GOOGLE_EVENTS_LIST_URL, rawurlencode( (string) $calendar_id ) );

        $url = add_query_arg( array(
            'timeMin'      => $range['timeMin'],
            'timeMax'      => $range['timeMax'],
            'singleEvents' => 'true',
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

        if ( is_wp_error( $res ) ) {
            $this->log_google_api_failure( 'google_list_events_wp_error', $url, $res );
            return $res;
        }

        $code = (int) wp_remote_retrieve_response_code( $res );
        $json = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
            $this->log_google_api_failure( 'google_list_events_failed', $url, $res );
            return new WP_Error( 'google_list_events_failed', 'Google Calendar events.list failed.' );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
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
        if ( ! $booking_id ) {
            return null;
        }

        if ( $calendar_id === '' ) {
            return new WP_Error( 'google_calendar_missing', 'Google Calendar ID is missing.' );
        }

        $range = $this->normalize_google_time_range( $timeMin, $timeMax );
        if ( is_wp_error( $range ) ) {
            return $range;
        }

        $access_token = $this->google_get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }

        $base = sprintf( self::GOOGLE_EVENTS_LIST_URL, rawurlencode( (string) $calendar_id ) );

        $url = add_query_arg( array(
            'timeMin'                 => $range['timeMin'],
            'timeMax'                 => $range['timeMax'],
            'singleEvents'            => 'true',
            'showDeleted'             => 'false',
            'maxResults'              => 2500,
            'orderBy'                 => 'startTime',
            'privateExtendedProperty' => 'booking_id=' . $booking_id,
        ), $base );

        $res = wp_remote_get( $url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ) );

        if ( is_wp_error( $res ) ) {
            $this->log_google_api_failure( 'google_find_event_by_booking_id_wp_error', $url, $res );
            return $res;
        }

        $code = (int) wp_remote_retrieve_response_code( $res );
        $json = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
            $this->log_google_api_failure( 'google_find_event_by_booking_id_failed', $url, $res );
            return new WP_Error( 'google_find_event_by_booking_id_failed', 'Google Calendar booking_id lookup failed.' );
        }

        $items = isset( $json['items'] ) && is_array( $json['items'] ) ? $json['items'] : array();
        if ( empty( $items ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            }
            return null;
        }

        foreach ( $items as $ev ) {
            $bid = 0;
            if ( isset( $ev['extendedProperties']['private']['booking_id'] ) ) {
                $bid = (int) $ev['extendedProperties']['private']['booking_id'];
            }
            if ( $bid === $booking_id ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                }
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


    protected function log_google_resolve_stage( $lesson_row, $stage, $extra = array() ) {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        $lesson_id  = (int) ( $lesson_row['id'] ?? 0 );
        $series_id  = (int) ( $lesson_row['series_id'] ?? 0 );
        $event_id   = (string) ( $lesson_row['google_event_id'] ?? '' );
        $instance_id = (string) ( $lesson_row['google_instance_event_id'] ?? '' );
        $anchor     = (string) ( $lesson_row['google_original_start_time'] ?? '' );
        $start_time = (string) ( $lesson_row['start_time'] ?? '' );
        $end_time   = (string) ( $lesson_row['end_time'] ?? '' );

        $parts = array(
            '[MRM] google_resolve_stage',
            'lesson_id=' . $lesson_id,
            'series_id=' . $series_id,
            'stage=' . (string) $stage,
            'google_event_id=' . $event_id,
            'google_instance_event_id=' . $instance_id,
            'anchor=' . $anchor,
            'start_time=' . $start_time,
            'end_time=' . $end_time,
        );

        if ( is_array( $extra ) ) {
            foreach ( $extra as $k => $v ) {
                if ( is_scalar( $v ) || $v === null ) {
                    $parts[] = sanitize_key( (string) $k ) . '=' . (string) $v;
                } else {
                    $parts[] = sanitize_key( (string) $k ) . '=' . wp_json_encode( $v );
                }
            }
        }

    }

    protected function google_event_original_start_to_mysql( $event ) {
        if ( ! is_array( $event ) ) {
            return '';
        }

        if ( ! empty( $event['originalStartTime']['dateTime'] ) ) {
            $ts = strtotime( (string) $event['originalStartTime']['dateTime'] );
            return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
        }

        if ( ! empty( $event['originalStartTime']['date'] ) ) {
            $ts = strtotime( (string) $event['originalStartTime']['date'] . ' 00:00:00 UTC' );
            return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
        }

        return '';
    }

    protected function google_event_is_recurring_master( $event ) {
        return (
            is_array( $event ) &&
            ! empty( $event['recurrence'] ) &&
            is_array( $event['recurrence'] )
        );
    }

    protected function google_list_events_with_private_property( $calendar_id, $timeMin, $timeMax, $property_clause ) {
        $calendar_id = rawurldecode( trim( (string) $calendar_id ) );

        if ( ! $this->google_is_configured() ) {
            return new WP_Error( 'google_not_configured', 'Google Calendar is not configured.' );
        }

        $property_clause = trim( (string) $property_clause );
        if ( $property_clause === '' ) {
            return new WP_Error( 'missing_property_clause', 'Missing private property clause.' );
        }

        if ( $calendar_id === '' ) {
            return new WP_Error( 'google_calendar_missing', 'Google Calendar ID is missing.' );
        }

        $range = $this->normalize_google_time_range( $timeMin, $timeMax );
        if ( is_wp_error( $range ) ) {
            return $range;
        }

        $access_token = $this->google_get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }

        $base = sprintf( self::GOOGLE_EVENTS_LIST_URL, rawurlencode( (string) $calendar_id ) );

        $url = add_query_arg( array(
            'timeMin'                 => $range['timeMin'],
            'timeMax'                 => $range['timeMax'],
            'singleEvents'            => 'true',
            'showDeleted'             => 'false',
            'maxResults'              => 2500,
            'orderBy'                 => 'startTime',
            'privateExtendedProperty' => $property_clause,
        ), $base );

        $res = wp_remote_get( $url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ) );

        if ( is_wp_error( $res ) ) {
            $this->log_google_api_failure( 'google_list_events_private_wp_error', $url, $res );
            return $res;
        }

        $code = (int) wp_remote_retrieve_response_code( $res );
        $json = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
            $this->log_google_api_failure( 'google_list_events_private_failed', $url, $res );
            return new WP_Error( 'google_list_events_private_filter_failed', 'Google Calendar filtered events.list failed.' );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        }

        return $json;
    }


    protected function google_find_event_by_series_id_and_anchor( $calendar_id, $series_id, $google_original_start_time, $local_start_time, $timeMin, $timeMax ) {
        $series_id = (int) $series_id;
        if ( $series_id <= 0 ) {
            return null;
        }

        $payload = $this->google_list_events_with_private_property(
            $calendar_id,
            $timeMin,
            $timeMax,
            'series_id=' . $series_id
        );

        if ( is_wp_error( $payload ) || ! is_array( $payload ) ) {
            return $payload;
        }

        $items = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
        if ( empty( $items ) ) {
            return null;
        }

        $wrapped = array( 'items' => $items );

        $match = $this->google_find_instance_by_original_start( $wrapped, $google_original_start_time );
        if ( ! is_array( $match ) ) {
            $match = $this->google_find_instance_by_local_start( $wrapped, $local_start_time );
        }
        if ( ! is_array( $match ) ) {
            $match = $this->google_find_instance_nearest_anchor(
                $wrapped,
                $google_original_start_time,
                $local_start_time,
                2 * DAY_IN_SECONDS
            );
        }

        return is_array( $match ) ? $match : null;
    }

    protected function google_find_event_by_anchor_scan( $calendar_id, $google_original_start_time, $local_start_time, $timeMin, $timeMax, $preferred_master_event_id = '' ) {
        $payload = $this->google_list_events( $calendar_id, $timeMin, $timeMax );
        if ( is_wp_error( $payload ) || ! is_array( $payload ) ) {
            return $payload;
        }

        $items = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
        if ( empty( $items ) ) {
            return null;
        }

        $filtered = array();
        foreach ( $items as $ev ) {
            if ( ! is_array( $ev ) ) {
                continue;
            }

            if ( ! empty( $preferred_master_event_id ) ) {
                $recurring_event_id = (string) ( $ev['recurringEventId'] ?? '' );
                if ( $recurring_event_id !== '' && $recurring_event_id !== (string) $preferred_master_event_id ) {
                    continue;
                }
            }

            if ( empty( $ev['originalStartTime'] ) && empty( $ev['start'] ) ) {
                continue;
            }

            $filtered[] = $ev;
        }

        if ( empty( $filtered ) ) {
            return null;
        }

        $wrapped = array( 'items' => $filtered );

        $match = $this->google_find_instance_by_original_start( $wrapped, $google_original_start_time );
        if ( ! is_array( $match ) ) {
            $match = $this->google_find_instance_by_local_start( $wrapped, $local_start_time );
        }
        if ( ! is_array( $match ) ) {
            $match = $this->google_find_instance_nearest_anchor(
                $wrapped,
                $google_original_start_time,
                $local_start_time,
                2 * DAY_IN_SECONDS
            );
        }

        return is_array( $match ) ? $match : null;
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

    protected function google_find_instance_nearest_anchor( $instances_payload, $anchor_mysql, $fallback_start_mysql = '', $max_diff_seconds = 172800 ) {
        if ( ! is_array( $instances_payload ) ) return null;

        $items = isset( $instances_payload['items'] ) && is_array( $instances_payload['items'] )
            ? $instances_payload['items']
            : array();

        if ( empty( $items ) ) return null;

        $anchor_ts = strtotime( (string) $anchor_mysql );
        if ( ! $anchor_ts ) {
            $anchor_ts = strtotime( (string) $fallback_start_mysql );
        }
        if ( ! $anchor_ts ) {
            return null;
        }

        $best = null;
        $best_diff = null;

        foreach ( $items as $ev ) {
            if ( strtolower( (string) ( $ev['status'] ?? 'confirmed' ) ) === 'cancelled' ) {
                continue;
            }

            list( $g_start_ts, $g_end_ts ) = $this->google_event_to_utc_ts( $ev );
            if ( ! $g_start_ts || ! $g_end_ts || $g_end_ts <= $g_start_ts ) {
                continue;
            }

            $diff = abs( $g_start_ts - $anchor_ts );

            if ( $best === null || $best_diff === null || $diff < $best_diff ) {
                $best = $ev;
                $best_diff = $diff;
            }
        }

        if ( $best !== null && $best_diff !== null && $best_diff <= (int) $max_diff_seconds ) {
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
        $instance_event_id = (string) ( $lesson_row['google_instance_event_id'] ?? '' );
        $series_id  = (int) ( $lesson_row['series_id'] ?? 0 );
        $booking_id = (int) ( $lesson_row['id'] ?? 0 );
        $start_time = (string) ( $lesson_row['start_time'] ?? '' );
        $end_time   = (string) ( $lesson_row['end_time'] ?? '' );
        $google_original_start_time = (string) ( $lesson_row['google_original_start_time'] ?? '' );

        if ( $google_original_start_time === '' ) {
            $google_original_start_time = $start_time;
        }

        $window_days = max( 30, (int) $instance_window_days );

                $make_window = function() use ( $google_original_start_time, $start_time, $end_time, $window_days ) {
            $anchor_ts = strtotime( (string) $google_original_start_time );
            $start_ts  = strtotime( (string) $start_time );
            $end_ts    = strtotime( (string) $end_time );

            if ( ! $anchor_ts ) $anchor_ts = $start_ts;
            if ( ! $start_ts )  $start_ts  = $anchor_ts;
            if ( ! $end_ts )    $end_ts    = $start_ts;

            $window_base_start = $anchor_ts ?: $start_ts ?: time();
            $window_base_end   = $end_ts ?: $window_base_start;

            $raw_min = gmdate(
                'Y-m-d H:i:s',
                min( $window_base_start, time() ) - ( $window_days * DAY_IN_SECONDS )
            );

            $raw_max = gmdate(
                'Y-m-d H:i:s',
                max( $window_base_end, time() ) + ( $window_days * DAY_IN_SECONDS )
            );

            $normalized = $this->normalize_google_time_range( $raw_min, $raw_max );

            if ( is_wp_error( $normalized ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                }

                return array(
                    'time_min' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $raw_min ) ),
                    'time_max' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $raw_max ) ),
                );
            }

            return array(
                'time_min' => $normalized['timeMin'],
                'time_max' => $normalized['timeMax'],
            );
        };

        $explicit_cancel = function( $ev ) {
            return is_array( $ev ) && strtolower( (string) ( $ev['status'] ?? 'confirmed' ) ) === 'cancelled';
        };

        $this->log_google_resolve_stage( $lesson_row, 'start', array(
            'calendar_id' => (string) $calendar_id,
            'booking_id'  => $booking_id,
            'window_days' => $window_days,
        ) );

        $resolve_from_master = function( $master_event_id, $stage_prefix = 'master' ) use (
            $lesson_row,
            $calendar_id,
            $start_time,
            $end_time,
            $google_original_start_time,
            $booking_id,
            $explicit_cancel,
            $instance_window_days,
            $make_window
        ) {
            $this->log_google_resolve_stage( $lesson_row, $stage_prefix . '_fetch_start', array(
                'master_event_id' => $master_event_id,
            ) );

            $got = $this->google_get_event( $calendar_id, $master_event_id );
            if ( is_wp_error( $got ) || ! is_array( $got ) ) {
                $this->log_google_resolve_stage( $lesson_row, $stage_prefix . '_fetch_failed', array(
                    'master_event_id' => $master_event_id,
                    'error' => is_wp_error( $got ) ? $got->get_error_message() : 'invalid_payload',
                ) );
                return array(
                    'status' => 'unresolved',
                    'event'  => null,
                    'reason' => 'master_fetch_failed',
                );
            }

            if ( $explicit_cancel( $got ) ) {
                $this->log_google_resolve_stage( $lesson_row, $stage_prefix . '_cancelled', array(
                    'master_event_id' => $master_event_id,
                ) );
                return array(
                    'status' => 'cancelled',
                    'event'  => $got,
                    'reason' => 'master_cancelled',
                );
            }

            $is_master = $this->google_event_is_recurring_master( $got );
            if ( ! $is_master ) {
                $this->log_google_resolve_stage( $lesson_row, $stage_prefix . '_direct_event_match', array(
                    'matched_event_id' => (string) ( $got['id'] ?? '' ),
                ) );
                return array(
                    'status' => 'resolved',
                    'event'  => $got,
                    'reason' => 'direct_event_match',
                );
            }

            $window = $make_window();
            $inst = $this->google_list_event_instances( $calendar_id, $master_event_id, $window['time_min'], $window['time_max'] );

            if ( is_wp_error( $inst ) || ! is_array( $inst ) ) {
                $this->log_google_resolve_stage( $lesson_row, $stage_prefix . '_instances_failed', array(
                    'master_event_id' => $master_event_id,
                    'time_min' => $window['time_min'],
                    'time_max' => $window['time_max'],
                    'error' => is_wp_error( $inst ) ? $inst->get_error_message() : 'invalid_payload',
                ) );
                return array(
                    'status' => 'unresolved',
                    'event'  => null,
                    'reason' => 'instance_list_failed',
                );
            }

            $items = isset( $inst['items'] ) && is_array( $inst['items'] ) ? $inst['items'] : array();

            $this->log_google_resolve_stage( $lesson_row, $stage_prefix . '_instances_loaded', array(
                'master_event_id' => $master_event_id,
                'count' => count( $items ),
                'time_min' => $window['time_min'],
                'time_max' => $window['time_max'],
            ) );

            $match = $this->google_find_instance_by_original_start( $inst, $google_original_start_time );
            if ( is_array( $match ) ) {
                $this->log_google_resolve_stage( $lesson_row, $stage_prefix . '_match_original_start', array(
                    'matched_event_id' => (string) ( $match['id'] ?? '' ),
                ) );
            }

            if ( ! is_array( $match ) ) {
                $match = $this->google_find_instance_by_local_start( $inst, $start_time );
                if ( is_array( $match ) ) {
                    $this->log_google_resolve_stage( $lesson_row, $stage_prefix . '_match_local_start', array(
                        'matched_event_id' => (string) ( $match['id'] ?? '' ),
                    ) );
                }
            }

            if ( ! is_array( $match ) ) {
                $match = $this->google_find_instance_by_booking_id( $inst, $booking_id );
                if ( is_array( $match ) ) {
                    $this->log_google_resolve_stage( $lesson_row, $stage_prefix . '_match_booking_id', array(
                        'matched_event_id' => (string) ( $match['id'] ?? '' ),
                    ) );
                }
            }

            if ( ! is_array( $match ) ) {
                $match = $this->google_find_instance_nearest_anchor(
                    $inst,
                    $google_original_start_time,
                    $start_time,
                    2 * DAY_IN_SECONDS
                );
                if ( is_array( $match ) ) {
                    $this->log_google_resolve_stage( $lesson_row, $stage_prefix . '_match_nearest_anchor', array(
                        'matched_event_id' => (string) ( $match['id'] ?? '' ),
                    ) );
                }
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

            $this->log_google_resolve_stage( $lesson_row, $stage_prefix . '_instances_no_match', array(
                'master_event_id' => $master_event_id,
            ) );

            return array(
                'status' => 'unresolved',
                'event'  => null,
                'reason' => 'instance_unresolved',
            );
        };

        if ( $instance_event_id !== '' ) {
            $this->log_google_resolve_stage( $lesson_row, 'direct_instance_fetch_start', array(
                'instance_event_id' => $instance_event_id,
            ) );

            $direct_instance = $this->google_get_event( $calendar_id, $instance_event_id );
            if ( ! is_wp_error( $direct_instance ) && is_array( $direct_instance ) ) {
                if ( $explicit_cancel( $direct_instance ) ) {
                    return array(
                        'status' => 'cancelled',
                        'event'  => $direct_instance,
                        'reason' => 'instance_event_cancelled',
                    );
                }

                $is_master = $this->google_event_is_recurring_master( $direct_instance );
                if ( ! $is_master ) {
                    $this->log_google_resolve_stage( $lesson_row, 'direct_instance_match', array(
                        'matched_event_id' => (string) ( $direct_instance['id'] ?? '' ),
                    ) );
                    return array(
                        'status' => 'resolved',
                        'event'  => $direct_instance,
                        'reason' => 'direct_instance_match',
                    );
                }

                $this->log_google_resolve_stage( $lesson_row, 'direct_instance_was_master', array(
                    'instance_event_id' => $instance_event_id,
                ) );
            } else {
                $this->log_google_resolve_stage( $lesson_row, 'direct_instance_fetch_failed', array(
                    'instance_event_id' => $instance_event_id,
                    'error' => is_wp_error( $direct_instance ) ? $direct_instance->get_error_message() : 'invalid_payload',
                ) );
            }
        }

        $series_master_event_id = '';
        if ( $series_id > 0 ) {
            $series_master_event_id = $this->get_series_master_google_event_id( $series_id );
            if ( $series_master_event_id !== '' ) {
                $series_result = $resolve_from_master( $series_master_event_id, 'series_master' );
                if ( $series_result['status'] !== 'unresolved' ) {
                    return $series_result;
                }
            }
        }

        if ( $event_id !== '' ) {
            $direct = $resolve_from_master( $event_id, 'stored_event_id' );
            if ( $direct['status'] !== 'unresolved' ) {
                return $direct;
            }
        }

        $window = $make_window();

        $this->log_google_resolve_stage( $lesson_row, 'anchor_scan_start', array(
            'time_min' => $window['time_min'],
            'time_max' => $window['time_max'],
            'preferred_master_event_id' => ( $series_master_event_id !== '' ? $series_master_event_id : $event_id ),
        ) );

        $anchor_scan = $this->google_find_event_by_anchor_scan(
            $calendar_id,
            $google_original_start_time,
            $start_time,
            $window['time_min'],
            $window['time_max'],
            ( $series_master_event_id !== '' ? $series_master_event_id : $event_id )
        );

        if ( is_wp_error( $anchor_scan ) ) {
            $this->log_google_resolve_stage( $lesson_row, 'anchor_scan_failed', array(
                'error' => $anchor_scan->get_error_message(),
            ) );
        } elseif ( is_array( $anchor_scan ) ) {
            $this->log_google_resolve_stage( $lesson_row, 'anchor_scan_match', array(
                'matched_event_id' => (string) ( $anchor_scan['id'] ?? '' ),
            ) );

            if ( $explicit_cancel( $anchor_scan ) ) {
                return array(
                    'status' => 'cancelled',
                    'event'  => $anchor_scan,
                    'reason' => 'anchor_scan_cancelled',
                );
            }

            return array(
                'status' => 'resolved',
                'event'  => $anchor_scan,
                'reason' => 'anchor_scan_match',
            );
        }

        if ( $series_id > 0 ) {
            $this->log_google_resolve_stage( $lesson_row, 'series_id_anchor_search_start', array(
                'series_id' => $series_id,
                'time_min' => $window['time_min'],
                'time_max' => $window['time_max'],
            ) );

            $series_anchor_match = $this->google_find_event_by_series_id_and_anchor(
                $calendar_id,
                $series_id,
                $google_original_start_time,
                $start_time,
                $window['time_min'],
                $window['time_max']
            );

            if ( is_wp_error( $series_anchor_match ) ) {
                $this->log_google_resolve_stage( $lesson_row, 'series_id_anchor_search_failed', array(
                    'error' => $series_anchor_match->get_error_message(),
                ) );
            } elseif ( is_array( $series_anchor_match ) ) {
                $this->log_google_resolve_stage( $lesson_row, 'series_id_anchor_match', array(
                    'matched_event_id' => (string) ( $series_anchor_match['id'] ?? '' ),
                ) );

                if ( $explicit_cancel( $series_anchor_match ) ) {
                    return array(
                        'status' => 'cancelled',
                        'event'  => $series_anchor_match,
                        'reason' => 'series_id_anchor_cancelled',
                    );
                }

                return array(
                    'status' => 'resolved',
                    'event'  => $series_anchor_match,
                    'reason' => 'series_id_anchor_match',
                );
            }
        }

        $this->log_google_resolve_stage( $lesson_row, 'booking_id_fallback_start', array(
            'booking_id' => $booking_id,
            'time_min' => $window['time_min'],
            'time_max' => $window['time_max'],
        ) );

        $found = $this->google_find_event_by_booking_id( $calendar_id, $booking_id, $window['time_min'], $window['time_max'] );
        if ( is_wp_error( $found ) ) {
            $this->log_google_resolve_stage( $lesson_row, 'booking_id_fallback_failed', array(
                'error' => $found->get_error_message(),
            ) );
        } elseif ( is_array( $found ) ) {
            $this->log_google_resolve_stage( $lesson_row, 'booking_id_match', array(
                'matched_event_id' => (string) ( $found['id'] ?? '' ),
            ) );

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

        $this->log_google_resolve_stage( $lesson_row, 'final_no_match', array(
            'booking_id' => $booking_id,
        ) );

        return array(
            'status' => 'unresolved',
            'event'  => null,
            'reason' => 'no_google_match',
        );
    }

    public function sync_lesson_row_from_google_truth( $lesson_row, $calendar_id, $instance_window_days = 120 ) {
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

        if ( (string) ( $lesson_row['status'] ?? '' ) === 'finalized' ) {
            $this->mrm_finalization_debug_log( 'google_sync_skipped_finalized_lesson', array(
                'lesson_id' => (int) ( $lesson_row['id'] ?? 0 ),
                'status'    => (string) ( $lesson_row['status'] ?? '' ),
            ) );

            return array(
                'status' => 'finalized_skipped',
                'lesson_row' => $lesson_row,
            );
        }

        $calendar_id = trim( (string) $calendar_id );
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

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
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
        if ( ! $g_start_ts || ! $g_end_ts || $g_end_ts <= $g_start_ts ) {
            return array(
                'status' => 'unresolved',
                'event'  => null,
                'reason' => 'google_event_missing_times',
            );
        }

        $new_start = gmdate( 'Y-m-d H:i:s', $g_start_ts );
        $new_end   = gmdate( 'Y-m-d H:i:s', $g_end_ts );

        $resolved_event_id = ! empty( $resolved_event['id'] ) ? (string) $resolved_event['id'] : '';
        $resolved_recurring_parent_id = ! empty( $resolved_event['recurringEventId'] )
            ? (string) $resolved_event['recurringEventId']
            : '';

        $original_anchor = ! empty( $lesson_row['google_original_start_time'] )
            ? (string) $lesson_row['google_original_start_time']
            : (string) ( $lesson_row['start_time'] ?? $new_start );

        $series_id = (int) ( $lesson_row['series_id'] ?? 0 );
        $series_master_event_id = $this->get_series_master_google_event_id( $series_id );

        $stored_event_id = ( $series_master_event_id !== '' )
            ? $series_master_event_id
            : ( $resolved_recurring_parent_id !== '' ? $resolved_recurring_parent_id : $resolved_event_id );

        $stored_instance_event_id = $resolved_event_id;

        $current_start = (string) ( $lesson_row['start_time'] ?? '' );
        $current_end   = (string) ( $lesson_row['end_time'] ?? '' );
        $current_google_event_id = (string) ( $lesson_row['google_event_id'] ?? '' );
        $current_google_instance_event_id = (string) ( $lesson_row['google_instance_event_id'] ?? '' );
        $current_anchor = (string) ( $lesson_row['google_original_start_time'] ?? '' );

        $needs_update =
            $current_start !== $new_start ||
            $current_end !== $new_end ||
            $current_google_event_id !== $stored_event_id ||
            $current_google_instance_event_id !== $stored_instance_event_id ||
            $current_anchor !== $original_anchor;

        $table_lessons = $wpdb->prefix . 'mrm_lessons';

        if ( $needs_update ) {
            $update_result = $wpdb->update(
                $table_lessons,
                array(
                    'start_time'                 => $new_start,
                    'end_time'                   => $new_end,
                    'google_original_start_time' => $original_anchor,
                    'google_event_id'            => $stored_event_id,
                    'google_instance_event_id'   => $stored_instance_event_id,
                    'updated_at'                 => current_time( 'mysql' ),
                ),
                array( 'id' => $lesson_id ),
                array( '%s', '%s', '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );

            if ( $update_result === false ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                }

                return array(
                    'status' => 'unresolved',
                    'event'  => null,
                    'reason' => 'db_update_failed',
                );
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            }
        } else {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            }
        }

        $fresh_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_lessons} WHERE id = %d LIMIT 1",
                $lesson_id
            ),
            ARRAY_A
        );

        if ( is_array( $fresh_row ) && ! empty( $fresh_row ) ) {
            $lesson_row = $fresh_row;
        } else {
            $lesson_row['start_time'] = $new_start;
            $lesson_row['end_time'] = $new_end;
            $lesson_row['google_event_id'] = $stored_event_id;
            $lesson_row['google_instance_event_id'] = $stored_instance_event_id;
            $lesson_row['google_original_start_time'] = $original_anchor;
        }

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

    protected function get_live_google_timing_for_lesson( $lesson_row, $calendar_id ) {
        if ( ! is_array( $lesson_row ) ) {
            return array(
                'status'   => 'unresolved',
                'reason'   => 'lesson_row_invalid',
                'source'   => 'db_fallback',
                'start_ts' => 0,
                'end_ts'   => 0,
                'lesson'   => $lesson_row,
                'sync'     => null,
            );
        }

        $calendar_id = trim( (string) $calendar_id );
        if ( $calendar_id === '' || ! $this->google_is_configured() ) {
            return array(
                'status'   => 'unresolved',
                'reason'   => 'missing_calendar_or_google_not_configured',
                'source'   => 'db_fallback',
                'start_ts' => 0,
                'end_ts'   => 0,
                'lesson'   => $lesson_row,
                'sync'     => null,
            );
        }

        $sync = $this->sync_lesson_row_from_google_truth( $lesson_row, $calendar_id, 120 );
        $sync_status = (string) ( $sync['status'] ?? 'unresolved' );

        if ( $sync_status === 'resolved' ) {
            $synced_lesson = isset( $sync['lesson_row'] ) && is_array( $sync['lesson_row'] )
                ? $sync['lesson_row']
                : $lesson_row;

            $start_ts = strtotime( (string) ( $sync['start_utc'] ?? '' ) );
            $end_ts   = strtotime( (string) ( $sync['end_utc'] ?? '' ) );

            if ( $start_ts && $end_ts && $end_ts > $start_ts ) {
                return array(
                    'status'   => 'resolved',
                    'reason'   => (string) ( $sync['reason'] ?? 'resolved' ),
                    'source'   => 'google',
                    'start_ts' => $start_ts,
                    'end_ts'   => $end_ts,
                    'lesson'   => $synced_lesson,
                    'sync'     => $sync,
                );
            }
        }

        return array(
            'status'   => 'unresolved',
            'reason'   => (string) ( $sync['reason'] ?? 'unresolved' ),
            'source'   => 'db_fallback',
            'start_ts' => 0,
            'end_ts'   => 0,
            'lesson'   => $lesson_row,
            'sync'     => $sync,
        );
    }


    protected function table_attendance() {
        global $wpdb;
        return $wpdb->prefix . 'mrm_lesson_attendance';
    }



    public static function get_instance() {
        if ( empty( self::$instance ) ) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        delete_transient( 'mrm_secret_google_scheduler_v5' );
        delete_transient( 'mrm_secret_google_scheduler_maps_distance_v1' );
        delete_transient( 'mrm_secret_google_scheduler_maps_distance_v2' );

        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_schema_notice' ) );

        add_action( 'admin_post_mrm_scheduler_run_upgrade', array( $this, 'handle_run_upgrade' ) );
        add_action( 'admin_post_mrm_scheduler_save_google', array( $this, 'handle_save_google_settings' ) );
        add_action( 'admin_post_mrm_scheduler_test_google', array( $this, 'handle_test_google_settings' ) );
        add_action( 'admin_post_mrm_scheduler_google_sync_now', array( $this, 'handle_google_sync_now_request' ) );
        add_action( 'admin_post_mrm_finalize_old_lessons_now', array( $this, 'admin_finalize_old_lessons_now' ) );
        add_action( 'admin_post_nopriv_mrm_scheduler_google_sync_now', array( $this, 'handle_google_sync_now_request' ) );

        add_action( 'admin_post_mrm_safety_attendance_action', array( $this, 'handle_safety_attendance_action' ) );
        add_action( 'admin_post_nopriv_mrm_safety_attendance_action', array( $this, 'handle_safety_attendance_action' ) );
        add_action( 'admin_post_mrm_safety_feedback_submit', array( $this, 'handle_safety_feedback_submit' ) );
        add_action( 'admin_post_nopriv_mrm_safety_feedback_submit', array( $this, 'handle_safety_feedback_submit' ) );
        add_action( 'admin_post_mrm_safety_emergency_submit', array( $this, 'handle_safety_emergency_submit' ) );
        add_action( 'admin_post_nopriv_mrm_safety_emergency_submit', array( $this, 'handle_safety_emergency_submit' ) );

        add_action( 'admin_post_mrm_run_safety_reminder_sweep_now', array( $this, 'admin_run_safety_reminder_sweep_now' ) );
        add_action( 'admin_post_mrm_run_safety_exception_check_now', array( $this, 'admin_run_safety_exception_check_now' ) );
        add_action( 'admin_post_mrm_run_safety_feedback_request_now', array( $this, 'admin_run_safety_feedback_request_now' ) );

        add_action( 'admin_post_mrm_export_1099_support', array( $this, 'handle_mrm_export_1099_support' ) );
        add_action( 'admin_post_mrm_export_mileage_summary', array( $this, 'handle_mrm_export_mileage_summary' ) );
        add_action( 'admin_post_mrm_export_calculations_summary', array( $this, 'handle_mrm_export_calculations_summary' ) );
        add_action( 'admin_post_mrm_recalculate_mileage_cache', array( $this, 'handle_mrm_recalculate_mileage_cache' ) );
        add_action( 'admin_post_mrm_clear_mileage_cache_for_period', array( $this, 'handle_mrm_clear_mileage_cache_for_period' ) );
        add_action( 'admin_post_mrm_clear_all_mileage_cache', array( $this, 'handle_mrm_clear_all_mileage_cache' ) );

        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // add_action( 'mrm_scheduler_send_lesson_reminder', array( $this, 'cron_send_lesson_reminder' ), 10, 1 );

        add_filter( 'cron_schedules', array( $this, 'register_custom_cron_schedules' ) );
        add_action( 'init', array( $this, 'ensure_scheduler_runtime_cron_hooks' ), 20 );

        add_action( 'mrm_scheduler_sync_upcoming_events', array( $this, 'cron_sync_upcoming_events' ) );
        add_action( 'mrm_scheduler_reconcile_completed_lessons', array( $this, 'cron_reconcile_completed_lessons' ) );
        add_action( 'mrm_scheduler_reconcile_cancelled_lessons', array( $this, 'cron_reconcile_cancelled_lessons' ) );
        add_action( 'mrm_scheduler_finalize_old_lessons', array( $this, 'cron_finalize_old_lessons' ) );
        add_action( 'mrm_scheduler_send_safety_reminders', array( $this, 'cron_send_safety_reminders' ) );
        add_action( 'mrm_scheduler_check_safety_exceptions', array( $this, 'cron_check_safety_exceptions' ) );
        add_action( 'mrm_scheduler_send_feedback_requests', array( $this, 'cron_send_feedback_requests' ) );

        $this->maybe_log_safety_boot( 'safety_system_constructor_loaded', array(
            'file' => __FILE__,
        ) );

        add_action( 'init', array( $this, 'register_join_gate_rewrite' ) );
        add_filter( 'query_vars', array( $this, 'register_join_gate_query_vars' ) );
        add_action( 'parse_request', array( $this, 'maybe_force_join_gate_virtual_request' ), 0 );
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

        // Reschedule cron jobs on activation to remove outdated intervals.
        $inst->maybe_reschedule_cron_jobs();
    }

    protected function maybe_reschedule_cron_jobs() {
        $this->mrm_safety_log( 'maybe_reschedule_cron_jobs_entered', array() );

        $hooks = array(
            'mrm_scheduler_sync_upcoming_events',
            'mrm_scheduler_reconcile_completed_lessons',
            'mrm_scheduler_reconcile_cancelled_lessons',
            'mrm_scheduler_finalize_old_lessons',
            'mrm_scheduler_send_safety_reminders',
            'mrm_scheduler_check_safety_exceptions',
            'mrm_scheduler_send_feedback_requests',
        );

        foreach ( $hooks as $hook ) {
            $next_before = wp_next_scheduled( $hook );

            $this->mrm_safety_log( 'cron_hook_before_clear', array(
                'hook'               => $hook,
                'next_run_local'     => $next_before ? wp_date( 'Y-m-d H:i:s', $next_before, wp_timezone() ) : '',
                'next_run_utc'       => $next_before ? gmdate( 'Y-m-d H:i:s', $next_before ) : '',
                'next_run_timestamp' => $next_before ? (int) $next_before : 0,
            ) );

            wp_clear_scheduled_hook( $hook );

            $this->mrm_safety_log( 'cleared_cron_hook_before_rebuild', array(
                'hook' => $hook,
            ) );
        }

        $this->ensure_scheduler_runtime_cron_hooks();

        $this->mrm_safety_log( 'maybe_reschedule_cron_jobs_finished', array() );
    }


    public static function deactivate() {
        wp_clear_scheduled_hook( 'mrm_scheduler_sync_upcoming_events' );
        wp_clear_scheduled_hook( 'mrm_scheduler_reconcile_completed_lessons' );
        wp_clear_scheduled_hook( 'mrm_scheduler_reconcile_cancelled_lessons' );
        wp_clear_scheduled_hook( 'mrm_scheduler_finalize_old_lessons' );
        wp_clear_scheduled_hook( 'mrm_scheduler_send_safety_reminders' );
        wp_clear_scheduled_hook( 'mrm_scheduler_check_safety_exceptions' );
        wp_clear_scheduled_hook( 'mrm_scheduler_send_feedback_requests' );
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
    address VARCHAR(255) NOT NULL DEFAULT '',
    zip_code VARCHAR(20) NOT NULL DEFAULT '',
    offers_in_person TINYINT(1) NOT NULL DEFAULT 0,
    offers_online TINYINT(1) NOT NULL DEFAULT 0,
    profile_image_url TEXT NULL,
    short_description TEXT NULL,
    long_description LONGTEXT NULL,
    instruments TEXT NULL,
    latitude DECIMAL(10,6) DEFAULT NULL,
    longitude DECIMAL(10,6) DEFAULT NULL,
    calendar_id VARCHAR(255) NOT NULL DEFAULT '',
    timezone VARCHAR(64) NOT NULL DEFAULT 'America/Phoenix',
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
    parent_timezone VARCHAR(64) NOT NULL DEFAULT 'America/Phoenix',
    address VARCHAR(255) NOT NULL DEFAULT '',
    address_city VARCHAR(100) NOT NULL DEFAULT '',
    address_state VARCHAR(50) NOT NULL DEFAULT '',
    address_postal VARCHAR(20) NOT NULL DEFAULT '',
    instrument VARCHAR(100) NOT NULL,
    is_online TINYINT(1) NOT NULL DEFAULT 0,
    is_consultation TINYINT(1) NOT NULL DEFAULT 0,
    instructor_timezone VARCHAR(64) NOT NULL DEFAULT 'America/Phoenix',
    lesson_length INT NOT NULL DEFAULT 60,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    google_original_start_time DATETIME NULL,
    delivered_at DATETIME NULL,
    finalized_at DATETIME NULL,
    charge_due_at DATETIME NULL,
    charge_status VARCHAR(30) NOT NULL DEFAULT 'none',
    charge_attempts INT NOT NULL DEFAULT 0,
    charge_last_attempt_at DATETIME NULL,
    charge_last_error LONGTEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'scheduled',
    google_event_id VARCHAR(255) NULL,
    google_instance_event_id VARCHAR(255) NULL,
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
    KEY finalized_at_idx (finalized_at),
    KEY charge_status_idx (charge_status),
    KEY charge_due_at_idx (charge_due_at),
    KEY reminder_token_hash_idx (reminder_token_hash),
    KEY reminder_sent_at_idx (reminder_sent_at)
) {$charset_collate};";
        $table_agreements = $wpdb->prefix . 'mrm_agreements';
        $table_attendance = $wpdb->prefix . 'mrm_lesson_attendance';
        $sql_attendance = "CREATE TABLE {$table_attendance} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lesson_id BIGINT UNSIGNED NOT NULL,
    parent_reminder_sent_at DATETIME NULL,
    instructor_reminder_sent_at DATETIME NULL,
    parent_feedback_request_sent_at DATETIME NULL,
    instructor_departure_email_sent_at DATETIME NULL,
    instructor_arrived_at DATETIME NULL,
    instructor_arrived_ip VARCHAR(45) NULL,
    parent_confirmed_arrival_at DATETIME NULL,
    parent_confirmed_arrival_ip VARCHAR(45) NULL,
    parent_no_show_reported_at DATETIME NULL,
    parent_no_show_reported_ip VARCHAR(45) NULL,
    parent_no_show_reason LONGTEXT NULL,
    no_show_admin_notified_at DATETIME NULL,
    instructor_departed_at DATETIME NULL,
    instructor_departed_ip VARCHAR(45) NULL,
    instructor_emergency_reported_at DATETIME NULL,
    instructor_emergency_reported_ip VARCHAR(45) NULL,
    instructor_emergency_message LONGTEXT NULL,
    instructor_emergency_notified_at DATETIME NULL,
    parent_rating TINYINT UNSIGNED NULL,
    parent_comment LONGTEXT NULL,
    feedback_submitted_at DATETIME NULL,
    arrival_alert_sent_at DATETIME NULL,
    departure_alert_sent_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY lesson_id_unique (lesson_id),
    KEY parent_reminder_sent_idx (parent_reminder_sent_at),
    KEY instructor_reminder_sent_idx (instructor_reminder_sent_at),
    KEY instructor_departure_email_sent_idx (instructor_departure_email_sent_at),
    KEY instructor_arrived_idx (instructor_arrived_at),
    KEY parent_no_show_reported_idx (parent_no_show_reported_at),
    KEY no_show_admin_notified_idx (no_show_admin_notified_at),
    KEY instructor_departed_idx (instructor_departed_at),
    KEY instructor_emergency_reported_idx (instructor_emergency_reported_at),
    KEY instructor_emergency_notified_idx (instructor_emergency_notified_at),
    KEY feedback_submitted_idx (feedback_submitted_at),
    KEY arrival_alert_sent_idx (arrival_alert_sent_at),
    KEY departure_alert_sent_idx (departure_alert_sent_at)
) {$charset_collate};";
        $sql3 = "CREATE TABLE {$table_agreements} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    agreement_version VARCHAR(50) NOT NULL,
    agreement_scope VARCHAR(80) NOT NULL DEFAULT 'terms_of_service',
    source_flow VARCHAR(80) NOT NULL DEFAULT '',
    related_lesson_id BIGINT UNSIGNED NULL,
    related_order_id BIGINT UNSIGNED NULL,
    related_sku VARCHAR(190) NULL,
    signature TEXT NOT NULL,
    acknowledgement_json LONGTEXT NULL,
    signed_at DATETIME NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    PRIMARY KEY (id),
    KEY email_idx (email),
    KEY agreement_version_idx (agreement_version),
    KEY source_flow_idx (source_flow),
    KEY related_lesson_idx (related_lesson_id),
    KEY related_order_idx (related_order_id),
    KEY related_sku_idx (related_sku)
) {$charset_collate};";
        dbDelta( $sql1 );
        dbDelta( $sql2 );
        // Ensure lesson address columns exist for Calculations mileage support.
        $lesson_required_columns = array(
            'address' => "ALTER TABLE {$table_lessons} ADD COLUMN address varchar(255) NOT NULL DEFAULT ''",
            'address_city' => "ALTER TABLE {$table_lessons} ADD COLUMN address_city varchar(100) NOT NULL DEFAULT ''",
            'address_state' => "ALTER TABLE {$table_lessons} ADD COLUMN address_state varchar(64) NOT NULL DEFAULT ''",
            'address_postal' => "ALTER TABLE {$table_lessons} ADD COLUMN address_postal varchar(32) NOT NULL DEFAULT ''",
        );

        foreach ( $lesson_required_columns as $col_name => $alter_sql ) {
            $column_exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table_lessons} LIKE %s", $col_name ) );
            if ( empty( $column_exists ) ) {
                $wpdb->query( $alter_sql );
            }
        }

        // Ensure mileage cache has error-message support for Google Distance Matrix diagnostics.
        $mileage_table = $wpdb->prefix . 'mrm_tax_mileage_cache';
        $found_mileage_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mileage_table ) );
        if ( $found_mileage_table === $mileage_table ) {
            $existing_mileage_cols = array();
            $cols = $wpdb->get_results( "SHOW COLUMNS FROM {$mileage_table}" );

            if ( is_array( $cols ) ) {
                foreach ( $cols as $c ) {
                    if ( ! empty( $c->Field ) ) {
                        $existing_mileage_cols[] = (string) $c->Field;
                    }
                }
            }

            if ( ! in_array( 'calc_error_message', $existing_mileage_cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$mileage_table} ADD COLUMN calc_error_message TEXT NULL AFTER calc_source" );
            }
        }

        dbDelta( $sql3 );
        dbDelta( $sql_attendance );

        $tax_expenses_table = $wpdb->prefix . 'mrm_tax_manual_expenses';
        $sql_tax_expenses = "CREATE TABLE {$tax_expenses_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    expense_date DATE NOT NULL,
    tax_year INT NOT NULL,
    tax_quarter TINYINT NOT NULL DEFAULT 0,
    environment_mode VARCHAR(10) NOT NULL DEFAULT 'live',
    category VARCHAR(100) NOT NULL DEFAULT '',
    vendor_name VARCHAR(190) NOT NULL DEFAULT '',
    description TEXT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(50) NOT NULL DEFAULT '',
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY tax_year (tax_year),
    KEY tax_quarter (tax_quarter),
    KEY category (category)
) {$charset_collate};";
        dbDelta( $sql_tax_expenses );

        $mileage_table = $wpdb->prefix . 'mrm_tax_mileage_cache';
        $sql_mileage = "CREATE TABLE {$mileage_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lesson_id BIGINT UNSIGNED NOT NULL,
    instructor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tax_year INT NOT NULL,
    environment_mode VARCHAR(10) NOT NULL DEFAULT 'live',
    trip_date DATE NOT NULL,
    origin_address VARCHAR(255) NOT NULL DEFAULT '',
    destination_address VARCHAR(255) NOT NULL DEFAULT '',
    one_way_miles DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    round_trip_miles DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    mileage_rate DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    mileage_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    calc_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    calc_source VARCHAR(30) NOT NULL DEFAULT 'manual',
    calc_error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY lesson_id (lesson_id),
    KEY instructor_id (instructor_id),
    KEY tax_year (tax_year)
) {$charset_collate};";
        dbDelta( $sql_mileage );

        $payee_table = $wpdb->prefix . 'mrm_tax_payee_profiles';
        $sql_payee = "CREATE TABLE {$payee_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payee_type VARCHAR(30) NOT NULL DEFAULT 'contractor',
    related_instructor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    related_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    display_name VARCHAR(190) NOT NULL DEFAULT '',
    legal_name VARCHAR(190) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL DEFAULT '',
    tin_last4 VARCHAR(10) NOT NULL DEFAULT '',
    w9_received TINYINT(1) NOT NULL DEFAULT 0,
    w9_received_date DATE NULL,
    is_1099_eligible TINYINT(1) NOT NULL DEFAULT 1,
    is_employee TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY payee_type (payee_type),
    KEY related_instructor_id (related_instructor_id),
    KEY is_employee (is_employee)
) {$charset_collate};";
        dbDelta( $sql_payee );

        $calc_cache_table = $wpdb->prefix . 'mrm_tax_calculation_cache';
        $sql_calc_cache = "CREATE TABLE {$calc_cache_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tax_year INT NOT NULL,
    tax_quarter TINYINT NOT NULL DEFAULT 0,
    environment_mode VARCHAR(10) NOT NULL DEFAULT 'live',
    calc_key VARCHAR(100) NOT NULL,
    calc_payload LONGTEXT NULL,
    generated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY year_quarter_key (tax_year, tax_quarter, calc_key)
) {$charset_collate};";
        dbDelta( $sql_calc_cache );

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

        $instructors = $wpdb->prefix . 'mrm_instructors';
        $exists_instructors = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $instructors ) ) === $instructors );
        if ( ! $exists_instructors ) {
            return array( 'ok' => false, 'reason' => 'missing_table', 'table' => 'mrm_instructors' );
        }

        $instructor_cols = $wpdb->get_col( "DESC {$instructors}", 0 );
        $need_instructors = array(
            'timezone', 'stripe_connected_account_id', 'hire_date', 'calendar_id', 'profile_image_url', 'short_description', 'long_description', 'instruments', 'state', 'address', 'zip_code', 'offers_in_person', 'offers_online',
        );

        foreach ( $need_instructors as $col ) {
            if ( ! in_array( $col, $instructor_cols, true ) ) {
                return array( 'ok' => false, 'reason' => 'missing_column', 'table' => 'mrm_instructors', 'column' => $col );
            }
        }

        $lessons = $wpdb->prefix . 'mrm_lessons';
        $exists_lessons = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $lessons ) ) === $lessons );
        if ( ! $exists_lessons ) {
            return array( 'ok' => false, 'reason' => 'missing_table', 'table' => 'mrm_lessons' );
        }

        $lesson_cols = $wpdb->get_col( "DESC {$lessons}", 0 );
        $need_lessons = array(
            'google_original_start_time', 'delivered_at', 'finalized_at', 'charge_due_at', 'charge_status', 'charge_attempts', 'charge_last_attempt_at', 'charge_last_error', 'google_event_id', 'google_instance_event_id', 'google_meet_url', 'order_id', 'payment_mode', 'payout_unlocked_at', 'autopay_profile_id', 'agreement_id', 'reminder_token', 'reminder_token_hash', 'reminder_scheduled_at', 'reminder_sent_at',
        );

        foreach ( $need_lessons as $col ) {
            if ( ! in_array( $col, $lesson_cols, true ) ) {
                return array( 'ok' => false, 'reason' => 'missing_column', 'table' => 'mrm_lessons', 'column' => $col );
            }
        }

        $attendance = $wpdb->prefix . 'mrm_lesson_attendance';
        $exists_attendance = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $attendance ) ) === $attendance );
        if ( ! $exists_attendance ) {
            return array( 'ok' => false, 'reason' => 'missing_table', 'table' => 'mrm_lesson_attendance' );
        }

        $attendance_cols = $wpdb->get_col( "DESC {$attendance}", 0 );
        $need_attendance = array(
            'lesson_id', 'parent_reminder_sent_at', 'instructor_reminder_sent_at', 'parent_feedback_request_sent_at', 'instructor_arrived_at', 'instructor_arrived_ip', 'parent_confirmed_arrival_at', 'parent_confirmed_arrival_ip', 'instructor_departed_at', 'instructor_departed_ip', 'parent_rating', 'parent_comment', 'feedback_submitted_at', 'arrival_alert_sent_at', 'departure_alert_sent_at', 'created_at', 'updated_at',
        );

        foreach ( $need_attendance as $col ) {
            if ( ! in_array( $col, $attendance_cols, true ) ) {
                return array( 'ok' => false, 'reason' => 'missing_column', 'table' => 'mrm_lesson_attendance', 'column' => $col );
            }
        }

        $mileage_cache = $wpdb->prefix . 'mrm_tax_mileage_cache';
        $exists_mileage_cache = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $mileage_cache ) ) === $mileage_cache );
        if ( $exists_mileage_cache ) {
            $mileage_cols = $wpdb->get_col( "DESC {$mileage_cache}", 0 );
            if ( ! in_array( 'calc_error_message', $mileage_cols, true ) ) {
                return array( 'ok' => false, 'reason' => 'missing_column', 'table' => 'mrm_tax_mileage_cache', 'column' => 'calc_error_message' );
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

        // Indefinite recurring bookings should seed a rolling 90-day local horizon.
        if ( $repeat_duration === 'indefinitely' ) {
            $repeat_count = (int) floor( 90 / $interval_days ) + 1;
        }

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
        $address_city  = sanitize_text_field( (string) ( $data['address_city'] ?? '' ) );
        $address_state = sanitize_text_field( (string) ( $data['address_state'] ?? '' ) );
        $address_postal= sanitize_text_field( (string) ( $data['address_postal'] ?? '' ) );
        $parent_timezone = sanitize_text_field( (string) ( $data['parent_timezone'] ?? '' ) );
        if ( $parent_timezone === '' ) {
            $parent_timezone = 'America/Phoenix';
        }

        $lesson_type       = sanitize_text_field( (string) ( $data['lesson_type'] ?? '' ) );          // online | inperson | consultation (per your UI)
        $repeat_frequency  = sanitize_text_field( (string) ( $data['repeat_frequency'] ?? 'none' ) ); // weekly | biweekly | none
        $repeat_duration   = sanitize_text_field( (string) ( $data['repeat_duration'] ?? '' ) );      // 1_month | 3_months | indefinitely (per UI)
        $appointment_type  = sanitize_text_field( (string) ( $data['appointment_type'] ?? 'lesson' ) ); // lesson | consultation

        $appointment_type = strtolower( trim( $appointment_type ) );
        $lesson_type_normalized = strtolower( trim( $lesson_type ) );

        $is_consultation = 0;
        if ( $appointment_type === 'consultation' || $lesson_type_normalized === 'consultation' ) {
            $is_consultation = 1;
        }

        // Consultations are always online in this system.
        if ( $is_consultation ) {
            $is_online = 1;
            $appointment_type = 'consultation';
        } else {
            $appointment_type = 'lesson';
        }
        $order_id = isset( $data['order_id'] ) ? intval( $data['order_id'] ) : 0;
        $payment_mode = sanitize_text_field( (string ) ( $data['payment_mode'] ?? 'none' ) ); // prepay|one_time|autopay|none
        $autopay_profile_id = isset( $data['autopay_profile_id'] ) ? intval( $data['autopay_profile_id'] ) : 0;

        $agreement = isset( $data['agreement'] ) && is_array( $data['agreement'] ) ? $data['agreement'] : array();
        $terms_accepted = ! empty( $agreement['terms_accepted'] );

        if ( ! $terms_accepted ) {
            return new WP_REST_Response(
                array(
                    'ok'      => false,
                    'message' => 'Please agree to the Terms of Service before booking.',
                ),
                400
            );
        }

        $agreement_signature = trim( (string) ( $agreement['signature'] ?? '' ) );
        if ( $agreement_signature === '' ) {
            $agreement_signature = 'Terms accepted electronically by ' . $student_email;
        }

        $agreement_id = $this->maybe_store_agreement(
            $student_email,
            self::TERMS_VERSION,
            $agreement_signature,
            isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : '',
            array(
                'agreement_scope' => 'terms_of_service',
                'source_flow' => 'lesson_booking',
                'related_order_id' => $order_id > 0 ? $order_id : null,
                'acknowledgement' => $agreement,
            )
        );

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
                    'parent_timezone'     => $parent_timezone,
                    'address'             => $address,
                    'address_city'        => $address_city,
                    'address_state'       => $address_state,
                    'address_postal'      => $address_postal,
                    'instrument'       => $instrument !== '' ? $instrument : 'unknown',
                    'is_online'        => $is_online,
                    'is_consultation'  => $is_consultation,
                    'instructor_timezone' => $instr_tz,
                    'lesson_length'    => $lesson_length > 0 ? $lesson_length : 60,
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
                    'agreement_id'    => ( $agreement_id > 0 ? $agreement_id : null ),
                    'created_at'      => $now,
                    'updated_at'      => $now,
                    'reminder_token'  => null,
                    'reminder_token_hash' => null,
                    'reminder_scheduled_at' => null,
                    'reminder_sent_at' => null,
                ),
                array(
                    '%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%d','%s','%s','%s','%s','%s',
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
            $slot_payment_mode = ( $payment_mode !== '' ? $payment_mode : 'none' );
            $slot_charge_status = 'none';

            if ( $payment_mode === 'autopay' ) {
                if ( $slot_index > 0 ) {
                    $slot_order_id = 0;
                    $slot_payment_mode = 'autopay';
                    $slot_charge_status = 'none';
                } else {
                    // First lesson in an autopay series is already paid by the initial checkout.
                    $slot_payment_mode = 'one_time';
                    $slot_charge_status = ( $slot_order_id > 0 ? 'paid' : 'none' );
                }
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            }

            $ok = $wpdb->insert( $lessons_table, array(
                'instructor_id' => $instructor_id,
                'series_id'     => ( $series_id ? $series_id : null ),
                'student_name'  => $student_name !== '' ? $student_name : $student_email,
                'student_email'    => $student_email,
                'parent_timezone'     => $parent_timezone,
                'address'             => $address,
                'address_city'        => $address_city,
                'address_state'       => $address_state,
                'address_postal'      => $address_postal,
                'instrument'       => $instrument !== '' ? $instrument : 'unknown',
                'is_online'        => $is_online,
                'is_consultation'  => $is_consultation,
                'instructor_timezone' => $instr_tz,
                'lesson_length'    => $lesson_length > 0 ? $lesson_length : 60,
                'start_time'    => $start_mysql,
                'end_time'      => $end_mysql,
                'google_original_start_time' => $start_mysql,
                'status'        => 'scheduled',
                'charge_status' => $slot_charge_status,
                'google_event_id' => ( $series_master_google_event_id !== '' ? $series_master_google_event_id : null ),
                'google_meet_url' => null,
                'order_id'        => ( $slot_order_id > 0 ? $slot_order_id : null ),
                'payment_mode'    => $slot_payment_mode,
                'payout_unlocked_at' => null,
                'autopay_profile_id' => ( $autopay_profile_id > 0 ? $autopay_profile_id : null ),
                'agreement_id'    => ( $agreement_id > 0 ? $agreement_id : null ),
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
                '%s', // parent_timezone
                '%s', // address
                '%s', // address_city
                '%s', // address_state
                '%s', // address_postal
                '%s', // instrument
                '%d', // is_online
                '%d', // is_consultation
                '%s', // instructor_timezone
                '%d', // lesson_length
                '%s', // start_time
                '%s', // end_time
                '%s', // google_original_start_time
                '%s', // status
                '%s', // charge_status
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

                if ( (int) $is_consultation === 1 ) {
                    $this->send_consultation_confirmation_for_lesson( $booking_id );
                }

                $timeframe_label = '';
                if ( $is_recurring_booking ) {
                    $timeframe_label = $this->mrm_get_timeframe_label( $repeat_frequency, $repeat_duration );
                }

                $should_send_instructor_scheduled_email =
                    ( ! $is_recurring_booking ) || ( (int) $slot_index === 0 );

                if ( $should_send_instructor_scheduled_email ) {
                    $this->send_instructor_scheduled_notification_for_lesson(
                        $booking_id,
                        array(
                            'timeframe_label' => $timeframe_label,
                        )
                    );
                }

                if ( ! $is_online ) {
                    $this->mrm_queue_mileage_calculation_for_lesson( $booking_id );
                }

                // Call existing Google Calendar insert function (already defined in this plugin)
                // Only do this if Google is configured and the instructor has a calendar_id.
                if ( $calendar_id === '' ) {
                    $google_messages[] = 'Instructor calendar_id is blank, so no Google Calendar event could be created.';
                } elseif ( ! $this->google_is_configured() ) {
                    $google_messages[] = 'Google Calendar is not configured, so no calendar event could be created.';
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
                        $city_state_zip = trim( implode( ', ', array_filter( array(
                            trim( (string) $address_city ),
                            $state_zip,
                        ) ) ) );

                        if ( $address !== '' && $city_state_zip !== '' ) {
                            $location = trim( $address . ', ' . $city_state_zip );
                        } elseif ( $address !== '' ) {
                            $location = trim( $address );
                        } elseif ( $city_state_zip !== '' ) {
                            $location = $city_state_zip;
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
                    $description_lines[] = 'City: ' . (string) $address_city;
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
                        'address_city'        => (string) $address_city,
                        'address_state'       => (string) $address_state,
                        'address_postal'      => (string) $address_postal,
                        'lesson_type'         => (string) $lesson_type,
                        'repeat_frequency'    => (string) $repeat_frequency,
                        'repeat_duration'     => (string) $repeat_duration,
                        'appointment_type'    => (string) $appointment_type,
                        'is_consultation'     => (string) ( $is_consultation ? '1' : '0' ),
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
                                        'google_instance_event_id' => $created_event_id,
                                        'google_meet_url' => null,
                                        'updated_at'      => $now,
                                    ),
                                    array( 'id' => $booking_id ),
                                    array( '%s', '%s', '%s', '%s' ),
                                    array( '%d' )
                                );

                                $google_messages[] = 'Calendar event created successfully.';
                            }
                        } else {
                            $msg = is_wp_error( $ins ) ? $ins->get_error_message() : 'Unknown insert response.';
                            $google_messages[] = 'Calendar event was not created: ' . $msg;
                        }

                    } else {
                        $msg = is_wp_error( $start_rfc3339 ) ? $start_rfc3339->get_error_message() : $end_rfc3339->get_error_message();
                        $google_messages[] = 'Calendar event was not created because time conversion failed: ' . $msg;
                    }
                }
            } else {
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

    public function maybe_force_join_gate_virtual_request( $wp ) {
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
            return;
        }

        $request_path = (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );
        $request_path = trim( $request_path, '/' );

        if ( $request_path !== 'join-online' && $request_path !== 'join-video-lesson' ) {
            return;
        }

        if ( ! is_object( $wp ) || ! isset( $wp->query_vars ) || ! is_array( $wp->query_vars ) ) {
            return;
        }

        $wp->query_vars['mrm_join_video_lesson'] = '1';
        $wp->query_vars['pagename'] = 'join-online';
        $wp->request = 'join-online';

        unset( $wp->query_vars['name'], $wp->query_vars['page'], $wp->query_vars['error'] );

        if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof WP_Query ) {
            $GLOBALS['wp_query']->is_404 = false;
        }
    }

    protected function resolve_gate_lesson_by_shared_token( $token_hash ) {
        global $wpdb;

        $table_lessons = $wpdb->prefix . 'mrm_lessons';
        $table_instructors = $wpdb->prefix . 'mrm_instructors';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.*, i.calendar_id, i.timezone
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
            return array(
                'lesson' => null,
                'appointment_type' => '',
                'start_ts' => 0,
                'end_ts' => 0,
                'timing_source' => '',
                'timing_status' => 'unresolved',
                'timing_reason' => 'no_rows',
            );
        }

        $now = time();

        $current_match = null;

        $nearest_upcoming = null;
        $nearest_upcoming_diff = null;

        $nearest_recent = null;
        $nearest_recent_diff = null;

        foreach ( $rows as $candidate ) {
            if ( empty( $candidate['is_online'] ) ) {
                continue;
            }

            $effective_lesson = $candidate;
            $calendar_id = (string) ( $candidate['calendar_id'] ?? '' );

            $timing_source = 'db';
            $timing_status = 'unresolved';
            $timing_reason = '';
            $start_ts = 0;
            $end_ts = 0;

            if ( $calendar_id !== '' && $this->google_is_configured() ) {
                $gate_timing = $this->get_live_google_timing_for_lesson( $candidate, $calendar_id );
                $timing_status = (string) ( $gate_timing['status'] ?? 'unresolved' );
                $timing_reason = (string) ( $gate_timing['reason'] ?? '' );

                if ( $timing_status === 'resolved' ) {
                    $effective_lesson = isset( $gate_timing['lesson'] ) && is_array( $gate_timing['lesson'] )
                        ? $gate_timing['lesson']
                        : $candidate;

                    $start_ts = (int) ( $gate_timing['start_ts'] ?? 0 );
                    $end_ts   = (int) ( $gate_timing['end_ts'] ?? 0 );
                    $timing_source = 'google';
                }
            }

            if ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) {
                $start_ts = strtotime( (string) ( $effective_lesson['start_time'] ?? '' ) );
                $end_ts   = strtotime( (string) ( $effective_lesson['end_time'] ?? '' ) );

                if ( ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) && ! empty( $effective_lesson['google_original_start_time'] ) ) {
                    $fallback_start_ts = strtotime( (string) $effective_lesson['google_original_start_time'] );
                    if ( $fallback_start_ts ) {
                        $start_ts = $fallback_start_ts;
                        $end_ts = $fallback_start_ts + ( max( 1, (int) ( $effective_lesson['lesson_length'] ?? 60 ) ) * MINUTE_IN_SECONDS );
                    }
                }

                if ( $start_ts && $end_ts && $end_ts > $start_ts && $timing_source !== 'google' ) {
                    $timing_source = 'db';
                    if ( $timing_status === '' ) {
                        $timing_status = 'fallback';
                    }
                }
            }

            if ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                }
                continue;
            }

            $open_ts  = $start_ts - ( 10 * MINUTE_IN_SECONDS );
            $close_ts = $end_ts + ( 10 * MINUTE_IN_SECONDS );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            }

            $bundle = array(
                'lesson' => $effective_lesson,
                'appointment_type' => '',
                'start_ts' => $start_ts,
                'end_ts' => $end_ts,
                'timing_source' => $timing_source,
                'timing_status' => $timing_status,
                'timing_reason' => $timing_reason,
            );

            if ( $now >= $open_ts && $now <= $close_ts ) {
                $current_match = $bundle;
                break;
            }

            if ( $now < $open_ts ) {
                $diff = $open_ts - $now;
                if ( $nearest_upcoming === null || $nearest_upcoming_diff === null || $diff < $nearest_upcoming_diff ) {
                    $nearest_upcoming = $bundle;
                    $nearest_upcoming_diff = $diff;
                }
            } else {
                $diff = $now - $close_ts;
                if ( $nearest_recent === null || $nearest_recent_diff === null || $diff < $nearest_recent_diff ) {
                    $nearest_recent = $bundle;
                    $nearest_recent_diff = $diff;
                }
            }
        }

        if ( $current_match !== null ) {
            return $current_match;
        }

        if ( $nearest_upcoming !== null ) {
            return $nearest_upcoming;
        }

        if ( $nearest_recent !== null ) {
            return $nearest_recent;
        }

        return array(
            'lesson' => null,
            'appointment_type' => '',
            'start_ts' => 0,
            'end_ts' => 0,
            'timing_source' => '',
            'timing_status' => 'unresolved',
            'timing_reason' => 'no_scored_candidates',
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

        $resolved_gate_start_ts = (int) ( $resolved_gate['start_ts'] ?? 0 );
        $resolved_gate_end_ts   = (int) ( $resolved_gate['end_ts'] ?? 0 );
        $resolved_gate_timing_source = (string) ( $resolved_gate['timing_source'] ?? '' );
        $resolved_gate_timing_status = (string) ( $resolved_gate['timing_status'] ?? '' );
        $resolved_gate_timing_reason = (string) ( $resolved_gate['timing_reason'] ?? '' );

        // Re-read the selected lesson row after token-based sync and gate resolution so this
        // request uses the latest DB values written from Google truth.
        if ( is_array( $lesson ) && ! empty( $lesson['id'] ) ) {
            $fresh_lesson = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table_lessons} WHERE id = %d LIMIT 1",
                    (int) $lesson['id']
                ),
                ARRAY_A
            );

            if ( is_array( $fresh_lesson ) && ! empty( $fresh_lesson ) ) {
                $lesson = array_merge( $lesson, $fresh_lesson );
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        }

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

        $gate_timing = array(
            'status' => $resolved_gate_timing_status,
            'reason' => $resolved_gate_timing_reason,
            'source' => $resolved_gate_timing_source,
        );

        if ( $resolved_gate_start_ts && $resolved_gate_end_ts && $resolved_gate_end_ts > $resolved_gate_start_ts ) {
            $start_ts = $resolved_gate_start_ts;
            $end_ts   = $resolved_gate_end_ts;
        } else {
            // Final safety fallback for unexpected cases.
            $gate_timing = $this->get_live_google_timing_for_lesson( $lesson, $calendar_id );

            if ( (string) ( $gate_timing['status'] ?? '' ) === 'resolved' ) {
                $lesson = isset( $gate_timing['lesson'] ) && is_array( $gate_timing['lesson'] )
                    ? $gate_timing['lesson']
                    : $lesson;

                $start_ts = (int) ( $gate_timing['start_ts'] ?? 0 );
                $end_ts   = (int) ( $gate_timing['end_ts'] ?? 0 );
            } else {
                $start_ts = strtotime( (string) ( $lesson['start_time'] ?? '' ) );
                $end_ts   = strtotime( (string) ( $lesson['end_time'] ?? '' ) );

                if ( ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) && ! empty( $lesson['google_original_start_time'] ) ) {
                    $fallback_start_ts = strtotime( (string) $lesson['google_original_start_time'] );
                    if ( $fallback_start_ts ) {
                        $start_ts = $fallback_start_ts;
                        $end_ts = $fallback_start_ts + ( max( 1, (int) ( $lesson['lesson_length'] ?? 60 ) ) * MINUTE_IN_SECONDS );
                    }
                }
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        }

        if ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) {
            $this->render_gate_message_page(
                'Schedule Unavailable',
                'We could not determine the lesson time for this link. Please contact your instructor.'
            );
            exit;
        }

        $open_ts  = $start_ts - ( 10 * MINUTE_IN_SECONDS );
        $close_ts = $end_ts + ( 10 * MINUTE_IN_SECONDS );
        $now_ts   = time();

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        }

        // If outside allowed window, show professional message
        if ( $now_ts < $open_ts || $now_ts > $close_ts ) {
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
        if ( $description === '' ) {
            return '';
        }

        // Normalize any old-domain join link back to the current site.
        $patterns = array(
            '#https?://[^\\s"\']+/(join-online|join-video-lesson)/\\?token=([A-Za-z0-9_\\-]+)#i',
            '#/(join-online|join-video-lesson)/\\?token=([A-Za-z0-9_\\-]+)#i',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $description, $m ) ) {
                $token = (string) ( $m[2] ?? '' );
                if ( $token !== '' ) {
                    return add_query_arg(
                        array( 'token' => $token ),
                        home_url( '/join-online/' )
                    );
                }
            }
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
        $is_gate = get_query_var( 'mrm_join_video_lesson' );
        if ( (string) $is_gate === '1' ) {
            return true;
        }

        $pagename = get_query_var( 'pagename' );
        if ( is_string( $pagename ) && in_array( $pagename, array( 'join-online', 'join-video-lesson' ), true ) ) {
            return true;
        }

        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $path = (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );
            $path = trim( $path, '/' );

            if ( in_array( $path, array( 'join-online', 'join-video-lesson' ), true ) ) {
                return true;
            }
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
            'zip_code' => '',
            'offers_in_person' => 0,
            'offers_online' => 0,
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
            $offers_in_person = ! empty( $row['offers_in_person'] ) ? 1 : 0;
            $offers_online    = ! empty( $row['offers_online'] ) ? 1 : 0;

            $teaching_format_label = '';
            if ( $offers_in_person && $offers_online ) {
                $teaching_format_label = 'In Person - Online';
            } elseif ( $offers_in_person ) {
                $teaching_format_label = 'In Person Only';
            } elseif ( $offers_online ) {
                $teaching_format_label = 'Online Only';
            }

            $output[] = array(
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'email' => (string) $row['email'],
                'city' => (string) $row['city'],
                'state' => (string) $row['state'],
                'profile_image_url' => $row['profile_image_url'],
                'short_description' => $row['short_description'],
                'long_description' => $row['long_description'],
                'instruments' => $row['instruments'],
                'instruments_display' => $row['instruments_display'],
                'instruments_text' => $row['instruments_text'],
                'latitude' => $row['latitude'],
                'longitude' => $row['longitude'],
                'distance' => $row['distance'] ?? null,
                'teaching_format_label' => $teaching_format_label,
                'offers_in_person' => $offers_in_person,
                'offers_online' => $offers_online,
            );
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
                "SELECT id,instructor_id,student_name,student_email,is_online,is_consultation,lesson_length,start_time,end_time,
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

                        // wp_schedule_single_event( $desired_send_at, 'mrm_scheduler_send_lesson_reminder', array( $lesson_id ) );

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

        $intro_html = '<p>Reminder: you have a ' . esc_html( $thing_lower ) . ' scheduled in 1 hour.</p>';

        $details_html =
            '<div><strong>Student:</strong> ' . esc_html( $student_name ) . '</div>' .
            '<div><strong>Length:</strong> ' . esc_html( (string) $minutes ) . ' minutes</div>' .
            '<div><strong>Type:</strong> ' . esc_html( $lesson_type_label ) . '</div>';

        if ( $join_link !== '' ) {
            $details_html .= '<div style="margin-top:12px;"><strong>' . esc_html( $thing_upper ) . ' link:</strong><br><a href="' . esc_url( $join_link ) . '">' . esc_html( $join_link ) . '</a></div>';
        }

        $html = $this->mrm_safety_email_wrap_html(
            $subject,
            $intro_html,
            $details_html,
            $join_link,
            $is_consultation ? 'Open consultation link' : 'Open lesson link'
        );

        $to = array();
        if ( is_email( $student_email ) ) $to[] = $student_email;
        if ( is_email( $instructor_email ) ) $to[] = $instructor_email;

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
        );

        $sent = wp_mail( $to, $subject, $html, $headers );

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
                    AND l.status <> 'finalized'
                    AND (
                        l.status IN ('scheduled', 'payment_due', 'delivered')
                        OR (
                            l.payout_unlocked_at IS NULL
                            AND l.status NOT IN ('cancelled', 'series', 'finalized')
                        )
                    )
                )
                OR
                (
                    l.payment_mode <> 'autopay'
                    AND l.status = 'scheduled'
                    AND l.payout_unlocked_at IS NULL
                    AND l.status <> 'finalized'
                )
          )
        ORDER BY l.start_time ASC
        LIMIT {$limit}
    ";

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    protected function get_recurring_autopay_series_seed_rows() {
        global $wpdb;

        $lessons_table = $wpdb->prefix . 'mrm_lessons';
        $instructors_table = $wpdb->prefix . 'mrm_instructors';

        $sql = "
            SELECT l.*, i.calendar_id, i.timezone
            FROM {$lessons_table} l
            JOIN {$instructors_table} i ON i.id = l.instructor_id
            WHERE l.series_id IS NOT NULL
              AND l.series_id > 0
              AND l.autopay_profile_id IS NOT NULL
              AND l.autopay_profile_id > 0
              AND l.google_event_id IS NOT NULL
              AND l.google_event_id <> ''
              AND l.status IN ('scheduled', 'payment_due', 'delivered')
              AND l.id IN (
                  SELECT MIN(l2.id)
                  FROM {$lessons_table} l2
                  WHERE l2.series_id IS NOT NULL
                    AND l2.series_id > 0
                    AND l2.autopay_profile_id IS NOT NULL
                    AND l2.autopay_profile_id > 0
                    AND l2.status IN ('scheduled', 'payment_due', 'delivered')
                  GROUP BY l2.series_id
              )
            ORDER BY l.start_time ASC
            LIMIT 100
        ";

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    protected function extend_indefinite_autopay_series_horizon( $horizon_days = 90 ) {
        global $wpdb;

        $rows = $this->get_recurring_autopay_series_seed_rows();
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return;
        }

        $lessons_table = $wpdb->prefix . 'mrm_lessons';
        $now = current_time( 'timestamp', true );
        $time_min = gmdate( 'c', $now - DAY_IN_SECONDS );
        $time_max = gmdate( 'c', $now + ( max( 30, (int) $horizon_days ) * DAY_IN_SECONDS ) );

        foreach ( $rows as $seed ) {
            $calendar_id = (string) ( $seed['calendar_id'] ?? '' );
            $master_event_id = (string) ( $seed['google_event_id'] ?? '' );
            $series_id = (int) ( $seed['series_id'] ?? 0 );

            if ( $calendar_id === '' || $master_event_id === '' || $series_id <= 0 ) {
                continue;
            }

            $instances = $this->google_list_event_instances( $calendar_id, $master_event_id, $time_min, $time_max );
            if ( is_wp_error( $instances ) || empty( $instances['items'] ) || ! is_array( $instances['items'] ) ) {
                continue;
            }

            $existing_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, google_instance_event_id, google_original_start_time
                     FROM {$lessons_table}
                     WHERE series_id = %d",
                    $series_id
                ),
                ARRAY_A
            );

            $existing_instance_ids = array();
            $existing_anchors = array();

            foreach ( (array) $existing_rows as $existing_row ) {
                $existing_instance_id = (string) ( $existing_row['google_instance_event_id'] ?? '' );
                $existing_anchor = (string) ( $existing_row['google_original_start_time'] ?? '' );

                if ( $existing_instance_id !== '' ) {
                    $existing_instance_ids[ $existing_instance_id ] = true;
                }
                if ( $existing_anchor !== '' ) {
                    $existing_anchors[ $existing_anchor ] = true;
                }
            }

            foreach ( $instances['items'] as $event ) {
                if ( ! is_array( $event ) ) {
                    continue;
                }

                $status = (string) ( $event['status'] ?? '' );
                if ( $status === 'cancelled' ) {
                    continue;
                }

                $instance_event_id = (string) ( $event['id'] ?? '' );
                $start_raw = (string) ( $event['start']['dateTime'] ?? '' );
                $end_raw   = (string) ( $event['end']['dateTime'] ?? '' );

                if ( $start_raw === '' || $end_raw === '' ) {
                    continue;
                }

                $start_ts = strtotime( $start_raw );
                $end_ts   = strtotime( $end_raw );

                if ( ! $start_ts || ! $end_ts || $end_ts <= $start_ts ) {
                    continue;
                }

                // Only create future rolling-horizon rows.
                if ( $start_ts < ( $now - DAY_IN_SECONDS ) ) {
                    continue;
                }

                $anchor_mysql = ! empty( $event['originalStartTime']['dateTime'] )
                    ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $event['originalStartTime']['dateTime'] ) )
                    : gmdate( 'Y-m-d H:i:s', $start_ts );

                if (
                    ( $instance_event_id !== '' && isset( $existing_instance_ids[ $instance_event_id ] ) ) ||
                    isset( $existing_anchors[ $anchor_mysql ] )
                ) {
                    continue;
                }

                $wpdb->insert(
                    $lessons_table,
                    array(
                        'instructor_id' => (int) ( $seed['instructor_id'] ?? 0 ),
                        'series_id' => $series_id,
                        'student_name' => (string) ( $seed['student_name'] ?? '' ),
                        'student_email' => (string) ( $seed['student_email'] ?? '' ),
                        'instrument' => (string) ( $seed['instrument'] ?? 'unknown' ),
                        'is_online' => (int) ( $seed['is_online'] ?? 0 ),
                        'lesson_length' => (int) ( $seed['lesson_length'] ?? 60 ),
                        'start_time' => gmdate( 'Y-m-d H:i:s', $start_ts ),
                        'end_time' => gmdate( 'Y-m-d H:i:s', $end_ts ),
                        'google_original_start_time' => $anchor_mysql,
                        'status' => 'scheduled',
                        'charge_status' => 'none',
                        'google_event_id' => (string) ( $event['recurringEventId'] ?? $master_event_id ),
                        'google_instance_event_id' => ( $instance_event_id !== '' ? $instance_event_id : null ),
                        'google_meet_url' => (string) ( $event['hangoutLink'] ?? '' ),
                        'order_id' => null,
                        'payment_mode' => 'autopay',
                        'payout_unlocked_at' => null,
                        'autopay_profile_id' => (int) ( $seed['autopay_profile_id'] ?? 0 ),
                        'agreement_id' => ! empty( $seed['agreement_id'] ) ? (int) $seed['agreement_id'] : null,
                        'created_at' => current_time( 'mysql' ),
                        'updated_at' => current_time( 'mysql' ),
                        'reminder_token' => (string) ( $seed['reminder_token'] ?? '' ),
                        'reminder_token_hash' => (string) ( $seed['reminder_token_hash'] ?? '' ),
                        'reminder_scheduled_at' => null,
                        'reminder_sent_at' => null,
                    ),
                    array(
                        '%d','%d','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%s','%s',
                        '%d','%s','%s','%d','%d','%s','%s','%s','%s','%s'
                    )
                );
            }
        }
    }


    public function cron_sync_upcoming_events( $lookback_hours = 72, $lookahead_days = 30 ) {
        if ( ! $this->google_is_configured() ) return;

        // Before syncing existing rows, keep recurring autopay series populated
        // about 90 days ahead so follow-up lessons always exist locally.
        $this->extend_indefinite_autopay_series_horizon( 90 );

        $rows = $this->get_lessons_needing_google_truth_pass( 400 );

        // Debug: record when the sync job fires and how many rows were fetched.  This aids in
        // diagnosing whether WP‑Cron is running versus whether the resolver is failing.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $count = ( is_array( $rows ) ? count( $rows ) : 0 );
        }

        if ( ! is_array( $rows ) || empty( $rows ) ) return;

        foreach ( $rows as $lesson_row ) {
            $calendar_id = (string) ( $lesson_row['calendar_id'] ?? '' );
            if ( $calendar_id === '' ) {
                continue;
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
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
        if ( ! isset( $schedules['mrm_1min'] ) ) {
            $schedules['mrm_1min'] = array(
                'interval' => 60,
                'display'  => __( 'Every 1 minute (MRM)', 'mrm-lesson-scheduler' ),
            );
        }

        if ( ! isset( $schedules['mrm_5min'] ) ) {
            $schedules['mrm_5min'] = array(
                'interval' => 300,
                'display'  => 'Every 5 Minutes',
            );
        }

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
        if ( (string) ( $lesson_row['status'] ?? '' ) === 'finalized' ) {
            $this->mrm_finalization_debug_log( 'cancel_skip_finalized_lesson', array(
                'lesson_id' => $lesson_id,
                'reason'    => (string) $reason,
            ) );
            return;
        }

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

    public function cron_reconcile_completed_lessons( $skip_initial_sync = false ) {
        global $wpdb;

        if ( ! $skip_initial_sync ) {
            $this->cron_sync_upcoming_events( 72, 30 );
        }

        $lessons_table = $wpdb->prefix . 'mrm_lessons';
        $rows = $this->get_lessons_needing_google_truth_pass( 300 );

        if ( ! $rows ) return;

        foreach ( $rows as $l ) {
            if ( (string) ( $l['status'] ?? '' ) === 'finalized' ) {
                $this->mrm_finalization_debug_log( 'completed_reconcile_skipped_finalized_lesson', array(
                    'lesson_id' => (int) ( $l['id'] ?? 0 ),
                    'status'    => (string) ( $l['status'] ?? '' ),
                ) );
                continue;
            }

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
            $charge_status = (string) ( $synced_lesson['charge_status'] ?? 'none' );
            $lesson_order_id = (int) ( $synced_lesson['order_id'] ?? 0 );
            $now_mysql = current_time( 'mysql' );

            $is_prepaid_initial_autopay = (
                $payment_mode === 'autopay'
                && $lesson_order_id > 0
                && $charge_status === 'paid'
            );

            if ( $is_prepaid_initial_autopay ) {
                $wpdb->update(
                    $lessons_table,
                    array(
                        'start_time'         => ( $new_start ?: (string) ( $synced_lesson['start_time'] ?? '' ) ),
                        'end_time'           => $new_end,
                        'google_event_id'    => ( $new_event_id !== '' ? $new_event_id : null ),
                        'payment_mode'       => 'one_time',
                        'status'             => 'delivered',
                        'delivered_at'       => $now_mysql,
                        'payout_unlocked_at' => $now_mysql,
                        'charge_due_at'      => null,
                        'charge_last_error'  => null,
                        'updated_at'         => $now_mysql,
                    ),
                    array( 'id' => $booking_id ),
                    array( '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' ),
                    array( '%d' )
                );

                do_action( 'mrm_lesson_delivered', array(
                    'lesson_id' => $booking_id,
                    'instructor_id' => (int) $synced_lesson['instructor_id'],
                    'student_email' => (string) $synced_lesson['student_email'],
                    'lesson_length' => (int) $synced_lesson['lesson_length'],
                    'is_online' => (int) $synced_lesson['is_online'],
                    'order_id' => $lesson_order_id,
                    'payment_mode' => 'one_time',
                    'autopay_profile_id' => (int) ( $synced_lesson['autopay_profile_id'] ?? 0 ),
                    'google_event_id' => $new_event_id,
                    'ended_at_utc' => (string) $new_end,
                ) );

                continue;
            }

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

    public function cron_finalize_old_lessons() {
        global $wpdb;

        $lessons_table = $wpdb->prefix . 'mrm_lessons';
        $now_mysql = current_time( 'mysql' );
        $cutoff_mysql = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS );

        $this->mrm_finalization_debug_log( 'finalization_cron_started', array(
            'now_mysql'    => $now_mysql,
            'cutoff_mysql' => $cutoff_mysql,
        ) );

        $finalized_at_column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM {$lessons_table} LIKE %s",
                'finalized_at'
            )
        );

        if ( empty( $finalized_at_column ) ) {
            $this->mrm_finalization_debug_log( 'finalization_schema_missing_finalized_at', array(
                'table' => $lessons_table,
            ) );
            return;
        }

        $this->mrm_finalization_debug_log( 'finalization_schema_ready', array(
            'table'               => $lessons_table,
            'finalized_at_column' => $finalized_at_column,
        ) );

        // High-level counts for diagnosis
        $count_delivered_total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$lessons_table}
                 WHERE status = %s",
                'delivered'
            )
        );

        $count_delivered_with_delivered_at = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$lessons_table}
                 WHERE status = %s
                   AND delivered_at IS NOT NULL
                   AND delivered_at <> ''",
                'delivered'
            )
        );

        $count_old_enough = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$lessons_table}
                 WHERE status = %s
                   AND delivered_at IS NOT NULL
                   AND delivered_at <> ''
                   AND delivered_at <= %s",
                'delivered',
                $cutoff_mysql
            )
        );

        $count_candidates = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$lessons_table}
                 WHERE status = %s
                   AND delivered_at IS NOT NULL
                   AND delivered_at <> ''
                   AND delivered_at <= %s
                   AND (finalized_at IS NULL OR finalized_at = '')",
                'delivered',
                $cutoff_mysql
            )
        );

        $this->mrm_finalization_debug_log( 'finalization_candidate_counts', array(
            'delivered_total'             => $count_delivered_total,
            'delivered_with_delivered_at' => $count_delivered_with_delivered_at,
            'not_old_enough'              => max( 0, $count_delivered_with_delivered_at - $count_old_enough ),
            'old_enough'                  => $count_old_enough,
            'finalization_candidates'     => $count_candidates,
        ) );

        if ( $count_delivered_total === 0 ) {
            $this->mrm_finalization_debug_log( 'finalization_no_delivered_rows_exist', array(
                'table' => $lessons_table,
            ) );
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$lessons_table}
                 WHERE status = %s
                   AND end_time IS NOT NULL
                   AND end_time <> ''
                   AND end_time <= %s
                   AND (finalized_at IS NULL OR finalized_at = '')
                 ORDER BY end_time ASC
                 LIMIT 250",
                'delivered',
                $cutoff_mysql
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) || empty( $rows ) ) {
            $this->mrm_finalization_debug_log( 'finalization_cron_finished_no_candidates', array(
                'cutoff_mysql' => $cutoff_mysql,
            ) );
            return;
        }

        foreach ( $rows as $row ) {
            $lesson_id = (int) ( $row['id'] ?? 0 );
            if ( $lesson_id <= 0 ) {
                continue;
            }

            $this->mrm_finalization_debug_log( 'lesson_finalize_candidate', array(
                'lesson_id'           => $lesson_id,
                'status'              => (string) ( $row['status'] ?? '' ),
                'end_time'            => (string) ( $row['end_time'] ?? '' ),
                'delivered_at'        => (string) ( $row['delivered_at'] ?? '' ),
                'finalized_at_before' => (string) ( $row['finalized_at'] ?? '' ),
                'start_time'          => (string) ( $row['start_time'] ?? '' ),
                'finalization_rule'   => '1_hour_after_end_time',
            ) );

            $updated = $wpdb->update(
                $lessons_table,
                array(
                    'status'       => 'finalized',
                    'finalized_at' => $now_mysql,
                    'updated_at'   => $now_mysql,
                ),
                array( 'id' => $lesson_id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );

            if ( $updated === false ) {
                $this->mrm_finalization_debug_log( 'lesson_finalization_update_failed', array(
                    'lesson_id' => $lesson_id,
                    'db_error'  => $wpdb->last_error,
                ) );
                continue;
            }

            $this->mrm_finalization_debug_log( 'lesson_finalized', array(
                'lesson_id'         => $lesson_id,
                'previous_status'   => (string) ( $row['status'] ?? '' ),
                'new_status'        => 'finalized',
                'end_time'          => (string) ( $row['end_time'] ?? '' ),
                'delivered_at'      => (string) ( $row['delivered_at'] ?? '' ),
                'finalized_at'      => $now_mysql,
                'rows_affected'     => $updated,
                'finalization_rule' => '1_hour_after_end_time',
            ) );
        }

        $this->mrm_finalization_debug_log( 'finalization_cron_finished', array(
            'processed_rows' => count( $rows ),
        ) );
    }

    public function cron_reconcile_cancelled_lessons( $skip_initial_sync = false ) {
        global $wpdb;

        if ( ! $this->google_is_configured() ) return;

        // Sync first so moved recurring instances update local start/end times.
        if ( ! $skip_initial_sync ) {
            $this->cron_sync_upcoming_events( 72, 30 );
        }

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

    protected function get_attendance_row_by_lesson_id( $lesson_id ) {
        global $wpdb;
        $table = $this->table_attendance();
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE lesson_id = %d LIMIT 1", (int) $lesson_id ),
            ARRAY_A
        );
    }

    protected function ensure_attendance_row( $lesson_id ) {
        global $wpdb;
        $lesson_id = (int) $lesson_id;
        if ( $lesson_id <= 0 ) return array();

        $row = $this->get_attendance_row_by_lesson_id( $lesson_id );
        if ( is_array( $row ) && ! empty( $row ) ) {
            return $row;
        }

        $table = $this->table_attendance();
        $now = current_time( 'mysql' );

        $wpdb->insert(
            $table,
            array(
                'lesson_id'   => $lesson_id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array( '%d', '%s', '%s' )
        );

        if ( $wpdb->last_error ) {
            $this->mrm_safety_log( 'attendance_row_insert_db_error', array(
                'lesson_id' => (int) $lesson_id,
                'db_error'  => $wpdb->last_error,
            ) );
        }

        $this->mrm_safety_log( 'attendance_row_ensured', array(
            'lesson_id' => (int) $lesson_id,
        ) );

        return $this->get_attendance_row_by_lesson_id( $lesson_id );
    }

    protected function update_attendance_row( $lesson_id, $data ) {
        global $wpdb;

        $lesson_id = (int) $lesson_id;
        if ( $lesson_id <= 0 || ! is_array( $data ) || empty( $data ) ) {
            $this->mrm_safety_log( 'attendance_row_update_skipped_invalid_input', array(
                'lesson_id' => $lesson_id,
                'data_keys' => is_array( $data ) ? array_keys( $data ) : array(),
            ) );
            return false;
        }

        $this->ensure_attendance_row( $lesson_id );

        $table = $this->table_attendance();
        $data['updated_at'] = current_time( 'mysql' );

        $formats = array();
        foreach ( $data as $value ) {
            $formats[] = is_int( $value ) ? '%d' : '%s';
        }

        $result = $wpdb->update(
            $table,
            $data,
            array( 'lesson_id' => $lesson_id ),
            $formats,
            array( '%d' )
        );

        if ( $result === false ) {
            $this->mrm_safety_log( 'attendance_row_update_failed', array(
                'lesson_id' => $lesson_id,
                'data_keys' => array_keys( $data ),
                'db_error'  => $wpdb->last_error,
            ) );
            return false;
        }

        $this->mrm_safety_log( 'attendance_row_updated', array(
            'lesson_id'      => $lesson_id,
            'data_keys'      => array_keys( $data ),
            'affected_rows'  => (int) $result,
        ) );

        return $result;
    }

    protected function get_lesson_with_instructor( $lesson_id ) {
        global $wpdb;
        $lessons_table = $wpdb->prefix . 'mrm_lessons';
        $instructors_table = $wpdb->prefix . 'mrm_instructors';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT l.*,
                        i.name AS instructor_name,
                        i.email AS instructor_email,
                        i.calendar_id AS instructor_calendar_id,
                        i.address AS instructor_address,
                        i.city AS instructor_city,
                        i.state AS instructor_state,
                        i.zip_code AS instructor_zip_code,
                        i.timezone AS instructor_timezone
                 FROM {$lessons_table} l
                 LEFT JOIN {$instructors_table} i ON i.id = l.instructor_id
                 WHERE l.id = %d
                 LIMIT 1",
                (int) $lesson_id
            ),
            ARRAY_A
        );
    }



    protected function render_safety_action_page( $args = array() ) {
        $defaults = array(
            'eyebrow'      => 'Lesson Update',
            'title'        => 'Update Recorded',
            'message_html' => '',
            'card_html'    => '',
            'footer_html'  => '',
        );

        $args = wp_parse_args( $args, $defaults );

        echo '<!doctype html><html><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . esc_html( (string) $args['title'] ) . '</title>';
        echo '<style>
            :root{
                --bg:#f6f4ef;
                --card:#ffffff;
                --text:#111111;
                --muted:#5b5b5b;
                --line:#dfd8ca;
                --accent:#111111;
                --soft:#f2ede3;
                --star:#d4a017;
            }
            *{box-sizing:border-box;}
            body{
                margin:0;
                background:linear-gradient(180deg,#f7f4ee 0%,#efe8db 100%);
                color:var(--text);
                font-family:Arial,sans-serif;
            }
            .mrm-shell{
                min-height:100vh;
                display:flex;
                align-items:center;
                justify-content:center;
                padding:24px 16px;
            }
            .mrm-card{
                width:100%;
                max-width:680px;
                background:var(--card);
                border:1px solid var(--line);
                border-radius:22px;
                box-shadow:0 12px 34px rgba(0,0,0,.08);
                overflow:hidden;
            }
            .mrm-card-top{
                padding:26px 24px 10px;
                text-align:center;
            }
            .mrm-eyebrow{
                display:inline-block;
                font-size:12px;
                letter-spacing:.12em;
                text-transform:uppercase;
                color:#7b6d52;
                margin-bottom:10px;
            }
            .mrm-title{
                margin:0;
                font-size:30px;
                line-height:1.15;
            }
            .mrm-body{
                padding:10px 24px 28px;
                font-size:16px;
                line-height:1.7;
            }
            .mrm-message{
                color:var(--muted);
                text-align:center;
                max-width:560px;
                margin:0 auto 18px;
            }
            .mrm-panel{
                background:var(--soft);
                border:1px solid var(--line);
                border-radius:18px;
                padding:18px;
                margin-top:18px;
            }
            .mrm-btn{
                display:inline-flex;
                align-items:center;
                justify-content:center;
                width:100%;
                min-height:52px;
                text-align:center;
                text-decoration:none;
                border-radius:12px;
                border:1px solid #111;
                padding:14px 18px;
                font-weight:700;
                font-size:15px;
                line-height:1.3;
                cursor:pointer;
            }
            .mrm-btn-primary{
                background:#111;
                color:#fff;
            }
            .mrm-btn-secondary{
                background:#fff;
                color:#111;
            }
            .mrm-stack > * + *{
                margin-top:12px;
            }
            .mrm-footer{
                margin-top:18px;
                color:var(--muted);
                font-size:14px;
                text-align:center;
            }
            textarea,
            input[type="text"]{
                width:100%;
                border:1px solid var(--line);
                border-radius:14px;
                padding:14px 16px;
                font:inherit;
                background:#fff;
                color:#111;
            }
            textarea{
                min-height:140px;
                resize:vertical;
            }
            .mrm-label{
                display:block;
                margin-bottom:10px;
                font-size:15px;
                font-weight:700;
            }
            .mrm-form-actions{
                margin-top:18px;
            }
            @media (max-width:640px){
                .mrm-card-top{
                    padding:22px 18px 8px;
                }
                .mrm-body{
                    padding:10px 18px 22px;
                }
                .mrm-title{
                    font-size:26px;
                }
            }
        </style>';
        echo '</head><body>';
        echo '<div class="mrm-shell">';
        echo '<div class="mrm-card">';
        echo '<div class="mrm-card-top">';
        echo '<div class="mrm-eyebrow">' . esc_html( (string) $args['eyebrow'] ) . '</div>';
        echo '<h1 class="mrm-title">' . esc_html( (string) $args['title'] ) . '</h1>';
        echo '</div>';
        echo '<div class="mrm-body">';
        if ( $args['message_html'] !== '' ) {
            echo '<div class="mrm-message">' . $args['message_html'] . '</div>';
        }
        echo (string) $args['card_html'];
        if ( $args['footer_html'] !== '' ) {
            echo '<div class="mrm-footer">' . $args['footer_html'] . '</div>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</body></html>';
        exit;
    }
    protected function mrm_safety_email_wrap_html( $title, $intro_html, $details_html, $cta_url = '', $cta_label = '' ) {
        $logo  = $this->mrm_get_email_logo_url();
        $site  = esc_html( get_bloginfo( 'name' ) );

        $logo_html = '';
        if ( $logo ) {
            $logo_html = '<div style="text-align:center;margin:0 0 22px 0;">
            <img src="' . esc_url( $logo ) . '" alt="' . $site . '" style="max-width:220px;height:auto;border:0;display:inline-block;"/>
        </div>';
        }

        $button_html = '';
        if ( $cta_url && $cta_label ) {
            $button_html = '<div style="text-align:center;margin:24px 0 0 0;">
            <a href="' . esc_url( $cta_url ) . '" style="display:inline-block;background:#111;color:#fff;text-decoration:none;font-weight:700;padding:13px 20px;border-radius:10px;">' . esc_html( $cta_label ) . '</a>
        </div>';
        }

        return '<!doctype html><html><body style="margin:0;padding:0;background:#f6f6f6;">
        <div style="max-width:640px;margin:0 auto;padding:24px;">
            <div style="background:#ffffff;border:1px solid #e8e8e8;border-radius:16px;padding:28px;box-shadow:0 2px 10px rgba(0,0,0,0.05);font-family:Arial,Helvetica,sans-serif;color:#111;">
                ' . $logo_html . '
                <h1 style="margin:0 0 12px 0;font-size:22px;line-height:1.3;text-align:center;color:#111;">' . esc_html( $title ) . '</h1>
                <div style="font-size:15px;line-height:1.7;color:#222;">' . $intro_html . '</div>
                <div style="margin-top:16px;padding:16px;border:1px solid #ededed;border-radius:12px;background:#fafafa;font-size:14px;line-height:1.7;color:#222;">
                    ' . $details_html . '
                </div>
                ' . $button_html . '
                <div style="margin-top:22px;font-size:12px;color:#777;text-align:center;">' . $site . '</div>
            </div>
        </div>
    </body></html>';
    }

    protected function mrm_safety_email_wrap_html_blocks( $title, $intro_html, $details_html, $extra_html = '' ) {
        $logo  = $this->mrm_get_email_logo_url();
        $site  = esc_html( get_bloginfo( 'name' ) );

        $logo_html = '';
        if ( $logo ) {
            $logo_html = '<div style="text-align:center;margin:0 0 22px 0;">
            <img src="' . esc_url( $logo ) . '" alt="' . $site . '" style="max-width:220px;height:auto;border:0;display:inline-block;"/>
        </div>';
        }

        return '<!doctype html><html><body style="margin:0;padding:0;background:#f6f6f6;">
        <div style="max-width:640px;margin:0 auto;padding:24px;">
            <div style="background:#ffffff;border:1px solid #e8e8e8;border-radius:16px;padding:28px;box-shadow:0 2px 10px rgba(0,0,0,0.05);font-family:Arial,Helvetica,sans-serif;color:#111;">
                ' . $logo_html . '
                <h1 style="margin:0 0 12px 0;font-size:22px;line-height:1.3;text-align:center;color:#111;">' . esc_html( $title ) . '</h1>
                <div style="font-size:15px;line-height:1.7;color:#222;">' . $intro_html . '</div>
                <div style="margin-top:16px;padding:16px;border:1px solid #ededed;border-radius:12px;background:#fafafa;font-size:14px;line-height:1.7;color:#222;">
                    ' . $details_html . '
                </div>
                ' . $extra_html . '
                <div style="margin-top:22px;font-size:12px;color:#777;text-align:center;">' . $site . '</div>
            </div>
        </div>
    </body></html>';
    }

    protected function get_safety_lesson_context( $lesson, $viewer_role = 'parent' ) {
        $lesson = is_array( $lesson ) ? $lesson : array();

        $viewer_role = ( $viewer_role === 'instructor' ) ? 'instructor' : 'parent';

        $timezone_string = 'America/Phoenix';
        if ( $viewer_role === 'instructor' ) {
            $timezone_string = trim( (string) ( $lesson['instructor_timezone'] ?? '' ) );
        } else {
            $timezone_string = trim( (string) ( $lesson['parent_timezone'] ?? '' ) );
        }

        if ( $timezone_string === '' ) {
            $timezone_string = wp_timezone_string();
        }

        try {
            $viewer_tz = new DateTimeZone( $timezone_string );
        } catch ( Exception $e ) {
            $viewer_tz = wp_timezone();
            $timezone_string = wp_timezone_string();
        }

        $start_raw = (string) ( $lesson['start_time'] ?? '' );
        $start_ts = strtotime( $start_raw );
        $start_label = $start_raw !== '' && $start_ts
            ? wp_date( 'F j, Y \a\t g:i A', $start_ts, $viewer_tz )
            : '';

        $minutes = (int) ( $lesson['lesson_length'] ?? 0 );

        $raw_lesson_type = (string) ( $lesson['lesson_type'] ?? '' );
        $normalized_lesson_type = strtolower( str_replace( array( '_', '-' ), ' ', $raw_lesson_type ) );
        $normalized_lesson_type = trim( preg_replace( '/\s+/', ' ', $normalized_lesson_type ) );

        $is_consultation = $this->mrm_is_consultation_lesson( $lesson );

        $is_online = ! empty( $lesson['is_online'] );
        if ( $normalized_lesson_type !== '' ) {
            if ( strpos( $normalized_lesson_type, 'online' ) !== false ) {
                $is_online = true;
            } elseif ( strpos( $normalized_lesson_type, 'in person' ) !== false ) {
                $is_online = false;
            }
        }

        if ( $is_consultation ) {
            $is_online = true;
        }

        $lesson_type_label = $is_consultation
            ? 'Consultation'
            : ( $is_online ? 'Online lesson' : 'In-person lesson' );

        $join_link = '';
        $location_text = '';
        $format_note = '';

        if ( $is_online ) {
            if ( ! empty( $lesson['reminder_token'] ) ) {
                $join_link = add_query_arg(
                    array( 'token' => (string) $lesson['reminder_token'] ),
                    home_url( '/join-online/' )
                );
            }

            if ( $join_link === '' && ! empty( $lesson['google_meet_url'] ) ) {
                $join_link = (string) $lesson['google_meet_url'];
            }

            if ( $is_consultation ) {
                $format_note = 'This is an online consultation. Please use the consultation link above at the scheduled time.';
            } else {
                $format_note = 'This is an online lesson. Please use the lesson link above at the scheduled time.';
            }
        } else {
            $calendar_id = (string) ( $lesson['instructor_calendar_id'] ?? '' );
            $google_event_id = (string) ( $lesson['google_event_id'] ?? '' );

            if ( $calendar_id !== '' && $google_event_id !== '' && $this->google_is_configured() ) {
                $event = $this->google_get_event( $calendar_id, $google_event_id );
                if ( ! is_wp_error( $event ) ) {
                    $location_text = trim( (string) ( $event['location'] ?? '' ) );
                }
            }

            if ( $location_text === '' ) {
                $address = trim( (string) ( $lesson['instructor_address'] ?? '' ) );
                $city = trim( (string) ( $lesson['instructor_city'] ?? '' ) );
                $state = trim( (string) ( $lesson['instructor_state'] ?? '' ) );
                $location_text = trim( implode( ', ', array_filter( array( $address, $city, $state ) ) ) );
            }

            $format_note = 'This is an in-person lesson. Please plan to meet in the agreed open, public, or community lesson setting for the scheduled lesson time.';
        }

        return array(
            'start_label'       => $start_label,
            'minutes'           => $minutes,
            'lesson_type_label' => $lesson_type_label,
            'join_link'         => $join_link,
            'location_text'     => $location_text,
            'format_note'       => $format_note,
            'is_consultation'   => $is_consultation,
            'is_online'         => $is_online,
            'viewer_timezone'   => $timezone_string,
        );
    }



    protected function mrm_get_google_event_summary_for_lesson( $lesson ) {
        $lesson = is_array( $lesson ) ? $lesson : array();

        $calendar_id = trim( (string) ( $lesson['instructor_calendar_id'] ?? '' ) );
        $event_id    = trim( (string) ( $lesson['google_event_id'] ?? '' ) );

        if ( $calendar_id === '' || $event_id === '' ) {
            return '';
        }

        if ( ! $this->google_is_configured() ) {
            return '';
        }

        $event = $this->google_get_event( $calendar_id, $event_id );
        if ( is_wp_error( $event ) || ! is_array( $event ) ) {
            return '';
        }

        $summary = trim( (string) ( $event['summary'] ?? '' ) );
        if ( $summary === '' ) {
            $summary = trim( (string) ( $event['title'] ?? '' ) );
        }

        return $summary;
    }

    protected function mrm_is_consultation_lesson( $lesson ) {
        $lesson = is_array( $lesson ) ? $lesson : array();

        $is_consultation = isset( $lesson['is_consultation'] ) ? (int) $lesson['is_consultation'] : 0;
        if ( $is_consultation === 1 ) {
            return true;
        }

        $appointment_type = strtolower( trim( (string) ( $lesson['appointment_type'] ?? '' ) ) );
        if ( $appointment_type === 'consultation' ) {
            return true;
        }

        $lesson_type = strtolower( trim( (string) ( $lesson['lesson_type'] ?? '' ) ) );
        if ( $lesson_type === 'consultation' ) {
            return true;
        }

        if ( strpos( $lesson_type, 'consultation' ) !== false ) {
            return true;
        }

        $google_summary = $this->mrm_get_google_event_summary_for_lesson( $lesson );
        $google_summary = strtolower( trim( (string) $google_summary ) );

        if ( $google_summary !== '' && strpos( $google_summary, 'consultation' ) !== false ) {
            return true;
        }

        return false;
    }

    protected function get_safety_reminder_subject( $lesson ) {
        $lesson = is_array( $lesson ) ? $lesson : array();

        $start_time   = (string) ( $lesson['start_time'] ?? '' );
        $student_name = trim( (string) ( $lesson['student_name'] ?? '' ) );
        $is_consultation = $this->mrm_is_consultation_lesson( $lesson );

        $date_part = '';
        if ( $start_time !== '' ) {
            $timestamp = strtotime( $start_time );
            if ( $timestamp ) {
                $date_part = wp_date( 'm/d', $timestamp, wp_timezone() );
            }
        }

        if ( $date_part === '' ) {
            $date_part = wp_date( 'm/d', current_time( 'timestamp' ), wp_timezone() );
        }

        if ( $student_name === '' ) {
            $student_name = 'Student';
        }

        if ( $is_consultation ) {
            return $date_part . ' ' . $student_name . ' - Consultation Reminder';
        }

        return $date_part . ' ' . $student_name . ' - Lesson Reminder';
    }


    protected function send_parent_no_show_alert_for_lesson( $lesson_id, $lesson, $reason = '' ) {
        $admin_email = $this->get_admin_notification_email();
        if ( ! is_email( $admin_email ) ) {
            $this->mrm_safety_log( 'parent_no_show_alert_skipped_missing_admin_email', array(
                'lesson_id' => (int) $lesson_id,
            ) );
            return false;
        }

        $context = $this->get_safety_lesson_context( $lesson );

        $details =
            '<div><strong>Lesson ID:</strong> ' . (int) $lesson_id . '</div>' .
            '<div><strong>Student:</strong> ' . esc_html( (string) ( $lesson['student_name'] ?? '' ) ) . '</div>' .
            '<div><strong>Instructor:</strong> ' . esc_html( (string) ( $lesson['instructor_name'] ?? '' ) ) . '</div>' .
            '<div><strong>Time:</strong> ' . esc_html( (string) $context['start_label'] ) . '</div>' .
            '<div><strong>Type:</strong> ' . esc_html( (string) $context['lesson_type_label'] ) . '</div>';

        if ( $reason !== '' ) {
            $details .= '<div style="margin-top:12px;"><strong>Parent note:</strong><br>' . nl2br( esc_html( (string) $reason ) ) . '</div>';
        }

        $html = $this->mrm_safety_email_wrap_html_blocks(
            'Safety alert — parent reported instructor did not arrive',
            '<p>A parent has reported that the instructor did not arrive for the scheduled lesson.</p>',
            $details
        );

        $sent = wp_mail(
            $admin_email,
            'Safety alert — parent reported instructor did not arrive',
            $html,
            array(
                'Content-Type: text/html; charset=UTF-8',
                'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
            )
        );

        $this->mrm_safety_log( 'parent_no_show_alert_result', array(
            'lesson_id'   => (int) $lesson_id,
            'admin_email' => $admin_email,
            'sent'        => $sent ? 'yes' : 'no',
        ) );

        return $sent;
    }

    protected function send_instructor_emergency_notifications( $lesson_id, $lesson, $message ) {
        $admin_email = $this->get_admin_notification_email();
        $student_email = sanitize_email( (string) ( $lesson['student_email'] ?? '' ) );

        $context = $this->get_safety_lesson_context( $lesson );

        $details =
            '<div><strong>Lesson ID:</strong> ' . (int) $lesson_id . '</div>' .
            '<div><strong>Student:</strong> ' . esc_html( (string) ( $lesson['student_name'] ?? '' ) ) . '</div>' .
            '<div><strong>Instructor:</strong> ' . esc_html( (string) ( $lesson['instructor_name'] ?? '' ) ) . '</div>' .
            '<div><strong>Time:</strong> ' . esc_html( (string) $context['start_label'] ) . '</div>' .
            '<div><strong>Type:</strong> ' . esc_html( (string) $context['lesson_type_label'] ) . '</div>' .
            '<div style="margin-top:12px;"><strong>Instructor message:</strong><br>' . nl2br( esc_html( (string) $message ) ) . '</div>';

        $html = $this->mrm_safety_email_wrap_html_blocks(
            'Lesson emergency notice',
            '<p>An emergency has been reported by the instructor and they can no longer make the lesson as scheduled.</p>',
            $details
        );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
        );

        $admin_sent = false;
        $parent_sent = false;

        if ( is_email( $admin_email ) ) {
            $admin_sent = wp_mail( $admin_email, 'Lesson emergency notice', $html, $headers );
        }

        if ( is_email( $student_email ) ) {
            $parent_sent = wp_mail( $student_email, 'Lesson emergency notice', $html, $headers );
        }

        $this->mrm_safety_log( 'instructor_emergency_notifications_result', array(
            'lesson_id'    => (int) $lesson_id,
            'admin_sent'   => $admin_sent ? 'yes' : 'no',
            'parent_sent'  => $parent_sent ? 'yes' : 'no',
            'parent_email' => $student_email,
        ) );

        return ( $admin_sent || $parent_sent );
    }

    protected function send_instructor_departure_followup_for_lesson( $lesson_id, $lesson ) {
        $attendance = $this->ensure_attendance_row( $lesson_id );
        if ( ! empty( $attendance['instructor_departure_email_sent_at'] ) ) {
            $this->mrm_safety_log( 'instructor_departure_followup_skipped_already_sent', array(
                'lesson_id' => (int) $lesson_id,
            ) );
            return false;
        }

        $instructor_email = sanitize_email( (string) ( $lesson['instructor_email'] ?? '' ) );
        if ( ! is_email( $instructor_email ) ) {
            $this->mrm_safety_log( 'instructor_departure_followup_skipped_invalid_email', array(
                'lesson_id' => (int) $lesson_id,
                'instructor_email' => (string) ( $lesson['instructor_email'] ?? '' ),
            ) );
            return false;
        }

        $depart_token = $this->mrm_safety_sign_token( $lesson_id, 'instructor', 'departed', time() + ( 12 * HOUR_IN_SECONDS ) );
        $depart_url = $this->mrm_safety_action_url( $depart_token );
        $context = $this->get_safety_lesson_context( $lesson );

        $details =
            '<div><strong>Student:</strong> ' . esc_html( (string) ( $lesson['student_name'] ?? '' ) ) . '</div>' .
            '<div><strong>Time:</strong> ' . esc_html( (string) $context['start_label'] ) . '</div>' .
            '<div><strong>Type:</strong> ' . esc_html( (string) $context['lesson_type_label'] ) . '</div>' .
            '<div style="margin-top:12px;">When the lesson has ended and you have left the lesson, use the button below.</div>';

        $html = $this->mrm_safety_email_wrap_html(
            'Please mark your lesson complete',
            '<p>Your arrival has been recorded. Please mark the lesson complete after it has ended.</p>',
            $details,
            $depart_url,
            'Click here when you have ended your lesson'
        );

        $sent = wp_mail(
            $instructor_email,
            'Please mark your lesson complete',
            $html,
            array(
                'Content-Type: text/html; charset=UTF-8',
                'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
            )
        );

        if ( $sent ) {
            $this->update_attendance_row( $lesson_id, array(
                'instructor_departure_email_sent_at' => current_time( 'mysql' ),
            ) );
        }

        $this->mrm_safety_log( 'instructor_departure_followup_result', array(
            'lesson_id' => (int) $lesson_id,
            'email'     => $instructor_email,
            'sent'      => $sent ? 'yes' : 'no',
        ) );

        return $sent;
    }

    protected function get_admin_notification_email() {
        return sanitize_email( (string) get_option( 'admin_email', '' ) );
    }

    protected function mrm_safety_sign_token( $lesson_id, $role, $verb, $expires_ts ) {
        $lesson_id = (int) $lesson_id;
        $role = (string) $role;
        $verb = (string) $verb;
        $expires_ts = (int) $expires_ts;

        $payload = $lesson_id . '|' . $role . '|' . $verb . '|' . $expires_ts;
        $sig = hash_hmac( 'sha256', $payload, wp_salt( 'mrm_safety_attendance' ) );

        return base64_encode( $payload . '|' . $sig );
    }

    protected function mrm_safety_verify_token( $token, $expected_role = '', $expected_verb = '' ) {
        $decoded = base64_decode( (string) $token, true );
        if ( ! is_string( $decoded ) || $decoded === '' ) return new WP_Error( 'bad_token', 'Invalid token.' );

        $parts = explode( '|', $decoded );
        if ( count( $parts ) !== 5 ) return new WP_Error( 'bad_token', 'Malformed token.' );

        list( $lesson_id, $role, $verb, $expires_ts, $sig ) = $parts;

        $payload = $lesson_id . '|' . $role . '|' . $verb . '|' . $expires_ts;
        $expected_sig = hash_hmac( 'sha256', $payload, wp_salt( 'mrm_safety_attendance' ) );

        if ( ! hash_equals( $expected_sig, $sig ) ) {
            return new WP_Error( 'bad_sig', 'Invalid signature.' );
        }

        if ( (int) $expires_ts < time() ) {
            return new WP_Error( 'expired', 'This link has expired.' );
        }

        if ( $expected_role !== '' && $role !== $expected_role ) {
            return new WP_Error( 'wrong_role', 'Invalid role.' );
        }

        if ( $expected_verb !== '' && $verb !== $expected_verb ) {
            return new WP_Error( 'wrong_verb', 'Invalid action.' );
        }

        return array(
            'lesson_id' => (int) $lesson_id,
            'role'      => (string) $role,
            'verb'      => (string) $verb,
            'expires'   => (int) $expires_ts,
        );
    }

    protected function mrm_safety_action_url( $token ) {
        return add_query_arg(
            array(
                'action' => 'mrm_safety_attendance_action',
                'token'  => rawurlencode( $token ),
            ),
            admin_url( 'admin-post.php' )
        );
    }

    protected function get_safety_reminder_window_minutes() {
        return array(
            'from' => 59,
            'to'   => 61,
        );
    }



    protected function log_safety_cron_diagnostics() {
        $hooks = array(
            'mrm_scheduler_send_safety_reminders',
            'mrm_scheduler_check_safety_exceptions',
            'mrm_scheduler_send_feedback_requests',
        );

        foreach ( $hooks as $hook ) {
            $next = wp_next_scheduled( $hook );

            $this->mrm_safety_log( 'cron_diagnostic_snapshot', array(
                'hook'               => $hook,
                'next_run_local'     => $next ? wp_date( 'Y-m-d H:i:s', $next, wp_timezone() ) : '',
                'next_run_utc'       => $next ? gmdate( 'Y-m-d H:i:s', $next ) : '',
                'next_run_timestamp' => $next ? (int) $next : 0,
            ) );
        }
    }

    public function run_safety_reminder_sweep_now() {
        $this->mrm_safety_log( 'run_safety_reminder_sweep_now_called', array() );
        $this->log_safety_cron_diagnostics();
        $this->cron_send_safety_reminders();
    }


    public function run_safety_exception_check_now() {
        $this->mrm_safety_log( 'run_safety_exception_check_now_called', array() );
        return $this->cron_check_safety_exceptions();
    }

    public function run_safety_feedback_request_now() {
        $this->mrm_safety_log( 'run_safety_feedback_request_now_called', array() );
        return $this->cron_send_feedback_requests();
    }

    public function cron_send_safety_reminders() {
        global $wpdb;

        $window = $this->get_safety_reminder_window_minutes();
        $lessons_table = $wpdb->prefix . 'mrm_lessons';
        $attendance_table = $this->table_attendance();

        $current_ts = current_time( 'timestamp' );
        $now_local  = wp_date( 'Y-m-d H:i:s', $current_ts, wp_timezone() );
        $from_ts    = $current_ts + ( (int) $window['from'] * MINUTE_IN_SECONDS );
        $to_ts      = $current_ts + ( (int) $window['to'] * MINUTE_IN_SECONDS );
        $from_local = wp_date( 'Y-m-d H:i:s', $from_ts, wp_timezone() );
        $to_local   = wp_date( 'Y-m-d H:i:s', $to_ts, wp_timezone() );

        $next_scheduled = wp_next_scheduled( 'mrm_scheduler_send_safety_reminders' );

        $this->mrm_safety_log( 'cron_send_safety_reminders_entered', array(
            'current_time_local'      => $now_local,
            'current_time_timestamp'  => $current_ts,
            'window_from_min'         => (int) $window['from'],
            'window_to_min'           => (int) $window['to'],
            'from_local'              => $from_local,
            'to_local'                => $to_local,
            'next_hook_run_local'     => $next_scheduled ? wp_date( 'Y-m-d H:i:s', $next_scheduled, wp_timezone() ) : '',
            'next_hook_run_timestamp' => $next_scheduled ? (int) $next_scheduled : 0,
        ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.id, l.status, l.start_time, l.student_email,
                        a.parent_reminder_sent_at, a.instructor_reminder_sent_at
                 FROM {$lessons_table} l
                 LEFT JOIN {$attendance_table} a ON a.lesson_id = l.id
                 WHERE l.status = %s
                   AND l.start_time BETWEEN %s AND %s
                   AND (
                        a.lesson_id IS NULL
                        OR a.parent_reminder_sent_at IS NULL OR a.parent_reminder_sent_at = ''
                        OR a.instructor_reminder_sent_at IS NULL OR a.instructor_reminder_sent_at = ''
                   )
                 ORDER BY l.start_time ASC
                 LIMIT 250",
                'scheduled',
                $from_local,
                $to_local
            ),
            ARRAY_A
        );

        if ( $wpdb->last_error ) {
            $this->mrm_safety_log( 'reminder_query_db_error', array(
                'db_error' => $wpdb->last_error,
            ) );
            return;
        }

        $this->mrm_safety_log( 'reminder_query_completed', array(
            'row_count'  => is_array( $rows ) ? count( $rows ) : 0,
            'from_local' => $from_local,
            'to_local'   => $to_local,
        ) );

        if ( empty( $rows ) ) {
            $this->mrm_safety_log( 'reminder_query_returned_no_rows', array(
                'from_local' => $from_local,
                'to_local'   => $to_local,
            ) );
            return;
        }

        foreach ( (array) $rows as $row ) {
            $lesson_id = (int) ( $row['id'] ?? 0 );
            if ( $lesson_id <= 0 ) {
                $this->mrm_safety_log( 'reminder_candidate_skipped_invalid_lesson_id', array(
                    'raw_row' => $row,
                ) );
                continue;
            }

            $this->mrm_safety_log( 'reminder_candidate_selected', array(
                'lesson_id'                   => $lesson_id,
                'status'                      => (string) ( $row['status'] ?? '' ),
                'start_time'                  => (string) ( $row['start_time'] ?? '' ),
                'student_email'               => (string) ( $row['student_email'] ?? '' ),
                'parent_reminder_sent_at'     => (string) ( $row['parent_reminder_sent_at'] ?? '' ),
                'instructor_reminder_sent_at' => (string) ( $row['instructor_reminder_sent_at'] ?? '' ),
            ) );

            $this->send_safety_reminders_for_lesson( $lesson_id );
        }

        $this->mrm_safety_log( 'reminder_sweep_finished', array(
            'processed_count' => is_array( $rows ) ? count( $rows ) : 0,
        ) );
    }


    protected function mrm_email_button_html( $url, $label, $variant = 'secondary' ) {
        $is_primary = ( $variant === 'primary' );

        $bg     = $is_primary ? '#111' : '#fff';
        $color  = $is_primary ? '#fff' : '#111';
        $border = $is_primary ? '1px solid #111' : '1px solid #111';

        return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:0 auto; width:auto; max-width:100%;">'
            . '<tr><td align="center" style="border-radius:10px; border:' . esc_attr( $border ) . '; background:' . esc_attr( $bg ) . ';">'
            . '<a href="' . esc_url( $url ) . '" style="display:block; width:auto; max-width:320px; padding:14px 18px; text-align:center; text-decoration:none; color:' . esc_attr( $color ) . '; font-weight:600; line-height:1.35; font-size:15px; white-space:normal; word-break:break-word;">'
            . esc_html( $label )
            . '</a>'
            . '</td></tr></table>';
    }


    protected function send_safety_reminders_for_lesson( $lesson_id ) {
        $lesson = $this->get_lesson_with_instructor( $lesson_id );
        if ( ! is_array( $lesson ) || empty( $lesson ) ) {
            $this->mrm_safety_log( 'reminder_skipped_missing_lesson', array(
                'lesson_id' => (int) $lesson_id,
            ) );
            return;
        }

        $this->mrm_safety_log( 'evaluating_lesson_for_safety_reminders', array(
            'lesson_id'        => (int) $lesson_id,
            'status'           => (string) ( $lesson['status'] ?? '' ),
            'start_time'       => (string) ( $lesson['start_time'] ?? '' ),
            'student_email'    => (string) ( $lesson['student_email'] ?? '' ),
            'instructor_email' => (string) ( $lesson['instructor_email'] ?? '' ),
            'appointment_type' => (string) ( $lesson['appointment_type'] ?? '' ),
            'lesson_type'      => (string) ( $lesson['lesson_type'] ?? '' ),
        ) );

        if ( (string) ( $lesson['status'] ?? '' ) !== 'scheduled' ) {
            $this->mrm_safety_log( 'reminder_skipped_non_scheduled_lesson', array(
                'lesson_id' => (int) $lesson_id,
                'status'    => (string) ( $lesson['status'] ?? '' ),
            ) );
            return;
        }

        $attendance = $this->ensure_attendance_row( $lesson_id );
        $parent_context     = $this->get_safety_lesson_context( $lesson, 'parent' );
        $instructor_context = $this->get_safety_lesson_context( $lesson, 'instructor' );
        $is_consultation    = ! empty( $parent_context['is_consultation'] );
        $exp        = time() + ( 8 * HOUR_IN_SECONDS );

        $parent_no_show_token       = $this->mrm_safety_sign_token( $lesson_id, 'parent', 'report_no_show', $exp );
        $instructor_arrived_token   = $this->mrm_safety_sign_token( $lesson_id, 'instructor', 'arrived', $exp );
        $instructor_emergency_token = $this->mrm_safety_sign_token( $lesson_id, 'instructor', 'emergency', $exp );

        $parent_no_show_url       = $this->mrm_safety_action_url( $parent_no_show_token );
        $instructor_arrived_url   = $this->mrm_safety_action_url( $instructor_arrived_token );
        $instructor_emergency_url = $this->mrm_safety_action_url( $instructor_emergency_token );

        $parent_details = '';
        $parent_details .= '<div><strong>Student:</strong> ' . esc_html( (string) ( $lesson['student_name'] ?? '' ) ) . '</div>';
        $parent_details .= '<div><strong>Instructor:</strong> ' . esc_html( (string) ( $lesson['instructor_name'] ?? '' ) ) . '</div>';
        $parent_details .= '<div><strong>Time:</strong> ' . esc_html( (string) $parent_context['start_label'] ) . '</div>';

        if ( $is_consultation ) {
            $parent_details .= '<div><strong>Type:</strong> Consultation</div>';
            $parent_details .= '<div><strong>Consultation length:</strong> ' . esc_html( (string) $parent_context['minutes'] ) . ' minutes</div>';
        } else {
            $parent_details .= '<div><strong>Length:</strong> ' . esc_html( (string) $parent_context['minutes'] ) . ' minutes</div>';
            $parent_details .= '<div><strong>Type:</strong> ' . esc_html( (string) $parent_context['lesson_type_label'] ) . '</div>';
        }

        if ( ! empty( $parent_context['join_link'] ) ) {
            $parent_details .= '<div><strong>' . ( $is_consultation ? 'Consultation link' : 'Lesson link' ) . ':</strong> <a href="' . esc_url( (string) $parent_context['join_link'] ) . '">' . esc_html( (string) $parent_context['join_link'] ) . '</a></div>';
        }

        if ( ! empty( $parent_context['location_text'] ) ) {
            $parent_details .= '<div><strong>Location:</strong> ' . esc_html( (string) $parent_context['location_text'] ) . '</div>';
        }

        $parent_details .= '<div style="margin-top:12px;">' . esc_html( (string) $parent_context['format_note'] ) . '</div>';

        $instructor_details = '';
        $instructor_details .= '<div><strong>Student:</strong> ' . esc_html( (string) ( $lesson['student_name'] ?? '' ) ) . '</div>';
        $instructor_details .= '<div><strong>Instructor:</strong> ' . esc_html( (string) ( $lesson['instructor_name'] ?? '' ) ) . '</div>';
        $instructor_details .= '<div><strong>Time:</strong> ' . esc_html( (string) $instructor_context['start_label'] ) . '</div>';

        if ( $is_consultation ) {
            $instructor_details .= '<div><strong>Type:</strong> Consultation</div>';
            $instructor_details .= '<div><strong>Consultation length:</strong> ' . esc_html( (string) $instructor_context['minutes'] ) . ' minutes</div>';
        } else {
            $instructor_details .= '<div><strong>Length:</strong> ' . esc_html( (string) $instructor_context['minutes'] ) . ' minutes</div>';
            $instructor_details .= '<div><strong>Type:</strong> ' . esc_html( (string) $instructor_context['lesson_type_label'] ) . '</div>';
        }

        if ( ! empty( $instructor_context['join_link'] ) ) {
            $instructor_details .= '<div><strong>' . ( $is_consultation ? 'Consultation link' : 'Lesson link' ) . ':</strong> <a href="' . esc_url( (string) $instructor_context['join_link'] ) . '">' . esc_html( (string) $instructor_context['join_link'] ) . '</a></div>';
        }

        if ( ! empty( $instructor_context['location_text'] ) ) {
            $instructor_details .= '<div><strong>Location:</strong> ' . esc_html( (string) $instructor_context['location_text'] ) . '</div>';
        }

        $instructor_details .= '<div style="margin-top:12px;">' . esc_html( (string) $instructor_context['format_note'] ) . '</div>';

        $student_email    = sanitize_email( (string) ( $lesson['student_email'] ?? '' ) );
        $instructor_email = sanitize_email( (string) ( $lesson['instructor_email'] ?? '' ) );

        if ( empty( $attendance['parent_reminder_sent_at'] ) ) {
            if ( ! is_email( $student_email ) ) {
                $this->mrm_safety_log( 'parent_reminder_skipped_invalid_email', array(
                    'lesson_id'     => (int) $lesson_id,
                    'student_email' => (string) ( $lesson['student_email'] ?? '' ),
                ) );
            } else {
                if ( $is_consultation ) {
                    $parent_buttons =
                        '<div style="margin-top:24px;">' .
                            '<div style="margin:0 auto;text-align:center;">' . $this->mrm_email_button_html( $parent_no_show_url, 'Click here if your instructor did not arrive', 'secondary' ) . '</div>' .
                        '</div>';

                    $parent_title = 'Consultation Reminder';
                    $parent_intro = '<p>Reminder: you have a consultation coming up in one hour.</p>';
                } else {
                    $parent_arrived_token = $this->mrm_safety_sign_token( $lesson_id, 'parent', 'confirm_arrival', $exp );
                    $parent_arrived_url   = $this->mrm_safety_action_url( $parent_arrived_token );

                    $parent_buttons =
                        '<div style="margin-top:24px;">' .
                            '<div style="margin:0 auto 12px auto;text-align:center;">' . $this->mrm_email_button_html( $parent_arrived_url, 'Click here when your instructor arrives', 'primary' ) . '</div>' .
                            '<div style="margin:0 auto;text-align:center;">' . $this->mrm_email_button_html( $parent_no_show_url, 'Click here if your instructor did not arrive', 'secondary' ) . '</div>' .
                        '</div>';

                    $parent_title = 'Lesson reminder';
                    $parent_intro = '<p>Reminder: you have a lesson coming up in one hour.</p>';
                }

                $parent_html = $this->mrm_safety_email_wrap_html_blocks(
                    $parent_title,
                    $parent_intro,
                    $parent_details,
                    $parent_buttons
                );

                $parent_sent = wp_mail(
                    $student_email,
                    $this->get_safety_reminder_subject( $lesson ),
                    $parent_html,
                    array(
                        'Content-Type: text/html; charset=UTF-8',
                        'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
                    )
                );

                if ( $parent_sent ) {
                    $this->update_attendance_row( $lesson_id, array(
                        'parent_reminder_sent_at' => current_time( 'mysql' ),
                    ) );
                }

                $this->mrm_safety_log( 'parent_reminder_result', array(
                    'lesson_id'        => (int) $lesson_id,
                    'email'            => $student_email,
                    'sent'             => $parent_sent ? 'yes' : 'no',
                    'is_consultation'  => $is_consultation ? 'yes' : 'no',
                ) );
            }
        } else {
            $this->mrm_safety_log( 'parent_reminder_skipped_already_sent', array(
                'lesson_id'               => (int) $lesson_id,
                'parent_reminder_sent_at' => (string) ( $attendance['parent_reminder_sent_at'] ?? '' ),
            ) );
        }

        if ( empty( $attendance['instructor_reminder_sent_at'] ) ) {
            if ( ! is_email( $instructor_email ) ) {
                $this->mrm_safety_log( 'instructor_reminder_skipped_invalid_email', array(
                    'lesson_id'        => (int) $lesson_id,
                    'instructor_email' => (string) ( $lesson['instructor_email'] ?? '' ),
                ) );
            } else {
                if ( $is_consultation ) {
                    $instructor_buttons =
                        '<div style="margin-top:24px;">' .
                            '<div style="margin:0 auto;text-align:center;">' . $this->mrm_email_button_html( $instructor_emergency_url, 'An emergency has arisen and I can no longer make this consultation', 'secondary' ) . '</div>' .
                        '</div>';

                    $instructor_title = 'Consultation Reminder';
                    $instructor_intro = '<p>Reminder: you have a consultation coming up in one hour.</p>';
                } else {
                    $instructor_buttons =
                        '<div style="margin-top:24px;">' .
                            '<div style="margin:0 auto 12px auto;text-align:center;">' . $this->mrm_email_button_html( $instructor_arrived_url, 'Click here when you have arrived for your lesson', 'primary' ) . '</div>' .
                            '<div style="margin:0 auto;text-align:center;">' . $this->mrm_email_button_html( $instructor_emergency_url, 'An emergency has arisen and I can no longer make this lesson', 'secondary' ) . '</div>' .
                        '</div>';

                    $instructor_title = 'Instructor lesson reminder';
                    $instructor_intro = '<p>Reminder: you have a lesson coming up in one hour.</p>';
                }

                $instructor_html = $this->mrm_safety_email_wrap_html_blocks(
                    $instructor_title,
                    $instructor_intro,
                    $instructor_details,
                    $instructor_buttons
                );

                $instructor_sent = wp_mail(
                    $instructor_email,
                    $this->get_safety_reminder_subject( $lesson ),
                    $instructor_html,
                    array(
                        'Content-Type: text/html; charset=UTF-8',
                        'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
                    )
                );

                if ( $instructor_sent ) {
                    $this->update_attendance_row( $lesson_id, array(
                        'instructor_reminder_sent_at' => current_time( 'mysql' ),
                    ) );
                }

                $this->mrm_safety_log( 'instructor_reminder_result', array(
                    'lesson_id'        => (int) $lesson_id,
                    'email'            => $instructor_email,
                    'sent'             => $instructor_sent ? 'yes' : 'no',
                    'is_consultation'  => $is_consultation ? 'yes' : 'no',
                ) );
            }
        } else {
            $this->mrm_safety_log( 'instructor_reminder_skipped_already_sent', array(
                'lesson_id'                   => (int) $lesson_id,
                'instructor_reminder_sent_at' => (string) ( $attendance['instructor_reminder_sent_at'] ?? '' ),
            ) );
        }
    }



    public function cron_send_feedback_requests() {
        global $wpdb;

        $this->mrm_safety_log( 'cron_send_feedback_requests_entered', array(
            'current_time_mysql' => current_time( 'mysql' ),
            'current_time_ts'    => current_time( 'timestamp' ),
        ) );

        $lessons_table    = $wpdb->prefix . 'mrm_lessons';
        $attendance_table = $this->table_attendance();

        $from_local = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 24 * HOUR_IN_SECONDS ) );
        $to_local   = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 15 * MINUTE_IN_SECONDS ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.id
                 FROM {$lessons_table} l
                 LEFT JOIN {$attendance_table} a ON a.lesson_id = l.id
                 WHERE l.status IN ('scheduled','delivered')
                   AND l.end_time BETWEEN %s AND %s
                   AND (
                        a.lesson_id IS NULL
                        OR (
                            (a.feedback_submitted_at IS NULL OR a.feedback_submitted_at = '')
                            AND (a.parent_feedback_request_sent_at IS NULL OR a.parent_feedback_request_sent_at = '')
                        )
                   )
                 ORDER BY l.end_time ASC
                 LIMIT 250",
                $from_local,
                $to_local
            ),
            ARRAY_A
        );

        if ( $wpdb->last_error ) {
            $this->mrm_safety_log( 'feedback_request_query_db_error', array( 'db_error' => $wpdb->last_error ) );
            return;
        }

        $this->mrm_safety_log( 'feedback_request_query_completed', array(
            'row_count'   => is_array( $rows ) ? count( $rows ) : 0,
            'from_local'  => $from_local,
            'to_local'    => $to_local,
        ) );

        if ( empty( $rows ) ) {
            $this->mrm_safety_log( 'feedback_request_query_returned_no_rows', array() );
            return;
        }

        foreach ( (array) $rows as $row ) {
            $lesson_id = (int) ( $row['id'] ?? 0 );
            if ( $lesson_id <= 0 ) { continue; }
            $this->send_parent_feedback_request_for_lesson( $lesson_id );
        }

        $this->mrm_safety_log( 'feedback_request_cron_finished', array( 'processed_count' => is_array( $rows ) ? count( $rows ) : 0 ) );
    }

    protected function mrm_claim_feedback_request_send( $lesson_id ) {
        $lesson_id = (int) $lesson_id;
        if ( $lesson_id <= 0 ) {
            return false;
        }

        $lock_key = 'mrm_feedback_request_claim_' . $lesson_id;

        if ( get_option( $lock_key, null ) ) {
            return false;
        }

        $added = add_option( $lock_key, current_time( 'mysql' ), '', 'no' );
        return (bool) $added;
    }

    protected function mrm_release_feedback_request_send( $lesson_id ) {
        $lesson_id = (int) $lesson_id;
        if ( $lesson_id <= 0 ) {
            return;
        }

        $lock_key = 'mrm_feedback_request_claim_' . $lesson_id;
        delete_option( $lock_key );
    }

    protected function send_parent_feedback_request_for_lesson( $lesson_id, $force_send = false ) {
        $lesson = $this->get_lesson_with_instructor( $lesson_id );
        if ( ! is_array( $lesson ) || empty( $lesson ) ) {
            $this->mrm_safety_log( 'feedback_request_skipped_missing_lesson', array(
                'lesson_id' => (int) $lesson_id,
            ) );
            return;
        }

        $attendance = $this->ensure_attendance_row( $lesson_id );

        if ( ! empty( $attendance['feedback_submitted_at'] ) ) {
            $this->mrm_safety_log( 'feedback_request_skipped_already_submitted', array(
                'lesson_id' => (int) $lesson_id,
                'feedback_submitted_at' => (string) ( $attendance['feedback_submitted_at'] ?? '' ),
            ) );
            return;
        }

        if ( ! empty( $attendance['parent_feedback_request_sent_at'] ) ) {
            $this->mrm_safety_log( 'feedback_request_skipped_already_sent', array(
                'lesson_id' => (int) $lesson_id,
                'parent_feedback_request_sent_at' => (string) ( $attendance['parent_feedback_request_sent_at'] ?? '' ),
            ) );
            return;
        }

        if ( ! $this->mrm_claim_feedback_request_send( $lesson_id ) ) {
            $this->mrm_safety_log( 'feedback_request_skipped_claim_locked', array(
                'lesson_id' => (int) $lesson_id,
                'forced'    => $force_send ? 'yes' : 'no',
            ) );
            return;
        }

        $student_email = sanitize_email( (string) ( $lesson['student_email'] ?? '' ) );
        if ( ! is_email( $student_email ) ) {
            $this->mrm_safety_log( 'feedback_request_skipped_invalid_email', array(
                'lesson_id' => (int) $lesson_id,
                'student_email' => (string) ( $lesson['student_email'] ?? '' ),
            ) );
            $this->mrm_release_feedback_request_send( $lesson_id );
            return;
        }

        $feedback_token = $this->mrm_safety_sign_token( $lesson_id, 'parent', 'feedback', time() + ( 24 * HOUR_IN_SECONDS ) );
        $feedback_url   = $this->mrm_safety_action_url( $feedback_token );
        $context = $this->get_safety_lesson_context( $lesson );

        $details =
            '<div><strong>Student:</strong> ' . esc_html( (string) ( $lesson['student_name'] ?? '' ) ) . '</div>' .
            '<div><strong>Instructor:</strong> ' . esc_html( (string) ( $lesson['instructor_name'] ?? '' ) ) . '</div>' .
            '<div><strong>Lesson time:</strong> ' . esc_html( (string) $context['start_label'] ) . '</div>' .
            '<div><strong>Type:</strong> ' . esc_html( (string) $context['lesson_type_label'] ) . '</div>';

        $html = $this->mrm_safety_email_wrap_html(
            'How was your lesson?',
            '<p>Please rate the lesson and share any comments you would like us to see.</p>',
            $details,
            $feedback_url,
            'Rate your lesson'
        );

        $sent = wp_mail(
            $student_email,
            'How was your lesson?',
            $html,
            array(
                'Content-Type: text/html; charset=UTF-8',
                'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
            )
        );

        $this->update_attendance_row( $lesson_id, array(
            'parent_feedback_request_sent_at' => current_time( 'mysql' ),
        ) );

        if ( ! $sent ) {
            $this->mrm_release_feedback_request_send( $lesson_id );
        }

        $this->mrm_safety_log( 'feedback_request_email_result', array(
            'lesson_id' => (int) $lesson_id,
            'email'     => $student_email,
            'sent'      => $sent ? 'yes' : 'no',
            'forced'    => $force_send ? 'yes' : 'no',
        ) );
    }

    public function handle_safety_attendance_action() {
        $token = isset( $_GET['token'] ) ? rawurldecode( (string) $_GET['token'] ) : '';

        $this->mrm_safety_log( 'handle_safety_attendance_action_entered', array(
            'has_token'   => $token !== '' ? 'yes' : 'no',
            'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
            'remote_addr' => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
        ) );

        $parsed = $this->mrm_safety_verify_token( $token );

        if ( is_wp_error( $parsed ) ) {
            $this->mrm_safety_log( 'handle_safety_attendance_action_invalid_token', array(
                'error' => $parsed->get_error_message(),
            ) );
            status_header( 400 );
            echo '<h2>Invalid or expired link</h2><p>' . esc_html( $parsed->get_error_message() ) . '</p>';
            exit;
        }

        $lesson_id = (int) $parsed['lesson_id'];
        $role      = (string) $parsed['role'];
        $verb      = (string) $parsed['verb'];

        $this->mrm_safety_log( 'handle_safety_attendance_action_token_valid', array(
            'lesson_id' => $lesson_id,
            'role'      => $role,
            'verb'      => $verb,
        ) );

        if ( $role === 'instructor' && $verb === 'arrived' ) {
            $this->render_instructor_arrived_page( $lesson_id );
            exit;
        }

        if ( $role === 'instructor' && $verb === 'departed' ) {
            $this->render_instructor_departed_page( $lesson_id );
            exit;
        }

        if ( $role === 'instructor' && $verb === 'emergency' ) {
            $this->render_instructor_emergency_form_page( $lesson_id );
            exit;
        }

        if ( $role === 'parent' && $verb === 'confirm_arrival' ) {
            $this->render_parent_confirm_arrival_page( $lesson_id );
            exit;
        }

        if ( $role === 'parent' && $verb === 'report_no_show' ) {
            $this->render_parent_no_show_page( $lesson_id );
            exit;
        }

        if ( $role === 'parent' && $verb === 'feedback' ) {
            $this->render_parent_feedback_form_page( $lesson_id, false );
            exit;
        }

        $this->mrm_safety_log( 'handle_safety_attendance_action_unsupported_action', array(
            'lesson_id' => $lesson_id,
            'role'      => $role,
            'verb'      => $verb,
        ) );

        status_header( 400 );
        echo '<h2>Unsupported action</h2>';
        exit;
    }

    public function handle_safety_feedback_submit() {
        $token = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : '';

        $this->mrm_safety_log( 'handle_safety_feedback_submit_entered', array(
            'has_token'   => $token !== '' ? 'yes' : 'no',
            'remote_addr' => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
        ) );

        $parsed = $this->mrm_safety_verify_token( $token, 'parent', 'feedback' );
        if ( is_wp_error( $parsed ) ) {
            $this->mrm_safety_log( 'handle_safety_feedback_submit_invalid_token', array( 'error' => $parsed->get_error_message() ) );
            status_header( 400 ); echo '<h2>Invalid or expired feedback link</h2><p>' . esc_html( $parsed->get_error_message() ) . '</p>'; exit;
        }

        $lesson_id = (int) $parsed['lesson_id'];
        $rating    = isset( $_POST['rating'] ) ? (int) $_POST['rating'] : 0;
        $comment   = isset( $_POST['comment'] ) ? wp_kses_post( wp_unslash( $_POST['comment'] ) ) : '';

        if ( $rating < 1 || $rating > 5 ) {
            $this->mrm_safety_log( 'handle_safety_feedback_submit_invalid_rating', array( 'lesson_id' => $lesson_id, 'rating' => $rating ) );
            status_header( 400 ); echo '<h2>Please choose a rating</h2>'; exit;
        }

        $this->ensure_attendance_row( $lesson_id );
        $this->update_attendance_row( $lesson_id, array( 'parent_rating' => $rating, 'parent_comment' => $comment, 'feedback_submitted_at' => current_time( 'mysql' ) ) );
        $lesson = $this->get_lesson_with_instructor( $lesson_id );
        $this->send_parent_feedback_notifications( $lesson_id, $lesson, $rating, $comment );

        $this->mrm_safety_log( 'parent_feedback_submitted', array( 'lesson_id' => $lesson_id, 'rating' => $rating, 'comment_length' => strlen( (string) $comment ) ) );

        echo '<h2>Thank you</h2><p>Your feedback has been submitted.</p>';
        exit;
    }


    public function handle_safety_emergency_submit() {
        $token = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : '';
        $message = isset( $_POST['message'] ) ? trim( wp_kses_post( wp_unslash( $_POST['message'] ) ) ) : '';

        $this->mrm_safety_log( 'handle_safety_emergency_submit_entered', array(
            'has_token'   => $token !== '' ? 'yes' : 'no',
            'message_len' => strlen( $message ),
            'remote_addr' => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
        ) );

        $parsed = $this->mrm_safety_verify_token( $token, 'instructor', 'emergency' );
        if ( is_wp_error( $parsed ) ) {
            $this->mrm_safety_log( 'handle_safety_emergency_submit_invalid_token', array(
                'error' => $parsed->get_error_message(),
            ) );
            status_header( 400 );
            $this->render_safety_action_page( array(
                'eyebrow'      => 'Lesson Update',
                'title'        => 'Invalid or expired emergency link',
                'message_html' => '<p class="mrm-message">' . esc_html( $parsed->get_error_message() ) . '</p>',
            ) );
            exit;
        }

        if ( $message === '' ) {
            status_header( 400 );
            $this->render_safety_action_page( array(
                'eyebrow'      => 'Lesson Update',
                'title'        => 'Please enter a message',
                'message_html' => '<p class="mrm-message">A message is required before sending an emergency notice.</p>',
            ) );
            exit;
        }

        $lesson_id = (int) $parsed['lesson_id'];
        $lesson = $this->get_lesson_with_instructor( $lesson_id );
        if ( ! is_array( $lesson ) || empty( $lesson ) ) {
            status_header( 404 );
            echo '<h2>Lesson not found</h2>';
            exit;
        }

        $attendance = $this->ensure_attendance_row( $lesson_id );

        if ( empty( $attendance['instructor_emergency_reported_at'] ) ) {
            $this->update_attendance_row( $lesson_id, array(
                'instructor_emergency_reported_at' => current_time( 'mysql' ),
                'instructor_emergency_reported_ip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : '',
                'instructor_emergency_message'     => $message,
            ) );
        }

        if ( empty( $attendance['instructor_emergency_notified_at'] ) ) {
            $sent = $this->send_instructor_emergency_notifications( $lesson_id, $lesson, $message );
            if ( $sent ) {
                $this->update_attendance_row( $lesson_id, array(
                    'instructor_emergency_notified_at' => current_time( 'mysql' ),
                ) );
            }
        }

        $this->mrm_safety_log( 'instructor_emergency_recorded', array(
            'lesson_id' => (int) $lesson_id,
            'instructor_email' => (string) ( $lesson['instructor_email'] ?? '' ),
        ) );

        $this->render_safety_action_page( array(
            'eyebrow'      => 'Lesson Update',
            'title'        => 'Emergency notice sent',
            'message_html' => '<p class="mrm-message">Your emergency message has been recorded and sent to the parent and site administrator.</p>',
            'card_html'    => '<div class="mrm-panel">Thank you. The family has now been notified.</div>',
        ) );
        exit;
    }

    protected function render_instructor_arrived_page( $lesson_id ) {
        $lesson = $this->get_lesson_with_instructor( $lesson_id );
        if ( ! is_array( $lesson ) || empty( $lesson ) ) {
            status_header( 404 );
            $this->render_safety_action_page( array(
                'eyebrow'      => 'Lesson Update',
                'title'        => 'Lesson Not Found',
                'message_html' => '<p>We could not locate the lesson connected to this link.</p>',
            ) );
        }

        $attendance = $this->ensure_attendance_row( $lesson_id );
        if ( empty( $attendance['instructor_arrived_at'] ) ) {
            $this->update_attendance_row( $lesson_id, array(
                'instructor_arrived_at' => current_time( 'mysql' ),
                'instructor_arrived_ip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : '',
            ) );
        }

        $this->send_instructor_departure_followup_for_lesson( $lesson_id, $lesson );

        $this->mrm_safety_log( 'instructor_arrived_recorded', array(
            'lesson_id'         => (int) $lesson_id,
            'instructor_email'  => (string) ( $lesson['instructor_email'] ?? '' ),
        ) );

        $card_html  = '<div class="mrm-panel">';
        $card_html .= '<div><strong>Student:</strong> ' . esc_html( (string) ( $lesson['student_name'] ?? '' ) ) . '</div>';
        $card_html .= '<div><strong>Status:</strong> Arrival recorded</div>';
        $card_html .= '</div>';

        $this->render_safety_action_page( array(
            'eyebrow'      => 'Arrival Recorded',
            'title'        => 'You’re All Set',
            'message_html' => '<p>Your arrival has been recorded for this lesson.</p><p>A follow-up email has been sent with the button to mark the lesson complete after it has ended.</p>',
            'card_html'    => $card_html,
        ) );
    }

    protected function render_instructor_departed_page( $lesson_id ) {
        $lesson = $this->get_lesson_with_instructor( $lesson_id );
        if ( ! is_array( $lesson ) || empty( $lesson ) ) {
            status_header( 404 );
            $this->render_safety_action_page( array(
                'eyebrow'      => 'Lesson Update',
                'title'        => 'Lesson Not Found',
                'message_html' => '<p>We could not locate the lesson connected to this link.</p>',
            ) );
        }

        $attendance = $this->ensure_attendance_row( $lesson_id );
        if ( empty( $attendance['instructor_departed_at'] ) ) {
            $this->update_attendance_row( $lesson_id, array(
                'instructor_departed_at' => current_time( 'mysql' ),
                'instructor_departed_ip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : '',
            ) );
        }

        $this->mrm_safety_log( 'instructor_departed_recorded', array(
            'lesson_id'        => (int) $lesson_id,
            'instructor_email' => (string) ( $lesson['instructor_email'] ?? '' ),
        ) );

        $card_html  = '<div class="mrm-panel">';
        $card_html .= '<div><strong>Student:</strong> ' . esc_html( (string) ( $lesson['student_name'] ?? '' ) ) . '</div>';
        $card_html .= '<div><strong>Status:</strong> Lesson completion recorded</div>';
        $card_html .= '</div>';

        $this->render_safety_action_page( array(
            'eyebrow'      => 'Lesson Completed',
            'title'        => 'Thank You',
            'message_html' => '<p>Your lesson completion has been recorded successfully.</p>',
            'card_html'    => $card_html,
        ) );
    }

    protected function render_instructor_emergency_form_page( $lesson_id ) {
        $lesson = $this->get_lesson_with_instructor( $lesson_id );
        if ( ! is_array( $lesson ) || empty( $lesson ) ) {
            status_header( 404 );
            $this->render_safety_action_page( array(
                'eyebrow'      => 'Lesson Update',
                'title'        => 'Lesson not found',
                'message_html' => '<p class="mrm-message">We could not locate this lesson.</p>',
            ) );
            exit;
        }

        $token = $this->mrm_safety_sign_token( $lesson_id, 'instructor', 'emergency', time() + ( 4 * HOUR_IN_SECONDS ) );
        $submit_url = add_query_arg( array( 'action' => 'mrm_safety_emergency_submit' ), admin_url( 'admin-post.php' ) );

        $context = $this->get_safety_lesson_context( $lesson, 'instructor' );
        $is_consultation = ! empty( $context['is_consultation'] );

        $message_html = '<p class="mrm-message">Please provide a professional explanation that will be sent to the parent and site administrator.</p>';

        $card_html = '
        <div class="mrm-panel mrm-stack">
            <div><strong>Student:</strong> ' . esc_html( (string) ( $lesson['student_name'] ?? '' ) ) . '</div>
            <div><strong>Time:</strong> ' . esc_html( (string) ( $context['start_label'] ?? '' ) ) . '</div>
            <div><strong>Type:</strong> ' . esc_html( $is_consultation ? 'Consultation' : (string) ( $context['lesson_type_label'] ?? 'Lesson' ) ) . '</div>
        </div>
        <form method="post" action="' . esc_url( $submit_url ) . '" class="mrm-stack" style="margin-top:18px;">
            <input type="hidden" name="token" value="' . esc_attr( $token ) . '">
            <div>
                <label class="mrm-label" for="mrm-emergency-message">Message</label>
                <textarea id="mrm-emergency-message" name="message" rows="8" required placeholder="Briefly explain the emergency and any helpful next steps for the family."></textarea>
            </div>
            <div class="mrm-form-actions">
                <button type="submit" class="mrm-btn mrm-btn-primary">Send emergency notice</button>
            </div>
        </form>
    ';

        $this->render_safety_action_page( array(
            'eyebrow'      => 'Lesson Update',
            'title'        => $is_consultation ? 'Consultation emergency notice' : 'Lesson emergency notice',
            'message_html' => $message_html,
            'card_html'    => $card_html,
        ) );
        exit;
    }

    protected function render_parent_confirm_arrival_page( $lesson_id ) {
        $lesson = $this->get_lesson_with_instructor( $lesson_id );
        if ( ! is_array( $lesson ) || empty( $lesson ) ) {
            status_header( 404 );
            $this->render_safety_action_page( array(
                'eyebrow'      => 'Lesson Update',
                'title'        => 'Lesson Not Found',
                'message_html' => '<p>We could not locate the lesson connected to this link.</p>',
            ) );
        }

        $attendance = $this->ensure_attendance_row( $lesson_id );
        if ( empty( $attendance['parent_confirmed_arrival_at'] ) ) {
            $this->update_attendance_row( $lesson_id, array(
                'parent_confirmed_arrival_at' => current_time( 'mysql' ),
                'parent_confirmed_arrival_ip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : '',
            ) );
        }

        $this->mrm_safety_log( 'parent_confirmed_arrival_recorded', array(
            'lesson_id'     => (int) $lesson_id,
            'student_email' => (string) ( $lesson['student_email'] ?? '' ),
        ) );

        $this->send_parent_feedback_request_for_lesson( $lesson_id, false );

        $card_html  = '<div class="mrm-panel">';
        $card_html .= '<div><strong>Student:</strong> ' . esc_html( (string) ( $lesson['student_name'] ?? '' ) ) . '</div>';
        $card_html .= '<div><strong>Instructor:</strong> ' . esc_html( (string) ( $lesson['instructor_name'] ?? '' ) ) . '</div>';
        $card_html .= '<div><strong>Status:</strong> Arrival confirmed</div>';
        $card_html .= '</div>';

        $this->render_safety_action_page( array(
            'eyebrow'      => 'Arrival Confirmed',
            'title'        => 'Thank You',
            'message_html' => '<p>We have recorded that your instructor arrived for the lesson.</p><p>A follow-up feedback email has been sent so you can rate the lesson and leave comments.</p>',
            'card_html'    => $card_html,
        ) );
    }

    protected function render_parent_feedback_form_page( $lesson_id, $show_arrival_notice = false ) {
        $lesson = $this->get_lesson_with_instructor( $lesson_id );
        if ( ! is_array( $lesson ) || empty( $lesson ) ) {
            status_header( 404 );
            $this->render_safety_action_page( array(
                'eyebrow'      => 'Lesson Feedback',
                'title'        => 'Lesson Not Found',
                'message_html' => '<p>We could not locate the lesson connected to this link.</p>',
            ) );
        }

        $attendance = $this->ensure_attendance_row( $lesson_id );

        if ( ! empty( $attendance['feedback_submitted_at'] ) ) {
            $this->render_safety_action_page( array(
                'eyebrow'      => 'Lesson Feedback',
                'title'        => 'Feedback Already Submitted',
                'message_html' => '<p>Thank you. Your lesson feedback has already been recorded.</p>',
            ) );
        }

        $feedback_token = $this->mrm_safety_sign_token( $lesson_id, 'parent', 'feedback', time() + ( 24 * HOUR_IN_SECONDS ) );
        $submit_url = add_query_arg(
            array( 'action' => 'mrm_safety_feedback_submit' ),
            admin_url( 'admin-post.php' )
        );

        $arrival_notice = '';
        if ( $show_arrival_notice ) {
            $arrival_notice = '<div class="mrm-panel" style="margin-bottom:18px;"><strong>Arrival confirmed.</strong><br>We have recorded that your instructor arrived for the lesson.</div>';
        }

        $card_html = '
            <style>
                .mrm-rating-wrap{
                    margin:18px 0 6px;
                }
                .mrm-rating-label{
                    display:block;
                    margin-bottom:10px;
                    font-size:15px;
                    font-weight:700;
                }
                .mrm-star-rating{
                    direction:rtl;
                    display:inline-flex;
                    gap:4px;
                    justify-content:center;
                    width:100%;
                }
                .mrm-star-rating input{
                    display:none;
                }
                .mrm-star-rating label{
                    cursor:pointer;
                    font-size:40px;
                    line-height:1;
                    color:transparent;
                    -webkit-text-stroke:1.6px #c7b58a;
                    transition:transform .15s ease,color .15s ease,-webkit-text-stroke-color .15s ease;
                }
                .mrm-star-rating label:hover,
                .mrm-star-rating label:hover ~ label,
                .mrm-star-rating input:checked ~ label{
                    color:#d4a017;
                    -webkit-text-stroke-color:#d4a017;
                }
                .mrm-star-rating label:active{
                    transform:scale(.96);
                }
                .mrm-rating-help{
                    margin-top:10px;
                    text-align:center;
                    color:#6b6457;
                    font-size:13px;
                }
            </style>
            ' . $arrival_notice . '
            <div class="mrm-panel">
                <form method="post" action="' . esc_url( $submit_url ) . '">
                    <input type="hidden" name="token" value="' . esc_attr( $feedback_token ) . '">

                    <div class="mrm-rating-wrap">
                        <label class="mrm-rating-label">How was your lesson?</label>
                        <div class="mrm-star-rating" aria-label="Star rating">
                            <input type="radio" id="mrm-star-5" name="rating" value="5" required>
                            <label for="mrm-star-5" title="5 stars">★</label>

                            <input type="radio" id="mrm-star-4" name="rating" value="4" required>
                            <label for="mrm-star-4" title="4 stars">★</label>

                            <input type="radio" id="mrm-star-3" name="rating" value="3" required>
                            <label for="mrm-star-3" title="3 stars">★</label>

                            <input type="radio" id="mrm-star-2" name="rating" value="2" required>
                            <label for="mrm-star-2" title="2 stars">★</label>

                            <input type="radio" id="mrm-star-1" name="rating" value="1" required>
                            <label for="mrm-star-1" title="1 star">★</label>
                        </div>
                        <div class="mrm-rating-help">Tap a star to choose your rating.</div>
                    </div>

                    <div style="margin-top:22px;">
                        <label class="mrm-label">Comments</label>
                        <textarea name="comment" rows="6" placeholder="Share any feedback you would like us to see."></textarea>
                    </div>

                    <div class="mrm-form-actions">
                        <button type="submit" class="mrm-btn mrm-btn-primary">Submit Feedback</button>
                    </div>
                </form>
            </div>
        ';

        $this->render_safety_action_page( array(
            'eyebrow'      => 'Lesson Feedback',
            'title'        => 'How Was Your Lesson?',
            'message_html' => '<p>Please rate the lesson and share any comments you would like us to see.</p>',
            'card_html'    => $card_html,
            'footer_html'  => 'Your feedback helps us maintain a high-quality lesson experience.',
        ) );
    }

    protected function render_parent_no_show_page( $lesson_id ) {
        $lesson = $this->get_lesson_with_instructor( $lesson_id );
        if ( ! is_array( $lesson ) || empty( $lesson ) ) {
            status_header( 404 );
            $this->render_safety_action_page( array(
                'eyebrow'      => 'Lesson Update',
                'title'        => 'Lesson Not Found',
                'message_html' => '<p>We could not locate the lesson connected to this link.</p>',
            ) );
        }

        $attendance = $this->ensure_attendance_row( $lesson_id );

        if ( empty( $attendance['parent_no_show_reported_at'] ) ) {
            $this->update_attendance_row( $lesson_id, array(
                'parent_no_show_reported_at' => current_time( 'mysql' ),
                'parent_no_show_reported_ip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : '',
            ) );
        }

        if ( empty( $attendance['no_show_admin_notified_at'] ) ) {
            $sent = $this->send_parent_no_show_alert_for_lesson( $lesson_id, $lesson );
            if ( $sent ) {
                $this->update_attendance_row( $lesson_id, array(
                    'no_show_admin_notified_at' => current_time( 'mysql' ),
                ) );
            }
        }

        $this->mrm_safety_log( 'parent_no_show_reported', array(
            'lesson_id'     => (int) $lesson_id,
            'student_email' => (string) ( $lesson['student_email'] ?? '' ),
        ) );

        $card_html  = '<div class="mrm-panel">';
        $card_html .= '<div><strong>Student:</strong> ' . esc_html( (string) ( $lesson['student_name'] ?? '' ) ) . '</div>';
        $card_html .= '<div><strong>Instructor:</strong> ' . esc_html( (string) ( $lesson['instructor_name'] ?? '' ) ) . '</div>';
        $card_html .= '<div><strong>Status:</strong> Instructor did not arrive reported</div>';
        $card_html .= '</div>';

        $this->render_safety_action_page( array(
            'eyebrow'      => 'Safety Update',
            'title'        => 'Report Received',
            'message_html' => '<p>We have recorded that your instructor did not arrive for the lesson.</p><p>The site administrator has been notified.</p>',
            'card_html'    => $card_html,
        ) );
    }

    protected function send_parent_feedback_notifications( $lesson_id, $lesson, $rating, $comment ) {
        if ( ! is_array( $lesson ) || empty( $lesson ) ) {
            $this->mrm_safety_log( 'parent_feedback_notifications_skipped_missing_lesson', array( 'lesson_id' => (int) $lesson_id ) );
            return;
        }

        $admin_email      = $this->get_admin_notification_email();
        $instructor_email = sanitize_email( (string) ( $lesson['instructor_email'] ?? '' ) );
        $student_name     = (string) ( $lesson['student_name'] ?? '' );
        $instructor_name  = (string) ( $lesson['instructor_name'] ?? '' );
        $start_label      = wp_date( 'F j, Y \a\t g:i A', strtotime( (string) ( $lesson['start_time'] ?? '' ) ), wp_timezone() );

        $intro = '<p>Parent feedback has been submitted for a lesson.</p>';
        $details = '<div><strong>Lesson ID:</strong> ' . (int) $lesson_id . '</div>' . '<div><strong>Student:</strong> ' . esc_html( $student_name ) . '</div>' . '<div><strong>Instructor:</strong> ' . esc_html( $instructor_name ) . '</div>' . '<div><strong>Lesson time:</strong> ' . esc_html( $start_label ) . '</div>' . '<div><strong>Rating:</strong> ' . esc_html( str_repeat( '★', (int) $rating ) ) . ' (' . (int) $rating . '/5)</div>' . '<div style="margin-top:12px;"><strong>Comment:</strong><br>' . nl2br( esc_html( (string) $comment ) ) . '</div>';
        $html = $this->mrm_safety_email_wrap_html( 'Parent Lesson Feedback', $intro, $details );
        $headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>' );

        $admin_sent = false;
        $instructor_sent = false;
        if ( is_email( $admin_email ) ) { $admin_sent = wp_mail( $admin_email, 'Parent lesson feedback received', $html, $headers ); }
        if ( is_email( $instructor_email ) ) { $instructor_sent = wp_mail( $instructor_email, 'Parent lesson feedback received', $html, $headers ); }

        $this->mrm_safety_log( 'parent_feedback_notifications_sent', array(
            'lesson_id'         => (int) $lesson_id,
            'admin_email'       => $admin_email,
            'admin_sent'        => $admin_sent ? 'yes' : 'no',
            'instructor_email'  => $instructor_email,
            'instructor_sent'   => $instructor_sent ? 'yes' : 'no',
        ) );
    }

    public function cron_check_safety_exceptions() {
        global $wpdb;

        $this->mrm_safety_log( 'cron_check_safety_exceptions_entered', array(
            'current_time_mysql' => current_time( 'mysql' ),
            'current_time_ts'    => current_time( 'timestamp' ),
        ) );

        $lessons_table = $wpdb->prefix . 'mrm_lessons';
        $attendance_table = $this->table_attendance();
        $admin_email = $this->get_admin_notification_email();

        if ( ! is_email( $admin_email ) ) {
            $this->mrm_safety_log( 'exception_monitor_skipped_missing_admin_email', array() );
            return;
        }

        $arrival_rows = $wpdb->get_results(
            "SELECT l.*, a.*
             FROM {$lessons_table} l
             LEFT JOIN {$attendance_table} a ON a.lesson_id = l.id
             WHERE l.status = 'scheduled'
               AND l.start_time <= '" . esc_sql( date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 10 * MINUTE_IN_SECONDS ) ) ) . "'
               AND (a.instructor_arrived_at IS NULL OR a.instructor_arrived_at = '')
               AND (a.arrival_alert_sent_at IS NULL OR a.arrival_alert_sent_at = '')",
            ARRAY_A
        );

        $this->mrm_safety_log( 'arrival_exception_query_completed', array(
            'row_count' => is_array( $arrival_rows ) ? count( $arrival_rows ) : 0,
        ) );

        foreach ( (array) $arrival_rows as $row ) {
            $lesson_id = (int) ( $row['lesson_id'] ?? $row['id'] ?? 0 );
            if ( $lesson_id <= 0 ) continue;

            $this->send_safety_exception_email( 'arrival_missing', $row, $admin_email );
            $this->update_attendance_row( $lesson_id, array(
                'arrival_alert_sent_at' => current_time( 'mysql' ),
            ) );
        }

        $departure_rows = $wpdb->get_results(
            "SELECT l.*, a.*
             FROM {$lessons_table} l
             LEFT JOIN {$attendance_table} a ON a.lesson_id = l.id
             WHERE l.status IN ('scheduled','delivered')
               AND l.end_time <= '" . esc_sql( date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 60 * MINUTE_IN_SECONDS ) ) ) . "'
               AND a.instructor_arrived_at IS NOT NULL
               AND a.instructor_arrived_at <> ''
               AND (a.instructor_departed_at IS NULL OR a.instructor_departed_at = '')
               AND (a.departure_alert_sent_at IS NULL OR a.departure_alert_sent_at = '')",
            ARRAY_A
        );

        $this->mrm_safety_log( 'departure_exception_query_completed', array(
            'row_count' => is_array( $departure_rows ) ? count( $departure_rows ) : 0,
        ) );

        foreach ( (array) $departure_rows as $row ) {
            $lesson_id = (int) ( $row['lesson_id'] ?? $row['id'] ?? 0 );
            if ( $lesson_id <= 0 ) continue;

            $this->send_safety_exception_email( 'departure_missing', $row, $admin_email );
            $this->update_attendance_row( $lesson_id, array(
                'departure_alert_sent_at' => current_time( 'mysql' ),
            ) );
        }

        $this->mrm_safety_log( 'exception_monitor_finished', array(
            'arrival_alerts' => is_array( $arrival_rows ) ? count( $arrival_rows ) : 0,
            'departure_alerts' => is_array( $departure_rows ) ? count( $departure_rows ) : 0,
        ) );
    }

    protected function send_safety_exception_email( $type, $row, $admin_email ) {
        $lesson_id    = (int) ( $row['lesson_id'] ?? $row['id'] ?? 0 );
        $student_name = (string) ( $row['student_name'] ?? '' );
        $start_label  = wp_date( 'F j, Y \a\t g:i A', strtotime( (string) ( $row['start_time'] ?? '' ) ), wp_timezone() );
        $end_label    = wp_date( 'F j, Y \a\t g:i A', strtotime( (string) ( $row['end_time'] ?? '' ) ), wp_timezone() );

        if ( $type === 'arrival_missing' ) {
            $title = 'Safety alert — instructor has not checked in';
            $intro = '<p>An instructor has not marked arrival for a lesson by the expected threshold.</p>';
        } else {
            $title = 'Safety alert — instructor has not checked out';
            $intro = '<p>An instructor marked arrival but has not marked the lesson as ended by the expected threshold.</p>';
        }

        $details = '<div><strong>Lesson ID:</strong> ' . $lesson_id . '</div>' . '<div><strong>Student:</strong> ' . esc_html( $student_name ) . '</div>' . '<div><strong>Start:</strong> ' . esc_html( $start_label ) . '</div>' . '<div><strong>End:</strong> ' . esc_html( $end_label ) . '</div>';
        $html = $this->mrm_safety_email_wrap_html( $title, $intro, $details );

        $sent = wp_mail( $admin_email, $title, $html, array( 'Content-Type: text/html; charset=UTF-8', 'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>' ) );

        $this->mrm_safety_log( 'safety_exception_email_sent', array(
            'type'        => $type,
            'lesson_id'   => $lesson_id,
            'admin_email' => $admin_email,
            'sent'        => $sent ? 'yes' : 'no',
        ) );
    }

    public function admin_run_safety_reminder_sweep_now() {
        $this->mrm_safety_log( 'manual_reminder_sweep_handler_entered', array(
            'user_id' => get_current_user_id(),
            'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
        ) );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            $this->mrm_safety_log( 'manual_reminder_sweep_denied_capability', array(
                'user_id' => get_current_user_id(),
            ) );
            wp_die( 'You do not have permission to do that.' );
        }
        check_admin_referer( 'mrm_run_safety_reminder_sweep_now' );

        $this->mrm_safety_log( 'manual_reminder_sweep_trigger_started', array(
            'user_id' => get_current_user_id(),
        ) );

        $this->run_safety_reminder_sweep_now();

        $this->mrm_safety_log( 'manual_reminder_sweep_trigger_finished', array(
            'user_id' => get_current_user_id(),
        ) );

        wp_safe_redirect( admin_url( 'admin.php?page=mrm-scheduler-safety-attendance&manual_reminder_sweep=1' ) );
        exit;
    }

    public function admin_run_safety_exception_check_now() {
        $this->mrm_safety_log( 'manual_exception_check_handler_entered', array(
            'user_id' => get_current_user_id(),
            'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
        ) );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            $this->mrm_safety_log( 'manual_exception_check_denied_capability', array(
                'user_id' => get_current_user_id(),
            ) );
            wp_die( 'You do not have permission to do that.' );
        }
        check_admin_referer( 'mrm_run_safety_exception_check_now' );

        $this->mrm_safety_log( 'manual_exception_check_trigger_started', array(
            'user_id' => get_current_user_id(),
        ) );

        $this->run_safety_exception_check_now();

        $this->mrm_safety_log( 'manual_exception_check_trigger_finished', array(
            'user_id' => get_current_user_id(),
        ) );

        wp_safe_redirect( admin_url( 'admin.php?page=mrm-scheduler-safety-attendance&manual_exception_check=1' ) );
        exit;
    }

    public function admin_run_safety_feedback_request_now() {
        $this->mrm_safety_log( 'manual_feedback_request_handler_entered', array(
            'user_id' => get_current_user_id(),
            'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
        ) );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            $this->mrm_safety_log( 'manual_feedback_request_denied_capability', array(
                'user_id' => get_current_user_id(),
            ) );
            wp_die( 'You do not have permission to do that.' );
        }

        check_admin_referer( 'mrm_run_safety_feedback_request_now' );

        $this->mrm_safety_log( 'manual_feedback_request_trigger_started', array(
            'user_id' => get_current_user_id(),
        ) );

        $this->run_safety_feedback_request_now();

        $this->mrm_safety_log( 'manual_feedback_request_trigger_finished', array(
            'user_id' => get_current_user_id(),
        ) );

        wp_safe_redirect( admin_url( 'admin.php?page=mrm-scheduler-safety-attendance&manual_feedback_request=1' ) );
        exit;
    }

    public function render_admin_safety_attendance_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }

        global $wpdb;
        $lessons_table = $wpdb->prefix . 'mrm_lessons';
        $attendance_table = $this->table_attendance();
        $instructors_table = $wpdb->prefix . 'mrm_instructors';

        $rows = $wpdb->get_results(
            "SELECT l.id AS lesson_id,
                    l.student_name,
                    l.student_email,
                    l.start_time,
                    l.end_time,
                    l.status,
                    i.name AS instructor_name,
                    i.email AS instructor_email,
                    a.parent_reminder_sent_at,
                    a.instructor_reminder_sent_at,
                    a.instructor_arrived_at,
                    a.parent_confirmed_arrival_at,
                    a.instructor_departed_at,
                    a.parent_rating,
                    a.parent_comment,
                    a.feedback_submitted_at,
                    a.arrival_alert_sent_at,
                    a.departure_alert_sent_at
             FROM {$lessons_table} l
             LEFT JOIN {$attendance_table} a ON a.lesson_id = l.id
             LEFT JOIN {$instructors_table} i ON i.id = l.instructor_id
             ORDER BY l.start_time DESC
             LIMIT 500",
            ARRAY_A
        );

        echo '<div class="wrap"><h1>Safety Attendance</h1>';
        echo '<p>This chart shows instructor arrival/departure tracking, parent confirmations, feedback, and alert status.</p>';
        echo '<p style="margin:16px 0 24px 0;">';
        echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mrm_run_safety_reminder_sweep_now' ), 'mrm_run_safety_reminder_sweep_now' ) ) . '" class="button button-primary" style="margin-right:10px;">Run Safety Reminder Sweep Now</a>';
        echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mrm_run_safety_exception_check_now' ), 'mrm_run_safety_exception_check_now' ) ) . '" class="button" style="margin-right:10px;">Run Safety Exception Check Now</a>';
        echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mrm_run_safety_feedback_request_now' ), 'mrm_run_safety_feedback_request_now' ) ) . '" class="button">Run Safety Feedback Request Now</a>';
        echo '</p>';
        echo '<table class="widefat striped"><thead><tr>
        <th>Lesson ID</th>
        <th>Lesson Time</th>
        <th>Status</th>
        <th>Student</th>
        <th>Instructor</th>
        <th>Parent Reminder</th>
        <th>Instructor Reminder</th>
        <th>Instructor Arrived</th>
        <th>Parent Confirmed</th>
        <th>Instructor Ended</th>
        <th>Rating</th>
        <th>Comment</th>
        <th>Arrival Alert</th>
        <th>Departure Alert</th>
    </tr></thead><tbody>';

        foreach ( (array) $rows as $row ) {
            echo '<tr>';
            echo '<td>' . (int) $row['lesson_id'] . '</td>';
            echo '<td>' . esc_html( (string) $row['start_time'] ) . '<br><small>to ' . esc_html( (string) $row['end_time'] ) . '</small></td>';
            echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
            echo '<td>' . esc_html( (string) $row['student_name'] ) . '<br><small>' . esc_html( (string) $row['student_email'] ) . '</small></td>';
            echo '<td>' . esc_html( (string) $row['instructor_name'] ) . '<br><small>' . esc_html( (string) $row['instructor_email'] ) . '</small></td>';
            echo '<td>' . esc_html( (string) $row['parent_reminder_sent_at'] ) . '</td>';
            echo '<td>' . esc_html( (string) $row['instructor_reminder_sent_at'] ) . '</td>';
            echo '<td>' . esc_html( (string) $row['instructor_arrived_at'] ) . '</td>';
            echo '<td>' . esc_html( (string) $row['parent_confirmed_arrival_at'] ) . '</td>';
            echo '<td>' . esc_html( (string) $row['instructor_departed_at'] ) . '</td>';
            echo '<td>' . esc_html( (string) $row['parent_rating'] ) . '</td>';
            echo '<td>' . esc_html( mb_strimwidth( (string) $row['parent_comment'], 0, 80, '…' ) ) . '</td>';
            echo '<td>' . esc_html( (string) $row['arrival_alert_sent_at'] ) . '</td>';
            echo '<td>' . esc_html( (string) $row['departure_alert_sent_at'] ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }





    public function register_settings() {
        register_setting(
            'mrm_calculations_settings_group',
            'mrm_calculations_settings',
            array( $this, 'sanitize_calculations_settings' )
        );
    }

    public function sanitize_calculations_settings( $input ) {
        $input = is_array( $input ) ? $input : array();

        $tax_year = isset( $input['default_tax_year'] ) ? (int) $input['default_tax_year'] : (int) gmdate( 'Y' );
        $business_type = isset( $input['business_type'] ) ? sanitize_text_field( $input['business_type'] ) : 's_corp';

        $stripe_fee_percent = isset( $input['stripe_fee_percent'] )
            ? (float) $input['stripe_fee_percent']
            : 2.9;

        $stripe_fee_fixed_cents = isset( $input['stripe_fee_fixed_cents'] )
            ? (int) $input['stripe_fee_fixed_cents']
            : 30;

        return array(
            'default_tax_year'       => max( 2020, min( 2099, $tax_year ) ),
            'business_type'          => in_array( $business_type, array( 's_corp' ), true ) ? $business_type : 's_corp',
            'stripe_fee_percent'     => max( 0, min( 20, $stripe_fee_percent ) ),
            'stripe_fee_fixed_cents' => max( 0, min( 500, $stripe_fee_fixed_cents ) ),
        );
    }

    public function render_calculations_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = get_option( 'mrm_calculations_settings', array(
            'default_tax_year' => (int) gmdate( 'Y' ),
            'business_type'    => 's_corp',
        ) );

        $selected_year    = isset( $_GET['tax_year'] ) ? (int) $_GET['tax_year'] : (int) $settings['default_tax_year'];
        $selected_quarter = isset( $_GET['tax_quarter'] ) ? (int) $_GET['tax_quarter'] : 0;
        $environment_mode = $this->mrm_get_effective_calculations_environment_mode();
        $maps_secret_status = $this->mrm_get_google_maps_distance_secret_status();
        $maps_key_configured = ! empty( $maps_secret_status['configured'] );

        $overview    = $this->mrm_get_calculations_overview( $selected_year, $selected_quarter, $environment_mode );
        $instructors = $this->mrm_get_calculations_instructor_summary( $selected_year, $selected_quarter, $environment_mode );
        $composer    = $this->mrm_get_calculations_composer_summary( $selected_year, $selected_quarter, $environment_mode );
        $mileage     = $this->mrm_get_calculations_mileage_summary( $selected_year, $selected_quarter, $environment_mode );
        $expenses    = $this->mrm_get_calculations_expense_summary( $selected_year, $selected_quarter, $environment_mode );
        $payroll     = $this->mrm_get_calculations_payroll_summary( $selected_year, $selected_quarter, $environment_mode );

        ?>
        <div class="wrap">
            <h1>Calculations</h1>
            <?php if ( isset( $_GET['mileage_cleared'] ) ) : ?>
                <div class="notice notice-success"><p>Mileage cache was cleared for the selected period.</p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['mileage_recalculated'] ) ) : ?>
                <div class="notice notice-success"><p>Mileage cache was recalculated for the selected period. Lessons processed: <?php echo esc_html( isset( $_GET['mileage_count'] ) ? (string) absint( $_GET['mileage_count'] ) : '0' ); ?>.</p></div>
            <?php endif; ?>

            <?php if ( isset( $_GET['mileage_error'] ) ) : ?>
                <div class="notice notice-error"><p>Mileage recalculation could not run: <?php echo esc_html( sanitize_text_field( (string) $_GET['mileage_error'] ) ); ?></p></div>
            <?php endif; ?>
            <?php
            echo '<p><strong>Accounting Data Source:</strong> <span style="color:#166534;">Live records only</span></p>';

            echo '<p><strong>Google Maps Distance API:</strong> ';
            echo $maps_key_configured
                ? '<span style="color:#166534;">Configured through AWS Secrets Manager using <code>maps_distance_api_key</code></span>'
                : '<span style="color:#b32d2e;">Not found in AWS Secrets Manager under <code>maps_distance_api_key</code></span>';
            echo '</p>';

            echo '<p><strong>AWS Secret Loaded:</strong> ';
            echo ! empty( $maps_secret_status['loaded'] )
                ? '<span style="color:#166534;">Yes</span>'
                : '<span style="color:#b32d2e;">No</span>';
            echo '</p>';

            if ( ! empty( $maps_secret_status['top_level_keys'] ) ) {
                echo '<p><strong>AWS Keys Found:</strong> <code>' . esc_html( implode( ', ', $maps_secret_status['top_level_keys'] ) ) . '</code></p>';
            }

            if ( ! empty( $maps_secret_status['nested_keys'] ) ) {
                echo '<p><strong>Nested AWS Keys Found:</strong> <code>' . esc_html( implode( ', ', $maps_secret_status['nested_keys'] ) ) . '</code></p>';
            }

            $refresh_url = add_query_arg(
                array(
                    'page' => 'mrm-calculations',
                    'tax_year' => $selected_year,
                    'tax_quarter' => $selected_quarter,
                    'mrm_refresh_maps_secret' => '1',
                ),
                admin_url( 'admin.php' )
            );

            echo '<p><a class="button button-secondary" href="' . esc_url( $refresh_url ) . '">Refresh AWS Maps Secret Check</a></p>';
            ?>
            <?php echo '<p><em>This page provides calculation and reconciliation support for S-corporation recordkeeping, including contractor 1099 support, payroll/W-2 support, mileage, expenses, and annual business summaries. Final tax filing should still be reviewed by your accountant.</em></p>'; ?>
            <form method="post" action="options.php" style="margin:16px 0 24px; padding:14px; background:#fff; border:1px solid #ccd0d4;">
                <?php settings_fields( 'mrm_calculations_settings_group' ); ?>
                <h2>Calculation Settings</h2>
                <table class="form-table">
    <tr>
        <th scope="row"><label for="default_tax_year">Default Tax Year</label></th>
        <td>
            <input type="number" id="default_tax_year" name="mrm_calculations_settings[default_tax_year]" value="<?php echo esc_attr( (int) ( $settings['default_tax_year'] ?? gmdate( 'Y' ) ) ); ?>" min="2020" max="2099">
            <p class="description">Used as the default year when you open the Calculations submenu.</p>
        </td>
    </tr>
                        <tr>
                            <th scope="row"><label for="stripe_fee_percent">Estimated Stripe Fee Percent</label></th>
                            <td>
                                <input type="number" step="0.01" id="stripe_fee_percent" name="mrm_calculations_settings[stripe_fee_percent]" value="<?php echo esc_attr( (string) ( $settings['stripe_fee_percent'] ?? '2.9' ) ); ?>" style="width:100px;">
                                <span>%</span>
                                <p class="description">Used for estimated Stripe fee calculations when actual Stripe balance transaction fees are not stored locally.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="stripe_fee_fixed_cents">Estimated Stripe Fixed Fee</label></th>
                            <td>
                                <input type="number" id="stripe_fee_fixed_cents" name="mrm_calculations_settings[stripe_fee_fixed_cents]" value="<?php echo esc_attr( (string) ( $settings['stripe_fee_fixed_cents'] ?? '30' ) ); ?>" style="width:100px;">
                                <span>cents per paid transaction</span>
                                <p class="description">For standard Stripe card pricing, this is commonly 30 cents, but use your actual Stripe pricing if different.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Google Maps Distance API Key</th>
        <td>
            <?php if ( $maps_key_configured ) : ?>
                <strong style="color:#166534;">Configured in AWS Secrets Manager</strong>
            <?php else : ?>
                <strong style="color:#b32d2e;">Not found in AWS Secrets Manager</strong>
            <?php endif; ?>
            <p class="description">
                Expected AWS secret:
                <code><?php echo esc_html( defined( 'MRM_SECRET_GOOGLE_SCHEDULER' ) ? MRM_SECRET_GOOGLE_SCHEDULER : 'lowbrass/google/scheduler' ); ?></code>.
                Required key:
                <code>maps_distance_api_key</code>.
            </p>
        </td>
    </tr>
</table>
                <?php submit_button( 'Save Calculation Settings' ); ?>
            </form>

            <form method="get" style="margin:16px 0 24px 0;">
                <input type="hidden" name="page" value="mrm-calculations">
                
                <label for="tax_year"><strong>Tax Year</strong></label>
                <input type="number" id="tax_year" name="tax_year" value="<?php echo esc_attr( $selected_year ); ?>" min="2020" max="2099" style="width:100px; margin:0 12px 0 8px;">

                <label for="tax_quarter"><strong>Quarter</strong></label>
                <select id="tax_quarter" name="tax_quarter">
                    <option value="0" <?php selected( $selected_quarter, 0 ); ?>>Full Year</option>
                    <option value="1" <?php selected( $selected_quarter, 1 ); ?>>Q1</option>
                    <option value="2" <?php selected( $selected_quarter, 2 ); ?>>Q2</option>
                    <option value="3" <?php selected( $selected_quarter, 3 ); ?>>Q3</option>
                    <option value="4" <?php selected( $selected_quarter, 4 ); ?>>Q4</option>
                </select>

                

                <button type="submit" class="button button-primary" style="margin-left:12px;">Run Calculations</button>

                
            </form>

            <p>
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mrm_export_1099_support&tax_year=' . $selected_year . '&tax_quarter=' . $selected_quarter ), 'mrm_export_1099_support' ) ); ?>">Export Instructor CSV</a>
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mrm_export_mileage_summary&tax_year=' . $selected_year . '&tax_quarter=' . $selected_quarter ), 'mrm_export_mileage_summary' ) ); ?>">Export Mileage CSV</a>
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mrm_export_calculations_summary&tax_year=' . $selected_year . '&tax_quarter=' . $selected_quarter ), 'mrm_export_calculations_summary' ) ); ?>">Export Annual Summary CSV</a>
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mrm_recalculate_mileage_cache&tax_year=' . $selected_year . '&tax_quarter=' . $selected_quarter ), 'mrm_recalculate_mileage_cache' ) ); ?>">Recalculate Mileage</a>
                <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mrm_clear_mileage_cache_for_period&tax_year=' . $selected_year . '&tax_quarter=' . $selected_quarter ), 'mrm_clear_mileage_cache_for_period' ) ); ?>" onclick="return confirm('Clear mileage cache rows for the selected year/quarter? You can rebuild them with Recalculate Mileage.');">Clear Mileage Cache</a>
            <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mrm_clear_all_mileage_cache' ), 'mrm_clear_all_mileage_cache' ) ); ?>" onclick="return confirm('This will clear ALL instructor mileage cache rows for all years. You can rebuild selected years with Recalculate Mileage. Continue?');">Clear All Mileage Cache</a>
            </p>

            <h2>Overview</h2>
            <table class="widefat striped">
                <tbody>
                    <tr><td>Lesson Revenue</td><td><?php echo esc_html( number_format( (float) $overview['lesson_revenue'], 2 ) ); ?></td></tr>
                    <tr><td>Sheet Music Revenue</td><td><?php echo esc_html( number_format( (float) $overview['sheet_music_revenue'], 2 ) ); ?></td></tr>
                    <tr><td>Gross Revenue</td><td><?php echo esc_html( number_format( (float) $overview['gross_revenue'], 2 ) ); ?></td></tr>
                    <tr><td>Refunds</td><td><?php echo esc_html( number_format( (float) $overview['refunds'], 2 ) ); ?></td></tr>
                    <tr><td>Estimated Stripe Fees</td><td><?php echo esc_html( number_format( (float) $overview['stripe_fees'], 2 ) ); ?> <small>Calculated from your saved Stripe fee settings, not exact Stripe balance transactions.</small></td></tr>
                    <tr><td>Instructor Wages</td><td><?php echo esc_html( number_format( (float) $overview['instructor_wages'], 2 ) ); ?></td></tr>
                    <tr><td>Composer Wages</td><td><?php echo esc_html( number_format( (float) $overview['composer_wages'], 2 ) ); ?></td></tr>
                    <tr><td>Manual Expenses</td><td><?php echo esc_html( number_format( (float) $overview['manual_expenses'], 2 ) ); ?></td></tr>
                    <tr><td>Payroll / W-2 Wages</td><td><?php echo esc_html( number_format( (float) $overview['payroll_wages'], 2 ) ); ?></td></tr>
                    
                    <tr><td>Estimated Net Income</td><td><strong><?php echo esc_html( number_format( (float) $overview['estimated_net_income'], 2 ) ); ?></strong></td></tr>
                </tbody>
            </table>

            <h2 style="margin-top:28px;">Instructor Wage Summary</h2>
            <?php $this->mrm_render_calculations_instructor_table( $instructors ); ?>
            <h2 style="margin-top:28px;">Composer Sheet Music Wage Summary</h2>
            <?php $this->mrm_render_calculations_composer_table( $composer ); ?>

            <h2 style="margin-top:28px;">Payroll / Officer Compensation Summary</h2>
            <?php $this->mrm_render_calculations_payroll_table( $payroll ); ?>

            <h2 style="margin-top:28px;">Instructor Mileage Support</h2>
            <p>This section estimates round-trip driving miles for in-person lessons so instructors have mileage support records. It is not treated as a company mileage deduction.</p>
            <p class="description">Rows are grouped by instructor and origin address. If the same instructor appears more than once, that means the mileage cache contains more than one origin address for that instructor during the selected period.</p>
            <?php $this->mrm_render_calculations_mileage_table( $mileage ); ?>

            <h2 style="margin-top:28px;">Expense Summary</h2>
            <?php $this->mrm_render_calculations_expense_table( $expenses ); ?>
        </div>
        <?php
    }

    

protected function mrm_normalize_secret_key_name( $key ) {
    $key = is_string( $key ) ? $key : '';
    $key = trim( $key );
    $key = strtolower( $key );
    $key = preg_replace( '/[^a-z0-9]+/', '_', $key );
    $key = trim( $key, '_' );

    return $key;
}

protected function mrm_find_secret_value_by_normalized_key( $secret, $target_keys ) {
    if ( ! is_array( $secret ) ) {
        return '';
    }

    $normalized_targets = array();

    foreach ( (array) $target_keys as $target ) {
        $normalized_targets[] = $this->mrm_normalize_secret_key_name( $target );
    }

    $normalized_targets = array_unique( array_filter( $normalized_targets ) );

    foreach ( $secret as $raw_key => $value ) {
        $normalized_key = $this->mrm_normalize_secret_key_name( (string) $raw_key );

        if ( in_array( $normalized_key, $normalized_targets, true ) ) {
            if ( is_string( $value ) && trim( $value ) !== '' ) {
                return trim( $value );
            }

            if ( is_numeric( $value ) ) {
                return trim( (string) $value );
            }
        }

        // Support one level of nesting, just in case the AWS secret was saved as a nested object.
        if ( is_array( $value ) ) {
            foreach ( $value as $nested_raw_key => $nested_value ) {
                $nested_normalized_key = $this->mrm_normalize_secret_key_name( (string) $nested_raw_key );

                if ( in_array( $nested_normalized_key, $normalized_targets, true ) ) {
                    if ( is_string( $nested_value ) && trim( $nested_value ) !== '' ) {
                        return trim( $nested_value );
                    }

                    if ( is_numeric( $nested_value ) ) {
                        return trim( (string) $nested_value );
                    }
                }
            }
        }
    }

    return '';
}

protected function mrm_get_google_maps_distance_api_key() {
    $secret = $this->mrm_get_google_scheduler_secret_bundle();

    if ( is_array( $secret ) && ! empty( $secret['maps_distance_api_key'] ) ) {
        return trim( (string) $secret['maps_distance_api_key'] );
    }

    $this->mrm_aws_debug_log( 'Scheduler missing AWS maps_distance_api_key. AWS-only mode active.', array(
        'secret_id' => defined( 'MRM_SECRET_GOOGLE_SCHEDULER' ) ? MRM_SECRET_GOOGLE_SCHEDULER : 'lowbrass/google/scheduler',
        'expected_key' => 'maps_distance_api_key',
        'available_normalized_keys' => is_array( $secret ) ? array_keys( $secret ) : array(),
    ) );

    return '';
}

protected function mrm_google_maps_distance_api_key_is_configured() {
    return $this->mrm_get_google_maps_distance_api_key() !== '';
}

protected function mrm_get_google_maps_distance_secret_status() {
    if ( isset( $_GET['mrm_refresh_maps_secret'] ) && current_user_can( 'manage_options' ) ) {
        delete_transient( 'mrm_secret_google_scheduler_v5' );
        delete_transient( 'mrm_secret_google_scheduler_maps_distance_v1' );
        delete_transient( 'mrm_secret_google_scheduler_maps_distance_v2' );
    }

    $secret = $this->mrm_get_google_scheduler_secret_bundle();

    $secret_id = defined( 'MRM_SECRET_GOOGLE_SCHEDULER' )
        ? MRM_SECRET_GOOGLE_SCHEDULER
        : 'lowbrass/google/scheduler';

    $raw_keys = array();

    if ( is_array( $secret ) && ! empty( $secret['_raw_keys'] ) && is_array( $secret['_raw_keys'] ) ) {
        $raw_keys = $secret['_raw_keys'];
    } elseif ( is_array( $secret ) ) {
        $raw_keys = array_keys( $secret );
    }

    return array(
        'secret_id'      => $secret_id,
        'loaded'         => is_array( $secret ),
        'configured'     => is_array( $secret ) && ! empty( $secret['maps_distance_api_key'] ),
        'top_level_keys' => $raw_keys,
        'nested_keys'    => array(),
    );
}


protected function mrm_get_effective_calculations_environment_mode() {
    return 'live';
}

    protected function mrm_get_tax_period_dates( $tax_year, $tax_quarter = 0 ) {
    $tax_year = max( 2020, min( 2099, (int) $tax_year ) );
    $tax_quarter = (int) $tax_quarter;

    if ( $tax_quarter === 1 ) {
        return array( "{$tax_year}-01-01 00:00:00", "{$tax_year}-03-31 23:59:59" );
    }

    if ( $tax_quarter === 2 ) {
        return array( "{$tax_year}-04-01 00:00:00", "{$tax_year}-06-30 23:59:59" );
    }

    if ( $tax_quarter === 3 ) {
        return array( "{$tax_year}-07-01 00:00:00", "{$tax_year}-09-30 23:59:59" );
    }

    if ( $tax_quarter === 4 ) {
        return array( "{$tax_year}-10-01 00:00:00", "{$tax_year}-12-31 23:59:59" );
    }

    return array( "{$tax_year}-01-01 00:00:00", "{$tax_year}-12-31 23:59:59" );
}

protected function mrm_table_exists( $table ) {
    global $wpdb;
    return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
}

protected function mrm_get_calculations_overview( $tax_year, $tax_quarter = 0, $environment_mode = 'live' ) {
    $lesson_revenue      = $this->mrm_calc_order_revenue_by_product( $tax_year, $tax_quarter, $environment_mode, 'lesson' );
    $sheet_music_revenue = $this->mrm_calc_order_revenue_by_product( $tax_year, $tax_quarter, $environment_mode, 'sheet_music' );
    $gross_revenue       = $lesson_revenue + $sheet_music_revenue;

    $refunds             = $this->mrm_calc_total_refunds( $tax_year, $tax_quarter, $environment_mode );
    $stripe_fees         = $this->mrm_calc_estimated_stripe_fees( $tax_year, $tax_quarter, $environment_mode );
    $instructor_wages    = $this->mrm_calc_payout_total_by_payee_type( $tax_year, $tax_quarter, $environment_mode, 'instructor' );
    $composer_wages      = $this->mrm_calc_payout_total_by_payee_type( $tax_year, $tax_quarter, $environment_mode, 'composer' );
    $manual_expenses     = $this->mrm_calc_total_manual_expenses( $tax_year, $tax_quarter, $environment_mode );
    $payroll_wages       = $this->mrm_calc_total_payroll_wages( $tax_year, $tax_quarter, $environment_mode );
    $estimated_net_income = $gross_revenue
        - $refunds
        - $stripe_fees
        - $instructor_wages
        - $composer_wages
        - $manual_expenses
        - $payroll_wages;

    return array(
        'lesson_revenue'        => $lesson_revenue,
        'sheet_music_revenue'   => $sheet_music_revenue,
        'gross_revenue'         => $gross_revenue,
        'refunds'               => $refunds,
        'stripe_fees'           => $stripe_fees,
        'instructor_wages'      => $instructor_wages,
        'composer_wages'        => $composer_wages,
        'manual_expenses'       => $manual_expenses,
        'payroll_wages'         => $payroll_wages,
                'estimated_net_income'  => $estimated_net_income,
    );
}

protected function mrm_calc_order_revenue_by_product( $tax_year, $tax_quarter = 0, $environment_mode = 'live', $product_type = '' ) {
    global $wpdb;

    list( $start, $end ) = $this->mrm_get_tax_period_dates( $tax_year, $tax_quarter );
    $orders_table = $wpdb->prefix . 'mrm_orders';

    if ( ! $this->mrm_table_exists( $orders_table ) ) {
        return 0.0;
    }

    $sql = $wpdb->prepare(
        "SELECT COALESCE(SUM(amount_cents),0)
         FROM {$orders_table}
         WHERE status = 'paid'
           AND environment_mode = %s
           AND product_type = %s
           AND created_at >= %s
           AND created_at <= %s",
        $environment_mode,
        $product_type,
        $start,
        $end
    );

    return round( (float) $wpdb->get_var( $sql ) / 100, 2 );
}

protected function mrm_calc_total_refunds( $tax_year, $tax_quarter = 0, $environment_mode = 'live' ) {
    global $wpdb;

    list( $start, $end ) = $this->mrm_get_tax_period_dates( $tax_year, $tax_quarter );
    $orders_table = $wpdb->prefix . 'mrm_orders';

    if ( ! $this->mrm_table_exists( $orders_table ) ) {
        return 0.0;
    }

    $sql = $wpdb->prepare(
        "SELECT COALESCE(SUM(amount_cents),0)
         FROM {$orders_table}
         WHERE status = 'refunded'
           AND environment_mode = %s
           AND updated_at >= %s
           AND updated_at <= %s",
        $environment_mode,
        $start,
        $end
    );

    return round( (float) $wpdb->get_var( $sql ) / 100, 2 );
}

protected function mrm_calc_estimated_stripe_fees( $tax_year, $tax_quarter = 0, $environment_mode = 'live' ) {
    global $wpdb;

    list( $start, $end ) = $this->mrm_get_tax_period_dates( $tax_year, $tax_quarter );
    $orders_table = $wpdb->prefix . 'mrm_orders';

    if ( ! $this->mrm_table_exists( $orders_table ) ) {
        return 0.0;
    }

    $settings = get_option( 'mrm_calculations_settings', array() );
    $percent = isset( $settings['stripe_fee_percent'] ) ? (float) $settings['stripe_fee_percent'] : 2.9;
    $fixed_cents = isset( $settings['stripe_fee_fixed_cents'] ) ? (int) $settings['stripe_fee_fixed_cents'] : 30;

    $amounts = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT amount_cents
             FROM {$orders_table}
             WHERE status = 'paid'
               AND environment_mode = %s
               AND created_at >= %s
               AND created_at <= %s",
            $environment_mode,
            $start,
            $end
        )
    );

    $total_fee_cents = 0;

    foreach ( (array) $amounts as $amount_cents ) {
        $amount_cents = max( 0, (int) $amount_cents );

        if ( $amount_cents <= 0 ) {
            continue;
        }

        $fee_cents = (int) round( $amount_cents * ( $percent / 100 ) ) + $fixed_cents;
        $total_fee_cents += max( 0, $fee_cents );
    }

    return round( $total_fee_cents / 100, 2 );
}

protected function mrm_calc_payout_total_by_payee_type( $tax_year, $tax_quarter = 0, $environment_mode = 'live', $payee_type = '' ) {
    global $wpdb;

    list( $start, $end ) = $this->mrm_get_tax_period_dates( $tax_year, $tax_quarter );
    $table = $wpdb->prefix . 'mrm_payout_ledger';

    if ( ! $this->mrm_table_exists( $table ) ) {
        return 0.0;
    }

    $sql = $wpdb->prepare(
        "SELECT COALESCE(SUM(net_cents),0)
         FROM {$table}
         WHERE payee_type = %s
           AND environment_mode = %s
           AND status IN ('pending','transferred','paid','payout_paid')
           AND created_at >= %s
           AND created_at <= %s",
        $payee_type,
        $environment_mode,
        $start,
        $end
    );

    return round( (float) $wpdb->get_var( $sql ) / 100, 2 );
}

protected function mrm_calc_total_manual_expenses( $tax_year, $tax_quarter = 0, $environment_mode = 'live' ) {
    global $wpdb;

    $table = $wpdb->prefix . 'mrm_tax_manual_expenses';

    if ( ! $this->mrm_table_exists( $table ) ) {
        return 0.0;
    }

    if ( (int) $tax_quarter > 0 ) {
        $sql = $wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0)
             FROM {$table}
             WHERE tax_year = %d
               AND tax_quarter = %d
               AND environment_mode = %s",
            $tax_year,
            $tax_quarter,
            $environment_mode
        );
    } else {
        $sql = $wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0)
             FROM {$table}
             WHERE tax_year = %d
               AND environment_mode = %s",
            $tax_year,
            $environment_mode
        );
    }

    return round( (float) $wpdb->get_var( $sql ), 2 );
}

protected function mrm_calc_total_payroll_wages( $tax_year, $tax_quarter = 0, $environment_mode = 'live' ) {
    global $wpdb;

    $table = $wpdb->prefix . 'mrm_tax_payroll_imports';

    if ( ! $this->mrm_table_exists( $table ) ) {
        return 0.0;
    }

    if ( (int) $tax_quarter > 0 ) {
        $sql = $wpdb->prepare(
            "SELECT COALESCE(SUM(gross_wages),0)
             FROM {$table}
             WHERE tax_year = %d
               AND tax_quarter = %d
               AND environment_mode = %s",
            $tax_year,
            $tax_quarter,
            $environment_mode
        );
    } else {
        $sql = $wpdb->prepare(
            "SELECT COALESCE(SUM(gross_wages),0)
             FROM {$table}
             WHERE tax_year = %d
               AND environment_mode = %s",
            $tax_year,
            $environment_mode
        );
    }

    return round( (float) $wpdb->get_var( $sql ), 2 );
}

protected function mrm_calc_total_mileage_deduction( $tax_year, $tax_quarter = 0, $environment_mode = 'live' ) {
    global $wpdb;

    $table = $wpdb->prefix . 'mrm_tax_mileage_cache';

    if ( ! $this->mrm_table_exists( $table ) ) {
        return 0.0;
    }

    if ( (int) $tax_quarter > 0 ) {
        $sql = $wpdb->prepare(
            "SELECT COALESCE(SUM(mileage_deduction),0)
             FROM {$table}
             WHERE tax_year = %d
               AND QUARTER(trip_date) = %d
               AND environment_mode = %s",
            $tax_year,
            $tax_quarter,
            $environment_mode
        );
    } else {
        $sql = $wpdb->prepare(
            "SELECT COALESCE(SUM(mileage_deduction),0)
             FROM {$table}
             WHERE tax_year = %d
               AND environment_mode = %s",
            $tax_year,
            $environment_mode
        );
    }

    return round( (float) $wpdb->get_var( $sql ), 2 );
}

protected function mrm_get_calculations_instructor_summary( $tax_year, $tax_quarter = 0, $environment_mode = 'live' ) {
    global $wpdb;

    list( $start, $end ) = $this->mrm_get_tax_period_dates( $tax_year, $tax_quarter );

    $payouts = $wpdb->prefix . 'mrm_payout_ledger';
    $lessons = $wpdb->prefix . 'mrm_lessons';
    $instructors = $wpdb->prefix . 'mrm_instructors';

    if ( ! $this->mrm_table_exists( $payouts ) ) {
        return array();
    }

    $join_sql = '';
    $select_instructor_id = "0 AS instructor_id";
    $select_instructor_name = "p.payee_ref AS instructor_name";
    $select_instructor_email = "'' AS instructor_email";

    if ( $this->mrm_table_exists( $lessons ) && $this->mrm_table_exists( $instructors ) ) {
        $join_sql = "
            LEFT JOIN {$lessons} l ON p.payee_ref = CONCAT('lesson:', l.id)
            LEFT JOIN {$instructors} i ON i.id = l.instructor_id
        ";
        $select_instructor_id = "COALESCE(l.instructor_id, 0) AS instructor_id";
        $select_instructor_name = "COALESCE(MAX(i.name), p.payee_ref, 'Unknown instructor') AS instructor_name";
        $select_instructor_email = "COALESCE(MAX(i.email), '') AS instructor_email";
    }

    $sql = $wpdb->prepare(
        "SELECT
            {$select_instructor_id},
            {$select_instructor_name},
            {$select_instructor_email},
            COUNT(*) AS payout_count,
            COALESCE(SUM(p.gross_cents),0) AS gross_cents,
            COALESCE(SUM(p.net_cents),0) AS net_cents
         FROM {$payouts} p
         {$join_sql}
         WHERE p.payee_type = 'instructor'
           AND p.environment_mode = %s
           AND p.status IN ('pending','transferred','paid','payout_paid')
           AND p.created_at >= %s
           AND p.created_at <= %s
         GROUP BY instructor_id, p.payee_ref
         ORDER BY net_cents DESC",
        $environment_mode,
        $start,
        $end
    );

    return $wpdb->get_results( $sql, ARRAY_A );
}

protected function mrm_get_calculations_composer_summary( $tax_year, $tax_quarter = 0, $environment_mode = 'live' ) {
    global $wpdb;

    list( $start, $end ) = $this->mrm_get_tax_period_dates( $tax_year, $tax_quarter );
    $payouts = $wpdb->prefix . 'mrm_payout_ledger';

    if ( ! $this->mrm_table_exists( $payouts ) ) {
        return array();
    }

    $sql = $wpdb->prepare(
        "SELECT
            COALESCE(payee_ref, 'composer') AS payee_ref,
            COUNT(*) AS payout_count,
            COALESCE(SUM(gross_cents),0) AS gross_cents,
            COALESCE(SUM(net_cents),0) AS net_cents,
            GROUP_CONCAT(DISTINCT notes SEPARATOR '; ') AS notes
         FROM {$payouts}
         WHERE payee_type = 'composer'
           AND environment_mode = %s
           AND status IN ('pending','transferred','paid','payout_paid')
           AND created_at >= %s
           AND created_at <= %s
         GROUP BY payee_ref
         ORDER BY net_cents DESC",
        $environment_mode,
        $start,
        $end
    );

    return $wpdb->get_results( $sql, ARRAY_A );
}

protected function mrm_get_calculations_mileage_summary( $tax_year, $tax_quarter = 0, $environment_mode = 'live' ) {
    global $wpdb;

    $table = $wpdb->prefix . 'mrm_tax_mileage_cache';
    $instructors = $wpdb->prefix . 'mrm_instructors';

    if ( ! $this->mrm_table_exists( $table ) ) {
        return array();
    }

    $select_instructor = "
        '' AS instructor_name,
        '' AS current_instructor_address
    ";
    $join_sql = '';

    if ( $this->mrm_table_exists( $instructors ) ) {
        $select_instructor = "
            MAX(i.name) AS instructor_name,
            TRIM(CONCAT_WS(', ',
                NULLIF(MAX(i.address), ''),
                NULLIF(MAX(i.city), ''),
                NULLIF(TRIM(CONCAT_WS(' ', NULLIF(MAX(i.state), ''), NULLIF(MAX(i.zip_code), ''))), ''),
                'USA'
            )) AS current_instructor_address
        ";
        $join_sql = "LEFT JOIN {$instructors} i ON i.id = m.instructor_id";
    }

    $period_where = "m.tax_year = %d";
    $args = array( $tax_year );

    if ( (int) $tax_quarter > 0 ) {
        $period_where = "m.tax_year = %d AND QUARTER(m.trip_date) = %d";
        $args = array( $tax_year, (int) $tax_quarter );
    }

    $sql = $wpdb->prepare(
        "SELECT
            m.instructor_id,
            {$select_instructor},
            COUNT(*) AS lesson_count,
            COALESCE(SUM(m.round_trip_miles),0) AS total_miles,
            GROUP_CONCAT(DISTINCT NULLIF(m.origin_address, '') SEPARATOR ' | ') AS cached_origin_addresses,
            GROUP_CONCAT(DISTINCT NULLIF(m.destination_address, '') SEPARATOR ' | ') AS destination_addresses,
            GROUP_CONCAT(DISTINCT m.calc_status ORDER BY m.calc_status SEPARATOR ', ') AS calc_statuses,
            GROUP_CONCAT(DISTINCT NULLIF(m.calc_error_message, '') SEPARATOR ' | ') AS calc_error_messages,
            COUNT(DISTINCT NULLIF(m.origin_address, '')) AS cached_origin_variant_count
         FROM {$table} m
         {$join_sql}
         WHERE {$period_where}
         GROUP BY m.instructor_id
         ORDER BY instructor_name ASC, m.instructor_id ASC",
        $args
    );

    return $wpdb->get_results( $sql, ARRAY_A );
}

protected function mrm_render_calculations_mileage_table( $rows ) {
    echo '<table class="widefat striped"><thead><tr><th>Instructor</th><th>Current Instructor Origin</th><th>In-Person Lessons</th><th>Total Round-Trip Miles</th><th>Destinations Sent to Google</th><th>Status</th><th>Details</th><th>Cached Origins Found</th></tr></thead><tbody>';

    if ( empty( $rows ) ) {
        echo '<tr><td colspan="8">No mileage data found.</td></tr>';
    } else {
        foreach ( $rows as $row ) {
            $current_origin = (string) ( $row['current_instructor_address'] ?? '' );
            $cached_origins = (string) ( $row['cached_origin_addresses'] ?? '' );

            echo '<tr>';
            echo '<td>' . esc_html( (string) ( $row['instructor_name'] ?? ( 'Instructor ID ' . ( $row['instructor_id'] ?? '' ) ) ) ) . '<br><small>ID: ' . esc_html( (string) ( $row['instructor_id'] ?? '' ) ) . '</small></td>';
            echo '<td style="max-width:280px;">' . esc_html( $current_origin ) . '</td>';
            echo '<td>' . esc_html( (string) ( $row['lesson_count'] ?? 0 ) ) . '</td>';
            echo '<td>' . esc_html( number_format( (float) ( $row['total_miles'] ?? 0 ), 2 ) ) . '</td>';
            echo '<td style="max-width:320px;">' . esc_html( (string) ( $row['destination_addresses'] ?? '' ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $row['calc_statuses'] ?? '' ) ) . '</td>';
            echo '<td style="max-width:420px;">' . esc_html( (string) ( $row['calc_error_messages'] ?? '' ) ) . '</td>';
            echo '<td style="max-width:320px;">' . esc_html( $cached_origins ) . '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
}

protected function mrm_format_current_instructor_address_from_row( $row ) {
    $street = trim( (string) ( $row['address'] ?? '' ) );
    $city   = trim( (string) ( $row['city'] ?? '' ) );
    $state  = trim( (string) ( $row['state'] ?? '' ) );
    $zip    = trim( (string) ( $row['zip_code'] ?? '' ) );

    $state_zip = trim( $state . ' ' . $zip );

    return trim( implode( ', ', array_filter( array(
        $street,
        $city,
        $state_zip,
        'USA',
    ) ) ) );
}

protected function mrm_format_instructor_origin_address( $lesson ) {
    $street = trim( (string) ( $lesson['instructor_address'] ?? '' ) );
    $city   = trim( (string) ( $lesson['instructor_city'] ?? '' ) );
    $state  = trim( (string) ( $lesson['instructor_state'] ?? '' ) );
    $zip    = trim( (string) ( $lesson['instructor_zip_code'] ?? '' ) );

    $state_zip = trim( $state . ' ' . $zip );

    return trim( implode( ', ', array_filter( array(
        $street,
        $city,
        $state_zip,
        'USA',
    ) ) ) );
}

protected function mrm_format_lesson_destination_address( $lesson ) {
    $street = trim( (string) ( $lesson['address'] ?? '' ) );
    $city   = trim( (string) ( $lesson['address_city'] ?? '' ) );
    $state  = trim( (string) ( $lesson['address_state'] ?? '' ) );
    $postal = trim( (string) ( $lesson['address_postal'] ?? '' ) );

    $state_postal = trim( $state . ' ' . $postal );

    return trim( implode( ', ', array_filter( array(
        $street,
        $city,
        $state_postal,
        'USA',
    ) ) ) );
}

protected function mrm_calculate_driving_distance_miles( $origin_address, $destination_address ) {
    $api_key = $this->mrm_get_google_maps_distance_api_key();

    if ( $api_key === '' ) {
        return new WP_Error( 'missing_api_key', 'Google Maps Distance API key is missing from AWS Secrets Manager.' );
    }

    $url = add_query_arg(
        array(
            'origins'      => $origin_address,
            'destinations' => $destination_address,
            'units'        => 'imperial',
            'key'          => $api_key,
        ),
        'https://maps.googleapis.com/maps/api/distancematrix/json'
    );

    $response = wp_remote_get( $url, array( 'timeout' => 15 ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $http_code = (int) wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    $json = json_decode( $body, true );

    if ( $http_code < 200 || $http_code >= 300 ) {
        return new WP_Error(
            'distance_http_error',
            'Google Distance Matrix HTTP error ' . $http_code . '.'
        );
    }

    if ( ! is_array( $json ) ) {
        return new WP_Error(
            'distance_json_error',
            'Google Distance Matrix returned invalid JSON.'
        );
    }

    $top_status = (string) ( $json['status'] ?? '' );

    if ( $top_status !== 'OK' ) {
        $message = (string) ( $json['error_message'] ?? '' );

        if ( $message === '' ) {
            $message = 'Google Distance Matrix returned status: ' . $top_status . '.';
        } else {
            $message = 'Google Distance Matrix returned status: ' . $top_status . '. ' . $message;
        }

        return new WP_Error( 'distance_api_error', $message );
    }

    $element = $json['rows'][0]['elements'][0] ?? null;

    if ( ! is_array( $element ) ) {
        return new WP_Error(
            'distance_not_found',
            'Google Distance Matrix did not return a route element for these addresses.'
        );
    }

    $element_status = (string) ( $element['status'] ?? '' );

    if ( $element_status !== 'OK' ) {
        return new WP_Error(
            'distance_not_found',
            'Google Distance Matrix element status: ' . $element_status . '.'
        );
    }

    $meters = isset( $element['distance']['value'] ) ? (float) $element['distance']['value'] : 0.0;

    if ( $meters <= 0 ) {
        return new WP_Error( 'distance_zero', 'Distance returned zero.' );
    }

    return $meters * 0.000621371;
}

protected function mrm_queue_mileage_calculation_for_lesson( $lesson_id ) {
    global $wpdb;

    $lesson = $this->get_lesson_with_instructor( $lesson_id );

    if ( ! is_array( $lesson ) || empty( $lesson ) ) {
        return;
    }

    if ( ! empty( $lesson['is_online'] ) ) {
        return;
    }

    $table = $wpdb->prefix . 'mrm_tax_mileage_cache';

    if ( ! $this->mrm_table_exists( $table ) ) {
        return;
    }

    $environment_mode = $this->mrm_get_effective_calculations_environment_mode();

    $origin_address = $this->mrm_format_instructor_origin_address( $lesson );
    $destination_address = $this->mrm_format_lesson_destination_address( $lesson );

    $start_time = (string) ( $lesson['start_time'] ?? '' );
    $trip_date = $start_time ? gmdate( 'Y-m-d', strtotime( $start_time ) ) : gmdate( 'Y-m-d' );
    $tax_year = (int) gmdate( 'Y', strtotime( $trip_date ) );

    $one_way_miles = 0.0;
    $round_trip_miles = 0.0;
    $mileage_rate = 0.0;
    $mileage_deduction = 0.0;
    $calc_status = 'pending';
    $calc_source = 'queued';
    $calc_error_message = '';

    if ( $origin_address === '' || $destination_address === '' ) {
        $calc_status = 'pending_missing_address';
        $calc_error_message = 'Missing origin or destination address. Origin: ' . $origin_address . ' | Destination: ' . $destination_address;
    } else {
        $distance = $this->mrm_calculate_driving_distance_miles( $origin_address, $destination_address );

        if ( is_wp_error( $distance ) ) {
            $calc_status = 'pending_' . sanitize_key( $distance->get_error_code() );
            $calc_error_message = $distance->get_error_message();
        } else {
            $one_way_miles = round( (float) $distance, 2 );
            $round_trip_miles = round( $one_way_miles * 2, 2 );
            $calc_status = 'calculated';
            $calc_source = 'google_distance_matrix';
            $calc_error_message = '';
        }
    }

    $wpdb->replace(
        $table,
        array(
            'lesson_id'           => (int) $lesson_id,
            'instructor_id'       => (int) ( $lesson['instructor_id'] ?? 0 ),
            'tax_year'            => $tax_year,
            'environment_mode'    => $environment_mode,
            'trip_date'           => $trip_date,
            'origin_address'      => $origin_address,
            'destination_address' => $destination_address,
            'one_way_miles'       => $one_way_miles,
            'round_trip_miles'    => $round_trip_miles,
            'mileage_rate'        => $mileage_rate,
            'mileage_deduction'   => $mileage_deduction,
            'calc_status'         => $calc_status,
            'calc_source'         => $calc_source,
            'calc_error_message'  => $calc_error_message,
            'created_at'          => current_time( 'mysql' ),
            'updated_at'          => current_time( 'mysql' ),
        ),
        array(
            '%d','%d','%d','%s','%s','%s','%s','%f','%f','%f','%f','%s','%s','%s','%s','%s'
        )
    );
}


    public function handle_mrm_recalculate_mileage_cache() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Not allowed.' );
    }

    check_admin_referer( 'mrm_recalculate_mileage_cache' );

    global $wpdb;

    $tax_year = isset( $_GET['tax_year'] ) ? (int) $_GET['tax_year'] : (int) gmdate( 'Y' );
    $tax_quarter = isset( $_GET['tax_quarter'] ) ? (int) $_GET['tax_quarter'] : 0;
    $environment_mode = $this->mrm_get_effective_calculations_environment_mode();

    list( $start, $end ) = $this->mrm_get_tax_period_dates( $tax_year, $tax_quarter );

    $lessons = $wpdb->prefix . 'mrm_lessons';
    $mileage = $wpdb->prefix . 'mrm_tax_mileage_cache';

    if ( ! $this->mrm_table_exists( $lessons ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=mrm-calculations&tax_year=' . $tax_year . '&tax_quarter=' . $tax_quarter . '&mileage_error=missing_lessons_table' ) );
        exit;
    }

    if ( ! $this->mrm_table_exists( $mileage ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=mrm-calculations&tax_year=' . $tax_year . '&tax_quarter=' . $tax_quarter . '&mileage_error=missing_mileage_table' ) );
        exit;
    }

    $rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id
         FROM {$lessons}
         WHERE is_online = 0
           AND is_consultation = 0
           AND start_time >= %s
           AND start_time <= %s
           AND status IN ('scheduled','completed','paid','delivered')
         ORDER BY start_time ASC",
        $start,
        $end
    ),
    ARRAY_A
);

$lesson_ids = array();
foreach ( (array) $rows as $row ) {
    $lesson_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
    if ( $lesson_id > 0 ) {
        $lesson_ids[] = $lesson_id;
    }
}

if ( ! empty( $lesson_ids ) ) {
    $placeholders = implode( ',', array_fill( 0, count( $lesson_ids ), '%d' ) );
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$mileage}
             WHERE lesson_id IN ({$placeholders})",
            $lesson_ids
        )
    );
}

// Also remove legacy selected-period rows that may not line up cleanly with lesson IDs.
if ( (int) $tax_quarter > 0 ) {
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$mileage}
             WHERE tax_year = %d
               AND QUARTER(trip_date) = %d",
            $tax_year,
            $tax_quarter
        )
    );
} else {
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$mileage}
             WHERE tax_year = %d",
            $tax_year
        )
    );
}

    foreach ( (array) $rows as $row ) {
        $this->mrm_queue_mileage_calculation_for_lesson( (int) $row['id'] );
    }

    wp_safe_redirect(
        admin_url(
            'admin.php?page=mrm-calculations&tax_year=' . $tax_year . '&tax_quarter=' . $tax_quarter . '&mileage_recalculated=1&mileage_count=' . count( (array) $rows )
        )
    );
    exit;
}


public function handle_mrm_clear_mileage_cache_for_period() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Not allowed.' );
    }

    check_admin_referer( 'mrm_clear_mileage_cache_for_period' );

    global $wpdb;

    $tax_year = isset( $_GET['tax_year'] ) ? (int) $_GET['tax_year'] : (int) gmdate( 'Y' );
    $tax_quarter = isset( $_GET['tax_quarter'] ) ? (int) $_GET['tax_quarter'] : 0;

    $mileage = $wpdb->prefix . 'mrm_tax_mileage_cache';

    if ( ! $this->mrm_table_exists( $mileage ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=mrm-calculations&tax_year=' . $tax_year . '&tax_quarter=' . $tax_quarter . '&mileage_error=missing_mileage_table' ) );
        exit;
    }

    if ( (int) $tax_quarter > 0 ) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$mileage}
                 WHERE tax_year = %d
                   AND QUARTER(trip_date) = %d",
                $tax_year,
                $tax_quarter
            )
        );
    } else {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$mileage}
                 WHERE tax_year = %d",
                $tax_year
            )
        );
    }

    wp_safe_redirect(
        admin_url(
            'admin.php?page=mrm-calculations&tax_year=' . $tax_year . '&tax_quarter=' . $tax_quarter . '&mileage_cleared=1'
        )
    );
    exit;
}

public function handle_mrm_clear_all_mileage_cache() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Not allowed.' );
    }

    check_admin_referer( 'mrm_clear_all_mileage_cache' );

    global $wpdb;

    $mileage = $wpdb->prefix . 'mrm_tax_mileage_cache';

    if ( ! $this->mrm_table_exists( $mileage ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=mrm-calculations&mileage_error=missing_mileage_table' ) );
        exit;
    }

    $wpdb->query( "TRUNCATE TABLE {$mileage}" );

    wp_safe_redirect(
        admin_url( 'admin.php?page=mrm-calculations&mileage_cleared=1' )
    );
    exit;
}

    protected function mrm_write_csv_row( $handle, $row ) {
        if ( is_resource( $handle ) ) {
            fputcsv( $handle, $row );
        }
    }

    public function handle_mrm_export_1099_support() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Not allowed.' );
    }

    check_admin_referer( 'mrm_export_1099_support' );

    $tax_year = isset( $_GET['tax_year'] ) ? (int) $_GET['tax_year'] : (int) gmdate( 'Y' );
    $tax_quarter = isset( $_GET['tax_quarter'] ) ? (int) $_GET['tax_quarter'] : 0;
    $environment_mode = $this->mrm_get_effective_calculations_environment_mode();

    $rows = $this->mrm_get_calculations_instructor_summary( $tax_year, $tax_quarter, $environment_mode );

    $this->mrm_send_csv_headers( 'mrm-instructor-wages-' . $tax_year . '-q' . $tax_quarter . '.csv' );
    $out = fopen( 'php://output', 'w' );

    $this->mrm_write_csv_row( $out, array(
        'instructor_id',
        'instructor_name',
        'instructor_email',
        'payout_entries',
        'gross',
        'net_wage'
    ) );

    foreach ( $rows as $row ) {
        $this->mrm_write_csv_row( $out, array(
            (string) ( $row['instructor_id'] ?? '' ),
            (string) ( $row['instructor_name'] ?? '' ),
            (string) ( $row['instructor_email'] ?? '' ),
            (string) ( $row['payout_count'] ?? 0 ),
            number_format( (float) ( (int) ( $row['gross_cents'] ?? 0 ) / 100 ), 2, '.', '' ),
            number_format( (float) ( (int) ( $row['net_cents'] ?? 0 ) / 100 ), 2, '.', '' ),
        ) );
    }

    fclose( $out );
    exit;
}



    public function handle_mrm_export_mileage_summary() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Not allowed.' );
    }

    check_admin_referer( 'mrm_export_mileage_summary' );

    $tax_year = isset( $_GET['tax_year'] ) ? (int) $_GET['tax_year'] : (int) gmdate( 'Y' );
    $tax_quarter = isset( $_GET['tax_quarter'] ) ? (int) $_GET['tax_quarter'] : 0;
    $environment_mode = $this->mrm_get_effective_calculations_environment_mode();

    $rows = $this->mrm_get_calculations_mileage_summary( $tax_year, $tax_quarter, $environment_mode );

    $this->mrm_send_csv_headers( 'mrm-mileage-summary-' . $tax_year . '-q' . $tax_quarter . '.csv' );
    $out = fopen( 'php://output', 'w' );

    $this->mrm_write_csv_row( $out, array(
        'instructor_id',
        'instructor_name',
        'in_person_lesson_count',
        'total_round_trip_miles',
        'statuses',
        'details'
    ) );

    foreach ( $rows as $row ) {
        $this->mrm_write_csv_row( $out, array(
            (string) ( $row['instructor_id'] ?? '' ),
            (string) ( $row['instructor_name'] ?? '' ),
            (string) ( $row['lesson_count'] ?? 0 ),
            number_format( (float) ( $row['total_miles'] ?? 0 ), 2, '.', '' ),
            (string) ( $row['calc_statuses'] ?? '' ),
            (string) ( $row['calc_error_messages'] ?? '' ),
        ) );
    }

    fclose( $out );
    exit;
}



    public function handle_mrm_export_calculations_summary() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Not allowed.' );
    }

    check_admin_referer( 'mrm_export_calculations_summary' );

    $tax_year = isset( $_GET['tax_year'] ) ? (int) $_GET['tax_year'] : (int) gmdate( 'Y' );
    $tax_quarter = isset( $_GET['tax_quarter'] ) ? (int) $_GET['tax_quarter'] : 0;
    $environment_mode = $this->mrm_get_effective_calculations_environment_mode();

    $overview = $this->mrm_get_calculations_overview( $tax_year, $tax_quarter, $environment_mode );

    $this->mrm_send_csv_headers( 'mrm-calculations-summary-' . $tax_year . '-q' . $tax_quarter . '.csv' );
    $out = fopen( 'php://output', 'w' );

    $this->mrm_write_csv_row( $out, array( 'metric', 'amount' ) );

    foreach ( $overview as $key => $value ) {
        $this->mrm_write_csv_row( $out, array(
            $key,
            number_format( (float) $value, 2, '.', '' ),
        ) );
    }

    fclose( $out );
    exit;
}



    /* =========================================================
     * Admin UI
     * ========================================================= */
    public function register_admin_menu() {
        add_menu_page( 'MRM Scheduler', 'MRM Scheduler', self::CAPABILITY, 'mrm-scheduler', array( $this, 'render_admin_instructors_page' ), 'dashicons-calendar-alt', 58 );
        add_submenu_page( 'mrm-scheduler', 'Instructors', 'Instructors', self::CAPABILITY, 'mrm-scheduler-instructors', array( $this, 'render_admin_instructors_page' ) );
        add_submenu_page( 'mrm-scheduler', 'Google Calendar', 'Google Calendar', self::CAPABILITY, 'mrm-scheduler-google', array( $this, 'render_admin_google_page' ) );
        add_submenu_page(
            'mrm-scheduler',
            'Safety Attendance',
            'Safety Attendance',
            self::CAPABILITY,
            'mrm-scheduler-safety-attendance',
            array( $this, 'render_admin_safety_attendance_page' )
        );
        add_submenu_page(
            'mrm-scheduler',
            'Calculations',
            'Calculations',
            'manage_options',
            'mrm-calculations',
            array( $this, 'render_calculations_settings_page' )
        );
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
            $address = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
            $zip_code = isset( $_POST['zip_code'] ) ? sanitize_text_field( wp_unslash( $_POST['zip_code'] ) ) : '';
            $offers_in_person = ! empty( $_POST['offers_in_person'] ) ? 1 : 0;
            $offers_online = ! empty( $_POST['offers_online'] ) ? 1 : 0;
            $calendar_id = isset( $_POST['calendar_id'] ) ? sanitize_text_field( wp_unslash( $_POST['calendar_id'] ) ) : '';
            $timezone    = isset( $_POST['timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone'] ) ) : 'America/Phoenix';
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
                if ( ! $address ) $errors[] = 'Address is required.';
                if ( ! $zip_code ) $errors[] = 'ZIP Code is required.';
                if ( ! $offers_in_person && ! $offers_online ) {
                    $errors[] = 'At least one teaching format must be selected.';
                }
            }
            $schema = $this->schema_status();
            if ( ! $schema['ok'] ) $errors[] = 'Database schema is not ready. Click “Run Installer/Upgrade” first.';
            if ( empty( $errors ) ) {
                $data = array(
                    'name' => $name,
                    'email' => $email,
                    'city' => $city,
                    'state' => $state,
                    'address' => $address,
                    'zip_code' => $zip_code,
                    'offers_in_person' => $offers_in_person,
                    'offers_online' => $offers_online,
                    'calendar_id' => $calendar_id,
                    'timezone' => $timezone,
                    'stripe_connected_account_id' => ( $stripe_connected_account_id === '' ? null : $stripe_connected_account_id ),
                    'hire_date' => ( $hire_date === '' ? null : $hire_date ),
                    'profile_image_url' => ( $profile_image_url === '' ? null : $profile_image_url ),
                    'short_description' => ( $short_description === '' ? null : $short_description ),
                    'long_description' => ( $long_description === '' ? null : $long_description ),
                    'instruments' => ( $instruments_json === '' ? null : $instruments_json ),
                );
                $formats = array( '%s','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%s','%s','%s' );
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
            <p style="max-width:900px;"> Add instructors here. <strong>Start Date</strong> is stored in <code>hire_date</code> and is used by the Payments Hub instructor payout chart to determine Year 1, Year 2, or Year 3+ payout rates. </p>
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
                        <th scope="row"><label for="address">Address</label></th>
                        <td>
                            <input name="address" id="address" type="text" class="regular-text" placeholder="123 Main St" value="<?php echo esc_attr( $editing['address'] ?? '' ); ?>">
                            <p class="description">Instructor address used for internal reference and location-based scheduling context.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zip_code">ZIP Code</label></th>
                        <td>
                            <input name="zip_code" id="zip_code" type="text" class="regular-text" placeholder="85001" value="<?php echo esc_attr( $editing['zip_code'] ?? '' ); ?>">
                            <p class="description">Instructor ZIP code for backend reference and location-based admin use.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Teaching Format</th>
                        <td>
                            <label style="display:block; margin-bottom:8px;">
                                <input type="checkbox" name="offers_in_person" value="1" <?php checked( ! empty( $editing['offers_in_person'] ) ); ?>>
                                In Person
                            </label>
                            <label style="display:block;">
                                <input type="checkbox" name="offers_online" value="1" <?php checked( ! empty( $editing['offers_online'] ) ); ?>>
                                Online
                            </label>
                            <p class="description">Choose whether this instructor offers in-person lessons, online lessons, or both.</p>
                        </td>
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
        $json = $this->mrm_get_google_service_account_json();
        $delegated = isset( $opts['google_delegated_user'] ) ? (string) $opts['google_delegated_user'] : '';
        $slot_default= isset( $opts['default_slot_minutes'] ) ? (int) $opts['default_slot_minutes'] : 30;
        $sa_email = '';
        $parsed = $this->parse_service_account_json( $json );
        if ( is_array( $parsed ) && ! empty( $parsed['client_email'] ) ) $sa_email = $parsed['client_email'];
        ?>
        <div class="wrap">
            <h1>MRM Scheduler — Google Calendar</h1>
            <?php if ( isset( $_GET['sync_now'] ) ) : ?>
                <div class="<?php echo ( (string) $_GET['sync_now'] === '1' ? 'notice notice-success' : 'notice notice-error' ); ?>"><p>
                    <?php
                    echo esc_html(
                        ( (string) $_GET['sync_now'] === '1' )
                            ? 'Direct Google sync finished. Rows fetched: ' . (int) ( $_GET['rows'] ?? 0 ) . '.'
                            : 'Direct Google sync failed.'
                    );
                    ?>
                </p></div>
            <?php endif; ?>
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
                            <p><strong>AWS Secrets Manager is the only supported source for the Google service account JSON.</strong></p>
                            <p class="description">
                                The scheduler loads the Google service account JSON from
                                <code><?php echo esc_html( defined( 'MRM_SECRET_GOOGLE_SCHEDULER' ) ? MRM_SECRET_GOOGLE_SCHEDULER : 'lowbrass/google/scheduler' ); ?></code>.
                                This settings page no longer stores service account JSON locally in WordPress.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Service Account Email</th>
                        <td>
                            <?php if ( $sa_email ) : ?>
                                <code style="font-size:14px;"><?php echo esc_html( $sa_email ); ?></code>
                                <p class="description">Share each instructor calendar with this email.</p>
                            <?php else : ?>
                                <?php if ( $this->mrm_google_service_account_uses_aws() ) : ?>
                                    <em>The scheduler is set to use AWS Secrets Manager for the Google service account JSON, but the AWS-loaded JSON could not be parsed into a valid service account. Re-check the <code><?php echo esc_html( defined( 'MRM_SECRET_GOOGLE_SCHEDULER' ) ? MRM_SECRET_GOOGLE_SCHEDULER : 'lowbrass/google/scheduler' ); ?></code> secret.</em>
                                <?php else : ?>
                                    <em>AWS Secrets Manager is not currently providing a usable service_account_json value, so the scheduler cannot initialize Google credentials in AWS-only mode.</em>
                                <?php endif; ?>
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
                    <tr>
                        <th scope="row">Direct Sync Secret</th>
                        <td>
                            <p><strong>AWS Secrets Manager is active for the direct sync secret.</strong></p>
                            <p class="description">
                                The scheduler is loading the direct sync secret from
                                <code><?php echo esc_html( defined( 'MRM_SECRET_GOOGLE_SCHEDULER' ) ? MRM_SECRET_GOOGLE_SCHEDULER : 'lowbrass/google/scheduler' ); ?></code>.
                                It is no longer editable from this settings page.
                            </p>
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
            <p>
                <?php if ( $this->mrm_google_service_account_uses_aws() ) : ?>
                    The Google service account JSON is being loaded from AWS Secrets Manager. Click test below to verify the AWS-loaded credentials.
                <?php else : ?>
                    After saving your JSON, click test. If it fails, you’ll get a readable error.
                <?php endif; ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field( 'mrm_scheduler_test_google', 'mrm_scheduler_google_test_nonce' ); ?>
                <input type="hidden" name="action" value="mrm_scheduler_test_google">
                <?php submit_button( 'Test Google Calendar API', 'secondary' ); ?>
            </form>
            <hr>
            <h2>4) Direct Google Sync</h2>
            <p>Use this when you want to force the plugin to pull Google event timing into <code>wp_mrm_lessons</code> immediately instead of waiting for WP-Cron.</p>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field( 'mrm_scheduler_google_sync_now', 'mrm_scheduler_google_sync_now_nonce' ); ?>
                <input type="hidden" name="action" value="mrm_scheduler_google_sync_now">
                <?php submit_button( 'Run Google Sync Now', 'secondary' ); ?>
            </form>
            <p><strong>Direct endpoint for Hostinger cron:</strong></p>
            <p>
                <code style="display:block; max-width:100%; overflow:auto;">
                    <?php
                    echo esc_html(
                        add_query_arg(
                            array(
                                'action'     => 'mrm_scheduler_google_sync_now',
                                'sync_token' => $this->mrm_get_google_sync_secret(),
                                'format'     => 'json',
                            ),
                            admin_url( 'admin-post.php' )
                        )
                    );
                    ?>
                </code>
            </p>


            <hr>
            <h2>5) Debug One Google Lesson Row</h2>
            <p>Use this to run the recurring Google resolver for one lesson row and print the raw result.</p>
            
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

        $opts['google_service_account_json'] = '';
        $opts['google_sync_secret'] = '';
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

        if ( ! $this->mrm_google_service_account_uses_aws() ) {
            $msg = 'AWS Secrets Manager is not providing a usable service_account_json value. The scheduler is not allowed to fall back to the WordPress settings box in AWS-only mode.';
            wp_safe_redirect( admin_url( 'admin.php?page=mrm-scheduler-google&test=fail&msg=' . rawurlencode( $msg ) ) );
            exit;
        }

        if ( ! $this->google_is_configured() ) {
            $msg = 'AWS Secrets Manager is active, but the Google service account JSON could not be parsed into a valid credential set. Check the PHP error log for parse_service_account_json diagnostics.';
            wp_safe_redirect( admin_url( 'admin.php?page=mrm-scheduler-google&test=fail&msg=' . rawurlencode( $msg ) ) );
            exit;
        }

        $token = $this->google_get_access_token();
        if ( is_wp_error( $token ) ) {
            $msg = 'Token fetch failed while using AWS-loaded Google credentials: ' . $token->get_error_message();
            wp_safe_redirect( admin_url( 'admin.php?page=mrm-scheduler-google&test=fail&msg=' . rawurlencode( $msg ) ) );
            exit;
        }

        $msg = 'Success: Access token obtained using the AWS Secrets Manager service account JSON.';
        wp_safe_redirect( admin_url( 'admin.php?page=mrm-scheduler-google&test=ok&msg=' . rawurlencode( $msg ) ) );
        exit;
    }

    protected function run_google_sync_now( $source = 'manual' ) {
        $summary = array(
            'ok'            => false,
            'source'        => (string) $source,
            'started_at'    => current_time( 'mysql' ),
            'finished_at'   => '',
            'rows_fetched'  => 0,
            'google_ready'  => false,
            'message'       => '',
        );

        if ( ! $this->google_is_configured() ) {
            $summary['message'] = 'Google is not configured. AWS Secrets Manager did not provide a parseable Google service account JSON value.';
            $summary['finished_at'] = current_time( 'mysql' );
            return $summary;
        }

        $summary['google_ready'] = true;

        $rows = $this->get_lessons_needing_google_truth_pass( 400 );
        $summary['rows_fetched'] = is_array( $rows ) ? count( $rows ) : 0;

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        }

        $this->cron_sync_upcoming_events( 72, 30 );
        $this->cron_reconcile_completed_lessons( true );
        $this->cron_reconcile_cancelled_lessons( true );

        $summary['ok'] = true;
        $summary['message'] = 'Direct Google sync completed.';
        $summary['finished_at'] = current_time( 'mysql' );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        }

        return $summary;
    }

    public function handle_google_sync_now_request() {
        $is_admin_request = current_user_can( self::CAPABILITY ) && isset( $_POST['mrm_scheduler_google_sync_now_nonce'] );

        if ( $is_admin_request ) {
            check_admin_referer( 'mrm_scheduler_google_sync_now', 'mrm_scheduler_google_sync_now_nonce' );
        } else {
            $saved_token = $this->mrm_get_google_sync_secret();
            $request_token = isset( $_REQUEST['sync_token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sync_token'] ) ) : '';

            if ( $saved_token === '' || $request_token === '' || ! hash_equals( $saved_token, $request_token ) ) {
                wp_die( 'Not allowed.', 'Not allowed', array( 'response' => 403 ) );
            }
        }

        $summary = $this->run_google_sync_now( $is_admin_request ? 'admin_button' : 'direct_endpoint' );

        $wants_json = ! $is_admin_request || ( isset( $_REQUEST['format'] ) && strtolower( (string) $_REQUEST['format'] ) === 'json' );

        if ( $wants_json ) {
            wp_send_json( $summary, ( ! empty( $summary['ok'] ) ? 200 : 500 ) );
        }

        wp_safe_redirect(
            admin_url(
                'admin.php?page=mrm-scheduler-google&sync_now=' . ( ! empty( $summary['ok'] ) ? '1' : '0' ) .
                '&rows=' . (int) ( $summary['rows_fetched'] ?? 0 )
            )
        );
        exit;
    }



    public function admin_finalize_old_lessons_now() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $this->mrm_finalization_debug_log( 'manual_finalization_trigger_started', array(
            'user_id' => get_current_user_id(),
        ) );

        $this->cron_finalize_old_lessons();

        $this->mrm_finalization_debug_log( 'manual_finalization_trigger_finished', array(
            'user_id' => get_current_user_id(),
        ) );

        wp_safe_redirect( admin_url( 'tools.php?page=mrm-lesson-scheduler&finalization_run=1' ) );
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
        $json = $this->mrm_get_google_service_account_json();
        $parsed = $this->parse_service_account_json( $json );
        return ( is_array( $parsed ) && ! empty( $parsed['client_email'] ) && ! empty( $parsed['private_key'] ) );
    }

protected function parse_service_account_json( $json ) {
    $json = is_string( $json ) ? trim( $json ) : '';

    if ( $json === '' ) {
        $this->mrm_aws_debug_log( 'parse_service_account_json received empty string' );
        return null;
    }

    $data = json_decode( $json, true );

    if ( ! is_array( $data ) ) {
        $this->mrm_aws_debug_log( 'parse_service_account_json json_decode failed', array(
            'input_length' => strlen( $json ),
            'input_preview' => substr( $json, 0, 120 ),
        ) );
        return null;
    }

    if ( empty( $data['client_email'] ) ) {
        $this->mrm_aws_debug_log( 'parse_service_account_json missing client_email', array(
            'keys_present' => array_keys( $data ),
        ) );
        return null;
    }

    if ( empty( $data['private_key'] ) ) {
        $this->mrm_aws_debug_log( 'parse_service_account_json missing private_key', array(
            'keys_present' => array_keys( $data ),
        ) );
        return null;
    }

    $this->mrm_aws_debug_log( 'parse_service_account_json succeeded', array(
        'client_email' => $data['client_email'],
        'has_private_key' => ! empty( $data['private_key'] ),
    ) );

    return array(
        'client_email' => (string) $data['client_email'],
        'private_key'  => (string) $data['private_key'],
        'token_uri'    => ! empty( $data['token_uri'] ) ? (string) $data['token_uri'] : self::GOOGLE_TOKEN_URL,
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

    protected function normalize_google_time_range( $time_min, $time_max ) {
        $time_min_n = $this->to_rfc3339_utc( $time_min );
        if ( is_wp_error( $time_min_n ) ) {
            return new WP_Error(
                'invalid_google_time_min',
                'Invalid Google timeMin: ' . $time_min_n->get_error_message()
            );
        }

        $time_max_n = $this->to_rfc3339_utc( $time_max );
        if ( is_wp_error( $time_max_n ) ) {
            return new WP_Error(
                'invalid_google_time_max',
                'Invalid Google timeMax: ' . $time_max_n->get_error_message()
            );
        }

        $min_ts = strtotime( $time_min_n );
        $max_ts = strtotime( $time_max_n );

        if ( ! $min_ts || ! $max_ts ) {
            return new WP_Error(
                'invalid_google_time_range',
                'Google time window could not be parsed after normalization.'
            );
        }

        if ( $max_ts <= $min_ts ) {
            return new WP_Error(
                'invalid_google_time_range',
                'Google timeMax must be greater than timeMin.'
            );
        }

        return array(
            'timeMin' => $time_min_n,
            'timeMax' => $time_max_n,
            'min_ts'  => $min_ts,
            'max_ts'  => $max_ts,
        );
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
        $parsed = $this->parse_service_account_json( $this->mrm_get_google_service_account_json() );
        $this->mrm_aws_debug_log( 'google_get_access_token parsed credentials', array(
            'parsed_is_array' => is_array( $parsed ),
            'client_email' => is_array( $parsed ) && ! empty( $parsed['client_email'] ) ? $parsed['client_email'] : '',
            'has_private_key' => is_array( $parsed ) && ! empty( $parsed['private_key'] ),
            'token_uri' => is_array( $parsed ) && ! empty( $parsed['token_uri'] ) ? $parsed['token_uri'] : '',
        ) );
        if ( ! $parsed ) {
            $this->mrm_aws_debug_log( 'google_get_access_token failed before JWT build because parsed credentials were invalid' );
            return new WP_Error( 'google_not_configured', 'Service Account JSON not configured.' );
        }
        $client_email = $parsed['client_email'];
        $private_key  = $parsed['private_key'];
        $token_url    = ! empty($parsed['token_uri']) ? $parsed['token_uri'] : self::GOOGLE_TOKEN_URL;
        $subject      = isset( $opts['google_delegated_user'] ) ? (string) $opts['google_delegated_user'] : '';
        if ( $subject_override !== '' ) {
            $subject = $subject_override;
        }
        $jwt = $this->google_make_jwt( $client_email, $private_key, $scope, $token_url, $subject );
        if ( is_wp_error( $jwt ) ) return $jwt;
        $this->mrm_aws_debug_log( 'google_get_access_token sending token request', array(
            'token_uri' => $token_url,
            'client_email' => $client_email,
        ) );
        $resp = wp_remote_post( $token_url, array(
            'timeout' => 20,
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'body'    => http_build_query( array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ) ),
        ) );
        $this->mrm_aws_debug_log( 'google_get_access_token token request completed', array(
            'is_wp_error' => is_wp_error( $resp ),
        ) );
        if ( is_wp_error( $resp ) ) {
            $this->mrm_aws_debug_log( 'google_get_access_token wp_remote_post returned WP_Error', array(
                'error_code' => $resp->get_error_code(),
                'error_message' => $resp->get_error_message(),
            ) );
            return $resp;
        }
        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        $this->mrm_aws_debug_log( 'google_get_access_token token endpoint response', array(
            'http_code' => $code,
            'body_preview' => is_string( $body ) ? substr( $body, 0, 500 ) : '',
        ) );
        $data = json_decode( $body, true );
        if ( $code < 200 || $code >= 300 || ! is_array($data) || empty($data['access_token']) ) {
            $this->mrm_aws_debug_log( 'google_get_access_token token endpoint rejected request', array(
                'http_code' => $code,
                'body_preview' => is_string( $body ) ? substr( $body, 0, 500 ) : '',
            ) );
            $detail = is_array($data) && ! empty($data['error_description']) ? $data['error_description'] : $body;
            return new WP_Error( 'google_token_failed', 'Token request failed: ' . $detail );
        }
        $token = (string) $data['access_token'];
        $this->mrm_aws_debug_log( 'google_get_access_token succeeded', array(
            'access_token_present' => ! empty( $token ),
            'token_length' => is_string( $token ) ? strlen( $token ) : 0,
        ) );
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

        $url = self::GOOGLE_FREEBUSY_URL;
        $this->mrm_aws_debug_log( 'Google Calendar API request starting', array(
            'url' => $url,
            'calendar_id' => implode( ',', $cal_ids ),
        ) );
        $resp = wp_remote_post( $url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( $payload ),
        ) );
        $this->mrm_aws_debug_log( 'Google Calendar API request completed', array(
            'is_wp_error' => is_wp_error( $resp ),
        ) );

        if ( is_wp_error( $resp ) ) {
            $this->mrm_aws_debug_log( 'Google Calendar API WP_Error', array(
                'error_code' => $resp->get_error_code(),
                'error_message' => $resp->get_error_message(),
            ) );
            return $resp;
        }
        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        $this->mrm_aws_debug_log( 'Google Calendar API response', array(
            'http_code' => $code,
            'body_preview' => is_string( $body ) ? substr( $body, 0, 500 ) : '',
        ) );
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

    protected function mrm_get_instructor_booking_notification_subject( $lesson ) {
        $lesson = is_array( $lesson ) ? $lesson : array();

        if ( $this->mrm_is_consultation_lesson( $lesson ) ) {
            return 'Consultation Scheduled';
        }

        return 'Private Lesson Scheduled';
    }

    protected function mrm_get_repeat_frequency_label( $repeat_frequency ) {
        $repeat_frequency = strtolower( trim( (string) $repeat_frequency ) );

        if ( $repeat_frequency === 'weekly' ) {
            return 'Weekly';
        }

        if ( $repeat_frequency === 'biweekly' ) {
            return 'Biweekly';
        }

        return '';
    }

    protected function mrm_get_repeat_duration_label( $repeat_duration ) {
        $repeat_duration = strtolower( trim( (string) $repeat_duration ) );

        if ( $repeat_duration === '1_month' ) {
            return 'For One Month';
        }

        if ( $repeat_duration === '3_months' ) {
            return 'For Three Months';
        }

        if ( $repeat_duration === 'indefinitely' ) {
            return 'Indefinitely';
        }

        return '';
    }

    protected function mrm_get_timeframe_label( $repeat_frequency, $repeat_duration ) {
        $frequency_label = $this->mrm_get_repeat_frequency_label( $repeat_frequency );
        $duration_label  = $this->mrm_get_repeat_duration_label( $repeat_duration );

        if ( $frequency_label !== '' && $duration_label !== '' ) {
            return trim( $frequency_label . ' ' . $duration_label );
        }

        return '';
    }

    protected function send_instructor_scheduled_notification_for_lesson( $lesson_id, $options = array() ) {
        $lesson = $this->get_lesson_with_instructor( $lesson_id );
        if ( ! is_array( $lesson ) || empty( $lesson ) ) {
            return false;
        }

        $instructor_email = sanitize_email( (string) ( $lesson['instructor_email'] ?? '' ) );
        if ( ! is_email( $instructor_email ) ) {
            return false;
        }

        $context = $this->get_safety_lesson_context( $lesson, 'instructor' );
        $is_consultation = ! empty( $context['is_consultation'] );

        $student_name    = (string) ( $lesson['student_name'] ?? 'Student' );
        $student_email   = (string) ( $lesson['student_email'] ?? '' );
        $instructor_name = (string) ( $lesson['instructor_name'] ?? 'Instructor' );
        $minutes         = (int) ( $lesson['lesson_length'] ?? 0 );
        $options = is_array( $options ) ? $options : array();
        $timeframe_label = (string) ( $options['timeframe_label'] ?? '' );

        $title = $this->mrm_get_instructor_booking_notification_subject( $lesson );

        $intro = $is_consultation
            ? '<p>A consultation has been scheduled on your calendar.</p>'
            : '<p>A private lesson has been scheduled on your calendar.</p>';

        $details = '';
        $details .= '<div><strong>Instructor:</strong> ' . esc_html( $instructor_name ) . '</div>';
        $details .= '<div><strong>Student:</strong> ' . esc_html( $student_name ) . '</div>';
        $details .= '<div><strong>Student Email:</strong> ' . esc_html( $student_email ) . '</div>';
        $details .= '<div><strong>Time:</strong> ' . esc_html( (string) ( $context['start_label'] ?? '' ) ) . '</div>';

        if ( $is_consultation ) {
            $details .= '<div><strong>Type:</strong> Consultation</div>';
            $details .= '<div><strong>Consultation Length:</strong> ' . esc_html( (string) $minutes ) . ' minutes</div>';
        } else {
            $details .= '<div><strong>Type:</strong> ' . esc_html( (string) ( $context['lesson_type_label'] ?? 'Private Lesson' ) ) . '</div>';
            $details .= '<div><strong>Lesson Length:</strong> ' . esc_html( (string) $minutes ) . ' minutes</div>';

            if ( $timeframe_label !== '' ) {
                $details .= '<div><strong>Timeframe:</strong> ' . esc_html( $timeframe_label ) . '</div>';
            }
        }

        if ( ! empty( $context['join_link'] ) ) {
            $details .= '<div><strong>Lesson Link:</strong> <a href="' . esc_url( (string) $context['join_link'] ) . '">' . esc_html( (string) $context['join_link'] ) . '</a></div>';
        }

        if ( ! empty( $context['location_text'] ) ) {
            $details .= '<div><strong>Location:</strong> ' . esc_html( (string) $context['location_text'] ) . '</div>';
        }

        $details .= '<div style="margin-top:12px;">' . esc_html( (string) ( $context['format_note'] ?? '' ) ) . '</div>';

        $html = $this->mrm_safety_email_wrap_html_blocks(
            $title,
            $intro,
            $details,
            ''
        );

        $sent = wp_mail(
            $instructor_email,
            $title,
            $html,
            array(
                'Content-Type: text/html; charset=UTF-8',
                'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
            )
        );


        return $sent;
    }

    protected function send_consultation_confirmation_for_lesson( $lesson_id ) {
        $lesson = $this->get_lesson_with_instructor( $lesson_id );
        if ( ! is_array( $lesson ) || empty( $lesson ) ) {
            return false;
        }

        if ( ! $this->mrm_is_consultation_lesson( $lesson ) ) {
            return false;
        }

        $student_email    = sanitize_email( (string) ( $lesson['student_email'] ?? '' ) );
        if ( ! is_email( $student_email ) ) {
            return false;
        }

        $context = $this->get_safety_lesson_context( $lesson );
        $minutes = (int) ( $lesson['lesson_length'] ?? 30 );
        $student_name = (string) ( $lesson['student_name'] ?? 'Student' );
        $instructor_name = (string) ( $lesson['instructor_name'] ?? 'Instructor' );

        $details = '';
        $details .= '<div><strong>Student:</strong> ' . esc_html( $student_name ) . '</div>';
        $details .= '<div><strong>Instructor:</strong> ' . esc_html( $instructor_name ) . '</div>';
        $details .= '<div><strong>Time:</strong> ' . esc_html( (string) $context['start_label'] ) . '</div>';
        $details .= '<div><strong>Type:</strong> Consultation</div>';
        $details .= '<div><strong>Consultation length:</strong> ' . esc_html( (string) $minutes ) . ' minutes</div>';

        if ( ! empty( $context['join_link'] ) ) {
            $details .= '<div><strong>Consultation link:</strong> <a href="' . esc_url( (string) $context['join_link'] ) . '">' . esc_html( (string) $context['join_link'] ) . '</a></div>';
        }

        $intro = '<p>Your consultation has been scheduled successfully.</p><p>This is an online consultation. Please use the consultation link above at the scheduled time.</p>';

        $html = $this->mrm_safety_email_wrap_html_blocks(
            'Consultation Confirmation',
            $intro,
            $details,
            ''
        );

        return wp_mail(
            $student_email,
            'Consultation Confirmation',
            $html,
            array(
                'Content-Type: text/html; charset=UTF-8',
                'From: LowBrass Lessons <no-reply@lowbrass-lessons.com>',
            )
        );
    }

    protected function mrm_wrap_email_html( $title, $intro_html, $details_html, $button_url, $button_text, $options = array() ) {
        return $this->mrm_safety_email_wrap_html( $title, $intro_html, $details_html, $button_url, $button_text );
    }

    protected function maybe_store_agreement( $email, $version, $signature, $ip, $args = array() ) {
        global $wpdb;

        $email     = sanitize_email( (string) $email );
        $version   = sanitize_text_field( (string) $version );
        $signature = sanitize_text_field( (string) $signature );
        $ip        = sanitize_text_field( (string) $ip );

        if ( ! $email || ! is_email( $email ) || ! $version || ! $signature ) {
            return 0;
        }

        $table = $wpdb->prefix . 'mrm_agreements';

        $scope      = sanitize_text_field( (string) ( $args['agreement_scope'] ?? 'terms_of_service' ) );
        $source     = sanitize_text_field( (string) ( $args['source_flow'] ?? '' ) );
        $lesson_id  = isset( $args['related_lesson_id'] ) ? absint( $args['related_lesson_id'] ) : null;
        $order_id   = isset( $args['related_order_id'] ) ? absint( $args['related_order_id'] ) : null;
        $sku        = sanitize_text_field( (string) ( $args['related_sku'] ?? '' ) );
        $ack        = isset( $args['acknowledgement'] ) && is_array( $args['acknowledgement'] ) ? $args['acknowledgement'] : array();
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_textarea_field( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE email = %s
                   AND agreement_version = %s
                   AND agreement_scope = %s
                   AND source_flow = %s
                   AND related_order_id <=> %d
                   AND related_sku = %s
                 ORDER BY id DESC
                 LIMIT 1",
                $email,
                $version,
                $scope,
                $source,
                $order_id ? $order_id : 0,
                $sku
            )
        );

        if ( $existing ) {
            return (int) $existing;
        }

        $wpdb->insert(
            $table,
            array(
                'email'                => $email,
                'agreement_version'    => $version,
                'agreement_scope'      => $scope,
                'source_flow'          => $source,
                'related_lesson_id'    => $lesson_id ? $lesson_id : null,
                'related_order_id'     => $order_id ? $order_id : null,
                'related_sku'          => $sku !== '' ? $sku : null,
                'signature'            => $signature,
                'acknowledgement_json' => wp_json_encode( $ack ),
                'signed_at'            => current_time( 'mysql' ),
                'ip_address'           => $ip,
                'user_agent'           => $user_agent,
            ),
            array( '%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%s' )
        );

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
register_deactivation_hook( __FILE__, array( 'MRM_Lesson_Scheduler', 'deactivate' ) );

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
