/**
 * Allowed currencies
 * Control for currency administration
 *
 * @module package/quiqqer/currency/bin/settings/AllowedCurrencies
 * @author www.pcsg.de (Henning Leutz)
 */
define('package/quiqqer/currency/bin/settings/AllowedCurrencies', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/buttons/Switch',
    'qui/controls/windows/Confirm',
    'qui/controls/windows/Prompt',
    'Ajax',
    'Locale',
    'controls/grid/Grid',
    'package/quiqqer/currency/bin/settings/CurrencyWindow',

    'css!package/quiqqer/currency/bin/settings/AllowedCurrencies.css'

], function (QUI, QUIControl, QUISwitch, QUIConfirm, QUIPrompt, QUIAjax, QUILocale, Grid, CurrencyWindow) {
    'use strict';

    const lg = 'quiqqer/currency';
    const CONTEXT_FRONTEND = 'frontend';
    const CONTEXT_BACKEND = 'backend';

    return new Class({

        Extends: QUIControl,
        Type: 'package/quiqqer/currency/bin/settings/AllowedCurrencies',

        Binds: [
            'refresh',
            'openCreateDialog',
            '$onImport',
            '$onCurrencyStatusChange',
            '$switchCurrencyStatus',
            '$changeAutoUpdate',
            '$importFromECB'
        ],

        options: {
            frontendValues: {},
            backendValues: {}
        },

        initialize: function (options) {
            this.parent(options);

            this.$Input = null;
            this.$Elm = null;
            this.$Container = null;
            this.$Grid = null;

            this.addEvents({
                onImport: this.$onImport
            });
        },

        /**
         * event : on import
         */
        $onImport: function () {
            this.$Input = this.getElm();
            this.$Input.type = 'hidden';

            /*
            if (this.$Input.value !== '') {
                let values = {};
                let value = this.$Input.value.split(',');

                for (i = 0, len = value.length; i < len; i++) {
                    values[value[i]] = 1;
                }

                this.setAttribute('values', values);
            }
            */

            this.$Elm = new Element('div', {
                'class': 'quiqqer-currency-allowed',
                html: '<div class="quiqqer-currency-allowed-container"></div>'
            }).wraps(this.$Input);

            const self = this,
                Settings = this.$Elm.getParent('.qui-xml-panel-row-item');

            if (Settings) {
                Settings.setStyles({
                    height: 300,
                    overflow: 'hidden',
                    width: '100%'
                });
            }

            this.$Container = this.$Elm.getElement(
                '.quiqqer-currency-allowed-container'
            );

            let width = this.$Container.getSize().x;

            this.$Grid = new Grid(this.$Container, {
                pagination: true,
                multipleSelection: true,
                height: 400,
                width: width,
                columnModel: [
                    {
                        header: QUILocale.get(lg, 'grid.setting.currency'),
                        dataIndex: 'code',
                        dataType: 'string',
                        width: 60,
                        editable: true
                    },
                    {
                        header: QUILocale.get(lg, 'grid.setting.sign'),
                        dataIndex: 'sign',
                        dataType: 'string',
                        width: 60,
                        editable: true
                    },
                    {
                        header: QUILocale.get(lg, 'grid.setting.rate'),
                        dataIndex: 'rate',
                        dataType: 'string',
                        width: 100
                    },
                    {
                        header: QUILocale.get(lg, 'grid.setting.precision'),
                        dataIndex: 'precision',
                        dataType: 'string',
                        width: 100
                    },
                    {
                        header: QUILocale.get(lg, 'grid.setting.frontend'),
                        dataIndex: 'frontend',
                        dataType: 'QUI',
                        width: 100
                    },
                    {
                        header: QUILocale.get(lg, 'grid.setting.backend'),
                        dataIndex: 'backend',
                        dataType: 'QUI',
                        width: 100
                    },
                    {
                        header: QUILocale.get(lg, 'grid.setting.update'),
                        dataIndex: 'autoupdate',
                        dataType: 'QUI',
                        width: 100
                    },
                    {
                        header: QUILocale.get(lg, 'grid.setting.typeTitle'),
                        dataIndex: 'typeTitle',
                        dataType: 'string',
                        width: 100
                    }
                ],
                buttons: [
                    {
                        name: 'add',
                        text: QUILocale.get(lg, 'grid.setting.button.add'),
                        textimage: 'fa fa-plus',
                        events: {
                            onClick: this.openCreateDialog
                        }
                    },
                    {
                        name: 'edit',
                        text: QUILocale.get(lg, 'grid.setting.button.edit'),
                        textimage: 'fa fa-edit',
                        disabled: true,
                        events: {
                            onClick: function () {
                                self.openUpdateDialog(self.$Grid.getSelectedData()[0].code);
                            }
                        }
                    },
                    {
                        type: 'separator'
                    },
                    {
                        name: 'delete',
                        text: QUILocale.get(lg, 'grid.setting.button.delete'),
                        textimage: 'fa fa-trash',
                        disabled: true,
                        events: {
                            onClick: function () {
                                const currencies = self.$Grid.getSelectedData().map(function (C) {
                                    return C.code;
                                });

                                self.openDeleteDialog(currencies);
                            }
                        }
                    }
                ]
            });

            this.$Grid.setWidth(width);

            this.$Grid.addEvents({
                onClick: function () {
                    const selected = self.$Grid.getSelectedIndices(),
                        buttons = self.$Grid.getButtons();

                    const Edit = buttons.filter(function (Btn) {
                        return Btn.getAttribute('name') === 'edit';
                    })[0];

                    const Delete = buttons.filter(function (Btn) {
                        return Btn.getAttribute('name') === 'delete';
                    })[0];

                    if (selected.length === 1) {
                        Edit.enable();
                        Delete.enable();
                    } else {
                        if (selected.length > 1) {
                            Edit.disable();
                            Delete.enable();
                        }
                    }
                },

                onDblClick: function () {
                    self.openUpdateDialog(self.$Grid.getSelectedData()[0].code);
                },

                onRefresh: this.refresh
            });

            this.$Grid.showLoader();

            this.$loadCurrencyStatus();
        },

        /**
         * refresh the currency list
         */
        refresh: function () {
            this.$Grid.showLoader();

            return this.getCurrencies().then((list) => {
                const frontendValues = this.getAttribute('frontendValues');
                const backendValues = this.getAttribute('backendValues');
                let data = Object.keys(list).map((key) => list[key]);

                let perPage = this.$Grid.options.perPage,
                    page = this.$Grid.options.page,
                    start = (page - 1) * perPage,
                    total = data.length;

                data = data.splice(start, perPage);

                data.forEach((entry, index) => {
                    data[index].frontend = new QUISwitch({
                        status: Object.prototype.hasOwnProperty.call(frontendValues, entry.code),
                        currency: entry.code,
                        context: CONTEXT_FRONTEND,
                        events: {
                            onChange: this.$onCurrencyStatusChange
                        }
                    });

                    data[index].backend = new QUISwitch({
                        status: Object.prototype.hasOwnProperty.call(backendValues, entry.code),
                        currency: entry.code,
                        context: CONTEXT_BACKEND,
                        events: {
                            onChange: this.$onCurrencyStatusChange
                        }
                    });

                    data[index].autoupdate = new QUISwitch({
                        status: entry.autoupdate,
                        currency: entry.code,
                        events: {
                            onChange: this.$changeAutoUpdate
                        }
                    });
                });

                this.$Grid.setData({
                    data: data,
                    total: total,
                    page: page
                });

                const buttons = this.$Grid.getButtons();

                const Edit = buttons.filter(function (Btn) {
                    return Btn.getAttribute('name') === 'edit';
                })[0];

                const Delete = buttons.filter(function (Btn) {
                    return Btn.getAttribute('name') === 'delete';
                })[0];

                Edit.disable();
                Delete.disable();

                this.$Grid.hideLoader();

            });
        },

        /**
         * update values to the input field
         */
        update: function () {
            const allowed = [],
                values = this.getAttribute('frontendValues');

            if (values && typeof values === 'object') {
                for (const i in values) {
                    if (!Object.prototype.hasOwnProperty.call(values, i)) {
                        continue;
                    }

                    if (values[i]) {
                        allowed.push(i);
                    }
                }
            }

            this.$Input.value = allowed.join(',');
        },

        /**
         * Return all available currencies
         * @returns {Promise}
         */
        getCurrencies: function () {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_currency_ajax_getCurrencies', resolve, {
                    'package': 'quiqqer/currency',
                    onError: reject
                });
            });
        },

        /**
         * Return all allowed (active) currencies
         *
         * @returns {Promise}
         */
        getAllowedCurrencies: function (context) {
            return new Promise((resolve, reject) => {
                QUIAjax.get('package_quiqqer_currency_ajax_getAllowedCurrencies', resolve, {
                    'package': 'quiqqer/currency',
                    context: context,
                    onError: reject
                });
            });
        },

        /**
         * Persist the active currencies for one application context.
         *
         * @param {String} context
         * @param {Array} currencies
         * @returns {Promise}
         */
        setAllowedCurrencies: function (context, currencies) {
            return new Promise((resolve, reject) => {
                QUIAjax.post('package_quiqqer_currency_ajax_setAllowedCurrencies', resolve, {
                    'package': 'quiqqer/currency',
                    context: context,
                    currencies: JSON.stringify(currencies),
                    onError: reject
                });
            });
        },

        /**
         * Load frontend and backend activation state.
         *
         * @returns {Promise}
         */
        $loadCurrencyStatus: function () {
            return Promise.all([
                this.getAllowedCurrencies(CONTEXT_FRONTEND),
                this.getAllowedCurrencies(CONTEXT_BACKEND)
            ]).then((result) => {
                const frontendValues = {};
                const backendValues = {};

                result[0].forEach((currency) => {
                    frontendValues[currency.code] = true;
                });

                result[1].forEach((currency) => {
                    backendValues[currency.code] = true;
                });

                this.setAttribute('frontendValues', frontendValues);
                this.setAttribute('backendValues', backendValues);
                this.update();

                return this.$Grid.refresh();
            });
        },

        /**
         * Delete a currency
         *
         * @param {Array|String} currencies
         * @returns {Promise}
         */
        deleteCurrency: function (currencies) {
            if (typeOf(currencies) === 'string') {
                currencies = [currencies];
            }

            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_currency_ajax_delete', resolve, {
                    'package': 'quiqqer/currency',
                    currencies: JSON.encode(currencies),
                    onError: reject
                });
            });
        },

        /**
         * Create a new currency
         *
         * @param {String} currency
         * @returns {Promise}
         */
        createCurrency: function (currency) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_currency_ajax_create', resolve, {
                    'package': 'quiqqer/currency',
                    currency: currency,
                    onError: reject
                });
            });
        },

        /**
         * event : on currency status change
         *
         * @param {Object} Switch
         */
        $onCurrencyStatusChange: function (Switch) {
            const currency = Switch.getAttribute('currency');
            const context = Switch.getAttribute('context');
            const attribute = context === CONTEXT_BACKEND ?
                'backendValues'
                : 'frontendValues';
            const values = this.getAttribute(attribute);

            if (Switch.getStatus()) {
                values[currency] = true;
            } else if (Object.prototype.hasOwnProperty.call(values, currency)) {
                delete values[currency];
            }

            this.setAttribute(attribute, values);

            if (context === CONTEXT_FRONTEND) {
                this.update();
            }

            return this.setAllowedCurrencies(context, Object.keys(values)).then((result) => {
                const persistedValues = {};

                result.forEach((entry) => {
                    persistedValues[entry.code] = true;
                });

                this.setAttribute(attribute, persistedValues);

                if (context === CONTEXT_FRONTEND) {
                    this.update();
                }

                return this.$Grid.refresh();
            }).catch(() => this.$loadCurrencyStatus());
        },

        /**
         * event : click on span -> currency status change
         *
         * @param {Event} event
         */
        $switchCurrencyStatus: function (event) {
            const Target = event.target;
            const SwitchContainer = Target.getParent().getElement('.qui-switch');

            const Switch = QUI.Controls.getById(
                SwitchContainer.get('data-quiid')
            );

            Switch.toggle();
        },

        /**
         * Set the currency autoupdate status
         *
         * @param {Object} Switch
         * @return {Promise}
         */
        $changeAutoUpdate: function (Switch) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_currency_ajax_setAutoupdate', resolve, {
                    'package': 'quiqqer/currency',
                    currency: Switch.getAttribute('currency'),
                    autoupdate: Switch.getStatus() ? 1 : 0,
                    onError: reject
                });
            });
        },

        /**
         * Import the currencies from the ECB
         *
         * @returns {Promise}
         */
        $importFromECB: function () {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_currency_ajax_importFromECB', resolve, {
                    'package': 'quiqqer/currency',
                    onError: reject
                });
            });
        },

        /**
         * dialogs
         */

        /**
         * Opens the edit dialog
         *
         * @param {String} currency
         */
        openUpdateDialog: function (currency) {
            new CurrencyWindow({
                currency: currency,
                events: {
                    onClose: this.refresh
                }
            }).open();
        },

        /**
         * Opens the delete dialog
         *
         * @param {Array} currencies
         */
        openDeleteDialog: function (currencies) {
            if (!currencies.length) {
                return;
            }

            const self = this;
            let text, information;

            if (currencies.length === 1) {
                text = QUILocale.get(lg, 'window.delete.text', {
                    currency: currencies[0]
                });

                information = QUILocale.get(lg, 'window.delete.information', {
                    currency: currencies[0]
                });
            } else {
                text = QUILocale.get(lg, 'window.delete.plural.text');

                information = QUILocale.get(lg, 'window.delete.plural.information', {
                    currencies: currencies.join(', ')
                });
            }

            new QUIConfirm({
                icon: 'fa fa-trash',
                texticon: 'fa fa-trash',
                title: QUILocale.get(lg, 'window.delete.title'),
                text: text,
                information: information,
                ok_button: {
                    text: QUILocale.get('quiqqer/system', 'delete'),
                    textimage: 'icon-ok fa fa-check'
                },
                maxHeight: 400,
                maxWidth: 600,
                autoclose: false,
                events: {
                    onSubmit: function (Win) {
                        Win.Loader.show();
                        self.deleteCurrency(currencies).then(function () {

                            self.refresh().then(function () {
                                self.update();
                                Win.Loader.hide();
                                Win.close();
                            });

                        }, function () {
                            Win.Loader.hide();
                        });
                    }
                }
            }).open();
        },

        /**
         * Opens the create dialog
         */
        openCreateDialog: function () {
            const self = this;

            new QUIPrompt({
                icon: 'fa fa-money',
                titleicon: 'fa fa-money',
                title: QUILocale.get(lg, 'window.create.title'),
                information: QUILocale.get(lg, 'window.create.information'),
                maxHeight: 300,
                maxWidth: 450,
                events: {
                    onSubmit: function (value, Win) {
                        Win.Loader.show();

                        self.createCurrency(value).then(function () {
                            Win.close();
                            self.openUpdateDialog(value);
                        }, function () {
                            Win.Loader.hide();
                        });
                    }
                }
            }).open();
        }
    });
});
