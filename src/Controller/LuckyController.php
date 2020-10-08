<?php
// src/Controller/LuckyController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

class LuckyController extends AbstractController
{
     /**
      * @Route("/lucky/number")
      */
    public function number(Request $request): Response
    {
        $number = random_int(0, 100);
/*
        return new Response(
            '<html><body>Lucky number: '.$number.'</body></html>'
        );
*/

        $response = $this->render('lucky/number.html.twig', [
            'number' => $number,
        ]);

        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        $response->setETag(md5($response->getContent()));
        $response->setPublic(); // make sure the response is public/cacheable
        $response->isNotModified($request);
        // set the private or shared max age
        $response->setMaxAge(600);
        $response->setSharedMaxAge(600);

        return $response;
    }
}
?>
