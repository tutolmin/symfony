<?php

// src/Service/PGNFetcher.php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use App\Service\CacheFileFetcher;
use GraphAware\Neo4j\OGM\EntityManagerInterface;
use App\Entity\Game;

class PGNFetcher
{
    private $logger;
    private $fetcher;
    private $manager;
    private $gameRepository;

    // StopWatch instance
    private $stopwatch;

    public function __construct( Stopwatch $watch, LoggerInterface $logger,
	    CacheFileFetcher $fetcher, EntityManagerInterface $manager)
    {
        $this->stopwatch = $watch;
        $this->logger = $logger;
        $this->fetcher = $fetcher;
        $this->manager = $manager;

        // get the game repository
        $this->gameRepository = $this->manager->getRepository( Game::class);
    }

    // invalidate local cache file
    public function invalidateLocalCache( $filename)
    {

      // Make sure local copy of the file is invalidated locally
      $this->fetcher->invalidateLocalCache( $filename);
    }

    // Fetches single PGN file
    public function getPGN( $hash)
    {
        $this->stopwatch->start('getPGN');

//        $game = $this->gameRepository->findOneById( $gid);
        $game = $this->gameRepository->findOneBy( ['hash' => $hash]);

        if( !$game) {

          if( $_ENV['APP_DEBUG'])
            $this->logger->debug( 'Game hash has not been found: '.$hash);

          return '';  // Game has not been found
        }

        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('(:Game) hash: '.$game->getHash());

        $this->stopwatch->lap('getPGN');

        $line = $game->getLine();
        if( !$line) {

          if( $_ENV['APP_DEBUG'])
            $this->logger->debug( '(:Line) has not been found');

          return '';
        }

        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('(:Line) hash: '.$line->getHash());

        $this->stopwatch->lap('getPGN');

	      $PGNstring = "";
        $PGNstring .= $this->fetchPGN( $game->getHash().'.pgn');

        $this->stopwatch->lap('getPGN');

        $PGNstring .= $this->fetchPGN( $line->getHash().'.pgn');

        $this->stopwatch->stop('getPGN');

        return $PGNstring;
    }


    // Get specific file from cache
    public function fetchPGN( $filename) {

      $PGNstring = "";
      $PGNstring .= $this->fetcher->getFile( $filename)->getContent();
      $PGNstring .= "\n"; // Tags should be delimited from moves

      return $PGNstring;
    }


    // Fetches the list of PGNs
    public function getPGNs( $hashes)
    {
      $PGNstring = "";

      // Iterate through all the IDs
      if( is_array( $hashes))
        foreach( $hashes as $hash)
          $PGNstring .= $this->getPGN( $hash);

      return $PGNstring;
    }
}
?>
