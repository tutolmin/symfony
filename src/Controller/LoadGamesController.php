<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

class LoadGamesController extends AbstractController
{
    // Number of games to load and display
    const RECORDS_PER_PAGE = 25;

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
	"Moves"		=>	"g.plycount",
	"Date"		=>	"g.date",
	"Status"	=>	"g.status",
	"ScoreW"	=>	"g.W_cheat_score",
	"ScoreB"	=>	"g.B_cheat_score",
	"DeltaW"	=>	"g.W_cheat_score-toInteger(w.ELO)",
	"DeltaB"	=>	"g.B_cheat_score-toInteger(b.ELO)",
	""		=>	"g.date"
	];

	$sort_cond = $request->query->getAlpha('sort');
	$descending = "";
	if( strpos( $sort_cond, "Desc")) {
	  $sort_cond = substr( $sort_cond, 0, strlen( $sort_cond) - 4);
	  $descending = " DESC";
	}
	$order_condition = "ORDER BY " . $order_by[$sort_cond] . $descending;

	// Use https://api.symfony.com/master/Symfony/Component/Serializer/Encoder/JsonDecode.html instead?
	$query_tags = $request->query->get('tags');
	$tags=explode(';', json_decode( $query_tags));

$player_condition="";
$result_condition="";
$result_mismatch="";
$moves_condition="";
$deltas_condition="";
$qplies_condition="";
$res_cond=[];
$resultType="result";
$mate_condition="";
$game_id_condition="";
$noStatus="";
$gameStatus="AND g.status='4_Complete'";
$gameStatus="";
$ELO_low_condition="";
$ELO_high_condition="";
$ECO_condition="";
//print_r( $tags);
foreach( $tags as $tag) {
if( strlen($tag))
if( is_numeric( $tag)) {	// Game ID was specified
	$game_id_condition=' AND id(g) = $gID';
	$params["gID"] = intval( $tag); 
	break;
} else
switch( $tag) {
	case "3 deltas":
	case "5 deltas":
	case "7 deltas":
	case "10 deltas":
	case "15 deltas":
		$deltas_condition = ' AND g.W_Deltas >= $deltas AND g.B_Deltas >= $deltas';
		preg_match('/([0-9]+)(\ deltas)/', $tag, $matches);
		$params["deltas"] = intval($matches[1]); 
		break;
	case "5 qplies":
	case "10 qplies":
	case "15 qplies":
	case "20 qplies":
	case "25 qplies":
	case "30 qplies":
	case "35 qplies":
	case "40 qplies":
		$qplies_condition = ' AND g.W_Analyzed > $qplies AND g.B_Analyzed > $qplies';
		preg_match('/([0-9]+)(\ qplies)/', $tag, $matches);
		$params["qplies"] = intval($matches[1]); 
		break;
	case "10 moves":
	case "15 moves":
	case "20 moves":
	case "25 moves":
		$moves_condition = ' AND g.W_Plies > $moves AND g.B_Plies > $moves';
		preg_match('/([0-9]+)(\ moves)/', $tag, $matches);
		$params["moves"] = intval($matches[1]); 
		break;
	case "1-0":
	case "0-1":
	case "1/2-1/2":
//		if( strlen( $res_cond)) $res_cond .= ",";
		$res_cond[] = $tag;
		break;
	case "mate":
	case "stalemate":
		$mate_condition="WHERE p.eval='$tag'";
		break;
	case "resultMismatch":
		$result_mismatch="AND g.result<>g.effective_result";
		break;
	case "effectiveResult":
		$resultType="effective_result";
		break;
	case "Pending":
	case "Loaded":
	case "Partly":
	case "Complete":
		$sts=["Complete"=>"4_Complete","Partly"=>"3_Partly","Loaded"=>"2_Loaded","Pending"=>"1_Pending"];
		$gameStatus="AND g.status='".$sts[$tag]."'";
		break;
	default:
	    // Check if Opening code have been specified
	    if( preg_match( '/^[A-E][0-9]{2}$/', $tag)) {
		$ECO_condition=' AND g.ECO = $ECO';
		$params["ECO"] = $tag; 
	    }
	    // ELO rating restriction
	    else if( preg_match( '/^[0-9]{4}[+-]$/', $tag)) {
		preg_match('/([0-9]{4})([+-])/', $tag, $matches);
		if( $matches[2]=='+') {
		  $params["ELO_low"] = intval( $matches[1]); 
		  $ELO_low_condition = 
//' AND exists( g.W_cheat_score) AND toInteger( g.W_cheat_score) > $ELO_low';
' AND exists( w.ELO) AND exists( b.ELO) AND toInteger( w.ELO) > $ELO_low AND toInteger( b.ELO) > $ELO_low';
		} else {
		  $params["ELO_high"] = intval( $matches[1]); 
		  $ELO_high_condition = 
//' AND exists( g.W_cheat_score) AND toInteger( g.W_cheat_score) < $ELO_high';
' AND exists( w.ELO) AND exists( b.ELO) AND toInteger( w.ELO) < $ELO_high AND toInteger( b.ELO) < $ELO_high';
		}
	    }
	    else {	
		$player_condition=' AND (plw.name = $name OR plb.name = $name)';
		$params["name"] = $tag; 
//		$player_condition="{name:'$tag'}";
	    }
		break;
}
}
if( count($res_cond) && strlen($res_cond[0])) {
	$result_condition="AND exists(g.$resultType) AND g.$resultType IN \$result";
	$params["result"] = $res_cond;
}

