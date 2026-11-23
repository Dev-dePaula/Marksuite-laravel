const KEY = 'marksuite_token';
let token = null;
try { token = localStorage.getItem(KEY); } catch { /* storage indisponível */ }

export const getToken = () => token;
export function setToken(t) {
  token = t;
  try { t ? localStorage.setItem(KEY, t) : localStorage.removeItem(KEY); } catch { /* ignorar */ }
}

async function request(method, path, body) {
  const res = await fetch(`${import.meta.env.VITE_API_URL || ''}/api${path}`, {
    method,
    headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  if (res.status === 204) return null;
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    if (res.status === 401 && path !== '/auth/login' && path !== '/auth/register') window.dispatchEvent(new Event('marksuite:logout'));
    throw new Error(data.error || `Erro ${res.status}`);
  }
  return data;
}

export const api = {
  get: (p) => request('GET', p),
  post: (p, b = {}) => request('POST', p, b),
  put: (p, b) => request('PUT', p, b),
  patch: (p, b = {}) => request('PATCH', p, b),
  del: (p) => request('DELETE', p),
};
