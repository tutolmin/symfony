<?php

namespace App\Repository;

use App\Entity\SitemapHashes;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method SitemapHashes|null find($id, $lockMode = null, $lockVersion = null)
 * @method SitemapHashes|null findOneBy(array $criteria, array $orderBy = null)
 * @method SitemapHashes[]    findAll()
 * @method SitemapHashes[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SitemapHashesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SitemapHashes::class);
    }

    // /**
    //  * @return SitemapHashes[] Returns an array of SitemapHashes objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?SitemapHashes
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
