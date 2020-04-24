<?php

// src/Command/QueueChangeRandomCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\GameManager;
use App\Service\QueueManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Guard\GuardAuthenticatorHandler;
use Symfony\Component\HttpFoundation\Request;
use App\Security\TokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;

class QueueChangeRandomCommand extends Command
{
    const FIREWALL_MAIN = "main";

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:change:random';

    private $logger;

    // Service references
    private $gameManager;
    private $queueManager;

    // Doctrine EntityManager
    private $em;

    // User repo
    private $userRepository;

    // Guard
    private $guardAuthenticatorHandler;

    // Dependency injection of the GameManager service
    public function __construct( LoggerInterface $logger, GameManager $gm, QueueManager $qm,
	EntityManagerInterface $em, GuardAuthenticatorHandler $gah)
    {
        $this->logger = $logger;

        parent::__construct();

        $this->gameManager = $gm;
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
        ->setDescription('Changes a random analysis in the the queue.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to change random analysis parameters in analysis queue')
        // the full command description shown when running the command with
        // the "--help" option
        ->addArgument(
        'param',
        InputArgument::REQUIRED,
        'Please specify parameter to change (side|type|status)'
        )
        ->addArgument(
        'value',
        InputArgument::REQUIRED,
        'Please specify new parameter value'
        )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
	// Get specified parameter name
	$param = $input->getArgument('param');

	// Valid parameter values
	$params = ['side', 'status', 'type'];
	if( !in_array( $param, $params)) {

	  $output->writeln( 'Invalid parameter: '.$param);
	  return -1;
	}

	// Get specified parameter value
	$value = $input->getArgument('value');

	// Valid parameters values
	$statuses = ['Pending','Processing','Partially',
	        'Skipped','Evaluated','Exported','Complete'];

	$sides = ['WhiteSide', 'BlackSide', 'Both'];

	$types = ['fast', 'deep'];

	// Valid parameter values
	if( ($param == 'status' && !in_array( $value, $statuses)) ||
	    ($param == 'side' && !in_array( $value, $sides)) ||
	    ($param == 'type' && !in_array( $value, $types))) {

	  $output->writeln( 'Invalid parameter name/value: '.$param.'/'.$value);
	  return -1;
	}

        // Get the user by email
        $userId = $_ENV['SYSTEM_WEB_USER_ID'];
        $user = $this->userRepository->findOneBy(['id' => $userId]);

	$this->guardAuthenticatorHandler->authenticateUserAndHandleSuccess(
            $user,
            new Request(),
            new TokenAuthenticator( $this->em),
            self::FIREWALL_MAIN
        );

	// Get specific node for certain parameters

	// We change first Skipped for Pending
	if( $param == 'status' && $value == 'Pending') {
	  $aid = $this->queueManager->getStatusQueueNode( 'Skipped', 'first');
	
	  if( $aid == -1)
	    $aid = $this->queueManager->getStatusQueueNode( 'Partially', 'first');

	  if( $aid == -1)
	    $aid = $this->queueManager->getStatusQueueNode( 'Evaluated', 'first');
	}

	// We fetch first Pending to change status for Processing
	else if( $param == 'status' && $value == 'Processing')
	  $aid = $this->queueManager->getStatusQueueNode( 'Pending', 'first');

	// We fetch first Processing to change status for Evaluated
	else if( $param == 'status' && $value == 'Evaluated')
	  $aid = $this->queueManager->getStatusQueueNode( 'Processing', 'first');

	else 

          // Get random analysis id
          $aid = $this->queueManager->getRandomAnalysisNode();

	$output->writeln( 'Changing '.$param.' to: '.$value.' for '. $aid);

	$result = false;
        if( $param == 'status')
          $result = $this->queueManager->promoteAnalysis( $aid, $value);
        else if( $param == 'side')
	  $result = $this->queueManager->setAnalysisSide( $aid, $value);
        else if( $param == 'type')
	  $result = $this->queueManager->setAnalysisDepth( $aid, $value);

	if( !$result)
	  $output->writeln( 'Error changing parameters');

        return 0;
    }
}
?>
