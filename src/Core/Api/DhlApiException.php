<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Api;

/**
 * DHL did not answer, or answered with an error other than «not found».
 */
final class DhlApiException extends \RuntimeException
{
}
