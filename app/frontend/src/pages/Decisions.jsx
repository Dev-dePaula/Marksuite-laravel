import React, { useState } from 'react';
import { A, AREA_L, Icon, KpiRow, Loader, Modal, PageHead, Tag, can, fdate, useAction, useApi, useToast } from '../ui.jsx';
import { api } from '../api.js';

export function Decisions({ user, go }) {
  const st = useApi('/decisions');
  const dash = useApi('/dashboard');
  const [open, setOpen] = useState(null);
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const toast = useToast();
  const w = can(user, 'decisions');

  const decide = async (action) => {
    if (action !== 'postpone' && !note.trim()) { toast('Indique a justificação — fica na Memória Corporativa.', true); return; }
    setBusy(true);
    try {
      await api.post(`/decisions/${open.id}/decide`, { action, justification: note });
      toast(action === 'approve' ? 'Decisão aprovada e registada na Memória Corporativa.' : action === 'reject' ? 'Decisão recusada e registada.' : 'Decisão adiada.');
      setOpen(null); setNote(''); st.reload(); dash.reload();
    } catch (e) { toast(e.message, true); } finally { setBusy(false); }
  };

  return (
    <>
      <PageHead title="Decision Engine" sub="Assuntos que precisam da sua atenção e decisões pendentes de aprovação">
        <button className="btn ghost" onClick={() => go('memoria')}>Memória Corporativa</button></PageHead>
      <div className="grid g-21">
        <div className="panel"><div className="hd"><h3>Decisões pendentes</h3><div className="spacer" /><span className="tag amb">{st.data?.length ?? 0} pendentes</span></div>
          <div className="bd"><Loader state={st}>{(rows) => (rows.length ? rows.map((d) => (
            <div key={d.id} style={{ display: 'flex', gap: 12, padding: 13, border: '1px solid var(--line)', borderRadius: 11, marginBottom: 10, alignItems: 'center' }}>
              <div className="ico"><Icon n="doc" size={16} /></div>
              <div style={{ flex: 1 }}><strong>{d.title}</strong><br /><small style={{ color: 'var(--muted)' }}>{d.subtitle}{d.amount ? ` · ${A(d.amount)}` : ''}</small></div>
              <button className="btn sm" onClick={() => { setOpen(d); setNote(''); }}>Decidir</button></div>
          )) : <div className="empty"><strong>Sem decisões pendentes</strong>Todas as aprovações estão em dia.</div>)}</Loader></div></div>
        <div className="panel"><div className="hd"><h3>Assuntos em atenção</h3></div><div className="bd" style={{ padding: '6px 16px' }}>
          <Loader state={dash}>{(d) => <ul className="list">{d.priorities.map((p) => (
            <li key={p.key}><div className="ico amb"><Icon n="bolt" size={16} /></div><div><strong>{p.area} — {p.title}</strong><small>{p.detail}</small></div></li>))}</ul>}</Loader></div></div>
      </div>
      {open && (
        <Modal title={open.title} onClose={() => setOpen(null)}>
          <p style={{ color: 'var(--muted)' }}>{open.subtitle}</p>
          {open.amount ? <div className="num" style={{ fontSize: 26, margin: '10px 0' }}>{A(open.amount)}</div> : null}
          <p style={{ fontSize: 14, marginBottom: 16 }}>{open.context}</p>
          {w ? (
            <>
              <div className="field"><label htmlFor="note">Justificação da decisão (fica na Memória Corporativa)</label>
                <textarea id="note" rows={3} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Razão da decisão..." /></div>
              <div style={{ display: 'flex', gap: 8 }}>
                <button className="btn ok" disabled={busy} onClick={() => decide('approve')}>Aprovar</button>
                <button className="btn danger" disabled={busy} onClick={() => decide('reject')}>Recusar</button>
                <button className="btn ghost" disabled={busy} onClick={() => decide('postpone')}>Adiar</button></div>
            </>
          ) : <p style={{ color: 'var(--muted)', fontSize: 13.5 }}>Apenas o Director-Geral pode decidir.</p>}
        </Modal>
      )}
    </>
  );
}

