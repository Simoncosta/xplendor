import Link from "next/link";
import Image from "next/image";

import AnimatedButton from "@/components/animation/AnimatedButton";

export default function Hero() {
  return (
    <div className="mxd-section mxd-hero-section padding-grid-pre-mtext">
      <div className="mxd-hero-05">
        <div className="mxd-hero-05__wrap loading-wrap">
          <div className="mxd-hero-05__top">
            <div className="mxd-hero-05__headline">
              <div className="container-fluid p-0">
                <div className="row g-0 d-flex flex-column flex-xl-row">
                  <div className="col-12 col-xl-8 col-xxl-10 order-2 order-xl-1 mxd-grid-item no-margin">
                    {/* title text */}
                    <h1 className="hero-05-title">
                      <span className="hero-05-title__row loading__item">
                        <em className="hero-05-title__item">Shaping ideas</em>
                      </span>
                      <span className="hero-05-title__row loading__item">
                        <em className="hero-05-title__item title-item-image">
                          <svg
                            className="mxd-pulse"
                            xmlns="http://www.w3.org/2000/svg"
                            version="1.1"
                            viewBox="0 0 20 20"
                          >
                            <path d="M19.6,9.6h-3.9c-.4,0-1.8-.2-1.8-.2-.6,0-1.1-.2-1.6-.6-.5-.3-.9-.8-1.2-1.2-.3-.4-.4-.9-.5-1.4,0,0,0-1.1-.2-1.5V.4c0-.2-.2-.4-.4-.4s-.4.2-.4.4v4.4c0,.4-.2,1.5-.2,1.5,0,.5-.2,1-.5,1.4-.3.5-.7.9-1.2,1.2s-1,.5-1.6.6c0,0-1.2,0-1.7.2H.4c-.2,0-.4.2-.4.4s.2.4.4.4h4.1c.4,0,1.7.2,1.7.2.6,0,1.1.2,1.6.6.4.3.8.7,1.1,1.1.3.5.5,1,.6,1.6,0,0,0,1.3.2,1.7v4.1c0,.2.2.4.4.4s.4-.2.4-.4v-4.1c0-.4.2-1.7.2-1.7,0-.6.2-1.1.6-1.6.3-.4.7-.8,1.1-1.1.5-.3,1-.5,1.6-.6,0,0,1.3,0,1.8-.2h3.9c.2,0,.4-.2.4-.4s-.2-.4-.4-.4h0Z" />
                          </svg>
                        </em>
                        <em className="hero-05-title__item">
                          into real stories
                        </em>
                      </span>
                    </h1>
                  </div>
                  <div className="col-12 col-xl-4 col-xxl-2 order-1 order-xl-2 mxd-grid-item no-margin">
                    {/* clients avatars */}
                    <div className="hero-05-headline__avatars loading__fade">
                      <div className="mxd-avatars-group align-right">
                        <div className="mxd-avatars align-right">
                          <div className="mxd-avatars__item small">
                            <Image
                              alt="Avatar"
                              src="/img/avatars/300x300_ava-02.webp"
                              width={300}
                              height={300}
                            />
                          </div>
                          <div className="mxd-avatars__item small bg-base-opp">
                            <svg
                              className="mxd-avatars__icon small"
                              version="1.1"
                              xmlns="http://www.w3.org/2000/svg"
                              xmlnsXlink="http://www.w3.org/1999/xlink"
                              x="0px"
                              y="0px"
                              width="60px"
                              height="60px"
                              viewBox="0 0 60 60"
                              enableBackground={"new 0 0 60 60"}
                              xmlSpace="preserve"
                            >
                              <style
                                type="text/css"
                                dangerouslySetInnerHTML={{
                                  __html:
                                    "\n                                  .icon-star {\n                                    fill: var(--additional);\n                                  }\n                                ",
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
                          <div className="mxd-avatars__item small">
                            <Image
                              alt="Avatar"
                              src="/img/avatars/300x300_ava-01.webp"
                              width={300}
                              height={300}
                            />
                          </div>
                        </div>
                        <div className="mxd-avatars-group__text">
                          <p>
                            Trusted by
                            <br />
                            <a href="#">10k+ customers</a>
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div className="mxd-hero-05__bottom mxd-grid-item no-margin">
            <div className="mxd-hero-05__worksblock loading__item">
              <Image
                className="mxd-move"
                alt="Hero image"
                src="/img/hero/05_hero-img.webp"
                width={800}
                height={800}
              />
              <div className="hero-05-worksblock__descr">
                <p className="t-large t-caption t-bright">
                  Blending creativity with practical design
                </p>
                <AnimatedButton
                  text="Explore"
                  className="btn btn-anim btn-default btn-outline slide-right-up"
                  href={`/works-simple`}
                >
                  <i className="ph-bold ph-arrow-up-right" />
                </AnimatedButton>
              </div>
            </div>
            <div className="mxd-hero-05__videoblock loading__item">
              <div className="mxd-hero-05-videoblock__video">
                <video
                  preload="auto"
                  autoPlay
                  loop
                  muted
                  poster="video/1280x720_video-01.webp"
                >
                  <source type="video/mp4" src="video/1280x720_video-01.mp4" />
                  <source
                    type="video/webm"
                    src="video/1280x720_video-01.webm"
                  />
                  <source type="video/ogv" src="video/1280x720_video-01.ogv" />
                </video>
              </div>
              <div className="mxd-hero-05-videoblock__descr">
                <p className="t-large t-caption t-bright">
                  We are a creative digital agency specializing in innovative
                  design and cutting-edge development.
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
