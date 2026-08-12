<?php

declare(strict_types=1);

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
	if ($expected !== $actual)
	{
		throw new RuntimeException(sprintf('%s (expected %s, got %s)', $message, var_export($expected, true), var_export($actual, true)));
	}
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
$db->exec('CREATE TABLE shopping_locations (id INTEGER PRIMARY KEY)');
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY)');
$db->exec((string)file_get_contents(__DIR__ . '/../migrations/0258.sql'));

$tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'receipt_import%' ORDER BY name")
	->fetchAll(PDO::FETCH_COLUMN);
assertSameValue(
	['receipt_import_aliases', 'receipt_import_lines', 'receipt_imports'],
	$tables,
	'Receipt import tables should be created'
);

$indexes = $db->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name LIKE 'receipt_import%_idx' ORDER BY name")
	->fetchAll(PDO::FETCH_COLUMN);
assertSameValue(3, count($indexes), 'Receipt import indexes should be created');

$db->exec('INSERT INTO users (id) VALUES (1)');
$db->exec('INSERT INTO shopping_locations (id) VALUES (2)');
$db->exec('INSERT INTO products (id) VALUES (3)');
$db->exec("INSERT INTO receipt_imports (receipt_hash, retailer_key, retailer_name, receipt_date, currency, receipt_total, shopping_location_id, raw_text, user_id)
	VALUES ('hash', 'lidl_ch', 'Lidl Switzerland', '2026-08-12', 'CHF', 10.5, 2, 'text', 1)");
$receiptId = (int)$db->lastInsertId();
$db->exec("INSERT INTO receipt_import_lines (receipt_import_id, line_index, raw_label, normalized_label, product_id, receipt_quantity, receipt_unit, stock_amount, gross_total, net_total, unit_price, best_before_date, transaction_id)
	VALUES ($receiptId, 0, 'Milk', 'milk', 3, 1, 'piece', 1, 2.5, 2.5, 2.5, '2026-08-20', 'tx-1')");
$db->exec("INSERT INTO receipt_import_aliases (retailer_key, normalized_label, product_id, amount_multiplier)
	VALUES ('lidl_ch', 'milk', 3, 1)");

assertSameValue(1, (int)$db->query('SELECT COUNT(*) FROM receipt_import_lines')->fetchColumn(), 'A receipt line should be insertable');
assertSameValue(1, (int)$db->query('SELECT COUNT(*) FROM receipt_import_aliases')->fetchColumn(), 'An alias should be insertable');

echo "ReceiptImportMigration: all tests passed\n";
