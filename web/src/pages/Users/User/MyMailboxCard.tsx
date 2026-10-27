import { useEffect, useState } from "react";
import { Alert, Badge, Button, Col, Collapse, Input, Label, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import PageCard from "Components/Common/PageCard";
import XSelect from "Components/Common/Select";
import { deleteMyMailbox, getMyMailbox, saveMyMailbox, testMyMailbox, testMyMailboxImap } from "helpers/laravel_helper";
import { refreshMailboxUnread } from "Components/Common/mailbox/useMailboxUnread";
import { confirmDelete } from "helpers/swal";
import {
    ACCOUNT_TYPE_OPTIONS, AccountType, AdvancedField, EMPTY_FORM, GMAIL_APP_PASSWORD_HELP, GOOGLE_APP_PASSWORDS, GOOGLE_SECURITY, MailboxForm,
    applyPreset, changeEmail, changeType, touchedFromSaved,
} from "./mailboxPresets";

/**
 * E-mail 1 · "A minha caixa de correio", no Perfil do Utilizador (só na própria conta e fora da
 * impersonation; o root também, na sua empresa: o backend recusa o resto). Primeiro o tipo de conta; nos
 * modelos basta o endereço e a palavra-passe, e os servidores ficam nas definições avançadas.
 * E-mail 3: "Testar IMAP" (a pasta de Enviados e os não lidos) e o estado da receção. A página
 * /email leva aqui com #mailbox. E-mail 5: a assinatura (texto simples), "Remover" com o SweetAlert2 e,
 * só em dev, os endereços de teste (recebem mesmo; o resto vai para a própria caixa).
 */

type Saved = Omit<MailboxForm, "password" | "signature" | "dev_test_addresses"> & {
    signature?: string | null; dev_test_addresses?: string | null;
    has_password: boolean; status: "por_testar" | "ativa" | "erro"; last_error: string | null; last_tested_at: string | null;
    imap_status?: "ativa" | "erro" | null; imap_error?: string | null; sent_folder?: string | null; unread_count?: number | null; unread_checked_at?: string | null;
};

const STATUS: Record<Saved["status"], { label: string; color: string }> = {
    por_testar: { label: "Por testar", color: "warning" },
    ativa: { label: "Ativa", color: "success" },
    erro: { label: "Com erro", color: "danger" },
};

const ENCRYPTION_OPTIONS = [
    { value: "ssl" as const, label: "SSL/TLS" },
    { value: "starttls" as const, label: "STARTTLS" },
];

const errorMessage = (e: any, fallback: string) => {
    const lists = e?.errors && typeof e.errors === "object" ? Object.values(e.errors).filter(Array.isArray) : [];
    const first = (lists.flat()[0] as string | undefined) ?? null;
    return first || e?.message || fallback;
};

const fromSaved = (m: Saved): MailboxForm => ({ ...EMPTY_FORM, ...m, password: "", signature: m.signature ?? "", dev_test_addresses: m.dev_test_addresses ?? "" });

/** As ligações da ajuda abrem num separador novo. */
const ExternalLink = ({ href }: { href: string }) => (
    <a href={href} target="_blank" rel="noopener noreferrer">{href.replace(/^https:\/\//, "")}</a>
);

export default function MyMailboxCard({ companyId }: { companyId: number }) {
    const [saved, setSaved] = useState<Saved | null>(null);
    const [form, setForm] = useState<MailboxForm>(EMPTY_FORM);
    const [touched, setTouched] = useState<Set<AdvancedField>>(new Set());
    const [advanced, setAdvanced] = useState(false);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState<"" | "save" | "test" | "imap" | "delete">("");
    const [dirty, setDirty] = useState(false);
    const [dev, setDev] = useState(false); // E-mail 5: os endereços de teste só existem fora de produção

    const load = (m: Saved | null) => {
        setSaved(m);
        const f = m ? fromSaved(m) : EMPTY_FORM;
        setForm(m ? f : applyPreset(f, new Set()));
        setTouched(m ? touchedFromSaved(f) : new Set());
        setAdvanced(f.account_type === "manual");
        setDirty(false);
    };

    useEffect(() => {
        setLoading(true);
        getMyMailbox(companyId)
            .then((r: any) => { setDev(r?.data?.dev === true); load(r?.data?.mailbox ?? null); })
            .catch(() => load(null))
            .finally(() => setLoading(false));
    }, [companyId]);

    // Vindo de /email ("Ligar a minha caixa"): o quadro fica à vista.
    useEffect(() => {
        if (!loading && window.location.hash === "#mailbox") document.getElementById("mailbox")?.scrollIntoView?.({ behavior: "smooth", block: "start" });
    }, [loading]);

    const edit = (next: MailboxForm) => { setForm(next); setDirty(true); };
    const editAdvanced = (field: AdvancedField, value: any) => {
        const t = new Set(touched);
        t.add(field);
        setTouched(t);
        edit({ ...form, [field]: value } as MailboxForm);
    };
    const pickType = (type: AccountType) => {
        const next = changeType(form, type, touched);
        if (type === "manual") setAdvanced(true);
        edit(next);
    };

    const save = async () => {
        setBusy("save");
        try {
            const { password, ...rest } = form;
            const r: any = await saveMyMailbox(companyId, password ? { ...rest, password } : rest);
            const m: Saved | null = r?.data?.mailbox ?? null;
            load(m);
            refreshMailboxUnread();
            // Só a ligação obriga a testar de novo (a assinatura e as opções de leitura não).
            toast.success(m?.status === "ativa" ? "Caixa de correio guardada." : "Caixa de correio guardada. Falta testar o envio.");
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível guardar a caixa de correio."));
        } finally {
            setBusy("");
        }
    };

    const test = async () => {
        setBusy("test");
        try {
            const r: any = await testMyMailbox(companyId);
            setSaved(r?.data?.mailbox ?? saved);
            if (r?.data?.sent_copy_error) toast.warning(r.message); else toast.success(r?.message || "Mensagem de teste enviada.");
        } catch (e: any) {
            if (e?.errors?.mailbox) setSaved(e.errors.mailbox);
            toast.error(errorMessage(e, "O envio de teste falhou."));
        } finally {
            setBusy("");
        }
    };

    const testImap = async () => {
        setBusy("imap");
        try {
            const r: any = await testMyMailboxImap(companyId);
            setSaved(r?.data?.mailbox ?? saved);
            refreshMailboxUnread();
            toast.success(r?.message || "IMAP ligado.");
        } catch (e: any) {
            if (e?.errors?.mailbox) setSaved(e.errors.mailbox);
            toast.error(errorMessage(e, "O teste do IMAP falhou."));
        } finally {
            setBusy("");
        }
    };

    const remove = async () => {
        if (!(await confirmDelete("Os e-mails continuam no seu servidor de e-mail.", "Remover a caixa de correio da XPLENDOR?"))) return;
        setBusy("delete");
        try {
            await deleteMyMailbox(companyId);
            load(null);
            refreshMailboxUnread();
            toast.success("Caixa de correio removida.");
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível remover a caixa de correio."));
        } finally {
            setBusy("");
        }
    };

    const preset = form.account_type !== "manual";
    const status = saved ? STATUS[saved.status] : null;

    return (
        <div id="mailbox">
        <PageCard
            title="A minha caixa de correio"
            info="Envie e receba pela XPLENDOR com a sua própria caixa. Só a própria pessoa a vê: nem o administrador, nem a agência, nem a equipa XPLENDOR."
            status={status ? <Badge color={status.color} className="fs-11" data-testid="mailbox-status">{status.label}</Badge> : "Ainda sem caixa ligada."}
            flush={false}
            bodyClassName="p-4"
            loading={loading}
            skeleton={loading ? "lines" : undefined}
            actions={<>
                {saved && <Button size="sm" color="outline-danger" disabled={!!busy} onClick={remove}>Remover</Button>}
                {saved && <Button size="sm" color="outline-primary" disabled={!!busy || dirty} onClick={testImap} title={dirty ? "Guarde primeiro as alterações." : undefined}>
                    {busy === "imap" ? <Spinner size="sm" /> : <><i className="ri-inbox-line me-1" />Testar IMAP</>}
                </Button>}
                {saved && <Button size="sm" color="outline-primary" disabled={!!busy || dirty} onClick={test} title={dirty ? "Guarde primeiro as alterações." : undefined}>
                    {busy === "test" ? <Spinner size="sm" /> : <><i className="ri-mail-send-line me-1" />Testar envio</>}
                </Button>}
                <Button size="sm" color="primary" disabled={!!busy || !dirty} onClick={save}>
                    {busy === "save" ? <Spinner size="sm" /> : <><i className="ri-save-line me-1" />Guardar</>}
                </Button>
            </>}
        >
            {saved?.status === "erro" && saved.last_error && <Alert color="danger" className="py-2" data-testid="mailbox-error">{saved.last_error}</Alert>}
            {saved?.imap_status === "erro" && saved.imap_error && <Alert color="danger" className="py-2" data-testid="mailbox-imap-error">Receção (IMAP): {saved.imap_error}</Alert>}
            {saved?.imap_status === "ativa" && (
                <p className="text-muted fs-13 mb-3" data-testid="mailbox-imap-ok">
                    <i className="ri-checkbox-circle-line text-success me-1" />Receção (IMAP) ligada
                    {typeof saved.unread_count === "number" ? ` · ${saved.unread_count} por ler na Entrada` : ""}
                    {saved.account_type === "gmail" || saved.smtp_host === "smtp.gmail.com" ? " · o Gmail guarda sozinho a cópia em Enviados" : saved.sent_folder ? ` · cópia em Enviados na pasta ${saved.sent_folder}` : ""}
                </p>
            )}
            <Row>
                <Col lg={6} className="mb-3">
                    <Label htmlFor="mailbox-type" className="form-label">Tipo de conta</Label>
                    <XSelect id="mailbox-type" ariaLabel="Tipo de conta" options={ACCOUNT_TYPE_OPTIONS} value={form.account_type} onChange={(v) => pickType(v as AccountType)} />
                </Col>
            </Row>
            {form.account_type === "gmail" && (
                <Alert color="info" className="py-2" data-testid="mailbox-gmail-help">
                    <p className="mb-1">No Gmail, entre com uma <strong>palavra-passe de app</strong>:</p>
                    <ol className="mb-1 ps-3">
                        <li>Ative a verificação em dois passos em <ExternalLink href={GOOGLE_SECURITY} /> (Segurança › Verificação em dois passos).</li>
                        <li>Crie uma palavra-passe de app com o nome XPLENDOR em <ExternalLink href={GOOGLE_APP_PASSWORDS} />.</li>
                        <li>Copie os 16 carateres, sem espaços, para o campo Palavra-passe.</li>
                    </ol>
                    <p className="mb-1">Se a opção não aparecer (conta de trabalho ou de escola, só chaves de segurança, ou Proteção Avançada), esta conta ainda não pode ser ligada.</p>
                    <p className="mb-0">Ajuda da Google: <ExternalLink href={GMAIL_APP_PASSWORD_HELP} /></p>
                </Alert>
            )}
            {form.account_type === "cpanel" && (
                <p className="text-muted mb-3" data-testid="mailbox-cpanel-help">Use o endereço e a palavra-passe com que entra no webmail.</p>
            )}
            <Row>
                <Col lg={6} className="mb-3">
                    <Label htmlFor="mailbox-email" className="form-label">Endereço de e-mail</Label>
                    <Input id="mailbox-email" type="email" autoComplete="off" value={form.email} placeholder="nome@oseudominio.pt"
                        onChange={(e) => edit(changeEmail(form, e.target.value, touched))} />
                </Col>
                <Col lg={6} className="mb-3">
                    <Label htmlFor="mailbox-password" className="form-label">{form.account_type === "gmail" ? "Palavra-passe de app" : "Palavra-passe"}</Label>
                    <Input id="mailbox-password" type="password" autoComplete="new-password" value={form.password}
                        placeholder={saved?.has_password ? "Gravada. Deixe em branco para a manter." : ""}
                        onChange={(e) => edit({ ...form, password: e.target.value })} />
                </Col>
            </Row>

            {form.account_type === "gmail" && (
                <div className="form-check form-switch mb-3" data-testid="mailbox-gmail-seen">
                    <Input type="checkbox" role="switch" className="form-check-input" id="mailbox-mark-seen" checked={form.mark_seen_on_server}
                        onChange={(e) => edit({ ...form, mark_seen_on_server: e.target.checked })} />
                    <Label className="form-check-label" for="mailbox-mark-seen">Ao abrir uma mensagem na XPLENDOR, marcá-la também como lida no Gmail</Label>
                    {!form.mark_seen_on_server && <div className="text-muted fs-12">No Gmail continua por ler; na XPLENDOR fica lida e deixa de contar no número de não lidos.</div>}
                </div>
            )}

            <div className="mb-3">
                <Label htmlFor="mailbox-signature" className="form-label">Assinatura <span className="text-muted fw-normal">(opcional)</span></Label>
                <Input id="mailbox-signature" type="textarea" rows={3} maxLength={2000} value={form.signature} placeholder={"Nome\nCargo · Empresa\nTelefone"}
                    onChange={(e) => edit({ ...form, signature: e.target.value })} />
                <div className="text-muted fs-12 mt-1">Texto simples. Vai no fim das mensagens novas e das respostas que escrever na XPLENDOR.</div>
            </div>

            {dev && (
                <div className="mb-3" data-testid="mailbox-dev-addresses">
                    <Label htmlFor="mailbox-dev-addresses" className="form-label">Endereços de teste (só dev)</Label>
                    <Input id="mailbox-dev-addresses" value={form.dev_test_addresses} placeholder="outro.endereco@exemplo.pt, mais.um@exemplo.pt" autoComplete="off"
                        onChange={(e) => edit({ ...form, dev_test_addresses: e.target.value })} />
                    <div className="text-muted fs-12 mt-1">Em dev, as mensagens para estes endereços saem mesmo para eles; tudo o resto vai só para a própria caixa. Em produção, este campo não existe.</div>
                </div>
            )}

            <button type="button" className="btn btn-link p-0 mb-2 text-decoration-none" aria-expanded={advanced} aria-controls="mailbox-advanced" onClick={() => setAdvanced(!advanced)}>
                <i className={`ri-arrow-${advanced ? "down" : "right"}-s-line me-1`} />Definições avançadas
                {preset && !advanced && <span className="text-muted ms-1">(preenchidas pelo modelo)</span>}
            </button>
            <Collapse isOpen={advanced} id="mailbox-advanced">
                <Row className="pt-2">
                    <Col lg={6} className="mb-3">
                        <Label htmlFor="mailbox-smtp-host" className="form-label">Servidor SMTP (envio)</Label>
                        <Input id="mailbox-smtp-host" value={form.smtp_host} onChange={(e) => editAdvanced("smtp_host", e.target.value.trim())} />
                    </Col>
                    <Col lg={3} className="mb-3">
                        <Label htmlFor="mailbox-smtp-port" className="form-label">Porta SMTP</Label>
                        <Input id="mailbox-smtp-port" type="number" value={form.smtp_port} onChange={(e) => editAdvanced("smtp_port", e.target.value === "" ? "" : Number(e.target.value))} />
                    </Col>
                    <Col lg={3} className="mb-3">
                        <Label htmlFor="mailbox-smtp-enc" className="form-label">Segurança SMTP</Label>
                        <XSelect id="mailbox-smtp-enc" ariaLabel="Segurança SMTP" options={ENCRYPTION_OPTIONS} value={form.smtp_encryption} onChange={(v) => editAdvanced("smtp_encryption", v)} />
                    </Col>
                    <Col lg={6} className="mb-3">
                        <Label htmlFor="mailbox-imap-host" className="form-label">Servidor IMAP (receção)</Label>
                        <Input id="mailbox-imap-host" value={form.imap_host} onChange={(e) => editAdvanced("imap_host", e.target.value.trim())} />
                    </Col>
                    <Col lg={3} className="mb-3">
                        <Label htmlFor="mailbox-imap-port" className="form-label">Porta IMAP</Label>
                        <Input id="mailbox-imap-port" type="number" value={form.imap_port} onChange={(e) => editAdvanced("imap_port", e.target.value === "" ? "" : Number(e.target.value))} />
                    </Col>
                    <Col lg={3} className="mb-3">
                        <Label htmlFor="mailbox-imap-enc" className="form-label">Segurança IMAP</Label>
                        <XSelect id="mailbox-imap-enc" ariaLabel="Segurança IMAP" options={ENCRYPTION_OPTIONS} value={form.imap_encryption} onChange={(v) => editAdvanced("imap_encryption", v)} />
                    </Col>
                    <Col lg={6} className="mb-3">
                        <Label htmlFor="mailbox-username" className="form-label">Utilizador</Label>
                        <Input id="mailbox-username" autoComplete="off" value={form.username} onChange={(e) => editAdvanced("username", e.target.value)} />
                    </Col>
                    <Col lg={6} className="mb-3 d-flex align-items-end">
                        <div className="form-check form-switch mb-2">
                            <Input type="checkbox" role="switch" className="form-check-input" id="mailbox-save-sent" checked={form.save_to_sent}
                                disabled={form.smtp_host.toLowerCase() === "smtp.gmail.com"} onChange={(e) => editAdvanced("save_to_sent", e.target.checked)} />
                            <Label className="form-check-label" for="mailbox-save-sent">Guardar cópia em Enviados</Label>
                        </div>
                    </Col>
                </Row>
            </Collapse>
        </PageCard>
        </div>
    );
}
