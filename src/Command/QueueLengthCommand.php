<?php

// src/Command/QueueLengthCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\QueueManager;

class QueueLengthCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:length';

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
        ->setDescription('Returns game analysis queue length.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to get game analysis queue length.')
        ->addOption(
        'status',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify analysis status',
        'Pending' // Default
        )
    ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Get specified option
        $status = $input->getOption('status');

	// Execute queue manager member function	
	$length = $this->queueManager->countAnalysisNodes( $status, true);

        $output->writeln( 'Current '.$status.' analysis queue length is: '.$length);

        return 0;
    }
}
?>
