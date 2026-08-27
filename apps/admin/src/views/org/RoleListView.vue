<template>
  <section class="page">
    <header class="bar">
      <h2>角色管理</h2>
      <div class="actions">
        <el-button
          type="primary"
          @click="openCreate"
        >
          新增角色
        </el-button>
        <el-button @click="reload">
          刷新
        </el-button>
      </div>
    </header>

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
        label="名称"
        min-width="160"
      />
      <el-table-column
        prop="code"
        label="代码"
        min-width="160"
      />
      <el-table-column
        prop="data_scope"
        label="数据范围"
        width="160"
      >
        <template #default="{ row }">
          <el-tag effect="light">
            {{ scopeLabel(row.data_scope) }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column
        prop="permission_ids"
        label="权限数"
        width="100"
      >
        <template #default="{ row }">
          {{ row.permission_ids.length }}
        </template>
      </el-table-column>
      <el-table-column
        prop="status"
        label="状态"
        width="100"
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
            :type="row.status === 'enabled' ? 'warning' : 'success'"
            @click="toggleStatus(row)"
          >
            {{ row.status === 'enabled' ? '禁用' : '启用' }}
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
      width="720px"
    >
      <el-form
        :model="draft"
        label-position="top"
      >
        <el-form-item
          label="名称"
          required
        >
          <el-input
            v-model="draft.name"
            maxlength="64"
            placeholder="1–64 字"
          />
        </el-form-item>
        <el-form-item
          v-if="draft.id === null"
          label="代码"
          required
        >
          <el-input
            v-model="draft.code"
            placeholder="小写字母开头，仅 a-z 0-9 _ . -"
          />
        </el-form-item>
        <el-form-item
          label="数据范围"
          required
        >
          <el-select
            v-model="draft.data_scope"
            style="width: 100%"
          >
            <el-option
              v-for="opt in scopeOptions"
              :key="opt.value"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
        </el-form-item>
        <el-form-item
          v-if="draft.data_scope === 'specified_depts'"
          label="指定部门"
        >
          <el-select
            v-model="draft.scope_department_ids"
            multiple
            collapse-tags
            placeholder="选择部门"
            style="width: 100%"
          >
            <el-option
              v-for="d in departments"
              :key="d.id"
              :label="d.name"
              :value="d.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="权限">
          <div class="permission-tree">
            <section
              v-for="group in permissionTree"
              :key="group.key"
              class="permission-node permission-root"
            >
              <el-checkbox
                :data-permission-toggle="group.key"
                :model-value="isPermissionNodeChecked(group)"
                :indeterminate="isPermissionNodeIndeterminate(group)"
                @change="togglePermissionNode(group, $event)"
              >
                <span class="permission-node-label">{{ group.label }}</span>
                <span class="permission-count">
                  {{ selectedPermissionCount(group) }}/{{ permissionIdsForNode(group).length }}
                </span>
              </el-checkbox>

              <div
                v-if="group.items.length > 0 || group.children.length > 0"
                class="permission-children"
              >
                <el-checkbox-group
                  v-if="group.items.length"
                  v-model="draft.permission_ids"
                  class="permission-leaves"
                >
                  <el-checkbox
                    v-for="p in group.items"
                    :key="p.id"
                    :data-permission-leaf="group.key"
                    :value="p.id"
                  >
                    <span class="permission-label">{{ p.description }}</span>
                    <code class="permission-code">{{ p.code }}</code>
                  </el-checkbox>
                </el-checkbox-group>

                <section
                  v-for="child in group.children"
                  :key="child.key"
                  class="permission-node permission-child"
                >
                  <el-checkbox
                    :data-permission-toggle="child.key"
                    :model-value="isPermissionNodeChecked(child)"
                    :indeterminate="isPermissionNodeIndeterminate(child)"
                    @change="togglePermissionNode(child, $event)"
                  >
                    <span class="permission-node-label">{{ child.label }}</span>
                    <span class="permission-count">
                      {{ selectedPermissionCount(child) }}/{{ permissionIdsForNode(child).length }}
                    </span>
                  </el-checkbox>
                  <el-checkbox-group
                    v-if="child.items.length > 0 || child.children.length > 0"
                    v-model="draft.permission_ids"
                    class="permission-leaves"
                  >
                    <el-checkbox
                      v-for="p in child.items"
                      :key="p.id"
                      :data-permission-leaf="group.key"
                      :value="p.id"
                    >
                      <span class="permission-label">{{ p.description }}</span>
                      <code class="permission-code">{{ p.code }}</code>
                    </el-checkbox>
                  </el-checkbox-group>
                </section>
              </div>
            </section>
          </div>
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
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type {
  CreateRoleInput,
  DataScope,
  DepartmentDTO,
  PermissionDTO,
  RoleDTO,
  RoleStatus,
  UpdateRoleInput,
} from '@learn-site/contracts'
import {
  createRole,
  deleteRole,
  listDepartments,
  listPermissions,
  listRoles,
  setRoleStatus,
  updateRole,
} from '@/api/org'

const scopeOptions: { value: DataScope; label: string }[] = [
  { value: 'all', label: '全部' },
  { value: 'dept_and_children', label: '本部门及下级' },
  { value: 'specified_depts', label: '指定部门' },
  { value: 'dept', label: '本部门' },
  { value: 'self', label: '仅本人' },
]

const loading = ref(false)
const saving = ref(false)
const status = ref<'idle' | 'error'>('idle')
const errorMessage = ref('')
const rows = ref<RoleDTO[]>([])
const permissions = ref<PermissionDTO[]>([])
const departments = ref<DepartmentDTO[]>([])
const dialogVisible = ref(false)
const draft = reactive<{
  id: number | null
  name: string
  code: string
  data_scope: DataScope
  permission_ids: number[]
  scope_department_ids: number[]
}>({
  id: null,
  name: '',
  code: '',
  data_scope: 'self',
  permission_ids: [],
  scope_department_ids: [],
})

const dialogTitle = computed(() => (draft.id === null ? '新增角色' : '编辑角色'))

interface PermissionTreeNode {
  key: string
  label: string
  items: PermissionDTO[]
  children: PermissionTreeNode[]
}

interface PermissionTreeConfig {
  key: string
  label: string
  codes?: readonly string[]
  children?: readonly PermissionTreeConfig[]
}

// Keep this order and nesting aligned with AdminMenu.ts. Permissions that
// operate on a menu's detail page stay under that menu's parent.
const permissionTreeConfig: readonly PermissionTreeConfig[] = [
  { key: 'dashboard', label: '工作台', codes: ['dashboard.view'] },
  { key: 'categories', label: '分类管理', codes: ['category.manage'] },
  {
    key: 'courses',
    label: '课程管理',
    codes: ['course.view', 'course.manage', 'course.publish', 'course.delete', 'asset.upload'],
    children: [
      {
        key: 'course-students',
        label: '课程学员',
        codes: ['course_student.view', 'course_student.reset', 'course_student.revoke_free'],
      },
    ],
  },
  { key: 'qa', label: '问答管理', codes: ['qa.view', 'qa.answer'] },
  { key: 'reviews', label: '评价管理', codes: ['review.view', 'review.moderate'] },
  { key: 'maps', label: '学习地图', codes: ['map.view', 'map.manage', 'map.publish'] },
  { key: 'orders', label: '订单管理', codes: ['order.view'] },
  { key: 'learners', label: '学员账号', codes: ['learner.view', 'learner.reset_password', 'learner.kick'] },
  { key: 'site-profile', label: '站点资料', codes: ['site.manage'] },
  { key: 'audit', label: '审计日志', codes: ['audit.view'] },
  {
    key: 'organization',
    label: '组织管理',
    children: [
      { key: 'departments', label: '部门管理', codes: ['org.department'] },
      { key: 'posts', label: '岗位管理', codes: ['org.post'] },
      { key: 'roles', label: '角色管理', codes: ['org.role'] },
      { key: 'staff', label: '员工管理', codes: ['org.staff'] },
      { key: 'grants', label: '用户级权限覆盖', codes: ['org.grant'] },
    ],
  },
]

const permissionTree = computed<PermissionTreeNode[]>(() => {
  const byCode = new Map(permissions.value.map((permission) => [permission.code, permission]))
  const usedCodes = new Set<string>()

  function buildNode(config: PermissionTreeConfig): PermissionTreeNode {
    const items = (config.codes ?? []).flatMap((code) => {
      const permission = byCode.get(code)
      if (!permission) return []
      usedCodes.add(code)
      return [permission]
    })
    const children = (config.children ?? [])
      .map(buildNode)
      .filter((node) => node.items.length > 0 || node.children.length > 0)
    return { key: config.key, label: config.label, items, children }
  }

  const tree = permissionTreeConfig
    .map(buildNode)
    .filter((node) => node.items.length > 0 || node.children.length > 0)
  const extras = permissions.value.filter((permission) => !usedCodes.has(permission.code))
  if (extras.length > 0) {
    tree.push({ key: 'other', label: '其他权限', items: extras, children: [] })
  }
  return tree
})

function permissionIdsForNode(node: PermissionTreeNode): number[] {
  return [
    ...node.items.map((permission) => permission.id),
    ...node.children.flatMap(permissionIdsForNode),
  ]
}

function selectedPermissionCount(node: PermissionTreeNode): number {
  const selected = new Set(draft.permission_ids)
  return permissionIdsForNode(node).filter((id) => selected.has(id)).length
}

function isPermissionNodeChecked(node: PermissionTreeNode): boolean {
  const ids = permissionIdsForNode(node)
  return ids.length > 0 && selectedPermissionCount(node) === ids.length
}

function isPermissionNodeIndeterminate(node: PermissionTreeNode): boolean {
  const selected = selectedPermissionCount(node)
  return selected > 0 && selected < permissionIdsForNode(node).length
}

function togglePermissionNode(
  node: PermissionTreeNode,
  value: boolean | string | number,
): void {
  const selected = new Set(draft.permission_ids)
  for (const id of permissionIdsForNode(node)) {
    if (value) selected.add(id)
    else selected.delete(id)
  }
  draft.permission_ids = Array.from(selected)
}

function scopeLabel(scope: DataScope): string {
  return scopeOptions.find((o) => o.value === scope)?.label ?? scope
}

async function reload(): Promise<void> {
  loading.value = true
  status.value = 'idle'
  errorMessage.value = ''
  try {
    const out = await listRoles()
    rows.value = out.items
  } catch (err: unknown) {
    status.value = 'error'
    errorMessage.value = readError(err, '加载角色失败')
  } finally {
    loading.value = false
  }
}

async function loadSupportData(): Promise<void> {
  try {
    const [p, d] = await Promise.all([listPermissions(), listDepartments()])
    permissions.value = p.items
    departments.value = d.items.filter((x) => x.status === 'enabled')
  } catch {
    permissions.value = []
    departments.value = []
  }
}

function openCreate(): void {
  draft.id = null
  draft.name = ''
  draft.code = ''
  draft.data_scope = 'self'
  draft.permission_ids = []
  draft.scope_department_ids = []
  dialogVisible.value = true
}

function openEdit(row: RoleDTO): void {
  draft.id = row.id
  draft.name = row.name
  draft.code = row.code
  draft.data_scope = row.data_scope
  draft.permission_ids = [...row.permission_ids]
  draft.scope_department_ids = [...row.scope_department_ids]
  dialogVisible.value = true
}

async function save(): Promise<void> {
  if (!draft.name.trim()) {
    ElMessage.warning('请输入角色名称')
    return
  }
  saving.value = true
  try {
    if (draft.id === null) {
      const input: CreateRoleInput = {
        name: draft.name.trim(),
        code: draft.code.trim(),
        data_scope: draft.data_scope,
        permission_ids: draft.permission_ids,
        scope_department_ids:
          draft.data_scope === 'specified_depts'
            ? draft.scope_department_ids
            : [],
      }
      await createRole(input)
    } else {
      const input: UpdateRoleInput = {
        name: draft.name.trim(),
        data_scope: draft.data_scope,
        permission_ids: draft.permission_ids,
        scope_department_ids:
          draft.data_scope === 'specified_depts'
            ? draft.scope_department_ids
            : [],
      }
      await updateRole(draft.id, input)
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

async function toggleStatus(row: RoleDTO): Promise<void> {
  const next: RoleStatus = row.status === 'enabled' ? 'disabled' : 'enabled'
  try {
    await setRoleStatus(row.id, next)
    ElMessage.success(`已${next === 'enabled' ? '启用' : '禁用'}`)
    await reload()
  } catch (err: unknown) {
    ElMessage.error(readError(err, '状态切换失败'))
  }
}

async function onDelete(row: RoleDTO): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定删除角色「${row.name}」吗？`, '确认', {
      type: 'warning',
    })
  } catch {
    return
  }
  try {
    await deleteRole(row.id)
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
  if (code === 'ROLE_CODE_INVALID') return '代码格式错误，需小写字母开头、仅含 a-z 0-9 _ . -'
  if (code === 'ROLE_CODE_TAKEN') return '角色代码已存在'
  if (code === 'ROLE_SCOPE_INVALID') return '数据范围无效'
  if (code === 'ROLE_IN_USE') return '该角色仍有员工，无法删除'
  if (code === 'CONFLICT') return message ?? '角色冲突'
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
.table {
  width: 100%;
}
.permission-tree {
  display: grid;
  gap: 10px;
  max-height: 420px;
  overflow-y: auto;
  padding: 2px;
}
.permission-node {
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  background: #fff;
}
.permission-root {
  padding: 12px 14px;
}
.permission-child {
  border: 0;
  border-top: 1px solid #e2e8f0;
  border-radius: 0;
  margin-top: 10px;
  padding: 10px 0 0 14px;
}
.permission-node-label {
  color: #0f172a;
  font-weight: 600;
}
.permission-count {
  color: #64748b;
  font-size: 12px;
  margin-left: 8px;
}
.permission-children {
  border-left: 2px solid #dbeafe;
  margin: 8px 0 0 11px;
  padding-left: 14px;
}
.permission-leaves {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px 16px;
  margin-top: 8px;
}
.permission-label {
  color: #334155;
  font-size: 13px;
}
.permission-code {
  color: #94a3b8;
  font-size: 11px;
  margin-left: 8px;
}
@media (max-width: 720px) {
  .permission-leaves { grid-template-columns: minmax(0, 1fr); }
}
</style>
