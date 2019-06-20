<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class AsIsController extends AbstractController
{
     /**
      * @Route("/asis")
      * @Security("is_granted('ROLE_USER')")
      */
    public function asis()
    {

    // or add an optional message - seen by developers
//    $this->denyAccessUnlessGranted('ROLE_USER', null, 'User tried to access a page without having ROLE_USER');

        $number = random_int(0, 100);
/*
        return new Response(
            '<html><body>Lucky number: '.$number.'</body></html>'
        );
*/
        return $this->render('asis.html.twig', [
            'number' => $number,
        ]);
    }
}

