import { DefaultValue } from '@mantine/core/lib/MultiSelect/DefaultValue/DefaultValue';
import { Path, UseFormRegister } from "react-hook-form"
import React from 'react';

interface InputProps {
    className?: string;
    classNameInput?: string;
    type: React.HTMLInputTypeAttribute;
    placeholder?: string;
    label?: string;
    required?: boolean;
    disabled?: boolean;
    multiple?: boolean;
    min?: string;
    max?: string;
    step?: string;
    accept?: string;
    register?: UseFormRegister<any>;
    name: Path<any>;
    children?: string | string[] | React.ReactElement | React.ReactElement[];
    value?: string;
    onChange?: (value: string) => void;
}

export default function UInput({
    className,
    classNameInput,
    type,
    placeholder,
    label,
    required,
    disabled,
    multiple,
    min,
    max,
    step,
    accept,
    register,
    name,
    children,
    value,
    onChange
}: InputProps) {
    return (
        <div className={className}>
            {label && (
                <label>{label}:
                    {required &&
                        (<span className='ml-1 text-danger'>*</span>)}
                </label>
            )}
            <div className='flex'>
                <input
                    {...(register && name ? register(name, { required }) : {})}
                    type={type}
                    placeholder={placeholder}
                    className={`${type === 'checkbox' ? 'form-checkbox outline-primary' : 'form-input'} col-span-6 disabled:cursor-not-allowed disabled:bg-gray-100 ${classNameInput}`}
                    disabled={disabled}
                    min={min}
                    max={max}
                    multiple={multiple}
                    step={step}
                    required={required}
                    accept={accept}
                    value={value}
                    onChange={(e) => onChange?.(e.target.value)}
                />
                {children && (
                    <div
                        className="bg-[#eee] flex justify-center items-center ltr:rounded-r-md rtl:rounded-l-md px-3 font-semibold border ltr:border-r-0 rtl:border-l-0 border-[#e0e6ed] dark:border-[#17263c] dark:bg-[#1b2e4b]"
                    >
                        {children}
                    </div>
                )}
            </div>
        </div>
    );
}