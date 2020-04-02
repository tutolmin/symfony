<?php

// src/Service/QueueManager.php

namespace App\Service;

use Psr\Log\LoggerInterface;
use GraphAware\Neo4j\Client\ClientInterface;
use Symfony\Component\Security\Core\Security;

class QueueManager
{
    // Array of Analysis node statuses
    const STATUS = ['Pending','Processing','Partially','Skipped',
	'Evaluated','Exported','Complete'];

    // Default number of games to export
    const NUMBER = 20;

    // Analysis types
    private $FAST = 0;
    private $DEEP = 0;

    // Logger reference
    private $logger;

    // Neo4j client interface reference
    private $neo4j_client;

    // We need to check roles and get user id
    private $security;

    // Special flags to avoid redundant DB calls
    private $queueGraphExistsFlag;
    private $updateCurrentFlag;

    public function __construct( ClientInterface $client, 
	LoggerInterface $logger, Security $security)
    {
        $this->logger = $logger;
        $this->neo4j_client = $client;
	$this->security = $security;

	$this->queueGraphExistsFlag=false;
	$this->updateCurrentFlag=false;

	$this->FAST = $_ENV['FAST_ANALYSIS_DEPTH'];
	$this->DEEP = $_ENV['DEEP_ANALYSIS_DEPTH'];
    }

    // Getter/setter for the flags
    private function getUpdateCurrentQueueNodeFlag() {

	return $this->updateCurrentFlag;
    }

    private function setUpdateCurrentQueueNodeFlag( $value) {

	$this->updateCurrentFlag = $value;
    }

    private function getQueueGraphExistsFlag() {

	return $this->queueGraphExistsFlag;
    }

    private function setQueueGraphExistsFlag( $value) {

	$this->queueGraphExistsFlag = $value;
    }

    // Checks if there is an analysis queue present
    // With forced flag do not use cached flag
    public function queueGraphExists( $force_flag = false)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Checking for queue graph existance: '. 
	    (($this->getQueueGraphExistsFlag() && !$force_flag)?"skip":"proceed"));

	// Queue graph existance has been checked already
	if( $this->getQueueGraphExistsFlag() && !$force_flag) return true;

        // If there is at least one :Queue node in the db       
        $query = 'MATCH (h:Head) MATCH (t:Tail) MATCH (c:Current)
