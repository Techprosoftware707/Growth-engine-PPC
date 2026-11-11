#!/usr/bin/env python3
"""Bundle the researched backlink databases into the plugin's data directory.

Reads  ops/backlinks/opportunities.csv      (88 rows, original verified database)
       ops/backlinks/opportunities-500.csv  (507 rows, expansion database)
       ops/backlinks/TOP-25.md, TOP-NEW-50.md (step-by-step playbooks, matched by platform name)
Writes ppc-link-growth-engine/data/verified-opportunities.json

Run from the repository root:  python3 bin/build-verified-db.py
"""
from __future__ import annotations

import csv
import hashlib
import json
import re
import sys
from datetime import date
from pathlib import Path
from urllib.parse import urlparse

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / 'ops' / 'backlinks'
OUT = ROOT / 'ppc-link-growth-engine' / 'data' / 'verified-opportunities.json'

# category (research DB) -> (site_type, opportunity_type) in the plugin vocabulary
CATEGORY_MAP = {
    'directory': ('directory', 'directory_listing'),
    'company_profile': ('directory', 'directory_listing'),
    'maps_data': ('directory', 'directory_listing'),
    'review': ('directory', 'directory_listing'),
    'employer': ('directory', 'directory_listing'),
    'event': ('directory', 'directory_listing'),
    'bookmarking': ('directory', 'directory_listing'),
    'other': ('directory', 'directory_listing'),
    'resource_directory': ('resource_page', 'resource_page'),
    'publishing': ('publication', 'educational_contribution'),
    'document_publishing': ('educational', 'educational_contribution'),
    'media_podcast': ('podcast', 'podcast'),
    'community': ('association', 'membership'),
    'press_release': ('media', 'editorial'),
}

GATE = (
    "Storefront consistency gate: every listing uses the research-use-only (RUO) lab-reagent description. "
    "Before submitting, make sure the live store presents the business the same way (no consumer-use category "
    "names, dosage forms or injection accessories that contradict the description). The plugin's compliance "
    "preflight checks this automatically and pauses automated submissions while the store contradicts it."
)

KIT = {
    'business_name': 'Pure Peptides Canada',
    'website': 'https://purepeptidescanada.com',
    'address': '222 Jarvis St, Toronto ON M5B 2B8, Canada',
    'email': 'hi@purepeptidescanada.com',
    'founded': '2023',
    'category_rule': 'Laboratory supplies / biotechnology / scientific research supplier. Never a health, medical, wellness, pharmacy or supplement category.',
    'description': (
        'Pure Peptides Canada is a Toronto-based supplier of research-grade peptides and laboratory reagents for '
        'scientific research use only. Every batch is independently third-party tested with published certificates '
        'of analysis. Canadian owned and operated; ships across Canada. Founded 2023.'
    ),
    'positioning_rule': 'Research-use-only everywhere: no health, dosing, therapeutic, efficacy or human-use claims.',
    'budget_rule': 'Free tiers only. Never buy a listing, upgrade, badge, sponsored slot or paid press release.',
    'identity_rule': 'Personal-profile platforms need a real staff member with disclosed affiliation; never invent a persona.',
}


def host(u: str) -> str:
    u = (u or '').strip()
    if not u:
        return ''
    h = urlparse(u if '://' in u else 'http://' + u).netloc.lower().split(':')[0]
    return h[4:] if h.startswith('www.') else h


def first_url(text: str) -> str:
    m = re.search(r'(https?://[^\s,;)"\']+|mailto:[^\s,;)"\']+)', text or '')
    return m.group(1).rstrip('.') if m else ''


def norm_name(s: str) -> str:
    return re.sub(r'[^a-z0-9]+', ' ', (s or '').lower()).strip()


def parse_playbook(path: Path) -> dict[str, str]:
    """Map normalized platform name -> markdown step block from '### N. Name' sections."""
    out: dict[str, str] = {}
    if not path.exists():
        return out
    cur, buf = None, []
    for line in path.read_text(encoding='utf-8').splitlines():
        m = re.match(r'^###\s+\d+\.\s+(.*?)(?:\s+\(authority \d+\))?\s*$', line)
        if m or line.startswith('## '):
            if cur and buf:
                out.setdefault(cur, '\n'.join(buf).strip())
            cur, buf = (norm_name(m.group(1)) if m else None), []
            continue
        if cur is not None:
            buf.append(line)
    if cur and buf:
        out.setdefault(cur, '\n'.join(buf).strip())
    return out


