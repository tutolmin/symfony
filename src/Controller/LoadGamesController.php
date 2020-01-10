<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

class LoadGamesController extends AbstractController
{
    // Number of games to load and display
    const RECORDS_PER_PAGE = 15;

    const _DEBUG = FALSE;

    /**
      * @Route("/loadGames")
      */
    public function loadGames( 
	\Symfony\Component\Stopwatch\Stopwatch $stopwatch,
	\GraphAware\Neo4j\Client\ClientInterface $neo4j_client)
    {
	// starts event named 'eventName'
	$stopwatch->start('loadGames');

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
			"eco",
			"white", "black", 
			"wins", "loses", "draws", 
		"start_year", "start_month", "start_day", "end_year", "end_month", "end_day" ];

	// Opposite arrays
	$opposite_color	 = [ "white" => "black", "black" => "white"];
	$opposite_result = [ "wins" => "loses", "draws" => "draws", "loses" => "wins" ];

	// Neo4j entiries
	$side_colors	 = [ "white" => "White", "black" => "Black"];
	$side_results	 = [ "wins" => "Win", "draws" => "Draw", "loses" => "Loss"];

	// Game Status Labels
	$game_statuses	= [ "complete" => ":Complete", "processing" => ":Processing", "pending" => "Pending"];
	$status_label = "";

	// If player color has been specified
	$color_specification_flag=FALSE;

	// Players arays
	$players = [];

	// Sides array
	$sides = [];

	// Results array
	$results = [];

	// Game Ending type lable
	$plycount_ending_label = "";
	$plycount_eco_label = "";

	$ending_label = "";

	$date_ending_label = "";
	$date_eco_label = "";

	$rel_type="GAME";

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
		
		  case "status":
		    if( array_key_exists( $tag_value, $game_statuses)) $status_label = $game_statuses[$tag_value];
		    break;

		  case "eco":
		    if( strlen( $tag_value) == 3 ) { 
			$plycount_eco_label	=	":".strtoupper( $tag_value)."PlyCount";
			$date_eco_label		=	":".strtoupper( $tag_value)."Day";
			$rel_type		=	strtoupper( $tag_value);
			$params["eco_code"]	=	strtoupper( $tag_value);
		    }
		    break;

		  case "ending":
		
		    // Only set rel type if no ECO has been specified yet
		    if( strlen( $plycount_eco_label) == 0)
		      $rel_type=strtoupper( $tag_value);

