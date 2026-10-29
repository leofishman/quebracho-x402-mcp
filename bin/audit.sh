#!/usr/bin/env bash
# Pre-push audit: every check must pass before a push.
# As a git hook: ln -s ../../bin/audit.sh .git/hooks/pre-push
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
lib=/var/www/html/packages/x402
app=/var/www/html/app
step() { echo "== $*"; }

step tests
ddev exec --dir $lib vendor/bin/phpunit
ddev exec --dir $app vendor/bin/phpunit
step phpstan
ddev exec --dir $lib vendor/bin/phpstan --no-progress
ddev exec --dir $app vendor/bin/phpstan --no-progress
step php-cs-fixer
ddev exec packages/x402/vendor/bin/php-cs-fixer fix --dry-run --diff
step composer
ddev exec --dir $lib composer validate --strict
ddev exec --dir $app composer validate --strict
ddev exec --dir $lib composer audit
ddev exec --dir $app composer audit
ddev exec --dir $lib vendor/bin/composer-dependency-analyser
step infection
# ponytail: only lines changed since origin/main (a full run takes ~15 min); full run: drop --git-diff-*.
# Coverage is made first: Infection's own initial run gets killed inside ddev exec.
ddev exec --dir $lib "rm -rf /tmp/inf && php -d pcov.directory=src vendor/bin/phpunit --coverage-xml=/tmp/inf/coverage-xml --log-junit=/tmp/inf/junit.xml >/dev/null && vendor/bin/infection --threads=max --no-progress --coverage=/tmp/inf --skip-initial-tests --git-diff-lines --git-diff-base=origin/main --ignore-msi-with-no-mutations"
step gitleaks
docker run --rm -v "$PWD:/repo" zricethezav/gitleaks:v8.28.0 git /repo --no-banner
step semgrep
docker run --rm -v "$PWD:/src" semgrep/semgrep:1.140.0 semgrep scan --config p/php --error --quiet --exclude vendor
step smoke
bash bin/smoke.sh
echo "audit OK"
