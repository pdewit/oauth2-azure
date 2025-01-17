<?php

namespace TheNetworg\OAuth2\Client\Provider;

use Firebase\JWT\JWT;
use League\OAuth2\Client\Grant\AbstractGrant;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Tool\BearerAuthorizationTrait;
use Psr\Http\Message\ResponseInterface;
use TheNetworg\OAuth2\Client\Grant\JwtBearer;
use TheNetworg\OAuth2\Client\Token\AccessToken;

class Azure extends AbstractProvider
{
    const ENDPOINT_VERSION_1_0 = '1.0';
    const ENDPOINT_VERSION_2_0 = '2.0';

    use BearerAuthorizationTrait;

    public $urlLogin = 'https://login.microsoftonline.com/';

    public $pathAuthorize = '/oauth2/authorize';

    public $pathToken = '/oauth2/token';

    public $scope = [];

    public $scopeSeparator = ' ';

    public $tenant = 'common';

    public $defaultEndPointVersion = self::ENDPOINT_VERSION_1_0;

    public $urlAPI = 'https://graph.windows.net/';

    public $resource = '';

    public $API_VERSION = '1.6';

    public $authWithResource = true;

    public function __construct(array $options = [], array $collaborators = [])
    {
        parent::__construct($options, $collaborators);
        $this->grantFactory->setGrant('jwt_bearer', new JwtBearer());
    }

    public function getBaseAuthorizationUrl()
    {
        return $this->urlLogin . $this->tenant . $this->pathAuthorize;
    }

    public function getBaseAccessTokenUrl(array $params)
    {
        return $this->urlLogin . $this->tenant . $this->pathToken;
    }

    public function getAccessToken($grant, array $options = [])
    {
        if ($this->authWithResource) {
            $options['resource'] = $this->resource ? $this->resource : $this->urlAPI;
        }
        return parent::getAccessToken($grant, $options);
    }

    public function getResourceOwner(\League\OAuth2\Client\Token\AccessToken $token)
    {
        $data = $token->getIdTokenClaims();
        return $this->createResourceOwner($data, $token);
    }

    public function getResourceOwnerDetailsUrl(\League\OAuth2\Client\Token\AccessToken $token)
    {
    }

    public function getObjects($tenant, $ref, &$accessToken, $headers = [])
    {
        $objects = [];

        $response = null;
        do {
            if (false === filter_var($ref, FILTER_VALIDATE_URL)) {
                $ref = $tenant . '/' . $ref;
            }

            $response = $this->request('get', $ref, $accessToken, ['headers' => $headers]);
            $values   = $response;
            if (isset($response['value'])) {
                $values = $response['value'];
            }
            foreach ($values as $value) {
                $objects[] = $value;
            }
            if (isset($response['odata.nextLink'])) {
                $ref = $response['odata.nextLink'];
            } elseif (isset($response['@odata.nextLink'])) {
                $ref = $response['@odata.nextLink'];
            } else {
                $ref = null;
            }
        } while (null != $ref);

        return $objects;
    }

    public function get($ref, &$accessToken, $headers = [])
    {
        $response = $this->request('get', $ref, $accessToken, ['headers' => $headers]);

        return $this->wrapResponse($response, $accessToken);
    }

    public function post($ref, $body, &$accessToken, $headers = [])
    {
        $response = $this->request('post', $ref, $accessToken, ['body' => $body, 'headers' => $headers]);

        return $this->wrapResponse($response, $accessToken);
    }

    public function put($ref, $body, &$accessToken, $headers = [])
    {
        $response = $this->request('put', $ref, $accessToken, ['body' => $body, 'headers' => $headers]);

        return $this->wrapResponse($response, $accessToken);
    }

    public function delete($ref, &$accessToken, $headers = [])
    {
        $response = $this->request('delete', $ref, $accessToken, ['headers' => $headers]);

        return $this->wrapResponse($response, $accessToken);
    }

    public function patch($ref, $body, &$accessToken, $headers = [])
    {
        $response = $this->request('patch', $ref, $accessToken, ['body' => $body, 'headers' => $headers]);

        return $this->wrapResponse($response, $accessToken);
    }

    public function request($method, $ref, &$accessToken, $options = [])
    {
        if ($accessToken->hasExpired()) {
            $accessToken = $this->getAccessToken('refresh_token', [
                'refresh_token' => $accessToken->getRefreshToken(),
            ]);
        }

        $url = null;
        if (false !== filter_var($ref, FILTER_VALIDATE_URL)) {
            $url = $ref;
        } else {
            if (false !== strpos($this->urlAPI, 'graph.windows.net')) {
                $tenant = 'common';
                if (property_exists($this, 'tenant')) {
                    $tenant = $this->tenant;
                }
                $ref = "$tenant/$ref";

                $url = $this->urlAPI . $ref;

                $url .= (false === strrpos($url, '?')) ? '?' : '&';
                $url .= 'api-version=' . $this->API_VERSION;
            } else {
                $url = $this->urlAPI . $ref;
            }
        }

        if (isset($options['body']) && ('array' == gettype($options['body']) || 'object' == gettype($options['body']))) {
            $options['body'] = json_encode($options['body']);
        }
        if (!isset($options['headers']['Content-Type']) && isset($options['body'])) {
            $options['headers']['Content-Type'] = 'application/json';
        }

        $request  = $this->getAuthenticatedRequest($method, $url, $accessToken, $options);
        $response = $this->getParsedResponse($request);

        return $response;
    }

