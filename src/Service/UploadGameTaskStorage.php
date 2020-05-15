<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\UploadGameTask;
use League\Flysystem\FilesystemInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * UploadGameTaskStorage.
 */
class UploadGameTaskStorage
{
    private $filesystem;

    /**
     * @param FilesystemInterface $uploadGamesTaskFilesystem
     */
    public function __construct(FilesystemInterface $uploadGamesTaskFilesystem)
    {
        $this->filesystem = $uploadGamesTaskFilesystem;
    }

    /**
     * @param UploadGameTask $task
     */
    public function saveTask(UploadGameTask $task): void
    {
        $parentDirectory = $this->createParentDirectoryForTask($task);

        $regular = $task->getRegular();
        if (is_string($regular)) {
            $filePath = sprintf('%s/regular.pgn', $parentDirectory);

            $this->filesystem->write($filePath, $regular);
        }

        $file = $task->getFile();
        if ($file instanceof UploadedFile) {
            $filePath = sprintf('%s/file.%s', $parentDirectory, $file->getClientOriginalExtension());

            $this->filesystem->copy($file->getRealPath(), $filePath);
        }
    }

    /**
     * @param UploadGameTask $task
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    private function createParentDirectoryForTask(UploadGameTask $task): string
    {
        $currentDate = new \DateTime();

        $parentDirectory = sprintf('%s/%s/%s/%s',
            $currentDate->format('Y'),
            $currentDate->format('m'),
            $currentDate->format('d'),
            $task->getHash()
        );

        if (!$this->filesystem->createDir($parentDirectory)) {
            throw new \RuntimeException(sprintf('Failed to create directory %s', $parentDirectory));
        }

        return $parentDirectory;
    }
}
