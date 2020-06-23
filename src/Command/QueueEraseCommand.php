<?php

// src/Command/QueueEraseCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\QueueManager;
use Symfony\Component\Security\Guard\GuardAuthenticatorHandler;
use Symfony\Component\HttpFoundation\Request;
use App\Security\TokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;

class QueueEraseCommand extends Command
{
    const FIREWALL_MAIN = "main";

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:erase:all';

    // Queue manager reference
    private $queueManager;

    // Doctrine EntityManager
    private $em;

    // User repo
    private $userRepository;

    // Guard
    private $guardAuthenticatorHandler;

    // Dependency injection of the Queue manager service
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
        ->setDescription('Erases game analysis queue.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to erase all the Neo4j nodes and relationship associated with the game analysis queue.')

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

      // Initialize security context
      $this->guardAuthenticatorHandler->authenticateUserAndHandleSuccess(
        $this->userRepository->findOneBy(['id' => $_ENV['SYSTEM_WEB_USER_ID']]),
        new Request(),
        new TokenAuthenticator( $this->em),
        self::FIREWALL_MAIN
      );

      // Execute queue manager member function
      // This won't work on a bigger graph
      // It will run OOM
      if( $this->queueManager->eraseQueueGraph()) {

        $output->writeln( 'Analysis queue nodes and relationships have been deleted successfully!');

        // Init empty queue graph
        if( $this->queueManager->initQueueGraph())

          $output->writeln( 'Empty analysis queue has been initialized successfully');
      }

      return 0;
    }
}
?>
