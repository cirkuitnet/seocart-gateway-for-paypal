#!/bin/sh
# shellcheck disable=SC2154 # The paths and helpers of SEOCart's lib are assigned there.
#
# PayPal's steps of the PayPal sandbox workflow (.github/workflows/paypal-sandbox.yml), run from the
# directory that holds the extension's checkout and SEOCart's beside it, as ext/ and seocart/ are
# on the runner:
#
#   sh ext/bin/ci/paypal-sandbox.sh <step>
#
#   check-secrets   Fails, naming the secret and never its value, when PAYPAL_CLIENT_ID or
#                   PAYPAL_SECRET_KEY is empty. It runs before SEOCart is checked out, so it alone
#                   does without SEOCart's lib.
#   settings        Writes the one file of sandbox settings the tests read, readable by its owner
#                   alone, in the work directory: the client id and secret, the webhook id when
#                   PAYPAL_WEBHOOK_ID is set, the account's country and the two policies. Also
#                   starts the run list and the report the live tests write to.
#   configure       Configures the checkout's site's gateway in test mode from the settings file, its
#                   webhooks left out. Installs and builds nothing: the file holds the sandbox app's
#                   secret.
#   checkout        Runs the checkout's browser tests (tests/browser/specs) against that site: those
#                   tagged @when-required, then, the settings file rewritten to ask the bank to check
#                   every card and the gateway configured from it again, those tagged @always. Each
#                   must pass, none skipped.
#   cleanup         Puts the PayPal orders of the checkout's site, when the job has one, on the run
#                   list, whatever became of the job's steps; voids what the run left authorized at
#                   PayPal (no webhook is created or removed); then stops the site's server, and
#                   removes the settings file, the run list, the report and the site. When the site's
#                   orders cannot be listed, it still voids what the list names, then fails, saying
#                   why, and keeps those files and the site for the clean-up to run again.
#
# Every other step is SEOCart's (seocart/bin/ci/extension-live.sh), and so are the helpers and
# paths below that this script does not define (seocart/bin/ci/extension-live-lib.sh). Nothing here
# prints a client id, a secret, an access token or a delivery's body: Playwright's output passes
# through the guard of the plugin's declaration (tests/live.php), and the shell never traces its
# commands.

set -eu

ext=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
core=$(CDPATH='' cd -- "$ext/../seocart" 2>/dev/null && pwd) || core=$ext/../seocart
# The clean-up runs whatever became of the job's steps. Without SEOCart's lib, SEOCart was never
# checked out, and the settings step, which always comes after that checkout, never ran.
if [ "${1:-}" = cleanup ] && [ ! -f "$core/bin/ci/extension-live-lib.sh" ]; then
	echo 'cleanup: SEOCart was not checked out, so no settings file was written and nothing was sent to PayPal.'
	exit 0
fi
# shellcheck source=/dev/null
[ "${1:-}" = check-secrets ] || . "$core/bin/ci/extension-live-lib.sh"

# Fails when a secret is empty: require_secret <name> <value>. The message names the secret, never
# the value. A function's arguments stay in this shell: no process list shows them.
require_secret() {
	if [ -z "$2" ]; then
		printf '::error::The secret %s is empty: the repository'\''s Actions secrets must hold it.\n' "$1"
		return 1
	fi
}

check_secrets() {
	status=0
	require_secret PAYPAL_CLIENT_ID "${PAYPAL_CLIENT_ID:-}" || status=1
	require_secret PAYPAL_SECRET_KEY "${PAYPAL_SECRET_KEY:-}" || status=1
	[ "$status" -eq 0 ] || exit 1
	echo 'Both secrets are set: PAYPAL_CLIENT_ID and PAYPAL_SECRET_KEY.'
}

write_settings() {
	check_secrets
	umask 077
	mkdir -p "$work"
	# jq reads the values from its environment: none is an argument a process list would show.
	jq -n '{
		client_id: env.PAYPAL_CLIENT_ID,
		client_secret: env.PAYPAL_SECRET_KEY,
		webhook_id: (env.PAYPAL_WEBHOOK_ID // ""),
		account_country: "US",
		sca_policy: "SCA_WHEN_REQUIRED",
		liability_shift_policy: "require"
	}' >"$settings"
	: >"$run_list"
	: >"$report"
	chmod 600 "$settings" "$run_list" "$report"
	echo "The settings file is written, readable by its owner alone: client_id, client_secret, webhook_id, account_country, sca_policy, liability_shift_policy."
}

# Configures the site's gateway from the settings file, once it is written; nothing is installed or
# built from then on.
configure() {
	[ -f "$settings" ] || fail 'The settings file is missing: run the settings step first.'
	[ -f "$site_env" ] || fail 'The site is missing: run the site step first.'
	site_wp seocart gateway configure paypal --mode=test --from-file="$settings" --no-webhooks --user=admin </dev/null
	echo 'The gateway takes payments in test mode, its webhooks left out.'
}

