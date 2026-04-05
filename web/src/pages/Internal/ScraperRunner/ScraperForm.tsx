import React, { useEffect, useRef, useState } from "react";
import {
    Alert,
    Button,
    Col,
    FormGroup,
    Input,
    Label,
    Row,
    Spinner,
} from "reactstrap";
import { getScraperExecutionApi, runScraperApi } from "helpers/laravel_helper";

interface ScraperFormProps {
    companyId: number;
    onRunComplete: () => void;
}

interface ExecutionResult {
    id: number;
    status: "success" | "failed";
    total_raw: number;
    total_normalized: number;
    total_sent: number;
    total_failed: number;
    error_message: string | null;
    duration_seconds: number | null;
}

const EMPTY_FILTERS = {
    brand: "",
    model: "",
    year_from: "",
    year_to: "",
    fuel_type: "",
    transmission: "",
    price_from: "",
    price_to: "",
};

const ScraperForm: React.FC<ScraperFormProps> = ({ companyId, onRunComplete }) => {
    const [source] = useState("standvirtual");
    const [filters, setFilters] = useState(EMPTY_FILTERS);
    const [running, setRunning] = useState(false);
    const [result, setResult] = useState<ExecutionResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => {
        return () => {
            if (pollRef.current) clearInterval(pollRef.current);
        };
    }, []);

    const setField = (key: keyof typeof EMPTY_FILTERS) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
        setFilters((prev) => ({ ...prev, [key]: e.target.value }));
    };

    const buildFilters = () => {
        const out: Record<string, any> = {};
        if (filters.brand) out.brand = filters.brand;
        if (filters.model) out.model = filters.model;
        if (filters.year_from) out.year_from = Number(filters.year_from);
        if (filters.year_to) out.year_to = Number(filters.year_to);
        if (filters.fuel_type) out.fuel_type = filters.fuel_type;
        if (filters.transmission) out.transmission = filters.transmission;
        if (filters.price_from) out.price_from = Number(filters.price_from);
        if (filters.price_to) out.price_to = Number(filters.price_to);
        return out;
    };

    const startPolling = (runId: number) => {
        pollRef.current = setInterval(async () => {
            try {
                const res: any = await getScraperExecutionApi(companyId, runId);
                const exec = res.data;

                if (exec.status === "success" || exec.status === "failed") {
                    if (pollRef.current) clearInterval(pollRef.current);
                    setRunning(false);
                    setResult(exec);
                    onRunComplete();
                }
            } catch {
                if (pollRef.current) clearInterval(pollRef.current);
                setRunning(false);
                setError("Erro ao verificar estado da execução.");
            }
        }, 3000);
    };

    const handleSubmit = async (mode: "preview" | "run") => {
        if (pollRef.current) clearInterval(pollRef.current);
        setRunning(true);
        setResult(null);
        setError(null);

        try {
            const res: any = await runScraperApi(companyId, {
                source,
                mode,
                filters: buildFilters(),
            });
            startPolling(res.data.run_id);
        } catch (err: any) {
            setRunning(false);
            setError(err?.errors?.source?.[0] ?? err ?? "Erro ao iniciar scraping.");
        }
    };

    return (
        <div>
            <Row className="g-3">
                <Col md={4}>
                    <FormGroup>
                        <Label className="form-label">Source</Label>
                        <Input type="select" value={source} disabled>
                            <option value="standvirtual">Standvirtual</option>
                        </Input>
                    </FormGroup>
                </Col>
                <Col md={4}>
                    <FormGroup>
                        <Label className="form-label">Marca</Label>
                        <Input type="text" placeholder="ex: BMW" value={filters.brand} onChange={setField("brand")} disabled={running} />
                    </FormGroup>
                </Col>
                <Col md={4}>
                    <FormGroup>
                        <Label className="form-label">Modelo</Label>
                        <Input type="text" placeholder="ex: Série 3" value={filters.model} onChange={setField("model")} disabled={running} />
                    </FormGroup>
                </Col>
                <Col md={3}>
                    <FormGroup>
                        <Label className="form-label">Ano mín.</Label>
                        <Input type="number" placeholder="2018" value={filters.year_from} onChange={setField("year_from")} disabled={running} />
                    </FormGroup>
                </Col>
                <Col md={3}>
                    <FormGroup>
                        <Label className="form-label">Ano máx.</Label>
                        <Input type="number" placeholder="2024" value={filters.year_to} onChange={setField("year_to")} disabled={running} />
                    </FormGroup>
                </Col>
                <Col md={3}>
                    <FormGroup>
                        <Label className="form-label">Combustível</Label>
                        <Input type="select" value={filters.fuel_type} onChange={setField("fuel_type")} disabled={running}>
                            <option value="">Todos</option>
                            <option value="gasoline">Gasolina</option>
                            <option value="diesel">Diesel</option>
                            <option value="electric">Elétrico</option>
                            <option value="hybrid">Híbrido</option>
                        </Input>
                    </FormGroup>
                </Col>
                <Col md={3}>
                    <FormGroup>
                        <Label className="form-label">Transmissão</Label>
                        <Input type="select" value={filters.transmission} onChange={setField("transmission")} disabled={running}>
                            <option value="">Todas</option>
                            <option value="manual">Manual</option>
                            <option value="automatic">Automática</option>
                        </Input>
                    </FormGroup>
                </Col>
                <Col md={3}>
                    <FormGroup>
                        <Label className="form-label">Preço mín. (€)</Label>
                        <Input type="number" placeholder="5000" value={filters.price_from} onChange={setField("price_from")} disabled={running} />
                    </FormGroup>
                </Col>
                <Col md={3}>
                    <FormGroup>
                        <Label className="form-label">Preço máx. (€)</Label>
                        <Input type="number" placeholder="30000" value={filters.price_to} onChange={setField("price_to")} disabled={running} />
                    </FormGroup>
                </Col>
            </Row>

            <div className="d-flex gap-2 mt-2">
                <Button
                    color="primary"
                    outline
                    disabled={running}
                    onClick={() => handleSubmit("preview")}
                >
                    {running ? <Spinner size="sm" className="me-2" /> : <i className="ri-eye-line me-2" />}
                    Preview
                </Button>
                <Button
                    color="success"
                    disabled={running}
                    onClick={() => handleSubmit("run")}
                >
                    {running ? <Spinner size="sm" className="me-2" /> : <i className="ri-save-line me-2" />}
                    Gravar
                </Button>
                {running && (
                    <span className="align-self-center text-muted ms-2 fs-13">
                        A correr... a verificar de 3 em 3 segundos
                    </span>
                )}
            </div>

            {error && (
                <Alert color="danger" className="mt-3 mb-0">
                    {error}
                </Alert>
            )}

            {result && result.status === "success" && (
                <Alert color="success" className="mt-3 mb-0">
                    <i className="ri-checkbox-circle-line me-2" />
                    <strong>Concluído{result.duration_seconds != null ? ` em ${result.duration_seconds}s` : ""}</strong>
                    <span className="ms-2 text-muted">
                        {result.total_raw} encontrados → {result.total_normalized} normalizados → {result.total_sent} guardados → {result.total_failed} falharam
                    </span>
                </Alert>
            )}

            {result && result.status === "failed" && (
                <Alert color="danger" className="mt-3 mb-0">
                    <i className="ri-close-circle-line me-2" />
                    <strong>Falhou</strong>
                    {result.error_message && <span className="ms-2">{result.error_message}</span>}
                </Alert>
            )}
        </div>
    );
};

export default ScraperForm;
