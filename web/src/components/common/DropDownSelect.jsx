import { useEffect, useRef, useState } from "react";
const optionsDefault = ["Newest", "Oldest", "3 days"];
export default function DropdownSelect({
  onChange = (elm) => {},
  options = optionsDefault,
  defaultOption,
  selectedValue,
  addtionalParentClass = "",
}) {
  const selectRef = useRef();
  const optionsRef = useRef();
  const [selected, setSelected] = useState("");
  const toggleDropdown = () => {
    selectRef.current.classList.toggle("open");
  };
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (!selectRef.current.contains(event.target)) {
        selectRef.current.classList.remove("open");
      }
    };

    // Add event listeners to each dropdown element

    // Add a global click event listener to detect outside clicks
    document.addEventListener("click", handleClickOutside);

    // Cleanup event listeners on component unmount
    return () => {
      document.removeEventListener("click", handleClickOutside);
    };
  }, []);

  useEffect(() => {
    // Function to handle clicks outside the select or options
    const handleClickOutside = (event) => {
      if (
        selectRef.current &&
        selectRef.current.contains(event.target) &&
        optionsRef.current &&
        !optionsRef.current.contains(event.target)
      ) {
        // Close the options if clicked outside
        toggleDropdown();
      }
    };

    // Add event listener on mount
    document.addEventListener("click", handleClickOutside);

    // Cleanup event listener on unmount
    return () => {
      document.removeEventListener("click", handleClickOutside);
    };
  }, []);

  return (
    <>
      <div className={`nice-select ${addtionalParentClass}`} ref={selectRef}>
        <span className="current">
          {selectedValue || selected || defaultOption || options[0]}
        </span>
        <ul className="list" ref={optionsRef}>
          {options.map((elm, i) => (
            <li
              key={i}
              onClick={() => {
                setSelected(elm);
                onChange(elm);
                toggleDropdown();
              }}
              className={`option ${
                !selectedValue
                  ? selected == elm
                    ? "selected"
                    : ""
                  : selectedValue == elm
                  ? "selected"
                  : ""
              }  text text-1`}
            >
              {elm}
            </li>
          ))}
        </ul>
      </div>
    </>
  );
}
