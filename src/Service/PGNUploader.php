<?php

// src/Service/PGNUploader.php
namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Security;
use Psr\Log\LoggerInterface;

class PGNUploader
{
    private $targetDirectory;
    private $security;
    private $logger;

    public function __construct($targetDirectory, Security $security, LoggerInterface $logger)
    {
        $this->targetDirectory = $targetDirectory;
        $this->security = $security;
        $this->logger = $logger;
    }

    public function upload(UploadedFile $file)
    {
        $user = $this->security->getUser();
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()', $originalFilename);
        $fileName = $user->getID().'-'.$safeFilename.'-'.uniqid().'.'.$file->guessExtension();

        $this->logger->debug( $this->getTargetDirectory());
        $this->logger->debug( $fileName);
        $this->logger->debug( $user->getId());

        try {
            $file->move($this->getTargetDirectory(), $fileName);
        } catch (FileException $e) {
            $this->logger->debug( $e->getMessage());
            // ... handle exception if something happens during file upload
        }

        return $fileName;
    }

    public function getTargetDirectory()
    {
        return $this->targetDirectory;
    }
}

?>

