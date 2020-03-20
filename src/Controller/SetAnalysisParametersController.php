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

class SetAnalysisParametersController extends AbstractController
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
	$this->stopwatch->start('setAnalysisParameters');
    }

    public function __destruct()
    {
	// stops event named 'eventName'
	$this->stopwatch->stop('setAnalysisParameters');
    }

    /**
      * @Route("/setAnalysisParam")
      * @Security("is_granted('ROLE_QUEUE_MANAGER')")
      */
    public function setAnalysisParam()
    {
      // or add an optional message - seen by developers
      $this->denyAccessUnlessGranted('ROLE_QUEUE_MANAGER', null, 
	'User tried to access a page without having ROLE_QUEUE_MANAGER');

      // HTTP request
      $request = Request::createFromGlobals();
	
      // Validate newly specified param type
      $param = $request->request->get( 'param');

      // Validate newly specified param value
      $value = $request->request->get( 'value');

      // get Analysis IDs from the query
      $aids = json_decode( $request->request->get( 'aids'));

      $this->logger->debug( "Analysis ids to change " .$param. 
	" to " . $value . " : ". implode( ",", $aids));
	
      // Iterate through all the IDs
      $counter = 0;
      foreach( $aids as $aid) {

	$this->stopwatch->lap('setAnalysisParameters');

        $this->logger->debug( 'Changing analysis ' .$param. ' for Id: '.$aid);

	// change depth for a particular analysis node
	if( $param == "depth")
	  if( $this->queueManager->setAnalysisDepth( $aid, $value))
	    $counter++;

	// change side for a particular analysis node
	if( $param == "side")
	  if( $this->queueManager->setAnalysisSide( $aid, $value))
	    $counter++;

	// change status label for a particular analysis node
	if( $param == "status")
	  if( $this->queueManager->setAnalysisStatus( $aid, $value))
	    $counter++;
      }

      return new Response( $counter . " analysis nodes have been modified.");
    }
}
?>
