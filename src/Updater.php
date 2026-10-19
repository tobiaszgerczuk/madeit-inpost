<?php

namespace MadeitInpost;

final class Updater
{
    private string $basename;

    private string $lastError = '';

    private int $lastCode = 0;

    private bool $lastWasUnknownKey = false;

    public function __construct(private string $pluginFile, private string $apiUrl)
    {
        $this->basename = plugin_basename($pluginFile);
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check']);
        add_filter('upgrader_pre_download', [$this, 'download'], 10, 4);
        add_filter('plugins_api', [$this, 'pluginInformation'], 10, 3);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_madeit_inpost_license', [$this, 'saveLicense']);
    }

    private function request(string $path): ?array
    {
        $key = (string) get_option('madeit_inpost_license_key', '');
        if ($key === '' || str_ends_with($this->apiUrl, '.invalid')) {
            return null;
        }
        $response = wp_remote_post(rtrim($this->apiUrl, '/').'/api/v1/'.$path, [
            'timeout' => 8,
            'body' => ['license_key' => $key, 'site_url' => home_url('/')],
        ]);
        if (is_wp_error($response)) {
            $this->lastError = 'Brak połączenia z WCO Labs ('.$response->get_error_message().').';

            return null;
        }
        $code = wp_remote_retrieve_response_code($response);
        $this->lastCode = $code;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        // Laravel answers an unknown key with a "No query results for model [..License]" 404;
        // a missing route is a 404 too, and must not be reported as a bad key.
        $this->lastWasUnknownKey = $code === 404 && is_array($data) && str_contains((string) ($data['message'] ?? ''), 'License');
        if ($code !== 200) {
            $this->lastError = $this->describeFailure($code, is_array($data) ? (string) ($data['message'] ?? '') : '');

            return null;
        }

        return is_array($data) ? $data : null;
    }

    private function describeFailure(int $code, string $message): string
    {
        return match (true) {
            $code === 404 && $this->lastWasUnknownKey => 'Nie znaleziono takiego klucza licencji.',
            $code === 429 => 'Zbyt wiele prób. Spróbuj ponownie za minutę.',
            $code === 403 && $message !== '' => $message,
            $code === 403 => 'Licencja nie ma uprawnień do tej operacji.',
            $code === 422 => 'Serwer odrzucił dane (adres sklepu lub klucz).',
            default => 'Serwer WCO Labs zwrócił błąd '.$code.'.',
        };
    }

    public function check($transient)
    {
        if (! isset($transient->checked[$this->basename])) {
            return $transient;
        }
        $data = $this->request('updates/check');
        if (! isset($data['version'], $data['download_url']) || ! version_compare($data['version'], $transient->checked[$this->basename], '>')) {
            return $transient;
        }
        $transient->response[$this->basename] = (object) [
            'id' => $this->apiUrl.'/updates/madeit-inpost',
            'slug' => 'madeit-inpost',
            'plugin' => $this->basename,
            'new_version' => $data['version'],
            'package' => $data['download_url'],
            'url' => $this->apiUrl,
            'requires' => $data['requires_wp'] ?? '',
            'requires_php' => $data['requires_php'] ?? '',
        ];

        return $transient;
    }

