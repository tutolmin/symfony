<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

class SearchHintController extends AbstractController
{
    // Number of hints to load and display
    const RECORDS_PER_PAGE = 5;

    /**
      * @Route("/searchHint")
      */
    public function searchHint(
        \Symfony\Component\Stopwatch\Stopwatch $stopwatch,
        \GraphAware\Neo4j\Client\ClientInterface $neo4j_client): JsonResponse
    {
        // starts event named 'eventName'
        $stopwatch->start('searchHint');

        // HTTP request
        $request = Request::createFromGlobals();

        // Query params
        $params = [];

	// Hints
	$hints = [];

//        if( $term = $request->query->getAlpha('term', 0)) {
        if( $term = urldecode( $request->query->get('term'))) {

	  if( stripos( "fast", $term) !== false)
	    $hints[] = "fast";

	  if( stripos( "deep", $term) !== false)
	    $hints[] = "deep";

	  if( stripos( "white", $term) !== false)
	    $hints[] = "white";

	  if( stripos( "black", $term) !== false)
	    $hints[] = "black";

	  if( stripos( "1-0", $term) !== false)
	    $hints[] = "1-0";

	  if( stripos( "0-1", $term) !== false)
	    $hints[] = "0-1";

	  if( stripos( "1/2-1/2", $term) !== false
	    || stripos( "draw", $term) !== false)
	    $hints[] = "1/2-1/2";

    if( stripos( "Pending", $term) !== false)
	    $hints[] = "pending";

    if( stripos( "Processing", $term) !== false)
	    $hints[] = "processing";

    if( stripos( "Skipped", $term) !== false)
	    $hints[] = "skipped";

    if( stripos( "Complete", $term) !== false)
	    $hints[] = "complete";

	  if( stripos( "checkmate", $term) !== false) {
	    $hints[] = "checkmate";
/*
	    $hints[] = "checkmate by queen";
	    $hints[] = "checkmate by rook";
	    $hints[] = "checkmate by bishop";
	    $hints[] = "checkmate by knight";
	    $hints[] = "checkmate by pawn";
	    $hints[] = "checkmate by king";
*/
	  }

	  if( stripos( "stalemate", $term) !== false) {
	    $hints[] = "stalemate";
/*
	    $hints[] = "stalemate by king";
	    $hints[] = "stalemate by pawn";
	    $hints[] = "stalemate by rook";
	    $hints[] = "stalemate by queen";
	    $hints[] = "stalemate by bishop";
	    $hints[] = "stalemate by knight";
*/
	  }

	  $params["term"] = $term;
	  $query = "MATCH (player:Player) WHERE player.name CONTAINS {term}
RETURN player.name LIMIT ".self::RECORDS_PER_PAGE;

          $result = $neo4j_client->run($query, $params);

          foreach ($result->records() as $record) {
	    $hints[] = $record->value('player.name');
	    $hints[] = $record->value('player.name'). "_as_white";
	    $hints[] = $record->value('player.name'). "_as_black";
/*
	    $hints[] = $record->value('player.name'). " wins";
	    $hints[] = $record->value('player.name'). " draws";
	    $hints[] = $record->value('player.name'). " loses";
*/
	  }

	}

        $event = $stopwatch->stop('searchHint');

        // Encode in JSON and output
        return new JsonResponse( $hints);
    }
}
?>
