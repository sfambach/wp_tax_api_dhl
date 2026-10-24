<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Api;

/**
 * Status, body and correlation id of an answer; transport failures are exceptions, not responses.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $correlationId = '',
    ) {
    }

    /** @return array<string, mixed> `mixed` because the shape differs between a result and a problem detail */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
