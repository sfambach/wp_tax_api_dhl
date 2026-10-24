<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Internetmarke;

/**
 * One print format of the Internetmarke catalog: plain page, envelope, label sheet or label printer, with its size.
 */
final class PageFormat
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $pageType,
        public readonly float $widthMm,
        public readonly float $heightMm,
        public readonly bool $addressPossible,
    ) {
    }

    /** @param array<string, mixed> $data `mixed` because the catalog leaves fields out freely */
    public static function fromApi(array $data): self
    {
        $size = is_array($data['pageLayout']['size'] ?? null) ? $data['pageLayout']['size'] : [];

        return new self(
            is_numeric($data['id'] ?? null) ? (int) $data['id'] : 0,
            is_string($data['name'] ?? null) ? trim($data['name']) : '',
            is_string($data['pageType'] ?? null) ? $data['pageType'] : '',
            is_numeric($size['x'] ?? null) ? (float) $size['x'] : 0.0,
            is_numeric($size['y'] ?? null) ? (float) $size['y'] : 0.0,
            ($data['isAddressPossible'] ?? false) === true,
        );
    }

    public function isLabelPrinter(): bool
    {
        return $this->pageType === 'LABELPRINTER';
    }
}
