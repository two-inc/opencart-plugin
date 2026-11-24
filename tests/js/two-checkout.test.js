/**
 * The checkout script around the shared company search: the call queue,
 * Confirm and the tile's order intent, against a scripted shop. The company
 * search itself is company-search-host.test.js.
 */
const { Api, Checkout, readConfig } = require('../../catalog/view/javascript/two_checkout.js');

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

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

afterEach(() => {
    jest.useRealTimers();
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

    test('a search skips a slow intent but waits for a slow sole-trader call; the save after them waits for all', async () => {
        const order = [];
        const release = {};
        const fetchImpl = jest.fn((url) => new Promise((resolve) => {
            const route = new URLSearchParams(url.split('?')[1]).get('route');
            order.push('start ' + route);
            release[route] = () => { order.push('end ' + route); resolve({ json: () => Promise.resolve({ items: [] }) }); };
        }));
        const api = new Api(config(), fetchImpl);
        api.get('sole_trader', { country: 'GB' });
        api.post('intent', {});
        api.get('search', { q: 'acme', country: 'GB' });
        api.post('save_company', { company_name: 'Acme' });
        await flush();
        expect(order).toEqual(['start st']);
        release.st();
        await flush();
        await flush();
        expect(order).toEqual(['start st', 'end st', 'start i', 'start s']);
        release.s();
        await flush();
        expect(order.includes('start save')).toBe(false);
        release.i();
        await flush();
        await flush();
        expect(order.slice(-2)).toEqual(['end i', 'start save']);
        release.save();
        await api.settled();
    });

    test('confirm is never abandoned by the browser: it runs as long as the shop needs', async () => {
        jest.useFakeTimers();
        let aborted = false;
        const fetchImpl = jest.fn((url, init) => new Promise(() => {
            init.signal.addEventListener('abort', () => { aborted = true; });
        }));
        const cfg = config();
        cfg.urls.confirm = 'index.php?route=confirm';
        new Api(cfg, fetchImpl).post('confirm', {});
        await jest.advanceTimersByTimeAsync(300000);
        expect([fetchImpl.mock.calls.length, aborted]).toEqual([1, false]);
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

    test.each([
        [{ tile: { company_name: 'Acme Ltd', organization_number: '0123', country_code: 'GB', source: 'search' } }, 1, 'a capture the tile already holds is asked about'],
        [{}, 0, 'nothing captured in the tile: nothing asked'],
        [{ payment: { company_name: 'Acme Ltd', organization_number: '0123', country_code: 'GB', source: 'search' } }, 0, 'an address capture never stands in for the tile in tile placement'],
    ])('tile placement %#', async (companies, asked, description) => {
        const form = tile('search', '<input id="input-two-company" name="two_company_name" data-two-country="GB">');
        const { calls, fetchImpl } = shop({ i: { outcome: 'approved', notice: 'likely' } });
        const cfg = config({ in_address: false, companies: companies });
        const checkout = new Checkout(cfg, document, new Api(cfg, fetchImpl), window);
        await checkout.tileReady(form);
        expect([calls.filter(([route]) => route === 'i').length, description]).toEqual([asked, description]);
    });

    const WITHDRAWN = { outcome: 'refused', notice: 'choose another', withdrawn: true };
    const APPROVED = { outcome: 'approved', notice: 'likely', withdrawn: false };
    const UNANSWERED = new Promise(() => {});
    const OTHER_COMPANY = { company_name: 'Example & Co Ltd', organization_number: '0456', country_code: 'GB', source: 'search' };
    const HELD_WITHDRAWN = { held: WITHDRAWN };

    /**
     * The first step is the tile's own ask; each later step is the buyer
     * picking another company (the intent answers with it: an answer, an
     * Error for no answer, or UNANSWERED for one still out), 'clear', or
     * 'redraw' (OpenCart renders the tile again, with no company). An answer
     * in HELD_WITHDRAWN lands only after every step.
     * Expected: [terms hidden, Confirm disabled, notice text].
     */
    test.each([
        [[WITHDRAWN], [true, true, 'choose another'], 'TWO-27369: a withdrawn tile hides its terms and disables confirm'],
        [[APPROVED], [false, false, 'likely'], 'an approval offers both'],
        [[{ outcome: 'declined', notice: 'cannot' }], [false, false, 'cannot'], 'a decline leaves both, as confirm asks again'],
        [[WITHDRAWN, APPROVED], [false, false, 'likely'], 'another company approved after a withdrawal offers both again'],
        [[WITHDRAWN, 'clear'], [false, false, ''], 'the company cleared after a withdrawal offers both again (confirm then asks for a company)'],
        [[WITHDRAWN, UNANSWERED], [true, true, 'checking'], 'another company being checked after a withdrawal keeps both off and says so'],
        [[WITHDRAWN, new Error('down')], [false, false, ''], 'another company with no answer after a withdrawal offers both again'],
        [[APPROVED, UNANSWERED], [false, false, 'checking'], 'another company being checked after an approval leaves both offered'],
        [[WITHDRAWN, 'clear', WITHDRAWN], [true, true, 'choose another'], 'a company withdrawn again after a clear withdraws both again'],
        [[HELD_WITHDRAWN, 'redraw'], [false, false, ''], 'a withdrawal meant for a tile OpenCart has since redrawn leaves the new tile offered'],
    ])('withdrawal %#', async (steps, expected, description) => {
        const draw = (summary) => {
            tile('address', summary + '<div data-two-terms></div>');
            document.body.insertAdjacentHTML('beforeend', '<button id="button-two-confirm">Confirm</button>');
            return document.getElementById('form-two');
        };
        const answers = steps.filter((step) => step !== 'clear' && step !== 'redraw');
        const held = [];
        const { fetchImpl } = shop({ i: () => {
            const answer = answers.shift();
            return answer && answer.held ? new Promise((resolve) => held.push(() => resolve(answer.held))) : answer;
        } });
        const checkout = new Checkout(config(), document, new Api(config(), fetchImpl), window);
        checkout.tileReady(draw('<p data-two-company-summary>Buying for Acme</p>'));
        await flush();
        for (const step of steps.slice(1)) {
            if (step === 'redraw') {
                checkout.tileReady(draw('<p class="text-danger" data-two-company-summary>Search first</p>'));
            } else {
                checkout.companyChanged('payment', step === 'clear' ? null : OTHER_COMPANY);
            }
            await flush();
        }
        held.forEach((release) => release());
        await flush();
        const notice = document.getElementById('two-intent-notice').textContent;
        const state = [document.querySelector('[data-two-terms]').hidden, document.getElementById('button-two-confirm').disabled, notice];
        expect([state, description]).toEqual([expected, description]);
        // Every state that disables Confirm tells the buyer why.
        expect([state[1] && notice === '', description]).toEqual([false, description]);
    });

    test('TWO-27369: a withdrawal that lands while confirm runs keeps confirm off', async () => {
        const form = tile('address', '<p data-two-company-summary>Buying for Acme</p><div data-two-terms></div>');
        document.body.insertAdjacentHTML('beforeend', '<button id="button-two-confirm">Confirm</button>');
        const button = document.getElementById('button-two-confirm');
        const later = {};
        const deferred = (name) => () => new Promise((resolve) => {
            later[name] = resolve;
        });
        const cfg = config({ urls: Object.assign({}, config().urls, { confirm: 'index.php?route=confirm' }) });
        const { fetchImpl } = shop({ i: deferred('intent'), confirm: deferred('confirm') });
        const checkout = new Checkout(cfg, document, new Api(cfg, fetchImpl), window);
        checkout.tileReady(form);
        await flush();
        const confirming = checkout.confirm(button, form);
        await flush();
        later.intent(WITHDRAWN);
        await flush();
        later.confirm({ error: 'choose another' });
        await confirming;
        expect([button.disabled, document.getElementById('two-intent-notice').textContent]).toEqual([true, 'choose another']);
    });

    test.each([
        [true, 1, 'the preview on asks once the company is known'],
        [undefined, 1, 'a page without the switch asks, as before it existed'],
        [false, 0, 'the preview switched off asks nothing'],
    ])('intent preview %s', async (preview, asked, description) => {
        const form = tile('address', '<p data-two-company-summary>Buying for Acme</p>');
        const { calls, fetchImpl } = shop({ i: { outcome: 'approved', notice: 'likely' } });
        const cfg = config(preview === undefined ? {} : { intent_preview: preview });
        const checkout = new Checkout(cfg, document, new Api(cfg, fetchImpl), window);
        await checkout.tileReady(form);
        expect([calls.filter(([route]) => route === 'i').length, description]).toEqual([asked, description]);
    });
});