    public function getClientId()
    {
        return $this->clientId;
    }

    /**
     * Obtain URL for logging out the user.
     *
     * @param $post_logout_redirect_uri string The URL which the user should be redirected to after logout
     *
     * @return string
     */
    public function getLogoutUrl($post_logout_redirect_uri)
    {
        return 'https://login.microsoftonline.com/' . $this->tenant . '/oauth2/logout?post_logout_redirect_uri=' . rawurlencode($post_logout_redirect_uri);
    }

    /**
     * Validate the access token you received in your application.
     *
     * @param $accessToken string The access token you received in the authorization header.
     *
     * @return array
     */
    public function validateAccessToken($accessToken)
    {
        $keys        = $this->getJwtVerificationKeys();
        $tokenClaims = (array)JWT::decode($accessToken, $keys, ['RS256']);

        if ($this->getClientId() != $tokenClaims['aud'] && $this->getClientId() != $tokenClaims['appid']) {
            throw new \RuntimeException('The client_id / audience is invalid!');
        }
        if ($tokenClaims['nbf'] > time() || $tokenClaims['exp'] < time()) {
            // Additional validation is being performed in firebase/JWT itself
            throw new \RuntimeException('The id_token is invalid!');
        }

        if ('common' == $this->tenant) {
            $this->tenant = $tokenClaims['tid'];

            $tenant = $this->getTenantDetails($this->tenant);
            if ($tokenClaims['iss'] != $tenant['issuer']) {
                throw new \RuntimeException('Invalid token issuer!');
            }
        } else {
            $tenant = $this->getTenantDetails($this->tenant);
            if ($tokenClaims['iss'] != $tenant['issuer']) {
                throw new \RuntimeException('Invalid token issuer!');
            }
        }

        return $tokenClaims;
    }

    /**
     * Get JWT verification keys from Azure Active Directory.
     *
     * @return array
     */
    public function getJwtVerificationKeys()
    {
        $factory = $this->getRequestFactory();
        $request = $factory->getRequestWithOptions('get', 'https://login.windows.net/common/discovery/keys', []);

        $response = $this->getParsedResponse($request);

        $keys = [];
        foreach ($response['keys'] as $i => $keyinfo) {
            if (isset($keyinfo['x5c']) && is_array($keyinfo['x5c'])) {
                foreach ($keyinfo['x5c'] as $encodedkey) {
                    $cert =
                        '-----BEGIN CERTIFICATE-----' . PHP_EOL
                        . chunk_split($encodedkey, 64,  PHP_EOL)
                        . '-----END CERTIFICATE-----' . PHP_EOL;

                    $cert_object = openssl_x509_read($cert);

                    if ($cert_object === false) {
                        throw new \RuntimeException('An attempt to read ' . $encodedkey . ' as a certificate failed.');
                    }

                    $pkey_object = openssl_pkey_get_public($cert_object);

                    if ($pkey_object === false) {
                        throw new \RuntimeException('An attempt to read a public key from a ' . $encodedkey . ' certificate failed.');
                    }

                    $pkey_array = openssl_pkey_get_details($pkey_object);

                    if ($pkey_array === false) {
                        throw new \RuntimeException('An attempt to get a public key as an array from a ' . $encodedkey . ' certificate failed.');
                    }

                    $publicKey = $pkey_array ['key'];

                    $keys[$keyinfo['kid']] = $publicKey;
                }
            }
        }

        return $keys;
    }

    /**
     * Get the specified tenant's details.
     *
     * @param string $tenant
     *
     * @return array
     * @throws IdentityProviderException
     */
    public function getTenantDetails($tenant)
    {
        $versionPath = $this->defaultEndPointVersion === '2.0' ? '/v2.0' : '';

        $factory = $this->getRequestFactory();
        $request = $factory->getRequestWithOptions(
            'get',
            $this->urlLogin . '/' . $tenant . $versionPath . '/.well-known/openid-configuration',
            []
        );

        $response = $this->getParsedResponse($request);

        return $response;
    }

    protected function checkResponse(ResponseInterface $response, $data)
    {
        if (isset($data['odata.error']) || isset($data['error'])) {
            if (isset($data['odata.error']['message']['value'])) {
                $message = $data['odata.error']['message']['value'];
            } elseif (isset($data['error']['message'])) {
                $message = $data['error']['message'];
            } elseif (isset($data['error']) && !is_array($data['error'])) {
                $message = $data['error'];
            } else {
                $message = $response->getReasonPhrase();
            }

            throw new IdentityProviderException(
                $message,
                $response->getStatusCode(),
                $response
            );
        }
    }

    protected function getDefaultScopes()
    {
        return $this->scope;
    }

    protected function getScopeSeparator()
    {
        return $this->scopeSeparator;
    }

    protected function createAccessToken(array $response, AbstractGrant $grant)
    {
        return new AccessToken($response, $this);
    }

    protected function createResourceOwner(array $response, \League\OAuth2\Client\Token\AccessToken $token)
    {
        return new AzureResourceOwner($response);
    }

    private function wrapResponse($response, $accessToken)
    {
        if (empty($response)) {
            return;
        } elseif (isset($response['value'])) {
            $array = $response['value'];
            if(isset($response['@odata.nextLink'])) {
                $nextLink = substr($response['@odata.nextLink'], strrpos($response['@odata.nextLink'], '/'));
                return array_merge($array, $this->get($nextLink, $accessToken));
            }

            return $array;
        }

        return $response;
    }
}
