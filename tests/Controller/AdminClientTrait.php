<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A client already signed in as the administrator, with the onboarding assistant marked as done.
 * The sign-in itself is covered by AuthControllerTest.
 */
trait AdminClientTrait
{
    protected function createAdminClient(): KernelBrowser
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get('doctrine.orm.entity_manager');

        $user = $em->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        if (null === $user) {
            $user = new User();
            $user->setUsername('test-admin');
            $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'test-password-123'));
            $em->persist($user);
            $em->flush();
        }

        if (null === $em->getRepository(Setting::class)->find('onboarding.done')) {
            $setting = new Setting();
            $setting->setKey('onboarding.done');
            $setting->setValue('1');
            $em->persist($setting);
            $em->flush();
        }

        $client->loginUser($user);

        return $client;
    }
}
