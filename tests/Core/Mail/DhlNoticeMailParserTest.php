<?php declare(strict_types=1);

namespace TaxApiDhl\Tests\Core\Mail;

use PHPUnit\Framework\TestCase;
use TaxApiDhl\Core\Mail\DhlNoticeMailParser;
use Taxmod\Core\Import\Mail\MailMessage;
use Taxmod\Core\Import\Mail\MimeParser;

/**
 * Synthetic notices shaped like the real ones of 2026-10-19/20 (D-1020).
 */
final class DhlNoticeMailParserTest extends TestCase
{
    private static function mail(string $fixture): MailMessage
    {
        return (new MimeParser())->parse(1, (string) file_get_contents(__DIR__ . '/fixtures/' . $fixture . '.eml'));
    }

    public function testOnTheWayIsShippedWithItsTrackingNumber(): void
    {
        $parser = new DhlNoticeMailParser('0.0.11');
        $order = $parser->parse(self::mail('n1'))->toArray();

        self::assertTrue($parser->recognises(self::mail('n1')));
        self::assertSame(['shipped', true, ['00340400000000000002']], [$order['kind'], $order['number_missing'], $order['tracking']]);
    }

    public function testDeliveredFindsTheNumberInTheLink(): void
    {
        $order = (new DhlNoticeMailParser('0.0.11'))->parse(self::mail('n2'))->toArray();

        self::assertSame(['delivered', ['00340400000000000001']], [$order['kind'], $order['tracking']]);
    }

    public function testALabelMailIsLeftToTaxmodMail(): void
    {
        self::assertFalse((new DhlNoticeMailParser('0.0.11'))->recognises(self::mail('n3')));
    }
}
