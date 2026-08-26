<?php
declare(strict_types=1);

namespace App\service;

use App\model\Course;
use App\model\Lesson;
use App\support\HtmlSanitizer;
use support\think\Db;

/**
 * PublicLessonService — lesson content delivery for the learner surface.
 *
 * Phase 5 (US1). Preview lessons are open to anyone; full lessons are
 * gated behind EntitlementService (active row in course_entitlements).
 * Markdown bodies are sanitised server-side (FR-009); PDF / video responses
 * carry the asset row directly so the browser can `<a>` / `<video>`
 * the storage path. Tokenised / signed delivery URLs are deferred to
 * Phase 9 (asset worker) — that path keeps the Phase 5 contract stable.
 *
 * // ponytail: a missing asset row is treated as 404 (the lesson row
 * stays so the lesson list still renders the summary). Resource
 * processing failures (status='broken') still deliver the storage
 * path; the file itself 404s if missing on disk. FR-012 says
 * processing failure must not advance progress — Phase 5 has no
 * progress record yet, so the no-progress invariant holds vacuously.
 */
final class PublicLessonService
{
    public function __construct(private readonly EntitlementService $entitlements)
    {
    }

    /** @return array<string, mixed> */
    public function deliver(int $courseId, int $lessonId, ?int $viewerAccountId): array
    {
        $course = Course::where('id', '=', $courseId)->where('status', '=', 'published')->find();
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        $lesson = Lesson::where('id', '=', $lessonId)->where('status', '=', 'enabled')->find();
        if (!$lesson || (int) $lesson->chapter_id <= 0) {
            throw new BusinessException('NOT_FOUND', 'LESSON_NOT_FOUND');
        }
        $chapter = Db::name('chapters')->where('id', '=', (int) $lesson->chapter_id)->find();
        if (!$chapter || (int) $chapter['course_id'] !== $courseId) {
            throw new BusinessException('NOT_FOUND', 'LESSON_NOT_FOUND');
        }

        $isPreview = (int) ($lesson->is_preview ?? 0) === 1;
        if (!$isPreview) {
            // ponytail: phase-6 entitlement check goes here.
            if ($viewerAccountId === null || $viewerAccountId <= 0 || !$this->viewerAuthorized($courseId, $viewerAccountId)) {
                throw new BusinessException('FORBIDDEN', 'LESSON_LOCKED');
            }
        }

        $type = (string) $lesson->content_type;
        return match ($type) {
            'markdown' => $this->deliverMarkdown($lesson),
            'pdf', 'video' => $this->deliverAsset($lesson, $type),
            default => throw new BusinessException('VALIDATION_FAILED', 'LESSON_TYPE_INVALID'),
        };
    }

    /** @return array<string, mixed> */
    private function deliverMarkdown(Lesson $lesson): array
    {
        $raw = (string) ($lesson->body_markdown ?? '');
        // Markdown lessons store raw markdown; we surface sanitised HTML
        // so the UI does not need a client-side renderer in Phase 5.
        $clean = HtmlSanitizer::sanitize($this->markdownToHtml($raw));
        return [
            'kind' => 'markdown',
            'html' => $clean['html'],
        ];
    }

    /** @return array<string, mixed> */
    private function deliverAsset(Lesson $lesson, string $kind): array
    {
        $assetId = (int) ($lesson->asset_id ?? 0);
        if ($assetId <= 0) {
            throw new BusinessException('NOT_FOUND', 'ASSET_NOT_FOUND');
        }
        $asset = Db::name('assets')->where('id', $assetId)->find();
        if (!$asset) {
            throw new BusinessException('NOT_FOUND', 'ASSET_NOT_FOUND');
        }
        return [
            'kind'         => $kind,
            'asset_id'     => (int) $asset['id'],
            'storage_path' => (string) $asset['storage_path'],
            'mime_type'    => (string) $asset['mime_type'],
            'size_bytes'   => (int) ($asset['size_bytes'] ?? 0),
            'status'       => (string) $asset['status'],
        ];
    }

    private function viewerAuthorized(int $courseId, int $viewerAccountId): bool
    {
        // Authoritative check: EntitlementService::viewerAuthorized
        // looks up the active row in course_entitlements. Preview
        // lessons never reach this branch.
        return $this->entitlements->viewerAuthorized($courseId, $viewerAccountId);
    }

    /**
     * Phase 5 inline Markdown → HTML. Intentionally tiny: paragraphs,
     * headings, fenced code, links, bold/italic, inline code, and
     * unordered lists. Anything else passes through as escaped text.
     * HtmlSanitizer still strips dangerous protocols after this.
     */
    private function markdownToHtml(string $md): string
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $lines = explode("\n", $md);
        $out = [];
        $inCode = false;
        $codeBuf = [];
        $inList = false;

        foreach ($lines as $line) {
            if (preg_match('/^```/', $line)) {
                if ($inCode) {
                    $out[] = '<pre><code>' . htmlspecialchars(implode("\n", $codeBuf), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</code></pre>';
                    $codeBuf = [];
                    $inCode = false;
                } else {
                    if ($inList) { $out[] = '</ul>'; $inList = false; }
                    $inCode = true;
                }
                continue;
            }
            if ($inCode) {
                $codeBuf[] = $line;
                continue;
            }
            if (preg_match('/^###\s+(.*)$/', $line, $m)) {
                if ($inList) { $out[] = '</ul>'; $inList = false; }
                $out[] = '<h3>' . $this->inline($m[1]) . '</h3>';
            } elseif (preg_match('/^##\s+(.*)$/', $line, $m)) {
                if ($inList) { $out[] = '</ul>'; $inList = false; }
                $out[] = '<h2>' . $this->inline($m[1]) . '</h2>';
            } elseif (preg_match('/^#\s+(.*)$/', $line, $m)) {
                if ($inList) { $out[] = '</ul>'; $inList = false; }
                $out[] = '<h1>' . $this->inline($m[1]) . '</h1>';
            } elseif (preg_match('/^\s*-\s+(.*)$/', $line, $m)) {
                if (!$inList) { $out[] = '<ul>'; $inList = true; }
                $out[] = '<li>' . $this->inline($m[1]) . '</li>';
            } elseif (trim($line) === '') {
                if ($inList) { $out[] = '</ul>'; $inList = false; }
                $out[] = '';
            } else {
                if ($inList) { $out[] = '</ul>'; $inList = false; }
                $out[] = '<p>' . $this->inline($line) . '</p>';
            }
        }
        if ($inCode) {
            $out[] = '<pre><code>' . htmlspecialchars(implode("\n", $codeBuf), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</code></pre>';
        }
        if ($inList) {
            $out[] = '</ul>';
        }
        return implode("\n", $out);
    }

    private function inline(string $s): string
    {
        $escaped = htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // links: [text](https?://...) — only http(s)
        $escaped = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/',
            fn($m) => '<a href="' . htmlspecialchars($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" rel="noopener noreferrer">' . $m[1] . '</a>',
            $escaped,
        );
        // bold then italic then inline-code
        $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped);
        $escaped = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $escaped);
        $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped);
        return $escaped;
    }
}
