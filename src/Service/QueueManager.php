<?php

// src/Service/QueueManager.php

namespace App\Service;

use Psr\Log\LoggerInterface;
use GraphAware\Neo4j\Client\ClientInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Analysis;
use App\Entity\CompleteAnalysis;
use App\Entity\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use App\Message\QueueManagerCommand;
use App\Message\InputOutputOperation;
use Symfony\Component\Messenger\MessageBusInterface;

class QueueManager
{
/*
    // Array of Analysis node statuses
    const STATUS = ['Pending','Processing','Partially',
	'Skipped','Evaluated','Exported','Complete'];

    // Array of valit analysis node actions
    const ACTION = ['Creation','Promotion','StatusChange',
	'DepthChange','SideChange'];

    // Array of valid analysis node actions
    const SIDE = ['White' => 'WhiteSide', 'Black' => 'BlackSide'];

    // Default number of games to export
    const NUMBER = 20;
*/
    // Analysis types
    private $depth = ['fast' => 0, 'deep' => 0];

    private $queue_id = -1;
    private $analysis_id = -1;

    // Logger reference
    private $logger;

    // Neo4j client interface reference
    private $neo4j_client;

    // We need to check roles and get user id
    private $security;

    // Doctrine EntityManager
    private $em;

    // Mailer interface
    private $mailer;

    // Message bus
    private $bus;

    // User repo
    private $userRepository;

    // Special flags to avoid redundant DB calls
    private $queueGraphExistsFlag;
    private $analysisNodeExistsFlag;
    private $updateCurrentFlag;

    private $router;

    public function __construct( ClientInterface $client,
      EntityManagerInterface $em, MailerInterface $mailer,
	    LoggerInterface $logger, Security $security, RouterInterface $router,
      MessageBusInterface $bus)
    {
        $this->logger = $logger;
        $this->neo4j_client = $client;
      	$this->security = $security;

        $this->em = $em;

        $this->bus = $bus;

        $this->mailer = $mailer;

        // get the User repository
        $this->userRepository = $this->em->getRepository( User::class);

        $this->router = $router;

      	$this->queueGraphExistsFlag=false;
      	$this->analysisNodeExistsFlag=false;
      	$this->updateCurrentFlag=false;

      	$this->depth['fast'] = $_ENV['FAST_ANALYSIS_DEPTH'];
      	$this->depth['deep'] = $_ENV['DEEP_ANALYSIS_DEPTH'];
    }

    // Getter/setter for the flags
    private function getUpdateCurrentQueueNodeFlag() {

	return $this->updateCurrentFlag;
    }

    private function setUpdateCurrentQueueNodeFlag( $value) {

	$this->updateCurrentFlag = $value;
    }

    private function getAnalysisNodeExistsFlag() {

	return $this->analysisNodeExistsFlag;
    }

    private function setAnalysisNodeExistsFlag( $value) {

	$this->analysisNodeExistsFlag = $value;
    }

    private function getQueueGraphExistsFlag() {

	return $this->queueGraphExistsFlag;
    }

    private function setQueueGraphExistsFlag( $value) {

	$this->queueGraphExistsFlag = $value;
    }



    // Checks if there is an analysis queue present
    private function queueGraphExists()
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Checking for queue graph existance: '.
	    ($this->getQueueGraphExistsFlag()?"skip":"proceed"));

	// Queue graph existance has been checked already
	if( $this->getQueueGraphExistsFlag()) return true;

        // If there is at least one :Queue node in the db
        $query = 'MATCH (h:Head) MATCH (t:Tail) MATCH (c:Current)
MATCH (p:Status{status:"Pending"})
RETURN id(h) AS head, id(t) AS tail, id(c) AS current, id(p) AS pending LIMIT 1';
        $result = $this->neo4j_client->run($query, null);

        // We expect a single record or null
        foreach ( $result->getRecords() as $record)
          if( $record->value('head') != null &&
	      $record->value('tail') != null &&
	      $record->value('current') != null &&
	      $record->value('pending') != null) {
	    $this->setQueueGraphExistsFlag( true);
	    return true;
	  }

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Queue graph does NOT exist!');

        return false;
    }



    // check :Queue node existance in the database
    private function queueNodeExists( $qid)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug( "Checking for queue node ".$qid." existance.");

	// Check if queue node present
	if( !$this->queueGraphExists()) return false;

        $query = 'MATCH (q:Queue) WHERE id(q) = {qid}
RETURN id(q) AS qid LIMIT 1';

        $params = ["qid" => intval( $qid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('qid') != null)
            return true;

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Queue node does NOT exist');

        // Return
        return false;
    }



    // check :Analysis node existance in the database
    private function analysisNodeExists( $aid)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug( "Checking for analysis node ".$aid." existance: ".
		($this->getAnalysisNodeExistsFlag()?"skip":"proceed"));

	// Analysis node existance has been checked already
	if( $this->getAnalysisNodeExistsFlag()) return true;

	// Check if analysis graph present
	if( !$this->queueGraphExists()) return false;

        $query = 'MATCH (a:Analysis) WHERE id(a) = {aid}
RETURN id(a) AS aid LIMIT 1';

        $params = ["aid" => intval( $aid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('aid') != null) {
	    $this->setAnalysisNodeExistsFlag( true);
            return true;
	  }

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Analysis node does NOT exist');

        // Return
        return false;
    }



    // Init empty analysis queue graph
    public function initQueueGraph()
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Initializing analysis queue graph');

	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Access denied');
	  return false;
	}

	// Check if there is already analysis graph present
	if( $this->queueGraphExists()){

	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Queue graph already exists');

	  return false;
	}

        // Create default empty analysis queue
        $this->neo4j_client->run( "CREATE (:Queue:Head:Current:Tail)", null);

	return true;
    }



    // Erase existing Analysis node
    public function eraseAnalysisNode( $aid)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Erasing analysis node id: '. $aid);

	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Access denied');
	  return false;
	}

	// Disconnect analysis node from it's current place
	$this->detachAnalysisNode( $aid);

	// Disconnect analysis node from status queue
	$this->detachStatusRels( $aid);

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Erasing analysis action nodes');

        // Erase Analysis node and all relationships
        $params = ["aid" => intval( $aid)];
        $this->neo4j_client->run( "MATCH (a:Analysis) WHERE id(a)={aid}
OPTIONAL MATCH (a)<-[:WAS_TAKEN_ON]-(c:Action)
DETACH DELETE a,c", $params);

	return true;
    }



    // Erase existing queue graph
    public function eraseQueueGraph()
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Erasing analysis queue graph');

	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Access denied');
	  return false;
	}

	// Get random Analysis nodes one by one
	while( ($aid = $this->getRandomAnalysisNode()) != -1)
	{
	  // Error occured while deleting analysis node
	  if( !$this->eraseAnalysisNode( $aid)) return false;

	  // Reset analysis existance flag for each new node
	  $this->setAnalysisNodeExistsFlag( false);
	}
	return true;
    }


/*
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

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug( "Current queue node has NOT been found.");

        // Return non-existant id
        return -1;
    }
*/


    // Update (:Current) pointer for a queue
    public function updateCurrentQueueNode( $force_flag = false)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Updating current queue node: '.
            (($this->getUpdateCurrentQueueNodeFlag() && !$force_flag)?"skip":"proceed"));

        // No need to update current node for eachgame analisys insert
        if( $this->getUpdateCurrentQueueNodeFlag() && !$force_flag) return true;

	// Check if there is already analysis graph present
	if( !$this->queueGraphExists()) return false;
/*
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
*/

	// Attach Current label to tail by default
	$query = 'MATCH (t:Tail)
OPTIONAL MATCH (c:Current)
REMOVE c:Current SET t:Current';

	// Get first pending analysis id
	$first = $this->getStatusQueueNode();

	// There are Pending nodes present
	if( $first != -1)
	  $query = 'MATCH (p:Status{status:"Pending"})
MATCH (p)-[:FIRST]->(:Analysis)<-[:QUEUED]-(q:Queue)
OPTIONAL MATCH (c:Current)
REMOVE c:Current SET q:Current';

	// Send request to the DB
        $result = $this->neo4j_client->run( $query, null);

	// We do not want to update Current node for subsequent calls
	$this->setUpdateCurrentQueueNodeFlag( true);

	return true;
    }



    // Get Random Analysis node, status is optional
    public function getRandomAnalysisNode( $status = '')
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Getting random '.$status.' analysis node');

	// Check if there is already analysis graph present
	if( !$this->queueGraphExists()) return -1;

	// Indicate error if status is not in the list
	if( strlen( $status) && !in_array( $status, Analysis::STATUS))
	  return -1;

	$total = $this->countAnalysisNodes( $status);
	if( $total < 1) return -1;
