# PriceWatch 🛒🔍

Sistema de monitoramento e análise histórica de preços para e-commerce, com coleta automatizada, painel administrativo em Filament e alertas via WhatsApp.

---

## 🎯 Produtos Monitorados Inicialmente

1. **Midea** `MA512W165/GK-05` (16,5 kg — 220V) — Meta: R$ 2.200,00
2. **Panasonic** `NA-F180P7` (18 kg — 220V) — Meta: R$ 2.500,00
3. **Samsung** `WA17CG6746BVBZ` (17 kg — 220V) — Meta: R$ 3.000,00

> **Critério Eliminatório:** Top-load, $\ge$ 15 kg, sem agitador/pino central, cesto inox, 220V obrigatório. Qualquer anúncio com voltagem diferente (127V/110V) é gravado como mismatch e não dispara alertas.

---

## 🛠️ Stack Tecnológica

* **Backend:** PHP 8.3 / Laravel 13
* **Painel Administrativo:** Filament 5
* **Banco de Dados:** MySQL 8.0 dedicado com volume isolado
* **Orquestração:** Docker & Docker Compose
* **Alertas:** GoWA WhatsApp Web API
* **Proxy & SSL:** Traefik v3.6 com Let's Encrypt automático via Cloudflare DNS

---

## 🚀 Como Rodar Localmente

1. Clone o repositório.
2. Copie o arquivo de ambiente:
   ```bash
   cp .env.example .env
   ```
3. Instale as dependências:
   ```bash
   composer install
   ```
4. Gere a chave da aplicação:
   ```bash
   php artisan key:generate
   ```
5. Execute as migrations e seeders:
   ```bash
   php artisan migrate:fresh --seed
   ```
6. Inicie o servidor:
   ```bash
   php artisan serve
   ```
7. Acesse o painel Filament em: `http://localhost:8000/admin`
   - **E-mail:** `admin@mgnexus.com.br`
   - **Senha:** `PriceWatch#2026!`

---

## 📖 Documentação Adicional

* [Arquitetura do Sistema](file:///d:/Claude%20Code%20-%20Projetos/Buscador%20de%20Pre%C3%A7os/ARCHITECTURE.md)
* [Runbook de Operação, Deploy e Backup](file:///d:/Claude%20Code%20-%20Projetos/Buscador%20de%20Pre%C3%A7os/RUNBOOK.md)
