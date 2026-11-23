import React, { useEffect, useState } from 'react';
import { FormModal, Loader, Tag, useAction, useApi, useSave, useToast } from '../../ui.jsx';
import { api } from '../../api.js';
import { EVENT_L, NSTATUS, REGIMES } from './lib.js';

function CompanyFiscal({ companies, canWrite, reload }) {
  const [cid, setCid] = useState(companies[0]?.id || '');
  const [form, setForm] = useState(false);
  const save = useSave();
  const c = companies.find((x) => x.id === +cid);
  if (!c) return <div className="empty">Sem empresas.</div>;
  return (
    <div className="panel" style={{ marginBottom: 14 }}>
      <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap', marginBottom: 10 }}>
        <h3 style={{ margin: 0 }}>Dados fiscais da empresa emitente</h3><div style={{ flex: 1 }} />
        {companies.length > 1 && <select aria-label="Empresa" value={cid} onChange={(e) => setCid(e.target.value)} style={{ width: 'auto' }}>{companies.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select>}
        {canWrite && <button className="btn" onClick={() => setForm(true)}>Editar</button>}
      </div>
      <div className="tablewrap"><table><tbody>
        {[['Nome', c.name], ['NIF', c.nif], ['Morada', c.address], ['Email', c.email], ['Telefone', c.phone], ['Website', c.website], ['IBAN', c.iban], ['Regime de IVA', REGIMES.find((r) => r.v === (c.ivaRegime || 'geral'))?.l]].map(([k, v]) => <tr key={k}><td style={{ width: 180 }}><b>{k}</b></td><td>{v || <span style={{ color: '#DC2626' }}>por preencher</span>}</td></tr>)}
      </tbody></table></div>
      {!c.nif && <p className="msg" style={{ marginTop: 10 }}>O NIF é obrigatório em todas as facturas (Dec. Pres. 71/25, art. 10.º).</p>}
      {form && <FormModal title={`Dados fiscais — ${c.name}`} onClose={() => setForm(false)}
        fields={[{ k: 'nif', l: 'NIF', nullable: true }, { k: 'address', l: 'Morada', nullable: true }, { k: 'email', l: 'Email', type: 'email', nullable: true }, { k: 'phone', l: 'Telefone', nullable: true }, { k: 'website', l: 'Website', nullable: true }, { k: 'iban', l: 'IBAN', nullable: true },
          { k: 'ivaRegime', l: 'Regime de IVA', type: 'select', req: true, opts: REGIMES, def: c.ivaRegime || 'geral', wide: true }]}
        initial={{ nif: c.nif || '', address: c.address || '', email: c.email || '', phone: c.phone || '', website: c.website || '', iban: c.iban || '', ivaRegime: c.ivaRegime || 'geral' }}
        onSubmit={(v) => save(() => api.put(`/companies/${c.id}/fiscal`, v), 'Dados fiscais guardados.', reload)} />}
    </div>
  );
}

function Notifications({ canWrite }) {
  const cfg = useApi('/notifications/config');
  const log = useApi('/notifications');
  const [ev, setEv] = useState(null);
  const [days, setDays] = useState(3);
  const [test, setTest] = useState({ channel: 'whatsapp', to: '' });
  const act = useAction(); const toast = useToast();
  useEffect(() => { if (cfg.data && ev === null) { setEv(cfg.data.events); setDays(cfg.data.dueDays); } }, [cfg.data, ev]);
  const toggle = (e) => setEv((s) => (s.includes(e) ? s.filter((x) => x !== e) : [...s, e]));
  const upd = (patch) => act(() => api.put('/notifications/config', patch), 'Configuração guardada.', cfg.reload);

  return (
    <Loader state={cfg}>{(c) => (<>
      <div className="panel" style={{ marginBottom: 14 }}>
        <h3 style={{ marginTop: 0 }}>Notificações ao cliente</h3>
        <p style={{ marginTop: 0, color: 'var(--muted)', fontSize: 13 }}>O cliente recebe a factura (ligação segura para ver e imprimir) por <b>WhatsApp</b> e por <b>email</b>, quando o documento é emitido, quando está para vencer, quando vence e quando é paga.</p>
        <div className="grid g2" style={{ marginBottom: 12 }}>
          {[['whatsapp', 'WhatsApp', c.providers.whatsapp, 'Configure WHATSAPP_TOKEN e WHATSAPP_PHONE_ID no servidor.'], ['email', 'Email', c.providers.email, 'Configure SMTP_HOST, SMTP_USER e SMTP_PASS no servidor.']].map(([k, l, ok, hint]) => (
            <div key={k} className="panel" style={{ background: 'transparent' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}><b>{l}</b><Tag kind={ok ? 'ok' : 'amb'}>{ok ? 'Ligado' : 'Por configurar (modo simulado)'}</Tag></div>
              {!ok && <small style={{ color: 'var(--muted)' }}>{hint} Enquanto isso, as mensagens ficam registadas como «simuladas» e nada é enviado.</small>}
              <label style={{ display: 'flex', gap: 6, alignItems: 'center', marginTop: 8 }}><input type="checkbox" disabled={!canWrite} checked={c[k]} onChange={(e) => upd({ [k]: e.target.checked })} /> Enviar por {l}</label>
            </div>))}
        </div>
        <b>Avisar o cliente quando…</b>
        <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', margin: '8px 0 12px' }}>
          {c.allEvents.map((e) => <label key={e} style={{ display: 'flex', gap: 6, alignItems: 'center' }}><input type="checkbox" disabled={!canWrite || !ev} checked={!!ev?.includes(e)} onChange={() => toggle(e)} /> {EVENT_L[e]}</label>)}
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'end', flexWrap: 'wrap' }}>
          <div className="field"><label htmlFor="n_d">Avisar «a vencer» com quantos dias de antecedência</label><input id="n_d" type="number" min="1" max="30" value={days} onChange={(e) => setDays(+e.target.value)} style={{ width: 100 }} /></div>
          {canWrite && <button className="btn" onClick={() => upd({ events: ev, dueDays: days })}>Guardar</button>}
          {canWrite && <button className="btn ghost" onClick={() => act(() => api.post('/notifications/run'), null, (r) => { toast(r.skipped ? r.skipped : `Verificadas ${r.checked} facturas em aberto; ${r.sent} mensagem(ns).`); log.reload(); })}>Verificar vencimentos agora</button>}
        </div>
        <p style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 0 }}>Os avisos de vencimento correm automaticamente de 30 em 30 minutos, entre as 08h e as 19h (hora de Luanda). «Vencida» repete-se semanalmente, no máximo 4 vezes.</p>
      </div>

      {canWrite && (
        <div className="panel" style={{ marginBottom: 14 }}>
          <h3 style={{ marginTop: 0 }}>Testar ligação</h3>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'end' }}>
            <div className="field"><label htmlFor="t_c">Canal</label><select id="t_c" value={test.channel} onChange={(e) => setTest({ ...test, channel: e.target.value })}><option value="whatsapp">WhatsApp</option><option value="email">Email</option></select></div>
            <div className="field" style={{ flex: 1, minWidth: 200 }}><label htmlFor="t_t">{test.channel === 'whatsapp' ? 'Número (ex.: 923 456 789)' : 'Email'}</label><input id="t_t" value={test.to} onChange={(e) => setTest({ ...test, to: e.target.value })} /></div>
            <button className="btn" disabled={!test.to.trim()} onClick={() => act(() => api.post('/notifications/test', test), 'Mensagem de teste enviada.')}>Enviar teste</button>
          </div>
          <p style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 0 }}>Nota WhatsApp: a Meta só permite iniciar conversa com o cliente através de <b>modelos de mensagem aprovados</b> (um por evento: variáveis <code>WHATSAPP_TPL_EMITIDA</code>, <code>_A_VENCER</code>, <code>_VENCIDA</code>, <code>_PARCIAL</code>, <code>_PAGA</code>). Com 5 variáveis no corpo: cliente, documento, valor, vencimento, ligação.</p>
        </div>)}

      <div className="panel tablewrap"><h3 style={{ marginTop: 0 }}>Registo de mensagens</h3>
        <Loader state={log}>{(rows) => (
          <table><thead><tr><th>Quando</th><th>Documento</th><th>Cliente</th><th>Canal</th><th>Evento</th><th>Para</th><th>Estado</th></tr></thead><tbody>
            {rows.map((n) => <tr key={n.id}><td>{new Date(n.createdAt).toLocaleString('pt-PT')}</td><td>{n.invoice.docNumber}</td><td>{n.invoice.counterparty}</td><td>{n.channel === 'whatsapp' ? 'WhatsApp' : 'Email'}</td><td>{EVENT_L[n.event] || n.event}{n.manual ? ' (manual)' : ''}</td><td>{n.toAddress}</td>
              <td><Tag kind={NSTATUS[n.status]}>{n.status}</Tag>{n.error ? <small style={{ display: 'block', color: 'var(--muted)', whiteSpace: 'normal', maxWidth: 260 }}>{n.error}</small> : null}</td></tr>)}
            {!rows.length && <tr><td colSpan="7"><div className="empty"><strong>Ainda não foram enviadas mensagens</strong></div></td></tr>}</tbody></table>)}</Loader>
      </div>
    </>)}</Loader>
  );
}

