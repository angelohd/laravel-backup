# Artigo — angelohd/laravel-backup

> **Nota editorial:** este ficheiro contém o artigo em formato de publicação. No final estão as opções de título, a descrição para SEO e as tags sugeridas para o Medium, Dev.to ou Hashnode.

---

# O backup que ninguém restaurou

**Como construí uma biblioteca Laravel que faz backups de MySQL — e que se recusa a dizer que um backup foi feito quando não foi**

---

Era uma terça-feira, quase três da manhã. O alerta entrou no Slack: o servidor MySQL de um cliente tinha morrido. O provider de infraestrutura já tinha confirmado: *sem reposição, os dados dos últimos seis dias estão perdidos.*

Se existedisse um backup. Existia, e tinha sido gerado todas as noites durante dois anos, sem uma única falha. Automático, confiável, sem intervenção humana.

Demorou seis horas a descobrir que **nenhum desses backups tinha sido verificado depois do primeiro dia**. Três deles estavam truncados ao meio. O ficheiro existia, o nome estava correcto, o tamanho era plausível. O cron devolvia código de saída zero. Nada indicava problema.

Os outros cento e setenta e cinco estavam intactos. A empresa perdeu a noite, não a empresa.

Aprendi com aquilo uma coisa que, desde então, repito a toda a gente que discute backups comigo: **um backup não é um ficheiro que existe. É um ficheiro que se sabe restaurar.** A diferença entre as duas frases é, muitas vezes, a diferença entre um susto e um desastre.

Foi essa aprendizagem que me fez escrever o `angelohd/laravel-backup`.

---

## O que estava errado com as alternativas

Quando comecei a procurar uma solução para o meu próprio fluxo de trabalho, encontrei três caminhos. Nenhum servia.

**O script de cron que toda a gente escreve.** Quinze linhas de bash, um `mysqldump` com a redirect para um ficheiro, e pronto. Funciona durante meses. Até que o disco enche a meio da exportação, ou o processo é morto por timeout, ou o `gzip` falha — e fica lá um ficheiro pela metade que toda a gente interpretará como um backup válido. Já vi isto acontecer em produção, mais do que uma vez.

**O plugin maior da comunidade.** Excelente, com imensas funcionalidades, e exactamente por isso mesmo: a sua configuração exige mais atenção do que devia. Cada opção extra é uma opção que pode estar errada em silêncio.

**O serviço SaaS.**resolver o problema técnico e criar um problema contractual. Os dados dos clientes saem do meu servidor para a infraestrutura de um terceiro. Em muitos contextos isso é uma decisão que não me compete tomar sozinho.

Faltava uma coisa. Uma biblioteca pequena, com Defaults que funcionam, que **falha alto** — ou seja, que prefere dizer "o backup não foi feito" a dizer "o backup foi feito" quando não foi. E que não hide nada da equipa que a usa.

---

## A regra que segui

Escrevi a biblioteca em torno de três perguntas. Um backup só é um backup se responder a todas:

**Foi feito?** O processo terminou com código de saída zero, sem erros no `stderr`?

**Está completo?** O ficheiro existe, não tem tamanho zero, e termina com a assinatura que o MySQL só escreve quando a exportação acabou de facto?

**Consegue ser restaurado?** O conteúdo corresponde ao que foi registado no momento da criação, e o comando de restauro recusa-se a correr sobre um ficheiro que foi alterado ou corrompido?

Se a resposta a qualquer uma destas é não, o backup não existe. O comando sai com código de erro, apaga o ficheiro parcial e avisa. Não há ambiguidade para ninguém interpretar mal às três da manhã.

---

## O que a biblioteca faz

O `angelohd/laravel-backup` é um pacote Composer que se instala num projecto Laravel e regista comandos Artisan com o prefixo `angelohd:`. Não pede base de dados de catálogo, não tem painel web, não pede chave de API. Lê a configuração que já existe — `config/database.php` — e fala com o `mysqldump` e o `mysql` que já estão instalados na máquina.

```bash
composer require angelohd/laravel-backup
```

Isto é tudo. A primeira cópia:

```bash
php artisan angelohd:backup-database --gzip
```

E o que fica no disco:

```
storage/app/backups-databases/2026-01-15_03-00-00/
├── mysql.sql.gz
├── mysql_reports.sql.gz
└── manifest.json
```

O `manifest.json` é o coração da coisa:

```json
{
    "created_at": "2026-01-15T03:00:04+00:00",
    "app": "Minha aplicacao",
    "files": {
        "mysql.sql.gz": {
            "connection": "mysql",
            "database": "minha_bd",
            "size": 18453221,
            "sha256": "9f2c1d84...a71b"
        }
    }
}
```

Guarda o SHA-256 de cada ficheiro. Quando se restaura, o checksum é recalculado e comparado. Se não bater certo, o restauro **não arranca** — a base de dados de destino nem é tocada. É a diferença entre descobrir que um backup está partido e dar com isso no pior momento possível.

---

## Cinco decisões que fiz e que valem a pena explicar

### 1. As credenciais nunca passam na linha de comandos

Isto parece um detalhe. Não é.

