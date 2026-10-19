<?php

namespace MadeitInpost;

final class Admin
{
    private const TABS = ['scope' => 'Zakres', 'connection' => 'Połączenie', 'appearance' => 'Wygląd', 'content' => 'Treści'];

    public function renderSettings(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die('Brak uprawnień.');
        }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'scope';
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'scope';
        $settings = Plugin::settings();
        $content = Plugin::content();
        $methods = Plugin::methods();
        $enabled = count(array_filter($methods, static fn (array $row): bool => $row['enabled']));
        $shipx = $settings['organization_id'] !== '' && $settings['api_token'] !== '';

        echo '<div class="wrap madeit-admin"><div class="madeit-hero"><div><span class="madeit-eyebrow">MADEIT INPOST / KONFIGURACJA</span><h1>Paczkomaty InPost</h1><p>Klient wybiera paczkomat na mapie w checkoucie, a Ty tworzysz przesyłki i drukujesz etykiety bez wychodzenia z WooCommerce.</p></div>';
        echo '<a class="madeit-button madeit-button-light" href="'.esc_url(admin_url('admin.php?page='.Plugin::LICENSE_PAGE)).'">Licencja <span aria-hidden="true">↗</span></a></div>';
        $this->notice();
        echo '<div class="madeit-stats"><div class="madeit-stat"><span>Aktywne metody w strefach</span><strong>'.esc_html((string) $enabled).'</strong></div>';
        echo '<div class="madeit-stat"><span>Mapa paczkomatów</span><strong>'.esc_html($settings['geowidget_token'] !== '' ? 'Włączona' : 'Pole z kodem punktu').'</strong></div>';
        echo '<div class="madeit-stat madeit-stat-note"><span>Etykiety ShipX</span><strong>'.esc_html($shipx ? ($settings['sandbox'] === 'yes' ? 'Sandbox' : 'Produkcja') : 'Nie skonfigurowano').'</strong></div></div>';

        echo '<nav class="madeit-tabs" aria-label="Ustawienia InPost">';
        foreach (self::TABS as $key => $label) {
            $url = add_query_arg(['page' => Plugin::PAGE, 'tab' => $key], admin_url('admin.php'));
            echo '<a class="'.($tab === $key ? 'is-active' : '').'" href="'.esc_url($url).'"'.($tab === $key ? ' aria-current="page"' : '').'>'.esc_html($label).'</a>';
        }
        echo '</nav>';

