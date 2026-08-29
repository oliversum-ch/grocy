<?php

namespace Grocy\Controllers;

use Grocy\Helpers\Grocycode;
use Grocy\Services\BatteriesService;
use Grocy\Services\UserfieldsService;
use Grocy\Services\UsersService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BatteriesController extends BaseController
{
	use GrocycodeTrait;

	public function BatteriesList(Request $request, Response $response, array $args)
	{
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$batteries = $this->DB->batteries()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$batteries = $this->DB->batteries()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->RenderPage($response, 'batteries', [
			'batteries' => $batteries,
			'userfields' => UserfieldsService::GetInstance()->GetFields('batteries'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('batteries')
		]);
	}

	public function BatteriesSettings(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'batteriessettings');
	}

	public function BatteryEditForm(Request $request, Response $response, array $args)
	{
		if ($args['batteryId'] == 'new')
		{
			return $this->RenderPage($response, 'batteryform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('batteries')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'batteryform', [
				'battery' => $this->DB->batteries($args['batteryId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('batteries')
			]);
		}
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
			// Default 2 years
			$where = "tracked_time > DATE(DATE('now', 'localtime'), '-24 months')";
		}

		if (isset($request->getQueryParams()['battery']) && filter_var($request->getQueryParams()['battery'], FILTER_VALIDATE_INT) !== false)
		{
			$batteryId = $request->getQueryParams()['battery'];
			$where .= " AND battery_id = $batteryId";
		}

		return $this->RenderPage($response, 'batteriesjournal', [
			'chargeCycles' => $this->DB->battery_charge_cycles()->where($where)->orderBy('tracked_time', 'DESC'),
			'batteries' => $this->DB->batteries()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('battery_charge_cycles'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('battery_charge_cycles')
		]);
	}

	public function Overview(Request $request, Response $response, array $args)
	{
		$usersService = UsersService::GetInstance();
		$nextXDays = $usersService->GetUserSettings(GROCY_USER_ID)['batteries_due_soon_days'];

		$batteries = $this->DB->batteries()->where('active = 1');
		$currentBatteries = BatteriesService::GetInstance()->GetCurrent();
		foreach ($currentBatteries as $currentBattery)
		{
			if (FindObjectInArrayByPropertyValue($batteries, 'id', $currentBattery->battery_id)->charge_interval_days > 0)
			{
				if ($currentBattery->next_estimated_charge_time < date('Y-m-d H:i:s'))
				{
					$currentBattery->due_type = 'overdue';
				}
				elseif ($currentBattery->next_estimated_charge_time <= date('Y-m-d 23:59:59'))
				{
					$currentBattery->due_type = 'duetoday';
				}
				elseif ($nextXDays > 0 && $currentBattery->next_estimated_charge_time <= date('Y-m-d H:i:s', strtotime('+' . $nextXDays . ' days')))
				{
					$currentBattery->due_type = 'duesoon';
				}
			}
		}

		return $this->RenderPage($response, 'batteriesoverview', [
			'batteries' => $batteries,
			'current' => $currentBatteries,
			'nextXDays' => $nextXDays,
			'userfields' => UserfieldsService::GetInstance()->GetFields('batteries'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('batteries')
		]);
	}

	public function TrackChargeCycle(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'batterytracking', [
			'batteries' => $this->DB->batteries()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('battery_charge_cycles')
		]);
	}

	public function BatteryGrocycodeImage(Request $request, Response $response, array $args)
	{
		$gc = new Grocycode(Grocycode::BATTERY, $args['batteryId']);
		return $this->ServeGrocycodeImage($request, $response, $gc);
	}
}
