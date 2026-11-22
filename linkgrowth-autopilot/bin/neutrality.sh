#!/usr/bin/env bash
# Neutrality scan (DESIGN.md §1.7): nothing of the engine's identity, the store's brand and data, unlisted market
# words or model identifiers may ship.
#
#   bin/neutrality.sh                   scan the working copy: A (whole fork), B (shipped files, tests, bin, configs),
#                                       C (shipped files and tests, with the allowlist), D (everything, docs included)
#   bin/neutrality.sh --zip FILE.zip    scan an extracted plugin ZIP: one top folder linkgrowth-autopilot/, no
#                                       tests/ docs/ bin/ dist/ node_modules/ .phpunit.cache/; A, B, C and D
#   bin/neutrality.sh --source FILE.zip scan an extracted source ZIP (top folder linkgrowth-autopilot-source/):
#                                       A, B and D with no exceptions and no pending list
#   --only=A,B,C,D   scan only these patterns          --root DIR   scan DIR instead of this working copy
#   --strict         ignore bin/neutrality-pending.txt (the release gate)
#
# Matching is case-insensitive (grep -rniEI). Every term below is written with a character class so that this file
# never matches its own patterns. The pending list (bin/neutrality-pending.txt) holds the B and C hits in shipped
# code (and C hits in its tests) that a later work package removes; a file may have at most its listed number of
# matching lines, a file that is not listed may have none, and tests may never have a B hit. Exit 0 clean, 1 hits,
# 2 usage error.
set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
MODE=repo
ARCHIVE=''
ONLY='A,B,C,D'
STRICT=0
PENDING_FILE="$HERE/neutrality-pending.txt"

while [ $# -gt 0 ]; do
  case "$1" in
    --zip) MODE=zip; ARCHIVE="${2:-}"; shift 2 || { echo "neutrality: --zip needs a file" >&2; exit 2; } ;;
    --zip=*) MODE=zip; ARCHIVE="${1#--zip=}"; shift ;;
    --source) MODE=source; ARCHIVE="${2:-}"; shift 2 || { echo "neutrality: --source needs a file" >&2; exit 2; } ;;
    --source=*) MODE=source; ARCHIVE="${1#--source=}"; shift ;;
    --only=*) ONLY="${1#--only=}"; shift ;;
    --root) ROOT="$(cd "${2:-.}" && pwd)"; shift 2 ;;
    --root=*) ROOT="$(cd "${1#--root=}" && pwd)"; shift ;;
    --strict) STRICT=1; shift ;;
    -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
    *) echo "neutrality: unknown argument $1" >&2; exit 2 ;;
  esac
done

# ------------------------------------------------------------------ patterns (§1.7)
PAT_A='p[p]clg|P[P]C\\+LinkGrowth|p[p]c[-_ ]link[-_ ]growth|Link Growth Engin[e]|P[P]CLinkGrowthBot|p[p]c_en_counterpart|p[p]c-french|\bP[P]C\b'
# B also lists the store's catalog (its compound product names and its category names and slugs): §1.7 B names
# no product, and unit fixtures had carried them.
PAT_B='purepept[i]des|pure pept[i]des|PurePept[i]desCan|6159240168594[0]|pept[i]de|janosh[i]k|222 Jarv[i]s|Jarv[i]s St|M5[B]|Xpressp[o]st|\bInter[a]c\b|GH[K]|bacteriostat[i]c|hi@pur[e]|\bBPC-?15[7]\b|\bTB-?50[0]\b|\bIGF-?[1]\b|semaglutid[e][ -]?([0-9]|canad)|weight[- ]loss[- ](&|&amp;|and)?[- ]?metaboli[c]|anti[- ]aging[- ](&|&amp;|and)?[- ]?cellula[r]|pigmentation[- ](&|&amp;|and)?[- ]?tannin[g]|cognitive[- ](&|&amp;|and)?[- ]?neur[o]\b'
PAT_C='research[- ]use[- ]onl[y]|\bR[U]O\b|not for huma[n]|HPL[C]|certificates? o[f] analysis|Health Canad[a]|Canada Pos[t]|canadapos[t]|Toront[o]|Ontari[o]|Canadia[n]|\bCAS[L]\b|\.gc\.c[a]'
PAT_D="\\b(claude|gemini|llama|mistral)-[a-z0-9.]+-[a-z0-9.]+|\\bgpt-[0-9]|[']model[']\\s*=>\\s*['][a-z]"

