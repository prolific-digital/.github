#!/usr/bin/env bash
# Local test suite for the shared plugin CI.
#
#   tests/run.sh                  # run everything
#   SYSPRO_DIR=/path tests/run.sh # also run against a real legacy plugin (expected to fail)
#
# Uses a local php >= 8.4 if available, otherwise the php:8.4-cli Docker image.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHECK="$ROOT/bin/check-plugin.php"
GOOD="$ROOT/tests/fixtures/good-plugin"
SYSPRO_DIR="${SYSPRO_DIR:-$HOME/projects/wp-sandbox/wp-content/plugins/syspro-order-fields}"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/prolific-ci-tests.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT

pass=0
fail=0
ok()  { echo "  ok    $1"; pass=$((pass + 1)); }
bad() { echo "  FAIL  $1"; fail=$((fail + 1)); }

# --- PHP runner ------------------------------------------------------------
if command -v php >/dev/null 2>&1 && php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then
	php_run() { php "$@"; }
	echo "Using local $(php -r 'echo PHP_VERSION;')"
elif command -v docker >/dev/null 2>&1; then
	# Mount every absolute path we may touch at the same path inside the container.
	mounts=(-v "$ROOT:$ROOT:ro" -v "$TMP:$TMP:ro")
	[ -d "$SYSPRO_DIR" ] && mounts+=(-v "$SYSPRO_DIR:$SYSPRO_DIR:ro")
	php_run() { docker run --rm -e NO_COLOR=1 "${mounts[@]}" -w "$ROOT" php:8.4-cli php "$@"; }
	echo "Using docker php:8.4-cli ($(php_run -r 'echo PHP_VERSION;'))"
else
	echo "Need php >= 8.4 or docker" >&2
	exit 2
fi

# expect <exit-code> <description> <grep-pattern-or-empty> -- <check-plugin args...>
expect() {
	local want="$1" desc="$2" pattern="$3"
	shift 4
	local out code
	out="$(php_run "$CHECK" "$@" 2>&1)"
	code=$?
	if [ "$code" -ne "$want" ]; then
		bad "$desc (exit $code, want $want)"
		echo "$out" | sed 's/^/        /'
		return
	fi
	if [ -n "$pattern" ] && ! grep -qE -- "$pattern" <<<"$out"; then
		bad "$desc (output missing /$pattern/)"
		echo "$out" | sed 's/^/        /'
		return
	fi
	ok "$desc"
}

# Copy the good fixture to $TMP/<name>/good-plugin and apply a sed to one file.
mutant() {
	local name="$1" file="$2" expr="$3"
	mkdir -p "$TMP/$name"
	cp -R "$GOOD" "$TMP/$name/"
	if [ -n "$file" ]; then
		sed -i.bak -E "$expr" "$TMP/$name/good-plugin/$file" && rm -f "$TMP/$name/good-plugin/$file.bak"
	fi
	echo "$TMP/$name/good-plugin"
}

echo
echo "== check-plugin.php syntax"
if php_run -l "$CHECK" >/dev/null; then ok "php -l bin/check-plugin.php"; else bad "php -l bin/check-plugin.php"; fi

echo
echo "== good fixture"
expect 0 "good-plugin passes" "Result: OK" -- "$GOOD" good-plugin
expect 0 "good-plugin passes with matching --tag" "Tag v1.2.3 = v1.2.3" -- "$GOOD" good-plugin --tag=v1.2.3
expect 1 "wrong --tag fails" "Tag 'v1.2.4' does not equal 'v1.2.3'" -- "$GOOD" good-plugin --tag=v1.2.4
expect 1 "wrong slug fails (main file not found)" "Main file .* not found" -- "$GOOD" other-plugin
expect 2 "usage error without args" "" --

