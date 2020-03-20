<?php

// src/Command/QueueWaitTimeCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\QueueManager;

class QueueWaitTimeCommand extends Command
{
    // Default number of games to take
    const NUMBER = 10;

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:wait:time';

    // Service references
    private $queueManager;

    // Dependency injection of the GameManager service
    public function __construct( QueueManager $qm)
    {
        parent::__construct();

        $this->queueManager = $qm;
    }

    protected function configure()
    {
	$this

        // the short description shown while running "php bin/console list"
        ->setDescription('Return median wait time.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to get a median wait time for specified number of recently evaluated games')
        // the full command description shown when running the command with
        // the "--help" option
        ->addOption(
        'type',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify the aggregate function: average or median',
	'median' // Default
        )
        ->addOption(
        'number',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify the number of games to take into account',
	self::NUMBER // Default
        )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
	// Get specified type
	$type = $input->getOption('type');

	// Get specified number
	$number = $input->getOption('number');

	$wait = $this->queueManager->getQueueWaitTime( $type, $number);

	$output->writeln( 'Current '.$type.' wait time based on '.
		$number.' of games: ' . $wait . ' sec');

        return 0;
    }
}
?>
