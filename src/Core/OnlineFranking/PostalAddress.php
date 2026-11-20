<?php declare(strict_types=1);

namespace TaxApiDhl\Core\OnlineFranking;

/**
 * An address as DHL's Online Frankierung takes it. Lengths are DHL's; a longer value is refused here, not cut.
 *
 * @see https://github.com/sfambach/wp_tax_shipping/blob/main/docs/konzept.md
 */
final class PostalAddress
{
    public function __construct(
        public readonly string $name,
        public readonly string $street,
        public readonly string $streetNumber,
        public readonly string $postalCode,
        public readonly string $city,
        public readonly string $countryIso3 = 'DEU',
        public readonly string $email = '',
        public readonly string $addition = '',
    ) {
        foreach (['name' => [$name, 35], 'street' => [$street, 35], 'street number' => [$streetNumber, 10], 'postal code' => [$postalCode, 17], 'city' => [$city, 35], 'addition' => [$addition, 35]] as $field => [$value, $max]) {
            if (mb_strlen($value) > $max) {
                throw new \InvalidArgumentException(sprintf('The %s is longer than DHL takes (%d characters).', $field, $max));
            }
        }

        if ($name === '' || $street === '' || $postalCode === '' || $city === '' || strlen($countryIso3) !== 3) {
            throw new \InvalidArgumentException('An address needs name, street, postal code, city and a three-letter country.');
        }
    }

    /** @return array<string, string> */
    public function toApi(): array
    {
        return array_filter([
            'name2'        => $this->name,
            'name3'        => $this->addition,
            'street'       => $this->street,
            'streetNumber' => $this->streetNumber,
            'plz'          => $this->postalCode,
            'city'         => $this->city,
            'country'      => $this->countryIso3,
            'email'        => $this->email,
        ], static fn (string $value): bool => $value !== '');
    }
}
