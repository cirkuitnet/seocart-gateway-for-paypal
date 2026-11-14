#!/bin/sh
#
# The steps of the PayPal sandbox workflow (.github/workflows/paypal-sandbox.yml), run from the
# directory that holds the extension's checkout and SEOCart's beside it, as ext/ and seocart/ are
# on the runner:
#
#   sh ext/bin/ci/paypal-sandbox.sh <step> [argument]
#
#   check-secrets   Fails, naming the secret and never its value, when PAYPAL_CLIENT_ID or
#                   PAYPAL_SECRET_KEY is empty.
#   settings        Writes the one file of sandbox settings the tests read, readable by its owner
#                   alone, in the runner's temporary directory: the client id and secret, the
#                   webhook id when PAYPAL_WEBHOOK_ID is set, the account's country and the two
#                   policies. Also starts the run list and the report the live tests write to.
#   heads           Gives the commits of the extension and of SEOCart as the job's outputs.
#   webhook-id      Gives as an output whether PAYPAL_WEBHOOK_ID is set, never its value; without
#                   it, the webhooks part runs nothing and says why.
#   conformance     Runs SEOCart's conformance suite with every request sent to PayPal's sandbox,
#                   adds the orders it created to the run list from the tapes it recorded, and
#                   checks it ran the replay's tests with the replay's two skips.
#   live <part>     Runs the live tests of a part, three-d-secure or webhooks: exactly the part's
#                   tests must run, and each pass, none skipped.
#   cleanup         Voids what the run left authorized at PayPal (no webhook is created or
#                   removed); then removes the settings file, the run list and the report.
#   verdict         Says, for each thing the run proves, which job proves it and how it went
#                   (PAYPAL_SANDBOX_NEEDS, PAYPAL_SANDBOX_PARTS), and fails a red run.
#
# Nothing here prints a client id, a secret, an access token or a delivery's body: PHPUnit's
# output is passed through the tests' own filter of credential-shaped text, and the shell never
# traces its commands. Outside GitHub Actions (a dry run), RUNNER_TEMP, GITHUB_OUTPUT and
# GITHUB_STEP_SUMMARY may be left unset.

set -eu

fail() {
	printf '::error::%s\n' "$*"
	exit 1
}

ext=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
core=$(CDPATH='' cd -- "$ext/../seocart" 2>/dev/null && pwd) || core=$ext/../seocart
step_php=$ext/tests/Integration/Live/workflow-step.php
work=${RUNNER_TEMP:-${TMPDIR:-/tmp}}/paypal-sandbox
settings=$work/settings.json
run_list=$work/run-list
report=$work/report
outputs=${GITHUB_OUTPUT:-/dev/stdout}

# Writes one output of the step.
output() {
	printf '%s=%s\n' "$1" "$2" >>"$outputs"
}

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

heads() {
	output ext-head "$(git -C "$ext" rev-parse HEAD)"
	output core-head "$(git -C "$core" rev-parse HEAD)"
}

webhook_id() {
	php "$step_php" webhook-id
}

# The run's nonce: fresh for every attempt, as PayPal answers a PayPal-Request-Id it saw in the last
# 45 days with its first answer.
nonce() {
	if [ -n "${GITHUB_RUN_ID:-}" ]; then
		printf 'r%s.%s' "$GITHUB_RUN_ID" "${GITHUB_RUN_ATTEMPT:-1}"
	else
		printf 'r%s.1' "$(date +%s)"
	fi
}

# Runs a command with its output filtered and kept in a file, and returns the command's status:
# the pipe's status would be the filter's.
filtered() {
	kept=$1
	shift
	status_file=$work/status
	{
		code=0
		"$@" 2>&1 || code=$?
		echo "$code" >"$status_file"
	} | php "$step_php" redact | tee "$kept"
	return "$(cat "$status_file")"
}

conformance() {
	[ -f "$settings" ] || fail 'The settings file is missing: run the settings step first.'
	run_nonce=$(nonce)
	output nonce "$run_nonce"
	status=0
	# The suite rewrites the tape of each scenario it completes: those newer than this mark are the
	# run's. A file's time is read in whole seconds, so the suite starts in the second after the
	# mark's, and a tape from the mark's own second is an earlier recording's.
	touch "$work/conformance.mark"
	sleep 1
	# env gives the suite its recording's environment: a mode, the run's nonce and the settings
	# file's path, none of them a credential.
	filtered "$work/conformance.out" env SEOCART_TAPE=record SEOCART_TAPE_NONCE="$run_nonce" SEOCART_TAPE_SETTINGS="$settings" \
		sh "$core/bin/ci/extension.sh" conformance "$ext" payments || status=$?
	# Whatever became of the suite, the orders it created go on the run list, so the cleanup voids
	# what they left authorized.
	php "$step_php" harvest "$ext/tests/tapes" "$work/conformance.mark" "$run_list" || status=1
	php "$step_php" suite "$work/conformance.out" || status=1
	return "$status"
}

live() {
	# The live tests of each part, as a PHPUnit filter of their classes. How many tests each part
	# must run is SuiteOutput::LIVE_TESTS: a test added to a class here is counted there.
	case ${1:-} in
		three-d-secure) filter='/Live\\ThreeDSecureTest::/' ;;
		webhooks) filter='/Live\\WebhooksTest::/' ;;
		*) fail "live: the part is three-d-secure or webhooks, not ${1:-nothing}." ;;
	esac
	[ -f "$settings" ] || fail 'The settings file is missing: run the settings step first.'
	mkdir -p "$work/tmp"
	run_nonce=$(nonce)
	status=0
	# As in the suite's step, env gives PHPUnit the run's environment: paths, a mode and the nonce,
	# none of them a credential.
	(
		cd "$ext"
		filtered "$work/live.out" env SEOCART_EXTENSION_PATH="$ext" SEOCART_TAPE=record SEOCART_TAPE_NONCE="$run_nonce" \
			SEOCART_TAPE_SETTINGS="$settings" SEOCART_LIVE_RUN_LIST="$run_list" SEOCART_LIVE_REPORT="$report" TMPDIR="$work/tmp" \
			php "$core/vendor/bin/phpunit" --configuration "$ext/phpunit.xml.dist" --testsuite integration \
			--bootstrap "$core/tests/bootstrap-integration.php" --group paypal-live --filter "$filter" --fail-on-empty-test-suite --verbose
	) || status=$?
	php "$step_php" live "$1" "$work/live.out" "$report" || status=1
	if [ -n "${GITHUB_STEP_SUMMARY:-}" ] && [ -s "$report" ]; then
		{
			printf '### Live tests: %s\n\n' "$1"
			php "$step_php" redact <"$report" | sed 's/^/- /'
		} >>"$GITHUB_STEP_SUMMARY"
	fi
	return "$status"
}

cleanup() {
	status=0
	if [ -f "$settings" ]; then
		php "$ext/tests/Integration/Live/cleanup.php" "$settings" "$run_list" || status=$?
	else
		echo 'cleanup: no settings file, so this job made no request to PayPal.'
	fi
	rm -rf "$work"
	return "$status"
}

verdict() {
	php "$step_php" verdict
}

case ${1:-} in
	check-secrets) check_secrets ;;
	settings) write_settings ;;
	heads) heads ;;
	webhook-id) webhook_id ;;
	conformance) conformance ;;
	live) live "${2:-}" ;;
	cleanup) cleanup ;;
	verdict) verdict ;;
	*) fail 'usage: paypal-sandbox.sh check-secrets|settings|heads|webhook-id|conformance|live <part>|cleanup|verdict' ;;
esac
