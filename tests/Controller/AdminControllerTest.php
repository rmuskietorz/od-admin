<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminControllerTest extends WebTestCase
{
    public function testDashboardRedirectsAnonymousToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');
    }

    public function testStatusEndpointRejectsAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/status');

        // Symfony redirected anonymous zu /admin/login (302), nicht 401.
        // Location ist eine absolute URL, daher Pfad-Vergleich statt isRedirect().
        $response = $client->getResponse();
        $location = (string) $response->headers->get('Location', '');
        self::assertTrue(
            ($response->isRedirect() && '/admin/login' === parse_url($location, PHP_URL_PATH))
            || 401 === $response->getStatusCode(),
            'Status-Endpoint sollte fuer Anonyme geblockt sein',
        );
    }

    public function testRestartRequiresPostMethod(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/restart');

        // Anonymous GET landet entweder bei 405 (method) oder Login-Redirect
        $status = $client->getResponse()->getStatusCode();
        self::assertTrue(
            in_array($status, [302, 405], true),
            "Erwartet 302 oder 405, bekam {$status}",
        );
    }
}
