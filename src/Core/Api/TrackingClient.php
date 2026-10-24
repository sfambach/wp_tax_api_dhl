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

    /**
     * @return Shipment|null null when DHL knows no shipment with this number (HTTP 404)
     * @throws DhlApiException on a refused key, an exceeded quota or any other failure
     */
    public function shipment(string $trackingNumber, string $language = 'de'): ?Shipment
    {
        $query = http_build_query(['trackingNumber' => $trackingNumber, 'language' => $language]);
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

        return is_array($first) ? Shipment::fromApi($first) : null;
    }

    private static function problem(HttpResponse $response): string
    {
        $body = $response->json();
        $text = match ($response->status) {
            401, 403 => 'DHL refused the API key',
            429      => 'Daily quota of the DHL app used up',
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
