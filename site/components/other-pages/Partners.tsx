import Image from "next/image";

import partners from "@/data/partners.json";
import { Partner } from "@/types/partner";
import VelocityMarquee from "../animation/VelocityMarquee";

type PartnersJson = { top: Partner[]; bottom: Partner[] };

const partnersData = partners as unknown as PartnersJson;

export default function Partners() {
  const top = partnersData.top;
  const bottom = partnersData.bottom;

  return (
    <div className="mxd-section padding-pre-title">
      <div className="mxd-container fullwidth-container">
        {/* Block - Marquee Partners Two Lines Start */}
        <div className="mxd-block">
          {/* top line */}
          <VelocityMarquee direction="left" className="marquee marquee--gsap">
            {top.map((p, idx) => (
              <a
                key={idx}
                className="marquee__item item-partners"
                href={p.href || "#0"}
              >
                <Image
                  alt="Partner Logo"
                  src={p.src}
                  width={p.width}
                  height={p.height}
                />
              </a>
            ))}
          </VelocityMarquee>
          {/* bottom line */}
          <VelocityMarquee className="marquee marquee--gsap">
            {bottom.map((p, idx) => (
              <a
                key={idx}
                className="marquee__item item-partners"
                href={p.href || "#0"}
              >
                <Image
                  alt="Partner Logo"
                  src={p.src}
                  width={p.width}
                  height={p.height}
                />
              </a>
            ))}
          </VelocityMarquee>
        </div>
        {/* Block - Marquee Partners Two Lines End */}
      </div>
    </div>
  );
}
