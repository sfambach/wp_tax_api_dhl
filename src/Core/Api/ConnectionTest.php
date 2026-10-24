<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Api;

/**
 * Proves the key with one tracking request that changes nothing. «Not found» counts as success: DHL only says so
 * after it accepted the key.
 */
final class ConnectionTest
{
    /** The sample number of DHL's own Postman collection for the Unified API. */
    public const PROBE_NUMBER = '00340434292135100162';

    public function __construct(private readonly TrackingClient $client)
    {
    }

    /** @return string what DHL answered, in a few words */
    public function run(): string
    {
        $shipment = $this->client->shipment(self::PROBE_NUMBER, 'en');

        return $shipment === null ? 'key accepted, sample number unknown' : 'key accepted, status ' . $shipment->statusCode;
    }
}
