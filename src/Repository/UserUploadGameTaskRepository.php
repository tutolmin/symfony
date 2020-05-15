<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserUploadGameTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * UserUploadGameTaskRepository.
 */
class UserUploadGameTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserUploadGameTask::class);
    }
}
