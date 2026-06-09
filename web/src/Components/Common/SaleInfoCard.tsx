import React, { useState } from "react";
import { Button, Card, CardBody } from "reactstrap";
import SaleEditModal from "Components/Common/SaleEditModal";
import type { CarSpecsSale } from "types/api";

// Labels pt-PT para os enums de venda. Mantidas locais ao componente porque
// só fazem sentido neste contexto (não justificam helpers/labels.ts global).
const SALE_CHANNEL_LABELS: Record<string, string> = {
    online: "Online",
    in_person: "Presencial",
    referral: "Referência",
    trade_in: "Retoma",
};

const BUYER_GENDER_LABELS: Record<string, string> = {
    male: "Masculino",
    female: "Feminino",
    company: "Empresa",
};

const BUYER_AGE_RANGE_LABELS: Record<string, string> = {
    "18-30": "18 a 30 anos",
    "31-45": "31 a 45 anos",
    "46-60": "46 a 60 anos",
    "60+":  "Mais de 60 anos",
};

const labelOf = (value: string | null | undefined, map: Record<string, string>): string | null => {
    if (!value) return null;
    return map[value] ?? value;
};

const formatCurrency = (value: number | null): string | null => {
    if (value === null || value === undefined) return null;
    return new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);
};

const formatDate = (value: string | null): string | null => {
    if (!value) return null;
    try {
        return new Date(value).toLocaleDateString("pt-PT", {
            day: "2-digit",
            month: "2-digit",
            year: "numeric",
        });
    } catch {
        return value;
    }
};

interface SaleInfoCardProps {
    sale: CarSpecsSale | null;     // null permite "criar" via modal (car vendido sem registo)
    companyId: number;
    carId: number;
    onSaved: () => void;            // refrescar specs depois de gravar
}

export default function SaleInfoCard({ sale, companyId, carId, onSaved }: SaleInfoCardProps) {
    const [isEditOpen, setIsEditOpen] = useState(false);
    // null vs objecto: quando null, secção mostra placeholder + botão Adicionar.
    const safeSale: CarSpecsSale = sale ?? {
        sale_price: null,
        sale_channel: null,
        buyer_name: null,
        buyer_phone: null,
        buyer_email: null,
        buyer_gender: null,
        buyer_age_range: null,
        contact_consent: false,
        notes: null,
        sold_at: null,
    };
    const rows: Array<{ label: string; value: React.ReactNode | null }> = [
        { label: "Comprador",        value: safeSale.buyer_name },
        { label: "Telefone",         value: safeSale.buyer_phone
            ? <a href={`tel:${safeSale.buyer_phone}`} className="text-decoration-none">{safeSale.buyer_phone}</a>
            : null },
        { label: "Email",            value: safeSale.buyer_email
            ? <a href={`mailto:${safeSale.buyer_email}`} className="text-decoration-none">{safeSale.buyer_email}</a>
            : null },
        { label: "Canal de venda",   value: labelOf(safeSale.sale_channel, SALE_CHANNEL_LABELS) },
        { label: "Data da venda",    value: formatDate(safeSale.sold_at) },
        { label: "Preço de venda",   value: formatCurrency(safeSale.sale_price) },
        { label: "Género",           value: labelOf(safeSale.buyer_gender, BUYER_GENDER_LABELS) },
        { label: "Faixa etária",     value: labelOf(safeSale.buyer_age_range, BUYER_AGE_RANGE_LABELS) },
        { label: "Consentimento de contacto", value: safeSale.contact_consent ? "Sim" : "Não" },
    ];

    const hasAnyData = rows.some((r) => r.value != null && r.value !== "");

    return (
        <>
            <Card className="mt-4 mb-0">
                <CardBody>
                    <div className="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 pb-2 border-bottom">
                        <h6 className="mb-0 fw-semibold">
                            <i className="ri-shopping-bag-3-line me-2 text-success" />
                            Venda concluída
                        </h6>
                        <div className="d-flex align-items-center gap-2 flex-wrap">
                            <span className="badge bg-light text-muted fs-12">
                                <i className="ri-lock-line me-1" />
                                Dados do comprador — uso interno
                            </span>
                            <Button
                                color="success"
                                outline
                                size="sm"
                                onClick={() => setIsEditOpen(true)}
                            >
                                <i className={`me-1 ${sale ? "ri-edit-2-line" : "ri-add-line"}`} />
                                {sale ? "Editar" : "Adicionar dados"}
                            </Button>
                        </div>
                    </div>

                    {hasAnyData ? (
                        <>
                            <div className="row g-2" role="list" aria-label="Detalhes da venda">
                                {rows.map((row) => row.value != null && row.value !== "" && (
                                    <div key={row.label} className="col-md-6" role="listitem">
                                        <div
                                            className="d-flex align-items-center gap-2 fs-13"
                                            style={{ border: "1px dashed #e9ebec", borderRadius: "0.4rem", padding: "0.55rem 0.75rem", background: "#fff" }}
                                        >
                                            <span className="text-muted flex-grow-1">{row.label}</span>
                                            <span className="fw-medium text-end">{row.value}</span>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {safeSale.notes && (
                                <div className="mt-3">
                                    <p className="text-muted fs-12 mb-1">Notas</p>
                                    <div
                                        className="fs-13 bg-light rounded p-3"
                                        style={{ whiteSpace: "pre-wrap", lineHeight: 1.6 }}
                                    >
                                        {safeSale.notes}
                                    </div>
                                </div>
                            )}
                        </>
                    ) : (
                        <p className="text-muted fs-13 mb-0">
                            Esta viatura foi vendida sem dados do comprador registados.
                            Clica em <strong>Adicionar dados</strong> para preencher.
                        </p>
                    )}
                </CardBody>
            </Card>

            <SaleEditModal
                isOpen={isEditOpen}
                onClose={() => setIsEditOpen(false)}
                onSaved={onSaved}
                companyId={companyId}
                carId={carId}
                initial={sale}
            />
        </>
    );
}