Quando se escreve `mysqldump -u utilizador -p'senha'`, essa senha fica visível. Em Unix, qualquer utilizador da máquina lê os argumentos de um processo em execução. Em Windows, idem, através de qualquer ferramenta de administração. Em painéis de alojamento partilhada, o comando acaba gravado num log que alguém vai ler um dia. E em sistemas de integração contínua, os argumentos aparecem no histórico de execução.

A biblioteca escreve as credenciais num ficheiro temporário de opções — `--defaults-extra-file` — com permissões `0600`, e apaga-o imediatamente a seguir, incluindo quando algo corre mal pelo caminho. Os valores são escapados correctamente para o parser do MySQL, incluindo passwords com `#`, `;`, aspas, espaços e barras invertidas.

Detalhe? Talvez. Mas é o tipo de detalhe que evita um incidente de segurança que nunca mais se apaga.

### 2. Nada passa por uma shell

Todos os processos são executados com o componente `Process` do Laravel, recebendo os argumentos como array em vez de string. Não há concatenação de comandos nem interpretação de metacaracteres, e o código de saída que verificamos é o do `mysqldump` — não o do `gzip`.

Isto não é académicas. Na primeira versão da biblioteca, um `mysqldump` que falhava com `--gzip` activo era reportado como **sucesso**, porque o código de saída que eu estava a ler era o do `gzip`, não o do dump. O ficheiro estava vazio e o sistema dizia que estava tudo bem. Foi, provavelmente, o bug mais importante que corrigi em 2.0.

### 3. A compressão é feita em PHP

Não há dependência do executável `gzip`. A compressão é feita com `gzopen`/`gzwrite`, em blocos de um megabyte.

A razão prática: `gzip` não existe nativamente no Windows. Com a compressão em PHP, o mesmo comando funciona em servidor Linux, contentor, pipeline de CI e máquina de desenvolvimento Windows — sem configuração condicional, sem scripts differentes para cada sistema operativo.

### 4. Um dump de produção restaura-se numa máquina local

Isto consumiu-me durante semanas antes de o resolver.

Um dump de MySQL inclui cláusulas `DEFINER=`utilizador`@`host`` em views, triggers e stored procedures. Quando tentas restaurar esse dump com um utilizador diferente daquele que criou os objectos, o MySQL recusa-se com `Access denied` — mesmo que a base de dados esteja perfeitamente íntegra. O dump está correcto. O problema é que o ficheiro carrega o nome de um utilizador que não existe no servidor de destino.

A biblioteca filtra essas cláusulas durante o restauro, em streaming. O resultado é que **um dump tirado de produção restaura-se numa máquina de desenvolvimento sem qualquer edição manual**. Foi uma das funcionalidades de que mais me orgulho, porque resolve um problema que toda a gente que trabalha com dados reais conhece — e quase ninguém resolve.

### 5. Operações destrutivas têm travões

Existem comandos para apagar uma base de dados, todas as tabelas, ou todas as bases de dados. São úteis em máquinas descartáveis e catastrophicos em produção.

Todos os comandos destrutivos pedem confirmação em produção. O `drop-all-databases` pede confirmação em **qualquer** ambiente, e sem terminal interactivo e sem `--force` a operação é cancelada. O `drop-database` só aceita bases de dados que estejam declaradas numa conexão do `config/database.php` — o que impede que um nome escrito apressadamente seja executado contra um servidor onde não estava previsto.

---

## O resto da caixa de ferramentas

**Compressão e ZIP.** `--gzip` comprime cada dump; `--zip` agrupa tudo num único ficheiro. Se a extensão `ext-zip` não estiver disponível, o comando avisa e mantém a pasta. Nunca perde trabalho.

**Cópia remota.** Um backup no mesmo servidor do disco que avariou não é um plano de recuperação. Configurei o upload para os disks que o Laravel já resolve — S3, B2, FTP, SFTP — e o `prune-backups` limpa também esses disks, para o custo de armazenamento não crescer sem controlo.

**Restauração simples ou automática.** Restaurar um ficheiro concreto, ou o backup mais recente com `--latest` — extraindo-o do ZIP se for preciso. Com `--create`, cria a base de dados se ainda não existir.

**Alertas que chegam a alguém.** Eventos `BackupSucceeded` e `BackupFailed` e um listener incluído que envia Slack e email. E um detalhe importante: uma falha na notificação nunca interrompe o backup. Erro de webhook vai para o log, não para a exceptions stack.

**Automação de limpeza.** `angelohd:prune-backups --older-than=7 --force`, com `--dry-run` para ver o que seria apagado antes de apagar.

**Informação da base de dados.** `angelohd:database-info` mostra tabelas, volume de registos e tamanho.

---

## Automatização

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('angelohd:backup-database --gzip --zip --disk=s3')
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command('angelohd:prune-backups --older-than=7 --force')
    ->dailyAt('04:00');
