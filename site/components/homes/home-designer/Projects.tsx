import Link from "next/link";
import Image from "next/image";

import { projects4 } from "@/data/projects.json";
import StackCards from "@/components/animation/StackCards";
export default function Projects() {
  return (
    <div className="mxd-section padding-hero-08 padding-stacked-pre-mtext">
      <div className="mxd-container grid-container">
        {/* Block - Projects Stacking Cards #01 Start */}
        <div className="mxd-block mxd-grid-item no-margin">
          <div className="content__block">
            <StackCards
              stackName="projects-stack"
              className="stack-wrapper mxd-hero-08-stack"
            >
              {projects4.map((project, idx) => (
                <Link
                  key={idx}
                  className={`mxd-projects-stack__inner ${project.justify}`}
                  href={`/project-details`}
                >
                  <div className="mxd-projects-stack__image">
                    <Image
                      className="mobile"
                      alt="Project Preview"
                      src={project.images.mobile}
                      width={1080}
                      height={1920}
                    />
                    <Image
                      className="desktop"
                      alt="Project Preview"
                      src={project.images.desktop}
                      width={1920}
                      height={1080}
                    />
                  </div>

                  <div className="mxd-projects-stack__tags">
                    {project.tags.map((tag, i) => (
                      <span
                        key={i}
                        className="tag tag-default tag-outline-permanent"
                      >
                        {tag}
                      </span>
                    ))}
                  </div>

                  <div className="mxd-projects-stack__title no-margin">
                    <h2 className="permanent-light">{project.title}</h2>
                  </div>
                </Link>
              ))}
            </StackCards>
          </div>
        </div>
        {/* Block - Projects Stacking Cards #01 End */}
      </div>
    </div>
  );
}
