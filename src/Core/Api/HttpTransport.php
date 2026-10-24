<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Api;

/**
 * Sends a request. The boundary implements it over WordPress; tests script it.
 */
interface HttpTransport
{
    /** @throws DhlApiException when nothing came back */
    public function send(HttpRequest $request): HttpResponse;
}
