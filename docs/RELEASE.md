# Spatial forum Pro release and fulfilment

## Marketplace draft

- Name: Spatial forum Pro
- Component: `local_spatialforumpro`
- Category: Local plugins
- Dependency: `mod_spatialforum`, build `2026111600` or later
- Short description: Track participation across Spatial forums, find unanswered discussions, and get a weekly overview in Moodle.
- Description: Spatial forum Pro adds filters and CSV export for participation reports, a moderation overview with links to active and unanswered threads, and an optional weekly site summary. It works with your existing Spatial forum activities, so schools can add Pro without migrating conversations or attachments.
- Pricing and trial: use the six USD price options and site limits in [README](../README.md#plans-and-trial). Enterprise is quoted separately. Draft trial copy: “Try Spatial forum Pro free for three calendar months per Moodle site. No payment is required to start your trial. To keep using Pro, choose a paid subscription after the trial.”
- [Participation report and weekly summary](screenshots/01-participation-and-weekly-summary.png) — course and forum filters, current and previous activity, CSV export, and the latest scheduled summary.
- [Moderation overview](screenshots/02-moderation.png) — unanswered and recently active threads across Spatial forums.
- Listing icon: [marketplace-icon.png](marketplace-icon.png), 256 × 256.

## Production signing key

The licence custodian chooses the key passphrase in an interactive terminal and stores it in the organisation's password or secrets manager. Choose an existing private parent directory outside the source workspace. The command creates a new directory and stops if that name already exists.

```sh
bash tools/provision_key.sh "/path/to/private-parent/spatial-forum-pro"
```

The directory contains a 3072-bit encrypted RSA signing key, the public verification key, and the empty licence registry. Back up the private key and the registry with access restricted to the custodian. Keep the registry in one authoritative location on a filesystem supporting hard links. Keep review and production keys separate. The production ZIP includes the public verification key as `public-key.pem` at the plugin root. The isolated QA runner substitutes a disposable key for its HTTP checks. A build without a bundled key accepts a QA public key from the Moodle root configuration.

## Marketplace checkout and licence fulfilment

1. The customer installs the Pro add-on. A Moodle administrator copies the site fingerprint from **Site administration → Plugins → Local plugins → Spatial forum Pro**.
2. Agree the trial start date and an appropriately sized plan. Issue the trial with the command below. The CLI calculates the end date three UTC calendar months later and allows only one trial per fingerprint in the shared registry. For a renamed or migrated site, verify its history before granting access. The fingerprint is derived from the configured Moodle base URL.
3. After the trial, the customer actively selects a subscription at Marketplace checkout. Configure the six price options only when the listing and seller payment setup are available. Keep the trial outside recurring billing.
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
