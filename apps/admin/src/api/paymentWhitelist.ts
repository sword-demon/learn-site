import {
  ApiResponse,
  PaymentWhitelistCreateInput,
  PaymentWhitelistEntry,
  PaymentWhitelistListResponse,
} from '@learn-site/contracts';
import type {
  PaymentWhitelistCreateInput as PaymentWhitelistInput,
  PaymentWhitelistEntry as PaymentWhitelistEntryDTO,
  PaymentWhitelistListResponse as PaymentWhitelistListDTO,
} from '@learn-site/contracts';
import { http } from '@/api/http';

export type { PaymentWhitelistInput, PaymentWhitelistEntryDTO, PaymentWhitelistListDTO };

export async function listPaymentWhitelist(page = 1, limit = 20): Promise<PaymentWhitelistListDTO> {
  const { data } = await http.get('/payment/whitelist', { params: { page, limit } });
  const parsed = ApiResponse(PaymentWhitelistListResponse).parse(data);
  if (!parsed.ok) throw new Error(parsed.error.code);
  return parsed.data;
}

export async function addPaymentWhitelist(
  input: PaymentWhitelistInput,
): Promise<PaymentWhitelistEntryDTO> {
  const { data } = await http.post('/payment/whitelist', PaymentWhitelistCreateInput.parse(input));
  const parsed = ApiResponse(PaymentWhitelistEntry).parse(data);
  if (!parsed.ok) throw new Error(parsed.error.code);
  return parsed.data;
}

export async function togglePaymentWhitelist(
  id: number,
  enabled: boolean,
): Promise<PaymentWhitelistEntryDTO> {
  const { data } = await http.patch(`/payment/whitelist/${id}`, { enabled, note: null });
  const parsed = ApiResponse(PaymentWhitelistEntry).parse(data);
  if (!parsed.ok) throw new Error(parsed.error.code);
  return parsed.data;
}

export async function removePaymentWhitelist(id: number): Promise<void> {
  await http.delete(`/payment/whitelist/${id}`);
}
