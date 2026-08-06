# Laravel Backup

Biblioteca Laravel para realizar backup automatico da base de dados.

## Instalacao

```bash
composer require angelohd/laravel-backup
```

O pacote usa auto-discovery do Laravel. Nao e necessario registar o provider manualmente.

## Configuracao

Publica o ficheiro de configuracao:

```bash
php artisan vendor:publish --tag=angelohd-backup-config
```

O ficheiro `config/angelohd-backup.php` permite configurar:
- Caminho padrao dos backups
- Opcoes do `mysqldump`
- Compressao gzip
- Timeout
- Dias para limpeza automatica

## Requisitos

- PHP 8.0+
- Laravel 10.x / 11.x / 12.x
- `mysqldump` e `mysql` instalados no PATH
- MySQL 5.7+ ou MariaDB 10.2+

## Comandos

### Criar backup

```bash
php artisan angelohd:backup-database
php artisan angelohd:backup-database --connection=mysql
php artisan angelohd:backup-database --gzip
php artisan angelohd:backup-database --path=/caminho/personalizado
```

Cria uma pasta `dd-mm-AAAA_H-i-s` com os ficheiros `.sql` de cada conexao MySQL/MariaDB.

### Restaurar backup

```bash
php artisan angelohd:restore-database storage/app/backups-databases/01-01-2026_12-00-00/mysql.sql --connection=mysql --database=minha_bd
php artisan angelohd:restore-database backup.sql.gz --connection=mysql --database=minha_bd --gzip
```

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
```

### Informacao da base de dados

```bash
php artisan angelohd:database-info
php artisan angelohd:database-info --connection=mysql
```

### Apagar base de dados

```bash
php artisan angelohd:drop-database nome_bd --connection=mysql
php artisan angelohd:drop-database nome_bd --connection=mysql --force
```

### Apagar todas as tabelas

```bash
php artisan angelohd:drop-all-tables --connection=mysql
php artisan angelohd:drop-all-tables --connection=mysql --force
```

### Apagar todas as bases de dados

```bash
php artisan angelohd:drop-all-databases
php artisan angelohd:drop-all-databases --force
```

## Seguranca em producao

Em ambiente de producao (`APP_ENV=production`), todos os comandos destrutivos pedem confirmacao. Usa `--force` para saltar a confirmacao.

## Agendamento (Scheduler)

```php
// app/Console/Kernel.php

$schedule->command('angelohd:backup-database --gzip')->dailyAt('03:00');
$schedule->command('angelohd:prune-backups --older-than=30 --force')->dailyAt('04:00');
```

## Licenca

MIT
