<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Stopwatch\Stopwatch;
use App\Service\QueueManager;

class EvaluationSpeedController extends AbstractController
{
    // Number of games to take into account
    private $NUMBER = 0;

    // Analysis types
    private $FAST = 0;
    private $DEEP = 0;

    // StopWatch instance
    private $stopwatch;

    // Logger reference
    private $logger;

    // Queue manager reference
    private $queueManager;

    // Dependency injection of necessary services
    public function __construct( Stopwatch $watch,
	LoggerInterface $logger, QueueManager $qm)
    {
        $this->NUMBER = $_ENV['SPEED_EVAL_GAMES_LIMIT'];
        $this->FAST = $_ENV['FAST_ANALYSIS_DEPTH'];
        $this->DEEP = $_ENV['DEEP_ANALYSIS_DEPTH'];

        $this->stopwatch = $watch;
	$this->logger = $logger;
	$this->queueManager = $qm;

	// starts event named 'eventName'
	$this->stopwatch->start('evaluationSpeed');
    }

    public function __destruct()
    {
	// stops event named 'eventName'
	$this->stopwatch->stop('evaluationSpeed');
    }

    /**
      * @Route("/internal/evaluationSpeed")
      */
    public function evaluationSpeed()
    {
      // HTTP request
      $request = Request::createFromGlobals();

      // Get type from a query parameter
      $type = $request->request->get('type', "");

      if( $_ENV['APP_DEBUG'])
        $this->logger->debug( "Type: ". $type);

      $speed = array();

      if( $type == "fast" || $type == "")
        $speed["fast"] = $this->queueManager
	->getEvaluationSpeed( $this->FAST, $this->NUMBER);

      $this->stopwatch->lap('evaluationSpeed');

      if( $type == "deep" || $type == "")
        $speed["deep"] = $this->queueManager
	->getEvaluationSpeed( $this->DEEP, $this->NUMBER);

      $this->stopwatch->lap('evaluationSpeed');

      if( $_ENV['APP_DEBUG'])
        $this->logger->debug( "Evaluation speed: ". implode( ",", $speed));

      // Encode in JSON and output
      return new JsonResponse( $speed);
    }
}
?>
