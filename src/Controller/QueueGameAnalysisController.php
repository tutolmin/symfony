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
use App\Message\QueueManagerCommand;
use App\Message\InputOutputOperation;
use Symfony\Component\Messenger\MessageBusInterface;

class QueueGameAnalysisController extends AbstractController
{
    // Neo4j client interface reference
    private $neo4j_client;

    // StopWatch instance
    private $stopwatch;

    // Logger reference
    private $logger;

    // Message bus
    private $bus;

    // Queue/Game manager reference
    private $queueManager;
    private $gameManager;

    private $gids = array();

    // Dependency injection of necessary services
    public function __construct( Stopwatch $watch, LoggerInterface $logger,
      MessageBusInterface $bus, GameManager $gm)
    {
      $this->stopwatch = $watch;
      $this->logger = $logger;
      $this->bus = $bus;
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
      $depth = 'fast';

      // HTTP request
      $request = Request::createFromGlobals();

      // Get analysis depth
      $requestDepth = $request->request->get( 'depth');
      if( $requestDepth == "deep" )
        $depth = $requestDepth;

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
      foreach( $gids as $hash) {

        $gid = $this->gameManager->gameIdByHash( $hash);

        $this->logger->debug( 'Queueing game ID: '.$gid);

        // Check if game id represents a valid game
        if( !$this->gameManager->gameExists( $gid)) {

          $this->logger->debug('The game is invalid.');
          continue;
        }

        // will cause the QueueManagerCommandHandler to be called
        $this->bus->dispatch(new QueueManagerCommand( 'enqueue',
          ['game_id' => $gid, 'depth' => $depth,
          'side_label' => $sideLabel, 'user_id' => $userId]));

        // Build the list of :Game ids to request :Line merge
        $this->gids[] = $gid;
      }

      $this->stopwatch->lap('queueGameAnalysis');

      $this->logger->debug( "Game ids to load: ". implode( ",", $this->gids));

      // Request :Line load for the list of games
//      $this->gameManager->loadLines( $this->gids);

      // will cause the InputOutputOperationHandler to be called
      $this->bus->dispatch(new InputOutputOperation( 'load_lines',
        ['gids' => $this->gids]));

      return new Response( count( $this->gids) . " game(s) have been queued for analysis.");
    }
}
?>
