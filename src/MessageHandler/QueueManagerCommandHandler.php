<?php

// src/MessageHandler/QueueManagerCommandHandler.php

namespace App\MessageHandler;

use App\Message\QueueManagerCommand;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use App\Service\QueueManager;
use Psr\Log\LoggerInterface;

class QueueManagerCommandHandler implements MessageHandlerInterface
{
/*
  // Queue/Game manager reference
  private $queueManager;

  // Logger reference
  private $logger;

  // Dependency injection of the QueueManager service
  public function __construct( QueueManager $qm, LoggerInterface $logger)
  {
      parent::__construct();

      $queueManager = $qm;
      $logger = $logger;
  }
*/
    public function __invoke(QueueManagerCommand $command, $params,
      QueueManager $queueManager, LoggerInterface $logger)
    {

      switch ( $command) {
        case 'enqueue':

          // enqueue particular game
          $aid = $queueManager->enqueueGameAnalysis(
            $params['gid'], $params['depth'], $params['side_label'], $params['user_id']);

          if( $aid == -1)

            $logger->debug( "Error queueing game id: " . $params['gid'] . " for analysis");

          else {

            $logger->debug( "Game id: " . $params['gid'] . " has been queued for analysis");

          // Request :Line load for the list of games
          // Alternatively I cal load lines for successful analysises here
//          $gameManager->loadLines( [$gid], $userId);
          }

          break;

        case 'delete':

          // Erase analysis
          if( $queueManager->eraseAnalysisNode( $params['analysis_id']))

            $logger->debug( "Analyis id: " . $params['analysis_id'] . " has been deleted");

          else

            $logger->debug( "Error deleting analysis id: " . $params['analysis_id']);

          break;

        case 'promote':

        // Promote analysis
        if( $queueManager->promoteAnalysis( $params['analysis_id']))

          $logger->debug( "Analyis id: " . $params['analysis_id'] . " has been promoted");

        else

          $logger->debug( "Error promoting analysis id: " . $params['analysis_id']);

          break;

        case 'set_side':

          // Set analysis side
          if( $queueManager->setAnalysisSide( $params['analysis_id'], $params['side_label']))

            $logger->debug( "Analyis id: " . $params['analysis_id'] . " new side: " . $params['side_label']);

          else

            $logger->debug( "Error setting side for analysis id: " . $params['analysis_id']);

          break;

        case 'set_depth':

          // Set analysis depth
          if( $queueManager->setAnalysisDepth( $params['analysis_id'], $params['depth']))

            $logger->debug( "Analyis id: " . $params['analysis_id'] . " new side: " . $params['side_label']);

          else

            $logger->debug( "Error setting side for analysis id: " . $params['analysis_id']);

          break;

/*
        case '':
            // code...
          break;
*/

        default:
          // code...
          break;
      }

    }
}

?>
