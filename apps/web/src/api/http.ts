import axios from 'axios';
import type { AxiosError, AxiosInstance, InternalAxiosRequestConfig } from 'axios';
import { ApiErr, parseTokenEnvelope, type TokenPair } from '@learn-site/contracts';

const API_BASE = '/api/learner/v1';
const REFRESH_PATH = '/auth/refresh';
const retried = new WeakSet<object>();
// 刷新页面后从这里恢复会话，避免只存在内存里导致退回登录页
export const AUTH_STORAGE_KEY = 'learn-site.learner.auth';

interface AuthState {
  access: string | null;
  refresh: string | null;
  mustChangePassword: boolean;
}

function emptyAuth(): AuthState {
  return { access: null, refresh: null, mustChangePassword: false };
}

function readPersistedAuth(): AuthState | null {
  if (typeof localStorage === 'undefined') return null;
  try {
    const raw = localStorage.getItem(AUTH_STORAGE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as Partial<AuthState>;
    if (typeof parsed.access !== 'string' || parsed.access.length === 0) return null;
    if (typeof parsed.refresh !== 'string' || parsed.refresh.length === 0) return null;
    return {
      access: parsed.access,
      refresh: parsed.refresh,
      mustChangePassword: parsed.mustChangePassword === true,
    };
  } catch {
    return null;
  }
}

function persistAuth(next: AuthState): void {
  if (typeof localStorage === 'undefined') return;
  if (!next.access || !next.refresh) {
    localStorage.removeItem(AUTH_STORAGE_KEY);
    return;
  }
  localStorage.setItem(
    AUTH_STORAGE_KEY,
    JSON.stringify({
      access: next.access,
      refresh: next.refresh,
      mustChangePassword: next.mustChangePassword,
    }),
  );
}

// 模块加载即水合，路由守卫第一次执行时就能读到 hasTokens
const state: AuthState = readPersistedAuth() ?? emptyAuth();
let inflightRefresh: Promise<TokenPair | null> | null = null;

export function setTokens(pair: TokenPair & { must_change_password?: boolean }): void {
  state.access = pair.access_token;
  state.refresh = pair.refresh_token;
  state.mustChangePassword = pair.must_change_password === true;
  persistAuth(state);
}

export function clearTokens(): void {
  state.access = null;
  state.refresh = null;
  state.mustChangePassword = false;
  persistAuth(state);
}

export function hasTokens(): boolean {
  return state.access !== null && state.refresh !== null;
}

export function getAccessToken(): string | null {
  return state.access;
}

export function mustChangePassword(): boolean {
  return state.mustChangePassword;
}

async function refreshOnce(): Promise<TokenPair | null> {
  if (inflightRefresh) return inflightRefresh;
  if (!state.refresh) return null;
  inflightRefresh = (async () => {
    try {
      const { data } = await raw.post(REFRESH_PATH, { refresh_token: state.refresh });
      const pair = parseTokenEnvelope(data);
      if (!pair) {
        clearTokens();
        return null;
      }
      setTokens(pair);
      return pair;
    } catch {
      clearTokens();
      return null;
    } finally {
      inflightRefresh = null;
    }
  })();
  return inflightRefresh;
}

const raw: AxiosInstance = axios.create({
  baseURL: API_BASE,
  timeout: 15000,
  headers: { 'Content-Type': 'application/json' },
});

raw.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  if (state.access) {
    config.headers.set('Authorization', `Bearer ${state.access}`);
  }
  return config;
});

raw.interceptors.response.use(
  (r) => r,
  async (err: AxiosError) => {
    const status = err.response?.status;
    const parsedErr = ApiErr.safeParse(err.response?.data);
    const code = parsedErr.success ? parsedErr.data.error.code : undefined;
    const cfg = err.config;
    if (status === 401 && code === 'TOKEN_EXPIRED' && cfg && !retried.has(cfg) && state.refresh) {
      retried.add(cfg);
      const next = await refreshOnce();
      if (next) {
        cfg.headers.set('Authorization', `Bearer ${next.access_token}`);
        return raw.request(cfg);
      }
    }
    return Promise.reject(err);
  },
);

export const http = raw;
export default http;
