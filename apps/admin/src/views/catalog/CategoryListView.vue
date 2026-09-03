<template>
  <section class="page">
    <header class="bar">
      <h2>分类管理</h2>
      <div class="actions">
        <el-button type="primary" @click="openCreate(null)"> 新增顶级分类 </el-button>
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
      <el-table-column prop="name" label="名称" min-width="220">
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
          <el-button link type="primary" :disabled="row.depth >= 3" @click="openCreate(row.id)">
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
import type { CategoryDTO, CategoryStatus } from '@learn-site/contracts';
import {
  listCategoryTree,
  createCategory,
  updateCategory,
  setCategoryStatus,
  deleteCategory,
} from '@/api/catalog';
import mapper from '@/api/catalog-mapper';
import type { FlatCategoryRow } from '@/api/types/catalog-views';

const loading = ref(false);
const saving = ref(false);
const status = ref<'idle' | 'error'>('idle');
const errorMessage = ref('');
const flatRows = ref<FlatCategoryRow[]>([]);
const dialogVisible = ref(false);
const draft = mapper.toCategoryEditorForm();

const dialogTitle = computed(() => (draft.id === null ? '新增分类' : '编辑分类'));

const parentLabel = computed(() => {
  if (draft.parent_id === null) return '顶级';
  const row = flatRows.value.find((r) => r.id === draft.parent_id);
  return row ? row.name : `#${draft.parent_id}`;
});

// No longer needed - mapper handles this automatically

async function reload(): Promise<void> {
  loading.value = true;
  status.value = 'idle';
  errorMessage.value = '';
  try {
    // Use mapper to convert DTOs to formatted flat rows
    const dtoList = await listCategoryTree();
    flatRows.value = mapper.toCategoryFlatList(dtoList as any);
  } catch (err: unknown) {
    status.value = 'error';
    errorMessage.value = readError(err, '加载分类失败');
  } finally {
    loading.value = false;
  }
}

async function openCreate(parentId: number | null): void {
  // Use mapper to prepare empty form state
  Object.assign(draft, mapper.toCategoryEditorForm());
  draft.parent_id = parentId;
  draft.name = '';
  draft.sort = 0;
  dialogVisible.value = true;
}

async function openEdit(row: CategoryDTO): void {
  // Use mapper to prepare form from DTO
  Object.assign(draft, mapper.toCategoryEditorForm(row));
  dialogVisible.value = true;
}

async function save(): Promise<void> {
  if (!draft.name.trim()) {
    ElMessage.warning('请输入分类名称');
    return;
  }
  saving.value = true;
  try {
    if (draft.id === null) {
      // Validate depth before creating (invariant: max 3 levels)
      if (draft.parent_id !== null) {
        const parentRow = flatRows.value.find(r => r.id === draft.parent_id);
        if (parentRow && parentRow.depth >= 3) {
          ElMessage.error('分类最多 3 层');
          saving.value = false;
          return;
        }
      }
      
      await createCategory({
        parent_id: draft.parent_id ?? 0,
        name: draft.name.trim(),
        sort: draft.sort,
      });
    } else {
      await updateCategory(draft.id, {
        name: draft.name.trim(),
        sort: draft.sort,
      });
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

async function toggleStatus(row: CategoryDTO): Promise<void> {
  const next: CategoryStatus = row.status === 'enabled' ? 'disabled' : 'enabled';
  try {
    await setCategoryStatus(row.id, { status: next });
    ElMessage.success(`已${next === 'enabled' ? '启用' : '禁用'}`);
    await reload();
  } catch (err: unknown) {
    ElMessage.error(readError(err, '状态切换失败'));
  }
}

async function onDelete(row: CategoryDTO): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定删除分类「${row.name}」吗？`, '确认', { type: 'warning' });
  } catch {
    return;
  }
  try {
    await deleteCategory(row.id);
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
  if (code === 'CATEGORY_IN_USE') return '该分类仍有课程引用，无法变更';
  if (code === 'CONFLICT') return message ?? '分类冲突';
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
