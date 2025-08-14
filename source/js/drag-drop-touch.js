import { DataTransferShim } from './data-transfer.js';

/**
 * Class to handle dragging and dropping elements.
 */
export class DragDropTouch {
    /** @type {DragDropTouch} */
    static instance = new DragDropTouch();

    // Constants
    static THRESHOLD = 5;
    static OPACITY = 0.5;
    static CONTEXTMENU_DELAY = 900;
    static PRESS_HOLD_MODE = true;
    static PRESS_HOLD_AWAIT = 400;
    static PRESS_HOLD_MARGIN = 25;
    static PRESS_HOLD_THRESHOLD = 0;
    static REMOVE_ATTRS = ['id', 'class', 'style', 'draggable'];
    static KBD_PROPS = ['altKey', 'ctrlKey', 'metaKey', 'shiftKey'];
    static PT_PROPS = ['pageX', 'pageY', 'clientX', 'clientY', 'screenX', 'screenY', 'offsetX', 'offsetY'];

    /**
     * Construction
     */
    constructor() {
        if (DragDropTouch._created) {
            throw new Error('DragDropTouch already instantiated');
        }
        DragDropTouch._created = true;

        this._reset();
        this._initEventListeners();
    }

    /**
     * Initialise the event listeners.
     * @private
     */
    _initEventListeners() {
        const options = { passive: false };
        document.addEventListener('touchstart', e => this._touchstart(e), options);
        document.addEventListener('touchmove', e => this._touchmove(e), options);
        document.addEventListener('touchend', e => this._touchend(e), options);
        document.addEventListener('touchcancel', e => this._touchend(e), options);
    }

    /**
     * Touch start
     */
    _touchstart(e) {
        if (!this._shouldHandle(e)) return;

        this._reset();
        const src = this._closestDraggable(e.target);
        if (!src) return;

        if (!this._dispatchEvent(e, 'mousemove', e.target) &&
            !this._dispatchEvent(e, 'mousedown', e.target)) {

            this._dragSource = src;
            this._ptDown = this._getPoint(e);
            this._lastTouch = e;

            setTimeout(() => {
                if (this._dragSource === src && !this._img &&
                    this._dispatchEvent(e, 'contextmenu', src)) {
                    this._reset();
                }
            }, DragDropTouch.CONTEXTMENU_DELAY);

            if (DragDropTouch.PRESS_HOLD_MODE) {
                this._pressHoldInterval = setTimeout(() => {
                    this._isDragEnabled = true;
                    this._touchmove(e);
                }, DragDropTouch.PRESS_HOLD_AWAIT);
            }
        }
    }

    /**
     * Touch move
     * @param e
     * @private
     */
    _touchmove(e) {
        if (this._shouldCancelPressHoldMove(e)) {
            this._reset();
            return;
        }

        if (this._shouldHandleMove(e) || this._shouldHandlePressHoldMove(e)) {
            const target = this._getTarget(e);

            // Start dragging
            if (this._dragSource && !this._img && this._shouldStartDragging(e)) {
                if (this._dispatchEvent(this._lastTouch, 'dragstart', this._dragSource)) {
                    this._dragSource = null;
                    return;
                }
                this._createImage(e);
                this._dispatchEvent(e, 'dragenter', target);
            }

            // Continue dragging
            if (this._img) {
                this._lastTouch = e;
                e.preventDefault(); // prevent scrolling
                this._dispatchEvent(e, 'drag', this._dragSource);
                if (target !== this._lastTarget) {
                    this._dispatchEvent(this._lastTouch, 'dragleave', this._lastTarget);
                    this._dispatchEvent(e, 'dragenter', target);
                    this._lastTarget = target;
                }
                this._moveImage(e);
                this._isDropZone = this._dispatchEvent(e, 'dragover', target);
            }
        }
    }

    /**
     * Touch end
     * @param e
     * @private
     */
    _touchend(e) {
        if (!this._shouldHandle(e)) return;

        if (this._dispatchEvent(this._lastTouch, 'mouseup', e.target)) {
            e.preventDefault();
            return;
        }

        if (!this._img) {
            this._dragSource = null;
            this._lastClick = Date.now();
        }

        this._destroyImage();
        if (this._dragSource) {
            if (!e.type.includes('cancel') && this._isDropZone) {
                this._dispatchEvent(this._lastTouch, 'drop', this._lastTarget);
            }
            this._dispatchEvent(this._lastTouch, 'dragend', this._dragSource);
            this._reset();
        }
    }

    /**
     * Utility methods
     */
    _shouldHandle(e) {
        return e && !e.defaultPrevented && e.touches?.length < 2;
    }
    _shouldHandleMove(e) {
        return !DragDropTouch.PRESS_HOLD_MODE && this._shouldHandle(e);
    }
    _shouldHandlePressHoldMove(e) {
        return DragDropTouch.PRESS_HOLD_MODE && this._isDragEnabled && e.touches?.length;
    }
    _shouldCancelPressHoldMove(e) {
        return DragDropTouch.PRESS_HOLD_MODE && !this._isDragEnabled &&
            this._getDelta(e) > DragDropTouch.PRESS_HOLD_MARGIN;
    }
    _shouldStartDragging(e) {
        const delta = this._getDelta(e);
        return delta > DragDropTouch.THRESHOLD ||
            (DragDropTouch.PRESS_HOLD_MODE && delta >= DragDropTouch.PRESS_HOLD_THRESHOLD);
    }

