<?php
/**
 * @see       https://github.com/zendframework/zend-http for the canonical source repository
 * @copyright Copyright (c) 2005-2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-http/blob/master/LICENSE.md New BSD License
 */

namespace Zend\Http;

use ArrayIterator;
use Traversable;
use Zend\Http\Client\Adapter\Curl;
use Zend\Http\Client\Adapter\Socket;
use Zend\Http\Client\Adapter\StreamInterface;
use Zend\Http\Exception\InvalidArgumentException;
use Zend\Http\Exception\RuntimeException;
use Zend\Http\Exception\UnexpectedValueException;
use Zend\Http\Header\HeaderInterface;
use Zend\Http\Header\SetCookie;
use Zend\Stdlib;
use Zend\Stdlib\ArrayUtils;
use Zend\Stdlib\ErrorHandler;
use Zend\Uri\Http;

/**
 * Http client
 */
class Client implements Stdlib\DispatchableInterface
{
    /**
     * @const string Supported HTTP Authentication methods
     */
    const AUTH_BASIC  = 'basic';
    const AUTH_DIGEST = 'digest';

    /**
     * @const string POST data encoding methods
     */
    const ENC_URLENCODED = 'application/x-www-form-urlencoded';
    const ENC_FORMDATA   = 'multipart/form-data';

    /**
     * @const string DIGEST Authentication
     */
    const DIGEST_REALM  = 'realm';
    const DIGEST_QOP    = 'qop';
    const DIGEST_NONCE  = 'nonce';
    const DIGEST_OPAQUE = 'opaque';
    const DIGEST_NC     = 'nc';
    const DIGEST_CNONCE = 'cnonce';

    /**
     * @var null|Response
     */
    protected $response;

    /**
     * @var null|Request
     */
    protected $request;

    /**
     * @var null|Client\Adapter\AdapterInterface
     */
    protected $adapter;

    /**
     * @var array
     */
    protected $auth = [];

    /**
     * @var null|string|resource
     */
    protected $streamName;

    /**
     * @var resource|null
     */
    protected $streamHandle;

    /**
     * @var array of Header\SetCookie
     */
    protected $cookies = [];

    /**
     * @var string
     */
    protected $encType = '';

    /**
     * @var null|string
     */
    protected $lastRawRequest;

    /**
     * @var null|string
     */
    protected $lastRawResponse;

    /**
     * @var int
     */
    protected $redirectCounter = 0;

    /**
     * Configuration array, set using the constructor or using ::setOptions()
     *
     * @var array
     */
    protected $config = [
        'maxredirects'    => 5,
        'strictredirects' => false,
        'useragent'       => Client::class,
        'timeout'         => 10,
        'connecttimeout'  => null,
        'adapter'         => Socket::class,
        'httpversion'     => Request::VERSION_11,
        'storeresponse'   => true,
        'keepalive'       => false,
        'outputstream'    => false,
        'encodecookies'   => true,
        'argseparator'    => null,
        'rfc3986strict'   => false,
        'sslcafile'       => null,
        'sslcapath'       => null,
    ];

    /**
     * Fileinfo magic database resource
     *
     * This variable is populated the first time _detectFileMimeType is called
     * and is then reused on every call to this method
     *
     * @var null|resource
     */
    protected static $fileInfoDb;

    /**
     * Constructor
     *
     * @param null|string $uri
     * @param null|array|Traversable $options
     */
    public function __construct($uri = null, $options = null)
    {
        if ($uri !== null) {
            $this->setUri($uri);
        }
        if ($options !== null) {
            $this->setOptions($options);
        }
    }

    /**
     * Set configuration parameters for this HTTP client
     *
     * @param  array|Traversable $options
     * @return $this
     * @throws Client\Exception\InvalidArgumentException
     */
    public function setOptions($options = [])
    {
        if ($options instanceof Traversable) {
            $options = ArrayUtils::iteratorToArray($options);
        }
        if (! is_array($options)) {
            throw new Client\Exception\InvalidArgumentException('Config parameter is not valid');
        }

        /** Config Key Normalization */
        foreach ($options as $k => $v) {
            $this->config[str_replace(['-', '_', ' ', '.'], '', strtolower($k))] = $v; // replace w/ normalized
        }

        // Pass configuration options to the adapter if it exists
        $this->getAdapter()->setOptions($options);

        return $this;
    }

    /**
     * Load the connection adapter
     *
     * While this method is not called more than one for a client, it is
     * separated from ->request() to preserve logic and readability
     *
     * @param  Client\Adapter\AdapterInterface|string $adapter
     * @return $this
     * @throws Client\Exception\InvalidArgumentException
     */
    public function setAdapter($adapter)
    {
        if (is_string($adapter)) {
            if (! class_exists($adapter)) {
                throw new Client\Exception\InvalidArgumentException(
                    'Unable to locate adapter class "' . $adapter . '"'
                );
            }
            $adapter = new $adapter;
        }

        if (! $adapter instanceof Client\Adapter\AdapterInterface) {
            throw new Client\Exception\InvalidArgumentException('Passed adapter is not a HTTP connection adapter');
        }

        $this->adapter = $adapter;

        $config = $this->config;
        unset($config['adapter']);

        $adapter->setOptions($config);

        return $this;
    }

