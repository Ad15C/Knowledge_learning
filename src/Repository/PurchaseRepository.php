<?php

namespace App\Repository;

use App\Entity\Purchase;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PurchaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Purchase::class);
    }

    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndStatus(User $user, string $status): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', $status)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndPeriod(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.createdAt BETWEEN :from AND :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getTotalSpent(User $user, ?string $status = null): float
    {
        $qb = $this->createQueryBuilder('p')
            ->select('SUM(p.total)')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user);

        if ($status) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        return (float) $qb->getQuery()->getSingleScalarResult();
    }

    public function getTotalOrders(User $user, ?string $status = null): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user);

        if ($status) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array{items: array<int, array{purchase: Purchase, itemsCount: int}>, total: int}
     * on normalise les données avant de construire les clauses WHERE pour éviter les problèmes
     * de jointures et de group by, et on ne refait pas la requête pour chaque filtre, 
     * on construit une seule requête avec tous les filtres appliqués
     */
    public function findForAdminListPaginated(
        string $q,
        ?string $status,
        ?int $userId,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
        string $sort,
        string $dir,
        int $page,
        int $perPage
    ): array {
        //  Normalisation "date only"
        // dateFrom => début de journée
        // dateTo   => fin de journée
        if ($dateFrom) {
            $dateFrom = $dateFrom->setTime(0, 0, 0);
        }
        if ($dateTo) {
            $dateTo = $dateTo->setTime(23, 59, 59);
        }

        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')->addSelect('u')
            ->leftJoin('p.items', 'pi')
            ->addSelect('COUNT(pi.id) AS itemsCount')
            ->groupBy('p.id')
            ->addGroupBy('u.id');

        if ($q !== '') {
            $qb->andWhere('LOWER(p.orderNumber) LIKE :q OR LOWER(u.email) LIKE :q OR LOWER(u.firstName) LIKE :q OR LOWER(u.lastName) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($q).'%');
        }

        if ($status !== null && $status !== '' && $status !== 'all') {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        if ($userId) {
            $qb->andWhere('u.id = :uid')->setParameter('uid', $userId);
        }

        if ($dateFrom) {
            $qb->andWhere('p.createdAt >= :from')->setParameter('from', $dateFrom);
        }
        if ($dateTo) {
            $qb->andWhere('p.createdAt <= :to')->setParameter('to', $dateTo);
        }

        $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

        $allowedSort = [
            'createdAt' => 'p.createdAt',
            'status'    => 'p.status',
            'total'     => 'p.total',
            'paidAt'    => 'p.paidAt',
            'user'      => 'u.lastName',
        ];
        $sortExpr = $allowedSort[$sort] ?? 'p.createdAt';

        // 1) Total (requête séparée, sans groupBy / limit / offset)
        $countQb = $this->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.id)')
            ->leftJoin('p.user', 'u');

        if ($q !== '') {
            $countQb->andWhere('LOWER(p.orderNumber) LIKE :q OR LOWER(u.email) LIKE :q OR LOWER(u.firstName) LIKE :q OR LOWER(u.lastName) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($q).'%');
        }
        if ($status !== null && $status !== '' && $status !== 'all') {
            $countQb->andWhere('p.status = :status')->setParameter('status', $status);
        }
        if ($userId) {
            $countQb->andWhere('u.id = :uid')->setParameter('uid', $userId);
        }
        if ($dateFrom) {
            $countQb->andWhere('p.createdAt >= :from')->setParameter('from', $dateFrom);
        }
        if ($dateTo) {
            $countQb->andWhere('p.createdAt <= :to')->setParameter('to', $dateTo);
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        // 2) Page courante
        $qb->orderBy($sortExpr, $dir)
            ->addOrderBy('p.id', $dir)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $rows = $qb->getQuery()->getResult();

        $items = [];
        foreach ($rows as $row) {
            /** @var Purchase $purchase */
            $purchase = $row[0];
            $itemsCount = (int) ($row['itemsCount'] ?? 0);

            $items[] = [
                'purchase' => $purchase,
                'itemsCount' => $itemsCount,
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    public function findOneForAdminShow(int $id): ?Purchase
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')->addSelect('u')
            ->leftJoin('p.items', 'pi')->addSelect('pi')
            ->leftJoin('pi.lesson', 'l')->addSelect('l')
            ->leftJoin('pi.cursus', 'c')->addSelect('c')
            ->andWhere('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countPaidPurchases(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.status = :paid')
            ->setParameter('paid', Purchase::STATUS_PAID)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getTotalSales(): float
    {
        return (float) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.total), 0)')
            ->andWhere('p.status = :paid')
            ->setParameter('paid', Purchase::STATUS_PAID)
            ->getQuery()
            ->getSingleScalarResult();
    }
}