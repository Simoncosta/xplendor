export type MailFolder = { path: string; name: string; special: string | null };
export type MailAddress = { name: string | null; address: string | null };
export type MailSummary = { uid: number; subject: string; from: MailAddress; date: string | null; seen: boolean; flagged: boolean; has_attachments: boolean; thread?: string };
export type MailAttachment = { index: number; name: string; mime: string; size: number; previewable: boolean };
export type MailFull = {
    uid: number; folder: string; subject: string; from: MailAddress; to: MailAddress[]; cc: MailAddress[]; date: string | null;
    text: string | null; html: string | null; external_images: number; seen: boolean; attachments: MailAttachment[];
    customer: { id: number; name: string } | null;
};

export const FOLDER_ICON: Record<string, string> = {
    inbox: "ri-inbox-archive-fill", sent: "ri-send-plane-2-fill", drafts: "ri-edit-2-fill", junk: "ri-error-warning-fill",
    trash: "ri-delete-bin-5-fill", archive: "ri-archive-fill", all: "ri-mail-fill",
};

export const who = (a: MailAddress | null | undefined) => (a?.name || a?.address || "Sem remetente");

/** Hoje: a hora; este ano: dia e mês; antes: a data completa. */
export function shortDate(iso: string | null, now = new Date()): string {
    if (!iso) return "";
    const d = new Date(iso);
    if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" });
    if (d.getFullYear() === now.getFullYear()) return d.toLocaleDateString("pt-PT", { day: "2-digit", month: "short" });
    return d.toLocaleDateString("pt-PT");
}

export const fileSize = (bytes: number) => (bytes < 1024 ? `${bytes} B` : bytes < 1048576 ? `${Math.round(bytes / 1024)} KB` : `${(bytes / 1048576).toFixed(1)} MB`);
