<?php
declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\support\ApiResponse;
use App\support\HtmlSanitizer;
use App\support\Logger;
use support\Request;
use support\think\Db;

/**
 * Phase 19 / US11 — site profile (T095).
 *
 *   GET    /api/admin/v1/site     → site profile (permission `site.manage`).
 *   PUT    /api/admin/v1/site     → update site profile + write audit row.
 *
 * The table has exactly one row (id=1). On first read with no row we
 * synthesize a default; the editor then PUTs to materialise it.
 */
final class SiteController
{
    public function show(Request $request): \support\Response
    {
        return $this->wrap(function () use ($request) {
            $row = $this->load();
            return $this->shape($row);
        });
    }

    public function update(Request $request): \support\Response
    {
        return $this->wrap(function () use ($request) {
            $body = self::readJson($request);
            $title = trim((string) ($body['title'] ?? ''));
            $subtitle = trim((string) ($body['subtitle'] ?? ''));
            $bodyHtml = (string) ($body['body_html'] ?? '');
            $contact = trim((string) ($body['contact_email'] ?? ''));
            $errors = [];
            if ($title === '' || strlen($title) > 80) {
                $errors[] = 'title';
            }
            if (strlen($subtitle) > 160) {
                $errors[] = 'subtitle';
            }
            if (strlen($bodyHtml) > 4000) {
                $errors[] = 'body_html';
            }
            if ($contact !== '' && !filter_var($contact, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'contact_email';
            }
            if ($errors) {
                throw new BusinessException('VALIDATION_FAILED', 'INVALID_FIELDS:' . implode(',', $errors));
            }
            $sanitized = HtmlSanitizer::sanitize($bodyHtml);
            if ($sanitized['truncated']) {
                throw new BusinessException('VALIDATION_FAILED', 'BODY_HTML_TOO_LARGE');
            }
            $sanitized = $sanitized['html'];
            $staffId = (int) ($request->account_id ?? 0);
            $now = date('Y-m-d H:i:s');
            Db::name('site_profile')->replace([
                'id' => 1,
                'title' => $title,
                'subtitle' => $subtitle,
                'body_html' => $sanitized,
                'contact_email' => $contact,
                'updated_by_staff_id' => $staffId > 0 ? $staffId : null,
                'updated_at' => $now,
            ]);
            Logger::info('site.profile.update', [
                'actor_id' => $staffId > 0 ? $staffId : null,
                'title' => $title,
            ]);
            Db::name('audit_log')->insert([
                'actor_id' => $staffId > 0 ? $staffId : null,
                'action' => 'site.profile.update',
                'target_type' => 'site_profile',
                'target_id' => 1,
                'payload_json' => json_encode(['title' => $title, 'contact_email' => $contact], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
            return $this->shape([
                'id' => 1,
                'title' => $title,
                'subtitle' => $subtitle,
                'body_html' => $sanitized,
                'contact_email' => $contact,
                'updated_at' => $now,
                'updated_by_staff_id' => $staffId > 0 ? $staffId : null,
            ]);
        });
    }

    private function load(): array
    {
        $row = Db::name('site_profile')->where('id', 1)->find();
        if ($row) {
            return $row;
        }
        return [
            'id' => 1,
            'title' => '学习平台',
            'subtitle' => '选课、学习、交流',
            'body_html' => '',
            'contact_email' => '',
            'updated_at' => null,
            'updated_by_staff_id' => null,
        ];
    }

    private function shape(array $row): array
    {
        return [
            'title' => (string) ($row['title'] ?? '学习平台'),
            'subtitle' => (string) ($row['subtitle'] ?? ''),
            'body_html' => (string) ($row['body_html'] ?? ''),
            'contact_email' => (string) ($row['contact_email'] ?? ''),
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    private function wrap(callable $fn): \support\Response
    {
        try {
            return ApiResponse::ok($fn());
        } catch (BusinessException $e) {
            return ApiResponse::fail($this->mapApiCode($e->apiCode), $e->getMessage());
        } catch (\Throwable $e) {
            Logger::error('site.admin.failed', ['err' => $e->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL');
        }
    }

    private function mapApiCode(string $code): string
    {
        return match ($code) {
            'VALIDATION_FAILED' => ApiResponse::VALIDATION_FAILED,
            'NOT_FOUND' => ApiResponse::NOT_FOUND,
            'FORBIDDEN' => ApiResponse::FORBIDDEN,
            default => ApiResponse::VALIDATION_FAILED,
        };
    }

    private static function readJson(Request $request): array
    {
        $raw = (string) $request->rawBody();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}