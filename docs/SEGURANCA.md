# MarkSuite — Segurança e Plano de Resposta a Ataques

> Âmbito: defesa activa e resposta a incidentes **dentro do nosso sistema**. "Contra-ataque" aqui significa
> detectar, bloquear, conter e recuperar. Não se faz "hack-back" a terceiros: é ilegal e contraproducente.

## 1. O que já está implementado

| Camada | Medida |
|---|---|
| **Autenticação** | Palavra-passe ≥ 10 caracteres com letras+números e lista de senhas triviais; hash bcrypt; tempo de resposta constante (não revela se o utilizador existe); **bloqueio de conta** após 5 falhas (15 min, depois 30, 60… até 24 h); **2FA/TOTP** com anti-replay e 8 códigos de recuperação de uso único (segredo cifrado com APP_KEY) |
| **Sessões** | Token Sanctum expira em 12 h; máx. 5 sessões por utilizador; mudar a senha, desactivar ou mudar o perfil **termina as sessões**; "Terminar outras sessões" |
| **Anti força-bruta** | Limite por IP no login/registo; contador de "strikes" por IP — **12 falhas em 15 min ⇒ IP bloqueado 1 h** (bloqueio automático) |
| **Honeypots** | Pedidos a `/.env`, `/.git`, `/wp-login.php`, `/phpmyadmin`, `/vendor`, etc. ⇒ **IP bloqueado 24 h** automaticamente |
| **Isolamento multi-empresa** | Filtro global por `tenantId` em todos os modelos; IDs cruzados validados dentro do tenant; testes automáticos de isolamento |
| **Permissões** | Escrita por perfil (RBAC) em cada rota; leitura/escrita validadas no servidor |
| **Cabeçalhos** | CSP restritiva (só scripts do próprio domínio), HSTS, `X-Frame-Options: DENY`, `nosniff`, Referrer/Permissions-Policy, COOP/CORP, `Cache-Control: no-store` na API |
| **Apache** | HTTPS forçado, sem listagem de directórios, bloqueio de ficheiros ocultos/sensíveis (`.env`, `.sqlite`, `.bak`, `composer.*`…), bloqueio de TRACE/TRACK |
| **Limites de abuso** | API 240 req/min/utilizador; IA 12/hora/utilizador; envio de propostas 30/hora; página pública de documentos 60/min/IP |
| **Entradas/saídas** | Validação em todos os endpoints; escape HTML nos documentos; URLs vindas da IA só `http(s)` (anti-`javascript:`); CSV protegido contra injecção de fórmulas; PDF/Email sem HTML não escapado |
| **Dados** | `.env` e SQLite fora da pasta pública, permissões 600; **backup diário** com rotação (14); erros em produção nunca expõem detalhes (`APP_DEBUG=false`) |
| **Auditoria** | Eventos de segurança (`security_events`) + log por empresa (Monitorização); retenção 90 dias |
| **Cadeia de fornecimento** | `composer audit` e `npm audit` sem vulnerabilidades; dependências fixadas em lockfile |

## 2. Configuração obrigatória no servidor (`.env`)

```
APP_ENV=production
APP_DEBUG=false
SECURITY_ALLOW_IPS=<o seu IP fixo>,<IP do escritório>   # nunca serão bloqueados (evita auto-bloqueio)
REGISTRATION_OPEN=false                                  # fechar registos públicos quando não forem necessários
GEMINI_API_KEY=...                                       # só no .env, nunca no Git
```
Depois: `php83 artisan config:cache`.
**Active o 2FA** em Configurações → Segurança da conta, em todas as contas Director-Geral.

## 3. Operação diária (comandos no servidor)

```
php83 artisan security:status --hours=24    # ataques, IPs agressores, bloqueios activos
php83 artisan security:ban <ip> 1440 motivo # bloquear manualmente
php83 artisan security:unban <ip>
php83 artisan security:unlock <email>       # desbloquear conta (falhas de login)
php83 artisan security:backup               # backup manual (o agendado corre às 03:15)
php83 artisan security:lockdown [--maintenance]   # BOTÃO DE PÂNICO (ver §4)
```
Agendador: no StackCP → *Cron Jobs*, criar `* * * * *  php83 ~/marksuite/artisan schedule:run` (corre backups, limpezas e KPIs).

## 4. Plano de resposta a incidentes ("contra-ataque")

**Princípio:** conter primeiro, investigar depois. Registar a hora de cada passo.

### Fase 1 — Detectar (sinais)
- `security:status` mostra picos de `LOGIN_FALHA`, `CONTA_BLOQUEADA`, `HONEYPOT`, `2FA_FALHA`, `IP_BANIDO`.
- Utilizadores a relatar sessões estranhas; novos registos suspeitos; custos de IA inesperados; erros 5xx em massa.
- Ficheiro `storage/logs/laravel.log` (linhas `[security]`).

