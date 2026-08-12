<?php

namespace Grocy\Services;

class ReceiptImportParser
{
	private const MONEY_PATTERN = '(\d{1,6}[.,]\d{2})';

	public function Parse(string $rawText): array
	{
		$text = $this->NormalizeText($rawText);
		$retailer = $this->DetectRetailer($text);

		if ($retailer['key'] !== 'lidl_ch')
		{
			throw new \InvalidArgumentException('This first version currently supports Lidl Switzerland receipts only');
		}

		return $this->ParseLidlSwitzerland($text, $retailer);
	}

	public function NormalizeLabel(string $label): string
	{
		$label = mb_strtolower(trim($label), 'UTF-8');
		$label = strtr($label, [
			'ä' => 'a',
			'ö' => 'o',
			'ü' => 'u',
			'ß' => 'ss',
			'é' => 'e',
			'è' => 'e',
			'à' => 'a'
		]);
		$label = preg_replace('/[^a-z0-9]+/u', ' ', $label);
		return trim(preg_replace('/\s+/', ' ', $label));
	}

	private function NormalizeText(string $text): string
	{
		$text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
		$lines = explode("\n", $text);
		$normalized = [];

		foreach ($lines as $line)
		{
			$line = trim(preg_replace('/[\t ]+/u', ' ', $line));
			if ($line !== '')
			{
				$normalized[] = $line;
			}
		}

		return implode("\n", $normalized);
	}

	private function DetectRetailer(string $text): array
	{
		if (preg_match('/\bLidl\b/ui', $text) || preg_match('/Lidl Plus/ui', $text))
		{
			return ['key' => 'lidl_ch', 'name' => 'Lidl Switzerland'];
		}

		return ['key' => 'unknown', 'name' => 'Unknown retailer'];
	}

	private function ParseLidlSwitzerland(string $text, array $retailer): array
	{
		$receiptDate = $this->ParseLidlDate($text);
		$receiptTotal = $this->ParseRequiredMoney('/\bzu zahlen\s+' . self::MONEY_PATTERN . '\b/ui', $text, 'receipt total');
		$store = $this->ParseLidlStore($text);
		$receiptReference = $this->ParseLidlReference($text);
		$lines = explode("\n", $text);
		$items = [];
		$currentIndex = null;
		$insideReceipt = false;

		foreach ($lines as $line)
		{
			if (!$insideReceipt && ($line === 'CHF' || preg_match('/^.+?\s+' . self::MONEY_PATTERN . '(?:\s+[A-Z])?$/u', $line)))
			{
				$insideReceipt = true;
			}

			if (!$insideReceipt)
			{
				continue;
			}

			if (preg_match('/\bzu zahlen\b/ui', $line))
			{
				break;
			}

			if ($line === 'CHF')
			{
				continue;
			}

			if ($currentIndex !== null && preg_match('/^Lidl Plus (?:Rabatt|Discount)\s+-?' . self::MONEY_PATTERN . '$/ui', $line, $matches))
			{
				$items[$currentIndex]['discount_total'] += $this->Money($matches[1]);
				continue;
			}

			if ($currentIndex !== null && preg_match('/^' . '(\d+(?:[.,]\d+)?)\s*(kg|g)?\s*x\s*' . self::MONEY_PATTERN . '(?:\s+CHF\/(?:kg|g))?' . '$/ui', $line, $matches))
			{
				$quantity = $this->Number($matches[1]);
				$unit = strtolower($matches[2] ?? '');
				$items[$currentIndex]['receipt_quantity'] = $quantity;
				$items[$currentIndex]['receipt_unit'] = $unit === '' ? 'piece' : $unit;
				$items[$currentIndex]['listed_unit_price'] = $this->Money($matches[3]);
				continue;
			}

			if (preg_match('/^(.+?)\s+' . self::MONEY_PATTERN . '(?:\s+[A-Z])?$/u', $line, $matches))
			{
				$label = trim($matches[1]);
				if ($this->IsNonProductLine($label))
				{
					continue;
				}

				$items[] = [
					'line_index' => count($items),
					'raw_label' => $label,
					'normalized_label' => $this->NormalizeLabel($label),
					'receipt_quantity' => 1.0,
					'receipt_unit' => 'piece',
					'listed_unit_price' => null,
					'gross_total' => $this->Money($matches[2]),
					'discount_total' => 0.0
				];
				$currentIndex = array_key_last($items);
			}
		}

		if (count($items) === 0)
		{
			throw new \InvalidArgumentException('No Lidl receipt line items were found');
		}

		$parsedTotal = 0.0;
		$discountTotal = 0.0;
		foreach ($items as &$item)
		{
			$item['gross_total'] = round($item['gross_total'], 2);
			$item['discount_total'] = round($item['discount_total'], 2);
			$item['net_total'] = round($item['gross_total'] - $item['discount_total'], 2);
			$parsedTotal += $item['net_total'];
			$discountTotal += $item['discount_total'];
		}
		unset($item);

		$parsedTotal = round($parsedTotal, 2);
		$discountTotal = round($discountTotal, 2);
		$difference = round($parsedTotal - $receiptTotal, 2);
		if (abs($difference) > 0.02)
		{
			throw new \InvalidArgumentException(sprintf('Receipt line total %.2f does not match receipt total %.2f (difference %.2f)', $parsedTotal, $receiptTotal, $difference));
		}

		return [
			'retailer_key' => $retailer['key'],
			'retailer_name' => $retailer['name'],
			'store_label' => $store,
			'receipt_reference' => $receiptReference,
			'receipt_date' => $receiptDate,
			'currency' => 'CHF',
			'receipt_total' => $receiptTotal,
			'parsed_total' => $parsedTotal,
			'discount_total' => $discountTotal,
			'is_reconciled' => true,
			'items' => $items
		];
	}

