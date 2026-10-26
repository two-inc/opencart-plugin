/**
 * The checkout capture script: company search per role, manual entry, the
 * sole-trader signup and the tile's order intent, against a scripted shop.
 */
const { Api, CompanySearch, Checkout, highlight, readConfig } = require('../../catalog/view/javascript/two_checkout.js');

const COUNTRIES = { 222: 'GB', 160: 'NO' };

function config(overrides) {
    return Object.assign({
        token: 'tok',
        in_address: true,
        urls: {
            search: 'index.php?route=s', countries: 'index.php?route=c', sole_trader: 'index.php?route=st',
            sole_trader_tokens: 'index.php?route=stt', save_company: 'index.php?route=save', intent: 'index.php?route=i'
        },
        countries: COUNTRIES,
        companies: {},
        min_length: 3,
        i18n: {
            search_hint: 'hint', searching: 'searching', no_matches: 'none', search_unavailable: 'unavailable',
            select_country: 'select country', country_not_covered: 'not covered', manual_entry: 'manual',
            back_to_search: 'back', sole_trader_entry: 'sole', sole_trader_error: 'sole error',
            sole_trader_popup_blocked: 'blocked', organization_number: 'Org', checking: 'checking'
        }
    }, overrides || {});
}

/** A shop answering by route; every call is recorded as [route, params]. */
function shop(answers) {
    const calls = [];
    const fetchImpl = jest.fn((url, init) => {
        const query = new URLSearchParams(url.split('?')[1]);
        const params = init && init.body ? Object.fromEntries(new URLSearchParams(init.body)) : Object.fromEntries(query);
        const route = query.get('route');
        delete params.route;
        calls.push([route, params]);
        const answer = typeof answers[route] === 'function' ? answers[route](params) : answers[route];
        if (answer instanceof Error) {
            return Promise.reject(answer);
        }
        return Promise.resolve({ ok: true, json: () => Promise.resolve(answer === undefined ? {} : answer) });
    });
    return { calls, fetchImpl };
}

