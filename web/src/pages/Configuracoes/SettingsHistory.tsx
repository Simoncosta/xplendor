import { SettingHistory, fmtSettingValue } from "common/models/settings.model";

/** XPLENDOR — Configurações: o histórico (quem, quando, antes e depois). */
export default function SettingsHistory({ rows, showCompany = false }: { rows: SettingHistory[]; showCompany?: boolean }) {
    if (rows.length === 0) return <p className="text-muted fs-13 mb-0">Ainda sem alterações.</p>;
    return (
        <div className="table-responsive">
            <table className="table table-sm align-middle mb-0 fs-13" data-testid="settings-history">
                <thead className="table-light">
                    <tr><th>Quando</th>{showCompany && <th>Empresa</th>}<th>Definição</th><th>Antes</th><th>Depois</th><th>Quem</th></tr>
                </thead>
                <tbody>
                    {rows.map((h, i) => (
                        <tr key={i}>
                            <td className="text-nowrap">{h.at ? new Date(h.at).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" }) : "—"}</td>
                            {showCompany && <td>{h.company ?? "Plataforma"}</td>}
                            <td>{h.label}{h.reason && <div className="text-muted fs-12">{h.reason}</div>}</td>
                            <td>{fmtSettingValue(h.before)}</td>
                            <td className="fw-medium">{fmtSettingValue(h.after)}</td>
                            <td>{h.user ?? "Migração"}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
