<?php

namespace App\Repository;

use App\Entity\CompleteAnalysis;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method CompleteAnalysis|null find($id, $lockMode = null, $lockVersion = null)
 * @method CompleteAnalysis|null findOneBy(array $criteria, array $orderBy = null)
 * @method CompleteAnalysis[]    findAll()
 * @method CompleteAnalysis[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CompleteAnalysisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompleteAnalysis::class);
    }

    // /**
    //  * @return CompleteAnalysis[] Returns an array of CompleteAnalysis objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?CompleteAnalysis
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
