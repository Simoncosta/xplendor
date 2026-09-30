import Link from "next/link";
import Image from "next/image";

import VelocityMarquee from "@/components/animation/VelocityMarquee";
import { projects6, projects7 } from "@/data/projects.json";
import React from "react";
export default function MarqueeSlider() {
  return (
    <div className="mxd-section padding-pre-title">
      <div className="mxd-container fullwidth-container">
        {/* Block - Marquee Images Two Lines Start */}
        <div className="mxd-block">
          {/* top line */}
          <VelocityMarquee direction="left" className="marquee marquee--gsap">
            {/* item */}
            {projects6.map((item, idx) => (
              <React.Fragment key={idx}>
                <div className="marquee__item image">
                  <Link className="marquee__link" href={`/project-details`}>
                    <Image
                      alt="Image"
                      src={item.src}
                      width={1200}
                      height={1000}
                    />
                  </Link>
                </div>
                {/* item */}
                {item.title && (
                  <div className="marquee__item has-caption padding-4">
                    <p>
                      {item.title.split(" ")[0]}
                      <br />
                      {item.title.split(" ")[1]}
                    </p>
                  </div>
                )}
              </React.Fragment>
            ))}
          </VelocityMarquee>
          {/* bottom line */}
          <VelocityMarquee direction="right" className="marquee marquee--gsap">
            {projects7.map((item, idx) => (
              <React.Fragment key={idx}>
                <div className="marquee__item image">
                  <Link className="marquee__link" href={`/project-details`}>
                    <Image
                      alt="Image"
                      src={item.src}
                      width={1200}
                      height={1000}
                    />
                  </Link>
                </div>
                {/* item */}
                {item.title && (
                  <div className="marquee__item has-caption padding-4">
                    <p>
                      {item.title.split(" ")[0]}
                      <br />
                      {item.title.split(" ")[1]}
                    </p>
                  </div>
                )}
              </React.Fragment>
            ))}
          </VelocityMarquee>
        </div>
        {/* Block - Marquee Images Two Lines End */}
      </div>
    </div>
  );
}
