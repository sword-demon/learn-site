// @vitest-environment happy-dom

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { finishRouteLoading, startRouteLoading, useRouteLoading } from '@/router/loading';

describe('route loading', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    const { loading } = useRouteLoading();
    loading.value = false;
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('shows loading while navigation is in progress', () => {
    const { loading } = useRouteLoading();

    startRouteLoading();
    expect(loading.value).toBe(true);
  });

  it('hides loading after navigation completes', () => {
    const { loading } = useRouteLoading();

    startRouteLoading();
    finishRouteLoading();

    vi.advanceTimersByTime(180);
    expect(loading.value).toBe(false);
  });

  it('keeps loading visible for a short minimum duration', () => {
    const { loading } = useRouteLoading();

    startRouteLoading();
    finishRouteLoading();

    vi.advanceTimersByTime(100);
    expect(loading.value).toBe(true);

    vi.advanceTimersByTime(80);
    expect(loading.value).toBe(false);
  });
});
