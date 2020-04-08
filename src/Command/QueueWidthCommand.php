<?php

// src/Command/QueueWidthCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\QueueManager;

class QueueWidthCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:width';

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
        ->setDescription('Returns game analysis queue width.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to get game analysis queue width.')

        ->addOption(
        'type',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify analysis depth',
        'fast' // Default
        )
    ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Get specified option
        $type = $input->getOption('type');

	// Execute queue manager member function	
	$depth = $this->queueManager->getQueueWidth( $type);

        $output->writeln( 'Current '.$type.' analysis queue width: '.$depth);

        return 0;
    }
}
?>
