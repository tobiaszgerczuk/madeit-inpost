<?php

namespace WcoInpost;

/**
 * Czysta logika wtyczki: koszt dostawy, walidacja punktu i budowa przesyłki ShipX. Bez WordPressa.
 */
final class Calculator
{
    public const SIZES = ['A' => 'small', 'B' => 'medium', 'C' => 'large'];

    /**
     * Koszt dostawy do paczkomatu. Darmowa od progu.
     */
    public static function shippingCost(float $cost, float $freeFrom, float $cartTotal): float
    {
        if ($freeFrom > 0 && round($cartTotal, 2) >= round($freeFrom, 2)) {
            return 0.0;
        }

        return max(0.0, round($cost, 2));
    }

    /**
     * Kody punktów InPost to np. WAW01M, KRA010, POP-WAW12.
     */
    public static function isValidPointName(string $name): bool
    {
        return preg_match('/^[A-Z0-9][A-Z0-9-]{2,19}$/', $name) === 1;
    }

    public static function normalizeSize(string $size): string
    {
        $size = strtoupper(trim($size));

        return array_key_exists($size, self::SIZES) ? $size : 'A';
    }

    /**
     * Telefon w formacie ShipX: 9 cyfr bez prefiksu kraju.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '48')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 13 && str_starts_with($digits, '0048')) {
            $digits = substr($digits, 4);
        }

        return $digits;
    }

    public static function isValidPhone(string $phone): bool
    {
        return preg_match('/^[1-9]\d{8}$/', self::normalizePhone($phone)) === 1;
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone: string, point: string, size: string, reference: string, cod: float, currency: string, sending_method: string}  $input
     * @return array<string, mixed>
     */
    public static function shipmentPayload(array $input): array
    {
        $payload = [
            'receiver' => [
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'email' => $input['email'],
                'phone' => self::normalizePhone($input['phone']),
            ],
            'parcels' => [['template' => self::SIZES[self::normalizeSize($input['size'])]]],
            'service' => 'inpost_locker_standard',
            'custom_attributes' => [
                'target_point' => $input['point'],
                'sending_method' => $input['sending_method'] === 'parcel_locker' ? 'parcel_locker' : 'dispatch_order',
            ],
            'reference' => mb_substr($input['reference'], 0, 100),
        ];
        if ($input['cod'] > 0) {
            $payload['cod'] = ['amount' => round($input['cod'], 2), 'currency' => $input['currency']];
        }

        return $payload;
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function fillTemplate(string $template, array $values): string
    {
        $pairs = [];
        foreach ($values as $key => $value) {
            $pairs['{'.$key.'}'] = $value;
        }

        return strtr($template, $pairs);
    }
}
