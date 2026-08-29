<?php

namespace Grocy\Services;

use Gettext\Translation;
use Gettext\Translations;
use Gettext\Translator;

class LocalizationService extends BaseService
{
	public function __construct(string $locale)
	{
		parent::__construct();

		$this->Locale = $locale;
		$this->LoadLocalizations($locale);
	}

	protected $Po;
	protected $PoQu;
	protected $Pot;
	protected $PotMain;
	protected $Translator;
	protected $TranslatorQu;
	protected $Locale;
	private static $InstanceMap = [];

	public function CheckAndAddMissingTranslationToPot($text)
	{
		if (GROCY_MODE === 'dev')
		{
			if ($this->Pot->find('', $text) === false && empty($text) === false)
			{
				$translation = new Translation('', $text);
				$this->PotMain[] = $translation;
				$this->PotMain->toPoFile(__DIR__ . '/../localization/strings.pot');
			}
		}
	}

	public function GetPluralCount()
	{
		if ($this->Po->getHeader(Translations::HEADER_PLURAL) !== null)
		{
			return intval($this->Po->getPluralForms()[0]);
		}
		else
		{
			return 2;
		}
	}

	public function GetPluralDefinition()
	{
		if ($this->Po->getHeader(Translations::HEADER_PLURAL) !== null)
		{
			return $this->Po->getPluralForms()[1];
		}
		else
		{
			return '(n != 1)';
		}
	}

	public function GetPoAsJsonString()
	{
		return $this->Po->toJsonString();
	}

	public function GetPoAsJsonStringQu()
	{
		return $this->PoQu->toJsonString();
	}

	public function __n($number, $singularForm, $pluralForm, $isQu = false)
	{
		$this->CheckAndAddMissingTranslationToPot($singularForm);

		if (empty($pluralForm))
		{
			$pluralForm = $singularForm;
		}

		if ($isQu)
		{
			return sprintf($this->TranslatorQu->ngettext($singularForm, $pluralForm, abs(floatval($number))), $number);
		}
		else
		{
			return sprintf($this->Translator->ngettext($singularForm, $pluralForm, abs(floatval($number))), $number);
		}
	}

	public function __t($text, ...$placeholderValues)
	{
		$this->CheckAndAddMissingTranslationToPot($text);

		if (func_num_args() === 1)
		{
			return $this->Translator->gettext($text);
		}
		else
		{
			if (is_array(...$placeholderValues))
			{
				return vsprintf($this->Translator->gettext($text), ...$placeholderValues);
			}
			else
			{
				return sprintf($this->Translator->gettext($text), array_shift($placeholderValues));
			}
		}
	}

	public static function GetInstance(string $locale = '')
	{
		if (empty($locale))
		{
			$locale = GROCY_LOCALE;
		}

		if (!in_array($locale, self::$InstanceMap))
		{
			self::$InstanceMap[$locale] = new self($locale);
		}

		return self::$InstanceMap[$locale];
	}

	private function LoadLocalizations()
	{
		$locale = $this->Locale;

		if (GROCY_MODE === 'dev')
		{
			$this->PotMain = Translations::fromPoFile(__DIR__ . '/../localization/strings.pot');

			$this->Pot = Translations::fromPoFile(__DIR__ . '/../localization/chore_period_types.pot');
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/chore_assignment_types.pot'));
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/component_translations.pot'));
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/stock_transaction_types.pot'));
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/strings.pot'));
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/userfield_types.pot'));
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/permissions.pot'));
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/locales.pot'));
			$this->Pot = $this->Pot->mergeWith(Translations::fromPoFile(__DIR__ . '/../localization/demo_data.pot'));
		}

		$this->Po = Translations::fromPoFile(__DIR__ . "/../localization/$locale/strings.po");

		if (file_exists(__DIR__ . "/../localization/$locale/chore_assignment_types.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/chore_assignment_types.po"));
		}

		if (file_exists(__DIR__ . "/../localization/$locale/component_translations.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/component_translations.po"));
		}

		if (file_exists(__DIR__ . "/../localization/$locale/stock_transaction_types.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/stock_transaction_types.po"));
		}

		if (file_exists(__DIR__ . "/../localization/$locale/chore_period_types.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/chore_period_types.po"));
		}

		if (file_exists(__DIR__ . "/../localization/$locale/userfield_types.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/userfield_types.po"));
		}

		if (file_exists(__DIR__ . "/../localization/$locale/permissions.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/permissions.po"));
		}

		if (file_exists(__DIR__ . "/../localization/$locale/locales.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/locales.po"));
		}

		if (GROCY_MODE !== 'production' && file_exists(__DIR__ . "/../localization/$locale/demo_data.po"))
		{
			$this->Po = $this->Po->mergeWith(Translations::fromPoFile(__DIR__ . "/../localization/$locale/demo_data.po"));
		}

		$this->Translator = new Translator();
		$this->Translator->loadTranslations($this->Po);

		$this->PoQu = new Translations();

		$quantityUnits = null;
		try
		{
			$quantityUnits = $this->DB->quantity_units()->where('active = 1')->fetchAll();
		}
		catch (\Exception $ex)
		{
			// Happens when database is not initialised or migrated...
		}

		if ($quantityUnits !== null)
		{
			$this->PoQu->setHeader(Translations::HEADER_PLURAL, $this->Po->getHeader(Translations::HEADER_PLURAL));

			foreach ($quantityUnits as $quantityUnit)
			{
				$translation = new Translation('', $quantityUnit['name']);
				$translation->setTranslation($quantityUnit['name']);
				$translation->setPlural($quantityUnit['name_plural']);
				$translation->setPluralTranslations(preg_split('/\r\n|\r|\n/', $quantityUnit['plural_forms'] ?? ''));

				$this->PoQu[] = $translation;
			}

			$this->TranslatorQu = new Translator();
			$this->TranslatorQu->loadTranslations($this->PoQu);
		};
	}
}
