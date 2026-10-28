/**
 * The OpenCart host for the shared company search (TWO-26721): placement,
 * the tile rule, the transport, saved captures, manual entry and the address
 * autofill, over the vendored modules as they ship.
 */
require('../../catalog/view/javascript/two/company-identity.js');
require('../../catalog/view/javascript/two/company-search-panel.js');
require('../../catalog/view/javascript/two/sole-trader.js');
require('../../catalog/view/javascript/two/company-capture-component.js');
const { Api } = require('../../catalog/view/javascript/two_checkout.js');
const { mount, PANEL_COPY } = require('../../catalog/view/javascript/two/company-search-host.js');

const I18N = {
    chip_registered: 'Registrert firma', chip_sole_trader: 'Enkeltpersonforetak', chip_manual: 'Skriv inn manuelt',
    search_aria: 'Søk', search_too_short: 'Minst 3', no_matches: 'Ingen treff', search_unavailable: 'Nede',
    select_country: 'Velg land', results_one: '1 treff', results_many: '%d treff', company_number: 'Org.nr',
    select_different_sole_trader: 'Velg en annen', sole_trader_prompt: 'Klikk her', sole_trader_error: 'Feil',
    address_unavailable: 'Fant ikke adressen'
};

function config(overrides) {
    return Object.assign({
        token: 'tok',
        in_address: true,
        address_lookup: true,
        checkout_api_url: 'https://api.example', checkout_page_url: 'https://checkout.example',
        urls: { search: 'index.php?route=s', countries: 'index.php?route=c', sole_trader: 'index.php?route=st', sole_trader_tokens: 'index.php?route=stt', save_company: 'index.php?route=save', company_details: 'index.php?route=d' },
        countries: { 222: 'GB', 160: 'NO' },
        companies: {},
        i18n: I18N
    }, overrides || {});
}

/** A shop answering by route; every call is recorded as [route, params]. An answer of { status } is that HTTP status. */
function shop(answers) {
    const calls = [];
    const fetchImpl = jest.fn((url, init) => {
        const query = new URLSearchParams(String(url).split('?')[1]);
        const params = init && typeof init.body === 'string' && init.body[0] !== '{' ? Object.fromEntries(new URLSearchParams(init.body)) : Object.fromEntries(query);
        const route = query.get('route');
        delete params.route;
        calls.push([route, params]);
        const fallback = { c: { ok: true, status: 200, body: { supported_countries: ['GB', 'NO'] } }, st: ['LIMITED_COMPANY'], save: { ok: true } }[route];
        const answer = route in answers ? (typeof answers[route] === 'function' ? answers[route](params) : answers[route]) : fallback;
        const status = answer && answer.status && Object.keys(answer).length === 1 ? answer.status : 200;
        return Promise.resolve({ ok: status === 200, status: status, headers: { get: () => null }, json: () => Promise.resolve(answer === undefined ? {} : answer) });
    });
    window.fetch = fetchImpl;
    return { calls, fetchImpl };
}

const ADDRESS = (role) => '<form id="form-' + role + '-address">'
    + '<div><input id="input-' + role + '-company"></div>'
    + '<input id="input-' + role + '-address-1"><input id="input-' + role + '-address-2"><input id="input-' + role + '-city"><input id="input-' + role + '-postcode">'
    + '<select id="input-' + role + '-country"><option value="222">UK</option><option value="160">Norway</option></select>'
    + '<select id="input-' + role + '-zone"><option value="1">Select</option><option value="2">Greater London</option></select></form>';

const TILE = '<form id="form-two"><div><input id="input-two-company" data-two-country="GB"></div></form>';

const flush = async () => {
    for (let i = 0; i < 5; i += 1) await new Promise((resolve) => setTimeout(resolve, 0));
};

let live = [];

function start(cfg, answers) {
    const recorded = shop(answers || {});
    const companies = [];
    const hosts = mount({ config: cfg, api: new Api(cfg, recorded.fetchImpl), doc: document, alert: () => {}, onCompany: (role, company) => companies.push([role, company]) });
    live = live.concat(Object.values(hosts));
    return Object.assign(recorded, { hosts, companies });
}

const wrapped = (id) => document.getElementById(id).parentElement.classList.contains('two-company-field-wrap');

afterEach(() => {
    live.forEach((host) => host.stop());
    live = [];
    document.body.innerHTML = '';
    jest.useRealTimers();
});

