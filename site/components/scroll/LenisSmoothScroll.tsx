"use client";
import ReactLenis, { useLenis } from "lenis/react";
import { useEffect, useRef } from "react";
import { ScrollTrigger } from "gsap/ScrollTrigger";
import gsap from "gsap";

gsap.registerPlugin(ScrollTrigger);

const isIOS =
  typeof window !== "undefined" && /iPad|iPhone|iPod/.test(navigator.userAgent);

export default function LenisSmoothScroll() {
  const lenis = useLenis();
  const cleanupRef = useRef<(() => void) | null>(null);

  useEffect(() => {
    if (isIOS || !lenis) return;

    let pinType: "transform" | "fixed" = "fixed";
    try {
      pinType = document.body.style.transform ? "transform" : "fixed";
    } catch (_) {
      pinType = "fixed";
    }

    ScrollTrigger.scrollerProxy(document.body, {
      scrollTop(value) {
        if (arguments.length && value !== undefined) {
          lenis.scrollTo(value as number, { immediate: true });
        }
        return lenis.scroll;
      },
      scrollLeft() {
        return 0;
      },
      getBoundingClientRect() {
        return {
          top: 0,
          left: 0,
          width: window.innerWidth,
          height: window.innerHeight,
        };
      },
      pinType,
    });

    document.body.style.overflow = "auto";
    lenis.on("scroll", ScrollTrigger.update);

    const safeRefresh = () => {
      try {
        if (
          typeof window !== "undefined" &&
          window.self === window.top &&
          document.readyState === "complete"
        ) {
          ScrollTrigger.refresh();
        }
      } catch (e) {
        if ((e as DOMException)?.name !== "SecurityError") throw e;
      }
    };

    let resizeTimer: ReturnType<typeof setTimeout>;
    const handleResize = () => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(safeRefresh, 150);
    };

    const initTimer = setTimeout(safeRefresh, 200);

    window.addEventListener("resize", handleResize);

    cleanupRef.current = () => {
      clearTimeout(initTimer);
      clearTimeout(resizeTimer);
      lenis.off("scroll", ScrollTrigger.update);
      window.removeEventListener("resize", handleResize);
      ScrollTrigger.scrollerProxy(document.body, {});
      document.body.style.overflow = "";
    };

    return () => cleanupRef.current?.();
  }, [lenis]);

  if (isIOS) return null;

  return <ReactLenis root />;
}
