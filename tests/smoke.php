<?php

/**
 * The pickup point flow: shipping zone method, saving the point in an order, showing it and the order box.
 * Run: wp eval-file wp-content/plugins/madeit-inpost/tests/smoke.php
 * It creates and deletes a shipping zone and an order, so use it only in an isolated test site.
 */

use Madeit\Inpost\Plugin;
use Madeit\Inpost\ShippingMethod;

function madeit_inpost_expect(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

WC()->shipping()->load_shipping_methods();
$original = get_option('madeit_inpost_settings', []);
update_option('madeit_inpost_settings', ['require_point' => 'yes', 'geowidget_token' => ''], false);

foreach (WC_Shipping_Zones::get_zones() as $leftover) {
    if ($leftover['zone_name'] === 'Smoke zone') {
        (new WC_Shipping_Zone($leftover['id']))->delete();
    }
}
$zone = new WC_Shipping_Zone;
$zone->set_zone_name('Smoke zone');
$zone->add_location('PL', 'country');
$zone->save();
$instance = $zone->add_shipping_method(ShippingMethod::ID);
$method = WC_Shipping_Zones::get_shipping_method($instance);
$method->instance_settings['cost'] = '9.99';
$method->instance_settings['free_from'] = '100';
update_option($method->get_instance_option_key(), $method->instance_settings);

$rows = array_filter(Plugin::methods(), static fn (array $row): bool => $row['zone'] === 'Smoke zone');
madeit_inpost_expect(count($rows) === 1 && reset($rows)['cost'] === '9.99', 'The method is listed with its cost.');

$method->calculate_shipping(['contents_cost' => 50, 'cart_subtotal' => 50, 'discount_total' => 0, 'contents' => []]);
$rate = reset($method->rates);
madeit_inpost_expect($rate && (float) $rate->get_cost() === 9.99, 'Below the threshold the cost is charged.');
$method->rates = [];
$method->calculate_shipping(['contents_cost' => 120, 'cart_subtotal' => 120, 'discount_total' => 0, 'contents' => []]);
madeit_inpost_expect((float) reset($method->rates)->get_cost() === 0.0, 'From the threshold shipping is free.');

$order = wc_create_order();
$item = new WC_Order_Item_Shipping;
$item->set_method_title('InPost Parcel Locker');
$item->set_method_id(ShippingMethod::ID);
$item->set_instance_id($instance);
$order->add_item($item);
$order->save();
madeit_inpost_expect(Plugin::orderUsesInpost($order), 'The order uses the InPost method.');

$plugin = new Plugin;
$_POST['madeit_inpost_point'] = 'waw01m';
$_POST['madeit_inpost_point_desc'] = '10 Marszalkowska St, Warsaw';
$plugin->saveClassic($order, []);
$order->save();
madeit_inpost_expect($order->get_meta(Plugin::META_POINT) === 'WAW01M', 'The point code is saved in upper case.');
madeit_inpost_expect($order->get_meta(Plugin::META_POINT_DESCRIPTION) === '10 Marszalkowska St, Warsaw', 'The point address is saved.');

$_POST['madeit_inpost_point'] = "bad point'; DROP";
$other = wc_create_order();
$other->add_item(clone $item);
$other->save();
$plugin->saveClassic($other, []);
madeit_inpost_expect($other->get_meta(Plugin::META_POINT) === '', 'An invalid point is not saved.');

ob_start();
$plugin->showPointInOrder($order);
$html = (string) ob_get_clean();
madeit_inpost_expect(str_contains($html, 'WAW01M') && str_contains($html, 'Marszalkowska'), 'The order page shows the point.');

ob_start();
(new Madeit\Inpost\Orders)->renderMetaBox($order);
$box = (string) ob_get_clean();
madeit_inpost_expect(str_contains($box, 'WAW01M') && (defined('MADEIT_INPOST_PRO_VERSION') || str_contains($box, 'MadeIT InPost Pro')), 'The order box shows the point and the Pro hint.');

$picker = (new ReflectionMethod($plugin, 'pickerMarkup'));
$picker->setAccessible(true);
$markup = (string) $picker->invoke($plugin, 'classic');
madeit_inpost_expect(str_contains($markup, 'data-madeit-inpost-manual'), 'Without a token the point code field is shown.');
update_option('madeit_inpost_settings', ['geowidget_token' => 'token'], false);
madeit_inpost_expect(str_contains((string) $picker->invoke($plugin, 'classic'), 'data-madeit-inpost-open'), 'With a token the map button is shown.');

$order->delete(true);
$other->delete(true);
$zone->delete();
update_option('madeit_inpost_settings', $original, false);
unset($_POST['madeit_inpost_point'], $_POST['madeit_inpost_point_desc']);
echo "OK\n";
