import Link from "next/link";
import Image from "next/image";

import AnimateRotation from "../animation/AnimateRotation";
import MasonryGrid from "../animation/MasonryGrid";
import { projects1 } from "@/data/projects.json";
import BackgroundParallax from "../animation/BackgroundParallax";
export default function PortfolioMasonry() {
  return (
    <div className="mxd-section mxd-section-inner-headline grid-headline padding-default">
      <div className="mxd-container grid-l-container">
        {/* Block - Projects Masonry #01 with Section Title Start */}
        <div className="mxd-block loading-wrap">
          <div className="mxd-projects-masonry loading__item">
            <div className="container-fluid p-0">
              {/* Portfolio Gallery Start */}
              <MasonryGrid
                className="row g-0 mxd-projects-masonry__gallery"
                data-masonry='{"percentPosition": true }'
                itemSelector=".mxd-projects-masonry__item"
              >
                {/* portfolio gallery title */}
                <div className="col-12 col-xl-6 mxd-projects-masonry__item mxd-projects-masonry__title headline-title">
                  <div className="mxd-block__inner-headline">
                    <h1 className="inner-headline__title headline-img-before headline-img-07">
                      Projects
                      <br />
                      to explore
                    </h1>
                  </div>
                </div>
                {/* portfolio gallery single item */}
                {projects1.map((item, index) => (
                  <div
                    key={index}
                    className="col-12 col-xl-6 mxd-project-item mxd-projects-masonry__item"
                  >
                    <Link
                      className="mxd-project-item__media masonry-media"
                      href={`/project-details`}
                    >
                      <BackgroundParallax
                        className={`mxd-project-item__preview masonry-preview ${item.previewClass} parallax-img-small`}
                      />
                      <div className="mxd-project-item__tags">
                        {item.tags.map((tag, idx) => (
                          <span
                            key={idx}
                            className="tag tag-default tag-permanent"
                          >
                            {tag}
                          </span>
                        ))}
                      </div>
                    </Link>
                    <div className="mxd-project-item__promo masonry-promo">
                      <div className="mxd-project-item__name">
                        <Link href={`/project-details`}>
                          <span>{item.title}</span> {item.description}
                        </Link>
                      </div>
                    </div>
                  </div>
                ))}
                {/* portfolio gallery single item */}
              </MasonryGrid>
              {/* Portfolio Gallery End */}
              {/* Portfolio Link Start */}
              <div className="mxd-projects-masonry__btngroup anim-uni-in-up">
                <Link
                  className="btn-rotating btn-rotating-180 ver-02"
                  href={`/contact`}
                >
                  {/* SVG rotating text */}
                  <AnimateRotation
                    as="svg"
                    version="1.1"
                    id="scrollDown"
                    xmlns="http://www.w3.org/2000/svg"
                    xmlnsXlink="http://www.w3.org/1999/xlink"
                    x="0px"
                    y="0px"
                    viewBox="0 0 160 160"
                    enableBackground={"new 0 0 160 160"}
                    xmlSpace="preserve"
                    className="btn-rotating__text animate-rotation"
                    value={360}
                  >
                    <defs>
                      <path
                        id="textPath"
                        d="M149.7,80c0,38.5-31.2,69.7-69.7,69.7S10.3,118.5,10.3,80S41.5,10.3,80,10.3S149.7,41.5,149.7,80z"
                      />
                    </defs>
                    <g>
                      <use xlinkHref="#textPath" fill="none" />
                      <text>
                        {/* button text here!!! */}
                        <textPath xlinkHref="#textPath">
                          Let&apos;s start new project * Let&apos;s start new
                          project *
                        </textPath>
                      </text>
                    </g>
                  </AnimateRotation>
                  {/* image */}
                  <Image
                    className="btn-rotating__image"
                    alt="Object"
                    src="/img/icons/300x300_obj-btn-03.webp"
                    width={300}
                    height={300}
                  />
                </Link>
              </div>
              {/* Portfolio Link End */}
            </div>
          </div>
        </div>
        {/* Block - Projects Masonry #01 with Section Title Start */}
      </div>
    </div>
  );
}
