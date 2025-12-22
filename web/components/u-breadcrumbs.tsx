"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import React from "react";

export interface NavigationProps {
    href: string;
    title: string;
}

function MobileBackButton() {
    const router = useRouter();
    const pathname = usePathname();

    const isAtRoot = pathname === "/";

    if (isAtRoot) return null;

    return (
        <button
            onClick={() => router.back()}
            className="block md:hidden p-2 text-primary hover:scale-105 transition"
        >
            <svg
                width="16"
                height="16"
                viewBox="0 0 24 24"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
                className="rotate-180 w-6 h-6"
            >
                <path
                    d="M4 12H20M20 12L14 6M20 12L14 18"
                    stroke="currentColor"
                    strokeWidth="1.5"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                ></path>
            </svg>
        </button>
    );
}

const UBreadcrumbs = ({
    title,
    navigation,
}: {
    title: string;
    navigation: NavigationProps[];
}) => {
    return (
        <div className="flex items-center justify-between mb-4">
            <MobileBackButton />

            <h3 className="text-2xl font-semibold">{title}</h3>

            <ul className="hidden md:flex rtl:space-x-reverse">
                {navigation.map((nav, index) => {
                    const isLast = index === navigation.length - 1;

                    return (
                        <li key={index} className="flex items-center">
                            {/* barra separadora */}
                            {index !== 0 && (
                                <span className="text-gray-500 mx-2">/</span>
                            )}

                            {/* link ou texto final */}
                            {isLast ? (
                                <span className="text-gray-700">
                                    {nav.title}
                                </span>
                            ) : (
                                <Link
                                    href={nav.href}
                                    className="text-primary hover:underline"
                                >
                                    {nav.title}
                                </Link>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
};

export default UBreadcrumbs;