function addressForms() {
    document.body.innerHTML = ''
        + '<form id="form-payment-address"><input id="input-payment-company"><select id="input-payment-country"><option value="222">UK</option><option value="160">Norway</option></select></form>'
        + '<form id="form-shipping-address"><input id="input-shipping-company"><select id="input-shipping-country"><option value="160">Norway</option></select></form>'
        + '<div id="two-intent-notice"></div>';
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

afterEach(() => {
    CompanySearch.coveredCountries = null;
    jest.useRealTimers();
});

describe('company search', () => {
    const items = [{ name: 'Acme Ltd', organization_number: '01234567', highlight: '<mark>Acme</mark> Ltd' }];

    test.each([
        ['ac', '222', [], 'hint', 'a term under the minimum asks nothing'],
        ['   ', '222', [], '', 'whitespace is not a term'],
        ['acme', '', [], 'select country', 'no country asks nothing and says why'],
        ['acme', '222', [['s', { two_token: 'tok', q: 'acme', country: 'GB' }]], '', 'a term and a country search that country'],
    ])('term %p in country %p', async (term, countryId, expectedCalls, status, description) => {
        addressForms();
        const select = document.getElementById('input-payment-country');
        select.insertAdjacentHTML('afterbegin', '<option value="">-</option>');
        select.value = countryId;
        const { calls, fetchImpl } = shop({ s: { items }, st: { available: false } });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), window);
        checkout.mountAddressRoles();
        await flush();
        calls.length = 0;
        await checkout.instances.payment.search(term);
        expect(calls).toEqual(expectedCalls);
        expect(checkout.instances.payment.status.textContent).toBe(status);
    });

    test('a country the search does not cover offers manual entry instead', async () => {
        addressForms();
        CompanySearch.coveredCountries = ['NO'];
        const { calls, fetchImpl } = shop({ st: { available: false } });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), window);
        checkout.mountAddressRoles();
        await flush();
        calls.length = 0;
        await checkout.instances.payment.search('acme');
        expect(calls).toEqual([]);
        expect(checkout.instances.payment.status.textContent).toBe('not covered');
    });

    test('a failed search says so, rather than reading as no match', async () => {
        addressForms();
        const { fetchImpl } = shop({ s: { error: 'unavailable' }, st: { available: false } });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), window);
        checkout.mountAddressRoles();
        await checkout.instances.payment.search('acme');
        expect(checkout.instances.payment.status.textContent).toBe('unavailable');
    });

    test('a slower, earlier answer never repaints over a later one', async () => {
        addressForms();
        const pending = {};
        const fetchImpl = jest.fn((url) => {
            const query = new URLSearchParams(url.split('?')[1]);
            if (query.get('route') !== 's') {
                return Promise.resolve({ json: () => Promise.resolve({ available: false }) });
            }
            return new Promise((resolve) => {
                pending[query.get('q')] = (body) => resolve({ json: () => Promise.resolve(body) });
            });
        });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), window);
        checkout.mountAddressRoles();
        const search = checkout.instances.payment;
        await flush();
        const first = search.search('acm');
        const second = search.search('acme');
        await flush();
        pending.acm({ items: [{ name: 'Acm Old', organization_number: '2' }] });
        await first;
        await flush();
        pending.acme({ items: [{ name: 'Acme Ltd', organization_number: '1' }] });
        await second;
        expect(Array.from(search.list.children).map((option) => option.textContent)).toEqual(['Acme Ltd1']);
    });

    test('typing is debounced into one search', async () => {
        jest.useFakeTimers();
        addressForms();
        const { calls, fetchImpl } = shop({ s: { items }, st: { available: false } });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), window);
        checkout.mountAddressRoles();
        const input = document.getElementById('input-payment-company');
        ['a', 'ac', 'acm', 'acme'].forEach((value) => {
            input.value = value;
            input.dispatchEvent(new Event('input'));
        });
        jest.advanceTimersByTime(300);
        jest.useRealTimers();
        await flush();
        await flush();
        expect(calls.filter(([route]) => route === 's').map(([, params]) => params.q)).toEqual(['acme']);
    });

    test('choosing a result fills the form field and saves it for its role only', async () => {
        addressForms();
        const { calls, fetchImpl } = shop({ s: { items }, st: { available: false }, save: { ok: true } });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), window);
        checkout.mountAddressRoles();
        await checkout.instances.payment.search('acme');
        checkout.instances.payment.list.children[0].dispatchEvent(new MouseEvent('mousedown'));
        await flush();
        expect(document.getElementById('input-payment-company').value).toBe('Acme Ltd');
        expect(document.getElementById('input-shipping-company').value).toBe('');
        expect(calls.filter(([route]) => route === 'save')).toEqual([['save', { two_token: 'tok', role: 'payment', company_name: 'Acme Ltd', organization_number: '01234567', country: 'GB', source: 'search' }]]);
        expect(checkout.config.companies).toEqual({ payment: { company_name: 'Acme Ltd', organization_number: '01234567', country_code: 'GB', source: 'search' } });
    });

    test('billing and shipping panels each get their own control and country', async () => {
        addressForms();
        const { calls, fetchImpl } = shop({ s: { items: [] }, st: { available: false } });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), window);
        checkout.mountAddressRoles();
        expect(Object.keys(checkout.instances)).toEqual(['payment', 'shipping']);
        expect(document.querySelectorAll('[data-two-role]').length).toBe(2);
        await flush();
        calls.length = 0;
        await checkout.instances.shipping.search('acme');
        expect(calls.map(([, params]) => params.country)).toEqual(['NO']);
        expect(checkout.instances.payment.status.textContent).toBe('');
    });

    test('a re-rendered address form gets a fresh control, and the old one is gone', () => {
        addressForms();
        const { fetchImpl } = shop({ st: { available: false } });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), window);
        checkout.mountAddressRoles();
        const old = checkout.instances.payment;
        document.getElementById('form-payment-address').innerHTML = '<input id="input-payment-company">';
        checkout.mountAddressRoles();
        expect(checkout.instances.payment).not.toBe(old);
        expect(document.querySelectorAll('[data-two-role="payment"]').length).toBe(1);
    });

    test('manual entry saves the typed name and number', async () => {
        addressForms();
        const { calls, fetchImpl } = shop({ st: { available: false }, save: { ok: true } });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), window);
        checkout.mountAddressRoles();
        const search = checkout.instances.payment;
        search.manualButton.click();
        expect(search.orgWrapper.hidden).toBe(false);
        jest.useFakeTimers();
        search.orgInput.value = '998877';
        ['S', 'Sm', 'Small', 'Small Shop'].forEach((value) => {
            search.input.value = value;
            search.input.dispatchEvent(new Event('input'));
        });
        search.orgInput.dispatchEvent(new Event('input'));
        jest.advanceTimersByTime(300);
        jest.useRealTimers();
        await flush();
        await flush();
        const saves = calls.filter(([route]) => route === 'save');
        expect(saves.length).toBe(1);
        expect(saves[0][1]).toMatchObject({ role: 'payment', company_name: 'Small Shop', organization_number: '998877', source: 'manual' });
    });

    test.each([
        ['<mark>Acme</mark> Ltd', 'MARK:Acme| Ltd', 'the register highlight kept'],
        ['<script>x</script>', '<script>x</script>', 'any other markup reads as text'],
        ['<mark><b>ACME</b></mark> &amp; I LTD', 'MARK:ACME| & I LTD', 'nested marks kept, the register\'s escaping decoded as text'],
        ['&lt;img src=x onerror=alert(1)&gt;', '<img src=x onerror=alert(1)>', 'decoded markup stays text'],
        ['</mark>Acme', '</mark>|Acme', 'a close tag that opens nothing reads as itself'],
    ])('highlight %p', (html, expected, description) => {
        const holder = document.createElement('span');
        holder.appendChild(highlight(document, html));
        const shape = Array.from(holder.childNodes).map((node) => (node.nodeType === 1 ? node.tagName + ':' + node.textContent : node.textContent)).join('|');
        expect([shape, holder.querySelector('script, img')]).toEqual([expected, null]);
    });
});

