// Constantes e cálculo de pré-visualização do editor. O servidor volta a calcular tudo ao gravar (é ele que manda).
export const TYPES = {
  FT: { n: 'Factura', fisc: true }, FR: { n: 'Factura-recibo', fisc: true }, FA: { n: 'Factura de adiantamento', fisc: true },
  ND: { n: 'Nota de débito', fisc: true }, AF: { n: 'Autofacturação', fisc: true }, FG: { n: 'Factura global', fisc: true },
  NC: { n: 'Nota de crédito', fisc: true, neg: true }, PF: { n: 'Pró-forma' }, GT: { n: 'Guia de transporte' }, EN: { n: 'Nota de encomenda' },
};
export const MODS = {
  fat: { mod: 'facturas', title: 'Facturas', types: ['FT', 'FR', 'FA', 'ND', 'AF', 'FG', 'NC'], btns: [['FT', 'Nova factura', 1], ['FR', 'Factura-recibo'], ['FA', 'Adiantamento'], ['ND', 'Nota de débito'], ['AF', 'Autofacturação'], ['FG', 'Factura global']] },
  pf: { mod: 'proformas', title: 'Pró-formas', types: ['PF'], btns: [['PF', 'Nova pró-forma', 1]] },
  gt: { mod: 'guias', title: 'Guias de transporte', types: ['GT'], btns: [['GT', 'Nova guia de transporte', 1]] },
  en: { mod: 'encomendas', title: 'Encomendas', types: ['EN'], btns: [['EN', 'Nova nota de encomenda', 1]] },
};
export const STATE_TXT = { R: 'Rascunho', D: 'Dentro do prazo', V: 'Vencido', P: 'Pago', C: 'Cancelado', E: 'Emitido', F: 'Convertido' };
export const STATE_TAG = { R: 'grey', D: 'blue', V: 'red', P: 'ok', C: 'grey', E: 'blue', F: 'ok' };
export const TERMS = ['Pronto pagamento', '15 dias', '30 dias', '45 dias', '60 dias', '90 dias'];
export const METHODS = [{ v: 'transferencia', l: 'Transferência' }, { v: 'dinheiro', l: 'Dinheiro' }, { v: 'multicaixa', l: 'Multicaixa' }, { v: 'cheque', l: 'Cheque' }];
export const REGIMES = [{ v: 'geral', l: 'Regime Geral (≥ 350 milhões Kz)' }, { v: 'simplificado', l: 'Regime Simplificado (25 a 350 milhões Kz)' }, { v: 'exclusao', l: 'Regime de Exclusão (< 25 milhões Kz)' }];
export const EVENT_L = { EMITIDA: 'Factura emitida', A_VENCER: 'A vencer', VENCIDA: 'Vencida (em atraso)', PARCIAL: 'Pagamento parcial', PAGA: 'Paga' };
export const NSTATUS = { ENVIADA: 'ok', SIMULADA: 'amb', FALHOU: 'red', PENDENTE: 'grey' };

export const r2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;
export const today = () => new Date().toISOString().slice(0, 10);
export const addDays = (iso, n) => { const d = new Date(`${iso}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
export const termDays = (t) => { const n = parseInt(t, 10); return Number.isNaN(n) ? 0 : n; };
export const dueOf = (type, date, terms) => (type === 'FR' || type === 'GT' ? date : addDays(date, termDays(terms)));
export const num = (v) => { const n = parseFloat(String(v).replace(',', '.')); return Number.isFinite(n) ? n : 0; };
export const brDate = (d) => (d ? String(d).slice(0, 10).split('-').reverse().join('/') : '—');
export const money = (n) => `${(Number(n) || 0).toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} Kz`;

/** Dias úteis entre duas datas (prazo de emissão: 5.º dia útil). */
export function workDays(a, b) {
  let n = 0; const d = new Date(`${a}T00:00:00Z`); const e = new Date(`${b}T00:00:00Z`);
  while (d < e) { d.setUTCDate(d.getUTCDate() + 1); const w = d.getUTCDay(); if (w !== 0 && w !== 6) n++; }
  return n;
}

export function calcPreview({ lines, gdiscPct, retPct, cativoPct, regime = 'geral', type }) {
  const g = Math.min(100, Math.max(0, num(gdiscPct))); const rt = Math.min(100, Math.max(0, num(retPct)));
  let sum = 0; let disc = 0; let gd = 0; let net = 0; let tax = 0; const by = {};
  lines.forEach((l) => {
    const gross = r2(num(l.qty) * num(l.price)); const dsc = r2(gross * num(l.discPct) / 100); const nl = r2(gross - dsc); const n = r2(nl * (1 - g / 100));
    const rate = regime === 'geral' ? num(l.ivaRate) : 0; const t = r2(n * rate / 100);
    sum += gross; disc += dsc; gd += nl - n; net += n; tax += t;
    const k = String(rate); by[k] = by[k] || { base: 0, tax: 0 }; by[k].base = r2(by[k].base + n); by[k].tax = r2(by[k].tax + t);
  });
  net = r2(net); tax = r2(tax);
  const ret = r2(net * rt / 100); const cp = TYPES[type]?.fisc ? num(cativoPct) : 0; const cativo = r2(tax * cp / 100); const total = r2(net + tax - ret);
  return { sum: r2(sum), disc: r2(disc), gdisc: r2(gd), net, tax, ret, total, by, cativo, cativoPct: cp, recv: r2(total - cativo), sg: TYPES[type]?.neg ? -1 : 1 };
}