//	else if( $total > 100) $total = 100; // Cap random
	$skip = rand(0, $total-1);
	$params = ['status' => $status, 'SKIP' => $skip];

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('There are '.$total.' Analysis nodes, skipping '. $skip);

	// What is the point to folow the NEXT rels!?
/*
        $query = 'MATCH (:Head)-[:FIRST]->(s:Analysis)
MATCH (s)-[:NEXT*0..]->(a:Analysis)
RETURN id(a) AS aid SKIP $SKIP LIMIT 1';
*/
        $query = 'MATCH (a:Analysis) RETURN id(a) AS aid SKIP $SKIP LIMIT 1';

	// Query for specific status
	if( strlen( $status))
/*
          $query = 'MATCH (:Status{status:{status}})-[:FIRST]->(s:Analysis)
MATCH (s)-[:NEXT_BY_STATUS*0..]->(a:Analysis)
RETURN id(a) AS aid SKIP $SKIP LIMIT 1';
*/
          $query = 'MATCH (:Status{status:{status}})<-[:HAS_GOT]-(a:Analysis)
RETURN id(a) AS aid SKIP $SKIP LIMIT 1';


	$result = $this->neo4j_client->run( $query, $params);

        foreach ($result->records() as $record)
          if( $record->value('aid') != null)
            return $record->value('aid');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Random analysis node was NOT found!');

        // Return non-existing id if not found
        return -1;
    }



    // Count Queue nodes
    public function countQueueNodes()
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Getting total number of :Queue nodes');

	// Check if there is already analysis graph present
	if( !$this->queueGraphExists()) return -1;

        $result = $this->neo4j_client->run( 'MATCH (q:Queue)
RETURN count(q) AS total LIMIT 1', null);

        foreach ($result->records() as $record)
          if( $record->value('total') !== null)
            return $record->value('total');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Error fetching total number of Queue nodes!');

        // Return negative to indicate error
        return -1;
    }



    // Count Analysis node, status is optional
    public function countAnalysisNodes( $status = '', $forced = false)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Getting total number of '.
		$status.' analysis nodes');

	// Fetch value from the cache
	$counter=0;
        $cacheVarName = $status.'AnalysisNodeCounter';
        if( apcu_exists( $cacheVarName))
          $counter = apcu_fetch( $cacheVarName);

        // Return counter, stored in the cache
        if( $counter > 0 && !$forced) {
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Total number of '.
              $status.' analysis nodes (cached): '.$counter);
          return $counter;
        }

	// Check if there is already analysis graph present
	if( !$this->queueGraphExists()) return -1;

	// Indicate error if status is not in the list
	if( strlen( $status) && !in_array( $status, Analysis::STATUS))
	  return -1;

	$query = 'MATCH (a:Analysis)
RETURN count(a) AS total LIMIT 1';

	// Query for specific status
	if( strlen( $status))
	  $query = 'MATCH (s:Status{status:$status})
OPTIONAL MATCH (s)-[:FIRST]->(f:Analysis)
OPTIONAL MATCH (s)-[:LAST]->(l:Analysis)
OPTIONAL MATCH path=shortestPath((f)-[:NEXT_BY_STATUS*0..]->(l))
 WITH size(nodes(path)) AS length
RETURN
 CASE length WHEN null THEN 0
 ELSE length END AS total LIMIT 1';

	$params = ['status' => $status];
        $result = $this->neo4j_client->run( $query, $params);

        foreach ($result->records() as $record)
          if( $record->value('total') !== null) {

            // Storing the value in cache
            apcu_add( $cacheVarName, $record->value('total'), 3600);

            return $record->value('total');
	}

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Error fetching total number of Analysis nodes!');

        // Return negative to indicate error
        return -1;
    }


/*
    // get analysis node of certain type
    public function getAnalysisStatusNode( $status = "Pending" First)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Getting first '.$status.' analysis node');

	// Indicate error if status is not in the list
	if( !in_array( $status, self::STATUS)) return -1;

	// Check if there is already analysis graph present
	if( !$this->updateCurrentQueueNode()) return -1;

	// Look backward, by default
        $query = 'MATCH (s:Status{status:{status}})
OPTIONAL MATCH (s)-[:FIRST]->(a:Analysis)
RETURN id(a) AS aid LIMIT 1';

	$params = ['status' => $status];
        $result = $this->neo4j_client->run( $query, $params);

        foreach ($result->records() as $record)
          if( $record->value('aid') != null)
            return $record->value('aid');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Analysis node was NOT found!');

        // Return non-existing id if not found
        return -1;
    }
*/


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


    // Returns interval in seconds between two Analysis nodes
    // taking into account current evaluation speed
    // Makes sence for Pending status only
    // Takes a lot DB hits for descending order
    public function getAnalysisInterval( $said, $faid)
    {
        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Calculating interval between '. $said.' and '.$faid);

        // Check if there is already analysis graph present
        if( !$this->updateCurrentQueueNode()) return -1;

	// Get current evaluation speed
	$games_number = $_ENV['SPEED_EVAL_GAMES_LIMIT'];
	$fast = $this->getEvaluationSpeed( $this->depth['fast'], $games_number);
	$deep = $this->getEvaluationSpeed( $this->depth['deep'], $games_number);

        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Current speeds are: '. $fast.
		' and '.$deep. ' ms per ply');

        $query = '
MATCH (s:Analysis) WHERE id(s)={said}
MATCH (f:Analysis) WHERE id(f)={faid}
MATCH path=shortestPath((s)-[:NEXT_BY_STATUS*0..]->(f))
 WITH nodes(path) AS nodes LIMIT 1
UNWIND nodes AS node
MATCH (node)-[:REQUIRED_DEPTH]->(d:Depth)
MATCH (node)-[:PERFORMED_ON]->(l:Line)-[:GAME_HAS_LENGTH]->(p:GamePlyCount)
RETURN node, d.level AS depth, p.counter AS plies';

        $params = ["said" => intval( $said), "faid" => intval( $faid)];
        $result = $this->neo4j_client->run( $query, $params);

	$interval = 0;
	$records = $result->records();

	// Process all but last element
        foreach ( array_slice( $records, 0, count( $records) - 1) as $record) {

          $labelsObj = $record->get('node');
          $labelsArray = $labelsObj->labels();

          if( $_ENV['APP_DEBUG'])
            $this->logger->debug( 'Node: '.implode (',', $labelsArray). ', depth: '.
		$record->value('depth'). ', plies: '.$record->value('plies'));

          // Analysis sides, do not divide if both labels present
	  $divider = 2;
          if( in_array( Analysis::SIDE['White'], $labelsArray)
	   && in_array( Analysis::SIDE['Black'], $labelsArray))
	    $divider = 1;

	  // Select analysis type
          if( $record->value('depth') == $this->depth['fast'])
	    $interval += $fast * $record->value('plies') / $divider;
	  else
	    $interval += $deep * $record->value('plies') / $divider;
	}

        if( $_ENV['APP_DEBUG'])
          $this->logger->debug( 'Interval: '. round($interval/1000));

        // Return seconds
        return round(  $interval/1000);
    }



    // return number of queue items for a user
    public function getUserQueueItems( $status = 'Pending')
    {
        // returns User object or null if not authenticated
        $user = $this->security->getUser();

        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Checking number of '.$status.
		' queue items for '. $user->getEmail());

	// Indicate error if status is not in the list
	if( !in_array( $status, Analysis::STATUS)) return -1;

        $query = 'MATCH (w:WebUser{id:{uid}})
MATCH (s:Status{status:{status}})
OPTIONAL MATCH (w)<-[:REQUESTED_BY]-(a:Analysis)-[:HAS_GOT]->(s)
RETURN count(a) AS items LIMIT 1';

        $params = ['uid' => intval( $user->getId()), 'status' => $status];
        $result = $this->neo4j_client->run( $query, $params);

	$items = 0;
        foreach ($result->records() as $record)
          if( $record->value('items') != null)
            $items = $record->value('items');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug( $status.' queue items: '.$items);

	return $items;
    }



    // check user limit, true if ok
    public function checkUserLimit()
    {
        // returns User object or null if not authenticated
        $user = $this->security->getUser();

        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Checking submission limit for '.  $user->getEmail());

	if( !$this->security->isGranted('ROLE_USER')) {

	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Access denied');

	  return false;
	}

	// Queue manager is allowed to override the limit
        if ($this->security->isGranted('ROLE_QUEUE_MANAGER')) {

          $this->logger->debug(
		'User posesses a queue manager privileges.');

	  return true;
	}

        // get user limit from the DB
        $limit = $user->getQueueLimit();
	if( $limit == null)
	  $limit = $_ENV['USER_QUEUE_SUBMISSION_LIMIT'];

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('User queue submission limit '.$limit);

	// Fetch user items currently in the queue, Pending (by default)
	$items = $this->getUserQueueItems();

	return $items < $limit;
    }



    // get queue node items (QUEUED|FIRST|LAST)
    public function getQueueNodeItems( $type = 'QUEUED')
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Getting '.$type.' queue node item(s)');

	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Access denied');
	  return false;
	}

	// Valid Queue node rel types
	$rels = ['QUEUED', 'FIRST', 'LAST'];
	if( !in_array( $type, $rels)) {

	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Invalid queue node rel type '. $type);

	  return -1;
	}

        $query = 'MATCH (q:Queue) WHERE id(q)={qid}
