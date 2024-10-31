<?php
/*
Plugin Name: pl8app Cryptocurrency BEP20 Payment Gateway For RestroPress
Plugin URI: https://token.pl8app.co.uk
Description: pl8app Cryptocurrency BEP20 Payment Gateway For RestroPress
Author: pl8apptoken
Text Domain: pl8app-cryptocurrency-bep20-payment-gateway-for-restropress
Version: 1.0
*/

add_action('plugins_loaded', 'pl8app_rpr_init_gateways');

define('pl8app_rpr_HD_TABLE', 'pl8app_rpr_pro_hd_addresses');
define('pl8app_rpr_PAYMENT_TABLE', 'pl8app_rpr_pro_payments');
define('pl8app_rpr_CAROUSEL_TABLE', 'pl8app_rpr_pro_carousel');
define('pl8app_rpr_HD_TABLE_VERSION', '1.1');
define('pl8app_rpr_LOGFILE_NAME', 'pl8app_rpr.log');
define('pl8app_rpr_REDUX_ID', 'pl8app_rpr_pro_redux_options');
define('pl8app_rpr_EXTENSION_KEY', 'pl8app_rpr_registered_extensions');

define('pl8app_rpr_PLUGIN_DIR', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)));
define('pl8app_rpr_PLUGIN_FILE', __FILE__);
define('pl8app_rpr_ABS_PATH', dirname(pl8app_rpr_PLUGIN_FILE));
define('pl8app_rpr_PLUGIN_BASENAME', plugin_basename(pl8app_rpr_PLUGIN_FILE));

define('pl8app_rpr_CRON_JOB_URL', plugins_url('', __FILE__) . '/src/pl8app_rpr_Cron.php');
define('pl8app_rpr_VERSION', '1.0.0');

define('pl8app_rpr_REDUX_SLUG', 'pl8app_rpr_pro_options');


register_activation_hook(__FILE__, 'pl8app_rpr_activate');
register_deactivation_hook(__FILE__, 'pl8app_rpr_deactivate');
register_uninstall_hook(__FILE__, 'pl8app_rpr_uninstall');

require_once(plugin_basename('src/pl8app_rpr_Settings.php'));

function pl8app_rpr_init_gateways(){
    if (!class_exists('RestroPress')) {
        return;
    };

    // Vendor
    if (!class_exists('bcmath_Utils')) {
        require_once(plugin_basename('src/vendor/bcmath_Utils.php'));
    }
    if (!class_exists('CurveFp')) {
        require_once(plugin_basename('src/vendor/CurveFp.php'));
    }
    if (!class_exists('HdHelper')) {
        require_once(plugin_basename('src/vendor/HdHelper.php'));
    }
    if (!class_exists('gmp_Utils')) {
        require_once(plugin_basename('src/vendor/gmp_Utils.php'));
    }
    if (!class_exists('NumberTheory')) {
        require_once(plugin_basename('src/vendor/NumberTheory.php'));
    }
    if (!class_exists('Point')) {
        require_once(plugin_basename('src/vendor/Point.php'));
    }
    if (!class_exists('\CashAddress\CashAddress')) {
        require_once(plugin_basename('src/vendor/CashAddress.php'));
    }
    if (!class_exists('QRinput')) {
        require_once(plugin_basename('src/vendor/phpqrcode.php'));
    }

    // Http
    require_once(plugin_basename('src/pl8app_rpr_Exchange.php'));
    require_once(plugin_basename('src/pl8app_rpr_Blockchain.php'));

    // Database
    require_once(plugin_basename('src/pl8app_rpr_Carousel_Repo.php'));
    require_once(plugin_basename('src/pl8app_rpr_Hd_Repo.php'));
    require_once(plugin_basename('src/pl8app_rpr_Payment_Repo.php'));

    // Simple Objects
    require_once(plugin_basename('src/pl8app_rpr_Cryptocurrency.php'));
    require_once(plugin_basename('src/pl8app_rpr_Transaction.php'));

    // Business Logic
    require_once(plugin_basename('src/pl8app_rpr_Cryptocurrencies.php'));
    require_once(plugin_basename('src/pl8app_rpr_Carousel.php'));
    require_once(plugin_basename('src/pl8app_rpr_Hd.php'));
    require_once(plugin_basename('src/pl8app_rpr_Payment.php'));

    // Misc
    require_once(plugin_basename('src/pl8app_rpr_Util.php'));
    require_once(plugin_basename('src/pl8app_rpr_Hooks.php'));
    require_once(plugin_basename('src/pl8app_rpr_Cron.php'));
    require_once(plugin_basename('src/pl8app_rpr_Admin.php'));
    require_once(plugin_basename('src/pl8app_rpr_Settings.php'));

    require_once(plugin_basename('src/pl8app_rpr_Validation.php'));

    // Core
    require_once(plugin_basename('src/pl8app_rpr_Gateway.php'));

    add_filter ('cron_schedules', 'pl8app_rpr_add_interval');

    add_action('pl8app_rpr_cron_hook', 'pl8app_rpr_do_cron_job');
    add_action('rpress_update_payment_status', 'pl8app_rpr_update_database_when_admin_changes_order_status', 10, 3);

    add_action('admin_notices', 'pl8app_rpr_display_flash_notices', 12);

    if (is_admin()) {
        add_action('admin_enqueue_scripts', 'pl8app_rpr_load_js_css');
        add_action('wp_ajax_firstmpkaddress', 'pl8app_rpr_first_mpk_address_ajax');
    }

    pl8app_rpr_Register_Extensions();
    pl8app_rpr_update_hd_table();

    if (!wp_next_scheduled('pl8app_rpr_cron_hook')) {
        wp_schedule_event(time(), 'seconds_30', 'pl8app_rpr_cron_hook');
    }

    //register custom crypto currency post type

    require_once(plugin_basename('src/pl8app_rpr_Custom_Cryptocurrency.php'));
    $new_cpt = new pl8app_rpr_Custom_Cryptocurrency();

    add_filter('rpress_payment_gateways', 'pl8app_rpr_filter_gateways', NULL, 10);
}

