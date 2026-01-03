/** @jsxImportSource @emotion/react */
import { Tab } from "@headlessui/react";
import { Fragment, ReactElement, useState } from "react";
import { css, keyframes } from "@emotion/react";

interface TabItem {
    label: string;
    icon?: ReactElement;
}

interface UTabPanelProps {
    tabs: TabItem[];
    panels: ReactElement[];
    className?: string;
    selectedIndex?: number;
    onChange?: (index: number) => void;
    responsive?: boolean;
}

const fadeIn = keyframes`
  from {
    opacity: 0;
    transform: translateY(8px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
`;

export default function UTabPanel({
    tabs,
    panels,
    className = "",
    selectedIndex: controlledIndex,
    onChange,
    responsive = true,
}: UTabPanelProps) {
    const [internalIndex, setInternalIndex] = useState(0);
    const currentIndex = controlledIndex ?? internalIndex;

    const handleChange = (index: number) => {
        setInternalIndex(index);
        onChange?.(index);
    };

    return (
        <Tab.Group selectedIndex={currentIndex} onChange={handleChange} className={className}>
            <div
                className="col-span-12 w-full"
                css={css`background: transparent; width: 100%; gap: 1rem;`}
            >
                {/* ---- TABS DESKTOP ---- */}
                <Tab.List className="flex flex-wrap border-b border-white-light dark:border-[#191e3a]">
                    {tabs.map((tab, index) => (
                        <Tab key={index} as={Fragment}>
                            {({ selected }) => (
                                <button
                                    className={`group relative flex items-center gap-2 px-5 py-3 text-sm font-medium transition-colors ${selected ? 'border-b !border-primary text-primary !outline-none' : ''}
                                                    before:inline-block' flex items-center border-transparent p-5 py-3 hover:border-b hover:border-primary hover:text-primary`}
                                >
                                    {tab?.icon && (
                                        <span className="flex items-center justify-center w-4 h-4 text-inherit">
                                            {tab.icon}
                                        </span>
                                    )}
                                    <span className="leading-none">{tab.label}</span>
                                </button>
                            )}
                        </Tab>
                    ))}
                </Tab.List>
            </div>

            <div className="col-span-12 w-full"
                css={css`
                        background: transparent;
                        width: 100%;
                        `}>
                {/* ---- CONTEÚDO ---- */}
                <Tab.Panels >
                    {panels.map((panel, index) => (
                        <Tab.Panel
                            unmount={true}
                            key={index}
                            css={css`animation: ${fadeIn} 0.25s ease;`}>
                            {panel}
                        </Tab.Panel>
                    ))}
                </Tab.Panels>
            </div>
        </Tab.Group>
    );
}