<template>
  <footer class="foot site-footer" aria-label="站点页脚">
    <div class="foot-inner">
      <div class="foot-brand">
        <div class="seal-mark" aria-hidden="true"><span>拾</span><span>阶</span></div>
        <div class="foot-copy-block">
          <p class="foot-lead">
            <strong class="serif">拾阶学社</strong>
            <span v-if="tagline"> · {{ tagline }}</span>
          </p>
          <p v-if="introText" class="foot-desc">{{ introText }}</p>
          <p v-if="contactEmail" class="foot-contact">
            课程合作：
            <a :href="`mailto:${contactEmail}`">{{ contactEmail }}</a>
          </p>
        </div>
      </div>
      <nav class="foot-links" aria-label="页脚导航">
        <router-link to="/">首页 · 分类</router-link>
        <router-link to="/maps">学习地图</router-link>
      </nav>
      <p class="foot-copy mono">© {{ currentYear }} 拾阶学社</p>
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
const tagline = computed(() => intro.value?.subtitle?.trim() ?? '拾级而上 · 日进一阶');
const introText = computed(() => {
  const title = intro.value?.title?.trim();
  if (!title || title === tagline.value) return '';
  return title;
});

onMounted(() => {
  void homeStore.load();
});
</script>

<style scoped>
.foot-inner {
  max-width: 1180px;
  margin: 0 auto;
  padding: 26px 24px;
  display: flex;
  gap: 20px;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  font-size: 12.5px;
  color: var(--ink-3);
}

.foot-brand {
  display: flex;
  gap: 12px;
  align-items: center;
  max-width: min(560px, 100%);
}

.foot-copy-block {
  display: grid;
  gap: 4px;
}

.foot-lead {
  margin: 0;
  line-height: 1.5;
}

.foot-lead strong {
  color: var(--ink-2);
  font-size: 14px;
}

.foot-desc {
  margin: 0;
  color: var(--ink-3);
  line-height: 1.65;
}

.foot-contact {
  margin: 0;
  font-size: 12px;
}

.foot-contact a {
  color: var(--indigo);
}

.foot-links {
  display: flex;
  gap: 16px;
}

.foot-links a {
  color: var(--ink-3);
  font-size: 12.5px;
}

.foot-links a:hover {
  color: var(--seal);
}

.foot-copy {
  margin: 0;
  font-size: 11px;
}

@media (max-width: 640px) {
  .foot-inner {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
