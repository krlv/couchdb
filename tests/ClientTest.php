<?php
namespace Couchdb\Test;

use Couchdb\Client;
use Couchdb\Test\Traits\VisibilityTrait;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class ClientTest extends \PHPUnit\Framework\TestCase
{
    use VisibilityTrait;

    public function testConstructorWithCookieAuth()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, ['Set-Cookie' => 'auth cookie']),
        ]);
        $handler->push(Middleware::history($container));

        $config = [
            'handler' => $handler,
            'headers' => [
                'User-Agent' => 'CouchDB PHP consumer'
            ],
        ];

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_COOKIE, $config);

        /** @var \GuzzleHttp\Client $http */
        $http = $this->getPrivateProperty($client, 'http');

        /** @var array $headers */
        $headers = $http->getConfig('headers');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://host:5984/_session', (string) $request->getUri());
        $this->assertEquals('CouchDB PHP consumer', $request->getHeaderLine('User-Agent'));
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"name":"user","password":"pass"}', (string) $request->getBody());

        $this->assertEquals('http://host:5984', (string) $http->getConfig('base_uri'));

        $this->assertEquals([
            'User-Agent'   => 'CouchDB PHP consumer',
            'Content-Type' => 'application/json',
            'Cookie'       => 'auth cookie',
        ], $headers);
    }

    public function testConstructorWithBasicAuth()
    {
        $config = [
            'headers' => [
                'User-Agent' => 'CouchDB PHP consumer'
            ],
        ];

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, $config);

        /** @var \GuzzleHttp\Client $http */
        $http = $this->getPrivateProperty($client, 'http');

        /** @var \GuzzleHttp\Psr7\Uri $uri */
        $uri = $http->getConfig('base_uri');

        /** @var array $headers */
        $headers = $http->getConfig('headers');

        $this->assertEquals('http://user:pass@host:5984', (string) $uri);

        $this->assertEquals([
            'User-Agent'   => 'CouchDB PHP consumer',
            'Content-Type' => 'application/json',
        ], $headers);
    }

    public function testGetAllDatabases()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '["_global_changes", "_metadata", "_replicator", "_users"]'),
        ]);
        $handler->push(Middleware::history($container));

        $client    = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $databases = $client->getAllDatabases();

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/_all_dbs', (string) $request->getUri());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('', (string) $request->getBody());

        $this->assertEquals(['_global_changes', '_metadata', '_replicator', '_users'], $databases);
    }

    /**
     * @expectedException \Couchdb\Exception\ConnectionException
     * @expectedExceptionMessage Failed to connect to host port 5984
     */
    public function testGetAllDatabasesCantConnect()
    {
        $message = 'Failed to connect to host port 5984';
        $request = new Request('GET', '/_all_dbs');

        $handler = MockHandler::createWithMiddleware([
            new ConnectException($message, $request),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getAllDatabases();
    }

    /**
     * @expectedException \Couchdb\Exception\RuntimeException
     * @expectedExceptionMessage Server error: `GET http://user:***@host:5984/_all_dbs` resulted in a `500 Internal Server Error`
     */
    public function testGetAllDatabasesServerException()
    {
        $message  = 'Server error: `GET http://user:***@host:5984/_all_dbs` resulted in a `500 Internal Server Error`';
        $request  = new Request('GET', '/_all_dbs');
        $response = new Response(500);

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getAllDatabases();
    }

    /**
     * @expectedException \Couchdb\Exception\UnauthorizedException
     * @expectedExceptionMessage Client error: `GET http://user:***@host:5984/_all_dbs` resulted in a `401 Unauthorized`
     */
    public function testGetAllDatabasesUnauthorized()
    {
        $message  = 'Client error: `GET http://user:***@host:5984/_all_dbs` resulted in a `401 Unauthorized`';
        $request  = new Request('GET', '/_all_dbs');
        $response = new Response(401, [], '{"error":"unauthorized","reason":"Name or password is incorrect."}');

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getAllDatabases();
    }

    public function testIsDatabaseExists()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);

        $this->assertTrue($client->isDatabaseExists('database'));

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database', (string) $request->getUri());
        $this->assertEquals('HEAD', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testIsDatabaseNotExists()
    {
        $message  = 'Client error: `HEAD http://user:***@host:5984/database` resulted in a `404 Object Not Found`';
        $request  = new Request('HEAD', '/database');
        $response = new Response(404);

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);

        $this->assertFalse($client->isDatabaseExists('database'));
    }

    /**
     * @expectedException \Couchdb\Exception\ConnectionException
     * @expectedExceptionMessage Failed to connect to host port 5984
     */
    public function testIsDatabaseExistsCantConnect()
    {
        $message = 'Failed to connect to host port 5984';
        $request = new Request('HEAD', '/database');

        $handler = MockHandler::createWithMiddleware([
            new ConnectException($message, $request),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->isDatabaseExists('database');
    }

    /**
     * @expectedException \Couchdb\Exception\RuntimeException
     * @expectedExceptionMessage Server error: `HEAD http://user:***@host:5984/database` resulted in a `500 Internal Server Error`
     */
    public function testIsDatabaseExistsServerException()
    {
        $message  = 'Server error: `HEAD http://user:***@host:5984/database` resulted in a `500 Internal Server Error`';
        $request  = new Request('HEAD', '/database');
        $response = new Response(500);

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getAllDatabases();
    }

    /**
     * @expectedException \Couchdb\Exception\UnauthorizedException
     * @expectedExceptionMessage Client error: `HEAD http://user:***@host:5984/database` resulted in a `401 Unauthorized`
     */
    public function testIsDatabaseExistsUnauthorized()
    {
        $message  = 'Client error: `HEAD http://user:***@host:5984/database` resulted in a `401 Unauthorized`';
        $request  = new Request('HEAD', '/database');
        $response = new Response(401, [], '{"error":"unauthorized","reason":"Name or password is incorrect."}');

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->isDatabaseExists('database');
    }

    public function testGetDatabase()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '{"db_name":"database"}'),
        ]);
        $handler->push(Middleware::history($container));

        $client   = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $database = $client->getDatabase('database');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database', (string) $request->getUri());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));

        $this->assertEquals(['db_name' => 'database'], $database);
    }

    /**
     * @expectedException \Couchdb\Exception\NotFoundException
     * @expectedExceptionMessage Client error: `GET http://user:***@host:5984/database` resulted in a `404 Object Not Found`
     */
    public function testGetDatabaseNotFound()
    {
        $message  = 'Client error: `GET http://user:***@host:5984/database` resulted in a `404 Object Not Found`';
        $request  = new Request('GET', '/database');
        $response = new Response(404);

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getDatabase('database');
    }

    /**
     * @expectedException \Couchdb\Exception\ConnectionException
     * @expectedExceptionMessage Failed to connect to host port 5984
     */
    public function testGetDatabaseCantConnect()
    {
        $message = 'Failed to connect to host port 5984';
        $request = new Request('GET', '/database');

        $handler = MockHandler::createWithMiddleware([
            new ConnectException($message, $request),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getDatabase('database');
    }

    /**
     * @expectedException \Couchdb\Exception\RuntimeException
     * @expectedExceptionMessage Server error: `GET http://user:***@host:5984/database` resulted in a `500 Internal Server Error`
     */
    public function testGetDatabaseServerException()
    {
        $message  = 'Server error: `GET http://user:***@host:5984/database` resulted in a `500 Internal Server Error`';
        $request  = new Request('GET', '/database');
        $response = new Response(500);

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getDatabase('database');
    }

    /**
     * @expectedException \Couchdb\Exception\UnauthorizedException
     * @expectedExceptionMessage Client error: `GET http://user:***@host:5984/database` resulted in a `401 Unauthorized`
     */
    public function testGetDatabaseUnauthorized()
    {
        $message  = 'Client error: `GET http://user:***@host:5984/database` resulted in a `401 Unauthorized`';
        $request  = new Request('GET', '/database');
        $response = new Response(401, [], '{"error":"unauthorized","reason":"Name or password is incorrect."}');

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getDatabase('database');
    }

    public function testCreateDatabase()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(201, [], '{"ok":true}'),
        ]);
        $handler->push(Middleware::history($container));

        $client   = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $database = $client->createDatabase('database');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database', (string) $request->getUri());
        $this->assertEquals('PUT', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));

        $this->assertEquals(['ok' => true], $database);
    }

    public function testCreateDatabaseWithParams()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(201, [], '{"ok":true}'),
        ]);
        $handler->push(Middleware::history($container));

        $client   = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $database = $client->createDatabase('database', ['q' => 8, 'n' => 3]);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database?q=8&n=3', (string) $request->getUri());
        $this->assertEquals('PUT', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));

        $this->assertEquals(['ok' => true], $database);
    }

    public function testCreateDatabaseAccepted()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(202, [], '{"ok":true}'),
        ]);
        $handler->push(Middleware::history($container));

        $client   = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $database = $client->createDatabase('database');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database', (string) $request->getUri());
        $this->assertEquals('PUT', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));

        $this->assertEquals(['ok' => true], $database);
    }

    /**
     * @expectedException \Couchdb\Exception\InvalidArgumentException
     * @expectedExceptionMessage Client error: `PUT http://user:***@host:5984/DATABASE` resulted in a `400 Bad Request`
     */
    public function testCreateDatabaseInvalidName()
    {
        $message  = 'Client error: `PUT http://user:***@host:5984/DATABASE` resulted in a `400 Bad Request`';
        $request  = new Request('PUT', '/DATABASE');
        $response = new Response(400, [], '{"error":"illegal_database_name","reason":"Name: \'DATABASE\'."}');

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->createDatabase('DATABASE');
    }

    /**
     * @expectedException \Couchdb\Exception\DuplicateException
     * @expectedExceptionMessage Client error: `PUT http://user:***@host:5984/database` resulted in a `412 Precondition Failed`
     */
    public function testCreateDatabaseAlreadyExists()
    {
        $message  = 'Client error: `PUT http://user:***@host:5984/database` resulted in a `412 Precondition Failed`';
        $request  = new Request('PUT', '/database');
        $response = new Response(412, [], '{"error":"file_exists","reason":"The database could not be created, the file already exists."}');

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->createDatabase('database');
    }

    public function testDeleteDatabase()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '{"ok":true}'),
        ]);
        $handler->push(Middleware::history($container));

        $client   = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $database = $client->deleteDatabase('database');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database', (string) $request->getUri());
        $this->assertEquals('DELETE', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));

        $this->assertEquals(['ok' => true], $database);
    }

    /**
     * @expectedException \Couchdb\Exception\NotFoundException
     * @expectedExceptionMessage Client error: `DELETE http://user:***@host:5984/database` resulted in a `404 Object Not Found`
     */
    public function testDeleteDatabaseNotFound()
    {
        $message  = 'Client error: `DELETE http://user:***@host:5984/database` resulted in a `404 Object Not Found`';
        $request  = new Request('DELETE', '/database');
        $response = new Response(404, [], '{"error":"not_found","reason":"Database does not exist."}');

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->deleteDatabase('database');
    }

    public function testGetAllDocuments()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '{"total_rows":1,"offset":0,"rows":[{"id":"366523ee63ac9873f90e0da48b9598bd","key":"366523ee63ac9873f90e0da48b9598bd","value":{"rev":"1-59414e77c768bc202142ac82c2f129de"}}]}'),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $docs   = $client->getAllDocuments('database');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_all_docs', (string) $request->getUri());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));

        $this->assertEquals([
            'total_rows' => 1,
            'offset' => 0,
            'rows' => [
                [
                    'id'    => '366523ee63ac9873f90e0da48b9598bd',
                    'key'   => '366523ee63ac9873f90e0da48b9598bd',
                    'value' => [
                        'rev' => '1-59414e77c768bc202142ac82c2f129de',
                    ],
                ],
            ],
        ], $docs);
    }

    public function testGetAllDocumentsWithParams()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '{"total_rows":1,"offset":0,"rows":[{"id":"366523ee63ac9873f90e0da48b9598bd","key":"366523ee63ac9873f90e0da48b9598bd","value":{"rev":"1-59414e77c768bc202142ac82c2f129de"},"doc":{"_id":"366523ee63ac9873f90e0da48b9598bd","_rev":"1-59414e77c768bc202142ac82c2f129de","key":"value"}}]}'),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $docs   = $client->getAllDocuments('database', ['include_docs' => 'true']);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_all_docs?include_docs=true', (string) $request->getUri());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));

        $this->assertEquals([
            'total_rows' => 1,
            'offset' => 0,
            'rows' => [
                [
                    'id'    => '366523ee63ac9873f90e0da48b9598bd',
                    'key'   => '366523ee63ac9873f90e0da48b9598bd',
                    'value' => [
                        'rev' => '1-59414e77c768bc202142ac82c2f129de',
                    ],
                    'doc' => [
                        '_id'  => '366523ee63ac9873f90e0da48b9598bd',
                        '_rev' => '1-59414e77c768bc202142ac82c2f129de',
                        'key'  => 'value',
                    ],
                ],
            ],
        ], $docs);
    }

    public function testGetAllDocumentsByKeys()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '{"total_rows":2,"rows":[{"id":"366523ee63ac9873f90e0da48bf3a4d3","key":"366523ee63ac9873f90e0da48bf3a4d3","value":{"rev":"1-59414e77c768bc202142ac82c2f129de"}},{"id":"366523ee63ac9873f90e0da48bf3bbb5","key":"366523ee63ac9873f90e0da48bf3bbb5","value":{"rev":"1-59414e77c768bc202142ac82c2f129de"}}]}'),
        ]);
        $handler->push(Middleware::history($container));

        $keys = [
            '366523ee63ac9873f90e0da48bf3a4d3',
            '366523ee63ac9873f90e0da48bf3bbb5',
        ];

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $docs   = $client->getAllDocumentsByKeys('database', $keys);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_all_docs', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"keys":["366523ee63ac9873f90e0da48bf3a4d3","366523ee63ac9873f90e0da48bf3bbb5"]}', (string) $request->getBody());

        $this->assertEquals([
            'total_rows' => 2,
            'rows' => [
                [
                    'id'    => '366523ee63ac9873f90e0da48bf3a4d3',
                    'key'   => '366523ee63ac9873f90e0da48bf3a4d3',
                    'value' => [
                        'rev' => '1-59414e77c768bc202142ac82c2f129de',
                    ],
                ],
                [
                    'id'    => '366523ee63ac9873f90e0da48bf3bbb5',
                    'key'   => '366523ee63ac9873f90e0da48bf3bbb5',
                    'value' => [
                        'rev' => '1-59414e77c768bc202142ac82c2f129de',
                    ],
                ],
            ],
        ], $docs);
    }

    public function testGetAllDocumentsByKeysWithParams()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '{"total_rows":2,"rows":[{"id":"366523ee63ac9873f90e0da48bf3a4d3","key":"366523ee63ac9873f90e0da48bf3a4d3","value":{"rev":"1-59414e77c768bc202142ac82c2f129de"},"doc":{"_id":"366523ee63ac9873f90e0da48bf3a4d3","_rev":"1-59414e77c768bc202142ac82c2f129de","key":"value"}},{"id":"366523ee63ac9873f90e0da48bf3bbb5","key":"366523ee63ac9873f90e0da48bf3bbb5","value":{"rev":"1-59414e77c768bc202142ac82c2f129de"},"doc":{"_id":"366523ee63ac9873f90e0da48bf3bbb5","_rev":"1-59414e77c768bc202142ac82c2f129de","key":"value"}}]}'),
        ]);
        $handler->push(Middleware::history($container));

        $keys = [
            '366523ee63ac9873f90e0da48bf3a4d3',
            '366523ee63ac9873f90e0da48bf3bbb5',
        ];

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $docs   = $client->getAllDocumentsByKeys('database', $keys, ['include_docs' => 'true']);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_all_docs?include_docs=true', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"keys":["366523ee63ac9873f90e0da48bf3a4d3","366523ee63ac9873f90e0da48bf3bbb5"]}', (string) $request->getBody());

        $this->assertEquals([
            'total_rows' => 2,
            'rows' => [
                [
                    'id'    => '366523ee63ac9873f90e0da48bf3a4d3',
                    'key'   => '366523ee63ac9873f90e0da48bf3a4d3',
                    'value' => [
                        'rev' => '1-59414e77c768bc202142ac82c2f129de',
                    ],
                    'doc' => [
                        '_id'  => '366523ee63ac9873f90e0da48bf3a4d3',
                        '_rev' => '1-59414e77c768bc202142ac82c2f129de',
                        'key'  => 'value',
                    ],
                ],
                [
                    'id'    => '366523ee63ac9873f90e0da48bf3bbb5',
                    'key'   => '366523ee63ac9873f90e0da48bf3bbb5',
                    'value' => [
                        'rev' => '1-59414e77c768bc202142ac82c2f129de',
                    ],
                    'doc' => [
                        '_id'  => '366523ee63ac9873f90e0da48bf3bbb5',
                        '_rev' => '1-59414e77c768bc202142ac82c2f129de',
                        'key'  => 'value',
                    ],
                ],
            ],
        ], $docs);
    }

    /**
     * @expectedException \Couchdb\Exception\NotImplementedException
     */
    public function testGetDesignDocuments()
    {
        $client = new Client('host', 5984, 'user', 'pass');
        $client->getDesignDocuments('database');
    }

    /**
     * @expectedException \Couchdb\Exception\NotImplementedException
     */
    public function testGetDesignDocumentsByKeys()
    {
        $client = new Client('host', 5984, 'user', 'pass');
        $client->getDesignDocumentsByKeys('database', ['keys' => ['366523ee63ac9873f90e0da48bf3a4d3', '366523ee63ac9873f90e0da48bf3bbb5']]);
    }

    /**
     * @expectedException \Couchdb\Exception\NotImplementedException
     */
    public function testGetBulkDocuments()
    {
        $client = new Client('host', 5984, 'user', 'pass');
        $client->getBulkDocuments('database', ['docs' => ['id' => '366523ee63ac9873f90e0da48bf3a4d3'], ['id' => '366523ee63ac9873f90e0da48bf3bbb5']]);
    }

    public function testBulkDocumentsInsert()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '[{"ok":true,"id":"366523ee63ac9873f90e0da48bf3a4d3","rev":"1-59414e77c768bc202142ac82c2f129de"},{"ok":true,"id":"366523ee63ac9873f90e0da48bf3bbb5","rev":"1-59414e77c768bc202142ac82c2f129de"}]'),
        ]);
        $handler->push(Middleware::history($container));

        $docs = [
            ['key' => 'value'],
            ['key' => 'value'],
        ];

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $bulk   = $client->bulkDocuments('database', $docs);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_bulk_docs', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"docs":[{"key":"value"},{"key":"value"}]}', (string) $request->getBody());

        $this->assertEquals([
            [
                'ok'  => true,
                'id'  => '366523ee63ac9873f90e0da48bf3a4d3',
                'rev' => '1-59414e77c768bc202142ac82c2f129de',
            ],
            [
                'ok'  => true,
                'id'  => '366523ee63ac9873f90e0da48bf3bbb5',
                'rev' => '1-59414e77c768bc202142ac82c2f129de',
            ],
        ], $bulk);
    }

    public function testBulkDocumentsUpdate()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '[{"ok":true,"id":"366523ee63ac9873f90e0da48bf3a4d3","rev":"2-2bff94179917f1dec7cd7f0209066fb8"},{"ok":true,"id":"366523ee63ac9873f90e0da48bf3bbb5","rev":"2-2bff94179917f1dec7cd7f0209066fb8"}]'),
        ]);
        $handler->push(Middleware::history($container));

        $docs = [
            [
                '_id'  => '366523ee63ac9873f90e0da48bf3a4d3',
                '_rev' => '1-59414e77c768bc202142ac82c2f129de',
                'key'  => 'new value',
            ],
            [
                '_id'  => '366523ee63ac9873f90e0da48bf3bbb5',
                '_rev' => '1-59414e77c768bc202142ac82c2f129de',
                'key'  => 'new value',
            ],
        ];

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $bulk   = $client->bulkDocuments('database', $docs);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_bulk_docs', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"docs":[{"_id":"366523ee63ac9873f90e0da48bf3a4d3","_rev":"1-59414e77c768bc202142ac82c2f129de","key":"new value"},{"_id":"366523ee63ac9873f90e0da48bf3bbb5","_rev":"1-59414e77c768bc202142ac82c2f129de","key":"new value"}]}', (string) $request->getBody());

        $this->assertEquals([
            [
                'ok'  => true,
                'id'  => '366523ee63ac9873f90e0da48bf3a4d3',
                'rev' => '2-2bff94179917f1dec7cd7f0209066fb8',
            ],
            [
                'ok'  => true,
                'id'  => '366523ee63ac9873f90e0da48bf3bbb5',
                'rev' => '2-2bff94179917f1dec7cd7f0209066fb8',
            ],
        ], $bulk);
    }

    public function testBulkDocumentsUpdateNoEdits()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '[{"ok":true,"id":"366523ee63ac9873f90e0da48bf3a4d3","rev":"1-59414e77c768bc202142ac82c2f129de"},{"ok":true,"id":"366523ee63ac9873f90e0da48bf3bbb5","rev":"1-59414e77c768bc202142ac82c2f129de"}]'),
        ]);
        $handler->push(Middleware::history($container));

        $docs = [
            [
                '_id'  => '366523ee63ac9873f90e0da48bf3a4d3',
                '_rev' => '1-59414e77c768bc202142ac82c2f129de',
                'key'  => 'new value',
            ],
            [
                '_id'  => '366523ee63ac9873f90e0da48bf3bbb5',
                '_rev' => '1-59414e77c768bc202142ac82c2f129de',
                'key'  => 'new value',
            ],
        ];

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $bulk   = $client->bulkDocuments('database', $docs, false);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_bulk_docs', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"docs":[{"_id":"366523ee63ac9873f90e0da48bf3a4d3","_rev":"1-59414e77c768bc202142ac82c2f129de","key":"new value"},{"_id":"366523ee63ac9873f90e0da48bf3bbb5","_rev":"1-59414e77c768bc202142ac82c2f129de","key":"new value"}],"new_edits":false}', (string) $request->getBody());

        $this->assertEquals([
            [
                'ok'  => true,
                'id'  => '366523ee63ac9873f90e0da48bf3a4d3',
                'rev' => '1-59414e77c768bc202142ac82c2f129de',
            ],
            [
                'ok'  => true,
                'id'  => '366523ee63ac9873f90e0da48bf3bbb5',
                'rev' => '1-59414e77c768bc202142ac82c2f129de',
            ],
        ], $bulk);
    }

    /**
     * @expectedException \Couchdb\Exception\RejectedException
     * @expectedExceptionMessage Client error: `POST http://user:***@host:5984/database/_bulk_docs` resulted in a `417 Expectation Failed`
     */
    public function testBulkDocumentsRejected()
    {
        $message  = 'Client error: `POST http://user:***@host:5984/database/_bulk_docs` resulted in a `417 Expectation Failed`';
        $request  = new Request('POST', '/database/_bulk_docs');
        $response = new Response(417, [], '[{"ok":true,"id":"366523ee63ac9873f90e0da48bf3a4d3","rev":"2-2bff94179917f1dec7cd7f0209066fb8"},{"error":"forbidden","id":"366523ee63ac9873f90e0da48bf3bbb5","reason":"invalid key value","rev":"1-59414e77c768bc202142ac82c2f129de"}]');

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $docs = [
            [
                '_id'  => '366523ee63ac9873f90e0da48bf3a4d3',
                '_rev' => '1-59414e77c768bc202142ac82c2f129de',
                'key'  => 'new value',
            ],
            [
                '_id'  => '366523ee63ac9873f90e0da48bf3bbb5',
                '_rev' => '1-59414e77c768bc202142ac82c2f129de',
                'key'  => '',
            ],
        ];

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->bulkDocuments('database', $docs);
    }

    /**
     * @expectedException \Couchdb\Exception\RuntimeException
     * @expectedExceptionMessage Server error: `POST http://user:***@host:5984/database/_bulk_docs` resulted in a `500 Internal Server Error`
     */
    public function testBulkDocumentsMalformed()
    {
        $message  = 'Server error: `POST http://user:***@host:5984/database/_bulk_docs` resulted in a `500 Internal Server Error`';
        $request  = new Request('POST', '/database/_bulk_docs');
        $response = new Response(500);

        $handler = MockHandler::createWithMiddleware([
            new ServerException($message, $request, $response),
        ]);

        $docs = [
            ['key' => 'value'],
            ['key' => 'value'],
        ];

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->bulkDocuments('database', $docs);
    }

    public function testFindDocuments()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $query = [
            'selector' => [
                'key'  => 'value',
            ],
            'limit' => 100,
            'skip'  => 0,
        ];

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->findDocuments('database', $query);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_find', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"selector":{"key":"value"},"limit":100,"skip":0}', (string) $request->getBody());
    }

    public function testCreateIndex()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $index = [
            'index' => [
                'fields' => ['key'],
            ],
            'ddoc' => 'docs',
            'name' => 'key',
        ];

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->createIndex('database', $index);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_index', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"index":{"fields":["key"]},"ddoc":"docs","name":"key"}', (string) $request->getBody());
    }

    public function testGetIndexes()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getIndexes('database');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_index', (string) $request->getUri());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testDeleteIndex()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->deleteIndex('database', 'docs', 'key');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_index/docs/json/key', (string) $request->getUri());
        $this->assertEquals('DELETE', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testExplain()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $query = [
            'selector' => [
                'key'  => 'value',
            ],
            'limit' => 100,
            'skip'  => 0,
        ];

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->explain('database', $query);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_explain', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"selector":{"key":"value"},"limit":100,"skip":0}', (string) $request->getBody());
    }

    public function testGetShards()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getDatabaseShards('database');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_shards', (string) $request->getUri());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testGetShardsByDocument()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getDocumentShards('database', 'docid');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_shards/docid', (string) $request->getUri());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testGetDatabaseChanges()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '{"last_seq": "5-g1AAAAIreJyVkEsKwjAURZ-toI5cgq5A0sQ0OrI70XyppcaRY92J7kR3ojupaSPUUgotgRd4yTlwbw4A0zRUMLdnpaMkwmyF3Ily9xBwEIuiKLI05KOTW0wkV4rruP29UyGWbordzwKVxWBNOGMKZhertDlarbr5pOT3DV4gudUC9-MPJX9tpEAYx4TQASns2E24ucuJ7rXJSL1BbEgf3vTwpmedCZkYa7Pulck7Xt7x_usFU2aIHOD4eEfVTVA5KMGUkqhNZV-8_o5i","pending": 0,"results": [{"changes": [{"rev": "2-7051cbe5c8faecd085a3fa619e6e6337"}],"id": "6478c2ae800dfc387396d14e1fc39626","seq": "3-g1AAAAG3eJzLYWBg4MhgTmHgz8tPSTV0MDQy1zMAQsMcoARTIkOS_P___7MSGXAqSVIAkkn2IFUZzIkMuUAee5pRqnGiuXkKA2dpXkpqWmZeagpu_Q4g_fGEbEkAqaqH2sIItsXAyMjM2NgUUwdOU_JYgCRDA5ACGjQfn30QlQsgKvcjfGaQZmaUmmZClM8gZhyAmHGfsG0PICrBPmQC22ZqbGRqamyIqSsLAAArcXo"},{"changes": [{"rev": "3-7379b9e515b161226c6559d90c4dc49f"}],"deleted": true,"id": "5bbc9ca465f1b0fcd62362168a7c8831","seq": "4-g1AAAAHXeJzLYWBg4MhgTmHgz8tPSTV0MDQy1zMAQsMcoARTIkOS_P___7MymBMZc4EC7MmJKSmJqWaYynEakaQAJJPsoaYwgE1JM0o1TjQ3T2HgLM1LSU3LzEtNwa3fAaQ_HqQ_kQG3qgSQqnoUtxoYGZkZG5uS4NY8FiDJ0ACkgAbNx2cfROUCiMr9CJ8ZpJkZpaaZEOUziBkHIGbcJ2zbA4hKsA-ZwLaZGhuZmhobYurKAgCz33kh"},{"changes": [{"rev": "6-460637e73a6288cb24d532bf91f32969"},{"rev": "5-eeaa298781f60b7bcae0c91bdedd1b87"}],"id": "729eb57437745e506b333068fff665ae","seq": "5-g1AAAAIReJyVkE0OgjAQRkcwUVceQU9g-mOpruQm2tI2SLCuXOtN9CZ6E70JFmpCCCFCmkyTdt6bfJMDwDQNFcztWWkcY8JXyB2cu49AgFwURZGloRid3MMkEUoJHbXbOxVy6arc_SxQWQzRVHCuYHaxSpuj1aqbj0t-3-AlSrZakn78oeSvjRSIkIhSNiCFHbsKN3c50b02mURvEB-yD296eNOzzoRMRLRZ98rkHS_veGcC_nR-fGe1gaCaxihhjOI2lX0BhniHaA"}]}'),
        ]);
        $handler->push(Middleware::history($container));

        $client  = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $changes = $client->getDatabaseChanges('database');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_changes', (string) $request->getUri());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));

        $this->assertEquals([
            'last_seq'  => '5-g1AAAAIreJyVkEsKwjAURZ-toI5cgq5A0sQ0OrI70XyppcaRY92J7kR3ojupaSPUUgotgRd4yTlwbw4A0zRUMLdnpaMkwmyF3Ily9xBwEIuiKLI05KOTW0wkV4rruP29UyGWbordzwKVxWBNOGMKZhertDlarbr5pOT3DV4gudUC9-MPJX9tpEAYx4TQASns2E24ucuJ7rXJSL1BbEgf3vTwpmedCZkYa7Pulck7Xt7x_usFU2aIHOD4eEfVTVA5KMGUkqhNZV-8_o5i',
            'pending'   => 0,
            'results'   => [
                [
                    'changes'   => [
                        ['rev'  => '2-7051cbe5c8faecd085a3fa619e6e6337'],
                    ],
                    'id'        => '6478c2ae800dfc387396d14e1fc39626',
                    'seq'       => '3-g1AAAAG3eJzLYWBg4MhgTmHgz8tPSTV0MDQy1zMAQsMcoARTIkOS_P___7MSGXAqSVIAkkn2IFUZzIkMuUAee5pRqnGiuXkKA2dpXkpqWmZeagpu_Q4g_fGEbEkAqaqH2sIItsXAyMjM2NgUUwdOU_JYgCRDA5ACGjQfn30QlQsgKvcjfGaQZmaUmmZClM8gZhyAmHGfsG0PICrBPmQC22ZqbGRqamyIqSsLAAArcXo',
                ],
                [
                    'changes'   => [
                        ['rev'  => '3-7379b9e515b161226c6559d90c4dc49f'],
                    ],
                    'deleted'   => true,
                    'id'        => '5bbc9ca465f1b0fcd62362168a7c8831',
                    'seq'       => '4-g1AAAAHXeJzLYWBg4MhgTmHgz8tPSTV0MDQy1zMAQsMcoARTIkOS_P___7MymBMZc4EC7MmJKSmJqWaYynEakaQAJJPsoaYwgE1JM0o1TjQ3T2HgLM1LSU3LzEtNwa3fAaQ_HqQ_kQG3qgSQqnoUtxoYGZkZG5uS4NY8FiDJ0ACkgAbNx2cfROUCiMr9CJ8ZpJkZpaaZEOUziBkHIGbcJ2zbA4hKsA-ZwLaZGhuZmhobYurKAgCz33kh',
                ],
                [
                    'changes'   => [
                        ['rev'  => '6-460637e73a6288cb24d532bf91f32969'],
                        ['rev'  => '5-eeaa298781f60b7bcae0c91bdedd1b87'],
                    ],
                    'id'        => '729eb57437745e506b333068fff665ae',
                    'seq'       => '5-g1AAAAIReJyVkE0OgjAQRkcwUVceQU9g-mOpruQm2tI2SLCuXOtN9CZ6E70JFmpCCCFCmkyTdt6bfJMDwDQNFcztWWkcY8JXyB2cu49AgFwURZGloRid3MMkEUoJHbXbOxVy6arc_SxQWQzRVHCuYHaxSpuj1aqbj0t-3-AlSrZakn78oeSvjRSIkIhSNiCFHbsKN3c50b02mURvEB-yD296eNOzzoRMRLRZ98rkHS_veGcC_nR-fGe1gaCaxihhjOI2lX0BhniHaA',
                ],
              ],
        ], $changes);
    }

    public function testGetDatabaseChangesWithParams()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '{"results":[{"seq":"2-g1AAAAITeJyV0EsOgjAQBuBRTNS1B9ATkLa82pXcRFsGgwRxxVpvojfRm-hNsDwSaiJENtOk8_fLTDMAWCQWwio6F1GCKqTEZg61PVs4mW5OJah1WZZpYsnJSV_MfRFIxuXvJz0Uqym10VVtWw1qzRUUieIIyyLH-HDMYxwcR4WVsfuaSAouMKCjJmq1faVdKk3CYDKf6QpXfejwrT_NjPS9ST-6bYlH_IjhH9uazrNxXp0TOzTgnIx03o1j_D4Grkt5bDrpB1cyjcY","id":"1e5a81111a583d78bca3fd2f29940045","changes":[{"rev":"1-59414e77c768bc202142ac82c2f129de"}]},{"seq":"3-g1AAAAIteJyV0EEOwiAQBdDRmqhrD6AnaIDWAit7E4UOpjZaV13rTfQmehO9ScXSREzU1M2QwPDmZ7YAMMoDhEm2r7IcdUpJyCIazkMZbe1jX4Ge1nVd5IHq7ezFMJFcMaE-f_lCsYbSM1v1otWg0WJJkWiBMK5KNOtNafBnHJ0-jWVr9BtDSSGR078StdrqqR3eEmUxNYyTzonKga1wtIdlToWCnztw3WfXfXlNJXOSZAw7TPWdq3NuL8dElAvRJb3v3J3jbQF5HFNhfKd4ALyWlIs","id":"1e5a81111a583d78bca3fd2f29940a4a","changes":[{"rev":"1-59414e77c768bc202142ac82c2f129de"}]}],"last_seq":"3-g1AAAAJHeJyV0EsOgjAQBuAKJuraA-gJSFsKbVdyE22ZEiSIK9Z6E72J3kRvguWRgIka3Mwk0_TLP5MjhOapC2gZH8s4BR0R7FGfeIEn_dw-OgrpVVVVWeqqycEOZqHkigr1-csXijaUXtuqN52GGo1JAlgLQIuyAJPsCwM_4-ioNrad4TSGkkICJ38l6rRdrZ3eEsWMGMrx6ETF1FZ0ts0yl97RiQKB-QiHDpxr69x6Bwc4jCn86dxb59E7xidciDF7DZ1n6wzuA5wxIszQyV43cJu1","pending":0}'),
        ]);
        $handler->push(Middleware::history($container));

        $params = [
            'doc_ids' => json_encode(['1e5a81111a583d78bca3fd2f29940045', '1e5a81111a583d78bca3fd2f29940a4a']),
            'filter'  => '_doc_ids',
        ];
        $client  = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $changes = $client->getDatabaseChanges('database', $params);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_changes?doc_ids=%5B%221e5a81111a583d78bca3fd2f29940045%22%2C%221e5a81111a583d78bca3fd2f29940a4a%22%5D&filter=_doc_ids', (string) $request->getUri());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));

        $this->assertEquals([
            'results'  => [
                [
                    'seq'      => '2-g1AAAAITeJyV0EsOgjAQBuBRTNS1B9ATkLa82pXcRFsGgwRxxVpvojfRm-hNsDwSaiJENtOk8_fLTDMAWCQWwio6F1GCKqTEZg61PVs4mW5OJah1WZZpYsnJSV_MfRFIxuXvJz0Uqym10VVtWw1qzRUUieIIyyLH-HDMYxwcR4WVsfuaSAouMKCjJmq1faVdKk3CYDKf6QpXfejwrT_NjPS9ST-6bYlH_IjhH9uazrNxXp0TOzTgnIx03o1j_D4Grkt5bDrpB1cyjcY',
                    'id'       => '1e5a81111a583d78bca3fd2f29940045',
                    'changes'  => [
                        ['rev' => '1-59414e77c768bc202142ac82c2f129de']
                    ],
                ],
                [
                    'seq'      => '3-g1AAAAIteJyV0EEOwiAQBdDRmqhrD6AnaIDWAit7E4UOpjZaV13rTfQmehO9ScXSREzU1M2QwPDmZ7YAMMoDhEm2r7IcdUpJyCIazkMZbe1jX4Ge1nVd5IHq7ezFMJFcMaE-f_lCsYbSM1v1otWg0WJJkWiBMK5KNOtNafBnHJ0-jWVr9BtDSSGR078StdrqqR3eEmUxNYyTzonKga1wtIdlToWCnztw3WfXfXlNJXOSZAw7TPWdq3NuL8dElAvRJb3v3J3jbQF5HFNhfKd4ALyWlIs',
                    'id'       => '1e5a81111a583d78bca3fd2f29940a4a',
                    'changes'  => [
                        ['rev' => '1-59414e77c768bc202142ac82c2f129de']
                    ],
                ],
            ],
            'last_seq' => '3-g1AAAAJHeJyV0EsOgjAQBuAKJuraA-gJSFsKbVdyE22ZEiSIK9Z6E72J3kRvguWRgIka3Mwk0_TLP5MjhOapC2gZH8s4BR0R7FGfeIEn_dw-OgrpVVVVWeqqycEOZqHkigr1-csXijaUXtuqN52GGo1JAlgLQIuyAJPsCwM_4-ioNrad4TSGkkICJ38l6rRdrZ3eEsWMGMrx6ETF1FZ0ts0yl97RiQKB-QiHDpxr69x6Bwc4jCn86dxb59E7xidciDF7DZ1n6wzuA5wxIszQyV43cJu1',
            'pending'  => 0,
        ], $changes);
    }

    public function testGetDatabaseChangesByCriteria()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '{"results":[{"seq":"1-g1AAAAGHeJzLYWBg4MhgTmEQTs4vTc5ISXIwNNAzMjbUM9WzNMkBSjIlMiTJ____PyuRAYcyI7CyJAUgmWRPjEoHkMp4kMoM5kTGXCCPPcnMxMDY0AK7LvymJYBMq8drL8QjeSxAkqEBSAEVzyfkSojqBRDV-4lTfQCi-j5xqh9AVIPcnQUAWt1qKg","id":"1e5a81111a583d78bca3fd2f29940045","changes":[{"rev":"1-59414e77c768bc202142ac82c2f129de"}]},{"seq":"3-g1AAAAIteJyV0FEOgjAMANAKJuq3B9ATkG0MGF9yE2UrBgniF996E72J3kRvgoORgAkS_emStX1tmgPAPLURlupUqhRlRInDXOp4TshznbRikKuqqrLUjidH_THjXAmG3nDLF4o1lFzrKDetBkYLKRIpEBZlgcn-UCQ4bkS1sW0NqzGkz4lLxXDXuLartfPHRirwFYa_bGQOVEx1hIt-NHPNYhidaKpvpvreTSUe8RXDn-9gnIdxnp2TuDQQgvzpvIzTuwIGnFOR9J3sDaXQlIo","id":"1e5a81111a583d78bca3fd2f2994013d","changes":[{"rev":"1-59414e77c768bc202142ac82c2f129de"}]}],"last_seq":"3-g1AAAAJHeJyV0FEOgjAMANApJuq3B9ATkG0MGF9yE2XrCBLEL771JnoTvYneBAcjARMk8NMmbfrSNkMIrRIL0EZeCpmACAm2qUNs1w5YppvzCIltWZZpYkWzsy4sGZOcgts_8oeiNSV2Oop9oyGjBQSw4IDWRQ4qPuUKho2wMg6NMa8N4THsEN4_NawdK-36s5H0PQnBmI3Mg_KFjuimk2burRNzHsWKjL7MOA_jPFsHu9iTFCY6L-O8W0c5xOccT3Q-xun8B3zGCFddJ_0CJfCbuw","pending":0}'),
        ]);
        $handler->push(Middleware::history($container));

        $criteria = [
            'doc_ids' => [
                '1e5a81111a583d78bca3fd2f2994013d',
                '1e5a81111a583d78bca3fd2f29940045',
            ],
        ];
        $params   = [
            'filter' => '_doc_ids',
        ];
        $client  = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $changes = $client->getDatabaseChangesByCriteria('database', $criteria, $params);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_changes?filter=_doc_ids', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"doc_ids":["1e5a81111a583d78bca3fd2f2994013d","1e5a81111a583d78bca3fd2f29940045"]}', (string) $request->getBody());

        $this->assertEquals([
            'results'  => [
                [
                    'seq'      => '1-g1AAAAGHeJzLYWBg4MhgTmEQTs4vTc5ISXIwNNAzMjbUM9WzNMkBSjIlMiTJ____PyuRAYcyI7CyJAUgmWRPjEoHkMp4kMoM5kTGXCCPPcnMxMDY0AK7LvymJYBMq8drL8QjeSxAkqEBSAEVzyfkSojqBRDV-4lTfQCi-j5xqh9AVIPcnQUAWt1qKg',
                    'id'       => '1e5a81111a583d78bca3fd2f29940045',
                    'changes'  => [
                        ['rev' => '1-59414e77c768bc202142ac82c2f129de']
                    ],
                ],
                [
                    'seq'      => '3-g1AAAAIteJyV0FEOgjAMANAKJuq3B9ATkG0MGF9yE2UrBgniF996E72J3kRvgoORgAkS_emStX1tmgPAPLURlupUqhRlRInDXOp4TshznbRikKuqqrLUjidH_THjXAmG3nDLF4o1lFzrKDetBkYLKRIpEBZlgcn-UCQ4bkS1sW0NqzGkz4lLxXDXuLartfPHRirwFYa_bGQOVEx1hIt-NHPNYhidaKpvpvreTSUe8RXDn-9gnIdxnp2TuDQQgvzpvIzTuwIGnFOR9J3sDaXQlIo',
                    'id'       => '1e5a81111a583d78bca3fd2f2994013d',
                    'changes'  => [
                        ['rev' => '1-59414e77c768bc202142ac82c2f129de']
                    ],
                ],
            ],
            'last_seq' => '3-g1AAAAJHeJyV0FEOgjAMANApJuq3B9ATkG0MGF9yE2XrCBLEL771JnoTvYneBAcjARMk8NMmbfrSNkMIrRIL0EZeCpmACAm2qUNs1w5YppvzCIltWZZpYkWzsy4sGZOcgts_8oeiNSV2Oop9oyGjBQSw4IDWRQ4qPuUKho2wMg6NMa8N4THsEN4_NawdK-36s5H0PQnBmI3Mg_KFjuimk2burRNzHsWKjL7MOA_jPFsHu9iTFCY6L-O8W0c5xOccT3Q-xun8B3zGCFddJ_0CJfCbuw',
            'pending'  => 0,
        ], $changes);
    }

    public function testCompactDatabase()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '{"ok":true}'),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->compactDatabase('database');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_compact', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testCompactDesignDocument()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '{"ok":true}'),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->compactDesignDocument('database', 'docs');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_compact/docs', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testEnsureFullCommit()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '{"ok":true}'),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->ensureFullCommit('database');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_ensure_full_commit', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testCleanupView()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200, [], '{"ok":true}'),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->cleanupView('database');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_view_cleanup', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testGetSecurityInfo()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getSecurityInfo('database');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_security', (string) $request->getUri());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testSetSecurityInfo()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $security = [
            'admins'  => [
                'names' => ['admin'],
                'roles' => ['admins'],
            ],
            'members' => [
                'names' => ['user'],
                'roles' => ['developers'],
            ],
        ];

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->setSecurityInfo('database', $security);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_security', (string) $request->getUri());
        $this->assertEquals('PUT', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"admins":{"names":["admin"],"roles":["admins"]},"members":{"names":["user"],"roles":["developers"]}}', (string) $request->getBody());
    }

    public function testPurge()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->purge('database', ['id' => ['1-rev', '2-rev']]);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_purge', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"id":["1-rev","2-rev"]}', (string) $request->getBody());
    }

    public function testGetPurgedLimit()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getPurgedLimit('database');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_purged_infos_limit', (string) $request->getUri());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testSetPurgedLimit()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->setPurgedLimit('database', $limit = 1000);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_purged_infos_limit', (string) $request->getUri());
        $this->assertEquals('PUT', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('1000', (string) $request->getBody());
    }

    public function testGetMissedRevisions()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getMissedRevisions('database', ['id' => ['1-rev', '2-rev']]);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_missing_revs', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"id":["1-rev","2-rev"]}', (string) $request->getBody());
    }

    public function testGetRevisionsDiff()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getRevisionsDiff('database', ['id' => ['1-rev', '2-rev']]);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_revs_diff', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"id":["1-rev","2-rev"]}', (string) $request->getBody());
    }

    public function testGetRevisionsLimit()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getRevisionsLimit('database');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_revs_limit', (string) $request->getUri());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testSetRevisionsLimit()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->setRevisionsLimit('database', 1000);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/_revs_limit', (string) $request->getUri());
        $this->assertEquals('PUT', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('1000', (string) $request->getBody());
    }

    public function testIsDocumentExists()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);

        $this->assertTrue($client->isDocumentExists('database', 'id'));

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/id', (string) $request->getUri());
        $this->assertEquals('HEAD', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testIsDocumentNotExists()
    {
        $message  = 'Client error: `HEAD http://user:***@host:5984/database/id` resulted in a `404 Object Not Found`';
        $request  = new Request('HEAD', '/database/id');
        $response = new Response(404);

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);

        $this->assertFalse($client->isDocumentExists('database', 'id'));
    }

    /**
     * @expectedException \Couchdb\Exception\ConnectionException
     * @expectedExceptionMessage Failed to connect to host port 5984
     */
    public function testIsDocumentExistsCantConnect()
    {
        $message = 'Failed to connect to host port 5984';
        $request = new Request('HEAD', '/database/id');

        $handler = MockHandler::createWithMiddleware([
            new ConnectException($message, $request),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->isDocumentExists('database', 'id');
    }

    /**
     * @expectedException \Couchdb\Exception\RuntimeException
     * @expectedExceptionMessage Server error: `HEAD http://user:***@host:5984/database/id` resulted in a `500 Internal Server Error`
     */
    public function testIsDocumentExistsServerException()
    {
        $message  = 'Server error: `HEAD http://user:***@host:5984/database/id` resulted in a `500 Internal Server Error`';
        $request  = new Request('HEAD', '/database/id');
        $response = new Response(500);

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->isDocumentExists('database', 'id');
    }

    /**
     * @expectedException \Couchdb\Exception\UnauthorizedException
     * @expectedExceptionMessage Client error: `HEAD http://user:***@host:5984/database/id` resulted in a `401 Unauthorized`
     */
    public function testIsDocumentExistsUnauthorized()
    {
        $message  = 'Client error: `HEAD http://user:***@host:5984/database/id` resulted in a `401 Unauthorized`';
        $request  = new Request('HEAD', '/database/id');
        $response = new Response(401, [], '{"error":"unauthorized","reason":"Name or password is incorrect."}');

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->isDocumentExists('database', 'id');
    }

    public function testGetDocument()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getDocument('database', 'id');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/id', (string) $request->getUri());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testCreateDocument()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(201, [], '{"db_name":"database"}'),
        ]);
        $handler->push(Middleware::history($container));

        $client   = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $document = $client->createDocument('database', ['key' => 'value']);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"key":"value"}', (string) $request->getBody());

        $this->assertEquals(['db_name' => 'database'], $document);
    }

    public function testCreateDocumentAccepted()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(201, [], '{"db_name":"database"}'),
        ]);
        $handler->push(Middleware::history($container));

        $client   = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $document = $client->createDocument('database', ['key' => 'value']);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database', (string) $request->getUri());
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"key":"value"}', (string) $request->getBody());

        $this->assertEquals(['db_name' => 'database'], $document);
    }

    /**
     * @expectedException \Couchdb\Exception\NotFoundException
     * @expectedExceptionMessage Client error: `POST http://user:***@host:5984/database` resulted in a `404 Object Not Found`
     */
    public function testCreateDocumentDatabaseNotFound()
    {
        $message  = 'Client error: `POST http://user:***@host:5984/database` resulted in a `404 Object Not Found`';
        $request  = new Request('POST', '/database');
        $response = new Response(404, [], '{"error":"not_found","reason":"Database does not exist."}');

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->createDocument('database', ['key' => 'value']);
    }

    /**
     * @expectedException \Couchdb\Exception\ConflictException
     * @expectedExceptionMessage Client error: `POST http://user:***@host:5984/database` resulted in a `409 Conflict`
     */
    public function testCreateDocumentConflict()
    {
        $message  = 'Client error: `POST http://user:***@host:5984/database` resulted in a `409 Conflict`';
        $request  = new Request('POST', '/database');
        $response = new Response(409, [], '{"error":"conflict","reason":"Document update conflict."}');

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->createDocument('database', ['key' => 'value']);
    }

    public function testUpdateDocument()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(201),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->updateDocument('database', 'id', ['key' => 'value']);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/id', (string) $request->getUri());
        $this->assertEquals('PUT', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"key":"value"}', (string) $request->getBody());
    }

    public function testUpdateDocumentWithParams()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(202),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->updateDocument('database', 'id', ['key' => 'value'], ['batch' => 'ok']);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/id?batch=ok', (string) $request->getUri());
        $this->assertEquals('PUT', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"key":"value"}', (string) $request->getBody());
    }

    public function testDeleteDocument()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->deleteDocument('database', 'id', '1-rev');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/id?rev=1-rev', (string) $request->getUri());
        $this->assertEquals('DELETE', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testDeleteDocumentWithParams()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->deleteDocument('database', 'id', '1-rev', ['batch' => 'ok']);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/id?batch=ok&rev=1-rev', (string) $request->getUri());
        $this->assertEquals('DELETE', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

public function testCopyDocument()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(201),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->copyDocument('database', 'id', 'id_copy');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/id', (string) $request->getUri());
        $this->assertEquals('COPY', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('id_copy', $request->getHeaderLine('Destination'));
    }

    public function testCopyDocumentWithParams()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(201),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->copyDocument('database', 'id', 'id_copy', ['rev' => '1-rev']);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/id?rev=1-rev', (string) $request->getUri());
        $this->assertEquals('COPY', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('id_copy', $request->getHeaderLine('Destination'));
    }

    public function testIsDocumentAttachmentExists()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);

        $this->assertTrue($client->isDocumentAttachmentExists('database', 'id', 'att.json'));

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/id/att.json', (string) $request->getUri());
        $this->assertEquals('HEAD', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testIsDocumentAttachmentExistWithRevision()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);

        $this->assertTrue($client->isDocumentAttachmentExists('database', 'id', 'att.json', '2-rev'));

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/id/att.json?rev=2-rev', (string) $request->getUri());
        $this->assertEquals('HEAD', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testIsDocumentAttachmentNotExists()
    {
        $message  = 'Client error: `HEAD http://user:***@host:5984/database/id/att.json` resulted in a `404 Object Not Found`';
        $request  = new Request('HEAD', '/database/id/att.json');
        $response = new Response(404);

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);

        $this->assertFalse($client->isDocumentAttachmentExists('database', 'id', 'att.json'));
    }

    /**
     * @expectedException \Couchdb\Exception\ConnectionException
     * @expectedExceptionMessage Failed to connect to host port 5984
     */
    public function testIsDocumentAttachmentExistsCantConnect()
    {
        $message = 'Failed to connect to host port 5984';
        $request = new Request('HEAD', '/database/id/att.json');

        $handler = MockHandler::createWithMiddleware([
            new ConnectException($message, $request),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->isDocumentAttachmentExists('database', 'id', 'att.json');
    }

    /**
     * @expectedException \Couchdb\Exception\RuntimeException
     * @expectedExceptionMessage Server error: `HEAD http://user:***@host:5984/database/id/att.json` resulted in a `500 Internal Server Error`
     */
    public function testIsDocumentAttachmentExistsServerException()
    {
        $message  = 'Server error: `HEAD http://user:***@host:5984/database/id/att.json` resulted in a `500 Internal Server Error`';
        $request  = new Request('HEAD', '/database/id/att.json');
        $response = new Response(500);

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->isDocumentAttachmentExists('database', 'id', 'att.json');
    }

    /**
     * @expectedException \Couchdb\Exception\UnauthorizedException
     * @expectedExceptionMessage Client error: `HEAD http://user:***@host:5984/database/id/att.json` resulted in a `401 Unauthorized`
     */
    public function testIsDocumentAttachmentExistsUnauthorized()
    {
        $message  = 'Client error: `HEAD http://user:***@host:5984/database/id/att.json` resulted in a `401 Unauthorized`';
        $request  = new Request('HEAD', '/database/id/att.json');
        $response = new Response(401, [], '{"error":"unauthorized","reason":"Name or password is incorrect."}');

        $handler = MockHandler::createWithMiddleware([
            new ClientException($message, $request, $response),
        ]);

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->isDocumentAttachmentExists('database', 'id', 'att.json');
    }

    public function testGetDocumentAttachment()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getDocumentAttachment('database', 'id', 'att.json');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/id/att.json', (string) $request->getUri());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testGetDocumentAttachmentWithRevision()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->getDocumentAttachment('database', 'id', 'att.json', '2-rev');

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/id/att.json?rev=2-rev', (string) $request->getUri());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testCreateDocumentAttachment()
    {
        $container = [];

        $handler = MockHandler::createWithMiddleware([
            new Response(200),
        ]);
        $handler->push(Middleware::history($container));

        $client = new Client('host', 5984, 'user', 'pass', Client::AUTH_BASIC, ['handler' => $handler]);
        $client->createDocumentAttachment('database', 'id', 'att.json', '2-rev', ['key' => 'value']);

        $this->assertNotEmpty($container[0]);

        /** @var Request $request */
        $request = $container[0]['request'];

        $this->assertEquals('http://user:pass@host:5984/database/id/att.json?rev=2-rev', (string) $request->getUri());
        $this->assertEquals('PUT', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('{"key":"value"}', (string) $request->getBody());
    }
}
