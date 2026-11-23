# Spatial forum Pro release and fulfilment

## Marketplace draft

- Name: Spatial forum Pro
- Component: `local_spatialforumpro`
- Category: Local plugins
- Dependency: `mod_spatialforum`, build `2026111600` or later
- Short description: Track participation across Spatial forums, find unanswered discussions, and get a weekly overview in Moodle.
- Description: Spatial forum Pro adds filters and CSV export for participation reports, a moderation overview with links to active and unanswered threads, and an optional weekly site summary. It works with your existing Spatial forum activities, so schools can add Pro without migrating conversations or attachments.
- Pricing and trial: use the plan catalogue and exact trial terms in README. The provider grants signed site entitlements after manual checks. Marketplace checkout and renewal are outside this beta's code.
- [Participation report and weekly summary](screenshots/01-participation-and-weekly-summary.png) — course and forum filters, current and previous activity, CSV export, and the latest scheduled summary.
- [Moderation overview](screenshots/02-moderation.png) — unanswered and recently active threads across Spatial forums.
- Listing icon: [marketplace-icon.png](marketplace-icon.png), 256 × 256.

## Review activation and paid fulfilment

Create an RSA signing pair in a protected workspace outside the repository. Keep the private key and registry accessible only to the issuing operator and back them up securely. Use separate QA and production keys. Package only a production **public** key as `public-key.pem` at the plugin root when its custodian has been established. The review ZIP can verify licences using the public QA key configured in Moodle's root configuration instead.

Run the following from the source checkout with the provider's own key and registry. Use real site fingerprint and agreed UTC dates, and give the resulting token to the customer through an approved channel.

```sh
php tools/issue_licence.php /private/operator-key.pem /private/trial-registry SITE_FINGERPRINT starter trial 2026-11-23T00:00:00Z
php tools/issue_licence.php /private/operator-key.pem /private/trial-registry SITE_FINGERPRINT starter paid 2027-02-23T00:00:00Z 2027-03-23T00:00:00Z
```

The issuer declines a second trial for the same fingerprint when using the same registry. The registry must be kept in one authoritative location. The fingerprint derives from Moodle's configured base URL. For a domain change or a restored/moved site, verify the site's history manually and preserve the existing trial end. Offline licences remain valid until their signed expiry, so issue a paid licence only after confirmed active enrolment and verified payment, for the term actually purchased. Repeat the manual issue step after verified renewals. No billing, account creation, or notification happens during plugin installation or trial activation.

## Verification for 0.1.0-beta.1

Verified on 2026-11-23 with PHP 8.3.36, PostgreSQL 17.11, and Spatial forum 0.14.0-rc.2 (`5332204`). The English screenshots were captured in Firefox against the Moodle 5.2.2+ demo using synthetic forums. Both PNGs are 1440 × 1334 pixels.

| Moodle | Core revision | PHPUnit | HTTP smoke test |
| --- | --- | --- | --- |
| 5.0.12 | `2d62d8b158` | 3 tests, 30 assertions passed | Passed |
| 5.1.9+ | `74b9c7fc48` | 3 tests, 30 assertions passed | Passed |
| 5.2.2+ | `b07dd04b40` | 3 tests, 30 assertions passed | Passed |
| 5.3.1+ | `1156a935ba` | 3 tests, 30 assertions passed | Passed |

The HTTP checks covered inactive and active trial reports, forum filtering, moderation, weekly summary generation via Moodle's scheduled task, CSV, and learner denial. The issuer accepted a first trial and a paid licence and rejected a duplicate trial for the same site fingerprint.

Installation ZIP: `local_spatialforumpro-0.1.0-beta.1.zip`, Git archive of `ad6479a`, SHA-256 `9e297b53b62f0e6176255f562e265499f66707cc4547992062bf356e86c7c766`. The packaged plugin code and tests are unchanged by the QA and listing asset updates.

## Release checks

- Install alongside the specified free version and run Moodle PHPUnit with `--testsuite local_spatialforumpro_testsuite`.
- To repeat the isolated checks, run `bash tools/qa.sh /path/to/moodle-git-checkout /path/to/mod_spatialforum`. The Moodle checkout must already contain `composer.phar` and the Composer development dependencies. The script runs inside a private Docker network with PHP 8.3 and PostgreSQL 17. Pass an existing output directory as the third argument to also start Firefox and capture the two screens from synthetic English data.
- On a synthetic site, exercise inactive, trial, expired, paid, and over-limit access, two forums in different courses, deleted/invisible posts, CSV, and weekly task execution with the setting on and off.
- Test both download and permission enforcement while signed in as a learner as well as an admin. Review Moodle's Privacy API discovery and run coding checks.
- Confirm no issuer tool, private key, credentials, local QA config, or ledger is in the ZIP.
- Before commercial launch, verify the provider's Marketplace checkout/renewal, key custody, terms of sale, and support destination with the owner. No submission is part of this release build.
