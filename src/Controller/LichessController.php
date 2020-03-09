<?php

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;

class LichessController extends AbstractController
{
    /**
     * Link to this controller to start the "connect" process
     *
     * @Route("/connect/lichess", name="connect_lichess_start")
     */
    public function connectAction(ClientRegistry $clientRegistry)
    {
        // on Symfony 3.3 or lower, $clientRegistry = $this->get('knpu.oauth2.registry');
    
        // will redirect to Lichess!
        return $clientRegistry
            ->getClient('lichess_oauth') // key used in config/packages/knpu_oauth2_client.yaml
            ->redirect([
//	    	'email:read game:read preference:read', // the scopes you want to access
	    	'email:read preference:read', // the scopes you want to access
            ])
        ;
	}

    /**
     * After going to Lichess, you're redirected back here
     * because this is the "redirect_route" you configured
     * in config/packages/knpu_oauth2_client.yaml
     *
     * @Route("/connect/lichess/check", name="connect_lichess_check")
     */
    public function connectCheckAction(Request $request, ClientRegistry $clientRegistry)
    {
        // ** if you want to *authenticate* the user, then
        // leave this method blank and create a Guard authenticator
        // (read below)

        /** @var \App\Client\Provider\LichessClient $client */
        $client = $clientRegistry->getClient('lichess_oauth');

var_dump( $client);
//var_dump( $client); die;
        try {
            // the exact class depends on which provider you're using
            /** @var \App\Provider\LichessUser $user */
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

            // do something with all this new power!
	    // e.g. $name = $user->getFirstName();
            var_dump($request); die;
            // ...
        } catch (IdentityProviderException $e) {
            // something went wrong!
            // probably you should return the reason to the user
//            var_dump($e->getMessage()); die;
        }

    return $this->redirectToRoute('index');

    }
}
?>
