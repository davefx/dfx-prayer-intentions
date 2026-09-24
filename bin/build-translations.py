#!/usr/bin/env python3
"""Merge bin/es_ES.py into the POT and write languages/dfx-prayer-intentions-es_ES.po.

Run after regenerating the POT (wp i18n make-pot), then compile with
`wp i18n make-mo languages` and `wp i18n make-json languages --no-purge`.
Strings missing from es_ES.py are left untranslated and listed on stderr.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, 'bin'))
from es_ES import T  # noqa: E402

pot = open(os.path.join(ROOT, 'languages', 'dfx-prayer-intentions.pot'), encoding='utf-8').read()
header, *entries = pot.split('\n\n')

header = re.sub(r'"Language: \\n"\n', '', header)
header = header.replace('"Language-Team: LANGUAGE <LL@li.org>\\n"', '"Language-Team: Spanish (Spain)\\n"')
header = header.replace('"Content-Type: text/plain; charset=UTF-8\\n"', '"Content-Type: text/plain; charset=UTF-8\\n"\n"Language: es_ES\\n"\n"Plural-Forms: nplurals=2; plural=(n != 1);\\n"')

out, missing = [header], []
for entry in entries:
    if not entry.strip():
        continue
    ctx = re.search(r'^msgctxt "(.*)"$', entry, re.M)
    mid = re.search(r'^msgid ((?:".*"\n?)+)', entry, re.M)
    text = ''.join(re.findall(r'"(.*)"', mid.group(1)))
    key = (ctx.group(1) + '\x04' if ctx else '') + text
    translation = T.get(key)
    if translation is None:
        missing.append(key)
        translation = ''
    escaped = translation.replace('"', '\\"') if '\\"' not in translation else translation
    entry = re.sub(r'^msgstr ""$', lambda _: 'msgstr "' + escaped + '"', entry, flags=re.M)
    out.append(entry)

open(os.path.join(ROOT, 'languages', 'dfx-prayer-intentions-es_ES.po'), 'w', encoding='utf-8').write('\n\n'.join(out) + '\n')

unused = set(T) - {((re.search(r'^msgctxt "(.*)"$', e, re.M).group(1) + '\x04') if re.search(r'^msgctxt "(.*)"$', e, re.M) else '') + ''.join(re.findall(r'"(.*)"', re.search(r'^msgid ((?:".*"\n?)+)', e, re.M).group(1))) for e in entries if e.strip()}
for key in missing:
    print('MISSING: ' + key.replace('\x04', ' | '), file=sys.stderr)
for key in sorted(unused):
    print('UNUSED: ' + key.replace('\x04', ' | '), file=sys.stderr)
print('%d translated, %d missing' % (len(entries) - len(missing) - (1 if not entries[-1].strip() else 0), len(missing)))
