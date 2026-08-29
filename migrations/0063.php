<?php

// This is executed inside DatabaseMigrationService class/context

use Grocy\Services\DatabaseService;
use Grocy\Services\LocalizationService;

$localizationService = LocalizationService::GetInstance(GROCY_DEFAULT_LOCALE);
$db = DatabaseService::GetInstance()->GetDbConnection();

$defaultShoppingList = $db->shopping_lists()->where('id = 1')->fetch();
$defaultShoppingList->update([
	'name' => $localizationService->__t('Shopping list')
]);
