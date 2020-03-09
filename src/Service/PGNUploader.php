<?php

// src/Service/PGNUploader.php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
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

    public function uploadLines( $file)
    {
	// Filename SHOULD contain 'lines' prefix in order to make sure
	// the filename is never matches 'games' prefix, reserved for :Game-only db merge
	// Strip '/tmp/' from a filename
        $fileName = $this->getTargetDirectory() . substr( $file, 4) . '.pgn';

        $this->logger->debug( $fileName);

	$filesystem = new Filesystem();
	try {

	  // Copy the file to a special upload directory
	  $filesystem->rename( $file, $fileName);

        } catch (IOExceptionInterface $exception) {

          $this->logger->debug( "An error occurred while moving a temp file ".$exception->getPath());

        }
    }

    public function uploadGames( UploadedFile $file)
    {
        $user = $this->security->getUser();

        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()', $originalFilename);

	// Filename SHOULD contain 'games' prefix in order to make sure
	// the filename is never matches 'lines' prefix, reserved for :Line-only db merge
        $fileName = 'games-'.$user->getID().'-'.$safeFilename.'-'.uniqid().'.'.$file->guessExtension();

        $this->logger->debug( $this->getTargetDirectory());
        $this->logger->debug( $fileName);

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