MATCH (q)-[:'.$type.']->(a:Analysis)
RETURN count(a) AS total';

        $params = ["qid" => intval( $this->queue_id)];
        $result = $this->neo4j_client->run( $query, $params);

        $total = 0;
        foreach ($result->records() as $record)
          $total = $record->value('total');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Queue items: ' .$total);

	return $total;
    }



    // get analysis queue width (fast|deep)
    public function getQueueWidth( $type = 'fast')
    {
        // Depth paramaeter
        $depth = $this->depth['fast'];
        if( array_key_exists( $type, $this->depth))
          $depth = $this->depth[$type];

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
/*
*
*	Should take timestamp from ChangeStatus Processing :Action
*	but there can be many: one for WhiteSide, another for BlackSide
*	There can also be Skipped or Partially events
*	So latest interval between Pending - Processing should be taken into account
*	Why latest, take all intervals!
*
*/
    public function getQueueWaitTime( $type = "median", $number)
    {
	$function = 'apoc.agg.median';
	if( $type == 'average')
	  $function = 'avg';

        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Calculating '.$type.' wait time for '.$number. ' games');

        // Check if there is already analysis graph present
        if( !$this->updateCurrentQueueNode()) return -1;

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


/*
    // Get analysis queue length for certain status
    public function getQueueLength( $status = 'Pending')
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Calculating queue length');

	// Check if there is already analysis graph present
	if( !$this->updateCurrentQueueNode()) return -1;

        // Indicate error if status is not in the list
        if( !in_array( $status, self::STATUS)) return -1;

	$query = 'MATCH (s:Status{status:{status}})
OPTIONAL MATCH (s)-[:FIRST]->(f:Analysis)
OPTIONAL MATCH (s)-[:LAST]->(l:Analysis)
OPTIONAL MATCH path=(f)-[:NEXT_BY_STATUS*0..]->(l)
RETURN size(nodes(path)) AS length LIMIT 1';

	$params = ['status' => $status];
        $result = $this->neo4j_client->run( $query, $params);

        $length = -1;
        foreach ($result->records() as $record)
          if( $record->value('length') != null)
            $length = $record->value('length');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Queue length ' .$length);

	return $length;
    }
*/


    // Create a new (:Queue) node and attach it to the (:Tail)
    private function createQueueNode()
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Creating new queue node');

	// Check if analysis graph present
	if( !$this->queueGraphExists()) return -1;

        $query = 'MATCH (t:Tail)
CREATE (q:Queue)
MERGE (t)-[:NEXT]->(q)
SET q:Tail REMOVE t:Tail
RETURN id(q) AS qid LIMIT 1';

        $result = $this->neo4j_client->run($query, null);

        foreach ($result->records() as $record)
          if( $record->value('qid') != null)
            return $record->value('qid');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Queue node has NOT been created!');

        // Return non-existing id if not found
        return -1;
    }


/*
    // check if analysis is linked to a queue tail
    private function analysisOnQueueTail( $aid)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug( "Checking if analysis is on queue tail");

	// Check if analysis graph present
	if( !$this->analysisNodeExists( $aid)) return false;

        $query = 'MATCH (a:Analysis) WHERE id(a) = {aid}
MATCH (a)<-[:QUEUED]-(q:Queue:Tail) RETURN id(q) AS qid LIMIT 1';

        $params = ["aid" => intval( $aid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('qid') != null)
            return true;

        // Return
        return false;
    }
*/


    // Returns an array of evaluated analysis depths for both sides
    public function getGameAnalysisDepths( $gid)
    {
	$depths = ['White' => 0, 'Black' => 0];
	$processing = array_search( "Processing", Analysis::STATUS);
	$complete   = array_search( "Complete", Analysis::STATUS);
	$fast = $this->depth['fast'];
	$deep = $this->depth['deep'];

        // Repeat for both sides
	foreach( $depths as $side => $value) {

	// Need to reset whenever new anaysis is examined
	$this->setAnalysisNodeExistsFlag( false);

        // If Analysis status is more than necessary value
        $aid = $this->matchGameAnalysis( $gid, $deep, ':'.Analysis::SIDE[$side]);

	// Analysis id exists and status is ok
	$status = $this->getAnalysisStatus( $aid);
	$property = $this->getAnalysisStatusProperty( $aid);
	if( $aid != -1 && (($property != $processing) &&
		($status == $processing || $status == $complete)))
          $depths[$side] = $deep;

	// Continue with fast depth check
        if( $depths[$side] == 0) {

	  // Need to reset whenever new anaysis is examined
	  $this->setAnalysisNodeExistsFlag( false);

	  $aid = $this->matchGameAnalysis( $gid, $fast, ':'.Analysis::SIDE[$side]);

	  $status = $this->getAnalysisStatus( $aid);
	  $property = $this->getAnalysisStatusProperty( $aid);
	  if( $aid != -1 && (($property != $processing) &&
                ($status == $processing || $status == $complete)))
            $depths[$side] = $fast;
	}
	}

	return $depths;
    }



    // Match existing Analysis request, returns aid
    public function matchGameAnalysis( $gid, $depth, $sideLabel)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Matching analysis node for depth: '.
		$depth. ' side: '.$sideLabel);

	// Check if analysis graph present
	if( !$this->queueGraphExists()) return -1;

        $query = 'MATCH (g:Game) WHERE id(g) = {gid}
MATCH (g)-[:FINISHED_ON]->(l:Line)
MATCH (d:Depth{level:{depth}})
MATCH (l)<-[:PERFORMED_ON]-(a:Analysis'.$sideLabel.')-[:REQUIRED_DEPTH]->(d)
RETURN id(a) AS aid LIMIT 1';

        $params = ["gid" => intval( $gid), "depth" => intval( $depth)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('aid') != null)
            return $record->value('aid');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Analysis node has NOT been found!');

        // Return -1 by default
        return -1;
    }



    // Get appropriate (:Queue) node for a given (:WebUser)
    private function getUserQueueNode()
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Finding suitable queue node for a user');

        // returns User object or null if not authenticatedna

        $user = $this->security->getUser();

        // Maintenance operation
        $this->updateCurrentQueueNode();

	// Potentially slow
	// As it has to examine all :Queue nodes past Current
        $query = 'MATCH (w:WebUser{id:{wuid}})
MATCH path=(:Current)-[:NEXT*0..]->(q:Queue)
WHERE NOT (q)-[:QUEUED]->(:Analysis)-[:REQUESTED_BY]->(w)
WITH q, size(nodes(path)) AS length ORDER BY length LIMIT 1
RETURN id(q) AS qid';

        $params = ["wuid" => intval( $user->getId())];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('qid') != null)
            return $record->value('qid');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Suitable queue node has NOT been found!');

        // Return
        return -1;
    }



    // Get last action for the Analysis node
    private function getLastAnalysisActionNode( $tid)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Fetching last analysis action node');

	$query = 'MATCH (t:Action) WHERE id(t)={tid}
MATCH (t)-[:WAS_TAKEN_ON]->(a:Analysis)
OPTIONAL MATCH (a)<-[:WAS_TAKEN_ON]->(f:Action{action:"Creation"})
OPTIONAL MATCH path=(f)-[:NEXT*0..]->(l:Action)
WITH l, size(nodes(path)) AS total ORDER BY total DESC LIMIT 1
RETURN id(l) AS tid';

        $params = ["tid" => intval( $tid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('tid') != null)
            return $record->value('tid');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Last analysis action node has NOT been found!');

	return -1; // Indicate error
    }



    // Attach analysis action node
    private function attachAnalysisActionNode( $tid)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Attaching analysis action node');

	// Get last analysis action node
	$lid = $this->getLastAnalysisActionNode( $tid);

	// Error fetching last action id
	if( $lid == -1) return false;

	// Attach to the end of the list
	$query = 'MATCH (l:Action) WHERE id(l)={lid}
