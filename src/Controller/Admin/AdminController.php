<?php

namespace App\Controller\Admin;

use App\Repository\UserRepository;
use App\Repository\CertificationRepository;
use App\Repository\LessonValidatedRepository;
use App\Repository\PurchaseItemRepository;
use App\Repository\PurchaseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin', name: 'admin_')]
class AdminController extends AbstractController
{
    #[Route('', name: 'dashboard')]
    public function dashboard(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    #[Route('/stats', name: 'stats')]
    public function stats(
        UserRepository $userRepo,
        CertificationRepository $certRepo,
        LessonValidatedRepository $lessonValidatedRepo,
        PurchaseItemRepository $purchaseItemRepo,
        PurchaseRepository $purchaseRepo,
    ): Response {
        $mostValidatedLessons = $lessonValidatedRepo->findMostValidatedLessons();
        $lessonsValidatedCount = $lessonValidatedRepo->count([]);

        $activeUsersCount = $userRepo->count(['archivedAt' => null]);

        $certificationsCount = $certRepo->count([]);     
    
        $paidPurchasesCount = $purchaseRepo->countPaidPurchases();
        $totalSales = $purchaseRepo->getTotalSales();

        $mostPurchasedLessons = $purchaseItemRepo->findMostPurchasedLessons();
        $mostPurchasedCursus = $purchaseItemRepo->findMostPurchasedCursus();    

        return $this->render('admin/users/stats.html.twig', [
            'mostValidatedLessons' => $mostValidatedLessons,
            'lessonsValidatedCount' => $lessonsValidatedCount,
            'activeUsersCount' => $activeUsersCount,
            'certificationsCount' => $certificationsCount,
            'paidPurchasesCount' => $paidPurchasesCount,
            'totalSales' => $totalSales,
            'mostPurchasedLessons' => $mostPurchasedLessons,
            'mostPurchasedCursus' => $mostPurchasedCursus,
        ]);
    }
}