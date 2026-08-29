<?php

namespace Grocy\Controllers;

use DI\Container;
use Grocy\Helpers\Grocycode;
use Grocy\Services\LocalizationService;
use Grocy\Services\RecipesService;
use Grocy\Services\StockService;
use Grocy\Services\UserfieldsService;
use Grocy\Services\UsersService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StockController extends BaseController
{
	use GrocycodeTrait;

	public function __construct(Container $container)
	{
		parent::__construct($container);

		try
		{
			$externalBarcodeLookupPluginName = StockService::GetInstance()->GetExternalBarcodeLookupPluginName();
		}
		catch (\Exception)
		{
			$externalBarcodeLookupPluginName = '';
		}
		finally
		{
			$this->View->set('ExternalBarcodeLookupPluginName', $externalBarcodeLookupPluginName);
		}
	}

	public function Consume(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'consume', [
			'products' => $this->DB->products()->where('active = 1')->where('id IN (SELECT product_id from stock_current WHERE amount_aggregated > 0)')->orderBy('name'),
			'barcodes' => $this->DB->product_barcodes_comma_separated(),
			'recipes' => $this->DB->recipes()->where('type', RecipesService::RECIPE_TYPE_NORMAL)->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->DB->locations()->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved()
		]);
	}

	public function Inventory(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'inventory', [
			'products' => $this->DB->products()->where('active = 1 AND no_own_stock = 0')->orderBy('name', 'COLLATE NOCASE'),
			'barcodes' => $this->DB->product_barcodes_comma_separated(),
			'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
			'userfields' => UserfieldsService::GetInstance()->GetFields('stock')
		]);
	}

	public function Journal(Request $request, Response $response, array $args)
	{
		if (isset($request->getQueryParams()['months']) && filter_var($request->getQueryParams()['months'], FILTER_VALIDATE_INT) !== false)
		{
			$months = $request->getQueryParams()['months'];
			if (intval($months) === 0)
			{
				$where = "row_created_timestamp >= DATE('now', 'localtime')";
			}
			else
			{
				$where = "row_created_timestamp > DATE(DATE('now', 'localtime'), '-$months months')";
			}
		}
		else
		{
			// Default today
			$where = "row_created_timestamp >= DATE('now', 'localtime')";
		}

		if (isset($request->getQueryParams()['product']) && filter_var($request->getQueryParams()['product'], FILTER_VALIDATE_INT) !== false)
		{
			$productId = $request->getQueryParams()['product'];
			$where .= " AND product_id = $productId";
		}

		$usersService = UsersService::GetInstance();

		return $this->RenderPage($response, 'stockjournal', [
			'stockLog' => $this->DB->uihelper_stock_journal()->where($where)->orderBy('row_created_timestamp', 'DESC'),
			'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->DB->locations()->orderBy('name', 'COLLATE NOCASE'),
			'users' => $usersService->GetUsersAsDto(),
			'transactionTypes' => GetClassConstants('\Grocy\Services\StockService', 'TRANSACTION_TYPE_'),
			'userfieldsStock' => UserfieldsService::GetInstance()->GetFields('stock'),
			'userfieldValuesStock' => UserfieldsService::GetInstance()->GetAllValues('stock')
		]);
	}

	public function LocationContentSheet(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'locationcontentsheet', [
			'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->DB->locations()->orderBy('name', 'COLLATE NOCASE'),
			'currentStockLocationContent' => StockService::GetInstance()->GetCurrentStockLocationContent(isset($request->getQueryParams()['include_out_of_stock']))
		]);
	}

	public function LocationEditForm(Request $request, Response $response, array $args)
	{
		if ($args['locationId'] == 'new')
		{
			return $this->RenderPage($response, 'locationform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('locations')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'locationform', [
				'location' => $this->DB->locations($args['locationId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('locations')
			]);
		}
	}

	public function LocationsList(Request $request, Response $response, array $args)
	{
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$locations = $this->DB->locations()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$locations = $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->RenderPage($response, 'locations', [
			'locations' => $locations,
			'userfields' => UserfieldsService::GetInstance()->GetFields('locations'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('locations')
		]);
	}

	public function Overview(Request $request, Response $response, array $args)
	{
		$usersService = UsersService::GetInstance();
		$userSettings = $usersService->GetUserSettings(GROCY_USER_ID);
		$nextXDays = $userSettings['stock_due_soon_days'];

		$where = 'is_in_stock_or_below_min_stock = 1';
		if (boolval($userSettings['stock_overview_show_all_out_of_stock_products']))
		{
			$where = '1=1';
		}

		return $this->RenderPage($response, 'stockoverview', [
			'currentStock' => $this->DB->uihelper_stock_current_overview()->where($where),
			'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'currentStockLocations' => StockService::GetInstance()->GetCurrentStockLocations(),
			'nextXDays' => $nextXDays,
			'productGroups' => $this->DB->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('products'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('products')
		]);
	}

	public function ProductBarcodesEditForm(Request $request, Response $response, array $args)
	{
		$product = null;
		if (isset($request->getQueryParams()['product']))
		{
			$product = $this->DB->products($request->getQueryParams()['product']);
		}

		if ($args['productBarcodeId'] == 'new')
		{
			return $this->RenderPage($response, 'productbarcodeform', [
				'mode' => 'create',
				'barcodes' => $this->DB->product_barcodes()->orderBy('barcode'),
				'product' => $product,
				'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
				'userfields' => UserfieldsService::GetInstance()->GetFields('product_barcodes')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'productbarcodeform', [
				'mode' => 'edit',
				'barcode' => $this->DB->product_barcodes($args['productBarcodeId']),
				'product' => $product,
				'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
				'userfields' => UserfieldsService::GetInstance()->GetFields('product_barcodes')
			]);
		}
	}

	public function ProductEditForm(Request $request, Response $response, array $args)
	{
		if ($args['productId'] == 'new')
		{
			$quantityunits = $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');

			return $this->RenderPage($response, 'productform', [
				'locations' => $this->DB->locations()->where('active = 1')->orderBy('name'),
				'barcodes' => $this->DB->product_barcodes()->orderBy('barcode'),
				'quantityunitsAll' => $quantityunits,
				'quantityunitsReferenced' => $quantityunits,
				'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'productgroups' => $this->DB->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'userfields' => UserfieldsService::GetInstance()->GetFields('products'),
				'products' => $this->DB->products()->where('parent_product_id IS NULL and active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'isSubProductOfOthers' => false,
				'mode' => 'create'
			]);
		}
		else
		{
			$product = $this->DB->products($args['productId']);

			return $this->RenderPage($response, 'productform', [
				'product' => $product,
				'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'barcodes' => $this->DB->product_barcodes()->orderBy('barcode'),
				'quantityunitsAll' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'quantityunitsReferenced' => $this->DB->quantity_units()->where('id IN (SELECT to_qu_id FROM cache__quantity_unit_conversions_resolved WHERE product_id = :1) OR NOT EXISTS(SELECT 1 FROM stock_log WHERE product_id = :1)', $product->id)->orderBy('name', 'COLLATE NOCASE'),
				'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'productgroups' => $this->DB->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'userfields' => UserfieldsService::GetInstance()->GetFields('products'),
				'products' => $this->DB->products()->where('id != :1 AND parent_product_id IS NULL and active = 1', $product->id)->orderBy('name', 'COLLATE NOCASE'),
				'isSubProductOfOthers' => $this->DB->products()->where('parent_product_id = :1', $product->id)->count() !== 0,
				'mode' => 'edit',
				'quConversions' => $this->DB->quantity_unit_conversions()->where('product_id', $product->id),
				'productBarcodeUserfields' => UserfieldsService::GetInstance()->GetFields('product_barcodes'),
				'productBarcodeUserfieldValues' => UserfieldsService::GetInstance()->GetAllValues('product_barcodes')
			]);
		}
	}

	public function ProductGrocycodeImage(Request $request, Response $response, array $args)
	{
		$gc = new Grocycode(Grocycode::PRODUCT, $args['productId']);
		return $this->ServeGrocycodeImage($request, $response, $gc);
	}

	public function ProductGroupEditForm(Request $request, Response $response, array $args)
	{
		if ($args['productGroupId'] == 'new')
		{
			return $this->RenderPage($response, 'productgroupform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('product_groups')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'productgroupform', [
				'group' => $this->DB->product_groups($args['productGroupId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('product_groups')
			]);
		}
	}

	public function ProductGroupsList(Request $request, Response $response, array $args)
	{
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$productGroups = $this->DB->product_groups()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$productGroups = $this->DB->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->RenderPage($response, 'productgroups', [
			'productGroups' => $productGroups,
			'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('product_groups'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('product_groups')
		]);
	}

	public function ProductsList(Request $request, Response $response, array $args)
	{
		$products = $this->DB->products();
		if (!isset($request->getQueryParams()['include_disabled']))
		{
			$products = $products->where('active = 1');
		}

		if (isset($request->getQueryParams()['filter']))
		{
			if ($request->getQueryParams()['filter'] == 'only_in_stock')
			{
				$products = $products->where('id IN (SELECT product_id from stock_current WHERE amount_aggregated > 0)');
			}
			elseif ($request->getQueryParams()['filter'] == 'only_out_of_stock')
			{
				$products = $products->where('id NOT IN (SELECT product_id from stock_current WHERE amount_aggregated > 0)');
			}
		}

		$products = $products->orderBy('name', 'COLLATE NOCASE');

		return $this->RenderPage($response, 'products', [
			'products' => $products,
			'locations' => $this->DB->locations()->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'productGroups' => $this->DB->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'shoppingLocations' => $this->DB->shopping_locations()->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('products'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('products')
		]);
	}

	public function Purchase(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'purchase', [
			'products' => $this->DB->products()->where('active = 1 AND no_own_stock = 0')->orderBy('name', 'COLLATE NOCASE'),
			'barcodes' => $this->DB->product_barcodes_comma_separated(),
			'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
			'userfields' => UserfieldsService::GetInstance()->GetFields('stock')
		]);
	}

	public function QuantityUnitConversionEditForm(Request $request, Response $response, array $args)
	{
		$product = null;
		if (isset($request->getQueryParams()['product']))
		{
			$product = $this->DB->products($request->getQueryParams()['product']);
		}

		$defaultQuUnit = null;

		if (isset($request->getQueryParams()['qu-unit']))
		{
			$defaultQuUnit = $this->DB->quantity_units($request->getQueryParams()['qu-unit']);
		}

		if ($args['quConversionId'] == 'new')
		{
			return $this->RenderPage($response, 'quantityunitconversionform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('quantity_unit_conversions'),
				'quantityunits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'product' => $product,
				'defaultQuUnit' => $defaultQuUnit
			]);
		}
		else
		{
			return $this->RenderPage($response, 'quantityunitconversionform', [
				'quConversion' => $this->DB->quantity_unit_conversions($args['quConversionId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('quantity_unit_conversions'),
				'quantityunits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'product' => $product,
				'defaultQuUnit' => $defaultQuUnit
			]);
		}
	}

	public function QuantityUnitEditForm(Request $request, Response $response, array $args)
	{
		if ($args['quantityunitId'] == 'new')
		{
			return $this->RenderPage($response, 'quantityunitform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('quantity_units'),
				'pluralCount' => LocalizationService::GetInstance()->GetPluralCount(),
				'pluralRule' => LocalizationService::GetInstance()->GetPluralDefinition()
			]);
		}
		else
		{
			$quantityUnit = $this->DB->quantity_units($args['quantityunitId']);

			return $this->RenderPage($response, 'quantityunitform', [
				'quantityUnit' => $quantityUnit,
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('quantity_units'),
				'pluralCount' => LocalizationService::GetInstance()->GetPluralCount(),
				'pluralRule' => LocalizationService::GetInstance()->GetPluralDefinition(),
				'defaultQuConversions' => $this->DB->quantity_unit_conversions()->where('from_qu_id = :1 AND product_id IS NULL', $quantityUnit->id),
				'quantityUnits' => $this->DB->quantity_units()
			]);
		}
	}

	public function QuantityUnitPluralFormTesting(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'quantityunitpluraltesting', [
			'quantityUnits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE')
		]);
	}

	public function QuantityUnitsList(Request $request, Response $response, array $args)
	{
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$quantityUnits = $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$quantityUnits = $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->RenderPage($response, 'quantityunits', [
			'quantityunits' => $quantityUnits,
			'userfields' => UserfieldsService::GetInstance()->GetFields('quantity_units'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('quantity_units')
		]);
	}

	public function ShoppingList(Request $request, Response $response, array $args)
	{
		$listId = 1;
		if (isset($request->getQueryParams()['list']))
		{
			$listId = $request->getQueryParams()['list'];
		}

		return $this->RenderPage($response, 'shoppinglist', [
			'listItems' => $this->DB->uihelper_shopping_list()->where('shopping_list_id = :1', $listId)->orderBy('product_name', 'COLLATE NOCASE'),
			'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'missingProducts' => StockService::GetInstance()->GetMissingProducts(),
			'shoppingLists' => $this->DB->shopping_lists_view()->orderBy('name', 'COLLATE NOCASE'),
			'selectedShoppingListId' => $listId,
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
			'productUserfields' => UserfieldsService::GetInstance()->GetFields('products'),
			'productUserfieldValues' => UserfieldsService::GetInstance()->GetAllValues('products'),
			'productGroupUserfields' => UserfieldsService::GetInstance()->GetFields('product_groups'),
			'productGroupUserfieldValues' => UserfieldsService::GetInstance()->GetAllValues('product_groups'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_list'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('shopping_list')
		]);
	}

	public function ShoppingListEditForm(Request $request, Response $response, array $args)
	{
		if ($args['listId'] == 'new')
		{
			return $this->RenderPage($response, 'shoppinglistform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_lists')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'shoppinglistform', [
				'shoppingList' => $this->DB->shopping_lists($args['listId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_lists')
			]);
		}
	}

	public function ShoppingListItemEditForm(Request $request, Response $response, array $args)
	{
		if ($args['itemId'] == 'new')
		{
			return $this->RenderPage($response, 'shoppinglistitemform', [
				'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'barcodes' => $this->DB->product_barcodes_comma_separated(),
				'shoppingLists' => $this->DB->shopping_lists()->orderBy('name', 'COLLATE NOCASE'),
				'mode' => 'create',
				'quantityUnits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
				'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_list')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'shoppinglistitemform', [
				'listItem' => $this->DB->shopping_list($args['itemId']),
				'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'barcodes' => $this->DB->product_barcodes_comma_separated(),
				'shoppingLists' => $this->DB->shopping_lists()->orderBy('name', 'COLLATE NOCASE'),
				'mode' => 'edit',
				'quantityUnits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
				'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_list')
			]);
		}
	}

	public function ShoppingListSettings(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'shoppinglistsettings', [
			'shoppingLists' => $this->DB->shopping_lists()->orderBy('name', 'COLLATE NOCASE')
		]);
	}

	public function ShoppingLocationEditForm(Request $request, Response $response, array $args)
	{
		if ($args['shoppingLocationId'] == 'new')
		{
			return $this->RenderPage($response, 'shoppinglocationform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_locations')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'shoppinglocationform', [
				'shoppingLocation' => $this->DB->shopping_locations($args['shoppingLocationId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_locations')
			]);
		}
	}

	public function ShoppingLocationsList(Request $request, Response $response, array $args)
	{
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$shoppingLocations = $this->DB->shopping_locations()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$shoppingLocations = $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->RenderPage($response, 'shoppinglocations', [
			'shoppinglocations' => $shoppingLocations,
			'userfields' => UserfieldsService::GetInstance()->GetFields('shopping_locations'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('shopping_locations')
		]);
	}

	public function StockEntryEditForm(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'stockentryform', [
			'stockEntry' => $this->DB->stock()->where('id', $args['entryId'])->fetch(),
			'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('stock')
		]);
	}

	public function StockEntryGrocycodeImage(Request $request, Response $response, array $args)
	{
		$stockEntry = $this->DB->stock()->where('id', $args['entryId'])->fetch();
		$gc = new Grocycode(Grocycode::PRODUCT, $stockEntry->product_id, [$stockEntry->stock_id]);
		return $this->ServeGrocycodeImage($request, $response, $gc);
	}

	public function StockEntryGrocycodeLabel(Request $request, Response $response, array $args)
	{
		$stockEntry = $this->DB->stock()->where('id', $args['entryId'])->fetch();
		return $this->RenderPage($response, 'stockentrylabel', [
			'stockEntry' => $stockEntry,
			'product' => $this->DB->products($stockEntry->product_id),
		]);
	}

	public function StockSettings(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'stocksettings', [
			'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'productGroups' => $this->DB->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE')
		]);
	}

	public function Stockentries(Request $request, Response $response, array $args)
	{
		$usersService = UsersService::GetInstance();
		$nextXDays = $usersService->GetUserSettings(GROCY_USER_ID)['stock_due_soon_days'];

		return $this->RenderPage($response, 'stockentries', [
			'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'shoppinglocations' => $this->DB->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'stockEntries' => $this->DB->uihelper_stock_entries()->orderBy('product_id'),
			'currentStockLocations' => StockService::GetInstance()->GetCurrentStockLocations(),
			'nextXDays' => $nextXDays,
			'userfieldsProducts' => UserfieldsService::GetInstance()->GetFields('products'),
			'userfieldValuesProducts' => UserfieldsService::GetInstance()->GetAllValues('products'),
			'userfieldsStock' => UserfieldsService::GetInstance()->GetFields('stock'),
			'userfieldValuesStock' => UserfieldsService::GetInstance()->GetAllValues('stock')
		]);
	}

	public function Transfer(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'transfer', [
			'products' => $this->DB->products()->where('active = 1')->where('no_own_stock = 0 AND id IN (SELECT product_id from stock_current WHERE amount_aggregated > 0)')->orderBy('name', 'COLLATE NOCASE'),
			'barcodes' => $this->DB->product_barcodes_comma_separated(),
			'locations' => $this->DB->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved()
		]);
	}

	public function JournalSummary(Request $request, Response $response, array $args)
	{
		$entries = $this->DB->uihelper_stock_journal_summary();
		if (isset($request->getQueryParams()['product_id']))
		{
			$entries = $entries->where('product_id', $request->getQueryParams()['product_id']);
		}
		if (isset($request->getQueryParams()['user_id']))
		{
			$entries = $entries->where('user_id', $request->getQueryParams()['user_id']);
		}
		if (isset($request->getQueryParams()['transaction_type']))
		{
			$entries = $entries->where('transaction_type', $request->getQueryParams()['transaction_type']);
		}

		$usersService = UsersService::GetInstance();
		return $this->RenderPage($response, 'stockjournalsummary', [
			'entries' => $entries,
			'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'users' => $usersService->GetUsersAsDto(),
			'transactionTypes' => GetClassConstants('\Grocy\Services\StockService', 'TRANSACTION_TYPE_')
		]);
	}

	public function QuantityUnitConversionsResolved(Request $request, Response $response, array $args)
	{
		$product = null;
		if (isset($request->getQueryParams()['product']))
		{
			$product = $this->DB->products($request->getQueryParams()['product']);
			$quantityUnitConversionsResolved = $this->DB->cache__quantity_unit_conversions_resolved()->where('product_id', $product->id);
		}
		else
		{
			$quantityUnitConversionsResolved = $this->DB->cache__quantity_unit_conversions_resolved()->where('product_id IS NULL');
		}

		return $this->RenderPage($response, 'quantityunitconversionsresolved', [
			'product' => $product,
			'quantityUnits' => $this->DB->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $quantityUnitConversionsResolved
		]);
	}
}
