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
 * Attach an event to all elements matching a selector.
 * @param parentElem The parent element.
 * @param selector The selector to use.
 * @param eventName The event name to attach to.
 * @param handler The callback when the event is fired.
 */
export function setEventBySelector(parentElem, selector, eventName, handler) {
    const elem = parentElem.querySelector(selector);
    elem[eventName] = (event) => handler(elem, event);
}

/**
 * Attach an `onclick` event to all elements matching a selector.
 * @param parentElem The parent element.
 * @param selector The selector to use.
 * @param handler The callback when the event is fired.
 */
export function setOnClickEventBySelector(parentElem, selector, handler)  {
    return setEventBySelector(parentElem, selector, 'onclick', handler);
}

/**
 * Attach an `onkeydown` event that only triggers when the user presses `enter`.
 * @param parentElem The parent element.
 * @param selector The selector to use.
 * @param handler The callback when the event is fired.
 */
export function setOnEnterEventBySelector(parentElem, selector, handler)  {
    return setEventBySelector(
        parentElem,
        selector,
        'onkeydown',
        (elem, keydownEvent) => {
            if (keydownEvent.keyCode === 13) {
                keydownEvent.preventDefault();
                handler();
            }
        });
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
 * Close a dialogue
 */
export function closeDialog(containerElemId) {
    const openCardElem = document.getElementById(containerElemId);
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
export function jsonFileToObj(file) {
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

/**
 * Select all text in an element.
 * @param id The ID of the element to select the text.
 */
export function selectAllInnerText(id){
    var sel, range;
    var el = document.getElementById(id); //get element id
    if (window.getSelection && document.createRange) { //Browser compatibility
        sel = window.getSelection();
        if(sel.toString() === ''){ //no text selection
            window.setTimeout(function(){
                range = document.createRange(); //range object
                range.selectNodeContents(el); //sets Range
                sel.removeAllRanges(); //remove all ranges from selection
                sel.addRange(range);//add Range to a Selection.
            },1);
        }
    }else if (document.selection) { //older ie
        sel = document.selection.createRange();
        if(sel.text === ''){ //no text selection
            range = document.body.createTextRange();//Creates TextRange object
            range.moveToElementText(el);//sets Range
            range.select(); //make selection.
        }
    }
}