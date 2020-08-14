<?php

// src/MessageHandler/QueueManagerCommandHandler.php

namespace App\MessageHandler;

use App\Message\QueueManagerCommand;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use App\Service\QueueManager;
use Psr\Log\LoggerInterface;
use App\Security\TokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;

class QueueManagerCommandHandler implements MessageHandlerInterface
{

  // Queue/Game manager reference
  private $queueManager;

  // Logger reference
  private $logger;

  // Doctrine EntityManager
  private $em;

  // User repo
  private $userRepository;

  // Guard
  private $guardAuthenticatorHandler;

  // Dependency injection of the QueueManager service
  public function __construct( QueueManager $qm, LoggerInterface $logger,
      EntityManagerInterface $em, GuardAuthenticatorHandler $gah)
  {
//      parent::__construct();

      $this->queueManager = $qm;
      $this->logger = $logger;
      $this->em = $em;
      // get the User repository
      $this->userRepository = $this->em->getRepository( User::class);
      $this->guardAuthenticatorHandler = $gah;
  }

    public function __invoke(QueueManagerCommand $command)
    {

      $user = $this->userRepository->findOneBy(['id' => $command->getUserId()]);

      $this->guardAuthenticatorHandler->authenticateUserAndHandleSuccess(
        $user,
        new Request(),
        new TokenAuthenticator( $this->em),
        self::FIREWALL_MAIN
      );

      switch ( $command->getCommand()) {
        case 'enqueue':

          // enqueue particular game
          $aid = $this->queueManager->enqueueGameAnalysis(
            $command->getGameId(), $command->getDepth(), $command->getSideLabel());

          if( $aid == -1)

            $this->logger->debug( "Error queueing game id: " . $command->getGameId() . " for analysis");

          else {

            $this->logger->debug( "Game id: " . $command->getGameId() . " has been queued for analysis");

          // Request :Line load for the list of games
          // Alternatively I cal load lines for successful analysises here
//          $gameManager->loadLines( [$gid], $userId);
          }

          break;

        case 'delete':

          // Erase analysis
          if( $this->queueManager->eraseAnalysisNode( $command->getAnalysisId()))

            $this->logger->debug( "Analyis id: " . $command->getAnalysisId() . " has been deleted");

          else

            $this->logger->debug( "Error deleting analysis id: " . $command->getAnalysisId());

          break;

        case 'promote':

        // Promote analysis
        if( $this->queueManager->promoteAnalysis( $command->getAnalysisId()))

          $this->logger->debug( "Analyis id: " . $command->getAnalysisId() . " has been promoted");

        else

          $this->logger->debug( "Error promoting analysis id: " . $command->getAnalysisId());

          break;

        case 'set_side':

          // Set analysis side
          if( $this->queueManager->setAnalysisSide( $command->getAnalysisId(), $command->getSideLabel()))

            $this->logger->debug( "Analyis id: " . $command->getAnalysisId() . " new side: " . $command->getSideLabel());

          else

            $this->logger->debug( "Error setting side for analysis id: " . $command->getAnalysisId());

          break;

        case 'set_depth':

          // Set analysis depth
          if( $this->queueManager->setAnalysisDepth( $command->getAnalysisId(), $command->getDepth()))

            $this->logger->debug( "Analyis id: " . $command->getAnalysisId() . " new side: " . $command->getSideLabel());

          else

            $this->logger->debug( "Error setting side for analysis id: " . $command->getAnalysisId());

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
