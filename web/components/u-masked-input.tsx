import React from 'react';
import MaskedInput from 'react-text-mask';

interface InputProps {
    className?: string;
    classNameInput?: string;
    type: React.HTMLInputTypeAttribute;
    placeholder: string;
    label?: string;
    required?: boolean;
    disabled?: boolean;
    mask: (string | RegExp)[];
    value?: string | number;
    onChange?: (event: any) => void;
    name?: string;
    removeMaskOnSubmit?: boolean;
}

export default function UMaskedInput({
    className,
    classNameInput,
    type,
    placeholder,
    label,
    required,
    disabled,
    mask,
    value,
    name,
    onChange,
    removeMaskOnSubmit = false,
}: InputProps) {

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const maskedValue = e.target.value;
        const rawValue = maskedValue.replace(/\D/g, ''); // remove tudo que não for número
        onChange?.(rawValue); // passa o valor limpo
    };

    return (
        <div className={className}>
            {label && (
                <label>{label}:
                    {required &&
                        (<span className='ml-1 text-danger'>*</span>)}
                </label>
            )}
            <MaskedInput
                name={name}
                required={required == true}
                type={type}
                placeholder={placeholder}
                className={`form-input ${classNameInput} disabled:cursor-not-allowed disabled:bg-gray-100 dark:disabled:bg-[#1b2e4b]`}
                disabled={disabled}
                mask={mask}
                value={value}
                onChange={(e) => {
                    removeMaskOnSubmit
                        ? handleChange(e)
                        : (typeof onChange === 'function'
                            ? onChange(e)
                            : '')
                }
                }
            />
        </div>
    );
}