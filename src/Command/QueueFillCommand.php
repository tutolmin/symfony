<?php

// src/Command/QueueEraseCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\QueueManager;

class QueueFillCommand extends Command
{
    // Default desired queue length
    const THRESHOLD = 20;
    const MAXLENGTH = 1000;

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:fill';

    // Queue manager reference
    private $queueManager;

    // Dependency injection of the Queue manager service
    public function __construct( QueueManager $qm)
    {
        parent::__construct();

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

	// Execute queue manager member function	
//	$this->queueManager->eraseQueue();

//	$output->writeln( 'Analysis queue nodes and relationships have been deleted successfully!');

        return 0;
    }
}