function pl8app_rpr_add_interval ($schedules)
{
    $schedules['seconds_5'] = array('interval'=>5, 'display'=>'debug');
    $schedules['seconds_30'] = array('interval'=>30, 'display'=>'Bi-minutely');
    $schedules['minutes_1'] = array('interval'=>60, 'display'=>'Once every 1 minute');
    $schedules['minutes_2'] = array('interval'=>120, 'display'=>'Once every 2 minutes');

    return $schedules;
}

function pl8app_rpr_activate() {
    if (!wp_next_scheduled('pl8app_rpr_cron_hook')) {
        wp_schedule_event(time(), 'seconds_30', 'pl8app_rpr_cron_hook');
    }

    pl8app_rpr_create_hd_mpk_address_table();
    pl8app_rpr_create_payment_table();
    pl8app_rpr_create_carousel_table();
    pl8app_rpr_predefine_settings();
}

function pl8app_rpr_deactivate() {
    wp_clear_scheduled_hook('pl8app_rpr_cron_hook');
}

function pl8app_rpr_uninstall() {
    pl8app_rpr_drop_mpk_address_table();
    pl8app_rpr_drop_payment_table();
    pl8app_rpr_drop_carousel_table();
}

function pl8app_rpr_drop_mpk_address_table() {
    global $wpdb;
    $tableName = $wpdb->prefix . pl8app_rpr_HD_TABLE;

    $query = "DROP TABLE IF EXISTS `$tableName`";
    $wpdb->query($query);
}

function pl8app_rpr_drop_payment_table() {
    global $wpdb;
    $tableName = $wpdb->prefix . pl8app_rpr_PAYMENT_TABLE;

    $query = "DROP TABLE IF EXISTS `$tableName`";
    $wpdb->query($query);
}

function pl8app_rpr_drop_carousel_table() {
    global $wpdb;
    $tableName = $wpdb->prefix . pl8app_rpr_CAROUSEL_TABLE;

    $query = "DROP TABLE IF EXISTS `$tableName`";
    $wpdb->query($query);
}

function pl8app_rpr_create_hd_mpk_address_table() {
    global $wpdb;
    $tableName = $wpdb->prefix . pl8app_rpr_HD_TABLE;

    $query = "CREATE TABLE IF NOT EXISTS `$tableName`
        (
            `id` bigint(12) unsigned NOT NULL AUTO_INCREMENT,
            `mpk` char(150) NOT NULL,
            `mpk_index` bigint(20) NOT NULL DEFAULT '0',
            `address` char(199) NOT NULL,
            `cryptocurrency` char(7) NOT NULL,
            `status` char(24)  NOT NULL DEFAULT 'error',
            `total_received` decimal( 16, 8 ) NOT NULL DEFAULT '0.00000000',
            `last_checked` bigint(20) NOT NULL DEFAULT '0',
            `assigned_at` bigint(20) NOT NULL DEFAULT '0',
            `order_id` bigint(10) NULL,
            `order_amount` decimal(16, 8) NOT NULL DEFAULT '0.00000000',
            `all_order_ids` text NULL,

            PRIMARY KEY (`id`),
            UNIQUE KEY `hd_address` (`cryptocurrency`, `address`),
            KEY `status` (`status`),
            KEY `mpk_index` (`mpk_index`),
            KEY `mpk` (`mpk`)
        );";

    $wpdb->query($query);
}

function pl8app_rpr_update_hd_table() {
    global $wpdb;

    if (get_option('pl8app_rpr_hd_table_version', '1.0') === '1.0') {
        update_option('pl8app_rpr_hd_table_version', '1.1');

        $tableName = $wpdb->prefix . pl8app_rpr_HD_TABLE;

        $query = "ALTER TABLE `$tableName` ADD `hd_mode` bigint(10) NOT NULL default '0'";
        $wpdb->query($query);
    }

}

