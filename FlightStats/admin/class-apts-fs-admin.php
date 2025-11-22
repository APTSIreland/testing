<?php
/**
 * Admin UI for APTS FlightStats Advanced.
 *
 * - Adds "APTS FlightStats" menu in wp-admin.
 * - Renders Dashboard (month-to-date usage & cost, budget status).
 * - Renders Budget & Settings page:
 *      • FlightStats App ID & API Key
 *      • Cost per 1000
 *      • Monthly budget
 *      • Auto shut-off
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APTS_FS_Admin {

    /**
     * Register admin menus.
     */
    public static function register_menu() {
        add_menu_page(
            __( 'APTS FlightStats', 'apts-fs-adv' ),
            __( 'APTS FlightStats', 'apts-fs-adv' ),
            'manage_options',
            'apts_fs_adv_dashboard',
            array( __CLASS__, 'render_dashboard_page' ),
            'dashicons-chart-area',
            55
        );

        add_submenu_page(
            'apts_fs_adv_dashboard',
            __( 'Budget & Settings', 'apts-fs-adv' ),
            __( 'Budget & Settings', 'apts-fs-adv' ),
            'manage_options',
            'apts_fs_adv_settings',
            array( __CLASS__, 'render_settings_page' )
        );
    }

    /**
     * Convenience getters/setters for our options.
     */
    protected static function get_option( $key, $default = '' ) {
        return get_option( 'apts_fs_adv_' . $key, $default );
    }

    protected static function update_option( $key, $value ) {
        return update_option( 'apts_fs_adv_' . $key, $value );
    }

    /**
     * Dashboard page – high level KPIs.
     */
    public static function render_dashboard_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Pull settings.
        $cost_per_1000  = floatval( self::get_option( 'cost_per_1000', 0 ) );
        $monthly_budget = floatval( self::get_option( 'monthly_budget', 0 ) );
        $auto_shutoff   = self::get_option( 'auto_shutoff', '0' ) === '1';

        // Month stats (from logger).
        if ( class_exists( 'APTS_FS_Logger' ) ) {
            $month_stats = APTS_FS_Logger::get_month_stats();
        } else {
            $month_stats = array(
                'total_calls' => 0,
                'total_cost'  => 0.0,
            );
        }

        $total_calls = $month_stats['total_calls'];
        $total_cost  = $month_stats['total_cost'];

        $month_label = gmdate( 'F Y' );

        // Budget status.
        $usage_pct            = 0;
        $budget_status_class  = 'apts-fs-badge-grey';
        $budget_status_label  = 'No Budget Set';

        if ( $monthly_budget > 0 ) {
            $usage_pct = $total_cost > 0 ? ( $total_cost / $monthly_budget ) * 100.0 : 0;

            if ( $usage_pct < 35 ) {
                $budget_status_class = 'apts-fs-badge-green';
                $budget_status_label = 'Safe';
            } elseif ( $usage_pct < 70 ) {
                $budget_status_class = 'apts-fs-badge-yellow';
                $budget_status_label = 'Caution';
            } elseif ( $usage_pct <= 100 ) {
                $budget_status_class = 'apts-fs-badge-red';
                $budget_status_label = 'Near Limit';
            } else {
                $budget_status_class = 'apts-fs-badge-red';
                $budget_status_label = 'Over Budget';
            }
        }

        ?>
        <div class="wrap apts-fs-dashboard">
            <h1 style="margin-bottom:10px;">APTS FlightStats – Cost &amp; Usage Dashboard</h1>
            <p class="description">
                Corporate airline-style view of FlightStats usage, estimated spend, and budget status for APTS Dispatch.
            </p>

            <style>
                .apts-fs-dashboard {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                }
                .apts-fs-summary-cards {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 16px;
                    margin-top: 20px;
                    margin-bottom: 20px;
                }
                .apts-fs-card {
                    background: #ffffff;
                    border: 1px solid #d6dee8;
                    border-radius: 8px;
                    padding: 16px 20px;
                    min-width: 220px;
                    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
                }
                .apts-fs-card h2 {
                    margin: 0;
                    font-size: 13px;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                    color: #6c7a89;
                }
                .apts-fs-card .apts-fs-value {
                    margin-top: 6px;
                    font-size: 22px;
                    font-weight: 600;
                    color: #2a2a2a;
                }
                .apts-fs-card small {
                    display: block;
                    margin-top: 4px;
                    color: #7f8c8d;
                }
                .apts-fs-badge {
                    display: inline-block;
                    padding: 4px 8px;
                    border-radius: 999px;
                    font-size: 11px;
                    font-weight: 600;
                    text-transform: uppercase;
                }
                .apts-fs-badge-green {
                    background: #e8f8f0;
                    color: #2ecc71;
                }
                .apts-fs-badge-yellow {
                    background: #fef6e3;
                    color: #f1c40f;
                }
                .apts-fs-badge-red {
                    background: #fdecea;
                    color: #e74c3c;
                }
                .apts-fs-badge-grey {
                    background: #ecf0f1;
                    color: #7f8c8d;
                }
                .apts-fs-kv-row {
                    margin-top: 20px;
                    padding: 16px;
                    background: #f5f7fa;
                    border-radius: 8px;
                }
                .apts-fs-kv-row table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .apts-fs-kv-row th,
                .apts-fs-kv-row td {
                    padding: 8px 4px;
                    font-size: 13px;
                    border-bottom: 1px solid #dde3ec;
                }
                .apts-fs-kv-row th {
                    text-align: left;
                    color: #6c7a89;
                }
                .apts-fs-kv-row td {
                    text-align: right;
                    color: #2a2a2a;
                }
            </style>

            <div class="apts-fs-summary-cards">
                <div class="apts-fs-card">
                    <h2>Month</h2>
                    <div class="apts-fs-value"><?php echo esc_html( $month_label ); ?></div>
                    <small>Current reporting period</small>
                </div>

                <div class="apts-fs-card">
                    <h2>Month-to-date Calls</h2>
                    <div class="apts-fs-value"><?php echo number_format_i18n( $total_calls ); ?></div>
                    <small>Total FlightStats API calls logged this month</small>
                </div>

                <div class="apts-fs-card">
                    <h2>Month-to-date Cost</h2>
                    <div class="apts-fs-value">
                        <?php
                        if ( $total_cost > 0 ) {
                            echo '€ ' . number_format_i18n( $total_cost, 4 );
                        } else {
                            echo '€ 0.0000';
                        }
                        ?>
                    </div>
                    <small>Based on cost per 1000 in settings</small>
                </div>

                <div class="apts-fs-card">
                    <h2>Budget Status</h2>
                    <div class="apts-fs-value">
                        <?php if ( $monthly_budget > 0 ) : ?>
                            € <?php echo number_format_i18n( $total_cost, 2 ); ?>
                            <span style="font-size: 12px; color:#7f8c8d;">
                                of € <?php echo number_format_i18n( $monthly_budget, 2 ); ?>
                            </span>
                        <?php else : ?>
                            <span style="font-size: 14px; color:#7f8c8d;">No budget set</span>
                        <?php endif; ?>
                    </div>
                    <small>
                        <?php if ( $monthly_budget > 0 ) : ?>
                            <span class="apts-fs-badge <?php echo esc_attr( $budget_status_class ); ?>">
                                <?php echo esc_html( $budget_status_label ); ?>
                                <?php if ( $usage_pct > 0 ) : ?>
                                    (<?php echo number_format_i18n( $usage_pct, 1 ); ?>%)
                                <?php endif; ?>
                            </span>
                        <?php else : ?>
                            <span class="apts-fs-badge apts-fs-badge-grey">
                                Set a monthly budget in settings
                            </span>
                        <?php endif; ?>
                    </small>
                </div>

                <div class="apts-fs-card">
                    <h2>Auto Shut-off</h2>
                    <div class="apts-fs-value">
                        <?php if ( $auto_shutoff ) : ?>
                            <span class="apts-fs-badge apts-fs-badge-red">Enabled</span>
                        <?php else : ?>
                            <span class="apts-fs-badge apts-fs-badge-grey">Disabled</span>
                        <?php endif; ?>
                    </div>
                    <small>Stops FlightStats calls once monthly budget exceeded</small>
                </div>
            </div>

            <div class="apts-fs-kv-row">
                <h2 style="margin-top:0; font-size:14px; color:#34495e;">Month Detail</h2>
                <table>
                    <tr>
                        <th>Cost per 1000 API calls</th>
                        <td>
                            <?php
                            if ( $cost_per_1000 > 0 ) {
                                echo '€ ' . number_format_i18n( $cost_per_1000, 4 );
                            } else {
                                echo 'Not set';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Monthly Budget</th>
                        <td>
                            <?php
                            if ( $monthly_budget > 0 ) {
                                echo '€ ' . number_format_i18n( $monthly_budget, 2 );
                            } else {
                                echo 'Not set';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Month-to-date Calls</th>
                        <td><?php echo number_format_i18n( $total_calls ); ?></td>
                    </tr>
                    <tr>
                        <th>Month-to-date Cost</th>
                        <td><?php echo '€ ' . number_format_i18n( $total_cost, 4 ); ?></td>
                    </tr>
                    <tr>
                        <th>Usage vs Budget</th>
                        <td>
                            <?php
                            if ( $monthly_budget > 0 ) {
                                echo number_format_i18n( $usage_pct, 1 ) . '%';
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Budget & Settings page – now includes App ID and API Key.
     */
    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Save on POST.
        if ( isset( $_POST['apts_fs_adv_settings_nonce'] )
             && wp_verify_nonce( $_POST['apts_fs_adv_settings_nonce'], 'apts_fs_adv_save_settings' ) ) {

            $app_id         = isset( $_POST['app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['app_id'] ) ) : '';
            $api_key        = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

            $cost_per_1000  = isset( $_POST['cost_per_1000'] ) ? floatval( wp_unslash( $_POST['cost_per_1000'] ) ) : 0;
            $monthly_budget = isset( $_POST['monthly_budget'] ) ? floatval( wp_unslash( $_POST['monthly_budget'] ) ) : 0;
            $auto_shutoff   = isset( $_POST['auto_shutoff'] ) ? '1' : '0';

            self::update_option( 'app_id', $app_id );
            self::update_option( 'api_key', $api_key );
            self::update_option( 'cost_per_1000', $cost_per_1000 );
            self::update_option( 'monthly_budget', $monthly_budget );
            self::update_option( 'auto_shutoff', $auto_shutoff );

            echo '<div class="updated"><p>Settings saved.</p></div>';
        }

        $app_id         = self::get_option( 'app_id', '' );
        $api_key        = self::get_option( 'api_key', '' );
        $cost_per_1000  = self::get_option( 'cost_per_1000', '' );
        $monthly_budget = self::get_option( 'monthly_budget', '' );
        $auto_shutoff   = self::get_option( 'auto_shutoff', '0' ) === '1';

        ?>
        <div class="wrap">
            <h1>APTS FlightStats – Budget &amp; Settings</h1>
            <p class="description">
                Configure your FlightStats credentials, cost assumptions, and monthly budget.
            </p>

            <form method="post">
                <?php wp_nonce_field( 'apts_fs_adv_save_settings', 'apts_fs_adv_settings_nonce' ); ?>

                <h2 class="title">FlightStats Credentials</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="app_id">FlightStats App ID</label>
                        </th>
                        <td>
                            <input type="text" name="app_id" id="app_id"
                                   value="<?php echo esc_attr( $app_id ); ?>" class="regular-text" autocomplete="off" />
                            <p class="description">
                                Your FlightStats application ID.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="api_key">FlightStats API Key</label>
                        </th>
                        <td>
                            <input type="text" name="api_key" id="api_key"
                                   value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" autocomplete="off" />
                            <p class="description">
                                Your FlightStats API key (keep this secret).
                            </p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Cost &amp; Budget</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="cost_per_1000">Cost per 1000 API calls (€)</label>
                        </th>
                        <td>
                            <input type="number" step="0.0001" name="cost_per_1000" id="cost_per_1000"
                                   value="<?php echo esc_attr( $cost_per_1000 ); ?>" class="small-text" />
                            <p class="description">
                                Enter your blended cost per 1000 FlightStats calls (e.g. 12.5000).
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="monthly_budget">Monthly Budget (€)</label>
                        </th>
                        <td>
                            <input type="number" step="0.01" name="monthly_budget" id="monthly_budget"
                                   value="<?php echo esc_attr( $monthly_budget ); ?>" class="small-text" />
                            <p class="description">
                                Example: 100 to track spend vs €100 monthly budget.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Automatic Shut-off</th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_shutoff" value="1" <?php checked( $auto_shutoff ); ?> />
                                Disable FlightStats calls automatically when monthly budget is exceeded.
                            </label>
                            <p class="description">
                                When enabled, the live FlightStats integration will refuse new calls once
                                month-to-date cost exceeds the budget. The front-end will show a clear message
                                to staff instead of querying the API.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Save Settings' ); ?>
            </form>
        </div>
        <?php
    }
}