describe('review round 1', () => {
    test('a result without a number is looked up, and saved with the number its entry gives', async () => {
        addressForms();
        const { calls, fetchImpl } = shop({ s: { items: [{ name: 'Acme Flooring Ltd', organization_number: '', lookup_id: 'bEB0' }] }, cd: { organization_number: '07844329', addresses: [] }, st: { available: false }, save: { ok: true } });
        const cfg = config();
        cfg.urls.company_details = 'index.php?route=cd';
        const checkout = new Checkout(cfg, document, new Api(cfg, fetchImpl), window);
        checkout.mountAddressRoles();
        await checkout.instances.payment.search('acme');
        await checkout.instances.payment.choose(0);
        await flush();
        expect(calls.filter(([route]) => route === 'cd')).toEqual([['cd', { two_token: 'tok', lookup_id: 'bEB0' }]]);
        expect(calls.filter(([route]) => route === 'save').pop()[1]).toMatchObject({ company_name: 'Acme Flooring Ltd', organization_number: '07844329' });
    });

    test.each([
        [{ organization_number: '' }, 'an entry without a number'],
        [new Error('down'), 'a failed lookup'],
    ])('no number: never saved empty, the buyer is asked for it (%#)', async (answer, description) => {
        addressForms();
        const { calls, fetchImpl } = shop({ s: { items: [{ name: 'Acme Ltd', organization_number: '', lookup_id: 'L' }] }, cd: answer, st: { available: false } });
        const cfg = config({ i18n: Object.assign(config().i18n, { number_missing: 'enter number' }) });
        cfg.urls.company_details = 'index.php?route=cd';
        const checkout = new Checkout(cfg, document, new Api(cfg, fetchImpl), window);
        checkout.mountAddressRoles();
        const search = checkout.instances.payment;
        await search.search('acme');
        await search.choose(0);
        await flush();
        expect([calls.filter(([route]) => route === 'save').length, search.manual, search.orgWrapper.hidden, search.status.textContent, search.input.value]).toEqual([0, true, false, 'enter number', 'Acme Ltd']);
    });

    test('session writes run one after another, so a clear can never land after the save that follows it', async () => {
        const order = [];
        const resolvers = [];
        const fetchImpl = jest.fn((url, init) => new Promise((resolve) => {
            order.push('start ' + new URLSearchParams(init.body).get('company_name'));
            resolvers.push(() => { order.push('end'); resolve({ json: () => Promise.resolve({ ok: true }) }); });
        }));
        const api = new Api(config(), fetchImpl);
        const first = api.post('save_company', { company_name: '' });
        const second = api.post('save_company', { company_name: 'Acme' });
        await flush();
        expect(order).toEqual(['start ']);
        resolvers[0]();
        await flush();
        await flush();
        expect(order).toEqual(['start ', 'end', 'start Acme']);
        resolvers[1]();
        await Promise.all([first, second]);
    });

    test.each([
        ['Acme Ltd', '01234567', 'Acme Ltd (01234567)'],
        ['Jo Bloggs', 'TWO:ST123', 'Jo Bloggs'],
        ['Jo Bloggs', 'two:st1', 'Jo Bloggs'],
        ['Acme Ltd', '', 'Acme Ltd'],
    ])('label for %p %p: a TWO: number is never shown', (name, number, expected) => {
        const { labelFor } = require('../../catalog/view/javascript/two_checkout.js');
        expect(labelFor(name, number)).toBe(expected);
    });

    test('an enrolled sole trader with no company name takes their own name, never the TWO: number', async () => {
        addressForms();
        const tokens = { autofill_token: 'a', api_base: 'https://api.sandbox.two.inc', country: 'GB' };
        const win = { fetch: jest.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ first_name: 'Jo', last_name: 'Bloggs', organization_number: 'TWO:ST1' }) })), addEventListener: jest.fn(), removeEventListener: jest.fn() };
        const { calls, fetchImpl } = shop({ st: { available: true }, save: { ok: true } });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), win);
        checkout.mountAddressRoles();
        const search = checkout.instances.payment;
        search.tokens = tokens;
        await search.adoptEnrolledBuyer();
        await flush();
        expect([search.input.value, search.status.textContent]).toEqual(['Jo Bloggs', 'Jo Bloggs']);
        expect(calls.filter(([route]) => route === 'save').pop()[1]).toMatchObject({ company_name: 'Jo Bloggs', organization_number: 'TWO:ST1', source: 'sole_trader' });
    });

    test('in address mode with nothing captured (a saved address), the tile searches itself and asks the intent with its fields', async () => {
        document.body.innerHTML = '<form id="form-two" data-two-tile="search"><select data-two-country name="two_country"><option value="GB">UK</option></select><input data-two-company name="two_company_name"><div data-two-organization><input name="two_organization_number"></div></form><div id="two-intent-notice"></div>';
        const { calls, fetchImpl } = shop({ st: { available: false }, s: { items: [{ name: 'Acme Ltd', organization_number: '0123' }] }, save: { ok: true }, i: { outcome: 'approved', notice: 'likely' } });
        const checkout = new Checkout(config({ in_address: true }), document, new Api(config(), fetchImpl), window);
        await checkout.tileReady(document.getElementById('form-two'));
        await checkout.instances.tile.search('acme');
        await checkout.instances.tile.choose(0);
        await flush();
        await flush();
        expect(calls.filter(([route]) => route === 'save').pop()[1]).toMatchObject({ role: 'tile', company_name: 'Acme Ltd' });
        expect(calls.filter(([route]) => route === 'i').pop()[1]).toMatchObject({ two_company_name: 'Acme Ltd', two_organization_number: '0123' });
    });
});

