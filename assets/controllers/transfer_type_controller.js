import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.createTabs();
        this.update();
        this.form?.addEventListener('submit', this.submitHandler = (event) => this.validateBeforeSubmit(event));
        this.balanceInputHandler = () => this.validateBalance();
        this.form?.querySelectorAll('[name$="[amount]"], [name$="[feeType]"], [name$="[feeAmount]"], [name$="[feeRate]"]').forEach((field) => {
            field.addEventListener('input', this.balanceInputHandler);
            field.addEventListener('change', this.balanceInputHandler);
            field.addEventListener('blur', this.balanceInputHandler);
        });
    }

    disconnect() {
        this.tabs?.remove();
        this.form?.removeEventListener('submit', this.submitHandler);
        this.form?.querySelectorAll('[name$="[amount]"], [name$="[feeType]"], [name$="[feeAmount]"], [name$="[feeRate]"]').forEach((field) => {
            field.removeEventListener('input', this.balanceInputHandler);
            field.removeEventListener('change', this.balanceInputHandler);
            field.removeEventListener('blur', this.balanceInputHandler);
        });
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
        this.form = form;

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

    async validateField(event) {
        const field = event.currentTarget;
        const name = field.name.replace(/^.*\[/, '').replace(/\]$/, '');
        if (field.disabled || !this.visibleFields(this.element.value).includes(name)) {
            return;
        }
        await this.validateAccount(field, this.roleFor(field));
    }

    async validateBeforeSubmit(event) {
        event.preventDefault();
        const fields = [...this.form.querySelectorAll('[data-transfer-account]')]
            .filter((row) => !row.hidden)
            .map((row) => row.querySelector('select, input'))
            .filter((field) => field && !field.disabled);

        for (const field of fields) {
            if (!(await this.validateAccount(field, this.roleFor(field)))) {
                return;
            }
        }
        if (!(await this.validateBalance(true))) {
            return;
        }
        this.form.submit();
    }

    async validateBalance(force = false) {
        const type = this.element.value;
        if (type === 'deposit') return true;

        const sourceName = type === 'transfer' ? 'from_account_number' : 'account_number';
        const sourceRow = this.form.querySelector(`[data-transfer-account="${sourceName}"]`);
        const source = sourceRow?.querySelector('select, input');
        const amount = this.form.querySelector('[name$="[amount]"]');
        if (!source || source.disabled || !amount || !amount.value) return true;

        const option = source.selectedOptions?.[0];
        const accountNumber = option?.textContent?.trim() || source.value?.trim();
        const destination = this.form.querySelector('[data-transfer-account="to_account_number"] select, [data-transfer-account="to_account_number"] input');
        const key = [type, accountNumber, amount.value, ...['feeType', 'feeAmount', 'feeRate'].map((name) => this.form.querySelector(`[name$="[${name}]"]`)?.value || '')].join('|');
        if (!force && key === this.lastBalanceValidationKey) return this.lastBalanceValid;
        this.lastBalanceValidationKey = key;
        const requestId = (this.balanceRequestId || 0) + 1;
        this.balanceRequestId = requestId;

        try {
            const response = await fetch(this.form.dataset.balanceValidationUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                body: JSON.stringify({
                    type,
                    account_number: type === 'withdrawal' ? accountNumber : undefined,
                    source_account_number: type === 'transfer' ? accountNumber : undefined,
                    destination_account_number: destination?.selectedOptions?.[0]?.textContent?.trim() || destination?.value,
                    amount: amount.value,
                    feeType: this.form.querySelector('[name$="[feeType]"]')?.value,
                    feeAmount: this.form.querySelector('[name$="[feeAmount]"]')?.value,
                    feeRate: this.form.querySelector('[name$="[feeRate]"]')?.value,
                }),
            });
            if (!response.ok) throw new Error('balance validation failed');
            const result = await response.json();
            if (requestId !== this.balanceRequestId) return true;
            if (result.valid) {
                this.lastBalanceValid = true;
                this.setFieldStatus(amount, 'success', 'Sufficient balance for this operation.');
                return true;
            }
            this.lastBalanceValid = false;
            if (result.code === 'insufficient_balance') {
                this.showDialog('insufficient_balance', type === 'transfer' ? 'source' : 'account', source, {
                    state: 'balance',
                    title: 'Insufficient balance',
                    message: 'The requested amount exceeds the available balance in this account.',
                    suggestion: 'Please reduce the amount or choose another account.',
                    action: 'Understood',
                    details: [
                        `Available balance: ${result.available}`,
                        `Requested amount: ${result.requested}`,
                        `Transaction fees: ${result.fees}`,
                        `Total amount required: ${result.total}`,
                        `Missing amount: ${result.missing}`,
                    ],
                });
            } else if (result.code !== 'invalid_amount') {
                this.showDialog(result.code, type === 'transfer' ? 'source' : 'account', source);
            }
            return false;
        } catch (error) {
            this.lastBalanceValid = false;
            this.showDialog('unavailable', type === 'transfer' ? 'source' : 'account', source);
            return false;
        }
    }

    async validateAccount(field, role) {
        const option = field.selectedOptions?.[0];
        const accountNumber = option?.textContent?.trim() || field.value?.trim();
        if (!accountNumber) {
            this.setFieldStatus(field, 'error', 'Choose an account.');
            return false;
        }

        this.setFieldStatus(field, 'loading', 'Checking account…');
        this.validationInProgress = true;
        try {
            const response = await fetch(this.form.dataset.accountValidationUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                body: JSON.stringify({account_number: accountNumber}),
            });
            if (!response.ok) throw new Error('validation failed');
            const result = await response.json();
            this.validationInProgress = false;
            if (result.valid) {
                this.setFieldStatus(field, 'success', 'Account is active and ready to use.');
                return true;
            }
            this.setFieldStatus(field, 'error');
            this.showDialog(result.code, role, field);
            return false;
        } catch (error) {
            this.validationInProgress = false;
            this.setFieldStatus(field, 'error');
            this.showDialog('unavailable', role, field);
            return false;
        }
    }

    roleFor(field) {
        return field.name.includes('from_account') ? 'source' : field.name.includes('to_account') ? 'destination' : 'account';
    }

    dialogFor(code, role) {
        const prefix = role === 'source' ? 'Source account: ' : role === 'destination' ? 'Destination account: ' : '';
        if (code === 'closed') {
            return {
                state: 'closed',
                title: 'Account closed',
                message: `${prefix}This account is closed and cannot be used for this operation.`,
                suggestion: 'Please choose another active account.',
                action: 'Understood',
            };
        }
        if (code === 'inactive') {
            return {
                state: 'inactive',
                title: 'Account inactive',
                message: `${prefix}This account is inactive and cannot be used for this operation.`,
                suggestion: 'Please choose another active account.',
                action: 'Understood',
            };
        }
        if (code === 'unavailable') {
            return {
                state: 'unavailable',
                title: 'Validation unavailable',
                message: 'The account could not be validated right now.',
                suggestion: 'Check your connection and try again.',
                action: 'Try again',
            };
        }

        return {
            state: 'not-found',
            title: 'Account not found',
            message: `${prefix}The account number entered does not exist.`,
            suggestion: 'Please check the account number and try again.',
            action: 'Understood',
        };
    }

    setFieldStatus(field, state, message = '') {
        field.classList.toggle('is-valid', state === 'success');
        field.classList.toggle('is-invalid', state === 'error');
        field.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');

        let status = field.parentElement?.querySelector('[data-account-validation-status]');
        if (!status) {
            status = document.createElement('div');
            status.dataset.accountValidationStatus = '';
            status.setAttribute('role', 'status');
            status.setAttribute('aria-live', 'polite');
            field.parentElement?.appendChild(status);
        }
        status.className = `account-validation-status is-${state}`;
        status.textContent = message;
    }

    showDialog(code, role, field, override = null) {
        const content = override || this.dialogFor(code, role);
        this.closeDialog(false);
        this.retryField = field;
        this.dialog = document.createElement('dialog');
        this.dialog.className = 'account-validation-dialog';
        this.dialog.dataset.state = content.state;
        this.dialog.setAttribute('aria-labelledby', 'account-validation-title');
        this.dialog.setAttribute('aria-describedby', 'account-validation-message account-validation-suggestion');
        this.dialog.innerHTML = `
            <div class="account-validation-dialog__icon" aria-hidden="true"></div>
            <div class="account-validation-dialog__body">
                <h2 id="account-validation-title"></h2>
                <p id="account-validation-message"></p>
                <p id="account-validation-suggestion" class="account-validation-dialog__suggestion"></p>
            </div>
            <div class="account-validation-dialog__actions">
                <button type="button" class="account-validation-dialog__action"></button>
                <button type="button" class="account-validation-dialog__close">Close</button>
            </div>`;
        this.dialog.querySelector('#account-validation-title').textContent = content.title;
        this.dialog.querySelector('#account-validation-message').textContent = content.message;
        this.dialog.querySelector('#account-validation-suggestion').textContent = content.suggestion;
        const details = document.createElement('ul');
        details.className = 'account-validation-dialog__details';
        (content.details || []).forEach((detail) => {
            const item = document.createElement('li');
            item.textContent = detail;
            details.appendChild(item);
        });
        this.dialog.querySelector('.account-validation-dialog__body').insertBefore(details, this.dialog.querySelector('#account-validation-suggestion'));
        const action = this.dialog.querySelector('.account-validation-dialog__action');
        action.textContent = content.action;
        action.addEventListener('click', () => content.state === 'unavailable'
            ? this.retryValidation()
            : this.closeDialog());
        this.dialog.querySelector('.account-validation-dialog__close').addEventListener('click', () => this.closeDialog());
        this.dialog.addEventListener('cancel', (event) => {
            if (this.validationInProgress) {
                event.preventDefault();
                return;
            }
            event.preventDefault();
            this.closeDialog();
        });
        document.body.appendChild(this.dialog);
        this.dialog.showModal();
        action.focus();
    }

    async retryValidation() {
        if (!this.retryField || this.validationInProgress) return;
        this.closeDialog();
        await this.validateAccount(this.retryField, this.roleFor(this.retryField));
    }

    closeDialog(animate = true) {
        if (!this.dialog || !this.dialog.open || this.validationInProgress) return;
        const dialog = this.dialog;
        const remove = () => {
            dialog.removeEventListener('animationend', remove);
            dialog.close();
            dialog.remove();
            if (this.dialog === dialog) this.dialog = null;
        };
        if (!animate) {
            remove();
            return;
        }
        dialog.classList.add('is-closing');
        dialog.addEventListener('animationend', remove, {once: true});
        window.setTimeout(remove, 220);
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
