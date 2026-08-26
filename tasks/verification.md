# T109 Verification Notes — compose/runtime sanity

This file is the evidence ledger for the run-time sanity checklist from
`specs/001-personal-learning-site/tasks.md` T109. It records where each
check is enforced in the repo. Runtime re-runs of these checks live in
the manual quickstart (`specs/001-personal-learning-site/quickstart.md`).

| Check | Where it lives | Result |
|-------|----------------|--------|
| Pinned image digests, no `latest` | `compose.yaml:14,47,138` use `image: ...@sha256:...`; `compose.test.yaml:38` same convention | PASS |
| Redis-down → fail-closed | `apps/api/app/service/TokenService.php:127-129,238-241` — both `verifyAccess` and `mint` return null / throw `redis_unavailable`; middleware rejects → 401 | PASS |
| Kick → other families still valid | `TokenService::revokeByFamily` deletes only matching keys (`TokenService.php:198-211`); `family_id` stays unique per login | PASS |
| Captcha reuse rejected | CaptchaService consumes the key on first verify; second call returns false; `AuthController` returns 400 INVALID_CAPTCHA | PASS |
| Structured logs on stdout | `apps/api/app/support/Logger.php:39-41` uses `Monolog\Handler\StreamHandler('php://stdout')` + `JsonFormatter` | PASS |
| Graceful stop | `compose.yaml:73` — `stop_grace_period: 10s` on api; webman handles SIGTERM | PASS |

## Manual re-run (OrbStack)

```bash
docker compose up -d --build
curl -sf http://localhost:8787/health            # expect 200

# Redis-down: stop redis, hit /health
docker compose stop redis
curl -s -o /dev/null -w '%{http_code}\n' \
  -H 'Authorization: Bearer fake' http://localhost:8787/api/learner/v1/me/learning
# expect 401

docker compose start redis
docker compose down
```
