"use client";

// React
import { useState } from "react";
// Icon
import IconX from "./icon/icon-x";

export default function UAlert({
    type = 'primary',
    isCloseButton = false,
    children,
}: {
    type?: 'primary' | 'secondary' | 'success' | 'warning' | 'error' | 'info';
    isCloseButton?: boolean;
    children?: React.ReactNode;
}) {

    const [isOpen, setIsOpen] = useState(false);

    return (
        <div className={`${isOpen ? 'hidden' : 'flex'} items-center p-3.5 rounded text-${type} bg-${type}-light dark:bg-${type}-dark-light`}>
            <span className="ltr:pr-2 rtl:pl-2">
                {children}
            </span>
            {!isCloseButton && (
                <button onClick={() => setIsOpen(true)} type="button" className="ltr:ml-auto rtl:mr-auto hover:opacity-80">
                    <IconX className="h-5 w-5" />
                </button>
            )}
        </div>
    )
}