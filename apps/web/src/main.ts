import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import { installElementPlusLocale } from './plugins/element-plus';
import './style.css';
import 'element-plus/theme-chalk/el-overlay.css';
import 'element-plus/theme-chalk/el-dialog.css';

try {
  document.documentElement.dataset.theme =
    localStorage.getItem('learn-portal-theme') === 'night' ? 'night' : 'day';
} catch {
  document.documentElement.dataset.theme = 'day';
}

const app = createApp(App);
app.use(createPinia());
app.use(router);
installElementPlusLocale(app);
app.mount('#app');
