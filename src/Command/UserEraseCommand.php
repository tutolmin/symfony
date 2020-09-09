<?php

// src/Command/UserEraseCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use App\Service\UserManager;
use Symfony\Component\Security\Guard\GuardAuthenticatorHandler;
use Symfony\Component\HttpFoundation\Request;
use App\Security\TokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
class UserEraseCommand extends Command
{
    const FIREWALL_MAIN = "main";

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'user:erase:all';

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
        $total = $this->userManager->eraseUsers();

        $output->writeln( 'WebUser nodes and relationships have been deleted successfully!');

	      return 0;
    }
}
?>
