<?php

// src/Service/PGNUploader.php

namespace App\Service;

use App\Entity\User;
use App\Entity\PGN;
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

    public function uploadHTML( $file, $hash)
    {
    	// Filename SHOULD contain 'html' prefix in order to make sure
    	// the filename is never matches 'games' prefix, reserved for :Game-only db merge
    	// the filename is never matches 'lines' prefix, reserved for :Game-only db merge
    	// Strip '/tmp/' from a filename
      $fileName = $this->getTargetDirectory() . "/pages-" . $hash . '.html';

      $this->logger->debug( $fileName);

      $filesystem = new Filesystem();
      try {

        // Copy the file to a special upload directory
        $filesystem->rename( $file, $fileName);

      } catch (IOExceptionInterface $exception) {

        $this->logger->debug( "An error occurred while moving a temp file ".$exception->getPath());

      }
    }

    public function uploadEvals( $file, $hash)
    {
    	// Filename SHOULD contain 'evals' prefix in order to make sure
    	// the filename is never matches 'games' prefix, reserved for :Game-only db merge
    	// the filename is never matches 'lines' prefix, reserved for :Game-only db merge
    	// Strip '/tmp/' from a filename
      $fileName = $this->getTargetDirectory() . "/evals-" . $hash . '.json';

      $this->logger->debug( $fileName);

      $filesystem = new Filesystem();
      try {

        // Copy the file to a special upload directory
        $filesystem->rename( $file, $fileName);

      } catch (IOExceptionInterface $exception) {

        $this->logger->debug( "An error occurred while moving a temp file ".$exception->getPath());

      }
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

    public function uploadPGN( PGN $PGN)
    {
      $user = $this->security->getUser();

// Filename SHOULD contain 'games' prefix in order to make sure
// the filename is never matches 'lines' prefix, reserved for :Line-only db merge
      $fileName = 'games-'.$user->getID().'-'.uniqid().'.pgn';

      $this->logger->debug( $this->getTargetDirectory());
      $this->logger->debug( $fileName);

      $filesystem = new Filesystem();
      try {

        $filesystem->appendToFile( $this->getTargetDirectory() . '/' . $fileName, $PGN->getText());
//          $file->move($this->getTargetDirectory(), $fileName);

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
