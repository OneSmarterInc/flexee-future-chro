# The Future CHRO: go-live on chro.flexee.org

For Mukesh. Decision B6: each merge to `main` deploys on its own through `.github/workflows/deploy-chro.yml`, once you have done the one-time setup below. You keep the server, the secrets and the `DEPLOY_ENABLED` switch. Claude has no server key; after each merge, Claude reads the deploy log on GitHub and reports.

## 1. The server (once)

1. Set up the subdomain `chro.flexee.org` with a certificate, PHP 8.4, and a MySQL or MariaDB database with its own user. The workflow loads PHP's fileinfo from `/opt/alt/php84/usr/lib64/php/modules/fileinfo.so`. If the path is different on this account, tell Claude, and Claude will change the workflow.
2. Give the server read access to the private repo: make a key pair on the server and add its public half to the repo under Settings, Deploy keys (read only).
3. Clone it: `git clone git@github.com:OneSmarterInc/flexee-future-chro.git ~/public_html/chro.flexee.org`, and point the web root at `public/`.
4. Make `.env` from `.env.example`. Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://chro.flexee.org` and the `DB_` lines. Leave `ANTHROPIC_API_KEY` empty for now if you like; the site runs on the fixed replies without it. Never commit `.env`.
5. Prepare the app by hand once, so the first deploy can put it in maintenance mode:

```
cd ~/public_html/chro.flexee.org
php -d extension=/opt/alt/php84/usr/lib64/php/modules/fileinfo.so /usr/local/lib/composer/composer.phar install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan key:generate
php artisan migrate --force
```

6. Add the scheduler's cron line, for classes that open and close years on their own:

```
* * * * * cd ~/public_html/chro.flexee.org && php artisan schedule:run >> /dev/null 2>&1
```

## 2. GitHub (once)

In the repo, under Settings, Environments, make an environment named `development` and give it these secrets:

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | The server's SSH host |
| `DEPLOY_USER` | The SSH user |
| `DEPLOY_PORT` | The SSH port |
| `DEPLOY_SSH_KEY` | A private key whose public half is in that user's `~/.ssh/authorized_keys` |
| `CHRO_HEALTH_URL` | `https://chro.flexee.org/up` |
| `CHRO_BACKUP_COMMAND` and `CHRO_BACKUP_VERIFY_COMMAND` | Optional. Set both or neither |

Then, under Settings, Variables, set the repository variable `DEPLOY_ENABLED` to `true`.

## 3. The first deploy

The workflow runs after the `tests` workflow passes on `main`. To start it now rather than at the next merge, open Actions, then `tests`, then the latest run on `main`, and choose "Re-run all jobs". When it passes, `deploy-chro-development` runs. It puts the site in maintenance mode, checks out the exact commit, installs, unpacks the tested front-end build, migrates, caches, comes back up, and checks `/up`. If any step fails, it puts the previous code and front end back and comes back up. Migrations that already ran stay, and Claude reads the log.

## 4. After the first deploy (once)

```
php artisan ashby:admin --email=<Vikram's email> --name="Vikram Sethi"
```

This prints Vikram's administrator password once. Send it to him privately, never in a chat or a CI log. From the admin page he makes classes and instructor logins himself. `php artisan ashby:demo-class --teams=4 --domain=<a domain you own>` makes a practice class if he wants one.

To turn the AI voices on later, add `ANTHROPIC_API_KEY` to `.env`, run `php artisan config:cache`, then `php artisan ashby:ai-check`. It prints "Passed" and one answer, or the reason it failed. Send the output to Claude.

## 5. Mail, reporting and checks

Outgoing mail is off until `.env` has mail lines. With Mailjet: `MAIL_MAILER=mailjet`, `MAILJET_API_KEY`, `MAILJET_API_SECRET`, `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`. Then new sign-ins are emailed, and reminders go a day, four hours and an hour before a year closes. Until then, the roster says mail is off.

The reporting connector is at `https://chro.flexee.org/mcp/reporting`. An administrator connects it in Claude: Organization settings, Connectors, Add, Custom, then that address. Set `ASHBY_REPORTING_ENABLED=false` to turn it off.

After every release, run `php artisan ashby:verify --database` and send the output to Claude (`docs/VERIFY.md`).

## 6. Turning it off

Set `DEPLOY_ENABLED` to anything but `true`. Merges then stop deploying until you set it back.
