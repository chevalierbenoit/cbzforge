<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AuthControllerTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    public function testWithoutAccountEveryPageGoesToSetup(): void
    {
        $client = static::createClient();
        $this->clearAccounts($client);

        $client->request('GET', '/');

        $this->assertResponseRedirects('/setup');
    }

    public function testSetupCreatesTheAdministratorThenCloses(): void
    {
        $client = static::createClient();
        $this->clearAccounts($client);

        $crawler = $client->request('GET', '/setup');
        $this->assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Create the account')->form([
            'form[username]' => 'benoit',
            'form[password][first]' => self::PASSWORD,
            'form[password][second]' => self::PASSWORD,
        ]));

        $this->assertResponseRedirects('/login');
        $user = $this->users($client);
        $this->assertCount(1, $user);
        $this->assertNotSame(self::PASSWORD, $user[0]->getPassword(), 'the password is stored hashed');

        $client->request('GET', '/setup');
        $this->assertResponseRedirects('/login');
    }

    public function testSetupRejectsDifferentPasswords(): void
    {
        $client = static::createClient();
        $this->clearAccounts($client);

        $crawler = $client->request('GET', '/setup');
        $client->submit($crawler->selectButton('Create the account')->form([
            'form[username]' => 'benoit',
            'form[password][first]' => self::PASSWORD,
            'form[password][second]' => 'something-else-entirely',
        ]));

        $this->assertResponseIsUnprocessable();
        $this->assertCount(0, $this->users($client));
    }

    public function testSignInWithCorrectPasswordReachesDashboard(): void
    {
        $client = static::createClient();
        $this->clearAccounts($client);
        $this->createAccount($client, 'benoit');

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'benoit',
            '_password' => self::PASSWORD,
        ]));
        $this->assertResponseRedirects('/');

        $client->followRedirect();
        $this->assertResponseRedirects('/onboarding');
    }

    public function testWrongPasswordIsRefused(): void
    {
        $client = static::createClient();
        $this->clearAccounts($client);
        $this->createAccount($client, 'benoit');

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'benoit',
            '_password' => 'wrong-password-here',
        ]));

        $this->assertResponseRedirects('/login');
        $client->followRedirect();
        $this->assertSelectorExists('.flash-error');
    }

    public function testPagesRequireSignIn(): void
    {
        $client = static::createClient();
        $this->clearAccounts($client);
        $this->createAccount($client, 'benoit');

        $client->request('GET', '/admin/libraries');

        $this->assertResponseRedirects('/login');
    }

    public function testFirstLoginSendsToOnboardingUntilItIsDone(): void
    {
        $client = static::createClient();
        $this->clearAccounts($client);
        $this->createAccount($client, 'benoit');
        $this->setSetting($client, 'onboarding.done', null);

        $client->loginUser($this->users($client)[0]);
        $client->request('GET', '/');

        $this->assertResponseRedirects('/onboarding');
    }

    public function testSkippingOnboardingOpensTheDashboard(): void
    {
        $client = static::createClient();
        $this->clearAccounts($client);
        $this->createAccount($client, 'benoit');
        $this->setSetting($client, 'onboarding.done', null);
        $client->loginUser($this->users($client)[0]);

        $crawler = $client->request('GET', '/onboarding/sources');
        $client->submit($crawler->selectButton('Skip for now')->form());

        $this->assertResponseRedirects('/');
    }

    private function createAccount(KernelBrowser $client, string $username): void
    {
        $container = $client->getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $user = new User();
        $user->setUsername($username);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em->persist($user);
        $em->flush();
    }

    private function clearAccounts(KernelBrowser $client): void
    {
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        foreach ($em->getRepository(User::class)->findAll() as $user) {
            $em->remove($user);
        }
        $em->flush();
    }

    /** @return User[] */
    private function users(KernelBrowser $client): array
    {
        return $client->getContainer()->get('doctrine.orm.entity_manager')->getRepository(User::class)->findAll();
    }

    private function setSetting(KernelBrowser $client, string $key, ?string $value): void
    {
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $setting = $em->getRepository(Setting::class)->find($key);
        if (null === $value) {
            if (null !== $setting) {
                $em->remove($setting);
            }
        } else {
            $setting ??= (new Setting())->setKey($key);
            $setting->setValue($value);
            $em->persist($setting);
        }
        $em->flush();
    }
}
