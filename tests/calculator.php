<?php

/**
 * Logika wtyczki bez WordPressa. Uruchom: php tests/calculator.php
 */

require __DIR__.'/../src/Calculator.php';

use MadeitInpost\Calculator;

function expectThat(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

expectThat(Calculator::shippingCost(12.99, 200, 150) === 12.99, 'Poniżej progu płaci klient.');
expectThat(Calculator::shippingCost(12.99, 200, 200) === 0.0, 'Dokładnie próg to dostawa gratis.');
expectThat(Calculator::shippingCost(12.99, 0, 5000) === 12.99, 'Brak progu: zawsze płatna.');
expectThat(Calculator::shippingCost(-3, 0, 10) === 0.0, 'Ujemny koszt nie jest możliwy.');
expectThat(Calculator::shippingCost(10, 199.99, 199.994) === 10.0 || Calculator::shippingCost(10, 199.99, 199.994) === 0.0, 'Zaokrąglenie nie rzuca wyjątku.');

foreach (['WAW01M', 'KRA010', 'POP-WAW12', 'GDA05A'] as $name) {
    expectThat(Calculator::isValidPointName($name), "$name to poprawny punkt.");
}
foreach (['', 'ab', 'waw01m', 'WAW 01', "WAW01M'; DROP", '-WAW01'] as $name) {
    expectThat(! Calculator::isValidPointName($name), "'$name' nie jest poprawnym punktem.");
}

expectThat(Calculator::normalizePhone('+48 512-345-678') === '512345678', 'Prefiks +48.');
expectThat(Calculator::normalizePhone('0048512345678') === '512345678', 'Prefiks 0048.');
expectThat(Calculator::normalizePhone('512 345 678') === '512345678', 'Spacje.');
expectThat(Calculator::isValidPhone('512345678') && ! Calculator::isValidPhone('0123') && ! Calculator::isValidPhone('012345678'), 'Walidacja numeru.');

expectThat(Calculator::normalizeSize('b') === 'B' && Calculator::normalizeSize('Z') === 'A', 'Rozmiar gabarytu.');

$payload = Calculator::shipmentPayload([
    'first_name' => 'Anna', 'last_name' => 'Nowak', 'email' => 'anna@example.com', 'phone' => '+48 512 345 678',
    'point' => 'WAW01M', 'size' => 'C', 'reference' => 'Zamówienie 123', 'cod' => 0.0, 'currency' => 'PLN', 'sending_method' => 'parcel_locker',
]);
expectThat($payload['parcels'][0]['template'] === 'large', 'Gabaryt C to large.');
expectThat($payload['receiver']['phone'] === '512345678', 'Telefon znormalizowany.');
expectThat($payload['custom_attributes'] === ['target_point' => 'WAW01M', 'sending_method' => 'parcel_locker'], 'Punkt i metoda nadania.');
expectThat(! isset($payload['cod']), 'Bez pobrania nie ma pola cod.');
$cod = Calculator::shipmentPayload(['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.pl', 'phone' => '512345678', 'point' => 'WAW01M', 'size' => 'A', 'reference' => 'x', 'cod' => 99.9, 'currency' => 'PLN', 'sending_method' => 'bogus']);
expectThat($cod['cod'] === ['amount' => 99.9, 'currency' => 'PLN'], 'Pobranie z kwotą i walutą.');
expectThat($cod['custom_attributes']['sending_method'] === 'dispatch_order', 'Nieznana metoda nadania wraca do kuriera.');

expectThat(Calculator::fillTemplate('Wybrano {point}', ['point' => 'WAW01M']) === 'Wybrano WAW01M', 'Znaczniki w komunikacie.');

echo "OK\n";
