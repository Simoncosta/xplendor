import { partnerLogos } from "@/data/brands";
import { Autoplay, Pagination } from "swiper/modules";
import { Swiper, SwiperSlide } from "swiper/react";

export default function Brands() {
  const swiperOptions = {
    autoplay: {
      delay: 0,
      disableOnInteraction: false,
    },
    slidesPerView: 2,
    loop: true,
    spaceBetween: 30,
    speed: 10000,
    observer: true,
    observeParents: true,

    breakpoints: {
      450: {
        slidesPerView: 3,
        spaceBetween: 20,
      },
      768: {
        slidesPerView: 4,
        spaceBetween: 20,
      },
      868: {
        slidesPerView: 5,
        spaceBetween: 30,
      },
      992: {
        slidesPerView: 6,
        spaceBetween: 30,
      },
    },
  };
  return (
    <section className="flat-brand tf-section3">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="title-section center">
              <h2
                className="wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                Our partners
              </h2>
            </div>
            <Swiper
              {...swiperOptions}
              modules={[Autoplay, Pagination]}
              className="swiper-container carousel-5"
            >
              {partnerLogos.map((logo, index) => (
                <SwiperSlide className="swiper-slide" key={index}>
                  <div className="slogan-logo">
                    <a href="#">
                      <img
                        className="lazyload"
                        data-src={logo.imgSrc}
                        alt={logo.imgAlt}
                        src={logo.imgSrc}
                        width={logo.imgWidth}
                        height={logo.imgHeight}
                      />
                    </a>
                  </div>
                </SwiperSlide>
              ))}
            </Swiper>
          </div>
        </div>
      </div>
    </section>
  );
}
