<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use GraphAware\Neo4j\Client\ClientInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Psr\Log\LoggerInterface;
use App\Service\CacheFileFetcher;
use App\Service\GameManager;

class GameDetailsController extends AbstractController
{
    const _DEBUG = FALSE;

    private $logger;
    private $fetcher;
    private $gameManager;

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
    private $ECOs = array();
    private $openings = array();
    private $variations = array();
    private $marks = array();
    private $scores = array();
    private $depths = array();
    private $times = array();
    private $var_moves = array();
    private $var_depths = array();
    private $var_scores = array();
    private $var_times = array();
/*
    private $T1_moves = array();
    private $T1_depths = array();
    private $T1_scores = array();
    private $T1_times = array();
*/
    private $sides = ["White" => "W_", "Black" => "B_"];

    // Dependency injection of the Neo4j ClientInterface
    public function __construct( ClientInterface $client, Stopwatch $watch, 
	LoggerInterface $logger, CacheFileFetcher $fetcher, GameManager $gm)
    {
        $this->neo4j_client = $client;
        $this->stopwatch = $watch;
        $this->logger = $logger;
        $this->fetcher = $fetcher;
        $this->gameManager = $gm;

	foreach( $this->sides as $prefix) {
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
	}
    }

    /**
      * @Route("/getGameDetails")
      * @Security("is_granted('IS_AUTHENTICATED_ANONYMOUSLY')")
      */
    public function getGameDetails()
    {
        // or add an optional message - seen by developers
//        $this->denyAccessUnlessGranted('ROLE_USER', null, 'User tried to access a page without having ROLE_USER');

	// starts event
	$this->stopwatch->start('getGameDetails');

	// HTTP request
	$request = Request::createFromGlobals();

	// Get game id from request
	$this->game['ID'] = $request->query->getInt('gid', -1);

	// Get game info
	if( $this->getGameInfo()) {

	  // Lap, Game node details fetched
	  $this->stopwatch->lap('getGameDetails');
	  $this->stopwatch->start('getBaselines');

	  // Get summary
	  $this->getSummary();

	  // Get baselines
//	  $this->getBaselines();

	  // Lap, baselines fetched
	  $this->stopwatch->stop('getBaselines');
	  $this->stopwatch->lap('getGameDetails');
	  $this->stopwatch->start('getMoveList');

	  // Get movelist
//	  $this->getMoveList( $request->query->getInt('gid', 0));
	  $this->getMoveList();

	  if( self::_DEBUG) {
	    print_r( $this->moves);
	  }

	  // Lap, Movelist fetched
	  $this->stopwatch->stop('getMoveList');
	  $this->stopwatch->lap('getGameDetails');
	  $this->stopwatch->start('getPositions');

	  // Get Position nodes data
	  $this->getPositions();

	  // Stop the timer
	  $this->stopwatch->stop('getPositions');
	  $this->stopwatch->stop('getGameDetails');

	} else unset( $this->game["ID"]);

	// Encode in JSON and output
        return new JsonResponse( $this->game);
    }
/*
    // Get random (:Game) id
    private function getRandomGameId()
    {
	// Use SKIP to get a pseudo random game
	$params["SKIP"] = rand(0,100);
	$params["counter"] = rand(20,220);
//	$query = 'MATCH (g:Game)-[:FINISHED_ON]->(:Line:CheckMate)-[:GAME_HAS_LENGTH]->(p:GamePlyCount)
//WHERE p.counter > {counter} RETURN id(g) AS id SKIP {SKIP} LIMIT 1';
	$query = 'MATCH (:CheckMatePlyCount{counter:{counter}})-[:LONGER_CHECKMATE*0..]->(p:CheckMatePlyCount)
MATCH (p)<-[:CHECKMATE_HAS_LENGTH]-(:CheckMate)<-[:FINISHED_ON]-(g:Game)
RETURN id(g) AS id SKIP {SKIP} LIMIT 1';
        $result = $this->neo4j_client->run($query, $params);

	$gid = 0;
	foreach ($result->records() as $record) {
  	  $gid = $record->value('id');
	}
        $this->logger->debug('Random (:Game) node id'.$gid);

	return $gid;
    }
*/
    // Get root node id
    private function getRootId()
    {
	$params["ZOBRIST"] = self::ROOT_ZOBRIST;
	$query = 'MATCH (l:Line)-[:COMES_TO]->(:Position {zobrist: {ZOBRIST}}) RETURN id(l) AS id LIMIT 1';
        $result = $this->neo4j_client->run($query, $params);

	foreach ($result->records() as $record) {
  	  $this->neo4j_node_id = $record->value('id');
	}
        $this->logger->debug('Root node id '.$record->value('id'));
    }

