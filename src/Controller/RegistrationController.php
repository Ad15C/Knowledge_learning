<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        MailerInterface $mailer
    ): Response {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword(
                $passwordHasher->hashPassword($user, $form->get('plainPassword')->getData())
            );

            $user->setVerificationToken(bin2hex(random_bytes(32)));
            $user->setVerificationTokenExpiresAt(new \DateTime('+1 day'));
            $user->setIsVerified(false);
            $user->setRoles(['ROLE_USER']);

            $em->persist($user);
            $em->flush();

            $verifyUrl = $this->generateUrl(
                'app_verify_email',
                ['token' => $user->getVerificationToken()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            $email = (new Email())
                ->from('knowledgelearningproject@alwaysdata.net')
                ->to($user->getEmail())
                ->subject('Activez votre compte Knowledge Learning')
                ->html("
                    <p>Bonjour,</p>
                    <p>Merci pour votre inscription.</p>
                    <p><a href=\"$verifyUrl\">Cliquez ici pour activer votre compte</a></p>
                    <p>Ce lien expire dans 24 heures.</p>
                ");

            try {
                $mailer->send($email);
                dd('MAIL ENVOYÉ', $user->getEmail());
            } catch (\Throwable $e) {
                dd($e::class, $e->getMessage());
            }

            $this->addFlash('success', 'Inscription réussie ! Vérifiez votre email pour activer votre compte.');


            // Dev/Test uniquement : afficher le lien
            if (in_array($this->getParameter('kernel.environment'), ['dev', 'test'], true)) {
                $request->getSession()->set('dev_verify_url', $verifyUrl);
                $this->addFlash('info', 'Lien de vérification disponible dans la navigation.');
            }

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }
}