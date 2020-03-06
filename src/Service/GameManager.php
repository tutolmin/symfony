<?php

// src/Service/GameManager.php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use App\Service\PGNFetcher;
use App\Service\PGNUploader;
use GraphAware\Neo4j\Client\ClientInterface;
use App\Entity\Game;

class GameManager
{
    private $logger;

    // Neo4j client interface reference
    private $neo4j_client;

    // PGN fetcher/uploader
    private $fetcher;
    private $uploader;

    // Game repo
    private $userRepository;

    public function __construct( ClientInterface $client, LoggerInterface $logger,
	PGNFetcher $fetcher, PGNUploader $uploader)
    {
        $this->logger = $logger;
        $this->neo4j_client = $client;
        $this->fetcher = $fetcher;
        $this->uploader = $uploader;
    }

    // Find :Game node in the database
    public function gameExists( $gid)
    {
        $this->logger->debug( "Checking for game existance");

        $query = 'MATCH (g:Game)
WHERE id(g) = {gid}
RETURN id(g) AS gid LIMIT 1';

        $params = ["gid" => intval( $gid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('gid') != "null")
            return true;

        // Return 
        return false;
    }

    // get total number of Games of certain type and length
    public function getGamesTotal( $type = "", $plycount = 80)
    {
        $counter = 0;
	$cacheVarName = 'gamesTotal'.$type.$plycount;

	// Check if the value is in the cache
	if( apcu_exists( $cacheVarName))
	  $counter = apcu_fetch( $cacheVarName);

	// Return counter, stored in the cache
	if( $counter > 0) { 
          $this->logger->debug('Total games of type '.
	    $type. ' and length '.$plycount.' = ' .$counter. ' (cached)');
	  return $counter;
	}

        $params["counter"] = intval( $plycount);

        $query = 'MATCH (:PlyCount{counter:{counter}})-[:LONGER*0..]->';

	switch( $type) {
	  case "checkmate":
        	$query .= '(p:CheckMatePlyCount) WITH p LIMIT 1
MATCH (p)<-[:CHECKMATE_HAS_LENGTH]-(:CheckMate)<-[:FINISHED_ON]-(g:Game)';
		break;
	  case "stalemate":
        	$query .= '(p:StaleMatePlyCount) WITH p LIMIT 1
MATCH (p)<-[:STALEMATE_HAS_LENGTH]-(:StaleMate)<-[:FINISHED_ON]-(g:Game)';
		break;
	  case "1-0":
        	$query .= '(p:GamePlyCount) WITH p LIMIT 1
MATCH (p)<-[:GAME_HAS_LENGTH]-(:Line)<-[:FINISHED_ON]-(g:Game) WITH g
MATCH (g)-[:ENDED_WITH]->(:Result:Win)<-[:ACHIEVED]-(:Side:White)';
		break;
	  case "0-1":
        	$query .= '(p:GamePlyCount) WITH p LIMIT 1
MATCH (p)<-[:GAME_HAS_LENGTH]-(:Line)<-[:FINISHED_ON]-(g:Game) WITH g
MATCH (g)-[:ENDED_WITH]->(:Result:Win)<-[:ACHIEVED]-(:Side:Black)';
		break;
	  default:
        	$query .= '(p:GamePlyCount) WITH p LIMIT 1
MATCH (p)<-[:GAME_HAS_LENGTH]-(:Line)<-[:FINISHED_ON]-(g:Game)';
		break;
	}

	$query .= ' RETURN count(g) AS ttl LIMIT 1';

        $result = $this->neo4j_client->run( $query, $params);

        foreach ($result->records() as $record)
          $counter = $record->value('ttl');

        $this->logger->debug('Total games of type '.
		$type. ' and length '.$plycount.' = ' .$counter);
	
	// Storing the value in cache
	apcu_add( $cacheVarName, $counter, 3600);

	return $counter;
    }

    // get maximum ply count for a certain game type
    private function getMaxPlyCount( $type = "")
    {
	$counter=0;
	$cacheVarName = 'maxPlyCount'.$type;

	// Check if the value is in the cache
	if( apcu_exists( $cacheVarName))
	  $counter = apcu_fetch( $cacheVarName);

	// Return counter, stored in the cache
	if( $counter > 0) { 
          $this->logger->debug('Maximum ply counter for game type '.
	    $type.' = '.$counter. ' (cached)');
	  return $counter;
	}

        $query = 'MATCH (:PlyCount{counter:999})<-[:LONGER*0..]-';

	switch( $type) {
	  case "checkmate":
        	$query .= '(p:CheckMatePlyCount)';
		break;
	  case "stalemate":
        	$query .= '(p:StaleMatePlyCount)';
		break;
	  case "1-0":
        	$query .= '(p:GamePlyCount) WITH p LIMIT 1
MATCH (p)<-[:GAME_HAS_LENGTH]-(:Line)<-[:FINISHED_ON]-(g:Game) WITH p,g
MATCH (g)-[:ENDED_WITH]->(:Result:Win)<-[:ACHIEVED]-(:Side:White)';
		break;
	  case "0-1":
        	$query .= '(p:GamePlyCount) WITH p LIMIT 1
MATCH (p)<-[:GAME_HAS_LENGTH]-(:Line)<-[:FINISHED_ON]-(g:Game) WITH p,g
MATCH (g)-[:ENDED_WITH]->(:Result:Win)<-[:ACHIEVED]-(:Side:Black)';
		break;
	  default:
        	$query .= '(p:GamePlyCount)';
		break;
	}

	$query .= " RETURN p.counter as counter LIMIT 1";

        $result = $this->neo4j_client->run( $query, null);

        foreach ($result->records() as $record)
          $counter = $record->value('counter');

        $this->logger->debug('Maximum ply counter for game type '.
		$type.' = '.$counter);

	// Storing the value in cache
	apcu_add( $cacheVarName, $counter, 3600);

	return $counter;
    }

    // get minimum ply count for a certain game type
    private function getMinPlyCount( $type = "")
    {
	$counter=0;
	$cacheVarName = 'minPlyCount'.$type;

	// Check if the value is in the cache
	if( apcu_exists( $cacheVarName))
	  $counter = apcu_fetch( $cacheVarName);

	// Return counter, stored in the cache
	if( $counter > 0) { 
          $this->logger->debug('Maximum ply counter for game type '.
	    $type.' = '.$counter. ' (cached)');
	  return $counter;
	}

        $query = 'MATCH (:PlyCount{counter:0})-[:LONGER*0..]->';

	switch( $type) {
	  case "checkmate":
        	$query .= '(p:CheckMatePlyCount)';
		break;
	  case "stalemate":
        	$query .= '(p:StaleMatePlyCount)';
		break;
	  case "1-0":
        	$query .= '(p:GamePlyCount) WITH p LIMIT 1
MATCH (p)<-[:GAME_HAS_LENGTH]-(:Line)<-[:FINISHED_ON]-(g:Game) WITH p,g
MATCH (g)-[:ENDED_WITH]->(:Result:Win)<-[:ACHIEVED]-(:Side:White)';
		break;
	  case "0-1":
        	$query .= '(p:GamePlyCount) WITH p LIMIT 1
MATCH (p)<-[:GAME_HAS_LENGTH]-(:Line)<-[:FINISHED_ON]-(g:Game) WITH p,g
MATCH (g)-[:ENDED_WITH]->(:Result:Win)<-[:ACHIEVED]-(:Side:Black)';
		break;
	  default:
        	$query .= '(p:GamePlyCount)';
		break;
	}

	$query .= " RETURN p.counter as counter LIMIT 1";

        $this->logger->debug( $query);

        $result = $this->neo4j_client->run( $query, null);

        foreach ($result->records() as $record)
          $counter = $record->value('counter');

        $this->logger->debug('Minimum ply counter for game type '.
		$type.' = '.$counter);

	// Storing the value in cache
	apcu_add( $cacheVarName, $counter, 3600);

	return $counter;
    }

    // get random game
    public function getRandomGameId( $type = "")
    {
	// Select a game plycount
        $params["counter"] = rand( $this->getMinPlyCount( $type), 
				$this->getMaxPlyCount( $type));

        $this->logger->debug('Selected game plycount '.$params["counter"]);

	// Get total games for a certain plycount
	$skip = $this->getGamesTotal( $type, $params["counter"])-1;
	if( $skip > 1000) $skip = 1000;

        // Use SKIP to get a pseudo random game
        $params["SKIP"] = rand( 0, $skip);

        $this->logger->debug('Skipping '.$params["SKIP"]. ' games');

        $query = 'MATCH (:PlyCount{counter:{counter}})-[:LONGER*0..]->';

	switch( $type) {
	  case "checkmate":
        	$query .= '(p:CheckMatePlyCount)
MATCH (p)<-[:CHECKMATE_HAS_LENGTH]-(:CheckMate)<-[:FINISHED_ON]-(g:Game)';
		break;
	  case "stalemate":
        	$query .= '(p:StaleMatePlyCount)
MATCH (p)<-[:STALEMATE_HAS_LENGTH]-(:StaleMate)<-[:FINISHED_ON]-(g:Game)';
		break;
	  case "1-0":
        	$query .= '(p:GamePlyCount)
MATCH (p)<-[:GAME_HAS_LENGTH]-(:Line)<-[:FINISHED_ON]-(g:Game) WITH g
MATCH (g)-[:ENDED_WITH]->(:Result:Win)<-[:ACHIEVED]-(:Side:White)';
		break;
	  case "0-1":
        	$query .= '(p:GamePlyCount) 
MATCH (p)<-[:GAME_HAS_LENGTH]-(:Line)<-[:FINISHED_ON]-(g:Game) WITH g
MATCH (g)-[:ENDED_WITH]->(:Result:Win)<-[:ACHIEVED]-(:Side:Black)';
		break;
	  default:
        	$query .= '(p:GamePlyCount)
MATCH (p)<-[:GAME_HAS_LENGTH]-(:Line)<-[:FINISHED_ON]-(g:Game)';
		break;
	}

	$query .= " RETURN id(g) as id SKIP {SKIP} LIMIT 1";

        $result = $this->neo4j_client->run( $query, $params);

	$gid=0;
        foreach ($result->records() as $record) {
          $gid = $record->value('id');
        }

        $this->logger->debug('Selected game id '.$gid);

	return $gid;
    }

    // Check if the move line has been already loaded for a game
    public function lineExists( $gid)
    {
        $this->logger->debug( "Checking move line existance");

        $query = 'MATCH (g:Game) WHERE id(g) = {gid}
MATCH (g)-[:FINISHED_ON]->(l:Line)
MATCH (r:Line{hash:"00000000"})
MATCH path=(l)-[:ROOT*0..]->(r)
RETURN length(path) AS length LIMIT 1';

        $params = ["gid" => intval( $gid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('length') != "null")
            return true;

	return false;
    }

    // Merge :Lines for the :Games into the DB
    public function loadLines( $gids, $wuid)
    {
      // Game ids to load
      $gameIds = array();

      // Check if the game line has been already loaded
      foreach( $gids as $gid)
	if( !$this->lineExists( $gid))
	  $gameIds[] = $gid;

      $this->logger->debug( "Game ids to fetch: ". implode( ",", $gameIds));

      // Exit if array is empty
      if( count( $gameIds) == 0) return 0;

      // Fetch the games from the cache
      $PGNstring = $this->fetcher->getPGNs( $gameIds);

      $this->logger->debug( "Fetched games: ". $PGNstring);

      $filesystem = new Filesystem();
      try {

        // Filename SHOULD contain 'lines' prefix in order to make sure
        // the filename is never matches 'games' prefix, reserved for :Game-only db merge
        $tmp_file = $filesystem->tempnam('/tmp', 'lines-'.$wuid.'-');

        // Save the PGNs into a local temp file
        file_put_contents( $tmp_file, $PGNstring);

      } catch (IOExceptionInterface $exception) {

        $this->logger->debug( "An error occurred while creating a temp file ".$exception->getPath());
      }

      // Put the file into special uploads directory
      $this->uploader->uploadLines( $tmp_file);
    }
}

?>

