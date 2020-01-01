<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use GraphAware\Neo4j\Client\ClientInterface;
use Symfony\Component\Stopwatch\Stopwatch;

class GameDetailsController extends AbstractController
{
    const _DEBUG = FALSE;

    // Initial position properties
    const ROOT_FEN = "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1";
    const ROOT_ZOBRIST = "463b96181691fc9c";
    const BASELINES_PER_SIDE = 5;

    // Neo4j client interface reference
    private $neo4j_client;

    // StopWatch instance
    private $stopwatch;

    // Position properties
    private $neo4j_node_id = 0;
    private $neo4j_current_FEN = self::ROOT_FEN;

    // Arrays to keep the data
    private $game = array();
    private $moves = array();

    private $sides = ["White" => "W_", "Black" => "B_"];

    // Dependency injection of the Neo4j ClientInterface
    public function __construct( ClientInterface $client, Stopwatch $watch)
    {
        $this->neo4j_client = $client;
        $this->stopwatch = $watch;
    }

    /**
      * @Route("/getGameDetails")
      */
    public function getGameDetails()
    {

    // or add an optional message - seen by developers
    $this->denyAccessUnlessGranted('ROLE_USER', null, 'User tried to access a page without having ROLE_USER');

	// starts event
	$this->stopwatch->start('getGameDetails');

	// HTTP request
	$request = Request::createFromGlobals();

	// Get game info
	if( $this->getGameInfo( $request->query->getInt('gid', 0))) {

	  // Lap, Game node details fetched
	  $this->stopwatch->lap('getGameDetails');

	  // Get baselines
	  $this->getBaselines();

	  // Lap, baselines fetched
	  $this->stopwatch->lap('getGameDetails');

	  // Get movelist
	  $this->getMoveList( $request->query->getInt('gid', 0));

	  if( self::_DEBUG) {
	    print_r( $this->moves);
	  }

	  // Lap, Movelist fetched
	  $this->stopwatch->lap('getGameDetails');

	  // Get Position nodes data
	  $this->getPositions();

	  // Stop the timer
	  $this->stopwatch->stop('getGameDetails');

	} else unset( $this->game["ID"]);

	// Encode in JSON and output
        return new JsonResponse( $this->game);
    }

    // Get root node id
    private function getRootId()
    {
	$params["ZOBRIST"] = self::ROOT_ZOBRIST;
	$query = 'MATCH (l:Line)-[:COMES_TO]->(:Position {zobrist: {ZOBRIST}}) RETURN id(l) AS id LIMIT 1';
        $result = $this->neo4j_client->run($query, $params);

	foreach ($result->records() as $record) {
  	  $this->neo4j_node_id = $record->value('id');
	}
    }

    // Get move list
    private function getMoveList( $gid)
    {
	// Fetch game move list 
	$params = ["gid" => $gid];
	$query = "MATCH (game:Game) WHERE id(game) = {gid} WITH game
MATCH (game)-[:FINISHED_ON]->(:Line)-[:ROOT*0..]->(l:Line)-[:LEAF]->(ply:Ply)
RETURN REVERSE( COLLECT( ply.san)) as movelist LIMIT 1";
	$result = $this->neo4j_client->run( $query, $params);

	foreach ($result->records() as $record) {
  	  $this->moves = $record->value('movelist');
	}
    }

