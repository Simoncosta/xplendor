import { useEffect, useState } from "react";
import { Alert, Button, Input, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { getCustomers, linkMailboxMessage } from "helpers/laravel_helper";

/** "Ligar a um cliente": procurar nos clientes da empresa (a mensagem fica guardada, ligada ao cliente; os anexos não). */
export default function LinkCustomerModal({ isOpen, toggle, companyId, uid, folder, onLinked }: {
    isOpen: boolean; toggle: () => void; companyId: number; uid: number; folder: string; onLinked: (c: { id: number; name: string }) => void;
}) {
    const [search, setSearch] = useState("");
    const [results, setResults] = useState<{ id: number; name: string; email?: string | null }[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState<number | null>(null);

    useEffect(() => {
        if (!isOpen) return;
        let alive = true;
        const t = setTimeout(() => {
            getCustomers(companyId, { search: search || undefined, perPage: 20 })
                .then((r: any) => { if (alive) { setResults(r?.data?.data ?? r?.data ?? []); setError(null); } })
                .catch((e: any) => { if (alive) setError(e?.__status === 403 ? "Não tem acesso aos clientes desta empresa." : (e?.message || "Não foi possível ler os clientes.")); });
        }, 250);
        return () => { alive = false; clearTimeout(t); };
    }, [isOpen, search, companyId]);

    const pick = async (c: { id: number; name: string }) => {
        setBusy(c.id);
        try {
            const r: any = await linkMailboxMessage(companyId, uid, folder, c.id);
            toast.success(r?.message || `Mensagem ligada a ${c.name}.`);
            onLinked(r?.data?.customer ?? c);
        } catch (e: any) {
            toast.error(e?.message || "Não foi possível ligar a mensagem.");
        } finally {
            setBusy(null);
        }
    };

    return (
        <Modal isOpen={isOpen} toggle={toggle} centered>
            <ModalHeader toggle={toggle}>Ligar a um cliente</ModalHeader>
            <ModalBody>
                <p className="text-muted fs-13">A mensagem fica guardada na XPLENDOR e aparece na "Conversa" da ficha do cliente. Os anexos não se guardam.</p>
                <Input placeholder="Procurar pelo nome" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Procurar cliente" />
                {error ? <Alert color="warning" className="mt-3 mb-0">{error}</Alert> : (
                    <div className="list-group mt-3" style={{ maxHeight: 300, overflowY: "auto" }} data-testid="link-customer-results">
                        {results.length === 0 ? <div className="text-muted fs-13">Sem clientes.</div> : results.map((c) => (
                            <button key={c.id} type="button" className="list-group-item list-group-item-action d-flex align-items-center" disabled={busy !== null} onClick={() => pick(c)}>
                                <span className="flex-grow-1 text-start">{c.name}{c.email ? <small className="text-muted ms-2">{c.email}</small> : null}</span>
                                {busy === c.id && <Spinner size="sm" />}
                            </button>
                        ))}
                    </div>
                )}
            </ModalBody>
            <ModalFooter><Button color="light" onClick={toggle}>Fechar</Button></ModalFooter>
        </Modal>
    );
}
