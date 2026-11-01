export const $ = selector => document.querySelector(selector);
export function element(tag, className, content) { const el = document.createElement(tag); if (className) el.className = className; if (content != null) el.textContent = content; return el; }
export async function api(path, { method = 'GET', body, accessToken } = {}) {
  let response;
  try { response = await fetch(path, { method, headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'ITurno', ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) }); }
  catch { throw new Error('No hay conexión con el servidor. Revisá tu conexión y reintentá.'); }
  const data = await response.json();
  if (!response.ok) { const error = new Error(data.details?.join(' · ') || data.error || 'Ocurrió un error'); error.status = response.status; throw error; }
  return data;
}
export function formatDate(date) { return new Date(date).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' }); }
export function badge(text) { return element('span', `badge ${['Resuelto', 'Entregado', 'resolved'].includes(text) ? 'success' : text === 'Alta' ? 'urgent' : ''}`, text); }
export function setupTheme() {
  const saved = localStorage.getItem('iturno-theme'); if (saved === 'dark') document.documentElement.dataset.theme = 'dark';
  $('.theme-toggle')?.addEventListener('click', () => { const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'; document.documentElement.dataset.theme = theme; localStorage.setItem('iturno-theme', theme); });
}
export function setupDialogs() {
  document.querySelectorAll('.close-dialog').forEach(btn => btn.addEventListener('click', () => btn.closest('dialog').close()));
}
