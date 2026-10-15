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
- `app/Services/Shopping`: busca no Google Shopping.
  - `SerperShoppingClient`: busca as ofertas.
  - `ShoppingOfferMatcher`: aceita ou recusa cada oferta e marca preço suspeito abaixo de 60%
    da mediana.
  - `OfferPageVerifier`: checagem ao vivo opcional.
  - Comando: `pricewatch:probe {query?} --products --verify -v`.
- `CandidateValidator::validateListing()`: valida a identidade sem depender da URL; é usado
  pela descoberta e pelo Shopping.

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

## Decisão vigente (2026-10-14): preço do Google Shopping como fonte de verdade
O objetivo é encontrar preços e promoções reais, de forma legal e barata; o "como" é
flexível. A checagem ao vivo nas grandes lojas se mostrou inviável (403), então:
1. O preço vem do Google Shopping via Serper. O Merchant Center exige que o preço do anúncio
   bata com o da página da loja.
2. Filtro de identidade antes de aceitar uma oferta: código do modelo, variantes erradas,
   usados ou recondicionados.
3. Link da oferta: o próprio link do Google Shopping, sem a busca extra com `site:`.
4. Depois: gravar no histórico existente e detectar promoção (abaixo da meta ou da média).
5. Depois: ampliar o leitor de grupos de WhatsApp para capturar ofertas, não só cupons.
6. Afiliados por último: Lomadee primeiro. A API de produtos da Amazon foi substituída em
   2026 e a nova exige vendas; a busca pública do Mercado Livre devolve 403.

Custo estimado: o endpoint `/shopping` cobra **2 créditos por busca**. Para 10 produtos com
4 rodadas por dia, são cerca de 2.400 créditos por mês, uns US$ 2,40 por mês após os 2.500
grátis (cerca de US$ 1 por 1.000, créditos expiram em 6 meses).

## Histórico: Fase 0 original (validar a premissa)
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
- Nenhum dos PHPs locais tem CA bundle configurado (cURL error 60). Para chamadas HTTPS
  reais, passe o bundle do Git sem mexer no `php.ini`:
  `php.exe -d curl.cainfo="C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt" -d openssl.cafile="C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt" artisan pricewatch:probe ...`
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
- Comentários traduzidos, cabeçalho de autoria incluído, Pint ok. Commits `66741e8`
  (webhook) e `f7b9d8b` (Fase 0).
- **Primeiro probe real** (IP residencial, não a VPS; 28 créditos):

  | Busca | No Shopping | Verificadas | Confirmadas | Preço dif. | Bloqueadas | Outros |
  |---|---|---|---|---|---|---|
  | Midea MA512W165 | 12 | 8 | 3 | 0 | 2 | 2 sem_preco, 1 sem_link |
  | Panasonic NA-F180P7 | 1 | 1 | 0 | 0 | 1 | — |
  | Samsung WA17CG6746 | 3 | 3 | 0 | 1 | 1 | 1 sem_preco |
  | galaxy s25 256gb | 40 | 8 | 0 | 0 | 5 | 2 sem_preco, 1 indisp. |

  Leituras:
  - O **Google Shopping tem cobertura** (40 ofertas de S25, contra 0 no Tavily).
  - **A verificação ao vivo nas grandes lojas não é viável** por HTTP simples: Magalu e
    Casas Bahia devolvem 403 até de IP residencial (da VPS deve ser pior). A Amazon abre,
    mas sem JSON-LD (`sem_preco`).
  - As 3 "confirmadas" são **produtos errados** (Midea com agitador, 15/16 kg, lava e seca).
    Falta filtro de identidade antes de verificar.
  - A resolução de URL às vezes cai em outra loja ou outro país (Leroy Merlin → Shopee,
    Desertcart → homedepot.com, samsung.com/us).
  - Na prática, nenhuma oferta certa foi confirmada ao vivo.
- **Mudança de rota aprovada pelo usuário:** o objetivo é achar preço e promoção, de forma
  legal e barata. O preço do Google Shopping passa a ser a fonte de verdade (ver "Decisão
  vigente").
- Passos 2 e 3 implementados:
  - `CandidateValidator::validateListing()`, com regras novas: variante (ultra/plus/+/edge/
    pro/max/mini/lite/fe), usado/seminovo/"como novo"/lojas de usados, kit/combo e
    armazenamento em GB ignorando RAM.
  - Correção na regra de versão, que confundia "18 kg" com versão 18.
  - Palavras de categoria de lavadora entraram na lista de palavras genéricas.
  - `ShoppingOfferMatcher` e link do Google, montado pelo `productId` quando o Serper não
    devolve o link.
  - A checagem ao vivo ficou opcional (`--verify`).
  - 40/40 testes passando.
- Probe real com o filtro (6 + 2 créditos):

  | Busca | No Shopping | Aceitas | Recusadas | Menor preço aceito |
  |---|---|---|---|---|
  | Midea MA512W165 | 14 | 2 | 12 | R$ 2.114,10 (Info Store) |
  | Panasonic NA-F180P7 | 1 | 1 | 0 | R$ 2.799,00 (Pontofrio) |
  | Samsung WA17CG6746 | 3 | 1 | 2 | R$ 2.626,59 (Shopee) |
  | galaxy s25 256gb | 40 | 6 | 34 | R$ 3.038,23 (Magalu) |

  Todas as aceitas têm link. As recusadas são variantes, usados, kits ou outras capacidades.
- Créditos Serper gastos até agora: cerca de 46 de 2.500.
- Pendências:
  - Passo 4: gravar as ofertas aceitas no histórico (`price_observations` ou estrutura
    nova; conferir o schema antes) e alertar abaixo da meta ou da média, rodando pelo
    scheduler.
  - Verificar num navegador se o link montado pelo `productId` abre a oferta certa.
  - Definir o critério numérico de "seguir ou encerrar", que pode já estar respondido.
