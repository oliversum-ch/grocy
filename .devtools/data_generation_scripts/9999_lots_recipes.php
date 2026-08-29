<?php

// This is executed inside DatabaseMigrationService class/context

use Grocy\Services\RecipesService;

$recipesService = RecipesService::GetInstance();

for ($i = 1; $i <= 87; $i++)
{
	$recipesService->CopyRecipe(1);
	$recipesService->CopyRecipe(2);
	$recipesService->CopyRecipe(3);
	$recipesService->CopyRecipe(4);
	$recipesService->CopyRecipe(5);
}
