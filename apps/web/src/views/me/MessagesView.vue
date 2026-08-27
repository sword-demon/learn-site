<template>
  <main class="page account-page messages-page">
    <header class="account-head">
      <div>
        <p class="eyebrow"><span class="eyebrow-rule" />个人书架 · 消息</p>
        <h1 class="display">消息</h1>
        <p class="lede">课程通知和学习提醒会集中放在这里。</p>
      </div>
    </header>
    <section class="empty-state">
      <span class="empty-mark" aria-hidden="true">—</span>
      <h2 class="display">暂时没有新消息</h2>
      <p>消息功能正在准备中，不影响课程学习和进度记录。</p>
      <router-link to="/" class="btn btn-primary">
        回到课程目录 <span aria-hidden="true">→</span>
      </router-link>
    </section>
  </main>
</template>

<script setup lang="ts">
defineOptions({ name: 'MessagesView' });
</script>

<style scoped>
.account-page {
  display: grid;
  gap: 28px;
}
.account-head {
  padding: 18px 0 26px;
  border-bottom: 1px solid var(--line);
}
.account-head .eyebrow {
  margin-bottom: 16px;
}
.account-head .display {
  margin: 0 0 9px;
  color: var(--pine-deep);
}
.empty-state {
  display: grid;
  justify-items: start;
  gap: 12px;
  padding: 48px 6px 60px;
  border-bottom: 1px solid var(--line);
}
.empty-mark {
  color: var(--accent);
  font: 700 2.4rem var(--font-mono);
  line-height: 1;
}
.empty-state h2 {
  margin: 0;
  color: var(--pine-deep);
  font-size: 1.55rem;
}
.empty-state p {
  margin: 0 0 6px;
  color: var(--muted);
  font-size: 0.85rem;
}
</style>
