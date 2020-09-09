<?php

namespace App\Controller;

use App\Entity\PGN;
use App\Form\PGNType;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security as SecurityBundle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\PGNUploader;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AsIsController extends AbstractController
{

  // We need to check roles and get user id
  private $security;

  // Logger reference
  private $logger;

  public function __construct( LoggerInterface $logger, Security $security)
  {
      $this->logger = $logger;
      $this->security = $security;
  }
     /**
      * @Route("/asis/{gid}", requirements={"gid": "\d+"})
      * @SecurityBundle("is_granted('IS_AUTHENTICATED_ANONYMOUSLY')")
      */
    public function asis( Request $request, PGNUploader $fileUploader, $gid)
    {

        $number = random_int(0, 100);

    // or add an optional message - seen by developers
//    $this->denyAccessUnlessGranted('ROLE_USER', null, 'User tried to access a page without having ROLE_USER');

      $isActive = false;
      if( $this->security->isGranted('ROLE_USER')) {
          $isActive = true;
          $this->logger->debug( "Activating form");
      }

	// Build a form
        $PGN = new PGN();

        $form = $this->createForm(PGNType::class, $PGN, [
            'disabled' => !$isActive,
        ]);

        $form->handleRequest($request);

	if ($form->isSubmitted() && $form->isValid()) {

          // $form->getData() holds the submitted values
          // but, the original `$task` variable has also been updated
          $PGN = $form->getData();

//          $this->logger->debug( $user->getId());
//          $this->logger->debug( $PGN->getText());

          $fileUploader->uploadPGN( $PGN);

          // ... perform some action, such as saving the task to the database
          // for example, if Task is a Doctrine entity, save it!
          // $entityManager = $this->getDoctrine()->getManager();
          // $entityManager->persist($task);
          // $entityManager->flush();

          return $this->redirectToRoute('index');

          // ...
	}

/*
        return new Response(
            '<html><body>Lucky number: '.$number.'</body></html>'
        );
*/

        return $this->render('asis.html.twig', [
          'number' => $number,
          'gid' =>  $gid,
	        'form' => $form->createView(),
        ]);
    }
}
?>
