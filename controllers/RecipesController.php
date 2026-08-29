<?php

namespace Grocy\Controllers;

use Grocy\Helpers\Grocycode;
use Grocy\Services\RecipesService;
use Grocy\Services\StockService;
use Grocy\Services\UserfieldsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class RecipesController extends BaseController
{
	use GrocycodeTrait;

	public function MealPlan(Request $request, Response $response, array $args)
	{
		$start = date('Y-m-d');
		if (isset($request->getQueryParams()['start']) && IsIsoDate($request->getQueryParams()['start']))
		{
			$start = $request->getQueryParams()['start'];
		}

		$days = 6;
		if (isset($request->getQueryParams()['days']) && filter_var($request->getQueryParams()['days'], FILTER_VALIDATE_INT) !== false)
		{
			$days = $request->getQueryParams()['days'];
		}

		$mealPlanWhereTimespan = "day BETWEEN DATE('$start', '-$days days') AND DATE('$start', '+$days days')";

		$recipes = $this->DB->recipes()->where('type', RecipesService::RECIPE_TYPE_NORMAL)->orderBy('name', 'COLLATE NOCASE')->fetchAll();
		$events = [];
		foreach ($this->DB->meal_plan()->where($mealPlanWhereTimespan) as $mealPlanEntry)
		{
			$recipe = FindObjectInArrayByPropertyValue($recipes, 'id', $mealPlanEntry['recipe_id']);
			$title = '';

			if ($recipe !== null)
			{
				$title = $recipe->name;
			}

			$productDetails = null;
			if ($mealPlanEntry['product_id'] !== null)
			{
				$productDetails = StockService::GetInstance()->GetProductDetails($mealPlanEntry['product_id']);
			}

			$events[] = [
				'id' => $mealPlanEntry['id'],
				'title' => $title,
				'start' => $mealPlanEntry['day'],
				'date_format' => 'date',
				'recipe' => json_encode($recipe),
				'mealPlanEntry' => json_encode($mealPlanEntry),
				'type' => $mealPlanEntry['type'],
				'productDetails' => json_encode($productDetails)
			];
		}

		$weekRecipe = $this->DB->recipes()->where("type = 'mealplan-week' AND name = LTRIM(STRFTIME('%Y-%W', DATE('$start')), '0')")->fetch();
		$weekRecipeId = 0;
		if ($weekRecipe != null)
		{
			$weekRecipeId = $weekRecipe->id;
		}

		return $this->RenderPage($response, 'mealplan', [
			'fullcalendarEventSources' => $events,
			'recipes' => $recipes,
			'internalRecipes' => $this->DB->recipes()->where("id IN (SELECT recipe_id FROM meal_plan_internal_recipe_relation WHERE $mealPlanWhereTimespan) OR id = $weekRecipeId")->fetchAll(),
			'recipesResolved' => RecipesService::GetInstance()->GetRecipesResolved("recipe_id IN (SELECT recipe_id FROM meal_plan_internal_recipe_relation WHERE $mealPlanWhereTimespan) OR recipe_id = $weekRecipeId"),
			'products' => $this->DB->products()->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
			'mealplanSections' => $this->DB->meal_plan_sections()->orderBy('sort_number'),
			'usedMealplanSections' => $this->DB->meal_plan_sections()->where("id IN (SELECT section_id FROM meal_plan WHERE $mealPlanWhereTimespan)")->orderBy('sort_number'),
			'weekRecipe' => $weekRecipe
		]);
	}

	public function Overview(Request $request, Response $response, array $args)
	{
		$recipes = $this->DB->recipes()->where('type', RecipesService::RECIPE_TYPE_NORMAL)->orderBy('name', 'COLLATE NOCASE');
		$recipesResolved = RecipesService::GetInstance()->GetRecipesResolved('recipe_id > 0');

		$selectedRecipe = null;
		if (isset($request->getQueryParams()['recipe']))
		{
			$selectedRecipe = $this->DB->recipes($request->getQueryParams()['recipe']);
		}
		else
		{
			foreach ($recipes as $recipe)
			{
				$selectedRecipe = $recipe;
				break;
			}
		}

		$totalCosts = null;
		$totalCalories = null;
		if ($selectedRecipe)
		{
			$totalCosts = FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $selectedRecipe->id)->costs;
			$totalCalories = FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $selectedRecipe->id)->calories;
		}

		$viewData = [
			'recipes' => $recipes,
			'recipesResolved' => $recipesResolved,
			'recipePositionsResolved' => $this->DB->recipes_pos_resolved()->where('recipe_id', $selectedRecipe->id),
			'selectedRecipe' => $selectedRecipe,
			'products' => $this->DB->products(),
			'quantityUnits' => $this->DB->quantity_units(),
			'userfields' => UserfieldsService::GetInstance()->GetFields('recipes'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('recipes'),
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
			'selectedRecipeTotalCosts' => $totalCosts,
			'selectedRecipeTotalCalories' => $totalCalories,
			'mealplanSections' => $this->DB->meal_plan_sections()->orderBy('sort_number')
		];

		if ($selectedRecipe)
		{
			$selectedRecipeSubRecipes = $this->DB->recipes()->where('id IN (SELECT includes_recipe_id FROM recipes_nestings_resolved WHERE recipe_id = :1 AND includes_recipe_id != :1)', $selectedRecipe->id)->orderBy('name', 'COLLATE NOCASE')->fetchAll();

			$includedRecipeIdsAbsolute = [];
			$includedRecipeIdsAbsolute[] = $selectedRecipe->id;
			foreach ($selectedRecipeSubRecipes as $subRecipe)
			{
				$includedRecipeIdsAbsolute[] = $subRecipe->id;
			}

			// TODO: Why not directly use recipes_pos_resolved for all recipe positions here (parent and child)?
			// This view already correctly recolves child recipe amounts...
			$allRecipePositions = [];
			foreach ($includedRecipeIdsAbsolute as $id)
			{
				$allRecipePositions[$id] = $this->DB->recipes_pos_resolved()->where('recipe_id = :1 AND is_nested_recipe_pos = 0', $id)->orderBy('ingredient_group', 'ASC', 'product_group', 'ASC');
				foreach ($allRecipePositions[$id] as $pos)
				{
					if ($id != $selectedRecipe->id)
					{
						$pos2 = $this->DB->recipes_pos_resolved()->where('recipe_id = :1  AND recipe_pos_id = :2 AND is_nested_recipe_pos = 1', $selectedRecipe->id, $pos->recipe_pos_id)->fetch();
						$pos->recipe_amount = $pos2->recipe_amount;
						$pos->missing_amount = $pos2->missing_amount;
					}
				}
			}

			$viewData['selectedRecipeSubRecipes'] = $selectedRecipeSubRecipes;
			$viewData['includedRecipeIdsAbsolute'] = $includedRecipeIdsAbsolute;
			$viewData['allRecipePositions'] = $allRecipePositions;
		}

		return $this->RenderPage($response, 'recipes', $viewData);
	}

	public function RecipeEditForm(Request $request, Response $response, array $args)
	{
		$recipeId = $args['recipeId'];

		return $this->RenderPage($response, 'recipeform', [
			'recipe' => $this->DB->recipes($recipeId),
			'recipePositions' => $this->DB->recipes_pos()->where('recipe_id', $recipeId),
			'mode' => $recipeId == 'new' ? 'create' : 'edit',
			'products' => $this->DB->products()->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->DB->quantity_units(),
			'recipes' => $this->DB->recipes()->where('type', RecipesService::RECIPE_TYPE_NORMAL)->orderBy('name', 'COLLATE NOCASE'),
			'recipeNestings' => $this->DB->recipes_nestings()->where('recipe_id', $recipeId),
			'userfields' => UserfieldsService::GetInstance()->GetFields('recipes'),
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved()
		]);
	}

	public function RecipePosEditForm(Request $request, Response $response, array $args)
	{
		if ($args['recipePosId'] == 'new')
		{
			return $this->RenderPage($response, 'recipeposform', [
				'mode' => 'create',
				'recipe' => $this->DB->recipes($args['recipeId']),
				'recipePos' => new \stdClass(),
				'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'barcodes' => $this->DB->product_barcodes_comma_separated(),
				'quantityUnits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved()
			]);
		}
		else
		{
			return $this->RenderPage($response, 'recipeposform', [
				'mode' => 'edit',
				'recipe' => $this->DB->recipes($args['recipeId']),
				'recipePos' => $this->DB->recipes_pos($args['recipePosId']),
				'products' => $this->DB->products()->orderBy('name', 'COLLATE NOCASE'),
				'barcodes' => $this->DB->product_barcodes_comma_separated(),
				'quantityUnits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved()
			]);
		}
	}

	public function RecipesSettings(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'recipessettings');
	}

	public function MealPlanSectionEditForm(Request $request, Response $response, array $args)
	{
		if ($args['sectionId'] == 'new')
		{
			return $this->RenderPage($response, 'mealplansectionform', [
				'mode' => 'create'
			]);
		}
		else
		{
			return $this->RenderPage($response, 'mealplansectionform', [
				'mealplanSection' => $this->DB->meal_plan_sections($args['sectionId']),
				'mode' => 'edit'
			]);
		}
	}

	public function MealPlanSectionsList(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'mealplansections', [
			'mealplanSections' => $this->DB->meal_plan_sections()->where('id > 0')->orderBy('sort_number')
		]);
	}

	public function RecipeGrocycodeImage(Request $request, Response $response, array $args)
	{
		$gc = new Grocycode(Grocycode::RECIPE, $args['recipeId']);
		return $this->ServeGrocycodeImage($request, $response, $gc);
	}
}
