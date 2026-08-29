import { createApp } from 'vue';
import { createPinia } from 'pinia';
import NProgress from 'nprogress';
import 'nprogress/nprogress.css';
import 'element-plus/dist/index.css';
import App from './App.vue';
import router from './router';
import './style.css';
import { installElementPlus } from './plugins/element-plus';

NProgress.configure({ showSpinner: false, trickleSpeed: 120 });

const app = createApp(App);
app.use(createPinia());
app.use(router);
installElementPlus(app);
app.mount('#app');
