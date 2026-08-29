<?php

namespace Grocy\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class CorsMiddleware extends BaseMiddleware
{
	public function __invoke(Request $request, RequestHandler $handler): Response
	{
		if ($request->getMethod() == 'OPTIONS')
		{
			// Handle CORS preflight OPTIONS requests
			$response = $this->ResponseFactory->createResponse(204);
			$response = $response->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
			$response = $response->withHeader('Access-Control-Allow-Headers', '*');
		}
		else
		{
			$response = $handler->handle($request);
		}

		// Needs to be included with every request, not only for CORS preflight OPTIONS requests
		$response = $response->withHeader('Access-Control-Allow-Origin', '*');

		return $response;
	}
}
