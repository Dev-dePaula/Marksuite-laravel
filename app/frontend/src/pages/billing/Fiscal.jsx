import React, { useState } from 'react';
import { Loader, Tag, csv, useAction, useApi } from '../../ui.jsx';
import { api } from '../../api.js';
import { brDate, money, REGIMES } from './lib.js';

const ICON = { ok: '✓', warn: '!', fail: '✕', info: 'i' };
const COL = { ok: ['#DCFCE7', '#166534'], warn: ['#FEF3C7', '#92400E'], fail: ['#FEE2E2', '#991B1B'], info: ['#DBEAFE', '#1E40AF'] };
const TXT = { ok: 'Conforme', warn: 'Atenção', fail: 'Não conforme', info: 'Informação' };
const Dot = ({ s }) => <span aria-hidden="true" style={{ width: 26, height: 26, borderRadius: '50%', display: 'grid', placeItems: 'center', fontWeight: 700, fontSize: 13, flex: 'none', background: COL[s][0], color: COL[s][1] }}>{ICON[s]}</span>;

function Ring({ p }) {
  const r = 18; const c = 2 * Math.PI * r; const col = p >= 90 ? '#16A34A' : p >= 60 ? '#F59E0B' : '#DC2626';
  return (<svg width="64" height="64" viewBox="0 0 44 44" role="img" aria-label={`${p}% de conformidade`}><circle cx="22" cy="22" r={r} fill="none" stroke="rgba(148,163,184,.3)" strokeWidth="5" />
    <circle cx="22" cy="22" r={r} fill="none" stroke={col} strokeWidth="5" strokeLinecap="round" strokeDasharray={`${(c * p / 100).toFixed(1)} ${c.toFixed(1)}`} transform="rotate(-90 22 22)" />
    <text x="22" y="26" textAnchor="middle" fontSize="11" fontWeight="700" fill="currentColor">{p}</text></svg>);
}
const Pill = ({ days }) => <Tag kind={days <= 7 ? 'red' : days <= 30 ? 'amb' : 'blue'}>{days === 0 ? 'hoje' : days === 1 ? 'amanhã' : `${days} dias`}</Tag>;

