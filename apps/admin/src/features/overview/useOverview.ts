import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api";

/** One line item in the health report (matches Health\CheckResult::to_array). */
export interface HealthCheck {
    group: string;
    id: string;
    label: string;
    status: "pass" | "warn" | "error" | "not_checked" | "na";
    message: string;
    remediation: string;
    context: Record<string, string | number | boolean>;
}

export interface HealthReport {
    status: "pass" | "warn" | "error" | "not_checked" | "na";
    generated_at: number;
    checks: HealthCheck[];
    cached: boolean;
    stale: boolean;
}

/** GET /health — reads the cached report only, never runs a check. */
export function useHealth() {
    return useQuery<HealthReport>({
        queryKey: ["health"],
        queryFn: () => api.get<HealthReport>("health"),
    });
}

/** POST /health/run — runs the checks now and refreshes the cached report. */
export function useRunHealth() {
    const qc = useQueryClient();
    return useMutation<HealthReport>({
        mutationFn: () => api.post<HealthReport>("health/run"),
        onSuccess: (data) => qc.setQueryData(["health"], data),
    });
}

export type HealthSignal =
    | "healthy"
    | "degraded"
    | "failing"
    | "insufficient_data";

export interface ProviderMetric {
    mailer: string;
    sent: number;
    failed: number;
    total: number;
    failure_rate: number;
    avg_duration_ms: number;
    last_sent_at: string;
    last_failed_at: string;
    health: HealthSignal;
}

export interface MonitoringData {
    range: { days: number; from: string; to: string };
    totals: { sent: number; failed: number; total: number; failure_rate: number };
    providers: ProviderMetric[];
    categories: Array<{ category: string; count: number }>;
    /** Always "local": these are this site's own metrics, not provider-wide uptime. */
    scope: string;
}

/** GET /monitoring?days=N — per-provider reliability from this site's own log. */
export function useMonitoring(days: number) {
    return useQuery<MonitoringData>({
        queryKey: ["monitoring", days],
        queryFn: () => api.get<MonitoringData>(`monitoring?days=${days}`),
    });
}