### Fase 2 — Conter (minutos)
| Situação | Acção imediata |
|---|---|
| Força bruta / scanning | Já é bloqueado automaticamente; reforçar: `security:ban <ip-ou-range> 10080` |
| Conta suspeita de comprometida | Desactivar o utilizador (Configurações → Utilizadores) — termina as sessões; forçar nova senha + 2FA |
| **Suspeita de roubo de tokens / invasão** | `security:lockdown` (termina **todas** as sessões). Se grave: `security:lockdown --maintenance` (503 para todos) |
| Registos em massa (spam de contas) | `REGISTRATION_OPEN=false` + `config:cache` |
| Abuso da IA/SMTP | Revogar a chave Gemini/SMTP no fornecedor e emitir nova |
| DDoS volumétrico | O alojamento partilhado não aguenta: activar **Cloudflare** (proxy laranja, "Under Attack Mode", rate limiting) e pedir ao alojamento filtragem a montante |

### Fase 3 — Erradicar (horas)
1. **Rodar segredos**: `APP_KEY` (invalida sessões/2FA cifrado — exigir reconfigurar 2FA), `GEMINI_API_KEY`, senha SMTP, chaves SSH e de assinatura fiscal.
2. Confirmar integridade do código: `git status`/`git diff` na cópia do servidor vs. repositório; procurar ficheiros novos em `public_html/teste` e `storage`.
3. `composer audit` + `npm audit`; actualizar dependências vulneráveis.
4. Rever `security_events` e `activity_logs` para determinar: IP de origem, contas afectadas, dados acedidos, janela temporal.

### Fase 4 — Recuperar
1. Repor a partir do backup mais recente **anterior** ao incidente (`~/backups-marksuite/db-*.sqlite`), validar e `php83 artisan up`.
2. Obrigar redefinição de senha de todos os utilizadores afectados; recomendar 2FA.
3. Monitorizar intensivamente 72 h (`security:status --hours=6` várias vezes por dia).

### Fase 5 — Notificar e aprender
- **Dados pessoais:** se houve acesso indevido, avisar os clientes afectados e a autoridade de protecção de dados (Angola — Lei n.º 22/11 de Protecção de Dados Pessoais, APD) sem demora injustificada; guardar o relatório.
- Relatório pós-incidente (causa raiz, linha temporal, o que falhou, correcções) e actualizar este plano.

## 5. Defesa activa — o que o sistema faz sozinho
1. **Auto-ban** por IP após 12 falhas de login/2FA em 15 min (1 h) e por honeypot (24 h).
2. **Bloqueio escalonado de contas** (15 min → 24 h).
3. **Isco (honeypot)**: caminhos que só scanners pedem identificam e banem o atacante antes de qualquer tentativa de exploração.
4. **Tarpit de custo**: respostas idênticas (401 genérico, tempo constante) e limites estritos tornam a enumeração e a força bruta economicamente inviáveis.
5. **Alertas**: eventos gravados e visíveis em `security:status`; ligar `MAIL_MAILER=smtp` e um cron diário com `security:status` por email é o próximo passo.

## 6. Limites honestos (o que o código sozinho não resolve)
- **Hospedagem partilhada (StackCP):** sem acesso root, sem Fail2ban/WAF de rede. Bloqueio é feito na aplicação (depois do Apache). Para protecção a sério de rede: Cloudflare (WAF + DDoS) à frente do domínio.
- **Cloudflare/proxy:** se activar, configurar os IPs reais (`TrustProxies`) para que o auto-ban veja o IP do atacante e não o do proxy.
- **SQLite** em ficheiro: adequado para testes; em produção com várias empresas migrar para MySQL com utilizador de privilégios mínimos e backups cifrados fora do servidor.
- **Backups no mesmo servidor** não protegem contra perda total: copiar periodicamente para fora (S3/Drive cifrado).
- **Pentest externo** antes de lançamento comercial; programa de divulgação responsável (`/.well-known/security.txt`).
- Tokens ficam em `localStorage`: a CSP restritiva mitiga XSS; migrar para cookies `HttpOnly` é um endurecimento futuro.

## 7. Lista de verificação mensal
- [ ] `composer audit` e `npm audit`
- [ ] Rever `security:status --hours=720`
- [ ] Testar restauro de um backup
- [ ] Rever utilizadores activos e perfis (menor privilégio)
- [ ] Rodar chaves de API; confirmar 2FA nos Directores-Gerais
- [ ] Confirmar certificado HTTPS e cabeçalhos (securityheaders.com / ssllabs.com)
