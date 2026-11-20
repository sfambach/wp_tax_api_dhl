<?php declare(strict_types=1);

namespace TaxApiDhl\Core\OnlineFranking;

/**
 * What a rate depends on: weight in grams, the three edges in centimetres and the destination as ISO 3166 alpha-3.
 *
 * @see https://github.com/sfambach/wp_tax_shipping/blob/main/docs/konzept.md
 */
final class Parcel
{
    public function __construct(
        public readonly int $weightGrams,
        public readonly int $lengthCm,
        public readonly int $widthCm,
        public readonly int $heightCm,
        public readonly string $countryIso3 = 'DEU',
    ) {
        if ($weightGrams <= 0) {
            throw new \InvalidArgumentException('A parcel needs a weight above 0 g.');
        }
    }

    /** Edges sorted longest first, so a box turned on its side fits the same products. @return array{int, int, int} */
    public function edges(): array
    {
        $edges = [$this->lengthCm, $this->widthCm, $this->heightCm];
        rsort($edges);

        return [$edges[0], $edges[1], $edges[2]];
    }

    /** Length plus twice width plus twice height, DHL's «Gurtmaß». */
    public function girthCm(): int
    {
        [$length, $width, $height] = $this->edges();

        return $length + 2 * $width + 2 * $height;
    }
}
