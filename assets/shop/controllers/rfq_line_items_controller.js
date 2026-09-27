import { Controller } from '@hotwired/stimulus';

// Standard Symfony CollectionType add/remove pattern. The container div
// (data-controller="rfq-line-items") is rendered by Symfony's default
// form theme with a data-prototype attribute (because RfqRequestType's
// lineItems field has 'prototype' => true); each new row's __name__
// placeholder is swapped for an incrementing index.
export default class extends Controller {
    static targets = ['container', 'addButton'];

    connect() {
        this.index = this.containerTarget.children.length;
    }

    addItem() {
        const prototype = this.containerTarget.dataset.prototype;
        const newRow = prototype.replace(/__name__/g, this.index);

        this.containerTarget.insertAdjacentHTML('beforeend', newRow);
        this.index++;
    }

    removeItem(event) {
        event.preventDefault();
        const row = event.target.closest('[data-rfq-line-items-target="row"]');
        if (row) {
            row.remove();
        }
    }
}
