import Link from "next/link";
import RevealText from "@/components/animation/RevealText";
import Image from "next/image";
import teamData from "@/data/team.json";
import AnimatedButton from "@/components/animation/AnimatedButton";

// Team members data
const teamMembers = teamData;

export default function Team() {
  return (
    <div className="mxd-section padding-pre-grid">
      <div className="mxd-container grid-container">
        {/* Block - Team Leaders Start */}
        <div className="mxd-block">
          <div className="mxd-team-cards">
            <div className="container-fluid p-0">
              <div className="row g-0 flex-column-reverse flex-xl-row">
                {/* item cards */}
                <div className="col-12 col-xl-8">
                  <div className="container-fluid p-0">
                    <div className="row g-0">
                      {teamMembers.map((member) => (
                        <div
                          key={member.id}
                          className="col-12 col-md-6 mxd-team-cards__item mxd-grid-item no-margin-desktop animate-card-3"
                        >
                          <div className="mxd-team-cards__media anim-uni-in-up">
                            <div className="mxd-team-cards__photo">
                              <Image
                                alt={member.image.alt}
                                src={member.image.src}
                                width={member.image.width}
                                height={member.image.height}
                              />
                            </div>
                            <div className="mxd-team-cards__socials">
                              {member.socials.map((social, index) => (
                                <AnimatedButton
                                  key={index}
                                  className="btn btn-anim tag tag-default tag-permanent"
                                  as={"a"}
                                  text={social.name}
                                  href={social.url}
                                  target="_blank"
                                ></AnimatedButton>
                              ))}
                            </div>
                          </div>
                          <div className="mxd-team-cards__info">
                            <p className="mxd-team-cards__name t-large t-bright t-caption">
                              {member.name}
                            </p>
                            <p className="mxd-team-cards__position t-small t-medium t-140">
                              {member.position}
                            </p>
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
                {/* item title */}
                <div className="col-12 col-xl-4 mxd-team-cards__item mxd-grid-item no-margin animate-card-3">
                  <div className="mxd-team-cards__h2-block right-block">
                    {/* Section Title Start */}
                    <div className="mxd-section-title pre-grid">
                      <div className="container-fluid p-0">
                        <div className="row g-0">
                          <div className="col-12">
                            <div className="mxd-section-title__title">
                              <RevealText as="h2" className="reveal-type">
                                Creative leaders
                              </RevealText>
                            </div>
                          </div>
                          <div className="col-12">
                            <div className="mxd-section-title__descr">
                              <p className="anim-uni-in-up">
                                Technical experts dedicated to delivering
                                flawless, high-performing digital experiences.
                              </p>
                            </div>
                          </div>
                          <div className="col-12">
                            <div className="mxd-section-title__controls anim-uni-in-up">
                              <AnimatedButton
                                className="btn btn-anim btn-default btn-outline slide-right-up"
                                text="Meet our team"
                                href={`/team`}
                              >
                                <i className="ph-bold ph-arrow-up-right" />
                              </AnimatedButton>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                    {/* Section Title End */}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        {/* Block - Team Leaders End */}
      </div>
    </div>
  );
}
