import { getPluginGlobal } from "./wp";

type Json = Record<string, unknown> | unknown[] | string | number | boolean | null;

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly code?: string,
    ) {
        super(message);
        this.name = "ApiError";
    }
}

async function request<T>(
    method: "GET" | "POST" | "PATCH" | "DELETE",
    path: string,
    body?: Json,
): Promise<T> {
    const { restUrl, restNonce } = getPluginGlobal();
    const url = restUrl.replace(/\/$/, "") + "/" + path.replace(/^\//, "");

    const response = await fetch(url, {
        method,
        credentials: "same-origin",
        headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": restNonce,
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    if (response.status === 204) {
        return undefined as T;
    }

    const data = (await response.json().catch(() => null)) as
        | { code?: string; message?: string }
        | T
        | null;

    if (!response.ok) {
        const message =
            (data && typeof data === "object" && "message" in data && typeof data.message === "string"
                ? data.message
                : null) ?? response.statusText;
        const code = data && typeof data === "object" && "code" in data && typeof data.code === "string"
            ? data.code
            : undefined;
        throw new ApiError(message, response.status, code);
    }

    return data as T;
}

export const api = {
    get: <T>(path: string) => request<T>("GET", path),
    post: <T>(path: string, body?: Json) => request<T>("POST", path, body),
    patch: <T>(path: string, body?: Json) => request<T>("PATCH", path, body),
    delete: <T>(path: string, body?: Json) => request<T>("DELETE", path, body),
};
