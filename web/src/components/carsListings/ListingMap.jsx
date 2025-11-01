import {
  GoogleMap,
  OverlayViewF,
  OverlayView,
  useLoadScript,
  InfoWindow,
} from "@react-google-maps/api";
import { useMemo, useState } from "react";
import { Link } from "react-router-dom";

import { cars } from "@/data/cars";
cars;
const option = {
  zoomControl: true,
  disableDefaultUI: true,
  scrollwheel: false,
  styles: [
    {
      featureType: "all",
      elementType: "geometry.fill",
      stylers: [
        {
          weight: "2.00",
        },
      ],
    },
    {
      featureType: "all",
      elementType: "geometry.stroke",
      stylers: [
        {
          color: "#9c9c9c",
        },
      ],
    },
    {
      featureType: "all",
      elementType: "labels.text",
      stylers: [
        {
          visibility: "on",
        },
      ],
    },
    {
      featureType: "landscape",
      elementType: "all",
      stylers: [
        {
          color: "#f2f2f2",
        },
      ],
    },
    {
      featureType: "landscape",
      elementType: "geometry.fill",
      stylers: [
        {
          color: "#ffffff",
        },
      ],
    },
    {
      featureType: "landscape.man_made",
      elementType: "geometry.fill",
      stylers: [
        {
          color: "#ffffff",
        },
      ],
    },
    {
      featureType: "poi",
      elementType: "all",
      stylers: [
        {
          visibility: "off",
        },
      ],
    },
    {
      featureType: "road",
      elementType: "all",
      stylers: [
        {
          saturation: -100,
        },
        {
          lightness: 45,
        },
      ],
    },
    {
      featureType: "road",
      elementType: "geometry.fill",
      stylers: [
        {
          color: "#eeeeee",
        },
      ],
    },
    {
      featureType: "road",
      elementType: "labels.text.fill",
      stylers: [
        {
          color: "#7b7b7b",
        },
      ],
    },
    {
      featureType: "road",
      elementType: "labels.text.stroke",
      stylers: [
        {
          color: "#ffffff",
        },
      ],
    },
    {
      featureType: "road.highway",
      elementType: "all",
      stylers: [
        {
          visibility: "simplified",
        },
      ],
    },
    {
      featureType: "road.arterial",
      elementType: "labels.icon",
      stylers: [
        {
          visibility: "off",
        },
      ],
    },
    {
      featureType: "transit",
      elementType: "all",
      stylers: [
        {
          visibility: "off",
        },
      ],
    },
    {
      featureType: "water",
      elementType: "all",
      stylers: [
        {
          color: "#46bcec",
        },
        {
          visibility: "on",
        },
      ],
    },
    {
      featureType: "water",
      elementType: "geometry.fill",
      stylers: [
        {
          color: "#c8d7d4",
        },
      ],
    },
    {
      featureType: "water",
      elementType: "labels.text.fill",
      stylers: [
        {
          color: "#070707",
        },
      ],
    },
    {
      featureType: "water",
      elementType: "labels.text.stroke",
      stylers: [
        {
          color: "#ffffff",
        },
      ],
    },
  ],
};
const containerStyle = {
  width: "100%",
  height: "100%",
};
export default function ListingMap() {
  const [getLocation, setLocation] = useState(null);

  const { isLoaded } = useLoadScript({
    googleMapsApiKey: "AIzaSyAAz77U5XQuEME6TpftaMdX0bBelQxXRlM",
  });
  const center = useMemo(
    () => ({ lat: 32.411201277163975, lng: -96.12394824867293 }),
    []
  );
  const CustomMarker = ({ elm }) => {
    return (
      <div className="marker-container" onClick={() => setLocation(elm)}>
        <div className="marker-card">
          <div className="front face">
            <div />
          </div>
          <div className="back face">
            <div />
          </div>
          <div className="marker-arrow" />
        </div>
      </div>
    );
  };

  // close handler
  const closeCardHandler = () => {
    setLocation(null);
  };

  return (
    <>
      {!isLoaded ? (
        <p>Loading...</p>
      ) : (
        <GoogleMap
          mapContainerStyle={containerStyle}
          center={center}
          zoom={4}
          options={option}
        >
          {cars.slice(0, 6).map((marker, i) => (
            <OverlayViewF
              key={i}
              position={{
                lat: marker.lat,
                lng: marker.long,
              }}
              mapPaneName={OverlayView.OVERLAY_MOUSE_TARGET}
            >
              <CustomMarker elm={marker} />
            </OverlayViewF>
          ))}
          {getLocation !== null && (
            <InfoWindow
              position={{
                lat: getLocation.lat,
                lng: getLocation.long,
              }}
              onCloseClick={closeCardHandler}
            >
              <div className="map-listing-item">
                <div className="inner-box">
                  <div className="image-box">
                    <figure className="image">
                      <img src={getLocation.imgSrc} alt="" />
                    </figure>
                  </div>
                  <div className="content">
                    <p className="text-color-3 font">{getLocation.type}</p>
                    <h5>
                      <Link to={`/property-detail-v1/${getLocation.id}`}>
                        {getLocation.title}
                      </Link>
                    </h5>
                    <div className="flex flex-wrap gap-8">
                      <p className="location">
                        <i className="icon-autodeal-km1" />
                        {getLocation.km.toLocaleString()} kms
                      </p>
                      <p className="location">
                        <i className="icon-autodeal-diesel" />
                        {getLocation.fuelType}
                      </p>
                      <p className="location">
                        <i className="icon-autodeal-automatic" />
                        {getLocation.transmission}
                      </p>
                    </div>
                    <h3>
                      <a>${getLocation.price.toLocaleString()}</a>
                    </h3>
                  </div>
                </div>
              </div>
            </InfoWindow>
          )}
        </GoogleMap>
      )}
    </>
  );
}
