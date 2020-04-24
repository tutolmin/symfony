<?php

// src/Command/QueueAddRandomCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\GameManager;
use App\Service\QueueManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Guard\GuardAuthenticatorHandler;
use Symfony\Component\HttpFoundation\Request;
use App\Security\TokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;

class QueueAddRandomCommand extends Command
{
    const FIREWALL_MAIN = "main";

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:add:random';

    private $logger;

    // Service references
    private $gameManager;
    private $queueManager;

    // Doctrine EntityManager
    private $em;

    // User repo
    private $userRepository;

    // Guard
    private $guardAuthenticatorHandler;

    // Dependency injection of the GameManager service
    public function __construct( LoggerInterface $logger, GameManager $gm, QueueManager $qm,
	EntityManagerInterface $em, GuardAuthenticatorHandler $gah)
    {
        $this->logger = $logger;

        parent::__construct();

        $this->gameManager = $gm;
        $this->queueManager = $qm;

        $this->em = $em;

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
	$_ENV['FAST_ANALYSIS_DEPTH'] // Default
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
	// Get specified option
	$type = $input->getOption('type');

	// Get the game id
	$gid = $this->gameManager->getRandomGameId( $type);

	$output->writeln( 'Adding analysis for game id ' . $gid);

        // Default analysis parameters
        $sideLabel = ":WhiteSide:BlackSide";
        $depth = $_ENV['FAST_ANALYSIS_DEPTH'];
        $userId = $_ENV['SYSTEM_WEB_USER_ID'];

        // Get the user by email
        $user = $this->userRepository->findOneBy(['id' => $userId]);

	// Get all active users
        $users = $this->userRepository->findAll();

	// Get random user
	$userId = rand( 0, count( $users)-1);
	$user = $users[$userId];

	$this->guardAuthenticatorHandler->authenticateUserAndHandleSuccess(
            $user,
            new Request(),
            new TokenAuthenticator( $this->em),
            self::FIREWALL_MAIN
        );

	// Validate depth option
	$depthOption = intval( $input->getOption('depth'));
	if( $depthOption != 0) $depth = $depthOption;

        // Validate side option
	$sideToAnalyze = $input->getOption('side');
        if( $sideToAnalyze == "WhiteSide" || $sideToAnalyze == "BlackSide")
          $sideLabel = ":".$sideToAnalyze;

	$output->writeln( 'Side labels(s): '.$sideLabel.
		' depth: '.$depth.' user: '.$user->getEmail());

        // enqueue particular game 
        if( ($aid = $this->queueManager->enqueueGameAnalysis(
                $gid, $depth, $sideLabel)) != -1) {

	  $output->writeln( 'New analysis id: '.$aid);

	  // Request :Line load for the list of games
	  $this->gameManager->loadLines( [$gid], $userId);
	}

        return 0;
    }
}
?>
