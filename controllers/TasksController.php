<?php

namespace Grocy\Controllers;

use Grocy\Services\TasksService;
use Grocy\Services\UserfieldsService;
use Grocy\Services\UsersService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TasksController extends BaseController
{
	public function Overview(Request $request, Response $response, array $args)
	{
		$usersService = UsersService::GetInstance();
		$nextXDays = $usersService->GetUserSettings(GROCY_USER_ID)['tasks_due_soon_days'];

		if (isset($request->getQueryParams()['include_done']))
		{
			$tasks = $this->DB->tasks()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$tasks = TasksService::GetInstance()->GetCurrent();
		}

		foreach ($tasks as $task)
		{
			if (empty($task->due_date))
			{
				$task->due_type = '';
			}
			elseif ($task->due_date < date('Y-m-d 23:59:59', strtotime('-1 days')))
			{
				$task->due_type = 'overdue';
			}
			elseif ($task->due_date <= date('Y-m-d 23:59:59'))
			{
				$task->due_type = 'duetoday';
			}
			elseif ($nextXDays > 0 && $task->due_date <= date('Y-m-d 23:59:59', strtotime('+' . $nextXDays . ' days')))
			{
				$task->due_type = 'duesoon';
			}
		}

		return $this->RenderPage($response, 'tasks', [
			'tasks' => $tasks,
			'nextXDays' => $nextXDays,
			'taskCategories' => $this->DB->task_categories()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'users' => $usersService->GetUsersAsDto(),
			'userfields' => UserfieldsService::GetInstance()->GetFields('tasks'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('tasks')
		]);
	}

	public function TaskCategoriesList(Request $request, Response $response, array $args)
	{
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$categories = $this->DB->task_categories()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$categories = $this->DB->task_categories()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->RenderPage($response, 'taskcategories', [
			'taskCategories' => $categories,
			'userfields' => UserfieldsService::GetInstance()->GetFields('task_categories'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('task_categories')
		]);
	}

	public function TaskCategoryEditForm(Request $request, Response $response, array $args)
	{
		if ($args['categoryId'] == 'new')
		{
			return $this->RenderPage($response, 'taskcategoryform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('task_categories')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'taskcategoryform', [
				'category' => $this->DB->task_categories($args['categoryId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('task_categories')
			]);
		}
	}

	public function TaskEditForm(Request $request, Response $response, array $args)
	{
		if ($args['taskId'] == 'new')
		{
			return $this->RenderPage($response, 'taskform', [
				'mode' => 'create',
				'taskCategories' => $this->DB->task_categories()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'users' => $this->DB->users()->orderBy('username'),
				'userfields' => UserfieldsService::GetInstance()->GetFields('tasks')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'taskform', [
				'task' => $this->DB->tasks($args['taskId']),
				'mode' => 'edit',
				'taskCategories' => $this->DB->task_categories()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'users' => $this->DB->users()->orderBy('username'),
				'userfields' => UserfieldsService::GetInstance()->GetFields('tasks')
			]);
		}
	}

	public function TasksSettings(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'taskssettings');
	}
}
