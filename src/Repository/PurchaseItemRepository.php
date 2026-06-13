<?php

namespace App\Repository;

use App\Entity\Cursus;
use App\Entity\Lesson;
use App\Entity\Purchase;
use App\Entity\PurchaseItem;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PurchaseItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseItem::class);
    }

    public function findByPurchase(Purchase $purchase): array
    {
        return $this->createQueryBuilder('pi')
            ->andWhere('pi.purchase = :purchase')
            ->setParameter('purchase', $purchase)
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndCursus(User $user, Cursus $cursus): array
    {
        return $this->createQueryBuilder('pi')
            ->join('pi.purchase', 'p')
            ->andWhere('p.user = :user')
            ->andWhere('pi.cursus = :cursus')
            ->setParameter('user', $user)
            ->setParameter('cursus', $cursus)
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndStatus(User $user, string $status): array
    {
        return $this->createQueryBuilder('pi')
            ->join('pi.purchase', 'p')
            ->andWhere('p.user = :user')
            ->andWhere('p.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', $status)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Lesson[]
     */
    public function findLessonsPurchasedByUser(User $user): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder();

        return $qb
            ->select('DISTINCT l, c, t')
            ->from(Lesson::class, 'l')
            ->join(PurchaseItem::class, 'pi', 'ON', 'pi.lesson = l')
            ->join('pi.purchase', 'p')
            ->leftJoin('l.cursus', 'c')
            ->leftJoin('c.theme', 't')
            ->andWhere('p.user = :user')
            ->andWhere('p.status = :paid')
            ->andWhere('pi.lesson IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('paid', Purchase::STATUS_PAID)
            ->getQuery()
            ->getResult();
    }

    public function findMostPurchasedLessons(): array
    {
        return $this->createQueryBuilder('pi')
            ->select('l.title AS lessonTitle, COUNT(pi.id) AS total')
            ->join('pi.lesson', 'l')
            ->join('pi.purchase', 'p')
            ->andWhere('pi.lesson IS NOT NULL')
            ->andWhere('p.status = :paid')
            ->setParameter('paid', Purchase::STATUS_PAID)
            ->groupBy('l.id')
            ->orderBy('total', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();
    }

    public function findMostPurchasedCursus(): array
    {
        return $this->createQueryBuilder('pi')
            ->select('c.name AS cursusName, COUNT(pi.id) AS total')
            ->join('pi.cursus', 'c')
            ->join('pi.purchase', 'p')
            ->andWhere('pi.cursus IS NOT NULL')
            ->andWhere('p.status = :paid')
            ->setParameter('paid', Purchase::STATUS_PAID)
            ->groupBy('c.id')
            ->orderBy('total', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();
    }
}