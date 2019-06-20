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
        $known_tags = [ "player", "white", "black", "wins", "loses", "draws", 
		"result", 
		"start_year", "start_month", "start_day", "end_year", "end_month", "end_day" ];

	// Results array
	$first_results  = [];
	$second_results = [];

	// Side present flag
	$first_side  = "";
	$second_side = "";

	// Parse each tag
	foreach( $tags as $tag)

	// Non empty and has a colon separator
	if( strlen($tag) && strpos($tag, ":") !== FALSE) {
	    
	    $tag_name_value=explode(':', $tag);

	    // Check if we accept the parameter
	    if (in_array( $tag_name_value[0], $known_tags)) {
	
		// Parse the parameter
		switch( $tag_name_value[0]) {

		  case "player":
		    if( array_key_exists( "first", $params))
		      $params["second"] = filter_var($tag_name_value[1], FILTER_SANITIZE_STRING);
		    else
		      $params["first"] = filter_var($tag_name_value[1], FILTER_SANITIZE_STRING);
		    break;

		  case "white":
		    if( array_key_exists( "first", $params)) {
		      $params["second"] = filter_var($tag_name_value[1], FILTER_SANITIZE_STRING);
		      $first_side  = ":Black";
		      $second_side = ":White";
		    } else {
		      $params["first"] = filter_var($tag_name_value[1], FILTER_SANITIZE_STRING);
		      $first_side  = ":White";
		      $second_side = ":Black";
		    }
		    break;

		  case "black":
		    if( array_key_exists( "first", $params)) {
		      $params["second"] = filter_var($tag_name_value[1], FILTER_SANITIZE_STRING);
		      $first_side  = ":White";
		      $second_side = ":Black";
		    } else {
		      $params["first"] = filter_var($tag_name_value[1], FILTER_SANITIZE_STRING);
		      $first_side  = ":Black";
		      $second_side = ":White";
		    }
		    break;

		  case "wins":
		    if( array_key_exists( "first", $params)) {
		      $params["second"] = filter_var($tag_name_value[1], FILTER_SANITIZE_STRING);
		      $first_results[]  = "Loss";
		      $second_results[] = "Win";
		    } else {
		      $params["first"] = filter_var($tag_name_value[1], FILTER_SANITIZE_STRING);
		      $first_results[]  = "Win";
		      $second_results[] = "Loss";
		    }
		    break;

		  case "draws":
		    if( array_key_exists( "first", $params)) {
		      $params["second"] = filter_var($tag_name_value[1], FILTER_SANITIZE_STRING);
		      $first_results[]  = "Draw";
		      $second_results[] = "Draw";
		    } else {
		      $params["first"] = filter_var($tag_name_value[1], FILTER_SANITIZE_STRING);
		      $first_results[]  = "Draw";
		      $second_results[] = "Draw";
		    }

		  case "loses":
		    if( array_key_exists( "first", $params)) {
		      $params["second"] = filter_var($tag_name_value[1], FILTER_SANITIZE_STRING);
		      $first_results[]  = "Win";
		      $second_results[] = "Loss";
		    } else {
		      $params["first"] = filter_var($tag_name_value[1], FILTER_SANITIZE_STRING);
		      $first_results[]  = "Loss";
		      $second_results[] = "Win";
		    }

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
		    switch( $tag_name_value[1]) {
			case "1-0":
		    	  if( !array_key_exists( "first", $params) && $first_side == "") {
			    $first_side  = ":White";
			    $second_side = ":Black";
			  }
			  if( $first_side == ":White") {
			    $first_results[]  = "Win";
			    $second_results[] = "Loss";
			  } else {
			    $first_results[]  = "Loss";
			    $second_results[] = "Win";
			  }
			  break;
			case "0-1":
		    	  if( !array_key_exists( "first", $params) && $first_side == "") {
			    $first_side  = ":Black";
			    $second_side = ":White";
			  }
			  if( $first_side == ":Black") {
			    $first_results[]  = "Win";
			    $second_results[] = "Loss";
			  } else {
			    $first_results[]  = "Loss";
			    $second_results[] = "Win";
			  }
			  break;
		 	default:
			  $first_results[]  = "Draw";
			  $second_results[] = "Draw";
			  break;
		    }
		    break;

		  default:
		    break;
		}
	    }
	}

	// Non-empty array of selected results
	if( !count( $first_results)) array_push($first_results, "Win", "Draw", "Loss");
	$params["first_results"] = $first_results;
	if( !count( $second_results)) array_push($second_results, "Win", "Draw", "Loss");
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
	if( array_key_exists( "first", $params)) $first_param  = "{name:{first}}";
	if( array_key_exists( "second", $params)) $second_param = "{name:{second}}";

	// We have different types of query if player name has been specified
	if( array_key_exists( "first", $params) || array_key_exists( "second", $params))
	  $query = "MATCH (year:Year)<-[:OF]-(month:Month)<-[:OF]-(day:Day)<-[:PLAYED_DATE]-(game:Game) 