        match ($tab) {
            'connection' => $this->connectionTab($settings),
            'appearance' => $this->appearanceTab($settings, $content),
            'content' => $this->contentTab($settings, $content),
            default => $this->scopeTab($settings, $methods),
        };
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
        echo '<section class="madeit-card madeit-settings-card"><div class="madeit-section-heading"><div><h2>Zachowanie w checkoucie i przesyłkach</h2><p>Koszt i próg darmowej dostawy ustawiasz przy metodzie „Paczkomat InPost” w strefie wysyłki WooCommerce.</p></div></div>';
        $this->formOpen('scope');
        echo '<label class="madeit-check"><input type="checkbox" name="require_point" value="1"'.checked($settings['require_point'], 'yes', false).'><span>Wymagaj wyboru paczkomatu przed złożeniem zamówienia</span></label>';
        echo '<label><span>Domyślny gabaryt</span><select name="default_size">';
        foreach (['A' => 'A: mała (do 8 cm)', 'B' => 'B: średnia (do 19 cm)', 'C' => 'C: duża (do 41 cm)'] as $value => $label) {
            echo '<option value="'.esc_attr($value).'"'.selected($settings['default_size'], $value, false).'>'.esc_html($label).'</option>';
        }
        echo '</select><small>Możesz go zmienić dla każdego zamówienia przed utworzeniem przesyłki.</small></label>';
        echo '<label><span>Sposób nadania</span><select name="sending_method">';
        foreach (['dispatch_order' => 'Odbiór przez kuriera', 'parcel_locker' => 'Nadanie w paczkomacie'] as $value => $label) {
            echo '<option value="'.esc_attr($value).'"'.selected($settings['sending_method'], $value, false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label>';
        echo '<button class="madeit-button madeit-button-primary" type="submit">Zapisz zakres</button></form></section>';

        echo '<section class="madeit-card madeit-settings-card"><div class="madeit-section-heading"><div><h2>Metody w strefach wysyłki</h2><p>Dodaj „Paczkomat InPost (MadeIT)” w WooCommerce → Ustawienia → Wysyłka.</p></div><a class="madeit-button madeit-button-outline" href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=shipping')).'">Strefy wysyłki →</a></div>';
        if (! $methods) {
            echo '<div class="madeit-empty"><span class="dashicons dashicons-location-alt" aria-hidden="true"></span><h3>Brak metody „Paczkomat InPost”</h3><p>Dodaj ją do strefy wysyłki (np. Polska), a klienci zobaczą wybór paczkomatu.</p></div>';
        } else {
            echo '<div class="madeit-list">';
            foreach ($methods as $row) {
                $free = (float) $row['free_from'] > 0 ? 'Gratis od '.wp_strip_all_tags(wc_price((float) $row['free_from'])) : 'Bez progu darmowej dostawy';
                echo '<article class="madeit-row"><div class="madeit-row-identity"><strong>'.esc_html($row['zone']).'</strong><small>'.esc_html($free).'</small></div>';
                echo '<div class="madeit-row-balance"><span class="madeit-cell-label">Koszt</span><strong>'.wp_kses_post(wc_price((float) $row['cost'])).'</strong></div>';
                echo '<div class="madeit-row-status"><span class="madeit-cell-label">Metoda</span><span class="madeit-badge madeit-badge-'.($row['enabled'] ? 'success' : 'muted').'">'.($row['enabled'] ? 'Włączona' : 'Wyłączona').'</span></div></article>';
            }
            echo '</div>';
        }
        echo '</section>';
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function connectionTab(array $settings): void
    {
        echo '<section class="madeit-card madeit-settings-card"><div class="madeit-section-heading"><div><h2>Połączenie z InPost</h2><p>Dane znajdziesz w Managerze Paczek InPost, w zakładce API. Token mapy (Geowidget) i token ShipX to dwa różne klucze.</p></div></div>';
        $this->formOpen('connection');
        echo '<label><span>Token mapy (Geowidget)</span><input type="text" name="geowidget_token" value="'.esc_attr($settings['geowidget_token']).'" autocomplete="off" spellcheck="false"><small>Bez tokenu klient wpisuje kod punktu ręcznie zamiast wybierać go na mapie.</small></label>';
        echo '<label><span>Identyfikator organizacji ShipX</span><input type="text" name="organization_id" value="'.esc_attr($settings['organization_id']).'" inputmode="numeric" autocomplete="off"></label>';
        echo '<label><span>Token API ShipX</span><input type="password" name="api_token" value="'.esc_attr($settings['api_token']).'" autocomplete="off" spellcheck="false"><small>Potrzebny do tworzenia przesyłek i pobierania etykiet. Token daje dostęp do Twojego konta InPost, więc nie udostępniaj go nikomu.</small></label>';
        echo '<label class="madeit-check"><input type="checkbox" name="sandbox" value="1"'.checked($settings['sandbox'], 'yes', false).'><span>Tryb testowy (sandbox InPost): dla tokenów z konta testowego</span></label>';
        echo '<button class="madeit-button madeit-button-primary" type="submit">Zapisz połączenie</button></form></section>';
    }