    /**
     * Load the connection adapter
     *
     * @return Client\Adapter\AdapterInterface
     */
    public function getAdapter()
    {
        if (! $this->adapter) {
            $this->setAdapter($this->config['adapter']);
        }

        /** @var Client\Adapter\AdapterInterface $adapter */
        $adapter = $this->adapter;

        return $adapter;
    }

    /**
     * Set request
     *
     * @param Request $request
     * @return $this
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;
        return $this;
    }

    /**
     * Get Request
     *
     * @return Request
     */
    public function getRequest()
    {
        if (empty($this->request)) {
            $this->request = new Request();
            $this->request->setAllowCustomMethods(false);
        }
        return $this->request;
    }

    /**
     * Set response
     *
     * @param Response $response
     * @return $this
     */
    public function setResponse(Response $response)
    {
        $this->response = $response;
        return $this;
    }

    /**
     * Get Response
     *
     * @return Response
     */
    public function getResponse()
    {
        if (empty($this->response)) {
            $this->response = new Response();
        }
        return $this->response;
    }

    /**
     * Get the last request (as a string)
     *
     * @return null|string
     */
    public function getLastRawRequest()
    {
        return $this->lastRawRequest;
    }

    /**
     * Get the last response (as a string)
     *
     * @return null|string
     */
    public function getLastRawResponse()
    {
        return $this->lastRawResponse;
    }

    /**
     * Get the redirections count
     *
     * @return int
     */
    public function getRedirectionsCount()
    {
        return $this->redirectCounter;
    }

    /**
     * Set Uri (to the request)
     *
     * @param string|Http $uri
     * @return $this
     */
    public function setUri($uri)
    {
        if (! empty($uri)) {
            // remember host of last request
            $lastHost = $this->getRequest()->getUri()->getHost() ?: '';
            $this->getRequest()->setUri($uri);

            // if host changed, the HTTP authentication should be cleared for security
            // reasons, see #4215 for a discussion - currently authentication is also
            // cleared for peer subdomains due to technical limits
            $nextHost = $this->getRequest()->getUri()->getHost();

            if (! $nextHost) {
                throw new InvalidArgumentException('Relative URIs are not allowed');
            }

            if (! preg_match('/' . preg_quote($lastHost, '/') . '$/i', $nextHost)) {
                $this->clearAuth();
            }

            $uri = $this->getUri();
            $user = $uri->getUser();
            $password = $uri->getPassword();

            // Set auth if username and password has been specified in the uri
            if ($user && $password) {
                $this->setAuth($user, $password);
            }

            // We have no ports, set the defaults
            if (! $uri->getPort() && $uri->isAbsolute()) {
                $uri->setPort($uri->getScheme() === 'https' ? 443 : 80);
            }
        }
        return $this;
    }

    /**
     * Get uri (from the request)
     *
     * @return Http
     */
    public function getUri()
    {
        return $this->getRequest()->getUri();
    }

    /**
     * Set the HTTP method (to the request)
     *
     * @param string $method
     * @return $this
     */
    public function setMethod($method)
    {
        $method = $this->getRequest()->setMethod($method)->getMethod();

        if (empty($this->encType)
            && in_array(
                $method,
                [
                    Request::METHOD_POST,
                    Request::METHOD_PUT,
                    Request::METHOD_DELETE,
                    Request::METHOD_PATCH,
                    Request::METHOD_OPTIONS,
                ],
                true
            )
        ) {
            $this->setEncType(self::ENC_URLENCODED);
        }

        return $this;
    }

    /**
     * Get the HTTP method
     *
     * @return string
     */
    public function getMethod()
    {
        return $this->getRequest()->getMethod();
    }

    /**
     * Set the query string argument separator
     *
     * @param string $argSeparator
     * @return $this
     */
    public function setArgSeparator($argSeparator)
    {
        $this->setOptions(['argseparator' => $argSeparator]);
        return $this;
    }

    /**
     * Get the query string argument separator
     *
     * @return string
     */
    public function getArgSeparator()
    {
        $argSeparator = $this->config['argseparator'];
        if (empty($argSeparator)) {
            $argSeparator = ini_get('arg_separator.output') ?: '&';
            $this->setArgSeparator($argSeparator);
        }
        return $argSeparator;
    }

    /**
     * Set the encoding type and the boundary (if any)
     *
     * @param null|string $encType
     * @param null|string $boundary
     * @return $this
     */
    public function setEncType($encType, $boundary = null)
    {
        if (null === $encType || empty($encType)) {
            $this->encType = '';
            return $this;
        }

        if (! empty($boundary)) {
            $encType .= sprintf('; boundary=%s', $boundary);
        }

        $this->encType = $encType;
        return $this;
    }

    /**
     * Get the encoding type
     *
     * @return string
     */
    public function getEncType()
    {
        return $this->encType;
    }

