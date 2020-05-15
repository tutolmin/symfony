<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\UserUploadGameTask;
use App\Model\UploadGameTask;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UploadGameTaskManager.
 */
class UploadGameTaskManager
{
    private $em;
    private $userManager;
    private $uploadGameTaskStorage;

    /**
     * @param EntityManagerInterface $em
     * @param UserManager            $userManager
     * @param UploadGameTaskStorage  $uploadGameTaskStorage
     */
    public function __construct(EntityManagerInterface $em, UserManager $userManager, UploadGameTaskStorage $uploadGameTaskStorage)
    {
        $this->em = $em;
        $this->userManager = $userManager;
        $this->uploadGameTaskStorage = $uploadGameTaskStorage;
    }

    /**
     * @param UploadGameTask $task
     *
     * @return UserUploadGameTask
     */
    public function createEntity(UploadGameTask $task): UserUploadGameTask
    {
        $this->uploadGameTaskStorage->saveTask($task);

        // @todo: use validator

        $currentUser = $this->userManager->getCurrentUser();
        $entity = new UserUploadGameTask($currentUser, $task->getHash());

        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }
}
