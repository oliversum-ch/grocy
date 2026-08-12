CREATE TABLE receipt_imports (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	receipt_hash TEXT NOT NULL UNIQUE,
	retailer_key TEXT NOT NULL,
	retailer_name TEXT NOT NULL,
	receipt_date DATE NOT NULL,
	currency TEXT NOT NULL,
	receipt_total REAL NOT NULL,
	discount_total REAL NOT NULL DEFAULT 0,
	shopping_location_id INTEGER,
	source_filename TEXT,
	raw_text TEXT NOT NULL,
	status TEXT NOT NULL DEFAULT 'imported' CHECK(status IN ('imported', 'undone')),
	user_id INTEGER NOT NULL,
	row_created_timestamp DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
	undone_timestamp DATETIME,
	FOREIGN KEY (shopping_location_id) REFERENCES shopping_locations(id),
	FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE receipt_import_lines (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	receipt_import_id INTEGER NOT NULL,
	line_index INTEGER NOT NULL,
	raw_label TEXT NOT NULL,
	normalized_label TEXT NOT NULL,
	product_id INTEGER NOT NULL,
	receipt_quantity REAL NOT NULL,
	receipt_unit TEXT NOT NULL,
	stock_amount REAL NOT NULL,
	gross_total REAL NOT NULL,
	discount_total REAL NOT NULL DEFAULT 0,
	net_total REAL NOT NULL,
	unit_price REAL NOT NULL,
	best_before_date DATE NOT NULL,
	transaction_id TEXT NOT NULL,
	FOREIGN KEY (receipt_import_id) REFERENCES receipt_imports(id) ON DELETE CASCADE,
	FOREIGN KEY (product_id) REFERENCES products(id),
	UNIQUE(receipt_import_id, line_index)
);

CREATE TABLE receipt_import_aliases (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	retailer_key TEXT NOT NULL,
	normalized_label TEXT NOT NULL,
	product_id INTEGER NOT NULL,
	amount_multiplier REAL NOT NULL,
	row_created_timestamp DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
	row_updated_timestamp DATETIME NOT NULL DEFAULT (datetime('now', 'localtime')),
	FOREIGN KEY (product_id) REFERENCES products(id),
	UNIQUE(retailer_key, normalized_label)
);

CREATE INDEX receipt_imports_status_idx ON receipt_imports(status);
CREATE INDEX receipt_import_lines_receipt_idx ON receipt_import_lines(receipt_import_id);
CREATE INDEX receipt_import_aliases_product_idx ON receipt_import_aliases(product_id);
