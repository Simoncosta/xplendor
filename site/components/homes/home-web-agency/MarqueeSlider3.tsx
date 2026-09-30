import Link from "next/link";
import Image from "next/image";

import VelocityMarquee from "@/components/animation/VelocityMarquee";
import AnimatedButton from "@/components/animation/AnimatedButton";

export default function MarqueeSlider3() {
  return (
    <div className="mxd-section padding-pre-title">
      <div className="mxd-container fullwidth-container">
        {/* Block - Marquee Images Two Lines Start */}
        <div className="mxd-block">
          {/* top line */}
          <VelocityMarquee direction="left" className="marquee marquee--gsap">
            {/* item */}
            <div className="marquee__item image">
              <Link className="marquee__link" href={`/team`}>
                <Image
                  alt="Image"
                  src="/img/marquee/1200x1000_marquee-10.webp"
                  width={1200}
                  height={1000}
                />
              </Link>
            </div>
            {/* item */}
            <div className="marquee__item has-caption padding-4">
              <div className="mxd-section-title no-margin">
                <div className="container-fluid p-0">
                  <div className="row g-0">
                    <div className="col-12">
                      <div className="mxd-section-title__descr">
                        <p>
                          Inspiring
                          <br />
                          ideas
                        </p>
                      </div>
                    </div>
                    <div className="col-12">
                      <div className="mxd-section-title__controls">
                        <AnimatedButton
                          text="Meet Our Team"
                          className="btn btn-anim btn-default btn-outline opposite slide-right-up"
                          href={`/team`}
                        >
                          <i className="ph-bold ph-arrow-up-right" />
                        </AnimatedButton>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            {/* item */}
            <div className="marquee__item">
              <Link className="marquee__link" href={`/team`}>
                <Image
                  alt="Image"
                  src="/img/marquee/1200x1000_marquee-11.webp"
                  width={1200}
                  height={1000}
                />
              </Link>
            </div>
            {/* item */}
            <div className="marquee__item">
              <Link className="marquee__link" href={`/team`}>
                <Image
                  alt="Image"
                  src="/img/marquee/1200x1000_marquee-12.webp"
                  width={1200}
                  height={1000}
                />
              </Link>
            </div>
            {/* item */}
            <div className="marquee__item has-caption padding-4">
              <div className="mxd-section-title no-margin">
                <div className="container-fluid p-0">
                  <div className="row g-0">
                    <div className="col-12">
                      <div className="mxd-section-title__descr">
                        <p>
                          Creative
                          <br />
                          minds
                        </p>
                      </div>
                    </div>
                    <div className="col-12">
                      <div className="mxd-section-title__controls">
                        <AnimatedButton
                          text="Meet Our Team"
                          className="btn btn-anim btn-default btn-outline opposite slide-right-up"
                          href={`/team`}
                        >
                          <i className="ph-bold ph-arrow-up-right" />
                        </AnimatedButton>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            {/* item */}
            <div className="marquee__item">
              <Link className="marquee__link" href={`/team`}>
                <Image
                  alt="Image"
                  src="/img/marquee/1200x1000_marquee-09.webp"
                  width={1200}
                  height={1000}
                />
              </Link>
            </div>
          </VelocityMarquee>
          {/* bottom line */}
          <VelocityMarquee className="marquee marquee--gsap">
            {/* item */}
            <div className="marquee__item has-caption padding-4">
              <div className="mxd-section-title no-margin">
                <div className="container-fluid p-0">
                  <div className="row g-0">
                    <div className="col-12">
                      <div className="mxd-section-title__descr">
                        <p>
                          Creative
                          <br />
                          minds
                        </p>
                      </div>
                    </div>
                    <div className="col-12">
                      <div className="mxd-section-title__controls">
                        <AnimatedButton
                          text="Meet Our Team"
                          className="btn btn-anim btn-default btn-outline opposite slide-right-up"
                          href={`/team`}
                        >
                          <i className="ph-bold ph-arrow-up-right" />
                        </AnimatedButton>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            {/* item */}
            <div className="marquee__item">
              <Link className="marquee__link" href={`/team`}>
                <Image
                  alt="Image"
                  src="/img/marquee/1200x1000_marquee-13.webp"
                  width={1200}
                  height={1000}
                />
              </Link>
            </div>
            {/* item */}
            <div className="marquee__item">
              <Link className="marquee__link" href={`/team`}>
                <Image
                  alt="Image"
                  src="/img/marquee/1200x1000_marquee-14.webp"
                  width={1200}
                  height={1000}
                />
              </Link>
            </div>
            {/* item */}
            <div className="marquee__item has-caption padding-4">
              <div className="mxd-section-title no-margin">
                <div className="container-fluid p-0">
                  <div className="row g-0">
                    <div className="col-12">
                      <div className="mxd-section-title__descr">
                        <p>
                          Inspiring
                          <br />
                          ideas
                        </p>
                      </div>
                    </div>
                    <div className="col-12">
                      <div className="mxd-section-title__controls">
                        <AnimatedButton
                          text="Meet Our Team"
                          className="btn btn-anim btn-default btn-outline opposite slide-right-up"
                          href={`/team`}
                        >
                          <i className="ph-bold ph-arrow-up-right" />
                        </AnimatedButton>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            {/* item */}
            <div className="marquee__item">
              <Link className="marquee__link" href={`/team`}>
                <Image
                  alt="Image"
                  src="/img/marquee/1200x1000_marquee-15.webp"
                  width={1200}
                  height={1000}
                />
              </Link>
            </div>
            {/* item */}
            <div className="marquee__item">
              <Link className="marquee__link" href={`/team`}>
                <Image
                  alt="Image"
                  src="/img/marquee/1200x1000_marquee-16.webp"
                  width={1200}
                  height={1000}
                />
              </Link>
            </div>
          </VelocityMarquee>
        </div>
        {/* Block - Marquee Images Two Lines End */}
      </div>
    </div>
  );
}
