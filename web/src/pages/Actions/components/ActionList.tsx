interface ActionListProps {
    actions: string[];
}

export default function ActionList({ actions }: ActionListProps) {
    return (
        <div className="d-grid gap-2">
            {actions.slice(0, 3).map((action) => (
                <div key={action} className="d-flex align-items-start gap-2 text-body fs-14">
                    <span className="fw-semibold" style={{ lineHeight: 1.4 }}>→</span>
                    <span>{action}</span>
                </div>
            ))}
        </div>
    );
}
