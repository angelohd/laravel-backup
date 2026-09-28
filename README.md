# Laravel Backup

Biblioteca Laravel para fazer backup, restauro e gestão de bases de dados MySQL/MariaDB.

- Backup de todas as conexões MySQL/MariaDB configuradas em `config/database.php`
- Compressão gzip (feita em PHP, funciona também em Windows) e agrupamento em ZIP
- `manifest.json` com checksum SHA-256 de cada dump, verificado no restauro
- Verificação de que o dump está completo: backups falhados nunca ficam no disco
- Cópia para disks remotos do Laravel (S3, FTP, SFTP…)
- Notificações por Slack e email, e eventos `BackupSucceeded` / `BackupFailed`
- Credenciais nunca expostas na linha de comandos

## Requisitos

- PHP 8.1+
- Laravel 10.x / 11.x / 12.x
- Binários `mysqldump` e `mysql` (no PATH ou configurados em `angelohd-backup.binaries`)
- MySQL 5.7+ ou MariaDB 10.2+
- `ext-zip` apenas para a opção `--zip`

## Instalação

```bash
composer require angelohd/laravel-backup
```

O pacote usa o auto-discovery do Laravel. Não é necessário registar o provider manualmente.

## Configuração

```bash
php artisan vendor:publish --tag=angelohd-backup-config
```

O ficheiro `config/angelohd-backup.php` permite configurar:

| Opção | Descrição |
|---|---|
| `default_backup_path` | Pasta local dos backups |
| `supported_drivers` | Drivers incluídos no backup (`mysql`, `mariadb`) |
| `binaries` | Caminho do `mysqldump` e do `mysql` (`BACKUP_MYSQLDUMP_PATH`, `BACKUP_MYSQL_PATH`) |
| `mysqldump` | Opções do `mysqldump` |
| `restore` | Desactivar FK/unique checks e remover cláusulas `DEFINER` no restauro |
| `compression` | gzip e ZIP por omissão |
| `disks` / `remote_path` | Disks remotos e pasta de destino neles |
| `prune_older_than_days` | Idade por omissão para a limpeza |
| `timeout` | Tempo máximo por operação em segundos (0 = sem limite) |
| `notifications` | Slack / email (`BACKUP_NOTIFICATIONS`, `BACKUP_SLACK_WEBHOOK_URL`, `BACKUP_MAIL_TO`) |

## Comandos

### Criar backup

```bash
php artisan angelohd:backup-database
php artisan angelohd:backup-database --connection=mysql
php artisan angelohd:backup-database --gzip --zip
php artisan angelohd:backup-database --path=/caminho/personalizado
php artisan angelohd:backup-database --disk=s3
```

Cria uma pasta `AAAA-mm-dd_HH-ii-ss` com um ficheiro `.sql` (ou `.sql.gz`) por conexão e um `manifest.json`. Com `--zip`, a pasta é substituída por `AAAA-mm-dd_HH-ii-ss.zip`.

O comando termina com código de erro se algum dump falhar ou estiver incompleto, e o ficheiro parcial é apagado.

### Restaurar backup

```bash
# Ficheiro concreto
php artisan angelohd:restore-database storage/app/backups-databases/2026-01-01_12-00-00/mysql.sql.gz --connection=mysql --database=minha_bd

# Backup mais recente (pasta ou ZIP), criando a base de dados se não existir
php artisan angelohd:restore-database --latest --connection=mysql --database=minha_bd --create
```

Se existir um `manifest.json`, o checksum é verificado antes do restauro (`--skip-verify` para ignorar). Os ficheiros `.gz` são detectados pela extensão.

Por omissão, as cláusulas `DEFINER=` de views, triggers e rotinas são removidas, para que um dump possa ser restaurado por outro utilizador (ex.: dump de produção restaurado localmente). Desactive com `restore.strip_definers = false`.

### Listar backups

```bash
php artisan angelohd:list-backups
php artisan angelohd:list-backups --path=/caminho/personalizado
```

### Apagar backups antigos

```bash
php artisan angelohd:prune-backups --older-than=30
php artisan angelohd:prune-backups --older-than=7 --dry-run
php artisan angelohd:prune-backups --older-than=7 --force
php artisan angelohd:prune-backups --local-only
```

Limpa a pasta local e os disks configurados em `disks` (ou os indicados com `--disk=`).

### Informação da base de dados

```bash
php artisan angelohd:database-info
php artisan angelohd:database-info --connection=mysql
```

O número de registos é uma estimativa do MySQL (`information_schema.TABLES`).

### Apagar base de dados

```bash
php artisan angelohd:drop-database nome_bd --connection=mysql
```

Só apaga bases de dados que estejam configuradas numa conexão.

### Apagar todas as tabelas

```bash
php artisan angelohd:drop-all-tables --connection=mysql
```

Apaga tabelas e views, mesmo com chaves estrangeiras.

### Apagar todas as bases de dados

```bash
php artisan angelohd:drop-all-databases
```

## Segurança

- Em produção (`APP_ENV=production`), todos os comandos destrutivos pedem confirmação. Use `--force` para a saltar.
- `drop-all-databases` pede confirmação **em qualquer ambiente**. Sem terminal interactivo e sem `--force`, a operação é cancelada.
- As credenciais são passadas ao `mysql`/`mysqldump` por um ficheiro temporário com permissão `0600`, apagado logo a seguir.

## Agendamento (Scheduler)

```php
// routes/console.php (Laravel 11+) ou app/Console/Kernel.php

Schedule::command('angelohd:backup-database --gzip')->dailyAt('03:00');
Schedule::command('angelohd:prune-backups --force')->dailyAt('04:00');
```

## Uso a partir do código

```php
use angelohd\Backup\BackupManager;

$report = app(BackupManager::class)->run(gzip: true, disks: ['s3']);

if (! $report->successful()) {
    // $report->errors, $report->uploadErrors
}
```

Eventos disponíveis: `angelohd\Backup\Events\BackupSucceeded` e `angelohd\Backup\Events\BackupFailed`, ambos com a propriedade `$report`.

## Desenvolvimento

```bash
composer test      # PHPUnit (os testes de integração precisam de MySQL, ver phpunit.xml.dist)
composer analyse   # Larastan
composer format    # Laravel Pint
```

## Licença

MIT
