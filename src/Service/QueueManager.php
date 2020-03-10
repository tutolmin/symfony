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
    public function queueGraphExists()
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
MATCH (h:Head)
OPTIONAL MATCH (h)-[:FIRST]->(:Analysis)-[:NEXT*0..]->(:Pending)<-[:QUEUED]-(q:Queue)
WITH CASE q WHEN null THEN h ELSE q END AS q LIMIT 1
OPTIONAL MATCH (c:Current)
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

        $this->logger->debug('Queue length ' .$length);

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

    // Find :Analysis node in the database
    public function analysisExists( $aid)
    {
        $this->logger->debug( "Checking for analysis node existance");

        $query = 'MATCH (a:Analysis) WHERE id(a) = {aid}
RETURN id(a) AS aid LIMIT 1';

        $params = ["aid" => intval( $aid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('aid') != "null")
            return true;

        // Return 
        return false;
    }

    // Match existing Analysis request
    public function matchAnalysis( $gid, $depth, $sideLabel)
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

    // Promote (:Analysis) node 
    public function promoteAnalysis( $aid)
    {
	if( !$this->analysisExists( $aid)) {

          $this->logger->debug('Analysis node does NOT exist');
	  return false;
	}

        $this->logger->debug('Promoting analysis node');
	
	// Disconnect analysis node from it's current place
	$this->deleteAnalysis( $aid, false);

	// Attach floating Analysis node to the :Current node
	$query = 'MATCH (a:Analysis) WHERE id(a)={aid} 
MATCH (c:Current) MERGE (c)-[:QUEUED]->(a)';

	// Send the query, we do NOT expect any return
        $params = ["aid" => intval( $aid)];
        $this->neo4j_client->run($query, $params);

	// Enqueue the newly attached node
	$this->enqueueAnalysisNode( $aid);	

        // Return 
        return true;
    }

    // Delete (:Analysis) node and interconnect affected :Analysys an :Queue nodes
    public function deleteAnalysis( $aid, $erase_flag = true)
    {
	if( !$this->analysisExists( $aid)) {

          $this->logger->debug('Analysis node does NOT exist');
	  return false;
	}

        $this->logger->debug('Deleting analysis node');

	// Previous :Queue last :Analysis node 
	// Current :Queue previous :Analysis node
	// Current :Queue next Analysis node
	// Next :Queue first :analysis node
	$pl_id=-1;
	$cp_id=-1;
	$cn_id=-1;
	$nf_id=-1;

	$query = 'MATCH (a:Analysis)<-[:QUEUED]-(q:Queue) WHERE id(a)={aid} 
OPTIONAL MATCH (pl:Analysis)<-[:LAST]-(:Queue)-[:NEXT]->(q) 
OPTIONAL MATCH (q)-[:NEXT]->(:Queue)-[:FIRST]->(nf:Analysis) 
OPTIONAL MATCH (q)-[:QUEUED]->(cp:Analysis)-[:NEXT]->(a) 
OPTIONAL MATCH (a)-[:NEXT]->(cn:Analysis)<-[:QUEUED]-(q) 
RETURN id(pl) AS pl_id, id(nf) AS nf_id, id(cp) AS cp_id, id(cn) AS cn_id';

        $params = ["aid" => intval( $aid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record) {
          $pl_id = $record->value('pl_id');
          $cp_id = $record->value('cp_id');
          $cn_id = $record->value('cn_id');
          $nf_id = $record->value('nf_id');
        }

        $this->logger->debug( "pl: $pl_id, cp: $cp_id, cn: $cn_id, nf: $nf_id");

	// Basic :analysis matching query
	$query = 'MATCH (a:Analysis)<-[:QUEUED]-(q:Queue) WHERE id(a)={aid} ';

	//
	// See Ticket https://trac.tutolmin.com/chess/ticket/107
	//

	// 1) The only :Analysis node left in the graph 
        if( $pl_id == null && $cp_id == null && $cn_id == null && $nf_id == null)
	  $query .= '';
        if( strlen( $query) > 60) $this->logger->debug( "Delete Analysis type1");

	// 2) The only :Analysis node for the :Head, next :Queue node(s) exist 
        if( $pl_id == null && $cp_id == null && $cn_id == null && $nf_id != null) {
	  $query .= 'MATCH (q)-[:NEXT]->(n:Queue) SET n:Head DETACH DELETE q';
          $this->logger->debug( "Delete Analysis type2");
	}

	// 3) First :Analysis node for single :Head node (:Tail) 
	// 4) First :Analysis node for :Head, next :Queue node(s) exist 
        if( ($pl_id == null && $cp_id == null && $cn_id != null && $nf_id == null) || 
            ($pl_id == null && $cp_id == null && $cn_id != null && $nf_id != null)) {
	  $query .= 'MATCH (a)-[:NEXT]->(cn:Analysis) MERGE (cn)<-[:FIRST]-(q)';
          $this->logger->debug( "Delete Analysis type34");
	}
	
	// 5) Last :Analysis node for single :Head (:Tail) 
	// 13) Last :Analysis node for the :Tail 
        if( ($pl_id == null && $cp_id != null && $cn_id == null && $nf_id == null) ||
            ($pl_id != null && $cp_id != null && $cn_id == null && $nf_id == null)) {
	  $query .= 'MATCH (cp:Analysis)-[:NEXT]->(a) MERGE (cp)<-[:LAST]-(q)';
          $this->logger->debug( "Delete Analysis type513");
	}

	// 6) Last :Analysis node for :Head, next :Queue node(s) exist 
	// 14) Last :Analysis node for the regular :Queue 
        if( ($pl_id == null && $cp_id != null && $cn_id == null && $nf_id != null) ||
            ($pl_id != null && $cp_id != null && $cn_id == null && $nf_id != null)) {
	  $query .= 'MATCH (cp:Analysis)-[:NEXT]->(a)-[:NEXT]->(nf:Analysis) 
MERGE (cp)<-[:LAST]-(q) MERGE (cp)-[:NEXT]->(nf)';
          $this->logger->debug( "Delete Analysis type614");
	}

	// 7) Regular :Analysis node for single :Head node (:Tail)
	// 8) Regular :Analysis node for :Head, next :Queue node(s) exist
	// 15) Regular :Analysis node for the :Tail
	// 16) Regular :Analysis node for regular :Queue 
        if( ($pl_id == null && $cp_id != null && $cn_id != null && $nf_id == null) ||
            ($pl_id == null && $cp_id != null && $cn_id != null && $nf_id != null) ||
            ($pl_id != null && $cp_id != null && $cn_id != null && $nf_id == null) ||
            ($pl_id != null && $cp_id != null && $cn_id != null && $nf_id != null)) {
	  $query .= 'MATCH (cp:Analysis)-[:NEXT]->(a)-[:NEXT]->(cn:Analysis) 
MERGE (cp)-[:NEXT]->(cn)';
          $this->logger->debug( "Delete Analysis type781516");
	}

	// 9) The only :Analysis node for the :Tail 
        if( $pl_id != null && $cp_id == null && $cn_id == null && $nf_id == null) {
	  $query .= 'MATCH (p:Queue)-[:NEXT]->(q) SET p:Tail DETACH DELETE q'; 
          $this->logger->debug( "Delete Analysis type9");
	}

	// 10) The only :Analysis node for the regular :Queue 
        if( $pl_id != null && $cp_id == null && $cn_id == null && $nf_id != null) {
	  $query .= 'MATCH (p:Queue)-[:NEXT]->(q)-[:NEXT]->(n:Queue) 
MATCH (pl:Analysis)-[:NEXT]->(a)-[:NEXT]->(nf:Analysis) 
MERGE (p)-[:NEXT]->(n) MERGE (pl)-[:NEXT]->(nf) DETACH DELETE q';
          $this->logger->debug( "Delete Analysis type10");
	}

	// 11) First :Analysis node for the :Tail
	// 12) First :Analysis node for the regular :Queue
        if( ($pl_id != null && $cp_id == null && $cn_id != null && $nf_id == null) ||
            ($pl_id != null && $cp_id == null && $cn_id != null && $nf_id != null)) {
	  $query .= 'MATCH (pl:Analysis)-[:NEXT]->(a)-[:NEXT]->(cn:Analysis) 
MERGE (cn)<-[:FIRST]-(q) MERGE (pl)-[:NEXT]->(cn)';
          $this->logger->debug( "Delete Analysis type1112");
	}

	// Send the query, we do NOT expect any return
        $this->neo4j_client->run($query, $params);

	// Erase :Analysis or delete relationships only
	if( $erase_flag)
	
	  // We are actually erasing :Analysis node
	  $query = 'MATCH (a:Analysis) WHERE id(a)={aid} DETACH DELETE a';

	else

	  // Delete relationships to siblings and :Queue
	  $query = 'MATCH (:Analysis)-[s]-(a:Analysis)<-[q]-(:Queue) 
WHERE id(a)={aid} DELETE s,q';

	// Send the query, we do NOT expect any return
        $this->neo4j_client->run($query, $params);

        // Maintenane operation
        $this->updateCurrentQueueNode();

        // Return 
        return true;
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
        if( $this->matchAnalysis( $gid, $depth, $sideLabel)) {

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
