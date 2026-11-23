import React, { useEffect, useState } from 'react';
import { Confirm, Loader, PageHead, Tag, can, useAction, useApi, useToast } from '../ui.jsx';
import { api, getToken } from '../api.js';

const ST_L = { NOVO: 'Novo', ENVIADA: 'Enviada', RESPONDEU: 'Respondeu', DESCARTADO: 'Removido' };
const ST_K = { NOVO: 'blue', ENVIADA: 'amber', RESPONDEU: 'green', DESCARTADO: 'red' };
const list = (s) => { try { return JSON.parse(s) || []; } catch { return []; } };

async function openPdf(id) {
  const res = await fetch(`${import.meta.env.VITE_API_URL || ''}/api/prospect/${id}/pdf`, { headers: { Authorization: `Bearer ${getToken()}` } });
  if (!res.ok) throw new Error('Não foi possível gerar o PDF.');
  window.open(URL.createObjectURL(await res.blob()), '_blank');
}

function RunView({ runId, canEdit, cfg, onBack }) {
  const run = useApi(`/prospect/runs/${runId}`);
  const act = useAction();
  const toast = useToast();
  const [sel, setSel] = useState(null);
  const [send, setSend] = useState(null);
  const [mail, setMail] = useState('');
  const running = run.data?.status === 'A_CORRER';
  useEffect(() => { if (!running) return undefined; const t = setInterval(run.reload, 5000); return () => clearInterval(t); }, [running]); // eslint-disable-line react-hooks/exhaustive-deps
  const summary = (() => { try { return JSON.parse(run.data?.summary || '{}'); } catch { return {}; } })();
  return (
    <>
      <PageHead title={run.data ? `${run.data.niche} · ${run.data.location}` : 'Pesquisa'} sub={run.data ? `Empresa: ${run.data.company.name}` : ''}>
        <button className="btn soft" onClick={onBack}>← Pesquisas</button>
      </PageHead>
      <Loader state={run}>{(r) => (
        <>
          {running && <div className="panel" style={{ padding: 16, marginBottom: 14 }}>A pesquisar na internet e a analisar empresas… pode demorar até 2 minutos. Esta página actualiza sozinha.</div>}
          {r.status === 'FALHOU' && <div className="errbox">{r.error}</div>}
          {r.status === 'CONCLUIDA' && r.error && <div className="errbox">{r.error}</div>}
          {(summary.common?.length || summary.commonPains?.length) && (
            <div className="panel" style={{ marginBottom: 14 }}>
              <div className="hd"><h3>O que as empresas do nicho têm em comum</h3></div>
              <div style={{ padding: '0 16px 14px' }}>
                {!!summary.common?.length && <ul>{summary.common.map((x, i) => <li key={i}>{x}</li>)}</ul>}
                {!!summary.commonPains?.length && <><strong>Dores comuns</strong><ul>{summary.commonPains.map((x, i) => <li key={i}>{x}</li>)}</ul></>}
                {summary.opportunity && <p><strong>Oportunidade:</strong> {summary.opportunity}</p>}
              </div>
            </div>
          )}
          <div className="panel">
            <div className="hd"><h3>Empresas encontradas ({r.prospects.length})</h3></div>
            <div style={{ overflowX: 'auto' }}>
              <table>
                <thead><tr><th>Empresa</th><th>Telefone</th><th>E-mail</th><th>Estado</th><th /></tr></thead>
                <tbody>
                  {r.prospects.map((p) => (
                    <tr key={p.id}>
                      <td><strong>{p.name}</strong><br /><small style={{ color: 'var(--muted)' }}>{p.website ? <a href={p.website.startsWith('http') ? p.website : `https://${p.website}`} target="_blank" rel="noreferrer">{p.website}</a> : p.city || ''}</small></td>
                      <td>{p.phone || '—'}</td>
                      <td>{p.email || <em style={{ color: 'var(--muted)' }}>sem e-mail</em>}</td>
                      <td><Tag kind={ST_K[p.status]}>{ST_L[p.status] || p.status}</Tag></td>
                      <td style={{ whiteSpace: 'nowrap' }}>
                        <button className="btn sm soft" onClick={() => setSel(p)}>Proposta</button>{' '}
                        {canEdit && p.status === 'NOVO' && <button className="btn sm" onClick={() => (p.email ? setSend(p) : (setMail(''), setSel({ ...p, askMail: true })))}>Enviar</button>}
                        {canEdit && p.status === 'ENVIADA' && <button className="btn sm" onClick={() => act(() => api.post(`/prospect/${p.id}/reply`), 'Marcada como respondida — oportunidade passou para a equipa comercial.', run.reload)}>Respondeu</button>}
                      </td>
                    </tr>
                  ))}
                  {!r.prospects.length && !running && <tr><td colSpan={5}>Nenhuma empresa encontrada.</td></tr>}
                </tbody>
              </table>
            </div>
          </div>
          {sel && (
            <div className="modal-back" onClick={() => setSel(null)} style={{ position: 'fixed', inset: 0, background: 'rgba(15,23,42,.5)', display: 'grid', placeItems: 'center', zIndex: 60, padding: 12 }}>
              <div className="panel" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 640, width: '100%', maxHeight: '90vh', overflow: 'auto', padding: 18 }}>
                <h3 style={{ marginTop: 0 }}>{sel.name}</h3>
                {sel.askMail && canEdit && (
                  <form style={{ display: 'flex', gap: 6, marginBottom: 12 }} onSubmit={async (e) => { e.preventDefault(); await act(() => api.put(`/prospect/${sel.id}`, { email: mail }), 'E-mail guardado.', run.reload); setSel(null); }}>
                    <input type="email" required placeholder="E-mail da empresa" value={mail} onChange={(e) => setMail(e.target.value)} style={{ flex: 1 }} /><button className="btn sm">Guardar</button>
                  </form>
                )}
                {sel.proposal && <p>{sel.proposal}</p>}
                <h4>Dores</h4><ul>{list(sel.pains).map((x, i) => <li key={i}>{x}</li>)}</ul>
                <h4>Soluções que oferecemos</h4><ul>{list(sel.solutions).map((x, i) => <li key={i}>{x}</li>)}</ul>
                {sel.sourceUrl && <small>Fonte: <a href={sel.sourceUrl} target="_blank" rel="noreferrer">{sel.sourceUrl}</a></small>}
                <div style={{ display: 'flex', gap: 6, marginTop: 14, flexWrap: 'wrap' }}>
                  <button className="btn sm" onClick={() => openPdf(sel.id).catch((e) => toast(e.message, true))}>Ver PDF</button>
                  {canEdit && sel.status !== 'DESCARTADO' && <button className="btn sm soft" onClick={() => act(() => api.post(`/prospect/${sel.id}/optout`), 'Empresa removida da prospecção.', () => { setSel(null); run.reload(); })}>Não contactar</button>}
                  <button className="btn sm soft" onClick={() => setSel(null)}>Fechar</button>
                </div>
              </div>
            </div>
          )}
          {send && <Confirm message={`Enviar a proposta em PDF para ${send.email} (${send.name})?${cfg?.sentToday != null ? ` Envios hoje: ${cfg.sentToday}/${cfg.dailyCap}.` : ''}`} label="Enviar"
            onClose={() => setSend(null)} onOk={async () => { await act(() => api.post(`/prospect/${send.id}/send`), 'Proposta enviada. Oportunidade criada no CRM.', run.reload); setSend(null); }} />}
        </>
      )}</Loader>
    </>
  );
}

