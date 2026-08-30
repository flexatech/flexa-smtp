import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { type PropsWithChildren } from "react";

/**
 * One QueryClient shared across every React root we mount. If you mount in
 * more than one place (e.g. a settings page plus an inline widget), they all
 * share cache - mutations propagate immediately.
 */
export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            refetchOnWindowFocus: false,
            staleTime: 30_000,
            retry: 1,
        },
    },
});

export function AppProviders({ children }: PropsWithChildren) {
    const theme = window.flexaSmtp?.theme ?? "light";
    return (
        <QueryClientProvider client={queryClient}>
            <div data-theme={theme} className="flexa-smtp-themed">
                {children}
            </div>
        </QueryClientProvider>
    );
}
