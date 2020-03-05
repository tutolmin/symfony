<?php

// src/Command/QueueAddCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\GameManager;
use App\Service\QueueManager;

class QueueAddCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:add';

    // Service references
    private $gameManager;
    private $queueManager;

    // Dependency injection of the GameManager service
    public function __construct( GameManager $gm, QueueManager $qm)
    {
        parent::__construct();

        $this->gameManager = $gm;
        $this->queueManager = $qm;
    }

    protected function configure()
    {
	$this

        // the short description shown while running "php bin/console list"
        ->setDescription('Adds a random game into analysis queue.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to add a random game of certain type into analysis queue')
        // the full command description shown when running the command with
        // the "--help" option
        ->addOption(
        'type',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify game type (checkmate, stalemate, etc.)',
	'' // Default
        )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
	// Get specified option
	$type = $input->getOption('type');

	// Get the game id
	$gid = $this->gameManager->getRandomGameId( $type);

	$output->writeln( 'Selected game id ' . $gid);

        return 0;
    }
}

?>
