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

    // Fetches single PGN file
    public function getPGN( $gid)
    {
        $this->logger->debug( 'Processing game ID: '.$gid);

        $this->stopwatch->start('getPGN');

        $game = $this->gameRepository->findOneById( $gid);

        $this->logger->debug('(:Game) hash: '.$game->getHash());

        $this->stopwatch->lap('getPGN');

        $line = $game->getLine();

        $this->logger->debug('(:Line) hash: '.$line->getHash());

        $this->stopwatch->lap('getPGN');

	$PGNstring = "";
        $PGNstring .= $this->fetcher->getFile( $game->getHash().'.pgn')->getContent();
        $PGNstring .= "\n"; // Tags should be delimited from moves

        $this->stopwatch->lap('getPGN');

        $PGNstring .= $this->fetcher->getFile( $line->getHash().'.pgn')->getContent();
        $PGNstring .= "\n";

        $this->stopwatch->stop('getPGN');

        return $PGNstring;
    }

    // Fetches the list of PGNs
    public function getPGNs( $gids)
    {
      $PGNstring = "";

      // Iterate through all the IDs
      foreach( $gids as $value)
        $PGNstring .= $this->getPGN( $value);

      return $PGNstring;
    }
}
?>
