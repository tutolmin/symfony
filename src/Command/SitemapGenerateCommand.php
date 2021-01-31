<?php

// src/Command/SitemapGenerateCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use Twig\Environment;
use App\Entity\SitemapHashes;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use App\Service\CacheFileFetcher;
use App\Service\GameManager;

class SitemapGenerateCommand extends Command
{
    const FIREWALL_MAIN = "main";
    const RECORDS_PER_FILE = 1000;

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'sitemap:generate';

    // Doctrine EntityManager
    private $em;

    // Game manager reference
    private $gameManager;

    // Hash repo
    private $hashRepository;

    private $twig;

    private $items = array();
    private $records = array();
    private $index;

    protected $parameterBag;

    // Logger reference
    private $logger;

    private $fetcher;

    // Guard
    private $guardAuthenticatorHandler;

    // Dependency injection of the necessary services
    public function __construct( EntityManagerInterface $em,
      Environment $twig, LoggerInterface $logger, ParameterBagInterface $parameterBag,
      CacheFileFetcher $fetcher, GameManager $gm)
    {
        parent::__construct();

        $this->logger = $logger;

        $this->em = $em;
        $this->gameManager = $gm;
        $this->twig = $twig;

        $this->fetcher = $fetcher;

        $this->parameterBag = $parameterBag;

        // get the Sitemap hash repository
        $this->hashRepository = $this->em->getRepository( SitemapHashes::class);
    }

    protected function configure()
    {
        $this
        // the short description shown while running "php bin/console list"
        ->setDescription('Generates sitemap index and data files.')

        // the full command description shown when running the command with
        // the "--help" option
        ->setHelp('This command allows you to generate sitemap index
        and sitemap data files for all analyzed games.')

        ->addOption(
        'index',
        null,
        InputOption::VALUE_OPTIONAL,
        'Please specify the sitemap index file to start with',
        0 // this is the new default value, instead of null
        )
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

        $output->writeln( 'Please confirm sitemap generation with --confirm option.');
        return 1;
      }

      // Parse the user specified index to start with
      $index = intval( $input->getOption('index'));

      // Make sure index is valid number
      if( $index < 0) $index = 0;

      $output->writeln( 'We are going to start with sitemap #'. $index. ' file');

      // Find all the hashes
//      $hashes = $this->hashRepository->findAll();
      // findBy (criteria, order, limit, offset)
      $hashes = $this->hashRepository->findBy( array(), array( 'id' => 'ASC'),
        null, $index * self::RECORDS_PER_FILE);

      if (!$hashes) {

        $output->writeln( 'No :Game hashes found');

        return 1;
      }

      // Iterate through all the hashes
      $counter = $index * self::RECORDS_PER_FILE;
      foreach ($hashes as $key => $hash) {

//        $output->writeln( "Working with :Game hash: ".$hash->getHash());

        // Check if the URL is OK (200)
        if( !$this->fetcher->checkPage( $hash->getHash())) {

          $output->writeln( "Static page for ".$hash->getHash()." is missing in SQUID cache");

          $this->gameManager->exportHTMLFile( $this->gameManager->gameIdByHash( $hash->getHash()));

          continue;
        }

        // Store hash in array
        $this->items[] = $hash->getHash();
        $this->records[] = $key;

        // Flush a bunch of record into a new file
        if( $counter++ % self::RECORDS_PER_FILE == self::RECORDS_PER_FILE - 1) {

          $this->index = $counter / self::RECORDS_PER_FILE;

          $output->writeln( "Flushing: ".count($this->items)." index: ".$this->index);

          // Flush accumulated hashes into a file
          $this->flushHashes();

          // Empty hashes array
          $this->items = [];
          $this->records = [];

          // Sleep to keep SQUID responsible to our queries
          sleep( 3);
        }
      }

      // Flush the rest of hashes
      if( count( $this->items)>0) {

        $this->index++;

        $output->writeln( "Flushing: ".count($this->items)." index: ".$this->index);

        $this->flushHashes();
      }

      $output->writeln( "Flushing index file: ".
        $this->parameterBag->get('kernel.project_dir').'/public/sitemap_index.xml');

      // Prepare array of files
      $files = array();
      for($i=0;$i<$this->index;$i++)
        $files[] = $i+1;

      // generate index file
      $xmlContents = $this->twig->render('sitemap/index.xml.twig', [
          'files' => $files,
      ]);

      $filesystem = new Filesystem();
      try {

        $filesystem->dumpFile( $this->parameterBag->get('kernel.project_dir').
          '/public/sitemap_index.xml', $xmlContents);

      } catch (IOExceptionInterface $exception) {

        $this->logger->debug( "An error occurred while writing sitemap index file ".$exception->getPath());
      }

      return 0;
    }

    // Function to save hash array to the file
    private function flushHashes() {

      $this->logger->debug( "Current index: ".$this->index);

      // TODO: Store date for each url in the DB and fetch it from there.
      $xmlContents = $this->twig->render('sitemap/data.xml.twig', [
          'date' => date("Y-m-d"),
          'hashes' => $this->items,
          'records' => $this->records,
      ]);
/*
      $filesystem = new Filesystem();
      $tmp_file = '';
      try {

        // Temporary filename
        $tmp_file = $filesystem->tempnam('/tmp', 'sitemap-');

        // Save the XML data into a local temp file
        file_put_contents( $tmp_file, $xmlContents);

      } catch (IOExceptionInterface $exception) {

        $this->logger->debug( "An error occurred while creating a temp file ".$exception->getPath());
      }

      try {

    	  // Copy the file to a special upload directory
    	  $filesystem->rename( $tmp_file, $tmp_file."-".$this->index.".xml");

      } catch (IOExceptionInterface $exception) {

        $this->logger->debug( "An error occurred while moving a temp file ".$exception->getPath());
      }
*/
      // Compress data
//      $data = implode("", $xmlContents);
      $gzdata = gzencode($xmlContents, 9);
      $fp = fopen($this->parameterBag->get('kernel.project_dir').
        "/public/sitemap".$this->index.".xml.gz", "w");
      fwrite($fp, $gzdata);
      fclose($fp);
    }
}
?>
