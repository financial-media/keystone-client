<?php

namespace FM\Keystone\Client;

use Psr\Log\LoggerInterface;
use Guzzle\Common\Event;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use FM\Cache\CacheInterface;

/**
 * Factory service to create a Guzzle client with Keystone authentication
 * support. This factory also deals with expiring tokens, by automatically
 * re-authenticating.
 *
 * Usage:
 *
 * <code>
 *     $client = $factory->createClient($tokenUrl, $username, $password);
 * </code>
 *
 * @deprecated The financial-media/keystone-client library is deprecated. Use treehouselabs/keystone-client instead
 */
class Factory implements EventSubscriberInterface
{
    private $cache;
    private $logger;

    /**
     * Constructor.
     *
     * @param CacheInterface  $cache
     * @param LoggerInterface $logger
     */
    public function __construct(CacheInterface $cache, LoggerInterface $logger = null)
    {
        $this->cache  = $cache;
        $this->logger = $logger;
    }

    /**
     * Creates a Guzzle client for communicating with a Keystone service. The
     * service type/name is used to select the right service from the token's
     * service catalog. If no name is given, the first service of the specified
     * type is used.
     *
     * @param  string           $tokenUrl    The url where to obtain a token
     * @param  string           $username    Username
     * @param  string           $password    Password
     * @param  string           $serviceType The type of service
     * @param  string           $serviceName Service name (optional)
     * @return Client
     */
    public function createClient($tokenUrl, $username, $password, $serviceType, $serviceName = null)
    {
        $client = new Client();
        $client->setDefaultOption('cache.key_filter', 'header=X-Auth-Token,X-Auth-Retries');
        $client->setTokenUrl($tokenUrl);
        $client->setKeystoneCredentials($username, $password);
        $client->setServiceType($serviceType);
        $client->setServiceName($serviceName);
        $client->getEventDispatcher()->addSubscriber($this);

        return $client;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            'client.initialize' => array('onInitialize'),
            'request.error' => array('onRequestError')
        );
    }

    public function onInitialize(Event $event)
    {
        $client = $event['client'];
        $token = $this->getToken($client);
        $client->setToken($token);
    }

    /**
     * Listener for request errors. Handles requests with expired
     * authentication, by reauthenticating and sending the request again.
     */
    public function onRequestError(Event $event)
    {
        // if token validity expired, re-request with a new token.
        if (in_array($event['response']->getStatusCode(), array(401, 403))) {
            /** @var \Guzzle\Http\Message\Request $request */
            $request = $event['request'];

            // if this is the token-url, stop now because we won't be able to fetch a token
            if ($request->getUrl() === $request->getClient()->getTokenUrl()) {
                return;
            }

            // see if we have retries left
            if ($request->hasHeader('X-Auth-Retries')) {
                $headerValues = $request->getHeader('X-Auth-Retries')->toArray();
                $retriesValue = array_shift($headerValues);
            }

            $retries = $request->hasHeader('X-Auth-Retries') ? $retriesValue : 1;
            if ($retries < 1) {
                if ($this->logger) {
                    $this->logger->addError('Keystone request failed, no more retries left');
                }

                return;
            }

            if ($this->logger) {
                $this->logger->addDebug('Token expired, fetching a new one');
            }

            // set new token in client
            $client = $request->getClient();
            $this->resetToken($client);

            // clone request and update token header
            $newRequest = clone $request;
            $newRequest->setHeader('X-Auth-Token', $client->getToken()->getId());
            $newRequest->setHeader('X-Auth-Retries', --$retries);
            $newResponse = $newRequest->send();

            // Set the response object of the request without firing more events
            $event['response'] = $newResponse;

            // Stop other events from firing when you override 401 responses
            $event->stopPropagation();
        }
    }

    protected function resetToken(Client $client)
    {
        $token = $this->getToken($client, true);
        $client->setToken($token);
    }

    /**
     * @return boolean
     */
    protected function tokenIsExpired(Token $token)
    {
        return new \DateTime() >= $token->getExpirationDate();
    }

    /**
     * Returns a token to use for the keystone service. Uses cached instance
     * whenever possible.
     *
     * @param  Client  $client   The client
     * @param  boolean $forceNew Whether to force creating a new token
     * @return Token
     */
    private function getToken(Client $client, $forceNew = false)
    {
        $tokenName = sprintf('keystone_token_%s', rawurlencode($client->getTokenUrl()));

        // see if token is in cache
        if (!$forceNew && $cachedToken = $this->cache->get($tokenName)) {
            $token = unserialize($cachedToken);
        }

        if (!isset($token) || !($token instanceof Token) || $this->tokenIsExpired($token)) {
            $token = $this->createToken($client);
            $this->cache->set($tokenName, serialize($token), $token->getExpirationDate()->getTimestamp() - time());
        }

        return $token;
    }

    /**
     * @throws RuntimeException when failed
     */
    protected function createToken(Client $client)
    {
        $data = array(
            'auth' => array(
                'passwordCredentials' => array(
                    'password' => $client->getKeystonePassword(),
                    'username' => $client->getKeystoneUsername()
                )
            )
        );

        if ($name = $client->getTenantName()) {
            $data['auth']['tenantName'] = $name;
        }

        // make sure token isn't sent in request
        $request = $client->post($client->getTokenUrl());
        $request->removeHeader('X-Auth-Token');
        $request->setBody(json_encode($data), 'application/json');

        $response = $request->send();
        $content = $response->json();

        $token = new Token($content['access']['token']['id'], new \DateTime($content['access']['token']['expires']));

        foreach ($content['access']['serviceCatalog'] as $catalog) {
            $token->addServiceCatalog($catalog['type'], $catalog['name'], $catalog['endpoints'][0]);
        }

        return $token;
    }
}
