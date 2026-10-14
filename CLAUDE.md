# PriceWatch — Contexto do projeto

Diretrizes do Laravel Boost (já instalado): @AGENTS.md

## Proposta
- **Agora:** monitorar preços de produtos de interesse (eletrônicos, smartphones,
  eletrodomésticos, bikes etc.) e alertar no WhatsApp quando surgir uma oferta real abaixo
  do preço-alvo.
- **Depois (laboratório de negócio):** usar essa base para manter grupos de ofertas
  automatizados (WhatsApp/Telegram) com links de afiliado (Mercado Livre, Amazon, Magalu,
  Casas Bahia/Awin etc.).

## Stack real (conferida no `composer.json`)
- Laravel 13, Filament 5, PHP ^8.3 no composer, mas o `vendor/` atual exige **PHP ≥ 8.4.1**
  (o Dockerfile usa `php:8.4-fpm-alpine`).
- MySQL 8, Docker Compose, VPS Hetzner atrás do Traefik (Coolify), domínio
  `buscador.mgnexus.com.br`.
- Alertas e leitura de grupos via GoWA (WhatsApp Web API).
- Busca: Tavily (legado) e **Serper.dev / Google Shopping** (Fase 0).

## Mapa do código
- `app/Services/Collector`: coleta de preços (API → JSON-LD → microdados), com `mismatch`
  e outlier guard.
- `app/Services/Discovery`: descoberta de candidatos (Tavily, DirectStore, Mock), com
  `CandidateScorer` e `CandidateValidator`.
- `app/Services/Profiling`: `ProductEnrichmentService` (heurística de marca, modelo,
  voltagem e termos de busca).
- `app/Services/Coupons`: cupons capturados de grupos de WhatsApp.
- `app/Services/Alerts`: avaliação e envio de alertas.
- `app/Services/Shopping`: **Fase 0**, com `SerperShoppingClient`, `OfferPageVerifier` e
  `ShoppingOffer`. Comando: `pricewatch:probe`.

## Diagnóstico: por que a V1/V2 não entregou
1. **Tavily não serve para e-commerce em tempo real:** devolve páginas antigas, categorias,
   produtos esgotados e URLs apagadas (404 na Casas Bahia e no Magalu, "indisponível" na
   Amazon).
2. **Anti-bot das grandes lojas:** Magalu, Amazon e Casas Bahia respondem 403 ao servidor.
   Sem checar se a página estava viva, o sistema exibia preço tirado do snippet da busca,
   ou seja, um dado fantasma.
3. **Heurísticas rígidas, moldadas para eletrodomésticos:** no Galaxy S25, "Galaxy" virou
   marca, "Universal Bivolt" virou nome comercial e "S25" nem foi enviado para a busca.
   Resultado: 0 ofertas.

Painel, banco e filas funcionam. O que falhou foi a base: **achar a oferta certa com preço
real**. WhatsApp, parser de cupons e auditoria foram construídos cedo demais.

## Decisão vigente: Fase 0 (validar a premissa)
- **Não** criar telas, **não** gravar no banco e **não** mexer em integrações periféricas
  enquanto a Fase 0 não terminar.
- Fonte: Google Shopping real via Serper.dev (catálogo estruturado com preços atuais).
- Toda oferta é checada **ao vivo** na página da loja. Se der 404, bloqueio ou preço
  divergente (tolerância de 3%), a oferta é descartada.
- **Resultado esperado:** dados concretos para decidir entre seguir (motor com links reais e
  preços verdadeiros, viabilizando o modelo de afiliados) ou encerrar o projeto sem gastar
  mais esforço.
- Custo: 1 crédito Serper por busca de Shopping mais 1 por oferta (resolução da URL direta
  com `site:`). Plano grátis de 2.500 créditos.

## Ambiente e operação
- No host há dois PHPs instalados via winget, e o `php` do PATH aponta para o **8.3**. Para
  este projeto, use o 8.4 pelo caminho completo, sem alterar o PATH (outros projetos
  dependem do 8.3):
  `C:\Users\moura\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe artisan test`
- O `composer` não está no PATH do Git Bash.
- O MCP `laravel-boost` falha ao iniciar neste host (spawn do `php`).
- `README.md`, `ARCHITECTURE.md` e `RUNBOOK.md` descrevem o foco original nas 3 lavadoras e
  estão parcialmente desatualizados.

## Regras de manutenção deste arquivo
- Atualizar o **Registro** a cada sessão com mudança relevante: decisão, resultado de teste
  ou de probe, commit importante.
- Entradas curtas e datadas (AAAA-MM-DD), da mais recente para a mais antiga.
- Mudanças de decisão também atualizam a seção "Decisão vigente".

## Registro

### 2026-10-14
- Claude assumiu o projeto. Este `CLAUDE.md` substituiu o stub de bootstrap do Boost (o
  Boost já estava instalado; as diretrizes estão em `AGENTS.md`).
- Estado não commitado encontrado:
  - Fase 0: `app/Services/Shopping/*`, `ProbeOffersCommand`, `ProbeOffersCommandTest`,
    `SERPER_API_KEY` em `config/services.php` e `.env.example`.
  - Webhook do WhatsApp: autenticação com `hash_equals` e HMAC `X-Hub-Signature-256`,
    descarte de eventos que não são `message` e log tolerante a falhas. O teste perdeu o
    `Queue::assertPushed` do job.
- O PHP 8.4.25 já estava instalado e a suíte roda com ele: **36/36 testes passando**.
- Teste do webhook corrigido: o `Queue::assertNothingPushed()` capturava a closure
  `afterResponse` agendada pelo model `Product` ao criar o produto do `setUp`. Agora o teste
  verifica `assertNotPushed(ProcessWhatsAppGroupMessageJob)` e voltou a checar o
  `assertPushed` do job.
- Pendências:
  - Traduzir para pt-BR os comentários do código novo e incluir o cabeçalho de autoria.
  - Executar `pricewatch:probe` com produtos reais e registrar os números aqui.
  - Definir o critério numérico de "seguir ou encerrar".
