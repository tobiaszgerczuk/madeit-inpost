<?php

namespace MadeitInpost;

/**
 * Klient API ShipX InPost (tworzenie przesyłek, status, etykieta).
 */
final class ShipX
{
    private const PRODUCTION = 'https://api-shipx-pl.easypack24.net/v1';

    private const SANDBOX = 'https://sandbox-api-shipx-pl.easypack24.net/v1';

    public function __construct(private string $organizationId, private string $token, private bool $sandbox) {}

    public function isConfigured(): bool
    {
        return $this->organizationId !== '' && $this->token !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function createShipment(array $payload): array|\WP_Error
    {
        return $this->request('POST', '/organizations/'.rawurlencode($this->organizationId).'/shipments', $payload);
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function shipment(string $id): array|\WP_Error
    {
        return $this->request('GET', '/shipments/'.rawurlencode($id));
    }

    /**
     * @return string|\WP_Error Zawartość PDF etykiety
     */
    public function label(string $id): string|\WP_Error
    {
        $response = wp_remote_get($this->base().'/shipments/'.rawurlencode($id).'/label?format=pdf&type=A6', [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/pdf'],
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return new \WP_Error('madeit_inpost_label', 'ShipX nie zwrócił etykiety (jeszcze się generuje albo przesyłka jest nieopłacona). Spróbuj za chwilę.');
        }

        return (string) wp_remote_retrieve_body($response);
    }

    private function base(): string
    {
        return $this->sandbox ? self::SANDBOX : self::PRODUCTION;
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>|\WP_Error
     */
    private function request(string $method, string $path, ?array $body = null): array|\WP_Error
    {
        $args = [
            'method' => $method,
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request($this->base().$path, $args);
        if (is_wp_error($response)) {
            return new \WP_Error('madeit_inpost_http', 'Nie można połączyć się z ShipX: '.$response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $data = is_array($data) ? $data : [];
        if ($code >= 200 && $code < 300) {
            return $data;
        }
        if ($code === 401 || $code === 403) {
            return new \WP_Error('madeit_inpost_auth', 'ShipX odrzucił token lub identyfikator organizacji. Sprawdź dane w zakładce Połączenie.');
        }

        return new \WP_Error('madeit_inpost_api', 'ShipX: '.self::describeError($data, $code));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function describeError(array $data, int $code): string
    {
        $parts = [];
        $walk = static function (mixed $value, string $path) use (&$walk, &$parts): void {
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $walk($item, $path === '' ? (string) $key : $path.'.'.$key);
                }
            } elseif ($value !== '' && $value !== null) {
                $field = preg_replace('/\.\d+$/', '', $path) ?? $path;
                $parts[] = ($field !== '' && ! is_numeric($field) ? $field.': ' : '').$value;
            }
        };
        $walk($data['details'] ?? [], '');
        if (! $parts) {
            $parts[] = (string) ($data['message'] ?? $data['error'] ?? 'błąd '.$code);
        }

        return implode('; ', array_slice($parts, 0, 4));
    }
}
