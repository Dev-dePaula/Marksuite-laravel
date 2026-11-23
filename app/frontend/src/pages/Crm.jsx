import React, { useState } from 'react';
import { Ac, A, Confirm, FormModal, Icon, KpiRow, Loader, Modal, PageHead, STAGES, STAGE_L, Tag, can, fdate, useAction, useApi, useSave, useToast } from '../ui.jsx';
import { api } from '../api.js';

const daysAgo = (d) => Math.max(0, Math.round((Date.now() - new Date(d)) / 86400000));
const todayIso = () => new Date().toISOString().slice(0, 10);
// Critérios do Score de Prioridade Comercial (pesos: 25/20/20/15/10/10)
const PRIO = [['sPain', 'Dor comprovada (25%)'], ['sAccess', 'Acesso ao decisor (20%)'], ['sTiming', 'Momento estratégico (20%)'],
  ['sBudget', 'Capacidade de contratação (15%)'], ['sFit', 'Fit (10%)'], ['sMeeting', 'Probabilidade de reunião (10%)']];

const AGS = [['aCap', 'Capacidade demonstrada (25%)'], ['aCoh', 'Coerência institucional (20%)'], ['aProof', 'Prova comercial (20%)'], ['aDigital', 'Autoridade digital (20%)'], ['aConv', 'Conversão (15%)']];
const ROLE_L = { DECISOR: 'Decisor económico', PATROCINADOR: 'Patrocinador interno', UTILIZADOR: 'Utilizador', GATEKEEPER: 'Gatekeeper', PORTA: 'Porta de entrada' };
const DRAFT_L = [['nivel1', '1.º contacto (nível 1)'], ['nivel2', 'Follow-up (nível 2)'], ['nivel3', 'Guião reunião 20 min (nível 3)'], ['perguntas', 'Perguntas custo da dor'], ['proposta', 'Esboço de proposta']];
const OFFERS = ['Diagnóstico Executivo', 'Sprint de Correcção (30 dias)', 'Programa de Autoridade (90 dias)', 'Gestão Contínua'];

