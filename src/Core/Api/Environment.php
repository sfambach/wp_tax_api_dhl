<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Api;

/**
 * The two servers of the Shipment Tracking – Unified API. An app approved for the sandbox only gets sample data there;
 * real shipments need the production approval in the DHL developer portal.
 *
 * @see https://developer.dhl.com/api-reference/shipment-tracking
 */
enum Environment: string
{
    case Sandbox    = 'sandbox';
    case Production = 'production';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Sandbox    => 'https://api-sandbox.dhl.com/track',
            self::Production => 'https://api-eu.dhl.com/track',
        };
    }
}
