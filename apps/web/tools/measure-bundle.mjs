#!/usr/bin/env node
/**
 * apps/web/tools/measure-bundle.mjs
 *
 * Measure the apps/web first-screen bundle size.
 *
 * Output JSON to stdout (one object, no log noise on stdout):
 *   {
 *     build_passed: 0|1,
 *     vitest_pass_rate: number,         // 1.0 if all tests pass, 0.0 otherwise
 *     critical_chunks_present: 0|1,
 *     entry_gzip_kb: number,            // PRIMARY: gzip size of the entry JS+CSS (KB)
 *     index_gzip_kb: number,
 *     total_gzip_kb: number,
 *     vendor_gzip_kb: number,
 *     chunk_count: number,
 *     build_seconds: number,
 *     notes: string[]
 *   }
 *
 * The entry chunk is determined by parsing dist/index.html for the first
 * `<script type="module">` and the largest linked CSS file. Everything else
 * is diagnostic.
 */

import { execSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, rmSync, statSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(fileURLToPath(import.meta.url), '../../../..');
const WEB = resolve(ROOT, 'apps/web');
const DIST = resolve(WEB, 'dist');

function log(msg) {
  process.stderr.write(`[measure-bundle] ${msg}\n`);
}

function rmrf(p) {
  if (existsSync(p)) rmSync(p, { recursive: true, force: true });
}

function listAssets(dir) {
  if (!existsSync(dir)) return [];
  const out = [];
  for (const name of readdirSync(dir)) {
    const full = join(dir, name);
    const st = statSync(full);
    if (st.isFile()) out.push({ name, full, bytes: st.size });
  }
  return out;
}

function gzipBytes(buf) {
  return gzipSync(buf, { level: 9 }).length;
}

function findEntry(distDir) {
  const indexHtml = join(distDir, 'index.html');
  if (!existsSync(indexHtml)) return { entryJs: null, entryCss: null };
  const html = readFileSync(indexHtml, 'utf8');
  const jsMatch = html.match(/<script[^>]+src="([^"]+)"/);
  const cssMatches = [...html.matchAll(/<link[^>]+href="([^"]+)"/g)]
    .map((m) => m[1])
    .filter((href) => {
      const tagStart = html.lastIndexOf('<link', html.indexOf(href));
      const tagEnd = html.indexOf('>', tagStart);
      const tag = html.slice(tagStart, tagEnd + 1);
      return /\brel="stylesheet"/.test(tag);
    });
  // Strip leading "/" and any "assets/" prefix — we compare against bare filenames
  // listed under dist/assets/.
  const strip = (href) => href.replace(/^\//, '').replace(/^assets\//, '');
  return {
    entryJs: jsMatch ? strip(jsMatch[1]) : null,
    entryCss: cssMatches.map(strip),
  };
}

function buildOnce() {
  const t0 = Date.now();
  const cmd = process.env.BUILD_CMD || 'pnpm --filter @learn-site/web run build';
  execSync(cmd, {
    cwd: process.env.BUILD_CWD || ROOT,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  return (Date.now() - t0) / 1000;
}

function syncFromContainer() {
  // Copy dist from the running web container so we can measure without
  // depending on host-side pnpm/rollup native binaries.
  execSync('sh -c "docker exec learn-site-web-1 tar -C /usr/share/nginx/html -cf - . | tar -xf - -C apps/web/dist"', {
    cwd: ROOT,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

function measure(distDir) {
  const { entryJs, entryCss } = findEntry(distDir);
  const assetsDir = join(distDir, 'assets');
  const all = listAssets(assetsDir);

  const sizes = all.map((a) => ({
    name: a.name,
    raw: a.bytes,
    gzip: gzipBytes(readFileSync(a.full)),
  }));

  const indexRaw = readFileSync(join(distDir, 'index.html'));
  const indexGzip = gzipBytes(indexRaw);

  const entryJsMeta = sizes.find((s) => s.name === entryJs);
  const entryCssRaw = entryCss
    .map((name) => sizes.find((s) => s.name === name))
    .filter(Boolean);
  const entryCssGzip = entryCssRaw.reduce((sum, s) => sum + s.gzip, 0);

  const entryJsGzip = entryJsMeta ? entryJsMeta.gzip : 0;
  const entryGzipKb = (entryJsGzip + entryCssGzip) / 1024;

  const totalGzip = sizes.reduce((s, a) => s + a.gzip, 0) + indexGzip;
  // Entry chunk + any non-view chunk carries the shared runtime + Element
  // Plus + Vue + Pinia + Vue Router code. Hash-named `index-*.js` and the
  // matching `index-*.css` are the entry.
  const vendorGzip = sizes
    .filter((s) => /^index-/.test(s.name))
    .reduce((s, a) => s + a.gzip, 0);

  return {
    entryGzipKb: round2(entryGzipKb),
    indexGzipKb: round2(indexGzip / 1024),
    totalGzipKb: round2(totalGzip / 1024),
    vendorGzipKb: round2(vendorGzip / 1024),
    chunkCount: sizes.length,
    entryJs,
    entryCss,
  };
}

function round2(n) {
  return Math.round(n * 100) / 100;
}

function runVitest() {
  // Default: run vitest inside the frontend-test container so we don't depend
  // on host-side pnpm/rollup native binaries. Set VITEST_CMD to override.
  const cmd = process.env.VITEST_CMD ||
    'docker compose -f compose.yaml -f compose.test.yaml run --rm frontend-test sh -c ' +
    "'corepack enable && corepack prepare pnpm@9.12.0 --activate && pnpm install --frozen-lockfile && pnpm --filter @learn-site/web test -- --reporter=basic 2>&1 | tail -40'";
  try {
    const out = execSync(cmd, {
      cwd: ROOT,
      stdio: ['ignore', 'pipe', 'pipe'],
    }).toString();
    const stripAnsi = (s) => s.replace(/\[[0-9;]*m/g, '');
    const clean = stripAnsi(out);
    const m = clean.match(/Tests\s+(\d+)\s+passed/);
    const total = clean.match(/Test Files\s+\d+\s+passed\s*\((\d+)\)/);
    if (m && total) return { passRate: 1.0, raw: `tests=${m[1]}/${total[1]}` };
    const failed = clean.match(/Tests\s+(\d+)\s+failed/);
    if (failed) return { passRate: 0.0, raw: `failed>=${failed[1]}` };
    return { passRate: 0.0, raw: `no-test-summary: ${clean.slice(-200)}` };
  } catch (e) {
    return { passRate: 0.0, raw: `vitest-error: ${String(e).slice(0, 160)}` };
  }
}

function ensureDistDir() {
  if (!existsSync(DIST)) {
    execSync(`mkdir -p "${DIST}"`, { stdio: 'ignore' });
  }
}

function main() {
  const notes = [];
  const skipBuild = process.env.SKIP_BUILD === '1';
  if (!skipBuild) rmrf(DIST);

  let buildPassed = 0;
  let buildSeconds = 0;
  let m = {
    entryGzipKb: 0,
    indexGzipKb: 0,
    totalGzipKb: 0,
    vendorGzipKb: 0,
    chunkCount: 0,
    entryJs: null,
    entryCss: [],
  };
  try {
    if (skipBuild) {
      ensureDistDir();
      syncFromContainer();
      buildSeconds = 0;
      notes.push('skip-build: synced dist from running web container');
    } else {
      buildSeconds = buildOnce();
    }
    buildPassed = 1;
    m = measure(DIST);
  } catch (e) {
    notes.push(`build error: ${String(e).slice(0, 200)}`);
  }

  const criticalChunksPresent =
    m.entryJs && existsSync(join(DIST, 'assets', m.entryJs)) ? 1 : 0;

  const vitest = runVitest();
  if (vitest.passRate < 1.0) notes.push(`vitest: ${vitest.raw}`);

  const result = {
    build_passed: buildPassed,
    vitest_pass_rate: vitest.passRate,
    critical_chunks_present: criticalChunksPresent,
    entry_gzip_kb: m.entryGzipKb,
    index_gzip_kb: m.indexGzipKb,
    total_gzip_kb: m.totalGzipKb,
    vendor_gzip_kb: m.vendorGzipKb,
    chunk_count: m.chunkCount,
    build_seconds: round2(buildSeconds),
    notes,
  };
  process.stdout.write(JSON.stringify(result, null, 2) + '\n');
}

main();