RETURN id(h) AS head, id(t) AS tail, id(c) AS current LIMIT 1';
        $result = $this->neo4j_client->run($query, null);

        // We expect a single record or null
        foreach ( $result->getRecords() as $record)
          if( $record->value('head') != null &&
	      $record->value('tail') != null &&
	      $record->value('current') != null) {
	    $this->setQueueGraphExistsFlag( true);
	    return true;
	  }
        return false;
    }

    // Init empty analysis queue
    public function initQueue()
    {
	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
          $this->logger->debug('Access denied');
	  return false;
	}

        $this->logger->debug('Initializing analysis queue graph');

	// Check if there is already analysis graph present
	if( !$this->queueGraphExists( true))

          // Create default empty analysis queue
          $this->neo4j_client->run( "CREATE (:Queue:Head:Current:Tail)", null);

	return true;
    }



    // Erase existing Analysis node
    public function eraseAnalysisNode( $aid) 
    {
	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
          $this->logger->debug('Access denied');
	  return false;
	}

        $this->logger->debug('Erasing analysis node'. $aid);

	if( !$this->analysisNodeExists( $aid)) {

          $this->logger->debug('Analysis node does NOT exist');
	  return false;
	}

        // Erase Analysis node and all relationships
        $this->neo4j_client->run( "MATCH (a:Analysis) WHERE id(a)={aid} 
OPTIONAL MATCH (a)-[]->(c:Action)
DETACH DELETE a,c", null);

	return true;
    }



    // Erase existing queue graph
    public function eraseQueue() 
    {
	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
          $this->logger->debug('Access denied');
	  return false;
	}

        $this->logger->debug('Erasing analysis queue graph');

	// Check if there is already analysis graph present
	if( $this->queueGraphExists())

          // Erase all nodes
          $this->neo4j_client->run( "MATCH (q:Queue) 
OPTIONAL MATCH (a:Analysis) DETACH DELETE q,a", null);
    }



    // return current queue node id, if found
    public function getCurrentQueueNode()
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug( "Checking for Current queue node existance");

	// Check if there is already analysis graph present
	if( $this->queueGraphExists()) {

          $result = $this->neo4j_client->run( '
MATCH (q:Queue:Current) RETURN id(q) AS qid LIMIT 1', null);

          foreach ($result->records() as $record)
            if( $record->value('qid') != null)
              return $record->value('qid');
	}
	
        // Return non-existant id 
        return -1;
    }

    // Update (:Current) pointer for a queue
    public function updateCurrentQueueNode( $force_flag = false)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Updating current queue node: '.
            (($this->getUpdateCurrentQueueNodeFlag() && !$force_flag)?"skip":"proceed"));

        // No need to update current node for eachgame analisys insert
        if( $this->getUpdateCurrentQueueNodeFlag() && !$force_flag) return true;

	// Check if there is already analysis graph present
	if( $this->queueGraphExists()) {

	  // Move :Current label forward
          $query = 'MATCH (t:Queue:Tail) 
OPTIONAL MATCH (c:Current)-[:FIRST]->(s:Analysis)
OPTIONAL MATCH (s)-[:NEXT*0..]->(e:Pending)
OPTIONAL MATCH (e)<-[:QUEUED]-(q:Queue)
WITH c, CASE q WHEN null THEN t ELSE q END AS q LIMIT 1
REMOVE c:Current SET q:Current';

	  // There is no Current Queue node, set it up
	  if( $qid = $this->getCurrentQueueNode() == -1 || $force_flag) {

            $this->logger->debug('No current queue node. Investigate!');

	    $query = 'MATCH (h:Queue:Head) MATCH (t:Queue:Tail) 
OPTIONAL MATCH (h)-[:FIRST]->(s:Analysis)
OPTIONAL MATCH (s)-[:NEXT*0..]->(e:Pending)
OPTIONAL MATCH (e)<-[:QUEUED]-(q:Queue)
WITH CASE q WHEN null THEN t ELSE q END AS q LIMIT 1
OPTIONAL MATCH (c:Current)
REMOVE c:Current SET q:Current';

	  }

	  // Send request to the DB
          $result = $this->neo4j_client->run( $query, null);

	  // We do not want to update Current node for subsequent calls
	  $this->setUpdateCurrentQueueNodeFlag( true);

	  return true;
	}
	
	return false;
    }

    // get first analysis node of certain type
    public function getFirstAnalysis( $label = "Pending")
    {
        $this->logger->debug('Getting first '.$label.' analysis node');

	// Check if there is already analysis graph present
	if( !$this->updateCurrentQueueNode())
	  return -1; // Negative to indicat ethe error

	// Look backward, by default
        $query = '
MATCH (:Current)-[:LAST]->(:Analysis)<-[:NEXT*0..]-(f:'.$label.') 
RETURN id(f) AS aid LIMIT 1';

	// Look forward
	if( $label == "Pending")
          $query = '
MATCH (:Current)-[:FIRST]->(:Analysis)-[:NEXT*0..]->(f:'.$label.') 
RETURN id(f) AS aid LIMIT 1';

        $result = $this->neo4j_client->run( $query, null);

        foreach ($result->records() as $record)
          if( $record->value('aid') != null)
            return $record->value('aid');

        // Return non-existing id if not found
        return -1;
    }
/*
    // get last analysis node of certain type
    public function getLastAnalysis( $label = "Processing")
    {
        $this->logger->debug('Getting last '.$label.' analysis node');

	// Check if there is already analysis graph present
	if( !$this->updateCurrentQueueNode())
	  return -1; // Negative to indicat ethe error

        $query = '
MATCH (:Current)-[:LAST]->(:Analysis)<-[:NEXT*0..]-(l:'.$label.') 
RETURN id(l) AS aid LIMIT 1';

        $result = $this->neo4j_client->run( $query, null);

        foreach ($result->records() as $record)
          if( $record->value('aid') != null)
            return $record->value('aid');

        // Return non-existing id if not found
        return -1;
    }
*/
    // return interval in seconds between two Analysis nodes
    // taking into account current evaluation speed
    public function getAnalysisInterval( $said, $faid)
    {
        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Calculating interval between '. $said.' and '.$faid);

        // Check if there is already analysis graph present
        if( !$this->updateCurrentQueueNode())
          return -1; // negative to indicate error

	// Get current evaluation speed
	$games_number = $_ENV['SPEED_EVAL_GAMES_LIMIT'];
	$fast = $this->getEvaluationSpeed( $this->FAST, $games_number);
	$deep = $this->getEvaluationSpeed( $this->DEEP, $games_number);

        $this->logger->debug('Current speeds are: '. $fast.' and '.$deep. ' ms per ply');

        $query = '
MATCH (s:Analysis) WHERE id(s)={said}
MATCH (f:Analysis) WHERE id(f)={faid}
MATCH path=(s)-[:NEXT*0..]->(f) WITH nodes(path) AS nodes LIMIT 1
UNWIND nodes AS node 
MATCH (node)-[:REQUIRED_DEPTH]->(d:Depth) 
MATCH (node)-[:PERFORMED_ON]->(l:Line)-[:GAME_HAS_LENGTH]->(p:GamePlyCount)
RETURN node, d.level AS depth, p.counter AS plies';

        $params = ["said" => intval( $said), "faid" => intval( $faid)];
        $result = $this->neo4j_client->run( $query, $params);

	$interval = 0;
	$records = $result->records();

	// Process all but last element
        foreach ( array_slice( $records, 0, count( $records) - 1) as $record){ 

          $labelsObj = $record->get('node');
          $labelsArray = $labelsObj->labels();

          $this->logger->debug( 'Node: '.implode (',', $labelsArray). ', depth: '.
		$record->value('depth'). ', plies: '.$record->value('plies'));

          // Analysis sides, do not divide if both labels present
	  $divider = 2;
          if( in_array( "WhiteSide", $labelsArray) 
	   && in_array( "BlackSide", $labelsArray))
	    $divider = 1;

	  // Select analysis type
          if( $record->value('depth') == $this->FAST)
	    $interval += $fast * $record->value('plies') / $divider; 
	  else
	    $interval += $deep * $record->value('plies') / $divider; 
	}

        $this->logger->debug( 'Interval: '. round($interval/1000));

        // Return negative to indicate error
        return round($interval/1000);
    }



    // return number of Pending queue items
    private function getUserQueueItems()
    {
        // returns User object or null if not authenticated
        $user = $this->security->getUser();

        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Checking number of Pending queue items for '.
		$user->getEmail());

        $query = 'MATCH (w:WebUser{id:{uid}}) 
