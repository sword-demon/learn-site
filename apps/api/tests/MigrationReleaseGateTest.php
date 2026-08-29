<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class MigrationReleaseGateTest extends TestCase
{
    public function testCriticalImagesAreVersionedAndDigestPinnedInTheGate(): void
    {
        $source = $this->readProjectFile('scripts/verify-images.sh');

        foreach (['php:8.4-cli@sha256:', 'node:22.11.0-alpine@sha256:', 'nginx:1.27.3-alpine@sha256:', 'mysql:8.4.11@sha256:', 'redis:7.4.11@sha256:'] as $image) {
            self::assertStringContainsString($image, $source);
        }
        self::assertStringContainsString('imagetools inspect', $source);
        self::assertStringContainsString('multi-architecture', $source);
        self::assertStringContainsString('latest', $source);
    }

    public function testMigrationGateChecksStatusSchemaAndUsesHealthValidation(): void
    {
        $source = $this->readProjectFile('scripts/verify-migrations.sh');
        $migrate = $this->readProjectFile('ops/backup/migrate.sh');

        self::assertStringContainsString('phinx status', $source);
        self::assertStringContainsString('required_tables', $source);
        self::assertStringContainsString('course_entitlements', $source);
        self::assertStringContainsString('notification_dispatches', $source);
        self::assertStringContainsString('notification_dispatch_recipients', $source);
        self::assertStringContainsString('learner_notifications', $source);
        self::assertStringContainsString('active_marker', $source);
        self::assertStringContainsString('uq_course_entitlements_active', $source);
        self::assertStringContainsString('duplicate_active', $source);
        self::assertStringContainsString('/health', $migrate);
        self::assertStringContainsString('verify-migrations.sh', $migrate);
    }

    public function testMakefileExposesOnlyTheComposeSafetyEntryPoints(): void
    {
        $makefile = $this->readProjectFile('Makefile');

        foreach (['migrate.sh', 'backup.sh', 'restore.sh', 'rehearse-restore.sh', 'verify-images', 'verify-migrations', 'verify-runtime-boundaries'] as $target) {
            self::assertStringContainsString($target, $makefile);
        }
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
