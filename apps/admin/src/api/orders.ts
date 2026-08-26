import http from './http';
import {
  AdminOrderDTO as AdminOrderSchema,
  AdminOrderListDTO as AdminOrderListSchema,
  ApiOk,
} from '@learn-site/contracts';
import type { AdminOrderDTO, AdminOrderListDTO, AdminOrderStatus } from '@learn-site/contracts';

export type { AdminOrderDTO, AdminOrderListDTO, AdminOrderStatus };

/**
 * Admin orders — Phase 14 / US10 (T077/T078/T079).
 *
 * Read-only surface — there is no admin mark-as-paid endpoint by design.
 * Payment state transitions are driven by the payment provider's
 * notification callback only.
 */

const AdminOrderEnvelope = ApiOk(AdminOrderSchema);
const AdminOrderListEnvelope = ApiOk(AdminOrderListSchema);

export interface AdminListOrderParams {
  status?: AdminOrderStatus;
  course_id?: number;
  learner_id?: number;
  page?: number;
  limit?: number;
}

export async function listOrders(params: AdminListOrderParams = {}): Promise<AdminOrderListDTO> {
  const response = await http.get<unknown>('/orders', { params });
  return AdminOrderListEnvelope.parse(response.data).data;
}

export async function getOrder(id: number): Promise<AdminOrderDTO> {
  const response = await http.get<unknown>(`/orders/${id}`);
  return AdminOrderEnvelope.parse(response.data).data;
}