SHIPPED=(linkgrowth-autopilot.php uninstall.php readme.txt includes assets data languages)
# A: the only files that must name the engine (DESIGN.md §1.7 A).
A_EXCEPTIONS=(docs/DESIGN.md tests/integration/coexistence.php)
# C: the files that legitimately carry market and positioning words (DESIGN.md §1.7 C).
C_ALLOW=(data/packs/industry/research-supplies.json data/packs/country/ca.json includes/Outreach/Jurisdictions.php includes/Profile/CountryData.php includes/Content/ClaimPacks.php tests/Unit/Profile/ tests/Unit/Outreach/JurisdictionsTest.php)

WORK=''
cleanup() { [ -n "$WORK" ] && rm -rf "$WORK"; }
trap cleanup EXIT
FAIL=0

if [ "$MODE" != repo ]; then
  [ -f "$ARCHIVE" ] || { echo "neutrality: no such archive: $ARCHIVE" >&2; exit 2; }
  WORK="$(mktemp -d "${TMPDIR:-/tmp}/lga-neutrality.XXXXXX")"
  unzip -q "$ARCHIVE" -d "$WORK" || { echo "neutrality: could not extract $ARCHIVE" >&2; exit 2; }
  if [ "$MODE" = zip ]; then TOP=linkgrowth-autopilot; else TOP=linkgrowth-autopilot-source; fi
  tops="$(cd "$WORK" && ls -A)"
  if [ "$tops" != "$TOP" ]; then
    echo "neutrality: the archive must hold exactly one top folder $TOP/ (found: $(echo "$tops" | tr '\n' ' '))"; FAIL=1
  fi
  ROOT="$WORK/$TOP"
  [ -d "$ROOT" ] || { echo "neutrality: $TOP/ is missing"; exit 1; }
  if [ "$MODE" = zip ]; then
    for d in tests docs bin dist node_modules .phpunit.cache; do
      if [ -e "$ROOT/$d" ]; then echo "neutrality: the plugin ZIP must not contain $d/"; FAIL=1; fi
    done
  fi
fi

cd "$ROOT" || exit 2

has() { case ",$ONLY," in *",$1,"*) return 0 ;; *) return 1 ;; esac; }

# Existing paths among the arguments (a fresh fork may not have languages/ content yet, a ZIP has no tests/).
existing() { local p; for p in "$@"; do [ -e "$p" ] && printf '%s\0' "$p"; done; }

# Matching lines of one pattern under the given paths: "path:line:text". Binary files are skipped, and so are the
# folders the build never ships (.git, node_modules, .phpunit.cache). Only the working copy's top-level dist/ (the
# build output, checked with --zip and --source) is left out: a dist/ folder anywhere else ships and is scanned.
hits() {
  local pattern="$1"; shift
  local -a paths=()
  while IFS= read -r -d '' p; do paths+=("$p"); done < <(existing "$@")
  [ ${#paths[@]} -eq 0 ] && return 0
  grep -rniEI --exclude-dir=.git --exclude-dir=node_modules --exclude-dir=.phpunit.cache -e "$pattern" -- "${paths[@]}" 2>/dev/null \
    | sed 's#^\./##' | { if [ "$MODE" = repo ]; then grep -v '^dist/' || true; else cat; fi; }
}

# Drops lines of excepted files ($@ = exact file paths; an entry ending in / is a folder and covers what it holds).
except() {
  local line keep entry
  while IFS= read -r line; do
    keep=1
    for entry in "$@"; do
      case "$entry" in
        */) case "$line" in "$entry"*) keep=0; break ;; esac ;;
        *) case "$line" in "$entry":*) keep=0; break ;; esac ;;
      esac
    done
    [ $keep -eq 1 ] && printf '%s\n' "$line"
  done
}

