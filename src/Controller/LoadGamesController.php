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
	$skip_records="";
	if( $page = $request->query->getInt('page', 0)) {
          $skip_records = 'SKIP $SKIP';
          $params["SKIP"] = $page * self::RECORDS_PER_PAGE;
	}

	// Order condition
	$order_by = [
	"Date"		=>	"date_str",
	""		=>	"date_str"
	];
/*
	"Moves"		=>	"g.plycount",
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
        $known_tags = [ "player", 
			"result", 
			"white", "black", 
			"wins", "loses", "draws", 
		"start_year", "start_month", "start_day", "end_year", "end_month", "end_day" ];

	// Opposite arrays
	$opposite_color	 = [ "white" => "black", "black" => "white"];
	$opposite_result = [ "wins" => "loses", "draws" => "draws", "loses" => "wins" ];

	// Neo4j entiries
	$side_colors	 = [ "white" => "White", "black" => "Black"];
	$side_results	 = [ "wins" => "Win", "draws" => "Draw", "loses" => "Loss"];

	// If player color has been specified
	$color_specification_flag=FALSE;

	// Players arays
	$players = [];

	// Sides array
	$sides = [];

	// Results array
	$results = [];

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
//			&& $tag_name == "white" && $results["first"] == "loses") {
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
	if( array_key_exists( "first", $players)) $params["first"] = $players["first"];
	if( array_key_exists( "second", $players)) $params["second"] = $players["second"];
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

	// We have different types of query if player name has been specified
	if( array_key_exists( "first", $params) || array_key_exists( "second", $params))
	  $query = "MATCH (game:Game)-[:ENDED_WITH]->(first_result:Result)<-[:ACHIEVED]-(first_side:Side)<-[:PLAYED_AS]-(first_player:Player$first_param) 
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
MATCH (year:Year)<-[:OF]-(month:Month)<-[:OF]-(day:Day)<-[:PLAYED_DATE]-(game)
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
RETURN DISTINCT id(game) AS gid, date_str 
$order_condition 
$skip_records
LIMIT ".self::RECORDS_PER_PAGE;
	else {
	  $query = strlen( $descending)==0?
"MATCH (:Year{year:{start_year}})<-[:OF]-(:Month{month:{start_month}})<-[:OF]-(:Day{day:{start_day}})-[:NEXT*0..]->(:Day)<-[:PLAYED_DATE]-(game:Game) ":
"MATCH (:Year{year:{end_year}})<-[:OF]-(:Month{month:{end_month}})<-[:OF]-(:Day{day:{end_day}})<-[:NEXT*0..]-(:Day)<-[:PLAYED_DATE]-(game:Game) ";
	  $query .= "
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
MATCH (year:Year)<-[:OF]-(month:Month)<-[:OF]-(day:Day)<-[:PLAYED_DATE]-(game) 
WITH game, CASE WHEN year.year=0 THEN '0000' ELSE toString(year.year) END+'.'+CASE WHEN month.month<10 THEN '0'+month.month ELSE toString(month.month) END+'.'+CASE WHEN day.day<10 THEN '0'+day.day ELSE toString(day.day) END AS date_str 
MATCH (game)-[:ENDED_WITH]->(white_result:Result)<-[:ACHIEVED]-(white_side:Side:White)<-[:PLAYED_AS]-(white_player:Player) 
MATCH (game)-[:ENDED_WITH]->(black_result:Result)<-[:ACHIEVED]-(black_side:Side:Black)<-[:PLAYED_AS]-(black_player:Player) 
MATCH (game)-[:FINISHED_ON]->(:Line)-[:LENGTH]->(plycount:PlyCount)
MATCH (white_side)-[:RATED]->(white_elo:Elo)
MATCH (black_side)-[:RATED]->(black_elo:Elo)
MATCH (game)-[:FROM_PGN]->(eco_code:EcoCode) 
MATCH (game)-[:PLAYED_IN]->(round:Round)-[:PART_OF]->(event:Event)-[:TOOK_PLACE_AT]->(site:Site)
RETURN game, white_player.name, black_player.name, date_str, event.name, round.name, site.name, white_elo.rating, black_elo.rating, plycount.counter, white_result
LIMIT 1";
//var_dump( $game_params);
//echo $game_query;

        $game_result = $neo4j_client->run($game_query, $game_params);
	foreach ($game_result->records() as $game_record) {
        $gameObj = $game_record->get('game');
        $games[$index]['ID'] = $gameObj->identity();
        $games[$index]['White'] = $game_record->value('white_player.name');
        $games[$index]['W_ELO'] = $game_record->value('white_elo.rating');
        $games[$index]['Black'] = $game_record->value('black_player.name');
        $games[$index]['B_ELO'] = $game_record->value('black_elo.rating');
        $games[$index]['Date']  = $game_record->value('date_str');
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

