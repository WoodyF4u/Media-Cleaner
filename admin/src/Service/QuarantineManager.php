<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

namespace Joomla\Component\Mediacleaner\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\Folder;

/**
 * Handles moving files to/from the protected quarantine folder, and the
 * bookkeeping in `#__mediacleaner_quarantine` that makes them restorable.
 *
 * v1.37.0: extracted out of FilesModel.php (which had grown past 2700
 * lines) as part of a deliberate split into single-purpose classes -
 * Scanner, QuarantineManager, WebpConverter - each independently
 * reviewable, so a permission or safety check can't as easily go missing
 * in a future change without standing out. Structural-only change: no
 * behaviour was altered, every method body was moved verbatim.
 *
 * This class does NOT check permissions itself - same as the model
 * methods it was extracted from, that's the caller's responsibility
 * (FilesModel::quarantine() / the site component's equivalent), so this
 * class stays usable from either context without duplicating ACL logic.
 */
class QuarantineManager
{
    /**
     * @var \Joomla\Database\DatabaseDriver
     */
    protected $db;

    /**
     * @param   \Joomla\Database\DatabaseDriver  $db
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @return  integer
     */
    public function getCount()
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__mediacleaner_quarantine'));

        $db->setQuery($query);

        try {
            return (int) $db->loadResult();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Move one or more files (by their `#__mediacleaner_files` id) to the
     * protected quarantine folder, and record enough information to allow
     * restoring them later. The files table row is removed once a file has
     * been moved, since the overview always reflects what's currently on
     * disk.
     *
     * No permission check of its own - callers (FilesModel::quarantine(),
     * or the site component with its own, separate frontend permissions)
     * are responsible for authorising the request first.
     *
     * @param   array  $ids  Numeric ids from `#__mediacleaner_files`.
     *
     * @return  array  ['moved' => int, 'errors' => int]
     */
    public function quarantine(array $ids)
    {
        $app = Factory::getApplication();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (empty($ids)) {
            return ['moved' => 0, 'errors' => 0];
        }

        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__mediacleaner_files'))
            ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')');

        $db->setQuery($query);

        try {
            $rows = $db->loadAssocList();
        } catch (\Exception $e) {
            return ['moved' => 0, 'errors' => count($ids)];
        }

        $quarantineRoot = $this->prepareQuarantineFolder();
        $now            = Factory::getDate()->toSql();
        $userId         = (int) $app->getIdentity()->id;

        $moved          = 0;
        $errors         = 0;
        $removedFileIds = [];

        foreach ($rows as $row) {
            $relative = trim($row['path'], '/') . '/' . $row['name'];
            $source   = JPATH_ROOT . '/' . $relative;

            if (!is_file($source)) {
                // File is already gone from disk; just drop the stale row.
                $removedFileIds[] = (int) $row['id'];
                $errors++;
                continue;
            }

            $token  = bin2hex(random_bytes(8));
            $folder = $quarantineRoot . '/' . $token;

            if (!is_dir($folder) && !Folder::create($folder)) {
                $errors++;
                continue;
            }

            $destination = $folder . '/' . $row['name'];

            if (!@rename($source, $destination)) {
                $errors++;
                continue;
            }

            $insertQuery = $db->getQuery(true)
                ->insert($db->quoteName('#__mediacleaner_quarantine'))
                ->columns($db->quoteName([
                    'original_path', 'original_name', 'quarantine_path',
                    'size', 'type', 'deleted_at', 'deleted_by',
                ]))
                ->values(
                    implode(',', [
                        $db->quote($row['path']),
                        $db->quote($row['name']),
                        $db->quote($token . '/' . $row['name']),
                        (int) $row['size'],
                        $db->quote($row['type']),
                        $db->quote($now),
                        $userId,
                    ])
                );

            $db->setQuery($insertQuery)->execute();

            $removedFileIds[] = (int) $row['id'];
            $moved++;
        }

        if (!empty($removedFileIds)) {
            $deleteQuery = $db->getQuery(true)
                ->delete($db->quoteName('#__mediacleaner_files'))
                ->where($db->quoteName('id') . ' IN (' . implode(',', $removedFileIds) . ')');

            $db->setQuery($deleteQuery)->execute();
        }

        return ['moved' => $moved, 'errors' => $errors];
    }

    /**
     * Make sure the quarantine root folder exists and is protected from
     * direct public web access (both Apache and IIS), and can't be browsed
     * as a directory listing.
     *
     * @return  string  Absolute path to the quarantine root folder.
     */
    protected function prepareQuarantineFolder()
    {
        $path = JPATH_ROOT . '/administrator/components/com_mediacleaner/quarantine';

        if (!is_dir($path)) {
            Folder::create($path);
        }

        $htaccess = $path . '/.htaccess';

        if (!is_file($htaccess)) {
            file_put_contents(
                $htaccess,
                "# Media Cleaner quarantine - deny all direct access.\n"
                . "<IfModule mod_authz_core.c>\n"
                . "    Require all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "    Order allow,deny\n"
                . "    Deny from all\n"
                . "</IfModule>\n"
            );
        }

        $webConfig = $path . '/web.config';

        if (!is_file($webConfig)) {
            file_put_contents(
                $webConfig,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<configuration>\n"
                . "    <system.webServer>\n"
                . "        <authorization>\n"
                . "            <deny users=\"*\" />\n"
                . "        </authorization>\n"
                . "    </system.webServer>\n"
                . "</configuration>\n"
            );
        }

        $indexHtml = $path . '/index.html';

        if (!is_file($indexHtml)) {
            file_put_contents($indexHtml, '<!DOCTYPE html><title></title>');
        }

        return $path;
    }
}
