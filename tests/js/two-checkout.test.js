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
        const first = search.search('acm');
        const second = search.search('acme');
        pending.acme({ items: [{ name: 'Acme Ltd', organization_number: '1' }] });
        pending.acm({ items: [{ name: 'Acm Old', organization_number: '2' }] });
        await Promise.all([first, second]);
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
        search.input.value = 'Small Shop';
        search.orgInput.value = '998877';
        search.orgInput.dispatchEvent(new Event('change'));
        await flush();
        expect(calls.filter(([route]) => route === 'save').pop()[1]).toMatchObject({ role: 'payment', company_name: 'Small Shop', organization_number: '998877', source: 'manual' });
    });

    test.each([
        ['<mark>Acme</mark> Ltd', 'MARK:Acme| Ltd', 'the register highlight kept'],
        ['<script>x</script>', '<script>x</script>', 'any other markup reads as text'],
        ['</mark>Acme', '</mark>|Acme', 'a close tag that opens nothing reads as itself'],
    ])('highlight %p', (html, expected, description) => {
        const holder = document.createElement('span');
        holder.appendChild(highlight(document, html));
        const shape = Array.from(holder.childNodes).map((node) => (node.nodeType === 1 ? node.tagName + ':' + node.textContent : node.textContent)).join('|');
        expect([shape, holder.querySelector('script')]).toEqual([expected, null]);
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
