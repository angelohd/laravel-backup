<?php

declare(strict_types=1);

namespace angelohd\Backup\Tests\Unit;

use angelohd\Backup\Support\DefinerFilter;
use PHPUnit\Framework\TestCase;

class DefinerFilterTest extends TestCase
{
    public function test_strips_definers_from_mysqldump_ddl(): void
    {
        $this->assertSame(
            "/*!50013 SQL SECURITY DEFINER */\n",
            DefinerFilter::strip("/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */\n")
        );
        $this->assertSame(
            '/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `t` BEFORE INSERT ON `x` FOR EACH ROW SET @a = 1 */;;',
            DefinerFilter::strip('/*!50003 CREATE*/ /*!50017 DEFINER=`app`@`%`*/ /*!50003 TRIGGER `t` BEFORE INSERT ON `x` FOR EACH ROW SET @a = 1 */;;')
        );
        $this->assertSame(
            'CREATE PROCEDURE `p`()',
            DefinerFilter::strip("CREATE DEFINER='my`user'@'10.0.0.%' PROCEDURE `p`()")
        );
    }

    public function test_never_touches_data(): void
    {
        $line = "INSERT INTO `notes` VALUES (1,'DEFINER=`root`@`localhost`');\n";

        $this->assertSame($line, DefinerFilter::strip($line));
    }

    public function test_chunks_preserve_content(): void
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, str_repeat("INSERT INTO t VALUES (1);\n", 100) . "/*!50013 DEFINER=`a`@`b` SQL SECURITY DEFINER */\n");
        rewind($stream);

        $output = implode('', iterator_to_array(DefinerFilter::chunks($stream, 256), false));

        $this->assertSame(str_repeat("INSERT INTO t VALUES (1);\n", 100) . "/*!50013 SQL SECURITY DEFINER */\n", $output);
    }
}
