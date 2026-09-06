import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import './style.css';

try {
  document.documentElement.dataset.theme =
    localStorage.getItem('learn-portal-theme') === 'night' ? 'night' : 'day';
} catch {
  document.documentElement.dataset.theme = 'day';
}

const app = createApp(App);
app.use(createPinia());
app.use(router);
app.mount('#app');
