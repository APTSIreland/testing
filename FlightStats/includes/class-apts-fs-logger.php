<?php
/**
 * Usage logging layer for APTS FlightStats Advanced.
 *
 * Responsible for:
 *  - Recording each API check (status + scheduled calls, cost, flights).
 *  - Providing simple month-to-date aggregation for the dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APTS_FS_Logger {

    /**
     * Insert a single usage record.
     *
     * @param int   $status_calls     Number of "Flight Status by Flight" calls.
     * @param int   $scheduled_calls  Number of "Scheduled Flights by Flight" calls.
     * @param float $cost             Cost attributed to this check (in €).
     * @param array $flights          List of flight numbers involved (optional).
     *
     * @return void
     */
    public static function log_usage( $status_calls, $scheduled_calls, $cost, $flights = array() ) {
        global $wpdb;

        if ( ! class_exists( 'APTS_FS_DB' ) ) {
            return;
        }

        $table = APTS_FS_DB::get_table();

        $status_calls    = max( 0, intval( $status_calls ) );
        $scheduled_calls = max( 0, intval( $scheduled_calls ) );
        $total_calls     = $status_calls + $scheduled_calls;
        $cost            = floatval( $cost );

        $wpdb->insert(
            $table,
            array(
                'ts'             => current_time( 'mysql' ),
                'status_calls'   => $status_calls,
                'scheduled_calls'=> $scheduled_calls,
                'total_calls'    => $total_calls,
                'cost'           => $cost,
                'flights'        => maybe_serialize( $flights ),
            ),
            array(
                '%s',
                '%d',
                '%d',
                '%d',
                '%f',
                '%s',
            )
        );
    }

    /**
     * Get aggregated stats for a specific month.
     *
     * Used by the dashboard to show month-to-date calls and cost.
     *
     * @param int|null $year   Year in UTC (default: current year).
     * @param int|null $month  Month in UTC, 1–12 (default: current month).
     *
     * @return array {
     *   @type int   $total_calls Month-to-date total API calls.
     *   @type float $total_cost  Month-to-date cost in €.
     * }
     */
    public static function get_month_stats( $year = null, $month = null ) {
        global $wpdb;

        if ( ! class_exists( 'APTS_FS_DB' ) ) {
            return array(
                'total_calls' => 0,
                'total_cost'  => 0.0,
            );
        }

        $table = APTS_FS_DB::get_table();

        if ( null === $year ) {
            $year = (int) gmdate( 'Y' );
        }
        if ( null === $month ) {
            $month = (int) gmdate( 'n' ); // 1–12
        }

        $sql = $wpdb->prepare(
            "SELECT 
                SUM(total_calls) AS total_calls,
                SUM(cost) AS total_cost
             FROM $table
             WHERE YEAR(ts) = %d
               AND MONTH(ts) = %d",
            $year,
            $month
        );

        $row = $wpdb->get_row( $sql );

        return array(
            'total_calls' => $row && $row->total_calls ? intval( $row->total_calls ) : 0,
            'total_cost'  => $row && $row->total_cost ? floatval( $row->total_cost ) : 0.0,
        );
    }
}
