<?php declare(strict_types=1);

namespace TaxApiDhl\WordPress;

use Taxmod\Core\Import\SecretSlot;
use Taxmod\WordPress\Import\Vault;
use TaxApiDhl\Core\Internetmarke\PortokasseAccess;

/**
 * Client id and secret of the DHL app plus the Portokasse login for the Internetmarke API. Secret and password are
 * sealed in the modeller's vault (D-951); an empty client id falls back to the tracking key, the same app's key.
 */
final class StoredPortokasse
{
    public const OPTION        = 'taxmod_dhl_portokasse';
    public const VAULT_CONTEXT = 'dhl-portokasse';

    public function __construct(private readonly StoredAccess $tracking = new StoredAccess())
    {
    }

    public function access(): ?PortokasseAccess
    {
        $stored = self::option();

        if (! StoredAccess::vaultAvailable()) {
            return null;
        }

        $access = new PortokasseAccess(
            $stored['client_id'] !== '' ? $stored['client_id'] : (string) $this->tracking->apiKey(),
            $this->open($stored['client_secret']),
            $stored['username'],
            $this->open($stored['password'])
        );

        return $access->isComplete() ? $access : null;
    }

    /** @return array{client_id: string, username: string, has_secret: bool, has_password: bool} what the form may show again */
    public function shown(): array
    {
        $stored = self::option();

        return [
            'client_id'    => $stored['client_id'],
            'username'     => $stored['username'],
            'has_secret'   => $stored['client_secret'] !== '',
            'has_password' => $stored['password'] !== '',
        ];
    }

    /** Empty secret or password keep the stored ones. Caller has checked capability and nonce and sanitized. */
    public function save(string $clientId, #[\SensitiveParameter] string $clientSecret, string $username, #[\SensitiveParameter] string $password): void
    {
        if (! StoredAccess::vaultAvailable()) {
            return;
        }

        $stored = self::option();
        $vault = Vault::for(self::VAULT_CONTEXT);
        update_option(self::OPTION, [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret === '' ? $stored['client_secret'] : $vault->seal($clientSecret),
            'username'      => $username,
            'password'      => $password === '' ? $stored['password'] : $vault->seal($password),
        ], false);
    }

    public function forget(): void
    {
        delete_option(self::OPTION);
    }

    private function open(string $sealed): string
    {
        // D-1409: opening and «does not open» live once, in the modeller's SecretSlot.
        return (new SecretSlot('', $sealed, Vault::for(self::VAULT_CONTEXT)))->value() ?? '';
    }

    /** @return array{client_id: string, client_secret: string, username: string, password: string} */
    private static function option(): array
    {
        $value = get_option(self::OPTION);
        $value = is_array($value) ? $value : [];
        $text = static fn (string $name): string => is_string($value[$name] ?? null) ? $value[$name] : '';

        return ['client_id' => $text('client_id'), 'client_secret' => $text('client_secret'), 'username' => $text('username'), 'password' => $text('password')];
    }
}
