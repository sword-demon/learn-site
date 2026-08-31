<?php

declare(strict_types=1);

namespace App\service;

use App\support\HtmlSanitizer;
use App\support\Logger;
use App\support\cache\HomeCache;
use support\think\Db;

final class SiteService
{
    /** @return array{title:string,subtitle:string,body_html:string,contact_email:string,updated_at:?string} */
    public function get(): array
    {
        $row = Db::name('site_profile')->where('id', 1)->find();
        return $this->shape(is_array($row) ? $row : []);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{title:string,subtitle:string,body_html:string,contact_email:string,updated_at:?string}
     */
    public function update(int $staffId, array $input): array
    {
        if ($staffId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        $title = trim((string) ($input['title'] ?? ''));
        $subtitle = trim((string) ($input['subtitle'] ?? ''));
        $bodyHtml = (string) ($input['body_html'] ?? '');
        $contactEmail = trim((string) ($input['contact_email'] ?? ''));
        $invalid = [];
        if ($title === '' || strlen($title) > 80) {
            $invalid[] = 'title';
        }
        if (strlen($subtitle) > 160) {
            $invalid[] = 'subtitle';
        }
        if (strlen($bodyHtml) > 4000) {
            $invalid[] = 'body_html';
        }
        if (
            $contactEmail !== ''
            && (strlen($contactEmail) > 120 || filter_var($contactEmail, FILTER_VALIDATE_EMAIL) === false)
        ) {
            $invalid[] = 'contact_email';
        }
        if ($invalid !== []) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_FIELDS:' . implode(',', $invalid));
        }

        $sanitized = HtmlSanitizer::sanitize($bodyHtml);
        if ($sanitized['truncated']) {
            throw new BusinessException('VALIDATION_FAILED', 'BODY_HTML_TOO_LARGE');
        }
        $now = date('Y-m-d H:i:s');
        $row = [
            'id' => 1,
            'title' => $title,
            'subtitle' => $subtitle,
            'body_html' => $sanitized['html'],
            'contact_email' => $contactEmail,
            'updated_by_staff_id' => $staffId,
            'updated_at' => $now,
        ];
        Db::name('site_profile')->replace()->insert($row);
        Logger::info('site.profile.updated', [
            'actor_id' => $staffId,
            'target_type' => 'site_profile',
            'target_id' => 1,
        ]);
        (new HomeCache())->forget(HomeCache::KEY_SITE_INTRO);
        return $this->shape($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{title:string,subtitle:string,body_html:string,contact_email:string,updated_at:?string}
     */
    private function shape(array $row): array
    {
        return [
            'title' => (string) ($row['title'] ?? '学习平台'),
            'subtitle' => (string) ($row['subtitle'] ?? '选课、学习、交流'),
            'body_html' => (string) ($row['body_html'] ?? ''),
            'contact_email' => (string) ($row['contact_email'] ?? ''),
            'updated_at' => isset($row['updated_at']) && $row['updated_at'] !== null
                ? (string) $row['updated_at']
                : null,
        ];
    }
}
