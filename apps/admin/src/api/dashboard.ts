import {
  ApiOk,
  DashboardSummaryDTO,
  type DashboardSummaryDTO as DashboardSummary,
} from '@learn-site/contracts';
import http from './http';

const DashboardEnvelope = ApiOk(DashboardSummaryDTO);

export async function fetchDashboard(rangeDays = 30): Promise<DashboardSummary> {
  const response = await http.get<unknown>('/dashboard', { params: { range_days: rangeDays } });
  return DashboardEnvelope.parse(response.data).data;
}
