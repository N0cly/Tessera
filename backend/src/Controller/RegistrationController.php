<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RegistrationController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly UserRepository $users,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/register', name: 'register', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
        }

        $email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        $password = is_string($payload['password'] ?? null) ? $payload['password'] : '';

        if ('' === $email || strlen($password) < 8) {
            return new JsonResponse(['error' => 'Email and password (>= 8 chars) are required.'], 400);
        }

        if (null !== $this->users->findOneBy(['email' => $email])) {
            return new JsonResponse(['error' => 'An account with this email already exists.'], 409);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $violations = $this->validator->validate($user);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $v) {
                $errors[$v->getPropertyPath()] = (string) $v->getMessage();
            }

            return new JsonResponse(['error' => 'Validation failed', 'violations' => $errors], 422);
        }

        $this->em->persist($user);
        $this->em->flush();

        return new JsonResponse([
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
        ], 201);
    }
}
