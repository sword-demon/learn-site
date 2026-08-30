# Multi-stage build for the learner SPA.
# Stage 1: build static assets with pnpm + Vite.
# Stage 2: serve them via nginx, reverse-proxying /api to the API container.

# ---- build stage ----
FROM node:22.11.0-alpine@sha256:b64ced2e7cd0a4816699fe308ce6e8a08ccba463c757c00c14cd372e3d2c763e AS build

WORKDIR /workspace

# corepack downloads pnpm from npm at build time; use npm install -g instead
# so NPM_REGISTRY can point at a mirror when registry.npmjs.org is unreachable.
ARG NPM_REGISTRY=https://registry.npmjs.org
RUN npm config set registry "${NPM_REGISTRY}" \
 && npm install -g pnpm@9.12.0 \
 && pnpm config set registry "${NPM_REGISTRY}"

COPY package.json pnpm-workspace.yaml pnpm-lock.yaml .npmrc ./
COPY apps/web/package.json apps/web/package.json
COPY packages/contracts/package.json packages/contracts/package.json
RUN pnpm install --frozen-lockfile

COPY apps/web apps/web
COPY packages/contracts packages/contracts
ARG VITE_PUSH_URL
ARG VITE_PUSH_APP_KEY
ENV VITE_PUSH_URL=$VITE_PUSH_URL
ENV VITE_PUSH_APP_KEY=$VITE_PUSH_APP_KEY
RUN pnpm --filter @learn-site/contracts build \
 && pnpm --filter @learn-site/web build

# ---- runtime stage ----
FROM nginx:1.27.3-alpine@sha256:814a8e88df978ade80e584cc5b333144b9372a8e3c98872d07137dbf3b44d0e4 AS runtime

COPY docker/web/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /workspace/apps/web/dist /usr/share/nginx/html
COPY docker/web/healthz.conf /etc/nginx/healthz.conf

EXPOSE 80
STOPSIGNAL SIGTERM

CMD ["nginx", "-g", "daemon off;"]
