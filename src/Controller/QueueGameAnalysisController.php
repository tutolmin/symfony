<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use GraphAware\Neo4j\Client\ClientInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use App\Service\PGNFetcher;
use App\Service\PGNUploader;

class QueueGameAnalysisController extends AbstractController
{
    // Neo4j client interface reference
    private $neo4j_client;

    // StopWatch instance
    private $stopwatch;

    // Logger reference
    private $logger;

    // PGN fetcher/uploader
    private $fetcher;
    private $uploader;

    // Necessary ids
    private $queue_id;
    private $analysis_id;
    private $game_id;
    private $wu_id;
    private $gids = array();

    // Analysis parameters
    private $sideLabel;
    private $depth;

    // Previous/Last/First ids
    private $p_id;
    private $l_id;
    private $f_id;

    // Dependency injection of the Neo4j ClientInterface
    public function __construct( ClientInterface $client, Stopwatch $watch, LoggerInterface $logger,
	PGNFetcher $fetcher, PGNUploader $uploader)
    {
        $this->neo4j_client = $client;
        $this->stopwatch = $watch;
	$this->logger = $logger;
	$this->fetcher = $fetcher;
	$this->uploader = $uploader;

        // Init Ids with non-existing values
	$this->queue_id = -1;
	$this->analysis_id = -1;
	$this->game_id = -1;
	$this->p_id = -1;
	$this->l_id = -1;
	$this->f_id = -1;

        // Default analysis parameters
	$this->sideLabel=":WhiteSide:BlackSide";
	$this->depth = $_ENV['DEFAULT_ANALYSIS_DEPTH'];

	// starts event named 'eventName'
	$this->stopwatch->start('queueGameAnalysis');

	// Maintenane operation
	$this->updateCurrentQueueNode();
    }

    public function __destruct()
    {
	// stops event named 'eventName'
	$this->stopwatch->stop('queueGameAnalysis');
    }

    // Update (:Current) pointer for a queue
    private function updateCurrentQueueNode()
    {
	$query = 'MATCH (:Queue:Head)-[:FIRST]->(:Analysis)-[:NEXT*0..]->(p:Pending) 
WITH p LIMIT 1
MATCH (p)<-[:QUEUED]-(q:Queue)
MATCH (c:Queue:Current)
REMOVE c:Current SET q:Current';
        $result = $this->neo4j_client->run($query, null);
    }

    // Match existing Analysis request
    private function matchAnalysisRequest()
    {
	$query = 'MATCH (g:Game) WHERE id(g) = {gid}
MATCH (g)-[:FINISHED_ON]->(l:Line)
MATCH (d:Depth{level:{depth}})
MATCH (l)<-[:PERFORMED_ON]-(a:Analysis'.$this->sideLabel.')-[:REQUIRED_DEPTH]->(d)
RETURN id(a) AS aid LIMIT 1';

	$params = ["gid" => intval( $this->game_id), 
	  "depth" => intval( $this->depth)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('aid') != "null") {
	    $this->analysis_id = $record->value('aid');
	    return TRUE; 
          }

	// Return FALSE by default
	return FALSE;
    }

