<?php

// src/Service/UserManager.php

namespace App\Service;

use Psr\Log\LoggerInterface;
use GraphAware\Neo4j\Client\ClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;

class UserManager
{
    // Logger reference
    private $logger;

    // Neo4j client interface reference
    private $neo4j_client;

    // Doctrine EntityManager
    private $em;

    // User repo
    private $userRepository;

    public function __construct( ClientInterface $client, 
	EntityManagerInterface $em, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->neo4j_client = $client;
        $this->em = $em;

        // get the User repository
        $this->userRepository = $this->em->getRepository( User::class);
    }

    // merge :WebUser nodes for all existing users
    public function mergeUsers()
    {
	// Get all the users from repository
        $users = $this->userRepository->findAll();

	// User counter
	$counter = 0;

	// Iterate through all the users
	foreach( $users as $user) {

          $params["id"] = $user->getId();
          $query = 'MERGE (:WebUser{id:{id}})';
          $this->neo4j_client->run( $query, $params);

//          $this->logger->debug('Email '.$user->getEmail());

	  $counter++;
	}

	return $counter;
    }

    // Erase all existing :WebUser nodes
    public function eraseUsers()
    {
        // Erase all nodes
        $this->neo4j_client->run( "MATCH (w:WebUser) DETACH DELETE w", null);
    }
}

?>
