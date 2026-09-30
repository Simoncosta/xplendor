import Link from "next/link";
import Image from "next/image";
import teamMembersData from "@/data/team-members.json";
import AnimatedButton from "@/components/animation/AnimatedButton";

export default function Teammembers() {
  return (
    <div className="mxd-section padding-default">
      <div className="mxd-container grid-container">
        {/* Block - Team Full Start */}
        <div className="mxd-block">
          <div className="mxd-team-cards">
            <div className="container-fluid p-0">
              <div className="row g-0">
                {/* item title */}
                <div className="col-12 col-xl-4 mxd-team-cards__item mxd-grid-item no-margin no-padding-bottom animate-card-3">
                  <div className="mxd-team-cards__h2-block left-block">
                    {/* Section Title Start */}
                    <div className="mxd-section-title pre-grid">
                      <div className="container-fluid p-0">
                        <div className="row g-0">
                          <div className="col-12">
                            <div className="mxd-section-title__title">
                              <h2 className="reveal-type">
                                Dreamers and doers
                              </h2>
                            </div>
                          </div>
                          <div className="col-12">
                            <div className="mxd-section-title__descr">
                              <p className="anim-uni-in-up">
                                Creatives and strategists united by a passion
                                for crafting impactful digital solutions.
                              </p>
                            </div>
                          </div>
                          <div className="col-12">
                            <div className="mxd-section-title__controls anim-uni-in-up">
                              <AnimatedButton
                                className="btn btn-anim btn-default btn-outline slide-right-up"
                                text="View Works"
                                href={`/works-simple`}
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
                {/* item cards */}
                <div className="col-12 col-xl-8">
                  <div className="container-fluid p-0">
                    <div className="row g-0">
                      {teamMembersData.slice(0, 2).map((member) => (
                        <div key={member.id} className={member.layout.classes}>
                          <div className="mxd-team-cards__media anim-uni-in-up">
                            <div className="mxd-team-cards__photo">
                              <Image
                                alt={member.image.alt}
                                width={member.image.width}
                                height={member.image.height}
                                src={member.image.src}
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
                {/* item cards */}
                <div className="col-12 col-xl-8">
                  <div className="container-fluid p-0">
                    <div className="row g-0">
                      {teamMembersData.slice(2, 4).map((member) => (
                        <div key={member.id} className={member.layout.classes}>
                          <div className="mxd-team-cards__media anim-uni-in-up">
                            <div className="mxd-team-cards__photo">
                              <Image
                                alt={member.image.alt}
                                width={member.image.width}
                                height={member.image.height}
                                src={member.image.src}
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
                {/* item career */}
                <div className="col-12 col-xl-4 mxd-team-cards__item mxd-grid-item animate-card-3">
                  <div className="mxd-team-cards__media no-margin overflow-visible anim-uni-in-up">
                    <Link className="mxd-team-cards__career" href={`/contact`}>
                      <div className="career__image">
                        <Image
                          alt="Image"
                          width={800}
                          height={800}
                          src="/img/illustrations/800x800_card-image-04.webp"
                        />
                      </div>
                      <div className="career__caption">
                        <p className="mxd-team-cards__name t-large t-bright t-caption">
                          Maybe you?
                        </p>
                        <p className="mxd-team-cards__position t-small t-medium t-140">
                          Become a part of our team
                        </p>
                      </div>
                    </Link>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        {/* Block - Team Full End */}
      </div>
    </div>
  );
}
