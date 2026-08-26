<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  kickLearner,
  listLearners,
  resetLearnerPassword,
  type LearnerAccountDTO,
  type LearnerListDTO,
} from '@/api/learners'

defineOptions({ name: 'LearnerListView' })

const list = ref<LearnerListDTO | null>(null)
const loading = ref(false)
const errorMsg = ref<string | null>(null)
const submittingId = ref<number | null>(null)
const resetDialog = ref<{ account: LearnerAccountDTO; newPassword: string } | null>(null)

const filters = ref({
  status: '' as '' | 'active' | 'disabled',
  search: '',
  department_id: '' as string,
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
      status?: 'active' | 'disabled'
      search?: string
      department_id?: number
      page: number
      limit: number
    } = { page: filters.value.page, limit: filters.value.limit }
    if (filters.value.status) params.status = filters.value.status
    if (filters.value.search) params.search = filters.value.search
    if (filters.value.department_id) {
      const n = Number(filters.value.department_id)
      if (n > 0) params.department_id = n
    }
    list.value = await listLearners(params)
  } catch (err) {
    errorMsg.value = (err as Error).message || 'load_failed'
  } finally {
    loading.value = false
  }
}

async function doKick(account: LearnerAccountDTO): Promise<void> {
  if (submittingId.value !== null) return
  if (!confirm(`强制下线 ${account.login} 的所有会话？此操作不可撤销。`)) return
  submittingId.value = account.account_id
  try {
    await kickLearner(account.account_id)
    await reload()
  } catch (err) {
    errorMsg.value = (err as Error).message || 'kick_failed'
  } finally {
    submittingId.value = null
  }
}

function openReset(account: LearnerAccountDTO): void {
  resetDialog.value = { account, newPassword: '' }
}

async function submitReset(): Promise<void> {
  const dlg = resetDialog.value
  if (!dlg) return
  if (dlg.newPassword.length < 8 || dlg.newPassword.length > 72) {
    errorMsg.value = '密码长度需在 8–72 之间'
    return
  }
  submittingId.value = dlg.account.account_id
  try {
    await resetLearnerPassword(dlg.account.account_id, dlg.newPassword)
    resetDialog.value = null
    await reload()
  } catch (err) {
    errorMsg.value = (err as Error).message || 'reset_failed'
  } finally {
    submittingId.value = null
  }
}

onMounted(() => {
  void reload()
})
</script>

<template>
  <main class="page">
    <header class="head">
      <h1 class="display">学员账号</h1>
      <p class="muted">共 {{ total }} 条</p>
    </header>

    <section class="filters">
      <label>
        状态
        <select v-model="filters.status">
          <option value="">全部</option>
          <option value="active">正常</option>
          <option value="disabled">已停用</option>
        </select>
      </label>
      <label>
        关键字
        <input
          v-model="filters.search"
          type="search"
          placeholder="账号或姓名"
          @keyup.enter="(filters.page = 1, reload())"
        />
      </label>
      <label>
        部门 ID
        <input v-model="filters.department_id" type="number" min="1" />
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
          <th>状态</th>
          <th>最近登录</th>
          <th>创建时间</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in list.items" :key="row.account_id">
          <td>{{ row.login }}</td>
          <td>{{ row.display_name }}</td>
          <td>{{ row.department_name || '—' }}</td>
          <td>
            <span :data-status="row.status">{{ row.status }}</span>
          </td>
          <td>{{ row.last_login_at || '—' }}</td>
          <td>{{ row.created_at }}</td>
          <td class="actions">
            <button
              type="button"
              class="btn"
              :disabled="submittingId === row.account_id"
              @click="openReset(row)"
            >
              重置密码
            </button>
            <button
              type="button"
              class="btn warn"
              :disabled="submittingId === row.account_id"
              @click="doKick(row)"
            >
              强制下线
            </button>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-else class="empty">没有匹配的学员.</p>

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

    <div v-if="resetDialog" class="modal-backdrop" @click.self="resetDialog = null">
      <div class="modal">
        <h2>重置 {{ resetDialog.account.login }} 的密码</h2>
        <label>
          新密码 (8–72)
          <input v-model="resetDialog.newPassword" type="text" minlength="8" maxlength="72" />
        </label>
        <p class="muted">重置后所有会话会失效.</p>
        <div class="modal-actions">
          <button type="button" class="btn" @click="resetDialog = null">取消</button>
          <button
            type="button"
            class="btn btn-primary"
            :disabled="submittingId === resetDialog.account.account_id"
            @click="submitReset"
          >
            确认重置
          </button>
        </div>
      </div>
    </div>
  </main>
</template>

<style scoped>
.page { display: grid; gap: 16px; }
.head { display: flex; align-items: baseline; justify-content: space-between; }
.display { margin: 0; font-size: 1.4rem; }
.muted { color: var(--color-text-muted, #5b6472); margin: 0; font-size: 0.85rem; }
.filters { display: flex; gap: 12px; align-items: end; flex-wrap: wrap; }
.filters label { display: grid; gap: 4px; font-size: 0.85rem; }
.filters input,
.filters select {
  padding: 6px 8px;
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
  font-size: 0.9rem;
  text-align: left;
}
.data th { background: var(--color-bg-soft, #fafbfd); }
[data-status='active'] { color: #137a3c; }
[data-status='disabled'] { color: #b42318; }
.actions { display: flex; gap: 6px; }
.btn {
  padding: 4px 10px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  background: #fff;
  font: inherit;
  cursor: pointer;
}
.btn-primary { background: var(--color-primary, #2563eb); color: #fff; border-color: transparent; }
.btn.warn { color: #b42318; border-color: #f3c1bb; background: #fff5f3; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.pager { display: flex; gap: 12px; align-items: center; justify-content: flex-end; }
.modal-backdrop {
  position: fixed; inset: 0; background: rgba(0, 0, 0, 0.35);
  display: flex; align-items: center; justify-content: center; z-index: 100;
}
.modal {
  background: #fff; padding: 20px 24px; border-radius: 8px;
  min-width: 320px; display: grid; gap: 12px;
}
.modal h2 { margin: 0; font-size: 1.1rem; }
.modal label { display: grid; gap: 4px; font-size: 0.85rem; }
.modal input {
  padding: 6px 8px; border: 1px solid var(--color-border, #d0d4dc); border-radius: 6px; font: inherit;
}
.modal-actions { display: flex; gap: 8px; justify-content: flex-end; }
</style>