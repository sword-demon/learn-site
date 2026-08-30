import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const readProjectFile = (relativePath: string): string =>
  readFileSync(fileURLToPath(new URL(`../${relativePath}`, import.meta.url)), 'utf8');

describe('Element Plus theme foundation', () => {
  it('declares the shared icon package as a web dependency', () => {
    const packageJson = JSON.parse(readProjectFile('package.json')) as {
      dependencies: Record<string, string>;
    };

    expect(packageJson.dependencies['@element-plus/icons-vue']).toMatch(/^\^2\.3\./);
  });

  it('exposes the paper and ink design tokens to Tailwind', () => {
    const config = readProjectFile('tailwind.config.js');

    for (const token of [
      'paper',
      'card',
      'ink',
      'muted',
      'seal',
      'indigo',
      'moss',
      'gold',
      'line',
    ]) {
      expect(config).toContain(`${token}:`);
    }
    expect(config).toContain("paper: 'var(--r)'");
    expect(config).toContain("elevated: 'var(--shadow-lg)'");
  });

  it('maps Element Plus states to both day and night themes', () => {
    const css = readProjectFile('src/style.css');
    const nightTheme = css.match(/html\[data-theme='night'\]\s*\{([\s\S]*?)\n\}/)?.[1] ?? '';

    expect(css).toContain('--el-color-primary: var(--seal)');
    expect(css).toContain('--el-color-success: var(--moss)');
    expect(css).toContain('--el-color-warning: var(--gold)');
    expect(css).toContain('--el-fill-color-light: var(--card-2)');
    expect(css).toContain('--el-mask-color: rgba(43, 38, 32, 0.42)');
    expect(css).toContain('--el-disabled-bg-color: var(--paper-2)');
    expect(nightTheme).toContain('--el-fill-color-light: var(--card-2)');
    expect(nightTheme).toContain('--el-mask-color: rgba(0, 0, 0, 0.62)');
  });
});