function pl8app_rpr_create_payment_table() {
    global $wpdb;
    $tableName = $wpdb->prefix . pl8app_rpr_PAYMENT_TABLE;

    $query = "CREATE TABLE IF NOT EXISTS `$tableName`
        (
            `id` bigint(12) unsigned NOT NULL AUTO_INCREMENT,
            `address` char(199) NOT NULL,
            `cryptocurrency` char(7) NOT NULL,
            `status` char(24)  NOT NULL DEFAULT 'error',
            `ordered_at` bigint(20) NOT NULL DEFAULT '0',
            `order_id` bigint(10) NOT NULL DEFAULT '0',
            `order_amount` decimal(32, 18) NOT NULL DEFAULT '0.000000000000000000',
            `tx_hash` char(255) NULL,
            `hd_address` tinyint(4) NOT NULL DEFAULT '0',


            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_payment` (`order_id`, `order_amount`),
            KEY `status` (`status`)
        );";

    $wpdb->query($query);
}

function pl8app_rpr_create_carousel_table() {
    global $wpdb;
    $tableName = $wpdb->prefix . pl8app_rpr_CAROUSEL_TABLE;

    $query = "CREATE TABLE IF NOT EXISTS `$tableName`
        (
            `id` bigint(12) unsigned NOT NULL AUTO_INCREMENT,
            `cryptocurrency` char(12) NOT NULL,
            `current_index` bigint(20) NOT NULL DEFAULT '0',
            `buffer` text NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `cryptocurrency` (`cryptocurrency`)
        );";

    $wpdb->query($query);

    require_once(plugin_basename('src/pl8app_rpr_Cryptocurrency.php'));
    require_once(plugin_basename('src/pl8app_rpr_Carousel_Repo.php'));
    require_once(plugin_basename('src/pl8app_rpr_Util.php'));
    require_once(plugin_basename('src/pl8app_rpr_Cryptocurrencies.php'));

    pl8app_rpr_Carousel_Repo::init();

    $cryptos = pl8app_rpr_Cryptocurrencies::get();

    $reduxOptions = get_option(pl8app_rpr_REDUX_ID, array());

    if (!empty($reduxOptions)) {
        $pl8appRprSettings = new pl8app_rpr_Settings($reduxOptions);

        foreach ($cryptos as $crypto) {
            $addresses = $pl8appRprSettings->get_addresses($crypto->get_id());
            if (!empty($addresses)) {
                $carouselRepo = new pl8app_rpr_Carousel_Repo();
                $carouselRepo->set_buffer($crypto->get_id(), $addresses);
            }
        }
    }
}

function pl8app_rpr_predefine_settings(){
    $reduxOptions = get_option(pl8app_rpr_REDUX_ID, array());

    //Payment Label set
    $reduxOptions['payment_label'] = 'Pay with BEP20 cryptocurrency';
    //Set the pl8app token information
    $reduxOptions['crypto_select'] = array('pl8app');
    $reduxOptions['pl8app_markup'] = 0;
    $reduxOptions['pl8app_mode'] = '1';
    $reduxOptions['pl8app_addresses'] = array();
    $reduxOptions['selected_price_apis'] = array(0);





    //put default pl8app token into CPT list

    $arg = array (
        'name' => 'pl8app',
        'post_type' => 'pl8app_rpr_cstm_crpt',
        'post_status' => 'publish'
    );
    $posts = new WP_Query( $arg );

    if ( !$posts->have_posts() ) {
        $arg['post_title'] = 'pl8app';
        $post_id = wp_insert_post( $arg );
        update_post_meta($post_id, 'contract_address', '0xb77178a0fdead814296eae631be8e8171c02592b');
    }else{
        while ($posts->have_posts()) : $posts->the_post();
            // Process Your Statements
            //ex the_content()
            $post_id = get_the_ID();
            break;
        endwhile;
    }
    $reduxOptions['pl8app_rpr_default_token_post_id'] = $post_id;
    //Update redux options
    update_option(pl8app_rpr_REDUX_ID,$reduxOptions);
}

function pl8app_rpr_Register_Extensions() {
    $extensionsDir = pl8app_rpr_ABS_PATH . '/src/extensions/';
    $extensions = scandir($extensionsDir);
    $extensionsToLoad = [];
    if (!is_array($extensions)) {
        return;
    }
    foreach ($extensions as $extension) {
        if ( $extension === '.' || $extension === '..' || ! is_dir( $extensionsDir . $extension ) || substr( $extension, 0, 1 ) === '.' || substr( $extension, 0, 1 ) === '@' ) {
            continue;
        }

        $extensionsToLoad[] = $extension;
        @include_once(plugin_basename('src/extensions/' . $extension . '/pl8app_rpr_' . ucfirst($extension) . '.php'));
    }

    update_option(pl8app_rpr_EXTENSION_KEY, $extensionsToLoad);
}
