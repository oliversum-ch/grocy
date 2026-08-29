<?php

namespace Grocy\Services;

class BaseService
{
	public function __construct()
	{
		$this->DB = DatabaseService::GetInstance()->GetDbConnection();
	}

	private static $Instances = [];
	protected $DB;

	public static function GetInstance()
	{
		$className = get_called_class();
		if (!isset(self::$Instances[$className]))
		{
			self::$Instances[$className] = new $className();
		}

		return self::$Instances[$className];
	}
}
