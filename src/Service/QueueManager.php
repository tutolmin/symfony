<?php

// src/Service/QueueManager.php

namespace App\Service;

use Psr\Log\LoggerInterface;
use GraphAware\Neo4j\Client\ClientInterface;

class QueueManager
{
    // Logger reference
    private $logger;

    // Neo4j client interface reference
    private $neo4j_client;

    // Special flags to avoid redundant DB calss
    private $queueExistsFlag;
    private $updateCurrentFlag;

    public function __construct( ClientInterface $client, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->neo4j_client = $client;

	$this->queueExistsFlag=false;
	$this->updateCurrentFlag=false;
    }

    // Getter/setter for the flags
    private function getUpdateCurrentFlag() {

	return $this->updateCurrentFlag;
    }

    private function setUpdateCurrentFlag( $value) {

	$this->updateCurrentFlag = $value;
    }

    private function getQueueExistsFlag() {

	return $this->queueExistsFlag;
    }

    private function setQueueExistsFlag( $value) {

	$this->queueExistsFlag = $value;
    }

    // Checks if there is an analysis queue present
    private function queueGraphExists()
    {
        $this->logger->debug('Checking for queue graph existance: '. 
		($this->getQueueExistsFlag()?"skip":"proceed"));

	// Queue graph existance has been checked already
	if( $this->getQueueExistsFlag()) return true;

        // If there is at least one :Queue node in the db       
        $query = 'MATCH (q:Queue) RETURN id(q) AS id LIMIT 1';
        $result = $this->neo4j_client->run($query, null);

        // We expect a single record or null
        foreach ( $result->getRecords() as $record)
          if( $record) {
	    $this->setQueueExistsFlag( true);
	    return true;
	  }

        return false;
    }

    // Init empty analysis queue
    public function initQueue()
    {
        $this->logger->debug('Initializing analysis queue graph');

	// Check if there is already analysis graph present
	if( !$this->queueGraphExists())

          // Create default empty analysis queue
          $this->neo4j_client->run( "CREATE (:Queue:Head:Current:Tail)", null);
    }

    // Erase existing queue graph
    public function eraseQueue() {

        $this->logger->debug('Erasing analysis queue graph');

	// Check if there is already analysis graph present
	if( $this->queueGraphExists())

          // Erase all nodes
          $this->neo4j_client->run( 
	"MATCH (q:Queue) OPTIONAL MATCH (a:Analysis) DETACH DELETE q,a", null);
    }

