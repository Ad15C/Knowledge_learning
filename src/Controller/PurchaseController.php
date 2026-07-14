<?php

namespace App\Controller;

use App\Entity\Cursus;
use App\Entity\Lesson;
use App\Entity\Purchase;
use App\Entity\PurchaseItem;
use App\Entity\User;
use App\Repository\CursusRepository;
use App\Repository\LessonRepository;
use App\Repository\PurchaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Stripe\StripeClient;

#[IsGranted('ROLE_USER')]
class PurchaseController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private PurchaseRepository $purchaseRepo,
        private StripeClient $stripeClient
    ) {
    }

    private function redirectAdminToDashboard(): ?Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            $this->addFlash('warning', "Les administrateurs n'ont pas accès aux fonctionnalités d'achat.");
            return $this->redirectToRoute('admin_dashboard');
        }

        return null;
    }

    private function getConnectedUser(): ?User
    {
        $user = $this->getUser();
        return $user instanceof User ? $user : null;
    }

    #[Route('/cart', name: 'cart_show', methods: ['GET'])]
    public function show(): Response
    {
        if ($response = $this->redirectAdminToDashboard()) {
            return $response;
        }

        $user = $this->getConnectedUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $purchase = $this->purchaseRepo->findOneBy([
            'user' => $user,
            'status' => Purchase::STATUS_CART,
        ]);

        return $this->render('cart/show.html.twig', [
            'purchase' => $purchase,
        ]);
    }

    /* Routes pour ajouter des leçons ou cursus au panier, supprimer des items, payer, etc. */
    #[Route('/cart/add/lesson/{slug}', name: 'cart_add_lesson', methods: ['POST'])]
    public function addLesson(
        Request $request,
        string $slug,
        LessonRepository $lessonRepository
    ): Response {
        if ($response = $this->redirectAdminToDashboard()) {
            return $response;
        }

        $lesson = $lessonRepository->findVisibleLessonBySlug($slug);

        if (!$lesson instanceof Lesson) {
            throw $this->createNotFoundException('Leçon introuvable ou indisponible.');
        }

        if (
            !$this->isCsrfTokenValid(
                'cart_add_lesson_' . $lesson->getSlug(),
                (string) $request->request->get('_token')
            )
        ) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $this->getConnectedUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $purchase = $this->purchaseRepo->findOneBy([
            'user' => $user,
            'status' => Purchase::STATUS_CART,
        ]);

        if (!$purchase) {
            $purchase = new Purchase();
            $purchase->setUser($user);
            $this->em->persist($purchase);
        }

        foreach ($purchase->getItems() as $existingItem) {
            if ($existingItem->getLesson() === $lesson) {
                $this->addFlash('info', 'Cette leçon est déjà dans le panier.');
                return $this->redirectToRoute('cart_show');
            }
        }

        $item = new PurchaseItem();
        $item->setPurchase($purchase);
        $item->setLesson($lesson);
        $item->setUnitPrice((float) $lesson->getPrice());

        $this->em->persist($item);
        $purchase->addItem($item);
        $purchase->calculateTotal();

        $this->em->flush();

        $this->addFlash('success', 'Leçon ajoutée au panier.');
        return $this->redirectToRoute('cart_show');
    }

    #[Route('/cart/add/cursus/{slug}', name: 'cart_add_cursus', methods: ['POST'])]
    public function addCursus(
        Request $request,
        string $slug,
        CursusRepository $cursusRepository
    ): Response {
        if ($response = $this->redirectAdminToDashboard()) {
            return $response;
        }

        $cursus = $cursusRepository->findVisibleCursusBySlug($slug);

        if (!$cursus instanceof Cursus) {
            throw $this->createNotFoundException('Cursus introuvable ou indisponible.');
        }

        if (
            !$this->isCsrfTokenValid(
                'cart_add_cursus_' . $cursus->getSlug(),
                (string) $request->request->get('_token')
            )
        ) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $this->getConnectedUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $purchase = $this->purchaseRepo->findOneBy([
            'user' => $user,
            'status' => Purchase::STATUS_CART,
        ]);

        if (!$purchase) {
            $purchase = new Purchase();
            $purchase->setUser($user);
            $this->em->persist($purchase);
        }

        foreach ($purchase->getItems() as $existingItem) {
            if ($existingItem->getCursus() === $cursus) {
                $this->addFlash('info', 'Ce cursus est déjà dans le panier.');
                return $this->redirectToRoute('cart_show');
            }
        }

        $item = new PurchaseItem();
        $item->setPurchase($purchase);
        $item->setCursus($cursus);
        $item->setUnitPrice((float) $cursus->getPrice());

        $this->em->persist($item);
        $purchase->addItem($item);
        $purchase->calculateTotal();

        $this->em->flush();

        $this->addFlash('success', 'Cursus ajouté au panier.');
        return $this->redirectToRoute('cart_show');
    }


    #[Route('/cart/remove/{type}/{id}', name: 'cart_remove', methods: ['POST'])]
    public function remove(Request $request, string $type, int $id): Response
    {
        if ($response = $this->redirectAdminToDashboard()) {
            return $response;
        }

        if (!in_array($type, ['lesson', 'cursus'], true)) {
            throw $this->createNotFoundException();
        }

        if (
            !$this->isCsrfTokenValid(
                'cart_remove_' . $type . '_' . $id,
                (string) $request->request->get('_token')
            )
        ) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $this->getConnectedUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $purchase = $this->purchaseRepo->findOneBy([
            'user' => $user,
            'status' => Purchase::STATUS_CART,
        ]);

        if (!$purchase) {
            return $this->redirectToRoute('cart_show');
        }

        foreach ($purchase->getItems() as $item) {
            if ($type === 'lesson' && $item->getLesson()?->getId() === $id) {
                $purchase->removeItem($item);
                $this->em->remove($item);
                break;
            }

            if ($type === 'cursus' && $item->getCursus()?->getId() === $id) {
                $purchase->removeItem($item);
                $this->em->remove($item);
                break;
            }
        }

        $purchase->calculateTotal();
        $this->em->flush();

        $this->addFlash('success', 'Item supprimé.');
        return $this->redirectToRoute('cart_show');
    }

    #[Route('/cart/pay', name: 'cart_pay', methods: ['POST'])]
    public function pay(Request $request): Response
    {
        if ($response = $this->redirectAdminToDashboard()) {
            return $response;
        }

        if (!$this->isCsrfTokenValid(
            'cart_pay',
            (string) $request->request->get('_token')
        )) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $acceptCgv = $request->request->getBoolean('accept_cgv');
        $acceptRetractationWaiver = $request->request
            ->getBoolean('accept_retractation_waiver');

        if (!$acceptCgv || !$acceptRetractationWaiver) {
            $this->addFlash(
                'error',
                'Vous devez accepter les CGV et reconnaître la renonciation au droit de rétractation pour poursuivre le paiement.'
            );

            return $this->redirectToRoute('cart_show');
        }

        $user = $this->getConnectedUser();

        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $purchase = $this->purchaseRepo->findOneBy([
            'user' => $user,
            'status' => Purchase::STATUS_CART,
        ]);

        if (!$purchase || $purchase->getItems()->isEmpty()) {
            $this->addFlash('error', 'Panier vide.');

            return $this->redirectToRoute('cart_show');
        }

        // Recalcul du montant côté serveur
        $purchase->calculateTotal();

        // Préparation des produits transmis à Stripe
        $lineItems = [];

        foreach ($purchase->getItems() as $item) {
            if ($item->getCursus()) {
                $productName = $item->getCursus()->getName();
            } elseif ($item->getLesson()) {
                $productName = $item->getLesson()->getTitle();
            } else {
                $productName = 'Produit';
            }

            $lineItems[] = [
                'price_data' => [
                    'currency' => 'eur',

                    // Stripe attend un montant en centimes
                    'unit_amount' => $item->getUnitPriceInCents(),

                    'product_data' => [
                        'name' => $productName,
                    ],
                ],

                'quantity' => $item->getQuantity(),
            ];
        }

        // Création de la session Stripe
        $session = $this->stripeClient->checkout->sessions->create([
            'mode' => 'payment',

            'line_items' => $lineItems,

            'customer_email' => $user->getEmail(),

            'client_reference_id' => (string) $purchase->getId(),

            'metadata' => [
                'purchase_id' => (string) $purchase->getId(),
                'order_number' => (string) $purchase->getOrderNumber(),
            ],

            'success_url' => $this->generateUrl(
                'cart_payment_success',
                [],
                \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
            ) . '?session_id={CHECKOUT_SESSION_ID}',

            'cancel_url' => $this->generateUrl(
                'cart_payment_cancel',
                [],
                \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
            ) . '?session_id={CHECKOUT_SESSION_ID}',
        ]);

        // La commande attend la confirmation du paiement
        $purchase
            ->setStripeSessionId($session->id)
            ->markPending();

        $this->em->flush();

        // Redirection vers Stripe Checkout
        return $this->redirect(
            $session->url,
            Response::HTTP_SEE_OTHER
        );
    }

    #[Route('/cart/payment/success', name: 'cart_payment_success', methods: ['GET'])]
    public function paymentSuccess(Request $request): Response
    {
        $user = $this->getConnectedUser();
        $sessionId = $request->query->getString('session_id');

        if (!$user || $sessionId === '') {
            return $this->redirectToRoute('cart_show');
        }

        $purchase = $this->purchaseRepo->findOneBy([
            'user' => $user,
            'stripeSessionId' => $sessionId,
        ]);

        if (!$purchase) {
            throw $this->createNotFoundException('Commande introuvable.');
        }

        if ($purchase->isPaid()) {
            return $this->redirectToRoute('cart_success', [
                'orderNumber' => $purchase->getOrderNumber(),
            ]);
        }

        $this->addFlash(
            'info',
            'Votre paiement a été reçu. La confirmation est en cours.'
        );

        return $this->redirectToRoute('cart_show');
    }

    #[Route('/cart/payment/cancel', name: 'cart_payment_cancel', methods: ['GET'])]
    public function paymentCancel(Request $request): Response
    {
        $user = $this->getConnectedUser();
        $sessionId = $request->query->getString('session_id');

        if ($user && $sessionId !== '') {
            $purchase = $this->purchaseRepo->findOneBy([
                'user' => $user,
                'stripeSessionId' => $sessionId,
                'status' => Purchase::STATUS_PENDING,
            ]);

            if ($purchase) {
                $purchase
                    ->setStatus(Purchase::STATUS_CART)
                    ->setStripeSessionId(null)
                    ->setStripePaymentIntentId(null);

                $this->em->flush();
            }
        }

        $this->addFlash('warning', 'Le paiement a été annulé.');

        return $this->redirectToRoute('cart_show');
    }

    #[Route('/cart/success/{orderNumber}', name: 'cart_success', methods: ['GET'])]
    public function success(string $orderNumber): Response
    {
        if ($response = $this->redirectAdminToDashboard()) {
            return $response;
        }

        $user = $this->getConnectedUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $purchase = $this->purchaseRepo->findOneBy([
            'orderNumber' => $orderNumber,
            'user' => $user,
            'status' => Purchase::STATUS_PAID,
        ]);

        if (!$purchase) {
            throw $this->createNotFoundException();
        }

        return $this->render('cart/success.html.twig', [
            'purchase' => $purchase,
        ]);
    }
}