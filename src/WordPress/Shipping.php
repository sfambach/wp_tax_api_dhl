<?php declare(strict_types=1);

namespace TaxApiDhl\WordPress;

use TaxApiDhl\Core\Api\DhlApiException;
use TaxApiDhl\Core\OnlineFranking\OnlineFrankingClient;
use TaxApiDhl\Core\OnlineFranking\ProductCatalog;
use TaxApiDhl\Core\OnlineFranking\ShippingAdapter;

/**
 * Hooks DHL's Online Frankierung into the shipping module's filters (D-1496). The catalog is cached for a day; a cart
 * is only created when the shipping module asks after the owner's click, and it costs nothing until paid on DHL's page.
 */
final class Shipping
{
    public const RATES  = 'taxmod_shipping_rates';
    public const BUY    = 'taxmod_shipping_buy';
    public const STATUS = 'taxmod_shipping_status';
    public const LABEL  = 'taxmod_shipping_label';
    public const CARRIERS = 'taxmod_shipping_carriers';

    private const CATALOG_CACHE = 'taxmod_dhl_of_catalog';

    public static function register(): void
    {
        add_filter(self::CARRIERS, static fn (mixed $carriers): array => (is_array($carriers) ? $carriers : []) + [ShippingAdapter::CARRIER => 'DHL']);
        add_filter(self::RATES, static fn (mixed $rates, mixed $parcel = []): array => self::adapter() === null
            ? (is_array($rates) ? $rates : [])
            : self::adapter()->rates(is_array($rates) ? array_values($rates) : [], is_array($parcel) ? $parcel : []), 10, 2);
        add_filter(self::BUY, static fn (mixed $previous, mixed $rate = [], mixed $order = []): ?array => self::adapter() === null || ! is_array($rate) || ! is_array($order)
            ? (is_array($previous) ? $previous : null)
            : self::adapter()->buy(is_array($previous) ? $previous : null, $rate, $order), 10, 3);
        add_filter(self::STATUS, static fn (mixed $previous, mixed $purchase = []): ?array => self::adapter() === null || ! is_array($purchase)
            ? (is_array($previous) ? $previous : null)
            : self::adapter()->status(is_array($previous) ? $previous : null, $purchase), 10, 2);
        add_filter(self::LABEL, static function (mixed $previous, mixed $carrier = '', mixed $number = ''): mixed {
            if (self::adapter() === null || ! is_string($carrier) || ! is_string($number)) {
                return $previous;
            }

            try {
                return self::adapter()->label(is_string($previous) ? $previous : null, $carrier, $number);
            } catch (DhlApiException $failure) {
                return new \WP_Error('taxapidhl_label', $failure->getMessage());
            }
        }, 10, 3);
    }

    /** Null without a key: then DHL simply answers nothing. */
    private static function adapter(): ?ShippingAdapter
    {
        static $adapter = false;

        if ($adapter === false) {
            $access = new StoredAccess();
            $key = $access->apiKey();
            $client = $key === null ? null : new OnlineFrankingClient(ApiTransport::make(), $key, $access->environment());
            $adapter = $client === null ? null : new ShippingAdapter($client, static function () use ($client): ProductCatalog {
                $cached = get_transient(self::CATALOG_CACHE);

                if (! is_array($cached)) {
                    $cached = $client->catalogBody();
                    set_transient(self::CATALOG_CACHE, $cached, DAY_IN_SECONDS);
                }

                return ProductCatalog::fromApi($cached);
            });
        }

        return $adapter;
    }
}
