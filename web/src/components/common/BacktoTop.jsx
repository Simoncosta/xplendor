import { useEffect, useState } from "react";

export default function BackToTop() {
  const [showScrollTop, setShowScrollTop] = useState(false);
  const [scrolled, setScrolled] = useState(0);
  const [scrollHeight, setScrollHeight] = useState(500);

  const scrollToTop = () => {
    window.scrollTo({
      top: 0,
      behavior: "smooth", // You can use 'auto' or 'instant' as well
    });
  };

  const handleScroll = () => {
    const currentScroll =
      document.body.scrollTop || document.documentElement.scrollTop;
    setScrolled(currentScroll);
    setShowScrollTop(window.scrollY >= window.innerHeight);
    const totalScrollHeight =
      document.documentElement.scrollHeight -
      document.documentElement.clientHeight;
    setScrollHeight(totalScrollHeight);
  };

  useEffect(() => {
    window.addEventListener("scroll", handleScroll);

    return () => {
      window.removeEventListener("scroll", handleScroll);
    };
  }, []);
  return (
    <div
      onClick={() => scrollToTop()}
      className={`progress-wrap ${scrolled > 150 ? "active-progress" : ""}`}
    >
      <svg
        className="progress-circle svg-content"
        width="100%"
        height="100%"
        viewBox="-1 -1 102 102"
      >
        <path
          d="M50,1 a49,49 0 0,1 0,98 a49,49 0 0,1 0,-98"
          style={{
            transition: "0s",
            strokeDasharray: "307.919, 307.919",
            strokeDashoffset: 307.919 - (scrolled / scrollHeight) * 307.919,
          }}
        />
      </svg>
    </div>
  );
}
