<?php

declare(strict_types=1);

namespace angelohd\Backup\Tests\Integration;

use angelohd\Backup\Support\MySqlClient;
use angelohd\Backup\Tests\TestCase;
use Illuminate\Support\Facades\File;
use PDO;
use Throwable;

/**
 * Corre contra um MySQL/MariaDB real. E ignorado se o servidor ou os binarios
 * nao estiverem disponiveis (ver variaveis BACKUP_TEST_DB_* em phpunit.xml.dist).
 */
class MySqlBackupTest extends TestCase
{
    private const DATABASE = 'angelohd_backup_test';

    private const USER = 'angelohd_bk_test';

    /** Password com caracteres que partiam o ficheiro .cnf antigo. */
    private const PASSWORD = 'p#a"s\\s w;o\'rd';

    private static ?PDO $root = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (MySqlClient::missingBinaries('mysql', 'mysqldump') !== []) {
            $this->markTestSkipped('mysql/mysqldump nao encontrados.');
        }

        try {
            self::$root ??= new PDO(
                sprintf('mysql:host=%s;port=%s', env('BACKUP_TEST_DB_HOST'), env('BACKUP_TEST_DB_PORT')),
                env('BACKUP_TEST_DB_USERNAME'),
                env('BACKUP_TEST_DB_PASSWORD') ?: null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (Throwable $e) {
            $this->markTestSkipped('MySQL indisponivel: ' . $e->getMessage());
        }

        $db = self::DATABASE;
        $password = self::$root->quote(self::PASSWORD);

        self::$root->exec("DROP DATABASE IF EXISTS `{$db}`; CREATE DATABASE `{$db}`");
        self::$root->exec("DROP USER IF EXISTS '" . self::USER . "'@'%'");
        self::$root->exec("CREATE USER '" . self::USER . "'@'%' IDENTIFIED BY {$password}");
        self::$root->exec("GRANT ALL PRIVILEGES ON `{$db}`.* TO '" . self::USER . "'@'%'");
        self::$root->exec("GRANT ALL PRIVILEGES ON `{$db}_restored`.* TO '" . self::USER . "'@'%'");
        self::$root->exec(<<<SQL
            USE `{$db}`;
            CREATE TABLE authors (id INT PRIMARY KEY, name VARCHAR(100));
            CREATE TABLE books (id INT PRIMARY KEY, author_id INT, title VARCHAR(100),
                FOREIGN KEY (author_id) REFERENCES authors(id));
            INSERT INTO authors VALUES (1, 'Pepetela'), (2, 'Ondjaki');
            INSERT INTO books VALUES (1, 1, 'Mayombe'), (2, 2, 'Os da Minha Rua'), (3, 1, 'Tab\tulado');
            CREATE VIEW book_titles AS SELECT title FROM books;
            SQL);

        config(['database.connections' => [
            'library' => [
                'driver' => 'mysql',
                'host' => env('BACKUP_TEST_DB_HOST'),
                'port' => env('BACKUP_TEST_DB_PORT'),
                'database' => self::DATABASE,
                'username' => self::USER,
                'password' => self::PASSWORD,
            ],
        ]]);
    }

    protected function tearDown(): void
    {
        if (self::$root !== null) {
            self::$root->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
            self::$root->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '_restored`');
            self::$root->exec("DROP USER IF EXISTS '" . self::USER . "'@'%'");
        }

        parent::tearDown();
    }

    /**
     * A view e criada pelo root, por isso o dump traz DEFINER=root: o restauro
     * pelo utilizador da aplicacao so funciona porque o DEFINER e removido.
     */
    public function test_backup_gzip_zip_then_restore_latest_into_new_database(): void
    {
        $this->artisan('angelohd:backup-database', ['--gzip' => true, '--zip' => true])->assertSuccessful();

        $zips = File::glob($this->backupPath . '/*.zip');
        $this->assertCount(1, $zips);

        $this->artisan('angelohd:restore-database', [
            '--connection' => 'library',
            '--database' => self::DATABASE . '_restored',
            '--latest' => true,
            '--create' => true,
        ])->expectsOutputToContain('Checksum SHA-256 verificado.')->assertSuccessful();

        $titles = self::$root->query('SELECT title FROM `' . self::DATABASE . '_restored`.book_titles ORDER BY title')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['Mayombe', 'Os da Minha Rua', "Tab\tulado"], $titles);
    }

    public function test_restore_rejects_corrupted_backup(): void
    {
        $this->artisan('angelohd:backup-database')->assertSuccessful();

        $dump = File::glob($this->backupPath . '/*/library.sql')[0];
        file_put_contents($dump, "\n-- adulterado", FILE_APPEND);

        $this->artisan('angelohd:restore-database', [
            'file' => $dump,
            '--connection' => 'library',
            '--database' => self::DATABASE,
        ])->expectsOutputToContain('Checksum invalido')->assertFailed();
    }

    public function test_drop_all_tables_with_foreign_keys_and_views(): void
    {
        // Numa app real a view pertence ao utilizador da aplicacao, nao ao root.
        self::$root->exec('CREATE OR REPLACE DEFINER=\'' . self::USER . '\'@\'%\' VIEW `' . self::DATABASE . '`.book_titles AS SELECT title FROM `' . self::DATABASE . '`.books');

        $this->artisan('angelohd:drop-all-tables', ['--connection' => 'library'])->assertSuccessful();

        $count = self::$root->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . self::DATABASE . "'"
        )->fetchColumn();

        $this->assertSame(0, (int) $count);
    }

    public function test_database_info_lists_tables(): void
    {
        $this->artisan('angelohd:database-info', ['--connection' => 'library'])
            ->expectsOutputToContain('Total tabelas: 3')
            ->assertSuccessful();
    }
}
