<?php

declare(strict_types=1);

require_once __DIR__ . '/../packages/autoload.php';

use DI\Container;
use Grocy\Controllers\LoginController;
use Grocy\Services\SessionService;
use Slim\Http\Response;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

function assertLogout(bool $condition, string $message): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
}

$controllerReflection = new ReflectionClass(LoginController::class);
$controller = $controllerReflection->newInstanceWithoutConstructor();
$container = new Container();
$container->set('UrlManager', new class()
{
	public function ConstructUrl(string $path, bool $isResource = false): string
	{
		return $path;
	}
});

$containerProperty = $controllerReflection->getParentClass()->getProperty('AppContainer');
$containerProperty->setValue($controller, $container);

unset($_COOKIE[SessionService::SESSION_TOKEN_COOKIE_NAME_ACCESS]);
unset($_COOKIE[SessionService::SESSION_TOKEN_COOKIE_NAME_REMEMBER_ME]);

$request = (new ServerRequestFactory())->createServerRequest('GET', '/logout');
$response = new Response((new ResponseFactory())->createResponse(), new StreamFactory());
$result = $controller->Logout($request, $response, []);

assertLogout($result->getStatusCode() === 302, 'Logout without an access token should redirect');
assertLogout($result->getHeaderLine('Location') === '/', 'Logout should redirect to the application root');

echo "LogoutWithoutAccessToken: logout without cookies passed\n";