		    if( $tag_value == "checkmate") { 
			$ending_label=":CheckMate"; 
			$plycount_ending_label=":CheckMatePlyCount";
			$date_ending_label=":CheckMateDay";
		    }
		    if( $tag_value == "stalemate") { 
			$ending_label=":StaleMate"; 
			$plycount_ending_label=":StaleMatePlyCount"; 
			$date_ending_label=":StaleMateDay";
		    }
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
	if( !array_key_exists( "start_year", $params)) $params["start_year"]	=1900;
	if( !array_key_exists( "start_month", $params)) $params["start_month"]	=0;
	if( !array_key_exists( "start_day", $params)) $params["start_day"]	=0;
	if( !array_key_exists( "end_year", $params)) $params["end_year"]	=intval( date('Y')-3);
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
	  $query = "MATCH (game:Game$status_label)-[:ENDED_WITH]->(first_result:Result)<-[:ACHIEVED]-(first_side:Side)<-[:PLAYED_AS]-(first_player:Player$first_param) 
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
MATCH (game)-[:FINISHED_ON]->(:Line$ending_label)-[:".$rel_type."_HAS_LENGTH]->(plycount:GamePlyCount".
$plycount_ending_label.$plycount_eco_label.")
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
	$params["end_year"]	= intval( date( "Y")+1);
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
	    $moves_ordering_condition = "MATCH (:PlyCount{counter:{maxplies}})<-[:LONGER*0..]-";
	    $left="<"; $right="";
	    
	    if( strlen( $descending)==0) {
	      $moves_ordering_condition = "MATCH (:PlyCount{counter:{minplies}})-[:LONGER*0..]->";
	      $left=""; $right=">";
	    }
/*
	    // Append query with ECO condition
	    // It results in longer and expensive hash join
	    $eco_classification_condition="";
	    if( strlen( $plycount_eco_label))
	      $eco_classification_condition="MATCH (l)-[:CLASSIFIED_AS]->(:EcoCode{code:{eco_code}})";
*/
	    $query = $moves_ordering_condition.
"(p:GamePlyCount".$plycount_ending_label.$plycount_eco_label.") WITH p LIMIT 1 ".
"MATCH (p)".$left."-[:LONGER_$rel_type*0..]-".$right."(:GamePlyCount".$plycount_ending_label.$plycount_eco_label.")<-[:".
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
"MATCH (d:GameDay".$date_ending_label.$date_eco_label.") WITH d ORDER BY d.idx $date_ordering_condition LIMIT 1 ".
"MATCH (d)".$left."-[:NEXT_$rel_type*0..]-".$right."(:GameDay".$date_ending_label.$date_eco_label.")<-[:".
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

//print_r( $params);
//echo $query;

        $result = $neo4j_client->run($query, $params);
	$games = array();
	$index=0;
//var_dump( $result->summarize()); // Returns the ResultSummary
    foreach ($result->records() as $record) {

	// Query params
	$game_params = [];
	$game_params["gid"] = intval( $record->value('gid'));

	$game_query="MATCH (game:Game) WHERE id(game) = {gid} WITH game 
MATCH (year:Year)<-[:OF]-(month:Month)<-[:OF]-(day:Day)<-[:".$rel_type."_WAS_PLAYED_ON_DATE]-(game) 
WITH game, CASE WHEN year.year=0 THEN '0000' ELSE toString(year.year) END+'.'+CASE WHEN month.month<10 THEN '0'+month.month ELSE toString(month.month) END+'.'+CASE WHEN day.day<10 THEN '0'+day.day ELSE toString(day.day) END AS date_str 
MATCH (game)-[:ENDED_WITH]->(white_result:Result)<-[:ACHIEVED]-(white_side:Side:White)<-[:PLAYED_AS]-(white_player:Player) 
MATCH (game)-[:ENDED_WITH]->(black_result:Result)<-[:ACHIEVED]-(black_side:Side:Black)<-[:PLAYED_AS]-(black_player:Player) 
MATCH (game)-[:FINISHED_ON]->(line:Line)-[:".$rel_type."_HAS_LENGTH]->(plycount:GamePlyCount)
MATCH (white_side)-[:RATED]->(white_elo:Elo)
MATCH (black_side)-[:RATED]->(black_elo:Elo)
MATCH (line)-[:CLASSIFIED_AS]->(eco_code:EcoCode) 
MATCH (game)-[:WAS_PLAYED_IN]->(round:Round)
MATCH (game)-[:WAS_PART_OF]->(event:Event)
MATCH (game)-[:TOOK_PLACE_AT]->(site:Site)
RETURN game, white_player.name, black_player.name, date_str, eco_code.code,
	event.name, round.name, site.name, white_elo.rating, black_elo.rating, plycount.counter, white_result
LIMIT 1";
//var_dump( $game_params);
//echo $game_query;

        $game_result = $neo4j_client->run($game_query, $game_params);
	foreach ($game_result->records() as $game_record) {
        $gameObj = $game_record->get('game');
        $games[$index]['ID'] = $gameObj->identity();
        $games[$index]['White'] = $game_record->value('white_player.name');
        $games[$index]['ELO_W'] = $game_record->value('white_elo.rating')==0?"":$game_record->value('white_elo.rating');
        $games[$index]['Black'] = $game_record->value('black_player.name');
        $games[$index]['ELO_B'] = $game_record->value('black_elo.rating')==0?"":$game_record->value('black_elo.rating');
        $games[$index]['Date']  = $game_record->value('date_str');
        $games[$index]['ECO']   = $game_record->value('eco_code.code');
        $games[$index]['Event'] = $game_record->value('event.name');
        $games[$index]['Round'] = $game_record->value('round.name');
        $games[$index]['Site']  = $game_record->value('site.name');
        $games[$index]['Moves'] = round( $game_record->value('plycount.counter')/2, 0, PHP_ROUND_HALF_UP);
	$labelsObj = $game_record->get('white_result');
	$labelsArray = $labelsObj->labels();
	if( in_array( "Draw", $labelsArray))
          $games[$index]['Result'] = "1/2-1/2";
	else if( in_array( "Win", $labelsArray))
          $games[$index]['Result'] = "1-0";
	else
          $games[$index]['Result'] = "0-1";
/*
        if($gameObj->hasValue('W_cheat_score'))
        $games[$index]['W_cheat_score'] = $gameObj->value('W_cheat_score');
        if($gameObj->hasValue('B_cheat_score'))
        $games[$index]['B_cheat_score'] = $gameObj->value('B_cheat_score');
        if($gameObj->hasValue('status'))
        $games[$index]['Status'] = $gameObj->value('status');
*/
	}
	$index++;
    }

	$event = $stopwatch->stop('loadGames');

	// Encode in JSON and output
        return new JsonResponse( $games);
//var_dump($games);
    }
}