describe('placement', () => {
    test.each([
        [true, ['payment', 'shipping'], { 'input-payment-company': true, 'input-shipping-company': true, 'input-two-company': false }, 'address placement: one control per address form, the tile field left plain'],
        [false, ['tile'], { 'input-payment-company': false, 'input-shipping-company': false, 'input-two-company': true }, 'tile placement: the tile field only, the address fields left plain'],
    ])('in_address %s', async (inAddress, roles, mounted, description) => {
        document.body.innerHTML = ADDRESS('payment') + ADDRESS('shipping') + TILE;
        const { hosts } = start(config({ in_address: inAddress }));
        await flush();
        const seen = Object.fromEntries(Object.keys(mounted).map((id) => [id, wrapped(id)]));
        expect([Object.keys(hosts), seen, description]).toEqual([roles, mounted, description]);
    });

    test.each([
        [{ payment: { company_name: 'Acme Ltd', organization_number: '0123', country_code: 'GB', source: 'search' } }, 'a capture in the address forms'],
        [{}, 'nothing captured anywhere'],
    ])('the tile control follows the setting alone: %s', async (companies, description) => {
        document.body.innerHTML = ADDRESS('payment') + TILE;
        start(config({ in_address: true, companies: companies }));
        await flush();
        expect([wrapped('input-two-company'), description]).toEqual([false, description]);
    });

    test.each([
        [true, ['registered', 'manual'], 'address placement offers manual entry'],
        [false, ['registered'], 'the tile never does'],
    ])('chips with in_address %s', async (inAddress, chips, description) => {
        document.body.innerHTML = ADDRESS('payment') + TILE;
        start(config({ in_address: inAddress }));
        await flush();
        const field = document.getElementById(inAddress ? 'input-payment-company' : 'input-two-company');
        field.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
        field.focus();
        await flush();
        const panel = field.parentElement.querySelector('.two-company-dropdown');
        const visible = Array.from(panel.querySelectorAll('.two-company-mode-chip')).filter((chip) => !chip.classList.contains('two-hidden'));
        expect([visible.map((chip) => chip.getAttribute('data-two-chip')), visible.map((chip) => chip.textContent), description])
            .toEqual([chips, chips.map((mode) => ({ registered: 'Registrert firma', manual: 'Skriv inn manuelt' })[mode]), description]);
    });

    test('the address edit form gets one control on its own company field', async () => {
        document.body.innerHTML = '<form id="form-address"><div><input id="input-company"></div><select id="input-country"><option value="222">UK</option></select></form>';
        const { hosts } = start(config({ roles: ['account'] }));
        await flush();
        expect([Object.keys(hosts), wrapped('input-company'), hosts.account.country()]).toEqual([['account'], true, 'GB']);
    });
});

describe('transport', () => {
    const term = (host, value, country) => host.transport.searchCompanies({ term: value, getCountryCode: () => country });

    test.each([
        [{ items: [{ name: 'Acme Ltd', organization_number: '0123', highlight: '<mark>Acme</mark> Ltd', lookup_id: 'L1' }] }, 'GB', { items: [{ text: 'Acme Ltd', html: '<mark>Acme</mark> Ltd (0123)', companyId: '0123', lookupId: 'L1' }] }, 'a row carries its number in brackets'],
        [{ items: [{ name: 'Jo Bloggs', organization_number: 'TWO:ST1', highlight: '', lookup_id: '' }] }, 'GB', { items: [{ text: 'Jo Bloggs', html: 'Jo Bloggs', companyId: 'TWO:ST1', lookupId: '' }] }, 'a TWO: number is never shown'],
        [{ error: 'unavailable' }, 'GB', { unavailable: true }, 'the relay failing reads as unavailable, not as no match'],
        [{ items: [], degraded: true }, 'GB', { unavailable: true }, 'a degraded answer is unavailable'],
        [{ error: 'Too many', code: 'rate_limited' }, 'GB', { unavailable: true }, 'the shop\'s rate limit is unavailable'],
        [{ items: [] }, '', { items: [] }, 'no country: nothing sent'],
    ])('%#', async (answer, country, expected, description) => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts, calls } = start(config(), { s: answer });
        await flush();
        expect([await term(hosts.payment, 'acme', country), description]).toEqual([expected, description]);
        expect(calls.filter(([route]) => route === 's').length).toBe(country ? 1 : 0);
    });

    test('a 429 parks this instance alone, and an answer is cached per country and term', async () => {
        document.body.innerHTML = ADDRESS('payment') + ADDRESS('shipping');
        let status = 429;
        const { hosts, calls } = start(config(), { s: () => (status === 429 ? { error: 'Too many', code: 'rate_limited' } : { items: [] }) });
        await flush();
        await term(hosts.payment, 'acme', 'GB');
        status = 200;
        expect(await term(hosts.payment, 'acme', 'GB')).toEqual({ unavailable: true });
        await term(hosts.shipping, 'acme', 'GB');
        await term(hosts.shipping, 'acme', 'GB');
        expect(calls.filter(([route]) => route === 's').length).toBe(2);
    });

    test('a search in flight holds back the save a pick makes, so its write-back cannot undo the pick', async () => {
        document.body.innerHTML = ADDRESS('payment');
        let answer;
        const { hosts, calls } = start(config({ address_lookup: false }), { s: () => new Promise((resolve) => { answer = resolve; }) });
        await flush();
        const searching = term(hosts.payment, 'acme', 'GB');
        await flush();
        hosts.payment.component.selectCompany({ text: 'Acme Ltd', companyId: '0123', lookupId: '' });
        await flush();
        const order = () => calls.map(([route]) => route).filter((route) => route === 's' || route === 'save');
        expect(order()).toEqual(['s']);
        answer({ items: [] });
        await searching;
        await hosts.payment.flush();
        expect(order()).toEqual(['s', 'save']);
    });

    test.each([
        ['GB', 'Ingen treff'],
        ['', 'Velg land'],
    ])('the empty answer in country "%s" reads "%s"', async (country, message) => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts } = start(config({ countries: country ? { 222: 'GB' } : {} }));
        await flush();
        expect(hosts.payment.transport.noResultsMessage()).toBe(message);
    });

    test.each(Object.keys(PANEL_COPY))('the panel string "%s" is translated', (text) => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts } = start(config());
        expect(hosts.payment.translate(text)).not.toBe(text);
    });
});

