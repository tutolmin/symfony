<?php

// src/Command/QueuePromoteRandomCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\QueueManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Guard\GuardAuthenticatorHandler;
use Symfony\Component\HttpFoundation\Request;
use App\Security\TokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;

class QueuePromoteRandomCommand extends Command
{
    const FIREWALL_MAIN = "main";

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:promote:random';

    private $logger;

    // Service references
    private $queueManager;

    // Doctrine EntityManager
    private $em;

    // User repo
    private $userRepository;

    // Guard
    private $guardAuthenticatorHandler;

    // Dependency injection of the GameManager service
    public function __construct( LoggerInterface $logger, QueueManager $qm,
	EntityManagerInterface $em, GuardAuthenticatorHandler $gah)
    {
        $this->logger = $logger;

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
        ->setDescription('Promotes a random analysis in the queue.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to promote a random analysis of certain type in the queue')
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

	// Get the analysis id, Pending by default
	$aid = $this->queueManager->getRandomAnalysisNode( 'Pending');

	$output->writeln( 'Promoting analysis id ' . $aid);

        // Get the system user by email
        $userId = $_ENV['SYSTEM_WEB_USER_ID'];
        $user = $this->userRepository->findOneBy(['id' => $userId]);

	$this->guardAuthenticatorHandler->authenticateUserAndHandleSuccess(
            $user,
            new Request(),
            new TokenAuthenticator( $this->em),
            self::FIREWALL_MAIN
        );

	// Promote analysis
        if( !$this->queueManager->promoteAnalysis( $aid))

	  $output->writeln( 'Analysis has NOT been promoted');

        return 0;
    }
}
?>
