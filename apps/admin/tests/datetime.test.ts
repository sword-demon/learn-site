import { describe, expect, it } from 'vitest';
import { formatDateTime, parseDateTime, toIso8601 } from '@/utils/datetime';

describe('admin datetime helpers', () => {
  it('formats ISO instants as Asia/Shanghai wall clock', () => {
    expect(formatDateTime('2026-09-14T07:30:00.000Z')).toBe('2026-09-14 15:30:00');
    expect(formatDateTime('2026-09-14T15:30:00+08:00')).toBe('2026-09-14 15:30:00');
    expect(formatDateTime('2026-09-14 15:30:00')).toBe('2026-09-14 15:30:00');
    expect(formatDateTime('2026-09-14 15:30')).toBe('2026-09-14 15:30:00');
    expect(formatDateTime(null)).toBe('—');
    expect(formatDateTime('')).toBe('—');
  });

  it('parses wall clock as Asia/Shanghai and round-trips to ISO', () => {
    expect(parseDateTime('2026-09-14 15:30:00')).toBe(Date.parse('2026-09-14T15:30:00+08:00'));
    expect(toIso8601('2026-09-14 15:30:00')).toBe('2026-09-14T15:30:00+08:00');
    expect(toIso8601('2026-09-14T07:30:00.000Z')).toBe('2026-09-14T15:30:00+08:00');
  });
});
