<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile')]
    public function index(): Response
    {
        return $this->render('profile/index.html.twig');
    }

    #[Route('/profile/password', name: 'app_profile_password', methods: ['POST'])]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
    ): Response {
        $user    = $this->getUser();
        $current = (string) $request->request->get('current_password', '');
        $new     = (string) $request->request->get('new_password', '');
        $confirm = (string) $request->request->get('confirm_password', '');

        if (!$hasher->isPasswordValid($user, $current)) {
            $this->addFlash('password_error', 'Mot de passe actuel incorrect.');
        } elseif (strlen($new) < 8) {
            $this->addFlash('password_error', 'Le nouveau mot de passe doit contenir au moins 8 caractères.');
        } elseif ($new !== $confirm) {
            $this->addFlash('password_error', 'Les mots de passe ne correspondent pas.');
        } else {
            $user->setPassword($hasher->hashPassword($user, $new));
            $em->flush();
            $this->addFlash('password_success', 'Mot de passe modifié avec succès.');
        }

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/profile/delete', name: 'app_profile_delete', methods: ['POST'])]
    public function deleteAccount(
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('delete_account', $request->request->get('_token'))) {
            $this->addFlash('danger_error', 'Token invalide.');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        $this->container->get('security.token_storage')->setToken(null);
        $request->getSession()->invalidate();

        $em->remove($user);
        $em->flush();

        return $this->redirectToRoute('app_home');
    }
}
