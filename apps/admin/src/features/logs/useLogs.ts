import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { api } from "@/lib/api";

export interface LogItem {
    id: number;
    subject: string;
    from: string;
    to: Array<{ address: string; name: string }>;
    mailer: string;
    status: number;
    sent: boolean;
    content_type: string;
    reason_error: string;
    source: string;
    extra_info: Record<string, unknown>;
    date_time: string;
    /** DL1 diagnosis columns (empty/0 when not recorded). */
    error_category: string;
    response_code: string;
    provider_message_id: string;
    duration_ms: number;
    retry_count: number;
}

export interface LogsResponse {
    items: LogItem[];
    total: number;
    page: number;
    per_page: number;
}

export interface LogQuery {
    page: number;
    per_page: number;
    search: string;
    status: string;
    mailer: string;
    error_category: string;
}

export function useLogs(q: LogQuery) {
    return useQuery<LogsResponse>({
        queryKey: ["logs", q],
        queryFn: () => {
            const params = new URLSearchParams();
            params.set("page", String(q.page));
            params.set("per_page", String(q.per_page));
            if (q.search) params.set("search", q.search);
            if (q.status !== "") params.set("status", q.status);
            if (q.mailer) params.set("mailer", q.mailer);
            if (q.error_category)
                params.set("error_category", q.error_category);
            return api.get<LogsResponse>(`logs?${params.toString()}`);
        },
        placeholderData: keepPreviousData,
    });
}

/** The normalised failure diagnosis (Diagnostics\Diagnosis::to_array). */
export interface Diagnosis {
    category: string;
    severity: "info" | "warning" | "error";
    retryable: boolean;
    explanation: string;
    action: string;
    technical: string;
}

export interface ClickRow {
    url: string;
    count: number;
    date_time: string;
}

export interface LogDetail extends LogItem {
    body_content: string;
    opens: number;
    clicks: ClickRow[];
    /** Present only for failed emails (status 0). */
    diagnosis?: Diagnosis;
}

export function useLogDetail(id: number | null) {
    return useQuery<LogDetail>({
        queryKey: ["logs", "detail", id],
        queryFn: () => api.get<LogDetail>(`logs/${id}`),
        enabled: id !== null,
    });
}
