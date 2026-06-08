<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\LocaleResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The authenticated user's own minimal profile. Used by the frontend i18n layer
 * to read and persist the preferred `locale` (CLAUDE.md i18n). Authenticated via
 * the `api` firewall; never exposes other users' data.
 */
final class MeController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly LocaleResolver $locales,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/me', name: 'me_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $user = $this->currentUser();

        return new JsonResponse([
            'email' => $user->getEmail(),
            'locale' => $user->getLocale(),
        ], headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/me', name: 'me_patch', methods: ['PATCH'])]
    public function patch(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        $payload = json_decode($request->getContent() ?: '{}', true);
        $locale = is_array($payload) && is_string($payload['locale'] ?? null) ? $payload['locale'] : null;
        if (null === $locale || !$this->locales->isSupported($locale)) {
            throw new BadRequestHttpException($this->translator->trans(
                'me.invalid_locale',
                ['%locales%' => implode(', ', LocaleResolver::SUPPORTED)],
            ));
        }

        $user->setLocale($locale);
        $this->em->flush();

        return new JsonResponse([
            'email' => $user->getEmail(),
            'locale' => $user->getLocale(),
        ], headers: ['Cache-Control' => 'private, no-store']);
    }

    private function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }
}
