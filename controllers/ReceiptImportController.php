<?php

namespace Grocy\Controllers;

use Grocy\Controllers\Users\User;
use Grocy\Services\ReceiptImportService;
use Grocy\Services\StockService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReceiptImportController extends BaseController
{
	public function Overview(Request $request, Response $response, array $args)
	{
		User::checkPermission($request, User::PERMISSION_STOCK_PURCHASE);
		try
		{
			$externalBarcodeLookupPluginName = StockService::GetInstance()->GetExternalBarcodeLookupPluginName();
		}
		catch (\Throwable)
		{
			$externalBarcodeLookupPluginName = '';
		}

		$receiptImportService = ReceiptImportService::GetInstance();
		return $this->RenderPage($response, 'receiptimport', [
			'products' => $receiptImportService->GetProductCatalog(),
			'shoppingLocations' => $receiptImportService->GetShoppingLocations(),
			'importHistory' => $receiptImportService->GetHistory(),
			'canCreateProducts' => User::hasPermissions(User::PERMISSION_MASTER_DATA_EDIT),
			'externalBarcodeLookupPluginName' => $externalBarcodeLookupPluginName
		]);
	}
}
