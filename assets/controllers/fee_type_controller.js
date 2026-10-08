import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.update();
    }

    change() {
        this.update();
    }

    update() {
        const form = this.element.form;

        const amount = form?.querySelector('[data-fee-field="amount"]');
        const rate = form?.querySelector('[data-fee-field="rate"]');

        if (!amount || !rate) {
            return;
        }

        amount.hidden = this.element.value !== 'fee charged_fixed';
        rate.hidden = this.element.value !== 'fee charged_rate';
    }
}