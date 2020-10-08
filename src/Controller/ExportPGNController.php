<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Stopwatch\Stopwatch;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Psr\Log\LoggerInterface;
use App\Service\PGNFetcher;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

class ExportPGNController extends AbstractController
{
    private $logger;
    private $fetcher;

    // StopWatch instance
    private $stopwatch;

    // Dependency injection
    public function __construct( Stopwatch $watch, LoggerInterface $logger, PGNFetcher $fetcher)
    {
        $this->stopwatch = $watch;
        $this->logger = $logger;
        $this->fetcher = $fetcher;
    }

    /**
      * @Route("/exportPGNs")
      * @Security("is_granted('IS_AUTHENTICATED_ANONYMOUSLY')")
      */
      // HTTP request
    public function exportPGNs(): Response {

      $request = Request::createFromGlobals();

      // get Game IDs from the query
      $gids = json_decode( $request->request->get( 'gids'));

      // Fetch the games from the cache
      $PGNstring = $this->fetcher->getPGNs( $gids);

      // Prepare response
      $response = new Response( $PGNstring);

      // Strip automatic Cache-Control header for the user session
      $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

      // Generate ETag
      $response->setETag(md5($response->getContent()));
      $response->setPublic(); // make sure the response is public/cacheable
      $response->isNotModified($request);
      $response->setMaxAge(31536000);
      $response->setSharedMaxAge(31536000);

      return $response;
    }
}
?>
