<?php declare(strict_types=1);

namespace TaxApiDhl\Tests\Core\OnlineFranking;

use PHPUnit\Framework\TestCase;
use TaxApiDhl\Core\OnlineFranking\OnlineFrankingClient;
use TaxApiDhl\Core\OnlineFranking\ProductCatalog;
use TaxApiDhl\Core\OnlineFranking\ShippingAdapter;
use TaxApiDhl\Tests\Core\ScriptedTransport;
use Taxmod\Core\Http\HttpResponse;

final class ShippingAdapterTest extends TestCase
{
    public function testRatesAreAddedToWhatOthersAnswered(): void
    {
        $rates = self::adapter([])->rates([['carrier' => 'gls', 'price_cents' => 450]], ['weight_g' => '800', 'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 8, 'country' => 'DE']);

        self::assertSame(['gls', 'dhl'], array_column($rates, 'carrier'));
        self::assertSame(['product' => 'PAK02', 'price_cents' => 549], ['product' => $rates[1]['product'], 'price_cents' => $rates[1]['price_cents']]);
    }

    public function testParcelWithoutWeightBecomesAnErrorEntry(): void
    {
        $rates = self::adapter([])->rates([], ['length_cm' => 30]);

        self::assertSame('dhl', $rates[0]['carrier']);
        self::assertArrayHasKey('error', $rates[0]);
    }

    public function testBuyAnswersOnlyForDhlAndGivesThePayPage(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(201, '{"shoppingcart":{"shoppingCartId":"SC9","state":"PREPAID","download":{"entryUrl":"https://www.dhl.de/p"},"items":[{"prices":[{"amount":5.49}]}]}}')]);
        $adapter = new ShippingAdapter(new OnlineFrankingClient($transport, 'key'), static fn (): ProductCatalog => ProductCatalog::fromApi([]));
        $order = ['sender' => self::address('Stefan'), 'receiver' => self::address('Käufer'), 'email' => ''];

        self::assertNull($adapter->buy(null, ['carrier' => 'gls'], $order));
        self::assertSame([], $transport->sent);
        $bought = $adapter->buy(null, ['carrier' => 'dhl', 'product' => 'PAK02'], $order);
        self::assertSame(['carrier' => 'dhl', 'reference' => 'SC9', 'state' => 'awaiting_payment', 'pay_url' => 'https://www.dhl.de/p', 'price_cents' => 549], $bought);
        self::assertSame('DEU', json_decode($transport->sent[0]->body, true)['shoppingcart']['items'][0]['address']['receiver']['country']);
    }

    public function testBuyWithIncompleteAddressSendsNothing(): void
    {
        $transport = new ScriptedTransport([]);
        $adapter = new ShippingAdapter(new OnlineFrankingClient($transport, 'key'), static fn (): ProductCatalog => ProductCatalog::fromApi([]));

        $answer = $adapter->buy(null, ['carrier' => 'dhl', 'product' => 'PAK02'], ['sender' => [], 'receiver' => []]);

        self::assertArrayHasKey('error', $answer);
        self::assertSame([], $transport->sent);
    }

    public function testStatusReadsTheNumbersOfAPaidCart(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(200, '{"shoppingcart":{"state":"PAYED","items":[{"shipmentNumber":"0034"}]}}')]);
        $adapter = new ShippingAdapter(new OnlineFrankingClient($transport, 'key'), static fn (): ProductCatalog => ProductCatalog::fromApi([]));

        self::assertSame(['carrier' => 'dhl', 'state' => 'paid', 'numbers' => ['0034'], 'pay_url' => ''], $adapter->status(null, ['carrier' => 'dhl', 'reference' => 'SC9']));
    }

    /** @return array<string, string> */
    private static function address(string $name): array
    {
        return ['name' => $name, 'street' => 'Weg', 'number' => '1', 'postal_code' => '53113', 'city' => 'Bonn', 'country' => 'DE'];
    }

    /** @param list<HttpResponse> $answers */
    private static function adapter(array $answers): ShippingAdapter
    {
        $catalog = ProductCatalog::fromApi(['products' => [
            'PAK02' => ['attributes' => ['maxWeight' => 2000, 'maxLength' => 60, 'maxWidth' => 30, 'maxDepth' => 15, 'tracking' => true], 'regions' => [['countries' => ['DEU'], 'price' => ['amount' => 5.49]]]],
        ]]);

        return new ShippingAdapter(new OnlineFrankingClient(new ScriptedTransport($answers), 'key'), static fn (): ProductCatalog => $catalog);
    }
}
