import assert from 'node:assert/strict';
import test from 'node:test';
import { extractReadAloudText } from '../../resources/js/read-aloud-content.js';

class Element {
    constructor(tagName, text = '', attributes = {}) {
        this.tagName = tagName.toUpperCase();
        this.text = text;
        this.attributes = attributes;
        this.children = [];
        this.parent = null;
    }

    append(...children) {
        children.forEach((child) => {
            child.parent = this;
            this.children.push(child);
        });
        return this;
    }

    remove() {
        if (!this.parent) return;
        this.parent.children = this.parent.children.filter((child) => child !== this);
        this.parent = null;
    }

    matches(selector) {
        if (selector === '[hidden], [aria-hidden="true"], [inert]') {
            return Object.hasOwn(this.attributes, 'hidden')
                || this.attributes['aria-hidden'] === 'true'
                || Object.hasOwn(this.attributes, 'inert');
        }
        if (selector === 'details:not([open])') {
            return this.tagName === 'DETAILS' && !Object.hasOwn(this.attributes, 'open');
        }
        return false;
    }

    cloneNode(deep = false) {
        const clone = new Element(this.tagName, this.text, { ...this.attributes });
        if (deep) clone.append(...this.children.map((child) => child.cloneNode(true)));
        return clone;
    }

    querySelectorAll(selector) {
        const matches = (element) => {
            if (selector === '[hidden], [aria-hidden="true"], [inert]') {
                return Object.hasOwn(element.attributes, 'hidden')
                    || element.attributes['aria-hidden'] === 'true'
                    || Object.hasOwn(element.attributes, 'inert');
            }
            if (selector === 'details:not([open])') {
                return element.tagName === 'DETAILS' && !Object.hasOwn(element.attributes, 'open');
            }
            if (selector === '[data-read-aloud-controls]') {
                return Object.hasOwn(element.attributes, 'data-read-aloud-controls');
            }
            return false;
        };
        const descendants = this.children.flatMap((child) => [child, ...child.querySelectorAll(selector)]);
        return descendants.filter(matches);
    }

    get textContent() {
        return this.text + this.children.map((child) => child.textContent).join(' ');
    }

    // Detached clones can expose textContent even when a browser's innerText
    // would normally omit content hidden by closed disclosure widgets.
    get innerText() { return this.textContent; }
}

test('read-aloud includes visible question text but excludes closed, hidden, and control content', () => {
    const content = new Element('fieldset').append(
        new Element('legend', 'Question 1'),
        new Element('p', 'What time does the clock show?'),
        new Element('label', '03:00'),
        new Element('details').append(
            new Element('summary', 'Show source text'),
            new Element('p', 'This transcript should remain hidden.'),
        ),
        new Element('p', 'Screen-reader-hidden answer.', { 'aria-hidden': 'true' }),
        new Element('p', 'Not yet available.', { hidden: '' }),
        new Element('p', 'Inactive question.', { inert: '' }),
        new Element('div', 'Pause Stop', { 'data-read-aloud-controls': '' }),
    );

    const spokenText = extractReadAloudText(content);

    assert.match(spokenText, /Question 1/);
    assert.match(spokenText, /What time does the clock show\?/);
    assert.match(spokenText, /03:00/);
    assert.match(spokenText, /Show source text/);
    assert.doesNotMatch(spokenText, /transcript should remain hidden|Screen-reader-hidden answer|Not yet available|Inactive question|Pause Stop/);
});

test('read-aloud returns an empty string for missing content', () => {
    assert.equal(extractReadAloudText(null), '');
    assert.equal(extractReadAloudText(new Element('div', 'Hidden answer', { hidden: '' })), '');
    assert.equal(extractReadAloudText(new Element('details').append(
        new Element('summary', 'Closed answer detail'),
        new Element('p', 'Hidden explanation'),
    )), 'Closed answer detail');
});
