import type { App } from 'vue';
import { provideGlobalConfig } from 'element-plus';
import zhCn from 'element-plus/es/locale/lang/zh-cn';

export function installElementPlusLocale(app: App): void {
  // Element Plus 2.14.5 types ConfigProviderContext with internal prop
  // descriptors, while provideGlobalConfig expects public locale values.
  provideGlobalConfig(
    { locale: zhCn } as unknown as Parameters<typeof provideGlobalConfig>[0],
    app,
    true,
  );
}
