<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
//use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\Security\Core\Security;
use GraphAware\Neo4j\Client\ClientInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use App\Service\UserManager;
use App\Service\QueueManager;

class LoadQueueController extends AbstractController
{
    // Number of queue items to load and display
    const RECORDS_PER_PAGE = 15;

    const _DEBUG = FALSE;

    // Neo4j client interface reference
    private $neo4j_client;

    // StopWatch instance
    private $stopwatch;

    // Logger reference
    private $logger;

    // Necessary ids
    private $queue_idx;
    private $analysis_id;
    private $game_id;
    private $wu_id;

    // Array of records
    private $items;
    private $idx;

    // Queue item details
    private $item_status;
    private $item_side;
    private $item_depth;
    private $item_date;
    private $item_interval;
    private $item_actions;
    private $item_action_dts;
    private $item_action_params;

    // Security context
    private $security;

    // User nameger reference
    private $userManager;

    // Queue manager reference
    private $queueManager;

    // Dependency injection of the Neo4j ClientInterface
    public function __construct( ClientInterface $client, Stopwatch $watch,
	LoggerInterface $logger, Security $security, UserManager $um, QueueManager $qm)
    {
        $this->neo4j_client = $client;
        $this->stopwatch = $watch;
        $this->logger = $logger;
        $this->security = $security;
        $this->userManager = $um;
        $this->queueManager = $qm;

        // Init Ids with non-existing values
        $this->queue_idx = 0;
        $this->analysis_id = -1;
        $this->game_id = -1;

	$this->idx = 0;
        $this->wu_id = null;
	$this->item_status = "";
	$this->item_interval = 0;

        // starts event named 'eventName'
        $this->stopwatch->start('loadQueue');
    }

    public function __destruct()
    {
        // stops event named 'eventName'
        $this->stopwatch->stop('loadQueue');
    }

    private function formatInterval() {

	// More than a day
	if( floor( $this->item_interval / 86400) > 0)
	  return round( $this->item_interval / 86400)."+ day(s)";

	// More than an hour
	if( floor( $this->item_interval / 3600) > 0)
	  return round( $this->item_interval / 3600)."+ hour(s)";

	// More than a minute
	if( floor( $this->item_interval / 60) > 0)
	  return round( $this->item_interval / 60)."+ minute(s)";

	return $this->item_interval." seconds";
    }

    private function fetchItem( $idx) {

	// Query params
	$game_params = [];
	$game_params["gid"] = intval( $this->game_id);

	$game_query="MATCH (game:Game) WHERE id(game) = {gid} WITH game
MATCH (year:Year)<-[:OF]-(month:Month)<-[:OF]-(day:Day)<-[:GAME_WAS_PLAYED_ON_DATE]-(game)
WITH game, CASE
  WHEN year.year=0 THEN '0000' ELSE toString(year.year) END+'.'+CASE
  WHEN month.month<10 THEN '0'+month.month ELSE toString(month.month) END+'.'+CASE
  WHEN day.day<10 THEN '0'+day.day ELSE toString(day.day) END AS date_str
MATCH (game)-[:ENDED_WITH]->(white_result:Result)<-[:ACHIEVED]-(white_side:Side:White)<-[:PLAYED_AS]-(white_player:Player)
MATCH (game)-[:ENDED_WITH]->(black_result:Result)<-[:ACHIEVED]-(black_side:Side:Black)<-[:PLAYED_AS]-(black_player:Player)
MATCH (game)-[:FINISHED_ON]->(line:Line)-[:GAME_HAS_LENGTH]->(plycount:GamePlyCount)
MATCH (white_side)-[:RATED]->(white_elo:Elo)
MATCH (black_side)-[:RATED]->(black_elo:Elo)
MATCH (line)-[:CLASSIFIED_AS]->(eco_code:EcoCode)
MATCH (game)-[:WAS_PLAYED_IN]->(round:Round)
MATCH (game)-[:WAS_PART_OF]->(event:Event)
MATCH (game)-[:TOOK_PLACE_AT]->(site:Site)
RETURN game, game.hash, white_player.name, black_player.name, date_str, eco_code.code,
	event.name, round.name, site.name, white_elo.rating, black_elo.rating, plycount.counter, white_result
LIMIT 1";

        $game_result = $this->neo4j_client->run($game_query, $game_params);

	foreach ($game_result->records() as $game_record) {

        $gameObj = $game_record->get('game');
        $this->items[$idx]['ID'] = $gameObj->identity();
        $this->items[$idx]['AId'] = $this->analysis_id;
        $this->items[$idx]['Index'] = $this->queue_idx;
        $this->items[$idx]['Status'] = $this->item_status;
        $this->items[$idx]['Side'] = $this->item_side;
        $this->items[$idx]['Depth'] = $this->item_depth;
        $this->items[$idx]['Date'] = $this->item_date;
        $this->items[$idx]['Actions'] = $this->item_actions;
        $this->items[$idx]['ADateTimes'] = $this->item_action_dts;
        $this->items[$idx]['AParams'] = $this->item_action_params;

        $this->items[$idx]['Interval'] = '';
	if( $this->item_status == "Pending")
          $this->items[$idx]['Interval'] = $this->item_interval?$this->formatInterval():"few seconds";

        $this->items[$idx]['Hash'] =  $game_record->value('game.hash');
        $this->items[$idx]['White'] = $game_record->value('white_player.name');
        $this->items[$idx]['ELO_W'] = $game_record->value('white_elo.rating')==0?"":$game_record->value('white_elo.rating');
        $this->items[$idx]['Black'] = $game_record->value('black_player.name');
        $this->items[$idx]['ELO_B'] = $game_record->value('black_elo.rating')==0?"":$game_record->value('black_elo.rating');
        $this->items[$idx]['Date']  = $game_record->value('date_str');
        $this->items[$idx]['ECO']   = $game_record->value('eco_code.code');
        $this->items[$idx]['Event'] = $game_record->value('event.name');
        $this->items[$idx]['Round'] = $game_record->value('round.name');
        $this->items[$idx]['Site']  = $game_record->value('site.name');
        $this->items[$idx]['Moves'] = round( $game_record->value('plycount.counter')/2, 0, PHP_ROUND_HALF_UP);
	$labelsObj = $game_record->get('white_result');
	$labelsArray = $labelsObj->labels();
	if( in_array( "Draw", $labelsArray))
          $this->items[$idx]['Result'] = "1/2-1/2";
	else if( in_array( "Win", $labelsArray))
          $this->items[$idx]['Result'] = "1-0";
	else
          $this->items[$idx]['Result'] = "0-1";
	}
    }

