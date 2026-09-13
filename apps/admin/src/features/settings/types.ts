import { type SettingsData } from "./useSettings";

export interface TabProps {
    form: SettingsData;
    setField: <K extends keyof SettingsData>(
        key: K,
        value: SettingsData[K],
    ) => void;
    setMailerField: (
        slug: string,
        field: string,
        value: string | number | boolean,
    ) => void;
}

/** Human labels for mailer slugs; falls back to a title-cased slug. */
export const MAILER_LABELS: Record<string, string> = {
    mail: "Default (PHP mail)",
    smtp: "Other SMTP",
    sendgrid: "SendGrid",
    mailgun: "Mailgun",
    brevo: "Brevo (Sendinblue)",
    amazonses: "Amazon SES",
    postmark: "Postmark",
    mailjet: "Mailjet",
    sparkpost: "SparkPost",
    smtpcom: "SMTP.com",
    pepipost: "Pepipost (Netcore)",
    sendpulse: "SendPulse",
    mandrill: "Mandrill",
    yournotify: "Yournotify",
    ionos: "IONOS",
    gmail: "Gmail / Google Workspace",
    outlook: "Outlook / Microsoft 365",
    zoho: "Zoho Mail",
};

export function mailerLabel(slug: string): string {
    return (
        MAILER_LABELS[slug] ??
        slug.charAt(0).toUpperCase() + slug.slice(1).replace(/[_-]/g, " ")
    );
}

/** Providers that connect via OAuth rather than typed credentials. */
export const OAUTH_MAILERS = new Set(["gmail", "outlook", "zoho"]);

/** Human labels for individual credential fields; falls back to the field key. */
export const FIELD_LABELS: Record<string, string> = {
    host: "SMTP Host",
    port: "SMTP Port",
    encryption: "Encryption",
    auth: "Authentication",
    user: "SMTP Username",
    pass: "SMTP Password",
    api_key: "API Key",
    secret_key: "Secret Key",
    client_id: "Client ID",
    client_secret: "Client Secret",
    region: "Region / Data Center",
    domain: "Sending Domain",
};

export function fieldLabel(key: string): string {
    return (
        FIELD_LABELS[key] ??
        key.charAt(0).toUpperCase() + key.slice(1).replace(/[_-]/g, " ")
    );
}

/**
 * Human labels for failure categories (matches Diagnostics::categories()).
 * Kept plain so `wp i18n make-pot` picks up the literal strings.
 */
export const CATEGORY_LABELS: Record<string, string> = {
    auth: "Authentication",
    connection: "Connection",
    timeout: "Timeout",
    tls: "TLS / SSL",
    dns: "DNS",
    rate_limit: "Rate limit",
    provider_rejection: "Provider rejection",
    invalid_recipient: "Invalid recipient",
    configuration: "Configuration",
    server: "Provider server",
    unknown: "Unknown",
};

export function categoryLabel(key: string): string {
    return (
        CATEGORY_LABELS[key] ??
        key.charAt(0).toUpperCase() + key.slice(1).replace(/[_-]/g, " ")
    );
}