    /**
     * Set raw body (for advanced use cases)
     *
     * @param string $body
     * @return $this
     */
    public function setRawBody($body)
    {
        $this->getRequest()->setContent($body);
        return $this;
    }

    /**
     * Set the POST parameters
     *
     * @param array $post
     * @return $this
     */
    public function setParameterPost(array $post)
    {
        $this->getRequest()->getPost()->fromArray($post);
        return $this;
    }

    /**
     * Set the GET parameters
     *
     * @param array $query
     * @return $this
     */
    public function setParameterGet(array $query)
    {
        $this->getRequest()->getQuery()->fromArray($query);
        return $this;
    }

    /**
     * Reset all the HTTP parameters (request, response, etc)
     *
     * @param  bool   $clearCookies  Also clear all valid cookies? (defaults to false)
     * @return $this
     */
    public function resetParameters($clearCookies = false)
    {
        $clearAuth = true;
        if (func_num_args() > 1) {
            $clearAuth = func_get_arg(1);
        }

        $uri = $this->getUri();

        $this->streamName      = null;
        $this->encType         = '';
        $this->request         = null;
        $this->response        = null;
        $this->lastRawRequest  = null;
        $this->lastRawResponse = null;

        $this->setUri($uri);

        if ($clearCookies) {
            $this->clearCookies();
        }

        if ($clearAuth) {
            $this->clearAuth();
        }

        return $this;
    }

    /**
     * Return the current cookies
     *
     * @return array
     */
    public function getCookies()
    {
        return $this->cookies;
    }

    /**
     * Get the cookie Id (name+domain+path)
     *
     * @param  Header\SetCookie $cookie
     * @return string|bool
     */
    protected function getCookieId($cookie)
    {
        if ($cookie instanceof Header\SetCookie) {
            return $cookie->getName() . $cookie->getDomain() . $cookie->getPath();
        }
        return false;
    }

    /**
     * Add a cookie
     *
     * @param array|ArrayIterator|Header\SetCookie|string $cookie
     * @param null|string  $value
     * @param null|string  $expire
     * @param null|string  $path
     * @param null|string  $domain
     * @param bool $secure
     * @param bool $httponly
     * @param null|int  $maxAge
     * @param null|int  $version
     * @throws Exception\InvalidArgumentException
     * @return $this
     */
    public function addCookie(
        $cookie,
        $value = null,
        $expire = null,
        $path = null,
        $domain = null,
        $secure = false,
        $httponly = true,
        $maxAge = null,
        $version = null
    ) {
        if (is_array($cookie) || $cookie instanceof ArrayIterator) {
            foreach ($cookie as $setCookie) {
                if ($setCookie instanceof Header\SetCookie) {
                    $this->cookies[$this->getCookieId($setCookie)] = $setCookie;
                } else {
                    throw new Exception\InvalidArgumentException('The cookie parameter is not a valid Set-Cookie type');
                }
            }
        } elseif (is_string($cookie) && $value !== null) {
            $setCookie = new Header\SetCookie(
                $cookie,
                $value,
                $expire,
                $path,
                $domain,
                $secure,
                $httponly,
                $maxAge,
                $version
            );
            $this->cookies[$this->getCookieId($setCookie)] = $setCookie;
        } elseif ($cookie instanceof Header\SetCookie) {
            $this->cookies[$this->getCookieId($cookie)] = $cookie;
        } else {
            throw new Exception\InvalidArgumentException('Invalid parameter type passed as Cookie');
        }
        return $this;
    }

    /**
     * Set an array of cookies
     *
     * @param  array|SetCookie[] $cookies Cookies as name=>value pairs or instances of SetCookie.
     * @throws Exception\InvalidArgumentException
     * @return $this
     */
    public function setCookies($cookies)
    {
        if (is_array($cookies)) {
            $this->clearCookies();
            foreach ($cookies as $name => $value) {
                if ($value instanceof SetCookie) {
                    $this->addCookie($value);
                } else {
                    $this->addCookie($name, $value);
                }
            }
        } else {
            throw new Exception\InvalidArgumentException('Invalid cookies passed as parameter, it must be an array');
        }
        return $this;
    }

    /**
     * Clear all the cookies
     */
    public function clearCookies()
    {
        $this->cookies = [];
    }

    /**
     * Set the headers (for the request)
     *
     * @param  Headers|array $headers
     * @throws Exception\InvalidArgumentException
     * @return $this
     */
    public function setHeaders($headers)
    {
        if (is_array($headers)) {
            $newHeaders = new Headers();
            $newHeaders->addHeaders($headers);
            $this->getRequest()->setHeaders($newHeaders);
        } elseif ($headers instanceof Headers) {
            $this->getRequest()->setHeaders($headers);
        } else {
            throw new Exception\InvalidArgumentException('Invalid parameter headers passed');
        }
        return $this;
    }

    /**
     * Check if exists the header type specified
     *
     * @param  string $name
     * @return bool
     */
    public function hasHeader($name)
    {
        $headers = $this->getRequest()->getHeaders();

        if ($headers instanceof Headers) {
            return $headers->has($name);
        }

        return false;
    }

