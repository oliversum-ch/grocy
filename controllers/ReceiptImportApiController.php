<?php

namespace Grocy\Controllers;

use Grocy\Controllers\Users\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReceiptImportApiController extends BaseApiController
{
	public function Preview(Request $request, Response $response, array $args)
	{
		User::checkPermission($request, User::PERMISSION_STOCK_PURCHASE);

		try
		{
			$body = $this->GetParsedAndFilteredRequestBody($request);
			if (!is_array($body) || !isset($body['raw_text'], $body['receipt_hash']))
			{
				throw new \InvalidArgumentException('Receipt text and fingerprint are required');
			}

			return $this->ApiResponse($response, $this->getReceiptImportService()->Preview(
				(string)$body['raw_text'],
				(string)$body['receipt_hash']
			));
		}
		catch (\Throwable $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	public function Commit(Request $request, Response $response, array $args)
	{
		User::checkPermission($request, User::PERMISSION_STOCK_PURCHASE);

		try
		{
			$body = $this->GetParsedAndFilteredRequestBody($request);
			if (!is_array($body) || !isset($body['raw_text'], $body['receipt_hash'], $body['selected_lines']) || !is_array($body['selected_lines']))
			{
				throw new \InvalidArgumentException('Receipt text, fingerprint and selected lines are required');
			}

			$shoppingLocationId = null;
			if (isset($body['shopping_location_id']) && $body['shopping_location_id'] !== '')
			{
				$validatedLocationId = filter_var($body['shopping_location_id'], FILTER_VALIDATE_INT);
				if ($validatedLocationId === false || $validatedLocationId <= 0)
				{
					throw new \InvalidArgumentException('The selected store is invalid');
				}
				$shoppingLocationId = intval($validatedLocationId);
			}

			return $this->ApiResponse($response, $this->getReceiptImportService()->Commit(
				(string)$body['raw_text'],
				(string)$body['receipt_hash'],
				isset($body['source_filename']) ? (string)$body['source_filename'] : null,
				$shoppingLocationId,
				$body['selected_lines'],
				GROCY_USER_ID
			));
		}
		catch (\Throwable $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	public function Undo(Request $request, Response $response, array $args)
	{
		User::checkPermission($request, User::PERMISSION_STOCK_EDIT);

		try
		{
			$receiptImportId = filter_var($args['receiptImportId'] ?? null, FILTER_VALIDATE_INT);
			if ($receiptImportId === false || $receiptImportId <= 0)
			{
				throw new \InvalidArgumentException('The receipt import id is invalid');
			}

			return $this->ApiResponse($response, $this->getReceiptImportService()->Undo(intval($receiptImportId)));
		}
		catch (\Throwable $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	public function History(Request $request, Response $response, array $args)
	{
		User::checkPermission($request, User::PERMISSION_STOCK_PURCHASE);
		return $this->ApiResponse($response, $this->getReceiptImportService()->GetHistory());
	}
}
