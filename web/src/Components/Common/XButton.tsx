import React from "react";

type Variant =
    | "primary"
    | "secondary"
    | "success"
    | "info"
    | "warning"
    | "danger"
    | "dark"
    | "light";

type Size = "sm" | "md" | "lg";

interface XButtonProps
    extends React.ButtonHTMLAttributes<HTMLButtonElement> {
    type?: "button" | "submit" | "reset";
    variant?: Variant;
    outline?: boolean;
    soft?: boolean;
    gradient?: boolean;
    rounded?: boolean;
    size?: Size;
    icon?: React.ReactNode;
    iconPosition?: "left" | "right";
    loading?: boolean;
    fullWidth?: boolean;
}

const XButton: React.FC<XButtonProps> = ({
    type = "button",
    variant = "primary",
    outline = false,
    soft = false,
    gradient = false,
    rounded = false,
    size = "md",
    icon,
    iconPosition = "left",
    loading = false,
    fullWidth = false,
    children,
    className = "",
    disabled,
    ...rest
}) => {
    const baseClass = "btn waves-effect waves-light";

    const variantClass = outline
        ? `btn-outline-${variant}`
        : soft
            ? `btn-soft-${variant}`
            : `btn-${variant}`;

    const gradientClass = gradient ? "bg-gradient" : "";

    const roundedClass = rounded ? "rounded-pill" : "";

    const sizeClass =
        size === "lg"
            ? "btn-lg"
            : size === "sm"
                ? "btn-sm"
                : "";

    const fullWidthClass = fullWidth ? "w-100" : "";

    const classes = [
        baseClass,
        variantClass,
        gradientClass,
        roundedClass,
        sizeClass,
        fullWidthClass,
        className,
    ]
        .filter(Boolean)
        .join(" ");

    return (
        <button
            type={type}
            className={classes}
            disabled={disabled || loading}
            {...rest}
        >
            {loading && (
                <span className="spinner-border spinner-border-sm me-2" />
            )}

            {icon && iconPosition === "left" && !loading && (
                <span className="me-2">{icon}</span>
            )}

            {children}

            {icon && iconPosition === "right" && !loading && (
                <span className="ms-2">{icon}</span>
            )}
        </button>
    );
};

export default XButton;