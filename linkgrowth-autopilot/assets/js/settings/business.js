/**
 * Settings tab "Business profile" (business): identity, contact and address, profiles and categories (site.*).
 * The Settings page (pages/settings.js) renders it from lga-config.settingsTabs; `tab` carries the page's shared
 * state and helpers (bind() edits the draft the page saves with every other tab's changes).
 */

import {
    el, card,
} from '../ui.js';

export default function render( tab ) {
    const { bind, grid } = tab;
    return el( 'div', { class: 'lga-stack' },
        card( { title: 'Identity', subtitle: 'Used verbatim in listing packages and outreach signatures', body: grid( [
            bind( 'site.brand_name', { type: 'text', label: 'Brand name', required: true } ),
            bind( 'site.legal_name', { type: 'text', label: 'Legal name' } ),
            bind( 'site.domain', { type: 'text', label: 'Site domain', hint: 'Without protocol, e.g. example.com' } ),
            bind( 'site.home_url', { type: 'url', label: 'Home URL' } ),
            bind( 'site.tagline', { type: 'textarea', label: 'Tagline', rows: 2, full: true } ),
            bind( 'site.logo_url', { type: 'url', label: 'Logo URL', full: true } ),
        ] ) } ),
        card( { title: 'Contact & address', body: grid( [
            bind( 'site.email', { type: 'email', label: 'Public email' } ),
            bind( 'site.phone', { type: 'tel', label: 'Phone' } ),
            bind( 'site.address', { type: 'text', label: 'Street address' } ),
            bind( 'site.city', { type: 'text', label: 'City' } ),
            bind( 'site.region', { type: 'text', label: 'Province / region' } ),
            bind( 'site.postal_code', { type: 'text', label: 'Postal code' } ),
            bind( 'site.country', { type: 'text', label: 'Country (ISO-2)', maxlength: 2 } ),
            bind( 'site.founded_year', { type: 'text', label: 'Founded year', maxlength: 4 } ),
        ] ) } ),
        card( { title: 'Profiles & categories', body: grid( [
            bind( 'site.social', { type: 'list', label: 'Social profile URLs (one per line)', rows: 4 } ),
            bind( 'site.categories', { type: 'list', label: 'Directory categories to request (one per line)', rows: 4, hint: 'Tried in order when a directory offers a category list.' } ),
        ] ) } ),
    );
}
