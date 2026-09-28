# Changelog

Todas as alterações relevantes deste pacote. O projecto segue [Semantic Versioning](https://semver.org/lang/pt-BR/).

## [2.0.0] - Por publicar

### Quebra de compatibilidade

- Requer PHP 8.1+ (antes 8.0), por causa do `Process` do Laravel.
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

### Adicionado

- `manifest.json` com checksum SHA-256 de cada dump, verificado no restauro (`--skip-verify`).
- Verificação de que cada dump termina com `-- Dump completed`.
- Cópia para disks remotos do Laravel (`disks`, `--disk=`) e limpeza remota no `prune-backups`.
- Notificações por Slack e email, e eventos `BackupSucceeded` / `BackupFailed`.
- `restore-database --latest` (pasta ou ZIP) e `--create`.
- Configuração do caminho dos binários (`binaries`).
- `BackupManager` para fazer backups a partir do código.
- Mensagens de erro do MySQL mostradas sem precisar de `-v`.
- Testes (PHPUnit + Testbench, incluindo integração com MySQL), Larastan, Pint e GitHub Actions.

### Alterado

- Os comandos usam o `Process` do Laravel sem passar pela shell; o timeout aplica-se a cada processo.
- Compressão gzip feita em PHP, sem depender do `gzip`/`gunzip` do sistema.
