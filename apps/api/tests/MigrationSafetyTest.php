<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class MigrationSafetyTest extends TestCase
{
    public function testEntitlementMigrationEnforcesUniquenessOnlyForActiveRows(): void
    {
        $path = dirname(__DIR__) . '/database/migrations/20260828000001_fix_course_entitlement_active_uniqueness.php';
        self::assertFileExists($path);

        $source = (string) file_get_contents($path);
        self::assertStringContainsString('GENERATED ALWAYS AS', strtoupper($source));
        self::assertStringContainsString('status', $source);
        self::assertStringContainsString('uq_course_entitlements_active', $source);
        self::assertStringContainsString('DROP INDEX', strtoupper($source));
        self::assertStringContainsString('multiple revoked rows', $source);
        self::assertStringContainsString('NULL learner/course/source/status rows', $source);
        self::assertStringContainsString('active_marker', $source);
    }

    public function testMigrationCapturesPreflightBackupBeforeSchemaMutation(): void
    {
        $source = $this->readProjectFile('ops/backup/migrate.sh');
        $status = strpos($source, 'pre-migration-status.txt');
        $backup = strpos($source, '$SCRIPT_DIR/backup.sh');
        $migrate = strpos($source, 'phinx migrate');
        $failureLog = strpos($source, 'LOG_FILE.migration');

        self::assertIsInt($status);
        self::assertIsInt($backup);
        self::assertIsInt($migrate);
        self::assertIsInt($failureLog);
        self::assertLessThan($migrate, $backup);
        self::assertStringContainsString('PIPESTATUS[0]', $source);
        self::assertStringContainsString('no automatic retry was attempted', $source);
        self::assertLessThan($failureLog, $migrate);
    }

    public function testBackupManifestCoversDatabaseAndUploadsArtifacts(): void
    {
        $source = $this->readProjectFile('ops/backup/manifest.sh');

        foreach (['mysql.sql', 'uploads.tar.gz', 'phinx-status.txt', 'migration-version.txt', 'image-references.txt'] as $artifact) {
            self::assertStringContainsString($artifact, $source);
        }
        self::assertStringContainsString('SHA256SUMS', $this->readProjectFile('ops/backup/backup.sh'));
        self::assertStringContainsString('sha256', $source);
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
