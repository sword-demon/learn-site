<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class RestoreRehearsalTest extends TestCase
{
    public function testRestoreUsesAnIsolatedComposeProjectAndCleansItUp(): void
    {
        $source = $this->readProjectFile('ops/backup/restore.sh');

        self::assertStringContainsString('RESTORE_PROJECT', $source);
        self::assertStringContainsString('-p "$RESTORE_PROJECT"', $source);
        self::assertStringContainsString('learn-site-restore-', $source);
        self::assertStringContainsString('down -v --remove-orphans', $source);
        self::assertStringContainsString('RESTORE_STARTED', $source);
    }

    public function testRestoreDoesNotPublishPortsAndCleansPartialStartup(): void
    {
        $source = $this->readProjectFile('ops/backup/restore.sh');
        $overlay = $this->readProjectFile('compose.restore.yaml');

        self::assertStringContainsString('compose.restore.yaml', $source);
        self::assertStringContainsString('ports: !reset []', $overlay);

        $cleanupEnabledAt = strpos($source, 'RESTORE_STARTED=1');
        $composeUpAt = strpos($source, 'run_compose up');
        self::assertIsInt($cleanupEnabledAt);
        self::assertIsInt($composeUpAt);
        self::assertLessThan($composeUpAt, $cleanupEnabledAt);
    }

    public function testRestoreValidatesChecksumsUploadsReferencesHealthAndMigrationState(): void
    {
        $source = $this->readProjectFile('ops/backup/restore.sh');

        foreach (['mysql.sql', 'uploads.tar.gz', 'manifest.json', 'SHA256SUMS'] as $artifact) {
            self::assertStringContainsString($artifact, $source);
        }
        self::assertStringContainsString('storage_path', $source);
        self::assertStringContainsString('/health', $source);
        self::assertStringContainsString('verify-migrations.sh', $source);
    }

    public function testRehearsalWrapperRequiresAnExplicitBackupDirectory(): void
    {
        $source = $this->readProjectFile('ops/backup/rehearse-restore.sh');

        self::assertStringContainsString('BACKUP_DIR must point to a completed backup', $source);
        self::assertStringContainsString('VERIFY_RESTORE=1', $source);
        self::assertStringContainsString('restore.sh', $source);
    }

    private function readProjectFile(string $relativePath): string
    {
        foreach ([dirname(__DIR__, 3), '/workspace'] as $root) {
            $path = $root . '/' . $relativePath;
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        self::fail('project file is unavailable: ' . $relativePath);
    }
}
