import { z } from 'zod';
import {
  ApiResponse,
  CommissionListDTO,
  DistributionStatusDTO,
  DownlineListDTO,
  ShareEntryCreateInput,
  ShareEntryCreateOutput,
  ShareEntryListDTO,
} from '@learn-site/contracts';
import { http } from '@/api/http';

const Ok = z.object({ ok: z.literal(true) });

export async function fetchDistributionStatus() {
  const { data } = await http.get('/distribution/status');
  const parsed = ApiResponse(DistributionStatusDTO).parse(data);
  if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  return parsed.data;
}

export async function fetchMyShareEntries() {
  const { data } = await http.get('/distribution/share-entries');
  const parsed = ApiResponse(ShareEntryListDTO).parse(data);
  if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  return parsed.data;
}

export async function createShareEntry(input: ShareEntryCreateInput) {
  const body = ShareEntryCreateInput.parse(input);
  const { data } = await http.post('/distribution/share-entries', body);
  const parsed = ApiResponse(ShareEntryCreateOutput).parse(data);
  if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  return parsed.data;
}

export async function revokeShareEntry(id: number) {
  const { data } = await http.delete(`/distribution/share-entries/${id}`);
  const parsed = ApiResponse(Ok).parse(data);
  if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  return parsed.data;
}

export async function fetchMyCommissions(params: Record<string, unknown> = {}) {
  const { data } = await http.get('/distribution/commissions', { params });
  const parsed = ApiResponse(CommissionListDTO).parse(data);
  if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  return parsed.data;
}

export async function fetchMyDownline(level?: number) {
  const { data } = await http.get('/distribution/downline', { params: level ? { level } : {} });
  const parsed = ApiResponse(DownlineListDTO).parse(data);
  if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  return parsed.data;
}
