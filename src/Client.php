<?php
namespace Couchdb;

use Couchdb\Exception\ConflictException;
use Couchdb\Exception\ConnectionException;
use Couchdb\Exception\InvalidArgumentException;
use Couchdb\Exception\NotFoundException;
use Couchdb\Exception\RuntimeException;
use Couchdb\Exception\UnauthorizedException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;

class Client
{
    const AUTH_BASIC  = 'basic';
    const AUTH_COOKIE = 'cookie';

    /**
     * @var \GuzzleHttp\Client
     */
    private $http;

    /**
     * @param string $host
     * @param int $port
     * @param string $user
     * @param string $password
     * @param string $auth
     * @param array $config
     */
    public function __construct(
        string $host,
        int $port,
        string $user,
        string $password,
        string $auth = self::AUTH_BASIC,
        array $config = []
    ) {
        $dsn = ($auth == self::AUTH_BASIC)
            ? sprintf('http://%s:%s@%s:%d', urlencode($user), urlencode($password), $host, $port)
            : sprintf('http://%s:%d', $host, $port);

        $config = array_merge_recursive($config, [
            'base_uri' => $dsn,
            'headers'  => [
                'Content-Type' => 'application/json',
            ],
        ]);

        if ($auth == self::AUTH_COOKIE) {
            $client = new \GuzzleHttp\Client($config);

            $response = $client->request('POST', '/_session', [
                'json' => [
                    'name'     => $user,
                    'password' => $password,
                ],
            ]);

            $config['headers']['Cookie'] = $response->getHeaderLine('Set-Cookie');
        }

        $this->http = new \GuzzleHttp\Client($config);
    }

    /**
     * Returns a list of all the databases in the CouchDB instance.
     * @link http://docs.couchdb.org/en/stable/api/server/common.html#all-dbs
     *
     * @return array
     */
    public function getAllDatabases(): array
    {
        return $this->request('GET', '/_all_dbs');
    }

    /**
     * Check if database exists
     * @link http://docs.couchdb.org/en/stable/api/database/common.html#head--db
     *
     * @param string $db Database name
     * @return bool
     */
    public function isDatabaseExists(string $db): bool
    {
        try {
            $this->request('HEAD', sprintf('/%s', $db));
            return true;
        } catch (NotFoundException $exception) {
            return false;
        }
    }

    /**
     * Sends request to the CouchDB HTTP API and handles response
     *
     * @param string $method
     * @param string $uri
     * @param array $options
     *
     * @return array
     *
     * @throws NotFoundException
     * @throws RuntimeException
     */
    private function request(string $method, string $uri, array $options = []): array
    {
        try {
            $response = $this->http->request($method, $uri, $options);
            return json_decode($response->getBody(), true);
        } catch (ClientException $e) {
            switch ($e->getCode()) {
                case 400:
                    // invalid JSON data / database name
                    throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
                    break;

                case 401:
                    // unauthorized
                    throw new UnauthorizedException($e->getMessage(), $e->getCode(), $e);
                    break;

                case 404:
                    // document or database doesn't exists
                    throw new NotFoundException($e->getMessage(), $e->getCode(), $e);
                    break;

                case 409:
                    // conflicting document with the same id already exists
                    throw new ConflictException($e->getMessage(), $e->getCode(), $e);
                    break;

                case 412:
                    // database already exists
                    throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
                    break;

                case 417:
                    // documents rejected
                    throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
                    break;

                default:
                    throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
                    break;
            }
        } catch (ServerException $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        } catch (ConnectException $e) {
            throw new ConnectionException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
