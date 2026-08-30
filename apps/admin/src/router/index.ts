import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import DashboardView from '@/views/dashboard/DashboardView.vue';
import LoginView from '@/views/auth/LoginView.vue';
import FirstPasswordView from '@/views/auth/FirstPasswordView.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import CategoryListView from '@/views/catalog/CategoryListView.vue';
import CourseListView from '@/views/catalog/CourseListView.vue';
import CourseEditView from '@/views/catalog/CourseEditView.vue';
import CoursePreviewView from '@/views/catalog/CoursePreviewView.vue';
import DepartmentListView from '@/views/org/DepartmentListView.vue';
import PostListView from '@/views/org/PostListView.vue';
import RoleListView from '@/views/org/RoleListView.vue';
import StaffListView from '@/views/org/StaffListView.vue';
import QuestionListView from '@/views/qa/QuestionListView.vue';
import ReviewModerateView from '@/views/reviews/ReviewModerateView.vue';
import MapEditorView from '@/views/maps/MapEditorView.vue';
import OrderListView from '@/views/orders/OrderListView.vue';
import StaffOverrideView from '@/views/org/StaffOverrideView.vue';
import LearnerListView from '@/views/students/LearnerListView.vue';
import LearnerProgressView from '@/views/students/LearnerProgressView.vue';
import LearnerLearningRecordsView from '@/views/students/LearnerLearningRecordsView.vue';
import CourseStudentView from '@/views/students/CourseStudentView.vue';
import SiteProfileView from '@/views/site/SiteProfileView.vue';
import AuditLogView from '@/views/site/AuditLogView.vue';
import NotificationListView from '@/views/notifications/NotificationListView.vue';
import ScheduledTaskListView from '@/views/scheduled-tasks/ScheduledTaskListView.vue';
import ScheduledTaskRunLogView from '@/views/scheduled-tasks/ScheduledTaskRunLogView.vue';
import ForbiddenView from '@/views/errors/ForbiddenView.vue';
import { hasTokens, mustChangePassword, permissionCodes } from '@/api/http';
import { firstVisiblePath } from '@/layouts/AdminMenu';
import { resolveAdminNavigation } from '@/router/access';
import { finishRouteLoading, startRouteLoading } from '@/router/loading';
import { shouldShowRouteLoading, shouldTrackTab } from '@/router/tabSync';
import { useTabsStore } from '@/stores/tabs';
import type { BreadcrumbItem } from '@/composables/useAdminBreadcrumb';

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean;
    title?: string;
    // US13 / T060 — when set, navigation requires this permission code.
    // Codes match apps/api/database/seeds/PermissionSeeder.php; super
    // admin (`*`) bypasses via `hasPermission`.
    permission?: string;
    affix?: boolean;
    hideInTabs?: boolean;
    breadcrumb?: BreadcrumbItem[];
  }
}

