import React, { useState } from "react";

export default function MyProfile() {
  const [preview, setPreview] = useState(
    "/assets/images/dashboard/avt-profile.jpg"
  );

  const handleImageUpload = (event) => {
    const file = event.target.files[0];
    if (file) {
      const reader = new FileReader();
      reader.onloadend = () => {
        setPreview(reader.result); // Set the preview to the uploaded image
      };
      reader.readAsDataURL(file);
    }
  };
  return (
    <div className="container">
      <div className="row">
        <div className="col-md-12">
          <div className="content-area">
            <main id="main" className="main-content">
              <div className="tfcl-dashboard">
                <h1 className="admin-title mb-3">Edit profile</h1>
                <div className="tfcl-add-listing profile-inner">
                  <h3>Avatar</h3>
                  <div className="tfcl_choose_avatar">
                    <div className="avatar">
                      <div className="form-group">
                        <img
                          loading="lazy"
                          decoding="async"
                          width={158}
                          height={138}
                          id="tfcl_avatar_thumbnail"
                          alt="avatar"
                          src={preview}
                        />
                      </div>
                      <div className="choose-box">
                        <label>Upload a new Avatar</label>
                        <div className="form-group relative pb-2 pt-2">
                          <input
                            type="file"
                            className="form-control ip-file"
                            accept="image/*"
                            onChange={handleImageUpload}
                          />
                          <label htmlFor="tfcl_avatar">
                            <button type="button">Choose file</button>
                          </label>
                        </div>
                        <span className="notify-avatar">
                          PNG, JPG, SVG dimension (400 * 400) max file not more
                          then size 4 mb
                        </span>
                      </div>
                    </div>
                  </div>
                  <h3 className="form-title">Information</h3>
                  <div className="form-group">
                    <label htmlFor="listing_title">Full name</label>
                    <input
                      type="text"
                      className="form-control"
                      name="listing_title"
                      placeholder="Your name"
                      defaultValue=""
                    />
                  </div>
                  <div className="form-group">
                    <label htmlFor="listing_title">Description</label>
                    <textarea
                      name=""
                      id=""
                      placeholder="Your description"
                      defaultValue={""}
                    />
                  </div>
                  <div className="form-group-4">
                    <div className="form-group">
                      <label htmlFor="listing_title">Your company</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        defaultValue=""
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">Job</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        defaultValue=""
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">Email address</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        defaultValue=""
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">Your phone</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        defaultValue=""
                      />
                    </div>
                  </div>
                  <div className="form-group">
                    <label htmlFor="listing_title">Location</label>
                    <input
                      type="text"
                      className="form-control"
                      name="listing_title"
                      defaultValue=""
                    />
                  </div>
                  <div id="map-single">
                    <iframe
                      className="map-single"
                      src="https://www.google.com/maps/embed?pb=!1m14!1m12!1m3!1d7302.453092836291!2d90.47477022812872!3d23.77494577893369!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!5e0!3m2!1svi!2s!4v1627293157601!5m2!1svi!2s"
                      allowFullScreen=""
                      loading="lazy"
                      width={"100%"}
                    ></iframe>
                  </div>
                  <div className="form-group">
                    <label htmlFor="listing_title">Socials</label>
                    <input
                      type="text"
                      className="form-control"
                      name="listing_title"
                      defaultValue=""
                    />
                  </div>
                  <div className="group-button-submit left mb-3">
                    <button className="pre-btn">Save &amp; Update</button>
                  </div>
                  <h3>Change passwords</h3>
                  <div className="tfcl-add-listing profile-password">
                    <div className="form-group">
                      <label htmlFor="listing_title">Old password</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        placeholder="Old password"
                        defaultValue=""
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor="listing_title">New password</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        placeholder="New password"
                        defaultValue=""
                      />
                    </div>
                    <ul className="list-check-req mb-3">
                      <li className="check">
                        <span>One number</span>
                      </li>
                      <li>
                        <span>One lowercase character</span>
                      </li>
                      <li>
                        <span>One uppercase character</span>
                      </li>
                      <li>
                        <span>8 characters minimum</span>
                      </li>
                    </ul>
                    <div className="form-group">
                      <label htmlFor="listing_title">Confirm password</label>
                      <input
                        type="text"
                        className="form-control"
                        name="listing_title"
                        placeholder="Confirm password"
                        defaultValue=""
                      />
                    </div>
                    <div className="group-button-submit left mb-0">
                      <button className="pre-btn">Change passwords</button>
                    </div>
                  </div>
                </div>
              </div>
            </main>
          </div>
        </div>
      </div>
    </div>
  );
}
