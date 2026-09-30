"use client";
import Link from "next/link";
import Image from "next/image";

import innerPagesSliderData from "@/data/innerPagesSlider.json";
import { Swiper, SwiperSlide } from "swiper/react";
import { Navigation, Parallax } from "swiper/modules";

export default function InnerPagesSlider() {
  return (
    <div className="mxd-section padding-pre-grid">
      <div className="mxd-container">
        {/* Block - Inner Pages Slider Start */}
        <div className="mxd-block">
          {/* Slider main container */}
          <Swiper
            className="mxd-demo-swiper mxd-grid-item anim-uni-in-up"
            {...{
              breakpoints: {
                640: { slidesPerView: 1, spaceBetween: 30 },
                768: { slidesPerView: 3, spaceBetween: 30 },
                1600: { slidesPerView: 3, spaceBetween: 30 },
              },
              loop: true,
              parallax: true,
              autoPlay: { disableOnInteraction: false, enabled: true },
              grabCursor: true,
              speed: 600,
              centeredSlides: true,
              keyboard: { enabled: true },
              navigation: {
                nextEl: ".swiper-button-next",
                prevEl: ".swiper-button-prev",
              },
            }}
            modules={[Navigation, Parallax]}
          >
            {innerPagesSliderData.map((slide, idx) => (
              <SwiperSlide
                className="swiper-slide mxd-demo-swiper__slide"
                key={idx}
              >
                <Link
                  className="demo-swiper-slide__image"
                  href={slide.href}
                  target={slide.target}
                >
                  <Image
                    alt="Rayo Image"
                    width={slide.imgWidth}
                    height={slide.imgHeight}
                    src={slide.img}
                  />
                </Link>
                <div className="demo-swiper-slide__descr">
                  <Link
                    href={slide.href}
                    data-swiper-parallax-opacity={0}
                    data-swiper-parallax-y={-30}
                  >
                    <span>{slide.title}</span>
                  </Link>
                </div>
              </SwiperSlide>
            ))}

            {/* navigation buttons */}
            <div className="swiper-button-prev mxd-slider-btn mxd-slider-btn-round-prev v2">
              <a
                className="btn btn-round btn-round-small btn-outline slide-left anim-no-delay"
                href="#"
              >
                <i className="ph ph-arrow-left" />
              </a>
            </div>
            <div className="swiper-button-next mxd-slider-btn mxd-slider-btn-round-next v2">
              <a
                className="btn btn-round btn-round-small btn-outline slide-right anim-no-delay"
                href="#"
              >
                <i className="ph ph-arrow-right" />
              </a>
            </div>
          </Swiper>
        </div>
        {/* Block - Inner Pages Slider End */}
      </div>
    </div>
  );
}
