import { createRouter, createWebHistory } from 'vue-router'
import { requireLearnerAuth } from '@/router/guards'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      component: () => import('@/layouts/LearnerLayout.vue'),
      children: [
        { path: '', name: 'home', component: () => import('@/views/home/HomeView.vue') },
        { path: 'maps', name: 'maps', component: () => import('@/views/maps/MapListView.vue') },
        { path: 'maps/:id', name: 'map-detail', component: () => import('@/views/maps/MapDetailView.vue') },
        {
          path: 'categories/:id',
          name: 'category',
          component: () => import('@/views/catalog/CategoryView.vue'),
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
          path: 'me/messages',
          name: 'messages',
          beforeEnter: requireLearnerAuth,
          component: () => import('@/views/me/MessagesView.vue'),
        },
      ],
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/auth/LoginView.vue'),
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/auth/RegisterView.vue'),
    },
  ],
  scrollBehavior() {
    return { top: 0 }
  },
})

export default router
