/**
 * Check if we are on a mobile device
 */
export function isMobileDevice() {
    return /Mobi/i.test(window.navigator.userAgent);
}

export function GetQueryStringParams() {
    const queryString = window.location.search;
    return new URLSearchParams(queryString);
}

/**
 * Attach an event to all elements matching a selector
 */
export function setEventBySelector(parentElem, selector, eventName, handler) {
    const elem = parentElem.querySelector(selector);
    elem[eventName] = (event) => handler(elem, event);
}

/**
 * Helper to get the content element
 */
export function GetContentElement() {
    return document.getElementById("content");
}

/**
 * returns a page element created from the tag template with the specified id
 * replacing all $args with the <args>  replacements array
 */
export function loadTemplate(templateName, args) {
    // replace args
    const templateHtml = document.getElementById(templateName).innerHTML;
    const html = replaceHtmlTemplateArgs(templateHtml, args);

    // convert back to an element
    const template = document.createElement('template');
    template.innerHTML = html;
    const result = template.content.children;
    if (result.length === 1)
        return result[0];
    return result;
}


/**
 * Select file(s).
 * @param {String} contentType The content type of files you wish to select. For instance, use "image/*" to select all types of images (other examples : "image/png", or "video/*, .pdf, .zip").
 * @param multipleFiles
 * @param onSelected
 */
export function selectFileDialog(contentType, multipleFiles, onSelected) {
    return new Promise(() => {
        let input = document.createElement('input');
        input.type = 'file';
        input.multiple = multipleFiles;
        input.accept = contentType;

        input.onchange = () => {
            let files = Array.from(input.files);
            if (multipleFiles) {
                onSelected(files);
            } else {
                onSelected(files[0]);
            }
        };

        input.click();
    });
}

/**
 * Blur on enter
 */
export function blurOnEnter(keydownEvent) {
    if (keydownEvent.keyCode === 13) {
        keydownEvent.preventDefault();
        keydownEvent.currentTarget.blur();
    }
}

/**
 * Add a class to all nodes
 */
export function AddClassToAll(parentNode, cssSelector, className) {
    const nodes = parentNode.querySelectorAll(cssSelector);
    for (let i = 0; i < nodes.length; i++) {
        nodes[i].classList.add(className);
    }
}

/**
 * Remove a class from all nodes
 */
export function RemoveClassFromAll(parentNode, cssSelector, className) {
    const nodes = parentNode.querySelectorAll(cssSelector);
    for (let i = 0; i < nodes.length; i++) {
        nodes[i].classList.remove(className);
    }
}

/**
 * Close a dialogue
 */
export function CloseDialog() {
    const openCardElem = document.getElementById("dialog-container");
    openCardElem.remove();
}

/**
 * Convert a file to Base 64 format
 */
export function fileToBase64(file) {
    return new Promise(resolve => {
        // read the file content as a base64 string
        const reader = new FileReader();
        reader.onload = readerEvent => {
            const base64String = readerEvent.target.result;
            const base64Start = base64String.indexOf("base64,") + 7;
            resolve(base64String.substring(base64Start));
        }
        reader.readAsDataURL(file);
    });
}

/**
 * Replace the HTML template arguments
 */
export function replaceHtmlTemplateArgs(templateHtml, args) {
    // replace args
    let html = templateHtml;
    for (const argName in args) {
        if (Object.hasOwn(args, argName)) {
            html = html.replaceAll("$" + argName + ":optional", args[argName]);
            html = html.replaceAll("$" + argName, args[argName]);
        }
    }
    // remove unused optional args
    html = html.replaceAll(/\$\w*:optional/g, "");

    return html;
}

/**
 * Convert a Json file to an object
 */
export function JsonFileToObj(file) {
    return new Promise(resolve => {
        // read the file content as a base64 string
        const reader = new FileReader();
        reader.onload = readerEvent => {
            const jsonString = readerEvent.target.result;
            const jsonObj = JSON.parse(jsonString);
            resolve(jsonObj);
        }
        reader.readAsText(file);
    });
}