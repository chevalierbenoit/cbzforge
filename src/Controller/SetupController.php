<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * First-run setup: creates the one administrator. Closed once an account exists.
 */
class SetupController extends AbstractController
{
    #[Route('/setup', name: 'app_setup', methods: ['GET', 'POST'])]
    public function setup(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $form = $this->createFormBuilder()
            ->add('username', TextType::class, [
                'label' => 'setup.username',
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 3, max: 64),
                    new Regex(pattern: '/^[A-Za-z0-9_.-]+$/', message: 'setup.username_chars'),
                ],
            ])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'setup.password',
                    'constraints' => [new NotBlank(), new Length(min: User::MIN_PASSWORD_LENGTH)],
                ],
                'second_options' => ['label' => 'setup.password_confirm'],
                'invalid_message' => 'setup.passwords_differ',
            ])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = new User();
            $user->setUsername($form->get('username')->getData());
            $user->setPassword($hasher->hashPassword($user, $form->get('password')->getData()));
            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'setup.created');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/setup.html.twig', ['form' => $form]);
    }
}
