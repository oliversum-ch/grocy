<?php

// This is executed inside DatabaseMigrationService class/context

$db = \Grocy\Services\DatabaseService::GetInstance();

$locationColumnExists = $db->ExecuteDbQuery("SELECT COUNT(*) FROM pragma_table_info('equipment') WHERE name = 'location_id'")->fetchColumn() > 0;

if (!$locationColumnExists)
{
	$db->ExecuteDbStatement('ALTER TABLE equipment ADD location_id INTEGER;');
}
