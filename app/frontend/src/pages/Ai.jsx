import React, { useEffect, useRef, useState } from 'react';
import { Icon, Loader, PageHead, can, useApi, useSave, useToast } from '../ui.jsx';
import { api } from '../api.js';

const AGENTS = [['CEO Agent', 'Analisa o grupo e aconselha a Direcção', '#2563EB', 'home'], ['CFO Agent', 'Caixa, margens, despesas e riscos', '#16A34A', 'money'],
  ['Sales Agent', 'Pipeline e follow-ups', '#F59E0B', 'crm'], ['Project Agent', 'Atrasos e dependências', '#DC2626', 'proj'], ['People Agent', 'Desempenho e capacidade', '#7C3AED', 'people'],
  ['Meeting Agent', 'Prepara reunião, cria acta e acompanha decisões', '#0EA5E9', 'doc'], ['Strategy Agent', 'Acompanha metas estratégicas', '#0E9F6E', 'target']];
const QS = ['Qual é o estado geral do grupo hoje?', 'As decisões de contratação tomadas este ano melhoraram as vendas?', 'Que projectos correm risco de derrapar?',
  'Onde estou a perder dinheiro?', 'Prepara a reunião de direcção de amanhã.', 'Como está o pipeline comercial?'];

export default function Ai({ user }) {
  const [agent, setAgent] = useState('CEO Agent');
  const [msgs, setMsgs] = useState([{ who: 'a', text: `Olá, ${user.name}. Sou o motor de inteligência do MarkSuite. Analiso CRM, projectos, finanças, pessoas e a Memória Corporativa do Grupo. Em que posso ajudar?` }]);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const end = useRef();
  const autos = useApi('/automations');
  const toast = useToast();
  const save = useSave();
  useEffect(() => { end.current?.scrollIntoView({ block: 'end' }); }, [msgs]);

  const ask = async (question) => {
    const q = question.trim();
    if (!q || busy) return;
    setInput(''); setBusy(true);
    setMsgs((m) => [...m, { who: 'u', text: q }]);
    try {
      const r = await api.post('/ai/ask', { agent, question: q });
      setMsgs((m) => [...m, { who: 'a', text: r.answer }]);
    } catch (e) { setMsgs((m) => [...m, { who: 'a', text: e.message }]); } finally { setBusy(false); }
  };

  return (
    <>
      <PageHead title="Automação & IA" sub="Agentes especializados a trabalhar sobre a mesma base de dados empresarial" />
      <div className="grid g-21">
        <div className="aibox">
          <div className="hd"><div className="ico purple">✦</div><div style={{ flex: 1 }}><strong>MarkSuite Intelligence</strong><br /><small style={{ color: 'var(--muted)' }}>Agente activo: {agent}</small></div>
            <select value={agent} onChange={(e) => setAgent(e.target.value)} aria-label="Agente" style={{ padding: '7px 11px', border: '1px solid var(--line)', borderRadius: 8 }}>
              {AGENTS.map((a) => <option key={a[0]}>{a[0]}</option>)}</select></div>
          <div className="chat" aria-live="polite">{msgs.map((m, i) => <div key={i} className={`msg ${m.who}`}><div className="b">{m.text}</div></div>)}
            {busy && <div className="msg a"><div className="b">A analisar os dados…</div></div>}<div ref={end} /></div>
          <div className="sugg">{QS.map((q) => <button key={q} onClick={() => ask(q)}>{q}</button>)}</div>
          <form className="askbar" onSubmit={(e) => { e.preventDefault(); ask(input); }}>
            <input value={input} onChange={(e) => setInput(e.target.value)} placeholder="Pergunte ao MarkSuite..." maxLength={1000} /><button className="btn" disabled={busy}>Perguntar</button></form>
        </div>
        <div>
          <div className="panel" style={{ marginBottom: 14 }}><div className="hd"><h3>Agentes</h3></div><div className="bd" style={{ display: 'grid', gap: 10 }}>
            {AGENTS.map(([n, d, c, ic]) => (<div className="agent" key={n}><i style={{ background: c }}><Icon n={ic} size={18} /></i><div style={{ flex: 1 }}><b>{n}</b><p>{d}</p>
              <button className="btn sm soft" onClick={() => { setAgent(n); toast(`${n} activado.`); }}>Activar</button></div></div>))}</div></div>
          <div className="panel"><div className="hd"><h3>Automation Engine</h3></div><div className="bd">
            <Loader state={autos}>{(rows) => rows.map((a) => (
              <label key={a.id} style={{ display: 'flex', gap: 10, alignItems: 'flex-start', padding: '9px 0', borderBottom: '1px solid var(--line)', fontSize: 13.5, cursor: 'pointer' }}>
                <input type="checkbox" checked={a.enabled} disabled={!can(user, 'automations')} style={{ width: 17, height: 17, accentColor: 'var(--blue)', marginTop: 2 }}
                  onChange={(e) => save(() => api.put(`/automations/${a.id}`, { enabled: e.target.checked }), e.target.checked ? 'Automação activada.' : 'Automação desactivada.', autos.reload).catch((x) => toast(x.message, true))} />{a.label}</label>))}</Loader>
            <small style={{ color: 'var(--muted)', display: 'block', marginTop: 8 }}>As regras ficam guardadas; a execução agendada requer um serviço de tarefas ainda por ligar.</small></div></div>
        </div>
      </div>
    </>
  );
}
