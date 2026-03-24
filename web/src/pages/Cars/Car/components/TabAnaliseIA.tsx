import { Col, Row, Progress } from "reactstrap";
import ReactApexChart from "react-apexcharts";
import XButton from "Components/Common/XButton";

interface Props {
    ips: any;
    ai: any;
    ipsRadialOptions: any;
    ipsHistoryOptions: any;
    ipsClassBadge: (cls: string) => string;
    ipsFactorLabels: Record<string, { label: string; max: number; icon: string; color: string }>;
    forecastOptions: any;
    fmtDate: (d: string) => string;
    carId: string | undefined;
    companyId: number;
    onRecalculate: () => void;
    onGenerateAi: () => void;
}

export default function TabAnaliseIA({
    ips, ai,
    ipsRadialOptions, ipsHistoryOptions,
    ipsClassBadge, ipsFactorLabels,
    forecastOptions,
    fmtDate, carId, companyId,
    onRecalculate, onGenerateAi,
}: Props) {
    return (
        <Row className="g-3">

            {/* ── IPS Índice de Potencial de Venda ───────────────────────────────────────────── */}
            <Col md={4}>
                <h6 className="fs-13 fw-semibold mb-3">
                    <i className="ri-award-line me-2 text-primary" />
                    Índice de Potencial de Venda
                </h6>

                {ips ? (
                    <>
                        <div className="text-center mb-2">
                            <ReactApexChart options={ipsRadialOptions} series={[ips.score]} type="radialBar" height={200} />
                            <div className="d-flex align-items-center justify-content-center gap-2 mt-1">
                                <span className={`badge rounded-pill fs-12 px-3 py-2 text-dark ${ipsClassBadge(ips.classification)}`}>
                                    {ips.classification === "hot" ? "🔥 Hot" : ips.classification === "warm" ? "⚠️ Warm" : "❄️ Cold"}
                                </span>
                                {ips.price_vs_market !== null && (
                                    <span className={`badge rounded-pill fs-11 text-dark ${Number(ips.price_vs_market) < 0 ? "badge-soft-success" : "badge-soft-danger"}`}>
                                        {Number(ips.price_vs_market) < 0 ? "▼" : "▲"} {Math.abs(Number(ips.price_vs_market))}% vs mercado
                                    </span>
                                )}
                            </div>
                        </div>

                        <div className="vstack gap-2 mt-3">
                            {Object.entries(ips.breakdown || {}).map(([key, pts]: any) => {
                                const factor = ipsFactorLabels[key];
                                if (!factor) return null;
                                const pct = Math.round((pts / factor.max) * 100);
                                return (
                                    <div key={key} style={{ border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.6rem 0.75rem", background: "#fff" }}>
                                        <div className="d-flex align-items-center justify-content-between mb-1">
                                            <div className="d-flex align-items-center gap-2">
                                                <i className={`${factor.icon} text-${factor.color} fs-14`} />
                                                <span className="fs-12 fw-medium">{factor.label}</span>
                                            </div>
                                            <span className="fs-12 fw-semibold">
                                                {pts}<span className="text-muted fw-normal">/{factor.max}</span>
                                            </span>
                                        </div>
                                        <Progress color={pct >= 70 ? "success" : pct >= 40 ? "warning" : "danger"} value={pct} style={{ height: "4px" }} />
                                    </div>
                                );
                            })}
                        </div>

                        <div className="mt-3">
                            <div className="d-flex align-items-center justify-content-between mb-2">
                                <span className="fs-12 fw-semibold text-muted text-uppercase">Histórico (90 dias)</span>
                                <span className="fs-11 text-muted">
                                    {ips.history?.length || 0} cálculo{(ips.history?.length || 0) !== 1 ? "s" : ""}
                                </span>
                            </div>
                            {ips.history && ips.history.length > 1 ? (
                                <ReactApexChart
                                    options={ipsHistoryOptions}
                                    series={[{ name: "Score", data: ips.history.map((h: any) => h.score) }]}
                                    type="line" height={80}
                                />
                            ) : (
                                <div style={{ border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.6rem 0.75rem", background: "#fff" }}>
                                    <p className="fs-12 text-muted mb-0 text-center">Histórico a construir — recalcula diariamente</p>
                                </div>
                            )}
                        </div>

                        <div className="mt-3 text-end">
                            <span className="fs-11 text-muted me-2">Calculado: {fmtDate(ips.calculated_at)}</span>
                            <button className="btn btn-soft-primary btn-sm" onClick={onRecalculate}>
                                <i className="ri-refresh-line me-1" /> Recalcular
                            </button>
                        </div>
                    </>
                ) : (
                    <div className="text-center py-4 text-muted">
                        <i className="ri-award-line fs-1 d-block mb-2" />
                        <p className="fs-13 mb-2">Score ainda não calculado</p>
                        <button className="btn btn-soft-primary btn-sm" onClick={onRecalculate}>
                            <i className="ri-play-line me-1" /> Calcular agora
                        </button>
                    </div>
                )}
            </Col>

            {/* ── Inteligência ────────────────────────────────────── */}
            {ai ? (
                <>
                    {/* Público-alvo + argumentos */}
                    <Col md={4}>
                        <h6 className="fs-13 fw-semibold mb-3">
                            <i className="ri-user-heart-line me-2 text-info" />
                            Público-Alvo
                        </h6>
                        {ai.publico_alvo && (
                            <div className="vstack gap-2">
                                {[
                                    { icon: "ri-calendar-2-line", color: "primary", label: "Faixa etária", val: ai.publico_alvo.faixa_etaria },
                                    { icon: "ri-men-line", color: "info", label: "Género", val: ai.publico_alvo.genero_predominante },
                                    { icon: "ri-briefcase-4-line", color: "success", label: "Perfil profissional", val: ai.publico_alvo.perfil_profissional },
                                    { icon: "ri-trophy-line", color: "warning", label: "Estilo de vida", val: ai.publico_alvo.estilo_de_vida },
                                    { icon: "ri-search-eye-line", color: "danger", label: "Comportamento", val: ai.publico_alvo.comportamento_de_compra },
                                ].map((row, idx) => (
                                    <div key={idx} className="d-flex align-items-start gap-2" style={{ border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.6rem 0.75rem", background: "#fff" }}>
                                        <div className={`avatar-title rounded-circle bg-${row.color}-subtle text-${row.color} flex-shrink-0`} style={{ width: 30, height: 30, fontSize: 14, display: "flex", alignItems: "center", justifyContent: "center", marginTop: 2 }}>
                                            <i className={row.icon} />
                                        </div>
                                        <div style={{ minWidth: 0, flex: 1 }}>
                                            <p className="fs-11 text-muted mb-0">{row.label}</p>
                                            <p className="fs-13 fw-medium mb-0" style={{ whiteSpace: "normal", wordBreak: "break-word" }}>{row.val}</p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        <h6 className="fs-13 fw-semibold mt-4 mb-2">
                            <i className="ri-checkbox-circle-line me-2 text-success" />
                            Argumentos de Venda
                        </h6>
                        <div className="vstack gap-2">
                            {(ai.argumentos_de_venda || []).map((arg: string, idx: number) => (
                                <div key={idx} className="d-flex align-items-center gap-2 fs-13" style={{ border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.6rem 0.75rem", background: "#fff" }}>
                                    <i className="ri-check-line text-success fs-16 flex-shrink-0" />
                                    {arg}
                                </div>
                            ))}
                        </div>
                    </Col>

                    {/* Previsão + canais + copy */}
                    <Col md={4}>
                        <h6 className="fs-13 fw-semibold mb-3">
                            <i className="ri-line-chart-line me-2 text-success" />
                            Previsão de Venda
                        </h6>
                        <Row className="g-2 mb-3">
                            {[
                                { label: "7 dias", val: ai.previsao?.probabilidade_venda_7d, color: "danger" },
                                { label: "14 dias", val: ai.previsao?.probabilidade_venda_14d, color: "warning" },
                                { label: "30 dias", val: ai.previsao?.probabilidade_venda_30d, color: "success" },
                            ].map((p, idx) => (
                                <Col xs={4} key={idx}>
                                    <div className={`bg-${p.color}-subtle rounded text-center p-2`}>
                                        <div className={`fs-18 fw-bold text-${p.color}`}>{p.val}%</div>
                                        <div className="fs-11 text-muted">{p.label}</div>
                                    </div>
                                </Col>
                            ))}
                        </Row>

                        <div className="mt-3 p-3 rounded" style={{ background: "#f8f9fa", border: "1px dashed #e9ebec", fontSize: 12 }}>
                            <p className="fw-semibold text-muted text-uppercase mb-2" style={{ fontSize: 11, letterSpacing: "0.5px" }}>
                                <i className="ri-information-line me-1" />O que significa este score?
                            </p>
                            <p className="text-muted mb-2">Probabilidade estimada de venda com base no preço vs mercado, engajamento, dias em stock e histórico do modelo.</p>
                            <div className="vstack gap-1">
                                {[
                                    { badge: "badge-soft-danger", label: "7 dias", text: "Probabilidade imediata — sem ajustes" },
                                    { badge: "badge-soft-warning", label: "14 dias", text: "Com pequenos ajustes de preço ou distribuição" },
                                    { badge: "badge-soft-success", label: "30 dias", text: "Potencial máximo com otimização ativa" },
                                ].map((item, idx) => (
                                    <div key={idx} className="d-flex align-items-center gap-2">
                                        <span className={`badge text-dark ${item.badge}`} style={{ fontSize: 10, minWidth: 48 }}>{item.label}</span>
                                        <span className="text-muted">{item.text}</span>
                                    </div>
                                ))}
                            </div>
                            {ai.previsao?.condicao && (
                                <div className="mt-2 pt-2" style={{ borderTop: "1px dashed #e9ebec" }}>
                                    <p className="text-muted mb-0" style={{ fontSize: 11, fontStyle: "italic" }}>
                                        <i className="ri-lightbulb-line me-1 text-warning" />{ai.previsao.condicao}
                                    </p>
                                </div>
                            )}
                        </div>

                        <ReactApexChart
                            options={forecastOptions}
                            series={[{ name: "Probabilidade", data: [0, ai.previsao?.probabilidade_venda_7d, ai.previsao?.probabilidade_venda_14d, ai.previsao?.probabilidade_venda_30d] }]}
                            type="area" height={140}
                        />

                        <h6 className="fs-13 fw-semibold mt-4 mb-3">
                            <i className="ri-megaphone-line me-2 text-primary" />
                            Canais Recomendados
                        </h6>
                        {[
                            { data: ai.canal_principal, badge: "Principal", badgeClass: "bg-primary-subtle text-primary", accentColor: "#405189" },
                            { data: ai.canal_secundario, badge: "Secundário", badgeClass: "bg-info-subtle text-info", accentColor: "#299cdb" },
                        ].map((c, idx) => c.data && (
                            <div key={idx} className="mb-2" style={{ border: "1px dashed #e9ebec", borderLeft: `3px solid ${c.accentColor}`, borderRadius: "0.4rem", padding: "0.65rem 0.75rem", background: "#fff" }}>
                                <div className="d-flex align-items-center justify-content-between mb-1">
                                    <span className="fw-semibold fs-13">{c.data.canal}</span>
                                    <span className={`badge ${c.badgeClass} fs-11`}>{c.badge}</span>
                                </div>
                                <p className="text-muted fs-12 mb-0">{c.data.justificacao}</p>
                            </div>
                        ))}

                        {ai.sugestao_conteudo && (
                            <>
                                <h6 className="fs-13 fw-semibold mt-3 mb-2">
                                    <i className="ri-quill-pen-line me-2 text-warning" />
                                    Copy Sugerido
                                </h6>
                                <div className="vstack gap-2">
                                    {[
                                        { label: "Título do anúncio", val: ai.sugestao_conteudo.titulo_anuncio, bold: true, italic: false },
                                        { label: "Hook de vídeo", val: ai.sugestao_conteudo.hook_video, bold: false, italic: true },
                                        { label: "Copy curto", val: ai.sugestao_conteudo.copy_curto, bold: false, italic: false },
                                    ].map((item, idx) => (
                                        <div key={idx} style={{ border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.65rem 0.75rem", background: "#fff" }}>
                                            <p className="fs-11 text-primary fw-semibold text-uppercase mb-1">{item.label}</p>
                                            <p className={`fs-13 mb-0 ${item.bold ? "fw-semibold" : ""} ${item.italic ? "fst-italic text-muted" : ""}`}>{item.val}</p>
                                        </div>
                                    ))}
                                </div>
                            </>
                        )}
                    </Col>
                </>
            ) : (
                <Col md={8}>
                    <div className="text-center py-5 text-muted">
                        <i className="ri-cpu-line fs-1 d-block mb-3" />
                        <h5>Inteligência ainda não disponível</h5>
                        <p className="mb-3 fs-13">A análise será gerada automaticamente assim que existirem dados suficientes.</p>
                        <XButton onClick={onGenerateAi}>Gerar análise</XButton>
                    </div>
                </Col>
            )}
        </Row>
    );
}
