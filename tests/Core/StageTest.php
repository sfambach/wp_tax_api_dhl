<?php declare(strict_types=1);

namespace TaxApiDhl\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TaxApiDhl\Core\Tracking\Shipment;
use TaxApiDhl\Core\Tracking\Stage;

final class StageTest extends TestCase
{
    /** @return array<string, array{string, list<string>, Stage}> */
    public static function cases(): array
    {
        return [
            'label only'          => ['pre-transit', [], Stage::Announced],
            'on its way'          => ['transit', ['transit', 'pre-transit'], Stage::Shipped],
            'delivered'           => ['delivered', ['delivered', 'transit'], Stage::Delivered],
            'failed'              => ['failure', ['failure', 'transit'], Stage::Problem],
            'unknown, events say' => ['unknown', ['unknown', 'transit'], Stage::Shipped],
            'unknown throughout'  => ['unknown', ['unknown'], Stage::Announced],
        ];
    }

    /** @param list<string> $events newest first */
    #[DataProvider('cases')]
    public function testStage(string $status, array $events, Stage $expected): void
    {
        $shipment = Shipment::fromApi([
            'id'     => '00340434292135100162',
            'status' => ['timestamp' => '2026-10-24T08:00:00', 'statusCode' => $status],
            'events' => array_map(static fn (string $code): array => ['timestamp' => '2026-10-24T08:00:00', 'statusCode' => $code], $events),
        ]);

        self::assertSame($expected, $shipment->stage());
    }
}
