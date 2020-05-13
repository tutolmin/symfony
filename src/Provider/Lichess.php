<?php

namespace App\Provider;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Tool\BearerAuthorizationTrait;
use Psr\Http\Message\ResponseInterface;

class Lichess extends AbstractProvider
{
    use BearerAuthorizationTrait;

    private $resourceOwnerDetailsURL = "https://lichess.org/api/account";

    public function getBaseAuthorizationUrl()
    {
        return 'https://oauth.lichess.org/oauth/authorize';
    }

    public function getBaseAccessTokenUrl(array $params)
    {
        return 'https://oauth.lichess.org/oauth';
    }

    public function getResourceOwnerDetailsUrl(AccessToken $token)
    {
	return $this->resourceOwnerDetailsURL;
    }

    public function setResourceOwnerDetailsUrl( $url)
    {
        $this->resourceOwnerDetailsURL = $url;
    }

    protected function getDefaultScopes()
    {
        return [
	    'preference:read',
            'email:read',
	    'game:read',
        ];
    }

    protected function checkResponse(ResponseInterface $response, $data)
    {
        // @codeCoverageIgnoreStart
        if (empty($data['error'])) {
            return;
        }
        // @codeCoverageIgnoreEnd

        $code = 0;
        $error = $data['error'];

        if (is_array($error)) {
            $code = $error['code'];
            $error = $error['message'];
        }

        throw new IdentityProviderException($error, $code, $data);
    }

    protected function createResourceOwner(array $response, AccessToken $token)
    {
        return new LichessUser($response);
    }
}
?>
