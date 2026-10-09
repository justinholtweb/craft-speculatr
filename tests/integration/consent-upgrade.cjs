/**
 * Runs the consent upgrade script Speculatr inlines against a fake DOM and a fake Toss, and prints
 * what happened as JSON. Called by consent.php — `node consent-upgrade.cjs <file with the script>`.
 *
 * The script's whole job is behaviour in a browser: add a speculation rule set when every category
 * is granted, never for undecided or refused, and take it away again on withdrawal. A string match
 * on the PHP side cannot tell whether it does that; running it can.
 */
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(process.argv[2], 'utf8');
const js = source.replace(/^<script[^>]*>/, '').replace(/<\/script>$/, '');

function run({ toss = 'before', supports = true, nonce = 'n0nce' } = {}) {
    const listeners = {};
    const body = { children: [] };
    const document = {
        currentScript: { nonce },
        body,
        createElement(tag) {
            const el = {
                tagName: tag, type: '', nonce: '', textContent: '', attrs: {},
                setAttribute(k, v) { this.attrs[k] = v; },
                remove() { body.children = body.children.filter((c) => c !== el); },
            };
            return el;
        },
        addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
    };
    body.appendChild = (el) => body.children.push(el);

    let tossListeners = [];
    let state = null;
    const Toss = {
        version: 1,
        onConsent(fn) { tossListeners.push(fn); if (state) fn(JSON.parse(JSON.stringify(state))); return () => {}; },
    };
    const window = {};
    window.HTMLScriptElement = { supports: (t) => supports && t === 'speculationrules' };
    if (toss === 'before') window.Toss = Toss;

    const sandbox = { window, document, HTMLScriptElement: window.HTMLScriptElement };
    vm.runInNewContext(js, sandbox);

    const decide = (categories, reason) => {
        state = { categories: { necessary: true, ...categories }, decided: true, reason };
        if (toss === 'before') {
            tossListeners.forEach((fn) => fn(JSON.parse(JSON.stringify(state))));
        } else {
            (listeners['toss:consent'] || []).forEach((fn) => fn({ detail: JSON.parse(JSON.stringify(state)) }));
        }
    };
    const snapshot = () => body.children.map((c) => ({
        type: c.type, nonce: c.nonce, marker: c.attrs['data-speculatr'], rules: JSON.parse(c.textContent),
    }));

    return { decide, snapshot };
}

const out = {};

// Toss's runtime ran first: undecided, then granted, then marketing withdrawn, then granted again.
let r = run({ toss: 'before' });
r.decide({ analytics: null, marketing: null }, 'load');
out.undecided = r.snapshot();
r.decide({ analytics: true, marketing: true }, 'change');
out.granted = r.snapshot();
r.decide({ analytics: true, marketing: false }, 'change');
out.withdrawn = r.snapshot();
r.decide({ analytics: true, marketing: true }, 'change');
r.decide({ analytics: true, marketing: true }, 'change');
out.regranted = r.snapshot();

// Speculatr's script ran first and Toss announces itself with the event.
r = run({ toss: 'after' });
r.decide({ analytics: true, marketing: true }, 'load');
out.eventGranted = r.snapshot();

// Half a grant is no grant.
r = run({ toss: 'before' });
r.decide({ analytics: true, marketing: false }, 'load');
out.partial = r.snapshot();

// A browser without speculation rules does nothing at all.
r = run({ toss: 'before', supports: false });
r.decide({ analytics: true, marketing: true }, 'load');
out.unsupported = r.snapshot();

// No nonce on the page, none on the rule set.
r = run({ toss: 'before', nonce: '' });
r.decide({ analytics: true, marketing: true }, 'load');
out.noNonce = r.snapshot();

process.stdout.write(JSON.stringify(out));
