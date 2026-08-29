import axios from 'axios';
import type { AxiosError, AxiosInstance, InternalAxiosRequestConfig } from 'axios';
import { ApiErr, parseTokenEnvelope, type TokenPair } from '@learn-site/contracts';

const API_BASE = '/api/learner/v1';
const REFRESH_PATH = '/auth/refresh';
const retried = new WeakSet<object>();

interface AuthState {
  access: string | null;
  refresh: string | null;
  mustChangePassword: boolean;
}

const state: AuthState = { access: null, refresh: null, mustChangePassword: false };
let inflightRefresh: Promise<TokenPair | null> | null = null;

export function setTokens(pair: TokenPair & { must_change_password?: boolean }): void {
  state.access = pair.access_token;
  state.refresh = pair.refresh_token;
  state.mustChangePassword = pair.must_change_password === true;
}

export function clearTokens(): void {
  state.access = null;
  state.refresh = null;
  state.mustChangePassword = false;
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
