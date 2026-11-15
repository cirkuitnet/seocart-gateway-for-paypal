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
#   site            Builds and installs the site the checkout's browser tests run against, in the
#                   runner's temporary directory, before the settings file exists: SEOCart's scripts
#                   built, SEOCart and this plugin installed from the zips built here, the runner's
#                   own address trusted as a proxy, and the site served by PHP's built-in server
#                   (WORDPRESS_VERSION and the SEOCART_CI_DB_* variables, as SEOCart's extension
#                   workflow sets them). Its administrator's password is made for the run and masked.
#   configure       Configures that site's gateway in test mode from the settings file, its webhooks
#                   left out. Installs and builds nothing: the file holds the sandbox app's secret.
#   checkout        Runs the checkout's browser tests (tests/browser/specs) against that site: those
#                   tagged @when-required, then, the settings file rewritten to ask the bank to check
#                   every card and the gateway configured from it again, those tagged @always. Each
#                   must pass, none skipped.
#   cleanup         Puts the PayPal orders of the checkout's site, when the job has one, on the run
#                   list, whatever became of the job's steps; voids what the run left authorized at
#                   PayPal (no webhook is created or removed); then stops the site's server, and
#                   removes the settings file, the run list, the report and the site.
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
site_dir=$work/site
site_env=$work/site.env
site_url=http://127.0.0.1:${PAYPAL_SANDBOX_HTTP_PORT:-8890}
outputs=${GITHUB_OUTPUT:-/dev/stdout}

# Writes one output of the step.
output() {
	printf '%s=%s\n' "$1" "$2" >>"$outputs"
}

# Masks a value in the rest of the job's log; outside GitHub Actions there is no log to mask.
mask() {
	if [ "${GITHUB_ACTIONS:-}" = true ]; then
		printf '::add-mask::%s\n' "$1"
	fi
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

# Runs WP-CLI against the checkout's site. Standard input is closed so that no command waits for a
# prompt, unless the caller gives one.
site_wp() {
	wp --path="$site_dir" "$@"
}

site() {
	for name in WORDPRESS_VERSION SEOCART_CI_DB_HOST SEOCART_CI_DB_USER SEOCART_CI_DB_PASSWORD SEOCART_CI_DB_NAME; do
		eval "is_set=\${$name+set}"
		[ "${is_set:-}" = set ] || fail "site: the environment variable $name is not set."
	done
	umask 077

	echo 'Building SEOCart'\''s scripts and the two zips.'
	(cd "$core" && npm ci && npm run build)
	php "$core/bin/build-zip.php"
	php "$core/bin/build-zip.php" --plugin="$ext"

	echo 'Installing WordPress.'
	sh "$core/bin/ci/download-wordpress.sh" "$WORDPRESS_VERSION" "$site_dir"
	# The passwords are read from standard input so that they never appear in the process table.
	printf '%s\n' "$SEOCART_CI_DB_PASSWORD" | site_wp config create --dbname="$SEOCART_CI_DB_NAME" \
		--dbuser="$SEOCART_CI_DB_USER" --dbhost="$SEOCART_CI_DB_HOST" --dbprefix=checkout_ --prompt=dbpass >/dev/null
	site_wp config set WP_ENVIRONMENT_TYPE local </dev/null
	site_wp config set DISABLE_WP_CRON true --raw </dev/null
	# The tests reach the site from this runner, as a proxy would, and give each test an address of
	# its own in X-Forwarded-For: the store allows one client five placements and resumes an hour.
	site_wp config set SEOCART_TRUSTED_PROXIES '127.0.0.0/8, ::1' </dev/null
	password=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
	mask "$password"
	printf '%s\n' "$password" | site_wp core install --url="$site_url" --title='SEOCart checkout' --admin_user=admin \
		--admin_email=admin@example.org --skip-email --prompt=admin_password >/dev/null

	echo 'Installing SEOCart and the adapter from their zips.'
	for zip in "$core"/dist/seocart-*.zip "$ext"/dist/seocart-gateway-for-paypal-*.zip; do
		site_wp plugin install "$zip" --activate </dev/null
	done

	# Opcache off, for the reason SEOCart's install smoke test gives.
	php -d opcache.enable=0 -d opcache.enable_cli=0 -S "${site_url#http://}" -t "$site_dir" >"$work/server.log" 2>&1 &
	echo "$!" >"$work/server.pid"
	attempts=0
	until curl -s -o /dev/null "$site_url/license.txt"; do
		attempts=$((attempts + 1))
		[ "$attempts" -lt 20 ] || fail 'PHP'\''s built-in server did not start.'
		sleep 1
	done

	printf 'WP_BASE_URL=%s\nWP_USERNAME=admin\nWP_PASSWORD=%s\n' "$site_url" "$password" >"$site_env"
	chmod 600 "$site_env"
	echo "The site answers at $site_url, SEOCart and the adapter active."
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
# process list would show. Playwright is run as the browser step installed it, never through npx,
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
# order harvested again is still one line.
harvest_site() {
	prefix=$(site_wp db prefix </dev/null)
	site_wp db query "SELECT provider_intent_id, uuid FROM ${prefix}seocart_payment_intents WHERE gateway_id = 'paypal' AND provider_intent_id REGEXP '^[A-Z0-9]{17}\$'" \
		--skip-column-names </dev/null | tr '\t' ' ' >>"$run_list"
	sort -u -o "$run_list" "$run_list"
	echo "$(grep -c '^[A-Z0-9]\{17\} ' "$run_list" || true) PayPal orders on the run list."
}

# Reads a count of Playwright's summary from a group's output: count <output> passed|skipped.
count() {
	sed -n "s/^ *\([0-9][0-9]*\) $2.*/\1/p" "$1" | tail -n 1
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
	if [ -f "$settings" ]; then
		# The checkout's site holds the orders its tests made, even when a step failed or the job ran
		# out of time before they were listed: they are listed before anything is voided.
		if [ -f "$site_env" ]; then
			harvest_site || status=1
		fi
		php "$ext/tests/Integration/Live/cleanup.php" "$settings" "$run_list" || status=$?
	else
		echo 'cleanup: no settings file, so this job made no request to PayPal.'
	fi
	if [ -f "$work/server.pid" ]; then
		kill "$(cat "$work/server.pid")" 2>/dev/null || true
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
	site) site ;;
	configure) configure ;;
	checkout) checkout ;;
	cleanup) cleanup ;;
	verdict) verdict ;;
	*) fail 'usage: paypal-sandbox.sh check-secrets|settings|heads|webhook-id|conformance|live <part>|site|configure|checkout|cleanup|verdict' ;;
esac
