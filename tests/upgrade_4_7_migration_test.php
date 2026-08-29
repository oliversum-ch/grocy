<?php

declare(strict_types=1);

function assertUpgrade47(bool $condition, string $message): void
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE sessions (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	session_key TEXT NOT NULL UNIQUE,
	user_id INTEGER NOT NULL,
	expires DATETIME NOT NULL,
	last_used DATETIME,
	row_created_timestamp DATETIME DEFAULT (datetime(\'now\', \'localtime\'))
)');
$db->exec("INSERT INTO sessions (session_key, user_id, expires) VALUES ('old-session', 1, '2099-01-01')");
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, parent_product_id INTEGER)');
$db->exec('INSERT INTO products (id, parent_product_id) VALUES (1, 1), (2, NULL)');

$db->exec((string)file_get_contents(__DIR__ . '/../migrations/0259.sql'));

$sessionColumns = $db->query('PRAGMA table_info(sessions)')->fetchAll(PDO::FETCH_ASSOC);
$sessionColumnNames = array_column($sessionColumns, 'name');
assertUpgrade47(
	$sessionColumnNames === ['id', 'user_id', 'token_type', 'token_hash', 'expires', 'last_used', 'client_info', 'row_created_timestamp'],
	'Grocy 4.7 session schema was not installed'
);
assertUpgrade47((int)$db->query('SELECT COUNT(*) FROM sessions')->fetchColumn() === 0, 'Old sessions were not invalidated');
assertUpgrade47($db->query('SELECT parent_product_id FROM products WHERE id = 1')->fetchColumn() === null, 'Existing self-referencing parent product was not cleared');

$triggerCount = (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'prevent_self_referenced_parent_product_%'")->fetchColumn();
assertUpgrade47($triggerCount === 2, 'Self-reference protection triggers were not created');

$selfReferenceRejected = false;
try
{
	$db->exec('UPDATE products SET parent_product_id = 2 WHERE id = 2');
}
catch (PDOException)
{
	$selfReferenceRejected = true;
}
assertUpgrade47($selfReferenceRejected, 'Self-referencing parent product update was not rejected');

echo "Grocy47CompatibilityMigration: all tests passed\n";
