<?php

// src/Command/QueueAddRandomCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Security\Guard\GuardAuthenticatorHandler;
use Symfony\Component\HttpFoundation\Request;
use App\Security\TokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use App\Service\GameManager;
use App\Message\QueueManagerCommand;
use Symfony\Component\Messenger\MessageBusInterface;

class QueueAddRandomCommand extends Command
{
    const FIREWALL_MAIN = "main";

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:add:random';

    // Service references
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
    public function __construct( GameManager $gm, EntityManagerInterface $em,
    GuardAuthenticatorHandler $gah, MessageBusInterface $bus)
    {
        parent::__construct();

        $this->gameManager = $gm;

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
        ->setDescription('Adds a random game into analysis queue.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to add a random game of certain type into analysis queue')
        // the full command description shown when running the command with
        // the "--help" option
        ->addOption(
        'random-user',
        null,
        InputOption::VALUE_OPTIONAL,
        'Whether random user id should be selected',
      	false // Default
        )
        ->addOption(
        'type',
        null,
        InputOption::VALUE_REQUIRED,
        'Please specify game type (checkmate, stalemate, 1-0, etc.)',
      	'checkmate' // Default
        )
        ->addOption(
        'depth',
        null,
        InputOption::VALUE_REQUIRED,
        'Please specify analysis depth',
      	'fast' // Default
        )
        ->addOption(
        'side',
        null,
        InputOption::VALUE_REQUIRED,
        'Please specify analysis side',
      	':WhiteSide:BlackSide' // Default
        )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
      	// Get specified option
      	$type = $input->getOption('type');

        // Get the game id
        $gid = $this->gameManager->getRandomGameId( $type);

        if( $gid == -1) {

          $output->writeln( 'Can not find matching game id.');
          return 1;
        }

        $output->writeln( 'Adding analysis for game id: ' . $gid);

        // Default analysis parameters
        $sideLabel = ":WhiteSide:BlackSide";
        $depth = 'fast';
        $userId = $_ENV['SYSTEM_WEB_USER_ID'];
        $user = null;

        // Get random user option
        $randomUser = $input->getOption('random-user');
        if( $randomUser === false)
        {
          // Get the user by id
          $user = $this->userRepository->findOneBy(['id' => $userId]);

        } else {

          // Get all active users
          $users = $this->userRepository->findAll();

          // Get random user
          $userId = rand( 0, count( $users)-1);
          $user = $users[$userId];
        }

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

        $output->writeln( 'Side labels(s): '.$sideLabel.
          ' depth: '.$depth.' user: '.$user->getEmail());

        // will cause the QueueManagerCommandHandler to be called
        $this->bus->dispatch(new QueueManagerCommand( 'enqueue',
          ['game_id' => $gid, 'depth' => $depth,
          'side_label' => $sideLabel, 'user_id' => $userId]));

        $output->writeln( 'Loading :Lines for :Game id: '.$gid);

        // Request :Line load for the list of games
        $this->gameManager->loadLines( [$gid]);

        return 0;
    }
}
?>
