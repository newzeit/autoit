import { $, element, api, badge, formatDate, setupTheme, setupDialogs } from './app.js';
setupTheme(); setupDialogs();
let config, activeId;
function showLogin() { $('#login-view').hidden = false; $('#dashboard').hidden = true; $('#logout').hidden = true; $('#login-form').elements.password.value = ''; }
function field(label, value) { const block = element('div', 'detail-field'); block.append(element('span', 'small-muted', label), element('p', '', value || 'Sin información')); return block; }
function conversation(messages, target) { for (const m of messages) { const item = element('div', `transcript ${m.role}`); item.append(element('strong', '', m.role === 'user' ? 'Cliente' : 'Asistente'), element('p', '', m.content), element('small', 'muted', formatDate(m.created_at))); target.append(item); } }
async function guarded(fn) { try { return await fn(); } catch (error) { if (error.status === 401) { showLogin(); $('#login-error').textContent = 'Tu sesión terminó. Volvé a ingresar.'; } else $('#admin-error').textContent = error.message; } }
async function loadStats() {
  const stats = await api('/api/admin/stats'); $('#metrics').replaceChildren();
  for (const [label, value, note] of [['Consultas recibidas', stats.received, 'Conversaciones con mensajes'], ['Resueltas por IA', stats.resolvedAI, 'Confirmadas por el cliente'], ['Tickets generados', stats.tickets, 'Solicitudes registradas'], ['En seguimiento', stats.pending, 'Pendientes de finalizar'], ['Tickets resueltos', stats.resolvedTickets, 'Resueltos o entregados'], ['Resolución automática', `${stats.automaticRate}%`, 'Sobre consultas recibidas']]) {
    const card = element('article', 'metric'); card.append(element('span', '', label), element('strong', '', value), element('small', 'small-muted', note)); $('#metrics').append(card);
  }
  $('#frequent').replaceChildren();
  for (const row of stats.frequent) { const item = element('div', 'frequent-item'); item.append(element('span', '', row.category), badge(String(row.count))); $('#frequent').append(item); }
  if (!stats.frequent.length) $('#frequent').append(element('p', 'muted', 'Los motivos aparecerán al recibir consultas.'));
}
async function loadTickets() {
  const params = new URLSearchParams([...new FormData($('#filters'))].filter(([, value]) => value));
  const tickets = await api(`/api/admin/tickets?${params}`); $('#tickets-body').replaceChildren(); $('#ticket-empty').hidden = tickets.length > 0; $('#ticket-count').textContent = `${tickets.length} resultados · hasta 200 más recientes`;
  for (const t of tickets) {
    const tr = element('tr');
    const code = element('td', 'ticket-code', t.code), client = element('td'); client.append(element('strong', '', t.name), element('small', 'small-muted block', t.equipment));
    tr.append(code, client, element('td', '', t.category));
    for (const value of [t.status, t.priority]) { const td = element('td'); td.append(badge(value)); tr.append(td); }
    tr.append(element('td', 'small-muted', formatDate(t.created_at))); const action = element('td'), btn = element('button', 'text-button', 'Ver →'); btn.setAttribute('aria-label', `Ver ticket ${t.code}`); btn.addEventListener('click', () => guarded(() => showTicket(t.id))); action.append(btn); tr.append(action); $('#tickets-body').append(tr);
  }
}
async function loadHistory() {
  const list = await api(`/api/admin/conversations?kind=${$('#history-filter').value}`); $('#history-list').replaceChildren();
  for (const c of list) {
    const card = element('article', 'history-card'), heading = element('div', 'panel-heading');
    const outcome = c.status === 'resolved' ? 'Solución confirmada' : c.status === 'ticketed' ? 'Ticket generado' : c.status === 'awaiting_details' ? 'Datos pendientes' : c.status === 'suggested' ? 'Solución sin confirmar' : 'En conversación';
    heading.append(badge(outcome), element('small', 'muted', `${formatDate(c.created_at)} · ${c.mode}`)); card.append(heading, element('h3', '', c.problem || c.first_message || 'Conversación iniciada'), element('p', 'muted', c.category));
    if (c.solution) card.append(field('Solución propuesta', c.solution));
    if (c.confirmation) card.append(field('Confirmación del cliente', c.confirmation));
    const btn = element('button', 'text-button', 'Ver conversación →'); btn.addEventListener('click', () => guarded(async () => { const full = await api(`/api/admin/conversations/${c.id}`); $('#conversation-detail').replaceChildren(); conversation(full.messages, $('#conversation-detail')); $('#conversation-dialog').showModal(); })); card.append(btn); $('#history-list').append(card);
  }
  if (!list.length) $('#history-list').append(element('p', 'empty-state', 'Todavía no hay consultas para este filtro.'));
}
async function refresh() { $('#admin-error').textContent = ''; await Promise.all([loadStats(), loadTickets(), loadHistory()]); }
async function enter() {
  await api('/api/admin/me'); config = await api('/api/config');
  for (const [selector, values] of [['#status-filter', config.states], ['#priority-filter', config.priorities]]) { const select = $(selector); while (select.options.length > 1) select.remove(1); values.forEach(v => select.append(new Option(v, v))); }
  $('#login-view').hidden = true; $('#dashboard').hidden = false; $('#logout').hidden = false; await refresh();
}
$('#login-form').addEventListener('submit', async event => { event.preventDefault(); const form = event.currentTarget, btn = form.querySelector('button'); btn.disabled = true; $('#login-error').textContent = ''; try { await api('/api/admin/login', { method: 'POST', body: Object.fromEntries(new FormData(form)) }); form.elements.password.value = ''; await enter(); } catch (error) { $('#login-error').textContent = error.message; } finally { btn.disabled = false; } });
$('#logout').addEventListener('click', () => guarded(async () => { await api('/api/admin/logout', { method: 'POST', body: {} }); showLogin(); }));
$('#refresh').addEventListener('click', () => guarded(refresh));
$('#filters').addEventListener('submit', event => { event.preventDefault(); guarded(loadTickets); });
$('#history-filter').addEventListener('change', () => guarded(loadHistory));
for (const tab of ['tickets', 'history']) $(`#${tab}-tab`).addEventListener('click', () => { $('#ticket-section').hidden = tab !== 'tickets'; $('#history-section').hidden = tab !== 'history'; $('#tickets-tab').setAttribute('aria-selected', String(tab === 'tickets')); $('#history-tab').setAttribute('aria-selected', String(tab === 'history')); });
async function showTicket(id) {
  const t = await api(`/api/admin/tickets/${id}`); activeId = id; $('#detail-title').textContent = t.code; const target = $('#ticket-detail'); target.replaceChildren();
  const grid = element('div', 'detail-grid');
  for (const [label, value] of [['Cliente', t.name], ['Contacto', t.contact], ['Equipo', t.equipment], ['Categoría', t.category], ['Fecha', formatDate(t.created_at)], ['Estado', t.status]]) grid.append(field(label, value)); target.append(grid, field('Problema reportado', t.description), field('Diagnóstico preliminar · requiere validación técnica', t.diagnosis), field('Resumen de la conversación', t.summary));
  const form = element('form', 'ticket-edit'), controls = element('div', 'detail-grid');
  for (const [name, label, values, current] of [['status', 'Estado', config.states, t.status], ['priority', 'Prioridad', config.priorities, t.priority]]) { const l = element('label', '', label), select = element('select'); select.name = name; values.forEach(v => select.append(new Option(v, v))); select.value = current; l.append(select); controls.append(l); }
  const noteLabel = element('label', '', 'Nota interna · solo visible para el equipo'), note = element('textarea'); note.name = 'note'; note.maxLength = 3000; note.rows = 3; noteLabel.append(note); const error = element('p', 'error'); error.setAttribute('role', 'alert'); const submit = element('button', 'button primary', 'Guardar cambios'); submit.type = 'submit';
  form.append(controls, noteLabel, error, submit); form.addEventListener('submit', async event => { event.preventDefault(); submit.disabled = true; try { const values = Object.fromEntries(new FormData(form)); if (!values.note.trim()) delete values.note; await api(`/api/admin/tickets/${activeId}`, { method: 'PATCH', body: values }); await showTicket(activeId); await refresh(); } catch (e) { error.textContent = e.message; } finally { submit.disabled = false; } }); target.append(form, element('h3', 'subheading', 'Actividad del ticket'));
  for (const ev of t.events) { const item = element('div', 'timeline-event'); item.append(element('small', 'muted', `${formatDate(ev.created_at)} · ${ev.actor} · ${ev.kind === 'note' ? 'Nota interna' : ev.kind === 'priority' ? 'Prioridad' : 'Estado'}`), element('p', '', ev.content)); target.append(item); }
  const details = element('details', 'conversation-expand'); details.append(element('summary', '', 'Ver conversación completa')); conversation(t.messages, details); target.append(details);
  if (!$('#detail-dialog').open) $('#detail-dialog').showModal();
}
try { await enter(); } catch (error) { showLogin(); if (error.status !== 401) $('#login-error').textContent = error.message; }
