<?php

namespace Madeit\Inpost;

defined('ABSPATH') || exit;

/**
 * The "InPost Parcel Locker" shipping method, available in the WooCommerce shipping zones.
 */
final class ShippingMethod extends \WC_Shipping_Method
{
    public const ID = Plugin::METHOD_ID;

    /**
     * @param  int  $instanceId
     */
    public function __construct($instanceId = 0)
    {
        $this->id = self::ID;
        $this->instance_id = absint($instanceId);
        $this->method_title = __('InPost Parcel Locker (MadeIT)', 'madeit-inpost');
        $this->method_description = __('Delivery to an InPost parcel locker chosen on a map.', 'madeit-inpost');
        $this->supports = ['shipping-zones', 'instance-settings', 'instance-settings-modal'];
        $this->init_form_fields();
        $this->init_settings();
        $this->title = (string) $this->get_option('title', __('InPost Parcel Locker', 'madeit-inpost'));
        add_action('woocommerce_update_options_shipping_'.$this->id.'_'.$this->instance_id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void
    {
        $this->instance_form_fields = [
            'title' => ['title' => __('Method name', 'madeit-inpost'), 'type' => 'text', 'default' => __('InPost Parcel Locker', 'madeit-inpost'), 'desc_tip' => true, 'description' => __('Shown to the customer in the cart and at checkout.', 'madeit-inpost')],
            'cost' => ['title' => __('Shipping cost', 'madeit-inpost'), 'type' => 'price', 'default' => '12.99', 'desc_tip' => true, 'description' => __('The net amount if your shop charges tax on shipping.', 'madeit-inpost')],
            'free_from' => ['title' => __('Free from', 'madeit-inpost'), 'type' => 'price', 'default' => '', 'placeholder' => __('No threshold', 'madeit-inpost'), 'desc_tip' => true, 'description' => __('The value of products after discounts from which shipping is free. Leave empty to turn the threshold off.', 'madeit-inpost')],
            'tax_status' => ['title' => __('Tax status', 'madeit-inpost'), 'type' => 'select', 'class' => 'wc-enhanced-select', 'default' => 'taxable', 'options' => ['taxable' => __('Taxable', 'madeit-inpost'), 'none' => __('None', 'madeit-inpost')]],
        ];
    }

    /**
     * @param  array<string, mixed>  $package
     */
    public function calculate_shipping($package = []): void
    {
        $cost = (float) wc_format_decimal((string) $this->get_option('cost', '0'));
        $freeFrom = (float) wc_format_decimal((string) $this->get_option('free_from', ''));
        $total = (float) ($package['cart_subtotal'] ?? 0) - (float) ($package['discount_total'] ?? 0);
        if (isset($package['contents_cost'])) {
            $total = (float) $package['contents_cost'];
        }
        $this->add_rate([
            'id' => $this->get_rate_id(),
            'label' => $this->title,
            'cost' => Calculator::shippingCost($cost, $freeFrom, $total),
            'package' => $package,
            'taxes' => $this->get_option('tax_status') === 'none' ? false : '',
        ]);
    }
}
