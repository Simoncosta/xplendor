import React from "react";
import BlogSidebar from "./BlogSidebar";
import { Link } from "react-router-dom";

import { blogPosts } from "@/data/blogs";
import Pagination2 from "../common/Pagination2";
export default function Blogs1() {
  return (
    <section className="tf-section3 flat-blog-list flat-property">
      <div className="container">
        <div className="inner-heading flex-two flex-wrap">
          <h1 className="heading-listing">Blog List</h1>
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
          <div className="col-lg-8">
            <div className="post">
              <div className="flat-blog">
                <div className="wrap-blog">
                  {blogPosts.map((post, index) => (
                    <div className="box hover-img" key={index}>
                      <div className="images img-style relative flex-none">
                        <img
                          alt="images"
                          src={post.imgSrc}
                          width={post.imgWidth}
                          height={post.imgHeight}
                        />
                      </div>
                      <div className="content">
                        <h3>
                          <Link to={`/blog-detail/${post.id}`}>
                            {post.title}
                          </Link>
                        </h3>
                        <div className="sub-box flex align-center fs-13 fw-6">
                          <a className="title-2 text-color-3">
                            {post.category}
                          </a>
                          <div className="title-1">{post.date}</div>
                        </div>
                        <p>{post.description}</p>
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
          <div className="col-lg-4">
            <BlogSidebar />
          </div>
        </div>
      </div>
    </section>
  );
}
