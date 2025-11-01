import { carCategories } from "@/data/categories";
import React from "react";
import { Pagination } from "swiper/modules";
import { Swiper, SwiperSlide } from "swiper/react";

export default function Categories() {
  const swiperOptions = {
    slidesPerView: 6,
    spaceBetween: 30,
    // autoplay: {
    //     delay: 0,
    //     disableOnInteraction: false,
    // },
    // speed: 10000,
    observer: true,
    observeParents: true,

    breakpoints: {
      0: {
        slidesPerView: 2,
        spaceBetween: 20,
      },
      600: {
        slidesPerView: 3,
        spaceBetween: 20,
      },
      992: {
        slidesPerView: 4,
      },
      1440: {
        slidesPerView: 6,
      },
    },
  };
  return (
    <section className="tf-section">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="heading-section flex align-center justify-space flex-wrap gap-20">
              <h2
                className="wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                Popular Car Makes &amp; Body Types
              </h2>
              <div className="flex-six gap-20 flex-wrap">
                <a
                  href="#"
                  className="tf-btn-arrow wow fadeInUpSmall"
                  data-wow-delay="0.2s"
                  data-wow-duration="1000ms"
                >
                  Car makes
                  <i className="icon-autodeal-btn-right" />
                </a>
                <a
                  href="#"
                  className="tf-btn-arrow wow fadeInUpSmall"
                  data-wow-delay="0.3s"
                  data-wow-duration="1000ms"
                >
                  Car body types
                  <i className="icon-autodeal-btn-right" />
                </a>
                <a
                  href="#"
                  className="tf-btn-arrow wow fadeInUpSmall"
                  data-wow-delay="0.3s"
                  data-wow-duration="1000ms"
                >
                  View all
                  <i className="icon-autodeal-btn-right" />
                </a>
              </div>
            </div>
          </div>
          <div className="col-lg-12">
            <Swiper
              {...swiperOptions}
              modules={[Pagination]}
              className="swiper partner-slide overflow-hidden"
            >
              {carCategories.map((slide, index) => (
                <SwiperSlide className="swiper-slide" key={index}>
                  <a href="#" className="partner-item style-4">
                    <div className="image">
                      <img
                        className="lazyload"
                        data-src={slide.imgSrc}
                        alt="images"
                        src={slide.imgSrc}
                        width={slide.width}
                        height={slide.height}
                      />
                    </div>
                    <div className="content center">
                      <div className="fs-16 fw-6 title text-color-2 font-2">
                        {slide.title}
                      </div>
                      <span className="sub-title fs-12 fw-4 font-2">
                        {slide.subTitle}
                      </span>
                    </div>
                  </a>
                </SwiperSlide>
              ))}
            </Swiper>
          </div>
        </div>
      </div>
    </section>
  );
}
