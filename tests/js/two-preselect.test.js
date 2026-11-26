/**
 * Checkout's half of the buy button (Build Guide section 20.8): the first
 * payment method list checks Two's option once, never over a method the
 * buyer saved, and the marker is spent whether or not Two is offered.
 */
const { start, MARKER, DECLINED } = require('../../catalog/view/javascript/two_preselect.js');

/** OpenCart's payment method modal as its checkout script builds it, the first option checked. */
function modal(codes) {
    const radios = codes.map((code, i) => '<input type="radio" name="payment_method" value="' + code + '"' + (i === 0 ? ' checked' : '') + '>').join('');
    return '<div id="modal-payment"><form id="form-payment-method">' + radios + '</form></div>';
}

const PRODUCT = 'https://shop.test/index.php?route=product/product&product_id=42';
const CART = 'https://shop.test/index.php?route=checkout/cart';

/** The checkout page, reached from `referrer` (the button's own hop comes from the product page). */
function checkout(saved, referrer = PRODUCT) {
    const doc = document.implementation.createHTMLDocument('checkout');
    Object.defineProperty(doc, 'referrer', { value: referrer });
    doc.body.innerHTML = '<input type="hidden" id="input-payment-code" value="' + saved + '">';
    return doc;
}

/** The button stores the product page it was pressed on. */
function win(marker) {
    const storage = new Map(marker ? [[MARKER, PRODUCT]] : []);
    return {
        storage,
        MutationObserver,
        sessionStorage: { getItem: (k) => (storage.has(k) ? storage.get(k) : null), removeItem: (k) => storage.delete(k) }
    };
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const CASES = [
    { marker: true, saved: '', codes: ['cod.cod', 'two.two'], later: true, checked: 'two.two', spent: true, description: 'from the button, the list opened later checks Two' },
    { marker: true, saved: '', codes: ['cod.cod', 'two.two'], later: false, checked: 'two.two', spent: true, description: 'a list already open checks Two at once' },
    { marker: false, saved: '', codes: ['cod.cod', 'two.two'], later: true, checked: 'cod.cod', spent: false, description: 'without the button nothing changes' },
    { marker: true, saved: 'cod.cod', codes: ['cod.cod', 'two.two'], later: true, checked: 'cod.cod', spent: true, description: 'a method the buyer saved is never overridden, and the marker is spent' },
    { marker: true, saved: '', codes: ['cod.cod', 'bank_transfer.bank_transfer'], later: true, checked: 'cod.cod', spent: true, description: 'Two not offered for this basket: OpenCart\'s default stands and the marker is spent' },
    { marker: true, saved: '', codes: ['cod.cod', 'two.two'], later: true, from: CART, checked: 'cod.cod', spent: true, description: 'checkout bounced to the cart (minimum or stock), a later checkout from there: OpenCart\'s default stands and the marker is spent' }
];

describe('checkout preselection', () => {
    test.each(CASES)('$description', async (c) => {
        const doc = checkout(c.saved, c.from);
        const w = win(c.marker);
        if (!c.later) {
            doc.body.insertAdjacentHTML('beforeend', modal(c.codes));
        }
        start(doc, w);
        if (c.later) {
            doc.body.insertAdjacentHTML('beforeend', '<p>shipping methods</p>');
            await flush();
            expect(w.storage.has(MARKER)).toBe(c.marker && !c.from);
            doc.body.insertAdjacentHTML('beforeend', modal(c.codes));
            await flush();
        }

        expect(doc.querySelector('input[name="payment_method"]:checked').value).toBe(c.checked);
        expect(w.storage.has(MARKER)).toBe(!c.spent && c.marker);
    });

    test('the marker is spent once: a list built again keeps what the buyer has', async () => {
        const doc = checkout('');
        const w = win(true);
        start(doc, w);
        doc.body.insertAdjacentHTML('beforeend', modal(['cod.cod', 'two.two']));
        await flush();
        doc.getElementById('modal-payment').remove();
        doc.body.insertAdjacentHTML('beforeend', modal(['cod.cod', 'two.two']));
        await flush();
        expect(doc.querySelector('input[name="payment_method"]:checked').value).toBe('cod.cod');
    });
});

/**
 * TWO-27391: on the decline return's landing (the marker beside its notice)
 * Two stays in the list but is never chosen for the buyer, however often the
 * list is built; a normal checkout keeps OpenCart's own first-option default.
 */
const DECLINE_CASES = [
    { declined: true, saved: '', codes: ['two.two', 'cod.cod'], checked: [], description: 'declined: OpenCart checked Two first, unchecked' },
    { declined: true, saved: '', codes: ['cod.cod', 'two.two'], checked: ['cod.cod'], description: 'declined: another method first, left as OpenCart checked it' },
    { declined: true, saved: 'two.two', codes: ['two.two', 'cod.cod'], checked: ['two.two'], description: 'declined, then the buyer saved Two again: kept' },
    { declined: false, saved: '', codes: ['two.two', 'cod.cod'], checked: ['two.two'], description: 'a normal checkout: unchanged' },
];

describe('decline return landing', () => {
    test.each(DECLINE_CASES)('$description', async (c) => {
        const doc = checkout(c.saved, CART);
        if (c.declined) {
            doc.body.insertAdjacentHTML('beforeend', '<span id="' + DECLINED + '" hidden></span>');
        }
        start(doc, win(false));
        for (let built = 0; built < 2; built++) {
            doc.getElementById('modal-payment')?.remove();
            doc.body.insertAdjacentHTML('beforeend', modal(c.codes));
            await flush();
            expect(Array.from(doc.querySelectorAll('input[name="payment_method"]:checked')).map((i) => i.value)).toEqual(c.checked);
            expect(doc.querySelector('input[value="two.two"]')).not.toBeNull();
        }
    });
});
