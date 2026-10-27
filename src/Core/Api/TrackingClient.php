<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Api;

use TaxApiDhl\Core\Tracking\Shipment;

/**
 * GET /shipments of the Unified Tracking API with the `DHL-API-Key` header. One call per tracking number; the daily
 * quota of the app (250 by default) is the boundary's to keep.
 *
 * @see https://developer.dhl.com/api-reference/shipment-tracking
 */
final class TrackingClient
{
    public function __construct(
        private readonly HttpTransport $transport,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly Environment $environment = Environment::Production,
    ) {
    }

    /** The values DHL's specification 1.5.8 lists for `service`; which of them an app may use depends on its approval. */
    public const SERVICES = ['dgf', 'dsc', 'ecommerce', 'ecommerce-apac', 'ecommerce-europe', 'ecommerce-ppl', 'ecommerce-iberia', 'express', 'freight',
        'parcel-de', 'parcel-nl', 'parcel-pl', 'parcel-uk', 'post-de', 'post-international', 'sameday', 'svb'];

    /**
     * @param string $service DHL's hint which division to ask (`parcel-de`, `express`…); empty lets DHL guess
     * @param string $recipientPostalCode parcel-de and parcel-nl show the full data only with it
     * @return Shipment|null null when DHL knows no shipment with this number (HTTP 404)
     * @throws DhlApiException on a refused key, an exceeded quota or any other failure
     */
    public function shipment(string $trackingNumber, string $language = 'de', string $service = '', string $recipientPostalCode = ''): ?Shipment
    {
        $query = http_build_query(array_filter([
            'trackingNumber'      => $trackingNumber,
            'service'             => $service,
            'recipientPostalCode' => $recipientPostalCode,
            'language'            => $language,
        ], static fn (string $value): bool => $value !== ''));
        $response = $this->transport->send(new HttpRequest('GET', $this->environment->baseUrl() . '/shipments?' . $query, [
            'DHL-API-Key' => $this->apiKey,
            'Accept'      => 'application/json',
        ]));

        if ($response->status === 404) {
            return null;
        }

        if ($response->status !== 200) {
            throw new DhlApiException(self::problem($response));
        }

        $shipments = $response->json()['shipments'] ?? null;
        $first = is_array($shipments) ? ($shipments[0] ?? null) : null;

        return is_array($first) ? Shipment::fromApi($first, $trackingNumber) : null;
    }

    private static function problem(HttpResponse $response): string
    {
        $body = $response->json();
        $text = match ($response->status) {
            401, 403 => 'DHL refused the API key',
            429      => 'DHL refused: too many calls (one every 5 seconds, 250 a day)',
            default  => 'DHL answered HTTP ' . $response->status,
        };

        foreach (['detail', 'title'] as $field) {
            if (is_string($body[$field] ?? null) && $body[$field] !== '') {
                return $text . ': ' . $body[$field];
            }
        }

        return $text . '.';
    }
}