MATCH (t:Action) WHERE id(t)={tid}
MERGE (l)-[:NEXT]->(t)
RETURN id(t) AS tid';

        $params = ["tid" => intval( $tid), "lid" => intval( $lid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('tid') != null)
            return true;

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Error attaching action node!');

	return false;
    }


    // Create new (:Action) node and attach to a (:Analysis) node
    private function createAnalysisActionNode( $aid, $action = 'Creation', $parameter = '')
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Creating new analysis action('.
		$action.', param:'.$parameter.') node');

	// Check if analysis node exists
	if( !$this->analysisNodeExists( $aid)) return -1;

	// Indicate error if action is not a valid one
	if( !in_array( $action, Analysis::ACTION)) return -1;

        // Date/Time items
        $day    = date( "j");
        $month  = date( "n");
        $year   = date( "Y");
        $second = date( "s");
        $minute = date( "i");
        $hour   = date( "G");

        $params = ["aid" => intval( $aid),
          "action"      => $action,
          "day"         => intval( $day),
          "month"       => intval( $month),
          "year"        => intval( $year),
          "second"      => intval( $second),
          "minute"      => intval( $minute),
          "hour"        => intval( $hour)
        ];

	// Special relation to the status node
	$match_param = '';
	$merge_param = '';
	$parameter_str = '';

	if( $action == 'SideChange') {
	  $parameter_str = ',parameter:{parameter}';
	  $params["parameter"] = $parameter;
	}
	if( $action == 'DepthChange') {
	  $match_param = 'MATCH (d:Depth{level:{parameter}})';
	  $merge_param = 'MERGE (t)-[:CHANGED_TO]->(d)';
	  $params["parameter"] = intval( $parameter);
	}
	if( $action == 'StatusChange') {
	  $match_param = 'MATCH (s:Status{status:{parameter}})';
	  $merge_param = 'MERGE (t)-[:CHANGED_TO]->(s)';
	  $params["parameter"] = $parameter;
	}

	$query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (date:Day {day: {day}})-[:OF]->(:Month {month: {month}})-[:OF]->(:Year {year: {year}})
MATCH (time:Second {second: {second}})-[:OF]->(:Minute {minute: {minute}})-[:OF]->(:Hour {hour: {hour}})
'.$match_param.'
CREATE (t:Action{action:{action}'.$parameter_str.'})
MERGE (t)-[:WAS_TAKEN_ON]->(a)
MERGE (t)-[:WAS_PERFORMED_DATE]->(date)
MERGE (t)-[:WAS_PERFORMED_TIME]->(time)
'.$merge_param.'
RETURN id(t) AS tid LIMIT 1';

        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( ($tid = $record->value('tid')) != null) {

	    // Attach newly created action node, if NOT Creation
	    if( $action != 'Creation')

	      if( !$this->attachAnalysisActionNode( $tid))
		return -1;

            return $record->value('tid');
	  }

        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Error creating analysis action node!');

	return -1; // Indicate error
    }



    // Create new (Analysis) and attach to a (:Queue) node
    private function createAnalysisNode( $gid, $depth, $sideLabel)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Creating new analysis node');

        // returns User object or null if not authenticated
        $user = $this->security->getUser();

	// Check if analysis graph present
	if( !$this->queueGraphExists()) return -1;

	// Queue node id to attach Analysis node for a user
	$qid = $this->getUserQueueNode();

        // Get appropriate (:Queue) node to attach the new (:Analysis)
        if( $qid == -1) $qid = $this->createQueueNode();

	// Could NOT match/create an appropriate queue node
        if( $qid == -1) return -1;

	// Check analysis side labels (all valid combinations)
	if( $sideLabel != ':'.Analysis::SIDE['White']
		&& $sideLabel != ':'.Analysis::SIDE['Black'])
          $sideLabel = ':'.Analysis::SIDE['White'].':'.Analysis::SIDE['Black'];

        $query = 'MATCH (q:Queue) WHERE id(q)={qid}
MATCH (g:Game) WHERE id(g)={gid}
MATCH (g)-[:FINISHED_ON]->(l:Line)
MATCH (w:WebUser{id:{wuid}})
MATCH (d:Depth{level:{depth}})
CREATE (a:Analysis'.$sideLabel.')
MERGE (q)-[:QUEUED]->(a)-[:REQUIRED_DEPTH]->(d)
MERGE (g)<-[:REQUESTED_FOR]-(a)-[:PERFORMED_ON]->(l)
MERGE (a)-[:REQUESTED_BY]->(w)
RETURN id(a) AS aid LIMIT 1';

        $params = ["qid" => intval( $qid),
          "gid"		=> intval( $gid),
          "wuid"	=> intval( $user->getId()),
          "depth"	=> intval( $depth)
	];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( ($aid = $record->value('aid')) != null) {

	    // Record creation
	    if( !$this->createAnalysisActionNode( $aid)) return -1;

            return $aid;
	  }

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Analysis node has NOT been created!');

        // Return non-existing id if not found
        return -1;
    }



    // Detach status relationships
    private function detachStatusRels( $aid) {

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Detaching status relationships');

	// Return false if node does not exist
	if( !$this->analysisNodeExists( $aid)) return false;

	// Get current analysis status
	$current_status = $this->getAnalysisStatus( $aid);

	// Node does not have status rels
	if( $current_status == -1) return true;

	// get first and last nodes for a given status
	$first = $this->getStatusQueueNode( Analysis::STATUS[$current_status]);
	$last = $this->getStatusQueueNode( Analysis::STATUS[$current_status], 'last');

	// Inconsistent status queue
	if( ($first == -1 && $last != -1) ||
		($first != -1 && $last == -1)) {

	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('!!! Critical Error !!! Inconsistent status queue');
	  return false;
        }

	// Detach regular node from status queue, link siblings
	$query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (a)-[r:HAS_GOT]->(:Status)
MATCH (p:Analysis)-[pr:NEXT_BY_STATUS]->(a)-[nr:NEXT_BY_STATUS]->(n:Analysis)
MERGE (p)-[:NEXT_BY_STATUS]->(n)
DELETE r, pr, nr';

	// Detaching head of the status queue
	if( $first == $aid)
	  $query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (a)-[r]-(s:Status)
MATCH (a)-[nr:NEXT_BY_STATUS]->(n:Analysis)
MERGE (s)-[:FIRST]->(n)
DELETE r, nr';

	// Detaching tail of the status queue
	if( $last == $aid)
	  $query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (a)-[r]-(s:Status)
MATCH (p:Analysis)-[pr:NEXT_BY_STATUS]->(a)
MERGE (s)-[:LAST]->(p)
DELETE r, pr';

	// Detaching last node from the status queue
	if( $last == $aid && $first == $last)
	  $query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (a)-[r]-(:Status)
DELETE r';

        $params = ["aid" => intval( $aid)];
        $this->neo4j_client->run($query, $params);

	return true;
    }


/*
    // Link Analysis node with status siblings, type: tail|promotion
    private function linkStatusRelationships( $aid, $type = 'tail') {

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Linking status relationships');

	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Access denied');
	  return false;
	}

	// Return false if node does not exist
	if( !$this->analysisNodeExists( $aid)) return false;

	// rearrange rels with siblings
	$query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (p)-[:NEXT]->(a)-[:NEXT]->(n)
MATCH (p)-[r:NEXT_BY_STATUS]->(n)
MERGE (p)-[:NEXT_BY_STATUS]->(a)-[:NEXT_BY_STATUS]->(n)
DELETE r';

	// Attach to the tail
	if( $type != 'promotion') {

	  $query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (a)-[:HAS_GOT]->(s:Status)
OPTIONAL MATCH (s)-[r:TAIL]->(t)
MERGE (a)-[:TAIL]->(s)
MERGE (t)-[:NEXT_BY_STATUS]->(a)
DELETE r';

	  // Get current analysis status
	  $current_status = $this->getAnalysisStatus( $aid);

	}

    }
*/

    // Sync analysis status for first available node
    public function syncAnalysisStatus( $status = 'Pending')
    {
	    if( $_ENV['APP_DEBUG'])
        $this->logger->debug('Syncing analysis status '.$status);

    	// Check if the status is valid
    	if( !in_array( $status, Analysis::STATUS)) return -1;

	    // Query params
      $params = ['status' => $status];

    	// By default, select an Analysis node with a property
    	$query = 'MATCH (a:Analysis)-[:HAS_GOT]->(s:Status{status:{status}})
        WHERE exists(a.status) AND a.status <> "Switching"
         WITH a, a.status AS property LIMIT 1
        SET a.status = "Switching"
        RETURN id(a) AS aid, property';

	    // Special handling of Processing status queue nodes
      if( $status == 'Processing')

	      $query = 'MATCH (:Status{status:{status}})-[:FIRST]->(f:Analysis)
          MATCH (f)-[:NEXT_BY_STATUS*0..]->(a:Analysis)
           WHERE exists(a.status) AND a.status <> "Switching"
            AND a.status IN ["Skipped","Partially","Evaluated"]
           WITH a, a.status AS property LIMIT 1
          SET a.status = "Switching"
          RETURN id(a) AS aid, property';

      // Iterate through all the fetched records
      $result = $this->neo4j_client->run($query, $params);

      foreach ($result->records() as $record)
      if( ($aid = $record->value('aid')) != null) {

	      $property = $record->value('property');

  	    // Working with Pending Analysis node
  	    if( $status == 'Pending') {

          // Switch to Processing
        	if( $property == 'Processing')
  	        $status = 'Processing';

    	    // Switch to Complete
    	    else if( $property == 'Partially'
  		      || $property == 'Evaluated')
  	        $status = 'Complete';

          // Skipped node
          else
  	        $status = 'Skipped';

  	    // Working with Processing Analysis nodes
        } else if( $status == 'Processing') {

          // Switch to Complete
          if( $property == 'Partially'
            || $property == 'Evaluated')
            $status = 'Complete';

          // Skipped node
          else
            $status = 'Skipped';

  	    // Unexpected situation, skip
        } else

  	      $status = 'Skipped';

        // will cause the QueueManagerCommandHandler to be called
        $this->bus->dispatch(new QueueManagerCommand(
          'promote', ['analysis_id' => $aid, 'status' => $status]));

        if( $status == 'Complete') {

          // will cause the QueueManagerCommandHandler to be called
          $this->bus->dispatch(new InputOutputOperation( 'export_json',
            ['analysis_id' => $aid]));

          // Send notification message
          $this->notifyUser( $aid);
        }

        // Return fetched Analysis id
	      return $aid;
      }

	    return -1;
    }



    // Set Analysis status
    // Should NEVER be called directly
    // Call promoteAnalysis instead to rearrange all rels properly
    private function setAnalysisStatus( $aid, $status = 'Pending')
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Setting analysis status '.$status);

	// Return false if node does not exist
	if( !$this->analysisNodeExists( $aid)) return false;

	// Check if the status is valid
	if( !in_array( $status, Analysis::STATUS)) return false;

	// Query params
        $params = ['aid' => intval( $aid), 'status' => $status];

	// Get current analysis status
	$current_status = $this->getAnalysisStatus( $aid);

	// Disconnect analysis node from status queue
	if( !$this->detachStatusRels( $aid)) return false;
