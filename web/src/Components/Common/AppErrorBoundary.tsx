import React from "react";

/**
 * Proteção na raiz da app: um erro ao desenhar um ecrã mostra esta mensagem com "Recarregar",
 * nunca um ecrã branco. Fica fora do router e do Redux (pode ser um deles a falhar), por isso só
 * usa HTML e as classes do tema. Os ficheiros do build que não carregam (antes de o React
 * arrancar) tratam-se no script de recuperação do index.html.
 */
type State = { failed: boolean };

export default class AppErrorBoundary extends React.Component<{ children: React.ReactNode }, State> {
    state: State = { failed: false };

    static getDerivedStateFromError(): State {
        return { failed: true };
    }

    componentDidCatch(error: unknown, info: React.ErrorInfo) {
        // eslint-disable-next-line no-console
        console.error("[XPLENDOR] Erro ao desenhar o ecrã", error, info?.componentStack);
    }

    render() {
        if (!this.state.failed) return this.props.children;
        return (
            <div className="d-flex align-items-center justify-content-center p-4" style={{ minHeight: "100vh" }} role="alert" data-testid="app-error">
                <div className="text-center" style={{ maxWidth: 420 }}>
                    <i className="ri-error-warning-line text-warning" style={{ fontSize: 48 }} aria-hidden />
                    <h5 className="mt-3">Não foi possível mostrar esta página.</h5>
                    <p className="text-muted">Recarregue a página para continuar. Se o problema voltar, avise a equipa XPLENDOR.</p>
                    <button type="button" className="btn btn-primary" onClick={() => window.location.reload()}>
                        <i className="ri-refresh-line me-1" />Recarregar
                    </button>
                </div>
            </div>
        );
    }
}
