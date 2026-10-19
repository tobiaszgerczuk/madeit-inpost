<?php

namespace Madeit\Inpost;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;

defined('ABSPATH') || exit;

// The checkout forms are processed by WooCommerce, which verifies its own checkout nonce before these hooks run.
// phpcs:disable WordPress.Security.NonceVerification.Missing

final class Plugin
{
    public const PAGE = 'madeit-inpost';

    public const EXTENSION = 'madeit-inpost';

    /** Id of the shipping method. The method class itself is only loaded when WooCommerce initialises shipping. */
    public const METHOD_ID = 'madeit_inpost';

    public const META_POINT = '_madeit_inpost_point';

    public const META_POINT_DESCRIPTION = '_madeit_inpost_point_desc';

    private const GEOWIDGET = 'https://geowidget.inpost.pl';

    private const GEOWIDGET_SANDBOX = 'https://sandbox-geowidget.inpost.pl';

    /**
     * @return array{geowidget_token: string, sandbox: string, require_point: string, accent: string}
     */
    public static function settingDefaults(): array
    {
        return [
            'geowidget_token' => '',
            'sandbox' => 'no',
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
            'button_choose' => __('Choose a parcel locker on the map', 'madeit-inpost'),
            'button_change' => __('Change parcel locker', 'madeit-inpost'),
            'selected' => __('Selected point: {point}, {address}', 'madeit-inpost'),
            'missing' => __('Choose the InPost parcel locker we should send your order to.', 'madeit-inpost'),
            'hint' => __('Click the button and pick a pickup point on the map.', 'madeit-inpost'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function settings(): array
    {
        $defaults = self::settingDefaults();
        $saved = get_option('madeit_inpost_settings', []);
        $settings = array_merge($defaults, is_array($saved) ? array_intersect_key($saved, $defaults) : []);
        $settings = array_map(static fn ($value): string => trim((string) $value), $settings);
        /**
         * Lets the Pro add-on supply the accent colour.
         *
         * @param  array<string, string>  $settings
         */
        $settings = array_merge($settings, array_intersect_key((array) apply_filters('madeit_inpost_settings', $settings), $defaults));
        $settings['accent'] = sanitize_hex_color((string) $settings['accent']) ?: $defaults['accent'];
        $settings['sandbox'] = $settings['sandbox'] === 'yes' ? 'yes' : 'no';
        $settings['require_point'] = $settings['require_point'] === 'no' ? 'no' : 'yes';

        return array_map('strval', $settings);
    }

    /**
     * @return array<string, string>
     */
    public static function content(): array
    {
        $defaults = self::contentDefaults();
        /**
         * Lets the Pro add-on replace the customer-facing texts.
         *
         * @param  array<string, string>  $content
         */
        $content = (array) apply_filters('madeit_inpost_content', $defaults);
        $content = array_merge($defaults, array_intersect_key($content, $defaults));

        return array_map(static fn ($value): string => (string) $value, $content);
    }

    /**
     * "InPost Parcel Locker" methods in the shipping zones.
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
                if ($method->id !== self::METHOD_ID) {
                    continue;
                }
                $rows[] = [
                    'zone' => $zone->get_zone_name(),
                    'title' => (string) $method->get_option('title', __('InPost Parcel Locker', 'madeit-inpost')),
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
            require_once __DIR__.'/ShippingMethod.php';
            $methods[self::METHOD_ID] = ShippingMethod::class;

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
        add_action('admin_post_madeit_inpost_save', [$this, 'saveSettings']);
        add_filter('plugin_action_links_'.plugin_basename(MADEIT_INPOST_FILE), [$this, 'actionLinks']);
        (new Orders)->register();
        (new Admin)->register();
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
     * Whether the customer chose the InPost method for this order.
     */
    public static function orderUsesInpost(\WC_Order $order): bool
    {
        foreach ($order->get_shipping_methods() as $item) {
            if ($item->get_method_id() === self::METHOD_ID) {
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
            if (str_starts_with((string) $rate, self::METHOD_ID.':')) {
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
            ? '<input type="hidden" name="madeit_inpost_point" value=""><input type="hidden" name="madeit_inpost_point_desc" value="">'
            : '';
        $hint = $settings['geowidget_token'] === '' ? __('Enter the code of the InPost parcel locker we should send your order to.', 'madeit-inpost') : $content['hint'];
        $manual = $settings['geowidget_token'] === ''
            ? '<label class="madeit-inpost__manual"><span>'.esc_html__('InPost point code', 'madeit-inpost').'</span><input type="text" data-madeit-inpost-manual maxlength="20" placeholder="'.esc_attr__('E.g. WAW01M', 'madeit-inpost').'" autocomplete="off"></label>'
            : '';

        return '<div class="madeit-inpost madeit-inpost--'.esc_attr($placement).'" data-madeit-inpost hidden style="--madeit-inpost-accent:'.esc_attr($settings['accent']).'">'
            .'<p class="madeit-inpost__hint">'.esc_html($hint).'</p>'
            .($settings['geowidget_token'] !== '' ? '<button type="button" class="madeit-inpost__button" data-madeit-inpost-open>'.esc_html($content['button_choose']).'</button>' : '')
            .$manual
            .'<p class="madeit-inpost__selected" data-madeit-inpost-selected role="status" aria-live="polite" hidden></p>'
            .$inputs
            .'</div>';
    }

    public function classicPicker(): void
    {
        $this->enqueueCheckout();
        echo '<tr class="madeit-inpost-row"><td colspan="2">'.$this->pickerMarkup('classic').'</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while the markup is built.
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
        wp_enqueue_script('madeit-inpost-frontend');
        wp_enqueue_style('madeit-inpost-frontend');
        if (self::settings()['geowidget_token'] !== '') {
            wp_enqueue_style('madeit-inpost-geowidget');
        }
    }

    public function frontendAssets(): void
    {
        $settings = self::settings();
        $content = self::content();
        $base = $settings['sandbox'] === 'yes' ? self::GEOWIDGET_SANDBOX : self::GEOWIDGET;
        wp_register_style('madeit-inpost-frontend', plugins_url('assets/frontend.css', MADEIT_INPOST_FILE), [], MADEIT_INPOST_VERSION);
        // The map is InPost's own Geowidget, loaded from InPost only when a token is set and a customer opens the map.
        wp_register_style('madeit-inpost-geowidget', $base.'/inpost-geowidget.css', [], MADEIT_INPOST_VERSION);
        wp_register_script('madeit-inpost-frontend', plugins_url('assets/frontend.js', MADEIT_INPOST_FILE), ['wp-data'], MADEIT_INPOST_VERSION, true);
        wp_localize_script('madeit-inpost-frontend', 'madeitInpost', [
            'methodId' => self::METHOD_ID,
            'extension' => self::EXTENSION,
            'token' => $settings['geowidget_token'],
            'widgetScript' => $base.'/inpost-geowidget.js',
            'language' => str_starts_with(determine_locale(), 'pl') ? 'pl' : 'en',
            'required' => $settings['require_point'] === 'yes',
            'validName' => '^[A-Z0-9][A-Z0-9-]{2,19}$',
            'content' => $content,
            'close' => __('Close', 'madeit-inpost'),
        ]);
    }

    public function validateClassic(): void
    {
        $chosen = (array) (WC()->session ? WC()->session->get('chosen_shipping_methods', []) : []);
        if (! self::chosenIsInpost($chosen) || self::settings()['require_point'] !== 'yes') {
            return;
        }
        $point = isset($_POST['madeit_inpost_point']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['madeit_inpost_point']))) : '';
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
        $point = isset($_POST['madeit_inpost_point']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['madeit_inpost_point']))) : '';
        $description = isset($_POST['madeit_inpost_point_desc']) ? sanitize_text_field(wp_unslash($_POST['madeit_inpost_point_desc'])) : '';
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
            throw new RouteException('madeit_inpost_missing_point', esc_html(self::content()['missing']), 400);
        }
        $this->storePoint($order, $point, $description);
    }

    private function storePoint(\WC_Order $order, string $point, string $description): void
    {
        if (! Calculator::isValidPointName($point)) {
            return;
        }
        $order->update_meta_data(self::META_POINT, $point);
        $order->update_meta_data(self::META_POINT_DESCRIPTION, mb_substr($description, 0, 200));
        /**
         * Runs when a pickup point was saved in a new order. The Pro add-on stores the default parcel size here.
         */
        do_action('madeit_inpost_point_saved', $order, $point);
    }

    private function pointLine(\WC_Order $order): string
    {
        $point = (string) $order->get_meta(self::META_POINT);
        if ($point === '') {
            return '';
        }
        $description = (string) $order->get_meta(self::META_POINT_DESCRIPTION);

        return Calculator::fillTemplate(self::content()['selected'], ['point' => $point, 'address' => $description]);
    }

    public function showPointInOrder(\WC_Order $order): void
    {
        $line = $this->pointLine($order);
        if ($line !== '') {
            echo '<section class="madeit-inpost-order"><h2 class="woocommerce-column__title">'.esc_html__('InPost Parcel Locker', 'madeit-inpost').'</h2><p>'.esc_html($line).'</p></section>';
        }
    }

    public function showPointInEmail(\WC_Order $order, bool $sentToAdmin, bool $plainText, $email): void
    {
        $line = $this->pointLine($order);
        if ($line === '') {
            return;
        }
        $label = __('InPost Parcel Locker', 'madeit-inpost');
        echo $plainText ? "\n".esc_html($label).': '.esc_html($line)."\n" : '<p><strong>'.esc_html($label).':</strong> '.esc_html($line).'</p>';
    }

    /**
     * @param  array<int, string>  $links
     * @return array<int, string>
     */
    public function actionLinks(array $links): array
    {
        array_unshift($links, '<a href="'.esc_url(admin_url('admin.php?page='.self::PAGE)).'">'.esc_html__('Settings', 'madeit-inpost').'</a>');

        return $links;
    }

    /**
     * Saves one settings tab. The free plugin has the scope and map tabs.
     */
    public function saveSettings(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'madeit-inpost'));
        }
        check_admin_referer('madeit_inpost_save');
        $tab = isset($_POST['tab']) ? sanitize_key(wp_unslash($_POST['tab'])) : 'scope';
        $stored = get_option('madeit_inpost_settings', []);
        $stored = is_array($stored) ? $stored : [];
        if ($tab === 'map') {
            $stored['geowidget_token'] = isset($_POST['geowidget_token']) ? trim(sanitize_text_field(wp_unslash($_POST['geowidget_token']))) : '';
            $stored['sandbox'] = ! empty($_POST['sandbox']) ? 'yes' : 'no';
        } else {
            $tab = 'scope';
            $stored['require_point'] = ! empty($_POST['require_point']) ? 'yes' : 'no';
        }
        update_option('madeit_inpost_settings', $stored, false);
        wp_safe_redirect(admin_url('admin.php?page='.self::PAGE.'&tab='.$tab.'&madeit_notice=settings'));
        exit;
    }
}