    /**
     * Get the header value of the request
     *
     * @param  string $name
     * @return string|bool
     */
    public function getHeader($name)
    {
        $headers = $this->getRequest()->getHeaders();

        if (! $headers instanceof Headers) {
            return false;
        }

        $header = $headers->get($name);
        if (! $header instanceof HeaderInterface) {
            return false;
        }

        return $header->getFieldValue();
    }

    /**
     * Set streaming for received data
     *
     * @param string|bool $streamfile Stream file, true for temp file, false/null for no streaming
     * @return $this
     */
    public function setStream($streamfile = true)
    {
        $this->setOptions(['outputstream' => $streamfile]);
        return $this;
    }

    /**
     * Get status of streaming for received data
     * @return bool|resource|string
     */
    public function getStream()
    {
        if (null !== $this->streamName) {
            return $this->streamName;
        }

        return $this->config['outputstream'];
    }

    /**
     * Create temporary stream
     *
     * @return resource
     * @throws Exception\RuntimeException
     */
    protected function openTempStream()
    {
        $this->streamName = $this->config['outputstream'];

        if (! is_string($this->streamName)) {
            // If name is not given, create temp name
            $this->streamName = tempnam(
                isset($this->config['streamtmpdir']) ? $this->config['streamtmpdir'] : sys_get_temp_dir(),
                Client::class
            ) ?: null;

            if (null === $this->streamName) {
                throw new RuntimeException('Unable to create temporary name for stream');
            }
        }

        ErrorHandler::start();
        $fp    = fopen($this->streamName, 'w+b');
        $error = ErrorHandler::stop();
        if (false === $fp) {
            if ($this->adapter instanceof Client\Adapter\AdapterInterface) {
                $this->adapter->close();
            }
            throw new Exception\RuntimeException(sprintf('Could not open temp file %s', $this->streamName), 0, $error);
        }

        return $fp;
    }

