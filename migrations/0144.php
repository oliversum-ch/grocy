<?php

// This is executed inside DatabaseMigrationService class/context

use Grocy\Services\StockService;

StockService::GetInstance()->CompactStockEntries();