export function Compliance({ canWrite, companies, onGoto }) {
  const [tab, setTab] = useState('chk');
  const [sim, setSim] = useState(null);
  const data = useApi('/compliance');
  const docs = useApi('/documents?mod=facturas');
  const act = useAction();
  const first = (docs.data || []).find((d) => d.hash && !d.cancelledAt);
  return (
    <Loader state={data}>{(x) => (
      <div className="grid" style={{ gridTemplateColumns: 'minmax(0,1fr) 300px', gap: 16, alignItems: 'start' }}>
        <div>
          <div className="tabs" style={{ marginBottom: 14 }}>{[['chk', 'Verificações'], ['ref', 'Referência legal']].map(([k, l]) => <button key={k} className={tab === k ? 'active' : ''} onClick={() => setTab(k)}>{l}</button>)}</div>
          {tab === 'chk' ? (<>
            <div className="panel" style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 14, flexWrap: 'wrap' }}>
              <Ring p={x.score} /><div><b style={{ fontSize: 18 }}>{x.score}% de conformidade</b><div style={{ color: 'var(--muted)', fontSize: 13 }}>{x.checks.filter((c) => c.s === 'ok').length} conformes · {x.checks.filter((c) => c.s === 'warn').length} com atenção · {x.checks.filter((c) => c.s === 'fail').length} não conformes</div></div>
              <div style={{ marginLeft: 'auto' }}><button className="btn ghost sm" onClick={data.reload}>Verificar de novo</button></div>
            </div>
            <div className="panel">{x.checks.map((c) => (
              <div key={c.id} style={{ display: 'flex', gap: 12, padding: '12px 0', borderTop: '1px solid var(--line, rgba(148,163,184,.25))' }}>
                <Dot s={c.s} /><div><b>{c.t}</b> <span style={{ position: 'absolute', left: -9999 }}>— {TXT[c.s]}</span><div style={{ fontSize: 13 }}>{c.d}</div><div style={{ fontSize: 12, color: 'var(--muted)' }}>{c.l}</div></div>
              </div>))}</div>
            <div className="panel" style={{ marginTop: 14 }}><h3 style={{ marginTop: 0 }}>Controlos automáticos do sistema</h3>
              {[['Total por extenso em kwanzas', 'art. 10.º n.º 1 d)'], ['Documentos em língua portuguesa', 'art. 10.º n.º 1 h)'], ['Numeração por tipo de documento e série', 'art. 10.º n.º 1 b)'], ['«Original» e «2.ª via, em conformidade com a original»', 'art. 7.º n.ºs 4 e 5'], ['Motivo de isenção e regime de IVA impressos no documento', 'art. 10.º n.º 1 f)'], ['IVA cativo e valor a receber discriminados', 'art. 6.º n.º 3'], ['Linhas com taxas diferentes mostradas em separado', 'art. 10.º n.º 2'], ['Hash encadeado e QR code em cada documento fiscal', 'art. 22.º']].map(([t, l]) => (
                <div key={t} style={{ display: 'flex', gap: 12, padding: '8px 0' }}><Dot s="ok" /><div><b>{t}</b><div style={{ fontSize: 12, color: 'var(--muted)' }}>Dec. Pres. 71/25, {l}</div></div></div>))}</div>
          </>) : (<>
            <div className="panel tablewrap"><h3 style={{ marginTop: 0 }}>Base legal</h3><table><tbody>{[['Decreto Presidencial n.º 71/25, de 20 de Março', 'Regime Jurídico das Facturas (substitui o Decreto Presidencial n.º 292/18)'], ['Código do IVA — Lei n.º 7/19, republicado pela Lei n.º 14/23', 'Taxas, regimes, IVA cativo, dedução, declaração periódica'], ['Lei n.º 14/25, de 30 de Dezembro', 'Orçamento Geral do Estado de 2026'], ['Decreto Executivo n.º 683/25, de 22 de Agosto', 'Regras técnicas da facturação electrónica'], ['Decreto Presidencial n.º 245/21', 'Regime do NIF'], ['Decreto n.º 82/01, de 16 de Novembro', 'Plano Geral de Contabilidade']].map(([a, b]) => <tr key={a}><td><b>{a}</b></td><td style={{ whiteSpace: 'normal' }}>{b}</td></tr>)}</tbody></table></div>
            <div className="grid g2" style={{ margin: '14px 0' }}>
              <div className="panel tablewrap"><h3 style={{ marginTop: 0 }}>Taxas de IVA</h3><table><tbody><tr><td><b>14%</b></td><td style={{ whiteSpace: 'normal' }}>Taxa geral</td></tr><tr><td><b>7%</b></td><td style={{ whiteSpace: 'normal' }}>Regime simplificado; hotelaria e restauração que cumpram as condições legais</td></tr><tr><td><b>5%</b></td><td style={{ whiteSpace: 'normal' }}>Bens alimentares essenciais e insumos agrícolas</td></tr><tr><td><b>1%</b></td><td style={{ whiteSpace: 'normal' }}>Regime especial da província de Cabinda</td></tr></tbody></table></div>
              <div className="panel tablewrap"><h3 style={{ marginTop: 0 }}>Regimes de IVA</h3><table><tbody><tr><td><b>Exclusão</b></td><td>Menos de 25 milhões Kz</td></tr><tr><td><b>Simplificado</b></td><td>25 a menos de 350 milhões Kz</td></tr><tr><td><b>Geral</b></td><td>350 milhões Kz ou mais</td></tr></tbody></table></div>
            </div>
            <div className="panel tablewrap"><h3 style={{ marginTop: 0 }}>Coimas de facturação (art. 35.º)</h3><table><tbody>{[['Não emitir factura', '7% do valor'], ['Não emitir factura (reincidência, mais de 5 operações)', '15% do valor'], ['Preço, NIF, morada, nome do emitente ou software em falta ou errados', '5% por factura'], ['Outros elementos em falta ou errados', '1% por factura'], ['Factura emitida fora do prazo', '0,2% por factura']].map(([a, b]) => <tr key={a}><td style={{ whiteSpace: 'normal' }}>{a}</td><td className="amt">{b}</td></tr>)}</tbody></table>
              <p className="msg" style={{ marginTop: 10 }}>A primeira infracção ao regime tem as coimas reduzidas a metade (art. 35.º n.º 15). Informação consultada em Novembro de 2026: <b>confirme sempre em agt.minfin.gov.ao</b>. Isto não substitui aconselhamento fiscal.</p></div>
          </>)}
        </div>
        <aside>
          <div className="panel" style={{ marginBottom: 14 }}><h3 style={{ marginTop: 0 }}>Regime de IVA</h3><b>{REGIMES.find((r) => r.v === x.regime)?.l}</b>
            <p style={{ fontSize: 12, color: 'var(--muted)' }}>Muda a menção impressa nas facturas e o apuramento do IVA. Altere em <a href="#" onClick={(e) => { e.preventDefault(); onGoto('cfg'); }}>Configuração</a>.</p></div>
          <div className="panel" style={{ marginBottom: 14 }}><h3 style={{ marginTop: 0 }}>Próximos prazos</h3>
            {x.deadlines.map((d) => <div key={d.id} style={{ display: 'flex', justifyContent: 'space-between', gap: 10, padding: '8px 0', borderTop: '1px solid var(--line, rgba(148,163,184,.25))' }}><span><b style={{ fontSize: 13 }}>{d.t}</b><br /><small style={{ color: 'var(--muted)' }}>{d.s} · {brDate(d.d)}</small></span><Pill days={d.days} /></div>)}</div>
          <div className="panel"><h3 style={{ marginTop: 0 }}>Teste de auditoria</h3>
            <p style={{ fontSize: 12, color: 'var(--muted)', marginTop: 0 }}>Simula alterar 1,00 Kz numa factura emitida e mostra que a verificação de integridade detecta a adulteração. <b>Não altera dados.</b></p>
            <button className="btn ghost" disabled={!first} onClick={async () => { const r = await act(() => api.get(`/compliance/tamper/${first.id}`), null); if (r) setSim(r); }}>Simular adulteração</button>
            {sim && <div className="msg" style={{ marginTop: 10, fontSize: 12 }}><b>{sim.docNumber}</b>: total {money(sim.originalTotal)} → {money(sim.alteredTotal)}.<br />Hash gravado: <code>{sim.storedHash.slice(0, 14)}…</code><br />Hash com o valor alterado: <code>{sim.hashIfAltered.slice(0, 14)}…</code><br /><b>{sim.detected ? 'Adulteração detectada: os hashes não coincidem.' : 'Não detectada.'}</b></div>}
            {!first && <small style={{ color: 'var(--muted)' }}>Emita uma factura para poder testar.</small>}</div>
        </aside>
      </div>)}</Loader>
  );
}

