import { useMutation, useQuery } from "@tanstack/react-query";
import { api } from "@/lib/api";

export interface ReportTotals {
    sent: number;
    failed: number;
    total: number;
    opens: number;
    clicks: number;
    opened_messages: number;
    clicked_messages: number;
    open_rate: number;
    click_rate: number;
}

export interface ReportPoint {
    date: string;
    sent: number;
    failed: number;
    opens: number;
    clicks: number;
}

export interface ReportData {
    range: { days: number; from: string; to: string };
    totals: ReportTotals;
    series: ReportPoint[];
    mailers: Array<{ mailer: string; count: number }>;
    top_links: Array<{ url: string; clicks: number }>;
}

export function useReports(days: number) {
    return useQuery<ReportData>({
        queryKey: ["reports", days],
        queryFn: () => api.get<ReportData>(`reports?days=${days}`),
    });
}

export function useSendDigest() {
    return useMutation({
        mutationFn: (days: number) =>
            api.post<{ sent: boolean; recipients: string[] }>("reports/send", {
                days,
            }),
    });
}
