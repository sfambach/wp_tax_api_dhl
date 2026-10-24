<?php declare(strict_types=1);

namespace TaxApiDhl\WordPress;

use Taxmod\Core\Exception\SecretDoesNotOpen;
use Taxmod\WordPress\Import\Vault;
use TaxApiDhl\Core\Api\Environment;

/**
 * The API key of the «DHL» tab, sealed in the modeller's vault (D-951), and the server it belongs to. Constants in
 * wp-config.php go first; without the vault there is no form, only the constants.
 */
final class StoredAccess
{
    public const OPTION        = 'taxmod_dhl_access';
    public const VAULT_CONTEXT = 'dhl';
    public const KEY_CONSTANT  = 'TAXDHL_API_KEY';
    public const ENV_CONSTANT  = 'TAXDHL_ENVIRONMENT';

    public static function vaultAvailable(): bool
    {
        return class_exists(Vault::class) && Vault::API >= 1;
    }

    public function fromConstants(): bool
    {
        return self::constant(self::KEY_CONSTANT) !== '';
    }

    public function apiKey(): ?string
    {
        $constant = self::constant(self::KEY_CONSTANT);

        if ($constant !== '') {
            return $constant;
        }

        $sealed = self::option()['key'];

        return $sealed === '' || ! self::vaultAvailable() ? null : $this->open($sealed);
    }

    public function environment(): Environment
    {
        $chosen = $this->fromConstants() ? self::constant(self::ENV_CONSTANT) : self::option()['environment'];

        return Environment::tryFrom($chosen) ?? Environment::Sandbox;
    }

    public function hasStoredKey(): bool
    {
        return self::option()['key'] !== '';
    }

    /** An empty key keeps the stored one. Caller has checked capability and nonce and sanitized. */
    public function save(Environment $environment, #[\SensitiveParameter] string $key): void
    {
        if (! self::vaultAvailable()) {
            return;
        }

        $stored = self::option();
        update_option(self::OPTION, [
            'environment' => $environment->value,
            'key'         => $key === '' ? $stored['key'] : Vault::for(self::VAULT_CONTEXT)->seal($key),
        ], false);
    }

    /** Deletes the stored key; constants in wp-config.php stay, they are not this page's to delete. */
    public function forget(): void
    {
        delete_option(self::OPTION);
    }

    /** true when a key is stored but no longer opens — the salts in wp-config.php changed */
    public function keyBroken(): bool
    {
        $sealed = self::option()['key'];

        return $sealed !== '' && self::vaultAvailable() && $this->open($sealed) === null;
    }

    private function open(string $sealed): ?string
    {
        try {
            $key = Vault::for(self::VAULT_CONTEXT)->open($sealed);

            return $key === '' ? null : $key;
        } catch (SecretDoesNotOpen) {
            return null;
        }
    }

    /** @return array{environment: string, key: string} */
    private static function option(): array
    {
        $value = get_option(self::OPTION);
        $value = is_array($value) ? $value : [];
        $text = static fn (string $name): string => is_string($value[$name] ?? null) ? $value[$name] : '';

        return ['environment' => $text('environment'), 'key' => $text('key')];
    }

    private static function constant(string $name): string
    {
        return defined($name) && is_string(constant($name)) ? trim(constant($name)) : '';
    }
}
