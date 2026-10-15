<?php

namespace WcoInpost;

defined('ABSPATH') || exit;

/**
 * Metoda wysyłki „Paczkomat InPost” dostępna w strefach wysyłki WooCommerce.
 */
final class ShippingMethod extends \WC_Shipping_Method
{
    public const ID = 'wco_inpost';

    /**
     * @param  int  $instanceId
     */
    public function __construct($instanceId = 0)
    {
        $this->id = self::ID;
        $this->instance_id = absint($instanceId);
        $this->method_title = 'Paczkomat InPost (WCO)';
        $this->method_description = 'Dostawa do wybranego na mapie paczkomatu InPost.';
        $this->supports = ['shipping-zones', 'instance-settings', 'instance-settings-modal'];
        $this->init_form_fields();
        $this->init_settings();
        $this->title = (string) $this->get_option('title', 'Paczkomat InPost');
        add_action('woocommerce_update_options_shipping_'.$this->id.'_'.$this->instance_id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void
    {
        $this->instance_form_fields = [
            'title' => ['title' => 'Nazwa metody', 'type' => 'text', 'default' => 'Paczkomat InPost', 'desc_tip' => true, 'description' => 'Widoczna dla klienta w koszyku i na stronie płatności.'],
            'cost' => ['title' => 'Koszt dostawy', 'type' => 'price', 'default' => '12.99', 'desc_tip' => true, 'description' => 'Kwota netto, jeśli sklep nalicza podatek od wysyłki.'],
            'free_from' => ['title' => 'Darmowa od kwoty', 'type' => 'price', 'default' => '', 'placeholder' => 'Bez progu', 'desc_tip' => true, 'description' => 'Wartość produktów po rabatach, od której dostawa jest gratis. Puste pole wyłącza próg.'],
            'tax_status' => ['title' => 'Podatek', 'type' => 'select', 'class' => 'wc-enhanced-select', 'default' => 'taxable', 'options' => ['taxable' => 'Opodatkowana', 'none' => 'Brak']],
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
