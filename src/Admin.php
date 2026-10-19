<?php

namespace Madeit\Inpost;

defined('ABSPATH') || exit;

// The screens are built from strings in which every dynamic value is escaped at the point where it is added (esc_html, esc_attr, esc_url, wp_kses).
// The tab only selects what a read-only screen shows; changes go through admin-post.php with a nonce.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.NonceVerification.Recommended

final class Admin
{
    public const UPGRADE_URL = 'https://labs.made-it.pl/en/plugins/madeit-inpost-pro';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void
    {
        add_menu_page(__('MadeIT InPost', 'madeit-inpost'), __('MadeIT InPost', 'madeit-inpost'), 'manage_woocommerce', Plugin::PAGE, [$this, 'renderSettings'], 'dashicons-location-alt', 58);
        add_submenu_page(Plugin::PAGE, __('Settings', 'madeit-inpost'), __('Settings', 'madeit-inpost'), 'manage_woocommerce', Plugin::PAGE, [$this, 'renderSettings']);
    }

    public function assets(): void
    {
        // Only used to decide whether to load the stylesheets on this plugin's own screens.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        /**
         * Screens of this plugin that load its stylesheets. The Pro add-on adds its licence screen.
         *
         * @param  array<int, string>  $pages
         */
        if (! in_array($page, (array) apply_filters('madeit_inpost_admin_pages', [Plugin::PAGE]), true)) {
            return;
        }
        wp_enqueue_style('madeit-inpost-admin', plugins_url('assets/admin.css', MADEIT_INPOST_FILE), [], MADEIT_INPOST_VERSION);
        wp_enqueue_style('madeit-inpost-preview', plugins_url('assets/frontend.css', MADEIT_INPOST_FILE), [], MADEIT_INPOST_VERSION);
    }

