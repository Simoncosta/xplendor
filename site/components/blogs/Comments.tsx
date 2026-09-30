import Image from "next/image";
import CommentForm from "./CommentForm";
import AnimatedButton from "../animation/AnimatedButton";

export default function Comments() {
  return (
    <div className="mxd-article-comments">
      <div className="mxd-article-comments__container">
        <h3>3 Comments</h3>
        <div className="mxd-article-comments__list">
          <ul className="mxd-comment__main">
            <li className="mxd-comment">
              <div className="mxd-comment__container">
                <div className="mxd-comment__avatar">
                  <Image
                    alt="Comment Avatar"
                    src="/img/avatars/300x300_ava-08.webp"
                    width={300}
                    height={300}
                  />
                </div>
                <div className="mxd-comment__content">
                  <div className="mxd-comment__info">
                    <p className="mxd-comment__date t-small t-140">
                      28 January, 2025
                    </p>
                    <h6 className="mxd-comment__name">
                      <a href="#">Lea Tomato</a>
                    </h6>
                  </div>
                  <p className="t-small">
                    AI-generated art is impressive, but I feel like it takes
                    away the human touch. Illustration is about personal
                    expression, and no algorithm can truly replace that.
                  </p>
                  <div className="mxd-comment__reply">
                    <AnimatedButton
                      className="btn btn-anim btn-default btn-small btn-outline slide-right-up"
                      as={"a"}
                      position={"next"}
                      text="Reply"
                      href="#"
                    >
                      <i className="ph ph-arrow-up-right" />
                    </AnimatedButton>
                  </div>
                </div>
              </div>
              <ul className="mxd-comment__children">
                <li className="mxd-comment">
                  <div className="mxd-comment__container">
                    <div className="mxd-comment__avatar">
                      <Image
                        alt="Comment Avatar"
                        src="/img/avatars/300x300_ava-02.webp"
                        width={300}
                        height={300}
                      />
                    </div>
                    <div className="mxd-comment__content">
                      <div className="mxd-comment__info">
                        <p className="mxd-comment__date t-small t-140">
                          28 January, 2025
                        </p>
                        <h6 className="mxd-comment__name">
                          <a href="#">Patrick Pineapple</a>
                        </h6>
                      </div>
                      <p className="t-small">
                        I get your point, Lea, but I think AI is more of a tool
                        than a replacement. It speeds up workflows and helps
                        artists experiment with new ideas they might not have
                        considered before.
                      </p>
                      <div className="mxd-comment__reply">
                        <AnimatedButton
                          position={"next"}
                          as={"a"}
                          className="btn btn-anim btn-default btn-small btn-outline slide-right-up"
                          text="Reply"
                          href="#"
                        >
                          <i className="ph ph-arrow-up-right" />
                        </AnimatedButton>
                      </div>
                    </div>
                  </div>
                </li>
              </ul>
            </li>
            <li className="mxd-comment">
              <div className="mxd-comment__container">
                <div className="mxd-comment__avatar">
                  <Image
                    alt="Comment Avatar"
                    src="/img/avatars/300x300_ava-09.webp"
                    width={300}
                    height={300}
                  />
                </div>
                <div className="mxd-comment__content">
                  <div className="mxd-comment__info">
                    <p className="mxd-comment__date t-small t-140">
                      01 February, 2025
                    </p>
                    <h6 className="mxd-comment__name">
                      <a href="#">Mary Cucumber</a>
                    </h6>
                  </div>
                  <p className="t-small">
                    As a designer, I&apos;ve started using AI in my work, and
                    it&apos;s actually helped me focus more on creativity. It
                    handles the repetitive tasks, so I can put more time into
                    refining concepts and storytelling.
                  </p>
                  <div className="mxd-comment__reply">
                    <AnimatedButton
                      position={"next"}
                      as={"a"}
                      className="btn btn-anim btn-default btn-small btn-outline slide-right-up"
                      text="Reply"
                      href="#"
                    >
                      <i className="ph ph-arrow-up-right" />
                    </AnimatedButton>
                  </div>
                </div>
              </div>
            </li>
          </ul>
        </div>
      </div>
      <div className="mxd-article-comments__respond">
        <h3>What do you think?</h3>
        <p>
          Please leave a reply. Your email address will not be published.
          Required fields are marked *
        </p>
        <div className="comments-respond__form">
          <CommentForm />
        </div>
      </div>
    </div>
  );
}
