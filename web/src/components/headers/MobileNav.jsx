import { blogPages, homepages, listingPages, otherPages } from "@/data/menu";
import { Link, useLocation } from "react-router-dom";

import React, { useEffect } from "react";

export default function MobileNav() {
  const { pathname } = useLocation();
  const isActive = (menus) => {
    let active = false;

    menus.forEach((elm) => {
      if (elm.links) {
        elm.links.forEach((elm2) => {
          if (elm2.href.split("/")[1] == pathname.split("/")[1]) {
            active = true;
          }
        });
      } else {
        if (elm.href.split("/")[1] == pathname.split("/")[1]) {
          active = true;
        }
      }
    });
    return active;
  };

  const handleActive1 = (event) => {
    const dropdown = event.currentTarget.closest(".dropdown2.parent-menu-1");
    const allDropdowns = document.querySelectorAll(".dropdown2.parent-menu-1");
    if (dropdown) {
      const ulElement = dropdown.querySelector("ul");
      if (dropdown.classList.contains("open")) {
        dropdown.classList.remove("open");

        if (ulElement) {
          ulElement.style.height = `0px`;
          ulElement.style.padding = "0px 20px";
        }
      } else {
        dropdown.classList.add("open");

        if (ulElement) {
          ulElement.style.height = `${ulElement.scrollHeight + 30}px`;
          ulElement.style.padding = "15px 20px";
        }
        allDropdowns.forEach((elm) => {
          if (elm !== dropdown) {
            elm.classList.remove("open");
            const ulElement2 = elm.querySelector("ul");
            if (ulElement2) {
              ulElement2.style.height = `0px`;
              ulElement2.style.padding = "0px 20px";
            }
          }
        });
      }
    }
  };
  const handleActive2 = (event) => {
    const dropdown = event.currentTarget.closest(
      ".dropdown2:not(.parent-menu-1)"
    );
    if (dropdown) {
      const ulElement = dropdown.querySelector("ul");
      if (dropdown.classList.contains("open")) {
        dropdown.classList.remove("open");

        if (ulElement) ulElement.style.height = `0px`;
        ulElement.style.padding = "0px 20px";
      } else {
        dropdown.classList.add("open");

        if (ulElement)
          ulElement.style.height = `${ulElement.scrollHeight + 30}px`;
        ulElement.style.padding = "15px 20px";
      }
    }
    const parentElement = dropdown.closest(".dropdown2.parent-menu-1");
    const ulElement2 = parentElement.querySelector("ul");
    ulElement2.style.height = `auto`;
  };
  useEffect(() => {
    document.body.classList.remove("mobile-menu-visible");
  }, [pathname]);

  return (
    <div className="menu-outer">
      <div
        className="navbar-collapse collapse clearfix"
        id="navbarSupportedContent"
      >
        <ul className="navigation clearfix">
          <li
            className={`tf-megamenu dropdown2 parent-menu-1 ${
              isActive(homepages) ? "current" : ""
            } `}
          >
            <a href="#">Home</a>
            <ul>
              {homepages.map((page, index) => (
                <li
                  key={index}
                  className={
                    page.href.split("/")[1] == pathname.split("/")[1]
                      ? "current"
                      : ""
                  }
                >
                  <Link to={page.href}>{page.text}</Link>
                </li>
              ))}
            </ul>
            <div className="dropdown2-btn" onClick={handleActive1} />
          </li>
          <li
            className={`tfcl-mega-menu dropdown2 parent-menu-1  ${
              isActive(listingPages) ? "current" : ""
            } `}
          >
            <a href="#">Listing Car</a>
            <ul>
              {listingPages.map((item, index) => (
                <li key={index} className={item.className}>
                  <a href="#">{item.title}</a>
                  <ul>
                    {item.links.map((link, linkIndex) => (
                      <li
                        key={linkIndex}
                        className={`${link.className || ""} ${
                          link.href.split("/")[1] == pathname.split("/")[1]
                            ? "current"
                            : ""
                        }`}
                      >
                        <Link to={link.href}>{link.text}</Link>
                      </li>
                    ))}
                  </ul>
                  <div className="dropdown2-btn" onClick={handleActive2} />
                </li>
              ))}
            </ul>
            <div className="dropdown2-btn" onClick={handleActive1} />
          </li>
          <li
            className={`dropdown2 parent-menu-1  ${
              isActive(otherPages) ? "current" : ""
            } `}
          >
            <a href="#">Page</a>
            <ul>
              {otherPages.map((item, index) => (
                <li
                  key={index}
                  className={`${item.className || ""}  ${
                    item.links ? (isActive(item.links) ? "current" : "") : ""
                  } ${
                    item.href?.split("/")[1] == pathname.split("/")[1]
                      ? "current"
                      : ""
                  }`}
                >
                  {item.title ? (
                    <>
                      <a href="#">{item.title}</a>
                      <ul>
                        {item.links.map((link, linkIndex) => (
                          <li
                            key={linkIndex}
                            className={
                              link.href.split("/")[1] == pathname.split("/")[1]
                                ? "current"
                                : ""
                            }
                          >
                            <Link to={link.href}>{link.text}</Link>
                          </li>
                        ))}
                      </ul>
                      <div className="dropdown2-btn" onClick={handleActive2} />
                    </>
                  ) : (
                    <Link to={item.href}>{item.text}</Link>
                  )}
                </li>
              ))}
            </ul>
            <div className="dropdown2-btn" onClick={handleActive1} />
          </li>
          <li
            className={`dropdown2  parent-menu-1 ${
              isActive(blogPages) ? "current" : ""
            } `}
          >
            <a href="#">Blog</a>
            <ul>
              {blogPages.map((item, index) => (
                <li
                  key={index}
                  className={
                    item.href.split("/")[1] == pathname.split("/")[1]
                      ? "current"
                      : ""
                  }
                >
                  <Link to={item.href}>{item.text}</Link>
                </li>
              ))}
            </ul>
            <div className="dropdown2-btn" onClick={handleActive1} />
          </li>
          <li className={"contact" == pathname.split("/")[1] ? "current" : ""}>
            <Link to={`/contact`}>Contact</Link>
          </li>
        </ul>
      </div>
    </div>
  );
}