describe('review round 2', () => {
    test('every call is queued: a search in flight finishes before a save starts', async () => {
        const order = [];
        const resolvers = {};
        const fetchImpl = jest.fn((url, init) => new Promise((resolve) => {
            const route = new URLSearchParams(url.split('?')[1]).get('route');
            order.push('start ' + route);
            resolvers[route] = () => { order.push('end ' + route); resolve({ json: () => Promise.resolve({ items: [], ok: true }) }); };
        }));
        const api = new Api(config(), fetchImpl);
        const search = api.get('search', { q: 'acme', country: 'GB' });
        const save = api.post('save_company', { company_name: 'Acme' });
        await flush();
        expect(order).toEqual(['start s']);
        resolvers.s();
        await search;
        await flush();
        expect(order).toEqual(['start s', 'end s', 'start save']);
        resolvers.save();
        await save;
    });

    test('Confirm waits for a manual entry still on its debounce and for queued saves', async () => {
        addressForms();
        const { calls, fetchImpl } = shop({ st: { available: false }, save: { ok: true } });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), window);
        checkout.mountAddressRoles();
        const search = checkout.instances.payment;
        search.manualButton.click();
        search.input.value = 'Small Shop';
        search.orgInput.value = '998877';
        search.orgInput.dispatchEvent(new Event('input'));
        await checkout.settled();
        expect(calls.filter(([route]) => route === 'save').pop()[1]).toMatchObject({ company_name: 'Small Shop', organization_number: '998877', source: 'manual' });
    });

    test.each([
        ['name', 'Small Shop', ''],
        ['number', '', '998877'],
    ])('manual entry: emptying the %s withdraws the saved company', async (field, name, number) => {
        addressForms();
        const { calls, fetchImpl } = shop({ st: { available: false }, save: { ok: true } });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), window);
        checkout.mountAddressRoles();
        const search = checkout.instances.payment;
        search.manualButton.click();
        search.input.value = 'Small Shop';
        search.orgInput.value = '998877';
        search.orgInput.dispatchEvent(new Event('input'));
        await checkout.settled();
        search.input.value = name;
        search.orgInput.value = number;
        search.orgInput.dispatchEvent(new Event('input'));
        await checkout.settled();
        expect([search.selected, calls.filter(([route]) => route === 'save').pop()[1].company_name, checkout.config.companies.payment]).toEqual([null, '', undefined]);
    });
});

