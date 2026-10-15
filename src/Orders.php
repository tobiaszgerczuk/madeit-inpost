<?php

namespace WcoInpost;

/**
 * Obsługa zamówień w panelu: wybrany punkt, tworzenie przesyłki ShipX i pobieranie etykiety.
 */
final class Orders
{
    public const META_POINT = '_wco_inpost_point';

    public const META_POINT_DESCRIPTION = '_wco_inpost_point_desc';

    public const META_SIZE = '_wco_inpost_size';

    public const META_SHIPMENT = '_wco_inpost_shipment_id';

    public const META_TRACKING = '_wco_inpost_tracking';

    public const META_STATUS = '_wco_inpost_status';

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'metaBox']);
        add_action('admin_post_wco_inpost_create', [$this, 'create']);
        add_action('admin_post_wco_inpost_refresh', [$this, 'refresh']);
        add_action('admin_post_wco_inpost_label', [$this, 'label']);
        add_action('admin_notices', [$this, 'notices']);
    }

    public function metaBox(): void
    {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
        add_meta_box('wco-inpost-order', 'InPost', [$this, 'renderMetaBox'], $screen, 'side', 'high');
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
        $point = (string) $order->get_meta(self::META_POINT);
        $shipment = (string) $order->get_meta(self::META_SHIPMENT);
        $size = Calculator::normalizeSize((string) ($order->get_meta(self::META_SIZE) ?: Plugin::settings()['default_size']));
        if (! Plugin::orderUsesInpost($order) && $point === '' && $shipment === '') {
            echo '<p>To zamówienie nie używa metody „Paczkomat InPost”.</p>';

            return;
        }
        $description = (string) $order->get_meta(self::META_POINT_DESCRIPTION);
        if ($shipment !== '') {
            $tracking = (string) $order->get_meta(self::META_TRACKING);
            $status = (string) $order->get_meta(self::META_STATUS);
            echo '<p><strong>Punkt:</strong> '.esc_html($point.($description !== '' ? ', '.$description : '')).'</p>';
            echo '<p><strong>Status:</strong> '.esc_html($status !== '' ? $status : 'utworzona').'</p>';
            echo '<p><strong>Numer przesyłki:</strong> '.esc_html($tracking !== '' ? $tracking : 'jeszcze nie nadano (odśwież za chwilę)').'</p>';
            echo '<p><a class="button button-primary" href="'.esc_url($this->actionUrl('wco_inpost_label', $order)).'">Pobierz etykietę (PDF)</a> ';
            echo '<a class="button" href="'.esc_url($this->actionUrl('wco_inpost_refresh', $order)).'">Odśwież status</a></p>';

            return;
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wco_inpost_create"><input type="hidden" name="order_id" value="'.esc_attr((string) $order->get_id()).'">';
        wp_nonce_field('wco_inpost_create_'.$order->get_id());
        echo '<p><label><strong>Kod punktu</strong><br><input type="text" name="point" class="widefat" value="'.esc_attr($point).'" placeholder="Np. WAW01M" maxlength="20"></label></p>';
        if ($description !== '') {
            echo '<p class="description">'.esc_html($description).'</p>';
        }
        echo '<p><label><strong>Gabaryt</strong><br><select name="size" class="widefat">';
        foreach (['A' => 'A: mała (8 × 38 × 64 cm)', 'B' => 'B: średnia (19 × 38 × 64 cm)', 'C' => 'C: duża (41 × 38 × 64 cm)'] as $value => $label) {
            echo '<option value="'.esc_attr($value).'"'.selected($size, $value, false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label></p>';
        echo '<p><button type="submit" class="button button-primary">Utwórz przesyłkę</button></p></form>';
        if (! Plugin::shipx()->isConfigured()) {
            echo '<p class="description">Najpierw uzupełnij połączenie z ShipX w ustawieniach WCO InPost.</p>';
        }
    }

    private function actionUrl(string $action, \WC_Order $order): string
    {
        return wp_nonce_url(add_query_arg(['action' => $action, 'order_id' => $order->get_id()], admin_url('admin-post.php')), $action.'_'.$order->get_id());
    }

    private function backUrl(\WC_Order $order, string $notice, string $detail = ''): string
    {
        if ($detail !== '') {
            set_transient('wco_inpost_error_'.get_current_user_id(), $detail, 60);
        }

        return add_query_arg('wco_inpost_notice', $notice, $order->get_edit_order_url());
    }

    private function loadOrder(string $action, string $nonceField = '_wpnonce'): \WC_Order
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die('Brak uprawnień.');
        }
        $orderId = isset($_REQUEST['order_id']) ? absint(wp_unslash($_REQUEST['order_id'])) : 0;
        check_admin_referer($action.'_'.$orderId, $nonceField);
        $order = wc_get_order($orderId);
        if (! $order instanceof \WC_Order) {
            wp_die('Nie znaleziono zamówienia.');
        }

        return $order;
    }

    public function create(): void
    {
        $order = $this->loadOrder('wco_inpost_create');
        $point = isset($_POST['point']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['point']))) : '';
        $size = Calculator::normalizeSize(isset($_POST['size']) ? sanitize_text_field(wp_unslash($_POST['size'])) : 'A');
        if (! Calculator::isValidPointName($point)) {
            wp_safe_redirect($this->backUrl($order, 'error', 'Podaj poprawny kod punktu InPost, np. WAW01M.'));
            exit;
        }
        $first = (string) ($order->get_shipping_first_name() ?: $order->get_billing_first_name());
        $last = (string) ($order->get_shipping_last_name() ?: $order->get_billing_last_name());
        $phone = (string) ($order->get_shipping_phone() ?: $order->get_billing_phone());
        if (! Calculator::isValidPhone($phone)) {
            wp_safe_redirect($this->backUrl($order, 'error', 'ShipX wymaga polskiego numeru komórkowego (9 cyfr). Popraw telefon w zamówieniu.'));
            exit;
        }
        $settings = Plugin::settings();
        $payload = Calculator::shipmentPayload([
            'first_name' => $first,
            'last_name' => $last,
            'email' => (string) $order->get_billing_email(),
            'phone' => $phone,
            'point' => $point,
            'size' => $size,
            'reference' => 'Zamówienie '.$order->get_order_number(),
            'cod' => $order->get_payment_method() === 'cod' ? (float) $order->get_total() : 0.0,
            'currency' => $order->get_currency(),
            'sending_method' => $settings['sending_method'],
        ]);
        $result = Plugin::shipx()->createShipment($payload);
        if (is_wp_error($result)) {
            wp_safe_redirect($this->backUrl($order, 'error', $result->get_error_message()));
            exit;
        }
        $order->update_meta_data(self::META_POINT, $point);
        $order->update_meta_data(self::META_SIZE, $size);
        $order->update_meta_data(self::META_SHIPMENT, (string) ($result['id'] ?? ''));
        $order->update_meta_data(self::META_STATUS, (string) ($result['status'] ?? 'created'));
        $order->update_meta_data(self::META_TRACKING, (string) ($result['tracking_number'] ?? ''));
        $order->add_order_note('InPost: utworzono przesyłkę do punktu '.$point.' (gabaryt '.$size.').');
        $order->save();
        wp_safe_redirect($this->backUrl($order, 'created'));
        exit;
    }

    public function refresh(): void
    {
        $order = $this->loadOrder('wco_inpost_refresh');
        $id = (string) $order->get_meta(self::META_SHIPMENT);
        $result = $id !== '' ? Plugin::shipx()->shipment($id) : new \WP_Error('wco_inpost_none', 'To zamówienie nie ma przesyłki InPost.');
        if (is_wp_error($result)) {
            wp_safe_redirect($this->backUrl($order, 'error', $result->get_error_message()));
            exit;
        }
        $tracking = (string) ($result['tracking_number'] ?? '');
        if ($tracking !== '' && $tracking !== (string) $order->get_meta(self::META_TRACKING)) {
            $order->add_order_note('InPost: numer przesyłki '.$tracking.'.');
        }
        $order->update_meta_data(self::META_TRACKING, $tracking);
        $order->update_meta_data(self::META_STATUS, (string) ($result['status'] ?? ''));
        $order->save();
        wp_safe_redirect($this->backUrl($order, 'refreshed'));
        exit;
    }

    public function label(): void
    {
        $order = $this->loadOrder('wco_inpost_label');
        $pdf = Plugin::shipx()->label((string) $order->get_meta(self::META_SHIPMENT));
        if (is_wp_error($pdf)) {
            wp_safe_redirect($this->backUrl($order, 'error', $pdf->get_error_message()));
            exit;
        }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="inpost-'.absint($order->get_id()).'.pdf"');
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- binarny PDF
        exit;
    }

    public function notices(): void
    {
        $notice = isset($_GET['wco_inpost_notice']) ? sanitize_key(wp_unslash($_GET['wco_inpost_notice'])) : '';
        if ($notice === '') {
            return;
        }
        if ($notice === 'error') {
            $detail = (string) get_transient('wco_inpost_error_'.get_current_user_id());
            delete_transient('wco_inpost_error_'.get_current_user_id());
            echo '<div class="notice notice-error"><p>InPost: '.esc_html($detail !== '' ? $detail : 'operacja nie powiodła się.').'</p></div>';
        } elseif (in_array($notice, ['created', 'refreshed'], true)) {
            echo '<div class="notice notice-success is-dismissible"><p>'.($notice === 'created' ? 'Przesyłka InPost została utworzona.' : 'Status przesyłki odświeżony.').'</p></div>';
        }
    }
}
