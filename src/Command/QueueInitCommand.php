<?php

// src/Command/QueueInitCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\QueueManager;
use Symfony\Component\Security\Guard\GuardAuthenticatorHandler;
use Symfony\Component\HttpFoundation\Request;
use App\Security\TokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;

class QueueInitCommand extends Command
{
    const FIREWALL_MAIN = "main";

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:init';

    // Queue manager reference
    private $queueManager;

    // Doctrine EntityManager
    private $em;

    // User repo
    private $userRepository;

    // Guard
    private $guardAuthenticatorHandler;

    // Dependency injection of the Queue Manager service
    public function __construct( QueueManager $qm,
      EntityManagerInterface $em, GuardAuthenticatorHandler $gah)
    {
        parent::__construct();

        $this->queueManager = $qm;

        $this->em = $em;

        // get the User repository
        $this->userRepository = $this->em->getRepository( User::class);

        $this->guardAuthenticatorHandler = $gah;
    }

    protected function configure()
    {
      $this

      // the short description shown while running "php bin/console list"
      ->setDescription('Initializes empty game analysis queue.')

      // the full command description shown when running the command with
      // the "--help" option
      ->setHelp('This command allows you to create a neccessary Neo4j nodes for the game analysis queue.')
      ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
      // Initialize security context
      $this->guardAuthenticatorHandler->authenticateUserAndHandleSuccess(
        $this->userRepository->findOneBy(['id' => $_ENV['SYSTEM_WEB_USER_ID']]),
        new Request(),
        new TokenAuthenticator( $this->em),
        self::FIREWALL_MAIN
      );

      // Init empty queue graph
      if( $this->queueManager->initQueueGraph())

        $output->writeln( 'Empty analysis queue has been initialized successfully');

	     else

        $output->writeln( 'Analysis queue already exists');

      return 0;
    }
}
?>
