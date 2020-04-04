<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Stopwatch\Stopwatch;
use App\Service\GameManager;
use App\Service\QueueManager;

class QueueGameAnalysisController extends AbstractController
{
    // Neo4j client interface reference
    private $neo4j_client;

    // StopWatch instance
    private $stopwatch;

    // Logger reference
    private $logger;

    // Queue/Game manager reference
    private $queueManager;
    private $gameManager;

    private $gids = array();

    // Dependency injection of necessary services
    public function __construct( Stopwatch $watch, LoggerInterface $logger,
	QueueManager $qm, GameManager $gm)
    {
        $this->stopwatch = $watch;
	$this->logger = $logger;
	$this->queueManager = $qm;
	$this->gameManager = $gm;

	// starts event named 'eventName'
	$this->stopwatch->start('queueGameAnalysis');
    }

    public function __destruct()
    {
	// stops event named 'eventName'
	$this->stopwatch->stop('queueGameAnalysis');
    }

    /**
      * @Route("/queueGameAnalysis")
      * @Security("is_granted('ROLE_USER')")
      */
    public function queueGameAnalysis()
    {
      // or add an optional message - seen by developers
      $this->denyAccessUnlessGranted('ROLE_USER', null, 
	'User tried to access a page without having ROLE_USER');

      // Default analysis parameters
      $sideLabel = ":WhiteSide:BlackSide";
      $depth = $_ENV['FAST_ANALYSIS_DEPTH'];

      // HTTP request
      $request = Request::createFromGlobals();
	
      // Get analysis depth
      $requestDepth = $request->request->getInt('depth', 0);
      if( $requestDepth > 0) $depth = $requestDepth;

      // Get side from a query parameter
      $sideToAnalyze = $request->request->get('side', "");

      // Prepare analyze addition to a query
      if( $sideToAnalyze == "WhiteSide" || $sideToAnalyze == "BlackSide")
	$sideLabel = ":".$sideToAnalyze;

      // get Game IDs from the query
      $gids = json_decode( $request->request->get( 'gids'));

      $this->logger->debug( "Game ids to queue: ". implode( ",", $gids));
	
      // get Doctrine userId
      $userId = $this->getUser()->getId();

      // Iterate through all the IDs
      $counter = 0;
      foreach( $gids as $gid) {

	$this->stopwatch->lap('queueGameAnalysis');

        $this->logger->debug( 'Queueing game ID: '.$gid);

	// Check if game id represents a valid game
	if( !$this->gameManager->gameExists( $gid)) {

            $this->logger->debug('The game is invalid.');
	    continue;
	}

	// enqueue particular game 
	if( $this->queueManager->enqueueGameAnalysis( 
		$gid, $depth, $sideLabel, $userId)) {

	  // Build the list of :Game ids to request :Line merge
	  $this->gids[] = $gid;

	  // Count successfull analysis additions
	  $counter++;
	}
      }

      $this->stopwatch->lap('queueGameAnalysis');

      $this->logger->debug( "Game ids to load: ". implode( ",", $this->gids));

      // Request :Line load for the list of games
      $this->gameManager->loadLines( $this->gids, $userId);

      return new Response( $counter . " game(s) have been queued for analysis.");
    }
}
?>
