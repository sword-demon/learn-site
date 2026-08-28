<template>
  <footer class="site-footer" aria-label="站点页脚">
    <div class="site-footer-inner">
      <div class="footer-main">
        <div class="footer-brand">
          <router-link to="/" class="footer-brand-link" aria-label="林间课室首页">
            <span class="footer-latin">Linjian</span>
            <strong class="display">林间课室</strong>
          </router-link>
          <p>沿着清晰路径学习，留下自己的课程档案。</p>
        </div>

        <div class="footer-directory">
          <nav class="footer-group" aria-label="页脚导航">
            <h2>浏览</h2>
            <router-link to="/">课程分类</router-link>
            <router-link to="/maps">学习地图</router-link>
          </nav>

          <address v-if="contactEmail" class="footer-group">
            <h2>课程合作</h2>
            <a :href="`mailto:${contactEmail}`">{{ contactEmail }}</a>
          </address>
        </div>
      </div>

      <div class="footer-floor">
        <p>© {{ currentYear }} 林间课室</p>
      </div>
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
.site-footer {
  border-top: 1px solid var(--line);
  background: rgba(233, 237, 228, 0.64);
}

.site-footer-inner {
  width: min(1180px, 100%);
  margin: 0 auto;
  padding: 48px 24px 28px;
}

.footer-main {
  display: grid;
  grid-template-columns: minmax(0, 1.45fr) minmax(320px, 0.55fr);
  gap: 72px;
  align-items: start;
}

.footer-brand {
  max-width: 520px;
}

.footer-brand-link {
  display: inline-flex;
  align-items: baseline;
  gap: 12px;
  color: var(--ink);
  text-decoration: none;
}

.footer-brand-link strong {
  color: var(--pine-deep);
  font-size: 1.75rem;
  letter-spacing: 0.06em;
}

.footer-latin {
  color: var(--accent-deep);
  font-family: var(--font-mono);
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

.footer-brand p {
  max-width: 28ch;
  margin: 18px 0 0;
  color: var(--ink-soft);
  font-size: 0.9rem;
  line-height: 1.8;
}

.footer-directory {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 40px;
}

.footer-group {
  display: grid;
  align-content: start;
  gap: 11px;
  min-width: 0;
  margin: 0;
  font-style: normal;
}

.footer-group h2 {
  margin: 0 0 3px;
  color: var(--pine-deep);
  font-size: 0.78rem;
  font-weight: 700;
}

.footer-group a {
  width: fit-content;
  max-width: 100%;
  color: var(--ink-soft);
  font-size: 0.82rem;
  line-height: 1.55;
  overflow-wrap: anywhere;
  text-decoration-color: transparent;
  text-underline-offset: 4px;
  transition:
    color 0.2s ease,
    text-decoration-color 0.2s ease;
}

.footer-group a:hover {
  color: var(--accent-deep);
  text-decoration-color: currentColor;
}

.footer-floor {
  margin-top: 42px;
  padding-top: 18px;
  border-top: 1px solid var(--line);
}

.footer-floor p {
  margin: 0;
  color: var(--ink-soft);
  font-family: var(--font-mono);
  font-size: 0.68rem;
}

@media (max-width: 760px) {
  .site-footer-inner {
    padding: 38px 18px 24px;
  }

  .footer-main {
    grid-template-columns: 1fr;
    gap: 34px;
  }

  .footer-directory {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 24px;
  }

  .footer-floor {
    margin-top: 32px;
  }
}

@media (max-width: 420px) {
  .footer-brand-link {
    align-items: flex-start;
    flex-direction: column;
    gap: 5px;
  }

  .footer-directory {
    grid-template-columns: 1fr;
    gap: 28px;
  }
}
</style>
