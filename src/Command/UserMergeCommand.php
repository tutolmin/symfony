<?php

// src/Command/UserMergeCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\UserManager;
use Symfony\Component\Security\Guard\GuardAuthenticatorHandler;
use Symfony\Component\HttpFoundation\Request;
use App\Security\TokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;

class UserMergeCommand extends Command
{
    const FIREWALL_MAIN = "main";

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'user:merge:all';

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
        ->setDescription('Syncronizes users in MySQL and Neo4j databases.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to create a neccessary Neo4j (:WebUser) nodes for existing Doctrine users.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

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
      $total = $this->userManager->mergeAllUsers();

      $output->writeln( $total. ' (:WebUser) nodes have been merged successfully');

      return 0;
    }
}
?>
