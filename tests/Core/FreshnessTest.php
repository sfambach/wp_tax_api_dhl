<?php declare(strict_types=1);

namespace TaxApiDhl\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxApiDhl\Core\Tracking\Freshness;

final class FreshnessTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testNothingStoredIsAsked(): void
    {
        self::assertFalse(Freshness::holds(null, self::NOW, 12));
    }

    public function testYoungerThanTheHoursHolds(): void
    {
        self::assertTrue(Freshness::holds(['stage' => 'shipped', 'checked_at' => self::NOW - 11 * 3600], self::NOW, 12));
    }

    public function testOlderThanTheHoursIsAskedAgain(): void
    {
        self::assertFalse(Freshness::holds(['stage' => 'shipped', 'checked_at' => self::NOW - 13 * 3600], self::NOW, 12));
    }

    public function testDeliveredHoldsForEver(): void
    {
        self::assertTrue(Freshness::holds(['stage' => 'delivered', 'checked_at' => 0], self::NOW, 12));
    }

    public function testZeroHoursAlwaysAsks(): void
    {
        self::assertFalse(Freshness::holds(['stage' => 'announced', 'checked_at' => self::NOW], self::NOW, 0));
    }
}
