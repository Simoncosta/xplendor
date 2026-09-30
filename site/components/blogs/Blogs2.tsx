import Link from "next/link";

import Image from "next/image";
import { blogs2, blogs5, blogs6 } from "@/data/blogs.json";
// -------------------- Shared bits --------------------
const StarSvg = () => (
  <svg xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 20 20">
    <path d="M19.6,9.6h-3.9c-.4,0-1.8-.2-1.8-.2-.6,0-1.1-.2-1.6-.6-.5-.3-.9-.8-1.2-1.2-.3-.4-.4-.9-.5-1.4,0,0,0-1.1-.2-1.5V.4c0-.2-.2-.4-.4-.4s-.4.2-.4.4v4.4c0,.4-.2,1.5-.2,1.5,0,.5-.2,1-.5,1.4-.3.5-.7.9-1.2,1.2s-1,.5-1.6.6c0,0-1.2,0-1.7.2H.4c-.2,0-.4.2-.4.4s.2.4.4.4h4.1c.4,0,1.7.2,1.7.2.6,0,1.1.2,1.6.6.4.3.8.7,1.1,1.1.3.5.5,1,.6,1.6,0,0,0,1.3.2,1.7v4.1c0,.2.2.4.4.4s.4-.2.4-.4v-4.1c0-.4.2-1.7.2-1.7,0-.6.2-1.1.6-1.6.3-.4.7-.8,1.1-1.1.5-.3,1-.5,1.6-.6,0,0,1.3,0,1.8-.2h3.9c.2,0,.4-.2.4-.4s-.2-.4-.4-.4h0Z" />
  </svg>
);

const MetaTag: React.FC<{ label: string }> = ({ label }) => (
  <span className="meta-tag">
    <a href="#">{label}</a>
    <StarSvg />
  </span>
);

// -------------------- Data (NO href fields) --------------------

