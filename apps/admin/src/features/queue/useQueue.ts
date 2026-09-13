import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api";

/** Mirrors Queue::stats(). Counts are live from the queue table. */
export interface QueueStats {
    pending: number;
    claimed: number;
    failed: number;
    due: number;
    /** Earliest scheduled run time (mysql string) or "" when nothing is waiting. */
    next_at: string;
    /** True when Action Scheduler drives the worker, false for the WP-Cron fallback. */
    action_scheduler: boolean;
    enabled: boolean;
    retry_enabled: boolean;
    max_attempts: number;
}

const QUEUE_KEY = ["queue"] as const;

/** GET /queue — read-only counts + engine info (never sends mail). */
export function useQueue() {
    return useQuery<QueueStats>({
        queryKey: QUEUE_KEY,
        queryFn: () => api.get<QueueStats>("queue"),
    });
}

/**
 * The queue mutations all return fresh stats, so each writes the result straight
 * back into the cache. `action` picks the endpoint: run the worker now, requeue
 * failed rows, or delete failed rows.
 */
export function useQueueAction() {
    const qc = useQueryClient();
    return useMutation<QueueStats, Error, "run" | "retry-failed" | "clear-failed">({
        mutationFn: (action) => api.post<QueueStats>(`queue/${action}`),
        onSuccess: (data) => qc.setQueryData(QUEUE_KEY, data),
    });
}
