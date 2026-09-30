"use client";

import { usePathname } from "next/navigation";
import { useLenis } from "lenis/react";
import { useEffect, useRef } from "react";
import { ScrollTrigger } from "gsap/ScrollTrigger";

export default function ScrollToTopOnRouteChange() {
  const pathname = usePathname();
  const lenis = useLenis();
  const prevPathname = useRef(pathname);

  useEffect(() => {
    if (prevPathname.current === pathname) return;
    prevPathname.current = pathname;

    if (lenis) {
      lenis.scrollTo(0, { immediate: true });
    }
    window.scrollTo(0, 0);

    requestAnimationFrame(() => {
      try {
        ScrollTrigger.refresh();
      } catch {
        /* noop */
      }
    });
  }, [pathname, lenis]);

  return null;
}
