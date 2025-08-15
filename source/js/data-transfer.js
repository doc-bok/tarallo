/**
 * Data transfer helper.
 */
export class DataTransferShim {
    /**
     * Construction
     */
    constructor() {
        this.dropEffect = 'move';
        this.effectAllowed = 'all';
        this._data = {};
    }

    /**
     * Retrieve the types.
     * @returns {string[]}
     */
    get types() {
        return Object.keys(this._data);
    }

    /**
     * Clear data for the type.
     * @param type
     */
    clearData(type) {
        if (type) {
            delete this._data[type.toLowerCase()];
        } else {
            this._data = {};
        }
    }

    /**
     * Get the data for the specified type.
     * @param type
     * @returns {*|string}
     */
    getData(type) {
        const lc = type.toLowerCase();
        return (this._data[lc] ?? (lc === 'text' ? this._data['text/plain'] : '')) || '';
    }

    /**
     * Set the data for the specified type.
     * @param type
     * @param value
     */
    setData(type, value) {
        this._data[type.toLowerCase()] = value;
    }

    /**
     * Set the position of a dragged image.
     * @param img The image to drag.
     * @param offsetX The x-position offset.
     * @param offsetY The y-position offset.
     */
    setDragImage(img, offsetX, offsetY) {
        const inst = DragDropTouch.instance;
        inst._imgCustom = img;
        inst._imgOffset = { x: offsetX, y: offsetY };
    }
}
