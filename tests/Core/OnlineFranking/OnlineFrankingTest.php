<?php declare(strict_types=1);

namespace TaxApiDhl\Tests\Core\OnlineFranking;

use PHPUnit\Framework\TestCase;
use TaxApiDhl\Core\Api\DhlApiException;
use TaxApiDhl\Core\Api\Environment;
use TaxApiDhl\Core\OnlineFranking\OnlineFrankingClient;
use TaxApiDhl\Core\OnlineFranking\Parcel;
use TaxApiDhl\Core\OnlineFranking\PostalAddress;
use TaxApiDhl\Tests\Core\ScriptedTransport;
use Taxmod\Core\Http\HttpResponse;

final class OnlineFrankingTest extends TestCase
{
    public function testRatesFitTheParcelAndComeCheapestFirst(): void
    {
        $client = new OnlineFrankingClient(new ScriptedTransport([new HttpResponse(200, self::catalogJson())]), 'key');

        $rates = $client->catalog()->ratesFor(new Parcel(800, 30, 20, 8));

        self::assertSame(['PAECK', 'PAK02'], array_map(static fn ($rate): string => $rate->productId, $rates));
        self::assertSame(419, $rates[0]->priceCents);
        self::assertSame('DHL Päckchen S', $rates[0]->name);
        self::assertFalse($rates[0]->tracked);
        self::assertTrue($rates[1]->tracked);
    }

    public function testTooHeavyOrTooBigOrUnavailableCountryIsLeftOut(): void
    {
        $catalog = (new OnlineFrankingClient(new ScriptedTransport([new HttpResponse(200, self::catalogJson())]), 'key'))->catalog();

        self::assertSame(['PAK02'], array_map(static fn ($rate): string => $rate->productId, $catalog->ratesFor(new Parcel(1500, 30, 20, 8))));
        self::assertSame(['PAK02'], array_map(static fn ($rate): string => $rate->productId, $catalog->ratesFor(new Parcel(800, 40, 20, 12))));
        self::assertSame([], $catalog->ratesFor(new Parcel(800, 30, 20, 8, 'CHE')));
    }

    public function testAParcelTurnedOnItsSideFitsTheSame(): void
    {
        $catalog = (new OnlineFrankingClient(new ScriptedTransport([new HttpResponse(200, self::catalogJson())]), 'key'))->catalog();

        self::assertCount(2, $catalog->ratesFor(new Parcel(800, 8, 20, 30)));
    }

    public function testCartIsPostedWithKeyAndAddressesAndReturnsThePayPage(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(201, (string) json_encode(['shoppingcart' => [
            'shoppingCartId' => 'SC1', 'state' => 'PREPAID', 'download' => ['entryUrl' => 'https://www.dhl.de/pay?token=x'],
            'items' => [['prices' => [['amount' => '5.49', 'id' => 'PAK02']]]],
        ]]))]);

        $cart = (new OnlineFrankingClient($transport, 'secret-key', Environment::Sandbox))->createCart('PAK02', self::address('Stefan'), self::address('Käufer'), 'me@example.org');

        $request = $transport->sent[0];
        self::assertSame('POST', $request->method);
        self::assertSame('https://api-sandbox.dhl.com/parcel/de/shipping/of/v1/public/shopping-carts/pre-paid', $request->url);
        self::assertSame('secret-key', $request->headers['dhl-api-key']);
        $body = json_decode($request->body, true);
        self::assertSame('PAK02', $body['shoppingcart']['items'][0]['product']['id']);
        self::assertSame('Käufer', $body['shoppingcart']['items'][0]['address']['receiver']['name2']);
        self::assertSame('me@example.org', $body['shoppingcart']['confirmation']['email']);
        self::assertSame('SC1', $cart->id);
        self::assertSame(549, $cart->totalCents);
        self::assertSame('https://www.dhl.de/pay?token=x', $cart->payUrl);
        self::assertFalse($cart->isPaid());
    }

    public function testPaidCartCarriesTheShipmentNumber(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(200, (string) json_encode(['shoppingcart' => [
            'state' => 'PAYED', 'items' => [['shipmentNumber' => '00340434161094042557', 'prices' => [['amount' => 5.49]]]],
        ]]))]);

        $cart = (new OnlineFrankingClient($transport, 'key'))->cart('SC1');

        self::assertStringEndsWith('/shopping-carts/SC1', $transport->sent[0]->url);
        self::assertTrue($cart->isPaid());
        self::assertSame('SC1', $cart->id);
        self::assertSame(['00340434161094042557'], $cart->shipmentNumbers);
    }

    public function testLabelIsAPdfOrAnError(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(200, '%PDF-1.4 x'), new HttpResponse(402, '{"detail":"not paid"}')]);
        $client = new OnlineFrankingClient($transport, 'key');

        self::assertSame('%PDF-1.4 x', $client->label('003404'));
        self::assertSame('application/pdf', $transport->sent[0]->headers['Accept']);
        $this->expectException(DhlApiException::class);
        $this->expectExceptionMessage('not paid');
        $client->label('003404');
    }

    public function testRefusedKeyNamesTheApproval(): void
    {
        $this->expectException(DhlApiException::class);
        $this->expectExceptionMessage('Private Shipping');
        (new OnlineFrankingClient(new ScriptedTransport([new HttpResponse(401, '{}')]), 'key'))->catalog();
    }

    public function testAddressLongerThanDhlTakesIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PostalAddress(str_repeat('x', 36), 'Weg', '1', '12345', 'Ort');
    }

    private static function address(string $name): PostalAddress
    {
        return new PostalAddress($name, 'Sträßchensweg', '1-5', '53113', 'Bonn');
    }

    private static function catalogJson(): string
    {
        return (string) json_encode(['products' => [
            'PAECK' => [
                'attributes' => ['maxWeight' => 1000, 'maxLength' => 35, 'maxWidth' => 25, 'maxDepth' => 10, 'tracking' => false, 'displayName' => ['text' => 'DHL Päckchen S']],
                'regions'    => [['countries' => ['DEU'], 'price' => ['amount' => 4.19, 'tax' => 19]], ['countries' => ['CHE'], 'unavailable' => true]],
            ],
            'PAK02' => [
                'attributes' => ['maxWeight' => 2000, 'maxLength' => 60, 'maxWidth' => 30, 'maxDepth' => 15, 'tracking' => true, 'liabilityLimit' => 500, 'displayName' => ['text' => 'DHL Paket 2 kg']],
                'regions'    => [['countries' => ['DEU'], 'price' => ['amount' => 5.49, 'tax' => 19]]],
            ],
        ]]);
    }
}