```

`withoutOverlapping()` não é decoração. Se uma exportação de uma base de dados grande exceder o intervalo, o scheduler não arranca uma segunda em paralelo — o que causaria contenção de I/O e ficheiros a disputarem o mesmo directório.

E a hora conta. Um dump de alguns gigabytes, comprimido, consome I/O de disco durante vários minutos. Faze-lo às três da manhã em vez de ao meio-dia é a diferença entre uma operação invisível e uma que os utilizadores notam.

Não se esqueça de correr o scheduler no servidor:

```cron
* * * * * cd /path/para/app && php artisan schedule:run >> /dev/null 2>&1
```

---

## Usar a partir do código

O `BackupManager` não depende do Artisan. Pode correr num job, num controller ou num teste.

```php
use angelohd\Backup\BackupManager;

$report = app(BackupManager::class)->run(gzip: true, disks: ['s3']);

if (! $report->successful()) {
    Log::critical('Backup falhado.', [
        'erros' => $report->errors,
        'erros_envio' => $report->uploadErrors,
    ]);
}
```

Isto permite uma coisa que me parece cada vez mais importante: **fazer backup automaticamente antes de uma operação de risco**, como uma migração de esquema.

```php
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
```

Se não há cópia de segurança, a migração não corre. Não há discussão, não há confiança, não há decisão a tomar sob pressão.

---

## Quando não usar esta biblioteca

Um artigo honesto tem de dizer isto.

**Se a sua base de dados é PostgreSQL ou SQLite.** Isto trabalha com `mysqldump` e `mysql`. Para os outros motores, a sua plataforma habitual.

**Se precisa de point-in-time recovery.** Ou seja, restaurar a base de dados tal como estava exactamente às 14h32 de uma terça-feira. Isso exige binary logs, e esta biblioteca não os gere. Se o seu RPO é de minutos, precisa de réplicas e binary logs — o dump é apenas a última linha.

**Se precisa de backups incrementais.** A biblioteca faz dumps completos. Para bases de dados muito grandes, isso pode ser caro em tempo e espaço; `borg`, `restic` ou `duplicity` são mais eficientes.

**Se precisa de uma interface web** para o utilizador final, ou de retenção e deduplicação avançadas.

Nesses casos, a mesma lógica de fundo aplica-se — copie os dados para fora, verifique a cópia, teste a recuperação — apenas a camada de gestão é diferente.

---

## O que aprendi a construir isto

Escrevi a primeira versão em Laravel 10, com um `scripts/backup.sh` ao lado. A versão 2.0 é, na prática, outra biblioteca.

A maior parte das decisões técnicas que tomei veio de ler o `CHANGELOG` de ferramentas que uso e de perguntar: *"isto está resolvido, e como?"* Um backup que falhava silenciosamente com `--gzip`. Um `drop-all-tables` que não lidava com chaves estrangeiras. Passwords com `#` que partiam o ficheiro de credenciais. Um `list-backups` que ordenava por data escrita como texto, por dia primeiro — e portanto com December antes de Janeiro.

Nenhum desses bugs é difícil de corrigir quando aparece. Todos são faciles de evitar **só se alguém pensou neles antes**, e foi exactamente esse o trabalho: passar horas a pensar neles para que ninguém mais tenha de as descobrir às três da manhã.

A biblioteca tem testes unitários, testes de comandos e testes de integração contra uma instância MySQL real — porque a maioria dos bugs de backup só se manifesta contra um servidor verdadeiro, com locking, caracteres especiais, views e rotinas. Análise estática com Larastan, formatação com Pint, e CI a correr numa matriz de PHP 8.2 a 8.4 com Laravel 11, 12 e 13.

---

## A única coisa que interessa

A palavra mais importante numa estratégia de backup não é "backup". É **verificar**.

Um ficheiro que ninguém restaurou é uma hipótese. Um ficheiro cujo checksum foi conferido no momento da criação e validado no momento da reexecução é, isso sim, uma garantia — porque a garantia é verificável e o resultado está à vista.

É isso que o `angelohd/laravel-backup` tenta fazer. Não é o pacote mais completo que existe. É, espero que, o mais difícil de usar mal.

```bash
composer require angelohd/laravel-backup
```

MIT.-documentação completa no repositório.

---

### Opções de título

1. O backup que ninguém restaurou
2. Construí uma biblioteca Laravel que se recusa a dizer que um backup foi feito quando não foi
3. Backups de MySQL em Laravel: a parte que ninguém implementa — a verificação
4. 175 backups, 3 corrompidos: a história que me fez escrever uma biblioteca

### Descrição para SEO (até 160 caracteres)

> Biblioteca Laravel para backup e restauro de MySQL/MariaDB com checksum SHA-256, credenciais protegidas e garantia de que um backup falhado nunca fica no disco.

### Tags sugeridas

`php` `laravel` `mysql` `mariadb` `backup` `devops` `databases` `security` `packagist` `opensource`

### Chamadas à acção sugeridas

- Deixar o primeiro `composer require` como comentário solto no início, para quem está a ler com pressa.
- Fechar com um convite a testar o restauro — "não acredite no seu backup até o ter restaurado uma vez" — e pedir comentários de quem já usa a biblioteca.
- Ligar ao repositório e ao Packagist no final, com a nota de que é MIT e que issues e PRs são bem-vindos.
