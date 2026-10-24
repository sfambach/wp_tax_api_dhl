<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Tracking;

/**
 * Where a parcel stands for a shipping page, derived from DHL's `statusCode` (pre-transit, transit, delivered,
 * failure, unknown). «unknown» is DHL's word for an event it did not group, so the events decide then.
 */
enum Stage: string
{
    /** Label exists, DHL has not seen the parcel yet. */
    case Announced = 'announced';
    case Shipped   = 'shipped';
    case Delivered = 'delivered';
    /** Delivery failed or the parcel goes back; someone has to look. */
    case Problem   = 'problem';

    public static function of(Shipment $shipment): self
    {
        $stage = self::fromCode($shipment->statusCode);

        if ($stage !== null) {
            return $stage;
        }

        foreach ($shipment->events as $event) {
            $stage = self::fromCode($event->statusCode);

            if ($stage !== null) {
                return $stage;
            }
        }

        return self::Announced;
    }

    private static function fromCode(string $code): ?self
    {
        return match ($code) {
            'pre-transit' => self::Announced,
            'transit'     => self::Shipped,
            'delivered'   => self::Delivered,
            'failure'     => self::Problem,
            default       => null,
        };
    }
}
