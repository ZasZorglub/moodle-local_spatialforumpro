# Spatial forum Pro for Moodle

Spatial forum Pro adds participation analytics, a cross-forum moderation overview, and a scheduled weekly summary to the existing Spatial forum activity. It depends on `mod_spatialforum` 0.14.0-rc.2 or newer. Installing or removing Pro does not migrate or alter forum content.

## Install and use

1. Install or upgrade the free Spatial forum activity. Pro requires Moodle 5.0–5.3 and PHP OpenSSL.
2. Install the Pro ZIP in **Site administration → Plugins → Install plugins**, or extract it into the Moodle web root at `local/spatialforumpro` and run the Moodle upgrade.
3. The production package includes the provider's verification key. For an isolated QA build without a bundled key, set `$CFG->local_spatialforumpro_publickey` in Moodle's root `config.php` to the supplied public PEM content. Use a separate key for review.
4. In **Site administration → Plugins → Local plugins → Spatial forum Pro**, copy the fingerprint. Purchase or renew through the [Moodle Marketplace listing](https://marketplace.moodle.com/plugins/local_spatialforumpro), then send the order reference and fingerprint to Simple Solutions at info@simplesolutionselearning.dk. After verifying the order, Simple Solutions returns a signed site licence for this setting.
5. Open **Site administration → Reports → Spatial forum Pro participation**. Use the course, forum, and time filters and download the same selection as CSV. CSV dates use UTC. Switch to **Moderation overview** to see recently active and unanswered threads with links back to the original posts.
6. Optionally enable **Generate weekly site summary** in the Pro settings. Moodle cron calculates totals for the previous completed Monday–Monday UTC week, and the latest summary appears on the participation page for site administrators. No messages are sent.

Pro reports are restricted to site administrators by default. If the Pro reporting capability is delegated, users must still be able to access the course, the activity, and the relevant group's posts. The scheduled summary uses site-admin access and is only shown to site administrators. Pro keeps the licence, summary preference and latest site-wide totals in Moodle's plugin settings. It does not send learner data or forum content outside Moodle.

Public support and issue tracker: https://github.com/ZasZorglub/moodle-local_spatialforumpro/issues

Plan prices, renewal and any trial terms are maintained on the Moodle Marketplace listing. The count used for the selected plan includes all non-deleted site accounts, including suspended accounts, and excludes Moodle's Guest account. An expired, invalid or out-of-tier entitlement suspends the Pro reports only.

See [release and fulfilment notes](docs/RELEASE.md) in the source repository for reviewer setup, key provisioning and payment fulfilment. Spatial forum Pro is licensed under GNU GPL v3 or later.
