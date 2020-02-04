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

class AsIsController extends AbstractController
{
     /**
      * @Route("/asis")
      * @Security("is_granted('ROLE_USER')")
      */
    public function asis( Request $request, PGNUploader $fileUploader)
    {

    // or add an optional message - seen by developers
//    $this->denyAccessUnlessGranted('ROLE_USER', null, 'User tried to access a page without having ROLE_USER');

        $PGN = new PGN();
        $form = $this->createForm(PGNType::class, $PGN);
        $form->handleRequest($request);

        $number = random_int(0, 100);

    if ($form->isSubmitted() && $form->isValid()) {
        /** @var UploadedFile $PGNFile */
        $PGNFile = $form['pgn']->getData();
        if ($PGNFile) {
            $PGNFileName = $fileUploader->upload($PGNFile);
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
	    'form' => $form->createView(),
        ]);
    }
}

