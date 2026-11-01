import { $, element, api, setupTheme, setupDialogs, badge, formatDate } from './app.js';
setupTheme(); setupDialogs();
let current, pending, busy = false, config;
const storageKey = 'iturno-conversation';
const save = () => sessionStorage.setItem(storageKey, JSON.stringify({ id: current.id, accessToken: current.accessToken, pending }));
function addMessage(role, content, date) {
  const row = element('div', `message-row ${role}`), bubble = element('div', 'bubble');
  if (role === 'assistant') row.append(element('span', 'message-avatar', '✳'));
  bubble.append(element('div', 'message-content', content));
  if (date) bubble.append(element('time', 'message-time', new Date(date).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' })));
  row.append(bubble); $('#messages').append(row); $('#messages').scrollTop = $('#messages').scrollHeight;
}
function render() {
  $('#messages').replaceChildren();
  current.messages.forEach(m => addMessage(m.role, m.content, m.created_at));
  $('#mode-label').textContent = current.mode === 'demo' ? 'MODO DEMO · Escenarios simulados, registros reales' : 'ASISTENCIA CON OPENAI · Diagnóstico orientativo';
  renderActions();
}
function renderActions() {
  $('#suggestions').replaceChildren();
  const done = ['resolved', 'ticketed'].includes(current.status);
  $('#message').disabled = busy || done || !!pending;
  $('.send-button').disabled = busy || done || !!pending;
  $('#retry-chat').hidden = !pending || busy;
  if (!done && !pending && !busy) {
    const options = current.state?.suggestions?.length ? current.state.suggestions : current.messages.length === 1 ? ['No tengo sonido', 'Mi equipo no enciende', 'Quiero mejorar mi PC'] : [];
    for (const suggestion of options) { const btn = element('button', 'suggestion', suggestion); btn.addEventListener('click', () => send(suggestion)); $('#suggestions').append(btn); }
  }
  const banner = $('#case-banner'); banner.replaceChildren(); banner.hidden = !['awaiting_details', 'resolved', 'ticketed'].includes(current.status);
  if (current.status === 'awaiting_details') { banner.append(element('span', '', 'Tenemos el contexto. Coordinemos la atención.')); const btn = element('button', 'button primary compact', 'Completar mis datos ↗'); btn.addEventListener('click', openTicket); banner.append(btn); }
  if (current.status === 'resolved') banner.append(element('span', '', '✓ Solución confirmada. Tu consulta quedó guardada.'));
  if (current.status === 'ticketed') { banner.append(element('strong', '', current.ticket?.code || 'Solicitud registrada')); const btn = element('button', 'text-button', 'Consultar seguimiento →'); btn.addEventListener('click', () => { $('#tracking-form').elements.code.value = current.ticket.code; $('#tracking-dialog').showModal(); }); banner.append(btn); }
}
function setBusy(value) { busy = value; renderActions(); $('#new-chat').disabled = value; }
async function start() {
  $('#chat-error').textContent = ''; $('#messages').replaceChildren(element('p', 'muted loading', 'Preparando tu espacio de soporte…'));
  try {
    config = await api('/api/config');
    let saved; try { saved = JSON.parse(sessionStorage.getItem(storageKey)); } catch { /* stale browser state */ }
    if (saved) {
      try { current = { ...await api(`/api/conversations/${saved.id}`, { accessToken: saved.accessToken }), accessToken: saved.accessToken }; pending = current.pendingRequest ? { requestId: current.pendingRequest.request_id, message: current.pendingRequest.message } : current.completedRequestIds?.includes(saved.pending?.requestId) ? null : saved.pending; }
      catch (error) { if (error.status !== 404 && error.status !== 400) throw error; sessionStorage.removeItem(storageKey); }
    }
    if (!current) { current = await api('/api/conversations', { method: 'POST', body: {} }); pending = null; }
    save(); render();
  } catch (error) { $('#messages').replaceChildren(); $('#chat-error').textContent = error.message; $('#retry-chat').hidden = false; }
}
async function send(message, retry = false) {
  if (busy || !current || ['resolved', 'ticketed'].includes(current.status)) return;
  if (pending && !retry) return;
  if (!retry) {
    pending = { requestId: crypto.randomUUID(), message: message.trim() }; if (!pending.message) { pending = null; return; }
    save(); addMessage('user', pending.message, new Date()); current.messages.push({ role: 'user', content: pending.message }); $('#message').value = '';
  }
  $('#chat-error').textContent = ''; setBusy(true);
  const indicator = element('div', 'typing', 'El asistente está revisando tu consulta…'); indicator.setAttribute('role', 'status'); $('#messages').append(indicator); $('#messages').scrollTop = $('#messages').scrollHeight;
  try {
    const result = await api('/api/chat', { method: 'POST', accessToken: current.accessToken, body: { conversationId: current.id, ...pending } });
    current = { ...current, ...result }; current.messages.push({ role: 'assistant', content: result.reply }); pending = null; save(); addMessage('assistant', result.reply, new Date());
  } catch (error) { $('#chat-error').textContent = error.message; }
  finally { indicator.remove(); setBusy(false); if (!$('#message').disabled) $('#message').focus({ preventScroll: true }); }
}
$('#chat-form').addEventListener('submit', event => { event.preventDefault(); send($('#message').value); });
$('#message').addEventListener('keydown', event => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); $('#chat-form').requestSubmit(); } });
$('#retry-chat').addEventListener('click', () => pending ? send(pending.message, true) : start());
$('#new-chat').addEventListener('click', async () => {
  if (busy) return;
  if (current?.messages.length > 1 && !confirm('¿Iniciar una nueva consulta? La actual seguirá guardada en el historial del negocio.')) return;
  sessionStorage.removeItem(storageKey); current = null; pending = null; await start();
});
document.querySelectorAll('[data-prompt]').forEach(btn => btn.addEventListener('click', () => { $('#chat').scrollIntoView({ behavior: 'smooth', block: 'start' }); if (current && !['resolved', 'ticketed'].includes(current.status)) send(btn.dataset.prompt); else $('#chat-error').textContent = 'Iniciá una nueva conversación con el botón + para consultar otro problema.'; }));
function openTicket() { const form = $('#ticket-form'); if (!form.elements.description.value) form.elements.description.value = current.messages.find(m => m.role === 'user')?.content || ''; $('#ticket-dialog').showModal(); }
$('#ticket-form').addEventListener('submit', async event => {
  event.preventDefault(); const form = event.currentTarget, btn = form.querySelector('[type=submit]'); btn.disabled = true; $('#ticket-error').textContent = '';
  try {
    const data = Object.fromEntries(new FormData(form)); data.consent = form.elements.consent.checked;
    const result = await api('/api/tickets', { method: 'POST', accessToken: current.accessToken, body: { conversationId: current.id, ...data } });
    current.ticket = result.ticket; current.status = 'ticketed';
    if (result.messages) current.messages = result.messages;
    else current.messages = (await api(`/api/conversations/${current.id}`, { accessToken: current.accessToken })).messages;
    save(); render(); $('#ticket-dialog').close();
  } catch (error) { $('#ticket-error').textContent = error.message; } finally { btn.disabled = false; }
});
$('#track-open').addEventListener('click', () => $('#tracking-dialog').showModal());
$('#tracking-form').addEventListener('submit', async event => {
  event.preventDefault(); $('#tracking-error').textContent = ''; $('#tracking-result').replaceChildren(); const btn = event.currentTarget.querySelector('button'); btn.disabled = true;
  try {
    const t = await api('/api/tracking', { method: 'POST', body: Object.fromEntries(new FormData(event.currentTarget)) });
    const result = $('#tracking-result'); result.append(element('h3', '', t.code), badge(t.status), element('p', 'muted', `Última actualización: ${formatDate(t.updated_at)}`));
    for (const change of t.history) result.append(element('p', 'timeline-event', `${formatDate(change.created_at)} · ${change.content}`));
  } catch (error) { $('#tracking-error').textContent = error.message; } finally { btn.disabled = false; }
});
await start();