    public function renderSettings(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'madeit-inpost'));
        }
        /**
         * Settings tabs. The Pro add-on appends its own.
         *
         * @param  array<string, string>  $tabs
         */
        $tabs = (array) apply_filters('madeit_inpost_settings_tabs', ['scope' => __('Scope', 'madeit-inpost'), 'map' => __('Map', 'madeit-inpost')]);
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'scope';
        $tab = array_key_exists($tab, $tabs) ? $tab : 'scope';
        $settings = Plugin::settings();
        $methods = Plugin::methods();
        $enabled = count(array_filter($methods, static fn (array $row): bool => $row['enabled']));

        echo '<div class="wrap madeit-admin"><div class="madeit-hero"><div><span class="madeit-eyebrow">'.esc_html__('MADEIT INPOST / SETTINGS', 'madeit-inpost').'</span><h1>'.esc_html__('InPost Parcel Lockers', 'madeit-inpost').'</h1><p>'.esc_html__('Customers choose a parcel locker on a map at checkout, and the point is saved in the order.', 'madeit-inpost').'</p></div>';
        do_action('madeit_inpost_hero');
        echo '</div>';
        $notice = isset($_GET['madeit_notice']) ? sanitize_key(wp_unslash($_GET['madeit_notice'])) : '';
        $messages = (array) apply_filters('madeit_inpost_notice_messages', ['settings' => __('Settings saved.', 'madeit-inpost')]);
        if (isset($messages[$notice])) {
            echo '<div class="madeit-notice" role="status">'.esc_html($messages[$notice]).'</div>';
        }
        echo '<div class="madeit-stats"><div class="madeit-stat"><span>'.esc_html__('Active methods in zones', 'madeit-inpost').'</span><strong>'.esc_html((string) $enabled).'</strong></div>';
        echo '<div class="madeit-stat"><span>'.esc_html__('Parcel locker map', 'madeit-inpost').'</span><strong>'.esc_html($settings['geowidget_token'] !== '' ? __('On', 'madeit-inpost') : __('Point code field', 'madeit-inpost')).'</strong></div>';
        echo '<div class="madeit-stat madeit-stat-note"><span>'.esc_html__('Choosing a point', 'madeit-inpost').'</span><strong>'.esc_html($settings['require_point'] === 'yes' ? __('Required', 'madeit-inpost') : __('Optional', 'madeit-inpost')).'</strong></div></div>';

        echo '<nav class="madeit-tabs" aria-label="'.esc_attr__('InPost settings', 'madeit-inpost').'">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(['page' => Plugin::PAGE, 'tab' => $key], admin_url('admin.php'));
            echo '<a class="'.($tab === $key ? 'is-active' : '').'" href="'.esc_url($url).'"'.($tab === $key ? ' aria-current="page"' : '').'>'.esc_html($label).'</a>';
        }
        echo '</nav>';

        if ($tab === 'scope') {
            $this->scopeTab($settings, $methods);
        } elseif ($tab === 'map') {
            $this->mapTab($settings);
        } else {
            do_action('madeit_inpost_render_settings_tab', $tab);
        }
        echo '</div>';
    }

    private function formOpen(string $tab): void
    {
        echo '<form class="madeit-settings-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="madeit_inpost_save"><input type="hidden" name="tab" value="'.esc_attr($tab).'">';
        wp_nonce_field('madeit_inpost_save');
    }

    /**
     * @param  array<string, string>  $settings
     * @param  array<int, array{zone: string, title: string, cost: string, free_from: string, enabled: bool}>  $methods
     */
    private function scopeTab(array $settings, array $methods): void
    {
        echo '<section class="madeit-card madeit-settings-card"><div class="madeit-section-heading"><div><h2>'.esc_html__('Checkout behaviour', 'madeit-inpost').'</h2><p>'.esc_html__('You set the cost and the free shipping threshold on the "InPost Parcel Locker" method in the WooCommerce shipping zone.', 'madeit-inpost').'</p></div></div>';
        $this->formOpen('scope');
        echo '<label class="madeit-check"><input type="checkbox" name="require_point" value="1"'.checked($settings['require_point'], 'yes', false).'><span>'.esc_html__('Require choosing a parcel locker before the order is placed', 'madeit-inpost').'</span></label>';
        echo '<button class="madeit-button madeit-button-primary" type="submit">'.esc_html__('Save', 'madeit-inpost').'</button>';
        if (! defined('MADEIT_INPOST_PRO_VERSION')) {
            echo '<p class="madeit-upsell">'.wp_kses(sprintf(/* translators: %s: link to the Pro add-on page */ __('Need shipments, PDF labels and cash on delivery? %s adds them.', 'madeit-inpost'), '<a href="'.esc_url(self::UPGRADE_URL).'" target="_blank" rel="noopener">'.esc_html__('MadeIT InPost Pro', 'madeit-inpost').'</a>'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]).'</p>';
        }
        echo '</form></section>';

        echo '<section class="madeit-card madeit-settings-card"><div class="madeit-section-heading"><div><h2>'.esc_html__('Methods in shipping zones', 'madeit-inpost').'</h2><p>'.esc_html__('Add "InPost Parcel Locker (MadeIT)" in WooCommerce → Settings → Shipping.', 'madeit-inpost').'</p></div><a class="madeit-button madeit-button-outline" href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=shipping')).'">'.esc_html__('Shipping zones →', 'madeit-inpost').'</a></div>';
        if (! $methods) {
            echo '<div class="madeit-empty"><span class="dashicons dashicons-location-alt" aria-hidden="true"></span><h3>'.esc_html__('No "InPost Parcel Locker" method', 'madeit-inpost').'</h3><p>'.esc_html__('Add it to a shipping zone (for example Poland) and customers will see the parcel locker choice.', 'madeit-inpost').'</p></div>';
        } else {
            echo '<div class="madeit-list">';
            foreach ($methods as $row) {
                $free = (float) $row['free_from'] > 0 ? sprintf(/* translators: %s: amount */ __('Free from %s', 'madeit-inpost'), wp_strip_all_tags(wc_price((float) $row['free_from']))) : __('No free shipping threshold', 'madeit-inpost');
                echo '<article class="madeit-row"><div class="madeit-row-identity"><strong>'.esc_html($row['zone']).'</strong><small>'.esc_html($free).'</small></div>';
                echo '<div class="madeit-row-balance"><span class="madeit-cell-label">'.esc_html__('Cost', 'madeit-inpost').'</span><strong>'.wp_kses_post(wc_price((float) $row['cost'])).'</strong></div>';
                echo '<div class="madeit-row-status"><span class="madeit-cell-label">'.esc_html__('Method', 'madeit-inpost').'</span><span class="madeit-badge madeit-badge-'.($row['enabled'] ? 'success' : 'muted').'">'.esc_html($row['enabled'] ? __('Enabled', 'madeit-inpost') : __('Disabled', 'madeit-inpost')).'</span></div></article>';
            }
            echo '</div>';
        }
        echo '</section>';
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function mapTab(array $settings): void
    {
        echo '<section class="madeit-card madeit-settings-card"><div class="madeit-section-heading"><div><h2>'.esc_html__('Parcel locker map', 'madeit-inpost').'</h2><p>'.esc_html__('The map is the official InPost Geowidget. You get the token in the InPost Parcel Manager, on the API tab.', 'madeit-inpost').'</p></div></div>';
        $this->formOpen('map');
        echo '<label><span>'.esc_html__('Map token (Geowidget)', 'madeit-inpost').'</span><input type="text" name="geowidget_token" value="'.esc_attr($settings['geowidget_token']).'" autocomplete="off" spellcheck="false"><small>'.esc_html__('Without a token customers type the point code instead of choosing it on the map. When a token is set, the map script is loaded from InPost when a customer opens the map.', 'madeit-inpost').'</small></label>';
        echo '<label class="madeit-check"><input type="checkbox" name="sandbox" value="1"'.checked($settings['sandbox'], 'yes', false).'><span>'.esc_html__('Test mode (InPost sandbox): for tokens from a test account', 'madeit-inpost').'</span></label>';
        echo '<button class="madeit-button madeit-button-primary" type="submit">'.esc_html__('Save map', 'madeit-inpost').'</button></form></section>';
    }

    /**
     * A static preview of the picker (colour and texts). Also used by the Pro add-on.
     *
     * @param  array<string, string>  $settings
     * @param  array<string, string>  $content
     */
    public static function preview(array $settings, array $content): string
    {
        $selected = Calculator::fillTemplate(esc_html($content['selected']), ['point' => 'WAW01M', 'address' => __('10 Marszalkowska St, Warsaw', 'madeit-inpost')]);
        $style = '--madeit-inpost-accent:'.esc_attr($settings['accent']);
        $out = '<span>'.esc_html__('Preview at checkout', 'madeit-inpost').'</span>';
        $out .= '<div class="madeit-inpost madeit-inpost--preview" style="'.$style.'"><p class="madeit-inpost__hint">'.esc_html($content['hint']).'</p><button type="button" class="madeit-inpost__button" tabindex="-1">'.esc_html($content['button_choose']).'</button></div>';
        $out .= '<small class="madeit-preview-caption">'.esc_html__('Before a point is chosen', 'madeit-inpost').'</small>';
        $out .= '<div class="madeit-inpost madeit-inpost--preview" style="'.$style.'"><p class="madeit-inpost__hint">'.esc_html($content['hint']).'</p><button type="button" class="madeit-inpost__button" tabindex="-1">'.esc_html($content['button_change']).'</button><p class="madeit-inpost__selected">'.$selected.'</p></div>';
        $out .= '<small class="madeit-preview-caption">'.esc_html__('After a point is chosen', 'madeit-inpost').'</small>';

        return $out;
    }
}