const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: LoginView,
    meta: { public: true, title: '管理员登录' },
  },
  {
    path: '/first-password',
    name: 'first-password',
    component: FirstPasswordView,
    meta: { title: '首次登录改密' },
  },
  {
    path: '/',
    component: AdminLayout,
    children: [
      {
        path: '',
        name: 'dashboard',
        component: DashboardView,
        meta: { title: '工作台', permission: 'dashboard.view', affix: true },
      },
      {
        path: 'categories',
        name: 'categories',
        component: CategoryListView,
        meta: { title: '分类管理', permission: 'category.manage' },
      },
      {
        path: 'courses',
        name: 'courses',
        component: CourseListView,
        meta: { title: '课程管理', permission: 'course.view' },
      },
      {
        path: 'courses/new',
        name: 'course-new',
        component: CourseEditView,
        meta: {
          title: '新建课程',
          permission: 'course.manage',
          breadcrumb: [{ title: '课程管理', path: '/courses' }, { title: '新建课程' }],
        },
      },
      {
        path: 'courses/:id/edit',
        name: 'course-edit',
        component: CourseEditView,
        props: true,
        meta: {
          title: '编辑课程',
          permission: 'course.manage',
          breadcrumb: [{ title: '课程管理', path: '/courses' }, { title: '编辑课程' }],
        },
      },
      {
        path: 'courses/:id/preview',
        name: 'course-preview',
        component: CoursePreviewView,
        props: true,
        meta: {
          title: '课程预览',
          permission: 'course.view',
          breadcrumb: [{ title: '课程管理', path: '/courses' }, { title: '课程预览' }],
        },
      },
      {
        path: 'org/departments',
        name: 'org-departments',
        component: DepartmentListView,
        meta: { title: '部门管理', permission: 'org.department' },
      },
      {
        path: 'org/posts',
        name: 'org-posts',
        component: PostListView,
        meta: { title: '岗位管理', permission: 'org.post' },
      },
      {
        path: 'org/roles',
        name: 'org-roles',
        component: RoleListView,
        meta: { title: '角色管理', permission: 'org.role' },
      },
      {
        path: 'org/staff',
        name: 'org-staff',
        component: StaffListView,
        meta: { title: '员工管理', permission: 'org.staff' },
      },
      {
        path: 'org/staff/:id/overrides',
        name: 'org-staff-overrides',
        component: StaffOverrideView,
        props: true,
        meta: {
          title: '权限覆盖',
          permission: 'org.grant',
          breadcrumb: [
            { title: '组织管理' },
            { title: '员工管理', path: '/org/staff' },
            { title: '权限覆盖' },
          ],
        },
      },
      {
        path: 'qa',
        name: 'qa',
        component: QuestionListView,
        meta: { title: '问答管理', permission: 'qa.view' },
      },
      {
        path: 'reviews',
        name: 'reviews',
        component: ReviewModerateView,
        meta: { title: '评价管理', permission: 'review.view' },
      },
      {
        path: 'maps',
        name: 'maps',
        component: MapEditorView,
        meta: { title: '学习地图', permission: 'map.view' },
      },
      {
        path: 'orders',
        name: 'orders',
        component: OrderListView,
        meta: { title: '订单管理', permission: 'order.view' },
      },
      {
        path: 'learners',
        name: 'learners',
        component: LearnerListView,
        meta: { title: '学员账号', permission: 'learner.view' },
      },
      {
        path: 'learners/:id/progress',
        name: 'learner-progress',
        component: LearnerProgressView,
        props: true,
        meta: {
          title: '学员学习进度',
          permission: 'learner.view',
          breadcrumb: [{ title: '学员账号', path: '/learners' }, { title: '学习进度' }],
        },
      },
      {
        path: 'learners/:id/records',
        name: 'learner-records',
        component: LearnerLearningRecordsView,
        props: true,
        meta: {
          title: '学员学习记录',
          permission: 'learner.view',
          breadcrumb: [{ title: '学员账号', path: '/learners' }, { title: '学习记录' }],
        },
      },
      {
        path: 'courses/:id/students',
        name: 'course-students',
        component: CourseStudentView,
        props: true,
        meta: {
          title: '课程学员',
          permission: 'course_student.view',
          breadcrumb: [{ title: '课程管理', path: '/courses' }, { title: '课程学员' }],
        },
      },
      {
        path: 'notifications',
        name: 'notifications',
        component: NotificationListView,
        meta: { title: '通知管理', permission: 'notification.manage' },
      },
      {
        path: 'scheduled-tasks',
        name: 'scheduled-tasks',
        component: ScheduledTaskListView,
        meta: { title: '自动任务', permission: 'scheduled_task.manage' },
      },
      {
        path: 'scheduled-tasks/runs',
        name: 'scheduled-task-runs',
        component: ScheduledTaskRunLogView,
        meta: { title: '执行日志', permission: 'scheduled_task.manage' },
      },
      {
        path: 'site/profile',
        name: 'site-profile',
        component: SiteProfileView,
        meta: { title: '站点资料', permission: 'site.manage' },
      },
      {
        path: 'site/audit',
        name: 'site-audit',
        component: AuditLogView,
        meta: { title: '审计日志', permission: 'audit.view' },
      },
      {
        path: 'forbidden',
        name: 'forbidden',
        component: ForbiddenView,
        meta: { title: '无权访问', hideInTabs: true },
      },
    ],
  },
  { path: '/:rest(.*)', redirect: '/' },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach((to) => {
  if (shouldShowRouteLoading(to)) {
    startRouteLoading();
  }

  const requiresPasswordChange = mustChangePassword();
  const codes = permissionCodes();
  const decision = resolveAdminNavigation(
    {
      name: typeof to.name === 'string' ? to.name : '',
      path: to.path,
      fullPath: to.fullPath,
      public: to.meta.public === true,
      ...(to.meta.permission === undefined ? {} : { permission: to.meta.permission }),
    },
    {
      hasTokens: hasTokens(),
      mustChangePassword: requiresPasswordChange,
      permissionCodes: codes,
      fallbackPath: firstVisiblePath(codes),
    },
  );

  if (
    decision !== true &&
    typeof decision === 'object' &&
    'name' in decision &&
    decision.name === 'login'
  ) {
    useTabsStore().reset();
  }

  return decision;
});

router.afterEach((to) => {
  if (shouldTrackTab(to)) {
    useTabsStore().syncFromRoute(to);
  }

  finishRouteLoading();

  const title = to.meta.title;
  if (typeof title === 'string' && typeof document !== 'undefined') {
    document.title = `${title} · 管理端`;
  }
});

router.onError(() => {
  finishRouteLoading();
});

export default router;
