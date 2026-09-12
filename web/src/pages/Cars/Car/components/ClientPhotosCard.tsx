import { useEffect, useState } from "react";
import { Card, CardBody, CardHeader, Spinner } from "reactstrap";
import axios from "axios";

/**
 * DMS Pós-venda (Incremento 2) — LADO INTERNO: as fotos que o cliente carregou
 * no relatório, para o stand ver/descarregar (redes sociais). Autenticado,
 * tenant-scoped pelo endpoint. Só mostra as que existem (apagadas foram mesmo
 * removidas — RGPD).
 */
const PUBLIC_URL = process.env.REACT_APP_PUBLIC_URL ?? "";

interface ClientPhoto { id: number; url: string; created_at?: string | null; }

const absUrl = (p: string): string => (p.startsWith("http") ? p : PUBLIC_URL + p);

export default function ClientPhotosCard({ companyId, carId }: { companyId: number; carId: number }) {
    const [photos, setPhotos] = useState<ClientPhoto[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let alive = true;
        setLoading(true);
        axios
            .get(`/companies/${companyId}/cars/${carId}/satisfaction-report/photos`)
            .then((res: any) => { if (alive) setPhotos(res?.data?.photos ?? []); })
            .catch(() => { if (alive) setPhotos([]); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId, carId]);

    return (
        <Card className="mt-3 mb-0">
            <CardHeader>
                <h5 className="mb-0"><i className="ri-camera-line me-1 text-primary" /> Fotos do cliente</h5>
                <small className="text-muted">Fotos que o cliente partilhou no relatório de pós-venda — usa nas redes sociais.</small>
            </CardHeader>
            <CardBody>
                {loading ? (
                    <div className="d-flex align-items-center gap-2 text-muted fs-13"><Spinner size="sm" /> A carregar…</div>
                ) : photos.length === 0 ? (
                    <p className="text-muted fs-13 mb-0">O cliente ainda não carregou fotos.</p>
                ) : (
                    <div className="d-flex flex-wrap gap-2">
                        {photos.map((p) => (
                            <a
                                key={p.id}
                                href={absUrl(p.url)}
                                target="_blank"
                                rel="noopener noreferrer"
                                download
                                title="Abrir / descarregar"
                                style={{ display: "block", width: 120, height: 120, borderRadius: 12, overflow: "hidden", boxShadow: "0 1px 4px rgba(15,23,42,.12)" }}
                            >
                                <img src={absUrl(p.url)} alt="Foto do cliente" style={{ width: "100%", height: "100%", objectFit: "cover" }} />
                            </a>
                        ))}
                    </div>
                )}
            </CardBody>
        </Card>
    );
}