// -------------------- Component --------------------
export default function Blogs2() {
  return (
    <>
      {/* Section - Inner Page Headline Start */}
      <div className="mxd-section mxd-section-inner-headline padding-blog-descr-pre-grid">
        <div className="mxd-container grid-container">
          <div className="mxd-block loading-wrap">
            <div className="container-fluid px-0">
              <div className="row gx-0">
                <div className="col-12" />
                <div className="col-12 col-xl-7 mxd-grid-item no-margin">
                  <div className="mxd-block__content">
                    <div className="mxd-block__inner-headline loading__item">
                      <h1 className="inner-headline__title headline-img-before headline-img-06">
                        Our digital digest
                      </h1>
                    </div>
                  </div>
                </div>
                <div className="col-12 col-xl-5 mxd-grid-item no-margin">
                  <div className="mxd-block__content">
                    <div className="inner-headline__descr loading__item">
                      <p>
                        Dive into the latest trends, uncover valuable industry
                        insights, and explore practical advice designed to help
                        you successfully navigate and thrive in the
                        ever-evolving digital landscape.
                      </p>
                    </div>
                  </div>
                </div>
                {/* Inner Headline Aside End */}
              </div>
            </div>
          </div>
        </div>
      </div>
      {/* Section - Inner Page Headline End */}

      {/* Section - Blog Start */}
      <div className="mxd-section padding-pre-title">
        <div className="mxd-container grid-container">
          <div className="mxd-posts-area column loading__fade">
            {/* Posts Container Start */}
            <div className="mxd-posts-container fullwidth-posts-container mxd-grid-item">
              {/* Featured Post */}
              {blogs2.map((featured) => (
                <article
                  key={featured.id}
                  className="mxd-post post-featured post-featured-v2 radius-l"
                >
                  <Link className="post-featured__thumb" href={`/blog-article`}>
                    <Image
                      alt="Featured Post Thumb"
                      src={featured.img}
                      width={1400}
                      height={900}
                    />
                  </Link>

                  <div className="post-featured__categories">
                    {featured.categories.map((c) => (
                      <span
                        key={c}
                        className="tag tag-default tag-outline-permanent tag-link-outline-premanent"
                      >
                        <a href="#">{c}</a>
                      </span>
                    ))}
                  </div>

                  <div className="post-featured__content">
                    <div className="post-featured__meta">
                      {featured.meta.map((m) => (
                        <MetaTag key={m} label={m} />
                      ))}
                      <span className="meta-date">{featured.date}</span>
                    </div>

                    <h3 className="post-featured__title">
                      <Link href={`/blog-article`}>{featured.title}</Link>
                    </h3>

                    <div className="post-featured__excerpt">
                      <p>{featured.excerpt}</p>
                    </div>
                  </div>
                </article>
              ))}
            </div>
            {/* Posts Container End */}

            {/* Secondary Posts Group Start */}
            <div className="mxd-posts-container fullwidth-posts-container">
              <div className="container-fluid p-0">
                <div className="row g-0">
                  {blogs5.map((sp) => (
                    <div
                      className="col-12 col-xl-6 mxd-grid-item"
                      key={sp.title}
                    >
                      <article className="mxd-post post-secondary">
                        <Link
                          className="post-secondary__thumb radius-l"
                          href={`/blog-article`}
                        >
                          <Image
                            alt="Post Simple Thumb"
                            src={sp.img}
                            width={1000}
                            height={1250}
                          />
                          <div className="mxd-preview-hover">
                            <i className="mxd-preview-hover__icon">
                              <Image
                                alt="Eye Icon"
                                src="/img/icons/icon-eye.svg"
                                width={38}
                                height={21}
                              />
                            </i>
                          </div>
                        </Link>

                        <div className="post-secondary__categories">
                          {sp.categories.map((c) => (
                            <span
                              key={c}
                              className="tag tag-default tag-outline-permanent tag-link-outline-premanent"
                            >
                              <a href="#">{c}</a>
                            </span>
                          ))}
                        </div>

                        <div className="post-secondary__descr">
                          <div className="post-secondary__meta">
                            {sp.meta.map((m) => (
                              <MetaTag key={m} label={m} />
                            ))}
                            <span className="meta-date">{sp.date}</span>
                          </div>

                          <div className="post-secondary__title">
                            <h5>
                              <Link href={`/blog-article`}>
                                <span>{sp.title}</span>
                              </Link>
                            </h5>
                          </div>
                        </div>
                      </article>
                    </div>
                  ))}
                </div>
              </div>
            </div>
            {/* Secondary Posts Group End */}
          </div>
        </div>
      </div>
      {/* Section - Blog End */}

      {/* Section - Blog Archive Start */}
      <div className="mxd-section padding-default">
        <div className="mxd-container grid-container">
          {/* Block - Section Title */}
          <div className="mxd-block">
            <div className="mxd-section-title no-controls">
              <div className="container-fluid p-0">
                <div className="row g-0">
                  <div className="col-12 col-xl-8 mxd-grid-item no-margin">
                    <div className="mxd-section-title__hrtitle">
                      <h2>
                        All
                        <br />
                        resources
                      </h2>
                    </div>
                  </div>
                  <div className="col-12 col-xl-4 mxd-grid-item no-margin">
                    <div className="mxd-section-title__hrdescr">
                      <p>
                        Browse through all our articles, tutorials, roundups,
                        and experiments.
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* Block - Archive Blog List */}
          <div className="mxd-block">
            <div className="mxd-projects-archive">
              {blogs6.map((item) => (
                <Link
                  key={item.title}
                  className="mxd-projects-archive__item"
                  href={`/blog-article`}
                >
                  <div className="mxd-projects-archive__border" />
                  <div className="mxd-projects-archive__inner">
                    <div className="container-fluid px-0">
                      <div className="row gx-0">
                        <div className="col-12 col-xl-8 mxd-grid-item no-margin">
                          <div className="mxd-projects-archive__title">
                            <div className="mxd-projects-archive__image">
                              <Image
                                alt="Project Preview"
                                src={item.img}
                                width={1200}
                                height={800}
                              />
                            </div>
                            <p>
                              <span>{item.title}</span>
                            </p>
                          </div>
                        </div>
                        <div className="col-6 col-md-6 col-xl-2 mxd-grid-item no-margin">
                          <div className="mxd-projects-archive__tagslist">
                            <ul>
                              {item.tags.map((t) => (
                                <li key={t}>
                                  <p className="t-small">{t}</p>
                                </li>
                              ))}
                            </ul>
                          </div>
                        </div>
                        <div className="col-6 col-md-6 col-xl-2 mxd-grid-item no-margin">
                          <div className="mxd-projects-list__date">
                            <p className="t-small">{item.date}</p>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div className="mxd-projects-archive__border" />
                </Link>
              ))}
            </div>
          </div>
          {/* Block - Archive Blog List End */}
        </div>
      </div>
      {/* Section - Blog Archive End */}
    </>
  );
}
