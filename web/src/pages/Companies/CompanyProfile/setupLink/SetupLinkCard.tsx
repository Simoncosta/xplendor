import { useEffect, useState } from "react";
import { Button, Card, CardBody, Col, Spinner } from "reactstrap";
import { getIntegrationsHistory } from "helpers/laravel_helper";
import SetupLinkPanel from "./SetupLinkPanel";

/**
 * Integrações: o link de configuração do cliente (só quem pode gerir o vê) e o histórico das
 * ligações (o que foi ligado, mudado ou desligado, quando e por quem, ou pelo link).
 */
type Event = { id: number; kind: "social" | "meta_ads" | "ga4"; action: "connected" | "account_changed" | "disconnected"; origin: "setup_link" | "user" | "system"; user: string | null; detail: any; created_at: string };

const KIND: Record<Event["kind"], string> = { social: "Facebook e Instagram", meta_ads: "Anúncios da Meta", ga4: "Google Analytics 4" };
const ACTION: Record<Event["action"], string> = { connected: "Ligado", account_changed: "Conta de anúncios escolhida", disconnected: "Desligado" };

function detailText(e: Event): string | null {
    const d = e.detail ?? {};
    if (e.kind === "social" && e.action === "connected") return [...(d.facebook ?? []), ...(d.instagram ?? [])].join(", ") || null;
    if (e.kind === "meta_ads" && d.account_id) return d.account_name ? `${d.account_name} (${d.account_id})` : `Conta ${d.account_id}`;
    if (e.kind === "ga4" && d.property_id) return `Propriedade ${d.property_id}`;
    return null;
}

export default function SetupLinkCard({ companyId }: { companyId: number }) {
    const [hidden, setHidden] = useState(false);
    const [showHistory, setShowHistory] = useState(false);
    const [events, setEvents] = useState<Event[] | null>(null);

    useEffect(() => {
        if (!showHistory || events) return;
        getIntegrationsHistory(companyId).then((r: any) => setEvents(r?.data?.events ?? [])).catch(() => setEvents([]));
    }, [showHistory, events, companyId]);

    if (hidden) return null;

    return (
        <Col xs={12}>
            <Card className="mb-0" data-testid="setup-link-card">
                <CardBody>
                    <div className="d-flex align-items-center gap-3 mb-3">
                        <div className="rounded d-flex align-items-center justify-content-center flex-shrink-0 bg-primary-subtle" style={{ width: 44, height: 44 }}>
                            <i className="ri-smartphone-line text-primary fs-20" />
                        </div>
                        <div>
                            <h6 className="fw-semibold mb-0">Link de configuração do cliente</h6>
                            <p className="text-muted fs-12 mb-0">O cliente autoriza no telemóvel o Facebook e o Instagram, os anúncios da Meta e o Google Analytics.</p>
                        </div>
                    </div>
                    <SetupLinkPanel companyId={companyId} onForbidden={() => setHidden(true)} />
                    <div className="border-top mt-3 pt-2">
                        <Button color="link" size="sm" className="p-0" onClick={() => setShowHistory((v) => !v)} aria-expanded={showHistory}>
                            <i className={`${showHistory ? "ri-arrow-up-s-line" : "ri-arrow-down-s-line"} me-1`} />Histórico das ligações
                        </Button>
                        {showHistory && (events === null ? <div className="py-2"><Spinner size="sm" /></div> : events.length === 0
                            ? <p className="text-muted fs-12 mt-2 mb-0">Ainda não há ligações registadas.</p>
                            : (
                                <ul className="list-unstyled vstack gap-2 mt-2 mb-0 fs-13" data-testid="connections-history">
                                    {events.map((e) => (
                                        <li key={e.id} className="d-flex flex-wrap gap-2">
                                            <span className="text-muted text-nowrap">{new Date(e.created_at).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" })}</span>
                                            <span><strong>{KIND[e.kind]}</strong>: {ACTION[e.action]}{detailText(e) ? `, ${detailText(e)}` : ""}</span>
                                            <span className="text-muted">{e.origin === "setup_link" ? "pelo cliente, no link de configuração" : e.user ? `por ${e.user}` : "automaticamente"}</span>
                                        </li>
                                    ))}
                                </ul>
                            ))}
                    </div>
                </CardBody>
            </Card>
        </Col>
    );
}
