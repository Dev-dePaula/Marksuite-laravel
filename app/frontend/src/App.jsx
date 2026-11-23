import React, { useEffect, useState } from 'react';
import { api, getToken, setToken } from './api.js';
import { Icon, ToastProvider, ROLE_L, initials, useToast } from './ui.jsx';
import Dashboard from './pages/Dashboard.jsx';
import Companies from './pages/Companies.jsx';
import Crm from './pages/Crm.jsx';
import Prospect from './pages/Prospect.jsx';
import Projects from './pages/Projects.jsx';
import Finance from './pages/Finance.jsx';
import Billing from './pages/Billing.jsx';
import Accounting from './pages/Accounting.jsx';
import { People, Operations, Courses } from './pages/People.jsx';
import { Decisions, Memory } from './pages/Decisions.jsx';
import { Okr, Bi } from './pages/Strategy.jsx';
import Ai from './pages/Ai.jsx';
import Settings from './pages/Settings.jsx';
import Monitor from './pages/Monitor.jsx';
import Analytics from './pages/Analytics.jsx';

const NAV = [
  ['cc', 'Centro de Comando', 'home', Dashboard], ['emp', 'Empresas', 'build', Companies], ['crm', 'Comercial (CRM)', 'crm', Crm], ['prosp', 'Prospecção IA', 'ai', Prospect],
  ['proj', 'Projectos', 'proj', Projects], ['fin', 'Financeiro', 'money', Finance], ['fat', 'Faturação', 'money', Billing], ['cont', 'Contabilidade', 'chart', Accounting], ['pes', 'Pessoas', 'people', People],
  ['ops', 'Operações', 'ops', Operations], ['decisoes', 'Decisões', 'check', Decisions], ['memoria', 'Memória Corporativa', 'brain', Memory],
  ['estr', 'Estratégia', 'target', Okr], ['bi', 'Relatórios (BI)', 'chart', Bi], ['analise', 'Central de Análise', 'chart', Analytics], ['ia', 'Automação & IA', 'ai', Ai],
  ['uni', 'Universidade', 'cap', Courses], ['conf', 'Configurações', 'ops', Settings], ['mon', 'Monitorização', 'chart', Monitor],
];

function Login({ onLogin }) {
  const [mode, setMode] = useState('login');
  const [f, setF] = useState({ username: '', password: '', companyName: '', name: '', email: '', demo: true });
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);
  const set = (k) => (e) => setF({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });
  const reg = mode === 'register';
  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setErr('');
    try {
      const r = reg
        ? await api.post('/auth/register', { companyName: f.companyName, name: f.name, email: f.email, password: f.password, demo: f.demo })
        : await api.post('/auth/login', { username: f.username, password: f.password });
      setToken(r.token);
      onLogin(r.user);
    } catch (x) { setErr(x.message); } finally { setBusy(false); }
  };
  return (
    <div id="login">
      <div className="side">
        <div className="bgimg" />
        <div>
          <img className="logo-plate login-logo" src="/logo.png" alt="Marksuite" />
          <h1>Ver → Compreender → Decidir → Executar.</h1>
          <p>Não é mais um software de gestão. É um sistema de comando empresarial com IA, onde CRM, projectos, pessoas, facturação e contabilidade são instrumentos do motor de decisão. Crie a conta da sua empresa e comece já.</p>
          <div className="caps">{[['SEE', 'Ver o negócio'], ['THINK', 'Interpretar'], ['DECIDE', 'Decidir'], ['ACT', 'Executar']].map(([a, b]) => <div key={a}><b>{a}</b><small>{b}</small></div>)}</div>
        </div>
        <small style={{ color: '#9FB3CE' }}>© 2026 MarkSuite · Ambiente de testes multi-empresa</small>
      </div>
      <div className="form">
        <form onSubmit={submit}>
          <img className="form-logo" src="/logo.png" alt="Marksuite" />
          <h2>{reg ? 'Criar a conta da sua empresa' : 'Iniciar sessão'}</h2>
          <p className="hint">{reg ? 'Fica com o seu próprio ERP completo, isolado das outras empresas.' : 'Entre com o seu email (ou utilizador) e palavra-passe.'}</p>
          {reg && <>
            <div className="field"><label htmlFor="co">Nome da empresa</label><input id="co" value={f.companyName} onChange={set('companyName')} required minLength={2} maxLength={120} /></div>
            <div className="field"><label htmlFor="nm">O seu nome</label><input id="nm" value={f.name} onChange={set('name')} autoComplete="name" required minLength={2} maxLength={120} /></div>
            <div className="field"><label htmlFor="em">Email</label><input id="em" type="email" value={f.email} onChange={set('email')} autoComplete="email" required /></div>
          </>}
          {!reg && <div className="field"><label htmlFor="user">Email ou utilizador</label><input id="user" value={f.username} onChange={set('username')} autoComplete="username" required /></div>}
          <div className="field"><label htmlFor="pass">Palavra-passe{reg ? ' (mín. 8 caracteres)' : ''}</label><input id="pass" type="password" value={f.password} onChange={set('password')} autoComplete={reg ? 'new-password' : 'current-password'} required minLength={reg ? 8 : 1} /></div>
          {reg && <label style={{ display: 'flex', gap: 8, alignItems: 'center', margin: '0 0 12px', fontSize: 13 }}><input type="checkbox" checked={f.demo} onChange={set('demo')} /> Carregar dados de demonstração (recomendado para testar)</label>}
          <button className="btn full" disabled={busy}>{busy ? 'Aguarde…' : reg ? 'Criar conta e entrar' : 'Entrar'}</button>
          <div className="login-err" role="alert">{err}</div>
          <p style={{ textAlign: 'center', marginTop: 14, fontSize: 13 }}>
            {reg ? 'Já tem conta? ' : 'Ainda não tem conta? '}
            <button type="button" style={{ color: 'var(--blue, #2563EB)', fontWeight: 600, padding: 0 }} onClick={() => { setMode(reg ? 'login' : 'register'); setErr(''); }}>{reg ? 'Iniciar sessão' : 'Registar a minha empresa'}</button>
          </p>
        </form>
      </div>
    </div>
  );
}

