<?php

namespace Grocy\Controllers\Api;

use Grocy\Controllers\Users\User;
use Grocy\Services\TasksService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TasksApiController extends BaseApiController
{
	public function Current(Request $request, Response $response, array $args)
	{
		return $this->FilteredApiResponse($response, TasksService::GetInstance()->GetCurrent(), $request->getQueryParams());
	}

	public function MarkTaskAsCompleted(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_TASKS_MARK_COMPLETED);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		try
		{
			$doneTime = date('Y-m-d H:i:s');

			if (array_key_exists('done_time', $requestBody) && IsIsoDateTime($requestBody['done_time']))
			{
				$doneTime = $requestBody['done_time'];
			}

			TasksService::GetInstance()->MarkTaskAsCompleted($args['taskId'], $doneTime);
			return $this->EmptyApiResponse($response);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	public function UndoTask(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_TASKS_UNDO_EXECUTION);

		try
		{
			TasksService::GetInstance()->UndoTask($args['taskId']);
			return $this->EmptyApiResponse($response);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}
}
