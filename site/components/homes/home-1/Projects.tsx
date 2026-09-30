import Link from "next/link";

import { projects1 } from "@/data/projects.json";
import RevealText from "@/components/animation/RevealText";
import BackgroundParallax from "@/components/animation/BackgroundParallax";
import AnimatedButton from "@/components/animation/AnimatedButton";
export default function Projects() {
  return (
    <div id="projects" className="mxd-section padding-pre-stack">
      <div className="mxd-container grid-container">
        {/* Block - Projects Pinned #01 with Section Title Start */}
        <div className="mxd-block">
          <div className="mxd-pinned-projects">
            <div className="container-fluid px-0">
              <div className="row gx-0">
                <div className="col-12 col-xl-5 mxd-pinned-projects__static">
                  <div className="mxd-pinned-projects__static-inner no-margin">
                    {/* Section Title Start */}
                    <div className="mxd-section-title no-margin-desktop">
                      <div className="container-fluid p-0">
                        <div className="row g-0">
                          <div className="col-12 mxd-grid-item no-margin">
                            <div className="mxd-section-title__title anim-uni-in-up">
                              <RevealText as="h2" className="reveal-type">
                                Featured
                                <br />
                                projects
                              </RevealText>
                            </div>
                          </div>
                          <div className="col-12 mxd-grid-item no-margin">
                            <div className="mxd-section-title__descr anim-uni-in-up">
                              <p>
                                Explore a selection of projects blending
                                <br />
                                creativity with practical design
                              </p>
                            </div>
                          </div>
                          <div className="col-12 mxd-grid-item no-margin">
                            <div className="mxd-section-title__controls anim-uni-in-up">
                              <AnimatedButton
                                text="All Works"
                                className="btn btn-anim btn-default btn-outline slide-right-up"
                                href={`/works-simple`}
                              >
                                <i className="ph-bold ph-arrow-up-right" />
                              </AnimatedButton>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                    {/* Section Title Start */}
                  </div>
                </div>
                <div className="col-12 col-xl-7 mxd-pinned-projects__scroll">
                  <div className="mxd-pinned-projects__scroll-inner mxd-grid-item no-margin">
                    {projects1.slice(0, 5).map((project, index) => (
                      <div key={index} className="mxd-project-item">
                        <Link
                          className={`mxd-project-item__media ${project.anim}`}
                          href={`/project-details`}
                        >
                          <BackgroundParallax
                            className={`mxd-project-item__preview ${project.previewClass} parallax-img-small`}
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
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        {/* Block - Projects Pinned #01 with Section Title Start */}
      </div>
    </div>
  );
}
