# angelohd/laravel-backup

### Backups de MySQL/MariaDB para Laravel que não falham em silêncio

---

> **O resumo em uma frase:** `angelohd/laravel-backup` é uma biblioteca PHP que adiciona ao Laravel comandos Artisan para criar, verificar, arquivar, enviar para a nuvem e restaurar backups de todas as bases de dados MySQL/MariaDB configuradas na aplicação — com checksum SHA-256 em cada ficheiro, credenciais fora da linha de comandos e garantia de que um backup falhado nunca fica no disco com aparência de backup válido.

---

## Índice

1. [O problema: o backup que ninguém restaurou](#1-o-problema-o-backup-que-ninguém-restaurou)
2. [Porque é que fazer backup é uma obrigação, não uma opção](#2-porque-é-que-fazer-backup-é-uma-obrigação-não-uma-opção)
3. [A regra 3-2-1 e os conceitos que um bom backup deve respeitar](#3-a-regra-3-2-1-e-os-conceitos-que-um-bom-backup-deve-respeitar)
4. [O que é o angelohd/laravel-backup](#4-o-que-é-o-angelohdlaravel-backup)
5. [Vantagens da biblioteca](#5-vantagens-da-biblioteca)
6. [Requisitos e instalação](#6-requisitos-e-instalação)
7. [Configuração](#7-configuração)
8. [Comandos](#8-comandos)
9. [Integridade: como a biblioteca garante que um backup é restaurável](#9-integridade-como-a-biblioteca-garante-que-um-backup-é-restaurável)
10. [Segurança](#10-segurança)
11. [Cópia remota e retenção](#11-cópia-remota-e-retenção)
12. [Agendamento automático](#12-agendamento-automático)
13. [Uso a partir do código](#13-uso-a-partir-do-código)
14. [Eventos e notificações](#14-eventos-e-notificações)
15. [Boas práticas](#15-boas-práticas)
16. [Quando usar esta biblioteca e quando não usar](#16-quando-usar-esta-biblioteca-e-quando-não-usar)
17. [Comparação com as alternativas habituais](#17-comparação-com-as-alternativas-habituais)
18. [Desenvolvimento e qualidade](#18-desenvolvimento-e-qualidade)
19. [Resolução de problemas](#19-resolução-de-problemas)
20. [Licença e autor](#20-licença-e-autor)

---

## 1. O problema: o backup que ninguém restaurou

A maioria dos disastros de bases de dados não acontece por falta de cópias de segurança. Acontece por três razões muito mais comuns:

**O backup nunca existiu.** A equipa decidiu que "os dumps são feitos à mão quando alguém se lembra" ou que "o alojamento já faz backup". A verdade é que o backup do alojamento protege contra a perda da máquina, não contra uma migração mal executada, um `DROP TABLE` acidental, um deploy com um comando destrutivo ou um ataque de ransomware.

**O backup existe, mas está partido.** Um `mysqldump` interrompido por falta de espaço em disco, por timeout ou por uma queda de rede deixa um ficheiro pela metade. O ficheiro existe, o cron devolveu sucesso, o painel de controlo mostra um backup "verde" — e a equipa só descobre que o ficheiro estava truncado no pior dia possível. Isto é o cenário de falha mais grave que existe, porque falha em silêncio.

**O backup existe, mas é inútil.** Está no mesmo servidor da aplicação. Se a máquina arder, o backup arde com ela. Ou está numa pasta que ninguém sabe gerir, ou o dump é tão grande que restaurar exige horas — e não existem testes que confirmem que o ficheiro ainda restaura.

Qualquer solução que responda apenas a "como é que faço um dump?" não resolve o problema real. Um bom backup tem de responder a três perguntas: **foi feito?**, **está completo?** e **consegue ser restaurado?**

---

## 2. Porque é que fazer backup é uma obrigação, não uma opção

Um dump SQL bem feito é, em última análise, uma **cópia integral e legível da base de dados**. A sua utilidade deriva dessa legibilidade: pode ser restaurado em qualquer servidor compatível, transformado em dados para migrações, inspecionado manualmente, ou usado para testes e reprodutibilidade.

As razões pelas quais esta prática é indispensável:

| Motivo | O que um dump protege |
|---|---|
| **Falha de hardware** | Discos que avariam, servidores que morrem, fornecedores que fecham |
| **Erro humano** | `DROP TABLE`, `DROP DATABASE`, migrações mal escritas, `git checkout` sobre ficheiros de conteúdo |
| **Ataques** | Ransomware que cifra ficheiros e apaga shadow copies; injeção de SQL destrutiva |
| **Migrações** | Reverter uma migração de esquema que se revelou incompatível com os dados em produção |
| **Conformidade** | Retenção de dados, requisitos de disponibilidade, auditoria, requisitos contratuais de continuidade |
| **Ambientes não produtivos** | Repor dados reais em *staging* e desenvolvimento, mantendo o rigor de uma cópia de produção |
| **Continuidade de negócio** | RTO e RPO definidos e atingíveis, com um plano que é testado e não apenas documentado |

A consequência prática de não ter uma cópia de dados verificável é simples e difícil de reverter: **um sistema de produção é, na prática, um sistema cuja reconstructibilidade ninguém testou**.

---

## 3. A regra 3-2-1 e os conceitos que um bom backup deve respeitar

Antes de descrever a biblioteca, vale fixar o referencial. Uma estratégia de cópias de segurança séria respeita a regra **3-2-1**:

- **3** cópias dos dados: a original mais duas redundâncias;
- **2** suportes diferentes: disco local mais armazenamento remoto;
- **1** cópia fora das instalações: um bucket S3, um servidor noutra região, um fornecedor terceiro.

A esta regra acrescentam-se dois conceitos que determinam se a estratégia funciona:

**RPO (Recovery Point Objective)** — quanto tempo de dados se aceita perder. Um backup diário significa um RPO de até 24 horas. Backups horários reduzem-no para uma hora. Esta é uma decisão de negócio, não técnica, e é ela que define a frequência.

**RTO (Recovery Time Objective)** — quanto tempo se aceita demorar a recuperar. Um dump de 40 GB que demora seis horas a restaurar obriga a decidir entre um plano de recuperação lento e o abandono do sistema por esse período. Compressão, cópia remota e automatização do restauro existem para reduzir precisamente este número.

E há um terceiro requisito, o mais frequentemente ignorado: **o backup tem de ser verificado**. Uma cópia que nunca foi restaurada é uma hipótese, não uma garantia. É por isso que a biblioteca que se apresenta a seguir verifica a integridade do ficheiro no momento em que o cria e no momento em que o restaura.

---

## 4. O que é o angelohd/laravel-backup

O `angelohd/laravel-backup` é um pacote Composer do tipo *library* que se instala num projeto Laravel e regista, por auto-discovery, um conjunto de comandos Artisan com o prefixo `angelohd:`.

Não é um painel web, não requer uma base de dados de catálogo, não funciona como um serviço SaaS e não pede qualquer licença para além da MIT. É código PHP dentro da aplicação, que lê a configuração que já existe (`config/database.php`) e fala diretamente com os binários `mysqldump` e `mysql`.

Em cada execução cria uma pasta com carimbo temporal no formato `AAAA-mm-dd_HH-ii-ss`, contendo um dump por conexão e um `manifest.json`:

```
storage/app/backups-databases/2026-01-15_03-00-00/
├── mysql.sql
├── mysql_reports.sql
└── manifest.json
```

```json
{
    "created_at": "2026-01-15T03:00:04+00:00",
    "app": "Minha aplicacao",
    "files": {
        "mysql.sql": {
            "connection": "mysql",
            "database": "minha_bd",
            "size": 184532210,
            "sha256": "9f2c...a71b"
        }
    }
}
```

O objectivo de design da biblioteca resume-se numa frase: **usar apenas as ferramentas nativas do MySQL para produzir cópias verificáveis, e eliminar todas as formas pelas quais um backup pode falhar sem que ninguém saiba.**

---

## 5. Vantagens da biblioteca

### 5.1 Configuração zero

O pacote não exige um ficheiro de configuração seu. No momento da instalação, lê as conexões já definidas em `config/database.php` e exporta todas as que usam os drivers `mysql` ou `mariadb`. Não há base de dados de catálogo para manter, nem tabelas de migração, nem chaves de API para configurar.

### 5.2 Integridade verificada em duas frentes

Cada dump é validado **antes** de ser considerado backup: o ficheiro tem de existir, ter tamanho diferente de zero e terminar com a assinatura `-- Dump completed` que o `mysqldump` escreve apenas quando conclui a exportação. Se alguma destas condições falhar, o ficheiro é apagado e a operação é reportada como falha.

Cada dump é também validado **no momento do restauro** através do `manifest.json`: o SHA-256 é recalculado e comparado com o valor gravado, usando `hash_equals()` para não ficar sujeito a ataques de temporização. Um ficheiro corrompido ou alterado é rejeitado antes de tocar na base de dados.

Esta combinação elimina a classe de falha mais perigosa em backups: **o backup que existe mas não é restaurável**.

### 5.3 Nunca deixa ficheiros parciais no disco

O tratamento de erros é explícito em todo o fluxo. Se o `mysqldump` falhar, se a compressão falhar, se o ZIP não puder ser gravado, o ficheiro parcial é eliminado e a operação devolve um código de erro ao shell. Um backup falhado não ocupa espaço, não aparece em listagens e não é tomado por válido por um humano a olhar para a pasta.

### 5.4 Credenciais fora da linha de comandos

Este é um detalhe que separa uma implementação cuidada de uma implementação improvisada. Invocar `mysqldump -u utilizador -p'palavra-passe'` é um erro grave e ubiquitário:

- Em sistemas Unix, os argumentos de um processo são legíveis por qualquer utilizador do sistema através de `/proc/<pid>/cmdline` ou do comando `ps`;
- Em Windows, a linha de comandos é legível por qualquer ferramenta de administração de processos;
- Em painéis de hospedagem partilhada, os comandos acabam em logs persistentes;
- Em sistemas de CI e orquestadores, os argumentos acabam por vezes no histórico de execução.

A biblioteca gera um ficheiro de opções `--defaults-extra-file` temporário, com permissões `0600`, com os valores correctamente escapados para o parser do MySQL, e apaga-o imediatamente depois de o processo terminar — incluindo numa cláusula `finally` e no destructor, para que uma excepção não deixe credenciais no disco. As passwords com `#`, `;`, aspas, espaços ou barras invertidas são escapadas corretamente, um bug que quebrou implementações mais simples.

### 5.5 Sem shell, com execução como array

Todos os processos são executados através do componente `Process` do Laravel, com os argumentos passados como **array** e não como string. Não existe concatenação de comandos, nem interpretação de metacaracteres, nem dependência de um interpretador de shell diferente entre Linux, macOS e Windows. O código de saída verificado é o do próprio binário `mysqldump` — e não o do `gzip`, que foi precisamente a causa de um bug corrigido na versão 2.0, em que um dump falhado com `--gzip` era reportado como sucesso.

### 5.6 Compressão que funciona em qualquer sistema operativo

A compressão gzip é feita em PHP, com `gzopen`/`gzwrite`, em blocos de 1 MB, e não por um executável externo. Isto elimina a dependência de `gzip` e `gunzip` — que não existem nativamente no Windows — e permite ao mesmo comando funcionar em servidores Linux, contentores, pipelines de CI e estações de desenvolvimento Windows sem configuração adicional. A compressão é configurável por omissão e o ZIP é opcional, com recuo para a pasta original se `ext-zip` não estiver disponível.

### 5.7 Dumps consistentes sem bloquear a aplicação

As opções de `mysqldump` são escolhidas com defaults sensatos: `--single-transaction` garante uma exportação consistente em tabelas InnoDB sem bloquear escritas, `--routines` e `--triggers` garantem que stored procedures, funções e triggers são incluídos, e `--skip-lock-tables` evita bloqueios de tabela durante o dump. Valores como `--max-allowed-packet` e `--net-buffer-length` estão pré-configurados para dumps de grande dimensão.

### 5.8 Restauro que funciona noutro utilizador e noutro ambiente

Um dos problemas mais comuns ao restaurar um dump é a cláusula `DEFINER=`ut-utilizador`@`host`, presente em views, triggers e rotinas. Se o dump foi criado por um utilizador que não existe no servidor de destino, o restauro falha com `Access denied` — mesmo com a base de dados completamente válida. A biblioteca filtra essas cláusulas em streaming durante o restauro (`DefinerFilter`), o que significa que **um dump de produção pode ser restaurado numa máquina de desenvolvimento**. O comportamento é configurável.

O restauro também desactiva verificações de chaves estrangeiras e de unicidade durante a carga, o que permite repor o schema e os dados numa ordem arbitrária, e envia o SQL por `stdin` em vez de argumentos, eliminando os limites de tamanho da linha de comandos.

### 5.9 Cópia remota com os disks que já conhece

O Laravel já resolve S3, FTP, SFTP, Backblaze B2, Google Drive, e dozens de outros destinos através do contrato de *filesystem*. A biblioteca usa exactamente esse contrato: configura-se `disks => ['s3']` e cada backup é copiado para lá, mantendo sempre a cópia local. A limpeza automática (`prune-backups`) varre os mesmos disks, o que evita a crescimento indefinido do custo de armazenamento.

### 5.10 Notificações e eventos nativos

Cada execução emite um evento, `BackupSucceeded` ou `BackupFailed`, com um relatório estruturado. Um *listener* incluído no pacote traduz esse evento para Slack e para email. Mais importante ainda: **uma falha na notificação nunca interrompe o backup** — as falhas de envio são registadas em log, não propagadas.

### 5.11 Protecção contra operações destrutivas

Todos os comandos destrutivos exigem confirmação em produção, e `drop-all-databases` exige confirmação em **qualquer** ambiente. Sem terminal interactivo e sem `--force`, a operação é cancelada. Combinado com o facto de o `drop-database` só aceitar bases de dados que estejam declaradas numa conexão do `config/database.php`, o raio de acção de um comando escrito apressadamente é consideravelmente menor.

### 5.12 Compatibilidade e manutenção

- PHP 8.2, 8.3 e 8.4;
- Laravel 11, 12 e 13;
- MySQL 5.7+ e MariaDB 10.2+;
- Análise estática de nível estrito com Larastan, formatação com Pint, e CI em GitHub Actions numa matriz de versões.

O autor mantém o pacote activamente. O Laravel 10 deixou de receber correcções de segurança upstream — decisão deliberada e assumida no `CHANGELOG`.

---

## 6. Requisitos e instalação

**Requisitos**

| Componente | Versão mínima |
|---|---|
| PHP | 8.2 (8.3+ para Laravel 13) |
| Laravel | 11.x, 12.x ou 13.x |
| Extensão PHP | `ext-zlib` obrigatória; `ext-zip` apenas para `--zip` |
| Base de dados | MySQL 5.7+ ou MariaDB 10.2+ |
| Binários | `mysqldump` e `mysql` no `PATH`, ou configurados em `angelohd-backup.binaries` |

**Instalação**

```bash
composer require angelohd/laravel-backup
```

O pacote utiliza o auto-discovery do Laravel. Não é necessário registar o `BackupServiceProvider` manualmente.

Publicação do ficheiro de configuração (opcional — todos os valores têm default):

```bash
php artisan vendor:publish --tag=angelohd-backup-config
```

**Primeiro teste**

```bash
php artisan angelohd:backup-database --gzip
```

---

## 7. Configuração

Ficheiro: `config/angelohd-backup.php`

| Opção | Descrição | Default |
|---|---|---|
| `default_backup_path` | Pasta local dos backups | `storage/app/backups-databases` |
| `supported_drivers` | Drivers incluídos no backup | `['mysql', 'mariadb']` |
| `binaries.mysqldump` | Caminho do `mysqldump` | `env('BACKUP_MYSQLDUMP_PATH', 'mysqldump')` |
| `binaries.mysql` | Caminho do `mysql` | `env('BACKUP_MYSQL_PATH', 'mysql')` |
| `mysqldump.single_transaction` | Exportação consistente sem bloquear escritas | `true` |
| `mysqldump.routines` | Incluir stored procedures e funções | `true` |
| `mysqldump.triggers` | Incluir triggers | `true` |
| `mysqldump.skip_lock_tables` | Não bloquear tabelas durante o dump | `true` |
| `mysqldump.max_allowed_packet` | Tamanho máximo de pacote | `'512M'` |
| `mysqldump.net_buffer_length` | Buffer de rede | `'16384'` |
| `restore.disable_foreign_key_checks` | Desactivar FK checks no restauro | `true` |
| `restore.disable_unique_checks` | Desactivar unique checks no restauro | `true` |
| `restore.strip_definers` | Remover cláusulas `DEFINER=` no restauro | `true` |
| `compression.enabled` | Comprimir cada dump com gzip | `false` |
| `compression.zip` | Agrupar a pasta num `.zip` | `false` |
| `disks` | Disks remotos para cópia | `[]` |
| `remote_path` | Pasta de destino nos disks remotos | `'backups-databases'` |
| `prune_older_than_days` | Idade por omissão para a limpeza | `7` |
| `timeout` | Timeout por operação em segundos (`0` = sem limite) | `0` |
| `notifications.enabled` | Activar notificações | `env('BACKUP_NOTIFICATIONS', false)` |
| `notifications.notify_on` | `['failure']`, `['success']` ou ambos | `['failure']` |
| `notifications.slack_webhook_url` | Webhook do Slack | `env('BACKUP_SLACK_WEBHOOK_URL')` |
| `notifications.mail_to` | Destinatário de email | `env('BACKUP_MAIL_TO')` |

Exemplo de `.env`:

```dotenv
BACKUP_MYSQLDUMP_PATH=/usr/bin/mysqldump
BACKUP_NOTIFICATIONS=true
BACKUP_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/xxx/yyy/zzz
BACKUP_MAIL_TO=ops@empresa.co.ao
```

---

## 8. Comandos

### 8.1 Criar backup

```bash
# Todas as conexões MySQL/MariaDB configuradas
php artisan angelohd:backup-database

# Apenas uma conexão
php artisan angelohd:backup-database --connection=mysql

# Com gzip e ZIP num único ficheiro
php artisan angelohd:backup-database --gzip --zip

# Pasta de destino personalizada
php artisan angelohd:backup-database --path=/var/backups/app

# Enviar também para um disk remoto
php artisan angelohd:backup-database --disk=s3 --disk=b2
```

| Opção | Efeito |
|---|---|
| `--path=` | Substitui `default_backup_path` nesta execução |
| `--connection=` | Restringe a uma conexão específica |
| `--gzip` | Comprime cada dump com gzip (implementação em PHP) |
| `--zip` | Substitui a pasta por `AAAA-mm-dd_HH-ii-ss.zip` |
| `--disk=*` | Envia o resultado para um ou mais disks remotos |

Cada backup termina com um código de erro diferente de zero se algum dump falhar ou estiver incompleto, e o ficheiro parcial é removido. Os erros do MySQL são apresentados sem necessidade de `-v`.

### 8.2 Restaurar

```bash
# A partir de um ficheiro concreto
php artisan angelohd:restore-database \
    storage/app/backups-databases/2026-01-15_03-00-00/mysql.sql.gz \
    --connection=mysql --database=minha_bd

# Backup mais recente (pasta ou ZIP), criando a base de dados se necessário
php artisan angelohd:restore-database \
    --latest --connection=mysql --database=minha_bd --create
```

| Opção | Efeito |
|---|---|
| `{file}` | Ficheiro `.sql` ou `.sql.gz` a restaurar |
| `--connection=` | Conexão de destino (obrigatório) |
| `--database=` | Base de dados de destino (obrigatório) |
| `--latest` | Usa o backup mais recente, extraindo do ZIP se necessário |
| `--path=` | Pasta dos backups quando `--latest` é usado |
| `--gzip` | Força a leitura como gzip |
| `--create` | Executa `CREATE DATABASE IF NOT EXISTS` |
| `--skip-verify` | Ignora a verificação do checksum do `manifest.json` |
| `--force` | Não pede confirmação |

O ficheiro `.gz` é detectado automaticamente pela extensão. Se existir um `manifest.json`, o checksum é verificado **antes** de qualquer escrita na base de dados; um ficheiro corrompido é recusado com uma mensagem explícita. Backups antigos, sem manifest, restauram normalmente com um aviso informativo.

### 8.3 Listar backups

```bash
php artisan angelohd:list-backups
php artisan angelohd:list-backups --path=/var/backups/app
```

Mostra cada backup com data, tamanho e connections incluídas, ordenados cronologicamente pelo formato `AAAA-mm-dd_HH-ii-ss`.

### 8.4 Limpar backups antigos

```bash
php artisan angelohd:prune-backups --older-than=30
php artisan angelohd:prune-backups --older-than=7 --dry-run
php artisan angelohd:prune-backups --older-than=7 --force
php artisan angelohd:prune-backups --local-only
```

| Opção | Efeito |
|---|---|
| `--older-than=` | Idade mínima em dias (default: `prune_older_than_days`) |
| `--path=` | Pasta alternativa dos backups |
| `--disk=*` | Disks remotos a limpar (por omissão, os de `config`) |
| `--local-only` | Não toca nos disks remotos |
| `--dry-run` | Apenas lista o que seria apagado |
| `--force` | Não pede confirmação |

A limpeza cobre a cópia local **e** os disks remotos, o que permite manter 7 dias no servidor e 90 dias no bucket de objectos com um único comando agendado.

### 8.5 Informação da base de dados

```bash
php artisan angelohd:database-info
php artisan angelohd:database-info --connection=mysql
```

Mostra tabelas, número de registos e tamanho estimado. O número de registos provém de `information_schema.TABLES` e é, por isso, uma estimativa — não um `COUNT(*)` que seria caro em tabelas grandes.

### 8.6 Apagar base de dados

```bash
php artisan angelohd:drop-database minha_bd --connection=mysql
```

Só apaga bases de dados declaradas numa conexão configurada — o que impede que um nome errado seja executado contra um servidor onde não estava previsto.

### 8.7 Apagar todas as tabelas

```bash
php artisan angelohd:drop-all-tables --connection=mysql
```

Apaga tabelas e views mesmo com chaves estrangeiras activas, desativando temporariamente a verificação de integridade referencial.

### 8.8 Apagar todas as bases de dados

```bash
php artisan angelohd:drop-all-databases
```

Pede confirmação em qualquer ambiente. **Usar apenas em máquinas descartáveis, jamais em produção.**

---

## 9. Integridade: como a biblioteca garante que um backup é restaurável

Este é o núcleo técnico da biblioteca e vale a pena explicá-lo em detalhe.

**Na criação do backup:**

1. O `mysqldump` escreve para o ficheiro directamente através de `--result-file`, sem shell;
2. O `MySqlClient` confirma que o processo terminou com código de saída zero e que o `stderr` não contém erros;
3. O `BackupManager` verifica que o ficheiro existe e não tem tamanho zero;
4. Lê os últimos 512 bytes do ficheiro e confirma a presença da assinatura `-- Dump completed`, que o MySQL escreve apenas no final de uma exportação bem sucedida;
5. Se qualquer verificação falhar, o ficheiro é apagado e a conexão é marcada como erro;
6. Se todas as conexões falharem, a pasta inteira é removida;
7. Se pelo menos um dump for válido, o `manifest.json` é escrito com o SHA-256, o tamanho e a base de dados de cada ficheiro.

**No restauro:**

1. O `manifest.json` da mesma pasta é lido;
2. O SHA-256 do ficheiro é recalculado com `hash_file()`;
3. A comparação usa `hash_equals()`, que é constante no tempo;
4. Uma divergência aborta o restauro com uma mensagem que identifica o ficheiro como corrompido ou alterado.

O resultado é uma garantia simples e verificável: **qualquer ficheiro que a biblioteca tenha produzido e que passe a verificação de integridade é um dump completo e inalterado**. Esta propriedade é verificável, e é o que distingue um backup de uma cópia de ficheiro.

---

## 10. Segurança

A biblioteca trata a segurança de dados de produção como um requisito, não como um extra.

- **Credenciais nunca na linha de comandos.** Ficheiro temporário `--defaults-extra-file` com `chmod 0600`, valores escapados para o parser de opções do MySQL, eliminação garantida em `finally` e no destructor.
- **Sem shell.** Argumentos como array, sem interpretação de metacaracteres, sem superfície de injecção de comandos.
- **Confirmação em operações destrutivas.** Todos os comandos destrutivos confirmam em produção; `drop-all-databases` confirma sempre e é cancelado sem terminal interactivo e sem `--force`.
- **Âmbito limitado nas operações destrutivas.** `drop-database` só aceita bases de dados presentes em `config/database.php`.
- **Identificadores e strings Citados.** Nomes de bases de dados e valores de configuração são escapados com `` ` `` e `'`, com duplicação de caracteres de escape.
- **Timeout configurável.** `timeout` limita cada processo, evitando que um dump pendurado bloqueie o scheduler indefinidamente.
- **Ficheiros temporários de restauro isolados.** A extração de ZIP usa um directório em `sys_get_temp_dir()` com sufixo aleatório, e apenas as entradas estritamente necessárias são extraídas, removidas no final.
- **Falha de notificação não é falha de backup.** Erros de Slack ou email são registados em log, nunca propagados, para que um webhook em baixo não invada a integridade do backup.

---

## 11. Cópia remota e retenção

Uma cópia no mesmo servidor não é um plano de recuperação. A configuração típica de produção:

```php
// config/angelohd-backup.php
'disks' => ['s3'],

'compression' => [
    'enabled' => true,   // gzip por omissão em produção
    'zip'     => true,   // um ficheiro por execução, mais fácil de gerir
],

'prune_older_than_days' => 7,
```

Isto produz backups diários comprimidos localmente, replicados para S3, e limpos automaticamente ao fim de 7 dias. Para retenção de longo prazo a custo baixo, combine com o **S3 Glacier Instant Retrieval** ou com uma regra de ciclo de vida do próprio bucket, e mantenha a retenção curta apenas no servidor.

---

## 12. Agendamento automático

Laravel 11+ (`routes/console.php`):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('angelohd:backup-database --gzip --zip --disk=s3')
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command('angelohd:prune-backups --older-than=7 --force')
    ->dailyAt('04:00');
```

Laravel anterior (`app/Console/Kernel.php`):

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('angelohd:backup-database --gzip --zip --disk=s3')
        ->dailyAt('03:00')
        ->withoutOverlapping();

    $schedule->command('angelohd:prune-backups --older-than=7 --force')
        ->dailyAt('04:00');
}
```

`withoutOverlapping()` é uma boa prática para backups de bases de dados grandes: se uma execução exceder o intervalo, o scheduler não arranca uma segunda em paralelo, o que causaria contenção de I/O e dumps concorrentes no mesmo directório.

Escolher a hora fora do pico de tráfego reduz o impacto do `--single-transaction` e da compressão sobre a latência da aplicação. Uma migração de 3 GB de dados, por exemplo, consome I/O de disco de forma significativa durante vários minutos.

**No servidor, não esqueça de correr o scheduler:**

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

---

## 13. Uso a partir do código

O `BackupManager` é independente do Artisan e pode ser usado em jobs, controllers, comandos ou testes.

```php
use angelohd\Backup\BackupManager;

$report = app(BackupManager::class)->run(
    gzip: true,
    zip: false,
    disks: ['s3'],
    progress: fn (string $level, string $message) => Log::info($message),
);

if (! $report->successful()) {
    Log::critical('Backup falhado.', [
        'erros' => $report->errors,
        'erros_envio' => $report->uploadErrors,
    ]);
}
```

Estrutura de `BackupReport`:

| Propriedade | Tipo | Descrição |
|---|---|---|
| `$timestamp` | `string` | Carimbo temporal (`AAAA-mm-dd_HH-ii-ss`) |
| `$files` | `array` | Dumps criados, indexados por nome de ficheiro |
| `$errors` | `array` | Erros indexados por conexão (`*` para erros gerais) |
| `$uploadErrors` | `array` | Erros de envio indexados por disk |
| `$uploadedTo` | `array` | Disks para onde o envio foi bem concluído |
| `$directory` | `?string` | Pasta do backup (`null` se foi convertido em ZIP) |
| `$zipPath` | `?string` | Caminho do `.zip`, se existir |
| `location()` | `?string` | Caminho final, seja ZIP ou pasta |
| `successful()` | `bool` | `true` apenas se houve ficheiros e zero erros |

**Exemplo: um job que cria o backup antes de uma migração de risco**

```php
use angelohd\Backup\BackupManager;
use angelohd\Backup\Events\BackupFailed;

class RunRiskyMigration implements ShouldQueue
{
    public function handle(): void
    {
        $report = app(BackupManager::class)->run(gzip: true);

        if (! $report->successful()) {
            Log::critical('Migração cancelada: não foi possível criar o backup.', [
                'erros' => $report->errors,
            ]);

            return;
        }

        Artisan::call('migrate', ['--force' => true]);
    }
}
```

**Exemplo: listener próprio para integridade com um sistema externo**

```php
use angelohd\Backup\Events\BackupSucceeded;
use Illuminate\Support\Facades\Http;

Event::listen(BackupSucceeded::class, function (BackupSucceeded $event) {
    Http::post('https://monitoramento.interno/api/backups', [
        'id' => $event->report->timestamp,
        'ficheiros' => array_keys($event->report->files),
        'destinos' => $event->report->uploadedTo,
    ]);
});
```

---

## 14. Eventos e notificações

**Eventos**

| Evento | Quando é disparado | Propriedade |
|---|---|---|
| `angelohd\Backup\Events\BackupSucceeded` | Pelo menos um dump criado e nenhum erro | `$report` |
| `angelohd\Backup\Events\BackupFailed` | Qualquer erro de dump ou de envio | `$report` |

Ambos são disparados sempre, com sucesso ou falha — o que permite usar o mesmo mecanismo de alerta para ambos os casos.

**Notificações nativas**

```php
// config/angelohd-backup.php
'notifications' => [
    'enabled' => env('BACKUP_NOTIFICATIONS', false),
    'notify_on' => ['failure', 'success'],
    'slack_webhook_url' => env('BACKUP_SLACK_WEBHOOK_URL'),
    'mail_to' => env('BACKUP_MAIL_TO'),
],
```

O corpo da notificação inclui a marca temporal, o caminho local, uma linha por base de dados com o tamanho do dump, os erros por conexão, os erros de envio e os disks confirmados. É informação suficiente para diagnosticar a maioria das falhas sem acesso ao servidor.

Configurar `notify_on` com `['failure']` evita o ruído de um e-mail por dia em que tudo correu bem — que é, statisticamente, a melhor forma de fazer com que alguém deixe de ler as notificações.

---

## 15. Boas práticas

**Definir a frequência a partir do RPO, não do hábito.** Se o negócio tolera perder uma hora de vendas, um backup diário é insuficiente. Aumente a frequência ou implemente réplicas como camada adicional.

**Sempre cópia remota.** A regra 3-2-1 não é negociável. Um backup no mesmo servidor do disco que avariou não é um backup.

**Testar o restauro.** Agende um `restore-database` mensal para uma base de dados descartável, e meça quanto tempo demora. Esse número é o seu RTO real, não o que está no documento de continuidade de negócio.

**Alertas que chegam a alguém.** Um backup que falha às 03:00 e ninguém vê até às 09:00 é um backup que falhou. Configure o Slack ou email e teste o webhook.

**Nunca usar `--force` em produção por preguiça.** `--force` existe para o scheduler e para ambientes descartáveis. Numa sessão interactiva de produção, a confirmação é a sua última barreira contra um erro.

**Fazer `prune` automaticamente.** Sem limpeza, o disco enche e o backup passa a falhar — que é a forma mais irónica de perder a protecção.

**Versionar o binário do MySQL.** Um `mysqldump` mais novo que o servidor é normalmente compatível; o inverso pode gerar SQL que o servidor não compreende. Em contentores, fixe a versão.

**Considerar `--single-transaction` e tabelas MyISAM.** A opção garante consistência em InnoDB. Se existirem tabelas MyISAM, a exportação é bloqueada durante o dump. Migre-as para InnoDB.

---

## 16. Quando usar esta biblioteca e quando não usar

**Use quando**

- A aplicação usa MySQL ou MariaDB e quer backups sem Glue;
- Precisa de um plano de retenção simples e verificável;
- Quer backups que funcionem igualmente em Linux, Windows e CI;
- Precisa de restaurar dumps de produção em ambientes locais, com a remoção automática de `DEFINER`;
- Prefere ter o código do processo de backup dentro do seu próprio repositório, auditável e versionado;
- Equipa uma equipa pequena que não quer operar uma plataforma de terceiros.

**Considere alternativas quando**

- Precisa de **PostgreSQL** ou **SQLite** — esta biblioteca trabalha apenas com `mysqldump`/`mysql`;
- Precisa de **backup incremental** ou de apenas as tabelas alteradas;
- Precisa de **point-in-time recovery** (recuperação para um instante exacto no tempo) — isso exige *binary logs*, que esta biblioteca não gere;
- Precisa de uma **interface web** para o utilizador final;
- Precisa de **retenção e deduplicação avançadas** — aqui uma ferramenta dedicada como `borg`, `restic` ou `duplicity` é mais eficiente;
- A base de dados é tão grande que o dump completo já não cabe na janela de manutenção.

Nestes casos, a combinação `mysqldump` + retenção via *snapshot* do volume ou *binary logs* continua a ser a base da solução — apenas a camada de gestão é diferente.

---

## 17. Comparação com as alternativas habituais

| Critério | `angelohd/laravel-backup` | `spatie/laravel-backup` | Dump manual por cron | Serviço SaaS |
|---|---|---|---|---|
| Custo | Gratuito (MIT) | Gratuito / pago | Gratuito | Mensal |
| Verificação de integridade no restauro | SHA-256 automático | Manifest e zip próprio | Nenhuma | Variável |
| Confirmação em operações destrutivas | Sim | Parcial | N/A | N/A |
| Credenciais fora da linha de comandos | Ficheiro `0600` | Variável | Frequentemente não | N/A |
| Cópia remota | Qualquer disk do Laravel | Amplo | Depende do script | Incluída |
| Notificações | Slack e email | Incluídas | Não | Incluídas |
| Funciona em Windows | Sim (gzip em PHP) | Parcialmente | Não | N/A |
| Suporte a múltiplos motores | Apenas MySQL/MariaDB | Múltiplos | Não | Múltiplos |
| Curva de adopção | Baixa | Média | Nenhuma | Nenhuma |

A escolha não é necessariamente exclusiva. Muitos projetos usam esta biblioteca para dumps rápidos e verificáveis em ambiente local e CI, e mantêm uma solução de snapshots do volume como segunda camada.

---

## 18. Desenvolvimento e qualidade

O projecto é analysed com ferramentas de nível profissional e a CI valida uma matriz de versões.

```bash
composer test      # PHPUnit (unit, feature e integração com MySQL)
composer analyse   # Larastan, análise estática de nível estrito
composer format    # Laravel Pint
```

**Cobertura de testes**

| Tipo | Âmbito |
|---|---|
| Unit | Formatação de datas, ficheiro de credenciais, filtro de `DEFINER` |
| Feature | Comandos Artisan, repositório de backups, listagem e limpeza |
| Integração | Backup e restauro reais contra uma instância MySQL |

A integração com MySQL real é o ponto que mais valor tem num pacote desta natureza: a maioria dos bugs de backup só se manifestam contra um servidor real — locking, caracteres especiais, `DEFINER` de outro utilizador, rotinas e triggers.

**CI:** GitHub Actions com PHP 8.2–8.4 × Laravel 11–13.

---

## 19. Resolução de problemas

**`mysqldump nao encontrado`**

O pacote procura o binário no `PATH` e depois em `angelohd-backup.binaries`. Instale o cliente MySQL ou configure o caminho absoluto:

```dotenv
BACKUP_MYSQLDUMP_PATH=/usr/local/mysql/bin/mysqldump
BACKUP_MYSQL_PATH=/usr/local/mysql/bin/mysql
```

**`Checksum invalido: o ficheiro esta corrompido ou foi alterado`**

O SHA-256 do ficheiro não corresponde ao do `manifest.json`. O ficheiro foi alterado, truncado ou transferido de forma não íntegra. Reutilize outra cópia, ou, se o caso for conhecido e legítimo, use `--skip-verify`.

**`Conexao [x] usa o driver [y], que nao e suportado`**

A biblioteca processa apenas drivers `mysql` e `mariadb`. Ajuste `supported_drivers` se necessário, ou aponte a conexão para o driver correcto.

**`O dump da base de dados [x] esta incompleto`**

O `mysqldump` terminou sem escrever a assinatura final. Causas habituais: espaço em disco esgotado, timeout, ou Permissão insuficiente na pasta de destino. A mensagem original do MySQL é apresentada acima desta.

**`ext-zip nao esta disponivel`**

A compressão ZIP é opcional. Active a extensão `ext-zip` do PHP ou use apenas `--gzip`; a pasta é mantida em qualquer dos casos.

**O restauro falha com `Access denied` em views ou triggers**

Verifique se `restore.strip_definers` está `true`. Se o problema persistir, o dump pode ter sido gerado com um método que não inclui as cláusulas de forma reconhecível — nesse caso, restaure manualmente o `DEFINER`.

**Timeout em bases de dados grandes**

Aumente `mysqldump.max_allowed_packet` e `net_buffer_length` na configuração, e defina `timeout` para um valor superior ao tempo típico de exportação.

---

## 20. Licença e autor

**MIT License.** Uso livre, incluindo em projectos comerciais.

**Angelo N. Mwadiavita** — [GitHub](https://github.com/angelohd) · [LinkedIn](https://www.linkedin.com/in/angelo-mwadiavita-446843183/) · `amwadiavita@ndaysystem.com`

Desenvolvido para a comunidade Laravel em Angola e na diáspora, com foco em fazer correctamente as coisas que costumam ser feitas apressadamente.

---

### Resumo final

A maioria das bibliotecas de backup responde a "como gero o dump?". `angelohd/laravel-backup` responde também a **"como garanto que este ficheiro vai restaurar-se quando eu precisar dele?"** — com checksum SHA-256 gravado no momento da criação e verificado no momento do restauro, com validação da assinatura de conclusão do `mysqldump`, com ficheiros parciais nunca a sobreviver no disco, e com credenciais que nunca tocam a linha de comandos.

É essa a diferença entre fazer backups e ter backups.
