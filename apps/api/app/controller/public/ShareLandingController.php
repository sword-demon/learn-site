<?php

declare(strict_types=1);

namespace App\controller\public;

use App\service\BusinessException;
use App\service\DistributionAuditService;
use App\service\ShareEntryService;
use App\support\ApiResponse;
use support\Request;
use support\Response;

/**
 * Public landing endpoint hit when a visitor follows a /r/{code} URL.
 * Always allowed without auth; logs the visit, sets the visitor cookie
 * (so a later registration can link back), and 302s to the matching web
 * page (course detail for scope=course, homepage for scope=site).
 *
 * Spec: 016-course-distribution/T020 — visitor never sees referrer id,
 * plaintext short code, or visitor_token in the response body, so we
 * leave the body empty and redirect.
 */
final class ShareLandingController
{
    public function __construct(
        private readonly ShareEntryService $service,
        private readonly DistributionAuditService $audit = new DistributionAuditService(),
    ) {
    }

    public function show(Request $request, string $code): Response
    {
        $token = (string) $request->cookie(ShareEntryService::VISITOR_COOKIE, '');
        try {
            $result = $this->service->recordVisit(
                $code,
                $token !== '' ? $token : null,
                $request->getRealIp(),
                (string) ($request->header('user-agent', '') ?? ''),
            );
        } catch (BusinessException $e) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, $e->getMessage());
        }
        // ponytail: write audit row for every successful landing hit so ops
        // can reconcile share_visits counts with referral chain growth.
        $this->audit->record(
            'system',
            null,
            'landing.hit',
            'share_entry',
            $result['share_entry_id'],
        );
        // ponytail: build the target URL inline; both shapes are pure string
        // concat off LEARN_SITE_PUBLIC_BASE, no helper wanted yet.
        $base = rtrim((string) (getenv('LEARN_SITE_PUBLIC_BASE') ?: 'https://learn.example.test'), '/');
        $target = $result['scope'] === 'course' && $result['course_id'] !== null
            ? $base . '/courses/' . $result['course_id']
            : $base . '/';
        return self::redirect($target)->cookie(
            ShareEntryService::VISITOR_COOKIE,
            $result['visitor_token'],
            ShareEntryService::COOKIE_TTL_SECONDS,
            '/',
            '',
            self::secureCookie(),
            true,
            'Lax',
        );
    }

    // ponytail: empty body keeps the redirect side-effect free — visitor
    // cannot sniff referrer / code / token from the HTML. Mirrors the
    // idiom used by PaymentNotifyController for gateway callbacks.
    private static function redirect(string $location): Response
    {
        return response('', 302)->withHeader('Location', $location);
    }

    // ponytail: Secure only in non-testing env. Tests / curl from localhost
    // would otherwise drop the cookie.
    private static function secureCookie(): bool
    {
        $env = (string) (getenv('APP_ENV') ?: 'production');
        return $env !== 'testing';
    }
}