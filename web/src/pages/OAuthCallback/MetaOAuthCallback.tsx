import { useEffect, useState } from "react";
import { useSearchParams } from "react-router-dom";

const API_URL = process.env.REACT_APP_API_URL ?? "";

/**
 * Página de callback OAuth do Meta.
 * Rota: /oauth/meta/callback
 *
 * O Meta redireciona para esta página com ?code=XXX&state=YYY
 * Esta página:
 * 1. Extrai o code e state
 * 2. Pede ao utilizador o account_id (ou tenta detectar automaticamente)
 * 3. Envia ao backend
 * 4. Fecha a popup
 */
export default function MetaOAuthCallback() {
    const [searchParams] = useSearchParams();
    const [status, setStatus] = useState<"loading" | "account" | "success" | "error">("loading");
    const [accountId, setAccountId] = useState("");
    const [error, setError] = useState("");

    const code = searchParams.get("code");
    const state = searchParams.get("state");

    useEffect(() => {
        if (!code || !state) {
            setStatus("error");
            setError("Parâmetros em falta. Tenta novamente.");
            return;
        }
        // Pedir o account_id ao utilizador
        setStatus("account");
    }, [code, state]);

    const handleSubmit = async () => {
        if (!accountId.trim()) return;

        setStatus("loading");

        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) {
            setStatus("error");
            setError("Sessão expirada. Faz login novamente.");
            return;
        }
        const { token } = JSON.parse(authUser);

        try {
            const res = await fetch(`${API_URL}/integrations/meta/callback`, {
                method: "POST",
                headers: {
                    Authorization: `Bearer ${token}`,
                    "Content-Type": "application/json",
                },
                body: JSON.stringify({
                    code,
                    state,
                    account_id: accountId.replace("act_", ""), // normalizar
                }),
            });

            const data = await res.json();

            if (!res.ok) {
                setStatus("error");
                setError(data?.message ?? "Erro ao conectar o Meta Ads.");
                return;
            }

            setStatus("success");

            // Fechar a popup após 1.5 segundos
            setTimeout(() => window.close(), 1500);

        } catch (err) {
            setStatus("error");
            setError("Erro de rede. Tenta novamente.");
        }
    };

    return (
        <div style={{
            minHeight: "100vh",
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            fontFamily: "system-ui, sans-serif",
            background: "#f8f9fa",
            padding: "1rem",
        }}>
            <div style={{
                background: "#fff",
                borderRadius: 12,
                padding: "2rem",
                maxWidth: 420,
                width: "100%",
                border: "1px solid #e9ebec",
                textAlign: "center",
            }}>

                {/* Loading */}
                {status === "loading" && (
                    <>
                        <div style={{ fontSize: 40, marginBottom: 16 }}>⏳</div>
                        <h2 style={{ fontSize: 18, marginBottom: 8 }}>A conectar...</h2>
                        <p style={{ color: "#878a99", fontSize: 14 }}>Aguarda um momento.</p>
                    </>
                )}

                {/* Pedir account_id */}
                {status === "account" && (
                    <>
                        <div style={{ fontSize: 40, marginBottom: 16 }}>📊</div>
                        <h2 style={{ fontSize: 18, marginBottom: 8 }}>Quase pronto!</h2>
                        <p style={{ color: "#878a99", fontSize: 14, marginBottom: 20 }}>
                            Introduz o ID da tua conta de anúncios.<br />
                            Encontras em <strong>Meta Business Suite → Configurações → Contas de anúncios</strong>.<br />
                            Aparece como <code>act_123456789</code> — podes introduzir com ou sem <code>act_</code>.
                        </p>
                        <input
                            type="text"
                            placeholder="123456789 ou act_123456789"
                            value={accountId}
                            onChange={e => setAccountId(e.target.value)}
                            onKeyDown={e => e.key === "Enter" && handleSubmit()}
                            style={{
                                width: "100%",
                                padding: "10px 14px",
                                borderRadius: 8,
                                border: "1px solid #e9ebec",
                                fontSize: 14,
                                marginBottom: 12,
                                boxSizing: "border-box",
                            }}
                        />
                        <button
                            onClick={handleSubmit}
                            disabled={!accountId.trim()}
                            style={{
                                width: "100%",
                                padding: "10px 0",
                                background: "#405189",
                                color: "#fff",
                                border: "none",
                                borderRadius: 8,
                                fontSize: 14,
                                fontWeight: 600,
                                cursor: accountId.trim() ? "pointer" : "not-allowed",
                                opacity: accountId.trim() ? 1 : 0.6,
                            }}
                        >
                            Conectar Meta Ads
                        </button>
                    </>
                )}

                {/* Sucesso */}
                {status === "success" && (
                    <>
                        <div style={{ fontSize: 48, marginBottom: 16 }}>✅</div>
                        <h2 style={{ fontSize: 18, marginBottom: 8, color: "#0ab39c" }}>Meta Ads conectado!</h2>
                        <p style={{ color: "#878a99", fontSize: 14 }}>
                            Esta janela fecha automaticamente.
                        </p>
                    </>
                )}

                {/* Erro */}
                {status === "error" && (
                    <>
                        <div style={{ fontSize: 48, marginBottom: 16 }}>❌</div>
                        <h2 style={{ fontSize: 18, marginBottom: 8, color: "#f06548" }}>Erro na autenticação</h2>
                        <p style={{ color: "#878a99", fontSize: 14, marginBottom: 16 }}>{error}</p>
                        <button
                            onClick={() => window.close()}
                            style={{
                                padding: "8px 24px",
                                background: "#f8f9fa",
                                border: "1px solid #e9ebec",
                                borderRadius: 8,
                                cursor: "pointer",
                                fontSize: 14,
                            }}
                        >
                            Fechar
                        </button>
                    </>
                )}

            </div>
        </div>
    );
}