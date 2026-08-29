<?php

namespace Grocy\Controllers;

use Grocy\Services\CalendarService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CalendarController extends BaseController
{
	public function Overview(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'calendar', [
			'fullcalendarEventSources' => CalendarService::GetInstance()->GetEvents()
		]);
	}
}
