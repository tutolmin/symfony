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
    public function exportPGNs() {

      $request = Request::createFromGlobals();

      // get Game IDs from the query
      $this->gids = json_decode( $request->request->get( 'gids'));

      // Fetch the games from the cache
      $PGNstring = $this->fetcher->getPGNs( $this->gids);

      return new Response( $PGNstring);
    }
}