//	$this->detachStatusRels( $aid);

	// get first and last nodes for a given status
	$first = $this->getStatusQueueNode( $status);
	$last = $this->getStatusQueueNode( $status, 'last');

	// Inconsistent status queue
	if( ($first == -1 && $last != -1) ||
		($first != -1 && $last == -1)) {

	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Inconsistent status queue');
	  return false;
	}

  if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Adjusting analysis status relationships');

	// Status queue empty, add very first item
	$query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (s:Status{status:{status}})
MERGE (a)-[:HAS_GOT]->(s)
MERGE (s)-[:FIRST]->(a)
MERGE (s)-[:LAST]->(a)';

	// Status queue NOT empty, attach to tail
	// Any status except for Pending
	// Pending when only one node present, no (a)-[:NEXT]->
	if( $last != -1)
	  $query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (s:Status{status:{status}})
MATCH (s)-[rl:LAST]-(l:Analysis)
MERGE (a)-[:HAS_GOT]->(s)
MERGE (s)-[:LAST]->(a)
MERGE (l)-[:NEXT_BY_STATUS]->(a)
DELETE rl';

	// Multiple pending nodes
//		&& !$this->analysisOnQueueTail( $aid))
	// It is possible that Current node has muntiple Skipped
	// Partially, etc. so previous Pending is NOT just (p)-[:NEXT]->(a)
	// One has to *find* it.
	// But the variable length path will expand to all prev nodes
	// It cna be either a promotion (attached to Current)
	// or regular addition to Tail
	if( $status == 'Pending' && $first != $last) {

	  // Object-oriented way
	  $this->analysis_id = $aid;

	  // Fetch previous Pending node
	  $prev = $this->getStatusQueueNode( $status, 'prev');

	  // Add one more parameter to the query
	  $params['pid'] = intval( $prev);

	  // We are not working with tail
	  if( $prev != $last)
	    $query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (p:Analysis) WHERE id(p)={pid}
MATCH (s:Status{status:{status}})
MATCH (p)-[r:NEXT_BY_STATUS]->(n)
MERGE (a)-[:HAS_GOT]->(s)
MERGE (p)-[:NEXT_BY_STATUS]->(a)
MERGE (a)-[:NEXT_BY_STATUS]->(n)
DELETE r';

	}

	// Delete any status properties for Pending nodes
  // so that evaluator can easily start processing them
	if( $status == 'Pending')
	  $query .= ' REMOVE a.status';

/*
	  $query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (s:Status{status:{status}})
MATCH (:Status{status:{status}})<-[:HAS_GOT]-(p:Analysis)-[:NEXT*0..]->(a)
  WITH p LIMIT 1
MATCH (p)-[r:NEXT_BY_STATUS]->(n)
MERGE (a)-[:HAS_GOT]->(s)
MERGE (p)-[:NEXT_BY_STATUS]->(a)
MERGE (a)-[:NEXT_BY_STATUS]->(n)
DELETE r';
	// Change status
	if( $current_status != -1) {

	  // Change status, status queue empty
	  $query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (s:Status{status:"'.$status.'"})
MATCH (a)-[r:HAS_GOT]->(:Status)
MATCH (p:Analysis)-[pr:NEXT_BY_STATUS]->(a)-[nr:NEXT_BY_STATUS]->(n:Analysis)
MERGE (a)-[:HAS_GOT]->(s)
MERGE (s)-[:FIRST]->(a)
MERGE (s)-[:LAST]->(a)
MERGE (p)-[:NEXT_BY_STATUS]->(n)
DELETE r, pr, nr';

	  // Change status, status queue NOT empty
	  if( $first != -1)
	    $query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (s:Status{status:"'.$status.'"})
MATCH (a)-[r:HAS_GOT]->(:Status)
MATCH (s)-[rl:LAST]-(l:Analysis)
MATCH (p:Analysis)-[pr:NEXT_BY_STATUS]->(a)-[nr:NEXT_BY_STATUS]->(n:Analysis)
MERGE (a)-[:HAS_GOT]->(s)
MERGE (s)-[:LAST]->(a)
MERGE (l)-[:NEXT_BY_STATUS]->(a)
MERGE (p)-[:NEXT_BY_STATUS]->(n)
DELETE r, pr, nr';

	  // last item in the status queue
	  if( $first != -1 && $first == $last) {

	    // last item, change status, new status queue empty
	    $query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (s:Status{status:"'.$status.'"})
MATCH (a)-[r]->(:Status)
MERGE (a)-[:HAS_GOT]->(s)
MERGE (s)-[:FIRST]->(a)
MERGE (s)-[:LAST]->(a)
DELETE r';

	  // last item, Change status, status queue NOT empty
	  if( $first != -1)
	    $query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (s:Status{status:"'.$status.'"})
MATCH (a)-[r]->(:Status)
MATCH (s)-[rl:LAST]-(l:Analysis)
MERGE (a)-[:HAS_GOT]->(s)
MERGE (s)-[:LAST]->(a)
MERGE (l)-[:NEXT_BY_STATUS]->(a)
DELETE r';

	  }
	}
*/
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug( $query . ' ' . implode( ',', $params));

        $this->neo4j_client->run($query, $params);

/*

        $params = ["aid" => intval( $aid)];
        $this->neo4j_client->run($query, $params);

/*
	// Deleting existing status labels and adding new
	$query = 'MATCH (a:Analysis) WHERE id(a)={aid}
REMOVE a:'.$statusLabels.' SET a:'.$label;

	// Send the query, we do NOT expect any return
        $params = ["aid" => intval( $aid)];
        $this->neo4j_client->run($query, $params);

        // Forcefully promote the analysis node
	if( $label == "Pending") $this->promoteAnalysis( $aid);
*/

	// Record status change if NOT promotion
	if( $current_status != array_search( $status, Analysis::STATUS))
	  $this->createAnalysisActionNode( $aid, 'StatusChange', $status);

	return true;
    }



    // Set Analysis side (WhiteSide/BlackSide/Both)
    public function setAnalysisSide( $aid, $value)
    {
	// Sides to analyze
	$sides = Analysis::SIDE;
        if( in_array( $value, Analysis::SIDE))
	  $sides = [$value];

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Suggested analysis node labels :'.
		implode( ':', $sides));

	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Access denied');
	  return false;
	}

	if( !$this->analysisNodeExists( $aid)) {
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Analysis node does NOT exist');
	  return false;
	}

	// Check if the node with new side labels exists
	$gid = $this->getAnalysisGameId( $aid);
	$depth = $this->getAnalysisDepth( $aid);

	// Iterate through all supplied side lables
	foreach( $sides as $key => $side) {

	  // Existing analysis id
	  $eaid = $this->matchGameAnalysis( $gid, $depth, ':'.$side);
	  if( $eaid != -1 && $eaid != $aid)
	    unset( $sides[$key]);
	}

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Adding analysis node labels :'.
		implode( ':', $sides));

	// Sides array does not have any records
	if( count( $sides) == 0) {
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('No labels left to assign');
	  return false;
	}

	// Deleting existing labels and adding new
	$query = 'MATCH (a:Analysis) WHERE id(a)={aid}