MATCH (game)-[:ENDED_WITH]->(first_result:Result)<-[:ACHIEVED]-(:Side$first_side)<-[:PLAYED_AS]-(first_player:Player$first_param) 
MATCH (game)-[:ENDED_WITH]->(second_result:Result)<-[:ACHIEVED]-(:Side$second_side)<-[:PLAYED_AS]-(second_player:Player$second_param) 
WITH year,month,day,game,first_result,second_result,first_player,second_player, 
CASE WHEN year.year=0 THEN '0000' ELSE toString(year.year) END+CASE WHEN month.month<10 THEN '0'+month.month ELSE toString(month.month) END+CASE WHEN day.day<10 THEN '0'+day.day ELSE toString(day.day) END AS date_str, 
CASE WHEN {start_year}=0 THEN '0000' ELSE toString({start_year}) END+CASE WHEN {start_month}<10 THEN '0'+{start_month} ELSE toString({start_month}) END+CASE WHEN {start_day}<10 THEN '0'+{start_day} ELSE toString({start_day}) END AS start_date_str, 
CASE WHEN {end_year}=0 THEN '0000' ELSE toString({end_year}) END+CASE WHEN {end_month}<10 THEN '0'+{end_month} ELSE toString({end_month}) END+CASE WHEN {end_day}<10 THEN '0'+{end_day} ELSE toString({end_day}) END AS end_date_str
  WHERE [x IN labels(first_result) WHERE x IN {first_results}] 
    AND [x IN labels(second_result) WHERE x IN {second_results}] 
    AND start_date_str <= date_str <= end_date_str
RETURN DISTINCT id(game) AS gid, date_str 
$order_condition 
$skip_records
LIMIT ".self::RECORDS_PER_PAGE;
	else {
	  $query = strlen( $descending)==0?
"MATCH (:Year{year:{start_year}})<-[:OF]-(:Month{month:{start_month}})<-[:OF]-(:Day{day:{start_day}})-[:NEXT*0..]->(:Day)<-[:PLAYED_DATE]-(game:Game) ":
"MATCH (:Year{year:{end_year}})<-[:OF]-(:Month{month:{end_month}})<-[:OF]-(:Day{day:{end_day}})<-[:NEXT*0..]-(:Day)<-[:PLAYED_DATE]-(game:Game) ";
	  $query .= "
MATCH (game)-[:ENDED_WITH]->(first_result:Result)<-[:ACHIEVED]-(:Side$first_side) 
MATCH (game)-[:ENDED_WITH]->(second_result:Result)<-[:ACHIEVED]-(:Side$second_side) 
  WHERE [x IN labels(first_result) WHERE x IN {first_results}] 
    AND [x IN labels(second_result) WHERE x IN {second_results}] 
RETURN DISTINCT id(game) AS gid 
$skip_records
LIMIT ".self::RECORDS_PER_PAGE;
	}

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

