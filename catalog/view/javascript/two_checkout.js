/**
 * Two checkout capture for OpenCart 4.1 (Build Guide sections 1, 2 and 6.3).
 *
 * - Company search, manual entry and the sole-trader signup, as one control
 *   per address role (`payment`, `shipping`) or in the payment tile (`tile`),
 *   as the merchant's placement switch says. Each control owns its own DOM
 *   subtree and state, keyed by its role, so the billing and shipping panels
 *   work independently and whatever else is on the page.
 * - The order intent, asked from the tile once Two is the chosen method and
 *   the company is known; never while the method list renders.
 *
 * Settings come from the JSON island the checkout page event adds
 * (`#two-checkout-config`). Every call goes to the shop, which relays it to
 * Two with the merchant's key, except the enrolled sole trader's own details,
 * which only the buyer's Two session can read.
 */
(function (root) {
    'use strict';

    const DEBOUNCE_MS = 300;
    const ROLES = ['payment', 'shipping'];

    function readConfig(doc) {
        const island = doc.getElementById('two-checkout-config');
        if (!island) {
            return null;
        }
        try {
            return JSON.parse(island.textContent);
        } catch (error) {
            return null;
        }
    }


    /** The shop's relay: every call carries the session's token. */
    class Api {
        constructor(config, fetchImpl) {
            this.config = config;
            this.fetch = fetchImpl || ((url, init) => root.fetch(url, init));
            this.queue = Promise.resolve();
        }

        /**
         * Runs a call after the ones before it have finished. Every call is
         * queued: OpenCart writes the whole session back at the end of every
         * request, even one that only reads it, so two at once could undo
         * one's change.
         */
        serial(name, call) {
            const run = this.queue.then(call, call);
            this.queue = run.catch(() => null);
            return run;
        }

        url(name, params) {
            const query = new URLSearchParams(Object.assign({ two_token: this.config.token }, params || {}));
            return this.config.urls[name] + '&' + query.toString();
        }

        /** Resolves once every call queued so far has finished. */
        settled() {
            return this.queue;
        }

        get(name, params, signal) {
            return this.serial(name, () => this.fetch(this.url(name, params), { credentials: 'same-origin', signal: signal })
                .then((response) => response.json()));
        }

        post(name, params) {
            const body = new URLSearchParams(Object.assign({ two_token: this.config.token }, params || {}));
            return this.serial(name, () => this.fetch(this.config.urls[name], {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then((response) => response.json()));
        }
    }

    /**
     * The register's own highlight, kept as <mark> and <b> only. Nothing is
     * parsed as markup: any other angle bracket reads as itself.
     */
    function highlight(doc, html) {
        // The register escapes its text (&amp; and the like): decoded as text, never as markup.
        const decoder = doc.createElement('textarea');
        const text = (token) => {
            decoder.innerHTML = token;
            return doc.createTextNode(decoder.value);
        };
        const fragment = doc.createDocumentFragment();
        const stack = [fragment];
        String(html || '').split(/(<\/?(?:mark|b)>)/).forEach((token) => {
            const tag = /^<(\/?)(mark|b)>$/.exec(token);
            const host = stack[stack.length - 1];
            if (!tag) {
                if (token) {
                    host.appendChild(text(token));
                }
            } else if (!tag[1]) {
                const element = doc.createElement(tag[2]);
                host.appendChild(element);
                stack.push(element);
            } else if (stack.length > 1 && host.tagName.toLowerCase() === tag[2]) {
                stack.pop();
            } else {
                host.appendChild(text(token));
            }
        });
        return fragment;
    }

    /** The company as the buyer reads it: Two's own TWO: identifiers (a sole trader's) are never shown. */
    function labelFor(name, number) {
        const internal = /^\s*two:/i.test(String(number || ''));
        return number && !internal ? name + ' (' + number + ')' : name;
    }

    /** One company control for one role. */
    class CompanySearch {
        /**
         * @param {object} options role, input (the company name field), country()
         *   (the role's ISO code or ''), organization (an existing field, or null to
         *   add one), api, config, onChange(company or null), win (the window)
         */
        constructor(options) {
            this.role = options.role;
            this.input = options.input;
            this.country = options.country;
            this.api = options.api;
            this.config = options.config;
            this.i18n = options.config.i18n || {};
            this.onChange = options.onChange || function () {};
            this.win = options.win || root;
            this.doc = this.input.ownerDocument;
            this.seq = 0;
            this.controller = null;
            this.timer = null;
            this.cache = new Map();
            this.results = [];
            this.active = -1;
            this.manual = false;
            this.selected = null;
            this.popup = null;
            this.tokens = null;
            this.build(options.organization || null);
            const saved = (options.config.companies || {})[this.role];
            if (saved && saved.organization_number) {
                this.selected = saved;
                this.manual = saved.source === 'manual';
                this.orgInput.value = saved.organization_number;
            }
            this.render();
            this.refreshSoleTrader();
        }

        build(organization) {
            const doc = this.doc;
            const id = 'two-company-' + this.role;
            this.panel = doc.createElement('div');
            this.panel.className = 'two-company-search';
            this.panel.setAttribute('data-two-role', this.role);
            this.list = doc.createElement('ul');
            this.list.id = id + '-results';
            this.list.className = 'list-group two-company-results';
            this.list.setAttribute('role', 'listbox');
            this.list.hidden = true;
            this.status = doc.createElement('div');
            this.status.className = 'form-text two-company-status';
            this.status.setAttribute('role', 'status');
            this.status.setAttribute('aria-live', 'polite');
            this.manualButton = doc.createElement('button');
            this.manualButton.type = 'button';
            this.manualButton.className = 'btn btn-link p-0 me-3 two-company-manual';
            this.soleTraderButton = doc.createElement('button');
            this.soleTraderButton.type = 'button';
            this.soleTraderButton.className = 'btn btn-link p-0 two-company-sole-trader';
            this.soleTraderButton.textContent = this.i18n.sole_trader_entry || '';
            this.soleTraderButton.hidden = true;
            if (organization) {
                this.orgWrapper = organization;
                this.orgInput = organization.querySelector('input');
            } else {
                this.orgWrapper = doc.createElement('div');
                this.orgWrapper.className = 'mt-2';
                const label = doc.createElement('label');
                label.className = 'form-label';
                label.htmlFor = id + '-organization';
                label.textContent = this.i18n.organization_number || '';
                this.orgInput = doc.createElement('input');
                this.orgInput.type = 'text';
                this.orgInput.id = id + '-organization';
                this.orgInput.className = 'form-control';
                this.orgWrapper.append(label, this.orgInput);
            }
            this.panel.append(this.list, this.status, this.manualButton, this.soleTraderButton);
            if (!organization) {
                this.panel.append(this.orgWrapper);
            }
            this.input.insertAdjacentElement('afterend', this.panel);
            this.input.setAttribute('role', 'combobox');
            this.input.setAttribute('aria-autocomplete', 'list');
            this.input.setAttribute('aria-controls', this.list.id);
            this.input.setAttribute('aria-expanded', 'false');
            this.input.setAttribute('autocomplete', 'off');

            this.handlers = {
                input: () => this.onInput(),
                keydown: (event) => this.onKeydown(event),
                manual: () => this.toggleManual(),
                orgChange: () => this.scheduleManual(),
                soleTrader: () => this.startSoleTrader()
            };
            this.input.addEventListener('input', this.handlers.input);
            this.input.addEventListener('keydown', this.handlers.keydown);
            this.manualButton.addEventListener('click', this.handlers.manual);
            this.orgInput.addEventListener('input', this.handlers.orgChange);
            this.soleTraderButton.addEventListener('click', this.handlers.soleTrader);
        }

        destroy() {
            this.cancel();
            this.input.removeEventListener('input', this.handlers.input);
            this.input.removeEventListener('keydown', this.handlers.keydown);
            clearTimeout(this.manualTimer);
            if (this.messageHandler) {
                this.win.removeEventListener('message', this.messageHandler);
            }
            this.panel.remove();
        }

        render() {
            this.manualButton.textContent = this.manual ? (this.i18n.back_to_search || '') : (this.i18n.manual_entry || '');
            this.orgWrapper.hidden = !this.manual;
        }

        setStatus(text) {
            this.status.textContent = text || '';
        }

        cancel() {
            this.seq += 1;
            clearTimeout(this.timer);
            if (this.controller) {
                this.controller.abort();
                this.controller = null;
            }
        }

        onInput() {
            if (this.manual) {
                // One save (and one intent) once the buyer pauses, never one per keystroke.
                this.scheduleManual();
                return;
            }
            if (this.selected) {
                this.clearSelection();
            }
            this.cancel();
            this.timer = setTimeout(() => this.search(this.input.value), DEBOUNCE_MS);
        }

        /** Searches the role's country; stale answers (a later keystroke, another country) are dropped. */
        search(term) {
            term = String(term).trim();
            if (term.length < this.config.min_length) {
                this.cancel();
                this.close();
                this.setStatus(term.length ? this.i18n.search_hint : '');
                return Promise.resolve();
            }
            const country = this.country();
            if (!country) {
                this.cancel();
                this.close();
                this.setStatus(this.i18n.select_country);
                return Promise.resolve();
            }
            const covered = CompanySearch.coveredCountries;
            if (Array.isArray(covered) && covered.indexOf(country) === -1) {
                this.cancel();
                this.close();
                this.setStatus(this.i18n.country_not_covered);
                return Promise.resolve();
            }
            const key = country + '|' + term.toLowerCase();
            if (this.cache.has(key)) {
                this.show(this.cache.get(key));
                return Promise.resolve();
            }
            this.cancel();
            const seq = this.seq;
            this.controller = typeof AbortController === 'function' ? new AbortController() : null;
            this.setStatus(this.i18n.searching);
            return this.api.get('search', { q: term, country: country }, this.controller ? this.controller.signal : undefined)
                .then((json) => {
                    if (seq !== this.seq) {
                        return;
                    }
                    this.controller = null;
                    if (!json || !Array.isArray(json.items)) {
                        this.close();
                        this.setStatus(this.i18n.search_unavailable);
                        return;
                    }
                    this.cache.set(key, json.items);
                    this.show(json.items);
                })
                .catch(() => {
                    if (seq === this.seq) {
                        this.close();
                        this.setStatus(this.i18n.search_unavailable);
                    }
                });
        }

        show(items) {
            const doc = this.doc;
            this.results = items;
            this.active = -1;
            this.list.textContent = '';
            items.forEach((item, index) => {
                const option = doc.createElement('li');
                option.className = 'list-group-item list-group-item-action';
                option.id = this.list.id + '-' + index;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');
                const name = doc.createElement('span');
                name.appendChild(highlight(doc, item.highlight || item.name));
                option.appendChild(name);
                if (item.organization_number && !/^\s*two:/i.test(item.organization_number)) {
                    const number = doc.createElement('small');
                    number.className = 'text-muted ms-2';
                    number.textContent = item.organization_number;
                    option.appendChild(number);
                }
                // mousedown, so the input's blur does not close the list first.
                option.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    this.choose(index);
                });
                this.list.appendChild(option);
            });
            this.list.hidden = items.length === 0;
            this.input.setAttribute('aria-expanded', items.length ? 'true' : 'false');
            this.setStatus(items.length ? '' : this.i18n.no_matches);
        }

        close() {
            this.list.hidden = true;
            this.list.textContent = '';
            this.results = [];
            this.active = -1;
            this.input.setAttribute('aria-expanded', 'false');
            this.input.removeAttribute('aria-activedescendant');
        }

        onKeydown(event) {
            if (this.list.hidden || !this.results.length) {
                return;
            }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const step = event.key === 'ArrowDown' ? 1 : -1;
                this.active = (this.active + step + this.results.length) % this.results.length;
                Array.prototype.forEach.call(this.list.children, (option, index) => {
                    option.setAttribute('aria-selected', index === this.active ? 'true' : 'false');
                    option.classList.toggle('active', index === this.active);
                });
                this.input.setAttribute('aria-activedescendant', this.list.id + '-' + this.active);
            } else if (event.key === 'Enter' && this.active >= 0) {
                event.preventDefault();
                this.choose(this.active);
            } else if (event.key === 'Escape') {
                this.close();
            }
        }

        /**
         * Adopts a result. Some registers leave the organisation number out of
         * the search results: then the company's own entry is looked up, and a
         * company is never saved without a number.
         */
        choose(index) {
            const item = this.results[index];
            if (!item) {
                return Promise.resolve();
            }
            const country = this.country();
            const adopt = (number) => (number
                ? this.select({ company_name: item.name, organization_number: number, country_code: country, source: 'search' })
                : this.needNumber(item.name));
            if (item.organization_number || !item.lookup_id) {
                return adopt(item.organization_number);
            }
            this.cancel();
            this.close();
            this.input.value = item.name;
            this.setStatus(this.i18n.searching);
            const seq = this.seq;
            return this.api.get('company_details', { lookup_id: item.lookup_id })
                .then((json) => (seq === this.seq ? adopt(json && json.organization_number) : null))
                .catch(() => (seq === this.seq ? this.needNumber(item.name) : null));
        }

        /** No number came with the company: the buyer enters it. */
        needNumber(name) {
            this.input.value = name;
            if (!this.manual) {
                this.toggleManual();
            }
            this.setStatus(this.i18n.number_missing);
            return Promise.resolve();
        }

        /** Adopts a company: into the form's own field, then the session, then the listeners. */
        select(company) {
            this.cancel();
            this.close();
            this.selected = company;
            this.input.value = company.company_name;
            this.orgInput.value = company.organization_number;
            this.setStatus(labelFor(company.company_name, company.organization_number));
            // Only if it is still the company: a later clear or choice supersedes it.
            return this.save(company).then(() => (this.selected === company ? this.onChange(company, this.role) : null));
        }

        clearSelection() {
            this.selected = null;
            if (!this.manual) {
                this.orgInput.value = '';
            }
            this.save({ company_name: '', organization_number: '', country_code: this.country() || 'XX', source: 'search' });
            this.onChange(null, this.role);
        }

        save(company) {
            return this.api.post('save_company', {
                role: this.role,
                company_name: company.company_name,
                organization_number: company.organization_number,
                country: company.country_code,
                source: company.source
            }).catch(() => null);
        }

        toggleManual() {
            this.manual = !this.manual;
            this.cancel();
            this.close();
            this.render();
            if (this.manual) {
                this.setStatus('');
                this.orgInput.focus();
            }
        }

        scheduleManual() {
            clearTimeout(this.manualTimer);
            this.manualTimer = setTimeout(() => {
                this.manualTimer = null;
                this.saveManual();
            }, DEBOUNCE_MS);
        }

        /** Saves a manual entry still waiting on its debounce, now. */
        flush() {
            if (this.manualTimer) {
                clearTimeout(this.manualTimer);
                this.manualTimer = null;
                this.saveManual();
            }
        }

        saveManual() {
            const name = this.input.value.trim();
            const number = this.orgInput.value.trim();
            const country = this.country();
            const same = this.selected && this.selected.company_name === name && this.selected.organization_number === number && this.selected.country_code === country;
            if (this.manual && this.selected && (!name || !number || !country)) {
                // An emptied name or number withdraws the saved company.
                this.clearSelection();
                return Promise.resolve();
            }
            if (!this.manual || !name || !number || !country || same) {
                return Promise.resolve();
            }
            return this.select({ company_name: name, organization_number: number, country_code: country, source: 'manual' });
        }

        /** Shows the sole-trader entry when the registry offers it in the role's country. */
        refreshSoleTrader() {
            const country = this.country();
            if (!country) {
                this.soleTraderButton.hidden = true;
                return Promise.resolve();
            }
            return this.api.get('sole_trader', { country: country })
                .then((json) => {
                    this.soleTraderButton.hidden = !(json && json.available === true);
                })
                .catch(() => {
                    this.soleTraderButton.hidden = true;
                });
        }

        /** Mints the signup's tokens and opens Two's hosted sole-trader signup. */
        startSoleTrader() {
            const country = this.country();
            this.setStatus('');
            // Opened inside the click, so a popup blocker lets it through.
            const popup = this.win.open('about:blank', '_blank', 'location=yes,resizable=yes,scrollbars=yes,status=yes,height=805,width=700');
            if (!popup) {
                this.setStatus(this.i18n.sole_trader_popup_blocked);
                return Promise.resolve();
            }
            return this.api.post('sole_trader_tokens', { country: country })
                .then((json) => {
                    if (!json || !json.delegation_token || !json.autofill_token) {
                        popup.close();
                        this.setStatus(this.i18n.sole_trader_error);
                        return;
                    }
                    this.tokens = json;
                    const prefill = { company_name: this.input.value.trim(), country_code: json.country };
                    const data = this.win.btoa(unescape(encodeURIComponent(JSON.stringify(prefill))));
                    popup.location.href = json.signup_url
                        + '?businessToken=' + encodeURIComponent(json.delegation_token)
                        + '&autofillToken=' + encodeURIComponent(json.autofill_token)
                        + '&autofillData=' + encodeURIComponent(data);
                    this.popup = popup;
                    this.listenForSignup();
                })
                .catch(() => {
                    popup.close();
                    this.setStatus(this.i18n.sole_trader_error);
                });
        }

        listenForSignup() {
            if (this.messageHandler) {
                this.win.removeEventListener('message', this.messageHandler);
            }
            const origin = new URL(this.tokens.signup_url).origin;
            this.messageHandler = (event) => {
                if (event.origin !== origin || (this.popup && event.source && event.source !== this.popup)) {
                    return;
                }
                if (event.data !== 'ACCEPTED') {
                    this.setStatus(this.i18n.sole_trader_error);
                    return;
                }
                this.win.removeEventListener('message', this.messageHandler);
                this.messageHandler = null;
                this.adoptEnrolledBuyer();
            };
            this.win.addEventListener('message', this.messageHandler);
        }

        /** Reads the enrolled sole trader from the buyer's own Two session and adopts it. */
        adoptEnrolledBuyer() {
            const tokens = this.tokens;
            return this.win.fetch(tokens.api_base + '/autofill/v1/buyer/current', {
                credentials: 'include',
                headers: { 'two-delegated-authority-token': tokens.autofill_token }
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((buyer) => {
                    if (!buyer || !buyer.organization_number) {
                        this.setStatus(this.i18n.sole_trader_error);
                        return null;
                    }
                    const person = [buyer.first_name, buyer.last_name].filter(Boolean).join(' ');
                    const fallback = /^\s*two:/i.test(buyer.organization_number) ? '' : buyer.organization_number;
                    return this.select({
                        company_name: buyer.company_name || person || fallback || (this.i18n.sole_trader_entry || ''),
                        organization_number: buyer.organization_number,
                        country_code: tokens.country,
                        source: 'sole_trader'
                    });
                })
                .catch(() => this.setStatus(this.i18n.sole_trader_error));
        }
    }

    /** The countries the search covers: unknown (null) until the shop answers. */
    CompanySearch.coveredCountries = null;

    /** The page's controls, one per role, and the tile's order intent. */
    class Checkout {
        constructor(config, doc, api, win) {
            this.config = config;
            this.doc = doc;
            this.api = api;
            this.win = win || root;
            this.instances = {};
            this.intentSeq = 0;
        }

        start() {
            this.api.get('countries').then((json) => {
                CompanySearch.coveredCountries = json && Array.isArray(json.countries) ? json.countries : null;
            }).catch(() => null);
            if (this.config.in_address) {
                this.mountAddressRoles();
                if (typeof MutationObserver === 'function') {
                    // The checkout re-renders its address forms (login, a new address).
                    new MutationObserver(() => this.mountAddressRoles()).observe(this.doc.body, { childList: true, subtree: true });
                }
            }
            return this;
        }

        /** One control per address form on the page, each on its own. */
        mountAddressRoles() {
            ROLES.forEach((role) => {
                const input = this.doc.getElementById('input-' + role + '-company');
                const current = this.instances[role];
                if (current && current.input === input && input && input.isConnected) {
                    return;
                }
                if (current) {
                    current.destroy();
                    delete this.instances[role];
                }
                if (!input) {
                    return;
                }
                const select = this.doc.getElementById('input-' + role + '-country');
                const instance = this.mount(role, input, () => (select ? this.config.countries[select.value] || '' : ''), null);
                if (select) {
                    select.addEventListener('change', () => {
                        instance.clearSelection();
                        instance.refreshSoleTrader();
                    });
                }
            });
        }

        mount(role, input, country, organization) {
            if (this.instances[role]) {
                this.instances[role].destroy();
            }
            const instance = new CompanySearch({
                role: role,
                input: input,
                country: country,
                organization: organization,
                api: this.api,
                config: this.config,
                win: this.win,
                onChange: (company) => this.companyChanged(role, company)
            });
            this.instances[role] = instance;
            return instance;
        }

        companyChanged(role, company) {
            this.config.companies = Object.assign({}, this.config.companies || {});
            if (company) {
                this.config.companies[role] = company;
            } else {
                delete this.config.companies[role];
            }
            if (!this.tile || (role !== 'tile' && role !== this.tileRole())) {
                return Promise.resolve();
            }
            if (!company) {
                this.intentSeq += 1;
                const notice = this.doc.getElementById('two-intent-notice');
                if (notice) {
                    notice.className = '';
                    notice.textContent = '';
                }
                return Promise.resolve();
            }
            return this.intent();
        }

        /** The address role whose company the tile pays for: billing when the page has one. */
        tileRole() {
            return this.doc.getElementById('input-payment-company') || (this.config.companies || {}).payment ? 'payment' : 'shipping';
        }

        /**
         * Resolves once every pending save has reached the shop, so placing
         * the order reads the company the buyer last gave.
         */
        settled() {
            Object.keys(this.instances).forEach((role) => this.instances[role].flush());
            return this.api.settled();
        }

        /** The payment tile has rendered (Two is the chosen method). */
        tileReady(form) {
            this.tile = form;
            if (form.getAttribute('data-two-tile') === 'search') {
                const select = form.querySelector('[data-two-country]');
                const instance = this.mount('tile', form.querySelector('[data-two-company]'), () => (select ? select.value : ''), form.querySelector('[data-two-organization]'));
                if (select) {
                    select.addEventListener('change', () => {
                        instance.clearSelection();
                        instance.refreshSoleTrader();
                    });
                }
                if (!instance.selected) {
                    return Promise.resolve();
                }
            } else if (!form.querySelector('[data-two-company-summary]:not(.text-danger)')) {
                return Promise.resolve();
            }
            return this.intent();
        }

        /** Asks the order intent and shows its notice; a later ask supersedes an earlier one. */
        intent() {
            const notice = this.doc.getElementById('two-intent-notice');
            if (!this.tile || !notice) {
                return Promise.resolve();
            }
            const seq = (this.intentSeq += 1);
            const params = {};
            new FormData(this.tile).forEach((value, key) => {
                params[key] = value;
            });
            notice.className = 'form-text';
            notice.textContent = this.config.i18n.checking || '';
            return this.api.post('intent', params)
                .then((json) => {
                    if (seq !== this.intentSeq) {
                        return;
                    }
                    const outcome = json && json.outcome;
                    notice.className = outcome === 'approved' ? 'alert alert-success' : (json && json.notice ? 'alert alert-warning' : '');
                    // The shop escapes the notice.
                    notice.innerHTML = (json && json.notice) || '';
                })
                .catch(() => {
                    if (seq === this.intentSeq) {
                        notice.className = '';
                        notice.textContent = '';
                    }
                });
        }
    }

    const api = { Api: Api, CompanySearch: CompanySearch, Checkout: Checkout, highlight: highlight, labelFor: labelFor, readConfig: readConfig };

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
        return;
    }
    const config = readConfig(root.document);
    if (!config) {
        return;
    }
    const checkout = new Checkout(config, root.document, new Api(config)).start();
    root.TwoCheckout = { tileReady: (form) => checkout.tileReady(form), settled: () => checkout.settled() };
    // The tile may have rendered before this script ran.
    const form = root.document.getElementById('form-two');
    if (form) {
        checkout.tileReady(form);
    }
}(typeof window !== 'undefined' ? window : this));
