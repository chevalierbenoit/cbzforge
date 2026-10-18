<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Before the firewall: with no account, every page leads to the setup page; once an account
 * exists, the setup page is closed. Runs after routing, so the route name is known.
 */
class SetupRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 9]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!\is_string($route) || str_starts_with($route, '_')) {
            return;
        }

        $hasAccount = $this->em->getRepository(User::class)->count([]) > 0;

        if (!$hasAccount && 'app_setup' !== $route) {
            $event->setResponse(new RedirectResponse($this->url('app_setup')));
        } elseif ($hasAccount && 'app_setup' === $route) {
            $event->setResponse(new RedirectResponse($this->url('app_login')));
        }
    }

    private function url(string $route): string
    {
        return $this->urlGenerator->generate($route, [], UrlGeneratorInterface::ABSOLUTE_PATH);
    }
}
