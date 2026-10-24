<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Internetmarke;

/**
 * A bearer token of the Internetmarke API and the Portokasse balance it was issued with.
 */
final class Session
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $token,
        public readonly int $balanceCents,
        public readonly string $user,
        public readonly string $notice,
    ) {
    }

    public function __debugInfo(): array
    {
        return ['token' => '***', 'balanceCents' => $this->balanceCents, 'user' => $this->user, 'notice' => $this->notice];
    }
}
