<?php

// src/Service/UserManager.php

namespace App\Service;

use Psr\Log\LoggerInterface;
use GraphAware\Neo4j\Client\ClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
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

    // Security
    private $security;

    public function __construct( ClientInterface $client,
	  EntityManagerInterface $em, LoggerInterface $logger, Security $security)
    {
        $this->logger = $logger;
        $this->neo4j_client = $client;
        $this->em = $em;
	      $this->security = $security;

        // get the User repository
        $this->userRepository = $this->em->getRepository( User::class);
    }



    // merge specific :WebUser
    public function mergeUser( $uid)
    {
/*
	      Can be called from Social authenticator for regular user

        if( !$this->security->isGranted('ROLE_USER_MANAGER')) {
          $this->logger->debug('Access denied');
          return false;
        }
*/
      	// Get the user by id
        $user = $this->userRepository->findOneBy(['id' => $uid]);

        if( $user == null) return false;

        $params["id"] = $user->getId();
        $params["limit"] = $user->getQueueLimit();
        $query = 'MERGE (w:WebUser{id:{id}}) SET w.queueLimit={limit}';
        $this->neo4j_client->run( $query, $params);

        return true;
    }



    // merge :WebUser nodes for all existing users
    public function mergeAllUsers()
    {
        if( !$this->security->isGranted('ROLE_USER_MANAGER')) {
          $this->logger->error('Access denied while merging users');
          return 0;
        }

      	// Get all the users from repository
        $users = $this->userRepository->findAll();

	      // User counter
	      $counter = 0;

	      // Iterate through all the users
        foreach( $users as $user)
          if( $this->mergeUser( $user->getId()))
            $counter++;

        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Merged '.$counter.' users');

        return $counter;
    }



    // Get WebUser id property by email
    public function fetchWebUserId( $email) {

	    // Get the user by email
      $user = $this->userRepository->findOneBy(['email' => $email]);

    	// Get the id if found
    	if( $user != null) {

    	  $wu_id = $user->getId();

        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('WebUser id: '.$wu_id);

    	  return $wu_id;
    	}

	    return -1;
    }



    // Set role to a user
    public function promoteUser( $email, $role) {

        if( !$this->security->isGranted('ROLE_USER_MANAGER')) {
          $this->logger->error('Access denied while promoting user');
          return false;
        }

	      // The role should start with ROLE_
	      if( strpos( $role, 'ROLE_') !== 0)
          return false;

	      // Get the user by email
        $user = $this->userRepository->findOneBy(['email' => $email]);

        // Get the roles, add a new role and save
        if( $user != null) {

          if( $_ENV['APP_DEBUG'])
            $this->logger->debug('WebUser id: '.$user->getId());

          $roles = $user->getRoles();
          $roles[] = $role;
          $user->setRoles( $roles);
          $this->em->persist($user);
          $this->em->flush();

          return true;
	      }

        return false;
    }



    // Erase all existing :WebUser nodes
    public function eraseUsers()
    {
        if( !$this->security->isGranted('ROLE_USER_MANAGER')) {
          $this->logger->error('Access denied while erasing users');
          return false;
        }


        if( $_ENV['APP_DEBUG'])
          $this->logger->debug('Erasing all users');

        // Erase all nodes
        $this->neo4j_client->run( "MATCH (w:WebUser) DETACH DELETE w", null);
    }
}
?>
