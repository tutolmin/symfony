<?php

// src/Command/UserEraseCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use App\Service\UserManager;

class UserEraseCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'user:erase';

    // User nameger reference
    private $userManager;

    // Dependency injection of the UserManager service
    public function __construct(  UserManager $um)
    {
        parent::__construct();
    
        $this->userManager = $um;
    }

    protected function configure()
    {
	$this

        // the short description shown while running "php bin/console list"
        ->setDescription('Erases all (:WebUser) nodes.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to erase all Neo4j (:WebUser) nodes.')
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

        // Call user manager service function
        $total = $this->userManager->eraseUsers();

        $output->writeln( 'WebUser nodes and relationships have been deleted successfully!');

	return 0;
    }
}

?>
