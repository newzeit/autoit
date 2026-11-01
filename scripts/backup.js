import { DatabaseSync, backup } from 'node:sqlite';
import { mkdirSync, chmodSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
const source = resolve(process.env.DB_PATH || './data/iturno.sqlite');
if (!existsSync(source)) throw new Error('La base de datos no existe. Iniciá la aplicación primero.');
const directory = resolve(process.env.BACKUP_DIR || './backups');
mkdirSync(directory, { recursive: true, mode: 0o700 });
const destination = resolve(directory, `iturno-${new Date().toISOString().replace(/[:.]/g, '-')}.sqlite`);
const db = new DatabaseSync(source, { readOnly: true });
try { await backup(db, destination); chmodSync(destination, 0o600); console.log(`Backup consistente creado: ${destination}`); }
finally { db.close(); }
