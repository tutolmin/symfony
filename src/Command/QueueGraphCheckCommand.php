<?php

// src/Command/QueueGraphCheckCommand.php

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
use App\Entity\Analysis;

class QueueGraphCheckCommand extends Command
{
    const FIREWALL_MAIN = "main";

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:graph:check';

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
        ->setDescription('Checks game analysis queue for validity.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to make various validity checks on the game analysis queue.')

	// option to confirm the graph deletion
	->addOption(
        'qid',
        null,
        InputOption::VALUE_OPTIONAL,
        'Queue id to start check from',
        -1
        )

	// option to confirm the graph deletion
	->addOption(
        'confirm',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please confirm the graph check execution',
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

	  $output->writeln( 'Please confirm graph validity check with --confirm option.');

          return 1;
	}

	// Queue node id to start check from
	$qid = $input->getOption('qid');

        // Initialize security context
        $this->guardAuthenticatorHandler->authenticateUserAndHandleSuccess(
            $this->userRepository->findOneBy(['id' => $_ENV['SYSTEM_WEB_USER_ID']]),
            new Request(),
            new TokenAuthenticator( $this->em),
            self::FIREWALL_MAIN
        );

	// Fetching queue tail
	$tail_id = $this->queueManager->getQueueNode( 'Tail');
	if( $tail_id == -1) {
	  $output->writeln( 'Error fetching :Queue:Tail');
	  return 1;
	}
	$output->writeln( ':Queue:Tail id: '.$tail_id);

	// Fetching queue current node
	$current_id = $this->queueManager->getQueueNode( 'Current');
	if( $current_id == -1) {
	  $output->writeln( 'Error fetching :Queue:Current');
	  return 1;
	}
	$output->writeln( ':Queue:Current id: '.$current_id);

	// Fetching queue head node
	$head_id = $this->queueManager->getQueueNode( 'Head');
	if( $head_id == -1) {
	  $output->writeln( 'Error fetching :Queue:Head');
	  return 1;
	}
	$output->writeln( ':Queue:Head id: '.$head_id);

	// If queue id has been specified, start from it.
	$node_id = $head_id;
	if( $this->queueManager->getQueueNode( $qid) != -1)	
	  $node_id = $qid;

	$counter = 0;
if( true)
	while( $node_id != -1) {

	  $output->writeln( 'Checking validity of the: '. $node_id);

	  // It should have single FIRST rel
	  $number = $this->queueManager->getQueueNodeItems( 'FIRST');
	  if( $number != 1) {
	    $output->writeln( 'Queue node has '.$number.' FIRST items.');
//	    break;
	  }

	  // It should have single LAST rel
	  $number = $this->queueManager->getQueueNodeItems( 'LAST');
	  if( $number != 1) {
	    $output->writeln( 'Queue node has '.$number.' LAST items.');
//	    break;
	  }

	  // It should have at least one QUEUED rels
	  $number = $this->queueManager->getQueueNodeItems();
	  if( $number == 0) {
	    $output->writeln( 'Queue node has no QUEUED items.');
	    break;
	  }

	  // Increment counter
	  $counter++;

	  // Get next node id
	  $node_id = $this->queueManager->getQueueNode();
	}
	$output->writeln( 'Validated '. $counter. ' :Queue nodes.');

	// Get total number of :Queue nodes
	$total = $this->queueManager->countQueueNodes();
	if( $counter != $total) {

	  $output->writeln( 'Total number of validated nodes do NOT match '.
		'the number of :Queue nodes in the db: '. $total);
	}

	// Check status queues consistency
	foreach( Analysis::STATUS as $status) {

	  $first_id = $this->queueManager->getStatusQueueNode( $status, 'first');
	  $last_id = $this->queueManager->getStatusQueueNode( $status, 'last');
	
	  $output->writeln( $status.' first node id: '.$first_id.
		' last node id: '.$last_id);

	  if( ($first_id == -1 && $last_id != -1) 
		|| ($first_id != -1 && $last_id == -1))
	    $output->writeln( $status.' status queue inconsistent!'); 
	}

        return 0;
    }
}
?>