    // Get game info
    private function getGameInfo( $gid)
    {
	$this->game['ID'] = 0;

	// Fetch game details 
	$params = ["gid" => $gid];
	$query = "MATCH (game:Game) WHERE id(game) = {gid} WITH game
MATCH (year:Year)<-[:OF]-(month:Month)<-[:OF]-(day:Day)<-[:PLAYED_DATE]-(game)
WITH game, 
 CASE WHEN year.year=0 THEN '0000' ELSE toString(year.year) END+'.'+
 CASE WHEN month.month<10 THEN '0'+month.month ELSE toString(month.month) END+'.'+
 CASE WHEN day.day<10 THEN '0'+day.day ELSE toString(day.day) END AS date_str 
 MATCH (game)-[:ENDED_WITH]->(result_w:Result)<-[:ACHIEVED]-(white:Side:White)<-[:PLAYED_AS]-(player_w:Player)
  MATCH (game)-[:ENDED_WITH]->(:Result)<-[:ACHIEVED]-(black:Side:Black)<-[:PLAYED_AS]-(player_b:Player)
  MATCH (white)-[:RATED]->(elo_w:Elo) MATCH (black)-[:RATED]->(elo_b:Elo)
  MATCH (game)-[:PLAYED_IN]->(round:Round)-[:PART_OF]->(event:Event)-[:TOOK_PLACE_AT]->(site:Site)
 RETURN date_str, result_w, event.name, player_b.name, player_w.name, elo_b.rating, elo_w.rating, game LIMIT 1";
	$result = $this->neo4j_client->run( $query, $params);

	foreach ($result->records() as $record) {

	  // Fetch game object
	  $gameObj = $record->get('game');
	  $this->game['ID']	= $gameObj->identity();
	  $this->game['White']  = $record->value('player_w.name');
	  $this->game['W_ELO']  = $record->value('elo_w.rating');
	  $this->game['Black']  = $record->value('player_b.name');
	  $this->game['B_ELO']  = $record->value('elo_b.rating');
	  $this->game['Event']  = $record->value('event.name');
	  $this->game['Date']   = $record->value('date_str');

	  // Result in human readable format
          $labelsObj = $record->get('result_w');
          $labelsArray = $labelsObj->labels();
          if( in_array( "Draw", $labelsArray))
            $this->game['Result'] = "1/2-1/2";
          else if( in_array( "Win", $labelsArray))
            $this->game['Result'] = "1-0";
          else
            $this->game['Result'] = "0-1";

// Optional game properties
$this->game['eResult']="";
$this->game['analyze']="";
$this->game['ECO']="";
$this->game['ECO_opening']="";
$this->game['ECO_variation']="";
$this->game['eval_time']="";
$this->game['eval_date']="";
if($gameObj->hasValue('effective_result'))
        $this->game['eResult'] = $gameObj->value('effective_result');
if($gameObj->hasValue('analyze'))
        $this->game['analyze'] = $gameObj->value('analyze');
if($gameObj->hasValue('ECO'))
        $this->game['ECO'] = $gameObj->value('ECO');
if($gameObj->hasValue('opening'))
        $this->game['ECO_opening'] = $gameObj->value('opening');
if($gameObj->hasValue('variation'))
        $this->game['ECO_variation'] = $gameObj->value('variation');
if($gameObj->hasValue('eval_time'))
        $this->game['eval_time'] = $gameObj->value('eval_time');
if($gameObj->hasValue('eval_date'))
        $this->game['eval_date'] = date('Y-m-d H:i:s.u',$gameObj->value('eval_date')/1000);

// Side specific properties
foreach( $this->sides as $side => $prefix) {

$this->game[$prefix.'Plies']="";
$this->game[$prefix.'Analyzed']="";
$this->game[$prefix.'ECOs']="";
$this->game[$prefix.'ECO_rate']="";
$this->game[$prefix.'T1']="";
$this->game[$prefix.'T1_rate']="";
$this->game[$prefix.'T2']="";
$this->game[$prefix.'T2_rate']="";
$this->game[$prefix.'T3']="";
$this->game[$prefix.'T3_rate']="";
$this->game[$prefix.'ET3']="";
$this->game[$prefix.'ET3_rate']="";
$this->game[$prefix.'Best']="";
$this->game[$prefix.'Best_rate']="";
$this->game[$prefix.'Sound']="";
$this->game[$prefix.'Sound_rate']="";
$this->game[$prefix.'Forced']="";
$this->game[$prefix.'Forced_rate']="";
$this->game[$prefix.'Deltas']="";
$this->game[$prefix.'avg_diff']="";
$this->game[$prefix.'median']="";
$this->game[$prefix.'std_dev']="";
$this->game[$prefix.'cheat_score']="";
$this->game[$prefix.'perp_len']="";
if($gameObj->hasValue($prefix.'Analyzed'))
        $this->game[$prefix.'Analyzed'] = $gameObj->value($prefix.'Analyzed');
if($gameObj->hasValue($prefix.'Plies'))
        $this->game[$prefix.'Plies'] = $gameObj->value($prefix.'Plies');
if($gameObj->hasValue($prefix.'ECOs'))
        $this->game[$prefix.'ECOs'] = $gameObj->value($prefix.'ECOs');
if($gameObj->hasValue($prefix.'ECO_rate'))
        $this->game[$prefix.'ECO_rate'] = $gameObj->value($prefix.'ECO_rate');
if($gameObj->hasValue($prefix.'T1'))
        $this->game[$prefix.'T1'] = $gameObj->value($prefix.'T1');
if($gameObj->hasValue($prefix.'T1_rate'))
        $this->game[$prefix.'T1_rate'] = $gameObj->value($prefix.'T1_rate');
if($gameObj->hasValue($prefix.'T2'))
        $this->game[$prefix.'T2'] = $gameObj->value($prefix.'T2');
if($gameObj->hasValue($prefix.'T2_rate'))
        $this->game[$prefix.'T2_rate'] = $gameObj->value($prefix.'T2_rate');
if($gameObj->hasValue($prefix.'T3'))
        $this->game[$prefix.'T3'] = $gameObj->value($prefix.'T3');
if($gameObj->hasValue($prefix.'T3_rate'))
        $this->game[$prefix.'T3_rate'] = $gameObj->value($prefix.'T3_rate');
if($gameObj->hasValue($prefix.'T3'))
        $this->game[$prefix.'ET3'] = $gameObj->value($prefix.'ET3');
if($gameObj->hasValue($prefix.'ET3_rate'))
        $this->game[$prefix.'ET3_rate'] = $gameObj->value($prefix.'ET3_rate');
if($gameObj->hasValue($prefix.'Best'))
        $this->game[$prefix.'Best'] = $gameObj->value($prefix.'Best');
if($gameObj->hasValue($prefix.'Best_rate'))
        $this->game[$prefix.'Best_rate'] = $gameObj->value($prefix.'Best_rate');
if($gameObj->hasValue($prefix.'Sound'))
        $this->game[$prefix.'Sound'] = $gameObj->value($prefix.'Sound');
if($gameObj->hasValue($prefix.'Sound_rate'))
        $this->game[$prefix.'Sound_rate'] = $gameObj->value($prefix.'Sound_rate');
if($gameObj->hasValue($prefix.'Forced'))
        $this->game[$prefix.'Forced'] = $gameObj->value($prefix.'Forced');
if($gameObj->hasValue($prefix.'Forced_rate'))
        $this->game[$prefix.'Forced_rate'] = $gameObj->value($prefix.'Forced_rate');
if($gameObj->hasValue($prefix.'Deltas'))
        $this->game[$prefix.'Deltas'] = $gameObj->value($prefix.'Deltas');
if($gameObj->hasValue($prefix.'avg_diff'))
        $this->game[$prefix.'avg_diff'] = $gameObj->value($prefix.'avg_diff');
if($gameObj->hasValue($prefix.'median'))
        $this->game[$prefix.'median'] = $gameObj->value($prefix.'median');
if($gameObj->hasValue($prefix.'std_dev'))
        $this->game[$prefix.'std_dev'] = $gameObj->value($prefix.'std_dev');
if($gameObj->hasValue($prefix.'cheat_score'))
        $this->game[$prefix.'cheat_score'] = $gameObj->value($prefix.'cheat_score');
if($gameObj->hasValue($prefix.'perp_len'))
        $this->game[$prefix.'perp_len'] = $gameObj->value($prefix.'perp_len');
}
	}

	return $this->game['ID'];
    }

