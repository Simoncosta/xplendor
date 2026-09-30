"use client";
import React, { useEffect, useState } from "react";

export default function ThemeSwitcherButton({}) {
  const [showSwitcher, setShowSwitcher] = useState(false);
  const [colorScheme, setColorScheme] = useState<"light" | "dark">(() => {
    if (typeof window !== "undefined") {
      return (
        (localStorage.getItem("color-scheme") as "light" | "dark") || "light"
      );
    }
    return "light";
  });
  useEffect(() => {
    setShowSwitcher(true);
  }, []);

  useEffect(() => {
    // Only set if not already set to the same value
    const currentScheme = document.documentElement.getAttribute("color-scheme");
    if (currentScheme !== colorScheme) {
      document.documentElement.setAttribute("color-scheme", colorScheme);
    }
    if (localStorage.getItem("color-scheme") !== colorScheme) {
      localStorage.setItem("color-scheme", colorScheme);
    }
  }, [colorScheme]);

  const handleColorSwitch = () => {
    setColorScheme((prev) => (prev === "light" ? "dark" : "light"));
  };
  return (
    <>
      {showSwitcher ? (
        <button
          id="color-switcher"
          className="mxd-color-switcher"
          type="button"
          role="switch"
          aria-label="light/dark mode"
          aria-checked={colorScheme === "dark"}
          onClick={handleColorSwitch}
        >
          <i
            className={
              colorScheme === "dark"
                ? "ph-bold ph-sun-horizon"
                : "ph-bold ph-moon-stars "
            }
          />
        </button>
      ) : (
        ""
      )}{" "}
    </>
  );
}
