<?php
/**
 * Plugin Name: MadeIT InPost
 * Plugin URI: https://labs.made-it.pl/en/plugins/madeit-inpost
 * Description: An InPost parcel locker shipping method for WooCommerce with a map to choose the pickup point at checkout.
 * Version: 1.0.0
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: WCO Labs
 * Author URI: https://labs.made-it.pl/en
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: madeit-inpost
 * Domain Path: /languages
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Madeit\Inpost\Plugin;

defined('ABSPATH') || exit;

define('MADEIT_INPOST_FILE', __FILE__);
define('MADEIT_INPOST_VERSION', '1.0.0');

require_once __DIR__.'/src/Calculator.php';
require_once __DIR__.'/src/Orders.php';
require_once __DIR__.'/src/Admin.php';
require_once __DIR__.'/src/Plugin.php';

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
});
