<template>
  <main class="page home-page">
    <section v-if="intro" class="intro">
      <h1 class="display">{{ intro.title }}</h1>
      <p v-if="intro.subtitle" class="lede">{{ intro.subtitle }}</p>
      <div v-if="intro.body_html" class="prose" v-html="intro.body_html" />
      <p v-if="intro.contact_email" class="contact">联系：{{ intro.contact_email }}</p>
    </section>
    <section class="hero">
      <p class="badge">首页 · 分类树</p>
      <h1 class="display">先选一条林间小径</h1>
      <p class="lede">
        学习地图在主导航, 不占首页. 这里只铺分类, 最多三级.
      </p>
    </section>
    <section class="tree-panel" aria-label="课程分类">
      <p v-if="loading">正在铺开分类…</p>
      <p v-else-if="error" class="notice">分类暂时读不到, 课室还在.</p>
      <p v-else-if="categories.length === 0" class="empty">还没有启用中的分类. 管理员发布课程后会出现在这里.</p>
      <ul v-else class="tree">
        <CategoryBranch v-for="node in categories" :key="node.id" :node="node" />
      </ul>
    </section>
  </main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import type { CategoryNode, SiteIntro } from '@learn-site/contracts'
import { fetchHome } from '@/api/learner'
import CategoryBranch from '@/views/home/CategoryBranch.vue'

const categories = ref<CategoryNode[]>([])
const intro = ref<SiteIntro | null>(null)
const loading = ref(true)
const error = ref(false)

onMounted(async () => {
  try {
    const home = await fetchHome()
    categories.value = home.categories
    intro.value = home.site_intro ?? null
  } catch {
    error.value = true
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.intro { display: grid; gap: 8px; padding: 16px 20px; border: 1px solid var(--color-border, #e3e6ee); border-radius: 10px; background: #fff; }
.contact { color: var(--color-text-muted, #5b6472); margin: 0; font-size: 0.9rem; }
.prose { line-height: 1.7; }
</style>