const OUT = { ACIMA: ['ok', 'Acima do esperado'], ABAIXO: ['amb', 'Abaixo do esperado'], EM_AVALIACAO: ['grey', 'Em avaliação'] };

export function Memory({ user, go }) {
  const st = useApi('/memory');
  const [q, setQ] = useState('');
  const [res, setRes] = useState(null);
  const [form, setForm] = useState({ actual: '', outcome: 'ACIMA' });
  const act = useAction();
  const w = can(user, 'decisions');
  return (
    <>
      <PageHead title="Memória Corporativa" sub="Cada decisão importante fica guardada com contexto, responsável, razão e resultado">
        <input type="search" placeholder="Pesquisar decisão" value={q} onChange={(e) => setQ(e.target.value)} style={{ padding: '9px 12px', border: '1px solid var(--line)', borderRadius: 9 }} />
        <button className="btn" onClick={() => go('ia')}>Perguntar à memória</button></PageHead>
      <Loader state={st}>{(m) => (
        <>
          <KpiRow items={[['Decisões registadas', m.total, '#2563EB', 'brain'], ['Com resultado medido', m.measured, '#16A34A', 'brain'], ['Acima do esperado', m.above, '#7C3AED', 'brain'], ['Em avaliação', m.evaluating, '#F59E0B', 'brain']]} />
          {m.items.filter((i) => !q || (i.title + i.reason + AREA_L[i.area]).toLowerCase().includes(q.toLowerCase())).map((i) => (
            <div className="mem" key={i.id}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}><span className="id">Decisão #{String(i.id).padStart(6, '0')}</span><Tag kind="blue">{AREA_L[i.area]}</Tag><span style={{ flex: 1 }} /><small style={{ color: 'var(--muted)' }}>{fdate(i.date)}</small></div>
              <h4>{i.title}</h4>
              <dl className="dl" style={{ gridTemplateColumns: 'auto 1fr' }}>
                {[['Contexto', i.context], ['Responsável', i.responsible], ['Aprovado por', i.approvedBy], ['Razão', i.reason], ['Resultado esperado', i.expected]].map(([k, v]) => (<React.Fragment key={k}><dt>{k}</dt><dd style={{ textAlign: 'left' }}>{v}</dd></React.Fragment>))}
                <dt>Resultado real</dt><dd style={{ textAlign: 'left' }}>{i.actual || '—'} <Tag kind={OUT[i.outcome][0]}>{OUT[i.outcome][1]}</Tag>
                  {w && <button className="linklike" style={{ marginLeft: 8 }} onClick={() => { setRes(i); setForm({ actual: i.actual || '', outcome: i.outcome }); }}>Registar resultado</button>}</dd>
              </dl>
            </div>
          ))}
        </>
      )}</Loader>
      {res && (
        <Modal title={`Resultado — ${res.title}`} onClose={() => setRes(null)} footer={<><button className="btn ghost" onClick={() => setRes(null)}>Cancelar</button>
          <button className="btn" onClick={() => form.actual.trim() && act(() => api.put(`/memory/${res.id}/result`, form), 'Resultado registado.', () => { setRes(null); st.reload(); })}>Guardar</button></>}>
          <div className="field"><label htmlFor="ra">Resultado real</label><textarea id="ra" rows={3} value={form.actual} onChange={(e) => setForm({ ...form, actual: e.target.value })} /></div>
          <div className="field"><label htmlFor="ro">Avaliação</label><select id="ro" value={form.outcome} onChange={(e) => setForm({ ...form, outcome: e.target.value })}>
            {Object.entries(OUT).map(([k, [, l]]) => <option key={k} value={k}>{l}</option>)}</select></div>
        </Modal>
      )}
    </>
  );
}
