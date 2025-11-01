import React from "react";
import { Link } from "react-router-dom";

import { blogPosts4 } from "@/data/blogs";
export default function Blogs2() {
  return (
    <section className="section-blog tf-section3">
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
          </div>
          <div className="col-lg-6">
            <div className="blog-article-left">
              {blogPosts4.slice(0, 1).map((post, i) => (
                <div key={i} className="blog-article-item hover-img">
                  <div className="images img-style relative flex-none">
                    <img
                      className="ls-is-cached lazyloaded"
                      alt="images"
                      src={post.imgSrc}
                      width={945}
                      height={623}
                    />
                    <div className="date">{post.date}</div>
                  </div>
                  <div className="content">
                    <div className="sub-box flex align-center fs-13 fw-6">
                      <a href="#" className="admin fw-7 text-color-2">
                        Jerome Bell
                      </a>
                      <a href="#" className="category text-color-3">
                        First Drives
                      </a>
                    </div>
                    <h3>
                      <Link to={`/blog-detail/${post.id}`}>{post.title}</Link>
                    </h3>
                    <p>{post.desc}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
          <div className="col-lg-6">
            <div className="blog-article-right">
              {blogPosts4.slice(1, 4).map((post, i) => (
                <div
                  key={i}
                  className="blog-article-item style3 hover-img wow fadeInUpSmall"
                  data-wow-delay="0.2s"
                  data-wow-duration="1000ms"
                >
                  <div className="images img-style relative flex-none">
                    <img
                      className="ls-is-cached lazyloaded"
                      alt="images"
                      src={post.imgSrc}
                      width={285}
                      height={188}
                    />
                  </div>
                  <div className="content">
                    <div className="sub-box flex align-center flex-wrap fs-13 fw-6">
                      <a href="#" className="admin fw-7 text-color-2">
                        Jerome Bell
                      </a>
                      <a href="#" className="category text-color-3 fw-4">
                        First Drives
                      </a>
                      <a href="#" className="date fw-4 fs-12 font-2">
                        {post.date}
                      </a>
                    </div>
                    <h3>
                      <Link to={`/blog-detail/${post.id}`}>{post.title}</Link>
                    </h3>
                    <p>{post.desc}</p>
                  </div>
                </div>
              ))}

              <div
                className="flat-bt-top wow fadeInUpSmall"
                data-wow-delay="0.2s"
                data-wow-duration="1000ms"
              >
                <a className="sc-button btn-1" href="#">
                  <span>View all news</span>
                  <i className="icon-autodeal-next" />
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