describe('review round 3', () => {
    function tileForm() {
        document.body.innerHTML = '<div id="alert"></div><input id="input-payment-company"><form id="form-two" data-two-tile="address"><p data-two-company-summary>Buying for Acme</p><input name="two_project" value="Roof"></form><div id="two-intent-notice"></div><button id="go">Confirm</button>';
        return [document.getElementById('form-two'), document.getElementById('go')];
    }

    test('a double click sends one confirm, and the button is disabled at once', async () => {
        const [form, button] = tileForm();
        const { calls, fetchImpl } = shop({ confirm: { error: 'Refused.' } });
        const cfg = config();
        cfg.urls.confirm = 'index.php?route=confirm';
        const checkout = new Checkout(cfg, document, new Api(cfg, fetchImpl), window);
        const first = checkout.confirm(button, form);
        expect(button.disabled).toBe(true);
        const second = checkout.confirm(button, form);
        await Promise.all([first, second]);
        expect(calls.filter(([route]) => route === 'confirm')).toEqual([['confirm', { two_token: 'tok', two_project: 'Roof' }]]);
        expect([button.disabled, document.getElementById('alert').textContent]).toEqual([false, 'Refused.']);
    });

    test('Confirm waits for pending saves at most 15 s, then re-enables with a message and sends nothing', async () => {
        jest.useFakeTimers();
        const [form, button] = tileForm();
        const fetchImpl = jest.fn(() => new Promise(() => {}));
        const cfg = config({ i18n: Object.assign(config().i18n, { settle_timeout: 'still saving' }) });
        cfg.urls.confirm = 'index.php?route=confirm';
        const api = new Api(cfg, fetchImpl);
        const checkout = new Checkout(cfg, document, api, window);
        api.post('save_company', { company_name: 'Acme' });
        const confirming = checkout.confirm(button, form);
        await jest.advanceTimersByTimeAsync(15000);
        await confirming;
        expect([button.disabled, document.getElementById('alert').textContent]).toEqual([false, 'still saving']);
        expect(fetchImpl.mock.calls.filter(([url]) => url.includes('route=confirm')).length).toBe(0);
    });

    test.each([
        ['save_company', 20000, 'a relay call'],
        ['intent', 40000, 'the intent'],
        ['confirm', 75000, 'confirm'],
    ])('%s is abandoned after %d ms, and the queue moves on', async (name, ms) => {
        jest.useFakeTimers();
        const aborted = [];
        const fetchImpl = jest.fn((url, init) => new Promise((resolve, reject) => {
            init.signal.addEventListener('abort', () => { aborted.push(url); reject(new Error('aborted')); });
        }));
        const cfg = config();
        cfg.urls.confirm = 'index.php?route=confirm';
        const api = new Api(cfg, fetchImpl);
        const call = api.post(name, {}).catch(() => 'gave up');
        await jest.advanceTimersByTimeAsync(ms - 1);
        expect(aborted.length).toBe(0);
        await jest.advanceTimersByTimeAsync(1);
        expect(await call).toBe('gave up');
        await api.settled();
    });

    test('a search waits at most 2 s behind a slow intent; the save after it waits for both', async () => {
        jest.useFakeTimers();
        const order = [];
        const release = {};
        const fetchImpl = jest.fn((url) => new Promise((resolve) => {
            const route = new URLSearchParams(url.split('?')[1]).get('route');
            order.push('start ' + route);
            release[route] = () => { order.push('end ' + route); resolve({ json: () => Promise.resolve({ items: [] }) }); };
        }));
        const api = new Api(config(), fetchImpl);
        api.post('intent', {});
        api.get('search', { q: 'acme', country: 'GB' });
        api.post('save_company', { company_name: 'Acme' });
        await jest.advanceTimersByTimeAsync(1999);
        expect(order).toEqual(['start i']);
        await jest.advanceTimersByTimeAsync(1);
        expect(order).toEqual(['start i', 'start s']);
        release.s();
        await jest.advanceTimersByTimeAsync(0);
        expect(order).toEqual(['start i', 'start s', 'end s']);
        release.i();
        await jest.advanceTimersByTimeAsync(0);
        expect(order).toEqual(['start i', 'start s', 'end s', 'end i', 'start save']);
        release.save();
        await api.settled();
    });

    test('settled() also waits for the intent a choice asks after its save', async () => {
        const [form] = tileForm();
        addressForms();
        document.body.appendChild(form);
        const order = [];
        const { fetchImpl } = shop({ st: { available: false }, save: () => { order.push('save'); return { ok: true }; }, i: () => { order.push('intent'); return { outcome: 'approved', notice: 'ok' }; } });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), window);
        checkout.mountAddressRoles();
        checkout.tile = form;
        checkout.instances.payment.select({ company_name: 'Acme', organization_number: '1', country_code: 'GB', source: 'search' });
        await checkout.settled();
        order.push('settled');
        expect(order).toEqual(['save', 'intent', 'settled']);
    });
});

