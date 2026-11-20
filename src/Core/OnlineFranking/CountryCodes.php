<?php declare(strict_types=1);

namespace TaxApiDhl\Core\OnlineFranking;

/**
 * ISO 3166 alpha-2 to alpha-3 for the countries a private parcel usually goes to; DHL's Online Frankierung wants
 * alpha-3, contacts often hold alpha-2. A code not listed passes unchanged and DHL decides.
 *
 * @see https://github.com/sfambach/wp_tax_shipping/blob/main/docs/konzept.md
 */
final class CountryCodes
{
    public const ALPHA3 = [
        'DE' => 'DEU', 'AT' => 'AUT', 'CH' => 'CHE', 'NL' => 'NLD', 'BE' => 'BEL', 'LU' => 'LUX', 'FR' => 'FRA', 'IT' => 'ITA',
        'ES' => 'ESP', 'PT' => 'PRT', 'DK' => 'DNK', 'SE' => 'SWE', 'NO' => 'NOR', 'FI' => 'FIN', 'PL' => 'POL', 'CZ' => 'CZE',
        'SK' => 'SVK', 'HU' => 'HUN', 'SI' => 'SVN', 'HR' => 'HRV', 'IE' => 'IRL', 'GB' => 'GBR', 'GR' => 'GRC', 'RO' => 'ROU',
        'BG' => 'BGR', 'EE' => 'EST', 'LV' => 'LVA', 'LT' => 'LTU', 'US' => 'USA', 'CA' => 'CAN',
    ];
}
