<?php declare(strict_types=1);

namespace TaxApiDhl\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxApiDhl\Core\Api\ConnectionTest;
use TaxApiDhl\Core\Api\DhlApiException;
use TaxApiDhl\Core\Api\Environment;
use TaxApiDhl\Core\Api\HttpResponse;
use TaxApiDhl\Core\Api\TrackingClient;
use TaxApiDhl\Core\Tracking\Stage;

final class TrackingClientTest extends TestCase
{
    public function testSendsKeyInHeaderAndNumberInQuery(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(200, self::example())]);
        (new TrackingClient($transport, 'secret-key', Environment::Sandbox))->shipment('GMDBD8E9CCE94842E495B7', 'en');

        $request = $transport->sent[0];
        self::assertSame('GET', $request->method);
        self::assertSame('https://api-sandbox.dhl.com/track/shipments?trackingNumber=GMDBD8E9CCE94842E495B7&language=en', $request->url);
        self::assertSame('secret-key', $request->headers['DHL-API-Key']);
    }

    public function testReadsTheExampleOfTheSpecification(): void
    {
        $shipment = (new TrackingClient(new ScriptedTransport([new HttpResponse(200, self::example())]), 'k'))->shipment('GMDBD8E9CCE94842E495B7');

        self::assertNotNull($shipment);
        self::assertSame('GMDBD8E9CCE94842E495B7', $shipment->trackingNumber);
        self::assertSame('ecommerce', $shipment->service);
        self::assertSame('transit', $shipment->statusCode);
        self::assertSame('ARRIVED AT CUSTOMS', $shipment->status);
        self::assertCount(8, $shipment->events);
        self::assertSame(Stage::Shipped, $shipment->stage());
        self::assertSame('2023-01-18T09:39:28', $shipment->firstMovement());
    }

    public function testNotFoundIsNoShipment(): void
    {
        $client = new TrackingClient(new ScriptedTransport([new HttpResponse(404, '{"title":"No shipment with given tracking number found.","status":404}')]), 'k');

        self::assertNull($client->shipment('123'));
    }

    public function testRefusedKeyIsAnError(): void
    {
        $client = new TrackingClient(new ScriptedTransport([new HttpResponse(401, '{"title":"Unauthorized","detail":"Invalid API key"}')]), 'k');

        $this->expectException(DhlApiException::class);
        $this->expectExceptionMessage('DHL refused the API key: Invalid API key');
        $client->shipment('123');
    }

    public function testConnectionTestAcceptsNotFound(): void
    {
        $test = new ConnectionTest(new TrackingClient(new ScriptedTransport([new HttpResponse(404, '')]), 'k'));

        self::assertSame('key accepted, sample number unknown', $test->run());
    }

    public function testServiceHintGoesIntoTheQuery(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(404, '')]);
        (new TrackingClient($transport, 'k'))->shipment('A005EFF1A1000000043E', 'de', 'parcel-de', '53113');

        self::assertSame('https://api-eu.dhl.com/track/shipments?trackingNumber=A005EFF1A1000000043E&service=parcel-de&recipientPostalCode=53113&language=de', $transport->sent[0]->url);
    }

    public function testKeepsTheAskedNumberWhenDhlAnswersWithAnInternalId(): void
    {
        $body = '{"shipments":[{"id":"7978A405-86D6-4928-B58D-263FFBD3CD97","service":"svb","status":{"timestamp":"2026-10-26T03:44:19+01:00","statusCode":"transit"}}]}';
        $shipment = (new TrackingClient(new ScriptedTransport([new HttpResponse(200, $body)]), 'k'))->shipment('A005EFF1A1000000042D');

        self::assertSame('A005EFF1A1000000042D', $shipment?->trackingNumber);
        self::assertSame('svb', $shipment?->service);
    }

    private static function example(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/shipments-example.json');
    }
}
