<?php

// src/Command/QueueEvaluationSpeedCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\QueueManager;

class QueueEvaluationSpeedCommand extends Command
{
    // Default number of games to take
    const NUMBER = 10;

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:evaluation:speed';

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
        ->setDescription('Return evaluation speed.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to get a speed of evaluation for certain analysis depth for specified number of games')
        // the full command description shown when running the command with
        // the "--help" option
        ->addOption(
        'depth',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify analysis depth',
	$_ENV['FAST_ANALYSIS_DEPTH'] // Default
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
	// Get specified option
	$depth = $input->getOption('depth');

	// Get specified option
	$number = $input->getOption('number');

	// Call service method
	$speed = $this->queueManager->getEvaluationSpeed( $depth, $number);

	// Error
	if( $speed == -1) {

	  $output->writeln( 'Error while fetching evaluation speed');
	  return 1;
	}
	
	// Success
	$output->writeln( 'Current analysis speed for depth '.$depth.
		' based on '.$number.' games: ' . $speed . ' ms');
        return 0;
    }
}
?>
