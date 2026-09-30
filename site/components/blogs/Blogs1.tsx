import Link from "next/link";
import socials from "@/data/socials.json";
import Image from "next/image";
import { blogs2, blogs3, blogs4 } from "@/data/blogs.json";
import SearchForm from "./SearchForm";
import AnimatedButton from "../animation/AnimatedButton";
// -------------------- Data (NO LINKS INSIDE) --------------------
const headlineTags = [
  "Midjourney",
  "AI",
  "Editorial",
  "Interactive design",
  "Web experience",
  "Web design",
  "Branding",
  "UI",
  "HTML",
  "Frontend",
  "UX",
  "Motion design",
  "3d modeling",
  "Video editing",
];

const sidebarCategories = [
  "Design",
  "Development",
  "Digital Marketing",
  "Technology",
  "Case Studies",
  "Trends",
];

// -------------------- Small helpers --------------------
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

// -------------------- Component --------------------
export default function Blogs1() {
  return (
    <>
      {/* Section - Inner Page Headline Start */}
      <div className="mxd-section mxd-section-inner-headline padding-blog-default-pre-grid">
        <div className="mxd-container grid-container">
          <div className="mxd-block loading-wrap">
            <div className="container-fluid px-0">
              <div className="row gx-0">
                <div className="col-12" />
                <div className="col-12 col-xl-10 mxd-grid-item no-margin">
                  <div className="mxd-block__content">
                    <div className="mxd-block__inner-headline loading__item">
                      <h1 className="inner-headline__title headline-img-before headline-img-06">
                        Our digital digest
                      </h1>
                    </div>
                  </div>
                </div>
                <div className="col-12" />
              </div>

              <div className="row g-0">
                <div className="col-12" />
                {/* Headline tags via map */}
                <div className="col-12 col-xl-8 mxd-grid-item no-margin">
                  <div className="inner-headline__blogtags loading__item">
                    {headlineTags.map((t) => (
                      <span
                        key={t}
                        className="tag tag-default tag-outline tag-link-outline"
                      >
                        <a href="#">{t}</a>
                      </span>
                    ))}
                  </div>
                </div>
                {/* Breadcrumbs */}
                <div className="col-12 col-xl-4 mxd-grid-item no-margin">
                  <div className="inner-headline__breadcrumbs loading__fade">
                    <div className="breadcrumbs__nav">
                      <span>
                        <Link href={`/index-main`}>Home</Link>
                      </span>
                      <span className="current-item">Insights</span>
                    </div>
                  </div>
                </div>
              </div>
              {/* Inner Headline Tags End */}
            </div>
          </div>
        </div>
      </div>
      {/* Section - Inner Page Headline End */}

      {/* Section - Blog Start */}
      <div className="mxd-section padding-default">
        <div className="mxd-container grid-container">
          <div className="mxd-posts-area loading__fade">
            {/* Posts Container Start */}
            <div className="mxd-posts-container mxd-grid-item">
              {/* Featured Post */}
              {blogs2.map((featured) => (
                <article
                  key={featured.id}
                  className="mxd-post post-featured radius-m"
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
              {/* Regular posts via map */}
              {blogs3.map((p, idx) => (
                <article className="mxd-post post-simple" key={idx}>
                  <Link
                    className="post-simple__thumb radius-m"
                    href={`/blog-article`}
                  >
                    <Image
                      alt={p.imgAlt}
                      src={p.img}
                      width={800}
                      height={680}
                    />
                    <div className="mxd-preview-hover">
                      <i className="mxd-preview-hover__icon icon-small">
                        <Image
                          alt="Eye Icon"
                          src="/img/icons/icon-eye.svg"
                          width={38}
                          height={21}
                        />
                      </i>
                    </div>
                  </Link>

                  <div className="post-simple__content">
                    <div className="post-simple__descr">
                      <div className="post-simple__meta">
                        {p.meta.map((m) => (
                          <MetaTag key={m.label} label={m.label} />
                        ))}
                        <span className="meta-date">{p.date}</span>
                      </div>

                      <div className="post-simple__title">
                        <h5>
                          <Link href={`/blog-article`}>{p.title}</Link>
                        </h5>
                      </div>
                    </div>

                    <div className="post-simple__btn">
                      <AnimatedButton
                        className="btn btn-anim btn-default btn-outline slide-right-up"
                        text="Read More"
                        href={`/blog-article`}
                      >
                        <i className="ph ph-arrow-up-right" />
                      </AnimatedButton>
                    </div>
                  </div>
                </article>
              ))}

              {/* Blog Pagination */}
              <div className="mxd-blog-pagination">
                <div className="mxd-blog-pagination__inner">
                  <nav className="mxd-blog-pagination__items">
                    <AnimatedButton
                      href="#"
                      className="mxd-blog-pagination__item blog-pagination-control prev btn btn-anim btn-line-small btn-bright anim-no-delay slide-left"
                      as={"a"}
                      text="Prev"
                      position={"previous"}
                    >
                      <i className="ph ph-arrow-left" />
                    </AnimatedButton>
                    {[1, 2, 3].map((n) => (
                      <AnimatedButton
                        key={n}
                        className={`mxd-blog-pagination__item blog-pagination-number btn btn-anim ${
                          n === 2 ? "active" : ""
                        }`}
                        as={"a"}
                        text={n.toString()}
                      ></AnimatedButton>
                    ))}
                    <AnimatedButton
                      as={"a"}
                      text="Next"
                      className="mxd-blog-pagination__item blog-pagination-control next btn btn-anim btn-line-small btn-bright anim-no-delay slide-right"
                      href="#"
                    >
                      <i className="ph ph-arrow-right" />
                    </AnimatedButton>
                  </nav>
                </div>
              </div>
            </div>
            {/* Posts Container End */}

            {/* Sidebar Start */}
            <div className="mxd-sidebar mxd-grid-item">
              {/* search widget */}
              <div className="mxd-sidebar__widget bg-base-tint radius-m widget-search">
                <SearchForm />
              </div>

              {/* categories widget */}
              <div className="mxd-sidebar__widget bg-base-tint radius-m">
                <div className="widget__title">
                  <p>Categories</p>
                </div>
                <ul className="widget__categories">
                  {sidebarCategories.map((c) => (
                    <li className="categories__item" key={c}>
                      <a href="#" className="categories__link">
                        {c}
                      </a>
                    </li>
                  ))}
                </ul>
              </div>

              {/* recent posts */}
              <div className="mxd-sidebar__widget bg-base-tint radius-m">
                <div className="widget__title">
                  <p>Latest posts</p>
                </div>
                <ul className="widget__recent-posts">
                  {blogs4.map((rp) => (
                    <li className="recent-post__item" key={rp.title}>
                      <div className="recent-post__thumb">
                        <Link href={`/blog-article`}>
                          <Image
                            alt="Recent Post Preview"
                            src={rp.img}
                            width={300}
                            height={300}
                          />
                        </Link>
                      </div>
                      <div className="recent-post__content">
                        <div className="recent-post__meta">
                          <span className="meta-tag">
                            <StarSvg />
                            <a href="#">{rp.category}</a>
                          </span>
                        </div>
                        <div className="recent-post__title">
                          <Link href={`/blog-article`}>{rp.title}</Link>
                        </div>
                      </div>
                    </li>
                  ))}
                </ul>
              </div>

              {/* ad */}
              <div className="mxd-sidebar__widget radius-m widget-ad">
                <a className="widget__image" href="#" target="_blank">
                  <Image
                    alt="Ad Image"
                    src="/img/blog/1200x1320_ad-01.webp"
                    width={1200}
                    height={1320}
                  />
                </a>
                <div className="widget__tags">
                  <span className="tag tag-default tag-permanent">
                    Sponsored
                  </span>
                </div>
              </div>

              {/* about widget */}
              <div className="mxd-sidebar__widget bg-base-tint radius-m widget-about">
                <div className="widget__title">
                  <p>About</p>
                </div>
                <div className="widget__descr">
                  <p className="t-small">
                    Dive into the latest trends, uncover valuable industry
                    insights, and explore practical advice.
                  </p>
                </div>
              </div>

              {/* socials */}
              <div className="mxd-sidebar__widget bg-base-tint radius-m widget-socials">
                <div className="widget__title">
                  <p>Ecosystem</p>
                </div>
                <div className="widget__descr">
                  <p className="t-small">
                    Follow us and get to know all the news and other interesting
                    stuff first!
                  </p>
                </div>

                <div className="widget__social-links-small">
                  {socials.map(({ title, url }) => (
                    <div className="social-links-small__item" key={title}>
                      <div className="social-links-small__divider" />
                      <a
                        className="social-links-small__link"
                        href={url}
                        target="_blank"
                      >
                        <p className="social-links-small__title">{title}</p>
                        <div className="social-links-small__icon">
                          <i className="ph-bold ph-arrow-up-right" />
                        </div>
                      </a>
                      <div className="social-links-small__divider" />
                    </div>
                  ))}
                </div>
              </div>
            </div>
            {/* Sidebar End */}
          </div>
        </div>
      </div>
      {/* Section - Blog End */}
    </>
  );
}
