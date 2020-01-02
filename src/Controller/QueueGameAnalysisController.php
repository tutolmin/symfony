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

class QueueGameAnalysisController extends AbstractController
{
    // Neo4j client interface reference
    private $neo4j_client;

    // StopWatch instance
    private $stopwatch;

    // Logger reference
    private $logger;

    // Next queue id
    private $next_queue_id = -1;

    // Dependency injection of the Neo4j ClientInterface
    public function __construct( ClientInterface $client, Stopwatch $watch, LoggerInterface $logger)
    {
        $this->neo4j_client = $client;
        $this->stopwatch = $watch;
	$this->logger = $logger;
    }

    // Find the queue element currently being processed
    private function getCurrentQueueId()
    {
	$query = 'MATCH (:Queue:Current)-[:NEXT*0..]->(q:Queue) WITH q
MATCH (q)<-[:PLACED_IN]-(:Analysis:Pending)
RETURN id(q) as qid LIMIT 1';
        $result = $this->neo4j_client->run($query, null);

        foreach ($result->records() as $record)
          return $record->value('qid');

	// Return non-existing id if not found
	return -1;
    }

    // Match existing Analysis request
    private function matchedAnalysisRequest( $gameID, $analysisDepth, $analyzeLabel)
    {
	$query = 'MATCH (g:Game) WHERE id(g) = {gid}
MATCH (d:Depth{level:{depth}})
MATCH (g)<-[:REQUESTED_FOR]-(a:Analysis:Pending'.$analyzeLabel.')-[:REQUIRED_DEPTH]->(d)
RETURN id(a) AS aid LIMIT 1';

	$params = ["gid" => intval( $gameID), "depth" => intval( $analysisDepth)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('aid') != "null")
	    return TRUE; 

	// Return FALSE by default
	return FALSE;
    }


    // Finding last Queue element for current WebUser
    private function getWebUserQueueId()
    {
	$query = 'MATCH (wu:WebUser{id:{wuid}})
MATCH (wu)<-[:REQUESTED_BY]-(:Analysis:Pending)-[:PLACED_IN]->(q:Queue)
RETURN id(q) AS qid ORDER BY q.idx DESC LIMIT 1';

	$params = ["wuid" => intval( $this->getUser()->getId())];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          return $record->value('qid');

	// Return non-existing id if not found
	return -1;
    }

    // Merging new :Queue element
    private function getLastQueueId()
    {
	$query = 'MATCH (q:Queue) WHERE NOT (q)-[:NEXT]->(:Queue)
RETURN id(q) AS qid LIMIT 1';

        $result = $this->neo4j_client->run($query, null);

        foreach ($result->records() as $record)
          return $record->value('qid');

	// Return non-existing id if not found
	return -1;
    }

    // Merging new :Queue element
    private function mergeNextQueueId()
    {
	$query = 'MATCH (q:Queue) WHERE id(q) = {qid}
MERGE (q)-[:NEXT]->(e:Queue) ON CREATE SET e.idx = q.idx+1
RETURN id(e) AS qid LIMIT 1';

	$params = ["qid" => intval( $this->next_queue_id)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          return $record->value('qid');

	// Return non-existing id if not found
	return -1;
    }

    /**
      * @Route("/queueGameAnalysis")
      * @Security("is_granted('ROLE_USER')")
      */
    public function queueGameAnalysis()
    {
        // or add an optional message - seen by developers
        $this->denyAccessUnlessGranted('ROLE_USER', null, 'User tried to access a page without having ROLE_USER');

	// starts event named 'eventName'
	$this->stopwatch->start('queueGameAnalysis');

	// HTTP request
	$request = Request::createFromGlobals();
	
	// get Game ID from the query
	$gameID = $request->request->get( 'gid', "");

	// Check for required paramteres
	if( strlen( $gameID) == 0)
	    return new Response( "Error! Game ID is required.");

	// Query params
	$params = [];

	// Get side from a query parameter
	$sideToAnalyze = $request->request->get('side', "");

	// Get analysis depth
	$analysisDepth = $request->request->get('depth');
	if( strlen( $analysisDepth) == 0) $analysisDepth = $_ENV['DEFAULT_ANALYSIS_DEPTH'];

	// Prepare analyze addition to a query
	$analyzeLabel=":BothSides";
	if( $sideToAnalyze == "WhiteOnly" || $sideToAnalyze == "BlackOnly")
	    $analyzeLabel = ":".$sideToAnalyze;

	// Find the queue element currently being processed
	$this->next_queue_id = $this->getCurrentQueueId();

        $this->logger->debug('Currently :Queue id='.$this->next_queue_id.' is being processed');
	
	// Some games are Pending
	if( $this->next_queue_id != -1) {

	  // Check if the :Game has been already queued
	  if( $this->matchedAnalysisRequest( $gameID, $analysisDepth, $analyzeLabel)) {

        	$this->logger->debug('The :Game has already been queued for analysys.');

		// Encode in JSON and output
        	return new Response( "Success! Already queued.");
	  }

	  // Check if a WebUser have some Pending games
	  $wu_queue_id = -1;
	  $wu_queue_id = $this->getWebUserQueueId();
	  if( $wu_queue_id != -1) {
            $this->logger->debug('WebUser last :Queue id='.$this->next_queue_id);
	    $this->next_queue_id = $wu_queue_id;
	
	    // Merge next :Queue element
	    $this->next_queue_id = $this->mergeNextQueueId();
	  }
	} else {

	  // Get the last :Queue element
	  $this->next_queue_id = $this->getLastQueueId();

	  // Something is wrong
	  if( $this->next_queue_id == -1 )
	    return new Response( "Error! Can not find last :Queue element.");
	}
/*	
	// Merge next :Queue element
	$this->next_queue_id = $this->mergeNextQueueId();

	// Something is wrong
	if( $this->next_queue_id == -1 )
	    return new Response( "Error! Can not merge :Queue element.");
*/	
	// Query parameters
	$params = ["gid" => intval( $gameID), "qid" => $this->next_queue_id, 
		"wuid" => intval( $this->getUser()->getId()),
		"depth" => intval( $analysisDepth)];

	// Merge analysis request into the DB
	$query = 'WITH datetime() AS dt
MATCH (g:Game) WHERE id(g) = {gid} 
MATCH (q:Queue) WHERE id(q) = {qid}
MATCH (wu:WebUser{id:{wuid}})
MATCH (d:Depth {level:{depth}})
MATCH (date:Day {day: dt.day})-[:OF]->(:Month {month: dt.month})-[:OF]->(:Year {year: dt.year})
MATCH (time:Second {second: dt.second})-[:OF]->(:Minute {minute: dt.minute})-[:OF]->(:Hour {hour: dt.hour})
CREATE (a:Analysis:Pending'.$analyzeLabel.')
MERGE (g)<-[:REQUESTED_FOR]-(a)-[:REQUIRED_DEPTH]->(d)
MERGE (q)<-[:PLACED_IN]-(a)-[:REQUESTED_BY]->(wu)
MERGE (date)<-[:QUEUED_ON]-(a)-[:QUEUED_ON]->(time)
RETURN id(a) AS aid LIMIT 1';

        $result = $this->neo4j_client->run($query, $params);

	$this->stopwatch->stop('queueGameAnalysis');

	// Encode in JSON and output
        return new Response( "Success!");
    }
}

