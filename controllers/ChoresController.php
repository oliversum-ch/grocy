<?php

namespace Grocy\Controllers;

use Grocy\Helpers\Grocycode;
use Grocy\Services\ChoresService;
use Grocy\Services\UserfieldsService;
use Grocy\Services\UsersService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ChoresController extends BaseController
{
	use GrocycodeTrait;

	public function ChoreEditForm(Request $request, Response $response, array $args)
	{
		$usersService = UsersService::GetInstance();
		$users = $usersService->GetUsersAsDto();

		if ($args['choreId'] == 'new')
		{
			return $this->RenderPage($response, 'choreform', [
				'periodTypes' => GetClassConstants('\Grocy\Services\ChoresService', 'CHORE_PERIOD_TYPE_'),
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('chores'),
				'assignmentTypes' => GetClassConstants('\Grocy\Services\ChoresService', 'CHORE_ASSIGNMENT_TYPE_'),
				'users' => $users,
				'products' => $this->DB->products()->orderBy('name', 'COLLATE NOCASE')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'choreform', [
				'chore' => $this->DB->chores($args['choreId']),
				'periodTypes' => GetClassConstants('\Grocy\Services\ChoresService', 'CHORE_PERIOD_TYPE_'),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('chores'),
				'assignmentTypes' => GetClassConstants('\Grocy\Services\ChoresService', 'CHORE_ASSIGNMENT_TYPE_'),
				'users' => $users,
				'products' => $this->DB->products()->orderBy('name', 'COLLATE NOCASE')
			]);
		}
	}

	public function ChoresList(Request $request, Response $response, array $args)
	{
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$chores = $this->DB->chores()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$chores = $this->DB->chores()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->RenderPage($response, 'chores', [
			'chores' => $chores,
			'userfields' => UserfieldsService::GetInstance()->GetFields('chores'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('chores')
		]);
	}

	public function ChoresSettings(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'choressettings');
	}

	public function Journal(Request $request, Response $response, array $args)
	{
		if (isset($request->getQueryParams()['months']) && filter_var($request->getQueryParams()['months'], FILTER_VALIDATE_INT) !== false)
		{
			$months = $request->getQueryParams()['months'];
			$where = "tracked_time > DATE(DATE('now', 'localtime'), '-$months months')";
		}
		else
		{
			// Default 1 year
			$where = "tracked_time > DATE(DATE('now', 'localtime'), '-12 months')";
		}

		if (isset($request->getQueryParams()['chore']) && filter_var($request->getQueryParams()['chore'], FILTER_VALIDATE_INT) !== false)
		{
			$choreId = $request->getQueryParams()['chore'];
			$where .= " AND chore_id = $choreId";
		}

		return $this->RenderPage($response, 'choresjournal', [
			'choresLog' => $this->DB->chores_log()->where($where)->orderBy('tracked_time', 'DESC'),
			'chores' => $this->DB->chores()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'users' => $this->DB->users()->orderBy('username'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('chores_log'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('chores_log')
		]);
	}

	public function Overview(Request $request, Response $response, array $args)
	{
		$usersService = UsersService::GetInstance();
		$nextXDays = $usersService->GetUserSettings(GROCY_USER_ID)['chores_due_soon_days'];

		$chores = $this->DB->chores()->orderBy('name', 'COLLATE NOCASE');
		$currentChores = ChoresService::GetInstance()->GetCurrent();
		foreach ($currentChores as $currentChore)
		{
			if (!empty($currentChore->next_estimated_execution_time))
			{
				if ($currentChore->next_estimated_execution_time < date('Y-m-d H:i:s'))
				{
					$currentChore->due_type = 'overdue';
				}
				elseif ($currentChore->next_estimated_execution_time <= date('Y-m-d 23:59:59'))
				{
					$currentChore->due_type = 'duetoday';
				}
				elseif ($nextXDays > 0 && $currentChore->next_estimated_execution_time <= date('Y-m-d H:i:s', strtotime('+' . $nextXDays . ' days')))
				{
					$currentChore->due_type = 'duesoon';
				}
			}
		}

		return $this->RenderPage($response, 'choresoverview', [
			'chores' => $chores,
			'currentChores' => $currentChores,
			'nextXDays' => $nextXDays,
			'userfields' => UserfieldsService::GetInstance()->GetFields('chores'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('chores'),
			'users' => $usersService->GetUsersAsDto()
		]);
	}

	public function TrackChoreExecution(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'choretracking', [
			'chores' => $this->DB->chores()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'users' => $this->DB->users()->orderBy('username'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('chores_log'),
		]);
	}

	public function ChoreGrocycodeImage(Request $request, Response $response, array $args)
	{
		$gc = new Grocycode(Grocycode::CHORE, $args['choreId']);
		return $this->ServeGrocycodeImage($request, $response, $gc);
	}
}
