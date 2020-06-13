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
            	],
		[]	// options
		);
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
      return $this->redirectToRoute('index');
    }
}
?>
