import { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

interface ButtonProps {
    disabled?: boolean;
    isOutline?: boolean;
    isRounded?: boolean;
    variants?: 'primary' | 'info' | 'success' | 'warning' | 'danger' | 'secondary' | 'dark';
    size?: 'btn-sm' | 'btn-md' | 'btn-lg';
    children?: string | JSX.Element | JSX.Element[] | JSX.Element;
    type?: "button" | "submit" | "reset";
    className?: string;
    loading?: boolean;
    onClick?: (event: React.MouseEvent<HTMLButtonElement>) => void;
    icon?: ReactNode;
    title?: string;
    /**Usado para exibir apenas o ícone */
    isIconOnly?: boolean;
}

export default function UButton({
    disabled,
    isOutline,
    isRounded,
    variants,
    size,
    children,
    type,
    className,
    loading,
    onClick,
    icon,
    title,
    isIconOnly
}: ButtonProps) {

    const { t } = useTranslation();

    const baseVariant = variants ?? 'primary';
    const btnClasses = `
    btn btn-${isOutline ? 'outline-' : ''}${baseVariant}
    ${isRounded ? 'rounded-full' : ''}
    ${size ?? ''}
    ${className}
  `.trim();

    return (
        <button
            title={title}
            disabled={disabled || loading}
            onClick={onClick}
            type={type}
            className={isIconOnly ? className : btnClasses}
        >
            {/* Spinner de loading */}
            {loading && (
                <span
                    className={`animate-spin border-2 border-current border-l-transparent rounded-full w-5 h-5 inline-block align-middle ${children || icon ? 'ltr:mr-2 rtl:ml-2' : ''}`}
                ></span>
            )}

            {/* Ícone, se existir */}
            {icon && (
                isIconOnly ? (
                    icon
                ) : (
                    <span
                        className={`inline-flex items-center justify-center ${children ? 'ltr:mr-2 rtl:ml-2' : ''}`}>
                        {icon}
                    </span>
                )

            )
            }

            {/* Texto (children) */}
            {children ?? (!icon && !loading ? t('to_send') : null)}
        </button >
    );
}