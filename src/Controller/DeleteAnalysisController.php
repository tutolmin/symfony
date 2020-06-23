<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Message\QueueManagerCommand;
use Symfony\Component\Messenger\MessageBusInterface;

class DeleteAnalysisController extends AbstractController
{
    // Neo4j client interface reference
    private $neo4j_client;

    // Logger reference
    private $logger;

    // Message bus
    private $bus;

    // Dependency injection of necessary services
    public function __construct( LoggerInterface $logger, MessageBusInterface $bus)
    {
      $this->logger = $logger;
      $this->bus = $bus;
    }

    /**
      * @Route("/deleteAnalysisList")
      * @Security("is_granted('ROLE_QUEUE_MANAGER')")
      */
    public function deleteAnalysisList()
    {
      // or add an optional message - seen by developers
      $this->denyAccessUnlessGranted('ROLE_QUEUE_MANAGER', null,
        'User tried to access a page without having ROLE_QUEUE_MANAGER');

      // HTTP request
      $request = Request::createFromGlobals();

      // get Analysis IDs from the query
      $aids = json_decode( $request->request->get( 'aids'));

      $this->logger->debug( "Analysis ids to delete: ". implode( ",", $aids));

      // Iterate through all the IDs
      foreach( $aids as $aid) {

        $this->logger->debug( 'Deleting analysis ID: '.$aid);

        // will cause the QueueManagerCommandHandler to be called
        $this->bus->dispatch(new QueueManagerCommand( 'delete', ['analysis_id' => $aid]));
      }

      return new Response( count( $aids) . " analysis node(s) have been deleted.");
    }
}
?>
