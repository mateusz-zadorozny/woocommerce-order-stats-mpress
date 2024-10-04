<?php
/**
 * Plugin Name: Woocommerce Order Stats Mpress
 * Description: Creates a new REST API endpoint for order statistics with cron preloading and settings.
 * Version: 1.0.1
 * Author: Mateusz Zadorożny
 * Plugin URI: https://mpress.lemonsqueezy.com/
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_Order_Stats_Plugin
{
    private static $instance = null;
    private $api_key;

    public static function get_instance()
    {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'init'));
    }

    public function init()
    {
        // Add settings
        add_filter('woocommerce_settings_tabs_array', array($this, 'add_settings_tab'), 50);
        add_action('woocommerce_settings_tabs_order_stats', array($this, 'settings_tab'));
        add_action('woocommerce_update_options_order_stats', array($this, 'update_settings'));

        // Register REST API routes
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // Schedule cron job
        add_action('wp', array($this, 'schedule_cron'));
        add_action('order_stats_cron_hook', array($this, 'cron_task'));

        // Load API key
        $this->api_key = get_option('wc_order_stats_api_key', '');

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function add_settings_tab($settings_tabs)
    {
        $settings_tabs['order_stats'] = __('Order Stats', 'woocommerce');
        return $settings_tabs;
    }

    public function update_settings()
    {
        woocommerce_update_options($this->get_settings());
    }

    public function enqueue_admin_scripts($hook)
    {
        if ('woocommerce_page_wc-settings' !== $hook) {
            return;
        }
        wp_enqueue_script('wc-order-stats-admin', plugin_dir_url(__FILE__) . 'js/admin.js', array('jquery'), '1.0', true);
        wp_localize_script('wc-order-stats-admin', 'wcOrderStats', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('generate_api_key_nonce')
        ));
    }



    private function get_settings()
    {
        return array(
            'section_title' => array(
                'name' => __('Order Stats Settings', 'woocommerce'),
                'type' => 'title',
                'desc' => '',
                'id' => 'wc_order_stats_section_title'
            ),
            'api_enabled' => array(
                'name' => __('Enable API', 'woocommerce'),
                'type' => 'checkbox',
                'desc' => __('Enable the Order Stats API', 'woocommerce'),
                'id' => 'wc_order_stats_api_enabled'
            ),
            'preload_enabled' => array(
                'name' => __('Enable Preload', 'woocommerce'),
                'type' => 'checkbox',
                'desc' => __('Enable cron preloading of stats', 'woocommerce'),
                'id' => 'wc_order_stats_preload_enabled'
            ),
            'preload_time' => array(
                'name' => __('Preload Time', 'woocommerce'),
                'type' => 'time',
                'desc' => __('Set the time for daily preload (24-hour format)', 'woocommerce'),
                'id' => 'wc_order_stats_preload_time'
            ),
            'api_key' => array(
                'name' => __('API Key', 'woocommerce'),
                'type' => 'text',
                'desc' => __('API key for authentication', 'woocommerce'),
                'id' => 'wc_order_stats_api_key'
            ),
            'section_end' => array(
                'type' => 'sectionend',
                'id' => 'wc_order_stats_section_end'
            )
        );
    }

    public function settings_tab()
    {
        woocommerce_admin_fields($this->get_settings());
        ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row" class="titledesc">
                    <label for="wc_order_stats_generate_key"><?php _e('Generate New Key', 'woocommerce'); ?></label>
                </th>
                <td class="forminp forminp-button">
                    <button name="wc_order_stats_generate_key" id="wc_order_stats_generate_key" type="button"
                        class="button"><?php _e('Generate New Key', 'woocommerce'); ?></button>
                </td>
            </tr>
        </table>
        <?php
    }

    public function register_rest_routes()
    {
        register_rest_route('wc-order-stats/v1', '/(?P<period>yesterday|last-week|last-month)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_order_stats'),
            'permission_callback' => array($this, 'check_permission')
        ));
    }

    public function check_permission($request)
    {
        // First, check if the API is enabled
        if (get_option('wc_order_stats_api_enabled') !== 'yes') {
            return new WP_Error('rest_forbidden', esc_html__('API is currently disabled.', 'woocommerce'), array('status' => 403));
        }

        // Then, check the API key
        $api_key = $request->get_header('X-API-Key');
        if ($api_key !== $this->api_key) {
            return new WP_Error('rest_forbidden', esc_html__('Invalid API Key.', 'woocommerce'), array('status' => 403));
        }

        return true;
    }

    public function get_order_stats($request)
    {
        $period = $request['period'];
        $transient_key = 'wc_order_stats_' . $period;

        if (get_option('wc_order_stats_preload_enabled') === 'yes') {
            $cached_data = get_transient($transient_key);
            if ($cached_data !== false) {
                return new WP_REST_Response($cached_data, 200);
            }
        }

        // Calculate stats on the go if no cached data
        $stats = $this->calculate_stats($period);
        return new WP_REST_Response($stats, 200);
    }

    private function calculate_stats($period)
    {
        // Create DateTime objects and set to UTC or your desired timezone
        $timezone = new DateTimeZone('Europe/Warsaw'); // Replace with your timezone, e.g., 'Europe/Warsaw'
        $end_date = new DateTime('now', $timezone);
        $start_date = new DateTime('now', $timezone);

        switch ($period) {
            case 'yesterday':
                // Set start_date to yesterday at 00:00:00
                $start_date->modify('-1 day')->setTime(0, 0, 0);
                // Set end_date to yesterday at 23:59:59
                $end_date->modify('-1 day')->setTime(23, 59, 59);
                break;

            case 'last-week':
                // Assuming week starts on Monday and ends on Sunday
                // Set to last week's Monday at 00:00:00
                $start_date->modify('last week Monday')->setTime(0, 0, 0);
                // Set to last week's Sunday at 23:59:59
                $end_date->modify('last week Sunday')->setTime(23, 59, 59);
                break;

            case 'last-month':
                // Set to first day of last month at 00:00:00
                $start_date->modify('first day of last month')->setTime(0, 0, 0);
                // Set to last day of last month at 23:59:59
                $end_date->modify('last day of last month')->setTime(23, 59, 59);
                break;

            default:
                // Handle unexpected period values
                throw new InvalidArgumentException('Invalid period specified.');
        }

        // Format dates with time
        $date_created = $start_date->format('Y-m-d H:i:s') . '...' . $end_date->format('Y-m-d H:i:s');

        $args = array(
            'date_created' => $date_created,
            'limit' => -1,
            'return' => 'ids',
        );

        $order_ids = wc_get_orders($args);

        $total_orders = count($order_ids);
        $orders_per_status = array();
        $net_value = 0;
        $net_shipping = 0;
        $net_value_per_status = array();
        $net_shipping_per_status = array();

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            $status = $order->get_status();
            $order_total = $order->get_total();
            $order_shipping = $order->get_shipping_total();

            // Count orders per status
            if (!isset($orders_per_status[$status])) {
                $orders_per_status[$status] = 0;
            }
            $orders_per_status[$status]++;

            // Calculate net values
            $net_value += $order_total - $order_shipping;
            $net_shipping += $order_shipping;

            // Calculate net values per status
            if (!isset($net_value_per_status[$status])) {
                $net_value_per_status[$status] = 0;
                $net_shipping_per_status[$status] = 0;
            }
            $net_value_per_status[$status] += $order_total - $order_shipping;
            $net_shipping_per_status[$status] += $order_shipping;
        }

        return array(
            'total_orders' => $total_orders,
            'orders_per_status' => $orders_per_status,
            'net_value' => $net_value,
            'net_shipping' => $net_shipping,
            'net_value_per_status' => $net_value_per_status,
            'net_shipping_per_status' => $net_shipping_per_status,
            'type' => $period,
            'date_start' => $start_date->format('d-m-Y'),
            'date_end' => $end_date->format('d-m-Y')
        );
    }

    public function schedule_cron()
    {
        if (get_option('wc_order_stats_preload_enabled') === 'yes') {
            $preload_time = get_option('wc_order_stats_preload_time', '00:00');
            if (!wp_next_scheduled('order_stats_cron_hook')) {
                wp_schedule_event(strtotime($preload_time), 'daily', 'order_stats_cron_hook');
            }
        } else {
            wp_clear_scheduled_hook('order_stats_cron_hook');
        }
    }

    public function cron_task()
    {
        $periods = array('yesterday', 'last-week', 'last-month');
        foreach ($periods as $period) {
            $stats = $this->calculate_stats($period);
            set_transient('wc_order_stats_' . $period, $stats, 25 * HOUR_IN_SECONDS);
        }
    }
}

// Initialize the plugin
add_action('plugins_loaded', array('WC_Order_Stats_Plugin', 'get_instance'));

// AJAX handler for generating new API key
add_action('wp_ajax_generate_order_stats_api_key', 'generate_order_stats_api_key');
function generate_order_stats_api_key()
{
    $new_key = bin2hex(random_bytes(16));
    update_option('wc_order_stats_api_key', $new_key);
    echo $new_key;
    wp_die();
}