<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Tracking;

/**
 * One scan or status change of a shipment, as DHL reports it.
 */
final class Event
{
    public function __construct(
        public readonly string $timestamp,
        public readonly string $statusCode,
        public readonly string $status,
        public readonly string $description,
        public readonly string $locality,
    ) {
    }

    /** @param array<string, mixed> $data `mixed` because DHL leaves fields out freely */
    public static function fromApi(array $data): self
    {
        $text = static fn (mixed $value): string => is_string($value) ? trim($value) : '';
        $address = is_array($data['location']['address'] ?? null) ? $data['location']['address'] : [];

        return new self(
            $text($data['timestamp'] ?? null),
            $text($data['statusCode'] ?? null),
            $text($data['status'] ?? null),
            $text($data['description'] ?? null),
            $text($address['addressLocality'] ?? null),
        );
    }
}
