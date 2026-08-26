<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { http } from '@/api/http'
import type { AuditLogDTO, AuditLogListDTO } from '@learn-site/contracts'

defineOptions({ name: 'AuditLogView' })

const list = ref<AuditLogListDTO | null>(null)
const loading = ref(false)
const errorMsg = ref<string | null>(null)

const filters = ref({
  action: '',
  target_type: '',
  actor_id: '' as string,
  page: 1,
  limit: 50,
})

const total = computed(() => list.value?.total ?? 0)
const totalPages = computed(() =>
  list.value ? Math.max(1, Math.ceil(list.value.total / list.value.limit)) : 1,
)

async function reload(): Promise<void> {
  loading.value = true
  errorMsg.value = null
  try {
    const params: Record<string, unknown> = {
      page: filters.value.page,
      limit: filters.value.limit,
    }
    if (filters.value.action) params.action = filters.value.action
    if (filters.value.target_type) params.target_type = filters.value.target_type
    if (filters.value.actor_id) {
      const n = Number(filters.value.actor_id)
      if (n > 0) params.actor_id = n
    }
    const { data } = await http.get('/audit', { params })
    const body = (data.data ?? data) as AuditLogListDTO
    list.value = body
  } catch (err) {
    errorMsg.value = (err as Error).message || 'load_failed'
  } finally {
    loading.value = false
  }
}

function parsePayload(row: AuditLogDTO): string {
  if (!row.payload_json) return ''
  try {
    const obj = JSON.parse(row.payload_json)
    return JSON.stringify(obj, null, 2)
  } catch {
    return row.payload_json
  }
}

onMounted(() => {
  void reload()
})
</script>

<template>
  <main class="page">
    <header class="head">
      <h1 class="display">审计日志</h1>
      <p class="muted">共 {{ total }} 条</p>
    </header>

    <section class="filters">
      <label>
        操作
        <input v-model="filters.action" type="text" placeholder="e.g. site.profile.update" />
      </label>
      <label>
        对象类型
        <input v-model="filters.target_type" type="text" placeholder="e.g. site_profile" />
      </label>
      <label>
        操作者 ID
        <input v-model="filters.actor_id" type="number" min="1" />
      </label>
      <button
        type="button"
        class="btn btn-primary"
        :disabled="loading"
        @click="(filters.page = 1, reload())"
      >
        查询
      </button>
    </section>

    <p v-if="errorMsg" class="error">{{ errorMsg }}</p>
    <p v-else-if="loading" class="notice">加载中…</p>

    <table v-else-if="list && list.items.length" class="data">
      <thead>
        <tr>
          <th>时间</th>
          <th>操作</th>
          <th>对象</th>
          <th>操作者</th>
          <th>数据</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list.items" :key="row.id">
          <td>{{ row.created_at }}</td>
          <td>{{ row.action }}</td>
          <td>{{ row.target_type }} {{ row.target_id ? `#${row.target_id}` : '' }}</td>
          <td>{{ row.actor_login || (row.actor_id ? `#${row.actor_id}` : '—') }}</td>
          <td><pre class="payload">{{ parsePayload(row) }}</pre></td>
        </tr>
      </tbody>
    </table>
    <p v-else class="empty">还没有审计记录.</p>

    <nav v-if="list && totalPages > 1" class="pager">
      <button
        type="button"
        :disabled="filters.page <= 1"
        @click="(filters.page -= 1, reload())"
      >
        上一页
      </button>
      <span>{{ filters.page }} / {{ totalPages }}</span>
      <button
        type="button"
        :disabled="filters.page >= totalPages"
        @click="(filters.page += 1, reload())"
      >
        下一页
      </button>
    </nav>
  </main>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.head { display: flex; align-items: baseline; justify-content: space-between; }
.display { margin: 0; font-size: 1.4rem; }
.muted { color: var(--color-text-muted, #5b6472); margin: 0; font-size: 0.85rem; }
.filters { display: flex; gap: 12px; align-items: end; flex-wrap: wrap; }
.filters label { display: grid; gap: 4px; font-size: 0.85rem; }
.filters input {
  padding: 6px 10px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  font: inherit;
}
.error { color: #b42318; margin: 0; }
.notice { color: var(--color-text-muted, #5b6472); margin: 0; }
.empty { color: var(--color-text-muted, #5b6472); margin: 0; }
.data { width: 100%; border-collapse: collapse; background: #fff; }
.data th,
.data td {
  padding: 8px 10px;
  border-bottom: 1px solid var(--color-border, #e3e6ee);
  font-size: 0.85rem;
  text-align: left;
  vertical-align: top;
}
.data th { background: var(--color-bg-soft, #fafbfd); }
.payload {
  margin: 0;
  padding: 6px 8px;
  background: var(--color-bg-soft, #fafbfd);
  border-radius: 4px;
  font-size: 0.75rem;
  max-width: 360px;
  white-space: pre-wrap;
  word-break: break-all;
}
.btn {
  padding: 6px 14px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  background: #fff;
  font: inherit;
  cursor: pointer;
}
.btn-primary { background: var(--color-primary, #2563eb); color: #fff; border-color: transparent; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.pager { display: flex; gap: 12px; align-items: center; justify-content: flex-end; }
</style>