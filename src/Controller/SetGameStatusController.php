<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class SetGameStatusController extends AbstractController
{
    /**
      * @Route("/setGameStatus")
      * @Security("is_granted('ROLE_USER')")
      */
    public function setGameStatus( 
	\Symfony\Component\Stopwatch\Stopwatch $stopwatch,
	\GraphAware\Neo4j\Client\ClientInterface $neo4j_client)
    {
	// starts event named 'eventName'
	$stopwatch->start('setGameStatus');

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

	// Prepare analyze addition to a query
	$analyzeStr = " REMOVE g.analyze ";
	if( $sideToAnalyze == "WhiteOnly" || $sideToAnalyze == "BlackOnly")
		$analyzeStr = ", g.analyze = \$side ";

	// Query parameters
	$params = ["gid" => intval( $gameID), "side" => $sideToAnalyze];
	$query = 'MATCH (g:Game) WHERE id(g) = $gid 
		SET g.status="1_Pending"'.$analyzeStr.
		'RETURN g.status LIMIT 1';
        $result = $neo4j_client->run($query, $params);

	$event = $stopwatch->stop('setGameStatus');

	// Encode in JSON and output
        return new Response( "Success!");
    }
}

