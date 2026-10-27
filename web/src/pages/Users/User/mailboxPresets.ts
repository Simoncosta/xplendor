/**
 * E-mail 1 · Os modelos de preenchimento da caixa de correio. Nos modelos, a pessoa só escreve o
 * endereço e a palavra-passe; os servidores e as portas vêm daqui e ficam nas "Definições
 * avançadas". O que a pessoa mudou lá (touched) nunca é sobreposto pelo modelo.
 */

export type AccountType = "gmail" | "cpanel" | "manual";

export type MailboxForm = {
    account_type: AccountType;
    email: string;
    password: string;
    smtp_host: string;
    smtp_port: number | "";
    smtp_encryption: "ssl" | "starttls";
    imap_host: string;
    imap_port: number | "";
    imap_encryption: "ssl" | "starttls";
    username: string;
    save_to_sent: boolean;
    /** E-mail 4 · Só no Gmail: abrir na XPLENDOR marca também como lida no Gmail (ligada por omissão). */
    mark_seen_on_server: boolean;
    /** E-mail 5 · Assinatura opcional, em texto simples, no fim das mensagens novas e das respostas. */
    signature: string;
    /** E-mail 5 · Só em dev: endereços que recebem mesmo (o resto vai para a própria caixa). */
    dev_test_addresses: string;
};

/** Os campos das definições avançadas (os que o modelo preenche). */
export const ADVANCED_FIELDS = ["smtp_host", "smtp_port", "smtp_encryption", "imap_host", "imap_port", "imap_encryption", "username", "save_to_sent"] as const;
export type AdvancedField = (typeof ADVANCED_FIELDS)[number];

export const ACCOUNT_TYPE_OPTIONS: { value: AccountType; label: string }[] = [
    { value: "gmail", label: "Gmail" },
    { value: "cpanel", label: "Domínio próprio (cPanel)" },
    { value: "manual", label: "Outro (manual)" },
];

export const GOOGLE_SECURITY = "https://myaccount.google.com/security";
export const GOOGLE_APP_PASSWORDS = "https://myaccount.google.com/apppasswords";
export const GMAIL_APP_PASSWORD_HELP = "https://support.google.com/accounts/answer/185833";

export const EMPTY_FORM: MailboxForm = {
    account_type: "cpanel", email: "", password: "", smtp_host: "", smtp_port: 465, smtp_encryption: "ssl",
    imap_host: "", imap_port: 993, imap_encryption: "ssl", username: "", save_to_sent: true, mark_seen_on_server: true, signature: "", dev_test_addresses: "",
};

const domainOf = (email: string): string => {
    const at = email.trim().toLowerCase().lastIndexOf("@");
    return at > 0 ? email.trim().toLowerCase().slice(at + 1) : "";
};

/** Um endereço do Gmail escolhe sozinho o tipo "Gmail". */
export const isGmailAddress = (email: string): boolean => ["gmail.com", "googlemail.com"].includes(domainOf(email));

/** Os valores do modelo para este tipo e este endereço (vazio em "Outro"). */
export function presetValues(type: AccountType, email: string): Partial<MailboxForm> {
    const address = email.trim();
    if (type === "gmail") {
        return { smtp_host: "smtp.gmail.com", smtp_port: 465, smtp_encryption: "ssl", imap_host: "imap.gmail.com", imap_port: 993, imap_encryption: "ssl",
            username: address, save_to_sent: false };
    }
    if (type === "cpanel") {
        const domain = domainOf(address);
        return { smtp_host: domain ? `mail.${domain}` : "", smtp_port: 465, smtp_encryption: "ssl", imap_host: domain ? `mail.${domain}` : "", imap_port: 993,
            imap_encryption: "ssl", username: address, save_to_sent: true };
    }
    return {};
}

/** Aplica o modelo sem sobrepor o que a pessoa mudou nas definições avançadas. */
export function applyPreset(form: MailboxForm, touched: ReadonlySet<AdvancedField>): MailboxForm {
    const preset = presetValues(form.account_type, form.email);
    const next: any = { ...form };
    (Object.keys(preset) as AdvancedField[]).forEach((field) => {
        if (!touched.has(field)) next[field] = (preset as any)[field];
    });
    return next as MailboxForm;
}

/** Ao mudar o endereço: escolhe o Gmail quando é um endereço do Gmail e volta a aplicar o modelo. */
export function changeEmail(form: MailboxForm, email: string, touched: ReadonlySet<AdvancedField>): MailboxForm {
    const account_type: AccountType = isGmailAddress(email) ? "gmail" : form.account_type;
    return applyPreset({ ...form, email, account_type }, touched);
}

/** Ao mudar o tipo: aplica o modelo do tipo novo. */
export function changeType(form: MailboxForm, type: AccountType, touched: ReadonlySet<AdvancedField>): MailboxForm {
    return applyPreset({ ...form, account_type: type }, touched);
}

/** Numa caixa já gravada, conta como mudado à mão o que é diferente do modelo. */
export function touchedFromSaved(form: MailboxForm): Set<AdvancedField> {
    const preset = presetValues(form.account_type, form.email) as any;
    return new Set(ADVANCED_FIELDS.filter((f) => f in preset && preset[f] !== (form as any)[f]));
}
