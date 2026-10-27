import React, { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardBody, Col, Container, Row } from "reactstrap";
import PageHeader from "Components/Common/PageHeader";
import { getMyMailbox } from "helpers/laravel_helper";
import { isImpersonating } from "helpers/impersonation";
import { isRootRole, sessionUser } from "helpers/roles";
import { useWorkingCompany } from "contexts/WorkingCompanyContext";
import Inbox, { InboxSkeleton } from "./inbox/Inbox";

/**
 * E-mail 3 · /email. A caixa é sempre a da própria pessoa, na sua empresa. Sem caixa: o que é e
 * como se liga, com "Ligar a minha caixa" (o quadro no Perfil). Com caixa: a caixa de entrada (E-mail 4,
 * lida ao vivo do servidor de e-mail). Durante a impersonation, ou para o root a trabalhar
 * noutra empresa, a caixa não está disponível aqui (e não se pede nada ao servidor).
 */

type Saved = { email: string; status: string; signature?: string | null };

export default function EmailPage() {
    document.title = "E-mail | Xplendor";
    const me = sessionUser();
    const wc = useWorkingCompany();
    const unavailable = isImpersonating() || (isRootRole(me?.role) && !!wc?.away);
    const [loading, setLoading] = useState(!unavailable);
    const [mailbox, setMailbox] = useState<Saved | null>(null);

    useEffect(() => {
        if (unavailable || !me?.company_id) {
            setLoading(false);
            return;
        }
        let alive = true;
        getMyMailbox(Number(me.company_id))
            .then((r: any) => { if (alive) setMailbox(r?.data?.mailbox ?? null); })
            .catch(() => { if (alive) setMailbox(null); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [unavailable, me?.company_id]);

    const profile = `/users/${me?.id ?? ""}#mailbox`;

    return (
        <React.Fragment>
            <div className="page-content">
                <Container fluid>
                    <PageHeader title="E-mail" breadcrumbs={[{ label: "Comunicação" }]} info="A sua caixa de correio na XPLENDOR. Só a própria pessoa a vê." />
                    {!unavailable && loading ? (
                        <InboxSkeleton email="" profileLink={profile} />
                    ) : !unavailable && mailbox && me?.company_id ? (
                        <Inbox companyId={Number(me.company_id)} email={mailbox.email} signature={mailbox.signature ?? null} profileLink={profile} />
                    ) : (
                    <Row className="justify-content-center">
                        <Col xxl={8} xl={10}>
                            <Card>
                                <CardBody className="p-4 p-lg-5">
                                    {unavailable ? (
                                        <div className="text-center py-4" data-testid="email-unavailable">
                                            <i className="ri-mail-lock-line display-5 text-muted" />
                                            <h5 className="mt-3">A caixa de correio não está disponível aqui</h5>
                                            <p className="text-muted mb-0">
                                                A caixa de correio é pessoal: só a própria pessoa a vê, na sua empresa. Durante uma sessão como cliente, ou a trabalhar noutra empresa, não se mostra a caixa de ninguém.
                                            </p>
                                        </div>
                                    ) : !mailbox ? (
                                        <div className="text-center py-4" data-testid="email-empty">
                                            <i className="ri-mail-add-line display-5 text-primary" />
                                            <h5 className="mt-3">Ligue a sua caixa de correio</h5>
                                            <p className="text-muted mx-auto" style={{ maxWidth: 560 }}>
                                                Ligue a caixa de e-mail que já usa (Gmail ou o e-mail do domínio da empresa) para enviar a partir da XPLENDOR, por exemplo o link de pós-venda, e ler aqui as suas mensagens. Os e-mails continuam no seu servidor de e-mail.
                                            </p>
                                            <ol className="text-start text-muted mx-auto mb-4" style={{ maxWidth: 460 }}>
                                                <li>No Perfil, escolha o tipo de conta (Gmail, Domínio próprio ou Outro).</li>
                                                <li>Escreva o endereço e a palavra-passe (no Gmail, uma palavra-passe de app).</li>
                                                <li>Guarde e use "Testar envio".</li>
                                            </ol>
                                            <Link to={profile} className="btn btn-primary"><i className="ri-link me-1" />Ligar a minha caixa</Link>
                                        </div>
                                    ) : null}
                                </CardBody>
                            </Card>
                        </Col>
                    </Row>
                    )}
                </Container>
            </div>
        </React.Fragment>
    );
}
