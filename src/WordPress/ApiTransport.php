<?php declare(strict_types=1);

namespace TaxApiDhl\WordPress;

use TaxApiDhl\Core\Api\DhlApiException;
use Taxmod\Core\Http\HttpTransport;
use Taxmod\WordPress\Http\WpHttpTransport;

/** This module's way to the web: the modeller's one HTTP block (D-1415) with this module's failure and trace header. */
final class ApiTransport
{
    public static function make(): HttpTransport
    {
        return new WpHttpTransport(static fn (string $why): \Throwable => new DhlApiException('The web server cannot reach DHL (' . $why . ').'), 'correlation-id');
    }
}
