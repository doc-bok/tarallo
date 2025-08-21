/**
 * Error popup message display
 */
export function showErrorPopup(errorMsg, popupID) {
        showPopup(errorMsg, popupID, "#d44");
}

/**
 * Info popup message display
 */
export function showInfoPopup(infoMessage, popupID) {
    showPopup(infoMessage, popupID, "#2aa");
}

/**
 * Show a Popup
 */
export function showPopup(msg, popupID, color) {
    if (typeof popupID !== 'undefined') {
        // fill the error displaying element
        const popupElem = document.getElementById(popupID);
        popupElem.innerHTML = msg;
        popupElem.style.backgroundColor = color;

        // reflow popup animation
        popupElem.style.animation = 'none';
        popupElem.offsetHeight; /* trigger reflow */
        popupElem.style.animation = null;
        popupElem.classList.add("popup-animate");
        popupElem.style.display = "block";

        popupElem.onclick = () => popupElem.style.display = "none";
    }
}