<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\QueueManager;
use App\Message\QueueManagerCommand;
use Symfony\Component\Messenger\MessageBusInterface;

class SetAnalysisParametersController extends AbstractController
{
    // Neo4j client interface reference
    private $neo4j_client;

    // Logger reference
    private $logger;

    // Message bus
    private $bus;

    // Queue manager reference
    private $queueManager;

    private $gids = array();

    // Dependency injection of necessary services
    public function __construct( LoggerInterface $logger,
    	QueueManager $qm, MessageBusInterface $bus)
    {
      $this->logger = $logger;
      $this->queueManager = $qm;
      $this->bus = $bus;
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

        $this->logger->debug( 'Changing analysis ' .$param.
          ' (value: '.$value.') for Id: '.$aid);

        // change depth for a particular analysis node
        if( $param == "depth")
          // will cause the QueueManagerCommandHandler to be called
          $this->bus->dispatch(new QueueManagerCommand( 'set_depth',
          ['analysis_id' => $aid, 'depth' => $value]));


	      // change side for a particular analysis node
        else if( $param == "side")
          // will cause the QueueManagerCommandHandler to be called
          $this->bus->dispatch(new QueueManagerCommand( 'set_side',
          ['analysis_id' => $aid, 'side_label' => $value]));

        // change status label for a particular analysis node
        else if( $param == "status")
          // will cause the QueueManagerCommandHandler to be called
          $this->bus->dispatch(new QueueManagerCommand( 'promote',
            ['analysis_id' => $aid, 'status' => $value]));
      }

      return new Response( count( $aids) . " analysis nodes have been modified.");
    }
}
?>