    // Get move list
    private function getMoveList( )
    {
	$response = $this->fetcher->getFile( $this->game["MoveListHash"].'.json');
	$response_eval = $this->fetcher->getFile( 'evals-'.$this->game["MoveListHash"].'.json');
	if( $response_eval != null) {

//	  $data = $response_eval->toArray();
	  $content = json_decode( $response_eval->getContent(), true);

	  // Parse each ply info (it can be simple SAN or huge array with alternative lines)
	  foreach( $content as $key => $item) {

	    $this->moves[$key]		= "";
	    $this->ECOs[$key]		= "";
	    $this->openings[$key]	= "";
	    $this->variations[$key]	= "";
	    $this->marks[$key]		= "";
	    $this->scores[$key]		= "";
	    $this->depths[$key]		= "";
	    $this->times[$key]		= "";
/*
	    $this->T1_moves[$key]		= array();
	    $this->T1_depths[$key]		= array();
	    $this->T1_scores[$key]		= array();
	    $this->T1_times[$key]		= array();
*/
	    // Up to three alternative lines
	    for( $i=0;$i<3;$i++) {
	      $this->var_moves[$key][$i]	= array();
	      $this->var_depths[$key][$i]	= array();
	      $this->var_scores[$key][$i]	= array();
	      $this->var_times[$key][$i]	= array();
	    }

	    // Only SAN is present
	    if( !is_array( $item)) {

	      $this->moves[$key]	= $item;

	    // An array, can be just SAN with eval info w/o alternatives
	    } else {

	      // SAN should always be there
	      $this->moves[$key]	= $item['san'];

	      // Other items are optional (eco, eval info)
	      if( array_key_exists( 'eco', $item)) 
		$this->ECOs[$key]	= $item['eco'];
	      if( array_key_exists( 'opening', $item)) 
	        $this->openings[$key]	= $item['opening'];
	      if( array_key_exists( 'variation', $item)) 
	        $this->variations[$key]	= $item['variation'];
	      if( array_key_exists( 'mark', $item)) 
	        $this->marks[$key]	= $item['mark'];
	      if( array_key_exists( 'score', $item)) 
	        $this->scores[$key]	= $item['score'];
	      if( array_key_exists( 'depth', $item)) 
	        $this->depths[$key]	= $item['depth'];
	      if( array_key_exists( 'time', $item)) 
	        $this->times[$key]	= $item['time'];
/*
	      // Parse alternative lines for NOT best moves only
	      if( $this->marks[$key] != "Best" 
		&& array_key_exists( 'alt', $item))
*/
	      // Alternative lines are present
	      // It can be a single san or array of SANs or
	      // array of arrays with san and eval info
	      if( array_key_exists( 'alt', $item))

		// Up to 3 alternative lines
		foreach( $item['alt'] as $altkey => $variation)

		// Each alternative line is a SAN or array of eval info
		foreach( $variation as $varkey => $varitem)

		// Only SAN with no eval data
		if( !is_array( $varitem)) {

		  array_push( $this->var_moves[$key][$altkey], $varitem);
		  array_push( $this->var_depths[$key][$altkey], 0);
		  array_push( $this->var_scores[$key][$altkey], "");
		  array_push( $this->var_times[$key][$altkey], "00:00.000");

		} else {

		  array_push( $this->var_moves[$key][$altkey],  $varitem['san']);
		  array_push( $this->var_depths[$key][$altkey],  $varitem['depth']);
		  array_push( $this->var_scores[$key][$altkey],  $varitem['score']);
		  array_push( $this->var_times[$key][$altkey],  $varitem['time']);
		}
/*
		// Only SAN with no eval data
		if( !is_array( $variation)) {
		  array_push( $this->T1_moves[$key],  $variation);
		  array_push( $this->T1_depths[$key], 0);
		  array_push( $this->T1_scores[$key], "");
		  array_push( $this->T1_times[$key],  "00:00.000");
		} else {	
		  array_push( $this->T1_moves[$key],  $variation['san']);
		  array_push( $this->T1_scores[$key], $variation['score']);
		  array_push( $this->T1_depths[$key], $variation['depth']);
		  array_push( $this->T1_times[$key],  $variation['time']);
		}
*/
	    }
	  }
	} else // Only SANs are present
	  $this->moves = $response->toArray();
    }
/*
    // Fetch move list
    private function fetchMoveList( )
    {
 	$store = new Store('/home/chchcom/cache/');
	$client = HttpClient::create();
	$client = new CachingHttpClient($client, $store, ["debug" => true]);

	$URL = 'http://cache.chesscheat.com/'.$this->game["MoveListHash"].'.json';
        $this->logger->debug('URL '.$URL);
	$response = $client->request('GET', $URL); 

	$statusCode = $response->getStatusCode();
	// $statusCode = 200
        $this->logger->debug('Status code '.$statusCode);

	$contentType = $response->getHeaders()['content-type'][0];
        $this->logger->debug('Content type '.$contentType);
	// $contentType = 'application/json'
//	$content = gzdecode( $response->getContent());
	$content = $response->getContent();
//        $this->logger->debug('Content '.$content);
	// $content = '{"id":521583, "name":"symfony-docs", ...}'
//	$this->moves = json_decode( $content);
	$this->moves = $response->toArray();
	// $content = ['id' => 521583, 'name' => 'symfony-docs', ...]
//        $this->logger->debug('Moves '.$this->moves);
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
*/

