<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\GameManager;

class DeleteGameController extends AbstractController
{
    // Neo4j client interface reference
    private $neo4j_client;

    // Logger reference
    private $logger;

    // Message bus
    private $bus;

    // Queue manager reference
    private $gameManager;

    // Dependency injection of necessary services
    public function __construct( GameManager $gm, LoggerInterface $logger)
    {
      $this->gameManager = $gm;

      $this->logger = $logger;
    }

    /**
      * @Route("/deleteGamesList")
      * @Security("is_granted('ROLE_GAMES_MANAGER')")
      */
    public function deleteAnalysisList()
    {
      // or add an optional message - seen by developers
      $this->denyAccessUnlessGranted('ROLE_GAMES_MANAGER', null,
        'User tried to access a page without having ROLE_GAMES_MANAGER');

      // HTTP request
      $request = Request::createFromGlobals();

      // get Games IDs from the query
      $gids = json_decode( $request->request->get( 'gids'));

      if( is_array( $gids)) {

        // Iterate through all the game IDs
        foreach( $gids as $key => $hash) {

          $gid = $this->gameManager->gameIdByHash( $hash);

          if( $_ENV['APP_DEBUG'])
            $this->logger->debug( 'Deleting game ID: '.$gid);

          // Delete game by id
          if( !$this->gameManager->deleteGame( $gid)) unset( $gids[$key]);

        }
      }

      return new Response( count( $gids) . " :Game node(s) have been deleted.");
    }
}
?>
