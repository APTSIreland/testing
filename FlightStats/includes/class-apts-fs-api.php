<?php
/**
 * API / cost & budget helper for APTS FlightStats Advanced.
 *
 * - Reads FlightStats credentials (App ID & API Key).
 * - Reads cost & budget settings.
 * - Calculates cost for a given number of API calls.
 * - Enforces automatic shut-off.
 * - Logs usage via APTS_FS_Logger.
 * - Calls FlightStats "Flight Status by Flight" (live).
 * - Classifies risk (on-time / at-risk / delayed / check DAA / diverted).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APTS_FS_API {

    /**
     * Base URL for Flight Status by Flight.
     *
     * /v2/json/flight/status/{carrier}/{flightNumber}/arr/{year}/{month}/{day}
     */
    const STATUS_BASE = 'https://api.flightstats.com/flex/flightstatus/rest/v2/json/flight/status';

    /* ---------------------------------------------------------------------
     *  Option helpers
     * ------------------------------------------------------------------ */

    protected static function get_option( $key, $default = '' ) {
        return get_option( 'apts_fs_adv_' . $key, $default );
    }

    /* ---------------------------------------------------------------------
     *  Credentials
     * ------------------------------------------------------------------ */

    public static function get_app_id() {
        return trim( self::get_option( 'app_id', '' ) );
    }

    public static function get_api_key() {
        return trim( self::get_option( 'api_key', '' ) );
    }

    /* ---------------------------------------------------------------------
     *  Cost / budget settings
     * ------------------------------------------------------------------ */

    public static function get_cost_per_1000() {
        return floatval( self::get_option( 'cost_per_1000', 0 ) );
    }

    public static function get_monthly_budget() {
        return floatval( self::get_option( 'monthly_budget', 0 ) );
    }

    public static function is_auto_shutoff_enabled() {
        return self::get_option( 'auto_shutoff', '0' ) === '1';
    }

    public static function get_month_stats() {
        if ( class_exists( 'APTS_FS_Logger' ) ) {
            return APTS_FS_Logger::get_month_stats();
        }

        return array(
            'total_calls' => 0,
            'total_cost'  => 0.0,
        );
    }

    public static function calculate_incremental_cost( $total_calls ) {
        $total_calls   = max( 0, intval( $total_calls ) );
        $cost_per_1000 = self::get_cost_per_1000();

        if ( $cost_per_1000 <= 0 || $total_calls === 0 ) {
            return 0.0;
        }

        return floatval( ( $total_calls / 1000.0 ) * $cost_per_1000 );
    }

    public static function can_make_calls( $planned_total_calls ) {
        $planned_total_calls = max( 0, intval( $planned_total_calls ) );

        if ( ! self::is_auto_shutoff_enabled() ) {
            return true;
        }

        $monthly_budget = self::get_monthly_budget();
        if ( $monthly_budget <= 0 ) {
            return true;
        }

        $stats          = self::get_month_stats();
        $current_cost   = $stats['total_cost'];
        $increment_cost = self::calculate_incremental_cost( $planned_total_calls );
        $new_cost       = $current_cost + $increment_cost;

        return ( $new_cost <= $monthly_budget );
    }

    public static function log_calls_and_cost( $status_calls, $scheduled_calls, $flights = array() ) {
        $status_calls    = max( 0, intval( $status_calls ) );
        $scheduled_calls = max( 0, intval( $scheduled_calls ) );
        $total_calls     = $status_calls + $scheduled_calls;

        $increment_cost = self::calculate_incremental_cost( $total_calls );

        if ( class_exists( 'APTS_FS_Logger' ) ) {
            APTS_FS_Logger::log_usage( $status_calls, $scheduled_calls, $increment_cost, $flights );
        }

        return array(
            'total_calls'    => $total_calls,
            'increment_cost' => $increment_cost,
        );
    }

    public static function get_budget_block_message() {
        $monthly_budget = self::get_monthly_budget();
        $stats          = self::get_month_stats();

        $msg = 'FlightStats API calls disabled: monthly budget exceeded.';
        if ( $monthly_budget > 0 ) {
            $msg .= ' Month-to-date cost €' . number_format( $stats['total_cost'], 2 ) .
                    ' vs budget €' . number_format( $monthly_budget, 2 ) . '.';
        }

        return $msg;
    }

    /* ---------------------------------------------------------------------
     *  Helpers – parsing / timing / risk
     * ------------------------------------------------------------------ */

    protected static function parse_flight_number( $raw ) {
        $raw = strtoupper( trim( $raw ) );
        if ( ! $raw ) {
            return null;
        }

        if ( ! preg_match( '/^([A-Z]{2,3})\s*0*([0-9]{1,4})$/', $raw, $m ) ) {
            return null;
        }

        $carrier = $m[1];
        $number  = ltrim( $m[2], '0' );
        if ( '' === $number ) {
            $number = $m[2];
        }

        return array( $carrier, $number );
    }

    protected static function build_check_daa_row( $flight, $note ) {
        return array(
            'flight'      => $flight,
            'origin'      => '',
            'destination' => '',
            'status'      => '🛬 Check DAA',
            'eta_utc'     => '—',
            'delay'       => '—',
            'arrives_in'  => '—',
            'row_class'   => 'apts-row-checkdaa',
            'note'        => $note,
        );
    }

    protected static function map_status_code( $code ) {
        $code = strtoupper( trim( $code ) );

        switch ( $code ) {
            case 'A':
                return 'en_route';
            case 'S':
                return 'scheduled';
            case 'L':
                return 'landed';
            case 'C':
                return 'cancelled';
            case 'D':
                return 'diverted';
            case 'R':
                return 'redirected';
            default:
                return 'unknown';
        }
    }

    /**
     * Compute timing from operationalTimes.
     *
     * @return array [etaDisplay, delayText, arrivesIn, minutesToArrive, delayMinutes, hasTimes]
     */
    protected static function compute_timing( $ops ) {
        $scheduled_utc = null;
        $best_utc      = null;

        if ( ! is_array( $ops ) ) {
            $ops = array();
        }

        foreach ( array( 'scheduledGateArrival', 'scheduledRunwayArrival' ) as $key ) {
            if ( ! empty( $ops[ $key ]['dateUtc'] ) ) {
                $scheduled_utc = $ops[ $key ]['dateUtc'];
                break;
            }
        }

        foreach ( array( 'estimatedGateArrival', 'estimatedRunwayArrival', 'actualGateArrival', 'actualRunwayArrival' ) as $key ) {
            if ( ! empty( $ops[ $key ]['dateUtc'] ) ) {
                $best_utc = $ops[ $key ]['dateUtc'];
                break;
            }
        }

        if ( ! $best_utc && $scheduled_utc ) {
            $best_utc = $scheduled_utc;
        }

        if ( ! $best_utc ) {
            return array( '—', '—', '—', null, null, false );
        }

        try {
            $eta  = new DateTimeImmutable( $best_utc, new DateTimeZone( 'UTC' ) );
            $now  = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
            $diff = $eta->getTimestamp() - $now->getTimestamp();

            $minutes_to_arrive = (int) floor( $diff / 60 );
            $eta_display       = $eta->format( 'H:i' );

            if ( $minutes_to_arrive < -30 ) {
                $arrives_in = 'arrived';
            } elseif ( $minutes_to_arrive < 0 ) {
                $arrives_in = '0 min';
            } else {
                $arrives_in = $minutes_to_arrive . ' min';
            }

            $delay_minutes = null;
            $delay_text    = '—';

            if ( $scheduled_utc ) {
                $sched = new DateTimeImmutable( $scheduled_utc, new DateTimeZone( 'UTC' ) );
                $delay_minutes = (int) floor( ( $eta->getTimestamp() - $sched->getTimestamp() ) / 60 );

                if ( $delay_minutes > 0 ) {
                    $delay_text = '+' . $delay_minutes . ' min';
                } elseif ( $delay_minutes < 0 ) {
                    $delay_text = $delay_minutes . ' min';
                } else {
                    $delay_text = '0 min';
                }
            }

            return array(
                $eta_display,
                $delay_text,
                $arrives_in,
                $minutes_to_arrive,
                $delay_minutes,
                true,
            );
        } catch ( Exception $e ) {
            return array( '—', '—', '—', null, null, false );
        }
    }

    /**
     * Classify risk & status override.
     *
     * @return array [row_class, status_override]
     */
    protected static function classify_risk( $status_text, $delay_minutes, $minutes_to_arrive, $has_times ) {
        $row_class       = '';
        $status_override = null;

        // Cancelled – always Check DAA.
        if ( 'cancelled' === $status_text ) {
            $row_class       = 'apts-row-checkdaa';
            $status_override = '🛬 Check DAA';
            return array( $row_class, $status_override );
        }

        // No usable timing – Check DAA.
        if ( ! $has_times ) {
            $row_class       = 'apts-row-checkdaa';
            $status_override = '🛬 Check DAA';
            return array( $row_class, $status_override );
        }

        // Diverted / redirected – high criticality, flashing red.
        if ( 'diverted' === $status_text || 'redirected' === $status_text ) {
            $row_class = 'apts-row-delayed apts-row-diverted-flash';
            return array( $row_class, $status_override );
        }

        // Landed – treat as completed / safe.
        if ( 'landed' === $status_text ) {
            $row_class = 'apts-row-ontime';
            return array( $row_class, $status_override );
        }

        if ( null === $delay_minutes || null === $minutes_to_arrive ) {
            return array( $row_class, $status_override );
        }

        // Thresholds:
        // - On-time: delay ≤ +10 min AND minutes_to_arrive > 30
        // - At-risk: (10 < delay ≤ 20) OR (0 ≤ delay ≤ 10 AND 10 < minutes_to_arrive ≤ 30)
        // - Delayed: delay > 20 OR minutes_to_arrive ≤ 10

        if ( $delay_minutes <= 10 && $minutes_to_arrive > 30 ) {
            $row_class = 'apts-row-ontime';
        } elseif (
            ( $delay_minutes > 10 && $delay_minutes <= 20 ) ||
            ( $delay_minutes >= 0 && $minutes_to_arrive <= 30 && $minutes_to_arrive > 10 )
        ) {
            $row_class = 'apts-row-atrisk';
        } elseif ( $delay_minutes > 20 || $minutes_to_arrive <= 10 ) {
            $row_class = 'apts-row-delayed';
        }

        return array( $row_class, $status_override );
    }

    /* ---------------------------------------------------------------------
     *  Live FlightStats integration
     * ------------------------------------------------------------------ */

    public static function fetch_flight_statuses_live( $flight_numbers ) {
        $results = array();

        $app_id  = self::get_app_id();
        $api_key = self::get_api_key();

        if ( ! $app_id || ! $api_key ) {
            return self::fetch_flight_statuses_placeholder( $flight_numbers );
        }

        $planned_calls = count( $flight_numbers );

        if ( ! self::can_make_calls( $planned_calls ) ) {
            $placeholder = self::fetch_flight_statuses_placeholder( $flight_numbers );
            $budget_msg  = self::get_budget_block_message();

            foreach ( $placeholder['flights'] as &$row ) {
                if ( empty( $row['note'] ) ) {
                    $row['note'] = $budget_msg;
                }
            }

            return $placeholder;
        }

        $status_calls = 0;

        $ts    = time();
        $year  = (int) gmdate( 'Y', $ts );
        $month = (int) gmdate( 'n', $ts );
        $day   = (int) gmdate( 'j', $ts );

        foreach ( $flight_numbers as $raw ) {
            $raw = trim( $raw );
            if ( '' === $raw ) {
                continue;
            }

            $parsed = self::parse_flight_number( $raw );
            if ( ! $parsed ) {
                $results[] = self::build_check_daa_row(
                    $raw,
                    'Invalid flight format; please use e.g. EI583 or FR553.'
                );
                continue;
            }

            list( $carrier, $number ) = $parsed;

            $path = sprintf(
                '%s/%s/%s/arr/%d/%d/%d',
                self::STATUS_BASE,
                rawurlencode( $carrier ),
                rawurlencode( $number ),
                $year,
                $month,
                $day
            );

            $url = add_query_arg(
                array(
                    'appId' => $app_id,
                    'appKey'=> $api_key,
                    'utc'   => 'true',
                ),
                $path
            );

            $status_calls++;

            $response = wp_remote_get(
                $url,
                array(
                    'timeout' => 10,
                )
            );

            if ( is_wp_error( $response ) ) {
                $results[] = self::build_check_daa_row(
                    $raw,
                    'FlightStats error: ' . $response->get_error_message() . ' – Check DAA.'
                );
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( 200 !== $code ) {
                $results[] = self::build_check_daa_row(
                    $raw,
                    'FlightStats HTTP ' . $code . ' – Check DAA.'
                );
                continue;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $body ) || empty( $body['flightStatuses'] ) || ! is_array( $body['flightStatuses'] ) ) {
                $results[] = self::build_check_daa_row(
                    $raw,
                    'No live status returned – Check DAA.'
                );
                continue;
            }

            $status_obj = $body['flightStatuses'][0];

            $origin = isset( $status_obj['departureAirportFsCode'] ) ? $status_obj['departureAirportFsCode'] : '';
            $dest   = isset( $status_obj['arrivalAirportFsCode'] ) ? $status_obj['arrivalAirportFsCode'] : '';

            $status_code = isset( $status_obj['status'] ) ? $status_obj['status'] : '';
            $status_text = self::map_status_code( $status_code );

            $ops = ( isset( $status_obj['operationalTimes'] ) && is_array( $status_obj['operationalTimes'] ) )
                ? $status_obj['operationalTimes']
                : array();

            list( $eta_display, $delay_text, $arrives_in, $minutes_to_arrive, $delay_minutes, $has_times ) =
                self::compute_timing( $ops );

            list( $row_class, $status_override ) =
                self::classify_risk( $status_text, $delay_minutes, $minutes_to_arrive, $has_times );

            $status_out = $status_override ? $status_override : $status_text;

            if ( '🛬 Check DAA' === $status_out ) {
                $note = 'Check DAA arrivals – data incomplete or cancelled.';
            } elseif ( 'diverted' === $status_text || 'redirected' === $status_text ) {
                $note = 'Diverted – take immediate action and confirm with DAA.';
            } else {
                $note = 'Live data from FlightStats.';
            }

            $results[] = array(
                'flight'      => $raw,
                'origin'      => $origin,
                'destination' => $dest,
                'status'      => $status_out,
                'eta_utc'     => $eta_display,
                'delay'       => $delay_text,
                'arrives_in'  => $arrives_in,
                'row_class'   => $row_class,
                'note'        => $note,
            );
        }

        if ( $status_calls > 0 ) {
            self::log_calls_and_cost( $status_calls, 0, $flight_numbers );
        }

        return array(
            'flights' => $results,
        );
    }

    /* ---------------------------------------------------------------------
     *  Placeholder (used when no credentials or budget shut-off)
     * ------------------------------------------------------------------ */

    public static function fetch_flight_statuses_placeholder( $flight_numbers ) {
        $results = array();

        $app_id  = self::get_app_id();
        $api_key = self::get_api_key();

        foreach ( $flight_numbers as $raw ) {
            $raw = trim( $raw );
            if ( '' === $raw ) {
                continue;
            }

            $results[] = array(
                'flight'      => $raw,
                'origin'      => '',
                'destination' => '',
                'status'      => '🛬 Check DAA',
                'eta_utc'     => '—',
                'delay'       => '—',
                'arrives_in'  => '—',
                'row_class'   => 'apts-row-checkdaa',
                'note'        => ( $app_id && $api_key )
                    ? 'FlightStats live API not wired yet – using placeholder row.'
                    : 'Add FlightStats App ID & API Key in settings to enable live lookups.',
            );
        }

        return array(
            'flights' => $results,
        );
    }
}