OPTIONAL MATCH(w)<-[:REQUESTED_BY]->(a:Pending) 
RETURN count(a) AS items LIMIT 1';

        $params = ["uid" => intval( $user->getId())];
        $result = $this->neo4j_client->run( $query, $params);

	$items = 0;
        foreach ($result->records() as $record)
          if( $record->value('items') != null)
            $items = $record->value('items');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Pending queue items '.$items);

	return $items;
    }



    // check user limit, true if ok
    public function checkUserLimit()
    {
	if( !$this->security->isGranted('ROLE_USER')) {

          $this->logger->debug('Access denied');

	  return false;
	}
	
	// Queue manager is allowed to override the limit
        if ($this->security->isGranted('ROLE_QUEUE_MANAGER')) {

          $this->logger->debug(
		'User posesses a queue manager privileges.');

	  return true;
	}

        // returns User object or null if not authenticated
        $user = $this->security->getUser();

        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Checking submission limit for '.
		$user->getEmail());

        // get user limit from the DB
        $limit = $user->getQueueLimit();
	if( $limit == null)
	  $limit = $_ENV['USER_QUEUE_SUBMISSION_LIMIT'];

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('User queue submission limit '.$limit);

	// Fetch user items currently in the queue
	$items = $this->getUserQueueItems();

	return $items < $limit;
    }



    // get analysis queue width
    public function getQueueWidth( $type)
    {
        // Depth paramaeter
        $depth = $this->FAST;
        if( intval( $type) == $this->DEEP)
          $depth = $this->DEEP;

        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Fetching queue size for depth '.$depth);

        // Check if there is already analysis graph present
        if( !$this->updateCurrentQueueNode())
          return 0; // width = 0 items

        $query = '
MATCH (:Current)-[:QUEUED]->(a:Analysis)-[:REQUIRED_DEPTH]->(:Depth{level:{level}}) 
RETURN count(a) AS width';

        $params = ["level" => intval( $depth)];
        $result = $this->neo4j_client->run( $query, $params);

        $width = 0;
        foreach ($result->records() as $record)
          $width = $record->value('width');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Queue width ' .$width);

	return $width;
    }

    // Median/Average wait time for queue items
    public function getQueueWaitTime( $type = "median", $number)
    {
	$function = 'apoc.agg.median';
	if( $type == 'average')
	  $function = 'avg';

        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Calculating '.$type.' wait time for '.$number. ' games');

        // Check if there is already analysis graph present
        if( !$this->updateCurrentQueueNode())
          return -1; // negative wait time to indicate error

        $query = 'MATCH (:Current)-[:LAST]->(:Analysis)<-[:NEXT*0..]-(s:Analysis)-[:EVALUATED]->(:PlyCount) WITH s LIMIT 1 
MATCH (s)<-[:NEXT*0..]-(a:Analysis)-[:EVALUATED]->(p:PlyCount) WITH a,p LIMIT {number}
  MATCH (ys:Year)<-[:OF]-(ms:Month)<-[:OF]-(ds:Day)<-[:WAS_CREATED_DATE]-(a)
  MATCH (a)-[:EVALUATION_WAS_STARTED_DATE]->(df:Day)-[:OF]->(mf:Month)-[:OF]->(yf:Year)
  MATCH (hs:Hour)<-[:OF]-(ns:Minute)<-[:OF]-(ss:Second)<-[:WAS_CREATED_TIME]-(a)
  MATCH (a)-[:EVALUATION_WAS_STARTED_TIME]->(sf:Second)-[:OF]->(nf:Minute)-[:OF]->(hf:Hour)
WITH 
duration.inSeconds(
  datetime({ year: yf.year, month: mf.month, day: df.day, hour: hf.hour, minute: nf.minute, second: sf.second}),
  datetime({ year: ys.year, month: ms.month, day: ds.day, hour: hs.hour, minute: ns.minute, second: ss.second})
) AS duration
RETURN round('.$function.'( duration.seconds)) AS wait';

        $params = ["number" => intval( $number)];
        $result = $this->neo4j_client->run( $query, $params);

        $wait = 0;
        foreach ($result->records() as $record)
          if( $record->value('wait') != null)
            $wait = $record->value('wait');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Queue wait time: ' .$wait);

	return $wait;
    }

    // get analysis queue length
