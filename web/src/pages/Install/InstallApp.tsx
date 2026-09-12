import React, { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardBody, Container } from "reactstrap";

import { isStandalone, isIOS, isAndroid } from "../../helpers/pwa";
import { canInstall, subscribeInstall, promptInstall } from "../../helpers/installPrompt";

const APP_ICON = (process.env.PUBLIC_URL || "") + "/logo192.png";

/**
 * Página "Instalar app" — explica como instalar a XPLENDOR como PWA, com
 * deteção de dispositivo:
 *  · já instalada (standalone)  → confirma que está instalada;
 *  · Android/Chrome com prompt  → botão "Instalar app" (prompt nativo);
 *  · iPhone/Safari              → instruções Partilhar → Adicionar ao ecrã principal;
 *  · outros                     → instruções genéricas do menu do browser.
 * Rota pública (acessível antes do login, sobretudo para iPhone).
 */
const InstallApp = () => {
    document.title = "Instalar app | Xplendor";

    // Re-render quando o estado de instalação muda (prompt disponível / instalado).
    const [, forceRender] = useState(0);
    useEffect(() => subscribeInstall(() => forceRender((n) => n + 1)), []);

    const [feedback, setFeedback] = useState<string | null>(null);

    const standalone = isStandalone();
    const installable = canInstall();
    const ios = isIOS();
    const android = isAndroid();

    const handleInstall = async () => {
        const outcome = await promptInstall();
        if (outcome === "accepted") setFeedback("Boa! A app está a ser instalada.");
        else if (outcome === "dismissed") setFeedback("Instalação cancelada — podes voltar a tentar quando quiseres.");
        else setFeedback(null);
    };

    return (
        <div className="d-flex align-items-center justify-content-center min-vh-100 py-5" style={{ background: "var(--vz-body-bg)" }}>
            <Container style={{ maxWidth: 560 }}>
                <Card className="shadow-sm">
                    <CardBody className="p-4 p-md-5">
                        {/* Cabeçalho com o ícone real da app */}
                        <div className="text-center mb-4">
                            <img
                                src={APP_ICON}
                                alt="XPLENDOR"
                                width={84}
                                height={84}
                                className="rounded-4 shadow-sm mb-3"
                            />
                            <h4 className="mb-1 fw-semibold">Instalar a XPLENDOR</h4>
                            <p className="text-muted mb-0 fs-14">
                                Instala a app no telemóvel para abrires com um toque, em ecrã inteiro e direto no login.
                            </p>
                        </div>

                        {/* 1) Já instalada */}
                        {standalone ? (
                            <div className="text-center py-3">
                                <div className="avatar-md mx-auto mb-3">
                                    <span className="avatar-title bg-success-subtle text-success rounded-circle fs-1">
                                        <i className="ri-checkbox-circle-line" />
                                    </span>
                                </div>
                                <h5 className="fw-semibold">Já tens a app instalada</h5>
                                <p className="text-muted mb-3">Estás a usá-la em modo app. Não é preciso instalar de novo.</p>
                                <Link to="/dashboard" className="btn btn-primary">
                                    Ir para o painel
                                </Link>
                            </div>
                        ) : ios ? (
                            /* 2) iPhone / iPad — não há prompt; instruções de Partilhar */
                            <>
                                <div className="alert alert-info d-flex align-items-center gap-2" role="alert">
                                    <i className="ri-apple-fill fs-18" />
                                    <span>No iPhone/iPad, a instalação faz-se pelo Safari em 3 passos.</span>
                                </div>
                                <ol className="list-unstyled mb-0">
                                    <li className="d-flex align-items-start gap-3 mb-3">
                                        <span className="badge bg-primary rounded-circle flex-shrink-0" style={{ width: 26, height: 26, lineHeight: "18px" }}>1</span>
                                        <div>
                                            Abre este site no <strong>Safari</strong> e toca no botão <strong>Partilhar</strong>
                                            <i className="ri-share-box-line mx-1 align-middle" />
                                            (o quadrado com a seta para cima, na barra inferior).
                                        </div>
                                    </li>
                                    <li className="d-flex align-items-start gap-3 mb-3">
                                        <span className="badge bg-primary rounded-circle flex-shrink-0" style={{ width: 26, height: 26, lineHeight: "18px" }}>2</span>
                                        <div>
                                            Desliza e escolhe <strong>“Adicionar ao ecrã principal”</strong>
                                            <i className="ri-add-box-line mx-1 align-middle" />.
                                        </div>
                                    </li>
                                    <li className="d-flex align-items-start gap-3">
                                        <span className="badge bg-primary rounded-circle flex-shrink-0" style={{ width: 26, height: 26, lineHeight: "18px" }}>3</span>
                                        <div>
                                            Confirma em <strong>“Adicionar”</strong>. O ícone da XPLENDOR fica no ecrã principal.
                                        </div>
                                    </li>
                                </ol>
                            </>
                        ) : installable ? (
                            /* 3) Android/Chrome com prompt disponível — botão nativo */
                            <div className="text-center py-2">
                                <button type="button" className="btn btn-primary btn-lg w-100" onClick={handleInstall}>
                                    <i className="ri-download-2-line align-bottom me-1" />
                                    Instalar app
                                </button>
                                {feedback && <p className="text-muted mt-3 mb-0">{feedback}</p>}
                                <p className="text-muted fs-13 mt-3 mb-0">
                                    Ao instalar, a XPLENDOR fica com ícone próprio e abre como uma app normal.
                                </p>
                            </div>
                        ) : android ? (
                            /* 4) Android sem prompt ainda — instruções manuais do Chrome */
                            <>
                                <div className="alert alert-warning d-flex align-items-center gap-2" role="alert">
                                    <i className="ri-android-fill fs-18" />
                                    <span>Se o botão de instalar não aparecer, instala pelo menu do Chrome.</span>
                                </div>
                                <ol className="mb-0 ps-3">
                                    <li className="mb-2">Toca no menu <i className="ri-more-2-fill" /> (três pontos) no canto superior direito.</li>
                                    <li className="mb-2">Escolhe <strong>“Instalar app”</strong> ou <strong>“Adicionar ao ecrã principal”</strong>.</li>
                                    <li>Confirma. O ícone da XPLENDOR fica no ecrã principal.</li>
                                </ol>
                            </>
                        ) : (
                            /* 5) Desktop / outros browsers */
                            <>
                                <div className="alert alert-info" role="alert">
                                    A instalação como app está disponível sobretudo no telemóvel (Android/Chrome e iPhone/Safari).
                                </div>
                                <p className="text-muted mb-0 fs-14">
                                    No computador, alguns browsers (Chrome/Edge) mostram um ícone de instalar
                                    <i className="ri-install-line mx-1 align-middle" />
                                    na barra de endereço. Em telemóvel, abre este endereço no browser e segue as instruções que aparecem aqui.
                                </p>
                            </>
                        )}

                        {!standalone && (
                            <div className="text-center mt-4">
                                <Link to="/dashboard" className="text-muted text-decoration-none fs-13">
                                    <i className="ri-arrow-left-line align-bottom me-1" />
                                    Voltar
                                </Link>
                            </div>
                        )}
                    </CardBody>
                </Card>
            </Container>
        </div>
    );
};

export default InstallApp;