function LateCharges({ canWrite }) {
  const cfg = useApi('/late/config');
  const [v, setV] = useState(null);
  const act = useAction();
  useEffect(() => { if (cfg.data && v === null) setV({ feePct: String(cfg.data.feePct), monthlyPct: String(cfg.data.monthlyPct), graceDays: String(cfg.data.graceDays) }); }, [cfg.data, v]);
  if (!v) return null;
  const on = +v.feePct > 0 || +v.monthlyPct > 0;
  return (
    <div className="panel" style={{ marginBottom: 14 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap' }}><h3 style={{ marginTop: 0 }}>Encargos por atraso</h3><Tag kind={on ? 'amb' : 'grey'}>{on ? 'Activos' : 'Desligados'}</Tag></div>
      <p style={{ marginTop: 0, color: 'var(--muted)', fontSize: 13 }}>Quando uma factura passa da data de vencimento, acrescem <b>multa</b> (única) e <b>juros de mora</b> (rateados ao dia) sobre o valor em aberto. Aparecem na factura, na lista, no aviso «vencida» ao cliente e podem ser cobrados no recebimento. <b>Não é IVA</b>: o IVA fica fixado na emissão. Defina as taxas conforme o contrato com o cliente e a lei aplicável.</p>
      <div className="frow">
        <div className="field"><label htmlFor="l_f">Multa única (%)</label><input id="l_f" type="number" min="0" max="100" step="any" value={v.feePct} onChange={(e) => setV({ ...v, feePct: e.target.value })} disabled={!canWrite} /></div>
        <div className="field"><label htmlFor="l_m">Juros de mora (% ao mês)</label><input id="l_m" type="number" min="0" max="100" step="any" value={v.monthlyPct} onChange={(e) => setV({ ...v, monthlyPct: e.target.value })} disabled={!canWrite} /></div>
        <div className="field"><label htmlFor="l_g">Dias de carência</label><input id="l_g" type="number" min="0" max="365" step="1" value={v.graceDays} onChange={(e) => setV({ ...v, graceDays: e.target.value })} disabled={!canWrite} /></div>
      </div>
      {canWrite && <button className="btn" onClick={() => act(() => api.put('/late/config', { feePct: +v.feePct || 0, monthlyPct: +v.monthlyPct || 0, graceDays: Math.round(+v.graceDays) || 0 }), 'Encargos guardados.', cfg.reload)}>Guardar</button>}
      {on && <p style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 0 }}>Exemplo: 100 000 Kz vencidos há 30 dias → multa {(100000 * (+v.feePct || 0) / 100).toLocaleString('pt-PT')} Kz + juros {(100000 * (+v.monthlyPct || 0) / 100).toLocaleString('pt-PT')} Kz.</p>}
    </div>
  );
}

export default function Config({ companies, canWrite, reloadCompanies }) {
  return (<><CompanyFiscal companies={companies} canWrite={canWrite} reload={reloadCompanies} /><LateCharges canWrite={canWrite} /><Notifications canWrite={canWrite} /></>);
}