// Type deep/fast queue length
    public function getQueueLength()
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Calculating queue length');

	// Check if there is already analysis graph present
	if( !$this->updateCurrentQueueNode())
	  return -1; // Negative to indicat ethe error

/*
        $query = '
MATCH (:Queue:Current)-[:FIRST]->(:Analysis)-[:NEXT*0..]->(f:Pending) WITH f LIMIT 1 
MATCH (:Queue:Tail)-[:LAST]->(:Analysis)<-[:NEXT*0..]-(l:Pending) WITH f,l LIMIT 1 
MATCH path=(f)-[:NEXT*0..]-(l) RETURN size(nodes(path)) AS length LIMIT 1';
*/
	$query = 'MATCH (p:Pending) RETURN count(p) AS length LIMIT 1';

        $result = $this->neo4j_client->run( $query, null);

        $length = 0;
        foreach ($result->records() as $record)
          $length = $record->value('length');

	if( $_ENV['APP_DEBUG'])
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
          if( $record->value('qid') != null)
            return $record->value('qid');

        // Return non-existing id if not found
        return -1;
    }



    // check :Analysis node existance in the database
    public function analysisNodeExists( $aid)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug( "Checking for analysis node existance");

	// Check if analysis graph present
	if( !$this->queueGraphExists())
	  return false;

        $query = 'MATCH (a:Analysis) WHERE id(a) = {aid}
