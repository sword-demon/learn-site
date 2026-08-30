import { createRouter, createWebHistory } from 'vue-router';
import { requireLearnerAuth } from '@/router/guards';
import { finishRouteLoading, startRouteLoading } from '@/router/loading';

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      component: () => import('@/layouts/LearnerLayout.vue'),
      children: [
        { path: '', name: 'home', component: () => import('@/views/home/HomeView.vue') },
        { path: 'maps', name: 'maps', component: () => import('@/views/maps/MapListView.vue') },
        {
          path: 'maps/:id',
          name: 'map-detail',
          component: () => import('@/views/maps/MapDetailView.vue'),
        },
        {
          path: 'categories/:id',
          redirect: (to) => ({ path: '/', query: { cat: String(to.params.id) } }),
        },
        {
          path: 'courses/:id',
          name: 'course-detail',
          component: () => import('@/views/catalog/CourseDetailView.vue'),
        },
        {
          path: 'learn/:courseId/:lessonId',
          name: 'lesson',
          beforeEnter: requireLearnerAuth,
          component: () => import('@/views/learn/LessonView.vue'),
        },
        {
          path: 'me/learning',
          name: 'learning',
          beforeEnter: requireLearnerAuth,
          component: () => import('@/views/me/MyLearningView.vue'),
        },
        {
          path: 'me/favorites',
          name: 'favorites',
          beforeEnter: requireLearnerAuth,
          component: () => import('@/views/me/FavoritesView.vue'),
        },
        {
          path: 'me/orders',
          name: 'orders',
          beforeEnter: requireLearnerAuth,
          component: () => import('@/views/me/MyOrdersView.vue'),
        },
        {
          path: 'checkout/:courseId',
          name: 'checkout',
          beforeEnter: requireLearnerAuth,
          component: () => import('@/views/checkout/CheckoutView.vue'),
        },
        {
          path: 'me/messages',
          name: 'messages',
          beforeEnter: requireLearnerAuth,
          component: () => import('@/views/me/MessagesView.vue'),
        },
        {
          path: 'me/checkins',
          name: 'checkins',
          beforeEnter: requireLearnerAuth,
          component: () => import('@/views/me/CheckinListView.vue'),
        },
        {
          path: 'me/account',
          name: 'account',
          beforeEnter: requireLearnerAuth,
          component: () => import('@/views/me/AccountView.vue'),
        },
      ],
    },
    {
      path: '/login',
      name: 'login',
      meta: { hideFooter: true },
      component: () => import('@/views/auth/LoginRegisterView.vue'),
    },
    {
      path: '/register',
      name: 'register',
      meta: { hideFooter: true },
      component: () => import('@/views/auth/LoginRegisterView.vue'),
    },
  ],
  scrollBehavior() {
    return { top: 0 };
  },
});

router.beforeEach(() => {
  startRouteLoading();
});

router.afterEach(() => {
  finishRouteLoading();
});

router.onError(() => {
  finishRouteLoading();
});

export default router;
