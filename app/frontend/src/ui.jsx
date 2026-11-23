import React, { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { api } from './api.js';

// ---------- formatação ----------
export const A = (n) => 'AOA ' + Math.round(n || 0).toLocaleString('pt-PT');
export const Ac = (n) => {
  n = n || 0;
  return Math.abs(n) >= 1e9 ? `AOA ${(n / 1e9).toFixed(2).replace('.', ',')}B`
    : Math.abs(n) >= 1e6 ? `AOA ${(n / 1e6).toFixed(1).replace('.', ',')}M` : A(n);
};
export const fdate = (d) => (d ? String(d).slice(0, 10) : '—');
export const hcol = (v) => (v >= 85 ? '#16A34A' : v >= 75 ? '#0E9F6E' : v >= 65 ? '#F59E0B' : '#DC2626');
export const initials = (n = '') => n.split(' ').map((x) => x[0]).slice(0, 2).join('');

export const STAGES = ['LEADS', 'QUALIFICACAO', 'PROPOSTA', 'NEGOCIACAO', 'FECHO'];
export const STAGE_L = { LEADS: 'Leads', QUALIFICACAO: 'Qualificação', PROPOSTA: 'Proposta', NEGOCIACAO: 'Negociação', FECHO: 'Fecho' };
export const AREA_L = { COMERCIAL: 'Comercial', FINANCEIRO: 'Financeiro', PROJECTOS: 'Projectos', PESSOAS: 'Pessoas', OPERACOES: 'Operações', DIRECCAO: 'Direcção' };
export const ROLE_L = { DIRECTOR_GERAL: 'Director-Geral', CFO: 'CFO', DIRECTOR_COMERCIAL: 'Director Comercial', DIRECTOR_PROJECTOS: 'Director de Projectos', DIRECTOR_PESSOAS: 'Director de Pessoas' };

const TAG = { 'No prazo': 'ok', 'Em risco': 'amb', Atrasado: 'red', 'Concluído': 'grey', Activo: 'ok', Sobrecarga: 'red', 'Em acompanhamento': 'amb',
  Leads: 'grey', 'Qualificação': 'blue', Proposta: 'purple', 'Negociação': 'amb', Fecho: 'ok', Alta: 'red', 'Média': 'amb', Baixa: 'grey',
  Vencida: 'red', 'A vencer': 'amb', Paga: 'ok', Recebimentos: 'ok', Pessoal: 'red', Fornecedores: 'red', Estrutura: 'grey' };
export const Tag = ({ children, kind }) => <span className={`tag ${kind || TAG[children] || 'blue'}`}>{children}</span>;

export const Bar = ({ value, tone }) => (
  <div className={`bar ${tone || ''}`}><i style={{ width: `${Math.max(0, Math.min(100, value))}%` }} /></div>
);

// ---------- ícones ----------
const PATHS = {
  home: 'M3 11l9-8 9 8v9a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1z',
  build: 'M3 3h18v18H3zM9 8h2M9 12h2M9 16h2M15 8h0M15 12h0M15 16h0',
  crm: 'M3 7h18v12H3zM8 7V5h8v2M3 12h18',
  proj: 'M3 4h18v16H3zM7 9h10M7 13h7',
  money: 'M2 6h20v12H2zM12 9a3 3 0 100 6 3 3 0 000-6z',
  people: 'M9 4.5a3.5 3.5 0 100 7 3.5 3.5 0 000-7zM2 20a7 7 0 0114 0M16 4a3.5 3.5 0 010 7M22 20a6 6 0 00-5-6',
  ops: 'M12 9a3 3 0 100 6 3 3 0 000-6zM12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1',
  doc: 'M14 3H6a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V9zM14 3v6h6',
  target: 'M12 3a9 9 0 100 18 9 9 0 000-18zM12 8a4 4 0 100 8 4 4 0 000-8z',
  chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  ai: 'M12 3a4 4 0 014 4v1a3 3 0 010 6v3a4 4 0 01-8 0v-3a3 3 0 010-6V7a4 4 0 014-4z',
  cap: 'M2 9l10-5 10 5-10 5zM6 11v5c0 2 3 3 6 3s6-1 6-3v-5',
  check: 'M9 12l2 2 4-4M12 3a9 9 0 100 18 9 9 0 000-18z',
  brain: 'M9 3a3 3 0 00-3 3v1a3 3 0 00-2 5 3 3 0 002 5v1a3 3 0 006 0V6a3 3 0 00-3-3zM15 3a3 3 0 013 3v1a3 3 0 012 5 3 3 0 01-2 5v1a3 3 0 01-6 0',
  edit: 'M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z',
  del: 'M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6',
  eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 9a3 3 0 100 6 3 3 0 000-6z',
  bolt: 'M13 2L4 14h7l-1 8 9-12h-7z',
};
export const Icon = ({ n, size }) => (
  <svg viewBox="0 0 24 24" width={size} height={size} fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d={PATHS[n] || ''} /></svg>
);

// ---------- toast ----------
const ToastCtx = createContext(() => {});
export const useToast = () => useContext(ToastCtx);
export function ToastProvider({ children }) {
  const [t, setT] = useState(null);
  const h = useRef();
  const show = useCallback((msg, err) => {
    setT({ msg, err });
    clearTimeout(h.current);
    h.current = setTimeout(() => setT(null), 3200);
  }, []);
  return (
    <ToastCtx.Provider value={show}>
      {children}
      <div className={`toast ${t ? 'on' : ''} ${t?.err ? 'err' : ''}`} role="status">{t?.msg}</div>
    </ToastCtx.Provider>
  );
}

// ---------- dados ----------
export function useApi(path, deps = []) {
  const [state, setState] = useState({ data: null, error: null, loading: true });
  const [tick, setTick] = useState(0);
  useEffect(() => {
    let alive = true;
    setState((s) => ({ ...s, loading: true }));
    api.get(path)
      .then((data) => alive && setState({ data, error: null, loading: false }))
      .catch((e) => alive && setState({ data: null, error: e.message, loading: false }));
    return () => { alive = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [path, tick, ...deps]);
  return { ...state, reload: () => setTick((t) => t + 1) };
}

/** Executa uma acção da API com toast de sucesso/erro e recarrega. */
export function useAction() {
  const toast = useToast();
  return useCallback(async (fn, okMsg, after) => {
    try {
      const r = await fn();
      if (okMsg) toast(okMsg);
      if (after) await after(r);
      return r;
    } catch (e) {
      toast(e.message, true);
      return undefined;
    }
  }, [toast]);
}

/** Para formulários: o erro propaga-se ao FormModal, que o mostra. */
export function useSave() {
  const toast = useToast();
  return useCallback(async (fn, okMsg, reload) => {
    const r = await fn();
    toast(okMsg);
    if (reload) reload();
    return r;
  }, [toast]);
}

export function Loader({ state, children }) {
  if (state.loading && !state.data) return <div className="loading">A carregar…</div>;
  if (state.error) return <div className="errbox">{state.error}</div>;
  return children(state.data);
}

// ---------- modal ----------
export function Modal({ title, onClose, children, footer }) {
  useEffect(() => {
    const k = (e) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', k);
    return () => document.removeEventListener('keydown', k);
  }, [onClose]);
  return (
    <div className="modal-bg on" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal" role="dialog" aria-modal="true" aria-label={title}>
        <div className="hd"><h3>{title}</h3><button className="iconbtn" onClick={onClose} aria-label="Fechar"><Icon n="del" size={16} /></button></div>
        <div className="bd">{children}</div>
        {footer && <div className="ft">{footer}</div>}
      </div>
    </div>
  );
}

/**
 * Formulário genérico. fields: [{k,l,type,opts:[{v,l}]|[string],req,wide,ph}]
 * onSubmit recebe valores já convertidos (number para type number/select numérico).
 */
export function FormModal({ title, fields, initial = {}, onSubmit, onClose, okLabel = 'Guardar' }) {
  const [v, setV] = useState(() => Object.fromEntries(fields.map((f) => [f.k, initial[f.k] ?? f.def ?? ''])));
  const [busy, setBusy] = useState(false);
  const toast = useToast();
  const set = (k, val) => setV((s) => ({ ...s, [k]: val }));
  const submit = async (e) => {
    e.preventDefault();
    const out = {};
    for (const f of fields) {
      const raw = v[f.k];
      if (f.req && String(raw).trim() === '') { toast(`Preencha: ${f.l}`, true); return; }
      if (raw === '' && !f.req) { if (f.nullable) out[f.k] = null; continue; }
      out[f.k] = f.type === 'number' || f.num ? Number(raw) : typeof raw === 'string' ? raw.trim() : raw;
    }
    setBusy(true);
    try { await onSubmit(out); onClose(); } catch (err) { toast(err.message, true); } finally { setBusy(false); }
  };
  return (
    <Modal title={title} onClose={onClose}>
      <form onSubmit={submit}>
        <div className="frow">
          {fields.map((f) => (
            <div className="field" key={f.k} style={f.wide ? { gridColumn: '1/-1' } : undefined}>
              <label htmlFor={`f_${f.k}`}>{f.l}</label>
              {f.type === 'select' ? (
                <select id={`f_${f.k}`} value={v[f.k]} onChange={(e) => set(f.k, e.target.value)}>
                  {!f.req && <option value="">—</option>}
                  {f.opts.map((o) => (typeof o === 'string' ? <option key={o}>{o}</option> : <option key={o.v} value={o.v}>{o.l}</option>))}
                </select>
              ) : f.type === 'textarea' ? (
                <textarea id={`f_${f.k}`} rows={3} value={v[f.k]} onChange={(e) => set(f.k, e.target.value)} placeholder={f.ph} />
              ) : (
                <input id={`f_${f.k}`} type={f.type || 'text'} value={v[f.k]} step={f.type === 'number' ? 'any' : undefined}
                  min={f.min} max={f.max} placeholder={f.ph} onChange={(e) => set(f.k, e.target.value)} />
              )}
            </div>
          ))}
        </div>
        <div className="ft" style={{ padding: '14px 0 0', border: 0 }}>
          <button type="button" className="btn ghost" onClick={onClose}>Cancelar</button>
          <button className="btn" disabled={busy}>{okLabel}</button>
        </div>
      </form>
    </Modal>
  );
}

export function Confirm({ message, onOk, onClose, label = 'Confirmar' }) {
  return (
    <Modal title="Confirmar" onClose={onClose} footer={<>
      <button className="btn ghost" onClick={onClose}>Cancelar</button>
      <button className="btn danger" onClick={() => { onOk(); onClose(); }}>{label}</button>
    </>}><p>{message}</p></Modal>
  );
}

export const KpiRow = ({ items, cols = 4 }) => (
  <div className={`grid g${cols}`} style={{ marginBottom: 14 }}>
    {items.map(([l, v, col, icon]) => (
      <div className="panel kpi" key={l}>
        <div className="top"><i style={{ background: col }}><Icon n={icon || 'chart'} size={16} /></i><b>{l}</b></div>
        <div className="val num" style={{ fontSize: String(v).length > 12 ? 17 : 22 }}>{v}</div>
      </div>
    ))}
  </div>
);

export const PageHead = ({ title, sub, children }) => (
  <div className="page-h"><div><h1>{title}</h1>{sub && <p>{sub}</p>}</div><div className="spacer" />{children}</div>
);

// quem pode escrever em cada módulo (espelha o backend; o servidor é quem decide)
const W = {
  companies: ['DIRECTOR_GERAL'], opportunities: ['DIRECTOR_GERAL', 'DIRECTOR_COMERCIAL'], projects: ['DIRECTOR_GERAL', 'DIRECTOR_PROJECTOS'],
  invoices: ['DIRECTOR_GERAL', 'CFO'], billing: ['DIRECTOR_GERAL', 'CFO'], accounting: ['DIRECTOR_GERAL', 'CFO'], approveExpense: ['DIRECTOR_GERAL'], movements: ['DIRECTOR_GERAL', 'CFO'], employees: ['DIRECTOR_GERAL', 'DIRECTOR_PESSOAS'],
  processes: ['DIRECTOR_GERAL', 'DIRECTOR_PROJECTOS', 'DIRECTOR_PESSOAS'], decisions: ['DIRECTOR_GERAL'], okrs: ['DIRECTOR_GERAL'],
  courses: ['DIRECTOR_GERAL', 'DIRECTOR_PESSOAS'], settings: ['DIRECTOR_GERAL'], automations: ['DIRECTOR_GERAL'],
  tasks: Object.keys(ROLE_L), users: ['DIRECTOR_GERAL'],
};
export const can = (user, mod) => !!user && W[mod].includes(user.role);

export function csv(name, head, rows) {
  const body = [head, ...rows].map((r) => r.map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(';')).join('\n');
  const url = URL.createObjectURL(new Blob(['﻿' + body], { type: 'text/csv;charset=utf-8' }));
  const a = document.createElement('a');
  a.href = url; a.download = name; a.click();
  URL.revokeObjectURL(url);
}
