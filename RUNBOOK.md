# PriceWatch — Guia de Operação e Runbook

Este documento orienta a operação, atualização, backup, restauração e resolução de problemas do **PriceWatch** em produção na VPS Hetzner.

---

## 1. Topologia de Produção

* **Diretório da Aplicação:** `/data/pricewatch`
* **Containers:**
  * `pricewatch_app`: Servidor web Nginx + PHP-FPM 8.3
  * `pricewatch_worker`: Laravel Queue Worker + Scheduler diário
  * `pricewatch_db`: MySQL 8.0 dedicado
* **Redes:**
  * `coolify` (externa, conecta ao Traefik e GoWA)
  * `pricewatch_internal` (isolada, para comunicação app <-> banco)
* **Volumes:**
  * `pricewatch_mysql_data`: Dados persistentes do MySQL

---

## 2. Deploy Inicial no Servidor

1. Acesse o servidor via SSH:
   ```bash
   ssh -i ~/.ssh/id_pricewatch_deploy root@178.156.245.190
   ```
2. Crie o diretório de produção:
   ```bash
   mkdir -p /data/pricewatch
   cd /data/pricewatch
   ```
3. Clone ou sincronize os arquivos do repositório no diretório `/data/pricewatch`.
4. Configure o arquivo `.env`:
   ```bash
   cp .env.example .env
   # Gere a APP_KEY e defina senhas seguras para o banco e GoWA
   ```
5. Inicie a stack com Docker Compose:
   ```bash
   docker compose up -d --build
   ```

---

## 3. Atualização de Código (Novas Versões)

Para aplicar atualizações com zero impacto nos outros serviços da VPS:

```bash
cd /data/pricewatch
git pull origin main
docker compose build app worker
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
```

---

## 4. Visualização de Logs

* **Logs gerais da aplicação:**
  ```bash
  docker compose -f /data/pricewatch/docker-compose.yml logs -f app
  ```
* **Logs do worker de agendamento:**
  ```bash
  docker compose -f /data/pricewatch/docker-compose.yml logs -f worker
  ```
* **Logs do banco de dados:**
  ```bash
  docker compose -f /data/pricewatch/docker-compose.yml logs -f db
  ```
* **Logs do Laravel:**
  ```bash
  docker compose -f /data/pricewatch/docker-compose.yml exec app tail -f storage/logs/laravel.log
  ```

---

## 5. Backup e Restauração do Banco de Dados

### Backup Diário do MySQL
```bash
# Gerar dump comprimido
docker compose -f /data/pricewatch/docker-compose.yml exec db mysqldump -u root -p"$DB_ROOT_PASSWORD" pricewatch | gzip > /data/pricewatch/backups/pricewatch_backup_$(date +%Y%m%d_%H%M%S).sql.gz
```

### Restauração do Backup
```bash
# Descomprimir e restaurar
gunzip < /data/pricewatch/backups/SEU_BACKUP.sql.gz | docker compose -f /data/pricewatch/docker-compose.yml exec -T db mysql -u root -p"$DB_ROOT_PASSWORD" pricewatch
```

---

## 6. Comandos Manuais Úteis

* **Executar coleta manual de preços via CLI:**
  ```bash
  docker compose -f /data/pricewatch/docker-compose.yml exec app php artisan pricewatch:collect
  ```
* **Coletar apenas uma fonte específica (ex: ID 1):**
  ```bash
  docker compose -f /data/pricewatch/docker-compose.yml exec app php artisan pricewatch:collect --source=1
  ```
* **Criar novo usuário administrador para o painel:**
  ```bash
  docker compose -f /data/pricewatch/docker-compose.yml exec app php artisan make:filament-user
  ```

---

## 7. Procedimento de Rollback de Emergência

Caso seja necessário remover ou pausar o PriceWatch sem deixar resíduos:

```bash
cd /data/pricewatch
docker compose down
```
> O comando acima desliga e remove imediatamente os containers do PriceWatch e libera todas as portas e redes. Os serviços GoWA e Evolution API continuam operando normalmente sem interrupção.