function Shell({ user, onLogout }) {
  const [page, setPage] = useState('cc');
  const [open, setOpen] = useState(false);
  const [now, setNow] = useState(new Date());
  const toast = useToast();
  useEffect(() => { const t = setInterval(() => setNow(new Date()), 30000); return () => clearInterval(t); }, []);
  const go = (p) => { setPage(p); setOpen(false); window.scrollTo(0, 0); };
  const Page = NAV.find((n) => n[0] === page)[3];
  const h = now.getHours();
  const greet = h < 12 ? 'Bom dia' : h < 19 ? 'Boa tarde' : 'Boa noite';
  const weekday = now.toLocaleDateString('pt-PT', { weekday: 'long' });
  return (
    <div id="app" className="on">
      <aside className={`side-nav ${open ? 'open' : ''}`}>
        <div className="brand"><div className="logo-plate"><img src="/logo.png" alt="Marksuite" /></div></div>
        <nav className="nav">
          {NAV.filter(([id]) => id !== 'mon' || user.role === 'DIRECTOR_GERAL').map(([id, l, ic]) => (
            <button key={id} className={page === id ? 'active' : ''} onClick={() => go(id)}><Icon n={ic} size={18} />{l}</button>
          ))}
        </nav>
        <div className="side-quote"><p>"Disciplina hoje.<br />Mais resultados amanhã."</p><small>{user.tenant?.name || 'MarkSuite'}</small></div>
        <div className="side-foot">{user.tenant?.name} · <strong>{user.name}</strong> · <button style={{ color: '#93B4F5', fontWeight: 600, padding: 0 }} onClick={onLogout}>Sair</button></div>
      </aside>
      <div className="main">
        <header className="topbar">
          <button className="iconbtn menu-btn" onClick={() => setOpen(!open)} aria-label="Menu"><Icon n="home" size={18} /></button>
          <div className="spacer" />
          <div className="pill"><div className="avatar">{initials(user.name)}</div><div><strong>{user.name}</strong><br /><small style={{ color: 'var(--muted)' }}>{ROLE_L[user.role]}</small></div></div>
        </header>
        <main className="content">
          <Page user={user} go={go} greet={greet} now={now} weekday={weekday} toast={toast} />
        </main>
      </div>
    </div>
  );
}

function Root() {
  const [user, setUser] = useState(null);
  const [ready, setReady] = useState(!getToken());
  useEffect(() => {
    if (!getToken()) return;
    api.get('/auth/me').then(setUser).catch(() => setToken(null)).finally(() => setReady(true));
  }, []);
  // Reporta erros do navegador ao servidor (aparecem na página Monitorização)
  useEffect(() => {
    const send = (message, stack) => {
      if (!getToken()) return;
      api.post('/monitor/client-error', { message: String(message).slice(0, 500), stack: stack ? String(stack).slice(0, 8000) : undefined }).catch(() => {});
    };
    const onErr = (e) => send(e.message || 'Erro de JavaScript', e.error?.stack);
    const onRej = (e) => send(e.reason?.message || String(e.reason), e.reason?.stack);
    window.addEventListener('error', onErr);
    window.addEventListener('unhandledrejection', onRej);
    return () => { window.removeEventListener('error', onErr); window.removeEventListener('unhandledrejection', onRej); };
  }, []);
  useEffect(() => {
    const f = () => { setToken(null); setUser(null); };
    window.addEventListener('marksuite:logout', f);
    return () => window.removeEventListener('marksuite:logout', f);
  }, []);
  if (!ready) return <div className="loading">A carregar…</div>;
  if (!user) return <Login onLogin={setUser} />;
  return <Shell user={user} onLogout={() => { setToken(null); setUser(null); }} />;
}

export default function App() {
  return <ToastProvider><Root /></ToastProvider>;
}
