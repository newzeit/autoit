import { randomBytes, scryptSync, timingSafeEqual, createHash } from 'node:crypto';
export const token = () => randomBytes(32).toString('hex');
export const hash = value => createHash('sha256').update(value).digest('hex');
export function passwordHash(password) {
  const salt = randomBytes(16).toString('hex');
  return `scrypt:${salt}:${scryptSync(password, salt, 64).toString('hex')}`;
}
export function verifyPassword(password, stored) {
  try {
    const [algorithm, salt, digest] = stored.split(':');
    if (algorithm !== 'scrypt' || !salt || !/^[a-f0-9]{128}$/.test(digest)) return false;
    return timingSafeEqual(scryptSync(password, salt, 64), Buffer.from(digest, 'hex'));
  } catch { return false; }
}
export const bearer = req => /^Bearer ([a-f0-9]{64})$/.exec(req.get('authorization') || '')?.[1];
export function sessionToken(req) {
  return /(?:^|;\s*)iturno_session=([a-f0-9]{64})(?:;|$)/.exec(req.get('cookie') || '')?.[1];
}
export const now = () => new Date().toISOString();