    // Get game summary for both players
    private function getSummary()
    {
	// Fetch game details 
	$params = ["gid" => $this->game['ID']];
	$query = 'MATCH (game:Game) WHERE id(game) = $gid
MATCH (game)-[:FINISHED_ON]->(line:Line)
OPTIONAL MATCH (line)-[:HAS_GOT]->(summary:Summary)
RETURN summary LIMIT 2';
	$result = $this->neo4j_client->run( $query, $params);

	foreach ($result->records() as $record) {
//	  $this->game['White']  = $record->value('player_w.name');

	  // If summary is null, get next
          $summaryObj = $record->get('summary');
          if( $record->value('summary') == null) continue;

	  // Which side summary is this?
          $labelsArray = $summaryObj->labels();
	  $side ='Black';
          if( in_array( 'White', $labelsArray)) $side = 'White';
	  $prefix = $this->sides[$side];

	if($summaryObj->hasValue('analyzed'))
		$this->game[$prefix.'Analyzed'] = $summaryObj->value('analyzed');
	if($summaryObj->hasValue('plies'))
		$this->game[$prefix.'Plies'] = $summaryObj->value('plies');
	if($summaryObj->hasValue('ecos'))
		$this->game[$prefix.'ECOs'] = $summaryObj->value('ecos');
	if($summaryObj->hasValue('eco_rate'))
		$this->game[$prefix.'ECO_rate'] = $summaryObj->value('eco_rate');
	if($summaryObj->hasValue('t1'))
		$this->game[$prefix.'T1'] = $summaryObj->value('t1');
	if($summaryObj->hasValue('t1_rate'))
		$this->game[$prefix.'T1_rate'] = $summaryObj->value('t1_rate');
	if($summaryObj->hasValue('t2'))
		$this->game[$prefix.'T2'] = $summaryObj->value('t2');
	if($summaryObj->hasValue('t2_rate'))
		$this->game[$prefix.'T2_rate'] = $summaryObj->value('t2_rate');
	if($summaryObj->hasValue('t3'))
		$this->game[$prefix.'T3'] = $summaryObj->value('t3');
	if($summaryObj->hasValue('t3_rate'))
		$this->game[$prefix.'T3_rate'] = $summaryObj->value('t3_rate');
	if($summaryObj->hasValue('t3'))
		$this->game[$prefix.'ET3'] = $summaryObj->value('et3');
	if($summaryObj->hasValue('et3_rate'))
		$this->game[$prefix.'ET3_rate'] = $summaryObj->value('et3_rate');
	if($summaryObj->hasValue('best'))
		$this->game[$prefix.'Best'] = $summaryObj->value('best');
	if($summaryObj->hasValue('best_rate'))
		$this->game[$prefix.'Best_rate'] = $summaryObj->value('best_rate');
	if($summaryObj->hasValue('sound'))
		$this->game[$prefix.'Sound'] = $summaryObj->value('sound');
	if($summaryObj->hasValue('sound_rate'))
		$this->game[$prefix.'Sound_rate'] = $summaryObj->value('sound_rate');
	if($summaryObj->hasValue('forced'))
		$this->game[$prefix.'Forced'] = $summaryObj->value('forced');
	if($summaryObj->hasValue('forced_rate'))
		$this->game[$prefix.'Forced_rate'] = $summaryObj->value('forced_rate');
	if($summaryObj->hasValue('deltas'))
		$this->game[$prefix.'Deltas'] = $summaryObj->value('deltas');
	if($summaryObj->hasValue('mean'))
		$this->game[$prefix.'avg_diff'] = $summaryObj->value('mean');
	if($summaryObj->hasValue('median'))
		$this->game[$prefix.'median'] = $summaryObj->value('median');
	if($summaryObj->hasValue('stddev'))
		$this->game[$prefix.'std_dev'] = $summaryObj->value('stddev');
	if($summaryObj->hasValue('cheatscore'))
		$this->game[$prefix.'cheat_score'] = $summaryObj->value('cheatscore');
	if($summaryObj->hasValue('perplen'))
		$this->game[$prefix.'perp_len'] = $summaryObj->value('perplen');
	}
    }