    /**
     * Reset
     * @private
     */
    _reset() {
        this._destroyImage();
        this._dragSource = null;
        this._lastTouch = null;
        this._lastTarget = null;
        this._ptDown = null;
        this._isDragEnabled = false;
        this._isDropZone = false;
        this._dataTransfer = new DataTransferShim();
        clearInterval(this._pressHoldInterval);
    }

    /**
     * Get the point
     * @param e
     * @param page
     * @returns {{x: *, y: *}}
     * @private
     */
    _getPoint(e, page = false) {
        const t = e.touches ? e.touches[0] : e;
        return { x: page ? t.pageX : t.clientX, y: page ? t.pageY : t.clientY };
    }

    /**
     * Get the delta
     * @param e
     * @returns {number}
     * @private
     */
    _getDelta(e) {
        if (DragDropTouch.PRESS_HOLD_MODE && !this._ptDown) return 0;
        const p = this._getPoint(e);
        return Math.abs(p.x - this._ptDown.x) + Math.abs(p.y - this._ptDown.y);
    }

    /**
     * Get the target
     * @param e
     * @returns {Element}
     * @private
     */
    _getTarget(e) {
        let pt = this._getPoint(e);
        let el = document.elementFromPoint(pt.x, pt.y);
        while (el && getComputedStyle(el).pointerEvents === 'none') {
            el = el.parentElement;
        }
        return el;
    }

    /**
     * Create an image
     * @param e
     * @private
     */
    _createImage(e) {
        if (this._img) this._destroyImage();
        const src = this._imgCustom || this._dragSource;
        this._img = src.cloneNode(true);
        this._copyStyle(src, this._img);
        this._img.style.top = this._img.style.left = '-9999px';

        if (!this._imgCustom) {
            let rc = src.getBoundingClientRect();
            let pt = this._getPoint(e);
            this._imgOffset = { x: pt.x - rc.left, y: pt.y - rc.top };
            this._img.style.opacity = DragDropTouch.OPACITY.toString();
        }
        this._moveImage(e);
        document.body.appendChild(this._img);
    }

    /**
     * Destroy and image
     * @private
     */
    _destroyImage() {
        if (this._img?.parentElement) {
            this._img.parentElement.removeChild(this._img);
        }
        this._img = null;
        this._imgCustom = null;
    }

    /**
     * Move an image
     * @param e
     * @private
     */
    _moveImage(e) {
        requestAnimationFrame(() => {
            if (!this._img) return;
            const pt = this._getPoint(e, true);
            Object.assign(this._img.style, {
                position: 'absolute',
                pointerEvents: 'none',
                zIndex: '999999',
                left: `${Math.round(pt.x - this._imgOffset.x)}px`,
                top: `${Math.round(pt.y - this._imgOffset.y)}px`
            });
        });
    }

    /**
     * Copy the style
     * @param src
     * @param dst
     * @private
     */
    _copyStyle(src, dst) {
        DragDropTouch.REMOVE_ATTRS.forEach(attr => dst.removeAttribute(attr));
        if (src instanceof HTMLCanvasElement) {
            dst.width = src.width;
            dst.height = src.height;
            dst.getContext('2d').drawImage(src, 0, 0);
        }
        let cs = getComputedStyle(src);
        for (let key of cs) {
            if (!key.includes('transition')) {
                dst.style[key] = cs[key];
            }
        }
        dst.style.pointerEvents = 'none';
        Array.from(src.children).forEach((child, i) => {
            this._copyStyle(child, dst.children[i]);
        });
    }

    /**
     * Dispatch an event
     * @param e
     * @param type
     * @param target
     * @returns {boolean}
     * @private
     */
    _dispatchEvent(e, type, target) {
        if (!e || !target) return false;
        const touch = e.touches?.[0] || e;
        const evt = new DragEvent(type, {
            bubbles: true,
            cancelable: true,
            clientX: touch.clientX,
            clientY: touch.clientY,
            pageX: touch.pageX,
            pageY: touch.pageY,
            screenX: touch.screenX,
            screenY: touch.screenY
        });
        Object.assign(evt, {
            button: 0,
            which: 1,
            buttons: 1,
            dataTransfer: this._dataTransfer
        });
        DragDropTouch.KBD_PROPS.forEach(p => evt[p] = e[p]);
        DragDropTouch.PT_PROPS.forEach(p => evt[p] = touch[p]);
        target.dispatchEvent(evt);
        return evt.defaultPrevented;
    }

    /**
     * Find the closest draggable
     * @param el
     * @returns {{draggable}|*|null}
     * @private
     */
    _closestDraggable(el) {
        for (; el; el = el.parentElement) {
            if (el.draggable) return el;
        }
        return null;
    }
}
