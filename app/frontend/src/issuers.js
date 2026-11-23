// Dados do emitente que aparecem no cabeçalho e no rodapé da fatura.
// Chave = nome da empresa tal como está registado no sistema. Empresas que não estejam aqui
// aparecem só com o nome (e o NIF/morada/dados bancários ficam por preencher).
// TODO: mover para a base de dados (campos na tabela Company) quando houver mais empresas a emitir.
export const ISSUERS = {}; // multi-empresa: os dados do emitente vêm da própria empresa (Facturação > Configuração fiscal)

// Compara sem maiúsculas/pontuação e ignora o tipo societário: "MARK SELLER, LDA" encontra "Mark Seller".
const norm = (s = '') => s.toLowerCase().replace(/[.,]/g, ' ').replace(/\b(lda|sa|limitada)\b/g, '').replace(/\s+/g, ' ').trim();
export const issuerFor = (companyName) => {
  const hit = Object.values(ISSUERS).find((x) => norm(x.name) === norm(companyName));
  return hit || { name: companyName, address: [], bank: [] };
};

// ---------- valor por extenso (kwanzas) ----------
const U = ['zero', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez', 'onze', 'doze', 'treze', 'catorze', 'quinze', 'dezasseis', 'dezassete', 'dezoito', 'dezanove'];
const T = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
const H = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];
const glue = (rest) => (rest < 100 || rest % 100 === 0 ? ' e ' : ' ');

const w100 = (n) => (n < 20 ? U[n] : T[Math.floor(n / 10)] + (n % 10 ? ` e ${U[n % 10]}` : ''));
const w1000 = (n) => {
  if (n === 100) return 'cem';
  const h = Math.floor(n / 100); const r = n % 100;
  return (h ? H[h] : '') + (h && r ? ' e ' : '') + (r || !h ? w100(r) : '');
};
const w1e6 = (n) => {
  const th = Math.floor(n / 1000); const r = n % 1000;
  const a = th ? (th === 1 ? 'mil' : `${w1000(th)} mil`) : '';
  return a + (th && r ? glue(r) : '') + (r || !th ? w1000(r) : '');
};
const wInt = (n) => {
  if (n < 1e6) return w1e6(n);
  const m = Math.floor(n / 1e6); const r = n % 1e6;
  return `${w1e6(m)} ${m === 1 ? 'milhão' : 'milhões'}` + (r ? glue(r) + w1e6(r) : '');
};

export function kwanzasPorExtenso(value) {
  const total = Math.round((Number(value) || 0) * 100);
  const kz = Math.floor(total / 100); const cent = total % 100;
  let s = `${wInt(kz)} ${kz === 1 ? 'kwanza' : 'kwanzas'}`;
  if (cent) s += ` e ${w100(cent)} ${cent === 1 ? 'cêntimo' : 'cêntimos'}`;
  return s.charAt(0).toUpperCase() + s.slice(1);
}

// 1300000 -> "1.300.000,00 Kz"
export const kz = (n) => `${(Number(n) || 0).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} Kz`;
