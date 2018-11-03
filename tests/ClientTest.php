<?php
namespace Couchdb\Test;

use Couchdb\Client;
use Couchdb\Test\Traits\VisibilityTrait;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
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
}
