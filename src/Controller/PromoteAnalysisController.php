<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Stopwatch\Stopwatch;
use App\Service\QueueManager;

class PromoteAnalysisController extends AbstractController
{
    // Neo4j client interface reference
    private $neo4j_client;

    // StopWatch instance
    private $stopwatch;

    // Logger reference
    private $logger;

    // Queue manager reference
    private $queueManager;

    private $gids = array();

    // Dependency injection of necessary services
    public function __construct( Stopwatch $watch, LoggerInterface $logger,
	QueueManager $qm)
    {
        $this->stopwatch = $watch;
	$this->logger = $logger;
	$this->queueManager = $qm;

	// starts event named 'eventName'
	$this->stopwatch->start('promoteAnalysis');
    }

    public function __destruct()
    {
	// stops event named 'eventName'
	$this->stopwatch->stop('promoteAnalysis');
    }

    /**
      * @Route("/promoteAnalysisList")
      * @Security("is_granted('ROLE_QUEUE_MANAGER')")
      */
    public function promoteAnalysisList()
    {
      // or add an optional message - seen by developers
      $this->denyAccessUnlessGranted('ROLE_QUEUE_MANAGER', null, 
	'User tried to access a page without having ROLE_QUEUE_MANAGER');

      // HTTP request
      $request = Request::createFromGlobals();
	
      // get Analysis IDs from the query
      $aids = json_decode( $request->request->get( 'aids'));

      $this->logger->debug( "Analysis ids to promote: ". implode( ",", $aids));
	
      // Iterate through all the IDs
      $counter = 0;
      foreach( $aids as $aid) {

	$this->stopwatch->lap('promoteAnalysis');

        $this->logger->debug( 'Promoting analysis ID: '.$aid);

	// promote particular analysis
	if( $this->queueManager->promoteAnalysis( $aid))

	  // Count successfull analysis deletions
	  $counter++;
      }

      return new Response( $counter . " analysis nodes have been promoted.");
    }
}
?>
