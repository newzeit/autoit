import { createInterface } from 'node:readline/promises';
import { readFileSync, writeFileSync, existsSync, chmodSync } from 'node:fs';
import { passwordHash } from '../src/security.js';
if (!process.stdin.isTTY) { console.error('Ejecutá este comando en una terminal interactiva.'); process.exit(1); }
const rl = createInterface({ input: process.stdin, output: process.stdout });
const username = (await rl.question('Usuario administrativo [admin]: ')).trim() || 'admin';
rl.close();
if (!/^[a-zA-Z0-9_.-]{3,50}$/.test(username)) { console.error('Usá 3 a 50 letras, números, puntos, guiones o guiones bajos.'); process.exit(1); }
function hidden(question) {
  process.stdout.write(question);
  return new Promise((resolve, reject) => {
    let value = '';
    process.stdin.setRawMode(true); process.stdin.resume(); process.stdin.setEncoding('utf8');
    const finish = () => { process.stdin.setRawMode(false); process.stdin.pause(); process.stdin.off('data', onData); process.stdout.write('\n'); };
    function onData(chunk) { for (const char of chunk) {
      if (char === '\u0003') { finish(); reject(new Error('Cancelado')); return; }
      if (char === '\r' || char === '\n') { finish(); resolve(value); return; }
      if (char === '\u007f' || char === '\b') value = value.slice(0, -1);
      else if (char >= ' ') value += char;
    } }
    process.stdin.on('data', onData);
  });
}
try {
  const password = await hidden('Contraseña (mínimo 12 caracteres, no se muestra): ');
  if (password.length < 12 || password.length > 200) throw new Error('La contraseña debe tener entre 12 y 200 caracteres.');
  const confirmation = await hidden('Repetí la contraseña: ');
  if (password !== confirmation) throw new Error('Las contraseñas no coinciden.');
  let env = readFileSync(existsSync('.env') ? '.env' : '.env.example', 'utf8');
  for (const [key, value] of [['ADMIN_USER', username], ['ADMIN_PASSWORD_HASH', passwordHash(password)]]) {
    const line = `${key}=${value}`;
    env = new RegExp(`^${key}=.*$`, 'm').test(env) ? env.replace(new RegExp(`^${key}=.*$`, 'm'), line) : `${env.trimEnd()}\n${line}\n`;
  }
  writeFileSync('.env', env, { mode: 0o600 }); chmodSync('.env', 0o600);
  // Invalidate previous sessions after a password change.
  const { openDatabase } = await import('../src/db/index.js'); const db = openDatabase(); db.prepare('DELETE FROM sessions').run(); db.close();
  console.log('Administrador configurado en .env. Reiniciá el servidor para aplicar el cambio.');
} catch (error) { console.error(error.message); process.exitCode = 1; }
