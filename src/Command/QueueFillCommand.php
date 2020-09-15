<?php

// src/Command/QueueEraseCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\GameManager;
use App\Service\QueueManager;
use Symfony\Component\Security\Guard\GuardAuthenticatorHandler;
use Symfony\Component\HttpFoundation\Request;
use App\Security\TokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use App\Message\QueueManagerCommand;
use Symfony\Component\Messenger\MessageBusInterface;

class QueueFillCommand extends Command
{
    const FIREWALL_MAIN = "main";

    // Default desired queue length
    const THRESHOLD = 20;

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:fill';

    // Queue/Game manager reference
    private $queueManager;
    private $gameManager;

    // Doctrine EntityManager
    private $em;

    // Message bus
    private $bus;

    // User repo
    private $userRepository;

    // Guard
    private $guardAuthenticatorHandler;

    // Dependency injection of the GameManager service
    public function __construct( GameManager $gm, QueueManager $qm,
    EntityManagerInterface $em, GuardAuthenticatorHandler $gah,
    MessageBusInterface $bus)
    {
        parent::__construct();

        $this->gameManager = $gm;
        $this->queueManager = $qm;

        $this->em = $em;

        $this->bus = $bus;

        // get the User repository
        $this->userRepository = $this->em->getRepository( User::class);

        $this->guardAuthenticatorHandler = $gah;
    }

    protected function configure()
    {
        $this
        // the short description shown while running "php bin/console list"
        ->setDescription('Fills analysis queue with games.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to add few games into the analysis queue.')

        // option to confirm the graph deletion
        ->addOption(
        'threshold',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify the desired queue length',
        self::THRESHOLD // this is the new default value, instead of null
        )
        ->addOption(
        'type',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify game type (checkmate, stalemate, etc.)',
        'checkmate' // Default
        )
        ->addOption(
        'depth',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify analysis depth',
        'fast' // Default
        )
        ->addOption(
        'side',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify analysis side',
        ':WhiteSide:BlackSide' // Default
        )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
      // Parse the user specified queue length
      $threshold = intval( $input->getOption('threshold'));

      // Cap the user input with reasonable max value
      if( $threshold > $_ENV['PENDING_QUEUE_LIMIT'])
        $threshold = $_ENV['PENDING_QUEUE_LIMIT'];

      $output->writeln( 'We are going to fill the queue to '. $threshold. ' items...');

      // Check if queue is already full
      $length = $this->queueManager->countAnalysisNodes( 'Pending', true);

      $output->writeln( 'Current analysis queue length = '. $length);

      // Queue length negative, no graph exists
      if( $length == -1) {

        $output->writeln( 'Analysis queue does not exist. Exiting...');

        return 1;
      }

      // Current queue length is bigger than desired value.
      if( $length >= $threshold) {

        $output->writeln( 'Analysis queue already has '. $length. ' items. Exiting...');

        return 1;
      }

      // Get specified option
      $type = $input->getOption('type');

      // Default analysis parameters
      $sideLabel = ":WhiteSide:BlackSide";
      $depth = 'fast';

      // Get the user by id
      $userId = $_ENV['SYSTEM_WEB_USER_ID'];
      $user = $this->userRepository->findOneBy(['id' => $userId]);

      // Let us only use system account for filling the Queue
      // Otherwise users will get surprised to receive an analysis
      // complete notification for the game they did not request
      $this->guardAuthenticatorHandler->authenticateUserAndHandleSuccess(
            $user,
            new Request(),
            new TokenAuthenticator( $this->em),
            self::FIREWALL_MAIN
      );

      // Validate depth option
      $depthOption = $input->getOption('depth');
      if( $depthOption == "deep")
        $depth = $depthOption;

      // Validate side option
      $sideToAnalyze = $input->getOption('side');
      if( $sideToAnalyze == "WhiteSide" || $sideToAnalyze == "BlackSide")
        $sideLabel = ":".$sideToAnalyze;

      $output->writeln( 'Side labels(s): '.$sideLabel.' depth: '.$depth.
        ' type: '.$type.' user: '.$user->getEmail());

      // Array of queued game ids
      $gids = array();

      // Add yet another game
      do {
        // Get the game id
        $gid = $this->gameManager->getRandomGameId( $type);

        $output->writeln( 'Selected game id ' . $gid . ' depth: ' .
          $depth . ' label ' . $sideLabel);

        $gids[] = $gid;

        // will cause the QueueManagerCommandHandler to be called
        $this->bus->dispatch(new QueueManagerCommand( 'enqueue',
          ['game_id' => $gid, 'depth' => $depth, 'side_label' => $sideLabel]));

        // There can be a situation where many enqueue requests are submitted
        // while handler is still busy adding first request
        // Let us add small delay
        sleep(1);

      } while( $this->queueManager->countAnalysisNodes( 'Pending', true) < $threshold);

      $output->writeln( 'Loading :Game lines');

      // Request :Line load for the list of games
      $this->gameManager->loadLines( $gids, $userId);

      return 0;
    }
}
?>
