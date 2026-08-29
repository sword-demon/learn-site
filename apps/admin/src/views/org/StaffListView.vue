<template>
  <section class="page">
    <header class="bar">
      <h2>员工管理</h2>
      <div class="actions">
        <el-button type="primary" @click="openCreate"> 新增员工 </el-button>
        <el-button @click="reload"> 刷新 </el-button>
      </div>
    </header>

    <div class="filters">
      <el-select
        v-model="filterStatus"
        clearable
        placeholder="按状态筛选"
        style="width: 160px"
        @change="reload"
      >
        <el-option label="启用" value="active" />
        <el-option label="禁用" value="disabled" />
      </el-select>
    </div>

    <el-alert
      v-if="status === 'error'"
      :title="errorMessage"
      type="error"
      show-icon
      :closable="false"
    />

    <el-table v-loading="loading" :data="rows" stripe row-key="account_id" class="table">
      <el-table-column prop="login" label="登录名" min-width="140" />
      <el-table-column prop="display_name" label="姓名" min-width="140" />
      <el-table-column label="部门" min-width="160">
        <template #default="{ row }">
          <span :class="{ dim: row.department_status === 'disabled' }">
            {{ row.department_name || '—' }}
          </span>
        </template>
      </el-table-column>
      <el-table-column label="角色" width="80">
        <template #default="{ row }">
          {{ rolesCount(row.account_id) }}
        </template>
      </el-table-column>
      <el-table-column label="超级管理员" width="120">
        <template #default="{ row }">
          <el-tag v-if="row.is_super_admin" type="danger" effect="dark"> 超级管理员 </el-tag>
          <span v-else>—</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag :type="row.account_status === 'active' ? 'success' : 'info'" effect="light">
            {{ row.account_status === 'active' ? '启用' : '禁用' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="308" fixed="right" class-name="actions-column">
        <template #default="{ row }">
          <div class="row-actions">
            <el-button class="action-btn" link type="primary" @click="openEdit(row)"> 编辑 </el-button>
            <el-button
              v-if="canManageOverrides"
              class="action-btn"
              link
              type="info"
              @click="goOverrides(row)"
            >
              权限覆盖
            </el-button>
            <el-button
              class="action-btn"
              link
              :type="row.account_status === 'active' ? 'warning' : 'success'"
              @click="toggleStatus(row)"
            >
              {{ row.account_status === 'active' ? '禁用' : '启用' }}
            </el-button>
            <el-dropdown
              class="action-btn"
              trigger="click"
              @command="(cmd: 'family' | 'all') => handleKick(cmd, row)"
            >
              <el-button class="action-btn__trigger" link> 踢下线 </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="family">按家族</el-dropdown-item>
                  <el-dropdown-item command="all">全部会话</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
            <el-button class="action-btn" link type="danger" @click="onDelete(row)"> 删除 </el-button>
          </div>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialogVisible" :title="dialogTitle" width="640px">
      <el-form :model="draft" label-position="top">
        <el-form-item v-if="draft.id === null" label="登录名" required>
          <el-input
            v-model="draft.login"
            clearable
            maxlength="64"
            placeholder="3–64 字,不可为 11 位手机号"
          />
        </el-form-item>
        <el-form-item v-if="draft.id === null" label="初始密码" required>
          <el-input
            v-model="draft.password"
            clearable
            type="password"
            show-password
            placeholder="8–72 字"
          />
        </el-form-item>
        <el-form-item label="姓名" required>
          <el-input v-model="draft.display_name" clearable maxlength="64" placeholder="1–64 字" />
        </el-form-item>
        <el-form-item label="部门" :required="!draft.is_super_admin">
          <el-select
            v-model="draft.department_id"
            clearable
            placeholder="选择部门"
            style="width: 100%"
            @change="onDepartmentChange"
          >
            <el-option v-for="d in departments" :key="d.id" :label="d.name" :value="d.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="超级管理员">
          <el-switch v-model="draft.is_super_admin" :disabled="!canManageSuperAdmin" />
          <span class="hint"> 需当前操作者已是超级管理员 </span>
        </el-form-item>
        <el-form-item label="角色">
          <el-select
            v-model="draft.role_ids"
            clearable
            multiple
            collapse-tags
            placeholder="选择角色"
            style="width: 100%"
          >
            <el-option
              v-for="r in roles"
              :key="r.id"
              :label="r.name"
              :value="r.id"
              :disabled="r.status !== 'enabled'"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="岗位">
          <el-select
            v-model="draft.post_ids"
            clearable
            multiple
            collapse-tags
            placeholder="选择岗位"
            style="width: 100%"
          >
            <el-option
              v-for="p in availablePosts"
              :key="p.id"
              :label="`${p.department_name} · ${p.name}`"
              :value="p.id"
              :disabled="p.status !== 'enabled'"
            />
          </el-select>
        </el-form-item>
        <template v-if="draft.id !== null">
          <el-form-item label="重置密码">
            <el-switch v-model="draft.reset_password" />
          </el-form-item>
          <el-form-item v-if="draft.reset_password" label="新密码" required>
            <el-input
              v-model="draft.new_password"
              clearable
              type="password"
              show-password
              placeholder="8–72 字"
            />
          </el-form-item>
        </template>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false"> 取消 </el-button>
        <el-button type="primary" :loading="saving" @click="save"> 保存 </el-button>
      </template>
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import type {
  CreateStaffInput,
  DepartmentDTO,
  PostDTO,
  RoleDTO,
  StaffDTO,
  UpdateStaffInput,
} from '@learn-site/contracts';
import {
  createStaff,
  deleteStaff,
  getStaff,
  kickStaff,
  listDepartments,
  listPosts,
  listRoles,
  listStaff,
  setStaffStatus,
  updateStaff,
} from '@/api/org';
import { permissionCodes } from '@/api/http';

const loading = ref(false);
const saving = ref(false);
const status = ref<'idle' | 'error'>('idle');
const errorMessage = ref('');
const rows = ref<StaffDTO[]>([]);
const roles = ref<RoleDTO[]>([]);
const posts = ref<PostDTO[]>([]);
const departments = ref<DepartmentDTO[]>([]);
const filterStatus = ref<StaffDTO['account_status'] | ''>('');
const roleCountCache = new Map<number, number>();
const dialogVisible = ref(false);
const draft = reactive<{
  id: number | null;
  login: string;
  password: string;
  display_name: string;
  is_super_admin: boolean;
  department_id: number | null;
  role_ids: number[];
  post_ids: number[];
  reset_password: boolean;
  new_password: string;
}>({
  id: null,
  login: '',
  password: '',
  display_name: '',
  is_super_admin: false,
  department_id: null,
  role_ids: [],
  post_ids: [],
  reset_password: false,
  new_password: '',
});

const dialogTitle = ref('新增员工');
const canManageSuperAdmin = computed(() => permissionCodes().includes('*'));
const canManageOverrides = computed(
  () => permissionCodes().includes('*') || permissionCodes().includes('org.grant'),
);
const availablePosts = computed(() => {
  if (draft.department_id === null) return [];
  return posts.value.filter((post) => post.department_id === draft.department_id);
});

const router = useRouter();
function goOverrides(row: StaffDTO): void {
  router.push({ name: 'org-staff-overrides', params: { id: String(row.account_id) } });
}

function rolesCount(id: number): number {
  return roleCountCache.get(id) ?? 0;
}

function onDepartmentChange(): void {
  const allowed = new Set(availablePosts.value.map((post) => post.id));
  draft.post_ids = draft.post_ids.filter((id) => allowed.has(id));
}

async function reload(): Promise<void> {
  loading.value = true;
  status.value = 'idle';
  errorMessage.value = '';
  try {
    const out = await listStaff({
      ...(filterStatus.value === '' ? {} : { status: filterStatus.value }),
    });
    rows.value = out.items;
    roleCountCache.clear();
    void loadRoleCounts();
  } catch (err: unknown) {
    status.value = 'error';
    errorMessage.value = readError(err, '加载员工失败');
  } finally {
    loading.value = false;
  }
}

async function loadRoleCounts(): Promise<void> {
  await Promise.all(
    rows.value.map(async (r) => {
      try {
        const detail = await getStaff(r.account_id);
        roleCountCache.set(r.account_id, detail.roles.length);
      } catch {
        roleCountCache.set(r.account_id, 0);
      }
    }),
  );
}

async function loadSupportData(): Promise<void> {
  try {
    const [r, p, d] = await Promise.all([listRoles(), listPosts(), listDepartments()]);
    roles.value = r.items;
    posts.value = p.items;
    departments.value = d.items.filter((x) => x.status === 'enabled');
  } catch {
    roles.value = [];
    posts.value = [];
    departments.value = [];
  }
}

function openCreate(): void {
  draft.id = null;
  draft.login = '';
  draft.password = '';
  draft.display_name = '';
  draft.is_super_admin = false;
  draft.department_id = null;
  draft.role_ids = [];
  draft.post_ids = [];
  draft.reset_password = false;
  draft.new_password = '';
  dialogTitle.value = '新增员工';
  dialogVisible.value = true;
}

async function openEdit(row: StaffDTO): Promise<void> {
  dialogTitle.value = '编辑员工';
  draft.id = row.account_id;
  draft.login = row.login;
  draft.password = '';
  draft.display_name = row.display_name;
  draft.is_super_admin = row.is_super_admin;
  draft.department_id = row.department_id;
  draft.role_ids = [];
  draft.post_ids = [];
  draft.reset_password = false;
  draft.new_password = '';
  dialogVisible.value = true;
  try {
    const detail = await getStaff(row.account_id);
    draft.role_ids = detail.roles;
    draft.post_ids = detail.posts;
  } catch (err: unknown) {
    ElMessage.error(readError(err, '加载员工详情失败'));
  }
}

async function save(): Promise<void> {
  if (!draft.is_super_admin && draft.department_id === null) {
    ElMessage.warning('普通员工必须选择启用中的部门');
    return;
  }
  const allowedPostIds = new Set(availablePosts.value.map((post) => post.id));
  if (draft.post_ids.some((id) => !allowedPostIds.has(id))) {
    ElMessage.warning('岗位必须属于员工当前部门');
    return;
  }
  saving.value = true;
  try {
    if (draft.id === null) {
      if (!draft.login.trim()) {
        ElMessage.warning('请输入登录名');
        saving.value = false;
        return;
      }
      if (/^1[3-9]\d{9}$/.test(draft.login.trim())) {
        ElMessage.warning('登录名不能是 11 位手机号');
        saving.value = false;
        return;
      }
      if (draft.password.length < 8 || draft.password.length > 72) {
        ElMessage.warning('密码长度需在 8–72 之间');
        saving.value = false;
        return;
      }
      const input: CreateStaffInput = {
        login: draft.login.trim(),
        password: draft.password,
        display_name: draft.display_name.trim(),
        is_super_admin: draft.is_super_admin,
        department_id: draft.department_id,
        role_ids: draft.role_ids,
        post_ids: draft.post_ids,
      };
      await createStaff(input);
    } else {
      const input: UpdateStaffInput = {
        display_name: draft.display_name.trim(),
        is_super_admin: draft.is_super_admin,
        department_id: draft.department_id,
        role_ids: draft.role_ids,
        post_ids: draft.post_ids,
        reset_password: draft.reset_password,
        new_password: draft.reset_password ? draft.new_password : undefined,
      };
      await updateStaff(draft.id, input);
    }
    dialogVisible.value = false;
    ElMessage.success('已保存');
    await reload();
  } catch (err: unknown) {
    ElMessage.error(readError(err, '保存失败'));
  } finally {
    saving.value = false;
  }
}

async function toggleStatus(row: StaffDTO): Promise<void> {
  const next = row.account_status === 'active' ? 'disabled' : 'active';
  try {
    await setStaffStatus(row.account_id, next);
    ElMessage.success(`已${next === 'active' ? '启用' : '禁用'}`);
    await reload();
  } catch (err: unknown) {
    ElMessage.error(readError(err, '状态切换失败'));
  }
}

async function onDelete(row: StaffDTO): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定删除员工「${row.display_name || row.login}」吗？`, '确认', {
      type: 'warning',
    });
  } catch {
    return;
  }
  try {
    await deleteStaff(row.account_id);
    ElMessage.success('已删除');
    await reload();
  } catch (err: unknown) {
    ElMessage.error(readError(err, '删除失败'));
  }
}

async function askKickAll(row: StaffDTO): Promise<void> {
  try {
    await ElMessageBox.confirm(`将踢下线「${row.login}」的所有会话,继续？`, '确认', {
      type: 'warning',
    });
  } catch {
    return;
  }
  try {
    const out = await kickStaff(row.account_id);
    ElMessage.success(`已吊销 ${out.revoked} 个会话`);
  } catch (err: unknown) {
    ElMessage.error(readError(err, '踢下线失败'));
  }
}

async function askKickFamily(row: StaffDTO): Promise<void> {
  let family = '';
  try {
    const out = await ElMessageBox.prompt(
      '请输入要吊销的家族 ID（留空将吊销全部家族）',
      '踢下线-家族',
      {
        inputPattern: /^.{0,64}$/,
        inputValue: '',
      },
    );
    family = (out.value ?? '').trim();
  } catch {
    return;
  }
  try {
    const out = await kickStaff(row.account_id, family || undefined);
    ElMessage.success(`已吊销 ${out.revoked} 个会话`);
  } catch (err: unknown) {
    ElMessage.error(readError(err, '踢下线失败'));
  }
}

function handleKick(command: 'family' | 'all', row: StaffDTO): void {
  if (command === 'family') void askKickFamily(row);
  if (command === 'all') void askKickAll(row);
}

function readError(err: unknown, fallback: string): string {
  const code = (err as { response?: { data?: { error?: { code?: string; message?: string } } } })
    ?.response?.data?.error?.code;
  const message = (err as { response?: { data?: { error?: { message?: string } } } })?.response
    ?.data?.error?.message;
  if (code === 'LAST_SUPER_ADMIN') return '系统至少保留一名超级管理员';
  if (code === 'SELF_GUARD') return '不能对自己执行该操作';
  if (code === 'NOT_SUPER_ADMIN') return '仅超级管理员可执行该操作';
  if (code === 'STAFF_LOGIN_TAKEN') return '登录名已被占用';
  if (code === 'INVALID_LOGIN') return '登录名无效(不可为 11 位手机号)';
  if (code === 'STAFF_DEPARTMENT_REQUIRED') return '普通员工必须选择部门';
  if (code === 'STAFF_DEPARTMENT_DISABLED') return '普通员工必须属于启用中的部门';
  if (code === 'STAFF_DEPARTMENT_INVALID') return '所属部门不存在';
  if (code === 'STAFF_POST_INVALID') return '岗位必须启用且属于员工当前部门';
  if (code === 'STAFF_ROLE_INVALID') return '角色无效或已停用';
  if (code === 'PERMISSION_NOT_HELD') return '不能授予当前账号未持有的权限';
  if (code === 'CONFLICT') return message ?? '员工冲突';
  if (code === 'VALIDATION_FAILED') return message ?? '校验失败';
  return fallback;
}

onMounted(() => {
  void loadSupportData();
  void reload();
});
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
.table :deep(.actions-column .cell) {
  padding: 8px 12px;
}
.row-actions {
  display: grid;
  grid-template-columns: repeat(3, 92px);
  gap: 6px 8px;
  width: 292px;
  justify-content: flex-end;
}
.action-btn,
.row-actions :deep(.el-dropdown.action-btn) {
  width: 92px;
  margin: 0;
}
.row-actions :deep(.el-button.action-btn),
.row-actions :deep(.el-button.action-btn__trigger) {
  width: 92px;
  margin: 0;
  padding: 4px 6px;
  justify-content: center;
  font-size: 13px;
}
.row-actions :deep(.el-dropdown.action-btn) {
  display: inline-flex;
  justify-content: center;
}
.dim {
  color: #94a3b8;
}
.hint {
  margin-left: 12px;
  color: #94a3b8;
  font-size: 12px;
}
</style>