RETURN id(a) AS aid LIMIT 1';

        $params = ["aid" => intval( $aid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('aid') != null)
            return true;

        // Return 
        return false;
    }



    // Returns an array of evaluated analysis depths for both sides
    public function getGameAnalysisDepths( $gid) 
    {
	$depth = ['white' => 0, 'black' => 0];
	$status = array_search( "Evaluated", self::STATUS);

        // If Analysis status is more than necessary value
        if( $this->matchAnalysis( $gid, 
	$_ENV['DEEP_ANALYSIS_DEPTH'], ":WhiteSide") >= $status)

          $depth['white'] = $_ENV['DEEP_ANALYSIS_DEPTH'];

        else if( $this->matchAnalysis( $gid, 
	$_ENV['FAST_ANALYSIS_DEPTH'], ":WhiteSide") >= $status)

          $depth['white'] = $_ENV['FAST_ANALYSIS_DEPTH'];

        if( $this->matchAnalysis( $gid, 
	$_ENV['DEEP_ANALYSIS_DEPTH'], ":BlackSide") >= $status)

          $depth['black'] = $_ENV['DEEP_ANALYSIS_DEPTH'];

        else if( $this->matchAnalysis( $gid, 
	$_ENV['FAST_ANALYSIS_DEPTH'], ":BlackSide") >= $status)

          $depth['black'] = $_ENV['FAST_ANALYSIS_DEPTH'];

        $this->logger->debug( 'Analysis depth for White: ' .
                $depth['white'] . ' Black: '. $depth['black']);

	return $depth;
    }



    // Match existing Analysis request
    public function matchAnalysis( $gid, $depth, $sideLabel)
    {
        $this->logger->debug('Matching analysis node for depth: '. 
		$depth. ' side: '.$sideLabel);

        $query = 'MATCH (g:Game) WHERE id(g) = {gid}
MATCH (g)-[:FINISHED_ON]->(l:Line)
MATCH (d:Depth{level:{depth}})
MATCH (l)<-[:PERFORMED_ON]-(a:Analysis'.$sideLabel.')-[:REQUIRED_DEPTH]->(d)
RETURN a LIMIT 1';

        $params = ["gid" => intval( $gid), "depth" => intval( $depth)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->get('a')->identity() != null) {

	    // Get Analysis node labels
	    $labels = $record->get('a')->labels();
	    foreach( self::STATUS as $key => $status)
	      if( in_array( $status, $labels))
		return $key;
	  }

        // Return -1 by default
        return -1;
    }

    // Find appropriate (:Queue) node for a given (:WebUser)
    private function findWebUserNextQueueNode()
    {
        $this->logger->debug('Finding suitable queue node for a user');

        // returns User object or null if not authenticatedna

        $user = $this->security->getUser();

        // Maintenane operation
        $this->updateCurrentQueueNode();

        $query = 'MATCH (w:WebUser{id:{wuid}})
MATCH (:Current)-[:NEXT*0..]->(q:Queue) 
WHERE NOT (q)-[:QUEUED]->(:Analysis)-[:REQUESTED_BY]->(w) 
RETURN id(q) AS qid LIMIT 1';

        $params = ["wuid" => intval( $user->getId())];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('qid') != null)
            return $record->value('qid');

        // Return 
        return -1;
    }

    // Attach new (Analysis) to a (:Queue) node
    private function createAnalysisNode( $gid, $depth, $sideLabel)
    {
        $this->logger->debug('Creating new analysis node');

        // returns User object or null if not authenticated
        $user = $this->security->getUser();

	// Check if analysis graph present
	if( !$this->queueGraphExists())
	  return -1;

	// Queue node id to attach Analysis node for a user
	$qid = $this->findWebUserNextQueueNode();

        // Get appropriate (:Queue) node to attach the new (:Analysis)
        if( $qid == -1) $qid = $this->createQueueNode();

	// Could NOT match/create an appropriate queue node
        if( $qid == -1) return -1; 

	// Check analysis side labels (all valid combinations)
	if( $sideLabel != ":WhiteSide" && $sideLabel != ":BlackSide" &&
	  $sideLabel != ":WhiteSide:BlackSide" && $sideLabel != ":BlackSide:WhiteSide")
          $sideLabel = ":WhiteSide:BlackSide";

	// Date/Time items
	$day	= date( "j");
	$month	= date( "n");
	$year	= date( "Y");
	$second	= date( "s");
	$minute	= date( "i");
	$hour	= date( "G");

        $query = 'MATCH (q:Queue) WHERE id(q)={qid}
MATCH (g:Game) WHERE id(g)={gid}
MATCH (g)-[:FINISHED_ON]->(l:Line)
MATCH (w:WebUser{id:{wuid}})
MATCH (d:Depth{level:{depth}})
MATCH (date:Day {day: {day}})-[:OF]->(:Month {month: {month}})-[:OF]->(:Year {year: {year}})
MATCH (time:Second {second: {second}})-[:OF]->(:Minute {minute: {minute}})-[:OF]->(:Hour {hour: {hour}})
CREATE (a:Analysis'.$sideLabel.':Pending)
MERGE (q)-[:QUEUED]->(a)-[:REQUIRED_DEPTH]->(d)
MERGE (g)<-[:REQUESTED_FOR]-(a)-[:PERFORMED_ON]->(l)
MERGE (a)-[:REQUESTED_BY]->(w)
MERGE (a)-[:WAS_CREATED_DATE]->(date)
MERGE (a)-[:WAS_CREATED_TIME]->(time)
RETURN id(a) AS aid LIMIT 1';

        $params = ["qid" => intval( $qid), 
          "gid"		=> intval( $gid),
          "wuid"	=> intval( $user->getId()), 
          "depth"	=> intval( $depth),
          "day"		=> intval( $day),
          "month"	=> intval( $month),
          "year"	=> intval( $year),
          "second"	=> intval( $second),
          "minute"	=> intval( $minute),
          "hour"	=> intval( $hour),
	];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('aid') != null)
            return $record->value('aid');

        // Return non-existing id if not found
        return -1;
    }



    // Set Analysis status label
    public function setAnalysisStatus( $aid, $label)
    {
	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
          $this->logger->debug('Access denied');
	  return false;
	}

	if( !$this->analysisNodeExists( $aid)) {

          $this->logger->debug('Analysis node does NOT exist');
	  return false;
	}

	// Check if the status label is valid
	if( !in_array( $label, self::STATUS))
	  return false;

        $this->logger->debug('Setting analysis node status label '.$label);
	
	$statusLabels = implode( ':', self::STATUS);

	// Deleting existing status labels and adding new
	$query = 'MATCH (a:Analysis) WHERE id(a)={aid} 
REMOVE a:'.$statusLabels.' SET a:'.$label;

	// Send the query, we do NOT expect any return
        $params = ["aid" => intval( $aid)];
        $this->neo4j_client->run($query, $params);

        // Forcefully promote the analysis node
	if( $label == "Pending") $this->promoteAnalysis( $aid);

	return true;
    }



    // Set Analysis side (WhiteSide/BlackSide)
    public function setAnalysisSide( $aid, $side)
    {
	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
          $this->logger->debug('Access denied');
	  return false;
	}

	if( !$this->analysisNodeExists( $aid)) {

          $this->logger->debug('Analysis node does NOT exist');
	  return false;
	}

	// Side to analyze
	$sideLabel = ':WhiteSide:BlackSide';
          if( $side == 'WhiteSide' || $side == 'BlackSide')
            $sideLabel = ':'.$side;

        $this->logger->debug('Setting analysis node lables '.$sideLabel);
	
	// Deleting existing relation and adding new
	$query = 'MATCH (a:Analysis) WHERE id(a)={aid} 
