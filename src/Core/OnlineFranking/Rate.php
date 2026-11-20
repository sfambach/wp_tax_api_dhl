<?php declare(strict_types=1);

namespace TaxApiDhl\Core\OnlineFranking;

/**
 * One DHL product that fits a parcel, with DHL's gross price for its destination.
 *
 * @see https://github.com/sfambach/wp_tax_shipping/blob/main/docs/konzept.md
 */
final class Rate
{
    public function __construct(
        public readonly string $productId,
        public readonly string $name,
        public readonly int $priceCents,
        public readonly bool $tracked,
        public readonly int $liabilityEuro,
    ) {
    }
}
