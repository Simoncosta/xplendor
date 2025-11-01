import React from "react";
import { Link } from "react-router-dom";

import { blogArticles } from "@/data/blogs";
import { Swiper, SwiperSlide } from "swiper/react";
import { Pagination } from "swiper/modules";
export default function Blogs3({ parentClass = "section-blog tf-section" }) {
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
            <div
              className="swiper-container overflow-visible tf-sw-mobile7"
              data-preview={2}
              data-space={30}
            >
              <div className="swiper-wrapper blog-article-grid list-car-grid-blog-2">
                {blogArticles.map((article, index) => (
                  <div className="swiper-slide" key={index}>
                    <div className="blog-article-item style2 hover-img">
                      <div className="images img-style relative flex-none">
                        <img
                          className="lazyload"
                          data-src={article.imgSrc}
                          alt="images"
                          src={article.imgSrc}
                          width={450}
                          height={297}
                        />
                        <div className="date">{article.date}</div>
                      </div>
                      <div className="content">
                        <div className="sub-box flex align-center fs-13 fw-6">
                          <a href="#" className="admin fw-7 text-color-2">
                            {article.admin}
                          </a>
                          <a href="#" className="category text-color-3">
                            {article.category}
                          </a>
                        </div>
                        <h3>
                          <Link to={`/blog-detail/${article.id}`}>
                            {article.title}
                          </Link>
                        </h3>
                        <p>{article.description}</p>
                        <Link
                          to={`/blog-detail/${article.id}`}
                          className="read-more"
                        >
                          Read more
                        </Link>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
              <div className="swiper-pagination3" />
            </div>
            <Swiper
              className="swiper-container overflow-visible tf-sw-mobile7-swiper"
              slidesPerView={2}
              spaceBetween={30}
              modules={[Pagination]}
              pagination={{
                clickable: true,
                el: ".spd26",
              }}
            >
              {blogArticles.map((article, index) => (
                <SwiperSlide className="swiper-slide" key={index}>
                  <div className="blog-article-item style2 hover-img">
                    <div className="images img-style relative flex-none">
                      <img
                        className="lazyload"
                        data-src={article.imgSrc}
                        alt="images"
                        src={article.imgSrc}
                        width={450}
                        height={297}
                      />
                      <div className="date">{article.date}</div>
                    </div>
                    <div className="content">
                      <div className="sub-box flex align-center fs-13 fw-6">
                        <a href="#" className="admin fw-7 text-color-2">
                          {article.admin}
                        </a>
                        <a href="#" className="category text-color-3">
                          {article.category}
                        </a>
                      </div>
                      <h3>
                        <Link to={`/blog-detail/${article.id}`}>
                          {article.title}
                        </Link>
                      </h3>
                      <p>{article.description}</p>
                      <Link
                        to={`/blog-detail/${article.id}`}
                        className="read-more"
                      >
                        Read more
                      </Link>
                    </div>
                  </div>
                </SwiperSlide>
              ))}

              <div className="swiper-pagination3 spd26" />
            </Swiper>
          </div>
        </div>
      </div>
    </section>
  );
}
