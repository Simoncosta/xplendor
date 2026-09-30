import Link from "next/link";
import Image from "next/image";

import { projects3 } from "@/data/projects.json";
export default function Projects() {
  return (
    <div
      id="projects"
      className="mxd-section overflow-hidden padding-hero-07 padding-pre-title"
    >
      <div className="mxd-container grid-container">
        {/* Block - Projects List #02 Start */}
        <div className="mxd-block">
          <div className="mxd-projects-list hover-reveal">
            {projects3.map((project, idx) => (
              <Link
                key={idx}
                className="mxd-projects-list__item hover-reveal__item"
                href={`/project-details`}
              >
                <div className="mxd-projects-list__border anim-uni-in-up" />

                <div className="hover-reveal__content hover-reveal-360x440">
                  <Image
                    className="hover-reveal__image"
                    alt="Project Preview"
                    src={project.preview.hover}
                    width={600}
                    height={730}
                  />
                </div>

                <div className="mxd-projects-list__inner-v2">
                  <div className="container-fluid px-0">
                    <div className="row gx-0">
                      <div className="col-12 col-xl-6 mxd-grid-item no-margin">
                        <div className="mxd-projects-list__title-v2 anim-uni-in-up">
                          <p>{project.title}</p>
                        </div>
                        <div className="mxd-projects-list__image anim-uni-in-up">
                          <Image
                            alt="Project Preview"
                            src={project.preview.full}
                            width={1200}
                            height={800}
                          />
                        </div>
                      </div>

                      <div className="col-12 col-md-6 col-xl-4 mxd-grid-item no-margin">
                        <div className="mxd-projects-list__tagslist-v2 anim-uni-in-up">
                          <ul>
                            {project.tags.map((tag, i) => (
                              <li key={i}>
                                <p>{tag.name}</p>
                                {tag.icon && (
                                  <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    version="1.1"
                                    viewBox="0 0 20 20"
                                  >
                                    <path d="M19.6,9.6h-3.9c..." />
                                  </svg>
                                )}
                              </li>
                            ))}
                          </ul>
                        </div>
                      </div>

                      <div className="col-12 col-md-6 col-xl-2 mxd-grid-item no-margin">
                        <div className="mxd-projects-list__product anim-uni-in-up">
                          <p>{project.subtitle}</p>
                          <div className="mxd-projects-list__icon-v2">
                            <i className="ph-bold ph-arrow-up-right" />
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div className="mxd-projects-list__border anim-uni-in-up" />
              </Link>
            ))}
          </div>
        </div>
        {/* Block - Projects List #02 End */}
      </div>
    </div>
  );
}
