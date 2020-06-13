<?php

namespace App\Controller;

use App\Entity\PGN;
use App\Form\PGNType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\PGNUploader;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AsIsController extends AbstractController
{
     /**
      * @Route("/asis/{gid}", requirements={"gid": "\d+"})
      * @Security("is_granted('IS_AUTHENTICATED_ANONYMOUSLY')")
      */
    public function asis( Request $request, PGNUploader $fileUploader, $gid)
    {

        $number = random_int(0, 100);

    // or add an optional message - seen by developers
//    $this->denyAccessUnlessGranted('ROLE_USER', null, 'User tried to access a page without having ROLE_USER');

	// Build a form
        $PGN = new PGN();
        $form = $this->createForm(PGNType::class, $PGN);
        $form->handleRequest($request);

	if ($form->isSubmitted() && $form->isValid()) {

          /** @var UploadedFile $PGNFile */
          $PGNFile = $form['pgn']->getData();
          if ($PGNFile) {
            $PGNFileName = $fileUploader->uploadGames( $PGNFile);
            $PGN->setPGNFilename($PGNFileName);
          }

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
