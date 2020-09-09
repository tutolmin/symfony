<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;

class ConnectController extends AbstractController
{
    /**
     * @Route("/connect", name="connect")
     */
    public function index(): Response
    {
        return $this->render('connect/index.html.twig', [
            'controller_name' => 'ConnectController',
            'gid' =>  0,
        ]);
    }
}
?>
