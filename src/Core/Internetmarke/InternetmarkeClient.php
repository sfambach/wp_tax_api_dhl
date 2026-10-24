<?php declare(strict_types=1);

namespace TaxApiDhl\Core\Internetmarke;

use TaxApiDhl\Core\Api\DhlApiException;
use TaxApiDhl\Core\Api\HttpRequest;
use TaxApiDhl\Core\Api\HttpResponse;
use TaxApiDhl\Core\Api\HttpTransport;

/**
 * The read-only part of the Internetmarke REST API: token with balance, and the catalog of print formats. It buys
 * nothing; a purchase debits the Portokasse at once and belongs to a later step with its own confirmation.
 *
 * @see https://developer.dhl.com/api-reference/deutsche-post-internetmarke-post-parcel-germany
 */
final class InternetmarkeClient
{
    /** Production only: a developer Portokasse runs against the same host and prints «Muster». */
    public const BASE_URL = 'https://api-eu.dhl.com/post/de/shipping/im/v1';

    public function __construct(private readonly HttpTransport $transport, private readonly string $baseUrl = self::BASE_URL)
    {
    }

    public function session(PortokasseAccess $access): Session
    {
        $response = $this->transport->send(new HttpRequest('POST', $this->baseUrl . '/user', [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept'       => 'application/json',
        ], http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $access->clientId,
            'client_secret' => $access->clientSecret,
            'username'      => $access->username,
            'password'      => $access->password,
        ])));
        $body = self::ok($response);
        $token = is_string($body['access_token'] ?? null) ? $body['access_token'] : '';

        if ($token === '') {
            throw new DhlApiException('Internetmarke answered without a token.');
        }

        return new Session(
            $token,
            is_numeric($body['walletBalance'] ?? null) ? (int) $body['walletBalance'] : 0,
            is_string($body['authenticated_user'] ?? null) ? $body['authenticated_user'] : '',
            is_string($body['infoMessage'] ?? null) ? $body['infoMessage'] : '',
        );
    }

    /** @return list<PageFormat> label printer formats first */
    public function pageFormats(Session $session): array
    {
        $body = self::ok($this->transport->send(new HttpRequest('GET', $this->baseUrl . '/app/catalog?types=PAGE_FORMATS', [
            'Authorization' => 'Bearer ' . $session->token,
            'Accept'        => 'application/json',
        ])));
        $formats = array_values(array_map(
            static fn (array $format): PageFormat => PageFormat::fromApi($format),
            array_filter(is_array($body['pageFormats'] ?? null) ? $body['pageFormats'] : [], 'is_array')
        ));
        usort($formats, static fn (PageFormat $a, PageFormat $b): int => [$b->isLabelPrinter(), $a->name] <=> [$a->isLabelPrinter(), $b->name]);

        return $formats;
    }

    /** @return array<string, mixed> `mixed` because the shape differs by call */
    private static function ok(HttpResponse $response): array
    {
        if ($response->status === 200) {
            return $response->json();
        }

        $body = $response->json();
        $detail = is_string($body['detail'] ?? null) ? $body['detail'] : (is_string($body['title'] ?? null) ? $body['title'] : '');
        $text = match ($response->status) {
            401     => 'Internetmarke refused the login (wrong data, or the app is not yet approved for this Portokasse)',
            429     => 'Too many Internetmarke requests',
            default => 'Internetmarke answered HTTP ' . $response->status,
        };

        throw new DhlApiException($detail === '' ? $text . '.' : $text . ': ' . $detail);
    }
}
