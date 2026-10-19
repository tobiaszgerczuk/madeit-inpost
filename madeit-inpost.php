<?php

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use MadeitInpost\Plugin;
use MadeitInpost\Updater;

/**
 * Plugin Name: Madeit InPost
 * Description: Wybór paczkomatu InPost na mapie w checkoucie WooCommerce oraz tworzenie przesyłek i etykiet przez ShipX.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * Author: WCO Labs
 * Update URI: https://labs.made-it.pl/updates/madeit-inpost
 */
defined('ABSPATH') || exit;

define('MADEIT_INPOST_FILE', __FILE__);

require_once __DIR__.'/src/Calculator.php';
require_once __DIR__.'/src/ShipX.php';
require_once __DIR__.'/src/Orders.php';
require_once __DIR__.'/src/Admin.php';
require_once __DIR__.'/src/Plugin.php';
require_once __DIR__.'/src/Updater.php';

add_action('before_woocommerce_init', static function (): void {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('plugins_loaded', static function (): void {
    if (! class_exists('WooCommerce')) {
        return;
    }

    (new Plugin)->register();
    $apiUrl = defined('WCO_LABS_API_URL') ? WCO_LABS_API_URL : 'https://labs.made-it.pl';
    (new Updater(__FILE__, $apiUrl))->register();
});
