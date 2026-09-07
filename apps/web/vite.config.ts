import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import AutoImport from 'unplugin-auto-import/vite';
import Components from 'unplugin-vue-components/vite';
import type { ComponentResolver } from 'unplugin-vue-components';
import path from 'node:path';
import process from 'node:process';

/**
 * 轻量级 PascalCase → kebab-case 转换，用于 element-plus 组件目录名。
 * ElementPlusResolver upstream 用 unplugin-vue-components/utils 里的 kebabCase，
 * 但该路径不在本项目的 unplugin-vue-components 0.27.5 的 subpath exports 里。
 * element-plus 目录约定是 PascalCase → kebab-case，单段足够这里。
 */
function toKebab(name: string): string {
  return name
    .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
    .replace(/([A-Z]+)([A-Z][a-z])/g, '$1-$2')
    .toLowerCase();
}

/**
 * Element Plus 自定义 resolver。
 *
 * Why: 官方 unplugin-vue-components ElementPlusResolver 在 element-plus@2.14.5 下生成的
 * CSS 路径 `element-plus/es/components/<name>/style/css` 已不存在（实际只有 `style/css.mjs`）。
 * 同时项目需要去掉 `import ElementPlus from 'element-plus'` + `app.use(ElementPlus)`，
 * 否则 entry JS 会被整个 ES 模块图 + install payload 撑到 374 KB gzip。
 *
 * What this resolver does:
 *   - 匹配 `El[A-Z]Xxx` → 从 `element-plus/es` 命名的 import，附带 base + 当前组件的 CSS sideEffects
 *   - 匹配 `ElIconXxx` → 走 `@element-plus/icons-vue`（图标体积小、必须整包打入）
 *   - 匹配指令（vLoading/vPopover/vInfiniteScroll） → 走 `element-plus/es`
 *
 * Why CSS as sideEffects instead of letting unplugin-vue-components split per component:
 *   - sideEffects 走 vite 的依赖图扫描，自动合并到所在 chunk（懒加载路由会按需加载对应 CSS）
 *   - 通过 base/style/css.mjs 一次性引入 base 重置样式，避免单独追踪每个 base.css
 *   - 通过具名 css.mjs 文件触发 Vite 的 esbuild CSS 处理，避免 raw text 误判
 *
 * 同步在 src/main.ts 移除了 full ElementPlus import 与 app.use，见 ce-optimize spec H12。
 */
function elementPlusLocalResolver(): ComponentResolver {
  const toDir = (name: string) => toKebab(name.replace(/^El/, ''));
  return {
    type: 'component',
    resolve: (name: string) => {
      // ElIcon 是 el-icon 包装组件（非图标本身），从 element-plus/es/components/icon 引入。
      // ElIconXxx（如 ElIconArrow）才是图标，从 @element-plus/icons-vue 引入。
      if (name === 'ElIcon') {
        return {
          name: 'ElIcon',
          from: 'element-plus/es/components/icon/index.mjs',
          sideEffects: [
            `element-plus/es/components/base/style/css.mjs`,
            `element-plus/es/components/icon/style/css.mjs`,
          ],
        };
      }
      if (name.startsWith('ElIcon')) {
        return {
          name: name.replace(/^ElIcon/, ''),
          from: '@element-plus/icons-vue',
        };
      }
      if (!/^El[A-Z]/.test(name)) return undefined;
      const dir = toDir(name);
      return {
        name,
        from: 'element-plus/es',
        sideEffects: [
          `element-plus/es/components/base/style/css.mjs`,
          `element-plus/es/components/${dir}/style/css.mjs`,
        ],
      };
    },
  };
}

function elementPlusDirectiveResolver(): ComponentResolver {
  const map: Record<string, { importName: string; dir: string }> = {
    Loading: { importName: 'ElLoadingDirective', dir: 'loading' },
    Popover: { importName: 'ElPopoverDirective', dir: 'popper' },
    InfiniteScroll: { importName: 'ElInfiniteScroll', dir: 'infinite-scroll' },
  };
  return {
    type: 'directive',
    resolve: (name: string) => {
      const target = map[name];
      if (!target) return undefined;
      return {
        name: target.importName,
        from: 'element-plus/es',
        sideEffects: [
          `element-plus/es/components/base/style/css.mjs`,
          `element-plus/es/components/${target.dir}/style/css.mjs`,
        ],
      };
    },
  };
}

const resolvers: ComponentResolver[] = [
  elementPlusLocalResolver(),
  elementPlusDirectiveResolver(),
];

export default defineConfig({
  plugins: [
    // element-plus/theme-chalk/*.css 在测试环境（node）下没有 CSS 加载器。
    // 只在 vitest 里把 specifier 换成空模块；生产构建必须走真实 CSS，
    // 否则 el-overlay 没有 position:fixed，弹窗会掉到页脚后面。
    {
      name: 'element-plus-css-stub',
      enforce: 'pre',
      apply: () => Boolean(process.env.VITEST),
      resolveId(source) {
        if (!process.env.VITEST) return null;
        if (/^element-plus\/theme-chalk\/.*\.css$/.test(source)) {
          return { id: path.resolve(__dirname, 'tests/css-stub.ts') };
        }
        return null;
      },
    },
    vue(),
    AutoImport({ resolvers }),
    Components({ resolvers }),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
      '@contracts': path.resolve(__dirname, '../../packages/contracts/src'),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8787',
        changeOrigin: true,
      },
      '/plugin': {
        target: 'http://127.0.0.1:8787',
        changeOrigin: true,
      },
      '/app': {
        target: 'http://127.0.0.1:3131',
        changeOrigin: true,
        ws: true,
      },
    },
  },
  build: {
    target: 'es2022',
    sourcemap: false,
  },
  test: {
    environment: 'node',
    env: {
      VITE_PUSH_URL: 'ws://push.test',
      VITE_PUSH_APP_KEY: 'test-key',
    },
    // E2E specs use Playwright's `test.describe`; vitest must skip them.
    exclude: ['**/node_modules/**', '**/dist/**', 'tests/e2e/**'],
    // vite-node 默认把 node_modules 透传给原生 require。
    // 把 element-plus 加入 inline，强制走 vite 解析链，让上面 plugins 里的
    // element-plus-css-stub 能在测试环境把 theme-chalk/*.css 重写到空模块。
    server: {
      deps: {
        inline: [/element-plus/],
      },
    },
  },
});