REMOVE a:WhiteSide:BlackSide SET a'.$sideLabel;

	// Send the query, we do NOT expect any return
        $params = ["aid" => intval( $aid)];
        $this->neo4j_client->run($query, $params);

	return true;
    }



    // Set Analysis depth
    public function setAnalysisDepth( $aid, $value)
    {
	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
          $this->logger->debug('Access denied');
	  return false;
	}

	if( !$this->analysisNodeExists( $aid)) {

          $this->logger->debug('Analysis node does NOT exist');
	  return false;
	}

	// Depth paramaeter
	$depth = $this->FAST;
	if( intval( $value) == $this->DEEP) 
	  $depth = $this->DEEP;

        $this->logger->debug('Setting analysis node depth '.$depth);
	
	// Deleting existing relation and adding new
	$query = 'MATCH (a:Analysis) WHERE id(a)={aid} 
MATCH (a)-[r:REQUIRED_DEPTH]->(old:Depth) 
MATCH (new:Depth{level:{depth}})
CREATE (a)-[:REQUIRED_DEPTH]->(new) DELETE r';

	// Send the query, we do NOT expect any return
        $params = ["aid" => intval( $aid), "depth" => intval( $depth)];
        $this->neo4j_client->run($query, $params);

	return true;
    }



    // Return Analysis depth for a particular node
    public function getAnalysisDepth( $aid) {

	// Check if analysis graph present
	if( !$this->queueGraphExists())
	  return [];

        $this->logger->debug('Fetching Analysis depth');
	
	$query = '
MATCH (a:Analysis)-[:REQUIRED]->(d:Depth) WHERE id(a)={aid}
RETURN d.depth AS depth';

        $params = ["aid" => intval( $aid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('depth') != null)
            return $record->value('depth');

	return -1; // Negative to indicate error
    }


	
    // Return a Game Id for an Analysis
    public function getAnalysisGameId( $aid) {

	// Check if analysis graph present
	if( !$this->queueGraphExists())
	  return [];

        $this->logger->debug('Fetching Game Id');
	
	$query = '
MATCH (a:Analysis)-[:REQUESTED_FOR]->(g:Game) WHERE id(a)={aid}
RETURN id(g) AS gid';

        $params = ["aid" => intval( $aid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('gid') != null)
            return $record->value('gid');

	return -1; // Negative to indicate error
    }



    // Promote (:Analysis) node 
    public function promoteAnalysis( $aid)
    {
	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
          $this->logger->debug('Access denied');
	  return false;
	}

        $this->logger->debug('Promoting analysis node');
	
	// Make sure the analysis exists
	if( !$this->analysisNodeExists( $aid) {

          $this->logger->debug('Analysis node does NOT exist');
	  return false;
	}

	// Disconnect analysis node from it's current place
	$this->detachAnalysisNode( $aid);

	// Date/Time items
	$day	= date( "j");
	$month	= date( "n");
	$year	= date( "Y");
	$second	= date( "s");
	$minute	= date( "i");
	$hour	= date( "G");

	// Attach floating Analysis node to the :Current node
	$query = 'MATCH (a:Analysis) WHERE id(a)={aid} MATCH (c:Current) 
MATCH (date:Day {day: {day}})-[:OF]->(:Month {month: {month}})-[:OF]->(:Year {year: {year}})
MATCH (time:Second {second: {second}})-[:OF]->(:Minute {minute: {minute}})-[:OF]->(:Hour {hour: {hour}})
MERGE (a)-[:WAS_PROMOTED_DATE]->(date)
MERGE (a)-[:WAS_PROMOTED_TIME]->(time)
MERGE (c)-[:QUEUED]->(a)';

	// Send the query, we do NOT expect any return
        $params = ["aid" => intval( $aid),
          "day"		=> intval( $day),
          "month"	=> intval( $month),
          "year"	=> intval( $year),
          "second"	=> intval( $second),
          "minute"	=> intval( $minute),
          "hour"	=> intval( $hour),
	];
        $this->neo4j_client->run($query, $params);

	// Enqueue the newly attached node
	$this->enqueueAnalysis( $aid);	

        // Return 
        return true;
    }

    // Detach (:Analysis) node and interconnect affected :Analysys an :Queue nodes
    public function detachAnalysisNode( $aid)
    {
	if( !$this->analysisNodeExists( $aid)) {

          $this->logger->debug('Analysis node does NOT exist');
	  return false;
	}

        $this->logger->debug('Deleting analysis node');

	// Previous :Queue last :Analysis node 
	// Current :Queue previous :Analysis node
	// Current :Queue next Analysis node
	// Next :Queue first :analysis node
	// current = :Current:Queue node flag
	$pl_id=-1;
	$cp_id=-1;
	$cn_id=-1;
	$nf_id=-1;
	$current = false;

	$query = 'MATCH (a:Analysis)<-[:QUEUED]-(q:Queue) WHERE id(a)={aid} 
OPTIONAL MATCH (pl:Analysis)<-[:LAST]-(:Queue)-[:NEXT]->(q) 
OPTIONAL MATCH (q)-[:NEXT]->(:Queue)-[:FIRST]->(nf:Analysis) 
OPTIONAL MATCH (q)-[:QUEUED]->(cp:Analysis)-[:NEXT]->(a) 
OPTIONAL MATCH (a)-[:NEXT]->(cn:Analysis)<-[:QUEUED]-(q) 
RETURN id(pl) AS pl_id, id(nf) AS nf_id, id(cp) AS cp_id, id(cn) AS cn_id,
	"Current" IN labels(q) AS current';

        $params = ["aid" => intval( $aid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record) {
          $pl_id = $record->value('pl_id');
          $cp_id = $record->value('cp_id');
          $cn_id = $record->value('cn_id');
          $nf_id = $record->value('nf_id');
          $current = $record->value('current');
        }

        $this->logger->debug( "pl: $pl_id, cp: $cp_id, cn: $cn_id, nf: $nf_id");

	// Basic :analysis matching query
	$query = 'MATCH (a:Analysis)<-[:QUEUED]-(q:Queue) WHERE id(a)={aid} ';

	//
	// See Ticket https://trac.tutolmin.com/chess/ticket/107
	//

	// 1) The only :Analysis node left in the graph 
        if( $pl_id == null && $cp_id == null && $cn_id == null && $nf_id == null) {
	  $query .= 'RETURN id(a)';
          $this->logger->debug( "Delete Analysis type1");
	}

	// 2) The only :Analysis node for the :Head, next :Queue node(s) exist 
        if( $pl_id == null && $cp_id == null && $cn_id == null && $nf_id != null) {

	  // Assign Current label to the next Queue node
	  $query_current = "";
	  if( $current) $query_current = ":Current";

	  $query .= 'MATCH (q)-[:NEXT]->(n:Queue) 
SET n:Head'.$quey_current.' DETACH DELETE q';

          $this->logger->debug( "Delete Analysis type2 ". ($current?":Current":""));
	}

	// 3) First :Analysis node for single :Head node (:Tail) 
	// 4) First :Analysis node for :Head, next :Queue node(s) exist 
        if( ($pl_id == null && $cp_id == null && $cn_id != null && $nf_id == null) || 
            ($pl_id == null && $cp_id == null && $cn_id != null && $nf_id != null)) {
	  $query .= 'MATCH (a)-[:NEXT]->(cn:Analysis) MERGE (cn)<-[:FIRST]-(q)';
          $this->logger->debug( "Delete Analysis type34");
	}
	
	// 5) Last (but not the only) :Analysis node for single :Head (:Tail) 
	// 13) Last (but not the only) :Analysis node for the :Tail
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

	  // Assign Current label to the next Queue node
	  $query_current = "";
	  if( $current) $query_current = ":Current";

	  $query .= 'MATCH (p:Queue)-[:NEXT]->(q) 
SET p:Tail'.$query_current.' DETACH DELETE q'; 

          $this->logger->debug( "Delete Analysis type9 ".($current?":Current":""));
	}

	// 10) The only :Analysis node for the regular :Queue 
        if( $pl_id != null && $cp_id == null && $cn_id == null && $nf_id != null) {
	
	  // Assign Current label to the next Queue node
	  $query_current = "";
	  if( $current) $query_current = "SET n:Current";

	  $query .= 'MATCH (p:Queue)-[:NEXT]->(q)-[:NEXT]->(n:Queue) 
MATCH (pl:Analysis)-[:NEXT]->(a)-[:NEXT]->(nf:Analysis) 
MERGE (p)-[:NEXT]->(n) MERGE (pl)-[:NEXT]->(nf) '.$query_current.' DETACH DELETE q';

          $this->logger->debug( "Delete Analysis type10 ".($current?":Current":""));
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
	  $query = 'MATCH (a:Analysis) WHERE id(a)={aid} 
OPTIONAL MATCH (:Analysis)-[s:NEXT]-(a) 
OPTIONAL MATCH (:Queue)-[r]->(a) DELETE s,r';

	// Send the query, we do NOT expect any return
        $this->neo4j_client->run($query, $params);

        // Return 
        return true;
    }

    // Interconnect (:Analysis) node with siblings
    private function enqueueAnalysis( $aid)
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

        $query = 'MATCH (q:Queue)-[:QUEUED]->(a:Analysis) WHERE id(a)={aid}';

        // All ids are null, very first (:Analysis) node
        if( $p_id == null && $l_id == null && $f_id == null) {

          $query .= '
MERGE (q)-[:FIRST]->(a)
MERGE (q)-[:LAST]->(a)
RETURN id(a) AS aid LIMIT 1';
	}
	
        // Adding (:Analysis) node to a new (:Tail) node
        if( $p_id != null && $l_id == null && $f_id == null) {

          $query .= '
MATCH (p:Analysis) WHERE id(p)={pid}
MERGE (q)-[:FIRST]->(a)
MERGE (q)-[:LAST]->(a)
MERGE (p)-[:NEXT]->(a)
RETURN id(a) AS aid LIMIT 1';
        }

        // Adding to an existing (:Tail) node
        if( $l_id != null && $f_id == null) {

          $query .= '
MATCH (l:Analysis) WHERE id(l)={lid}
MATCH (q)-[r:LAST]->(l)
MERGE (l)-[:NEXT]->(a)
MERGE (q)-[:LAST]->(a)
DELETE r
RETURN id(a) AS aid LIMIT 1';
        }

        // Regular addition
        if( $l_id != null && $f_id != null) {

          $query .= '
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
          if( $record->value('aid') != null)
	    return true;
	
	return false;
    }

    // Queue Game Analysis function
    public function queueGameAnalysis( $gid, $depth, $sideLabel) 
    {
	if( !$this->security->isGranted('ROLE_USER')) {
          $this->logger->debug('Access denied');
	  return false;
	}

        $this->logger->debug('Staring game anaysis enqueueing process.');

	// Match analysis for both sides
	$sides = array_unique( array_slice( explode( ':', $sideLabel), 1));

	// Check if the side analysis is already present
	foreach( $sides as $key => $side) {

          // Check if the :Game has already been queued
	  $status = $this->matchAnalysis( $gid, $depth, ':'.$side);
          if( $status != -1) {

            $this->logger->debug(
		'The game has already been queued for analysys for '. 
		$side. ' status '.self::STATUS[$status]);

	    // Delete array element
	    unset( $sides[$key]);
          }
	}

	// If sides array is not empty build sides label
	if( count( $sides) > 0)
	  $sideLabel = ':'.implode( ':', $sides);
	else
	  return false;

	// Make sure user is allowd to add more items
	if( !$this->checkUserLimit()) {

            $this->logger->debug(
		'User has exceeded their submission limit.');

              return false;
	}

        // Create a new (:Analysis) node
        $aid = $this->createAnalysisNode( $gid, $depth, $sideLabel);

        // Finally create necessary relationships
	if( $aid != -1)
          return $this->enqueueAnalysis( $aid);

	return false;
    }

    // get the Analysis evaluation speed
    public function getEvaluationSpeed( $type, $number)
    {
	// Depth paramaeter
	$depth = $this->FAST;
	if( intval( $type) == $this->DEEP) 
	  $depth = $this->DEEP;

	// Limit number of games to get
	if( !is_numeric( $number) || $number < 0 || $number > 100)
	  return -1; // Negative to indicate error

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Getting analysis '.$depth.
		' evaluation speed for '.$number.' games');

        // Check if the value is in the cache already
	$speed=0;
        $cacheVarName = 'speed_analysis'.$depth.'_games'.$number;
        if( apcu_exists( $cacheVarName))
          $speed = apcu_fetch( $cacheVarName);

        // Return speed, stored in the cache
        if( $speed > 0) {
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Analysis '.
              $depth.' evaluation speed = '.$speed.' (cached)');
          return $speed;
        }

	// Check if there is already analysis graph present
	if( !$this->updateCurrentQueueNode())
	  return -1; // negative indicate error

        $query = '
MATCH (:Current)-[:LAST]->(:Analysis)<-[:NEXT*0..]-(s:Analysis)-[:EVALUATED]->(:PlyCount) WITH s LIMIT 1 
MATCH (s)<-[:NEXT*0..]-(a:Analysis)-[:EVALUATED]->(p:PlyCount) WHERE p.counter>0 
MATCH (a)-[:REQUIRED_DEPTH]-(d:Depth{level:{level}}) WITH a,p LIMIT {number}
  MATCH (ys:Year)<-[:OF]-(ms:Month)<-[:OF]-(ds:Day)<-[:EVALUATION_WAS_STARTED_DATE]-(a)
  MATCH (a)-[:EVALUATION_WAS_FINISHED_DATE]->(df:Day)-[:OF]->(mf:Month)-[:OF]->(yf:Year)
  MATCH (hs:Hour)<-[:OF]-(ns:Minute)<-[:OF]-(ss:Second)<-[:EVALUATION_WAS_STARTED_TIME]-(a)
  MATCH (a)-[:EVALUATION_WAS_FINISHED_TIME]->(sf:Second)-[:OF]->(nf:Minute)-[:OF]->(hf:Hour)
WITH 
avg(
duration.inSeconds(
  datetime({ year: ys.year, month: ms.month, day: ds.day, hour: hs.hour, minute: ns.minute, second: ss.second}),
  datetime({ year: yf.year, month: mf.month, day: df.day, hour: hf.hour, minute: nf.minute, second: sf.second})
) / p.counter
) AS average
RETURN average.milliseconds AS speed';

        $params = ["level" => intval( $depth), "number" => intval( $number)];
        $result = $this->neo4j_client->run( $query, $params);

        foreach ($result->records() as $record)
          if( $record->value('speed') != null)
            $speed = $record->value('speed');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug( $speed);

        // Storing the value in cache
        apcu_add( $cacheVarName, $speed, 3600);

	return $speed;
    }
}
?>
