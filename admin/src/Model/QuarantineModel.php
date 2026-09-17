<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

/**
 * Model backing the quarantine overview: files that were removed from their
 * original location via "Media Cleaner" but not yet permanently deleted, so
 * they can still be reviewed and restored.
 */
class QuarantineModel extends BaseDatabaseModel
{
    /**
     * Get all quarantined files, most recently deleted first.
     *
     * @return  array
     */
    public function getItems()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__mediacleaner_quarantine'))
            ->order($db->quoteName('deleted_at') . ' DESC');

        $db->setQuery($query);

        try {
            $rows = $db->loadAssocList();
        } catch (\Exception $e) {
            return [];
        }

        $items = [];

        foreach ($rows as $row) {
            $size = (int) $row['size'];

            $items[] = [
                'id'              => (int) $row['id'],
                'original_path'   => $row['original_path'],
                'original_name'   => $row['original_name'],
                'quarantine_path' => $row['quarantine_path'],
                'size'            => $size,
                'sizeKB'          => $size / 1024,
                'type'            => $row['type'],
                'deleted_at'      => $row['deleted_at'],
            ];
        }

        return $items;
    }

    /**
     * Restore one or more quarantined files to their original location.
     *
     * @param   array  $ids  Numeric ids from `#__mediacleaner_quarantine`.
     *
     * @return  array  ['restored' => int, 'errors' => int]
     *
     * @throws  \Exception  When the current user is not allowed to manage this component.
     */
    public function restore(array $ids)
    {
        $rows = $this->getRowsById($ids);

        $restored = 0;
        $errors   = 0;
        $doneIds  = [];

        foreach ($rows as $row) {
            $source      = $this->getQuarantineRoot() . '/' . $row['quarantine_path'];
            $destDir     = JPATH_ROOT . '/' . trim($row['original_path'], '/');
            $destination = $destDir . '/' . $row['original_name'];

            if (!is_file($source)) {
                $errors++;
                continue;
            }

            if (!is_dir($destDir) && !Folder::create($destDir)) {
                $errors++;
                continue;
            }

            if (is_file($destination)) {
                // A new file with the same name already exists at the
                // original location - don't overwrite it, keep the
                // quarantined copy so nothing is lost.
                $errors++;
                continue;
            }

            if (!@rename($source, $destination)) {
                $errors++;
                continue;
            }

            $this->removeQuarantineSubfolder($row['quarantine_path']);

            $doneIds[]  = (int) $row['id'];
            $restored++;
        }

        $this->deleteRows($doneIds);

        if ($restored > 0) {
            $this->markNeedsRescan();
        }

        return ['restored' => $restored, 'errors' => $errors];
    }

    /**
     * Sets the same one-shot "run a background rescan next time the Files
     * view loads" marker script.php's markNeedsRescanAfterUpdate() sets
     * after an update - reused here (same `_needs_rescan_after_update`
     * params key, read by Files/HtmlView::consumeNeedsRescanAfterUpdateFlag())
     * because a manually restored file needs exactly the same treatment: it's
     * back on disk, but `#__mediacleaner_files` still has no row for it (it
     * was deleted from that table the moment it got quarantined - see
     * QuarantineManager::quarantine()), so without a rescan the Files view
     * would keep looking as if nothing had changed. Deliberately its own
     * small copy of the marker-write logic rather than a shared helper -
     * same "independent fallback" reasoning as script.php's own copy (see
     * that method's docblock): this one small write is safer duplicated
     * than coupled to a class in a different MVC layer.
     *
     * @return  void
     */
    protected function markNeedsRescan()
    {
        try {
            $db = $this->getDatabase();

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
            // Best-effort only - never let a failed marker write turn a
            // successful restore into an error message. Worst case, the
            // site owner just clicks "Scan opnieuw uitvoeren" by hand once.
        }
    }

    /**
     * Permanently delete one or more quarantined files from disk.
     *
     * @param   array  $ids  Numeric ids from `#__mediacleaner_quarantine`.
     *
     * @return  array  ['deleted' => int, 'errors' => int]
     *
     * @throws  \Exception  When the current user is not allowed to manage this component.
     */
    public function purge(array $ids)
    {
        $rows = $this->getRowsById($ids);

        $deleted = 0;
        $errors  = 0;
        $doneIds = [];

        foreach ($rows as $row) {
            $source = $this->getQuarantineRoot() . '/' . $row['quarantine_path'];

            if (is_file($source) && !@unlink($source)) {
                $errors++;
                continue;
            }

            $this->removeQuarantineSubfolder($row['quarantine_path']);

            $doneIds[] = (int) $row['id'];
            $deleted++;
        }

        $this->deleteRows($doneIds);

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * Fetch quarantine rows by id, after checking the current user is
     * allowed to manage this component.
     *
     * @param   array  $ids
     *
     * @return  array
     *
     * @throws  \Exception
     */
    protected function getRowsById(array $ids)
    {
        $app = Factory::getApplication();

        if (!$app->getIdentity()->authorise('core.manage', 'com_mediacleaner')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (empty($ids)) {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__mediacleaner_quarantine'))
            ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')');

        $db->setQuery($query);

        try {
            return $db->loadAssocList();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Delete a set of quarantine rows by id.
     *
     * @param   array  $ids
     *
     * @return  void
     */
    protected function deleteRows(array $ids)
    {
        if (empty($ids)) {
            return;
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__mediacleaner_quarantine'))
            ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')');

        $db->setQuery($query)->execute();
    }

    /**
     * Remove the now-empty per-file quarantine subfolder (best effort).
     *
     * @param   string  $quarantinePath  e.g. "a1b2c3.../filename.jpg"
     *
     * @return  void
     */
    protected function removeQuarantineSubfolder($quarantinePath)
    {
        $token = strtok($quarantinePath, '/');

        if (!$token) {
            return;
        }

        $folder = $this->getQuarantineRoot() . '/' . $token;

        if (is_dir($folder)) {
            $files = @scandir($folder);

            if ($files !== false && count($files) <= 2) {
                // Only "." and ".." left - safe to remove.
                @rmdir($folder);
            }
        }
    }

    /**
     * Absolute path to the quarantine root folder.
     *
     * @return  string
     */
    protected function getQuarantineRoot()
    {
        return JPATH_ROOT . '/administrator/components/com_mediacleaner/quarantine';
    }

    /**
     * Resolves a quarantined file's absolute path and MIME type for the
     * "Voorbeeld" thumbnail column - added in v2.4.0, since quarantined
     * files sit in a folder that's deliberately NOT web-accessible (see
     * COM_MEDIACLEANER_QUARANTINE_NOTICE), so unlike the Files view an
     * `<img src>` can't just point at the file directly; it has to go
     * through QuarantineController::thumb(), which calls this first.
     *
     * Two independent safety checks, neither of which trusts the stored
     * `quarantine_path` blindly: (1) only file types that are genuinely
     * safe and meaningful to preview as an image are ever returned -
     * everything else (PDFs, docs, etc.) gets no thumbnail, same as the
     * Files view's badge-instead-of-image fallback; (2) `realpath()`
     * resolves the full path and the result must still be located
     * inside the quarantine root, which defends against a corrupted or
     * tampered `quarantine_path` value ever escaping that folder (e.g.
     * via `../`) and having arbitrary filesystem content served back
     * through this endpoint.
     *
     * @param   integer  $id  A `#__mediacleaner_quarantine` row id.
     *
     * @return  array|null  ['path' => string, 'mime' => string], or null
     *                       if there's nothing safe to serve.
     */
    public function getThumbnailInfo($id)
    {
        $id = (int) $id;

        if ($id <= 0) {
            return null;
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['quarantine_path', 'type']))
            ->from($db->quoteName('#__mediacleaner_quarantine'))
            ->where($db->quoteName('id') . ' = ' . $id);

        $db->setQuery($query);

        try {
            $row = $db->loadAssoc();
        } catch (\Exception $e) {
            return null;
        }

        if (!$row || empty($row['quarantine_path'])) {
            return null;
        }

        $previewableMimeTypes = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ];

        $type = strtolower((string) $row['type']);

        if (!isset($previewableMimeTypes[$type])) {
            return null;
        }

        $root = realpath($this->getQuarantineRoot());
        $real = realpath($this->getQuarantineRoot() . '/' . $row['quarantine_path']);

        if ($root === false || $real === false || strpos($real, $root) !== 0 || !is_file($real)) {
            return null;
        }

        return ['path' => $real, 'mime' => $previewableMimeTypes[$type]];
    }
}
