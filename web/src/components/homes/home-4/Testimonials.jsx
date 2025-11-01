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
      el: ".spd17",
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
    <section className="tf-section bg-1">
      <div className="container">
        <div className="row">
          <div className="col-lg-12">
            <div className="heading-section center">
              <h2
                className="wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                We love our clients
              </h2>
              <p
                className="mt-18 wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                Discover exceptional experiences through testimonials from our
                satisfied customers.
              </p>
            </div>
          </div>
          <div className="col-lg-12">
            <Swiper
              {...swiperOptions}
              modules={[Pagination, Navigation]}
              className="swiper-container carousel-7"
            >
              {testimonials.slice(0, 6).map((elm, i) => (
                <SwiperSlide key={i} className="swiper-slide">
                  <div className="tf-testimonial style-2 box-tes">
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

              <div className="swiper-pagination3 spd17 pb-1" />
            </Swiper>
          </div>
        </div>
      </div>
    </section>
  );
}
