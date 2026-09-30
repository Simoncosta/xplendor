import Link from "next/link";
import Image from "next/image";

import factsData from "@/data/facts.json";
import Counter from "@/components/common/Counter";
import AnimatedButton from "../animation/AnimatedButton";
import React from "react";

export default function Facts() {
  return (
    <div className="mxd-section padding-grid-pre-mtext overflow-hidden">
      <div className="mxd-container grid-container">
        {/* Block - Statistics Cards Start */}
        <div className="mxd-block">
          <div className="mxd-stats-cards">
            <div className="container-fluid px-0">
              <div className="row gx-0">
                {factsData.map((item, idx) => (
                  <div className={item.colClass} key={idx}>
                    <div className={item.cardClass}>
                      {item.avatars ? (
                        <div className="mxd-stats-cards__btngroup">
                          <div className="mxd-avatars">
                            {item.avatars.map((avatar, aIdx) =>
                              avatar.svg ? (
                                <div
                                  className="mxd-avatars__item bg-base-opp"
                                  key={aIdx}
                                >
                                  <svg
                                    className="mxd-avatars__icon"
                                    version="1.1"
                                    xmlns="http://www.w3.org/2000/svg"
                                    xmlnsXlink="http://www.w3.org/1999/xlink"
                                    x="0px"
                                    y="0px"
                                    width="60px"
                                    height="60px"
                                    viewBox="0 0 60 60"
                                    enableBackground="new 0 0 60 60"
                                    xmlSpace="preserve"
                                  >
                                    <style
                                      type="text/css"
                                      dangerouslySetInnerHTML={{
                                        __html:
                                          "\n                                .icon-star {\n                                  fill: var(--additional);\n                                }\n                              ",
                                      }}
                                    />
                                    <path
                                      className="icon-star"
                                      d="M58.9,28.9c0,0-9.1,0.1-12.1,0c-1.3,0-5.3-0.5-5.3-0.5c-1.7-0.2-3.4-0.7-4.8-1.7c-1.4-1-2.7-2.3-3.6-3.7
                          c-0.8-1.3-1.3-2.7-1.5-4.2c0,0-0.4-3.3-0.5-4.4c-0.2-3.3,0-13.1,0-13.1c0-0.6-0.5-1.1-1.1-1.1s-1.1,0.5-1.1,1.1
                          c0,0,0.2,9.8,0,13.1c0,1.1-0.5,4.4-0.5,4.4c-0.2,1.5-0.6,3-1.5,4.2c-0.9,1.5-2.2,2.7-3.6,3.7s-3,1.5-4.7,1.7c0,0-3.7,0.4-5,0.5
                          c-3.1,0.2-12.5,0-12.5,0C0.5,28.9,0,29.4,0,30s0.5,1.1,1.1,1.1c0,0,9.4-0.2,12.5,0c1.2,0,5,0.5,5,0.5c1.7,0.2,3.3,0.7,4.7,1.7
                          c1.3,0.9,2.4,2,3.3,3.3c1,1.4,1.5,3.1,1.7,4.8c0,0,0.4,3.9,0.5,5.2c0.1,3,0,12.2,0,12.2c0,0.6,0.5,1.1,1.1,1.1s1.1-0.5,1.1-1.1
                          c0,0-0.1-9.2,0-12.2c0-1.3,0.5-5.2,0.5-5.2c0.2-1.7,0.7-3.4,1.7-4.8c0.9-1.3,2-2.4,3.3-3.3c1.4-1,3.1-1.5,4.8-1.7
                          c0,0,3.9-0.4,5.3-0.5c3-0.1,12.1,0,12.1,0c0.6,0,1.1-0.5,1.1-1.1s-0.5-1.1-1.1-1.1l0,0L58.9,28.9z"
                                    />
                                  </svg>
                                </div>
                              ) : (
                                <div className="mxd-avatars__item" key={aIdx}>
                                  <Image
                                    alt={avatar.alt || "Avatar"}
                                    src={avatar.src || ""}
                                    width={avatar.width || 60}
                                    height={avatar.height || 60}
                                  />
                                </div>
                              )
                            )}
                          </div>
                        </div>
                      ) : null}
                      <div
                        className={
                          item.counterId.includes("2")
                            ? "mxd-counter align-end"
                            : "mxd-counter"
                        }
                      >
                        <p id={item.counterId} className={item.numberClass}>
                          <Counter
                            max={item.number}
                            parentClass={item.numberClass}
                          />

                          {item.suffix}
                        </p>
                        <p className={item.descrClass}>
                          {item.description.split("\n").map((line, i) => (
                            <React.Fragment key={i}>
                              {line}
                              {i !==
                                item.description.split("\n").length - 1 && (
                                <br />
                              )}
                            </React.Fragment>
                          ))}
                        </p>
                      </div>
                      {item.button ? (
                        <div className="mxd-stats-cards__btngroup">
                          <AnimatedButton
                            text={item.button.text}
                            className={item.button.btnClass}
                            href={item.button.href}
                          >
                            <i className={item.button.iconClass} />
                          </AnimatedButton>
                        </div>
                      ) : null}
                      <div className={item.imageClass}>
                        <Image
                          alt="Illustration"
                          src={item.image}
                          width={item.imageWidth}
                          height={item.imageHeight}
                        />
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
        {/* Block - Statistics Cards End */}
      </div>
    </div>
  );
}
