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
 * - The payment term chips in the tile (section 4), an ARIA radiogroup: a
 *   choice is saved to the shop and the order totals reloaded, so the
 *   payment terms fee follows the term. Both go through the call queue, so
 *   Confirm waits for them.
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


    /** How long a relay call may take before it is abandoned, by call (the shop's own calls to Two time out at 30 s; confirm makes two). */
    const TIMEOUTS = { intent: 40000, confirm: 75000 };
    const DEFAULT_TIMEOUT = 20000;
    /** How long a call waits for the queue ahead of it before it runs anyway, by call. */
    const QUEUE_CAPS = { search: 2000, company_details: 2000 };
    /** How long Confirm waits for pending saves before it gives the buyer a message instead. */
    const SETTLE_CAP = 15000;

    function wait(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    /** The shop's relay: every call carries the session's token, and none runs forever. */
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
         * one's change. A search waits at most its cap (it writes nothing of
         * its own, so overlapping a slow intent loses nothing), and the calls
         * after it still wait for everything before them.
         */
        serial(name, call) {
            const before = this.queue;
            const start = QUEUE_CAPS[name] ? Promise.race([before, wait(QUEUE_CAPS[name])]) : before;
            const run = start.then(call, call);
            this.queue = Promise.all([before, run.catch(() => null)]).then(() => null, () => null);
            return run;
        }

        /** One request, abandoned after its timeout or when the caller's signal aborts. */
        timed(name, url, init, signal) {
            const controller = typeof AbortController === 'function' ? new AbortController() : null;
            const timer = setTimeout(() => controller && controller.abort(), TIMEOUTS[name] || DEFAULT_TIMEOUT);
            if (signal && controller) {
                signal.addEventListener('abort', () => controller.abort());
            }
            return this.fetch(url, Object.assign({ credentials: 'same-origin', signal: controller ? controller.signal : undefined }, init))
                .then((response) => response.json())
                .finally(() => clearTimeout(timer));
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
            return this.serial(name, () => this.timed(name, this.url(name, params), {}, signal));
        }

        post(name, params) {
            const body = new URLSearchParams(Object.assign({ two_token: this.config.token }, params || {}));
            return this.serial(name, () => this.timed(name, this.config.urls[name], {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }));
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
            // Kept as pending, so settled() also waits for the intent this choice asks.
            this.pending = this.save(company).then(() => (this.selected === company ? this.onChange(company, this.role) : null));
            return this.pending;
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
            return Promise.resolve(this.pending).catch(() => null);
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
         * Resolves once every pending save, and the intent each choice asks
         * after its save, has reached the shop, so placing the order reads the
         * company the buyer last gave.
         */
        settled() {
            const pending = Object.keys(this.instances).map((role) => this.instances[role].flush());
            return Promise.all(pending).then(() => this.api.settled());
        }

        /**
         * Places the order: one confirm however often the button is pressed.
         * The button is disabled at once; Confirm waits for pending saves for
         * at most SETTLE_CAP, and goes through the same queue as every call.
         */
        confirm(button, form) {
            if (this.confirming) {
                return Promise.resolve();
            }
            this.confirming = true;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            const reset = (message) => {
                this.confirming = false;
                button.disabled = false;
                button.removeAttribute('aria-busy');
                if (message) {
                    this.alert(message);
                }
            };
            const capped = Promise.race([this.settled().then(() => true), wait(SETTLE_CAP).then(() => false)]);
            return capped.then((settled) => {
                if (!settled) {
                    reset(this.config.i18n.settle_timeout);
                    return null;
                }
                const params = {};
                new FormData(form).forEach((value, key) => {
                    params[key] = value;
                });
                return this.api.post('confirm', params).then((json) => {
                    if (json && json.redirect) {
                        this.win.location = json.redirect;
                        return;
                    }
                    // The shop escapes its notice.
                    reset('');
                    if (json && json.error) {
                        this.alert(json.error, true);
                    }
                });
            }).catch(() => reset(this.config.i18n.confirm_failed));
        }

        /** A dismissible notice where OpenCart shows its own. */
        alert(message, escaped) {
            const host = this.doc.getElementById('alert');
            if (!host) {
                return;
            }
            const box = this.doc.createElement('div');
            box.className = 'alert alert-danger alert-dismissible';
            box.setAttribute('role', 'alert');
            if (escaped) {
                box.innerHTML = message;
            } else {
                box.textContent = message;
            }
            const close = this.doc.createElement('button');
            close.type = 'button';
            close.className = 'btn-close';
            close.setAttribute('data-bs-dismiss', 'alert');
            box.appendChild(close);
            host.prepend(box);
        }

        /** The payment tile has rendered (Two is the chosen method). */
        tileReady(form) {
            this.tile = form;
            const group = form.querySelector('[data-two-term-chips]');
            if (group) {
                this.terms = new TermChips({
                    group: group,
                    api: this.api,
                    config: this.config,
                    doc: this.doc,
                    alert: (message, escaped) => this.alert(message, escaped),
                    payIn: form.querySelector('[data-two-pay-in]'),
                    endOfMonth: Boolean(this.config.end_of_month)
                }).start();
            }
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

    /** How long arrow-key traversal waits before saving, so a run of keystrokes saves once. */
    const TERM_SAVE_DELAY_MS = 250;

    /**
     * The payment term chips (Build Guide section 4): one radio per offered
     * term in a radiogroup, one tab stop on the chosen chip, arrow keys,
     * Home and End moving the choice (the W3C radio group pattern). A single
     * term is shown, not chosen. Each chip shows the fee its term would add,
     * once the shop has quoted it; a term whose quote fails shows none.
     */
    class TermChips {
        constructor(options) {
            this.group = options.group;
            this.api = options.api;
            this.config = options.config;
            this.doc = options.doc;
            this.alert = options.alert || (() => {});
            this.payIn = options.payIn || null;
            this.endOfMonth = Boolean(options.endOfMonth);
            this.timer = null;
            this.chips = Array.prototype.slice.call(this.group.querySelectorAll('[data-two-term]'));
            const checked = this.chips.find((chip) => chip.getAttribute('aria-checked') === 'true');
            this.saved = checked ? Number(checked.dataset.twoTerm) : null;
        }

        start() {
            if (this.chips.length > 1) {
                this.chips.forEach((chip) => chip.addEventListener('click', () => this.choose(Number(chip.dataset.twoTerm))));
                this.group.addEventListener('keydown', (event) => this.key(event));
            }
            this.fees();
            return this;
        }

        key(event) {
            if (event.altKey || event.ctrlKey || event.metaKey) {
                return;
            }
            const current = Math.max(this.chips.indexOf(this.doc.activeElement), 0);
            const last = this.chips.length - 1;
            const next = { ArrowRight: current + 1, ArrowDown: current + 1, ArrowLeft: current - 1, ArrowUp: current - 1, Home: 0, End: last }[event.key];
            if (next === undefined) {
                return;
            }
            event.preventDefault();
            const chip = this.chips[(next + this.chips.length) % this.chips.length];
            chip.focus();
            this.schedule(Number(chip.dataset.twoTerm), TERM_SAVE_DELAY_MS);
        }

        /** One predicate sets the tick, the state and the tab stop, so they cannot drift apart. */
        select(days) {
            this.chips.forEach((chip) => {
                const on = Number(chip.dataset.twoTerm) === days;
                chip.setAttribute('aria-checked', on ? 'true' : 'false');
                chip.tabIndex = on ? 0 : -1;
                chip.classList.toggle('btn-primary', on);
                chip.classList.toggle('btn-outline-primary', !on);
            });
            if (this.payIn) {
                const copy = this.endOfMonth ? this.config.i18n.pay_in_eom : this.config.i18n.pay_in;
                this.payIn.textContent = (copy || '').replace('%d', String(days));
            }
        }

        choose(days) {
            return this.schedule(days, 0);
        }

        /**
         * Shows the term at once and saves it after $delay (a run of arrow
         * keys saves once). The save takes its place in the call queue the
         * moment the choice is made, holding it until the delay ends, so a
         * Confirm pressed meanwhile waits and places the term on screen.
         */
        schedule(days, delay) {
            this.select(days);
            this.next = days;
            clearTimeout(this.timer);
            if (!this.pending) {
                let release;
                const held = new Promise((resolve) => {
                    release = resolve;
                });
                this.release = release;
                this.pending = this.api.serial('term', () => held.then(() => {
                    this.pending = null;
                    return this.save(this.next);
                }));
            }
            this.timer = setTimeout(() => this.release(), delay);
            return this.pending;
        }

        /**
         * Saves the term, then reloads the totals, from inside the queue
         * (calls made here go straight to the shop, not to the back of the
         * queue). A failed save re-syncs the chips and the totals with the
         * shop's last saved term, unless a later choice is already waiting:
         * that one then saves, and the chips and totals follow it.
         */
        save(days) {
            const body = new URLSearchParams({ two_token: this.config.token, days: String(days) }).toString();
            const failed = (message) => {
                if (message) {
                    this.alert(message, true);
                }
                if (this.pending || this.saved === null) {
                    return null;
                }
                this.select(this.saved);
                return this.totals().catch(() => null);
            };
            return this.api.timed('term', this.config.urls.term, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body }).then((json) => {
                if (!json || !json.ok) {
                    return failed(json && json.error);
                }
                this.saved = days;
                // The term is saved: a totals reload that fails is tried once more, then the buyer is told.
                return this.totals().catch(() => this.totals()).catch(() => this.alert(this.config.i18n.totals_failed || '', false));
            }, () => failed(''));
        }

        /**
         * Rewrites the order's totals for the new term: the checkout's own
         * confirm view writes the order and renders its totals, of which only
         * the totals rows are swapped in, so nothing the buyer typed is lost.
         * Runs inside the term save's place in the queue.
         */
        totals() {
            const url = this.config.urls.totals;
            if (!url) {
                return Promise.resolve();
            }
            return this.api.fetch(url, { credentials: 'same-origin' }).then((response) => response.text()).then((html) => {
                const parsed = this.doc.createElement('template');
                parsed.innerHTML = html;
                const fresh = parsed.content.querySelector('tfoot');
                const host = this.doc.getElementById('checkout-confirm');
                const stale = host ? host.querySelector('tfoot') : null;
                if (fresh && stale) {
                    stale.replaceWith(this.doc.importNode(fresh, true));
                }
            });
        }

        /** Fills each chip's fee from the shop's quotes; the shop escapes them. */
        fees() {
            if (!this.config.urls.fees) {
                return Promise.resolve();
            }
            return this.api.get('fees').then((json) => {
                const fees = (json && json.fees) || {};
                this.chips.forEach((chip) => {
                    const slot = chip.querySelector('[data-two-term-fee]');
                    const fee = fees[chip.dataset.twoTerm];
                    if (slot) {
                        slot.innerHTML = fee || '';
                    }
                    const name = chip.getAttribute('aria-label');
                    if (fee && name && slot) {
                        chip.setAttribute('aria-label', name + ', ' + slot.textContent);
                    }
                });
            }).catch(() => null);
        }
    }

    const api = { Api: Api, TermChips: TermChips, CompanySearch: CompanySearch, Checkout: Checkout, highlight: highlight, labelFor: labelFor, readConfig: readConfig };

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
        return;
    }
    const config = readConfig(root.document);
    if (!config) {
        return;
    }
    const checkout = new Checkout(config, root.document, new Api(config)).start();
    root.TwoCheckout = { tileReady: (form) => checkout.tileReady(form), settled: () => checkout.settled(), confirm: (button, form) => checkout.confirm(button, form) };
    // The tile may have rendered before this script ran.
    const form = root.document.getElementById('form-two');
    if (form) {
        checkout.tileReady(form);
    }
}(typeof window !== 'undefined' ? window : this));