    /**
     * Create a HTTP authentication "Authorization:" header according to the
     * specified user, password and authentication method.
     *
     * @param string $user
     * @param string $password
     * @param string $type
     * @throws Exception\InvalidArgumentException
     * @return $this
     */
    public function setAuth($user, $password, $type = self::AUTH_BASIC)
    {
        if (! defined('static::AUTH_' . strtoupper($type))) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Invalid or not supported authentication type: \'%s\'',
                $type
            ));
        }

        if (empty($user)) {
            throw new Exception\InvalidArgumentException('The username cannot be empty');
        }

        $this->auth = [
            'user'     => $user,
            'password' => $password,
            'type'     => $type,
        ];

        return $this;
    }

    /**
     * Clear http authentication
     */
    public function clearAuth()
    {
        $this->auth = [];
    }

    /**
     * Calculate the response value according to the HTTP authentication type
     *
     * @see http://www.faqs.org/rfcs/rfc2617.html
     * @param string $user
     * @param string $password
     * @param string $type
     * @param string[] $digest
     * @param null|string $entityBody
     * @throws Exception\InvalidArgumentException
     * @return string|bool
     */
    protected function calcAuthDigest($user, $password, $type = self::AUTH_BASIC, $digest = [], $entityBody = null)
    {
        if (! defined('self::AUTH_' . strtoupper($type))) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Invalid or not supported authentication type: \'%s\'',
                $type
            ));
        }
        $response = false;
        switch (strtolower($type)) {
            case self::AUTH_BASIC:
                // In basic authentication, the user name cannot contain ":"
                if (strpos($user, ':') !== false) {
                    throw new Exception\InvalidArgumentException(
                        'The user name cannot contain \':\' in Basic HTTP authentication'
                    );
                }
                $response = base64_encode($user . ':' . $password);
                break;
            case self::AUTH_DIGEST:
                if (empty($digest)) {
                    throw new Exception\InvalidArgumentException('The digest cannot be empty');
                }
                foreach ($digest as $key => $value) {
                    if (! defined('self::DIGEST_' . strtoupper((string) $key))) {
                        throw new Exception\InvalidArgumentException(sprintf(
                            'Invalid or not supported digest authentication parameter: \'%s\'',
                            $key
                        ));
                    }
                }
                $ha1 = md5($user . ':' . $digest['realm'] . ':' . $password);
                if (empty($digest['qop']) || strtolower($digest['qop']) == 'auth') {
                    $ha2 = md5($this->getMethod() . ':' . $this->getUri()->getPath());
                } elseif (strtolower($digest['qop']) == 'auth-int') {
                    if (empty($entityBody)) {
                        throw new Exception\InvalidArgumentException(
                            'I cannot use the auth-int digest authentication without the entity body'
                        );
                    }
                    $ha2 = md5($this->getMethod() . ':' . $this->getUri()->getPath() . ':' . md5($entityBody));
                } else {
                    throw new InvalidArgumentException('Invalid DIGEST auth data');
                }
                if (empty($digest['qop'])) {
                    $response = md5($ha1 . ':' . $digest['nonce'] . ':' . $ha2);
                } else {
                    $response = md5($ha1 . ':' . $digest['nonce'] . ':' . $digest['nc']
                                    . ':' . $digest['cnonce'] . ':' . $digest['qoc'] . ':' . $ha2);
                }
                break;
        }
        return $response;
    }

    /**
     * Dispatch
     *
     * @param Stdlib\RequestInterface $request
     * @param Stdlib\ResponseInterface $response
     * @throws UnexpectedValueException on $request not an instance of Zend\http\Request
     * @return Stdlib\ResponseInterface
     */
    public function dispatch(Stdlib\RequestInterface $request, Stdlib\ResponseInterface $response = null)
    {
        if (! $request instanceof Request) {
            throw UnexpectedValueException::unexpectedType(Request::class, $request);
        }

        return $this->send($request);
    }

    /**
     * Send HTTP request
     *
     * @param  Request|null $request
     * @return Response
     * @throws Exception\RuntimeException
     * @throws Client\Exception\RuntimeException
     */
    public function send(Request $request = null)
    {
        if ($request !== null) {
            $this->setRequest($request);
        }

        $this->redirectCounter = 0;

        $adapter = $this->getAdapter();

        // Send the first request. If redirected, continue.
        do {
            // uri
            $uri = $this->getUri();

            // query
            $query = $this->getRequest()->getQuery();

            if (! empty($query)) {
                $queryArray = $query->toArray();

                if (! empty($queryArray)) {
                    $newUri = $uri->toString();
                    $queryString = http_build_query($queryArray, '', $this->getArgSeparator());

                    if ($this->config['rfc3986strict']) {
                        $queryString = str_replace('+', '%20', $queryString);
                    }

                    if (strpos($newUri, '?') !== false) {
                        $newUri .= $this->getArgSeparator() . $queryString;
                    } else {
                        $newUri .= '?' . $queryString;
                    }

                    $uri = new Http($newUri);
                }
            }
            // If we have no ports, set the defaults
            if (! $uri->getPort() && $uri->isAbsolute()) {
                $uri->setPort($uri->getScheme() === 'https' ? 443 : 80);
            }

            // method
            $method = $this->getRequest()->getMethod();

            // this is so the correct Encoding Type is set
            $this->setMethod($method);

            // body
            $body = $this->prepareBody();

            // headers
            $headers = $this->prepareHeaders($body, $uri);

            $secure = $uri->getScheme() === 'https';

            if (null === $uri->getHost()) {
                throw new InvalidArgumentException('Invalid URI in request');
            }

            // cookies
            $cookie = $this->prepareCookies($uri->getHost(), $uri->getPath(), $secure);
            if ($cookie->getFieldValue()) {
                $headers['Cookie'] = $cookie->getFieldValue();
            }

            $this->streamHandle = null;
            // calling protected method to allow extending classes
            // to wrap the interaction with the adapter
            $response = $this->doRequest($uri, $method, $secure, $headers, $body);
            /** @var null|resource $stream */
            $stream = $this->streamHandle;
            $this->streamHandle = null;

            if (! $response) {
                if ($stream !== null) {
                    fclose($stream);
                }
                throw new Exception\RuntimeException('Unable to read response, or response is empty');
            }

            if ($this->config['storeresponse']) {
                $this->lastRawResponse = $response;
            } else {
                $this->lastRawResponse = null;
            }

            if ($this->config['outputstream']) {
                if (! $adapter instanceof StreamInterface) {
                    throw new Client\Exception\RuntimeException('Adapter does not support streaming');
                }

                if ($stream === null) {
                    // @todo: check if it's really used
                    $stream = $this->getStream();
                    if (! is_resource($stream) && is_string($stream)) {
                        $stream = fopen($stream, 'rb');
                    }
                }

                if (! is_resource($stream)) {
                    throw new UnexpectedValueException('Stream is not a resource');
                }

                $streamMetaData = stream_get_meta_data($stream);
                if ($streamMetaData['seekable']) {
                    rewind($stream);
                }

                // cleanup the adapter
                $adapter->setOutputStream(null);
                $response = Response\Stream::fromStream($response, $stream);

                // streamName can just be a string at this point
                if (! is_string($this->streamName)) {
                    throw new UnexpectedValueException('Unexpected value for streamName');
                }

                $response->setStreamName($this->streamName);
                if (! is_string($this->config['outputstream'])) {
                    // we used temp name, will need to clean up
                    $response->setCleanup(true);
                }
            } else {
                $response = $this->getResponse()->fromString($response);
            }

            // Get the cookies from response (if any)
            $setCookies = $response->getCookie();
            if (! is_bool($setCookies) && ! empty($setCookies)) {
                $this->addCookie($setCookies);
            }

            /** @var Headers $responseHeaders */
            $responseHeaders = $response->getHeaders();

            // If we got redirected, look for the Location header
            if ($response->isRedirect() && ($responseHeaders->has('Location'))) {
                // Avoid problems with buggy servers that add whitespace at the
                // end of some headers
                /** @var HeaderInterface $locationHeader */
                $locationHeader = $responseHeaders->get('Location');
                $location = trim($locationHeader->getFieldValue());

                // Check whether we send the exact same request again, or drop the parameters
                // and send a GET request
                if ($response->getStatusCode() == 303
                    || ((! $this->config['strictredirects'])
                        && ($response->getStatusCode() == 302 || $response->getStatusCode() == 301))
                ) {
                    $this->resetParameters(false, false);
                    $this->setMethod(Request::METHOD_GET);
                }

                // If we got a well formed absolute URI
                if (($scheme = substr($location, 0, 6))
                    && ($scheme == 'http:/' || $scheme == 'https:')
                ) {
                    // setURI() clears parameters if host changed, see #4215
                    $this->setUri($location);
                } else {
                    // Split into path and query and set the query
                    if (strpos($location, '?') !== false) {
                        list($location, $query) = explode('?', $location, 2);
                    } else {
                        $query = '';
                    }
                    $this->getUri()->setQuery($query);

                    // Else, if we got just an absolute path, set it
                    if (strpos($location, '/') === 0) {
                        $this->getUri()->setPath($location);
                        // Else, assume we have a relative path
                    } else {
                        // Get the current path directory, removing any trailing slashes
                        $path = $this->getUri()->getPath() ?: '/';
                        $slashPosition = strrpos($path, '/') ?: 0;
                        $path = rtrim(substr($path, 0, $slashPosition) ?: '', '/');
                        $this->getUri()->setPath($path . '/' . $location);
                    }
                }
                ++$this->redirectCounter;
            } else {
                // If we didn't get any location, stop redirecting
                break;
            }
        } while ($this->redirectCounter <= $this->config['maxredirects']);

        $this->response = $response;
        return $response;
    }

    /**
     * Fully reset the HTTP client (auth, cookies, request, response, etc.)
     *
     * @return $this
     */
    public function reset()
    {
        $this->resetParameters();
        $this->clearAuth();
        $this->clearCookies();

        return $this;
    }

    /**
     * Set a file to upload (using a POST request)
     *
     * Can be used in two ways:
     *
     * 1. $data is null (default): $filename is treated as the name if a local file which
     * will be read and sent. Will try to guess the content type using mime_content_type().
     * 2. $data is set - $filename is sent as the file name, but $data is sent as the file
     * contents and no file is read from the file system. In this case, you need to
     * manually set the Content-Type ($ctype) or it will default to
     * application/octet-stream.
     *
     * @param  string $filename Name of file to upload, or name to save as
     * @param  string $formname Name of form element to send as
     * @param  string $data Data to send (if null, $filename is read and sent)
     * @param  string $ctype Content type to use (if $data is set and $ctype is
     *                null, will be application/octet-stream)
     * @return $this
     * @throws Exception\RuntimeException
     */
    public function setFileUpload($filename, $formname, $data = null, $ctype = null)
    {
        if ($data === null) {
            ErrorHandler::start();
            $data  = file_get_contents($filename);
            $error = ErrorHandler::stop();
            if ($data === false) {
                throw new Exception\RuntimeException(sprintf(
                    'Unable to read file \'%s\' for upload',
                    $filename
                ), 0, $error);
            }
            if (! $ctype) {
                $ctype = $this->detectFileMimeType($filename);
            }
        }

        $this->getRequest()->getFiles()->set($filename, [
            'formname' => $formname,
            'filename' => basename($filename),
            'ctype' => $ctype,
            'data' => $data,
        ]);

        return $this;
    }

    /**
     * Remove a file to upload
     *
     * @param  string $filename
     * @return bool
     */
    public function removeFileUpload($filename)
    {
        $file = $this->getRequest()->getFiles()->get($filename);
        if (! empty($file)) {
            $this->getRequest()->getFiles()->set($filename, null);
            return true;
        }
        return false;
    }

    /**
     * Prepare Cookies
     *
     * @param   null|string $domain
     * @param   null|string $path
     * @param   bool $secure
     * @return  Header\Cookie
     */
    protected function prepareCookies($domain, $path, $secure)
    {
        $validCookies = [];

        if (! empty($this->cookies)) {
            foreach ($this->cookies as $id => $cookie) {
                if ($cookie->isExpired()) {
                    unset($this->cookies[$id]);
                    continue;
                }

                if ($cookie->isValidForRequest($domain, $path, $secure)) {
                    // OAM hack some domains try to set the cookie multiple times
                    $validCookies[$cookie->getName()] = $cookie;
                }
            }
        }

        $cookies = Header\Cookie::fromSetCookieArray($validCookies);
        $cookies->setEncodeValue($this->config['encodecookies']);

        return $cookies;
    }

    /**
     * Prepare the request headers
     *
     * @param resource|string $body
     * @param Http $uri
     * @return array
     * @throws Exception\RuntimeException
     */
    protected function prepareHeaders($body, $uri)
    {
        $headers = [];

        $adapter = $this->getAdapter();

        /** @var Headers|HeaderInterface[] $requestHeaders */
        $requestHeaders = $this->getRequest()->getHeaders();

        // Set the host header
        if ($this->config['httpversion'] == Request::VERSION_11) {
            $host = $uri->getHost();
            // If the port is not default, add it
            if (! (($uri->getScheme() == 'http' && $uri->getPort() == 80)
                || ($uri->getScheme() == 'https' && $uri->getPort() == 443))
            ) {
                $host .= ':' . $uri->getPort();
            }

            $headers['Host'] = $host;
        }

        // Set the connection header
        if (! $requestHeaders->has('Connection')) {
            if (! $this->config['keepalive']) {
                $headers['Connection'] = 'close';
            }
        }

        // Set the Accept-encoding header if not set - depending on whether
        // zlib is available or not.
        if (! $requestHeaders->has('Accept-Encoding')) {
            if (empty($this->config['outputstream']) && function_exists('gzinflate')) {
                $headers['Accept-Encoding'] = 'gzip, deflate';
            } else {
                $headers['Accept-Encoding'] = 'identity';
            }
        }

        // Set the user agent header
        if (! $requestHeaders->has('User-Agent') && isset($this->config['useragent'])) {
            $headers['User-Agent'] = $this->config['useragent'];
        }

        // Set HTTP authentication if needed
        if (! empty($this->auth)) {
            switch ($this->auth['type']) {
                case self::AUTH_BASIC:
                    $auth = $this->calcAuthDigest($this->auth['user'], $this->auth['password'], $this->auth['type']);
                    if ($auth !== false) {
                        $headers['Authorization'] = 'Basic ' . $auth;
                    }
                    break;
                case self::AUTH_DIGEST:
                    if (! $adapter instanceof Client\Adapter\Curl) {
                        throw new Exception\RuntimeException(sprintf(
                            'The digest authentication is only available for curl adapters (%s)',
                            Curl::class
                        ));
                    }

                    $adapter->setCurlOption(CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
                    $adapter->setCurlOption(CURLOPT_USERPWD, $this->auth['user'] . ':' . $this->auth['password']);
            }
        }

        // Content-type
        $encType = $this->getEncType();
        if (! empty($encType)) {
            $headers['Content-Type'] = $encType;
        }

        if (! empty($body)) {
            if (is_resource($body)) {
                $fstat = fstat($body);
                $headers['Content-Length'] = $fstat['size'];
            } else {
                $headers['Content-Length'] = strlen($body);
            }
        }

        // Merge the headers of the request (if any)
        // here we need right 'http field' and not lowercase letters
        foreach ($requestHeaders as $requestHeaderElement) {
            $headers[$requestHeaderElement->getFieldName()] = $requestHeaderElement->getFieldValue();
        }
        return $headers;
    }

    /**
     * Prepare the request body (for PATCH, POST and PUT requests)
     *
     * @return string
     * @throws \Zend\Http\Client\Exception\RuntimeException
     */
    protected function prepareBody()
    {
        // According to RFC2616, a TRACE request should not have a body.
        if ($this->getRequest()->isTrace()) {
            return '';
        }

        $rawBody = $this->getRequest()->getContent();
        if (! empty($rawBody)) {
            return $rawBody;
        }

        $body = '';
        $hasFiles = false;

        /** @var Headers $headers */
        $headers = $this->getRequest()->getHeaders();

        if (! $headers->has('Content-Type')) {
            $hasFiles = ! empty($this->getRequest()->getFiles()->toArray());
            // If we have files to upload, force encType to multipart/form-data
            if ($hasFiles) {
                $this->setEncType(self::ENC_FORMDATA);
            }
        } else {
            $contentType = $this->getHeader('Content-Type');
            $this->setEncType(is_string($contentType) ? $contentType : '');
        }

        // If we have POST parameters or files, encode and add them to the body
        if (! empty($this->getRequest()->getPost()->toArray()) || $hasFiles) {
            if (stripos($this->getEncType(), self::ENC_FORMDATA) === 0) {
                $boundary = '---ZENDHTTPCLIENT-' . md5(microtime());
                $this->setEncType(self::ENC_FORMDATA, $boundary);

                // Get POST parameters and encode them
                $params = self::flattenParametersArray($this->getRequest()->getPost()->toArray());
                foreach ($params as $pp) {
                    $body .= $this->encodeFormData($boundary, $pp[0], $pp[1]);
                }

                // Encode files
                foreach ($this->getRequest()->getFiles()->toArray() as $file) {
                    $fhead = ['Content-Type' => $file['ctype']];
                    $body .= $this->encodeFormData(
                        $boundary,
                        $file['formname'],
                        $file['data'],
                        $file['filename'],
                        $fhead
                    );
                }
                $body .= '--' . $boundary . '--' . "\r\n";
            } elseif (stripos($this->getEncType(), self::ENC_URLENCODED) === 0) {
                // Encode body as application/x-www-form-urlencoded
                $body = http_build_query($this->getRequest()->getPost()->toArray(), '', '&');
            } else {
                throw new Client\Exception\RuntimeException(sprintf(
                    'Cannot handle content type \'%s\' automatically',
                    $this->encType
                ));
            }
        }

        return $body;
    }


    /**
     * Attempt to detect the MIME type of a file using available extensions
     *
     * This method will try to detect the MIME type of a file. If the fileinfo
     * extension is available, it will be used. If not, the mime_magic
     * extension which is deprecated but is still available in many PHP setups
     * will be tried.
     *
     * If neither extension is available, the default application/octet-stream
     * MIME type will be returned
     *
     * @param string $file File path
     * @return string MIME type
     */
    protected function detectFileMimeType($file)
    {
        $type = null;

        // First try with fileinfo functions
        if (function_exists('finfo_open')) {
            $fileInfoDb = static::$fileInfoDb;
            if (static::$fileInfoDb === null) {
                ErrorHandler::start();
                $fileInfoDb = finfo_open(FILEINFO_MIME);
                ErrorHandler::stop();
            }

            if ($fileInfoDb) {
                static::$fileInfoDb = $fileInfoDb;
                $type = finfo_file($fileInfoDb, $file);
            }
        } elseif (function_exists('mime_content_type')) {
            $type = mime_content_type($file);
        }

        // Fallback to the default application/octet-stream
        if (! $type) {
            $type = 'application/octet-stream';
        }

        return $type;
    }

    /**
     * Encode data to a multipart/form-data part suitable for a POST request.
     *
     * @param string $boundary
     * @param string $name
     * @param mixed $value
     * @param string $filename
     * @param array $headers Associative array of optional headers @example ("Content-Transfer-Encoding" => "binary")
     * @return string
     */
    public function encodeFormData($boundary, $name, $value, $filename = null, $headers = [])
    {
        $ret = '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . $name . '"';

        if ($filename) {
            $ret .= '; filename="' . $filename . '"';
        }
        $ret .= "\r\n";

        foreach ($headers as $hname => $hvalue) {
            $ret .= $hname . ': ' . $hvalue . "\r\n";
        }
        $ret .= "\r\n";
        $ret .= $value . "\r\n";

        return $ret;
    }

    /**
     * Convert an array of parameters into a flat array of (key, value) pairs
     *
     * Will flatten a potentially multi-dimentional array of parameters (such
     * as POST parameters) into a flat array of (key, value) paris. In case
     * of multi-dimentional arrays, square brackets ([]) will be added to the
     * key to indicate an array.
     *
     * @since 1.9
     *
     * @param array $parray
     * @param string $prefix
     * @return array
     */
    protected function flattenParametersArray($parray, $prefix = null)
    {
        if (! is_array($parray)) {
            return $parray;
        }

        $parameters = [];

        foreach ($parray as $name => $value) {
            // Calculate array key
            if ($prefix) {
                if (is_int($name)) {
                    $key = $prefix . '[]';
                } else {
                    $key = $prefix . sprintf('[%s]', $name);
                }
            } else {
                $key = (string) $name;
            }

            if (is_array($value)) {
                $parameters = array_merge($parameters, $this->flattenParametersArray($value, $key));
            } else {
                $parameters[] = [$key, $value];
            }
        }

        return $parameters;
    }

    /**
     * Separating this from send method allows subclasses to wrap
     * the interaction with the adapter
     *
     * @param Http $uri
     * @param string $method
     * @param  bool $secure
     * @param array $headers
     * @param string $body
     * @return string the raw response
     * @throws Exception\RuntimeException
     */
    protected function doRequest(Http $uri, $method, $secure = false, $headers = [], $body = '')
    {
        if (null === $uri->getHost()) {
            throw new InvalidArgumentException('URI does not have an host');
        }

        $adapter = $this->getAdapter();

        // Open the connection, send the request and read the response
        $adapter->connect($uri->getHost(), $uri->getPort(), $secure);

        if ($this->config['outputstream']) {
            if ($adapter instanceof Client\Adapter\StreamInterface) {
                $this->streamHandle = $this->openTempStream();
                $adapter->setOutputStream($this->streamHandle);
            } else {
                throw new Exception\RuntimeException('Adapter does not support streaming');
            }
        }
        // HTTP connection
        $this->lastRawRequest = $adapter->write(
            $method,
            $uri,
            $this->config['httpversion'],
            $headers,
            $body
        );

        return $adapter->read();
    }

    /**
     * Create a HTTP authentication "Authorization:" header according to the
     * specified user, password and authentication method.
     *
     * @see http://www.faqs.org/rfcs/rfc2617.html
     * @param string $user
     * @param string $password
     * @param string $type
     * @return string
     * @throws Client\Exception\InvalidArgumentException
     */
    public static function encodeAuthHeader($user, $password, $type = self::AUTH_BASIC)
    {
        switch ($type) {
            case self::AUTH_BASIC:
                // In basic authentication, the user name cannot contain ":"
                if (strpos($user, ':') !== false) {
                    throw new Client\Exception\InvalidArgumentException(
                        'The user name cannot contain \':\' in \'Basic\' HTTP authentication'
                    );
                }

                return 'Basic ' . base64_encode($user . ':' . $password);

            //case self::AUTH_DIGEST:
                /**
                 * @todo Implement digest authentication
                 */
                //    break;

            default:
                throw new Client\Exception\InvalidArgumentException(sprintf(
                    'Not a supported HTTP authentication type: \'%s\'',
                    $type
                ));
        }
    }
}
