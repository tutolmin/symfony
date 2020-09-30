<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;

class StaticContentController extends AbstractController
{
    /**
     * @Route("/terms", name="terms")
     */
    public function tos(): Response
    {
        return $this->render('terms.html.twig', [
            'controller_name' => 'StaticContentController',
            'gid' =>  0,
        ]);
    }
    /**
     * @Route("/privacy", name="privacy")
     */
    public function privacy(): Response
    {
        return $this->render('privacy.html.twig', [
            'controller_name' => 'StaticContentController',
            'gid' =>  0,
        ]);
    }
    /**
     * @Route("/about", name="about")
     */
    public function about(): Response
    {
        return $this->render('about.html.twig', [
            'controller_name' => 'StaticContentController',
            'gid' =>  0,
        ]);
    }
}
?>
