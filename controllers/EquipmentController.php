<?php

namespace Grocy\Controllers;

use Grocy\Services\UserfieldsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EquipmentController extends BaseController
{
	protected $UserfieldsService;

	public function EditForm(Request $request, Response $response, array $args)
	{
		if ($args['equipmentId'] == 'new')
		{
			return $this->RenderPage($response, 'equipmentform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('equipment'),
				'locations' => $this->DB->locations()->orderBy('name', 'COLLATE NOCASE')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'equipmentform', [
				'equipment' => $this->DB->equipment($args['equipmentId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('equipment'),
				'locations' => $this->DB->locations()->orderBy('name', 'COLLATE NOCASE')
			]);
		}
	}

	public function Overview(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'equipment', [
			'equipment' => $this->DB->equipment()->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('equipment'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('equipment'),
			'locations' => $this->DB->locations()->orderBy('name', 'COLLATE NOCASE')
		]);
	}
}
