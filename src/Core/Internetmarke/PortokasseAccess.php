<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Internetmarke;

/**
 * What the Internetmarke API needs for a token: the app's client id and secret from the DHL developer portal and the
 * login of a Portokasse (a developer Portokasse for tests).
 *
 * @see https://developer.dhl.com/api-reference/deutsche-post-internetmarke-post-parcel-germany
 */
final class PortokasseAccess
{
    public function __construct(
        public readonly string $clientId,
        #[\SensitiveParameter] public readonly string $clientSecret,
        public readonly string $username,
        #[\SensitiveParameter] public readonly string $password,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->username !== '' && $this->password !== '';
    }

    /** Keeps the secrets out of dumps and logs. */
    public function __debugInfo(): array
    {
        return ['clientId' => $this->clientId, 'clientSecret' => '***', 'username' => $this->username, 'password' => '***'];
    }
}
