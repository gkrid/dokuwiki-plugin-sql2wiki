-- Defines the queries that can be used in wiki
CREATE TABLE cache (
    db TEXT NOT NULL,
    query TEXT NOT NULL,
    params TEXT NOT NULL,
    result TEXT NOT NULL,
    last_changed INT NULL,
    last_checked INT NULL,
    PRIMARY KEY (db, query, params)
);