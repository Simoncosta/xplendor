"use client";
import { useEffect, useRef, useState } from "react";

interface CounterProps {
  parentClass?: string;
  min?: number;
  max: number;
}

const Counter: React.FC<CounterProps> = ({ parentClass, min = 0, max }) => {
  const targetElement = useRef<HTMLSpanElement | null>(null);
  // O HTML exportado leva o número final (lido por motores de pesquisa e sem JavaScript);
  // no browser volta ao início e conta até ao fim quando o contador fica visível.
  const [counted, setCounted] = useState<number>(max);

  useEffect(() => {
    setCounted(min);
    const startCountup = () => {
      const intervalId = setInterval(() => {
        setCounted((prevCount) => {
          const tempCount = prevCount + Math.ceil(max / 20);
          if (tempCount >= max) {
            clearInterval(intervalId);
            return max;
          }
          return tempCount;
        });
      }, 50);
    };
    const handleIntersection: IntersectionObserverCallback = (
      entries,
      observer
    ) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          startCountup();
          observer.unobserve(entry.target);
        }
      });
    };

    const options: IntersectionObserverInit = {
      root: null,
      rootMargin: "0px",
      threshold: 0.5,
    };

    const observer = new IntersectionObserver(handleIntersection, options);
    if (targetElement.current) {
      observer.observe(targetElement.current);
    }

    return () => {
      if (targetElement.current) {
        // eslint-disable-next-line react-hooks/exhaustive-deps
        observer.unobserve(targetElement.current);
      }
    };
  }, [max, min]);

  return (
    <span ref={targetElement} className={parentClass}>
      {counted}
    </span>
  );
};

export default Counter;
