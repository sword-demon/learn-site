<template>
  <section class="page">
    <header class="bar">
      <h2>部门管理</h2>
      <div class="actions">
        <el-button type="primary" @click="openCreate(null)"> 新增顶级部门 </el-button>
        <el-button @click="reload"> 刷新 </el-button>
      </div>
    </header>

    <el-alert
      v-if="status === 'error'"
      :title="errorMessage"
      type="error"
      show-icon
      :closable="false"
    />

    <el-table v-loading="loading" :data="flatRows" stripe row-key="id" class="table">
      <el-table-column prop="name" label="名称" min-width="240">
        <template #default="{ row }">
          <span :style="{ paddingLeft: `${(row.depth - 1) * 18}px` }">
            {{ '— '.repeat(row.depth - 1) }}{{ row.name }}
          </span>
        </template>
      </el-table-column>
      <el-table-column prop="status" label="状态" width="120">
        <template #default="{ row }">
          <el-tag :type="row.status === 'enabled' ? 'success' : 'info'" effect="light">
            {{ row.status === 'enabled' ? '启用' : '禁用' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="sort" label="排序" width="100" />
      <el-table-column label="操作" width="320" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" :disabled="row.depth >= 5" @click="openCreate(row.id)">
            新增子级
          </el-button>
          <el-button link type="primary" @click="openEdit(row)"> 编辑 </el-button>
          <el-button
            link
            :type="row.status === 'enabled' ? 'warning' : 'success'"
            @click="toggleStatus(row)"
          >
            {{ row.status === 'enabled' ? '禁用' : '启用' }}
          </el-button>
          <el-button link type="danger" @click="onDelete(row)"> 删除 </el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialogVisible" :title="dialogTitle" width="480px">
      <el-form :model="draft" label-position="top">
        <el-form-item v-if="draft.parent_id !== null" label="父级">
          <el-input :value="parentLabel" clearable disabled />
        </el-form-item>
        <el-form-item label="名称" required>
          <el-input v-model="draft.name" clearable maxlength="64" placeholder="1–64 字" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="draft.sort" :min="0" :max="999" />
        </el-form-item>
        <el-form-item v-if="draft.id === null" label="初始状态">
          <el-select v-model="draft.status" clearable>
            <el-option label="启用" value="enabled" />
            <el-option label="禁用" value="disabled" />
          </el-select>
        </el-form-item>
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
import { ElMessage, ElMessageBox } from 'element-plus';
import type {
  CreateDepartmentInput,
  DepartmentDTO,
  DepartmentStatus,
  UpdateDepartmentInput,
} from '@learn-site/contracts';
import {
  createDepartment,
  deleteDepartment,
  listDepartments,
  setDepartmentStatus,
  updateDepartment,
} from '@/api/org';

interface FlatRow extends DepartmentDTO {
  depth: number;
}

const loading = ref(false);
const saving = ref(false);
const status = ref<'idle' | 'error'>('idle');
const errorMessage = ref('');
const rows = ref<DepartmentDTO[]>([]);
const dialogVisible = ref(false);
const draft = reactive<{
  id: number | null;
  parent_id: number | null;
  name: string;
  sort: number;
  status: DepartmentStatus;
}>({
  id: null,
  parent_id: null,
  name: '',
  sort: 0,
  status: 'enabled',
});

// Materialized path already encodes depth; render in server-provided order.
const flatRows = computed<FlatRow[]>(() => rows.value.map((r) => ({ ...r, depth: r.depth })));

const dialogTitle = computed(() => (draft.id === null ? '新增部门' : '编辑部门'));

const parentLabel = computed(() => {
  if (draft.parent_id === null) return '顶级';
  const row = rows.value.find((r) => r.id === draft.parent_id);
  return row ? row.name : `#${draft.parent_id}`;
});

async function reload(): Promise<void> {
  loading.value = true;
  status.value = 'idle';
  errorMessage.value = '';
  try {
    const out = await listDepartments();
    rows.value = out.items;
  } catch (err: unknown) {
    status.value = 'error';
    errorMessage.value = readError(err, '加载部门失败');
  } finally {
    loading.value = false;
  }
}

function openCreate(parentId: number | null): void {
  draft.id = null;
  draft.parent_id = parentId;
  draft.name = '';
  draft.sort = 0;
  draft.status = 'enabled';
  dialogVisible.value = true;
}

function openEdit(row: DepartmentDTO): void {
  draft.id = row.id;
  draft.parent_id = row.parent_id;
  draft.name = row.name;
  draft.sort = row.sort;
  draft.status = row.status;
  dialogVisible.value = true;
}

async function save(): Promise<void> {
  if (!draft.name.trim()) {
    ElMessage.warning('请输入部门名称');
    return;
  }
  saving.value = true;
  try {
    if (draft.id === null) {
      const input: CreateDepartmentInput = {
        parent_id: draft.parent_id ?? 0,
        name: draft.name.trim(),
        sort: draft.sort,
        status: draft.status,
      };
      await createDepartment(input);
    } else {
      const input: UpdateDepartmentInput = {
        name: draft.name.trim(),
        sort: draft.sort,
      };
      await updateDepartment(draft.id, input);
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

async function toggleStatus(row: DepartmentDTO): Promise<void> {
  const next: DepartmentStatus = row.status === 'enabled' ? 'disabled' : 'enabled';
  try {
    await setDepartmentStatus(row.id, next);
    ElMessage.success(`已${next === 'enabled' ? '启用' : '禁用'}`);
    await reload();
  } catch (err: unknown) {
    ElMessage.error(readError(err, '状态切换失败'));
  }
}

async function onDelete(row: DepartmentDTO): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定删除部门「${row.name}」吗？`, '确认', {
      type: 'warning',
    });
  } catch {
    return;
  }
  try {
    await deleteDepartment(row.id);
    ElMessage.success('已删除');
    await reload();
  } catch (err: unknown) {
    ElMessage.error(readError(err, '删除失败'));
  }
}

function readError(err: unknown, fallback: string): string {
  const code = (err as { response?: { data?: { error?: { code?: string; message?: string } } } })
    ?.response?.data?.error?.code;
  const message = (err as { response?: { data?: { error?: { message?: string } } } })?.response
    ?.data?.error?.message;
  if (code === 'DEPARTMENT_DEPTH_EXCEEDED') return '部门最多 5 层';
  if (code === 'DEPARTMENT_HAS_CHILDREN') return '该部门仍有子部门，请先删除子部门';
  if (code === 'DEPARTMENT_IN_USE') return '该部门仍有员工，无法删除';
  if (code === 'DEPARTMENT_HAS_POSTS') return '该部门仍有岗位，无法删除';
  if (code === 'DEPARTMENT_NAME_TAKEN') return '同级别下已存在同名部门';
  if (code === 'CONFLICT') return message ?? '部门冲突';
  if (code === 'VALIDATION_FAILED') return message ?? '校验失败';
  return fallback;
}

onMounted(() => {
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
.table {
  width: 100%;
}
</style>
