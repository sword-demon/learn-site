<template>
  <div class="admin-tab-bar">
    <el-scrollbar class="tab-scroll">
      <div class="tab-list" role="tablist" aria-label="已打开页面">
        <div
          v-for="tab in tabsStore.opened"
          :key="tab.key"
          class="tab-item"
          :class="{ active: tab.key === tabsStore.activeKey }"
          role="tab"
          :aria-selected="tab.key === tabsStore.activeKey"
          @click="onTabClick(tab.key)"
          @contextmenu.prevent="onTabContextMenu($event, tab)"
        >
          <span class="tab-title">{{ tab.title }}</span>
          <button
            v-if="tab.closable"
            type="button"
            class="tab-close"
            aria-label="关闭标签"
            @click.stop="onCloseTab(tab.key)"
          >
            <el-icon><Close /></el-icon>
          </button>
        </div>
      </div>
    </el-scrollbar>

    <el-dropdown trigger="click" @command="onBulkCommand">
      <el-button text class="tab-menu-btn" aria-label="标签页操作">
        <el-icon><ArrowDown /></el-icon>
      </el-button>
      <template #dropdown>
        <el-dropdown-menu>
          <el-dropdown-item command="close-current" :disabled="!currentClosable">
            关闭当前
          </el-dropdown-item>
          <el-dropdown-item command="close-others" :disabled="!hasOtherClosable">
            关闭其他
          </el-dropdown-item>
          <el-dropdown-item command="close-all" :disabled="!hasOtherClosable">
            关闭全部
          </el-dropdown-item>
        </el-dropdown-menu>
      </template>
    </el-dropdown>

    <ul
      v-if="contextMenu.visible"
      class="tab-context-menu"
      :style="{ top: `${contextMenu.y}px`, left: `${contextMenu.x}px` }"
      @click.stop
    >
      <li>
        <button type="button" :disabled="!contextMenu.closable" @click="closeContextTab">
          关闭当前
        </button>
      </li>
      <li>
        <button type="button" :disabled="!hasOtherClosable" @click="closeOthersFromContext">
          关闭其他
        </button>
      </li>
      <li>
        <button type="button" :disabled="!hasOtherClosable" @click="closeAllTabs">关闭全部</button>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive } from 'vue';
import { useRouter } from 'vue-router';
import { ArrowDown, Close } from '@element-plus/icons-vue';
import { useTabsStore, type AdminTab } from '@/stores/tabs';

const tabsStore = useTabsStore();
const router = useRouter();

const contextMenu = reactive({
  visible: false,
  x: 0,
  y: 0,
  key: '',
  closable: false,
});

const activeTab = computed(() => tabsStore.opened.find((tab) => tab.key === tabsStore.activeKey));

const currentClosable = computed(() => activeTab.value?.closable === true);

const hasOtherClosable = computed(() =>
  tabsStore.opened.some((tab) => tab.closable && tab.key !== tabsStore.activeKey),
);

function navigateToKey(key: string): void {
  if (key === tabsStore.activeKey) return;
  const tab = tabsStore.opened.find((item) => item.key === key);
  if (!tab) return;
  void router.push(key);
}

function onTabClick(key: string): void {
  hideContextMenu();
  navigateToKey(key);
}

function onCloseTab(key: string): void {
  hideContextMenu();
  const wasActive = tabsStore.activeKey === key;
  const nextKey = tabsStore.closeTab(key);
  if (wasActive && nextKey !== undefined) {
    void router.push(nextKey);
  }
}

function onBulkCommand(command: string): void {
  if (command === 'close-current' && tabsStore.activeKey) {
    onCloseTab(tabsStore.activeKey);
    return;
  }
  if (command === 'close-others') {
    tabsStore.closeOthers(tabsStore.activeKey);
    return;
  }
  if (command === 'close-all') {
    const nextKey = tabsStore.closeAll();
    void router.push(nextKey);
  }
}

function onTabContextMenu(event: MouseEvent, tab: AdminTab): void {
  contextMenu.visible = true;
  contextMenu.x = event.clientX;
  contextMenu.y = event.clientY;
  contextMenu.key = tab.key;
  contextMenu.closable = tab.closable;
}

function hideContextMenu(): void {
  contextMenu.visible = false;
}

function closeContextTab(): void {
  onCloseTab(contextMenu.key);
}

function closeOthersFromContext(): void {
  tabsStore.closeOthers(contextMenu.key);
  navigateToKey(contextMenu.key);
  hideContextMenu();
}

function closeAllTabs(): void {
  const nextKey = tabsStore.closeAll();
  void router.push(nextKey);
  hideContextMenu();
}

function onDocumentClick(): void {
  hideContextMenu();
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick);
});

onUnmounted(() => {
  document.removeEventListener('click', onDocumentClick);
});
</script>

<style scoped>
.admin-tab-bar {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 8px 12px 0;
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
  position: relative;
}

.tab-scroll {
  flex: 1 1 auto;
}

.tab-list {
  display: flex;
  align-items: center;
  gap: 6px;
  padding-bottom: 8px;
}

.tab-item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  max-width: 180px;
  padding: 6px 10px;
  border: 1px solid #e2e8f0;
  border-radius: 6px 6px 0 0;
  background: #f8fafc;
  color: #475569;
  cursor: pointer;
  user-select: none;
}

.tab-item.active {
  background: #ffffff;
  color: #0f172a;
  border-bottom-color: #ffffff;
  box-shadow: inset 0 -2px 0 #38bdf8;
}

.tab-title {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 13px;
}

.tab-close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  color: #94a3b8;
  cursor: pointer;
  padding: 0;
}

.tab-close:hover {
  color: #ef4444;
}

.tab-menu-btn {
  margin-bottom: 8px;
}

.tab-context-menu {
  position: fixed;
  z-index: 3000;
  min-width: 140px;
  margin: 0;
  padding: 4px 0;
  list-style: none;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
}

.tab-context-menu button {
  width: 100%;
  border: none;
  background: transparent;
  text-align: left;
  padding: 8px 12px;
  font-size: 13px;
  color: #334155;
  cursor: pointer;
}

.tab-context-menu button:hover:not(:disabled) {
  background: #f1f5f9;
}

.tab-context-menu button:disabled {
  color: #cbd5e1;
  cursor: not-allowed;
}
</style>