// Mesa de trabalho da oportunidade: mapa de influência + rascunhos de IA seguindo o playbook
function Workbench({ opp, canEdit, onClose }) {
  const contacts = useApi(`/opportunities/${opp.id}/contacts`);
  const toast = useToast();
  const [f, setF] = useState({ name: '', role: 'DECISOR', area: '', email: '' });
  const [kind, setKind] = useState('nivel1');
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const add = async (e) => {
    e.preventDefault();
    if (!f.name.trim()) return;
    try { await api.post(`/opportunities/${opp.id}/contacts`, { name: f.name, role: f.role, area: f.area, email: f.email }); setF({ ...f, name: '', area: '', email: '' }); contacts.reload(); } catch (er) { toast(er.message, true); }
  };
  const gen = async () => {
    setBusy(true); setText('');
    try { setText((await api.post(`/opportunities/${opp.id}/draft`, { kind })).text); } catch (er) { toast(er.message, true); } finally { setBusy(false); }
  };
  return (
    <Modal title={`Preparar abordagem — ${opp.client}`} onClose={onClose}>
      <h4 style={{ margin: '0 0 6px' }}>Mapa de influência</h4>
      <table><tbody>{(contacts.data || []).map((c) => <tr key={c.id}><td><strong>{c.name}</strong></td><td>{ROLE_L[c.role]}</td><td>{c.area || '—'}</td><td>{c.email || '—'}</td>
        <td>{canEdit && <button className="iconbtn" title="Remover" onClick={async () => { await api.del(`/opportunities/${opp.id}/contacts/${c.id}`); contacts.reload(); }}><Icon n="del" size={16} /></button>}</td></tr>)}
        {contacts.data && !contacts.data.length && <tr><td>Sem contactos mapeados.</td></tr>}</tbody></table>
      {canEdit && <form onSubmit={add} style={{ display: 'flex', gap: 6, flexWrap: 'wrap', margin: '8px 0 16px' }}>
        <input placeholder="Nome" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
        <select value={f.role} onChange={(e) => setF({ ...f, role: e.target.value })}>{Object.entries(ROLE_L).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        <input placeholder="Área" value={f.area} onChange={(e) => setF({ ...f, area: e.target.value })} />
        <input placeholder="E-mail" type="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} />
        <button className="btn sm">Adicionar</button></form>}
      <h4 style={{ margin: '0 0 6px' }}>Rascunho com IA</h4>
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
        <select value={kind} onChange={(e) => setKind(e.target.value)}>{DRAFT_L.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        <button className="btn sm" disabled={busy || !canEdit} onClick={gen}>{busy ? 'A gerar…' : 'Gerar'}</button>
        {text && <button className="btn sm soft" onClick={() => navigator.clipboard?.writeText(text).then(() => toast('Copiado.'))}>Copiar</button>}</div>
      {text && <textarea readOnly rows={12} value={text} style={{ width: '100%', marginTop: 8 }} />}
      <small style={{ color: 'var(--muted)', display: 'block', marginTop: 6 }}>Rascunho gerado a partir dos dados da oportunidade. Reveja sempre antes de enviar (regra de ouro: não entregar o diagnóstico no 1.º contacto).</small>
    </Modal>
  );
}

export default function Crm({ user }) {
  const opps = useApi('/opportunities');
  const comps = useApi('/companies');
  const dash = useApi('/dashboard');
  const fun = useApi('/opportunities/funnel');
  const [filter, setFilter] = useState('');
  const [q, setQ] = useState('');
  const [edit, setEdit] = useState(null);
  const [del, setDel] = useState(null);
  const [bench, setBench] = useState(null);
  const act = useAction();
  const save = useSave();
  const w = can(user, 'opportunities');
  const staleDays = dash.data?.cfg.prop ?? 5;
  const reloadAll = () => { opps.reload(); dash.reload(); fun.reload(); };

  const fields = (comps.data || []).length ? [
    { k: 'client', l: 'Cliente', req: true }, { k: 'title', l: 'Oportunidade', req: true },
    { k: 'companyId', l: 'Empresa', type: 'select', req: true, num: true, opts: comps.data.map((c) => ({ v: c.id, l: c.name })), def: comps.data[0].id },
    { k: 'stage', l: 'Fase', type: 'select', req: true, opts: STAGES.map((s) => ({ v: s, l: STAGE_L[s] })), def: 'LEADS' },
    { k: 'value', l: 'Valor (AOA)', type: 'number', req: true, min: 0, def: 0 }, { k: 'probability', l: 'Probabilidade (%)', type: 'number', min: 0, max: 100, def: 20, req: true },
    { k: 'lastContact', l: 'Último contacto', type: 'date', req: true, def: new Date().toISOString().slice(0, 10) },
    // Prospecção (playbook)
    { k: 'pain', l: 'Dor identificada', wide: true, nullable: true }, { k: 'decisionMaker', l: 'Decisor', nullable: true },
    { k: 'email', l: 'E-mail do decisor', type: 'email', nullable: true },
    { k: 'trigger', l: 'Gatilho de timing (expansão, evento, contrato…)', nullable: true }, { k: 'triggerDate', l: 'Data do gatilho', type: 'date', nullable: true },
    { k: 'firstContact', l: 'Primeiro contacto', type: 'date', nullable: true }, { k: 'nextFollowup', l: 'Próximo follow-up', type: 'date', nullable: true },
    { k: 'nextAction', l: 'Próxima acção', wide: true, nullable: true },
    { k: 'replied', l: 'Respondeu?', type: 'select', req: true, num: true, opts: [{ v: 0, l: 'Não' }, { v: 1, l: 'Sim' }], def: 0 },
    { k: 'meetingAt', l: 'Data da reunião', type: 'date', nullable: true }, { k: 'diagnosedAt', l: 'Data do diagnóstico', type: 'date', nullable: true },
    { k: 'infoLevel', l: 'Nível de informação libertado', type: 'select', req: true, num: true, opts: [{ v: 0, l: '0 — nenhum' }, { v: 1, l: '1 — 2–3 pontos' }, { v: 2, l: '2 — evidência' }, { v: 3, l: '3 — diagnóstico' }], def: 0 },
    { k: 'offer', l: 'Oferta inicial (escada)', type: 'select', nullable: true, opts: OFFERS },
    ...AGS.map(([k, l]) => ({ k, l: `AGS · ${l} — nível já demonstrado (0–10)`, type: 'number', min: 0, max: 10, req: true, def: 0 })),
    ...PRIO.map(([k, l]) => ({ k, l: `${l} (0–10)`, type: 'number', min: 0, max: 10, req: true, def: 0 })),
  ] : [];

  return (
    <>
      <PageHead title="Comercial (CRM)" sub="Pipeline, oportunidades e follow-ups">
        <select value={filter} onChange={(e) => setFilter(e.target.value)} aria-label="Filtrar por empresa">
          <option value="">Todas as empresas</option>{(comps.data || []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
        {w && <button className="btn" onClick={() => setEdit({})}>+ Nova oportunidade</button>}
      </PageHead>
      <Loader state={opps}>{(all) => {
        const fup = all.filter((o) => o.nextFollowup && fdate(o.nextFollowup) <= todayIso()).sort((a, b) => (a.nextFollowup < b.nextFollowup ? -1 : 1));
        const timing = all.filter((o) => o.trigger && o.triggerDate && !o.firstContact && daysAgo(o.triggerDate) <= 3 && fdate(o.triggerDate) <= todayIso());
        const rows = all.filter((o) => (!filter || o.companyId === +filter) && (!q || (o.client + o.title).toLowerCase().includes(q.toLowerCase()))).sort((a, b) => b.priority - a.priority);
        const total = all.reduce((a, o) => a + o.value, 0);
        const stale = (o) => ['PROPOSTA', 'NEGOCIACAO'].includes(o.stage) && daysAgo(o.lastContact) >= staleDays;
        return (
          <>
            <KpiRow cols={5} items={[['Pipeline total', Ac(total), '#2563EB', 'crm'], ['Oportunidades', all.length, '#0EA5E9', 'crm'],
              ['Em fecho', Ac(all.filter((o) => o.stage === 'FECHO').reduce((a, o) => a + o.value, 0)), '#16A34A', 'crm'],
              ['Sem follow-up', all.filter(stale).length, '#DC2626', 'crm'], ['Ticket médio', Ac(all.length ? total / all.length : 0), '#7C3AED', 'crm']]} />
            {fun.data && <div className="panel" style={{ marginBottom: 14 }}><div className="hd"><h3>Funil de prospecção</h3></div>
              <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', padding: '4px 16px 14px' }}>
                {fun.data.map((f) => <div key={f.label} style={{ flex: '1 1 110px' }}><small style={{ color: 'var(--muted)' }}>{f.label}</small>
                  <div><strong style={{ fontSize: 20 }}>{f.count}</strong>{f.pct !== null && <span style={{ color: 'var(--muted)', marginLeft: 6 }}>{f.pct}%</span>}<div><small style={{ color: 'var(--muted)' }}>meta {f.meta}</small></div></div></div>)}
              </div></div>}
            {timing.length > 0 && <div className="panel" style={{ marginBottom: 14 }}><div className="hd"><h3>Gatilhos recentes — abordar nas 24–72h ({timing.length})</h3></div>
              <div className="tablewrap"><table><tbody>{timing.map((o) => <tr key={o.id}><td><strong>{o.client}</strong></td><td>{o.trigger}</td><td>{fdate(o.triggerDate)}</td>
                <td><button className="btn sm soft" onClick={() => setBench(o)}>Preparar</button></td></tr>)}</tbody></table></div></div>}
            {fup.length > 0 && <div className="panel" style={{ marginBottom: 14 }}><div className="hd"><h3>Follow-ups de hoje ({fup.length})</h3></div>
              <div className="tablewrap"><table><tbody>{fup.map((o) => <tr key={o.id}><td><strong>{o.client}</strong></td><td>{o.nextAction || '—'}</td>
                <td className={fdate(o.nextFollowup) < todayIso() ? 'dn' : ''}>{fdate(o.nextFollowup) < todayIso() ? `atrasado (${fdate(o.nextFollowup)})` : 'hoje'}</td>
                <td>{w && <button className="btn sm soft" onClick={() => setEdit(o)}>Actualizar</button>}</td></tr>)}</tbody></table></div></div>}
            <div className="kan">
              {STAGES.map((s) => {
                const it = rows.filter((o) => o.stage === s);
                return (
                  <div className="kcol" key={s}><h4>{STAGE_L[s]}</h4><div className="tot">{Ac(it.reduce((a, o) => a + o.value, 0))}</div>
                    {it.map((o) => (
                      <div className="kcard" key={o.id}><b>{o.client}</b><small>{o.title}</small>
                        <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6 }}><strong>{Ac(o.value)}</strong><span style={{ color: 'var(--muted)' }}>{o.probability}%</span></div></div>
                    ))}{!it.length && <div style={{ fontSize: 12.5, color: 'var(--muted)', padding: 6 }}>—</div>}
                  </div>
                );
              })}
            </div>
            <div className="panel" style={{ marginTop: 14 }}>
              <div className="hd"><h3>Oportunidades</h3><div className="spacer" />
                <input type="search" placeholder="Pesquisar" value={q} onChange={(e) => setQ(e.target.value)} style={{ padding: '7px 11px', border: '1px solid var(--line)', borderRadius: 8 }} /></div>
              <div className="tablewrap"><table><thead><tr><th>Cliente</th><th>Oportunidade</th><th>Empresa</th><th className="amt">Valor</th><th>Fase</th><th>Prioridade</th><th>AGS</th><th>Prob.</th><th>Último contacto</th><th /></tr></thead><tbody>
                {rows.map((o) => (
                  <tr key={o.id}><td><strong>{o.client}</strong></td><td>{o.title}</td><td>{o.company.name}</td><td className="amt">{A(o.value)}</td><td><Tag>{STAGE_L[o.stage]}</Tag></td><td><Tag>{o.priority}</Tag></td><td>{o.ags}</td><td>{o.probability}%</td>
                    <td className={stale(o) ? 'dn' : ''}>{daysAgo(o.lastContact) === 0 ? 'hoje' : `há ${daysAgo(o.lastContact)} dias`}</td>
                    <td>{w && <div className="actions">
                      <button className="btn sm soft" onClick={() => act(() => api.post(`/opportunities/${o.id}/advance`), o.stage === 'FECHO' ? `Contrato de ${o.client} fechado e registado na memória.` : 'Fase avançada.', reloadAll)}>{o.stage === 'FECHO' ? 'Fechar' : 'Avançar'}</button>
                      <button className="btn sm soft" onClick={() => setBench(o)}>Preparar</button>
                      <button className="iconbtn" title="Editar" onClick={() => setEdit(o)}><Icon n="edit" size={16} /></button>
                      <button className="iconbtn" title="Eliminar" onClick={() => setDel(o)}><Icon n="del" size={16} /></button></div>}</td></tr>
                ))}{!rows.length && <tr><td colSpan="10"><div className="empty"><strong>Sem oportunidades</strong></div></td></tr>}
              </tbody></table></div>
            </div>
          </>
        );
      }}</Loader>
      {edit && fields.length > 0 && (
        <FormModal title={edit.id ? 'Editar oportunidade' : 'Nova oportunidade'} fields={fields} onClose={() => setEdit(null)}
          initial={edit.id ? { ...edit, lastContact: fdate(edit.lastContact), replied: edit.replied ? 1 : 0, ...Object.fromEntries(['triggerDate', 'firstContact', 'nextFollowup', 'meetingAt', 'diagnosedAt'].map((k) => [k, edit[k] ? fdate(edit[k]) : ''])) } : {}}
          onSubmit={(v) => save(() => (edit.id ? api.put(`/opportunities/${edit.id}`, v) : api.post('/opportunities', v)), 'Oportunidade guardada.', reloadAll)} />
      )}
      {bench && <Workbench opp={bench} canEdit={w} onClose={() => setBench(null)} />}
      {del && <Confirm message={`Eliminar a oportunidade de ${del.client}?`} label="Eliminar" onClose={() => setDel(null)} onOk={() => act(() => api.del(`/opportunities/${del.id}`), 'Eliminada.', reloadAll)} />}
    </>
  );
}
