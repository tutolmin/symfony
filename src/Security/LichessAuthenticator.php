<?php

namespace App\Security;

use App\Entity\User; // your user entity
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Security\Authenticator\SocialAuthenticator;
use KnpU\OAuth2ClientBundle\Client\Provider\LichessClient;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class LichessAuthenticator extends SocialAuthenticator
{
    private $clientRegistry;
    private $em;

    public function __construct(ClientRegistry $clientRegistry, EntityManagerInterface $em)
    {
        $this->clientRegistry = $clientRegistry;
        $this->em = $em;
    }

    public function supports(Request $request)
    {
        // continue ONLY if the current ROUTE matches the check ROUTE
        return $request->attributes->get('_route') === 'connect_lichess_check';
    }

    public function getCredentials(Request $request)
    {
        // this method is only called if supports() returns true

        // For Symfony lower than 3.4 the supports method need to be called manually here:
        // if (!$this->supports($request)) {
        //     return null;
        // }
        return $this->fetchAccessToken($this->getLichessClient());
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {

var_dump( $credentials->getToken());


/*
        $client = $clientRegistry->getClient('lichess_oauth');

var_dump( $client);
//var_dump( $client); die;
        try {
            // the exact class depends on which provider you're using
//            $user = $client->fetchUser();
// get the access token and then user
$accessToken = $client->getAccessToken();
var_dump( $accessToken);
$user = $client->fetchUserFromToken($accessToken)->getId();
var_dump( $user);


// access the underlying "provider" from league/oauth2-client
$provider = $client->getOAuth2Provider();
var_dump( $provider->getResourceOwnerDetailsUrl( $accessToken));
$provider->setResourceOwnerDetailsUrl( "https://lichess.org/api/account/email");
var_dump( $provider->getResourceOwnerDetailsUrl( $accessToken));
$email = $client->fetchUserFromToken($accessToken)->getEmail();
var_dump( $email);



	$client = $this->getClient();
	$provider = $client->getOAuth2Provider();

        $lichessUserId = $this->getLichessClient()
            ->fetchUserFromToken($credentials)->getId();
*/

	$client=$this->getLichessClient();

//var_dump( $client);
// get the access token and then user
$accessToken = $credentials->getToken();
$lichessUser = $client->fetchUserFromToken($accessToken);

// get the access token and then user
var_dump( $credentials);
var_dump( $accessToken);
var_dump( $lichessUser); 
die;
        $email = $lichessUser->getEmail();
/*
        // 1) have they logged in with Lichess before? Easy!
        $existingUser = $this->em->getRepository(User::class)
            ->findOneBy(['lichessId' => $lichessUser->getId()]);
        if ($existingUser) {
            return $existingUser;
        }
*/
        // 2) do we have a matching user by email?
        $user = $this->em->getRepository(User::class)
                    ->findOneBy(['email' => $email]);

	if( $user == null) $user = new User();

        // 3) Maybe you just want to "register" them by creating
        // a User object
//        $user->setLichessId($lichessUser->getId());
        $user->setLichessId($lichessUser->getEmail());
        $user->setEmail($lichessUser->getEmail());
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return LichessClient
     */
    private function getLichessClient()
    {
        return $this->clientRegistry
            // "lichess_main" is the key used in config/packages/knpu_oauth2_client.yaml
            ->getClient('lichess_oauth');
	}

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        // on success, let the request continue
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
		$message = strtr($exception->getMessageKey(), $exception->getMessageData());

        return new Response($message, Response::HTTP_FORBIDDEN);
    }

    /**
     * Called when authentication is needed, but it's not sent.
     * This redirects to the 'login'.
     */
    public function start(Request $request, AuthenticationException $authException = null)
    {
        return new RedirectResponse(
            '/connect', // might be the site, where users choose their oauth provider
            Response::HTTP_TEMPORARY_REDIRECT
        );
    }

    // ...
}

