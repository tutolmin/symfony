<?php

// src/Command/QueueExportJSONCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\GameManager;
use App\Service\QueueManager;
use Psr\Log\LoggerInterface;

class QueueExportJSONCommand extends Command
{
    // Maximum number of games to export
    const NUMBER = 200;

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:export:json';

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
        ->setDescription('Exports evaluated games from analysis queue.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('Exports games that have been already processed by evaluator into JSON files for upload to the cache.')

	// option to confirm the graph deletion
	->addOption(
        'number',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify the number of games to export',
        self::NUMBER // this is the new default value, instead of null
	)
	// specific game id
	->addOption(
        'gid',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify valid game id to export',
        -1 // this is the new default value, instead of null
        )
	;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
	// Parse the user specified game id
	$gid = intval( $input->getOption('gid'));

	// Game id has been specified, process it
	if( $gid != -1) {

          $output->writeln( 'Selected analysis game id: ' . $gid);

	  // Get available depth for each side
	  $depths = $this->queueManager->getGameAnalysisDepths( $gid);

          // Request JSON files update for the game
          $this->gameManager->exportJSONFile( $gid, $depths);

	// How to select processed but not processing games?
	} else if ( false) {
	
	// Parse the user specified number of games
	$number = intval( $input->getOption('number'));

	// Cap the user input with reasonable max value
	if( $number > self::NUMBER) $number=self::NUMBER;

	$output->writeln( 'We are going to export '. $number. ' items...');

	// Iterate
	while( $number-- > 0) {

	  // Get first matching analysis node with Evaluated status
	  $aid = $this->queueManager->getStatusQueueNode( "Complete");

          $output->writeln( 'Selected analysis id: '. $aid);

	  // No more analysis found, exit
	  if( $aid == -1) break;
	
	  // Get game ID for analyis
          $gid = $this->queueManager->getAnalysisGameId( $aid);

	  // Error fetching game Id for analysis
	  if( $gid == -1) {

            $output->writeln( 'Can not fetch game id for analysis');

	    // We need to mark it somehow, otherwise we will fetch it again
	    $this->queueManager->promoteAnalysis( $aid, "Skipped");

	    continue;
	  }

          $output->writeln( 'Selected analysis game id: ' . $gid);

	  // Fetch analysis depth
	  $depths = $this->queueManager->getGameAnalysisDepths( $gid);

          // Request JSON files update for the game
          if( $this->gameManager->exportJSONFile( $gid, $depths))

	    // Mark the Analysis so we do not fetch it again
	    $this->queueManager->promoteAnalysis( $aid, "Complete");
 	  else
	    $this->queueManager->promoteAnalysis( $aid, "Skipped");
	}
	}
        return 0;
    }
}
?>