	private function ParseLidlDate(string $text): string
	{
		$months = [
			'jan' => 1, 'feb' => 2, 'mar' => 3, 'mär' => 3, 'apr' => 4,
			'mai' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9,
			'okt' => 10, 'nov' => 11, 'dez' => 12
		];

		if (preg_match('/\b(\d{1,2})\.\s*([\p{L}]{3,})\.\s*(20\d{2})\b/u', $text, $matches))
		{
			$key = mb_strtolower(mb_substr($matches[2], 0, 3), 'UTF-8');
			if (isset($months[$key]))
			{
				return sprintf('%04d-%02d-%02d', intval($matches[3]), $months[$key], intval($matches[1]));
			}
		}

		if (preg_match('/\b(\d{2})[.]([01]\d)[.](20\d{2})\b/', $text, $matches))
		{
			return sprintf('%04d-%02d-%02d', intval($matches[3]), intval($matches[2]), intval($matches[1]));
		}

		if (preg_match('/\b(\d{2})[.](\d{2})[.](\d{2})\b/', $text, $matches))
		{
			return sprintf('20%02d-%02d-%02d', intval($matches[3]), intval($matches[2]), intval($matches[1]));
		}

		throw new \InvalidArgumentException('The Lidl receipt date could not be read');
	}

	private function ParseLidlStore(string $text): ?string
	{
		if (preg_match('/([^\n]+)\s+\(Fil\.Nr\.\s*\d+\)/u', $text, $matches))
		{
			return trim($matches[1]);
		}

		if (preg_match('/([^\n,]+),\s*([^\n]+)\s+Geöffnet/ui', $text, $matches))
		{
			return trim($matches[1] . ', ' . $matches[2]);
		}

		return null;
	}

	private function ParseLidlReference(string $text): ?string
	{
		if (preg_match('/\b(\d{4})\s+(\d{6}\/\d{2})\s+\d{2}[.]\d{2}[.]\d{2}\s+\d{2}:\d{2}\b/', $text, $matches))
		{
			return $matches[1] . '-' . $matches[2];
		}

		return null;
	}

	private function ParseRequiredMoney(string $pattern, string $text, string $field): float
	{
		if (!preg_match($pattern, $text, $matches))
		{
			throw new \InvalidArgumentException("The $field could not be read");
		}

		return $this->Money($matches[1]);
	}

	private function Money(string $value): float
	{
		return round($this->Number($value), 2);
	}

	private function Number(string $value): float
	{
		return floatval(str_replace(',', '.', $value));
	}

	private function IsNonProductLine(string $label): bool
	{
		return preg_match('/^(?:Total-EFT CHF|A 2[.,]6 % MwSt von|Gesamter Preisvorteil|Karte)$/ui', $label) === 1;
	}
}
