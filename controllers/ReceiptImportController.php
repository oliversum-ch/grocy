<?php

namespace Grocy\Controllers;

use Grocy\Controllers\Users\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReceiptImportController extends BaseController
{
	public function Overview(Request $request, Response $response, array $args)
	{
		User::checkPermission($request, User::PERMISSION_STOCK_PURCHASE);
		try
		{
			$externalBarcodeLookupPluginName = $this->getStockService()->GetExternalBarcodeLookupPluginName();
		}
		catch (\Throwable)
		{
			$externalBarcodeLookupPluginName = '';
		}

		return $this->renderPage($response, 'receiptimport', [
			'products' => $this->getReceiptImportService()->GetProductCatalog(),
			'shoppingLocations' => $this->getReceiptImportService()->GetShoppingLocations(),
			'importHistory' => $this->getReceiptImportService()->GetHistory(),
			'canCreateProducts' => User::hasPermissions(User::PERMISSION_MASTER_DATA_EDIT),
			'externalBarcodeLookupPluginName' => $externalBarcodeLookupPluginName
		]);
	}
}
