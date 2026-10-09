import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.createTabs();
        this.update();
    }

    disconnect() {
        this.tabs?.remove();
    }

    change() {
        this.update();
    }

    select(event) {
        event.preventDefault();

        this.selectType(event.currentTarget.dataset.transferType);
    }

    navigate(event) {
        const tabs = [...(this.tabs?.querySelectorAll('[data-transfer-type]') ?? [])];

        if (tabs.length === 0) {
            return;
        }

        const currentIndex = tabs.indexOf(event.currentTarget);
        let nextIndex = currentIndex;

        switch (event.key) {
            case 'ArrowRight':
            case 'ArrowDown':
                nextIndex = (currentIndex + 1) % tabs.length;
                break;
            case 'ArrowLeft':
            case 'ArrowUp':
                nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
                break;
            case 'Home':
                nextIndex = 0;
                break;
            case 'End':
                nextIndex = tabs.length - 1;
                break;
            default:
                return;
        }

        event.preventDefault();
        tabs[nextIndex].focus();
        this.selectType(tabs[nextIndex].dataset.transferType);
    }

    selectType(type) {
        this.element.value = type;
        this.element.dispatchEvent(new Event('change', { bubbles: true }));
    }

    createTabs() {
        const row = this.element.closest('[data-transfer-type-selector]');

        if (!row || this.tabs) {
            return;
        }

        this.tabs = document.createElement('nav');
        this.tabs.className = 'transfer-type-tabs';
        this.tabs.setAttribute('aria-label', 'Transfer type');
        this.tabs.setAttribute('role', 'tablist');

        for (const [value, label] of [
            ['deposit', 'Deposit'],
            ['withdrawal', 'Withdrawal'],
            ['transfer', 'Transfer'],
        ]) {
            const tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'transfer-type-tab';
            tab.dataset.transferType = value;
            tab.setAttribute('role', 'tab');
            tab.addEventListener('click', (event) => this.select(event));
            tab.addEventListener('keydown', (event) => this.navigate(event));
            tab.textContent = label;
            this.tabs.appendChild(tab);
        }

        row.hidden = true;
        row.before(this.tabs);
    }

    update() {
        const form = this.element.form;

        if (!form) {
            return;
        }

        this.tabs?.querySelectorAll('[data-transfer-type]').forEach((tab) => {
            const active = tab.dataset.transferType === this.element.value;

            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
        });

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
