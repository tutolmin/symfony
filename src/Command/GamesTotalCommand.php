<?php

// src/Command/GamesTotalCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\GameManager;

class GamesTotalCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'games:total';

    // Game manager reference
    private $gameManager;

    // Dependency injection of the GameManager service
    public function __construct( GameManager $gm)
    {
        parent::__construct();

        $this->gameManager = $gm;
    }

    protected function configure()
    {
        $this
        // the short description shown while running "php bin/console list"
        ->setDescription('Returns total number of games.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to get the total number of games from the DB.')

        ->addOption(
        'type',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify game type (checkmate, stalemate, etc.)',
        'checkmate' // Default
        )
	;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Get specified option
//        $type = $input->getOption('type');

        // Get the number of games
        $number = $this->gameManager->getGamesTotal();

        $output->writeln( 'Total number of games: ' . $number);

        return 0;
    }
}
?>
