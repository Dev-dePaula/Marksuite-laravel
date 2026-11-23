import React, { useEffect, useState } from 'react';
import { Loader, PageHead, ROLE_L, can, useApi, useToast } from '../ui.jsx';
import { api } from '../api.js';
import QRCode from 'qrcode';

const FIELDS = [['prop', 'Alerta — proposta sem resposta há (dias)'], ['risk', 'Alerta — risco de projecto acima de (%)'], ['cob', 'Alerta — cobrança vencida há (dias)'],
  ['health', 'Saúde mínima aceitável'], ['openingCash', 'Saldo de abertura de caixa (AOA)']];

/** Segurança da conta: autenticação em dois passos (TOTP), troca de palavra-passe e terminar outras sessões. */
function Security({ user }) {
  const toast = useToast();
  const [on, setOn] = useState(!!user.twoFactor);
  const [setup, setSetup] = useState(null); // { secret, qr }
  const [code, setCode] = useState('');
  const [codes, setCodes] = useState(null);
  const [pw, setPw] = useState({ current: '', password: '' });
  const [off, setOff] = useState({ password: '', code: '' });
  const run = async (fn, ok) => { try { await fn(); if (ok) toast(ok); } catch (x) { toast(x.message, true); } };
  const start = () => run(async () => { const r = await api.post('/auth/2fa/setup'); setSetup({ secret: r.secret, qr: await QRCode.toDataURL(r.uri, { margin: 1, width: 180 }) }); });
  const enable = (e) => { e.preventDefault(); run(async () => { const r = await api.post('/auth/2fa/enable', { code }); setCodes(r.recoveryCodes); setOn(true); setSetup(null); setCode(''); }, 'Autenticação em dois passos activada.'); };
  const disable = (e) => { e.preventDefault(); run(async () => { await api.post('/auth/2fa/disable', off); setOn(false); setOff({ password: '', code: '' }); }, 'Autenticação em dois passos desactivada.'); };
  const changePw = (e) => { e.preventDefault(); run(async () => { await api.post('/auth/password', pw); setPw({ current: '', password: '' }); }, 'Palavra-passe alterada. As outras sessões foram terminadas.'); };
  return (
    <div className="panel" style={{ maxWidth: 860, marginTop: 18 }}>
      <div className="hd"><h3>Segurança da conta</h3></div>
      <div className="bd">
        <h4 style={{ margin: '0 0 6px' }}>Autenticação em dois passos {on ? '— activa ✓' : '— desactivada'}</h4>
        {!on && !setup && <><p style={{ margin: '0 0 8px', color: 'var(--muted)' }}>Protege a conta mesmo que a palavra-passe seja roubada. Use Google Authenticator, Microsoft Authenticator ou Authy.</p><button className="btn" onClick={start}>Activar</button></>}
        {setup && (
          <form onSubmit={enable}>
            <p>1. Leia o código QR na app autenticadora (ou introduza a chave <code>{setup.secret}</code>). 2. Escreva o código de 6 dígitos que a app mostra.</p>
            <img src={setup.qr} alt="QR 2FA" width={180} height={180} />
            <div className="field" style={{ maxWidth: 220 }}><label htmlFor="c2">Código de 6 dígitos</label><input id="c2" value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" autoComplete="one-time-code" required /></div>
            <button className="btn">Confirmar e activar</button>
          </form>
        )}
        {codes && <div className="errbox" style={{ marginTop: 10 }}><strong>Guarde estes códigos de recuperação num local seguro (cada um funciona uma vez; não voltam a ser mostrados):</strong><br />{codes.join('   ')}</div>}
        {on && (
          <form onSubmit={disable} style={{ marginTop: 8 }}>
            <div className="frow">
              <div className="field"><label htmlFor="op">Palavra-passe</label><input id="op" type="password" value={off.password} onChange={(e) => setOff({ ...off, password: e.target.value })} required /></div>
              <div className="field"><label htmlFor="oc">Código actual</label><input id="oc" value={off.code} onChange={(e) => setOff({ ...off, code: e.target.value })} required /></div>
            </div>
            <button className="btn ghost">Desactivar 2FA</button>
          </form>
        )}
        <h4 style={{ margin: '20px 0 6px' }}>Alterar palavra-passe</h4>
        <form onSubmit={changePw}>
          <div className="frow">
            <div className="field"><label htmlFor="pc">Palavra-passe actual</label><input id="pc" type="password" value={pw.current} onChange={(e) => setPw({ ...pw, current: e.target.value })} autoComplete="current-password" required /></div>
            <div className="field"><label htmlFor="pn">Nova (mín. 10, letras e números)</label><input id="pn" type="password" value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} autoComplete="new-password" required minLength={10} /></div>
          </div>
          <button className="btn">Alterar</button>{' '}
          <button type="button" className="btn ghost" onClick={() => run(() => api.post('/auth/logout-others'), 'Outras sessões terminadas.')}>Terminar outras sessões</button>
        </form>
      </div>
    </div>
  );
}

