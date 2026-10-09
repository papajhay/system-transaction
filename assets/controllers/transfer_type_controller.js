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

        if (!form) {
            return;
        }

        const accountFields = form.querySelectorAll('[data-transfer-account]');
        const visibleFields = this.visibleFields(this.element.value);

        accountFields.forEach((row) => {
            const fieldName = row.dataset.transferAccount;
            const visible = visibleFields.includes(fieldName);

            row.hidden = !visible;

            row.querySelectorAll('input, select, textarea').forEach((field) => {
                field.disabled = !visible;
            });
        });
    }

    visibleFields(type) {
        switch (type) {
            case 'transfer':
                return ['from_account_number', 'to_account_number'];
            case 'deposit':
            case 'withdrawal':
                return ['account_number'];
            default:
                return [];
        }
    }
}
