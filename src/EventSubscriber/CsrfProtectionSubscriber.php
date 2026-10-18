<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Every request that changes data must carry the CSRF token of the session, either in the
 * X-CSRF-Token header (scripts) or in a _token form field, and must not come from another site.
 *
 * This is a second layer on top of the SameSite cookie: it also protects browsers that send
 * cookies on cross-site requests. Sign-in and setup keep their own protection.
 */
class CsrfProtectionSubscriber implements EventSubscriberInterface
{
    public const TOKEN_ID = 'submit';
    public const HEADER = 'X-CSRF-Token';

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];
    private const EXEMPT_ROUTES = ['app_login', 'app_setup'];

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Before the firewall (priority 8), so that sign-out is protected as well.
        return [KernelEvents::REQUEST => ['onRequest', 9]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (\in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (!\is_string($route) || str_starts_with($route, '_') || \in_array($route, self::EXEMPT_ROUTES, true)) {
            return;
        }

        if (!$this->comesFromThisSite($request)) {
            throw new AccessDeniedHttpException('Cross-site request refused.');
        }

        // An empty header counts as absent: the token may then come from the form field.
        $token = (string) ($request->headers->get(self::HEADER) ?: $request->request->get('_token', ''));
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::TOKEN_ID, $token))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    /** A request without an Origin header (scripts, older browsers) is checked by its token only. */
    private function comesFromThisSite(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        if (null === $origin || '' === $origin) {
            return true;
        }

        return 'null' !== $origin && $origin === $request->getSchemeAndHttpHost();
    }
}