    /**
     * @param  array<string, string>  $settings
     * @param  array<string, string>  $content
     */
    private function appearanceTab(array $settings, array $content): void
    {
        echo '<section class="madeit-card madeit-settings-card"><div class="madeit-section-heading"><div><h2>Wygląd wyboru paczkomatu</h2><p>Kolor przycisku i ramki. Tło dopasowuje się do motywu sklepu.</p></div></div>';
        echo '<div class="madeit-settings-layout">';
        $this->formOpen('appearance');
        echo '<label><span>Kolor akcentu</span><input type="color" name="accent" value="'.esc_attr($settings['accent']).'"></label>';
        echo '<button class="madeit-button madeit-button-primary" type="submit">Zapisz wygląd</button></form>';
        echo '<div class="madeit-settings-preview">'.$this->preview($settings, $content).'</div></div></section>';
    }

    /**
     * @param  array<string, string>  $settings
     * @param  array<string, string>  $content
     */
    private function contentTab(array $settings, array $content): void
    {
        $fields = [
            'hint' => ['Podpowiedź nad przyciskiem', 'Krótkie wyjaśnienie, co klient ma zrobić.'],
            'button_choose' => ['Przycisk przed wyborem', 'Tekst na przycisku otwierającym mapę.'],
            'button_change' => ['Przycisk po wyborze', 'Tekst na przycisku, gdy punkt jest już wybrany.'],
            'selected' => ['Komunikat o wybranym punkcie', 'Znaczniki: {point} (kod punktu), {address} (adres).'],
            'missing' => ['Błąd braku wyboru', 'Pokazywany, gdy klient próbuje złożyć zamówienie bez paczkomatu.'],
        ];
        echo '<section class="madeit-card madeit-settings-card"><div class="madeit-section-heading"><div><h2>Teksty widoczne dla klientów</h2><p>Dostępne znaczniki: {point}, {address}.</p></div></div>';
        echo '<div class="madeit-settings-layout">';
        $this->formOpen('content');
        foreach ($fields as $key => [$label, $hint]) {
            echo '<label><span>'.esc_html($label).'</span><textarea name="'.esc_attr($key).'" rows="2">'.esc_textarea($content[$key]).'</textarea><small>'.esc_html($hint).'</small></label>';
        }
        echo '<button class="madeit-button madeit-button-primary" type="submit">Zapisz treści</button></form>';
        echo '<div class="madeit-settings-preview">'.$this->preview($settings, $content).'</div></div></section>';
    }

    /**
     * @param  array<string, string>  $settings
     * @param  array<string, string>  $content
     */
    private function preview(array $settings, array $content): string
    {
        $selected = Calculator::fillTemplate(esc_html($content['selected']), ['point' => 'WAW01M', 'address' => 'ul. Marszałkowska 10, Warszawa']);
        $style = '--madeit-inpost-accent:'.esc_attr($settings['accent']);
        $out = '<span>Podgląd w checkoucie</span>';
        $out .= '<div class="madeit-inpost madeit-inpost--preview" style="'.$style.'"><p class="madeit-inpost__hint">'.esc_html($content['hint']).'</p><button type="button" class="madeit-inpost__button" tabindex="-1">'.esc_html($content['button_choose']).'</button></div>';
        $out .= '<small class="madeit-preview-caption">Przed wyborem punktu</small>';
        $out .= '<div class="madeit-inpost madeit-inpost--preview" style="'.$style.'"><p class="madeit-inpost__hint">'.esc_html($content['hint']).'</p><button type="button" class="madeit-inpost__button" tabindex="-1">'.esc_html($content['button_change']).'</button><p class="madeit-inpost__selected">'.$selected.'</p></div>';
        $out .= '<small class="madeit-preview-caption">Po wyborze punktu</small>';

        return $out;
    }

    private function notice(): void
    {
        $notice = isset($_GET['madeit_notice']) ? sanitize_key(wp_unslash($_GET['madeit_notice'])) : '';
        if ($notice === 'settings') {
            echo '<div class="madeit-notice" role="status">Ustawienia zapisane.</div>';
        }
    }
}
