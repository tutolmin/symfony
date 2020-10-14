<?php

// src/MessageHandler/InputOutputOperationHandler.php

namespace App\MessageHandler;

use App\Message\InputOutputOperation;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use App\Service\QueueManager;
use App\Service\GameManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Guard\GuardAuthenticatorHandler;
use Symfony\Component\HttpFoundation\Request;
use App\Security\TokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;

class InputOutputOperationHandler implements MessageHandlerInterface
{
  const FIREWALL_MAIN = "main";

  // Queue/Game manager reference
  private $queueManager;
  private $gameManager;

  // Logger reference
  private $logger;

  // Doctrine EntityManager
  private $em;

  // User repo
  private $userRepository;

  // Guard
  private $guardAuthenticatorHandler;

  // Dependency injection of the QueueManager service
  public function __construct( GameManager $gm, QueueManager $qm,
    LoggerInterface $logger,
    EntityManagerInterface $em, GuardAuthenticatorHandler $gah)
  {
//      parent::__construct();

      $this->queueManager = $qm;
      $this->gameManager = $gm;
      $this->logger = $logger;
      $this->em = $em;
      // get the User repository
      $this->userRepository = $this->em->getRepository( User::class);
      $this->guardAuthenticatorHandler = $gah;
  }

    public function __invoke(InputOutputOperation $operation)
    {

      $user = $this->userRepository->findOneBy(['id' => $operation->getUserId()]);

      $this->guardAuthenticatorHandler->authenticateUserAndHandleSuccess(
        $user,
        new Request(),
        new TokenAuthenticator( $this->em),
        self::FIREWALL_MAIN
      );

      switch ( $operation->getOperation()) {

        case 'export_json':

          $this->logger->debug( "Exporting JSON for Analysis id: " . $operation->getAnalysisId());

          // Get game ID for analyis
          $gid = $this->queueManager->getAnalysisGameId( $operation->getAnalysisId());

          // Get available depth for each side
          $depths = $this->queueManager->getGameAnalysisDepths( $gid);

          // Request JSON files update for the game
          $this->gameManager->exportJSONFile( $gid, $depths);

          break;

        default:
          // code...
          break;
      }

    }
}

?>
