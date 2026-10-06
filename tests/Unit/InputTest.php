<?php

namespace Tests\Unit;

use App\Support\Input;
use PHPUnit\Framework\TestCase;

class InputTest extends TestCase
{
    public function test_money_is_optional_and_bounded(): void
    {
        $this->assertSame([true, null], Input::money('', 'Price'));
        $this->assertSame([true, null], Input::money(null, 'Price'));
        $this->assertSame([true, 12.36], Input::money('12.356', 'Price'));
        $this->assertSame([false, "Price must be a number."], Input::money('abc', 'Price'));
        $this->assertSame([false, "Price can't be negative."], Input::money(-1, 'Price'));
        $this->assertSame([false, 'Price is too large.'], Input::money(Input::MAX_MONEY + 1, 'Price'));
    }

    public function test_count_defaults_when_blank(): void
    {
        $this->assertSame([true, 5], Input::count('', 'Threshold', 5));
        $this->assertSame([true, 7], Input::count('7', 'Threshold', 5));
        $this->assertSame([false, 'Threshold must be a whole number.'], Input::count('1.5', 'Threshold', 5));
        $this->assertSame([false, "Threshold can't be negative."], Input::count('-2', 'Threshold', 5));
    }

    public function test_qty_must_be_a_positive_whole_number(): void
    {
        $this->assertSame([true, 3], Input::qty('3'));
        $this->assertSame([false, 'Quantity must be a whole number above 0.'], Input::qty('0'));
        $this->assertSame([false, 'Quantity must be a whole number above 0.'], Input::qty('2.5'));
        $this->assertSame([false, 'Quantity must be a whole number above 0.'], Input::qty('abc'));
        $this->assertSame([false, 'Quantity is too large.'], Input::qty(Input::MAX_QTY + 1));
    }

    public function test_uuid_recognition(): void
    {
        $this->assertTrue(Input::isUuid('43807bf2-e1d8-4802-ae7e-545be885b619'));
        $this->assertFalse(Input::isUuid('not-a-uuid'));
        $this->assertFalse(Input::isUuid(123));
    }

    public function test_tx_date_is_anchored_and_bounded(): void
    {
        [$ok, $value] = Input::txDate('2026-10-05');
        $this->assertTrue($ok);
        $this->assertSame('2026-10-05 12:00:00', $value);

        [$ok, $error] = Input::txDate('2026-13-45');
        $this->assertFalse($ok);
        $this->assertSame('Enter a valid date.', $error);

        [$ok, $error] = Input::txDate('1990-01-01');
        $this->assertFalse($ok);
        $this->assertSame('That date is too far in the past.', $error);

        [$ok, $error] = Input::txDate(gmdate('Y-m-d', strtotime('+5 days')));
        $this->assertFalse($ok);
        $this->assertSame("The date can't be in the future.", $error);
    }

    public function test_blank_date_means_now(): void
    {
        [$ok, $value] = Input::txDate('');
        $this->assertTrue($ok);
        $this->assertSame(gmdate('Y-m-d H:i:s'), $value);
    }

    public function test_safe_next_path_blocks_protocol_relative_urls(): void
    {
        $this->assertSame('/reports', Input::safeNextPath('/reports'));
        $this->assertSame('/', Input::safeNextPath('//evil.com'));
        $this->assertSame('/', Input::safeNextPath('/\\evil.com'));
        $this->assertSame('/', Input::safeNextPath('https://evil.com'));
        $this->assertSame('/', Input::safeNextPath(''));
        $this->assertSame('/', Input::safeNextPath(null));
    }

    public function test_idempotency_key_is_stable_and_uuid_shaped(): void
    {
        $input = [
            'shopId' => 'shop-1',
            'type' => 'sale',
            'paymentMethod' => 'cash',
            'amount' => '500',
            'customerName' => '  Kofi  ',
            'customerPhone' => '024',
            'date' => '2026-10-05',
            'outItems' => [['modelId' => 'b', 'qty' => 1], ['modelId' => 'a', 'qty' => 2]],
            'swapIn' => [['name' => 'iPhone 11']],
        ];

        $key = Input::idempotencyKey($input);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key);

        // Line order must not change the key (the original sorted too).
        $reordered = $input;
        $reordered['outItems'] = array_reverse($input['outItems']);
        $this->assertSame($key, Input::idempotencyKey($reordered));

        // A different submission must produce a different key.
        $changed = $input;
        $changed['amount'] = '501';
        $this->assertNotSame($key, Input::idempotencyKey($changed));
    }

    public function test_email_validation(): void
    {
        $this->assertTrue(Input::email('a@b.co'));
        $this->assertFalse(Input::email('nope'));
        $this->assertFalse(Input::email(42));
    }
}
