import Link from "next/link";
import Image from "next/image";

import RevealText from "@/components/animation/RevealText";
import AnimatedButton from "@/components/animation/AnimatedButton";

export default function About() {
  return (
    <div className="mxd-section padding-pre-grid">
      <div className="mxd-container grid-l-container">
        {/* Block - About Description with Manifest Start */}
        <div className="mxd-block">
          <div className="container-fluid px-0">
            <div className="row gx-0">
              <div className="col-12 col-xl-6 mxd-grid-item-l no-margin anim-uni-in-up">
                <div className="mxd-block__image">
                  <Image
                    alt="Photo"
                    src="/img/avatars/1400x1400_ava-01.webp"
                    width={1400}
                    height={1400}
                  />
                </div>
              </div>
              <div className="col-12 col-xl-6 mxd-grid-item-l no-margin">
                <div className="mxd-block__content content-flex">
                  <div className="mxd-block__manifest">
                    <RevealText
                      as="p"
                      className="mxd-manifest mxd-manifest-l reveal-type"
                    >
                      As a digital illustrator, I&apos;ve always been fascinated
                      by the intersection of art and technology.
                    </RevealText>
                    <div className="mxd-manifest__controls">
                      <AnimatedButton
                        text="About Me"
                        className="btn btn-anim btn-default btn-outline slide-right-up anim-uni-in-up"
                        href={`/about-me`}
                      >
                        <i className="ph-bold ph-arrow-up-right" />
                      </AnimatedButton>
                    </div>
                  </div>
                  <div className="mxd-block__decoration anim-uni-in-up">
                    <Image
                      alt="Decoration"
                      src="/img/icons/96x96-decor-01.svg"
                      width={96}
                      height={96}
                    />
                  </div>
                  <div className="mxd-links-lines">
                    {/* item */}
                    <div className="mxd-links-lines__item">
                      <div className="mxd-links-lines__divider anim-uni-in-up" />
                      <a
                        className="mxd-links-lines__link anim-uni-in-up"
                        href="https://medium.com/"
                        target="_blank"
                      >
                        <h6 className="mxd-links-lines__title">
                          Some article on Medium
                        </h6>
                        <div className="mxd-links-lines__icon">
                          <i className="ph ph-arrow-up-right" />
                        </div>
                      </a>
                      <div className="mxd-links-lines__divider anim-uni-in-up" />
                    </div>
                    {/* item */}
                    <div className="mxd-links-lines__item">
                      <div className="mxd-links-lines__divider anim-uni-in-up" />
                      <a
                        className="mxd-links-lines__link anim-uni-in-up"
                        href="https://www.awwwards.com/"
                        target="_blank"
                      >
                        <h6 className="mxd-links-lines__title">
                          Awwwards nomination
                        </h6>
                        <div className="mxd-links-lines__icon">
                          <i className="ph ph-arrow-up-right" />
                        </div>
                      </a>
                      <div className="mxd-links-lines__divider anim-uni-in-up" />
                    </div>
                    {/* item */}
                    <div className="mxd-links-lines__item">
                      <div className="mxd-links-lines__divider anim-uni-in-up" />
                      <a
                        className="mxd-links-lines__link anim-uni-in-up"
                        href="https://www.behance.net/"
                        target="_blank"
                      >
                        <h6 className="mxd-links-lines__title">
                          Behance curated work
                        </h6>
                        <div className="mxd-links-lines__icon">
                          <i className="ph ph-arrow-up-right" />
                        </div>
                      </a>
                      <div className="mxd-links-lines__divider anim-uni-in-up" />
                    </div>
                    {/* item */}
                    <div className="mxd-links-lines__item">
                      <div className="mxd-links-lines__divider anim-uni-in-up" />
                      <a
                        className="mxd-links-lines__link anim-uni-in-up"
                        href="https://medium.com/"
                        target="_blank"
                      >
                        <h6 className="mxd-links-lines__title">
                          Article on Medium
                        </h6>
                        <div className="mxd-links-lines__icon">
                          <i className="ph ph-arrow-up-right" />
                        </div>
                      </a>
                      <div className="mxd-links-lines__divider anim-uni-in-up" />
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        {/* Block - About Description with Manifest End */}
      </div>
    </div>
  );
}