/** Utilizadores da empresa: o Director-Geral convida a equipa e define o perfil de cada um. */
function Users({ user }) {
  const st = useApi('/users');
  const toast = useToast();
  const [f, setF] = useState({ name: '', email: '', password: '', role: 'CFO' });
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const add = async (e) => {
    e.preventDefault();
    try { await api.post('/users', f); toast('Utilizador criado.'); setF({ name: '', email: '', password: '', role: 'CFO' }); st.reload(); } catch (x) { toast(x.message, true); }
  };
  const patch = async (u, body) => { try { await api.put(`/users/${u.id}`, body); st.reload(); } catch (x) { toast(x.message, true); } };
  return (
    <div className="panel" style={{ maxWidth: 860, marginTop: 18 }}>
      <div className="hd"><h3>Utilizadores da empresa</h3></div>
      <div className="bd">
        <Loader state={st}>{(rows) => (
          <div className="tbl"><table><thead><tr><th>Nome</th><th>Email</th><th>Perfil</th><th>Estado</th><th /></tr></thead><tbody>
            {rows.map((u) => (
              <tr key={u.id}>
                <td>{u.name}{u.isOwner ? ' ★' : ''}</td><td>{u.email}</td>
                <td><select value={u.role} disabled={u.isOwner} onChange={(e) => patch(u, { role: e.target.value })}>{Object.entries(ROLE_L).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></td>
                <td>{u.active ? 'Activo' : 'Desactivado'}</td>
                <td>{!u.isOwner && u.id !== user.id && <button className="btn sm ghost" onClick={() => patch(u, { active: !u.active })}>{u.active ? 'Desactivar' : 'Reactivar'}</button>}</td>
              </tr>
            ))}
          </tbody></table></div>
        )}</Loader>
        <form onSubmit={add} style={{ marginTop: 16 }}>
          <h4 style={{ margin: '0 0 8px' }}>Adicionar utilizador</h4>
          <div className="frow">
            <div className="field"><label htmlFor="un">Nome</label><input id="un" value={f.name} onChange={set('name')} required minLength={2} /></div>
            <div className="field"><label htmlFor="ue">Email</label><input id="ue" type="email" value={f.email} onChange={set('email')} required /></div>
            <div className="field"><label htmlFor="up">Palavra-passe inicial</label><input id="up" type="password" value={f.password} onChange={set('password')} required minLength={10} /></div>
            <div className="field"><label htmlFor="ur">Perfil</label><select id="ur" value={f.role} onChange={set('role')}>{Object.entries(ROLE_L).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></div>
          </div>
          <button className="btn">Adicionar</button>
        </form>
      </div>
    </div>
  );
}

export default function Settings({ user }) {
  const st = useApi('/settings');
  const [v, setV] = useState(null);
  const toast = useToast();
  const w = can(user, 'settings');
  useEffect(() => { if (st.data) setV(st.data); }, [st.data]);
  const submit = async (e) => {
    e.preventDefault();
    try { await api.put('/settings', Object.fromEntries(FIELDS.map(([k]) => [k, Number(v[k])]))); toast('Regras do motor de decisão aplicadas.'); st.reload(); } catch (x) { toast(x.message, true); }
  };
  return (
    <>
      <PageHead title="Configurações" sub="Regras do motor de decisão e utilizadores" />
      <Loader state={st}>{() => v && (
        <div className="panel" style={{ maxWidth: 640 }}><div className="hd"><h3>Motor de decisão</h3></div><form className="bd" onSubmit={submit}>
          <div className="frow">{FIELDS.map(([k, l]) => (<div className="field" key={k}><label htmlFor={k}>{l}</label>
            <input id={k} type="number" value={v[k]} disabled={!w} onChange={(e) => setV({ ...v, [k]: e.target.value })} /></div>))}</div>
          {w ? <button className="btn">Aplicar regras</button> : <small style={{ color: 'var(--muted)' }}>Apenas o Director-Geral pode alterar estas regras.</small>}
        </form></div>
      )}</Loader>
      <Security user={user} />
      {can(user, 'users') && <Users user={user} />}
    </>
  );
}
