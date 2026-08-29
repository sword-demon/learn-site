// @vitest-environment happy-dom

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const nprogress = vi.hoisted(() => ({
  start: vi.fn(),
  done: vi.fn(),
}));

vi.mock('nprogress', () => ({
  default: nprogress,
}));

import { finishRouteLoading, startRouteLoading } from '@/router/loading';

describe('admin route loading', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    nprogress.start.mockClear();
    nprogress.done.mockClear();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('starts nprogress on navigation', () => {
    startRouteLoading();
    expect(nprogress.start).toHaveBeenCalledTimes(1);
  });

  it('finishes nprogress after minimum visible duration', () => {
    startRouteLoading();
    finishRouteLoading();

    vi.advanceTimersByTime(180);
    expect(nprogress.done).toHaveBeenCalledTimes(1);
  });

  it('keeps progress visible briefly before finishing', () => {
    startRouteLoading();
    finishRouteLoading();

    vi.advanceTimersByTime(100);
    expect(nprogress.done).not.toHaveBeenCalled();

    vi.advanceTimersByTime(80);
    expect(nprogress.done).toHaveBeenCalledTimes(1);
  });
});
