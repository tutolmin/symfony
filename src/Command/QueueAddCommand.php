<?php

// src/Command/QueueAddCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\GameManager;
use App\Service\QueueManager;
use Psr\Log\LoggerInterface;

class QueueAddCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:add';

    private $logger;

    // Service references
    private $gameManager;
    private $queueManager;

    // Dependency injection of the GameManager service
    public function __construct( LoggerInterface $logger, GameManager $gm, QueueManager $qm)
    {
        $this->logger = $logger;

        parent::__construct();

        $this->gameManager = $gm;
        $this->queueManager = $qm;
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

	$output->writeln( 'Selected game id ' . $gid);

        // Default analysis parameters
        $sideLabel = ":WhiteSide:BlackSide";
        $depth = $_ENV['FAST_ANALYSIS_DEPTH'];
        $userId = $_ENV['SYSTEM_WEB_USER_ID'];

	// Validate depth option
	$depthOption = intval( $input->getOption('depth'));
	if( $depthOption != 0) $depth = $depthOption;

        // Validate side option
	$sideToAnalyze = $input->getOption('side');
        if( $sideToAnalyze == "WhiteSide" || $sideToAnalyze == "BlackSide")
          $sideLabel = ":".$sideToAnalyze;

	$output->writeln( 'Side labels(s): '.$sideLabel.' depth: '.$depth);

        // enqueue particular game 
        if( $this->queueManager->queueGameAnalysis(
                $gid, $depth, $sideLabel, $userId))

	  // Request :Line load for the list of games
	  $this->gameManager->loadLines( [$gid], $userId);

        return 0;
    }
}
?>
