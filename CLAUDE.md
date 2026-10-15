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

### 2026-10-14 (link real da loja)
- O usuário apontou que "Ver Oferta" também abria um agregador do Google. O Serper não
  devolve o endereço da loja no `/shopping`; só há links do Google.
- Solução (`f4544d1`, `e2ad943`):
  - `SerperShoppingClient::findStorePage()` faz um `/search` com `site:<domínio>` (1
    crédito) e devolve URL e título.
  - Loja sem domínio conhecido: a busca é por título + loja, e o resultado só vale se o
    host contiver o nome da loja (a Outlet do Celular caía em `shop.samsung.com`).
  - O `ShoppingPriceRecorder` procura o endereço só para a melhor oferta (confiável) e a
    mais barata, valida o título do resultado com o `validateListing` e guarda
    `metadata.direct_url` e `direct_lookup_at`.
  - O resultado, inclusive o "não encontrado", é reaproveitado por 7 dias para a mesma
    fonte e o mesmo título (`DirectLinkCacheDays`).
  - O card mostra "Ver na loja" (endereço real) ou "Ver no Google" (busca da oferta). O
    botão "Comparar lojas" continua abrindo o painel do produto.
- Teste manual (5 créditos): KaBuM, Magalu, Shopee e Mercado Livre devolveram a página
  certa; a loja desconhecida caiu em outra loja (por isso a regra do host).
- Produção: 5 de 6 produtos com "Ver na loja" (Magalu, Extra, Shopee, Mercado Livre,
  KaBuM). O Switch 2 no Mercado Livre não teve página encontrada.
  - A primeira rodada gastou 22 créditos (12 de busca e 10 de endereço). As seguintes
    reaproveitam o cache.

### 2026-10-14 (dois links, preço atual e lojas confiáveis)
- O usuário quis **manter** a página geral do Google (para explorar outras lojas), além de
  ter o link da oferta da loja. Diretriz: ampliar recursos em vez de substituir.
- Implementado (`d7601c3`, `adfbbda`):
  - `ShoppingOffer::storeSearchLink()` (busca título + loja) passou a ser o `url` da
    fonte. O painel do Google fica em `metadata.google_link`.
  - O card ganhou o botão "Comparar lojas", e o alerta leva os dois links.
  - O card considera como preço atual só as observações das últimas 24h
    (`CurrentPriceWindowHours`). O mínimo de todo o histórico vira "Menor histórico".
  - Lojas confiáveis: `Store::TrustedLevels` (`official`, `marketplace_1p`).
    - Lojas conhecidas do Shopping são criadas ou promovidas como `marketplace_1p`; a
      Shopee entra como `marketplace_3p`.
    - Lojas desconhecidas ficam como `standard`.
    - O preço principal e o alerta usam a melhor oferta confiável. Uma oferta mais barata
      de loja não verificada vira um aviso amarelo no card.
    - Sem nenhuma oferta confiável, o card mostra a melhor oferta com "Loja pouco
      conhecida".
  - No cadastro de lojas, o domínio passou a ser opcional e foi incluída a opção "Não
    verificada", para o usuário promover lojas.
- Produção após a rodada:
  - S25: R$ 3.163,13 na KaBuM, com aviso de R$ 2.763,90 na Outlet do Celular.
  - Switch 2: R$ 3.752 no Mercado Livre, com aviso de R$ 2.934,48 na CSTech.
  - Panasonic e Samsung: só aparecem na Shopee (3P), marcadas como "Loja pouco conhecida".
- Ponto em aberto: o texto "Menor histórico" do card ainda considera lojas não verificadas
  (no S25 mostra R$ 2.763,90 da Outlet).

### 2026-10-14 (divergência de preço e produtos usados)
- O card do S25 mostrava R$ 2.763,90 (Outlet do Celular), mas o link levava a outro
  preço. Causas:
  - O link do Serper abre o painel do **produto** no Google (várias lojas, em destaque
    as que o Google prefere), não a oferta daquela loja.
  - A oferta está 30% abaixo da faixa "normal" que o próprio Google exibe; o título não
    diz que é usado.
  - O "menor preço" do card é o mínimo de todo o histórico, não o da última rodada.