    /**
      * @Route("/loadQueue")
      */
    public function loadQueue()
    {
	// HTTP request
	$request = Request::createFromGlobals();

	// Query params
	$params = [];

	// Pagination, skip records
        $skip_records = 'SKIP $SKIP';
        $params["SKIP"] = 0;
	if( $page = $request->query->getInt('page', 0))
          $params["SKIP"] = $page * self::RECORDS_PER_PAGE;

	// Order condition
	$order_by = [
	"Date"		=>	"date_str",
	"Moves"		=>	"plycount",
	"Place"		=>	"idx",
	""		=>	"date_str"
	];
/*
	"Status"	=>	"g.status",
	"ScoreW"	=>	"g.W_cheat_score",
	"ScoreB"	=>	"g.B_cheat_score",
	"DeltaW"	=>	"g.W_cheat_score-toInteger(w.ELO)",
	"DeltaB"	=>	"g.B_cheat_score-toInteger(b.ELO)",
*/
	// Get value from query parameter
	$sort_cond = $request->query->getAlpha('sort');

	// Check for descending attribute
	$descending = "";
	if( strpos( $sort_cond, "Desc")) {
	  $sort_cond = substr( $sort_cond, 0, strlen( $sort_cond) - 4);
	  $descending = " DESC";
	}

	// Check for sort key existance, unset on unusual key
	if( !array_key_exists( $sort_cond, $order_by)) $sort_cond = "";
	$order_condition = "ORDER BY " . $order_by[$sort_cond] . $descending;

	// Use https://api.symfony.com/master/Symfony/Component/Serializer/Encoder/JsonDecode.html instead?
	$query_tags = urldecode( $request->query->get('tags'));
//	echo $query_tags;
	$tags=explode(';', json_decode( $query_tags));

        // Order condition
        $known_tags = [ "id",
			"player",
			"result", "ending",
			"status",
			"side",
			"type",
			"email",
			"eco",
			"piece",
			"white", "black",
			"wins", "loses", "draws",
		"start_year", "start_month", "start_day", "end_year", "end_month", "end_day" ];

	// Opposite arrays
	$opposite_color	 = [ "white" => "black", "black" => "white" ];
	$opposite_result = [ "wins" => "loses", "draws" => "draws", "loses" => "wins" ];

	// Neo4j entiries
	$side_colors	 = [ "white" => "White", "black" => "Black" ];
	$side_results	 = [ "wins" => "Win", "draws" => "Draw", "loses" => "Loss" ];
	$side_label = "";

	// Analysis type
	$analysis_types	= [ "fast" => $_ENV['FAST_ANALYSIS_DEPTH'],
			"deep" => $_ENV['DEEP_ANALYSIS_DEPTH'] ];
	$analysis_depth = 0;
	$depth_param	= "";

	// Valid tag values
	$valid_ending	= [ "checkmate" => "CheckMate", "stalemate" => "StaleMate" ];
	$valid_piece	= [ "pawn" => "Pawn", "rook" => "Rook", "queen" => "Queen",
		"king" => "King", "bishop" => "Bishop", "knight" => "Knight" ];

	// Game Status Labels
	$analysis_statuses	= [ "complete" => "Complete", "processing" => "Processing",
		"pending" => "Pending", "skipped" => "Skipped",
		"partially" => "Partially", "evaluated" => "Evaluated",
		"exported" => "Exported" ];
	$status_param	= "";

	// If player color has been specified
	$color_specification_flag=FALSE;

	// Players arays
	$players = [];

	// Sides array
	$sides = [];

	// Results array
	$results = [];

	// web user
	$webuser = "";

	// Game Ending type lable
	$plycount_ending_label = "";
	$plycount_eco_label = "";

	$ending_label = "";

	$date_ending_label = "";
	$date_eco_label = "";

	$rel_type="GAME";
	$order_rel_type="GAME";

	// Parse each tag
	foreach( $tags as $tag)

	// Non empty and has a colon separator
	if( strlen($tag) && strpos($tag, ":") !== FALSE) {

	    $tag_name_value=explode(':', $tag);
            $tag_name = $tag_name_value[0];

	    // Check if we accept the parameter
	    if (in_array( $tag_name, $known_tags)) {

		$tag_value = filter_var($tag_name_value[1], FILTER_SANITIZE_STRING);

if( self::_DEBUG) {
print_r($players);
print_r($sides);
print_r($results);
echo "<br/>\n";
}
		// Parse the parameter
		switch( $tag_name) {

		  case "id":
		    $params["id"] = intval( filter_var($tag_name_value[1], FILTER_SANITIZE_NUMBER_INT));
//		    if( $params["end_month"] > 12 || $params["end_month"] < 1) $params["end_month"] = 0;
		    break;

		  case "player":

		    // Set second player name only if not equal to first one or first does not exist
		    if( array_key_exists( "first", $players) && $tag_value != $players["first"])
		      $players["second"] = $tag_value;
		    else
		      $players["first"] = $tag_value;
		    break;

		  case "black":

 		    // Reverse result for black player
		    if(array_key_exists( "first", $results)) {
		      $results["second"] = $results["first"];
		      $results["first"] = $opposite_result[$results["first"]];
		    }

		  case "white":

		    $sides["first"] = $tag_name;
		    $sides["second"] = $opposite_color[$tag_name];

		    // Replace first player with new one and keep it as a second
		    if( array_key_exists( "first", $players) && $tag_value != $players["first"]) {
		      $players["second"] = $players["first"];

		      // Reverse result for white player only
		      if( array_key_exists( "first", $results) && $color_specification_flag
			&& $tag_name == "white") {
		        $results["second"] = $results["first"];
		        $results["first"] = $opposite_result[$results["first"]];
		      }
		    }
		    $players["first"] = $tag_value;

		    $color_specification_flag=TRUE;

		    break;

		  case "wins":
		  case "draws":
		  case "loses":
//		    $color_specification_flag=TRUE;
		    if( array_key_exists( "first", $players) && $tag_value != $players["first"]) {
		      $players["second"] = $tag_value;
		      if( !array_key_exists( "first", $results)) {
		        $results["second"] = $tag_name;
		        $results["first"] = $opposite_result[$tag_name];
		      }
		    } else {
		      $players["first"] = $tag_value;
		      if( !array_key_exists( "first", $results)) {
		        $results["first"] = $tag_name;
		        $results["second"] = $opposite_result[$tag_name];
		      }
		    }
		    break;

		  case "start_year":
		    $params["start_year"] = intval( filter_var($tag_name_value[1], FILTER_SANITIZE_NUMBER_INT));
		    if( $params["start_year"] > date('Y') || $params["start_year"] < 1) $params["start_year"] = 0;
		    break;

		  case "start_month":
		    $params["start_month"] = intval( filter_var($tag_name_value[1], FILTER_SANITIZE_NUMBER_INT));
		    if( $params["start_month"] > 12 || $params["start_month"] < 1) $params["start_month"] = 0;
		    break;

		  case "start_day":
		    $params["start_day"] = intval( filter_var($tag_name_value[1], FILTER_SANITIZE_NUMBER_INT));
		    if( $params["start_day"] > 31 || $params["start_day"] < 1) $params["start_day"] = 0;
		    break;

		  case "end_year":
		    $params["end_year"] = intval( filter_var($tag_name_value[1], FILTER_SANITIZE_NUMBER_INT));
		    if( $params["end_year"] > date('Y') || $params["end_year"] < 1) $params["end_year"] = 0;
		    break;

		  case "end_month":
		    $params["end_month"] = intval( filter_var($tag_name_value[1], FILTER_SANITIZE_NUMBER_INT));
		    if( $params["end_month"] > 12 || $params["end_month"] < 1) $params["end_month"] = 0;
		    break;

		  case "end_day":
		    $params["end_day"] = intval( filter_var($tag_name_value[1], FILTER_SANITIZE_NUMBER_INT));
		    if( $params["end_day"] > 31 || $params["end_day"] < 1) $params["end_day"] = 0;
		    break;

		  case "type":

		    // Check for valid analysis type
		    if( array_key_exists( strtolower( $tag_value), $analysis_types))
		      $analysis_depth = $analysis_types[strtolower( $tag_value)];
		    break;

		  case "side":

		    // Check for valid side
		    if( array_key_exists( $tag_value, $side_colors))
		      $side_label .= ":".$side_colors[$tag_value]."Side";
		    break;

		  case "email":

		    // Check for valid email, fetch user id
		    if( filter_var( $tag_value, FILTER_VALIDATE_EMAIL))
		      $this->wu_id = $this->userManager->fetchWebUserId( $tag_value);
		    break;

		  case "status":

		    // Check for valid status
		    if( array_key_exists( strtolower( $tag_value), $analysis_statuses))
		      $this->item_status = $analysis_statuses[strtolower( $tag_value)];
		    break;

		  case "eco":
		    // Check for valid ECO string
		    if( strlen( $tag_value) == 3 ) {
			$plycount_eco_label	=	":".strtoupper( $tag_value)."PlyCount";
			$date_eco_label		=	":".strtoupper( $tag_value)."Day";
			$order_rel_type		=	strtoupper( $tag_value);
		        if( strlen( $plycount_ending_label))
			  $rel_type	       =	strtoupper( $tag_value)."_".$rel_type;
			else
			  $rel_type		=	strtoupper( $tag_value);
			$params["eco_code"]	=	strtoupper( $tag_value);
		    }
		    break;

		  case "piece":

		    // Check for valid piece type
		    if( !array_key_exists( $tag_value, $valid_piece)) break;

//		    if( strlen( $ending_label))
//			$ending_label .= "By".$valid_piece[$tag_value];
		    if( strlen( $plycount_ending_label))
			$plycount_ending_label .= "By".$valid_piece[$tag_value];
		    if( strlen( $date_ending_label))
			$date_ending_label .= "By".$valid_piece[$tag_value];
		    if( strlen( $rel_type))
		        $rel_type.="_BY_".strtoupper( $tag_value);
		    if( strlen( $order_rel_type))
		        $order_rel_type.="_BY_".strtoupper( $tag_value);

		    break;

		  case "ending":

		    // Check for valid ending type
		    if( !array_key_exists( $tag_value, $valid_ending)) break;

		    // Only set ending order rel type if no ECO has been specified yet
		    // as it is considered more infrequent?!?!?!?!?!?!
		    // DB schema is like this
		    if( strlen( $plycount_eco_label) == 0) {
		      $rel_type = strtoupper( $tag_value);
		      $order_rel_type = $rel_type;
		    } else
		      $rel_type.="_".strtoupper( $tag_value);

		    $ending_label=":".$valid_ending[$tag_value];
		    $plycount_ending_label=$valid_ending[$tag_value];
		    $date_ending_label=$valid_ending[$tag_value];

		    break;

		  case "result":
		    switch( $tag_value) {
		      case "1-0":
		        if( array_key_exists( "first", $sides)) {
			  if( $sides["first"] == "white") {
			    $results["first"] = "wins";
			    $results["second"] = "loses";
			  } else {
			    $results["first"] = "loses";
			    $results["second"] = "wins";
			  }
			} else {
			  $results["first"] = "wins";
			  $results["second"] = "loses";
			}
			break;
		      case "0-1":
		        if( array_key_exists( "first", $sides)) {
			  if( $sides["first"] == "black") {
			    $results["first"] = "wins";
			    $results["second"] = "loses";
			  } else {
			    $results["first"] = "loses";
			    $results["second"] = "wins";
			  }
			} else {
			    $results["first"] = "loses";
			    $results["second"] = "wins";
			}
			break;
		      default:
			$results["first"] = "draws";
			$results["second"] = "draws";
			break;
		    }
		    break;

		  default:
		    break;
		}
	    }
	}

// If player color has not been specified we need equal results for the query
if( !$color_specification_flag && count( $results))
  $results["second"]=$results["first"];

if( self::_DEBUG) {
print_r($players);
print_r($sides);
print_r($results);
echo "<br/>\n";
}

// Empty sides array, set defaults
if( !count( $sides))
  $sides["first"]=$sides["second"]="white";
$sides["first"]=$side_colors[$sides["first"]];
$sides["second"]=$side_colors[$sides["second"]];

// Results
$first_results = array();
$second_results = array();

// Push all values if empty results array
if( !count( $results)) {
  array_push( $first_results, "Win", "Draw", "Loss");
  $second_results=$first_results;
} else {
  array_push( $first_results, $side_results[$results["first"]]);
  array_push( $second_results, $side_results[$results["second"]]);
}

if( self::_DEBUG) {
print_r($players);
print_r($sides);
print_r($first_results);
print_r($second_results);
echo "<br/>\n";
}
	if( array_key_exists( "first", $players))	$params["first"]	= $players["first"];
	if( array_key_exists( "second", $players))	$params["second"]	= $players["second"];
	$params["first_side"] = $sides["first"];
	$params["second_side"] = $sides["second"];
	$params["first_results"] = $first_results;
	$params["second_results"] = $second_results;

	// Set default param values
	if( !array_key_exists( "start_year", $params)) $params["start_year"]	=0;
	if( !array_key_exists( "start_month", $params)) $params["start_month"]	=0;
	if( !array_key_exists( "start_day", $params)) $params["start_day"]	=0;
	if( !array_key_exists( "end_year", $params)) $params["end_year"]	=intval( date('Y'));
	if( !array_key_exists( "end_month", $params)) $params["end_month"]	=intval( date('n'));
	if( !array_key_exists( "end_day", $params)) $params["end_day"]		=intval( date('j'));

	$first_param = "";
	$second_param ="";
	if( array_key_exists( "first", $players)) $first_param  = "{name:{first}}";
	if( array_key_exists( "second", $players)) $second_param = "{name:{second}}";

	// Game ID has been specified, simple query
	if( array_key_exists( "id", $params)) {

	  $query = "MATCH (game:Game) WHERE id(game) = {id}
RETURN DISTINCT id(game) AS gid LIMIT 1";

	// Player name(s) have been specified
	} else if( array_key_exists( "first", $params) || array_key_exists( "second", $params))

//MATCH (year:Year)<-[:OF]-(month:Month)<-[:OF]-(day:".$date_ending_label.$date_eco_label.")<-[".$rel_type."_WAS_PLAYED_ON]-(game)
	  $query = "MATCH (game:Game".$this->item_status.")-[:ENDED_WITH]->(first_result:Result)<-[:ACHIEVED]-(first_side:Side)<-[:PLAYED_AS]-(first_player:Player$first_param)
MATCH (game)-[:ENDED_WITH]->(second_result:Result)<-[:ACHIEVED]-(second_side:Side)<-[:PLAYED_AS]-(second_player:Player$second_param)
  WITH game,second_player,first_player,second_result,first_result,second_side,first_side
  WHERE
      first_player <> second_player
    AND (
      (
	[x IN labels(first_result) WHERE x IN {first_results}]
	AND {first_side} IN labels(first_side)
      )
      OR
      (
	[x IN labels(second_result) WHERE x IN {second_results}]
	AND {second_side} IN labels(second_side)
      )
    )
MATCH (year:Year)<-[:OF]-(month:Month)<-[:OF]-(day:Day)<-[:GAME_WAS_PLAYED_ON_DATE]-(game)
  WITH game,second_player,first_player,second_result,first_result,second_side,first_side,
    CASE WHEN year.year=0	THEN '0000'		ELSE toString(year.year) END +
    CASE WHEN month.month<10	THEN '0'+month.month	ELSE toString(month.month) END +
    CASE WHEN day.day<10	THEN '0'+day.day	ELSE toString(day.day) END AS date_str,
    CASE WHEN {start_year}=0	THEN '0000'		ELSE toString({start_year}) END +
    CASE WHEN {start_month}<10	THEN '0'+{start_month}	ELSE toString({start_month}) END +
    CASE WHEN {start_day}<10	THEN '0'+{start_day}	ELSE toString({start_day}) END AS start_date_str,
    CASE WHEN {end_year}=0	THEN '0000'		ELSE toString({end_year}) END +
    CASE WHEN {end_month}<10	THEN '0'+{end_month}	ELSE toString({end_month}) END +
    CASE WHEN {end_day}<10	THEN '0'+{end_day}	ELSE toString({end_day}) END AS end_date_str
  WHERE
      start_date_str <= date_str <= end_date_str
MATCH (game)-[:FINISHED_ON]->(:Line$ending_label)-[:".$rel_type."_HAS_LENGTH]->(plycount:GamePlyCount:".
$plycount_ending_label."PlyCount".$plycount_eco_label.")
RETURN DISTINCT id(game) AS gid, date_str, plycount
$order_condition
$skip_records
LIMIT ".self::RECORDS_PER_PAGE;
/*
	// Final position type (mate is slow 2M db hits on a 1.6M games DB)
	else if( strlen( $ending_label) || strlen( $status_label))

	  $query = "MATCH (game:Game$status_label)-[:FINISHED_ON]->($ending_label)-[:LENGTH]->(plycount:PlyCount)
	  WITH game, plycount
MATCH (year:Year)<-[:OF]-(month:Month)<-[:OF]-(day:Day)<-[:PLAYED_DATE]-(game)
	WITH game, plycount,
    CASE WHEN year.year=0       THEN '0000'             ELSE toString(year.year) END +
    CASE WHEN month.month<10    THEN '0'+month.month    ELSE toString(month.month) END +
    CASE WHEN day.day<10        THEN '0'+day.day        ELSE toString(day.day) END AS date_str,
    CASE WHEN {start_year}=0    THEN '0000'             ELSE toString({start_year}) END +
    CASE WHEN {start_month}<10  THEN '0'+{start_month}  ELSE toString({start_month}) END +
    CASE WHEN {start_day}<10    THEN '0'+{start_day}    ELSE toString({start_day}) END AS start_date_str,
    CASE WHEN {end_year}=0      THEN '0000'             ELSE toString({end_year}) END +
    CASE WHEN {end_month}<10    THEN '0'+{end_month}    ELSE toString({end_month}) END +
    CASE WHEN {end_day}<10      THEN '0'+{end_day}      ELSE toString({end_day}) END AS end_date_str
  WHERE
      start_date_str <= date_str <= end_date_str
MATCH (game)-[:ENDED_WITH]->(first_result:Result)<-[:ACHIEVED]-(:Side:White)
MATCH (game)-[:ENDED_WITH]->(second_result:Result)<-[:ACHIEVED]-(:Side:Black)
  WHERE [x IN labels(first_result) WHERE x IN {first_results}]
RETURN DISTINCT id(game) AS gid, date_str, plycount
$order_condition
$skip_records
LIMIT ".self::RECORDS_PER_PAGE;
*/
	// No special limiting tags, select all games
	else {

	// Game ordering (plies or date by default)
	// Hint: If minplies specified, start node might NOT be StaleMatePlyCount
	// If this is the case, find the NEXT StaleMatePlyCount using the following:
	// MATCH (:PlyCount{counter:{minplies}})-[:LONGER*0..]->(p:StaleMatePlyCount) WITH p LIMIT 1 RETURN p.counter
	$params["minplies"] = intval( "0");
	$params["maxplies"] = intval( "999");
	$params["start_year"]	= intval( "0");
	$params["start_month"]	= intval( "0");
	$params["start_day"]	= intval( "0");
	$params["end_year"]	= intval( intval( date( "Y")) + 1);
	$params["end_month"]	= intval( "0");
	$params["end_day"]	= intval( "0");
	switch( $sort_cond) {

	  case "Moves":

	    //
	    //
	    // What if Date period have to be taken into account?
	    //
	    //

	    // Begin from either head or tail of :PlyCount sequence
	    // If both maxplies and minplies are specified, how do I limit the end of search
//	    $moves_ordering_condition = "MATCH (:PlyCount{counter:{maxplies}})<-[:LONGER*0..]-";
	    $moves_ordering_condition = "DESC";
	    $left="<"; $right="";

	    if( strlen( $descending)==0) {
	      $moves_ordering_condition = "";
//	      $moves_ordering_condition = "MATCH (:PlyCount{counter:{minplies}})-[:LONGER*0..]->";
	      $left=""; $right=">";
	    }
/*
	    // Append query with ECO condition
	    // It results in longer and expensive hash join
	    $eco_classification_condition="";
	    if( strlen( $plycount_eco_label))
	      $eco_classification_condition="MATCH (l)-[:CLASSIFIED_AS]->(:EcoCode{code:{eco_code}})";
*/
/*
	    $query = $moves_ordering_condition.
"(p:GamePlyCount".$plycount_ending_label.$plycount_eco_label.") WITH p LIMIT 1 ".
*/
	    $query =
"MATCH (p:GamePlyCount:".$plycount_ending_label."PlyCount".$plycount_eco_label.") WITH p ORDER BY p.counter $moves_ordering_condition LIMIT 1 ".
"MATCH (p)".$left."-[:LONGER_$order_rel_type*0..]-".$right."(:GamePlyCount:".$plycount_ending_label."PlyCount".$plycount_eco_label.")<-[:".
$rel_type."_HAS_LENGTH]-(:Line$ending_label)<-[:FINISHED_ON]-(game:Game) ";
//$eco_classification_condition;

	    break;

	  default:	// Date ordering

	    //
	    //
	    // What if Plycount minplies/maxplies have to be taken into account?
	    //
	    //

	    // Begin from either head or tail of :Day sequence
	    // If both start and end_year/month/day are specified, how do I limit the end of search
	    $date_ordering_condition = "DESC";
//"MATCH (:Year{year:{end_year}})<-[:OF]-(:Month{month:{end_month}})<-[:OF]-(:Day{day:{end_day}})<-[:NEXT*0..]-";
	    $left="<"; $right="";

	    if( strlen( $descending)==0) {
	      $date_ordering_condition = "";
//"MATCH (:Year{year:{start_year}})<-[:OF]-(:Month{month:{start_month}})<-[:OF]-(:Day{day:{start_day}})-[:NEXT*0..]->";
	      $left=""; $right=">";
	    }

	    $query =
"MATCH (d:GameDay:".$date_ending_label."Day".$date_eco_label.") WITH d ORDER BY d.idx $date_ordering_condition LIMIT 1 ".
"MATCH (d)".$left."-[:NEXT_$order_rel_type*0..]-".$right."(:GameDay:".$date_ending_label."Day".$date_eco_label.")<-[:".
$rel_type."_WAS_PLAYED_ON_DATE]-(game:Game)-[:FINISHED_ON]->(:Line$ending_label) ";

//"MATCH (:Year{year:{end_year}})<-[:OF]-(:Month{month:{end_month}})<-[:OF]-(:Day{day:{end_day}})<-[:NEXT*0..]-(:Day)<-[$rel_type]-(game:Game)";
//	    if( strlen( $ending_label)) $query .= "-[:FINISHED_ON]->(:Line$ending_label)";

	    break;
	}

	  $query .= " WITH game
MATCH (game)-[:ENDED_WITH]->(first_result:Result)<-[:ACHIEVED]-(:Side:White)
MATCH (game)-[:ENDED_WITH]->(second_result:Result)<-[:ACHIEVED]-(:Side:Black)
  WHERE [x IN labels(first_result) WHERE x IN {first_results}]
RETURN DISTINCT id(game) AS gid
$skip_records
LIMIT ".self::RECORDS_PER_PAGE;
	}
//    AND [x IN labels(second_result) WHERE x IN {second_results}]






/* Queue controller starts here
*
*
*
*/

	// Add web user parameter
	if( $this->wu_id) {
	  $webuser = "{id:{wu_id}}";
	  $params["wu_id"] = intval( $this->wu_id);
	}

	// Add depth paramteer
	if( $analysis_depth > 0) {
	  $depth_param = "{level:{depth}}";
	  $params["depth"] = intval( $analysis_depth);
	}

	// Specific status
	if( strlen( $this->item_status) > 0) {
	  $status_param = "{status:{status}}";
	  $params["status"] = $this->item_status;
	}

	// Specific game id
	if( array_key_exists( "id", $params)) {

	  $query = "MATCH (a:Analysis)-[:REQUESTED_FOR]->(game:Game)
WHERE id(game) = {id}
MATCH (:Head:Queue)-[:FIRST]->(f:Analysis)
MATCH dist=shortestPath((f)-[:NEXT*0..]->(a)) WITH a,game,length(dist)+1 as distance
MATCH (s:Status)<-[:HAS_GOT]-(a)
MATCH (d:Depth)<-[:REQUIRED_DEPTH]-(a)
RETURN a, distance AS idx, d.level, id(game) AS gid, s.status ORDER BY idx";

	  // Descending sorting
	  if( strlen( $descending)) $query .= " DESC";

	} else {

	  // All analysis nodes starting from head
//  USING SCAN f:Analysis
	  $query = "MATCH (:Head:Queue)-[:FIRST]->(f:Analysis)
MATCH (:Tail:Queue)-[:LAST]->(l:Analysis)
  WITH f,l LIMIT 1
MATCH dist=shortestPath((f)-[:NEXT*0..]->(l)) WITH length(dist)+2 as distance,f,l LIMIT 1
MATCH path=(f)-[:NEXT*0..]->(a:Analysis".$side_label.")-[:REQUESTED_BY]->(w:WebUser".$webuser.")
MATCH (s:Status)<-[:HAS_GOT]-(a)
MATCH (d:Depth".$depth_param.")<-[:REQUIRED_DEPTH]-(a)-[:REQUESTED_FOR]->(game:Game)
RETURN a, length(path) AS idx, d.level, id(game) AS gid, s.status
$skip_records LIMIT ".self::RECORDS_PER_PAGE;

	  // Items for specific status
	  if( array_key_exists( "status", $params))
	    $query = "
MATCH (s:Status".$status_param.")-[:FIRST]->(f:Analysis)
MATCH (:Status".$status_param.")-[:LAST]->(l:Analysis)
  WITH f,l,s LIMIT 1
MATCH dist=shortestPath((f)-[:NEXT_BY_STATUS*0..]->(l)) WITH length(dist)+2 as distance,f,l,s LIMIT 1
MATCH path=(f)-[:NEXT_BY_STATUS*0..]->(a:Analysis".$side_label.")-[:REQUESTED_BY]->(w:WebUser".$webuser.")
MATCH (d:Depth".$depth_param.")<-[:REQUIRED_DEPTH]-(a)-[:REQUESTED_FOR]->(game:Game)
RETURN a, length(path) AS idx, d.level, id(game) AS gid, s.status
$skip_records LIMIT ".self::RECORDS_PER_PAGE;

	  // Descending sorting
	  if( strlen( $descending)) {

	    // All analysis nodes starting from tail
	    $query = "MATCH (:Head:Queue)-[:FIRST]->(f:Analysis)
MATCH (:Tail:Queue)-[:LAST]->(l:Analysis)
  WITH f,l LIMIT 1
MATCH dist=shortestPath((f)-[:NEXT*0..]->(l)) WITH length(dist)+2 as distance,f,l LIMIT 1
MATCH path=(l)<-[:NEXT*0..]-(a:Analysis".$side_label.")-[:REQUESTED_BY]->(w:WebUser".$webuser.")
MATCH (s:Status)<-[:HAS_GOT]-(a)
MATCH (d:Depth".$depth_param.")<-[:REQUIRED_DEPTH]-(a)-[:REQUESTED_FOR]->(game:Game)
RETURN a, distance-length(path) AS idx, d.level, id(game) AS gid, s.status
$skip_records LIMIT ".self::RECORDS_PER_PAGE;

	    // Items for specific status
	    if( array_key_exists( "status", $params))
	      $query = "
MATCH (s:Status".$status_param.")-[:FIRST]->(f:Analysis)
MATCH (:Status".$status_param.")-[:LAST]->(l:Analysis)
  WITH f,l,s LIMIT 1
MATCH dist=shortestPath((f)-[:NEXT_BY_STATUS*0..]->(l)) WITH length(dist)+2 as distance,f,l,s LIMIT 1
MATCH path=(l)<-[:NEXT_BY_STATUS*0..]-(a:Analysis".$side_label.")-[:REQUESTED_BY]->(w:WebUser".$webuser.")
MATCH (d:Depth".$depth_param.")<-[:REQUIRED_DEPTH]-(a)-[:REQUESTED_FOR]->(game:Game)
RETURN a, distance-length(path) AS idx, d.level, id(game) AS gid, s.status
$skip_records LIMIT ".self::RECORDS_PER_PAGE;

	  }
	}

//print_r( $params);
//echo $query;
        $result = $this->neo4j_client->run($query, $params);

//	$event = $this->stopwatch->lap('loadQueue');

	// Start analysis Id -1 means we need to fetch first Pending once
	$said = -1;
//if( false)
        // Fetch the item data from the DB
        foreach ( $result->records() as $record) {
	  $this->item_side = "";
	  $labelsObj = $record->get('a');
	  $this->analysis_id = $labelsObj->identity();
	  $labelsArray = $labelsObj->labels();
	  $this->item_status = '';

	  // We selected games without specific status
	  if( in_array( $record->value('s.status'), $analysis_statuses))
	    $this->item_status = $record->value('s.status');

	  // Analysis side
	  if( in_array( "WhiteSide", $labelsArray)) {
	    if( strlen( $this->item_side))
	      $this->item_side = "Both";
	    else
	      $this->item_side = "White";
	  }
	  if( in_array( "BlackSide", $labelsArray)) {
	    if( strlen( $this->item_side))
	      $this->item_side = "Both";
	    else
	      $this->item_side = "Black";
	  }

	  // If Pending, calculate estimate
	  if( $this->item_status == "Pending") {

	    // Get first Pending Analysis in the queue once
	    if( $said == -1) {

	      // First Pending by default
	      $said = $this->queueManager->getStatusQueueNode();

	      $this->item_interval =
		$this->queueManager->getAnalysisInterval( $said, $this->analysis_id);

	    } else {

	    if( strlen( $descending))
	      $this->item_interval -=
		$this->queueManager->getAnalysisInterval( $this->analysis_id, $said);
	    else
	      $this->item_interval +=
		$this->queueManager->getAnalysisInterval( $said, $this->analysis_id);
	    }

	    $said = $this->analysis_id;
	  }

	  // Fetch actions taken on specific analysis node
	  $query = "MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (a)<-[:WAS_TAKEN_ON]-(f:Action{action:'Creation'})
MATCH (f)-[:NEXT*0..]->(t:Action)
  MATCH (t)-[:WAS_PERFORMED_DATE]->(d:Day)-[:OF]->(m:Month)-[:OF]->(y:Year)
  MATCH (t)-[:WAS_PERFORMED_TIME]->(s:Second)-[:OF]->(n:Minute)-[:OF]->(h:Hour)
    OPTIONAL MATCH (t)-[:CHANGED_TO]->(status:Status)
    OPTIONAL MATCH (t)-[:CHANGED_TO]->(depth:Depth)
RETURN t.action,
  apoc.temporal.format( datetime({ year: y.year, month: m.month,
				day: d.day, hour: h.hour,
				minute: n.minute, second: s.second}),
				'YYYY-MM-dd HH:mm:ss') AS dts,
  t.parameter, status.status, depth.level";

	  $params["aid"] = intval( $this->analysis_id);
          $result_a = $this->neo4j_client->run($query, $params);

	  // Store all actions and parameters in an array
	  $this->item_actions = array();
	  $this->item_action_dts = array();
	  $this->item_action_params = array();
          foreach ( $result_a->records() as $record_a) {

	    // Fill in arrays
	    $this->item_actions[] = $record_a->value('t.action');
	    $this->item_action_dts[] = $record_a->value('dts');

	    if( $record_a->value('t.parameter') != null)
	      $this->item_action_params[] = $record_a->value('t.parameter');
	    else if( $record_a->value('status.status') != null)
	      $this->item_action_params[] = $record_a->value('status.status');
	    else if( $record_a->value('depth.level') != null)
	      $this->item_action_params[] = $record_a->value('depth.level');
	    else
	      $this->item_action_params[] ='';
	  }

	  // Fetch item details
	  $this->game_id = $record->value('gid');
	  $this->item_depth = $record->value('d.level');
	  $this->queue_idx = $record->value('idx');
	  $this->fetchItem( $this->idx++);
	}

	// Encode in JSON and output
        return new JsonResponse( $this->items);
    }
}
?>
