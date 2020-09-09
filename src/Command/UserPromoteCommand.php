<?php

// src/Command/UserPromoteCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use App\Service\UserManager;
use Symfony\Component\Security\Guard\GuardAuthenticatorHandler;
use Symfony\Component\HttpFoundation\Request;
use App\Security\TokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;

class UserPromoteCommand extends Command
{
    const FIREWALL_MAIN = "main";

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'user:promote';

    // User nameger reference
    private $userManager;

    // Doctrine EntityManager
    private $em;

    // User repo
    private $userRepository;

    // Guard
    private $guardAuthenticatorHandler;

    // Dependency injection of the UserManager service
    public function __construct(  UserManager $um,
    EntityManagerInterface $em, GuardAuthenticatorHandler $gah)
    {
        parent::__construct();

        $this->userManager = $um;

        $this->em = $em;

        // get the User repository
        $this->userRepository = $this->em->getRepository( User::class);

        $this->guardAuthenticatorHandler = $gah;
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

        // Get the system user by email
        $userId = $_ENV['SYSTEM_WEB_USER_ID'];
        $user = $this->userRepository->findOneBy(['id' => $userId]);

        if( $user === null) {

                $output->writeln( 'Error! Check system user config');
                return 1;
        }

        $this->guardAuthenticatorHandler->authenticateUserAndHandleSuccess(
              $user,
              new Request(),
              new TokenAuthenticator( $this->em),
              self::FIREWALL_MAIN
        );

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
