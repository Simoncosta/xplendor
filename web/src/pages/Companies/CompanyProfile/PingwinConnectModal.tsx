import { useEffect, useMemo, useState } from "react";
import { Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { connectPingwin } from "helpers/laravel_helper";
import { PingwinConfigFields } from "common/models/pingwin.model";

/**
 * XPLENDOR — Cadastro/reconfiguração do PingWin (POS restauração). Só os 3
 * campos que VARIAM por restaurante: utilizador, senha e base de dados
 * (X-Database). Tudo o resto (URLs, versão, report_id…) é global e vive no .env
 * do servidor. O backend VALIDA a ligação (login + logout de teste) antes de
 * gravar; a senha é cifrada e NUNCA devolvida — por isso o campo começa vazio.
 */

const emptyValues = (): PingwinConfigFields => ({ username: "", database: "" });

type Props = {
    isOpen: boolean;
    companyId: number;
    /** IDs não-secretos já guardados (username/database) — para pré-preencher. Sem senha. */
    initialConfig?: Partial<PingwinConfigFields> | null;
    isReconfigure?: boolean;
    onClose: () => void;
    onSaved: () => void;
};

export default function PingwinConnectModal({ isOpen, companyId, initialConfig, isReconfigure, onClose, onSaved }: Props) {
    const [values, setValues] = useState<PingwinConfigFields>(emptyValues());
    const [password, setPassword] = useState("");
    const [showPassword, setShowPassword] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Ao (re)abrir: pré-preenche utilizador/base de dados; a senha fica SEMPRE vazia.
    useEffect(() => {
        if (!isOpen) return;
        setValues({ ...emptyValues(), ...(initialConfig ?? {}) });
        setPassword("");
        setShowPassword(false);
        setError(null);
    }, [isOpen, initialConfig]);

    const setField = (key: keyof PingwinConfigFields, v: string) =>
        setValues((prev) => ({ ...prev, [key]: v }));

    const missingRequired = useMemo(
        () => !values.username.trim() || !values.database.trim() || !password.trim(),
        [values, password]
    );

    const submit = async () => {
        if (missingRequired) {
            setError("Preenche o utilizador, a base de dados e a senha.");
            return;
        }
        setSaving(true);
        setError(null);
        try {
            await connectPingwin(companyId, {
                username: values.username.trim(),
                database: values.database.trim(),
                password: password.trim(),
            });
            toast.info("Credenciais guardadas — a validar a ligação. Serás notificado quando terminar.");
            onSaved();
            onClose();
        } catch (e: any) {
            const msg =
                e?.errors?.connection?.[0] ||
                (e?.errors && Object.values(e.errors)?.[0] && (Object.values(e.errors)[0] as any)[0]) ||
                e?.message ||
                "Não foi possível ligar ao PingWin. Verifica as credenciais.";
            setError(String(msg));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal isOpen={isOpen} toggle={saving ? undefined : onClose} centered>
            <ModalHeader toggle={saving ? undefined : onClose}>
                {isReconfigure ? "Reconfigurar PingWin" : "Ligar PingWin"}
            </ModalHeader>
            <ModalBody>
                <p className="text-muted fs-13">
                    Ao guardar, as credenciais ficam gravadas (com a senha cifrada) e a XPLENDOR
                    <strong> valida a ligação em segundo plano</strong> (teste real de login + logout).
                    Serás <strong>notificado no sino</strong> quando terminar — ligado, ou o motivo se falhar.
                    Os restantes parâmetros (servidor, versão, relatório) são geridos globalmente.
                </p>

                {error && (
                    <div className="alert alert-danger py-2 px-3 fs-13" role="alert">
                        <i className="ri-error-warning-line me-1" /> {error}
                    </div>
                )}

                <div className="mb-3">
                    <label className="form-label">Utilizador / Login</label>
                    <input
                        type="text"
                        className="form-control"
                        placeholder="operador"
                        value={values.username}
                        onChange={(e) => setField("username", e.target.value)}
                        disabled={saving}
                    />
                </div>

                <div className="mb-3">
                    <label className="form-label">Base de dados (X-Database)</label>
                    <input
                        type="text"
                        className="form-control"
                        placeholder="identificador do restaurante (ex.: REST0001)"
                        value={values.database}
                        onChange={(e) => setField("database", e.target.value)}
                        disabled={saving}
                    />
                </div>

                <div className="mb-2">
                    <label className="form-label">Senha</label>
                    <div className="position-relative auth-pass-inputgroup">
                        <input
                            type={showPassword ? "text" : "password"}
                            className="form-control pe-5"
                            placeholder={isReconfigure ? "•••••••• (escreve para atualizar)" : "senha do PingWin"}
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            autoComplete="new-password"
                            disabled={saving}
                        />
                        <button
                            type="button"
                            className="btn btn-link position-absolute end-0 top-0 text-decoration-none text-muted"
                            onClick={() => setShowPassword((s) => !s)}
                            tabIndex={-1}
                        >
                            <i className={showPassword ? "ri-eye-off-fill" : "ri-eye-fill"} />
                        </button>
                    </div>
                    <div className="form-text fs-11">
                        Por segurança, a senha guardada não é mostrada. Escreve-a para validar/guardar.
                    </div>
                </div>
            </ModalBody>
            <ModalFooter>
                <button className="btn btn-light" onClick={onClose} disabled={saving}>Cancelar</button>
                <button className="btn btn-primary" onClick={submit} disabled={saving || missingRequired}>
                    {saving ? <><Spinner size="sm" className="me-1" /> A validar ligação…</> : <><i className="ri-links-line me-1" /> Guardar e validar</>}
                </button>
            </ModalFooter>
        </Modal>
    );
}
