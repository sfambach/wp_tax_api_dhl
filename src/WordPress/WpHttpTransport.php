<?php declare(strict_types=1);

namespace TaxApiDhl\WordPress;

use TaxApiDhl\Core\Api\DhlApiException;
use TaxApiDhl\Core\Api\HttpRequest;
use TaxApiDhl\Core\Api\HttpResponse;
use TaxApiDhl\Core\Api\HttpTransport;

/**
 * The core's HTTP port over `wp_remote_request`.
 */
final class WpHttpTransport implements HttpTransport
{
    public function send(HttpRequest $request): HttpResponse
    {
        $answer = wp_remote_request($request->url, [
            'method'  => $request->method,
            'headers' => $request->headers,
            'body'    => $request->body === '' ? null : $request->body,
            'timeout' => 20,
        ]);

        if (is_wp_error($answer)) {
            // Said apart from a refused key: a host that blocks outgoing calls makes applying for a key pointless.
            throw new DhlApiException('The web server cannot reach DHL (' . $answer->get_error_message() . ').');
        }

        return new HttpResponse(
            (int) wp_remote_retrieve_response_code($answer),
            (string) wp_remote_retrieve_body($answer),
            (string) wp_remote_retrieve_header($answer, 'correlation-id')
        );
    }
}
