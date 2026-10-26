PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;
PRAGMA busy_timeout = 5000;

CREATE TABLE IF NOT EXISTS knowledge (
    id INTEGER PRIMARY KEY,
    path TEXT NOT NULL,
    content TEXT NOT NULL,
    embedding BLOB NOT NULL CHECK (length(embedding) = 1536),
    category TEXT NOT NULL CHECK (category IN ('bugs_resolvidos', 'arquitetura', 'padroes', 'tentativas_falhas')),
    agent TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS knowledge_path ON knowledge(path);
CREATE INDEX IF NOT EXISTS knowledge_category_agent ON knowledge(category, agent);

CREATE VIRTUAL TABLE IF NOT EXISTS knowledge_vec USING vec0(embedding float[384]);

CREATE TABLE IF NOT EXISTS documents (
    path TEXT PRIMARY KEY,
    content_hash TEXT NOT NULL,
    indexed_at TEXT NOT NULL
);
