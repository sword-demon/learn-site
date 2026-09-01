<template>
  <footer class="foot site-footer" aria-label="站点页脚">
    <div class="foot-inner">
      <p class="foot-brand-name serif">拾阶学社</p>
      <nav class="foot-links" aria-label="页脚导航">
        <router-link to="/">关于我们</router-link>
        <router-link to="/maps">帮助中心</router-link>
        <router-link to="/terms">用户协议</router-link>
        <router-link to="/refund">退款说明</router-link>
        <a v-if="contactEmail" :href="`mailto:${contactEmail}`">课程合作</a>
      </nav>
      <p class="foot-copy mono">© {{ currentYear }} 拾阶学社 Shi Jie Xue She</p>
    </div>
  </footer>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { storeToRefs } from 'pinia';
import { useHomeStore } from '@/stores/home';

defineOptions({ name: 'SiteFooter' });

const homeStore = useHomeStore();
const { intro } = storeToRefs(homeStore);
const currentYear = new Date().getFullYear();
const contactEmail = computed(() => intro.value?.contact_email.trim() ?? '');

onMounted(() => {
  void homeStore.load();
});
</script>

<style scoped>
.foot-inner {
  max-width: 1200px;
  margin: 0 auto;
  padding: 40px 24px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  text-align: center;
}

.foot-brand-name {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
  color: var(--ink-2);
}

.foot-links {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 24px;
}

.foot-links a,
.foot-links span {
  color: var(--ink-2);
  font-size: 16px;
  text-decoration: underline;
  text-underline-offset: 3px;
}

.foot-links a:hover {
  color: var(--seal);
}

.foot-copy {
  margin: 0;
  font-size: 12px;
  color: var(--ink-3);
}
</style>
