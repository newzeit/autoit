import { DatabaseSync } from 'node:sqlite';
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
export function openDatabase(path = process.env.DB_PATH || './data/iturno.sqlite') {
  if (path !== ':memory:') mkdirSync(dirname(resolve(path)), { recursive: true, mode: 0o700 });
  const db = new DatabaseSync(path);
  db.exec(`PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;
    CREATE TABLE IF NOT EXISTS conversations (id TEXT PRIMARY KEY, token_hash TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, category TEXT NOT NULL DEFAULT 'Otros', status TEXT NOT NULL DEFAULT 'active', state TEXT NOT NULL DEFAULT '{}', mode TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS messages (id INTEGER PRIMARY KEY, conversation_id TEXT NOT NULL REFERENCES conversations(id), role TEXT NOT NULL, content TEXT NOT NULL, created_at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS requests (conversation_id TEXT NOT NULL REFERENCES conversations(id), request_id TEXT NOT NULL, message TEXT NOT NULL, response TEXT, PRIMARY KEY(conversation_id, request_id));
    CREATE TABLE IF NOT EXISTS clients (id TEXT PRIMARY KEY, name TEXT NOT NULL, contact TEXT NOT NULL, created_at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS tickets (id TEXT PRIMARY KEY, code TEXT NOT NULL UNIQUE, conversation_id TEXT NOT NULL UNIQUE REFERENCES conversations(id), client_id TEXT NOT NULL REFERENCES clients(id), equipment TEXT NOT NULL, category TEXT NOT NULL, description TEXT NOT NULL, diagnosis TEXT NOT NULL, summary TEXT NOT NULL, priority TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'Nuevo', created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS ticket_events (id INTEGER PRIMARY KEY, ticket_id TEXT NOT NULL REFERENCES tickets(id), kind TEXT NOT NULL, content TEXT NOT NULL, actor TEXT NOT NULL, created_at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS resolutions (conversation_id TEXT PRIMARY KEY REFERENCES conversations(id), problem TEXT NOT NULL, category TEXT NOT NULL, diagnosis TEXT NOT NULL, solution TEXT NOT NULL, confirmation TEXT, confirmed INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS sessions (token_hash TEXT PRIMARY KEY, username TEXT NOT NULL, expires_at INTEGER NOT NULL);
    CREATE INDEX IF NOT EXISTS messages_conversation ON messages(conversation_id, id);
    CREATE INDEX IF NOT EXISTS tickets_status ON tickets(status, priority);
  `);
  return db;
}
export function transaction(db, fn) {
  db.exec('BEGIN IMMEDIATE');
  try { const result = fn(); db.exec('COMMIT'); return result; }
  catch (error) { db.exec('ROLLBACK'); throw error; }
}
