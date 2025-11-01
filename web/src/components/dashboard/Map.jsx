import {
  GoogleMap,
  OverlayViewF,
  OverlayView,
  useLoadScript,
  InfoWindow,
} from "@react-google-maps/api";
import { useMemo, useState } from "react";

const contactItems = [
  {
    lat: 32.411201277163975,
    long: -96.12394824867293,
  },
  // Add more objects here for additional contact items
];

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
export default function ContactMap() {
  const { isLoaded } = useLoadScript({
    googleMapsApiKey: "AIzaSyAAz77U5XQuEME6TpftaMdX0bBelQxXRlM",
  });
  const center = useMemo(
    () => ({ lat: 32.411201277163975, lng: -96.12394824867293 }),
    []
  );

  const CustomMarker = () => {
    return (
      <div className="marker-container">
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

  return (
    <>
      {!isLoaded ? (
        <p>Loading...</p>
      ) : (
        <GoogleMap
          mapContainerStyle={containerStyle}
          center={center}
          zoom={4.5}
          options={option}
        >
          {contactItems.slice(0, 6).map((marker, i) => (
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
        </GoogleMap>
      )}
    </>
  );
}
