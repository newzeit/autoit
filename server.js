import express from 'express';
import helmet from 'helmet';
import { rateLimit } from 'express-rate-limit';
import { z } from 'zod';
import { randomUUID, randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { resolve, dirname } from 'node:path';
import { openDatabase, transaction } from './src/db/index.js';
import { token, hash, bearer, sessionToken, verifyPassword, now } from './src/security.js';
import { createAI, resultSchema } from './src/services/ai.js';
import { isConfirmation } from './src/services/demo-ai.js';
import { GREETING, STATES, PRIORITIES } from './src/constants.js';
const root = dirname(fileURLToPath(import.meta.url));
const uuid = z.string().uuid();
const text = (max, min = 1) => z.string().trim().min(min).max(max);
const contactSchema = text(150, 5).refine(s => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s) || /^[+\d ()-]{7,30}$/.test(s) && s.replace(/\D/g, '').length >= 7, 'Ingresá un email o teléfono válido');
const normalizeContact = c => c.includes('@') ? c.toLowerCase().trim() : c.replace(/\D/g, '');
class HttpError extends Error { constructor(status, message) { super(message); this.status = status; } }
const requireThat = (condition, status, message) => { if (!condition) throw new HttpError(status, message); };
export function createApp({ db = openDatabase(), ai = createAI(), adminUser = process.env.ADMIN_USER || 'admin', adminHash = process.env.ADMIN_PASSWORD_HASH || '', production = process.env.NODE_ENV === 'production', limits = true } = {}) {
  const app = express();
  app.disable('x-powered-by');
  if (process.env.TRUST_PROXY === '1') app.set('trust proxy', 'loopback');
  app.use(helmet({ contentSecurityPolicy: { directives: { defaultSrc: ["'self'"], scriptSrc: ["'self'"], styleSrc: ["'self'"], imgSrc: ["'self'", 'data:'], fontSrc: ["'self'"], connectSrc: ["'self'"], upgradeInsecureRequests: production ? [] : null } } }));
  app.use(express.json({ limit: '16kb' }));
  app.use('/api', (_req, res, next) => { res.set('Cache-Control', 'no-store'); next(); });
  app.use('/api', (req, _res, next) => {
    if (['POST', 'PATCH', 'DELETE'].includes(req.method)) {
      requireThat(req.is('application/json'), 415, 'Se requiere application/json');
      requireThat(req.get('x-requested-with') === 'ITurno', 403, 'Solicitud no autorizada');
      const origin = req.get('origin');
      if (origin) requireThat(origin === `${req.protocol}://${req.get('host')}`, 403, 'Origen no autorizado');
    }
    next();
  });
  if (limits) app.use('/api', rateLimit({ windowMs: 60000, limit: 120, standardHeaders: 'draft-8', legacyHeaders: false, message: { error: 'Demasiadas solicitudes. Esperá un minuto.' } }));
  const tight = limits ? rateLimit({ windowMs: 15 * 60000, limit: 10, standardHeaders: 'draft-8', legacyHeaders: false, message: { error: 'Demasiados intentos. Reintentá en 15 minutos.' } }) : (_req, _res, next) => next();
  const chatLimit = limits ? rateLimit({ windowMs: 60000, limit: 20, standardHeaders: 'draft-8', legacyHeaders: false, message: { error: 'Esperá un minuto antes de continuar.' } }) : (_req, _res, next) => next();
  const getConversation = req => {
    const id = uuid.parse(req.params.id || req.body.conversationId);
    const c = db.prepare('SELECT * FROM conversations WHERE id=?').get(id);
    requireThat(c && bearer(req) && c.token_hash === hash(bearer(req)), 404, 'Conversación no disponible');
    return c;
  };
  const messagesFor = id => db.prepare('SELECT role,content,created_at FROM messages WHERE conversation_id=? ORDER BY id').all(id);
  const ticketFor = id => db.prepare('SELECT id,code,status,priority,created_at FROM tickets WHERE conversation_id=?').get(id);
  const insertMessage = (id, role, content) => db.prepare('INSERT INTO messages(conversation_id,role,content,created_at) VALUES (?,?,?,?)').run(id, role, content, now());
  const admin = (req, _res, next) => {
    const key = sessionToken(req);
    const session = key && db.prepare('SELECT * FROM sessions WHERE token_hash=? AND expires_at>?').get(hash(key), Date.now());
    requireThat(session, 401, 'Iniciá sesión para continuar'); req.admin = session.username; next();
  };
  app.get('/api/health', (_req, res) => { db.prepare('SELECT 1').get(); res.json({ ok: true, mode: ai.mode }); });
  app.get('/api/config', (_req, res) => res.json({ mode: ai.mode, states: STATES, priorities: PRIORITIES }));
  app.post('/api/conversations', chatLimit, (_req, res) => {
    const id = randomUUID(), accessToken = token(), date = now();
    transaction(db, () => {
      db.prepare('INSERT INTO conversations(id,token_hash,created_at,updated_at,mode) VALUES (?,?,?,?,?)').run(id, hash(accessToken), date, date, ai.mode);
      insertMessage(id, 'assistant', GREETING);
    });
    res.status(201).json({ id, accessToken, mode: ai.mode, messages: messagesFor(id), status: 'active', state: {} });
  });
  app.get('/api/conversations/:id', (req, res) => {
    const c = getConversation(req), state = JSON.parse(c.state);
    res.json({ id: c.id, messages: messagesFor(c.id), category: c.category, status: c.status, state, ticket: ticketFor(c.id), mode: c.mode, completedRequestIds: db.prepare('SELECT request_id FROM requests WHERE conversation_id=? AND response IS NOT NULL').all(c.id).map(r => r.request_id), pendingRequest: db.prepare('SELECT request_id,message FROM requests WHERE conversation_id=? AND response IS NULL ORDER BY rowid DESC LIMIT 1').get(c.id) || null });
  });
  const busy = new Set();
  app.post('/api/chat', chatLimit, async (req, res) => {
    const input = z.object({ conversationId: uuid, requestId: uuid, message: text(2000) }).strict().parse(req.body);
    const c = getConversation(req);
    const previousRequest = db.prepare('SELECT * FROM requests WHERE conversation_id=? AND request_id=?').get(c.id, input.requestId);
    if (previousRequest) {
      requireThat(previousRequest.message === input.message, 409, 'El identificador ya fue usado con otro mensaje');
      if (previousRequest.response) return res.json(JSON.parse(previousRequest.response));
    }
    requireThat(!busy.has(c.id), 409, 'Hay una respuesta en proceso. Reintentá en unos segundos.');
    requireThat(!['resolved', 'ticketed'].includes(c.status), 409, 'El caso ya está registrado. Iniciá una nueva conversación.');
    const pending = db.prepare('SELECT request_id FROM requests WHERE conversation_id=? AND response IS NULL').get(c.id);
    requireThat(!pending || pending.request_id === input.requestId, 409, 'Reintentá primero el mensaje pendiente');
    const history = messagesFor(c.id);
    requireThat(history.length < 101 || previousRequest, 422, 'La conversación alcanzó el límite. Iniciá una nueva consulta.');
    busy.add(c.id);
    try {
      if (!previousRequest) transaction(db, () => {
        db.prepare('INSERT INTO requests(conversation_id,request_id,message) VALUES (?,?,?)').run(c.id, input.requestId, input.message);
        insertMessage(c.id, 'user', input.message);
        db.prepare('UPDATE conversations SET updated_at=? WHERE id=?').run(now(), c.id);
      });
      const oldState = JSON.parse(c.state);
      let result;
      try {
        const raw = await ai.reply(messagesFor(c.id), oldState);
        result = { ...raw, ...resultSchema.parse(raw) };
      } catch (error) {
        console.error('AI request failed:', error.name);
        throw new HttpError(503, 'El asistente no pudo responder. Tu mensaje quedó guardado. Reintentá el envío en unos segundos.');
      }
      // A model cannot mark a case solved without a preceding solution and an explicit customer confirmation.
      if (result.action === 'resolved' && !(oldState.action === 'suggested' && oldState.solution && isConfirmation(input.message))) {
        result.action = oldState.solution ? 'suggested' : 'continue';
        result.reply = oldState.solution ? 'Dejé la solución como sugerida. Para cerrarla, confirmá si el paso anterior solucionó el problema.' : 'Necesito comprender mejor el problema antes de registrar una solución. ¿Qué sucede con tu equipo?';
      }
      if (result.action === 'resolved') result.solution = oldState.solution;
      if (result.action === 'suggested' && !result.solution) { result.action = 'continue'; }
      if (oldState.action === 'escalate') result.action = 'escalate';
      const state = { ...oldState, ...result };
      const status = result.action === 'resolved' ? 'resolved' : result.action === 'suggested' ? 'suggested' : result.action === 'escalate' ? 'awaiting_details' : 'active';
      const output = { reply: result.reply, state, status, category: result.category, mode: ai.mode };
      transaction(db, () => {
        insertMessage(c.id, 'assistant', result.reply);
        db.prepare('UPDATE conversations SET state=?,status=?,category=?,updated_at=? WHERE id=?').run(JSON.stringify(state), status, result.category, now(), c.id);
        if (['suggested', 'resolved'].includes(result.action)) db.prepare(`INSERT INTO resolutions(conversation_id,problem,category,diagnosis,solution,confirmation,confirmed,created_at) VALUES (?,?,?,?,?,?,?,?) ON CONFLICT(conversation_id) DO UPDATE SET diagnosis=excluded.diagnosis, solution=excluded.solution, confirmation=excluded.confirmation, confirmed=excluded.confirmed`).run(c.id, history.find(m => m.role === 'user')?.content || input.message, result.category, result.diagnosis, result.solution, result.action === 'resolved' ? input.message : null, result.action === 'resolved' ? 1 : 0, now());
        db.prepare('UPDATE requests SET response=? WHERE conversation_id=? AND request_id=?').run(JSON.stringify(output), c.id, input.requestId);
      });
      res.json(output);
    } finally { busy.delete(c.id); }
  });
  app.post('/api/tickets', chatLimit, (req, res) => {
    const input = z.object({ conversationId: uuid, name: text(100, 2), contact: contactSchema, equipment: text(150, 2), description: text(2000, 5), consent: z.literal(true) }).strict().parse(req.body);
    const c = getConversation(req);
    const existing = ticketFor(c.id);
    if (existing) return res.json({ ticket: existing });
    requireThat(!busy.has(c.id), 409, 'Esperá la respuesta del asistente');
    requireThat(c.status === 'awaiting_details', 409, 'El asistente todavía no derivó este caso');
    const state = JSON.parse(c.state), date = now(), id = randomUUID(), clientId = randomUUID(), code = `IT-${randomBytes(10).toString('hex').toUpperCase()}`;
    transaction(db, () => {
      db.prepare('INSERT INTO clients(id,name,contact,created_at) VALUES (?,?,?,?)').run(clientId, input.name, normalizeContact(input.contact), date);
      db.prepare('INSERT INTO tickets(id,code,conversation_id,client_id,equipment,category,description,diagnosis,summary,priority,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)').run(id, code, c.id, clientId, input.equipment, c.category, input.description, state.diagnosis || 'Pendiente de evaluación técnica', state.summary || input.description, PRIORITIES.includes(state.priority) ? state.priority : 'Media', date, date);
      db.prepare('INSERT INTO ticket_events(ticket_id,kind,content,actor,created_at) VALUES (?,?,?,?,?)').run(id, 'status', 'Nuevo · solicitud registrada con consentimiento de contacto', 'Sistema', date);
      db.prepare("UPDATE conversations SET status='ticketed',updated_at=? WHERE id=?").run(date, c.id);
      insertMessage(c.id, 'assistant', `Tu solicitud quedó registrada con el código ${code}. Guardalo para consultar el estado junto con tu contacto. Un técnico coordinará los próximos pasos; todavía no hay un turno confirmado.`);
    });
    res.status(201).json({ ticket: ticketFor(c.id), messages: messagesFor(c.id) });
  });
  app.get('/api/tickets/:id', (req, res) => {
    const id = uuid.parse(req.params.id);
    const t = db.prepare('SELECT * FROM tickets WHERE id=?').get(id);
    requireThat(t, 404, 'Ticket no disponible');
    const c = db.prepare('SELECT token_hash FROM conversations WHERE id=?').get(t.conversation_id);
    requireThat(bearer(req) && c.token_hash === hash(bearer(req)), 404, 'Ticket no disponible');
    res.json({ id: t.id, code: t.code, status: t.status, category: t.category, equipment: t.equipment, created_at: t.created_at, updated_at: t.updated_at, history: db.prepare("SELECT content,created_at FROM ticket_events WHERE ticket_id=? AND kind='status' ORDER BY id").all(id) });
  });
  app.post('/api/tracking', tight, (req, res) => {
    const { code, contact } = z.object({ code: text(30).transform(s => s.toUpperCase()), contact: contactSchema }).strict().parse(req.body);
    const t = db.prepare('SELECT t.id,t.code,t.status,t.category,t.created_at,t.updated_at FROM tickets t JOIN clients c ON t.client_id=c.id WHERE t.code=? AND c.contact=?').get(code, normalizeContact(contact));
    requireThat(t, 404, 'No encontramos una solicitud con esos datos');
    res.json({ ...t, history: db.prepare("SELECT content,created_at FROM ticket_events WHERE ticket_id=? AND kind='status' ORDER BY id").all(t.id) });
  });
  const cookieOptions = { httpOnly: true, secure: production, sameSite: 'strict', path: '/api/admin', maxAge: 8 * 3600000 };
  app.post('/api/admin/login', tight, (req, res) => {
    const { username, password } = z.object({ username: text(100), password: z.string().min(1).max(200) }).strict().parse(req.body);
    requireThat(adminHash, 503, 'La administración no está configurada. Ejecutá npm run admin:setup.');
    const validPassword = verifyPassword(password, adminHash);
    requireThat(validPassword && username === adminUser, 401, 'Usuario o contraseña incorrectos');
    const access = token();
    db.prepare('DELETE FROM sessions WHERE expires_at<=?').run(Date.now());
    db.prepare('INSERT INTO sessions(token_hash,username,expires_at) VALUES (?,?,?)').run(hash(access), username, Date.now() + cookieOptions.maxAge);
    res.cookie('iturno_session', access, cookieOptions).json({ username });
  });
  app.use('/api/admin', admin);
  app.get('/api/admin/me', (req, res) => res.json({ username: req.admin }));
  app.post('/api/admin/logout', (req, res) => {
    db.prepare('DELETE FROM sessions WHERE token_hash=?').run(hash(sessionToken(req)));
    res.clearCookie('iturno_session', { ...cookieOptions, maxAge: undefined }).json({ ok: true });
  });
  app.get('/api/admin/stats', (_req, res) => {
    const count = sql => db.prepare(sql).get().n;
    const received = count('SELECT COUNT(*) n FROM conversations WHERE EXISTS (SELECT 1 FROM messages WHERE conversation_id=conversations.id AND role=\'user\')');
    const resolvedAI = count('SELECT COUNT(*) n FROM resolutions WHERE confirmed=1');
    res.json({ received, resolvedAI, tickets: count('SELECT COUNT(*) n FROM tickets'), pending: count("SELECT COUNT(*) n FROM tickets WHERE status NOT IN ('Resuelto','Entregado','Cancelado')"), resolvedTickets: count("SELECT COUNT(*) n FROM tickets WHERE status IN ('Resuelto','Entregado')"), automaticRate: received ? Math.round(resolvedAI / received * 100) : 0, frequent: db.prepare("SELECT category,COUNT(*) count FROM conversations WHERE EXISTS (SELECT 1 FROM messages WHERE conversation_id=conversations.id AND role='user') GROUP BY category ORDER BY count DESC LIMIT 5").all() });
  });
  app.get('/api/admin/tickets', (req, res) => {
    const query = z.object({ status: z.enum(STATES).optional(), priority: z.enum(PRIORITIES).optional(), q: z.string().max(100).optional() }).parse(req.query);
    const where = [], params = [];
    if (query.status) { where.push('t.status=?'); params.push(query.status); }
    if (query.priority) { where.push('t.priority=?'); params.push(query.priority); }
    if (query.q) { where.push("(c.name LIKE ? ESCAPE '\\' OR t.code LIKE ? ESCAPE '\\')"); const like = `%${query.q.replace(/[\\%_]/g, '\\$&')}%`; params.push(like, like); }
    res.json(db.prepare(`SELECT t.*,c.name,c.contact FROM tickets t JOIN clients c ON t.client_id=c.id ${where.length ? 'WHERE ' + where.join(' AND ') : ''} ORDER BY t.created_at DESC LIMIT 200`).all(...params));
  });
  app.get('/api/admin/tickets/:id', (req, res) => {
    const id = uuid.parse(req.params.id);
    const t = db.prepare('SELECT t.*,c.name,c.contact FROM tickets t JOIN clients c ON t.client_id=c.id WHERE t.id=?').get(id);
    requireThat(t, 404, 'Ticket no encontrado');
    res.json({ ...t, messages: messagesFor(t.conversation_id), events: db.prepare('SELECT * FROM ticket_events WHERE ticket_id=? ORDER BY id').all(id) });
  });
  app.patch('/api/admin/tickets/:id', (req, res) => {
    const id = uuid.parse(req.params.id);
    const input = z.object({ status: z.enum(STATES).optional(), priority: z.enum(PRIORITIES).optional(), note: text(3000).optional() }).strict().refine(s => Object.keys(s).length > 0).parse(req.body);
    const t = db.prepare('SELECT * FROM tickets WHERE id=?').get(id);
    requireThat(t, 404, 'Ticket no encontrado');
    transaction(db, () => {
      const date = now();
      db.prepare('UPDATE tickets SET status=?,priority=?,updated_at=? WHERE id=?').run(input.status || t.status, input.priority || t.priority, date, id);
      const event = (kind, content) => db.prepare('INSERT INTO ticket_events(ticket_id,kind,content,actor,created_at) VALUES (?,?,?,?,?)').run(id, kind, content, req.admin, date);
      if (input.status && input.status !== t.status) event('status', `${t.status} → ${input.status}`);
      if (input.priority && input.priority !== t.priority) event('priority', `${t.priority} → ${input.priority}`);
      if (input.note) event('note', input.note);
    });
    res.json({ ok: true });
  });
  app.get('/api/admin/conversations', (req, res) => {
    const kind = z.enum(['all', 'resolved', 'suggested', 'human']).parse(req.query.kind || 'all');
    const where = kind === 'resolved' ? 'WHERE r.confirmed=1' : kind === 'suggested' ? 'WHERE r.confirmed=0 AND c.status!=\'ticketed\'' : kind === 'human' ? "WHERE c.status IN ('ticketed','awaiting_details')" : '';
    res.json(db.prepare(`SELECT c.id,c.created_at,c.category,c.status,c.mode,r.problem,r.diagnosis,r.solution,r.confirmed,r.confirmation,(SELECT content FROM messages WHERE conversation_id=c.id AND role='user' ORDER BY id LIMIT 1) first_message FROM conversations c LEFT JOIN resolutions r ON r.conversation_id=c.id ${where} ORDER BY c.created_at DESC LIMIT 200`).all());
  });
  app.get('/api/admin/conversations/:id', (req, res) => {
    const id = uuid.parse(req.params.id), c = db.prepare('SELECT id,category,status,state,mode,created_at FROM conversations WHERE id=?').get(id);
    requireThat(c, 404, 'Conversación no encontrada');
    res.json({ ...c, state: JSON.parse(c.state), messages: messagesFor(id) });
  });
  app.use('/api', (_req, _res, next) => next(new HttpError(404, 'Ruta no encontrada')));
  app.use(express.static(resolve(root, 'public'), { dotfiles: 'deny', etag: true }));
  app.use((error, _req, res, _next) => {
    if (error instanceof z.ZodError) return res.status(400).json({ error: 'Revisá los datos ingresados', details: error.issues.map(i => `${i.path.join('.')}: ${i.message}`) });
    if (error.type === 'entity.too.large') return res.status(413).json({ error: 'El mensaje es demasiado grande' });
    if (error instanceof SyntaxError && error.status === 400) return res.status(400).json({ error: 'JSON inválido' });
    if (!error.status) console.error('Server error:', error.name);
    res.status(error.status || 500).json({ error: error.status ? error.message : 'No pudimos completar la operación' });
  });
  return { app, db };
}
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const { app, db } = createApp();
  const server = app.listen(Number(process.env.PORT || 3000), process.env.HOST || '127.0.0.1', () => console.log(`ITurno AI: http://${process.env.HOST || '127.0.0.1'}:${process.env.PORT || 3000} · ${process.env.AI_MODE || 'demo'}`));
  for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => server.close(() => { db.close(); process.exit(0); }));
}
