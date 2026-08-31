type PushChannel = {
  on(event: string, callback: (data: unknown) => void): void;
  trigger?(event: string, data: unknown): void;
};

type PushClient = {
  subscribe(channel: string): PushChannel;
  disconnect(): void;
};

type PushConstructor = new (options: {
  url: string;
  app_key: string;
  auth: string;
  authHeader?: Record<string, string> | null;
  authData?: Record<string, string>;
  getAuthHeader?: () => Record<string, string> | null;
  getAuthData?: () => Record<string, string>;
}) => PushClient;

declare global {
  interface Window {
    Push?: PushConstructor;
  }
}

let scriptPromise: Promise<void> | null = null;

function loadPushScript(): Promise<void> {
  if (typeof window === 'undefined') {
    return Promise.resolve();
  }
  if (window.Push) {
    return Promise.resolve();
  }
  if (scriptPromise) {
    return scriptPromise;
  }
  scriptPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = '/plugin/webman/push/push.js';
    script.async = true;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error('PUSH_SCRIPT_LOAD_FAILED'));
    document.head.appendChild(script);
  });
  return scriptPromise;
}

export async function createPushConnection(options: {
  url: string;
  appKey: string;
  auth: string;
  authHeader?: Record<string, string> | null;
  authData?: Record<string, string>;
  getAuthHeader?: () => Record<string, string> | null;
  getAuthData?: () => Record<string, string>;
}): Promise<PushClient | null> {
  try {
    await loadPushScript();
    if (!window.Push) {
      return null;
    }
    return new window.Push({
      url: options.url,
      app_key: options.appKey,
      auth: options.auth,
      ...(options.getAuthHeader ? { getAuthHeader: options.getAuthHeader } : {}),
      ...(options.getAuthData ? { getAuthData: options.getAuthData } : {}),
      ...(options.authHeader ? { authHeader: options.authHeader } : {}),
      ...(options.authData ? { authData: options.authData } : {}),
    });
  } catch {
    return null;
  }
}

export type { PushChannel, PushClient };
