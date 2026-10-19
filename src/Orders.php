<?php

namespace Madeit\Inpost;

defined('ABSPATH') || exit;

/**
 * The InPost box on the order screen: the pickup point chosen by the customer.
 */
final class Orders
{
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'metaBox']);
    }

    public function metaBox(): void
    {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
        add_meta_box('madeit-inpost-order', __('InPost', 'madeit-inpost'), [$this, 'renderMetaBox'], $screen, 'side', 'high');
    }

    /**
     * @param  \WP_Post|\WC_Order  $postOrOrder
     */
    public function renderMetaBox($postOrOrder): void
    {
        $order = $postOrOrder instanceof \WC_Order ? $postOrOrder : wc_get_order($postOrOrder->ID);
        if (! $order instanceof \WC_Order) {
            return;
        }
        $point = (string) $order->get_meta(Plugin::META_POINT);
        if (! Plugin::orderUsesInpost($order) && $point === '') {
            echo '<p>'.esc_html__('This order does not use the "InPost Parcel Locker" method.', 'madeit-inpost').'</p>';

            return;
        }
        $description = (string) $order->get_meta(Plugin::META_POINT_DESCRIPTION);
        echo '<p><strong>'.esc_html__('Point:', 'madeit-inpost').'</strong> '.esc_html($point !== '' ? $point.($description !== '' ? ', '.$description : '') : '—').'</p>';
        /**
         * Lets the Pro add-on add shipments and labels to the box.
         *
         * @param  \WC_Order  $order
         * @param  string  $point  The point code, empty if the customer did not choose one.
         */
        do_action('madeit_inpost_order_box', $order, $point);
        if (! defined('MADEIT_INPOST_PRO_VERSION')) {
            echo '<p class="description">'.wp_kses(sprintf(/* translators: %s: link to the Pro add-on page */ __('Create shipments and print labels from the order with %s.', 'madeit-inpost'), '<a href="'.esc_url(Admin::UPGRADE_URL).'" target="_blank" rel="noopener">'.esc_html__('MadeIT InPost Pro', 'madeit-inpost').'</a>'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]).'</p>';
        }
    }
}
