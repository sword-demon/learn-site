const SHANGHAI_OFFSET_MS = 8 * 60 * 60 * 1000;
const WALL_CLOCK = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/;

function shanghaiParts(time: number): string {
  return new Date(time + SHANGHAI_OFFSET_MS).toISOString().slice(0, 19).replace('T', ' ');
}

/**
 * Display helper for admin UI. Wire format stays ISO-8601 with offset;
 * humans always see Asia/Shanghai wall clock `YYYY-MM-DD HH:mm:ss`.
 */
export function formatDateTime(value: string | number | Date | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'number' || value instanceof Date) {
    const time = typeof value === 'number' ? value : value.getTime();
    if (!Number.isFinite(time)) return '—';
    return shanghaiParts(time);
  }
  const trimmed = value.trim();
  if (WALL_CLOCK.test(trimmed)) {
    return trimmed.length === 16 ? `${trimmed}:00` : trimmed;
  }
  const time = Date.parse(trimmed);
  if (!Number.isFinite(time)) return '—';
  return shanghaiParts(time);
}

/** Parse a human wall-clock (`YYYY-MM-DD HH:mm[:ss]`) or ISO instant to epoch ms. */
export function parseDateTime(value: string): number {
  const trimmed = value.trim();
  if (WALL_CLOCK.test(trimmed)) {
    return Date.parse(`${trimmed.replace(' ', 'T')}${trimmed.length === 16 ? ':00' : ''}+08:00`);
  }
  return Date.parse(trimmed);
}

/** Round-trip a display wall-clock back to ISO-8601 with +08:00 for API payloads. */
export function toIso8601(value: string | number | Date): string {
  const time =
    typeof value === 'string'
      ? parseDateTime(value)
      : typeof value === 'number'
        ? value
        : value.getTime();
  if (!Number.isFinite(time)) return '';
  return `${new Date(time + SHANGHAI_OFFSET_MS).toISOString().slice(0, 19)}+08:00`;
}

export function nowWallClock(roundToMinute = false): string {
  const time = roundToMinute ? Math.round(Date.now() / 60_000) * 60_000 : Date.now();
  return shanghaiParts(time);
}