- Teste de filtro de condição no Serper (8 créditos), busca "Galaxy S25":
  - `tbs=mr:1,new:1` é **ignorado**: o resultado sai idêntico.
  - `-usado -seminovo` **piora** (14 usados contra 7): o Google ignora o operador.
  - Acrescentar "novo" à busca: 12 ofertas e 0 usados, mas **perde a melhor oferta nova
    real** (KaBuM a R$ 3.163,13). Descartado.
  - Conclusão: não há como excluir usados na origem. O caminho é o filtro por título e
    loja somado a uma lista de lojas confiáveis.

### 2026-10-14 (correções do painel e Tavily só para fotos)
- O usuário viu preços ilógicos no card: Galaxy S25 a R$ 69,90 (capinha) e Switch 2 a
  R$ 129 (jogo).
  - O `ShoppingOfferMatcher` já os marcava `is_suspicious`, mas o
    `MonitoredProductsWidget` não filtrava suspeitas. Agora filtra.
  - O validador ganhou regras para acessório/jogo (capa, película, carregador, jogo,
    edition, controle, cartão de jogo, código digital...) e para nota de conservação de
    seminovo ("- Muito Bom", "- Excelente"), além da loja `cellularstore`.
  - Jogos sem palavra-chave (ex.: "Welcome Tour") continuam caindo na trava de mediana.
- Tensão:
  - O `ProductForm` vinha com `default('Bivolt')`, e todo produto, inclusive celular e
    console, ganhava "Bivolt". Agora a tensão é opcional ("Não se aplica").
  - O card oculta marca e modelo vazios; antes exibia "Universal".
  - Em produção, a tensão do Galaxy S25 e do Nintendo Switch 2 foi zerada. A bike ficou
    com Bivolt.
- **Tavily só para fotos (decisão do usuário):**
  - `pricewatch:discover` e `pricewatch:collect` saíram do agendamento e continuam
    disponíveis manualmente.
  - Os botões "Buscar Ofertas" do painel passaram a usar o `ShoppingPriceRecorder`.
- Consumo do Serper:
  - Cada chamada é gravada em `api_usage_logs` (`provider = serper`, `purpose` igual a
    `shopping` ou `store_url`).
  - O `TavilyQuotaWidget` virou `ApiUsageWidget`: Serper no mês e no dia, mais o Tavily
    das fotos.
  - Orçamento mensal em `SERPER_MONTHLY_LIMIT` (padrão 2000). Com 6 produtos, são cerca
    de 1.440 créditos por mês.
- Pontos em aberto:
  - Lojas estrangeiras no Shopping (Microless, nos Emirados, a R$ 3.176 no Switch 2).
  - O Kaspersky bloqueia o `claude.exe` ao iniciar o `php`; o `.mcp.json` aponta para o
    `php` do sistema (8.3).

### 2026-10-14 (deploy em produção)
- **Deploy feito por Claude** (o usuário sempre pede o deploy por aqui), seguindo o RUNBOOK:
  `git pull`, `docker compose build app worker`, `up -d`, `migrate --force` e caches.
  - O acesso SSH depende de uma regra de permissão do usuário no Claude Code:
    `Bash(ssh -i ~/.ssh/id_pricewatch_deploy root@178.156.245.190:*)`. Os comandos
    precisam **começar** com esse `ssh` para casar com a regra.
- `.env` de produção:
  - Backup em `.env.backup-20261015-003437`.
  - Foram adicionados `SERPER_API_KEY`, `GOWA_ENABLED=false` e um `GOWA_WEBHOOK_SECRET`
    aleatório. Antes não havia segredo, e valia o padrão público do `config/services.php`.
    O GoWA precisará ser configurado com o segredo novo.
- Primeira rodada em produção (6 produtos, 12 créditos): Midea, Panasonic, Samsung,
  Switch 2, bike GT73 e Galaxy S25. 3 alertas `skipped` (Midea e Panasonic).
- **Erros encontrados e corrigidos (`8ba8c75`):**
  - Uma lava e seca Samsung de R$ 19.599 passou como a lavadora; `secadora` e
    `lava e seca` viraram variantes recusadas.
  - Com só 2 ofertas aceitas, a mediana marcou a lavadora correta como suspeita; o
    critério de preço fora da curva agora exige 3 ou mais ofertas aceitas.
  - Em produção, a observação da lava e seca foi marcada `is_mismatch` e a da Sipolatti
    foi desmarcada como suspeita.
