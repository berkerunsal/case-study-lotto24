<?php
/**
 * Plugin Name: Lotto24 Products
 * Plugin URI: https://google.com
 * Description: Displays a list of Lotto24 Products. Use [lotto_products] shortocde to display products.
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
        add_filter('init_icon', array($this, 'init_icon'), 10, 1);
        add_filter('get_image', array($this, 'get_image'));

        add_action('init', function () {
            add_rewrite_endpoint('add_product.php', EP_PERMALINK);
            add_rewrite_endpoint('upload.php', EP_PERMALINK);
        });
        add_filter('init', function ($template) {
            if (str_starts_with($_SERVER['REQUEST_URI'], "/add_product.php")) {

                include plugin_dir_path(__FILE__) . 'add_product.php';
                die;
            }
            if (str_starts_with($_SERVER['REQUEST_URI'], "/upload.php")) {

                include plugin_dir_path(__FILE__) . 'upload.php';
                die;
            }
        });
    }

    public function product_scripts()
    {

        wp_enqueue_style('lotto-products', plugin_dir_url(__FILE__) . 'assets/css/lotto-products.css');
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
            INSERT INTO `$products_table` ( `product_id`, `api_key`, `display_name`, `productIcon`)
            VALUES ( %s, %s, %s, %s )",
                $product["productId"], $api_key, $product["displayName"], $icon
            );
            $wpdb->query($sql);

        }

    }
    public function hash_exist($hash)
    {
        global $wpdb;
        $option = $wpdb->get_row($wpdb->prepare("
    SELECT * FROM $wpdb->options
    WHERE `option_value` = %s",
            $hash));
        if ($option) {
            return true;
        } else {
            return false;
        }

    }
    public function init_icon($product)
    {
        $icon = $product["productIcon"];

        if (file_exists(plugin_dir_path(__FILE__) . 'assets/icons/' . $icon)) {
            if (get_option($icon)) {
                $hash = get_option($icon);
            } else {
                $hash = base64_encode(random_bytes(20));
            }
            if (get_option($icon) !== false) {
                update_option($icon, $hash);
            } else {
                add_option($icon, $hash);
            }

        } else {
            $hash = get_option('default.svg');
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
        $i = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->options WHERE `option_value` = %s", $hash), OBJECT);
        $icon = $i->option_name;
        if ($icon) {
            return $icon;
        } else {
            return "default.svg";
        }

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
        ["productId" => "lotto-6-aus-49", "apiKey" => "6aus49", "displayName" => "LOTTO 6 aus 49", "productIcon" => "lotto-6-aus-49.svg"],
        ["productId" => "eurojackpot", "apiKey" => "eurojackpot", "displayName" => "EUROJACKPOT", "productIcon" => "eurojackpot.svg"],
        ["productId" => "freiheitplus", "apiKey" => "freiheitplus", "displayName" => "Freiheit+", "productIcon" => "freiheitplus.svg"],
        ["productId" => "traumhauslotterie", "apiKey" => "traumhauslotterie", "displayName" => "TRAUMHAUSLOTTERIE", "productIcon" => "traumhauslotterie.svg"],
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
      productIcon longtext,
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
function get_product_meta($product_id, $meta_key)
{
    global $wpdb;
    $meta_table = $wpdb->prefix . "lotto_products_meta";
    $meta = $wpdb->get_row($wpdb->prepare("
        SELECT * FROM `$meta_table`
        WHERE `product_id` = %s AND meta_key = %s",
        $product_id, $meta_key));

    if ($meta) {
        return $meta->meta_value;
    }
}

function is_active($product_id)
{

    if (get_product_meta($product_id, "is_current") == 1) {
        return true;
    } else {
        return false;
    }
}
function jackpot_format($n)
{
    if ($n > 1000000) {

        return round(($n / 1000000), 2) . ' MILLION';
    } elseif ($n > 1000) {
        return round(($n / 1000), 2) . ' THOUSANDS';
    }

    return number_format($n);
}
function currency_format($c)
{
    if ($c = "euro") {
        return "€";
    }

}
function display_products()
{
    global $wpdb;
    $products_table = $wpdb->prefix . "lotto_products";
    $products = $wpdb->get_results("SELECT * FROM $products_table");
    echo '<div class="container">';
    foreach ($products as $product) {

        $product_id = $product->product_id;
        $img_hash = $product->productIcon;
        $img = apply_filters('get_image', $img_hash);
        echo '<div class="card ' . $product_id . '">
       <div class="top">
       <img src="' . plugin_dir_url(__FILE__) . "assets/icons/" . $img . '">
       <h3>' . $product->display_name . '</h3>
       <h3 class="cutoff">';
        if (!is_active($product_id)) {
            echo 'Cut Off<br>';
            $cutoff_stmp = get_product_meta($product_id, "cutoff_time");
            $cutoff_date = strtotime($cutoff_stmp);
            echo date("d/M/Y", $cutoff_date);

        }

        echo '</h3></div>
       <div class="content">';
        if (is_active($product_id)) {

            $jackpot = get_product_meta($product_id, "jackpots");
            $currency = get_product_meta($product_id, "currency");
            echo "Current Jackpot: ";
            echo jackpot_format($jackpot) . ' ' . currency_format($currency) . '<br>';
        }
        $draw_date_stmp = get_product_meta($product_id, "draw_date");
        $draw_date = strtotime($draw_date_stmp);
        echo "Draw Date: ";
        echo date("d/M/Y", $draw_date);
        echo '</div>
     </div>';
    }
    echo '</div>';
}
add_shortcode('lotto_products', 'display_products');

$lotto_product = new Lotto_Product();