# Pending allowance for (pattern letter, path); 0 when not listed or in strict/source mode.
allowance() {
  [ "$STRICT" -eq 1 ] || [ "$MODE" = source ] || [ ! -f "$PENDING_FILE" ] && { echo 0; return; }
  awk -v p="$1" -v f="$2" '$1 == p && $3 == f { print $2; found = 1; exit } END { if ( ! found ) print 0 }' "$PENDING_FILE"
}

# Reports $2 (hit lines) for pattern $1, honouring the pending list for the paths it may cover ($3 = 1).
report() {
  local letter="$1" lines="$2" pendingOk="$3" file count allowed bad=0
  [ -z "$lines" ] && { echo "pattern $letter: 0 hits"; return 0; }
  local total; total="$(printf '%s\n' "$lines" | wc -l | tr -d ' ')"
  local pendingHits=0
  while IFS= read -r file; do
    count="$(printf '%s\n' "$lines" | awk -F: -v f="$file" '$1 == f' | wc -l | tr -d ' ')"
    allowed=0
    [ "$pendingOk" -eq 1 ] && allowed="$(allowance "$letter" "$file")"
    if [ "$count" -gt "$allowed" ]; then
      bad=1
      echo "pattern $letter: $file has $count matching line(s), allowed $allowed:"
      printf '%s\n' "$lines" | awk -F: -v f="$file" '$1 == f' | cut -c1-220 | sed 's/^/    /'
    else
      pendingHits=$((pendingHits + count))
    fi
  done < <(printf '%s\n' "$lines" | cut -d: -f1 | sort -u)
  if [ $bad -eq 1 ]; then return 1; fi
  echo "pattern $letter: $total hit(s), all in files of the pending list (bin/neutrality-pending.txt)"
  return 0
}

if [ "$MODE" = source ]; then
  A_SCOPE=(.); A_EXC=(); B_SCOPE=(.); C_SCOPE=(); D_SCOPE=(.)
elif [ "$MODE" = zip ]; then
  A_SCOPE=(.); A_EXC=(); B_SCOPE=(.); C_SCOPE=(.); D_SCOPE=(.)
else
  A_SCOPE=(.); A_EXC=("${A_EXCEPTIONS[@]}"); B_SCOPE=("${SHIPPED[@]}" tests bin composer.json phpunit.xml.dist); C_SCOPE=("${SHIPPED[@]}" tests); D_SCOPE=(.)
fi

if has A; then
  out="$(hits "$PAT_A" "${A_SCOPE[@]}" | except "${A_EXC[@]}")"
  report A "$out" 0 || FAIL=1
fi
if has B; then
  out="$(hits "$PAT_B" "${B_SCOPE[@]}")"
  # Tests, bin and configs may never carry store data: only shipped files can be on the pending list for B.
  shipped_only="$(printf '%s\n' "$out" | grep -E '^(linkgrowth-autopilot\.php|uninstall\.php|readme\.txt|includes/|assets/|data/|languages/)' || true)"
  others="$(printf '%s\n' "$out" | grep -vE '^(linkgrowth-autopilot\.php|uninstall\.php|readme\.txt|includes/|assets/|data/|languages/)' | grep -v '^$' || true)"
  if [ -n "$others" ]; then
    echo "pattern B: store data outside the shipped files (never allowed):"; printf '%s\n' "$others" | cut -c1-220 | sed 's/^/    /'; FAIL=1
  fi
  report B "$shipped_only" 1 || FAIL=1
fi
if has C && [ ${#C_SCOPE[@]} -gt 0 ]; then
  out="$(hits "$PAT_C" "${C_SCOPE[@]}" | except "${C_ALLOW[@]}")"
  report C "$out" 1 || FAIL=1
fi
if has D; then
  out="$(hits "$PAT_D" "${D_SCOPE[@]}")"
  report D "$out" 0 || FAIL=1
fi

label="$MODE"
[ "$STRICT" -eq 1 ] && label="$label, strict"
if [ $FAIL -eq 0 ]; then
  echo "Neutrality OK ($label)"
else
  echo "Neutrality FAILED ($label)"
fi
exit $FAIL
