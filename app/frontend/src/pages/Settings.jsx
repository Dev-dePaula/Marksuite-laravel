import React, { useEffect, useState } from 'react';
import { Loader, PageHead, ROLE_L, can, useApi, useToast } from '../ui.jsx';
import { api } from '../api.js';

const FIELDS = [['prop', 'Alerta — proposta sem resposta há (dias)'], ['risk', 'Alerta — risco de projecto acima de (%)'], ['cob', 'Alerta — cobrança vencida há (dias)'],
  ['health', 'Saúde mínima aceitável'], ['openingCash', 'Saldo de abertura de caixa (AOA)']];

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
            <div className="field"><label htmlFor="up">Palavra-passe inicial</label><input id="up" type="password" value={f.password} onChange={set('password')} required minLength={8} /></div>
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
      {can(user, 'users') && <Users user={user} />}
    </>
  );
}
