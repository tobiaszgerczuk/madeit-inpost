<?php

/**
 * Plugin logic without WordPress. Run: php tests/calculator.php
 */
define('ABSPATH', __DIR__);

require __DIR__.'/../src/Calculator.php';

use Madeit\Inpost\Calculator;

function expectThat(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

expectThat(Calculator::shippingCost(12.99, 200, 150) === 12.99, 'Below the threshold the customer pays.');
expectThat(Calculator::shippingCost(12.99, 200, 200) === 0.0, 'Exactly the threshold means free delivery.');
expectThat(Calculator::shippingCost(12.99, 0, 5000) === 12.99, 'No threshold: always paid.');
expectThat(Calculator::shippingCost(-3, 0, 10) === 0.0, 'A negative cost is not possible.');

foreach (['WAW01M', 'KRA010', 'POP-WAW12', 'GDA05A'] as $name) {
    expectThat(Calculator::isValidPointName($name), "$name is a valid point.");
}
foreach (['', 'ab', 'waw01m', 'WAW 01', "WAW01M'; DROP", '-WAW01'] as $name) {
    expectThat(! Calculator::isValidPointName($name), "'$name' is not a valid point.");
}

expectThat(Calculator::fillTemplate('Point: {point}, {address}', ['point' => 'WAW01M', 'address' => 'Warsaw']) === 'Point: WAW01M, Warsaw', 'Template placeholders are filled.');

echo "OK\n";
