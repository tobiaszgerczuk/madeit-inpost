<?php

namespace WcoInpost;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;

final class Plugin
{
    public const PAGE = 'wco-inpost';

    public const LICENSE_PAGE = 'wco-inpost-license';

    public const EXTENSION = 'wco-inpost';

    private const GEOWIDGET = 'https://geowidget.inpost.pl';

    private const GEOWIDGET_SANDBOX = 'https://sandbox-geowidget.inpost.pl';

    /**
     * @return array{geowidget_token: string, sandbox: string, organization_id: string, api_token: string, default_size: string, sending_method: string, require_point: string, accent: string}
     */
    public static function settingDefaults(): array
    {
        return [
            'geowidget_token' => '',
            'sandbox' => 'no',
            'organization_id' => '',
            'api_token' => '',
            'default_size' => 'A',
            'sending_method' => 'dispatch_order',
            'require_point' => 'yes',
            'accent' => '#294a74',
        ];
    }

    /**
     * @return array{button_choose: string, button_change: string, selected: string, missing: string, hint: string}
     */
    public static function contentDefaults(): array
    {
        return [
            'button_choose' => 'Wybierz paczkomat na mapie',
            'button_change' => 'Zmień paczkomat',
            'selected' => 'Wybrany punkt: {point}, {address}',
            'missing' => 'Wybierz paczkomat InPost, do którego mamy wysłać zamówienie.',
            'hint' => 'Kliknij przycisk i wskaż punkt odbioru na mapie.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function settings(): array
    {
        $saved = get_option('wco_inpost_settings', []);
        $settings = array_merge(self::settingDefaults(), is_array($saved) ? array_intersect_key($saved, self::settingDefaults()) : []);
        $settings = array_map(static fn ($value): string => trim((string) $value), $settings);
        $settings['accent'] = sanitize_hex_color($settings['accent']) ?: self::settingDefaults()['accent'];
        $settings['default_size'] = Calculator::normalizeSize($settings['default_size']);
        $settings['sending_method'] = $settings['sending_method'] === 'parcel_locker' ? 'parcel_locker' : 'dispatch_order';
        $settings['sandbox'] = $settings['sandbox'] === 'yes' ? 'yes' : 'no';
        $settings['require_point'] = $settings['require_point'] === 'no' ? 'no' : 'yes';

        return $settings;
    }

    /**
     * @return array<string, string>
     */
    public static function content(): array
    {
        $saved = get_option('wco_inpost_content', []);
        $content = array_merge(self::contentDefaults(), is_array($saved) ? array_intersect_key($saved, self::contentDefaults()) : []);

        return array_map(static fn ($value): string => (string) $value, $content);
    }

    public static function shipx(): ShipX
    {
        $settings = self::settings();

        return new ShipX($settings['organization_id'], $settings['api_token'], $settings['sandbox'] === 'yes');
    }

    /**
     * Metody „Paczkomat InPost” w strefach wysyłki.
     *
     * @return array<int, array{zone: string, title: string, cost: string, free_from: string, enabled: bool}>
     */
    public static function methods(): array
    {
        $zones = [];
        foreach (\WC_Shipping_Zones::get_zones() as $zoneData) {
            $zones[] = new \WC_Shipping_Zone($zoneData['id']);
        }
        $zones[] = new \WC_Shipping_Zone(0);
        $rows = [];
        foreach ($zones as $zone) {
            foreach ($zone->get_shipping_methods(false) as $method) {
                if ($method->id !== ShippingMethod::ID) {
                    continue;
                }
                $rows[] = [
                    'zone' => $zone->get_zone_name(),
                    'title' => (string) $method->get_option('title', 'Paczkomat InPost'),
                    'cost' => (string) $method->get_option('cost', '0'),
                    'free_from' => (string) $method->get_option('free_from', ''),
                    'enabled' => $method->is_enabled(),
                ];
            }
        }

        return $rows;
    }

    public function register(): void
    {
        add_action('woocommerce_shipping_init', static function (): void {
            require_once __DIR__.'/ShippingMethod.php';
        });
        add_filter('woocommerce_shipping_methods', static function (array $methods): array {
            $methods[ShippingMethod::ID] = ShippingMethod::class;

            return $methods;
        });
        add_action('woocommerce_review_order_after_shipping', [$this, 'classicPicker']);
        add_filter('render_block', [$this, 'blockPicker'], 10, 2);
        add_action('woocommerce_checkout_process', [$this, 'validateClassic']);
        add_action('woocommerce_checkout_create_order', [$this, 'saveClassic'], 10, 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'saveBlocks'], 10, 2);
        add_action('woocommerce_init', [$this, 'registerStoreApi']);
        add_action('woocommerce_order_details_after_customer_details', [$this, 'showPointInOrder']);
        add_action('woocommerce_email_after_order_table', [$this, 'showPointInEmail'], 10, 4);
        add_action('wp_enqueue_scripts', [$this, 'frontendAssets']);
        add_action('admin_menu', [$this, 'adminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'adminAssets']);
        add_action('admin_post_wco_inpost_save', [$this, 'saveSettings']);
        add_filter('plugin_action_links_'.plugin_basename(WCO_INPOST_FILE), [$this, 'actionLinks']);
        (new Orders)->register();
    }

    public function registerStoreApi(): void
    {
        if (! function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => CheckoutSchema::IDENTIFIER,
            'namespace' => self::EXTENSION,
            'schema_callback' => static fn (): array => [
                'point' => ['type' => ['string', 'null'], 'readonly' => false],
                'description' => ['type' => ['string', 'null'], 'readonly' => false],
            ],
            'data_callback' => static fn (): array => ['point' => null, 'description' => null],
            'schema_type' => ARRAY_A,
        ]);
    }

    /**
     * Czy w tym zamówieniu klient wybrał metodę InPost.
     */
    public static function orderUsesInpost(\WC_Order $order): bool
    {
        foreach ($order->get_shipping_methods() as $item) {
            if ($item->get_method_id() === ShippingMethod::ID) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $chosen
     */
    private static function chosenIsInpost(array $chosen): bool
    {
        foreach ($chosen as $rate) {
            if (str_starts_with((string) $rate, ShippingMethod::ID.':')) {
                return true;
            }
        }

        return false;
    }

    private function pickerMarkup(string $placement): string
    {
        $settings = self::settings();
        $content = self::content();
        $inputs = $placement === 'classic'
            ? '<input type="hidden" name="wco_inpost_point" value=""><input type="hidden" name="wco_inpost_point_desc" value="">'
            : '';
        $hint = $settings['geowidget_token'] === '' ? 'Wpisz kod paczkomatu InPost, do którego mamy wysłać zamówienie.' : $content['hint'];
        $manual = $settings['geowidget_token'] === ''
            ? '<label class="wco-inpost__manual"><span>Kod punktu InPost</span><input type="text" data-wco-inpost-manual maxlength="20" placeholder="Np. WAW01M" autocomplete="off"></label>'
            : '';

        return '<div class="wco-inpost wco-inpost--'.esc_attr($placement).'" data-wco-inpost hidden style="--wco-inpost-accent:'.esc_attr($settings['accent']).'">'
            .'<p class="wco-inpost__hint">'.esc_html($hint).'</p>'
            .($settings['geowidget_token'] !== '' ? '<button type="button" class="wco-inpost__button" data-wco-inpost-open>'.esc_html($content['button_choose']).'</button>' : '')
            .$manual
            .'<p class="wco-inpost__selected" data-wco-inpost-selected role="status" aria-live="polite" hidden></p>'
            .$inputs
            .'</div>';
    }

    public function classicPicker(): void
    {
        $this->enqueueCheckout();
        echo '<tr class="wco-inpost-row"><td colspan="2">'.$this->pickerMarkup('classic').'</td></tr>'; // Znaczniki są escapowane przy budowaniu.
    }

    public function blockPicker(string $content, array $block): string
    {
        if (($block['blockName'] ?? '') !== 'woocommerce/checkout') {
            return $content;
        }
        $this->enqueueCheckout();

        return $content.$this->pickerMarkup('blocks');
    }

    private function enqueueCheckout(): void
    {
        wp_enqueue_script('wco-inpost-frontend');
        wp_enqueue_style('wco-inpost-frontend');
        wp_enqueue_style('wco-inpost-geowidget');
    }

    public function frontendAssets(): void
    {
        $directory = plugin_dir_path(WCO_INPOST_FILE).'assets/';
        $settings = self::settings();
        $content = self::content();
        $base = $settings['sandbox'] === 'yes' ? self::GEOWIDGET_SANDBOX : self::GEOWIDGET;
        wp_register_style('wco-inpost-frontend', plugins_url('assets/frontend.css', WCO_INPOST_FILE), [], (string) filemtime($directory.'frontend.css'));
        wp_register_style('wco-inpost-geowidget', $settings['geowidget_token'] !== '' ? $base.'/inpost-geowidget.css' : false, [], null);
        wp_register_script('wco-inpost-frontend', plugins_url('assets/frontend.js', WCO_INPOST_FILE), ['wp-data'], (string) filemtime($directory.'frontend.js'), true);
        wp_localize_script('wco-inpost-frontend', 'wcoInpost', [
            'methodId' => ShippingMethod::ID,
            'extension' => self::EXTENSION,
            'token' => $settings['geowidget_token'],
            'widgetScript' => $base.'/inpost-geowidget.js',
            'language' => 'pl',
            'required' => $settings['require_point'] === 'yes',
            'validName' => '^[A-Z0-9][A-Z0-9-]{2,19}$',
            'content' => $content,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function validateClassic(): void
    {
        $chosen = (array) (WC()->session ? WC()->session->get('chosen_shipping_methods', []) : []);
        if (! self::chosenIsInpost($chosen) || self::settings()['require_point'] !== 'yes') {
            return;
        }
        $point = isset($_POST['wco_inpost_point']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['wco_inpost_point']))) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if (! Calculator::isValidPointName($point)) {
            wc_add_notice(esc_html(self::content()['missing']), 'error');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveClassic(\WC_Order $order, array $data): void
    {
        if (! self::orderUsesInpost($order)) {
            return;
        }
        $point = isset($_POST['wco_inpost_point']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['wco_inpost_point']))) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $description = isset($_POST['wco_inpost_point_desc']) ? sanitize_text_field(wp_unslash($_POST['wco_inpost_point_desc'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $this->storePoint($order, $point, $description);
    }

    public function saveBlocks(\WC_Order $order, \WP_REST_Request $request): void
    {
        if (! self::orderUsesInpost($order)) {
            return;
        }
        $extensions = (array) $request->get_param('extensions');
        $data = (array) ($extensions[self::EXTENSION] ?? []);
        $point = strtoupper(sanitize_text_field((string) ($data['point'] ?? '')));
        $description = sanitize_text_field((string) ($data['description'] ?? ''));
        if (self::settings()['require_point'] === 'yes' && ! Calculator::isValidPointName($point)) {
            throw new RouteException('wco_inpost_missing_point', esc_html(self::content()['missing']), 400);
        }
        $this->storePoint($order, $point, $description);
    }

    private function storePoint(\WC_Order $order, string $point, string $description): void
    {
        if (! Calculator::isValidPointName($point)) {
            return;
        }
        $order->update_meta_data(Orders::META_POINT, $point);
        $order->update_meta_data(Orders::META_POINT_DESCRIPTION, mb_substr($description, 0, 200));
        $order->update_meta_data(Orders::META_SIZE, self::settings()['default_size']);
    }

    private function pointLine(\WC_Order $order): string
    {
        $point = (string) $order->get_meta(Orders::META_POINT);
        if ($point === '') {
            return '';
        }
        $description = (string) $order->get_meta(Orders::META_POINT_DESCRIPTION);

        return Calculator::fillTemplate(self::content()['selected'], ['point' => $point, 'address' => $description]);
    }

    public function showPointInOrder(\WC_Order $order): void
    {
        $line = $this->pointLine($order);
        if ($line !== '') {
            echo '<section class="wco-inpost-order"><h2 class="woocommerce-column__title">Paczkomat InPost</h2><p>'.esc_html($line).'</p></section>';
        }
    }

    public function showPointInEmail(\WC_Order $order, bool $sentToAdmin, bool $plainText, $email): void
    {
        $line = $this->pointLine($order);
        if ($line === '') {
            return;
        }
        echo $plainText ? "\nPaczkomat InPost: ".$line."\n" : '<p><strong>Paczkomat InPost:</strong> '.esc_html($line).'</p>';
    }

    public function adminMenu(): void
    {
        add_menu_page('WCO InPost', 'WCO InPost', 'manage_woocommerce', self::PAGE, [$this, 'settingsPage'], 'dashicons-location-alt', 58);
        add_submenu_page(self::PAGE, 'Ustawienia', 'Ustawienia', 'manage_woocommerce', self::PAGE, [$this, 'settingsPage']);
    }

    /**
     * @param  array<int, string>  $links
     * @return array<int, string>
     */
    public function actionLinks(array $links): array
    {
        array_unshift($links, '<a href="'.esc_url(admin_url('admin.php?page='.self::PAGE)).'">Ustawienia</a>');

        return $links;
    }

    public function adminAssets(): void
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (! in_array($page, [self::PAGE, self::LICENSE_PAGE], true)) {
            return;
        }
        $directory = plugin_dir_path(WCO_INPOST_FILE).'assets/';
        wp_enqueue_style('wco-inpost-admin', plugins_url('assets/admin.css', WCO_INPOST_FILE), [], (string) filemtime($directory.'admin.css'));
        wp_enqueue_style('wco-inpost-preview', plugins_url('assets/frontend.css', WCO_INPOST_FILE), [], (string) filemtime($directory.'frontend.css'));
    }

    public function settingsPage(): void
    {
        (new Admin)->renderSettings();
    }

    public function saveSettings(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die('Brak uprawnień.');
        }
        check_admin_referer('wco_inpost_save');
        $tab = isset($_POST['tab']) ? sanitize_key(wp_unslash($_POST['tab'])) : 'scope';
        $settings = self::settings();
        if ($tab === 'scope') {
            $settings['require_point'] = ! empty($_POST['require_point']) ? 'yes' : 'no';
            $size = isset($_POST['default_size']) ? sanitize_text_field(wp_unslash($_POST['default_size'])) : 'A';
            $settings['default_size'] = Calculator::normalizeSize($size);
            $method = isset($_POST['sending_method']) ? sanitize_key(wp_unslash($_POST['sending_method'])) : '';
            $settings['sending_method'] = $method === 'parcel_locker' ? 'parcel_locker' : 'dispatch_order';
            update_option('wco_inpost_settings', $settings, false);
        } elseif ($tab === 'connection') {
            $settings['geowidget_token'] = isset($_POST['geowidget_token']) ? trim(sanitize_text_field(wp_unslash($_POST['geowidget_token']))) : '';
            $settings['organization_id'] = isset($_POST['organization_id']) ? preg_replace('/\D+/', '', wp_unslash($_POST['organization_id'])) : '';
            $settings['api_token'] = isset($_POST['api_token']) ? trim(sanitize_text_field(wp_unslash($_POST['api_token']))) : '';
            $settings['sandbox'] = ! empty($_POST['sandbox']) ? 'yes' : 'no';
            update_option('wco_inpost_settings', $settings, false);
        } elseif ($tab === 'appearance') {
            $accent = isset($_POST['accent']) ? sanitize_hex_color(wp_unslash($_POST['accent'])) : null;
            $settings['accent'] = $accent ?: self::settingDefaults()['accent'];
            update_option('wco_inpost_settings', $settings, false);
        } else {
            $tab = 'content';
            $content = [];
            foreach (self::contentDefaults() as $key => $default) {
                $value = isset($_POST[$key]) ? sanitize_textarea_field(wp_unslash($_POST[$key])) : $default;
                $content[$key] = trim($value) === '' ? $default : $value;
            }
            update_option('wco_inpost_content', $content, false);
        }
        wp_safe_redirect(admin_url('admin.php?page='.self::PAGE.'&tab='.$tab.'&wco_notice=settings'));
        exit;
    }
}
