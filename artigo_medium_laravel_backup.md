# Backups no Laravel sem Complicação: Segurança, Compressão e Restauro Inteligente

![Laravel Backup](https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg)

A perda de dados é um dos piores pesadelos que qualquer equipa de desenvolvimento ou engenharia de software pode enfrentar. Uma migração que correu mal, um erro num script em produção ou uma falha de infraestrutura podem deitar a perder meses de trabalho se não houver um plano de contingência fiável.

Embora existam soluções no ecossistema, muitas exigem configurações complexas ou falham em detalhes cruciais — como a exposição acidental de credenciais nos logs de processos ou erros de permissão ao restaurar dumps de produção em ambiente local.

Foi para resolver esses e outros problemas que criei o pacote **`angelohd/laravel-backup`**.

Neste artigo, vou apresentar como esta biblioteca para Laravel (11.x, 12.x e 13.x) simplifica o backup, restauro e gestão de bases de dados MySQL e MariaDB com foco total em **segurança**, **integridade de dados** e **automação**.

---

## 💡 Por que usar o `angelohd/laravel-backup`?

O pacote foi desenhado para ir além de um simples `mysqldump`. Ele resolve dores reais de quem lida com infraestrutura e desenvolvimento diário:

* 🔒 **Segurança Absoluta de Credenciais:** As passwords da base de dados **nunca** são expostas na linha de comandos (evitando que fiquem visíveis em comandos como `ps aux`). A autenticação é feita via ficheiros temporários com permissão estrita `0600`, eliminados imediatamente após a execução.
* 🛡️ **Verificação com Checksum SHA-256:** Cada cópia de segurança gera um `manifest.json`. Se o dump estiver incompleto ou corrompido, a operação falha, o ficheiro parcial é removido do disco e o restauro é bloqueado.
* 📦 **Compressão Nativa (GZip & ZIP):** A compressão GZip é realizada diretamente via PHP. Isto significa que funciona perfeitamente em qualquer SO (Linux, macOS e Windows) sem depender de ferramentas extras instaladas no sistema.
* 🔄 **Restauro Inteligente sem Conflitos (`DEFINER` Removal):** Quem nunca tentou restaurar um dump de produção no ambiente local (`localhost`) e recebeu erros de permissão por causa de cláusulas `DEFINER=` em Views, Triggers ou Stored Procedures? Por omissão, o pacote remove estas cláusulas durante o restauro.
* ☁️ **Sincronização com a Nuvem:** Envie os seus backups diretamente para qualquer *disk* do Laravel (Amazon S3, DigitalOcean Spaces, FTP, SFTP).
* 🔔 **Notificações em Tempo Real:** Alertas nativos via Slack e Email, além de eventos disparados (`BackupSucceeded` e `BackupFailed`).

---

## 🚀 Instalação e Configuração em 1 Minuto

A biblioteca exige **PHP 8.2+** e **Laravel 11+**. Como utiliza o *Auto-Discovery* do Laravel, a instalação é direta:

```bash
composer require angelohd/laravel-backup
```

Em seguida, publique o ficheiro de configuração:

```bash
php artisan vendor:publish --tag=angelohd-backup-config
```

Isto irá criar o ficheiro `config/angelohd-backup.php`, onde pode personalizar retenção, notificações, conexões e destinos remotos.

---

## 🛠️ Principais Comandos no Dia a Dia

A biblioteca adiciona uma suíte intuitiva de comandos `php artisan`:

### 1. Criar um Backup Completo

Pode fazer um backup simples ou combinar opções de compressão e envio para a nuvem:

```bash
# Backup simples de todas as conexões
php artisan angelohd:backup-database

# Backup com compressão GZip e compactação ZIP
php artisan angelohd:backup-database --gzip --zip

# Enviar o backup diretamente para o Amazon S3
php artisan angelohd:backup-database --disk=s3
```

> **Nota:** Se algum erro ocorrer durante a geração do dump, o pacote limpa automaticamente os ficheiros residuais para garantir que nunca fiquem backups parciais no seu servidor.

### 2. Restaurar um Backup com Facilidade

Restaurar a base de dados mais recente (criando-a se não existir) requer apenas um comando:

```bash
php artisan angelohd:restore-database --latest --connection=mysql --database=minha_bd --create
```

Se preferir restaurar um ficheiro específico:

```bash
php artisan angelohd:restore-database storage/app/backups-databases/2026-01-01_12-00-00/mysql.sql.gz --connection=mysql --database=minha_bd
```

### 3. Limpeza Automática de Backups Antigos (Pruning)

Evite que o disco fique cheio definindo uma política de retenção:

```bash
# Apagar backups locais e remotos com mais de 30 dias
php artisan angelohd:prune-backups --older-than=30

# Fazer uma simulação sem apagar nada (Dry Run)
php artisan angelohd:prune-backups --older-than=7 --dry-run
```

---

## ⏰ Automação Total com o Laravel Scheduler

Para garantir que a sua aplicação faça backups diariamente sem intervenção humana, adicione as tarefas ao agendador do Laravel em `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

// Backup diário às 03:00 da manhã com compressão
Schedule::command('angelohd:backup-database --gzip')->dailyAt('03:00');

// Limpeza diária às 04:00 para manter apenas os últimos 30 dias
Schedule::command('angelohd:prune-backups --force')->dailyAt('04:00');
```

---

## 💻 Uso Programático (Via Código)

Precisa de acionar um backup através de um painel de administração ou num *Job* personalizado? Pode injetar o `BackupManager`:

```php
use angelohd\Backup\BackupManager;

class BackupController extends Controller
{
    public function store(BackupManager $backupManager)
    {
        $report = $backupManager->run(gzip: true, disks: ['s3']);

        if ($report->successful()) {
            return back()->with('success', 'Backup realizado com sucesso!');
        }

        return back()->with('error', 'Falha ao gerar o backup.');
    }
}
```

---

## 🎯 Conclusão

Ter uma estratégia de backup fiável não precisa de ser algo complicado ou cheio de scripts em Bash difíceis de manter. O **`angelohd/laravel-backup`** traz o controlo total para dentro do ecossistema Laravel com facilidade, performance e segurança.

Se gostou do projeto ou ele foi útil para a sua aplicação, considere deixar uma ⭐️ no repositório do GitHub!

👉 **Repositório no GitHub:** [github.com/angelohd/laravel-backup](https://github.com/angelohd/laravel-backup)  
👉 **Pacote no Packagist:** [packagist.org/packages/angelohd/laravel-backup](https://packagist.org/packages/angelohd/laravel-backup)

---

*Fique à vontade para deixar sugestões, dúvidas ou feedback nos comentários!*