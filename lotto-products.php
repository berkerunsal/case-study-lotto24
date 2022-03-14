<?php
/**
 * Plugin Name: Lotto24 Products
 * Plugin URI: https://google.com
 * Description: Displays a list of Lotto24 Products.
 * Version: 1.0
 * Author: Berker Unsal
 * Author URI: https://google.com
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
add_action('current_products_update', 'update_current_products');
add_action('all_products_update', 'update_all_products');

class Lotto_Product
{

    public function __construct()
    {

        add_action('wp_enqueue_scripts', array($this, 'product_scripts'));
        add_action('init_icon', array($this, 'init_icon'));
        add_action('save_product', array($this, 'save_product'));
        add_filter('is_current', array($this, 'is_current'), 10, 1);
        add_action('add_product_meta', array($this, 'add_product_meta'), 10, 3);
        add_filter('cron_schedules', array($this, 'custom_interval'));
        add_filter('is_product_exist', array($this, 'check_product_id'));
        add_filter('get_icon', array($this, 'get_icon'));

    }

    public function product_scripts()
    {

        wp_enqueue_style('lotto-products', plugin_dir_url(__FILE__) . '/assets/css/lotto-products.css');
    }

    public function save_product($product)
    {

        global $wpdb;
        $products_table = $wpdb->prefix . "lotto_products";
        $api_key = $product["apiKey"];
        $icon = $this->init_icon($product);
        $url = "https://api.lotto24.de/drawinfo/$api_key/nextdraw";

        $request = wp_remote_get($url);

        if (is_wp_error($request)) {
            return false;
        }
        $body = wp_remote_retrieve_body($request);
        $data = json_decode($body);

        if (!empty($data)) {

            $sql = $wpdb->prepare("
            INSERT INTO `$products_table` ( `product_id`, `api_key`, `display_name`, `icon_hash`)
            VALUES ( %s, %s, %s, %s )",
                $product["productId"], $api_key, $product["displayName"], $icon
            );
            $wpdb->query($sql);

        }

    }

    public function init_icon($product)
    {
        $icon = $product["icon"];

        if (file_exists(plugin_dir_path(__FILE__) . 'assets/icons/' . $icon)) {
            $file = $icon;
        } else {
            $file = 'default.svg';
        }
        $hash = base64_encode(random_bytes(20));
        if (get_option($file) !== false) {
            update_option($file, $hash);
        } else {
            add_option($file, $hash);
        }

        return $hash;
    }
    public function is_current($api_key)
    {

        $url = "https://api.lotto24.de/drawinfo/$api_key/jackpot";
        $request = wp_remote_get($url);
        $response_code = wp_remote_retrieve_response_code($request);

        if ($response_code == 200 && !is_wp_error($request)) {

            return true;
        } else {

            return false;
        }

    }

    public function add_product_meta($product_id, $meta_key, $meta_value)
    {
        global $wpdb;
        $meta_table = $wpdb->prefix . "lotto_products_meta";

        $sql = $wpdb->prepare("
    INSERT INTO `$meta_table` ( `product_id`, `meta_key`, `meta_value`)
    VALUES ( %s, %s, %s)
    ON DUPLICATE KEY UPDATE `meta_value` = VALUES( `meta_value` )",
            $product_id, $meta_key, $meta_value
        );
        $wpdb->query($sql);

    }
    public function get_icon($hash)
    {
        global $wpdb;
        $icon = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                FROM $wpdb->options
                WHERE option_value = %s",
                $hash
            )
        );
        $icons = plugin_dir_url(__FILE__) . 'assets/icons/' . $icon->option_name;
        return $icons;
    }
    public function custom_interval($schedule)
    {
        $schedules['every_five_minutes'] = array(
            'interval' => 30,
            'display' => __('Every 5 Minutes', 'textdomain'),
        );
        return $schedules;
    }
    public function get_image($hash)
    {
        global $wpdb;
        $icon = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
            FROM $wpdb->options
            WHERE option_value = %s",
                $hash
            )
        );

        $icons = plugin_dir_url(__FILE__) . 'assets/icons/' . $icon->option_name;
        return $icons;

    }
    public function check_product_id($product_id)
    {
        global $wpdb;
        $products_table = $wpdb->prefix . "lotto_products";
        $products = $wpdb->get_results("SELECT * FROM $products_table");
        foreach ($products as $product) {
            $ids[] = $product->product_id;
        }
        if (in_array($product_id, $ids)) {
            return true;
        } else {
            return false;
        }
    }
}

register_activation_hook(__FILE__, 'activation');
register_deactivation_hook(__FILE__, 'deactivation');

function activation()
{

    wp_schedule_event(time(), 'every_five_minutes', 'current_products_update');
    wp_schedule_event(time(), 'every_five_minutes', 'all_products_update');
    create_products_table();
    create_products_meta_table();
    init_products();
    update_current_products();
    update_all_products();

}

function deactivation()
{
    drop_tables();
    wp_clear_scheduled_hook('current_products_update');
    wp_clear_scheduled_hook('all_products_update');
}
function drop_tables()
{
    global $wpdb;
    $products_table = $wpdb->prefix . "lotto_products";
    $meta_table = $wpdb->prefix . "lotto_products_meta";
    $sql = "DROP TABLE IF EXISTS $products_table, $meta_table";
    $wpdb->query($sql);
}
function init_products()
{
    $initial_products = [
        ["productId" => "lotto-6-aus-49", "apiKey" => "6aus49", "displayName" => "LOTTO 6 aus 49", "icon" => "lotto-6-aus-49.svg"],
        ["productId" => "eurojackpot", "apiKey" => "eurojackpot", "displayName" => "EUROJACKPOT", "icon" => "eurojackpot.svg"],
        ["productId" => "freiheitplus", "apiKey" => "freiheitplus", "displayName" => "Freiheit+", "icon" => "freiheitplus.svg"],
        ["productId" => "traumhauslotterie", "apiKey" => "traumhauslotterie", "displayName" => "TRAUMHAUSLOTTERIE", "icon" => "traumhauslotterie.svg"],
    ];

    foreach ($initial_products as $product) {

        do_action('save_product', $product);

    }

}

function update_current_products()
{
    global $wpdb;
    $products_table = $wpdb->prefix . "lotto_products";
    $products = $wpdb->get_results("SELECT * FROM $products_table");

    foreach ($products as $product) {
        $api_key = $product->api_key;
        if (apply_filters('is_current', $api_key)) {
            $url = "https://api.lotto24.de/drawinfo/$api_key/jackpot";
            $request = wp_remote_get($url);
            if (is_wp_error($request)) {
                return false;
            }
            $body = wp_remote_retrieve_body($request);
            $data = json_decode($body);

            if (!empty($data)) {

                do_action('add_product_meta', $product->product_id, "currency", $data->currency);
                do_action('add_product_meta', $product->product_id, "jackpots", $data->jackpots->WC_1);
            }
        }

    }

}

function update_all_products()
{
    global $wpdb;
    $products_table = $wpdb->prefix . "lotto_products";
    $products = $wpdb->get_results("SELECT * FROM $products_table");

    foreach ($products as $product) {
        $api_key = $product->api_key;
        $is_current = 0;
        $url = "https://api.lotto24.de/drawinfo/$api_key/nextdraw";
        $request = wp_remote_get($url);
        if (is_wp_error($request)) {
            return false;
        }
        $body = wp_remote_retrieve_body($request);
        $data = json_decode($body);

        if (!empty($data)) {

            do_action('add_product_meta', $product->product_id, "draw_date", $data->drawDate);
            do_action('add_product_meta', $product->product_id, "cutoff_time", $data->cutofftime);
            if (apply_filters('is_current', $api_key) == true) {
                $is_current = 1;
            }
            do_action('add_product_meta', $product->product_id, "is_current", $is_current);

        }

    }

}
function create_products_table()
{
    global $wpdb;

    $products_table = $wpdb->prefix . "lotto_products";
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $products_table (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      product_id text NOT NULL,
      api_key text NOT NULL,
      display_name text NOT NULL,
      icon_hash longtext,
      PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function create_products_meta_table()
{
    global $wpdb;

    $meta_table = $wpdb->prefix . "lotto_products_meta";
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $meta_table  (
      meta_id mediumint(9) NOT NULL AUTO_INCREMENT,
      product_id varchar(255) NOT NULL,
      meta_key varchar(255) NOT NULL,
      meta_value text NOT NULL,
      PRIMARY KEY  (meta_id),
      UNIQUE INDEX (product_id(50), meta_key(50))
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

}

$lotto_product = new Lotto_Product();