export default function Prospect({ user }) {
  const runs = useApi('/prospect/runs');
  const comps = useApi('/companies');
  const cfg = useApi('/prospect/config');
  const act = useAction();
  const toast = useToast();
  const [open, setOpen] = useState(null);
  const [f, setF] = useState({ companyId: '', niche: '', location: 'Luanda, Angola', max: 8 });
  const [busy, setBusy] = useState(false);
  const w = can(user, 'opportunities');
  const isDG = user.role === 'DIRECTOR_GERAL';
  const c = cfg.data;

  useEffect(() => { if (!f.companyId && comps.data?.length) setF((x) => ({ ...x, companyId: comps.data[0].id })); }, [comps.data]); // eslint-disable-line react-hooks/exhaustive-deps

  if (open) return <RunView runId={open} canEdit={w} cfg={c} onBack={() => { setOpen(null); runs.reload(); cfg.reload(); }} />;

  const recognise = async () => {
    setBusy(true);
    try { const r = await api.post('/prospect/profile', { companyId: +f.companyId }); setF((x) => ({ ...x, niche: r.niche, location: r.location })); toast(`Nicho identificado: ${r.niche}`); } catch (e) { toast(e.message, true); } finally { setBusy(false); }
  };
  const start = async (e) => {
    e.preventDefault();
    setBusy(true);
    try { const r = await api.post('/prospect/runs', { companyId: +f.companyId, niche: f.niche, location: f.location, max: +f.max }); setOpen(r.id); } catch (er) { toast(er.message, true); } finally { setBusy(false); }
  };

  return (
    <>
      <PageHead title="Prospecção com IA" sub="Reconhece o nicho, pesquisa empresas na internet, identifica dores e envia propostas em PDF" />
      {c && (!c.gemini || !c.smtp) && <div className="errbox" style={{ marginBottom: 14 }}>
        {!c.gemini && 'Falta GEMINI_API_KEY no servidor (pesquisa indisponível). '}{!c.smtp && 'Falta configurar SMTP (envio de propostas indisponível).'}</div>}
      {w && (
        <form className="panel" onSubmit={start} style={{ padding: 16, marginBottom: 14, display: 'grid', gap: 10, gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', alignItems: 'end' }}>
          <div className="field"><label>Empresa que opera</label>
            <select value={f.companyId} onChange={(e) => setF({ ...f, companyId: e.target.value })} required>{(comps.data || []).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></div>
          <div className="field"><label>Nicho a prospectar</label><input value={f.niche} onChange={(e) => setF({ ...f, niche: e.target.value })} placeholder="ex.: construção civil" required /></div>
          <div className="field"><label>Cidade / País</label><input value={f.location} onChange={(e) => setF({ ...f, location: e.target.value })} required /></div>
          <div className="field"><label>Nº de empresas</label><input type="number" min={3} max={15} value={f.max} onChange={(e) => setF({ ...f, max: e.target.value })} /></div>
          <div style={{ display: 'flex', gap: 6 }}>
            <button type="button" className="btn soft" disabled={busy || !f.companyId} onClick={recognise}>Reconhecer nicho</button>
            <button className="btn" disabled={busy || !f.niche}>{busy ? 'A iniciar…' : 'Pesquisar'}</button>
          </div>
        </form>
      )}
      {c && (
        <div className="panel" style={{ padding: 12, marginBottom: 14, display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap' }}>
          <span>Envios hoje: <strong>{c.sentToday}/{c.dailyCap}</strong></span>
          <label style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
            <input type="checkbox" checked={c.autoSend} disabled={!isDG} onChange={(e) => act(() => api.put('/prospect/config', { autoSend: e.target.checked }), e.target.checked ? 'Envio automático ligado.' : 'Envio automático desligado.', cfg.reload)} />
            Enviar automaticamente após a pesquisa (empresas com e-mail)</label>
          {!isDG && <small style={{ color: 'var(--muted)' }}>Só o Director-Geral altera.</small>}
        </div>
      )}
      <div className="panel">
        <div className="hd"><h3>Pesquisas</h3></div>
        <Loader state={runs}>{(rows) => (
          <table>
            <thead><tr><th>Data</th><th>Empresa</th><th>Nicho</th><th>Local</th><th>Empresas</th><th>Estado</th><th /></tr></thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id}><td>{String(r.createdAt).slice(0, 10)}</td><td>{r.company.name}</td><td>{r.niche}</td><td>{r.location}</td><td>{r._count.prospects}</td>
                  <td><Tag kind={r.status === 'CONCLUIDA' ? 'green' : r.status === 'FALHOU' ? 'red' : 'amber'}>{r.status === 'A_CORRER' ? 'A correr' : r.status === 'CONCLUIDA' ? 'Concluída' : 'Falhou'}</Tag></td>
                  <td><button className="btn sm soft" onClick={() => setOpen(r.id)}>Abrir</button></td></tr>
              ))}
              {!rows.length && <tr><td colSpan={7}>Ainda sem pesquisas.</td></tr>}
            </tbody>
          </table>
        )}</Loader>
      </div>
    </>
  );
}
