<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CsrfProtectionTest extends WebTestCase
{
    use AdminClientTrait;

    public function testChangeWithoutTokenIsRefused(): void
    {
        $client = $this->createAdminClient();
        $client->setServerParameter('HTTP_X_CSRF_TOKEN', '');

        $client->request('POST', '/admin/libraries/add', ['name' => 'X', 'path' => '/tmp']);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testChangeWithWrongTokenIsRefused(): void
    {
        $client = $this->createAdminClient();
        $client->setServerParameter('HTTP_X_CSRF_TOKEN', 'not-the-token');

        $client->request('POST', '/admin/libraries/add', ['name' => 'X', 'path' => '/tmp']);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testTokenInFormFieldIsAccepted(): void
    {
        $client = $this->createAdminClient();
        $client->setServerParameter('HTTP_X_CSRF_TOKEN', '');
        $crawler = $client->request('GET', '/admin/settings');
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/admin/settings', ['_token' => $token]);

        $this->assertResponseRedirects('/admin/settings');
    }

    public function testCrossSiteRequestIsRefusedEvenWithAValidToken(): void
    {
        $client = $this->createAdminClient();
        $client->setServerParameter('HTTP_ORIGIN', 'https://evil.example');

        $client->request('POST', '/admin/settings', []);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSignOutNeedsTheToken(): void
    {
        $client = $this->createAdminClient();
        $client->setServerParameter('HTTP_X_CSRF_TOKEN', '');

        $client->request('POST', '/logout');

        $this->assertResponseStatusCodeSame(403);
        $client->request('GET', '/admin/libraries');
        $this->assertResponseIsSuccessful();
    }

    public function testReadsStayOpenToTheSession(): void
    {
        $client = $this->createAdminClient();
        $client->setServerParameter('HTTP_X_CSRF_TOKEN', '');

        $client->request('GET', '/admin/libraries');

        $this->assertResponseIsSuccessful();
    }
}
