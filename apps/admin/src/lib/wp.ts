/**
 * Bridge to the `flexaSmtp` global published by Enqueue.php via
 * wp_localize_script.
 */

export type AppTheme = "light" | "dark";

export interface FieldDef {
    type: "string" | "int" | "bool" | "enum";
    secret?: boolean;
    enum?: string[];
}

/** slug => (field => definition), from Settings::mailer_schema(). */
export type MailerSchema = Record<string, Record<string, FieldDef>>;

export interface PluginGlobal {
    restUrl: string;
    restNonce: string;
    namespace: string;
    version: string;
    pluginUrl: string;
    adminUrl: string;
    locale: string;
    theme: AppTheme;
    /** Whether the current user may change settings (manage_options). */
    canManageSettings: boolean;
    /** Per-mailer credential field schema for every registered mailer. */
    schema: MailerSchema;
    /** Sentinel a secret field echoes back when left unedited. */
    secretMask: string;
}

declare global {
    interface Window {
        flexaSmtp?: PluginGlobal;
    }
}

export function getPluginGlobal(): PluginGlobal {
    if (!window.flexaSmtp) {
        throw new Error(
            "flexaSmtp global missing - make sure Enqueue::enqueue_admin ran before this script.",
        );
    }
    return window.flexaSmtp;
}