describe('sole trader', () => {
    test.each([
        [{ available: true }, false, 'offered where the registry lists sole traders'],
        [{ available: false }, true, 'hidden in a business-only country'],
        [new Error('down'), true, 'hidden when the registry cannot be asked'],
    ])('availability %p', async (answer, hidden, description) => {
        addressForms();
        const { fetchImpl } = shop({ st: answer });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), window);
        checkout.mountAddressRoles();
        await checkout.instances.payment.refreshSoleTrader();
        expect(checkout.instances.payment.soleTraderButton.hidden).toBe(hidden);
    });

    test('signup: tokens, the popup, Two\'s ACCEPTED message, then the enrolled buyer is adopted', async () => {
        addressForms();
        const popup = { location: { href: '' }, close: jest.fn() };
        const win = { open: jest.fn(() => popup), btoa: window.btoa.bind(window), addEventListener: jest.fn(), removeEventListener: jest.fn(), fetch: jest.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ company_name: 'Jo Bloggs', organization_number: 'TWO:ST1' }) })) };
        const tokens = { delegation_token: 'd', autofill_token: 'a', signup_url: 'https://checkout.sandbox.two.inc/soletrader/signup', api_base: 'https://api.sandbox.two.inc', country: 'GB' };
        const { calls, fetchImpl } = shop({ st: { available: true }, stt: tokens, save: { ok: true } });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), win);
        checkout.mountAddressRoles();
        const search = checkout.instances.payment;
        await search.startSoleTrader();
        expect(popup.location.href).toMatch(/^https:\/\/checkout\.sandbox\.two\.inc\/soletrader\/signup\?businessToken=d&autofillToken=a&autofillData=/);
        const handler = win.addEventListener.mock.calls.find(([type]) => type === 'message')[1];
        handler({ origin: 'https://evil.example', data: 'ACCEPTED', source: popup });
        expect(win.fetch).not.toHaveBeenCalled();
        handler({ origin: 'https://checkout.sandbox.two.inc', data: 'ACCEPTED', source: popup });
        await flush();
        await flush();
        expect(win.fetch).toHaveBeenCalledWith('https://api.sandbox.two.inc/autofill/v1/buyer/current', { credentials: 'include', headers: { 'two-delegated-authority-token': 'a' } });
        expect(calls.filter(([route]) => route === 'save').pop()[1]).toMatchObject({ role: 'payment', company_name: 'Jo Bloggs', organization_number: 'TWO:ST1', country: 'GB', source: 'sole_trader' });
    });

    test.each([
        [null, {}, 'blocked', 'a blocked popup says so'],
        [{ location: {}, close: jest.fn() }, { error: 'x' }, 'sole error', 'a failed mint closes the popup and says so'],
    ])('signup failure', async (popup, answer, status, description) => {
        addressForms();
        const win = { open: jest.fn(() => popup), addEventListener: jest.fn(), removeEventListener: jest.fn() };
        const { fetchImpl } = shop({ st: { available: true }, stt: answer });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), win);
        checkout.mountAddressRoles();
        await checkout.instances.payment.startSoleTrader();
        expect(checkout.instances.payment.status.textContent).toBe(status);
    });
});