# Sets when the settings file asks PayPal for the bank's check: the file is written again beside
# itself, readable by its owner alone, and put in its place.
policy() {
	umask 077
	jq --arg policy "$1" '.sca_policy = $policy' "$settings" >"$settings.next"
	chmod 600 "$settings.next"
	mv "$settings.next" "$settings"
	echo "The settings file asks for the bank's check: $1."
}

# Runs the checkout's browser tests of one group against the site, its output filtered and kept:
# browser_tests <group> <kept output>. The site's address and administrator, and the settings
# file's path, which the tests give PHP to read the public settings from: none is an argument a
# process list would show. Playwright is run as the set-up installed it, never through npx,
# which would fetch a package it did not find.
browser_tests() {
	(
		cd "$ext/tests/browser"
		set -a
		# shellcheck disable=SC1090 # The site's file, written by the site step.
		. "$site_env"
		set +a
		SEOCART_TAPE_SETTINGS=$settings
		export SEOCART_TAPE_SETTINGS
		filtered "$2" node_modules/.bin/playwright test --config playwright.config.mjs --forbid-only --grep "@$1"
	)
}

# Adds the site's PayPal orders to the run list, each as PayPal's id and the store's uuid, once: an
# order harvested again is still one line. Fails when WP-CLI does: its caller tests the status, so
# stop-on-error is off here, and each command's status is tested where it is run, none in a pipe.
harvest_site() {
	prefix=$(site_wp db prefix </dev/null) || return 1
	site_wp db query "SELECT provider_intent_id, uuid FROM ${prefix}seocart_payment_intents WHERE gateway_id = 'paypal' AND provider_intent_id REGEXP '^[A-Z0-9]{17}\$'" \
		--skip-column-names </dev/null >"$work/site-orders" || return 1
	tr '\t' ' ' <"$work/site-orders" >>"$run_list" || return 1
	sort -u -o "$run_list" "$run_list" || return 1
	echo "$(grep -c '^[A-Z0-9]\{17\} ' "$run_list" || true) PayPal orders on the run list."
}

checkout() {
	[ -f "$settings" ] || fail 'The settings file is missing: run the settings step first.'
	[ -f "$site_env" ] || fail 'The site is missing: run the site step first.'
	status=0
	browser_tests when-required "$work/checkout-when-required.out" || status=$?
	policy SCA_ALWAYS
	configure
	browser_tests always "$work/checkout-always.out" || status=$?
	passed=0
	skipped=0
	for group in when-required always; do
		group_passed=$(count "$work/checkout-$group.out" passed)
		group_skipped=$(count "$work/checkout-$group.out" skipped)
		if [ "${group_passed:-0}" -eq 0 ] || [ "${group_skipped:-0}" -ne 0 ]; then
			printf '::error::The checkout'\''s @%s browser tests passed %s and skipped %s: each must run and pass.\n' "$group" "${group_passed:-0}" "${group_skipped:-0}"
			status=1
		fi
		passed=$((passed + ${group_passed:-0}))
		skipped=$((skipped + ${group_skipped:-0}))
	done
	extra=$(cat "$work/checkout-when-required.out" "$work/checkout-always.out" | grep -c '^extra press:' || true)
	output tests "$passed"
	output skipped "$skipped"
	output extra-presses "$extra"
	echo "The checkout's browser tests: $passed passed, $skipped skipped, $extra extra presses after a refusal before any challenge."
	return "$status"
}

cleanup() {
	status=0
	harvested=true
	if [ -f "$settings" ]; then
		# The checkout's site holds the orders its tests made, even when a step failed or the job ran
		# out of time before they were listed: they are listed before anything is voided.
		if [ -f "$site_env" ]; then
			harvest_site || harvested=false
		fi
		php "$ext/tests/Integration/Live/cleanup.php" "$settings" "$run_list" || status=$?
	else
		echo 'cleanup: no settings file, so this job made no request to PayPal.'
	fi
	if [ -f "$work/server.pid" ]; then
		kill "$(cat "$work/server.pid")" 2>/dev/null || true
		rm -f "$work/server.pid"
	fi
	if [ "$harvested" = false ]; then
		# What the site's orders authorized may still be held: what the clean-up needs to run again
		# once the site answers is kept.
		printf '::error::The site'\''s PayPal orders could not be put on the run list, so what they authorized may still be held: the settings file, the run list and the site are kept in %s, for the clean-up to run again once the site answers.\n' "$work"
		return 1
	fi
	rm -rf "$work"
	return "$status"
}

case ${1:-} in
	check-secrets) check_secrets ;;
	settings) write_settings ;;
	configure) configure ;;
	checkout) checkout ;;
	cleanup) cleanup ;;
	*) fail 'usage: paypal-sandbox.sh check-secrets|settings|configure|checkout|cleanup' ;;
esac
