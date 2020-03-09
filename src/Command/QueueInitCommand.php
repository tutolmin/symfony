<?php

// src/Command/QueueInitCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\QueueManager;

class QueueInitCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:init';

    // Queue manager reference
    private $queueManager;

    // Dependency injection of the Queue Manager service
    public function __construct( QueueManager $qm)
    {
        parent::__construct();

        $this->queueManager = $qm;
    }

    protected function configure()
    {
	$this

        // the short description shown while running "php bin/console list"
        ->setDescription('Initializes empty game analysis queue.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to create a neccessary Neo4j nodes for the game analysis queue.')
    ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
	// If analysys queue graph exists, return error
	if( $this->queueManager->queueGraphExists()) {

	  $output->writeln( 'Error! Analysis queue graph already exists!');

	  return 1;
	}
	
	// Init empty queue graph
	$this->queueManager->initQueue();

	$output->writeln( 'Analysis queue has been initialized successfully');

        return 0;
    }
}
?>