describe('order intent from the tile', () => {
    function tile(mode, inner) {
        document.body.innerHTML = '<input id="input-payment-company"><form id="form-two" data-two-tile="' + mode + '">' + inner + '<input name="two_project" value="Roof"></form><div id="two-intent-notice"></div>';
        return document.getElementById('form-two');
    }

    test.each([
        ['address', '<p data-two-company-summary>Buying for Acme</p>', { outcome: 'approved', notice: 'likely' }, 1, 'alert alert-success', 'likely', 'a captured company is asked about straight away'],
        ['address', '<p data-two-company-summary>Buying for Acme</p>', { outcome: 'declined', notice: 'cannot' }, 1, 'alert alert-warning', 'cannot', 'a decline is shown'],
        ['address', '<p data-two-company-summary>Buying for Acme</p>', { outcome: 'undecided', notice: '' }, 1, '', '', 'no decision shows nothing'],
        ['address', '<p class="text-danger" data-two-company-summary>Search first</p>', {}, 0, '', '', 'no company yet: nothing asked'],
    ])('%s tile', async (mode, inner, answer, asked, className, text, description) => {
        const form = tile(mode, inner);
        const { calls, fetchImpl } = shop({ i: answer });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), window);
        await checkout.tileReady(form);
        expect(calls.filter(([route]) => route === 'i').length).toBe(asked);
        const notice = document.getElementById('two-intent-notice');
        expect([notice.className, notice.textContent]).toEqual([className, text]);
    });

    test('in the tile, choosing a company asks the intent with the tile\'s fields', async () => {
        const form = tile('search', '<select data-two-country name="two_country"><option value="GB">UK</option></select><input data-two-company name="two_company_name"><div data-two-organization><input name="two_organization_number"></div>');
        const { calls, fetchImpl } = shop({ st: { available: false }, s: { items: [{ name: 'Acme Ltd', organization_number: '0123' }] }, save: { ok: true }, i: { outcome: 'approved', notice: 'likely' } });
        const checkout = new Checkout(config({ in_address: false }), document, new Api(config(), fetchImpl), window);
        await checkout.tileReady(form);
        expect(calls.filter(([route]) => route === 'i').length).toBe(0);
        await checkout.instances.tile.search('acme');
        checkout.instances.tile.list.children[0].dispatchEvent(new MouseEvent('mousedown'));
        await flush();
        await flush();
        expect(calls.filter(([route]) => route === 'i').pop()[1]).toMatchObject({ two_company_name: 'Acme Ltd', two_organization_number: '0123', two_country: 'GB', two_project: 'Roof' });
    });
});

test.each([
    ['<script type="application/json" id="two-checkout-config">{"token":"t"}</script>', { token: 't' }, 'the island read'],
    ['<script type="application/json" id="two-checkout-config">{oops</script>', null, 'an unreadable island is none'],
    ['', null, 'no island, nothing to run'],
])('config %#', (html, expected, description) => {
    document.body.innerHTML = html;
    expect(readConfig(document)).toEqual(expected);
});
