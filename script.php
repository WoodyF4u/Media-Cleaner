<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 *
 * Installer script. After every install/update, this automatically
 * patches Joomla's own `manifest_cache`/`#__schemas` bookkeeping for this
 * extension to match what's actually on disk, then runs the equivalent
 * of ticking the checkbox and clicking "Fix" on System Information ->
 * Database - confirmed through repeated real-world testing to reliably
 * resolve Joomla's "database version does not match extension version"
 * warning for this component.
 *
 * This matters beyond just this component's own admin screens: Joomla's
 * *core* Extensies: Updaten / System Information -> Database screens read
 * `manifest_cache` directly and never call into this component's own
 * code, so if that cache is stale those core screens stay wrong even
 * while the Files view's self-heal (below) is working correctly.
 *
 * v1.35.0 added this same repair here, in postflight(), so it now also
 * runs right at install/update time - not just on the next visit to the
 * Files page. The Files view (admin/src/View/Files/HtmlView.php) still
 * runs its own copy of the same repair on every page view, as a fallback
 * in case this install-time hook doesn't fire for some Joomla/hosting-
 * specific reason - deliberately kept as an independent implementation
 * rather than shared code, so one broken code path can't take down both.
 *
 * v1.36.0 fixed the actual root cause of why the repair kept appearing
 * not to "stick": the component's manifest was named `com_mediacleaner.
 * xml`, but Joomla's own InstallerHelper::getInstallationXML() always
 * looks for `mediacleaner.xml` (element with the `com_` prefix
 * stripped) - the standard Joomla naming convention. Because that
 * lookup silently failed, Joomla's own official "Fix Structure" routine
 * (DatabaseModel::fixUpdateVersion(), called below via $model->fix())
 * treated the installed version as an empty string and overwrote
 * `manifest_cache.version` back to "" immediately after this script's
 * own repair had just set it correctly - in the same request, every
 * time, regardless of which of the two ran first. The manifest is now
 * named `mediacleaner.xml` at the package root, which fixes this for
 * good; removeStaleLegacyManifestFile() below just cleans up the old
 * filename left behind on sites updating from an earlier version.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Log\Log;

class COM_MEDIACLEANERInstallerScript implements InstallerScriptInterface
{
    public function preflight($type, $adapter): bool
    {
        return true;
    }

    public function install($adapter): bool
    {
        return true;
    }

    public function update($adapter): bool
    {
        return true;
    }

    public function uninstall($adapter): bool
    {
        return true;
    }

    public function postflight($type, $adapter): bool
    {
        if (!in_array($type, ['install', 'update', 'discover_install'], true)) {
            return true;
        }

        // v1.37.3: the one-time "hide ... from Unlinked" default-to-on
        // migration used to run only from update() - which Joomla never
        // calls for a fresh install, only for an upgrade of an existing
        // one. A genuinely fresh install (e.g. after first uninstalling
        // and then reinstalling, as opposed to just updating in place)
        // therefore ended up with an empty `params` column, and this
        // component's own PHP fallback (`->get($key, 0)`) reads that as
        // "off" for all three exclusion toggles - even though config.xml
        // declares default="1" for each - since Joomla's ComponentHelper::
        // getParams() never merges the XML form defaults into what's
        // actually stored. Moving the call here, into postflight() (which
        // fires for both 'install' and 'update'), closes that gap so a
        // fresh install gets the same correct defaults an upgrade already
        // did.
        $this->migrateUnlinkedViewDefaultsToOn();

        // v2.3.3: Wouter asked for every update to automatically pick up
        // a fresh scan, so the database never lags behind schema/matching
        // changes a new version ships with. Deliberately does NOT run the
        // scan itself here - a filesystem+database scan can take a while
        // on a large site, and doing that synchronously inside the
        // installer risks the *update itself* timing out or appearing to
        // hang. Instead this only sets a marker, and the Files view (see
        // HtmlView::display()) picks it up on its next load and runs the
        // scan the exact same way the existing "auto-rescan after saving
        // Options" flow already does: page loads immediately, progress
        // bar shows, the scan itself happens via a background AJAX call.
        // Scoped to 'update' only (not 'install') - a fresh install has
        // nothing previously scanned to bring up to date, and already
        // prompts for an initial scan on its own.
        if ($type === 'update') {
            $this->markNeedsRescanAfterUpdate();
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            $query = $db->getQuery(true)
                ->select($db->quoteName(['extension_id', 'manifest_cache']))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_mediacleaner'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'));

            $db->setQuery($query);
            $row = $db->loadAssoc();

            if (!$row) {
                return true;
            }

            $extensionId = (int) $row['extension_id'];

            // Remove the old, wrongly-named manifest copy BEFORE anything
            // else runs. Up to and including v1.35.0, this component's
            // root manifest was named `com_mediacleaner.xml`, which
            // Joomla's own Installer::copyManifest() then copies verbatim
            // (basename preserved) into
            // administrator/components/com_mediacleaner/com_mediacleaner.xml.
            // That's the bug: Joomla's own core helper,
            // InstallerHelper::getInstallationXML(), unconditionally looks
            // for `<element-without-"com_">.xml` instead - i.e.
            // `mediacleaner.xml` - and silently returns null when it isn't
            // there. Every core code path that calls it - most importantly
            // DatabaseModel::fixUpdateVersion(), which is exactly what
            // System Information -> Database's "Fix" button runs, and
            // which this very script also called further down for its own
            // reasons - then treats "installed version" as an empty
            // string and overwrites `manifest_cache.version` back to ""
            // even after our own repairManifestCache() below had just set
            // it correctly. This is why the manual patch appeared to
            // "not stick": Joomla's own official fix was silently undoing
            // it again in the very same request, every single time,
            // regardless of which order the two ran in.
            //
            // v1.36.0 renames the manifest to `mediacleaner.xml` (see
            // com_mediacleaner.xml at package root - now literally named
            // mediacleaner.xml), which is what Joomla's installer now
            // copies to disk. This cleanup only handles the one-time
            // transition for sites that already have the old file present
            // from a prior install.
            $this->removeStaleLegacyManifestFile();

            // Manual patch first, official "Fix Structure" second - in
            // that order, kept as belt-and-suspenders even though the
            // real cause (above) is now fixed at the source.
            $this->repairManifestCache($db, $extensionId, $row['manifest_cache']);

            // v1.37.1: the v1.37.0 condition below was still wrong for
            // the single most common case - installing an actual new
            // version over an older one. repairManifestCache() ALWAYS
            // reports "was stale" on a real version bump (the manifest
            // version on disk genuinely differs from what was cached
            // before this install started), so `$versionWasStale ||`
            // made the expensive branch fire on every normal update
            // anyway - exactly the case a site owner hits every time a
            // new release ships, which is why v1.37.0 alone didn't
            // actually fix the multi-minute install time.
            //
            // The insight that was missing: now that the manifest
            // filename bug (v1.36.0) is fixed, Joomla's own NORMAL
            // install/update process - not this postflight hook, not the
            // official "Fix Structure" routine - already sets
            // `manifest_cache` and applies any new SQL update files (and
            // updates `#__schemas` accordingly) correctly by itself, on
            // every single install, as standard Joomla behaviour. That
            // was never broken; the wrong manifest filename just hid it.
            // repairManifestCache() above is only a cheap, fast fallback
            // patch now (a couple of small queries) - not a reason on its
            // own to justify the expensive full schema audit.
            //
            // So the heavy official "Fix Structure" call is now gated
            // ONLY on the fast, targeted structural health check - never
            // on the version having changed, since a version change is
            // the normal case, not a sign that something is broken.
            //
            // v1.37.2 added per-phase timing instrumentation here to
            // track down a remaining ~20-50s install/update time. v1.37.5
            // removed it again: the logged numbers repeatedly showed this
            // entire postflight() completing in single-digit milliseconds,
            // proving the remaining time was Joomla's own core file-copy/
            // SQL-execution step and/or host-specific disk I/O - confirmed
            // directly by the same install finishing in 5-6 seconds on a
            // different (slower-in-general) host. Nothing left here for
            // this component's own code to account for.
            if (!$this->unlinkedViewIndexLooksHealthy($db)) {
                $app   = Factory::getApplication();
                $input = $app->getInput();
                $input->set('cid', [$extensionId]);

                $component  = $app->bootComponent('com_installer');
                $mvcFactory = $component->getMVCFactory();
                $model      = $mvcFactory->createModel('Database', 'Administrator', ['ignore_request' => false]);

                if ($model && method_exists($model, 'fix')) {
                    $model->fix([$extensionId]);
                }
            }
        } catch (\Exception $e) {
            // Best-effort only - never let this break the install/update
            // itself. The Files view runs the same repair as a fallback.
            Log::add('Media Cleaner: could not run database fix after install: ' . $e->getMessage(), Log::WARNING, 'jerror');
        }

        return true;
    }

    /**
     * Bring `#__extensions.manifest_cache` for this extension back in
     * line with the manifest XML actually installed on disk, using that
     * file as the source of truth. This is the same check-and-patch the
     * Files view runs on every page view (see
     * admin/src/View/Files/HtmlView.php::getComponentVersion() for the
     * full rationale); duplicated here deliberately, on purpose, rather
     * than shared, so this install-time hook keeps working even if
     * something about the Files view itself is ever broken.
     *
     * v1.36.1 removed this method's old `#__schemas` bookkeeping reset
     * (it used to force `version_id` to equal the manifest version on
     * every run). That was only ever coincidentally correct - `#__schemas.
     * version_id` is supposed to track the highest-numbered SQL update
     * file actually present on disk (whatever Joomla's own ChangeSet
     * class computes it to be), not this extension's marketing version
     * number. Once a version ships with no accompanying new SQL file (as
     * has been the case since v1.28.0), those two numbers permanently
     * diverge, and forcing them equal on every page view fought directly
     * against Joomla's own official "Fix Structure" logic - which this
     * script already calls right below, and which computes the correct
     * value from the real SQL files. That official call is now the only
     * thing that touches `#__schemas` here.
     *
     * Best-effort only throughout - any failure here must never break the
     * install/update itself.
     *
     * @param   \Joomla\Database\DatabaseDriver  $db             Database driver.
     * @param   integer                          $extensionId    Row id in `#__extensions`.
     * @param   string|null                      $manifestCache  Current raw `manifest_cache` JSON.
     *
     * @return  boolean  True if `manifest_cache.version` was found stale (and a patch was
     *                    attempted) - used by postflight() to decide whether the heavier
     *                    official "Fix Structure" pass is actually needed.
     */
    protected function repairManifestCache($db, $extensionId, $manifestCache)
    {
        if ($extensionId <= 0) {
            return false;
        }

        $realVersion = $this->getInstalledManifestVersion();

        if ($realVersion === '') {
            return false;
        }

        $cache = !empty($manifestCache) ? json_decode($manifestCache, true) : [];

        if (!is_array($cache)) {
            $cache = [];
        }

        $cachedVersion = isset($cache['version']) ? (string) $cache['version'] : '';

        if ($cachedVersion === $realVersion) {
            return false;
        }

        try {
            $cache['version'] = $realVersion;

            $update = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('manifest_cache') . ' = ' . $db->quote(json_encode($cache)))
                ->where($db->quoteName('extension_id') . ' = ' . $extensionId);

            $db->setQuery($update)->execute();
        } catch (\Exception $e) {
            // Best-effort only.
        }

        return true;
    }

    /**
     * Lightweight, fast structural health check - a single
     * `information_schema.STATISTICS` lookup - used to decide whether the
     * much heavier official "Fix Structure" pass (which rebuilds and
     * re-checks every historical SQL update file) is actually worth
     * running. Independent copy of the same check in
     * admin/src/View/Files/HtmlView.php::unlinkedViewIndexLooksHealthy(),
     * for the same "independent fallback" reason noted throughout this
     * file - this one takes `$db` directly since postflight() already has
     * it in scope.
     *
     * @param   \Joomla\Database\DatabaseDriver  $db
     *
     * @return  boolean
     */
    protected function unlinkedViewIndexLooksHealthy($db)
    {
        try {
            $table = $db->replacePrefix('#__mediacleaner_files');

            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('information_schema.STATISTICS'))
                ->where($db->quoteName('TABLE_SCHEMA') . ' = DATABASE()')
                ->where($db->quoteName('TABLE_NAME') . ' = ' . $db->quote($table))
                ->where($db->quoteName('INDEX_NAME') . ' = ' . $db->quote('idx_unlinked_view2'));

            $db->setQuery($query);
            $columnCount = (int) $db->loadResult();

            return $columnCount === 5;
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * One-time cleanup (v1.36.0): delete the stray, wrongly-named
     * `com_mediacleaner.xml` left behind in the component's admin folder
     * by any install prior to v1.36.0. Joomla's installer never removes
     * it on its own, since it isn't part of the new package's manifest
     * filename anymore - it would otherwise just sit there harmlessly
     * forever, but removing it avoids any confusion for anyone poking
     * around the filesystem later. Only deletes it once the correctly-
     * named `mediacleaner.xml` is confirmed present, so a failed or
     * partial file copy can never leave the component with no manifest
     * file at all.
     *
     * Best-effort only - never let this break the install/update itself.
     *
     * @return  void
     */
    protected function removeStaleLegacyManifestFile()
    {
        try {
            $correctPath = JPATH_ADMINISTRATOR . '/components/com_mediacleaner/mediacleaner.xml';
            $legacyPath  = JPATH_ADMINISTRATOR . '/components/com_mediacleaner/com_mediacleaner.xml';

            if (is_file($correctPath) && is_file($legacyPath)) {
                @unlink($legacyPath);
            }
        } catch (\Exception $e) {
            // Best-effort only.
        }
    }

    /**
     * Read the <version> from the manifest XML actually installed on
     * disk - unaffected by whatever causes `manifest_cache` to go stale,
     * so it's used as the source of truth for repairManifestCache()
     * above. Kept as its own copy of the same logic in
     * admin/src/View/Files/HtmlView.php::getInstalledManifestVersion(),
     * for the same "independent fallback" reason noted at the top of
     * this file.
     *
     * @return  string
     */
    protected function getInstalledManifestVersion()
    {
        $path = JPATH_ADMINISTRATOR . '/components/com_mediacleaner/mediacleaner.xml';

        if (!is_file($path)) {
            return '';
        }

        try {
            $xml = simplexml_load_file($path);
        } catch (\Exception $e) {
            return '';
        }

        if ($xml === false || !isset($xml->version)) {
            return '';
        }

        return trim((string) $xml->version);
    }

    /**
     * One-time migration (v1.31.0): the three "hide ... from Unlinked"
     * options on the Options screen used to default to "Nee"/off. Per an
     * explicit request from the site owner, this switches all three to
     * "Ja"/on - including for a site that already has this component
     * installed and has already saved the Options screen at least once
     * (which would otherwise keep the old "0" values stored regardless
     * of the config.xml default, since a stored value always wins over
     * the XML default).
     *
     * v1.37.3: called from postflight() now (both 'install' and
     * 'update'), not just update() - see the note at that call site for
     * why a fresh install needed this too.
     *
     * Guarded by its own marker key inside this extension's own params,
     * so it only ever forces the value once - a later, deliberate choice
     * to turn one of these back off on the Options screen is never
     * silently reverted by a future update.
     *
     * @return  void
     */
    protected function migrateUnlinkedViewDefaultsToOn()
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            $query = $db->getQuery(true)
                ->select([$db->quoteName('extension_id'), $db->quoteName('params')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_mediacleaner'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'));

            $db->setQuery($query);
            $row = $db->loadAssoc();

            if (!$row) {
                return;
            }

            $params = !empty($row['params']) ? json_decode($row['params'], true) : [];

            if (!is_array($params)) {
                $params = [];
            }

            if (!empty($params['_v1_31_0_unlinked_defaults_migrated'])) {
                return;
            }

            $params['hide_thumbs_from_unlinked']                        = 1;
            $params['hide_active_extension_assets_from_unlinked']       = 1;
            $params['hide_active_extension_images_from_unlinked']       = 1;
            $params['_v1_31_0_unlinked_defaults_migrated']              = 1;

            $update = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
                ->where($db->quoteName('extension_id') . ' = ' . (int) $row['extension_id']);

            $db->setQuery($update)->execute();
        } catch (\Exception $e) {
            // Best-effort only - never let this break the update itself.
            // Worst case, the site owner just sets these three switches
            // to "Ja" by hand on the Options screen.
            Log::add('Media Cleaner: could not migrate Unlinked-view defaults: ' . $e->getMessage(), Log::WARNING, 'jerror');
        }
    }

    /**
     * Sets a one-shot marker in this extension's own params, telling the
     * Files view to run a background rescan the next time it's loaded
     * (see HtmlView::display(), which clears the marker again as soon as
     * it's picked up, and the "mc_auto_rescan" flow in
     * admin/tmpl/files/default.php that actually performs it). See the
     * postflight() call site for why this doesn't just scan directly.
     *
     * The same `_needs_rescan_after_update` params key (and the same
     * consumption logic in HtmlView) is also set from a different place -
     * QuarantineModel::markNeedsRescan(), after a manually restored file -
     * since both cases need identical treatment: something changed on disk
     * that `#__mediacleaner_files` doesn't know about yet. The name is a
     * historical leftover from when only the update case existed; not
     * worth a rename since it's an internal params key, not user-facing.
     *
     * @return  void
     */
    protected function markNeedsRescanAfterUpdate()
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            $query = $db->getQuery(true)
                ->select([$db->quoteName('extension_id'), $db->quoteName('params')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_mediacleaner'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'));

            $db->setQuery($query);
            $row = $db->loadAssoc();

            if (!$row) {
                return;
            }

            $params = !empty($row['params']) ? json_decode($row['params'], true) : [];

            if (!is_array($params)) {
                $params = [];
            }

            $params['_needs_rescan_after_update'] = 1;

            $update = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
                ->where($db->quoteName('extension_id') . ' = ' . (int) $row['extension_id']);

            $db->setQuery($update)->execute();
        } catch (\Exception $e) {
            // Best-effort only - never let this break the update itself.
            // Worst case, the site owner just clicks "Scan opnieuw
            // uitvoeren" by hand once, same as before this feature existed.
            Log::add('Media Cleaner: could not mark rescan-after-update: ' . $e->getMessage(), Log::WARNING, 'jerror');
        }
    }
}
