<?php

namespace Tests\Unit;

use App\Support\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function test_money_matches_the_original_format(): void
    {
        $this->assertSame('GHS 0.00', Format::money(null));
        $this->assertSame('GHS 1,234.56', Format::money(1234.56));
        $this->assertSame('GHS 9,999,999.99', Format::money(9999999.99));
    }

    public function test_number_is_a_plain_integer(): void
    {
        $this->assertSame('0', Format::number(null));
        $this->assertSame('42', Format::number(42));
        $this->assertSame('42', Format::number('42'));
    }

    public function test_date_time_shows_only_the_time_for_today(): void
    {
        $today = date('Y-m-d H:i:s');

        $this->assertSame(date('H:i'), Format::dateTime($today));
    }

    public function test_date_time_includes_the_day_for_older_rows(): void
    {
        $this->assertSame('5 Oct 2026, 14:30', Format::dateTime('2026-10-05 14:30:00'));
    }

    public function test_empty_values_render_as_an_em_dash(): void
    {
        $this->assertSame('—', Format::dateTime(null));
        $this->assertSame('—', Format::dateTime(''));
        $this->assertSame('—', Format::date(null));
    }

    public function test_today_is_utc(): void
    {
        $this->assertSame(gmdate('Y-m-d'), Format::today());
    }
}
