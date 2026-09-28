<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Caminho padrão para backups
    |--------------------------------------------------------------------------
    |
    | Cada backup é guardado numa pasta "AAAA-mm-dd_HH-ii-ss" (ou num ficheiro
    | .zip com o mesmo nome) contendo um dump por conexão e um manifest.json
    | com o checksum SHA-256 de cada ficheiro.
    |
    */
    'default_backup_path' => storage_path('app/backups-databases'),

    /*
    |--------------------------------------------------------------------------
    | Drivers de base de dados suportados
    |--------------------------------------------------------------------------
    */
    'supported_drivers' => ['mysql', 'mariadb'],

    /*
    |--------------------------------------------------------------------------
    | Binários
    |--------------------------------------------------------------------------
    |
    | Caminho para o mysqldump e o mysql, caso não estejam no PATH.
    |
    */
    'binaries' => [
        'mysqldump' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),
        'mysql' => env('BACKUP_MYSQL_PATH', 'mysql'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Opções do mysqldump
    |--------------------------------------------------------------------------
    */
    'mysqldump' => [
        'single_transaction' => true,
        'routines' => true,
        'triggers' => true,
        'max_allowed_packet' => '512M',
        'net_buffer_length' => '16384',
        'skip_lock_tables' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Opções de restauro
    |--------------------------------------------------------------------------
    |
    | "strip_definers" remove as cláusulas DEFINER=`user`@`host` de views,
    | triggers e rotinas, para que o dump possa ser restaurado por um
    | utilizador diferente daquele que criou esses objectos.
    |
    */
    'restore' => [
        'disable_foreign_key_checks' => true,
        'disable_unique_checks' => true,
        'strip_definers' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Compressão
    |--------------------------------------------------------------------------
    |
    | "enabled" comprime cada dump com gzip (feito em PHP, funciona em Windows).
    | "zip" agrupa a pasta do backup num único ficheiro .zip (requer ext-zip).
    |
    */
    'compression' => [
        'enabled' => false,
        'zip' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cópia remota
    |--------------------------------------------------------------------------
    |
    | Disks do Laravel (config/filesystems.php) para onde cada backup é
    | enviado, por exemplo ['s3']. A cópia local é sempre mantida.
    |
    */
    'disks' => [],

    'remote_path' => 'backups-databases',

    /*
    |--------------------------------------------------------------------------
    | Limpeza automática (prune)
    |--------------------------------------------------------------------------
    */
    'prune_older_than_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Timeout máximo em segundos por operação (0 = sem limite)
    |--------------------------------------------------------------------------
    */
    'timeout' => 0,

    /*
    |--------------------------------------------------------------------------
    | Notificações (Slack, Email)
    |--------------------------------------------------------------------------
    |
    | "notify_on" aceita "failure" e/ou "success".
    |
    */
    'notifications' => [
        'enabled' => env('BACKUP_NOTIFICATIONS', false),
        'notify_on' => ['failure'],
        'slack_webhook_url' => env('BACKUP_SLACK_WEBHOOK_URL'),
        'mail_to' => env('BACKUP_MAIL_TO'),
    ],

];