REMOVE a:'.Analysis::SIDE['White'].':'.Analysis::SIDE['Black'].'
SET a:' . implode( ':', $sides);

	// Send the query, we do NOT expect any return
        $params = ["aid" => intval( $aid)];
        $this->neo4j_client->run($query, $params);

	// Record action
	$this->createAnalysisActionNode( $aid,
		'SideChange', implode( ' ',$sides));

	return true;
    }



    // Set Analysis depth (fast|deep)
    public function setAnalysisDepth( $aid, $value = 'fast')
    {
	// Depth paramaeter
	$depth = $this->depth['fast'];
	if( array_key_exists( $value, $this->depth))
	  $depth = $this->depth[$value];

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Setting analysis node depth '.$depth);

	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Access denied');
	  return false;
	}

	if( !$this->analysisNodeExists( $aid)) {
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Analysis node does NOT exist');
	  return false;
	}

	// Check if the node with new depth exists
	$gid = $this->getAnalysisGameId( $aid);
	$suggested_sides = $this->getAnalysisSides( $aid);

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Suggested analysis node labels :'.
		implode( ':', $suggested_sides));

	// Iterate through all possible side lables
	$sides = Analysis::SIDE;
	foreach( $sides as $key => $side)
	  if( $this->matchGameAnalysis( $gid, $depth, ':'.$side) == -1)
	    unset( $sides[$key]);

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Remaning analysis node labels :'.
		implode( ':', $sides));

	// Find sides to set depth for
	$diff_sides = array_diff( $suggested_sides, $sides);

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Diff analysis node labels :'.
		implode( ':', $diff_sides));

	if( count( $diff_sides) == 0) {

	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('No more sides to change depth for');
	  return true;
	}

	// Change side for analysis node
	if( count( $sides) != 0)
	  $this->setAnalysisSide( $aid, array_pop( $diff_sides));

	// Deleting existing relation and adding new
	$query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (a)-[r:REQUIRED_DEPTH]->(old:Depth)
MATCH (new:Depth{level:{depth}})
CREATE (a)-[:REQUIRED_DEPTH]->(new) DELETE r';

	// Send the query, we do NOT expect any return
        $params = ["aid" => intval( $aid), "depth" => intval( $depth)];
        $this->neo4j_client->run($query, $params);

	// Record action
	$this->createAnalysisActionNode( $aid, 'DepthChange', $depth);

	return true;
    }



    // Return Analysis status property for a particular node
    public function getAnalysisStatusProperty( $aid) {

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Fetching Analysis status property');

	// Check if analysis node exists
	if( !$this->analysisNodeExists( $aid))
	  return -1;

	$query = '
MATCH (a:Analysis) WHERE id(a)={aid}
RETURN a.status AS status';

        $params = ["aid" => intval( $aid)];
        $result = $this->neo4j_client->run($query, $params);
        foreach ($result->records() as $record)
          if( $record->value('status') != null)
            return array_search( $record->value('status'), Analysis::STATUS);

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Error fetching analysis status property');

	return -1; // Negative to indicate error
    }



    // Get various Queue nodes (Head|Current|Tail|Next)
    // Return Analysis status code for a particular node
    public function getAnalysisStatus( $aid) {

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Fetching Analysis status');

	// Check if analysis node exists
	if( !$this->analysisNodeExists( $aid))
	  return -1;

	$query = '
MATCH (a:Analysis)-[:HAS_GOT]->(t:Status) WHERE id(a)={aid}
RETURN t.status AS status';

        $params = ["aid" => intval( $aid)];
        $result = $this->neo4j_client->run($query, $params);
        foreach ($result->records() as $record)
          if( $record->value('status') != null)
            return array_search( $record->value('status'), Analysis::STATUS);

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Error fetching analysis status');

	return -1; // Negative to indicate error
    }


    // Get various Queue nodes (Head|Current|Tail|Next or qid)
    public function getQueueNode( $type = 'Next') {

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Getting '.$type.' queue node');

	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Access denied');
	  return false;
	}

	// Check by queue node id
	if( is_numeric( $type))
	  if( $this->queueNodeExists( $type)) {
	    $this->queue_id = $type;
	    return $type;
	  } else
	    return -1;

	// Valid Queue node labels
	$labels = ['Head', 'Current', 'Tail'];
	if( !in_array( $type, $labels) && $type != 'Next') {

	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Invalid queue node label '. $type);

	  return -1;
	}

	// Basic label check
	if( !$this->queueGraphExists()) return -1;

        // Match queue node by label by default
        $query = 'MATCH (q:'.$type.') RETURN id(q) AS qid LIMIT 2';

	if( $type == 'Next')
          $query = 'MATCH (q:Queue) WHERE id(q)={qid}
