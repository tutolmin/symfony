<?php

namespace App\Controller;

use App\Form\UploadGameType;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class AsIsController extends AbstractController
{
     /**
      * @Route("/asis")
      * @Security("is_granted('IS_AUTHENTICATED_ANONYMOUSLY')")
      */
    public function asis()
    {
        $form = $this->createForm(UploadGameType::class);

        return $this->render('asis.html.twig', [
	        'form' => $form->createView(),
        ]);
    }
}
