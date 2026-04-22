import type { ReactNode } from "react";

interface ContactProbabilityPanelProps {
    carId?: number | string;
    companyId?: number;
    contactProbability?: any;
    primaryAction?: any;
    children?: ReactNode;
}

export default function ContactProbabilityPanel({ children }: ContactProbabilityPanelProps) {
    return children ? <>{children}</> : null;
}
