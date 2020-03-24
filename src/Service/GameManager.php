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
        $this->logger->debug( "Memory usage (".memory_get_usage().")");

        $this->logger->debug( "Checking for game existance");

        $query = 'MATCH (g:Game) WHERE id(g) = {gid}
RETURN id(g) AS gid LIMIT 1';

        $params = ["gid" => intval( $gid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('gid') != null)
            return true;

        // Return 
        return false;
    }

    // get the total number of Games in the DB
    public function getGamesTotal()
    {
        if( $_ENV['APP_DEBUG'])
          $this->logger->debug( "Memory usage (".memory_get_usage().")");

        $counter = 0;

        $result = $this->neo4j_client->run( 'MATCH (g:Game) RETURN count(g) AS ttl', null);

        foreach ($result->records() as $record)
          if( $record->value('ttl') != null)
            $counter = $record->value('ttl');

        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Total games = ' .$counter);

	return $counter;
    }

    // get number of Games of certain type and length
    public function getGamesNumber( $type = "", $plycount = 80)
    {
        $this->logger->debug( "Memory usage (".memory_get_usage().")");

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
        $this->logger->debug( "Memory usage (".memory_get_usage().")");

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
        	$query .= '(p:GamePlyCount) WITH p 
MATCH (p)<-[:GAME_HAS_LENGTH]-(:Line)<-[:FINISHED_ON]-(g:Game) WITH p,g
MATCH (g)-[:ENDED_WITH]->(:Result:Win)<-[:ACHIEVED]-(:Side:White)';
		break;
	  case "0-1":
        	$query .= '(p:GamePlyCount) WITH p 
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
        $this->logger->debug( "Memory usage (".memory_get_usage().")");

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
        	$query .= '(p:GamePlyCount) WITH p 
MATCH (p)<-[:GAME_HAS_LENGTH]-(:Line)<-[:FINISHED_ON]-(g:Game) WITH p,g
MATCH (g)-[:ENDED_WITH]->(:Result:Win)<-[:ACHIEVED]-(:Side:White)';
		break;
	  case "0-1":
        	$query .= '(p:GamePlyCount) WITH p 
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

        $this->logger->debug('Minimum ply counter for game type '.
		$type.' = '.$counter);

	// Storing the value in cache
	apcu_add( $cacheVarName, $counter, 3600);

	return $counter;
    }

    // get random game
    public function getRandomGameId( $type = "")
    {
        $this->logger->debug( "Memory usage (".memory_get_usage().")");

	$skip = 0;
	do {

	// Select a game plycount
        $params["counter"] = rand( $this->getMinPlyCount( $type), 
				$this->getMaxPlyCount( $type));

        $this->logger->debug('Selected game plycount '.$params["counter"]);

	// Get total games for a certain plycount
	} while( ($skip = $this->getGamesNumber( $type, $params["counter"])) == 0);

        // Use SKIP to get a pseudo random game
	if( $skip > 1000) $skip = 1000; else $skip--;
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
        $this->logger->debug( "Memory usage (".memory_get_usage().")");

        // Checks if game exists
        if( !$this->gameExists( $gid))
	  return false;

        $this->logger->debug( "Checking move line existance");

        $query = 'MATCH (g:Game) WHERE id(g) = {gid}
MATCH (g)-[:FINISHED_ON]->(l:Line) WITH g,l LIMIT 1
MATCH path=shortestPath((l)-[:ROOT*0..]->(r:Root))
RETURN length(path) AS length LIMIT 1';

        $params = ["gid" => intval( $gid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('length') != null)
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

//      $this->logger->debug( "Fetched games: ". $PGNstring);

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

    // Exports single JSON file for a game
    public function exportJSONFile( $gid)
    {
      // Checks if the move line for a game exists
      if( !$this->lineExists( $gid)) return false;

      // Fetch movelist, ECOs
      $query = 'MATCH (g:Game) WHERE id(g) = {gid}
MATCH (g)-[:FINISHED_ON]->(l:Line) WITH l LIMIT 1
MATCH path=shortestPath((l)-[:ROOT*0..]->(r:Root)) WITH l, nodes(path) AS moves 
UNWIND moves AS move 
 MATCH (move)-[:LEAF]->(p:Ply) 
 OPTIONAL MATCH (move)-[:COMES_TO]-(:Position)-[:KNOWN_AS]->(o:Opening)-[:PART_OF]->(e:EcoCode) 
  WITH l,
   reverse( collect( id(move))) AS lids, 
   reverse( collect( p.san)) AS movelist, 
   reverse( collect(COALESCE(e.code,""))) AS ecos,
   reverse( collect(COALESCE(o.opening,""))) AS openings,
   reverse( collect(COALESCE(o.variation,""))) AS variations,
   reverse( collect(CASE WHEN "Forced" IN labels(move) THEN "Forced" ELSE "" END)) AS marks
RETURN l.hash, lids, movelist, ecos, openings, variations, marks LIMIT 1';

      $params = ["gid" => intval( $gid)];
      $result = $this->neo4j_client->run($query, $params);

      // If there is no record or movelist empty, exit immediately
      $record = $result->records()[0];
      if( $record->value('movelist') == null) return false;

      // Arrays
      $lids		= $record->value('lids');
      $moves		= $record->value('movelist');
      $keys		= array_keys( $lids);
      $ecos		= array_combine( $keys, $record->value('ecos')); 
      $openings		= array_combine( $keys, $record->value('openings')); 
      $variations	= array_combine( $keys, $record->value('variations')); 
      $marks		= array_combine( $keys, $record->value('marks')); 

      // Go through all the SANs, build huge array
      $arr = array();
      foreach( $lids AS $key => $lid) {

	$move_score_idx	= -1;
	$best_score_idx	= -1;
	$var_start_id	= -1;
	$best_eval_id	= -1;

	// Push move SAN as first element	
	$item = array();
	$item['san'] = $moves[$key];

        $this->logger->debug( "Fetching eval data for ". $item['san']);

	// Add eco info for respective moves
	if( strlen( $ecos[$key]))
	  $item['eco'] = $ecos[$key];
	if( strlen( $openings[$key]))
	  $item['opening'] = $openings[$key];
	if( strlen( $variations[$key]))
	  $item['variation'] = $variations[$key];
	if( strlen( $marks[$key]))
	  $item['mark'] = $marks[$key];

	// Get best evaluation for the current move
	if( $best_eval = $this->getBestEvaluation( $lid)) {

	  $item['depth'] = $best_eval['depth'];
	  $item['time']  = $best_eval['time'];
	  $item['score'] = $best_eval['score'];
	  $move_score_idx = $best_eval['idx'];
	}

        // No need to check for alternative moves if we have forced line
        if( !array_key_exists( 'mark', $item) || $item['mark'] != "Forced") {

          // Get best evaluation for the alternative moves
          $query = 'MATCH (l:Line)<-[:ROOT]-(cl:Line) WHERE id(cl) = {node_id}
OPTIONAL MATCH (l)<-[:ROOT]-(vl:Line)-[:HAS_GOT]->(v:Evaluation)-[:RECEIVED]->(score:Score)
RETURN score.idx, id(vl) AS var_start_id, id(v) AS best_eval_id
ORDER BY score.idx LIMIT 1';

          $this->logger->debug( $lid);

      	  $params_b = ["node_id" => intval( $lid)];
          $result_b = $this->neo4j_client->run( $query, $params_b);

          // Get Best Move data. Save :Score idx for later comparison
          foreach ($result_b->records() as $record_b) {
            $best_score_idx = $record_b->value('score.idx');
            $var_start_id = $record_b->value('var_start_id');
            $best_eval_id = $record_b->value('best_eval_id');
          }
        }

        $this->logger->debug( "Current move score idx: ". $move_score_idx.
		" best move score idx: ".$best_score_idx);

        // Actual move better than or equal to the best line score
        if( $best_score_idx >= $move_score_idx)

          $item['mark'] = "Best";

        else {  // If the scores do NOT match add best variations of top 3 alternative lines

	  $query = 'PROFILE MATCH (l:Line)<-[:ROOT]-(cl:Line) WHERE id(cl) = {node_id}
OPTIONAL MATCH (l)<-[:ROOT]-(vl:Line) WHERE cl <> vl
OPTIONAL MATCH (vl)-[:HAS_GOT]->(e:Evaluation)-[:RECEIVED]->(s:Score)
WITH s,vl ORDER BY s.idx WITH collect( DISTINCT vl) AS lines WITH lines[0..3] AS slice
UNWIND slice AS node
MATCH (node)-[:LEAF]->(ply:Ply)
RETURN ply.san, id(node) AS node_id';

      	  $params_a = ["node_id" => intval( $lid)];
          $result_a = $this->neo4j_client->run( $query, $params_a);

          // Get Best Move data. Save :Score idx for later comparison
	  $alt = array();
          foreach ($result_a->records() as $record_a) {

	    $Titem['san'] = $record_a->value('ply.san');
	    if( $alt_eval = $this->getBestEvaluation( $record_a->value('node_id'))) {

	      $Titem['depth'] = $alt_eval['depth'];
	      $Titem['time']  = $alt_eval['time'];
	      $Titem['score'] = $alt_eval['score'];
	    }

	    array_push( $alt, $Titem);
          }

	  // Only add alt key if there is something
	  if( count( $alt) > 0) $item['alt'] = $alt;

        }

	// If array has only SAN add a value, not array
	if( count( $item) == 1)
	  array_push( $arr, $item['san']);
	else
	  array_push( $arr, $item);
      }
/*
echo json_encode( $arr);
die;
*/
      $filesystem = new Filesystem();
      try {

        // Filename SHOULD contain 'evals' prefix in order to make sure
        // the filename is never matches 'games|lines' prefixes
        $tmp_file = $filesystem->tempnam('/tmp', 'evals-');

        // Save the PGNs into a local temp file
        file_put_contents( $tmp_file, json_encode( $arr));

      } catch (IOExceptionInterface $exception) {

        $this->logger->debug( "An error occurred while creating a temp file ".$exception->getPath());
      }

      // Put the file into special uploads directory
      $this->uploader->uploadEvals( $tmp_file, $record->value('l.hash'));

      return true;
    }

    // Format evaluation time
    private function formatEvaluationTime( $minute, $second, $msec) 
    {
      return str_pad( $minute, 2, '0', STR_PAD_LEFT) . ":" .
             str_pad( $second, 2, '0', STR_PAD_LEFT) . "." .
             str_pad( $msec,   3, '0', STR_PAD_LEFT);
    }

    // Format evaluation score
    private function formatEvaluationScore( $scoreLabelsArray)
    {
      if( in_array( "MateLine", $scoreLabelsArray))
        return "M";
      else if( in_array( "Pawn", $scoreLabelsArray))
        return "P";
      return "";
    }

    // Get evaluation data for the best move in the position
    private function getBestEvaluation( $node_id)
    {
      // Get best :Evaluation :Score for the actual game move
      $query = 'MATCH (line:Line) WHERE id(line) = {node_id}
OPTIONAL MATCH (line)-[:HAS_GOT]->(evaluation:Evaluation)-[:RECEIVED]->(score:Score)
OPTIONAL MATCH (seldepth:SelDepth)<-[:REACHED]-(evaluation)-[:REACHED]->(depth:Depth)
OPTIONAL MATCH (evaluation)-[:TOOK]->(second:Second)-[:OF]->(minute:Minute)-[:OF]->(hour:Hour)
OPTIONAL MATCH (msec:MilliSecond)<-[:TOOK]-(evaluation)
RETURN score, depth.level, seldepth.level, minute.minute, second.second, msec.ms
ORDER BY score.idx LIMIT 1';

      $params_e = ["node_id" => intval( $node_id)];
      $result_e = $this->neo4j_client->run( $query, $params_e);

      // Fetch actual evaluation data
      $record_e = $result_e->records()[0];
      if( $record_e->value('depth.level') == null) return null;

      $eval_data['depth']	= $record_e->value('depth.level');
      $eval_data['seldepth']	= $record_e->value('seldepth.level');
      $eval_data['time']	= $this->formatEvaluationTime( 
		$record_e->value('minute.minute'),
		$record_e->value('second.second'), 
		$record_e->value('msec.ms'));

      // Skip if null returned
      $scoreObj = $record_e->get('score');

      // Save :Score idx for later comparison
      $eval_data['idx'] = $scoreObj->value('idx');

      // Parse :Score labels
      $eval_data['score'] = $this->formatEvaluationScore(
        $scoreObj->labels()) . $scoreObj->value('score');

      return $eval_data;
    }
}
?>
