<?php

// src/Command/QueueEraseCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\QueueManager;

class QueueEraseCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:erase';

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
        ->setDescription('Erases game analysis queue.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to erase all the Neo4j nodes and relationship associated with the game analysis queue.')

	// option to confirm the graph deletion
	->addOption(
        'confirm',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please confirm the graph deletion',
        false // this is the new default value, instead of null
    )
    ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
	// Check if the user confirmed the graph deletion
	$optionValue = $input->getOption('confirm');
	$confirm = ($optionValue !== false);

	if( !$confirm) {

	  $output->writeln( 'Please confirm graph deletion with --confirm option.');

          return 1;
	}

	// Execute queue manager member function	
	$this->queueManager->eraseQueue();

	$output->writeln( 'Analysis queue nodes and relationships have been deleted successfully!');

        return 0;
    }
}

