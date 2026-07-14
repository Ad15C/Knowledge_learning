<?php

namespace App\Controller;

use App\Entity\Purchase;
use App\Repository\PurchaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use UnexpectedValueException;

final class StripeWebhookController extends AbstractController
{
    public function __construct(
        private PurchaseRepository $purchaseRepository,
        private EntityManagerInterface $entityManager,
        private string $webhookSecret,
    ) {
    }

    #[Route('/stripe/webhook', name: 'stripe_webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->headers->get('Stripe-Signature');

        if (!$signature) {
            return new JsonResponse(
                ['error' => 'Signature Stripe absente'],
                400
            );
        }

        try {
            $event = Webhook::constructEvent(
                $payload,
                $signature,
                $this->webhookSecret
            );
        } catch (UnexpectedValueException|SignatureVerificationException) {
            return new JsonResponse(
                ['error' => 'Webhook Stripe invalide'],
                400
            );
        }

        if ($event->type === 'checkout.session.completed') {
            /** @var Session $session */
            $session = $event->data->object;

            if ($session->payment_status === 'paid') {
                $purchase = $this->purchaseRepository->findOneBy([
                    'stripeSessionId' => $session->id,
                ]);

                if (!$purchase) {
                    $purchaseId = $session->metadata?->purchase_id ?? null;

                    if ($purchaseId) {
                        $purchase = $this->purchaseRepository->find(
                            (int) $purchaseId
                        );
                    }
                }

                if ($purchase instanceof Purchase && !$purchase->isPaid()) {
                    $paymentIntentId = is_string($session->payment_intent)
                        ? $session->payment_intent
                        : null;

                    $purchase
                        ->setStripeSessionId($session->id)
                        ->setStripePaymentIntentId($paymentIntentId)
                        ->markPaid();

                    $this->entityManager->flush();
                }
            }
        }

        return new JsonResponse(['received' => true]);
    }
}