import { Navigate, useLocation, useParams } from "react-router-dom";

/**
 * Um ecrã que mudou de sítio no menu: o URL antigo redireciona para o novo, com os mesmos parâmetros,
 * a mesma query (por exemplo ?tab=stock) e o mesmo estado.
 */
export function MovedTo({ to }: { to: string }) {
    const params = useParams();
    const location = useLocation();
    const path = to.replace(/:(\w+)/g, (_, k) => encodeURIComponent(params[k] ?? ""));
    return <Navigate to={`${path}${location.search}${location.hash}`} state={location.state} replace />;
}
