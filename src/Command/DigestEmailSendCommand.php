<?php

// src/Command/DigestEmailSendCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use App\Service\QueueManager;

class DigestEmailSendCommand extends Command
{
    const FIREWALL_MAIN = "main";

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'email:digest:send';

    // Doctrine EntityManager
    private $em;

    // Queue manager reference
    private $queueManager;

    // User repo
    private $userRepository;

    // Guard
    private $guardAuthenticatorHandler;

    // Dependency injection of the Queue manager service
    public function __construct( QueueManager $qm, EntityManagerInterface $em)
    {
        parent::__construct();

        $this->queueManager = $qm;

        $this->em = $em;

        // get the User repository
        $this->userRepository = $this->em->getRepository( User::class);
    }

    protected function configure()
    {
        $this
        // the short description shown while running "php bin/console list"
        ->setDescription('Send digest email to all users.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to send a digest of
        complete analyses to all the users.')

        // option to confirm the graph deletion
        ->addOption(
        'confirm',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please confirm the email dispatch',
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

        $output->writeln( 'Please confirm email dispatch with --confirm option.');
        return 1;
      }

      // Find all the users
      $users = $this->userRepository->findAll();
      if (!$users) {

        $output->writeln( 'No users found');

        return 1;
      }

      // Iterate through all the users
      foreach ($users as $user) {

        // Get the list of complete analises for a user
        $analyses = $user->getCompleteAnalyses();

        // Skip if no complete analyses have been found
        if( count( $analyses) == 0) continue;

        // Get ids of selected analyses
        $aids = array();
        foreach ($analyses as $analysis) {
          
          // Remove analysis record from the database
          $this->em->remove( $analysis);

          // Do not add duplicates
          $aid = $analysis->getAnalysisId();
          if( in_array( $aid, $aids)) continue;

          $aids[] = $analysis->getAnalysisId();
        }

        // Actually execute delete statements
        $this->em->flush();

        $output->writeln( "Sending for user id: ".$user->getId().
          ", number of analyses: ".count($analyses)." (".implode(',', $aids).")");

        // Dispatch an email
        $this->queueManager->dispatchEmail( $user, $aids);
      }

      return 0;
    }
}
?>
