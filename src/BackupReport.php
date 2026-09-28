<?php

declare(strict_types=1);

namespace angelohd\Backup;

class BackupReport
{
    /** @var array<string, array{connection: string, database: string, path: string, size: int, sha256: string}> */
    public array $files = [];

    /** @var array<string, string> erros indexados pela conexao ('*' para erros gerais) */
    public array $errors = [];

    /** @var array<string, string> erros de envio indexados pelo disk */
    public array $uploadErrors = [];

    /** @var string[] disks para onde o backup foi enviado com sucesso */
    public array $uploadedTo = [];

    public ?string $directory = null;

    public ?string $zipPath = null;

    public function __construct(public string $timestamp) {}

    /** Caminho final do backup (ZIP ou pasta). */
    public function location(): ?string
    {
        return $this->zipPath ?? $this->directory;
    }

    public function successful(): bool
    {
        return $this->files !== [] && $this->errors === [] && $this->uploadErrors === [];
    }
}
