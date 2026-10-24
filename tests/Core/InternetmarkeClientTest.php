<?php declare(strict_types=1);

namespace TaxApiDhl\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxApiDhl\Core\Api\DhlApiException;
use TaxApiDhl\Core\Api\HttpResponse;
use TaxApiDhl\Core\Internetmarke\InternetmarkeClient;
use TaxApiDhl\Core\Internetmarke\PortokasseAccess;

final class InternetmarkeClientTest extends TestCase
{
    public function testTokenRequestIsAFormPostWithAllFiveFields(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(200, (string) json_encode([
            'access_token' => 'tok', 'walletBalance' => 2446814, 'infoMessage' => '', 'authenticated_user' => 'max@example.org',
        ]))]);

        $session = (new InternetmarkeClient($transport))->session(self::access());

        $request = $transport->sent[0];
        self::assertSame('POST', $request->method);
        self::assertSame(InternetmarkeClient::BASE_URL . '/user', $request->url);
        self::assertSame('application/x-www-form-urlencoded', $request->headers['Content-Type']);
        parse_str($request->body, $form);
        self::assertSame(['grant_type' => 'client_credentials', 'client_id' => 'id', 'client_secret' => 'secret', 'username' => 'max@example.org', 'password' => 'pw#1'], $form);
        self::assertSame('tok', $session->token);
        self::assertSame(2446814, $session->balanceCents);
    }

    public function testPageFormatsComeLabelPrintersFirst(): void
    {
        $transport = new ScriptedTransport([
            new HttpResponse(200, '{"access_token":"tok","walletBalance":0}'),
            new HttpResponse(200, (string) json_encode(['pageFormats' => [
                ['id' => 1, 'name' => 'DIN A4 Normalpapier', 'pageType' => 'REGULARPAGE', 'isAddressPossible' => true, 'pageLayout' => ['size' => ['x' => 210, 'y' => 297]]],
                ['id' => 42, 'name' => 'Etikettendrucker 62 mm', 'pageType' => 'LABELPRINTER', 'isAddressPossible' => true, 'pageLayout' => ['size' => ['x' => 62, 'y' => 100]]],
            ]])),
        ]);
        $client = new InternetmarkeClient($transport);

        $formats = $client->pageFormats($client->session(self::access()));

        self::assertSame('Bearer tok', $transport->sent[1]->headers['Authorization']);
        self::assertStringEndsWith('/app/catalog?types=PAGE_FORMATS', $transport->sent[1]->url);
        self::assertSame([42, 1], array_map(static fn ($format): int => $format->id, $formats));
        self::assertSame(62.0, $formats[0]->widthMm);
    }

    public function testRefusedLoginNamesTheApproval(): void
    {
        $client = new InternetmarkeClient(new ScriptedTransport([new HttpResponse(401, '{"statusCode":"401","title":"Unauthorized"}')]));

        $this->expectException(DhlApiException::class);
        $this->expectExceptionMessage('not yet approved for this Portokasse');
        $client->session(self::access());
    }

    private static function access(): PortokasseAccess
    {
        return new PortokasseAccess('id', 'secret', 'max@example.org', 'pw#1');
    }
}
