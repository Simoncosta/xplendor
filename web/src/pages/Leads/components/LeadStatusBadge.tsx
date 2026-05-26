import {
    UncontrolledDropdown,
    DropdownToggle,
    DropdownMenu,
    DropdownItem,
} from "reactstrap";

interface StatusConfig {
    label: string;
    variant: string;
}

const STATUS_CONFIG: Record<string, StatusConfig> = {
    new:        { label: "Novo",        variant: "primary"   },
    contacted:  { label: "Contactado",  variant: "secondary" },
    qualified:  { label: "Qualificado", variant: "warning"   },
    won:        { label: "Ganho",       variant: "success"   },
    lost:       { label: "Perdido",     variant: "danger"    },
    spam:       { label: "Spam",        variant: "dark"      },
};

const STATUS_ORDER = ["new", "contacted", "qualified", "won", "lost", "spam"];

interface LeadStatusBadgeProps {
    currentStatus: string;
    onChange: (newStatus: string) => void;
    disabled?: boolean;
    size?: "sm" | "md";
}

export default function LeadStatusBadge({
    currentStatus,
    onChange,
    disabled = false,
    size = "sm",
}: LeadStatusBadgeProps) {
    const config = STATUS_CONFIG[currentStatus] ?? { label: currentStatus, variant: "secondary" };
    const { variant } = config;

    const toggleClasses = [
        "badge",
        `bg-${variant}-subtle`,
        `text-${variant}`,
        "fw-medium",
        "border-0",
        size === "sm" ? "px-2 py-1 rounded-pill fs-12" : "px-3 py-2 rounded fs-13",
        disabled ? "opacity-50" : "",
    ].filter(Boolean).join(" ");

    return (
        <UncontrolledDropdown>
            <DropdownToggle
                tag="span"
                caret={false}
                disabled={disabled}
                className={toggleClasses}
                style={{ cursor: disabled ? "not-allowed" : "pointer", userSelect: "none" }}
            >
                <span
                    style={{
                        display: "inline-block",
                        width: 6,
                        height: 6,
                        borderRadius: "50%",
                        background: "currentColor",
                        marginRight: 5,
                        verticalAlign: "middle",
                    }}
                />
                {config.label}
                <i className="ri-arrow-down-s-line ms-1" style={{ fontSize: 11, verticalAlign: "middle" }} />
            </DropdownToggle>
            <DropdownMenu end>
                {STATUS_ORDER.map((key) => {
                    const item = STATUS_CONFIG[key];
                    const isActive = key === currentStatus;
                    return (
                        <DropdownItem
                            key={key}
                            onClick={() => !disabled && onChange(key)}
                            className="d-flex align-items-center justify-content-between gap-3"
                        >
                            <span className="d-flex align-items-center gap-2">
                                <span
                                    style={{
                                        display: "inline-block",
                                        width: 8,
                                        height: 8,
                                        borderRadius: "50%",
                                        background: `var(--bs-${item.variant})`,
                                        flexShrink: 0,
                                    }}
                                />
                                {item.label}
                            </span>
                            {isActive && <i className="ri-check-line text-success fs-14" />}
                        </DropdownItem>
                    );
                })}
            </DropdownMenu>
        </UncontrolledDropdown>
    );
}