    // Get game info
    private function getGameInfo()
    {
	// Check if specified game id does not exist, get random game
	if( !$this->gameManager->gameExists( $this->game['ID'])) 
	  $this->game['ID'] = $this->gameManager->getRandomGameId( "checkmate");

	// Game has not been selected
	if( $this->game['ID'] == -1)
	  return false;

	// Fetch game details 
	$params = ["gid" => $this->game['ID']];
	$query = "MATCH (game:Game) WHERE id(game) = {gid} WITH game
MATCH (year:Year)<-[:OF]-(month:Month)<-[:OF]-(day:Day)<-[:GAME_WAS_PLAYED_ON_DATE]-(game)
WITH game, 
 CASE WHEN year.year=0 THEN '0000' ELSE toString(year.year) END+'.'+
 CASE WHEN month.month<10 THEN '0'+month.month ELSE toString(month.month) END+'.'+
 CASE WHEN day.day<10 THEN '0'+day.day ELSE toString(day.day) END AS date_str 
 MATCH (game)-[:ENDED_WITH]->(result_w:Result)<-[:ACHIEVED]-(white:Side:White)<-[:PLAYED_AS]-(player_w:Player)
  MATCH (game)-[:ENDED_WITH]->(:Result)<-[:ACHIEVED]-(black:Side:Black)<-[:PLAYED_AS]-(player_b:Player)
  MATCH (white)-[:RATED]->(elo_w:Elo) MATCH (black)-[:RATED]->(elo_b:Elo)
  MATCH (game)-[:WAS_PLAYED_IN]->(round:Round)
  MATCH (game)-[:WAS_PART_OF]->(event:Event)
  MATCH (game)-[:TOOK_PLACE_AT]->(site:Site)
  MATCH (game)-[:FINISHED_ON]->(line:Line)
  MATCH (line)-[:CLASSIFIED_AS]->(eco:EcoCode)<-[:PART_OF]-(opening:Opening)
 RETURN date_str, result_w, event.name, player_b.name, player_w.name, elo_b.rating, elo_w.rating, game, line.hash,
	eco.code, opening.opening, opening.variation LIMIT 1";
	$result = $this->neo4j_client->run( $query, $params);

	foreach ($result->records() as $record) {

	  // Fetch game object
	  $gameObj = $record->get('game');
//	  $this->game['ID']	= $gameObj->identity();
	  $this->game['White']  = $record->value('player_w.name');
	  $this->game['W_ELO']  = $record->value('elo_w.rating');
	  $this->game['Black']  = $record->value('player_b.name');
	  $this->game['B_ELO']  = $record->value('elo_b.rating');
	  $this->game['Event']  = $record->value('event.name');
	  $this->game['Date']   = $record->value('date_str');
	  $this->game['ECO']	= $record->value('eco.code');
	  $this->game['ECO_opening']	= $record->value('opening.opening');
	  $this->game['ECO_variation']	= $record->value('opening.variation');
	  $this->game['MoveListHash']	= $record->value('line.hash');

	  // Result in human readable format
          $labelsObj = $record->get('result_w');
          $labelsArray = $labelsObj->labels();
          if( in_array( "Draw", $labelsArray))
            $this->game['Result'] = "1/2-1/2";
          else if( in_array( "Win", $labelsArray))
            $this->game['Result'] = "1-0";
          else if( in_array( "Loss", $labelsArray))
            $this->game['Result'] = "0-1";
          else
            $this->game['Result'] = "Unknown";

/*
	// Effective result
	$this->game['eResult']="";
	if($gameObj->hasValue('effective_result'))
        	$this->game['eResult'] = $gameObj->value('effective_result');
//$this->game['analyze']="";
//$this->game['eval_time']="";
//$this->game['eval_date']="";
if($gameObj->hasValue('analyze'))
        $this->game['analyze'] = $gameObj->value('analyze');
if($gameObj->hasValue('eval_time'))
        $this->game['eval_time'] = $gameObj->value('eval_time');
if($gameObj->hasValue('eval_date'))
        $this->game['eval_date'] = date('Y-m-d H:i:s.u',$gameObj->value('eval_date')/1000);
// Side specific properties
foreach( $this->sides as $side => $prefix) {

}
*/
	}

	return true;
    }

