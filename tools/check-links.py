#!/usr/bin/env python3
"""Check relative links and #anchors in the Markdown documentation (used by CI)."""
import re, os, sys
def slug(h):
    h = re.sub(r'[`*_]', '', h.strip().lower())
    h = re.sub(r'[^\w\- ]', '', h)
    return h.replace(' ', '-')
bad = 0
files = ['README.md', 'AGENTS.md'] + ['docs/'+f for f in os.listdir('docs') if f.endswith('.md')]
for f in files:
    txt = open(f).read()
    for m in re.finditer(r'\]\(([^)\s]+)\)', txt):
        t = m.group(1)
        if t.startswith(('http://', 'https://', 'mailto:')): continue
        path, _, anchor = t.partition('#')
        target = os.path.normpath(os.path.join(os.path.dirname(f), path)) if path else f
        if not os.path.exists(target):
            print(f"{f}: missing file {t}"); bad += 1; continue
        if anchor and target.endswith('.md'):
            heads = {slug(h) for h in re.findall(r'^#+\s+(.*)$', open(target).read(), re.M)}
            if anchor not in heads:
                print(f"{f}: missing anchor {t}"); bad += 1
print("links checked, problems:", bad)
sys.exit(1 if bad else 0)
