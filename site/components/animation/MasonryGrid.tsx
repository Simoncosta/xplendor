"use client";

import { useEffect, useRef } from "react";

export default function MasonryGrid({
  children,
  className = "",
  itemSelector = ".gallery__item",
  ...rest
}: {
  children: React.ReactNode;
  className?: string;
  itemSelector?: string;
}) {
  const isotopContainer = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    /////////////////////////////////////////////////////
    // Magnate Animation

    setTimeout(() => {
      const initIsotop = async () => {
        const Isotope = (await import("isotope-layout")).default;
        const imagesloaded = (await import("imagesloaded")).default;

        if (!isotopContainer.current) return;
        // Initialize Isotope in the mounted hook
        const isotope = new Isotope(isotopContainer.current, {
          itemSelector: itemSelector,
          layoutMode: "masonry", // or 'fitRows', depending on your layout needs
        });
        imagesloaded(isotopContainer.current).on("progress", function () {
          // Trigger Isotope layout
          isotope.layout();
        });
      };
      initIsotop();
    }, 100);
  }, [itemSelector]);
  return (
    <div className={className} ref={isotopContainer} {...rest}>
      {children}
    </div>
  );
}
