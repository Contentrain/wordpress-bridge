// Test fixture: interface strings and the code-shaped literals around them.
const labels = { more: 'Show more', loading: "Loading your table…", thanks: `Thanks for subscribing!` };
const selector = '.menu-toggle';
const attr = 'data-toggle';
const api = 'https://example.org/api/v1';
const token = 'ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789ab';
const year = '2026';
document.querySelector(selector)?.setAttribute(attr, labels.more);
