<?php

// src/Command/QueueSyncStatusCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\QueueManager;
//use App\Service\GameManager;
use Symfony\Component\Security\Guard\GuardAuthenticatorHandler;
use Symfony\Component\HttpFoundation\Request;
use App\Security\TokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use App\Entity\Analysis;

class QueueSyncStatusCommand extends Command
{
    // Maximum number of items to process
    const NUMBER = 5;
    const FIREWALL_MAIN = "main";

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'queue:sync:status';

    // Queue/Game manager reference
    private $queueManager;
//    private $gameManager;

    // Doctrine EntityManager
    private $em;

    // User repo
    private $userRepository;

    // Guard
    private $guardAuthenticatorHandler;

    // Dependency injection of the Queue manager service
    public function __construct( QueueManager $qm,
//    GameManager $gm,
    	EntityManagerInterface $em, GuardAuthenticatorHandler $gah)
    {
        parent::__construct();

        $this->queueManager = $qm;
  //      $this->gameManager = $gm;

        $this->em = $em;

        // get the User repository
        $this->userRepository = $this->em->getRepository( User::class);

        $this->guardAuthenticatorHandler = $gah;
    }

    protected function configure()
    {
        $this
        // the short description shown while running "php bin/console list"
        ->setDescription('Syncs analysis status property and rels.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to take analysis node status property and set status.')
        // option to confirm the graph deletion
        ->addOption(
        'number',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify the number of nodess to process',
        self::NUMBER // this is the new default value, instead of null
        )
      	// option to select status
      	->addOption(
        'status',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify status to sync',
        'Pending'
        )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Parse the user specified number of games
        $number = intval( $input->getOption('number'));

        // Cap the user input with reasonable max value
        if( $number > self::NUMBER) $number=self::NUMBER;

        $output->writeln( 'We are going to process '. $number. ' items...');

      	// get status from command line
      	$optionValue = $input->getOption('status');

      	// Some output
      	$output->writeln( 'Syncing analysis status '. $optionValue);

        // Initialize security context
        $this->guardAuthenticatorHandler->authenticateUserAndHandleSuccess(
            $this->userRepository->findOneBy(['id' => $_ENV['SYSTEM_WEB_USER_ID']]),
            new Request(),
            new TokenAuthenticator( $this->em),
            self::FIREWALL_MAIN
        );

	      // Iterate
        while( $number-- > 0)

        	// Execute queue manager member function
        	if( ($aid = $this->queueManager->syncAnalysisStatus( $optionValue)) != -1) {
/*
            $status = $this->queueManager->getAnalysisStatus( $aid);
	          if( $status == -1) continue;
*/
        	  $output->writeln( 'Analysis node '.$aid.' status sync complete!');
/*
          // Complete analysis needs JSON export
          if( Analysis::STATUS[$status] == 'Complete') {

            // Get game ID for analyis
            $gid = $this->queueManager->getAnalysisGameId( $aid);

            // Get available depth for each side
            $depths = $this->queueManager->getGameAnalysisDepths( $gid);

            // Request JSON files update for the game
            $this->gameManager->exportJSONFile( $gid, $depths);

            // Request HTML file export for the game
            $this->gameManager->exportHTMLFile( $gid);

            // Send notification message
            $this->queueManager->notifyUser( $aid);
	        }
*/
	      }

      return 0;
    }
}
?>
