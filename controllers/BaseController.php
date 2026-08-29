<?php

namespace Grocy\Controllers;

use DI\Container;
use Grocy\Controllers\Users\User;
use Grocy\Services\ApplicationService;
use Grocy\Services\DatabaseService;
use Grocy\Services\LocalizationService;
use Grocy\Services\UsersService;

class BaseController
{
	public function __construct(Container $container)
	{
		$this->AppContainer = $container;
		$this->View = $container->get('view');
		$this->DB = DatabaseService::GetInstance()->GetDbConnection();
	}

	protected $AppContainer;
	protected $View;
	protected $DB;

	protected function Render($response, $viewName, $data = [])
	{
		$container = $this->AppContainer;

		$versionInfo = ApplicationService::GetInstance()->GetInstalledVersion();
		$this->View->set('version', $versionInfo->Version);

		$localizationService = LocalizationService::GetInstance();
		$this->View->set('__t', function (string $text, ...$placeholderValues) use ($localizationService)
		{
			return $localizationService->__t($text, $placeholderValues);
		});
		$this->View->set('__n', function ($number, $singularForm, $pluralForm, $isQu = false) use ($localizationService)
		{
			return $localizationService->__n($number, $singularForm, $pluralForm, $isQu);
		});
		$this->View->set('LocalizationStrings', $localizationService->GetPoAsJsonString());
		$this->View->set('LocalizationStringsQu', $localizationService->GetPoAsJsonStringQu());

		// TODO: Better handle this generically based on the current language (header in .po file?)
		$dir = 'ltr';
		if (GROCY_LOCALE == 'he_IL')
		{
			$dir = 'rtl';
		}
		$this->View->set('dir', $dir);

		$this->View->set('U', function ($relativePath, $isResource = false) use ($container)
		{
			return $container->get('UrlManager')->ConstructUrl($relativePath, $isResource);
		});

		$embedded = false;
		if (isset($_GET['embedded']))
		{
			$embedded = true;
		}
		$this->View->set('embedded', $embedded);

		$constants = get_defined_constants();
		foreach ($constants as $constant => $value)
		{
			if (substr($constant, 0, 19) !== 'GROCY_FEATURE_FLAG_')
			{
				unset($constants[$constant]);
			}
		}
		$this->View->set('featureFlags', $constants);

		if (GROCY_AUTHENTICATED)
		{
			$this->View->set('permissions', User::PermissionList());

			$decimalPlacesAmounts = UsersService::GetInstance()->GetUserSetting(GROCY_USER_ID, 'stock_decimal_places_amounts');
			if ($decimalPlacesAmounts <= 0)
			{
				$defaultMinAmount = 1;
			}
			else
			{
				$defaultMinAmount = '0.' . str_repeat('0', $decimalPlacesAmounts - 1) . '1';
			}
			$this->View->set('DEFAULT_MIN_AMOUNT', $defaultMinAmount);
		}

		$this->View->set('viewName', $viewName);

		return $this->View->Render($response, $viewName, $data);
	}

	protected function RenderPage($response, $viewName, $data = [])
	{
		$this->View->set('userentitiesForSidebar', $this->DB->userentities()->where('show_in_sidebar_menu = 1')->orderBy('name'));
		try
		{
			$usersService = UsersService::GetInstance();
			if (defined('GROCY_USER_ID'))
			{
				$this->View->set('userSettings', $usersService->GetUserSettings(GROCY_USER_ID));
			}
			else
			{
				$this->View->set('userSettings', null);
			}
		}
		catch (\Exception $ex)
		{
			// Happens when database is not initialised or migrated...
		}

		return $this->Render($response, $viewName, $data);
	}
}
