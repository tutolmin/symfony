<?php

namespace App\Security;

use App\Entity\User; // your user entity
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Security\Authenticator\SocialAuthenticator;
use App\Provider\Lichess;
use App\Provider\LichessClient;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use GraphAware\Neo4j\Client\ClientInterface;
use App\Service\UserManager;
use App\Provider\LichessUser;

class LichessAuthenticator extends SocialAuthenticator
{
    private $clientRegistry;
    private $em;
    private $userManager;

    /** @var Lichess */
    protected $provider;

    public function __construct(ClientRegistry $clientRegistry,
	EntityManagerInterface $em, Lichess $provider, UserManager $um)
    {
        $this->clientRegistry = $clientRegistry;
        $this->em = $em;
        $this->userManager = $um;
	$this->provider = $provider;
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
        /** @var LichessUser $lichessUser */
        $lichessUser = $this->getLichessClient()
            ->fetchUserFromToken($credentials);

	$lichessUserId = $lichessUser->getId();

  $lichessUserFirstName = $lichessUser->getFirstName();
  $lichessUserLastName = $lichessUser->getLastName();

	$this->provider->setResourceOwnerDetailsUrl( "https://lichess.org/api/account/email");
        $lichessUserEmail = $this->provider->getResourceOwner($credentials)->getEmail();
//        $lichessUserEmail = $lichessUser->getEmail();

        // 1) have they logged in with Lichess before? Easy!
        $existingUser = $this->em->getRepository(User::class)
            ->findOneBy(['lichessId' => $lichessUserId]);

        if ($existingUser) {

            // Merge a :WebUser entity in Neo4j
            $this->userManager->mergeUser( $existingUser->getId());

            return $existingUser;
        }

        // 2) do we have a matching user by email?
        $user = $this->em->getRepository(User::class)
                    ->findOneBy(['email' => $lichessUserEmail]);

        if( $user == null) $user = new User();

        // 3) Maybe you just want to "register" them by creating
        // a User object
        $user->setLichessId($lichessUserId);
        $user->setEmail($lichessUserEmail);
        $user->setFirstName($lichessUserFirstName);
        $user->setLastName($lichessUserLastName);
        $this->em->persist($user);
        $this->em->flush();

        // Merge a :WebUser entity in Neo4j
        $this->userManager->mergeUser( $user->getId());

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
?>
