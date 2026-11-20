<?php declare(strict_types=1);

namespace TaxApiDhl\Core\OnlineFranking;

/**
 * DHL's product catalog of the Online Frankierung, read once, asked per parcel: which products fit it and what each
 * costs to its destination. Services (insurance, cash on delivery …) are left out; a rate is the bare product.
 *
 * ```mermaid
 * flowchart LR
 *   P[Parcel] --> F{fits limits?}
 *   F -- no --> X[left out]
 *   F -- yes --> R{price region with the country?}
 *   R -- no / unavailable --> X
 *   R -- yes --> Rate
 * ```
 *
 * @see https://github.com/sfambach/wp_tax_shipping/blob/main/docs/konzept.md
 */
final class ProductCatalog
{
    /** @param array<string, array<string, mixed>> $products `mixed` because DHL's catalog nests freely */
    private function __construct(private readonly array $products)
    {
    }

    /** @param array<string, mixed> $body the JSON of GET /catalog/current/products */
    public static function fromApi(array $body): self
    {
        $products = is_array($body['products'] ?? null) ? array_filter($body['products'], 'is_array') : [];

        return new self($products);
    }

    /** @return list<Rate> cheapest first */
    public function ratesFor(Parcel $parcel): array
    {
        $rates = [];

        foreach ($this->products as $id => $product) {
            $attributes = is_array($product['attributes'] ?? null) ? $product['attributes'] : [];

            if (! self::fits($attributes, $parcel)) {
                continue;
            }

            $price = self::priceFor(is_array($product['regions'] ?? null) ? $product['regions'] : [], $parcel->countryIso3);

            if ($price === null) {
                continue;
            }

            $rates[] = new Rate(
                (string) $id,
                self::label($attributes['displayName'] ?? null, (string) $id),
                $price,
                (bool) ($attributes['tracking'] ?? false),
                is_numeric($attributes['liabilityLimit'] ?? null) ? (int) $attributes['liabilityLimit'] : 0,
            );
        }

        usort($rates, static fn (Rate $a, Rate $b): int => [$a->priceCents, $a->productId] <=> [$b->priceCents, $b->productId]);

        return $rates;
    }

    /** @param array<string, mixed> $limits */
    private static function fits(array $limits, Parcel $parcel): bool
    {
        [$length, $width, $height] = $parcel->edges();
        $checks = [
            ['minWeight', 'maxWeight', $parcel->weightGrams],
            ['minLength', 'maxLength', $length],
            ['minWidth', 'maxWidth', $width],
            ['minDepth', 'maxDepth', $height],
            ['minGurtmass', 'maxGurtmass', $parcel->girthCm()],
        ];

        foreach ($checks as [$min, $max, $value]) {
            if (is_numeric($limits[$min] ?? null) && $value < (int) $limits[$min]) {
                return false;
            }

            if (is_numeric($limits[$max] ?? null) && $value > (int) $limits[$max]) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int|string, mixed> $regions */
    private static function priceFor(array $regions, string $country): ?int
    {
        foreach ($regions as $region) {
            if (! is_array($region) || ! in_array($country, is_array($region['countries'] ?? null) ? $region['countries'] : [], true)) {
                continue;
            }

            if (($region['unavailable'] ?? false) === true || ! is_numeric($region['price']['amount'] ?? null)) {
                return null;
            }

            return (int) round(((float) $region['price']['amount']) * 100);
        }

        return null;
    }

    private static function label(mixed $label, string $fallback): string
    {
        return is_array($label) && is_string($label['text'] ?? null) && $label['text'] !== '' ? $label['text'] : $fallback;
    }
}