describe('selection, saved captures and manual entry', () => {
    const pick = (host, item) => host.component.selectCompany(Object.assign({ text: 'Acme Ltd', companyId: '0123', lookupId: '' }, item));

    test('a pick is saved for its role only, with its number and country', async () => {
        document.body.innerHTML = ADDRESS('payment') + ADDRESS('shipping');
        const { hosts, calls, companies } = start(config({ address_lookup: false }));
        await flush();
        pick(hosts.payment);
        await hosts.payment.flush();
        await flush();
        expect(calls.filter(([route]) => route === 'save')).toEqual([['save', { two_token: 'tok', role: 'payment', company_name: 'Acme Ltd', organization_number: '0123', country: 'GB', source: 'search' }]]);
        expect(companies.pop()[0]).toBe('payment');
    });

    test('a row with no number takes it from the details call', async () => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts, calls } = start(config({ address_lookup: false }), { d: { organization_number: '999', addresses: [] } });
        await flush();
        pick(hosts.payment, { companyId: '', lookupId: 'L1' });
        await flush();
        await hosts.payment.flush();
        expect(calls.filter(([route]) => route === 'save').pop()[1]).toMatchObject({ company_name: 'Acme Ltd', organization_number: '999' });
    });

    test('a capture the session holds is restored into the field and the identity', async () => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts, calls } = start(config({ companies: { payment: { company_name: 'Acme Ltd', organization_number: '0123', country_code: 'GB', source: 'search' } } }));
        await flush();
        await hosts.payment.flush();
        expect([document.getElementById('input-payment-company').value, hosts.payment.identity.companyId(), calls.filter(([route]) => route === 'save').length]).toEqual(['Acme Ltd', '0123', 0]);
        expect(document.querySelector('.two-company-id-text').textContent).toBe('0123');
    });

    test('manual entry is name only: the typed name is saved with no number', async () => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts, calls } = start(config());
        await flush();
        hosts.payment.component.manualEntryMode();
        const field = document.getElementById('input-payment-company');
        field.value = 'Typed Ltd';
        field.dispatchEvent(new Event('input', { bubbles: true }));
        await hosts.payment.flush();
        await flush();
        expect(field.getAttribute('role')).toBe(null);
        expect(calls.filter(([route]) => route === 'save').pop()[1]).toMatchObject({ company_name: 'Typed Ltd', organization_number: '', source: 'manual' });
    });
});

