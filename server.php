<?php

require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\Http\Router as SocketRouter;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\Socket\Server as SocketServer;
use React\EventLoop\Factory;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class Router
{
    /** @var \Symfony\Component\Routing\RouteCollection */
    protected $routes;

    public function __construct()
    {
        $this->routes = new RouteCollection;
    }

    public function getRoutes(): RouteCollection
    {
        return $this->routes;
    }

    public function echo()
    {
        $this->get('/app/{appKey}', WebSocketHandler::class);

        $this->post('/apps/{appId}/events', TriggerEventController::class);
        $this->get('/apps/{appId}/channels', FetchChannelsController::class);
        $this->get('/apps/{appId}/channels/{channelName}', FetchChannelController::class);
        $this->get('/apps/{appId}/channels/{channelName}/users', FetchUsersController::class);
    }

    public function get(string $uri, $action)
    {
        $this->addRoute('GET', $uri, $action);
    }

    public function post(string $uri, $action)
    {
        $this->addRoute('POST', $uri, $action);
    }

    public function put(string $uri, $action)
    {
        $this->addRoute('PUT', $uri, $action);
    }

    public function patch(string $uri, $action)
    {
        $this->addRoute('PATCH', $uri, $action);
    }

    public function delete(string $uri, $action)
    {
        $this->addRoute('DELETE', $uri, $action);
    }

    public function addRoute(string $method, string $uri, $action)
    {
        $this->routes->add($uri, $this->getRoute($method, $uri, $action));
    }

    protected function getRoute(string $method, string $uri, $action): Route
    {
        /**
         * If the given action is a class that handles WebSockets, then it's not a regular
         * controller but a WebSocketHandler that needs to converted to a WsServer.
         *
         * If the given action is a regular controller we'll just instanciate it.
         */
        $action = $this->createWebSocketsServer($action);

        return new Route($uri, ['_controller' => $action], [], [], null, [], [$method]);
    }

    protected function createWebSocketsServer(string $action): WsServer
    {
        return new WsServer(new $action);
    }
}

class Server {
    protected $loop;
    /** @var \Symfony\Component\Routing\RouteCollection  */
    protected $routes;

    public function __construct() {
        $this->loop = Factory::create();
    }

    public function useRoutes(RouteCollection $routes)
    {
        $this->routes = $routes;

        return $this;
    }

    public function getLoop()
    {
        return $this->loop;
    }

    public function create()
    {
        $socket = new SocketServer("0.0.0.0:8080", $this->loop);
        $matcher = new UrlMatcher($this->routes, new RequestContext());
        $router = new SocketRouter($matcher);
        $http = new HttpServer($router);

        return new IoServer($http, $socket, $this->loop);
    }
}

abstract class Message implements MessageComponentInterface {
    /** @var \Ratchet\ConnectionInterface;[] */
    public static $clients = [];

    public function onOpen(ConnectionInterface $conn) {
        static::$clients[$conn->resourceId] = $conn;
    }

    public abstract function onMessage(ConnectionInterface $conn, $message);

    public function onClose(ConnectionInterface $conn) {
        unset(static::$clients[$conn->resourceId]);
        $conn->close();
    }

    public function onError(ConnectionInterface $conn, Exception $e) {
        printf("error: %s\n", $e->getMessage());
        unset(static::$clients[$conn->resourceId]);
        $conn->close();
    }
}

class Test extends Message {
    public function onMessage(ConnectionInterface $conn, $message) {
        printf("receiver: %s\n", $message);
    }
}

$server = new Server();
$router = new Router();
$server->useRoutes($router->getRoutes());
$loop = $server->getLoop();

$router->get('/', Test::class);

$loop->addPeriodicTimer(1, function () {
    $clients = Message::$clients;

    foreach ($clients as $client) {
        $client->send(date('Y-m-d H:i:s'));
    }
});

$io = $server->create();
$io->run();