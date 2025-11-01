import React from "react";

import { carBrands3 } from "@/data/categories";
import { Swiper, SwiperSlide } from "swiper/react";
import { Pagination } from "swiper/modules";
export default function Trending({ parentClass = "tf-section" }) {
  const swiperOptions = {
    slidesPerView: 5,
    spaceBetween: 30,
    speed: 1000,
    pagination: {
      el: ".spd29",
      clickable: true,
    },

    breakpoints: {
      0: {
        slidesPerView: 1,
      },
      600: {
        slidesPerView: 2,
      },
      991: {
        slidesPerView: 4,
      },
      1200: {
        slidesPerView: 5,
      },
    },
  };
  return (
    <section className={parentClass}>
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="heading-section flex align-center justify-space flex-wrap gap-20">
              <h2
                className="wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                Trending now
              </h2>
              <a
                href="#"
                className="tf-btn-arrow wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                View all
                <i className="icon-autodeal-btn-right" />
              </a>
            </div>
          </div>
        </div>
        <div className="row">
          <div className="col-lg-12">
            <div
              className="swiper-container tf-sw-mobile6"
              data-preview={5}
              data-space={30}
            >
              <div className="swiper-wrapper grid-sw-5">
                {carBrands3.map((brand, index) => (
                  <div className="swiper-slide" key={index}>
                    <div className="tf-image-list">
                      <div className="image">
                        <img
                          className="lazyloaded"
                          data-src={brand.imageSrc}
                          alt="car brand image"
                          src={brand.imageSrc}
                          width={351}
                          height={198}
                        />
                      </div>
                      <div className="content">
                        <h5>{brand.title}</h5>
                        <ul>
                          {brand.models.map((model, idx) => (
                            <li key={idx}>
                              <p>{model}</p>
                            </li>
                          ))}
                        </ul>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
              <div className="swiper-pagination3" />
            </div>
            <Swiper
              className="swiper-container tf-sw-mobile6-swiper"
              {...swiperOptions}
              modules={[Pagination]}
            >
              {carBrands3.map((brand, index) => (
                <SwiperSlide className="swiper-slide" key={index}>
                  <div className="tf-image-list">
                    <div className="image">
                      <img
                        className="lazyloaded"
                        data-src={brand.imageSrc}
                        alt="car brand image"
                        src={brand.imageSrc}
                        width={351}
                        height={198}
                      />
                    </div>
                    <div className="content">
                      <h5>{brand.title}</h5>
                      <ul>
                        {brand.models.map((model, idx) => (
                          <li key={idx}>
                            <p>{model}</p>
                          </li>
                        ))}
                      </ul>
                    </div>
                  </div>
                </SwiperSlide>
              ))}

              <div className="swiper-pagination3 spd29 pb-1" />
            </Swiper>
          </div>
        </div>
      </div>
    </section>
  );
}