OPTIONAL MATCH (q)-[:NEXT]->(n:Queue) RETURN id(n) AS qid LIMIT 2';

	$params = ['qid' => intval( $this->queue_id)];
        $result = $this->neo4j_client->run($query, $params);
	$records = $result->getRecords();

        // We expect a single record
	if( count( $records) > 1) {

	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Expected single record but got '. count( $records));

	  return -1;
	}

        // We expect a single record or null
        foreach ( $records as $record)
          if( $record->value('qid') != null) {

	    $this->queue_id = $record->value('qid');
	    return $this->queue_id;
	  }

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Error fetching queue node');

        return -1;
    }


    // Return node id for given status (first/last/previous)
    public function getStatusQueueNode( $status = 'Pending', $type = 'first') {

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Fetching '.$type.' '.$status.' queue node');

	// Check if the status is valid
	if( !in_array( $status, Analysis::STATUS)) return -1;

	// Check if analysis node exists
	if( !$this->queueGraphExists()) return -1;

	// By default we want first node
	$rel = 'FIRST';
	if( $type == 'last') $rel = 'LAST';

	$query = 'MATCH (s:Status{status:{status}})
OPTIONAL MATCH (s)-[:'.$rel.']->(a:Analysis)
RETURN id(a) AS aid';

	// Previous node requires var-length path
	// But we limit it to a :Queue node
	// so it does NOT expand till :Head
	if( $type == 'prev')
	  $query = '
MATCH (f:Analysis)<-[:FIRST]-(:Queue)-[:QUEUED]->(l:Analysis)-[:NEXT]->(a:Analysis)
WHERE id(a) = {aid}
MATCH path=shortestPath((f)-[:NEXT*0..]->(l)) WITH a,nodes(path) as nodes
UNWIND nodes AS node
MATCH (node)-[:HAS_GOT]->(:Status{status:{status}})
MATCH path=shortestPath((node)-[:NEXT*0..]->(a)) WITH id(node) AS aid, size(nodes(path)) AS dist
RETURN aid ORDER BY dist LIMIT 1';
/*
	  $query = '
MATCH (a:Analysis) WHERE id(a) = {aid}
MATCH (s:Status{status:{status}})
MATCH path=(a)<-[:NEXT*1..]-(p:Analysis)-[:HAS_GOT]->(s)
 WITH size(nodes(path)) as distance, p
RETURN id(p) AS aid ORDER BY distance LIMIT 1';
*/
	$params = ['status' => $status, 'aid' => intval( $this->analysis_id)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('aid') != null)
            return $record->value('aid');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Error fetching status node');

	return -1; // Negative to indicate error
    }



    // Return Analysis depth for a particular node
    public function getAnalysisDepth( $aid) {

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Fetching Analysis depth for: '.$aid);

	// Check if analysis node exists
	if( !$this->analysisNodeExists( $aid)) return -1;

	$query = '
MATCH (a:Analysis)-[:REQUIRED_DEPTH]->(d:Depth) WHERE id(a)={aid}
RETURN d.level AS depth';

        $params = ["aid" => intval( $aid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('depth') != null)
            return $record->value('depth');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Error fetching analysis depth');

	return -1; // Negative to indicate error
    }



    // Return a Game Id for an Analysis
    public function getAnalysisGameId( $aid) {

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Fetching Game Id for '. $aid);

	// Check if analysis node exists
	if( !$this->analysisNodeExists( $aid)) return -1;

	$query = '
MATCH (a:Analysis)-[:REQUESTED_FOR]->(g:Game) WHERE id(a)={aid}
RETURN id(g) AS gid';

        $params = ["aid" => intval( $aid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('gid') != null)
            return $record->value('gid');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Error fetching analysis game id');

	return -1; // Negative to indicate error
    }


    // Return a Game Id for an Analysis
    public function getAnalysisUserId( $aid) {

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Fetching User Id for '. $aid);

	// Check if analysis node exists
	if( !$this->analysisNodeExists( $aid)) return -1;

	$query = '
MATCH (a:Analysis)-[:REQUESTED_BY]->(u:WebUser) WHERE id(a)={aid}
RETURN u.id AS uid';

        $params = ["aid" => intval( $aid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( $record->value('uid') != null)
            return $record->value('uid');

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Error fetching analysis user id');

	return -1; // Negative to indicate error
    }


    // Return Analysis sides array
    private function getAnalysisSides( $aid) {

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Fetching analysis sides for '. $aid);

	// Check if analysis node exists
	if( !$this->analysisNodeExists( $aid)) return -1;

	$query = 'MATCH (a:Analysis) WHERE id(a)={aid} RETURN a';

        $params = ["aid" => intval( $aid)];
        $result = $this->neo4j_client->run($query, $params);

        foreach ($result->records() as $record)
          if( ($labelsObj = $record->get('a')) != null)
	    return array_intersect(
		Analysis::SIDE, $labelsObj->labels());

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Error fetching analysis sides');

	return []; // Empty array
    }



    // Promote (:Analysis) to a Current node
    public function promoteAnalysis( $aid, $status = 'Pending')
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Promoting analysis node');

	if( !$this->security->isGranted('ROLE_QUEUE_MANAGER')) {
          $this->logger->debug('Access denied');
	  return false;
	}

	// Check if the status is valid
	if( !in_array( $status, Analysis::STATUS)) {

	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Invalid status '.$status);
	  return false;
	}

	// Make sure the analysis exists
	if( !$this->analysisNodeExists( $aid)) {

	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Analysis node does NOT exist');
	  return false;
	}

	// Check if there is already analysis graph present
	if( !$this->updateCurrentQueueNode( true)) return false;

	// Disconnect analysis node from it's current place
	if( !$this->detachAnalysisNode( $aid)) return false;

	// Attach floating Analysis node to the :Current node
	$query = 'MATCH (a:Analysis) WHERE id(a)={aid} MATCH (c:Current)
MERGE (c)-[:QUEUED]->(a)';

	// Send the query, we do NOT expect any return
        $params = ["aid" => intval( $aid)];
        $this->neo4j_client->run($query, $params);

	// Record promotion for Pending only
	if( $status == 'Pending')
	  if( !$this->createAnalysisActionNode( $aid, 'Promotion'))
	    return false;

        // Finally create necessary relationships with siblings
	if( !$this->enqueueAnalysisNode( $aid)) return false;

	if( !$this->setAnalysisStatus( $aid, $status))
	  return false;
/*
	// Set the same status as before
	if( ($current_status = $this->getAnalysisStatus( $aid)) != -1)
	  if( !$this->setAnalysisStatus( $aid, self::STATUS[$status]))
	    return false;
	else
	  return false;
*/
        // Return
        return true;
    }



    // Detach (:Analysis) node and interconnect affected :Analysys and :Queue nodes
    private function detachAnalysisNode( $aid)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Detaching analysis node id: '. $aid);

	// Make sure analysis node exists
	if( !$this->analysisNodeExists( $aid)) return false;

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

	$query = 'MATCH (a:Analysis) WHERE id(a)={aid}
OPTIONAL MATCH (a)<-[:QUEUED]-(q:Queue)
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

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug( "pl: $pl_id, cp: $cp_id, cn: $cn_id, nf: $nf_id");

	// Basic :analysis matching query
	$query = 'MATCH (a:Analysis) WHERE id(a)={aid}
MATCH (a)<-[:QUEUED]-(q:Queue) ';

	//
	// See Ticket https://trac.tutolmin.com/chess/ticket/107
	//

	// 1) The only :Analysis node left in the graph
        if( $pl_id == null && $cp_id == null && $cn_id == null && $nf_id == null) {
	  $query .= 'RETURN id(a)';
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug( "Detach Analysis type1");
	}

	// 2) The only :Analysis node for the :Head, next :Queue node(s) exist
        if( $pl_id == null && $cp_id == null && $cn_id == null && $nf_id != null) {

	  // Assign Current label to the next Queue node
	  $query_current = "";
	  if( $current) $query_current = ":Current";

	  $query .= 'MATCH (q)-[:NEXT]->(n:Queue)
SET n:Head'.$query_current.' DETACH DELETE q';

	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug( "Detach Analysis type2 ". ($current?":Current":""));
	}

	// 3) First :Analysis node for single :Head node (:Tail)
	// 4) First :Analysis node for :Head, next :Queue node(s) exist
        if( ($pl_id == null && $cp_id == null && $cn_id != null && $nf_id == null) ||
            ($pl_id == null && $cp_id == null && $cn_id != null && $nf_id != null)) {
	  $query .= 'MATCH (a)-[:NEXT]->(cn:Analysis) MERGE (cn)<-[:FIRST]-(q)';
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug( "Detach Analysis type34");
	}

	// 5) Last (but not the only) :Analysis node for single :Head (:Tail)
	// 13) Last (but not the only) :Analysis node for the :Tail
        if( ($pl_id == null && $cp_id != null && $cn_id == null && $nf_id == null) ||
            ($pl_id != null && $cp_id != null && $cn_id == null && $nf_id == null)) {
	  $query .= 'MATCH (cp:Analysis)-[:NEXT]->(a) MERGE (cp)<-[:LAST]-(q)';
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug( "Detach Analysis type513");
	}

	// 6) Last :Analysis node for :Head, next :Queue node(s) exist
	// 14) Last :Analysis node for the regular :Queue
        if( ($pl_id == null && $cp_id != null && $cn_id == null && $nf_id != null) ||
            ($pl_id != null && $cp_id != null && $cn_id == null && $nf_id != null)) {
	  $query .= 'MATCH (cp:Analysis)-[:NEXT]->(a)-[:NEXT]->(nf:Analysis)
MERGE (cp)<-[:LAST]-(q) MERGE (cp)-[:NEXT]->(nf)';
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug( "Detach Analysis type614");
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
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug( "Detach Analysis type781516");
	}

	// 9) The only :Analysis node for the :Tail
        if( $pl_id != null && $cp_id == null && $cn_id == null && $nf_id == null) {

	  // Assign Current label to the next Queue node
	  $query_current = "";
	  if( $current) $query_current = ":Current";

	  $query .= 'MATCH (p:Queue)-[:NEXT]->(q)
SET p:Tail'.$query_current.' DETACH DELETE q';

	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug( "Detach Analysis type9 ".($current?":Current":""));
	}

	// 10) The only :Analysis node for the regular :Queue
        if( $pl_id != null && $cp_id == null && $cn_id == null && $nf_id != null) {

	  // Assign Current label to the next Queue node
	  $query_current = "";
	  if( $current) $query_current = "SET n:Current";

	  $query .= 'MATCH (p:Queue)-[:NEXT]->(q)-[:NEXT]->(n:Queue)
MATCH (pl:Analysis)-[:NEXT]->(a)-[:NEXT]->(nf:Analysis)
MERGE (p)-[:NEXT]->(n) MERGE (pl)-[:NEXT]->(nf) '.$query_current.' DETACH DELETE q';

	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug( "Detach Analysis type10 ".($current?":Current":""));
	}

	// 11) First :Analysis node for the :Tail
	// 12) First :Analysis node for the regular :Queue
        if( ($pl_id != null && $cp_id == null && $cn_id != null && $nf_id == null) ||
            ($pl_id != null && $cp_id == null && $cn_id != null && $nf_id != null)) {
	  $query .= 'MATCH (pl:Analysis)-[:NEXT]->(a)-[:NEXT]->(cn:Analysis)
MERGE (cn)<-[:FIRST]-(q) MERGE (pl)-[:NEXT]->(cn)';
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug( "Detach Analysis type1112");
	}

	// Send the query, we do NOT expect any return
        $this->neo4j_client->run( $query, $params);

	// Delete relationships to siblings and :Queue
	$query = 'MATCH (a:Analysis) WHERE id(a)={aid}
OPTIONAL MATCH (:Analysis)-[s:NEXT]-(a)
OPTIONAL MATCH (:Queue)-[r]->(a) DELETE s,r';

	// Send the query, we do NOT expect any return
        $this->neo4j_client->run( $query, $params);

        // Return
        return true;
    }



    // Interconnect (:Analysis) node with siblings
    private function enqueueAnalysisNode( $aid)
    {
	if( $_ENV['APP_DEBUG'])
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

	if( $_ENV['APP_DEBUG'])
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
          if( $record->value('aid') != null) {

	    // Add necessary status relationships
//	    $this->linkStatusRelationships( $aid);
	    return true;
	}

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Error while enqueuing analysis node!');

	return false;
    }



    // Queue Game Analysis function
    public function enqueueGameAnalysis( $gid, $depth_param, $sideLabel)
    {
	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Staring game anaysis enqueueing process.');

	if( !$this->security->isGranted('ROLE_USER')) {
	  if( $_ENV['APP_DEBUG'])
            $this->logger->debug('Access denied');
	  return -1;
	}

  // Depth parameter
	$depth = $this->depth['fast'];
	if( array_key_exists( $depth_param, $this->depth))
	  $depth = $this->depth[$depth_param];

	// Match analysis for both sides
	$sides = array_unique( array_slice( explode( ':', $sideLabel), 1));

	// Check if the side analysis is already present
	foreach( $sides as $key => $side) {

          // Check if the :Game has already been queued
	  $aid = $this->matchGameAnalysis( $gid, $depth, ':'.$side);
          if( $aid != -1) {

	    if( $_ENV['APP_DEBUG'])
              $this->logger->debug(
		'The game has already been queued for analysys for '.
		$side. ' depth '.$depth);

	    // Delete array element
	    unset( $sides[$key]);
          }
	}

	// If sides array is not empty build sides label
	if( count( $sides) > 0)
	  $sideLabel = ':'.implode( ':', $sides);
	else
	  return -1;

	// Make sure user is allowed to add more items
	if( !$this->checkUserLimit()) {

	    if( $_ENV['APP_DEBUG'])
              $this->logger->debug(
		'User has exceeded their submission limit.');

              return -1;
	}

	// Check system wide limit
	if( $this->countAnalysisNodes( 'Pending', true) >=
		$_ENV['PENDING_QUEUE_LIMIT']) {

	    if( $_ENV['APP_DEBUG'])
              $this->logger->debug(
		'System wide submission limit reached.');

              return -1;
	}

        // Create a new (:Analysis) node
        $aid = $this->createAnalysisNode( $gid, $depth, $sideLabel);

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Created game analysis id: '.$aid);

	// Error creating analysis node
	if( $aid == -1) return -1;

        // Finally create necessary relationships with siblings
	if( !$this->enqueueAnalysisNode( $aid)) return -1;

	// Set Pending status by default
	if( !$this->setAnalysisStatus( $aid)) return -1;

	return $aid;
    }



    // get the Analysis evaluation speed
    public function getEvaluationSpeed( $type, $number)
    {
	// Depth paramaeter
	$depth = $this->depth['fast'];
	if( $type == $this->depth['deep'])
	  $depth = $this->depth['deep'];

	// Limit number of games to get
	if( !is_numeric( $number) || $number < 1 || $number > 100)
	  return -1; // Negative to indicate error

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Getting analysis '.$depth.
		' evaluation speed for '.$number.' game(s)');

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

	// Maintenance
	if( !$this->updateCurrentQueueNode()) return -1;

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

	// Nothing has been fetched from the DB, set dummy
	if( $speed == 0)
	  if( $depth == $this->depth['fast']) $speed = 2000;
	    else $speed = 8000;

	if( $_ENV['APP_DEBUG'])
          $this->logger->debug( 'Fetched speed: '.$speed. ' ms');

        // Storing the value in cache
        apcu_add( $cacheVarName, $speed, 3600);

	return $speed;
    }

    // Notify a user about analysis completion
    public function notifyUser( $aid) {

//      $depth = $this->getAnalysisDepth( $aid);
      $uid = $this->getAnalysisUserId( $aid);

      if( $_ENV['APP_DEBUG'])
              $this->logger->debug( 'Fetched uid: '.$uid);

      $user = $this->userRepository->findOneBy(['id' => $uid]);

      if( $_ENV['APP_DEBUG'])
              $this->logger->debug( 'Fetched email: '.$user->getEmail());

      if( $user->getNotificationType() == "digest") {

        // Add a record into CompleteAnalysis table
        $ca = new CompleteAnalysis();

        // relates this product to the user
        $ca->setUser($user);
        $ca->setAnalysisId($aid);

        $this->em->persist($ca);
        $this->em->flush();

      } else if( $user->getNotificationType() == "instant") {

        // Dispatch email immediately
        $this->dispatchEmail( $user, [$aid]);

      } else {

        // Some other notification type
        // Possibly "none" to disable notifications altogether
      }

    }

    public function dispatchEmail( $user, $aids) {

      $games      = array();
      $whites     = array();
      $white_elos = array();
      $white_cs   = array();
      $blacks     = array();
      $black_elos = array();
      $black_cs   = array();
      $results    = array();
      $ecos       = array();
      $openings   = array();
      $variations = array();
      $events     = array();
      $dates      = array();
      $links      = array();

      foreach ($aids as $key => $aid) {

        $gid = $this->getAnalysisGameId( $aid);

        $games[] = $key;

        if( $_ENV['APP_DEBUG'])
                $this->logger->debug( 'Fetched game id: '.$gid);

        // Fetch game details
      	$params = ["gid" => $gid];
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
                  OPTIONAL MATCH (line)-[:HAS_GOT]->(sum_w:Summary:White)
                  OPTIONAL MATCH (line)-[:HAS_GOT]->(sum_b:Summary:Black)
                 RETURN game.hash, date_str, result_w, event.name, player_b.name, player_w.name,
                  CASE WHEN elo_b.rating IS NULL THEN '' ELSE '('+elo_b.rating+')' END AS black_rating,
                  CASE WHEN elo_w.rating IS NULL THEN '' ELSE '('+elo_w.rating+')' END AS white_rating,
                   game, line.hash, eco.code, opening.opening,
                  CASE WHEN opening.variation IS NULL THEN '' ELSE opening.variation END AS variation,
                  CASE WHEN sum_w.cheat_score IS NULL THEN '' ELSE sum_w.cheat_score END AS white_cs,
                  CASE WHEN sum_b.cheat_score IS NULL THEN '' ELSE sum_b.cheat_score END AS black_cs
                 LIMIT 1";

        $result = $this->neo4j_client->run( $query, $params);

        foreach ($result->records() as $record) {

      	  // Fetch game object
//      	  $gameObj = $record->get('game');
          //	  $this->game['ID']	= $gameObj->identity();
      	  $whites[]      = $record->value('player_w.name');
      	  $white_elos[]  = $record->value('white_rating');
          $white_cs[]    = $record->value('white_cs');
      	  $blacks[]      = $record->value('player_b.name');
      	  $black_elos[]  = $record->value('black_rating');
          $black_cs[]    = $record->value('black_cs');
      	  $events[]      = $record->value('event.name');
      	  $dates[]       = $record->value('date_str');
      	  $ecos[]        = $record->value('eco.code');
      	  $openings[]    = $record->value('opening.opening');
      	  $variations[]  = $record->value('variation');

      	  // Result in human readable format
          $labelsObj = $record->get('result_w');
          $labelsArray = $labelsObj->labels();
          if( in_array( "Draw", $labelsArray))
            $results[] = "1/2-1/2";
          else if( in_array( "Win", $labelsArray))
            $results[] = "1-0";
          else if( in_array( "Loss", $labelsArray))
            $results[] = "0-1";
          else
            $results[] = "Unknown";

          // generated URLs are "absolute paths" by default. Pass a third optional
          // argument to generate different URLs (e.g. an "absolute URL")
          $links[] = $this->router->generate('index', ['gid' => $record->value('game.hash')],
            UrlGeneratorInterface::ABSOLUTE_URL);
        }
      }

      $email = (new TemplatedEmail())
          ->from(new Address('support@chesscheat.com', 'ChessCheat Support'))
          ->to(new Address($user->getEmail(),
            $user->getFirstName()." ".$user->getLastName()))
          //->cc('cc@example.com')
          ->bcc('support@chesscheat.com')
          //->replyTo('fabien@example.com')
          //->priority(Email::PRIORITY_HIGH)
          ->subject('Game analysis complete!')
          // path of the Twig template to render
          ->htmlTemplate('emails/analysis_complete.html.twig')
          ->textTemplate('emails/analysis_complete.txt.twig')

          // pass variables (name => value) to the template
          ->context([
              'game'      => $games,
              'link'      => $links,
              'white'     => $whites,
              'white_elo' => $white_elos,
              'white_cs'  => $white_cs,
              'black'     => $blacks,
              'black_elo' => $black_elos,
              'black_cs'  => $black_cs,
              'result'    => $results,
              'eco'       => $ecos,
              'opening'   => $openings,
              'variation' => $variations,
              'event'     => $events,
              'date'      => $dates,
//              'expiration_date' => new \DateTime('+7 days'),
              'firstName' => (strlen( $user->getFirstName())>0)?$user->getFirstName():"User",
          ])
      ;

      $this->mailer->send($email);
    }
}
?>