    // Get baselines
    private function getBaselines()
    {

// Baseline type based on the Result property
$bl_type = ["White" => ["1-0" => "WhiteWin", "1/2-1/2" => "WhiteDraw", "0-1" => "WhiteLoss", "Unknown" => "WhiteDraw"],
             "Black" => ["0-1" => "BlackWin", "1/2-1/2" => "BlackDraw", "1-0" => "BlackLoss", "Unknown" => "BlackDraw"]];

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
 WHERE p.name IN["Aggregate Data Player", {Name}] AND b.min_plies<={Plies} 
 AND ((b.ELO_min<={ELO} AND b.ELO_max>{ELO}) OR (b.ELO_min<={cELO} AND b.ELO_max>{cELO}))
 RETURN b ORDER BY abs(b.cheat_score-{ELO}) LIMIT ' . self::BASELINES_PER_SIDE;
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
if( false)
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

	// Initialize positions array wit hfirst element
	$positions = array();
//	array_push( $positions, array( "", self::ROOT_FEN, self::ROOT_ZOBRIST, "", "", "", "", "", "" ,"" ,"", "" ,""));
	array_push( $positions, array( "", self::ROOT_FEN, self::ROOT_ZOBRIST, "", "", "", "", "", "" ,"" ,"", "" ,""));

	// Add position info for each move
	foreach( $this->moves as $key => $move) {
          $position = array();
          $position[] = $move;
          $position[] = array_key_exists( $key, $this->ECOs)		?$this->ECOs[$key]	:"";
          $position[] = array_key_exists( $key, $this->openings)	?$this->openings[$key]	:"";
          $position[] = array_key_exists( $key, $this->variations)	?$this->variations[$key]:"";
          $position[] = array_key_exists( $key, $this->marks)		?$this->marks[$key]	:"";
          $position[] = array_key_exists( $key, $this->scores)		?$this->scores[$key]	:"";
          $position[] = array_key_exists( $key, $this->depths)		?$this->depths[$key]	:"";
          $position[] = array_key_exists( $key, $this->times)		?$this->times[$key]	:"";

          $position[] = array_key_exists( $key, $this->var_moves)	?
		[$this->var_moves[$key], $this->var_scores[$key], 
		$this->var_depths[$key], $this->var_times[$key]] : ["","","","",""];

/*
          $position[] = array_key_exists( $key, $this->T1_moves)	?$this->T1_moves[$key]	:[];
          $position[] = array_key_exists( $key, $this->T1_scores)	?$this->T1_scores[$key]	:[];
          $position[] = array_key_exists( $key, $this->T1_depths)	?$this->T1_depths[$key]	:[];
          $position[] = array_key_exists( $key, $this->T1_times)	?$this->T1_times[$key]	:[];
*/
          $positions[] = $position;
	}

	$this->game["Positions"] = $positions;
    }
}
?>