def clip(s: str, n: int) -> str:
    s = re.sub(r'\s+', ' ', (s or '')).strip()
    return s if len(s) <= n else s[: n - 1].rstrip() + '…'


def to_int(s: str):
    try:
        return int(str(s).strip())
    except (TypeError, ValueError):
        return None


def row_to_item(r: dict, db: str, playbooks: dict[str, str]) -> dict | None:
    website = (r.get('website') or '').strip()
    sub = first_url(r.get('submission_url') or '') or first_url(r.get('registration_url') or '') or website
    h = host(website) or host(sub)
    if not h:
        return None
    cat = (r.get('category') or 'other').strip()
    site_type, opp = CATEGORY_MAP.get(cat, ('directory', 'directory_listing'))
    approval = (r.get('approval_required') or '').strip()
    outreach = approval.lower().startswith('yes — outreach') or approval.lower().startswith('yes - outreach')
    if outreach and opp == 'directory_listing':
        opp = 'resource_page'
    req = (r.get('requirements') or '').strip()
    conditional = req.upper().startswith('CONDITIONAL') or 'conditional' in (r.get('verification_note') or '').lower()[:0]
    name = (r.get('platform') or h).strip()
    steps = playbooks.get(norm_name(name), '')
    if not steps:
        # tolerate small name differences: match on the leading words
        key = norm_name(name)
        for k, v in playbooks.items():
            if k and (k.startswith(key[:24]) or key.startswith(k[:24])) and min(len(k), len(key)) >= 8:
                steps = v
                break
    return {
        'id': hashlib.sha1(f'{h}|{name}'.encode('utf-8')).hexdigest()[:16],
        'db': db,
        'platform': clip(name, 190),
        'website': website,
        'host': h,
        'submission_url': sub,
        'registration_url': first_url(r.get('registration_url') or ''),
        'category': cat,
        'site_type': site_type,
        'opportunity_type': opp,
        'tier': to_int(r.get('tier')),
        'authority': to_int(r.get('authority')),
        'free': clip(r.get('free') or '', 300),
        'payment': clip(r.get('payment') or '', 300),
        'requirements': clip(req, 1200),
        'link_clickable': clip(r.get('link_clickable') or '', 120),
        'link_attribute': clip(r.get('link_attribute') or '', 160),
        'approval_required': clip(approval, 400),
        'effort': clip(r.get('effort') or '', 80),
        'seo_value': clip(r.get('seo_value') or '', 80),
        'relevance': clip(r.get('relevance') or '', 160),
        'risk': clip(r.get('risk') or '', 300),
        'verification_note': clip(r.get('verification_note') or '', 700),
        'conditional': bool(req.upper().startswith('CONDITIONAL')),
        'outreach': outreach,
        'source': (r.get('source') or ('master' if db == 'master' else '')).strip(),
        'steps': clip(steps, 2500),
    }


def main() -> int:
    playbooks = {}
    playbooks.update(parse_playbook(SRC / 'TOP-25.md'))
    for k, v in parse_playbook(SRC / 'TOP-NEW-50.md').items():
        playbooks.setdefault(k, v)

    items: list[dict] = []
    seen: set[str] = set()
    for fname, db in (('opportunities.csv', 'master'), ('opportunities-500.csv', 'expansion')):
        with open(SRC / fname, newline='', encoding='utf-8') as fh:
            for r in csv.DictReader(fh):
                it = row_to_item(r, db, playbooks)
                if not it:
                    continue
                key = it['host'] + '|' + norm_name(it['platform'])
                if key in seen:
                    continue
                seen.add(key)
                items.append(it)

    items.sort(key=lambda x: (x['tier'] or 9, -(x['authority'] or -1), x['platform'].lower()))
    payload = {
        'version': date.today().isoformat() + '-' + hashlib.sha1(json.dumps(items, sort_keys=True).encode()).hexdigest()[:8],
        'generated': date.today().isoformat(),
        'counts': {
            'total': len(items),
            'master': sum(1 for i in items if i['db'] == 'master'),
            'expansion': sum(1 for i in items if i['db'] == 'expansion'),
            'with_steps': sum(1 for i in items if i['steps']),
            'outreach': sum(1 for i in items if i['outreach']),
            'conditional': sum(1 for i in items if i['conditional']),
        },
        'kit': KIT,
        'gate': GATE,
        'items': items,
    }
    OUT.write_text(json.dumps(payload, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')
    print(json.dumps(payload['counts']), '->', OUT.relative_to(ROOT))
    return 0


if __name__ == '__main__':
    sys.exit(main())
