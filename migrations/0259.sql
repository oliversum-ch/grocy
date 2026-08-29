-- Compatibility migration for this fork: custom migrations already occupy
-- 0256 and 0257, so apply the Grocy 4.7.0 migrations under a new id.
DROP TABLE sessions;

CREATE TABLE sessions (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE,
	user_id INTEGER NOT NULL,
	token_type TINYINT NOT NULL,
	token_hash TEXT NOT NULL UNIQUE,
	expires DATETIME NOT NULL,
	last_used DATETIME,
	client_info TEXT,
	row_created_timestamp DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE INDEX ix_sessions_performance1 ON sessions (
	token_type,
	token_hash
);

UPDATE products
SET parent_product_id = null
WHERE id = parent_product_id;

CREATE TRIGGER prevent_self_referenced_parent_product_INS AFTER INSERT ON products
BEGIN
SELECT CASE WHEN((
	SELECT 1
	FROM products p
	WHERE p.id = NEW.id
		AND p.id = p.parent_product_id
	)
	NOTNULL) THEN RAISE(ABORT, 'A product cannot reference itself as a parent product') END;
END;

CREATE TRIGGER prevent_self_referenced_parent_product_UPD AFTER UPDATE ON products
BEGIN
	SELECT CASE WHEN((
		SELECT 1
		FROM products p
		WHERE p.id = NEW.id
			AND p.id = p.parent_product_id
	) NOTNULL) THEN RAISE(ABORT, 'A product cannot reference itself as a parent product') END;
END;