- Pontos a observar nas próximas rodadas:
  - Lojas estrangeiras no Shopping (Microless, Desertcart).
  - O produto "Galaxy S25" em produção não tem armazenamento no nome, então aceita
    qualquer capacidade.
- O Kaspersky do usuário bloqueou o `claude.exe` (PDM:Trojan.Win32.Generic, detecção por
  comportamento) quando ele iniciou o `php.exe` 8.3. Isso explica o erro
  `EPERM uv_spawn 'php'` do MCP `laravel-boost`. O `claude.exe` tem assinatura válida da
  Anthropic (MD5 `11DEAE9E...`).

### 2026-10-14 (passo 4: gravação automática e alertas)
- **WhatsApp desligado de propósito:** o número foi compartilhado com outro projeto e
  quebrou os dois. O usuário vai providenciar um número exclusivo.
  - Novo `GOWA_ENABLED` (padrão `false`). Com ele desligado, o `GoWaChannel` não chama a
    API e o alerta é gravado com status `skipped`, mas continua indo para o sino do painel
    (Filament).
- Passo 4 implementado **sem migration**, reaproveitando as tabelas existentes:
  - `ShoppingPriceRecorder`: uma `ProductSource` por produto e loja com
    `collector_type = google_shopping` (URL = link do Google, atualizado a cada rodada) e
    uma `PriceObservation` por loja por rodada (a oferta mais barata da loja).
  - A loja é encontrada pelo domínio conhecido (`domainForStore`) ou criada pelo nome com
    `domain = ''`.
  - O alerta é avaliado só para a oferta mais barata e não suspeita de cada rodada,
    usando as `alert_rules` existentes (`target_price`, `lowest_price`).
  - Comando `pricewatch:shopping {--product=}`, agendado `0 8,12,16,20 * * *`
    (America/Sao_Paulo).
  - O coletor HTTP nunca processa fontes `google_shopping`: há uma guarda no
    `CollectionPipeline::run`, o escopo `ProductSource::scrapable()` no
    `pricewatch:collect` e nos botões do `MonitoredProductsWidget`.
  - Correções no `AlertEvaluator`:
    - O envio foi unificado em `deliverViaWhatsApp`.
    - O cooldown de 24h passou a considerar também os alertas `skipped`.
    - A regra `lowest_price` passou a ignorar observações `is_suspicious`; antes, um preço
      errado virava o "recorde" e bloqueava alertas reais.
- 44/44 testes passando. Rodada real no banco local (6 créditos):
  - Midea: R$ 2.114,10 (Info Store), abaixo da meta de R$ 2.200; gerou alertas de meta e
    de menor histórico.
  - Panasonic: R$ 2.799,00 (Pontofrio).
  - Samsung: R$ 2.626,59 (Shopee), abaixo da meta de R$ 3.000.
- Para produção:
  - O `.env` precisa de `SERPER_API_KEY`, e o scheduler precisa estar rodando no worker.
  - `GOWA_ENABLED` ausente equivale a desligado.
- Pendências:
  - Ligar `GOWA_ENABLED=true` quando houver número exclusivo.
  - Decidir se a descoberta via Tavily (`pricewatch:discover`, terça e sexta) e a coleta
    HTTP (`pricewatch:collect`, diária) continuam agendadas.
  - ~~Telefone real no `.env.example`~~: trocado por um fictício. O repositório GitHub é
    **público** e o número continua no histórico desde o commit inicial (`ccec28b`).
  - ~~Deploy~~: feito (ver a entrada "deploy em produção").
  - Passo 5 (capturar ofertas de grupos de WhatsApp) depende do número novo.

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
  - Definir o critério numérico de "seguir ou encerrar", que pode já estar respondido.
- Links testados pelo usuário no navegador:
  - O link original do Serper (`ibp=oshop&prds=...gpcid...`) abre o painel do produto com
    as lojas e a faixa "o preço normalmente é de R$ X–Y".
  - O link montado só com `catalogid` **falha** ("detalhes do produto não estão
    disponíveis"): o Google exige o `gpcid`.
  - A busca `q=<título> <loja>&udm=28` traz a oferta como primeiro resultado. Ela passou a
    ser o fallback quando o Serper não devolve link.
