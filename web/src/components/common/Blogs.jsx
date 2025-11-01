import { blogSlides } from "@/data/blogs";
import { Swiper, SwiperSlide } from "swiper/react";
import { Link } from "react-router-dom";

export default function Blogs({ parentClass = "section-blog tf-section" }) {
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
                News to help choose your car
              </h2>
              <Link
                to={`/blog-grid`}
                className="tf-btn-arrow wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                View all
                <i className="icon-autodeal-btn-right" />
              </Link>
            </div>
            <Swiper
              className="swiper tf-sw-mobile"
              slidesPerView={3}
              breakpoints={{
                1024: {
                  slidesPerView: 3,
                },
                768: {
                  slidesPerView: 2,
                },
                0: {
                  slidesPerView: 1,
                },
              }}
              spaceBetween={30}
            >
              {blogSlides.map((slide, index) => (
                <SwiperSlide className="swiper-slide" key={index}>
                  <div className="blog-article-item style1 hover-img">
                    <div className="images img-style relative flex-none">
                      <img
                        className="lazyload"
                        data-src={slide.imgSrc}
                        alt={slide.imgAlt}
                        src={slide.imgSrc}
                        width={slide.imgWidth}
                        height={slide.imgHeight}
                      />
                      <div className="date">{slide.date}</div>
                    </div>
                    <div className="content">
                      <div className="sub-box flex align-center fs-13 fw-6">
                        <a href="#" className="admin fw-7 text-color-2">
                          {slide.admin}
                        </a>
                        <a href="#" className="category text-color-3">
                          {slide.category}
                        </a>
                      </div>
                      <h3>
                        <Link to={`/blog-detail/${slide.id}`}>
                          {slide.title}
                        </Link>
                      </h3>
                      <p>{slide.description}</p>
                    </div>
                  </div>
                </SwiperSlide>
              ))}

              <div className="swiper-pagination3" />
            </Swiper>
          </div>
        </div>
      </div>
    </section>
  );
}
