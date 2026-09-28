# Changelog

Todas as alterações relevantes deste pacote. O projecto segue [Semantic Versioning](https://semver.org/lang/pt-BR/).

## [2.1.0] - 2026-09-28

### Corrigido

- **O pacote não instalava em projectos com Guzzle 8.** A constraint `guzzlehttp/guzzle: ^7.5` impedia a instalação em qualquer projecto onde o Composer tivesse resolvido o Guzzle para a série 8 — o que passou a acontecer com frequência, já que o Laravel 13 aceita `^7.8.2 || ^8.0`. A constraint passou a `^7.15.2 || ^8.0.1`, cobrindo ambos os ramos.
- O mínimo foi fixado em `7.15.2` e `8.0.1` porque abaixo dessas versões o Guzzle é afectado por `CVE-2026-69246` e `CVE-2026-69245`.

A biblioteca nunca usa classes `GuzzleHttp\` directamente — apenas a facade `Http` do Laravel — pelo que a compatibilidade com a série 8 não exige alterações ao código. A CI passa a testar ambos os ramos do Guzzle.

## [2.0.0] - 2026-09-28

### Quebra de compatibilidade

- Requer PHP 8.2+ e Laravel 11, 12 ou 13 (antes PHP 8.0 e Laravel 10–12). O Laravel 10 já não recebe correcções de segurança.
- As pastas de backup passam a usar o formato `AAAA-mm-dd_HH-ii-ss` (antes `dd-mm-AAAA_H-i-s`). Os backups antigos continuam a ser listados, limpos e restaurados.
- `drop-all-databases` pede confirmação em qualquer ambiente, não só em produção.
- Removida a opção de configuração `compression.command` (a compressão gzip é feita em PHP).
- Removida a classe interna `Support\MySqlRunner` (substituída por `Support\MySqlClient`).

### Corrigido

- Um backup com `--gzip` cujo `mysqldump` falhava era reportado como sucesso (o código de saída era o do `gzip`).
- Dumps falhados ou incompletos ficavam no disco com aspecto de backup válido.
- `drop-all-tables` falhava com chaves estrangeiras e usava `2>/dev/null` também em Windows.
- `list-backups` ordenava os backups pela data escrita como texto (dia primeiro).
- Uma conexão inexistente mostrava um stack trace em vez de uma mensagem de erro.
- Ficava uma pasta vazia quando não havia conexões para exportar.
- Passwords com `#`, `;`, espaços, aspas ou `\` partiam o ficheiro de credenciais.
- O restauro falhava quando o dump continha `DEFINER` de outro utilizador.
- A opção `supported_drivers` era ignorada.
- As notificações Slack falhavam em Laravel 10 por falta do Guzzle (agora é dependência do pacote).

### Adicionado

- `manifest.json` com checksum SHA-256 de cada dump, verificado no restauro (`--skip-verify`).
- Verificação de que cada dump termina com `-- Dump completed`.
- Cópia para disks remotos do Laravel (`disks`, `--disk=`) e limpeza remota no `prune-backups`.
- Notificações por Slack e email, e eventos `BackupSucceeded` / `BackupFailed`.
- `restore-database --latest` (pasta ou ZIP) e `--create`.
- Configuração do caminho dos binários (`binaries`).
- `BackupManager` para fazer backups a partir do código.
- Mensagens de erro do MySQL mostradas sem precisar de `-v`.
- Testes (PHPUnit + Testbench, incluindo integração com MySQL), Larastan, Pint e GitHub Actions (PHP 8.2–8.4 × Laravel 11–13).

### Alterado

- Os comandos usam o `Process` do Laravel sem passar pela shell; o timeout aplica-se a cada processo.
- Compressão gzip feita em PHP, sem depender do `gzip`/`gunzip` do sistema.