    // Get baselines
    private function getBaselines()
    {

// Baseline type based on the Result property
$bl_type = ["White" => ["1-0" => "WhiteWin", "1/2-1/2" => "WhiteDraw", "0-1" => "WhiteLoss"],
             "Black" => ["0-1" => "BlackWin", "1/2-1/2" => "BlackDraw", "1-0" => "BlackLoss"]];

// Fetch baselines for both sides
foreach( $this->sides as $side => $prefix) {

// If we know effective result switch to it
$game_result=$this->game['Result'];
if( strlen( $this->game['eResult'])>0)
  $game_result=$this->game['eResult'];

// Get baselines for both players
$params = ["Name" => $this->game[$side], "Plies" => intval( $this->game[$prefix.'Plies']),
 "ELO" => intval( $this->game[$prefix.'ELO']), "cELO" => intval( $this->game[$prefix.'cheat_score'])];
//$query = 'MATCH (p:Player{ name: $Name })<-[:'.$bl_type[$side][$game['Result']].']-(b:Baseline) 
// WHERE b.min_plies<$Plies AND b.ELO_min<$ELO AND b.ELO_max>$ELO 
//$query = 'MATCH (p:Player)<-[:'.$bl_type[$side][$game['Result']].']-(b:Baseline) 
// ORDER BY p.name is slooooooooooooooooooooow
$query = 'MATCH (p:Player)<-[:'.$bl_type[$side][$game_result].']-(b:Baseline) 
 WHERE p.name IN["Aggregate Data Player", $Name] AND b.min_plies<=$Plies 
 AND ((b.ELO_min<=$ELO AND b.ELO_max>$ELO) OR (b.ELO_min<=$cELO AND b.ELO_max>$cELO))
 RETURN b ORDER BY abs(b.cheat_score-$ELO) LIMIT ' . self::BASELINES_PER_SIDE;
$result = $this->neo4j_client->run( $query, $params);

$baselines = array();

	foreach ($result->records() as $record) {
$baselineObj = $record->get('b');
if( $baselineObj->hasValue('games'))
$baseline['Games']              = $baselineObj->value('games');
if( $baselineObj->hasValue('Plies'))
$baseline['Plies']              = $baselineObj->value('Plies');
if( $baselineObj->hasValue('Analyzed'))
$baseline['Analyzed']           = $baselineObj->value('Analyzed');
if( $baselineObj->hasValue('min_plies'))
$baseline['min_plies']          = $baselineObj->value('min_plies');
if( $baselineObj->hasValue('ELO_min'))
$baseline['ELO_min']            = $baselineObj->value('ELO_min');
if( $baselineObj->hasValue('ELO_max'))
$baseline['ELO_max']            = $baselineObj->value('ELO_max');
if( $baselineObj->hasValue('ECOs'))
$baseline['ECOs']               = $baselineObj->value('ECOs');
if( $baselineObj->hasValue('ECO_rate'))
$baseline['ECO_rate']           = $baselineObj->value('ECO_rate');
if( $baselineObj->hasValue('T1'))
$baseline['T1']                 = $baselineObj->value('T1');
if( $baselineObj->hasValue('T1_rate'))
$baseline['T1_rate']            = $baselineObj->value('T1_rate');
if( $baselineObj->hasValue('Best'))
$baseline['Best']               = $baselineObj->value('Best');
if( $baselineObj->hasValue('Best_rate'))
$baseline['Best_rate']          = $baselineObj->value('Best_rate');
if( $baselineObj->hasValue('T2'))
$baseline['T2']                 = $baselineObj->value('T2');
if( $baselineObj->hasValue('T2_rate'))
$baseline['T2_rate']            = $baselineObj->value('T2_rate');
if( $baselineObj->hasValue('T3'))
$baseline['T3']                 = $baselineObj->value('T3');
if( $baselineObj->hasValue('T3_rate'))
$baseline['T3_rate']            = $baselineObj->value('T3_rate');
if( $baselineObj->hasValue('ET3'))
$baseline['ET3']                = $baselineObj->value('ET3');
if( $baselineObj->hasValue('ET3_rate'))
$baseline['ET3_rate']           = $baselineObj->value('ET3_rate');
if( $baselineObj->hasValue('Sound'))
$baseline['Sound']              = $baselineObj->value('Sound');
if( $baselineObj->hasValue('Sound_rate'))
$baseline['Sound_rate']         = $baselineObj->value('Sound_rate');
if( $baselineObj->hasValue('Forced'))
$baseline['Forced']             = $baselineObj->value('Forced');
if( $baselineObj->hasValue('Forced_rate'))
$baseline['Forced_rate']        = $baselineObj->value('Forced_rate');
if( $baselineObj->hasValue('Deltas'))
$baseline['Deltas']             = $baselineObj->value('Deltas');
if( $baselineObj->hasValue('avg_diff'))
$baseline['avg_diff']           = $baselineObj->value('avg_diff');
if( $baselineObj->hasValue('median'))
$baseline['median']             = $baselineObj->value('median');
if( $baselineObj->hasValue('std_dev'))
$baseline['std_dev']            = $baselineObj->value('std_dev');
if( $baselineObj->hasValue('cheat_score'))
$baseline['cheat_score']        = $baselineObj->value('cheat_score');
if( $baselineObj->hasValue('perp_len'))
$baseline['perp_len']   = $baselineObj->value('perp_len');

$baselines[] = $baseline;
	}
$this->game[$prefix.'baselines'] = $baselines;
}
    }

