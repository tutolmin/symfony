<?php

// src/Command/QueueDeleteRandomCommand.php

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
use App\Message\QueueManagerCommand;
use Symfony\Component\Messenger\MessageBusInterface;

class QueueDeleteRandomCommand extends Command
{
    const FIREWALL_MAIN = "main";

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:delete:random';

    // Service references
    private $queueManager;

    // Doctrine EntityManager
    private $em;

    // Message bus
    private $bus;

    // User repo
    private $userRepository;

    // Guard
    private $guardAuthenticatorHandler;

    // Dependency injection of the GameManager service
    public function __construct( QueueManager $qm,
      EntityManagerInterface $em, GuardAuthenticatorHandler $gah,
      MessageBusInterface $bus)
    {
        parent::__construct();

        $this->queueManager = $qm;

        $this->em = $em;

        $this->bus = $bus;

        // get the User repository
        $this->userRepository = $this->em->getRepository( User::class);

        $this->guardAuthenticatorHandler = $gah;
    }

    protected function configure()
    {
        $this

        // the short description shown while running "php bin/console list"
        ->setDescription('Deletes a random analysis in the queue.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to delete a random analysis of certain type in the queue')
        // the full command description shown when running the command with
        // the "--help" option
        ->addOption(
        'status',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify analysis status (Pending, Complete, etc.)',
        'Pending' // Default
        )
        ->addOption(
        'type',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify analysis type (deep/fast)',
        'deep' // Default
        )
        ->addOption(
        'side',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify analysis side',
        'BlackSide' // Default
        )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
	     /* specific type, status, side selection is not available atm */

       // Get the analysis id
       $aid = $this->queueManager->getRandomAnalysisNode();

       $output->writeln( 'Erasing analysis id: ' . $aid);

       // Get the system user by email
       $userId = $_ENV['SYSTEM_WEB_USER_ID'];
       $user = $this->userRepository->findOneBy(['id' => $userId]);

       $this->guardAuthenticatorHandler->authenticateUserAndHandleSuccess(
         $user,
         new Request(),
         new TokenAuthenticator( $this->em),
         self::FIREWALL_MAIN
       );

       // will cause the QueueManagerCommandHandler to be called
       $this->bus->dispatch(new QueueManagerCommand( 'delete', ['analysis_id' => $aid]));

       return 0;
    }
}
?>
