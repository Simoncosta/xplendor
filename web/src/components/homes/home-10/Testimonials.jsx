import { testimonials } from "@/data/testimonials";
import { Navigation, Pagination } from "swiper/modules";
import { Swiper, SwiperSlide } from "swiper/react";

export default function Testimonials() {
  const swiperOptions = {
    // autoplay: {
    //     delay: 5000,
    //     disableOnInteraction: false,
    // },
    loop: true,
    slidesPerView: 1,
    spaceBetween: 30,

    pagination: {
      el: ".spd13",
      clickable: true,
    },
    breakpoints: {
      0: {
        slidesPerView: 1,
        spaceBetween: 20,
      },
      768: {
        slidesPerView: 2,
        spaceBetween: 20,
      },
      992: {
        slidesPerView: 3,
        spaceBetween: 30,
      },
    },
  };
  return (
    <section className="tf-section3">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div
              className="heading-section center wow fadeInUpSmall"
              data-wow-delay="0.2s"
              data-wow-duration="1000ms"
            >
              <h2>We love our clients</h2>
            </div>
          </div>
          <div className="col-lg-12">
            <Swiper
              {...swiperOptions}
              modules={[Navigation, Pagination]}
              className="swiper-container carousel-7 overflow-hidden"
            >
              {testimonials.slice(0, 6).map((elm, i) => (
                <SwiperSlide key={i} className="swiper-slide">
                  <div className="tf-testimonial bg-4">
                    <div className="inner-top flex-two">
                      <img
                        className="lazyload"
                        data-src="/assets/images/section/star-5.png"
                        alt="images"
                        src="/assets/images/section/star-5.png"
                        width={112}
                        height={21}
                      />
                      <p className="fs-12">{elm.date}</p>
                    </div>
                    <p className="fs-16 lh-22 text-color-2">"{elm.text}"</p>
                    <div className="author-box flex">
                      <div className="images">
                        <img
                          className="lazyload"
                          alt="images"
                          src={elm.authorImage}
                          width={120}
                          height={120}
                        />
                      </div>
                      <div className="content">
                        <h5>{elm.authorName}</h5>
                        <p className="fs-12 lh-16">{elm.authorTitle}</p>
                      </div>
                    </div>
                  </div>
                </SwiperSlide>
              ))}

              <div className="swiper-pagination3 spd13 pb-1" />
            </Swiper>
          </div>
        </div>
      </div>
    </section>
  );
}
