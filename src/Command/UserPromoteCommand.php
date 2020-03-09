<?php

// src/Command/UserPromoteCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use App\Service\UserManager;

class UserPromoteCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'user:promote';

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
        ->setDescription('Adds a new role to a user.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to assign a new role to a user.')
        ->addArgument(
        'email',
        InputArgument::REQUIRED, 
        'Please specify user email address'
	)
        ->addArgument(
        'role',
        InputArgument::REQUIRED, 
        'Please specify a role starting with ROLE_'
	)
	;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Check if the user email was specified
        $emailValue = $input->getArgument('email');

	// Email validation
        if( strpos( $emailValue, '@') === false) {

          $output->writeln( 'Email address should contain @ symbol.');

          return 1;
        }

        $roleValue = $input->getArgument('role');

        // Call user manager service function
        if( !$this->userManager->promoteUser( $emailValue, $roleValue)) {

          $output->writeln( 'Error while assigning a user role!');

          return 1;
	}

        $output->writeln( 'New role has been assigned successfully!');

	return 0;
    }
}
?>
