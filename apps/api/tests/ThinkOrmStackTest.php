<?php
declare(strict_types=1);

namespace Tests;

use App\model\Account;
use App\model\Department;
use App\model\Learner;
use App\model\Permission;
use App\model\Role;
use App\model\StaffUser;
use PHPUnit\Framework\TestCase;
use support\think\Model;

final class ThinkOrmStackTest extends TestCase
{
    public function testComposerRequiresThinkOrmAndNotIlluminate(): void
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);
        $this->assertIsArray($json);
        $require = $json['require'] ?? [];
        $this->assertArrayHasKey('webman/think-orm', $require);
        $this->assertArrayNotHasKey('illuminate/database', $require);
        $lock = dirname(__DIR__) . '/composer.lock';
        $this->assertFileExists($lock);
        $lockBody = (string) file_get_contents($lock);
        $this->assertStringNotContainsString('"name": "illuminate/database"', $lockBody);
        $this->assertStringContainsString('webman/think-orm', $lockBody);
    }

    public function testThinkOrmConfigUsesOfficialKeys(): void
    {
        $config = require dirname(__DIR__) . '/config/think-orm.php';
        $this->assertSame('mysql', $config['default']);
        $mysql = $config['connections']['mysql'];
        $this->assertSame('mysql', $mysql['type']);
        $this->assertSame('utf8mb4', $mysql['charset']);
        $this->assertTrue($mysql['break_reconnect']);
        $this->assertArrayHasKey('pool', $mysql);
        $this->assertArrayHasKey('hostname', $mysql);
        $this->assertArrayHasKey('hostport', $mysql);
    }

    public function testDatabasePhpIsNotAnOrmConfig(): void
    {
        $config = require dirname(__DIR__) . '/config/database.php';
        $this->assertSame([], $config);
        $this->assertArrayNotHasKey('connections', $config);
    }

    /**
     * @dataProvider modelClasses
     */
    public function testModelsExtendThinkOrm(string $class): void
    {
        $this->assertTrue(is_subclass_of($class, Model::class), $class . ' must extend support\\think\\Model');
    }

    public static function modelClasses(): array
    {
        return [
            [Account::class],
            [Learner::class],
            [StaffUser::class],
            [Department::class],
            [Role::class],
            [Permission::class],
        ];
    }

    public function testBusinessPhpDoesNotImportPdo(): void
    {
        $root = dirname(__DIR__) . '/app';
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $src = (string) file_get_contents($path);
            if (preg_match('/^use\s+PDO\s*;/m', $src) || preg_match('/new\s+\\\\PDO\b/', $src)) {
                $hits[] = $path;
            }
        }
        $this->assertSame([], $hits, 'business code must not use PDO as the data access layer');
    }
}
