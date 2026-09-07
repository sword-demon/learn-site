import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import AutoImport from 'unplugin-auto-import/vite';
import Components from 'unplugin-vue-components/vite';
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers';
import path from 'node:path';
import { visualizer } from 'rollup-plugin-visualizer';

// Analyze-only config; used by `make bundle-analyze` / ce-optimize diagnostics.
// Not part of the production build path.
export default defineConfig({
  plugins: [
    vue(),
    AutoImport({ resolvers: [ElementPlusResolver({ importStyle: false })] }),
    Components({ resolvers: [ElementPlusResolver({ importStyle: false })] }),
    visualizer({
      filename: '/workspace/apps/web/dist/stats.html',
      gzipSize: true,
      brotliSize: true,
      template: 'treemap',
    }),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
      '@contracts': path.resolve(__dirname, '../../packages/contracts/src'),
    },
  },
  build: {
    target: 'es2022',
    sourcemap: false,
  },
});
