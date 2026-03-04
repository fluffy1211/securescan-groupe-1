<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AuthController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('auth/login.html.twig', [
            'error'         => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
        ]);
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
        UserRepository $userRepository,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $email    = trim((string) $request->request->get('email', ''));
            $password = (string) $request->request->get('password', '');
            $confirm  = (string) $request->request->get('confirm', '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Email invalide.';
            } elseif (strlen($password) < 8) {
                $error = 'Le mot de passe doit contenir au moins 8 caractères.';
            } elseif ($password !== $confirm) {
                $error = 'Les mots de passe ne correspondent pas.';
            } elseif ($userRepository->findOneBy(['email' => $email])) {
                $error = 'Un compte existe déjà avec cet email.';
            } else {
                $user = new User();
                $user->setEmail($email);
                $user->setPassword($hasher->hashPassword($user, $password));

                $em = $userRepository->getEntityManager() instanceof \Doctrine\ORM\EntityManagerInterface
                    ? $userRepository->getEntityManager()
                    : null;

                $userRepository->getEntityManager()->persist($user);
                $userRepository->getEntityManager()->flush();

                $this->addFlash('success', 'Compte créé ! Connectez-vous.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('auth/register.html.twig', ['error' => $error]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Géré par Symfony Security.');
    }
}
