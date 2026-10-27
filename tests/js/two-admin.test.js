/**
 * The admin page script: "Autofill company address" is unavailable, and off,
 * while the company search sits in the payment tile (TWO-26721).
 */
function page(placement, autofill) {
    document.body.innerHTML = '<input type="checkbox" id="input-company-search-in-address"' + (placement ? ' checked' : '') + '>'
        + '<input type="checkbox" id="input-address-lookup"' + (autofill && placement ? ' checked' : '') + (placement ? '' : ' disabled') + '>';
    jest.isolateModules(() => require('../../admin/view/javascript/two_admin.js'));
    return [document.getElementById('input-company-search-in-address'), document.getElementById('input-address-lookup')];
}

const toggle = (box) => {
    box.checked = !box.checked;
    box.dispatchEvent(new Event('change', { bubbles: true }));
};

test.each([
    [true, true, [true, false], [false, true], 'on in address placement, greyed out and off in the tile'],
    [false, false, [false, true], [true, false], 'a page loaded in tile placement offers it, on by default, once the search moves to the address'],
])('placement %s, autofill %s', (placement, autofill, first, second, description) => {
    const [where, fill] = page(placement, autofill);
    expect([fill.disabled, fill.checked, description]).toEqual([!placement, placement && autofill, description]);
    toggle(where);
    expect([fill.disabled, fill.checked, description]).toEqual(first.concat(description));
    toggle(where);
    expect([fill.disabled, fill.checked, description]).toEqual(second.concat(description));
});

test('a merchant who switched autofill off finds it off again after a round trip to the tile', () => {
    const [where, fill] = page(true, true);
    toggle(fill);
    toggle(where);
    toggle(where);
    expect([fill.disabled, fill.checked]).toEqual([false, false]);
});