// Existance check prevents using index
// We have to use nasty workaround instead "g.status CONTAINS '_'"
// WHERE exists(g.status) $gameStatus 
// Do we really need to fetch DISTINCT game id?
$query = "MATCH (plw:Player)-[w:White]->(g:Game)<-[b:Black]-(plb:Player)
 WHERE g.status CONTAINS '_' $gameStatus 
 $game_id_condition $player_condition $ELO_low_condition $ELO_high_condition $ECO_condition 
 $result_condition $result_mismatch $moves_condition $deltas_condition $qplies_condition
 MATCH (g)<--(e:Event) $mate_condition
 RETURN id(g), e.name, plb.name, plw.name, g.plycount, w.ELO, b.ELO, g 
 $order_condition 
 $skip_records 
 LIMIT " . self::RECORDS_PER_PAGE;

        $result = $neo4j_client->run($query, $params);
$games = array();
$index=0;
foreach ($result->records() as $record) {
        $gameObj = $record->get('g');
        $games[$index]['ID'] = $gameObj->identity();
        $games[$index]['White'] = $record->value('plw.name');
        $games[$index]['White_ELO'] = $record->value('w.ELO');
        $games[$index]['Black'] = $record->value('plb.name');
        $games[$index]['Black_ELO'] = $record->value('b.ELO');
        $games[$index]['Event'] = $record->value('e.name');
        $games[$index]['Moves'] = round( $record->value('g.plycount')/2, 0, PHP_ROUND_HALF_UP);
        if($gameObj->hasValue('result'))
        $games[$index]['Result'] = $gameObj->value('result');
        if($gameObj->hasValue('date'))
        $games[$index]['Date'] = $gameObj->value('date');
        if($gameObj->hasValue('W_cheat_score'))
        $games[$index]['W_cheat_score'] = $gameObj->value('W_cheat_score');
        if($gameObj->hasValue('B_cheat_score'))
        $games[$index]['B_cheat_score'] = $gameObj->value('B_cheat_score');
        if($gameObj->hasValue('status'))
        $games[$index]['Status'] = $gameObj->value('status');
	$index++;

}

$event = $stopwatch->stop('loadGames');

	// Encode in JSON and output
        return new JsonResponse( $games);
    }
}

