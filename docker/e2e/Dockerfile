ARG PLAYWRIGHT_IMAGE=mcr.microsoft.com/playwright:v1.62.1-noble@sha256:dcc5531e97840b9b5e794f2814476b21571c5124a3fca2267d73041f56e7580e
FROM ${PLAYWRIGHT_IMAGE}

ENV CI=1 \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

WORKDIR /workspace

COPY package.json pnpm-workspace.yaml pnpm-lock.yaml .npmrc ./
COPY apps/admin/package.json apps/admin/package.json
COPY apps/web/package.json apps/web/package.json
COPY packages/contracts/package.json packages/contracts/package.json

RUN corepack enable \
    && corepack prepare pnpm@9.12.0 --activate \
    && pnpm install --frozen-lockfile

COPY apps/admin/playwright.config.ts apps/admin/playwright.config.ts
COPY apps/admin/tests/e2e apps/admin/tests/e2e
COPY apps/web/playwright.config.ts apps/web/playwright.config.ts
COPY apps/web/tests/e2e apps/web/tests/e2e

CMD ["sh", "-c", "pnpm --filter @learn-site/admin e2e && pnpm --filter @learn-site/web e2e"]
