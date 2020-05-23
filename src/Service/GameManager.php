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

        $query = 'MATCH (:PlyCount{counter:0})-[:LONGER*0..999]->';

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
	$params["counter"] = 0;
	do {

	  // If there are ANY games of this type
	  if( $this->getGamesNumber( $type, 0) == 0)
	    break;

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

	$gid=-1;
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
      $tmp_file = '';
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
    public function exportJSONFile( $gid, $sides_depth)
    {
      // Checks if the move line for a game exists
      if( !$this->lineExists( $gid)) return false;

      // Array to keep analysis counters
      $Totals = array();

      // Fetch movelist, ECOs, Forced labels
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
      $plycount		= count( $moves);
      $keys		= array_keys( $lids);
      $ecos		= array_combine( $keys, $record->value('ecos'));
      $openings		= array_combine( $keys, $record->value('openings'));
      $variations	= array_combine( $keys, $record->value('variations'));
      $marks		= array_combine( $keys, $record->value('marks'));

      // Go through all the SANs, build huge array
      $arr = array();
      $deltas = array();

      $Totals = array(
	'White' => array(
		'plies' => 0,
		't1' => 0,
		't2' => 0,
		't3' => 0,
		'ecos' => 0,
		'forced' => 0,
		'best' => 0,
		'sound' => 0,
		'analyzed' => 0,
		),
	'Black' => array(
		'plies' => 0,
		't1' => 0,
		't2' => 0,
		't3' => 0,
		'ecos' => 0,
		'forced' => 0,
		'best' => 0,
		'sound' => 0,
		'analyzed' => 0,
		)
		);
      $effectiveResult = ['White' => 'EffectiveDraw', 'Black' => 'EffectiveDraw'];
      $prev_ply_eval_idx = -1;

      // Go through all the game moves
      foreach( $lids as $key => $lid) {

        $this->logger->debug( "Line id: ". $lid);

	// Switch side/depth based on ply number
	$side = 'White';
	if( $key % 2) $side = 'Black';
	$depth = $sides_depth[$side];
//	if( $depth == 0) continue;

	// We will need it to decide later if it was best move
	$move_score_idx	= -1;

	// Push move SAN as first element
	$item = array();
	$item['san'] = $moves[$key];

	// total plies counter
	$Totals[$side]['plies']++;

	// Add eco info for respective moves
	if( strlen( $ecos[$key])) {
	  $item['eco'] = $ecos[$key];
	  $Totals[$side]['ecos']++;
	}
	if( strlen( $openings[$key]))
	  $item['opening'] = $openings[$key];
	if( strlen( $variations[$key]))
	  $item['variation'] = $variations[$key];

	// We can only fetch Forced mark from DB
	if( strlen( $marks[$key])) {
	  $item['mark'] = $marks[$key];
	  $Totals[$side]['forced']++;
	}

        $this->logger->debug( "Fetching eval data for ". $item['san']);

	// Get best evaluation for the current move
	if( $best_eval = $this->getBestEvaluation( $lid, $depth)) {

	  $item['depth'] = $best_eval['depth'];
	  $item['time']  = $best_eval['time'];
	  $item['score'] = $best_eval['score'];
	  $move_score_idx = $best_eval['idx'];
	}

	// Final move, store effective result
	if( $key == $plycount-1) {

	  // Valid eval data present
	  if( $move_score_idx != -1) {

	    $effectiveResult['White'] = $this->getEffectiveResult( $move_score_idx, $key);

	  // Consider previous move
	  } else {

	    $effectiveResult['White'] = $this->getEffectiveResult( $prev_ply_eval_idx, $key - 1);
	  }

	  // Set opposite result for Black
	  if( $effectiveResult['White'] == 'EffectiveWin')
	    $effectiveResult['Black'] = 'EffectiveLoss';
	  else if( $effectiveResult['White'] == 'EffectiveLoss')
	    $effectiveResult['Black'] = 'EffectiveWin';
        }
	$prev_ply_eval_idx = $move_score_idx;

        // No need to check for alternative moves if we have forced line or book move
        if( (!array_key_exists( 'mark', $item) || $item['mark'] != "Forced") &&
		!array_key_exists( 'eco', $item) && $depth != 0) {

          $this->logger->debug( "Fetching alternatives for ". $lid);

	  // Find top 3 alternative lines
	  $query = 'MATCH (l:Line)<-[:ROOT]-(cl:Line) WHERE id(cl) = {node_id}
MATCH (l)<-[:ROOT]-(vl:Line) WHERE cl <> vl
MATCH (vl)-[:HAS_GOT]->(e:Evaluation)-[:RECEIVED]->(s:Score)
MATCH (e)-[:REACHED]->(d:Depth) WHERE d.level >= {depth}
WITH vl ORDER BY s.idx WITH collect( DISTINCT vl) AS lines WITH lines[0..3] AS slice
UNWIND slice AS node RETURN id(node) AS node_id';

      	  $params_a = ["node_id" => intval( $lid), "depth" => intval( $depth)];
          $result_a = $this->neo4j_client->run( $query, $params_a);

          // Go through all alternative lines root nodes
	  $alt = array();
	  $T_scores = array();
          foreach ($result_a->records() as $record_a) {

	    $alt_eval = $this->getBestEvaluation( $record_a->value('node_id'), $depth);

	    // Push alternative move score into array
	    $T_scores[] = $alt_eval['idx'];

            $this->logger->debug( "Alternative move score idx: ". $alt_eval['idx'].
		" for node id: ". $record_a->value('node_id'));

	    // Fetch all proposed nodes for alternative line
	    $query = 'MATCH (l:Line) WHERE id(l) = {node_id}
MATCH (l)-[:HAS_GOT]->(e:Evaluation)-[:RECEIVED]->(s:Score{idx:{idx}})
MATCH (e)-[:REACHED]->(d:Depth) WHERE d.level >= {depth} WITH l,e LIMIT 1
OPTIONAL MATCH (e)-[:PROPOSED]->(pl:Line)
OPTIONAL MATCH path=shortestPath((l)<-[:ROOT*0..11]-(pl)) WITH l,nodes(path) AS nodes
WITH CASE WHEN nodes IS NULL THEN l ELSE nodes END AS list
UNWIND list AS node
MATCH (node)-[:LEAF]->(ply:Ply)
RETURN ply.san, id(node) AS node_id';

      	    $params_v = ["node_id" => intval( $record_a->value('node_id')),
			"idx" => intval( $alt_eval['idx']),
			"depth" => intval( $depth)];
            $result_v = $this->neo4j_client->run( $query, $params_v);

	    // Add all variation line moves
	    $line = array();
            foreach ($result_v->records() as $record_v) {

	      // Always store SAN
	      $var['san'] = $record_v->value('ply.san');

              $this->logger->debug( "Fetching eval data for variation ". $var['san']);

	      // Optionally add evaluations
	      if( $var_eval = $this->getBestEvaluation( $record_v->value('node_id'), $depth)) {

	        $var['depth'] = $var_eval['depth'];
	        $var['time']  = $var_eval['time'];
	        $var['score'] = $var_eval['score'];

	      } else {

	        $var['depth'] = 0;
	        $var['time']  = 0;
	        $var['score'] = 0;
	      }

	      // Add either SAN only or eval array
	      if( $var['depth'] > 0) array_push( $line, $var);
	      else array_push( $line, $var['san']);
            }

	    // Only add line key if there is something
	    if( count( $line) > 0) array_push( $alt, $line);
          }

	  // Dummy records to count T3 properly
	  $alt_lines = count( $T_scores);
	  while( count( $T_scores) < 4) $T_scores[] = -1;

	  // Count T1/2/3
	  foreach( $T_scores as $skey => $index) {

	    if( $move_score_idx <= $index) {
	      $Totals[$side]['t'.($skey+1)]++;
	      break;
	    }

	    // Special treatment of T3 line
            if( $skey == $alt_lines && $skey == 2)
              $Totals[$side]['t'.($skey+1)]++;

	    // Special treatment of T3 line
            if( $skey == $alt_lines && $skey == 1)
              $Totals[$side]['t'.($skey+1)]++;
	  }

	  // Save deltas for later processing
	  $delta = $move_score_idx - $T_scores[0];
          if( $T_scores[0] != -1 && $delta > 0)
            $deltas[$key] = $delta;

          // Second alternative is much worse, (recapture, missed chance)
          if( ($delta <= 0 && abs( $delta) > $_ENV['SOUND_MOVE_THRESHOLD'])) {

              $Totals[$side]['sound']++;
	      $deltas[$key] = 0;
	      $item['mark'] = 'Sound';

          // Multiple equal lines
	  } else if ($move_score_idx == $T_scores[0] && $T_scores[0] == $T_scores[1]) {

              $Totals[$side]['sound']++;
	      $deltas[$key] = 0;
	      $item['mark'] = 'Sound';

          // Won/lost positions
	  } else if (
	          ($move_score_idx < $_ENV['WON_POSITION_IDX'] &&
			$T_scores[0] < $_ENV['WON_POSITION_IDX']) ||
	          ($move_score_idx > $_ENV['LOST_POSITION_IDX'] &&
			$T_scores[0] > $_ENV['LOST_POSITION_IDX'])) {

              $Totals[$side]['sound']++;
	      $deltas[$key] = 0;
	      $item['mark'] = 'Sound';

	  } else if ( $delta <= 0 ||      // Move better than T1 or equal
        	( $T_scores[0] - $_ENV['EQUAL_POSITION_IDX'] != 0 &&
		$delta * 100 / abs( $T_scores[0] - $_ENV['EQUAL_POSITION_IDX'])
			< $_ENV['BEST_MOVE_THRESHOLD'] // diff below threshold
        	)) {

              $Totals[$side]['best']++;
	      $deltas[$key] = 0;
	      $item['mark'] = 'Best';
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

      // Store counters and calculate rates
      $this->updateLineSummary( $gid, $Totals, $deltas, $effectiveResult);

      $filesystem = new Filesystem();
      try {

        // Filename SHOULD contain 'evals' prefix in order to make sure
        // the filename is never matches 'games|lines' prefixes
        $tmp_file = $filesystem->tempnam('/tmp', 'evals-');

        // Save the PGNs into a local temp file
        file_put_contents( $tmp_file, json_encode( $arr));

      } catch (IOExceptionInterface $exception) {

        $this->logger->debug( "An error occurred while creating a temp file ".$exception->getPath());

	return false;
      }

      // Put the file into special uploads directory
      $this->uploader->uploadEvals( $tmp_file, $record->value('l.hash'));

      return true;
    }



    // get white effective result based on score and plycount
    private function getEffectiveResult( $move_score, $ply_count) {

      $result = 'EffectiveDraw';

      // White made final move
      if( $ply_count%2 == 0) {
        if( $_ENV['EQUAL_POSITION_IDX'] - $move_score > $_ENV['DRAWISH_POSITION_THRESHOLD'])
          $result = "EffectiveWin";
        else if( $move_score - $_ENV['EQUAL_POSITION_IDX'] > $_ENV['DRAWISH_POSITION_THRESHOLD'])
          $result = "EffectiveLoss";

      // Black move finished the game
      } else {
        if( $_ENV['EQUAL_POSITION_IDX'] - $move_score > $_ENV['DRAWISH_POSITION_THRESHOLD'])
          $result = "EffectiveLoss";
        else if( $move_score - $_ENV['EQUAL_POSITION_IDX'] > $_ENV['DRAWISH_POSITION_THRESHOLD'])
          $result = "EffectiveWin";
      }

      $this->logger->debug( "Effective game result for white: ".$result);

      return $result;
    }



    // Store counters and calculate rates
    private function updateLineSummary( $gid, $Totals, $deltas, $effectiveResult) {

      // Deltas arrays for both sides
      $Deltas = array( 'White' => array(), 'Black' => array());
      foreach( $deltas as $key => $delta)
	if( $delta > 0)
	  if( $key % 2)
	    $Deltas['Black'][] = $delta;
	  else
	    $Deltas['White'][] = $delta;

      $this->logger->debug( "Deltas white: ".implode(',', $Deltas['White']));
      $this->logger->debug( "Deltas black: ".implode(',', $Deltas['Black']));

      // Counters and rates
      foreach( ['White','Black'] as $side) {

	$Totals[$side]['analyzed'] = $Totals[$side]['plies'] -
		$Totals[$side]['ecos'] -
		$Totals[$side]['forced'] -
		$Totals[$side]['sound'];

        $Totals[$side]['deltas'] = count( $Deltas[$side]);
        $Totals[$side]['mean'] = $this->findMean( $Deltas[$side]);
        $Totals[$side]['median'] = $this->findMedian( $Deltas[$side]);
        $Totals[$side]['stddev'] = $this->findStdDev( $Deltas[$side]);

	$Totals[$side]['forced_rate'] = 0;
	if( $Totals[$side]['plies'] > 0)
	  $Totals[$side]['forced_rate'] = round( $Totals[$side]['forced'] * 100 / $Totals[$side]['plies'], 1);

	$nonECOplies = ($Totals[$side]['plies']-$Totals[$side]['ecos']-$Totals[$side]['forced']);

	$Totals[$side]['t1_rate'] = 0;
	$Totals[$side]['t2_rate'] = 0;
	$Totals[$side]['t3_rate'] = 0;
	$Totals[$side]['sound_rate'] = 0;
	if( $nonECOplies > 0) {
	  $Totals[$side]['t1_rate'] = round( $Totals[$side]['t1'] * 100 / $nonECOplies, 1);
	  $Totals[$side]['t2_rate'] = round( ($Totals[$side]['t1'] + $Totals[$side]['t2']) * 100 / $nonECOplies, 1);
	  $Totals[$side]['t3_rate'] = round( ($Totals[$side]['t1'] + $Totals[$side]['t2'] + $Totals[$side]['t3']) * 100 / $nonECOplies, 1);
	  $Totals[$side]['sound_rate'] = round( $Totals[$side]['sound'] * 100 / $nonECOplies, 1);
        }

	$nonForcedplies = ($Totals[$side]['plies']-$Totals[$side]['forced']);

	$Totals[$side]['eco_rate'] = 0;
	$Totals[$side]['et3'] = 0;
	$Totals[$side]['et3_rate'] = 0;
	if( $nonForcedplies > 0) {
	  $Totals[$side]['eco_rate'] = round( $Totals[$side]['ecos'] * 100 / $nonForcedplies, 1);
	  $Totals[$side]['et3'] = $Totals[$side]['ecos'] + $Totals[$side]['t1'] + $Totals[$side]['t2'] + $Totals[$side]['t3'];
	  $Totals[$side]['et3_rate'] = round( $Totals[$side]['et3'] * 100 / $nonForcedplies, 1);
	}

	$Totals[$side]['best_rate'] = 0;
	if( $Totals[$side]['analyzed'] > 0) {
	  $Totals[$side]['best_rate'] = round( $Totals[$side]['best'] * 100 / $Totals[$side]['analyzed'], 1);
	}


$Totals[$side]['perp_len'] = 0;
$Totals[$side]['cheat_score'] = $_ENV['ELO_START'];

    // Do not even attempt to calculate elo for erroneous games
    // We should have at least one delta
    if( ($Totals[$side]['median'] > 0 || $Totals[$side]['best_rate'] > 0)
  	   && $Totals[$side]['median'] < 50 && $Totals[$side]['mean'] < 100) {

      $t = (10000 - 100 * $Totals[$side]['best_rate'] +
      50 * $Totals[$side]['median'] +
      100 * $Totals[$side]['mean']) / 22500;

      $x1 = 100 - 100 * $t;
      $y1 = 50 * $t;
      $z1 = 100 * $t;

      $Totals[$side]['perp_len'] = sqrt(
        pow( $Totals[$side]['best_rate'] - $x1, 2) +
        pow( $Totals[$side]['median'] - $y1, 2) +
        pow( $Totals[$side]['mean'] - $z1, 2)
      );

      $Totals[$side]['cheat_score'] + round(
        sqrt(
          pow( $x1, 2) +
          pow( 50 - $Totals[$side]['median'], 2) +
          pow( 100 - $Totals[$side]['mean'], 2)
        ) * ($_ENV['ELO_END'] - $_ENV['ELO_START']) / 150);
    }


        $this->logger->debug( $side.": ".implode(',', $Totals[$side]));

        $query = 'MATCH (game:Game)-[:FINISHED_ON]->(line:Line) WHERE id(game) = $gid
MATCH (game)-[:ENDED_WITH]->(wr)<-[:ACHIEVED]-(:White)
MATCH (game)-[:ENDED_WITH]->(br)<-[:ACHIEVED]-(:Black)
MERGE (s:Summary:'.$side.')<-[:HAS_GOT]-(line)
REMOVE wr:EffectiveWin:EffectiveLoss:EffectiveDraw
REMOVE br:EffectiveWin:EffectiveLoss:EffectiveDraw
SET wr:'.$effectiveResult['White'].', br:'.$effectiveResult['Black'].',
s.deltas = $deltas, s.plies = $plies, s.analyzed = $analyzed,
s.t1 = $t1, s.t2 = $t2, s.t3 = $t3,
s.et3 = $et3, s.ecos = $ecos, s.sound = $sound, s.best = $best, s.forced = $forced,
s.eco_rate = $eco_rate, s.et3_rate = $et3_rate, s.t1_rate = $t1_rate, s.t2_rate = $t2_rate, s.t3_rate = $t3_rate,
s.sound_rate = $sound_rate, s.best_rate = $best_rate, s.forced_rate = $forced_rate,
s.mean = $mean, s.median = $median, s.stddev = $stddev,
s.perp_len = $perp_len, s.cheat_score = $cheat_score';

        $params = $Totals[$side];
	$params['gid'] = intval( $gid);
        $result = $this->neo4j_client->run( $query, $params);
      }
    }

    // find Median value
    private function findMedian( $array) {

      $counter = count( $array);
      sort( $array);

      if( $counter > 0)
        if ($counter % 2 != 0)
          return round($array[floor($counter/2)],1);
	else
          return round(($array[floor(($counter-1)/2)] + $array[$counter/2])/2,1);

      return 0;
    }

    // find Mean value
    private function findMean( $array) {

      $counter = count( $array);

      if( $counter > 0)
        return round(array_sum( $array) / $counter, 1);

      return 0;
    }

    // find StdDev value
    private function findStdDev( $array) {

      $var = 0;
      $counter = count( $array);

      $mean = $this->findMean( $array);

      foreach( $array as $value)
        $var += pow(($value - $mean), 2);

      if( $counter > 0)
	return round( sqrt( $var / $counter),1);

      return 0;
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
    private function getBestEvaluation( $node_id, $depth)
    {
      $this->logger->debug( "Fetching eval data for node id: ".$node_id);

      // Get best :Evaluation :Score for the actual game move
      $query = 'MATCH (line:Line) WHERE id(line) = {node_id}
MATCH (line)-[:HAS_GOT]->(evaluation:Evaluation)-[:RECEIVED]->(score:Score)
MATCH (seldepth:SelDepth)<-[:REACHED]-(evaluation)
MATCH (evaluation)-[:REACHED]->(depth:Depth) WHERE depth.level >= {depth}
MATCH (evaluation)-[:TOOK]->(second:Second)-[:OF]->(minute:Minute)-[:OF]->(hour:Hour)
MATCH (msec:MilliSecond)<-[:TOOK]-(evaluation)
RETURN score, depth.level, seldepth.level, minute.minute, second.second, msec.ms
ORDER BY score.idx LIMIT 1';

      $params_e = ["node_id" => intval( $node_id), "depth" => intval( $depth)];
      $result_e = $this->neo4j_client->run( $query, $params_e);

      // Fetch actual evaluation data
      foreach( $result_e->records() as $record_e) {

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

      return null;
    }
}
?>
