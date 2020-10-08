<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

class StaticContentController extends AbstractController
{
    /**
     * @Route("/terms", name="terms")
     * @Cache(expires="+1 week")
     */
    public function tos(Request $request): Response
    {
        // Generate content with template
        $response = $this->render('terms.html.twig', [
            'controller_name' => 'StaticContentController',
        ]);

        // Strip automatic Cache-Control header for the user session
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        // Generate ETag
        $response->setETag(md5($response->getContent()));
        $response->setPublic(); // make sure the response is public/cacheable
        $response->isNotModified($request);
        $response->setMaxAge(604800);
        $response->setSharedMaxAge(604800);

        return $response;
    }
    /**
     * @Route("/privacy", name="privacy")
     * @Cache(expires="+1 week")
     */
    public function privacy(Request $request): Response
    {
      // Generate content with template
      $response = $this->render('privacy.html.twig', [
          'controller_name' => 'StaticContentController',
      ]);

      // Strip automatic Cache-Control header for the user session
      $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

      // Generate ETag
      $response->setETag(md5($response->getContent()));
      $response->setPublic(); // make sure the response is public/cacheable
      $response->isNotModified($request);
      $response->setMaxAge(604800);
      $response->setSharedMaxAge(604800);

      return $response;
    }
    /**
     * @Route("/about", name="about")
     * @Cache(expires="+1 week")
     */
    public function about(Request $request): Response
    {
      // Generate content with template
      $response = $this->render('about.html.twig', [
          'controller_name' => 'StaticContentController',
      ]);

      // Strip automatic Cache-Control header for the user session
      $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

      // Generate ETag
      $response->setETag(md5($response->getContent()));
      $response->setPublic(); // make sure the response is public/cacheable
      $response->isNotModified($request);
      $response->setMaxAge(604800);
      $response->setSharedMaxAge(604800);

      return $response;
    }
}
?>
