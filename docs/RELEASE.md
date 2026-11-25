# Spatial forum Pro release and fulfilment

## Marketplace draft

- Name: Spatial forum Pro
- Component: `local_spatialforumpro`
- Category: Local plugins
- Dependency: `mod_spatialforum`, build `2026111600` or later
- Requirements: Moodle 5.0–5.3 and PHP with OpenSSL enabled. A signed site licence, obtained after purchase through the Marketplace listing, unlocks the reports.
- Short description: Track participation across Spatial forums, find unanswered discussions, and get a weekly overview in Moodle.
- Description: Spatial forum Pro adds filters and CSV export for participation reports, a moderation overview with links to active and unanswered threads, and an optional weekly site summary. It works with your existing Spatial forum activities, so schools can add Pro without migrating conversations or attachments.
- Pricing and trial: use the [Moodle Marketplace listing](https://marketplace.moodle.com/plugins/local_spatialforumpro).
- Support and public issue tracker: [GitHub Issues](https://github.com/ZasZorglub/moodle-local_spatialforumpro/issues).
- [Participation report and weekly summary](screenshots/01-participation-and-weekly-summary.png) — course and forum filters, current and previous activity, CSV export, and the latest scheduled summary.
- [Moderation overview](screenshots/02-moderation.png) — unanswered and recently active threads across Spatial forums.
- Listing icon: [marketplace-icon.png](marketplace-icon.png), 256 × 256.

## Production signing key

The licence custodian chooses the key passphrase in an interactive terminal and stores it in the organisation's password or secrets manager. Choose an existing private parent directory outside the source workspace. The command creates a new directory and stops if that name already exists.

```sh
bash tools/provision_key.sh "/path/to/private-parent/spatial-forum-pro"
```

The directory contains a 3072-bit encrypted RSA signing key, the public verification key, and the empty licence registry. Back up the private key and the registry with access restricted to the custodian. Keep the registry in one authoritative location on a filesystem supporting hard links. Keep review and production keys separate. The production ZIP includes the public verification key as `public-key.pem` at the plugin root. The isolated QA runner substitutes a disposable key for its HTTP checks. A build without a bundled key accepts a QA public key from the Moodle root configuration.

## Marketplace price options

Use these fields for the separate paid listing `local_spatialforumpro`. Each paid option covers one Moodle site. [Lenarys Analytics](https://marketplace.moodle.com/plugins/local_la/prices) is a published example of a free download option alongside paid subscriptions.

| Price option | Amount | Billing | Site allowance |
| --- | ---: | --- | --- |
| Free Trial | Free | No subscription | Three calendar months per eligible Moodle site |
| Starter Monthly | 10 USD | Monthly | Up to 2,000 site accounts |
| Starter Annual | 100 USD | Annual | Up to 2,000 site accounts |
| Growth Monthly | 25 USD | Monthly | Up to 10,000 site accounts |
| Growth Annual | 250 USD | Annual | Up to 10,000 site accounts |
| Scale Monthly | 50 USD | Monthly | Up to 25,000 site accounts |
| Scale Annual | 500 USD | Annual | Up to 25,000 site accounts |

Enterprise is quoted separately for sites with more than 25,000 accounts. The [plan catalogue](../classes/local/plans.php) is the source of truth for the paid tiers. Site accounts include non-deleted accounts, including suspended accounts, and exclude Moodle's Guest account.

**Free Trial description**

> Try Spatial forum Pro free for three calendar months on your Moodle site. Download and install the add-on, then request a trial licence using the site fingerprint in the Pro settings. You can try Pro without providing a payment card. The trial ends automatically. To keep using Pro, choose a paid plan through Moodle Marketplace.

The free option provides the ZIP. The provider issues the time-limited trial licence and checks the registry for prior use. Keep the free option separate from every recurring price so trial activation never starts a paid subscription. Review site history before reissuing a licence for a moved or renamed site.

**Seller setup and checkout verification**

- Sign in as the provider who owns the Pro listing. Complete the payout setup offered by Marketplace using the provider's verified business details. Prepare the seller's Terms of Sale and support details for approval before publishing. [Moodle's submission and purchasing guidance](https://docs.moodle.org/502/en/Add-on_FAQ)
- When price editing is available, enter the seven options above. Record the Marketplace price identifiers alongside their tiers if the dashboard exposes them. Verify the displayed currency, amount and billing interval for each.
- Verify the free download without starting a subscription. Check that the signed trial expires after three calendar months. For a paid checkout, verify that the successful order identifies the purchased price, the subtotal before tax and the billing period. Use a seller test mode if Marketplace offers one.
- For the first paid order and each renewal, follow the fulfilment steps below. A failed payment or cancellation must never trigger another licence. Verify that the current signed term expires when expected.

## Marketplace checkout and licence fulfilment

1. The customer installs the Pro add-on. A Moodle administrator copies the site fingerprint from **Site administration → Plugins → Local plugins → Spatial forum Pro**.
2. Agree the trial start date and an appropriately sized plan. Issue the trial with the command below. The CLI calculates the end date three UTC calendar months later and allows only one trial per fingerprint in the shared registry. For a renamed or migrated site, verify its history before granting access. The fingerprint is derived from the configured Moodle base URL.
3. After the trial, the customer actively selects a subscription at Marketplace checkout. Set up the free download and six recurring prices using the price table above. Keep the trial outside recurring billing.
4. For each first order or renewal, authenticate to the seller's payment dashboard. Verify the captured payment, its product and price option, the subtotal before tax, the billing dates, and the purchaser's association with the supplied site fingerprint. Use the immutable reference for the **individual paid invoice/order**, including on retries. Never use the subscription identifier for multiple billing periods.
5. Save only the fields below to a private file outside the checkout (permissions `600`). This is the operator's record of a verified order. A customer-supplied receipt or JSON must never be passed directly to the issuer.
6. Run the issuer and make the token available via an approved channel. The customer pastes it into the licence setting when the paid term begins. Repeat for each successful renewal. Cancellation, failed payment or refund stops future issuance. A previously issued offline licence expires at the date signed into it.

```json
{
  "reference": "example-invoice-001",
  "site": "replace-with-the-real-64-character-fingerprint",
  "status": "paid",
  "currency": "USD",
  "subtotal_cents": 1000,
  "plan": "starter",
  "interval": "monthly",
  "start": "2027-02-23T00:00:00Z",
  "end": "2027-03-23T00:00:00Z"
}
```

Use Bash to read the passphrase without echo and expose it only to the PHP issuer process. Run the command suited to the order, then clear the shell variable.

```sh
read -r -s -p 'Signing key passphrase: ' sfpro_passphrase
printf '\n'
SFPRO_KEY_PASSPHRASE="$sfpro_passphrase" php tools/issue_licence.php PRIVATE_PEM PRIVATE_REGISTRY SITE_FINGERPRINT starter trial 2026-11-23T00:00:00Z
SFPRO_KEY_PASSPHRASE="$sfpro_passphrase" php tools/issue_licence.php PRIVATE_PEM PRIVATE_REGISTRY --marketplace-order PRIVATE_JSON
unset sfpro_passphrase
```

The CLI checks the catalogue price and billing interval and gives the same licence for a retry with the same details. Reusing the order reference for different terms or a different site is rejected. The operator verifies payment and the site association. Moodle's [public Marketplace API](https://moodledev.io/general/community/plugincontribution/marketplace-api/moodle-marketplace-api) documents catalogue, release, download and buyer purchase operations. Enable automatic fulfilment only after confirming that the Marketplace supplies sellers with authenticated payment and renewal events.

## Production package

Build the 0.1.0-beta.2 installation ZIP from the corresponding source revision. Check that the only PEM file in the ZIP is `spatialforumpro/public-key.pem`, and compare it with the custodian's public key.

```sh
git archive --format=zip --prefix=spatialforumpro/ --output=../local_spatialforumpro-0.1.0-beta.2.zip HEAD
```

## Verification for 0.1.0-beta.1

Verified on 2026-11-23 with PHP 8.3.36, PostgreSQL 17.11, and Spatial forum 0.14.0-rc.2 (`5332204`). The English screenshots were captured in Firefox against the Moodle 5.2.2+ demo using synthetic forums. Both PNGs are 1440 × 1334 pixels.

| Moodle | Core revision | PHPUnit | HTTP smoke test |
| --- | --- | --- | --- |
| 5.0.12 | `2d62d8b158` | 3 tests, 30 assertions passed | Passed |
| 5.1.9+ | `74b9c7fc48` | 3 tests, 30 assertions passed | Passed |
| 5.2.2+ | `b07dd04b40` | 3 tests, 30 assertions passed | Passed |
| 5.3.1+ | `1156a935ba` | 3 tests, 30 assertions passed | Passed |

The HTTP checks covered inactive and active trial reports, forum filtering, moderation, weekly summary generation via Moodle's scheduled task, CSV, and learner denial. The issuer checks the trial and paid licence lifecycle with disposable records. Run `bash tools/qa_issuer.sh PRIVATE_QA_PEM EMPTY_PRIVATE_QA_REGISTRY` to repeat those checks with a disposable key.

Installation ZIP: `local_spatialforumpro-0.1.0-beta.1.zip`, Git archive of `ad6479a`, SHA-256 `9e297b53b62f0e6176255f562e265499f66707cc4547992062bf356e86c7c766`. The packaged plugin code and tests are unchanged by the QA and listing asset updates.

## Release checks

- Install alongside the specified free version and run Moodle PHPUnit with `--testsuite local_spatialforumpro_testsuite`.
- To repeat the isolated checks, run `bash tools/qa.sh /path/to/moodle-git-checkout /path/to/mod_spatialforum`. The Moodle checkout must already contain `composer.phar` and the Composer development dependencies. The script runs inside a private Docker network with PHP 8.3 and PostgreSQL 17. Pass an existing output directory as the third argument to also start Firefox and capture the two screens from synthetic English data.
- On a synthetic site, exercise inactive, trial, expired, paid, and over-limit access, two forums in different courses, deleted/invisible posts, CSV, and weekly task execution with the setting on and off.
- Test both download and permission enforcement while signed in as a learner as well as an admin. Review Moodle's Privacy API discovery and run coding checks.
- Confirm no issuer tool, private key, credentials, local QA config, or ledger is in the ZIP.
- Before commercial launch, verify the provider's Marketplace checkout/renewal, key custody, terms of sale, and support destination with the owner. No submission is part of this release build.
