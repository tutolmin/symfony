<?php

// src/Command/UserMergeCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\UserManager;

class UserMergeCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'user:merge';

    // User nameger reference
    private $userManager;

    // Dependency injection of the UserManager service
    public function __construct( UserManager $um)
    {
        parent::__construct();

        $this->userManager = $um;
    }

    protected function configure()
    {
	$this

        // the short description shown while running "php bin/console list"
        ->setDescription('Syncronizes users in MySQL and Neo4j databases.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to create a neccessary Neo4j (:WebUser) nodes for existing Doctrine users.')
    ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
	// Call user manager service function
	$total = $this->userManager->mergeUsers();

	$output->writeln( $total. ' (:WebUser) nodes have been merged successfully');

        return 0;
    }
}