describe('address autofill', () => {
    const DETAILS = { organization_number: '0123', addresses: [{ type: 'POSTAL', street_address: 'PO Box 1', city: 'Nowhere', postal_code: 'X1' }, { type: 'REGISTERED', street_address: '1 High St', city: 'London', postal_code: 'EC1A 1AA', region: 'greater london' }] };
    const fields = (role) => ['address-1', 'city', 'postcode', 'zone'].map((suffix) => document.getElementById('input-' + role + '-' + suffix).value);

    test.each([
        [true, true, ['1 High St', 'London', 'EC1A 1AA', '2'], 'on: the registered address goes into this form, the zone matched by name'],
        [true, false, ['', '', '', '1'], 'the merchant switched it off'],
        [false, true, null, 'the tile has no address to fill'],
    ])('in_address %s, address_lookup %s', async (inAddress, lookup, expected, description) => {
        document.body.innerHTML = ADDRESS('payment') + ADDRESS('shipping') + TILE;
        const { hosts } = start(config({ in_address: inAddress, address_lookup: lookup }), { d: DETAILS });
        await flush();
        const host = hosts.payment || hosts.tile;
        await host.component.selectCompany({ text: 'Acme Ltd', companyId: '0123', lookupId: 'L1' });
        await flush();
        expect([expected ? fields('payment') : fields('payment').concat(fields('shipping')), description])
            .toEqual([expected || ['', '', '', '1', '', '', '', '1'], description]);
        expect(fields('shipping')).toEqual(['', '', '', '1']);
    });

    test('a details failure shows the address notice under the field', async () => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts } = start(config(), { d: { error: 'unavailable' } });
        await flush();
        await hosts.payment.component.selectCompany({ text: 'Acme Ltd', companyId: '0123', lookupId: 'L1' });
        await flush();
        const notice = document.querySelector('.two-company-address-notice');
        expect([notice.getAttribute('role'), notice.textContent]).toEqual(['alert', 'Fant ikke adressen']);
    });

    test('a country change clears only the fields still holding what was written', async () => {
        document.body.innerHTML = ADDRESS('payment');
        const { hosts } = start(config(), { d: DETAILS });
        await flush();
        await hosts.payment.component.selectCompany({ text: 'Acme Ltd', companyId: '0123', lookupId: 'L1' });
        await flush();
        document.getElementById('input-payment-city').value = 'Edited';
        const country = document.getElementById('input-payment-country');
        country.value = '160';
        country.dispatchEvent(new Event('change', { bubbles: true }));
        await flush();
        expect([fields('payment'), hosts.payment.identity.companyName()]).toEqual([['', 'Edited', '', ''], '']);
    });
});

describe('the tile', () => {
    const { Checkout } = require('../../catalog/view/javascript/two_checkout.js');
    const host = require('../../catalog/view/javascript/two/company-search-host.js');

    test('tileReady saves every capture again, forced, then names the company and asks the intent once', async () => {
        document.body.innerHTML = ADDRESS('payment') + '<form id="form-two" data-two-tile="address"><p class="text-danger" data-two-company-summary>Add your company</p></form><div id="two-intent-notice"></div>';
        const acme = { company_name: 'Acme Ltd', organization_number: '0123', country_code: 'GB', source: 'search' };
        const cfg = config({ companies: { payment: acme }, urls: Object.assign({}, config().urls, { intent: 'index.php?route=i' }), i18n: Object.assign({ company_from_address: 'Buying for %s.' }, I18N) });
        const { calls, fetchImpl } = shop({ i: { outcome: 'approved', notice: 'ok' } });
        window.TwoCompanySearchHost = host;
        const checkout = new Checkout(cfg, document, new Api(cfg, fetchImpl), window).start();
        live = live.concat(Object.values(checkout.instances));
        await flush();
        expect(calls.filter(([route]) => route === 'save').length).toBe(0);
        await checkout.tileReady(document.getElementById('form-two'));
        const routes = calls.map(([route]) => route).filter((route) => route === 'save' || route === 'i');
        expect(routes).toEqual(['save', 'save', 'i']);
        expect(calls.find(([route]) => route === 'save')[1]).toMatchObject({ role: 'payment', company_name: 'Acme Ltd', organization_number: '0123' });
        const summary = document.querySelector('[data-two-company-summary]');
        expect([summary.textContent, summary.classList.contains('text-danger')]).toEqual(['Buying for Acme Ltd (0123).', false]);
        delete window.TwoCompanySearchHost;
    });

    test('a saved address keeps its own summary: a capture in the hidden new-address form never replaces it', async () => {
        document.body.innerHTML = ADDRESS('payment') + '<form id="form-two" data-two-tile="address"><p class="text-danger" data-two-company-summary data-two-saved-address>Add your company to the address</p></form><div id="two-intent-notice"></div>';
        const beta = { company_name: 'Beta AS', organization_number: '9876', country_code: 'GB', source: 'search' };
        const cfg = config({ companies: { payment: beta }, urls: Object.assign({}, config().urls, { intent: 'index.php?route=i' }), i18n: Object.assign({ company_from_address: 'Buying for %s.' }, I18N) });
        const { calls, fetchImpl } = shop({ i: { outcome: 'approved', notice: 'ok' } });
        window.TwoCompanySearchHost = host;
        const checkout = new Checkout(cfg, document, new Api(cfg, fetchImpl), window).start();
        live = live.concat(Object.values(checkout.instances));
        await flush();
        await checkout.tileReady(document.getElementById('form-two'));
        const summary = document.querySelector('[data-two-company-summary]');
        expect([summary.textContent, summary.classList.contains('text-danger'), calls.filter(([route]) => route === 'i').length])
            .toEqual(['Add your company to the address', true, 0]);
        delete window.TwoCompanySearchHost;
    });
});

