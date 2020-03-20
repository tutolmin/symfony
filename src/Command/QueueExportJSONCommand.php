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
    const NUMBER = 20;

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
	;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
	// Parse the user specified number of games
	$number = intval( $input->getOption('number'));

	// Cap the user input with reasonable max value
	if( $number > self::NUMBER) $number=self::NUMBER;

	$output->writeln( 'We are going to export '. $number. ' items...');

	// Array of exported game ids
	$gids = array();

	// Export list of games ids
        $gids = $this->queueManager->getAnalysisGameIds( "Evaluated", $number);

        $output->writeln( 'Selected Analysis game ids ' . implode( ',', $gids));

        // Request JSON files update for the list of games
//        $this->gameManager->exportJSONFiles( $gids);

        return 0;
    }
}
?>
