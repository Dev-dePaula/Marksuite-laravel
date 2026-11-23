# MarkSuite ERP — Laravel multi-empresa (ambiente de testes)

Port do sistema `projeto-angola` (Node + Prisma + React) para **Laravel 12**, onde **cada empresa que se
regista recebe o seu próprio dashboard ERP completo**, isolado das restantes.

## Como funciona o multi-cliente

- **Base de dados única, isolamento por `tenantId`.** Cada empresa-cliente é um registo em `tenants`.
  Todas as tabelas de negócio têm `tenantId`; o trait `BelongsToTenant` filtra toda a leitura e carimba
  toda a criação com o tenant do utilizador autenticado (`app/Models/Concerns`, `app/Support/Tenancy.php`).
- **Registo** (`POST /api/auth/register`): cria o tenant, o utilizador Director-Geral (titular), a empresa,
  as definições, as automações e o plano de contas. Opção "dados de demonstração" para testar logo todos os módulos.
- **Utilizadores por empresa**: o Director-Geral convida a equipa em *Configurações → Utilizadores*
  (perfis: Director-Geral, CFO, Comercial, Projectos, Pessoas; as permissões de escrita são as do sistema original).
- Referências cruzadas (ex.: `companyId`) são validadas **dentro do tenant**; recursos de outro tenant respondem 404.
- A página pública de documentos (link enviado ao cliente) usa um token assinado que inclui o tenant.

## Módulos portados (frontend React original, sem alterações de API)

Centro de Comando · Empresas · CRM (funil, mapa de influência, rascunhos IA) · Projectos · Financeiro ·
**Facturação** (10 tipos de documento, hash encadeado, NC, recibos, IVA cativo, retenção, encargos de mora,
notificações WhatsApp/email, conformidade AGT, apuramento de IVA) · **Contabilidade** (partidas dobradas,
balancete, DRE, balanço, razão, impostos) · Pessoas · Operações · Decisões + Memória Corporativa · OKR ·
Relatórios · Central de Análise (KPIs diários, alertas, insights IA) · Automação & IA · Universidade ·
Configurações · Monitorização.

## Arranque

```bash
cd app
copy .env.example .env && php artisan key:generate   # já feito neste ambiente
php artisan migrate                                  # SQLite por omissão (database/database.sqlite)
cd frontend && npm install && npm run build && cd ..  # compila o React para public/app
php artisan serve --port=8123                        # http://localhost:8123  (abre o ERP)
php artisan test                                     # 4 testes de ponta a ponta (isolamento, facturação, permissões, módulos)
```

Para MySQL, definir `DB_CONNECTION=mysql` (+ host/base/utilizador) no `.env` e correr `php artisan migrate`.

Desenvolvimento do frontend: `cd app/frontend && npm run dev` (proxy para `http://localhost:8123`).

## Configuração opcional (`app/.env`)

| Variável | Para quê |
|---|---|
| `GEMINI_API_KEY` ou `ANTHROPIC_API_KEY` | Assistente de IA, rascunhos de prospecção e insights |
| `PUBLIC_API_URL` | URL público usado nos links de documentos enviados aos clientes |
| `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_ID` (+ `WHATSAPP_TPL_*`) | Envio real por WhatsApp (sem isto fica "SIMULADA") |
| `MAIL_HOST/PORT/USERNAME/PASSWORD/FROM_ADDRESS` | Envio real por email |
| `SIGN_PRIVATE_KEY` / `SIGN_PRIVATE_KEY_PATH` | Assinatura RSA dos documentos fiscais (opcional) |

Tarefas periódicas (KPIs diários e lembretes de cobrança de todos os clientes): ligar o agendador —
`* * * * * php artisan schedule:run` — ou correr `php artisan marksuite:daily`.

## Limitações conhecidas

- Registo aberto a qualquer pessoa (adequado a testes; em produção juntar verificação de email/convite e planos).
- Testado em SQLite; o schema foi escrito para ser compatível com MySQL mas não foi corrido em MySQL aqui.
- Mantém o aviso do original: o módulo de facturação **não está validado pela AGT**.
- Os dados de emitente (NIF, morada, IBAN) de cada empresa preenchem-se em Facturação → configuração fiscal;
  os dados fixos da empresa original (Mark Seller) foram removidos do frontend.
