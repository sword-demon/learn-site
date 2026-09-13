<template><main class="page courses-page"><header><p class="eyebrow">COURSE LIBRARY</p><h1>全部课程</h1><p>按分类探索完整课程目录，找到适合你的下一阶。</p></header><nav class="filters"><router-link to="/courses" :class="{on:!route.query.cat}">全部</router-link><router-link v-for="c in categories" :key="c.id" :to="{path:'/courses',query:{cat:c.id}}" :class="{on:String(route.query.cat)===String(c.id)}">{{c.name}}</router-link></nav><el-skeleton v-if="loading" animated :rows="6"/><el-empty v-else-if="!items.length" description="暂无公开课程"/><div v-else class="grid"><CourseEntryRow v-for="c in items" :key="c.id" :course="c" :show-favorite="session.loggedIn"/></div><el-pagination v-if="total > limit" v-model:current-page="page" :page-size="limit" :total="total" layout="total, prev, pager, next" @current-change="load"/></main></template>
<script setup lang="ts">import {computed,onMounted,ref,watch} from 'vue';import {useRoute} from 'vue-router';import {storeToRefs} from 'pinia';import {useHomeStore} from '@/stores/home';import {fetchCategoryCourses} from '@/api/learner';import CourseEntryRow from '@/components/CourseEntryRow.vue';import {useLoginFamilyStore} from '@/api/login';
const route = useRoute();
const store = useHomeStore();
const session = useLoginFamilyStore();
const { categories, recentCourses } = storeToRefs(store);
const items = ref<any[]>([]);
const loading = ref(true);
const page = ref(Number(route.query.page) || 1);
const limit = 12;
const total = ref(0);
const selected = computed(() => Number(route.query.cat) || 0);
async function load() {
  loading.value = true;
  try {
    if (selected.value) {
      const result = await fetchCategoryCourses(selected.value, page.value, limit);
      total.value = result.list.total;
      items.value = result.list.items;
    } else {
      const results = await Promise.all(categories.value.map((category) => fetchCategoryCourses(category.id, 1, 100)));
      const all = Array.from(new Map(results.flatMap((result) => result.list.items).map((course) => [course.id, course])).values());
      total.value = all.length;
      items.value = all.slice((page.value - 1) * limit, page.value * limit);
    }
  } catch {
    items.value = [];
  } finally {
    loading.value = false;
  }
}
onMounted(async () => { await store.load(); await load(); });
watch(() => route.query.cat, () => { page.value = 1; void load(); });
watch(() => route.query.page, () => { page.value = Number(route.query.page) || 1; void load(); });
</script>
<style scoped>.courses-page{display:grid;gap:28px}.eyebrow{color:var(--seal);font-size:11px;letter-spacing:.18em;font-weight:700}.courses-page h1{margin:4px 0;font-size:42px}.courses-page header p:last-child{color:var(--ink-2)}.filters{display:flex;gap:24px;flex-wrap:wrap;border-bottom:1px solid var(--line);padding-bottom:16px}.filters a{color:var(--ink-2)}.filters a.on{color:var(--seal);font-weight:700}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:18px}.courses-page :deep(.el-pagination){justify-content:flex-end}</style>