    public function pluginInformation($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? null) !== 'madeit-inpost') {
            return $result;
        }
        $data = $this->request('updates/check');
        if (! isset($data['version'])) {
            return $result;
        }

        return (object) [
            'name' => 'MadeIT InPost',
            'slug' => 'madeit-inpost',
            'version' => $data['version'],
            'author' => 'WCO Labs',
            'requires' => $data['requires_wp'] ?? '',
            'requires_php' => $data['requires_php'] ?? '',
            'download_link' => $data['download_url'] ?? '',
            'sections' => ['changelog' => wpautop(esc_html((string) ($data['changelog'] ?? '')))],
        ];
    }

    public function download($reply, $package, $upgrader, $hookExtra)
    {
        if (($hookExtra['plugin'] ?? null) !== $this->basename) {
            return $reply;
        }
        $data = $this->request('updates/check');
        if (empty($data['download_url'])) {
            return new \WP_Error('madeit_inpost_update', 'Nie można pobrać aktualizacji. Sprawdź licencję i połączenie z WCO Labs.');
        }

        return download_url($data['download_url']);
    }

    /**
     * Current license state as reported by WCO Labs. Cached for a few minutes unless $refresh is true.
     *
     * @return array{state: string, message?: string, expires_at?: string, sites_used?: int, site_limit?: int, site_active?: bool}
     */
    public function licenseStatus(bool $refresh = false): array
    {
        $key = (string) get_option('madeit_inpost_license_key', '');
        if ($key === '') {
            return ['state' => 'none'];
        }
        if (str_ends_with($this->apiUrl, '.invalid')) {
            return ['state' => 'unavailable'];
        }
        $fingerprint = md5($key.home_url('/'));
        $cached = get_transient('madeit_inpost_license_status');
        if (! $refresh && is_array($cached) && ($cached['fingerprint'] ?? '') === $fingerprint) {
            return $cached;
        }
        $this->lastCode = 0;
        $this->lastWasUnknownKey = false;
        $data = $this->request('licenses/status');
        if (! is_array($data) || ! isset($data['state'])) {
            return $this->lastWasUnknownKey
                ? ['state' => 'unknown', 'message' => 'Serwer nie zna takiego klucza licencji.']
                : ['state' => 'unreachable', 'message' => $this->lastError ?: 'Nie udało się sprawdzić statusu licencji.'];
        }
        $data['fingerprint'] = $fingerprint;
        set_transient('madeit_inpost_license_status', $data, 5 * MINUTE_IN_SECONDS);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array{tone: string, icon: string, title: string, text: string, updates: string}
     */
    private function describeStatus(array $status): array
    {
        $date = isset($status['expires_at']) ? wp_date('d.m.Y', strtotime((string) $status['expires_at'])) : '';
        $renew = esc_url(rtrim($this->apiUrl, '/').'/konto');

        return match ($status['state'] ?? '') {
            'none' => ['tone' => 'idle', 'icon' => '—', 'title' => 'Brak klucza', 'text' => 'Wklej klucz licencji, aby aktywować sklep i otrzymywać aktualizacje.', 'updates' => 'Niedostępne bez licencji'],
            'unavailable' => ['tone' => 'idle', 'icon' => '—', 'title' => 'Klucz zapisany', 'text' => 'Połączenie z WCO Labs nie jest jeszcze skonfigurowane.', 'updates' => 'Oczekują na konfigurację'],
            'unreachable' => ['tone' => 'warn', 'icon' => '!', 'title' => 'Nie można sprawdzić licencji', 'text' => (string) ($status['message'] ?? 'Brak połączenia z WCO Labs.'), 'updates' => 'Nie udało się ustalić'],
            'unknown' => ['tone' => 'error', 'icon' => '×', 'title' => 'Nieznany klucz', 'text' => 'Serwer nie zna takiego klucza licencji. Sprawdź, czy został wklejony w całości.', 'updates' => 'Niedostępne'],
            'expired' => ['tone' => 'warn', 'icon' => '!', 'title' => 'Licencja wygasła '.$date, 'text' => 'Wtyczka działa dalej, ale nowe wersje nie są już dostępne. <a href="'.$renew.'" target="_blank" rel="noopener">Odnów licencję w koncie klienta</a>.', 'updates' => 'Wstrzymane do odnowienia'],
            'revoked' => ['tone' => 'error', 'icon' => '×', 'title' => 'Licencja zablokowana', 'text' => 'Ta licencja została zablokowana. Skontaktuj się ze wsparciem WCO Labs.', 'updates' => 'Niedostępne'],
            'active' => ! empty($status['site_active'])
                ? ['tone' => 'ok', 'icon' => '✓', 'title' => 'Licencja aktywna', 'text' => 'Ważna do '.$date.'. Aktywne strony: '.(int) ($status['sites_used'] ?? 0).' z '.(int) ($status['site_limit'] ?? 0).'.', 'updates' => 'Dostępne do '.$date]
                : ['tone' => 'warn', 'icon' => '!', 'title' => 'Sklep nie jest aktywowany', 'text' => 'Licencja jest ważna do '.$date.', ale ten sklep nie jest do niej przypisany (strony: '.(int) ($status['sites_used'] ?? 0).' z '.(int) ($status['site_limit'] ?? 0).'). Kliknij „Zapisz i aktywuj”.', 'updates' => 'Dostępne po aktywacji'],
            default => ['tone' => 'warn', 'icon' => '!', 'title' => 'Nieznany stan licencji', 'text' => 'Nie rozpoznano odpowiedzi serwera.', 'updates' => 'Nie udało się ustalić'],
        };
    }

    public function menu(): void
    {
        add_submenu_page('madeit-inpost', 'Licencja MadeIT InPost', 'Licencja', 'manage_woocommerce', 'madeit-inpost-license', [$this, 'settings']);
    }

    public function settings(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die('Brak uprawnień.');
        }
        $key = (string) get_option('madeit_inpost_license_key', '');
        $apiReady = ! str_ends_with($this->apiUrl, '.invalid');
        $notice = isset($_GET['madeit_notice']) ? sanitize_key(wp_unslash($_GET['madeit_notice'])) : '';
        $messages = [
            'active' => 'Licencja została aktywowana dla tego sklepu.',
            'failed' => 'Nie udało się aktywować licencji. Sprawdź klucz i połączenie z WCO Labs.',
            'unavailable' => 'Klucz został zapisany. Aktywacja będzie dostępna po podłączeniu publicznego adresu WCO Labs.',
            'cleared' => 'Klucz licencji został usunięty.',
        ];
        echo '<div class="wrap madeit-admin"><div class="madeit-hero"><div><span class="madeit-eyebrow">MADEIT FREE SHIPPING / DOSTĘP</span><h1>Licencja</h1><p>Zarządzaj kluczem licencji i aktualizacjami wtyczki dla tego sklepu.</p></div>';
        echo '<a class="madeit-button madeit-button-light" href="'.esc_url(admin_url('admin.php?page=madeit-inpost')).'">← Wróć do ustawień</a></div>';
        if (isset($messages[$notice])) {
            $detail = $notice === 'failed' ? (string) get_transient('madeit_inpost_license_error') : '';
            echo '<div class="madeit-notice'.($notice === 'failed' ? ' madeit-notice-error' : '').'" role="status">'.esc_html($messages[$notice]).($detail !== '' ? ' '.esc_html($detail) : '').'</div>';
        }
        echo '<div class="madeit-license-layout"><section class="madeit-card madeit-license-card"><span class="madeit-eyebrow">KLUCZ LICENCJI</span><h2>Połącz sklep z WCO Labs</h2><p>Klucz znajdziesz na koncie klienta po zakupie wtyczki. Jedna licencja obejmuje jeden sklep.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="madeit_inpost_license">';
        wp_nonce_field('madeit_inpost_license', 'madeit_inpost_license_nonce');
        echo '<label><span>Klucz licencji</span><input type="password" name="madeit_inpost_license_key" value="'.esc_attr($key).'" autocomplete="off" spellcheck="false" placeholder="Wklej klucz licencji"></label>';
        echo '<button class="madeit-button madeit-button-primary" type="submit">'.($apiReady ? 'Zapisz i aktywuj' : 'Zapisz klucz').'</button></form></section>';
        // Ekran otwiera się rzadko i ma pokazywać aktualny stan, więc zawsze pytamy serwer (bez bufora).
        $info = $this->describeStatus($this->licenseStatus(true));
        echo '<aside class="madeit-card madeit-license-info"><span class="madeit-eyebrow">STATUS</span><div class="madeit-license-state"><span class="madeit-state-icon is-'.esc_attr($info['tone']).'">'.esc_html($info['icon']).'</span><div><strong>'.esc_html($info['title']).'</strong><p>'.wp_kses($info['text'], ['a' => ['href' => [], 'target' => [], 'rel' => []]]).'</p></div></div>';
        echo '<div class="madeit-license-meta"><span>Adres sklepu</span><strong>'.esc_html(home_url('/')).'</strong></div>';
        echo '<div class="madeit-license-meta"><span>Aktualizacje</span><strong>'.esc_html($info['updates']).'</strong></div></aside></div></div>';
    }

    public function saveLicense(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die('Brak uprawnień.');
        }
        check_admin_referer('madeit_inpost_license', 'madeit_inpost_license_nonce');
        $key = sanitize_text_field(wp_unslash($_POST['madeit_inpost_license_key'] ?? ''));
        if ($key === '' && ! str_ends_with($this->apiUrl, '.invalid')) {
            // Free the license slot on WCO Labs before the key is forgotten.
            $this->request('licenses/deactivate');
        }
        $key = preg_replace('/\s+/', '', $key) ?? $key;
        if ($key !== '' && ! preg_match('/^[A-Za-z0-9]{32}$/', $key)) {
            set_transient('madeit_inpost_license_error', 'To nie wygląda na klucz licencji. Klucz ma 32 znaki (litery i cyfry), a podana wartość ma '.strlen($key).'.', 60);
            wp_safe_redirect(admin_url('admin.php?page=madeit-inpost-license&madeit_notice=failed'));
            exit;
        }
        update_option('madeit_inpost_license_key', $key, false);
        delete_transient('madeit_inpost_license_error');
        delete_transient('madeit_inpost_license_status');
        $notice = $key === '' ? 'cleared' : (str_ends_with($this->apiUrl, '.invalid') ? 'unavailable' : (($this->request('licenses/activate')['status'] ?? null) === 'active' ? 'active' : 'failed'));
        if ($notice === 'failed' && $this->lastError !== '') {
            set_transient('madeit_inpost_license_error', $this->lastError, 60);
        }
        wp_safe_redirect(admin_url('admin.php?page=madeit-inpost-license&madeit_notice='.$notice));
        exit;
    }
}