describe('sole trader', () => {
    const TOKENS = { delegation_token: 'D1', autofill_token: 'A1' };
    const POPUP = { closed: false, close() {}, focus() {} };
    // A country select with nothing chosen yet, as a guest first sees it.
    const UNCHOSEN = (role) => ADDRESS(role).replace('<option value="222">', '<option value="" selected>Select</option><option value="222">');
    const tokenCalls = (calls) => calls.filter(([route]) => route === 'stt').map(([, params]) => params);
    const prompt = () => document.querySelector('.two-sole-trader-prompt');

    // The tokens are page-level in the vendored module, so each test starts with none.
    beforeEach(() => {
        Object.assign(window.TwoSoleTrader.prototype, { delegationToken: '', autofillToken: '', _prefetch: null, _autofillBuyer: null, _prefetchOwed: false });
    });

    afterEach(() => jest.restoreAllMocks());

    const choose = (role, value) => {
        const select = document.getElementById('input-' + role + '-country');
        select.value = value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    };

    test.each([
        [ADDRESS('shipping'), () => {}, 'guest: the shipping form alone, fresh'],
        [ADDRESS('shipping'), (hosts) => hosts.shipping.component.selectCompany({ text: 'Acme Ltd', companyId: '0123', lookupId: '' }), 'guest: after a registered pick'],
        [UNCHOSEN('shipping'), () => choose('shipping', '222'), 'guest: no country chosen at load, then the UK'],
        [UNCHOSEN('payment') + ADDRESS('shipping'), () => {}, 'a payment form with no country beside a UK shipping form'],
    ])('the first click opens the popup, case %#', async (dom, before, description) => {
        document.body.innerHTML = dom;
        const open = jest.spyOn(window, 'open').mockReturnValue(POPUP);
        const { hosts, calls } = start(config({ address_lookup: false }), { stt: TOKENS, st: ['LIMITED_COMPANY', 'SOLE_TRADER'] });
        await flush();
        before(hosts);
        await flush();
        hosts.shipping.component.soleTraderMode();
        const opened = open.mock.calls.map(([url]) => /businessToken=D1&autofillToken=A1/.test(url));
        // The token route takes no country: the boot mint runs before any panel has one.
        expect([opened, prompt(), tokenCalls(calls), description]).toEqual([[true], null, [{ two_token: 'tok' }], description]);
    });

    test.each([
        [(host) => host.component.registeredMode({ openDropdown: true }), false, 'Registered company hides it'],
        [(host) => host.component.manualEntryMode(), false, 'Enter manually hides it'],
        [(host) => { window.open.mockReturnValue(POPUP); host.component.soleTraderMode(); }, false, 'Sole trader, now allowed, hides it'],
        [(host) => host.component.soleTraderMode(), true, 'Sole trader, still blocked, keeps it'],
    ])('the blocked-popup fallback after a chip switch, case %#', async (chip, shown, description) => {
        document.body.innerHTML = ADDRESS('shipping');
        jest.spyOn(window, 'open').mockReturnValue(null);
        const { hosts } = start(config({ address_lookup: false }), { stt: TOKENS, st: ['LIMITED_COMPANY', 'SOLE_TRADER'] });
        await flush();
        hosts.shipping.component.soleTraderMode();
        const blocked = !!prompt();
        chip(hosts.shipping);
        expect([blocked, !!prompt(), description]).toEqual([true, shown, description]);
    });
});