export function Vat({ companies }) {
  const n = new Date(); const p = new Date(n.getFullYear(), n.getMonth() - 1, 1);
  const [month, setMonth] = useState(`${p.getFullYear()}-${String(p.getMonth() + 1).padStart(2, '0')}`);
  const [cid, setCid] = useState('');
  const data = useApi(`/iva?month=${month}${cid ? `&companyId=${cid}` : ''}`);
  return (<>
    <div className="panel" style={{ display: 'flex', gap: 12, alignItems: 'end', flexWrap: 'wrap', marginBottom: 14 }}>
      <div className="field"><label htmlFor="v_m">Mês de apuramento</label><input id="v_m" type="month" value={month} onChange={(e) => e.target.value && setMonth(e.target.value)} /></div>
      {companies.length > 1 && <div className="field"><label htmlFor="v_c">Empresa</label><select id="v_c" value={cid} onChange={(e) => setCid(e.target.value)}><option value="">Primeira empresa</option>{companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></div>}
    </div>
    <Loader state={data}>{(x) => {
      const r = x.result; const dueTxt = x.daysLeft < 0 ? `prazo ultrapassado há ${-x.daysLeft} dias` : x.daysLeft === 0 ? 'termina hoje' : `faltam ${x.daysLeft} dias`;
      return (
        <div className="grid" style={{ gridTemplateColumns: 'minmax(0,1fr) 280px', gap: 16, alignItems: 'start' }}>
          <div>
            <div className="panel" style={{ marginBottom: 14 }}>
              <p className="msg">Regime de IVA configurado: <b>{REGIMES.find((g) => g.v === x.regime)?.l}</b>.</p>
              {x.regime === 'geral' && <div className="tablewrap"><table><tbody><tr><td>IVA liquidado (facturas)</td><td className="amt">{money(r.liquidado)}</td></tr><tr><td>IVA dedutível (compras e IVA cativo)</td><td className="amt">− {money(r.dedutivel)}</td></tr><tr><td><b>{r.apagar >= 0 ? 'IVA a pagar' : 'Crédito de IVA a reportar'}</b></td><td className="amt"><b>{money(Math.abs(r.apagar))}</b></td></tr></tbody></table></div>}
              {x.regime === 'simplificado' && <div className="tablewrap"><table><tbody><tr><td>Volume de negócios recebido</td><td className="amt">{money(r.recebido)}</td></tr><tr><td>IVA a 7% sobre o recebido</td><td className="amt">{money(r.iva7)}</td></tr><tr><td>Dedução de 10% do IVA suportado</td><td className="amt">− {money(r.deducao10)}</td></tr><tr><td><b>IVA a pagar (simulação)</b></td><td className="amt"><b>{money(r.apagar)}</b></td></tr></tbody></table></div>}
              {x.regime === 'exclusao' && <div className="empty">Regime de Exclusão: as facturas não liquidam IVA e carregam a menção «IVA — Regime de Exclusão».</div>}
            </div>
            {x.regime === 'geral' && (<>
              <div className="panel tablewrap" style={{ marginBottom: 14 }}><h3 style={{ marginTop: 0 }}>Operações por taxa</h3><table><thead><tr><th>Taxa</th><th className="amt">Base tributável</th><th className="amt">IVA</th></tr></thead><tbody>
                {Object.keys(x.by).sort((a, b) => b - a).map((k) => <tr key={k}><td>{+k ? `${k}%` : 'Isento'}</td><td className="amt">{money(x.by[k].base)}</td><td className="amt">{money(x.by[k].tax)}</td></tr>)}
                {!Object.keys(x.by).length && <tr><td colSpan="3"><div className="empty">Sem operações neste mês.</div></td></tr>}</tbody></table></div>
              <div className="panel tablewrap"><div style={{ display: 'flex', justifyContent: 'space-between' }}><h3 style={{ marginTop: 0 }}>Documentos do mês</h3>
                <button className="btn ghost sm" onClick={() => csv(`iva-${month}.csv`, ['Documento', 'Cliente', 'Base', 'IVA', 'IVA cativo'], x.docs.map((d) => [d.docNumber, d.counterparty, d.net, d.tax, d.cativo]))}>Exportar CSV</button></div>
                <table><thead><tr><th>Documento</th><th>Cliente</th><th className="amt">Base</th><th className="amt">IVA</th><th className="amt">IVA cativo</th></tr></thead><tbody>
                  {x.docs.map((d) => <tr key={d.id}><td>{d.docNumber}</td><td>{d.counterparty}</td><td className="amt">{money(d.net)}</td><td className="amt">{money(d.tax)}</td><td className="amt">{d.cativo ? money(d.cativo) : ''}</td></tr>)}
                  {!x.docs.length && <tr><td colSpan="5"><div className="empty">Sem documentos.</div></td></tr>}</tbody></table></div>
            </>)}
          </div>
          <aside>
            <div className="panel" style={{ marginBottom: 14 }}><h3 style={{ marginTop: 0 }}>Declaração periódica</h3><div>Entrega e pagamento até <b>{brDate(x.due)}</b></div><div style={{ marginTop: 8 }}><Tag kind={x.daysLeft < 0 ? 'red' : x.daysLeft <= 7 ? 'amb' : 'blue'}>{dueTxt}</Tag></div></div>
            <p className="msg">A declaração periódica (Modelo 7) e o pagamento fazem-se no <b>Portal do Contribuinte</b> até ao último dia útil do mês seguinte (Código do IVA, art. 44.º e 30.º). Não considera feriados nem prorrogações da AGT.</p>
            <p className="msg"><b>IVA cativo:</b> Estado, petrolíferas e autarquias retêm 100% do IVA; BNA, bancos, seguradoras e operadoras de telecomunicações retêm 50% (art. 21.º). O fornecedor deduz o valor retido.</p>
            <p className="msg"><b>Não dedutível</b> (art. 24.º): viaturas de turismo, alojamento, alimentação, bebidas, divertimentos e tabaco.</p>
          </aside>
        </div>);
    }}</Loader>
  </>);
}
