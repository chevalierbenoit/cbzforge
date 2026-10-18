<?php

namespace App\Tests\Controller;

use App\Entity\Library;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardControllerTest extends WebTestCase
{
    public function testEmptyDashboardShowsWelcome(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Welcome to cbzforge');
        $this->assertSame(3, $crawler->filter('.welcome-step')->count());
        $this->assertSame(
            '/admin/libraries',
            $crawler->selectLink('Add my first library')->attr('href'),
        );
    }

    public function testIndexShowsLibrariesAsCards(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test Lib');
        $library->setPath('/tmp/test-lib');
        $em->persist($library);
        $em->flush();

        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Test Lib');
        $this->assertSame(0, $crawler->filter('.welcome')->count());
        // The whole card is the link: no separate browse button.
        $card = $crawler->filter('a.library-card');
        $this->assertSame(1, $card->count());
        $this->assertSame('/library/'.$library->getId(), $card->attr('href'));
        $this->assertStringNotContainsString('Browse', $crawler->filter('main')->text());
    }
}