    // Find Game in the database
    private function isValidGame()
    {
        $query = 'MATCH (g:Game)
WHERE id(g) = {gid}
RETURN id(g) AS gid LIMIT 1';

        $params = ["gid" => intval( $this->game_id)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
	  if( $record->value('gid') != "null") {
	    $this->game_id = $record->value('gid');
            return TRUE; 
          }

        // Return 
        return FALSE;
    }

    // Find appropriate (:Queue) node for a given (:WebUser) to attach the (:Analysis)
    private function findWebUserQueueNode()
    {
        $query = 'MATCH (w:WebUser{id:{wuid}})
MATCH (:Current)-[:NEXT*0..]->(q:Queue) 
WHERE NOT (q)-[:QUEUED]->(:Analysis)-[:REQUESTED_BY]->(w) 
RETURN id(q) AS qid LIMIT 1';

	$this->wu_id = $this->getUser()->getId();
        $params = ["wuid" => intval( $this->wu_id)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
	  if( $record->value('qid') != "null") {
	    $this->queue_id = $record->value('qid');
            return TRUE; 
          }

        // Return 
        return FALSE;
    }

    // Create a new (:Queue) node and attach it to the (:Tail)
    private function createQueueNode()
    {
        $query = 'MATCH (t:Tail) 
CREATE (q:Queue)
MERGE (t)-[:NEXT]->(q)
SET q:Tail REMOVE t:Tail
RETURN id(q) AS qid LIMIT 1';

        $result = $this->neo4j_client->run($query, null);

        foreach ($result->records() as $record) {
	  $this->queue_id = $record->value('qid');
          return $record->value('qid');
	}
	
        // Return non-existing id if not found
        return -1;
    }

    // Attach new (Analysis) to a (:Queue) node
    private function createAnalysisNode()
    {
        $query = 'MATCH (q:Queue) WHERE id(q)={qid}
MATCH (g:Game) WHERE id(g)={gid}
MATCH (g)-[:FINISHED_ON]->(l:Line)
MATCH (w:WebUser{id:{wuid}})
MATCH (d:Depth{level:{depth}})
CREATE (a:Analysis'.$this->sideLabel.':Pending)
MERGE (q)-[:QUEUED]->(a)-[:REQUIRED_DEPTH]->(d)
MERGE (g)<-[:REQUESTED_FOR]-(a)-[:PERFORMED_ON]->(l)
MERGE (a)-[:REQUESTED_BY]->(w)
RETURN id(a) AS aid LIMIT 1';

        $params = ["qid" => intval( $this->queue_id), 
	  "gid" => intval( $this->game_id),
          "wuid" => intval( $this->wu_id), 
	  "depth" => intval( $this->depth)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record) {
	  $this->analysis_id = $record->value('aid');
          return $record->value('aid');
	}

        // Return non-existing id if not found
        return -1;
    }

    // Find (:Analysis) siblings
    private function findAnalysisSiblings()
    {
        $query = 'MATCH (q:Queue) WHERE id(q)={qid}
OPTIONAL MATCH (q)-[:LAST]-(l:Analysis)
OPTIONAL MATCH (l)-[:NEXT]->(f:Analysis)
OPTIONAL MATCH (q)<-[:NEXT]-(:Queue)-[:LAST]->(p:Analysis)
RETURN id(p) AS pid, id(l) AS lid, id(f) AS fid LIMIT 1';

        $params = ["qid" => intval( $this->queue_id)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record) {
	  $this->p_id = $record->value('pid');
	  $this->l_id = $record->value('lid');
	  $this->f_id = $record->value('fid');
	}

	return;
    }

    // Enqueue (:Analysis) Node
    private function enqueueAnalysisNode()
    {
	// Find the siblings first
	$this->findAnalysisSiblings();

        $this->logger->debug( "pid: $this->p_id, lid: $this->l_id, fid: $this->f_id");

	// Default query, all ids are null, very first (:Analysis) node
        $query = 'MATCH (q:Queue) WHERE id(q)={qid}
MATCH (a:Analysis) WHERE id(a)={aid}
MERGE (q)-[:FIRST]->(a)
MERGE (q)-[:LAST]->(a)
RETURN id(a) AS aid LIMIT 1';

	// Adding (:Analysis) node to a new (:Tail) node
	if( $this->p_id != null && 
	  $this->l_id == null && $this->f_id == null) {

	  $query = 'MATCH (q:Queue) WHERE id(q)={qid}
MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (p:Analysis) WHERE id(p)={pid}
MERGE (q)-[:FIRST]->(a)
MERGE (q)-[:LAST]->(a)
MERGE (p)-[:NEXT]->(a)
RETURN id(a) AS aid LIMIT 1';
	}

	// Adding to an existing (:Tail) node
	if( $this->l_id != null && $this->f_id == null) {

	  $query = 'MATCH (q:Queue) WHERE id(q)={qid}
MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (l:Analysis) WHERE id(l)={lid}
MATCH (q)-[r:LAST]->(l)
MERGE (l)-[:NEXT]->(a)
MERGE (q)-[:LAST]->(a)
DELETE r
RETURN id(a) AS aid LIMIT 1';
	}

	// Regular addition
	if( $this->l_id != null && $this->f_id != null) {

	  $query = 'MATCH (q:Queue) WHERE id(q)={qid}
MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (l:Analysis)-[r1:NEXT]->(f:Analysis) WHERE id(l)={lid}
MATCH (q)-[r2:LAST]->(l)
MERGE (l)-[:NEXT]->(a)-[:NEXT]->(f)
MERGE (q)-[:LAST]->(a)
DELETE r1, r2
RETURN id(a) AS aid LIMIT 1';
        }

        $params = ["qid" => intval( $this->queue_id), 
	  "aid" => intval( $this->analysis_id),
	  "pid" => intval( $this->p_id), 
	  "lid" => intval( $this->l_id)];
        $result = $this->neo4j_client->run($query, $params);
    }

    // Merge :Lines for the :Games
    private function mergeLines()
    {
      // Exit if array is empty
      if( count( $this->gids) == 0) return 0;

      // Fetch the games from the cache
      $PGNstring = $this->fetcher->getPGNs( $this->gids);

      $this->logger->debug( "Fetched games: ". $PGNstring);

      $filesystem = new Filesystem();
      try {

        // Filename SHOULD contain 'lines' prefix in order to make sure
        // the filename is never matches 'games' prefix, reserved for :Game-only db merge
	$tmp_file = $filesystem->tempnam('/tmp', 'lines-'.$this->getUser()->getId().'-');

        // Save the PGNs into a local temp file
        file_put_contents( $tmp_file, $PGNstring);

      } catch (IOExceptionInterface $exception) {

	$this->logger->debug( "An error occurred while creating a temp file ".$exception->getPath());

      }

      // Put the file into special uploads directory
      $PGNFileName = $this->uploader->uploadLines( $tmp_file);
    }

    /**
      * @Route("/queueGameAnalysis")
      * @Security("is_granted('ROLE_USER')")
      */
    public function queueGameAnalysis()
    {
      // or add an optional message - seen by developers
      $this->denyAccessUnlessGranted('ROLE_USER', null, 
	'User tried to access a page without having ROLE_USER');

      // HTTP request
      $request = Request::createFromGlobals();
	
      // Get analysis depth
      $requestDepth = $request->request->getInt('depth', 0);
      if( $requestDepth > 0) $this->depth = $requestDepth;

      // Get side from a query parameter
      $sideToAnalyze = $request->request->get('side', "");

      // Prepare analyze addition to a query
      if( $sideToAnalyze == "WhiteSide" || $sideToAnalyze == "BlackSide")
	$this->sideLabel = ":".$sideToAnalyze;

      // get Game IDs from the query
      $gids = json_decode( $request->request->get( 'gids'));
	
      // Iterate through all the IDs
      $counter = 0;
      foreach( $gids as $value) {

        $this->logger->debug( 'Processing game ID: '.$value);

	// Sanitize it somehow!!!
	$this->game_id = $value;

	$this->stopwatch->lap('queueGameAnalysis');

	// Check if game id is a valid game
	if( !$this->isValidGame()) {

            $this->logger->debug('The game is invalid.');
	    continue;
	}

	$this->stopwatch->lap('queueGameAnalysis');

	// Check if the :Game has already been queued
	if( $this->matchAnalysisRequest()) {

            $this->logger->debug('The game has already been queued for analysys.');
	    continue;
	}

	$this->stopwatch->lap('queueGameAnalysis');

	// Build the list of :Game ids to request :Line merge
	$this->gids[] = $this->game_id;

	$this->stopwatch->lap('queueGameAnalysis');

	// Get appropriate (:Queue) node to attach the new (:Analysis)
	if( !$this->findWebUserQueueNode()) $this->createQueueNode();

	$this->stopwatch->lap('queueGameAnalysis');

	// Create a new (:Analysis) node
	$this->createAnalysisNode();

	$this->stopwatch->lap('queueGameAnalysis');

	// Finally create necessary relationships
	$this->enqueueAnalysisNode();

	// Count successfull analysis additions
	$counter++;
      }

      $this->stopwatch->lap('queueGameAnalysis');

      // Request :Line merge
      $this->mergeLines();

      return new Response( $counter . " game(s) have been queued for analysis.");
    }
}

