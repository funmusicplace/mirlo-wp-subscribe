# Helpers for the end-to-end test scripts. Source it, call check* for each
# expectation, and end with `finish`, which exits non-zero if any failed.

PASSES=0
FAILS=0

section() { printf '\n== %s\n' "$1"; }

pass() { PASSES=$((PASSES + 1)); printf '  ok    %s\n' "$1"; }

fail() {
  FAILS=$((FAILS + 1))
  printf '  FAIL  %s\n' "$1"
  printf '        expected: %s\n' "$2"
  printf '        got:      %s\n' "$(printf '%s' "$3" | head -c 400)"
}

# check "label" expected actual: they must be equal.
check() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "$2" "$3"; fi; }

# check_has "label" needle haystack: the haystack must contain the needle.
check_has() { case "$3" in *"$2"*) pass "$1" ;; *) fail "$1" "something containing: $2" "$3" ;; esac; }

# check_lacks "label" needle haystack: the haystack mustn't contain it.
check_lacks() { case "$3" in *"$2"*) fail "$1" "nothing containing: $2" "$3" ;; *) pass "$1" ;; esac; }

# echo '{"a":1}' | json 'd["a"]' prints 1. Prints ERROR if it isn't JSON.
json() { python3 -c "import json,sys
try:
    d = json.load(sys.stdin)
    print($1)
except Exception as e:
    print('ERROR', e)"; }

# wait_for_wordpress URL DB_CONTAINER
wait_for_wordpress() {
  for _ in $(seq 1 60); do curl -s -o /dev/null "$1/wp-admin/install.php" && break; sleep 1; done
  for _ in $(seq 1 60); do docker exec "$2" mariadb -uwp -pwp -e "select 1" wp >/dev/null 2>&1 && break; sleep 1; done
}

finish() {
  printf '\n%d passed, %d failed\n' "$PASSES" "$FAILS"
  [ "$FAILS" -eq 0 ]
}
