<template>
  <section class="page">
    <header class="bar">
      <h2>岗位管理</h2>
      <div class="actions">
        <el-button
          type="primary"
          :disabled="departments.length === 0"
          @click="openCreate"
        >
          新增岗位
        </el-button>
        <el-button @click="reload">
          刷新
        </el-button>
      </div>
    </header>

    <div class="filters">
      <el-select
        v-model="filterDept"
        clearable
        placeholder="按部门筛选"
        style="width: 240px"
        @change="reload"
      >
        <el-option
          v-for="d in departments"
          :key="d.id"
          :label="d.name"
          :value="d.id"
        />
      </el-select>
      <el-select
        v-model="filterStatus"
        clearable
        placeholder="按状态筛选"
        style="width: 160px"
        @change="reload"
      >
        <el-option
          label="启用"
          value="enabled"
        />
        <el-option
          label="禁用"
          value="disabled"
        />
      </el-select>
    </div>

    <el-alert
      v-if="status === 'error'"
      :title="errorMessage"
      type="error"
      show-icon
      :closable="false"
    />

    <el-table
      v-loading="loading"
      :data="rows"
      stripe
      row-key="id"
      class="table"
    >
      <el-table-column
        prop="name"
        label="岗位名称"
        min-width="220"
      />
      <el-table-column
        prop="department_name"
        label="所属部门"
        min-width="200"
      />
      <el-table-column
        label="默认角色"
        min-width="220"
      >
        <template #default="{ row }">
          <div class="role-tags">
            <el-tag
              v-for="name in roleNames(row.role_ids)"
              :key="name"
              effect="plain"
            >
              {{ name }}
            </el-tag>
            <span v-if="row.role_ids.length === 0" class="muted">未绑定</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column
        prop="status"
        label="状态"
        width="120"
      >
        <template #default="{ row }">
          <el-tag
            :type="row.status === 'enabled' ? 'success' : 'info'"
            effect="light"
          >
            {{ row.status === 'enabled' ? '启用' : '禁用' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column
        label="操作"
        width="260"
        fixed="right"
      >
        <template #default="{ row }">
          <el-button
            link
            type="primary"
            @click="openEdit(row)"
          >
            编辑
          </el-button>
          <el-button
            link
            type="danger"
            @click="onDelete(row)"
          >
            删除
          </el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="560px"
    >
      <el-form
        :model="draft"
        label-position="top"
      >
        <el-form-item
          label="所属部门"
          required
        >
          <el-select
            v-model="draft.department_id"
            placeholder="选择部门"
            style="width: 100%"
            :disabled="draft.id !== null"
          >
            <el-option
              v-for="d in departments"
              :key="d.id"
              :label="d.name"
              :value="d.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item
          label="岗位名称"
          required
        >
          <el-input
            v-model="draft.name"
            maxlength="64"
            placeholder="1–64 字"
          />
        </el-form-item>
        <el-form-item label="默认角色">
          <el-select
            v-model="draft.role_ids"
            multiple
            collapse-tags
            placeholder="选择岗位默认角色"
            style="width: 100%"
          >
            <el-option
              v-for="role in roles"
              :key="role.id"
              :label="role.name"
              :value="role.id"
              :disabled="role.status !== 'enabled'"
            />
          </el-select>
        </el-form-item>
        <el-form-item
          v-if="draft.id === null"
          label="初始状态"
        >
          <el-select v-model="draft.status">
            <el-option
              label="启用"
              value="enabled"
            />
            <el-option
              label="禁用"
              value="disabled"
            />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">
          取消
        </el-button>
        <el-button
          type="primary"
          :loading="saving"
          @click="save"
        >
          保存
        </el-button>
      </template>
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type {
  CreatePostInput,
  DepartmentDTO,
  PostDTO,
  PostStatus,
  RoleDTO,
  UpdatePostInput,
} from '@learn-site/contracts'
import {
  createPost,
  deletePost,
  listDepartments,
  listPosts,
  listRoles,
  updatePost,
} from '@/api/org'

const loading = ref(false)
const saving = ref(false)
const status = ref<'idle' | 'error'>('idle')
const errorMessage = ref('')
const rows = ref<PostDTO[]>([])
const departments = ref<DepartmentDTO[]>([])
const roles = ref<RoleDTO[]>([])
const filterDept = ref<number | null>(null)
const filterStatus = ref<PostStatus | ''>('')
const dialogVisible = ref(false)
const draft = reactive<{
  id: number | null
  department_id: number | null
  name: string
  status: PostStatus
  role_ids: number[]
}>({
  id: null,
  department_id: null,
  name: '',
  status: 'enabled',
  role_ids: [],
})

const dialogTitle = ref('新增岗位')

async function reload(): Promise<void> {
  loading.value = true
  status.value = 'idle'
  errorMessage.value = ''
  try {
    const out = await listPosts({
      ...(filterDept.value === null ? {} : { department_id: filterDept.value }),
      ...(filterStatus.value === '' ? {} : { status: filterStatus.value }),
    })
    rows.value = out.items
  } catch (err: unknown) {
    status.value = 'error'
    errorMessage.value = readError(err, '加载岗位失败')
  } finally {
    loading.value = false
  }
}

async function loadSupportData(): Promise<void> {
  try {
    const [departmentResult, roleResult] = await Promise.all([
      listDepartments(),
      listRoles(),
    ])
    departments.value = departmentResult.items.filter((d) => d.status === 'enabled')
    roles.value = roleResult.items
  } catch {
    departments.value = []
    roles.value = []
  }
}

function roleNames(ids: number[]): string[] {
  const names = new Map(roles.value.map((role) => [role.id, role.name]))
  return ids.map((id) => names.get(id) ?? `#${id}`)
}

function openCreate(): void {
  draft.id = null
  draft.department_id = filterDept.value ?? departments.value[0]?.id ?? null
  draft.name = ''
  draft.status = 'enabled'
  draft.role_ids = []
  dialogTitle.value = '新增岗位'
  dialogVisible.value = true
}

function openEdit(row: PostDTO): void {
  draft.id = row.id
  draft.department_id = row.department_id
  draft.name = row.name
  draft.status = row.status
  draft.role_ids = [...row.role_ids]
  dialogTitle.value = '编辑岗位'
  dialogVisible.value = true
}

async function save(): Promise<void> {
  if (!draft.department_id || draft.department_id <= 0) {
    ElMessage.warning('请选择所属部门')
    return
  }
  if (!draft.name.trim()) {
    ElMessage.warning('请输入岗位名称')
    return
  }
  saving.value = true
  try {
    if (draft.id === null) {
      const input: CreatePostInput = {
        department_id: draft.department_id,
        name: draft.name.trim(),
        status: draft.status,
        role_ids: draft.role_ids,
      }
      await createPost(input)
    } else {
      const input: UpdatePostInput = {
        name: draft.name.trim(),
        status: draft.status,
        role_ids: draft.role_ids,
      }
      await updatePost(draft.id, input)
    }
    dialogVisible.value = false
    ElMessage.success('已保存')
    await reload()
  } catch (err: unknown) {
    ElMessage.error(readError(err, '保存失败'))
  } finally {
    saving.value = false
  }
}

async function onDelete(row: PostDTO): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定删除岗位「${row.name}」吗？`, '确认', {
      type: 'warning',
    })
  } catch {
    return
  }
  try {
    await deletePost(row.id)
    ElMessage.success('已删除')
    await reload()
  } catch (err: unknown) {
    ElMessage.error(readError(err, '删除失败'))
  }
}

function readError(err: unknown, fallback: string): string {
  const code = (err as { response?: { data?: { error?: { code?: string; message?: string } } } })
    ?.response?.data?.error?.code
  const message = (err as { response?: { data?: { error?: { message?: string } } } })
    ?.response?.data?.error?.message
  if (code === 'POST_DEPARTMENT_INVALID') return '所属部门无效'
  if (code === 'POST_IN_USE') return '该岗位仍有员工，无法删除'
  if (code === 'POST_ROLE_INVALID') return '岗位角色无效或已停用'
  if (code === 'PERMISSION_NOT_HELD') return '不能绑定当前账号未持有的权限'
  if (code === 'SELF_GUARD') return '不能修改自己当前岗位的默认角色'
  if (code === 'CONFLICT') return message ?? '岗位冲突'
  if (code === 'VALIDATION_FAILED') return message ?? '校验失败'
  return fallback
}

onMounted(() => {
  void loadSupportData()
  void reload()
})
</script>

<style scoped>
.page {
  background: #ffffff;
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
}
.bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}
.bar h2 {
  margin: 0;
  font-size: 18px;
  color: #0f172a;
}
.actions {
  display: flex;
  gap: 8px;
}
.filters {
  display: flex;
  gap: 12px;
  margin-bottom: 12px;
}
.table {
  width: 100%;
}
.role-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.muted {
  color: #94a3b8;
}
</style>
