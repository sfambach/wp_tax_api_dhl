<?php declare(strict_types=1);

namespace TaxApiDhl\Core\OnlineFranking;

use TaxApiDhl\Core\Api\DhlApiException;

/**
 * DHL's answer to the shipping module's filters (D-1496): rates for a parcel, an unpaid cart for a chosen rate, the
 * cart's state, the label. It speaks the module's plain arrays so the module needs no DHL class.
 *
 * @see https://github.com/sfambach/wp_tax_shipping/blob/main/docs/konzept.md
 */
final class ShippingAdapter
{
    public const CARRIER = 'dhl';

    /** @param \Closure(): ProductCatalog $catalog cached by the boundary */
    public function __construct(private readonly OnlineFrankingClient $client, private readonly \Closure $catalog)
    {
    }

    /**
     * @param list<array<string, mixed>> $rates what other carriers already answered
     * @param array<string, mixed> $parcel weight_g, length_cm, width_cm, height_cm, country (ISO alpha-3)
     * @return list<array<string, mixed>>
     */
    public function rates(array $rates, array $parcel): array
    {
        try {
            $parcelObject = new Parcel(self::int($parcel, 'weight_g'), self::int($parcel, 'length_cm'), self::int($parcel, 'width_cm'), self::int($parcel, 'height_cm'), self::country($parcel['country'] ?? 'DEU'));

            foreach (($this->catalog)()->ratesFor($parcelObject) as $rate) {
                $rates[] = [
                    'carrier' => self::CARRIER, 'product' => $rate->productId, 'name' => $rate->name, 'price_cents' => $rate->priceCents,
                    'currency' => 'EUR', 'tracked' => $rate->tracked, 'liability_eur' => $rate->liabilityEuro, 'source' => 'api',
                ];
            }
        } catch (DhlApiException | \InvalidArgumentException $failure) {
            $rates[] = ['carrier' => self::CARRIER, 'error' => $failure->getMessage()];
        }

        return $rates;
    }

    /**
     * @param array<string, mixed>|null $previous another carrier's answer
     * @param array<string, mixed> $rate one entry of rates()
     * @param array<string, mixed> $order sender, receiver (address arrays), email
     * @return array<string, mixed>|null
     */
    public function buy(?array $previous, array $rate, array $order): ?array
    {
        if ($previous !== null || ($rate['carrier'] ?? '') !== self::CARRIER) {
            return $previous;
        }

        try {
            $cart = $this->client->createCart(
                (string) ($rate['product'] ?? ''),
                self::address(is_array($order['sender'] ?? null) ? $order['sender'] : []),
                self::address(is_array($order['receiver'] ?? null) ? $order['receiver'] : []),
                is_string($order['email'] ?? null) ? $order['email'] : '',
            );

            return ['carrier' => self::CARRIER, 'reference' => $cart->id, 'state' => 'awaiting_payment', 'pay_url' => $cart->payUrl, 'price_cents' => $cart->totalCents];
        } catch (DhlApiException | \InvalidArgumentException $failure) {
            return ['carrier' => self::CARRIER, 'error' => $failure->getMessage()];
        }
    }

    /**
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $purchase carrier, reference
     * @return array<string, mixed>|null state paid|awaiting_payment, numbers
     */
    public function status(?array $previous, array $purchase): ?array
    {
        if ($previous !== null || ($purchase['carrier'] ?? '') !== self::CARRIER || ! is_string($purchase['reference'] ?? null)) {
            return $previous;
        }

        try {
            $cart = $this->client->cart($purchase['reference']);

            return ['carrier' => self::CARRIER, 'state' => $cart->isPaid() ? 'paid' : 'awaiting_payment', 'numbers' => $cart->shipmentNumbers, 'pay_url' => $cart->payUrl];
        } catch (DhlApiException $failure) {
            return ['carrier' => self::CARRIER, 'error' => $failure->getMessage()];
        }
    }

    /** @throws DhlApiException */
    public function label(?string $previous, string $carrier, string $number): ?string
    {
        return $previous !== null || $carrier !== self::CARRIER ? $previous : $this->client->label($number);
    }

    /** @param array<string, mixed> $address name, street, number, postal_code, city, country, email, addition */
    private static function address(array $address): PostalAddress
    {
        $text = static fn (string $key): string => is_scalar($address[$key] ?? null) ? trim((string) $address[$key]) : '';

        return new PostalAddress($text('name'), $text('street'), $text('number'), $text('postal_code'), $text('city'), self::country($address['country'] ?? 'DEU'), $text('email'), $text('addition'));
    }

    /** @param array<string, mixed> $values */
    private static function int(array $values, string $key): int
    {
        return is_numeric($values[$key] ?? null) ? (int) ceil((float) $values[$key]) : 0;
    }

    private static function country(mixed $code): string
    {
        $code = is_string($code) ? strtoupper(trim($code)) : '';

        return strlen($code) === 2 ? (CountryCodes::ALPHA3[$code] ?? $code) : $code;
    }
}