    // Update (:Current) pointer for a queue
    private function updateCurrentQueueNode()
    {
        $this->logger->debug('Updating current queue node: '.
                ($this->getUpdateCurrentFlag()?"skip":"proceed"));

        // No need to update current node for eachgame analisys insert
        if( $this->getUpdateCurrentFlag()) return true;

	// Check if there is already analysis graph present
	if( $this->queueGraphExists())

	  // Move :Current label
          $this->neo4j_client->run('
MATCH (:Queue:Head)-[:FIRST]->(:Analysis)-[:NEXT*0..]->(p:Pending) 
WITH p LIMIT 1
MATCH (p)<-[:QUEUED]-(q:Queue)
MATCH (c:Queue:Current)
REMOVE c:Current SET q:Current', null);

	// We do not want to update Current node for subsequent calls
	$this->setUpdateCurrentFlag( true);
    }

    // get analysis queue length
    public function getQueueLength()
    {
        $this->logger->debug('Calculating queue length');

	// Check if there is already analysis graph present
	if( !$this->queueGraphExists())
	  return -1; // Negative to indicat ethe error

        // Maintenane operation
        $this->updateCurrentQueueNode();

        $query = '
MATCH (:Queue:Current)-[:FIRST]->(:Analysis)-[:NEXT*0..]->(f:Pending) WITH f LIMIT 1 
MATCH (:Queue:Tail)-[:LAST]->(:Analysis)<-[:NEXT*0..]-(l:Pending) WITH f,l LIMIT 1 
MATCH path=(f)-[:NEXT*0..]-(l) RETURN size(nodes(path)) AS length LIMIT 1';

        $result = $this->neo4j_client->run( $query, null);

        $length = 0;
        foreach ($result->records() as $record)
          $length = $record->value('length');

        $this->logger->debug('Queue length' .$length);

	return $length;
    }

    // Create a new (:Queue) node and attach it to the (:Tail)
    private function createQueueNode()
    {
        $this->logger->debug('Creating new queue node');

	// Check if analysis graph present
	if( !$this->queueGraphExists())
	  return -1;

        $query = 'MATCH (t:Tail) 
CREATE (q:Queue)
MERGE (t)-[:NEXT]->(q)
SET q:Tail REMOVE t:Tail
RETURN id(q) AS qid LIMIT 1';

        $result = $this->neo4j_client->run($query, null);

        foreach ($result->records() as $record)
          if( $record->value('qid') != "null")
            return $record->value('qid');

        // Return non-existing id if not found
        return -1;
    }

    // Match existing Analysis request
    public function analysisExists( $gid, $depth, $sideLabel)
    {
        $this->logger->debug('Matching analysis node');

        $query = 'MATCH (g:Game) WHERE id(g) = {gid}
MATCH (g)-[:FINISHED_ON]->(l:Line)
MATCH (d:Depth{level:{depth}})
MATCH (l)<-[:PERFORMED_ON]-(a:Analysis'.$sideLabel.')-[:REQUIRED_DEPTH]->(d)
RETURN id(a) AS aid LIMIT 1';

        $params = ["gid" => intval( $gid), "depth" => intval( $depth)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('aid') != "null")
            return true;

        // Return FALSE by default
        return false;
    }

    // Find appropriate (:Queue) node for a given (:WebUser)
    private function findWebUserNextQueueNode( $wuid)
    {
        $this->logger->debug('Finding suitable queue node for a user');

        // Maintenane operation
        $this->updateCurrentQueueNode();

        $query = 'MATCH (w:WebUser{id:{wuid}})
MATCH (:Current)-[:NEXT*0..]->(q:Queue) 
WHERE NOT (q)-[:QUEUED]->(:Analysis)-[:REQUESTED_BY]->(w) 
RETURN id(q) AS qid LIMIT 1';

        $params = ["wuid" => intval( $wuid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('qid') != "null")
            return $record->value('qid');

        // Return 
        return -1;
    }

    // Attach new (Analysis) to a (:Queue) node
    private function createAnalysisNode( $gid, $depth, $sideLabel, $wuid)
    {
        $this->logger->debug('Creating new analysis node');

	// Check if analysis graph present
	if( !$this->queueGraphExists())
	  return -1;

	// Queue node id to attach Analysis node for a user
	$qid = $this->findWebUserNextQueueNode( $wuid);

        // Get appropriate (:Queue) node to attach the new (:Analysis)
        if( $qid == -1) $qid = $this->createQueueNode();

	// Could NOT match/create an appropriate queue node
        if( $qid == -1) return -1; 

	// Check analysis side labels
	if( $sideLabel != ":WhiteSide" && $sideLabel != ":BlackSide" &&
	  $sideLabel != ":WhiteSide:BlackSide" && $sideLabel != ":BlackSide:WhiteSide")
        $sideLabel = ":WhiteSide:BlackSide";

        $query = 'MATCH (q:Queue) WHERE id(q)={qid}
MATCH (g:Game) WHERE id(g)={gid}
MATCH (g)-[:FINISHED_ON]->(l:Line)
MATCH (w:WebUser{id:{wuid}})
MATCH (d:Depth{level:{depth}})
CREATE (a:Analysis'.$sideLabel.':Pending)
MERGE (q)-[:QUEUED]->(a)-[:REQUIRED_DEPTH]->(d)
MERGE (g)<-[:REQUESTED_FOR]-(a)-[:PERFORMED_ON]->(l)
MERGE (a)-[:REQUESTED_BY]->(w)
RETURN id(a) AS aid LIMIT 1';

        $params = ["qid" => intval( $qid), 
          "gid" => intval( $gid),
          "wuid" => intval( $wuid), 
          "depth" => intval( $depth)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('aid') != "null")
            return $record->value('aid');

        // Return non-existing id if not found
        return -1;
    }

    // Interconnect (:Analysis) node with siblings
    private function enqueueAnalysisNode( $aid)
    {
        $this->logger->debug('Enqueuing analysis node');

	// Previous, last, first node ids
	$p_id=-1;
	$l_id=-1;
	$f_id=-1;

	// Find sibling :Analysis nodes
        $query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (q:Queue)-[:QUEUED]->(a)
OPTIONAL MATCH (q)-[:LAST]-(l:Analysis)
OPTIONAL MATCH (l)-[:NEXT]->(f:Analysis)
OPTIONAL MATCH (q)<-[:NEXT]-(:Queue)-[:LAST]->(p:Analysis)
RETURN id(p) AS pid, id(l) AS lid, id(f) AS fid LIMIT 1';

        $params = ["aid" => intval( $aid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record) {
          $p_id = $record->value('pid');
          $l_id = $record->value('lid');
          $f_id = $record->value('fid');
        }

        $this->logger->debug( "pid: $p_id, lid: $l_id, fid: $f_id");

        // Default query, all ids are null, very first (:Analysis) node
        $query = '
MATCH (q:Queue)-[:QUEUED]->(a:Analysis) WHERE id(a)={aid}
MERGE (q)-[:FIRST]->(a)
MERGE (q)-[:LAST]->(a)
RETURN id(a) AS aid LIMIT 1';

        // Adding (:Analysis) node to a new (:Tail) node
        if( $p_id != null && $l_id == null && $f_id == null) {

          $query = '
MATCH (q:Queue)-[:QUEUED]->(a:Analysis) WHERE id(a)={aid}
MATCH (p:Analysis) WHERE id(p)={pid}
MERGE (q)-[:FIRST]->(a)
MERGE (q)-[:LAST]->(a)
MERGE (p)-[:NEXT]->(a)
RETURN id(a) AS aid LIMIT 1';
        }

        // Adding to an existing (:Tail) node
        if( $l_id != null && $f_id == null) {

          $query = '
MATCH (q:Queue)-[:QUEUED]->(a:Analysis) WHERE id(a)={aid}
MATCH (l:Analysis) WHERE id(l)={lid}
MATCH (q)-[r:LAST]->(l)
MERGE (l)-[:NEXT]->(a)
MERGE (q)-[:LAST]->(a)
DELETE r
RETURN id(a) AS aid LIMIT 1';
        }

        // Regular addition
        if( $l_id != null && $f_id != null) {

          $query = '
MATCH (q:Queue)-[:QUEUED]->(a:Analysis) WHERE id(a)={aid}
MATCH (l:Analysis)-[r1:NEXT]->(f:Analysis) WHERE id(l)={lid}
MATCH (q)-[r2:LAST]->(l)
MERGE (l)-[:NEXT]->(a)-[:NEXT]->(f)
MERGE (q)-[:LAST]->(a)
DELETE r1, r2
RETURN id(a) AS aid LIMIT 1';
        }

	// Run the query
        $params = ["aid" => intval( $aid),
          "pid" => intval( $p_id),
          "lid" => intval( $l_id)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('aid') != "null")
	    return true;
	
	return false;
    }

    // Queue Game Analysis function
    public function queueGameAnalysis( $gid, $depth, $sideLabel, $userId) {

        $this->logger->debug('Staring game anaysis enqueueing process.');

        // Check if the :Game has already been queued
        if( $this->analysisExists( $gid, $depth, $sideLabel)) {

            $this->logger->debug(
		'The game has already been queued for analysys.');

            return false;
        }

        // Create a new (:Analysis) node
        $aid = $this->createAnalysisNode(
                $gid, $depth, $sideLabel, $userId);

        // Finally create necessary relationships
        $result = $this->enqueueAnalysisNode( $aid);

	return $result;
    }
}

?>
