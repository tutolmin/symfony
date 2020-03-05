<?php

// src/Command/QueueEraseCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\GameManager;
use App\Service\QueueManager;
use Psr\Log\LoggerInterface;

class QueueFillCommand extends Command
{
    // Default desired queue length
    const THRESHOLD = 20;
    const MAXLENGTH = 1000;

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:fill';

    // Queue/Game manager reference
    private $queueManager;
    private $gameManager;

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
        'depth',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify analysis depth',
        $_ENV['DEFAULT_ANALYSIS_DEPTH'] // Default
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
	if( $threshold > self::MAXLENGTH) $threshold=self::MAXLENGTH;

	$output->writeln( 'We are going to add '. $threshold. ' items...');

	// Check if queue is already full
	$length = $this->queueManager->getQueueLength();

	$output->writeln( 'Current analysis queue length = '. $length);

	// Queue length negative, no graph exists
	if( $length == -1) {

	  $output->writeln( 'Analysis queue does not exist. Exiting...');

          return 1;
	}

	// Current queue length is bigger than desired value.
	if( $length > $threshold) {

	  $output->writeln( 'Analysis queue already has '. $length. ' items. Exiting...');

          return 1;
	}

        // Get specified option
        $type = $input->getOption('type');

        // Default analysis parameters
        $sideLabel = ":WhiteSide:BlackSide";
        $depth = $_ENV['DEFAULT_ANALYSIS_DEPTH'];
        $userId = $_ENV['SYSTEM_WEB_USER_ID'];

        // Validate depth option
        $depthOption = intval( $input->getOption('depth'));
        if( $depthOption != 0) $depth = $depthOption;

        // Validate side option
        $sideToAnalyze = $input->getOption('side');
        if( $sideToAnalyze == "WhiteSide" || $sideToAnalyze == "BlackSide")
          $sideLabel = ":".$sideToAnalyze;

        $output->writeln( 'Side labels(s): '.$sideLabel.' depth: '.$depth);

	// Array of queued game ids
	$gids = array();

	// Add yet another game
	do {
          // Get the game id
          $gid = $this->gameManager->getRandomGameId( $type);

          $output->writeln( 'Selected game id ' . $gid);

	  // Enqueue game analysis node
	  if( $this->queueManager->queueGameAnalysis(
                $gid, $depth, $sideLabel, $userId))
	    $gids[] = $gid;	

	} while( $this->queueManager->getQueueLength() < $threshold);

        // Request :Line load for the list of games
        $this->gameManager->loadLines( $gids, $userId);

        return 0;
    }
}
?>
