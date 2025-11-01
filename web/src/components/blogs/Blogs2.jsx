import React from "react";
import { Link } from "react-router-dom";

import { blogPosts2 } from "@/data/blogs";
import Pagination2 from "../common/Pagination2";
export default function Blogs2() {
  return (
    <section className="tf-section3 flat-blog-grid flat-blog-list flat-property">
      <div className="container">
        <div className="inner-heading flex-two flex-wrap">
          <h1 className="heading-listing">Blog Grid</h1>
          <div className="social-listing flex-six flex-wrap">
            <p>Share this page:</p>
            <div className="icon-social style1">
              <a href="#">
                <i className="icon-autodeal-facebook" />
              </a>
              <a href="#">
                <i className="icon-autodeal-linkedin" />
              </a>
              <a href="#">
                <i className="icon-autodeal-twitter" />
              </a>
              <a href="#">
                <i className="icon-autodeal-instagram" />
              </a>
            </div>
          </div>
        </div>
        <div className="row">
          <div className="col-lg-12">
            <div className="post">
              <div className="flat-blog">
                <div className="row">
                  {blogPosts2.map((post, index) => (
                    <div className="col-lg-4 col-md-6" key={index}>
                      <div className="box hover-img">
                        <div className="images img-style relative flex-none">
                          <img
                            alt="images"
                            src={post.imgSrc}
                            width={820}
                            height={540}
                          />
                          <div className="date">{post.date}</div>
                        </div>
                        <div className="content">
                          <div className="sub-box flex align-center fs-13 fw-6">
                            <a href="#" className="admin fw-7 text-color-2">
                              {post.admin}
                            </a>
                            <a href="#" className="category text-color-3">
                              {post.category}
                            </a>
                          </div>
                          <h3>
                            <Link to={`/blog-detail/${post.id}`}>
                              {post.title}
                            </Link>
                          </h3>
                          <p>{post.excerpt}</p>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
              <div className="themesflat-pagination clearfix center">
                <ul>
                  <Pagination2 />
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
