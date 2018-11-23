<?php
namespace Couchdb;

use Couchdb\Exception\ConflictException;
use Couchdb\Exception\ConnectionException;
use Couchdb\Exception\DuplicateException;
use Couchdb\Exception\InvalidArgumentException;
use Couchdb\Exception\NotFoundException;
use Couchdb\Exception\NotImplementedException;
use Couchdb\Exception\RejectedException;
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
     *
     * @throws UnauthorizedException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function getAllDatabases(): array
    {
        return $this->request('GET', '/_all_dbs');
    }

    /**
     * Checks if database exists
     * @link http://docs.couchdb.org/en/stable/api/database/common.html#head--db
     *
     * @param string $db Database name
     * @return bool
     *
     * @throws UnauthorizedException
     * @throws RuntimeException
     * @throws ConnectionException
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
     * Returns the database information
     * @link http://docs.couchdb.org/en/stable/api/database/common.html#get--db
     *
     * @param string $db Database name
     * @return array
     *
     * @throws NotFoundException
     * @throws UnauthorizedException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function getDatabase(string $db): array
    {
        return $this->request('GET', sprintf('/%s', $db));
    }

    /**
     * Creates a new database
     * @link https://docs.couchdb.org/en/stable/api/database/common.html#put--db
     *
     * @param string $db Database name
     * @param array $params Database parameters (eg. shards, replicas)
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws InvalidArgumentException
     * @throws DuplicateException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function createDatabase(string $db, array $params = []): array
    {
        $params = !empty($params) ? ['query' => $params] : [];
        return $this->request('PUT', sprintf('/%s', $db), $params);
    }

    /**
     * Delete an existing database
     * @link http://docs.couchdb.org/en/stable/api/database/common.html#delete--db
     *
     * @param string $db
     * @return array
     *
     * @throws UnauthorizedException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function deleteDatabase(string $db): array
    {
        return $this->request('DELETE', sprintf('/%s', $db));
    }

    /**
     * Returns all the documents from the database
     * @link http://docs.couchdb.org/en/stable/api/database/bulk-api.html#get--db-_all_docs
     *
     * @param string $db
     * @param array $params
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function getAllDocuments(string $db, array $params = []): array
    {
        $params = !empty($params) ? ['query' => $params] : [];
        return $this->request('GET', sprintf('/%s/_all_docs', $db), $params);
    }

    /**
     * Returns certain rows from the _all_docs view of the database
     * @link http://docs.couchdb.org/en/stable/api/database/bulk-api.html#post--db-_all_docs
     *
     * @param string $db
     * @param array $keys
     * @param array $params
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function getAllDocumentsByKeys(string $db, array $keys, array $params = []): array
    {
        $params = !empty($params) ? ['query' => $params] : [];
        $params['json'] = ['keys' => $keys];
        return $this->request('POST', sprintf('/%s/_all_docs', $db), $params);
    }

    /**
     * Returns a JSON structure of all of the design documents in a given database
     * @link http://docs.couchdb.org/en/stable/api/database/bulk-api.html#db-design-docs
     *
     * @param string $db
     * @param array $params
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws RuntimeException
     * @throws ConnectionException
     * @throws NotImplementedException
     */
    public function getDesignDocuments(string $db, array $params = []): array
    {
        throw new NotImplementedException;
    }

    /**
     * Returns multiple design documents in a single request
     * @link http://docs.couchdb.org/en/stable/api/database/bulk-api.html#post--db-_design_docs
     *
     * @param string $db
     * @param array $keys
     * @param array $params
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws RuntimeException
     * @throws ConnectionException
     * @throws NotImplementedException
     */
    public function getDesignDocumentsByKeys(string $db, array $keys, array $params = []): array
    {
        throw new NotImplementedException;
    }

    /**
     * Inserts or update multiple documents in to the database
     * @link http://docs.couchdb.org/en/stable/api/database/bulk-api.html#db-bulk-get
     *
     * @param string $db
     * @param array $docs
     * @param array $params
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws ConnectionException
     * @throws NotImplementedException
     */
    public function getBulkDocuments(string $db, array $docs, array $params = []): array
    {
        throw new NotImplementedException;
    }

    /**
     * Inserts or update multiple documents in to the database
     * @link http://docs.couchdb.org/en/stable/api/database/bulk-api.html#post--db-_bulk_docs
     *
     * @param string $db
     * @param array $docs
     * @param bool $isNewEdits
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws InvalidArgumentException
     * @throws RejectedException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function bulkDocuments(string $db, array $docs, bool $isNewEdits = true): array
    {
        $params = ['json' => ['docs' => $docs]];

        if (!$isNewEdits) {
            $params['json']['new_edits'] = $isNewEdits;
        }

        return $this->request('POST', sprintf('/%s/_bulk_docs', $db), $params);
    }

    /**
     * Finds documents within a given database
     * @link http://docs.couchdb.org/en/stable/api/database/find.html#db-find
     *
     * @param string $db
     * @param array $query
     *
     * @return array
     *
     * @throws InvalidArgumentException
     * @throws UnauthorizedException
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function findDocuments(string $db, array $query): array
    {
        $params = ['json' => $query];
        return $this->request('POST', sprintf('/%s/_find', $db), $params);
    }

    /**
     * Creates a new index
     * @link http://docs.couchdb.org/en/stable/api/database/find.html#post--db-_index
     *
     * @param string $db
     * @param array $index
     *
     * @return array
     *
     * @throws InvalidArgumentException
     * @throws UnauthorizedException
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function createIndex(string $db, array $index): array
    {
        $params = ['json' => $index];
        return $this->request('POST', sprintf('/%s/_index', $db), $params);
    }

    /**
     * List all indexes in the database
     * @link http://docs.couchdb.org/en/stable/api/database/find.html#get--db-_index
     *
     * @param string $db
     * @return array
     *
     * @throws InvalidArgumentException
     * @throws UnauthorizedException
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function getIndexes(string $db): array
    {
        return $this->request('GET', sprintf('/%s/_index', $db));
    }

    /**
     * Deletes an index
     * @link http://docs.couchdb.org/en/stable/api/database/find.html#delete--db-_index-designdoc-json-name
     *
     * @param string $db
     * @param string $ddoc
     * @param string $index
     *
     * @return array
     *
     * @throws InvalidArgumentException
     * @throws UnauthorizedException
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function deleteIndex(string $db, string $ddoc, string $index): array
    {
        return $this->request('DELETE', sprintf('/%s/_index/%s/json/%s', $db, $ddoc, $index));
    }

    /**
     * Shows which index is being used by the query
     * @link https://docs.couchdb.org/en/stable/api/database/find.html#db-explain
     *
     * @param string $db
     * @param array $query
     *
     * @return array
     *
     * @throws InvalidArgumentException
     * @throws UnauthorizedException
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function explain(string $db, array $query): array
    {
        $params = ['json' => $query];
        return $this->request('POST', sprintf('/%s/_explain', $db), $params);
    }

    /**
     * Returens a list of database shards
     * @link https://docs.couchdb.org/en/stable/api/database/shard.html#db-shards
     *
     * @param string $db
     * @return array
     *
     * @throws InvalidArgumentException
     * @throws UnauthorizedException
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function getDatabaseShards(string $db): array
    {
        return $this->request('GET', sprintf('/%s/_shards', $db));
    }

    /**
     * Returns information about the specific shard into which a given document has been stored
     * @link https://docs.couchdb.org/en/stable/api/database/shard.html#db-shards-doc
     *
     * @param string $db
     * @param string $docid
     *
     * @return array
     *
     * @throws InvalidArgumentException
     * @throws UnauthorizedException
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function getDocumentShards(string $db, string $docid): array
    {
        return $this->request('GET', sprintf('/%s/_shards/%s', $db, $docid));
    }

    /**
     * Returns a list of changes made to documents in the database
     * @link https://docs.couchdb.org/en/stable/api/database/changes.html#get--db-_changes
     *
     * @param string $db
     * @param array $params
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function getDatabaseChanges(string $db, array $params = []): array
    {
        $params = !empty($params) ? ['query' => $params] : [];
        return $this->request('GET', sprintf('/%s/_changes', $db), $params);
    }

    /**
     * Returns a list of changes made to documents in the database
     *
     * This method is widely used with ?filter=_doc_ids query parameter
     * and allows one to pass a larger list of document IDs to filter.
     *
     * @link https://docs.couchdb.org/en/stable/api/database/changes.html#post--db-_changes
     *
     * @param string $db
     * @param array $criteria
     * @param array $params
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function getDatabaseChangesByCriteria(string $db, array $criteria, array $params = []): array
    {
        $params = !empty($params)
            ? ['query' => $params, 'json' => $criteria]
            : ['json' => $criteria];
        return $this->request('POST', sprintf('/%s/_changes', $db), $params);
    }

    /**
     * Starts compaction of the database
     * @link https://docs.couchdb.org/en/stable/api/database/compact.html#db-compact
     *
     * @param string $db
     * @return array
     *
     * @throws UnauthorizedException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function compactDatabase(string $db): array
    {
        return $this->request('POST', sprintf('/%s/_compact', $db));
    }

    /**
     * Starts compaction of the view indexes associated with the specified design document
     * @link https://docs.couchdb.org/en/stable/api/database/compact.html#db-compact-design-doc
     *
     * @param string $db
     * @param string $ddoc
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function compactDesignDocument(string $db, string $ddoc): array
    {
        return $this->request('POST', sprintf('/%s/_compact/%s', $db, $ddoc));
    }

    /**
     * Creates new document for the database
     * @link https://docs.couchdb.org/en/stable/api/database/common.html#post--db
     *
     * @param string $db
     * @param array $doc
     * @param array $params
     *
     * @return array
     *
     * @throws UnauthorizedException
     * @throws NotFoundException
     * @throws ConflictException
     * @throws RuntimeException
     * @throws ConnectionException
     */
    public function createDocument(string $db, array $doc, array $params = []): array
    {
        $params['json'] = $doc;
        return $this->request('POST', sprintf('/%s', $db), $params);
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
            $response = (string) $response->getBody();
            return !empty($response) ? json_decode($response, true) : [];
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
                    throw new DuplicateException($e->getMessage(), $e->getCode(), $e);
                    break;

                case 417:
                    // documents rejected
                    throw new RejectedException($e->getMessage(), $e->getCode(), $e);
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
