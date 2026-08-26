import {
  ApiOk,
  DashboardSummaryDTO,
  type DashboardSummaryDTO as DashboardSummary,
} from '@learn-site/contracts';
import http from './http';

const DashboardEnvelope = ApiOk(DashboardSummaryDTO);

export async function fetchDashboard(): Promise<DashboardSummary> {
  const response = await http.get<unknown>('/dashboard');
  return DashboardEnvelope.parse(response.data).data;
}
