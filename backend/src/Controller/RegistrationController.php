<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\FeatureFlags;
use App\Service\LocaleResolver;
use App\Service\SubscriptionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RegistrationController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly UserRepository $users,
        private readonly ValidatorInterface $validator,
        private readonly SubscriptionManager $subscriptions,
        private readonly TranslatorInterface $translator,
        private readonly LocaleResolver $locales,
        private readonly FeatureFlags $flags,
    ) {
    }

    #[Route('/api/register', name: 'register', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // No real accounts in demo mode — visitors use the anonymous demo session.
        if ($this->flags->isDemoMode()) {
            return new JsonResponse(['error' => $this->translator->trans('registration.demo_disabled')], 403);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => $this->translator->trans('registration.invalid_json')], 400);
        }

        $email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        $password = is_string($payload['password'] ?? null) ? $payload['password'] : '';
        // The new account's language: an explicit choice from the client, else
        // the browser's Accept-Language (the locale they were browsing in).
        $locale = is_string($payload['locale'] ?? null)
            ? $this->locales->normalize($payload['locale'])
            : $this->locales->fromAcceptLanguage($request);

        if ('' === $email || strlen($password) < 8) {
            return new JsonResponse(['error' => $this->translator->trans('registration.invalid_input')], 400);
        }

        if (null !== $this->users->findOneBy(['email' => $email])) {
            return new JsonResponse(['error' => $this->translator->trans('registration.email_taken')], 409);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setLocale($locale);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $violations = $this->validator->validate($user);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $v) {
                $errors[$v->getPropertyPath()] = (string) $v->getMessage();
            }

            return new JsonResponse([
                'error' => $this->translator->trans('registration.validation_failed'),
                'violations' => $errors,
            ], 422);
        }

        $this->em->persist($user);
        // New account → 14-day free trial, starting now (CLAUDE.md rule 14).
        $this->em->persist($this->subscriptions->newTrial($user));
        $this->em->flush();

        return new JsonResponse([
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
        ], 201);
    }
}
