import Link from "next/link";
import Image from "next/image";

import { projects1 } from "@/data/projects.json";
import RevealText from "@/components/animation/RevealText";
import BackgroundParallax from "@/components/animation/BackgroundParallax";
import AnimatedButton from "@/components/animation/AnimatedButton";
import AnimateRotation from "@/components/animation/AnimateRotation";
import MasonryGrid from "@/components/animation/MasonryGrid";
export default function Projects() {
  return (
    <div className="mxd-section padding-pre-grid">
      <div className="mxd-container grid-l-container">
        {/* Block - Projects Masonry #01 with Section Title Start */}
        <div className="mxd-block">
          <div className="mxd-projects-masonry">
            <div className="container-fluid p-0">
              {/* Portfolio Gallery Start */}
              <MasonryGrid
                itemSelector=".mxd-projects-masonry__item"
                className="row g-0 mxd-projects-masonry__gallery"
              >
                {/* portfolio gallery title */}
                <div className="col-12 col-xl-6 mxd-projects-masonry__title section-title mxd-projects-masonry__item">
                  <div className="mxd-section-title no-margin-desktop">
                    <div className="container-fluid p-0">
                      <div className="row g-0">
                        <div className="col-12">
                          <div className="mxd-section-title__title anim-uni-in-up">
                            <RevealText as="h2" className="reveal-type">
                              Case studies
                            </RevealText>
                          </div>
                        </div>
                        <div className="col-12">
                          <div className="mxd-section-title__descr">
                            <p className="anim-uni-in-up">
                              Explore a selection of projects blending
                              <br />
                              creativity with practical design
                            </p>
                          </div>
                        </div>
                        <div className="col-12">
                          <div className="mxd-section-title__controls anim-uni-in-up">
                            <AnimatedButton
                              text="All Works"
                              className="btn btn-anim btn-default btn-outline slide-right-up"
                              href={`/works-simple`}
                            >
                              <i className="ph ph-arrow-up-right" />
                            </AnimatedButton>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                {/* portfolio gallery single item */}
                {projects1.slice(0, 6).map((project, index) => (
                  <div
                    key={index}
                    className="col-12 col-xl-6 mxd-project-item mxd-projects-masonry__item"
                  >
                    <Link
                      className="mxd-project-item__media masonry-media"
                      href={`/project-details`}
                    >
                      <BackgroundParallax
                        className={`mxd-project-item__preview masonry-preview ${project.previewClass} parallax-img-small`}
                      />
                      <div className="mxd-project-item__tags">
                        {project.tags.map((tag, i) => (
                          <span
                            key={i}
                            className="tag tag-default tag-permanent"
                          >
                            {tag}
                          </span>
                        ))}
                      </div>
                    </Link>
                    <div className="mxd-project-item__promo">
                      <div className="mxd-project-item__name">
                        <Link
                          className={project.anim}
                          href={`/project-details`}
                        >
                          <span>{project.title}</span> {project.description}
                        </Link>
                      </div>
                    </div>
                  </div>
                ))}
              </MasonryGrid>
              {/* Portfolio Gallery End */}
              {/* Portfolio Link Start */}
              <div className="mxd-projects-masonry__btngroup animate-in-up">
                <Link
                  href={`/contact`}
                  className="btn-rotating btn-rotating-180 ver-02"
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