    // Get Positions data
    private function getPositions()
    {
	// Get root node details
	$this->getRootId();

	// Lap, root position node details fetched
	$this->stopwatch->lap('getGameDetails');

	// Get Positions data
	$FENs      = array();
	$zkeys     = array();
	$scores    = array();
	$evals     = array();
	$marks     = array();
	$ECOs      = array();
	$openings  = array();
	$variations= array();
	$depths    = array();
	$times     = array();
	$T1_moves  = array();
	$T1_FENs   = array();
	$T1_scores = array();
	$T1_depths = array();
	$T1_times  = array();

	// Lets go through all the moves in the movelist
	foreach( $this->moves as $key => $move) {

	  $ECOs[$key]    = "";
          $openings[$key]    = "";
          $variations[$key]    = "";
          $evals[$key]    = "";
          $marks[$key]    = "";
          $scores[$key]    = "";
          $depths[$key]    = "";
          $times[$key]    = "";
          $T1_moves[$key]    = "";
          $T1_scores[$key]    = "";
          $T1_depths[$key]    = "";
          $T1_times[$key]    = "";

	  // :Score indexes
	  $move_score_idx = -1;
	  $best_score_idx = -1;

	  // Best evaluation path
	  $best_eval_id = null;
	  $var_start_id = null;
	  $var_end_id = null;

	  // Forced move flag
	  $forced_move_flag = FALSE;

	  $params = ["move" => $move, "node_id" => intval( $this->neo4j_node_id)];

	  // Get best :Evaluation :Score for the actual game move
	  $query = 'MATCH (l1:Line)<-[:ROOT]-(l2:Line)-[:LEAF]->(:Ply{san: {move}}) 
WHERE id(l1) = {node_id}
OPTIONAL MATCH (l2)-[:COMES_TO]->(:Position)-[:KNOWN_AS]->(o:Opening)-[:PART_OF]->(e:EcoCode)
OPTIONAL MATCH (l2)-[:HAS_GOT]->(v:Evaluation)-[:RECEIVED]->(score:Score)
OPTIONAL MATCH (sd:SelDepth)<-[:REACHED]-(v)-[:REACHED]->(d:Depth)
OPTIONAL MATCH (msec:MilliSecond)<-[:TOOK]-(v)-[:TOOK]->(second:Second)-[:OF]->(m:Minute)-[:OF]->(h:Hour)
RETURN l2, o.opening, o.variation, e.code, score, d.level, sd.level, m.minute, second.second, msec.ms
ORDER BY score.idx LIMIT 1';
	  $result = $this->neo4j_client->run( $query, $params);

	  if( self::_DEBUG) {
	    echo $query;
	    print_r( $params);
	  }

	  // Get actual move data
	  foreach ($result->records() as $record) {

//            $this->neo4j_node_id  = $record->value('id');
            $ECOs[$key] = $record->value('e.code');
            $openings[$key] = $record->value('o.opening');
            $variations[$key] = $record->value('o.variation');
            $scores[$key] = "";
            $depths[$key] = $record->value('d.level');
            $times[$key] = str_pad( $record->value('m.minute'), 2, '0', STR_PAD_LEFT) . ":" .
	      str_pad( $record->value('second.second'), 2, '0', STR_PAD_LEFT) . "." .
	      str_pad( $record->value('msec.ms'), 3, '0', STR_PAD_LEFT);
            $evals[$key] = "";

	    // Check for special mark labels
            $marks[$key] = "";
	    $lineObj = $record->get('l2');
            $this->neo4j_node_id  = $lineObj->identity();
            $lineLabelsArray = $lineObj->labels();
            if( in_array( "Forced", $lineLabelsArray)) {
              $marks[$key] = "Forced";
	      $forced_move_flag = TRUE;
	    }

	    // If we have an :Evaluation :Score for the move
	    if( $scoreObj = $record->get('score')) {

	      if( self::_DEBUG) {
	        echo $move;
	        print_r( $scoreObj);
	      }

	      // Save :Score idx for later comparison
	      $move_score_idx = $scoreObj->value('idx');

	      // Parse :Score labels
              $scoreLabelsArray = $scoreObj->labels();
              if( in_array( "MateLine", $scoreLabelsArray))
                $scores[$key] = "M" . $scoreObj->value('score');
	      else if ( in_array( "Pawn", $scoreLabelsArray))
                $scores[$key] = "P" . $scoreObj->value('score');
	      else
                $scores[$key] = "" . $scoreObj->value('score');
	    }
	  }

	  if( self::_DEBUG) {
	    echo $move_score_idx." ".$best_score_idx."</br>\n";
	  }

	// No need to check for alternative moves if we have forced line
	if( !$forced_move_flag) {

	  // Get best evaluation for the alternative moves
	  $query = 'MATCH (l:Line) WHERE id(l) = {node_id}
OPTIONAL MATCH (l)<-[:ROOT]-(vl:Line)-[:HAS_GOT]->(v:Evaluation)-[:RECEIVED]->(score:Score)
RETURN score.idx, id(vl) AS var_start_id, id(v) AS best_eval_id
ORDER BY score.idx LIMIT 1';
	  $result = $this->neo4j_client->run( $query, $params);

	  if( self::_DEBUG) {
	    echo $query;
	    print_r( $params);
	  }

	  // Get Best Move data
	  // Save :Score idx for later comparison
	  foreach ($result->records() as $record) {
            $best_score_idx = $record->value('score.idx');
	    $var_start_id = $record->value('var_start_id');
	    $best_eval_id = $record->value('best_eval_id');
	  }
	}

	if( self::_DEBUG) {
	  echo $move_score_idx." ".$best_score_idx."</br>\n";
	}

	// Actual move better than best line score
	if( $best_score_idx >= $move_score_idx)
          $marks[$key] = "Best";
	
	else {	// If the scores do NOT match add better variation

	  $params["best_eval_id"] = intval( $best_eval_id);
	  $params["var_start_id"] = intval( $var_start_id);

	  $query = 'MATCH (v:Evaluation)-[:RECEIVED]->(score:Score) 
WHERE id(v) = {best_eval_id}
MATCH (l:Line) WHERE id(l) = {var_start_id}
OPTIONAL MATCH (v)-[:PROPOSED]->(vl:Line)
OPTIONAL MATCH (sd:SelDepth)<-[:REACHED]-(v)-[:REACHED]->(d:Depth)
OPTIONAL MATCH (msec:MilliSecond)<-[:TOOK]-(v)-[:TOOK]->(second:Second)-[:OF]->(m:Minute)-[:OF]->(h:Hour)
RETURN id(vl) AS var_end_id, score, d.level, sd.level, m.minute, second.second, msec.ms
LIMIT 1';
	  $result = $this->neo4j_client->run( $query, $params);

	  if( self::_DEBUG) {
	    echo $query;
	    print_r( $params);
	  }

	  foreach ($result->records() as $record) {

            $var_end_id = $record->get('var_end_id');
	    $params["var_end_id"] = intval( $var_end_id);
            $T1_depths[$key] = $record->value('d.level');
            $T1_times[$key] = str_pad( $record->value('m.minute'), 2, '0', STR_PAD_LEFT) . ":" .
	      str_pad( $record->value('second.second'), 2, '0', STR_PAD_LEFT) . "." .
	      str_pad( $record->value('msec.ms'), 3, '0', STR_PAD_LEFT);

	    // If we have an :Evaluation :Score for the move
	    if( $scoreObj = $record->get('score')) {

	      // Parse :Score labels
              $scoreLabelsArray = $scoreObj->labels();
              if( in_array( "MateLine", $scoreLabelsArray))
                $T1_scores[$key] = "M" . $scoreObj->value('score');
	      else if ( in_array( "Pawn", $scoreLabelsArray))
                $T1_scores[$key] = "P" . $scoreObj->value('score');
	      else
                $T1_scores[$key] = "" . $scoreObj->value('score');
	    }
	  }
//	  print_r( $T1_moves[$key]);

	// Fetch the variation path
	if( $var_end_id)

	  $query = 'MATCH (l:Line) WHERE id(l) = {var_start_id}
MATCH (vl:Line) WHERE id(vl) = {var_end_id}
MATCH path = (l)<-[:ROOT*1..9]-(vl) 
UNWIND nodes(path) AS n MATCH (n)-[:LEAF]->(ply:Ply)
RETURN COLLECT( ply.san) AS variationLine
LIMIT 1';
	
	else

	  $query = 'MATCH (l:Line) WHERE id(l) = {var_start_id}
MATCH (l)-[:LEAF]->(ply:Ply)
RETURN COLLECT( ply.san) AS variationLine
LIMIT 1';

	  $result = $this->neo4j_client->run( $query, $params);

	  foreach ($result->records() as $record) {

            $variationArr = $record->get('variationLine');

	    if( self::_DEBUG) {
	      print_r( $variationArr);
	    }

	    $T1_moves[$key]       = $variationArr;
	  }
	}

	}
/*
        // All we need is the two nodes and T1 path
 $positionObj           = $record->get('a');
 $relationObj           = $record->get('p');
 $endPositionObj        = $record->get('b');
 $pathObj               = $record->get('path');

 // Fill in arrays
 if($endPositionObj->hasValue('FEN'))
  $FENs[$key] = $endPositionObj->value('FEN');
 else $p_FENs[$key] = "";
 if($endPositionObj->hasValue('zobrist'))
  $zkeys[$key] = $endPositionObj->value('zobrist');
 else $p_zkeys[$key] = "";
// if($relationObj->hasValue('ECO'))
//  $ECOs[$key] = $relationObj->value('ECO');
// else $ECOs[$key] = "";
 if($endPositionObj->hasValue('ECO'))
  $ECOs[$key] = $endPositionObj->value('ECO');
 else $ECOs[$key] = "";
 if($endPositionObj->hasValue('opening'))
  $openings[$key] = $endPositionObj->value('opening');
 else $openings[$key] = "";
 if($endPositionObj->hasValue('variation'))
  $variations[$key] = $endPositionObj->value('variation');
 else $variations[$key] = "";
 if($relationObj->hasValue('score'))
  $scores[$key] = $relationObj->value('score');
 else $scores[$key] = "";
 if($relationObj->hasValue('depth'))
  $depths[$key] = $relationObj->value('depth');
 else $depths[$key] = "";
 if($relationObj->hasValue('time'))
  $times[$key] = $relationObj->value('time');
 else $times[$key] = "";
 if($relationObj->hasValue('eval'))
  $evals[$key] = $relationObj->value('eval');
 else $evals[$key] = "";
 if($relationObj->hasValue('mark'))
  $marks[$key] = $relationObj->value('mark');
 else $marks[$key] = "";

  $T1_FENs[$key]        = array();
  $T1_zkeys[$key]       = array();
  $T1_moves[$key]       = array();
  $T1_scores[$key]      = array();
  $T1_depths[$key]      = array();
  $T1_times[$key]       = array();
 // If we get any moves in T1 line save it as variation
// if( $evals[$key] != "T1" && $pathObj && $pathObj->length()) 
 if( $pathObj && $pathObj->length()) {

  $path_moves   = array();
  $path_FENs    = array();
  $path_zkeys   = array();
  $path_scores  = array();
  $path_depths  = array();
  $path_times   = array();

  // And the nodes connected by these rels
  foreach( $pathObj->nodes() as $path_node) {
        if($path_node->hasValue('FEN'))
        $path_FENs[] = $path_node->value('FEN');
        else $path_FENs[] = "";
        if($path_node->hasValue('zobrist'))
        $path_zkeys[] = $path_node->value('zobrist');
        else $path_zkeys[] = "";
  }
  $T1_FENs[$key]        = $path_FENs;
  $T1_zkeys[$key]       = $path_zkeys;

  // Get all the relations in variation
  foreach( $pathObj->relationships() as $pkey => $path_rels) {
        if($path_rels->hasValue('move'))
        $path_moves[$pkey] = $path_rels->value('move');
        else $path_moves[$pkey] = "";
        if($path_rels->hasValue('score'))
        $path_scores[$pkey] = $path_rels->value('score');
        else $path_scores[$pkey] = "";
        if($path_rels->hasValue('depth'))
        $path_depths[$pkey] = $path_rels->value('depth');
        else $path_depths[$pkey] = "";
        if($path_rels->hasValue('time'))
        $path_times[$pkey] = $path_rels->value('time');
        else $path_times[$pkey] = "";
  }
//  array_push($path_moves,"");
  $T1_moves[$key]       = $path_moves;
  $T1_scores[$key]      = $path_scores;
  $T1_depths[$key]      = $path_depths;
  $T1_times[$key]       = $path_times;
 }

 $this->neo4j_node_id  = $endPositionObj->identity();
*/

	// Initialize positions array wit hfirst element
	$positions = array();
	array_push( $positions, array( "", self::ROOT_FEN, self::ROOT_ZOBRIST, "", "", "", "", "", "" ,"" ,"", "" ,""));

	// Add position info for each move
	foreach( $this->moves as $key => $move) {
          $position = array();
          $position[] = $move;
          $position[] = $ECOs[$key];
          $position[] = $openings[$key];
          $position[] = $variations[$key];
          $position[] = $evals[$key];
          $position[] = $marks[$key];
          $position[] = $scores[$key];
          $position[] = $depths[$key];
          $position[] = $times[$key];
          $position[] = $T1_moves[$key];
          $position[] = $T1_scores[$key];
          $position[] = $T1_depths[$key];
          $position[] = $T1_times[$key];

/*
          $position[] = $FENs[$key];
          $position[] = $zkeys[$key];

          $position[] = $T1_FENs[$key];
          $position[] = $T1_zkeys[$key];
*/
          $positions[] = $position;
	}

	$this->game["Positions"] = $positions;
    }
}

