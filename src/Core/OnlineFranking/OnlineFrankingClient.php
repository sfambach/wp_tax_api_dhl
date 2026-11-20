<?php declare(strict_types=1);

namespace TaxApiDhl\Core\OnlineFranking;

use TaxApiDhl\Core\Api\DhlApiException;
use TaxApiDhl\Core\Api\Environment;
use Taxmod\Core\Http\HttpRequest;
use Taxmod\Core\Http\HttpResponse;
use Taxmod\Core\Http\HttpTransport;

/**
 * DHL Parcel DE Private Shipping («Online Frankierung»): the product catalog with prices, an unpaid cart that the
 * owner pays on DHL's page, the cart's state and the label of a paid shipment. Creating a cart costs nothing; money
 * moves only when the owner pays on DHL's page.
 *
 * ```mermaid
 * sequenceDiagram
 *   participant T as Taxmod
 *   participant D as DHL
 *   T->>D: GET catalog (prices)
 *   T->>D: POST shopping-carts/pre-paid
 *   D-->>T: entryUrl (state PREPAID)
 *   Note over T,D: owner pays on DHL's page
 *   T->>D: GET shopping-carts/{id} (PAYED, shipment number)
 *   T->>D: GET labels/{number} (PDF)
 * ```
 *
 * @see https://developer.dhl.com/api-reference/parcel-de-private-shipping-post-parcel-germany
 */
final class OnlineFrankingClient
{
    public function __construct(
        private readonly HttpTransport $transport,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly Environment $environment = Environment::Production,
    ) {
    }

    public function catalog(): ProductCatalog
    {
        return ProductCatalog::fromApi($this->catalogBody());
    }

    /** @return array<string, mixed> the catalog as DHL sent it, for the boundary to cache */
    public function catalogBody(): array
    {
        return self::json($this->send('GET', '/catalog/current/products'), 200);
    }

    public function createCart(string $productId, PostalAddress $sender, PostalAddress $receiver, string $confirmationEmail = '', string $notifyUrl = ''): Cart
    {
        $confirmation = array_filter(['email' => $confirmationEmail, 'notifyUrl' => $notifyUrl], static fn (string $value): bool => $value !== '');
        $cart = ['items' => [[
            'type'    => 'ShipmentItem',
            'product' => ['id' => $productId],
            'address' => ['sender' => $sender->toApi(), 'receiver' => $receiver->toApi()],
        ]]];

        if ($confirmation !== []) {
            $cart['confirmation'] = $confirmation;
        }

        $response = $this->send('POST', '/shopping-carts/pre-paid', (string) json_encode(['shoppingcart' => $cart]));
        $created = Cart::fromApi(self::json($response, 201));

        if ($created->payUrl === '') {
            throw new DhlApiException('DHL created the cart without a page to pay it.');
        }

        return $created;
    }

    public function cart(string $cartId): Cart
    {
        return Cart::fromApi(self::json($this->send('GET', '/shopping-carts/' . rawurlencode($cartId)), 200), $cartId);
    }

    /** The label PDF of a paid shipment, as bytes. */
    public function label(string $shipmentNumber): string
    {
        $response = $this->send('GET', '/labels/' . rawurlencode($shipmentNumber), null, 'application/pdf');

        if ($response->status !== 200 || ! str_starts_with($response->body, '%PDF')) {
            throw new DhlApiException(self::problem($response));
        }

        return $response->body;
    }

    public function baseUrl(): string
    {
        return match ($this->environment) {
            Environment::Sandbox    => 'https://api-sandbox.dhl.com/parcel/de/shipping/of/v1/public',
            Environment::Production => 'https://api-eu.dhl.com/parcel/de/shipping/of/v1/public',
        };
    }

    private function send(string $method, string $path, ?string $body = null, string $accept = 'application/json'): HttpResponse
    {
        $headers = ['dhl-api-key' => $this->apiKey, 'Accept' => $accept];

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->transport->send(new HttpRequest($method, $this->baseUrl() . $path, $headers, $body ?? ''));
    }

    /** @return array<string, mixed> `mixed` because the shape differs by call */
    private static function json(HttpResponse $response, int $expected): array
    {
        if ($response->status !== $expected) {
            throw new DhlApiException(self::problem($response));
        }

        return $response->json();
    }

    private static function problem(HttpResponse $response): string
    {
        $body = $response->json();
        $text = match ($response->status) {
            401, 403 => 'DHL refused the API key for the Online Frankierung (is the app approved for «Parcel DE Private Shipping»?)',
            402      => 'DHL has no paid label for this number yet',
            429      => 'Too many calls to DHL',
            default  => 'DHL answered HTTP ' . $response->status,
        };

        foreach (['detail', 'title', 'message'] as $field) {
            if (is_string($body[$field] ?? null) && $body[$field] !== '') {
                return $text . ': ' . $body[$field];
            }
        }

        return $text . '.';
    }
}
