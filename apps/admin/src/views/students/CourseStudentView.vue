<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  listCourseStudents,
  revokeCourseStudent,
  type CourseStudentDTO,
  type CourseStudentListDTO,
} from '@/api/courseStudents'

defineOptions({ name: 'CourseStudentView' })

const route = useRoute()
const courseId = computed(() => Number(route.params.id))

const list = ref<CourseStudentListDTO | null>(null)
const loading = ref(false)
const errorMsg = ref<string | null>(null)
const submittingId = ref<number | null>(null)

const filters = ref({
  status: '' as '' | 'active' | 'revoked',
  page: 1,
  limit: 20,
})

const total = computed(() => list.value?.total ?? 0)
const totalPages = computed(() =>
  list.value ? Math.max(1, Math.ceil(list.value.total / list.value.limit)) : 1,
)

async function reload(): Promise<void> {
  loading.value = true
  errorMsg.value = null
  try {
    const params: {
      status?: 'active' | 'revoked'
      page: number
      limit: number
    } = { page: filters.value.page, limit: filters.value.limit }
    if (filters.value.status) params.status = filters.value.status
    list.value = await listCourseStudents(courseId.value, params)
  } catch (err) {
    errorMsg.value = (err as Error).message || 'load_failed'
  } finally {
    loading.value = false
  }
}

watch(courseId, () => {
  filters.value.page = 1
  void reload()
})

async function revoke(row: CourseStudentDTO): Promise<void> {
  if (submittingId.value !== null) return
  if (row.entitlement_status !== 'active') return
  if (row.source !== 'free') {
    errorMsg.value = '仅免费授权可在此撤销, 付费授权走退款流程.'
    return
  }
  const reason = prompt(`撤销 ${row.login} 的免费授权, 请填写原因：`, 'admin_revoke')
  if (reason === null) return
  submittingId.value = row.account_id
  try {
    await revokeCourseStudent(courseId.value, row.account_id, reason)
    await reload()
  } catch (err) {
    errorMsg.value = (err as Error).message || 'revoke_failed'
  } finally {
    submittingId.value = null
  }
}

onMounted(() => {
  void reload()
})

function sourceLabel(src: CourseStudentDTO['source']): string {
  return src === 'free' ? '免费授权' : '购买'
}
function entitlementLabel(s: CourseStudentDTO['entitlement_status']): string {
  return s === 'active' ? '有效' : '已撤销'
}
</script>

<template>
  <main class="page">
    <header class="head">
      <h1 class="display">课程 {{ courseId }} · 学员名单</h1>
      <p class="muted">共 {{ total }} 人</p>
    </header>

    <section class="filters">
      <label>
        授权状态
        <select v-model="filters.status">
          <option value="">全部</option>
          <option value="active">有效</option>
          <option value="revoked">已撤销</option>
        </select>
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
          <th>账号</th>
          <th>姓名</th>
          <th>部门</th>
          <th>账号状态</th>
          <th>来源</th>
          <th>授权</th>
          <th>最近登录</th>
          <th>授权时间</th>
          <th>撤销时间</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list.items" :key="row.account_id">
          <td>{{ row.login }}</td>
          <td>{{ row.display_name }}</td>
          <td>{{ row.department_name || '—' }}</td>
          <td>
            <span :data-status="row.account_status">{{ row.account_status }}</span>
          </td>
          <td>{{ sourceLabel(row.source) }}</td>
          <td>
            <span :data-entitlement="row.entitlement_status">{{ entitlementLabel(row.entitlement_status) }}</span>
          </td>
          <td>{{ row.last_login_at || '—' }}</td>
          <td>{{ row.enrolled_at }}</td>
          <td>{{ row.revoked_at || '—' }}</td>
          <td class="actions">
            <button
              v-if="row.entitlement_status === 'active' && row.source === 'free'"
              type="button"
              class="btn warn"
              :disabled="submittingId === row.account_id"
              @click="revoke(row)"
            >
              撤销授权
            </button>
            <span v-else class="muted">—</span>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-else class="empty">还没有学员选修这门课.</p>

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
.filters select {
  padding: 6px 8px; border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px; font: inherit;
}
.error { color: #b42318; margin: 0; }
.notice { color: var(--color-text-muted, #5b6472); margin: 0; }
.empty { color: var(--color-text-muted, #5b6472); margin: 0; }
.data { width: 100%; border-collapse: collapse; background: #fff; }
.data th,
.data td {
  padding: 8px 10px; border-bottom: 1px solid var(--color-border, #e3e6ee);
  font-size: 0.9rem; text-align: left;
}
.data th { background: var(--color-bg-soft, #fafbfd); }
[data-status='active'] { color: #137a3c; }
[data-status='disabled'] { color: #b42318; }
[data-entitlement='active'] { color: #137a3c; }
[data-entitlement='revoked'] { color: #b42318; }
.actions { display: flex; gap: 6px; }
.btn.warn { color: #b42318; border-color: #f3c1bb; background: #fff5f3; }
.btn {
  padding: 6px 12px; border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px; background: #fff; font: inherit; cursor: pointer;
}
.btn-primary { background: var(--color-primary, #2563eb); color: #fff; border-color: transparent; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.pager { display: flex; gap: 12px; align-items: center; justify-content: flex-end; }
</style>