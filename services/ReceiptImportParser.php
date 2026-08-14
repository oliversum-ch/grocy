<?php

namespace Grocy\Services;

class ReceiptImportParser
{
	private const MONEY_PATTERN = '(\d{1,6}[.,]\d{2})';
	private const FLEXIBLE_MONEY_PATTERN = '(\d{1,6}(?:[.,]\d{2}|\s+\d{2}))';

	public function Parse(string $rawText): array
	{
		$text = $this->NormalizeText($rawText);
		$retailer = $this->DetectRetailer($text);

		if ($retailer['key'] === 'lidl_ch')
		{
			return $this->ParseLidlSwitzerland($text, $retailer);
		}

		return $this->ParseGenericReceipt($text, $retailer);
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
			if (preg_match('/[\p{L}]/u', $line))
			{
				$line = preg_replace('/(\d{1,6}[.,]\d{2})([A-Z4*])$/u', '$1 $2', $line);
			}
			if ($line !== '')
			{
				$normalized[] = $line;
			}
		}

		return implode("\n", $normalized);
	}

	private function DetectRetailer(string $text): array
	{
		$country = $this->DetectCountry($text);
		$knownRetailers = [
			'aldi' => '/\bALDI(?:\s+SUISSE|\s+SUED|\s+SÜD|\s+NORD)?\b/ui',
			'lidl' => '/\bLidl\b/ui',
			'coop' => '/\bCoop\b/ui',
			'migros' => '/\bMigros\b/ui',
			'denner' => '/\bDenner\b/ui',
			'volg' => '/\bVolg\b/ui',
			'spar' => '/\bSpar\b/ui',
			'rewe' => '/\bREWE\b/ui',
			'edeka' => '/\bEDEKA\b/ui',
			'kaufland' => '/\bKaufland\b/ui',
			'penny' => '/\bPenny\b/ui',
			'netto' => '/(?:\bNetto\s+Marken-Discount\b|netto-online[.]de|netto[.]de)/ui',
			'dm' => '/\bdm(?:-drogerie markt)?\b/ui',
			'carrefour' => '/\bCarrefour\b/ui'
		];

		foreach ($knownRetailers as $key => $pattern)
		{
			if (!preg_match($pattern, $text))
			{
				continue;
			}

			$countrySuffix = $country === null ? '' : '_' . $country['key'];
			$name = $key === 'dm' ? 'dm' : ucfirst($key);
			if ($country !== null)
			{
				$name .= ' ' . $country['name'];
			}
			return ['key' => $key . $countrySuffix, 'name' => $name];
		}

		$name = $this->DetectGenericRetailerName($text);
		$key = str_replace(' ', '_', $this->NormalizeLabel($name));
		$key = substr($key, 0, 80);
		return ['key' => $key === '' ? 'unknown_retailer' : $key, 'name' => $name];
	}

	private function DetectCountry(string $text): ?array
	{
		$countries = [
			'ch' => ['name' => 'Switzerland', 'pattern' => '/(?:\bCHF\b|\bCHE[- ]|\bSchweiz\b|\bSuisse\b|\bSvizzera\b|[.]ch\b)/ui'],
			'de' => ['name' => 'Germany', 'pattern' => '/(?:\bDeutschland\b|[.]de\b)/ui'],
			'fr' => ['name' => 'France', 'pattern' => '/(?:\bFrance\b|[.]fr\b)/ui'],
			'it' => ['name' => 'Italy', 'pattern' => '/(?:\bItalia\b|[.]it\b)/ui'],
			'at' => ['name' => 'Austria', 'pattern' => '/(?:\bÖsterreich\b|\bOesterreich\b|[.]at\b)/ui']
		];

		foreach ($countries as $key => $country)
		{
			if (preg_match($country['pattern'], $text))
			{
				return ['key' => $key, 'name' => $country['name']];
			}
		}

		return null;
	}

	private function DetectGenericRetailerName(string $text): string
	{
		foreach (array_slice(explode("\n", $text), 0, 12) as $line)
		{
			if (mb_strlen($line) < 2 || mb_strlen($line) > 80 || !preg_match('/[\p{L}]/u', $line))
			{
				continue;
			}
			if (preg_match('/^(?:store receipts?|digital(?:er)? kassenbon|receipt|kassenbon|bon de caisse|www[.]|https?:|tel[.: ]|phone[.: ])/ui', $line))
			{
				continue;
			}
			if (preg_match('/^\d{4,6}\s+\p{L}/u', $line) || preg_match('/\b(?:strasse|straße|street|road|avenue|weg|gasse)\b/ui', $line))
			{
				continue;
			}
			return trim(preg_replace('/\s+(?:AG|GmbH|SA|SAGL|Ltd[.]?|Inc[.]?)$/ui', '', $line));
		}

		return 'Unknown retailer';
	}

	private function ParseGenericReceipt(string $text, array $retailer): array
	{
		$lines = explode("\n", $text);
		$receiptDate = $this->ParseGenericDate($text);
		$total = $this->ParseGenericTotal($lines);
		$currency = $this->DetectCurrency($text);
		$items = [];
		$unpricedItems = [];
		$currentIndex = null;
		$roundingAdjustment = $this->ParseRoundingAdjustment($lines);
		$subtotal = $this->ParseGenericSubtotal($lines);
		if ($subtotal !== null)
		{
			$subtotalRounding = round($total['amount'] - $subtotal, 2);
			if (abs($subtotalRounding) <= 0.05 && ($roundingAdjustment === null || abs($roundingAdjustment - $subtotalRounding) > 0.01))
			{
				$roundingAdjustment = $subtotalRounding;
			}
		}

		for ($lineIndex = 0; $lineIndex < $total['line_index']; $lineIndex++)
		{
			$line = $lines[$lineIndex];

			if ($currentIndex !== null && $this->ApplyGenericQuantityLine($line, $items[$currentIndex]))
			{
				continue;
			}

			if ($currentIndex !== null && $this->ApplyGenericDiscountLine($line, $items[$currentIndex]))
			{
				continue;
			}

			$item = $this->ParseGenericItemLine($line);
			if ($item === null)
			{
				$unpricedItem = count($items) > 0 ? $this->ParseGenericUnpricedItemLine($line) : null;
				if ($unpricedItem !== null)
				{
					$unpricedItem['insert_index'] = count($items);
					$unpricedItems[] = $unpricedItem;
					$currentIndex = null;
				}
				continue;
			}

			$item['line_index'] = count($items);
			$items[] = $item;
			$currentIndex = array_key_last($items);
		}

		$this->InferSingleMissingItem(
			$items,
			$unpricedItems,
			$this->ParseDeclaredItemCount($lines),
			$total['amount'],
			$roundingAdjustment
		);
		foreach ($items as $lineIndex => &$item)
		{
			$item['line_index'] = $lineIndex;
		}
		unset($item);

		if (count($items) === 0)
		{
			throw new \InvalidArgumentException('No receipt line items were found');
		}

		$receiptTotal = $total['amount'];
		$parsedTotal = $this->FinalizeItems($items);
		$difference = round($receiptTotal - $parsedTotal, 2);

		if (abs($difference) > 0.0001 && abs($difference) <= 0.05 && ($roundingAdjustment === null || abs($roundingAdjustment - $difference) <= 0.01))
		{
			$lastIndex = array_key_last($items);
			if ($difference < 0)
			{
				$items[$lastIndex]['discount_total'] = round($items[$lastIndex]['discount_total'] + abs($difference), 2);
			}
			else
			{
				$items[$lastIndex]['gross_total'] = round($items[$lastIndex]['gross_total'] + $difference, 2);
			}
			$parsedTotal = $this->FinalizeItems($items);
		}

		$difference = round($parsedTotal - $receiptTotal, 2);
		if (abs($difference) > 0.02)
		{
			throw new \InvalidArgumentException(sprintf('Receipt line total %.2f does not match receipt total %.2f (difference %.2f)', $parsedTotal, $receiptTotal, $difference));
		}

		$discountTotal = round(array_sum(array_column($items, 'discount_total')), 2);

		return [
			'retailer_key' => $retailer['key'],
			'retailer_name' => $retailer['name'],
			'store_label' => $this->ParseGenericStore($lines),
			'receipt_reference' => null,
			'receipt_date' => $receiptDate,
			'currency' => $currency,
			'receipt_total' => $receiptTotal,
			'parsed_total' => $parsedTotal,
			'discount_total' => $discountTotal,
			'is_reconciled' => true,
			'items' => $items
		];
	}

	private function ParseGenericItemLine(string $line): ?array
	{
		$line = preg_replace('/([\p{L}\d])\s+[)OQ][.,](\d{2})(\s+[A-Z0-9*])?$/ui', '$1 0.$2$3', $line);
		$pattern = '/^(.+?)\s+(?:(?:CHF|EUR|USD|GBP|Fr[.]?)\s*)?' . self::FLEXIBLE_MONEY_PATTERN . '(?:\s+[A-Z0-9*])?$/ui';
		if (!preg_match($pattern, $line, $matches))
		{
			return null;
		}

		$label = trim($matches[1]);
		if ($this->IsNonProductLine($label))
		{
			return null;
		}

		$label = preg_replace('/^\d{4,14}\s+/u', '', $label);
		$quantity = 1.0;
		$unit = 'piece';
		$listedUnitPrice = null;

		if (preg_match('/^(\d+(?:[.,]\d+)?)\s*[xX*]\s+(.+)$/u', $label, $quantityMatches))
		{
			$quantity = $this->Number($quantityMatches[1]);
			$label = trim($quantityMatches[2]);
			if (preg_match('/^(.+?)\s+' . self::FLEXIBLE_MONEY_PATTERN . '$/u', $label, $unitPriceMatches))
			{
				$label = trim($unitPriceMatches[1]);
				$listedUnitPrice = $this->Money($unitPriceMatches[2]);
			}
		}

		if ($label === '' || !preg_match('/[\p{L}]/u', $label))
		{
			return null;
		}

		return [
			'raw_label' => $label,
			'normalized_label' => $this->NormalizeLabel($label),
			'receipt_quantity' => $quantity,
			'receipt_unit' => $unit,
			'listed_unit_price' => $listedUnitPrice,
			'gross_total' => $this->Money($matches[2]),
			'discount_total' => 0.0,
			'price_inferred' => false
		];
	}

	private function ParseGenericUnpricedItemLine(string $line): ?array
	{
		if (!preg_match('/^\d{4,14}\s+(.+)$/u', $line, $matches))
		{
			return null;
		}

		$label = trim(preg_replace('/\s+\d{2}\s+[A-Z0-9*]$/u', '', $matches[1]));
		$label = trim(preg_replace('/\s+[A-Z]{1,3}$/u', '', $label));
		if ($label === '' || !preg_match('/[\p{L}]/u', $label) || $this->IsNonProductLine($label))
		{
			return null;
		}

		return [
			'raw_label' => $label,
			'normalized_label' => $this->NormalizeLabel($label),
			'receipt_quantity' => 1.0,
			'receipt_unit' => 'piece',
			'listed_unit_price' => null,
			'discount_total' => 0.0,
			'price_inferred' => true
		];
	}

	private function ParseDeclaredItemCount(array $lines): ?int
	{
		foreach ($lines as $line)
		{
			if (preg_match('/\b(\d{1,3})\s+(?:artikel|items?|articles?|articoli)\b/ui', $line, $matches))
			{
				return intval($matches[1]);
			}
		}
		return null;
	}

	private function InferSingleMissingItem(array &$items, array $unpricedItems, ?int $declaredCount, float $receiptTotal, ?float $roundingAdjustment): void
	{
		if (count($unpricedItems) !== 1 || $declaredCount !== count($items) + 1)
		{
			return;
		}
		foreach ($items as $item)
		{
			if (abs($item['discount_total']) > 0.0001)
			{
				return;
			}
		}

		$knownTotal = $this->FinalizeItems($items);
		$targetBeforeRounding = round($receiptTotal - ($roundingAdjustment ?? 0.0), 2);
		$inferredPrice = round($targetBeforeRounding - $knownTotal, 2);
		if ($inferredPrice <= 0 || $inferredPrice > $targetBeforeRounding)
		{
			return;
		}

		$item = $unpricedItems[0];
		$insertIndex = $item['insert_index'];
		unset($item['insert_index']);
		$item['gross_total'] = $inferredPrice;
		array_splice($items, $insertIndex, 0, [$item]);
	}

	private function ApplyGenericQuantityLine(string $line, array &$item): bool
	{
		$pattern = '/^(\d+(?:[.,]\d+)?)\s*(kg|g|l|ml|stk|stück|piece|pcs)?\s*[xX*]\s*(?:(?:CHF|EUR|USD|GBP|Fr[.]?)\s*)?' . self::FLEXIBLE_MONEY_PATTERN . '(?:\s+(?:CHF|EUR|USD|GBP)\/(?:kg|g|l|ml))?$/ui';
		if (!preg_match($pattern, $line, $matches))
		{
			return false;
		}

		$unit = mb_strtolower($matches[2] ?? '', 'UTF-8');
		$item['receipt_quantity'] = $this->Number($matches[1]);
		$item['receipt_unit'] = $unit === '' ? 'piece' : $unit;
		$item['listed_unit_price'] = $this->Money($matches[3]);
		return true;
	}

	private function ApplyGenericDiscountLine(string $line, array &$item): bool
	{
		if (!preg_match('/\b(?:rabatt|discount|coupon|aktion|ersparnis|remise|sconto)\b/ui', $line))
		{
			return false;
		}
		if (!preg_match('/(-?\s*\d{1,6}(?:[.,]\d{2}|\s+\d{2})\s*-?)\s*(?:[A-Z*])?$/u', $line, $matches))
		{
			return false;
		}

		$item['discount_total'] = round($item['discount_total'] + abs($this->Money($matches[1])), 2);
		return true;
	}

	private function FinalizeItems(array &$items): float
	{
		$parsedTotal = 0.0;
		foreach ($items as &$item)
		{
			$item['gross_total'] = round($item['gross_total'], 2);
			$item['discount_total'] = round($item['discount_total'], 2);
			$item['net_total'] = round($item['gross_total'] - $item['discount_total'], 2);
			$parsedTotal += $item['net_total'];
		}
		unset($item);
		return round($parsedTotal, 2);
	}

	private function ParseGenericTotal(array $lines): array
	{
		$matchesFound = [];
		foreach ($lines as $lineIndex => $line)
		{
			if (!preg_match('/^(.+?)\s+(?:(?:CHF|EUR|USD|GBP|Fr[.]?)\s*)?' . self::FLEXIBLE_MONEY_PATTERN . '(?:\s+[A-Z*])?$/ui', $line, $matches))
			{
				continue;
			}

			$score = $this->GenericTotalLabelScore($matches[1]);
			if ($score === 0 && $lineIndex > 0)
			{
				$score = $this->SplitGenericTotalLabelScore($lines[$lineIndex - 1], $matches[1]);
			}
			if ($score > 0)
			{
				$matchesFound[] = [
					'line_index' => $lineIndex,
					'amount' => $this->Money($matches[2]),
					'score' => $score
				];
			}
		}

		if (count($matchesFound) === 0)
		{
			throw new \InvalidArgumentException('The receipt total could not be read');
		}

		usort($matchesFound, static function(array $left, array $right): int
		{
			return [$left['score'], $left['line_index']] <=> [$right['score'], $right['line_index']];
		});

		$total = $matchesFound[array_key_last($matchesFound)];
		unset($total['score']);
		return $total;
	}

	private function GenericTotalLabelScore(string $label): int
	{
		$label = trim($label);
		if (preg_match('/^(?:aldi\s+preis|zu\s+zahlen|grand\s+total|total(?:-eft)?|gesamt(?:betrag)?|summe|endbetrag|amount\s+due|to\s+pay|a\s+payer|totale)$/ui', $label))
		{
			return 100;
		}

		if (preg_match('/\b(?:zwischen(?:summe|total)|sub[- ]?total|sous[- ]?total|netto|mwst|vat|tva|iva|tax|rabatt|discount|coupon|ersparnis|preisvorteil|remise|sconto)\b/ui', $label))
		{
			return 0;
		}

		// OCR commonly damages the retailer name while preserving the total keyword and amount.
		if (preg_match('/\b(?:preis|price|total|gesamt(?:betrag)?|summe|endbetrag)\b/ui', $label))
		{
			return 80;
		}

		// A card/cash payment amount is a useful fallback when the printed total label is unreadable.
		if (preg_match('/^(?:total-eft|kartenzahlung|karte|card(?:\s+payment)?|cash|bar(?:zahlung)?|bargeld|payment|paiement|pagamento)$/ui', $label))
		{
			return 60;
		}

		return 0;
	}

	private function SplitGenericTotalLabelScore(string $previousLine, string $currentLabel): int
	{
		if (preg_match('/\d{1,6}(?:[.,]\d{2}|\s+\d{2})/u', $previousLine))
		{
			return 0;
		}

		$compact = mb_strtolower($previousLine . $currentLabel, 'UTF-8');
		$compact = preg_replace('/[^\p{L}]+/u', '', $compact);
		if (preg_match('/(?:zwischen(?:summe|total)|subtotal|soustotal|netto|mwst|vat|tva|iva|tax)/u', $compact))
		{
			return 0;
		}

		// OCR can place the last letters of a strong total label on the amount line,
		// for example "ALDI PREI" followed by "S 4 Artikel 6.60".
		return preg_match('/(?:aldipreis|zuzahlen|grandtotal|gesamtbetrag|endbetrag|amountdue|topay|apayer)/u', $compact) ? 90 : 0;
	}

	private function ParseGenericDate(string $text): string
	{
		if (preg_match('/\b(20\d{2})[-\/.]([01]?\d)[-\/.]([0-3]?\d)\b/', $text, $matches))
		{
			return $this->ValidatedDate(intval($matches[1]), intval($matches[2]), intval($matches[3]));
		}
		if (preg_match('/\b([0-3]?\d)[.\/-]([01]?\d)[.\/-](20\d{2}|\d{2})\b/', $text, $matches))
		{
			$year = intval($matches[3]);
			return $this->ValidatedDate($year < 100 ? 2000 + $year : $year, intval($matches[2]), intval($matches[1]));
		}

		try
		{
			return $this->ParseLidlDate($text);
		}
		catch (\InvalidArgumentException $exception)
		{
			throw new \InvalidArgumentException('The receipt date could not be read');
		}
	}

	private function ValidatedDate(int $year, int $month, int $day): string
	{
		if (!checkdate($month, $day, $year))
		{
			throw new \InvalidArgumentException('The receipt date is invalid');
		}
		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}

	private function DetectCurrency(string $text): string
	{
		if (preg_match('/(?:\bCHF\b|\bFr[.]?\s*\d)/ui', $text))
		{
			return 'CHF';
		}
		if (preg_match('/(?:\bEUR\b|€)/ui', $text))
		{
			return 'EUR';
		}
		if (preg_match('/(?:\bGBP\b|£)/ui', $text))
		{
			return 'GBP';
		}
		if (preg_match('/(?:\bUSD\b|\$)/ui', $text))
		{
			return 'USD';
		}
		return 'CHF';
	}

	private function ParseGenericStore(array $lines): ?string
	{
		foreach (array_slice($lines, 0, 12) as $lineIndex => $line)
		{
			if (!preg_match('/^\d{4,6}\s+\p{L}/u', $line))
			{
				continue;
			}
			$nextLine = $lines[$lineIndex + 1] ?? '';
			if ($nextLine !== '' && preg_match('/(?:\d|strasse|straße|street|road|avenue|weg|gasse)/ui', $nextLine))
			{
				return $line . ', ' . $nextLine;
			}
			return $line;
		}
		return null;
	}

	private function ParseRoundingAdjustment(array $lines): ?float
	{
		foreach ($lines as $line)
		{
			if (preg_match('/\b(?:rundung|rounding|arrondi|arrotondamento)\b.*?(-?\s*\d{1,6}(?:[.,]\d{2}|\s+\d{2})\s*-?)$/ui', $line, $matches))
			{
				return $this->Money($matches[1]);
			}
		}
		return null;
	}

	private function ParseGenericSubtotal(array $lines): ?float
	{
		foreach ($lines as $line)
		{
			if (preg_match('/^(?:zwischen(?:summe|total)|sub[- ]?total|sous[- ]?total)\s+(?:(?:CHF|EUR|USD|GBP|Fr[.]?)\s*)?' . self::FLEXIBLE_MONEY_PATTERN . '(?:\s+[A-Z0-9*])?$/ui', $line, $matches))
			{
				return $this->Money($matches[1]);
			}
		}
		return null;
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
		$value = trim(str_replace(["'", '’'], '', $value));
		$negative = str_starts_with($value, '-') || str_ends_with($value, '-');
		$value = trim($value, "- \t\n\r\0\x0B");
		if (preg_match('/^(\d+)\s+(\d{2})$/', $value, $matches))
		{
			$value = $matches[1] . '.' . $matches[2];
		}
		else
		{
			$value = str_replace(' ', '', $value);
			$value = str_replace(',', '.', $value);
		}
		$number = floatval($value);
		return $negative ? -$number : $number;
	}

	private function IsNonProductLine(string $label): bool
	{
		return preg_match('/^(?:
			Zwischen(?:summe|total)|Sub[- ]?total|Sous[- ]?total|Run(?:d(?:ung)?)?|Round(?:ing)?|Arrondi|
			ALDI\s+PREIS|zu\s+zahlen|Grand\s+Total|Total|Gesamt(?:betrag)?|Summe|Endbetrag|Amount\s+due|To\s+pay|A\s+payer|Totale|
			Total-EFT|Kartenzahlung|Karte|Card|Cash|Bar|Bargeld|Change|Rückgeld|Retourgeld|
			Netto|MwSt|MWST|VAT|TVA|IVA|Tax|A\s+\d+[.,]\d+\s*%\s*MwSt|
			Gesamter\s+Preisvorteil|Ersparnis|\d+\s+Artikel
		)/uix', $label) === 1;
	}
}