echo
echo "== mutations of the good fixture (each must fail)"
d=$(mutant v-const good-plugin.php "s/'GOOD_PLUGIN_VERSION', '1.2.3'/'GOOD_PLUGIN_VERSION', '1.2.2'/")
expect 1 "VERSION constant mismatch" "GOOD_PLUGIN_VERSION is '1.2.2'" -- "$d" good-plugin
d=$(mutant stable readme.txt "s/^Stable tag: 1.2.3/Stable tag: 1.2.0/")
expect 1 "readme Stable tag mismatch" "Stable tag is '1.2.0'" -- "$d" good-plugin
d=$(mutant rphp readme.txt "s/^Requires PHP: 8.4/Requires PHP: 7.4/")
expect 1 "readme Requires PHP != 8.4" "Requires PHP: expected '8.4'" -- "$d" good-plugin
d=$(mutant tested readme.txt "/^Tested up to:/d")
expect 1 "readme Tested up to missing" "Tested up to missing" -- "$d" good-plugin
d=$(mutant pkg package.json 's/"version": "1.2.3"/"version": "1.0.0"/')
expect 1 "package.json version mismatch" "package.json version is '1.0.0'" -- "$d" good-plugin
d=$(mutant hdr-php good-plugin.php "s/^( \* Requires PHP: +)8.4/\17.4/")
expect 1 "header Requires PHP wrong" "Requires PHP: expected '8.4', found '7.4'" -- "$d" good-plugin
d=$(mutant hdr-wp good-plugin.php "s/^( \* Requires at least: +)6.5/\16.4/")
expect 1 "header Requires at least wrong" "Requires at least: expected '6.5'" -- "$d" good-plugin
d=$(mutant hdr-author good-plugin.php "s/^( \* Author: +).*/\1Someone Else/")
expect 1 "header Author wrong" "Author: expected 'Prolific Digital'" -- "$d" good-plugin
d=$(mutant hdr-lic good-plugin.php "s/^( \* License: +).*/\1GPLv2 or later/")
expect 1 "header License wrong" "License: expected 'GPL-2.0-or-later'" -- "$d" good-plugin
d=$(mutant hdr-td good-plugin.php "s/^( \* Text Domain: +).*/\1good/")
expect 1 "header Text Domain != slug" "Text Domain: expected 'good-plugin'" -- "$d" good-plugin
d=$(mutant hdr-upd good-plugin.php "/Update URI:/d")
expect 1 "header Update URI missing" "Missing 'Update URI'" -- "$d" good-plugin
d=$(mutant hdr-order good-plugin.php "/^ \* Plugin URI:/{h;d;};/^ \* Author: /{G;}")
expect 1 "header out of order" "out of order" -- "$d" good-plugin
d=$(mutant semver good-plugin.php "s/^( \* Version: +)1.2.3/\11.2/")
expect 1 "non-semver Version" "not three-part semver" -- "$d" good-plugin
d=$(mutant guard good-plugin.php "s/^defined\( 'ABSPATH' \) \|\| exit;/if ( ! defined( 'ABSPATH' ) ) { exit; }/")
expect 1 "main-file guard not in contract form" "must be the first statement after the header" -- "$d" good-plugin
d=$(mutant guard-inc includes/update-checker.php "/ABSPATH/d")
expect 1 "include without ABSPATH guard" "without an ABSPATH guard: includes/update-checker.php" -- "$d" good-plugin
for f in CHANGELOG.md LICENSE .distignore readme.txt; do
	d=$(mutant "rm-$f" "" "")
	rm "$d/$f"
	expect 1 "$f missing" "$f missing" -- "$d" good-plugin
done
d=$(mutant cl-tag CHANGELOG.md "s/^## \[1.2.3\].*/## [9.9.9]/")
expect 1 "release tag without CHANGELOG section" "no '## \[1.2.3\]' section" -- "$d" good-plugin --tag=v1.2.3

echo
echo "== real legacy plugin"
# The legacy (pre-normalization) state is exported from SYSPRO_LEGACY_REF so the
# test stays meaningful after the plugin itself is normalized.
SYSPRO_LEGACY_REF="${SYSPRO_LEGACY_REF:-main}"
if [ -d "$SYSPRO_DIR/.git" ]; then
	legacy="$TMP/legacy/syspro-order-fields"
	mkdir -p "$legacy"
	git -C "$SYSPRO_DIR" archive "$SYSPRO_LEGACY_REF" | tar -x -C "$legacy"
	echo "  -- $SYSPRO_LEGACY_REF (legacy, expected to FAIL)"
	out="$(php_run "$CHECK" "$legacy" syspro-order-fields 2>&1)"
	code=$?
	echo "$out" | sed 's/^/        /'
	if [ "$code" -eq 1 ]; then ok "syspro-order-fields@$SYSPRO_LEGACY_REF reported as non-compliant"; else bad "syspro-order-fields@$SYSPRO_LEGACY_REF exit $code, want 1"; fi

	echo "  -- working tree ($(git -C "$SYSPRO_DIR" branch --show-current), informational)"
	out="$(php_run "$CHECK" "$SYSPRO_DIR" syspro-order-fields 2>&1)"
	echo "        exit $? / $(grep '^Result:' <<<"$out")"
else
	echo "  skip  $SYSPRO_DIR is not a git checkout"
fi

echo
echo "== workflow YAML"
workflows=("$ROOT"/.github/workflows/*.yml)
if command -v actionlint >/dev/null 2>&1; then
	if actionlint "${workflows[@]}"; then ok "actionlint ${#workflows[@]} reusable workflows"; else bad "actionlint"; fi
	# Callers reference the remote reusable workflow; render with a slug and lint them too.
	mkdir -p "$TMP/callers/.github/workflows"
	for t in ci release; do sed 's/{slug}/good-plugin/' "$ROOT/templates/$t.yml" > "$TMP/callers/.github/workflows/$t.yml"; done
	if (cd "$TMP/callers" && actionlint .github/workflows/*.yml); then ok "actionlint caller templates"; else bad "actionlint caller templates"; fi
else
	echo "  (actionlint not found; falling back to YAML parse)"
fi
if command -v python3 >/dev/null 2>&1 && python3 -c 'import yaml' 2>/dev/null; then
	for f in "${workflows[@]}" "$ROOT/templates/ci.yml" "$ROOT/templates/release.yml"; do
		if sed 's/{slug}/good-plugin/' "$f" | python3 -c 'import sys,yaml; yaml.safe_load(sys.stdin)'; then
			ok "yaml parse $(basename "$f")"
		else
			bad "yaml parse $(basename "$f")"
		fi
	done
fi

echo
echo "Passed: $pass  Failed: $fail"
[ "$fail" -eq 0 ]
