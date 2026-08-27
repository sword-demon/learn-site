import type { App } from 'vue'
import ElementPlus, { type ConfigProviderProps } from 'element-plus'
import zhCn from 'element-plus/es/locale/lang/zh-cn'

export function installElementPlus(app: App): void {
  // Element Plus 2.14.5 exposes internal prop descriptors in the installer type,
  // while its runtime installer expects public locale values.
  const options = { locale: zhCn } as unknown as Partial<ConfigProviderProps>
  app.use(ElementPlus, options)
}
