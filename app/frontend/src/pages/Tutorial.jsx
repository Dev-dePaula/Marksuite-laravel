import React, { useState } from 'react';
import { PageHead } from '../ui.jsx';

// Para adicionar um vídeo: copie o .mp4 para app/public/tutoriais/ e preencha `src: '/tutoriais/ficheiro.mp4'`
// (ou, em alternativa, `youtube: 'ID_DO_VIDEO'`). Sem src/youtube, a aula aparece como "Em breve".
// `go` abre o módulo correspondente no sistema.
const LESSONS = [
  { id: 'inicio', title: 'Visão geral do sistema', mod: 'cc', dur: '', desc: 'Um passeio pelo menu, pelo Centro de Comando e pela forma como os módulos se ligam.', src: '', youtube: '', poster: '' },
  { id: 'empresa', title: 'Configurar a sua empresa', mod: 'conf', dur: '', desc: 'Preencha os dados da empresa (NIF, morada, logótipo), IVA e utilizadores da equipa.', src: '', youtube: '', poster: '' },
  { id: 'crm', title: 'Clientes e oportunidades (CRM)', mod: 'crm', dur: '', desc: 'Registe clientes, crie oportunidades e acompanhe o funil comercial.', src: '', youtube: '', poster: '' },
  { id: 'prosp', title: 'Prospecção com IA', mod: 'prosp', dur: '', desc: 'Encontre novas empresas-alvo e gere propostas automaticamente.', src: '', youtube: '', poster: '' },
  { id: 'fat', title: 'Emitir facturas', mod: 'fat', dur: '', desc: 'Crie facturas, envie ao cliente e registe pagamentos.', src: '', youtube: '', poster: '' },
  { id: 'fin', title: 'Financeiro e contabilidade', mod: 'fin', dur: '', desc: 'Receitas, despesas, plano de contas e lançamentos automáticos.', src: '', youtube: '', poster: '' },
  { id: 'proj', title: 'Projectos e tarefas', mod: 'proj', dur: '', desc: 'Planeie projectos, atribua tarefas e controle riscos e prazos.', src: '', youtube: '', poster: '' },
  { id: 'pes', title: 'Pessoas e equipa', mod: 'pes', dur: '', desc: 'Colaboradores, funções e permissões de acesso.', src: '', youtube: '', poster: '' },
  { id: 'bi', title: 'Relatórios e análise', mod: 'bi', dur: '', desc: 'Indicadores, relatórios e a Central de Análise.', src: '', youtube: '', poster: '' },
];

const has = (l) => !!(l.src || l.youtube);

function Player({ l }) {
  if (l.youtube) {
    return <iframe title={l.title} src={`https://www.youtube-nocookie.com/embed/${encodeURIComponent(l.youtube)}`} allow="encrypted-media; picture-in-picture; fullscreen" allowFullScreen style={{ width: '100%', aspectRatio: '16/9', border: 0, borderRadius: 10, background: '#000' }} />;
  }
  if (l.src) {
    return <video key={l.src} controls preload="metadata" poster={l.poster || undefined} src={l.src} style={{ width: '100%', aspectRatio: '16/9', borderRadius: 10, background: '#000' }} />;
  }
  return <div style={{ width: '100%', aspectRatio: '16/9', borderRadius: 10, background: 'var(--line)', display: 'grid', placeItems: 'center', color: 'var(--muted)', fontWeight: 600 }}>Vídeo em breve</div>;
}

export default function Tutorial({ go }) {
  const [sel, setSel] = useState(LESSONS[0].id);
  const cur = LESSONS.find((l) => l.id === sel);
  return (
    <>
      <PageHead title="Tutorial" sub="Aprenda a usar o sistema passo a passo. Comece por configurar os dados da sua empresa." />
      <div className="grid g-21">
        <div className="panel">
          <div className="bd">
            <Player l={cur} />
            <h2 style={{ margin: '14px 0 4px', fontSize: 18 }}>{cur.title}</h2>
            <p style={{ color: 'var(--muted)', margin: '0 0 12px' }}>{cur.desc}</p>
            <button className="btn" onClick={() => go(cur.mod)}>Abrir este módulo</button>
          </div>
        </div>
        <div className="panel">
          <div className="hd"><h3>Aulas</h3></div>
          <div className="bd" style={{ display: 'grid', gap: 6 }}>
            {LESSONS.map((l, i) => (
              <button key={l.id} onClick={() => setSel(l.id)} aria-current={sel === l.id}
                style={{ textAlign: 'left', padding: '10px 12px', borderRadius: 8, border: '1px solid var(--line)', background: sel === l.id ? 'var(--blue, #2563EB)' : 'transparent', color: sel === l.id ? '#fff' : 'inherit', cursor: 'pointer' }}>
                <strong>{i + 1}. {l.title}</strong>
                <br /><small style={{ opacity: .8 }}>{has(l) ? (l.dur || 'Vídeo') : 'Em breve'}</small>
              </button>
            ))}
          </div>
        </div>
      </div>
    </>
  );
}
